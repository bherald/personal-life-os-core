<?php

namespace App\Console\Commands;

use App\Services\Custody\TaskCustodyService;
use App\Services\ScheduledJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * SchedulerRunCommand - Central job scheduler
 *
 * This command is meant to be run every minute (or every 5 minutes) via system cron:
 * * * * * * cd /path/to/project && php artisan scheduler:run >> /dev/null 2>&1
 *
 * Or for 5-minute intervals:
 * * /5 * * * * cd /path/to/project && php artisan scheduler:run >> /dev/null 2>&1
 */
class SchedulerRunCommand extends Command
{
    private const SCHEDULER_TICK_LOCK_KEY = 'scheduler:run:tick';

    private const SCHEDULER_TICK_LOCK_TTL = 300;

    protected $signature = 'scheduler:run
                            {--dry-run : Show what would run without executing}
                            {--job= : Run a specific job by ID or name}
                            {--force : Run job even if not due}
                            {--sync : Run synchronously (used by background spawner)}
                            {--worker-id= : Worker ID for parallel execution}';

    protected $description = 'Run scheduled jobs that are due';

    private ScheduledJobService $scheduledJobService;

    private TaskCustodyService $custodyService;

    public function __construct(ScheduledJobService $scheduledJobService, TaskCustodyService $custodyService)
    {
        parent::__construct();
        $this->scheduledJobService = $scheduledJobService;
        $this->custodyService = $custodyService;
    }

    public function handle(): int
    {
        // Block all scheduled job execution outside production
        if (! app()->environment('production')) {
            $this->warn('Scheduler is disabled in non-production environments (APP_ENV='.app()->environment().').');
            $this->warn('Use --force with a specific --job to override for testing.');

            // Allow explicit single-job + force for dev testing
            if ($this->option('job') && $this->option('force')) {
                $this->info('Force-running single job in dev mode...');

                return $this->runSingleJob($this->option('job'));
            }

            return 0;
        }

        // Handle single job run
        if ($jobIdentifier = $this->option('job')) {
            return $this->runSingleJob($jobIdentifier);
        }

        $lock = Cache::lock(self::SCHEDULER_TICK_LOCK_KEY, self::SCHEDULER_TICK_LOCK_TTL);

        if (! $lock->get()) {
            $this->warn('Another scheduler tick is already running; skipping this invocation.');
            Log::warning('Scheduler tick skipped: lock already held');

            return 0;
        }

        try {
            $this->recordSchedulerHeartbeat();

            // APL #8A — dead-PID reconciliation (fast path). Catches crashed/OOM/rebooted
            // workers within one tick instead of waiting for timeout_minutes + 15.
            $reconciled = $this->scheduledJobService->reconcileDeadRunningJobs();
            if ($reconciled !== []) {
                $names = array_map(static fn (array $r) => "{$r['name']}(PID {$r['pid']})", $reconciled);
                $this->warn('Reconciled '.count($reconciled).' dead-PID running job(s): '.implode(', ', $names));
            }

            // Fix stuck jobs on every scheduler tick (before checking due jobs)
            $fixed = $this->scheduledJobService->fixStuckJobs();
            if ($fixed > 0) {
                $this->warn("Auto-fixed {$fixed} stuck job(s)");
            }

            // Framework C2 — sweep expired-unreleased TaskCustodyRecord rows.
            // Runs AFTER reconcileDeadRunningJobs/fixStuckJobs so any custody
            // rows orphaned by those paths get released cleanly in the same
            // tick. Liveness probe inside sweep() skips records whose owners
            // are still alive, so this is safe to run alongside a job that
            // may be releasing its own custody at the same moment.
            $swept = $this->custodyService->sweep();
            if ($swept !== []) {
                $this->warn('Swept '.count($swept).' expired-unreleased custody record(s)');
            }

            // Get due jobs
            $dueJobs = $this->scheduledJobService->getDueJobs();

            if (empty($dueJobs)) {
                if ($this->option('dry-run')) {
                    $this->info('No jobs are due to run.');
                }

                return 0;
            }

            $this->info(sprintf('Found %d job(s) due to run.', count($dueJobs)));

            foreach ($dueJobs as $job) {
                if ($this->option('dry-run')) {
                    $resolved = ($job->max_parallel ?? 1) > 1 ? $this->scheduledJobService->resolveMaxParallel($job) : 1;
                    $this->line(sprintf(
                        '  [DRY-RUN] Would run: %s (%s)%s',
                        $job->name,
                        $job->command,
                        $resolved > 1 ? " [workers:{$resolved}/{$job->max_parallel}, running:{$job->running_count}]" : ''
                    ));

                    continue;
                }

                $this->runJob($job);
            }

            return 0;
        } finally {
            rescue(static fn () => $lock->release(), report: false);
        }
    }

    private function recordSchedulerHeartbeat(): void
    {
        DB::table('system_configs')->updateOrInsert(
            [
                'section' => 'scheduler',
                'config_key' => 'last_heartbeat_at',
            ],
            [
                'config_value' => now()->toIso8601String(),
                'data_type' => 'datetime',
                'description' => 'Last successful scheduler:run production tick heartbeat',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Run a specific job by ID or name
     */
    private function runSingleJob(string $identifier): int
    {
        // Try as ID first
        $job = is_numeric($identifier)
            ? $this->scheduledJobService->getJob((int) $identifier)
            : $this->scheduledJobService->getJobByName($identifier);

        if (! $job) {
            $this->error("Job not found: {$identifier}");

            return 1;
        }

        if (! $job->enabled && ! $this->option('force')) {
            $this->warn("Job '{$job->name}' is disabled. Use --force to run anyway.");

            return 1;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('[DRY-RUN] Would run: %s (%s)', $job->name, $job->command));

            return 0;
        }

        return $this->runJob($job) ? 0 : 1;
    }

    /**
     * Execute a job
     *
     * @param  object  $job  The job to run
     * @param  bool  $forceSync  Force synchronous execution (ignores run_in_background flag)
     */
    private function runJob(object $job, bool $forceSync = false): bool
    {
        $this->line(sprintf('Running: %s', $job->name));
        Log::info("Scheduler running job: {$job->name}", ['job_id' => $job->id]);

        $startTime = microtime(true);

        // Run in background only if configured AND not forced to sync
        if ($job->run_in_background && ! $forceSync && ! $this->option('sync')) {
            // Run in background - fork the process
            $result = $this->runInBackground($job);
        } else {
            // Install wall-clock timeout for sync execution via pcntl_alarm
            $this->installWallClockTimeout($job);

            // Build adaptive timeout extender closure for agent tasks
            $timeoutExtender = $this->buildTimeoutExtender($job, $startTime);

            // Run synchronously — if we have a worker-id, find the existing run record
            // created by the parent to avoid duplicate run records (zombie fix)
            $existingRunId = null;
            $workerId = $this->option('worker-id');
            if ($workerId) {
                $existing = DB::selectOne("
                    SELECT id FROM scheduled_job_runs
                    WHERE scheduled_job_id = ? AND worker_id = ? AND status = 'running'
                    ORDER BY id DESC LIMIT 1
                ", [$job->id, $workerId]);
                $existingRunId = $existing->id ?? null;
            }
            $result = $this->scheduledJobService->runJobNow($job->id, 'scheduler', $existingRunId, $timeoutExtender);

            // Cancel alarm and clean up deadline key on normal completion
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            Cache::forget("scheduler:job:{$job->id}:deadline");
        }

        $duration = round(microtime(true) - $startTime, 2);

        if ($result['success']) {
            $this->info(sprintf('  ✓ Completed in %.2fs', $duration));
        } else {
            $this->error(sprintf('  ✗ Failed: %s', $result['error'] ?? $result['output'] ?? 'Unknown error'));
            Log::error("Scheduler job failed: {$job->name}", [
                'job_id' => $job->id,
                'error' => $result['error'] ?? $result['output'] ?? 'Unknown error',
            ]);
        }

        return $result['success'];
    }

    /**
     * Install a hard wall-clock timeout for synchronous job execution.
     * Uses pcntl_alarm + SIGALRM to kill the process if it exceeds timeout_minutes.
     */
    private function installWallClockTimeout(object $job): void
    {
        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal')) {
            return;
        }

        $timeoutMinutes = $job->timeout_minutes ?? 120;
        $timeoutSeconds = $timeoutMinutes * 60;

        pcntl_signal(SIGALRM, function () use ($job, $timeoutMinutes) {
            $msg = "[SIGALRM] Job #{$job->id} ({$job->name}) exceeded {$timeoutMinutes}min wall-clock timeout, terminating";
            Log::error("Scheduler: {$msg}");

            // Mark job as failed in DB before exiting
            // Use a short statement timeout to avoid deadlocking on hung connections
            try {
                DB::connection('mysql')->reconnect();
                DB::connection('mysql')->statement('SET SESSION wait_timeout = 3');

                DB::connection('mysql')->update("
                    UPDATE scheduled_jobs
                    SET last_run_status = 'failed', last_pid = NULL, running_pids = NULL, running_count = 0,
                        last_run_output = CONCAT(COALESCE(last_run_output, ''), '\n{$msg}')
                    WHERE id = ?
                ", [$job->id]);

                DB::connection('mysql')->update("
                    UPDATE scheduled_job_runs
                    SET status = 'failed', completed_at = NOW(),
                        output = CONCAT(COALESCE(output, ''), '\n{$msg}')
                    WHERE scheduled_job_id = ? AND status = 'running'
                ", [$job->id]);

                // Clean up adaptive timeout deadline key
                Cache::forget("scheduler:job:{$job->id}:deadline");

                // Release any in-progress genealogy research queue items
                DB::connection('mysql')->update("
                    UPDATE genealogy_research_queue
                    SET status = 'failed', completed_at = NOW(),
                        notes = CONCAT(COALESCE(notes, ''), ' [SIGALRM timeout]'),
                        updated_at = NOW()
                    WHERE status = 'in_progress'
                      AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                ", [$timeoutMinutes]);
            } catch (\Throwable $e) {
                // Best effort — we're about to exit anyway
            }

            // exit() can deadlock inside a signal handler when cURL is blocking.
            // SIGKILL is unblockable and guarantees process termination.
            if (function_exists('posix_kill')) {
                posix_kill(getmypid(), SIGKILL);
            }
            exit(124); // Fallback if posix_kill unavailable
        });

        pcntl_alarm($timeoutSeconds);

        Log::debug('Scheduler: pcntl_alarm set', [
            'job' => $job->name,
            'timeout_minutes' => $timeoutMinutes,
            'timeout_seconds' => $timeoutSeconds,
            'pid' => getmypid(),
        ]);
    }

    /**
     * Build a closure that allows agents to extend their pcntl_alarm deadline mid-run.
     * Returns null if pcntl is unavailable. The closure validates against a hard ceiling
     * (from SKILL.md max_timeout_minutes or config) and updates a Redis key so zombie
     * detectors know the job is still legitimately running.
     */
    private function buildTimeoutExtender(object $job, float $jobStartTime): ?\Closure
    {
        if (! function_exists('pcntl_alarm')) {
            return null;
        }

        $jobId = $job->id;
        $jobName = $job->name;
        // SkillLoaderService caches max_timeout under the skill name (e.g. "genealogy-researcher"),
        // not the scheduled job name (e.g. "genealogy_agent_research_queue"). For agent_task jobs,
        // $job->command holds the skill name.
        $skillName = ($job->job_type === 'agent_task') ? $job->command : $jobName;
        $originalTimeoutMinutes = $job->timeout_minutes ?? 120;

        return function (int $newTotalMinutes) use ($jobId, $jobName, $skillName, $jobStartTime, $originalTimeoutMinutes): bool {
            // Resolve ceiling: SKILL.md max_timeout_minutes (cached by SkillLoader) → config fallback
            $maxMinutes = (int) Cache::get(
                "skill_max_timeout:{$skillName}",
                config('agents.adaptive_timeout_max_minutes', 150)
            );

            if ($newTotalMinutes > $maxMinutes) {
                Log::warning('Scheduler: Timeout extension denied — exceeds ceiling', [
                    'job' => $jobName, 'requested' => $newTotalMinutes, 'max' => $maxMinutes,
                ]);

                return false;
            }

            if ($newTotalMinutes <= $originalTimeoutMinutes) {
                return true; // Already within original budget
            }

            // Calculate remaining seconds from new total
            $elapsedSeconds = microtime(true) - $jobStartTime;
            $newTotalSeconds = $newTotalMinutes * 60;
            $remainingSeconds = max(60, (int) ($newTotalSeconds - $elapsedSeconds));

            // Reset the hard wall-clock alarm
            pcntl_alarm($remainingSeconds);

            // Update Redis deadline so zombie detectors know we're still alive
            $deadline = time() + $remainingSeconds;
            Cache::put("scheduler:job:{$jobId}:deadline", $deadline, $remainingSeconds + 300);

            Log::info('Scheduler: Timeout extended', [
                'job' => $jobName,
                'new_total_minutes' => $newTotalMinutes,
                'remaining_seconds' => $remainingSeconds,
                'deadline' => date('Y-m-d H:i:s', $deadline),
            ]);

            return true;
        };
    }

    /**
     * Run job in background with PID capture
     *
     * Marks job as 'running' and advances next_run_at BEFORE spawning the child
     * process. Captures child PID for zombie detection.
     *
     * For parallel jobs (max_parallel > 1), creates a separate worker with its own
     * run record and worker_id. Only advances next_run_at when all worker slots are filled.
     */
    private function runInBackground(object $job): array
    {
        $maxParallel = $job->max_parallel ?? 1;
        $isParallel = $maxParallel > 1;

        if ($isParallel) {
            return $this->runParallelWorker($job);
        }

        // --- Single worker path (PID capture) ---
        // NOTE: Do NOT create a run record here. The child process creates its own
        // via markJobStarted(). Creating one here caused zombie records: the parent's
        // PID dies after exec(), zombie cleanup marks it failed, polluting job status.

        // Mark job as running + advance next_run_at to prevent overlap
        DB::update("
            UPDATE scheduled_jobs SET
                last_run_at = NOW(),
                last_run_status = 'running',
                last_pid = NULL,
                updated_at = NOW()
            WHERE id = ?
        ", [$job->id]);

        // Advance next_run_at so it's not due again
        $nextRun = (new \Cron\CronExpression($job->cron_expression))
            ->getNextRunDate(now()->toDateTimeImmutable())
            ->format('Y-m-d H:i:s');
        DB::update('UPDATE scheduled_jobs SET next_run_at = ? WHERE id = ?', [$nextRun, $job->id]);

        // Spawn the background process and capture PID
        $pendingProcess = Process::path(base_path())
            ->forever()
            ->quietly();

        if (! app()->runningUnitTests()) {
            $pendingProcess->options(['create_new_console' => true]);
        }

        $process = $pendingProcess->start([
            PHP_BINARY,
            'artisan',
            'scheduler:run',
            '--job='.$job->id,
            '--force',
            '--sync',
        ]);
        $pid = (int) ($process->id() ?? 0);

        // Store PID on scheduled_jobs only — child hasn't created its run record yet.
        // Child's markJobStarted() will pick up last_pid and copy to its run record.
        if ($pid > 0) {
            DB::update('UPDATE scheduled_jobs SET last_pid = ? WHERE id = ?', [$pid, $job->id]);
            Log::info("Scheduler: Spawned background PID {$pid} for job #{$job->id} ({$job->name})");
        }

        return [
            'success' => true,
            'output' => 'Job started in background'.($pid > 0 ? " (PID {$pid})" : ''),
        ];
    }

    /**
     * Spawn a parallel worker for jobs with max_parallel > 1.
     */
    private function runParallelWorker(object $job): array
    {
        // Confirm another worker is warranted via dynamic resolution
        $resolved = $this->scheduledJobService->resolveMaxParallel($job);
        $currentRunning = $job->running_count ?? 0;

        if ($currentRunning >= $resolved) {
            return [
                'success' => true,
                'output' => "Parallel slot not needed (running: {$currentRunning}, resolved max: {$resolved})",
            ];
        }

        // Create parallel worker run record
        $result = $this->scheduledJobService->markParallelWorkerStarted($job->id);
        $runId = $result['run_id'];
        $workerId = $result['worker_id'];

        // Only advance next_run_at when we're filling the last slot
        if ($currentRunning + 1 >= $resolved) {
            $nextRun = (new \Cron\CronExpression($job->cron_expression))
                ->getNextRunDate(now()->toDateTimeImmutable())
                ->format('Y-m-d H:i:s');
            DB::update('UPDATE scheduled_jobs SET next_run_at = ? WHERE id = ?', [$nextRun, $job->id]);
        }

        // Spawn background process with worker-id
        $pendingProcess = Process::path(base_path())
            ->forever()
            ->quietly();

        if (! app()->runningUnitTests()) {
            $pendingProcess->options(['create_new_console' => true]);
        }

        $process = $pendingProcess->start([
            PHP_BINARY,
            'artisan',
            'scheduler:run',
            '--job='.$job->id,
            '--force',
            '--sync',
            '--worker-id='.$workerId,
        ]);
        $pid = (int) ($process->id() ?? 0);

        // Register worker PID
        if ($pid > 0) {
            $this->scheduledJobService->registerWorkerPid($job->id, $pid, $runId);
            Log::info("Scheduler: Spawned parallel worker PID {$pid} (worker: {$workerId}) for job #{$job->id} ({$job->name})");
        }

        return [
            'success' => true,
            'output' => "Parallel worker started (PID {$pid}, worker: {$workerId})",
        ];
    }
}

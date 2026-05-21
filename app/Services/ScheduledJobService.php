<?php

namespace App\Services;

use App\Jobs\ExecuteWorkflow;
use App\Services\Custody\TaskCustodyService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * ScheduledJobService - Centralized job scheduling with cron pattern support
 *
 * Replaces Laravel's routes/console.php with database-driven scheduling.
 * Supports full 5-field cron expressions (minute hour day month weekday).
 *
 * Cron format: * * * * *
 *              │ │ │ │ │
 *              │ │ │ │ └─ Day of week (0-6, Sunday=0)
 *              │ │ │ └─── Month (1-12)
 *              │ │ └───── Day of month (1-31)
 *              │ └─────── Hour (0-23)
 *              └───────── Minute (0-59)
 *
 * Special patterns:
 *
 *   @hourly   = 0 * * * *
 *
 *   @daily    = 0 0 * * *
 *
 *   @weekly   = 0 0 * * 0
 *
 *   @monthly  = 0 0 1 * *
 */
class ScheduledJobService
{
    /**
     * Common cron pattern aliases
     */
    private const CRON_ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    /**
     * Get all scheduled jobs
     */
    public function getAllJobs(): array
    {
        $sql = 'SELECT * FROM scheduled_jobs ORDER BY source_module, name';

        return $this->enrichJobsWithHealthMetrics(DB::select($sql));
    }

    /**
     * Get jobs grouped by source module
     */
    public function getJobsByModule(): array
    {
        $jobs = $this->getAllJobs();
        $grouped = [];

        foreach ($jobs as $job) {
            $module = $job->source_module ?? 'Uncategorized';
            if (! isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $job;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Get a single job by ID
     */
    public function getJob(int $id): ?object
    {
        $sql = 'SELECT * FROM scheduled_jobs WHERE id = ? LIMIT 1';
        $results = DB::select($sql, [$id]);

        $job = $results[0] ?? null;

        if (! $job) {
            return null;
        }

        return $this->enrichJobsWithHealthMetrics([$job])[0];
    }

    /**
     * Get a single job by name
     */
    public function getJobByName(string $name): ?object
    {
        $sql = 'SELECT * FROM scheduled_jobs WHERE name = ? LIMIT 1';
        $results = DB::select($sql, [$name]);

        $job = $results[0] ?? null;

        if (! $job) {
            return null;
        }

        return $this->enrichJobsWithHealthMetrics([$job])[0];
    }

    /**
     * Attach recent health metrics to scheduled job payloads.
     */
    public function enrichJobsWithHealthMetrics(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        $jobIds = array_map(fn ($job) => (int) $job->id, $jobs);
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));

        $recentHealthRows = DB::select("
            SELECT
                scheduled_job_id,
                COUNT(*) AS runs_24h,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failures_24h,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successes_24h
            FROM scheduled_job_runs
            WHERE scheduled_job_id IN ({$placeholders})
              AND started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY scheduled_job_id
        ", $jobIds);

        $consecutiveFailureRows = DB::select("
            SELECT
                sj.id AS scheduled_job_id,
                (
                    SELECT COUNT(*)
                    FROM scheduled_job_runs sjr
                    WHERE sjr.scheduled_job_id = sj.id
                      AND sjr.status = 'failed'
                      AND sjr.started_at > COALESCE(
                          (
                              SELECT MAX(s2.started_at)
                              FROM scheduled_job_runs s2
                              WHERE s2.scheduled_job_id = sj.id
                                AND s2.status = 'success'
                          ),
                          '2000-01-01'
                      )
                ) AS consecutive_failures
            FROM scheduled_jobs sj
            WHERE sj.id IN ({$placeholders})
        ", $jobIds);

        $recentHealth = [];
        foreach ($recentHealthRows as $row) {
            $recentHealth[(int) $row->scheduled_job_id] = $row;
        }

        $consecutiveFailures = [];
        foreach ($consecutiveFailureRows as $row) {
            $consecutiveFailures[(int) $row->scheduled_job_id] = (int) $row->consecutive_failures;
        }

        return array_map(function ($job) use ($recentHealth, $consecutiveFailures) {
            $jobId = (int) $job->id;
            $recent = $recentHealth[$jobId] ?? null;
            $runs24h = (int) ($recent->runs_24h ?? 0);
            $failures24h = (int) ($recent->failures_24h ?? 0);
            $successes24h = (int) ($recent->successes_24h ?? 0);

            $job->runs_24h = $runs24h;
            $job->failures_24h = $failures24h;
            $job->success_rate_24h = $runs24h > 0
                ? round(($successes24h / $runs24h) * 100, 1)
                : null;
            $job->consecutive_failures = $consecutiveFailures[$jobId] ?? 0;

            return $job;
        }, $jobs);
    }

    /**
     * Create a new scheduled job
     */
    public function createJob(array $data): int
    {
        $cronExpression = $this->normalizeCronExpression($data['cron_expression']);
        $nextRun = $this->calculateNextRun($cronExpression);
        $notes = $this->normalizeJobNotes($data['notes'] ?? null);

        DB::insert('
            INSERT INTO scheduled_jobs
            (name, description, job_type, command, cron_expression, enabled, run_in_background,
             without_overlapping, timeout_minutes, notes, category, source_module, next_run_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ', [
            $data['name'],
            $data['description'] ?? null,
            $data['job_type'] ?? 'command',
            $data['command'],
            $cronExpression,
            $data['enabled'] ?? true,
            $data['run_in_background'] ?? true,
            $data['without_overlapping'] ?? true,
            $data['timeout_minutes'] ?? 60,
            $notes,
            $data['category'] ?? null,
            $data['source_module'] ?? null,
            $nextRun?->format('Y-m-d H:i:s'),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a scheduled job
     */
    public function updateJob(int $id, array $data): bool
    {
        $job = $this->getJob($id);
        if (! $job) {
            return false;
        }

        $cronExpression = isset($data['cron_expression'])
            ? $this->normalizeCronExpression($data['cron_expression'])
            : $job->cron_expression;

        $nextRun = $this->calculateNextRun($cronExpression);
        $notes = array_key_exists('notes', $data)
            ? $this->normalizeJobNotes($data['notes'])
            : $this->normalizeJobNotes($job->notes ?? null);

        $sql = '
            UPDATE scheduled_jobs SET
                name = ?,
                description = ?,
                job_type = ?,
                command = ?,
                cron_expression = ?,
                enabled = ?,
                run_in_background = ?,
                without_overlapping = ?,
                timeout_minutes = ?,
                notes = ?,
                category = ?,
                source_module = ?,
                next_run_at = ?,
                updated_at = NOW()
            WHERE id = ?
        ';

        DB::update($sql, [
            $data['name'] ?? $job->name,
            $data['description'] ?? $job->description,
            $data['job_type'] ?? $job->job_type,
            $data['command'] ?? $job->command,
            $cronExpression,
            $data['enabled'] ?? $job->enabled,
            $data['run_in_background'] ?? $job->run_in_background,
            $data['without_overlapping'] ?? $job->without_overlapping,
            $data['timeout_minutes'] ?? $job->timeout_minutes,
            $notes,
            $data['category'] ?? $job->category,
            $data['source_module'] ?? $job->source_module,
            $nextRun?->format('Y-m-d H:i:s'),
            $id,
        ]);

        return true;
    }

    /**
     * Delete a scheduled job
     */
    public function deleteJob(int $id): bool
    {
        $affected = DB::delete('DELETE FROM scheduled_jobs WHERE id = ?', [$id]);

        return $affected > 0;
    }

    /**
     * Toggle job enabled status
     */
    public function toggleJob(int $id): ?bool
    {
        $job = $this->getJob($id);
        if (! $job) {
            return null;
        }

        $newStatus = ! $job->enabled;
        $nextRun = $newStatus ? $this->calculateNextRun($job->cron_expression) : null;

        DB::update('UPDATE scheduled_jobs SET enabled = ?, next_run_at = ?, updated_at = NOW() WHERE id = ?', [
            $newStatus,
            $nextRun?->format('Y-m-d H:i:s'),
            $id,
        ]);

        return $newStatus;
    }

    /**
     * Get jobs that are due to run now
     */
    public function getDueJobs(): array
    {
        $now = Carbon::now();

        // --- PID-based zombie detection: check all 'running' jobs with PIDs ---
        $this->cleanupDeadProcesses();

        // Auto-seed next_run_at for enabled jobs that were inserted without it
        $unseeded = DB::select('SELECT id, cron_expression FROM scheduled_jobs WHERE enabled = 1 AND next_run_at IS NULL');
        foreach ($unseeded as $job) {
            $nextRun = $this->calculateNextRun($job->cron_expression);
            if ($nextRun) {
                DB::update('UPDATE scheduled_jobs SET next_run_at = ? WHERE id = ?', [$nextRun->format('Y-m-d H:i:s'), $job->id]);
                Log::info("Auto-seeded next_run_at for job #{$job->id}", ['next_run_at' => $nextRun->format('Y-m-d H:i:s')]);
            }
        }

        $sql = "
            SELECT * FROM scheduled_jobs
            WHERE enabled = 1
            AND next_run_at IS NOT NULL
            AND next_run_at <= ?
            AND (
                last_run_status IS NULL
                OR last_run_status != 'running'
                OR without_overlapping = 0
                OR (max_parallel > 1 AND running_count < max_parallel)
            )
            ORDER BY next_run_at ASC
        ";

        $jobs = DB::select($sql, [$now->format('Y-m-d H:i:s')]);

        // For parallel jobs, check if dynamic resolution actually allows another worker
        return array_values(array_filter($jobs, function ($job) {
            if (($job->max_parallel ?? 1) > 1 && ($job->running_count ?? 0) > 0) {
                $resolved = $this->resolveMaxParallel($job);
                if (($job->running_count ?? 0) >= $resolved) {
                    return false; // Dynamic resolution says no more workers
                }
            }

            return true;
        }));
    }

    /**
     * Mark job as started
     */
    public function markJobStarted(int $jobId, string $triggeredBy = 'scheduler'): int
    {
        // Use own PID for the run record — this is the actual executing process.
        // Parent may have set last_pid on scheduled_jobs, but using getmypid()
        // is more reliable and avoids any race condition.
        $pid = getmypid() ?: null;

        DB::update("
            UPDATE scheduled_jobs SET
                last_run_at = NOW(),
                last_run_status = 'running',
                last_run_output = NULL,
                last_pid = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$pid, $jobId]);

        DB::insert("
            INSERT INTO scheduled_job_runs (scheduled_job_id, started_at, status, triggered_by, pid)
            VALUES (?, NOW(), 'running', ?, ?)
        ", [$jobId, $triggeredBy, $pid]);

        $runId = (int) DB::getPdo()->lastInsertId();

        // Framework C2: record a task custody lease alongside the run row.
        // Per docs/plos-task-lease-contract.md, the TCR is authoritative for
        // "who owns this task right now" across process boundaries. It
        // augments — does not replace — `last_pid` / `running_pids` etc.
        $this->acquireRunCustody($jobId, $runId, $pid);

        return $runId;
    }

    /**
     * Framework C2 — acquire a TaskCustodyRecord for this scheduled-job run.
     *
     * Surface ref scheme: `"{jobId}:{runId}"` (per-run, NOT per-job).
     *
     * Rationale: a parallel job (`max_parallel > 1`) has N independent workers
     * each holding their own sub-task. The lease contract says one custody row
     * = one owner holding one task — which for a parallel job means N rows,
     * one per worker. Keying on (jobId, runId) gives each worker its own
     * `active_key` slot so the partial-unique index still blocks duplicate
     * acquires FOR THE SAME RUN while allowing sibling workers to coexist.
     * Single-worker jobs are the degenerate case of one run per job.
     *
     * Expiry mirrors the zombie-detector slack: timeout_minutes + 15 min.
     * Failure is non-fatal; the existing run still proceeds so the scheduler
     * never depends on TCR for forward progress.
     *
     * Idempotent across the spawn boundary: the parent process can acquire in
     * registerWorkerPid() using the child's pid; when the child then re-enters
     * via runJobNow($existingRunId) it computes the same owner_token (same
     * child pid, same runId) and TaskCustodyService::acquire() returns the
     * existing row without creating a duplicate.
     */
    private function acquireRunCustody(int $jobId, int $runId, ?int $pid): void
    {
        try {
            $row = DB::selectOne(
                'SELECT timeout_minutes FROM scheduled_jobs WHERE id = ?',
                [$jobId]
            );
            $timeoutMinutes = max(1, (int) ($row->timeout_minutes ?? 60));
            $expiresInSeconds = ($timeoutMinutes + 15) * 60;

            $ownerToken = sprintf('pid:%s:run:%d', $pid ?? 'unknown', $runId);

            app(TaskCustodyService::class)->acquire(
                TaskCustodyService::SURFACE_SCHEDULED_JOB,
                $this->custodySurfaceRef($jobId, $runId),
                $ownerToken,
                $expiresInSeconds
            );
        } catch (\Throwable $e) {
            Log::warning('ScheduledJobService: custody acquire failed (non-fatal)', [
                'job_id' => $jobId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the TCR surface_ref for a scheduled-job run. Centralized so every
     * acquire/release/reconcile path agrees on the key format.
     */
    public static function custodySurfaceRef(int $jobId, int $runId): string
    {
        return $jobId.':'.$runId;
    }

    /**
     * Framework C2 — release the TaskCustodyRecord for THIS specific run.
     *
     * Surface ref scheme: `"{jobId}:{runId}"` — matches acquireRunCustody().
     * For parallel jobs this means each worker releases only its own row;
     * sibling workers' custody rows are untouched. For single-worker jobs
     * the behavior is identical to the pre-change code (one row, released
     * at completion).
     *
     * Idempotent: if the row was already released by the recovery sweep or
     * by reconcileDeadRunningJobs, `findUnreleased` returns null and this
     * is a no-op. Failure is non-fatal.
     */
    private function releaseRunCustody(int $jobId, int $runId, bool $success, ?string $output, ?int $itemsProcessed): void
    {
        try {
            $custody = app(TaskCustodyService::class)->findUnreleased(
                TaskCustodyService::SURFACE_SCHEDULED_JOB,
                self::custodySurfaceRef($jobId, $runId)
            );
            if (! $custody) {
                return;
            }

            $envelope = [
                'success' => $success,
                'run_id' => $runId,
                'items_processed' => $itemsProcessed,
                'output_excerpt' => $output !== null ? mb_substr($output, 0, 500) : null,
            ];

            app(TaskCustodyService::class)->release(
                (int) $custody->id,
                $success ? 'success' : 'failure',
                $envelope
            );
        } catch (\Throwable $e) {
            Log::warning('ScheduledJobService: custody release failed (non-fatal)', [
                'job_id' => $jobId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark job as completed (success or failure)
     *
     * For parallel jobs (max_parallel > 1), unregisters the worker PID and only
     * resets the job status when the last worker finishes.
     */
    public function markJobCompleted(int $jobId, int $runId, bool $success, ?string $output = null, ?float $duration = null, ?int $pid = null, ?int $itemsProcessed = null): void
    {
        $status = $success ? 'success' : 'failed';
        $job = $this->getJob($jobId);

        // Extract items_processed from output marker if not explicitly provided
        if ($itemsProcessed === null && $output) {
            if (preg_match('/\[ITEMS_PROCESSED:(\d+)\]/', $output, $m)) {
                $itemsProcessed = (int) $m[1];
            }
        }

        // For parallel jobs, unregister this worker first
        if ($job && ($job->max_parallel ?? 1) > 1 && $pid) {
            $this->unregisterWorkerPid($jobId, $pid);
            $job = $this->getJob($jobId); // Refresh to get updated running_count
        }

        // Only update job-level status when no more workers are running
        $isLastWorker = ! $job || ($job->running_count ?? 0) <= 0;

        if ($isLastWorker) {
            $nextRun = $job ? $this->calculateNextRun($job->cron_expression) : null;

            $sql = '
                UPDATE scheduled_jobs SET
                    last_completed_at = NOW(),
                    last_run_status = ?,
                    last_run_output = ?,
                    last_pid = NULL,
                    next_run_at = ?,
                    run_count = run_count + ?,
                    fail_count = fail_count + ?,
                    updated_at = NOW()
                WHERE id = ?
            ';

            DB::update($sql, [
                $status,
                $output ? mb_substr($output, 0, 16000) : null,
                $nextRun?->format('Y-m-d H:i:s'),
                $success ? 1 : 0,
                $success ? 0 : 1,
                $jobId,
            ]);
        } else {
            // Other workers still running — only increment counts, don't change job status
            DB::update('
                UPDATE scheduled_jobs SET
                    run_count = run_count + ?,
                    fail_count = fail_count + ?,
                    updated_at = NOW()
                WHERE id = ?
            ', [
                $success ? 1 : 0,
                $success ? 0 : 1,
                $jobId,
            ]);
        }

        // Always update the individual run record (including items_processed)
        DB::update('
            UPDATE scheduled_job_runs SET
                completed_at = NOW(),
                status = ?,
                output = ?,
                duration_seconds = ?,
                items_processed = ?
            WHERE id = ?
        ', [
            $status,
            $output ? mb_substr($output, 0, 16000) : null,
            $duration,
            $itemsProcessed,
            $runId,
        ]);

        // Clean up adaptive timeout deadline key
        Cache::forget("scheduler:job:{$jobId}:deadline");

        // Framework C2 — release the custody record for THIS run. Surface ref
        // is per-run ("{jobId}:{runId}") so parallel workers each release
        // their own row; the previous isLastWorker gate was a relic of the
        // per-job scheme where only one custody row existed across all
        // workers.
        $this->releaseRunCustody($jobId, $runId, $success, $output, $itemsProcessed);

        // Adapt timeout for this job based on historical performance
        if ($success && $duration > 0 && $isLastWorker) {
            $this->adaptJobTimeout($jobId);
        }
    }

    /**
     * Dynamically adapt a job's timeout_minutes based on p95 historical duration.
     *
     * Requires ≥5 successful runs. Skips if timeout_locked=1 (human override).
     * New timeout = ceil(p95_duration_minutes) + 15 min buffer, clamped to the
     * job-type floor and a hard ceiling of 360 minutes.
     * Only updates if difference >10% from current to avoid churn.
     */
    public function adaptJobTimeout(int $jobId): void
    {
        try {
            $job = $this->getJob($jobId);
            if (! $job) {
                return;
            }

            // Skip if human has locked the timeout
            if ($job->timeout_locked ?? false) {
                return;
            }

            // Need at least 5 successful runs to adapt
            $stats = DB::selectOne("
                SELECT
                    COUNT(*) as run_count,
                    MAX(duration_seconds) as max_duration,
                    AVG(duration_seconds) as avg_duration
                FROM (
                    SELECT duration_seconds
                    FROM scheduled_job_runs
                    WHERE scheduled_job_id = ?
                      AND status = 'success'
                      AND duration_seconds > 0
                    ORDER BY completed_at DESC
                    LIMIT 20
                ) recent
            ", [$jobId]);

            if (! $stats || $stats->run_count < 5) {
                return;
            }

            // Calculate p95 using PERCENT_RANK approximation (get 95th percentile from last 20 runs)
            $p95Row = DB::selectOne("
                SELECT duration_seconds
                FROM scheduled_job_runs
                WHERE scheduled_job_id = ?
                  AND status = 'success'
                  AND duration_seconds > 0
                ORDER BY duration_seconds DESC
                LIMIT 1 OFFSET ?
            ", [$jobId, max(0, (int) floor($stats->run_count * 0.05))]);

            if (! $p95Row || $p95Row->duration_seconds <= 0) {
                return;
            }

            $p95Minutes = $p95Row->duration_seconds / 60;
            $newTimeout = (int) ceil($p95Minutes) + 15;
            $newTimeout = max($this->getAdaptiveTimeoutFloor($job), min(360, $newTimeout));

            $currentTimeout = $job->timeout_minutes ?? 90;

            // Only update if difference >10%
            if (abs($newTimeout - $currentTimeout) / max($currentTimeout, 1) <= 0.10) {
                return;
            }

            $changeRatio = ($currentTimeout - $newTimeout) / max($currentTimeout, 1);
            if ($changeRatio >= 0.20) {
                Log::warning('ScheduledJob: Timeout adaptation reducing configured timeout', [
                    'job_id' => $jobId,
                    'job_name' => $job->name,
                    'old_timeout' => $currentTimeout,
                    'new_timeout' => $newTimeout,
                    'reduction_percent' => round($changeRatio * 100, 1),
                    'p95_minutes' => round($p95Minutes, 1),
                    'sample_size' => $stats->run_count,
                    'timeout_locked' => (bool) ($job->timeout_locked ?? false),
                ]);
            }

            DB::update('
                UPDATE scheduled_jobs SET timeout_minutes = ?, updated_at = NOW() WHERE id = ?
            ', [$newTimeout, $jobId]);

            Log::info('ScheduledJob: Timeout adapted', [
                'job_id' => $jobId,
                'job_name' => $job->name,
                'old_timeout' => $currentTimeout,
                'new_timeout' => $newTimeout,
                'p95_minutes' => round($p95Minutes, 1),
                'sample_size' => $stats->run_count,
            ]);
        } catch (\Exception $e) {
            Log::warning('ScheduledJob: Timeout adaptation failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getAdaptiveTimeoutFloor(object $job): int
    {
        return (($job->job_type ?? null) === 'agent_task') ? 60 : 30;
    }

    /**
     * Run a specific job immediately (manual trigger)
     */
    public function runJobNow(int $jobId, string $triggeredBy = 'manual', ?int $existingRunId = null, ?\Closure $timeoutExtender = null): array
    {
        $job = $this->getJob($jobId);
        if (! $job) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        // Check for overlapping if enabled (skip for scheduler — parent already validated)
        if ($triggeredBy !== 'scheduler' && $job->without_overlapping && $job->last_run_status === 'running') {
            return ['success' => false, 'error' => 'Job is already running'];
        }

        // Reuse parent's run record if provided (parallel worker path), else create new
        if ($existingRunId) {
            $runId = $existingRunId;
            // Still update job status and set our PID on the existing run
            $pid = getmypid() ?: null;
            DB::update("
                UPDATE scheduled_jobs SET last_run_at = NOW(), last_run_status = 'running', last_pid = ?, updated_at = NOW()
                WHERE id = ?
            ", [$pid, $jobId]);
            DB::update('UPDATE scheduled_job_runs SET pid = ? WHERE id = ?', [$pid, $runId]);

            // C2 — acquire custody for this run. For parallel workers this is
            // idempotent with the parent's registerWorkerPid() acquire (same
            // pid, same runId → same owner_token). For non-parallel paths
            // that reuse an existing run (e.g., the UI-triggered
            // `scheduled-job:run-now` command, which creates the run row in
            // the controller and passes $existingRunId), this is the
            // authoritative acquire — otherwise the run would have no
            // custody at all.
            $this->acquireRunCustody($jobId, $runId, $pid);
        } else {
            $runId = $this->markJobStarted($jobId, $triggeredBy);
        }
        $startTime = microtime(true);
        $output = '';
        $success = false;

        try {
            switch ($job->job_type) {
                case 'command':
                    $exitCode = $this->runArtisanCommand($job->command, $output);
                    $success = ($exitCode === 0);
                    break;

                case 'workflow':
                    $success = $this->runWorkflow($job->command, $output);
                    break;

                case 'job_class':
                    $success = $this->dispatchJobClass($job->command, $output);
                    break;

                case 'agent_task':
                    $success = $this->runAgentTask(
                        $job->command,
                        $job->notes ?? '{}',
                        $output,
                        $job->timeout_minutes ?? 30,
                        $timeoutExtender,
                        $job->name
                    );
                    break;

                default:
                    $output = "Unknown job type: {$job->job_type}";
                    $success = false;
            }
        } catch (\Throwable $e) {
            $output = 'Exception: '.$e->getMessage();
            $success = false;
            Log::error("ScheduledJob {$job->name} failed", [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $duration = round(microtime(true) - $startTime, 2);
        $pid = getmypid() ?: null;
        $this->markJobCompleted($jobId, $runId, $success, $output, $duration, $pid);

        return [
            'success' => $success,
            'output' => $output,
            'duration' => $duration,
            'run_id' => $runId,
        ];
    }

    /**
     * Run artisan command and capture output
     */
    private function runArtisanCommand(string $command, string &$output): int
    {
        $commandLine = trim($command);
        if (preg_match('/^(?:php\s+)?artisan\s+(.+)$/', $commandLine, $matches)) {
            $commandLine = trim($matches[1]);
        }

        $exitCode = Artisan::call($commandLine);
        $output = Artisan::output();

        return $exitCode;
    }

    /**
     * Run a workflow by name
     */
    private function runWorkflow(string $workflowName, string &$output): bool
    {
        ExecuteWorkflow::dispatch($workflowName);
        $output = "Workflow queued: {$workflowName}";

        return true;
    }

    /**
     * Dispatch a Laravel job class
     */
    private function dispatchJobClass(string $jobClass, string &$output): bool
    {
        if (! class_exists($jobClass)) {
            $output = "Job class not found: {$jobClass}";

            return false;
        }

        try {
            $job = new $jobClass;
            if ($job instanceof ShouldQueue) {
                dispatch($job);
                $output = 'Job queued successfully';
            } else {
                dispatch_sync($job);
                $output = 'Job dispatched synchronously';
            }

            return true;
        } catch (\Exception $e) {
            $output = 'Job dispatch failed: '.$e->getMessage();

            return false;
        }
    }

    /**
     * Run an agent task via AgentLoopService
     *
     * Command format: "skill_name" (the agent skill to execute)
     * Parameters JSON: {"task": "description", "tree_id": 1, "notify": true, ...}
     */
    private function runAgentTask(
        string $skillName,
        string $parametersJson,
        string &$output,
        int $timeoutMinutes = 30,
        ?\Closure $timeoutExtender = null,
        ?string $scheduledJobName = null
    ): bool {
        try {
            $params = $this->decodeJobParameters($parametersJson);
            $agentLoop = app(AgentLoopService::class);
            $runtimeMode = $this->extractRuntimeValue($params, 'runtime_mode');

            if ($runtimeMode === 'direct_assess') {
                return $this->runGenealogyAssess($params, $output, $timeoutMinutes);
            }

            // genealogy_agent_assess is intentionally a direct DB-backed assess pass.
            // Route by scheduled job name so a drifted command/notes config cannot turn
            // it back into a slow agent loop that times out before queue population.
            if ($scheduledJobName === 'genealogy_agent_assess') {
                return $this->runGenealogyAssess($params, $output, $timeoutMinutes);
            }

            if ($scheduledJobName === 'research_analyst_agent') {
                return $this->runResearchAnalystSnapshot($agentLoop, $output);
            }

            // Mode-aware routing for genealogy research workers. The queue job can legitimately
            // run either genealogy-researcher or genealogy-records depending on the deployed
            // scheduled_jobs config, so route by runtime mode for both.
            if (in_array($skillName, ['genealogy-researcher', 'genealogy-records'], true)) {
                $mode = $params['mode'] ?? 'full';
                if ($runtimeMode === 'queue_research') {
                    return $this->runGenealogyResearchFromQueue($agentLoop, $skillName, $params, $output, $timeoutMinutes, $timeoutExtender);
                }
                if ($mode === 'assess') {
                    return $this->runGenealogyAssess($params, $output, $timeoutMinutes);
                }
                if ($mode === 'research') {
                    return $this->runGenealogyResearchFromQueue($agentLoop, $skillName, $params, $output, $timeoutMinutes, $timeoutExtender);
                }
                if ($scheduledJobName && str_starts_with($scheduledJobName, 'genealogy_')) {
                    $output = "Automatic whole-tree genealogy runs are disabled for scheduled jobs ({$scheduledJobName}).";
                    Log::warning('ScheduledJobService: Blocked scheduled genealogy fallback', [
                        'scheduled_job_name' => $scheduledJobName,
                        'skill' => $skillName,
                        'runtime_mode' => $runtimeMode,
                        'mode' => $mode,
                    ]);

                    return false;
                }
                // mode === 'full': manual legacy path only
                if (empty($params['tree_id'])) {
                    return $this->runGenealogyAgentAllTrees($agentLoop, $params, $output);
                }
            }

            $task = $params['task'] ?? $this->getDefaultAgentTask($skillName, $params);
            $options = [
                'tree_id' => $params['tree_id'] ?? null,
                'notify' => $params['notify'] ?? $this->shouldAgentNotify($skillName),
                'model' => $params['model'] ?? null,
                'model_role' => $params['model_role'] ?? null,
                'context' => $params['context'] ?? [],
                'max_iterations' => isset($params['max_iterations']) ? (int) $params['max_iterations'] : null,
                'benchmark_mode' => $params['benchmark_mode'] ?? null,
                'timeout_minutes' => $timeoutMinutes,
                'timeout_extender' => $timeoutExtender,
                // Scheduled ops/review agents run on the cron critical path. Keep post-run
                // indexing/memory work opt-in so final reports do not wedge the scheduler.
                'index_findings' => (bool) ($params['index_findings'] ?? false),
                'capture_procedural_memory' => (bool) ($params['capture_procedural_memory'] ?? false),
                'capture_episodic_memory' => (bool) ($params['capture_episodic_memory'] ?? false),
                'record_adaptive_outcome' => (bool) ($params['record_adaptive_outcome'] ?? false),
            ];

            Log::info('ScheduledJobService: Starting agent task', [
                'scheduled_job_name' => $scheduledJobName,
                'skill' => $skillName,
                'timeout_minutes' => $timeoutMinutes,
                'has_timeout_extender' => $timeoutExtender !== null,
                'task_preview' => Str::limit($task, 180),
            ]);

            $result = $agentLoop->execute($skillName, $task, $options);

            Log::info('ScheduledJobService: Agent task returned', [
                'scheduled_job_name' => $scheduledJobName,
                'skill' => $skillName,
                'success' => $result['success'] ?? false,
                'duration_ms' => $result['duration_ms'] ?? null,
                'iterations' => $result['iterations'] ?? null,
                'tool_calls' => is_array($result['tool_calls'] ?? null) ? count($result['tool_calls']) : null,
                'tokens_used' => $result['tokens_used'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

            if ($result['success']) {
                $output = "Agent '{$skillName}' completed in ".($result['duration_ms'] ?? 0).'ms. '
                    .'Tokens: '.($result['tokens_used'] ?? 0).'. '
                    .'Response: '.substr($result['response'] ?? '', 0, 500);
                Log::info('ScheduledJobService: Returning successful agent task result', [
                    'scheduled_job_name' => $scheduledJobName,
                    'skill' => $skillName,
                    'output_preview' => Str::limit($output, 240),
                ]);

                return true;
            } else {
                $output = "Agent '{$skillName}' failed: ".($result['error'] ?? 'unknown');
                Log::warning('ScheduledJobService: Returning failed agent task result', [
                    'scheduled_job_name' => $scheduledJobName,
                    'skill' => $skillName,
                    'output_preview' => Str::limit($output, 240),
                ]);

                return false;
            }
        } catch (\Exception $e) {
            $output = 'Agent task exception: '.$e->getMessage();
            Log::error('ScheduledJobService: Agent task exception', [
                'scheduled_job_name' => $scheduledJobName,
                'skill' => $skillName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function decodeJobParameters(string $parametersJson): array
    {
        $decoded = json_decode($parametersJson, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($parametersJson, '{');
        $end = strrpos($parametersJson, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $candidate = substr($parametersJson, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeJobNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_SLASHES);
        }

        $recovered = $this->decodeJobParameters($trimmed);
        if ($recovered !== []) {
            return json_encode($recovered, JSON_UNESCAPED_SLASHES);
        }

        return $trimmed;
    }

    private function extractRuntimeValue(array $params, string $key): ?string
    {
        $runtime = $params['runtime'] ?? null;
        $value = is_array($runtime) ? ($runtime[$key] ?? null) : null;
        $value ??= $params[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function runResearchAnalystSnapshot(AgentLoopService $agentLoop, string &$output): bool
    {
        $toolRegistry = app(AgentToolRegistryService::class);
        $toolNames = [
            'research_topic_coverage',
            'research_pending_results',
            'research_result_quality',
            'research_source_credibility',
        ];

        $messages = [];
        $toolCalls = [];
        $failedToolNames = [];

        foreach ($toolNames as $toolName) {
            try {
                $result = $toolRegistry->executeTool($toolName, [], [
                    'agent_id' => 'research-analyst',
                    'scheduled_job' => 'research_analyst_agent',
                ]);
            } catch (\Throwable $e) {
                $result = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }

            $success = (bool) $result['success'];
            $toolCalls[] = [
                'tool' => $toolName,
                'success' => $success,
            ];
            if (! $success) {
                $failedToolNames[] = $toolName;
            }

            $payload = $result['result'] ?? $result['result_text'] ?? ['error' => $result['error'] ?? 'unknown'];
            $encoded = is_string($payload)
                ? $payload
                : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $messages[] = [
                'role' => 'user',
                'content' => "Tool result for {$toolName}:\n".($encoded ?: '{}'),
            ];
        }

        $summary = $agentLoop->buildResearchAnalystOperationalSummary($messages, $toolCalls, 'No research analyst data available.');

        $output = 'Research analyst snapshot completed. Tools: '.count($toolCalls)
            .', Failures: '.count($failedToolNames).'. Response: '.substr($summary, 0, 500);

        if ($failedToolNames !== []) {
            Log::warning('ScheduledJobService: Research analyst snapshot completed with tool failures', [
                'failed_tools' => $failedToolNames,
            ]);
        }

        return true;
    }

    /**
     * Run genealogy researcher agent against all trees that have persons.
     * Each tree is researched independently — trees are isolated silos.
     */
    private function runGenealogyAgentAllTrees(AgentLoopService $agentLoop, array $params, string &$output): bool
    {
        $trees = DB::select('
            SELECT tree_id, COUNT(*) as person_count
            FROM genealogy_persons
            WHERE tree_id IS NOT NULL
            GROUP BY tree_id
            HAVING person_count > 0
            ORDER BY person_count DESC
        ');

        if (empty($trees)) {
            $output = 'No trees with persons found.';

            return true;
        }

        $results = [];
        $allSuccess = true;

        foreach ($trees as $tree) {
            $treeId = (int) $tree->tree_id;
            $personCount = $tree->person_count;

            Log::info("ScheduledJobService: Running genealogy-researcher for tree {$treeId} ({$personCount} persons)");

            $task = $this->getDefaultAgentTask('genealogy-researcher', ['tree_id' => $treeId]);
            $options = [
                'tree_id' => $treeId,
                'notify' => $params['notify'] ?? $this->shouldAgentNotify('genealogy-researcher'),
                'model' => $params['model'] ?? null,
                'context' => $params['context'] ?? [],
            ];

            $result = $agentLoop->execute('genealogy-researcher', $task, $options);

            $results[] = [
                'tree_id' => $treeId,
                'persons' => $personCount,
                'success' => $result['success'],
                'duration_ms' => $result['duration_ms'] ?? 0,
                'tool_calls' => count($result['tool_calls'] ?? []),
            ];

            if (! $result['success']) {
                $allSuccess = false;
            }
        }

        $output = 'Genealogy researcher ran on '.count($trees).' tree(s): ';
        foreach ($results as $r) {
            $status = $r['success'] ? 'OK' : 'FAIL';
            $output .= "Tree {$r['tree_id']} ({$r['persons']}p): [{$status}] {$r['tool_calls']} calls in ".round($r['duration_ms'] / 1000).'s. ';
        }

        return $allSuccess;
    }

    public function cleanupGenealogyTaskBacklog(?int $treeId = null, int $staleHours = 72): array
    {
        $staleHours = max(1, $staleHours);
        $genealogy = app(\App\Services\Genealogy\GenealogyService::class);
        $cleanup = $genealogy->cleanupStaleProcessingResearchTasks($treeId, $staleHours * 60);

        $recoveredTaskIds = array_map(
            static fn (array $task): int => (int) ($task['id'] ?? 0),
            $cleanup['cancelled_tasks'] ?? []
        );
        foreach ($cleanup['requeued_task_ids'] ?? [] as $taskId) {
            $recoveredTaskIds[] = (int) $taskId;
        }
        $recoveredTaskIds = array_values(array_unique(array_filter($recoveredTaskIds)));
        sort($recoveredTaskIds);

        $releasedQueueItemIds = array_values(array_map(
            static fn ($id): int => (int) $id,
            $cleanup['queue_reset_ids'] ?? []
        ));

        return [
            'stale_task_count' => count($recoveredTaskIds),
            'released_queue_count' => count($releasedQueueItemIds),
            'stale_task_ids' => $recoveredTaskIds,
            'released_queue_item_ids' => $releasedQueueItemIds,
        ];
    }

    public function getGenealogyTaskSprintCandidates(int $treeId, int $limit = 5): array
    {
        $rows = DB::select(
            "SELECT t.id, t.person_id, t.tree_id, t.status, t.priority, t.task_type, t.research_question, t.updated_at,
                    t.outcome_state,
                    CONCAT_WS(' ', p.given_name, p.surname) AS person_name,
                    COALESCE(JSON_LENGTH(t.sources_checked), 0) AS source_count,
                    COALESCE(q.findings_count, 0) AS findings_count,
                    COALESCE(q.review_items_count, 0) AS review_items_count
             FROM genealogy_research_tasks t
             JOIN genealogy_persons p ON p.id = t.person_id
             LEFT JOIN genealogy_research_queue q ON q.id = t.queue_item_id
             WHERE t.tree_id = ?
               AND COALESCE(NULLIF(t.research_question, ''), NULLIF(t.evidence_summary, '')) IS NOT NULL
               AND (
                    t.status IN ('processing', 'failed')
                    OR t.outcome_state IN ('requeue', 'needs_human_review')
               )
             ORDER BY
                CASE WHEN t.status = 'processing' THEN 0 ELSE 1 END,
                CASE t.outcome_state
                    WHEN 'needs_human_review' THEN 0
                    WHEN 'requeue' THEN 1
                    WHEN 'deferred' THEN 2
                    ELSE 3
                END,
                COALESCE(JSON_LENGTH(t.sources_checked), 0) DESC,
                COALESCE(q.review_items_count, 0) DESC,
                COALESCE(q.findings_count, 0) DESC,
                t.updated_at DESC
             LIMIT ?",
            [$treeId, $limit]
        );

        return array_map(static fn ($row) => (array) $row, $rows);
    }

    /**
     * Assess-only mode: populate genealogy_research_queue with priority persons.
     * No agent loop — directly queries coverage data via GenealogyService.
     */
    private function runGenealogyAssess(array $params, string &$output, int $timeoutMinutes): bool
    {
        try {
            $trees = DB::select('
                SELECT tree_id, COUNT(*) as person_count
                FROM genealogy_persons
                WHERE tree_id IS NOT NULL
                GROUP BY tree_id
                HAVING person_count > 0
                ORDER BY person_count DESC
            ');

            if (empty($trees)) {
                $output = 'No trees with persons found.';

                return true;
            }

            $genealogyService = app(\App\Services\Genealogy\GenealogyService::class);
            $totalQueued = 0;
            $totalStale = 0;
            $totalStaleTasks = 0;
            $staleTaskHours = max(1, (int) ($params['stale_task_hours'] ?? $params['stale_hours'] ?? 3));

            foreach ($trees as $tree) {
                $treeId = (int) $tree->tree_id;

                $taskCleanup = $this->cleanupGenealogyTaskBacklog($treeId, $staleTaskHours);
                $totalStaleTasks += $taskCleanup['stale_task_count'];

                // Mark stale pending entries (older than 7 days)
                $stale = DB::update("
                    UPDATE genealogy_research_queue
                    SET status = 'skipped',
                        notes = CONCAT(COALESCE(notes, ''), ' [stale: expired by new assess]'),
                        updated_at = NOW()
                    WHERE tree_id = ? AND status = 'pending'
                      AND assessed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                ", [$treeId]);
                $totalStale += $stale;

                // Reset abandoned in-progress items (>3 hours old)
                DB::update("
                    UPDATE genealogy_research_queue
                    SET status = 'pending', started_at = NULL, session_id = NULL,
                        notes = CONCAT(COALESCE(notes, ''), ' [auto-reset: abandoned]'),
                        updated_at = NOW()
                    WHERE tree_id = ? AND status = 'in_progress'
                      AND started_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)
                ", [$treeId]);

                // Get priority persons from coverage table
                $result = $genealogyService->getPriorityPersons($treeId, 20);
                $persons = $result['persons'] ?? [];

                foreach ($persons as $person) {
                    $personId = $person['person_id'] ?? null;
                    if (! $personId) {
                        continue;
                    }

                    $personName = $person['name'] ?? 'Unknown';
                    $score = $person['priority_score'] ?? 0;
                    $contract = $this->buildGenealogyQuestionContract($person);
                    $reason = $contract['priority_reason'];

                    // Skip if already pending or in_progress for this person
                    $existing = DB::selectOne("
                        SELECT id FROM genealogy_research_queue
                        WHERE tree_id = ? AND person_id = ? AND status IN ('pending', 'in_progress')
                        LIMIT 1
                    ", [$treeId, $personId]);

                    if ($existing) {
                        continue;
                    }

                    // Reuse the latest inactive queue row for this person instead of creating
                    // duplicate history rows on every reassess cycle.
                    $revivable = DB::selectOne("
                        SELECT id FROM genealogy_research_queue
                        WHERE tree_id = ? AND person_id = ? AND status IN ('skipped', 'failed')
                        ORDER BY id DESC
                        LIMIT 1
                    ", [$treeId, $personId]);

                    if ($revivable) {
                        DB::update("
                            UPDATE genealogy_research_queue
                            SET person_name = ?,
                                priority_score = ?,
                                priority_reason = ?,
                                question_type = ?,
                                research_question = ?,
                                selection_reason = ?,
                                status = 'pending',
                                assessed_at = NOW(),
                                started_at = NULL,
                                completed_at = NULL,
                                session_id = NULL,
                                findings_count = 0,
                                review_items_count = 0,
                                last_task_id = NULL,
                                last_outcome_state = NULL,
                                last_outcome_reason = NULL,
                                notes = CONCAT(COALESCE(notes, ''), ' [re-queued by assess]'),
                                updated_at = NOW()
                            WHERE id = ?
                        ", [
                            $personName,
                            $score,
                            $reason,
                            $contract['question_type'],
                            $contract['research_question'],
                            $contract['selection_reason'],
                            $revivable->id,
                        ]);

                        $totalQueued++;

                        continue;
                    }

                    DB::insert("
                        INSERT INTO genealogy_research_queue
                        (tree_id, person_id, person_name, priority_score, priority_reason,
                         question_type, research_question, selection_reason,
                         status, assessed_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW(), NOW())
                    ", [
                        $treeId,
                        $personId,
                        $personName,
                        $score,
                        $reason,
                        $contract['question_type'],
                        $contract['research_question'],
                        $contract['selection_reason'],
                    ]);

                    $totalQueued++;
                }
            }

            $output = "Assess complete: queued {$totalQueued} persons across "
                .count($trees)." tree(s), expired {$totalStale} stale queue entries, "
                ."recovered {$totalStaleTasks} stale tasks.";

            return true;
        } catch (\Throwable $e) {
            $output = 'Assess failed: '.$e->getMessage();
            Log::error('runGenealogyAssess failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return false;
        }
    }

    /**
     * Single-person research from queue: claims top pending person,
     * runs research→analyze→report via AgentLoopService with adaptive timeout.
     */
    private function runGenealogyResearchFromQueue(
        AgentLoopService $agentLoop, string $skillName, array $params, string &$output,
        int $timeoutMinutes, ?\Closure $timeoutExtender = null
    ): bool {
        // Pull highest-priority pending person
        $item = DB::selectOne("
            SELECT * FROM genealogy_research_queue
            WHERE status = 'pending'
            ORDER BY priority_score DESC, assessed_at ASC, id ASC
            LIMIT 1
        ");

        if (! $item) {
            $output = 'No pending persons in research queue.';

            return true; // Not a failure — just nothing to do
        }

        $sessionId = 'genealogy-research-'.uniqid();

        // Atomic claim: prevents double-processing if two workers start simultaneously
        $claimed = DB::update("
            UPDATE genealogy_research_queue
            SET status = 'in_progress', started_at = NOW(), session_id = ?, updated_at = NOW()
            WHERE id = ? AND status = 'pending'
        ", [$sessionId, $item->id]);

        if ($claimed === 0) {
            $output = "Queue item #{$item->id} was claimed by another process.";

            return true;
        }

        $treeId = (int) $item->tree_id;
        $personId = (int) $item->person_id;
        $personName = $item->person_name;
        $researchQuestion = trim((string) ($item->research_question ?? ''));
        $selectionReason = trim((string) ($item->selection_reason ?? ''));
        $questionType = trim((string) ($item->question_type ?? ''));

        if ($researchQuestion === '' || $selectionReason === '') {
            $reason = 'Queue item missing required anchor-question contract.';
            DB::update("
                UPDATE genealogy_research_queue
                SET status = 'failed',
                    completed_at = NOW(),
                    last_outcome_state = 'needs_human_review',
                    last_outcome_reason = ?,
                    notes = CONCAT(COALESCE(notes, ''), ' [contract-missing]'),
                    updated_at = NOW()
                WHERE id = ?
            ", [$reason, $item->id]);

            $output = "Research queue failed: {$personName} (#{$personId}) missing research_question/selection_reason.";
            Log::warning('ScheduledJobService: Queue item missing contract', [
                'queue_id' => $item->id,
                'person_id' => $personId,
                'person_name' => $personName,
                'question_type' => $questionType,
            ]);

            return false;
        }

        Log::info('Genealogy research queue: starting single-person run', [
            'queue_id' => $item->id,
            'person_id' => $personId,
            'person_name' => $personName,
            'tree_id' => $treeId,
            'priority_score' => $item->priority_score,
            'question_type' => $questionType,
            'research_question' => $researchQuestion,
        ]);

        $taskId = $this->createGenealogyQueueTask($item);

        // Build focused single-person task prompt (skips assess phase)
        $task = "Anchor-centered genealogy research run for {$personName} (ID: {$personId}) in tree {$treeId}.\n"
            ."SKIP ASSESS PHASE — anchor person already selected by queue.\n"
            ."Research question: {$researchQuestion}\n"
            ."Selection reason: {$selectionReason}\n"
            .'Question type: '.($questionType !== '' ? $questionType : 'unspecified')."\n"
            ."Priority context: {$item->priority_reason}\n\n"
            ."Rules:\n"
            ."- This run is anchored on {$personName}.\n"
            ."- You may include related people only when they materially help answer the anchor question.\n"
            ."- Do NOT expand to unrelated branches or whole-tree research.\n"
            ."- Record negative searches as real progress when they narrow the field.\n"
            ."- Use GPS-style evidence analysis and note conflicts explicitly.\n"
            ."- Use log_research_search with task_id={$taskId} when documenting searches.\n\n"
            ."At the END of your final response, include these exact lines:\n"
            ."OUTCOME_STATE: completed|deferred|requeue|needs_human_review\n"
            ."OUTCOME_REASON: <concise reason>\n"
            ."SCOPE_REASON: <who else was included and why, or none>\n"
            ."RELATED_PEOPLE_USED: <comma-separated people or none>\n"
            ."SOURCES_CHECKED: <comma-separated source classes>\n"
            ."EVIDENCE_SUMMARY: <concise evidence-backed summary>\n"
            .'CONFLICTS_FOUND: <concise conflict summary or none>';

        $options = [
            'tree_id' => $treeId,
            'session_id' => $sessionId,
            'notify' => false,
            'index_findings' => false,
            'capture_procedural_memory' => false,
            'capture_episodic_memory' => false,
            'record_adaptive_outcome' => false,
            'model' => $params['model'] ?? null,
            'model_role' => $params['model_role'] ?? null,
            'max_iterations' => min((int) ($params['max_iterations'] ?? 8), 8),
            'context' => [
                'queue_mode' => true,
                'queue_item_id' => $item->id,
                'target_person_id' => $personId,
                'target_person_name' => $personName,
                'research_question' => $researchQuestion,
                'selection_reason' => $selectionReason,
                'question_type' => $questionType,
                'genealogy_task_id' => $taskId,
                'priority_score' => (float) $item->priority_score,
                'skip_assess' => true,
            ],
            'timeout_minutes' => $timeoutMinutes,
            'timeout_extender' => $timeoutExtender,
            'progress_callback' => function (string $event, array $details = []) use ($item, $taskId): void {
                $phase = $details['phase'] ?? null;
                $tool = $details['tool'] ?? null;
                $message = match ($event) {
                    'phase_started' => "phase_started:{$phase}",
                    'phase_completed' => "phase_completed:{$phase}",
                    'tool_call' => $tool ? "tool_call:{$phase}:{$tool}" : null,
                    default => null,
                };

                if (! $message) {
                    return;
                }

                DB::update('
                    UPDATE genealogy_research_tasks
                    SET parameters = JSON_SET(COALESCE(parameters, JSON_OBJECT()), "$.progress", ?),
                        updated_at = NOW()
                    WHERE id = ?
                ', [$message, $taskId]);

                DB::update('
                    UPDATE genealogy_research_queue
                    SET notes = ?, updated_at = NOW()
                    WHERE id = ?
                ', [$message, $item->id]);
            },
        ];

        $result = $agentLoop->execute($skillName, $task, $options);

        // Count findings and review items from tool calls
        $findingsCount = 0;
        $reviewItemsCount = 0;
        if ($result['success']) {
            foreach ($result['tool_calls'] ?? [] as $tc) {
                $tool = $tc['tool'] ?? '';
                $success = $tc['success'] ?? false;
                if ($success && in_array($tool, ['propose_change', 'propose_relationship'])) {
                    $findingsCount++;
                }
                if ($success && $tool === 'submit_for_review') {
                    $reviewItemsCount++;
                }
            }
        }

        $parsedOutcome = $this->parseGenealogyOutcomeContract((string) ($result['response'] ?? ''));
        $outcomeState = $parsedOutcome['outcome_state']
            ?? ($result['success'] ? ($findingsCount > 0 ? 'completed' : 'deferred') : 'requeue');
        $outcomeReason = $parsedOutcome['outcome_reason']
            ?? ($result['success']
                ? ($findingsCount > 0 ? 'Evidence-backed findings generated.' : 'No supported change found; preserve for future follow-up.')
                : ($result['error'] ?? 'Agent execution failed.'));

        if ($outcomeState === 'deferred') {
            $findingsCount = 0;
        }

        $scopeReason = $parsedOutcome['scope_reason'] ?? 'none';
        $relatedPeopleUsed = $parsedOutcome['related_people_used'] ?? [];
        $sourcesChecked = $parsedOutcome['sources_checked'] ?? [];
        $evidenceSummary = $parsedOutcome['evidence_summary']
            ?? mb_substr(trim((string) ($result['response'] ?? '')), 0, 500);
        $conflictsFound = $parsedOutcome['conflicts_found'] ?? 'none';

        DB::update('
            UPDATE genealogy_research_tasks
            SET status = ?,
                results = ?,
                research_question = ?,
                selection_reason = ?,
                scope_reason = ?,
                related_people_used = ?,
                sources_checked = ?,
                evidence_summary = ?,
                conflicts_found = ?,
                outcome_state = ?,
                outcome_reason = ?,
                completed_at = NOW(),
                updated_at = NOW(),
                error_message = ?
            WHERE id = ?
        ', [
            $result['success'] ? 'completed' : 'failed',
            json_encode([
                'tool_calls_count' => count($result['tool_calls'] ?? []),
                'findings_count' => $findingsCount,
                'review_items_count' => $reviewItemsCount,
                'duration_ms' => $result['duration_ms'] ?? null,
                'tokens_used' => $result['tokens_used'] ?? null,
            ]),
            $researchQuestion,
            $selectionReason,
            $scopeReason,
            json_encode($relatedPeopleUsed),
            json_encode($sourcesChecked),
            $evidenceSummary,
            $conflictsFound,
            $outcomeState,
            $outcomeReason,
            $result['success'] ? null : ($result['error'] ?? 'unknown'),
            $taskId,
        ]);

        $queueStatus = match (true) {
            ! $result['success'] => 'failed',
            $outcomeState === 'requeue' => 'pending',
            default => 'completed',
        };

        // Update queue entry
        if ($queueStatus === 'pending') {
            DB::update('
                UPDATE genealogy_research_queue
                SET status = ?,
                    started_at = NULL,
                    completed_at = NULL,
                    session_id = NULL,
                    findings_count = ?,
                    review_items_count = ?,
                    last_task_id = ?,
                    last_outcome_state = ?,
                    last_outcome_reason = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ', [
                $queueStatus,
                $findingsCount,
                $reviewItemsCount,
                $taskId,
                $outcomeState,
                $outcomeReason,
                'Requeued: '.($outcomeReason ?: 'retry requested'),
                $item->id,
            ]);
        } else {
            DB::update('
                UPDATE genealogy_research_queue
                SET status = ?,
                    completed_at = NOW(),
                    findings_count = ?,
                    review_items_count = ?,
                    last_task_id = ?,
                    last_outcome_state = ?,
                    last_outcome_reason = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ', [
                $queueStatus,
                $findingsCount,
                $reviewItemsCount,
                $taskId,
                $outcomeState,
                $outcomeReason,
                $result['success']
                    ? 'Completed in '.($result['duration_ms'] ?? 0).'ms. Tokens: '.($result['tokens_used'] ?? 0)
                    : 'Failed: '.($result['error'] ?? 'unknown'),
                $item->id,
            ]);
        }

        $output = "Research queue ({$skillName}): {$personName} (#{$personId}, tree {$treeId}) — "
            .($queueStatus === 'pending' ? 'REQUEUED' : ($result['success'] ? 'completed' : 'FAILED'))
            ." — {$findingsCount} findings, {$reviewItemsCount} reviews"
            ." — outcome {$outcomeState}";

        return $result['success'];
    }

    private function buildGenealogyQuestionContract(array $person): array
    {
        $name = trim((string) ($person['name'] ?? 'Unknown person'));
        $tier = (int) ($person['bloodline_tier'] ?? 9);
        $priorityScore = round((float) ($person['priority_score'] ?? 0), 3);
        $gap = round((float) ($person['data_gap_score'] ?? 0), 3);
        $exhaustion = round((float) ($person['research_exhaustion'] ?? $person['research_exhaustion_score'] ?? 0), 3);
        $birthMissing = empty($person['birth_date']);
        $deathMissing = empty($person['death_date']);
        $birthPlaceMissing = empty($person['birth_place']);
        $deathPlaceMissing = empty($person['death_place']);
        $pendingHints = (int) ($person['pending_hints'] ?? 0);

        $questionType = 'suggest_sources';
        $researchQuestion = "What additional evidence best strengthens {$name}'s profile and source coverage?";

        if (($birthMissing || $birthPlaceMissing) && ($deathMissing || $deathPlaceMissing)) {
            $questionType = 'verify_fact';
            $researchQuestion = "Can we identify direct or strong indirect evidence for {$name}'s birth and death details, including date and place?";
        } elseif ($birthMissing || $birthPlaceMissing) {
            $questionType = 'find_record';
            $researchQuestion = "Can we identify evidence for {$name}'s birth details, especially the missing date or place information?";
        } elseif ($deathMissing || $deathPlaceMissing) {
            $questionType = 'find_record';
            $researchQuestion = "Can we identify evidence for {$name}'s death details, especially the missing date or place information?";
        } elseif ($pendingHints > 0) {
            $questionType = 'negative_search_followup';
            $researchQuestion = "Which pending genealogy hints or source leads best advance {$name}'s unresolved evidence gaps?";
        }

        $selectionParts = [];
        if ($tier === 1) {
            $selectionParts[] = 'direct ancestor bias';
        } elseif ($tier === 2) {
            $selectionParts[] = 'near-direct line candidate';
        } else {
            $selectionParts[] = "tier {$tier} candidate";
        }
        if ($gap > 0) {
            $selectionParts[] = "data gap {$gap}";
        }
        if ($pendingHints > 0) {
            $selectionParts[] = "{$pendingHints} pending hints";
        }
        if ($exhaustion > 0) {
            $selectionParts[] = "exhaustion {$exhaustion}";
        }

        return [
            'question_type' => $questionType,
            'research_question' => $researchQuestion,
            'selection_reason' => implode(', ', $selectionParts),
            'priority_reason' => "tier={$tier}, gap={$gap}, exhaustion={$exhaustion}, priority={$priorityScore}",
        ];
    }

    private function createGenealogyQueueTask(object $item): int
    {
        DB::insert("
            INSERT INTO genealogy_research_tasks
            (tree_id, person_id, queue_item_id, task_type, priority, status, research_question, selection_reason, created_at, updated_at)
            VALUES (?, ?, ?, 'find_records', 'high', 'processing', ?, ?, NOW(), NOW())
        ", [
            (int) $item->tree_id,
            (int) $item->person_id,
            (int) $item->id,
            $item->research_question,
            $item->selection_reason,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    private function parseGenealogyOutcomeContract(string $response): array
    {
        $extract = function (string $label) use ($response): ?string {
            if (preg_match('/^'.preg_quote($label, '/').':\s*(.+)$/mi', $response, $m)) {
                return trim($m[1]);
            }

            return null;
        };

        $splitList = static function (?string $value): array {
            if (! $value || strtolower($value) === 'none') {
                return [];
            }

            return array_values(array_filter(array_map('trim', explode(',', $value))));
        };

        return [
            'outcome_state' => $extract('OUTCOME_STATE'),
            'outcome_reason' => $extract('OUTCOME_REASON'),
            'scope_reason' => $extract('SCOPE_REASON'),
            'related_people_used' => $splitList($extract('RELATED_PEOPLE_USED')),
            'sources_checked' => $splitList($extract('SOURCES_CHECKED')),
            'evidence_summary' => $extract('EVIDENCE_SUMMARY'),
            'conflicts_found' => $extract('CONFLICTS_FOUND'),
        ];
    }

    /**
     * Build a meaningful default task description for agent scheduled runs.
     * Each agent type gets specific instructions so the LLM knows what to do.
     */
    private function getDefaultAgentTask(string $skillName, array $params): string
    {
        $treeId = $params['tree_id'] ?? 'unknown';

        return match ($skillName) {
            'genealogy-researcher' => "Hybrid genealogy research run for tree {$treeId}.\n"
                .'PHASE 1 ASSESS: get_source_metrics, recall_procedures, recall_episodes, list_trees, '
                .'get_research_landscape, get_recent_searches, get_tree_statistics, get_missing_data_report, '
                ."get_research_hints, get_open_research_tasks. Select priority persons by bloodline tier.\n"
                .'PHASE 2 RESEARCH: For each person — get_repositories_for_person, surname_phonetic_matches, '
                .'then source_search_all + at least 3 targeted tools (wikitree_search, nara_search, '
                ."newspaper_search, ellis_island_search, dar_search, etc.). Log coverage with update_search_coverage.\n"
                .'PHASE 3 ANALYZE: get_person_full, get_person_events, detect_source_conflicts, '
                ."assess_gps_compliance, evidence_build_chain, detect_duplicates.\n"
                .'PHASE 4 REPORT: For each person — log_research_search (use real task_id), update_hint_status, '
                ."propose_change/propose_relationship (only with real evidence), rag_index findings.\n"
                .'Do NOT submit_for_review for negative results. Use at least 10 different tools total.',
            'system-guardian' => 'Run health check on all systems. Check service health, database connectivity, '
                .'disk usage, GPU status, queue depths, and recent error trends. Report any issues found.',
            'ai-ops' => 'Check AI pipeline status. Review job throughput, GPU utilization, stalled jobs, '
                .'queue backlogs, and capacity. Fix any stalled jobs and adjust configs if needed.',
            'knowledge-curator' => 'Curate the knowledge base. Start with rag_stats, raptor_get_pending, '
                .'graph_stats, and content_extract_status. Keep this run lean: identify the single dominant '
                .'RAG or graph maintenance gap, process only a bounded backlog slice, and avoid broad full-system '
                .'rebuilds in one run. Prefer reporting focused actionable findings over exhaustive sweeps.',
            'research-analyst' => 'Analyze research content quality and coverage. Start with '
                .'research_topic_coverage, research_pending_results, research_result_quality, and '
                .'research_source_credibility. Keep this run lean: review at most 15 pending results, '
                .'auto-approve only clear high-quality items, auto-skip obvious low-quality or duplicate items, '
                .'and trigger at most 2 research_run_topic actions for genuine coverage gaps. Do not perform '
                .'fresh external archive or web research in this scheduled pass. '
                .'Report the single dominant quality or coverage issue rather than wandering across all topics.',
            'email-ops' => 'Monitor email system health. Check Thunderbird MCP connectivity, bounce rates, '
                .'rate limits, draft queue status, follow-up tracking, and sender reputation. '
                .'Process pending reminders if overdue. Keep this run lean: assess the core health signals first, '
                .'then investigate only the single dominant degraded area. Report degraded or critical findings.',
            'research-ops' => 'Monitor research pipeline health. Check engine fallback chain status, '
                .'circuit breakers, topic scheduling (overdue topics), and result quality. '
                .'Keep this run lean: persist engine health, then investigate only the single dominant outage pattern. '
                .'Reset circuit breakers for recovered engines. Report degraded or critical findings.',
            'workflow-ops' => 'Monitor workflow pipeline health. Check all workflow success rates, '
                .'stuck scheduled jobs, compensation/saga rollbacks, '
                .'webhook trigger reliability, node performance bottlenecks, and error patterns. '
                .'Fix stuck jobs automatically. Report any degraded or critical findings.',
            'file-ops' => 'Monitor file registry health. Check registry stats, maintenance stats, '
                .'AI tagging backlog, RAG index drift, and GPU contention. '
                .'Keep this run lean: verify the dominant file-health issue first, avoid destructive cleanup, '
                .'and investigate only one major degraded area before reporting findings.',
            'file-curator' => 'Curate file metadata quality. Check uncategorized files, '
                .'AI tag quality and consistency, folder distribution, recent ingestions, '
                .'and duplicate resolution candidates. Suggest categorizations for unclassified files. '
                .'Submit significant findings for human review.',
            'factcheck-ops' => 'Monitor fact-check pipeline health. Check pending claims, stale verdicts, '
                .'evidence quality, NLI reranker performance, and source credibility. '
                .'Rerun failed claims if appropriate. Report any degraded or critical findings.',
            'data-removal-ops' => 'Monitor data removal pipeline. Check pending requests, broker health, '
                .'re-listing detections, CAPTCHA queue, proof archive completeness, and suppression list. '
                .'Process actionable items. Report any degraded or critical findings.',
            'youtube-ops' => 'Monitor YouTube pipeline health. Check transcript failures, key points '
                .'generation, Joplin note integrity, RAG indexing, and watch later workflow. '
                .'Retry failed transcripts if appropriate. Report any degraded or critical findings.',
            default => "Scheduled run of {$skillName}. Follow your SKILL.md instructions.",
        };
    }

    /**
     * Check if an agent's SKILL.md has notifications: pushover configured.
     * Only agents with explicit pushover config should trigger completion notifications.
     */
    private function shouldAgentNotify(string $skillName): bool
    {
        try {
            $skillLoader = app(SkillLoaderService::class);
            $config = $skillLoader->getSkillConfig($skillName);

            return ($config['notifications'] ?? null) === 'pushover';
        } catch (\Exception $e) {
            Log::warning('ScheduledJobService: Failed to check agent notification config', [
                'skill' => $skillName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Normalize cron expression (handle aliases)
     */
    public function normalizeCronExpression(string $expression): string
    {
        $expression = strtolower(trim($expression));

        return self::CRON_ALIASES[$expression] ?? $expression;
    }

    /**
     * Calculate next run time from cron expression
     */
    public function calculateNextRun(string $cronExpression): ?Carbon
    {
        $cronExpression = $this->normalizeCronExpression($cronExpression);
        $parts = preg_split('/\s+/', $cronExpression);

        if (count($parts) !== 5) {
            Log::warning("Invalid cron expression: {$cronExpression}");

            return null;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        $now = Carbon::now();
        $candidate = $now->copy()->startOfMinute()->addMinute();

        // Search up to 2 years ahead
        $maxIterations = 60 * 24 * 365 * 2; // minutes in 2 years

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($this->matchesCron($candidate, $minute, $hour, $dayOfMonth, $month, $dayOfWeek)) {
                return $candidate;
            }
            $candidate->addMinute();
        }

        return null;
    }

    /**
     * Check if a Carbon time matches cron fields
     */
    private function matchesCron(Carbon $time, string $minute, string $hour, string $dayOfMonth, string $month, string $dayOfWeek): bool
    {
        return $this->matchesCronField($time->minute, $minute, 0, 59)
            && $this->matchesCronField($time->hour, $hour, 0, 23)
            && $this->matchesCronField($time->day, $dayOfMonth, 1, 31)
            && $this->matchesCronField($time->month, $month, 1, 12)
            && $this->matchesCronField($time->dayOfWeek, $dayOfWeek, 0, 6);
    }

    /**
     * Check if a value matches a cron field pattern
     */
    private function matchesCronField(int $value, string $field, int $min, int $max): bool
    {
        // Wildcard matches everything
        if ($field === '*') {
            return true;
        }

        // Handle lists: 1,2,3
        if (strpos($field, ',') !== false) {
            $values = explode(',', $field);
            foreach ($values as $v) {
                if ($this->matchesCronField($value, trim($v), $min, $max)) {
                    return true;
                }
            }

            return false;
        }

        // Handle steps first (before ranges): */5 or 1-10/2
        if (strpos($field, '/') !== false) {
            [$range, $step] = explode('/', $field, 2);
            $step = (int) $step;

            if ($range === '*') {
                return ($value - $min) % $step === 0;
            }

            if (strpos($range, '-') !== false) {
                [$start, $end] = explode('-', $range, 2);

                return $value >= (int) $start && $value <= (int) $end && ($value - (int) $start) % $step === 0;
            }

            return $value === (int) $range;
        }

        // Handle ranges: 1-5
        if (strpos($field, '-') !== false) {
            [$start, $end] = explode('-', $field, 2);

            return $value >= (int) $start && $value <= (int) $end;
        }

        // Direct match
        return $value === (int) $field;
    }

    /**
     * Get recent run history for a job
     */
    public function getJobHistory(int $jobId, int $limit = 20): array
    {
        $sql = '
            SELECT * FROM scheduled_job_runs
            WHERE scheduled_job_id = ?
            ORDER BY started_at DESC
            LIMIT ?
        ';

        return DB::select($sql, [$jobId, $limit]);
    }

    /**
     * Fix jobs stuck in 'running' status past their timeout.
     * Now PID-aware: kills live processes past timeout, instantly clears dead ones.
     * Returns count of jobs fixed.
     */
    /**
     * APL #8A — dead-run reconciliation.
     *
     * Pivots on `scheduled_job_runs` (one row per worker) so parallel jobs
     * — where `max_parallel > 1` means several `status='running'` rows can
     * share a single `scheduled_jobs` parent — get per-worker PID liveness
     * checks. The earlier implementation pivoted on `scheduled_jobs` and
     * only looked at `last_pid`, so a crashed worker whose PID wasn't the
     * one stored on the parent row could sit for `timeout_minutes + 15`
     * before `fixStuckJobs()` caught it.
     *
     * For each dead-pid run: mark the run row failed, release its custody
     * record, and (only after ALL of this job's running rows are closed)
     * reset the parent `scheduled_jobs` bookkeeping — last_pid / running_pids /
     * running_count / last_run_status. If live workers remain, just rebuild
     * the parent's running_pids JSON from the survivors and leave status
     * as 'running'. A 60-second grace window is enforced against each run's
     * own started_at so a freshly-spawned worker whose PID hasn't been
     * recorded yet isn't reconciled mid-bootstrap.
     *
     * Counter semantics (finding: dead-PID reconcile under-reported failures):
     * On FULL parent collapse (all workers dead → parent flipped to 'failed')
     * we bump `run_count +1`, `fail_count +1`, and set `last_completed_at = NOW()`
     * — exactly once per parent, not once per dead worker — so reporting and
     * adaptive-timeout logic see the failure. On PARTIAL collapse (some dead,
     * survivors live → parent stays 'running') we deliberately do NOT bump
     * counters: the run is still in progress and the surviving worker's
     * eventual markJobCompleted() path will account for the outcome. This
     * matches markJobCompleted()'s own "last worker" gating.
     *
     * @return array<int,array{id:int,name:string,pid:int,run_id:int}> Reconciled per-worker rows.
     */
    public function reconcileDeadRunningJobs(): array
    {
        $candidates = DB::select("
            SELECT sjr.id AS run_id,
                   sjr.scheduled_job_id,
                   sjr.pid,
                   sjr.started_at,
                   sj.name,
                   sj.cron_expression,
                   sj.max_parallel
            FROM scheduled_job_runs sjr
            JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
            WHERE sjr.status = 'running'
              AND sjr.pid IS NOT NULL
              AND sjr.pid > 0
              AND sjr.started_at IS NOT NULL
              AND sjr.started_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ");

        $reconciled = [];
        $affectedJobIds = [];
        foreach ($candidates as $cand) {
            $pid = (int) $cand->pid;
            if ($this->isProcessAlive($pid)) {
                continue;
            }

            $jobId = (int) $cand->scheduled_job_id;
            $runId = (int) $cand->run_id;
            $marker = "\n[AUTO-RECONCILED] PID {$pid} dead (no process), run never completed.";

            DB::update(
                "UPDATE scheduled_job_runs
                 SET status = 'failed',
                     completed_at = NOW(),
                     output = CONCAT(COALESCE(output, ''), ?)
                 WHERE id = ? AND status = 'running'",
                [$marker, $runId]
            );

            // Finding M1 (updated for per-run custody scheme): release THIS
            // worker's custody row. Surface ref is "{jobId}:{runId}" so
            // sibling workers' custody rows are untouched. `findUnreleased`
            // returns any unreleased row regardless of expiry, so we also
            // clean up expired-but-unreleased rows from prior crashes.
            try {
                $custody = app(\App\Services\Custody\TaskCustodyService::class)->findUnreleased(
                    \App\Services\Custody\TaskCustodyService::SURFACE_SCHEDULED_JOB,
                    self::custodySurfaceRef($jobId, $runId),
                );
                if ($custody) {
                    app(\App\Services\Custody\TaskCustodyService::class)->release(
                        (int) $custody->id,
                        'failure',
                        [
                            'reason' => 'dead_pid_reconciled',
                            'pid' => $pid,
                            'run_id' => $runId,
                            'reconciled_at' => date('Y-m-d H:i:s'),
                        ],
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('ScheduledJobService: custody release during dead-PID reconcile failed (non-fatal)', [
                    'job_id' => $jobId,
                    'run_id' => $runId,
                    'pid' => $pid,
                    'error' => $e->getMessage(),
                ]);
            }

            $reconciled[] = [
                'id' => $jobId,
                'name' => (string) $cand->name,
                'pid' => $pid,
                'run_id' => $runId,
            ];
            $affectedJobIds[$jobId] = (object) [
                'id' => $jobId,
                'cron_expression' => $cand->cron_expression,
                'marker' => $marker,
            ];
        }

        // Per-job bookkeeping: only collapse the parent scheduled_jobs row
        // after every running worker on that job is closed. Otherwise we'd
        // downgrade a still-running parallel job to 'failed' just because
        // one of its workers crashed.
        foreach ($affectedJobIds as $jobCtx) {
            $survivors = DB::select(
                "SELECT id, pid FROM scheduled_job_runs
                 WHERE scheduled_job_id = ? AND status = 'running'",
                [$jobCtx->id]
            );

            $deadlineKey = "scheduler:job:{$jobCtx->id}:deadline";

            if ($survivors === []) {
                if (Cache::has($deadlineKey)) {
                    Cache::forget($deadlineKey);
                }
                $nextRun = $this->calculateNextRun($jobCtx->cron_expression);
                // Counter bump is WHERE-gated on last_run_status='running' so
                // orphan-run reconciliation (parent already in a terminal
                // state — see orphan_running_run_is_reconciled... test) does
                // NOT double-count a failure that was already counted when
                // the parent originally completed. Single increment per real
                // parent collapse.
                DB::update(
                    "UPDATE scheduled_jobs
                     SET last_run_status = 'failed',
                         last_pid = NULL,
                         running_pids = NULL,
                         running_count = 0,
                         last_run_output = CONCAT(COALESCE(last_run_output, ''), ?),
                         next_run_at = ?,
                         last_completed_at = NOW(),
                         run_count = run_count + 1,
                         fail_count = fail_count + 1,
                         updated_at = NOW()
                     WHERE id = ? AND last_run_status = 'running'",
                    [$jobCtx->marker, $nextRun?->format('Y-m-d H:i:s'), $jobCtx->id]
                );

                continue;
            }

            // Parallel job with live survivors — keep the parent 'running' but
            // rebuild running_pids / running_count from the survivors so the
            // bookkeeping reflects reality. Leave last_pid alone only if it
            // still matches a live survivor; otherwise pick the first.
            $liveIds = array_values(array_map(static fn ($r) => (int) $r->pid, $survivors));
            $current = DB::selectOne(
                'SELECT last_pid FROM scheduled_jobs WHERE id = ?',
                [$jobCtx->id]
            );
            $lastPid = (int) ($current->last_pid ?? 0);
            if (! in_array($lastPid, $liveIds, true)) {
                $lastPid = $liveIds[0];
            }
            DB::update(
                'UPDATE scheduled_jobs
                 SET last_pid = ?,
                     running_pids = ?,
                     running_count = ?,
                     updated_at = NOW()
                 WHERE id = ?',
                [$lastPid, json_encode($liveIds), count($liveIds), $jobCtx->id]
            );
        }

        if ($reconciled !== []) {
            Log::warning('ScheduledJobService: reconciled dead-PID running workers', [
                'count' => count($reconciled),
                'workers' => array_map(
                    static fn (array $r) => "{$r['name']}(job#{$r['id']}, run#{$r['run_id']}, PID {$r['pid']})",
                    $reconciled
                ),
            ]);
        }

        return $reconciled;
    }

    public function fixStuckJobs(): int
    {
        // Find stuck jobs — include last_pid for process verification
        $stuckJobs = DB::select("
            SELECT id, name, command, cron_expression, last_run_at, timeout_minutes, last_pid, job_type, notes
            FROM scheduled_jobs
            WHERE last_run_status = 'running'
              AND COALESCE(stall_exempt, 0) = 0
              AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes + 15, 120) MINUTE)
              AND (last_completed_at IS NULL OR last_completed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
        ");

        if (empty($stuckJobs)) {
            return 0;
        }

        $fixedCount = 0;
        $fixedNames = [];

        foreach ($stuckJobs as $job) {
            if (! $this->shouldAutoFixTimedOutJob($job)) {
                continue;
            }

            $pid = $job->last_pid;

            // Check for adaptive timeout extension before marking stuck
            $deadline = Cache::get("scheduler:job:{$job->id}:deadline");
            if ($deadline && $deadline > time()) {
                if ($pid && ! $this->isProcessAlive((int) $pid)) {
                    Cache::forget("scheduler:job:{$job->id}:deadline");
                    Log::warning('ScheduledJobService: Deadline key active but PID dead, cleaning up', [
                        'job' => $job->name,
                        'pid' => (int) $pid,
                        'deadline' => date('Y-m-d H:i:s', $deadline),
                    ]);
                } else {
                    Log::debug("ScheduledJobService: Job #{$job->id} ({$job->name}) has active timeout extension", [
                        'deadline' => date('Y-m-d H:i:s', $deadline),
                        'remaining_min' => round(($deadline - time()) / 60, 1),
                    ]);

                    continue;
                }
            }

            // PID-aware handling
            if ($pid && $this->isProcessAlive($pid)) {
                // Process alive but past timeout — kill it
                $this->killProcess($pid);
                $label = "killed PID {$pid}";
            } elseif ($pid) {
                $label = "dead PID {$pid}";
            } else {
                $label = 'no PID';
            }

            $nextRun = $this->calculateNextRun($job->cron_expression);

            DB::update("
                UPDATE scheduled_jobs
                SET last_run_status = 'failed',
                    last_pid = NULL,
                    running_pids = NULL,
                    running_count = 0,
                    last_run_output = CONCAT(COALESCE(last_run_output, ''), '\n[AUTO-FIXED] Job timed out ({$label})'),
                    next_run_at = ?
                WHERE id = ? AND last_run_status = 'running'
            ", [
                $nextRun?->format('Y-m-d H:i:s'),
                $job->id,
            ]);

            // Fix corresponding run records
            DB::update("
                UPDATE scheduled_job_runs
                SET status = 'failed',
                    completed_at = NOW(),
                    output = CONCAT(COALESCE(output, ''), '\n[AUTO-FIXED] Job timed out ({$label})')
                WHERE scheduled_job_id = ?
                  AND status = 'running'
                  AND started_at < DATE_SUB(NOW(), INTERVAL COALESCE(?, 120) MINUTE)
            ", [$job->id, $job->timeout_minutes ? $job->timeout_minutes + 15 : null]);

            $this->releaseQueueResearchItemsForJob($job, "Automatic cleanup after timed-out queue research worker ({$label}).");

            $stuckMinutes = $job->last_run_at
                ? round((time() - strtotime($job->last_run_at)) / 60)
                : '?';

            $fixedNames[] = "{$job->name} (stuck {$stuckMinutes}min, {$label})";
            $fixedCount++;
        }

        Log::warning('ScheduledJobService: Auto-fixed stuck jobs', [
            'count' => $fixedCount,
            'jobs' => $fixedNames,
        ]);

        // Stuck jobs are auto-fixed and logged — daily report covers visibility

        return $fixedCount;
    }

    /**
     * Check for jobs with consecutive failures and send escalating alerts.
     * Thresholds: 3 = warning, 5 = high priority, 10 = emergency.
     */
    public function checkConsecutiveFailures(): void
    {
        $failingJobs = DB::select("
            SELECT id, name, fail_count,
                   (SELECT COUNT(*) FROM scheduled_job_runs
                    WHERE scheduled_job_id = sj.id
                      AND status = 'failed'
                      AND started_at > COALESCE(
                          (SELECT MAX(started_at) FROM scheduled_job_runs
                           WHERE scheduled_job_id = sj.id AND status = 'success'),
                          '2000-01-01'
                      )
                   ) as consecutive_failures
            FROM scheduled_jobs sj
            WHERE enabled = 1
              AND last_run_status = 'failed'
        ");

        $alerts = [];
        $maxPriority = 0;

        foreach ($failingJobs as $job) {
            $consecutive = (int) $job->consecutive_failures;

            $warn = config('health_thresholds.job_failures.warning_threshold', 3);
            $high = config('health_thresholds.job_failures.high_threshold', 5);
            $emerg = config('health_thresholds.job_failures.emergency_threshold', 10);
            $cooldown = config('health_thresholds.job_failures.cooldown_seconds', 21600);

            $cacheKey = "consecutive_fail_alert:{$job->id}:{$consecutive}";
            if ($consecutive >= $warn && ! Cache::has($cacheKey)) {
                $priority = match (true) {
                    $consecutive >= $emerg => 2,
                    $consecutive >= $high => 1,
                    default => 0,
                };

                $maxPriority = max($maxPriority, $priority);
                $label = match (true) {
                    $consecutive >= $emerg => 'EMERGENCY',
                    $consecutive >= $high => 'HIGH',
                    default => 'WARNING',
                };

                $alerts[] = "{$label}: {$job->name} — {$consecutive} consecutive failures";

                Cache::put($cacheKey, true, $cooldown);
            }
        }

        if (! empty($alerts)) {
            Log::warning('ScheduledJobService: Consecutive job failures detected', [
                'alerts' => $alerts,
                'max_priority' => $maxPriority,
            ]);
        }
    }

    /**
     * Get statistics about scheduled jobs
     */
    public function getStats(): array
    {
        // Auto-fix stuck jobs before calculating stats
        $this->fixStuckJobs();

        $stats = DB::select("
            SELECT
                COUNT(*) as total_jobs,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled_jobs,
                SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) as disabled_jobs,
                SUM(CASE WHEN enabled = 1 AND stall_exempt = 0 AND COALESCE(job_type, '') <> 'agent_task' AND last_run_status = 'running' THEN 1 ELSE 0 END) as running_jobs,
                SUM(CASE WHEN enabled = 1 AND stall_exempt = 0 AND COALESCE(job_type, '') <> 'agent_task' AND last_run_status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                SUM(run_count) as total_runs,
                SUM(fail_count) as total_failures
            FROM scheduled_jobs
        ")[0];

        $recentRuns = DB::select('
            SELECT COUNT(*) as count FROM scheduled_job_runs
            WHERE started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ')[0];

        $moduleStats = DB::select("
            SELECT
                COALESCE(source_module, 'Uncategorized') as module,
                COUNT(*) as job_count,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled_count
            FROM scheduled_jobs
            GROUP BY source_module
            ORDER BY module
        ");

        return [
            'total_jobs' => (int) $stats->total_jobs,
            'enabled_jobs' => (int) $stats->enabled_jobs,
            'disabled_jobs' => (int) $stats->disabled_jobs,
            'running_jobs' => (int) $stats->running_jobs,
            'failed_jobs' => (int) $stats->failed_jobs,
            'total_runs' => (int) $stats->total_runs,
            'total_failures' => (int) $stats->total_failures,
            'runs_last_24h' => (int) $recentRuns->count,
            'by_module' => $moduleStats,
        ];
    }

    /**
     * Validate cron expression
     */
    public function validateCronExpression(string $expression): array
    {
        $normalized = $this->normalizeCronExpression($expression);
        $parts = preg_split('/\s+/', $normalized);

        if (count($parts) !== 5) {
            return [
                'valid' => false,
                'error' => 'Cron expression must have 5 fields: minute hour day month weekday',
            ];
        }

        $fields = ['minute', 'hour', 'day of month', 'month', 'day of week'];
        $ranges = [
            [0, 59],
            [0, 23],
            [1, 31],
            [1, 12],
            [0, 6],
        ];

        foreach ($parts as $i => $part) {
            $valid = $this->validateCronField($part, $ranges[$i][0], $ranges[$i][1]);
            if (! $valid) {
                return [
                    'valid' => false,
                    'error' => "Invalid {$fields[$i]} field: {$part}",
                ];
            }
        }

        $nextRun = $this->calculateNextRun($normalized);

        return [
            'valid' => true,
            'normalized' => $normalized,
            'next_run' => $nextRun?->format('Y-m-d H:i:s'),
            'next_run_human' => $nextRun?->diffForHumans(),
        ];
    }

    /**
     * Validate a single cron field
     */
    private function validateCronField(string $field, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        // Handle lists
        if (strpos($field, ',') !== false) {
            foreach (explode(',', $field) as $part) {
                if (! $this->validateCronField(trim($part), $min, $max)) {
                    return false;
                }
            }

            return true;
        }

        // Handle steps
        if (strpos($field, '/') !== false) {
            [$range, $step] = explode('/', $field, 2);
            if (! is_numeric($step) || (int) $step < 1) {
                return false;
            }

            return $range === '*' || $this->validateCronField($range, $min, $max);
        }

        // Handle ranges
        if (strpos($field, '-') !== false) {
            [$start, $end] = explode('-', $field, 2);

            return is_numeric($start) && is_numeric($end)
                && (int) $start >= $min && (int) $start <= $max
                && (int) $end >= $min && (int) $end <= $max
                && (int) $start <= (int) $end;
        }

        // Direct value
        return is_numeric($field) && (int) $field >= $min && (int) $field <= $max;
    }

    /**
     * Describe cron expression in human readable format
     */
    public function describeCron(string $expression): string
    {
        $normalized = $this->normalizeCronExpression($expression);
        $parts = preg_split('/\s+/', $normalized);

        if (count($parts) !== 5) {
            return 'Invalid cron expression';
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        // Common patterns
        if ($normalized === '0 * * * *') {
            return 'Every hour at minute 0';
        }
        if ($normalized === '0 0 * * *') {
            return 'Every day at midnight';
        }
        if ($normalized === '0 0 * * 0') {
            return 'Every Sunday at midnight';
        }
        if ($normalized === '0 0 1 * *') {
            return 'First day of every month at midnight';
        }

        // Build description
        $desc = [];

        if ($minute === '*') {
            $desc[] = 'Every minute';
        } elseif (strpos($minute, '*/') === 0) {
            $desc[] = 'Every '.substr($minute, 2).' minutes';
        } elseif ($minute !== '0') {
            $desc[] = "At minute {$minute}";
        }

        if ($hour !== '*') {
            if (strpos($hour, '*/') === 0) {
                $desc[] = 'every '.substr($hour, 2).' hours';
            } else {
                $desc[] = "at hour {$hour}";
            }
        }

        if ($dayOfMonth !== '*') {
            $desc[] = "on day {$dayOfMonth}";
        }

        if ($month !== '*') {
            $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $desc[] = 'in '.($months[(int) $month] ?? "month {$month}");
        }

        if ($dayOfWeek !== '*') {
            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            if (strpos($dayOfWeek, ',') !== false) {
                $dayNames = array_map(fn ($d) => $days[(int) $d] ?? $d, explode(',', $dayOfWeek));
                $desc[] = 'on '.implode(', ', $dayNames);
            } else {
                $desc[] = 'on '.($days[(int) $dayOfWeek] ?? "day {$dayOfWeek}");
            }
        }

        return ucfirst(implode(' ', $desc)) ?: 'Custom schedule';
    }

    /**
     * Clean up old run history
     */
    public function cleanupHistory(int $daysToKeep = 30): int
    {
        $cutoff = Carbon::now()->subDays($daysToKeep)->format('Y-m-d H:i:s');

        return DB::delete('DELETE FROM scheduled_job_runs WHERE completed_at < ?', [$cutoff]);
    }

    /**
     * Sync a workflow schedule to the scheduled_jobs table
     *
     * Call this when a workflow is created, updated, toggled, or deleted.
     */
    public function syncWorkflowSchedule(int $workflowId, string $workflowName, ?string $schedule, bool $active): void
    {
        $jobName = 'workflow_'.$this->slugify($workflowName);

        // Check if job already exists
        $existing = DB::select("SELECT id FROM scheduled_jobs WHERE name = ? OR (job_type = 'workflow' AND command = ?) LIMIT 1", [
            $jobName,
            $workflowName,
        ]);

        if (empty($schedule)) {
            // No schedule - delete the job if it exists
            if (! empty($existing)) {
                DB::delete('DELETE FROM scheduled_jobs WHERE id = ?', [$existing[0]->id]);
                Log::info('Deleted scheduled job for workflow without schedule', ['workflow' => $workflowName]);
            }

            return;
        }

        // Validate cron expression
        $cronValidation = $this->validateCronExpression($schedule);
        if (! $cronValidation['valid']) {
            Log::warning('Invalid cron expression for workflow', [
                'workflow' => $workflowName,
                'schedule' => $schedule,
                'error' => $cronValidation['error'],
            ]);

            return;
        }

        $nextRun = $this->calculateNextRun($schedule);

        if (! empty($existing)) {
            // Update existing job
            DB::update('
                UPDATE scheduled_jobs SET
                    name = ?,
                    command = ?,
                    cron_expression = ?,
                    enabled = ?,
                    next_run_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ', [
                $jobName,
                $workflowName,
                $schedule,
                $active,
                $nextRun?->format('Y-m-d H:i:s'),
                $existing[0]->id,
            ]);
            Log::info('Updated scheduled job for workflow', ['workflow' => $workflowName, 'job_id' => $existing[0]->id]);
        } else {
            // Create new job
            $sourceModule = $this->determineWorkflowModule($workflowName);

            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, run_in_background,
                 without_overlapping, timeout_minutes, notes, category, source_module, next_run_at, created_at, updated_at)
                VALUES (?, ?, 'workflow', ?, ?, ?, 1, 1, 60, ?, 'Workflows', ?, ?, NOW(), NOW())
            ", [
                $jobName,
                "Run workflow: {$workflowName}",
                $workflowName,
                $schedule,
                $active,
                "Auto-created from workflow schedule. Workflow ID: {$workflowId}",
                $sourceModule,
                $nextRun?->format('Y-m-d H:i:s'),
            ]);
            Log::info('Created scheduled job for workflow', ['workflow' => $workflowName]);
        }
    }

    /**
     * Delete scheduled job for a workflow
     */
    public function deleteWorkflowSchedule(string $workflowName): void
    {
        $jobName = 'workflow_'.$this->slugify($workflowName);

        $deleted = DB::delete("DELETE FROM scheduled_jobs WHERE name = ? OR (job_type = 'workflow' AND command = ?)", [
            $jobName,
            $workflowName,
        ]);

        if ($deleted) {
            Log::info('Deleted scheduled job for deleted workflow', ['workflow' => $workflowName]);
        }
    }

    /**
     * Convert name to slug
     */
    // ─── PID Tracking & Process Verification ───────────────────────────

    /**
     * Check if a process is alive by PID.
     * Uses posix_kill(pid, 0) as primary check, /proc fallback.
     */
    public function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // posix_kill with signal 0 checks existence without sending a signal
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: check /proc filesystem
        return is_dir("/proc/{$pid}");
    }

    /**
     * Kill a process with graceful SIGTERM, then SIGKILL after grace period.
     */
    public function killProcess(int $pid, int $grace = 5): bool
    {
        if ($pid <= 0 || ! $this->isProcessAlive($pid)) {
            return false;
        }

        // SIGTERM (graceful)
        if (function_exists('posix_kill')) {
            posix_kill($pid, 15); // SIGTERM
        } else {
            \Illuminate\Support\Facades\Process::timeout(5)->run(['kill', '-15', (string) $pid]);
        }

        // Wait for graceful shutdown
        $waited = 0;
        while ($waited < $grace && $this->isProcessAlive($pid)) {
            sleep(1);
            $waited++;
        }

        // SIGKILL if still alive
        if ($this->isProcessAlive($pid)) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, 9); // SIGKILL
            } else {
                \Illuminate\Support\Facades\Process::timeout(5)->run(['kill', '-9', (string) $pid]);
            }
            Log::warning("ScheduledJobService: Force-killed PID {$pid} after {$grace}s grace period");
        }

        return ! $this->isProcessAlive($pid);
    }

    /**
     * Clean up dead processes detected via PID tracking.
     * Called on every scheduler tick for instant zombie detection.
     */
    private function cleanupDeadProcesses(): void
    {
        // Clean orphaned run records with NULL PIDs (parallel worker companions)
        DB::update("
            UPDATE scheduled_job_runs
            SET status = 'failed', completed_at = COALESCE(completed_at, NOW()),
                output = CONCAT(COALESCE(output, ''), '\n[ZOMBIE] No PID, auto-cleared')
            WHERE status = 'running' AND pid IS NULL AND started_at < NOW() - INTERVAL 5 MINUTE
        ");

        // Sweep stale run records with PIDs that are dead — catches orphans where
        // the parent job already moved on to a new run (last_run_status != 'running')
        $staleRuns = DB::select("
            SELECT id, pid FROM scheduled_job_runs
            WHERE status = 'running' AND pid IS NOT NULL AND started_at < NOW() - INTERVAL 10 MINUTE
        ");
        foreach ($staleRuns as $run) {
            if (! $this->isProcessAlive((int) $run->pid)) {
                DB::update("
                    UPDATE scheduled_job_runs
                    SET status = 'failed', completed_at = NOW(),
                        output = CONCAT(COALESCE(output, ''), '\n[ZOMBIE] Stale PID dead, auto-cleared')
                    WHERE id = ? AND status = 'running'
                ", [$run->id]);
            }
        }

        // Single-worker jobs with dead PIDs (must be in 'running' state)
        // OR parallel jobs with stale running_pids (any status — a completed worker
        // may have set last_run_status='success' while sibling PIDs are still tracked)
        $runningJobs = DB::select("
            SELECT id, name, cron_expression, last_pid, max_parallel, running_pids
            FROM scheduled_jobs
            WHERE (last_run_status = 'running' AND (last_pid IS NOT NULL OR running_pids IS NOT NULL))
               OR (running_pids IS NOT NULL AND JSON_LENGTH(running_pids) > 0)
        ");

        foreach ($runningJobs as $job) {
            $maxParallel = $job->max_parallel ?? 1;

            if ($maxParallel <= 1) {
                // Single-worker: check last_pid
                if ($job->last_pid && ! $this->isProcessAlive($job->last_pid)) {
                    $runStatus = DB::selectOne('
                        SELECT status FROM scheduled_job_runs
                        WHERE scheduled_job_id = ? AND pid = ?
                        ORDER BY id DESC LIMIT 1
                    ', [$job->id, $job->last_pid]);

                    $nextRun = $this->calculateNextRun($job->cron_expression);

                    if ($runStatus && in_array($runStatus->status, ['success', 'completed'], true)) {
                        DB::update("
                            UPDATE scheduled_jobs
                            SET last_run_status = 'success',
                                last_pid = NULL,
                                next_run_at = ?
                            WHERE id = ? AND last_run_status = 'running'
                        ", [$nextRun?->format('Y-m-d H:i:s'), $job->id]);
                        Cache::forget("scheduler:job:{$job->id}:deadline");

                        Log::info("ScheduledJobService: Cleared completed single-worker PID {$job->last_pid} for job #{$job->id} ({$job->name})");

                        continue;
                    }

                    DB::update("
                        UPDATE scheduled_jobs
                        SET last_run_status = 'failed',
                            last_pid = NULL,
                            last_run_output = CONCAT(COALESCE(last_run_output, ''), '\n[ZOMBIE] PID {$job->last_pid} dead, auto-cleared'),
                            next_run_at = ?
                        WHERE id = ? AND last_run_status = 'running'
                    ", [$nextRun?->format('Y-m-d H:i:s'), $job->id]);
                    Cache::forget("scheduler:job:{$job->id}:deadline");

                    DB::update("
                        UPDATE scheduled_job_runs
                        SET status = 'failed', completed_at = NOW(),
                            output = CONCAT(COALESCE(output, ''), '\n[ZOMBIE] PID dead, auto-cleared')
                        WHERE scheduled_job_id = ? AND status = 'running' AND pid = ?
                    ", [$job->id, $job->last_pid]);

                    $fullJob = $this->getJob((int) $job->id);
                    if ($fullJob) {
                        $this->releaseQueueResearchItemsForJob($fullJob, 'Queue research worker exited before completing the claimed item.');
                    }

                    Log::info("ScheduledJobService: Cleared zombie job #{$job->id} ({$job->name}), dead PID {$job->last_pid}");
                }
            } else {
                // Parallel jobs: check each PID in running_pids
                $pids = json_decode($job->running_pids ?? '[]', true) ?: [];
                $deadPids = [];

                foreach ($pids as $pid) {
                    if (! $this->isProcessAlive((int) $pid)) {
                        $deadPids[] = $pid;
                    }
                }

                $trueZombiePids = [];
                foreach ($deadPids as $deadPid) {
                    // Check if the run already completed successfully before marking as zombie
                    $runStatus = DB::selectOne('
                        SELECT status FROM scheduled_job_runs
                        WHERE scheduled_job_id = ? AND pid = ?
                        ORDER BY id DESC LIMIT 1
                    ', [$job->id, $deadPid]);

                    $this->unregisterWorkerPid($job->id, (int) $deadPid);

                    if ($runStatus && in_array($runStatus->status, ['success', 'completed'])) {
                        // Worker finished normally — PID exited cleanly, not a zombie
                        Log::debug("ScheduledJobService: PID {$deadPid} for job #{$job->id} ({$job->name}) already completed, not a zombie");

                        continue;
                    }

                    $trueZombiePids[] = $deadPid;
                    DB::update("
                        UPDATE scheduled_job_runs
                        SET status = 'failed', completed_at = NOW(),
                            output = CONCAT(COALESCE(output, ''), '\n[ZOMBIE] PID dead, auto-cleared')
                        WHERE scheduled_job_id = ? AND status = 'running' AND pid = ?
                    ", [$job->id, $deadPid]);

                    Log::info("ScheduledJobService: Cleared zombie worker PID {$deadPid} for job #{$job->id} ({$job->name})");
                }

                // If all workers dead AND at least one was a true zombie, mark job as failed
                $refreshed = $this->getJob($job->id);
                if ($refreshed && $refreshed->last_run_status === 'running' && ($refreshed->running_count ?? 0) <= 0) {
                    if (! empty($trueZombiePids)) {
                        $nextRun = $this->calculateNextRun($job->cron_expression);
                        DB::update("
                            UPDATE scheduled_jobs
                            SET last_run_status = 'failed', last_pid = NULL, next_run_at = ?,
                                last_run_output = CONCAT(COALESCE(last_run_output, ''), '\n[ZOMBIE] All parallel workers dead')
                            WHERE id = ? AND last_run_status = 'running'
                        ", [$nextRun?->format('Y-m-d H:i:s'), $job->id]);
                        Cache::forget("scheduler:job:{$job->id}:deadline");
                    } else {
                        // All workers completed successfully — mark job as success
                        $nextRun = $this->calculateNextRun($job->cron_expression);
                        DB::update("
                            UPDATE scheduled_jobs
                            SET last_run_status = 'success', last_pid = NULL, next_run_at = ?
                            WHERE id = ? AND last_run_status = 'running'
                        ", [$nextRun?->format('Y-m-d H:i:s'), $job->id]);
                        Cache::forget("scheduler:job:{$job->id}:deadline");
                        Log::info("ScheduledJobService: All workers for job #{$job->id} ({$job->name}) completed successfully, cleaned up");
                    }
                }
            }
        }

        // CPU-stall detection: find alive processes that have consumed almost no CPU time
        $this->detectStalledProcesses();
    }

    /**
     * Detect processes that are alive but stalled (consuming no CPU time).
     * Uses a two-sample delta over 3 seconds: truly stuck processes show zero
     * CPU delta. Agent tasks are excluded because they are predominantly
     * I/O-bound and often spend long periods waiting on LLM/API responses.
     */
    private function detectStalledProcesses(): void
    {
        $stalledRuns = DB::select("
            SELECT sjr.id, sjr.pid, sjr.started_at, sj.id as job_id, sj.name
            FROM scheduled_job_runs sjr
            JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
            WHERE sjr.status = 'running'
              AND sjr.pid IS NOT NULL
              AND sjr.started_at < NOW() - INTERVAL 30 MINUTE
              AND COALESCE(sj.stall_exempt, 0) = 0
              AND COALESCE(sj.job_type, '') <> 'agent_task'
              AND (sj.last_completed_at IS NULL OR sj.last_completed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
        ");

        if (empty($stalledRuns)) {
            return;
        }

        // First snapshot: read CPU ticks for all candidate PIDs
        $snapshot1 = [];
        foreach ($stalledRuns as $run) {
            $pid = (int) $run->pid;
            if ($this->isProcessAlive($pid)) {
                $snapshot1[$pid] = $this->readCpuTicks($pid);
            }
        }

        if (empty($snapshot1)) {
            return;
        }

        // Wait 3 seconds, then take second snapshot
        sleep(3);

        foreach ($stalledRuns as $run) {
            $pid = (int) $run->pid;
            if (! isset($snapshot1[$pid])) {
                continue; // Was dead on first snapshot
            }
            if (! $this->isProcessAlive($pid)) {
                continue; // Dead PIDs handled by cleanupDeadProcesses above
            }

            $ticks2 = $this->readCpuTicks($pid);
            $delta = $ticks2 - $snapshot1[$pid];

            if ($delta > 0) {
                continue; // Process made CPU progress — not stalled
            }

            // Stalled: zero CPU delta over 3 seconds after 30+ min runtime
            Log::warning('ScheduledJobService: Stalled process detected', [
                'job' => $run->name, 'pid' => $pid,
                'running_since' => $run->started_at,
            ]);

            $this->killProcess($pid);

            $nextRun = $this->calculateNextRun(
                DB::selectOne('SELECT cron_expression FROM scheduled_jobs WHERE id = ?', [$run->job_id])->cron_expression ?? '* * * * *'
            );

            DB::update("
                UPDATE scheduled_job_runs
                SET status = 'failed', completed_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                    output = CONCAT(COALESCE(output, ''), '\n[STALLED] PID {$pid} alive but 0 CPU delta, killed')
                WHERE id = ? AND status = 'running'
            ", [$run->id]);

            DB::update("
                UPDATE scheduled_jobs
                SET last_run_status = 'failed', last_pid = NULL, running_pids = NULL, running_count = 0, next_run_at = ?,
                    last_run_output = CONCAT(COALESCE(last_run_output, ''), '\n[STALLED] PID {$pid} killed (0 CPU delta)')
                WHERE id = ? AND last_run_status = 'running'
            ", [$nextRun?->format('Y-m-d H:i:s'), $run->job_id]);
        }
    }

    private function shouldAutoFixTimedOutJob(object $job): bool
    {
        if (($job->job_type ?? null) !== 'agent_task') {
            return true;
        }

        return $this->isQueueResearchJob($job);
    }

    private function isQueueResearchJob(object $job): bool
    {
        if (! in_array($job->command ?? '', ['genealogy-records', 'genealogy-researcher'], true)) {
            return false;
        }

        $params = $this->decodeJobParameters($job->notes ?? null);
        $runtimeMode = $this->extractRuntimeValue($params, 'runtime_mode');

        return $runtimeMode === 'queue_research';
    }

    private function releaseQueueResearchItemsForJob(object $job, string $reason): void
    {
        if (! $this->isQueueResearchJob($job)) {
            return;
        }

        DB::update("
            UPDATE genealogy_research_queue
            SET status = 'failed',
                completed_at = NOW(),
                last_outcome_state = 'needs_human_review',
                last_outcome_reason = ?,
                notes = CONCAT(COALESCE(notes, ''), ' [queue-worker-released]'),
                updated_at = NOW()
            WHERE status = 'in_progress'
        ", [$reason]);
    }

    /**
     * Read cumulative CPU ticks (utime + stime) from /proc/{pid}/stat.
     * Returns -1 if unreadable (treat as non-zero delta to avoid false kills).
     */
    private function readCpuTicks(int $pid): int
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (! $stat) {
            return -1;
        }

        // Field 2 (comm) may contain spaces and is wrapped in parens — split after closing paren
        $afterComm = strrchr($stat, ')');
        if (! $afterComm) {
            return -1;
        }

        $fields = preg_split('/\s+/', trim(substr($afterComm, 2)));
        if (count($fields) < 13) {
            return -1;
        }

        return (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0);
    }

    /**
     * Store the PID of a spawned background process on both tables.
     */
    public function storePid(int $jobId, int $pid, ?int $runId = null): void
    {
        DB::update('UPDATE scheduled_jobs SET last_pid = ? WHERE id = ?', [$pid, $jobId]);

        if ($runId) {
            DB::update('UPDATE scheduled_job_runs SET pid = ? WHERE id = ?', [$pid, $runId]);
        } else {
            // Update the latest running run for this job
            DB::update("
                UPDATE scheduled_job_runs SET pid = ?
                WHERE scheduled_job_id = ? AND status = 'running' AND pid IS NULL
                ORDER BY id DESC LIMIT 1
            ", [$pid, $jobId]);
        }
    }

    // ─── Dynamic Parallel Workers ────────────────────────────────────

    private static ?int $cpuCores = null;

    /**
     * Resource profiles for job types — inferred from command string.
     * Each profile defines how that job type consumes resources.
     *
     * hard_cap: absolute max workers regardless of system state
     * gpu_bound: requires GPU — limits concurrency when GPU is busy
     * cpu_bound: scales with CPU headroom
     * io_bound: scales with memory/IO (higher natural concurrency)
     * llm_bound: constrained by LLM provider throughput
     */
    private const JOB_RESOURCE_PROFILES = [
        'faces' => ['hard_cap' => 4, 'gpu_bound' => false, 'cpu_bound' => true,  'io_bound' => false, 'llm_bound' => false],
        'ai' => ['hard_cap' => 3, 'gpu_bound' => true,  'cpu_bound' => false, 'io_bound' => false, 'llm_bound' => true],
        'rag' => ['hard_cap' => 4, 'gpu_bound' => false, 'cpu_bound' => false, 'io_bound' => true,  'llm_bound' => false],
        'phash' => ['hard_cap' => 3, 'gpu_bound' => false, 'cpu_bound' => true,  'io_bound' => false, 'llm_bound' => false],
        'video' => ['hard_cap' => 2, 'gpu_bound' => false, 'cpu_bound' => true,  'io_bound' => false, 'llm_bound' => false],
        'exif' => ['hard_cap' => 3, 'gpu_bound' => false, 'cpu_bound' => false, 'io_bound' => true,  'llm_bound' => false],
        'writeback' => ['hard_cap' => 2, 'gpu_bound' => false, 'cpu_bound' => false, 'io_bound' => true,  'llm_bound' => false],
        'thumbnails' => ['hard_cap' => 3, 'gpu_bound' => false, 'cpu_bound' => true,  'io_bound' => false, 'llm_bound' => false],
        'default' => ['hard_cap' => 2, 'gpu_bound' => false, 'cpu_bound' => true,  'io_bound' => false, 'llm_bound' => false],
    ];

    /**
     * Fully dynamic parallel worker resolution — computes optimal concurrency
     * from system state, workload, and resource availability.
     *
     * max_parallel column is only a hard safety cap (defaults high).
     * The framework decides the actual worker count from:
     *   1. Job resource profile (CPU/GPU/IO/LLM-bound)
     *   2. CPU load + available cores
     *   3. Available memory
     *   4. GPU utilization (for GPU-bound jobs)
     *   5. Job-specific backlog size
     *   6. Total workers running across ALL jobs
     *   7. Time of day
     */
    public function resolveMaxParallel(object $job): int
    {
        $safetyCap = $job->max_parallel ?? 1;
        if ($safetyCap <= 1) {
            return 1; // Explicitly single-worker jobs stay single
        }

        $profile = $this->getJobResourceProfile($job);
        $hardCap = $profile['hard_cap'];

        // ── Gather system state (cached for this tick) ──
        $system = $this->getSystemState();

        // ── Start at 1, earn additional workers ──
        $workers = 1;

        // ── CPU headroom → additional workers for CPU-bound jobs ──
        if ($profile['cpu_bound']) {
            $idleCores = max(0, $system['cores'] - ($system['load'] * $system['cores']));
            // Each worker needs ~2 cores of headroom for CPU-bound work
            $cpuWorkers = (int) floor($idleCores / 2);
            $workers = max($workers, min($cpuWorkers, $hardCap));
        }

        // ── IO-bound jobs scale more freely (they wait on disk/network) ──
        if ($profile['io_bound']) {
            // IO-bound can run more workers since they spend time waiting
            $workers = max($workers, min(3, $hardCap));
            // But back off if memory is tight
            if ($system['memory_gb'] < 8) {
                $workers = min($workers, 2);
            }
        }

        // ── LLM-bound jobs scale with available providers ──
        if ($profile['llm_bound']) {
            $healthyProviders = $this->countHealthyLlmProviders();
            $workers = min($workers, max(1, $healthyProviders), $hardCap);
        }

        // ── GPU gate: GPU-bound jobs clamp when GPU is loaded ──
        if ($profile['gpu_bound']) {
            if ($system['gpu_util'] > 70) {
                $workers = 1;
            } elseif ($system['gpu_util'] > 40) {
                $workers = min($workers, 2);
            }
        }

        // ── Hard gates: insufficient resources → clamp to 1 ──
        if ($system['load_ratio'] > 0.80) {
            return 1;
        }
        if ($system['memory_gb'] < 4) {
            return 1;
        }

        // ── Backlog scaling: scale down when nearly caught up ──
        $pending = $this->getJobPendingCount($job);
        if ($pending < 100) {
            return 1; // Tail end — single worker finishes it
        }
        if ($pending > 10000) {
            $workers++; // Large backlog — push harder
        }

        // ── Cross-job awareness: total running workers across all jobs ──
        try {
            $totalRunning = (int) (DB::selectOne("
                SELECT COALESCE(SUM(running_count), 0) as c FROM scheduled_jobs WHERE last_run_status = 'running'
            ")->c ?? 0);

            // Don't let total system workers exceed core count
            if ($totalRunning >= $system['cores']) {
                return 1;
            }
            // If already running many workers system-wide, be conservative
            if ($totalRunning > $system['cores'] * 0.6) {
                $workers = min($workers, 2);
            }
        } catch (\Exception $e) {
            Log::debug('ScheduledJobService: system load check failed during worker calculation', ['error' => $e->getMessage()]);
        }

        // ── Off-hours boost (9 PM - 6 AM): system is idle, push harder ──
        $hour = (int) now()->format('H');
        if ($hour >= 21 || $hour <= 6) {
            $workers++;
        }

        // ── Final clamp to safety cap ──
        return max(1, min($workers, $safetyCap, $hardCap));
    }

    /**
     * Count healthy LLM providers (active, healthy, circuit not open).
     * Cached 60s to avoid repeated DB queries within a scheduler tick.
     */
    private function countHealthyLlmProviders(): int
    {
        return Cache::remember('healthy_llm_provider_count', 60, function () {
            try {
                $row = DB::selectOne("
                    SELECT COUNT(*) as c FROM llm_instances
                    WHERE is_active = 1 AND is_healthy = 1 AND circuit_state != 'open'
                ");

                return (int) ($row->c ?? 0);
            } catch (\Exception $e) {
                Log::debug('ScheduledJobService: healthy LLM provider count query failed', ['error' => $e->getMessage()]);

                return 2; // Safe default
            }
        });
    }

    /**
     * Determine resource profile from job command string.
     */
    private function getJobResourceProfile(object $job): array
    {
        $resourceProfile = $this->getScheduledJobRuntimeValue($job, 'resource_profile');
        if (is_string($resourceProfile) && isset(self::JOB_RESOURCE_PROFILES[$resourceProfile])) {
            return self::JOB_RESOURCE_PROFILES[$resourceProfile];
        }

        $cmd = $job->command ?? '';

        // Match longest keys first to avoid substring collisions (e.g. 'ai' in 'thumbnails')
        $keys = array_keys(self::JOB_RESOURCE_PROFILES);
        usort($keys, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $key) {
            if ($key !== 'default' && str_contains($cmd, $key)) {
                return self::JOB_RESOURCE_PROFILES[$key];
            }
        }

        // Check for common patterns
        if (str_contains($cmd, 'rag-sync') || str_contains($cmd, 'file-catalog')) {
            return self::JOB_RESOURCE_PROFILES['rag'];
        }

        return self::JOB_RESOURCE_PROFILES['default'];
    }

    /**
     * Get pending count specific to a job's enrichment type.
     */
    private function getJobPendingCount(object $job): int
    {
        $backlogMetric = $this->getScheduledJobRuntimeValue($job, 'backlog_metric');
        $cmd = $job->command ?? '';
        $imageExts = "'jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif'";

        try {
            if ($backlogMetric === 'faces' || str_contains($cmd, 'faces')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NULL AND extension IN ({$imageExts})")->c ?? 0);
            }
            if ($backlogMetric === 'ai' || str_contains($cmd, 'type=ai') || str_contains($cmd, 'ai')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NULL AND extension IN ({$imageExts})")->c ?? 0);
            }
            if ($backlogMetric === 'rag' || str_contains($cmd, 'rag') || str_contains($cmd, 'file-catalog')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND rag_indexed_at IS NULL")->c ?? 0);
            }
            if ($backlogMetric === 'phash' || str_contains($cmd, 'phash')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry fr WHERE status = 'active' AND extension IN ({$imageExts}) AND NOT EXISTS (SELECT 1 FROM file_registry_perceptual_hashes ph WHERE ph.file_registry_id = fr.id)")->c ?? 0);
            }
            if ($backlogMetric === 'thumbnail' || str_contains($cmd, 'thumbnail')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND thumbnail_generated_at IS NULL AND thumbnail_error IS NULL")->c ?? 0);
            }
        } catch (\Exception $e) {
            Log::debug('ScheduledJobService: backlog estimation query failed', ['error' => $e->getMessage()]);
        }

        return 1000; // Unknown job — assume moderate backlog
    }

    private function getScheduledJobRuntimeValue(object $job, string $key): mixed
    {
        $notes = $job->notes ?? null;
        if (! is_string($notes) || trim($notes) === '') {
            return null;
        }

        $decoded = $this->decodeJobParameters($notes);
        if ($decoded === []) {
            return null;
        }

        return $this->extractRuntimeValue($decoded, $key);
    }

    /**
     * Gather system state snapshot (CPU, memory, GPU).
     * Cached per-process to avoid repeated shell calls within a single scheduler tick.
     */
    private ?array $systemStateCache = null;

    private function getSystemState(): array
    {
        if ($this->systemStateCache !== null) {
            return $this->systemStateCache;
        }

        if (self::$cpuCores === null) {
            self::$cpuCores = (int) (trim(Process::timeout(5)->run(['nproc'])->output() ?: '4'));
        }

        $loadAvg = sys_getloadavg();
        $load1min = $loadAvg[0] ?? 0;

        $memoryGB = 8.0; // default
        $memInfo = @file_get_contents('/proc/meminfo');
        if ($memInfo && preg_match('/MemAvailable:\s+(\d+)\s+kB/', $memInfo, $m)) {
            $memoryGB = (int) $m[1] / 1024 / 1024;
        }

        $gpuUtil = 0;
        $gpuOutput = trim(Process::timeout(5)->run([
            'nvidia-smi',
            '--query-gpu=utilization.gpu',
            '--format=csv,noheader,nounits',
        ])->output() ?: '0');
        $gpuUtil = (int) $gpuOutput;

        $this->systemStateCache = [
            'cores' => self::$cpuCores,
            'load' => $load1min / self::$cpuCores, // normalized 0.0-1.0+
            'load_ratio' => $load1min / self::$cpuCores,
            'memory_gb' => $memoryGB,
            'gpu_util' => $gpuUtil,
        ];

        return $this->systemStateCache;
    }

    /**
     * Start a parallel worker — creates run record with worker_id.
     * Returns ['run_id' => int, 'worker_id' => string]
     */
    public function markParallelWorkerStarted(int $jobId, string $triggeredBy = 'scheduler'): array
    {
        $workerId = substr(md5(uniqid((string) mt_rand(), true)), 0, 12);

        DB::insert("
            INSERT INTO scheduled_job_runs (scheduled_job_id, started_at, status, triggered_by, worker_id)
            VALUES (?, NOW(), 'running', ?, ?)
        ", [$jobId, $triggeredBy, $workerId]);

        $runId = (int) DB::getPdo()->lastInsertId();

        // Update job to running if not already
        DB::update("
            UPDATE scheduled_jobs SET
                last_run_at = NOW(),
                last_run_status = 'running',
                updated_at = NOW()
            WHERE id = ?
        ", [$jobId]);

        return ['run_id' => $runId, 'worker_id' => $workerId];
    }

    /**
     * Register a worker PID for a parallel job.
     * Appends to running_pids JSON array and increments running_count.
     *
     * Framework C2 — acquires a per-run TaskCustodyRecord here because this
     * is the first point in the parallel-worker lifecycle where both the
     * worker's PID and its run_id are known together. markParallelWorkerStarted
     * runs before the child process is spawned, so PID is not available yet;
     * by the time we reach registerWorkerPid, Process::start() has returned
     * the child pid. Acquire uses the child's pid in owner_token so that the
     * child's subsequent runJobNow($existingRunId) call is an idempotent
     * re-acquire (same pid, same run_id → same owner_token).
     */
    public function registerWorkerPid(int $jobId, int $pid, int $runId): void
    {
        // Store PID on the run record
        DB::update('UPDATE scheduled_job_runs SET pid = ? WHERE id = ?', [$pid, $runId]);

        // Append to running_pids JSON array atomically
        DB::update("
            UPDATE scheduled_jobs SET
                running_pids = CASE
                    WHEN running_pids IS NULL THEN JSON_ARRAY(?)
                    ELSE JSON_ARRAY_APPEND(running_pids, '$', ?)
                END,
                running_count = running_count + 1,
                last_pid = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$pid, $pid, $pid, $jobId]);

        // C2 — acquire custody for this worker's run. Non-fatal inside
        // acquireRunCustody so a custody failure never blocks the worker.
        $this->acquireRunCustody($jobId, $runId, $pid);
    }

    /**
     * Unregister a worker PID — remove from JSON array and decrement count.
     */
    public function unregisterWorkerPid(int $jobId, int $pid): void
    {
        $job = $this->getJob($jobId);
        if (! $job) {
            return;
        }

        $pids = json_decode($job->running_pids ?? '[]', true) ?: [];
        $pids = array_values(array_filter($pids, fn ($p) => (int) $p !== $pid));
        $newCount = max(0, count($pids));

        DB::update('
            UPDATE scheduled_jobs SET
                running_pids = ?,
                running_count = ?,
                updated_at = NOW()
            WHERE id = ?
        ', [
            empty($pids) ? null : json_encode(array_values($pids)),
            $newCount,
            $jobId,
        ]);
    }

    private function slugify(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
    }

    /**
     * Determine source module from workflow name
     */
    private function determineWorkflowModule(string $name): string
    {
        $nameLower = strtolower($name);

        if (str_contains($nameLower, 'youtube')) {
            return 'YouTube';
        }
        if (str_contains($nameLower, 'joplin')) {
            return 'Joplin';
        }
        if (str_contains($nameLower, 'weather')) {
            return 'Weather';
        }
        if (str_contains($nameLower, 'news') || str_contains($nameLower, 'cybersecurity') || str_contains($nameLower, 'press')) {
            return 'News';
        }
        if (str_contains($nameLower, 'calendar')) {
            return 'Calendar';
        }
        if (str_contains($nameLower, 'reminder') || str_contains($nameLower, 'ozempic')) {
            return 'Reminders';
        }

        return 'Workflows';
    }
}

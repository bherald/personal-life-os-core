<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Jobs\ExecuteWorkflow;
use App\Services\AgentMetrics\AgentUsefulYieldService;
use App\Services\Custody\TaskCustodyService;
use App\Services\Genealogy\GenealogyResearchPlaybookService;
use App\Services\Genealogy\GenealogyService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

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
    public const PHYSICAL_END_OUTCOME_UNKNOWN = 'unknown';

    public const TIMEOUT_OUTCOME_UNKNOWN = 'scheduler_timeout_outcome_unknown';

    private const LAUNCH_RESERVED = 'scheduler_launch_reserved';

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
            SELECT sj.id AS scheduled_job_id, COUNT(f.id) AS consecutive_failures
            FROM scheduled_jobs sj
            LEFT JOIN (
                SELECT scheduled_job_id, MAX(started_at) AS last_success
                FROM scheduled_job_runs
                WHERE status = 'success'
                  AND scheduled_job_id IN ({$placeholders})
                GROUP BY scheduled_job_id
            ) s ON s.scheduled_job_id = sj.id
            LEFT JOIN scheduled_job_runs f
                ON f.scheduled_job_id = sj.id
               AND f.status = 'failed'
               AND f.started_at > COALESCE(s.last_success, '2000-01-01')
            WHERE sj.id IN ({$placeholders})
            GROUP BY sj.id
        ", array_merge($jobIds, $jobIds));

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
        $enabled = (bool) ($data['enabled'] ?? true);
        $nextRunAt = $this->resolveNextRunAtForMutation($cronExpression, $enabled);
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
            $enabled,
            $data['run_in_background'] ?? true,
            $data['without_overlapping'] ?? true,
            $data['timeout_minutes'],
            $notes,
            $data['category'] ?? null,
            $data['source_module'] ?? null,
            $nextRunAt,
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

        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : (bool) $job->enabled;
        $nextRunAt = $this->resolveNextRunAtForMutation($cronExpression, $enabled, $job);
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
            $enabled,
            $data['run_in_background'] ?? $job->run_in_background,
            $data['without_overlapping'] ?? $job->without_overlapping,
            $data['timeout_minutes'] ?? $job->timeout_minutes,
            $notes,
            $data['category'] ?? $job->category,
            $data['source_module'] ?? $job->source_module,
            $nextRunAt,
            $id,
        ]);

        return true;
    }

    /**
     * Resolve the durable clock for a create or update without skipping a due run.
     */
    private function resolveNextRunAtForMutation(string $cronExpression, bool $enabled, ?object $existingJob = null): ?string
    {
        if (! $enabled) {
            return null;
        }

        if ($existingJob !== null) {
            $wasEnabled = (bool) ($existingJob->enabled ?? false);
            $previousCron = $this->normalizeCronExpression((string) ($existingJob->cron_expression ?? ''));
            $existingNextRunAt = $existingJob->next_run_at ?? null;

            if ($wasEnabled && $previousCron === $cronExpression && $existingNextRunAt !== null) {
                return (string) $existingNextRunAt;
            }
        }

        return $this->calculateNextRun($cronExpression)?->format('Y-m-d H:i:s');
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
            try {
                $this->assertScheduledCustodyAdmission($job);
            } catch (InferenceAdmissionException) {
                Log::notice('Scheduler: Replacement custody held', ['reason_code' => 'scheduled_work_custody_unproven']);

                return false;
            }
            if (($job->max_parallel ?? 1) > 1 && ($job->running_count ?? 0) > 0) {
                $resolved = $this->resolveMaxParallel($job);
                if (($job->running_count ?? 0) >= $resolved) {
                    return false; // Dynamic resolution says no more workers
                }
            }

            return true;
        }));
    }

    /** Exact unreleased run custody, including expired/orphaned rows, gates replacement. */
    private function assertScheduledCustodyAdmission(object $job, bool $locked = false): void
    {
        try {
            $suffix = $locked ? ' FOR UPDATE' : '';
            $custodies = DB::select('SELECT *, (expires_at <= NOW()) AS is_expired FROM task_custody_records
                WHERE surface = ? AND released_at IS NULL AND surface_ref LIKE ?'.$suffix,
                [TaskCustodyService::SURFACE_SCHEDULED_JOB, $job->id.':%']);
            $runs = DB::select("SELECT id, pid, status FROM scheduled_job_runs WHERE scheduled_job_id = ?
                AND (status IN ('running', 'unknown') OR EXISTS(SELECT 1 FROM task_custody_records tcr
                    WHERE tcr.surface = ? AND tcr.surface_ref = CONCAT(?, ':', scheduled_job_runs.id) AND tcr.released_at IS NULL))".$suffix,
                [$job->id, TaskCustodyService::SURFACE_SCHEDULED_JOB, $job->id]);
            $byRun = [];
            $activeRunCount = 0;
            $custodyService = app(TaskCustodyService::class);
            foreach ($custodies as $custody) {
                if (! preg_match('/\A'.preg_quote((string) $job->id, '/').':([1-9][0-9]*)\z/', $custody->surface_ref, $reference)
                    || ($custody->is_expired ?? 1) != 0 || in_array($custody->progress_note ?? null, [self::TIMEOUT_OUTCOME_UNKNOWN, self::LAUNCH_RESERVED], true)
                    || ! preg_match('/\Apid:([1-9][0-9]*):start:[0-9]+:run:([1-9][0-9]*)\z/', $custody->owner_token, $owner)
                    || $reference[1] !== $owner[2] || ! $this->isProcessAlive((int) $owner[1])
                    || $custodyService->scheduledJobOwnerToken((int) $owner[1], (int) $owner[2]) !== $custody->owner_token) {
                    throw new \RuntimeException('scheduled_work_custody_unproven');
                }
                $byRun[(int) $reference[1]] = $custody;
            }
            foreach ($runs as $run) {
                if ($run->status === self::PHYSICAL_END_OUTCOME_UNKNOWN && $this->qualifiedUnknownNewWork($job, (int) $run->id)) {
                    continue;
                }
                $activeRunCount++;
                if ($run->status !== 'running' || ! isset($byRun[(int) $run->id])
                    || $byRun[(int) $run->id]->owner_token !== $custodyService->scheduledJobOwnerToken((int) $run->pid, (int) $run->id)) {
                    throw new \RuntimeException('scheduled_work_custody_unproven');
                }
                unset($byRun[(int) $run->id]);
            }
            if ($byRun !== [] || ((int) ($job->running_count ?? 0) > $activeRunCount)
                || ($activeRunCount === 0 && ($job->last_run_status ?? null) === 'running')) {
                throw new \RuntimeException('scheduled_work_custody_unproven');
            }
            $limit = max(1, (int) ($job->max_parallel ?? 1));
            if ($limit > 1) {
                $limit = $this->resolveMaxParallel($job);
                if ($activeRunCount >= $limit) {
                    throw new \RuntimeException('scheduled_parallel_capacity_held');
                }
            } elseif (($job->without_overlapping ?? true) && $activeRunCount > 0) {
                throw new \RuntimeException('scheduled_work_custody_occupied');
            }
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Scheduled work custody is unproven; admission held.', 0, $error);
        }
    }

    /** A trusted empty-current-scope checkpoint permits new work, never the old run. */
    private function qualifiedUnknownNewWork(object $job, int $runId): bool
    {
        $custody = DB::table('task_custody_records')->where('surface', TaskCustodyService::SURFACE_SCHEDULED_JOB)
            ->where('surface_ref', self::custodySurfaceRef((int) $job->id, $runId))->whereNotNull('released_at')
            ->whereNull('outcome')->where('progress_note', 'scheduler_physical_terminal_verified')
            ->where('notification_state', 'suppressed')->first();
        if (! $custody) {
            return false;
        }
        $envelope = json_decode($custody->result_envelope, true, flags: JSON_THROW_ON_ERROR);
        $lifecycle = $envelope['scheduler_lifecycle_reconciliation'] ?? [];
        $checkpoint = $envelope['new_work_checkpoint'] ?? [];
        $physical = $envelope['physical_closure'] ?? [];
        if (($lifecycle['schema'] ?? null) !== 'plos.scheduled_unknown_lifecycle.v1'
            || ($lifecycle['job_id'] ?? null) !== (int) $job->id || ($lifecycle['run_id'] ?? null) !== $runId
            || ($lifecycle['custody_id'] ?? null) !== (int) $custody->id
            || ($lifecycle['domain_outcome'] ?? null) !== 'unknown' || ($lifecycle['replay_authorized'] ?? null) !== false
            || ($physical['schema'] ?? null) !== 'plos.scheduled_db_physical_terminal.v1'
            || ($physical['owner_token'] ?? null) !== $custody->owner_token
            || ($physical['surface_ref'] ?? null) !== $custody->surface_ref
            || ($checkpoint['schema'] ?? null) !== 'plos.scheduled_db_new_work_checkpoint.v1'
            || ($checkpoint['job_id'] ?? null) !== (int) $job->id || ($checkpoint['run_id'] ?? null) !== $runId
            || ($checkpoint['custody_id'] ?? null) !== (int) $custody->id
            || ($checkpoint['command_sha256'] ?? null) !== hash('sha256', $job->command)
            || ($checkpoint['current_candidate_count'] ?? null) !== 0
            || ($checkpoint['operation_kind'] ?? null) !== 'current_pending_idempotent_review_ignore'
            || ($checkpoint['domain_outcome'] ?? null) !== 'unknown'
            || ($checkpoint['historical_replay_authorized'] ?? null) !== false
            || ! is_string($checkpoint['preview_receipt_sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $checkpoint['preview_receipt_sha256'])) {
            return false;
        }
        $observed = Carbon::parse($checkpoint['observed_at'] ?? 'invalid');
        if ($observed->isFuture() || $observed->lt(Carbon::parse($lifecycle['recorded_at']))) {
            return false;
        }
        $originalRun = $lifecycle['original_rows']['run'] ?? null;
        $currentRun = DB::table('scheduled_job_runs')->where('id', $runId)->where('scheduled_job_id', $job->id)->first();
        if (! is_array($originalRun) || ! $currentRun || $this->physicalClosureSnapshot((array) $currentRun)
            !== $this->physicalClosureSnapshot(array_replace($originalRun, ['status' => self::PHYSICAL_END_OUTCOME_UNKNOWN]))) {
            return false;
        }
        // Fresh direct table/source reads; cached or drifted policy cannot grant
        // new work from an old checkpoint. Producer qualification remains root-owned.
        $policy = DB::table('system_configs')->where('section', $checkpoint['policy_section'] ?? '')
            ->where('config_key', $checkpoint['policy_key'] ?? '')->first();
        $registry = DB::table('review_type_registry')->where('name', $checkpoint['review_type'] ?? '')->first();
        if (! $policy || ! $registry || ($checkpoint['policy_row_sha256'] ?? null) !== $this->physicalClosureSnapshot((array) $policy)
            || ($checkpoint['registry_row_sha256'] ?? null) !== $this->physicalClosureSnapshot((array) $registry)
            || ($checkpoint['source_classes_sha256'] ?? []) === []) {
            return false;
        }
        $commandClass = $checkpoint['command_class'] ?? null;
        if (! is_string($commandClass) || ! str_starts_with($commandClass, 'App\\Console\\Commands\\')) {
            return false;
        }
        $reflection = new \ReflectionClass($commandClass);
        $signature = trim((string) ($reflection->getDefaultProperties()['signature'] ?? ''));
        if (preg_split('/\s+/', $signature)[0] !== preg_split('/\s+/', trim($job->command))[0]) {
            return false;
        }
        $requiredClasses = [$commandClass];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin() && str_starts_with($type->getName(), 'App\\')) {
                $requiredClasses[] = $type->getName();
            }
        }
        if (array_diff($requiredClasses, array_keys($checkpoint['source_classes_sha256'])) !== []) {
            return false;
        }
        foreach ($checkpoint['source_classes_sha256'] as $class => $expectedHash) {
            if (! is_string($class) || ! str_starts_with($class, 'App\\') || ! is_string($expectedHash)
                || ! preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
                return false;
            }
            $file = (new \ReflectionClass($class))->getFileName();
            if (! $file || hash_file('sha256', $file) !== $expectedHash) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark job as started
     */
    public function markJobStarted(int $jobId, string $triggeredBy = 'scheduler'): int
    {
        return DB::transaction(function () use ($jobId, $triggeredBy) {
            $job = DB::selectOne('SELECT * FROM scheduled_jobs WHERE id = ? FOR UPDATE', [$jobId]);
            if (! $job) {
                throw new InferenceAdmissionException('Scheduled job admission unavailable.');
            }
            $this->assertScheduledCustodyAdmission($job, true);
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
                running_count = running_count + 1,
                running_pids = CASE WHEN running_pids IS NULL THEN JSON_ARRAY(?) ELSE JSON_ARRAY_APPEND(running_pids, '$', ?) END,
                updated_at = NOW()
            WHERE id = ?
        ", [$pid, $pid, $pid, $jobId]);

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
        });
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
     * Publication failure holds execution; a missing ledger cannot grant admission.
     *
     * Idempotent across the spawn boundary: the parent process can acquire in
     * registerWorkerPid() using the child's pid; when the child then re-enters
     * via runJobNow($existingRunId) it computes the same owner_token (same
     * child pid, same runId) and TaskCustodyService::acquire() returns the
     * existing row without creating a duplicate.
     */
    private function acquireRunCustody(int $jobId, int $runId, ?int $pid): object
    {
        try {
            $row = DB::selectOne(
                'SELECT timeout_minutes FROM scheduled_jobs WHERE id = ?',
                [$jobId]
            );
            $timeoutMinutes = max(1, (int) ($row->timeout_minutes ?? 60));
            $expiresInSeconds = ($timeoutMinutes + 15) * 60;

            $custodyService = app(TaskCustodyService::class);
            $ownerToken = $custodyService->scheduledJobOwnerToken($pid, $runId);

            $custody = $custodyService->acquire(
                TaskCustodyService::SURFACE_SCHEDULED_JOB,
                $this->custodySurfaceRef($jobId, $runId),
                $ownerToken,
                $expiresInSeconds
            );
            if (! $custody) {
                throw new InferenceAdmissionException('Scheduled custody publication unavailable; execution held.');
            }

            return $custody;
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Scheduled custody publication unavailable; execution held.', 0, $error);
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
     * Consume an independently observed, already persisted physical closure.
     * This cannot release custody, observe/kill work, or authorize a replay.
     * The trusted scoped observer owns the original-source/DB/native proof and
     * its exact TCR release CAS; a caller cannot supply proof booleans here.
     */
    public function reconcilePhysicallyClosedRun(int $custodyId, ?string $expectedSnapshotSha256 = null, bool $dryRun = true): array
    {
        $held = static fn (string $reason): array => ['status' => 'held', 'reason_code' => $reason,
            'domain_outcome' => 'unknown', 'replay_authorized' => false];
        if ($custodyId < 1 || (! $dryRun && $expectedSnapshotSha256 === null)
            || ($expectedSnapshotSha256 !== null && ! preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshotSha256))) {
            return $held('physical_closure_selector_invalid');
        }

        try {
            return DB::transaction(function () use ($custodyId, $expectedSnapshotSha256, $dryRun, $held): array {
                $selected = DB::table('task_custody_records')->where('id', $custodyId)->first();
                if (! $selected || $selected->surface !== TaskCustodyService::SURFACE_SCHEDULED_JOB
                    || ! preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $selected->surface_ref, $ref)) {
                    return $held('scheduled_physical_closure_unqualified');
                }
                // Match the scheduler's job-first lock order. All authority
                // comes from these complete locked rows, never the selector.
                $job = DB::table('scheduled_jobs')->where('id', (int) $ref[1])->lockForUpdate()->first();
                $run = DB::table('scheduled_job_runs')->where('id', (int) $ref[2])->lockForUpdate()->first();
                $custody = DB::table('task_custody_records')->where('id', $custodyId)->lockForUpdate()->first();
                if (! $job || ! $run || ! $custody || $run->scheduled_job_id != $job->id
                    || $custody->surface_ref !== $selected->surface_ref || $custody->surface !== $selected->surface) {
                    return $held('scheduled_physical_closure_unqualified');
                }
                $preimage = ['job' => (array) $job, 'run' => (array) $run, 'custody' => (array) $custody];
                $snapshot = $this->physicalClosureSnapshot($preimage);
                if ($expectedSnapshotSha256 !== null && ! hash_equals($expectedSnapshotSha256, $snapshot)) {
                    return $held('scheduled_physical_closure_snapshot_changed');
                }
                $envelope = json_decode((string) $custody->result_envelope, true, flags: JSON_THROW_ON_ERROR);
                $proof = $envelope['physical_closure'] ?? null;
                if (! $this->qualifiedPhysicalClosure($job, $run, $custody, $proof)
                    || array_key_exists('scheduler_lifecycle_reconciliation', $envelope)) {
                    return $held('scheduled_physical_closure_unqualified');
                }
                // Keep this boundary single-worker. Other workers and unknown
                // slots require their own exact physical/effect proof.
                $otherRunning = DB::table('scheduled_job_runs')->where('scheduled_job_id', $job->id)
                    ->where('id', '<>', $run->id)->where('status', 'running')->exists();
                $pids = json_decode($job->running_pids ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                if ($otherRunning || ! is_array($pids) || (int) $job->running_count !== 1
                    || ($job->last_pid !== null && (int) $job->last_pid !== (int) $run->pid)
                    || array_filter($pids, fn ($pid) => ! is_int($pid) || $pid !== (int) $run->pid) !== []) {
                    return $held('scheduled_other_physical_slots_unqualified');
                }
                $result = ['schema' => 'plos.scheduled_unknown_lifecycle.v1', 'custody_id' => $custodyId,
                    'job_id' => (int) $job->id, 'run_id' => (int) $run->id, 'snapshot_sha256' => $snapshot,
                    'domain_outcome' => 'unknown', 'replay_authorized' => false];
                if ($dryRun) {
                    return $result + ['status' => 'dry_run_verified'];
                }
                $envelope['scheduler_lifecycle_reconciliation'] = $result + ['original_rows' => $preimage,
                    'recorded_at' => now()->toIso8601String()];
                $changedRun = DB::table('scheduled_job_runs')->where('id', $run->id)
                    ->where('scheduled_job_id', $job->id)->where('status', 'running')->where('pid', $run->pid)
                    ->update(['status' => self::PHYSICAL_END_OUTCOME_UNKNOWN]);
                $changedJob = DB::table('scheduled_jobs')->where('id', $job->id)->where('last_run_status', 'running')
                    ->where('running_count', 1)->update(['last_run_status' => self::PHYSICAL_END_OUTCOME_UNKNOWN,
                        'last_pid' => null, 'running_pids' => null, 'running_count' => 0, 'updated_at' => now()]);
                $changedCustody = DB::table('task_custody_records')->where('id', $custodyId)
                    ->where('surface', $custody->surface)->where('surface_ref', $custody->surface_ref)
                    ->where('owner_token', $custody->owner_token)->where('released_at', $custody->released_at)
                    ->whereNull('outcome')->where('progress_note', 'scheduler_physical_terminal_verified')
                    ->update(['result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                if ($changedRun !== 1 || $changedJob !== 1 || $changedCustody !== 1) {
                    throw new \RuntimeException('Scheduled UNKNOWN lifecycle CAS failed.');
                }
                $afterJob = DB::table('scheduled_jobs')->where('id', $job->id)->first();
                $afterRun = DB::table('scheduled_job_runs')->where('id', $run->id)->first();
                $afterCustody = DB::table('task_custody_records')->where('id', $custodyId)->first();
                $expectedJob = array_replace((array) $job, ['last_run_status' => 'unknown', 'last_pid' => null,
                    'running_pids' => null, 'running_count' => 0]);
                $expectedRun = array_replace((array) $run, ['status' => 'unknown']);
                $expectedCustody = array_replace((array) $custody, ['result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR)]);
                // MySQL canonicalizes JSON bytes; compare its complete typed
                // document without weakening other full-row readback fields.
                $afterCustody->result_envelope = json_decode($afterCustody->result_envelope, true, flags: JSON_THROW_ON_ERROR);
                $expectedCustody['result_envelope'] = $envelope;
                foreach (['job' => [$afterJob, $expectedJob], 'run' => [$afterRun, $expectedRun],
                    'custody' => [$afterCustody, $expectedCustody]] as [$after, $expected]) {
                    $actual = (array) $after;
                    unset($actual['updated_at'], $expected['updated_at']);
                    if ($this->physicalClosureSnapshot($actual) !== $this->physicalClosureSnapshot($expected)) {
                        throw new \RuntimeException('Scheduled UNKNOWN lifecycle readback failed.');
                    }
                }

                return $result + ['status' => 'reconciled'];
            });
        } catch (\Throwable) {
            return $held('scheduled_physical_closure_outcome_unproven');
        }
    }

    private function physicalClosureSnapshot(array $rows): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($rows), JSON_THROW_ON_ERROR));
    }

    private function qualifiedPhysicalClosure(object $job, object $run, object $custody, mixed $proof): bool
    {
        if (! is_array($proof) || ($proof['schema'] ?? null) !== 'plos.scheduled_db_physical_terminal.v1'
            || $custody->released_at === null || $custody->outcome !== null
            || $custody->progress_note !== 'scheduler_physical_terminal_verified'
            || $custody->notification_state !== 'suppressed' || $job->last_run_status !== 'running'
            || $run->status !== 'running' || $run->completed_at !== null || $job->job_type !== 'command'
            || ($proof['custody_id'] ?? null) !== (int) $custody->id
            || ($proof['job_id'] ?? null) !== (int) $job->id || ($proof['run_id'] ?? null) !== (int) $run->id
            || ($proof['owner_token'] ?? null) !== $custody->owner_token
            || ($proof['surface_ref'] ?? null) !== $custody->surface_ref
            || ($proof['command_sha256'] ?? null) !== hash('sha256', $job->command)
            || ($proof['domain_outcome'] ?? null) !== 'unknown' || ($proof['replay_authorized'] ?? null) !== false) {
            return false;
        }
        foreach (['source_scope_receipt_sha256', 'privileged_db_receipt_sha256', 'original_custody_sha256',
            'original_job_sha256', 'original_run_sha256'] as $field) {
            if (! is_string($proof[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $proof[$field])) {
                return false;
            }
        }
        if (! hash_equals($proof['original_job_sha256'], $this->physicalClosureSnapshot((array) $job))
            || ! hash_equals($proof['original_run_sha256'], $this->physicalClosureSnapshot((array) $run))) {
            return false;
        }
        $original = $proof['original_custody_row'] ?? null;

        return is_array($original) && ($original['id'] ?? null) === (int) $custody->id
            && ($original['surface'] ?? null) === $custody->surface && ($original['surface_ref'] ?? null) === $custody->surface_ref
            && ($original['owner_token'] ?? null) === $custody->owner_token
            && array_key_exists('released_at', $original) && $original['released_at'] === null
            && array_key_exists('outcome', $original) && $original['outcome'] === null
            && array_key_exists('result_envelope', $original) && $original['result_envelope'] === null
            && hash_equals($proof['original_custody_sha256'], $this->physicalClosureSnapshot($original));
    }

    /**
     * Mark job as completed (success or failure)
     *
     * For parallel jobs (max_parallel > 1), unregisters the worker PID and only
     * resets the job status when the last worker finishes.
     */
    public function markJobCompleted(int $jobId, int $runId, bool $success, ?string $output = null, ?float $duration = null, ?int $pid = null, ?int $itemsProcessed = null): void
    {
        // Extract items_processed from output marker if not explicitly provided
        if ($itemsProcessed === null && $output) {
            if (preg_match('/\[ITEMS_PROCESSED:(\d+)\]/', $output, $m)) {
                $itemsProcessed = (int) $m[1];
            }
        }

        $isLastWorker = DB::transaction(function () use ($jobId, $runId, $success, $output, $duration, $pid, $itemsProcessed) {
            $job = DB::selectOne('SELECT * FROM scheduled_jobs WHERE id = ? FOR UPDATE', [$jobId]);
            if (! $job) {
                throw new InferenceAdmissionException('Scheduled completion parent unavailable; custody retained.');
            }
            if (DB::table('scheduled_job_runs')->where('id', $runId)->where('scheduled_job_id', $jobId)
                ->where('status', self::PHYSICAL_END_OUTCOME_UNKNOWN)->exists()) {
                throw new InferenceAdmissionException('Scheduled UNKNOWN outcome requires domain reconciliation; completion refused.');
            }
            $status = $success ? 'success' : 'failed';

            // Every registered owner has a counted slot, including single workers.
            if ($job && $pid) {
                $this->unregisterWorkerPid($jobId, $pid);
                $job = DB::selectOne('SELECT * FROM scheduled_jobs WHERE id = ?', [$jobId]);
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

            // Framework C2 — release the custody record for THIS run. Surface ref
            // is per-run ("{jobId}:{runId}") so parallel workers each release
            // their own row; the previous isLastWorker gate was a relic of the
            // per-job scheme where only one custody row existed across all
            // workers.
            $this->releaseRunCustody($jobId, $runId, $success, $output, $itemsProcessed);

            return $isLastWorker;
        });

        if ($isLastWorker) {
            try {
                Cache::forget("scheduler:job:{$jobId}:deadline");
            } catch (\Throwable $error) {
                throw new InferenceAdmissionException('Scheduled completion cache cleanup failed; execution must not be replayed.', 0, $error);
            }
        }

        // Adapt timeout for this job based on historical performance
        if ($success && $duration > 0 && $isLastWorker && $itemsProcessed !== 0) {
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
                      AND (items_processed IS NULL OR items_processed > 0)
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
                  AND (items_processed IS NULL OR items_processed > 0)
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
        $default = (($job->job_type ?? null) === 'agent_task') ? 60 : 30;
        $notes = $job->notes ?? null;
        if (is_string($notes)) {
            $decoded = json_decode($notes, true);
            $notes = is_array($decoded) ? $decoded : [];
        }

        $runtime = is_array($notes) && is_array($notes['runtime'] ?? null)
            ? $notes['runtime']
            : [];
        $configured = $runtime['minimum_timeout_minutes'] ?? null;
        if (! is_int($configured) || $configured < $default || $configured > 360) {
            return $default;
        }

        return $configured;
    }

    /**
     * Run a specific job immediately (manual trigger)
     */
    public function runJobNow(int $jobId, string $triggeredBy = 'manual', ?int $existingRunId = null, ?\Closure $timeoutExtender = null, ?string $workerId = null): array
    {
        $job = $this->getJob($jobId);
        if (! $job) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        // Check for overlapping if enabled (skip for scheduler — parent already validated)
        if ($triggeredBy !== 'scheduler' && $job->without_overlapping && $job->last_run_status === 'running') {
            return ['success' => false, 'error' => 'Job is already running'];
        }

        $pid = getmypid() ?: null;

        // Reuse parent's run record if provided (parallel worker path), else create new
        if ($existingRunId) {
            $runId = $existingRunId;
            $this->claimReservedWorker($jobId, (int) $pid, $runId, $workerId);
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
                        $job->name,
                        [
                            'run_id' => $runId,
                            'pid' => $pid,
                        ]
                    );
                    break;

                default:
                    $output = "Unknown job type: {$job->job_type}";
                    $success = false;
            }
        } catch (\Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
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

        $buffer = new BufferedOutput;
        $exitCode = Artisan::call($commandLine, [], $buffer);
        $output = $buffer->fetch();

        if ($output === '') {
            $output = Artisan::output();
        }

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
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
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
        ?string $scheduledJobName = null,
        array $executionContext = []
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
                    return $this->runGenealogyResearchFromQueue($agentLoop, $skillName, $params, $output, $timeoutMinutes, $timeoutExtender, $executionContext);
                }
                if ($mode === 'assess') {
                    return $this->runGenealogyAssess($params, $output, $timeoutMinutes);
                }
                if ($mode === 'research') {
                    return $this->runGenealogyResearchFromQueue($agentLoop, $skillName, $params, $output, $timeoutMinutes, $timeoutExtender, $executionContext);
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
                'scheduled_job_name' => $scheduledJobName,
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
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
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

    private function extractRuntimeValue(array $params, string $key): mixed
    {
        $runtime = $params['runtime'] ?? null;
        $value = is_array($runtime) ? ($runtime[$key] ?? null) : null;
        $value ??= $params[$key] ?? null;

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        return is_scalar($value) ? $value : null;
    }

    private function normalizedUnitInterval(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function nonNegativeRuntimeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
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
                if (InferenceAdmissionException::isAdmissionFailure($e)) {
                    throw $e;
                }
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
        $genealogy = app(GenealogyService::class);
        $cleanup = $genealogy->cleanupStaleProcessingResearchTasks($treeId, $staleHours * 60);

        return [
            'stale_task_count' => (int) ($cleanup['stale_count'] ?? 0),
            'held_task_count' => (int) ($cleanup['held_count'] ?? $cleanup['stale_count'] ?? 0),
            'released_queue_count' => 0,
            'stale_task_ids' => [],
            'released_queue_item_ids' => [],
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

            $genealogyService = app(GenealogyService::class);
            $totalQueued = 0;
            $totalStale = 0;
            $totalStaleTasks = 0;
            $staleTaskHours = max(1, (int) ($params['stale_task_hours'] ?? $params['stale_hours'] ?? 3));
            $assessLimit = max(1, min(200, (int) ($this->extractRuntimeValue($params, 'assess_limit') ?? 20)));
            $recentWorkCooldownHours = $this->extractRuntimeValue($params, 'recent_work_cooldown_hours');
            $recentWorkCooldownHours = is_numeric($recentWorkCooldownHours) && (int) $recentWorkCooldownHours > 0
                ? (int) $recentWorkCooldownHours
                : null;
            $recentWorkCutoff = $recentWorkCooldownHours !== null
                ? Carbon::now()->subHours($recentWorkCooldownHours)->toDateTimeString()
                : null;
            $includeExhaustedFallback = filter_var(
                $this->extractRuntimeValue($params, 'include_exhausted_fallback'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) === true;
            $exhaustedFallbackMinDataGapScore = $this->normalizedUnitInterval(
                $this->extractRuntimeValue($params, 'exhausted_fallback_min_data_gap_score')
            );
            $exhaustedFallbackMinPendingHints = $this->nonNegativeRuntimeInt(
                $this->extractRuntimeValue($params, 'exhausted_fallback_min_pending_hints')
            );
            $exhaustedFallbackMaxDataGapOnlyExhaustionScore = $this->normalizedUnitInterval(
                $this->extractRuntimeValue($params, 'exhausted_fallback_max_data_gap_only_exhaustion_score')
            );
            $emptyQueueRefillEnabled = filter_var(
                $this->extractRuntimeValue($params, 'empty_queue_refill_enabled'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) === true;
            $emptyQueueRefillCooldownHours = $this->nonNegativeRuntimeInt(
                $this->extractRuntimeValue($params, 'empty_queue_refill_cooldown_hours')
            );
            $emptyQueueRefillLimit = $this->nonNegativeRuntimeInt(
                $this->extractRuntimeValue($params, 'empty_queue_refill_limit')
            );
            $researchYieldGuard = $this->genealogyResearchYieldGuard($params);
            $exhaustedFallbackTrees = 0;
            $emptyQueueRefillTrees = 0;
            $yieldGuardPausedTrees = 0;

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

                // In-progress queue rows remain held until their exact owner is proven dead.

                if ($researchYieldGuard['pause_assessment']) {
                    $yieldGuardPausedTrees++;

                    continue;
                }

                // Get priority persons from coverage table
                $result = $genealogyService->getPriorityPersons($treeId, $assessLimit, null, false, $recentWorkCutoff);
                $persons = $result['persons'] ?? [];
                if ($persons === [] && $includeExhaustedFallback) {
                    $fallbackResult = $genealogyService->getPriorityPersons(
                        $treeId,
                        $assessLimit,
                        null,
                        true,
                        $recentWorkCutoff,
                        $exhaustedFallbackMinDataGapScore,
                        $exhaustedFallbackMinPendingHints,
                        $exhaustedFallbackMaxDataGapOnlyExhaustionScore
                    );
                    $fallbackPersons = $fallbackResult['persons'] ?? [];
                    if ($fallbackPersons !== []) {
                        $persons = $fallbackPersons;
                        $exhaustedFallbackTrees++;
                    }
                }
                if ($persons === [] && $emptyQueueRefillEnabled) {
                    $activeQueueCount = $this->getActiveGenealogyQueueCount($treeId);
                    if ($activeQueueCount === 0) {
                        $refillLimit = $emptyQueueRefillLimit !== null && $emptyQueueRefillLimit > 0
                            ? min($assessLimit, $emptyQueueRefillLimit)
                            : min($assessLimit, 10);
                        $refillCooldownHours = $emptyQueueRefillCooldownHours !== null
                            ? $emptyQueueRefillCooldownHours
                            : 12;
                        $refillCutoff = $refillCooldownHours > 0
                            ? Carbon::now()->subHours($refillCooldownHours)->toDateTimeString()
                            : null;
                        $refillResult = $genealogyService->getPriorityPersons(
                            $treeId,
                            $refillLimit,
                            null,
                            true,
                            $refillCutoff,
                            $exhaustedFallbackMinDataGapScore,
                            $exhaustedFallbackMinPendingHints,
                            $exhaustedFallbackMaxDataGapOnlyExhaustionScore
                        );
                        $refillPersons = $refillResult['persons'] ?? [];
                        if ($refillPersons !== []) {
                            $persons = $refillPersons;
                            $emptyQueueRefillTrees++;
                        }
                    }
                }

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
                ."held {$totalStaleTasks} stale tasks with unproven owners. Assess limit: {$assessLimit}. "
                .'Recent work cooldown: '.($recentWorkCooldownHours !== null ? $recentWorkCooldownHours.'h.' : 'off.').' '
                .'Exhausted fallback: '.($includeExhaustedFallback ? 'enabled' : 'disabled')
                .", used {$exhaustedFallbackTrees} tree(s). "
                .'Fallback filters: data_gap>='.($exhaustedFallbackMinDataGapScore ?? 'off')
                .', pending_hints>='.($exhaustedFallbackMinPendingHints ?? 'off')
                .', data_gap_only_exhaustion<='.($exhaustedFallbackMaxDataGapOnlyExhaustionScore ?? 'off').'. '
                .'Empty-queue refill: '.($emptyQueueRefillEnabled ? 'enabled' : 'disabled')
                .", used {$emptyQueueRefillTrees} tree(s), cooldown "
                .($emptyQueueRefillCooldownHours !== null ? $emptyQueueRefillCooldownHours.'h' : 'default')
                .', limit '.($emptyQueueRefillLimit !== null ? $emptyQueueRefillLimit : 'default').'. '
                .'Research yield guard: '.$researchYieldGuard['state']
                .', terminal non-useful '.$researchYieldGuard['non_useful_terminal'].'/'.$researchYieldGuard['decision_count']
                .' decisions ('.round($researchYieldGuard['non_useful_rate'] * 100, 1).'%), '
                .'accepted '.$researchYieldGuard['accepted'].', pending '.$researchYieldGuard['pending'].', '
                .'sample floor '.$researchYieldGuard['min_submissions']
                .', threshold '.round($researchYieldGuard['max_non_useful_rate'] * 100, 1).'%, '
                ."paused {$yieldGuardPausedTrees} tree(s).";

            return true;
        } catch (\Throwable $e) {
            $output = 'Assess failed: '.$e->getMessage();
            Log::error('runGenealogyAssess failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return false;
        }
    }

    private function getActiveGenealogyQueueCount(int $treeId): int
    {
        $row = DB::selectOne("
            SELECT COUNT(*) AS c
            FROM genealogy_research_queue
            WHERE tree_id = ? AND status IN ('pending', 'in_progress')
        ", [$treeId]);

        return (int) ($row->c ?? 0);
    }

    /**
     * @return array{state:string,enabled:bool,pause_assessment:bool,window_hours:int,min_submissions:int,max_non_useful_rate:float,agent_id:string,submissions:int,decision_count:int,non_useful_terminal:int,accepted:int,reviewed_only:int,pending:int,non_useful_rate:float}
     */
    private function genealogyResearchYieldGuard(array $params): array
    {
        $enabled = filter_var(
            $this->extractRuntimeValue($params, 'research_yield_guard_enabled'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) === true;
        $windowHours = max(1, min(168, (int) ($this->extractRuntimeValue($params, 'research_yield_guard_window_hours') ?? 24)));
        $minSubmissions = max(1, min(10000, (int) ($this->extractRuntimeValue($params, 'research_yield_guard_min_submissions') ?? 50)));
        $maxNonUsefulRate = $this->normalizedUnitInterval(
            $this->extractRuntimeValue($params, 'research_yield_guard_max_non_useful_rate')
                ?? $this->extractRuntimeValue($params, 'research_yield_guard_max_auto_reject_rate')
        ) ?? 0.8;
        $agentId = (string) ($this->extractRuntimeValue($params, 'research_yield_guard_agent_id') ?? 'genealogy-records');
        $pauseWhenTriggered = filter_var(
            $this->extractRuntimeValue($params, 'research_yield_guard_pause_assessment'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) !== false;

        $result = [
            'state' => $enabled ? 'insufficient_sample' : 'disabled',
            'enabled' => $enabled,
            'pause_assessment' => false,
            'window_hours' => $windowHours,
            'min_submissions' => $minSubmissions,
            'max_non_useful_rate' => $maxNonUsefulRate,
            'agent_id' => $agentId,
            'submissions' => 0,
            'decision_count' => 0,
            'non_useful_terminal' => 0,
            'accepted' => 0,
            'reviewed_only' => 0,
            'pending' => 0,
            'non_useful_rate' => 0.0,
        ];

        if (! $enabled) {
            return $result;
        }

        $snapshot = app(AgentUsefulYieldService::class)->submissionOutcomeSnapshot(
            $agentId,
            'genealogy_finding',
            $windowHours
        );
        if (! $snapshot['available']) {
            $result['state'] = 'unavailable';

            return $result;
        }

        $result['submissions'] = $snapshot['submitted'];
        $result['non_useful_terminal'] = $snapshot['declined'] + $snapshot['expired'];
        $result['accepted'] = $snapshot['accepted'];
        $result['reviewed_only'] = $snapshot['reviewed_only'];
        $result['pending'] = $snapshot['pending'];
        $result['decision_count'] = $result['accepted'] + $result['non_useful_terminal'];
        $result['non_useful_rate'] = $result['decision_count'] > 0
            ? round($result['non_useful_terminal'] / $result['decision_count'], 4)
            : 0.0;

        if ($result['decision_count'] < $minSubmissions) {
            return $result;
        }

        $lowYield = $result['non_useful_rate'] >= $maxNonUsefulRate;
        $result['state'] = $lowYield ? 'low_yield' : 'healthy';
        $result['pause_assessment'] = $lowYield && $pauseWhenTriggered;

        return $result;
    }

    /**
     * Single-person research from queue: claims top pending person,
     * runs research→analyze→report via AgentLoopService with adaptive timeout.
     */
    private function runGenealogyResearchFromQueue(
        AgentLoopService $agentLoop, string $skillName, array $params, string &$output,
        int $timeoutMinutes, ?\Closure $timeoutExtender = null, array $executionContext = []
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

        $runId = (int) ($executionContext['run_id'] ?? 0);
        $pid = (int) ($executionContext['pid'] ?? 0);
        $sessionId = self::buildGenealogyQueueSessionId($runId, $pid, (int) $item->id);

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
        $playbookService = app(GenealogyResearchPlaybookService::class);
        $researchPlaybook = $playbookService->fromQueueContract($questionType, $researchQuestion, $selectionReason);
        $researchPlaybookPrompt = $playbookService->promptBlock($researchPlaybook);

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
                WHERE id = ? AND status = 'in_progress' AND session_id = ?
            ", [$reason, $item->id, $sessionId]);

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
        $maxIterations = $this->extractRuntimeValue($params, 'max_iterations') ?? $params['max_iterations'] ?? null;
        $queueQualityAnalysis = filter_var(
            $this->extractRuntimeValue($params, 'quality_analysis_enabled') ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? false;
        $configuredModelRole = $queueQualityAnalysis
            ? $this->extractRuntimeValue($params, 'quality_analysis_model_role')
            : $this->extractRuntimeValue($params, 'model_role');
        $proposalQualityPolicy = $queueQualityAnalysis
            ? $this->extractRuntimeValue($params, 'proposal_quality_policy')
            : null;

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
            ."- Document searches in the outcome and use search-coverage tools when available; do not pass genealogy_research_tasks.id={$taskId} to GPS-only logging tools unless the runtime supplies a compatible GPS task ID.\n\n"
            .($researchPlaybookPrompt !== '' ? $researchPlaybookPrompt."\n\n" : '')
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
            'model' => $this->extractRuntimeValue($params, 'model') ?? $params['model'] ?? null,
            'model_role' => $configuredModelRole ?? $params['model_role'] ?? null,
            'context' => [
                'queue_mode' => true,
                'queue_quality_analysis' => $queueQualityAnalysis,
                'queue_quality_model_role' => $configuredModelRole,
                'queue_proposal_quality_policy' => $proposalQualityPolicy,
                'queue_quality_policy_source' => 'scheduled_jobs.genealogy_agent_research_queue.notes.runtime',
                'queue_item_id' => $item->id,
                'target_person_id' => $personId,
                'target_person_name' => $personName,
                'research_question' => $researchQuestion,
                'selection_reason' => $selectionReason,
                'question_type' => $questionType,
                'research_playbook' => $playbookService->contextPayload($researchPlaybook),
                'genealogy_task_id' => $taskId,
                'priority_score' => (float) $item->priority_score,
                'skip_assess' => true,
            ],
            'timeout_minutes' => $timeoutMinutes,
            'timeout_extender' => $timeoutExtender,
            'execution_guard' => function () use ($item, $taskId, $sessionId): void {
                $current = DB::selectOne('
                    SELECT t.id
                    FROM genealogy_research_tasks t
                    JOIN genealogy_research_queue q ON q.id = t.queue_item_id AND q.tree_id = t.tree_id
                    WHERE t.id = ? AND t.tree_id = ? AND t.queue_item_id = ?
                      AND t.status = \'processing\' AND q.status = \'in_progress\' AND q.session_id = ?
                ', [$taskId, $item->tree_id, $item->id, $sessionId]);
                if ($current === null) {
                    throw new InferenceAdmissionException('Genealogy queue attempt no longer owns execution; no new work was dispatched.');
                }
            },
            'progress_callback' => function (string $event, array $details = []) use ($item, $taskId, $sessionId): void {
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
                      AND status = \'processing\'
                      AND queue_item_id = ?
                      AND tree_id = ?
                      AND EXISTS (
                          SELECT 1 FROM genealogy_research_queue q
                          WHERE q.id = ? AND q.tree_id = ?
                            AND q.status = \'in_progress\' AND q.session_id = ?
                      )
                ', [$message, $taskId, $item->id, $item->tree_id, $item->id, $item->tree_id, $sessionId]);

                DB::update('
                    UPDATE genealogy_research_queue
                    SET notes = ?, updated_at = NOW()
                    WHERE id = ? AND status = \'in_progress\' AND session_id = ?
                ', [$message, $item->id, $sessionId]);
            },
        ];
        if (is_numeric($maxIterations)) {
            $options['max_iterations'] = max(1, (int) $maxIterations);
        }

        $result = $agentLoop->execute($skillName, $task, $options);
        if (($result['execution_guard_held'] ?? false) === true) {
            $output = "Research queue attempt #{$item->id} held before new work; retained task and queue evidence require review.";

            return false;
        }
        $capacityDeferred = ! ($result['success'] ?? false)
            && ($result['controller_capacity_wait_expired'] ?? false) === true;
        $tokensUsed = $this->resolveGenealogyQueueTokens($result, $sessionId);

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
                if ($this->isCountableGenealogyReviewToolCall($tc)) {
                    $reviewItemsCount++;
                }
            }
        }

        $parsedOutcome = $this->parseGenealogyOutcomeContract((string) ($result['response'] ?? ''));
        $outcomeState = $capacityDeferred ? 'requeue' : ($parsedOutcome['outcome_state']
            ?? ($result['success'] ? ($findingsCount > 0 ? 'completed' : 'deferred') : 'requeue'));
        $outcomeReason = $capacityDeferred ? 'Controller capacity remained unavailable before inference; retry when resources recover.' : ($parsedOutcome['outcome_reason']
            ?? ($result['success']
                ? ($findingsCount > 0 ? 'Evidence-backed findings generated.' : 'No supported change found; preserve for future follow-up.')
                : ($result['error'] ?? 'Agent execution failed.')));

        if ($result['success'] && $outcomeState === 'completed' && $findingsCount === 0 && $reviewItemsCount === 0) {
            $outcomeState = 'deferred';
            $outcomeReason = 'No queued review row survived quality/actionability gates; preserve for future follow-up.';
        }

        if ($outcomeState === 'deferred') {
            $findingsCount = 0;
            $reviewItemsCount = 0;
        }

        $scopeReason = $parsedOutcome['scope_reason'] ?? 'none';
        $relatedPeopleUsed = $parsedOutcome['related_people_used'] ?? [];
        $sourcesChecked = $parsedOutcome['sources_checked'] ?? [];
        $evidenceSummary = $parsedOutcome['evidence_summary']
            ?? mb_substr(trim((string) ($result['response'] ?? '')), 0, 500);
        $conflictsFound = $parsedOutcome['conflicts_found'] ?? 'none';

        $queueStatus = match (true) {
            $capacityDeferred => 'pending',
            ! $result['success'] => 'failed',
            $outcomeState === 'requeue' => 'pending',
            default => 'completed',
        };

        $taskUpdateParams = [
            $result['success'] ? 'completed' : 'failed',
            json_encode([
                'tool_calls_count' => count($result['tool_calls'] ?? []),
                'findings_count' => $findingsCount,
                'review_items_count' => $reviewItemsCount,
                'duration_ms' => $result['duration_ms'] ?? null,
                'tokens_used' => $tokensUsed,
                'capacity_deferred' => $capacityDeferred,
                ...(! $result['success'] && ! $capacityDeferred ? ['failure_diagnosis' => $this->diagnoseGenealogyQueueFailure($result)] : []),
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
            $item->id,
            $item->tree_id,
            $item->id,
            $item->tree_id,
            $sessionId,
        ];

        if ($queueStatus === 'pending') {
            $queueUpdateSql = '
                UPDATE genealogy_research_queue
                SET status = ?, started_at = NULL, completed_at = NULL, session_id = NULL,
                    findings_count = ?, review_items_count = ?, last_task_id = ?,
                    last_outcome_state = ?, last_outcome_reason = ?, notes = ?, updated_at = NOW()
                WHERE id = ? AND status = \'in_progress\' AND session_id = ?
            ';
            $queueUpdateParams = [
                $queueStatus,
                $findingsCount,
                $reviewItemsCount,
                $taskId,
                $outcomeState,
                $outcomeReason,
                'Requeued: '.($outcomeReason ?: 'retry requested'),
                $item->id,
                $sessionId,
            ];
        } else {
            $queueUpdateSql = '
                UPDATE genealogy_research_queue
                SET status = ?, completed_at = NOW(), findings_count = ?, review_items_count = ?,
                    last_task_id = ?, last_outcome_state = ?, last_outcome_reason = ?, notes = ?, updated_at = NOW()
                WHERE id = ? AND status = \'in_progress\' AND session_id = ?
            ';
            $queueUpdateParams = [
                $queueStatus,
                $findingsCount,
                $reviewItemsCount,
                $taskId,
                $outcomeState,
                $outcomeReason,
                $result['success']
                    ? 'Completed in '.($result['duration_ms'] ?? 0).'ms. Tokens: '.$tokensUsed
                    : 'Failed: '.($result['error'] ?? 'unknown'),
                $item->id,
                $sessionId,
            ];
        }

        try {
            DB::transaction(function () use ($taskUpdateParams, $queueUpdateSql, $queueUpdateParams): void {
                $taskUpdated = DB::update('
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
                              AND status = \'processing\'
                              AND queue_item_id = ?
                              AND tree_id = ?
                              AND EXISTS (
                                  SELECT 1 FROM genealogy_research_queue q
                                  WHERE q.id = ? AND q.tree_id = ?
                                    AND q.status = \'in_progress\' AND q.session_id = ?
                              )
                        ', $taskUpdateParams);
                if ($taskUpdated !== 1) {
                    throw new \RuntimeException('genealogy_queue_attempt_lost');
                }

                if (DB::update($queueUpdateSql, $queueUpdateParams) !== 1) {
                    throw new \RuntimeException('genealogy_queue_attempt_lost');
                }
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'genealogy_queue_attempt_lost') {
                throw $exception;
            }

            $output = "Research queue attempt for #{$item->id} lost ownership; result was not finalized.";
            Log::info('ScheduledJobService: discarded genealogy queue result after ownership changed', [
                'queue_id' => $item->id,
                'task_id' => $taskId,
            ]);

            return false;
        }

        if (! $capacityDeferred) {
            $this->markGenealogyPersonAgentWorked($treeId, $personId);
        }

        if ($result['success']) {
            $this->recordGenealogyQueueSearchCoverage(
                $item,
                $taskId,
                $sourcesChecked,
                $outcomeState,
                $outcomeReason,
                $evidenceSummary,
                $findingsCount,
                $reviewItemsCount,
                $result
            );
        }

        $output = "Research queue ({$skillName}): {$personName} (#{$personId}, tree {$treeId}) — "
            .($queueStatus === 'pending' ? 'REQUEUED' : ($result['success'] ? 'completed' : 'FAILED'))
            ." — {$findingsCount} findings, {$reviewItemsCount} reviews"
            ." — outcome {$outcomeState}";

        return $result['success'] || $capacityDeferred;
    }

    /** Diagnosis never changes execution status, admission, retries, or canonical data. */
    private function diagnoseGenealogyQueueFailure(array $result): array
    {
        try {
            $policy = app(SystemConfigService::class)->get(TypeSafeJevDecisionService::POLICY_KEY, []);
            $policy = is_string($policy) ? json_decode($policy, true, flags: JSON_THROW_ON_ERROR) : $policy;
            $task = $policy['tasks']['pipeline_issue_triage'] ?? [];
            $diagnosis = $task['failure_diagnosis'] ?? [];
            if (($diagnosis['enabled'] ?? false) !== true) {
                return ['status' => 'skipped', 'reason_code' => 'failure_diagnosis_disabled'];
            }

            $error = (string) ($result['error'] ?? '');
            $known = $diagnosis['deterministic_errors'][$error] ?? null;
            if (is_array($known) && is_string($known['category'] ?? null) && is_string($known['diagnostic_path'] ?? null)) {
                return ['status' => 'classified', 'method' => 'deterministic',
                    'category' => $known['category'], 'diagnostic_path' => $known['diagnostic_path']];
            }
            if (($policy['enabled'] ?? false) !== true || ($task['enabled'] ?? false) !== true) {
                return ['status' => 'skipped', 'reason_code' => 'jev_task_disabled'];
            }
            $tool = DB::selectOne('SELECT enabled, service_class, method, requires_confirmation FROM agent_tool_registry WHERE name = ?', ['typesafe_jev_decide']);
            if ($tool === null || ! in_array($tool->enabled, [true, 1, '1'], true)
                || ! in_array($tool->requires_confirmation, [false, 0, '0'], true)
                || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate') {
                return ['status' => 'skipped', 'reason_code' => 'jev_tool_unavailable'];
            }

            $questions = $diagnosis['questions'] ?? null;
            $cooldown = $diagnosis['cooldown_seconds'] ?? null;
            $patterns = $diagnosis['signal_patterns'] ?? null;
            if (! is_array($questions) || $questions === [] || ! is_int($cooldown) || $cooldown < 1
                || $cooldown > 86400 || ! is_array($patterns)) {
                return ['status' => 'skipped', 'reason_code' => 'failure_diagnosis_policy_invalid'];
            }
            // Only table-defined signal names leave this process; never copy raw error text or task context.
            $signals = [];
            foreach ($patterns as $name => $pattern) {
                if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $name) !== 1 || ! is_string($pattern)) {
                    return ['status' => 'skipped', 'reason_code' => 'failure_diagnosis_policy_invalid'];
                }
                $matched = @preg_match($pattern, $error);
                if ($matched === false) {
                    return ['status' => 'skipped', 'reason_code' => 'failure_diagnosis_policy_invalid'];
                }
                $signals[$name] = $matched === 1;
            }
            $state = ['workload' => 'genealogy_research_queue', 'execution_failed' => true,
                'tools_executed' => count($result['tool_calls'] ?? []) > 0, 'signals' => $signals];
            $key = 'genealogy_failure_diagnosis:'.hash('sha256', json_encode([$state, $questions], JSON_THROW_ON_ERROR));
            if (! Cache::add($key, true, $cooldown)) {
                return ['status' => 'skipped', 'reason_code' => 'duplicate_symptom_cooldown'];
            }
            $judgment = app(TypeSafeJevDecisionService::class)->evaluate('pipeline_issue_triage', 'sanitized_internal', $state, $questions);

            return array_intersect_key($judgment, array_flip(['status', 'reason_code', 'route_trace_id', 'answers', 'usage', 'duration_ms']));
        } catch (\Throwable) {
            // Optional diagnostics must not mask the failure or prevent its durable queue/task record.
            return ['status' => 'skipped', 'reason_code' => 'failure_diagnosis_unavailable'];
        }
    }

    private function isCountableGenealogyReviewToolCall(array $toolCall): bool
    {
        if (($toolCall['tool'] ?? '') !== 'submit_for_review' || empty($toolCall['success'])) {
            return false;
        }

        if (! empty($toolCall['skipped'])) {
            return false;
        }

        if (! empty($toolCall['merged_into'])) {
            return true;
        }

        $status = strtolower(trim((string) ($toolCall['status'] ?? '')));
        if (! empty($toolCall['review_created'])) {
            return $status === '' || $status === 'pending';
        }

        if ($status !== '') {
            return $status === 'pending'
                && (isset($toolCall['review_id']) || isset($toolCall['token']) || ! empty($toolCall['deduplicated']));
        }

        return false;
    }

    /**
     * Local/private model routes do not always return usage in the agent result,
     * but AgentLoopService still records session token totals.
     */
    private function resolveGenealogyQueueTokens(array $result, string $sessionId): int
    {
        $reported = $result['tokens_used'] ?? null;
        if (is_numeric($reported) && (int) $reported > 0) {
            return (int) $reported;
        }

        try {
            $row = DB::selectOne(
                'SELECT total_tokens FROM agent_sessions WHERE session_id = ? LIMIT 1',
                [$sessionId]
            );

            return max(0, (int) ($row->total_tokens ?? 0));
        } catch (\Throwable $e) {
            Log::debug('ScheduledJobService: Could not resolve genealogy queue session tokens', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return max(0, (int) ($reported ?? 0));
        }
    }

    private function recordGenealogyQueueSearchCoverage(
        object $item,
        int $queueTaskId,
        array $sourcesChecked,
        string $outcomeState,
        string $outcomeReason,
        string $evidenceSummary,
        int $findingsCount,
        int $reviewItemsCount,
        array $result
    ): void {
        $treeId = (int) $item->tree_id;
        $personId = (int) $item->person_id;
        $question = trim((string) ($item->research_question ?? ''));
        $sourcesChecked = array_values(array_filter(array_map(
            static fn ($source) => trim((string) $source),
            $sourcesChecked
        )));
        $negativeResult = $outcomeState === 'deferred';
        $durationMinutes = isset($result['duration_ms'])
            ? max(1, (int) ceil(((int) $result['duration_ms']) / 60000))
            : null;
        $gpsStatus = match ($outcomeState) {
            'completed' => 'resolved',
            'requeue' => 'in_progress',
            default => 'inconclusive',
        };

        try {
            DB::insert('
                INSERT INTO gps_research_tasks
                (person_id, tree_id, task_type, question, hypothesis, status, priority,
                 conclusion, evidence_summary, resolved_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ', [
                $personId,
                $treeId,
                $this->mapGenealogyQueueQuestionToGpsTaskType(
                    (string) ($item->question_type ?? ''),
                    $question
                ),
                $question !== '' ? $question : 'Queue-mode genealogy research run.',
                'Mirrored from genealogy_research_tasks.id='.$queueTaskId,
                $gpsStatus,
                'high',
                $outcomeReason,
                json_encode([
                    'queue_task_id' => $queueTaskId,
                    'queue_item_id' => (int) $item->id,
                    'outcome_state' => $outcomeState,
                    'outcome_reason' => $outcomeReason,
                    'sources_checked' => $sourcesChecked,
                    'findings_count' => $findingsCount,
                    'review_items_count' => $reviewItemsCount,
                ]),
                $gpsStatus === 'in_progress' ? null : now()->toDateTimeString(),
            ]);

            $gpsTaskId = (int) DB::getPdo()->lastInsertId();

            DB::insert('
                INSERT INTO gps_research_logs
                (task_id, person_id, log_type, repository_searched, repository_url,
                 search_terms, date_range_searched, location_searched, record_types_searched,
                 results_summary, negative_result, source_ids_found, media_ids_found,
                 search_duration_minutes, notes, searched_at, created_at)
                VALUES (?, ?, \'search\', ?, NULL, ?, NULL, NULL, ?, ?, ?, NULL, NULL, ?, ?, NOW(), NOW())
            ', [
                $gpsTaskId,
                $personId,
                $sourcesChecked
                    ? Str::limit(implode(', ', $sourcesChecked), 255, '')
                    : 'queue-mode genealogy research',
                Str::limit(trim(((string) ($item->person_name ?? 'Unknown person')).' '.$question), 1000, ''),
                $sourcesChecked ? json_encode($sourcesChecked) : null,
                $evidenceSummary,
                $negativeResult ? 1 : 0,
                $durationMinutes,
                'Mirrored from queue research task '.$queueTaskId.'; outcome='.$outcomeState,
            ]);

            DB::update('
                UPDATE genealogy_person_coverage
                SET research_exhaustion_score = ROUND(
                        LEAST(
                            (COALESCE(negative_count_30d, 0) + ?)
                            / GREATEST(COALESCE(search_count_30d, 0) + 1, 1),
                            1
                        ),
                        3
                    ),
                    last_searched_at = NOW(),
                    search_count_30d = LEAST(COALESCE(search_count_30d, 0) + 1, 65535),
                    negative_count_30d = LEAST(COALESCE(negative_count_30d, 0) + ?, 65535),
                    coverage_updated_at = NOW(),
                    updated_at = NOW()
                WHERE tree_id = ? AND person_id = ?
            ', [
                $negativeResult ? 1 : 0,
                $negativeResult ? 1 : 0,
                $treeId,
                $personId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ScheduledJobService: Failed to mirror genealogy queue research into GPS coverage', [
                'queue_item_id' => (int) $item->id,
                'queue_task_id' => $queueTaskId,
                'tree_id' => $treeId,
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markGenealogyPersonAgentWorked(int $treeId, int $personId): void
    {
        app(GenealogyService::class)->markPersonAgentWorked($treeId, $personId);
    }

    private function mapGenealogyQueueQuestionToGpsTaskType(string $questionType, string $question): string
    {
        $haystack = strtolower($questionType.' '.$question);

        return match (true) {
            str_contains($haystack, 'birth') => 'birth',
            str_contains($haystack, 'death') || str_contains($haystack, 'probate') => 'death',
            str_contains($haystack, 'marriage') || str_contains($haystack, 'spouse') => 'marriage',
            str_contains($haystack, 'parent') || str_contains($haystack, 'child') => 'parentage',
            str_contains($haystack, 'identity') || str_contains($haystack, 'verify') => 'identity',
            str_contains($haystack, 'place') || str_contains($haystack, 'location') => 'location',
            str_contains($haystack, 'occupation') => 'occupation',
            str_contains($haystack, 'migration') || str_contains($haystack, 'immigration') => 'migration',
            str_contains($haystack, 'military') => 'military',
            default => 'other',
        };
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
        $playbookService = app(GenealogyResearchPlaybookService::class);
        $playbook = $playbookService->chooseForQueueCandidate($person, $questionType, $researchQuestion);
        if (! empty($playbook['enabled']) && ! empty($playbook['key'])) {
            $selectionParts[] = 'playbook='.$playbook['key'];
            $researchQuestion = $playbookService->appendGuidance($researchQuestion, $playbook);
        }

        return [
            'question_type' => $questionType,
            'research_question' => $researchQuestion,
            'selection_reason' => implode(', ', $selectionParts),
            'priority_reason' => "tier={$tier}, gap={$gap}, exhaustion={$exhaustion}, priority={$priorityScore}",
            'research_playbook' => $playbookService->contextPayload($playbook),
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
                .'PHASE 1 ASSESS: get_source_metrics, recall_procedures, recall_episodes, agent_session_search, list_trees, '
                .'get_research_landscape, get_recent_searches, get_tree_statistics, get_missing_data_report, '
                ."get_research_hints, get_open_research_tasks. Select priority persons by bloodline tier.\n"
                .'PHASE 2 RESEARCH: For each person — get_repositories_for_person, surname_phonetic_matches, '
                .'then source_search_all + at least 3 targeted tools (wikitree_search, nara_search, '
                ."newspaper_search, ellis_island_search, dar_search, etc.). Log coverage with update_search_coverage.\n"
                .'When queue-mode search planning is available, let the LLM propose a bounded per-person plan using vetted public/API sources, then execute only validated tools/params. '
                .'Treat source_search_all/LOC/NARA same-name hits as leads only unless the result text supplies an identity bridge beyond the query string. '
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
    public function getJobHistory(int $jobId, int $limit): array
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
     * Observe running workers whose local PID is absent or uncertain.
     * Parent PID end does not prove descendants or remote work ended, so preserve
     * running state, all custody, deadlines and research claims until owner completion.
     *
     * @return array<int,array{id:int,name:string,pid:int,run_id:int}> No unproven recovery receipts.
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

        $heldCount = 0;
        foreach ($candidates as $candidate) {
            try {
                if ($this->isProcessAlive((int) $candidate->pid)) {
                    continue;
                }
            } catch (\Throwable) {
                // An unavailable PID probe retains every hold too.
            }
            $heldCount++;
        }
        if ($heldCount > 0) {
            Log::notice('ScheduledJobService: Missing-parent work custody retained', [
                'held_count' => $heldCount,
                'reason_code' => 'scheduled_job_work_end_unproven',
            ]);
        }

        return [];
    }

    public function fixStuckJobs(): int
    {
        // Timeout candidates are observations only; preserve physical and queue custody.
        $stuckJobs = DB::select("
            SELECT id, name, command, cron_expression, last_run_at, timeout_minutes, last_pid, job_type, notes
            FROM scheduled_jobs
            WHERE last_run_status = 'running'
              AND COALESCE(stall_exempt, 0) = 0
              AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes + 15, 120) MINUTE)
              AND (last_completed_at IS NULL OR last_completed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
        ");

        $heldCount = 0;
        foreach ($stuckJobs as $job) {
            if (! $this->shouldAutoFixTimedOutJob($job)) {
                continue;
            }
            // Age, missing/recycled PID and kill acceptance cannot prove all work ended.
            // Normal owner-driven markJobCompleted retains terminal publication authority.
            $heldCount++;
        }
        if ($heldCount > 0) {
            Log::notice('ScheduledJobService: Timed-out work custody retained', [
                'held_count' => $heldCount,
                'reason_code' => 'scheduled_job_work_end_unproven',
            ]);
        }

        return 0;
    }

    /**
     * Check for jobs with consecutive failures and send escalating alerts.
     * Thresholds: 3 = warning, 5 = high priority, 10 = emergency.
     */
    public function checkConsecutiveFailures(): void
    {
        $failingJobs = DB::select("
            SELECT sj.id, sj.name, sj.fail_count, COUNT(f.id) AS consecutive_failures
            FROM scheduled_jobs sj
            LEFT JOIN (
                SELECT r.scheduled_job_id, MAX(r.started_at) AS last_success
                FROM scheduled_job_runs r
                JOIN scheduled_jobs eligible ON eligible.id = r.scheduled_job_id
                WHERE r.status = 'success'
                  AND eligible.enabled = 1
                  AND eligible.last_run_status = 'failed'
                GROUP BY r.scheduled_job_id
            ) s ON s.scheduled_job_id = sj.id
            LEFT JOIN scheduled_job_runs f
                ON f.scheduled_job_id = sj.id
               AND f.status = 'failed'
               AND f.started_at > COALESCE(s.last_success, '2000-01-01')
            WHERE sj.enabled = 1
              AND sj.last_run_status = 'failed'
            GROUP BY sj.id, sj.name, sj.fail_count
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
    public function cleanupHistory(int $daysToKeep): int
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
            Process::timeout(5)->run(['kill', '-15', (string) $pid]);
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
                Process::timeout(5)->run(['kill', '-9', (string) $pid]);
            }
            Log::warning("ScheduledJobService: Force-killed PID {$pid} after {$grace}s grace period");
        }

        return ! $this->isProcessAlive($pid);
    }

    /**
     * Observe uncertain process tracking without clearing physical work custody.
     * Called on scheduler ticks; PID absence and historical status are not completion proof.
     */
    private function cleanupDeadProcesses(): void
    {
        $nullPidRuns = DB::select("
            SELECT id, scheduled_job_id
            FROM scheduled_job_runs
            WHERE status = 'running' AND pid IS NULL AND started_at < NOW() - INTERVAL 5 MINUTE
        ");
        $staleRuns = DB::select("
            SELECT id, scheduled_job_id, pid FROM scheduled_job_runs
            WHERE status = 'running' AND pid IS NOT NULL AND started_at < NOW() - INTERVAL 10 MINUTE
        ");
        $runningJobs = DB::select("
            SELECT id, name, cron_expression, last_pid, max_parallel, running_pids
            FROM scheduled_jobs
            WHERE (last_run_status = 'running' AND (last_pid IS NOT NULL OR running_pids IS NOT NULL))
               OR (running_pids IS NOT NULL AND JSON_LENGTH(running_pids) > 0)
        ");

        $held = $nullPidRuns !== [];
        foreach ($staleRuns as $run) {
            try {
                $held = ! $this->isProcessAlive((int) $run->pid) || $held;
            } catch (\Throwable) {
                $held = true;
            }
        }
        foreach ($runningJobs as $job) {
            $pids = json_decode($job->running_pids ?? '[]', true);
            if (! is_array($pids)) {
                $held = true;

                continue;
            }
            if ($job->last_pid !== null) {
                $pids[] = $job->last_pid;
            }
            foreach ($pids as $pid) {
                try {
                    $held = ! $this->isProcessAlive((int) $pid) || $held;
                } catch (\Throwable) {
                    $held = true;
                }
            }
        }
        if ($held) {
            Log::notice('ScheduledJobService: Cleanup work custody retained', [
                'reason_code' => 'scheduled_job_work_end_unproven',
            ]);
        }
        // No kill, claim release, deadline removal, state repair or CPU-stall recovery.
        // Only normal owner completion publishes terminal status.
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

    private function releaseQueueResearchItemsForJob(object $job, string $reason, ?int $runId = null, ?int $pid = null): void
    {
        if (! $this->isQueueResearchJob($job)) {
            return;
        }

        $where = ['status = \'in_progress\''];
        $filterParams = [];
        $sessionLikes = self::genealogyQueueSessionLikePatterns($runId, $pid);

        if ($sessionLikes !== []) {
            $where[] = '('.implode(' OR ', array_fill(0, count($sessionLikes), 'session_id LIKE ?')).')';
            array_push($filterParams, ...$sessionLikes);
        } elseif ((int) ($job->max_parallel ?? 1) > 1) {
            Log::warning('ScheduledJobService: skipped broad queue release for parallel genealogy worker without owner identity', [
                'job_id' => $job->id ?? null,
                'job_name' => $job->name ?? null,
                'reason' => $reason,
            ]);

            return;
        }

        try {
            $workedRows = DB::select('
                SELECT DISTINCT tree_id, person_id
                FROM genealogy_research_queue
                WHERE '.implode(' AND ', $where).'
            ', $filterParams);

            foreach ($workedRows as $workedRow) {
                $this->markGenealogyPersonAgentWorked((int) $workedRow->tree_id, (int) $workedRow->person_id);
            }
        } catch (\Throwable $e) {
            Log::debug('ScheduledJobService: Could not mark released genealogy queue people as worked', [
                'job_id' => $job->id ?? null,
                'job_name' => $job->name ?? null,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        $params = array_merge([$reason], $filterParams);

        DB::update("
            UPDATE genealogy_research_queue
            SET status = 'failed',
                completed_at = NOW(),
                last_outcome_state = 'needs_human_review',
                last_outcome_reason = ?,
                notes = CONCAT(COALESCE(notes, ''), ' [queue-worker-released]'),
                updated_at = NOW()
            WHERE ".implode(' AND ', $where).'
        ', $params);
    }

    /**
     * Queue session IDs are stored in genealogy_research_queue.session_id (varchar 36).
     * Keep run/PID ownership tokens compact so timeout cleanup can release only the
     * affected worker's item without overflowing the queue table.
     */
    private static function buildGenealogyQueueSessionId(int $runId, int $pid, int $queueId): string
    {
        $runToken = self::compactGenealogyQueueSessionNumber($runId);
        $pidToken = self::compactGenealogyQueueSessionNumber($pid);
        $queueToken = self::compactGenealogyQueueSessionNumber($queueId);
        $nonce = substr(hash('sha256', implode('|', [$runId, $pid, $queueId, uniqid('', true)])), 0, 8);

        return "gq-r{$runToken}-p{$pidToken}-q{$queueToken}-{$nonce}";
    }

    public static function genealogyQueueSessionLikePatterns(?int $runId = null, ?int $pid = null): array
    {
        if ($runId !== null && $runId > 0) {
            return [
                self::genealogyQueueSessionLikeForRun($runId),
                "genealogy-research-run{$runId}-%",
            ];
        }

        if ($pid !== null && $pid > 0) {
            return [
                self::genealogyQueueSessionLikeForPid($pid),
                "%-pid{$pid}-%",
            ];
        }

        return [];
    }

    public static function genealogyQueueSessionLikeForRun(int $runId): string
    {
        return 'gq-r'.self::compactGenealogyQueueSessionNumber($runId).'-%';
    }

    public static function genealogyQueueSessionLikeForPid(int $pid): string
    {
        return '%-p'.self::compactGenealogyQueueSessionNumber($pid).'-%';
    }

    private static function compactGenealogyQueueSessionNumber(int $value): string
    {
        $token = base_convert((string) max(0, $value), 10, 36);

        if (strlen($token) <= 6) {
            return $token;
        }

        return substr(hash('sha256', (string) $value), 0, 6);
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
     * Fully dynamic parallel worker resolution — computes optimal concurrency
     * from system state, workload, and resource availability.
     *
     * max_parallel column is the operator-owned safety cap.
     * The framework decides the actual worker count from:
     *   1. Table-owned job resource profile (CPU/GPU/IO/LLM-bound)
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

        $profileKey = $this->getJobResourceProfileKey($job);
        $profiles = $this->getJobResourceProfiles();
        $profile = $profiles[$profileKey]
            ?? $profiles['default']
            ?? $this->nonLimitingResourceProfile((int) $safetyCap);
        $hardCap = $this->getJobHardCap($job, $profile, (int) $safetyCap);
        $scalingPolicy = $this->getWorkerScalingPolicy();

        // ── Gather system state (cached for this tick) ──
        $system = $this->getSystemState();

        // ── Start at 1, earn additional workers ──
        $workers = 1;

        // ── CPU headroom → additional workers for CPU-bound jobs ──
        if ($profile['cpu_bound']) {
            $idleCores = max(0, $system['cores'] - ($system['load'] * $system['cores']));
            $coresPerWorker = $this->positivePolicyFloat($scalingPolicy, 'cpu_cores_per_worker');
            if ($coresPerWorker !== null) {
                $cpuWorkers = (int) floor($idleCores / $coresPerWorker);
                $workers = max($workers, min($cpuWorkers, $hardCap));
            }
        }

        // ── IO-bound jobs scale more freely (they wait on disk/network) ──
        if ($profile['io_bound']) {
            $ioTargetWorkers = $this->positivePolicyInt($scalingPolicy, 'io_target_workers');
            if ($ioTargetWorkers !== null) {
                $workers = max($workers, min($ioTargetWorkers, $hardCap));
            }

            $ioLowMemoryGb = $this->positivePolicyFloat($scalingPolicy, 'io_low_memory_gb');
            $ioLowMemoryWorkers = $this->positivePolicyInt($scalingPolicy, 'io_low_memory_workers');
            if ($ioLowMemoryGb !== null && $ioLowMemoryWorkers !== null && $system['memory_gb'] < $ioLowMemoryGb) {
                $workers = min($workers, $ioLowMemoryWorkers);
            }
        }

        // ── LLM-bound jobs scale with available providers ──
        if ($profile['llm_bound']) {
            $providerCapacity = $this->countHealthyLlmProviderCapacity($profileKey, $job);
            $workers = max($workers, min(max(1, $providerCapacity), $hardCap));
        }

        // ── GPU gate: GPU-bound jobs clamp when GPU is loaded ──
        if ($profile['gpu_bound']) {
            $gpuSingleWorkerPct = $this->nonNegativePolicyInt($scalingPolicy, 'gpu_single_worker_util_pct');
            $gpuLimitedWorkerPct = $this->nonNegativePolicyInt($scalingPolicy, 'gpu_limited_worker_util_pct');
            $gpuLimitedWorkers = $this->positivePolicyInt($scalingPolicy, 'gpu_limited_workers');
            if ($gpuSingleWorkerPct !== null && $system['gpu_util'] > $gpuSingleWorkerPct) {
                $workers = 1;
            } elseif ($gpuLimitedWorkerPct !== null && $gpuLimitedWorkers !== null && $system['gpu_util'] > $gpuLimitedWorkerPct) {
                $workers = min($workers, $gpuLimitedWorkers);
            }
        }

        // ── Hard gates: insufficient resources → clamp to 1 ──
        $loadRatioThreshold = $this->positivePolicyFloat($scalingPolicy, 'load_ratio_single_worker_threshold');
        if ($loadRatioThreshold !== null && $system['load_ratio'] > $loadRatioThreshold) {
            return 1;
        }
        $minMemoryGb = $this->positivePolicyFloat($scalingPolicy, 'min_memory_gb_single_worker');
        if ($minMemoryGb !== null && $system['memory_gb'] < $minMemoryGb) {
            return 1;
        }

        // ── Backlog scaling: scale down when nearly caught up ──
        $pending = $this->getJobPendingCount($job);
        $parallelMinBacklog = $this->getParallelMinBacklog($job);
        if ($pending < $parallelMinBacklog) {
            return 1; // Tail end — single worker finishes it
        }
        $largeBacklogThreshold = $this->positivePolicyInt($scalingPolicy, 'large_backlog_threshold');
        $largeBacklogBonusWorkers = $this->nonNegativePolicyInt($scalingPolicy, 'large_backlog_bonus_workers');
        if ($largeBacklogThreshold !== null && $largeBacklogBonusWorkers !== null && $pending > $largeBacklogThreshold) {
            $workers += $largeBacklogBonusWorkers;
        }
        $workers = min($workers, max(1, $pending));

        // ── Cross-job awareness: total running workers across all jobs ──
        try {
            $totalRunning = (int) (DB::selectOne("
                SELECT COALESCE(SUM(running_count), 0) as c FROM scheduled_jobs WHERE last_run_status = 'running'
            ")->c ?? 0);

            $systemWorkerLimitRatio = $this->positivePolicyFloat($scalingPolicy, 'total_running_core_ratio_limit');
            if ($systemWorkerLimitRatio !== null && $totalRunning >= ($system['cores'] * $systemWorkerLimitRatio)) {
                return 1;
            }

            $conservativeRatio = $this->positivePolicyFloat($scalingPolicy, 'total_running_core_ratio_conservative');
            $conservativeWorkers = $this->positivePolicyInt($scalingPolicy, 'total_running_conservative_workers');
            if ($conservativeRatio !== null && $conservativeWorkers !== null && $totalRunning > ($system['cores'] * $conservativeRatio)) {
                $workers = min($workers, $conservativeWorkers);
            }
        } catch (\Exception $e) {
            Log::debug('ScheduledJobService: system load check failed during worker calculation', ['error' => $e->getMessage()]);
        }

        // ── Configured off-hours boost: system is idle, push harder ──
        $hour = (int) now()->format('H');
        $offHoursStart = $this->hourPolicyInt($scalingPolicy, 'off_hours_start_hour');
        $offHoursEnd = $this->hourPolicyInt($scalingPolicy, 'off_hours_end_hour');
        $offHoursBonus = $this->nonNegativePolicyInt($scalingPolicy, 'off_hours_bonus_workers');
        if ($offHoursStart !== null && $offHoursEnd !== null && $offHoursBonus !== null && $this->isWithinConfiguredHourWindow($hour, $offHoursStart, $offHoursEnd)) {
            $workers += $offHoursBonus;
        }

        // ── Final clamp to safety cap ──
        return max(1, min($workers, $safetyCap, $hardCap));
    }

    private function getParallelMinBacklog(object $job): int
    {
        $configured = $this->getScheduledJobRuntimeValue($job, 'parallel_min_backlog');
        if (is_numeric($configured)) {
            return max(1, (int) $configured);
        }

        $backlogMetric = $this->getScheduledJobRuntimeValue($job, 'backlog_metric');
        $scalingPolicy = $this->getWorkerScalingPolicy();
        if ($backlogMetric === 'genealogy_queue' || $this->isQueueResearchJob($job)) {
            return $this->positivePolicyInt($scalingPolicy, 'genealogy_parallel_min_backlog') ?? 1;
        }

        return $this->positivePolicyInt($scalingPolicy, 'default_parallel_min_backlog') ?? 1;
    }

    /**
     * Return the workload hard cap, allowing scheduled job runtime metadata to
     * raise or lower a job-specific ceiling while max_parallel stays the final
     * operator-owned safety cap.
     *
     * @param  array<string, bool|int>  $profile
     */
    private function getJobHardCap(object $job, array $profile, int $safetyCap): int
    {
        $configured = $this->getScheduledJobRuntimeValue($job, 'hard_cap');
        if (is_numeric($configured)) {
            return max(1, min((int) $configured, max(1, $safetyCap)));
        }

        if (isset($profile['hard_cap']) && is_numeric($profile['hard_cap']) && (int) $profile['hard_cap'] > 0) {
            return max(1, min((int) $profile['hard_cap'], max(1, $safetyCap)));
        }

        return max(1, $safetyCap);
    }

    /**
     * Count healthy LLM providers (active, healthy, circuit not open).
     * Cached 60s to avoid repeated DB queries within a scheduler tick.
     */
    private function countHealthyLlmProviderCapacity(string $resourceProfile = 'default', ?object $job = null): int
    {
        $capacityModelRoles = $job ? $this->getScheduledJobRuntimeList($job, 'capacity_model_roles') : [];
        $errorDefault = $this->nonNegativePolicyInt($this->getWorkerScalingPolicy(), 'provider_capacity_error_default') ?? 0;
        $roleCacheSuffix = $capacityModelRoles === []
            ? ''
            : ':roles:'.sha1(json_encode($capacityModelRoles, JSON_UNESCAPED_SLASHES));

        return Cache::remember("healthy_llm_provider_capacity:{$resourceProfile}{$roleCacheSuffix}", 60, function () use ($resourceProfile, $capacityModelRoles, $errorDefault) {
            try {
                if ($resourceProfile === 'genealogy_llm') {
                    $roleWhere = '';
                    $bindings = [];

                    if ($capacityModelRoles !== []) {
                        $rolePredicates = [];
                        foreach ($capacityModelRoles as $role) {
                            $rolePredicates[] = 'JSON_EXTRACT(COALESCE(config, JSON_OBJECT()), ?) IS NOT NULL';
                            $bindings[] = '$.models.'.$role;
                        }

                        $roleWhere = ' AND ('.implode(' OR ', $rolePredicates).')';
                    }

                    $row = DB::selectOne("
                        SELECT COALESCE(SUM(GREATEST(COALESCE(max_concurrent, 1), 1)), 0) AS c
                        FROM llm_instances
                        WHERE is_active = 1
                          AND is_healthy = 1
                          AND circuit_state != 'open'
                          AND COALESCE(routability, 'allowed') = 'allowed'
                          AND COALESCE(allows_private_data, 0) = 1
                          AND COALESCE(data_privacy_scope, 'private_allowed') IN ('local_private', 'private_allowed')
                          AND JSON_CONTAINS(COALESCE(capabilities, JSON_ARRAY()), JSON_QUOTE('text')) = 1
                          AND JSON_CONTAINS(COALESCE(capabilities, JSON_ARRAY()), JSON_QUOTE('privacy_deny_prefilter')) = 0
                          {$roleWhere}
                    ", $bindings);

                    return (int) ($row->c ?? 0);
                }

                $row = DB::selectOne("
                    SELECT COALESCE(SUM(GREATEST(COALESCE(max_concurrent, 1), 1)), 0) AS c
                    FROM llm_instances
                    WHERE is_active = 1 AND is_healthy = 1 AND circuit_state != 'open'
                ");

                return (int) ($row->c ?? 0);
            } catch (\Exception $e) {
                Log::debug('ScheduledJobService: healthy LLM provider capacity query failed', [
                    'resource_profile' => $resourceProfile,
                    'error' => $e->getMessage(),
                ]);

                return $errorDefault;
            }
        });
    }

    /**
     * Determine resource profile from job command string.
     */
    private function getJobResourceProfile(object $job): array
    {
        $profiles = $this->getJobResourceProfiles();
        $key = $this->getJobResourceProfileKey($job);

        return $profiles[$key]
            ?? $profiles['default']
            ?? $this->nonLimitingResourceProfile((int) ($job->max_parallel ?? 1));
    }

    /**
     * Determine resource profile key from typed runtime metadata or command string.
     */
    private function getJobResourceProfileKey(object $job): string
    {
        $profiles = $this->getJobResourceProfiles();
        $resourceProfile = $this->getScheduledJobRuntimeValue($job, 'resource_profile');
        if (is_string($resourceProfile) && isset($profiles[$resourceProfile])) {
            return $resourceProfile;
        }

        $cmd = $job->command ?? '';

        // Match longest keys first to avoid substring collisions (e.g. 'ai' in 'thumbnails')
        $keys = array_keys($profiles);
        usort($keys, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $key) {
            if ($key !== 'default' && str_contains($cmd, $key)) {
                return $key;
            }
        }

        // Check for common patterns
        if (str_contains($cmd, 'rag-sync') || str_contains($cmd, 'file-catalog')) {
            return 'rag';
        }

        return 'default';
    }

    /**
     * Resource profiles are table-owned at runtime.
     *
     * @return array<string,array<string,mixed>>
     */
    private function getJobResourceProfiles(): array
    {
        return Cache::remember('scheduler_resource_profiles', 60, function (): array {
            try {
                $row = DB::selectOne(
                    "SELECT config_value
                     FROM system_configs
                     WHERE section = 'scheduler'
                       AND config_key = 'resource_profiles'
                     LIMIT 1"
                );

                $decoded = json_decode((string) ($row->config_value ?? ''), true);
                if (! is_array($decoded) || $decoded === []) {
                    return [];
                }

                $profiles = [];
                foreach ($decoded as $key => $profile) {
                    if (! is_string($key) || trim($key) === '' || ! is_array($profile)) {
                        continue;
                    }

                    $profiles[$key] = $this->normalizeResourceProfile($profile);
                }

                return $profiles;
            } catch (\Throwable $e) {
                Log::debug('ScheduledJobService: scheduler resource profile config unavailable', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        });
    }

    /**
     * Scheduler scaling thresholds are table-owned at runtime.
     *
     * @return array<string,int|float>
     */
    private function getWorkerScalingPolicy(): array
    {
        return Cache::remember('scheduler_worker_scaling_policy', 60, function (): array {
            try {
                $row = DB::selectOne(
                    "SELECT config_value
                     FROM system_configs
                     WHERE section = 'scheduler'
                       AND config_key = 'worker_scaling_policy'
                     LIMIT 1"
                );

                $decoded = json_decode((string) ($row->config_value ?? ''), true);
                if (! is_array($decoded)) {
                    return [];
                }

                $policy = [];
                foreach ($decoded as $key => $value) {
                    if (! is_string($key) || ! is_numeric($value)) {
                        continue;
                    }

                    $policy[$key] = str_contains((string) $key, 'ratio')
                        || str_contains((string) $key, 'gb')
                        || str_contains((string) $key, 'cores')
                            ? (float) $value
                            : (int) $value;
                }

                return $policy;
            } catch (\Throwable $e) {
                Log::debug('ScheduledJobService: scheduler worker scaling config unavailable', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        });
    }

    private function isWithinConfiguredHourWindow(int $hour, int $startHour, int $endHour): bool
    {
        $hour = max(0, min(23, $hour));
        $startHour = max(0, min(23, $startHour));
        $endHour = max(0, min(23, $endHour));

        if ($startHour === $endHour) {
            return true;
        }

        if ($startHour < $endHour) {
            return $hour >= $startHour && $hour <= $endHour;
        }

        return $hour >= $startHour || $hour <= $endHour;
    }

    /**
     * @return array{hard_cap:int,gpu_bound:bool,cpu_bound:bool,io_bound:bool,llm_bound:bool}
     */
    private function nonLimitingResourceProfile(int $safetyCap): array
    {
        return [
            'hard_cap' => max(1, $safetyCap),
            'gpu_bound' => false,
            'cpu_bound' => false,
            'io_bound' => false,
            'llm_bound' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function normalizeResourceProfile(array $profile): array
    {
        $normalized = [
            'hard_cap' => null,
            'gpu_bound' => false,
            'cpu_bound' => false,
            'io_bound' => false,
            'llm_bound' => false,
        ];

        if (isset($profile['hard_cap']) && is_numeric($profile['hard_cap'])) {
            $normalized['hard_cap'] = max(1, (int) $profile['hard_cap']);
        }

        foreach (['gpu_bound', 'cpu_bound', 'io_bound', 'llm_bound'] as $key) {
            if (array_key_exists($key, $profile)) {
                $normalized[$key] = filter_var($profile[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? (bool) $profile[$key];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,int|float>  $policy
     */
    private function positivePolicyInt(array $policy, string $key): ?int
    {
        if (! isset($policy[$key]) || ! is_numeric($policy[$key])) {
            return null;
        }

        $value = (int) $policy[$key];

        return $value > 0 ? $value : null;
    }

    /**
     * @param  array<string,int|float>  $policy
     */
    private function nonNegativePolicyInt(array $policy, string $key): ?int
    {
        if (! isset($policy[$key]) || ! is_numeric($policy[$key])) {
            return null;
        }

        return max(0, (int) $policy[$key]);
    }

    /**
     * @param  array<string,int|float>  $policy
     */
    private function hourPolicyInt(array $policy, string $key): ?int
    {
        if (! isset($policy[$key]) || ! is_numeric($policy[$key])) {
            return null;
        }

        $value = (int) $policy[$key];

        return $value >= 0 && $value <= 23 ? $value : null;
    }

    /**
     * @param  array<string,int|float>  $policy
     */
    private function positivePolicyFloat(array $policy, string $key): ?float
    {
        if (! isset($policy[$key]) || ! is_numeric($policy[$key])) {
            return null;
        }

        $value = (float) $policy[$key];

        return $value > 0 ? $value : null;
    }

    /**
     * Get pending count specific to a job's enrichment type.
     */
    private function getJobPendingCount(object $job): int
    {
        $backlogMetric = $this->getScheduledJobRuntimeValue($job, 'backlog_metric');
        $cmd = $job->command ?? '';
        $imageExts = "'jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif'";
        $available = $this->availableFileRegistryPredicate();
        $availableFr = $this->availableFileRegistryPredicate('fr');

        try {
            if ($backlogMetric === 'faces' || str_contains($cmd, 'faces')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND face_scan_at IS NULL AND extension IN ({$imageExts})")->c ?? 0);
            }
            if ($backlogMetric === 'ai' || str_contains($cmd, 'type=ai') || str_contains($cmd, 'ai')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND ai_analyzed_at IS NULL AND extension IN ({$imageExts})")->c ?? 0);
            }
            if ($backlogMetric === 'rag' || str_contains($cmd, 'rag') || str_contains($cmd, 'file-catalog')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND rag_indexed_at IS NULL")->c ?? 0);
            }
            if ($backlogMetric === 'phash' || str_contains($cmd, 'phash')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry fr WHERE status = 'active' AND {$availableFr} AND extension IN ({$imageExts}) AND NOT EXISTS (SELECT 1 FROM file_registry_perceptual_hashes ph WHERE ph.file_registry_id = fr.id)")->c ?? 0);
            }
            if ($backlogMetric === 'thumbnail' || str_contains($cmd, 'thumbnail')) {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND thumbnail_generated_at IS NULL AND thumbnail_error IS NULL")->c ?? 0);
            }
            if ($backlogMetric === 'genealogy_queue') {
                return (int) (DB::selectOne("SELECT COUNT(*) as c FROM genealogy_research_queue WHERE status = 'pending'")->c ?? 0);
            }
        } catch (\Exception $e) {
            Log::debug('ScheduledJobService: backlog estimation query failed', ['error' => $e->getMessage()]);
        }

        return 1000; // Unknown job — assume moderate backlog
    }

    private function availableFileRegistryPredicate(string $alias = 'file_registry'): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        $error = "LOWER(COALESCE({$prefix}thumbnail_error, ''))";

        return "COALESCE({$prefix}verification_failures, 0) = 0
            AND {$error} NOT LIKE '%404%'
            AND {$error} NOT LIKE '%not found%'
            AND {$error} NOT LIKE '%no such file%'
            AND {$error} NOT LIKE '%failed to download source file%'";
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
     * @return array<int,string>
     */
    private function getScheduledJobRuntimeList(object $job, string $key): array
    {
        $notes = $job->notes ?? null;
        if (! is_string($notes) || trim($notes) === '') {
            return [];
        }

        $decoded = $this->decodeJobParameters($notes);
        if ($decoded === []) {
            return [];
        }

        $runtime = $decoded['runtime'] ?? null;
        $value = is_array($runtime) && array_key_exists($key, $runtime)
            ? $runtime[$key]
            : ($decoded[$key] ?? null);

        if (is_string($value)) {
            $value = str_contains($value, ',') ? explode(',', $value) : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $item)) {
                continue;
            }

            $items[] = $item;
        }

        return array_values(array_unique($items));
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
        return DB::transaction(function () use ($jobId, $triggeredBy) {
            $job = DB::selectOne('SELECT * FROM scheduled_jobs WHERE id = ? FOR UPDATE', [$jobId]);
            if (! $job) {
                throw new InferenceAdmissionException('Scheduled job admission unavailable.');
            }
            $this->assertScheduledCustodyAdmission($job, true);
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
                running_count = running_count + 1,
                updated_at = NOW()
            WHERE id = ?
        ", [$jobId]);

            $custody = $this->acquireRunCustody($jobId, $runId, getmypid() ?: null);
            if (! app(TaskCustodyService::class)->hold((int) $custody->id, 0, self::LAUNCH_RESERVED)) {
                throw new InferenceAdmissionException('Scheduled reservation publication unavailable.');
            }

            return ['run_id' => $runId, 'worker_id' => $workerId];
        });
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
    public function registerWorkerPid(int $jobId, int $pid, int $runId, ?string $workerId = null): void
    {
        try {
            $this->claimReservedWorker($jobId, $pid, $runId, $workerId);
        } catch (InferenceAdmissionException $held) {
            // A fast child may finish before its original parent registers its PID.
            // This acknowledgement never grants execution or mutates completed state.
            if ($pid !== getmypid() && $workerId !== null && $workerId !== '') {
                try {
                    $terminal = DB::selectOne('SELECT sjr.status, sjr.completed_at, tcr.owner_token, tcr.outcome, tcr.released_at
                        FROM scheduled_job_runs sjr JOIN task_custody_records tcr ON tcr.surface = ?
                            AND tcr.surface_ref = ?
                        WHERE sjr.id = ? AND sjr.scheduled_job_id = ? AND sjr.worker_id = ? AND sjr.pid = ?
                            AND (SELECT COUNT(*) FROM task_custody_records evidence WHERE evidence.surface = ? AND evidence.surface_ref = ?) = 1',
                        [TaskCustodyService::SURFACE_SCHEDULED_JOB, self::custodySurfaceRef($jobId, $runId), $runId, $jobId, $workerId, $pid, TaskCustodyService::SURFACE_SCHEDULED_JOB, self::custodySurfaceRef($jobId, $runId)]);
                    if ($terminal && in_array($terminal->status, ['success', 'completed'], true)
                        && $terminal->completed_at !== null && $terminal->released_at !== null && $terminal->outcome === 'success'
                        && preg_match('/\Apid:'.preg_quote((string) $pid, '/').':start:[0-9]+:run:'.preg_quote((string) $runId, '/').'\z/', $terminal->owner_token)) {
                        return;
                    }
                } catch (\Throwable) {
                    // Unknown terminal readback retains the original handoff hold.
                }
            }
            throw $held;
        }
    }

    /** Exact reservation CAS; parent registration and same-child reentry are idempotent. */
    private function claimReservedWorker(int $jobId, int $pid, int $runId, ?string $workerId): void
    {
        DB::transaction(function () use ($jobId, $pid, $runId, $workerId) {
            if (! DB::selectOne('SELECT id FROM scheduled_jobs WHERE id = ? FOR UPDATE', [$jobId])) {
                throw new InferenceAdmissionException('Scheduled worker parent unavailable; execution held.');
            }
            $run = DB::selectOne('SELECT * FROM scheduled_job_runs WHERE id = ? AND scheduled_job_id = ? FOR UPDATE', [$runId, $jobId]);
            $custody = DB::selectOne('SELECT *, (expires_at <= NOW()) AS is_expired FROM task_custody_records
                WHERE surface = ? AND surface_ref = ? AND released_at IS NULL FOR UPDATE',
                [TaskCustodyService::SURFACE_SCHEDULED_JOB, self::custodySurfaceRef($jobId, $runId)]);
            $service = app(TaskCustodyService::class);
            $childOwner = $service->scheduledJobOwnerToken($pid, $runId);
            if (! $run || $run->status !== 'running' || ! $custody || ($custody->is_expired ?? 1) != 0
                || ($custody->progress_note ?? null) === self::TIMEOUT_OUTCOME_UNKNOWN
                || ! preg_match('/\Apid:[1-9][0-9]*:start:[0-9]+:run:'.$runId.'\z/', $childOwner)
                || ! $this->isProcessAlive($pid)) {
                throw new InferenceAdmissionException('Scheduled worker handoff is unproven; execution held.');
            }
            if ((int) $run->pid === $pid && $custody->owner_token === $childOwner) {
                return;
            }
            if ($workerId === null || $workerId === '' || $run->worker_id !== $workerId || $run->pid !== null
                || ($custody->progress_note ?? null) !== self::LAUNCH_RESERVED
                || ! preg_match('/\Apid:([1-9][0-9]*):start:[0-9]+:run:'.$runId.'\z/', $custody->owner_token, $parent)
                || ! $this->isProcessAlive((int) $parent[1])
                || $service->scheduledJobOwnerToken((int) $parent[1], $runId) !== $custody->owner_token
                || ($pid !== getmypid() && (int) $parent[1] !== getmypid())) {
                throw new InferenceAdmissionException('Scheduled worker handoff is unproven; execution held.');
            }
            $claimed = DB::update('UPDATE task_custody_records SET owner_token = ?, progress_note = ?, updated_at = NOW()
                WHERE id = ? AND owner_token = ? AND progress_note = ? AND released_at IS NULL',
                [$childOwner, 'scheduler_worker_claimed', $custody->id, $custody->owner_token, self::LAUNCH_RESERVED]);
            $bound = DB::update("UPDATE scheduled_job_runs SET pid = ? WHERE id = ? AND scheduled_job_id = ?
                AND worker_id = ? AND pid IS NULL AND status = 'running'", [$pid, $runId, $jobId, $workerId]);
            if ($claimed !== 1 || $bound !== 1) {
                throw new InferenceAdmissionException('Scheduled worker handoff changed; execution held.');
            }
            DB::update("UPDATE scheduled_jobs SET running_pids = CASE WHEN running_pids IS NULL THEN JSON_ARRAY(?)
                ELSE JSON_ARRAY_APPEND(running_pids, '$', ?) END, last_pid = ?, updated_at = NOW() WHERE id = ?",
                [$pid, $pid, $pid, $jobId]);
        });
    }

    /**
     * Unregister a worker PID — remove from JSON array and decrement count.
     */
    public function unregisterWorkerPid(int $jobId, int $pid): void
    {
        DB::transaction(function () use ($jobId, $pid) {
            $job = DB::selectOne('SELECT * FROM scheduled_jobs WHERE id = ? FOR UPDATE', [$jobId]);
            if (! $job) {
                throw new InferenceAdmissionException('Scheduled completion parent unavailable; custody retained.');
            }
            $pids = json_decode($job->running_pids ?? '[]', true);
            if (! is_array($pids)) {
                throw new InferenceAdmissionException('Scheduled completion tracking unavailable; custody retained.');
            }
            $runs = DB::select("SELECT id, pid FROM scheduled_job_runs WHERE scheduled_job_id = ? AND status = 'running' FOR UPDATE", [$jobId]);
            $ownedRows = 0;
            $reserved = 0;
            $remainingPids = array_values(array_filter(array_map('intval', $pids), fn ($value) => $value !== $pid));
            foreach ($runs as $run) {
                if ($run->pid === null) {
                    $reserved++;
                } elseif ((int) $run->pid === $pid) {
                    $ownedRows++;
                } else {
                    $remainingPids[] = (int) $run->pid;
                }
            }
            if ($ownedRows > 1) {
                $remainingPids[] = $pid; // Another same-PID run cannot be silently discarded.
            }
            $remainingPids = array_values(array_unique($remainingPids));
            $ownedSlot = $ownedRows > 0 ? 1 : 0;
            $count = max(count($remainingPids) + $reserved, count($runs) - $ownedSlot,
                max(0, (int) $job->running_count - $ownedSlot));
            DB::update('UPDATE scheduled_jobs SET running_pids = ?, running_count = ?, last_pid = ?, updated_at = NOW() WHERE id = ?',
                [$remainingPids === [] ? null : json_encode($remainingPids), $count, $remainingPids[0] ?? null, $jobId]);
        });
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

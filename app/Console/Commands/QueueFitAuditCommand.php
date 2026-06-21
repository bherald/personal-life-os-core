<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueFitAuditCommand extends Command
{
    protected $signature = 'ops:queue-fit-audit
                            {--json : Output machine-readable JSON}
                            {--compact : Emit aggregate-only JSON without job names, row ids, or reasons; requires --json}
                            {--strict : Return non-zero exit code when likely default-lane misfits are found}';

    protected $description = 'Report-only audit for scheduled jobs that likely do not belong on the default queue/lane';

    private const HEAVY_RESOURCE_PROFILES = [
        'ai',
        'rag',
        'video',
        'thumbnails',
        'faces',
        'phash',
        'writeback',
        'exif',
    ];

    public function handle(): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option requires --json.');

            return self::FAILURE;
        }

        $jobs = $this->loadScheduledJobs();
        $flagged = [];

        foreach ($jobs as $job) {
            $assessment = $this->assessQueueFit($job);
            if ($assessment['flag_default_misfit']) {
                $flagged[] = $assessment;
            }
        }

        $actionableWarningCount = $this->actionableWarningCount($flagged);

        $report = [
            'inferential' => true,
            'scope' => 'scheduled_jobs',
            'note' => 'scheduled_jobs uses process-spawned scheduler execution; recommended_queue is an advisory runtime lane classification, not a canonical Horizon queue assignment.',
            'generated_at' => now()->toIso8601String(),
            'jobs_reviewed' => count($jobs),
            'flagged_count' => count($flagged),
            'advisory_classification_count' => count($flagged),
            'actionable_warning_count' => $actionableWarningCount,
            'flagged_jobs' => $flagged,
        ];

        if ($this->option('json')) {
            $payload = $this->option('compact') ? $this->compactReport($report) : $report;
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('=== Queue Fit Audit ===');
            $this->line('Mode: report-only advisory classification from scheduled job metadata and recent run history.');
            $this->line('Note: scheduled_jobs uses process-spawned execution; recommended_queue is not an authoritative Horizon queue assignment.');
            $this->line("Jobs reviewed: {$report['jobs_reviewed']}");
            $this->line("Advisory non-default lane classifications: {$report['advisory_classification_count']}");
            $this->line("Actionable warning classifications: {$report['actionable_warning_count']}");

            if ($flagged === []) {
                $this->line('No likely default-lane misfits detected.');
            } else {
                $rows = array_map(function (array $item) {
                    return [
                        $item['name'],
                        $item['job_type'],
                        $item['recommended_queue'],
                        ($item['actionable_warning'] ?? false) ? 'warning' : 'advisory',
                        implode('; ', $item['reasons']),
                    ];
                }, $flagged);

                $this->table(['Name', 'Type', 'Recommend', 'Signal', 'Evidence'], $rows);
            }
        }

        if ($this->option('strict') && $actionableWarningCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function compactReport(array $report): array
    {
        $flagged = is_array($report['flagged_jobs'] ?? null) ? $report['flagged_jobs'] : [];
        $queueCounts = [];
        $resourceProfileCounts = [];
        $runtimeModeCounts = [];
        $recentFailureFlagged = 0;
        $recentTimeoutFlagged = 0;
        $metadataGapCount = 0;
        $unrecoveredRecentProblemCount = 0;
        $recoveredRecentProblemCount = 0;
        $actionableWarningCount = 0;

        foreach ($flagged as $job) {
            if (! is_array($job)) {
                continue;
            }

            $queue = $this->countKey($job['recommended_queue'] ?? null, 'unknown');
            $queueCounts[$queue] = ($queueCounts[$queue] ?? 0) + 1;

            $profile = $this->countKey($job['resource_profile'] ?? null, 'none');
            $resourceProfileCounts[$profile] = ($resourceProfileCounts[$profile] ?? 0) + 1;

            $mode = $this->countKey($job['runtime_mode'] ?? null, 'none');
            $runtimeModeCounts[$mode] = ($runtimeModeCounts[$mode] ?? 0) + 1;

            if ((int) ($job['failed_runs_7d'] ?? 0) > 0) {
                $recentFailureFlagged++;
            }
            if ((int) ($job['timeout_runs_7d'] ?? 0) > 0) {
                $recentTimeoutFlagged++;
            }
            if ((bool) ($job['metadata_gap'] ?? false)) {
                $metadataGapCount++;
            }
            if ((bool) ($job['unrecovered_recent_problem'] ?? false)) {
                $unrecoveredRecentProblemCount++;
            }
            if ((bool) ($job['recent_problem_recovered'] ?? false)) {
                $recoveredRecentProblemCount++;
            }
            if ((bool) ($job['actionable_warning'] ?? false)) {
                $actionableWarningCount++;
            }
        }

        ksort($queueCounts);
        ksort($resourceProfileCounts);
        ksort($runtimeModeCounts);

        $flaggedCount = (int) ($report['flagged_count'] ?? count($flagged));
        $actionableWarningCount = (int) ($report['actionable_warning_count'] ?? $actionableWarningCount);

        return [
            'inferential' => (bool) ($report['inferential'] ?? true),
            'compact' => true,
            'mode' => 'observe',
            'status' => $actionableWarningCount > 0 ? 'observe_warning' : 'observe_ok',
            'scope' => (string) ($report['scope'] ?? 'scheduled_jobs'),
            'generated_at' => $report['generated_at'] ?? null,
            'jobs_reviewed' => (int) ($report['jobs_reviewed'] ?? 0),
            'flagged_count' => $flaggedCount,
            'advisory_classification_count' => (int) ($report['advisory_classification_count'] ?? $flaggedCount),
            'actionable_warning_count' => $actionableWarningCount,
            'recommended_queue_counts' => $queueCounts,
            'resource_profile_counts' => $resourceProfileCounts,
            'runtime_mode_counts' => $runtimeModeCounts,
            'recent_failure_flagged_count' => $recentFailureFlagged,
            'recent_timeout_flagged_count' => $recentTimeoutFlagged,
            'metadata_gap_count' => $metadataGapCount,
            'unrecovered_recent_problem_count' => $unrecoveredRecentProblemCount,
            'recovered_recent_problem_count' => $recoveredRecentProblemCount,
            'posture' => [
                'aggregate_only' => true,
                'job_names_included' => false,
                'row_ids_included' => false,
                'commands_included' => false,
                'reasons_included' => false,
                'raw_notes_included' => false,
                'queue_mutations_allowed' => false,
                'writes_enabled' => false,
            ],
        ];
    }

    private function countKey(mixed $value, string $fallback): string
    {
        $key = is_string($value) ? trim($value) : '';

        return $key !== '' ? $key : $fallback;
    }

    private function loadScheduledJobs(): array
    {
        // Typed runtime-metadata columns added in the B2 migration are preferred
        // over command-string inspection. When the migration has not yet run on
        // a given environment, fall through to a SELECT that omits them; the
        // classification path treats missing values as NULL and continues with
        // the legacy notes-JSON + command-substring fallbacks.
        $hasTypedMetadata = $this->typedRuntimeMetadataAvailable();

        $typedProjection = $hasTypedMetadata
            ? '1 AS typed_metadata_available, sj.runtime_mode, sj.workload_family, sj.resource_profile, sj.stall_policy, sj.backlog_metric, sj.notification_mode,'
            : '0 AS typed_metadata_available, NULL AS runtime_mode, NULL AS workload_family, NULL AS resource_profile, NULL AS stall_policy, NULL AS backlog_metric, NULL AS notification_mode,';

        $typedGroupBy = $hasTypedMetadata
            ? ', sj.runtime_mode, sj.workload_family, sj.resource_profile, sj.stall_policy, sj.backlog_metric, sj.notification_mode'
            : '';

        return DB::select("
            SELECT
                sj.id,
                sj.name,
                sj.job_type,
                sj.command,
                sj.timeout_minutes,
                sj.category,
                sj.source_module,
                sj.notes,
                sj.last_run_status,
                sj.last_run_at,
                sj.last_completed_at,
                {$typedProjection}
                COUNT(sjr.id) AS runs_7d,
                SUM(CASE WHEN sjr.status = 'failed' THEN 1 ELSE 0 END) AS failed_runs_7d,
                SUM(CASE WHEN sjr.status = 'timeout' THEN 1 ELSE 0 END) AS timeout_runs_7d,
                AVG(CASE WHEN sjr.duration_seconds > 0 THEN sjr.duration_seconds END) AS avg_duration_seconds,
                MAX(CASE WHEN sjr.duration_seconds > 0 THEN sjr.duration_seconds END) AS max_duration_seconds
            FROM scheduled_jobs sj
            LEFT JOIN scheduled_job_runs sjr
                ON sjr.scheduled_job_id = sj.id
               AND sjr.started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            WHERE sj.enabled = 1
            GROUP BY
                sj.id, sj.name, sj.job_type, sj.command, sj.timeout_minutes,
                sj.category, sj.source_module, sj.notes, sj.last_run_status,
                sj.last_run_at, sj.last_completed_at{$typedGroupBy}
            ORDER BY sj.name
        ");
    }

    private function typedRuntimeMetadataAvailable(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS present
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?',
            ['scheduled_jobs', 'runtime_mode']
        );

        return (int) ($row->present ?? 0) > 0;
    }

    private function assessQueueFit(object $job): array
    {
        // Source priority for classification fields:
        //   1. Typed column on scheduled_jobs  (B2 migration — authoritative)
        //   2. runtime.* block inside notes JSON  (legacy operator-set)
        //   3. command/name substring inspection below (ultimate fallback)
        $typedRuntimeMode = $this->normalizeTypedValue($job->runtime_mode ?? null);
        $typedResourceProfile = $this->normalizeTypedValue($job->resource_profile ?? null);

        $resourceProfile = $typedResourceProfile
            ?? $this->extractRuntimeValue($job->notes ?? null, 'resource_profile');
        $runtimeMode = $typedRuntimeMode
            ?? $this->extractRuntimeValue($job->notes ?? null, 'runtime_mode');
        $reportCategory = $this->extractRuntimeValue($job->notes ?? null, 'report_category')
            ?? $this->extractRuntimeValue($job->notes ?? null, 'category');
        $avgDuration = (int) round((float) ($job->avg_duration_seconds ?? 0));
        $maxDuration = (int) ($job->max_duration_seconds ?? 0);
        $timeoutMinutes = (int) ($job->timeout_minutes ?? 0);
        $failedRuns = (int) ($job->failed_runs_7d ?? 0);
        $timeoutRuns = (int) ($job->timeout_runs_7d ?? 0);
        $lastRunStatus = $this->normalizeTypedValue($job->last_run_status ?? null);
        $metadataGap = $this->hasTypedMetadataGap($job);
        $hasRecentProblem = $failedRuns > 0 || $timeoutRuns > 0;
        $unrecoveredRecentProblem = $hasRecentProblem && in_array($lastRunStatus, ['failed', 'timeout', 'running'], true);
        $recentProblemRecovered = $hasRecentProblem && ! $unrecoveredRecentProblem;
        $command = strtolower((string) ($job->command ?? ''));
        $name = strtolower((string) ($job->name ?? ''));
        $category = strtolower((string) ($job->category ?? ''));
        $sourceModule = strtolower((string) ($job->source_module ?? ''));

        $reasons = [];
        $recommendedQueue = 'default';

        if ($job->job_type === 'workflow' || str_contains($command, 'workflow')) {
            $recommendedQueue = 'workflow';
            $reasons[] = 'workflow-bound execution should be capacity-isolated';
        } elseif (str_contains($command, 'speculative') || str_contains($name, 'speculative')) {
            $recommendedQueue = 'speculative';
            $reasons[] = 'speculative work should not compete with core queues';
        } elseif ($this->looksLowPriority($name, $command, $category, $sourceModule, $reportCategory, $timeoutMinutes, $avgDuration)) {
            $recommendedQueue = 'low';
            $reasons[] = 'maintenance/backfill style work can yield to normal operations';
        } elseif (
            $job->job_type === 'agent_task'
            || $runtimeMode === 'agent'
            || ($resourceProfile !== null && in_array($resourceProfile, self::HEAVY_RESOURCE_PROFILES, true))
            || $timeoutMinutes >= 90
            || $avgDuration >= 300
            || $maxDuration >= 600
            || str_contains($command, 'rag')
            || str_contains($command, 'thumbnail')
            || str_contains($command, 'ocr')
            || str_contains($command, 'video')
            || str_contains($command, 'llm')
            || str_contains($command, 'file-catalog')
        ) {
            $recommendedQueue = 'long-running';

            if ($job->job_type === 'agent_task' || $runtimeMode === 'agent') {
                $reasons[] = 'agent task runtime should be isolated from ordinary background work';
            }
            if ($resourceProfile !== null && in_array($resourceProfile, self::HEAVY_RESOURCE_PROFILES, true)) {
                $reasons[] = "resource_profile={$resourceProfile}";
            }
            if ($timeoutMinutes >= 90) {
                $reasons[] = "timeout_minutes={$timeoutMinutes}";
            }
            if ($avgDuration >= 300) {
                $reasons[] = "avg_duration_seconds={$avgDuration}";
            }
            if ($maxDuration >= 600) {
                $reasons[] = "max_duration_seconds={$maxDuration}";
            }
        }

        if ($timeoutRuns > 0) {
            $reasons[] = 'recent timeout history';
        }
        if ($failedRuns >= 3) {
            $reasons[] = 'recent failure spike';
        }
        if ($metadataGap) {
            $reasons[] = 'typed runtime metadata gap';
        }
        if ($unrecoveredRecentProblem) {
            $reasons[] = 'recent problem has not recovered';
        }
        if ($runtimeMode !== null && $recommendedQueue !== 'default') {
            $reasons[] = "runtime_mode={$runtimeMode}";
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'id' => (int) $job->id,
            'name' => $job->name,
            'job_type' => $job->job_type,
            'recommended_queue' => $recommendedQueue,
            'flag_default_misfit' => $recommendedQueue !== 'default',
            'resource_profile' => $resourceProfile,
            'runtime_mode' => $runtimeMode,
            'report_category' => $reportCategory,
            'timeout_minutes' => $timeoutMinutes,
            'last_run_status' => $lastRunStatus,
            'typed_metadata_available' => (int) ($job->typed_metadata_available ?? 0) === 1,
            'metadata_gap' => $metadataGap,
            'unrecovered_recent_problem' => $unrecoveredRecentProblem,
            'recent_problem_recovered' => $recentProblemRecovered,
            'actionable_warning' => $metadataGap || $unrecoveredRecentProblem,
            'runs_7d' => (int) ($job->runs_7d ?? 0),
            'failed_runs_7d' => $failedRuns,
            'timeout_runs_7d' => $timeoutRuns,
            'avg_duration_seconds' => $avgDuration,
            'max_duration_seconds' => $maxDuration,
            'reasons' => $reasons,
        ];
    }

    private function actionableWarningCount(array $flagged): int
    {
        $count = 0;

        foreach ($flagged as $job) {
            if (is_array($job) && (bool) ($job['actionable_warning'] ?? false)) {
                $count++;
            }
        }

        return $count;
    }

    private function hasTypedMetadataGap(object $job): bool
    {
        if ((int) ($job->typed_metadata_available ?? 0) !== 1) {
            return false;
        }

        foreach (['runtime_mode', 'workload_family', 'resource_profile', 'stall_policy'] as $field) {
            if ($this->normalizeTypedValue($job->{$field} ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    private function looksLowPriority(
        string $name,
        string $command,
        string $category,
        string $sourceModule,
        ?string $reportCategory,
        int $timeoutMinutes,
        int $avgDuration
    ): bool {
        $lowSignals = [
            'cleanup',
            'backfill',
            'maintenance',
            'digest',
            'sync',
            'archive',
            'rebuild',
        ];

        $haystack = implode(' ', array_filter([
            $name,
            $command,
            $category,
            $sourceModule,
            strtolower((string) $reportCategory),
        ]));

        foreach ($lowSignals as $signal) {
            if (str_contains($haystack, $signal)) {
                return $timeoutMinutes <= 60 && $avgDuration < 300;
            }
        }

        return false;
    }

    private function normalizeTypedValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function extractRuntimeValue(?string $notes, string $key): ?string
    {
        if (! is_string($notes) || trim($notes) === '') {
            return null;
        }

        $decoded = $this->decodeNotes($notes);
        if ($decoded === []) {
            return null;
        }

        $runtime = $decoded['runtime'] ?? null;
        $value = is_array($runtime) ? ($runtime[$key] ?? null) : null;
        $value ??= $decoded[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function decodeNotes(string $notes): array
    {
        $decoded = json_decode($notes, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($notes, '{');
        $end = strrpos($notes, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $candidate = substr($notes, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : [];
    }
}

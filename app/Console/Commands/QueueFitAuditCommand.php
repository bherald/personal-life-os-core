<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueFitAuditCommand extends Command
{
    protected $signature = 'ops:queue-fit-audit
                            {--json : Output machine-readable JSON}
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
        $jobs = $this->loadScheduledJobs();
        $flagged = [];

        foreach ($jobs as $job) {
            $assessment = $this->assessQueueFit($job);
            if ($assessment['flag_default_misfit']) {
                $flagged[] = $assessment;
            }
        }

        $report = [
            'inferential' => true,
            'scope' => 'scheduled_jobs',
            'note' => 'scheduled_jobs has no canonical queue column; this audit reports likely default-lane misfits using runtime metadata and recent run history.',
            'generated_at' => now()->toIso8601String(),
            'jobs_reviewed' => count($jobs),
            'flagged_count' => count($flagged),
            'flagged_jobs' => $flagged,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('=== Queue Fit Audit ===');
            $this->line('Mode: report-only inference from scheduled job metadata and recent run history.');
            $this->line('Note: scheduled_jobs does not store an authoritative queue assignment.');
            $this->line("Jobs reviewed: {$report['jobs_reviewed']}");
            $this->line("Likely default-lane misfits: {$report['flagged_count']}");

            if ($flagged === []) {
                $this->line('No likely default-lane misfits detected.');
            } else {
                $rows = array_map(function (array $item) {
                    return [
                        $item['name'],
                        $item['job_type'],
                        $item['recommended_queue'],
                        implode('; ', $item['reasons']),
                    ];
                }, $flagged);

                $this->table(['Name', 'Type', 'Recommend', 'Evidence'], $rows);
            }
        }

        if ($this->option('strict') && $flagged !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
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
            ? 'sj.runtime_mode, sj.workload_family, sj.resource_profile, sj.stall_policy, sj.backlog_metric, sj.notification_mode,'
            : 'NULL AS runtime_mode, NULL AS workload_family, NULL AS resource_profile, NULL AS stall_policy, NULL AS backlog_metric, NULL AS notification_mode,';

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
                sj.category, sj.source_module, sj.notes{$typedGroupBy}
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

        if ((int) ($job->timeout_runs_7d ?? 0) > 0) {
            $reasons[] = 'recent timeout history';
        }
        if ((int) ($job->failed_runs_7d ?? 0) >= 3) {
            $reasons[] = 'recent failure spike';
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
            'runs_7d' => (int) ($job->runs_7d ?? 0),
            'failed_runs_7d' => (int) ($job->failed_runs_7d ?? 0),
            'timeout_runs_7d' => (int) ($job->timeout_runs_7d ?? 0),
            'avg_duration_seconds' => $avgDuration,
            'max_duration_seconds' => $maxDuration,
            'reasons' => $reasons,
        ];
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

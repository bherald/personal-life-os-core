<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

class SchedulerOptimizeReportService
{
    private const HEAVY_RESOURCE_PROFILES = [
        'ai',
        'faces',
        'rag',
        'thumbnails',
        'workflow',
    ];

    public function buildPayload(array $window): array
    {
        $jobs = $this->loadJobs();
        $runsByJob = $this->loadRunsByJob((int) $window['minutes']);
        $jobSummaries = $this->buildJobSummaries($jobs, $runsByJob);
        $recommendations = array_merge(
            $this->recommendMetadataRepairs($jobSummaries),
            $this->recommendFailureHotspotReviews($jobSummaries),
            $this->recommendTimeoutReviews($jobSummaries),
            $this->recommendSpacingReviews($jobSummaries)
        );

        usort(
            $recommendations,
            static fn (array $left, array $right): int => [$right['priority'], $left['id']] <=> [$left['priority'], $right['id']]
        );

        return [
            'version' => 1,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'mode' => 'observe',
            'window' => $window['canonical'],
            'job_count' => count($jobSummaries),
            'recommendation_count' => count($recommendations),
            'jobs' => array_values($jobSummaries),
            'recommendations' => $recommendations,
        ];
    }

    public function parseWindow(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (! preg_match('/^(\d+)([mhd])$/', $trimmed, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        if ($value <= 0) {
            return null;
        }

        $unit = $matches[2];
        $minutes = match ($unit) {
            'm' => $value,
            'h' => $value * 60,
            'd' => $value * 60 * 24,
        };

        return [
            'minutes' => $minutes,
            'canonical' => $value.$unit,
        ];
    }

    private function loadJobs(): array
    {
        try {
            $rows = DB::select(
                'SELECT id, name, command, cron_expression, enabled, timeout_minutes,
                        runtime_mode, workload_family, resource_profile, stall_policy,
                        run_in_background, max_parallel, last_run_status
                   FROM scheduled_jobs
                  WHERE enabled = 1
                  ORDER BY name ASC'
            );
        } catch (\Throwable) {
            return [];
        }

        $jobs = [];
        foreach ($rows as $row) {
            $jobs[(int) ($row->id ?? 0)] = [
                'id' => (int) ($row->id ?? 0),
                'name' => (string) ($row->name ?? ''),
                'command' => (string) ($row->command ?? ''),
                'cron_expression' => (string) ($row->cron_expression ?? ''),
                'enabled' => (int) ($row->enabled ?? 0),
                'timeout_minutes' => (int) ($row->timeout_minutes ?? 0),
                'runtime_mode' => $row->runtime_mode !== null ? (string) $row->runtime_mode : null,
                'workload_family' => $row->workload_family !== null ? (string) $row->workload_family : null,
                'resource_profile' => $row->resource_profile !== null ? (string) $row->resource_profile : null,
                'stall_policy' => $row->stall_policy !== null ? (string) $row->stall_policy : null,
                'run_in_background' => (int) ($row->run_in_background ?? 0),
                'max_parallel' => (int) ($row->max_parallel ?? 1),
                'last_run_status' => $row->last_run_status !== null ? (string) $row->last_run_status : null,
            ];
        }

        return $jobs;
    }

    private function loadRunsByJob(int $windowMinutes): array
    {
        if (! $this->mysqlTableExists('scheduled_job_runs')) {
            return [];
        }

        try {
            $rows = DB::select(
                'SELECT scheduled_job_id, status, duration_seconds, started_at
                   FROM scheduled_job_runs
                  WHERE started_at >= (NOW() - INTERVAL ? MINUTE)
                  ORDER BY started_at DESC',
                [$windowMinutes]
            );
        } catch (\Throwable) {
            return [];
        }

        $runs = [];
        foreach ($rows as $row) {
            $jobId = (int) ($row->scheduled_job_id ?? 0);
            $runs[$jobId][] = [
                'status' => (string) ($row->status ?? ''),
                'duration_seconds' => is_numeric($row->duration_seconds ?? null) ? (float) $row->duration_seconds : null,
                'started_at' => isset($row->started_at) ? (string) $row->started_at : null,
            ];
        }

        return $runs;
    }

    private function buildJobSummaries(array $jobs, array $runsByJob): array
    {
        $summaries = [];

        foreach ($jobs as $jobId => $job) {
            $runs = $runsByJob[$jobId] ?? [];
            $durations = array_values(array_filter(
                array_map(static fn (array $run): ?float => $run['duration_seconds'], $runs),
                static fn (?float $duration): bool => $duration !== null
            ));
            $statusCounts = [];

            foreach ($runs as $run) {
                $status = (string) ($run['status'] ?? '');
                if ($status === '') {
                    continue;
                }
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }

            $totalRuns = count($runs);
            $successRuns = (int) ($statusCounts['success'] ?? 0);

            $summaries[$jobId] = array_merge($job, [
                'fixed_minute' => $this->fixedCronMinute((string) $job['cron_expression']),
                'run_count' => $totalRuns,
                'status_counts' => $statusCounts,
                'success_rate' => $totalRuns > 0 ? round(($successRuns / $totalRuns) * 100, 1) : null,
                'p95_duration_seconds' => $this->percentile($durations, 95),
                'median_duration_seconds' => $this->percentile($durations, 50),
            ]);
        }

        return $summaries;
    }

    private function recommendMetadataRepairs(array $jobs): array
    {
        $missing = array_values(array_filter($jobs, static function (array $job): bool {
            return $job['runtime_mode'] === null
                || $job['workload_family'] === null
                || $job['resource_profile'] === null
                || $job['stall_policy'] === null;
        }));

        if ($missing === []) {
            return [];
        }

        return [[
            'id' => 'metadata-runtime-fields',
            'priority' => 90,
            'severity' => 'warning',
            'category' => 'metadata',
            'action' => 'Backfill runtime metadata before enabling scheduler auto-tuning.',
            'reason' => 'Optimizer recommendations need typed runtime fields to avoid command-name heuristics.',
            'evidence' => [
                'missing_count' => count($missing),
                'job_names' => array_map(static fn (array $job): string => $job['name'], array_slice($missing, 0, 10)),
            ],
        ]];
    }

    private function recommendFailureHotspotReviews(array $jobs): array
    {
        $recommendations = [];

        foreach ($jobs as $job) {
            $runCount = (int) $job['run_count'];
            $failed = (int) (($job['status_counts']['failed'] ?? 0) + ($job['status_counts']['timeout'] ?? 0));
            if ($runCount < 3 || $failed === 0) {
                continue;
            }

            $failureRate = round(($failed / $runCount) * 100, 1);
            if ($failureRate < 10.0) {
                continue;
            }

            $recommendations[] = [
                'id' => 'failure-hotspot-'.$job['id'],
                'priority' => 80,
                'severity' => 'warning',
                'category' => 'reliability',
                'job_id' => $job['id'],
                'job_name' => $job['name'],
                'action' => 'Review job failures before changing cadence or batch size.',
                'reason' => 'A failing job should not be optimized for throughput until root cause is known.',
                'evidence' => [
                    'run_count' => $runCount,
                    'failed_or_timeout_count' => $failed,
                    'failure_rate' => $failureRate,
                    'status_counts' => $job['status_counts'],
                ],
            ];
        }

        return $recommendations;
    }

    private function recommendTimeoutReviews(array $jobs): array
    {
        $recommendations = [];

        foreach ($jobs as $job) {
            $timeoutSeconds = (int) $job['timeout_minutes'] * 60;
            $p95 = $job['p95_duration_seconds'];
            if ($timeoutSeconds <= 0 || $p95 === null || (int) $job['run_count'] < 3) {
                continue;
            }

            if ($p95 >= ($timeoutSeconds * 0.8)) {
                $recommendations[] = [
                    'id' => 'timeout-tight-'.$job['id'],
                    'priority' => 70,
                    'severity' => 'notice',
                    'category' => 'timeout',
                    'job_id' => $job['id'],
                    'job_name' => $job['name'],
                    'action' => 'Consider reducing batch size or raising timeout after reviewing output.',
                    'reason' => 'p95 duration is within 80% of the declared timeout.',
                    'evidence' => [
                        'timeout_seconds' => $timeoutSeconds,
                        'p95_duration_seconds' => $p95,
                        'run_count' => $job['run_count'],
                    ],
                ];
            } elseif ($timeoutSeconds >= 1800 && $p95 <= ($timeoutSeconds * 0.1)) {
                $recommendations[] = [
                    'id' => 'timeout-loose-'.$job['id'],
                    'priority' => 30,
                    'severity' => 'info',
                    'category' => 'timeout',
                    'job_id' => $job['id'],
                    'job_name' => $job['name'],
                    'action' => 'Consider lowering timeout only after a longer observation window.',
                    'reason' => 'p95 duration is below 10% of a long declared timeout.',
                    'evidence' => [
                        'timeout_seconds' => $timeoutSeconds,
                        'p95_duration_seconds' => $p95,
                        'run_count' => $job['run_count'],
                    ],
                ];
            }
        }

        return $recommendations;
    }

    private function recommendSpacingReviews(array $jobs): array
    {
        $groups = [];

        foreach ($jobs as $job) {
            $minute = $job['fixed_minute'];
            $profile = (string) ($job['resource_profile'] ?? '');
            if ($minute === null || ! in_array($profile, self::HEAVY_RESOURCE_PROFILES, true)) {
                continue;
            }

            $groups[$minute][] = $job;
        }

        $recommendations = [];
        foreach ($groups as $minute => $group) {
            if (count($group) < 3) {
                continue;
            }

            $recommendations[] = [
                'id' => 'spacing-heavy-minute-'.$minute,
                'priority' => 60,
                'severity' => 'notice',
                'category' => 'spacing',
                'action' => 'Review fixed-minute spacing for heavy jobs; stagger before enabling auto-tuning.',
                'reason' => 'Multiple heavy jobs share the same fixed cron minute.',
                'evidence' => [
                    'minute' => (int) $minute,
                    'job_count' => count($group),
                    'job_names' => array_map(static fn (array $job): string => $job['name'], $group),
                    'resource_profiles' => array_values(array_unique(array_map(
                        static fn (array $job): string => (string) $job['resource_profile'],
                        $group
                    ))),
                ],
            ];
        }

        return $recommendations;
    }

    private function fixedCronMinute(string $cron): ?int
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];
        $minute = $parts[0] ?? null;

        return $minute !== null && preg_match('/^\d+$/', $minute)
            ? max(0, min(59, (int) $minute))
            : null;
    }

    private function percentile(array $values, int $percentile): ?float
    {
        $values = array_values(array_filter($values, static fn ($value): bool => is_numeric($value)));
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return round((float) $values[$index], 3);
    }

    private function mysqlTableExists(string $table): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT COUNT(*) as c
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name = ?',
                [$table]
            );

            return (int) ($row->c ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}

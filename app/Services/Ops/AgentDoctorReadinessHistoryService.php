<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgentDoctorReadinessHistoryService
{
    private const STATUS_RANK = [
        'healthy' => 0,
        'warning' => 1,
        'critical' => 2,
    ];

    /**
     * @return array<string, mixed>
     */
    public function collect(int $days = 7, int $limit = 30): array
    {
        $days = max(1, min(90, $days));
        $limit = max(1, min(100, $limit));
        $since = CarbonImmutable::now('UTC')->subDays($days);

        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'generated_at' => now()->utc()->toIso8601String(),
            'source' => [
                'table' => 'dev_agent_readiness_snapshots',
                'projection' => 'history_aggregate_only',
            ],
            'window' => [
                'days' => $days,
                'since' => $since->toIso8601String(),
                'limit' => $limit,
            ],
            'summary' => $this->emptySummary(),
            'snapshots' => [],
            'note' => 'Read-only aggregate readiness history. Uses dev_agent_readiness_snapshots only; excludes per-agent detail, raw traces, prompts, completions, command output, and filesystem paths.',
        ];

        if (! Schema::hasTable('dev_agent_readiness_snapshots')) {
            $payload['summary']['trend'] = 'unavailable';
            $payload['warnings'] = ['dev_agent_readiness_snapshots table is missing'];

            return $payload;
        }

        $snapshots = DB::table('dev_agent_readiness_snapshots')
            ->select([
                'id',
                'captured_at',
                'window_hours',
                'overall_status',
                'agent_count',
                'warning_count',
                'critical_count',
                'trace_status',
                'trace_enabled',
                'trace_directory_writable',
                'trace_events_24h',
                'trace_malformed_lines_24h',
                'trace_scan_status',
                'recursion_status',
                'recursion_calls_7d',
                'checks_summary',
            ])
            ->where('captured_at', '>=', $since->toDateTimeString())
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->snapshotRow($row))
            ->all();

        $payload['snapshots'] = $snapshots;
        $payload['summary'] = $this->summary($snapshots);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactPayload(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null)
            ? $payload['summary']
            : $this->emptySummary();
        $snapshots = is_array($payload['snapshots'] ?? null)
            ? array_values(array_map(
                fn (mixed $snapshot): array => $this->compactSnapshot(is_array($snapshot) ? $snapshot : []),
                $payload['snapshots']
            ))
            : [];

        $latest = $snapshots[0] ?? [];

        return [
            'version' => 1,
            'mode' => (string) ($payload['mode'] ?? 'observe'),
            'compact' => true,
            'generated_at' => $this->nullableString($payload['generated_at'] ?? null),
            'source' => [
                'table' => 'dev_agent_readiness_snapshots',
                'projection' => 'history_compact_aggregate_only',
            ],
            'window' => $this->compactWindow($payload['window'] ?? []),
            'summary' => [
                'snapshot_count' => (int) ($summary['snapshot_count'] ?? 0),
                'latest_status' => $this->nullableStatus($summary['latest_status'] ?? null),
                'latest_captured_at' => $this->nullableString($summary['latest_captured_at'] ?? null),
                'trend' => $this->nullableString($summary['trend'] ?? null) ?? 'unknown',
                'status_counts' => $this->statusCounts($summary['status_counts'] ?? []),
                'latest_warning_count' => $this->nullableInt($summary['latest_warning_count'] ?? null),
                'latest_critical_count' => $this->nullableInt($summary['latest_critical_count'] ?? null),
                'warning_delta' => $this->nullableSignedInt($summary['warning_delta'] ?? null),
                'critical_delta' => $this->nullableSignedInt($summary['critical_delta'] ?? null),
                'agent_count_delta' => $this->nullableSignedInt($summary['agent_count_delta'] ?? null),
                'latest_failure_modes' => $this->failureModes($summary['latest_failure_modes'] ?? []),
                'oldest_failure_modes' => $this->failureModes($summary['oldest_failure_modes'] ?? []),
                'failure_mode_snapshot_count' => (int) ($summary['failure_mode_snapshot_count'] ?? 0),
                'failure_mode_coverage_percent' => $this->nullableFloat($summary['failure_mode_coverage_percent'] ?? null),
                'failure_mode_delta_status' => $this->failureModeDeltaStatus($summary['failure_mode_delta_status'] ?? null),
                'failure_mode_delta_reason' => $this->failureModeDeltaReason($summary['failure_mode_delta_reason'] ?? null),
                'failure_mode_deltas' => $this->failureModeDeltasFromValue($summary['failure_mode_deltas'] ?? []),
                'top_rising_failure_modes' => $this->failureModeDeltasFromValue($summary['top_rising_failure_modes'] ?? []),
                'top_falling_failure_modes' => $this->failureModeDeltasFromValue($summary['top_falling_failure_modes'] ?? []),
            ],
            'trace' => [
                'latest_status' => $this->nullableStatus($latest['trace_status'] ?? null),
                'latest_events_24h' => $this->nullableInt($latest['trace_events_24h'] ?? null),
                'events_24h_delta' => $this->nullableSignedInt($summary['trace_events_24h_delta'] ?? null),
            ],
            'recursion' => [
                'latest_status' => $this->nullableStatus($latest['recursion_status'] ?? null),
                'latest_calls_7d' => $this->nullableInt($latest['recursion_calls_7d'] ?? null),
                'calls_7d_delta' => $this->nullableSignedInt($summary['recursion_calls_7d_delta'] ?? null),
            ],
            'output_quality' => $this->outputQuality($summary['latest_output_quality'] ?? []),
            'snapshots' => $snapshots,
            'posture' => [
                'scope' => 'aggregate_only',
                'snapshot_ids_included' => false,
                'check_ids_included' => false,
                'per_agent_details_included' => false,
                'raw_traces_included' => false,
                'prompts_or_completions_included' => false,
                'command_output_included' => false,
                'filesystem_paths_included' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'snapshot_count' => 0,
            'latest_status' => null,
            'latest_captured_at' => null,
            'status_counts' => [
                'healthy' => 0,
                'warning' => 0,
                'critical' => 0,
                'unknown' => 0,
            ],
            'latest_warning_count' => null,
            'latest_critical_count' => null,
            'warning_delta' => null,
            'critical_delta' => null,
            'agent_count_delta' => null,
            'trace_events_24h_delta' => null,
            'recursion_calls_7d_delta' => null,
            'latest_output_quality' => $this->emptyOutputQuality(),
            'latest_failure_modes' => [],
            'oldest_failure_modes' => [],
            'failure_mode_snapshot_count' => 0,
            'failure_mode_coverage_percent' => null,
            'failure_mode_delta_status' => 'insufficient_data',
            'failure_mode_delta_reason' => 'no_snapshots',
            'failure_mode_deltas' => [],
            'top_rising_failure_modes' => [],
            'top_falling_failure_modes' => [],
            'trend' => 'insufficient_data',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return array<string, mixed>
     */
    private function summary(array $snapshots): array
    {
        $summary = $this->emptySummary();
        $summary['snapshot_count'] = count($snapshots);

        if ($snapshots === []) {
            return $summary;
        }

        $latest = $snapshots[0];
        $oldest = $snapshots[count($snapshots) - 1];
        $statusCounts = $summary['status_counts'];
        foreach ($snapshots as $snapshot) {
            $status = (string) ($snapshot['overall_status'] ?? 'unknown');
            if (! array_key_exists($status, $statusCounts)) {
                $status = 'unknown';
            }
            $statusCounts[$status]++;
        }

        $summary['latest_status'] = $latest['overall_status'];
        $summary['latest_captured_at'] = $latest['captured_at'];
        $summary['status_counts'] = $statusCounts;
        $summary['latest_warning_count'] = $latest['warning_count'];
        $summary['latest_critical_count'] = $latest['critical_count'];
        $summary['warning_delta'] = (int) $latest['warning_count'] - (int) $oldest['warning_count'];
        $summary['critical_delta'] = (int) $latest['critical_count'] - (int) $oldest['critical_count'];
        $summary['agent_count_delta'] = (int) $latest['agent_count'] - (int) $oldest['agent_count'];
        $summary['trace_events_24h_delta'] = (int) ($latest['trace_events_24h'] ?? 0) - (int) ($oldest['trace_events_24h'] ?? 0);
        $summary['recursion_calls_7d_delta'] = (int) ($latest['recursion_calls_7d'] ?? 0) - (int) ($oldest['recursion_calls_7d'] ?? 0);
        $summary['latest_output_quality'] = $latest['output_quality'] ?? $this->emptyOutputQuality();
        $summary['latest_failure_modes'] = $this->failureModes($latest['failure_modes'] ?? []);
        $summary['oldest_failure_modes'] = $this->failureModes($oldest['failure_modes'] ?? []);
        $failureModeCoverage = $this->failureModeCoverage($snapshots);
        $summary['failure_mode_snapshot_count'] = $failureModeCoverage['snapshot_count'];
        $summary['failure_mode_coverage_percent'] = $failureModeCoverage['coverage_percent'];
        $summary['failure_mode_delta_status'] = $failureModeCoverage['delta_status'];
        $summary['failure_mode_delta_reason'] = $failureModeCoverage['delta_reason'];
        if ($failureModeCoverage['delta_status'] === 'complete') {
            $summary['failure_mode_deltas'] = $this->failureModeDeltas(
                $summary['latest_failure_modes'],
                $summary['oldest_failure_modes']
            );
            $summary['top_rising_failure_modes'] = $this->topFailureModeDeltas($summary['failure_mode_deltas'], rising: true);
            $summary['top_falling_failure_modes'] = $this->topFailureModeDeltas($summary['failure_mode_deltas'], rising: false);
        }
        $summary['trend'] = $this->trend($latest, $oldest, $summary);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotRow(object $row): array
    {
        $checks = $this->decodeChecksSummary($row->checks_summary ?? null);

        return [
            'id' => (int) $row->id,
            'captured_at' => $this->timeString($row->captured_at ?? null),
            'window_hours' => (int) ($row->window_hours ?? 0),
            'overall_status' => $this->status($row->overall_status ?? null),
            'agent_count' => (int) ($row->agent_count ?? 0),
            'warning_count' => (int) ($row->warning_count ?? 0),
            'critical_count' => (int) ($row->critical_count ?? 0),
            'trace_status' => $this->nullableStatus($row->trace_status ?? null),
            'trace_enabled' => (bool) ($row->trace_enabled ?? false),
            'trace_directory_writable' => (bool) ($row->trace_directory_writable ?? false),
            'trace_events_24h' => $this->nullableInt($row->trace_events_24h ?? null),
            'trace_malformed_lines_24h' => $this->nullableInt($row->trace_malformed_lines_24h ?? null),
            'trace_scan_status' => $this->nullableString($row->trace_scan_status ?? null),
            'recursion_status' => $this->nullableStatus($row->recursion_status ?? null),
            'recursion_calls_7d' => $this->nullableInt($row->recursion_calls_7d ?? null),
            'warning_check_ids' => $checks['warning_check_ids'],
            'critical_check_ids' => $checks['critical_check_ids'],
            'output_quality' => $checks['output_quality'],
            'failure_modes' => $checks['failure_modes'],
            'failure_modes_present' => $checks['failure_modes_present'],
        ];
    }

    /**
     * @param  array<string, mixed>  $latest
     * @param  array<string, mixed>  $oldest
     * @param  array<string, mixed>  $summary
     */
    private function trend(array $latest, array $oldest, array $summary): string
    {
        if ((int) ($summary['snapshot_count'] ?? 0) < 2) {
            return 'insufficient_data';
        }

        $rankDelta = $this->rank((string) $latest['overall_status']) - $this->rank((string) $oldest['overall_status']);
        if ($rankDelta > 0 || (int) $summary['critical_delta'] > 0) {
            return 'degrading';
        }

        if ($rankDelta < 0 || (int) $summary['critical_delta'] < 0) {
            return 'improving';
        }

        if ((int) $summary['warning_delta'] > 0) {
            return 'watch_worsening';
        }

        if ((int) $summary['warning_delta'] < 0) {
            return 'watch_improving';
        }

        return 'stable';
    }

    private function rank(string $status): int
    {
        return self::STATUS_RANK[$status] ?? 1;
    }

    private function status(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return 'unknown';
        }

        return substr(preg_replace('/[^a-z0-9_-]/', '_', $value) ?: 'unknown', 0, 20);
    }

    private function nullableStatus(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->status($value);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : max(0, (int) $value);
    }

    private function nullableSignedInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 1) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(preg_replace('/[^A-Za-z0-9_.:+-]/', '_', trim((string) $value)) ?: 'unknown', 0, 120);
    }

    /**
     * @return array<string, mixed>
     */
    private function compactWindow(mixed $value): array
    {
        $window = is_array($value) ? $value : [];

        return [
            'days' => $this->nullableInt($window['days'] ?? null),
            'since' => $this->nullableString($window['since'] ?? null),
            'limit' => $this->nullableInt($window['limit'] ?? null),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(mixed $value): array
    {
        $counts = is_array($value) ? $value : [];

        return [
            'healthy' => max(0, (int) ($counts['healthy'] ?? 0)),
            'warning' => max(0, (int) ($counts['warning'] ?? 0)),
            'critical' => max(0, (int) ($counts['critical'] ?? 0)),
            'unknown' => max(0, (int) ($counts['unknown'] ?? 0)),
        ];
    }

    private function failureModeDeltaStatus(mixed $value): string
    {
        $status = $this->nullableString($value) ?? 'insufficient_data';

        return in_array($status, ['complete', 'partial_history', 'insufficient_data'], true)
            ? $status
            : 'insufficient_data';
    }

    private function failureModeDeltaReason(mixed $value): string
    {
        $reason = $this->nullableString($value) ?? 'unknown';

        return in_array($reason, [
            'all_snapshots_have_failure_modes',
            'endpoint_snapshots_have_failure_modes',
            'fewer_than_two_snapshots',
            'latest_missing_failure_modes',
            'no_snapshots',
            'oldest_missing_failure_modes',
        ], true)
            ? $reason
            : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function compactSnapshot(array $snapshot): array
    {
        return [
            'captured_at' => $this->nullableString($snapshot['captured_at'] ?? null),
            'window_hours' => $this->nullableInt($snapshot['window_hours'] ?? null),
            'overall_status' => $this->nullableStatus($snapshot['overall_status'] ?? null),
            'agent_count' => $this->nullableInt($snapshot['agent_count'] ?? null),
            'warning_count' => $this->nullableInt($snapshot['warning_count'] ?? null),
            'critical_count' => $this->nullableInt($snapshot['critical_count'] ?? null),
            'trace_status' => $this->nullableStatus($snapshot['trace_status'] ?? null),
            'trace_events_24h' => $this->nullableInt($snapshot['trace_events_24h'] ?? null),
            'recursion_status' => $this->nullableStatus($snapshot['recursion_status'] ?? null),
            'recursion_calls_7d' => $this->nullableInt($snapshot['recursion_calls_7d'] ?? null),
            'output_quality' => $this->outputQuality($snapshot['output_quality'] ?? []),
            'failure_modes' => $this->failureModes($snapshot['failure_modes'] ?? []),
            'failure_modes_present' => (bool) ($snapshot['failure_modes_present'] ?? false),
        ];
    }

    private function timeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{warning_check_ids:list<string>,critical_check_ids:list<string>,output_quality:array<string,int>,failure_modes:array<string,int>,failure_modes_present:bool}
     */
    private function decodeChecksSummary(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($decoded)) {
            return [
                'warning_check_ids' => [],
                'critical_check_ids' => [],
                'output_quality' => $this->emptyOutputQuality(),
                'failure_modes' => [],
                'failure_modes_present' => false,
            ];
        }
        $failureModes = $decoded['failure_modes'] ?? null;

        return [
            'warning_check_ids' => $this->stringList($decoded['warning_check_ids'] ?? []),
            'critical_check_ids' => $this->stringList($decoded['critical_check_ids'] ?? []),
            'output_quality' => $this->outputQuality($decoded['output_quality'] ?? []),
            'failure_modes' => $this->failureModes($failureModes ?? []),
            'failure_modes_present' => is_array($failureModes),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return array{snapshot_count:int,coverage_percent:?float,delta_status:string,delta_reason:string}
     */
    private function failureModeCoverage(array $snapshots): array
    {
        $total = count($snapshots);
        if ($total === 0) {
            return [
                'snapshot_count' => 0,
                'coverage_percent' => null,
                'delta_status' => 'insufficient_data',
                'delta_reason' => 'no_snapshots',
            ];
        }

        $presentCount = count(array_filter(
            $snapshots,
            fn (array $snapshot): bool => (bool) ($snapshot['failure_modes_present'] ?? false)
        ));
        $latestPresent = (bool) ($snapshots[0]['failure_modes_present'] ?? false);
        $oldestPresent = (bool) ($snapshots[$total - 1]['failure_modes_present'] ?? false);

        if ($total < 2) {
            $status = 'insufficient_data';
            $reason = 'fewer_than_two_snapshots';
        } elseif (! $latestPresent) {
            $status = 'insufficient_data';
            $reason = 'latest_missing_failure_modes';
        } elseif (! $oldestPresent) {
            $status = 'partial_history';
            $reason = 'oldest_missing_failure_modes';
        } else {
            $status = 'complete';
            $reason = $presentCount === $total ? 'all_snapshots_have_failure_modes' : 'endpoint_snapshots_have_failure_modes';
        }

        return [
            'snapshot_count' => $presentCount,
            'coverage_percent' => round(($presentCount / $total) * 100, 1),
            'delta_status' => $status,
            'delta_reason' => $reason,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function failureModes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $modes = [];
        foreach ($value as $key => $count) {
            if (! is_numeric($count)) {
                continue;
            }

            $code = $this->failureModeCode($key);
            if ($code === null) {
                continue;
            }

            $normalized = max(0, (int) $count);
            if ($normalized <= 0) {
                continue;
            }

            $modes[$code] = $normalized;
            if (count($modes) >= 20) {
                break;
            }
        }

        ksort($modes);

        return $modes;
    }

    private function failureModeCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $code = strtolower(trim((string) $value));

        return preg_match('/^[a-z][a-z0-9_]{0,80}$/', $code) === 1 ? $code : null;
    }

    /**
     * @param  array<string, int>  $latest
     * @param  array<string, int>  $oldest
     * @return array<string, int>
     */
    private function failureModeDeltas(array $latest, array $oldest): array
    {
        $deltas = [];
        foreach (array_unique([...array_keys($latest), ...array_keys($oldest)]) as $mode) {
            $delta = (int) ($latest[$mode] ?? 0) - (int) ($oldest[$mode] ?? 0);
            if ($delta !== 0) {
                $deltas[$mode] = $delta;
            }
        }

        ksort($deltas);

        return $deltas;
    }

    /**
     * @return array<string, int>
     */
    private function failureModeDeltasFromValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $deltas = [];
        foreach ($value as $key => $delta) {
            if (! is_numeric($delta)) {
                continue;
            }

            $code = $this->failureModeCode($key);
            if ($code === null) {
                continue;
            }

            $normalized = (int) $delta;
            if ($normalized !== 0) {
                $deltas[$code] = $normalized;
            }

            if (count($deltas) >= 20) {
                break;
            }
        }

        ksort($deltas);

        return $deltas;
    }

    /**
     * @param  array<string, int>  $deltas
     * @return array<string, int>
     */
    private function topFailureModeDeltas(array $deltas, bool $rising): array
    {
        $filtered = array_filter(
            $deltas,
            fn (int $delta): bool => $rising ? $delta > 0 : $delta < 0
        );

        if ($filtered === []) {
            return [];
        }

        if ($rising) {
            arsort($filtered);
        } else {
            asort($filtered);
        }

        return array_slice($filtered, 0, 8, preserve_keys: true);
    }

    /**
     * @return array<string, int>
     */
    private function emptyOutputQuality(): array
    {
        return [
            'scheduled_success_runs_window' => 0,
            'scheduled_empty_success_outputs_window' => 0,
            'scheduled_cjk_output_runs_window' => 0,
            'scheduled_non_ascii_output_runs_window' => 0,
            'scheduled_guarded_output_runs_window' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function outputQuality(mixed $value): array
    {
        if (! is_array($value)) {
            return $this->emptyOutputQuality();
        }

        return [
            'scheduled_success_runs_window' => max(0, (int) ($value['scheduled_success_runs_window'] ?? 0)),
            'scheduled_empty_success_outputs_window' => max(0, (int) ($value['scheduled_empty_success_outputs_window'] ?? 0)),
            'scheduled_cjk_output_runs_window' => max(0, (int) ($value['scheduled_cjk_output_runs_window'] ?? 0)),
            'scheduled_non_ascii_output_runs_window' => max(0, (int) ($value['scheduled_non_ascii_output_runs_window'] ?? 0)),
            'scheduled_guarded_output_runs_window' => max(0, (int) ($value['scheduled_guarded_output_runs_window'] ?? 0)),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->nullableString($item),
            $value
        ))), 0, 20);
    }
}

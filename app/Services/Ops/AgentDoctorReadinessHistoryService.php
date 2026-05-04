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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', trim((string) $value)) ?: 'unknown', 0, 120);
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
     * @return array{warning_check_ids:list<string>,critical_check_ids:list<string>,output_quality:array<string,int>}
     */
    private function decodeChecksSummary(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($decoded)) {
            return [
                'warning_check_ids' => [],
                'critical_check_ids' => [],
                'output_quality' => $this->emptyOutputQuality(),
            ];
        }

        return [
            'warning_check_ids' => $this->stringList($decoded['warning_check_ids'] ?? []),
            'critical_check_ids' => $this->stringList($decoded['critical_check_ids'] ?? []),
            'output_quality' => $this->outputQuality($decoded['output_quality'] ?? []),
        ];
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

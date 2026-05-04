<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AgentDoctorReadinessSnapshotService
{
    public function __construct(
        private readonly AgentDoctorService $doctor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function capture(int $windowHours = 24, bool $dryRun = false): array
    {
        $windowHours = max(1, min(168, $windowHours));
        $doctor = $this->doctor->collect(windowHours: $windowHours, quick: true);
        $snapshot = $this->project($doctor, $windowHours);
        $snapshotId = null;

        if (! $dryRun) {
            $snapshotId = DB::table('dev_agent_readiness_snapshots')->insertGetId($this->databaseRow($snapshot));
        }

        return [
            'version' => 1,
            'mode' => 'observe',
            'generated_at' => now()->utc()->toIso8601String(),
            'dry_run' => $dryRun,
            'persisted' => ! $dryRun,
            'snapshot_id' => $snapshotId,
            'snapshot' => $snapshot,
            'source' => [
                'command' => "ops:agent-doctor --json --since={$windowHours}",
                'projection' => 'aggregate_only',
            ],
            'note' => 'Manual aggregate readiness snapshot. Stores statuses, counts, check ids, and output-quality counts only; excludes per-agent detail, raw trace events, prompts, completions, command output, and filesystem paths.',
        ];
    }

    /**
     * @param  array<string, mixed>  $doctor
     * @return array<string, mixed>
     */
    private function project(array $doctor, int $windowHours): array
    {
        $agents = is_array($doctor['agents'] ?? null) ? $doctor['agents'] : [];
        $checks = is_array($doctor['checks'] ?? null) ? $doctor['checks'] : [];
        $trace = is_array($doctor['trace'] ?? null) ? $doctor['trace'] : [];
        $recursion = is_array($doctor['recursion'] ?? null) ? $doctor['recursion'] : [];
        $summary = is_array($doctor['summary'] ?? null) ? $doctor['summary'] : [];
        $capturedAt = $this->capturedAt($doctor['generated_at'] ?? null);
        $checksSummary = $this->checksSummary($checks, $summary);

        return [
            'captured_at' => $capturedAt->toIso8601String(),
            'window_hours' => $windowHours,
            'overall_status' => $this->boundedStatus($doctor['overall_status'] ?? 'unknown'),
            'agent_count' => count($agents),
            'warning_count' => $checksSummary['status_counts']['warning'] + $this->agentStatusCount($agents, 'warning'),
            'critical_count' => $checksSummary['status_counts']['critical'] + $this->agentStatusCount($agents, 'critical'),
            'trace_status' => $this->nullableStatus($trace['status'] ?? null),
            'trace_enabled' => (bool) ($trace['enabled'] ?? false),
            'trace_directory_writable' => (bool) ($trace['directory_writable'] ?? false),
            'trace_events_24h' => $this->nullableInt($trace['events_24h'] ?? null),
            'trace_malformed_lines_24h' => $this->nullableInt($trace['malformed_lines_24h'] ?? null),
            'trace_scan_status' => $this->nullableBounded($trace['scan_status'] ?? null, 40),
            'recursion_status' => $this->nullableStatus($recursion['status'] ?? null),
            'recursion_calls_7d' => $this->nullableInt($recursion['calls_7d'] ?? null),
            'checks_summary' => $checksSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function databaseRow(array $snapshot): array
    {
        return [
            'captured_at' => CarbonImmutable::parse((string) $snapshot['captured_at'])->utc()->toDateTimeString(),
            'window_hours' => $snapshot['window_hours'],
            'overall_status' => $snapshot['overall_status'],
            'agent_count' => $snapshot['agent_count'],
            'warning_count' => $snapshot['warning_count'],
            'critical_count' => $snapshot['critical_count'],
            'trace_status' => $snapshot['trace_status'],
            'trace_enabled' => $snapshot['trace_enabled'],
            'trace_directory_writable' => $snapshot['trace_directory_writable'],
            'trace_events_24h' => $snapshot['trace_events_24h'],
            'trace_malformed_lines_24h' => $snapshot['trace_malformed_lines_24h'],
            'trace_scan_status' => $snapshot['trace_scan_status'],
            'recursion_status' => $snapshot['recursion_status'],
            'recursion_calls_7d' => $snapshot['recursion_calls_7d'],
            'checks_summary' => json_encode($snapshot['checks_summary'], JSON_UNESCAPED_SLASHES),
            'created_at' => now()->utc()->toDateTimeString(),
        ];
    }

    private function capturedAt(mixed $value): CarbonImmutable
    {
        if ($value !== null && $value !== '') {
            try {
                return CarbonImmutable::parse((string) $value)->utc();
            } catch (\Throwable) {
                // Fall through to the command time.
            }
        }

        return CarbonImmutable::now('UTC');
    }

    /**
     * @param  array<int, mixed>  $checks
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function checksSummary(array $checks, array $summary): array
    {
        $counts = [
            'ok' => 0,
            'warning' => 0,
            'critical' => 0,
            'unknown' => 0,
        ];
        $warningIds = [];
        $criticalIds = [];

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $status = $this->boundedStatus($check['status'] ?? 'unknown');
            if (! array_key_exists($status, $counts)) {
                $status = 'unknown';
            }

            $counts[$status]++;
            $id = $this->nullableBounded($check['id'] ?? null, 120);
            if ($id === null) {
                continue;
            }

            if ($status === 'warning') {
                $warningIds[] = $id;
            } elseif ($status === 'critical') {
                $criticalIds[] = $id;
            }
        }

        return [
            'total' => array_sum($counts),
            'status_counts' => $counts,
            'warning_check_ids' => array_slice(array_values(array_unique($warningIds)), 0, 20),
            'critical_check_ids' => array_slice(array_values(array_unique($criticalIds)), 0, 20),
            'output_quality' => [
                'scheduled_success_runs_window' => $this->boundedInt($summary['scheduled_success_runs_window'] ?? 0),
                'scheduled_empty_success_outputs_window' => $this->boundedInt($summary['scheduled_empty_success_outputs_window'] ?? 0),
                'scheduled_cjk_output_runs_window' => $this->boundedInt($summary['scheduled_cjk_output_runs_window'] ?? 0),
                'scheduled_guarded_output_runs_window' => $this->boundedInt($summary['scheduled_guarded_output_runs_window'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $agents
     */
    private function agentStatusCount(array $agents, string $status): int
    {
        return count(array_filter($agents, static fn (mixed $agent): bool => is_array($agent)
            && ($agent['status'] ?? null) === $status));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->boundedInt($value);
    }

    private function boundedInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function nullableStatus(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->boundedStatus($value);
    }

    private function boundedStatus(mixed $value): string
    {
        return substr(preg_replace('/[^a-z0-9_-]/', '_', strtolower(trim((string) $value))) ?: 'unknown', 0, 20);
    }

    private function nullableBounded(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?: 'unknown', 0, $limit);
    }
}

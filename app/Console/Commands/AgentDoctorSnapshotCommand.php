<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentDoctorReadinessSnapshotService;
use Illuminate\Console\Command;

class AgentDoctorSnapshotCommand extends Command
{
    protected $signature = 'ops:agent-doctor-snapshot
        {--dry-run : Build the snapshot payload without writing}
        {--json : Emit machine-readable JSON}
        {--compact : With --json, emit aggregate-only scheduled-output JSON without row or check ids}
        {--since=24 : Window size in hours, 1-168}';

    protected $description = 'Persist a manual aggregate Agent Doctor readiness snapshot';

    public function handle(AgentDoctorReadinessSnapshotService $service): int
    {
        $hours = filter_var($this->option('since'), FILTER_VALIDATE_INT);
        if (! is_int($hours) || $hours < 1 || $hours > 168) {
            $this->error('Since must be an integer from 1 to 168 hours.');

            return self::INVALID;
        }

        $payload = $service->capture(
            windowHours: $hours,
            dryRun: (bool) $this->option('dry-run')
        );

        if ($this->option('json')) {
            if ((bool) $this->option('compact')) {
                $payload = $this->compactPayload($payload);
            }

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent-doctor snapshot JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $snapshot = $payload['snapshot'];
        $this->line(sprintf(
            'agent doctor snapshot: %s agents=%d warnings=%d critical=%d trace=%s persisted=%s id=%s',
            (string) ($snapshot['overall_status'] ?? 'unknown'),
            (int) ($snapshot['agent_count'] ?? 0),
            (int) ($snapshot['warning_count'] ?? 0),
            (int) ($snapshot['critical_count'] ?? 0),
            (string) ($snapshot['trace_status'] ?? 'unknown'),
            ($payload['persisted'] ?? false) ? 'yes' : 'no',
            (string) ($payload['snapshot_id'] ?? 'dry-run'),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];
        $checks = is_array($snapshot['checks_summary'] ?? null) ? $snapshot['checks_summary'] : [];
        $warningCheckIds = is_array($checks['warning_check_ids'] ?? null) ? $checks['warning_check_ids'] : [];
        $criticalCheckIds = is_array($checks['critical_check_ids'] ?? null) ? $checks['critical_check_ids'] : [];

        return [
            'version' => (int) ($payload['version'] ?? 1),
            'mode' => (string) ($payload['mode'] ?? 'observe'),
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? now()->utc()->toIso8601String(),
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'persisted' => (bool) ($payload['persisted'] ?? false),
            'snapshot' => [
                'captured_at' => $snapshot['captured_at'] ?? null,
                'window_hours' => isset($snapshot['window_hours']) ? (int) $snapshot['window_hours'] : null,
                'overall_status' => (string) ($snapshot['overall_status'] ?? 'unknown'),
                'agent_count' => (int) ($snapshot['agent_count'] ?? 0),
                'warning_count' => (int) ($snapshot['warning_count'] ?? 0),
                'critical_count' => (int) ($snapshot['critical_count'] ?? 0),
                'trace_status' => $snapshot['trace_status'] ?? null,
                'trace_enabled' => (bool) ($snapshot['trace_enabled'] ?? false),
                'trace_directory_writable' => (bool) ($snapshot['trace_directory_writable'] ?? false),
                'trace_events_24h' => $snapshot['trace_events_24h'] ?? null,
                'trace_malformed_lines_24h' => $snapshot['trace_malformed_lines_24h'] ?? null,
                'trace_scan_status' => $snapshot['trace_scan_status'] ?? null,
                'recursion_status' => $snapshot['recursion_status'] ?? null,
                'recursion_calls_7d' => $snapshot['recursion_calls_7d'] ?? null,
                'checks_summary' => [
                    'total' => (int) ($checks['total'] ?? 0),
                    'status_counts' => is_array($checks['status_counts'] ?? null) ? $checks['status_counts'] : [],
                    'warning_check_count' => count($warningCheckIds),
                    'critical_check_count' => count($criticalCheckIds),
                    'output_quality' => is_array($checks['output_quality'] ?? null) ? $checks['output_quality'] : [],
                    'failure_modes' => is_array($checks['failure_modes'] ?? null) ? $checks['failure_modes'] : [],
                ],
            ],
            'source' => [
                'projection' => 'aggregate_only',
            ],
            'posture' => [
                'aggregate_only' => true,
                'snapshot_ids_included' => false,
                'check_ids_included' => false,
                'per_agent_details_included' => false,
                'raw_trace_events_included' => false,
                'prompts_included' => false,
                'completions_included' => false,
                'command_output_included' => false,
                'filesystem_paths_included' => false,
            ],
            'timestamp' => now()->utc()->toIso8601String(),
        ];
    }
}

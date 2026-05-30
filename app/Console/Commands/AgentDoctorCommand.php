<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentDoctorService;
use Illuminate\Console\Command;

class AgentDoctorCommand extends Command
{
    protected $signature = 'ops:agent-doctor
        {--agent= : Limit diagnostics to one agent id}
        {--quick : Keep output compatible with quicker future probes}
        {--compact : Emit a terse operator status summary without per-agent details}
        {--json : Emit machine-readable JSON}
        {--since=24 : Window size in hours, 1-168}';

    protected $description = 'Observe-only health summary for agent sessions, scheduled agent jobs, and review queues';

    public function handle(AgentDoctorService $doctor): int
    {
        $windowHours = filter_var($this->option('since'), FILTER_VALIDATE_INT);
        if (! is_int($windowHours) || $windowHours < 1 || $windowHours > 168) {
            $this->error('Since must be an integer from 1 to 168 hours.');

            return self::INVALID;
        }

        $payload = $doctor->collect(
            windowHours: $windowHours,
            agent: $this->option('agent') ? (string) $this->option('agent') : null,
            quick: (bool) $this->option('quick')
        );

        if ($this->option('json')) {
            $json = json_encode(
                $this->option('compact') ? $this->compactPayload($payload) : $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                $this->error('Failed to encode agent-doctor JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->writeCompactPayload($this->compactPayload($payload));

            return self::SUCCESS;
        }

        $summary = $payload['summary'];
        $this->line(sprintf(
            'Agent doctor: %s  agents=%d  active_sessions=%d  stalled=%d  pending_reviews=%d',
            strtoupper((string) $payload['overall_status']),
            (int) $summary['agents_total'],
            (int) $summary['sessions_active'],
            (int) $summary['sessions_stalled'],
            (int) $summary['review_queue_pending'],
        ));
        $this->line(sprintf(
            'Scheduled output: success_runs=%d  empty_success=%d  cjk_signals=%d  non_ascii_markers=%d  guarded=%d',
            (int) ($summary['scheduled_success_runs_window'] ?? 0),
            (int) ($summary['scheduled_empty_success_outputs_window'] ?? 0),
            (int) ($summary['scheduled_cjk_output_runs_window'] ?? 0),
            (int) ($summary['scheduled_non_ascii_output_runs_window'] ?? 0),
            (int) ($summary['scheduled_guarded_output_runs_window'] ?? 0),
        ));
        $this->line(sprintf(
            'Registry tools: missing=%d  blocked=%d  unavailable=%d  degraded=%d',
            (int) ($summary['tools_missing_total'] ?? 0),
            (int) ($summary['tools_blocked_total'] ?? 0),
            (int) ($summary['tools_unavailable_total'] ?? 0),
            (int) ($summary['tools_degraded_total'] ?? 0),
        ));
        $this->line(sprintf(
            'Memory evidence: episodes=%d  summaries=%d  undistilled=%d  undistilled_sessions=%d  oldest_undistilled_h=%s  low_signal_undistilled=%d  memory_errors=%d  low_quality_procedures=%d',
            (int) ($summary['memory_episodes_window'] ?? 0),
            (int) ($summary['memory_summaries_window'] ?? 0),
            (int) ($summary['memory_undistilled_episodes_window'] ?? 0),
            (int) ($summary['memory_undistilled_sessions_window'] ?? 0),
            $summary['memory_oldest_undistilled_age_hours'] ?? 'n/a',
            (int) ($summary['memory_low_signal_undistilled_sessions_window'] ?? 0),
            (int) ($summary['memory_error_episodes_window'] ?? 0),
            (int) ($summary['procedures_low_quality_total'] ?? 0),
        ));
        $this->line('Failure modes: '.$this->formatCountMap((array) ($summary['failure_mode_counts'] ?? [])));

        $recursion = $payload['recursion'] ?? null;
        if (is_array($recursion)) {
            $moveOnRate = $recursion['move_on_rate_7d'] ?? null;
            $masterEnabled = match ($recursion['master_enabled'] ?? null) {
                true => 'on',
                false => 'off',
                default => 'unknown',
            };
            $this->line(sprintf(
                'Recursion signal: %s  calls_7d=%d  move_on_7d=%s  master=%s',
                strtoupper((string) ($recursion['status'] ?? 'unknown')),
                (int) ($recursion['calls_7d'] ?? 0),
                $moveOnRate === null ? 'n/a' : number_format((float) $moveOnRate * 100, 1).'%',
                $masterEnabled,
            ));
        }

        foreach ($payload['agents'] as $agent) {
            $line = sprintf(
                '[%s] %s  sessions=%d/%d stalled  reviews=%d  job_failures=%d',
                strtoupper((string) $agent['status']),
                (string) $agent['agent_id'],
                (int) $agent['sessions']['active'],
                (int) $agent['sessions']['stalled'],
                (int) $agent['review_queue']['pending'],
                (int) $agent['scheduled_job']['consecutive_failures'],
            );

            if (($agent['status'] ?? '') === 'critical') {
                $this->error($line);
            } elseif (($agent['status'] ?? '') === 'warning') {
                $this->warn($line);
            } else {
                $this->info($line);
            }
        }

        foreach ($payload['checks'] as $check) {
            if (($check['status'] ?? '') === 'ok') {
                continue;
            }
            $this->warn(sprintf('%s: %s', (string) $check['id'], (string) $check['detail']));
        }

        // Observe-only by design: critical state is reported in the payload,
        // but does not fail shell polling unless a future strict flag adds that.
        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $recursion = is_array($payload['recursion'] ?? null) ? $payload['recursion'] : [];
        $trace = is_array($payload['trace'] ?? null) ? $payload['trace'] : [];
        $agents = is_array($payload['agents'] ?? null) ? $payload['agents'] : [];

        return [
            'generated_at' => $payload['generated_at'] ?? null,
            'window_hours' => (int) ($payload['window_hours'] ?? 0),
            'compact' => true,
            'overall_status' => (string) ($payload['overall_status'] ?? 'unknown'),
            'agent_counts' => [
                'total' => (int) ($summary['agents_total'] ?? 0),
                'enabled' => (int) ($summary['agents_enabled'] ?? 0),
                'warning' => (int) ($summary['agents_with_warnings'] ?? 0),
                'critical' => (int) ($summary['agents_with_critical'] ?? 0),
            ],
            'sessions' => [
                'active' => (int) ($summary['sessions_active'] ?? 0),
                'stalled' => (int) ($summary['sessions_stalled'] ?? 0),
            ],
            'review_queue' => [
                'pending' => (int) ($summary['review_queue_pending'] ?? 0),
            ],
            'registry_tools' => [
                'missing' => (int) ($summary['tools_missing_total'] ?? 0),
                'blocked' => (int) ($summary['tools_blocked_total'] ?? 0),
                'unavailable' => (int) ($summary['tools_unavailable_total'] ?? 0),
                'degraded' => (int) ($summary['tools_degraded_total'] ?? 0),
            ],
            'scheduled_output' => [
                'success_runs' => (int) ($summary['scheduled_success_runs_window'] ?? 0),
                'empty_success' => (int) ($summary['scheduled_empty_success_outputs_window'] ?? 0),
                'cjk_signals' => (int) ($summary['scheduled_cjk_output_runs_window'] ?? 0),
                'non_ascii_markers' => (int) ($summary['scheduled_non_ascii_output_runs_window'] ?? 0),
                'guarded' => (int) ($summary['scheduled_guarded_output_runs_window'] ?? 0),
            ],
            'scheduled_output_freshness' => [
                'latest_empty_success_at' => $summary['scheduled_latest_empty_success_output_at'] ?? null,
                'latest_empty_success_age_hours' => $summary['scheduled_latest_empty_success_output_age_hours'] ?? null,
                'latest_cjk_signal_at' => $summary['scheduled_latest_cjk_output_at'] ?? null,
                'latest_cjk_signal_age_hours' => $summary['scheduled_latest_cjk_output_age_hours'] ?? null,
                'latest_non_ascii_marker_at' => $summary['scheduled_latest_non_ascii_output_at'] ?? null,
                'latest_non_ascii_marker_age_hours' => $summary['scheduled_latest_non_ascii_output_age_hours'] ?? null,
                'latest_guarded_at' => $summary['scheduled_latest_guarded_output_at'] ?? null,
                'latest_guarded_age_hours' => $summary['scheduled_latest_guarded_output_age_hours'] ?? null,
            ],
            'memory_evidence' => [
                'episodes' => (int) ($summary['memory_episodes_window'] ?? 0),
                'summaries' => (int) ($summary['memory_summaries_window'] ?? 0),
                'undistilled' => (int) ($summary['memory_undistilled_episodes_window'] ?? 0),
                'undistilled_sessions' => (int) ($summary['memory_undistilled_sessions_window'] ?? 0),
                'undistilled_tokens' => (int) ($summary['memory_undistilled_tokens_window'] ?? 0),
                'oldest_undistilled_age_hours' => $summary['memory_oldest_undistilled_age_hours'] ?? null,
                'undistilled_age_buckets' => (array) ($summary['memory_undistilled_age_buckets'] ?? []),
                'low_signal_undistilled_sessions' => (int) ($summary['memory_low_signal_undistilled_sessions_window'] ?? 0),
                'memory_errors' => (int) ($summary['memory_error_episodes_window'] ?? 0),
                'low_quality_procedures' => (int) ($summary['procedures_low_quality_total'] ?? 0),
            ],
            'issue_code_counts' => array_slice(
                array_filter(
                    (array) ($summary['issue_code_counts'] ?? []),
                    fn (mixed $count): bool => is_numeric($count)
                ),
                0,
                10,
                true
            ),
            'failure_mode_counts' => array_slice(
                array_filter(
                    (array) ($summary['failure_mode_counts'] ?? []),
                    fn (mixed $count): bool => is_numeric($count)
                ),
                0,
                10,
                true
            ),
            'top_issue_codes' => array_values(array_filter(
                (array) ($summary['top_issue_codes'] ?? []),
                fn (mixed $code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $code) === 1
            )),
            'top_failure_modes' => array_values(array_filter(
                (array) ($summary['top_failure_modes'] ?? []),
                fn (mixed $mode): bool => is_string($mode) && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $mode) === 1
            )),
            'recursion' => [
                'status' => (string) ($recursion['status'] ?? 'unknown'),
                'calls_7d' => (int) ($recursion['calls_7d'] ?? 0),
                'move_on_rate_7d' => $recursion['move_on_rate_7d'] ?? null,
                'master_enabled' => $recursion['master_enabled'] ?? null,
            ],
            'trace_readiness' => [
                'status' => (string) ($trace['status'] ?? 'unknown'),
                'enabled' => (bool) ($trace['enabled'] ?? false),
                'scan_status' => (string) ($trace['scan_status'] ?? 'unknown'),
                'directory_writable' => (bool) ($trace['directory_writable'] ?? false),
                'retention_days' => (int) ($trace['retention_days'] ?? 0),
                'files_over_retention' => (int) ($trace['files_over_retention'] ?? 0),
                'events_24h' => $trace['events_24h'] ?? null,
                'events_24h_exact' => (bool) ($trace['events_24h_exact'] ?? false),
                'malformed_lines_24h' => $trace['malformed_lines_24h'] ?? null,
            ],
            'top_agents' => [
                'critical' => $this->topAgentIds($agents, 'critical'),
                'warning' => $this->topAgentIds($agents, 'warning'),
            ],
            'top_agent_reasons' => [
                'critical' => AgentDoctorService::compactAgentReasonSummaries($payload, 'critical'),
                'warning' => AgentDoctorService::compactAgentReasonSummaries($payload, 'warning'),
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $agents
     * @return list<string>
     */
    private function topAgentIds(array $agents, string $status): array
    {
        return collect($agents)
            ->filter(fn (mixed $agent): bool => is_array($agent) && ($agent['status'] ?? null) === $status)
            ->map(fn (array $agent): string => (string) ($agent['agent_id'] ?? ''))
            ->filter(fn (string $agentId): bool => $agentId !== '')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $compact
     */
    private function writeCompactPayload(array $compact): void
    {
        $agentCounts = $compact['agent_counts'];
        $sessions = $compact['sessions'];
        $reviewQueue = $compact['review_queue'];
        $registryTools = $compact['registry_tools'];
        $scheduledOutput = $compact['scheduled_output'];
        $memory = $compact['memory_evidence'];
        $recursion = $compact['recursion'];
        $trace = $compact['trace_readiness'];
        $topAgents = $compact['top_agents'];
        $topAgentReasons = is_array($compact['top_agent_reasons'] ?? null) ? $compact['top_agent_reasons'] : [];
        $failureModeCounts = is_array($compact['failure_mode_counts'] ?? null) ? $compact['failure_mode_counts'] : [];
        $moveOnRate = $recursion['move_on_rate_7d'] ?? null;
        $masterEnabled = match ($recursion['master_enabled'] ?? null) {
            true => 'on',
            false => 'off',
            default => 'unknown',
        };

        $this->line(sprintf(
            'Agent doctor compact: %s  agents=%d  enabled=%d  warning=%d  critical=%d  active_sessions=%d  stalled=%d  pending_reviews=%d',
            strtoupper((string) ($compact['overall_status'] ?? 'unknown')),
            (int) ($agentCounts['total'] ?? 0),
            (int) ($agentCounts['enabled'] ?? 0),
            (int) ($agentCounts['warning'] ?? 0),
            (int) ($agentCounts['critical'] ?? 0),
            (int) ($sessions['active'] ?? 0),
            (int) ($sessions['stalled'] ?? 0),
            (int) ($reviewQueue['pending'] ?? 0),
        ));
        $this->line(sprintf(
            'Scheduled output: success_runs=%d  empty_success=%d  cjk_signals=%d  non_ascii_markers=%d  guarded=%d',
            (int) ($scheduledOutput['success_runs'] ?? 0),
            (int) ($scheduledOutput['empty_success'] ?? 0),
            (int) ($scheduledOutput['cjk_signals'] ?? 0),
            (int) ($scheduledOutput['non_ascii_markers'] ?? 0),
            (int) ($scheduledOutput['guarded'] ?? 0),
        ));
        $this->line(sprintf(
            'Registry tools: missing=%d  blocked=%d  unavailable=%d  degraded=%d',
            (int) ($registryTools['missing'] ?? 0),
            (int) ($registryTools['blocked'] ?? 0),
            (int) ($registryTools['unavailable'] ?? 0),
            (int) ($registryTools['degraded'] ?? 0),
        ));
        $this->line(sprintf(
            'Memory evidence: episodes=%d  summaries=%d  undistilled=%d  undistilled_sessions=%d  oldest_undistilled_h=%s  low_signal_undistilled=%d  memory_errors=%d  low_quality_procedures=%d',
            (int) ($memory['episodes'] ?? 0),
            (int) ($memory['summaries'] ?? 0),
            (int) ($memory['undistilled'] ?? 0),
            (int) ($memory['undistilled_sessions'] ?? 0),
            $memory['oldest_undistilled_age_hours'] ?? 'n/a',
            (int) ($memory['low_signal_undistilled_sessions'] ?? 0),
            (int) ($memory['memory_errors'] ?? 0),
            (int) ($memory['low_quality_procedures'] ?? 0),
        ));
        $this->line('Failure modes: '.$this->formatCountMap($failureModeCounts));
        $this->line(sprintf(
            'Recursion signal: %s  calls_7d=%d  move_on_7d=%s  master=%s',
            strtoupper((string) ($recursion['status'] ?? 'unknown')),
            (int) ($recursion['calls_7d'] ?? 0),
            $moveOnRate === null ? 'n/a' : number_format((float) $moveOnRate * 100, 1).'%',
            $masterEnabled,
        ));
        $this->line(sprintf(
            'Trace readiness: %s  enabled=%s  scan=%s  writable=%s  retention_days=%d  files_over_retention=%d  events_24h=%s  exact=%s  malformed=%s',
            strtoupper((string) ($trace['status'] ?? 'unknown')),
            $this->boolWord($trace['enabled'] ?? false),
            (string) ($trace['scan_status'] ?? 'unknown'),
            $this->boolWord($trace['directory_writable'] ?? false),
            (int) ($trace['retention_days'] ?? 0),
            (int) ($trace['files_over_retention'] ?? 0),
            $trace['events_24h'] === null ? 'n/a' : (string) $trace['events_24h'],
            $this->boolWord($trace['events_24h_exact'] ?? false),
            $trace['malformed_lines_24h'] === null ? 'n/a' : (string) $trace['malformed_lines_24h'],
        ));
        $this->line('Critical agents: '.$this->formatAgentReasonList($topAgentReasons['critical'] ?? [], $topAgents['critical'] ?? []));
        $this->line('Warning agents: '.$this->formatAgentReasonList($topAgentReasons['warning'] ?? [], $topAgents['warning'] ?? []));
    }

    /**
     * @param  list<string>  $agentIds
     */
    private function formatAgentIdList(array $agentIds): string
    {
        return $agentIds === [] ? 'none' : implode(', ', $agentIds);
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private function formatCountMap(array $counts): string
    {
        $items = [];
        foreach ($counts as $key => $count) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{1,80}$/', $key) !== 1 || ! is_numeric($count)) {
                continue;
            }

            $items[] = $key.'='.(int) $count;
            if (count($items) >= 8) {
                break;
            }
        }

        return $items === [] ? 'none' : implode(', ', $items);
    }

    /**
     * @param  array<int, mixed>  $summaries
     * @param  list<string>  $fallbackAgentIds
     */
    private function formatAgentReasonList(array $summaries, array $fallbackAgentIds): string
    {
        $items = [];
        foreach ($summaries as $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $agentId = trim((string) ($summary['agent_id'] ?? ''));
            if ($agentId === '') {
                continue;
            }

            $codes = array_values(array_filter(
                (array) ($summary['reason_codes'] ?? []),
                fn (mixed $code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $code) === 1
            ));
            $items[] = $codes === [] ? $agentId : $agentId.'('.implode(',', $codes).')';
        }

        return $items === [] ? $this->formatAgentIdList($fallbackAgentIds) : implode(', ', $items);
    }

    private function boolWord(mixed $value): string
    {
        return $value === true ? 'yes' : 'no';
    }
}

<?php

namespace App\Services\Ops;

use App\Services\OfflinePolicyService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LlmCircuitStateReportService
{
    public function __construct(private readonly OfflinePolicyService $offlinePolicy) {}

    public function collect(int $openMinutes = 15, bool $strict = false, bool $details = false): array
    {
        $generatedAt = now();

        if (! Schema::hasTable('llm_instances')) {
            return [
                'generated_at' => $generatedAt->toIso8601String(),
                'status' => 'fail',
                'strict' => $strict,
                'thresholds' => [
                    'open_minutes' => $openMinutes,
                ],
                'message' => 'llm_instances table is missing.',
                'summary' => [],
                'issues' => [
                    [
                        'code' => 'missing_llm_instances_table',
                        'severity' => 'error',
                        'message' => 'llm_instances table is missing.',
                    ],
                ],
            ];
        }

        $select = [
            'instance_id',
            'instance_name',
            'instance_type',
            'base_url',
            'priority',
            'is_active',
            'is_healthy',
            'health_score',
            'routability',
            'gpu_target',
            'host_affinity',
            'compat_status',
            'capabilities',
            'config',
            'allows_private_data',
            'data_privacy_scope',
            'avg_response_ms',
            'p95_response_ms',
            'success_rate',
            'total_requests',
            'total_failures',
            'consecutive_failures',
            'circuit_state',
            'circuit_opened_at',
            'circuit_retry_at',
            'last_health_check',
            'last_success_at',
            'last_failure_at',
        ];

        foreach ([
            'quarantine_status',
            'quarantined_at',
            'quarantine_reason',
            'quarantine_source',
        ] as $column) {
            if (Schema::hasColumn('llm_instances', $column)) {
                $select[] = $column;
            }
        }

        $rows = DB::table('llm_instances')
            ->select($select)
            ->orderBy('priority')
            ->orderBy('instance_id')
            ->get();

        $instances = [];
        $issues = [];
        $summary = [
            'total' => 0,
            'active' => 0,
            'healthy_active' => 0,
            'routable_allowed_active' => 0,
            'closed' => 0,
            'open' => 0,
            'half_open' => 0,
            'blocked_active' => 0,
            'bench_only_active' => 0,
            'unhealthy_active' => 0,
            'stale_compat_active' => 0,
            'retry_due_still_open' => 0,
            'open_over_threshold' => 0,
            'quarantined' => 0,
            'active_quarantined' => 0,
            'provider_classes' => [
                'local_llm' => 0,
                'cloud_sensitive_safe' => 0,
                'cloud_external' => 0,
            ],
        ];

        foreach ($rows as $row) {
            $instance = $this->instancePayload($row, $generatedAt, $openMinutes);
            $instances[] = $instance;
            $this->incrementSummary($summary, $instance);

            foreach ($this->issuesForInstance($instance) as $issue) {
                $issues[] = $issue;
            }
        }

        $hasError = collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? null) === 'error');
        $status = empty($issues) ? 'pass' : (($strict || $hasError) ? 'fail' : 'warn');

        $payload = [
            'generated_at' => $generatedAt->toIso8601String(),
            'status' => $status,
            'strict' => $strict,
            'thresholds' => [
                'open_minutes' => $openMinutes,
            ],
            'summary' => $summary,
            'issues' => $issues,
            'instances' => $instances,
        ];

        if (! $details) {
            $payload['sample_instances'] = array_slice($instances, 0, 20);
            unset($payload['instances']);
        }

        return $payload;
    }

    private function instancePayload(object $row, CarbonInterface $generatedAt, int $openMinutes): array
    {
        $isActive = (int) ($row->is_active ?? 0) === 1;
        $isHealthy = (int) ($row->is_healthy ?? 0) === 1;
        $circuitState = (string) ($row->circuit_state ?? 'closed');
        $routability = (string) ($row->routability ?? 'blocked');
        $compatStatus = (string) ($row->compat_status ?? 'provisional');
        $providerClass = $this->offlinePolicy->classifyProvider($row);
        $openedAt = $this->nullableIso8601($row->circuit_opened_at ?? null);
        $retryAt = $this->nullableIso8601($row->circuit_retry_at ?? null);
        $openMinutesActual = $openedAt !== null && $circuitState === 'open'
            ? (int) max(0, $this->parseTime($openedAt)?->diffInMinutes($generatedAt) ?? 0)
            : null;
        $retryDue = $retryAt !== null
            && $circuitState === 'open'
            && $this->parseTime($retryAt)?->lessThanOrEqualTo($generatedAt);

        return [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'instance_name' => (string) ($row->instance_name ?? ''),
            'instance_type' => (string) ($row->instance_type ?? ''),
            'provider_class' => $providerClass,
            'base_url_host' => $this->baseUrlHost((string) ($row->base_url ?? '')),
            'priority' => (int) ($row->priority ?? 0),
            'active' => $isActive,
            'healthy' => $isHealthy,
            'health_score' => (int) ($row->health_score ?? 0),
            'routability' => $routability,
            'gpu_target' => (string) ($row->gpu_target ?? ''),
            'host_affinity' => (string) ($row->host_affinity ?? ''),
            'compat_status' => $compatStatus,
            'capabilities' => $this->decodeList($row->capabilities ?? null),
            'avg_response_ms' => $this->nullableFloat($row->avg_response_ms ?? null),
            'p95_response_ms' => $this->nullableFloat($row->p95_response_ms ?? null),
            'success_rate' => $this->nullableFloat($row->success_rate ?? null),
            'total_requests' => (int) ($row->total_requests ?? 0),
            'total_failures' => (int) ($row->total_failures ?? 0),
            'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
            'circuit_state' => $circuitState,
            'circuit_opened_at' => $openedAt,
            'circuit_retry_at' => $retryAt,
            'retry_due' => $retryDue,
            'quarantine' => [
                'status' => (string) ($row->quarantine_status ?? 'none'),
                'quarantined_at' => $this->nullableIso8601($row->quarantined_at ?? null),
                'reason' => $this->redactReason($row->quarantine_reason ?? null),
                'source' => $this->redactReason($row->quarantine_source ?? null),
            ],
            'open_minutes' => $openMinutesActual,
            'open_over_threshold' => $isActive
                && $circuitState === 'open'
                && $openMinutesActual !== null
                && $openMinutesActual >= $openMinutes,
            'last_health_check' => $this->nullableIso8601($row->last_health_check ?? null),
            'last_success_at' => $this->nullableIso8601($row->last_success_at ?? null),
            'last_failure_at' => $this->nullableIso8601($row->last_failure_at ?? null),
        ];
    }

    private function incrementSummary(array &$summary, array $instance): void
    {
        $summary['total']++;

        if ($instance['active']) {
            $summary['active']++;
        }

        if ($instance['active'] && $instance['healthy']) {
            $summary['healthy_active']++;
        }

        if ($instance['active'] && $instance['routability'] === 'allowed') {
            $summary['routable_allowed_active']++;
        }

        if (isset($summary[$instance['circuit_state']])) {
            $summary[$instance['circuit_state']]++;
        }

        if ($instance['active'] && $instance['routability'] === 'blocked') {
            $summary['blocked_active']++;
        }

        if ($instance['active'] && $instance['routability'] === 'bench_only') {
            $summary['bench_only_active']++;
        }

        if ($instance['active'] && ! $instance['healthy']) {
            $summary['unhealthy_active']++;
        }

        if ($instance['active'] && $instance['compat_status'] === 'stale') {
            $summary['stale_compat_active']++;
        }

        if ($instance['retry_due']) {
            $summary['retry_due_still_open']++;
        }

        if (($instance['quarantine']['status'] ?? 'none') === 'quarantined') {
            $summary['quarantined']++;
            if ($instance['active']) {
                $summary['active_quarantined']++;
            }
        }

        if ($instance['open_over_threshold']) {
            $summary['open_over_threshold']++;
        }

        if (isset($summary['provider_classes'][$instance['provider_class']])) {
            $summary['provider_classes'][$instance['provider_class']]++;
        }
    }

    private function issuesForInstance(array $instance): array
    {
        $issues = [];
        $context = [
            'instance_id' => $instance['instance_id'],
            'instance_name' => $instance['instance_name'],
            'instance_type' => $instance['instance_type'],
        ];

        if ($instance['open_over_threshold']) {
            $issues[] = [
                'code' => 'open_circuit_over_threshold',
                'severity' => 'warning',
                'message' => sprintf(
                    '%s circuit has been open for %d minutes.',
                    $instance['instance_id'],
                    (int) $instance['open_minutes']
                ),
                'context' => $context + [
                    'open_minutes' => $instance['open_minutes'],
                    'circuit_opened_at' => $instance['circuit_opened_at'],
                ],
            ];
        }

        if ($instance['retry_due']) {
            $issues[] = [
                'code' => 'retry_due_still_open',
                'severity' => 'warning',
                'message' => "{$instance['instance_id']} retry time has passed but the circuit is still open.",
                'context' => $context + [
                    'circuit_retry_at' => $instance['circuit_retry_at'],
                ],
            ];
        }

        if ($instance['active'] && ! $instance['healthy']) {
            $issues[] = [
                'code' => 'active_unhealthy_provider',
                'severity' => 'warning',
                'message' => "{$instance['instance_id']} is active but unhealthy.",
                'context' => $context + [
                    'health_score' => $instance['health_score'],
                    'consecutive_failures' => $instance['consecutive_failures'],
                ],
            ];
        }

        if ($instance['active'] && $instance['routability'] === 'allowed' && $instance['compat_status'] === 'stale') {
            $issues[] = [
                'code' => 'active_allowed_stale_compat',
                'severity' => 'warning',
                'message' => "{$instance['instance_id']} is routable but has stale compatibility metadata.",
                'context' => $context,
            ];
        }

        if ($instance['active'] && ($instance['quarantine']['status'] ?? 'none') === 'quarantined') {
            $issues[] = [
                'code' => 'active_quarantined_provider',
                'severity' => 'error',
                'message' => "{$instance['instance_id']} is quarantined but still active.",
                'context' => $context + [
                    'quarantined_at' => $instance['quarantine']['quarantined_at'] ?? null,
                    'quarantine_source' => $instance['quarantine']['source'] ?? null,
                ],
            ];
        }

        if ($instance['active'] && $instance['healthy'] && $instance['circuit_state'] === 'open') {
            $issues[] = [
                'code' => 'healthy_provider_open_circuit',
                'severity' => 'warning',
                'message' => "{$instance['instance_id']} is healthy but its circuit is open.",
                'context' => $context,
            ];
        }

        return $issues;
    }

    private function baseUrlHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function nullableIso8601(mixed $value): ?string
    {
        $time = $this->parseTime($value);

        return $time?->toIso8601String();
    }

    private function redactReason(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/\b(?:password|passwd|pwd|api[_-]?key|apikey|secret|token|bearer|authorization)\s*[:=]\s*["\']?[^"\'\s,;{}<>]{3,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{10,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('~/home/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);

        return mb_substr($text, 0, 500);
    }

    private function parseTime(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

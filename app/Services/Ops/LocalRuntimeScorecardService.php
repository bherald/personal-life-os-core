<?php

namespace App\Services\Ops;

use App\Services\OfflinePolicyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocalRuntimeScorecardService
{
    private const NEXT_COMMANDS = [
        'php artisan ops:offline-smoke --json --compact',
        'php artisan ops:llm-circuit-state --json',
        'php artisan ollama:drift-check --json --compact --no-fail',
        'php artisan ollama:eval-scorecard --json --compact',
        'php artisan llm:sync-providers --json --compact --no-live',
        'php artisan codex:exec-smoke --json',
    ];

    public function __construct(private readonly OfflinePolicyService $offlinePolicy) {}

    public function collect(): array
    {
        $generatedAt = now();

        if (! Schema::hasTable('llm_instances')) {
            return [
                'generated_at' => $generatedAt->toIso8601String(),
                'status' => 'unavailable',
                'mode' => $this->mode(),
                'summary' => $this->emptySummary(),
                'privacy_posture' => $this->emptyPrivacyPosture(),
                'ollama_eval' => $this->ollamaEvalPosture(),
                'issues' => [[
                    'code' => 'missing_llm_instances_table',
                    'severity' => 'error',
                    'count' => 1,
                    'message' => 'llm_instances table is missing.',
                ]],
                'instances' => [],
                'next_commands' => self::NEXT_COMMANDS,
            ];
        }

        $instances = [];
        foreach ($this->instanceRows() as $row) {
            $instances[] = $this->instancePayload($row);
        }

        $summary = $this->summarize($instances);
        $privacyPosture = $this->privacyPosture($instances);
        $issues = $this->issues($summary, $privacyPosture);

        return [
            'generated_at' => $generatedAt->toIso8601String(),
            'status' => $this->status($issues),
            'mode' => $this->mode(),
            'summary' => $summary,
            'privacy_posture' => $privacyPosture,
            'ollama_eval' => $this->ollamaEvalPosture(),
            'issues' => $issues,
            'instances' => $instances,
            'next_commands' => self::NEXT_COMMANDS,
        ];
    }

    public function compactPayload(array $payload): array
    {
        $issues = array_map(
            static fn (array $issue): array => [
                'code' => (string) ($issue['code'] ?? 'unknown'),
                'severity' => (string) ($issue['severity'] ?? 'watch'),
                'count' => (int) ($issue['count'] ?? 1),
            ],
            (array) ($payload['issues'] ?? [])
        );

        return [
            'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
            'compact' => true,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'mode' => (array) ($payload['mode'] ?? $this->mode()),
            'summary' => (array) ($payload['summary'] ?? $this->emptySummary()),
            'privacy_posture' => (array) ($payload['privacy_posture'] ?? $this->emptyPrivacyPosture()),
            'ollama_eval' => (array) ($payload['ollama_eval'] ?? $this->ollamaEvalPosture()),
            'issues' => $issues,
            'next_commands' => array_values((array) ($payload['next_commands'] ?? self::NEXT_COMMANDS)),
        ];
    }

    private function instanceRows(): iterable
    {
        $columns = array_values(array_filter([
            'instance_id',
            'instance_name',
            'instance_type',
            'priority',
            'is_active',
            'is_healthy',
            'health_score',
            'routability',
            'gpu_target',
            'host_affinity',
            'compat_runtime_family',
            'compat_backend',
            'compat_status',
            'capabilities',
            'supported_models',
            'context_length',
            'embedding_context_length',
            'max_concurrent',
            'allows_private_data',
            'data_privacy_scope',
            'privacy_reviewed_at',
            'last_health_check',
            'last_success_at',
            'circuit_state',
            'quarantine_status',
            'updated_at',
        ], static fn (string $column): bool => Schema::hasColumn('llm_instances', $column)));

        return DB::table('llm_instances')
            ->select($columns)
            ->orderBy('priority')
            ->orderBy('instance_id')
            ->get();
    }

    private function instancePayload(object $row): array
    {
        $type = (string) ($row->instance_type ?? '');
        $providerClass = $this->offlinePolicy->classifyProvider($row);
        $isLocal = $providerClass === 'local_llm' || in_array($type, ['ollama', 'local_llm'], true);
        $isCodex = (string) ($row->instance_id ?? '') === 'codex_exec' || $type === 'codex_cli';
        $allowsPrivate = property_exists($row, 'allows_private_data')
            ? $this->truthy($row->allows_private_data)
            : null;

        return [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'instance_type' => $type,
            'provider_class' => $providerClass,
            'provider_scope' => $isLocal ? 'local' : ($isCodex ? 'codex_external' : 'external'),
            'active' => $this->truthy($row->is_active ?? false),
            'healthy' => $this->truthy($row->is_healthy ?? false),
            'health_score' => (int) ($row->health_score ?? 0),
            'routability' => (string) ($row->routability ?? 'blocked'),
            'compat_status' => (string) ($row->compat_status ?? 'unknown'),
            'circuit_state' => (string) ($row->circuit_state ?? 'unknown'),
            'quarantine_status' => (string) ($row->quarantine_status ?? 'none'),
            'gpu_target' => (string) ($row->gpu_target ?? ''),
            'host_affinity' => (string) ($row->host_affinity ?? ''),
            'runtime_family' => (string) ($row->compat_runtime_family ?? ''),
            'backend' => (string) ($row->compat_backend ?? ''),
            'capability_count' => count($this->decodeList($row->capabilities ?? null)),
            'supported_model_count' => count($this->decodeList($row->supported_models ?? null)),
            'context_length' => $this->nullableInt($row->context_length ?? null),
            'embedding_context_length' => $this->nullableInt($row->embedding_context_length ?? null),
            'max_concurrent' => $this->nullableInt($row->max_concurrent ?? null),
            'allows_private_data' => $allowsPrivate,
            'data_privacy_scope' => (string) ($row->data_privacy_scope ?? 'unknown'),
            'privacy_reviewed' => ! empty($row->privacy_reviewed_at),
            'last_health_check' => $this->nullableIso8601($row->last_health_check ?? null),
            'last_success_at' => $this->nullableIso8601($row->last_success_at ?? null),
            'updated_at' => $this->nullableIso8601($row->updated_at ?? null),
        ];
    }

    private function summarize(array $instances): array
    {
        $summary = $this->emptySummary();

        foreach ($instances as $instance) {
            $active = (bool) ($instance['active'] ?? false);
            $healthy = (bool) ($instance['healthy'] ?? false);
            $allowed = ($instance['routability'] ?? '') === 'allowed';
            $benchOnly = ($instance['routability'] ?? '') === 'bench_only';
            $isLocal = ($instance['provider_scope'] ?? '') === 'local';
            $isCodex = ($instance['provider_scope'] ?? '') === 'codex_external';
            $isExternal = ! $isLocal;
            $allowsPrivate = $instance['allows_private_data'] ?? null;

            $summary['total']++;
            $summary[$active ? 'active' : 'inactive']++;

            if ($active && $healthy) {
                $summary['active_healthy']++;
            }

            if ($active && ! $healthy) {
                $summary['active_unhealthy']++;
            }

            if ($active && $allowed) {
                $summary['active_allowed']++;
            }

            if ($active && $benchOnly) {
                $summary['active_bench_only']++;
            }

            if ($active && $isLocal) {
                $summary['active_local']++;
            }

            if ($active && $isLocal && $allowed && $healthy) {
                $summary['active_local_allowed_healthy']++;
            }

            if ($active && $isExternal) {
                $summary['active_external']++;
            }

            if ($active && $isCodex) {
                $summary['active_codex']++;
            }

            if ($active && $isExternal && $allowed) {
                $summary['active_external_allowed']++;
            }

            if ($active && $isCodex && $allowed && $allowsPrivate === true) {
                $summary['active_codex_private_allowed']++;
            }

            if ($active && $isExternal && ! $isCodex && $allowed && $allowsPrivate === false) {
                $summary['public_external_routable_active']++;
            }

            if ($active && $isExternal && ! $isCodex && $allowsPrivate === true) {
                $summary['private_external_non_codex_active']++;
            }

            if ($active && $isExternal && $allowsPrivate === null) {
                $summary['active_external_privacy_unknown']++;
            }

            if ($active && ($instance['compat_status'] ?? '') === 'stale') {
                $summary['active_stale_compat']++;
            }

            if ($active && ($instance['circuit_state'] ?? '') === 'open') {
                $summary['active_open_circuit']++;
            }

            if ($active && ($instance['quarantine_status'] ?? 'none') === 'quarantined') {
                $summary['active_quarantined']++;
            }
        }

        return $summary;
    }

    private function privacyPosture(array $instances): array
    {
        $posture = $this->emptyPrivacyPosture();

        foreach ($instances as $instance) {
            $scope = $this->bucket((string) ($instance['data_privacy_scope'] ?? 'unknown'), [
                'local_private',
                'private_allowed',
                'public_only',
                'unknown',
            ]);
            $class = $this->bucket((string) ($instance['provider_class'] ?? 'unknown'), [
                'local_llm',
                'cloud_sensitive_safe',
                'cloud_external',
            ]);
            $providerScope = $this->bucket((string) ($instance['provider_scope'] ?? 'unknown'), [
                'local',
                'codex_external',
                'external',
            ]);

            $posture['data_privacy_scope_counts'][$scope]++;
            $posture['provider_class_counts'][$class]++;
            $posture['provider_scope_counts'][$providerScope]++;

            if (! empty($instance['active']) && ! empty($instance['privacy_reviewed'])) {
                $posture['active_privacy_reviewed']++;
            }

            if (! empty($instance['active']) && empty($instance['privacy_reviewed'])) {
                $posture['active_privacy_unreviewed']++;
            }
        }

        return $posture;
    }

    private function ollamaEvalPosture(): array
    {
        $candidateQueue = (array) config('ollama_eval.candidate_queue', []);
        $candidateCounts = [
            'active' => 0,
            'testing' => 0,
            'bench' => 0,
            'watch' => 0,
            'other' => 0,
        ];

        foreach ($candidateQueue as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $status = $this->bucket((string) ($candidate['status'] ?? 'other'), array_keys($candidateCounts));
            $candidateCounts[$status]++;
        }

        return [
            'read_only' => true,
            'promotion_apply_enabled' => false,
            'routing_bucket_count' => count((array) config('ollama_eval.routing', [])),
            'scorecard_field_count' => count((array) config('ollama_eval.scorecard_fields', [])),
            'minimum_acceptance_count' => count((array) config('ollama_eval.minimum_acceptance', [])),
            'regression_case_count' => count((array) config('ollama_eval.regression_cases', [])),
            'compression_family_count' => count((array) config('ollama_eval.compression_families', [])),
            'candidate_counts' => $candidateCounts,
        ];
    }

    private function issues(array $summary, array $privacyPosture): array
    {
        $issues = [];

        $this->addIssueIf($issues, $summary['active_local_allowed_healthy'] === 0, 'no_healthy_allowed_local_runtime', 'error', 1);
        $this->addIssueIf($issues, $summary['private_external_non_codex_active'] > 0, 'private_external_non_codex_active', 'error', $summary['private_external_non_codex_active']);
        $this->addIssueIf($issues, $summary['public_external_routable_active'] > 0, 'public_external_routable_active', 'error', $summary['public_external_routable_active']);
        $this->addIssueIf($issues, $summary['active_external_privacy_unknown'] > 0, 'external_privacy_gate_unknown', 'error', $summary['active_external_privacy_unknown']);
        $this->addIssueIf($issues, $summary['active_quarantined'] > 0, 'active_quarantined_runtime', 'error', $summary['active_quarantined']);
        $this->addIssueIf($issues, $summary['active_unhealthy'] > 0, 'active_unhealthy_runtime', 'watch', $summary['active_unhealthy']);
        $this->addIssueIf($issues, $summary['active_stale_compat'] > 0, 'active_stale_compat_runtime', 'watch', $summary['active_stale_compat']);
        $this->addIssueIf($issues, $summary['active_open_circuit'] > 0, 'active_open_circuit_runtime', 'watch', $summary['active_open_circuit']);
        $this->addIssueIf($issues, $privacyPosture['active_privacy_unreviewed'] > 0, 'active_privacy_unreviewed', 'watch', $privacyPosture['active_privacy_unreviewed']);

        return $issues;
    }

    private function addIssueIf(array &$issues, bool $condition, string $code, string $severity, int $count): void
    {
        if (! $condition) {
            return;
        }

        $issues[] = [
            'code' => $code,
            'severity' => $severity,
            'count' => $count,
        ];
    }

    private function status(array $issues): string
    {
        if ($issues === []) {
            return 'healthy';
        }

        foreach ($issues as $issue) {
            if (($issue['severity'] ?? null) === 'error') {
                return 'review_required';
            }
        }

        return 'watch';
    }

    private function mode(): array
    {
        return [
            'read_only' => true,
            'no_write' => true,
            'network_probes_executed' => false,
            'external_llm_invoked' => false,
            'routing_change_allowed' => false,
            'promotion_apply_enabled' => false,
            'private_data_shared' => false,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'active_healthy' => 0,
            'active_unhealthy' => 0,
            'active_allowed' => 0,
            'active_bench_only' => 0,
            'active_local' => 0,
            'active_local_allowed_healthy' => 0,
            'active_external' => 0,
            'active_codex' => 0,
            'active_external_allowed' => 0,
            'active_codex_private_allowed' => 0,
            'public_external_routable_active' => 0,
            'private_external_non_codex_active' => 0,
            'active_external_privacy_unknown' => 0,
            'active_stale_compat' => 0,
            'active_open_circuit' => 0,
            'active_quarantined' => 0,
        ];
    }

    private function emptyPrivacyPosture(): array
    {
        return [
            'active_privacy_reviewed' => 0,
            'active_privacy_unreviewed' => 0,
            'data_privacy_scope_counts' => [
                'local_private' => 0,
                'private_allowed' => 0,
                'public_only' => 0,
                'unknown' => 0,
                'other' => 0,
            ],
            'provider_class_counts' => [
                'local_llm' => 0,
                'cloud_sensitive_safe' => 0,
                'cloud_external' => 0,
                'other' => 0,
            ],
            'provider_scope_counts' => [
                'local' => 0,
                'codex_external' => 0,
                'external' => 0,
                'other' => 0,
            ],
        ];
    }

    private function bucket(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : 'other';
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableIso8601(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}

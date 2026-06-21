<?php

namespace App\Console\Commands;

use App\Services\LLMPoolManagerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpsLlmHealthReconcileCommand extends Command
{
    protected $signature = 'ops:llm-health-reconcile
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate compact JSON; requires --json}';

    protected $description = 'Reconcile table-backed LLM provider health from configured runtime probes';

    private const POLICY_SOURCE = 'system_configs.ops.llm_health_reconcile_policy';

    public function handle(LLMPoolManagerService $pool): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('Use --compact with --json.');

            return self::INVALID;
        }

        $policy = $this->policy();
        if (isset($policy['__error'])) {
            return $this->emit($this->basePayload([
                'status' => 'configuration_error',
                'error' => $policy['__error'],
                'summary' => $this->emptySummary(),
            ]), self::FAILURE);
        }

        if (($policy['enabled'] ?? null) !== true) {
            return $this->emit($this->basePayload([
                'status' => 'disabled',
                'summary' => $this->emptySummary(),
            ]), self::SUCCESS);
        }

        $policyError = $this->validatePolicySafety($policy);
        if ($policyError !== null) {
            return $this->emit($this->basePayload([
                'status' => 'configuration_error',
                'error' => $policyError,
                'summary' => $this->emptySummary(),
            ]), self::FAILURE);
        }

        $started = microtime(true);
        $results = $pool->healthCheckAllInstances();

        return $this->emit($this->payload($results, (int) ((microtime(true) - $started) * 1000)), self::SUCCESS);
    }

    /**
     * @return array<string,mixed>
     */
    private function policy(): array
    {
        if (! Schema::hasTable('system_configs')) {
            return ['__error' => 'system_configs_missing'];
        }

        $row = DB::selectOne(
            'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['ops', 'llm_health_reconcile_policy']
        );
        if ($row === null) {
            return ['__error' => 'llm_health_reconcile_policy_missing'];
        }

        $decoded = json_decode((string) $row->config_value, true);
        if (! is_array($decoded)) {
            return ['__error' => 'llm_health_reconcile_policy_invalid_json'];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function validatePolicySafety(array $policy): ?string
    {
        if (data_get($policy, 'safety.write_llm_instance_health') !== true) {
            return 'write_llm_instance_health_not_enabled';
        }

        if (data_get($policy, 'safety.routing_changes_allowed') !== false) {
            return 'routing_changes_must_be_disabled';
        }

        if (data_get($policy, 'safety.role_changes_allowed') !== false) {
            return 'role_changes_must_be_disabled';
        }

        if (data_get($policy, 'safety.external_llm_invoked') !== false) {
            return 'external_llm_invocation_must_be_disabled';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $results
     * @return array<string,mixed>
     */
    private function payload(array $results, int $durationMs): array
    {
        $summary = [
            'checked' => (int) ($results['checked'] ?? 0),
            'healthy' => (int) ($results['healthy'] ?? 0),
            'unhealthy' => (int) ($results['unhealthy'] ?? 0),
            'recovered' => (int) ($results['recovered'] ?? 0),
            'degraded' => (int) ($results['degraded'] ?? 0),
            'duration_ms' => $durationMs,
        ];

        return $this->basePayload([
            'status' => $this->status($summary),
            'summary' => $summary,
            'details' => $this->details((array) ($results['details'] ?? [])),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function basePayload(array $overrides): array
    {
        return array_merge([
            'generated_at' => now()->toIso8601String(),
            'policy_source' => self::POLICY_SOURCE,
            'mode' => [
                'read_only' => false,
                'writes_llm_instances_health' => true,
                'network_probes_executed' => true,
                'external_llm_invoked' => false,
                'routing_changes_allowed' => false,
                'role_changes_allowed' => false,
                'provider_role_promotions_allowed' => false,
                'private_data_shared' => false,
            ],
            'safety' => [
                'write_scope' => 'llm_instances health/circuit metadata only',
                'model_role_changes' => false,
                'routability_changes' => false,
                'max_concurrent_changes' => false,
                'scheduled_job_changes' => false,
            ],
        ], $overrides);
    }

    /**
     * @return array<string,int>
     */
    private function emptySummary(): array
    {
        return [
            'checked' => 0,
            'healthy' => 0,
            'unhealthy' => 0,
            'recovered' => 0,
            'degraded' => 0,
            'duration_ms' => 0,
        ];
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function status(array $summary): string
    {
        if (($summary['unhealthy'] ?? 0) > 0) {
            return 'review_required';
        }

        if (($summary['recovered'] ?? 0) > 0) {
            return 'recovered';
        }

        if (($summary['degraded'] ?? 0) > 0) {
            return 'watch';
        }

        return 'healthy';
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<int,array<string,mixed>>
     */
    private function details(array $details): array
    {
        $out = [];
        foreach ($details as $instanceId => $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $out[] = [
                'instance_id' => (string) ($detail['instance_id'] ?? $instanceId),
                'type' => (string) ($detail['type'] ?? 'unknown'),
                'healthy' => (bool) ($detail['healthy'] ?? false),
                'recovered' => (bool) ($detail['recovered'] ?? false),
                'degraded' => (bool) ($detail['degraded'] ?? false),
                'response_ms' => isset($detail['response_ms']) ? (int) $detail['response_ms'] : null,
                'error' => isset($detail['error']) ? mb_substr((string) $detail['error'], 0, 160) : null,
            ];
        }

        return $out;
    }

    private function emit(array $payload, int $exitCode): int
    {
        if ($this->option('json')) {
            $output = $this->option('compact') ? $this->compactPayload($payload) : $payload;
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $summary = (array) ($payload['summary'] ?? $this->emptySummary());
        $this->line(sprintf(
            'LLM health reconcile: %s checked=%d healthy=%d unhealthy=%d recovered=%d degraded=%d',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($summary['checked'] ?? 0),
            (int) ($summary['healthy'] ?? 0),
            (int) ($summary['unhealthy'] ?? 0),
            (int) ($summary['recovered'] ?? 0),
            (int) ($summary['degraded'] ?? 0),
        ));

        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);
        }

        return $exitCode;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function compactPayload(array $payload): array
    {
        return [
            'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
            'compact' => true,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'policy_source' => self::POLICY_SOURCE,
            'mode' => $payload['mode'] ?? [],
            'summary' => $payload['summary'] ?? $this->emptySummary(),
            'safety' => $payload['safety'] ?? [],
            'error' => $payload['error'] ?? null,
        ];
    }
}

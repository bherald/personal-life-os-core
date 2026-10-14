<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class OllamaCoexistenceAdmissionService
{
    private const RECEIPT_SHA256 = '96c214a8ac2fa462c6236a4b911d07b09608b92a7f05c92ee2107c58a901505f';

    /** @return array<string,mixed> */
    public function status(): array
    {
        $provider = $this->providerRow();
        $config = $this->decode($provider->config ?? '{}');
        $coexistence = $this->map($config['coexistence'] ?? null);

        $durable = (bool) ($provider->is_active ?? false)
            && (bool) ($provider->is_healthy ?? false)
            && (string) ($provider->routability ?? '') === 'allowed'
            && (string) ($provider->circuit_state ?? '') === 'closed'
            && (string) ($coexistence['phase'] ?? '') === 'K3-COEX-04-live'
            && (string) ($coexistence['mode'] ?? '') === 'serialized'
            && ($coexistence['comparison_receipt_sha256'] ?? null) === self::RECEIPT_SHA256
            && $this->exactProviderPolicy($config)
            && (int) ($provider->max_concurrent ?? 0) === 1;

        return [
            'schema' => 'plos.ollama_coexistence_admission.v1',
            'phase' => (string) ($coexistence['phase'] ?? ''),
            'instance_id' => app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'instance_id'),
            'model' => app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model'),
            'is_active' => (bool) ($provider->is_active ?? false),
            'is_healthy' => (bool) ($provider->is_healthy ?? false),
            'routability' => (string) ($provider->routability ?? ''),
            'circuit_state' => (string) ($provider->circuit_state ?? ''),
            'quarantine_status' => (string) ($provider->quarantine_status ?? ''),
            'max_concurrent' => isset($provider->max_concurrent) ? (int) $provider->max_concurrent : null,
            'admission_group' => data_get($config, 'slot_policy.admission_group'),
            'mode' => (string) ($coexistence['mode'] ?? ''),
            'comparison_receipt_sha256' => $coexistence['comparison_receipt_sha256'] ?? null,
            'comparison_receipt_exact' => $this->receiptExact(),
            'exact_provider_policy' => $this->exactProviderPolicy($config),
            'explicit_instance_required' => (bool) data_get($config, 'route_policy.require_explicit_instance', false),
            'generic_routing_enabled' => (bool) ($coexistence['normal_routing'] ?? true),
            'tools_enabled' => (bool) data_get($config, 'route_policy.tools_enabled', true),
            'fallback_enabled' => (bool) data_get($config, 'route_policy.allow_generic_fallback', true),
            'thinking_enabled' => (bool) data_get($config, 'role_runtime.standard.think', true),
            'durable' => $durable,
        ];
    }

    /** @return array<string,mixed> */
    public function promote(): array
    {
        $this->assertReceipt();
        $this->assertQwenDurable();
        $this->assertHealth();

        DB::transaction(function (): void {
            $provider = $this->providerRow(true);
            $config = $this->decode($provider->config ?? '{}');
            $coexistence = $this->map($config['coexistence'] ?? null);
            $routePolicy = $this->map($config['route_policy'] ?? null);
            $slotPolicy = $this->map($config['slot_policy'] ?? null);

            $blockedPhase = (string) ($coexistence['phase'] ?? '');
            $blockedQuarantine = (string) ($provider->quarantine_status ?? '');
            $blockedStateExact = ($blockedPhase === 'K3-COEX-01-staged'
                    && $blockedQuarantine === 'operator_coexistence_blocked')
                || ($blockedPhase === 'K3-COEX-04-rollback'
                    && $blockedQuarantine === 'operator_rollback_blocked');

            if ((int) ($provider->is_active ?? 0) !== 0
                || (int) ($provider->is_healthy ?? 0) !== 0
                || (string) ($provider->routability ?? '') !== 'blocked'
                || ! $blockedStateExact
                || ($routePolicy['operator_route_block'] ?? null) !== true
                || ($routePolicy['operator_route_block_phase'] ?? null) !== 'K3-COEX-01'
                || ($slotPolicy['admission_group'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'admission_group')
                || ($coexistence['mode'] ?? null) !== 'serialized'
                || ! in_array(app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model'), $this->list($coexistence['allowed_models'] ?? null), true)
            ) {
                throw new RuntimeException('Ollama coexistence promotion requires the exact blocked K3-COEX-01 state.');
            }

            $roleRuntime = $this->map($config['role_runtime'] ?? null);
            $coexistence['pre_promotion'] = [
                'route_policy' => $routePolicy,
                'standard_runtime_present' => array_key_exists('standard', $roleRuntime),
                'standard_runtime' => $roleRuntime['standard'] ?? null,
                'max_concurrent' => isset($provider->max_concurrent) ? (int) $provider->max_concurrent : null,
            ];
            $config['models']['standard'] = app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model');
            $config['default_model'] = app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model');
            $config['role_runtime']['standard'] = [
                'think' => false,
                'max_tokens' => 128,
                'ai_timeout' => 180,
                'options' => [
                    'num_ctx' => 4096,
                    'temperature' => 0,
                    'num_predict' => 128,
                ],
            ];
            $config['route_policy'] = [
                ...$routePolicy,
                'allowed_model_roles' => ['standard'],
                'require_model_role' => true,
                'require_explicit_instance' => true,
                'allow_generic_fallback' => false,
                'tools_enabled' => false,
                'operator_route_block' => false,
                'operator_route_block_phase' => 'K3-COEX-04-live',
            ];
            $config['coexistence'] = [
                ...$coexistence,
                'phase' => 'K3-COEX-04-live',
                'mode' => 'serialized',
                'model' => app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model'),
                'comparison_receipt_sha256' => self::RECEIPT_SHA256,
                'lifecycle_task' => 'PLOS-OllamaCoex11434',
                'candidate_only_admission' => true,
                'normal_routing' => false,
                'gpu_only_required' => true,
                'cpu_spill_max_bytes' => 0,
                'tools_enabled' => false,
                'fallback_enabled' => false,
            ];

            $now = now();
            DB::table('llm_instances')->where('id', $provider->id)->update($this->filterColumns([
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 100,
                'routability' => 'allowed',
                'circuit_state' => 'closed',
                'circuit_opened_at' => null,
                'circuit_retry_at' => null,
                'consecutive_failures' => 0,
                'quarantine_status' => 'none',
                'quarantined_at' => null,
                'quarantine_reason' => null,
                'quarantine_source' => null,
                'max_concurrent' => 1,
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $this->appendNote($provider->notes ?? null, 'K3-COEX-04: explicit-instance qwen3:8b serialized coexistence; no generic routing, tools, or fallback.'),
                'last_health_check' => $now,
                'last_success_at' => $now,
                'updated_at' => $now,
            ]));
        });
        $this->clearCaches();

        return $this->status();
    }

    /** @return array<string,mixed> */
    public function rollback(): array
    {
        DB::transaction(function (): void {
            $provider = $this->providerRow(true);
            $config = $this->decode($provider->config ?? '{}');
            $coexistence = $this->map($config['coexistence'] ?? null);
            $prePromotion = $this->map($coexistence['pre_promotion'] ?? null);

            if (($coexistence['phase'] ?? null) !== 'K3-COEX-04-live'
                || ($coexistence['comparison_receipt_sha256'] ?? null) !== self::RECEIPT_SHA256
                || $prePromotion === []
            ) {
                throw new RuntimeException('Refusing to roll back Ollama state not owned by K3-COEX-04.');
            }

            $config['route_policy'] = $this->map($prePromotion['route_policy'] ?? null);
            $roleRuntime = $this->map($config['role_runtime'] ?? null);
            if (($prePromotion['standard_runtime_present'] ?? false) === true) {
                $roleRuntime['standard'] = $prePromotion['standard_runtime'] ?? [];
            } else {
                unset($roleRuntime['standard']);
            }
            if ($roleRuntime === []) {
                unset($config['role_runtime']);
            } else {
                $config['role_runtime'] = $roleRuntime;
            }
            unset($coexistence['pre_promotion']);
            $coexistence['phase'] = 'K3-COEX-04-rollback';
            $coexistence['normal_routing'] = false;
            $coexistence['tools_enabled'] = false;
            $coexistence['fallback_enabled'] = false;
            $config['coexistence'] = $coexistence;

            $now = now();
            DB::table('llm_instances')->where('id', $provider->id)->update($this->filterColumns([
                'is_active' => 0,
                'is_healthy' => 0,
                'health_score' => 0,
                'routability' => 'blocked',
                'circuit_state' => 'open',
                'circuit_opened_at' => $now,
                'circuit_retry_at' => null,
                'quarantine_status' => 'operator_rollback_blocked',
                'quarantined_at' => $now,
                'quarantine_reason' => 'K3-COEX-04 rollback: Ollama route remains blocked pending a new operator-owned promotion.',
                'quarantine_source' => 'K3-COEX-04-rollback',
                'max_concurrent' => (int) ($prePromotion['max_concurrent'] ?? 1),
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $this->appendNote($provider->notes ?? null, 'K3-COEX-04 rollback: durable Qwen preserved; Ollama route blocked.'),
                'last_health_check' => null,
                'updated_at' => $now,
            ]));
        });
        $this->clearCaches();

        return $this->status();
    }

    private function assertQwenDurable(): void
    {
        $row = DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'qwen_instance_id'))->first();
        if ($row === null) {
            throw new RuntimeException('Durable Qwen provider is missing.');
        }
        $config = $this->decode($row->config ?? '{}');
        if (! (bool) ($row->is_active ?? false)
            || ! (bool) ($row->is_healthy ?? false)
            || (string) ($row->routability ?? '') !== 'allowed'
            || data_get($config, 'admission.phase') !== 'K3-ALT-06-live'
            || data_get($config, 'slot_policy.admission_group') !== app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'admission_group')
            || data_get($config, 'coexistence.mode') !== 'serialized'
        ) {
            throw new RuntimeException('Exact durable serialized Qwen state is required.');
        }
    }

    private function assertHealth(): void
    {
        $provider = $this->providerRow();
        $base = rtrim((string) ($provider->base_url ?? ''), '/');
        if ($base !== app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'base_url')) {
            throw new RuntimeException('Ollama coexistence endpoint identity has drifted.');
        }
        $response = Http::connectTimeout(5)->timeout(15)->get($base.'/api/tags');
        if (! $response->successful()) {
            throw new RuntimeException('Ollama coexistence promotion requires healthy .87 ingress.');
        }
        $models = $response->json('models');
        if (is_array($models) && ! collect($models)->contains(fn (mixed $entry): bool => is_array($entry) && ($entry['name'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model'))) {
            throw new RuntimeException('Exact qwen3:8b model is not installed on .87.');
        }
    }

    private function assertReceipt(): void
    {
        if (! $this->receiptExact()) {
            throw new RuntimeException('Exact green K3-COEX-03 serialized receipt is required.');
        }
        $payload = json_decode((string) file_get_contents($this->receiptPath()), true, 512, JSON_THROW_ON_ERROR);
        if (($payload['success'] ?? null) !== true
            || ($payload['recommended_mode'] ?? null) !== 'serialized'
            || ($payload['serialized_green'] ?? null) !== true
            || ($payload['concurrent_green'] ?? null) !== false
            || ($payload['model_fully_gpu_resident'] ?? null) !== true
            || ($payload['fallback_enabled'] ?? null) !== false
            || ($payload['tools_enabled'] ?? null) !== false
            || (float) ($payload['qwen_serialized_regression_percent'] ?? 100) > 25.0
        ) {
            throw new RuntimeException('K3-COEX-03 receipt does not authorize serialized promotion.');
        }
    }

    /** @param array<string,mixed> $config */
    private function exactProviderPolicy(array $config): bool
    {
        return ($config['models']['standard'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model')
            && ($config['default_model'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model')
            && ($config['slot_policy']['admission_group'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'admission_group')
            && ($config['route_policy']['allowed_model_roles'] ?? null) === ['standard']
            && ($config['route_policy']['require_model_role'] ?? null) === true
            && ($config['route_policy']['require_explicit_instance'] ?? null) === true
            && ($config['route_policy']['allow_generic_fallback'] ?? null) === false
            && ($config['route_policy']['tools_enabled'] ?? null) === false
            && ($config['route_policy']['operator_route_block'] ?? null) === false
            && ($config['role_runtime']['standard']['think'] ?? null) === false
            && (int) ($config['role_runtime']['standard']['max_tokens'] ?? 0) === 128
            && (int) ($config['role_runtime']['standard']['options']['num_ctx'] ?? 0) === 4096
            && (int) ($config['role_runtime']['standard']['options']['num_predict'] ?? 0) === 128
            && ($config['coexistence']['candidate_only_admission'] ?? null) === true
            && ($config['coexistence']['normal_routing'] ?? null) === false
            && ($config['coexistence']['gpu_only_required'] ?? null) === true
            && (int) ($config['coexistence']['cpu_spill_max_bytes'] ?? -1) === 0;
    }

    private function receiptExact(): bool
    {
        return is_file($this->receiptPath()) && hash_file('sha256', $this->receiptPath()) === self::RECEIPT_SHA256;
    }

    private function receiptPath(): string
    {
        return base_path('scripts/patches/colibri/k3-selective-v162/K3-COEX-03-SERIALIZED-RECEIPT.json');
    }

    private function providerRow(bool $lock = false): object
    {
        if (! Schema::hasTable('llm_instances')) {
            throw new RuntimeException('llm_instances table is unavailable.');
        }
        $query = DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'instance_id'));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new RuntimeException('Ollama secondary provider row is missing.');
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function filterColumns(array $payload): array
    {
        return array_filter($payload, static fn (string $column): bool => Schema::hasColumn('llm_instances', $column), ARRAY_FILTER_USE_KEY);
    }

    private function appendNote(mixed $existing, string $line): string
    {
        $existing = trim((string) ($existing ?? ''));
        if (str_contains($existing, $line)) {
            return $existing;
        }

        return $existing === '' ? $line : $existing."\n".$line;
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
        Cache::forget('rpm_count_'.app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'instance_id'));
        Cache::forget('rate_limit_'.app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'instance_id'));
    }
}

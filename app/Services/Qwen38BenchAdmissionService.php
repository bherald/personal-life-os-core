<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class Qwen38BenchAdmissionService
{
    private const MODEL_SHA256 = '31629f53165ab6a7dad8c9847dcfd1fdf55829dac1e6e748f4a68581b0033d34';

    private const RUNTIME_COMMIT = '01818e4956858fe225a6875aae88b4cbee0b319e';

    /** @return array<string,mixed> */
    public function status(): array
    {
        $row = $this->providerRow();
        $config = $this->decodeConfig($row->config ?? '{}');
        $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];

        return [
            'schema' => 'plos.qwen38_bench_admission.v1',
            'instance_id' => app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'),
            'model' => app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'model'),
            'is_active' => (bool) ($row->is_active ?? false),
            'is_healthy' => (bool) ($row->is_healthy ?? false),
            'routability' => (string) ($row->routability ?? ''),
            'admission_state' => (string) ($admission['state'] ?? ''),
            'phase' => (string) ($admission['phase'] ?? ''),
            'attempt' => isset($admission['attempt']) ? (int) $admission['attempt'] : null,
            'normal_routing' => (bool) ($admission['normal_routing'] ?? false),
            'exact_provider_policy' => $this->exactProviderPolicy($config),
            'max_concurrent' => isset($row->max_concurrent) ? (int) $row->max_concurrent : null,
            'rate_limit_rpm' => isset($row->rate_limit_rpm) ? (int) $row->rate_limit_rpm : null,
            'tools_enabled' => (bool) data_get($config, 'route_policy.tools_enabled', true),
            'fallback_enabled' => (bool) data_get($config, 'route_policy.allow_generic_fallback', true),
            'thinking_enabled' => (bool) data_get($config, 'chat_template_kwargs.enable_thinking', true),
        ];
    }

    /** @return array<string,mixed> */
    public function open(bool $retry = false): array
    {
        DB::transaction(function () use ($retry): void {
            $row = $this->providerRow(true);
            $config = $this->decodeConfig($row->config ?? '{}');
            $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
            $expectedPhase = $retry ? 'K3-ALT-04-complete' : 'K3-ALT-03-staged';
            $previousAttempt = $admission['attempt'] ?? null;

            if ((int) ($row->is_active ?? 0) !== 0
                || (int) ($row->is_healthy ?? 0) !== 0
                || (string) ($row->routability ?? '') !== 'bench_only'
                || (string) ($admission['state'] ?? '') !== 'staged'
                || (string) ($admission['phase'] ?? '') !== $expectedPhase
            ) {
                throw new RuntimeException($retry
                    ? 'Qwen3.8 retry admission requires exact K3-ALT-04 completion state.'
                    : 'Qwen3.8 admission requires exact K3-ALT-03 staged state.');
            }
            if (! $this->exactProviderPolicy($config)) {
                throw new RuntimeException('Qwen3.8 exact-provider policy has drifted.');
            }
            if ($retry && (! is_int($previousAttempt) || $previousAttempt < 1)) {
                throw new RuntimeException('Qwen3.8 retry admission requires a positive prior attempt counter.');
            }
            if (isset($row->max_concurrent) && (int) $row->max_concurrent !== 1) {
                throw new RuntimeException('Qwen3.8 admission requires exactly one provider slot.');
            }
            if (isset($row->rate_limit_rpm) && (int) $row->rate_limit_rpm !== 5) {
                throw new RuntimeException('Qwen3.8 admission requires the exact five-request canary limit.');
            }

            $config['admission'] = array_merge($admission, $this->admissionPayload(
                'plos_canary',
                'K3-ALT-04-live',
                $retry ? $previousAttempt + 1 : 1
            ));
            DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'))->update(
                $this->filterColumns([
                    'is_active' => 1,
                    'is_healthy' => 1,
                    'health_score' => 80,
                    'routability' => 'allowed',
                    'compat_runtime_family' => 'llama-cpp-01818e49-qwen38',
                    'compat_status' => 'provisional',
                    'circuit_state' => 'closed',
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'notes' => 'K3-ALT-04 temporary exact-provider Qwen3.8 admission; normal routing, tools, thinking, and fallback remain disabled.',
                    'last_health_check' => now(),
                    'updated_at' => now(),
                ])
            );
        });
        $this->clearCaches();

        return $this->status();
    }

    /** @return array<string,mixed> */
    public function close(): array
    {
        DB::transaction(function (): void {
            $row = $this->providerRow(true);
            $config = $this->decodeConfig($row->config ?? '{}');
            $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
            $closingLiveGate = (string) ($admission['phase'] ?? '') === 'K3-ALT-04-live';
            $attempt = isset($admission['attempt']) ? (int) $admission['attempt'] : null;
            $config['route_policy'] = array_merge(
                is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [],
                [
                    'allowed_model_roles' => ['deep_offline'],
                    'require_model_role' => true,
                    'require_explicit_instance' => true,
                    'allow_generic_fallback' => false,
                    'tools_enabled' => false,
                ]
            );
            $config['enable_thinking'] = false;
            $config['chat_template_kwargs'] = ['enable_thinking' => false];
            $config['admission'] = array_merge($admission, $this->admissionPayload(
                'staged',
                $closingLiveGate ? 'K3-ALT-04-complete' : (string) ($admission['phase'] ?? ''),
                $attempt
            ));

            DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'))->update(
                $this->filterColumns([
                    'is_active' => 0,
                    'is_healthy' => 0,
                    'health_score' => 0,
                    'routability' => 'bench_only',
                    'compat_runtime_family' => 'llama-cpp-01818e49-qwen38',
                    'compat_status' => 'provisional',
                    'circuit_state' => 'closed',
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'notes' => $closingLiveGate
                        ? 'Disabled Qwen3.8 candidate on .87; K3-ALT-04 temporary admission completed and closed.'
                        : 'Disabled Qwen3.8 candidate on .87; temporary admission remains closed.',
                    'last_health_check' => null,
                    'updated_at' => now(),
                ])
            );
        });
        $this->clearCaches();

        return $this->status();
    }

    private function providerRow(bool $lock = false): object
    {
        if (! Schema::hasTable('llm_instances')) {
            throw new RuntimeException('llm_instances table is unavailable.');
        }
        $query = DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'));
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (! $row) {
            throw new RuntimeException('The staged Qwen3.8 provider row is missing.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $value): array
    {
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $config */
    private function exactProviderPolicy(array $config): bool
    {
        $policy = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
        $structured = is_array($config['structured_output'] ?? null) ? $config['structured_output'] : [];

        return ($config['adapter'] ?? null) === 'openai_compatible'
            && ($config['transport'] ?? null) === 'openai_sse_monitored'
            && ($config['default_model'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'model')
            && ($config['models']['deep_offline'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'model')
            && ($config['extra_headers']['Host'] ?? null) === '127.0.0.1'
            && ($policy['allowed_model_roles'] ?? null) === ['deep_offline']
            && ($policy['require_model_role'] ?? null) === true
            && ($policy['require_explicit_instance'] ?? null) === true
            && ($policy['allow_generic_fallback'] ?? null) === false
            && ($policy['tools_enabled'] ?? null) === false
            && ($structured['enabled'] ?? null) === true
            && ($structured['transport'] ?? null) === 'openai_json_schema'
            && ($structured['local_validation'] ?? null) === true
            && ($structured['fail_closed'] ?? null) === true
            && ($structured['models'][app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'model')] ?? null) === true
            && ($config['chat_template_kwargs']['enable_thinking'] ?? null) === false
            && (int) ($config['max_tokens'] ?? 0) === 128;
    }

    /** @return array<string,mixed> */
    private function admissionPayload(string $state, string $phase, ?int $attempt): array
    {
        return [
            'state' => $state,
            'phase' => $phase,
            'attempt' => $attempt,
            'runtime_commit' => self::RUNTIME_COMMIT,
            'model_sha256' => self::MODEL_SHA256,
            'backend' => 'cpu_only',
            'threads' => 12,
            'parallel' => 1,
            'normal_routing' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'thinking_enabled' => false,
            'batch_case_count' => 5,
            'max_tokens' => 128,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function filterColumns(array $payload): array
    {
        return array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn('llm_instances', $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
        Cache::forget('rpm_count_'.app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'));
        Cache::forget('rate_limit_'.app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'));
    }
}

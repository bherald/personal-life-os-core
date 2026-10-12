<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class Qwen38DurableAdmissionService
{
    private const INSTANCE_ID = 'qwen38_87';

    private const MODEL = 'qwen3.8-27b-q4_k_m';

    private const PROFILE = 'deep_offline_qwen38';

    private const ATTEMPT = 8;

    private const ALT05_RECEIPT_SHA256 = 'eac9e290ad2f97e5e6b5b78185e61930609f677098ad1e4ecb2915ef69ad6d54';

    private const MODEL_SHA256 = '31629f53165ab6a7dad8c9847dcfd1fdf55829dac1e6e748f4a68581b0033d34';

    private const RUNTIME_COMMIT = '01818e4956858fe225a6875aae88b4cbee0b319e';

    /** @return array<string,mixed> */
    public function status(): array
    {
        $provider = $this->providerRow();
        $profile = $this->profileRow();
        $registry = $this->registryRow((int) $provider->id);
        $config = $this->decode($provider->config ?? '{}');
        $metadata = $this->decode($profile->metadata ?? '{}');
        $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
        $durable = (bool) ($provider->is_active ?? false)
            && (bool) ($provider->is_healthy ?? false)
            && (string) ($provider->routability ?? '') === 'allowed'
            && (string) ($admission['state'] ?? '') === 'durable'
            && (string) ($admission['phase'] ?? '') === 'K3-ALT-06-live'
            && (bool) ($admission['normal_routing'] ?? false)
            && (bool) ($profile->enabled ?? false)
            && (string) ($profile->status ?? '') === 'active'
            && ! (bool) ($profile->requires_override ?? true)
            && (bool) ($registry->is_available ?? false)
            && (string) ($registry->status ?? '') === 'vetted';

        return [
            'schema' => 'plos.qwen38_durable_admission.v1',
            'instance_id' => self::INSTANCE_ID,
            'profile' => self::PROFILE,
            'model' => self::MODEL,
            'is_active' => (bool) ($provider->is_active ?? false),
            'is_healthy' => (bool) ($provider->is_healthy ?? false),
            'routability' => (string) ($provider->routability ?? ''),
            'admission_state' => (string) ($admission['state'] ?? ''),
            'phase' => (string) ($admission['phase'] ?? ''),
            'attempt' => isset($admission['attempt']) ? (int) $admission['attempt'] : null,
            'normal_routing' => (bool) ($admission['normal_routing'] ?? false),
            'profile_enabled' => (bool) ($profile->enabled ?? false),
            'profile_status' => (string) ($profile->status ?? ''),
            'profile_requires_override' => (bool) ($profile->requires_override ?? true),
            'registry_available' => (bool) ($registry->is_available ?? false),
            'registry_status' => (string) ($registry->status ?? ''),
            'exact_provider_policy' => $this->exactProviderPolicy($config),
            'exact_profile_policy' => $this->exactProfilePolicy($metadata),
            'alt05_receipt_exact' => ($admission['alt05_receipt_sha256'] ?? null) === self::ALT05_RECEIPT_SHA256 || $this->receiptExact(),
            'max_concurrent' => isset($provider->max_concurrent) ? (int) $provider->max_concurrent : null,
            'rate_limit_rpm' => isset($provider->rate_limit_rpm) ? (int) $provider->rate_limit_rpm : null,
            'tools_enabled' => (bool) data_get($config, 'route_policy.tools_enabled', true),
            'fallback_enabled' => (bool) data_get($config, 'route_policy.allow_generic_fallback', true),
            'thinking_enabled' => (bool) data_get($config, 'chat_template_kwargs.enable_thinking', true),
            'durable' => $durable,
        ];
    }

    /** @return array<string,mixed> */
    public function promote(): array
    {
        if (! $this->receiptExact()) {
            throw new RuntimeException('The exact green ALT-05 receipt is required for durable promotion.');
        }
        $this->assertHealth();
        DB::transaction(function (): void {
            $provider = $this->providerRow(true);
            $profile = $this->profileRow(true);
            $registry = $this->registryRow((int) $provider->id, true);
            $config = $this->decode($provider->config ?? '{}');
            $metadata = $this->decode($profile->metadata ?? '{}');
            $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
            if ((int) ($provider->is_active ?? 0) !== 0
                || (int) ($provider->is_healthy ?? 0) !== 0
                || (string) ($provider->routability ?? '') !== 'bench_only'
                || (string) ($admission['state'] ?? '') !== 'staged'
                || ! in_array((string) ($admission['phase'] ?? ''), ['K3-ALT-04-complete', 'K3-ALT-06-rollback'], true)
                || (int) ($admission['attempt'] ?? 0) !== self::ATTEMPT
                || (int) ($profile->enabled ?? 0) !== 0
                || (string) ($profile->status ?? '') !== 'staged'
                || (int) ($registry->is_available ?? 0) !== 0
            ) {
                throw new RuntimeException('Qwen3.8 durable promotion requires the exact cleaned ALT-05 state.');
            }
            if (! $this->exactProviderPolicy($config) || ! $this->exactProfilePolicy($metadata)) {
                throw new RuntimeException('Qwen3.8 durable promotion policy has drifted.');
            }

            $config['route_policy']['mode'] = 'operator_approved_deep_offline';
            $config['admission'] = array_merge($admission, $this->admissionPayload('durable', 'K3-ALT-06-live', true));
            $metadata['admission_state'] = 'durable';
            $metadata['operator_approval'] = [
                'decision' => 'operator_rtc_override_2026_08_18',
                'availability' => 'healthy_only',
                'alt05_receipt_sha256' => self::ALT05_RECEIPT_SHA256,
            ];

            DB::table('llm_instances')->where('id', $provider->id)->update($this->filterColumns('llm_instances', [
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 90,
                'routability' => 'allowed',
                'compat_status' => 'authoritative',
                'circuit_state' => 'closed',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => 'K3-ALT-06 durable Qwen3.8 deep-offline route; exact provider, no tools, thinking, or fallback.',
                'last_health_check' => now(),
                'updated_at' => now(),
            ]));
            DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->update($this->filterColumns('llm_model_profiles', [
                'enabled' => 1,
                'status' => 'active',
                'requires_override' => 0,
                'capabilities' => json_encode(['text_input', 'text_output', 'streaming', 'structured_output', 'local_private'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => 'Operator-approved Qwen3.8 deep-offline route; healthy-only exact provider with no fallback or tools.',
                'vetted_at' => now(),
                'updated_at' => now(),
            ]));
            DB::table('ollama_models')->where('id', $registry->id)->update($this->filterColumns('ollama_models', [
                'status' => 'vetted',
                'is_available' => 1,
                'capabilities' => json_encode(['text_input', 'text_output', 'streaming', 'structured_output', 'local_private'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'vetting_notes' => 'K3-ALT-05 green '.$this->shortReceipt().'; K3-ALT-06 durable exact route.',
                'vetted_by' => 'operator-rtc-override-2026-08-18',
                'vetted_at' => now(),
                'last_seen_at' => now(),
                'updated_at' => now(),
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
            $profile = $this->profileRow(true);
            $registry = $this->registryRow((int) $provider->id, true);
            $config = $this->decode($provider->config ?? '{}');
            $metadata = $this->decode($profile->metadata ?? '{}');
            $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
            if (($admission['phase'] ?? null) !== 'K3-ALT-06-live'
                || ($admission['alt05_receipt_sha256'] ?? null) !== self::ALT05_RECEIPT_SHA256
                || ($metadata['operator_approval']['alt05_receipt_sha256'] ?? null) !== self::ALT05_RECEIPT_SHA256
            ) {
                throw new RuntimeException('Refusing to roll back Qwen3.8 state not owned by K3-ALT-06.');
            }
            $config['route_policy']['mode'] = 'narrow';
            $config['admission'] = array_merge($admission, $this->admissionPayload('staged', 'K3-ALT-06-rollback', false));
            $metadata['admission_state'] = 'staged';
            unset($metadata['operator_approval']);

            DB::table('llm_instances')->where('id', $provider->id)->update($this->filterColumns('llm_instances', [
                'is_active' => 0,
                'is_healthy' => 0,
                'health_score' => 0,
                'routability' => 'bench_only',
                'compat_status' => 'provisional',
                'circuit_state' => 'closed',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => 'K3-ALT-06 Qwen3.8 durable route rolled back to staged.',
                'last_health_check' => null,
                'updated_at' => now(),
            ]));
            DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->update($this->filterColumns('llm_model_profiles', [
                'enabled' => 0,
                'status' => 'staged',
                'requires_override' => 1,
                'capabilities' => json_encode(['text_input', 'text_output', 'streaming', 'structured_output', 'local_candidate'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => 'Inactive K3-ALT-06 Qwen3.8 durable-route profile.',
                'vetted_at' => null,
                'updated_at' => now(),
            ]));
            DB::table('ollama_models')->where('id', $registry->id)->update($this->filterColumns('ollama_models', [
                'status' => 'testing',
                'is_available' => 0,
                'capabilities' => json_encode(['text_input', 'text_output', 'streaming', 'structured_output', 'local_candidate'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'vetting_notes' => 'K3-ALT-06-staged',
                'vetted_by' => 'k3-alt-05-green-awaiting-alt-06',
                'vetted_at' => null,
                'last_seen_at' => null,
                'updated_at' => now(),
            ]));
        });
        $this->clearCaches();

        return $this->status();
    }

    private function assertHealth(): void
    {
        $provider = $this->providerRow();
        $config = $this->decode($provider->config ?? '{}');
        $base = rtrim((string) ($config['health_base_url'] ?? ''), '/');
        if ($base === '') {
            throw new RuntimeException('Qwen3.8 health endpoint is missing.');
        }
        $response = Http::connectTimeout(5)->timeout(15)->get($base.'/health');
        if (! $response->successful() || (string) $response->json('status') !== 'ok') {
            throw new RuntimeException('Qwen3.8 durable promotion requires a healthy .87 sidecar.');
        }
    }

    /** @param array<string,mixed> $config */
    private function exactProviderPolicy(array $config): bool
    {
        return ($config['adapter'] ?? null) === 'openai_compatible'
            && ($config['transport'] ?? null) === 'openai_sse_monitored'
            && ($config['default_model'] ?? null) === self::MODEL
            && ($config['models']['deep_offline'] ?? null) === self::MODEL
            && ($config['extra_headers']['Host'] ?? null) === '127.0.0.1'
            && ($config['route_policy']['allowed_model_roles'] ?? null) === ['deep_offline']
            && ($config['route_policy']['require_model_role'] ?? null) === true
            && ($config['route_policy']['require_explicit_instance'] ?? null) === true
            && ($config['route_policy']['allow_generic_fallback'] ?? null) === false
            && ($config['route_policy']['tools_enabled'] ?? null) === false
            && ($config['structured_output']['enabled'] ?? null) === true
            && ($config['structured_output']['fail_closed'] ?? null) === true
            && ($config['chat_template_kwargs']['enable_thinking'] ?? null) === false
            && (int) ($config['max_tokens'] ?? 0) === 128;
    }

    /** @param array<string,mixed> $metadata */
    private function exactProfilePolicy(array $metadata): bool
    {
        return ($metadata['instance_id'] ?? null) === self::INSTANCE_ID
            && ($metadata['required_provider'] ?? null) === true
            && ($metadata['route'] ?? null) === 'required_local_provider_no_fallback'
            && ($metadata['tools_enabled'] ?? null) === false
            && ($metadata['fallback_enabled'] ?? null) === false
            && ($metadata['runtime']['backend'] ?? null) === 'cpu_only'
            && (int) ($metadata['runtime']['max_tokens'] ?? 0) === 128
            && ($metadata['stage_marker'] ?? null) === 'K3-ALT-06-staged';
    }

    /** @return array<string,mixed> */
    private function admissionPayload(string $state, string $phase, bool $normalRouting): array
    {
        return [
            'state' => $state,
            'phase' => $phase,
            'attempt' => self::ATTEMPT,
            'runtime_commit' => self::RUNTIME_COMMIT,
            'model_sha256' => self::MODEL_SHA256,
            'alt05_receipt_sha256' => self::ALT05_RECEIPT_SHA256,
            'backend' => 'cpu_only',
            'threads' => 12,
            'parallel' => 1,
            'normal_routing' => $normalRouting,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'thinking_enabled' => false,
            'max_tokens' => 128,
        ];
    }

    private function receiptExact(): bool
    {
        $path = base_path('scripts/patches/colibri/k3-selective-v162/K3-ALT-05-ATTEMPT-8-RECEIPT.json');

        return is_file($path) && hash_file('sha256', $path) === self::ALT05_RECEIPT_SHA256;
    }

    private function shortReceipt(): string
    {
        return substr(self::ALT05_RECEIPT_SHA256, 0, 8).'...'.substr(self::ALT05_RECEIPT_SHA256, -4);
    }

    private function providerRow(bool $lock = false): object
    {
        if (! Schema::hasTable('llm_instances')) {
            throw new RuntimeException('llm_instances table is unavailable.');
        }
        $query = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new RuntimeException('The staged Qwen3.8 provider row is missing.');
    }

    private function profileRow(bool $lock = false): object
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            throw new RuntimeException('llm_model_profiles table is unavailable.');
        }
        $query = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new RuntimeException('The staged Qwen3.8 profile is missing.');
    }

    private function registryRow(int $instanceId, bool $lock = false): object
    {
        if (! Schema::hasTable('ollama_models')) {
            throw new RuntimeException('ollama_models table is unavailable.');
        }
        $query = DB::table('ollama_models')->where('instance_id', $instanceId)->where('model_name', self::MODEL);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new RuntimeException('The staged Qwen3.8 registry row is missing.');
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter($payload, static fn (string $column): bool => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers', 'llm_model_profiles', 'ollama_available_models', 'ollama_vetted_models'] as $key) {
            Cache::forget($key);
        }
        Cache::forget('rpm_count_'.self::INSTANCE_ID);
        Cache::forget('rate_limit_'.self::INSTANCE_ID);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class KimiK3ScorecardAdmissionService
{
    private const PROFILE = 'deep_offline_kimi_k3';

    private const RUNTIME = 'dev-33302b8-kimi-k3-mmap-prefix-v162-cancelacct-cpu';

    private const PATCH_SHA256 = '11d362dd53381dfded4a168fc3c07070597f0265a27bfbdb1452e2fbb0178adb';

    private const PATCHED_TREE = 'e49b98fdfdd44e8e12e2f026b651fc1cd006f2b1';

    private const CONTEXT_RECEIPT_SHA256 = '8a73b228ece6ca8ff412a05d51003c8cf74af227780ed9411e6b8b9957620241';

    /** @return array<string,mixed> */
    public function status(): array
    {
        $row = $this->providerRow();
        $config = $this->decodeConfig($row->config ?? '{}');
        $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];

        return [
            'schema' => 'plos.kimi_k3_scorecard_admission.v1',
            'instance_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'is_active' => (bool) ($row->is_active ?? false),
            'is_healthy' => (bool) ($row->is_healthy ?? false),
            'routability' => (string) ($row->routability ?? ''),
            'admission_state' => (string) ($admission['state'] ?? ''),
            'phase' => (string) ($admission['phase'] ?? ''),
            'batch_number' => isset($admission['batch_number']) ? (int) $admission['batch_number'] : null,
            'attempt' => isset($admission['attempt']) ? (int) $admission['attempt'] : null,
            'normal_routing' => (bool) ($admission['normal_routing'] ?? false),
            'exact_provider_policy' => $this->exactProviderPolicy($config),
            'profile_gated' => $this->profileGated(),
            'max_concurrent' => isset($row->max_concurrent) ? (int) $row->max_concurrent : null,
            'rate_limit_rpm' => isset($row->rate_limit_rpm) ? (int) $row->rate_limit_rpm : null,
            'tools_enabled' => (bool) data_get($config, 'route_policy.tools_enabled', true),
            'fallback_enabled' => (bool) data_get($config, 'route_policy.allow_generic_fallback', true),
        ];
    }

    /** @return array<string,mixed> */
    public function open(bool $retry = false, int $batch = 1): array
    {
        if ($batch < 1 || $batch > 4) {
            throw new RuntimeException('K3-05 admission batch must be an integer from 1 through 4.');
        }

        DB::transaction(function () use ($retry, $batch): void {
            $row = $this->providerRow(true);
            $config = $this->decodeConfig($row->config ?? '{}');
            $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
            $phase = (string) ($admission['phase'] ?? '');
            $expectedPhases = $retry
                ? ($batch === 1
                    ? ['K3-05-batch-1-complete']
                    : ['K3-05-batch-'.($batch - 1).'-complete', "K3-05-batch-{$batch}-complete"])
                : ['K3-04-complete'];
            $previousAttempt = $admission['attempt'] ?? null;

            if ((int) ($row->is_active ?? 0) !== 0
                || (int) ($row->is_healthy ?? 0) !== 0
                || (string) ($row->routability ?? '') !== 'bench_only'
                || (string) ($admission['state'] ?? '') !== 'staged'
                || ! in_array($phase, $expectedPhases, true)
            ) {
                throw new RuntimeException($retry
                    ? "K3-05 batch {$batch} admission requires its exact prior or same-batch completion state."
                    : 'K3-05 admission requires the exact closed K3-04 completion state; a completed batch needs --retry and new approval.');
            }
            if (! $this->exactProviderPolicy($config) || ! $this->profileGated()) {
                throw new RuntimeException('K3-05 exact-provider or normal-profile policy has drifted.');
            }
            if ($retry && (! is_int($previousAttempt) || $previousAttempt < 1)) {
                throw new RuntimeException('K3-05 retry admission requires an exact positive prior attempt counter.');
            }
            if (isset($row->max_concurrent) && (int) $row->max_concurrent !== 1) {
                throw new RuntimeException('K3-05 requires exactly one provider slot.');
            }
            if (isset($row->rate_limit_rpm) && (int) $row->rate_limit_rpm !== 1) {
                throw new RuntimeException('K3-05 requires a one-request-per-minute provider limit.');
            }

            $config['admission'] = array_merge(
                $admission,
                $this->admissionPayload('scorecard_batch', "K3-05-batch-{$batch}", $batch),
                ['attempt' => $retry ? $previousAttempt + 1 : 1]
            );
            DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'))->update(
                $this->filterColumns([
                    'is_active' => 1,
                    'is_healthy' => 1,
                    'health_score' => 80,
                    'routability' => 'allowed',
                    'compat_runtime_family' => 'colibri-k3-v162-cancel-acct',
                    'compat_status' => 'authoritative',
                    'circuit_state' => 'closed',
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'notes' => "K3-05 batch {$batch} temporary exact-provider scorecard admission; normal routing, tools, and fallback remain disabled.",
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
            $phase = (string) ($admission['phase'] ?? '');
            $closingLiveBatch = preg_match('/^K3-05-batch-([1-4])$/', $phase, $matches) === 1;
            $batch = $closingLiveBatch ? (int) $matches[1] : null;
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
            $config['admission'] = array_merge($admission, $this->admissionPayload(
                'staged',
                $closingLiveBatch ? "K3-05-batch-{$batch}-complete" : $phase,
                $batch
            ));

            DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'))->update(
                $this->filterColumns([
                    'is_active' => 0,
                    'is_healthy' => 0,
                    'health_score' => 0,
                    'routability' => 'bench_only',
                    'compat_runtime_family' => 'colibri-k3-47a749d',
                    'compat_status' => 'provisional',
                    'circuit_state' => 'closed',
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'notes' => $closingLiveBatch
                        ? "Disabled Kimi K3 candidate on .87; K3-05 batch {$batch} completed and temporary admission closed."
                        : 'Disabled Kimi K3 candidate on .87; temporary admission is closed without consuming the K3-05 batch gate.',
                    'last_health_check' => null,
                    'updated_at' => now(),
                ])
            );

            if (Schema::hasTable('llm_model_profiles')) {
                DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->update(
                    array_filter([
                        'enabled' => 0,
                        'status' => 'staged',
                        'requires_override' => 1,
                        'updated_at' => now(),
                    ], static fn (string $column): bool => Schema::hasColumn('llm_model_profiles', $column), ARRAY_FILTER_USE_KEY)
                );
            }
        });
        $this->clearCaches();

        return $this->status();
    }

    private function providerRow(bool $lock = false): object
    {
        if (! Schema::hasTable('llm_instances')) {
            throw new RuntimeException('llm_instances table is unavailable.');
        }

        $query = DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'));
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (! $row) {
            throw new RuntimeException('The staged Kimi K3 provider row is missing.');
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

        return ($config['adapter'] ?? null) === 'openai_compatible'
            && ($config['transport'] ?? null) === 'openai_sse_monitored'
            && ($config['default_model'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            && ($config['models']['deep_offline'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            && ($config['extra_headers']['Host'] ?? null) === '127.0.0.1'
            && ($policy['allowed_model_roles'] ?? null) === ['deep_offline']
            && ($policy['require_model_role'] ?? null) === true
            && ($policy['require_explicit_instance'] ?? null) === true
            && ($policy['allow_generic_fallback'] ?? null) === false
            && ($policy['tools_enabled'] ?? null) === false
            && (int) ($config['max_tokens'] ?? 0) >= 128;
    }

    private function profileGated(): bool
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return false;
        }

        $profile = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->first();

        return $profile !== null
            && (int) ($profile->enabled ?? 1) === 0
            && (string) ($profile->status ?? '') === 'staged'
            && (int) ($profile->requires_override ?? 0) === 1;
    }

    /** @return array<string,mixed> */
    private function admissionPayload(string $state, string $phase, ?int $batch = null): array
    {
        return [
            'state' => $state,
            'phase' => $phase,
            'batch_number' => $batch,
            'runtime' => self::RUNTIME,
            'patch_sha256' => self::PATCH_SHA256,
            'patched_tree' => self::PATCHED_TREE,
            'context_receipt_sha256' => self::CONTEXT_RECEIPT_SHA256,
            'baseline' => 'cpu_no_fallback',
            'normal_routing' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
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
    }
}

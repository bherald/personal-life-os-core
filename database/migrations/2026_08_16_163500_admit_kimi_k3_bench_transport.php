<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'kimi_k3_87';

    private const RUNTIME = 'dev-33302b8-kimi-k3-mmap-prefix-v162-cancelacct-cpu';

    private const COMPAT_RUNTIME_FAMILY = 'colibri-k3-v162-cancel-acct';

    private const PREVIOUS_COMPAT_RUNTIME_FAMILY = 'colibri-k3-47a749d';

    private const COMPAT_STATUS = 'authoritative';

    private const PREVIOUS_COMPAT_STATUS = 'provisional';

    private const PATCH_SHA256 = '11d362dd53381dfded4a168fc3c07070597f0265a27bfbdb1452e2fbb0178adb';

    private const PATCHED_TREE = 'e49b98fdfdd44e8e12e2f026b651fc1cd006f2b1';

    private const CONTEXT_RECEIPT_SHA256 = '8a73b228ece6ca8ff412a05d51003c8cf74af227780ed9411e6b8b9957620241';

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if (! $row) {
            throw new RuntimeException('The staged Kimi K3 provider row is missing.');
        }

        $config = json_decode((string) ($row->config ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertExactProviderPolicy($config);
        $this->assertProfileRemainsGated();
        $config['admission'] = array_merge(
            is_array($config['admission'] ?? null) ? $config['admission'] : [],
            [
                'state' => 'bench_transport',
                'phase' => 'K3-04',
                'runtime' => self::RUNTIME,
                'patch_sha256' => self::PATCH_SHA256,
                'patched_tree' => self::PATCHED_TREE,
                'context_receipt_sha256' => self::CONTEXT_RECEIPT_SHA256,
                'baseline' => 'cpu_no_fallback',
                'normal_routing' => false,
            ]
        );

        $now = now();
        DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->update(
            $this->filterColumns('llm_instances', [
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 80,
                'routability' => 'allowed',
                'compat_runtime_family' => self::COMPAT_RUNTIME_FAMILY,
                'compat_status' => self::COMPAT_STATUS,
                'circuit_state' => 'closed',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => 'K3-04 bench-only exact-provider transport. Requires explicit kimi_k3_87 selection; tools and fallback remain disabled.',
                'last_health_check' => $now,
                'updated_at' => $now,
            ])
        );

        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if (! $row) {
            return;
        }

        $config = json_decode((string) ($row->config ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        $config['admission'] = array_merge(
            is_array($config['admission'] ?? null) ? $config['admission'] : [],
            [
                'state' => 'staged',
                'phase' => 'K3-03C-complete',
                'runtime' => self::RUNTIME,
                'patch_sha256' => self::PATCH_SHA256,
                'patched_tree' => self::PATCHED_TREE,
                'context_receipt_sha256' => self::CONTEXT_RECEIPT_SHA256,
                'baseline' => 'cpu_no_fallback',
                'normal_routing' => false,
            ]
        );

        DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->update(
            $this->filterColumns('llm_instances', [
                'is_active' => 0,
                'is_healthy' => 0,
                'health_score' => 0,
                'routability' => 'bench_only',
                'compat_runtime_family' => self::PREVIOUS_COMPAT_RUNTIME_FAMILY,
                'compat_status' => self::PREVIOUS_COMPAT_STATUS,
                'circuit_state' => 'closed',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => 'Disabled Kimi K3 Colibri candidate on .87; K3-03C context qualification retained.',
                'last_health_check' => null,
                'updated_at' => now(),
            ])
        );

        $this->clearCaches();
    }

    /** @param array<string,mixed> $config */
    private function assertExactProviderPolicy(array $config): void
    {
        $policy = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
        $roles = is_array($policy['allowed_model_roles'] ?? null)
            ? array_values($policy['allowed_model_roles'])
            : [];

        if ($roles !== ['deep_offline']
            || ($config['adapter'] ?? null) !== 'openai_compatible'
            || ($config['transport'] ?? null) !== 'openai_sse_monitored'
            || ($config['extra_headers']['Host'] ?? null) !== '127.0.0.1'
            || ($policy['require_model_role'] ?? null) !== true
            || ($policy['require_explicit_instance'] ?? null) !== true
            || ($policy['allow_generic_fallback'] ?? null) !== false
            || ($policy['tools_enabled'] ?? null) !== false
        ) {
            throw new RuntimeException('The Kimi K3 exact-provider route policy has drifted.');
        }
    }

    private function assertProfileRemainsGated(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }

        $profile = DB::table('llm_model_profiles')
            ->where('profile_name', 'deep_offline_kimi_k3')
            ->first(['enabled', 'status', 'requires_override']);
        if (! $profile
            || (int) $profile->enabled !== 0
            || (string) $profile->status !== 'staged'
            || (int) $profile->requires_override !== 1
        ) {
            throw new RuntimeException('The Kimi K3 normal profile gate has drifted.');
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }
};

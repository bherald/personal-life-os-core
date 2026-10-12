<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'kimi_k3_87';

    private const RUNTIME = 'dev-33302b8-kimi-k3-mmap-prefix-v162-cancelacct-cpu';

    private const COMPAT_RUNTIME_FAMILY = 'colibri-k3-47a749d';

    private const PATCH_SHA256 = '11d362dd53381dfded4a168fc3c07070597f0265a27bfbdb1452e2fbb0178adb';

    private const PATCHED_TREE = 'e49b98fdfdd44e8e12e2f026b651fc1cd006f2b1';

    private const CONTEXT_RECEIPT_SHA256 = '8a73b228ece6ca8ff412a05d51003c8cf74af227780ed9411e6b8b9957620241';

    public function up(): void
    {
        $this->enforceClosedGate();
    }

    public function down(): void
    {
        // K3-04 was temporary. Migration rollback must never re-admit it.
        $this->enforceClosedGate();
    }

    private function enforceClosedGate(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if (! $row) {
            throw new RuntimeException('The Kimi K3 provider row is missing during K3-04 closure.');
        }

        $config = json_decode((string) ($row->config ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        $config['admission'] = array_merge(
            is_array($config['admission'] ?? null) ? $config['admission'] : [],
            [
                'state' => 'staged',
                'phase' => 'K3-04-complete',
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
                'compat_runtime_family' => self::COMPAT_RUNTIME_FAMILY,
                'compat_status' => 'provisional',
                'circuit_state' => 'closed',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => 'Disabled Kimi K3 candidate on .87; K3-04 temporary transport passed and cleaned.',
                'last_health_check' => null,
                'updated_at' => now(),
            ])
        );

        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
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
};

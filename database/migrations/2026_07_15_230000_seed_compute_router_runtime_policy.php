<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESCRIPTION = 'Table-owned liveness, routing, circuit, timeout, and health policy for shared compute workers.';

    private const FILE_MARKER = 'class ComputeRouterRuntimePolicyService';

    private const LINE_MARKER = '$this->runtimePolicy->';

    private const DEFAULT_POLICY = [
        'cache_ttl_seconds' => 300,
        'execution_timeout_seconds' => 30,
        'ssh_connect_timeout_seconds' => 5,
        'local_gpu_probe_timeout_seconds' => 10,
        'local_jetson_probe_timeout_seconds' => 5,
        'remote_health_timeout_seconds' => 15,
        'health_freshness_seconds' => 93600,
        'circuit_failure_threshold' => 5,
        'circuit_cooldown_seconds' => 60,
        'health_success_delta' => 5,
        'health_failure_delta' => 10,
        'unhealthy_threshold' => 30,
        'routing_weight_priority' => 0.32,
        'routing_weight_health' => 0.24,
        'routing_weight_speed' => 0.24,
        'routing_weight_load' => 0.20,
        'priority_reference' => 50,
        'unknown_latency_ms' => 5000,
        'speed_reference_ms' => 20000,
        'latency_ema_alpha' => 0.20,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', 'compute')
            ->where('config_key', 'router_runtime_policy')
            ->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => 'compute',
                'config_key' => 'router_runtime_policy',
                'config_value' => json_encode(self::DEFAULT_POLICY, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => self::DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->updateAuditMarkers(true);
        Cache::forget('syscfg:compute.router_runtime_policy');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'compute')
            ->where('config_key', 'router_runtime_policy')
            ->where('description', self::DESCRIPTION)
            ->delete();
        $this->updateAuditMarkers(false);
        Cache::forget('syscfg:compute.router_runtime_policy');
    }

    private function updateAuditMarkers(bool $add): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        $classifier = is_array($policy['classifier'] ?? null) ? $policy['classifier'] : [];
        $fileMarkers = is_array($classifier['file_table_policy_markers'] ?? null)
            ? $classifier['file_table_policy_markers']
            : [];
        $lineMarkers = is_array($classifier['line_table_driven_markers'] ?? null)
            ? $classifier['line_table_driven_markers']
            : [];
        $classifier['file_table_policy_markers'] = $this->markers($fileMarkers, self::FILE_MARKER, $add);
        $classifier['line_table_driven_markers'] = $this->markers($lineMarkers, self::LINE_MARKER, $add);
        $policy['classifier'] = $classifier;

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        Cache::forget('syscfg:ops.runtime_policy_leak_audit');
    }

    /** @return list<string> */
    private function markers(array $markers, string $target, bool $add): array
    {
        $markers = array_values(array_filter(
            array_map(static fn (mixed $marker): string => trim((string) $marker), $markers),
            static fn (string $marker): bool => $marker !== ''
        ));

        return $add
            ? array_values(array_unique([...$markers, $target]))
            : array_values(array_filter($markers, static fn (string $marker): bool => $marker !== $target));
    }
};

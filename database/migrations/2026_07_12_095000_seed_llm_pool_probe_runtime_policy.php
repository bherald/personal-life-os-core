<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESCRIPTION = 'Table-owned timeout windows for LLMPool health, recovery, process, and CLI probes.';

    private const AUDIT_MARKER = 'class LlmPoolProbePolicyService';

    private const DEFAULT_POLICY = [
        'process_lookup_timeout_seconds' => 5,
        'cli_status_timeout_seconds' => 10,
        'http_connect_timeout_seconds' => 5,
        'local_sidecar_connect_timeout_seconds' => 3,
        'health_request_timeout_seconds' => 10,
        'external_health_request_timeout_seconds' => 15,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', 'llm')
            ->where('config_key', 'pool_probe_runtime_policy')
            ->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => 'llm',
                'config_key' => 'pool_probe_runtime_policy',
                'config_value' => json_encode(self::DEFAULT_POLICY, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => self::DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->updateAuditMarker(true);
        Cache::forget('syscfg:llm.pool_probe_runtime_policy');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'llm')
            ->where('config_key', 'pool_probe_runtime_policy')
            ->where('description', self::DESCRIPTION)
            ->delete();
        $this->updateAuditMarker(false);
        Cache::forget('syscfg:llm.pool_probe_runtime_policy');
    }

    private function updateAuditMarker(bool $add): void
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
        $markers = is_array($classifier['file_table_policy_markers'] ?? null)
            ? $classifier['file_table_policy_markers']
            : [];
        $markers = array_values(array_filter(
            array_map(static fn (mixed $marker): string => trim((string) $marker), $markers),
            static fn (string $marker): bool => $marker !== ''
        ));
        $classifier['file_table_policy_markers'] = $add
            ? array_values(array_unique([...$markers, self::AUDIT_MARKER]))
            : array_values(array_filter($markers, static fn (string $marker): bool => $marker !== self::AUDIT_MARKER));
        $policy['classifier'] = $classifier;

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update(['config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        Cache::forget('syscfg:ops.runtime_policy_leak_audit');
    }
};

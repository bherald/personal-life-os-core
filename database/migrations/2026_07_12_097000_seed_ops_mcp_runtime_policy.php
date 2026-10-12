<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESCRIPTION = 'Table-owned retry, probe, and transient issue windows for OpsMCPService.';

    private const DEFAULT_POLICY = [
        'horizon_master_retry_attempts' => 3,
        'horizon_master_retry_delay_seconds' => 2,
        'process_probe_timeout_seconds' => 5,
        'transient_issue_stale_hours' => 6,
    ];

    private const FILE_MARKER = 'class OpsMcpRuntimePolicyService';

    private const LINE_MARKER = '$this->opsRuntimePolicy->';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')->where('section', 'ops')->where('config_key', 'mcp_runtime_policy')->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => 'ops',
                'config_key' => 'mcp_runtime_policy',
                'config_value' => json_encode(self::DEFAULT_POLICY, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => self::DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->updateAuditMarkers(true);
        Cache::forget('syscfg:ops.mcp_runtime_policy');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'mcp_runtime_policy')
            ->where('description', self::DESCRIPTION)
            ->delete();
        $this->updateAuditMarkers(false);
        Cache::forget('syscfg:ops.mcp_runtime_policy');
    }

    private function updateAuditMarkers(bool $add): void
    {
        $row = DB::table('system_configs')->where('section', 'ops')->where('config_key', 'runtime_policy_leak_audit')->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        $classifier = is_array($policy['classifier'] ?? null) ? $policy['classifier'] : [];
        $fileMarkers = is_array($classifier['file_table_policy_markers'] ?? null) ? $classifier['file_table_policy_markers'] : [];
        $lineMarkers = is_array($classifier['line_table_driven_markers'] ?? null) ? $classifier['line_table_driven_markers'] : [];
        $classifier['file_table_policy_markers'] = $this->markers($fileMarkers, self::FILE_MARKER, $add);
        $classifier['line_table_driven_markers'] = $this->markers($lineMarkers, self::LINE_MARKER, $add);
        $policy['classifier'] = $classifier;

        DB::table('system_configs')->where('section', 'ops')->where('config_key', 'runtime_policy_leak_audit')->update([
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

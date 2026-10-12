<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESCRIPTION = 'Table-owned data-broker probe, coverage, and failure-confirmation policy.';

    private const FILE_MARKER = 'class BrokerHealthPolicyService';

    private const LINE_MARKER = '$this->brokerHealthPolicy';

    private const DEFAULT_POLICY = [
        'batch_size' => 80,
        'run_interval_hours' => 24,
        'inter_check_delay_ms' => 500,
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 15,
        'stale_after_hours' => 168,
        'failure_confirmation_checks' => 2,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->insertOrIgnore([
            'section' => 'data_removal',
            'config_key' => 'broker_health_policy',
            'config_value' => json_encode(self::DEFAULT_POLICY, JSON_UNESCAPED_SLASHES),
            'data_type' => 'json',
            'description' => self::DESCRIPTION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->updateAuditMarkers(true);
        Cache::forget('syscfg:data_removal.broker_health_policy');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'data_removal')
            ->where('config_key', 'broker_health_policy')
            ->where('description', self::DESCRIPTION)
            ->delete();
        $this->updateAuditMarkers(false);
        Cache::forget('syscfg:data_removal.broker_health_policy');
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

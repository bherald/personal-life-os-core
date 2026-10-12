<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKER = 'class LlmCircuitBreakerPolicyService';

    public function up(): void
    {
        $this->updateMarker(true);
    }

    public function down(): void
    {
        $this->updateMarker(false);
    }

    private function updateMarker(bool $add): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

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
            ? array_values(array_unique([...$markers, self::MARKER]))
            : array_values(array_filter($markers, static fn (string $marker): bool => $marker !== self::MARKER));
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
};

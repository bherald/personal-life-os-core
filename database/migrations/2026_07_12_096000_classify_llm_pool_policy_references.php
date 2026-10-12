<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKERS = [
        '$this->probePolicy->',
        '$this->circuitBreakerPolicy->',
    ];

    public function up(): void
    {
        $this->updateMarkers(true);
    }

    public function down(): void
    {
        $this->updateMarkers(false);
    }

    private function updateMarkers(bool $add): void
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
        $markers = is_array($classifier['line_table_driven_markers'] ?? null)
            ? $classifier['line_table_driven_markers']
            : [];
        $markers = array_values(array_filter(
            array_map(static fn (mixed $marker): string => trim((string) $marker), $markers),
            static fn (string $marker): bool => $marker !== ''
        ));
        $classifier['line_table_driven_markers'] = $add
            ? array_values(array_unique([...$markers, ...self::MARKERS]))
            : array_values(array_diff($markers, self::MARKERS));
        $policy['classifier'] = $classifier;

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update(['config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        Cache::forget('syscfg:ops.runtime_policy_leak_audit');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);

        $policy = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($policy)) {
            return;
        }

        $classifier = $policy['classifier'] ?? [];
        if (! is_array($classifier)) {
            $classifier = [];
        }

        $markers = $classifier['file_table_policy_markers'] ?? [];
        if (! is_array($markers)) {
            $markers = [];
        }

        $markers[] = 'RUNTIME_POLICY_SOURCE';
        $classifier['file_table_policy_markers'] = array_values(array_unique(array_filter(
            array_map(static fn (mixed $marker): string => trim((string) $marker), $markers),
            static fn (string $marker): bool => $marker !== ''
        )));
        $policy['classifier'] = $classifier;

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => 'runtime_policy_leak_audit'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Observe-only scanner for hardcoded growth limits and runtime policy leaks.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);

        $policy = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $markers = $policy['classifier']['file_table_policy_markers'] ?? [];
        if (! is_array($markers)) {
            return;
        }

        $policy['classifier']['file_table_policy_markers'] = array_values(array_filter(
            $markers,
            static fn (mixed $marker): bool => $marker !== 'RUNTIME_POLICY_SOURCE'
        ));

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const MONITOR_KEY = 'pipeline_monitor_runtime_policy';

    private const MONITOR_POLICY_MARKER = 'ops.pipeline_monitor_runtime_policy';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $this->upsertMonitorPolicy();
        $this->updateAuditPolicy(true);
        Cache::forget('syscfg:ops.'.self::AUDIT_KEY);
        Cache::forget('syscfg:ops.'.self::MONITOR_KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        // Runtime policy and operator finding triage are durable control data.
        // Older code ignores these keys, so rollback must not erase them.
        Cache::forget('syscfg:ops.'.self::AUDIT_KEY);
        Cache::forget('syscfg:ops.'.self::MONITOR_KEY);
    }

    private function upsertMonitorPolicy(): void
    {
        $existing = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::MONITOR_KEY)
            ->first(['config_value']);
        $stored = $existing !== null ? json_decode((string) $existing->config_value, true) : null;
        $policy = array_replace([
            'default_stall_timeout_minutes' => 30,
            'recent_run_window_minutes' => 60,
            'categories' => ['E13-FileRegistry', 'Files', 'File Management'],
        ], is_array($stored) ? $stored : []);

        $values = [
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
            'data_type' => 'json',
            'description' => 'Required stale-job and recent-run scope for pipeline:monitor.',
            'updated_at' => now(),
        ];
        if ($existing !== null) {
            DB::table('system_configs')
                ->where('section', 'ops')
                ->where('config_key', self::MONITOR_KEY)
                ->update($values);

            return;
        }

        DB::table('system_configs')->insert([
            'section' => 'ops',
            'config_key' => self::MONITOR_KEY,
            ...$values,
            'created_at' => now(),
        ]);
    }

    private function updateAuditPolicy(bool $add): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::AUDIT_KEY)
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $classifier = $policy['classifier'];
        $markers = $this->stringList($classifier['file_table_policy_markers'] ?? []);
        $classifier['file_table_policy_markers'] = $add
            ? array_values(array_unique([...$markers, self::MONITOR_POLICY_MARKER]))
            : array_values(array_filter(
                $markers,
                static fn (string $marker): bool => $marker !== self::MONITOR_POLICY_MARKER
            ));

        if ($add) {
            $classifier['finding_overrides'] = is_array($classifier['finding_overrides'] ?? null)
                ? $classifier['finding_overrides']
                : [];
        } else {
            unset($classifier['finding_overrides']);
        }
        $policy['classifier'] = $classifier;

        $classificationPolicy = is_array($policy['classification_policy'] ?? null)
            ? $policy['classification_policy']
            : [];
        if ($add) {
            $classificationPolicy['finding_override'] =
                'A stable finding fingerprint may be reclassified only by exact table entry with an explicit reason.';
            $classificationPolicy['finding_fingerprint'] =
                'SHA-256 over version, relative path, rule, normalized statement, and nearest code context; absolute line number is excluded.';
        } else {
            unset($classificationPolicy['finding_override'], $classificationPolicy['finding_fingerprint']);
        }
        $policy['classification_policy'] = $classificationPolicy;

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::AUDIT_KEY)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }
};

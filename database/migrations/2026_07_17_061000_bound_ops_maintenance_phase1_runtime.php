<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ops';

    private const KEY = 'ops_maintenance_job_runtime_policy';

    private const MARKER = '2026_07_17_061000_bound_ops_maintenance_phase1_runtime';

    /** @return array<string,int> */
    private function values(): array
    {
        return [
            'recursion_purge_retention_days' => 3,
            'recursion_purge_batch_size' => 5000,
            'recursion_purge_max_rows' => 500000,
            'recursion_purge_sleep_ms' => 100,
            'recursion_purge_max_runtime_seconds' => 600,
        ];
    }

    /** @return array<string,string> */
    private function notes(): array
    {
        return [
            'recursion_purge_retention_days' => 'Age threshold for automatic agent_recursion_calls retention inside checkpointed Phase 1.',
            'recursion_purge_batch_size' => 'Indexed delete batch size for one automatic recursion-retention call.',
            'recursion_purge_max_rows' => 'Absolute row ceiling for one automatic recursion-retention call.',
            'recursion_purge_sleep_ms' => 'Pause between committed recursion-retention batches.',
            'recursion_purge_max_runtime_seconds' => 'Wall-clock budget checked between committed batches so Phase 1 can checkpoint before the Horizon timeout.',
            'phase1_checkpointing' => 'Completed Phase 1 steps remain cached across queue retries; a retry resumes at the first incomplete step.',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->first(['id', 'config_value', 'description']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : [];
        $policy = is_array($policy) ? $policy : [];
        $policy['policy_notes'] = is_array($policy['policy_notes'] ?? null) ? $policy['policy_notes'] : [];
        $policy['_migration_extensions'] = is_array($policy['_migration_extensions'] ?? null)
            ? $policy['_migration_extensions']
            : [];

        if (isset($policy['_migration_extensions'][self::MARKER])) {
            return;
        }

        $ownedValues = [];
        foreach ($this->values() as $key => $value) {
            if (! array_key_exists($key, $policy)) {
                $policy[$key] = $value;
                $ownedValues[$key] = $value;
            }
        }

        $ownedNotes = [];
        foreach ($this->notes() as $key => $value) {
            if (! array_key_exists($key, $policy['policy_notes'])) {
                $policy['policy_notes'][$key] = $value;
                $ownedNotes[$key] = $value;
            }
        }

        $policy['_migration_extensions'][self::MARKER] = [
            'values' => $ownedValues,
            'policy_notes' => $ownedNotes,
        ];

        $values = [
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_type' => 'json',
            'description' => $row?->description
                ?: 'Runtime policy for OpsMaintenanceJob queue envelope, continuation checkpointing, and bounded cleanup windows.',
            'updated_at' => now(),
        ];
        if ($row === null) {
            $values['section'] = self::SECTION;
            $values['config_key'] = self::KEY;
            $values['created_at'] = now();
            DB::table('system_configs')->insert($values);
        } else {
            DB::table('system_configs')->where('id', $row->id)->update($values);
        }

        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->first(['id', 'config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        $owned = is_array($policy) ? ($policy['_migration_extensions'][self::MARKER] ?? null) : null;
        if (! is_array($policy) || ! is_array($owned)) {
            return;
        }

        foreach (is_array($owned['values'] ?? null) ? $owned['values'] : [] as $key => $value) {
            if (($policy[$key] ?? null) === $value) {
                unset($policy[$key]);
            }
        }
        foreach (is_array($owned['policy_notes'] ?? null) ? $owned['policy_notes'] : [] as $key => $value) {
            if (($policy['policy_notes'][$key] ?? null) === $value) {
                unset($policy['policy_notes'][$key]);
            }
        }
        unset($policy['_migration_extensions'][self::MARKER]);
        if (($policy['_migration_extensions'] ?? null) === []) {
            unset($policy['_migration_extensions']);
        }
        if (($policy['policy_notes'] ?? null) === []) {
            unset($policy['policy_notes']);
        }

        DB::table('system_configs')->where('id', $row->id)->update([
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }
};

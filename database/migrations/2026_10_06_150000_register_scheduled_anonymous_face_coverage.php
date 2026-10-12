<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NOTES = '{"schema":"anonymous_face_coverage_schedule.v1","policy_source":"files.registry_face_coverage_policy","scope":"registry_images_and_all_tree_media","source_writes":false,"identity_writes":false,"anonymous_generation_writes":true,"automatic_reconciliation":false}';

    public function up(): void
    {
        $definition = $this->definition();
        foreach (['file_registry_image_audit_checkpoints', 'file_registry_image_audit_observations',
            'file_registry_face_generation_runs', 'scheduled_job_runs', 'task_custody_records'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Anonymous coverage requires existing source inspection, generation and scheduler custody.');
            }
        }
        DB::transaction(function () use ($definition): void {
            DB::table('system_configs')->insertOrIgnore([
                'section' => 'files', 'config_key' => 'registry_face_coverage_policy',
                'config_value' => json_encode($definition['policy'], JSON_THROW_ON_ERROR),
                'data_type' => 'json', 'description' => 'Bounded anonymous nightly and incoming coverage; identities and protected generations remain held.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('scheduled_jobs')->insertOrIgnore([
                'name' => $definition['schedule']['name'],
                'description' => 'Track historical/incoming photo coverage and generate only missing anonymous source/engine receipts.',
                'job_type' => 'command', 'command' => $definition['schedule']['command'],
                'cron_expression' => $definition['schedule']['cron_expression'],
                'enabled' => 1, 'run_in_background' => 1, 'without_overlapping' => 1, 'max_parallel' => 1,
                'timeout_minutes' => $definition['policy']['timeout_minutes'], 'timeout_locked' => 1,
                'stall_exempt' => 0, 'category' => 'Maintenance', 'source_module' => 'Files',
                'runtime_mode' => 'maintenance', 'workload_family' => 'files', 'resource_profile' => 'cpu',
                'stall_policy' => 'strict', 'notification_mode' => 'silent', 'notes' => self::NOTES,
                'next_run_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Cache::forget('syscfg:files.registry_face_coverage_policy');
    }

    private function definition(): array
    {
        $definition = config('file_registry.face_coverage');
        // PROD1 migrates before rebuilding config cache. Read the reviewed local file if that cache predates this feature.
        if ($definition === null) {
            $fresh = require config_path('file_registry.php');
            $definition = $fresh['face_coverage'] ?? null;
        }
        if (! is_array($definition) || ! is_array($definition['policy'] ?? null) || ! is_array($definition['schedule'] ?? null)) {
            throw new RuntimeException('Anonymous coverage registration defaults are unavailable.');
        }
        foreach (['name', 'command', 'cron_expression'] as $key) {
            if (! is_string($definition['schedule'][$key] ?? null) || $definition['schedule'][$key] === '') {
                throw new RuntimeException('Anonymous coverage registration schedule is invalid.');
            }
        }
        foreach (['max_rows', 'max_seconds', 'lease_seconds', 'retry_seconds', 'epoch_interval_seconds', 'max_binding_bytes',
            'max_checkpoint_bytes', 'max_observation_bytes', 'max_finding_bytes', 'hard_timeout_seconds',
            'outer_timeout_seconds', 'timeout_minutes', 'historical_start_hour', 'historical_end_hour'] as $key) {
            $value = $definition['policy'][$key] ?? null;
            if (! is_int($value) || $value < ($key === 'historical_start_hour' ? 0 : 1)
                || $value > ($definition['bounds'][$key] ?? 0)) {
                throw new RuntimeException('Anonymous coverage registration bounds are invalid.');
            }
        }
        $policy = $definition['policy'];
        if (! is_string($policy['timezone'] ?? null) || ! in_array($policy['timezone'], timezone_identifiers_list(), true)
            || ! is_string($policy['timeout_binary'] ?? null) || $policy['timeout_binary'] === ''
            || $policy['historical_start_hour'] >= $policy['historical_end_hour']
            || $policy['max_seconds'] <= ($definition['minimum_row_budget_seconds'] ?? PHP_INT_MAX)
            || $policy['max_seconds'] >= $policy['hard_timeout_seconds'] || $policy['hard_timeout_seconds'] >= $policy['outer_timeout_seconds']
            || $policy['outer_timeout_seconds'] >= $policy['lease_seconds'] || $policy['outer_timeout_seconds'] >= $policy['timeout_minutes'] * 60) {
            throw new RuntimeException('Anonymous coverage registration fences are invalid.');
        }

        return $definition;
    }

    public function down(): void
    {
        $definition = $this->definition();
        DB::table('scheduled_jobs')->where('name', $definition['schedule']['name'])
            ->where('command', $definition['schedule']['command'])->where('notes', self::NOTES)
            ->update(['enabled' => 0, 'next_run_at' => null, 'updated_at' => now()]);
        // Retain policy overrides, source observations, coverage cursors, holds and generation receipts.
    }
};

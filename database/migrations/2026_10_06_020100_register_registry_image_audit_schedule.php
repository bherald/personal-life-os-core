<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NOTES = '{"schema":"file_registry_image_audit_schedule.v1","policy_source":"files.registry_image_audit_policy","scope":"registry_images_and_all_tree_media","source_writes":false,"identity_writes":false,"inspection_evidence_writes":true}';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('system_configs')->insertOrIgnore([
                'section' => 'files', 'config_key' => 'registry_image_audit_policy',
                'config_value' => json_encode(config('file_registry.image_audit.maintenance_policy'), JSON_THROW_ON_ERROR),
                'data_type' => 'json', 'description' => 'Bounded recurring inspection of registry images and all-tree media; preserves operator policy.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('scheduled_jobs')->insertOrIgnore([
                'name' => 'file_registry_image_audit', 'description' => 'Inspect one resumable registry/media source slice; preserve private evidence and identities.',
                'job_type' => 'command', 'command' => 'files:image-audit --json',
                'cron_expression' => config('file_registry.image_audit.schedule.cron_expression'),
                'enabled' => 1, 'run_in_background' => 1, 'without_overlapping' => 1, 'max_parallel' => 1,
                'timeout_minutes' => config('file_registry.image_audit.maintenance_policy.timeout_minutes'),
                'stall_exempt' => 0, 'category' => 'Maintenance', 'source_module' => 'Files',
                'runtime_mode' => 'maintenance', 'workload_family' => 'files', 'resource_profile' => 'io',
                'stall_policy' => 'strict', 'notification_mode' => 'silent', 'notes' => self::NOTES,
                'next_run_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Cache::forget('syscfg:files.registry_image_audit_policy');
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'file_registry_image_audit')
            ->where('command', 'files:image-audit --json')->where('notes', self::NOTES)
            ->update(['enabled' => 0, 'next_run_at' => null, 'updated_at' => now()]);
        // Retain operator policy, actual observations, review findings and progress.
    }
};

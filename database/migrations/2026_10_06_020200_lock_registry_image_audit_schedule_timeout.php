<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NOTES = '{"schema":"file_registry_image_audit_schedule.v1","policy_source":"files.registry_image_audit_policy","scope":"registry_images_and_all_tree_media","source_writes":false,"identity_writes":false,"inspection_evidence_writes":true}';

    public function up(): void
    {
        DB::transaction(function (): void {
            $record = DB::table('system_configs')->where('section', 'files')
                ->where('config_key', 'registry_image_audit_policy')->lockForUpdate()->first();
            $policy = $record && $record->data_type === 'json'
                ? json_decode($record->config_value, true) : null;
            $original = config('file_registry.image_audit.maintenance_policy');
            if (! is_array($policy) || ! is_array($original)) {
                return;
            }
            ksort($policy);
            ksort($original);
            if ($policy !== $original) {
                return;
            }
            $bounds = array_merge(config('file_registry.image_audit.state_bounds', []), config('file_registry.image_audit.maintenance_bounds', []));
            foreach ($bounds as $key => $bound) {
                if (! is_int($bound) || ! is_int($policy[$key] ?? null) || $policy[$key] < 1 || $policy[$key] > $bound) {
                    return;
                }
            }
            if ($policy['max_seconds'] >= $policy['hard_timeout_seconds']
                || $policy['hard_timeout_seconds'] >= $policy['outer_timeout_seconds']
                || $policy['outer_timeout_seconds'] >= $policy['lease_seconds']
                || $policy['outer_timeout_seconds'] >= $policy['timeout_minutes'] * 60) {
                return;
            }

            $expected = [
                'name' => 'file_registry_image_audit',
                'description' => 'Inspect one resumable registry/media source slice; preserve private evidence and identities.',
                'job_type' => 'command', 'command' => 'files:image-audit --json',
                'cron_expression' => config('file_registry.image_audit.schedule.cron_expression'),
                'enabled' => 1, 'run_in_background' => 1, 'without_overlapping' => 1, 'max_parallel' => 1,
                'timeout_locked' => 0, 'stall_exempt' => 0, 'category' => 'Maintenance', 'source_module' => 'Files',
                'runtime_mode' => 'maintenance', 'workload_family' => 'files', 'resource_profile' => 'io',
                'stall_policy' => 'strict', 'notification_mode' => 'silent', 'notes' => self::NOTES,
            ];
            // ScheduledJobService::getAdaptiveTimeoutFloor() uses 30 for command
            // jobs. Repair that known drift only; other timeout edits are custody.
            $query = DB::table('scheduled_jobs')->where($expected)
                ->whereIn('timeout_minutes', [$policy['timeout_minutes'], 30]);
            $job = (clone $query)->lockForUpdate()->first();
            if ($job !== null) {
                $query->where('id', $job->id)->update([
                    'timeout_minutes' => $policy['timeout_minutes'], 'timeout_locked' => 1, 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Retain the bounded timeout and its lock. Unlocking recreates the
        // adaptive drift; any later policy change is an operator decision.
    }
};

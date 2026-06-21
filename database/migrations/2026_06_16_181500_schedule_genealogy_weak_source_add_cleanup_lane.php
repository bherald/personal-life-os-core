<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'genealogy_weak_source_add_cleanup';

    /** @return array<string,mixed> */
    private function lane(): array
    {
        return [
            'name' => self::JOB_NAME,
            'job_name' => self::JOB_NAME,
            'enabled' => true,
            'min_minutes_between_runs' => 15,
            'trigger_mode' => 'all',
            'triggers' => [
                'weak_source_add_min' => 10,
                'stale_job_name' => self::JOB_NAME,
                'stale_minutes' => 15,
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            $this->upsertScheduledJob();
        }

        if (Schema::hasTable('system_configs')) {
            $this->mergeManagerPolicy();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->delete();
        }

        if (Schema::hasTable('system_configs')) {
            $row = DB::table('system_configs')
                ->where('section', 'genealogy')
                ->where('config_key', 'supervisor_manager')
                ->first(['config_value']);

            $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
            if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
                return;
            }

            $policy['lanes'] = array_values(array_filter(
                $policy['lanes'],
                static fn (mixed $lane): bool => ! is_array($lane)
                    || (($lane['name'] ?? null) !== self::JOB_NAME && ($lane['job_name'] ?? null) !== self::JOB_NAME)
            ));

            DB::table('system_configs')
                ->where('section', 'genealogy')
                ->where('config_key', 'supervisor_manager')
                ->update([
                    'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Table-driven Genea cleanup lane for below-actionability source_add review noise.',
            'job_type' => 'command',
            'command' => 'genealogy:weak-source-add-cleanup --tree=4 --execute --confirm --json --compact',
            'cron_expression' => '*/15 * * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 10,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Genealogy',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'genealogy',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'genealogy_weak_source_add',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Ignores only below-actionability source_add genealogy_finding rows selected by system_configs.genealogy.weak_source_add_cleanup_policy.',
                'policy_source' => 'system_configs.genealogy.weak_source_add_cleanup_policy',
                'manager_lane_source' => 'system_configs.genealogy.supervisor_manager',
                'safety' => [
                    'canonical_writes' => false,
                    'canonical_source_link_writeback' => false,
                    'relationship_changes' => false,
                    'face_identity_automation' => false,
                    'review_queue_status_only' => true,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => self::JOB_NAME, 'created_at' => $now],
            $values
        ));
    }

    private function mergeManagerPolicy(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            $policy = [
                'enabled' => true,
                'max_jobs_per_pass' => 2,
                'lanes' => [],
                'safety' => [
                    'canonical_writes' => false,
                    'face_identity_automation' => false,
                    'scheduler_bookkeeping_required' => true,
                ],
            ];
        }

        $lanes = is_array($policy['lanes'] ?? null) ? $policy['lanes'] : [];
        $laneExists = false;
        foreach ($lanes as $existingLane) {
            if (is_array($existingLane)
                && (($existingLane['name'] ?? null) === self::JOB_NAME || ($existingLane['job_name'] ?? null) === self::JOB_NAME)) {
                $laneExists = true;
                break;
            }
        }

        if (! $laneExists) {
            $lanes[] = $this->lane();
        }

        $policy['lanes'] = $lanes;
        $policy['manager_notes'] = array_replace(
            is_array($policy['manager_notes'] ?? null) ? $policy['manager_notes'] : [],
            [
                'weak_source_add_cleanup_lane' => 'Manager may trigger genealogy_weak_source_add_cleanup when table-configured weak source_add review noise reappears.',
                'weak_source_add_cleanup_policy_source' => 'system_configs.genealogy.weak_source_add_cleanup_policy',
            ]
        );

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'genealogy', 'config_key' => 'supervisor_manager'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-driven Genea manager policy for supervising existing safe scheduler lanes.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
};

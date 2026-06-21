<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'ops_capacity_utilization_manager';

    /** @return array<string,mixed> */
    private function managerPolicy(): array
    {
        return [
            'enabled' => true,
            'max_jobs_per_pass' => 1,
            'allowed_job_names' => [
                'genealogy_manager',
            ],
            'allowed_resource_profiles' => [
                'db',
                'genealogy_llm',
            ],
            'blocked_safety_true_flags' => [
                'canonical_writes',
                'direct_genealogy_writes',
                'face_identity_automation',
                'relationship_changes',
                'canonical_source_link_writeback',
            ],
            'capacity' => [
                'window_minutes' => 60,
                'model_roles' => ['standard', 'quality', 'coding'],
                'required_capability' => 'text',
                'excluded_capabilities' => ['privacy_deny_prefilter'],
                'private_scopes' => ['local_private', 'private_allowed'],
                'local_instance_types' => ['ollama', 'local_llm'],
                'watched_instance_ids' => ['ollama_jetson', 'codex_exec'],
                'running_resource_profiles' => ['genealogy_llm'],
            ],
            'workload' => [
                'tree_id' => 4,
                'signals' => [
                    'genealogy_queue',
                    'genealogy_review_backlog',
                    'llm_running_jobs',
                ],
            ],
            'lanes' => [
                [
                    'name' => 'genealogy_capacity_runway',
                    'job_name' => 'genealogy_manager',
                    'enabled' => true,
                    'min_minutes_between_runs' => 3,
                    'trigger_mode' => 'all',
                    'triggers' => [
                        'private_llm_capacity_min' => 1,
                        'local_llm_capacity_min' => 1,
                        'idle_llm_slots_min' => 1,
                        'watched_instances_available_min' => 1,
                        'genealogy_queue_pending_min' => 1,
                        'safe_work_units_min' => 1,
                        'weak_source_add_max' => 20,
                        'stale_job_name' => 'genealogy_manager',
                        'stale_minutes' => 3,
                    ],
                ],
            ],
            'feedback' => [
                'agent_note' => 'When private LLM slots are idle and safe Genea queue work exists, wake genealogy_manager rather than waiting for manual RTC triage.',
                'policy' => 'Capacity utilization starts only table-allowed scheduled jobs through scheduler:run and never writes canonical genealogy or face identity data directly.',
            ],
            'safety' => [
                'canonical_writes' => false,
                'face_identity_automation' => false,
                'operator_review_required' => true,
                'scheduler_bookkeeping_required' => true,
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->updateOrInsert(
                ['section' => 'ops', 'config_key' => 'capacity_utilization_manager'],
                [
                    'config_value' => json_encode($this->managerPolicy(), JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json',
                    'description' => 'Table-driven manager policy for matching private LLM capacity to safe scheduler lanes.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('scheduled_jobs')) {
            $this->upsertScheduledJob();
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
            DB::table('system_configs')
                ->where('section', 'ops')
                ->where('config_key', 'capacity_utilization_manager')
                ->delete();
        }
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Table-driven capacity utilization manager for waking safe scheduler lanes when private LLM capacity is idle.',
            'job_type' => 'command',
            'command' => 'ops:capacity-utilization-manager --execute --json --compact',
            'cron_expression' => '*/5 * * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 10,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Ops',
            'runtime_mode' => 'manager',
            'workload_family' => 'ops',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'capacity_utilization_manager',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Meta-manager for safe scheduler lanes. Policy lives in system_configs.ops.capacity_utilization_manager.',
                'policy_source' => 'system_configs.ops.capacity_utilization_manager',
                'safety' => [
                    'direct_genealogy_writes' => false,
                    'face_identity_automation' => false,
                    'calls_scheduler_run_only' => true,
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
};

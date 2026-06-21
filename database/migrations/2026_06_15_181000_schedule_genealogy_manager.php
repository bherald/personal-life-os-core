<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function managerPolicy(): array
    {
        return [
            'enabled' => true,
            'max_jobs_per_pass' => 2,
            'lanes' => [
                [
                    'name' => 'genealogy_supervisor',
                    'job_name' => 'genealogy_supervisor',
                    'enabled' => true,
                    'min_minutes_between_runs' => 5,
                    'trigger_mode' => 'all',
                    'triggers' => [
                        'queue_pending_min' => 1,
                        'slot_job_name' => 'genealogy_agent_research_queue',
                        'slots_available_min' => 1,
                    ],
                ],
                [
                    'name' => 'face_person_source_repair_packet_refill',
                    'job_name' => 'face_person_source_repair_packet_refill',
                    'enabled' => true,
                    'min_minutes_between_runs' => 30,
                    'trigger_mode' => 'all',
                    'triggers' => [
                        'face_repair_pending_below_max' => true,
                        'stale_job_name' => 'face_person_source_repair_packet_refill',
                        'stale_minutes' => 30,
                    ],
                ],
                [
                    'name' => 'face_person_source_provenance_audit',
                    'job_name' => 'face_person_source_provenance_audit',
                    'enabled' => true,
                    'min_minutes_between_runs' => 30,
                    'trigger_mode' => 'all',
                    'triggers' => [
                        'stale_job_name' => 'face_person_source_provenance_audit',
                        'stale_minutes' => 75,
                    ],
                ],
            ],
            'safety' => [
                'canonical_writes' => false,
                'face_identity_automation' => false,
                'scheduler_bookkeeping_required' => true,
                'policy' => 'Manager may only call existing scheduled_jobs through scheduler:run; it must not write genealogy facts, face identities, files, media metadata, or person-media links directly.',
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->updateOrInsert(
                ['section' => 'genealogy', 'config_key' => 'supervisor_manager'],
                [
                    'config_value' => json_encode($this->managerPolicy(), JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json',
                    'description' => 'Table-driven Genea manager policy for supervising existing safe scheduler lanes.',
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
                ->where('name', 'genealogy_manager')
                ->delete();
        }

        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'genealogy')
                ->where('config_key', 'supervisor_manager')
                ->delete();
        }
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Table-driven Genea manager that supervises existing scheduler lanes without direct genealogy or face identity writes.',
            'job_type' => 'command',
            'command' => 'genealogy:manager --tree=4 --execute --json --compact',
            'cron_expression' => '*/10 * * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 10,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Genealogy',
            'runtime_mode' => 'manager',
            'workload_family' => 'genealogy',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'genealogy_manager',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Meta-supervisor for Genea scheduler lanes. Policy lives in system_configs.genealogy.supervisor_manager.',
                'safety' => [
                    'direct_genealogy_writes' => false,
                    'face_identity_automation' => false,
                    'calls_scheduler_run_only' => true,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_manager')
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => 'genealogy_manager', 'created_at' => $now],
            $values
        ));
    }
};

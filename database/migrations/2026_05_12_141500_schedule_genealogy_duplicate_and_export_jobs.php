<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_duplicate_candidate_scan'],
            [
                'description' => 'Daily genealogy duplicate-person candidate scan across all family trees.',
                'job_type' => 'command',
                'command' => 'genealogy:duplicate-scan --all-trees --min-score=0.75 --limit=250 --json',
                'cron_expression' => '35 5 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 30,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_duplicate_candidates',
                'notification_mode' => 'digest',
                'notes' => 'Review-first scan only. Creates or refreshes pending genealogy_duplicate_pairs rows with score/reasons, but does not merge people or mutate canonical person/family facts.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_export_readiness_check'],
            [
                'description' => 'Daily export-readiness check for self-contained genealogy trees.',
                'job_type' => 'command',
                'command' => 'genealogy:health-audit --all-trees --sections=export --json --compact --limit=25',
                'cron_expression' => '55 5 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 15,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_export_readiness',
                'notification_mode' => 'digest',
                'notes' => 'Observe-only export preflight. Checks every tree for non-self-contained media paths and missing export blockers without downloads, link changes, or person/family fact writes.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->whereIn('name', [
                'genealogy_duplicate_candidate_scan',
                'genealogy_export_readiness_check',
            ])
            ->delete();
    }
};

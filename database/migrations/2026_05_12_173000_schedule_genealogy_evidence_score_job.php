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
            ['name' => 'genealogy_evidence_score_report'],
            [
                'description' => 'Daily observe-only genealogy evidence score report across all family trees.',
                'job_type' => 'command',
                'command' => 'genealogy:evidence-score --all-trees --json --limit=100',
                'cron_expression' => '5 6 * * *',
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
                'backlog_metric' => 'genealogy_evidence_scores',
                'notification_mode' => 'digest',
                'notes' => 'Observe-only evidence scoring. Summarizes strong/medium/weak/conflict/missing evidence bands for genealogy proposals; does not approve, reject, apply, or mutate person/family/media facts.',
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
            ->where('name', 'genealogy_evidence_score_report')
            ->delete();
    }
};

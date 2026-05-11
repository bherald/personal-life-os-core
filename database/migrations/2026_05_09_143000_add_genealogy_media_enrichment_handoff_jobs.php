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
            ['name' => 'genealogy_media_enrichment_status'],
            [
                'description' => 'Observe-only genealogy media enrichment status and quarantine report for captured/source media handoff',
                'job_type' => 'command',
                'command' => 'genealogy:enrich-media --status --quarantined',
                'cron_expression' => '35 6 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 5,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_media_enrichment',
                'notification_mode' => 'digest',
                'notes' => 'TODO-9M observe-only post-capture handoff report. No downloads, FT storage writes, genealogy links, review decisions, AI calls, or canonical genealogy writes are performed by this status command.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_media_enrichment_batch'],
            [
                'description' => 'Disabled genealogy media enrichment batch lane for operator activation after post-capture preflight is clean',
                'job_type' => 'command',
                'command' => 'genealogy:enrich-media --limit=5',
                'cron_expression' => '50 6 * * *',
                'enabled' => 0,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 60,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'batch',
                'workload_family' => 'genealogy',
                'resource_profile' => 'ai',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_media_enrichment',
                'notification_mode' => 'digest',
                'notes' => 'TODO-9M disabled by default. Enabling this row can generate genealogy media enrichment proposals from eligible captured/source media; activate only after operator review of observe-only status and dry-run output.',
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
                'genealogy_media_enrichment_status',
                'genealogy_media_enrichment_batch',
            ])
            ->delete();
    }
};

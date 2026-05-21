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
            ['name' => 'genealogy_htr_status_check'],
            [
                'description' => 'Daily genealogy HTR/OCR eligibility and availability status check.',
                'job_type' => 'command',
                'command' => 'genealogy:transcribe-media --status',
                'cron_expression' => '15 6 * * *',
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
                'backlog_metric' => 'genealogy_htr_eligibility',
                'notification_mode' => 'digest',
                'notes' => 'Observe-only HTR/OCR readiness check. Reports pending transcription and eligibility reasons; does not transcribe or mutate media rows.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_unlinked_media_review'],
            [
                'description' => 'Daily unlinked and missing genealogy media review audit across all trees.',
                'job_type' => 'command',
                'command' => 'genealogy:health-audit --all-trees --sections=media --json --compact --limit=50',
                'cron_expression' => '25 6 * * *',
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
                'backlog_metric' => 'genealogy_unlinked_media',
                'notification_mode' => 'digest',
                'notes' => 'Observe-only media review. Surfaces unlinked media, missing local files, and external-only media without deleting files, linking records, or changing person/family facts.',
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
                'genealogy_htr_status_check',
                'genealogy_unlinked_media_review',
            ])
            ->delete();
    }
};

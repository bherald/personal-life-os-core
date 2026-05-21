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
            ['name' => 'genealogy_health_audit'],
            [
                'description' => 'Daily read-only genealogy health audit across all family trees.',
                'job_type' => 'command',
                'command' => 'genealogy:health-audit --all-trees --json --compact --limit=20',
                'cron_expression' => '05 5 * * *',
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
                'backlog_metric' => 'genealogy_health_audit',
                'notification_mode' => 'digest',
                'notes' => 'Observe-only control-panel audit. Runs for every known family tree and performs no downloads, storage writes, genealogy links, review decisions, privacy/export release, or canonical record writes.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_media_rag_index'],
            [
                'description' => 'Incremental genealogy media metadata and transcription RAG indexing across all family trees.',
                'job_type' => 'command',
                'command' => 'genealogy:media-rag-index --limit=1500',
                'cron_expression' => '27 */6 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 120,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'batch',
                'workload_family' => 'rag',
                'resource_profile' => 'ai',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_media_rag',
                'notification_mode' => 'digest',
                'notes' => 'Indexes readable genealogy_media metadata, OCR/transcription text, AI descriptions, filenames, and rejected/non-FT name context into local RAG across all trees. No --tree filter so new family trees are covered automatically.',
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
                'genealogy_health_audit',
                'genealogy_media_rag_index',
            ])
            ->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_rag_index')
            ->update([
                'command' => 'genealogy:rag-index --limit=1000',
                'timeout_minutes' => 30,
                'notes' => 'Incremental person profile RAG indexing across all family trees. Always includes living and deceased persons on this private system; do not schedule with --exclude-living.',
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_embed_persons')
            ->update([
                'command' => 'genealogy:embed-persons --limit=1000',
                'timeout_minutes' => 30,
                'notes' => 'Incremental person semantic embedding indexing across all family trees. Always includes living and deceased persons on this private system; do not schedule with --exclude-living.',
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_rag_full_reindex'],
            [
                'description' => 'Monthly full rebuild of genealogy person profile RAG documents across all family trees.',
                'job_type' => 'command',
                'command' => 'genealogy:rag-index --reindex --limit=0',
                'cron_expression' => '10 1 1 * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 180,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'rag',
                'resource_profile' => 'heavy',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_person_rag',
                'notification_mode' => 'digest',
                'notes' => 'Runs monthly on the first at 01:10. Uses --limit=0 so all family-tree person profile docs are rebuilt, including living and deceased persons on the private system. Do not schedule with --exclude-living.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_embed_persons_full_reindex'],
            [
                'description' => 'Monthly full rebuild of genealogy person semantic embeddings across all family trees.',
                'job_type' => 'command',
                'command' => 'genealogy:embed-persons --reindex --limit=0',
                'cron_expression' => '40 3 1 * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 180,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'rag',
                'resource_profile' => 'heavy',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_person_embeddings',
                'notification_mode' => 'digest',
                'notes' => 'Runs monthly on the first at 03:40 after the person profile full reindex. Uses --limit=0 so all family-tree person embeddings are rebuilt, including living and deceased persons on the private system. Do not schedule with --exclude-living.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_rag_index')
            ->update([
                'command' => 'genealogy:rag-index --limit=50',
                'timeout_minutes' => 30,
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_embed_persons')
            ->update([
                'command' => 'genealogy:embed-persons --limit=200',
                'timeout_minutes' => 30,
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->whereIn('name', [
                'genealogy_rag_full_reindex',
                'genealogy_embed_persons_full_reindex',
            ])
            ->delete();
    }
};

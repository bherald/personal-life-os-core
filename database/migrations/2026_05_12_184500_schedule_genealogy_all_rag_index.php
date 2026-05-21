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

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_rag_index')
            ->update([
                'description' => 'Incremental genealogy person, place, and source RAG indexing across all family trees.',
                'command' => 'genealogy:rag-index --type=all --limit=1000',
                'timeout_minutes' => 60,
                'runtime_mode' => 'batch',
                'workload_family' => 'rag',
                'resource_profile' => 'ai',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_rag',
                'notification_mode' => 'digest',
                'notes' => 'Incremental person, place, and source RAG indexing across all family trees. Always includes living and deceased persons on this private system; do not schedule with --exclude-living.',
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_rag_full_reindex')
            ->update([
                'description' => 'Monthly full rebuild of genealogy person, place, and source RAG documents across all family trees.',
                'command' => 'genealogy:rag-index --type=all --reindex --limit=0',
                'timeout_minutes' => 240,
                'resource_profile' => 'heavy',
                'backlog_metric' => 'genealogy_rag',
                'notes' => 'Runs monthly on the first at 01:10. Uses --type=all --limit=0 so all family-tree person, place, and source RAG docs are rebuilt, including living and deceased persons on the private system. Do not schedule with --exclude-living.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_rag_index')
            ->update([
                'description' => 'Incremental person profile RAG indexing across all family trees.',
                'command' => 'genealogy:rag-index --limit=1000',
                'timeout_minutes' => 30,
                'backlog_metric' => 'none',
                'notes' => 'Incremental person profile RAG indexing across all family trees. Always includes living and deceased persons on this private system; do not schedule with --exclude-living.',
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_rag_full_reindex')
            ->update([
                'description' => 'Monthly full rebuild of genealogy person profile RAG documents across all family trees.',
                'command' => 'genealogy:rag-index --reindex --limit=0',
                'timeout_minutes' => 180,
                'backlog_metric' => 'genealogy_person_rag',
                'notes' => 'Runs monthly on the first at 01:10. Uses --limit=0 so all family-tree person profile docs are rebuilt, including living and deceased persons on the private system. Do not schedule with --exclude-living.',
                'updated_at' => now(),
            ]);
    }
};

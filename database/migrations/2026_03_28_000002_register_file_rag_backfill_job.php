<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register the file RAG backfill scheduled job.
 *
 * Indexes files with AI descriptions into rag_documents for semantic search.
 * Runs hourly at minute 45 to avoid collision with file_enrich_ai (top of hour).
 * Self-completing: once backlog is cleared, processes 0 files per run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'file_rag_backfill' LIMIT 1");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled,
                 run_in_background, without_overlapping, timeout_minutes,
                 category, source_module, created_at, updated_at)
                VALUES (
                    'file_rag_backfill',
                    'Index files with AI descriptions into RAG for semantic search. Hourly until backlog cleared.',
                    'command',
                    'files:rag-backfill --limit=500 --priority=described --throttle-ms=100',
                    '45 * * * *',
                    1, 1, 1, 60,
                    'RAG', 'file_management',
                    NOW(), NOW()
                )
            ");
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'file_rag_backfill'");
    }
};

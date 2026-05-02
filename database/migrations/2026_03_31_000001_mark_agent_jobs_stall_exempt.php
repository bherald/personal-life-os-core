<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mark all agent-type and I/O-bound RAG indexing jobs as stall_exempt.
 *
 * Agent jobs spend most of their time in HTTP calls (Ollama, external APIs,
 * Puppeteer) which accumulate near-zero PHP CPU time. The CPU-based stall
 * detector (detectStalledProcesses) was killing these as "stalled" after
 * 30 minutes despite being legitimately I/O-bound.
 *
 * pcntl_alarm() hard timeout still enforces per-job timeout_minutes ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        // All agent-type jobs (they spend time in LLM HTTP calls)
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 1
            WHERE stall_exempt = 0
              AND enabled = 1
              AND (
                  name LIKE '%\\_agent'
                  OR name LIKE '%\\_ops\\_agent'
                  OR command LIKE '%:operations%'
                  OR command LIKE 'agent:%'
              )
        ");

        // I/O-bound RAG indexing jobs (embedding HTTP calls to Ollama/external)
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 1
            WHERE stall_exempt = 0
              AND name IN (
                  'rag_file_bulk_index',
                  'file_rag_backfill',
                  'email_rag_index',
                  'genealogy_rag_index',
                  'contacts_sync_rag',
                  'calendar_sync_rag',
                  'news_rag_index'
              )
        ");
    }

    public function down(): void
    {
        // Restore only the jobs that were NOT exempt before this migration.
        // The original exempt list: file_enrich_ai, file_enrich_faces,
        // raptor_build, rag_sentence_indexing, knowledge_graph_build
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 0
            WHERE name NOT IN (
                'file_enrich_ai',
                'file_enrich_faces',
                'raptor_build',
                'rag_sentence_indexing',
                'knowledge_graph_build'
            )
            AND stall_exempt = 1
        ");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add scheduled job for incremental KG extraction
        // Runs every 6 hours, processes 500 docs per run with 2s sleep between
        // Uses local Ollama to avoid external API rate limits
        DB::table('scheduled_jobs')->insert([
            'name' => 'knowledge_graph_build',
            'description' => 'Incremental knowledge graph entity extraction from RAG documents',
            'job_type' => 'command',
            'command' => 'rag:build-knowledge-graph --limit=500 --sleep=2000 --min-chars=50 --max-chars=8000',
            'cron_expression' => '0 */6 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'timeout_minutes' => 120,
            'category' => 'RAG',
            'source_module' => 'KnowledgeGraph',
            'notes' => 'Phase 0 GraphRAG integration - backfill KG from 110K RAG documents. 2s sleep between docs to avoid Ollama contention.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'knowledge_graph_build')->delete();
    }
};

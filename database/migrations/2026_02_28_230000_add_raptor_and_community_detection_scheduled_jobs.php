<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // RAPTOR hierarchical summarization - runs every 8 hours
        // Processes 50 docs/run (AI-heavy: 4 levels of summarization + embeddings per doc)
        // Depends on KG build populating documents; runs offset from KG schedule
        DB::table('scheduled_jobs')->insert([
            'name' => 'raptor_build',
            'description' => 'Build RAPTOR hierarchical summaries (sentence→paragraph→section→document) for RAG documents',
            'job_type' => 'command',
            'command' => 'rag:raptor-build --limit=50',
            'cron_expression' => '0 2,10,18 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'timeout_minutes' => 120,
            'category' => 'RAG',
            'source_module' => 'KnowledgeGraph',
            'notes' => 'RAPTOR summaries for multi-level retrieval. 50 docs/run is conservative — each doc generates 4 levels of AI summaries + embeddings. Runs at 2AM/10AM/6PM to avoid Ollama contention with agents.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Community detection - runs daily at 5 AM
        // Requires meaningful KG population (300+ entities) before producing useful communities
        // Includes --reports flag to generate LLM summaries for global search
        DB::table('scheduled_jobs')->insert([
            'name' => 'community_detection',
            'description' => 'Run Leiden community detection on knowledge graph and generate LLM community reports',
            'job_type' => 'command',
            'command' => 'graph:detect-communities --force --reports --report-limit=50 --sleep=2000',
            'cron_expression' => '0 5 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'timeout_minutes' => 90,
            'category' => 'RAG',
            'source_module' => 'KnowledgeGraph',
            'notes' => 'Leiden community detection + LLM report generation. Rebuilds daily as KG grows from backfill. Reports enable global search mode in GraphRAG. 50 reports/run with 2s sleep to limit Ollama load.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'raptor_build')->delete();
        DB::table('scheduled_jobs')->where('name', 'community_detection')->delete();
    }
};

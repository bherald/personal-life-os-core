<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO scheduled_jobs
                (name, command, cron_expression, enabled, category, description, job_type, run_in_background, timeout_minutes, max_parallel, created_at, updated_at)
            VALUES
                ('rag_sentence_indexing', 'rag:build-sentences --limit=50', '0 */4 * * *', 1, 'RAG',
                 'Build sentence-level embeddings for sentence window retrieval. 50 docs/run, GPU-bound.',
                 'command', 1, 30, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                command = VALUES(command),
                cron_expression = VALUES(cron_expression),
                description = VALUES(description),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM scheduled_jobs WHERE name = 'rag_sentence_indexing'");
    }
};

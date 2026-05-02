<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DI-6: Add email RAG indexing scheduled job (disabled by default).
 * Enable when ready to start indexing the 1.8GB email archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, run_in_background,
                 without_overlapping, timeout_minutes, category, source_module, created_at, updated_at)
             VALUES (?, ?, 'command', ?, ?, 0, 1, 1, 60, 'Data Ingestion', 'email', NOW(), NOW())",
            [
                'email_rag_index',
                'Index Thunderbird email archives into RAG. Incremental — skips already-indexed messages via rag_email_index hash dedup.',
                'email:rag-index --limit=200',
                '0 */4 * * *', // Every 4 hours when enabled
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'email_rag_index'");
    }
};

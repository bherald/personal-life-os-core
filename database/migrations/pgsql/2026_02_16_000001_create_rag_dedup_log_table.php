<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * RAG Dedup Log - Tracks deduplication decisions
     *
     * Logs every deduplication check to help tune thresholds and debug issues.
     * Used by SemDeDupService for analytics and reporting.
     */
    public function up(): void
    {
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS rag_dedup_log (
                id BIGSERIAL PRIMARY KEY,
                incoming_title VARCHAR(500),
                incoming_source_type VARCHAR(100),
                incoming_content_hash VARCHAR(64) NOT NULL,
                strategy VARCHAR(50) NOT NULL,
                matched_document_id BIGINT,
                similarity_score DECIMAL(5,4),
                action_taken VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Indexes for common queries
        DB::connection('pgsql_rag')->statement("CREATE INDEX IF NOT EXISTS idx_dedup_log_strategy ON rag_dedup_log(strategy)");
        DB::connection('pgsql_rag')->statement("CREATE INDEX IF NOT EXISTS idx_dedup_log_action ON rag_dedup_log(action_taken)");
        DB::connection('pgsql_rag')->statement("CREATE INDEX IF NOT EXISTS idx_dedup_log_hash ON rag_dedup_log(incoming_content_hash)");
        DB::connection('pgsql_rag')->statement("CREATE INDEX IF NOT EXISTS idx_dedup_log_created ON rag_dedup_log(created_at)");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS rag_dedup_log");
    }
};

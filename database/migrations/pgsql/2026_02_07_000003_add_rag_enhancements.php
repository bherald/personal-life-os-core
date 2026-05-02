<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RAG module enhancements (PostgreSQL):
     * - Query tracing for performance analysis
     * - Compression columns on rag_documents
     */
    public function up(): void
    {
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS rag_query_traces (
                id BIGSERIAL PRIMARY KEY,
                query_text TEXT NOT NULL,
                strategy_used VARCHAR(50) NULL,
                retrieval_time_ms INT NULL,
                rerank_time_ms INT NULL,
                total_time_ms INT NULL,
                result_count INT NOT NULL DEFAULT 0,
                top_similarity DECIMAL(6,4) NULL,
                hyde_used BOOLEAN NOT NULL DEFAULT FALSE,
                raptor_used BOOLEAN NOT NULL DEFAULT FALSE,
                filters_applied JSONB NULL,
                metadata JSONB NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_query_traces_created ON rag_query_traces (created_at)
        ");
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_query_traces_strategy ON rag_query_traces (strategy_used)
        ");

        // Add compression columns to rag_documents
        try {
            DB::connection('pgsql_rag')->statement("
                ALTER TABLE rag_documents ADD COLUMN IF NOT EXISTS compressed_content TEXT NULL
            ");
        } catch (\Exception $e) {
        }
        try {
            DB::connection('pgsql_rag')->statement("
                ALTER TABLE rag_documents ADD COLUMN IF NOT EXISTS compression_ratio DECIMAL(5,3) NULL
            ");
        } catch (\Exception $e) {
        }
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS rag_query_traces");

        try {
            DB::connection('pgsql_rag')->statement("ALTER TABLE rag_documents DROP COLUMN IF EXISTS compressed_content");
        } catch (\Exception $e) {
        }
        try {
            DB::connection('pgsql_rag')->statement("ALTER TABLE rag_documents DROP COLUMN IF EXISTS compression_ratio");
        } catch (\Exception $e) {
        }
    }
};

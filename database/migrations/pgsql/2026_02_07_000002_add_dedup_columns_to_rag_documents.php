<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Semantic Dedup support for RAG documents
     *
     * Adds content hashing and dedup tracking columns to rag_documents,
     * plus a dedup log table for auditing dedup decisions.
     */
    public function up(): void
    {
        // Add dedup columns to rag_documents
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64) NULL
        ");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS dedup_status VARCHAR(20) DEFAULT 'unchecked'
        ");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS dedup_matched_id BIGINT NULL
        ");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS dedup_similarity DECIMAL(6,5) NULL
        ");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS dedup_checked_at TIMESTAMP NULL
        ");

        // Partial index on content_hash (only non-null)
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_documents_content_hash
            ON rag_documents(content_hash)
            WHERE content_hash IS NOT NULL
        ");

        // Partial index on dedup_status
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_documents_dedup_status
            ON rag_documents(dedup_status)
            WHERE dedup_status != 'unchecked'
        ");

        // Dedup log table
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS rag_dedup_log (
                id BIGSERIAL PRIMARY KEY,
                incoming_title VARCHAR(500) NULL,
                incoming_source_type VARCHAR(100) NULL,
                incoming_content_hash VARCHAR(64) NOT NULL,
                strategy VARCHAR(30) NOT NULL,
                matched_document_id BIGINT NULL,
                similarity_score DECIMAL(6,5) NULL,
                action_taken VARCHAR(30) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_dedup_log_hash
            ON rag_dedup_log(incoming_content_hash)
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_dedup_log_created
            ON rag_dedup_log(created_at)
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_dedup_log_strategy
            ON rag_dedup_log(strategy)
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS rag_dedup_log");

        DB::connection('pgsql_rag')->statement("DROP INDEX IF EXISTS idx_rag_documents_content_hash");
        DB::connection('pgsql_rag')->statement("DROP INDEX IF EXISTS idx_rag_documents_dedup_status");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            DROP COLUMN IF EXISTS content_hash,
            DROP COLUMN IF EXISTS dedup_status,
            DROP COLUMN IF EXISTS dedup_matched_id,
            DROP COLUMN IF EXISTS dedup_similarity,
            DROP COLUMN IF EXISTS dedup_checked_at
        ");
    }
};

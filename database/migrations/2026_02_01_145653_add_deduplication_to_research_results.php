<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add deduplication columns to research_results and create research_rejections table.
     *
     * Multi-layer deduplication for recurring research topics:
     * - Layer 1: content_hash for exact match detection
     * - Layer 2: RAG semantic similarity (uses existing infrastructure)
     * - Layer 3: research_rejections table for "never show again" tracking
     * - Layer 4: extracted_facts JSONB for structured comparison
     */
    public function up(): void
    {
        $connection = DB::connection('pgsql_rag');

        // Add deduplication columns to research_results
        $connection->statement("
            ALTER TABLE research_results
            ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64),
            ADD COLUMN IF NOT EXISTS normalized_content TEXT,
            ADD COLUMN IF NOT EXISTS extracted_facts JSONB DEFAULT '[]'::jsonb,
            ADD COLUMN IF NOT EXISTS dedup_status VARCHAR(20) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS dedup_matched_id BIGINT DEFAULT NULL
        ");

        // Add index on content_hash for fast lookups
        $connection->statement("
            CREATE INDEX IF NOT EXISTS idx_research_results_content_hash
            ON research_results(content_hash)
            WHERE content_hash IS NOT NULL
        ");

        // Add index on topic + hash for topic-scoped dedup
        $connection->statement("
            CREATE INDEX IF NOT EXISTS idx_research_results_topic_hash
            ON research_results(research_topic_id, content_hash)
            WHERE content_hash IS NOT NULL
        ");

        // Create research_rejections table for Layer 3
        $connection->statement("
            CREATE TABLE IF NOT EXISTS research_rejections (
                id BIGSERIAL PRIMARY KEY,
                research_topic_id BIGINT NOT NULL,
                content_hash VARCHAR(64) NOT NULL,
                fact_hashes JSONB DEFAULT '[]'::jsonb,
                rejection_reason VARCHAR(255),
                rejected_by VARCHAR(50) DEFAULT 'human',
                original_result_id BIGINT,
                created_at TIMESTAMP DEFAULT NOW(),

                CONSTRAINT fk_rejection_topic
                    FOREIGN KEY (research_topic_id)
                    REFERENCES research_topics(id)
                    ON DELETE CASCADE
            )
        ");

        // Unique constraint to prevent duplicate rejection entries
        $connection->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_research_rejections_unique
            ON research_rejections(research_topic_id, content_hash)
        ");

        // Index for fast rejection lookups
        $connection->statement("
            CREATE INDEX IF NOT EXISTS idx_research_rejections_lookup
            ON research_rejections(content_hash)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection('pgsql_rag');

        // Drop research_rejections table
        $connection->statement("DROP TABLE IF EXISTS research_rejections CASCADE");

        // Remove columns from research_results
        $connection->statement("
            ALTER TABLE research_results
            DROP COLUMN IF EXISTS content_hash,
            DROP COLUMN IF EXISTS normalized_content,
            DROP COLUMN IF EXISTS extracted_facts,
            DROP COLUMN IF EXISTS dedup_status,
            DROP COLUMN IF EXISTS dedup_matched_id
        ");
    }
};

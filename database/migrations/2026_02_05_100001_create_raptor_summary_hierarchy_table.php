<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create RAPTOR summary hierarchy table for hierarchical document summarization
 *
 * RAPTOR (Recursive Abstractive Processing for Tree-Organized Retrieval) builds
 * a tree of summaries: sentences -> paragraphs -> sections -> document.
 * This enables retrieval at multiple granularity levels.
 *
 * Stored in PostgreSQL RAG database alongside rag_documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create RAPTOR summary hierarchy table in PostgreSQL
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS raptor_summaries (
                id BIGSERIAL PRIMARY KEY,
                document_id BIGINT NOT NULL REFERENCES rag_documents(id) ON DELETE CASCADE,
                parent_summary_id BIGINT NULL REFERENCES raptor_summaries(id) ON DELETE CASCADE,
                level INT NOT NULL DEFAULT 0,
                level_name VARCHAR(50) NOT NULL DEFAULT 'sentence',
                summary_text TEXT NOT NULL,
                source_chunk_ids JSONB NULL,
                token_count INT NULL,
                embedding vector(768) NULL,
                metadata JSONB NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Add column comments separately (PostgreSQL syntax)
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN raptor_summaries.level IS '0=sentence, 1=paragraph, 2=section, 3=document'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN raptor_summaries.source_chunk_ids IS 'Array of rag_document IDs or raptor_summary IDs that were summarized'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN raptor_summaries.metadata IS 'Additional context (position, title hints, etc.)'");

        // Indexes for efficient traversal
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_raptor_document_id ON raptor_summaries(document_id)
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_raptor_parent_id ON raptor_summaries(parent_summary_id)
            WHERE parent_summary_id IS NOT NULL
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_raptor_level ON raptor_summaries(document_id, level)
        ");

        // HNSW index for vector similarity search on summaries
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_raptor_embedding_hnsw ON raptor_summaries
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");

        // Add raptor_indexed_at to rag_documents to track which documents have been processed
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS raptor_indexed_at TIMESTAMP NULL
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_documents_raptor_indexed
            ON rag_documents(raptor_indexed_at)
            WHERE raptor_indexed_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP INDEX IF EXISTS idx_rag_documents_raptor_indexed");
        DB::connection('pgsql_rag')->statement("ALTER TABLE rag_documents DROP COLUMN IF EXISTS raptor_indexed_at");
        DB::connection('pgsql_rag')->statement("DROP INDEX IF EXISTS idx_raptor_embedding_hnsw");
        DB::connection('pgsql_rag')->statement("DROP INDEX IF EXISTS idx_raptor_level");
        DB::connection('pgsql_rag')->statement("DROP INDEX IF EXISTS idx_raptor_parent_id");
        DB::connection('pgsql_rag')->statement("DROP INDEX IF EXISTS idx_raptor_document_id");
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS raptor_summaries");
    }
};

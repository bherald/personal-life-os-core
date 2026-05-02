<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RAG-4: HyPE (Hypothetical questions Per chunk at Index time)
 *
 * Adds:
 *   - rag_chunk_hypotheticals table — stores LLM-generated question embeddings per chunk
 *   - rag_documents.hype_eligible  — screening flag (1=yes, 0=no, NULL=unscreened)
 *   - rag_documents.hype_indexed_at — timestamp of last indexing run
 *   - rag_documents.hype_error_count — failure counter; skip after 3
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'pgsql_rag';
    }

    public function up(): void
    {
        // 1. New columns on rag_documents
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
                ADD COLUMN IF NOT EXISTS hype_eligible    SMALLINT  DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS hype_indexed_at  TIMESTAMP DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS hype_error_count INTEGER   DEFAULT 0
        ");

        // 2. rag_chunk_hypotheticals table
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS rag_chunk_hypotheticals (
                id             BIGSERIAL    PRIMARY KEY,
                document_id    BIGINT       NOT NULL,
                question_text  TEXT         NOT NULL,
                embedding      VECTOR(768)  NOT NULL,
                question_index SMALLINT     NOT NULL DEFAULT 0,
                created_at     TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // B-tree index for document lookup / deletion
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rch_document_id
            ON rag_chunk_hypotheticals (document_id)
        ");

        // HNSW index for approximate nearest-neighbour search
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rch_embedding
            ON rag_chunk_hypotheticals
            USING hnsw (embedding vector_cosine_ops)
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS rag_chunk_hypotheticals");
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
                DROP COLUMN IF EXISTS hype_eligible,
                DROP COLUMN IF EXISTS hype_indexed_at,
                DROP COLUMN IF EXISTS hype_error_count
        ");
    }
};

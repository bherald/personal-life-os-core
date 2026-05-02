<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RAG Sentence Window Retrieval support
     *
     * Adds sentence_positions JSON column to track sentence boundaries
     * within chunks for expanded context retrieval.
     */
    public function up(): void
    {
        // Add sentence_positions column
        // Format: [{start: int, end: int, embedding_id: bigint|null}, ...]
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS sentence_positions JSONB NULL
        ");

        // Add column for sentence-level embedding mode
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS embedding_mode VARCHAR(20) DEFAULT 'chunk'
        ");

        // Create GIN index for sentence_positions queries
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_documents_sentence_positions
            ON rag_documents USING gin(sentence_positions)
            WHERE sentence_positions IS NOT NULL
        ");

        // Create sentence embeddings table for sentence-level retrieval
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS rag_sentence_embeddings (
                id BIGSERIAL PRIMARY KEY,
                document_id BIGINT NOT NULL REFERENCES rag_documents(id) ON DELETE CASCADE,
                sentence_index INT NOT NULL,
                sentence_text TEXT NOT NULL,
                char_start INT NOT NULL,
                char_end INT NOT NULL,
                embedding vector(768) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT unique_doc_sentence UNIQUE (document_id, sentence_index)
            )
        ");

        // HNSW index for sentence embeddings
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_sentence_embeddings_hnsw
            ON rag_sentence_embeddings USING hnsw (embedding vector_cosine_ops)
            WITH (m = 32, ef_construction = 128)
        ");

        // Index for document lookup
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_sentence_embeddings_document
            ON rag_sentence_embeddings(document_id)
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS rag_sentence_embeddings");

        DB::connection('pgsql_rag')->statement("
            DROP INDEX IF EXISTS idx_rag_documents_sentence_positions
        ");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            DROP COLUMN IF EXISTS sentence_positions,
            DROP COLUMN IF EXISTS embedding_mode
        ");
    }
};

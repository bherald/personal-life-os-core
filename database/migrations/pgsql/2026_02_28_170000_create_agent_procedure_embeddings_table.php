<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create agent_procedure_embeddings table in PostgreSQL (pgvector).
 *
 * Bridges MySQL agent_procedures.id to pgvector embeddings for semantic
 * procedural memory recall (AG-1). Replaces Jaccard keyword matching
 * with embedding cosine similarity for procedure retrieval.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS agent_procedure_embeddings (
                id SERIAL PRIMARY KEY,
                procedure_id BIGINT NOT NULL,
                agent_id VARCHAR(100) NOT NULL,
                embedding vector(768) NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Unique constraint: one embedding per procedure
        DB::connection($this->connection)->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_ape_procedure_id
            ON agent_procedure_embeddings(procedure_id)
        ");

        // Agent lookup index
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_ape_agent_id
            ON agent_procedure_embeddings(agent_id)
        ");

        // HNSW index for fast cosine similarity search
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_ape_embedding_hnsw
            ON agent_procedure_embeddings
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS agent_procedure_embeddings
        ");
    }
};

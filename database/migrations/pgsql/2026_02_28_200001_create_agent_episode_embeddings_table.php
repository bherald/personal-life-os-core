<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create agent_episode_embeddings table in PostgreSQL (pgvector).
 *
 * Bridges MySQL agent_episode_summaries.id to pgvector embeddings for
 * semantic episodic memory recall (AG-2). Same pattern as
 * agent_procedure_embeddings (AG-1).
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS agent_episode_embeddings (
                id SERIAL PRIMARY KEY,
                summary_id BIGINT NOT NULL,
                agent_id VARCHAR(100) NOT NULL,
                embedding vector(768) NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Unique constraint: one embedding per summary
        DB::connection($this->connection)->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_aee_summary_id
            ON agent_episode_embeddings(summary_id)
        ");

        // Agent lookup index
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_aee_agent_id
            ON agent_episode_embeddings(agent_id)
        ");

        // HNSW index for fast cosine similarity search
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_aee_embedding_hnsw
            ON agent_episode_embeddings
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS agent_episode_embeddings
        ");
    }
};

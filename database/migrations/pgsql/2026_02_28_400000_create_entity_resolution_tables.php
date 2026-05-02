<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_entity_embeddings (
                id BIGSERIAL PRIMARY KEY,
                entity_id BIGINT NOT NULL UNIQUE,
                entity_type VARCHAR(50) NOT NULL,
                embedding_text TEXT NOT NULL,
                embedding vector(768) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kgee_entity_type
            ON knowledge_graph_entity_embeddings (entity_type)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kgee_embedding_hnsw
            ON knowledge_graph_entity_embeddings
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");

        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS entity_resolution_runs (
                id BIGSERIAL PRIMARY KEY,
                phase VARCHAR(50) NOT NULL,
                entities_processed INT DEFAULT 0,
                candidates_found INT DEFAULT 0,
                auto_merged INT DEFAULT 0,
                llm_compared INT DEFAULT 0,
                llm_merged INT DEFAULT 0,
                submitted_for_review INT DEFAULT 0,
                errors INT DEFAULT 0,
                duration_ms INT DEFAULT 0,
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS entity_resolution_runs");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS knowledge_graph_entity_embeddings");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS ai_semantic_cache (
                id BIGSERIAL PRIMARY KEY,
                prompt_hash VARCHAR(64) NOT NULL,
                context_hash VARCHAR(32) NOT NULL,
                embedding vector(768) NOT NULL,
                response JSONB NOT NULL,
                prompt_preview TEXT,
                hit_count INTEGER DEFAULT 0,
                last_accessed_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_semantic_cache_embedding
            ON ai_semantic_cache USING hnsw(embedding vector_cosine_ops)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_semantic_cache_prompt_hash
            ON ai_semantic_cache(prompt_hash)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_semantic_cache_context
            ON ai_semantic_cache(context_hash)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_semantic_cache_created
            ON ai_semantic_cache(created_at)
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS ai_semantic_cache");
    }
};

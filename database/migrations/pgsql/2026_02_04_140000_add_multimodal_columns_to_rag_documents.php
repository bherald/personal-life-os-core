<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Add image_embedding vector column for visual embeddings
        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS image_embedding vector(768) NULL
        ");

        // Add image_description for AI-generated visual description
        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS image_description TEXT NULL
        ");

        // Add has_visual_content flag
        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS has_visual_content BOOLEAN DEFAULT FALSE
        ");

        // Add visual_analyzed_at timestamp
        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS visual_analyzed_at TIMESTAMP NULL
        ");

        // Create HNSW index for image embedding similarity search
        // Using same parameters as text embedding index for consistency
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_image_embedding_hnsw
            ON rag_documents USING hnsw (image_embedding vector_cosine_ops)
            WITH (m = 32, ef_construction = 128)
        ");

        // Create index for finding visual documents
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_has_visual_content
            ON rag_documents (has_visual_content)
            WHERE has_visual_content = TRUE
        ");

        // Create full-text search index on image descriptions for hybrid visual search
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_image_description_fts
            ON rag_documents USING gin(to_tsvector('english', COALESCE(image_description, '')))
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_rag_image_description_fts
        ");

        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_rag_has_visual_content
        ");

        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_rag_image_embedding_hnsw
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents DROP COLUMN IF EXISTS visual_analyzed_at
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents DROP COLUMN IF EXISTS has_visual_content
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents DROP COLUMN IF EXISTS image_description
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents DROP COLUMN IF EXISTS image_embedding
        ");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E20: Family Tree App - PostgreSQL Embeddings Table
 *
 * Creates vector embedding storage for genealogy person search.
 * Uses pgvector for semantic search over person biographies.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Ensure pgvector extension is available
        DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS vector');

        // genealogy_person_embeddings - Vector embeddings for person search
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS genealogy_person_embeddings (
                id BIGSERIAL PRIMARY KEY,
                person_id INTEGER NOT NULL,
                tree_id INTEGER NOT NULL,
                full_name VARCHAR(500),
                surname VARCHAR(255),
                given_name VARCHAR(255),
                birth_year VARCHAR(10),
                death_year VARCHAR(10),
                birth_place VARCHAR(500),
                death_place VARCHAR(500),
                biography TEXT,
                search_text TEXT,
                embedding VECTOR(768),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (person_id)
            )
        ");

        // HNSW index for fast vector similarity search
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_genealogy_person_embedding
            ON genealogy_person_embeddings
            USING hnsw (embedding vector_cosine_ops)
            WITH (m=32, ef_construction=128)
        ");

        // Index on tree_id for filtering
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_genealogy_person_tree
            ON genealogy_person_embeddings (tree_id)
        ");

        // Full-text search index
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_genealogy_person_fts
            ON genealogy_person_embeddings
            USING gin(to_tsvector('english', COALESCE(search_text, '')))
        ");

        // Surname index for browsing
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_genealogy_person_surname
            ON genealogy_person_embeddings (surname)
        ");

        // Search function: Find similar persons by embedding
        DB::connection($this->connection)->statement("
            CREATE OR REPLACE FUNCTION search_genealogy_persons(
                query_embedding VECTOR,
                target_tree_id INTEGER DEFAULT NULL,
                max_results INTEGER DEFAULT 20,
                similarity_threshold FLOAT DEFAULT 0.6
            )
            RETURNS TABLE(
                person_id INTEGER,
                tree_id INTEGER,
                full_name VARCHAR,
                birth_year VARCHAR,
                death_year VARCHAR,
                birth_place VARCHAR,
                death_place VARCHAR,
                biography TEXT,
                similarity FLOAT
            )
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                RETURN QUERY
                SELECT
                    gpe.person_id,
                    gpe.tree_id,
                    gpe.full_name,
                    gpe.birth_year,
                    gpe.death_year,
                    gpe.birth_place,
                    gpe.death_place,
                    gpe.biography,
                    (1 - (gpe.embedding <=> query_embedding))::FLOAT AS similarity
                FROM genealogy_person_embeddings gpe
                WHERE
                    (target_tree_id IS NULL OR gpe.tree_id = target_tree_id)
                    AND (1 - (gpe.embedding <=> query_embedding)) >= similarity_threshold
                ORDER BY gpe.embedding <=> query_embedding
                LIMIT max_results;
            END;
            \$\$
        ");

        // Hybrid search function: Vector + full-text
        DB::connection($this->connection)->statement("
            CREATE OR REPLACE FUNCTION hybrid_search_genealogy_persons(
                search_text TEXT,
                query_embedding VECTOR,
                target_tree_id INTEGER DEFAULT NULL,
                max_results INTEGER DEFAULT 20,
                fts_weight FLOAT DEFAULT 0.3,
                vector_weight FLOAT DEFAULT 0.7
            )
            RETURNS TABLE(
                person_id INTEGER,
                tree_id INTEGER,
                full_name VARCHAR,
                birth_year VARCHAR,
                death_year VARCHAR,
                combined_score FLOAT
            )
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                RETURN QUERY
                SELECT
                    gpe.person_id,
                    gpe.tree_id,
                    gpe.full_name,
                    gpe.birth_year,
                    gpe.death_year,
                    (
                        fts_weight * COALESCE(ts_rank(
                            to_tsvector('english', COALESCE(gpe.search_text, '')),
                            plainto_tsquery('english', search_text)
                        ), 0)
                        + vector_weight * (1 - (gpe.embedding <=> query_embedding))
                    )::FLOAT AS combined_score
                FROM genealogy_person_embeddings gpe
                WHERE
                    (target_tree_id IS NULL OR gpe.tree_id = target_tree_id)
                ORDER BY combined_score DESC
                LIMIT max_results;
            END;
            \$\$
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP FUNCTION IF EXISTS hybrid_search_genealogy_persons");
        DB::connection($this->connection)->statement("DROP FUNCTION IF EXISTS search_genealogy_persons");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS genealogy_person_embeddings");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.5: Create research_cache table
 *
 * Provides persistent caching for genealogy research API results.
 * Reduces API calls and improves response times for repeated queries.
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Create research_cache table
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS research_cache (
                id SERIAL PRIMARY KEY,
                source_id INT NOT NULL REFERENCES research_sources(id) ON DELETE CASCADE,
                query_hash VARCHAR(64) NOT NULL,
                query_params JSONB NOT NULL,

                -- Results
                result_count INT DEFAULT 0,
                results JSONB,

                -- Metadata
                person_id INT NULL,
                tree_id INT NULL,

                -- Expiry
                cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                access_count INT DEFAULT 1,
                last_accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                UNIQUE (source_id, query_hash)
            )
        ");

        // Create indexes for efficient lookups
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_research_cache_source ON research_cache(source_id)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_research_cache_hash ON research_cache(query_hash)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_research_cache_expires ON research_cache(expires_at)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_research_cache_person ON research_cache(person_id) WHERE person_id IS NOT NULL
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_research_cache_tree ON research_cache(tree_id) WHERE tree_id IS NOT NULL
        ");

        // Create function to auto-cleanup expired cache entries
        DB::connection($this->connection)->statement("
            CREATE OR REPLACE FUNCTION cleanup_expired_research_cache()
            RETURNS INTEGER AS $$
            DECLARE
                deleted_count INTEGER;
            BEGIN
                DELETE FROM research_cache
                WHERE expires_at IS NOT NULL AND expires_at < NOW();
                GET DIAGNOSTICS deleted_count = ROW_COUNT;
                RETURN deleted_count;
            END;
            $$ LANGUAGE plpgsql
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP FUNCTION IF EXISTS cleanup_expired_research_cache()");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS research_cache");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KG Quality Runs — stores quality metric snapshots
 *
 * Tracks accuracy, freshness, and coverage scores over time.
 * Used by DailyOps dashboard and knowledge-curator agent.
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS kg_quality_runs (
                id BIGSERIAL PRIMARY KEY,
                accuracy_score DECIMAL(5,4) DEFAULT 0,
                freshness_score DECIMAL(5,4) DEFAULT 0,
                coverage_score DECIMAL(5,4) DEFAULT 0,
                composite_score DECIMAL(5,4) DEFAULT 0,
                sample_size INTEGER DEFAULT 50,
                sample_details JSONB DEFAULT '{}'::jsonb,
                stale_triple_count INTEGER DEFAULT 0,
                orphan_entity_count INTEGER DEFAULT 0,
                total_triples INTEGER DEFAULT 0,
                total_entities INTEGER DEFAULT 0,
                eligible_documents INTEGER DEFAULT 0,
                extracted_documents INTEGER DEFAULT 0,
                duration_ms INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_quality_runs_created
            ON kg_quality_runs (created_at DESC)
        ");

        DB::connection($this->connection)->statement("
            COMMENT ON TABLE kg_quality_runs IS 'Quality metric snapshots for knowledge graph accuracy, freshness, and coverage'
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS kg_quality_runs");
    }
};

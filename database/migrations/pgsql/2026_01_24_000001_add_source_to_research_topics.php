<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * Add source tracking to research_topics and status tracking to research_results.
     *
     * This migration enables:
     * - Distinguishing auto-generated vs human-created topics
     * - Automatically deferring low-quality results for auto topics
     * - Showing "no results" message to humans for manual topics
     */
    public function up(): void
    {
        // Add source column to track where topic came from
        DB::connection($this->connection)->statement("
            ALTER TABLE research_topics
            ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT 'auto'
        ");

        // Add comment for documentation
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN research_topics.source IS 'Topic origin: auto (system-generated), human (manual), workflow (from workflow node)'
        ");

        // Add deferred status to research_results for low-quality auto results
        // Current statuses: pending, approved, skipped
        // New status: deferred (auto-skipped due to no useful results)
        DB::connection($this->connection)->statement("
            ALTER TABLE research_results
            ADD COLUMN IF NOT EXISTS quality_score DECIMAL(3,2) DEFAULT NULL
        ");

        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN research_results.quality_score IS 'AI-assessed quality score 0.0-1.0, NULL if not assessed'
        ");

        // Add index for filtering by source
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_research_topics_source ON research_topics(source)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_research_topics_source
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE research_results DROP COLUMN IF EXISTS quality_score
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE research_topics DROP COLUMN IF EXISTS source
        ");
    }
};

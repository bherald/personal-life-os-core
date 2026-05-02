<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * Add human review columns to research_facts table.
     * This enables the unified Research Review Queue where humans
     * approve or skip AI-discovered facts before they're considered final.
     */
    public function up(): void
    {
        // Add human review columns to research_facts
        DB::connection($this->connection)->statement("
            ALTER TABLE research_facts
            ADD COLUMN IF NOT EXISTS needs_human_review BOOLEAN DEFAULT TRUE,
            ADD COLUMN IF NOT EXISTS human_reviewed_at TIMESTAMP,
            ADD COLUMN IF NOT EXISTS human_review_action VARCHAR(20)
        ");

        // Add partial index for efficient review queue queries
        // Only indexes rows that are pending review
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_pending_review
            ON research_facts (created_at DESC)
            WHERE needs_human_review = TRUE AND human_review_action IS NULL
        ");

        // Add index for finding already-skipped facts by hash (deduplication)
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_skipped_hash
            ON research_facts (fact_hash)
            WHERE human_review_action = 'skipped'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_facts_skipped_hash
        ");

        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_facts_pending_review
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE research_facts
            DROP COLUMN IF EXISTS human_review_action,
            DROP COLUMN IF EXISTS human_reviewed_at,
            DROP COLUMN IF EXISTS needs_human_review
        ");
    }
};

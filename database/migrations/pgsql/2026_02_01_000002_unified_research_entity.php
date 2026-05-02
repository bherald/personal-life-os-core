<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unified Research Entity Migration
 *
 * Merges Research Topics and Research Missions into a single system:
 * - Adds scheduling/recurring fields to research_missions
 * - Adds review tracking to research_facts
 * - Creates research_rejected_facts table for deduplication
 * - Migrates existing research_topics to research_missions
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // =========================================================================
        // Step 1: Add scheduling/recurring fields to research_missions
        // =========================================================================
        DB::connection($this->connection)->statement("
            ALTER TABLE research_missions
            ADD COLUMN IF NOT EXISTS frequency VARCHAR(20) DEFAULT 'once',
            ADD COLUMN IF NOT EXISTS rag_category VARCHAR(100),
            ADD COLUMN IF NOT EXISTS last_ran_at TIMESTAMP,
            ADD COLUMN IF NOT EXISTS next_run_at TIMESTAMP,
            ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT true,
            ADD COLUMN IF NOT EXISTS require_human_approval BOOLEAN DEFAULT true,
            ADD COLUMN IF NOT EXISTS migrated_from_topic_id INTEGER
        ");

        // Add comment to frequency column
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN research_missions.frequency IS 'once, daily, weekly, monthly, quarterly, biannually'
        ");

        // Index for finding due recurring missions
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_missions_recurring
            ON research_missions (frequency, next_run_at, is_active)
            WHERE frequency != 'once' AND is_active = true
        ");

        // =========================================================================
        // Step 2: Add review tracking to research_facts
        // =========================================================================
        DB::connection($this->connection)->statement("
            ALTER TABLE research_facts
            ADD COLUMN IF NOT EXISTS review_status VARCHAR(20) DEFAULT 'pending',
            ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP,
            ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(100),
            ADD COLUMN IF NOT EXISTS skip_reason TEXT,
            ADD COLUMN IF NOT EXISTS source_count INTEGER DEFAULT 0,
            ADD COLUMN IF NOT EXISTS verification_summary JSONB
        ");

        // Add comment to review_status column
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN research_facts.review_status IS 'pending, approved, rejected, auto_skipped'
        ");

        // Index for review queue (pending facts sorted by confidence)
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_review_queue
            ON research_facts (review_status, confidence_score DESC, created_at DESC)
            WHERE review_status = 'pending'
        ");

        // Index for finding rejected facts by hash (deduplication)
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_rejected_hash
            ON research_facts (fact_hash)
            WHERE review_status = 'rejected'
        ");

        // =========================================================================
        // Step 3: Create research_rejected_facts table for deduplication
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS research_rejected_facts (
                fact_hash VARCHAR(64) PRIMARY KEY,
                original_fact_statement TEXT NOT NULL,
                rejected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                rejected_by VARCHAR(100),
                rejection_reason TEXT,
                mission_id UUID REFERENCES research_missions(id) ON DELETE SET NULL,
                rejection_count INTEGER DEFAULT 1,
                last_rejected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Index for fast hash lookup
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_rejected_facts_hash ON research_rejected_facts(fact_hash)
        ");

        // Index by mission for audit trail
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_rejected_facts_mission ON research_rejected_facts(mission_id)
        ");

        // =========================================================================
        // Step 4: Migrate existing research_topics to research_missions
        // =========================================================================

        // Check if research_topics table exists before migration
        $topicsExist = DB::connection($this->connection)->select("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_name = 'research_topics'
            ) as exists
        ");

        if ($topicsExist[0]->exists ?? false) {
            // Migrate topics to missions
            DB::connection($this->connection)->statement("
                INSERT INTO research_missions (
                    id, title, description, query_template, domain_category, rag_category,
                    frequency, is_active, depth_level, verification_level, max_sources,
                    require_human_approval, created_by, created_at, updated_at, last_ran_at,
                    migrated_from_topic_id
                )
                SELECT
                    gen_random_uuid(),
                    rt.description,
                    rt.topic_content,
                    rt.topic_content,
                    COALESCE(rt.rag_category, 'general'),
                    rt.rag_category,
                    rt.frequency,
                    rt.is_active,
                    COALESCE(rt.search_depth, 3),
                    'standard',
                    COALESCE(rt.max_sources, 10),
                    true,
                    COALESCE(rt.source, 'system'),
                    rt.created_at,
                    rt.updated_at,
                    rt.last_ran_at,
                    rt.id
                FROM research_topics rt
                WHERE NOT EXISTS (
                    SELECT 1 FROM research_missions rm
                    WHERE rm.migrated_from_topic_id = rt.id
                )
            ");

            // Mark topics as migrated
            DB::connection($this->connection)->statement("
                ALTER TABLE research_topics
                ADD COLUMN IF NOT EXISTS migrated_to_mission_id UUID
            ");

            // Update topics with their new mission IDs
            DB::connection($this->connection)->statement("
                UPDATE research_topics rt
                SET migrated_to_mission_id = (
                    SELECT rm.id FROM research_missions rm
                    WHERE rm.migrated_from_topic_id = rt.id
                    LIMIT 1
                )
                WHERE migrated_to_mission_id IS NULL
            ");
        }

        // =========================================================================
        // Step 5: Migrate existing human_review_action to new review_status
        // =========================================================================
        DB::connection($this->connection)->statement("
            UPDATE research_facts
            SET review_status = CASE
                WHEN human_review_action = 'approved' THEN 'approved'
                WHEN human_review_action = 'skipped' THEN 'rejected'
                WHEN needs_human_review = true AND human_review_action IS NULL THEN 'pending'
                ELSE 'pending'
            END,
            reviewed_at = human_reviewed_at,
            reviewed_by = CASE WHEN human_review_action IS NOT NULL THEN 'human' ELSE NULL END
            WHERE review_status = 'pending' OR review_status IS NULL
        ");

        // =========================================================================
        // Step 6: Populate source_count from existing data
        // =========================================================================
        DB::connection($this->connection)->statement("
            UPDATE research_facts
            SET source_count = external_sources_checked,
                verification_summary = jsonb_build_object(
                    'external_confirmed', COALESCE(external_sources_confirmed, 0),
                    'external_denied', COALESCE(external_sources_denied, 0),
                    'rag_match_score', COALESCE(rag_match_score, 0),
                    'llm_confidence', COALESCE(llm_confidence, 0)
                )
            WHERE source_count = 0 OR source_count IS NULL
        ");

        // =========================================================================
        // Step 7: Backfill research_rejected_facts from existing skipped facts
        // =========================================================================
        DB::connection($this->connection)->statement("
            INSERT INTO research_rejected_facts (fact_hash, original_fact_statement, rejected_at, rejected_by, mission_id)
            SELECT
                fact_hash,
                fact_statement,
                COALESCE(human_reviewed_at, updated_at, created_at),
                COALESCE(reviewed_by, 'system'),
                mission_id
            FROM research_facts
            WHERE human_review_action = 'skipped' OR review_status = 'rejected'
            ON CONFLICT (fact_hash) DO UPDATE
            SET rejection_count = research_rejected_facts.rejection_count + 1,
                last_rejected_at = CURRENT_TIMESTAMP
        ");
    }

    public function down(): void
    {
        // Drop the rejected facts table
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS research_rejected_facts
        ");

        // Remove indexes
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_missions_recurring
        ");
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_facts_review_queue
        ");
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_facts_rejected_hash
        ");

        // Remove columns from research_facts
        DB::connection($this->connection)->statement("
            ALTER TABLE research_facts
            DROP COLUMN IF EXISTS review_status,
            DROP COLUMN IF EXISTS reviewed_at,
            DROP COLUMN IF EXISTS reviewed_by,
            DROP COLUMN IF EXISTS skip_reason,
            DROP COLUMN IF EXISTS source_count,
            DROP COLUMN IF EXISTS verification_summary
        ");

        // Remove columns from research_missions
        DB::connection($this->connection)->statement("
            ALTER TABLE research_missions
            DROP COLUMN IF EXISTS frequency,
            DROP COLUMN IF EXISTS rag_category,
            DROP COLUMN IF EXISTS last_ran_at,
            DROP COLUMN IF EXISTS next_run_at,
            DROP COLUMN IF EXISTS is_active,
            DROP COLUMN IF EXISTS require_human_approval,
            DROP COLUMN IF EXISTS migrated_from_topic_id
        ");

        // Remove migrated_to_mission_id from topics if it exists
        $topicsExist = DB::connection($this->connection)->select("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_name = 'research_topics'
            ) as exists
        ");

        if ($topicsExist[0]->exists ?? false) {
            DB::connection($this->connection)->statement("
                ALTER TABLE research_topics
                DROP COLUMN IF EXISTS migrated_to_mission_id
            ");
        }
    }
};

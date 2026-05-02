<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * Run the migrations.
     *
     * Creates source_performance_feedback table for tracking how well
     * sources perform in actual research missions. This enables the
     * self-learning feedback loop.
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS source_performance_feedback (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

                -- Source reference (to discovered_sources or research_sources)
                source_id UUID,
                source_domain VARCHAR(500) NOT NULL,
                -- domain stored separately so we can track even if source deleted

                -- Mission context
                mission_id UUID,
                research_topic TEXT,
                research_category VARCHAR(100),

                -- Performance ratings (1-5 scale)
                accuracy_rating SMALLINT CHECK (accuracy_rating IS NULL OR (accuracy_rating >= 1 AND accuracy_rating <= 5)),
                relevance_rating SMALLINT CHECK (relevance_rating IS NULL OR (relevance_rating >= 1 AND relevance_rating <= 5)),
                reliability_rating SMALLINT CHECK (reliability_rating IS NULL OR (reliability_rating >= 1 AND reliability_rating <= 5)),
                timeliness_rating SMALLINT CHECK (timeliness_rating IS NULL OR (timeliness_rating >= 1 AND timeliness_rating <= 5)),

                -- Aggregate score (computed from ratings)
                overall_score DECIMAL(3,2),

                -- Feedback classification
                feedback_type VARCHAR(30) NOT NULL DEFAULT 'neutral',
                -- 'excellent', 'good', 'neutral', 'poor', 'unusable',
                -- 'false_positive', 'irrelevant', 'outdated', 'blocked', 'error'

                -- Detailed feedback
                notes TEXT,
                error_message TEXT,
                response_time_ms INTEGER,
                content_length INTEGER,

                -- What facts/data came from this source
                facts_extracted INTEGER DEFAULT 0,
                facts_verified INTEGER DEFAULT 0,
                facts_rejected INTEGER DEFAULT 0,

                -- Score adjustments triggered by this feedback
                trust_score_before DECIMAL(4,3),
                trust_score_after DECIMAL(4,3),
                safety_score_before DECIMAL(4,3),
                safety_score_after DECIMAL(4,3),

                -- Metadata
                rated_by VARCHAR(100) DEFAULT 'system',
                rated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                -- Constraints
                CONSTRAINT valid_feedback_type CHECK (feedback_type IN (
                    'excellent', 'good', 'neutral', 'poor', 'unusable',
                    'false_positive', 'irrelevant', 'outdated', 'blocked', 'error'
                ))
            )
        ");

        // Indexes
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_source_feedback_source
            ON source_performance_feedback(source_id)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_source_feedback_domain
            ON source_performance_feedback(source_domain)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_source_feedback_mission
            ON source_performance_feedback(mission_id)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_source_feedback_type
            ON source_performance_feedback(feedback_type)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_source_feedback_category
            ON source_performance_feedback(research_category)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_source_feedback_rated_at
            ON source_performance_feedback(rated_at DESC)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS source_performance_feedback CASCADE");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contradictions Table for Claim Verification
 *
 * Stores detected contradictions between claims and evidence for human review.
 * Supports 5 contradiction types: negation, antonym, numeric, temporal, semantic.
 *
 * Based on: Enhancement #26 - ContradictionDetector
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // =========================================================================
        // Table: contradictions - Detected conflicts between claims and evidence
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS contradictions (
                id BIGSERIAL PRIMARY KEY,
                claim_id BIGINT REFERENCES claims(id) ON DELETE SET NULL,
                evidence_id BIGINT REFERENCES evidence(id) ON DELETE SET NULL,
                text1 TEXT NOT NULL,
                text2 TEXT NOT NULL,
                contradiction_types JSONB DEFAULT '[]'::jsonb,
                severity DECIMAL(4,3) NOT NULL DEFAULT 0.0,
                severity_label VARCHAR(20) NOT NULL DEFAULT 'none',
                detection_details JSONB DEFAULT '[]'::jsonb,
                human_reviewed BOOLEAN DEFAULT FALSE,
                is_valid BOOLEAN,
                reviewed_by VARCHAR(100),
                review_notes TEXT,
                reviewed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT chk_severity CHECK (severity >= 0 AND severity <= 1),
                CONSTRAINT chk_severity_label CHECK (severity_label IN ('none', 'minor', 'moderate', 'major'))
            )
        ");

        // Index for finding contradictions by claim
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_contradictions_claim
            ON contradictions (claim_id)
            WHERE claim_id IS NOT NULL
        ");

        // Index for finding contradictions by evidence
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_contradictions_evidence
            ON contradictions (evidence_id)
            WHERE evidence_id IS NOT NULL
        ");

        // Index for human review queue (unreviewed, sorted by severity)
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_contradictions_review_queue
            ON contradictions (severity DESC, created_at DESC)
            WHERE human_reviewed = FALSE
        ");

        // Index for contradiction types (GIN for JSONB array containment)
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_contradictions_types
            ON contradictions USING GIN (contradiction_types)
        ");

        // Index for severity filtering
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_contradictions_severity
            ON contradictions (severity_label, severity DESC)
        ");

        // Index for reviewed contradictions
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_contradictions_reviewed
            ON contradictions (reviewed_at DESC)
            WHERE human_reviewed = TRUE
        ");

        // Add comments
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE contradictions IS 'Detected contradictions between claims and evidence via ContradictionDetectorService'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN contradictions.contradiction_types IS 'Array of types: [\"negation\", \"antonym\", \"numeric\", \"temporal\", \"semantic\"]'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN contradictions.severity IS 'Weighted severity score 0-1 based on contradiction types and confidence'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN contradictions.severity_label IS 'Human-readable severity: none, minor, moderate, major'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN contradictions.detection_details IS 'Full detection results including evidence for each type'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN contradictions.is_valid IS 'Human determination: TRUE if contradiction is real, FALSE if false positive'
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS contradictions
        ");
    }
};

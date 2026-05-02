<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Claim Decomposition Pipeline Tables
 *
 * Supports fact-checking workflow:
 * 1. claims - Decomposed atomic claims from source text
 * 2. evidence - Retrieved evidence snippets with NLI labels
 * 3. verdicts - Final verdicts with human review support
 *
 * Based on: research-synthesis-feb2026.md §5 (Claimify + Loki patterns)
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // =========================================================================
        // Table 1: claims - Atomic claims extracted from source text
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS claims (
                id BIGSERIAL PRIMARY KEY,
                source_text TEXT NOT NULL,
                normalized_claim TEXT NOT NULL,
                checkworthiness_score DECIMAL(4,3) DEFAULT 0.0,
                entities JSONB DEFAULT '[]'::jsonb,
                source_document_id BIGINT,
                decomposition_context JSONB DEFAULT '{}'::jsonb,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT chk_checkworthiness CHECK (checkworthiness_score >= 0 AND checkworthiness_score <= 1)
            )
        ");

        // Index for filtering claims by checkworthiness threshold
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_claims_checkworthiness
            ON claims (checkworthiness_score DESC)
            WHERE checkworthiness_score >= 0.5
        ");

        // Index for linking to source documents
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_claims_source_doc
            ON claims (source_document_id)
        ");

        // GIN index for entity search in JSONB
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_claims_entities
            ON claims USING GIN (entities)
        ");

        // Index for recent claims
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_claims_created
            ON claims (created_at DESC)
        ");

        // Add comments
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE claims IS 'Atomic claims extracted from source text via ClaimDecompositionService'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN claims.checkworthiness_score IS 'Score 0-1 indicating if claim is worth verifying (threshold: 0.5)'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN claims.entities IS 'Extracted named entities: [{\"text\": \"...\", \"type\": \"PERSON|ORG|DATE|LOC\"}]'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN claims.decomposition_context IS 'Pipeline metadata: stage timings, original sentence, disambiguation steps'
        ");

        // =========================================================================
        // Table 2: evidence - Retrieved evidence snippets with NLI scoring
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS evidence (
                id BIGSERIAL PRIMARY KEY,
                claim_id BIGINT NOT NULL REFERENCES claims(id) ON DELETE CASCADE,
                snippet TEXT NOT NULL,
                source_url TEXT NOT NULL,
                source_title TEXT,
                source_domain TEXT,
                nli_label VARCHAR(20) NOT NULL DEFAULT 'neutral',
                nli_score DECIMAL(4,3) DEFAULT 0.0,
                credibility_score DECIMAL(4,3) DEFAULT 0.5,
                retrieval_query TEXT,
                retrieval_rank INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT chk_nli_label CHECK (nli_label IN ('supported', 'contradicted', 'neutral')),
                CONSTRAINT chk_nli_score CHECK (nli_score >= 0 AND nli_score <= 1),
                CONSTRAINT chk_credibility CHECK (credibility_score >= 0 AND credibility_score <= 1)
            )
        ");

        // Index for finding evidence by claim
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_evidence_claim
            ON evidence (claim_id, nli_label)
        ");

        // Index for finding supporting/contradicting evidence
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_evidence_nli
            ON evidence (nli_label, nli_score DESC)
        ");

        // Index for credibility filtering
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_evidence_credibility
            ON evidence (credibility_score DESC)
        ");

        // Index for source domain analysis
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_evidence_domain
            ON evidence (source_domain)
        ");

        // Add comments
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE evidence IS 'Evidence snippets retrieved for claim verification via SearXNG'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN evidence.nli_label IS 'Natural Language Inference label: supported, contradicted, or neutral'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN evidence.nli_score IS 'Confidence score for NLI classification (0-1)'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN evidence.credibility_score IS 'Source credibility score based on domain reputation (0-1)'
        ");

        // =========================================================================
        // Table 3: verdicts - Final claim verdicts with human review
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS verdicts (
                id BIGSERIAL PRIMARY KEY,
                claim_id BIGINT NOT NULL REFERENCES claims(id) ON DELETE CASCADE,
                verdict VARCHAR(20) NOT NULL DEFAULT 'inconclusive',
                confidence DECIMAL(4,3) DEFAULT 0.0,
                factuality_score DECIMAL(4,3),
                evidence_summary TEXT,
                supporting_count INTEGER DEFAULT 0,
                contradicting_count INTEGER DEFAULT 0,
                neutral_count INTEGER DEFAULT 0,
                human_reviewed BOOLEAN DEFAULT FALSE,
                reviewed_by VARCHAR(100),
                review_notes TEXT,
                reviewed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT chk_verdict CHECK (verdict IN ('supported', 'refuted', 'inconclusive')),
                CONSTRAINT chk_confidence CHECK (confidence >= 0 AND confidence <= 1),
                CONSTRAINT chk_factuality CHECK (factuality_score IS NULL OR (factuality_score >= 0 AND factuality_score <= 1))
            )
        ");

        // Unique constraint - one verdict per claim (latest)
        DB::connection($this->connection)->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_verdicts_claim_unique
            ON verdicts (claim_id)
        ");

        // Index for human review queue
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_verdicts_review_queue
            ON verdicts (human_reviewed, confidence DESC)
            WHERE human_reviewed = FALSE
        ");

        // Index for verdict statistics
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_verdicts_by_verdict
            ON verdicts (verdict, confidence DESC)
        ");

        // Index for reviewed verdicts
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_verdicts_reviewed
            ON verdicts (reviewed_at DESC)
            WHERE human_reviewed = TRUE
        ");

        // Add comments
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE verdicts IS 'Final verification verdicts for claims with human review support'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN verdicts.verdict IS 'Final verdict: supported (confirmed), refuted (debunked), inconclusive'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN verdicts.factuality_score IS 'Computed as: supporting / (supporting + contradicting), NULL if no evidence'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN verdicts.evidence_summary IS 'AI-generated summary of evidence for/against the claim'
        ");

        // =========================================================================
        // Helper function: Calculate factuality score
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE OR REPLACE FUNCTION calculate_factuality_score(supporting INT, contradicting INT)
            RETURNS DECIMAL(4,3) AS $$
            BEGIN
                IF supporting + contradicting = 0 THEN
                    RETURN NULL;
                END IF;
                RETURN ROUND(supporting::DECIMAL / (supporting + contradicting), 3);
            END;
            $$ LANGUAGE plpgsql IMMUTABLE
        ");

        // =========================================================================
        // Trigger: Auto-update factuality score on verdict changes
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE OR REPLACE FUNCTION update_verdict_factuality()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.factuality_score := calculate_factuality_score(NEW.supporting_count, NEW.contradicting_count);
                NEW.updated_at := CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ");

        DB::connection($this->connection)->statement("
            DROP TRIGGER IF EXISTS trg_verdict_factuality ON verdicts
        ");

        DB::connection($this->connection)->statement("
            CREATE TRIGGER trg_verdict_factuality
            BEFORE INSERT OR UPDATE OF supporting_count, contradicting_count ON verdicts
            FOR EACH ROW
            EXECUTE FUNCTION update_verdict_factuality()
        ");
    }

    public function down(): void
    {
        // Drop triggers first
        DB::connection($this->connection)->statement("
            DROP TRIGGER IF EXISTS trg_verdict_factuality ON verdicts
        ");

        // Drop functions
        DB::connection($this->connection)->statement("
            DROP FUNCTION IF EXISTS update_verdict_factuality()
        ");
        DB::connection($this->connection)->statement("
            DROP FUNCTION IF EXISTS calculate_factuality_score(INT, INT)
        ");

        // Drop tables in reverse order (respecting foreign keys)
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS verdicts
        ");
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS evidence
        ");
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS claims
        ");
    }
};

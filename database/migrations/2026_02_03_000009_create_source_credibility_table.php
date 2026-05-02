<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Source Credibility Table
     *
     * Tracks 5-dimension credibility scoring for evidence sources:
     * 1. Domain authority (gov/edu/established news = high)
     * 2. Historical accuracy (track past verification results)
     * 3. Citation frequency (how often cited by other sources)
     * 4. Temporal relevance (recent vs outdated)
     * 5. Cross-reference score (corroborated by other sources)
     *
     * Uses PostgreSQL (pgsql_rag) for integration with RAG/evidence system.
     */
    public function up(): void
    {
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS source_credibility (
                id SERIAL PRIMARY KEY,

                -- Domain identification
                domain VARCHAR(255) NOT NULL,
                url TEXT,

                -- Composite score
                composite_score DECIMAL(5,4),
                dimension_scores JSONB,
                tier VARCHAR(50),
                confidence DECIMAL(4,3),

                -- Dimension 1: Domain authority (custom override)
                custom_score DECIMAL(5,4),

                -- Dimension 2: Historical accuracy
                verification_result VARCHAR(30),
                accuracy_score DECIMAL(5,4),
                verification_count INTEGER DEFAULT 0,
                last_verified_at TIMESTAMP,

                -- Dimension 3: Citation frequency
                citation_count INTEGER DEFAULT 0,
                cited_by_count INTEGER DEFAULT 0,
                last_citation_at TIMESTAMP,

                -- Metadata
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Indexes for common queries
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_source_credibility_domain
            ON source_credibility(domain)
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_source_credibility_url
            ON source_credibility(url) WHERE url IS NOT NULL
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_source_credibility_verification
            ON source_credibility(verification_result) WHERE verification_result IS NOT NULL
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_source_credibility_composite
            ON source_credibility(composite_score DESC NULLS LAST)
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_source_credibility_tier
            ON source_credibility(tier) WHERE tier IS NOT NULL
        ");

        // Unique constraint on domain+url combination
        DB::connection('pgsql_rag')->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_source_credibility_domain_url
            ON source_credibility(domain, COALESCE(url, ''))
        ");

        // Partial index for domains without specific URLs (domain-level scores)
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_source_credibility_domain_only
            ON source_credibility(domain) WHERE url IS NULL
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS source_credibility CASCADE");
    }
};

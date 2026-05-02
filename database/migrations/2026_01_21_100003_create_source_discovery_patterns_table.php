<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * Run the migrations.
     *
     * Creates source_discovery_patterns table for tracking which discovery
     * strategies work best for each category. This enables the system to
     * learn and improve its source discovery over time.
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS source_discovery_patterns (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

                -- Pattern identification
                pattern_name VARCHAR(255),
                pattern_hash VARCHAR(64) NOT NULL,
                -- Hash of pattern_used for deduplication

                -- Discovery context
                domain_category VARCHAR(100) NOT NULL,
                pattern_used TEXT NOT NULL,
                -- The AI prompt, search query, or discovery method used

                discovery_method VARCHAR(50) DEFAULT 'ai_suggestion',
                -- 'ai_suggestion', 'search_engine', 'manual', 'scrape_extraction', 'reference_following'

                -- Pattern components (for structured patterns)
                pattern_keywords JSONB DEFAULT '[]'::jsonb,
                pattern_exclusions JSONB DEFAULT '[]'::jsonb,
                pattern_modifiers JSONB DEFAULT '{}'::jsonb,

                -- Results tracking
                sources_discovered INTEGER DEFAULT 0,
                sources_whitelisted INTEGER DEFAULT 0,
                sources_blacklisted INTEGER DEFAULT 0,
                sources_active INTEGER DEFAULT 0,
                sources_inactive INTEGER DEFAULT 0,

                -- Success metrics
                total_success_count INTEGER DEFAULT 0,
                total_failure_count INTEGER DEFAULT 0,
                success_rate_pct DECIMAL(5,2),
                avg_trust_score DECIMAL(4,3),
                avg_safety_score DECIMAL(4,3),

                -- Quality metrics
                avg_accuracy_rating DECIMAL(3,2),
                avg_relevance_rating DECIMAL(3,2),
                facts_generated INTEGER DEFAULT 0,
                facts_verified INTEGER DEFAULT 0,

                -- Usage tracking
                times_used INTEGER DEFAULT 1,
                first_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_success_at TIMESTAMP,

                -- Learning metadata
                derived_from_pattern_id UUID,
                -- If this pattern evolved from another

                evolved_count INTEGER DEFAULT 0,
                -- How many times this pattern has been refined

                confidence_score DECIMAL(4,3) DEFAULT 0.5,
                -- How confident we are this pattern is good (0.0-1.0)

                -- Flags
                is_active BOOLEAN DEFAULT true,
                is_verified BOOLEAN DEFAULT false,
                is_manual BOOLEAN DEFAULT false,

                -- Metadata
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                -- Constraints
                CONSTRAINT valid_discovery_method CHECK (discovery_method IN (
                    'ai_suggestion', 'search_engine', 'manual',
                    'scrape_extraction', 'reference_following', 'hybrid'
                )),
                UNIQUE(pattern_hash)
            )
        ");

        // Indexes
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_patterns_category_active
            ON source_discovery_patterns(domain_category, is_active)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_patterns_success_rate
            ON source_discovery_patterns(success_rate_pct DESC NULLS LAST)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_patterns_confidence
            ON source_discovery_patterns(confidence_score DESC)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_patterns_method
            ON source_discovery_patterns(discovery_method)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_patterns_last_used
            ON source_discovery_patterns(last_used_at DESC)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS source_discovery_patterns CASCADE");
    }
};

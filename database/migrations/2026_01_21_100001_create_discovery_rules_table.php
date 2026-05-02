<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * Run the migrations.
     *
     * Creates discovery_rules table for dynamic source evaluation rules.
     * This replaces hardcoded TLD_TRUST_SCORES, WHITELIST_PATTERNS,
     * BLACKLIST_PATTERNS, and CATEGORY_DOMAINS constants.
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS discovery_rules (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

                -- Rule identification
                rule_name VARCHAR(255) NOT NULL,
                rule_type VARCHAR(50) NOT NULL,
                -- Types: 'tld_trust', 'whitelist_pattern', 'blacklist_pattern',
                --        'category_domain', 'category_pattern', 'safety_modifier'

                -- Pattern matching
                match_pattern TEXT NOT NULL,
                pattern_type VARCHAR(20) DEFAULT 'exact',
                -- 'exact', 'regex', 'suffix', 'prefix', 'contains'

                -- Scoring impact
                trust_score_value DECIMAL(5,3),
                -- For tld_trust: the base trust score (0.0-1.0)
                -- For others: NULL or modifier

                trust_score_multiplier DECIMAL(5,3) DEFAULT 1.0,
                -- Applied as: score * multiplier

                safety_score_adjustment DECIMAL(5,3) DEFAULT 0.0,
                -- Applied as: score + adjustment (capped 0.0-1.0)

                -- Categorization
                domain_category VARCHAR(100),
                -- NULL = applies to all categories
                -- 'genealogy', 'science', 'news', 'medical', 'legal', etc.

                suggested_specializations JSONB DEFAULT '[]'::jsonb,
                -- e.g., ['vital_records', 'census', 'immigration']

                suggested_content_types JSONB DEFAULT '[]'::jsonb,
                -- e.g., ['database', 'article', 'primary_source']

                -- Rule behavior
                priority INTEGER DEFAULT 100,
                -- Lower = higher priority, for conflict resolution

                applies_to_new_sources BOOLEAN DEFAULT true,
                applies_to_existing_sources BOOLEAN DEFAULT false,
                applies_to_ai_evaluation BOOLEAN DEFAULT true,

                -- Auto-correction behavior
                auto_whitelist BOOLEAN DEFAULT false,
                auto_blacklist BOOLEAN DEFAULT false,
                requires_verification BOOLEAN DEFAULT true,

                -- Performance tracking (self-learning)
                times_applied INTEGER DEFAULT 0,
                last_applied_at TIMESTAMP,
                sources_matched INTEGER DEFAULT 0,
                sources_succeeded INTEGER DEFAULT 0,
                success_rate_pct DECIMAL(5,2),

                -- Audit
                notes TEXT,
                created_by VARCHAR(100) DEFAULT 'system',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN DEFAULT true,

                -- Constraints
                CONSTRAINT valid_rule_type CHECK (rule_type IN (
                    'tld_trust', 'whitelist_pattern', 'blacklist_pattern',
                    'category_domain', 'category_pattern', 'safety_modifier'
                )),
                CONSTRAINT valid_pattern_type CHECK (pattern_type IN (
                    'exact', 'regex', 'suffix', 'prefix', 'contains'
                )),
                CONSTRAINT valid_trust_value CHECK (
                    trust_score_value IS NULL OR
                    (trust_score_value >= 0.0 AND trust_score_value <= 1.0)
                )
            )
        ");

        // Indexes for efficient rule lookup
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_discovery_rules_type_active
            ON discovery_rules(rule_type, is_active)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_discovery_rules_category
            ON discovery_rules(domain_category, is_active)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_discovery_rules_priority
            ON discovery_rules(priority, rule_type)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS discovery_rules CASCADE");
    }
};

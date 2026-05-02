<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create research_sources table for dynamic AI-driven source discovery
 *
 * Stores discovered web sources with health tracking, trust scores,
 * and category metadata for AI to select appropriate sources per topic.
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Create research_sources table
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS research_sources (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                base_url VARCHAR(500) NOT NULL,
                url_pattern VARCHAR(500),
                source_type VARCHAR(50) NOT NULL DEFAULT 'website',
                categories JSONB DEFAULT '[]'::jsonb,
                trust_score SMALLINT DEFAULT 5 CHECK (trust_score >= 1 AND trust_score <= 10),
                domain_type VARCHAR(50),
                requires_scraping BOOLEAN DEFAULT true,
                rate_limit_per_hour INTEGER DEFAULT 60,
                last_success_at TIMESTAMP NULL,
                last_failure_at TIMESTAMP NULL,
                failure_count INTEGER DEFAULT 0,
                success_count INTEGER DEFAULT 0,
                avg_response_ms INTEGER,
                is_active BOOLEAN DEFAULT true,
                is_search_engine BOOLEAN DEFAULT false,
                search_url_template VARCHAR(500),
                result_selector VARCHAR(255),
                notes TEXT,
                discovered_by VARCHAR(50) DEFAULT 'manual',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(base_url)
            )
        ");

        // Create index for active sources lookup
        DB::connection($this->connection)->statement("
            CREATE INDEX idx_research_sources_active ON research_sources(is_active, trust_score DESC)
        ");

        // Create index for search engines
        DB::connection($this->connection)->statement("
            CREATE INDEX idx_research_sources_search_engines ON research_sources(is_search_engine, is_active)
        ");

        // Create index for category search (GIN for JSONB)
        DB::connection($this->connection)->statement("
            CREATE INDEX idx_research_sources_categories ON research_sources USING GIN(categories)
        ");

        // Insert default privacy-respecting search engines
        DB::connection($this->connection)->statement("
            INSERT INTO research_sources
                (name, base_url, source_type, categories, trust_score, domain_type,
                 requires_scraping, is_search_engine, search_url_template, result_selector, notes, discovered_by)
            VALUES
                ('DuckDuckGo', 'https://duckduckgo.com', 'search_engine', '[\"general\", \"privacy\"]'::jsonb, 8,
                 'commercial', true, true, 'https://html.duckduckgo.com/html/?q={query}',
                 '.result__title a', 'Privacy-focused, no tracking, HTML version for scraping', 'system'),

                ('Startpage', 'https://www.startpage.com', 'search_engine', '[\"general\", \"privacy\"]'::jsonb, 8,
                 'commercial', true, true, 'https://www.startpage.com/sp/search?query={query}',
                 '.w-gl__result-title', 'Google results without tracking', 'system'),

                ('Searx', 'https://searx.be', 'search_engine', '[\"general\", \"privacy\", \"meta\"]'::jsonb, 7,
                 'community', true, true, 'https://searx.be/search?q={query}&format=html',
                 '.result h3 a', 'Open source meta search engine', 'system'),

                ('Mojeek', 'https://www.mojeek.com', 'search_engine', '[\"general\", \"privacy\", \"independent\"]'::jsonb, 7,
                 'commercial', true, true, 'https://www.mojeek.com/search?q={query}',
                 '.results-standard a.ob', 'Independent index, no tracking', 'system'),

                ('Qwant', 'https://www.qwant.com', 'search_engine', '[\"general\", \"privacy\", \"european\"]'::jsonb, 7,
                 'commercial', true, true, 'https://www.qwant.com/?q={query}&t=web',
                 '.result__title a', 'European privacy search engine', 'system')
        ");

        // Create research_source_results table for caching scraped results
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS research_source_results (
                id SERIAL PRIMARY KEY,
                research_topic_id INTEGER NOT NULL,
                source_id INTEGER REFERENCES research_sources(id) ON DELETE CASCADE,
                url VARCHAR(1000) NOT NULL,
                title VARCHAR(500),
                snippet TEXT,
                full_content TEXT,
                content_hash VARCHAR(64),
                published_date DATE,
                scraped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                relevance_score DECIMAL(5,4),
                ai_vetted BOOLEAN DEFAULT false,
                ai_vetting_notes TEXT,
                is_duplicate BOOLEAN DEFAULT false,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Index for deduplication
        DB::connection($this->connection)->statement("
            CREATE INDEX idx_research_source_results_hash ON research_source_results(content_hash)
        ");

        // Index for topic lookup
        DB::connection($this->connection)->statement("
            CREATE INDEX idx_research_source_results_topic ON research_source_results(research_topic_id, scraped_at DESC)
        ");

        // Add config columns to research_topics table
        DB::connection($this->connection)->statement("
            ALTER TABLE research_topics
            ADD COLUMN IF NOT EXISTS search_depth INTEGER DEFAULT 3,
            ADD COLUMN IF NOT EXISTS max_sources INTEGER DEFAULT 10,
            ADD COLUMN IF NOT EXISTS max_results_per_source INTEGER DEFAULT 5,
            ADD COLUMN IF NOT EXISTS date_filter_days INTEGER DEFAULT 30,
            ADD COLUMN IF NOT EXISTS preferred_categories JSONB DEFAULT '[]'::jsonb,
            ADD COLUMN IF NOT EXISTS excluded_domains JSONB DEFAULT '[]'::jsonb,
            ADD COLUMN IF NOT EXISTS require_recent_only BOOLEAN DEFAULT true
        ");
    }

    public function down(): void
    {
        // Remove added columns from research_topics
        DB::connection($this->connection)->statement("
            ALTER TABLE research_topics
            DROP COLUMN IF EXISTS search_depth,
            DROP COLUMN IF EXISTS max_sources,
            DROP COLUMN IF EXISTS max_results_per_source,
            DROP COLUMN IF EXISTS date_filter_days,
            DROP COLUMN IF EXISTS preferred_categories,
            DROP COLUMN IF EXISTS excluded_domains,
            DROP COLUMN IF EXISTS require_recent_only
        ");

        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS research_source_results");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS research_sources");
    }
};

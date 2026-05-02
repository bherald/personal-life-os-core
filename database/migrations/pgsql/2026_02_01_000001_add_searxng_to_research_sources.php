<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add SearXNG to research_sources table
 *
 * SearXNG is a privacy-respecting meta search engine running locally.
 * Added as high-trust source (trust_score=9) in the research fallback chain.
 *
 * Position in fallback chain:
 * 1. NewsAPI → 2. GNews → 3. Wikipedia → 4. SearXNG (NEW) → 5. Curl → 6. Puppeteer
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Add SearXNG as a search engine source
        DB::connection($this->connection)->statement("
            INSERT INTO research_sources
                (name, base_url, source_type, categories, trust_score, domain_type,
                 requires_scraping, is_search_engine, search_url_template, result_selector,
                 rate_limit_per_hour, notes, discovered_by)
            VALUES
                ('SearXNG', 'http://localhost:8888', 'search_engine',
                 '[\"general\", \"privacy\", \"meta\", \"local\"]'::jsonb, 9,
                 'internal', false, true, 'http://localhost:8888/search?q={query}&format=json',
                 NULL, 1000,
                 'Local SearXNG instance - privacy-respecting meta search engine. JSON API format. Circuit breaker protected.',
                 'system')
            ON CONFLICT (base_url) DO UPDATE SET
                trust_score = 9,
                is_active = true,
                notes = 'Local SearXNG instance - privacy-respecting meta search engine. JSON API format. Circuit breaker protected.',
                updated_at = CURRENT_TIMESTAMP
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DELETE FROM research_sources WHERE name = 'SearXNG' AND base_url = 'http://localhost:8888'
        ");
    }
};

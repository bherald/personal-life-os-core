<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ═══════════════════════════════════════════════════════════════
        // PART 1: Seed NARA + genealogy sources into research_sources (PostgreSQL)
        // These are search engines used by WebResearchService
        // ═══════════════════════════════════════════════════════════════

        $rag = 'pgsql_rag';

        $researchSources = [
            [
                'name' => 'National Archives (NARA)',
                'base_url' => 'https://catalog.archives.gov',
                'search_url_template' => 'https://catalog.archives.gov/api/v2/records/search?q={query}&limit=20',
                'result_selector' => 'json',
                'trust_score' => 10,
                'is_active' => true,
                'is_search_engine' => true,
                'rate_limit_per_hour' => 300,
                'notes' => 'US National Archives API. 37M+ records: military, census, immigration, court, genealogy, government. API key required (pending).',
            ],
            [
                'name' => 'Chronicling America (LOC)',
                'base_url' => 'https://chroniclingamerica.loc.gov',
                'search_url_template' => 'https://chroniclingamerica.loc.gov/search/pages/results/?andtext={query}&format=json&page=1',
                'result_selector' => 'json',
                'trust_score' => 10,
                'is_active' => true,
                'is_search_engine' => true,
                'rate_limit_per_hour' => 120,
                'notes' => 'Library of Congress newspaper archive 1690-1963. No API key needed. Free.',
            ],
            [
                'name' => 'Digital Public Library of America',
                'base_url' => 'https://api.dp.la',
                'search_url_template' => 'https://api.dp.la/v2/items?q={query}&page_size=20',
                'result_selector' => 'json',
                'trust_score' => 10,
                'is_active' => true,
                'is_search_engine' => true,
                'rate_limit_per_hour' => 120,
                'notes' => 'DPLA: 37M+ items from 4000+ institutions. No API key needed.',
            ],
            [
                'name' => 'Internet Archive',
                'base_url' => 'https://archive.org',
                'search_url_template' => 'https://archive.org/advancedsearch.php?q={query}&output=json&rows=20',
                'result_selector' => 'json',
                'trust_score' => 9,
                'is_active' => true,
                'is_search_engine' => true,
                'rate_limit_per_hour' => 60,
                'notes' => 'Internet Archive: 600K+ genealogy books, historical documents. No key needed.',
            ],
            [
                'name' => 'WikiTree API',
                'base_url' => 'https://api.wikitree.com',
                'search_url_template' => 'https://api.wikitree.com/api.php?action=searchPerson&Query={query}&format=json',
                'result_selector' => 'json',
                'trust_score' => 8,
                'is_active' => true,
                'is_search_engine' => true,
                'rate_limit_per_hour' => 120,
                'notes' => 'WikiTree collaborative genealogy. No auth for public profiles.',
            ],
        ];

        foreach ($researchSources as $src) {
            try {
                $existing = DB::connection($rag)->selectOne(
                    "SELECT id FROM research_sources WHERE name = ?", [$src['name']]
                );
                if (!$existing) {
                    DB::connection($rag)->insert(
                        "INSERT INTO research_sources (name, base_url, search_url_template, result_selector, trust_score, is_active, is_search_engine, rate_limit_per_hour, notes, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        [$src['name'], $src['base_url'], $src['search_url_template'], $src['result_selector'],
                         $src['trust_score'], $src['is_active'], $src['is_search_engine'], $src['rate_limit_per_hour'], $src['notes']]
                    );
                }
            } catch (\Exception $e) {
                // Table may not exist on dev — skip silently
                \Illuminate\Support\Facades\Log::info("Skipping research_sources seed: " . $e->getMessage());
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PART 2: Seed into discovered_sources (PostgreSQL)
        // Used by DynamicSourceDiscoveryService and research pipeline
        // ═══════════════════════════════════════════════════════════════

        $discoveredSources = [
            ['archives.gov', 'National Archives (NARA)', 'government', 0.98, 0.99, true, 'genealogy,military,census,immigration,government,historical'],
            ['chroniclingamerica.loc.gov', 'Chronicling America', 'government', 0.98, 0.99, true, 'newspapers,historical,genealogy,obituaries'],
            ['api.dp.la', 'Digital Public Library of America', 'government', 0.95, 0.95, true, 'historical,cultural,genealogy,images'],
            ['api.wikitree.com', 'WikiTree', 'genealogy', 0.82, 0.90, true, 'genealogy,family_trees,collaborative'],
            ['www.freebmd.org.uk', 'FreeBMD UK', 'genealogy', 0.90, 0.88, true, 'genealogy,uk_records,birth,marriage,death'],
        ];

        foreach ($discoveredSources as [$domain, $name, $category, $trust, $safety, $whitelisted, $specs]) {
            try {
                $existing = DB::connection($rag)->selectOne(
                    "SELECT id FROM discovered_sources WHERE domain = ?", [$domain]
                );
                if (!$existing) {
                    DB::connection($rag)->insert(
                        "INSERT INTO discovered_sources (domain, name, domain_category, trust_score, safety_score, is_whitelisted, is_active, specializations, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, true, ?, NOW(), NOW())",
                        [$domain, $name, $category, $trust, $safety, $whitelisted, $specs]
                    );
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::info("Skipping discovered_sources seed: " . $e->getMessage());
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PART 3: Register NARA search as an agent tool (MySQL)
        // Makes it discoverable by ALL agents via agent_tool_registry
        // ═══════════════════════════════════════════════════════════════

        try {
            $existing = DB::selectOne("SELECT id FROM agent_tool_registry WHERE tool_name = 'nara_search'");
            if (!$existing) {
                DB::insert("
                    INSERT INTO agent_tool_registry (tool_name, display_name, description, service, method, permissions, category, is_enabled, proposed_by, approved, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'system', 1, NOW(), NOW())
                ", [
                    'nara_search',
                    'NARA Archives Search',
                    'Search the US National Archives catalog (37M+ records). Covers military, census, immigration, naturalization, court records, genealogy, land patents, presidential documents, photographs, maps. Primary source government documents with highest authority. Requires API key in genealogy_research_providers table.',
                    'WebResearchService',
                    'searchWithNARA',
                    json_encode(['research', 'genealogy', 'fact_check']),
                    'research',
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::info("Skipping agent_tool_registry seed: " . $e->getMessage());
        }

        // Also register LOC newspaper search
        try {
            $existing = DB::selectOne("SELECT id FROM agent_tool_registry WHERE tool_name = 'loc_newspaper_search'");
            if (!$existing) {
                DB::insert("
                    INSERT INTO agent_tool_registry (tool_name, display_name, description, service, method, permissions, category, is_enabled, proposed_by, approved, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'system', 1, NOW(), NOW())
                ", [
                    'loc_newspaper_search',
                    'LOC Newspaper Search',
                    'Search Library of Congress Chronicling America newspaper archive (1690-1963). All 50 states. Full OCR text search of historical newspaper pages. Free, no API key required. Useful for obituaries, birth/marriage announcements, historical events.',
                    'WebResearchService',
                    'searchWithChroniclingAmerica',
                    json_encode(['research', 'genealogy', 'fact_check']),
                    'research',
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::info("Skipping agent_tool_registry seed: " . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            DB::connection('pgsql_rag')->delete("DELETE FROM research_sources WHERE name IN ('National Archives (NARA)', 'Chronicling America (LOC)', 'Digital Public Library of America', 'Internet Archive', 'WikiTree API')");
            DB::connection('pgsql_rag')->delete("DELETE FROM discovered_sources WHERE domain IN ('archives.gov', 'chroniclingamerica.loc.gov', 'api.dp.la', 'api.wikitree.com', 'www.freebmd.org.uk')");
        } catch (\Exception $e) {}

        DB::delete("DELETE FROM agent_tool_registry WHERE tool_name IN ('nara_search', 'loc_newspaper_search')");
    }
};

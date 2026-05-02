<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N114 — Genealogy External Sources + Metrics Monitoring
 *
 * 1. Creates genealogy_source_metrics table (usage monitoring)
     * 2. Registers agent tools:
     *    - source_search_all       (supported-source aggregator)
     *    - ellis_island_search     (immigration 1820-1957)
     *    - freedmens_bureau_search (post-Civil War African-American records)
     *    - dar_search              (DAR Revolutionary War patriots)
 *    - german_church_records_search (Archion / Matricula)
 *    - europeana_search        (European digitized records)
 *    - get_source_metrics      (monitoring dashboard)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. Metrics table
        // ----------------------------------------------------------------
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_source_metrics (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tool_name    VARCHAR(100) NOT NULL,
                source_id    VARCHAR(50)  NOT NULL,
                source_name  VARCHAR(100) NOT NULL,
                person_id    INT UNSIGNED NULL,
                tree_id      INT UNSIGNED NULL,
                agent_id     VARCHAR(50)  NULL,
                query_params JSON         NULL,
                result_count INT          NOT NULL DEFAULT 0,
                success      TINYINT(1)   NOT NULL DEFAULT 0,
                duration_ms  INT          NOT NULL DEFAULT 0,
                ran_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_source_id  (source_id),
                INDEX idx_tool_name  (tool_name),
                INDEX idx_ran_at     (ran_at),
                INDEX idx_person_id  (person_id),
                INDEX idx_tree_id    (tree_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ----------------------------------------------------------------
        // 2. Helper: upsert an agent tool
        // ----------------------------------------------------------------
        $upsert = function (
            string $name,
            string $description,
            string $service,
            string $method,
            array  $parameters,
            string $returns,
            int    $maxCalls    = 6,
            int    $maxTokens   = 4000
        ) {
            DB::statement("
                INSERT INTO agent_tool_registry
                    (name, description, service_class, method, parameters, returns_description,
                     permissions, risk_level, category, enabled, max_calls_per_run, max_tokens_per_call)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description        = VALUES(description),
                    service_class      = VALUES(service_class),
                    method             = VALUES(method),
                    parameters         = VALUES(parameters),
                    returns_description= VALUES(returns_description),
                    enabled            = VALUES(enabled)
            ", [
                $name, $description, $service, $method,
                json_encode($parameters), $returns,
                json_encode(['genealogy:read']), 'read', 'genealogy',
                1, $maxCalls, $maxTokens,
            ]);
        };

        $svc = 'App\\Services\\Genealogy\\GenealogyExternalSearchService';

        // ----------------------------------------------------------------
        // Tool: source_search_all
        // ----------------------------------------------------------------
        $upsert(
            'source_search_all',
            'Search supported configured genealogy sources in one call: LOC Chronicling America, optional private Newspapers.com, Europeana, NARA, FindAGrave, BillionGraves, and WikiTree. FamilySearch, Ancestry, Fold3, and NEHGS are manual/browser-only citation targets.',
            $svc, 'searchAllSources',
            [
                'given_name'  => ['type' => 'string',  'required' => false, 'description' => 'First/given name'],
                'surname'     => ['type' => 'string',  'required' => true,  'description' => 'Last name / surname at birth'],
                'birth_year'  => ['type' => 'integer', 'required' => false, 'description' => 'Birth year (±5 used as date range)'],
                'birth_place' => ['type' => 'string',  'required' => false, 'description' => 'Birth place / state'],
                'death_year'  => ['type' => 'integer', 'required' => false, 'description' => 'Death year'],
                'state'       => ['type' => 'string',  'required' => false, 'description' => 'US state abbreviation or full name'],
                'record_type' => ['type' => 'string',  'required' => false, 'description' => 'Filter: birth, death, marriage, census, military, immigration'],
                'limit'       => ['type' => 'integer', 'required' => false, 'description' => 'Results per source (default 10)'],
            ],
            'Object: results[] (merged from all sources with source field), sources_searched[], errors{}, query_built, duration_ms.',
            4, 8000
        );

        // ----------------------------------------------------------------
        // Tool: ellis_island_search
        // ----------------------------------------------------------------
        $upsert(
            'ellis_island_search',
            'Search Ellis Island and Castle Garden immigration records (1820-1957) for New York arrivals. Queries the Liberty Ellis Foundation passenger database directly AND via SearXNG. Also covers Castle Garden pre-1892 arrivals. Returns passenger name, arrival date, ship, and origin country when found.',
            $svc, 'searchEllisIsland',
            [
                'given_name'     => ['type' => 'string',  'required' => false, 'description' => 'First name (may be anglicized — try variants)'],
                'surname'        => ['type' => 'string',  'required' => true,  'description' => 'Last name (try phonetic variants from surname_phonetic_matches)'],
                'birth_year'     => ['type' => 'integer', 'required' => false, 'description' => 'Birth year (used to estimate arrival year)'],
                'origin_country' => ['type' => 'string',  'required' => false, 'description' => 'Country of origin (Germany, Ireland, Italy, etc.)'],
            ],
            'Object: results[] (name, arrival_year, ship, origin, url), direct_url (Ellis Island passenger search).',
            6, 3000
        );

        // ----------------------------------------------------------------
        // Tool: freedmens_bureau_search
        // ----------------------------------------------------------------
        $upsert(
            'freedmens_bureau_search',
            'Search Freedmen\'s Bureau records (1865-1872) via public NARA/DiscoverFreedmen-style sources. FamilySearch collection lookup is manual/browser-only.',
            $svc, 'searchFreedmensBureau',
            [
                'given_name' => ['type' => 'string', 'required' => false, 'description' => 'First name'],
                'surname'    => ['type' => 'string', 'required' => true,  'description' => 'Last name (formerly enslaved persons often took surnames of enslavers — try both)'],
                'birth_year' => ['type' => 'integer', 'required' => false, 'description' => 'Approximate birth year'],
                'state'      => ['type' => 'string',  'required' => false, 'description' => 'Southern state: AL, AR, DC, FL, GA, KY, LA, MD, MS, MO, NC, SC, TN, TX, VA'],
            ],
            'Object: results[] (title, url, snippet), direct_url, coverage (states served).',
            6, 3000
        );

        // ----------------------------------------------------------------
        // Tool: dar_search
        // ----------------------------------------------------------------
        $upsert(
            'dar_search',
            'Search DAR (Daughters of the American Revolution) Patriot Index and genealogical records. Best for verifying Revolutionary War service (1775-1783) and finding documented patriot lineages. The DAR Patriot Index is a free public database of verified patriots. Also searches DAR Library compiled genealogies.',
            $svc, 'searchDAR',
            [
                'given_name' => ['type' => 'string',  'required' => false, 'description' => 'First name of ancestor'],
                'surname'    => ['type' => 'string',  'required' => true,  'description' => 'Last name of ancestor'],
                'birth_year' => ['type' => 'integer', 'required' => false, 'description' => 'Birth year (approx 1730-1765 for Rev War patriots)'],
                'state'      => ['type' => 'string',  'required' => false, 'description' => 'Colony/state of service'],
            ],
            'Object: results[] (title, url, snippet), dar_db_url (direct DAR patriot search link).',
            5, 3000
        );

        // ----------------------------------------------------------------
        // Tool: german_church_records_search
        // ----------------------------------------------------------------
        $upsert(
            'german_church_records_search',
            'Search German, Austrian, and Swiss church records (Kirchenbücher) via Archion (Protestant), Matricula Online (Catholic), and Archivportal-D. FamilySearch collections are manual/browser-only.',
            $svc, 'searchGermanChurchRecords',
            [
                'surname'    => ['type' => 'string',  'required' => true,  'description' => 'Surname (German spelling — may differ from Americanized version)'],
                'given_name' => ['type' => 'string',  'required' => false, 'description' => 'Given name (German form preferred)'],
                'birth_year' => ['type' => 'integer', 'required' => false, 'description' => 'Year of baptism/birth'],
                'region'     => ['type' => 'string',  'required' => false, 'description' => 'German region or state: Bavaria, Prussia, Württemberg, Rhineland, Hesse, Saxony, Baden, etc.'],
            ],
            'Object: results[] (title, url, snippet), direct_archion (Archion search URL), direct_matricula (Matricula URL), coverage.',
            5, 3000
        );

        // ----------------------------------------------------------------
        // Tool: europeana_search
        // ----------------------------------------------------------------
        $upsert(
            'europeana_search',
            'Search Europeana — Europe\'s digital library with 50M+ digitized items including historical newspapers, genealogical records, and archival documents. Requires EUROPEANA_API_KEY in .env (free at apis.europeana.eu). Best for European ancestors across multiple countries.',
            $svc, 'searchEuropeana',
            [
                'given_name' => ['type' => 'string',  'required' => false, 'description' => 'First name'],
                'surname'    => ['type' => 'string',  'required' => true,  'description' => 'Last name'],
                'birth_year' => ['type' => 'integer', 'required' => false, 'description' => 'Birth year'],
                'country'    => ['type' => 'string',  'required' => false, 'description' => 'European country of origin'],
            ],
            'Object: results[] (title, url, description, source), error if API key not configured.',
            5, 3000
        );

        // ----------------------------------------------------------------
        // Tool: get_source_metrics
        // ----------------------------------------------------------------
        $upsert(
            'get_source_metrics',
            'Get usage and success rate metrics for all genealogy external sources. Shows which sources are returning results, success rates, average result counts, and circuit breaker status. Use to understand which research avenues are productive and which are failing. Part of GPS Element 1 (reasonably exhaustive search) documentation.',
            $svc, 'getSourceMetrics',
            [
                'days'      => ['type' => 'integer', 'required' => false, 'description' => 'Lookback period in days (default 30)'],
                'source_id' => ['type' => 'string',  'required' => false, 'description' => 'Filter to specific source: nara, ellis_island, wikitree, findagrave, etc.'],
                'person_id' => ['type' => 'integer', 'required' => false, 'description' => 'Filter to a specific person\'s search history'],
            ],
            'Object: sources[] (source_id, total_calls, success_rate, avg_results, health: good/degraded/poor, last_called), circuit_status{}.',
            3, 3000
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS genealogy_source_metrics');

        DB::table('agent_tool_registry')->whereIn('name', [
            'source_search_all',
            'ellis_island_search',
            'freedmens_bureau_search',
            'dar_search',
            'german_church_records_search',
            'europeana_search',
            'get_source_metrics',
        ])->delete();
    }
};

<?php

namespace App\Services\Genealogy;

use App\Services\SearXNGService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GenealogyExternalSearchService — N114
 *
 * Site-specific genealogy searches via SearXNG for sources that have no direct
 * PHP API: Ellis Island, Freedmen's Bureau, DAR, German records, Europeana.
 * Subscription/login sources return manual-only guidance instead of scraping.
 *
 * Also wraps GenealogySourceService::searchAll() in an agent-callable method
 * with structured criteria input.
 *
 * All searches are logged to genealogy_source_metrics for monitoring.
 */
class GenealogyExternalSearchService
{
    private ?SearXNGService $searxng = null;

    private ?GenealogySourceService $sourceService = null;

    // SearXNG result count per source
    private const RESULTS_PER_QUERY = 10;

    // Cache TTL for successful results (genealogy data is stable)
    private const CACHE_TTL_HIT = 43200; // 12 hours — results found

    private const CACHE_TTL_MISS = 3600;  // 1 hour  — no results (retry sooner)

    // Circuit breaker: per-source key prefix, thresholds
    private const CB_PREFIX = 'genealogy_ext_circuit_';

    private const CB_THRESHOLD = 3;    // failures before open

    private const CB_COOLDOWN = 300;  // seconds before half-open retry (5 min)

    private function getSearXNG(): SearXNGService
    {
        if (! $this->searxng) {
            $this->searxng = app(SearXNGService::class);
        }

        return $this->searxng;
    }

    private function getSourceService(): GenealogySourceService
    {
        if (! $this->sourceService) {
            $this->sourceService = app(GenealogySourceService::class);
        }

        return $this->sourceService;
    }

    // -------------------------------------------------------------------------
    // Agent-callable: multi-source aggregator
    // -------------------------------------------------------------------------

    /**
     * Search ALL 9 configured genealogy sources in a single call.
     * Wraps GenealogySourceService::searchAll() with structured criteria input.
     *
     * @param  array  $params  Keys: given_name, surname, birth_year, birth_place,
     *                         death_year, state, record_type, limit
     */
    public function searchAllSources(array $params): array
    {
        $query = $this->buildNameQuery($params);
        if (! $query) {
            return ['success' => false, 'error' => 'At least surname is required', 'results' => []];
        }

        $options = array_filter([
            'date_start' => isset($params['birth_year']) ? ((int) $params['birth_year'] - 5) : null,
            'date_end' => isset($params['death_year']) ? ((int) $params['death_year'] + 5) : null,
            'state' => $params['state'] ?? $params['birth_place'] ?? null,
            'limit' => $params['limit'] ?? 10,
            'record_type' => $params['record_type'] ?? null,
        ], fn ($v) => $v !== null);

        $t0 = microtime(true);
        $result = $this->getSourceService()->searchAll($query, $options);
        $ms = (int) ((microtime(true) - $t0) * 1000);

        $this->logMetric('source_search_all', 'all_sources', $params,
            count($result['results'] ?? []), ! empty($result['results']), $ms);

        return array_merge($result, [
            'query_built' => $query,
            'duration_ms' => $ms,
        ]);
    }

    // -------------------------------------------------------------------------
    // Agent-callable: Fold3 (military records)
    // -------------------------------------------------------------------------

    /**
     * Search Fold3 for military service records, pension files, draft registrations.
     * Best for: Civil War, WWI, WWII, Revolutionary War, War of 1812.
     *
     * @param  array  $params  Keys: given_name, surname, birth_year, state, war (optional)
     */
    public function searchFold3(array $params): array
    {
        $name = $this->buildNameQuery($params);
        if (! $name) {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        return $this->manualOnlySource(
            'Fold3 (Military Records)',
            'https://www.fold3.com/search#query='.urlencode($name),
            'Fold3 is a subscription/login source. PLOS keeps it as an operator-opened citation target and does not scrape or automate it.'
        );
    }

    // -------------------------------------------------------------------------
    // Agent-callable: Ellis Island / immigration records
    // -------------------------------------------------------------------------

    /**
     * Search Ellis Island & Castle Garden immigration records (1820–1957).
     * Best for: immigrants arriving at New York. Also checks Statue of Liberty foundation.
     *
     * @param  array  $params  Keys: given_name, surname, birth_year, origin_country
     */
    public function searchEllisIsland(array $params): array
    {
        $name = $this->buildNameQuery($params);
        if (! $name) {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        $parts = ['"'.$name.'"'];
        if (! empty($params['origin_country'])) {
            $parts[] = $params['origin_country'];
        }
        if (! empty($params['birth_year'])) {
            // Approximate arrival year — usually 20-40 years after birth
            $arrivalMin = (int) $params['birth_year'] + 15;
            $arrivalMax = (int) $params['birth_year'] + 60;
            $parts[] = 'immigration';
        }
        // Search public Ellis Island/Castle Garden pages only. FamilySearch is
        // manual-only and is intentionally excluded from automated queries.
        $parts[] = '(site:libertyellisfoundation.org OR site:statueofliberty.org OR site:castlegarden.org)';

        // Also try the direct Ellis Island API
        $directResults = $this->queryEllisIslandApi($params);

        $searxResult = $this->execSearXNG('ellis_island', implode(' ', $parts), $params, [
            'direct_url' => 'https://www.libertyellisfoundation.org/passenger-search?q='.urlencode($name),
            'source_name' => 'Ellis Island / Castle Garden (Immigration)',
            'note' => 'New York arrival records 1820-1957. Free access.',
        ]);

        // Merge direct API results if any
        if (! empty($directResults)) {
            $searxResult['results'] = array_merge($directResults, $searxResult['results'] ?? []);
            $searxResult['total_count'] = count($searxResult['results']);
            $searxResult['direct_api_results'] = count($directResults);
        }

        return $searxResult;
    }

    /**
     * Query Ellis Island Foundation public search API.
     */
    private function queryEllisIslandApi(array $params): array
    {
        $name = $this->buildNameQuery($params);
        if (! $name) {
            return [];
        }

        try {
            $cacheKey = 'ellis_island_api:'.md5(json_encode($params));
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            // Liberty Ellis Foundation has a public JSON search endpoint
            $response = \Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(15)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PLOS-Genealogy/1.0',
                ])
                ->get('https://www.libertyellisfoundation.org/passenger-result', [
                    'q' => $name,
                    'searchtype' => 'PASSENGER',
                    'rows' => 10,
                    'format' => 'json',
                ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $records = [];

            foreach ($data['response']['docs'] ?? [] as $r) {
                $records[] = [
                    'id' => $r['id'] ?? null,
                    'name' => $r['LNAME'].', '.($r['FNAME'] ?? ''),
                    'arrival_year' => $r['ARRIVDATE'] ?? null,
                    'origin' => $r['CALLASBN'] ?? null,
                    'ship' => $r['SHIPNAME'] ?? null,
                    'url' => 'https://www.libertyellisfoundation.org/passenger-details/czoxOiIxMjM0NTY3ODkiOw--/'.($r['id'] ?? ''),
                    'source' => 'Ellis Island Foundation',
                ];
            }

            Cache::put($cacheKey, $records, self::CACHE_TTL_HIT);

            return $records;

        } catch (\Exception $e) {
            Log::debug('GenealogyExternalSearch: Ellis Island API error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Agent-callable: Freedmen's Bureau
    // -------------------------------------------------------------------------

    /**
     * Search Freedmen's Bureau records (1865–1872) for freed enslaved persons
     * and their families. Covers labor contracts, marriage records, ration records.
     *
     * @param  array  $params  Keys: given_name, surname, birth_year, state
     */
    public function searchFreedmensBureau(array $params): array
    {
        $name = $this->buildNameQuery($params);
        if (! $name) {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        $parts = ['"'.$name.'"'];
        if (! empty($params['state'])) {
            $parts[] = $params['state'];
        }
        $parts[] = 'freedmen';
        $parts[] = '(site:freedmensbureau.com OR site:discoverfreedmen.org OR site:archives.gov)';

        // Also search NARA for the original records
        $naraQuery = '"'.$name.'" "freedmen\'s bureau"'.(! empty($params['state']) ? ' '.$params['state'] : '');

        return $this->execSearXNG('freedmens_bureau', implode(' ', $parts), $params, [
            'direct_url' => 'https://www.archives.gov/research/african-americans/freedmens-bureau',
            'nara_query' => $naraQuery,
            'source_name' => "Freedmen's Bureau Records",
            'note' => 'Bureau of Refugees, Freedmen, and Abandoned Lands 1865-1872. Uses public NARA/DiscoverFreedmen-style sources; FamilySearch collection lookup is manual-only.',
            'coverage' => 'Alabama, Arkansas, DC, Florida, Georgia, Kentucky, Louisiana, Maryland, Mississippi, Missouri, North Carolina, South Carolina, Tennessee, Texas, Virginia',
        ]);
    }

    // -------------------------------------------------------------------------
    // Agent-callable: NEHGS / American Ancestors
    // -------------------------------------------------------------------------

    /**
     * Search New England Historic Genealogical Society (NEHGS) / AmericanAncestors.org.
     * Best for: New England ancestors (MA, CT, RI, NH, VT, ME) and Canadian Maritime.
     *
     * @param  array  $params  Keys: given_name, surname, birth_year, state
     */
    public function searchNEHGS(array $params): array
    {
        $name = $this->buildNameQuery($params);
        if (! $name) {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        return $this->manualOnlySource(
            'NEHGS / American Ancestors',
            'https://www.americanancestors.org/databases/search?search='.urlencode($name),
            'AmericanAncestors/NEHGS is a membership source. PLOS records it as a manual lookup target and does not scrape or automate it.',
            ['coverage' => 'Massachusetts, Connecticut, Rhode Island, New Hampshire, Vermont, Maine, Nova Scotia']
        );
    }

    // -------------------------------------------------------------------------
    // Agent-callable: DAR Genealogical Records
    // -------------------------------------------------------------------------

    /**
     * Search Daughters of the American Revolution genealogical records.
     * Best for: Revolutionary War patriots and their lineages (1775–1783).
     *
     * @param  array  $params  Keys: given_name, surname, birth_year, state
     */
    public function searchDAR(array $params): array
    {
        $surname = trim((string) ($params['surname'] ?? ''));
        if ($surname === '') {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        $name = $this->buildNameQuery($params);
        if (! $name) {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        $parts = ['"'.$name.'"'];
        if (! empty($params['state'])) {
            $parts[] = $params['state'];
        }
        $parts[] = '(revolutionary war OR patriot OR DAR)';
        $parts[] = 'site:dar.org';

        return $this->execSearXNG('dar', implode(' ', $parts), $params, [
            'direct_url' => 'https://www.dar.org/national-society/genealogy/lineage-research',
            'dar_db_url' => 'https://services.dar.org/Public/DAR_Member_Data/search_patriot_ancestors.cfm?LastName='.urlencode($surname),
            'source_name' => 'DAR Genealogical Records',
            'access_mode' => 'public_helper',
            'manual_review_required' => true,
            'auto_import' => false,
            'canonical_write_allowed' => false,
            'writeback_allowed' => false,
            'lineage_conclusion_auto_import_allowed' => false,
            'evidence_posture' => 'review_cue_only',
            'note' => 'DAR Patriot Index and lineage pages are public helper links for manual review. Treat results as review cues only; do not auto-import lineage conclusions or write canonical genealogy facts from this helper output.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Agent-callable: German church records
    // -------------------------------------------------------------------------

    /**
     * Search German church records via Archion and Matricula Online.
     * Best for: German/Austro-Hungarian/Swiss ancestors — baptisms, marriages, burials.
     *
     * @param  array  $params  Keys: surname, given_name, birth_year, region (Bavaria, Prussia, etc.)
     */
    public function searchGermanChurchRecords(array $params): array
    {
        $surname = $params['surname'] ?? '';
        if (! $surname) {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        // German records: use surname only + region + record type keywords
        $parts = ['"'.$surname.'"'];
        if (! empty($params['given_name'])) {
            // German given name may have variants — include in query loosely
            $parts[] = $params['given_name'];
        }
        if (! empty($params['birth_year'])) {
            $parts[] = (string) $params['birth_year'];
        }
        if (! empty($params['region'])) {
            $parts[] = $params['region'];
        }
        $parts[] = '(Kirchenbuch OR Taufregister OR baptism OR church)';
        $parts[] = '(site:archion.de OR site:matricula-online.eu OR site:archivportal-d.de)';

        return $this->execSearXNG('german_church_records', implode(' ', $parts), $params, [
            'direct_archion' => 'https://www.archion.de/search/?q='.urlencode($surname),
            'direct_matricula' => 'https://www.matricula-online.eu/',
            'source_name' => 'German Church Records (Archion / Matricula)',
            'access_mode' => 'public_discovery_helper',
            'manual_review_required' => true,
            'auto_import' => false,
            'canonical_write_allowed' => false,
            'writeback_allowed' => false,
            'paid_viewer_automation_allowed' => false,
            'login_session_automation_allowed' => false,
            'evidence_posture' => 'review_cue_only',
            'note' => 'Public discovery helper for Archion, Matricula, and Archivportal-D. Treat results as manual review cues only; do not automate paid viewers, login sessions, manual-only collection pages, auto-imports, or canonical genealogy writes from this helper output.',
            'coverage' => 'Germany, Austria, Switzerland, Alsace-Lorraine, Silesia, Sudetenland',
        ]);
    }

    // -------------------------------------------------------------------------
    // Agent-callable: Europeana (direct wrapper — service exists, needs tool)
    // -------------------------------------------------------------------------

    /**
     * Search Europeana digitized European historical records and newspapers.
     * Requires services.europeana.api_key configuration.
     *
     * @param  array  $params  Keys: given_name, surname, birth_year, country
     */
    public function searchEuropeana(array $params): array
    {
        $query = $this->buildNameQuery($params);
        if (! $query) {
            return ['success' => false, 'error' => 'surname required', 'results' => []];
        }

        $apiKey = config('services.europeana.api_key');
        if (! $apiKey) {
            return [
                'success' => false,
                'error' => 'EUROPEANA_API_KEY not configured',
                'note' => 'Register at https://apis.europeana.eu/en to get a free API key',
                'results' => [],
            ];
        }

        $t0 = microtime(true);
        $result = app(GenealogySourceService::class)->searchEuropeana($query, $params, $apiKey);
        $ms = (int) ((microtime(true) - $t0) * 1000);

        $this->logMetric('europeana_search', 'europeana', $params,
            count($result['results'] ?? []), $result['success'] ?? false, $ms);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Agent-callable: Source metrics dashboard
    // -------------------------------------------------------------------------

    /**
     * Get usage and success metrics for genealogy external sources.
     * Shows which sources return results, success rates, call counts.
     *
     * @param  array  $params  Keys: source_id (optional filter), days (default 30), person_id (optional)
     */
    public function getSourceMetrics(array $params): array
    {
        $days = (int) ($params['days'] ?? 30);
        $sourceId = $params['source_id'] ?? null;
        $personId = $params['person_id'] ?? null;

        $where = ['since' => now()->subDays($days)->toDateTimeString()];
        $sql = 'SELECT source_id, source_name, tool_name,
                          COUNT(*) AS total_calls,
                          SUM(success) AS successful_calls,
                          ROUND(AVG(result_count), 1) AS avg_results,
                          MAX(result_count) AS max_results,
                          ROUND(AVG(duration_ms)) AS avg_duration_ms,
                          MAX(ran_at) AS last_called
                   FROM genealogy_source_metrics
                   WHERE ran_at >= ?';
        $binds = [$where['since']];

        if ($sourceId) {
            $sql .= ' AND source_id = ?';
            $binds[] = $sourceId;
        }
        if ($personId) {
            $sql .= ' AND person_id = ?';
            $binds[] = (int) $personId;
        }

        $sql .= ' GROUP BY source_id, source_name, tool_name ORDER BY total_calls DESC';

        try {
            $rows = DB::select($sql, $binds);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'metrics table not yet created: '.$e->getMessage()];
        }

        $metrics = [];
        foreach ($rows as $r) {
            $successRate = $r->total_calls > 0
                ? round(($r->successful_calls / $r->total_calls) * 100, 1)
                : 0;

            $metrics[] = [
                'source_id' => $r->source_id,
                'source_name' => $r->source_name,
                'tool_name' => $r->tool_name,
                'total_calls' => (int) $r->total_calls,
                'success_rate' => $successRate.'%',
                'avg_results' => (float) $r->avg_results,
                'max_results' => (int) $r->max_results,
                'avg_duration_ms' => (int) $r->avg_duration_ms,
                'last_called' => $r->last_called,
                'health' => $successRate >= 80 ? 'good' : ($successRate >= 50 ? 'degraded' : 'poor'),
            ];
        }

        // Also pull circuit breaker state from GenealogySourceService
        try {
            $circuitStatus = app(GenealogySourceService::class)->getSourceStatus();
        } catch (\Exception $e) {
            $circuitStatus = [];
        }

        return [
            'success' => true,
            'period_days' => $days,
            'sources' => $metrics,
            'circuit_status' => $circuitStatus,
            'note' => empty($metrics) ? 'No calls recorded yet in this period. Metrics accumulate as agent tools are used.' : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function manualOnlySource(string $sourceName, string $directUrl, string $note, array $extra = []): array
    {
        return array_merge([
            'success' => true,
            'manual_only' => true,
            'manual_required' => true,
            'access_mode' => 'manual_browser',
            'manual_review_required' => true,
            'automation_supported' => false,
            'network_search_performed' => false,
            'scrape_allowed' => false,
            'login_session_automation_allowed' => false,
            'auto_import' => false,
            'canonical_write_allowed' => false,
            'writeback_allowed' => false,
            'metadata_writeback_allowed' => false,
            'operator_action' => 'open_in_browser',
            'evidence_posture' => 'manual_citation_target',
            'error' => null,
            'source_name' => $sourceName,
            'direct_url' => $directUrl,
            'note' => $note,
            'results' => [],
        ], $extra);
    }

    /**
     * Execute a SearXNG search with circuit breaker, retry logic, and smart caching.
     *
     * Cache policy:
     *   - Results found  → cache 12h (stable genealogy data)
     *   - No results     → cache 1h  (retry sooner — may be a thin query)
     *   - SearXNG error  → NOT cached (retry immediately on next call)
     *
     * Circuit breaker (per sourceId):
     *   - 3 consecutive errors → circuit opens → source skipped
     *   - After 5 min → half-open → single probe attempt
     *   - Probe success → circuit resets; probe failure → re-opens
     */
    private function execSearXNG(string $sourceId, string $query, array $params, array $meta = []): array
    {
        // Check circuit breaker first
        if ($this->isCircuitOpen($sourceId)) {
            $ttl = $this->circuitReopenIn($sourceId);

            return array_merge([
                'success' => false,
                'error' => "Source {$sourceId} circuit open after repeated failures. Retries in {$ttl}s.",
                'circuit_open' => true,
                'retry_in_secs' => $ttl,
                'results' => [],
            ], $meta);
        }

        // Check result cache (only for successful past queries)
        $cacheKey = 'genealogy_ext:'.$sourceId.':'.md5($query);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return array_merge($cached, ['from_cache' => true]);
        }

        $searxng = $this->getSearXNG();

        if (! $searxng->isAvailable()) {
            // SearXNG itself is down — don't record per-source failure, just report
            $this->logMetric($sourceId.'_search', $sourceId, $params, 0, false, 0);

            return array_merge([
                'success' => false,
                'error' => 'SearXNG unavailable (port 8888). Will retry next call.',
                'results' => [],
            ], $meta);
        }

        $t0 = microtime(true);
        try {
            $raw = $searxng->search($query, self::RESULTS_PER_QUERY);
            $ms = (int) ((microtime(true) - $t0) * 1000);
        } catch (\Exception $e) {
            $ms = (int) ((microtime(true) - $t0) * 1000);
            $this->recordCircuitFailure($sourceId);
            $this->logMetric($sourceId.'_search', $sourceId, $params, 0, false, $ms);
            Log::warning("GenealogyExternalSearch: SearXNG threw on {$sourceId}", ['error' => $e->getMessage()]);

            return array_merge([
                'success' => false,
                'error' => 'Search error: '.$e->getMessage(),
                'results' => [],
            ], $meta);
        }

        $results = $this->normalizeResults($raw, $meta['source_name'] ?? $sourceId, $sourceId);
        $found = count($results) > 0;

        // Reset circuit on any successful SearXNG response (even zero results)
        $this->recordCircuitSuccess($sourceId);
        $this->logMetric($sourceId.'_search', $sourceId, $params, count($results), $found, $ms);

        $result = array_merge([
            'success' => true,
            'query' => $query,
            'total_count' => count($results),
            'results' => $results,
            'duration_ms' => $ms,
        ], $meta);

        // Cache hits longer than misses — but always cache so repeat calls are fast
        Cache::put($cacheKey, $result, $found ? self::CACHE_TTL_HIT : self::CACHE_TTL_MISS);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Circuit breaker helpers (per-source, independent of GenealogySourceService)
    // -------------------------------------------------------------------------

    private function isCircuitOpen(string $sourceId): bool
    {
        $state = Cache::get(self::CB_PREFIX.$sourceId);
        if (! $state || $state['status'] !== 'open') {
            return false;
        }
        // Cooldown elapsed → transition to half-open (allow one probe)
        if (time() - $state['opened_at'] >= self::CB_COOLDOWN) {
            Cache::put(self::CB_PREFIX.$sourceId, array_merge($state, ['status' => 'half-open']), 3600);

            return false;
        }

        return true;
    }

    private function circuitReopenIn(string $sourceId): int
    {
        $state = Cache::get(self::CB_PREFIX.$sourceId);
        if (! $state) {
            return 0;
        }

        return max(0, self::CB_COOLDOWN - (time() - ($state['opened_at'] ?? time())));
    }

    private function recordCircuitFailure(string $sourceId): void
    {
        $state = Cache::get(self::CB_PREFIX.$sourceId) ?? ['status' => 'closed', 'failures' => 0];
        $state['failures']++;
        if ($state['failures'] >= self::CB_THRESHOLD) {
            $state['status'] = 'open';
            $state['opened_at'] = time();
            Log::warning("GenealogyExternalSearch: circuit opened for {$sourceId}", ['failures' => $state['failures']]);
        }
        Cache::put(self::CB_PREFIX.$sourceId, $state, 3600);
    }

    private function recordCircuitSuccess(string $sourceId): void
    {
        $state = Cache::get(self::CB_PREFIX.$sourceId);
        if ($state && in_array($state['status'], ['open', 'half-open'])) {
            Cache::forget(self::CB_PREFIX.$sourceId);
            Log::info("GenealogyExternalSearch: circuit reset for {$sourceId}");
        }
    }

    /**
     * Normalize SearXNG results to standard genealogy record format.
     */
    private function normalizeResults(array $rawResults, string $sourceName, string $sourceId): array
    {
        $results = [];
        foreach ($rawResults as $r) {
            $results[] = [
                'title' => $r['title'] ?? null,
                'url' => $r['url'] ?? null,
                'snippet' => $r['content'] ?? null,
                'score' => $r['score'] ?? null,
                'source' => $sourceName,
                'source_id' => $sourceId,
            ];
        }

        return $results;
    }

    /**
     * Build a name query string from criteria array.
     */
    private function buildNameQuery(array $params): string
    {
        $parts = [];
        if (! empty($params['given_name'])) {
            $parts[] = $params['given_name'];
        }
        if (! empty($params['surname'])) {
            $parts[] = $params['surname'];
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Log a source usage event to genealogy_source_metrics.
     */
    private function logMetric(
        string $toolName,
        string $sourceId,
        array $params,
        int $resultCount,
        bool $success,
        int $durationMs
    ): void {
        try {
            DB::insert(
                'INSERT INTO genealogy_source_metrics
                    (tool_name, source_id, source_name, person_id, tree_id, agent_id,
                     query_params, result_count, success, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $toolName,
                    $sourceId,
                    ucwords(str_replace('_', ' ', $sourceId)),
                    $params['person_id'] ?? null,
                    $params['tree_id'] ?? null,
                    $params['agent_id'] ?? null,
                    json_encode(array_intersect_key($params, array_flip(['given_name', 'surname', 'birth_year', 'state', 'record_type']))),
                    $resultCount,
                    $success ? 1 : 0,
                    $durationMs,
                ]
            );
        } catch (\Exception $e) {
            // Non-fatal — metrics logging should never break a search
            Log::debug('GenealogyExternalSearch: metric log failed', ['error' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genealogy Source Service
 *
 * Unified interface for searching multiple genealogy sources with:
 * - Fault-tolerant multi-source searching
 * - Circuit breaker pattern per source
 * - Rate limiting per source
 * - Result aggregation and deduplication
 * - Deep research mode with query variations
 *
 * Sources:
 * 1. Chronicling America (LOC) - FREE API - Historical newspapers
 * 2. Newspapers.com Library Edition - Private/personal barcode auth
 * 3. Europeana Newspapers - FREE API - European newspapers
 * 4. NARA Catalog - FREE API - National Archives records
 * 5. FindAGrave - FREE - Cemetery/burial records
 * 6. BillionGraves - FREE - Cemetery photos/records
 * 7. WikiTree - FREE API - Collaborative family trees
 *
 * RAW SQL ONLY - No Eloquent/Query Builder per project standards.
 */
class GenealogySourceService
{
    protected const NEWSPAPERS_SESSION_COOLDOWN_SECONDS = 3600;

    // Circuit breaker thresholds
    protected const CIRCUIT_FAILURE_THRESHOLD = 3;

    protected const CIRCUIT_RECOVERY_TIMEOUT = 300; // 5 minutes

    // Rate limits (requests per minute)
    protected const RATE_LIMITS = [
        'chronicling_america' => 60,
        'newspapers_com' => 30,
        'europeana' => 60,
        'nara' => 60,
        'findagrave' => 20,
        'billiongraves' => 30,
        'wikitree' => 30,
    ];

    protected ?NewspaperSearchService $newspaperService = null;

    private static bool $missingUserAgentContactLogged = false;

    private GenealogyTreeRootResolver $treeRootResolver;

    public function __construct(?GenealogyTreeRootResolver $treeRootResolver = null)
    {
        $this->treeRootResolver = $treeRootResolver ?? app(GenealogyTreeRootResolver::class);
        $this->newspaperService = app(NewspaperSearchService::class);
    }

    private function userAgent(string $version = '3.28'): string
    {
        $contact = trim((string) config('services.internet_archive.user_agent_contact', ''));
        if ($contact === '' && ! self::$missingUserAgentContactLogged) {
            self::$missingUserAgentContactLogged = true;
            Log::warning('GenealogySourceService: PLOS_USER_AGENT_CONTACT is empty; archive providers may throttle anonymous clients');
        }

        return $contact !== ''
            ? "PLOS-Framework/{$version} ({$contact})"
            : "PLOS-Framework/{$version}";
    }

    /**
     * Search all available genealogy sources for a query
     *
     * @param  string  $query  Search query (person name, etc.)
     * @param  array  $options  Search options (date_start, date_end, state, limit, record_type)
     * @return array Aggregated results from all sources
     */
    public function searchAll(string $query, array $options = []): array
    {
        $perProvider = [];
        $errors = [];
        $sourcesSearched = [];

        // 2.1a per-provider cap prevents any single provider (LoC historically
        // dominated) from monopolizing the aggregated result set. Each
        // provider contributes at most `$cap` rows; final overall limit is
        // applied to the union after a round-robin fairness merge.
        $cap = $this->perProviderCap($options);

        // 1. Chronicling America (always available, FREE)
        if (! $this->isCircuitOpen('chronicling_america')) {
            try {
                $locResults = $this->searchChroniclingAmerica($query, $options);
                if ($locResults['success']) {
                    $perProvider['chronicling_america'] = array_slice($this->prioritizeResults($locResults['results']), 0, $cap);
                    $sourcesSearched[] = 'Library of Congress - Chronicling America';
                    $this->recordSuccess('chronicling_america');
                } else {
                    $errors['chronicling_america'] = $locResults['error'];
                    $this->recordFailure('chronicling_america');
                }
            } catch (\Exception $e) {
                $errors['chronicling_america'] = $e->getMessage();
                $this->recordFailure('chronicling_america');
            }
        }

        // 2. Newspapers.com Library Edition (if barcode configured)
        $newspapersBarcode = config('services.newspapers.barcode');
        $newspapersEnabled = (bool) config('services.newspapers.personal_automation_enabled', false);
        if ($newspapersEnabled && $newspapersBarcode && ! $this->isCircuitOpen('newspapers_com')) {
            try {
                $newspapersResults = $this->searchNewspapersCom($query, $options, $newspapersBarcode);
                if ($newspapersResults['success']) {
                    $perProvider['newspapers_com'] = array_slice($this->prioritizeResults($newspapersResults['results']), 0, $cap);
                    $sourcesSearched[] = 'Newspapers.com Library Edition';
                    $this->recordSuccess('newspapers_com');
                } else {
                    $errors['newspapers_com'] = $newspapersResults['error'];
                    $this->recordFailure('newspapers_com');
                }
            } catch (\Exception $e) {
                $errors['newspapers_com'] = $e->getMessage();
                $this->recordFailure('newspapers_com');
            }
        }

        // 3. Europeana Newspapers (FREE API)
        $europeanaKey = config('services.europeana.api_key');
        if ($europeanaKey && ! $this->isCircuitOpen('europeana')) {
            try {
                $europeanaResults = $this->searchEuropeana($query, $options, $europeanaKey);
                if ($europeanaResults['success']) {
                    $perProvider['europeana'] = array_slice($this->prioritizeResults($europeanaResults['results']), 0, $cap);
                    $sourcesSearched[] = 'Europeana Newspapers';
                    $this->recordSuccess('europeana');
                } else {
                    $errors['europeana'] = $europeanaResults['error'];
                    $this->recordFailure('europeana');
                }
            } catch (\Exception $e) {
                $errors['europeana'] = $e->getMessage();
                $this->recordFailure('europeana');
            }
        }

        // 4. NARA Catalog (FREE API)
        if (! $this->isCircuitOpen('nara')) {
            try {
                $naraResults = $this->searchNARA($query, $options);
                if ($naraResults['success']) {
                    $perProvider['nara'] = array_slice($this->prioritizeResults($naraResults['results']), 0, $cap);
                    $sourcesSearched[] = 'National Archives (NARA)';
                    $this->recordSuccess('nara');
                } else {
                    $errors['nara'] = $naraResults['error'];
                    $this->recordFailure('nara');
                }
            } catch (\Exception $e) {
                $errors['nara'] = $e->getMessage();
                $this->recordFailure('nara');
            }
        }

        // 5. FindAGrave (FREE - scrape search results)
        if (! $this->isCircuitOpen('findagrave')) {
            try {
                $fagResults = $this->searchFindAGrave($query, $options);
                if ($fagResults['success']) {
                    $perProvider['findagrave'] = array_slice($this->prioritizeResults($fagResults['results']), 0, $cap);
                    $sourcesSearched[] = 'FindAGrave';
                    $this->recordSuccess('findagrave');
                } else {
                    $errors['findagrave'] = $fagResults['error'];
                    $this->recordFailure('findagrave');
                }
            } catch (\Exception $e) {
                $errors['findagrave'] = $e->getMessage();
                $this->recordFailure('findagrave');
            }
        }

        // 6. BillionGraves (FREE API)
        if (! $this->isCircuitOpen('billiongraves')) {
            try {
                $bgResults = $this->searchBillionGraves($query, $options);
                if ($bgResults['success']) {
                    $perProvider['billiongraves'] = array_slice($this->prioritizeResults($bgResults['results']), 0, $cap);
                    $sourcesSearched[] = 'BillionGraves';
                    $this->recordSuccess('billiongraves');
                } else {
                    $errors['billiongraves'] = $bgResults['error'];
                    $this->recordFailure('billiongraves');
                }
            } catch (\Exception $e) {
                $errors['billiongraves'] = $e->getMessage();
                $this->recordFailure('billiongraves');
            }
        }

        // 7. WikiTree (FREE collaborative genealogy)
        if (! $this->isCircuitOpen('wikitree')) {
            try {
                $wikitreeResults = $this->searchWikiTree($query, $options);
                if ($wikitreeResults['success']) {
                    $perProvider['wikitree'] = array_slice($this->prioritizeResults($wikitreeResults['results']), 0, $cap);
                    $sourcesSearched[] = 'WikiTree';
                    $this->recordSuccess('wikitree');
                } else {
                    $errors['wikitree'] = $wikitreeResults['error'];
                    $this->recordFailure('wikitree');
                }
            } catch (\Exception $e) {
                $errors['wikitree'] = $e->getMessage();
                $this->recordFailure('wikitree');
            }
        }

        // 2.1a fairness merge: round-robin across providers so no
        // single provider's full quota appears before every other
        // provider has had a chance. Within each provider the list is
        // already prioritized by score (or passthrough order if no
        // score). Overall limit applies last.
        $limit = (int) ($options['limit'] ?? 50);
        $results = $this->mergeRoundRobin($perProvider, $limit);

        return [
            'success' => ! empty($results) || empty($errors),
            'query' => $query,
            'sources_searched' => $sourcesSearched,
            'total_results' => count($results),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Sort one provider's result list by `score` desc when any result
     * has a score; otherwise return the list unchanged. Called before
     * per-provider array_slice so the cap keeps the highest-relevance
     * rows rather than whatever order the provider returned.
     */
    protected function prioritizeResults(array $results): array
    {
        $anyHasScore = false;
        foreach ($results as $r) {
            if (isset($r['score']) && is_numeric($r['score'])) {
                $anyHasScore = true;
                break;
            }
        }
        if (! $anyHasScore) {
            return $results;
        }

        usort($results, function ($a, $b) {
            $sa = isset($a['score']) && is_numeric($a['score']) ? (float) $a['score'] : 0.0;
            $sb = isset($b['score']) && is_numeric($b['score']) ? (float) $b['score'] : 0.0;

            return $sb <=> $sa;
        });

        return $results;
    }

    /**
     * Round-robin fan-in of per-provider result lists. Takes one from
     * each provider, then another, until the overall `$limit` is hit
     * or all lists are exhausted. Preserves per-provider ordering
     * (already prioritized by `prioritizeResults`) within each round.
     *
     * @param  array<string, array<int, mixed>>  $perProvider
     */
    protected function mergeRoundRobin(array $perProvider, int $limit): array
    {
        $result = [];
        if ($limit <= 0) {
            return $result;
        }

        $indices = [];
        foreach ($perProvider as $key => $rows) {
            $indices[$key] = 0;
        }

        while (count($result) < $limit) {
            $madeProgress = false;
            foreach ($perProvider as $key => $rows) {
                if ($indices[$key] >= count($rows)) {
                    continue;
                }
                $result[] = $rows[$indices[$key]];
                $indices[$key]++;
                $madeProgress = true;
                if (count($result) >= $limit) {
                    break 2;
                }
            }
            if (! $madeProgress) {
                break;
            }
        }

        return $result;
    }

    /**
     * Search specifically for marriage records
     */
    public function searchMarriages(string $person1, ?string $person2 = null, array $options = []): array
    {
        $query = $person1;
        if ($person2) {
            $query .= " {$person2}";
        }
        $query .= ' (married OR marriage OR wedding OR bride OR groom OR nuptials OR license)';

        $options['record_type'] = 'marriage';

        return $this->searchAll($query, $options);
    }

    /**
     * Search for obituaries
     */
    public function searchObituaries(string $name, array $options = []): array
    {
        $query = "{$name} (obituary OR died OR death OR funeral OR burial OR passed away)";
        $options['record_type'] = 'obituary';

        return $this->searchAll($query, $options);
    }

    /**
     * Search for birth records
     */
    public function searchBirths(string $name, array $options = []): array
    {
        $query = "{$name} (born OR birth OR baby OR christening OR baptism)";
        $options['record_type'] = 'birth';

        return $this->searchAll($query, $options);
    }

    /**
     * Deep Research Mode - Thorough search with multiple query variations
     *
     * Performs extensive research with:
     * - Multiple query reformulations
     * - Date range expansion if initial results are empty
     * - Location variations (county, state, region)
     * - Configurable time limit (default 3 minutes)
     *
     * @param  string  $description  Full research topic description
     * @param  array  $options  Options including time_limit_seconds, person1, person2, date, state
     * @return array Comprehensive results from all variations
     */
    public function deepResearch(string $description, array $options = []): array
    {
        $startTime = time();
        $timeLimit = $options['time_limit_seconds'] ?? 180; // 3 minutes default
        $allResults = [];
        $sourcesSearched = [];
        $queriesUsed = [];
        $errors = [];

        Log::info('GenealogySourceService: Starting deep research', [
            'description' => $description,
            'time_limit' => $timeLimit,
        ]);

        // Extract key information from description
        $names = $this->extractNames($description);
        $dateMatch = [];
        preg_match('/(\d{1,2})?\s*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)?\s*(\d{4})/i', $description, $dateMatch);
        $year = $dateMatch[3] ?? null;

        // Detect record type from description
        $recordType = 'general';
        if (preg_match('/marriage|married|wedding/i', $description)) {
            $recordType = 'marriage';
        } elseif (preg_match('/obituary|death|died|burial/i', $description)) {
            $recordType = 'obituary';
        } elseif (preg_match('/birth|born/i', $description)) {
            $recordType = 'birth';
        }

        // Build query variations
        $queryVariations = $this->buildQueryVariations($names, $recordType, $year, $options);

        Log::info('GenealogySourceService: Built query variations', [
            'count' => count($queryVariations),
            'record_type' => $recordType,
        ]);

        // Run searches with time limit
        foreach ($queryVariations as $queryInfo) {
            // Check time limit
            if (time() - $startTime > $timeLimit) {
                Log::info('GenealogySourceService: Time limit reached', [
                    'elapsed' => time() - $startTime,
                    'queries_completed' => count($queriesUsed),
                ]);
                break;
            }

            $query = $queryInfo['query'];
            $queriesUsed[] = $query;

            try {
                $searchOptions = array_merge($options, [
                    'record_type' => $recordType,
                    'limit' => $queryInfo['limit'] ?? 10,
                ]);

                if ($year) {
                    // Expand date range for better coverage
                    $searchOptions['date_start'] = (int) $year - 5;
                    $searchOptions['date_end'] = (int) $year + 5;
                }

                $results = $this->searchAll($query, $searchOptions);

                if ($results['success'] && ! empty($results['results'])) {
                    $allResults = array_merge($allResults, $results['results']);
                    $sourcesSearched = array_unique(array_merge(
                        $sourcesSearched,
                        $results['sources_searched'] ?? []
                    ));

                    Log::info('GenealogySourceService: Query returned results', [
                        'query' => substr($query, 0, 50),
                        'results' => count($results['results']),
                    ]);
                }

                if (! empty($results['errors'])) {
                    $errors = array_merge($errors, $results['errors']);
                }

                // Small delay between queries to avoid rate limiting
                usleep(500000); // 500ms

            } catch (\Exception $e) {
                Log::warning('GenealogySourceService: Query failed in deep research', [
                    'query' => substr($query, 0, 50),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Deduplicate results by URL or ID
        $uniqueResults = $this->deduplicateResults($allResults);

        // Sort by relevance (prefer marriage records if searching for marriage, etc.)
        usort($uniqueResults, function ($a, $b) use ($recordType) {
            $aMatch = ($a['type'] ?? '') === $recordType ? 1 : 0;
            $bMatch = ($b['type'] ?? '') === $recordType ? 1 : 0;

            return $bMatch - $aMatch;
        });

        $elapsed = time() - $startTime;

        Log::info('GenealogySourceService: Deep research completed', [
            'elapsed_seconds' => $elapsed,
            'queries_run' => count($queriesUsed),
            'total_results' => count($uniqueResults),
            'sources' => $sourcesSearched,
        ]);

        return [
            'success' => ! empty($uniqueResults),
            'description' => $description,
            'record_type' => $recordType,
            'sources_searched' => $sourcesSearched,
            'queries_used' => $queriesUsed,
            'total_results' => count($uniqueResults),
            'results' => array_slice($uniqueResults, 0, $options['max_results'] ?? 50),
            'errors' => $errors,
            'elapsed_seconds' => $elapsed,
        ];
    }

    /**
     * Build query variations for deep research
     * Includes state/county vital records search patterns
     */
    protected function buildQueryVariations(array $names, string $recordType, ?string $year, array $options): array
    {
        $variations = [];
        $surname = $names['surname'] ?? '';
        $given = $names['given'] ?? '';
        $full = $names['full'] ?? '';

        // Base query from full name
        if ($full) {
            $variations[] = ['query' => $full, 'limit' => 15];
        }

        // Record type specific queries
        switch ($recordType) {
            case 'marriage':
                if ($surname) {
                    $variations[] = ['query' => "{$surname} marriage", 'limit' => 10];
                    $variations[] = ['query' => "{$full} married", 'limit' => 10];
                    $variations[] = ['query' => "{$full} wedding", 'limit' => 10];
                    $variations[] = ['query' => "{$surname} marriage license", 'limit' => 10];
                    $variations[] = ['query' => "{$full} marriage certificate", 'limit' => 10];
                    $variations[] = ['query' => "{$surname} bride groom", 'limit' => 10];
                    if ($year) {
                        $variations[] = ['query' => "{$full} married {$year}", 'limit' => 15];
                        $variations[] = ['query' => "{$surname} marriage {$year}", 'limit' => 10];
                        // Vital records patterns
                        $variations[] = ['query' => "{$full} marriage record {$year}", 'limit' => 10];
                    }
                }
                break;

            case 'obituary':
                if ($surname) {
                    $variations[] = ['query' => "{$full} obituary", 'limit' => 10];
                    $variations[] = ['query' => "{$surname} death", 'limit' => 10];
                    $variations[] = ['query' => "{$full} funeral", 'limit' => 10];
                    $variations[] = ['query' => "{$full} passed away", 'limit' => 10];
                    $variations[] = ['query' => "{$full} death certificate", 'limit' => 10];
                    if ($year) {
                        $variations[] = ['query' => "{$full} died {$year}", 'limit' => 15];
                        $variations[] = ['query' => "{$full} death record {$year}", 'limit' => 10];
                    }
                }
                break;

            case 'birth':
                if ($surname) {
                    $variations[] = ['query' => "{$full} born", 'limit' => 10];
                    $variations[] = ['query' => "{$full} birth", 'limit' => 10];
                    $variations[] = ['query' => "{$surname} baby", 'limit' => 10];
                    $variations[] = ['query' => "{$full} birth certificate", 'limit' => 10];
                    $variations[] = ['query' => "{$full} christening baptism", 'limit' => 10];
                    if ($year) {
                        $variations[] = ['query' => "{$full} born {$year}", 'limit' => 15];
                        $variations[] = ['query' => "{$full} birth record {$year}", 'limit' => 10];
                    }
                }
                break;

            default:
                if ($surname) {
                    $variations[] = ['query' => "{$full} Pennsylvania", 'limit' => 10];
                    $variations[] = ['query' => "{$surname} family", 'limit' => 10];
                    $variations[] = ['query' => "{$full} vital records", 'limit' => 10];
                }
        }

        // State/location variations if provided
        $state = $options['state'] ?? null;
        if ($state && $full) {
            $variations[] = ['query' => "{$full} {$state}", 'limit' => 10];
            // State vital records patterns
            $variations[] = ['query' => "{$full} {$state} vital records", 'limit' => 10];
            $variations[] = ['query' => "{$full} {$state} county records", 'limit' => 10];
        }

        // Pennsylvania-specific variations (common for this project's genealogy research)
        if ($full && (! $state || strtolower($state) === 'pennsylvania' || strtolower($state) === 'pa')) {
            $paCounties = ['Columbia', 'Montour', 'Northumberland', 'Snyder', 'Union', 'Lycoming'];
            // Add a couple random county variations for broader coverage
            $selectedCounties = array_slice($paCounties, 0, 2);
            foreach ($selectedCounties as $county) {
                $variations[] = ['query' => "{$full} {$county} County Pennsylvania", 'limit' => 8];
            }
        }

        return $variations;
    }

    /**
     * Deduplicate results by URL or ID
     */
    protected function deduplicateResults(array $results): array
    {
        $seen = [];
        $unique = [];

        foreach ($results as $result) {
            $key = $result['url'] ?? $result['id'] ?? json_encode($result);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $result;
            }
        }

        return $unique;
    }

    // ========================================================================
    // SOURCE-SPECIFIC SEARCH METHODS
    // ========================================================================

    /**
     * Resolve the per-provider cap for a single searchAll() run.
     * Options override → config → safe default (10). A cap ≤ 0 disables
     * capping entirely (each provider's full result set contributes).
     */
    protected function perProviderCap(array $options): int
    {
        $fromOptions = $options['per_provider_cap'] ?? null;
        if ($fromOptions !== null) {
            $cap = (int) $fromOptions;

            return $cap > 0 ? $cap : PHP_INT_MAX;
        }

        $cap = (int) config('genealogy.search_all.per_provider_cap', 10);

        return $cap > 0 ? $cap : PHP_INT_MAX;
    }

    /**
     * Wrap a free-form query as an exact-phrase search so downstream
     * providers return same-person hits rather than OR-across-token
     * scatter (e.g., "Michael" in one article and "Smith" in another).
     *
     * Opt-out: pass `$options['allow_loose'] = true`, or set the
     * per-provider fallback_loose flag in config/genealogy.php.
     *
     * No-op conditions: already-quoted queries, queries containing boolean
     * operators (AND/OR/NOT), and single-token queries.
     */
    protected function tightenPhraseQuery(string $query, string $providerKey, array $options = []): string
    {
        // Structured-query bypass: callers that assemble a multi-field query
        // (e.g. searchNARACensus combining surname + given_name + state + year)
        // pass ['structured_query' => true] to skip phrase-tightening without
        // triggering the fallback_loose warning. The tokens are not prose and
        // should not be joined into an exact phrase.
        if (! empty($options['structured_query'])) {
            return $query;
        }

        $fallbackLooseFromOption = array_key_exists('allow_loose', $options);
        $fallbackLoose = $fallbackLooseFromOption
            ? (bool) $options['allow_loose']
            : (bool) config("genealogy.name_match.fallback_loose.{$providerKey}", false);

        if ($fallbackLoose) {
            // Emit a single warning per call so the operator has a
            // grep-able signal when a provider is running with the
            // proximity guardrail down (2.1c audit finding: silent
            // escape hatch defeated the gate intent).
            Log::warning('GenealogySourceService: fallback_loose active — query NOT tightened', [
                'provider' => $providerKey,
                'source' => $fallbackLooseFromOption ? 'option' : 'config',
                'query_preview' => mb_substr($query, 0, 120),
            ]);

            return $query;
        }

        $trimmed = trim($query);
        if ($trimmed === '') {
            return $query;
        }

        // Already-quoted → trust caller.
        if (str_contains($trimmed, '"')) {
            return $query;
        }

        // Boolean operators in any case, plus common symbol syntaxes.
        if (preg_match('/\b(and|or|not)\b/i', $trimmed)
            || str_contains($trimmed, ' + ')
            || str_contains($trimmed, ' | ')) {
            return $query;
        }

        // Single-token query — nothing to scatter, nothing to tighten.
        if (substr_count($trimmed, ' ') === 0) {
            return $query;
        }

        return '"'.$trimmed.'"';
    }

    /**
     * Search Library of Congress Chronicling America
     * FREE API - No authentication required
     *
     * Query-layer hardening (2.1c): multi-word queries are wrapped in
     * double quotes so LoC's full-text search returns exact-phrase hits.
     * (The plan's original `proxtext`/`proxdistance` syntax is supported
     * by the legacy `chroniclingamerica.loc.gov` endpoint but NOT the
     * newer `loc.gov/collections/` endpoint used here — quote-wrap is the
     * portable equivalent.)
     */
    protected function searchChroniclingAmerica(string $query, array $options = []): array
    {
        $this->rateLimit('chronicling_america');

        $url = 'https://www.loc.gov/collections/chronicling-america/';
        $params = [
            'fo' => 'json',
            'q' => $this->tightenPhraseQuery($query, 'chronicling_america', $options),
            'c' => $options['limit'] ?? 20,
            'sp' => $options['page'] ?? 1,
        ];

        // Date range
        if (! empty($options['date_start']) || ! empty($options['date_end'])) {
            $dateFilter = ($options['date_start'] ?? '').'/'.($options['date_end'] ?? '');
            $params['dates'] = trim($dateFilter, '/');
        }

        // State filter
        if (! empty($options['state'])) {
            $params['fa'] = 'location:'.$options['state'];
        }

        Log::info('GenealogySourceService: Searching Chronicling America', [
            'query' => $query,
            'params' => $params,
        ]);

        $response = Http::connectTimeout(5)->timeout(30)->get($url, $params);

        if (! $response->successful()) {
            return [
                'success' => false,
                'error' => "LOC API error: HTTP {$response->status()}",
                'results' => [],
            ];
        }

        $data = $response->json();
        $results = [];

        foreach ($data['results'] ?? [] as $item) {
            $results[] = $this->normalizeResult($item, 'chronicling_america');
        }

        return [
            'success' => true,
            'source' => 'Library of Congress - Chronicling America',
            'total_count' => $data['pagination']['total'] ?? count($results),
            'results' => $results,
        ];
    }

    /**
     * Search Newspapers.com Library Edition
     * Requires library barcode authentication via cookies
     */
    protected function searchNewspapersCom(string $query, array $options = [], string $barcode = ''): array
    {
        $this->rateLimit('newspapers_com');

        // Newspapers.com uses Cloudflare protection
        // We'll use their search API with session cookies

        // First, try to get/reuse session cookie
        $sessionCookie = $this->getNewspapersComSession($barcode);

        if (! $sessionCookie) {
            return [
                'success' => false,
                'error' => 'Could not establish Newspapers.com session',
                'results' => [],
            ];
        }

        $url = 'https://www.newspapers.com/api/search/hits';
        $params = [
            'query' => $this->tightenPhraseQuery($query, 'newspapers_com', $options),
            'page' => $options['page'] ?? 1,
            'perpage' => $options['limit'] ?? 20,
        ];

        // Date range
        if (! empty($options['date_start'])) {
            $params['dr'] = ($options['date_start'] ?? '1700').'-'.($options['date_end'] ?? '2000');
        }

        Log::info('GenealogySourceService: Searching Newspapers.com', [
            'query' => $query,
        ]);

        try {
            $response = Http::connectTimeout(5)->withHeaders([
                'Cookie' => $sessionCookie,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'application/json',
            ])->timeout(30)->get($url, $params);

            if (! $response->successful()) {
                // Session might be invalid, clear cache
                Cache::forget('newspapers_com_session');

                return [
                    'success' => false,
                    'error' => "Newspapers.com error: HTTP {$response->status()}",
                    'results' => [],
                ];
            }

            $data = $response->json();
            $results = [];

            foreach ($data['hits'] ?? $data['records'] ?? [] as $item) {
                $results[] = $this->normalizeResult($item, 'newspapers_com');
            }

            return [
                'success' => true,
                'source' => 'Newspapers.com Library Edition',
                'total_count' => $data['total'] ?? count($results),
                'results' => $results,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    /**
     * Get or create Newspapers.com session
     */
    protected function getNewspapersComSession(string $barcode): ?string
    {
        $cacheKey = 'newspapers_com_session';
        $cooldownKey = 'newspapers_com_session_unavailable';

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        if (Cache::has($cooldownKey)) {
            return null;
        }

        // Authenticate with library barcode
        try {
            // Step 1: Get initial cookies and CSRF token
            $initialResponse = Http::connectTimeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->get('https://www.newspapers.com/account/libcard');

            if (! $initialResponse->successful()) {
                Cache::put($cooldownKey, true, self::NEWSPAPERS_SESSION_COOLDOWN_SECONDS);
                Log::info('GenealogySourceService: Newspapers.com login page unavailable, cooling down source', [
                    'status' => $initialResponse->status(),
                ]);

                return null;
            }

            $cookies = $initialResponse->cookies();
            $cookieString = $this->extractCookieString($cookies);

            // Step 2: Submit library card login
            $loginResponse = Http::connectTimeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Cookie' => $cookieString,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Referer' => 'https://www.newspapers.com/account/libcard',
            ])->asForm()->post('https://www.newspapers.com/account/libcard', [
                'barcode' => $barcode,
            ]);

            if ($loginResponse->successful() || $loginResponse->status() === 302) {
                // Extract session cookies
                $allCookies = array_merge(
                    $this->extractCookieString($cookies),
                    $this->extractCookieString($loginResponse->cookies())
                );

                $sessionCookie = is_array($allCookies) ? implode('; ', $allCookies) : $allCookies;

                // Cache for 1 hour
                Cache::put($cacheKey, $sessionCookie, 3600);

                Log::info('GenealogySourceService: Newspapers.com session established');

                return $sessionCookie;
            }

            Log::warning('GenealogySourceService: Newspapers.com login failed', [
                'status' => $loginResponse->status(),
            ]);
            Cache::put($cooldownKey, true, self::NEWSPAPERS_SESSION_COOLDOWN_SECONDS);

            return null;

        } catch (\Exception $e) {
            Cache::put($cooldownKey, true, self::NEWSPAPERS_SESSION_COOLDOWN_SECONDS);
            Log::warning('GenealogySourceService: Newspapers.com auth error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Search Europeana Newspapers
     * FREE API - Requires API key
     */
    public function searchEuropeana(string $query, array $options = [], string $apiKey = ''): array
    {
        $this->rateLimit('europeana');

        $url = 'https://api.europeana.eu/record/v2/search.json';
        $params = [
            'wskey' => $apiKey,
            'query' => $this->tightenPhraseQuery($query, 'europeana', $options),
            'qf' => 'TYPE:TEXT', // Newspaper type
            'rows' => $options['limit'] ?? 20,
            'start' => (($options['page'] ?? 1) - 1) * ($options['limit'] ?? 20) + 1,
        ];

        // Date range - Europeana uses YEAR format
        if (! empty($options['date_start'])) {
            $params['qf'] = $params['qf'].' AND YEAR:['.$options['date_start'].' TO '.($options['date_end'] ?? '*').']';
        }

        Log::info('GenealogySourceService: Searching Europeana', [
            'query' => $query,
        ]);

        $response = Http::connectTimeout(5)->timeout(30)->get($url, $params);

        if (! $response->successful()) {
            return [
                'success' => false,
                'error' => "Europeana API error: HTTP {$response->status()}",
                'results' => [],
            ];
        }

        $data = $response->json();
        $results = [];

        foreach ($data['items'] ?? [] as $item) {
            $results[] = $this->normalizeResult($item, 'europeana');
        }

        return [
            'success' => true,
            'source' => 'Europeana Newspapers',
            'total_count' => $data['totalResults'] ?? count($results),
            'results' => $results,
        ];
    }

    /**
     * Search National Archives Catalog (NARA) — v2 API
     * Requires API key (x-api-key header). 10K calls/month limit.
     * Key loaded from genealogy_research_providers table.
     */
    public function searchNARA(string $query, array $options = []): array
    {
        $startTime = microtime(true);
        $this->rateLimit('nara');

        // Load API key from DB
        $apiKey = $this->getNaraApiKey();
        if (! $apiKey) {
            Log::warning('GenealogySourceService: NARA API key not configured — skipping');

            return [
                'success' => false,
                'error' => 'NARA API key not configured in genealogy_research_providers',
                'results' => [],
            ];
        }

        $url = 'https://catalog.archives.gov/api/v2/records/search';
        $params = [
            'q' => $this->tightenPhraseQuery($query, 'nara', $options),
            'limit' => $options['limit'] ?? 20,
            'offset' => (($options['page'] ?? 1) - 1) * ($options['limit'] ?? 20),
        ];

        // Filter by record group for record types
        if (! empty($options['record_type'])) {
            $typeMap = [
                'military' => 'Military Records',
                'census' => 'Records of the Bureau of the Census',
                'immigration' => 'Records of the Immigration and Naturalization Service',
                'naturalization' => 'Naturalization Records',
                'court' => 'Records of District Courts of the United States',
                'land' => 'Records of the Bureau of Land Management',
                'pension' => 'Pension Records',
                'bounty_land' => 'Bounty Land Warrant',
                'patent' => 'Patent Records',
                'passport' => 'Passport Applications',
                'homestead' => 'Homestead Records',
            ];
            if (isset($typeMap[$options['record_type']])) {
                $params['q'] .= ' '.$typeMap[$options['record_type']];
            }
        }

        Log::info('GenealogySourceService: Searching NARA v2', [
            'query' => $query,
        ]);

        $response = Http::connectTimeout(5)->withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->get($url, $params);

        if (! $response->successful()) {
            $this->logNaraMetric($query, $options, 0, false, $startTime);

            return [
                'success' => false,
                'error' => "NARA API error: HTTP {$response->status()}",
                'results' => [],
            ];
        }

        $data = $response->json();
        $hits = $data['body']['hits']['hits'] ?? [];
        $results = [];

        foreach ($hits as $hit) {
            $record = $hit['_source']['record'] ?? [];
            $results[] = $this->normalizeNaraV2Result($record, $hit['_id'] ?? null);
        }

        $result = [
            'success' => true,
            'source' => 'National Archives (NARA)',
            'total_count' => $data['body']['hits']['total']['value'] ?? count($results),
            'results' => $results,
        ];

        // Log metrics for monitoring
        $this->logNaraMetric($query, $options, count($results), true, $startTime);

        return $result;
    }

    /**
     * Search NARA specifically for US Census records.
     *
     * Supports year-specific census searches (1790-1950) with state/county filters.
     * Maps census years to NARA record group numbers for precise results.
     *
     * Query-layer hardening (2.1c): this path already accepts structured
     * given/surname inputs separately, so no phrase-tightening is needed —
     * the query is assembled from known name components, not free-form.
     * The downstream NARA API treats separate tokens as AND by default in
     * census record searches.
     */
    public function searchNARACensus(?string $surname, array $options = []): array
    {
        if (empty($surname)) {
            return ['success' => false, 'error' => 'surname is required', 'results' => []];
        }
        $year = $options['year'] ?? null;
        $state = $options['state'] ?? null;
        $givenName = $options['given_name'] ?? null;

        // Build targeted census query
        $queryParts = [$surname];
        if ($givenName) {
            $queryParts[] = $givenName;
        }
        if ($state) {
            $queryParts[] = $state;
        }

        // Census year → NARA record group mapping
        $censusGroups = [
            1790 => 'First Census',
            1800 => 'Second Census',
            1810 => 'Third Census',
            1820 => 'Fourth Census',
            1830 => 'Fifth Census',
            1840 => 'Sixth Census',
            1850 => 'Seventh Census',
            1860 => 'Eighth Census',
            1870 => 'Ninth Census',
            1880 => 'Tenth Census',
            1890 => 'Eleventh Census',
            1900 => 'Twelfth Census',
            1910 => 'Thirteenth Census',
            1920 => 'Fourteenth Census',
            1930 => 'Fifteenth Census',
            1940 => 'Sixteenth Census',
            1950 => 'Seventeenth Census',
        ];

        if ($year && isset($censusGroups[$year])) {
            $queryParts[] = $censusGroups[$year];
        } else {
            $queryParts[] = 'Census';
        }

        $query = implode(' ', $queryParts);

        // Structured census queries are multi-field tokens, not free-form prose,
        // and should NOT be wrapped into an exact phrase by tightenPhraseQuery().
        // The bypass flag keeps direct searchNARA() callers tightening as before
        // without emitting the fallback_loose warning for routine census work.
        $searchOptions = array_merge($options, [
            'record_type' => 'census',
            'limit' => $options['limit'] ?? 20,
            'structured_query' => true,
        ]);

        $result = $this->searchNARA($query, $searchOptions);

        // Add census context to results
        if ($result['success']) {
            $result['census_year'] = $year;
            $result['search_context'] = "Census search for {$surname}".
                ($year ? " ({$year})" : '').
                ($state ? " in {$state}" : '');
        }

        return $result;
    }

    /**
     * Log NARA search metrics for monitoring and hit rate tracking.
     */
    private function logNaraMetric(string $query, array $options, int $resultCount, bool $success, float $startTime): void
    {
        try {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            DB::insert(
                'INSERT INTO genealogy_source_metrics
                    (tool_name, source_id, source_name, query_params, result_count, success, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    'nara_search',
                    'nara',
                    'National Archives (NARA)',
                    json_encode(['query' => $query, 'record_type' => $options['record_type'] ?? null]),
                    $resultCount,
                    $success,
                    $durationMs,
                ]
            );
        } catch (\Exception $e) {
            Log::warning('GenealogySourceService: Failed to log NARA metric', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Normalize a NARA v2 API result into the standard result format.
     */
    protected function normalizeNaraV2Result(array $record, ?string $naId): array
    {
        $title = $record['title'] ?? 'Untitled';
        $ancestors = $record['ancestors'] ?? [];
        $seriesTitle = null;
        foreach ($ancestors as $ancestor) {
            if (($ancestor['levelOfDescription'] ?? '') === 'series') {
                $seriesTitle = $ancestor['title'] ?? null;
                break;
            }
        }

        // Extract date from various v2 locations
        $date = null;
        if (! empty($record['inclusiveStartDate']['year'])) {
            $date = (string) $record['inclusiveStartDate']['year'];
            if (! empty($record['inclusiveEndDate']['year']) && $record['inclusiveEndDate']['year'] !== $record['inclusiveStartDate']['year']) {
                $date .= '-'.$record['inclusiveEndDate']['year'];
            }
        }

        // Extract location from physical occurrences
        $location = null;
        $refUnits = $record['physicalOccurrences'][0]['referenceUnits'][0] ?? null;
        if ($refUnits) {
            $location = $refUnits['name'] ?? null;
        }

        // Extract digital objects (downloadable files)
        $digitalObjects = [];
        foreach ($record['digitalObjects'] ?? [] as $obj) {
            $digitalObjects[] = $this->normalizeNaraDigitalObject($obj);
        }

        return [
            'source' => 'National Archives',
            'id' => $naId ?? ($record['naId'] ?? null),
            'title' => $this->toStr($title),
            'date' => $date,
            'url' => 'https://catalog.archives.gov/id/'.($naId ?? ($record['naId'] ?? '')),
            'description' => $this->toStr($record['scopeAndContentNote'] ?? null),
            'location' => $this->toStr($location),
            'type' => $this->detectRecordType($record),
            'record_group' => $this->toStr($seriesTitle),
            'level' => $record['levelOfDescription'] ?? null,
            'digital_objects' => $digitalObjects,
            'has_digital' => ! empty($digitalObjects),
        ];
    }

    /**
     * Get NARA API key from genealogy_research_providers table.
     */
    protected function getNaraApiKey(): ?string
    {
        return Cache::remember('nara_api_key', 3600, function () {
            $row = DB::selectOne("SELECT api_key FROM genealogy_research_providers WHERE provider_id = 'nara' AND is_active = 1");

            return $row->api_key ?? null;
        });
    }

    /**
     * Search FindAGrave (FREE - memorial search)
     */
    protected function searchFindAGrave(string $query, array $options = []): array
    {
        $this->rateLimit('findagrave');

        $names = $this->extractNames($query);

        // FindAGrave search API
        $url = 'https://www.findagrave.com/memorial/search';
        $params = [
            'firstname' => $names['given'] ?? '',
            'middlename' => '',
            'lastname' => $names['surname'] ?? '',
            'page' => $options['page'] ?? 1,
            'cemeteryCountry' => '4', // USA
        ];

        // Add date filters
        if (! empty($options['date_start'])) {
            $params['birthyearfrom'] = $options['date_start'];
        }
        if (! empty($options['date_end'])) {
            $params['deathyearto'] = $options['date_end'];
        }

        Log::info('GenealogySourceService: Searching FindAGrave', [
            'query' => $query,
            'names' => $names,
        ]);

        try {
            $response = Http::connectTimeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/json',
            ])->timeout(30)->get($url, $params);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => "FindAGrave error: HTTP {$response->status()}",
                    'results' => [],
                ];
            }

            // Parse HTML response for memorial data
            $html = $response->body();
            $results = $this->parseFindAGraveResults($html, $options['limit'] ?? 10);

            return [
                'success' => true,
                'source' => 'FindAGrave',
                'total_count' => count($results),
                'results' => $results,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    /**
     * Parse FindAGrave HTML results
     */
    protected function parseFindAGraveResults(string $html, int $limit = 10): array
    {
        $results = [];

        // Match memorial cards in the HTML
        preg_match_all('/<div[^>]*class="[^"]*memorial-item[^"]*"[^>]*>(.*?)<\/div>/s', $html, $matches);

        // Alternative pattern for newer layout
        if (empty($matches[0])) {
            preg_match_all('/<a[^>]*href="\/memorial\/(\d+)\/([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $linkMatches);

            foreach (array_slice($linkMatches[1] ?? [], 0, $limit) as $i => $memorialId) {
                $name = urldecode($linkMatches[2][$i] ?? '');
                $name = str_replace(['-', '_'], ' ', $name);

                $results[] = [
                    'source' => 'FindAGrave',
                    'id' => $memorialId,
                    'title' => ucwords($name),
                    'url' => "https://www.findagrave.com/memorial/{$memorialId}",
                    'description' => $linkMatches[3][$i] ?? null,
                    'type' => 'burial',
                ];
            }
        }

        return $results;
    }

    /**
     * Search BillionGraves (FREE public search)
     * Scrapes the public HTML search results page
     */
    protected function searchBillionGraves(string $query, array $options = []): array
    {
        $this->rateLimit('billiongraves');

        $names = $this->extractNames($query);

        // Build the public search URL
        $searchParams = [];
        if (! empty($names['given'])) {
            $searchParams['given_names'] = $names['given'];
        }
        if (! empty($names['surname'])) {
            $searchParams['family_names'] = $names['surname'];
        }
        $searchParams['country'] = 'United States';

        // Date filters
        if (! empty($options['date_start'])) {
            $searchParams['birth_year_from'] = $options['date_start'];
        }
        if (! empty($options['date_end'])) {
            $searchParams['death_year_to'] = $options['date_end'];
        }

        $url = 'https://billiongraves.com/search/results?'.http_build_query($searchParams);

        Log::info('GenealogySourceService: Searching BillionGraves', [
            'query' => $query,
            'url' => $url,
        ]);

        try {
            $response = Http::connectTimeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ])->timeout(30)->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => "BillionGraves error: HTTP {$response->status()}",
                    'results' => [],
                ];
            }

            $html = $response->body();
            $results = $this->parseBillionGravesResults($html, $options['limit'] ?? 10);

            return [
                'success' => true,
                'source' => 'BillionGraves',
                'total_count' => count($results),
                'results' => $results,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    /**
     * Parse BillionGraves HTML search results
     */
    protected function parseBillionGravesResults(string $html, int $limit = 10): array
    {
        $results = [];

        // BillionGraves uses data attributes or JSON in script tags
        // Try to find embedded JSON first
        if (preg_match('/<script[^>]*>.*?window\.__PRELOADED_STATE__\s*=\s*({.*?});?\s*<\/script>/s', $html, $matches)) {
            $jsonData = json_decode($matches[1], true);
            $searchResults = $jsonData['search']['results'] ?? $jsonData['results'] ?? [];

            foreach (array_slice($searchResults, 0, $limit) as $item) {
                $results[] = [
                    'source' => 'BillionGraves',
                    'id' => $item['record_id'] ?? $item['id'] ?? null,
                    'title' => ($item['given_names'] ?? '').' '.($item['family_names'] ?? ''),
                    'date' => $item['birth_year'] ?? null,
                    'death_date' => $item['death_year'] ?? null,
                    'url' => isset($item['record_id']) ? "https://billiongraves.com/grave/{$item['record_id']}" : null,
                    'description' => $item['cemetery_name'] ?? null,
                    'location' => ($item['cemetery_city'] ?? '').', '.($item['cemetery_state'] ?? ''),
                    'type' => 'burial',
                ];
            }
        }

        // Fallback: parse HTML search result cards
        if (empty($results)) {
            // Match grave cards in the HTML
            preg_match_all('/<a[^>]*href="\/grave\/(\d+)[^"]*"[^>]*class="[^"]*search-result[^"]*"[^>]*>(.*?)<\/a>/s', $html, $cardMatches);

            if (empty($cardMatches[0])) {
                // Alternative pattern for grave links
                preg_match_all('/<a[^>]*href="\/grave\/(\d+)\/([^"]+)"[^>]*>/s', $html, $linkMatches);

                foreach (array_slice($linkMatches[1] ?? [], 0, $limit) as $i => $graveId) {
                    $name = urldecode($linkMatches[2][$i] ?? '');
                    $name = str_replace(['-', '_'], ' ', $name);
                    $results[] = [
                        'source' => 'BillionGraves',
                        'id' => $graveId,
                        'title' => ucwords($name),
                        'url' => "https://billiongraves.com/grave/{$graveId}",
                        'type' => 'burial',
                    ];
                }
            } else {
                foreach (array_slice($cardMatches[1] ?? [], 0, $limit) as $i => $graveId) {
                    $cardHtml = $cardMatches[2][$i] ?? '';
                    // Extract name from card
                    preg_match('/<[^>]*class="[^"]*name[^"]*"[^>]*>([^<]+)</', $cardHtml, $nameMatch);
                    $results[] = [
                        'source' => 'BillionGraves',
                        'id' => $graveId,
                        'title' => trim($nameMatch[1] ?? 'Unknown'),
                        'url' => "https://billiongraves.com/grave/{$graveId}",
                        'type' => 'burial',
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Search WikiTree (FREE collaborative genealogy)
     * WikiTree has a public API
     */
    protected function searchWikiTree(string $query, array $options = []): array
    {
        $this->rateLimit('wikitree');

        $names = $this->extractNames($query);

        // WikiTree API endpoint
        $url = 'https://api.wikitree.com/api.php';
        $params = [
            'action' => 'searchPerson',
            'format' => 'json',
            'LastName' => $names['surname'] ?? '',
            'FirstName' => $names['given'] ?? '',
            'limit' => $options['limit'] ?? 10,
        ];

        Log::info('GenealogySourceService: Searching WikiTree', [
            'query' => $query,
            'names' => $names,
        ]);

        try {
            $response = Http::connectTimeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; GenealogyResearchBot/1.0)',
                'Accept' => 'application/json',
            ])->timeout(30)->get($url, $params);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => "WikiTree error: HTTP {$response->status()}",
                    'results' => [],
                ];
            }

            $data = $response->json();
            $results = [];

            foreach ($data['searchPerson'] ?? $data['matches'] ?? $data as $item) {
                if (! is_array($item) || empty($item)) {
                    continue;
                }

                $wikiTreeId = $item['Name'] ?? $item['Id'] ?? null;
                $results[] = [
                    'source' => 'WikiTree',
                    'id' => $wikiTreeId,
                    'title' => ($item['FirstName'] ?? '').' '.($item['LastName'] ?? $item['LastNameAtBirth'] ?? ''),
                    'date' => $item['BirthDate'] ?? $item['BirthYear'] ?? null,
                    'death_date' => $item['DeathDate'] ?? $item['DeathYear'] ?? null,
                    'url' => $wikiTreeId ? "https://www.wikitree.com/wiki/{$wikiTreeId}" : null,
                    'location' => $item['BirthLocation'] ?? null,
                    'type' => 'family_tree',
                ];
            }

            return [
                'success' => true,
                'source' => 'WikiTree',
                'total_count' => count($results),
                'results' => $results,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    /**
     * Extract first and last names from a query string
     */
    protected function extractNames(string $query): array
    {
        // Remove common genealogy keywords
        $cleaned = preg_replace('/\b(marriage|married|wedding|obituary|death|birth|born|Research)\b/i', '', $query);
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));

        // Split by "and" to get both people in a marriage query
        $people = preg_split('/\s+and\s+/i', $cleaned);
        $person = trim($people[0] ?? $cleaned);

        // Try to extract name parts
        $parts = preg_split('/\s+/', $person);

        if (count($parts) >= 2) {
            return [
                'given' => $parts[0],
                'surname' => end($parts),
                'full' => $person,
            ];
        }

        return [
            'given' => '',
            'surname' => $person,
            'full' => $person,
        ];
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Normalize result from any source to common format
     */
    protected function normalizeResult(array $item, string $source): array
    {
        switch ($source) {
            case 'chronicling_america':
                $title = $item['title'] ?? $item['item']['title'] ?? 'Untitled';
                if (is_array($title)) {
                    $title = $title[0] ?? 'Untitled';
                }

                return [
                    'source' => 'Library of Congress',
                    'id' => $item['id'] ?? null,
                    'title' => $title,
                    'newspaper' => $this->toStr($item['partof']['title'] ?? null),
                    'date' => $this->toStr($item['date'] ?? null),
                    'url' => $this->toStr($item['url'] ?? $item['id'] ?? null),
                    'description' => $this->toStr($item['description'] ?? null),
                    'location' => $this->toStr($item['location'] ?? null),
                    'type' => $this->detectRecordType($item),
                ];

            case 'newspapers_com':
                return [
                    'source' => 'Newspapers.com',
                    'id' => $item['id'] ?? $item['page_id'] ?? null,
                    'title' => $this->toStr($item['title'] ?? $item['snippet'] ?? 'Untitled'),
                    'newspaper' => $this->toStr($item['publication'] ?? $item['pub_name'] ?? null),
                    'date' => $this->toStr($item['pub_date'] ?? $item['date'] ?? null),
                    'url' => isset($item['id']) ? "https://www.newspapers.com/image/{$item['id']}" : null,
                    'description' => $this->toStr($item['snippet'] ?? $item['text'] ?? null),
                    'location' => $this->toStr($item['location'] ?? $item['pub_location'] ?? null),
                    'type' => $this->detectRecordType($item),
                ];

            case 'europeana':
                return [
                    'source' => 'Europeana',
                    'id' => $item['id'] ?? null,
                    'title' => $this->toStr($item['title'] ?? 'Untitled'),
                    'newspaper' => $this->toStr($item['dataProvider'][0] ?? null),
                    'date' => $this->toStr($item['year'][0] ?? null),
                    'url' => $this->toStr($item['guid'] ?? null),
                    'description' => $this->toStr($item['dcDescription'] ?? null),
                    'location' => $this->toStr($item['country'][0] ?? null),
                    'type' => $this->detectRecordType($item),
                ];

            case 'nara':
                // v1 format — kept for backward compat with any cached/stored results
                // New searches use normalizeNaraV2Result() directly
                $desc = $item['description'] ?? [];

                return [
                    'source' => 'National Archives',
                    'id' => $item['naId'] ?? null,
                    'title' => $this->toStr($desc['item']['title'] ?? $desc['fileUnit']['title'] ?? 'Untitled'),
                    'date' => $this->toStr($desc['item']['productionDateArray']['proposableQualifiableDate']['year'] ?? null),
                    'url' => 'https://catalog.archives.gov/id/'.($item['naId'] ?? ''),
                    'description' => $this->toStr($desc['item']['scopeAndContentNote'] ?? null),
                    'location' => $this->toStr($desc['item']['geographicReferenceArray']['geographicPlaceName']['termName'] ?? null),
                    'type' => $this->detectRecordType($item),
                    'record_group' => $this->toStr($desc['item']['parentSeries']['title'] ?? null),
                ];

            case 'familysearch':
                $person = $item['person'] ?? $item;

                return [
                    'source' => 'FamilySearch',
                    'id' => $person['id'] ?? $item['id'] ?? null,
                    'title' => $this->toStr($person['name'] ?? $person['display']['name'] ?? 'Unknown'),
                    'date' => $this->toStr($person['display']['birthDate'] ?? $person['birthDate'] ?? null),
                    'death_date' => $this->toStr($person['display']['deathDate'] ?? $person['deathDate'] ?? null),
                    'url' => isset($person['id']) ? "https://www.familysearch.org/ark:/61903/1:1:{$person['id']}" : null,
                    'description' => $this->toStr($person['display']['lifespan'] ?? null),
                    'location' => $this->toStr($person['display']['birthPlace'] ?? $person['birthPlace'] ?? null),
                    'type' => $this->detectRecordType($item),
                    'record_type' => $this->toStr($item['collection']['title'] ?? null),
                ];

            case 'findagrave':
                return [
                    'source' => 'FindAGrave',
                    'id' => $item['id'] ?? $item['memorial_id'] ?? null,
                    'title' => $this->toStr($item['name'] ?? $item['title'] ?? 'Unknown'),
                    'date' => $this->toStr($item['birth_date'] ?? $item['birth'] ?? null),
                    'death_date' => $this->toStr($item['death_date'] ?? $item['death'] ?? null),
                    'url' => $item['url'] ?? (isset($item['id']) ? "https://www.findagrave.com/memorial/{$item['id']}" : null),
                    'description' => $this->toStr($item['description'] ?? null),
                    'location' => $this->toStr($item['cemetery'] ?? $item['location'] ?? null),
                    'type' => 'burial',
                ];

            case 'billiongraves':
                $givenNames = $item['given_names'] ?? '';
                $familyNames = $item['family_names'] ?? '';

                return [
                    'source' => 'BillionGraves',
                    'id' => $item['id'] ?? $item['record_id'] ?? null,
                    'title' => trim($this->toStr($givenNames).' '.$this->toStr($familyNames)),
                    'date' => $this->toStr($item['birth_year'] ?? null),
                    'death_date' => $this->toStr($item['death_year'] ?? null),
                    'url' => isset($item['id']) ? "https://billiongraves.com/grave/{$item['id']}" : ($item['url'] ?? null),
                    'description' => $this->toStr($item['inscription'] ?? null),
                    'location' => $this->toStr($item['cemetery_name'] ?? $item['location'] ?? null),
                    'type' => 'burial',
                ];

            default:
                return $item;
        }
    }

    /**
     * Safely convert a value to string — handles arrays from external APIs
     */
    private function toStr(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return implode(', ', array_filter($value, 'is_string'));
        }

        return (string) $value;
    }

    /**
     * Detect record type from content
     */
    protected function detectRecordType(array $item): string
    {
        $text = strtolower(json_encode($item));

        if (preg_match('/obituar|died|death|funeral|burial|passed away|in loving memory/i', $text)) {
            return 'obituary';
        }
        if (preg_match('/married|marriage|wedding|bride|groom|nuptials|license/i', $text)) {
            return 'marriage';
        }
        if (preg_match('/born|birth|baby|christening|baptism/i', $text)) {
            return 'birth';
        }
        if (preg_match('/military|army|navy|marine|soldier|veteran|war|draft|enlisted/i', $text)) {
            return 'military';
        }
        if (preg_match('/census|enumerat/i', $text)) {
            return 'census';
        }
        if (preg_match('/immigra|passenger|ship|arrival|naturali/i', $text)) {
            return 'immigration';
        }

        return 'other';
    }

    /**
     * Extract cookie string from response
     */
    protected function extractCookieString($cookies): string
    {
        if (is_string($cookies)) {
            return $cookies;
        }

        $parts = [];
        foreach ($cookies as $cookie) {
            if (is_object($cookie) && method_exists($cookie, 'getName')) {
                $parts[] = $cookie->getName().'='.$cookie->getValue();
            } elseif (is_array($cookie)) {
                $parts[] = ($cookie['name'] ?? '').'='.($cookie['value'] ?? '');
            }
        }

        return implode('; ', $parts);
    }

    // ========================================================================
    // NARA DOWNLOAD & FILE REGISTRATION
    // ========================================================================

    /**
     * Get digital objects for a NARA record by naId.
     * Returns downloadable file URLs, formats, and sizes.
     */
    public function getNaraRecord(string $naId): array
    {
        $naId = trim($naId);
        if ($naId === '' || ! preg_match('/^\d+$/', $naId)) {
            return ['success' => false, 'error' => 'Invalid NARA NAID', 'record' => null, 'objects' => []];
        }

        $this->rateLimit('nara');

        $apiKey = $this->getNaraApiKey();
        if (! $apiKey) {
            return ['success' => false, 'error' => 'NARA API key not configured', 'record' => null, 'objects' => []];
        }

        $url = 'https://catalog.archives.gov/api/v2/records/search';
        $attempts = [
            ['strategy' => 'naIds', 'params' => ['naIds' => $naId, 'limit' => 1]],
            ['strategy' => 'q_filtered', 'params' => ['q' => $naId, 'limit' => 10]],
        ];

        try {
            $lastError = null;

            foreach ($attempts as $attempt) {
                $response = Http::connectTimeout(5)->withHeaders([
                    'x-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])->timeout(30)->get($url, $attempt['params']);

                if (! $response->successful()) {
                    $lastError = "NARA API error: HTTP {$response->status()}";

                    continue;
                }

                $data = $response->json();
                $hits = $data['body']['hits']['hits'] ?? [];
                $match = $this->matchingNaraHit($hits, $naId);
                if ($match === null) {
                    $lastError = 'No exact NARA record found for NAID '.$naId;

                    continue;
                }

                $record = $match['_source']['record'] ?? [];

                return [
                    'success' => true,
                    'na_id' => $naId,
                    'title' => $record['title'] ?? 'Untitled',
                    'record' => $record,
                    'hit' => $match,
                    'lookup_strategy' => $attempt['strategy'],
                    'objects' => array_values(array_map(
                        fn (array $obj): array => $this->normalizeNaraDigitalObject($obj),
                        $record['digitalObjects'] ?? []
                    )),
                ];
            }

            return ['success' => false, 'error' => $lastError ?? 'NARA record not found', 'record' => null, 'objects' => []];
        } catch (\Exception $e) {
            Log::error('GenealogySourceService: NARA record fetch failed', [
                'na_id' => $naId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'record' => null, 'objects' => []];
        }
    }

    /**
     * Get digital objects for a NARA record by naId.
     * Returns downloadable file URLs, formats, and sizes.
     */
    public function getNaraDigitalObjects(string $naId): array
    {
        try {
            $recordResult = $this->getNaraRecord($naId);
            if (! ($recordResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $recordResult['error'] ?? 'NARA record not found',
                    'objects' => [],
                ];
            }

            $objects = $recordResult['objects'] ?? [];

            return [
                'success' => true,
                'na_id' => $naId,
                'title' => $recordResult['title'] ?? 'Untitled',
                'objects' => $objects,
                'total' => count($objects),
            ];
        } catch (\Exception $e) {
            Log::error('GenealogySourceService: NARA digital objects fetch failed', [
                'na_id' => $naId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'objects' => []];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     */
    private function matchingNaraHit(array $hits, string $naId): ?array
    {
        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $record = $hit['_source']['record'] ?? [];
            $hitNaId = (string) ($record['naId'] ?? $hit['_id'] ?? '');
            if ($hitNaId === $naId) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public function normalizeNaraDigitalObject(array $object): array
    {
        $url = $this->firstStringField($object, ['downloadUrl', 'objectUrl', 'url']);
        $filename = $this->firstStringField($object, ['fileName', 'objectFilename', 'filename']);
        if (($filename === null || $filename === '') && $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $basename = basename($path);
            $filename = $basename !== '' && $basename !== '.' ? $basename : null;
        }

        $size = $object['size'] ?? $object['objectFileSize'] ?? null;
        if (is_string($size) && is_numeric($size)) {
            $size = (int) $size;
        }

        return [
            'url' => $url,
            'object_id' => $this->firstStringField($object, ['objectId', 'id']),
            'filename' => $filename,
            'format' => $this->firstStringField($object, ['fileFormat', 'objectType', 'format', 'mimeType']),
            'size' => is_int($size) ? $size : null,
            'thumbnail' => $this->firstStringField($object, ['thumbnailUrl', 'objectThumbnailUrl']),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $fields
     */
    private function firstStringField(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Download a digital object from NARA to local storage.
     *
     * @param  string  $naId  NARA record ID
     * @param  string  $downloadUrl  Direct download URL for the digital object
     * @param  string|null  $filename  Override filename (auto-detected from URL if null)
     * @param  string|null  $familySurname  Organize under family name folder
     * @return array Download result with local path
     */
    public function downloadNaraObject(string $naId, string $downloadUrl, ?string $filename = null, ?string $familySurname = null): array
    {
        if (empty($downloadUrl)) {
            return ['success' => false, 'error' => 'Download URL required'];
        }

        // Extract filename from URL if not provided
        if (! $filename) {
            $filename = basename(parse_url($downloadUrl, PHP_URL_PATH));
            if (empty($filename) || $filename === '/') {
                $filename = "nara_{$naId}";
            }
        }

        // Sanitize filename
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Build storage path
        if ($familySurname) {
            $safeSurname = preg_replace('/[^a-zA-Z0-9_-]/', '_', $familySurname);
            $localPath = "nara/genealogy/{$safeSurname}/{$filename}";
        } else {
            $localPath = "nara/{$naId}/{$filename}";
        }

        $fullPath = storage_path('app/'.$localPath);
        $dir = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Skip if already downloaded
        if (file_exists($fullPath) && filesize($fullPath) > 0) {
            return [
                'success' => true,
                'path' => $localPath,
                'full_path' => $fullPath,
                'size' => filesize($fullPath),
                'cached' => true,
                'na_id' => $naId,
            ];
        }

        $this->rateLimit('nara');

        try {
            Log::info('GenealogySourceService: Downloading NARA object', [
                'na_id' => $naId,
                'url' => $downloadUrl,
                'destination' => $localPath,
            ]);

            $response = Http::connectTimeout(5)->timeout(300)
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->withOptions(['sink' => $fullPath])
                ->get($downloadUrl);

            if (! $response->successful()) {
                @unlink($fullPath);

                return ['success' => false, 'error' => 'HTTP '.$response->status(), 'na_id' => $naId];
            }

            $size = filesize($fullPath);

            Log::info('GenealogySourceService: NARA download complete', [
                'na_id' => $naId,
                'file' => $filename,
                'size' => $size,
            ]);

            $this->recordSuccess('nara');

            return [
                'success' => true,
                'path' => $localPath,
                'full_path' => $fullPath,
                'size' => $size,
                'cached' => false,
                'na_id' => $naId,
                'filename' => $filename,
            ];

        } catch (\Exception $e) {
            @unlink($fullPath);
            $this->recordFailure('nara');
            Log::error('GenealogySourceService: NARA download error', [
                'na_id' => $naId,
                'url' => $downloadUrl,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'na_id' => $naId];
        }
    }

    /**
     * Download the best available digital object for a NARA record.
     * Prefers: TIFF > JPG/JPEG > PDF > PNG > any other.
     *
     * @param  string  $naId  NARA record ID
     * @param  string|null  $familySurname  Organize under family name
     * @return array Download result
     */
    public function downloadBestNaraObject(string $naId, ?string $familySurname = null): array
    {
        $objectsResult = $this->getNaraDigitalObjects($naId);
        if (! ($objectsResult['success'] ?? false) || empty($objectsResult['objects'])) {
            return ['success' => false, 'error' => 'No digital objects found for NARA record '.$naId];
        }

        // Priority order for format selection
        $preferredFormats = ['TIFF', 'TIF', 'JPEG', 'JPG', 'PDF', 'PNG'];
        $best = null;

        foreach ($preferredFormats as $format) {
            foreach ($objectsResult['objects'] as $obj) {
                $objFormat = strtoupper($obj['format'] ?? '');
                $objFilename = strtoupper($obj['filename'] ?? '');
                if (str_contains($objFormat, $format) || str_ends_with($objFilename, ".{$format}")) {
                    $best = $obj;
                    break 2;
                }
            }
        }

        if (! $best) {
            // Fallback to first object with a URL
            foreach ($objectsResult['objects'] as $obj) {
                if (! empty($obj['url'])) {
                    $best = $obj;
                    break;
                }
            }
        }

        if (! $best || empty($best['url'])) {
            return ['success' => false, 'error' => 'No downloadable objects found for NARA record '.$naId];
        }

        return $this->downloadNaraObject($naId, $best['url'], $best['filename'], $familySurname);
    }

    /**
     * Copy a downloaded NARA file into Nextcloud genealogy tree and register in file_registry.
     *
     * @param  string  $localPath  Path relative to storage/app
     * @param  int  $treeId  Genealogy tree ID
     * @param  string  $subfolder  Subfolder (documents, photos, etc.)
     * @param  array  $metadata  Optional NARA metadata (na_id, title, record_group, type)
     * @return array Result with Nextcloud path and asset_uuid
     */
    public function copyNaraToTree(string $localPath, int $treeId, string $subfolder = 'documents', array $metadata = []): array
    {
        $fullPath = storage_path('app/'.$localPath);
        if (! file_exists($fullPath)) {
            return ['success' => false, 'error' => 'Local file not found'];
        }

        try {
            $tree = DB::selectOne('SELECT name FROM genealogy_trees WHERE id = ?', [$treeId]);
            if (! $tree) {
                return ['success' => false, 'error' => 'Tree not found'];
            }

            $filename = basename($localPath);
            $treeRoot = $this->treeRootResolver->treeScopedRoot(
                $treeId,
                $this->treeRootResolver->mediaRoot($treeId),
                (string) $tree->name
            );
            $nextcloudPath = $treeRoot.'/'.$this->normalizeTreeSubfolder($subfolder).'/'.$filename;

            $nextcloudApi = app(\App\Services\NextcloudFileApiService::class);
            $nextcloudApi->ensureDirectoryExists(dirname($nextcloudPath));

            $content = file_get_contents($fullPath);
            $result = $nextcloudApi->uploadFile($nextcloudPath, $content);

            if (! $result) {
                return ['success' => false, 'error' => 'Upload to Nextcloud failed'];
            }

            Log::info('GenealogySourceService: Copied NARA file to genealogy tree', [
                'source' => $localPath,
                'destination' => $nextcloudPath,
                'tree_id' => $treeId,
                'na_id' => $metadata['na_id'] ?? null,
            ]);

            // Register in file_registry
            $registrationResult = null;
            try {
                $fileRegistry = app(\App\Services\FileRegistryService::class);
                $registrationResult = $fileRegistry->registerFile($nextcloudPath, [
                    'compute_hash' => true,
                    'original_source' => 'nara',
                    'title' => $metadata['title'] ?? $filename,
                    'category' => 'genealogy',
                    'tags' => array_filter([
                        'source:nara',
                        ! empty($metadata['na_id']) ? 'nara_id:'.$metadata['na_id'] : null,
                        ! empty($metadata['type']) ? 'type:'.$metadata['type'] : null,
                        ! empty($metadata['record_group']) ? 'record_group:'.$metadata['record_group'] : null,
                    ]),
                ]);
            } catch (\Exception $e) {
                Log::warning('GenealogySourceService: File registry registration failed (non-fatal)', [
                    'path' => $nextcloudPath,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'nextcloud_path' => $nextcloudPath,
                'filename' => $filename,
                'tree_id' => $treeId,
                'asset_uuid' => $registrationResult['asset_uuid'] ?? null,
                'reference' => $registrationResult ? ($registrationResult['reference'] ?? null) : null,
                'na_id' => $metadata['na_id'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('GenealogySourceService: Copy NARA to tree failed', [
                'path' => $localPath,
                'tree_id' => $treeId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizeTreeSubfolder(string $subfolder): string
    {
        $parts = array_values(array_filter(
            explode('/', str_replace('\\', '/', trim($subfolder))),
            static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..'
        ));

        $safe = array_map(static function (string $part): string {
            $part = preg_replace('/[^A-Za-z0-9._-]+/', '-', $part) ?? '';
            $part = trim($part, '-_.');

            return $part !== '' ? $part : 'files';
        }, $parts);

        return $safe !== [] ? implode('/', $safe) : 'documents';
    }

    // ========================================================================
    // CIRCUIT BREAKER & RATE LIMITING
    // ========================================================================

    /**
     * Check if circuit is open (source disabled due to failures)
     */
    protected function isCircuitOpen(string $source): bool
    {
        $state = Cache::get("genealogy_circuit_{$source}");

        if (! $state) {
            return false;
        }

        // Check if in recovery period
        if ($state['status'] === 'open') {
            if (time() - $state['opened_at'] > self::CIRCUIT_RECOVERY_TIMEOUT) {
                // Try half-open
                Cache::put("genealogy_circuit_{$source}", [
                    'status' => 'half-open',
                    'failures' => $state['failures'],
                    'opened_at' => $state['opened_at'],
                ], 3600);

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Record successful request
     */
    protected function recordSuccess(string $source): void
    {
        $state = Cache::get("genealogy_circuit_{$source}");

        if ($state && $state['status'] === 'half-open') {
            // Reset circuit
            Cache::forget("genealogy_circuit_{$source}");
        }

        // Update source stats in database (non-critical, use pgsql_rag connection)
        try {
            DB::connection('pgsql_rag')->statement('
                UPDATE research_sources
                SET success_count = COALESCE(success_count, 0) + 1,
                    last_success_at = NOW(),
                    failure_count = 0
                WHERE name LIKE ?
            ', ["%{$source}%"]);
        } catch (\Exception $e) {
            // Non-critical - stats tracking can fail silently
        }
    }

    /**
     * Record failed request
     */
    protected function recordFailure(string $source): void
    {
        $state = Cache::get("genealogy_circuit_{$source}") ?? [
            'status' => 'closed',
            'failures' => 0,
            'opened_at' => null,
        ];

        $state['failures']++;

        if ($state['failures'] >= self::CIRCUIT_FAILURE_THRESHOLD) {
            $state['status'] = 'open';
            $state['opened_at'] = time();

            Log::warning("GenealogySourceService: Circuit opened for {$source}", [
                'failures' => $state['failures'],
            ]);
        }

        Cache::put("genealogy_circuit_{$source}", $state, 3600);

        // Update source stats in database (non-critical, use pgsql_rag connection)
        try {
            DB::connection('pgsql_rag')->statement('
                UPDATE research_sources
                SET failure_count = COALESCE(failure_count, 0) + 1,
                    last_failure_at = NOW()
                WHERE name LIKE ?
            ', ["%{$source}%"]);
        } catch (\Exception $e) {
            // Non-critical - stats tracking can fail silently
        }
    }

    /**
     * Simple rate limiting per source
     */
    protected function rateLimit(string $source): void
    {
        $key = "genealogy_rate_{$source}";
        $limit = self::RATE_LIMITS[$source] ?? 60;

        $count = Cache::get($key, 0);

        if ($count >= $limit) {
            // Wait until next minute
            $waitMs = (60 - (time() % 60)) * 1000;
            usleep($waitMs * 1000);
            Cache::put($key, 1, 60);
        } else {
            Cache::put($key, $count + 1, 60);
        }
    }

    /**
     * Get status of all sources
     */
    public function getSourceStatus(): array
    {
        $sources = [
            'chronicling_america' => [
                'name' => 'Library of Congress - Chronicling America',
                'configured' => true,
                'auth_required' => false,
                'description' => 'Historic American newspapers from 1777-1963 (FREE)',
            ],
            'newspapers_com' => [
                'name' => 'Newspapers.com Library Edition',
                'configured' => (bool) config('services.newspapers.personal_automation_enabled', false) && ! empty(config('services.newspapers.barcode')),
                'auth_required' => true,
                'description' => 'Private/personal adapter for operator-owned library access',
            ],
            'europeana' => [
                'name' => 'Europeana Newspapers',
                'configured' => ! empty(config('services.europeana.api_key')),
                'auth_required' => true,
                'description' => 'European historical newspapers (FREE API)',
            ],
            'nara' => [
                'name' => 'National Archives (NARA)',
                'configured' => true,
                'auth_required' => false,
                'description' => 'U.S. federal records including military, immigration (FREE)',
            ],
            'findagrave' => [
                'name' => 'FindAGrave',
                'configured' => true,
                'auth_required' => false,
                'description' => 'Cemetery records and burial locations (FREE)',
            ],
            'billiongraves' => [
                'name' => 'BillionGraves',
                'configured' => true,
                'auth_required' => false,
                'description' => 'GPS-verified headstone photos and records (FREE)',
            ],
        ];

        foreach ($sources as $key => &$source) {
            $source['circuit_status'] = $this->isCircuitOpen($key) ? 'open' : 'closed';
            $source['available'] = $source['configured'] && ! $this->isCircuitOpen($key);
        }

        return $sources;
    }
}

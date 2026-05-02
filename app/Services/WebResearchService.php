<?php

namespace App\Services;

use App\Engine\MCPRouter;
use App\Traits\RecursionAware;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Web Research Service
 *
 * Provides fault-tolerant, AI-driven web research using Puppeteer
 * to scrape privacy-respecting search engines. Implements multi-engine
 * fallback pattern similar to YouTube transcript harvesting.
 *
 * Features:
 * - Dynamic search engine selection with health tracking
 * - AI-driven source discovery and vetting
 * - Date filtering for current information
 * - Deduplication of results
 * - Configurable depth and limits
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class WebResearchService
{
    use RecursionAware;

    private MCPRouter $mcpRouter;

    private string $dbConnection = 'pgsql_rag';

    // Default config (can be overridden by .env or per-topic)
    private int $defaultSearchDepth;

    private int $defaultMaxSources;

    private int $defaultMaxResultsPerSource;

    private int $defaultDateFilterDays;

    private int $requestDelayMs;

    public function __construct()
    {
        $this->mcpRouter = app(MCPRouter::class);
        $this->defaultSearchDepth = (int) config('research.web.search_depth', 3);
        $this->defaultMaxSources = (int) config('research.web.max_sources', 10);
        $this->defaultMaxResultsPerSource = (int) config('research.web.max_results_per_source', 5);
        $this->defaultDateFilterDays = (int) config('research.web.date_filter_days', 30);
        $this->requestDelayMs = (int) config('research.web.request_delay_ms', 1000);
    }

    /**
     * Perform PARALLEL web research across multiple sources simultaneously
     * Merges and deduplicates results from SearXNG + NewsAPI + Wikipedia
     *
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum total results to return
     * @return array Merged research results
     */
    public function parallelSearch(string $query, int $maxResults = 15): array
    {
        // RLM: Try recursive web research
        $rlm = $this->tryRecursive('web_research', 'partition_map', ['query' => $query, 'max_results' => $maxResults], function ($ctx) {
            return $this->parallelSearch($ctx['query'] ?? $ctx['data'], $ctx['max_results'] ?? 15);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $startTime = microtime(true);
        $allResults = [];
        $sources = [];
        $errors = [];

        Log::info('WebResearchService: Starting parallel search', [
            'query' => $query,
            'max_results' => $maxResults,
        ]);

        // Build tasks for concurrent execution
        // Each task must disconnect DB before exit to prevent pgsql connection pool leak
        $disconnectAfter = function (callable $fn) {
            return function () use ($fn) {
                try {
                    return $fn();
                } finally {
                    \Illuminate\Support\Facades\DB::disconnect();
                    \Illuminate\Support\Facades\DB::disconnect('pgsql_rag');
                }
            };
        };

        $tasks = [
            'searxng' => $disconnectAfter(fn () => $this->searchWithSearXNG($query, $maxResults)),
            'wikipedia' => $disconnectAfter(fn () => $this->searchWithWikipediaApi($query, 5)),
        ];

        $newsApiKey = config('services.newsapi.api_key');
        if ($newsApiKey) {
            $tasks['newsapi'] = $disconnectAfter(fn () => $this->searchWithNewsApi($query, 5, $newsApiKey));
        }

        // Execute all searches concurrently via Laravel Concurrency (forked processes)
        try {
            $taskResults = \Illuminate\Support\Facades\Concurrency::run($tasks);
        } catch (\Throwable $e) {
            // Fallback to sequential if concurrency fails (e.g., pcntl not available)
            Log::warning('WebResearchService: Concurrency unavailable, falling back to sequential', [
                'error' => $e->getMessage(),
            ]);
            $taskResults = [];
            foreach ($tasks as $key => $task) {
                try {
                    $taskResults[$key] = $task();
                } catch (\Throwable $te) {
                    $taskResults[$key] = ['success' => false, 'error' => $te->getMessage(), 'results' => []];
                }
            }
        }

        // Merge results from all engines
        foreach ($taskResults as $engine => $engineResult) {
            if (isset($engineResult['success']) && $engineResult['success'] && ! empty($engineResult['results'])) {
                foreach ($engineResult['results'] as $result) {
                    $result['source_engine'] = $engine;
                    $allResults[] = $result;
                }
                $sources[] = $engine;
            } elseif (isset($engineResult['error'])) {
                $errors[] = ['engine' => $engine, 'error' => $engineResult['error']];
            }
        }

        // Deduplicate by URL
        $seenUrls = [];
        $uniqueResults = [];
        foreach ($allResults as $result) {
            $url = $result['url'] ?? '';
            if ($url && ! isset($seenUrls[$url])) {
                $seenUrls[$url] = true;
                $uniqueResults[] = $result;
            }
        }

        // Sort by relevance (prefer results with snippets/content)
        usort($uniqueResults, function ($a, $b) {
            $aScore = strlen($a['snippet'] ?? $a['content'] ?? '') > 50 ? 1 : 0;
            $bScore = strlen($b['snippet'] ?? $b['content'] ?? '') > 50 ? 1 : 0;

            return $bScore - $aScore;
        });

        // Limit to max results
        $finalResults = array_slice($uniqueResults, 0, $maxResults);

        $duration = round((microtime(true) - $startTime) * 1000);

        Log::info('WebResearchService: Parallel search completed', [
            'query' => $query,
            'sources_used' => $sources,
            'total_raw' => count($allResults),
            'unique_results' => count($uniqueResults),
            'final_results' => count($finalResults),
            'duration_ms' => $duration,
            'errors' => count($errors),
        ]);

        return [
            'success' => ! empty($finalResults),
            'results' => $finalResults,
            'sources_used' => $sources,
            'errors' => $errors,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Perform web research for a topic with fault-tolerant multi-engine search
     *
     * @param  string  $query  Search query
     * @param  array  $config  Configuration overrides
     * @return array Research results
     */
    public function research(string $query, array $config = []): array
    {
        $searchDepth = $config['search_depth'] ?? $this->defaultSearchDepth;
        $maxSources = $config['max_sources'] ?? $this->defaultMaxSources;
        $maxResultsPerSource = $config['max_results_per_source'] ?? $this->defaultMaxResultsPerSource;
        $dateFilterDays = $config['date_filter_days'] ?? $this->defaultDateFilterDays;
        $topicId = $config['topic_id'] ?? null;
        $useParallel = $config['parallel'] ?? false;

        Log::info('WebResearchService: Starting research', [
            'query' => $query,
            'search_depth' => $searchDepth,
            'max_sources' => $maxSources,
            'parallel_mode' => $useParallel,
        ]);

        // Use parallel search if requested (faster, combines multiple sources)
        if ($useParallel) {
            return $this->parallelSearch($query, $maxSources);
        }

        // Load engine health hints (populated by research-ops agent) to skip known-dead engines
        $engineHealth = app(ResearchEngineHealthService::class)->getEngineHealthHints();
        $skippedEngines = [];

        // Try NewsAPI first (configured, good for news/research topics)
        $newsApiKey = config('services.newsapi.api_key');
        if ($newsApiKey && ! empty($engineHealth['newsapi']['skip'])) {
            $skippedEngines[] = 'newsapi';
            Log::info('WebResearchService: Skipping NewsAPI (health: '.($engineHealth['newsapi']['status'] ?? 'unknown').')');
            $newsApiKey = null; // Skip this engine
        }
        if ($newsApiKey) {
            $newsResults = $this->searchWithNewsApi($query, $maxSources, $newsApiKey);
            if ($newsResults['success'] && ! empty($newsResults['results'])) {
                // Filter results for relevance before accepting
                $relevantResults = $this->filterByRelevance($newsResults['results'], $query);
                if (! empty($relevantResults)) {
                    Log::info('WebResearchService: NewsAPI search succeeded with relevant results', [
                        'total_results' => count($newsResults['results']),
                        'relevant_results' => count($relevantResults),
                    ]);
                    $newsResults['results'] = $relevantResults;

                    return $newsResults;
                }
                Log::info('WebResearchService: NewsAPI results not relevant to query, trying next source', [
                    'query' => $query,
                    'results_checked' => count($newsResults['results']),
                ]);
            }
        }

        // GNews API removed — provider dropped (2026-03-23)

        // Try Wikipedia API (free, reliable, good for factual queries)
        if (! empty($engineHealth['wikipedia']['skip'])) {
            $skippedEngines[] = 'wikipedia';
            Log::info('WebResearchService: Skipping Wikipedia (health: '.($engineHealth['wikipedia']['status'] ?? 'unknown').')');
            $wikiResults = ['success' => false, 'results' => []];
        } else {
            Log::info('WebResearchService: Trying Wikipedia API search');
            $wikiResults = $this->searchWithWikipediaApi($query, $maxSources);
        }
        if ($wikiResults['success'] && ! empty($wikiResults['results'])) {
            // Filter results for relevance before accepting
            $relevantResults = $this->filterByRelevance($wikiResults['results'], $query);
            if (! empty($relevantResults)) {
                Log::info('WebResearchService: Wikipedia API search succeeded with relevant results', [
                    'total_results' => count($wikiResults['results']),
                    'relevant_results' => count($relevantResults),
                ]);
                $wikiResults['results'] = $relevantResults;

                return $wikiResults;
            }
            Log::info('WebResearchService: Wikipedia results not relevant to query, trying next source', [
                'query' => $query,
                'results_checked' => count($wikiResults['results']),
            ]);
        }

        // Try SearXNG (local privacy-respecting meta search)
        if (! empty($engineHealth['searxng']['skip'])) {
            $skippedEngines[] = 'searxng';
            Log::info('WebResearchService: Skipping SearXNG (health: '.($engineHealth['searxng']['status'] ?? 'unknown').')');
            $searxngResults = ['success' => false, 'results' => []];
        } else {
            Log::info('WebResearchService: Trying SearXNG search');
            $searxngResults = $this->searchWithSearXNG($query, $maxSources);
        }
        if ($searxngResults['success'] && ! empty($searxngResults['results'])) {
            // Filter results for relevance before accepting
            $relevantResults = $this->filterByRelevance($searxngResults['results'], $query);
            if (! empty($relevantResults)) {
                Log::info('WebResearchService: SearXNG search succeeded with relevant results', [
                    'total_results' => count($searxngResults['results']),
                    'relevant_results' => count($relevantResults),
                ]);
                $searxngResults['results'] = $relevantResults;

                return $searxngResults;
            }
            Log::info('WebResearchService: SearXNG results not relevant to query, trying next source', [
                'query' => $query,
                'results_checked' => count($searxngResults['results']),
            ]);
        }

        // Try curl-based direct scraping of authoritative sources
        if (! empty($engineHealth['curl_scraper']['skip'])) {
            $skippedEngines[] = 'curl_scraper';
            Log::info('WebResearchService: Skipping Curl scraper (health: '.($engineHealth['curl_scraper']['status'] ?? 'unknown').')');
            $curlResults = ['success' => false, 'results' => []];
        } else {
            Log::info('WebResearchService: Trying curl direct scraping');
            $curlResults = $this->searchWithCurlDirectScraping($query, $maxSources);
        }
        if ($curlResults['success'] && ! empty($curlResults['results'])) {
            return $curlResults;
        }

        // Last resort: Puppeteer-based search (slow, often blocked)
        if (! empty($engineHealth['puppeteer']['skip'])) {
            $skippedEngines[] = 'puppeteer';
            Log::info('WebResearchService: Skipping Puppeteer (health: '.($engineHealth['puppeteer']['status'] ?? 'unknown').')');
            if (! empty($skippedEngines)) {
                Log::warning('WebResearchService: All engines exhausted (skipped: '.implode(', ', $skippedEngines).')');
            }

            return ['success' => false, 'error' => 'All research engines unavailable', 'results' => [], 'skipped_engines' => $skippedEngines];
        }
        Log::info('WebResearchService: Curl scraping failed, trying Puppeteer engines');

        $allResults = [];
        $attempts = [];
        $engines = $this->getActiveSearchEngines();

        if (empty($engines)) {
            Log::error('WebResearchService: No active search engines available');

            return [
                'success' => false,
                'error' => 'No active search engines available',
                'results' => [],
            ];
        }

        // Try each engine with fault tolerance
        foreach ($engines as $engine) {
            if (count($allResults) >= $maxSources) {
                break;
            }

            try {
                $engineResults = $this->searchWithEngine($engine, $query, $maxResultsPerSource);

                $attempts[] = [
                    'engine' => $engine['name'],
                    'success' => $engineResults['success'],
                    'result_count' => count($engineResults['results'] ?? []),
                ];

                if ($engineResults['success'] && ! empty($engineResults['results'])) {
                    // Record success
                    $this->recordEngineSuccess($engine['id']);

                    // Filter by date if required
                    $filtered = $this->filterByDate($engineResults['results'], $dateFilterDays);

                    // Deduplicate against existing results
                    $unique = $this->deduplicateResults($filtered, $allResults);

                    $allResults = array_merge($allResults, $unique);

                    Log::info('WebResearchService: Engine succeeded', [
                        'engine' => $engine['name'],
                        'results' => count($unique),
                    ]);
                } else {
                    $this->recordEngineFailure($engine['id']);
                }

                // Rate limiting delay
                usleep($this->requestDelayMs * 1000);

            } catch (Exception $e) {
                Log::warning('WebResearchService: Engine failed', [
                    'engine' => $engine['name'],
                    'error' => $e->getMessage(),
                ]);

                $this->recordEngineFailure($engine['id']);
                $attempts[] = [
                    'engine' => $engine['name'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Store results if topic_id provided
        if ($topicId && ! empty($allResults)) {
            $this->storeResults($topicId, $allResults);
        }

        // Log message if no results found
        if (empty($allResults)) {
            Log::warning('WebResearchService: All search engines failed', [
                'query' => $query,
                'attempts' => $attempts,
            ]);
        }

        return [
            'success' => ! empty($allResults),
            'query' => $query,
            'results' => array_slice($allResults, 0, $maxSources),
            'total_found' => count($allResults),
            'attempts' => $attempts,
            'engines_tried' => count($attempts),
            'error' => empty($allResults) ? 'All search engines failed or returned no results.' : null,
        ];
    }

    /**
     * Search using a specific engine via Puppeteer
     *
     * @param  array  $engine  Engine configuration from database
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to extract
     * @return array Search results
     */
    private function searchWithEngine(array $engine, string $query, int $maxResults): array
    {
        $searchUrl = str_replace('{query}', urlencode($query), $engine['search_url_template']);

        Log::debug('WebResearchService: Searching with engine', [
            'engine' => $engine['name'],
            'url' => $searchUrl,
        ]);

        try {
            // Navigate to search page using Puppeteer MCP
            $navResult = $this->mcpRouter->callTool('puppeteer', 'puppeteer_navigate', [
                'url' => $searchUrl,
                'allowDangerous' => true,
                'launchOptions' => [
                    'headless' => true,
                    'args' => ['--no-sandbox', '--disable-setuid-sandbox'],
                ],
            ]);

            // Wait for results to load
            usleep(2000000); // 2 seconds

            // Extract search results using JavaScript
            $extractScript = $this->buildExtractionScript($engine, $maxResults);

            $evalResult = $this->mcpRouter->callTool('puppeteer', 'puppeteer_evaluate', [
                'script' => $extractScript,
            ]);

            // Parse the results
            $results = [];
            if (isset($evalResult['content']) && is_array($evalResult['content'])) {
                foreach ($evalResult['content'] as $item) {
                    if (isset($item['text'])) {
                        $decoded = json_decode($item['text'], true);
                        if (is_array($decoded)) {
                            $results = $decoded;
                        }
                    }
                }
            } elseif (is_array($evalResult) && isset($evalResult[0]['text'])) {
                $decoded = json_decode($evalResult[0]['text'], true);
                if (is_array($decoded)) {
                    $results = $decoded;
                }
            }

            // Add source metadata
            foreach ($results as &$result) {
                $result['source_engine'] = $engine['name'];
                $result['source_id'] = $engine['id'];
                $result['scraped_at'] = now()->toIso8601String();
            }

            return [
                'success' => true,
                'results' => $results,
                'engine' => $engine['name'],
            ];

        } catch (Exception $e) {
            Log::error('WebResearchService: Puppeteer search failed', [
                'engine' => $engine['name'],
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'engine' => $engine['name'],
                'results' => [],
            ];
        }
    }

    /**
     * Build JavaScript extraction script for search results
     *
     * @param  array  $engine  Engine configuration
     * @param  int  $maxResults  Maximum results to extract
     * @return string JavaScript code
     */
    private function buildExtractionScript(array $engine, int $maxResults): string
    {
        $selector = $engine['result_selector'] ?? 'a';
        $engineName = strtolower($engine['name']);

        // Engine-specific extraction logic.
        // DuckDuckGo branch removed after repeated reliability failures.
        // BannedExternalPatternsTest (tests/Feature/Quality) guards against any
        // DuckDuckGo API hostname reappearing in active code.
        $script = match ($engineName) {
            'startpage' => <<<JS
                (() => {
                    const results = [];
                    const items = document.querySelectorAll('.w-gl__result');
                    for (let i = 0; i < Math.min(items.length, {$maxResults}); i++) {
                        const item = items[i];
                        const link = item.querySelector('.w-gl__result-title');
                        const snippet = item.querySelector('.w-gl__description')?.textContent || '';
                        if (link?.href) {
                            results.push({
                                title: link.textContent.trim(),
                                url: link.href,
                                snippet: snippet.trim()
                            });
                        }
                    }
                    return JSON.stringify(results);
                })()
            JS,

            'searx' => <<<JS
                (() => {
                    const results = [];
                    const items = document.querySelectorAll('.result');
                    for (let i = 0; i < Math.min(items.length, {$maxResults}); i++) {
                        const item = items[i];
                        const link = item.querySelector('h3 a, h4 a');
                        const snippet = item.querySelector('.content, .result-content')?.textContent || '';
                        if (link?.href) {
                            results.push({
                                title: link.textContent.trim(),
                                url: link.href,
                                snippet: snippet.trim()
                            });
                        }
                    }
                    return JSON.stringify(results);
                })()
            JS,

            'mojeek' => <<<JS
                (() => {
                    const results = [];
                    const items = document.querySelectorAll('.results-standard li');
                    for (let i = 0; i < Math.min(items.length, {$maxResults}); i++) {
                        const item = items[i];
                        const link = item.querySelector('a.ob');
                        const snippet = item.querySelector('.s')?.textContent || '';
                        if (link?.href) {
                            results.push({
                                title: link.textContent.trim(),
                                url: link.href,
                                snippet: snippet.trim()
                            });
                        }
                    }
                    return JSON.stringify(results);
                })()
            JS,

            'qwant' => <<<JS
                (() => {
                    const results = [];
                    const items = document.querySelectorAll('[data-testid="webResult"]');
                    for (let i = 0; i < Math.min(items.length, {$maxResults}); i++) {
                        const item = items[i];
                        const link = item.querySelector('a');
                        const title = item.querySelector('h2, [data-testid="title"]')?.textContent || '';
                        const snippet = item.querySelector('[data-testid="description"]')?.textContent || '';
                        if (link?.href) {
                            results.push({
                                title: title.trim() || link.textContent.trim(),
                                url: link.href,
                                snippet: snippet.trim()
                            });
                        }
                    }
                    return JSON.stringify(results);
                })()
            JS,

            default => <<<JS
                (() => {
                    const results = [];
                    const links = document.querySelectorAll('{$selector}');
                    for (let i = 0; i < Math.min(links.length, {$maxResults}); i++) {
                        const link = links[i];
                        if (link.href && link.href.startsWith('http')) {
                            results.push({
                                title: link.textContent.trim(),
                                url: link.href,
                                snippet: ''
                            });
                        }
                    }
                    return JSON.stringify(results);
                })()
            JS,
        };

        return $script;
    }

    /**
     * Get active search engines ordered by trust score and health
     *
     * @return array List of engine configurations
     */
    private function getActiveSearchEngines(): array
    {
        $sql = '
            SELECT id, name, base_url, search_url_template, result_selector,
                   trust_score, failure_count, success_count, rate_limit_per_hour
            FROM research_sources
            WHERE is_active = true
              AND is_search_engine = true
            ORDER BY
                CASE WHEN failure_count > 5 THEN 1 ELSE 0 END,
                trust_score DESC,
                success_count DESC
        ';

        $results = DB::connection($this->dbConnection)->select($sql);

        return array_map(function ($row) {
            return (array) $row;
        }, $results);
    }

    /**
     * Record successful search engine usage
     *
     * @param  int  $engineId  Engine ID
     */
    private function recordEngineSuccess(int $engineId): void
    {
        try {
            $sql = '
                UPDATE research_sources
                SET success_count = success_count + 1,
                    last_success_at = CURRENT_TIMESTAMP,
                    failure_count = GREATEST(0, failure_count - 1),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ';

            DB::connection($this->dbConnection)->update($sql, [$engineId]);
        } catch (\Throwable $e) {
            // research_sources table may not exist yet
        }
    }

    /**
     * Record failed search engine usage
     *
     * @param  int  $engineId  Engine ID
     */
    private function recordEngineFailure(int $engineId): void
    {
        try {
            $sql = '
                UPDATE research_sources
                SET failure_count = failure_count + 1,
                    last_failure_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ';

            DB::connection($this->dbConnection)->update($sql, [$engineId]);

            // Disable engine if too many failures
            $checkSql = 'SELECT failure_count FROM research_sources WHERE id = ?';
            $result = DB::connection($this->dbConnection)->select($checkSql, [$engineId]);

            if (! empty($result) && $result[0]->failure_count > 10) {
                $disableSql = 'UPDATE research_sources SET is_active = false WHERE id = ?';
                DB::connection($this->dbConnection)->update($disableSql, [$engineId]);

                Log::warning('WebResearchService: Engine disabled due to repeated failures', [
                    'engine_id' => $engineId,
                ]);
            }
        } catch (\Throwable $e) {
            // research_sources table may not exist yet
        }
    }

    /**
     * Filter results by date (keep only recent content)
     *
     * @param  array  $results  Search results
     * @param  int  $maxDays  Maximum age in days
     * @return array Filtered results
     */
    private function filterByDate(array $results, int $maxDays): array
    {
        // For now, return all results - date filtering will be done by AI during vetting
        // Search engines don't always provide dates in scraped snippets
        return $results;
    }

    /**
     * Remove duplicate results based on URL
     *
     * @param  array  $newResults  New results to check
     * @param  array  $existingResults  Existing results
     * @return array Unique results
     */
    private function deduplicateResults(array $newResults, array $existingResults): array
    {
        $existingUrls = array_column($existingResults, 'url');
        $unique = [];

        foreach ($newResults as $result) {
            $url = $result['url'] ?? '';
            $normalizedUrl = $this->normalizeUrl($url);

            // Check against existing
            $isDuplicate = false;
            foreach ($existingUrls as $existingUrl) {
                if ($this->normalizeUrl($existingUrl) === $normalizedUrl) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (! $isDuplicate && ! empty($url)) {
                $unique[] = $result;
                $existingUrls[] = $url;
            }
        }

        return $unique;
    }

    /**
     * Normalize URL for comparison
     *
     * @param  string  $url  URL to normalize
     * @return string Normalized URL
     */
    private function normalizeUrl(string $url): string
    {
        $url = strtolower($url);
        $url = preg_replace('#^https?://(www\.)?#', '', $url);
        $url = rtrim($url, '/');
        $url = preg_replace('/[?#].*$/', '', $url);

        return $url;
    }

    /**
     * Store search results in database
     *
     * @param  int  $topicId  Research topic ID
     * @param  array  $results  Search results
     */
    private function storeResults(int $topicId, array $results): void
    {
        foreach ($results as $result) {
            $contentHash = md5($result['url'].($result['snippet'] ?? ''));

            // Check for existing
            $checkSql = '
                SELECT id FROM research_source_results
                WHERE research_topic_id = ? AND content_hash = ?
            ';
            $existing = DB::connection($this->dbConnection)->select($checkSql, [$topicId, $contentHash]);

            if (empty($existing)) {
                $insertSql = '
                    INSERT INTO research_source_results
                        (research_topic_id, source_id, url, title, snippet, content_hash, scraped_at)
                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ';

                DB::connection($this->dbConnection)->insert($insertSql, [
                    $topicId,
                    $result['source_id'] ?? null,
                    $result['url'],
                    $result['title'] ?? '',
                    $result['snippet'] ?? '',
                    $contentHash,
                ]);
            }
        }

        Log::info('WebResearchService: Results stored', [
            'topic_id' => $topicId,
            'count' => count($results),
        ]);
    }

    /**
     * Discover new sources for a topic using AI classification
     *
     * @param  string  $topic  Topic description
     * @return array Suggested authoritative sources
     */
    public function discoverSourcesForTopic(string $topic): array
    {
        // Use initial search to find authoritative sources
        $discoveryQuery = "authoritative sources for {$topic} research .edu .gov .org";

        $results = $this->research($discoveryQuery, [
            'search_depth' => 1,
            'max_sources' => 20,
            'max_results_per_source' => 10,
        ]);

        // Extract unique domains
        $domains = [];
        foreach ($results['results'] ?? [] as $result) {
            $url = $result['url'] ?? '';
            $parsed = parse_url($url);
            $domain = $parsed['host'] ?? '';

            if ($domain && ! isset($domains[$domain])) {
                $domains[$domain] = [
                    'domain' => $domain,
                    'url' => $parsed['scheme'].'://'.$domain,
                    'sample_url' => $url,
                    'title' => $result['title'] ?? '',
                ];
            }
        }

        return [
            'topic' => $topic,
            'discovered_domains' => array_values($domains),
            'count' => count($domains),
        ];
    }

    /**
     * Add a discovered source to the database
     *
     * @param  array  $sourceData  Source information
     * @return int|null Inserted source ID
     */
    public function addSource(array $sourceData): ?int
    {
        $sql = "
            INSERT INTO research_sources
                (name, base_url, source_type, categories, trust_score, domain_type,
                 requires_scraping, discovered_by, created_at, updated_at)
            VALUES (?, ?, ?, ?::jsonb, ?, ?, true, 'ai', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (base_url) DO NOTHING
            RETURNING id
        ";

        $result = DB::connection($this->dbConnection)->select($sql, [
            $sourceData['name'] ?? $sourceData['domain'] ?? 'Unknown',
            $sourceData['url'] ?? $sourceData['base_url'],
            $sourceData['source_type'] ?? 'website',
            json_encode($sourceData['categories'] ?? []),
            $sourceData['trust_score'] ?? 5,
            $sourceData['domain_type'] ?? 'unknown',
        ]);

        return $result[0]->id ?? null;
    }

    /**
     * Get search engine health status
     *
     * @return array Engine statuses
     */
    public function getEngineStatus(): array
    {
        $sql = '
            SELECT name, is_active, trust_score, success_count, failure_count,
                   last_success_at, last_failure_at
            FROM research_sources
            WHERE is_search_engine = true
            ORDER BY trust_score DESC
        ';

        $results = DB::connection($this->dbConnection)->select($sql);

        return array_map(function ($row) {
            return [
                'name' => $row->name,
                'active' => $row->is_active,
                'trust_score' => $row->trust_score,
                'success_count' => $row->success_count,
                'failure_count' => $row->failure_count,
                'last_success' => $row->last_success_at,
                'last_failure' => $row->last_failure_at,
                'health' => $row->failure_count > 5 ? 'degraded' : ($row->is_active ? 'healthy' : 'disabled'),
            ];
        }, $results);
    }

    /**
     * Search using NewsAPI (news articles)
     * Already configured in .env - good for research topics
     *
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to return
     * @param  string  $apiKey  NewsAPI key
     * @return array Search results
     */
    private function searchWithNewsApi(string $query, int $maxResults, string $apiKey): array
    {
        try {
            Log::info('WebResearchService: Searching with NewsAPI', ['query' => $query]);

            $response = \Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(15)
                ->get('https://newsapi.org/v2/everything', [
                    'q' => $query,
                    'pageSize' => min($maxResults, 100),
                    'sortBy' => 'relevancy',
                    'language' => 'en',
                    'apiKey' => $apiKey,
                ]);

            if (! $response->successful()) {
                Log::warning('WebResearchService: NewsAPI request failed', [
                    'status' => $response->status(),
                ]);

                return ['success' => false, 'results' => [], 'error' => 'NewsAPI request failed'];
            }

            $data = $response->json();
            $articles = $data['articles'] ?? [];

            if (empty($articles)) {
                return ['success' => false, 'results' => [], 'error' => 'No results from NewsAPI'];
            }

            $results = [];
            foreach ($articles as $article) {
                $results[] = [
                    'url' => $article['url'] ?? '',
                    'title' => $article['title'] ?? '',
                    'snippet' => $article['description'] ?? '',
                    'source_engine' => 'NewsAPI',
                    'source_name' => $article['source']['name'] ?? '',
                    'published_at' => $article['publishedAt'] ?? null,
                    'scraped_at' => now()->toIso8601String(),
                ];
            }

            return [
                'success' => true,
                'query' => $query,
                'results' => $results,
                'total_found' => $data['totalResults'] ?? count($results),
                'engine' => 'NewsAPI',
            ];

        } catch (Exception $e) {
            Log::error('WebResearchService: NewsAPI search failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'results' => [], 'error' => $e->getMessage()];
        }
    }

    // searchWithGNewsApi() removed — GNews provider dropped (2026-03-23)

    /**
     * Search using Wikipedia API (free, reliable, works well for factual queries)
     *
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to return
     * @return array Search results
     */
    private function searchWithWikipediaApi(string $query, int $maxResults = 10): array
    {
        try {
            Log::info('WebResearchService: Searching with Wikipedia API', ['query' => $query]);

            $response = \Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(10)
                ->withHeaders([
                    'User-Agent' => 'PLOS/1.0 (Personal Automation Project; contact@example.com)',
                ])
                ->get('https://en.wikipedia.org/w/api.php', [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $query,
                    'format' => 'json',
                    'srlimit' => min($maxResults, 50),
                    'srprop' => 'snippet|titlesnippet|size',
                ]);

            if (! $response->successful()) {
                Log::warning('WebResearchService: Wikipedia API request failed', [
                    'status' => $response->status(),
                ]);

                return ['success' => false, 'results' => [], 'error' => 'Wikipedia API request failed'];
            }

            $data = $response->json();
            $searchResults = $data['query']['search'] ?? [];

            if (empty($searchResults)) {
                return ['success' => false, 'results' => [], 'error' => 'No results from Wikipedia'];
            }

            $results = [];
            foreach ($searchResults as $result) {
                $title = $result['title'] ?? '';
                // Clean snippet (remove HTML search highlight spans)
                $snippet = strip_tags(html_entity_decode($result['snippet'] ?? ''));

                $results[] = [
                    'url' => 'https://en.wikipedia.org/wiki/'.urlencode(str_replace(' ', '_', $title)),
                    'title' => $title,
                    'snippet' => $snippet,
                    'source_engine' => 'Wikipedia',
                    'source_name' => 'Wikipedia',
                    'scraped_at' => now()->toIso8601String(),
                ];
            }

            return [
                'success' => true,
                'query' => $query,
                'results' => $results,
                'total_found' => $data['query']['searchinfo']['totalhits'] ?? count($results),
                'engine' => 'Wikipedia',
            ];

        } catch (Exception $e) {
            Log::error('WebResearchService: Wikipedia API search failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'results' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Search using local SearXNG instance (privacy-respecting meta search)
     *
     * SearXNG aggregates results from multiple search engines without tracking.
     * Falls back gracefully if SearXNG is unavailable (circuit breaker pattern).
     *
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to return
     * @return array Search results
     */
    private function searchWithSearXNG(string $query, int $maxResults = 10): array
    {
        try {
            $searxngService = app(SearXNGService::class);

            // Check if service is available (circuit breaker)
            if (! $searxngService->isAvailable()) {
                Log::info('WebResearchService: SearXNG circuit breaker open, skipping');

                return ['success' => false, 'results' => [], 'error' => 'SearXNG circuit breaker open'];
            }

            Log::info('WebResearchService: Searching with SearXNG', ['query' => $query]);

            $results = $searxngService->search($query, $maxResults, 'en', '');

            if (! $results['success'] || empty($results['results'])) {
                return [
                    'success' => false,
                    'results' => [],
                    'error' => $results['error'] ?? 'No results from SearXNG',
                ];
            }

            // Format results to match expected structure
            $formattedResults = [];
            foreach ($results['results'] as $item) {
                $rawTitle = $item['title'] ?? '';
                $rawSnippet = $item['snippet'] ?? '';
                $rawUrl = $item['url'] ?? '';
                $formattedResults[] = [
                    'url' => is_array($rawUrl) ? ($rawUrl[0] ?? '') : $rawUrl,
                    'title' => is_array($rawTitle) ? implode(' ', $rawTitle) : $rawTitle,
                    'snippet' => is_array($rawSnippet) ? implode(' ', $rawSnippet) : $rawSnippet,
                    'source_engine' => 'SearXNG',
                    'source_name' => $item['source_name'] ?? parse_url(is_array($rawUrl) ? ($rawUrl[0] ?? '') : $rawUrl, PHP_URL_HOST),
                    'scraped_at' => $item['scraped_at'] ?? now()->toIso8601String(),
                ];
            }

            return [
                'success' => true,
                'query' => $query,
                'results' => $formattedResults,
                'total_found' => $results['total_found'] ?? count($formattedResults),
                'engine' => 'SearXNG',
            ];

        } catch (Exception $e) {
            Log::error('WebResearchService: SearXNG search failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'results' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Curl-based direct scraping of authoritative sources
     * Dynamically selects sources based on query keywords
     * Fallback when search engines are blocked
     *
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to return
     * @return array Search results
     */
    private function searchWithCurlDirectScraping(string $query, int $maxResults = 10): array
    {
        try {
            Log::info('WebResearchService: Trying curl direct scraping', ['query' => $query]);

            // Determine authoritative sources based on query keywords
            $sources = $this->determineAuthoritativeSources($query);
            $results = [];

            foreach ($sources as $source) {
                if (count($results) >= $maxResults) {
                    break;
                }

                if ($this->isManualOnlyUrl($source['url'] ?? null)) {
                    Log::info('WebResearchService: Skipping manual-only source', [
                        'source' => $source['name'] ?? 'unknown',
                        'url' => $source['url'] ?? null,
                    ]);
                    continue;
                }

                try {
                    $response = \Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(10)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        ])
                        ->get($source['url']);

                    if ($response->successful()) {
                        $html = $response->body();
                        $extracted = $this->extractContentFromHtml($html, $source['url'], $source['name']);

                        if (! empty($extracted)) {
                            $results[] = $extracted;
                            Log::info('WebResearchService: Curl scraped source', [
                                'source' => $source['name'],
                                'url' => $source['url'],
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    Log::debug('WebResearchService: Source failed', [
                        'source' => $source['name'],
                        'error' => $e->getMessage(),
                    ]);
                    // Continue to next source
                }
            }

            if (empty($results)) {
                return ['success' => false, 'results' => [], 'error' => 'No content from direct sources'];
            }

            return [
                'success' => true,
                'query' => $query,
                'results' => $results,
                'total_found' => count($results),
                'engine' => 'Curl Direct Scraping',
            ];

        } catch (Exception $e) {
            Log::error('WebResearchService: Curl scraping failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'results' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Determine authoritative sources based on query keywords
     *
     * Dynamically pulls sources from discovered_sources table based on detected category,
     * with fallback to hardcoded sources for categories not yet in the database.
     *
     * @param  string  $query  Search query
     * @return array List of sources with URLs
     */
    private function determineAuthoritativeSources(string $query): array
    {
        $encodedQuery = urlencode($query);
        $sources = [];

        // Detect category from query
        $category = $this->detectCategoryFromQuery($query);

        // Try to get dynamic sources from database first (cached for 1 hour)
        $cacheKey = "authoritative_sources:{$category}";
        $dynamicSources = Cache::remember($cacheKey, 3600, function () use ($category) {
            return $this->getDynamicSourcesForCategory($category);
        });

        // Build source URLs from dynamic sources
        foreach ($dynamicSources as $source) {
            $url = $this->buildSearchUrl($source, $encodedQuery);
            if ($url) {
                $sources[] = [
                    'name' => $source['display_name'],
                    'url' => $url,
                    'trust_score' => $source['trust_score'] ?? 0.5,
                ];
            }
        }

        // If we got dynamic sources, sort by trust score and return
        if (! empty($sources)) {
            usort($sources, fn ($a, $b) => ($b['trust_score'] ?? 0) <=> ($a['trust_score'] ?? 0));
            Log::info('WebResearchService: Using dynamic sources', [
                'category' => $category,
                'source_count' => count($sources),
            ]);

            return array_slice($sources, 0, config('research.max_sources_stored', 10));
        }

        // Fallback to hardcoded sources for categories not in database
        return $this->getHardcodedSourcesForCategory($category, $encodedQuery);
    }

    /**
     * Detect category from query keywords
     * Order matters: more specific patterns should come first
     */
    private function detectCategoryFromQuery(string $query): string
    {
        $patterns = [
            // Legal/legislative (check before government for more specific matching)
            'legal' => '/\bACA\b|affordable care act|congress|legislative|bill\s+\d|HR\s*\d|senate|house of representatives|premium tax credit|PTC|subsidy|subsidies|legislation|reconciliation|discharge petition|CBO|committee hearing|markup/i',
            // Health (expanded for insurance and medical products)
            'health' => '/health|insurance|coverage|medicare|medicaid|vitamin|supplement|nutrition|diet|medical|medicine|drug|symptom|disease|treatment|cure|remedy|cancer|screening|diagnostic|clinical|FDA|pharmaceutical|patient|doctor|hospital|therapy/i',
            'food' => '/recipe|cook|cooking|ingredient|olive oil|garlic|infuse|herb|spice|kitchen|food/i',
            'technology' => '/laravel|php|python|javascript|react|vue|node|npm|programming|software|code|api|database/i',
            'academic' => '/study|research|scientific|evidence|clinical|trial|paper|journal|thesis/i',
            'genealogy' => '/ancestor|family|genealogy|heritage|lineage|birth|death|marriage|census|grave/i',
            'government' => '/law|regulation|policy|federal|state|county|city|municipal|legal|court/i',
            'finance' => '/stock|invest|bank|finance|money|tax|budget|economic|market|trading/i',
            'news' => '/news|current|breaking|headline|today|recent|update/i',
        ];

        foreach ($patterns as $category => $pattern) {
            if (preg_match($pattern, $query)) {
                return $category;
            }
        }

        return 'general';
    }

    /**
     * Get dynamic sources from discovered_sources table for a category
     */
    private function getDynamicSourcesForCategory(string $category): array
    {
        try {
            // Map query categories to database domain_category values
            $categoryMappings = [
                'health' => ['health', 'medical', 'government'],
                'legal' => ['government', 'legal', 'news'],
                'academic' => ['academic', 'government'],
                'genealogy' => ['archive', 'genealogy', 'community'],
                'government' => ['government'],
                'finance' => ['finance', 'government'],
                'technology' => ['technology', 'academic'],
                'food' => ['food', 'general'],
                'news' => ['news', 'general'],
                'general' => ['general', 'academic', 'government'],
            ];

            $dbCategories = $categoryMappings[$category] ?? ['general'];
            $placeholders = implode(',', array_fill(0, count($dbCategories), '?'));

            $sql = "SELECT domain, full_url, display_name, domain_category, trust_score,
                           api_endpoint, scrape_selectors
                    FROM discovered_sources
                    WHERE is_active = true
                      AND is_blacklisted = false
                      AND domain_category IN ({$placeholders})
                      AND trust_score >= 0.5
                    ORDER BY trust_score DESC, success_count DESC
                    LIMIT 15";

            $results = DB::connection($this->dbConnection)->select($sql, $dbCategories);

            return array_map(fn ($r) => [
                'domain' => $r->domain,
                'full_url' => $r->full_url,
                'display_name' => $r->display_name,
                'category' => $r->domain_category,
                'trust_score' => (float) $r->trust_score,
                'api_endpoint' => $r->api_endpoint,
                'scrape_selectors' => $r->scrape_selectors ? json_decode($r->scrape_selectors, true) : null,
            ], $results);

        } catch (Exception $e) {
            Log::warning('WebResearchService: Failed to get dynamic sources', [
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build search URL for a source
     */
    private function buildSearchUrl(array $source, string $encodedQuery): ?string
    {
        if ($this->isManualOnlyHost($source['domain'] ?? null)) {
            return null;
        }

        // If source has API endpoint with query placeholder, use it
        if (! empty($source['api_endpoint'])) {
            $endpoint = $source['api_endpoint'];
            if (strpos($endpoint, '{query}') !== false) {
                $url = str_replace('{query}', $encodedQuery, $endpoint);

                return $this->isManualOnlyUrl($url) ? null : $url;
            }
            if (strpos($endpoint, '?') !== false) {
                $url = $endpoint.'&q='.$encodedQuery;

                return $this->isManualOnlyUrl($url) ? null : $url;
            }

            $url = $endpoint.'?q='.$encodedQuery;

            return $this->isManualOnlyUrl($url) ? null : $url;
        }

        // Build search URL based on known domain patterns
        $domain = $source['domain'];
        $searchPatterns = [
            'pubmed.ncbi.nlm.nih.gov' => "https://pubmed.ncbi.nlm.nih.gov/?term={$encodedQuery}",
            'scholar.google.com' => "https://scholar.google.com/scholar?q={$encodedQuery}",
            'arxiv.org' => "https://arxiv.org/search/?query={$encodedQuery}&searchtype=all",
            'familysearch.org' => "https://www.familysearch.org/search/record/results?q.anyText={$encodedQuery}",
            'findagrave.com' => "https://www.findagrave.com/memorial/search?firstname=&lastname={$encodedQuery}",
            'billiongraves.com' => "https://billiongraves.com/search?firstName=&lastName={$encodedQuery}",
            'wikitree.com' => "https://www.wikitree.com/wiki/Special:SearchPerson?Name={$encodedQuery}",
            'archive.org' => "https://archive.org/search?query={$encodedQuery}",
            'loc.gov' => "https://www.loc.gov/search/?q={$encodedQuery}",
            'census.gov' => "https://www.census.gov/search-results.html?q={$encodedQuery}",
            'nih.gov' => "https://search.nih.gov/search?utf8=%E2%9C%93&query={$encodedQuery}",
            'webmd.com' => "https://www.webmd.com/search/search_results/default.aspx?query={$encodedQuery}",
            'mayoclinic.org' => "https://www.mayoclinic.org/search/search-results?q={$encodedQuery}",
            'healthline.com' => "https://www.healthline.com/search?q1={$encodedQuery}",
            'wikipedia.org' => "https://en.wikipedia.org/w/index.php?search={$encodedQuery}",
        ];

        foreach ($searchPatterns as $pattern => $url) {
            if (strpos($domain, $pattern) !== false || strpos($pattern, $domain) !== false) {
                return $this->isManualOnlyUrl($url) ? null : $url;
            }
        }

        // Fallback: try generic search URL pattern
        if (! empty($source['full_url'])) {
            $baseUrl = rtrim($source['full_url'], '/');

            $url = "{$baseUrl}/search?q={$encodedQuery}";

            return $this->isManualOnlyUrl($url) ? null : $url;
        }

        return null;
    }

    private function isManualOnlyUrl(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        return $this->isManualOnlyHost(parse_url($url, PHP_URL_HOST));
    }

    private function isManualOnlyHost(?string $host): bool
    {
        $normalizedHost = strtolower(trim((string) $host));
        $normalizedHost = trim($normalizedHost, " \t\n\r\0\x0B\"'()[]{}<>.,;:");
        $normalizedHost = ltrim($normalizedHost, '*.');
        $normalizedHost = preg_replace('/^www\./', '', $normalizedHost) ?? $normalizedHost;

        if ($normalizedHost === '') {
            return false;
        }

        foreach ((array) config('scraping.manual_only_domains', []) as $domain) {
            $domain = ltrim(strtolower(trim((string) $domain)), '*.');
            if ($domain === '') {
                continue;
            }

            if ($normalizedHost === $domain || str_ends_with($normalizedHost, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback hardcoded sources for categories not in database
     */
    private function getHardcodedSourcesForCategory(string $category, string $encodedQuery): array
    {
        $sources = [];

        switch ($category) {
            case 'health':
                $sources[] = ['name' => 'WebMD', 'url' => "https://www.webmd.com/search/search_results/default.aspx?query={$encodedQuery}"];
                $sources[] = ['name' => 'Healthline', 'url' => "https://www.healthline.com/search?q1={$encodedQuery}"];
                $sources[] = ['name' => 'Mayo Clinic', 'url' => "https://www.mayoclinic.org/search/search-results?q={$encodedQuery}"];
                $sources[] = ['name' => 'Medical News Today', 'url' => "https://www.medicalnewstoday.com/search?q={$encodedQuery}"];
                break;

            case 'food':
                $sources[] = ['name' => 'Serious Eats', 'url' => "https://www.seriouseats.com/search?q={$encodedQuery}"];
                $sources[] = ['name' => 'Food Network', 'url' => "https://www.foodnetwork.com/search/{$encodedQuery}-"];
                $sources[] = ['name' => 'Bon Appetit', 'url' => "https://www.bonappetit.com/search?q={$encodedQuery}"];
                break;

            case 'technology':
                $sources[] = ['name' => 'MDN Web Docs', 'url' => "https://developer.mozilla.org/en-US/search?q={$encodedQuery}"];
                $sources[] = ['name' => 'Stack Overflow', 'url' => "https://stackoverflow.com/search?q={$encodedQuery}"];
                break;

            case 'academic':
                $sources[] = ['name' => 'PubMed', 'url' => "https://pubmed.ncbi.nlm.nih.gov/?term={$encodedQuery}"];
                $sources[] = ['name' => 'Google Scholar', 'url' => "https://scholar.google.com/scholar?q={$encodedQuery}"];
                break;

            case 'genealogy':
                $sources[] = ['name' => 'NARA Catalog', 'url' => "https://catalog.archives.gov/search?q={$encodedQuery}"];
                $sources[] = ['name' => 'Library of Congress', 'url' => "https://www.loc.gov/search/?q={$encodedQuery}"];
                $sources[] = ['name' => 'Internet Archive', 'url' => "https://archive.org/search?query={$encodedQuery}"];
                $sources[] = ['name' => 'Find a Grave', 'url' => "https://www.findagrave.com/memorial/search?firstname=&lastname={$encodedQuery}"];
                $sources[] = ['name' => 'WikiTree', 'url' => "https://www.wikitree.com/wiki/Special:SearchPerson?Name={$encodedQuery}"];
                break;

            case 'legal':
                $sources[] = ['name' => 'Congress.gov', 'url' => "https://www.congress.gov/search?q=%7B%22source%22%3A%22all%22%2C%22search%22%3A%22{$encodedQuery}%22%7D"];
                $sources[] = ['name' => 'CBO', 'url' => "https://www.cbo.gov/search/google/{$encodedQuery}"];
                $sources[] = ['name' => 'Healthcare.gov', 'url' => "https://www.healthcare.gov/search/?query={$encodedQuery}"];
                $sources[] = ['name' => 'KFF Health News', 'url' => "https://kffhealthnews.org/?s={$encodedQuery}"];
                $sources[] = ['name' => 'The Hill', 'url' => "https://thehill.com/search/?q={$encodedQuery}"];
                break;

            default:
                // General fallback
                $sources[] = ['name' => 'Wikipedia', 'url' => "https://en.wikipedia.org/w/index.php?search={$encodedQuery}"];
                $sources[] = ['name' => 'Britannica', 'url' => "https://www.britannica.com/search?query={$encodedQuery}"];
        }

        // Always add Wikipedia as reliable fallback
        if (! in_array('Wikipedia', array_column($sources, 'name'))) {
            $sources[] = ['name' => 'Wikipedia', 'url' => "https://en.wikipedia.org/w/index.php?search={$encodedQuery}"];
        }

        return $sources;
    }

    /**
     * Extract content from HTML page
     *
     * @param  string  $html  HTML content
     * @param  string  $url  Source URL
     * @param  string  $sourceName  Source name
     * @return array|null Extracted content or null
     */
    private function extractContentFromHtml(string $html, string $url, string $sourceName): ?array
    {
        // Extract title
        preg_match('/<title>([^<]+)<\/title>/i', $html, $titleMatch);
        $title = isset($titleMatch[1]) ? html_entity_decode(trim($titleMatch[1])) : $sourceName;

        // Extract meta description
        preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']|<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $descMatch);
        $description = isset($descMatch[1]) ? html_entity_decode(trim($descMatch[1])) : (isset($descMatch[2]) ? html_entity_decode(trim($descMatch[2])) : '');

        // Extract first paragraph if no description
        if (empty($description)) {
            preg_match('/<p[^>]*>([^<]{50,500})<\/p>/i', $html, $pMatch);
            $description = isset($pMatch[1]) ? html_entity_decode(trim(strip_tags($pMatch[1]))) : '';
        }

        if (empty($title) && empty($description)) {
            return null;
        }

        return [
            'url' => $url,
            'title' => $title,
            'snippet' => substr($description, 0, 300),
            'source_engine' => 'Curl/'.$sourceName,
            'scraped_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Filter search results by relevance to the query
     *
     * Uses keyword matching and scoring to ensure results actually relate
     * to the search query. This prevents NewsAPI/GNews from returning
     * tangentially related but irrelevant results.
     *
     * @param  array  $results  Search results to filter
     * @param  string  $query  Original search query
     * @param  float  $minRelevanceScore  Minimum score (0-1) to keep result
     * @return array Filtered results that are relevant to the query
     */
    private function filterByRelevance(array $results, string $query, float $minRelevanceScore = 0.3): array
    {
        // Extract significant keywords from query (3+ chars, excluding stop words)
        $stopWords = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had',
            'her', 'was', 'one', 'our', 'out', 'has', 'have', 'been', 'were', 'they',
            'this', 'that', 'with', 'what', 'when', 'where', 'who', 'how', 'which',
            'from', 'into', 'will', 'would', 'could', 'should', 'about', 'some'];

        $queryLower = strtolower($query);
        $words = preg_split('/\s+/', $queryLower);
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) >= 3 && ! in_array($word, $stopWords);
        });

        if (empty($keywords)) {
            // If no significant keywords, return all results
            return $results;
        }

        $filtered = [];
        foreach ($results as $result) {
            $rawTitle = $result['title'] ?? '';
            $rawSnippet = $result['snippet'] ?? '';
            $title = strtolower(is_array($rawTitle) ? implode(' ', $rawTitle) : $rawTitle);
            $snippet = strtolower(is_array($rawSnippet) ? implode(' ', $rawSnippet) : $rawSnippet);
            $combined = $title.' '.$snippet;

            // Score based on keyword presence
            $matchCount = 0;
            $totalKeywords = count($keywords);

            foreach ($keywords as $keyword) {
                if (strpos($combined, $keyword) !== false) {
                    $matchCount++;
                    // Bonus for title matches
                    if (strpos($title, $keyword) !== false) {
                        $matchCount += 0.5;
                    }
                }
            }

            // Calculate relevance score (0-1 scale, can exceed 1 with title bonuses)
            $score = min(1.0, $matchCount / max(1, $totalKeywords));

            if ($score >= $minRelevanceScore) {
                $result['relevance_score'] = $score;
                $filtered[] = $result;
            } else {
                Log::debug('WebResearchService: Filtered out irrelevant result', [
                    'title' => $result['title'] ?? 'untitled',
                    'score' => $score,
                    'threshold' => $minRelevanceScore,
                    'keywords' => $keywords,
                ]);
            }
        }

        // Sort by relevance score (highest first)
        usort($filtered, function ($a, $b) {
            return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
        });

        return $filtered;
    }
}

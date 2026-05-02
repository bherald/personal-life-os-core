<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * SearXNG Service
 *
 * Privacy-respecting meta search engine integration.
 * Uses local SearXNG instance with JSON API format.
 *
 * Features:
 * - Circuit breaker pattern for fault tolerance
 * - Multi-category search (general, images, news)
 * - Configurable engines and result limits
 * - Health tracking with auto-recovery
 *
 * Setup: pip install searxng in /opt/searxng/venv
 * Port: 8888 (configurable via SEARXNG_URL)
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class SearXNGService
{
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;

    // Circuit breaker settings
    private int $failureThreshold;
    private int $recoveryTimeout;

    private CircuitBreaker $circuitBreaker;
    private const CIRCUIT_NAME = 'searxng';

    public function __construct(?CircuitBreaker $circuitBreaker = null)
    {
        $this->baseUrl = rtrim(config('services.searxng.url', 'http://127.0.0.1:8888'), '/');
        $this->timeout = (int) config('services.searxng.timeout', 30);
        $this->connectTimeout = (int) config('services.searxng.connect_timeout', 5);
        $this->failureThreshold = (int) config('services.searxng.failure_threshold', 5);
        $this->recoveryTimeout = (int) config('services.searxng.recovery_timeout', 300); // 5 minutes
        $this->circuitBreaker = $circuitBreaker ?? app(CircuitBreaker::class);
    }

    /**
     * Search for web content
     *
     * @param string $query Search query
     * @param int $maxResults Maximum results to return
     * @param string $language Language code (default: en)
     * @param string $timeRange Time range filter: day, week, month, year, or empty
     * @return array Search results
     */
    public function search(string $query, int $maxResults = 10, string $language = 'en', string $timeRange = ''): array
    {
        return $this->executeSearch($query, [
            'categories' => 'general',
            'pageno' => 1,
            'language' => $language,
            'time_range' => $timeRange,
        ], $maxResults);
    }

    /**
     * Search for images
     *
     * @param string $query Search query
     * @param int $maxResults Maximum results to return
     * @param string $language Language code
     * @return array Image search results
     */
    public function searchImages(string $query, int $maxResults = 20, string $language = 'en'): array
    {
        return $this->executeSearch($query, [
            'categories' => 'images',
            'pageno' => 1,
            'language' => $language,
        ], $maxResults);
    }

    /**
     * Search for news articles
     *
     * @param string $query Search query
     * @param int $maxResults Maximum results to return
     * @param string $language Language code
     * @param string $timeRange Time range filter
     * @return array News search results
     */
    public function searchNews(string $query, int $maxResults = 10, string $language = 'en', string $timeRange = 'week'): array
    {
        return $this->executeSearch($query, [
            'categories' => 'news',
            'pageno' => 1,
            'language' => $language,
            'time_range' => $timeRange,
        ], $maxResults);
    }

    /**
     * Execute search request against SearXNG API
     *
     * @param string $query Search query
     * @param array $params Additional parameters
     * @param int $maxResults Maximum results
     * @return array Search results with metadata
     */
    private function executeSearch(string $query, array $params, int $maxResults): array
    {
        if ($manualOnlyDomain = $this->queryTargetsManualOnlyDomain($query)) {
            Log::warning('SearXNGService: Manual-only domain search blocked', [
                'query' => $query,
                'domain' => $manualOnlyDomain,
            ]);

            return [
                'success' => false,
                'query' => $query,
                'error' => "Manual-only domain search is disabled for {$manualOnlyDomain}; use operator browser review instead.",
                'results' => [],
                'source' => 'SearXNG',
                'manual_only' => true,
                'manual_required' => true,
                'policy' => 'manual_only_search_domain',
                'domain' => $manualOnlyDomain,
            ];
        }

        // Check circuit breaker (300s recovery timeout)
        if (!$this->circuitBreaker->isAvailable(self::CIRCUIT_NAME, $this->recoveryTimeout)) {
            Log::warning('SearXNGService: Circuit breaker open, skipping search');
            return [
                'success' => false,
                'error' => 'Service temporarily unavailable (circuit breaker open)',
                'results' => [],
                'source' => 'SearXNG',
                'circuit_open' => true,
            ];
        }

        try {
            Log::info('SearXNGService: Executing search', [
                'query' => $query,
                'category' => $params['categories'] ?? 'general',
            ]);

            // Build request parameters
            $requestParams = array_merge([
                'q' => $query,
                'format' => 'json',
            ], $params);

            // Remove empty params
            $requestParams = array_filter($requestParams, fn($v) => !empty($v));

            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PLOS/1.0 (Private Research Automation)',
                ])
                ->get("{$this->baseUrl}/search", $requestParams);

            if (!$response->successful()) {
                $this->recordFailure('HTTP ' . $response->status());
                return [
                    'success' => false,
                    'error' => 'SearXNG request failed: HTTP ' . $response->status(),
                    'results' => [],
                    'source' => 'SearXNG',
                ];
            }

            $data = $response->json();

            // Check for valid response
            if (!isset($data['results']) || !is_array($data['results'])) {
                $this->recordFailure('Invalid response format');
                return [
                    'success' => false,
                    'error' => 'Invalid response from SearXNG',
                    'results' => [],
                    'source' => 'SearXNG',
                ];
            }

            // Record success
            $this->recordSuccess();

            // Format results
            $results = [];
            $category = $params['categories'] ?? 'general';

            foreach (array_slice($data['results'], 0, $maxResults) as $item) {
                $result = $this->formatResult($item, $category);
                if ($result) {
                    $results[] = $result;
                }
            }

            Log::info('SearXNGService: Search completed', [
                'query' => $query,
                'results_count' => count($results),
                'engines_used' => count($data['infoboxes'] ?? []),
            ]);

            return [
                'success' => true,
                'query' => $query,
                'results' => $results,
                'total_found' => count($data['results']),
                'returned' => count($results),
                'source' => 'SearXNG',
                'suggestions' => $data['suggestions'] ?? [],
                'corrections' => $data['corrections'] ?? [],
                'scraped_at' => now()->toIso8601String(),
            ];

        } catch (Exception $e) {
            $this->recordFailure($e->getMessage());

            Log::error('SearXNGService: Search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'SearXNG search failed: ' . $e->getMessage(),
                'results' => [],
                'source' => 'SearXNG',
            ];
        }
    }

    /**
     * Format a single search result
     *
     * @param array $item Raw result from SearXNG
     * @param string $category Search category
     * @return array|null Formatted result or null if invalid
     */
    private function formatResult(array $item, string $category): ?array
    {
        $url = $item['url'] ?? '';
        if (empty($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($this->manualOnlyDomainForHost($host)) {
            Log::info('SearXNGService: Dropping manual-only domain result', [
                'url' => $url,
                'domain' => $this->manualOnlyDomainForHost($host),
            ]);

            return null;
        }

        $base = [
            'url' => $url,
            'title' => $item['title'] ?? 'Untitled',
            'source_engine' => 'SearXNG',
            'engines' => $item['engines'] ?? [],
            'score' => $item['score'] ?? null,
            'scraped_at' => now()->toIso8601String(),
        ];

        if ($category === 'images') {
            return array_merge($base, [
                'thumbnail' => $item['thumbnail_src'] ?? $item['img_src'] ?? null,
                'image_url' => $item['img_src'] ?? null,
                'image_format' => $item['img_format'] ?? null,
                'source_name' => $item['source'] ?? parse_url($url, PHP_URL_HOST),
            ]);
        }

        if ($category === 'news') {
            return array_merge($base, [
                'snippet' => $item['content'] ?? '',
                'published_at' => $item['publishedDate'] ?? null,
                'source_name' => $item['source'] ?? parse_url($url, PHP_URL_HOST),
            ]);
        }

        // General/web results
        return array_merge($base, [
            'snippet' => $item['content'] ?? '',
            'source_name' => parse_url($url, PHP_URL_HOST),
        ]);
    }

    /**
     * Return the manual-only domain targeted by a site: query, if any.
     */
    private function queryTargetsManualOnlyDomain(string $query): ?string
    {
        preg_match_all('/(?:^|\s)site:\s*["\']?([*.a-z0-9-]+)/i', $query, $matches);

        foreach ($matches[1] ?? [] as $host) {
            $domain = $this->manualOnlyDomainForHost($host);
            if ($domain) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Return the configured manual-only root domain for a host.
     */
    private function manualOnlyDomainForHost(?string $host): ?string
    {
        $normalizedHost = strtolower(trim((string) $host));
        $normalizedHost = trim($normalizedHost, " \t\n\r\0\x0B\"'()[]{}<>.,;:");
        $normalizedHost = ltrim($normalizedHost, '*.');
        $normalizedHost = preg_replace('/^www\./', '', $normalizedHost) ?? $normalizedHost;

        if ($normalizedHost === '') {
            return null;
        }

        foreach ($this->manualOnlyDomains() as $domain) {
            if ($normalizedHost === $domain || str_ends_with($normalizedHost, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Domains that may be opened manually but must not be searched or scraped by agents.
     *
     * @return array<int, string>
     */
    private function manualOnlyDomains(): array
    {
        $domains = config('scraping.manual_only_domains', []);
        if (!is_array($domains)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($domain) => ltrim(strtolower(trim((string) $domain)), '*.'),
            $domains
        ))));
    }

    /**
     * Get service status and health information
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        $circuitState = $this->circuitBreaker->getState(self::CIRCUIT_NAME);
        $circuitOpen = $circuitState === 'open';

        // Test connectivity if circuit is closed
        $connectivity = 'unknown';
        $version = null;

        if (!$circuitOpen) {
            try {
                $response = Http::timeout(5)
                    ->connectTimeout(2)
                    ->get("{$this->baseUrl}/config");

                if ($response->successful()) {
                    $connectivity = 'healthy';
                    $config = $response->json();
                    $version = $config['version'] ?? null;
                } else {
                    $connectivity = 'degraded';
                }
            } catch (Exception $e) {
                $connectivity = 'unreachable';
            }
        } else {
            $connectivity = 'circuit_open';
        }

        return [
            'service' => 'SearXNG',
            'url' => $this->baseUrl,
            'status' => $connectivity,
            'circuit_breaker' => [
                'state' => $circuitState,
                'failure_threshold' => $this->failureThreshold,
                'recovery_timeout' => $this->recoveryTimeout,
            ],
            'version' => $version,
            'config' => [
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Record a successful request via shared CircuitBreaker
     */
    private function recordSuccess(): void
    {
        $this->circuitBreaker->recordSuccess(self::CIRCUIT_NAME);
    }

    /**
     * Record a failed request via shared CircuitBreaker
     *
     * @param string $reason Failure reason for logging
     */
    private function recordFailure(string $reason): void
    {
        Log::warning('SearXNGService: Request failed', [
            'reason' => $reason,
        ]);

        $this->circuitBreaker->recordFailure(self::CIRCUIT_NAME, $this->failureThreshold);
    }

    /**
     * Manually reset the circuit breaker (for admin use)
     *
     * @return bool Always true
     */
    public function forceResetCircuit(): bool
    {
        $this->circuitBreaker->reset(self::CIRCUIT_NAME);
        return true;
    }

    /**
     * Check if service is available
     *
     * @return bool True if service can accept requests
     */
    public function isAvailable(): bool
    {
        return $this->circuitBreaker->isAvailable(self::CIRCUIT_NAME, $this->recoveryTimeout);
    }
}

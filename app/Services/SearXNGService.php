<?php

namespace App\Services;

use App\Support\SensitiveTextTelemetry;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    private const QUERY_TELEMETRY_SCOPE = 'searxng-connector-query-v1';

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
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to return
     * @param  string  $language  Language code (default: en)
     * @param  string  $timeRange  Time range filter: day, week, month, year, or empty
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
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to return
     * @param  string  $language  Language code
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
     * @param  string  $query  Search query
     * @param  int  $maxResults  Maximum results to return
     * @param  string  $language  Language code
     * @param  string  $timeRange  Time range filter
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
     * @param  string  $query  Search query
     * @param  array  $params  Additional parameters
     * @param  int  $maxResults  Maximum results
     * @return array Search results with metadata
     */
    private function executeSearch(string $query, array $params, int $maxResults): array
    {
        $providerSpecificDomain = $this->queryTargetsProviderSpecificDomain($query);
        if ($providerSpecificDomain && $this->shouldEnforceProviderSpecificDomains()) {
            Log::warning('SearXNGService: Provider-specific domain search blocked by strict policy', [
                ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
                'domain' => $providerSpecificDomain,
            ]);

            return [
                'success' => false,
                'query' => $query,
                'error' => "Provider-specific domain search is disabled for {$providerSpecificDomain} by strict policy.",
                'results' => [],
                'source' => 'SearXNG',
                'provider_specific' => true,
                'operator_review_required' => true,
                'policy' => 'provider_specific_search_domain',
                'domain' => $providerSpecificDomain,
            ];
        }

        if ($providerSpecificDomain) {
            Log::info('SearXNGService: Provider-specific domain search allowed by private/advisory policy', [
                ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
                'domain' => $providerSpecificDomain,
            ]);
        }

        // Check circuit breaker (300s recovery timeout)
        if (! $this->circuitBreaker->isAvailable(self::CIRCUIT_NAME, $this->recoveryTimeout)) {
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
                ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
                'category' => $params['categories'] ?? 'general',
            ]);

            // Build request parameters
            $requestParams = array_merge([
                'q' => $query,
                'format' => 'json',
            ], $params);

            // Remove empty params
            $requestParams = array_filter($requestParams, fn ($v) => ! empty($v));

            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PLOS/1.0 (Private Research Automation)',
                ])
                ->get("{$this->baseUrl}/search", $requestParams);

            if (! $response->successful()) {
                $this->recordFailure('http_error');

                return [
                    'success' => false,
                    'error' => 'SearXNG request failed: HTTP '.$response->status(),
                    'results' => [],
                    'source' => 'SearXNG',
                ];
            }

            $data = $response->json();

            // Check for valid response
            if (! isset($data['results']) || ! is_array($data['results'])) {
                $this->recordFailure('invalid_response_format');

                return [
                    'success' => false,
                    'error' => 'Invalid response from SearXNG',
                    'results' => [],
                    'source' => 'SearXNG',
                ];
            }

            // Format results
            $results = [];
            $category = $params['categories'] ?? 'general';

            foreach (array_slice($data['results'], 0, $maxResults) as $item) {
                $result = $this->formatResult($item, $category);
                if ($result) {
                    $results[] = $result;
                }
            }

            $engineSummary = $this->engineSummary($results);
            $engineHealth = $this->engineHealth($data['unresponsive_engines'] ?? []);

            if ($results === [] && $engineHealth['unresponsive_engine_count'] > 0) {
                $this->recordFailure('unresponsive_engines_no_results');

                Log::warning('SearXNGService: No results and upstream engines were unresponsive', [
                    ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
                    'unresponsive_engine_count' => $engineHealth['unresponsive_engine_count'],
                    'failure_event_count' => $engineHealth['failure_event_count'],
                    'failure_reason_codes' => $engineHealth['reason_codes'],
                ]);

                return [
                    'success' => false,
                    'query' => $query,
                    'error' => 'SearXNG engines unavailable',
                    'failure_reason' => 'unresponsive_engines_no_results',
                    'results' => [],
                    'total_found' => count($data['results']),
                    'returned' => 0,
                    'source' => 'SearXNG',
                    'suggestions' => $data['suggestions'] ?? [],
                    'corrections' => $data['corrections'] ?? [],
                    'engine_summary' => $engineSummary,
                    'engine_health' => $engineHealth,
                    'degraded' => true,
                    'scraped_at' => now()->toIso8601String(),
                    'provider_specific_domain' => $providerSpecificDomain,
                ];
            }

            $this->recordSuccess();

            Log::info('SearXNGService: Search completed', [
                ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
                'results_count' => count($results),
                'unique_engines_count' => $engineSummary['unique_engine_count'],
                'multi_engine_results' => $engineSummary['multi_engine_result_count'],
                'unresponsive_engine_count' => $engineHealth['unresponsive_engine_count'],
                'failure_event_count' => $engineHealth['failure_event_count'],
                'degraded' => $engineHealth['degraded'],
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
                'engine_summary' => $engineSummary,
                'engine_health' => $engineHealth,
                'degraded' => $engineHealth['degraded'],
                'scraped_at' => now()->toIso8601String(),
                'provider_specific_domain' => $providerSpecificDomain,
            ];

        } catch (Exception $e) {
            $this->recordFailure('request_exception');

            Log::error('SearXNGService: Search failed', [
                ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
                'exception_class' => $e::class,
            ]);

            return [
                'success' => false,
                'error' => 'SearXNG search failed',
                'results' => [],
                'source' => 'SearXNG',
            ];
        }
    }

    /**
     * Format a single search result
     *
     * @param  array  $item  Raw result from SearXNG
     * @param  string  $category  Search category
     * @return array|null Formatted result or null if invalid
     */
    private function formatResult(array $item, string $category): ?array
    {
        $url = $item['url'] ?? '';
        if (empty($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $providerSpecificDomain = $this->providerSpecificDomainForHost($host);
        if ($providerSpecificDomain && $this->shouldEnforceProviderSpecificDomains()) {
            Log::info('SearXNGService: Dropping provider-specific domain result under strict policy', [
                'result_host' => $host,
                'domain' => $providerSpecificDomain,
            ]);

            return null;
        }

        $engines = $this->normalizeEngines($item['engines'] ?? ($item['engine'] ?? []));
        $base = [
            'url' => $url,
            'title' => $item['title'] ?? 'Untitled',
            'source_engine' => 'SearXNG',
            'engines' => $engines,
            'engine_count' => count($engines),
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
            'provider_specific_domain' => $providerSpecificDomain,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeEngines(mixed $engines): array
    {
        if (is_string($engines)) {
            $engines = [$engines];
        }

        if (! is_array($engines)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($engine): string => trim((string) $engine),
            $engines
        ))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{unique_engines: array<int, string>, unique_engine_count: int, multi_engine_result_count: int}
     */
    private function engineSummary(array $results): array
    {
        $uniqueEngines = [];
        $multiEngineResultCount = 0;

        foreach ($results as $result) {
            $engines = $this->normalizeEngines($result['engines'] ?? []);
            if (count($engines) > 1) {
                $multiEngineResultCount++;
            }

            foreach ($engines as $engine) {
                $uniqueEngines[$engine] = true;
            }
        }

        $engineNames = array_keys($uniqueEngines);
        sort($engineNames, SORT_STRING);

        return [
            'unique_engines' => $engineNames,
            'unique_engine_count' => count($engineNames),
            'multi_engine_result_count' => $multiEngineResultCount,
        ];
    }

    /**
     * @return array{degraded: bool, unresponsive_engine_count: int, failure_event_count: int, unresponsive_engines: array<int, array{engine: string, reason_codes: array<int, string>}>, reason_codes: array<int, string>}
     */
    private function engineHealth(mixed $unresponsiveEngines): array
    {
        if (! is_array($unresponsiveEngines)) {
            $unresponsiveEngines = [];
        }

        $byEngine = [];
        $failureEventCount = 0;
        foreach ($unresponsiveEngines as $item) {
            if (! is_array($item)) {
                continue;
            }

            $engine = trim((string) ($item['engine'] ?? $item[0] ?? ''));
            if ($engine === '') {
                continue;
            }

            $engine = substr(preg_replace('/[^a-z0-9_. -]/i', '', $engine) ?? '', 0, 80);
            if ($engine === '') {
                continue;
            }

            $reasonCode = $this->engineFailureReasonCode((string) ($item['reason'] ?? $item[1] ?? ''));
            $byEngine[$engine][$reasonCode] = true;
            $failureEventCount++;
        }

        ksort($byEngine, SORT_STRING);
        $engines = [];
        $reasonCodes = [];
        foreach ($byEngine as $engine => $engineReasonSet) {
            $engineReasonCodes = array_keys($engineReasonSet);
            sort($engineReasonCodes, SORT_STRING);
            $engines[] = [
                'engine' => $engine,
                'reason_codes' => $engineReasonCodes,
            ];
            $reasonCodes = array_merge($reasonCodes, $engineReasonCodes);
        }

        $reasonCodes = array_values(array_unique($reasonCodes));
        sort($reasonCodes, SORT_STRING);

        return [
            'degraded' => $engines !== [],
            'unresponsive_engine_count' => count($engines),
            'failure_event_count' => $failureEventCount,
            'unresponsive_engines' => $engines,
            'reason_codes' => $reasonCodes,
        ];
    }

    private function engineFailureReasonCode(string $reason): string
    {
        $reason = strtolower($reason);

        return match (true) {
            str_contains($reason, 'timeout') => 'timeout',
            str_contains($reason, 'captcha'), str_contains($reason, 'bot'), str_contains($reason, 'cloudflare') => 'access_challenge',
            str_contains($reason, 'connect'), str_contains($reason, 'network'), str_contains($reason, 'dns') => 'network_error',
            str_contains($reason, 'http') => 'http_error',
            default => 'engine_error',
        };
    }

    /**
     * Return the provider-specific domain targeted by a site: query, if any.
     */
    private function queryTargetsProviderSpecificDomain(string $query): ?string
    {
        preg_match_all('/(?:^|\s)site:\s*["\']?([*.a-z0-9-]+)/i', $query, $matches);

        foreach ($matches[1] ?? [] as $host) {
            $domain = $this->providerSpecificDomainForHost($host);
            if ($domain) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Return the configured provider-specific root domain for a host.
     */
    private function providerSpecificDomainForHost(?string $host): ?string
    {
        $normalizedHost = strtolower(trim((string) $host));
        $normalizedHost = trim($normalizedHost, " \t\n\r\0\x0B\"'()[]{}<>.,;:");
        $normalizedHost = ltrim($normalizedHost, '*.');
        $normalizedHost = preg_replace('/^www\./', '', $normalizedHost) ?? $normalizedHost;

        if ($normalizedHost === '') {
            return null;
        }

        foreach ($this->providerSpecificDomains() as $domain) {
            if ($normalizedHost === $domain || str_ends_with($normalizedHost, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Domains that need source-specific/API/private-capture posture.
     *
     * @return array<int, string>
     */
    private function providerSpecificDomains(): array
    {
        $domains = config('scraping.provider_specific_domains', config('scraping.manual_only_domains', []));
        if (! is_array($domains)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($domain) => ltrim(strtolower(trim((string) $domain)), '*.'),
            $domains
        ))));
    }

    private function shouldEnforceProviderSpecificDomains(): bool
    {
        return (bool) config('scraping.enforce_provider_specific_domains', config('scraping.enforce_manual_only_domains', false));
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

        if (! $circuitOpen) {
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
     * @param  string  $reason  Failure reason for logging
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

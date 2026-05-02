<?php

namespace App\Services\Genealogy\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for genealogy providers
 *
 * Provides common functionality for:
 * - HTTP requests with rate limiting
 * - Caching
 * - Token storage/retrieval (RAW SQL)
 * - Error handling
 */
abstract class AbstractGenealogyProvider implements GenealogyProviderInterface
{
    protected ?string $lastError = null;
    protected array $config = [];
    protected int $requestCount = 0;
    protected ?int $rateLimitRemaining = null;
    protected ?string $rateLimitReset = null;

    /**
     * Default capabilities - override in subclasses
     */
    protected array $defaultCapabilities = [
        'search_persons' => false,
        'search_records' => false,
        'get_record' => false,
        'get_person' => false,
        'get_family' => false,
        'get_collections' => false,
        'hints' => false,
        'attach_records' => false,
        'dna_matches' => false,
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Get default configuration - override in subclasses
     */
    protected function getDefaultConfig(): array
    {
        return [
            'timeout' => 30,
            'retry_attempts' => 3,
            'cache_ttl' => 3600,
            'rate_limit_per_hour' => 1000,
        ];
    }

    /**
     * Make HTTP GET request with error handling and caching
     */
    protected function httpGet(string $url, array $params = [], array $headers = [], bool $useCache = true): ?array
    {
        $cacheKey = $this->getCacheKey('get', $url, $params);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = Http::connectTimeout(5)->timeout($this->config['timeout'])
                ->withHeaders($this->buildHeaders($headers))
                ->retry($this->config['retry_attempts'], 1000)
                ->get($url, $params);

            $this->trackRateLimit($response);

            if ($response->successful()) {
                $data = $response->json();
                if ($useCache) {
                    Cache::put($cacheKey, $data, $this->config['cache_ttl']);
                }
                return $data;
            }

            $this->lastError = "HTTP {$response->status()}: " . $response->body();
            Log::warning("{$this->getProviderId()}: Request failed", [
                'url' => $url,
                'status' => $response->status(),
                'error' => $this->lastError,
            ]);
            return null;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("{$this->getProviderId()}: Request exception", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Make HTTP POST request
     */
    protected function httpPost(string $url, array $data = [], array $headers = []): ?array
    {
        try {
            $response = Http::connectTimeout(5)->timeout($this->config['timeout'])
                ->withHeaders($this->buildHeaders($headers))
                ->retry($this->config['retry_attempts'], 1000)
                ->post($url, $data);

            $this->trackRateLimit($response);

            if ($response->successful()) {
                return $response->json();
            }

            $this->lastError = "HTTP {$response->status()}: " . $response->body();
            return null;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Build request headers - override to add auth headers
     */
    protected function buildHeaders(array $additional = []): array
    {
        return array_merge([
            'Accept' => 'application/json',
            'User-Agent' => 'GenealogyResearchTool/1.0',
        ], $additional);
    }

    /**
     * Track rate limit from response headers
     */
    protected function trackRateLimit($response): void
    {
        $this->requestCount++;

        if ($response->hasHeader('X-RateLimit-Remaining')) {
            $this->rateLimitRemaining = (int) $response->header('X-RateLimit-Remaining');
        }
        if ($response->hasHeader('X-RateLimit-Reset')) {
            $this->rateLimitReset = $response->header('X-RateLimit-Reset');
        }
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(string $method, string $url, array $params = []): string
    {
        return "genealogy_provider:{$this->getProviderId()}:{$method}:" . md5($url . json_encode($params));
    }

    /**
     * Store OAuth tokens in database (RAW SQL)
     */
    protected function storeTokens(int $treeId, array $tokens): bool
    {
        $existing = DB::selectOne("
            SELECT id FROM genealogy_provider_tokens
            WHERE tree_id = ? AND provider_id = ?
        ", [$treeId, $this->getProviderId()]);

        if ($existing) {
            return DB::update("
                UPDATE genealogy_provider_tokens
                SET access_token = ?, refresh_token = ?, token_expires_at = ?,
                    token_data = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                $tokens['access_token'] ?? null,
                $tokens['refresh_token'] ?? null,
                $tokens['expires_at'] ?? null,
                isset($tokens['data']) ? json_encode($tokens['data']) : null,
                $existing->id,
            ]) > 0;
        }

        DB::insert("
            INSERT INTO genealogy_provider_tokens
            (tree_id, provider_id, access_token, refresh_token, token_expires_at, token_data, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ", [
            $treeId,
            $this->getProviderId(),
            $tokens['access_token'] ?? null,
            $tokens['refresh_token'] ?? null,
            $tokens['expires_at'] ?? null,
            isset($tokens['data']) ? json_encode($tokens['data']) : null,
        ]);

        return true;
    }

    /**
     * Retrieve OAuth tokens from database (RAW SQL)
     */
    protected function getStoredTokens(int $treeId): ?object
    {
        return DB::selectOne("
            SELECT * FROM genealogy_provider_tokens
            WHERE tree_id = ? AND provider_id = ?
        ", [$treeId, $this->getProviderId()]);
    }

    /**
     * Delete stored tokens (RAW SQL)
     */
    protected function deleteTokens(int $treeId): bool
    {
        return DB::delete("
            DELETE FROM genealogy_provider_tokens
            WHERE tree_id = ? AND provider_id = ?
        ", [$treeId, $this->getProviderId()]) > 0;
    }

    /**
     * Log provider activity (RAW SQL)
     */
    protected function logActivity(string $action, array $data = [], bool $success = true): void
    {
        DB::insert("
            INSERT INTO genealogy_provider_logs
            (provider_id, action, request_data, success, error_message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ", [
            $this->getProviderId(),
            $action,
            json_encode($data),
            $success,
            $success ? null : $this->lastError,
        ]);
    }

    // Default implementations

    public function getCapabilities(): array
    {
        return $this->defaultCapabilities;
    }

    public function getRateLimitStatus(): array
    {
        return [
            'requests_made' => $this->requestCount,
            'remaining' => $this->rateLimitRemaining,
            'reset_at' => $this->rateLimitReset,
            'limit_per_hour' => $this->config['rate_limit_per_hour'],
        ];
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    // Default stubs for optional methods

    public function getAuthorizationUrl(?string $state = null): ?string
    {
        return null;
    }

    public function handleOAuthCallback(string $code, ?string $state = null): bool
    {
        return false;
    }

    public function refreshAccessToken(): bool
    {
        return false;
    }

    public function getCollections(): array
    {
        return [];
    }

    public function getPerson(string $personId): ?array
    {
        return null;
    }

    public function getPersonFamily(string $personId): ?array
    {
        return null;
    }
}

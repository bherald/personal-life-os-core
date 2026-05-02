<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * SearXNG MCP Service
 *
 * MCP wrapper for SearXNG privacy-respecting meta search.
 * Exposes 4 tools for web, image, and news search plus status.
 *
 * Tools provided (4):
 * - searxng_search: General web search
 * - searxng_images: Image search
 * - searxng_news: News article search
 * - searxng_status: Service health and status
 *
 * Uses local SearXNG instance on port 8888.
 * Circuit breaker pattern protects against cascading failures.
 */
class SearXNGMCPService
{
    private SearXNGService $searxng;

    public function __construct()
    {
        $this->searxng = app(SearXNGService::class);
    }

    /**
     * Search the web using SearXNG
     *
     * @param string $query Search query
     * @param int $max_results Maximum results to return (default: 10)
     * @param string $language Language code (default: en)
     * @param string $time_range Time filter: day, week, month, year, or empty
     * @return array Search results
     */
    public function searxng_search(
        string $query,
        int $max_results = 10,
        string $language = 'en',
        string $time_range = ''
    ): array {
        Log::info('SearXNGMCPService: searxng_search called', [
            'query' => $query,
            'max_results' => $max_results,
        ]);

        $results = $this->searxng->search($query, $max_results, $language, $time_range);

        return [
            'tool' => 'searxng_search',
            'query' => $query,
            'success' => $results['success'],
            'results' => $results['results'] ?? [],
            'total_found' => $results['total_found'] ?? 0,
            'returned' => $results['returned'] ?? count($results['results'] ?? []),
            'suggestions' => $results['suggestions'] ?? [],
            'error' => $results['error'] ?? null,
            'manual_only' => $results['manual_only'] ?? false,
            'manual_required' => $results['manual_required'] ?? false,
            'policy' => $results['policy'] ?? null,
            'domain' => $results['domain'] ?? null,
            'source' => 'SearXNG',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Search for images using SearXNG
     *
     * @param string $query Search query
     * @param int $max_results Maximum results to return (default: 20)
     * @param string $language Language code (default: en)
     * @return array Image search results
     */
    public function searxng_images(
        string $query,
        int $max_results = 20,
        string $language = 'en'
    ): array {
        Log::info('SearXNGMCPService: searxng_images called', [
            'query' => $query,
            'max_results' => $max_results,
        ]);

        $results = $this->searxng->searchImages($query, $max_results, $language);

        return [
            'tool' => 'searxng_images',
            'query' => $query,
            'success' => $results['success'],
            'results' => $results['results'] ?? [],
            'total_found' => $results['total_found'] ?? 0,
            'returned' => $results['returned'] ?? count($results['results'] ?? []),
            'error' => $results['error'] ?? null,
            'manual_only' => $results['manual_only'] ?? false,
            'manual_required' => $results['manual_required'] ?? false,
            'policy' => $results['policy'] ?? null,
            'domain' => $results['domain'] ?? null,
            'source' => 'SearXNG',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Search for news articles using SearXNG
     *
     * @param string $query Search query
     * @param int $max_results Maximum results to return (default: 10)
     * @param string $language Language code (default: en)
     * @param string $time_range Time filter: day, week, month, year (default: week)
     * @return array News search results
     */
    public function searxng_news(
        string $query,
        int $max_results = 10,
        string $language = 'en',
        string $time_range = 'week'
    ): array {
        Log::info('SearXNGMCPService: searxng_news called', [
            'query' => $query,
            'max_results' => $max_results,
            'time_range' => $time_range,
        ]);

        $results = $this->searxng->searchNews($query, $max_results, $language, $time_range);

        return [
            'tool' => 'searxng_news',
            'query' => $query,
            'success' => $results['success'],
            'results' => $results['results'] ?? [],
            'total_found' => $results['total_found'] ?? 0,
            'returned' => $results['returned'] ?? count($results['results'] ?? []),
            'error' => $results['error'] ?? null,
            'manual_only' => $results['manual_only'] ?? false,
            'manual_required' => $results['manual_required'] ?? false,
            'policy' => $results['policy'] ?? null,
            'domain' => $results['domain'] ?? null,
            'source' => 'SearXNG',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get SearXNG service status and health
     *
     * @return array Service status information
     */
    public function searxng_status(): array
    {
        Log::info('SearXNGMCPService: searxng_status called');

        $status = $this->searxng->getStatus();

        return [
            'tool' => 'searxng_status',
            'service' => $status['service'],
            'url' => $status['url'],
            'status' => $status['status'],
            'available' => $this->searxng->isAvailable(),
            'circuit_breaker' => $status['circuit_breaker'],
            'version' => $status['version'],
            'config' => $status['config'],
            'timestamp' => $status['timestamp'],
        ];
    }

    /**
     * Reset the circuit breaker (admin function)
     *
     * @return array Reset confirmation
     */
    public function searxng_reset_circuit(): array
    {
        Log::info('SearXNGMCPService: searxng_reset_circuit called');

        $result = $this->searxng->forceResetCircuit();

        return [
            'tool' => 'searxng_reset_circuit',
            'success' => $result,
            'message' => $result ? 'Circuit breaker reset successfully' : 'Failed to reset circuit breaker',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}

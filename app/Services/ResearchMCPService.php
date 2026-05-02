<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Research MCP Service
 *
 * Internal MCP server exposing multi-source research tools.
 * Provides balanced news research using ground.news + multiple search APIs.
 *
 * Tools provided (5):
 * - research_query: Research a topic across all sources
 * - research_trending: Research a trending ground.news story
 * - get_trending_topics: Get current trending stories from ground.news
 * - get_biased_stories: Get stories with significant left/right bias
 * - research_status: Get research service status and available sources
 */
class ResearchMCPService
{
    private ResearchService $researchService;

    private GroundNewsScraperService $groundNews;

    public function __construct()
    {
        $this->researchService = app(ResearchService::class);
        $this->groundNews = app(GroundNewsScraperService::class);
    }

    /**
     * Research a query across all available sources
     *
     * @param  string  $query  Search query
     * @param  array  $sources  Optional: sources to use (newsapi, gnews)
     * @param  int  $limit  Max results per source
     * @param  bool  $parallel  Execute searches in parallel
     * @param  bool  $use_ai  Use AI to analyze results
     * @return array Research results with aggregated data
     */
    public function research_query(
        string $query,
        array $sources = [],
        int $limit = 10,
        bool $parallel = true,
        bool $use_ai = true
    ): array {
        Log::info('ResearchMCPService: research_query called', [
            'query' => $query,
            'sources' => $sources,
            'limit' => $limit,
        ]);

        // Use default sources if none specified
        if (empty($sources)) {
            $sources = $this->getAvailableSources();
        }

        $options = [
            'sources' => $sources,
            'limit' => $limit,
            'parallel' => $parallel,
            'use_ai' => $use_ai,
        ];

        return $this->researchService->research($query, $options);
    }

    /**
     * Research a trending topic from ground.news
     *
     * @param  int  $index  Index of trending story (0-9, default: 0 = top story)
     * @param  array  $sources  Optional: sources to use
     * @param  int  $limit  Max results per source
     * @param  bool  $use_ai  Use AI to analyze results
     * @return array Research results with ground.news context
     */
    public function research_trending(
        int $index = 0,
        array $sources = [],
        int $limit = 10,
        bool $use_ai = true
    ): array {
        Log::info('ResearchMCPService: research_trending called', [
            'index' => $index,
        ]);

        if (empty($sources)) {
            $sources = $this->getAvailableSources();
        }

        $options = [
            'sources' => $sources,
            'limit' => $limit,
            'parallel' => true,
            'use_ai' => $use_ai,
        ];

        return $this->researchService->researchTrending($index, $options);
    }

    /**
     * Get current trending topics from ground.news
     *
     * @param  int  $limit  Number of trending stories to return
     * @return array Trending stories with bias indicators
     */
    public function get_trending_topics(int $limit = 10): array
    {
        Log::info('ResearchMCPService: get_trending_topics called', [
            'limit' => $limit,
        ]);

        $stories = $this->groundNews->getTrendingStories($limit);

        return [
            'status' => 'success',
            'stories' => $stories,
            'count' => count($stories),
            'source' => 'ground.news',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get stories with significant bias (left/right imbalance)
     *
     * @param  int  $min_balance_score  Minimum L-R percentage difference (default: 30)
     * @param  int  $limit  Number of stories to return
     * @return array Biased stories from ground.news
     */
    public function get_biased_stories(
        int $min_balance_score = 30,
        int $limit = 10
    ): array {
        Log::info('ResearchMCPService: get_biased_stories called', [
            'min_balance_score' => $min_balance_score,
            'limit' => $limit,
        ]);

        $stories = $this->groundNews->getBiasedStories($min_balance_score, $limit);

        return [
            'status' => 'success',
            'stories' => $stories,
            'count' => count($stories),
            'min_balance_score' => $min_balance_score,
            'note' => 'Stories with significant left/right coverage imbalance or marked as blindspots',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get research service status and available sources
     *
     * @return array Service status and configuration
     */
    public function research_status(): array
    {
        Log::info('ResearchMCPService: research_status called');

        $sources = [];

        // Check NewsAPI
        $sources['newsapi'] = [
            'name' => 'NewsAPI.org',
            'enabled' => (bool) config('services.newsapi.api_key'),
            'free_tier' => '100 requests/day',
            'requires_api_key' => true,
            'configured' => (bool) config('services.newsapi.api_key'),
            'signup_url' => 'https://newsapi.org/register',
        ];

        // GNews removed — provider dropped (2026-03-23)

        // Check Ground News (Puppeteer scraping)
        $sources['ground_news'] = [
            'name' => 'Ground News (scraping)',
            'enabled' => true,
            'free_tier' => 'Unlimited (scraping)',
            'requires_api_key' => false,
            'configured' => true,
            'note' => 'Used for bias indicators and trending topics',
        ];

        $enabledCount = count(array_filter($sources, fn ($s) => $s['enabled']));

        return [
            'status' => 'operational',
            'total_sources' => count($sources),
            'enabled_sources' => $enabledCount,
            'sources' => $sources,
            'features' => [
                'parallel_search' => true,
                'ai_analysis' => true,
                'bias_detection' => true,
                'trending_topics' => true,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get list of available/enabled sources
     *
     * @return array List of source identifiers
     */
    private function getAvailableSources(): array
    {
        // DuckDuckGo was removed after repeated reliability failures.
        // GNews was removed after the provider was dropped 2026-03-23.
        // BannedExternalPatternsRule guards against reintroduction.
        $sources = [];

        if (config('services.newsapi.api_key')) {
            $sources[] = 'newsapi';
        }

        return $sources;
    }
}

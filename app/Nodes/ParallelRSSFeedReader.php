<?php

namespace App\Nodes;

use App\Services\DataSanitizer;
use App\Services\NewsArticleService;
use App\Services\ParallelRSSProcessor;
use App\Services\SystemConfigService;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Parallel RSS Feed Reader Node
 *
 * Fetches multiple RSS/Atom feeds in parallel for dramatically faster performance.
 * Instead of fetching feeds sequentially, all feeds are fetched concurrently.
 *
 * Performance Example:
 * - 5 feeds × 2s each = 10s sequential
 * - 5 feeds in parallel = 2s total (5x speedup)
 *
 * Configuration:
 * - feeds: (required) Array of feed configurations:
 *   [
 *     ['url' => 'https://...', 'limit' => 10, 'timeout' => 20],
 *     ['url' => 'https://...', 'limit' => 5],
 *     ...
 *   ]
 * - max_concurrent: (optional) Maximum parallel fetches (default: 10)
 * - max_age_hours: (optional) Filter articles older than X hours (default: 24)
 * - include_content: (optional) Include full content if available (default: false)
 * - max_total_articles: (optional) Cap accumulated articles passed downstream (default: 50)
 * - max_formatted_chars: (optional) Cap formatted text size passed downstream (default: 50000)
 */
class ParallelRSSFeedReader extends BaseNode
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'rss.parallel_reader_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'max_concurrent' => 10,
        'max_age_hours' => 24,
        'include_content_default' => false,
        'max_total_articles' => 50,
        'max_formatted_chars' => 50000,
        'persist_articles_default' => true,
        'content_preview_max_chars' => 300,
        'all_feeds_failed_log_level' => 'error',
        'partial_empty_log_level' => 'warning',
    ];

    private ParallelRSSProcessor $parallelProcessor;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function execute(array $input): array
    {
        try {
            $this->parallelProcessor = new ParallelRSSProcessor;

            // Extract configuration
            $feedConfigs = $this->getConfigValue('feeds');
            $maxConcurrent = $this->configuredPositiveInt('max_concurrent') ?? $this->runtimePolicyInt('max_concurrent');
            $maxAgeHours = $this->configuredInt('max_age_hours') ?? $this->runtimePolicyNonNegativeInt('max_age_hours');
            $includeContent = $this->configuredBool('include_content') ?? $this->runtimePolicyBool('include_content_default');
            $maxTotalArticles = $this->configuredPositiveInt('max_total_articles') ?? $this->runtimePolicyInt('max_total_articles');
            $maxFormattedChars = $this->configuredPositiveInt('max_formatted_chars') ?? $this->runtimePolicyInt('max_formatted_chars');
            $persistArticles = $this->configuredBool('persist_articles') ?? $this->runtimePolicyBool('persist_articles_default');
            $contentPreviewMaxChars = $this->configuredPositiveInt('content_preview_max_chars')
                ?? $this->runtimePolicyInt('content_preview_max_chars');
            $runtimeMeta = [
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
                'max_concurrent' => $maxConcurrent,
                'max_age_hours' => $maxAgeHours,
                'include_content' => $includeContent,
                'max_total_articles' => $maxTotalArticles,
                'max_formatted_chars' => $maxFormattedChars,
                'persist_articles' => $persistArticles,
                'content_preview_max_chars' => $contentPreviewMaxChars,
            ];

            if (empty($feedConfigs) || ! is_array($feedConfigs)) {
                return $this->standardOutput(
                    null,
                    $runtimeMeta,
                    'feeds configuration is required and must be an array'
                );
            }

            // Validate feed configs
            $validFeeds = [];
            foreach ($feedConfigs as $config) {
                if (isset($config['url']) && ! empty($config['url'])) {
                    $validFeeds[] = $config;
                } else {
                    Log::warning('ParallelRSSFeedReader: Skipping invalid feed config', [
                        'config' => $config,
                    ]);
                }
            }

            if (empty($validFeeds)) {
                return $this->standardOutput(
                    null,
                    $runtimeMeta,
                    'No valid feed URLs found in configuration'
                );
            }

            $startTime = microtime(true);

            Log::info('ParallelRSSFeedReader: Starting parallel fetch', [
                'feed_count' => count($validFeeds),
                'max_concurrent' => $maxConcurrent,
            ]);

            // Fetch all feeds in parallel
            $results = $this->parallelProcessor->fetchFeeds($validFeeds, $maxConcurrent);

            // Combine results
            $combined = $this->parallelProcessor->combineResults($results);

            $articles = $combined['articles'];
            $metadata = $combined['metadata'];

            // Filter old articles
            if ($maxAgeHours > 0) {
                $beforeFilter = count($articles);
                $articles = $this->filterByAge($articles, $maxAgeHours);
                $afterFilter = count($articles);

                if ($beforeFilter !== $afterFilter) {
                    Log::info('ParallelRSSFeedReader: Filtered old articles', [
                        'before' => $beforeFilter,
                        'after' => $afterFilter,
                        'removed' => $beforeFilter - $afterFilter,
                    ]);
                }
            }

            // Sanitize articles
            $articles = array_map(function ($article) {
                return DataSanitizer::sanitizeArticle($article);
            }, $articles);

            // Sort by publication date (newest first)
            usort($articles, function ($a, $b) {
                $timeA = strtotime($a['pubDate'] ?? '');
                $timeB = strtotime($b['pubDate'] ?? '');

                return $timeB <=> $timeA; // Descending order
            });

            $duration = round((microtime(true) - $startTime) * 1000);

            // Extract previous data for chaining
            $previousData = $this->extractPreviousData($input);
            $previousArticles = $this->extractPreviousArticles($input);

            // Combine with previous articles
            $allArticles = $this->mergeArticleCollections($previousArticles, $articles, $maxTotalArticles);

            // Format for output
            $formatted = $this->formatArticles($articles, $metadata, $contentPreviewMaxChars);

            // Prepend previous data if exists
            if (! empty($previousData)) {
                $formatted = $previousData."\n\n".$formatted;
            }

            $formatted = $this->trimFormattedText($formatted, $maxFormattedChars);

            Log::info('ParallelRSSFeedReader: Completed parallel fetch', [
                'duration_ms' => $duration,
                'total_articles' => count($articles),
                'successful_feeds' => $metadata['successful_feeds'],
                'failed_feeds' => $metadata['failed_feeds'],
            ]);

            $persistStats = null;
            if ($persistArticles && ! empty($articles)) {
                try {
                    $newsService = app(NewsArticleService::class);
                    $workflowId = $input['workflow_run']['workflow_id'] ?? null;
                    $persistStats = $newsService->persistArticles($articles, null, 'Parallel RSS', $workflowId);
                } catch (Exception $persistError) {
                    Log::warning('Failed to persist parallel RSS articles', [
                        'error' => $persistError->getMessage(),
                    ]);
                }
            }

            // MONITORING: Alert if no articles were found
            // This could indicate a configuration issue or all feeds failing
            if (empty($articles)) {
                $allFeedsFailed = $metadata['successful_feeds'] === 0;
                $logLevel = $allFeedsFailed
                    ? $this->runtimePolicyLogLevel('all_feeds_failed_log_level', 'error')
                    : $this->runtimePolicyLogLevel('partial_empty_log_level', 'warning');

                Log::$logLevel('ParallelRSSFeedReader: No articles retrieved', [
                    'total_feeds' => $metadata['total_feeds'],
                    'successful_feeds' => $metadata['successful_feeds'],
                    'failed_feeds' => $metadata['failed_feeds'],
                    'possible_causes' => $allFeedsFailed
                        ? ['All feeds failed', 'Network connectivity issues', 'Feed URLs invalid']
                        : ['maxAgeHours filter too strict', 'All articles older than threshold', 'Feeds returned no new content'],
                    'recommendation' => $allFeedsFailed
                        ? 'Check network connectivity and feed URLs'
                        : 'Review maxAgeHours configuration or check feed freshness',
                ]);
            }

            return $this->standardOutput([
                'formatted_text' => $formatted,
                'articles' => $allArticles,
            ], array_merge($runtimeMeta, [
                'source' => 'Parallel RSS Feeds',
                'feed_count' => $metadata['total_feeds'],
                'successful_feeds' => $metadata['successful_feeds'],
                'failed_feeds' => $metadata['failed_feeds'],
                'article_count' => count($articles),
                'total_articles' => count($allArticles),
                'max_total_articles' => $maxTotalArticles,
                'formatted_text_chars' => $formatted ? mb_strlen($formatted) : 0,
                'duration_ms' => $duration,
                'fetched_at' => now()->toISOString(),
                'feed_details' => $metadata['feeds'],
                'persisted' => $persistStats,
            ]));

        } catch (Exception $e) {
            Log::error('ParallelRSSFeedReader: Error', [
                'message' => $e->getMessage(),
            ]);

            // Return previous data on error
            $previousData = $this->extractPreviousData($input);
            $previousArticles = $this->extractPreviousArticles($input);

            return $this->standardOutput([
                'formatted_text' => $previousData ?: null,
                'articles' => $previousArticles,
            ], [], 'Parallel RSS fetch error: '.$e->getMessage());
        }
    }

    private function configuredBool(string $key): ?bool
    {
        $value = $this->getConfigValue($key);
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function configuredInt(string $key): ?int
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function configuredPositiveInt(string $key): ?int
    {
        $value = $this->configuredInt($key);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function runtimePolicyBool(string $key): bool
    {
        $fallback = (bool) self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    private function runtimePolicyNonNegativeInt(string $key): int
    {
        return $this->nonNegativeInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    private function runtimePolicyLogLevel(string $key, string $fallback): string
    {
        $level = $this->runtimePolicy()[$key] ?? $fallback;
        if (! is_string($level)) {
            return $fallback;
        }

        $level = strtolower($level);

        return in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)
            ? $level
            : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function nonNegativeInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue >= 0 ? $intValue : $fallback;
    }

    /**
     * Extract previous data from input
     */
    private function extractPreviousData(array $input): string
    {
        if (isset($input['data']['formatted_text']) && is_string($input['data']['formatted_text'])) {
            return $input['data']['formatted_text'];
        }

        if (isset($input['data']) && is_string($input['data'])) {
            return $input['data'];
        }

        return '';
    }

    /**
     * Extract previous articles from input
     */
    private function extractPreviousArticles(array $input): array
    {
        if (isset($input['data']['articles']) && is_array($input['data']['articles'])) {
            return $input['data']['articles'];
        }

        return [];
    }

    /**
     * Filter articles by age
     */
    private function filterByAge(array $articles, int $maxAgeHours): array
    {
        if ($maxAgeHours <= 0) {
            return $articles;
        }

        $cutoffTimestamp = now()->subHours($maxAgeHours)->getTimestamp();

        return array_filter($articles, function ($article) use ($cutoffTimestamp) {
            try {
                $pubDate = new \DateTime($article['pubDate']);
                $articleTimestamp = $pubDate->getTimestamp();

                return $articleTimestamp >= $cutoffTimestamp;
            } catch (Exception $e) {
                // If date parsing fails, include the article
                return true;
            }
        });
    }

    /**
     * Format articles for output
     */
    private function formatArticles(array $articles, array $metadata, int $contentPreviewMaxChars): string
    {
        if (empty($articles)) {
            return 'No articles found from parallel RSS feeds.';
        }

        $output = [];
        $output[] = 'PARALLEL RSS FEED ARTICLES';
        $output[] = "Feeds: {$metadata['successful_feeds']}/{$metadata['total_feeds']} successful";
        $output[] = 'Total Articles: '.count($articles);
        $output[] = '';

        // Group by feed if we have feed details
        $feedDetails = [];
        foreach ($metadata['feeds'] as $feed) {
            if ($feed['status'] === 'success') {
                $feedDetails[$feed['url']] = [
                    'count' => $feed['article_count'],
                    'duration' => $feed['duration_ms'],
                ];
            }
        }

        if (! empty($feedDetails)) {
            $output[] = 'Feed Performance:';
            foreach ($feedDetails as $url => $stats) {
                $host = parse_url($url, PHP_URL_HOST);
                $output[] = "  • {$host}: {$stats['count']} articles ({$stats['duration']}ms)";
            }
            $output[] = '';
        }

        foreach ($articles as $index => $article) {
            $num = $index + 1;
            $output[] = "Article {$num}:";
            $output[] = "Title: {$article['title']}";

            if (! empty($article['description'])) {
                $output[] = "Description: {$article['description']}";
            }

            $output[] = "URL: {$article['url']}";

            if (! empty($article['author'])) {
                $output[] = "Author: {$article['author']}";
            }

            $output[] = "Published: {$article['pubDate']}";
            $output[] = "Source: {$article['source']}";

            if (! empty($article['content'])) {
                $content = mb_substr($article['content'], 0, $contentPreviewMaxChars, 'UTF-8');
                if (mb_strlen($article['content'], 'UTF-8') > $contentPreviewMaxChars) {
                    $content .= '...';
                }
                $output[] = "Content Preview: {$content}";
            }

            $output[] = ''; // Blank line between articles
        }

        return implode("\n", $output);
    }
}

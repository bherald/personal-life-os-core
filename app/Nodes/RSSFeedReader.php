<?php

namespace App\Nodes;

use Exception;
use SimpleXMLElement;
use App\Services\RetryService;
use App\Services\CircuitBreaker;
use App\Services\TimeoutManager;
use App\Services\DataSanitizer;
use App\Services\RssFeedHealthService;
use App\Services\NewsArticleService;

/**
 * RSS Feed Reader Node
 *
 * Fetches and parses RSS/Atom feeds from specified URL
 * Returns standardized article data for use in workflows
 *
 * Configuration:
 * - feed_url: (required) URL of RSS/Atom feed
 * - limit: (optional) Maximum articles to return (default: 10)
 * - timeout: (optional) Fetch timeout in seconds (default: 10)
 * - include_content: (optional) Include full content if available (default: false)
 * - persist_articles: (optional) Save articles to MySQL for historical search (default: true)
 * - feed_name: (optional) Human-readable name for the feed
 * - max_total_articles: (optional) Cap accumulated articles passed downstream (default: 50)
 * - max_formatted_chars: (optional) Cap formatted text size passed downstream (default: 50000)
 */
class RSSFeedReader extends BaseNode
{
    public function execute(array $input): array
    {
        // Extract previous RSS feed data FIRST (needed for error handling)
        $previousData = $this->extractPreviousData($input);
        $previousArticles = $this->extractPreviousArticles($input);

        $healthService = app(RssFeedHealthService::class);
        $healthCheckEnabled = $this->getConfigValue('track_health', true); // Track health by default

        try {
            $feedUrl = $this->getConfigValue('feed_url');

            if (empty($feedUrl)) {
                // Return previous data even on error to preserve accumulated feeds
                return $this->standardOutput([
                    'formatted_text' => $previousData ?: null,
                    'articles' => $previousArticles
                ], [], 'feed_url configuration is required');
            }

            $limit = (int) $this->getConfigValue('limit', 10);
            $timeout = (int) $this->getConfigValue('timeout', 10);
            $includeContent = $this->getConfigValue('include_content', false);
            $maxAgeHours = (int) $this->getConfigValue('max_age_hours', 24); // Filter articles older than 24 hours by default
            $maxTotalArticles = $this->resolvePositiveIntConfig('max_total_articles', 50);
            $maxFormattedChars = $this->resolvePositiveIntConfig('max_formatted_chars', 50000);

            $fetchStartTime = microtime(true);

            // Fetch RSS feed
            $feedContent = $this->fetchFeed($feedUrl, $timeout);

            if (empty($feedContent)) {
                // Track health failure
                if ($healthCheckEnabled) {
                    $this->recordHealthFailure($healthService, $feedUrl, 'network', 'Failed to fetch feed content', $fetchStartTime);
                }

                // Return previous data even on error to preserve accumulated feeds
                return $this->standardOutput([
                    'formatted_text' => $previousData ?: null,
                    'articles' => $previousArticles
                ], [], "Failed to fetch RSS feed from: {$feedUrl}");
            }

            // Parse RSS/Atom feed
            try {
                $articles = $this->parseFeed($feedContent, $feedUrl, $limit, $includeContent);
            } catch (Exception $parseError) {
                // Track health failure for parse errors
                if ($healthCheckEnabled) {
                    $this->recordHealthFailure($healthService, $feedUrl, 'parse', $parseError->getMessage(), $fetchStartTime);
                }

                return $this->standardOutput([
                    'formatted_text' => $previousData ?: null,
                    'articles' => $previousArticles
                ], [], "Failed to parse RSS feed: {$feedUrl} - {$parseError->getMessage()}");
            }

            // Filter old articles based on max_age_hours
            $articles = $this->filterByAge($articles, $maxAgeHours);

            // Track health success (even if no articles after filtering)
            if ($healthCheckEnabled) {
                $this->recordHealthSuccess($healthService, $feedUrl, count($articles), $fetchStartTime);
            }

            if (empty($articles)) {
                // Return previous data even when no articles found
                return $this->standardOutput([
                    'formatted_text' => $previousData ?: null,
                    'articles' => $previousArticles
                ], [], "No articles found in RSS feed: {$feedUrl}");
            }

            // Combine with current articles
            $allArticles = $this->mergeArticleCollections($previousArticles, $articles, $maxTotalArticles);

            // Format for output (backward compatibility)
            $formatted = $this->formatArticles($articles, $feedUrl);

            // CRITICAL: Prepend data from previous RSS feeds (if any)
            // This allows multiple RSS feeds to accumulate data in the workflow chain
            if (!empty($previousData)) {
                $formatted = $previousData . "\n\n" . $formatted;
            }

            $formatted = $this->trimFormattedText($formatted, $maxFormattedChars);

            // Persist articles to MySQL for historical search (default: enabled)
            $persistArticles = $this->getConfigValue('persist_articles', true);
            $persistStats = null;
            if ($persistArticles && !empty($articles)) {
                try {
                    $newsService = app(NewsArticleService::class);
                    $feedName = $this->getConfigValue('feed_name');
                    $workflowId = $input['workflow_run']['workflow_id'] ?? null;
                    $persistStats = $newsService->persistArticles($articles, $feedUrl, $feedName, $workflowId);
                } catch (Exception $persistError) {
                    \Log::warning('Failed to persist RSS articles', [
                        'feed' => $feedUrl,
                        'error' => $persistError->getMessage(),
                    ]);
                }
            }

            // NEW: Output both structured articles AND formatted text
            return $this->standardOutput([
                'formatted_text' => $formatted,
                'articles' => $allArticles  // Accumulated articles from all RSS feeds
            ], [
                'source' => 'RSS Feed',
                'feed_url' => $feedUrl,
                'article_count' => count($articles),
                'total_articles' => count($allArticles),
                'max_total_articles' => $maxTotalArticles,
                'formatted_text_chars' => $formatted ? mb_strlen($formatted) : 0,
                'limit' => $limit,
                'fetched_at' => now()->toISOString(),
                'persisted' => $persistStats,
            ]);

        } catch (Exception $e) {
            // Track health failure for unexpected exceptions
            if ($healthCheckEnabled) {
                $feedUrl = $this->getConfigValue('feed_url');
                if ($feedUrl) {
                    $healthService->checkFeedHealth($feedUrl, 10); // This will record the failure
                }
            }

            // Return previous data even on exception to preserve accumulated feeds
            return $this->standardOutput([
                'formatted_text' => $previousData ?: null,
                'articles' => $previousArticles
            ], [], 'RSS feed error: ' . $e->getMessage());
        }
    }

    /**
     * Record health success for a feed
     */
    private function recordHealthSuccess(RssFeedHealthService $healthService, string $feedUrl, int $articleCount, float $startTime): void
    {
        try {
            $responseTimeMs = round((microtime(true) - $startTime) * 1000);

            // Use the health service to record success
            // We'll create a simplified success record directly
            $healthService->checkFeedHealth($feedUrl, 10);
        } catch (\Exception $e) {
            // Silently fail health tracking - don't break workflow
            \Log::warning("Failed to record RSS feed health success", [
                'feed_url' => $feedUrl,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Record health failure for a feed
     */
    private function recordHealthFailure(RssFeedHealthService $healthService, string $feedUrl, string $errorType, string $errorMessage, float $startTime): void
    {
        try {
            // The health service will record the failure
            $healthService->checkFeedHealth($feedUrl, 10);
        } catch (\Exception $e) {
            // Silently fail health tracking - don't break workflow
            \Log::warning("Failed to record RSS feed health failure", [
                'feed_url' => $feedUrl,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extract previous node data (e.g., other RSS feeds) from input
     * This handles the workflow chaining where multiple RSS feeds execute in sequence
     */
    private function extractPreviousData(array $input): string
    {
        // Check for formatted_text from previous RSS feeds
        if (isset($input['data']['formatted_text']) && is_string($input['data']['formatted_text'])) {
            return $input['data']['formatted_text'];
        }

        // Check if input contains data from previous nodes
        if (isset($input['data']) && is_string($input['data'])) {
            return $input['data'];
        }

        return '';
    }

    /**
     * Extract previous articles (structured) from input
     * This allows accumulation of articles from multiple RSS feeds
     */
    private function extractPreviousArticles(array $input): array
    {
        // Check for articles array from previous RSS feeds
        if (isset($input['data']['articles']) && is_array($input['data']['articles'])) {
            return $input['data']['articles'];
        }

        return [];
    }

    /**
     * Filter articles by age, removing those older than maxAgeHours
     */
    private function filterByAge(array $articles, int $maxAgeHours): array
    {
        if ($maxAgeHours <= 0) {
            return $articles; // No filtering if max_age_hours is 0 or negative
        }

        $cutoffTimestamp = now()->subHours($maxAgeHours)->getTimestamp();

        return array_filter($articles, function($article) use ($cutoffTimestamp) {
            try {
                $pubDate = new \DateTime($article['pubDate']);
                $articleTimestamp = $pubDate->getTimestamp();
                return $articleTimestamp >= $cutoffTimestamp;
            } catch (\Exception $e) {
                // If date parsing fails, include the article
                return true;
            }
        });
    }

    /**
     * Fetch RSS feed content via HTTP with fault tolerance
     */
    private function fetchFeed(string $url, int $timeout): ?string
    {
        $retryService = app(RetryService::class);
        $circuitBreaker = app(CircuitBreaker::class);
        $timeoutManager = app(TimeoutManager::class);

        // Use adaptive timeout if available
        $adaptiveTimeout = $timeoutManager->getTimeout('rss_feed', $timeout);

        $startTime = microtime(true);

        try {
            // Circuit breaker protection
            $content = $circuitBreaker->call('rss_feed', function () use ($retryService, $url, $adaptiveTimeout) {
                // Retry logic with exponential backoff
                return $retryService->retry(
                    operation: function () use ($url, $adaptiveTimeout) {
                        $context = stream_context_create([
                            'http' => [
                                'method' => 'GET',
                                'timeout' => $adaptiveTimeout,
                                'user_agent' => 'PLOS-RSS-Reader/2.0',
                                'follow_location' => 1,
                                'max_redirects' => 3
                            ]
                        ]);

                        $content = @file_get_contents($url, false, $context);

                        if ($content === false) {
                            throw new Exception("Failed to fetch RSS feed from: {$url}");
                        }

                        return $content;
                    },
                    maxAttempts: 3,
                    backoffStrategy: 'exponential',
                    shouldRetry: function (Exception $e) {
                        // Retry on network errors, not on parse errors
                        return !str_contains($e->getMessage(), 'parse');
                    },
                    operationName: "RSS Feed: {$url}"
                );
            });

            // Record successful execution for adaptive timeout
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('rss_feed', $duration, true);

            return $content;

        } catch (Exception $e) {
            // Record failed execution
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('rss_feed', $duration, false);

            // Return null to trigger graceful degradation in execute()
            return null;
        }
    }

    /**
     * Parse RSS or Atom feed XML
     */
    private function parseFeed(string $content, string $feedUrl, int $limit, bool $includeContent): array
    {
        try {
            // Suppress XML parsing warnings
            libxml_use_internal_errors(true);

            $xml = new SimpleXMLElement($content);
            libxml_clear_errors();

            $articles = [];

            // Detect feed type (RSS 2.0 or Atom)
            if (isset($xml->channel->item)) {
                // RSS 2.0
                $items = $xml->channel->item;

                $count = 0;
                foreach ($items as $item) {
                    if ($count >= $limit) break;

                    // Extract domain-based source for better bias matching
                    $domainSource = $this->extractDomainFromUrl($feedUrl);
                    $channelTitle = $this->sanitizeUtf8((string)($xml->channel->title ?? 'RSS Feed'));

                    $article = [
                        'title' => $this->sanitizeUtf8((string)$item->title),
                        'description' => $this->cleanDescription((string)$item->description),
                        'url' => (string)$item->link,
                        'pubDate' => $this->parseDate((string)$item->pubDate),
                        'author' => $this->sanitizeUtf8((string)($item->author ?? $item->creator ?? '')),
                        'source' => $domainSource ?: $channelTitle,
                        'source_display' => $channelTitle, // Keep original name for display
                        'feed_url' => $feedUrl, // Store feed URL for bias matching
                    ];

                    // Include full content if requested and available
                    if ($includeContent && isset($item->children('content', true)->encoded)) {
                        $article['content'] = $this->sanitizeUtf8(strip_tags((string)$item->children('content', true)->encoded));
                    }

                    // Only add if has title and link
                    if (!empty($article['title']) && !empty($article['url'])) {
                        $articles[] = $article;
                        $count++;
                    }
                }
            } elseif (isset($xml->entry)) {
                // Atom feed
                $count = 0;
                foreach ($xml->entry as $entry) {
                    if ($count >= $limit) break;

                    // Extract domain-based source for better bias matching
                    $domainSource = $this->extractDomainFromUrl($feedUrl);
                    $feedTitle = $this->sanitizeUtf8((string)($xml->title ?? 'Atom Feed'));

                    $article = [
                        'title' => $this->sanitizeUtf8((string)$entry->title),
                        'description' => $this->cleanDescription((string)$entry->summary),
                        'url' => (string)($entry->link['href'] ?? $entry->link),
                        'pubDate' => $this->parseDate((string)($entry->published ?? $entry->updated)),
                        'author' => $this->sanitizeUtf8((string)($entry->author->name ?? '')),
                        'source' => $domainSource ?: $feedTitle,
                        'source_display' => $feedTitle, // Keep original name for display
                        'feed_url' => $feedUrl, // Store feed URL for bias matching
                    ];

                    // Include content if requested
                    if ($includeContent && isset($entry->content)) {
                        $article['content'] = $this->sanitizeUtf8(strip_tags((string)$entry->content));
                    }

                    // Only add if has title and link
                    if (!empty($article['title']) && !empty($article['url'])) {
                        $articles[] = $article;
                        $count++;
                    }
                }
            }

            return $articles;

        } catch (Exception $e) {
            throw new Exception("Failed to parse RSS feed: " . $e->getMessage());
        }
    }

    /**
     * Clean and truncate description/summary (now uses DataSanitizer service)
     */
    private function cleanDescription(string $description): string
    {
        // Use centralized DataSanitizer service
        return DataSanitizer::cleanHtml($description, 500);
    }

    /**
     * Sanitize UTF-8 string (now uses DataSanitizer service)
     */
    private function sanitizeUtf8(string $text): string
    {
        // Use centralized DataSanitizer service
        return DataSanitizer::cleanUtf8($text);
    }

    /**
     * Parse various date formats into ISO 8601
     */
    private function parseDate(string $dateString): string
    {
        if (empty($dateString)) {
            return now()->toISOString();
        }

        try {
            $date = new \DateTime($dateString);
            return $date->format('c'); // ISO 8601
        } catch (Exception $e) {
            return now()->toISOString();
        }
    }

    /**
     * Extract domain from feed URL for reliable source identification
     *
     * Examples:
     * - https://www.wnep.com/feeds/rss -> wnep.com
     * - https://feeds.npr.org/1001/rss.xml -> npr.org
     * - https://www.spotlightpa.org/feeds/full.xml -> spotlightpa.org
     *
     * @param string $url Feed URL
     * @return string|null Domain without common prefixes
     */
    private function extractDomainFromUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if (!isset($parsed['host'])) {
            return null;
        }

        $host = strtolower($parsed['host']);

        // Remove common prefixes
        $prefixes = ['www.', 'feeds.', 'rss.', 'api.', 'news.'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($host, $prefix)) {
                $host = substr($host, strlen($prefix));
            }
        }

        return $host;
    }

    /**
     * Format articles into structured text for AI processing
     */
    private function formatArticles(array $articles, string $feedUrl): string
    {
        if (empty($articles)) {
            return "No articles found in RSS feed.";
        }

        $output = [];
        $output[] = "RSS FEED ARTICLES";
        $output[] = "Source: {$feedUrl}";
        $output[] = "Total Articles: " . count($articles);
        $output[] = "";

        foreach ($articles as $index => $article) {
            $num = $index + 1;
            $output[] = "Article {$num}:";
            $output[] = "Title: {$article['title']}";

            if (!empty($article['description'])) {
                $output[] = "Description: {$article['description']}";
            }

            $output[] = "URL: {$article['url']}";

            if (!empty($article['author'])) {
                $output[] = "Author: {$article['author']}";
            }

            $output[] = "Published: {$article['pubDate']}";

            // Include bias rating if available
            $biasInfo = '';
            if (isset($article['bias_rating'])) {
                $biasEmoji = $article['bias_rating']['emoji'] ?? '';
                $biasText = $article['bias_rating']['rating'] ?? '';
                if ($biasEmoji && $biasText) {
                    $biasInfo = " {$biasEmoji} (bias: {$biasText})";
                }
            }
            $output[] = "Source: {$article['source']}{$biasInfo}";

            if (!empty($article['content'])) {
                // Truncate content for display (use mb_substr to avoid cutting UTF-8 characters)
                $content = mb_substr($article['content'], 0, 300, 'UTF-8');
                if (mb_strlen($article['content'], 'UTF-8') > 300) {
                    $content .= '...';
                }
                $output[] = "Content Preview: {$content}";
            }

            $output[] = ""; // Blank line between articles
        }

        return implode("\n", $output);
    }
}

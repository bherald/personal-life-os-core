<?php

namespace App\Services;

use Exception;
use SimpleXMLElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RSS Feed Health Monitoring Service
 *
 * Validates RSS feeds and tracks their health status over time.
 * All database operations use raw SQL (no Eloquent).
 */
class RssFeedHealthService
{
    // Health status constants
    const STATUS_HEALTHY = 'healthy';
    const STATUS_DEGRADED = 'degraded';
    const STATUS_FAILED = 'failed';
    const STATUS_UNKNOWN = 'unknown';

    // Error type constants
    const ERROR_TYPE_TIMEOUT = 'timeout';
    const ERROR_TYPE_NETWORK = 'network';
    const ERROR_TYPE_PARSE = 'parse';
    const ERROR_TYPE_HTTP_ERROR = 'http_error';

    // Thresholds
    const HEALTHY_THRESHOLD = 0;
    const DEGRADED_THRESHOLD = 2;
    const FAILED_THRESHOLD = 3;
    const DEFAULT_TIMEOUT = 15;
    const WARNING_RESPONSE_TIME = 5000;

    /**
     * Check health of a single RSS feed
     */
    public function checkFeedHealth(string $feedUrl, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $startTime = microtime(true);
        $result = [
            'url' => $feedUrl,
            'success' => false,
            'response_time_ms' => 0,
            'error_type' => null,
            'error_message' => null,
            'article_count' => 0,
            'feed_title' => null,
            'total_items' => 0,
            'feed_last_updated' => null,
            'redirect_detected' => false,
            'redirect_url' => null,
            'redirect_is_valid_feed' => false,
        ];

        try {
            $fetchResult = $this->fetchFeedContent($feedUrl, $timeout);
            $content = $fetchResult['content'];

            if ($fetchResult['redirect_detected']) {
                $result['redirect_detected'] = true;
                $result['redirect_url'] = $fetchResult['final_url'];

                Log::info('RssFeedHealthService: Redirect detected', [
                    'original_url' => $feedUrl,
                    'redirect_url' => $fetchResult['final_url'],
                    'http_code' => $fetchResult['http_code'],
                ]);

                if ($content && $this->isValidFeedContent($content)) {
                    $result['redirect_is_valid_feed'] = true;
                } else {
                    $redirectContent = $this->fetchRedirectContent($fetchResult['final_url'], $timeout);
                    $result['redirect_is_valid_feed'] = $redirectContent && $this->isValidFeedContent($redirectContent);
                }
            }

            if ($content === false || empty($content)) {
                $result['error_type'] = self::ERROR_TYPE_NETWORK;
                $result['error_message'] = 'Failed to fetch feed content (HTTP ' . ($fetchResult['http_code'] ?? 'unknown') . ')';
                $result['response_time_ms'] = round((microtime(true) - $startTime) * 1000);
                return $this->updateHealthRecord($feedUrl, $result);
            }

            try {
                libxml_use_internal_errors(true);
                $xml = new SimpleXMLElement($content);
                libxml_clear_errors();

                if (isset($xml->channel)) {
                    $result['feed_title'] = (string) ($xml->channel->title ?? '');
                    $result['total_items'] = count($xml->channel->item ?? []);
                    $result['article_count'] = $result['total_items'];
                    if (isset($xml->channel->lastBuildDate)) {
                        try {
                            $result['feed_last_updated'] = (new \DateTime((string) $xml->channel->lastBuildDate))
                                ->setTimezone(new \DateTimeZone('UTC'))
                                ->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            Log::debug('RssFeedHealthService: lastBuildDate parse failed', ['error' => $e->getMessage()]);
                        }
                    }
                } elseif (isset($xml->entry)) {
                    $result['feed_title'] = (string) ($xml->title ?? '');
                    $result['total_items'] = count($xml->entry);
                    $result['article_count'] = $result['total_items'];
                    if (isset($xml->updated)) {
                        try {
                            $result['feed_last_updated'] = (new \DateTime((string) $xml->updated))
                                ->setTimezone(new \DateTimeZone('UTC'))
                                ->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            Log::debug('RssFeedHealthService: atom updated date parse failed', ['error' => $e->getMessage()]);
                        }
                    }
                }

                $result['success'] = true;
            } catch (Exception $e) {
                $result['error_type'] = self::ERROR_TYPE_PARSE;
                $result['error_message'] = 'Failed to parse XML: ' . $e->getMessage();
                if ($result['redirect_detected'] && !$result['redirect_is_valid_feed']) {
                    $result['error_message'] = 'Feed redirected to non-RSS content: ' . $fetchResult['final_url'];
                }
            }
        } catch (Exception $e) {
            $result['error_type'] = self::ERROR_TYPE_NETWORK;
            $result['error_message'] = $e->getMessage();
        }

        $result['response_time_ms'] = round((microtime(true) - $startTime) * 1000);

        return $this->updateHealthRecord($feedUrl, $result);
    }

    /**
     * Fetch content from redirect URL directly
     */
    private function fetchRedirectContent(string $url, int $timeout): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'user_agent' => 'PLOS-RSS-Health-Monitor/2.0',
                'follow_location' => 0,
            ],
        ]);

        set_error_handler(function () {
        });
        $content = @file_get_contents($url, false, $context);
        restore_error_handler();

        return $content;
    }

    /**
     * Fetch feed content via HTTP with redirect tracking
     */
    private function fetchFeedContent(string $url, int $timeout): array
    {
        $result = [
            'content' => false,
            'final_url' => null,
            'redirect_detected' => false,
            'http_code' => null,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_TIMEOUT => min($timeout, 10),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'PLOS-RSS-Health-Monitor/2.0',
        ]);
        curl_exec($ch);
        $curlError = curl_error($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result['http_code'] = $httpCode;

        if ($finalUrl && $finalUrl !== $url) {
            $result['redirect_detected'] = true;
            $result['final_url'] = $finalUrl;
        }

        if ($curlError) {
            Log::debug('RssFeedHealthService: redirect probe failed', [
                'url' => $url,
                'error' => $curlError,
            ]);
        }

        $fetchUrl = $result['final_url'] ?: $url;

        try {
            $response = Http::connectTimeout(min($timeout, 5))
                ->timeout($timeout)
                ->withHeaders(['User-Agent' => 'PLOS-RSS-Health-Monitor/2.0'])
                ->get($fetchUrl);

            if ($response->successful()) {
                $result['content'] = $response->body();
            }
        } catch (\Throwable $e) {
            Log::debug('RssFeedHealthService: feed fetch failed', [
                'url' => $fetchUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Check if content is valid RSS/Atom XML
     */
    private function isValidFeedContent(string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        if (stripos($content, '<rss') === false &&
            stripos($content, '<feed') === false &&
            stripos($content, '<channel') === false) {
            return false;
        }

        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($content);
        libxml_clear_errors();

        return $xml !== false;
    }

    /**
     * Update or create health record for a feed (raw SQL)
     */
    private function updateHealthRecord(string $feedUrl, array $result): array
    {
        $existing = DB::selectOne("SELECT * FROM rss_feed_health WHERE feed_url = ? LIMIT 1", [$feedUrl]);

        $now = now()->format('Y-m-d H:i:s');

        if ($existing) {
            $updates = ['last_check_at' => $now];

            if ($result['success']) {
                $updates['last_success_at'] = $now;
                $updates['consecutive_successes'] = $existing->consecutive_successes + 1;
                $updates['consecutive_failures'] = 0;
                $updates['total_successes'] = $existing->total_successes + 1;
                $updates['articles_fetched_last_success'] = $result['article_count'];

                if ($result['feed_title']) {
                    $updates['feed_title'] = $result['feed_title'];
                    $updates['feed_name'] = $result['feed_title'];
                }
                $updates['total_items_in_feed'] = $result['total_items'];
                if ($result['feed_last_updated']) {
                    $updates['feed_last_updated'] = $result['feed_last_updated'];
                }

                $avgTime = $existing->avg_response_time_ms
                    ? round(($existing->avg_response_time_ms + $result['response_time_ms']) / 2)
                    : $result['response_time_ms'];
                $updates['avg_response_time_ms'] = $avgTime;

                $updates['last_error_message'] = null;
                $updates['last_error_type'] = null;

                if ($existing->alert_sent) {
                    $updates['alert_sent'] = 0;
                    $updates['alert_sent_at'] = null;
                }

                if ($existing->permanently_dead) {
                    $updates['permanently_dead'] = 0;
                    Log::info('RssFeedHealthService: Feed recovered from dead state', ['feed_url' => $feedUrl]);
                }
            } else {
                $updates['last_failure_at'] = $now;
                $updates['consecutive_failures'] = $existing->consecutive_failures + 1;
                $updates['consecutive_successes'] = 0;
                $updates['total_failures'] = $existing->total_failures + 1;

                if ($result['error_type'] === self::ERROR_TYPE_TIMEOUT) {
                    $updates['total_timeouts'] = $existing->total_timeouts + 1;
                }

                $updates['last_error_message'] = $result['error_message'];
                $updates['last_error_type'] = $result['error_type'];
            }

            if ($result['redirect_detected'] ?? false) {
                $updates['redirect_url'] = $result['redirect_url'];
                $updates['redirect_count'] = ($existing->redirect_count ?? 0) + 1;
                if (!$existing->redirect_detected_at) {
                    $updates['redirect_detected_at'] = $now;
                }

                if (!$result['redirect_is_valid_feed'] && ($updates['consecutive_failures'] ?? $existing->consecutive_failures) >= 3) {
                    $updates['permanently_dead'] = 1;
                    Log::warning('RssFeedHealthService: Feed marked as permanently dead', [
                        'feed_url' => $feedUrl,
                        'redirect_url' => $result['redirect_url'],
                        'reason' => 'Redirects to non-RSS content after 3+ failures',
                    ]);
                }
            }

            $updates['total_checks'] = $existing->total_checks + 1;

            // Determine status
            $consFailures = $updates['consecutive_failures'] ?? $existing->consecutive_failures;
            $totalSuccesses = $updates['total_successes'] ?? $existing->total_successes;
            $updates['status'] = $this->determineHealthStatus($consFailures, $totalSuccesses);

            $updates['updated_at'] = $now;

            $setClauses = [];
            $params = [];
            foreach ($updates as $col => $val) {
                $setClauses[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = $existing->id;

            DB::update("UPDATE rss_feed_health SET " . implode(', ', $setClauses) . " WHERE id = ?", $params);
        } else {
            // Insert new record
            $consFailures = $result['success'] ? 0 : 1;
            $totalSuccesses = $result['success'] ? 1 : 0;
            $status = $this->determineHealthStatus($consFailures, $totalSuccesses);

            DB::insert("INSERT INTO rss_feed_health (feed_url, status, last_check_at, last_success_at, last_failure_at,
                consecutive_failures, consecutive_successes, total_checks, total_successes, total_failures,
                total_timeouts, articles_fetched_last_success, feed_title, feed_name, total_items_in_feed,
                feed_last_updated, avg_response_time_ms, last_error_message, last_error_type,
                redirect_url, redirect_count, redirect_detected_at, permanently_dead,
                alert_sent, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)", [
                $feedUrl,
                $status,
                $now,
                $result['success'] ? $now : null,
                $result['success'] ? null : $now,
                $result['success'] ? 0 : 1,
                $result['success'] ? 1 : 0,
                $result['success'] ? 1 : 0,
                $result['success'] ? 0 : 1,
                (!$result['success'] && $result['error_type'] === self::ERROR_TYPE_TIMEOUT) ? 1 : 0,
                $result['article_count'],
                $result['feed_title'],
                $result['feed_title'],
                $result['total_items'],
                $result['feed_last_updated'],
                $result['response_time_ms'],
                $result['error_message'],
                $result['error_type'],
                ($result['redirect_detected'] ?? false) ? $result['redirect_url'] : null,
                ($result['redirect_detected'] ?? false) ? 1 : 0,
                ($result['redirect_detected'] ?? false) ? $now : null,
                0,
                $now,
                $now,
            ]);
        }

        return $result;
    }

    /**
     * Determine health status based on consecutive failures and success count
     */
    private function determineHealthStatus(int $consecutiveFailures, int $totalSuccesses): string
    {
        if ($consecutiveFailures >= self::FAILED_THRESHOLD) {
            return self::STATUS_FAILED;
        }
        if ($consecutiveFailures >= self::DEGRADED_THRESHOLD) {
            return self::STATUS_DEGRADED;
        }
        if ($consecutiveFailures === self::HEALTHY_THRESHOLD && $totalSuccesses > 0) {
            return self::STATUS_HEALTHY;
        }
        return self::STATUS_UNKNOWN;
    }

    /**
     * Check health of multiple feeds
     */
    public function checkMultipleFeeds(array $feedUrls, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $results = [];
        foreach ($feedUrls as $feedUrl) {
            $results[] = $this->checkFeedHealth($feedUrl, $timeout);
        }
        return $results;
    }

    /**
     * Get all feeds needing attention (degraded or failed)
     */
    public function getFeedsNeedingAttention(): array
    {
        $rows = DB::select(
            "SELECT * FROM rss_feed_health
             WHERE status IN (?, ?)
             ORDER BY consecutive_failures DESC, last_check_at DESC",
            [self::STATUS_DEGRADED, self::STATUS_FAILED]
        );

        return array_map(function ($row) {
            $row = (array) $row;
            $row['success_rate'] = $row['total_checks'] > 0
                ? round(($row['total_successes'] / $row['total_checks']) * 100, 2)
                : 0;
            return $row;
        }, $rows);
    }

    /**
     * Get feeds that should trigger alerts
     */
    public function getFeedsThatNeedAlerts(int $failureThreshold = 3): array
    {
        return array_map(
            fn($r) => (array) $r,
            DB::select(
                "SELECT * FROM rss_feed_health
                 WHERE status = ? AND consecutive_failures >= ? AND alert_sent = 0",
                [self::STATUS_FAILED, $failureThreshold]
            )
        );
    }

    /**
     * Get health summary for all feeds
     */
    public function getHealthSummary(): array
    {
        $rows = DB::select(
            "SELECT status, COUNT(*) as cnt FROM rss_feed_health GROUP BY status"
        );

        $counts = ['healthy' => 0, 'degraded' => 0, 'failed' => 0, 'unknown' => 0];
        $total = 0;
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->cnt;
            $total += (int) $row->cnt;
        }

        return [
            'total' => $total,
            'healthy' => $counts['healthy'],
            'degraded' => $counts['degraded'],
            'failed' => $counts['failed'],
            'unknown' => $counts['unknown'],
            'health_percentage' => $total > 0 ? round(($counts['healthy'] / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Extract all RSS feed URLs from workflow configurations
     */
    public function extractFeedUrlsFromWorkflows(): array
    {
        $urls = [];

        $rssNodes = DB::select(
            "SELECT * FROM workflow_nodes WHERE node_type IN (?, ?)",
            ['RSSFeedReader', 'ParallelRSSFeedReader']
        );

        foreach ($rssNodes as $node) {
            if ($node->node_type === 'RSSFeedReader') {
                $result = DB::select(
                    "SELECT config_value FROM workflow_node_configs WHERE workflow_node_id = ? AND config_key = ? LIMIT 1",
                    [$node->id, 'feed_url']
                );
                $feedUrl = $result[0]->config_value ?? null;
                if ($feedUrl && !in_array($feedUrl, $urls)) {
                    $urls[] = $feedUrl;
                }
            } elseif ($node->node_type === 'ParallelRSSFeedReader') {
                $result = DB::select(
                    "SELECT config_value FROM workflow_node_configs WHERE workflow_node_id = ? AND config_key = ? LIMIT 1",
                    [$node->id, 'feeds']
                );
                $feedsJson = $result[0]->config_value ?? null;
                if ($feedsJson) {
                    try {
                        $feeds = json_decode($feedsJson, true);
                        if (is_array($feeds)) {
                            foreach ($feeds as $feed) {
                                if (isset($feed['url']) && !in_array($feed['url'], $urls)) {
                                    $urls[] = $feed['url'];
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to parse parallel feed config", [
                            'node_id' => $node->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * Check health of all feeds configured in workflows
     */
    public function checkAllWorkflowFeeds(int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $feedUrls = $this->extractFeedUrlsFromWorkflows();
        return $this->checkMultipleFeeds($feedUrls, $timeout);
    }

    /**
     * Generate health report text
     */
    public function generateHealthReport(): string
    {
        $summary = $this->getHealthSummary();
        $needsAttention = $this->getFeedsNeedingAttention();

        $report = [];
        $report[] = "RSS Feed Health Report";
        $report[] = "Generated: " . now()->format('Y-m-d H:i:s');
        $report[] = "";
        $report[] = "Summary:";
        $report[] = "  Total Feeds: {$summary['total']}";
        $report[] = "  Healthy: {$summary['healthy']}";
        $report[] = "  Degraded: {$summary['degraded']}";
        $report[] = "  Failed: {$summary['failed']}";
        $report[] = "  Unknown: {$summary['unknown']}";
        $report[] = "  Health Rate: {$summary['health_percentage']}%";
        $report[] = "";

        if (count($needsAttention) > 0) {
            $report[] = "Feeds Needing Attention:";
            $report[] = "";

            foreach ($needsAttention as $feed) {
                $feedName = $feed['feed_title'] ?: $feed['feed_url'];
                $successRate = $feed['success_rate'] ?? 0;

                $report[] = "[{$feed['status']}] {$feedName}";
                $report[] = "   URL: {$feed['feed_url']}";
                $report[] = "   Consecutive Failures: {$feed['consecutive_failures']}";
                $report[] = "   Success Rate: {$successRate}%";

                if ($feed['last_error_message']) {
                    $report[] = "   Last Error: {$feed['last_error_message']}";
                }
                $report[] = "";
            }
        } else {
            $report[] = "All feeds are healthy!";
        }

        return implode("\n", $report);
    }

    // =========================================================================
    // SELF-CORRECTION METHODS
    // =========================================================================

    /**
     * Get feeds that need auto-correction
     */
    public function getFeedsNeedingCorrection(): array
    {
        return DB::select(
            "SELECT * FROM rss_feed_health
             WHERE redirect_url IS NOT NULL
             AND auto_corrected = 0
             AND permanently_dead = 0
             AND (consecutive_failures >= 3 OR redirect_count >= 2)
             ORDER BY consecutive_failures DESC, redirect_count DESC"
        );
    }

    /**
     * Get permanently dead feeds
     */
    public function getDeadFeeds(): array
    {
        return array_map(
            fn($r) => (array) $r,
            DB::select("SELECT * FROM rss_feed_health WHERE permanently_dead = 1")
        );
    }

    /**
     * Validate that a URL is a safe RSS feed before auto-correction
     */
    public function validateFeedUrlSafety(string $url): array
    {
        $result = [
            'safe' => false,
            'url' => $url,
            'checks' => [],
            'reason' => null,
        ];

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $result['reason'] = 'Invalid URL format';
            $result['checks']['url_format'] = false;
            return $result;
        }
        $result['checks']['url_format'] = true;

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        $host = $parsed['host'] ?? '';

        $trustedHttpDomains = ['feeds.feedburner.com', 'rss.nytimes.com', 'feeds.bbci.co.uk'];
        if ($scheme !== 'https' && !in_array($host, $trustedHttpDomains)) {
            $result['checks']['https'] = false;
            Log::warning('RssFeedHealthService: Feed uses HTTP', ['url' => $url]);
        } else {
            $result['checks']['https'] = true;
        }

        $blockedDomains = [
            'bit.ly', 't.co', 'goo.gl', 'tinyurl.com',
            'facebook.com', 'instagram.com', 'twitter.com',
            'youtube.com', 'youtu.be',
        ];
        foreach ($blockedDomains as $blocked) {
            if (stripos($host, $blocked) !== false) {
                $result['reason'] = "Blocked domain: {$blocked}";
                $result['checks']['domain_allowed'] = false;
                return $result;
            }
        }
        $result['checks']['domain_allowed'] = true;

        try {
            $fetchResult = $this->fetchFeedContent($url, 10);
            $content = $fetchResult['content'];

            if ($content === false || empty($content)) {
                $result['reason'] = 'Failed to fetch content';
                $result['checks']['fetch_success'] = false;
                return $result;
            }
            $result['checks']['fetch_success'] = true;

            if (!$this->isValidFeedContent($content)) {
                $result['reason'] = 'Content is not valid RSS/Atom XML';
                $result['checks']['valid_rss'] = false;
                return $result;
            }
            $result['checks']['valid_rss'] = true;

            libxml_use_internal_errors(true);
            $xml = @simplexml_load_string($content);
            libxml_clear_errors();

            $articleCount = 0;
            if ($xml) {
                if (isset($xml->channel->item)) {
                    $articleCount = count($xml->channel->item);
                } elseif (isset($xml->entry)) {
                    $articleCount = count($xml->entry);
                }
            }

            if ($articleCount === 0) {
                $result['reason'] = 'Feed contains no articles';
                $result['checks']['has_articles'] = false;
                return $result;
            }
            $result['checks']['has_articles'] = true;
            $result['article_count'] = $articleCount;

            $result['safe'] = true;
            $result['reason'] = 'All safety checks passed';
        } catch (Exception $e) {
            $result['reason'] = 'Exception during validation: ' . $e->getMessage();
            $result['checks']['no_exception'] = false;
        }

        return $result;
    }

    /**
     * Auto-correct a feed URL in workflow configurations
     */
    public function autoCorrectFeedUrl(string $oldUrl, string $newUrl): array
    {
        $result = [
            'success' => false,
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'configs_updated' => 0,
            'reason' => null,
        ];

        $safety = $this->validateFeedUrlSafety($newUrl);
        if (!$safety['safe']) {
            $result['reason'] = 'New URL failed safety validation: ' . $safety['reason'];
            Log::warning('RssFeedHealthService: Auto-correction blocked - unsafe URL', [
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'safety_result' => $safety,
            ]);
            return $result;
        }

        try {
            $existingNew = DB::select("SELECT id FROM rss_feed_health WHERE feed_url = ? LIMIT 1", [$newUrl]);
            $existingOld = DB::select("SELECT id FROM rss_feed_health WHERE feed_url = ? LIMIT 1", [$oldUrl]);

            $updated = DB::update(
                "UPDATE workflow_node_configs SET config_value = ? WHERE config_key = 'feed_url' AND config_value = ?",
                [$newUrl, $oldUrl]
            );

            $result['configs_updated'] = $updated;

            if (!empty($existingNew) && !empty($existingOld)) {
                DB::delete("DELETE FROM rss_feed_health WHERE feed_url = ?", [$oldUrl]);
                DB::update(
                    "UPDATE rss_feed_health SET consecutive_failures = 0, status = 'unknown', redirect_url = NULL, redirect_count = 0 WHERE feed_url = ?",
                    [$newUrl]
                );
                $result['merged_records'] = true;
                Log::info('RssFeedHealthService: Merged duplicate health records', ['deleted' => $oldUrl, 'kept' => $newUrl]);
            } elseif (!empty($existingOld)) {
                DB::update(
                    "UPDATE rss_feed_health SET auto_corrected = 1, auto_corrected_at = NOW(), original_url = ?, feed_url = ?, consecutive_failures = 0, status = 'unknown' WHERE feed_url = ?",
                    [$oldUrl, $newUrl, $oldUrl]
                );
            }

            $result['success'] = true;

            if ($updated > 0) {
                $result['reason'] = "Updated {$updated} workflow config(s)" .
                    (($result['merged_records'] ?? false) ? " and merged health records" : " and health record");
            } else {
                $result['reason'] = ($result['merged_records'] ?? false)
                    ? "Merged health records (no active workflow configs found)"
                    : "Updated health record (no active workflow configs found)";
            }

            Log::info('RssFeedHealthService: Auto-corrected feed URL', [
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'configs_updated' => $updated,
            ]);
        } catch (Exception $e) {
            $result['reason'] = 'Database error: ' . $e->getMessage();
            Log::error('RssFeedHealthService: Auto-correction failed', [
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Run self-healing process for all failed feeds
     */
    public function runSelfHealing(): array
    {
        $results = [
            'timestamp' => now()->toIso8601String(),
            'feeds_checked' => 0,
            'auto_corrected' => 0,
            'marked_dead' => 0,
            'skipped' => 0,
            'corrections' => [],
            'dead_feeds' => [],
            'errors' => [],
        ];

        $feedsToCheck = $this->getFeedsNeedingCorrection();
        $results['feeds_checked'] = count($feedsToCheck);

        foreach ($feedsToCheck as $feed) {
            try {
                $safety = $this->validateFeedUrlSafety($feed->redirect_url);

                if ($safety['safe']) {
                    $correction = $this->autoCorrectFeedUrl($feed->feed_url, $feed->redirect_url);
                    if ($correction['success']) {
                        $results['auto_corrected']++;
                        $results['corrections'][] = [
                            'old_url' => $feed->feed_url,
                            'new_url' => $feed->redirect_url,
                            'configs_updated' => $correction['configs_updated'],
                        ];
                    } else {
                        $results['skipped']++;
                        $results['errors'][] = ['url' => $feed->feed_url, 'reason' => $correction['reason']];
                    }
                } else {
                    DB::update("UPDATE rss_feed_health SET permanently_dead = 1 WHERE id = ?", [$feed->id]);
                    $results['marked_dead']++;
                    $results['dead_feeds'][] = [
                        'url' => $feed->feed_url,
                        'redirect' => $feed->redirect_url,
                        'reason' => $safety['reason'],
                    ];
                    Log::warning('RssFeedHealthService: Feed marked as dead during self-healing', [
                        'feed_url' => $feed->feed_url,
                        'redirect_url' => $feed->redirect_url,
                        'reason' => $safety['reason'],
                    ]);
                }
            } catch (Exception $e) {
                $results['errors'][] = ['url' => $feed->feed_url, 'error' => $e->getMessage()];
            }
        }

        $highFailureDeaths = $this->markHighFailureFeedsAsDead();
        $results['marked_dead'] += $highFailureDeaths['count'];
        $results['dead_feeds'] = array_merge($results['dead_feeds'], $highFailureDeaths['feeds']);

        Log::info('RssFeedHealthService: Self-healing completed', $results);

        return $results;
    }

    /**
     * Mark feeds with 30+ consecutive failures as permanently dead
     */
    public function markHighFailureFeedsAsDead(int $threshold = 30): array
    {
        $result = ['count' => 0, 'feeds' => []];

        $highFailures = DB::select(
            "SELECT id, feed_url, consecutive_failures, last_error_message
             FROM rss_feed_health
             WHERE consecutive_failures >= ? AND permanently_dead = 0 AND redirect_url IS NULL",
            [$threshold]
        );

        foreach ($highFailures as $feed) {
            DB::update("UPDATE rss_feed_health SET permanently_dead = 1 WHERE id = ?", [$feed->id]);
            $result['count']++;
            $result['feeds'][] = [
                'url' => $feed->feed_url,
                'redirect' => null,
                'reason' => "Exceeded {$threshold} consecutive failures: " . ($feed->last_error_message ?? 'Unknown error'),
            ];
            Log::warning('RssFeedHealthService: Feed marked dead due to high failures', [
                'feed_url' => $feed->feed_url,
                'consecutive_failures' => $feed->consecutive_failures,
            ]);
        }

        return $result;
    }

    /**
     * Find potential replacement feeds for dead feeds
     */
    public function findReplacementFeed(string $deadUrl): ?string
    {
        $parsed = parse_url($deadUrl);
        $host = $parsed['host'] ?? '';

        $host = preg_replace('/^(www\.|rss\.|feeds\.|blog\.|news\.)/i', '', $host);

        $patterns = [
            "https://{$host}/feed/",
            "https://{$host}/rss/",
            "https://{$host}/rss.xml",
            "https://{$host}/feed.xml",
            "https://{$host}/atom.xml",
            "https://feeds.{$host}/",
            "https://{$host}/blog/feed/",
            "https://{$host}/news/feed/",
        ];

        foreach ($patterns as $pattern) {
            if ($pattern === $deadUrl) {
                continue;
            }
            $safety = $this->validateFeedUrlSafety($pattern);
            if ($safety['safe']) {
                Log::info('RssFeedHealthService: Found replacement feed', [
                    'dead_url' => $deadUrl,
                    'replacement' => $pattern,
                ]);
                return $pattern;
            }
        }

        return null;
    }
}

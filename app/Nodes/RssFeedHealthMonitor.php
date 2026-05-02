<?php

namespace App\Nodes;

use App\Controllers\NotificationController;
use App\Services\RssFeedHealthService;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * RSS Feed Health Monitor Node
 *
 * Checks health of RSS feeds and optionally sends alerts for failures.
 * Can monitor all workflow feeds or specific feeds provided in configuration.
 *
 * Configuration:
 * - feed_urls: (optional) Array of specific URLs to check, or empty to check all workflow feeds
 * - timeout: (optional) Timeout in seconds (default: 15)
 * - send_alerts: (optional) Send Pushover alerts for failures (default: false)
 * - failure_threshold: (optional) Number of consecutive failures before alerting (default: 3)
 * - include_report: (optional) Include full health report in output (default: true)
 */
class RssFeedHealthMonitor extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $healthService = app(RssFeedHealthService::class);

            // Extract configuration
            $feedUrls = $this->getConfigValue('feed_urls', []);
            $timeout = (int) $this->getConfigValue('timeout', 15);
            $sendAlerts = $this->getConfigValue('send_alerts', false);
            $failureThreshold = (int) $this->getConfigValue('failure_threshold', 3);
            $includeReport = $this->getConfigValue('include_report', true);

            $startTime = microtime(true);

            // If no specific URLs provided, check all workflow feeds
            if (empty($feedUrls)) {
                $feedUrls = $healthService->extractFeedUrlsFromWorkflows();
                Log::info('RssFeedHealthMonitor: Checking all workflow feeds', [
                    'feed_count' => count($feedUrls),
                ]);
            } else {
                Log::info('RssFeedHealthMonitor: Checking specific feeds', [
                    'feed_count' => count($feedUrls),
                    'urls' => $feedUrls,
                ]);
            }

            if (empty($feedUrls)) {
                return $this->standardOutput(
                    ['message' => 'No RSS feeds found to monitor'],
                    ['feeds_checked' => 0],
                    'No RSS feeds configured'
                );
            }

            // Check health of all feeds
            $results = $healthService->checkMultipleFeeds($feedUrls, $timeout);

            // Count successes and failures
            $successCount = count(array_filter($results, fn ($r) => $r['success']));
            $failureCount = count($results) - $successCount;

            // Get health summary
            $summary = $healthService->getHealthSummary();

            // Build output message
            $output = [];
            $output[] = '🏥 RSS Feed Health Check Complete';
            $output[] = '';
            $output[] = 'Feeds Checked: '.count($results);
            $output[] = "✅ Successful: {$successCount}";
            $output[] = "🚨 Failed: {$failureCount}";
            $output[] = '';
            $output[] = 'Overall Health:';
            $output[] = "  Total Tracked: {$summary['total']}";
            $output[] = "  ✅ Healthy: {$summary['healthy']}";
            $output[] = "  ⚠️  Degraded: {$summary['degraded']}";
            $output[] = "  🚨 Failed: {$summary['failed']}";
            $output[] = "  Health Rate: {$summary['health_percentage']}%";

            // Add failed feeds details if any
            if ($failureCount > 0) {
                $output[] = '';
                $output[] = 'Failed Feeds:';
                foreach ($results as $result) {
                    if (! $result['success']) {
                        $feedTitle = $result['feed_title'] ?: parse_url($result['url'], PHP_URL_HOST);
                        $output[] = "  🚨 {$feedTitle}";
                        $output[] = "     URL: {$result['url']}";
                        $output[] = "     Error: {$result['error_message']}";
                    }
                }
            }

            // Check for feeds needing alerts (log only, Pushover alerts disabled)
            $feedsNeedingAlerts = [];
            if ($sendAlerts) {
                $feedsNeedingAlerts = $healthService->getFeedsThatNeedAlerts($failureThreshold);

                if (! empty($feedsNeedingAlerts)) {
                    Log::info('RssFeedHealthMonitor: Failing feeds detected (alerts suppressed)', [
                        'count' => count($feedsNeedingAlerts),
                        'feeds' => array_map(fn ($f) => $f['feed_url'] ?? 'unknown', $feedsNeedingAlerts),
                    ]);
                }
            }

            // Include full health report if requested
            if ($includeReport && $failureCount > 0) {
                $output[] = '';
                $output[] = '--- Full Health Report ---';
                $output[] = '';
                $report = $healthService->generateHealthReport();
                $output[] = $report;
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            $formattedOutput = implode("\n", $output);

            Log::info('RssFeedHealthMonitor: Completed health check', [
                'duration_ms' => $duration,
                'feeds_checked' => count($results),
                'successes' => $successCount,
                'failures' => $failureCount,
                'alerts_sent' => $sendAlerts ? count($feedsNeedingAlerts ?? []) : 0,
            ]);

            return $this->standardOutput(
                [
                    'message' => $formattedOutput,
                    'summary' => $summary,
                    'results' => $results,
                    'failures' => array_filter($results, fn ($r) => ! $r['success']),
                ],
                [
                    'source' => 'RSS Feed Health Monitor',
                    'feeds_checked' => count($results),
                    'successful' => $successCount,
                    'failed' => $failureCount,
                    'health_percentage' => $summary['health_percentage'],
                    'duration_ms' => $duration,
                    'alerts_sent' => $sendAlerts ? count($feedsNeedingAlerts ?? []) : 0,
                    'checked_at' => now()->toISOString(),
                ]
            );

        } catch (Exception $e) {
            Log::error('RssFeedHealthMonitor: Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput(
                ['message' => 'Health check failed'],
                [],
                'RSS Feed Health Monitor error: '.$e->getMessage()
            );
        }
    }

    /**
     * Send Pushover alerts for failing feeds
     */
    private function sendHealthAlerts(array $feedsNeedingAlerts): void
    {
        try {
            $controller = new NotificationController;

            foreach ($feedsNeedingAlerts as $feed) {
                $feedName = $feed['feed_title'] ?: parse_url($feed['feed_url'], PHP_URL_HOST);

                $message = [];
                $message[] = '<b><font color="#EE5A6F">🚨 RSS Feed Failure Alert</font></b>';
                $message[] = '';
                $message[] = "<b>Feed:</b> {$feedName}";
                $message[] = "<b>URL:</b> {$feed['feed_url']}";
                $message[] = '';
                $message[] = "<b>Status:</b> {$feed['status']}";
                $message[] = "<b>Consecutive Failures:</b> {$feed['consecutive_failures']}";
                $message[] = "<b>Success Rate:</b> {$feed['success_rate']}%";
                $message[] = '';

                if ($feed['last_error_message']) {
                    $message[] = "<b>Error:</b> {$feed['last_error_message']}";
                    $message[] = '';
                }

                if ($feed['last_failure_at']) {
                    $lastFailure = \Carbon\Carbon::parse($feed['last_failure_at']);
                    $message[] = "<b>Last Failure:</b> {$lastFailure->format('Y-m-d H:i:s')}";
                    $message[] = "<b>Time Since:</b> {$lastFailure->diffForHumans()}";
                }

                $messageText = implode("\n", $message);

                $controller->send('pushover', [
                    'source_group' => 'workflow_node_notifications',
                    'title' => 'RSS Feed Health Alert',
                    'message' => $messageText,
                    'priority' => 1,
                    'format_type' => 'html',
                ]);

                // Mark alert as sent
                \Illuminate\Support\Facades\DB::update(
                    'UPDATE rss_feed_health SET alert_sent = 1, alert_sent_at = NOW() WHERE feed_url = ?',
                    [$feed['feed_url']]
                );

                Log::info('RssFeedHealthMonitor: Sent alert for failing feed', [
                    'feed_url' => $feed['feed_url'],
                    'consecutive_failures' => $feed['consecutive_failures'],
                ]);
            }

        } catch (Exception $e) {
            Log::error('RssFeedHealthMonitor: Failed to send alerts', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

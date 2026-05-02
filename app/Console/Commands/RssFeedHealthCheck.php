<?php

namespace App\Console\Commands;

use App\Services\RssFeedHealthService;
use Illuminate\Console\Command;

class RssFeedHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rss:health
                            {--url= : Check specific feed URL}
                            {--all : Check all workflow feeds}
                            {--report : Show health report}
                            {--stats : Show statistics only}
                            {--timeout=15 : Timeout in seconds}
                            {--reset : Reset all health records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check RSS feed health and show monitoring status';

    private RssFeedHealthService $healthService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->healthService = app(RssFeedHealthService::class);

        // Handle reset flag
        if ($this->option('reset')) {
            return $this->handleReset();
        }

        // Handle stats flag
        if ($this->option('stats')) {
            return $this->showStats();
        }

        // Handle report flag
        if ($this->option('report')) {
            return $this->showReport();
        }

        // Handle specific URL check
        if ($url = $this->option('url')) {
            return $this->checkSingleFeed($url);
        }

        // Handle all feeds check
        if ($this->option('all')) {
            return $this->checkAllFeeds();
        }

        // Default: show help
        $this->showHelp();
        return 0;
    }

    /**
     * Check a single RSS feed
     */
    private function checkSingleFeed(string $url): int
    {
        $timeout = (int) $this->option('timeout');

        $this->info("Checking RSS feed health...");
        $this->info("URL: {$url}");
        $this->info("Timeout: {$timeout}s");
        $this->newLine();

        $result = $this->healthService->checkFeedHealth($url, $timeout);

        if ($result['success']) {
            $this->info("✅ Feed is healthy!");
            $this->newLine();
            $this->line("Response Time: {$result['response_time_ms']}ms");
            $this->line("Feed Title: {$result['feed_title']}");
            $this->line("Total Items: {$result['total_items']}");
            $this->line("Articles Found: {$result['article_count']}");

            if ($result['feed_last_updated']) {
                $this->line("Last Updated: {$result['feed_last_updated']->format('Y-m-d H:i:s')}");
            }
        } else {
            $this->error("🚨 Feed check failed!");
            $this->newLine();
            $this->line("Error Type: {$result['error_type']}");
            $this->line("Error Message: {$result['error_message']}");
            $this->line("Response Time: {$result['response_time_ms']}ms");
        }

        $this->newLine();
        $this->showHealthRecord($result['health_record']);

        return $result['success'] ? 0 : 1;
    }

    /**
     * Check all feeds from workflows
     */
    private function checkAllFeeds(): int
    {
        $timeout = (int) $this->option('timeout');

        $this->info("Extracting RSS feed URLs from workflows...");
        $feedUrls = $this->healthService->extractFeedUrlsFromWorkflows();

        $this->info("Found " . count($feedUrls) . " RSS feeds");
        $this->newLine();

        if (count($feedUrls) === 0) {
            $this->warn("No RSS feeds found in workflow configurations");
            return 0;
        }

        $this->info("Checking health of all feeds...");
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($feedUrls));
        $progressBar->start();

        $results = [];
        foreach ($feedUrls as $url) {
            $results[] = $this->healthService->checkFeedHealth($url, $timeout);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show summary
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failureCount = count($results) - $successCount;

        $this->info("Check complete!");
        $this->newLine();
        $this->line("Total Feeds: " . count($results));
        $this->line("✅ Successful: {$successCount}");
        $this->line("🚨 Failed: {$failureCount}");
        $this->newLine();

        // Show failed feeds
        if ($failureCount > 0) {
            $this->error("Failed Feeds:");
            foreach ($results as $result) {
                if (!$result['success']) {
                    $this->line("  🚨 {$result['url']}");
                    $this->line("     Error: {$result['error_message']}");
                }
            }
            $this->newLine();
        }

        // Show stats
        $this->showStats();

        return $failureCount > 0 ? 1 : 0;
    }

    /**
     * Show health statistics
     */
    private function showStats(): int
    {
        $summary = $this->healthService->getHealthSummary();

        $this->info("📊 RSS Feed Health Statistics");
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Feeds', $summary['total']],
                ['✅ Healthy', $summary['healthy']],
                ['⚠️  Degraded', $summary['degraded']],
                ['🚨 Failed', $summary['failed']],
                ['❓ Unknown', $summary['unknown']],
                ['Health Rate', $summary['health_percentage'] . '%'],
            ]
        );

        return 0;
    }

    /**
     * Show health report
     */
    private function showReport(): int
    {
        $report = $this->healthService->generateHealthReport();
        $this->line($report);
        return 0;
    }

    /**
     * Show health record details
     */
    private function showHealthRecord(RssFeedHealth $health): void
    {
        $this->info("Health Record:");
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status', $health->status_emoji . ' ' . $health->status],
                ['Total Checks', $health->total_checks],
                ['Total Successes', $health->total_successes],
                ['Total Failures', $health->total_failures],
                ['Success Rate', $health->success_rate . '%'],
                ['Consecutive Failures', $health->consecutive_failures],
                ['Avg Response Time', ($health->avg_response_time_ms ?? 0) . 'ms'],
                ['Last Check', $health->last_check_at?->format('Y-m-d H:i:s') ?? 'Never'],
                ['Last Success', $health->last_success_at?->format('Y-m-d H:i:s') ?? 'Never'],
                ['Last Failure', $health->last_failure_at?->format('Y-m-d H:i:s') ?? 'Never'],
            ]
        );
    }

    /**
     * Reset all health records
     */
    private function handleReset(): int
    {
        if (!$this->confirm('Are you sure you want to reset all RSS feed health records?')) {
            $this->info('Reset cancelled');
            return 0;
        }

        $count = \Illuminate\Support\Facades\DB::selectOne("SELECT COUNT(*) as cnt FROM rss_feed_health")->cnt;
        \Illuminate\Support\Facades\DB::delete("DELETE FROM rss_feed_health");

        $this->info("Reset complete! Deleted {$count} health records.");
        return 0;
    }

    /**
     * Show help information
     */
    private function showHelp(): void
    {
        $this->info("RSS Feed Health Monitor");
        $this->newLine();
        $this->line("Usage:");
        $this->line("  php artisan rss:health --url=<feed-url>  # Check specific feed");
        $this->line("  php artisan rss:health --all             # Check all workflow feeds");
        $this->line("  php artisan rss:health --report          # Show health report");
        $this->line("  php artisan rss:health --stats           # Show statistics");
        $this->line("  php artisan rss:health --reset           # Reset all health records");
        $this->newLine();
        $this->line("Options:");
        $this->line("  --timeout=<seconds>                      # Request timeout (default: 15)");
    }
}

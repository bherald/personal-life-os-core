<?php

namespace App\Console\Commands;

use App\Services\RssFeedHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * RSS Feed Self-Healing Command
 *
 * Automatically detects and corrects failed RSS feeds:
 * - Detects permanent redirects (301)
 * - Validates redirect destinations as valid RSS feeds
 * - Auto-updates workflow configurations
 * - Marks permanently dead feeds
 * - Sends notifications on corrections
 *
 * Safety features:
 * - Validates all new URLs before updating
 * - Checks for valid RSS/Atom XML
 * - Blocks known bad domains
 * - Requires articles to exist in feed
 * - Logs all corrections for audit
 *
 * Usage:
 *   php artisan rss:self-heal              # Run self-healing
 *   php artisan rss:self-heal --dry-run    # Preview without making changes
 *   php artisan rss:self-heal --check-all  # Check all feeds first
 *   php artisan rss:self-heal --notify     # Send Pushover notification on completion
 */
class RssFeedSelfHeal extends Command
{
    protected $signature = 'rss:self-heal
                            {--dry-run : Preview changes without applying them}
                            {--check-all : Run health check on all feeds first}
                            {--notify : Send Pushover notification with results}
                            {--find-replacements : Attempt to find replacements for dead feeds}';

    protected $description = 'Automatically detect and correct failed RSS feeds';

    private RssFeedHealthService $healthService;

    public function __construct(RssFeedHealthService $healthService)
    {
        parent::__construct();
        $this->healthService = $healthService;
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $checkAll = $this->option('check-all');
        $notify = $this->option('notify');
        $findReplacements = $this->option('find-replacements');

        $this->info('RSS Feed Self-Healing Process');
        $this->info('============================');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Step 1: Optionally run health check on all feeds
        if ($checkAll) {
            $this->info("\n[1/4] Running health check on all workflow feeds...");
            $results = $this->healthService->checkAllWorkflowFeeds();
            $this->info("  Checked " . count($results) . " feeds");

            $failed = array_filter($results, fn($r) => !$r['success']);
            $redirected = array_filter($results, fn($r) => $r['redirect_detected'] ?? false);

            $this->info("  - Failed: " . count($failed));
            $this->info("  - Redirected: " . count($redirected));
        } else {
            $this->info("\n[1/4] Skipping health check (use --check-all to enable)");
        }

        // Step 2: Get feeds needing correction
        $this->info("\n[2/4] Finding feeds that need correction...");
        $feedsNeedingCorrection = $this->healthService->getFeedsNeedingCorrection();
        $this->info("  Found " . count($feedsNeedingCorrection) . " feed(s) with redirect URLs");

        if (count($feedsNeedingCorrection) > 0) {
            $this->table(
                ['Feed URL', 'Redirect URL', 'Failures'],
                array_map(fn($f) => [
                    substr($f->feed_url, 0, 50) . (strlen($f->feed_url) > 50 ? '...' : ''),
                    substr($f->redirect_url ?? 'N/A', 0, 50) . (strlen($f->redirect_url ?? '') > 50 ? '...' : ''),
                    $f->consecutive_failures,
                ], $feedsNeedingCorrection)
            );
        }

        // Step 3: Run self-healing
        $this->info("\n[3/4] Running self-healing process...");

        if ($dryRun) {
            // In dry-run mode, just validate without updating
            $results = $this->dryRunSelfHealing($feedsNeedingCorrection);
        } else {
            $results = $this->healthService->runSelfHealing();
        }

        // Display results
        $this->displayResults($results);

        // Step 4: Optionally find replacements for dead feeds
        if ($findReplacements) {
            $this->info("\n[4/4] Searching for replacement feeds...");
            $this->findReplacementsForDeadFeeds($dryRun);
        } else {
            $this->info("\n[4/4] Skipping replacement search (use --find-replacements to enable)");
        }

        // Send notification if requested
        if ($notify && !$dryRun) {
            $this->sendNotification($results);
        }

        // Summary
        $this->info("\n============================");
        $this->info("Self-healing complete!");

        if ($results['auto_corrected'] > 0) {
            $this->info("  Auto-corrected: {$results['auto_corrected']} feed(s)");
        }
        if ($results['marked_dead'] > 0) {
            $this->warn("  Marked dead: {$results['marked_dead']} feed(s)");
        }
        if (count($results['errors'] ?? []) > 0) {
            $this->error("  Errors: " . count($results['errors']));
        }

        return Command::SUCCESS;
    }

    /**
     * Dry-run self-healing (validate without updating)
     */
    private function dryRunSelfHealing(array $feeds): array
    {
        $results = [
            'timestamp' => now()->toIso8601String(),
            'feeds_checked' => count($feeds),
            'auto_corrected' => 0,
            'marked_dead' => 0,
            'skipped' => 0,
            'corrections' => [],
            'dead_feeds' => [],
            'errors' => [],
        ];

        foreach ($feeds as $feed) {
            $this->line("  Validating: {$feed->feed_url}");

            $safety = $this->healthService->validateFeedUrlSafety($feed->redirect_url);

            if ($safety['safe']) {
                $this->info("    → Would auto-correct to: {$feed->redirect_url}");
                $this->info("      Safety checks: " . json_encode($safety['checks']));
                $results['auto_corrected']++;
                $results['corrections'][] = [
                    'old_url' => $feed->feed_url,
                    'new_url' => $feed->redirect_url,
                    'would_update' => true,
                ];
            } else {
                $this->warn("    → Would mark as dead: {$safety['reason']}");
                $results['marked_dead']++;
                $results['dead_feeds'][] = [
                    'url' => $feed->feed_url,
                    'redirect' => $feed->redirect_url,
                    'reason' => $safety['reason'],
                ];
            }
        }

        return $results;
    }

    /**
     * Display self-healing results
     */
    private function displayResults(array $results): void
    {
        if (count($results['corrections']) > 0) {
            $this->info("\n  Auto-corrections applied:");
            foreach ($results['corrections'] as $correction) {
                $this->info("    ✓ {$correction['old_url']}");
                $this->info("      → {$correction['new_url']}");
            }
        }

        if (count($results['dead_feeds']) > 0) {
            $this->warn("\n  Feeds marked as permanently dead:");
            foreach ($results['dead_feeds'] as $dead) {
                $this->warn("    ✗ {$dead['url']}");
                $this->warn("      Reason: {$dead['reason']}");
            }
        }

        if (count($results['errors']) > 0) {
            $this->error("\n  Errors encountered:");
            foreach ($results['errors'] as $error) {
                $this->error("    ! {$error['url']}");
                $errorMsg = $error['reason'] ?? ($error['error'] ?? 'Unknown error');
                $this->error("      {$errorMsg}");
            }
        }
    }

    /**
     * Find replacements for dead feeds
     */
    private function findReplacementsForDeadFeeds(bool $dryRun): void
    {
        $deadFeeds = $this->healthService->getDeadFeeds();

        if (count($deadFeeds) === 0) {
            $this->info("  No dead feeds to find replacements for");
            return;
        }

        $this->info("  Searching for replacements for " . count($deadFeeds) . " dead feed(s)...");

        foreach ($deadFeeds as $feed) {
            $this->line("  Checking: {$feed->feed_url}");

            $replacement = $this->healthService->findReplacementFeed($feed->feed_url);

            if ($replacement) {
                $this->info("    → Found replacement: {$replacement}");

                if (!$dryRun) {
                    // Update suggested_replacement in health record
                    \DB::update(
                        "UPDATE rss_feed_health SET suggested_replacement = ? WHERE id = ?",
                        [$replacement, $feed->id]
                    );
                }
            } else {
                $this->warn("    → No automatic replacement found");
            }
        }
    }

    /**
     * Send Pushover notification with results
     */
    private function sendNotification(array $results): void
    {
        $corrected = $results['auto_corrected'];
        $dead = $results['marked_dead'];
        $errors = count($results['errors']);

        if ($corrected === 0 && $dead === 0 && $errors === 0) {
            $this->info("  No changes to notify about");
            return;
        }

        $message = "RSS Feed Self-Healing Results:\n\n";

        if ($corrected > 0) {
            $message .= "✓ Auto-corrected: {$corrected} feed(s)\n";
            foreach (array_slice($results['corrections'], 0, 3) as $c) {
                $message .= "  • " . parse_url($c['old_url'], PHP_URL_HOST) . "\n";
            }
            if (count($results['corrections']) > 3) {
                $message .= "  • ... and " . (count($results['corrections']) - 3) . " more\n";
            }
        }

        if ($dead > 0) {
            $message .= "\n✗ Marked dead: {$dead} feed(s)\n";
        }

        if ($errors > 0) {
            $message .= "\n⚠ Errors: {$errors}\n";
        }

        // RSS self-healing results logged and covered by daily report
        Log::info('RSS self-healing completed', ['summary' => $message]);
        $this->info("  Results logged (Pushover suppressed)");
    }
}

<?php

namespace App\Console\Commands;

use App\Services\DataRemoval\BrokerHealthService;
use App\Services\DataRemoval\BrokerListSyncService;
use App\Services\DataRemoval\RelistingDetectionService;
use App\Services\DataRemoval\EffectivenessDashboardService;
use Illuminate\Console\Command;

/**
 * Data Removal Tools Command
 *
 * Usage:
 *   php artisan data-removal:tools --health-check
 *   php artisan data-removal:tools --sync-badbool
 *   php artisan data-removal:tools --relisting-scan --subject=1
 *   php artisan data-removal:tools --breach-scan --subject=1
 *   php artisan data-removal:tools --effectiveness
 *   php artisan data-removal:tools --proof-stats
 */
class DataRemovalToolsCommand extends Command
{
    protected $signature = 'data-removal:tools
        {--health-check : Run health checks on data broker opt-out pages}
        {--sync-badbool : Review or sync the Eraser broker source (legacy flag name)}
        {--relisting-scan : Scan for data broker relistings}
        {--breach-scan : Scan subjects for data breaches via HIBP API}
        {--effectiveness : Generate effectiveness report}
        {--proof-stats : Show proof archive statistics}
        {--subject= : Data subject ID for relisting/breach scan}
        {--broker= : Specific broker ID}
        {--dry-run : Fetch and compare the source without changes}
        {--broken-only : Limit source comparison or repair to active broken brokers}
        {--confirm : Confirm source-backed database writes}';

    protected $description = 'Data removal tools: health checks, Eraser source review, relisting detection, effectiveness reporting';

    public function handle(): int
    {
        if ($this->option('health-check')) {
            return $this->healthCheck();
        }
        if ($this->option('sync-badbool')) {
            return $this->syncBadbool();
        }
        if ($this->option('relisting-scan')) {
            return $this->relistingScan();
        }
        if ($this->option('effectiveness')) {
            return $this->effectiveness();
        }

        $this->info('Usage: data-removal:tools --health-check|--sync-badbool|--relisting-scan|--effectiveness');
        return self::SUCCESS;
    }

    private function healthCheck(): int
    {
        $service = app(BrokerHealthService::class);
        $brokerId = $this->option('broker');

        if ($brokerId) {
            $this->info("Checking broker {$brokerId}...");
            $result = $service->checkBrokerHealth((int) $brokerId);
            $httpCode = $result['http_code'] ?? 'N/A';
            $responseTime = $result['response_time_ms'] ?? 0;
            $this->line("  Status: {$result['status']}, HTTP: {$httpCode}, Time: {$responseTime}ms");
        } else {
            $this->info('Running batch health check...');
            $result = $service->batchHealthCheck();
            $this->info("Checked: {$result['checked']}, Healthy: {$result['healthy']}, Degraded: {$result['degraded']}, Broken: {$result['broken']}, Changed: {$result['changed']}");
        }

        // Show unhealthy brokers
        $unhealthy = $service->getUnhealthyBrokers();
        if (!empty($unhealthy)) {
            $this->warn(count($unhealthy) . ' unhealthy brokers:');
            $rows = array_map(fn($b) => [$b->id, $b->name, $b->domain, $b->health_status, $b->last_health_check], $unhealthy);
            $this->table(['ID', 'Name', 'Domain', 'Status', 'Last Check'], $rows);
        }

        return self::SUCCESS;
    }

    private function syncBadbool(): int
    {
        $service = app(BrokerListSyncService::class);
        $brokenOnly = (bool) $this->option('broken-only');

        if ($this->option('dry-run')) {
            $preview = $service->previewSync($brokenOnly);
            if (($preview['success'] ?? false) !== true) {
                $this->error('Source preview failed: '.($preview['error'] ?? 'unknown error'));

                return self::FAILURE;
            }

            $summary = $preview['summary'];
            $this->info(sprintf(
                'Source: %s (%s), rows: %d, domains: %d, matched: %d, unmatched: %d, URL changes: %d',
                $preview['source']['repository'],
                $preview['source']['license'],
                $summary['source_rows'],
                $summary['source_domains'],
                $summary['matched'],
                $summary['unmatched'],
                $summary['url_changes']
            ));
            if ($preview['url_changes'] !== []) {
                $this->table(
                    ['Broker ID', 'Domain', 'Current URL', 'Source URL'],
                    array_map(static fn (array $change): array => [
                        $change['broker_id'],
                        $change['domain'],
                        $change['current_url'],
                        $change['source_url'],
                    ], $preview['url_changes'])
                );
            }
            if ($preview['unmatched_brokers'] !== []) {
                $this->table(
                    ['Broker ID', 'Domain', 'Current URL', 'Health'],
                    array_map(static fn (array $broker): array => [
                        $broker['broker_id'],
                        $broker['domain'],
                        $broker['current_url'],
                        $broker['health_status'],
                    ], $preview['unmatched_brokers'])
                );
            }
            $this->warn('Dry run: no database writes performed.');

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->error('Source sync requires --confirm. Use --dry-run first.');

            return self::FAILURE;
        }

        if ($brokenOnly) {
            $result = $service->repairBrokenUrls(true);
            if (($result['success'] ?? false) !== true) {
                $this->error('Broken URL repair failed: '.($result['error'] ?? 'unknown error'));

                return self::FAILURE;
            }

            $this->info("Source-backed broken URL repairs applied: {$result['updated']} of {$result['previewed']} previewed.");

            return self::SUCCESS;
        }

        $this->info('Syncing broker list from the configured Eraser source...');
        $result = $service->syncBADBOOL();

        if (($result['skipped'] ?? false) === true) {
            $this->warn("Skipped: " . ($result['reason'] ?? 'source disabled'));
        } elseif ($result['success']) {
            $this->info("Total: {$result['total']}, New: {$result['new']}, Updated: {$result['updated']}, Skipped: {$result['skipped']}");
        } else {
            $this->error("Sync failed: {$result['error']}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function relistingScan(): int
    {
        $service = app(RelistingDetectionService::class);
        $subjectId = $this->option('subject');

        if (!$subjectId) {
            // Show relisting report
            $report = $service->getRelistingReport();
            $this->info("Total relistings: {$report['total_relistings']} across {$report['brokers_relisting']} brokers");
            if (!empty($report['by_broker'])) {
                $rows = array_map(fn($b) => [$b->broker_name, $b->domain, $b->relisting_count, $b->last_relisting], $report['by_broker']);
                $this->table(['Broker', 'Domain', 'Relistings', 'Last'], $rows);
            }
            return self::SUCCESS;
        }

        $this->info("Scanning for relistings for subject {$subjectId}...");
        $result = $service->scanForRelisting((int) $subjectId);

        $this->info("Scanned: {$result['scanned']} completed removals");
        if (!empty($result['details'])) {
            foreach ($result['details'] as $detail) {
                $this->warn("  Needs verification: {$detail['broker']} ({$detail['domain']}) - completed {$detail['completed_at']}");
            }
        }

        return self::SUCCESS;
    }

    private function effectiveness(): int
    {
        $service = app(EffectivenessDashboardService::class);
        $report = $service->generateReport();

        $this->info("Effectiveness Report ({$report['period']['start']} to {$report['period']['end']}):");
        $this->line("  Total requests: {$report['overall']['total_requests']}");
        $this->line("  Confirmed: {$report['overall']['confirmed']}");
        $this->line("  Failed: {$report['overall']['failed']}");
        $this->line("  Pending: {$report['overall']['pending']}");
        $this->line("  Success rate: {$report['overall']['success_rate']}%");
        $this->line("  Avg days to removal: {$report['overall']['avg_days_to_removal']}");

        $this->newLine();
        $this->info('Trends (last 6 months):');
        $trends = $service->getTrends();
        $rows = array_map(fn($t) => [$t->month, $t->total_requests, $t->confirmed, $t->success_rate . '%'], $trends);
        $this->table(['Month', 'Requests', 'Confirmed', 'Success Rate'], $rows);

        return self::SUCCESS;
    }

}

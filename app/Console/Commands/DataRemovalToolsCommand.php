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
        {--sync-badbool : Sync broker list from BADBOOL GitHub repository}
        {--relisting-scan : Scan for data broker relistings}
        {--breach-scan : Scan subjects for data breaches via HIBP API}
        {--effectiveness : Generate effectiveness report}
        {--proof-stats : Show proof archive statistics}
        {--subject= : Data subject ID for relisting/breach scan}
        {--broker= : Specific broker ID}
        {--dry-run : Preview without changes}';

    protected $description = 'Data removal tools: health checks, BADBOOL sync, relisting detection, effectiveness reporting';

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

        if ($this->option('dry-run')) {
            $status = $service->getSyncStatus();
            $this->info("Current: {$status['total_brokers']} brokers, {$status['badbool_brokers']} from BADBOOL");
            $this->info("Last sync: " . ($status['last_sync'] ?? 'never'));
            $this->warn('Dry run - no sync performed.');
            return self::SUCCESS;
        }

        $this->info('Syncing broker list from BADBOOL...');
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

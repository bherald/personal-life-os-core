<?php

namespace App\Console\Commands;

use App\Services\ProactiveAlertService;
use Illuminate\Console\Command;

class AlertsCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:check
                            {--run : Run all alert checks}
                            {--active : Show active alerts}
                            {--stats : Show alert statistics}
                            {--period=24 hours : Time period for statistics}
                            {--severity= : Filter by severity (info, warning, error, critical)}
                            {--acknowledge=* : Acknowledge alert by ID}
                            {--resolve=* : Resolve alert by ID}
                            {--cleanup : Clean up old resolved alerts}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check system alerts and run proactive monitoring';

    private ProactiveAlertService $alertService;

    public function __construct(ProactiveAlertService $alertService)
    {
        parent::__construct();
        $this->alertService = $alertService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Handle acknowledgement
        if ($acknowledgeIds = $this->option('acknowledge')) {
            return $this->acknowledgeAlerts($acknowledgeIds);
        }

        // Handle resolution
        if ($resolveIds = $this->option('resolve')) {
            return $this->resolveAlerts($resolveIds);
        }

        // Handle cleanup
        if ($this->option('cleanup')) {
            return $this->cleanupOldAlerts();
        }

        // Show statistics
        if ($this->option('stats')) {
            return $this->showStatistics($this->option('period'));
        }

        // Run all checks
        if ($this->option('run')) {
            return $this->runAllChecks();
        }

        // Default: show active alerts
        return $this->showActiveAlerts($this->option('severity'));
    }

    /**
     * Run all alert checks
     */
    private function runAllChecks(): int
    {
        $this->info('🔍 Running System Alert Checks...');
        $this->newLine();

        $results = $this->alertService->runAllChecks();

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->line("Total Alerts Generated: <fg=yellow>{$results['total_alerts']}</>");
        $this->line("Error Rate Alerts: {$results['error_rate_alerts']}");
        $this->line("Workflow Alerts: {$results['workflow_alerts']}");
        $this->line("System Alerts: {$results['system_alerts']}");
        $this->newLine();

        if ($results['total_alerts'] > 0) {
            $this->warn("⚠️  {$results['total_alerts']} new alert(s) generated");
            $this->info('Run with --active to view active alerts');
        } else {
            $this->info('✅ No new alerts');
        }

        return self::SUCCESS;
    }

    /**
     * Show active alerts
     */
    private function showActiveAlerts(?string $severity): int
    {
        $this->info('🚨 Active Alerts');
        $this->newLine();

        $alerts = $this->alertService->getActiveAlerts($severity);

        if (empty($alerts)) {
            $this->info('✅ No active alerts');
            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($alerts, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $headers = ['ID', 'Type', 'Severity', 'Title', 'Triggered', 'Occurrences'];
        $rows = [];

        foreach ($alerts as $alert) {
            $emoji = match ($alert->severity) {
                'critical' => '🚨',
                'error' => '❌',
                'warning' => '⚠️',
                'info' => 'ℹ️',
                default => '•'
            };

            $rows[] = [
                $alert->id,
                $alert->alert_type,
                $emoji . ' ' . $alert->severity,
                substr($alert->title, 0, 40),
                $alert->triggered_at,
                $alert->occurrence_count
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();
        $this->line("Total Active Alerts: <fg=yellow>" . count($alerts) . "</>");

        return self::FAILURE;
    }

    /**
     * Show alert statistics
     */
    private function showStatistics(string $period): int
    {
        $stats = $this->alertService->getAlertStatistics($period);

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("📊 Alert Statistics ($period)");
        $this->newLine();

        $this->line("Total Alerts: {$stats['total_alerts']}");
        $this->line("Active: <fg=yellow>{$stats['active_alerts']}</>");
        $this->line("Resolved: <fg=green>{$stats['resolved_alerts']}</>");
        $this->newLine();

        // Severity distribution
        if (!empty($stats['by_severity'])) {
            $this->info('By Severity:');
            foreach ($stats['by_severity'] as $severity => $count) {
                $emoji = match ($severity) {
                    'critical' => '🚨',
                    'error' => '❌',
                    'warning' => '⚠️',
                    'info' => 'ℹ️',
                    default => '•'
                };
                $this->line("  $emoji " . ucfirst($severity) . ": $count");
            }
            $this->newLine();
        }

        // Type distribution
        if (!empty($stats['by_type'])) {
            $this->info('Top Alert Types:');
            $headers = ['Alert Type', 'Count'];
            $rows = [];

            foreach ($stats['by_type'] as $type => $count) {
                $rows[] = [$type, $count];
            }

            $this->table($headers, $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Acknowledge alerts
     */
    private function acknowledgeAlerts(array $alertIds): int
    {
        $this->info('Acknowledging alerts...');
        $acknowledged = 0;

        foreach ($alertIds as $alertId) {
            if ($this->alertService->acknowledgeAlert((int) $alertId, 'cli')) {
                $acknowledged++;
                $this->line("  ✅ Alert #$alertId acknowledged");
            } else {
                $this->error("  ❌ Failed to acknowledge alert #$alertId");
            }
        }

        $this->newLine();
        $this->info("Acknowledged: $acknowledged/" . count($alertIds));

        return self::SUCCESS;
    }

    /**
     * Resolve alerts
     */
    private function resolveAlerts(array $alertIds): int
    {
        $this->info('Resolving alerts...');
        $resolved = 0;

        foreach ($alertIds as $alertId) {
            if ($this->alertService->resolveAlert((int) $alertId, 'cli', 'Resolved via CLI')) {
                $resolved++;
                $this->line("  ✅ Alert #$alertId resolved");
            } else {
                $this->error("  ❌ Failed to resolve alert #$alertId");
            }
        }

        $this->newLine();
        $this->info("Resolved: $resolved/" . count($alertIds));

        return self::SUCCESS;
    }

    /**
     * Clean up old resolved alerts
     */
    private function cleanupOldAlerts(): int
    {
        $this->info('🧹 Cleaning up old resolved alerts...');

        $deleted = $this->alertService->cleanupOldAlerts(30);

        $this->info("Deleted $deleted old alert(s)");

        return self::SUCCESS;
    }
}

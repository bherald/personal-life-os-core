<?php

namespace App\Console\Commands;

use App\Services\WorkflowDiagnosticsService;
use Illuminate\Console\Command;

class WorkflowDiagnosticsReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflow:diagnostics
                            {workflow? : Workflow ID to analyze}
                            {--all : Analyze all workflows}
                            {--failing : Show only failing workflows}
                            {--update : Update diagnostics for all workflows}
                            {--period=7 days : Analysis period}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze workflow health and performance';

    private WorkflowDiagnosticsService $diagnostics;

    public function __construct(WorkflowDiagnosticsService $diagnostics)
    {
        parent::__construct();
        $this->diagnostics = $diagnostics;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $period = $this->option('period');

        // Update all diagnostics if requested
        if ($this->option('update')) {
            return $this->updateAllDiagnostics($period);
        }

        // Show health summary
        if ($this->option('all')) {
            return $this->showHealthSummary();
        }

        // Show failing workflows
        if ($this->option('failing')) {
            return $this->showFailingWorkflows();
        }

        // Analyze specific workflow
        $workflowId = $this->argument('workflow');
        if ($workflowId) {
            return $this->analyzeWorkflow((int) $workflowId, $period);
        }

        // Default: show health summary
        return $this->showHealthSummary();
    }

    /**
     * Show workflow health summary
     */
    private function showHealthSummary(): int
    {
        $summary = $this->diagnostics->getHealthSummary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('📊 Workflow Health Summary');
        $this->newLine();

        $this->line("Total Workflows: <fg=cyan>{$summary['total_workflows']}</>");
        $this->line("✅ Healthy: <fg=green>{$summary['healthy_count']}</>");
        $this->line("⚠️  Degraded: <fg=yellow>{$summary['degraded_count']}</>");
        $this->line("🔴 Failing: <fg=red>{$summary['failing_count']}</>");
        $this->line("🚨 Critical: <fg=red;options=bold>{$summary['critical_count']}</>");
        $this->line("Average Success Rate: <fg=cyan>{$summary['avg_success_rate']}%</>");
        $this->newLine();

        if ($summary['failing_count'] > 0 || $summary['critical_count'] > 0) {
            $this->warn('⚠️  Some workflows need attention. Run with --failing to see details.');
        }

        return self::SUCCESS;
    }

    /**
     * Show failing workflows
     */
    private function showFailingWorkflows(): int
    {
        $failing = $this->diagnostics->getFailingWorkflows('degraded');

        if (empty($failing)) {
            $this->info('✅ All workflows are healthy!');
            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($failing, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->error("⚠️  " . count($failing) . " Workflows Need Attention");
        $this->newLine();

        $headers = ['ID', 'Status', 'Success Rate', 'Failed Runs', 'Consecutive Failures', 'Last Failure'];
        $rows = [];

        foreach ($failing as $workflow) {
            $emoji = match ($workflow->health_status) {
                'healthy' => '✅',
                'degraded' => '⚠️',
                'failing' => '🔴',
                'critical' => '🚨',
                default => '❓'
            };

            $rows[] = [
                $workflow->workflow_id,
                $emoji . ' ' . $workflow->health_status,
                number_format($workflow->success_rate, 2) . '%',
                $workflow->failed_runs,
                $workflow->consecutive_failures,
                $workflow->last_failure_at ?? 'N/A',
            ];
        }

        $this->table($headers, $rows);

        return self::FAILURE;
    }

    /**
     * Analyze specific workflow
     */
    private function analyzeWorkflow(int $workflowId, string $period): int
    {
        $this->info("🔍 Analyzing Workflow #$workflowId");
        $this->newLine();

        $analysis = $this->diagnostics->analyzeWorkflow($workflowId, $period);

        if ($this->option('json')) {
            $this->line(json_encode($analysis, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Display overview
        $statusEmoji = match ($analysis['health_status']) {
            'healthy' => '✅',
            'degraded' => '⚠️',
            'failing' => '🔴',
            'critical' => '🚨',
            default => '❓'
        };

        $this->line("Health Status: $statusEmoji <fg=bold>{$analysis['health_status']}</>");
        $this->line("Period: {$analysis['period']}");
        $this->newLine();

        // Performance metrics
        $this->info('Performance Metrics:');
        $this->line("  Total Runs: {$analysis['total_runs']}");
        $this->line("  ✅ Successful: {$analysis['successful_runs']}");
        $this->line("  ❌ Failed: {$analysis['failed_runs']}");
        $this->line("  Success Rate: <fg=cyan>{$analysis['success_rate']}%</>");
        $this->line("  Average Duration: {$analysis['avg_duration_ms']} ms");
        $this->newLine();

        // Failure info
        if ($analysis['failed_runs'] > 0) {
            $this->info('Failure Information:');
            $this->line("  Consecutive Failures: {$analysis['consecutive_failures']}");
            if ($analysis['last_failure_at']) {
                $this->line("  Last Failure: {$analysis['last_failure_at']}");
            }
            $this->newLine();
        }

        // Error patterns
        if (!empty($analysis['error_patterns']['frequency'])) {
            $this->info('Error Patterns:');
            $headers = ['Error Type', 'Count'];
            $rows = [];

            foreach ($analysis['error_patterns']['frequency'] as $error) {
                $rows[] = [$error['type'], $error['count']];
            }

            $this->table($headers, $rows);
            $this->newLine();
        }

        // Node failures
        if (!empty($analysis['node_failures'])) {
            $this->info('Node Failures:');
            $headers = ['Node ID', 'Node Type', 'Failure Count'];
            $rows = [];

            foreach ($analysis['node_failures'] as $node) {
                $rows[] = [
                    $node['node_id'],
                    $node['node_type'],
                    $node['failure_count']
                ];
            }

            $this->table($headers, $rows);
            $this->newLine();
        }

        // Recommendations
        if (!empty($analysis['recommendations'])) {
            $this->info('Recommendations:');
            foreach ($analysis['recommendations'] as $i => $rec) {
                $emoji = match ($rec['severity']) {
                    'critical' => '🚨',
                    'high' => '🔴',
                    'medium' => '⚠️',
                    default => 'ℹ️'
                };

                $this->line("  " . ($i + 1) . ". $emoji [{$rec['severity']}] {$rec['issue']}");
                $this->line("     → {$rec['recommendation']}");
                if ($rec['occurrences'] !== null) {
                    $this->line("     Occurrences: {$rec['occurrences']}");
                }
            }
        }

        return $analysis['health_status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Update all workflow diagnostics
     */
    private function updateAllDiagnostics(string $period): int
    {
        $this->info("🔄 Updating diagnostics for all workflows...");
        $this->newLine();

        $results = $this->diagnostics->updateAllDiagnostics($period);

        $this->line("Total Workflows: {$results['total_workflows']}");
        $this->line("✅ Updated: <fg=green>{$results['updated']}</>");
        $this->line("❌ Failed: <fg=red>{$results['failed']}</>");

        return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\WorkflowTemplateService;
use App\Services\WorkflowApprovalService;
use App\Services\WorkflowDryRunService;
use App\Services\WorkflowMetricsService;

use Illuminate\Console\Command;

/**
 * Workflow Tools Command
 *
 * Usage:
 *   php artisan workflow:tools --templates
 *   php artisan workflow:tools --approvals
 *   php artisan workflow:tools --dry-run --workflow=1
 *   php artisan workflow:tools --metrics --workflow=1
 *   php artisan workflow:tools --create-samples
 */
class WorkflowToolsCommand extends Command
{
    protected $signature = 'workflow:tools
        {--templates : List workflow templates}
        {--approvals : Show pending approval gates}
        {--dry-run : Dry run a workflow}
        {--metrics : Show workflow metrics}
        {--create-samples : Create sample templates}
        {--workflow= : Workflow ID}
        {--run= : Workflow run ID}';

    protected $description = 'Workflow tools: templates, approvals, dry-run, metrics, samples';

    public function handle(): int
    {
        if ($this->option('templates')) {
            return $this->listTemplates();
        }
        if ($this->option('approvals')) {
            return $this->showApprovals();
        }
        if ($this->option('dry-run')) {
            return $this->dryRun();
        }
        if ($this->option('metrics')) {
            return $this->showMetrics();
        }
        if ($this->option('create-samples')) {
            return $this->createSamples();
        }
        $this->info('Usage: workflow:tools --templates|--approvals|--dry-run|--metrics|--create-samples');
        return self::SUCCESS;
    }

    private function listTemplates(): int
    {
        $service = app(WorkflowTemplateService::class);
        $templates = $service->listTemplates();

        if (empty($templates)) {
            $this->info('No templates found. Use --create-samples to create some.');
            return self::SUCCESS;
        }

        $rows = array_map(fn($t) => [$t->id, $t->name, $t->category ?? '-', $t->usage_count, $t->created_at], $templates);
        $this->table(['ID', 'Name', 'Category', 'Uses', 'Created'], $rows);
        return self::SUCCESS;
    }

    private function showApprovals(): int
    {
        $service = app(WorkflowApprovalService::class);

        // Check for expired first
        $expired = $service->checkExpired();
        if ($expired > 0) {
            $this->warn("{$expired} approval(s) auto-expired.");
        }

        $pending = $service->getPendingApprovals();
        if (empty($pending)) {
            $this->info('No pending approvals.');
            return self::SUCCESS;
        }

        $rows = array_map(fn($a) => [
            $a->id,
            $a->workflow_name ?? 'Unknown',
            $a->workflow_run_id,
            $a->timeout_minutes . ' min',
            $a->requested_at,
        ], $pending);

        $this->table(['Gate ID', 'Workflow', 'Run ID', 'Timeout', 'Requested'], $rows);
        return self::SUCCESS;
    }

    private function dryRun(): int
    {
        $workflowId = $this->option('workflow');
        if (!$workflowId) {
            $this->error('--workflow is required');
            return self::FAILURE;
        }

        $service = app(WorkflowDryRunService::class);

        $this->info("Dry running workflow {$workflowId}...");
        $result = $service->dryRun((int) $workflowId);

        if (!$result['success']) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $this->info("Workflow: {$result['workflow']} ({$result['node_count']} nodes)");

        foreach ($result['nodes'] as $node) {
            $status = $node['config_valid'] ? 'OK' : 'WARN';
            $this->line("  [{$status}] {$node['node_type']} (order: {$node['sort_order']})");
            foreach ($node['warnings'] ?? [] as $warning) {
                $this->warn("    ! {$warning}");
            }
        }

        if (!empty($result['warnings'])) {
            $this->warn(count($result['warnings']) . ' warning(s) found.');
        }

        return self::SUCCESS;
    }

    private function showMetrics(): int
    {
        $workflowId = $this->option('workflow');
        $runId = $this->option('run');

        $service = app(WorkflowMetricsService::class);

        if ($runId) {
            $metrics = $service->getRunMetrics((int) $runId);
            $this->info("Metrics for run #{$runId}: " . count($metrics) . ' entries');
            $rows = array_map(fn($m) => [$m->node_type, $m->metric_name, round($m->metric_value, 2), $m->unit, $m->recorded_at], $metrics);
            $this->table(['Node Type', 'Metric', 'Value', 'Unit', 'Recorded'], $rows);
        } elseif ($workflowId) {
            $stats = $service->getWorkflowStats((int) $workflowId);
            $this->info("Workflow #{$workflowId} Stats:");
            $this->line("  Total runs: {$stats['total_runs']}");
            $this->line("  Completed: {$stats['completed']}, Failed: {$stats['failed']}");
            $this->line("  Success rate: {$stats['success_rate']}%");
            $this->line("  Avg duration: {$stats['avg_duration_sec']}s");
        } else {
            // Show slow nodes
            $slow = $service->getSlowNodes();
            if (empty($slow)) {
                $this->info('No slow nodes detected.');
            } else {
                $rows = array_map(fn($s) => [$s->node_type, $s->occurrences, round($s->avg_ms, 1) . 'ms', round($s->max_ms, 1) . 'ms'], $slow);
                $this->table(['Node Type', 'Occurrences', 'Avg Time', 'Max Time'], $rows);
            }
        }

        return self::SUCCESS;
    }

    private function createSamples(): int
    {
        $service = app(WorkflowTemplateService::class);
        $result = $service->createSampleTemplates();
        $this->info("Created {$result['created']} of {$result['total_samples']} sample templates.");
        return self::SUCCESS;
    }

}

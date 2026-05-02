<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ScheduledJobService;
use Carbon\Carbon;

/**
 * SchedulerListCommand - List and manage scheduled jobs from CLI
 */
class SchedulerListCommand extends Command
{
    protected $signature = 'scheduler:list
                            {--module= : Filter by source module}
                            {--status= : Filter by status (enabled, disabled, failed, running)}
                            {--stats : Show statistics only}';

    protected $description = 'List all scheduled jobs';

    private ScheduledJobService $scheduledJobService;

    public function __construct(ScheduledJobService $scheduledJobService)
    {
        parent::__construct();
        $this->scheduledJobService = $scheduledJobService;
    }

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $jobs = $this->scheduledJobService->getAllJobs();

        // Apply filters
        if ($module = $this->option('module')) {
            $jobs = array_filter($jobs, fn($j) => stripos($j->source_module ?? '', $module) !== false);
        }

        if ($status = $this->option('status')) {
            $jobs = match ($status) {
                'enabled' => array_filter($jobs, fn($j) => $j->enabled),
                'disabled' => array_filter($jobs, fn($j) => !$j->enabled),
                'failed' => array_filter($jobs, fn($j) => $j->last_run_status === 'failed'),
                'running' => array_filter($jobs, fn($j) => $j->last_run_status === 'running'),
                default => $jobs,
            };
        }

        if (empty($jobs)) {
            $this->info('No scheduled jobs found.');
            return 0;
        }

        // Group by module
        $grouped = [];
        foreach ($jobs as $job) {
            $module = $job->source_module ?? 'Uncategorized';
            $grouped[$module][] = $job;
        }
        ksort($grouped);

        foreach ($grouped as $module => $moduleJobs) {
            $this->newLine();
            $this->line("<fg=cyan;options=bold>═══ {$module} ═══</>");

            $headers = ['ID', 'Name', 'Schedule', 'Enabled', 'Last Run', 'Status', 'Next Run'];
            $rows = [];

            foreach ($moduleJobs as $job) {
                $lastRun = $job->last_run_at
                    ? Carbon::parse($job->last_run_at)->diffForHumans()
                    : 'Never';

                $nextRun = $job->next_run_at
                    ? Carbon::parse($job->next_run_at)->diffForHumans()
                    : '-';

                $statusIcon = match ($job->last_run_status) {
                    'success' => '<fg=green>✓</>',
                    'failed' => '<fg=red>✗</>',
                    'running' => '<fg=yellow>⟳</>',
                    'timeout' => '<fg=red>⏱</>',
                    default => '-',
                };

                $enabledIcon = $job->enabled ? '<fg=green>●</>' : '<fg=gray>○</>';

                $rows[] = [
                    $job->id,
                    $job->name,
                    $this->scheduledJobService->describeCron($job->cron_expression),
                    $enabledIcon,
                    $lastRun,
                    $statusIcon,
                    $nextRun,
                ];
            }

            $this->table($headers, $rows);
        }

        return 0;
    }

    /**
     * Show statistics
     */
    private function showStats(): int
    {
        $stats = $this->scheduledJobService->getStats();

        $this->newLine();
        $this->line('<fg=cyan;options=bold>═══ Scheduler Statistics ═══</>');
        $this->newLine();

        $this->line(sprintf('Total Jobs:      %d', $stats['total_jobs']));
        $this->line(sprintf('Enabled:         <fg=green>%d</>', $stats['enabled_jobs']));
        $this->line(sprintf('Disabled:        <fg=gray>%d</>', $stats['disabled_jobs']));
        $this->line(sprintf('Actionable Running: <fg=yellow>%d</>', $stats['running_jobs']));
        $this->line(sprintf('Failed (last):   <fg=red>%d</>', $stats['failed_jobs']));
        $this->newLine();
        $this->line(sprintf('Total Runs:      %d', $stats['total_runs']));
        $this->line(sprintf('Total Failures:  %d', $stats['total_failures']));
        $this->line(sprintf('Runs (24h):      %d', $stats['runs_last_24h']));

        if (!empty($stats['by_module'])) {
            $this->newLine();
            $this->line('<fg=cyan;options=bold>By Module:</>');
            foreach ($stats['by_module'] as $module) {
                $this->line(sprintf(
                    '  %s: %d jobs (%d enabled)',
                    $module->module,
                    $module->job_count,
                    $module->enabled_count
                ));
            }
        }

        return 0;
    }
}

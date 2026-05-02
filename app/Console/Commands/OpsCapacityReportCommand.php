<?php

namespace App\Console\Commands;

use App\Services\Ops\CapacityReportService;
use Illuminate\Console\Command;

class OpsCapacityReportCommand extends Command
{
    protected $signature = 'ops:capacity-report {--json : Emit machine-readable JSON}';

    protected $description = 'Summarize host baseline captures and report observe-only capacity readiness';

    public function handle(CapacityReportService $reports): int
    {
        $report = $reports->buildReport();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Capacity report: '.$report['status']);
        $this->line('Enforcement ready: '.($report['enforcement_ready'] ? 'yes' : 'no'));

        foreach ($report['scenarios'] as $scenario => $summary) {
            $this->line(sprintf(
                '%s: captures=%d latest=%s heavy_window=%s queue_total=%d running_jobs=%d',
                $scenario,
                $summary['captures'],
                $summary['latest_captured_at'] ?? '-',
                $summary['heavy_window_captures'] > 0 ? (string) $summary['heavy_window_captures'] : 'no',
                $summary['latest_metrics']['queue_depth_total'] ?? 0,
                $summary['latest_metrics']['running_scheduled_jobs'] ?? 0
            ));
        }

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}

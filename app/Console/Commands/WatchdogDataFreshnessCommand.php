<?php

namespace App\Console\Commands;

use App\Services\Watchdog\DataFreshnessChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * APL #8B layers 4 + 6 — data-freshness / notification-delivery observer.
 *
 * Iterates `expected_outputs_catalog` rows via DataFreshnessChecker and
 * prints a per-row report. Exit code 0 if every critical check passed;
 * exit 1 if any critical check failed (so framework-watchdog picks up
 * the failure the same way it picks up other failing scheduled jobs).
 *
 * Warn/info failures are reported but do NOT trigger a non-zero exit —
 * they surface in the log + daily report but don't page the operator.
 */
class WatchdogDataFreshnessCommand extends Command
{
    protected $signature = 'watchdog:data-freshness
        {--json : Machine-readable JSON output}
        {--severity=critical : Minimum severity to fail on (info|warn|critical)}';

    protected $description = 'APL #8B: evaluate expected_outputs_catalog and fail if critical expectations are not met';

    private const SEVERITY_RANK = ['info' => 1, 'warn' => 2, 'critical' => 3];

    public function handle(DataFreshnessChecker $checker): int
    {
        $report = $checker->runAll();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $this->computeExitCode($report);
        }

        if ($report === []) {
            $this->warn('expected_outputs_catalog is empty — no checks to run.');
            return self::SUCCESS;
        }

        $this->info('Data-freshness checks:');
        $passed = 0;
        $failed = 0;
        foreach ($report as $r) {
            $statusTag = $r['status'] === 'pass' ? '[OK]  ' : '[FAIL]';
            $line = sprintf('  %s [%s] %s — %s', $statusTag, strtoupper($r['severity']), $r['expected_item'], $r['message']);

            if ($r['status'] === 'pass') {
                $this->info($line);
                $passed++;
            } else {
                $r['severity'] === 'critical' ? $this->error($line) : $this->warn($line);
                $failed++;
            }
        }

        $this->line('');
        $this->info(sprintf('%d passed, %d failed', $passed, $failed));
        $this->info(sprintf('[ITEMS_PROCESSED:%d]', count($report)));

        Log::info('WatchdogDataFreshness: report', [
            'passed' => $passed,
            'failed' => $failed,
            'failing' => array_values(array_filter(
                $report,
                static fn ($r) => $r['status'] !== 'pass'
            )),
        ]);

        return $this->computeExitCode($report);
    }

    /**
     * @param array<int, array{status:string, severity:string}> $report
     */
    private function computeExitCode(array $report): int
    {
        $minSeverity = strtolower((string) ($this->option('severity') ?: 'critical'));
        $minRank = self::SEVERITY_RANK[$minSeverity] ?? self::SEVERITY_RANK['critical'];

        foreach ($report as $r) {
            if ($r['status'] === 'pass') {
                continue;
            }
            $rank = self::SEVERITY_RANK[$r['severity']] ?? 0;
            if ($rank >= $minRank) {
                return self::FAILURE;
            }
        }
        return self::SUCCESS;
    }
}

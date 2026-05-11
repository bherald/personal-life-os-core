<?php

namespace App\Console\Commands;

use App\Services\Ops\SmokeTestService;
use Illuminate\Console\Command;

/**
 * ops:smoke-test — Validates critical runtime assumptions before they break silently.
 *
 * Catches the class of bugs that cause the most churn:
 * - Tool registry entries pointing to deleted/renamed service classes or methods
 * - Scheduled jobs referencing non-existent artisan commands
 * - SKILL.md files referencing tools not in the registry
 * - Pipeline queries that silently fail due to schema drift
 * - LLM pool with no healthy providers
 *
 * Run after every deploy and before declaring PROD1 complete.
 * Run on dev before pushing to catch issues early.
 *
 * Usage:
 *   php artisan ops:smoke-test              # Full test suite
 *   php artisan ops:smoke-test --quick      # Registry + jobs only (10s)
 *   php artisan ops:smoke-test --allow-schema-drift # Downgrade local schema drift to warnings
 *   php artisan ops:smoke-test --fix        # Auto-disable broken registry entries
 *   php artisan ops:smoke-test --json       # Machine-readable output
 */
class SmokeTestCommand extends Command
{
    protected $signature = 'ops:smoke-test
                            {--quick : Run only fast checks (registry + jobs)}
                            {--fix : Auto-disable broken tool registry entries}
                            {--allow-schema-drift : Downgrade local schema drift failures to warnings}
                            {--json : Output results as JSON}';

    protected $description = 'Validate tool registry, scheduled jobs, SKILL.md, pipeline queries, and LLM pool';

    public function handle(SmokeTestService $smokeTest): int
    {
        if (! $this->option('json')) {
            $this->info("=== PLOS Smoke Test ===\n");
        }

        $report = $smokeTest->run(
            quick: (bool) $this->option('quick'),
            fix: (bool) $this->option('fix'),
            allowSchemaDrift: (bool) $this->option('allow-schema-drift')
        );

        if ($this->option('json')) {
            $this->line(json_encode($this->jsonReport($report), JSON_PRETTY_PRINT));
        } else {
            $this->writeHumanReport($report);
        }

        $this->line("[ITEMS_PROCESSED:{$report['pass']}]");

        return ($report['fail'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function jsonReport(array $report): array
    {
        $copy = $report;
        $copy['results'] = array_map(static function (array $result): array {
            unset($result['section']);

            return $result;
        }, $copy['results'] ?? []);

        return $copy;
    }

    private function writeHumanReport(array $report): void
    {
        $currentSection = null;

        foreach ($report['results'] ?? [] as $result) {
            $section = (string) ($result['section'] ?? '');
            if ($section !== '' && $section !== $currentSection) {
                $this->line("\n[{$section}]");
                $currentSection = $section;
            }

            $status = (string) ($result['status'] ?? '');
            $icon = match ($status) {
                'PASS' => '  OK',
                'FAIL' => 'FAIL',
                'WARN' => 'WARN',
                default => '  ??',
            };

            $this->line("  [{$icon}] ".(string) ($result['message'] ?? ''));

            if (! empty($result['auto_disabled'])) {
                $this->warn('  Auto-disabled: '.$result['auto_disabled']);
            }
        }

        $this->newLine();
        $this->line('--- Summary ---');
        $this->info("PASS: {$report['pass']}  FAIL: {$report['fail']}  WARN: {$report['warn']}  ({$report['duration_s']}s)");

        if (($report['fail'] ?? 0) > 0) {
            $this->error("\nSmoke test FAILED — fix issues before deploying.");
        } else {
            $this->info("\nSmoke test passed.".(($report['warn'] ?? 0) > 0 ? ' Review warnings.' : ''));
        }
    }
}

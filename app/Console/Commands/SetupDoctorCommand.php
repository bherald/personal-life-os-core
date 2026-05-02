<?php

namespace App\Console\Commands;

use App\Services\Setup\SetupDoctor;
use App\Support\Setup\CheckResult;
use App\Support\Setup\Report;
use Illuminate\Console\Command;

/**
 * setup:doctor — first-pass public-install health command.
 *
 * Read-only. Walks env, php, binaries, python, services, passport, database,
 * browser, assets, and docker check groups against `config/setup.php`
 * for the requested profile and emits either a human table or stable JSON.
 */
class SetupDoctorCommand extends Command
{
    protected $signature = 'setup:doctor
                            {--profile=core : Install profile to validate (core|media|gpu|full|personal)}
                            {--json : Emit a stable JSON report instead of text}
                            {--strict : Treat warnings as failures (exit 1)}
                            {--skip-services : Skip localhost service and database probes entirely}
                            {--only= : Comma-separated list of groups to run (env,php,binaries,python,services,passport,database,browser,assets,docker)}';

    protected $description = 'Read-only health checks for a public PLOS install.';

    public function handle(SetupDoctor $doctor): int
    {
        $profile = $doctor->normalizeProfile((string) $this->option('profile'));
        $only = $this->parseOnly((string) ($this->option('only') ?? ''));

        $report = $doctor->diagnose([
            'profile' => $profile,
            'strict' => (bool) $this->option('strict'),
            'skip_services' => (bool) $this->option('skip-services'),
            'only' => $only,
        ]);

        if ($this->option('json')) {
            $this->renderJson($report);
        } else {
            $this->renderText($report);
        }

        return $report->exitCode() === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function parseOnly(string $only): array
    {
        if (trim($only) === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $only) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
    }

    private function renderJson(Report $report): void
    {
        $payload = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->line($payload === false ? '{}' : $payload);
    }

    private function renderText(Report $report): void
    {
        $this->info("=== PLOS Setup Doctor (profile={$report->profile}, strict=".($report->strict ? 'yes' : 'no').') ===');

        $rows = [];
        foreach ($report->checks as $check) {
            $rows[] = [
                $this->statusIcon($check->status),
                $check->group,
                $check->name,
                $check->message,
            ];
        }

        if ($rows !== []) {
            $this->table(['Status', 'Group', 'Check', 'Detail'], $rows);
        }

        $totals = $report->totals();
        $this->newLine();
        $this->line(sprintf(
            'Totals: pass=%d warn=%d fail=%d skip=%d (total=%d)',
            $totals['pass'], $totals['warn'], $totals['fail'], $totals['skip'], $totals['total']
        ));

        $status = $report->status();
        $line = "Overall: {$status}";
        match ($status) {
            CheckResult::STATUS_FAIL => $this->error($line),
            CheckResult::STATUS_WARN => $this->warn($line),
            default => $this->info($line),
        };
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            CheckResult::STATUS_PASS => 'PASS',
            CheckResult::STATUS_WARN => 'WARN',
            CheckResult::STATUS_FAIL => 'FAIL',
            default => 'SKIP',
        };
    }
}

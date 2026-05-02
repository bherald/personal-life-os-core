<?php

namespace App\Console\Commands;

use App\Services\OllamaModelRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Row 3 — Ollama inventory drift check.
 *
 * Reads `/api/tags` from every active Ollama instance, compares to
 * `llm_instances.supported_models`, and prints a per-instance drift
 * report. Reports only — does not mutate `llm_instances`.
 *
 *   - `in_live_not_in_db`  : new models pulled outside PLOS control
 *   - `in_db_not_in_live`  : phantom rows lying about availability
 *   - `unreachable: true`  : couldn't probe the host at all
 *
 * Exit code 0 when no drift in either direction on any reachable
 * instance. Exit code 1 when any drift is present (so framework-watchdog
 * picks it up the same way it picks up other failing scheduled jobs).
 */
class OllamaDriftCheckCommand extends Command
{
    protected $signature = 'ollama:drift-check
        {--json : Machine-readable JSON output}
        {--no-fail : Always exit 0 regardless of drift (reporting-only)}';

    protected $description = 'Row 3: compare live /api/tags to llm_instances.supported_models and report drift';

    public function handle(OllamaModelRegistryService $registry): int
    {
        $report = $registry->driftCheck();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->computeExitCode($report);
        }

        if ($report === []) {
            $this->warn('No active Ollama instances found in llm_instances.');
            return self::SUCCESS;
        }

        $totalDrift = 0;
        foreach ($report as $row) {
            $this->line('');
            $this->info("  {$row['instance_id']} ({$row['base_url']}):");

            if ($row['unreachable']) {
                $this->warn('    UNREACHABLE — could not probe /api/tags (host down or network blocked)');
                continue;
            }

            $this->line("    live: {$row['live_count']} models | db: {$row['db_count']} models");

            if ($row['in_live_not_in_db'] !== []) {
                $totalDrift++;
                $this->warn(sprintf(
                    '    [informational] in live, not in DB (%d): %s',
                    count($row['in_live_not_in_db']),
                    implode(', ', $row['in_live_not_in_db'])
                ));
            }

            if ($row['in_db_not_in_live'] !== []) {
                $totalDrift++;
                $this->error(sprintf(
                    '    [phantom] in DB, not in live (%d): %s  — DB is lying; routing to these models will fail',
                    count($row['in_db_not_in_live']),
                    implode(', ', $row['in_db_not_in_live'])
                ));
            }

            if ($row['in_live_not_in_db'] === [] && $row['in_db_not_in_live'] === []) {
                $this->info('    OK — no drift');
            }
        }

        $this->line('');
        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $totalDrift));

        Log::info('OllamaDriftCheck: report', [
            'total_drift_categories' => $totalDrift,
            'instances' => array_map(
                static fn (array $r) => [
                    'instance_id' => $r['instance_id'],
                    'unreachable' => $r['unreachable'],
                    'in_live_not_in_db' => $r['in_live_not_in_db'],
                    'in_db_not_in_live' => $r['in_db_not_in_live'],
                ],
                $report
            ),
        ]);

        return $this->computeExitCode($report);
    }

    /**
     * @param array<int, array{unreachable:bool, in_live_not_in_db:array, in_db_not_in_live:array}> $report
     */
    private function computeExitCode(array $report): int
    {
        if ($this->option('no-fail')) {
            return self::SUCCESS;
        }

        foreach ($report as $row) {
            if ($row['unreachable']) {
                continue;
            }
            if ($row['in_db_not_in_live'] !== [] || $row['in_live_not_in_db'] !== []) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}

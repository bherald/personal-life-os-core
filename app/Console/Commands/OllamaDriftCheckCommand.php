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
        {--compact : Emit aggregate-only output without model names or host URLs}
        {--no-fail : Always exit 0 regardless of drift (reporting-only)}';

    protected $description = 'Row 3: compare live /api/tags to llm_instances.supported_models and report drift';

    public function handle(OllamaModelRegistryService $registry): int
    {
        $report = $registry->driftCheck();

        if ($this->option('json')) {
            $payload = $this->option('compact') ? $this->compactReport($report) : $report;
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->computeExitCode($report);
        }

        if ($this->option('compact')) {
            $compact = $this->compactReport($report);
            $this->line(sprintf(
                'ollama_drift status=%s instances=%d clean=%d unreachable=%d live_only_models=%d phantom_models=%d read_only=true',
                $compact['status'],
                $compact['instances'],
                $compact['clean_instances'],
                $compact['unreachable_instances'],
                $compact['live_only_models'],
                $compact['phantom_models'],
            ));

            foreach ($compact['attention_instances'] as $instance) {
                $this->line(sprintf(
                    '  %s verdict=%s live_only=%d phantom=%d unreachable=%s',
                    $instance['instance_id'],
                    $instance['verdict'],
                    $instance['live_only_count'],
                    $instance['phantom_count'],
                    $instance['unreachable'] ? 'true' : 'false',
                ));
            }

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
     * @param  array<int, array{instance_id:string, base_url:string, unreachable:bool, live_count:int, db_count:int, in_live_not_in_db:array<int,string>, in_db_not_in_live:array<int,string>}>  $report
     * @return array{status:string, read_only:bool, no_write:bool, instances:int, clean_instances:int, unreachable_instances:int, live_only_models:int, phantom_models:int, attention_instances:list<array{instance_id:string, verdict:string, unreachable:bool, live_only_count:int, phantom_count:int}>}
     */
    private function compactReport(array $report): array
    {
        $clean = 0;
        $unreachable = 0;
        $liveOnly = 0;
        $phantom = 0;
        $attention = [];

        foreach ($report as $row) {
            $liveOnlyCount = count($row['in_live_not_in_db']);
            $phantomCount = count($row['in_db_not_in_live']);
            $isUnreachable = (bool) $row['unreachable'];

            $liveOnly += $liveOnlyCount;
            $phantom += $phantomCount;
            if ($isUnreachable) {
                $unreachable++;
            }

            if (! $isUnreachable && $liveOnlyCount === 0 && $phantomCount === 0) {
                $clean++;

                continue;
            }

            $attention[] = [
                'instance_id' => (string) $row['instance_id'],
                'verdict' => $isUnreachable ? 'unreachable' : ($phantomCount > 0 ? 'phantom_drift' : 'live_only_drift'),
                'unreachable' => $isUnreachable,
                'live_only_count' => $liveOnlyCount,
                'phantom_count' => $phantomCount,
            ];
        }

        $status = 'pass';
        if ($phantom > 0) {
            $status = 'fail';
        } elseif ($liveOnly > 0 || $unreachable > 0) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'read_only' => true,
            'no_write' => true,
            'instances' => count($report),
            'clean_instances' => $clean,
            'unreachable_instances' => $unreachable,
            'live_only_models' => $liveOnly,
            'phantom_models' => $phantom,
            'attention_instances' => $attention,
        ];
    }

    /**
     * @param  array<int, array{unreachable:bool, in_live_not_in_db:array, in_db_not_in_live:array}>  $report
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

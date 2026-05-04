<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentRecursionCallsRetentionService;
use Illuminate\Console\Command;

class OpsArcRetentionCommand extends Command
{
    protected $signature = 'ops:arc-retention
        {--execute : Delete eligible agent_recursion_calls rows}
        {--json : Emit machine-readable JSON}
        {--retention-days= : Override recursion.retention_days}
        {--batch=10000 : Delete batch size, capped at 50000}
        {--max-rows=50000 : Max rows to delete in this execution, capped at 1000000}
        {--sleep-ms=100 : Milliseconds to pause between batches}';

    protected $description = 'Bounded agent_recursion_calls retention cleanup with dry-run default';

    public function handle(AgentRecursionCallsRetentionService $service): int
    {
        $retentionDays = $this->intOption('retention-days', nullable: true);
        $batchSize = $this->intOption('batch');
        $maxRows = $this->intOption('max-rows');
        $sleepMs = $this->intOption('sleep-ms');

        if ($retentionDays !== null && $retentionDays < 1) {
            $this->error('retention-days must be at least 1.');

            return self::INVALID;
        }

        if ($batchSize === null || $batchSize < 1 || $batchSize > 50_000) {
            $this->error('batch must be an integer from 1 to 50000.');

            return self::INVALID;
        }

        if ($maxRows === null || $maxRows < 1 || $maxRows > 1_000_000) {
            $this->error('max-rows must be an integer from 1 to 1000000.');

            return self::INVALID;
        }

        if ($sleepMs === null || $sleepMs < 0 || $sleepMs > 5_000) {
            $this->error('sleep-ms must be an integer from 0 to 5000.');

            return self::INVALID;
        }

        $payload = $service->collect(
            execute: (bool) $this->option('execute'),
            retentionDays: $retentionDays,
            batchSize: $batchSize,
            maxRows: $maxRows,
            sleepMs: $sleepMs,
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode ARC retention JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'ARC retention: %s env=%s host=%s database=%s mode=%s retention_days=%d cutoff=%s deleted=%d batches=%d stopped=%s',
            $payload['status'] ?? 'unknown',
            $payload['environment']['app_env'] ?? 'unknown',
            $payload['environment']['hostname'] ?? 'unknown',
            $payload['environment']['database_name'] ?? 'unknown',
            $payload['mode'] ?? 'unknown',
            (int) ($payload['retention_days'] ?? 0),
            (string) ($payload['cutoff'] ?? '-'),
            (int) ($payload['deleted_rows'] ?? 0),
            (int) ($payload['batches'] ?? 0),
            (string) ($payload['stopped_reason'] ?? '-'),
        ));

        if (! ($payload['execute'] ?? false)) {
            $this->warn('dry-run only; add --execute to delete bounded eligible rows.');
        }

        return self::SUCCESS;
    }

    private function intOption(string $name, bool $nullable = false): ?int
    {
        $value = $this->option($name);

        if (($value === null || $value === '') && $nullable) {
            return null;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($filtered) ? $filtered : null;
    }
}

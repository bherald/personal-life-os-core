<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentRecursionCallsRetentionService;
use Illuminate\Console\Command;

class OpsArcRetentionCommand extends Command
{
    protected $signature = 'ops:arc-retention
        {--execute : Delete eligible agent_recursion_calls rows}
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only evidence without environment names, row IDs, or raw timestamps}
        {--retention-days= : Override recursion.retention_days}
        {--batch=10000 : Delete batch size, capped at 50000}
        {--max-rows=50000 : Max rows to delete in this execution, capped at 1000000}
        {--sleep-ms=100 : Milliseconds to pause between batches}
        {--repeat=1 : Number of bounded execute runs to perform, capped at 100}';

    protected $description = 'Bounded agent_recursion_calls retention cleanup with dry-run default';

    public function handle(AgentRecursionCallsRetentionService $service): int
    {
        $retentionDays = $this->intOption('retention-days', nullable: true);
        $batchSize = $this->intOption('batch');
        $maxRows = $this->intOption('max-rows');
        $sleepMs = $this->intOption('sleep-ms');
        $repeat = $this->intOption('repeat');
        $execute = (bool) $this->option('execute');

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

        if ($repeat === null || $repeat < 1 || $repeat > 100) {
            $this->error('repeat must be an integer from 1 to 100.');

            return self::INVALID;
        }

        if ($repeat > 1 && ! $execute) {
            $this->error('repeat greater than 1 is only supported with --execute.');

            return self::INVALID;
        }

        if ($repeat > 1) {
            $payload = $this->collectRepeated($service, $repeat, $retentionDays, $batchSize, $maxRows, $sleepMs);
        } else {
            $payload = $service->collect(
                execute: $execute,
                retentionDays: $retentionDays,
                batchSize: $batchSize,
                maxRows: $maxRows,
                sleepMs: $sleepMs,
            );
        }

        $compact = (bool) $this->option('compact');
        if ($compact) {
            $payload = $this->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode ARC retention JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($compact) {
            if (($payload['mode'] ?? null) === 'execute_repeat') {
                $this->line(sprintf(
                    'ARC retention compact: %s mode=%s requested_runs=%d completed_runs=%d retention_days=%d deleted=%d batches=%d stopped=%s',
                    $payload['status'] ?? 'unknown',
                    $payload['mode'] ?? 'unknown',
                    (int) ($payload['requested_runs'] ?? 0),
                    (int) ($payload['completed_runs'] ?? 0),
                    (int) ($payload['retention_days'] ?? 0),
                    (int) ($payload['deleted_rows'] ?? 0),
                    (int) ($payload['batches'] ?? 0),
                    (string) ($payload['stopped_reason'] ?? '-'),
                ));

                return self::SUCCESS;
            }

            $this->line(sprintf(
                'ARC retention compact: %s mode=%s retention_days=%d estimated_rows=%d total_gb=%.3f oldest_eligible=%s deleted=%d batches=%d stopped=%s',
                $payload['status'] ?? 'unknown',
                $payload['mode'] ?? 'unknown',
                (int) ($payload['retention_days'] ?? 0),
                (int) ($payload['table']['estimated_rows'] ?? 0),
                (float) ($payload['table']['total_gb'] ?? 0.0),
                ($payload['table']['has_oldest_eligible'] ?? false) ? 'yes' : 'no',
                (int) ($payload['deleted_rows'] ?? 0),
                (int) ($payload['batches'] ?? 0),
                (string) ($payload['stopped_reason'] ?? '-'),
            ));

            if (! ($payload['execute'] ?? false)) {
                $this->warn('dry-run only; add --execute to delete bounded eligible rows.');
            }

            return self::SUCCESS;
        }

        if (($payload['mode'] ?? null) === 'execute_repeat') {
            $this->line(sprintf(
                'ARC retention repeat: %s requested_runs=%d completed_runs=%d retention_days=%d deleted=%d batches=%d stopped=%s last_status=%s',
                $payload['status'] ?? 'unknown',
                (int) ($payload['requested_runs'] ?? 0),
                (int) ($payload['completed_runs'] ?? 0),
                (int) ($payload['retention_days'] ?? 0),
                (int) ($payload['deleted_rows'] ?? 0),
                (int) ($payload['batches'] ?? 0),
                (string) ($payload['stopped_reason'] ?? '-'),
                (string) ($payload['last_run_status'] ?? '-'),
            ));

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

    private function collectRepeated(
        AgentRecursionCallsRetentionService $service,
        int $repeat,
        ?int $retentionDays,
        int $batchSize,
        int $maxRows,
        int $sleepMs,
    ): array {
        $runs = [];
        $deletedRows = 0;
        $batches = 0;
        $stoppedReason = 'repeat_limit_reached';
        $lastRunStatus = 'unknown';

        for ($run = 1; $run <= $repeat; $run++) {
            $payload = $service->collect(
                execute: true,
                retentionDays: $retentionDays,
                batchSize: $batchSize,
                maxRows: $maxRows,
                sleepMs: $sleepMs,
            );

            $runs[] = [
                'run' => $run,
                'payload' => $payload,
            ];

            $deletedRows += (int) ($payload['deleted_rows'] ?? 0);
            $batches += (int) ($payload['batches'] ?? 0);
            $lastRunStatus = (string) ($payload['status'] ?? 'unknown');
            $runStoppedReason = (string) ($payload['stopped_reason'] ?? 'unknown');

            if (! $this->shouldContinueRepeat($payload)) {
                $stoppedReason = $runStoppedReason;
                break;
            }
        }

        if (count($runs) >= $repeat && $lastRunStatus === 'cleanup_incomplete') {
            $stoppedReason = 'repeat_limit_reached';
        }

        return [
            'version' => 1,
            'mode' => 'execute_repeat',
            'execute' => true,
            'status' => $lastRunStatus,
            'requested_runs' => $repeat,
            'completed_runs' => count($runs),
            'retention_days' => $retentionDays ?? (int) config('recursion.retention_days', 30),
            'batch_size' => $batchSize,
            'max_rows_per_run' => $maxRows,
            'sleep_ms' => $sleepMs,
            'deleted_rows' => $deletedRows,
            'batches' => $batches,
            'stopped_reason' => $stoppedReason,
            'last_run_status' => $lastRunStatus,
            'runs' => $runs,
            'safety' => [
                'count_first' => false,
                'bounded_batch_delete' => true,
                'uses_created_at_index' => true,
                'summary_table_preserved' => 'recursion_effectiveness',
                'requires_execute_flag' => true,
                'repeat_requires_execute' => true,
                'repeat_cap' => 100,
                'stops_unless_chunk_reaches_max_rows' => true,
            ],
        ];
    }

    private function shouldContinueRepeat(array $payload): bool
    {
        return ($payload['status'] ?? null) === 'cleanup_incomplete'
            && (int) ($payload['deleted_rows'] ?? 0) > 0
            && ($payload['stopped_reason'] ?? null) === 'max_rows_reached';
    }

    private function compactPayload(array $payload): array
    {
        if (($payload['mode'] ?? null) === 'execute_repeat') {
            return $this->compactRepeatPayload($payload);
        }

        $table = is_array($payload['table'] ?? null) ? $payload['table'] : [];
        $safety = is_array($payload['safety'] ?? null) ? $payload['safety'] : [];

        return [
            'version' => (int) ($payload['version'] ?? 1),
            'compact' => true,
            'mode' => (string) ($payload['mode'] ?? 'unknown'),
            'execute' => (bool) ($payload['execute'] ?? false),
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'retention_days' => (int) ($payload['retention_days'] ?? 0),
            'batch_size' => (int) ($payload['batch_size'] ?? 0),
            'max_rows' => (int) ($payload['max_rows'] ?? 0),
            'sleep_ms' => (int) ($payload['sleep_ms'] ?? 0),
            'table' => [
                'name' => (string) ($table['name'] ?? 'agent_recursion_calls'),
                'estimated_rows' => (int) ($table['estimated_rows'] ?? 0),
                'data_gb' => (float) ($table['data_gb'] ?? 0.0),
                'index_gb' => (float) ($table['index_gb'] ?? 0.0),
                'total_gb' => (float) ($table['total_gb'] ?? 0.0),
                'has_oldest_created_at' => ($table['oldest_created_at'] ?? null) !== null,
                'has_newest_created_at' => ($table['newest_created_at'] ?? null) !== null,
                'has_oldest_eligible' => ($payload['oldest_eligible'] ?? null) !== null,
                'has_remaining_oldest_eligible' => ($payload['remaining_oldest_eligible'] ?? null) !== null,
            ],
            'deleted_rows' => (int) ($payload['deleted_rows'] ?? 0),
            'batches' => (int) ($payload['batches'] ?? 0),
            'stopped_reason' => (string) ($payload['stopped_reason'] ?? 'unknown'),
            'safety' => [
                'count_first' => (bool) ($safety['count_first'] ?? false),
                'bounded_batch_delete' => (bool) ($safety['bounded_batch_delete'] ?? true),
                'uses_created_at_index' => (bool) ($safety['uses_created_at_index'] ?? true),
                'summary_table_preserved' => (string) ($safety['summary_table_preserved'] ?? 'recursion_effectiveness'),
                'requires_execute_flag' => (bool) ($safety['requires_execute_flag'] ?? true),
            ],
            'posture' => [
                'aggregate_only' => true,
                'environment_included' => false,
                'host_included' => false,
                'database_name_included' => false,
                'row_ids_included' => false,
                'raw_timestamps_included' => false,
                'database_mutation_enabled' => (bool) ($payload['execute'] ?? false),
            ],
        ];
    }

    private function compactRepeatPayload(array $payload): array
    {
        $safety = is_array($payload['safety'] ?? null) ? $payload['safety'] : [];
        $runs = [];

        foreach ((array) ($payload['runs'] ?? []) as $run) {
            $runPayload = is_array($run['payload'] ?? null) ? $run['payload'] : [];
            $runs[] = [
                'run' => (int) ($run['run'] ?? 0),
                'status' => (string) ($runPayload['status'] ?? 'unknown'),
                'deleted_rows' => (int) ($runPayload['deleted_rows'] ?? 0),
                'batches' => (int) ($runPayload['batches'] ?? 0),
                'stopped_reason' => (string) ($runPayload['stopped_reason'] ?? 'unknown'),
                'has_remaining_oldest_eligible' => ($runPayload['remaining_oldest_eligible'] ?? null) !== null,
            ];
        }

        return [
            'version' => (int) ($payload['version'] ?? 1),
            'compact' => true,
            'mode' => 'execute_repeat',
            'execute' => true,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'requested_runs' => (int) ($payload['requested_runs'] ?? 0),
            'completed_runs' => (int) ($payload['completed_runs'] ?? 0),
            'retention_days' => (int) ($payload['retention_days'] ?? 0),
            'batch_size' => (int) ($payload['batch_size'] ?? 0),
            'max_rows_per_run' => (int) ($payload['max_rows_per_run'] ?? 0),
            'sleep_ms' => (int) ($payload['sleep_ms'] ?? 0),
            'deleted_rows' => (int) ($payload['deleted_rows'] ?? 0),
            'batches' => (int) ($payload['batches'] ?? 0),
            'stopped_reason' => (string) ($payload['stopped_reason'] ?? 'unknown'),
            'last_run_status' => (string) ($payload['last_run_status'] ?? 'unknown'),
            'runs' => $runs,
            'safety' => [
                'count_first' => (bool) ($safety['count_first'] ?? false),
                'bounded_batch_delete' => (bool) ($safety['bounded_batch_delete'] ?? true),
                'uses_created_at_index' => (bool) ($safety['uses_created_at_index'] ?? true),
                'summary_table_preserved' => (string) ($safety['summary_table_preserved'] ?? 'recursion_effectiveness'),
                'requires_execute_flag' => (bool) ($safety['requires_execute_flag'] ?? true),
                'repeat_requires_execute' => (bool) ($safety['repeat_requires_execute'] ?? true),
                'repeat_cap' => (int) ($safety['repeat_cap'] ?? 100),
                'stops_unless_chunk_reaches_max_rows' => (bool) ($safety['stops_unless_chunk_reaches_max_rows'] ?? true),
            ],
            'posture' => [
                'aggregate_only' => true,
                'environment_included' => false,
                'host_included' => false,
                'database_name_included' => false,
                'row_ids_included' => false,
                'raw_timestamps_included' => false,
                'database_mutation_enabled' => true,
            ],
        ];
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

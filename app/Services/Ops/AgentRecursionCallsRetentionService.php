<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

class AgentRecursionCallsRetentionService
{
    private const MAX_BATCH_SIZE = 50_000;

    private const MAX_ROWS_PER_EXECUTION = 1_000_000;

    public function collect(
        bool $execute = false,
        ?int $retentionDays = null,
        int $batchSize = 10_000,
        int $maxRows = 50_000,
        int $sleepMs = 100,
    ): array {
        $retentionDays = $retentionDays ?? (int) config('recursion.retention_days', 30);
        $retentionDays = max(1, $retentionDays);
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, $batchSize));
        $maxRows = max(1, min(self::MAX_ROWS_PER_EXECUTION, $maxRows));
        $sleepMs = max(0, min(5_000, $sleepMs));
        $cutoff = now()->subDays($retentionDays)->toDateTimeString();

        $payload = [
            'version' => 1,
            'mode' => $execute ? 'execute' : 'dry_run',
            'execute' => $execute,
            'table' => $this->tableSummary(),
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff,
            'batch_size' => $batchSize,
            'max_rows' => $maxRows,
            'sleep_ms' => $sleepMs,
            'started_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'deleted_rows' => 0,
            'batches' => 0,
            'stopped_reason' => $execute ? 'not_started' : 'dry_run',
            'safety' => [
                'count_first' => false,
                'bounded_batch_delete' => true,
                'uses_created_at_index' => true,
                'summary_table_preserved' => 'recursion_effectiveness',
                'requires_execute_flag' => true,
            ],
        ];

        $payload['oldest_eligible'] = $this->oldestEligible($cutoff);

        if (! $execute) {
            $payload['status'] = $payload['oldest_eligible'] === null ? 'observe_ok' : 'review_required';
            $payload['completed_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');

            return $payload;
        }

        while ($payload['deleted_rows'] < $maxRows) {
            $remainingAllowance = $maxRows - $payload['deleted_rows'];
            $limit = min($batchSize, $remainingAllowance);
            $deleted = $this->deleteBatch($cutoff, $limit);

            if ($deleted <= 0) {
                $payload['stopped_reason'] = 'no_more_eligible_rows';
                break;
            }

            $payload['deleted_rows'] += $deleted;
            $payload['batches']++;

            if ($deleted < $limit) {
                $payload['stopped_reason'] = 'last_partial_batch';
                break;
            }

            if ($payload['deleted_rows'] >= $maxRows) {
                $payload['stopped_reason'] = 'max_rows_reached';
                break;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $payload['remaining_oldest_eligible'] = $this->oldestEligible($cutoff);
        $payload['completed_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $payload['status'] = $this->status($payload);

        return $payload;
    }

    private function tableSummary(): array
    {
        $stats = DB::selectOne(
            "SELECT table_rows AS table_rows,
                    data_length AS data_length,
                    index_length AS index_length,
                    (data_length + index_length) AS total_bytes
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = 'agent_recursion_calls'
              LIMIT 1"
        );

        return [
            'name' => 'agent_recursion_calls',
            'estimated_rows' => (int) $this->rowValue($stats, 'table_rows'),
            'data_gb' => $this->bytesToGb((int) $this->rowValue($stats, 'data_length')),
            'index_gb' => $this->bytesToGb((int) $this->rowValue($stats, 'index_length')),
            'total_gb' => $this->bytesToGb((int) $this->rowValue($stats, 'total_bytes')),
            'oldest_created_at' => $this->createdAtBoundary('ASC'),
            'newest_created_at' => $this->createdAtBoundary('DESC'),
        ];
    }

    private function createdAtBoundary(string $direction): ?string
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $row = DB::selectOne(
            "SELECT created_at
               FROM agent_recursion_calls FORCE INDEX (idx_arc_created_covering)
              WHERE created_at IS NOT NULL
              ORDER BY created_at {$direction}
              LIMIT 1"
        );

        return $this->nullableString($row->created_at ?? null);
    }

    private function oldestEligible(string $cutoff): ?array
    {
        $row = DB::selectOne(
            'SELECT id, created_at
               FROM agent_recursion_calls FORCE INDEX (idx_arc_created_covering)
              WHERE created_at < ?
              ORDER BY created_at ASC
              LIMIT 1',
            [$cutoff]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'created_at' => $this->nullableString($row->created_at ?? null),
        ];
    }

    private function deleteBatch(string $cutoff, int $limit): int
    {
        $limit = max(1, min(self::MAX_BATCH_SIZE, $limit));

        return DB::delete(
            "DELETE arc
               FROM agent_recursion_calls AS arc
               JOIN (
                    SELECT id
                      FROM (
                           SELECT id
                             FROM agent_recursion_calls FORCE INDEX (idx_arc_created_covering)
                            WHERE created_at < ?
                            ORDER BY created_at ASC
                            LIMIT {$limit}
                      ) AS arc_retention_candidates
               ) AS purge_ids ON purge_ids.id = arc.id",
            [$cutoff]
        );
    }

    private function status(array $payload): string
    {
        if (($payload['deleted_rows'] ?? 0) <= 0) {
            return $payload['remaining_oldest_eligible'] === null ? 'observe_ok' : 'review_required';
        }

        if (($payload['stopped_reason'] ?? null) === 'max_rows_reached'
            && $payload['remaining_oldest_eligible'] !== null) {
            return 'cleanup_incomplete';
        }

        return $payload['remaining_oldest_eligible'] === null ? 'cleanup_complete' : 'cleanup_incomplete';
    }

    private function bytesToGb(int $bytes): float
    {
        return round($bytes / 1024 / 1024 / 1024, 3);
    }

    private function rowValue(?object $row, string $key): mixed
    {
        if ($row === null) {
            return null;
        }

        $upper = strtoupper($key);

        return $row->{$key} ?? $row->{$upper} ?? null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}

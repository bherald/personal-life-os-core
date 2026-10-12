<?php

namespace App\Services;

class WorkflowExecutionTraceService
{
    public const SCHEMA = 'plos.workflow_execution_retry_trace.v1';

    private const STATES = [
        'pending',
        'running',
        'awaiting_approval',
        'success',
        'failed',
        'skipped',
    ];

    /**
     * @param  array<int,object|array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    public function summarize(object|array $run, array $nodes): array
    {
        $attempts = [];
        $timedOutNodes = 0;

        foreach ($nodes as $node) {
            $attemptNumber = max(1, min(1000, (int) $this->value($node, 'attempt_number', 1)));
            $state = $this->safeState($this->value($node, 'state'));
            $durationMs = max(0, (int) $this->value($node, 'duration_ms', 0));
            $timedOut = (int) $this->value($node, 'timed_out', 0) === 1;
            $executedAt = $this->safeTimestamp($this->value($node, 'executed_at'));

            $attempts[$attemptNumber] ??= [
                'attempt_number' => $attemptNumber,
                'node_count' => 0,
                'success_nodes' => 0,
                'failed_nodes' => 0,
                'skipped_nodes' => 0,
                'pending_nodes' => 0,
                'running_nodes' => 0,
                'awaiting_approval_nodes' => 0,
                'timed_out_nodes' => 0,
                'duration_ms' => 0,
                'first_executed_at' => null,
                'last_executed_at' => null,
            ];

            $attempts[$attemptNumber]['node_count']++;
            $attempts[$attemptNumber]['duration_ms'] += $durationMs;
            $attempts[$attemptNumber][$this->stateCountKey($state)]++;

            if ($timedOut) {
                $attempts[$attemptNumber]['timed_out_nodes']++;
                $timedOutNodes++;
            }

            if ($executedAt !== null) {
                $first = $attempts[$attemptNumber]['first_executed_at'];
                $last = $attempts[$attemptNumber]['last_executed_at'];
                $attempts[$attemptNumber]['first_executed_at'] = $first === null || $executedAt < $first
                    ? $executedAt
                    : $first;
                $attempts[$attemptNumber]['last_executed_at'] = $last === null || $executedAt > $last
                    ? $executedAt
                    : $last;
            }
        }

        ksort($attempts, SORT_NUMERIC);
        foreach ($attempts as &$attempt) {
            $attempt['status'] = $this->attemptStatus($attempt);
        }
        unset($attempt);

        $attemptRows = array_values($attempts);
        $attemptCount = $attemptRows === []
            ? 1
            : max(array_column($attemptRows, 'attempt_number'));
        $failedAttempts = count(array_filter(
            $attemptRows,
            static fn (array $attempt): bool => $attempt['status'] === 'failed'
        ));
        $finalAttempt = $attemptRows === [] ? null : $attemptRows[array_key_last($attemptRows)];
        $runStatus = $this->safeRunStatus($this->value($run, 'status'));

        return [
            'schema' => self::SCHEMA,
            'attempt_count' => $attemptCount,
            'retry_count' => max(0, $attemptCount - 1),
            'retried' => $attemptCount > 1,
            'recovered' => $runStatus === 'completed'
                && $failedAttempts > 0
                && ($finalAttempt['status'] ?? null) === 'success',
            'failed_attempts' => $failedAttempts,
            'timed_out_nodes' => $timedOutNodes,
            'attempts' => $attemptRows,
            'posture' => [
                'aggregate_only' => true,
                'raw_inputs_included' => false,
                'raw_outputs_included' => false,
                'raw_errors_included' => false,
            ],
        ];
    }

    private function value(object|array $row, string $key, mixed $default = null): mixed
    {
        return is_array($row) ? ($row[$key] ?? $default) : ($row->{$key} ?? $default);
    }

    private function safeState(mixed $state): string
    {
        return is_string($state) && in_array($state, self::STATES, true) ? $state : 'pending';
    }

    private function safeRunStatus(mixed $status): ?string
    {
        return is_string($status) && in_array($status, ['running', 'awaiting_approval', 'completed', 'failed'], true)
            ? $status
            : null;
    }

    private function safeTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp->format('Y-m-d H:i:s');
        }

        if (! is_string($timestamp)) {
            return null;
        }

        $timestamp = trim($timestamp);

        return preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\z/D', $timestamp) === 1
            ? $timestamp
            : null;
    }

    private function stateCountKey(string $state): string
    {
        return match ($state) {
            'success' => 'success_nodes',
            'failed' => 'failed_nodes',
            'skipped' => 'skipped_nodes',
            'running' => 'running_nodes',
            'awaiting_approval' => 'awaiting_approval_nodes',
            default => 'pending_nodes',
        };
    }

    /** @param array<string,mixed> $attempt */
    private function attemptStatus(array $attempt): string
    {
        if ($attempt['awaiting_approval_nodes'] > 0) {
            return 'awaiting_approval';
        }
        if ($attempt['failed_nodes'] > 0) {
            return 'failed';
        }
        if ($attempt['running_nodes'] > 0 || $attempt['pending_nodes'] > 0) {
            return 'running';
        }
        if ($attempt['node_count'] > 0
            && $attempt['node_count'] === $attempt['success_nodes'] + $attempt['skipped_nodes']) {
            return 'success';
        }

        return 'pending';
    }
}

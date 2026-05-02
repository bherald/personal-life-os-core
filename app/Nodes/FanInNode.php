<?php

namespace App\Nodes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Fan-In Node - Aggregate Parallel Branch Results
 *
 * Waits for all branches from a FanOutNode to complete, then aggregates their results.
 * This node can poll or be triggered when all branches are done.
 *
 * Configuration:
 * - fan_out_id_key: Key in input containing the fan_out_id (default: 'fan_out_id')
 * - timeout_seconds: Max time to wait for branches (default: 3600 = 1 hour)
 * - poll_interval_seconds: Polling interval when waiting (default: 5)
 * - aggregation_mode: How to combine results - 'array', 'merge', 'concat' (default: 'array')
 * - include_failed: Include failed branch results (default: false)
 * - require_all_success: Fail if any branch failed (default: true)
 *
 * Output:
 * - results: Aggregated results from all branches
 * - branch_stats: Statistics about branch execution
 */
class FanInNode extends BaseNode
{
    public function execute(array $input): array
    {
        $fanOutIdKey = $this->getConfigValue('fan_out_id_key', 'fan_out_id');
        $timeoutSeconds = (int) $this->getConfigValue('timeout_seconds', 3600);
        $pollInterval = (int) $this->getConfigValue('poll_interval_seconds', 5);
        $aggregationMode = $this->getConfigValue('aggregation_mode', 'array');
        $includeFailed = (bool) $this->getConfigValue('include_failed', false);
        $requireAllSuccess = (bool) $this->getConfigValue('require_all_success', true);

        // Extract fan_out_id from input
        $fanOutId = $this->extractFanOutId($input, $fanOutIdKey);

        if (!$fanOutId) {
            return $this->standardOutput(null, [], 'No fan_out_id found in input');
        }

        Log::info('FanInNode: Waiting for branches', [
            'fan_out_id' => $fanOutId,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $startTime = time();

        // Poll until all branches complete or timeout
        while (true) {
            $canExecute = $this->canExecute($fanOutId);

            if ($canExecute['ready']) {
                break;
            }

            // Check timeout
            if ((time() - $startTime) > $timeoutSeconds) {
                Log::error('FanInNode: Timeout waiting for branches', [
                    'fan_out_id' => $fanOutId,
                    'pending_count' => $canExecute['pending'],
                    'running_count' => $canExecute['running'],
                ]);

                return $this->standardOutput(null, [
                    'timeout' => true,
                    'pending' => $canExecute['pending'],
                    'running' => $canExecute['running'],
                ], 'Timeout waiting for branches to complete');
            }

            // Wait before polling again
            sleep($pollInterval);
        }

        // All branches complete, aggregate results
        return $this->aggregateResults($fanOutId, $aggregationMode, $includeFailed, $requireAllSuccess);
    }

    /**
     * Check if all branches are complete
     */
    public function canExecute(string $fanOutId): array
    {
        $states = DB::select(
            'SELECT state, COUNT(*) as count FROM node_executions
             WHERE parent_fan_out_id = ?
             GROUP BY state',
            [$fanOutId]
        );

        $stateCounts = [];
        foreach ($states as $row) {
            $stateCounts[$row->state] = $row->count;
        }

        $pending = $stateCounts['pending'] ?? 0;
        $running = $stateCounts['running'] ?? 0;
        $success = $stateCounts['success'] ?? 0;
        $failed = $stateCounts['failed'] ?? 0;
        $skipped = $stateCounts['skipped'] ?? 0;

        $total = $pending + $running + $success + $failed + $skipped;
        $complete = $success + $failed + $skipped;

        return [
            'ready' => $total > 0 && $pending === 0 && $running === 0,
            'total' => $total,
            'pending' => $pending,
            'running' => $running,
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'complete' => $complete,
        ];
    }

    /**
     * Aggregate results from all completed branches
     */
    private function aggregateResults(
        string $fanOutId,
        string $mode,
        bool $includeFailed,
        bool $requireAllSuccess
    ): array {
        // Build WHERE clause based on includeFailed setting
        $stateClause = $includeFailed
            ? "state IN ('success', 'failed')"
            : "state = 'success'";

        $branches = DB::select(
            "SELECT id, branch_index, state, input, output, duration_ms, error_message
             FROM node_executions
             WHERE parent_fan_out_id = ? AND {$stateClause}
             ORDER BY branch_index",
            [$fanOutId]
        );

        // Get total branch count including failed
        $allBranches = DB::select(
            'SELECT state, COUNT(*) as count FROM node_executions
             WHERE parent_fan_out_id = ?
             GROUP BY state',
            [$fanOutId]
        );

        $stats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($allBranches as $row) {
            if (isset($stats[$row->state])) {
                $stats[$row->state] = $row->count;
            }
        }

        // Check if we require all success
        if ($requireAllSuccess && $stats['failed'] > 0) {
            return $this->standardOutput(null, [
                'fan_out_id' => $fanOutId,
                'branch_stats' => $stats,
                'failed_branches' => $stats['failed'],
            ], "Fan-in failed: {$stats['failed']} branch(es) failed");
        }

        // Aggregate based on mode
        $results = [];
        $totalDuration = 0;

        foreach ($branches as $branch) {
            $output = $branch->output ? json_decode($branch->output, true) : null;
            $totalDuration += $branch->duration_ms ?? 0;

            $branchResult = [
                'branch_index' => $branch->branch_index,
                'state' => $branch->state,
                'output' => $output,
                'duration_ms' => $branch->duration_ms,
            ];

            if ($branch->state === 'failed') {
                $branchResult['error'] = $branch->error_message;
            }

            $results[] = $branchResult;
        }

        // Apply aggregation mode
        $aggregated = match ($mode) {
            'merge' => $this->mergeResults($results),
            'concat' => $this->concatResults($results),
            default => $results, // 'array' mode - return as-is
        };

        Log::info('FanInNode: Aggregation complete', [
            'fan_out_id' => $fanOutId,
            'branch_count' => count($branches),
            'total_duration_ms' => $totalDuration,
            'mode' => $mode,
        ]);

        return $this->standardOutput([
            'fan_out_id' => $fanOutId,
            'results' => $aggregated,
            'branch_count' => count($branches),
        ], [
            'branch_stats' => $stats,
            'total_duration_ms' => $totalDuration,
            'aggregation_mode' => $mode,
        ]);
    }

    /**
     * Extract fan_out_id from various input locations
     */
    private function extractFanOutId(array $input, string $key): ?string
    {
        // Direct key
        if (isset($input[$key])) {
            return $input[$key];
        }

        // In data
        if (isset($input['data'][$key])) {
            return $input['data'][$key];
        }

        // In data->data (nested)
        if (isset($input['data']['data'][$key])) {
            return $input['data']['data'][$key];
        }

        // From fan_out_id directly
        if (isset($input['data']['fan_out_id'])) {
            return $input['data']['fan_out_id'];
        }

        return null;
    }

    /**
     * Merge all branch outputs into a single object
     */
    private function mergeResults(array $results): array
    {
        $merged = [];
        foreach ($results as $result) {
            if (is_array($result['output'])) {
                $merged = array_merge($merged, $result['output']);
            }
        }
        return $merged;
    }

    /**
     * Concatenate string/array results from branches
     */
    private function concatResults(array $results): array
    {
        $concatenated = [];
        foreach ($results as $result) {
            if (isset($result['output']['data'])) {
                if (is_array($result['output']['data'])) {
                    $concatenated = array_merge($concatenated, $result['output']['data']);
                } else {
                    $concatenated[] = $result['output']['data'];
                }
            } elseif (is_array($result['output'])) {
                $concatenated = array_merge($concatenated, $result['output']);
            }
        }
        return $concatenated;
    }
}

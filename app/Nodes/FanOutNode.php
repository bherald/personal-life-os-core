<?php

namespace App\Nodes;

use App\Jobs\ExecuteNodeJob;
use App\Services\SystemConfigService;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fan-Out Node - Parallel Branch Execution
 *
 * Takes an array input and creates parallel branch executions for each item.
 * Each branch runs independently via queued jobs.
 *
 * Configuration:
 * - input_key: Key in input data containing the array to fan out (default: 'items')
 * - target_node_type: Node type to execute for each item (required)
 * - target_node_config: Configuration to pass to target nodes (optional)
 * - max_parallel: Maximum parallel branches (default: 10)
 * - http_pool_enabled: Enable Http::pool for parallel API calls (default: false)
 * - http_pool_endpoint: API endpoint for Http::pool mode
 *
 * Output:
 * - fan_out_id: UUID identifying this fan-out operation
 * - branch_count: Number of branches created
 * - branches: Array of branch execution IDs
 */
class FanOutNode extends BaseNode
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'workflow.fanout_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'max_parallel' => 10,
        'http_pool_timeout_seconds' => 30,
    ];

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function execute(array $input): array
    {
        try {
            $inputKey = $this->getConfigValue('input_key', 'items');
            $targetNodeType = $this->getConfigValue('target_node_type');
            $targetNodeConfig = $this->getConfigValue('target_node_config', []);
            $maxParallel = $this->configuredPositiveInt('max_parallel') ?? $this->runtimePolicyInt('max_parallel');
            $httpPoolEnabled = (bool) $this->getConfigValue('http_pool_enabled', false);
            $httpPoolEndpoint = $this->getConfigValue('http_pool_endpoint');

            if (! $targetNodeType && ! $httpPoolEnabled) {
                return $this->standardOutput(null, [], 'target_node_type or http_pool_enabled is required');
            }

            // Extract items from input
            $items = $this->extractItems($input, $inputKey);

            if (empty($items)) {
                Log::info('FanOutNode: No items to fan out', ['input_key' => $inputKey]);

                return $this->standardOutput([
                    'fan_out_id' => null,
                    'branch_count' => 0,
                    'branches' => [],
                    'skipped' => true,
                ], ['reason' => 'no_items']);
            }

            // Limit parallel branches
            if (count($items) > $maxParallel) {
                Log::warning('FanOutNode: Limiting branches', [
                    'requested' => count($items),
                    'max' => $maxParallel,
                ]);
                $items = array_slice($items, 0, $maxParallel);
            }

            // If Http::pool mode, execute synchronously with parallel HTTP calls
            if ($httpPoolEnabled && $httpPoolEndpoint) {
                return $this->executeHttpPool($items, $httpPoolEndpoint, $input);
            }

            // Generate unique fan-out ID
            $fanOutId = (string) Str::uuid();

            // Get run_id from input context
            $runId = $input['_run_id'] ?? $input['run_id'] ?? 0;
            $attemptNumber = max(1, min(1000, (int) $this->getConfigValue('workflow_attempt_number', 1)));

            Log::info('FanOutNode: Creating parallel branches', [
                'fan_out_id' => $fanOutId,
                'item_count' => count($items),
                'target_node' => $targetNodeType,
            ]);

            $branches = [];

            foreach ($items as $index => $item) {
                // Create node execution record for this branch
                DB::insert(
                    'INSERT INTO node_executions
                    (run_id, workflow_node_id, node_type, node_order, attempt_number, branch_index, parent_fan_out_id, state, input, executed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $runId,
                        0, // workflow_node_id - not directly linked to workflow_nodes table
                        $targetNodeType,
                        $index,
                        $attemptNumber,
                        $index,
                        $fanOutId,
                        'pending',
                        json_encode($item),
                        now(),
                    ]
                );

                $nodeExecutionId = (int) DB::getPdo()->lastInsertId();

                // Build input for branch (item + context from parent)
                $branchInput = is_array($item) ? $item : ['value' => $item];
                $branchInput['_branch_index'] = $index;
                $branchInput['_parent_fan_out_id'] = $fanOutId;
                $branchInput['_run_id'] = $runId;

                // Merge parent context (exclude large data arrays)
                foreach ($input as $key => $value) {
                    if (! in_array($key, [$inputKey, 'data', 'streams']) && ! str_starts_with($key, '_')) {
                        $branchInput[$key] = $value;
                    }
                }

                // Dispatch job for async execution
                dispatch(new ExecuteNodeJob(
                    $runId,
                    $nodeExecutionId,
                    $targetNodeType,
                    $targetNodeConfig,
                    $branchInput,
                    $index,
                    $fanOutId
                ));

                $branches[] = [
                    'execution_id' => $nodeExecutionId,
                    'branch_index' => $index,
                ];
            }

            return $this->standardOutput([
                'fan_out_id' => $fanOutId,
                'branch_count' => count($branches),
                'branches' => $branches,
                'target_node_type' => $targetNodeType,
            ], [
                'parallel_execution' => true,
                'items_processed' => count($items),
                'max_parallel' => $maxParallel,
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            ]);
        } catch (\Throwable $e) {
            Log::error('FanOutNode: Unhandled exception', [
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput(null, ['error' => true], $e->getMessage());
        }
    }

    /**
     * Extract items array from input
     */
    private function extractItems(array $input, string $key): array
    {
        // Direct key
        if (isset($input[$key]) && is_array($input[$key])) {
            return array_values($input[$key]);
        }

        // Nested in data
        if (isset($input['data'][$key]) && is_array($input['data'][$key])) {
            return array_values($input['data'][$key]);
        }

        // If key is 'items' and we have a flat array, use it
        if ($key === 'items' && isset($input['data']) && is_array($input['data']) && ! isset($input['data']['items'])) {
            // Check if data itself is a sequential array
            if (array_is_list($input['data'])) {
                return $input['data'];
            }
        }

        return [];
    }

    /**
     * Execute parallel API calls using Laravel Http::pool
     *
     * This is useful when you need to make multiple API calls in parallel
     * within a single node execution, rather than spawning queued jobs.
     */
    private function executeHttpPool(array $items, string $endpoint, array $parentInput): array
    {
        $fanOutId = (string) Str::uuid();
        $method = $this->getConfigValue('http_method', 'POST');
        $headers = $this->getConfigValue('http_headers', []);
        $timeout = $this->configuredPositiveInt('http_timeout')
            ?? $this->runtimePolicyInt('http_pool_timeout_seconds');

        Log::info('FanOutNode: Executing Http::pool', [
            'fan_out_id' => $fanOutId,
            'item_count' => count($items),
            'endpoint' => $endpoint,
        ]);

        $responses = Http::pool(function (Pool $pool) use ($items, $endpoint, $method, $headers, $timeout) {
            $requests = [];
            foreach ($items as $index => $item) {
                $request = $pool->as("item_{$index}")
                    ->timeout($timeout)
                    ->withHeaders($headers);

                if (strtoupper($method) === 'POST') {
                    $requests[] = $request->post($endpoint, is_array($item) ? $item : ['data' => $item]);
                } else {
                    $requests[] = $request->get($endpoint, is_array($item) ? $item : ['data' => $item]);
                }
            }

            return $requests;
        });

        // Collect results
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($items as $index => $item) {
            $key = "item_{$index}";
            $response = $responses[$key] ?? null;

            if ($response && $response->successful()) {
                $results[] = [
                    'branch_index' => $index,
                    'status' => 'success',
                    'data' => $response->json(),
                ];
                $successCount++;
            } else {
                $results[] = [
                    'branch_index' => $index,
                    'status' => 'failed',
                    'error' => $response?->body() ?? 'Request failed',
                    'status_code' => $response?->status() ?? 0,
                ];
                $failureCount++;
            }
        }

        return $this->standardOutput([
            'fan_out_id' => $fanOutId,
            'results' => $results,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'http_pool' => true,
        ], [
            'execution_mode' => 'http_pool',
            'endpoint' => $endpoint,
            'http_timeout_seconds' => $timeout,
            'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
        ]);
    }

    private function configuredPositiveInt(string $key): ?int
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }
}

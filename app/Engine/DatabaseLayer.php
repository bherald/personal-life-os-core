<?php

namespace App\Engine;

use Illuminate\Support\Facades\DB;

class DatabaseLayer
{
    /**
     * Create a workflow run with optional idempotency key
     *
     * @param int $workflowId Workflow ID
     * @param array $inputData Input data for the workflow
     * @param string|null $idempotencyKey Optional explicit idempotency key
     * @return int Run ID
     */
    public function createWorkflowRun(int $workflowId, array $inputData = [], ?string $idempotencyKey = null): int
    {
        // Auto-generate idempotency key if not provided and input exists
        if ($idempotencyKey === null && !empty($inputData)) {
            $idempotencyKey = $this->generateIdempotencyKey($workflowId, $inputData);
        }

        DB::insert("INSERT INTO workflow_runs (workflow_id, status, started_at, idempotency_key) VALUES (?, ?, ?, ?)", [
            $workflowId, 'running', now(), $idempotencyKey
        ]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Generate idempotency key from workflow ID and input data
     *
     * @param int $workflowId Workflow ID
     * @param array $inputData Input data to hash
     * @return string SHA256 hash
     */
    public function generateIdempotencyKey(int $workflowId, array $inputData): string
    {
        // Sort keys for consistent hashing
        $inputData = $this->recursiveKeySort($inputData);
        $normalized = json_encode($inputData);
        return hash('sha256', $workflowId . ':' . $normalized);
    }

    /**
     * Recursively sort array keys for consistent hashing
     */
    private function recursiveKeySort(array $array): array
    {
        ksort($array);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->recursiveKeySort($value);
            }
        }
        return $array;
    }

    /**
     * Check if a workflow run with the given idempotency key already exists
     *
     * @param string $idempotencyKey The key to check
     * @return object|null Existing run if found
     */
    public function findRunByIdempotencyKey(string $idempotencyKey): ?object
    {
        return DB::selectOne(
            "SELECT * FROM workflow_runs WHERE idempotency_key = ? ORDER BY id DESC LIMIT 1",
            [$idempotencyKey]
        );
    }

    /**
     * Check if a workflow execution should be skipped due to existing idempotent run
     *
     * @param int $workflowId Workflow ID
     * @param array $inputData Input data
     * @param string|null $explicitKey Optional explicit key
     * @return array ['skip' => bool, 'existing_run' => object|null, 'key' => string]
     */
    public function checkIdempotency(int $workflowId, array $inputData, ?string $explicitKey = null): array
    {
        $key = $explicitKey ?? $this->generateIdempotencyKey($workflowId, $inputData);
        $existingRun = $this->findRunByIdempotencyKey($key);

        return [
            'skip' => $existingRun !== null && $existingRun->status === 'completed',
            'existing_run' => $existingRun,
            'key' => $key,
        ];
    }

    public function updateWorkflowRun(int $runId, string $status, ?string $errorMessage = null): void
    {
        DB::update("UPDATE workflow_runs SET status = ?, error_message = ?, completed_at = ? WHERE id = ?", [
            $status, $this->sanitizeForDatabase($errorMessage), now(), $runId
        ]);
    }

    public function logWorkflowRunInputs(int $runId, array $inputs): void
    {
        foreach ($inputs as $key => $value) {
            DB::insert("INSERT INTO workflow_run_inputs (run_id, input_key, input_value) VALUES (?, ?, ?)", [
                $runId, $key, is_array($value) ? json_encode($value) : $value
            ]);
        }
    }

    public function logWorkflowRunOutputs(int $runId, array $outputs): void
    {
        foreach ($outputs as $key => $value) {
            try {
                $sanitizedValue = is_array($value) ? json_encode($value) : $this->sanitizeForDatabase($value);
                DB::insert("INSERT INTO workflow_run_outputs (run_id, output_key, output_value) VALUES (?, ?, ?)", [
                    $runId, $key, $sanitizedValue
                ]);
            } catch (\Exception $e) {
                \Log::warning("Failed to log workflow run output", [
                    'run_id' => $runId,
                    'key' => $key,
                    'error' => mb_substr($e->getMessage(), 0, 200)
                ]);
            }
        }
    }

    public function createNodeExecution(int $runId, int $workflowNodeId, string $nodeType, int $nodeOrder): int
    {
        DB::insert("INSERT INTO node_executions (run_id, workflow_node_id, node_type, node_order, executed_at) VALUES (?, ?, ?, ?, ?)", [
            $runId, $workflowNodeId, $nodeType, $nodeOrder, now()
        ]);
        return (int) DB::getPdo()->lastInsertId();
    }

    public function updateNodeExecution(int $executionId, int $durationMs, ?string $errorMessage = null): void
    {
        DB::update("UPDATE node_executions SET duration_ms = ?, error_message = ? WHERE id = ?", [
            $durationMs, $this->sanitizeForDatabase($errorMessage), $executionId
        ]);
    }

    public function logNodeExecutionInputs(int $executionId, array $inputs): void
    {
        foreach ($inputs as $key => $value) {
            DB::insert("INSERT INTO node_execution_inputs (node_execution_id, input_key, input_value) VALUES (?, ?, ?)", [
                $executionId, $key, is_array($value) ? json_encode($value) : $value
            ]);
        }
    }

    public function logNodeExecutionOutputs(int $executionId, array $outputs, string $stream = 'default'): void
    {
        foreach ($outputs as $key => $value) {
            try {
                $sanitizedValue = is_array($value) ? json_encode($value) : $this->sanitizeForDatabase($value);
                DB::insert("INSERT INTO node_execution_outputs (node_execution_id, output_stream, output_key, output_value) VALUES (?, ?, ?, ?)", [
                    $executionId, $stream, $key, $sanitizedValue
                ]);
            } catch (\Exception $e) {
                \Log::warning("Failed to log node execution output", [
                    'execution_id' => $executionId,
                    'key' => $key,
                    'error' => mb_substr($e->getMessage(), 0, 200)
                ]);
            }
        }
    }

    public function logNodeExecutionMeta(int $executionId, array $meta): void
    {
        foreach ($meta as $key => $value) {
            DB::insert("INSERT INTO node_execution_meta (node_execution_id, meta_key, meta_value) VALUES (?, ?, ?)", [
                $executionId, $key, is_array($value) ? json_encode($value) : $value
            ]);
        }
    }

    public function getWorkflow(string $name): ?object
    {
        return DB::selectOne("SELECT * FROM workflows WHERE name = ?", [$name]);
    }

    public function getWorkflowNodes(int $workflowId): array
    {
        return DB::select("SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order", [$workflowId]);
    }

    /**
     * Get node timeout setting
     *
     * @param int $nodeId Workflow node ID
     * @param int $defaultTimeout Default timeout in seconds (300 = 5 minutes)
     * @return int Timeout in seconds
     */
    public function getNodeTimeout(int $nodeId, int $defaultTimeout = 300): int
    {
        $node = DB::selectOne(
            "SELECT timeout_seconds FROM workflow_nodes WHERE id = ?",
            [$nodeId]
        );

        return $node->timeout_seconds ?? $defaultTimeout;
    }

    /**
     * Set node timeout
     *
     * @param int $nodeId Workflow node ID
     * @param int|null $timeoutSeconds Timeout in seconds (NULL = use default)
     */
    public function setNodeTimeout(int $nodeId, ?int $timeoutSeconds): void
    {
        DB::update(
            "UPDATE workflow_nodes SET timeout_seconds = ? WHERE id = ?",
            [$timeoutSeconds, $nodeId]
        );
    }

    /**
     * Update node execution with timeout information
     *
     * @param int $executionId Node execution ID
     * @param int $durationMs Execution duration in milliseconds
     * @param string|null $errorMessage Error message if failed
     * @param int|null $timeoutSeconds Timeout that was applied
     * @param bool $timedOut Whether execution was terminated due to timeout
     */
    public function updateNodeExecutionWithTimeout(
        int $executionId,
        int $durationMs,
        ?string $errorMessage = null,
        ?int $timeoutSeconds = null,
        bool $timedOut = false
    ): void {
        DB::update(
            "UPDATE node_executions SET duration_ms = ?, error_message = ?, timeout_seconds = ?, timed_out = ?, state = ? WHERE id = ?",
            [
                $durationMs,
                $this->sanitizeForDatabase($errorMessage),
                $timeoutSeconds,
                $timedOut ? 1 : 0,
                $timedOut ? 'failed' : ($errorMessage ? 'failed' : 'success'),
                $executionId
            ]
        );
    }

    public function getNodeConfigs(int $nodeId): array
    {
        $configs = DB::select("SELECT config_key, config_value FROM workflow_node_configs WHERE workflow_node_id = ?", [$nodeId]);

        $result = [];
        foreach ($configs as $config) {
            $value = $config->config_value;

            // Auto-decode JSON strings to arrays/objects
            if (is_string($value) && $this->looksLikeJson($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                    \Log::debug("DatabaseLayer: Decoded JSON config", [
                        'node_id' => $nodeId,
                        'config_key' => $config->config_key,
                        'original_length' => strlen($config->config_value),
                        'decoded_type' => gettype($value)
                    ]);
                }
            }

            $result[$config->config_key] = $value;
        }
        return $result;
    }

    /**
     * Check if a string looks like JSON (starts with [ or {)
     */
    private function looksLikeJson(string $value): bool
    {
        $trimmed = trim($value);
        return !empty($trimmed) &&
               ($trimmed[0] === '{' || $trimmed[0] === '[');
    }

    public function getWorkflowDefaults(int $workflowId): array
    {
        $defaults = DB::select("SELECT config_key, config_value FROM workflow_defaults WHERE workflow_id = ?", [$workflowId]);

        $result = [];
        foreach ($defaults as $default) {
            $value = $default->config_value;

            // Auto-decode JSON strings to arrays/objects
            if (is_string($value) && $this->looksLikeJson($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $result[$default->config_key] = $value;
        }
        return $result;
    }

    public function getRetryConfig(int $workflowId): ?object
    {
        return DB::selectOne("SELECT * FROM retry_configs WHERE workflow_id = ?", [$workflowId]);
    }

    public function getRetryBackoffIntervals(int $retryConfigId): array
    {
        $rows = DB::select("SELECT backoff_seconds FROM retry_backoff_intervals WHERE retry_config_id = ? ORDER BY attempt_number", [$retryConfigId]);
        return array_column($rows, 'backoff_seconds');
    }

    public function saveRetryConfig(int $workflowId, array $config): void
    {
        // Delete existing retry config
        $this->deleteRetryConfig($workflowId);

        // Insert new retry config
        DB::insert("INSERT INTO retry_configs (workflow_id, max_attempts, notify_on_failure, backoff_strategy) VALUES (?, ?, ?, ?)", [
            $workflowId,
            $config['max_attempts'] ?? 3,
            $config['notify_on_failure'] ?? '',
            $config['backoff_strategy'] ?? 'exponential'
        ]);
        $retryConfigId = (int) DB::getPdo()->lastInsertId();

        // Generate and insert backoff intervals based on strategy
        $intervals = $this->calculateBackoffIntervals(
            $config['backoff_strategy'] ?? 'exponential',
            $config['max_attempts'] ?? 3
        );

        foreach ($intervals as $attemptNumber => $backoffSeconds) {
            DB::insert("INSERT INTO retry_backoff_intervals (retry_config_id, attempt_number, backoff_seconds) VALUES (?, ?, ?)", [
                $retryConfigId, $attemptNumber + 1, $backoffSeconds
            ]);
        }
    }

    public function deleteRetryConfig(int $workflowId): void
    {
        $retryConfig = DB::selectOne("SELECT id FROM retry_configs WHERE workflow_id = ?", [$workflowId]);
        if ($retryConfig) {
            DB::delete("DELETE FROM retry_backoff_intervals WHERE retry_config_id = ?", [$retryConfig->id]);
            DB::delete("DELETE FROM retry_configs WHERE id = ?", [$retryConfig->id]);
        }
    }

    private function calculateBackoffIntervals(string $strategy, int $maxAttempts): array
    {
        $intervals = [];

        switch ($strategy) {
            case 'exponential':
                for ($i = 0; $i < $maxAttempts; $i++) {
                    $intervals[] = (int) (5 * pow(3, $i));
                }
                break;

            case 'linear':
                for ($i = 0; $i < $maxAttempts; $i++) {
                    $intervals[] = 10 * ($i + 1);
                }
                break;

            case 'fixed':
                for ($i = 0; $i < $maxAttempts; $i++) {
                    $intervals[] = 10;
                }
                break;

            default:
                for ($i = 0; $i < $maxAttempts; $i++) {
                    $intervals[] = (int) (5 * pow(3, $i));
                }
        }

        return $intervals;
    }

    public function getActiveWorkflows(): array
    {
        return DB::select("SELECT * FROM workflows WHERE active = 1 AND schedule IS NOT NULL");
    }

    public function getAllWorkflows(): array
    {
        return DB::select("SELECT * FROM workflows");
    }

    /**
     * Sanitize text for database storage
     */
    private function sanitizeForDatabase(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        if (strlen($text) > 65000) {
            $text = mb_substr($text, 0, 65000) . '... [truncated]';
        }

        return $text;
    }
}

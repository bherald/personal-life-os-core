<?php

namespace App\Nodes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Sub-Workflow Node - Execute a child workflow within the current workflow
 *
 * Enables hierarchical workflow composition where complex processes
 * can be broken into reusable sub-workflows.
 *
 * Configuration:
 * - workflow_id: Target workflow ID to execute (required)
 * - input_mapping: Map parent context keys to child input keys (optional)
 * - output_mapping: Map child output keys to parent context keys (optional)
 * - wait_for_completion: Whether to wait for child to complete (default: true)
 * - timeout: Max seconds to wait for completion (default: 3600)
 *
 * Output:
 * - child_run_id: ID of the child workflow run
 * - child_status: Status of child workflow (completed/failed/running)
 * - child_output: Output from child workflow (if wait_for_completion=true)
 */
class SubWorkflowNode extends BaseNode
{
    public function execute(array $input): array
    {
        $workflowId = $this->getConfigValue('workflow_id');
        $inputMapping = $this->getConfigValue('input_mapping', []);
        $outputMapping = $this->getConfigValue('output_mapping', []);
        $waitForCompletion = (bool) $this->getConfigValue('wait_for_completion', true);
        $timeout = (int) $this->getConfigValue('timeout', 3600);

        // Validation
        $errors = $this->validate();
        if (!empty($errors)) {
            return $this->standardOutput(null, [], implode('; ', $errors));
        }

        // Get parent context
        $parentRunId = $input['_run_id'] ?? $input['run_id'] ?? null;
        $parentNodeExecutionId = $input['_node_execution_id'] ?? null;
        $currentDepth = $input['_depth'] ?? 0;

        // Check max depth
        $maxDepth = $this->getMaxDepth();
        if ($currentDepth >= $maxDepth) {
            Log::warning('SubWorkflowNode: Max depth exceeded', [
                'current_depth' => $currentDepth,
                'max_depth' => $maxDepth,
                'workflow_id' => $workflowId,
            ]);
            return $this->standardOutput(null, [], "Maximum workflow depth ({$maxDepth}) exceeded");
        }

        // Check for circular references
        if ($parentRunId) {
            $circularCheck = $this->checkCircularReference($parentRunId, $workflowId);
            if ($circularCheck) {
                Log::warning('SubWorkflowNode: Circular reference detected', [
                    'parent_run_id' => $parentRunId,
                    'target_workflow_id' => $workflowId,
                    'cycle_path' => $circularCheck,
                ]);
                return $this->standardOutput(null, [], "Circular reference detected: {$circularCheck}");
            }
        }

        // Build child input from mapping
        $childInput = $this->mapInput($input, $inputMapping);
        $childInput['_depth'] = $currentDepth + 1;

        try {
            // Get target workflow
            $workflow = DB::selectOne('SELECT * FROM workflows WHERE id = ?', [$workflowId]);
            if (!$workflow) {
                return $this->standardOutput(null, [], "Target workflow not found: {$workflowId}");
            }

            if (!$workflow->active) {
                return $this->standardOutput(null, [], "Target workflow is not active: {$workflow->name}");
            }

            Log::info('SubWorkflowNode: Starting child workflow', [
                'parent_run_id' => $parentRunId,
                'child_workflow_id' => $workflowId,
                'child_workflow_name' => $workflow->name,
                'depth' => $currentDepth + 1,
                'wait' => $waitForCompletion,
            ]);

            // Create child workflow run
            $childRunId = $this->createChildRun(
                $workflowId,
                $parentRunId,
                $parentNodeExecutionId,
                $currentDepth + 1
            );

            // Log child inputs
            try {
                $this->logRunInputs($childRunId, $childInput);
            } catch (\Throwable $e) {
                Log::warning('SubWorkflowNode: Failed to log run inputs', [
                    'child_run_id' => $childRunId,
                    'error' => $e->getMessage(),
                ]);
            }
            // Execute child workflow nodes
            $childOutput = $this->executeChildWorkflow($workflow, $childRunId, $childInput);

            // Update child run status
            $this->updateRunStatus($childRunId, 'completed');
            try {
                $this->logRunOutputs($childRunId, $childOutput);
            } catch (\Throwable $e) {
                Log::warning('SubWorkflowNode: Failed to log run outputs', [
                    'child_run_id' => $childRunId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Map output back to parent context
            $mappedOutput = $this->mapOutput($childOutput, $outputMapping);

            Log::info('SubWorkflowNode: Child workflow completed', [
                'child_run_id' => $childRunId,
                'child_workflow_name' => $workflow->name,
            ]);

            return $this->standardOutput([
                'child_run_id' => $childRunId,
                'child_status' => 'completed',
                'child_output' => $mappedOutput,
            ], [
                'workflow_name' => $workflow->name,
                'depth' => $currentDepth + 1,
            ]);

        } catch (Exception $e) {
            $this->updateRunStatus($childRunId, 'failed', $e->getMessage());

            Log::error('SubWorkflowNode: Child workflow failed', [
                'child_run_id' => $childRunId,
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput([
                'child_run_id' => $childRunId,
                'child_status' => 'failed',
                'child_output' => null,
            ], [], $e->getMessage());
        }
    }

    /**
     * Validate node configuration
     */
    public function validate(): array
    {
        $errors = [];

        $workflowId = $this->getConfigValue('workflow_id');
        if (empty($workflowId)) {
            $errors[] = 'workflow_id is required';
            return $errors;
        }

        // Check workflow exists
        $workflow = DB::selectOne('SELECT id, name, active FROM workflows WHERE id = ?', [$workflowId]);
        if (!$workflow) {
            $errors[] = "Target workflow not found: {$workflowId}";
        }

        return $errors;
    }

    /**
     * Get the type identifier for this node
     */
    public function getType(): string
    {
        return 'sub_workflow';
    }

    /**
     * Get default configuration for this node type
     */
    public static function getDefaultConfig(): array
    {
        return [
            'workflow_id' => null,
            'input_mapping' => [],
            'output_mapping' => [],
            'wait_for_completion' => true,
            'timeout' => 3600,
        ];
    }

    /**
     * Get max depth from system config
     */
    private function getMaxDepth(): int
    {
        $config = DB::selectOne(
            'SELECT config_value FROM system_configs WHERE config_key = ?',
            ['workflow_max_depth']
        );

        return $config ? (int) $config->config_value : 5;
    }

    /**
     * Check for circular reference in workflow chain
     *
     * Returns the cycle path if detected, null otherwise
     */
    private function checkCircularReference(int $runId, int $targetWorkflowId): ?string
    {
        $visited = [];
        $currentRunId = $runId;

        while ($currentRunId) {
            $run = DB::selectOne(
                'SELECT workflow_id, parent_run_id FROM workflow_runs WHERE id = ?',
                [$currentRunId]
            );

            if (!$run) {
                break;
            }

            // Check if target workflow already in chain
            if ($run->workflow_id == $targetWorkflowId) {
                $visited[] = $targetWorkflowId;
                return implode(' -> ', array_reverse($visited)) . " -> {$targetWorkflowId}";
            }

            $visited[] = $run->workflow_id;
            $currentRunId = $run->parent_run_id;

            // Safety limit
            if (count($visited) > 20) {
                break;
            }
        }

        return null;
    }

    /**
     * Map input from parent context to child input
     */
    private function mapInput(array $parentInput, array $mapping): array
    {
        if (empty($mapping)) {
            // Pass through data and meta, exclude internal keys
            $childInput = [];
            foreach ($parentInput as $key => $value) {
                if (!str_starts_with($key, '_') && !in_array($key, ['run_id', 'node_execution_id'])) {
                    $childInput[$key] = $value;
                }
            }
            return $childInput;
        }

        $childInput = [];
        foreach ($mapping as $parentKey => $childKey) {
            if (isset($parentInput[$parentKey])) {
                $childInput[$childKey] = $parentInput[$parentKey];
            } elseif (isset($parentInput['data'][$parentKey])) {
                $childInput[$childKey] = $parentInput['data'][$parentKey];
            }
        }

        return $childInput;
    }

    /**
     * Map output from child to parent context
     */
    private function mapOutput(array $childOutput, array $mapping): array
    {
        if (empty($mapping)) {
            return $childOutput;
        }

        $parentOutput = [];
        foreach ($mapping as $childKey => $parentKey) {
            if (isset($childOutput[$childKey])) {
                $parentOutput[$parentKey] = $childOutput[$childKey];
            } elseif (isset($childOutput['data'][$childKey])) {
                $parentOutput[$parentKey] = $childOutput['data'][$childKey];
            }
        }

        return $parentOutput;
    }

    /**
     * Create a child workflow run record
     */
    private function createChildRun(int $workflowId, ?int $parentRunId, ?int $parentNodeExecutionId, int $depth): int
    {
        DB::insert(
            'INSERT INTO workflow_runs (workflow_id, status, parent_run_id, parent_node_execution_id, depth, started_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$workflowId, 'running', $parentRunId, $parentNodeExecutionId, $depth, now()]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update workflow run status
     */
    private function updateRunStatus(int $runId, string $status, ?string $errorMessage = null): void
    {
        DB::update(
            'UPDATE workflow_runs SET status = ?, error_message = ?, completed_at = ? WHERE id = ?',
            [$status, $errorMessage, now(), $runId]
        );
    }

    /**
     * Log workflow run inputs
     */
    private function logRunInputs(int $runId, array $inputs): void
    {
        foreach ($inputs as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue; // Skip internal keys
            }
            DB::insert(
                'INSERT INTO workflow_run_inputs (run_id, input_key, input_value) VALUES (?, ?, ?)',
                [$runId, $key, is_array($value) ? json_encode($value) : $value]
            );
        }
    }

    /**
     * Log workflow run outputs
     */
    private function logRunOutputs(int $runId, array $outputs): void
    {
        foreach ($outputs as $key => $value) {
            DB::insert(
                'INSERT INTO workflow_run_outputs (run_id, output_key, output_value) VALUES (?, ?, ?)',
                [$runId, $key, is_array($value) ? json_encode($value) : $value]
            );
        }
    }

    /**
     * Execute child workflow nodes
     */
    private function executeChildWorkflow(object $workflow, int $runId, array $input): array
    {
        $nodes = DB::select(
            'SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order',
            [$workflow->id]
        );

        $currentInput = $input;
        $currentInput['_run_id'] = $runId;

        foreach ($nodes as $node) {
            $currentInput = $this->executeChildNode($node, $currentInput, $runId, $workflow->id);
        }

        return $currentInput;
    }

    /**
     * Execute a single child node
     */
    private function executeChildNode(object $node, array $input, int $runId, int $workflowId): array
    {
        $startTime = microtime(true);

        // Get node configuration
        $configs = DB::select(
            'SELECT config_key, config_value FROM workflow_node_configs WHERE workflow_node_id = ?',
            [$node->id]
        );

        $nodeConfig = [];
        foreach ($configs as $config) {
            $value = $config->config_value;
            if (is_string($value) && ($value[0] ?? '') === '{' || ($value[0] ?? '') === '[') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }
            $nodeConfig[$config->config_key] = $value;
        }

        // Create node execution record
        DB::insert(
            'INSERT INTO node_executions (run_id, workflow_node_id, node_type, node_order, executed_at) VALUES (?, ?, ?, ?, ?)',
            [$runId, $node->id, $node->node_type, $node->node_order, now()]
        );
        $executionId = (int) DB::getPdo()->lastInsertId();

        // Pass execution context
        $input['_node_execution_id'] = $executionId;

        try {
            // Load and execute node
            $nodeClass = $this->resolveNodeClass($node->node_type);
            $nodeInstance = new $nodeClass($nodeConfig);
            $output = $nodeInstance->execute($input);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            DB::update(
                'UPDATE node_executions SET duration_ms = ? WHERE id = ?',
                [$durationMs, $executionId]
            );

            // Handle standard output format
            if (isset($output['data']) && !isset($output['error'])) {
                return array_merge($input, $output['data']);
            }

            return $output;

        } catch (Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            DB::update(
                'UPDATE node_executions SET duration_ms = ?, error_message = ? WHERE id = ?',
                [$durationMs, $e->getMessage(), $executionId]
            );

            throw $e;
        }
    }

    /**
     * Resolve node class from type
     */
    private function resolveNodeClass(string $nodeType): string
    {
        // Convert snake_case to PascalCase
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $nodeType)));

        // Check various locations
        $candidates = [
            "App\\Nodes\\{$className}",
            "App\\Nodes\\{$className}Node",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        throw new Exception("Node class not found for type: {$nodeType}");
    }
}

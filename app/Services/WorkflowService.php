<?php

namespace App\Services;

use App\Engine\WorkflowEngine;
use App\Services\WorkflowEventService;
use App\Services\WorkflowReplayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Exception;

/**
 * Workflow Service
 *
 * Provides workflow operations for MCP tools and API.
 * Wraps WorkflowEngine with a service layer for external access.
 * Uses direct SQL queries for safety.
 */
class WorkflowService
{
    private WorkflowEngine $engine;
    private WorkflowEventService $eventService;
    private WorkflowReplayer $replayer;

    public function __construct()
    {
        $this->eventService = new WorkflowEventService();
        $this->replayer = new WorkflowReplayer($this->eventService);
        $this->engine = new WorkflowEngine($this->eventService, $this->replayer);
    }

    /**
     * Get all workflows
     *
     * @param bool $activeOnly Filter to only active workflows
     * @return array List of workflows
     */
    public function getAllWorkflows(bool $activeOnly = false): array
    {
        $query = "SELECT w.*,
                         EXISTS(
                             SELECT 1
                             FROM scheduled_jobs sj
                             WHERE sj.job_type = 'workflow'
                               AND sj.command = w.name
                               AND sj.enabled = 1
                               AND sj.cron_expression IS NOT NULL
                         ) AS has_schedule
                  FROM workflows w";

        if ($activeOnly) {
            $query .= " WHERE w.active = 1";
        }

        $query .= " ORDER BY w.name";

        $workflows = DB::select($query);

        return array_map(function ($workflow) {
            // Get node count
            $nodeCount = DB::scalar(
                "SELECT COUNT(*) FROM workflow_nodes WHERE workflow_id = ?",
                [$workflow->id]
            );

            return [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'is_active' => (bool) $workflow->active,
                'trigger_type' => !empty($workflow->has_schedule) ? 'schedule' : 'manual',
                'node_count' => $nodeCount,
                'created_at' => date('c', strtotime($workflow->created_at)),
                'updated_at' => date('c', strtotime($workflow->updated_at)),
            ];
        }, $workflows);
    }

    /**
     * Get workflow by name
     *
     * @param string $name Workflow name
     * @return array Workflow details
     */
    public function getWorkflowByName(string $name): array
    {
        $workflow = DB::selectOne(
            "SELECT * FROM workflows WHERE name = ? LIMIT 1",
            [$name]
        );

        if (!$workflow) {
            throw new Exception("Workflow not found: {$name}");
        }

        // Get nodes
        $nodes = DB::select(
            "SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order",
            [$workflow->id]
        );

        // Get execution count
        $executionCount = DB::scalar(
            "SELECT COUNT(*) FROM workflow_runs WHERE workflow_id = ?",
            [$workflow->id]
        );

        // Get last run
        $lastRun = DB::selectOne(
            "SELECT started_at FROM workflow_runs WHERE workflow_id = ? ORDER BY started_at DESC LIMIT 1",
            [$workflow->id]
        );

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'is_active' => (bool) $workflow->active,
            'trigger_type' => $workflow->schedule ? 'schedule' : 'manual',
            'trigger_config' => $workflow->schedule ? ['cron' => $workflow->schedule] : null,
            'definition' => [
                'nodes' => array_map(function ($node) {
                    $configs = DB::select(
                        "SELECT config_key, config_value FROM workflow_node_configs WHERE workflow_node_id = ?",
                        [$node->id]
                    );

                    $config = [];
                    foreach ($configs as $entry) {
                        $config[$entry->config_key] = $this->decodeStoredValue($entry->config_value);
                    }

                    return [
                        'id' => $node->id,
                        'type' => $node->node_type,
                        'config' => $config,
                        'position' => $node->node_order,
                    ];
                }, $nodes),
            ],
            'tags' => [],
            'created_at' => date('c', strtotime($workflow->created_at)),
            'updated_at' => date('c', strtotime($workflow->updated_at)),
            'execution_count' => $executionCount,
            'last_run' => $lastRun ? date('c', strtotime($lastRun->started_at)) : null,
        ];
    }

    /**
     * Execute workflow by name
     *
     * @param string $name Workflow name
     * @param array $input Optional input data
     * @return array Execution details
     */
    public function executeWorkflow(string $name, array $input = []): array
    {
        $workflow = DB::selectOne(
            "SELECT * FROM workflows WHERE name = ? LIMIT 1",
            [$name]
        );

        if (!$workflow) {
            throw new Exception("Workflow not found: {$name}");
        }

        if (!$workflow->active) {
            throw new Exception("Workflow is not active: {$name}");
        }

        // Execute workflow synchronously through the current engine API.
        $result = $this->engine->executeWorkflow($name, $input);

        // Get run details
        $run = DB::selectOne(
            "SELECT * FROM workflow_runs WHERE id = ? LIMIT 1",
            [$result['run_id']]
        );

        if (!$run) {
            throw new Exception("Workflow executed but no workflow_runs record was found for run {$result['run_id']}");
        }

        return [
            'run_id' => $run->id,
            'workflow_id' => $workflow->id,
            'workflow_name' => $workflow->name,
            'status' => $run->status,
            'execution_id' => $result['execution_id'] ?? $this->engine->getCurrentExecutionId(),
            'started_at' => date('c', strtotime($run->started_at)),
            'completed_at' => $run->completed_at ? date('c', strtotime($run->completed_at)) : null,
            'error' => $run->error_message,
            'output' => $result['output'] ?? null,
        ];
    }

    /**
     * Resume a failed workflow execution from the last checkpoint
     *
     * @param string $executionId UUID of the failed execution
     * @param array $overrideInput Optional input to override context
     * @return array Execution details
     */
    public function resumeWorkflow(string $executionId, array $overrideInput = []): array
    {
        $result = $this->engine->resumeWorkflow($executionId, $overrideInput);

        return [
            'run_id' => $result['run_id'],
            'execution_id' => $result['execution_id'],
            'resumed' => true,
            'status' => $result['success'] ? 'completed' : 'failed',
            'output' => $result['output'] ?? null,
        ];
    }

    /**
     * Get resume point information for a failed execution
     *
     * @param string $executionId UUID of the execution
     * @return array Resume point details
     */
    public function getResumePoint(string $executionId): array
    {
        return $this->replayer->getResumePoint($executionId);
    }

    /**
     * Check if an execution can be resumed
     *
     * @param string $executionId UUID of the execution
     * @return bool True if resumable
     */
    public function canResumeExecution(string $executionId): bool
    {
        return $this->replayer->canResume($executionId);
    }

    /**
     * Get execution events for debugging/monitoring
     *
     * @param string $executionId UUID of the execution
     * @return array List of events
     */
    public function getExecutionEvents(string $executionId): array
    {
        return $this->eventService->getEvents($executionId);
    }

    /**
     * Get execution statistics
     *
     * @param string $executionId UUID of the execution
     * @return array Stats including event counts
     */
    public function getExecutionStats(string $executionId): array
    {
        return $this->eventService->getExecutionStats($executionId);
    }

    private function decodeStoredValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Get workflow execution history
     *
     * @param int|null $workflowId Filter by workflow ID
     * @param int $limit Maximum results
     * @return array Execution history
     */
    public function getExecutionHistory(?int $workflowId = null, int $limit = 50): array
    {
        $query = "SELECT wr.*, w.name as workflow_name
                  FROM workflow_runs wr
                  JOIN workflows w ON wr.workflow_id = w.id";

        $params = [];

        if ($workflowId) {
            $query .= " WHERE wr.workflow_id = ?";
            $params[] = $workflowId;
        }

        $query .= " ORDER BY wr.started_at DESC LIMIT ?";
        $params[] = $limit;

        $runs = DB::select($query, $params);

        return array_map(function ($run) {
            $duration = null;
            if ($run->started_at && $run->completed_at) {
                $start = strtotime($run->started_at);
                $end = strtotime($run->completed_at);
                $duration = ($end - $start) * 1000; // Convert to milliseconds
            }

            return [
                'run_id' => $run->id,
                'workflow_id' => $run->workflow_id,
                'workflow_name' => $run->workflow_name,
                'status' => $run->status,
                'started_at' => date('c', strtotime($run->started_at)),
                'completed_at' => $run->completed_at ? date('c', strtotime($run->completed_at)) : null,
                'duration_ms' => $duration,
            ];
        }, $runs);
    }

    /**
     * Get execution details
     *
     * @param int $runId Run ID
     * @return array Execution details
     */
    public function getExecutionDetails(int $runId): array
    {
        $run = DB::selectOne(
            "SELECT wr.*, w.name as workflow_name
             FROM workflow_runs wr
             JOIN workflows w ON wr.workflow_id = w.id
             WHERE wr.id = ?",
            [$runId]
        );

        if (!$run) {
            throw new Exception("Execution not found: {$runId}");
        }

        // Get node executions
        $nodeExecutions = DB::select(
            "SELECT * FROM node_executions WHERE run_id = ? ORDER BY node_order",
            [$runId]
        );

        $duration = null;
        if ($run->started_at && $run->completed_at) {
            $start = strtotime($run->started_at);
            $end = strtotime($run->completed_at);
            $duration = ($end - $start) * 1000;
        }

        return [
            'run_id' => $run->id,
            'workflow_id' => $run->workflow_id,
            'workflow_name' => $run->workflow_name,
            'status' => $run->status,
            'started_at' => date('c', strtotime($run->started_at)),
            'completed_at' => $run->completed_at ? date('c', strtotime($run->completed_at)) : null,
            'duration_ms' => $duration,
            'error' => $run->error_message,
            'nodes' => array_map(function ($nodeExec) {
                return [
                    'node_type' => $nodeExec->node_type,
                    'node_order' => $nodeExec->node_order,
                    'duration_ms' => $nodeExec->duration_ms,
                    'error' => $nodeExec->error_message,
                    'executed_at' => $nodeExec->executed_at ? date('c', strtotime($nodeExec->executed_at)) : null,
                ];
            }, $nodeExecutions),
        ];
    }

    /**
     * Execute whitelisted artisan command
     *
     * @param string $command Artisan command
     * @param array $arguments Command arguments
     * @return array Command output
     */
    public function executeArtisanCommand(string $command, array $arguments = []): array
    {
        // Whitelist of allowed commands
        $whitelist = [
            'route:list',
            'migrate:status',
            'workflow:list',
            'about',
        ];

        if (!in_array($command, $whitelist)) {
            throw new Exception("Artisan command not whitelisted: {$command}");
        }

        // Execute command
        $exitCode = \Illuminate\Support\Facades\Artisan::call($command, $arguments);
        $output = \Illuminate\Support\Facades\Artisan::output();

        return [
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => trim($output),
            'success' => $exitCode === 0,
        ];
    }

    /**
     * Create a new workflow node class from template
     *
     * @param string $name Node class name
     * @param string $description What the node does
     * @return array Node creation details
     */
    public function createNodeClass(string $name, string $description): array
    {
        // Validate name (must be PascalCase, no spaces)
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            throw new Exception("Node name must be PascalCase (e.g., EmailSender): {$name}");
        }

        $className = $name;
        $filePath = app_path("Nodes/{$className}.php");

        // Check if already exists
        if (file_exists($filePath)) {
            throw new Exception("Node class already exists: {$className}");
        }

        // Template
        $template = <<<PHP
<?php

namespace App\Nodes;

use App\Nodes\BaseNode;

/**
 * {$className} Node
 *
 * {$description}
 */
class {$className} extends BaseNode
{
    /**
     * Execute node logic
     *
     * @param array \$context Workflow context
     * @return array Modified context
     */
    public function execute(array \$context): array
    {
        \$config = \$this->config;

        // TODO: Implement {$className} logic here
        // Example:
        // \$result = \$this->performAction(\$config, \$context);
        // \$context['result'] = \$result;

        return \$context;
    }

    /**
     * Validate node configuration
     *
     * @param array \$config Node configuration
     * @return array Validation errors (empty if valid)
     */
    public function validate(array \$config): array
    {
        \$errors = [];

        // TODO: Add validation rules
        // Example:
        // if (empty(\$config['required_field'])) {
        //     \$errors[] = 'required_field is required';
        // }

        return \$errors;
    }
}

PHP;

        // Create file
        file_put_contents($filePath, $template);

        return [
            'class_name' => $className,
            'file_path' => $filePath,
            'namespace' => 'App\\Nodes',
            'description' => $description,
            'created' => true,
        ];
    }

    /**
     * Get all scheduled workflows
     *
     * @return array Scheduled workflows with cron info
     */
    public function getScheduledWorkflows(): array
    {
        $workflows = DB::select(
            "SELECT w.id, w.name, w.description, sj.cron_expression AS schedule
             FROM workflows w
             JOIN scheduled_jobs sj
               ON sj.job_type = 'workflow'
              AND sj.command = w.name
             WHERE w.active = 1
               AND sj.enabled = 1
               AND sj.cron_expression IS NOT NULL
             ORDER BY w.name"
        );

        return array_map(function ($workflow) {
            $cronDescription = $this->describeCron($workflow->schedule);

            // Get last run
            $lastRun = DB::selectOne(
                "SELECT started_at, status FROM workflow_runs WHERE workflow_id = ? ORDER BY started_at DESC LIMIT 1",
                [$workflow->id]
            );

            return [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'cron_expression' => $workflow->schedule,
                'cron_description' => $cronDescription,
                'last_run' => $lastRun ? [
                    'started_at' => date('c', strtotime($lastRun->started_at)),
                    'status' => $lastRun->status,
                ] : null,
            ];
        }, $workflows);
    }

    /**
     * Get system diagnostics
     *
     * @return array System health information
     */
    public function getSystemDiagnostics(): array
    {
        // Database check
        try {
            DB::select("SELECT 1");
            $databaseStatus = 'healthy';
            $databaseError = null;
        } catch (\Exception $e) {
            $databaseStatus = 'unhealthy';
            $databaseError = $e->getMessage();
        }

        // Queue check
        try {
            if (config('queue.default') === 'redis') {
                $redis = \Illuminate\Support\Facades\Redis::connection();
                $queues = array_values(array_unique(array_filter([
                    config('queue.connections.redis.queue', 'default'),
                    'high',
                    'default',
                    'low',
                    'workflow',
                    'long-running',
                    'speculative',
                ])));
                $queueSize = 0;

                foreach ($queues as $queue) {
                    $queueSize += (int) ($redis->llen("queues:{$queue}") ?? 0);
                }
            } else {
                $queueSize = (int) DB::scalar("SELECT COUNT(*) FROM jobs");
            }

            $failedJobs = (int) DB::scalar("SELECT COUNT(*) FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $queueStatus = 'healthy';
            if ($queueSize > 100) {
                $queueStatus = 'degraded';
            } elseif ($failedJobs > 10) {
                $queueStatus = 'degraded';
            }
            $queueError = null;
        } catch (\Exception $e) {
            $queueSize = null;
            $failedJobs = null;
            $queueStatus = 'unhealthy';
            $queueError = $e->getMessage();
        }

        // AI services check (Ollama)
        try {
            $ollamaUrl = DB::selectOne("SELECT base_url FROM llm_instances WHERE instance_type = 'ollama' AND is_active = 1 ORDER BY priority ASC LIMIT 1")?->base_url ?? config('services.ollama.api_url', 'http://127.0.0.1:11434');
            $response = Http::connectTimeout(5)->timeout(5)->get(rtrim($ollamaUrl, '/') . '/api/tags');
            $aiStatus = $response->successful() ? 'healthy' : 'unreachable';
            $aiError = $response->successful() ? null : 'Unable to connect to Ollama (HTTP ' . $response->status() . ')';
        } catch (\Exception $e) {
            $aiStatus = 'unhealthy';
            $aiError = $e->getMessage();
        }

        // Workflow stats
        $totalWorkflows = DB::scalar("SELECT COUNT(*) FROM workflows");
        $activeWorkflows = DB::scalar("SELECT COUNT(*) FROM workflows WHERE active = 1");
        $scheduledWorkflows = DB::scalar("
            SELECT COUNT(*)
            FROM workflows w
            JOIN scheduled_jobs sj
              ON sj.job_type = 'workflow'
             AND sj.command = w.name
            WHERE w.active = 1
              AND sj.enabled = 1
              AND sj.cron_expression IS NOT NULL
        ");

        return [
            'timestamp' => date('c'),
            'database' => [
                'status' => $databaseStatus,
                'error' => $databaseError,
            ],
            'queue' => [
                'status' => $queueStatus,
                'pending_jobs' => $queueSize,
                'failed_jobs' => $failedJobs,
                'error' => $queueError,
            ],
            'ai_services' => [
                'ollama' => [
                    'status' => $aiStatus,
                    'url' => $ollamaUrl,
                    'error' => $aiError,
                ],
            ],
            'workflows' => [
                'total' => $totalWorkflows,
                'active' => $activeWorkflows,
                'scheduled' => $scheduledWorkflows,
            ],
            'overall_status' => ($databaseStatus === 'healthy' && $queueStatus === 'healthy' && $aiStatus === 'healthy')
                ? 'healthy'
                : 'degraded',
        ];
    }

    /**
     * Describe cron schedule in human-readable format
     *
     * @param string $cron Cron expression
     * @return string Human-readable description
     */
    private function describeCron(string $cron): string
    {
        // Basic cron description (can be enhanced)
        $parts = explode(' ', $cron);

        if (count($parts) !== 5) {
            return $cron;
        }

        [$minute, $hour, $day, $month, $dayOfWeek] = $parts;

        // Common patterns
        if ($cron === '* * * * *') {
            return 'Every minute';
        }
        if ($cron === '0 * * * *') {
            return 'Every hour';
        }
        if ($cron === '0 0 * * *') {
            return 'Daily at midnight';
        }
        if ($cron === '0 0 * * 0') {
            return 'Weekly on Sunday at midnight';
        }
        if ($cron === '0 0 1 * *') {
            return 'Monthly on the 1st at midnight';
        }

        // Custom format
        $desc = [];

        if ($minute !== '*') {
            $desc[] = "at minute {$minute}";
        }
        if ($hour !== '*') {
            $desc[] = "at hour {$hour}";
        }
        if ($day !== '*') {
            $desc[] = "on day {$day}";
        }
        if ($month !== '*') {
            $desc[] = "in month {$month}";
        }
        if ($dayOfWeek !== '*') {
            $desc[] = "on day of week {$dayOfWeek}";
        }

        return implode(', ', $desc) ?: 'Custom schedule';
    }
}

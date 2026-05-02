<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Distributed Agent Service
 *
 * Multi-node agent coordination via Redis/queue following DeepMind distributed agent patterns.
 * Handles agent registration, discovery, task distribution, result aggregation,
 * health monitoring, failover, and load balancing.
 */
class DistributedAgentService
{
    // Agent statuses
    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_BUSY = 'busy';
    public const STATUS_DRAINING = 'draining';

    // Task statuses
    public const TASK_PENDING = 'pending';
    public const TASK_ASSIGNED = 'assigned';
    public const TASK_RUNNING = 'running';
    public const TASK_COMPLETED = 'completed';
    public const TASK_FAILED = 'failed';
    public const TASK_CANCELLED = 'cancelled';

    // Batch statuses
    public const BATCH_PENDING = 'pending';
    public const BATCH_RUNNING = 'running';
    public const BATCH_COMPLETED = 'completed';
    public const BATCH_FAILED = 'failed';
    public const BATCH_CANCELLED = 'cancelled';

    // Redis keys
    private const REDIS_AGENT_PREFIX = 'distributed_agent:';
    private const REDIS_TASK_LOCK_PREFIX = 'task_lock:';
    private const REDIS_AGENT_HEARTBEAT_PREFIX = 'agent_heartbeat:';

    // Configuration
    private int $heartbeatIntervalSeconds = 30;
    private int $agentTimeoutSeconds = 90;
    private int $taskTimeoutSeconds = 300;
    private int $maxTaskRetries = 3;

    public function __construct()
    {
        $this->heartbeatIntervalSeconds = (int) config('distributed_agents.heartbeat_interval', 30);
        $this->agentTimeoutSeconds = (int) config('distributed_agents.agent_timeout', 90);
        $this->taskTimeoutSeconds = (int) config('distributed_agents.task_timeout', 300);
        $this->maxTaskRetries = (int) config('distributed_agents.max_retries', 3);
    }

    // =========================================================================
    // Agent Registration & Discovery
    // =========================================================================

    /**
     * Register a new agent node
     *
     * @param string $nodeName Hostname or identifier
     * @param array $capabilities List of capabilities this agent supports
     * @param int $maxConcurrentTasks Maximum tasks agent can handle
     * @param array $metadata Additional metadata
     * @return array Agent registration data
     */
    public function registerAgent(
        string $nodeName,
        array $capabilities = [],
        int $maxConcurrentTasks = 5,
        array $metadata = []
    ): array {
        $agentId = Str::uuid()->toString();
        $now = now();

        $sql = "
            INSERT INTO distributed_agents
            (agent_id, node_name, status, capabilities, metadata, max_concurrent_tasks, last_heartbeat_at, registered_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        DB::insert($sql, [
            $agentId,
            $nodeName,
            self::STATUS_ONLINE,
            json_encode($capabilities),
            json_encode($metadata),
            $maxConcurrentTasks,
            $now,
            $now,
            $now,
        ]);

        // Store in Redis for fast lookup
        $this->cacheAgentState($agentId, [
            'node_name' => $nodeName,
            'status' => self::STATUS_ONLINE,
            'capabilities' => $capabilities,
            'max_concurrent_tasks' => $maxConcurrentTasks,
            'current_load' => 0,
            'last_heartbeat' => $now->timestamp,
        ]);

        Log::info('Agent registered', [
            'agent_id' => $agentId,
            'node_name' => $nodeName,
            'capabilities' => $capabilities,
        ]);

        return [
            'agent_id' => $agentId,
            'node_name' => $nodeName,
            'status' => self::STATUS_ONLINE,
            'capabilities' => $capabilities,
            'max_concurrent_tasks' => $maxConcurrentTasks,
        ];
    }

    /**
     * Unregister an agent (graceful shutdown)
     */
    public function unregisterAgent(string $agentId): bool
    {
        // Reassign any pending tasks
        $this->reassignAgentTasks($agentId);

        // Update status to offline
        $sql = "UPDATE distributed_agents SET status = ?, updated_at = ? WHERE agent_id = ?";
        $affected = DB::update($sql, [self::STATUS_OFFLINE, now(), $agentId]);

        // Remove from Redis
        Cache::forget(self::REDIS_AGENT_PREFIX . $agentId);

        Log::info('Agent unregistered', ['agent_id' => $agentId]);

        return $affected > 0;
    }

    /**
     * Discover available agents with optional capability filter
     *
     * @param array $requiredCapabilities Filter by capabilities
     * @param bool $includeOffline Include offline agents in results
     * @return array List of agents
     */
    public function discoverAgents(array $requiredCapabilities = [], bool $includeOffline = false): array
    {
        $sql = "SELECT * FROM distributed_agents WHERE 1=1";
        $params = [];

        if (!$includeOffline) {
            $sql .= " AND status IN (?, ?)";
            $params[] = self::STATUS_ONLINE;
            $params[] = self::STATUS_BUSY;
        }

        $sql .= " ORDER BY current_load ASC, last_heartbeat_at DESC";
        $agents = DB::select($sql, $params);

        // Filter by capabilities if specified
        if (!empty($requiredCapabilities)) {
            $agents = array_filter($agents, function ($agent) use ($requiredCapabilities) {
                $agentCapabilities = json_decode($agent->capabilities ?? '[]', true);
                foreach ($requiredCapabilities as $required) {
                    if (!in_array($required, $agentCapabilities)) {
                        return false;
                    }
                }
                return true;
            });
        }

        return array_map(function ($agent) {
            return [
                'id' => $agent->id,
                'agent_id' => $agent->agent_id,
                'node_name' => $agent->node_name,
                'status' => $agent->status,
                'capabilities' => json_decode($agent->capabilities ?? '[]', true),
                'max_concurrent_tasks' => $agent->max_concurrent_tasks,
                'current_load' => $agent->current_load,
                'total_tasks_completed' => $agent->total_tasks_completed,
                'avg_task_duration_ms' => $agent->avg_task_duration_ms,
                'last_heartbeat_at' => $agent->last_heartbeat_at,
            ];
        }, array_values($agents));
    }

    /**
     * Send heartbeat from agent
     */
    public function heartbeat(string $agentId, array $metrics = []): bool
    {
        $now = now();

        $sql = "
            UPDATE distributed_agents
            SET last_heartbeat_at = ?, status = ?, updated_at = ?
            WHERE agent_id = ? AND status != ?
        ";

        $affected = DB::update($sql, [
            $now,
            self::STATUS_ONLINE,
            $now,
            $agentId,
            self::STATUS_OFFLINE,
        ]);

        if ($affected > 0) {
            // Update Redis cache
            Cache::put(
                self::REDIS_AGENT_HEARTBEAT_PREFIX . $agentId,
                $now->timestamp,
                $this->agentTimeoutSeconds
            );

            // Record health metrics if provided
            if (!empty($metrics)) {
                $this->recordHealthMetrics($agentId, $metrics);
            }
        }

        return $affected > 0;
    }

    // =========================================================================
    // Task Distribution
    // =========================================================================

    /**
     * Submit a task for distributed execution
     *
     * @param string $taskType Type of task
     * @param array $payload Task payload
     * @param array $options Additional options (priority, capabilities, timeout)
     * @return array Task submission result
     */
    public function submitTask(string $taskType, array $payload, array $options = []): array
    {
        $taskId = Str::uuid()->toString();
        $now = now();
        $priority = $options['priority'] ?? 0;
        $requiredCapabilities = $options['required_capabilities'] ?? [];
        $timeoutSeconds = $options['timeout'] ?? $this->taskTimeoutSeconds;
        $maxRetries = $options['max_retries'] ?? $this->maxTaskRetries;

        $sql = "
            INSERT INTO distributed_tasks
            (task_id, task_type, payload, required_capabilities, status, priority, max_retries, timeout_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        DB::insert($sql, [
            $taskId,
            $taskType,
            json_encode($payload),
            json_encode($requiredCapabilities),
            self::TASK_PENDING,
            $priority,
            $maxRetries,
            $now->addSeconds($timeoutSeconds),
            $now,
            $now,
        ]);

        // Dispatch to queue for processing
        if ($options['dispatch_immediately'] ?? true) {
            $this->dispatchTaskAssignment($taskId);
        }

        Log::debug('Task submitted', [
            'task_id' => $taskId,
            'task_type' => $taskType,
            'priority' => $priority,
        ]);

        return [
            'task_id' => $taskId,
            'status' => self::TASK_PENDING,
            'created_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Assign a task to the best available agent
     *
     * @param string $taskId Task ID to assign
     * @return array|null Assigned agent info or null if no agent available
     */
    public function assignTask(string $taskId): ?array
    {
        // Get task details
        $task = DB::selectOne("SELECT * FROM distributed_tasks WHERE task_id = ?", [$taskId]);

        if (!$task || $task->status !== self::TASK_PENDING) {
            return null;
        }

        $requiredCapabilities = json_decode($task->required_capabilities ?? '[]', true);

        // Find best agent using load balancing
        $agent = $this->selectBestAgent($requiredCapabilities);

        if (!$agent) {
            Log::warning('No available agent for task', [
                'task_id' => $taskId,
                'required_capabilities' => $requiredCapabilities,
            ]);
            return null;
        }

        // Acquire lock to prevent race conditions
        $lockKey = self::REDIS_TASK_LOCK_PREFIX . $taskId;
        $lock = Cache::lock($lockKey, 10);

        if (!$lock->get()) {
            return null; // Another process is handling this task
        }

        try {
            $now = now();

            // Assign task to agent
            $sql = "
                UPDATE distributed_tasks
                SET assigned_agent_id = ?, status = ?, assigned_at = ?, updated_at = ?
                WHERE task_id = ? AND status = ?
            ";

            $affected = DB::update($sql, [
                $agent['id'],
                self::TASK_ASSIGNED,
                $now,
                $now,
                $taskId,
                self::TASK_PENDING,
            ]);

            if ($affected > 0) {
                // Increment agent load
                DB::update(
                    "UPDATE distributed_agents SET current_load = current_load + 1, updated_at = ? WHERE id = ?",
                    [$now, $agent['id']]
                );

                // Dispatch job to Horizon queue for the assigned agent
                $this->dispatchTaskToAgent($taskId, $agent['agent_id']);

                Log::info('Task assigned', [
                    'task_id' => $taskId,
                    'agent_id' => $agent['agent_id'],
                    'node_name' => $agent['node_name'],
                ]);

                return [
                    'task_id' => $taskId,
                    'agent_id' => $agent['agent_id'],
                    'node_name' => $agent['node_name'],
                    'assigned_at' => $now->toIso8601String(),
                ];
            }
        } finally {
            $lock->release();
        }

        return null;
    }

    /**
     * Select the best agent using weighted load balancing
     *
     * @param array $requiredCapabilities Required capabilities
     * @return array|null Best agent or null
     */
    private function selectBestAgent(array $requiredCapabilities = []): ?array
    {
        // Get available agents
        $agents = $this->discoverAgents($requiredCapabilities, false);

        if (empty($agents)) {
            return null;
        }

        // Filter agents that have capacity
        $availableAgents = array_filter($agents, function ($agent) {
            return $agent['current_load'] < $agent['max_concurrent_tasks']
                && $agent['status'] === self::STATUS_ONLINE;
        });

        if (empty($availableAgents)) {
            // Try busy agents if no online agents have capacity
            $availableAgents = array_filter($agents, function ($agent) {
                return $agent['current_load'] < $agent['max_concurrent_tasks']
                    && $agent['status'] === self::STATUS_BUSY;
            });
        }

        if (empty($availableAgents)) {
            return null;
        }

        // Score agents using weighted factors (DeepMind pattern)
        $scoredAgents = array_map(function ($agent) {
            // Lower score = better choice
            $loadRatio = $agent['current_load'] / max(1, $agent['max_concurrent_tasks']);
            $performanceScore = $agent['avg_task_duration_ms'] / 1000; // Normalize to seconds
            $reliabilityScore = $agent['total_tasks_completed'] > 0
                ? 1 - ($agent['total_tasks_completed'] / max(1, $agent['total_tasks_completed'] + 1))
                : 0.5;

            $agent['score'] = ($loadRatio * 0.5) + ($performanceScore * 0.3) + ($reliabilityScore * 0.2);
            return $agent;
        }, $availableAgents);

        // Sort by score (ascending)
        usort($scoredAgents, fn($a, $b) => $a['score'] <=> $b['score']);

        return $scoredAgents[0] ?? null;
    }

    /**
     * Mark task as started by agent
     */
    public function startTask(string $taskId, string $agentId): bool
    {
        $sql = "
            UPDATE distributed_tasks
            SET status = ?, started_at = ?, updated_at = ?
            WHERE task_id = ? AND status = ?
        ";

        $affected = DB::update($sql, [
            self::TASK_RUNNING,
            now(),
            now(),
            $taskId,
            self::TASK_ASSIGNED,
        ]);

        if ($affected > 0) {
            // Update agent status to busy if at capacity
            $this->updateAgentBusyStatus($agentId);
        }

        return $affected > 0;
    }

    /**
     * Complete a task with result
     */
    public function completeTask(string $taskId, array $result, int $durationMs = 0): bool
    {
        $now = now();

        // Get task to find assigned agent
        $task = DB::selectOne("SELECT * FROM distributed_tasks WHERE task_id = ?", [$taskId]);

        if (!$task) {
            return false;
        }

        $sql = "
            UPDATE distributed_tasks
            SET status = ?, result = ?, completed_at = ?, updated_at = ?
            WHERE task_id = ? AND status = ?
        ";

        $affected = DB::update($sql, [
            self::TASK_COMPLETED,
            json_encode($result),
            $now,
            $now,
            $taskId,
            self::TASK_RUNNING,
        ]);

        if ($affected > 0 && $task->assigned_agent_id) {
            // Decrement agent load and update statistics
            $this->updateAgentAfterTask($task->assigned_agent_id, true, $durationMs);

            // Check if task is part of a batch
            $this->updateBatchProgress($taskId);

            Log::info('Task completed', [
                'task_id' => $taskId,
                'duration_ms' => $durationMs,
            ]);
        }

        return $affected > 0;
    }

    /**
     * Fail a task with error
     */
    public function failTask(string $taskId, string $errorMessage, bool $canRetry = true): bool
    {
        $now = now();

        $task = DB::selectOne("SELECT * FROM distributed_tasks WHERE task_id = ?", [$taskId]);

        if (!$task) {
            return false;
        }

        // Check if we should retry
        if ($canRetry && $task->retry_count < $task->max_retries) {
            // Increment retry count and reset to pending
            $sql = "
                UPDATE distributed_tasks
                SET status = ?, retry_count = retry_count + 1, assigned_agent_id = NULL,
                    error_message = ?, updated_at = ?
                WHERE task_id = ?
            ";

            DB::update($sql, [self::TASK_PENDING, $errorMessage, $now, $taskId]);

            // Dispatch for reassignment
            $this->dispatchTaskAssignment($taskId);

            Log::warning('Task failed, retrying', [
                'task_id' => $taskId,
                'retry_count' => $task->retry_count + 1,
                'error' => $errorMessage,
            ]);
        } else {
            // Mark as failed permanently
            $sql = "
                UPDATE distributed_tasks
                SET status = ?, error_message = ?, completed_at = ?, updated_at = ?
                WHERE task_id = ?
            ";

            DB::update($sql, [self::TASK_FAILED, $errorMessage, $now, $now, $taskId]);

            // Update batch if applicable
            $this->updateBatchProgress($taskId, true);

            Log::error('Task failed permanently', [
                'task_id' => $taskId,
                'error' => $errorMessage,
            ]);
        }

        if ($task->assigned_agent_id) {
            $this->updateAgentAfterTask($task->assigned_agent_id, false);
        }

        return true;
    }

    // =========================================================================
    // Result Aggregation
    // =========================================================================

    /**
     * Create a batch of tasks for distributed execution
     *
     * @param array $tasks Array of task definitions
     * @param array $options Batch options
     * @return array Batch info
     */
    public function createBatch(array $tasks, array $options = []): array
    {
        $batchId = Str::uuid()->toString();
        $batchName = $options['name'] ?? 'Batch ' . substr($batchId, 0, 8);
        $now = now();

        $sql = "
            INSERT INTO distributed_task_batches
            (batch_id, batch_name, total_tasks, status, options, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        DB::insert($sql, [
            $batchId,
            $batchName,
            count($tasks),
            self::BATCH_PENDING,
            json_encode($options),
            $now,
        ]);

        $batchDbId = DB::selectOne("SELECT id FROM distributed_task_batches WHERE batch_id = ?", [$batchId])->id;

        // Create individual tasks
        $taskIds = [];
        foreach ($tasks as $index => $taskDef) {
            $taskResult = $this->submitTask(
                $taskDef['type'],
                $taskDef['payload'],
                array_merge($taskDef['options'] ?? [], ['dispatch_immediately' => false])
            );

            $taskIds[] = $taskResult['task_id'];

            // Link task to batch
            $taskDbId = DB::selectOne("SELECT id FROM distributed_tasks WHERE task_id = ?", [$taskResult['task_id']])->id;

            DB::insert(
                "INSERT INTO distributed_task_batch_items (batch_id, task_id, sequence_order) VALUES (?, ?, ?)",
                [$batchDbId, $taskDbId, $index]
            );
        }

        // Update batch status and dispatch tasks
        DB::update(
            "UPDATE distributed_task_batches SET status = ? WHERE batch_id = ?",
            [self::BATCH_RUNNING, $batchId]
        );

        // Dispatch all tasks for assignment
        foreach ($taskIds as $taskId) {
            $this->dispatchTaskAssignment($taskId);
        }

        Log::info('Batch created', [
            'batch_id' => $batchId,
            'total_tasks' => count($tasks),
        ]);

        return [
            'batch_id' => $batchId,
            'batch_name' => $batchName,
            'total_tasks' => count($tasks),
            'task_ids' => $taskIds,
            'status' => self::BATCH_RUNNING,
        ];
    }

    /**
     * Get batch status with aggregated results
     */
    public function getBatchStatus(string $batchId): ?array
    {
        $batch = DB::selectOne("SELECT * FROM distributed_task_batches WHERE batch_id = ?", [$batchId]);

        if (!$batch) {
            return null;
        }

        // Get task statuses
        $sql = "
            SELECT dt.status, COUNT(*) as count
            FROM distributed_tasks dt
            INNER JOIN distributed_task_batch_items bi ON dt.id = bi.task_id
            INNER JOIN distributed_task_batches b ON bi.batch_id = b.id
            WHERE b.batch_id = ?
            GROUP BY dt.status
        ";

        $statusCounts = DB::select($sql, [$batchId]);
        $counts = array_column($statusCounts, 'count', 'status');

        return [
            'batch_id' => $batch->batch_id,
            'batch_name' => $batch->batch_name,
            'status' => $batch->status,
            'total_tasks' => $batch->total_tasks,
            'completed_tasks' => $batch->completed_tasks,
            'failed_tasks' => $batch->failed_tasks,
            'task_status_breakdown' => $counts,
            'aggregated_results' => json_decode($batch->aggregated_results ?? '[]', true),
            'created_at' => $batch->created_at,
            'completed_at' => $batch->completed_at,
        ];
    }

    /**
     * Aggregate results from completed batch
     */
    public function aggregateBatchResults(string $batchId, callable $aggregator = null): array
    {
        $sql = "
            SELECT dt.*
            FROM distributed_tasks dt
            INNER JOIN distributed_task_batch_items bi ON dt.id = bi.task_id
            INNER JOIN distributed_task_batches b ON bi.batch_id = b.id
            WHERE b.batch_id = ? AND dt.status = ?
            ORDER BY bi.sequence_order
        ";

        $completedTasks = DB::select($sql, [$batchId, self::TASK_COMPLETED]);

        $results = array_map(function ($task) {
            return [
                'task_id' => $task->task_id,
                'task_type' => $task->task_type,
                'result' => json_decode($task->result ?? '[]', true),
                'started_at' => $task->started_at,
                'completed_at' => $task->completed_at,
            ];
        }, $completedTasks);

        // Apply custom aggregator if provided
        if ($aggregator) {
            $aggregated = $aggregator($results);
        } else {
            // Default aggregation: collect all results
            $aggregated = [
                'total_completed' => count($results),
                'results' => array_column($results, 'result'),
            ];
        }

        // Store aggregated results
        DB::update(
            "UPDATE distributed_task_batches SET aggregated_results = ? WHERE batch_id = ?",
            [json_encode($aggregated), $batchId]
        );

        return $aggregated;
    }

    // =========================================================================
    // Health Monitoring & Failover
    // =========================================================================

    /**
     * Check and handle timed-out agents
     */
    public function checkAgentHealth(): array
    {
        $cutoffTime = now()->subSeconds($this->agentTimeoutSeconds);

        // Find timed-out agents
        $sql = "
            SELECT * FROM distributed_agents
            WHERE status IN (?, ?) AND last_heartbeat_at < ?
        ";

        $timedOutAgents = DB::select($sql, [
            self::STATUS_ONLINE,
            self::STATUS_BUSY,
            $cutoffTime,
        ]);

        $failedOver = [];

        foreach ($timedOutAgents as $agent) {
            Log::warning('Agent timed out', [
                'agent_id' => $agent->agent_id,
                'node_name' => $agent->node_name,
                'last_heartbeat' => $agent->last_heartbeat_at,
            ]);

            // Mark agent as offline
            DB::update(
                "UPDATE distributed_agents SET status = ?, updated_at = ? WHERE id = ?",
                [self::STATUS_OFFLINE, now(), $agent->id]
            );

            // Reassign tasks
            $reassigned = $this->reassignAgentTasks($agent->agent_id);

            $failedOver[] = [
                'agent_id' => $agent->agent_id,
                'node_name' => $agent->node_name,
                'tasks_reassigned' => $reassigned,
            ];
        }

        return [
            'timed_out_agents' => count($timedOutAgents),
            'failover_details' => $failedOver,
        ];
    }

    /**
     * Check and handle timed-out tasks
     */
    public function checkTaskHealth(): array
    {
        $now = now();

        // Find timed-out tasks
        $sql = "
            SELECT * FROM distributed_tasks
            WHERE status IN (?, ?) AND timeout_at < ?
        ";

        $timedOutTasks = DB::select($sql, [
            self::TASK_ASSIGNED,
            self::TASK_RUNNING,
            $now,
        ]);

        $recovered = [];

        foreach ($timedOutTasks as $task) {
            Log::warning('Task timed out', [
                'task_id' => $task->task_id,
                'status' => $task->status,
                'timeout_at' => $task->timeout_at,
            ]);

            // Fail the task (will retry if possible)
            $this->failTask($task->task_id, 'Task execution timed out', true);

            $recovered[] = [
                'task_id' => $task->task_id,
                'task_type' => $task->task_type,
                'previous_status' => $task->status,
            ];
        }

        return [
            'timed_out_tasks' => count($timedOutTasks),
            'recovered_tasks' => $recovered,
        ];
    }

    /**
     * Reassign all tasks from a specific agent
     */
    private function reassignAgentTasks(string $agentId): int
    {
        $agent = DB::selectOne("SELECT id FROM distributed_agents WHERE agent_id = ?", [$agentId]);

        if (!$agent) {
            return 0;
        }

        // Find tasks assigned to this agent that aren't completed
        $sql = "
            SELECT task_id FROM distributed_tasks
            WHERE assigned_agent_id = ? AND status IN (?, ?)
        ";

        $tasks = DB::select($sql, [$agent->id, self::TASK_ASSIGNED, self::TASK_RUNNING]);

        $now = now();
        $count = 0;

        foreach ($tasks as $task) {
            // Reset task to pending for reassignment
            DB::update(
                "UPDATE distributed_tasks SET status = ?, assigned_agent_id = NULL, updated_at = ? WHERE task_id = ?",
                [self::TASK_PENDING, $now, $task->task_id]
            );

            // Dispatch for reassignment
            $this->dispatchTaskAssignment($task->task_id);
            $count++;
        }

        return $count;
    }

    /**
     * Record health metrics for an agent
     */
    private function recordHealthMetrics(string $agentId, array $metrics): void
    {
        $agent = DB::selectOne("SELECT id, current_load FROM distributed_agents WHERE agent_id = ?", [$agentId]);

        if (!$agent) {
            return;
        }

        $sql = "
            INSERT INTO distributed_agent_health
            (agent_id, cpu_usage, memory_usage, active_tasks, avg_response_time_ms, tasks_per_minute, custom_metrics, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        DB::insert($sql, [
            $agent->id,
            $metrics['cpu_usage'] ?? null,
            $metrics['memory_usage'] ?? null,
            $agent->current_load,
            $metrics['avg_response_time_ms'] ?? null,
            $metrics['tasks_per_minute'] ?? 0,
            json_encode($metrics['custom'] ?? []),
            now(),
        ]);
    }

    // =========================================================================
    // Load Balancing Utilities
    // =========================================================================

    /**
     * Get current system load statistics
     */
    public function getSystemLoad(): array
    {
        $sql = "
            SELECT
                COUNT(*) as total_agents,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as online_agents,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as busy_agents,
                SUM(current_load) as total_current_load,
                SUM(max_concurrent_tasks) as total_capacity
            FROM distributed_agents
            WHERE status != ?
        ";

        $agentStats = DB::selectOne($sql, [
            self::STATUS_ONLINE,
            self::STATUS_BUSY,
            self::STATUS_OFFLINE,
        ]);

        $sql = "
            SELECT status, COUNT(*) as count
            FROM distributed_tasks
            WHERE created_at > ?
            GROUP BY status
        ";

        $taskStats = DB::select($sql, [now()->subHours(24)]);
        $taskCounts = array_column($taskStats, 'count', 'status');

        return [
            'agents' => [
                'total' => $agentStats->total_agents ?? 0,
                'online' => $agentStats->online_agents ?? 0,
                'busy' => $agentStats->busy_agents ?? 0,
                'current_load' => $agentStats->total_current_load ?? 0,
                'total_capacity' => $agentStats->total_capacity ?? 0,
                'load_percentage' => $agentStats->total_capacity > 0
                    ? round(($agentStats->total_current_load / $agentStats->total_capacity) * 100, 2)
                    : 0,
            ],
            'tasks_24h' => [
                'pending' => $taskCounts[self::TASK_PENDING] ?? 0,
                'assigned' => $taskCounts[self::TASK_ASSIGNED] ?? 0,
                'running' => $taskCounts[self::TASK_RUNNING] ?? 0,
                'completed' => $taskCounts[self::TASK_COMPLETED] ?? 0,
                'failed' => $taskCounts[self::TASK_FAILED] ?? 0,
            ],
        ];
    }

    /**
     * Drain an agent (stop accepting new tasks, finish existing)
     */
    public function drainAgent(string $agentId): bool
    {
        $sql = "UPDATE distributed_agents SET status = ?, updated_at = ? WHERE agent_id = ? AND status != ?";
        $affected = DB::update($sql, [self::STATUS_DRAINING, now(), $agentId, self::STATUS_OFFLINE]);

        Log::info('Agent draining', ['agent_id' => $agentId]);

        return $affected > 0;
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function cacheAgentState(string $agentId, array $state): void
    {
        Cache::put(
            self::REDIS_AGENT_PREFIX . $agentId,
            $state,
            $this->agentTimeoutSeconds * 2
        );
    }

    private function updateAgentBusyStatus(string $agentId): void
    {
        $agent = DB::selectOne(
            "SELECT current_load, max_concurrent_tasks FROM distributed_agents WHERE agent_id = ?",
            [$agentId]
        );

        if ($agent && $agent->current_load >= $agent->max_concurrent_tasks) {
            DB::update(
                "UPDATE distributed_agents SET status = ?, updated_at = ? WHERE agent_id = ?",
                [self::STATUS_BUSY, now(), $agentId]
            );
        }
    }

    private function updateAgentAfterTask(int $agentDbId, bool $success, int $durationMs = 0): void
    {
        $now = now();

        if ($success) {
            // Update completion stats with running average for duration
            $sql = "
                UPDATE distributed_agents
                SET current_load = GREATEST(0, current_load - 1),
                    total_tasks_completed = total_tasks_completed + 1,
                    avg_task_duration_ms = (avg_task_duration_ms * total_tasks_completed + ?) / (total_tasks_completed + 1),
                    status = CASE WHEN current_load <= 1 THEN ? ELSE status END,
                    updated_at = ?
                WHERE id = ?
            ";

            DB::update($sql, [$durationMs, self::STATUS_ONLINE, $now, $agentDbId]);
        } else {
            $sql = "
                UPDATE distributed_agents
                SET current_load = GREATEST(0, current_load - 1),
                    total_tasks_failed = total_tasks_failed + 1,
                    status = CASE WHEN current_load <= 1 THEN ? ELSE status END,
                    updated_at = ?
                WHERE id = ?
            ";

            DB::update($sql, [self::STATUS_ONLINE, $now, $agentDbId]);
        }
    }

    private function updateBatchProgress(string $taskId, bool $failed = false): void
    {
        // Find batch containing this task
        $sql = "
            SELECT b.batch_id
            FROM distributed_task_batches b
            INNER JOIN distributed_task_batch_items bi ON b.id = bi.batch_id
            INNER JOIN distributed_tasks t ON bi.task_id = t.id
            WHERE t.task_id = ?
        ";

        $result = DB::selectOne($sql, [$taskId]);

        if (!$result) {
            return;
        }

        $batchId = $result->batch_id;

        if ($failed) {
            DB::update(
                "UPDATE distributed_task_batches SET failed_tasks = failed_tasks + 1 WHERE batch_id = ?",
                [$batchId]
            );
        } else {
            DB::update(
                "UPDATE distributed_task_batches SET completed_tasks = completed_tasks + 1 WHERE batch_id = ?",
                [$batchId]
            );
        }

        // Check if batch is complete
        $batch = DB::selectOne("SELECT * FROM distributed_task_batches WHERE batch_id = ?", [$batchId]);

        if ($batch && ($batch->completed_tasks + $batch->failed_tasks) >= $batch->total_tasks) {
            $finalStatus = $batch->failed_tasks > 0 ? self::BATCH_FAILED : self::BATCH_COMPLETED;

            DB::update(
                "UPDATE distributed_task_batches SET status = ?, completed_at = ? WHERE batch_id = ?",
                [$finalStatus, now(), $batchId]
            );

            // Aggregate results
            $this->aggregateBatchResults($batchId);

            Log::info('Batch completed', [
                'batch_id' => $batchId,
                'status' => $finalStatus,
                'completed' => $batch->completed_tasks,
                'failed' => $batch->failed_tasks,
            ]);
        }
    }

    private function dispatchTaskAssignment(string $taskId): void
    {
        // Dispatch to Horizon queue for task assignment
        Queue::push(function () use ($taskId) {
            app(DistributedAgentService::class)->assignTask($taskId);
        }, '', 'default');
    }

    private function dispatchTaskToAgent(string $taskId, string $agentId): void
    {
        \App\Jobs\ProcessAgentTask::dispatch($taskId, $agentId);

        Log::info('Task dispatched via ProcessAgentTask', [
            'task_id' => $taskId,
            'agent_id' => $agentId,
        ]);
    }

    /**
     * Get task details
     */
    public function getTask(string $taskId): ?array
    {
        $task = DB::selectOne("SELECT * FROM distributed_tasks WHERE task_id = ?", [$taskId]);

        if (!$task) {
            return null;
        }

        return [
            'task_id' => $task->task_id,
            'task_type' => $task->task_type,
            'payload' => json_decode($task->payload ?? '{}', true),
            'required_capabilities' => json_decode($task->required_capabilities ?? '[]', true),
            'status' => $task->status,
            'priority' => $task->priority,
            'retry_count' => $task->retry_count,
            'max_retries' => $task->max_retries,
            'result' => json_decode($task->result ?? 'null', true),
            'error_message' => $task->error_message,
            'assigned_at' => $task->assigned_at,
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
            'created_at' => $task->created_at,
        ];
    }

    /**
     * Get agent details
     */
    public function getAgent(string $agentId): ?array
    {
        $agent = DB::selectOne("SELECT * FROM distributed_agents WHERE agent_id = ?", [$agentId]);

        if (!$agent) {
            return null;
        }

        return [
            'id' => $agent->id,
            'agent_id' => $agent->agent_id,
            'node_name' => $agent->node_name,
            'status' => $agent->status,
            'capabilities' => json_decode($agent->capabilities ?? '[]', true),
            'metadata' => json_decode($agent->metadata ?? '{}', true),
            'max_concurrent_tasks' => $agent->max_concurrent_tasks,
            'current_load' => $agent->current_load,
            'total_tasks_completed' => $agent->total_tasks_completed,
            'total_tasks_failed' => $agent->total_tasks_failed,
            'avg_task_duration_ms' => $agent->avg_task_duration_ms,
            'last_heartbeat_at' => $agent->last_heartbeat_at,
            'registered_at' => $agent->registered_at,
        ];
    }
}

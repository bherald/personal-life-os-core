<?php

namespace App\Jobs;

use App\Services\AgentLoopService;
use App\Services\DistributedAgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process Agent Task Job
 *
 * Queue worker that processes distributed_tasks by executing agent logic
 * via AgentLoopService. This is the missing execution bridge between
 * DistributedAgentService task dispatch and actual AI agent execution.
 *
 * Flow:
 * 1. DistributedAgentService->submitTask() creates task in DB + dispatches this job
 * 2. This job loads task details, marks as running
 * 3. Calls AgentLoopService->execute() with task payload
 * 4. Updates task with result or error
 * 5. Sends Pushover notification if configured
 */
class ProcessAgentTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // 10 minutes max
    public int $tries = 2;
    public array $backoff = [30, 60];

    public function __construct(
        private string $taskId,
        private string $agentId
    ) {
        $this->onQueue('long-running');
    }

    /**
     * Prevent overlapping execution of the same task
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->taskId),
        ];
    }

    public function handle(): void
    {
        $distributedService = app(DistributedAgentService::class);
        $agentLoop = app(AgentLoopService::class);

        // Load task from DB
        $task = $distributedService->getTask($this->taskId);

        if (!$task) {
            Log::warning('ProcessAgentTask: Task not found', ['task_id' => $this->taskId]);
            return;
        }

        if (!in_array($task['status'], ['pending', 'assigned'])) {
            Log::debug('ProcessAgentTask: Task already processed', [
                'task_id' => $this->taskId,
                'status' => $task['status'],
            ]);
            return;
        }

        // Mark task as running
        $distributedService->startTask($this->taskId, $this->agentId);

        $startTime = microtime(true);

        try {
            $payload = $task['payload'] ?? [];
            $taskDescription = $payload['task'] ?? $payload['description'] ?? $task['task_type'];
            $options = [
                'session_id' => $payload['session_id'] ?? null,
                'context' => $payload['context'] ?? [],
                'model' => $payload['model'] ?? null,
                'tree_id' => $payload['tree_id'] ?? null,
                'notify' => $payload['notify'] ?? false,
                'depth' => $payload['depth'] ?? 0,
            ];

            // Determine which agent skill to use
            $skillName = $payload['skill'] ?? $task['task_type'];

            // Execute the agent loop
            $result = $agentLoop->execute($skillName, $taskDescription, $options);

            $durationMs = round((microtime(true) - $startTime) * 1000);

            if ($result['success']) {
                $distributedService->completeTask($this->taskId, $result, $durationMs);

                Log::info('ProcessAgentTask: Task completed', [
                    'task_id' => $this->taskId,
                    'agent_id' => $this->agentId,
                    'duration_ms' => $durationMs,
                    'tokens' => $result['tokens_used'] ?? 0,
                ]);
            } else {
                $distributedService->failTask(
                    $this->taskId,
                    $result['error'] ?? 'Agent execution returned failure',
                    true
                );
            }

        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000);

            $distributedService->failTask($this->taskId, $e->getMessage(), true);

            Log::error('ProcessAgentTask: Execution failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            throw $e; // Let Laravel retry if configured
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessAgentTask: Job failed permanently', [
            'task_id' => $this->taskId,
            'agent_id' => $this->agentId,
            'error' => $exception?->getMessage(),
        ]);

        try {
            app(DistributedAgentService::class)->failTask(
                $this->taskId,
                'Job failed: ' . ($exception?->getMessage() ?? 'Unknown error'),
                false
            );
        } catch (Throwable $e) {
            // Don't let failure handling failure mask original error
        }
    }

    public function tags(): array
    {
        return [
            'agent-task',
            'task:' . substr($this->taskId, 0, 8),
            'agent:' . $this->agentId,
        ];
    }
}

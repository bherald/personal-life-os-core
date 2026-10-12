<?php

namespace App\Jobs;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AgentHandoffService;
use App\Services\AgentLoopService;
use App\Services\DistributedAgentService;
use App\Services\SystemConfigService;
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

    private const RUNTIME_POLICY_CONFIG_KEY = 'agents.process_agent_task_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 600,
        'tries' => 2,
        'backoff_seconds' => [30, 60],
    ];

    public int $timeout = 600;

    public int $tries = 2;

    public array $backoff = [30, 60];

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    private ?int $assignmentGeneration = null;

    public function __construct(
        private string $taskId,
        private string $agentId,
        private int $attempt = 0,
        ?int $assignmentGeneration = null,
    ) {
        $this->assignmentGeneration = $assignmentGeneration;
        $this->applyRuntimePolicy();

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
        if ($this->assignmentGeneration === null || $this->assignmentGeneration < 1) {
            Log::warning('ProcessAgentTask: Legacy unbound assignment rejected', ['task_id' => $this->taskId]);

            return;
        }
        $distributedService = app(DistributedAgentService::class);
        $agentLoop = app(AgentLoopService::class);

        // Load task from DB
        $task = $distributedService->getTask($this->taskId);

        if (! $task) {
            Log::warning('ProcessAgentTask: Task not found', ['task_id' => $this->taskId]);

            return;
        }

        if (! in_array($task['status'], ['pending', 'assigned'])) {
            Log::debug('ProcessAgentTask: Task already processed', [
                'task_id' => $this->taskId,
                'status' => $task['status'],
            ]);

            return;
        }

        $payload = $task['payload'] ?? [];

        // Mark task as running before agent execution so the handoff remains
        // nonterminal until the child produces an authoritative outcome.
        if (! $distributedService->startTask($this->taskId, $this->agentId, $this->attempt, $this->assignmentGeneration)) {
            Log::warning('ProcessAgentTask: Task could not transition to running', [
                'task_id' => $this->taskId,
                'status' => $task['status'],
            ]);

            return;
        }
        $this->syncHandoff($payload, DistributedAgentService::TASK_RUNNING);

        $startTime = microtime(true);

        try {
            $taskDescription = $payload['task'] ?? $payload['description'] ?? $task['task_type'];
            $options = [
                'session_id' => $payload['session_id'] ?? null,
                'context' => $payload['context'] ?? [],
                'model' => $payload['model'] ?? null,
                'tree_id' => $payload['tree_id'] ?? null,
                'notify' => $payload['notify'] ?? false,
                'depth' => $payload['depth'] ?? 0,
                'delegation_depth' => $payload['delegation_depth'] ?? $payload['depth'] ?? 0,
                'handoff_id' => $payload['handoff_id'] ?? null,
                'parent_session_id' => $payload['parent_session_id'] ?? null,
                'ds_profile' => $payload['ds_profile'] ?? null,
                'sidecar' => $payload['sidecar'] ?? false,
                'distributed_task_id' => $this->taskId,
            ];

            // Determine which agent skill to use
            $skillName = $payload['skill'] ?? $task['task_type'];

            // Execute the agent loop
            $result = $agentLoop->execute($skillName, $taskDescription, $options);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($result['success']) {
                $completed = $distributedService->completeTask($this->taskId, $result, $durationMs, $this->agentId, $this->attempt, $this->assignmentGeneration);
                if ($completed) {
                    $this->syncHandoff($payload, DistributedAgentService::TASK_COMPLETED, $durationMs);
                    Log::info('ProcessAgentTask: Task completed', [
                        'task_id' => $this->taskId,
                        'agent_id' => $this->agentId,
                        'duration_ms' => $durationMs,
                        'tokens' => $result['tokens_used'] ?? 0,
                    ]);
                } else {
                    Log::warning('ProcessAgentTask: Result rejected after task ownership changed', [
                        'task_id' => $this->taskId,
                        'agent_id' => $this->agentId,
                    ]);
                }
            } else {
                // These outer receipt flags are built by AgentLoop, never model error text.
                if (($result['execution_guard_held'] ?? false) === true
                    || ($result['inference_admission_held'] ?? false) === true) {
                    throw new InferenceAdmissionException($result['error'] ?? 'Agent execution guard held.');
                }
                $changed = $distributedService->failTask(
                    $this->taskId,
                    $result['error'] ?? 'Agent execution returned failure',
                    true,
                    $this->agentId,
                    $this->attempt,
                    $this->assignmentGeneration,
                );
                if ($changed) {
                    $retrying = (int) $task['retry_count'] < (int) $task['max_retries'];
                    $this->syncHandoff(
                        $payload,
                        $retrying ? DistributedAgentService::TASK_PENDING : DistributedAgentService::TASK_FAILED,
                        $durationMs,
                        $result['error'] ?? 'Agent execution returned failure',
                        $this->assignmentGeneration + (int) $retrying
                    );
                }
            }

        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000);
            $admissionStop = InferenceAdmissionException::isAdmissionFailure($e);

            try {
                $changed = $distributedService->failTask($this->taskId, $e, ! $admissionStop, $this->agentId, $this->attempt, $this->assignmentGeneration);
                if ($changed) {
                    $retrying = ! $admissionStop && (int) $task['retry_count'] < (int) $task['max_retries'];
                    $this->syncHandoff(
                        $payload,
                        $retrying ? DistributedAgentService::TASK_PENDING : DistributedAgentService::TASK_FAILED,
                        (int) $durationMs,
                        $e->getMessage(),
                        $this->assignmentGeneration + (int) $retrying
                    );
                }

                Log::error('ProcessAgentTask: Execution failed', [
                    'task_id' => $this->taskId,
                    'error' => $e->getMessage(),
                    'duration_ms' => $durationMs,
                ]);
            } catch (Throwable $bookkeepingError) {
                if (! $admissionStop) {
                    throw $bookkeepingError;
                }
                // Failed persistence/logging does not convert the original stop into queue retry.
                try {
                    Log::warning('ProcessAgentTask: Typed stop bookkeeping held', [
                        'task_id' => $this->taskId,
                        'error_class' => $bookkeepingError::class,
                    ]);
                } catch (Throwable) {
                    // The original failure remains authoritative even if logging is unavailable.
                }
            }

            if ($admissionStop) {
                $this->fail($e);

                return;
            }

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
            $distributedService = app(DistributedAgentService::class);
            $task = $distributedService->getTask($this->taskId);
            $changed = $distributedService->failTask(
                $this->taskId,
                'Job failed: '.($exception?->getMessage() ?? 'Unknown error'),
                false,
                $this->agentId,
                $this->attempt,
                $this->assignmentGeneration,
            );
            if ($changed && $task) {
                $this->syncHandoff(
                    $task['payload'] ?? [],
                    DistributedAgentService::TASK_FAILED,
                    0,
                    'Job failed: '.($exception?->getMessage() ?? 'Unknown error')
                );
            }
        } catch (Throwable $e) {
            // Don't let failure handling failure mask original error
        }
    }

    public function tags(): array
    {
        return [
            'agent-task',
            'task:'.substr($this->taskId, 0, 8),
            'agent:'.$this->agentId,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function syncHandoff(
        array $payload,
        string $taskStatus,
        int $durationMs = 0,
        ?string $error = null,
        ?int $assignmentGeneration = null
    ): void {
        $handoffId = $payload['handoff_id'] ?? null;
        if (! is_string($handoffId) || $handoffId === '') {
            return;
        }

        try {
            app(AgentHandoffService::class)->syncDistributedTaskState(
                $handoffId,
                $this->taskId,
                $taskStatus,
                $durationMs,
                $error,
                $assignmentGeneration ?? $this->assignmentGeneration
            );
        } catch (Throwable $e) {
            Log::error('ProcessAgentTask: Handoff lifecycle sync failed', [
                'handoff_id' => $handoffId,
                'task_id' => $this->taskId,
                'task_status' => $taskStatus,
                'error_class' => $e::class,
            ]);
        }
    }

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('timeout_seconds');
        $this->tries = $this->runtimePolicyInt('tries');
        $this->backoff = $this->runtimePolicyIntList('backoff_seconds');
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return list<int>
     */
    private function runtimePolicyIntList(string $key): array
    {
        $fallback = self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (! is_array($value)) {
            return $fallback;
        }

        $ints = array_values(array_filter(
            array_map(fn ($item) => is_numeric($item) ? (int) $item : 0, $value),
            fn (int $item) => $item > 0
        ));

        return $ints !== [] ? $ints : $fallback;
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

<?php

namespace App\Engine;

use App\Controllers\NotificationController;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\NodeTimeoutException;
use App\Exceptions\WorkflowApprovalRequiredException;
use App\Exceptions\WorkflowSuspendedException;
use App\Services\ExecutionState;
use App\Services\SystemConfigService;
use App\Services\WorkflowApprovalService;
use App\Services\WorkflowEventService;
use App\Services\WorkflowReplayer;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkflowEngine
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'workflow.engine_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'default_node_timeout_seconds' => 300,
        'fallback_retry_max_attempts' => 1,
        'fallback_retry_backoff_seconds' => [5],
        'event_payload_max_bytes' => 64000,
        'event_string_truncate_chars' => 1000,
        'failure_alert_cooldown_seconds' => 300,
        'failure_alert_suppressed_errors_limit' => 10,
        'failure_alert_suppressed_errors_ttl_extra_seconds' => 60,
        'failure_alert_summary_schedule_extra_seconds' => 30,
        'failure_alert_summary_delay_extra_seconds' => 10,
        'failure_alert_summary_error_limit' => 5,
    ];

    private NodeLoader $nodeLoader;

    private DatabaseLayer $databaseLayer;

    private WorkflowEventService $eventService;

    private WorkflowReplayer $replayer;

    private ?array $workflowDefaults = null;

    private ?string $currentExecutionId = null;

    private ?string $currentTraceSessionId = null;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(
        ?WorkflowEventService $eventService = null,
        ?WorkflowReplayer $replayer = null,
        ?DatabaseLayer $databaseLayer = null,
    ) {
        $this->nodeLoader = new NodeLoader;
        $this->databaseLayer = $databaseLayer ?? new DatabaseLayer;
        $this->eventService = $eventService ?? new WorkflowEventService;
        $this->replayer = $replayer ?? new WorkflowReplayer($this->eventService);
    }

    /**
     * Execute a workflow by name
     *
     * @param  string  $workflowName  Workflow name
     * @param  array  $initialInput  Input data for the workflow
     * @param  array  $options  ['idempotency_key' => string, 'skip_idempotency_check' => bool]
     * @return array Execution result
     */
    public function executeWorkflow(string $workflowName, array $initialInput = [], array $options = []): array
    {
        $requestedTraceSessionId = $this->normalizeTraceSessionId($options['trace_session_id'] ?? null);

        // Load workflow configuration
        $workflow = $this->databaseLayer->getWorkflow($workflowName);

        if (! $workflow) {
            throw new Exception("Workflow not found: {$workflowName}");
        }

        if (! $workflow->active) {
            throw new Exception("Workflow is not active: {$workflowName}");
        }

        // Check idempotency unless explicitly skipped
        $hasIdempotencyEvidence = ! empty($initialInput)
            || ! empty($options['idempotency_key']);
        if (! ($options['skip_idempotency_check'] ?? false) && $hasIdempotencyEvidence) {
            $idempotencyCheck = $this->databaseLayer->checkIdempotency(
                $workflow->id,
                $initialInput,
                $options['idempotency_key'] ?? null
            );

            if ($idempotencyCheck['skip']) {
                Log::info('Skipping duplicate workflow execution (idempotency)', [
                    'workflow' => $workflowName,
                    'idempotency_key' => $idempotencyCheck['key'],
                    'existing_run_id' => $idempotencyCheck['existing_run']->id,
                    'existing_status' => $idempotencyCheck['existing_run']->status,
                ]);

                return [
                    'success' => true,
                    'run_id' => $idempotencyCheck['existing_run']->id,
                    'execution_id' => null,
                    'output' => null,
                    'skipped' => true,
                    'reason' => 'duplicate_idempotency_key',
                    'idempotency_key' => $idempotencyCheck['key'],
                    'trace_session_id' => $requestedTraceSessionId,
                ];
            }
        }

        // Load workflow defaults
        $this->workflowDefaults = $this->databaseLayer->getWorkflowDefaults($workflow->id);

        // Create workflow run record with idempotency key
        $runId = $this->databaseLayer->createWorkflowRun(
            $workflow->id,
            $initialInput,
            $options['idempotency_key'] ?? null
        );

        // Generate execution ID for checkpointing
        $this->currentExecutionId = (string) Str::uuid();
        $this->currentTraceSessionId = $requestedTraceSessionId ?? $this->currentExecutionId;

        if (! empty($initialInput)) {
            $this->databaseLayer->logWorkflowRunInputs($runId, $initialInput);
        }

        Log::info('Starting workflow execution', [
            'workflow' => $workflowName,
            'run_id' => $runId,
            'execution_id' => $this->currentExecutionId,
            'trace_session_id' => $this->currentTraceSessionId,
        ]);

        try {
            // Get retry configuration
            $retryConfig = $this->databaseLayer->getRetryConfig($workflow->id);

            // Execute workflow with retry logic
            $output = $this->executeWithRetry(function (int $attemptNumber) use ($workflow, $runId, $initialInput) {
                return $this->executeNodes($workflow, $runId, $initialInput, $attemptNumber);
            }, $retryConfig);

            // Mark workflow as completed
            $this->databaseLayer->updateWorkflowRun($runId, 'completed');
            $this->databaseLayer->logWorkflowRunOutputs($runId, $output);

            Log::info('Workflow execution completed', [
                'workflow' => $workflowName,
                'run_id' => $runId,
                'execution_id' => $this->currentExecutionId,
                'trace_session_id' => $this->currentTraceSessionId,
            ]);

            return [
                'success' => true,
                'run_id' => $runId,
                'execution_id' => $this->currentExecutionId,
                'trace_session_id' => $this->currentTraceSessionId,
                'output' => $output,
            ];

        } catch (WorkflowSuspendedException $exception) {
            $this->databaseLayer->updateWorkflowRun($runId, 'awaiting_approval');

            Log::info('Workflow execution suspended for operator approval', [
                'workflow' => $workflowName,
                'run_id' => $runId,
                'execution_id' => $this->currentExecutionId,
                'gate_id' => $exception->gateId,
                'node_id' => $exception->nodeId,
            ]);

            return $this->suspendedResult($exception);
        } catch (\Throwable $e) {
            $this->recordFailurePreservingAdmission($e, function () use ($runId, $workflowName, $e) {
                // Mark workflow as failed
                $this->databaseLayer->updateWorkflowRun($runId, 'failed', $e->getMessage());

                Log::error('Workflow execution failed', [
                    'workflow' => $workflowName,
                    'run_id' => $runId,
                    'execution_id' => $this->currentExecutionId,
                    'trace_session_id' => $this->currentTraceSessionId,
                    'error' => $e->getMessage(),
                ]);
            });

            throw $e;
        }
    }

    /**
     * Resume a failed workflow execution from the last checkpoint
     *
     * @param  string  $executionId  UUID of the failed execution
     * @param  array  $overrideInput  Optional input to override context
     * @return array Execution result
     */
    public function resumeWorkflow(string $executionId, array $overrideInput = [], ?int $existingRunId = null): array
    {
        // Get resume point from replayer
        $resumePoint = $this->replayer->getResumePoint($executionId);

        if (! $resumePoint['can_resume']) {
            throw new Exception("Cannot resume execution: {$executionId}");
        }

        // Find the original workflow run
        $events = $this->eventService->getEvents($executionId);
        if (empty($events)) {
            throw new Exception("No events found for execution: {$executionId}");
        }

        // Rebuild state
        $state = $this->replayer->rebuild($executionId);
        $this->currentExecutionId = $executionId;
        $this->currentTraceSessionId = $this->traceSessionIdFromEvents($events, $executionId);

        // Get context from state, merge with any overrides
        $resumeContext = array_merge($state->getContext(), $overrideInput);

        Log::info('Resuming workflow execution', [
            'execution_id' => $executionId,
            'last_completed' => $resumePoint['last_completed_node'],
            'failed_node' => $resumePoint['failed_node'],
            'completed_nodes' => count($resumePoint['completed_nodes']),
            'trace_session_id' => $this->currentTraceSessionId,
        ]);

        // Find the workflow from the first event's metadata
        $firstEvent = $events[0];
        $workflowName = $firstEvent->metadata['workflow_name'] ?? null;

        if (! $workflowName) {
            throw new Exception('Cannot determine workflow name from events');
        }

        $workflow = $this->databaseLayer->getWorkflow($workflowName);
        if (! $workflow) {
            throw new Exception("Workflow not found: {$workflowName}");
        }

        $checkpointSequence = (int) ($events[array_key_last($events)]->sequence ?? 0);
        if ($existingRunId !== null) {
            $run = $this->databaseLayer->getWorkflowRun($existingRunId);
            if ($run === null || (int) $run->workflow_id !== (int) $workflow->id) {
                throw new Exception("Approval resume run does not belong to execution: {$executionId}");
            }
            $runId = $existingRunId;
        } else {
            $resumeClaimKey = hash('sha256', "workflow-resume:{$executionId}:{$checkpointSequence}");
            $claim = $this->databaseLayer->claimWorkflowResumeRun($workflow->id, $resumeClaimKey);
            $runId = (int) $claim['run']->id;
        }
        if ($existingRunId === null && ! $claim['claimed']) {
            Log::info('Skipping duplicate workflow checkpoint resume', [
                'execution_id' => $executionId,
                'trace_session_id' => $this->currentTraceSessionId,
                'checkpoint_sequence' => $checkpointSequence,
                'run_id' => $runId,
                'run_status' => $claim['run']->status,
            ]);

            return [
                'success' => $claim['run']->status === 'completed',
                'run_id' => $runId,
                'execution_id' => $executionId,
                'trace_session_id' => $this->currentTraceSessionId,
                'resumed' => false,
                'skipped' => true,
                'reason' => 'resume_checkpoint_already_claimed',
                'status' => $claim['run']->status,
                'output' => null,
            ];
        }

        if ($resumeContext !== []) {
            $this->databaseLayer->logWorkflowRunInputs($runId, $resumeContext);
        }
        $this->workflowDefaults = $this->databaseLayer->getWorkflowDefaults($workflow->id);
        $resumeAttemptNumber = $existingRunId !== null
            ? $this->attemptNumberFromEvents($events)
            : 1;

        try {
            // Execute nodes, skipping completed ones
            $output = $this->executeNodesWithResume(
                $workflow,
                $runId,
                $resumeContext,
                $state,
                $resumeAttemptNumber
            );

            $this->databaseLayer->updateWorkflowRun($runId, 'completed');
            $this->databaseLayer->logWorkflowRunOutputs($runId, $output);

            Log::info('Resumed workflow execution completed', [
                'execution_id' => $executionId,
                'run_id' => $runId,
                'trace_session_id' => $this->currentTraceSessionId,
            ]);

            return [
                'success' => true,
                'run_id' => $runId,
                'execution_id' => $executionId,
                'trace_session_id' => $this->currentTraceSessionId,
                'resumed' => true,
                'output' => $output,
            ];

        } catch (WorkflowSuspendedException $exception) {
            $this->databaseLayer->updateWorkflowRun($runId, 'awaiting_approval');

            Log::info('Resumed workflow suspended for operator approval', [
                'execution_id' => $executionId,
                'run_id' => $runId,
                'gate_id' => $exception->gateId,
                'node_id' => $exception->nodeId,
            ]);

            return $this->suspendedResult($exception) + ['resumed' => true];
        } catch (\Throwable $e) {
            $this->recordFailurePreservingAdmission($e, function () use ($runId, $executionId, $e) {
                $this->databaseLayer->updateWorkflowRun($runId, 'failed', $e->getMessage());

                Log::error('Resumed workflow execution failed', [
                    'execution_id' => $executionId,
                    'run_id' => $runId,
                    'trace_session_id' => $this->currentTraceSessionId,
                    'error' => $e->getMessage(),
                ]);
            });

            throw $e;
        }
    }

    /** @param array<string,mixed> $overrideInput */
    public function resumeApprovedWorkflow(string $executionId, int $runId, array $overrideInput = []): array
    {
        return $this->resumeWorkflow($executionId, $overrideInput, $runId);
    }

    /**
     * Get the current execution ID
     */
    public function getCurrentExecutionId(): ?string
    {
        return $this->currentExecutionId;
    }

    public function getCurrentTraceSessionId(): ?string
    {
        return $this->currentTraceSessionId;
    }

    private function executeNodes(object $workflow, int $runId, array $input, int $attemptNumber = 1): array
    {
        $nodes = $this->databaseLayer->getWorkflowNodes($workflow->id);
        $currentInput = $input;

        $errorHandling = $this->workflowDefaults['error_handling']
            ?? $workflow->error_handling
            ?? config('app.error_handling', 'stop');

        foreach ($nodes as $node) {
            try {
                $currentInput = $this->executeNode(
                    $node,
                    $currentInput,
                    $runId,
                    $workflow->id,
                    $workflow->name,
                    $attemptNumber
                );
            } catch (WorkflowSuspendedException $exception) {
                throw $exception;
            } catch (\Throwable $e) {
                if (InferenceAdmissionException::isAdmissionFailure($e)) {
                    throw $e;
                }
                if ($errorHandling === 'continue') {
                    Log::warning('Node execution failed, continuing to next node', [
                        'node_type' => $node->node_type,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
                throw $e;
            }
        }

        return $currentInput;
    }

    /**
     * Execute nodes with resume support (skip already completed nodes)
     */
    private function executeNodesWithResume(
        object $workflow,
        int $runId,
        array $input,
        ExecutionState $state,
        int $attemptNumber = 1
    ): array {
        $nodes = $this->databaseLayer->getWorkflowNodes($workflow->id);
        $currentInput = $input;

        $errorHandling = $this->workflowDefaults['error_handling']
            ?? $workflow->error_handling
            ?? config('app.error_handling', 'stop');

        foreach ($nodes as $node) {
            $nodeId = (string) $node->id;

            // Skip nodes that were already completed
            if ($state->isNodeCompleted($nodeId)) {
                $previousOutput = $state->getNodeOutput($nodeId);
                if ($previousOutput) {
                    $currentInput = array_merge($currentInput, $previousOutput);
                }
                Log::debug('Skipping completed node during resume', [
                    'node_id' => $nodeId,
                    'node_type' => $node->node_type,
                ]);

                continue;
            }

            try {
                $currentInput = $this->executeNode(
                    $node,
                    $currentInput,
                    $runId,
                    $workflow->id,
                    $workflow->name,
                    $attemptNumber
                );
            } catch (WorkflowSuspendedException $exception) {
                throw $exception;
            } catch (\Throwable $e) {
                if (InferenceAdmissionException::isAdmissionFailure($e)) {
                    throw $e;
                }
                if ($errorHandling === 'continue') {
                    Log::warning('Node execution failed during resume, continuing', [
                        'node_type' => $node->node_type,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
                throw $e;
            }
        }

        return $currentInput;
    }

    /** @param array<int,object> $events */
    private function attemptNumberFromEvents(array $events): int
    {
        $attemptNumber = 1;

        foreach ($events as $event) {
            $candidate = $event->metadata['attempt_number'] ?? null;
            if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
                $attemptNumber = max($attemptNumber, min(1000, (int) $candidate));
            }
        }

        return $attemptNumber;
    }

    private function executeNode(
        object $node,
        array $input,
        int $runId,
        int $workflowId,
        ?string $workflowName = null,
        int $attemptNumber = 1
    ): array {
        $startTime = microtime(true);
        $nodeId = (string) $node->id;

        // Load node configuration
        $nodeConfig = $this->databaseLayer->getNodeConfigs($node->id);

        // Apply configuration hierarchy: workflow defaults -> node config
        $finalConfig = array_merge($this->workflowDefaults ?? [], $nodeConfig);

        // Get node timeout (from node config, workflow defaults, or system default)
        $timeoutSeconds = $node->timeout_seconds
            ?? $finalConfig['timeout_seconds']
            ?? $this->workflowDefaults['timeout_seconds']
            ?? $this->runtimePolicyInt('default_node_timeout_seconds');
        $finalConfig['timeout_seconds'] = $timeoutSeconds;
        $finalConfig['effective_timeout_seconds'] = $this->peekEffectiveTimeoutSeconds((int) $timeoutSeconds);
        $finalConfig['workflow_attempt_number'] = max(1, min(1000, $attemptNumber));

        // Check "only_if" condition
        if (isset($finalConfig['only_if']) && ! $this->evaluateCondition($finalConfig['only_if'], $input)) {
            Log::info('Node skipped due to only_if condition', [
                'node_type' => $node->node_type,
                'condition' => $finalConfig['only_if'],
            ]);

            return $input; // Pass through input unchanged
        }

        // Create node execution record
        $executionId = $this->databaseLayer->createNodeExecution(
            $runId,
            $node->id,
            $node->node_type,
            $node->node_order,
            $attemptNumber
        );

        // Log input
        if (! empty($input)) {
            $this->databaseLayer->logNodeExecutionInputs($executionId, $input);
        }

        // Record NodeStarted event for checkpointing
        if ($this->currentExecutionId) {
            $this->eventService->recordEvent(
                $this->currentExecutionId,
                'NodeStarted',
                $nodeId,
                ['input' => $this->truncateForEvent($input)],
                $this->withTraceMetadata([
                    'workflow_name' => $workflowName,
                    'node_type' => $node->node_type,
                    'node_order' => $node->node_order,
                    'attempt_number' => $attemptNumber,
                    'run_id' => $runId,
                    'timeout_seconds' => $timeoutSeconds,
                ])
            );
        }

        try {
            // Load and execute node with timeout
            $nodeInstance = $this->nodeLoader->loadNode($node->node_type, $finalConfig);
            $output = $this->executeWithTimeout(
                fn () => $nodeInstance->execute($input),
                $timeoutSeconds,
                $node->node_type
            );

            // Calculate duration
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->failIfTimeoutWasReturnedAsOutput(
                $output,
                $node->node_type,
                $timeoutSeconds,
                $durationMs
            );

            // Update execution record with timeout info
            $this->databaseLayer->updateNodeExecutionWithTimeout(
                $executionId,
                $durationMs,
                null,
                $timeoutSeconds,
                false
            );

            // Handle multi-stream output
            if (isset($output['streams'])) {
                foreach ($output['streams'] as $streamName => $streamData) {
                    if (isset($streamData['data'])) {
                        $this->databaseLayer->logNodeExecutionOutputs(
                            $executionId,
                            is_array($streamData['data']) ? $streamData['data'] : ['value' => $streamData['data']],
                            $streamName
                        );
                    }
                }
                // For now, use default stream as output
                $output = $output['streams']['default']['data'] ?? [];
            } else {
                // Log single output
                if (! empty($output)) {
                    $this->databaseLayer->logNodeExecutionOutputs($executionId, $output);
                }
            }

            // Record NodeCompleted event for checkpointing
            if ($this->currentExecutionId) {
                $this->eventService->recordEvent(
                    $this->currentExecutionId,
                    'NodeCompleted',
                    $nodeId,
                    ['output' => $this->truncateForEvent($output)],
                    $this->withTraceMetadata([
                        'duration_ms' => $durationMs,
                        'node_type' => $node->node_type,
                        'attempt_number' => $attemptNumber,
                    ])
                );
            }

            Log::debug('Node executed successfully', [
                'node_type' => $node->node_type,
                'execution_id' => $executionId,
                'duration_ms' => $durationMs,
            ]);

            return $output;

        } catch (WorkflowApprovalRequiredException $exception) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            if ($this->currentExecutionId === null) {
                throw new Exception('Approval nodes require workflow checkpointing.');
            }

            $this->eventService->recordEvent(
                $this->currentExecutionId,
                'SignalReceived',
                $nodeId,
                [
                    'name' => 'workflow_approval',
                    'data' => ['action' => 'pending'],
                ],
                $this->withTraceMetadata([
                    'run_id' => $runId,
                    'node_type' => 'Approval',
                    'attempt_number' => $attemptNumber,
                ])
            );
            $gateId = app(WorkflowApprovalService::class)->requestApproval(
                $runId,
                $executionId,
                [
                    'schema_version' => 1,
                    'form' => [
                        'title' => $exception->title,
                        'instructions' => $exception->instructions,
                        'response_fields' => $exception->responseFields,
                    ],
                    'resume_input' => $input,
                ],
                $exception->timeoutMinutes,
                $this->currentExecutionId,
                $nodeId,
            );
            $this->databaseLayer->markNodeExecutionAwaitingApproval($executionId, $durationMs);

            throw new WorkflowSuspendedException(
                $gateId,
                $runId,
                $this->currentExecutionId,
                $nodeId,
            );
        } catch (\Throwable $e) {
            $this->recordFailurePreservingAdmission($e, function () use ($startTime, $executionId, $timeoutSeconds, $nodeId, $node, $attemptNumber, $e) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $timedOut = str_contains($e->getMessage(), 'timed out')
                    || str_contains($e->getMessage(), 'Node timeout:')
                    || $e instanceof NodeTimeoutException;

                $this->databaseLayer->updateNodeExecutionWithTimeout(
                    $executionId,
                    $durationMs,
                    $e->getMessage(),
                    $timeoutSeconds,
                    $timedOut
                );

                // Record NodeFailed event for checkpointing
                if ($this->currentExecutionId) {
                    $this->eventService->recordEvent(
                        $this->currentExecutionId,
                        'NodeFailed',
                        $nodeId,
                        [
                            'error' => $e->getMessage(),
                            'exception' => get_class($e),
                            'timed_out' => $timedOut,
                        ],
                        $this->withTraceMetadata([
                            'duration_ms' => $durationMs,
                            'node_type' => $node->node_type,
                            'timeout_seconds' => $timeoutSeconds,
                            'attempt_number' => $attemptNumber,
                        ])
                    );
                }

                Log::error('Node execution failed', [
                    'node_type' => $node->node_type,
                    'execution_id' => $executionId,
                    'error' => $e->getMessage(),
                    'timed_out' => $timedOut,
                ]);
            });

            throw $e;
        }
    }

    /**
     * Execute a callable with timeout
     *
     * @param  callable  $callable  The function to execute
     * @param  int  $timeoutSeconds  Timeout in seconds
     * @param  string  $context  Context for error messages
     * @return mixed Result of the callable
     *
     * @throws Exception If execution times out
     */
    private function executeWithTimeout(callable $callable, int $timeoutSeconds, string $context): mixed
    {
        $startedAt = microtime(true);

        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
            $previousAsync = pcntl_async_signals(true);

            // Save remaining time on any parent alarm (e.g., scheduler job timeout)
            // so we can restore it after the node completes.
            $remainingParentAlarm = pcntl_alarm(0);
            $parentAlarmStart = microtime(true);

            // Capture the current handler BEFORE installing ours
            $previousHandler = pcntl_signal_get_handler(SIGALRM);

            // Use the lesser of node timeout and remaining parent alarm
            // to ensure we don't exceed the parent's deadline
            $effectiveTimeout = $timeoutSeconds;
            if ($remainingParentAlarm > 0) {
                $effectiveTimeout = min($timeoutSeconds, $remainingParentAlarm);
            }

            // Install handler that throws — this interrupts blocking calls when
            // signals are delivered promptly by the runtime.
            pcntl_signal(SIGALRM, function () use ($context, $timeoutSeconds, $startedAt) {
                $elapsedSeconds = max(1, (int) ceil(microtime(true) - $startedAt));

                throw new NodeTimeoutException(
                    $context,
                    $timeoutSeconds,
                    $elapsedSeconds,
                    "Node timeout: {$context} exceeded {$timeoutSeconds}s limit"
                );
            });

            pcntl_alarm($effectiveTimeout);

            try {
                $result = $callable();
                $this->failIfTimeoutWasReturnedAsOutput(
                    $result,
                    $context,
                    $timeoutSeconds,
                    (int) ((microtime(true) - $startedAt) * 1000)
                );
                $this->assertWithinNodeTimeLimit(
                    $context,
                    $timeoutSeconds,
                    $startedAt,
                    $effectiveTimeout
                );
                pcntl_alarm(0); // Cancel node alarm
                pcntl_async_signals($previousAsync);

                // Restore parent alarm with reduced time
                $this->restoreParentAlarm($remainingParentAlarm, $parentAlarmStart, $previousHandler);

                return $result;
            } catch (\Throwable $e) {
                pcntl_alarm(0);
                pcntl_async_signals($previousAsync);

                // Restore parent alarm even on failure
                $this->restoreParentAlarm($remainingParentAlarm, $parentAlarmStart, $previousHandler);

                throw $e;
            }
        }

        // Fallback: wall-clock enforcement via polling
        $startTime = microtime(true);
        Log::debug('Executing node without pcntl timeout', [
            'context' => $context,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $result = $callable();
        $this->failIfTimeoutWasReturnedAsOutput(
            $result,
            $context,
            $timeoutSeconds,
            (int) ((microtime(true) - $startedAt) * 1000)
        );
        $this->assertWithinNodeTimeLimit($context, $timeoutSeconds, $startedAt, $timeoutSeconds);

        return $result;
    }

    private function assertWithinNodeTimeLimit(
        string $context,
        int $timeoutSeconds,
        float $startedAt,
        int $effectiveTimeout
    ): void {
        $elapsedSeconds = max(0, (int) ceil(microtime(true) - $startedAt));
        $limit = max(1, $effectiveTimeout);

        if ($elapsedSeconds <= $limit) {
            return;
        }

        $message = $effectiveTimeout < $timeoutSeconds
            ? "Node timeout: {$context} exceeded {$effectiveTimeout}s effective limit ({$timeoutSeconds}s configured)"
            : "Node timeout: {$context} exceeded {$timeoutSeconds}s limit";

        throw new NodeTimeoutException($context, $timeoutSeconds, $elapsedSeconds, $message);
    }

    private function failIfTimeoutWasReturnedAsOutput(
        mixed $output,
        string $context,
        int $timeoutSeconds,
        int $durationMs
    ): void {
        $message = $this->timeoutMessageFromOutput($output);
        if ($message === null) {
            return;
        }

        $elapsedSeconds = max(1, (int) ceil($durationMs / 1000));

        throw new NodeTimeoutException($context, $timeoutSeconds, $elapsedSeconds, $message);
    }

    private function timeoutMessageFromOutput(mixed $output): ?string
    {
        if (! is_array($output)) {
            return null;
        }

        $meta = isset($output['meta']) && is_array($output['meta']) ? $output['meta'] : [];
        foreach ([
            $output['error'] ?? null,
            $meta['error_message'] ?? null,
        ] as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $message = trim((string) $candidate);
            if ($this->isNodeTimeoutMessage($message)) {
                return $message;
            }
        }

        return null;
    }

    private function isNodeTimeoutMessage(string $message): bool
    {
        return str_contains($message, 'Node timeout:')
            || preg_match("/\\bNode '.+' execution timed out after \\d+s \\(limit: \\d+s\\)/", $message) === 1;
    }

    private function peekEffectiveTimeoutSeconds(int $timeoutSeconds): int
    {
        if (! function_exists('pcntl_alarm')) {
            return $timeoutSeconds;
        }

        $remainingParentAlarm = pcntl_alarm(0);
        if ($remainingParentAlarm <= 0) {
            return $timeoutSeconds;
        }

        pcntl_alarm($remainingParentAlarm);

        return min($timeoutSeconds, $remainingParentAlarm);
    }

    /**
     * Restore a parent-level pcntl_alarm after a node finishes.
     * Subtracts elapsed time and re-installs the parent's signal handler.
     */
    private function restoreParentAlarm(int $remainingParentAlarm, float $parentAlarmStart, mixed $previousHandler): void
    {
        if ($remainingParentAlarm <= 0) {
            return;
        }

        $elapsed = (int) ceil(microtime(true) - $parentAlarmStart);
        $newRemaining = max(1, $remainingParentAlarm - $elapsed);

        // Re-install the parent's signal handler and alarm
        // $previousHandler is from pcntl_signal_get_handler(): SIG_DFL (0), SIG_IGN (1), or callable
        if (is_callable($previousHandler) || is_int($previousHandler)) {
            pcntl_signal(SIGALRM, $previousHandler);
        }
        pcntl_alarm($newRemaining);
    }

    /**
     * Truncate large data for event storage
     *
     * @param  array  $data  Data to truncate
     * @return array Truncated data
     */
    private function truncateForEvent(array $data): array
    {
        $maxSize = $this->runtimePolicyInt('event_payload_max_bytes');
        $encoded = json_encode($data);

        if (strlen($encoded) <= $maxSize) {
            return $data;
        }

        // Truncate large string values
        $stringMaxChars = $this->runtimePolicyInt('event_string_truncate_chars');

        return array_map(function ($value) use ($stringMaxChars) {
            if (is_string($value) && strlen($value) > $stringMaxChars) {
                return substr($value, 0, $stringMaxChars).'... [truncated]';
            }
            if (is_array($value)) {
                return $this->truncateForEvent($value);
            }

            return $value;
        }, $data);
    }

    /** @param array<string,mixed> $metadata */
    private function withTraceMetadata(array $metadata): array
    {
        if ($this->currentTraceSessionId !== null) {
            $metadata['trace_session_id'] = $this->currentTraceSessionId;
        }

        return $metadata;
    }

    private function normalizeTraceSessionId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)
            || strlen($value) > 100
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/', $value) !== 1) {
            throw new \InvalidArgumentException(
                'trace_session_id must be 1-100 characters using letters, numbers, dot, underscore, colon, or hyphen.'
            );
        }

        return $value;
    }

    /** @param array<int,object> $events */
    private function traceSessionIdFromEvents(array $events, string $executionId): string
    {
        foreach ($events as $event) {
            $candidate = $event->metadata['trace_session_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                try {
                    return $this->normalizeTraceSessionId($candidate) ?? $executionId;
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        }

        return $executionId;
    }

    /** Failure bookkeeping must not turn a typed stop into an ordinary retryable error. */
    private function recordFailurePreservingAdmission(\Throwable $failure, callable $record): void
    {
        try {
            $record();
        } catch (\Throwable $bookkeepingError) {
            if (! InferenceAdmissionException::isAdmissionFailure($failure)) {
                throw $bookkeepingError;
            }
            try {
                Log::warning('Admission failure bookkeeping failed; original stop retained', [
                    'exception_class' => get_class($bookkeepingError),
                ]);
            } catch (\Throwable) {
                // Logging is also bookkeeping; retain the original admission stop.
            }
        }
    }

    private function executeWithRetry(callable $execution, ?object $retryConfig): mixed
    {
        $maxAttempts = $retryConfig->max_attempts ?? $this->runtimePolicyInt('fallback_retry_max_attempts');
        $retryConfigId = $retryConfig->id ?? null;
        $notifyProvider = $retryConfig->notify_on_failure ?? 'pushover';

        if (! is_string($notifyProvider) || trim($notifyProvider) === '') {
            $notifyProvider = 'pushover';
        }

        $backoffSeconds = $retryConfigId
            ? $this->databaseLayer->getRetryBackoffIntervals($retryConfigId)
            : $this->runtimePolicyIntList('fallback_retry_backoff_seconds');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $execution($attempt);
            } catch (WorkflowSuspendedException $exception) {
                throw $exception;
            } catch (\Throwable $e) {
                if (InferenceAdmissionException::isAdmissionFailure($e)) {
                    throw $e;
                }
                if ($attempt === $maxAttempts) {
                    // Notify on final failure
                    $this->notifyFailure($notifyProvider, $e);
                    throw $e;
                }

                $delay = $backoffSeconds[min($attempt - 1, count($backoffSeconds) - 1)] ?? 5;

                Log::info('Workflow execution failed, retrying', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'backoff_seconds' => $delay,
                ]);

                sleep($delay);
            }
        }

        throw new Exception('Retry logic failed unexpectedly');
    }

    /** @return array<string,mixed> */
    private function suspendedResult(WorkflowSuspendedException $exception): array
    {
        return [
            'success' => true,
            'run_id' => $exception->runId,
            'execution_id' => $exception->executionId,
            'trace_session_id' => $this->currentTraceSessionId,
            'status' => 'awaiting_approval',
            'paused' => true,
            'gate_id' => $exception->gateId,
            'node_id' => $exception->nodeId,
            'output' => null,
        ];
    }

    private function notifyFailure(string $provider, \Throwable $exception): void
    {
        if ($provider === 'none') {
            Log::warning('WorkflowEngine: Failure notification disabled by workflow policy', [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        // Rate limit workflow failure alerts to prevent spam during cascading failures
        $alertKey = 'workflow_failure_alert_sent';
        $suppressedKey = 'workflow_failure_alerts_suppressed';
        $suppressedErrorsKey = 'workflow_failure_suppressed_errors';
        $cooldownSeconds = $this->runtimePolicyInt('failure_alert_cooldown_seconds');
        $suppressedErrorsLimit = $this->runtimePolicyInt('failure_alert_suppressed_errors_limit');
        $suppressedErrorsTtlExtraSeconds = $this->runtimePolicyInt('failure_alert_suppressed_errors_ttl_extra_seconds');
        $summaryScheduleExtraSeconds = $this->runtimePolicyInt('failure_alert_summary_schedule_extra_seconds');
        $summaryDelayExtraSeconds = $this->runtimePolicyInt('failure_alert_summary_delay_extra_seconds');
        $summaryErrorLimit = $this->runtimePolicyInt('failure_alert_summary_error_limit');

        if (Cache::has($alertKey)) {
            $suppressed = Cache::increment($suppressedKey, 1);

            // Track unique suppressed error messages for the summary
            $errors = Cache::get($suppressedErrorsKey, []);
            $errors[] = $exception->getMessage();
            Cache::put(
                $suppressedErrorsKey,
                array_slice($errors, -$suppressedErrorsLimit),
                $cooldownSeconds + $suppressedErrorsTtlExtraSeconds
            );

            Log::warning('WorkflowEngine: Alert suppressed (rate limited)', [
                'suppressed_count' => $suppressed,
                'error' => $exception->getMessage(),
            ]);

            // Schedule a summary alert when cooldown expires
            // Use a separate key to track if summary is already scheduled
            if (! Cache::has('workflow_failure_summary_scheduled')) {
                Cache::put('workflow_failure_summary_scheduled', true, $cooldownSeconds + $summaryScheduleExtraSeconds);

                // Dispatch a delayed closure to send the summary
                dispatch(function () use ($provider, $suppressedKey, $suppressedErrorsKey, $summaryErrorLimit) {
                    $count = Cache::get($suppressedKey, 0);
                    $errors = Cache::get($suppressedErrorsKey, []);
                    Cache::forget($suppressedKey);
                    Cache::forget($suppressedErrorsKey);
                    Cache::forget('workflow_failure_summary_scheduled');

                    if ($count > 0) {
                        $uniqueErrors = array_unique($errors);
                        $errorSummary = implode("\n• ", array_slice($uniqueErrors, 0, $summaryErrorLimit));

                        try {
                            $controller = new NotificationController;
                            $controller->send($provider, [
                                'source_group' => 'workflow_node_notifications',
                                'title' => "⚠️ {$count} Workflow Failures (Summary)",
                                'message' => "{$count} workflow failure(s) were suppressed during rate limiting.\n\nErrors:\n• {$errorSummary}"
                                    .(count($uniqueErrors) > $summaryErrorLimit ? "\n...and ".(count($uniqueErrors) - $summaryErrorLimit).' more' : ''),
                                'priority' => 1,
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('WorkflowEngine: Failed to send summary alert', [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                })->delay(now()->addSeconds($cooldownSeconds + $summaryDelayExtraSeconds));
            }

            return;
        }

        Cache::put($alertKey, true, $cooldownSeconds);

        // Include any previously suppressed count from last cooldown cycle
        $previousSuppressed = Cache::get($suppressedKey, 0);
        Cache::forget($suppressedKey);
        Cache::forget($suppressedErrorsKey);

        $message = $exception->getMessage();
        if ($previousSuppressed > 0) {
            $message .= "\n\n({$previousSuppressed} similar alerts were suppressed in previous cycle)";
        }

        try {
            $controller = new NotificationController;
            $controller->send($provider, [
                'source_group' => 'workflow_node_notifications',
                'title' => 'Workflow Execution Failed',
                'message' => $message,
                'priority' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send failure notification', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }
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
        if (! is_array($fallback) || ! is_array($value)) {
            return is_array($fallback) ? $fallback : [];
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

    /**
     * Evaluate a condition expression against input data
     * Supports simple comparisons: variable > value, variable == value, etc.
     *
     * @param  string  $condition  Condition expression (e.g., "count > 0")
     * @param  array  $input  Input data to evaluate against
     * @return bool True if condition is met, false otherwise
     */
    private function evaluateCondition(string $condition, array $input): bool
    {
        // Parse condition: variable operator value
        // Supported operators: >, <, >=, <=, ==, !=
        if (! preg_match('/^\s*(\w+)\s*(>=|<=|==|!=|>|<)\s*(.+?)\s*$/', $condition, $matches)) {
            Log::warning('Invalid condition syntax, treating as false', ['condition' => $condition]);

            return false;
        }

        $variable = $matches[1];
        $operator = $matches[2];
        $expectedValue = trim($matches[3]);

        // Extract actual value from input data
        $actualValue = $this->extractValue($variable, $input);

        // Convert expectedValue to appropriate type
        if ($expectedValue === 'true') {
            $expectedValue = true;
        } elseif ($expectedValue === 'false') {
            $expectedValue = false;
        } elseif ($expectedValue === 'null') {
            $expectedValue = null;
        } elseif (is_numeric($expectedValue)) {
            $expectedValue = strpos($expectedValue, '.') !== false
                ? (float) $expectedValue
                : (int) $expectedValue;
        } else {
            // Remove quotes from string values
            $expectedValue = trim($expectedValue, '"\'');
        }

        // Evaluate condition
        $result = match ($operator) {
            '>' => $actualValue > $expectedValue,
            '<' => $actualValue < $expectedValue,
            '>=' => $actualValue >= $expectedValue,
            '<=' => $actualValue <= $expectedValue,
            '==' => $actualValue == $expectedValue,
            '!=' => $actualValue != $expectedValue,
        };

        Log::debug('Condition evaluated', [
            'condition' => $condition,
            'variable' => $variable,
            'actual_value' => $actualValue,
            'operator' => $operator,
            'expected_value' => $expectedValue,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Extract a value from input data by variable name
     * Checks multiple locations: direct key, data array, meta array
     *
     * @param  string  $variable  Variable name
     * @param  array  $input  Input data
     * @return mixed Value or null if not found
     */
    private function extractValue(string $variable, array $input): mixed
    {
        // Try direct key
        if (isset($input[$variable])) {
            return $input[$variable];
        }

        // Try data array
        if (isset($input['data'][$variable])) {
            return $input['data'][$variable];
        }

        // Try meta array
        if (isset($input['meta'][$variable])) {
            return $input['meta'][$variable];
        }

        // Try data->videos array (for count)
        if ($variable === 'count' && isset($input['data']['videos'])) {
            return count($input['data']['videos']);
        }

        // Try videos array directly
        if ($variable === 'count' && isset($input['videos'])) {
            return count($input['videos']);
        }

        return null;
    }
}

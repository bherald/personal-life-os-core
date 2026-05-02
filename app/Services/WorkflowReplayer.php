<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workflow Replayer
 *
 * Rebuilds workflow execution state from events for resume capability.
 * Follows Temporal.io event-sourcing replay pattern.
 */
class WorkflowReplayer
{
    private WorkflowEventService $eventService;

    public function __construct(?WorkflowEventService $eventService = null)
    {
        $this->eventService = $eventService ?? new WorkflowEventService();
    }

    /**
     * Rebuild execution state from events
     *
     * @param string $executionId UUID for the workflow execution
     * @return ExecutionState Reconstructed state object
     */
    public function rebuild(string $executionId): ExecutionState
    {
        $events = $this->eventService->getEvents($executionId);
        $state = new ExecutionState($executionId);

        foreach ($events as $event) {
            $this->applyEvent($state, $event);
        }

        Log::info('WorkflowReplayer: State rebuilt', [
            'execution_id' => $executionId,
            'events_replayed' => count($events),
            'completed_nodes' => count($state->getCompletedNodes()),
            'failed_nodes' => count($state->getFailedNodes()),
        ]);

        return $state;
    }

    /**
     * Apply a single event to the state
     *
     * @param ExecutionState $state State to modify
     * @param object $event Event to apply
     */
    private function applyEvent(ExecutionState $state, object $event): void
    {
        match ($event->event_type) {
            'NodeStarted' => $state->markNodeStarted(
                $event->node_id,
                $event->payload['input'] ?? []
            ),
            'NodeCompleted' => $state->markNodeCompleted(
                $event->node_id,
                $event->payload['output'] ?? $event->payload
            ),
            'NodeFailed' => $state->markNodeFailed(
                $event->node_id,
                $event->payload['error'] ?? 'Unknown error',
                $event->payload['exception'] ?? null
            ),
            'SignalReceived' => $state->addSignal(
                $event->payload['name'] ?? 'unknown',
                $event->payload['data'] ?? []
            ),
            'VariableSet' => $state->setVariable(
                $event->payload['name'] ?? '',
                $event->payload['value'] ?? null
            ),
            default => Log::warning('WorkflowReplayer: Unknown event type', [
                'event_type' => $event->event_type,
            ]),
        };
    }

    /**
     * Get resume point for a failed execution
     *
     * @param string $executionId UUID for the workflow execution
     * @return array Resume info with last_completed_node, failed_node, and context
     */
    public function getResumePoint(string $executionId): array
    {
        $state = $this->rebuild($executionId);

        $lastCompleted = $this->eventService->getLastCompletedNode($executionId);
        $lastFailed = $this->eventService->getLastFailedNode($executionId);

        return [
            'execution_id' => $executionId,
            'last_completed_node' => $lastCompleted,
            'failed_node' => $lastFailed?->node_id,
            'failed_error' => $lastFailed?->payload['error'] ?? null,
            'completed_nodes' => $state->getCompletedNodes(),
            'context' => $state->getContext(),
            'variables' => $state->getVariables(),
            'can_resume' => $lastFailed !== null,
        ];
    }

    /**
     * Check if an execution can be resumed
     *
     * @param string $executionId UUID for the workflow execution
     * @return bool True if execution has events and can potentially resume
     */
    public function canResume(string $executionId): bool
    {
        $stats = $this->eventService->getExecutionStats($executionId);
        return ($stats['total_events'] ?? 0) > 0
            && ($stats['nodes_failed'] ?? 0) > 0;
    }
}

/**
 * Execution State Value Object
 *
 * Represents the reconstructed state of a workflow execution.
 */
class ExecutionState
{
    private string $executionId;
    private array $completedNodes = [];
    private array $failedNodes = [];
    private array $startedNodes = [];
    private array $nodeOutputs = [];
    private array $variables = [];
    private array $signals = [];
    private array $context = [];

    public function __construct(string $executionId)
    {
        $this->executionId = $executionId;
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function markNodeStarted(string $nodeId, array $input = []): void
    {
        $this->startedNodes[$nodeId] = [
            'started_at' => now()->toISOString(),
            'input' => $input,
        ];
        // Update context with input
        $this->context = array_merge($this->context, $input);
    }

    public function markNodeCompleted(string $nodeId, array $output): void
    {
        $this->completedNodes[$nodeId] = [
            'completed_at' => now()->toISOString(),
        ];
        $this->nodeOutputs[$nodeId] = $output;
        // Update context with output
        $this->context = array_merge($this->context, $output);
    }

    public function markNodeFailed(string $nodeId, string $error, ?string $exception = null): void
    {
        $this->failedNodes[$nodeId] = [
            'failed_at' => now()->toISOString(),
            'error' => $error,
            'exception' => $exception,
        ];
    }

    public function setVariable(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
    }

    public function addSignal(string $name, array $data): void
    {
        $this->signals[] = [
            'name' => $name,
            'data' => $data,
            'received_at' => now()->toISOString(),
        ];
    }

    public function getCompletedNodes(): array
    {
        return array_keys($this->completedNodes);
    }

    public function getFailedNodes(): array
    {
        return array_keys($this->failedNodes);
    }

    public function getStartedNodes(): array
    {
        return array_keys($this->startedNodes);
    }

    public function getNodeOutput(string $nodeId): ?array
    {
        return $this->nodeOutputs[$nodeId] ?? null;
    }

    public function getAllNodeOutputs(): array
    {
        return $this->nodeOutputs;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getVariable(string $name, mixed $default = null): mixed
    {
        return $this->variables[$name] ?? $default;
    }

    public function getSignals(): array
    {
        return $this->signals;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function isNodeCompleted(string $nodeId): bool
    {
        return isset($this->completedNodes[$nodeId]);
    }

    public function isNodeFailed(string $nodeId): bool
    {
        return isset($this->failedNodes[$nodeId]);
    }

    public function getLastCompletedNodeId(): ?string
    {
        if (empty($this->completedNodes)) {
            return null;
        }
        return array_key_last($this->completedNodes);
    }

    /**
     * Get context for resuming from a specific node
     *
     * @param string $resumeFromNodeId Node to resume from
     * @return array Context/input for the resume node
     */
    public function getResumeContext(string $resumeFromNodeId): array
    {
        // Return the accumulated context up to the resume point
        return $this->context;
    }
}

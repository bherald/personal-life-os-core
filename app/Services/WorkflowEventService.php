<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workflow Event Service
 *
 * Records workflow events for checkpointing and state reconstruction.
 * Uses event-sourcing pattern (Temporal.io style) for replay capability.
 */
class WorkflowEventService
{
    /**
     * Valid event types matching database ENUM
     */
    private const VALID_EVENT_TYPES = [
        'NodeStarted',
        'NodeCompleted',
        'NodeFailed',
        'SignalReceived',
        'VariableSet',
    ];

    /**
     * Record a workflow event
     *
     * @param string $executionId UUID for the workflow execution
     * @param string $eventType One of: NodeStarted, NodeCompleted, NodeFailed, SignalReceived, VariableSet
     * @param string|null $nodeId Node identifier (workflow_node.id or custom)
     * @param array $payload Event-specific data
     * @param array $metadata Contextual info (duration_ms, attempt, etc.)
     * @return int The event ID
     */
    public function recordEvent(
        string $executionId,
        string $eventType,
        ?string $nodeId,
        array $payload,
        array $metadata = []
    ): int {
        if (!in_array($eventType, self::VALID_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid event type: {$eventType}");
        }

        $sequence = $this->getNextSequence($executionId);

        DB::insert("
            INSERT INTO workflow_events
            (execution_id, sequence, event_type, node_id, payload, metadata, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ", [
            $executionId,
            $sequence,
            $eventType,
            $nodeId,
            json_encode($payload),
            json_encode($metadata),
        ]);

        $eventId = (int) DB::getPdo()->lastInsertId();

        Log::debug('WorkflowEventService: Event recorded', [
            'event_id' => $eventId,
            'execution_id' => $executionId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'node_id' => $nodeId,
        ]);

        return $eventId;
    }

    /**
     * Get all events for an execution in sequence order
     *
     * @param string $executionId UUID for the workflow execution
     * @return array Array of event objects
     */
    public function getEvents(string $executionId): array
    {
        $events = DB::select("
            SELECT id, execution_id, sequence, event_type, node_id, payload, metadata, recorded_at
            FROM workflow_events
            WHERE execution_id = ?
            ORDER BY sequence ASC
        ", [$executionId]);

        return array_map(function ($event) {
            $event->payload = json_decode($event->payload, true) ?? [];
            $event->metadata = json_decode($event->metadata, true) ?? [];
            return $event;
        }, $events);
    }

    /**
     * Get the last successfully completed node for an execution
     *
     * @param string $executionId UUID for the workflow execution
     * @return string|null Node ID of last completed node, or null if none
     */
    public function getLastCompletedNode(string $executionId): ?string
    {
        $result = DB::selectOne("
            SELECT node_id
            FROM workflow_events
            WHERE execution_id = ?
              AND event_type = 'NodeCompleted'
            ORDER BY sequence DESC
            LIMIT 1
        ", [$executionId]);

        return $result?->node_id;
    }

    /**
     * Get the last failed node for an execution
     *
     * @param string $executionId UUID for the workflow execution
     * @return object|null Event object with node_id and error info
     */
    public function getLastFailedNode(string $executionId): ?object
    {
        $result = DB::selectOne("
            SELECT node_id, payload, metadata, recorded_at
            FROM workflow_events
            WHERE execution_id = ?
              AND event_type = 'NodeFailed'
            ORDER BY sequence DESC
            LIMIT 1
        ", [$executionId]);

        if ($result) {
            $result->payload = json_decode($result->payload, true) ?? [];
            $result->metadata = json_decode($result->metadata, true) ?? [];
        }

        return $result;
    }

    /**
     * Check if a node was completed in this execution
     *
     * @param string $executionId UUID for the workflow execution
     * @param string $nodeId Node identifier to check
     * @return bool True if node was completed
     */
    public function wasNodeCompleted(string $executionId, string $nodeId): bool
    {
        $count = DB::scalar("
            SELECT COUNT(*)
            FROM workflow_events
            WHERE execution_id = ?
              AND node_id = ?
              AND event_type = 'NodeCompleted'
        ", [$executionId, $nodeId]);

        return $count > 0;
    }

    /**
     * Get the output of a completed node from events
     *
     * @param string $executionId UUID for the workflow execution
     * @param string $nodeId Node identifier
     * @return array|null Node output or null if not found
     */
    public function getNodeOutput(string $executionId, string $nodeId): ?array
    {
        $result = DB::selectOne("
            SELECT payload
            FROM workflow_events
            WHERE execution_id = ?
              AND node_id = ?
              AND event_type = 'NodeCompleted'
            ORDER BY sequence DESC
            LIMIT 1
        ", [$executionId, $nodeId]);

        if (!$result) {
            return null;
        }

        $payload = json_decode($result->payload, true) ?? [];
        return $payload['output'] ?? $payload;
    }

    /**
     * Get all variable sets for an execution
     *
     * @param string $executionId UUID for the workflow execution
     * @return array Associative array of variable name => value
     */
    public function getVariables(string $executionId): array
    {
        $events = DB::select("
            SELECT payload
            FROM workflow_events
            WHERE execution_id = ?
              AND event_type = 'VariableSet'
            ORDER BY sequence ASC
        ", [$executionId]);

        $variables = [];
        foreach ($events as $event) {
            $payload = json_decode($event->payload, true) ?? [];
            if (isset($payload['name'], $payload['value'])) {
                $variables[$payload['name']] = $payload['value'];
            }
        }

        return $variables;
    }

    /**
     * Record a variable set event
     *
     * @param string $executionId UUID for the workflow execution
     * @param string $name Variable name
     * @param mixed $value Variable value
     * @return int Event ID
     */
    public function setVariable(string $executionId, string $name, mixed $value): int
    {
        return $this->recordEvent($executionId, 'VariableSet', null, [
            'name' => $name,
            'value' => $value,
        ]);
    }

    /**
     * Get summary statistics for an execution
     *
     * @param string $executionId UUID for the workflow execution
     * @return array Stats including event counts and duration
     */
    public function getExecutionStats(string $executionId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_events,
                SUM(CASE WHEN event_type = 'NodeStarted' THEN 1 ELSE 0 END) as nodes_started,
                SUM(CASE WHEN event_type = 'NodeCompleted' THEN 1 ELSE 0 END) as nodes_completed,
                SUM(CASE WHEN event_type = 'NodeFailed' THEN 1 ELSE 0 END) as nodes_failed,
                MIN(recorded_at) as first_event,
                MAX(recorded_at) as last_event
            FROM workflow_events
            WHERE execution_id = ?
        ", [$executionId]);

        return [
            'total_events' => (int) $stats->total_events,
            'nodes_started' => (int) $stats->nodes_started,
            'nodes_completed' => (int) $stats->nodes_completed,
            'nodes_failed' => (int) $stats->nodes_failed,
            'first_event' => $stats->first_event,
            'last_event' => $stats->last_event,
        ];
    }

    /**
     * Get next sequence number for an execution (auto-increment per execution)
     *
     * @param string $executionId UUID for the workflow execution
     * @return int Next sequence number
     */
    private function getNextSequence(string $executionId): int
    {
        $maxSequence = DB::scalar("
            SELECT COALESCE(MAX(sequence), 0)
            FROM workflow_events
            WHERE execution_id = ?
        ", [$executionId]);

        return ((int) $maxSequence) + 1;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

/**
 * Compensation Service
 *
 * Implements the Saga pattern for workflow compensation/rollback.
 * Defines "undo" operations for workflow nodes and auto-reverses on workflow failure.
 *
 * Pattern References:
 * - Prefect compensation pattern
 * - Saga pattern for distributed transactions
 *
 * Compensation handlers are registered per node_type and executed in reverse order
 * when a workflow fails after partial execution.
 *
 * Usage:
 * ```php
 * $compensationService = app(CompensationService::class);
 *
 * // Register a compensation handler
 * $compensationService->registerHandler('EmailNode', 'App\\Services\\CompensationHandlers\\EmailCompensationHandler');
 *
 * // Execute compensation for a failed workflow
 * $result = $compensationService->compensateWorkflow($executionId, $failedNodeId);
 * ```
 */
class CompensationService
{
    private WorkflowEventService $eventService;

    /**
     * In-memory handler registry (supplements database registry)
     */
    private array $handlers = [];

    /**
     * Built-in compensation handlers for common node types
     */
    private const BUILTIN_HANDLERS = [
        'EmailNode' => 'compensateEmail',
        'JoplinWriteNode' => 'compensateJoplinWrite',
        'RAGIndex' => 'compensateRAGIndex',
        'PushoverNotify' => 'compensatePushover',
    ];

    public function __construct(?WorkflowEventService $eventService = null)
    {
        $this->eventService = $eventService ?? new WorkflowEventService();
    }

    /**
     * Register a compensation handler for a node type
     *
     * @param string $nodeType The node type (e.g., 'EmailNode')
     * @param string $handlerClass Fully qualified class name or callable method name
     * @param array $config Optional handler configuration
     * @return int Handler ID
     */
    public function registerHandler(string $nodeType, string $handlerClass, array $config = []): int
    {
        // Check if handler already exists
        $existing = DB::selectOne(
            "SELECT id FROM compensation_handlers WHERE node_type = ? AND active = 1",
            [$nodeType]
        );

        if ($existing) {
            // Update existing handler
            DB::update(
                "UPDATE compensation_handlers SET handler_class = ?, config = ?, updated_at = NOW() WHERE id = ?",
                [$handlerClass, json_encode($config), $existing->id]
            );
            return $existing->id;
        }

        // Insert new handler
        DB::insert(
            "INSERT INTO compensation_handlers (node_type, handler_class, config, active, created_at, updated_at)
             VALUES (?, ?, ?, 1, NOW(), NOW())",
            [$nodeType, $handlerClass, json_encode($config)]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Register an in-memory handler (not persisted to database)
     *
     * @param string $nodeType The node type
     * @param callable $handler The handler callback
     */
    public function registerMemoryHandler(string $nodeType, callable $handler): void
    {
        $this->handlers[$nodeType] = $handler;
    }

    /**
     * Get the compensation handler for a node type
     *
     * @param string $nodeType The node type
     * @param string|null $nodeId Optional node ID to check node-level handler first
     * @return array|null Handler info or null if not found
     */
    public function getHandler(string $nodeType, ?string $nodeId = null): ?array
    {
        // Check node-level handler first (from workflow_nodes table)
        if ($nodeId) {
            $nodeHandler = $this->getNodeLevelHandler($nodeId);
            if ($nodeHandler) {
                return $nodeHandler;
            }
        }

        // Check in-memory handlers
        if (isset($this->handlers[$nodeType])) {
            return [
                'type' => 'memory',
                'handler' => $this->handlers[$nodeType],
            ];
        }

        // Check database registry
        $handler = DB::selectOne(
            "SELECT * FROM compensation_handlers WHERE node_type = ? AND active = 1",
            [$nodeType]
        );

        if ($handler) {
            return [
                'type' => 'database',
                'id' => $handler->id,
                'handler_class' => $handler->handler_class,
                'config' => json_decode($handler->config, true) ?? [],
            ];
        }

        // Check built-in handlers
        if (isset(self::BUILTIN_HANDLERS[$nodeType])) {
            return [
                'type' => 'builtin',
                'method' => self::BUILTIN_HANDLERS[$nodeType],
            ];
        }

        return null;
    }

    /**
     * Get compensation handler defined at the node level (workflow_nodes table)
     *
     * @param string $nodeId The node ID
     * @return array|null Handler info or null if not defined
     */
    private function getNodeLevelHandler(string $nodeId): ?array
    {
        $node = DB::selectOne(
            "SELECT compensation_handler, compensation_config FROM workflow_nodes WHERE id = ?",
            [$nodeId]
        );

        if (!$node || !$node->compensation_handler) {
            return null;
        }

        return [
            'type' => 'node_level',
            'handler_class' => $node->compensation_handler,
            'config' => $node->compensation_config ? json_decode($node->compensation_config, true) : [],
        ];
    }

    /**
     * Execute compensation for a failed workflow execution
     *
     * Rolls back completed nodes in reverse order starting from the node
     * before the failed node.
     *
     * @param string $executionId UUID of the workflow execution
     * @param string|null $failedNodeId ID of the failed node (optional, auto-detected)
     * @return array Compensation result
     */
    public function compensateWorkflow(string $executionId, ?string $failedNodeId = null): array
    {
        $startTime = microtime(true);

        // Get all events for the execution
        $events = $this->eventService->getEvents($executionId);

        if (empty($events)) {
            return [
                'success' => false,
                'error' => 'No events found for execution',
                'execution_id' => $executionId,
            ];
        }

        // Find the failed node if not provided
        if (!$failedNodeId) {
            $failedEvent = $this->eventService->getLastFailedNode($executionId);
            $failedNodeId = $failedEvent?->node_id;
        }

        // Get completed nodes in order
        $completedNodes = $this->getCompletedNodesBeforeFailure($events, $failedNodeId);

        if (empty($completedNodes)) {
            return [
                'success' => true,
                'message' => 'No completed nodes to compensate',
                'execution_id' => $executionId,
                'compensated_count' => 0,
            ];
        }

        // Create compensation log entry
        $logId = $this->createCompensationLog($executionId, $failedNodeId, $completedNodes);

        // Execute compensation in reverse order (LIFO - last completed first)
        $reversedNodes = array_reverse($completedNodes);
        $compensated = [];
        $errors = [];

        foreach ($reversedNodes as $node) {
            $nodeCompensation = $this->compensateNode($node, $executionId, $logId);

            if ($nodeCompensation['success']) {
                $compensated[] = $node['node_id'];
            } else {
                $errors[] = [
                    'node_id' => $node['node_id'],
                    'node_type' => $node['node_type'],
                    'error' => $nodeCompensation['error'],
                ];
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Update compensation log
        $this->updateCompensationLog($logId, $compensated, $errors, $durationMs);

        $success = empty($errors);

        Log::info("CompensationService: Workflow compensation completed", [
            'execution_id' => $executionId,
            'compensated_count' => count($compensated),
            'error_count' => count($errors),
            'duration_ms' => $durationMs,
        ]);

        return [
            'success' => $success,
            'execution_id' => $executionId,
            'log_id' => $logId,
            'compensated' => $compensated,
            'errors' => $errors,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Compensate a single node
     *
     * @param array $node Node info from events
     * @param string $executionId Execution ID
     * @param int $logId Compensation log ID
     * @return array Result with success status
     */
    public function compensateNode(array $node, string $executionId, int $logId): array
    {
        $nodeType = $node['node_type'];
        $nodeId = $node['node_id'];
        $output = $node['output'] ?? [];

        $handler = $this->getHandler($nodeType, $nodeId);

        if (!$handler) {
            // No handler registered - log as skipped but not an error
            $this->logNodeCompensation($logId, $nodeId, $nodeType, 'skipped', null, 'No compensation handler registered');
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'No compensation handler registered',
            ];
        }

        try {
            $result = $this->executeHandler($handler, $nodeType, $nodeId, $output, $executionId);
            $this->logNodeCompensation($logId, $nodeId, $nodeType, 'completed', $result);

            return [
                'success' => true,
                'result' => $result,
            ];
        } catch (Throwable $e) {
            $this->logNodeCompensation($logId, $nodeId, $nodeType, 'failed', null, $e->getMessage());

            Log::error("CompensationService: Node compensation failed", [
                'node_id' => $nodeId,
                'node_type' => $nodeType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute a compensation handler
     *
     * @param array $handler Handler info
     * @param string $nodeType Node type
     * @param string $nodeId Node ID
     * @param array $output Node output to compensate
     * @param string $executionId Execution ID
     * @return array Handler result
     */
    private function executeHandler(array $handler, string $nodeType, string $nodeId, array $output, string $executionId): array
    {
        switch ($handler['type']) {
            case 'memory':
                return call_user_func($handler['handler'], $nodeId, $output, $executionId);

            case 'node_level':
            case 'database':
                $class = $handler['handler_class'];
                if (class_exists($class)) {
                    $instance = app($class);
                    if (method_exists($instance, 'compensate')) {
                        return $instance->compensate($nodeId, $output, $executionId, $handler['config'] ?? []);
                    }
                }
                // If class doesn't exist, try as a method name on this class
                if (method_exists($this, $class)) {
                    return $this->$class($nodeId, $output, $executionId);
                }
                throw new Exception("Handler class not found: {$class}");

            case 'builtin':
                $method = $handler['method'];
                return $this->$method($nodeId, $output, $executionId);

            default:
                throw new Exception("Unknown handler type: {$handler['type']}");
        }
    }

    /**
     * Get completed nodes before the failure point
     *
     * @param array $events All events for the execution
     * @param string|null $failedNodeId Failed node ID
     * @return array Completed nodes with their outputs
     */
    private function getCompletedNodesBeforeFailure(array $events, ?string $failedNodeId): array
    {
        $completedNodes = [];

        foreach ($events as $event) {
            if ($event->event_type !== 'NodeCompleted') {
                continue;
            }

            // Stop at failed node (don't include it)
            if ($failedNodeId && $event->node_id === $failedNodeId) {
                break;
            }

            $completedNodes[] = [
                'node_id' => $event->node_id,
                'node_type' => $event->metadata['node_type'] ?? 'Unknown',
                'output' => $event->payload['output'] ?? [],
                'completed_at' => $event->recorded_at,
            ];
        }

        return $completedNodes;
    }

    /**
     * Create a compensation log entry
     */
    private function createCompensationLog(string $executionId, ?string $failedNodeId, array $nodesToCompensate): int
    {
        // compensation_log table dropped (D4 decision). Stub — no persistence.
        return 0;
    }

    /**
     * Update compensation log after completion
     */
    private function updateCompensationLog(int $logId, array $compensated, array $errors, int $durationMs): void
    {
        // compensation_log table dropped (D4 decision). Stub — no-op.
    }

    /**
     * Log individual node compensation
     */
    private function logNodeCompensation(
        int $logId,
        string $nodeId,
        string $nodeType,
        string $status,
        ?array $result = null,
        ?string $error = null
    ): void {
        // compensation_log_nodes table dropped (D4 decision). Stub — no-op.
    }

    /**
     * Get compensation log with details
     *
     * @param int $logId Compensation log ID
     * @return array|null Log details
     */
    public function getCompensationLog(int $logId): ?array
    {
        // compensation_log table dropped (D4 decision). Stub.
        return null;
    }

    /**
     * Get all compensation logs for an execution
     *
     * @param string $executionId Execution ID
     * @return array Compensation logs
     */
    public function getCompensationLogsForExecution(string $executionId): array
    {
        // compensation_log table dropped (D4 decision). Stub.
        return [];
    }

    /**
     * Get all registered handlers
     *
     * @return array List of handlers
     */
    public function getAllHandlers(): array
    {
        $dbHandlers = DB::select("SELECT * FROM compensation_handlers WHERE active = 1 ORDER BY node_type");

        $handlers = [];

        // Add database handlers
        foreach ($dbHandlers as $handler) {
            $handlers[$handler->node_type] = [
                'id' => $handler->id,
                'type' => 'database',
                'handler_class' => $handler->handler_class,
                'config' => json_decode($handler->config, true) ?? [],
            ];
        }

        // Add built-in handlers (if not overridden)
        foreach (self::BUILTIN_HANDLERS as $nodeType => $method) {
            if (!isset($handlers[$nodeType])) {
                $handlers[$nodeType] = [
                    'type' => 'builtin',
                    'method' => $method,
                ];
            }
        }

        // Add memory handlers (if not overridden)
        foreach ($this->handlers as $nodeType => $handler) {
            if (!isset($handlers[$nodeType])) {
                $handlers[$nodeType] = [
                    'type' => 'memory',
                    'handler' => '(callable)',
                ];
            }
        }

        return $handlers;
    }

    /**
     * Deactivate a handler
     *
     * @param string $nodeType Node type
     * @return bool Success
     */
    public function deactivateHandler(string $nodeType): bool
    {
        $affected = DB::update(
            "UPDATE compensation_handlers SET active = 0, updated_at = NOW() WHERE node_type = ?",
            [$nodeType]
        );

        // Also remove from memory handlers
        unset($this->handlers[$nodeType]);

        return $affected > 0;
    }

    // =========================================================================
    // Built-in Compensation Handlers
    // =========================================================================

    /**
     * Compensate EmailNode - delete queued draft
     */
    private function compensateEmail(string $nodeId, array $output, string $executionId): array
    {
        $draftId = $output['data']['draft_id'] ?? $output['draft_id'] ?? null;

        if (!$draftId) {
            return ['action' => 'skip', 'reason' => 'No draft_id in output'];
        }

        // Delete the queued draft
        $deleted = DB::delete(
            "DELETE FROM email_reply_drafts WHERE id = ? AND status = 'pending'",
            [$draftId]
        );

        Log::info("CompensationService: Email draft deleted", [
            'draft_id' => $draftId,
            'deleted' => $deleted > 0,
        ]);

        return [
            'action' => 'delete_draft',
            'draft_id' => $draftId,
            'deleted' => $deleted > 0,
        ];
    }

    /**
     * Compensate JoplinWriteNode - delete created note
     */
    private function compensateJoplinWrite(string $nodeId, array $output, string $executionId): array
    {
        $noteId = $output['data']['note_id'] ?? $output['note_id'] ?? null;

        if (!$noteId) {
            return ['action' => 'skip', 'reason' => 'No note_id in output'];
        }

        // Call Joplin API to delete note
        try {
            $joplinService = app(\App\Services\JoplinWriteService::class);
            $result = $joplinService->deleteNote($noteId);

            return [
                'action' => 'delete_note',
                'note_id' => $noteId,
                'deleted' => $result,
            ];
        } catch (Throwable $e) {
            return [
                'action' => 'delete_note',
                'note_id' => $noteId,
                'deleted' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Compensate RAGIndex - delete indexed document
     */
    private function compensateRAGIndex(string $nodeId, array $output, string $executionId): array
    {
        $documentId = $output['data']['document_id'] ?? $output['document_id'] ?? null;

        if (!$documentId) {
            return ['action' => 'skip', 'reason' => 'No document_id in output'];
        }

        // Delete from RAG documents (PostgreSQL)
        try {
            $ragService = app(\App\Services\RAGService::class);
            $result = $ragService->deleteDocument($documentId);

            return [
                'action' => 'delete_document',
                'document_id' => $documentId,
                'deleted' => $result,
            ];
        } catch (Throwable $e) {
            return [
                'action' => 'delete_document',
                'document_id' => $documentId,
                'deleted' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Compensate PushoverNotify - notifications cannot be undelivered
     * This is a no-op handler that logs the compensation attempt
     */
    private function compensatePushover(string $nodeId, array $output, string $executionId): array
    {
        // Pushover notifications cannot be recalled once sent
        // Log for audit purposes
        Log::info("CompensationService: Pushover notification cannot be compensated (already delivered)", [
            'node_id' => $nodeId,
            'execution_id' => $executionId,
        ]);

        return [
            'action' => 'no_op',
            'reason' => 'Pushover notifications cannot be undelivered',
            'logged' => true,
        ];
    }

    // =========================================================================
    // Statistics and Monitoring
    // =========================================================================

    /**
     * Get compensation statistics
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        // compensation_log table dropped (D4 decision). Stub — return zeroes.
        $handlerCount = DB::scalar("SELECT COUNT(*) FROM compensation_handlers WHERE active = 1");

        return [
            'by_status' => [],
            'last_24h' => ['total' => 0, 'completed' => 0, 'partial' => 0, 'avg_duration_ms' => 0],
            'registered_handlers' => (int) $handlerCount,
            'builtin_handlers' => count(self::BUILTIN_HANDLERS),
        ];
    }
}

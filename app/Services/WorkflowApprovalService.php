<?php

namespace App\Services;

use App\Controllers\NotificationController;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class WorkflowApprovalService
{
    public function __construct(private readonly ?WorkflowEventService $eventService = null) {}

    private function getNotifier(): NotificationController
    {
        return new NotificationController;
    }

    /** @param array<string,mixed>|null $context */
    public function requestApproval(
        int $runId,
        ?int $nodeExecutionId,
        ?array $context = null,
        int $timeoutMinutes = 1440,
        ?string $executionId = null,
        ?string $nodeId = null,
    ): int {
        $encodedContext = $context === null ? null : json_encode($context, JSON_THROW_ON_ERROR);

        try {
            DB::insert(
                "INSERT INTO workflow_approval_gates
                    (workflow_run_id, node_execution_id, execution_id, node_id, approval_type, status, requested_at, timeout_minutes, context)
                 VALUES (?, ?, ?, ?, 'manual', 'pending', ?, ?, ?)",
                [$runId, $nodeExecutionId, $executionId, $nodeId, now(), $timeoutMinutes, $encodedContext]
            );
            $gateId = (int) DB::getPdo()->lastInsertId();
        } catch (QueryException $exception) {
            if ($executionId === null || $nodeId === null || ! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $existing = DB::selectOne(
                'SELECT id FROM workflow_approval_gates WHERE execution_id = ? AND node_id = ? LIMIT 1',
                [$executionId, $nodeId]
            );
            if ($existing === null) {
                throw $exception;
            }

            return (int) $existing->id;
        }

        $workflowName = DB::selectOne(
            'SELECT w.name FROM workflow_runs wr JOIN workflows w ON w.id = wr.workflow_id WHERE wr.id = ?',
            [$runId]
        );

        try {
            $this->getNotifier()->send('pushover', [
                'source_group' => 'agent_approval_review',
                'title' => 'Workflow Approval Required',
                'message' => sprintf(
                    'Workflow "%s" (run #%d) requires approval. Gate #%d, timeout: %d min.',
                    $workflowName->name ?? 'Unknown',
                    $runId,
                    $gateId,
                    $timeoutMinutes
                ),
                'priority' => 0,
            ]);
        } catch (Exception $exception) {
            Log::warning('WorkflowApproval: Failed to send notification', ['error' => $exception->getMessage()]);
        }

        Log::info('WorkflowApproval: Approval requested', [
            'gate_id' => $gateId,
            'run_id' => $runId,
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'timeout_minutes' => $timeoutMinutes,
        ]);

        return $gateId;
    }

    /**
     * Resolve a one-shot approval decision and persist the replay boundary.
     *
     * @param  array<string,mixed>  $inputs
     * @return array<string,mixed>
     */
    public function decide(
        int $gateId,
        string $action,
        array $inputs = [],
        ?string $respondedBy = null,
        ?string $notes = null,
    ): array {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Approval action must be approve or reject.');
        }

        return DB::transaction(function () use ($gateId, $action, $inputs, $respondedBy, $notes): array {
            $gate = DB::table('workflow_approval_gates')->where('id', $gateId)->lockForUpdate()->first();
            if ($gate === null) {
                throw new InvalidArgumentException("Workflow approval gate {$gateId} was not found.");
            }

            if ($gate->status !== 'pending') {
                return [
                    'changed' => false,
                    'gate_id' => $gateId,
                    'status' => $gate->status,
                    'run_id' => (int) $gate->workflow_run_id,
                ];
            }

            if ($this->gateHasExpired($gate)) {
                $this->expireLockedGate($gate);

                return [
                    'changed' => false,
                    'gate_id' => $gateId,
                    'status' => 'expired',
                    'run_id' => (int) $gate->workflow_run_id,
                ];
            }

            $context = $this->decodeContext($gate->context);
            $form = is_array($context['form'] ?? null) ? $context['form'] : [];
            $validatedInputs = $action === 'approve'
                ? $this->validateResponseInputs($form['response_fields'] ?? [], $inputs)
                : [];
            $decision = [
                'action' => $action,
                'inputs' => $validatedInputs,
                'responded_by' => $respondedBy,
                'responded_at' => now()->toISOString(),
            ];
            $status = $action === 'approve' ? 'approved' : 'rejected';

            DB::update(
                'UPDATE workflow_approval_gates
                 SET status = ?, responded_at = ?, responded_by = ?, response_notes = ?, decision_payload = ?
                 WHERE id = ? AND status = ?',
                [$status, now(), $respondedBy, $notes, json_encode($decision, JSON_THROW_ON_ERROR), $gateId, 'pending']
            );

            $executionId = is_string($gate->execution_id) ? $gate->execution_id : null;
            $nodeId = is_string($gate->node_id) ? $gate->node_id : null;
            if ($executionId !== null) {
                $this->events()->recordEvent(
                    $executionId,
                    'SignalReceived',
                    $nodeId,
                    ['name' => 'workflow_approval', 'data' => ['gate_id' => $gateId] + $decision],
                    ['run_id' => (int) $gate->workflow_run_id, 'approval_status' => $status]
                );
            }

            if ($action === 'approve') {
                $approvalOutput = ['approval' => ['gate_id' => $gateId] + $decision];
                if ($executionId !== null && $nodeId !== null) {
                    $this->events()->recordEvent(
                        $executionId,
                        'NodeCompleted',
                        $nodeId,
                        ['output' => $approvalOutput],
                        ['run_id' => (int) $gate->workflow_run_id, 'node_type' => 'Approval']
                    );
                }
                if ($gate->node_execution_id !== null) {
                    DB::update(
                        "UPDATE node_executions SET state = 'success', error_message = NULL WHERE id = ?",
                        [(int) $gate->node_execution_id]
                    );
                }
                DB::update(
                    "UPDATE workflow_runs SET status = 'running', error_message = NULL, completed_at = NULL WHERE id = ?",
                    [(int) $gate->workflow_run_id]
                );
            } else {
                if ($gate->node_execution_id !== null) {
                    DB::update(
                        "UPDATE node_executions SET state = 'failed', error_message = ? WHERE id = ?",
                        ['Workflow approval rejected by operator.', (int) $gate->node_execution_id]
                    );
                }
                DB::update(
                    "UPDATE workflow_runs SET status = 'failed', error_message = ?, completed_at = ? WHERE id = ?",
                    ['Workflow approval rejected by operator.', now(), (int) $gate->workflow_run_id]
                );
            }

            Log::info("WorkflowApproval: {$status}", ['gate_id' => $gateId, 'by' => $respondedBy]);

            return [
                'changed' => true,
                'gate_id' => $gateId,
                'status' => $status,
                'run_id' => (int) $gate->workflow_run_id,
                'execution_id' => $executionId,
                'resume_input' => is_array($context['resume_input'] ?? null) ? $context['resume_input'] : [],
                'approval_output' => $action === 'approve' ? $approvalOutput : [],
            ];
        }, 3);
    }

    public function approve(int $gateId, ?string $respondedBy = null, ?string $notes = null): bool
    {
        return (bool) $this->decide($gateId, 'approve', [], $respondedBy, $notes)['changed'];
    }

    public function reject(int $gateId, ?string $respondedBy = null, ?string $notes = null): bool
    {
        return (bool) $this->decide($gateId, 'reject', [], $respondedBy, $notes)['changed'];
    }

    /** @return array<int,object> */
    public function getPendingApprovals(): array
    {
        $gates = DB::select(
            "SELECT wag.id, wag.workflow_run_id, wag.node_execution_id, wag.execution_id, wag.node_id,
                    wag.status, wag.requested_at, wag.timeout_minutes, wag.context,
                    w.name AS workflow_name, wr.status AS run_status
             FROM workflow_approval_gates wag
             JOIN workflow_runs wr ON wr.id = wag.workflow_run_id
             JOIN workflows w ON w.id = wr.workflow_id
             WHERE wag.status = 'pending'
             ORDER BY wag.requested_at ASC"
        );

        return array_map(function (object $gate): object {
            $context = $this->decodeContext($gate->context);
            $gate->run_id = (int) $gate->workflow_run_id;
            $gate->context = is_array($context['form'] ?? null) ? $context['form'] : [];

            return $gate;
        }, $gates);
    }

    public function checkExpired(): int
    {
        $gateIds = DB::table('workflow_approval_gates')
            ->where('status', 'pending')
            ->whereRaw('DATE_ADD(requested_at, INTERVAL timeout_minutes MINUTE) <= NOW()')
            ->pluck('id');

        $expired = 0;
        foreach ($gateIds as $gateId) {
            $changed = DB::transaction(function () use ($gateId): bool {
                $gate = DB::table('workflow_approval_gates')->where('id', $gateId)->lockForUpdate()->first();
                if ($gate === null || $gate->status !== 'pending' || ! $this->gateHasExpired($gate)) {
                    return false;
                }
                $this->expireLockedGate($gate);

                return true;
            }, 3);
            $expired += $changed ? 1 : 0;
        }

        if ($expired > 0) {
            Log::info('WorkflowApproval: Expired gates', ['count' => $expired]);
        }

        return $expired;
    }

    /** @return array<int,object> */
    public function getApprovalHistory(?int $workflowId = null, int $limit = 50): array
    {
        $params = [];
        $where = '';
        if ($workflowId) {
            $where = 'AND wr.workflow_id = ?';
            $params[] = $workflowId;
        }
        $params[] = max(1, min(200, $limit));

        return DB::select(
            "SELECT wag.id, wag.workflow_run_id, wag.execution_id, wag.node_id, wag.status,
                    wag.requested_at, wag.responded_at, wag.responded_by, wag.response_notes,
                    w.name AS workflow_name
             FROM workflow_approval_gates wag
             JOIN workflow_runs wr ON wr.id = wag.workflow_run_id
             JOIN workflows w ON w.id = wr.workflow_id
             WHERE 1=1 {$where}
             ORDER BY wag.requested_at DESC
             LIMIT ?",
            $params
        );
    }

    /** @param array<int,mixed> $fieldDefinitions @param array<string,mixed> $inputs */
    private function validateResponseInputs(array $fieldDefinitions, array $inputs): array
    {
        $validated = [];
        $allowedNames = [];
        foreach ($fieldDefinitions as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                throw new InvalidArgumentException('Stored approval response field definition is invalid.');
            }
            $name = $field['name'];
            $allowedNames[] = $name;
            $present = array_key_exists($name, $inputs);
            if (($field['required'] ?? false) && ! $present) {
                throw new InvalidArgumentException("Approval response field {$name} is required.");
            }
            if (! $present) {
                continue;
            }

            $type = $field['type'] ?? 'text';
            $value = $inputs[$name];
            if ($type === 'boolean') {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException("Approval response field {$name} must be boolean.");
                }
                $validated[$name] = $value;

                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Approval response field {$name} must be scalar.");
            }
            $value = mb_substr(trim((string) $value), 0, 4000);
            if (($field['required'] ?? false) && $value === '') {
                throw new InvalidArgumentException("Approval response field {$name} is required.");
            }
            if ($type === 'select' && ! in_array($value, $field['options'] ?? [], true)) {
                throw new InvalidArgumentException("Approval response field {$name} has an invalid option.");
            }
            $validated[$name] = $value;
        }

        $unexpected = array_diff(array_keys($inputs), $allowedNames);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Unexpected approval response fields: '.implode(', ', $unexpected));
        }

        return $validated;
    }

    private function expireLockedGate(object $gate): void
    {
        DB::update(
            "UPDATE workflow_approval_gates
             SET status = 'expired', responded_at = ?, response_notes = 'Auto-expired due to timeout'
             WHERE id = ? AND status = 'pending'",
            [now(), (int) $gate->id]
        );
        if ($gate->node_execution_id !== null) {
            DB::update(
                "UPDATE node_executions SET state = 'failed', error_message = ? WHERE id = ?",
                ['Workflow approval expired.', (int) $gate->node_execution_id]
            );
        }
        DB::update(
            "UPDATE workflow_runs SET status = 'failed', error_message = ?, completed_at = ? WHERE id = ?",
            ['Workflow approval expired.', now(), (int) $gate->workflow_run_id]
        );

        if (is_string($gate->execution_id)) {
            $this->events()->recordEvent(
                $gate->execution_id,
                'SignalReceived',
                is_string($gate->node_id) ? $gate->node_id : null,
                ['name' => 'workflow_approval', 'data' => ['gate_id' => (int) $gate->id, 'action' => 'expired']],
                ['run_id' => (int) $gate->workflow_run_id, 'approval_status' => 'expired']
            );
        }
    }

    private function gateHasExpired(object $gate): bool
    {
        return now()->greaterThanOrEqualTo(
            Carbon::parse($gate->requested_at)->addMinutes((int) $gate->timeout_minutes)
        );
    }

    /** @return array<string,mixed> */
    private function decodeContext(mixed $context): array
    {
        if (is_array($context)) {
            return $context;
        }
        if (! is_string($context) || $context === '') {
            return [];
        }

        $decoded = json_decode($context, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function events(): WorkflowEventService
    {
        return $this->eventService ?? new WorkflowEventService;
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}

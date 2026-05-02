<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowApprovalService
{
    private function getNotifier(): \App\Controllers\NotificationController
    {
        return new \App\Controllers\NotificationController;
    }

    public function requestApproval(int $runId, ?int $nodeExecutionId, ?array $context = null, int $timeoutMinutes = 1440): int
    {
        DB::insert(
            "INSERT INTO workflow_approval_gates (workflow_run_id, node_execution_id, approval_type, status, requested_at, timeout_minutes, context)
             VALUES (?, ?, 'manual', 'pending', NOW(), ?, ?)",
            [$runId, $nodeExecutionId, $timeoutMinutes, $context ? json_encode($context) : null]
        );

        $gateId = (int) DB::getPdo()->lastInsertId();

        // Send notification
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
        } catch (Exception $e) {
            Log::warning('WorkflowApproval: Failed to send notification', ['error' => $e->getMessage()]);
        }

        Log::info('WorkflowApproval: Approval requested', [
            'gate_id' => $gateId,
            'run_id' => $runId,
            'timeout_minutes' => $timeoutMinutes,
        ]);

        return $gateId;
    }

    public function approve(int $gateId, ?string $respondedBy = null, ?string $notes = null): bool
    {
        $updated = DB::update(
            "UPDATE workflow_approval_gates SET status = 'approved', responded_at = NOW(), responded_by = ?, response_notes = ?
             WHERE id = ? AND status = 'pending'",
            [$respondedBy, $notes, $gateId]
        );

        if ($updated) {
            Log::info('WorkflowApproval: Approved', ['gate_id' => $gateId, 'by' => $respondedBy]);
        }

        return $updated > 0;
    }

    public function reject(int $gateId, ?string $respondedBy = null, ?string $notes = null): bool
    {
        $updated = DB::update(
            "UPDATE workflow_approval_gates SET status = 'rejected', responded_at = NOW(), responded_by = ?, response_notes = ?
             WHERE id = ? AND status = 'pending'",
            [$respondedBy, $notes, $gateId]
        );

        if ($updated) {
            Log::info('WorkflowApproval: Rejected', ['gate_id' => $gateId, 'by' => $respondedBy]);
        }

        return $updated > 0;
    }

    public function getPendingApprovals(): array
    {
        return DB::select(
            "SELECT wag.*, w.name as workflow_name, wr.status as run_status
             FROM workflow_approval_gates wag
             JOIN workflow_runs wr ON wr.id = wag.workflow_run_id
             JOIN workflows w ON w.id = wr.workflow_id
             WHERE wag.status = 'pending'
             ORDER BY wag.requested_at ASC"
        );
    }

    public function checkExpired(): int
    {
        $expired = DB::update(
            "UPDATE workflow_approval_gates
             SET status = 'expired', responded_at = NOW(), response_notes = 'Auto-expired due to timeout'
             WHERE status = 'pending'
             AND DATE_ADD(requested_at, INTERVAL timeout_minutes MINUTE) <= NOW()"
        );

        if ($expired > 0) {
            Log::info('WorkflowApproval: Expired gates', ['count' => $expired]);
        }

        return $expired;
    }

    public function getApprovalHistory(?int $workflowId = null, int $limit = 50): array
    {
        $params = [];
        $where = '';
        if ($workflowId) {
            $where = 'AND wr.workflow_id = ?';
            $params[] = $workflowId;
        }
        $params[] = $limit;

        return DB::select(
            "SELECT wag.*, w.name as workflow_name
             FROM workflow_approval_gates wag
             JOIN workflow_runs wr ON wr.id = wag.workflow_run_id
             JOIN workflows w ON w.id = wr.workflow_id
             WHERE 1=1 {$where}
             ORDER BY wag.requested_at DESC
             LIMIT ?",
            $params
        );
    }
}

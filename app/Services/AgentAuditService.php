<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INF-3: Structured audit logging for agent actions.
 *
 * Writes to agent_execution_log (repurposed) for:
 * - Tool calls with risk levels
 * - LLM decisions and phase transitions
 * - Guardrail checks (permission denied, blocked paths)
 * - Review submissions and resolutions
 * - Errors and timeouts
 *
 * Non-blocking: audit failures never break the calling operation.
 */
class AgentAuditService
{
    /**
     * Record a structured audit event.
     */
    public function record(
        string $actionType,
        ?string $sessionId = null,
        ?string $agentName = null,
        ?string $actionDetail = null,
        ?string $riskLevel = null,
        ?string $role = null,
        ?string $inputSummary = null,
        ?string $outputSummary = null,
        ?float $durationMs = null,
        string $outcome = 'success',
        ?array $context = null,
    ): void {
        try {
            DB::insert(
                "INSERT INTO agent_execution_log
                    (session_id, agent_name, action_type, action_detail, risk_level,
                     role, input_summary, output_summary, duration_ms, success,
                     outcome, context, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $sessionId ?? '',
                    $agentName ? mb_substr($agentName, 0, 100) : null,
                    $actionType,
                    $actionDetail ? mb_substr($actionDetail, 0, 255) : null,
                    $riskLevel,
                    mb_substr($role ?? 'agent', 0, 50),
                    $inputSummary ? mb_substr($inputSummary, 0, 5000) : null,
                    $outputSummary ? mb_substr($outputSummary, 0, 5000) : null,
                    $durationMs,
                    $outcome === 'success' ? 1 : 0,
                    $outcome,
                    $context ? json_encode($context) : null,
                ]
            );
        } catch (\Throwable $e) {
            // Audit failures must never break the calling operation
            Log::debug('AgentAuditService: failed to record audit event', [
                'action_type' => $actionType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience: record a tool call.
     */
    public function recordToolCall(
        string $sessionId,
        string $agentName,
        string $toolName,
        string $riskLevel,
        float $durationMs,
        string $outcome = 'success',
        ?string $error = null,
    ): void {
        $this->record(
            actionType: 'tool_call',
            sessionId: $sessionId,
            agentName: $agentName,
            actionDetail: $toolName,
            riskLevel: $riskLevel,
            durationMs: $durationMs,
            outcome: $outcome,
            context: $error ? ['error' => mb_substr($error, 0, 500)] : null,
        );
    }

    /**
     * Convenience: record a guardrail event (permission denied, blocked path, etc.).
     */
    public function recordGuardrail(
        string $sessionId,
        string $agentName,
        string $detail,
        string $outcome = 'denied',
        ?array $context = null,
    ): void {
        $this->record(
            actionType: 'guardrail_check',
            sessionId: $sessionId,
            agentName: $agentName,
            actionDetail: $detail,
            riskLevel: 'blocked',
            outcome: $outcome,
            context: $context,
        );
    }

    /**
     * Convenience: record a review submission.
     */
    public function recordReviewSubmission(
        string $sessionId,
        string $agentName,
        string $reviewType,
        float $confidence,
        ?int $reviewId = null,
    ): void {
        $this->record(
            actionType: 'review_submitted',
            sessionId: $sessionId,
            agentName: $agentName,
            actionDetail: $reviewType,
            riskLevel: 'write',
            outcome: 'success',
            context: ['confidence' => $confidence, 'review_id' => $reviewId],
        );
    }

    /**
     * Convenience: record a phase transition.
     */
    public function recordPhaseTransition(
        string $sessionId,
        string $agentName,
        string $fromPhase,
        string $toPhase,
    ): void {
        $this->record(
            actionType: 'phase_transition',
            sessionId: $sessionId,
            agentName: $agentName,
            actionDetail: "{$fromPhase} → {$toPhase}",
            outcome: 'success',
        );
    }

    /**
     * Query recent audit events for an agent.
     */
    public function getRecentEvents(
        ?string $agentName = null,
        ?string $actionType = null,
        int $limit = 50,
        int $hoursBack = 24,
    ): array {
        $sql = "SELECT * FROM agent_execution_log WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $params = [$hoursBack];

        if ($agentName) {
            $sql .= " AND agent_name = ?";
            $params[] = $agentName;
        }
        if ($actionType) {
            $sql .= " AND action_type = ?";
            $params[] = $actionType;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        try {
            return DB::select($sql, $params);
        } catch (\Throwable $e) {
            Log::warning('AgentAuditService: query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get audit summary stats for a time window.
     */
    public function getSummary(int $hoursBack = 24): array
    {
        try {
            $rows = DB::select(
                "SELECT
                    action_type,
                    outcome,
                    risk_level,
                    COUNT(*) as count,
                    AVG(duration_ms) as avg_duration_ms
                 FROM agent_execution_log
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 GROUP BY action_type, outcome, risk_level
                 ORDER BY count DESC",
                [$hoursBack]
            );

            $total = array_sum(array_column($rows, 'count'));
            $denied = 0;
            $failures = 0;
            foreach ($rows as $row) {
                if ($row->outcome === 'denied') $denied += $row->count;
                if ($row->outcome === 'failure') $failures += $row->count;
            }

            return [
                'total_events' => $total,
                'denied' => $denied,
                'failures' => $failures,
                'hours_back' => $hoursBack,
                'breakdown' => $rows,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

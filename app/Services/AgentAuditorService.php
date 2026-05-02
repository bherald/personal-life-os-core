<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AG-9: Auditor Agent — Lightweight agent reviewing other agents' tool call traces.
 *
 * Scans the agent_execution_log (from AgentAuditService) for anomalies:
 *   - Excessive tool calls per session (thrashing)
 *   - Repeated failures on the same tool (broken tool)
 *   - Guardrail violations (boundary testing)
 *   - Unusually long sessions (stalling)
 *   - Low tool diversity (single-tool loops)
 *
 * Runs as a scheduled check (not a full agent loop). Returns findings
 * that can be submitted to the review queue or logged as alerts.
 *
 * Reference: Bounded Autonomy pattern
 */
class AgentAuditorService
{
    /** Max tool calls per session before flagging */
    public const THRASHING_THRESHOLD = 50;

    /** Max consecutive failures on same tool before flagging */
    public const REPEATED_FAILURE_THRESHOLD = 5;

    /** Session duration in minutes before flagging */
    public const LONG_SESSION_THRESHOLD_MINUTES = 60;

    /** Minimum tool diversity ratio (unique tools / total calls) */
    public const MIN_DIVERSITY_RATIO = 0.15;

    // =========================================================================
    // Main audit
    // =========================================================================

    /**
     * Run a full audit of recent agent activity.
     *
     * @param  int  $hoursBack  Hours of history to audit
     * @return array Audit findings
     */
    public function audit(int $hoursBack = 24): array
    {
        $findings = [];

        try {
            $findings = array_merge(
                $findings,
                $this->checkThrashing($hoursBack),
                $this->checkRepeatedFailures($hoursBack),
                $this->checkGuardrailViolations($hoursBack),
                $this->checkLongSessions($hoursBack),
                $this->checkLowDiversity($hoursBack),
            );
        } catch (\Throwable $e) {
            Log::error('AgentAuditor: Audit failed', ['error' => $e->getMessage()]);
            $findings[] = [
                'type' => 'audit_error',
                'severity' => 'warning',
                'message' => 'Audit scan failed: '.$e->getMessage(),
            ];
        }

        Log::info('AgentAuditor: Audit complete', [
            'hours_back' => $hoursBack,
            'findings' => count($findings),
        ]);

        return [
            'findings' => $findings,
            'total' => count($findings),
            'hours_audited' => $hoursBack,
            'scanned_at' => now()->toIso8601String(),
        ];
    }

    // =========================================================================
    // Anomaly detectors
    // =========================================================================

    /**
     * Detect sessions with excessive tool calls (thrashing).
     */
    public function checkThrashing(int $hoursBack = 24): array
    {
        try {
            $sessions = DB::select("
                SELECT session_id, agent_name, COUNT(*) as tool_calls
                FROM agent_execution_log
                WHERE action_type = 'tool_call'
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY session_id, agent_name
                HAVING COUNT(*) > ?
                ORDER BY tool_calls DESC
                LIMIT 10
            ", [$hoursBack, self::THRASHING_THRESHOLD]);

            return array_map(fn ($s) => [
                'type' => 'thrashing',
                'severity' => $s->tool_calls > self::THRASHING_THRESHOLD * 2 ? 'critical' : 'warning',
                'agent' => $s->agent_name,
                'session_id' => $s->session_id,
                'tool_calls' => (int) $s->tool_calls,
                'threshold' => self::THRASHING_THRESHOLD,
                'message' => "{$s->agent_name} made {$s->tool_calls} tool calls in session (threshold: ".self::THRASHING_THRESHOLD.')',
            ], $sessions);

        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Detect repeated failures on the same tool.
     */
    public function checkRepeatedFailures(int $hoursBack = 24): array
    {
        try {
            $failures = DB::select("
                SELECT agent_name, action_detail as tool_name, COUNT(*) as failure_count
                FROM agent_execution_log
                WHERE action_type = 'tool_call'
                  AND outcome = 'failure'
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY agent_name, action_detail
                HAVING COUNT(*) >= ?
                ORDER BY failure_count DESC
                LIMIT 10
            ", [$hoursBack, self::REPEATED_FAILURE_THRESHOLD]);

            return array_map(fn ($f) => [
                'type' => 'repeated_failure',
                'severity' => $f->failure_count > self::REPEATED_FAILURE_THRESHOLD * 2 ? 'critical' : 'warning',
                'agent' => $f->agent_name,
                'tool' => $f->tool_name,
                'failure_count' => (int) $f->failure_count,
                'message' => "{$f->agent_name}: tool '{$f->tool_name}' failed {$f->failure_count} times",
            ], $failures);

        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Detect guardrail violations.
     */
    public function checkGuardrailViolations(int $hoursBack = 24): array
    {
        try {
            $violations = DB::select("
                SELECT agent_name, action_detail, risk_level, COUNT(*) as violation_count
                FROM agent_execution_log
                WHERE action_type = 'guardrail_check'
                  AND outcome = 'blocked'
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY agent_name, action_detail, risk_level
                ORDER BY violation_count DESC
                LIMIT 10
            ", [$hoursBack]);

            return array_map(fn ($v) => [
                'type' => 'guardrail_violation',
                'severity' => $v->risk_level === 'destructive' ? 'critical' : 'warning',
                'agent' => $v->agent_name,
                'action' => $v->action_detail,
                'risk_level' => $v->risk_level,
                'count' => (int) $v->violation_count,
                'message' => "{$v->agent_name}: guardrail blocked '{$v->action_detail}' ({$v->risk_level}) {$v->violation_count} times",
            ], $violations);

        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Detect unusually long sessions.
     */
    public function checkLongSessions(int $hoursBack = 24): array
    {
        try {
            $sessions = DB::select("
                SELECT agent_name, id as session_id, status,
                       TIMESTAMPDIFF(
                           MINUTE,
                           created_at,
                           CASE
                               WHEN status = 'active' THEN NOW()
                               ELSE COALESCE(updated_at, last_activity_at, created_at)
                           END
                       ) as duration_minutes
                FROM agent_sessions
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                  AND TIMESTAMPDIFF(
                      MINUTE,
                      created_at,
                      CASE
                          WHEN status = 'active' THEN NOW()
                          ELSE COALESCE(updated_at, last_activity_at, created_at)
                      END
                  ) > ?
                ORDER BY duration_minutes DESC
                LIMIT 10
            ", [$hoursBack, self::LONG_SESSION_THRESHOLD_MINUTES]);

            return array_map(fn ($s) => [
                'type' => 'long_session',
                'severity' => $s->status === 'running' ? 'critical' : 'info',
                'agent' => $s->agent_name,
                'session_id' => $s->session_id,
                'duration_minutes' => (int) $s->duration_minutes,
                'status' => $s->status,
                'message' => "{$s->agent_name}: session ran for {$s->duration_minutes}min (status: {$s->status})",
            ], $sessions);

        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Detect sessions with low tool diversity (single-tool loops).
     */
    public function checkLowDiversity(int $hoursBack = 24): array
    {
        try {
            $sessions = DB::select("
                SELECT session_id, agent_name,
                       COUNT(*) as total_calls,
                       COUNT(DISTINCT action_detail) as unique_tools
                FROM agent_execution_log
                WHERE action_type = 'tool_call'
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY session_id, agent_name
                HAVING COUNT(*) >= 10
                   AND (COUNT(DISTINCT action_detail) / COUNT(*)) < ?
                ORDER BY total_calls DESC
                LIMIT 10
            ", [$hoursBack, self::MIN_DIVERSITY_RATIO]);

            return array_map(fn ($s) => [
                'type' => 'low_diversity',
                'severity' => 'warning',
                'agent' => $s->agent_name,
                'session_id' => $s->session_id,
                'total_calls' => (int) $s->total_calls,
                'unique_tools' => (int) $s->unique_tools,
                'diversity_ratio' => round((int) $s->unique_tools / max(1, (int) $s->total_calls), 3),
                'message' => "{$s->agent_name}: used only {$s->unique_tools} unique tools in {$s->total_calls} calls (ratio: ".round((int) $s->unique_tools / (int) $s->total_calls, 2).')',
            ], $sessions);

        } catch (\Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // Classification helpers (pure — unit-testable)
    // =========================================================================

    /**
     * Classify the severity of a finding.
     */
    public function classifySeverity(string $type, array $details): string
    {
        return match ($type) {
            'thrashing' => ($details['tool_calls'] ?? 0) > self::THRASHING_THRESHOLD * 2 ? 'critical' : 'warning',
            'repeated_failure' => ($details['failure_count'] ?? 0) > self::REPEATED_FAILURE_THRESHOLD * 2 ? 'critical' : 'warning',
            'guardrail_violation' => ($details['risk_level'] ?? '') === 'destructive' ? 'critical' : 'warning',
            'long_session' => ($details['status'] ?? '') === 'running' ? 'critical' : 'info',
            'low_diversity' => 'warning',
            default => 'info',
        };
    }

    /**
     * Determine if findings warrant an alert.
     */
    public function shouldAlert(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? 'info') === 'critical') {
                return true;
            }
        }

        return false;
    }
}

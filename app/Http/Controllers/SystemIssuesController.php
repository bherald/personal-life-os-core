<?php

namespace App\Http\Controllers;

use App\Services\OpsMCPService;
use App\Services\RemediationExecutionService;
use App\Services\RemediationRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Exception;

/**
 * System Issues Controller
 *
 * Provides API endpoints for managing system issues detected by AI ops.
 * Issues persist in the pending list until explicitly dismissed by human.
 *
 * Statuses:
 * - open: Issue detected and needs attention
 * - resolved: Issue addressed but still visible (human/AI collaboration)
 * - dismissed: Issue removed from pending list (skipped/completed/archived)
 */
class SystemIssuesController extends Controller
{
    private OpsMCPService $opsService;
    private RemediationRegistryService $remediationRegistry;
    private RemediationExecutionService $remediationExecutor;

    public function __construct(
        OpsMCPService $opsService,
        RemediationRegistryService $remediationRegistry,
        RemediationExecutionService $remediationExecutor
    )
    {
        $this->opsService = $opsService;
        $this->remediationRegistry = $remediationRegistry;
        $this->remediationExecutor = $remediationExecutor;
    }

    /**
     * Get all pending issues (open + resolved)
     * These are issues that appear in daily reports until dismissed
     */
    public function getPending(Request $request): JsonResponse
    {
        try {
            $rows = DB::select("
                SELECT * FROM system_issues
                WHERE status IN ('open', 'resolved')
                ORDER BY FIELD(severity, 'critical', 'warning', 'info'),
                         FIELD(status, 'open', 'resolved'),
                         last_seen_at DESC
            ");

            $issues = array_map(fn($issue) => $this->formatIssue($issue), $rows);
            $stats = $this->getStats();

            return response()->json([
                'success' => true,
                'data' => [
                    'issues' => $issues,
                    'stats' => $stats,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch pending issues: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all issues (including dismissed) for history view
     */
    public function getAll(Request $request): JsonResponse
    {
        try {
            $status = $request->input('status', 'all');
            $severity = $request->input('severity', 'all');
            $limit = min($request->input('limit', 100), 500);

            $sql = "SELECT * FROM system_issues WHERE 1=1";
            $params = [];

            if ($status !== 'all') {
                $sql .= " AND status = ?";
                $params[] = $status;
            }

            if ($severity !== 'all') {
                $sql .= " AND severity = ?";
                $params[] = $severity;
            }

            $sql .= " ORDER BY last_seen_at DESC LIMIT ?";
            $params[] = $limit;

            $rows = DB::select($sql, $params);
            $issues = array_map(fn($issue) => $this->formatIssue($issue), $rows);
            $stats = $this->getStats();

            return response()->json([
                'success' => true,
                'data' => [
                    'issues' => $issues,
                    'stats' => $stats,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch issues: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single issue by ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $issue = DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id]);

            if (!$issue) {
                return response()->json([
                    'success' => false,
                    'error' => 'Issue not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatIssue($issue),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch issue: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark issue as resolved (still shows in pending list)
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        try {
            $notes = $request->input('notes', '');
            $resolvedBy = $request->input('resolved_by', 'human');

            $issue = DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id]);

            if (!$issue) {
                return response()->json([
                    'success' => false,
                    'error' => 'Issue not found',
                ], 404);
            }

            DB::update("UPDATE system_issues SET status = ?, resolved_at = ?, resolved_by = ?, resolution_notes = ?, updated_at = ? WHERE id = ?", [
                'resolved', now(), $resolvedBy, $notes ?: null, now(), $id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Issue marked as resolved',
                'data' => $this->formatIssue(
                    DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id])
                ),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to resolve issue: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Dismiss issue (removes from pending list, keeps in history)
     */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        try {
            $notes = $request->input('notes', '');
            $dismissedBy = $request->input('dismissed_by', 'human');

            $issue = DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id]);

            if (!$issue) {
                return response()->json([
                    'success' => false,
                    'error' => 'Issue not found',
                ], 404);
            }

            DB::update("UPDATE system_issues SET status = ?, resolved_at = ?, resolved_by = ?, resolution_notes = ?, updated_at = ? WHERE id = ?", [
                'dismissed',
                $issue->resolved_at ?? now(),
                $issue->resolved_by ?? $dismissedBy,
                $notes ?: $issue->resolution_notes,
                now(),
                $id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Issue dismissed',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to dismiss issue: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run the suggested fix for an issue
     */
    public function runFix(Request $request, int $id): JsonResponse
    {
        try {
            $issue = DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id]);

            if (!$issue) {
                return response()->json([
                    'success' => false,
                    'error' => 'Issue not found',
                ], 404);
            }

            if (empty($issue->suggested_fix) && empty($issue->finding_type)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No suggested fix available for this issue',
                ], 400);
            }

            $result = $this->executeFix($issue, $request->boolean('confirmed'));

            if ($result['success']) {
                // Mark as resolved after successful fix
                DB::update("UPDATE system_issues SET status = ?, resolved_at = ?, resolved_by = ?, resolution_notes = ?, updated_at = ? WHERE id = ?", [
                    'resolved',
                    now(),
                    'human+auto',
                    'Fix executed: ' . $result['action'] . "\nOutput: " . ($result['output'] ?? $result['detail'] ?? 'Success'),
                    now(),
                    $id
                ]);
            }

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => [
                    'action' => $result['action'] ?? null,
                    'output' => $result['output'] ?? $result['detail'] ?? null,
                    'issue' => $result['success']
                        ? $this->formatIssue(DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id]))
                        : null,
                ],
            ], $result['status_code'] ?? ($result['success'] ? 200 : 400));
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to execute fix: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute a fix based on the suggested_fix text
     */
    private function executeFix(object $issue, bool $confirmed = false): array
    {
        if (!empty($issue->finding_type)) {
            $registryAction = $this->remediationRegistry->getActionForFinding($issue->finding_type);
            if ($registryAction) {
                $result = $this->remediationExecutor->executeFindingType($issue->finding_type, $confirmed);
                $result['action'] = $registryAction['description'];
                $result['output'] = $result['detail'] ?? null;
                return $result;
            }
        }

        return [
            'success' => false,
            'status_code' => 422,
            'message' => 'This issue has no registered remediation. Manual intervention required.',
            'action' => 'manual',
            'output' => $issue->suggested_fix,
        ];
    }

    /**
     * Reopen a resolved or dismissed issue
     */
    public function reopen(int $id): JsonResponse
    {
        try {
            $issue = DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id]);

            if (!$issue) {
                return response()->json([
                    'success' => false,
                    'error' => 'Issue not found',
                ], 404);
            }

            DB::update("UPDATE system_issues SET status = ?, resolved_at = NULL, resolved_by = NULL, resolution_notes = NULL, updated_at = ? WHERE id = ?", [
                'open', now(), $id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Issue reopened',
                'data' => $this->formatIssue(
                    DB::selectOne("SELECT * FROM system_issues WHERE id = ?", [$id])
                ),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to reopen issue: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format issue for API response
     */
    private function formatIssue(object $issue): array
    {
        $firstSeen = $issue->first_seen_at ? \Carbon\Carbon::parse($issue->first_seen_at) : null;
        $lastSeen = $issue->last_seen_at ? \Carbon\Carbon::parse($issue->last_seen_at) : null;
        $resolvedAt = $issue->resolved_at ? \Carbon\Carbon::parse($issue->resolved_at) : null;
        $remediation = !empty($issue->finding_type)
            ? $this->remediationRegistry->getActionForFinding($issue->finding_type)
            : null;

        return [
            'id' => $issue->id,
            'category' => $issue->category,
            'severity' => $issue->severity,
            'title' => $issue->title,
            'description' => $issue->description,
            'suggested_fix' => $issue->suggested_fix,
            'finding_type' => $issue->finding_type,
            'status' => $issue->status,
            'detected_by' => $issue->detected_by,
            'first_seen_at' => $firstSeen?->toIso8601String(),
            'first_seen_formatted' => $firstSeen?->format('D m/d/Y g:i A'),
            'last_seen_at' => $lastSeen?->toIso8601String(),
            'last_seen_formatted' => $lastSeen?->format('D m/d/Y g:i A'),
            'occurrence_count' => $issue->occurrence_count ?? 1,
            'resolved_at' => $resolvedAt?->toIso8601String(),
            'resolved_at_formatted' => $resolvedAt?->format('D m/d/Y g:i A'),
            'resolved_by' => $issue->resolved_by,
            'resolution_notes' => $issue->resolution_notes,
            'context' => json_decode($issue->context ?? '{}', true),
            'has_remediation' => $remediation !== null,
            'remediation_risk_level' => $remediation['risk_level'] ?? null,
            'remediation_requires_confirmation' => $remediation['requires_confirmation'] ?? false,
            'can_run_fix' => $issue->status === 'open'
                && $remediation !== null
                && $remediation['risk_level'] !== 'destructive',
        ];
    }

    /**
     * Get issue statistics
     */
    private function getStats(): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
                SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed_count,
                SUM(CASE WHEN status IN ('open', 'resolved') THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'open' AND severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN status = 'open' AND severity = 'warning' THEN 1 ELSE 0 END) as warning_count,
                SUM(CASE WHEN status = 'open' AND severity = 'info' THEN 1 ELSE 0 END) as info_count
            FROM system_issues
        ");

        return [
            'total' => (int) ($stats->total ?? 0),
            'open' => (int) ($stats->open_count ?? 0),
            'resolved' => (int) ($stats->resolved_count ?? 0),
            'dismissed' => (int) ($stats->dismissed_count ?? 0),
            'pending' => (int) ($stats->pending_count ?? 0),
            'critical' => (int) ($stats->critical_count ?? 0),
            'warning' => (int) ($stats->warning_count ?? 0),
            'info' => (int) ($stats->info_count ?? 0),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Engine\AIRouter;
use App\Engine\MCPRouter;
use App\Services\AgentAuditService;
use App\Services\AIService;
use App\Services\RAGService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * DashboardController
 * E01 Phase 3: Now uses AIService for enhanced health reporting
 */
class DashboardController extends Controller
{
    private AIRouter $aiRouter;
    private AIService $aiService;
    private RAGService $ragService;
    private MCPRouter $mcpRouter;

    public function __construct(AIRouter $aiRouter, AIService $aiService, RAGService $ragService, MCPRouter $mcpRouter)
    {
        $this->aiRouter = $aiRouter;
        $this->aiService = $aiService;
        $this->ragService = $ragService;
        $this->mcpRouter = $mcpRouter;
    }

    public function stats(): JsonResponse
    {
        // Get workflow statistics using raw SQL with parameters
        $sql = "SELECT COUNT(*) as count FROM workflows";
        $totalWorkflows = DB::select($sql)[0]->count ?? 0;

        $sql = "SELECT COUNT(*) as count FROM workflows WHERE active = 1";
        $activeWorkflows = DB::select($sql)[0]->count ?? 0;

        // Get recent runs (last 24 hours)
        $sql = "SELECT COUNT(*) as count FROM workflow_runs WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $recentRuns = DB::select($sql)[0]->count ?? 0;

        // Get failed runs (last 24 hours)
        $sql = "SELECT COUNT(*) as count FROM workflow_runs
                WHERE status = 'failed'
                AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $failedRuns = DB::select($sql)[0]->count ?? 0;

        // Get recent workflow runs with details (last 10) - raw SQL with JOIN
        $sql = "SELECT wr.id, wr.workflow_id, w.name as workflow_name, wr.status,
                       wr.started_at, wr.completed_at, wr.error_message,
                       TIMESTAMPDIFF(SECOND, wr.started_at, wr.completed_at) as duration_seconds
                FROM workflow_runs wr
                JOIN workflows w ON wr.workflow_id = w.id
                ORDER BY wr.started_at DESC
                LIMIT 10";
        $recentWorkflowRuns = DB::select($sql);

        // Get AI service status from both router and AIService
        $aiStatus = $this->aiRouter->getStatus();
        $aiHealth = $this->aiService->getHealthStats();

        // Get RAG statistics
        $ragStats = $this->ragService->getStats();

        // Get MCP statistics
        $mcpTools = $this->mcpRouter->getAvailableTools();
        $mcpServers = $this->mcpRouter->getAllServersStatus();
        $activeServers = array_filter($mcpServers, fn($s) => $s['enabled'] ?? false);

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_workflows' => $totalWorkflows,
                    'active_workflows' => $activeWorkflows,
                    'recent_runs_24h' => $recentRuns,
                    'failed_runs_24h' => $failedRuns,
                ],
                'recent_runs' => $recentWorkflowRuns,
                'ai_status' => [
                    'ollama_available' => $aiStatus['ollama']['available'] ?? false,
                    'claude_available' => $aiStatus['claude']['available'] ?? false,
                    'mode' => $aiStatus['mode'] ?? 'unknown',
                ],
                'ai_health' => $aiHealth, // E01 Phase 3: Circuit breaker + provider health stats
                'rag_stats' => [
                    'total_documents' => $ragStats['total_documents'],
                    'by_type' => $ragStats['by_type'],
                ],
                'mcp_stats' => [
                    'total_tools' => count($mcpTools),
                    'active_servers' => count($activeServers),
                    'total_servers' => count($mcpServers),
                ],
                'config' => [
                    'timezone' => config('app.timezone'),
                ],
            ]
        ]);
    }

    /**
     * Get Daily Ops summary - items requiring human attention
     */
    public function dailyOps(): JsonResponse
    {
        // Email Queue - pending drafts needing approval
        $emailQueue = DB::select("
            SELECT COUNT(*) as pending_count
            FROM email_reply_drafts
            WHERE status = 'pending'
        ");
        $pendingEmails = $emailQueue[0]->pending_count ?? 0;

        // File Catalog - files pending RAG sync
        $fileCatalog = DB::selectOne("
            SELECT COUNT(*) as pending_count
            FROM file_registry
            WHERE rag_indexed_at IS NULL
              AND status = 'active'
        ");
        $pendingRagSync = $fileCatalog->pending_count ?? 0;

        // System Issues - pending issues (matching SystemIssuesController::getPending)
        // Pending = open + resolved (visible in pending list until dismissed)
        $systemIssues = DB::select("
            SELECT COUNT(*) as issue_count
            FROM system_issues
            WHERE status IN ('open', 'resolved')
        ");
        $unresolvedIssues = $systemIssues[0]->issue_count ?? 0;

        // Workflow executions - failed in last 24h
        $failedWorkflows = DB::select("
            SELECT COUNT(*) as failed_count
            FROM workflow_runs
            WHERE status = 'failed'
            AND started_at >= NOW() - INTERVAL 24 HOUR
        ");
        $failedCount = $failedWorkflows[0]->failed_count ?? 0;

        // File Registry Sync - stuck runs and today's stats
        $syncStuck = DB::selectOne("
            SELECT COUNT(*) as stuck_count
            FROM file_registry_sync_runs
            WHERE status = 'running'
            AND heartbeat_at < NOW() - INTERVAL 1 HOUR
        ");
        $stuckRuns = $syncStuck->stuck_count ?? 0;

        $syncToday = DB::selectOne("
            SELECT
                COUNT(*) as run_count,
                COALESCE(SUM(files_registered), 0) as files_registered,
                COALESCE(SUM(files_scanned), 0) as files_scanned
            FROM file_registry_sync_runs
            WHERE DATE(started_at) = CURDATE()
        ");

        $syncRunning = DB::selectOne("
            SELECT COUNT(*) as running_count
            FROM file_registry_sync_runs
            WHERE status = 'running'
        ");

        // Scheduled Jobs - failures in last 7 days with per-job detail
        $failedJobs7d = DB::select("
            SELECT sj.id, sj.name,
                   COUNT(sjr.id) as failure_count,
                   MAX(sjr.started_at) as last_failure,
                   (SELECT COUNT(*) FROM scheduled_job_runs r2
                    WHERE r2.scheduled_job_id = sj.id
                      AND r2.status = 'failed'
                      AND r2.started_at > COALESCE(
                          (SELECT MAX(r3.started_at) FROM scheduled_job_runs r3
                           WHERE r3.scheduled_job_id = sj.id AND r3.status = 'success'),
                          '2000-01-01'
                      )
                   ) as consecutive_failures
            FROM scheduled_jobs sj
            JOIN scheduled_job_runs sjr ON sjr.scheduled_job_id = sj.id
            WHERE sjr.status = 'failed'
              AND sjr.started_at >= NOW() - INTERVAL 7 DAY
              AND sj.enabled = 1
            GROUP BY sj.id, sj.name
            HAVING consecutive_failures > 0
            ORDER BY failure_count DESC
            LIMIT 20
        ");

        $failedJobCount = count($failedJobs7d);
        $failedJobsList = array_map(fn($j) => [
            'id' => $j->id,
            'name' => $j->name,
            'failures' => (int) $j->failure_count,
            'consecutive' => (int) $j->consecutive_failures,
            'last_failure' => $j->last_failure,
        ], $failedJobs7d);

        // Check for any currently stuck jobs
        $stuckJobs = DB::select("
            SELECT COUNT(*) as stuck_count
            FROM scheduled_jobs
            WHERE last_run_status = 'running'
              AND stall_exempt = 0
              AND COALESCE(job_type, '') <> 'agent_task'
              AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes + 30, 120) MINUTE)
        ");
        $stuckJobCount = (int) ($stuckJobs[0]->stuck_count ?? 0);

        return response()->json([
            'success' => true,
            'items' => [
                [
                    'id' => 'email_queue',
                    'label' => 'Email Queue',
                    'count' => (int) $pendingEmails,
                    'status' => $pendingEmails > 0 ? 'attention' : 'ok',
                    'route' => '/email-queue',
                    'description' => 'Drafts awaiting approval',
                ],
                [
                    'id' => 'file_catalog',
                    'label' => 'File Catalog',
                    'count' => (int) $pendingRagSync,
                    'status' => $pendingRagSync > 100 ? 'attention' : 'ok',
                    'route' => '/file-catalog',
                    'description' => 'Files pending RAG sync',
                ],
                [
                    'id' => 'system_issues',
                    'label' => 'System Issues',
                    'count' => (int) $unresolvedIssues,
                    'status' => $unresolvedIssues > 0 ? 'attention' : 'ok',
                    'route' => '/system-issues',
                    'description' => 'Pending issues (open + resolved)',
                ],
                [
                    'id' => 'executions',
                    'label' => 'Executions',
                    'count' => (int) $failedCount,
                    'status' => $failedCount > 0 ? 'attention' : 'ok',
                    'route' => '/executions',
                    'description' => 'Failed workflows (24h)',
                ],
                [
                    'id' => 'scheduled_jobs',
                    'label' => 'Scheduled Jobs',
                    'count' => $failedJobCount + $stuckJobCount,
                    'status' => $stuckJobCount > 0 ? 'warning' : ($failedJobCount > 0 ? 'attention' : 'ok'),
                    'route' => '/scheduled-jobs',
                    'description' => $stuckJobCount > 0
                        ? "{$stuckJobCount} stuck + {$failedJobCount} failed (7d)"
                        : ($failedJobCount > 0
                            ? "{$failedJobCount} job(s) with failures (7d)"
                            : 'All jobs healthy'),
                    'extra' => [
                        'failed_jobs' => $failedJobsList,
                        'stuck_count' => $stuckJobCount,
                    ],
                ],
                [
                    'id' => 'file_catalog_sync',
                    'label' => 'Catalog Sync',
                    'count' => (int) $stuckRuns,
                    'status' => $stuckRuns > 0 ? 'warning' : ($syncRunning->running_count > 0 ? 'running' : 'ok'),
                    'route' => '/file-catalog',
                    'description' => $stuckRuns > 0
                        ? "{$stuckRuns} stuck run(s)"
                        : ($syncRunning->running_count > 0
                            ? 'Scan running'
                            : "Today: {$syncToday->files_registered} registered, {$syncToday->files_scanned} scanned"),
                    'extra' => [
                        'runs_today' => (int) $syncToday->run_count,
                        'files_registered_today' => (int) $syncToday->files_registered,
                        'files_scanned_today' => (int) $syncToday->files_scanned,
                        'is_running' => (int) $syncRunning->running_count > 0,
                    ],
                ],
                $this->getToolCallsPanel(),
                $this->getKgQualityPanel(),
                $this->getEntityResolutionPanel(),
            ],
            'total_attention' => ($pendingEmails > 0 ? 1 : 0) +
                                 ($pendingRagSync > 100 ? 1 : 0) +
                                 ($unresolvedIssues > 0 ? 1 : 0) +
                                 ($failedCount > 0 ? 1 : 0) +
                                 ($failedJobCount > 0 ? 1 : 0) +
                                 ($stuckRuns > 0 ? 1 : 0) +
                                 ($this->toolCallFailRate > 10 ? 1 : 0) +
                                 ($this->kgQualityAttention ? 1 : 0) +
                                 ($this->entityResolutionAttention ? 1 : 0),
        ]);
    }

    private float $toolCallFailRate = 0;
    private bool $kgQualityAttention = false;
    private bool $entityResolutionAttention = false;

    private function getToolCallsPanel(): array
    {
        try {
            $stats = DB::selectOne("
                SELECT COUNT(*) as total_calls,
                       SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as fail_count,
                       ROUND(AVG(duration_ms)) as avg_duration_ms,
                       COUNT(DISTINCT tool_name) as unique_tools
                FROM mcp_tool_calls
                WHERE created_at >= NOW() - INTERVAL 24 HOUR
            ");

            $totalCalls = (int) ($stats->total_calls ?? 0);
            $failCount = (int) ($stats->fail_count ?? 0);
            $avgDuration = (int) ($stats->avg_duration_ms ?? 0);
            $uniqueTools = (int) ($stats->unique_tools ?? 0);
            $this->toolCallFailRate = $totalCalls > 0 ? round(100.0 * $failCount / $totalCalls, 1) : 0;

            $topErrors = DB::select("
                SELECT tool_name, COUNT(*) as error_count
                FROM mcp_tool_calls
                WHERE success = 0 AND created_at >= NOW() - INTERVAL 24 HOUR
                GROUP BY tool_name
                ORDER BY error_count DESC
                LIMIT 5
            ");

            return [
                'id' => 'tool_calls',
                'label' => 'Tool Calls',
                'count' => $totalCalls,
                'status' => $this->toolCallFailRate > 20 ? 'warning' : ($failCount > 0 ? 'attention' : 'ok'),
                'route' => '/mcp',
                'description' => $totalCalls === 0
                    ? 'No tool calls (24h)'
                    : "{$totalCalls} calls, {$failCount} failed, avg {$avgDuration}ms, {$uniqueTools} tools",
                'extra' => [
                    'fail_count' => $failCount,
                    'fail_rate' => $this->toolCallFailRate,
                    'avg_duration_ms' => $avgDuration,
                    'unique_tools' => $uniqueTools,
                    'top_errors' => array_map(fn($e) => ['tool' => $e->tool_name, 'count' => (int) $e->error_count], $topErrors),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'tool_calls',
                'label' => 'Tool Calls',
                'count' => 0,
                'status' => 'ok',
                'route' => '/mcp',
                'description' => 'Analytics collecting data',
            ];
        }
    }

    private function getKgQualityPanel(): array
    {
        try {
            $run = DB::connection('pgsql_rag')->selectOne("
                SELECT composite_score, accuracy_score, freshness_score, coverage_score,
                       stale_triple_count, orphan_entity_count, total_triples, total_entities,
                       created_at
                FROM kg_quality_runs
                ORDER BY created_at DESC
                LIMIT 1
            ");

            if (!$run) {
                return [
                    'id' => 'kg_quality',
                    'label' => 'KG Quality',
                    'count' => 0,
                    'status' => 'ok',
                    'route' => null,
                    'description' => 'No quality runs yet',
                ];
            }

            $composite = round((float) $run->composite_score * 100, 1);
            $status = $composite >= 80 ? 'ok' : ($composite >= 60 ? 'attention' : 'warning');
            $this->kgQualityAttention = $status !== 'ok';

            return [
                'id' => 'kg_quality',
                'label' => 'KG Quality',
                'count' => $composite,
                'status' => $status,
                'route' => null,
                'description' => sprintf(
                    'Acc %.0f%% | Fresh %.0f%% | Cov %.0f%% | %s stale, %s orphans',
                    (float) $run->accuracy_score * 100,
                    (float) $run->freshness_score * 100,
                    (float) $run->coverage_score * 100,
                    number_format($run->stale_triple_count),
                    number_format($run->orphan_entity_count)
                ),
                'extra' => [
                    'accuracy' => round((float) $run->accuracy_score, 4),
                    'freshness' => round((float) $run->freshness_score, 4),
                    'coverage' => round((float) $run->coverage_score, 4),
                    'composite' => round((float) $run->composite_score, 4),
                    'total_triples' => (int) $run->total_triples,
                    'total_entities' => (int) $run->total_entities,
                    'last_run' => $run->created_at,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'kg_quality',
                'label' => 'KG Quality',
                'count' => 0,
                'status' => 'ok',
                'route' => null,
                'description' => 'Collecting data',
            ];
        }
    }

    private function getEntityResolutionPanel(): array
    {
        try {
            $totalEntities = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM knowledge_graph_entities
            ");
            $embeddedCount = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM knowledge_graph_entity_embeddings
            ");

            $total = (int) ($totalEntities->cnt ?? 0);
            $embedded = (int) ($embeddedCount->cnt ?? 0);
            $coverage = $total > 0 ? round(100.0 * $embedded / $total, 1) : 0;

            $lastRun = DB::connection('pgsql_rag')->selectOne("
                SELECT auto_merged, llm_merged, candidates_found, created_at
                FROM entity_resolution_runs
                ORDER BY created_at DESC
                LIMIT 1
            ");

            $totals7d = DB::connection('pgsql_rag')->selectOne("
                SELECT COALESCE(SUM(auto_merged), 0) as merged,
                       COALESCE(SUM(candidates_found), 0) as candidates
                FROM entity_resolution_runs
                WHERE created_at >= NOW() - INTERVAL '7 days'
            ");

            $pendingReviews = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM agent_review_queue
                WHERE review_type = 'entity_merge_proposal' AND status = 'pending'
            ");
            $pending = (int) ($pendingReviews->cnt ?? 0);

            $status = 'ok';
            if ($coverage < 50 || $pending > 5) {
                $status = 'attention';
                $this->entityResolutionAttention = true;
            }

            $merged7d = (int) ($totals7d->merged ?? 0);

            return [
                'id' => 'entity_resolution',
                'label' => 'Entity Resolution',
                'count' => $coverage,
                'status' => $status,
                'route' => null,
                'description' => sprintf(
                    'Coverage %.0f%% (%s/%s) | 7d merged: %d | Pending: %d',
                    $coverage,
                    number_format($embedded),
                    number_format($total),
                    $merged7d,
                    $pending
                ),
                'extra' => [
                    'total_entities' => $total,
                    'embedded' => $embedded,
                    'coverage_pct' => $coverage,
                    'merged_7d' => $merged7d,
                    'candidates_7d' => (int) ($totals7d->candidates ?? 0),
                    'pending_reviews' => $pending,
                    'last_run' => $lastRun->created_at ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'entity_resolution',
                'label' => 'Entity Resolution',
                'count' => 0,
                'status' => 'ok',
                'route' => null,
                'description' => 'Collecting data',
            ];
        }
    }

    /**
     * INF-4: AI Observability Dashboard — provider health, latency, tokens, audit events.
     */
    public function aiObservability(): JsonResponse
    {
        try {
            // 1. Provider health from llm_instances
            $providers = DB::select("
                SELECT instance_id, instance_name, instance_type, is_active, is_healthy,
                       circuit_state, total_requests, success_rate, priority,
                       last_health_check,
                       JSON_EXTRACT(capabilities, '$.embedding') as has_embedding,
                       JSON_EXTRACT(capabilities, '$.vision') as has_vision
                FROM llm_instances
                ORDER BY priority ASC
            ");

            // 2. Per-provider request stats (last 24h from mcp_tool_calls won't have provider info,
            //    so use agent_execution_log which now tracks tool calls with context)
            $toolStats24h = DB::select("
                SELECT action_detail as tool_name, risk_level, outcome,
                       COUNT(*) as calls,
                       AVG(duration_ms) as avg_ms,
                       MAX(duration_ms) as max_ms
                FROM agent_execution_log
                WHERE action_type = 'tool_call'
                  AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY action_detail, risk_level, outcome
                ORDER BY calls DESC
                LIMIT 50
            ");

            // 3. Token usage by agent (last 24h)
            $tokensByAgent = DB::select("
                SELECT agent_name,
                       COUNT(*) as total_events,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as successes,
                       SUM(CASE WHEN outcome = 'failure' THEN 1 ELSE 0 END) as failures,
                       SUM(CASE WHEN outcome = 'denied' THEN 1 ELSE 0 END) as denied
                FROM agent_execution_log
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND agent_name IS NOT NULL
                GROUP BY agent_name
                ORDER BY total_events DESC
            ");

            // 4. Guardrail events (last 24h)
            $guardrailEvents = DB::select("
                SELECT action_detail, outcome, COUNT(*) as count
                FROM agent_execution_log
                WHERE action_type = 'guardrail_check'
                  AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY action_detail, outcome
                ORDER BY count DESC
                LIMIT 20
            ");

            // 5. Review submissions (last 24h)
            $reviewStats = DB::select("
                SELECT action_detail as review_type, COUNT(*) as count,
                       AVG(JSON_EXTRACT(context, '$.confidence')) as avg_confidence
                FROM agent_execution_log
                WHERE action_type = 'review_submitted'
                  AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY action_detail
            ");

            // 6. Circuit breaker summary
            $circuitBreakers = DB::select("
                SELECT instance_name, circuit_state, consecutive_failures, circuit_opened_at,
                       last_health_check, success_rate
                FROM llm_instances
                WHERE is_active = 1
                ORDER BY priority ASC
            ");

            // 7. Hourly event volume (last 24h)
            $hourlyVolume = DB::select("
                SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour,
                       COUNT(*) as events,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as success,
                       SUM(CASE WHEN outcome = 'failure' THEN 1 ELSE 0 END) as failure
                FROM agent_execution_log
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY hour
                ORDER BY hour ASC
            ");

            // 8. Audit summary from AgentAuditService
            $auditSummary = app(AgentAuditService::class)->getSummary(24);

            return response()->json([
                'providers' => $providers,
                'tool_stats_24h' => $toolStats24h,
                'tokens_by_agent' => $tokensByAgent,
                'guardrail_events' => $guardrailEvents,
                'review_stats' => $reviewStats,
                'circuit_breakers' => $circuitBreakers,
                'hourly_volume' => $hourlyVolume,
                'audit_summary' => $auditSummary,
                'generated_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to load AI observability data: ' . $e->getMessage(),
            ], 500);
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workflow Operations Service
 *
 * Provides agent-callable tool methods for the workflow-ops agent.
 * Wraps WorkflowDiagnosticsService, WorkflowMetricsService,
 * CompensationService, WebhookTriggerService, ScheduledJobService,
 * and WorkflowService for workflow pipeline health monitoring.
 */
class WorkflowOpsService
{
    // =========================================================================
    // ASSESS TOOLS
    // =========================================================================

    /**
     * Get health summary for all workflows — success rates, failure counts,
     * health status classification per workflow.
     */
    public function getHealthSummary(): array
    {
        try {
            $summary = app(WorkflowDiagnosticsService::class)->getHealthSummary();

            $healthy = 0;
            $degraded = 0;
            $critical = 0;
            foreach ($summary as $wf) {
                $status = $wf['health_status'] ?? 'unknown';
                match ($status) {
                    'healthy' => $healthy++,
                    'degraded' => $degraded++,
                    'critical', 'failing' => $critical++,
                    default => null,
                };
            }

            return [
                'workflows' => $summary,
                'summary' => [
                    'total' => count($summary),
                    'healthy' => $healthy,
                    'degraded' => $degraded,
                    'critical' => $critical,
                    'overall' => $critical > 0 ? 'critical' : ($degraded > 0 ? 'degraded' : 'healthy'),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getHealthSummary failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get failing workflows below a health threshold.
     */
    public function getFailingWorkflows(float $threshold = 0.7): array
    {
        try {
            return app(WorkflowDiagnosticsService::class)->getFailingWorkflows($threshold);
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getFailingWorkflows failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get metrics dashboard — execution times, slow nodes, throughput for all workflows.
     */
    public function getMetricsDashboard(): array
    {
        try {
            return app(WorkflowMetricsService::class)->getDashboard();
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getMetricsDashboard failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get compensation/saga stats — rollback counts, success/failure rates, registered handlers.
     */
    public function getCompensationStats(): array
    {
        try {
            return app(CompensationService::class)->getStats();
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getCompensationStats failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get scheduled job stats — enabled/disabled counts, stuck jobs, consecutive failures.
     */
    public function getJobStats(): array
    {
        try {
            $stats = app(ScheduledJobService::class)->getStats();

            // Add workflow-specific job breakdown
            $workflowJobs = DB::select("
                SELECT name, cron_expression, enabled, last_run_at, last_run_status, fail_count
                FROM scheduled_jobs
                WHERE job_type = 'workflow' OR category = 'Workflows'
                ORDER BY name
            ");

            $stats['workflow_jobs'] = $workflowJobs;
            return $stats;
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getJobStats failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get execution history for a specific workflow — recent runs with status, duration, errors.
     */
    public function getExecutionHistory(int $workflow_id, int $limit = 20): array
    {
        try {
            return app(WorkflowService::class)->getExecutionHistory($workflow_id, $limit);
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getExecutionHistory failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get webhook trigger health for all workflows — active triggers, recent fire counts,
     * success/failure rates, response times.
     */
    public function getWebhookStats(): array
    {
        try {
            $triggers = DB::select("SELECT * FROM webhook_triggers ORDER BY workflow_id");

            $results = [];
            $triggerService = app(WebhookTriggerService::class);
            foreach ($triggers as $trigger) {
                try {
                    $stats = $triggerService->getTriggerStats($trigger->id);
                    $results[] = [
                        'id' => $trigger->id,
                        'name' => $trigger->name ?? 'unnamed',
                        'workflow_id' => $trigger->workflow_id,
                        'active' => (bool) ($trigger->is_active ?? true),
                        'stats' => $stats,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'id' => $trigger->id,
                        'name' => $trigger->name ?? 'unnamed',
                        'workflow_id' => $trigger->workflow_id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return [
                'triggers' => $results,
                'summary' => [
                    'total' => count($results),
                    'active' => count(array_filter($results, fn($t) => $t['active'] ?? false)),
                    'with_errors' => count(array_filter($results, fn($t) => isset($t['error']))),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getWebhookStats failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get error patterns across all workflows — common error types, node failure hotspots.
     */
    public function getErrorPatterns(string $period = '7d'): array
    {
        try {
            $workflows = DB::select("SELECT id, name FROM workflows WHERE active = 1");
            $patterns = [];
            $diag = app(WorkflowDiagnosticsService::class);

            foreach ($workflows as $wf) {
                try {
                    $ep = $diag->getErrorPatterns($wf->id, $period);
                    if (!empty($ep)) {
                        $patterns[] = [
                            'workflow_id' => $wf->id,
                            'workflow_name' => $wf->name,
                            'patterns' => $ep,
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip workflows with no error data
                }
            }

            return [
                'period' => $period,
                'workflows_with_errors' => count($patterns),
                'patterns' => $patterns,
            ];
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getErrorPatterns failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get slow nodes across all workflows — nodes exceeding performance thresholds.
     */
    public function getSlowNodes(int $threshold_ms = 5000): array
    {
        try {
            return app(WorkflowMetricsService::class)->getSlowNodes($threshold_ms);
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::getSlowNodes failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // ACT TOOLS
    // =========================================================================

    /**
     * Analyze a specific failing workflow in depth — node failures, error patterns,
     * recommended fixes.
     */
    public function analyzeWorkflow(int $workflow_id, string $period = '7d'): array
    {
        try {
            $analysis = app(WorkflowDiagnosticsService::class)->analyzeWorkflow($workflow_id, $period);
            $fixes = app(WorkflowDiagnosticsService::class)->getRecommendedFixes($workflow_id);

            return [
                'analysis' => $analysis,
                'recommended_fixes' => $fixes,
            ];
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::analyzeWorkflow failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Fix stuck scheduled jobs — jobs that are marked as running but their process
     * has died. Safe operation: only resets state for dead processes.
     */
    public function fixStuckJobs(): array
    {
        try {
            $fixed = app(ScheduledJobService::class)->fixStuckJobs();
            return [
                'fixed_count' => $fixed,
                'message' => $fixed > 0
                    ? "Fixed {$fixed} stuck job(s)"
                    : 'No stuck jobs found',
            ];
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::fixStuckJobs failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Update diagnostics for all workflows — recalculates success rates, health scores,
     * and error pattern analysis. Safe read+write operation.
     */
    public function refreshDiagnostics(string $period = '7d'): array
    {
        try {
            $results = app(WorkflowDiagnosticsService::class)->updateAllDiagnostics($period);
            return [
                'updated' => count($results),
                'period' => $period,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::refreshDiagnostics failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Resume a failed workflow execution from its last checkpoint.
     * SUBMIT FOR REVIEW FIRST for production workflows.
     */
    public function resumeExecution(string $execution_id): array
    {
        try {
            // Verify it can be resumed
            $canResume = app(WorkflowService::class)->canResumeExecution($execution_id);
            if (!$canResume) {
                return ['error' => 'Execution cannot be resumed', 'execution_id' => $execution_id];
            }

            $result = app(WorkflowService::class)->resumeWorkflow($execution_id);
            return [
                'execution_id' => $execution_id,
                'resumed' => true,
                'result' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('WorkflowOpsService::resumeExecution failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}

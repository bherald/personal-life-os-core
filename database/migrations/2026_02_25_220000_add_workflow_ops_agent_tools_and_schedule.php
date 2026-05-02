<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            // ── ASSESS PHASE ──────────────────────────────────────────────
            [
                'name' => 'workflow_health_summary',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getHealthSummary',
                'description' => 'Get health summary for all workflows — success rates, failure counts, health status classification (healthy/degraded/critical) per workflow, and overall pipeline state.',
                'parameters' => '[]',
                'returns_description' => 'Array with per-workflow health status and summary (total, healthy, degraded, critical counts, overall state)',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_failing_workflows',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getFailingWorkflows',
                'description' => 'Get workflows below a health threshold (default 0.7 = 70% success rate). Returns only workflows that need attention with their failure details.',
                'parameters' => json_encode([
                    'threshold' => ['type' => 'number', 'required' => false, 'default' => 0.7, 'description' => 'Health threshold (0.0-1.0). Workflows below this are returned.'],
                ]),
                'returns_description' => 'Array of failing workflows with ID, name, success rate, recent errors, and health score',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_metrics_dashboard',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getMetricsDashboard',
                'description' => 'Get metrics dashboard — execution times, throughput, and slow nodes across all workflows. Shows performance trends and bottlenecks.',
                'parameters' => '[]',
                'returns_description' => 'Array with per-workflow execution metrics, slow nodes list, and throughput data',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_dlq_stats',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getDlqStats',
                'description' => 'Get dead letter queue statistics — pending item count, counts by status (pending/resolved/retried/dismissed) and job type, last 24h activity, and oldest pending item age.',
                'parameters' => '[]',
                'returns_description' => 'Array with DLQ counts by status/type, 24h metrics, and oldest pending item details',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_dlq_pending',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getDlqPending',
                'description' => 'Get pending dead letter queue items requiring review. Shows job type, error context, retry count, and age for each item.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 20, 'description' => 'Maximum number of pending items to return'],
                ]),
                'returns_description' => 'Array of pending DLQ items with ID, job type, error message, context, retry count, and created timestamp',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_compensation_stats',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getCompensationStats',
                'description' => 'Get compensation/saga rollback statistics — compensation counts by status, last 24h activity, registered handler count, and compensation success rate.',
                'parameters' => '[]',
                'returns_description' => 'Array with compensation counts by status, 24h metrics, handler count, and success rate',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_job_stats',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getJobStats',
                'description' => 'Get scheduled job statistics — enabled/disabled counts, stuck jobs, consecutive failure alerts, plus workflow-specific job breakdown with cron schedules and last run status.',
                'parameters' => '[]',
                'returns_description' => 'Array with overall job stats, stuck job count, consecutive failure alerts, and workflow job details',
                'permissions' => '["workflow:read", "system:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_webhook_stats',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getWebhookStats',
                'description' => 'Get webhook trigger health for all workflows — active triggers, recent fire counts, success/failure rates, and response times per trigger.',
                'parameters' => '[]',
                'returns_description' => 'Array with per-trigger stats and summary (total, active, with_errors counts)',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_error_patterns',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getErrorPatterns',
                'description' => 'Get error patterns across all active workflows — common error types, node failure hotspots, and systemic issues for a given time period.',
                'parameters' => json_encode([
                    'period' => ['type' => 'string', 'required' => false, 'default' => '7d', 'description' => 'Analysis period (e.g. 7d, 30d, 24h)'],
                ]),
                'returns_description' => 'Array with workflows that have errors, error pattern details, and period analyzed',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_slow_nodes',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getSlowNodes',
                'description' => 'Get workflow nodes executing above a performance threshold. Identifies bottleneck nodes that slow down workflow execution.',
                'parameters' => json_encode([
                    'threshold_ms' => ['type' => 'integer', 'required' => false, 'default' => 5000, 'description' => 'Threshold in milliseconds. Nodes slower than this are returned.'],
                ]),
                'returns_description' => 'Array of slow nodes with node type, avg/max/p95 execution time, and workflow context',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'workflow_analyze',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'analyzeWorkflow',
                'description' => 'Deep analysis of a specific workflow — node-level failure analysis, error patterns, success rate breakdown, and recommended fixes. Use on each failing workflow identified in assessment.',
                'parameters' => json_encode([
                    'workflow_id' => ['type' => 'integer', 'required' => true, 'description' => 'Workflow ID to analyze'],
                    'period' => ['type' => 'string', 'required' => false, 'default' => '7d', 'description' => 'Analysis period (e.g. 7d, 30d)'],
                ]),
                'returns_description' => 'Array with detailed analysis (node failures, error patterns, success rate) and recommended fixes',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_fix_stuck_jobs',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'fixStuckJobs',
                'description' => 'Fix scheduled jobs stuck in running state where the process has died. Safe operation: only resets state for confirmed-dead processes. Run whenever stuck jobs are detected.',
                'parameters' => '[]',
                'returns_description' => 'Array with fixed_count and message describing what was fixed',
                'permissions' => '["system:write"]',
                'risk_level' => 'write',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_retry_dlq',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'retryDlqItem',
                'description' => 'Retry a specific dead letter queue item. Use when the underlying issue is likely resolved (e.g., transient error, service recovered). LIMIT TO 3 PER RUN.',
                'parameters' => json_encode([
                    'dlq_id' => ['type' => 'integer', 'required' => true, 'description' => 'Dead letter queue item ID to retry'],
                ]),
                'returns_description' => 'Array with dlq_id, retried status, and result message',
                'permissions' => '["workflow:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'workflow',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'workflow_resolve_dlq',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'resolveDlqItem',
                'description' => 'Resolve a DLQ item as handled. SUBMIT FOR REVIEW FIRST unless item is clearly stale (>7 days same error). Marks item as resolved with resolution notes.',
                'parameters' => json_encode([
                    'dlq_id' => ['type' => 'integer', 'required' => true, 'description' => 'Dead letter queue item ID to resolve'],
                    'resolution' => ['type' => 'string', 'required' => false, 'default' => 'Resolved by workflow-ops agent', 'description' => 'Resolution description'],
                ]),
                'returns_description' => 'Array with dlq_id, resolved status, and resolution text',
                'permissions' => '["workflow:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'workflow',
                'requires_confirmation' => 1,
            ],
            [
                'name' => 'workflow_refresh_diagnostics',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'refreshDiagnostics',
                'description' => 'Recalculate health scores, success rates, and error patterns for all workflows. Safe operation that updates the diagnostics table.',
                'parameters' => json_encode([
                    'period' => ['type' => 'string', 'required' => false, 'default' => '7d', 'description' => 'Analysis period (e.g. 7d, 30d)'],
                ]),
                'returns_description' => 'Array with count of updated workflows, period, and per-workflow results',
                'permissions' => '["workflow:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_resume_execution',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'resumeExecution',
                'description' => 'Resume a failed workflow execution from its last checkpoint. SUBMIT FOR REVIEW FIRST. Failed workflows may have side effects. Only use when root cause is resolved.',
                'parameters' => json_encode([
                    'execution_id' => ['type' => 'string', 'required' => true, 'description' => 'Execution ID (UUID) of the failed workflow run to resume'],
                ]),
                'returns_description' => 'Array with execution_id, resumed status, and execution result',
                'permissions' => '["workflow:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'workflow',
                'requires_confirmation' => 1,
            ],
            [
                'name' => 'workflow_execution_history',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getExecutionHistory',
                'description' => 'Get recent execution history for a specific workflow — run status, duration, errors, timestamps. Use to understand failure patterns and timing.',
                'parameters' => json_encode([
                    'workflow_id' => ['type' => 'integer', 'required' => true, 'description' => 'Workflow ID to get history for'],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 20, 'description' => 'Maximum number of runs to return'],
                ]),
                'returns_description' => 'Array of recent execution runs with status, duration, error details, and timestamps',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
        ];

        foreach ($tools as $tool) {
            try {
                $columns = 'name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, enabled, source';
                $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'config\'';
                $values = [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ];

                if (isset($tool['requires_confirmation'])) {
                    $columns .= ', requires_confirmation';
                    $placeholders .= ', ?';
                    $values[] = $tool['requires_confirmation'];
                }

                if (isset($tool['max_calls_per_run'])) {
                    $columns .= ', max_calls_per_run';
                    $placeholders .= ', ?';
                    $values[] = $tool['max_calls_per_run'];
                }

                DB::insert("
                    INSERT INTO agent_tool_registry ({$columns})
                    VALUES ({$placeholders})
                ", $values);
            } catch (\Exception $e) {
                // Skip duplicates (idempotent)
            }
        }

        // Add scheduled job for workflow-ops agent
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'workflow_ops_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'workflow_ops_agent',
                'Workflow pipeline health monitoring: success rates, dead letter queue, stuck jobs, compensation rollbacks, webhook triggers, node performance',
                'workflow-ops',
                '*/30 * * * *',
                'agent_task',
                1,
                'Agent',
                10,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }
    }

    public function down(): void
    {
        $toolNames = [
            'workflow_health_summary', 'workflow_failing_workflows', 'workflow_metrics_dashboard',
            'workflow_dlq_stats', 'workflow_dlq_pending', 'workflow_compensation_stats',
            'workflow_job_stats', 'workflow_webhook_stats', 'workflow_error_patterns',
            'workflow_slow_nodes', 'workflow_analyze', 'workflow_fix_stuck_jobs',
            'workflow_retry_dlq', 'workflow_resolve_dlq', 'workflow_refresh_diagnostics',
            'workflow_resume_execution', 'workflow_execution_history',
        ];

        $placeholders = implode(',', array_fill(0, count($toolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $toolNames);
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'workflow_ops_agent'");
    }
};

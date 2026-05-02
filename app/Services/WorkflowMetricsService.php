<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class WorkflowMetricsService
{
    public function recordMetric(int $runId, ?int $nodeExecutionId, ?string $nodeType, string $name, float $value, string $unit = 'ms'): void
    {
        DB::insert(
            "INSERT INTO workflow_execution_metrics (workflow_run_id, node_execution_id, node_type, metric_name, metric_value, unit, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$runId, $nodeExecutionId, $nodeType, $name, $value, $unit]
        );
    }

    public function getRunMetrics(int $runId): array
    {
        return DB::select(
            "SELECT * FROM workflow_execution_metrics WHERE workflow_run_id = ? ORDER BY recorded_at ASC",
            [$runId]
        );
    }

    public function getNodeTypeStats(string $nodeType, ?string $startDate = null, ?string $endDate = null): array
    {
        $params = [$nodeType, 'execution_time'];
        $dateFilter = '';
        if ($startDate && $endDate) {
            $dateFilter = 'AND recorded_at BETWEEN ? AND ?';
            $params[] = $startDate;
            $params[] = $endDate;
        }

        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total_executions,
                AVG(metric_value) as avg_ms,
                MIN(metric_value) as min_ms,
                MAX(metric_value) as max_ms,
                -- Approximate P95 using PERCENT_RANK
                PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY metric_value) as p95_ms,
                PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY metric_value) as p99_ms
             FROM workflow_execution_metrics
             WHERE node_type = ? AND metric_name = ? {$dateFilter}",
            $params
        );

        // MySQL fallback (no PERCENTILE_CONT)
        if ($stats === null || $stats === false) {
            $stats = DB::selectOne(
                "SELECT
                    COUNT(*) as total_executions,
                    AVG(metric_value) as avg_ms,
                    MIN(metric_value) as min_ms,
                    MAX(metric_value) as max_ms
                 FROM workflow_execution_metrics
                 WHERE node_type = ? AND metric_name = ? {$dateFilter}",
                $params
            );
        }

        return [
            'node_type' => $nodeType,
            'total_executions' => $stats->total_executions ?? 0,
            'avg_ms' => round($stats->avg_ms ?? 0, 2),
            'min_ms' => round($stats->min_ms ?? 0, 2),
            'max_ms' => round($stats->max_ms ?? 0, 2),
            'p95_ms' => round($stats->p95_ms ?? $stats->max_ms ?? 0, 2),
            'p99_ms' => round($stats->p99_ms ?? $stats->max_ms ?? 0, 2),
        ];
    }

    public function getWorkflowStats(int $workflowId, ?string $startDate = null, ?string $endDate = null): array
    {
        $params = [$workflowId];
        $dateFilter = '';
        if ($startDate && $endDate) {
            $dateFilter = 'AND wr.started_at BETWEEN ? AND ?';
            $params[] = $startDate;
            $params[] = $endDate;
        }

        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total_runs,
                SUM(CASE WHEN wr.status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN wr.status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(TIMESTAMPDIFF(SECOND, wr.started_at, wr.completed_at)) as avg_duration_sec
             FROM workflow_runs wr
             WHERE wr.workflow_id = ? {$dateFilter}",
            $params
        );

        $total = $stats->total_runs ?? 0;
        $completed = $stats->completed ?? 0;

        return [
            'workflow_id' => $workflowId,
            'total_runs' => $total,
            'completed' => $completed,
            'failed' => $stats->failed ?? 0,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'avg_duration_sec' => round($stats->avg_duration_sec ?? 0, 1),
        ];
    }

    public function getSlowNodes(float $thresholdMs = 5000): array
    {
        return DB::select(
            "SELECT node_type,
                    COUNT(*) as occurrences,
                    AVG(metric_value) as avg_ms,
                    MAX(metric_value) as max_ms
             FROM workflow_execution_metrics
             WHERE metric_name = 'execution_time'
             AND metric_value > ?
             GROUP BY node_type
             ORDER BY avg_ms DESC",
            [$thresholdMs]
        );
    }

    public function exportMetrics(?string $startDate = null, ?string $endDate = null): array
    {
        $params = [];
        $where = '';
        if ($startDate && $endDate) {
            $where = 'WHERE recorded_at BETWEEN ? AND ?';
            $params = [$startDate, $endDate];
        }

        return DB::select(
            "SELECT wem.*, w.name as workflow_name
             FROM workflow_execution_metrics wem
             JOIN workflow_runs wr ON wr.id = wem.workflow_run_id
             JOIN workflows w ON w.id = wr.workflow_id
             {$where}
             ORDER BY wem.recorded_at ASC",
            $params
        );
    }

    // =========================================================================
    // PROMETHEUS EXPORT
    // =========================================================================

    public function exportPrometheus(): string
    {
        $output = [];

        // Get all workflow stats
        $workflows = DB::select("SELECT id, name FROM workflows WHERE active = 1");
        foreach ($workflows as $workflow) {
            $stats = $this->getWorkflowStats($workflow->id);
            $name = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($workflow->name));

            $output[] = "# HELP workflow_{$name}_runs_total Total workflow runs";
            $output[] = "# TYPE workflow_{$name}_runs_total counter";
            $output[] = "workflow_{$name}_runs_total {$stats['total_runs']}";

            $output[] = "# HELP workflow_{$name}_success_rate Workflow success rate percentage";
            $output[] = "# TYPE workflow_{$name}_success_rate gauge";
            $output[] = "workflow_{$name}_success_rate {$stats['success_rate']}";

            $output[] = "# HELP workflow_{$name}_avg_duration_seconds Average duration in seconds";
            $output[] = "# TYPE workflow_{$name}_avg_duration_seconds gauge";
            $output[] = "workflow_{$name}_avg_duration_seconds {$stats['avg_duration_sec']}";
        }

        // Node type metrics
        $nodeTypes = DB::select(
            "SELECT DISTINCT node_type FROM workflow_execution_metrics WHERE node_type IS NOT NULL"
        );
        foreach ($nodeTypes as $nt) {
            $stats = $this->getNodeTypeStats($nt->node_type);
            $type = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($nt->node_type));

            $output[] = "# HELP node_{$type}_executions_total Total node executions";
            $output[] = "# TYPE node_{$type}_executions_total counter";
            $output[] = "node_{$type}_executions_total {$stats['total_executions']}";

            $output[] = "# HELP node_{$type}_avg_ms Average execution time in ms";
            $output[] = "# TYPE node_{$type}_avg_ms gauge";
            $output[] = "node_{$type}_avg_ms {$stats['avg_ms']}";
        }

        return implode("\n", $output);
    }

    public function getDashboard(): array
    {
        $workflows = DB::select("SELECT id, name FROM workflows WHERE active = 1");
        $workflowStats = [];
        foreach ($workflows as $w) {
            $workflowStats[] = array_merge(['name' => $w->name], $this->getWorkflowStats($w->id));
        }

        return [
            'workflows' => $workflowStats,
            'slow_nodes' => $this->getSlowNodes(5000),
            'node_types' => DB::select(
                "SELECT node_type, COUNT(*) as executions, AVG(metric_value) as avg_ms
                 FROM workflow_execution_metrics
                 WHERE metric_name = 'execution_time'
                 GROUP BY node_type
                 ORDER BY executions DESC
                 LIMIT 20"
            ),
        ];
    }
}

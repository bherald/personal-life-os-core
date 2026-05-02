<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ExecuteWorkflow;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ExecutionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Build WHERE conditions and parameters
        $conditions = [];
        $params = [];

        if ($workflowId = $request->query('workflow_id')) {
            $conditions[] = 'wr.workflow_id = ?';
            $params[] = $workflowId;
        }

        if ($status = $request->query('status')) {
            $conditions[] = 'wr.status = ?';
            $params[] = $status;
        }

        if ($dateFrom = $request->query('date_from')) {
            $conditions[] = 'wr.started_at >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo = $request->query('date_to')) {
            $conditions[] = 'wr.started_at <= ?';
            $params[] = $dateTo;
        }

        // Build WHERE clause
        $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        // Execute query using raw SQL with parameters
        $limit = $request->query('limit', 100);
        $sql = "SELECT wr.*, w.name as workflow_name
                FROM workflow_runs wr
                JOIN workflows w ON wr.workflow_id = w.id
                {$whereClause}
                ORDER BY wr.id DESC
                LIMIT ?";
        $params[] = $limit;
        $runs = DB::select($sql, $params);

        return response()->json([
            'success' => true,
            'data' => $runs
        ]);
    }

    public function show(int $id): JsonResponse
    {
        // Get workflow run using raw SQL
        $sql = "SELECT wr.*, w.name as workflow_name
                FROM workflow_runs wr
                JOIN workflows w ON wr.workflow_id = w.id
                WHERE wr.id = ?
                LIMIT 1";
        $runs = DB::select($sql, [$id]);
        $run = $runs[0] ?? null;

        if (!$run) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Execution not found']
            ], 404);
        }

        // Get node executions using raw SQL
        $sql = "SELECT * FROM node_executions WHERE run_id = ? ORDER BY node_order";
        $nodeExecutions = DB::select($sql, [$id]);

        // Get inputs/outputs for each node using raw SQL
        foreach ($nodeExecutions as $execution) {
            $sql = "SELECT id, node_execution_id, input_key as `key`, input_value as value
                    FROM node_execution_inputs
                    WHERE node_execution_id = ?";
            $execution->inputs = DB::select($sql, [$execution->id]);

            $sql = "SELECT id, node_execution_id, output_stream, output_key as `key`, output_value as value
                    FROM node_execution_outputs
                    WHERE node_execution_id = ?";
            $execution->outputs = DB::select($sql, [$execution->id]);
        }

        // Get run inputs/outputs using raw SQL
        $sql = "SELECT id, run_id, input_key as `key`, input_value as value
                FROM workflow_run_inputs
                WHERE run_id = ?";
        $runInputs = DB::select($sql, [$id]);

        $sql = "SELECT id, run_id, output_key as `key`, output_value as value
                FROM workflow_run_outputs
                WHERE run_id = ?";
        $runOutputs = DB::select($sql, [$id]);

        return response()->json([
            'success' => true,
            'data' => [
                'run' => $run,
                'node_executions' => $nodeExecutions,
                'inputs' => $runInputs,
                'outputs' => $runOutputs
            ]
        ]);
    }

    public function retry(int $id): JsonResponse
    {
        // Get workflow run using raw SQL
        $sql = "SELECT wr.*, w.name as workflow_name
                FROM workflow_runs wr
                JOIN workflows w ON wr.workflow_id = w.id
                WHERE wr.id = ?
                LIMIT 1";
        $runs = DB::select($sql, [$id]);
        $run = $runs[0] ?? null;

        if (!$run) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Execution not found']
            ], 404);
        }

        if ($run->status !== 'failed') {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_STATUS', 'message' => 'Can only retry failed executions']
            ], 400);
        }

        try {
            $runInputs = DB::select(
                "SELECT input_key, input_value FROM workflow_run_inputs WHERE run_id = ? ORDER BY id",
                [$id]
            );

            $input = [];
            foreach ($runInputs as $runInput) {
                $input[$runInput->input_key] = $this->decodeStoredValue($runInput->input_value);
            }

            ExecuteWorkflow::dispatch($run->workflow_name, $run->workflow_id, $input);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'queued',
                    'message' => 'Workflow retry queued for execution',
                    'workflow_id' => $run->workflow_id,
                    'workflow_name' => $run->workflow_name,
                    'original_run_id' => $run->id,
                    'input_keys' => array_keys($input),
                ]
            ], 202);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'RETRY_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    private function decodeStoredValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function stats(Request $request): JsonResponse
    {
        // Get all stats using raw SQL with parameters
        $sql = "SELECT COUNT(*) as count FROM workflow_runs";
        $totalRuns = DB::select($sql)[0]->count ?? 0;

        $sql = "SELECT COUNT(*) as count FROM workflow_runs WHERE status = ?";
        $completed = DB::select($sql, ['completed'])[0]->count ?? 0;

        $sql = "SELECT COUNT(*) as count FROM workflow_runs WHERE status = ?";
        $failed = DB::select($sql, ['failed'])[0]->count ?? 0;

        $sql = "SELECT COUNT(*) as count FROM workflow_runs WHERE status = ?";
        $running = DB::select($sql, ['running'])[0]->count ?? 0;

        // Recent activity (last 24 hours)
        $sql = "SELECT COUNT(*) as count FROM workflow_runs
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $recentRuns = DB::select($sql)[0]->count ?? 0;

        // Average duration for completed runs using MySQL TIMESTAMPDIFF
        $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_seconds
                FROM workflow_runs
                WHERE status = ? AND completed_at IS NOT NULL";
        $avgDuration = DB::select($sql, ['completed'])[0]->avg_seconds ?? 0;

        $stats = [
            'total_runs' => $totalRuns,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'recent_runs' => $recentRuns,
            'avg_duration_seconds' => $avgDuration ? round($avgDuration, 2) : 0
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}

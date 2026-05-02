<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workflow Diagnostics Service
 *
 * Analyzes workflow failures and identifies problematic patterns using RAW SQL with parameters.
 * NO Eloquent, NO Query Builder - only prepared statements for maximum performance and security.
 *
 * Features:
 * - Workflow performance analysis
 * - Failure pattern detection
 * - Node-level failure analysis
 * - Success rate calculation
 * - Automated diagnostics updates
 * - Recommended fix generation
 *
 * Usage:
 * ```php
 * $diagnostics = app(WorkflowDiagnosticsService::class);
 * $analysis = $diagnostics->analyzeWorkflow($workflowId);
 * ```
 */
class WorkflowDiagnosticsService
{
    /**
     * Health status thresholds
     */
    private const THRESHOLD_DEGRADED = 80.0;  // Success rate below 80%
    private const THRESHOLD_FAILING = 50.0;   // Success rate below 50%
    private const THRESHOLD_CRITICAL = 25.0;  // Success rate below 25%

    /**
     * Analysis periods
     */
    private const PERIOD_SHORT = '24 hours';
    private const PERIOD_MEDIUM = '7 days';
    private const PERIOD_LONG = '30 days';

    /**
     * Analyze workflow performance and health using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @param string $period Analysis period (e.g., '7 days', '24 hours')
     * @return array Comprehensive workflow analysis
     */
    public function analyzeWorkflow(int $workflowId, string $period = self::PERIOD_MEDIUM): array
    {
        try {
            $since = now()->sub($period)->toDateTimeString();

            // Get run counts in a single query
            $countsSql = "SELECT COUNT(*) as total,
                                 SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                                 SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                          FROM workflow_runs
                          WHERE workflow_id = ? AND started_at >= ?";
            $counts = DB::selectOne($countsSql, [$workflowId, $since]);
            $total = (int) ($counts->total ?? 0);
            $successful = (int) ($counts->successful ?? 0);
            $failed = (int) ($counts->failed ?? 0);

            // Calculate success rate (no runs = healthy, not 0%)
            $successRate = $total > 0 ? round(($successful / $total) * 100, 2) : 100.0;

            // Get average duration
            $durationSql = "SELECT AVG(TIMESTAMPDIFF(MICROSECOND, started_at, completed_at) / 1000) as avg_ms
                           FROM workflow_runs
                           WHERE workflow_id = ? AND completed_at IS NOT NULL AND started_at >= ?";
            $avgDuration = DB::select($durationSql, [$workflowId, $since])[0]->avg_ms ?? 0;

            // Get error patterns
            $errorPatterns = $this->getErrorPatterns($workflowId, $period);

            // Get node failures
            $nodeFailures = $this->analyzeNodeFailures($workflowId, $period);

            // Get last failure
            $lastFailureSql = "SELECT started_at
                              FROM workflow_runs
                              WHERE workflow_id = ? AND status = 'failed'
                              ORDER BY started_at DESC
                              LIMIT 1";
            $lastFailureResult = DB::select($lastFailureSql, [$workflowId]);
            $lastFailure = $lastFailureResult[0]->started_at ?? null;

            // Calculate consecutive failures
            $consecutiveFailures = $this->getConsecutiveFailures($workflowId);

            // Determine health status
            $healthStatus = $this->determineHealthStatus($successRate);

            // Get recommended fixes
            $recommendations = $this->getRecommendedFixes($workflowId);

            return [
                'workflow_id' => $workflowId,
                'period' => $period,
                'total_runs' => $total,
                'successful_runs' => $successful,
                'failed_runs' => $failed,
                'success_rate' => $successRate,
                'failure_rate' => round(100 - $successRate, 2),
                'avg_duration_ms' => (int) round($avgDuration),
                'health_status' => $healthStatus,
                'consecutive_failures' => $consecutiveFailures,
                'last_failure_at' => $lastFailure,
                'error_patterns' => $errorPatterns,
                'node_failures' => $nodeFailures,
                'recommendations' => $recommendations,
                'analyzed_at' => now()->toDateTimeString(),
            ];
        } catch (Exception $e) {
            Log::error('Workflow analysis failed', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update or create diagnostics record using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @param string $period Analysis period
     * @return bool Success
     */
    public function updateDiagnostics(int $workflowId, string $period = self::PERIOD_MEDIUM): bool
    {
        try {
            $analysis = $this->analyzeWorkflow($workflowId, $period);

            // Check if diagnostic record exists
            $existsSql = "SELECT id FROM workflow_diagnostics WHERE workflow_id = ?";
            $existing = DB::select($existsSql, [$workflowId]);

            if (!empty($existing)) {
                // Update existing record
                $updateSql = "UPDATE workflow_diagnostics
                             SET total_runs = ?,
                                 successful_runs = ?,
                                 failed_runs = ?,
                                 avg_duration_ms = ?,
                                 most_common_error = ?,
                                 error_frequency = ?,
                                 failing_nodes = ?,
                                 success_rate = ?,
                                 health_status = ?,
                                 last_failure_at = ?,
                                 consecutive_failures = ?,
                                 last_analysis_at = NOW(),
                                 updated_at = NOW()
                             WHERE workflow_id = ?";

                $params = [
                    $analysis['total_runs'],
                    $analysis['successful_runs'],
                    $analysis['failed_runs'],
                    $analysis['avg_duration_ms'],
                    $analysis['error_patterns']['most_common'] ?? null,
                    json_encode($analysis['error_patterns']['frequency'] ?? []),
                    json_encode(array_keys($analysis['node_failures'])),
                    $analysis['success_rate'],
                    $analysis['health_status'],
                    $analysis['last_failure_at'],
                    $analysis['consecutive_failures'],
                    $workflowId,
                ];

                DB::update($updateSql, $params);
            } else {
                // Create new record
                $insertSql = "INSERT INTO workflow_diagnostics (
                                workflow_id,
                                total_runs,
                                successful_runs,
                                failed_runs,
                                avg_duration_ms,
                                most_common_error,
                                error_frequency,
                                failing_nodes,
                                success_rate,
                                health_status,
                                last_failure_at,
                                consecutive_failures,
                                last_analysis_at,
                                created_at,
                                updated_at
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())";

                $params = [
                    $workflowId,
                    $analysis['total_runs'],
                    $analysis['successful_runs'],
                    $analysis['failed_runs'],
                    $analysis['avg_duration_ms'],
                    $analysis['error_patterns']['most_common'] ?? null,
                    json_encode($analysis['error_patterns']['frequency'] ?? []),
                    json_encode(array_keys($analysis['node_failures'])),
                    $analysis['success_rate'],
                    $analysis['health_status'],
                    $analysis['last_failure_at'],
                    $analysis['consecutive_failures'],
                ];

                DB::insert($insertSql, $params);
            }

            Log::info('Workflow diagnostics updated', [
                'workflow_id' => $workflowId,
                'health_status' => $analysis['health_status'],
                'success_rate' => $analysis['success_rate'],
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('Diagnostics update failed', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get workflows by health threshold using raw SQL
     *
     * @param string $threshold Health threshold: healthy, degraded, failing, critical
     * @return array Array of workflow diagnostic records
     */
    public function getFailingWorkflows(string $threshold = 'degraded'): array
    {
        $statuses = match ($threshold) {
            'healthy' => ['healthy'],
            'degraded' => ['degraded', 'failing', 'critical'],
            'failing' => ['failing', 'critical'],
            'critical' => ['critical'],
            default => ['degraded', 'failing', 'critical'],
        };

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        $sql = "SELECT workflow_id, health_status, success_rate, failed_runs,
                       consecutive_failures, most_common_error, last_failure_at, total_runs
                FROM workflow_diagnostics
                WHERE health_status IN ($placeholders)
                  AND total_runs > 0
                ORDER BY success_rate ASC, consecutive_failures DESC";

        return DB::select($sql, $statuses);
    }

    /**
     * Analyze node-level failures using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @param string $period Analysis period
     * @return array Node failure analysis
     */
    public function analyzeNodeFailures(int $workflowId, string $period = self::PERIOD_MEDIUM): array
    {
        $since = now()->sub($period)->toDateTimeString();

        $sql = "SELECT node_id, node_type, COUNT(*) as failure_count
                FROM system_errors
                WHERE workflow_id = ?
                  AND source_type = 'workflow'
                  AND node_id IS NOT NULL
                  AND occurred_at >= ?
                GROUP BY node_id, node_type
                ORDER BY failure_count DESC";

        $results = DB::select($sql, [$workflowId, $since]);

        $nodeFailures = [];
        foreach ($results as $row) {
            $nodeFailures[$row->node_id] = [
                'node_id' => $row->node_id,
                'node_type' => $row->node_type,
                'failure_count' => $row->failure_count,
            ];
        }

        return $nodeFailures;
    }

    /**
     * Get error patterns for workflow using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @param string $period Analysis period
     * @return array Error pattern analysis
     */
    public function getErrorPatterns(int $workflowId, string $period = self::PERIOD_MEDIUM): array
    {
        $since = now()->sub($period)->toDateTimeString();

        $sql = "SELECT error_type, error_message, COUNT(*) as count
                FROM system_errors
                WHERE workflow_id = ?
                  AND source_type = 'workflow'
                  AND occurred_at >= ?
                GROUP BY error_type, error_message
                ORDER BY count DESC
                LIMIT 10";

        $results = DB::select($sql, [$workflowId, $since]);

        $frequency = [];
        $mostCommon = null;
        $maxCount = 0;

        foreach ($results as $row) {
            $key = $row->error_type;
            $frequency[$key] = [
                'type' => $row->error_type,
                'message' => $row->error_message,
                'count' => $row->count,
            ];

            if ($row->count > $maxCount) {
                $maxCount = $row->count;
                $mostCommon = $key;
            }
        }

        return [
            'frequency' => $frequency,
            'most_common' => $mostCommon,
            'total_unique_errors' => count($frequency),
        ];
    }

    /**
     * Calculate workflow success rate using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @param string $period Analysis period
     * @return float Success rate percentage
     */
    public function calculateSuccessRate(int $workflowId, string $period = self::PERIOD_MEDIUM): float
    {
        $since = now()->sub($period)->toDateTimeString();

        // Get total and successful runs
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful
                FROM workflow_runs
                WHERE workflow_id = ? AND started_at >= ?";

        $result = DB::select($sql, [$workflowId, $since])[0] ?? null;

        if (!$result || $result->total == 0) {
            return 0.0;
        }

        return round(($result->successful / $result->total) * 100, 2);
    }

    /**
     * Get recommended fixes based on error patterns using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @return array Array of recommended fixes
     */
    public function getRecommendedFixes(int $workflowId): array
    {
        $recommendations = [];

        // Get recent errors for analysis
        $errorSql = "SELECT error_type, error_message, node_type, COUNT(*) as count
                    FROM system_errors
                    WHERE workflow_id = ?
                      AND source_type = 'workflow'
                      AND occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    GROUP BY error_type, error_message, node_type
                    ORDER BY count DESC
                    LIMIT 5";

        $errors = DB::select($errorSql, [$workflowId]);

        foreach ($errors as $error) {
            $errorType = $error->error_type ?? '';
            $nodeType = $error->node_type ?? 'unknown';
            $errorMessage = $error->error_message ?? '';

            // Generate context-aware recommendations
            if (str_contains($errorType, 'Timeout') || str_contains($errorMessage, 'timeout')) {
                $recommendations[] = [
                    'issue' => "Timeout errors in {$nodeType}",
                    'severity' => 'high',
                    'recommendation' => 'Increase timeout limits or optimize node processing',
                    'occurrences' => $error->count,
                ];
            } elseif (str_contains($errorType, 'Connection') || str_contains($errorMessage, 'connection')) {
                $recommendations[] = [
                    'issue' => "Connection failures in {$nodeType}",
                    'severity' => 'high',
                    'recommendation' => 'Implement retry logic with exponential backoff',
                    'occurrences' => $error->count,
                ];
            } elseif (str_contains($errorType, 'Validation') || str_contains($errorMessage, 'validation')) {
                $recommendations[] = [
                    'issue' => "Validation errors in {$nodeType}",
                    'severity' => 'medium',
                    'recommendation' => 'Review input data validation rules and error handling',
                    'occurrences' => $error->count,
                ];
            } elseif (str_contains($errorType, 'Memory') || str_contains($errorMessage, 'memory')) {
                $recommendations[] = [
                    'issue' => "Memory issues in {$nodeType}",
                    'severity' => 'critical',
                    'recommendation' => 'Optimize memory usage or increase memory limits',
                    'occurrences' => $error->count,
                ];
            } else {
                $recommendations[] = [
                    'issue' => "Recurring {$errorType} errors",
                    'severity' => 'medium',
                    'recommendation' => 'Review error logs and implement proper error handling',
                    'occurrences' => $error->count,
                ];
            }
        }

        // Check for high failure rate
        $successRate = $this->calculateSuccessRate($workflowId);
        if ($successRate < self::THRESHOLD_FAILING) {
            $recommendations[] = [
                'issue' => 'Critical success rate',
                'severity' => 'critical',
                'recommendation' => 'Immediate investigation required - success rate below 50%',
                'occurrences' => null,
            ];
        }

        // Check for consecutive failures
        $consecutiveFailures = $this->getConsecutiveFailures($workflowId);
        if ($consecutiveFailures >= 5) {
            $recommendations[] = [
                'issue' => 'Consecutive failures detected',
                'severity' => 'critical',
                'recommendation' => 'Disable workflow until root cause is identified',
                'occurrences' => $consecutiveFailures,
            ];
        }

        return $recommendations;
    }

    /**
     * Get count of consecutive failures using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @return int Consecutive failure count
     */
    private function getConsecutiveFailures(int $workflowId): int
    {
        $sql = "SELECT status
                FROM workflow_runs
                WHERE workflow_id = ?
                ORDER BY started_at DESC
                LIMIT 20";

        $results = DB::select($sql, [$workflowId]);

        $consecutive = 0;
        foreach ($results as $row) {
            if ($row->status === 'failed') {
                $consecutive++;
            } else {
                break;
            }
        }

        return $consecutive;
    }

    /**
     * Determine health status from success rate
     *
     * @param float $successRate Success rate percentage
     * @return string Health status: healthy, degraded, failing, critical
     */
    private function determineHealthStatus(float $successRate): string
    {
        return match (true) {
            $successRate >= self::THRESHOLD_DEGRADED => 'healthy',
            $successRate >= self::THRESHOLD_FAILING => 'degraded',
            $successRate >= self::THRESHOLD_CRITICAL => 'failing',
            default => 'critical',
        };
    }

    /**
     * Get workflow health summary using raw SQL
     *
     * @return array Health summary for all workflows
     */
    public function getHealthSummary(): array
    {
        $sql = "SELECT
                    COUNT(*) as total_workflows,
                    SUM(CASE WHEN health_status = 'healthy' THEN 1 ELSE 0 END) as healthy_count,
                    SUM(CASE WHEN health_status = 'degraded' THEN 1 ELSE 0 END) as degraded_count,
                    SUM(CASE WHEN health_status = 'failing' THEN 1 ELSE 0 END) as failing_count,
                    SUM(CASE WHEN health_status = 'critical' THEN 1 ELSE 0 END) as critical_count,
                    AVG(success_rate) as avg_success_rate
                FROM workflow_diagnostics";

        $result = DB::select($sql)[0] ?? null;

        if (!$result) {
            return [
                'total_workflows' => 0,
                'healthy_count' => 0,
                'degraded_count' => 0,
                'failing_count' => 0,
                'critical_count' => 0,
                'avg_success_rate' => 0.0,
            ];
        }

        return [
            'total_workflows' => $result->total_workflows ?? 0,
            'healthy_count' => $result->healthy_count ?? 0,
            'degraded_count' => $result->degraded_count ?? 0,
            'failing_count' => $result->failing_count ?? 0,
            'critical_count' => $result->critical_count ?? 0,
            'avg_success_rate' => round($result->avg_success_rate ?? 0, 2),
        ];
    }

    /**
     * Update all workflow diagnostics using raw SQL
     *
     * @param string $period Analysis period
     * @return array Update summary
     */
    public function updateAllDiagnostics(string $period = self::PERIOD_MEDIUM): array
    {
        // Get all active workflows
        $workflowsSql = "SELECT id FROM workflows WHERE active = 1";
        $workflows = DB::select($workflowsSql);

        $updated = 0;
        $failed = 0;

        foreach ($workflows as $workflow) {
            try {
                $this->updateDiagnostics($workflow->id, $period);
                $updated++;
            } catch (Exception $e) {
                $failed++;
                Log::error('Failed to update workflow diagnostics', [
                    'workflow_id' => $workflow->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'total_workflows' => count($workflows),
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /**
     * Get diagnostics by health status using raw SQL
     *
     * @param string $status Health status: healthy, degraded, failing, critical
     * @param int $limit Maximum number of records to return
     * @return array Array of workflow diagnostic records
     */
    public function getDiagnosticsByHealthStatus(string $status, int $limit = 100): array
    {
        $sql = "SELECT workflow_id, health_status, success_rate, total_runs,
                       successful_runs, failed_runs, consecutive_failures,
                       most_common_error, last_failure_at
                FROM workflow_diagnostics
                WHERE health_status = ?
                ORDER BY success_rate ASC
                LIMIT ?";

        return DB::select($sql, [$status, $limit]);
    }

    /**
     * Get diagnostics below success rate threshold using raw SQL
     *
     * @param float $threshold Success rate threshold
     * @param int $limit Maximum number of records to return
     * @return array Array of workflow diagnostic records
     */
    public function getDiagnosticsBelowSuccessRate(float $threshold, int $limit = 100): array
    {
        $sql = "SELECT workflow_id, health_status, success_rate, total_runs,
                       successful_runs, failed_runs, consecutive_failures,
                       most_common_error, last_failure_at
                FROM workflow_diagnostics
                WHERE success_rate < ?
                ORDER BY success_rate ASC, consecutive_failures DESC
                LIMIT ?";

        return DB::select($sql, [$threshold, $limit]);
    }

    // =========================================================================
    // SVC-007: Merged from WorkflowHealthCheck (deleted)
    // =========================================================================

    /**
     * Auto-clean stuck workflow runs that exceeded timeout + 15min buffer.
     * Returns count of cleaned runs.
     */
    public function autoCleanStuckRuns(): int
    {
        try {
            $stuck = DB::select("
                SELECT wr.id
                FROM workflow_runs wr
                JOIN workflows w ON w.id = wr.workflow_id
                LEFT JOIN scheduled_jobs sj ON sj.command = w.name AND sj.job_type = 'workflow'
                WHERE wr.status = 'running'
                AND wr.started_at < DATE_SUB(NOW(), INTERVAL COALESCE(sj.timeout_minutes, 120) + 15 MINUTE)
            ");

            if (empty($stuck)) {
                return 0;
            }

            $ids = array_map(fn($r) => $r->id, $stuck);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $cleaned = DB::update(
                "UPDATE workflow_runs SET status = 'failed', error_message = 'Auto-cleaned: exceeded timeout + 15min buffer', completed_at = NOW() WHERE id IN ({$placeholders})",
                $ids
            );

            Log::info('WorkflowDiagnostics: Auto-cleaned stuck runs', ['count' => $cleaned, 'run_ids' => $ids]);
            return $cleaned;
        } catch (\Throwable $e) {
            Log::warning('WorkflowDiagnostics: autoCleanStuckRuns failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Check for scheduled workflows that missed their expected run.
     * Returns array of missed workflows with schedule details.
     */
    public function checkMissedSchedules(): array
    {
        try {
            $workflows = DB::select("
                SELECT w.id, w.name, sj.cron_expression AS schedule
                FROM workflows w
                JOIN scheduled_jobs sj
                  ON sj.job_type = 'workflow'
                 AND sj.command = w.name
                WHERE w.active = 1
                  AND sj.enabled = 1
                  AND sj.cron_expression IS NOT NULL
            ");

            $missed = [];
            $now = now();

            foreach ($workflows as $workflow) {
                try {
                    $cron = new \Cron\CronExpression($workflow->schedule);
                    $previousRunTime = $cron->getPreviousRunDate($now);

                    $lastRun = DB::selectOne("
                        SELECT id FROM workflow_runs
                        WHERE workflow_id = ? AND started_at >= ? AND started_at <= ?
                        LIMIT 1
                    ", [$workflow->id, $previousRunTime, $now]);

                    if (!$lastRun) {
                        $missed[] = [
                            'name' => $workflow->name,
                            'schedule' => $workflow->schedule,
                            'expected_at' => $previousRunTime->format('Y-m-d H:i:s'),
                            'minutes_late' => (int) $now->diffInMinutes($previousRunTime),
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return $missed;
        } catch (\Throwable $e) {
            Log::warning('WorkflowDiagnostics: checkMissedSchedules failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get failed workflow runs in the last N hours.
     */
    public function getRecentFailures(int $hours = 24): array
    {
        try {
            return DB::select("
                SELECT w.name, wr.id as run_id, wr.error_message, wr.started_at
                FROM workflow_runs wr
                JOIN workflows w ON w.id = wr.workflow_id
                WHERE wr.status = 'failed' AND wr.started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY wr.started_at DESC LIMIT 20
            ", [$hours]);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

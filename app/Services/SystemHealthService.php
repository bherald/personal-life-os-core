<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * System Health Service
 *
 * Comprehensive system health monitoring using RAW SQL with parameters.
 * NO Eloquent, NO Query Builder - only prepared statements.
 *
 * Features:
 * - Multi-service health checks (database, Ollama, Redis, queue execution)
 * - Health score calculation (0-100)
 * - System snapshots (raw SQL INSERT)
 * - Health trend analysis
 * - Resource monitoring (disk, memory, queue)
 *
 * Usage:
 * ```php
 * $health = app(SystemHealthService::class);
 * $status = $health->checkHealth();
 * $health->takeSnapshot();
 * ```
 */
class SystemHealthService
{
    /**
     * Health status constants
     */
    private const STATUS_HEALTHY = 'healthy';
    private const STATUS_DEGRADED = 'degraded';
    private const STATUS_UNHEALTHY = 'unhealthy';
    private const STATUS_CRITICAL = 'critical';

    /**
     * Health score thresholds
     */
    private const SCORE_HEALTHY = 80;      // >= 80 = healthy
    private const SCORE_DEGRADED = 60;     // >= 60 = degraded
    private const SCORE_UNHEALTHY = 40;    // >= 40 = unhealthy
    // < 40 = critical

    /**
     * Run complete system health check
     *
     * @return array Health status with all service checks
     */
    public function checkHealth(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'ollama' => $this->checkOllama(),
            'redis' => $this->checkRedis(),
            'queue_execution' => $this->checkQueueExecution(),
            'disk_space' => $this->checkDiskSpace(),
            'workflows' => $this->checkWorkflows(),
            'errors' => $this->checkErrorRate(),
        ];

        $healthScore = $this->calculateHealthScore($checks);
        $healthStatus = $this->determineHealthStatus($healthScore);

        return [
            'health_score' => $healthScore,
            'health_status' => $healthStatus,
            'checks' => $checks,
            'timestamp' => now()->toDateTimeString(),
        ];
    }

    /**
     * Check database health using raw SQL
     *
     * @return array Database health status
     */
    public function checkDatabase(): array
    {
        $startTime = microtime(true);

        try {
            // Test connection with simple query
            $sql = "SELECT 1 as test";
            $result = DB::select($sql);

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Get table stats
            $tableCountSql = "SELECT COUNT(*) as count
                             FROM information_schema.tables
                             WHERE table_schema = ?";
            $tableCount = DB::select($tableCountSql, [config('database.connections.mysql.database')])[0]->count ?? 0;

            return [
                'status' => 'up',
                'healthy' => true,
                'response_time_ms' => $responseTime,
                'table_count' => $tableCount,
                'score' => 100,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'down',
                'healthy' => false,
                'error' => $e->getMessage(),
                'score' => 0,
            ];
        }
    }

    /**
     * Check Ollama service health
     *
     * @return array Ollama health status
     */
    public function checkOllama(): array
    {
        $instances = DB::select("SELECT instance_name, base_url, priority FROM llm_instances WHERE instance_type = 'ollama' AND is_active = 1 ORDER BY priority ASC");

        // Fallback if no DB instances configured
        if (empty($instances)) {
            $instances = [(object) [
                'instance_name' => 'default',
                'base_url' => config('services.ollama.api_url', 'http://127.0.0.1:11434'),
                'priority' => 1,
            ]];
        }

        $results = [];
        $anyHealthy = false;
        $totalScore = 0;

        foreach ($instances as $instance) {
            $startTime = microtime(true);
            $url = rtrim($instance->base_url, '/');

            try {
                $response = Http::connectTimeout(5)->timeout(5)->get("{$url}/api/tags");
                $responseTime = round((microtime(true) - $startTime) * 1000, 2);

                if ($response->successful()) {
                    $data = $response->json();
                    $instanceResult = [
                        'status' => 'up',
                        'healthy' => true,
                        'url' => $url,
                        'priority' => $instance->priority,
                        'response_time_ms' => $responseTime,
                        'models_count' => count($data['models'] ?? []),
                        'score' => 100,
                    ];
                    $anyHealthy = true;
                } else {
                    $instanceResult = [
                        'status' => 'degraded',
                        'healthy' => false,
                        'url' => $url,
                        'priority' => $instance->priority,
                        'response_time_ms' => $responseTime,
                        'http_code' => $response->status(),
                        'score' => 50,
                    ];
                }
            } catch (Exception $e) {
                $instanceResult = [
                    'status' => 'down',
                    'healthy' => false,
                    'url' => $url,
                    'priority' => $instance->priority,
                    'error' => $e->getMessage(),
                    'score' => 0,
                ];
            }

            $results[$instance->instance_name] = $instanceResult;
            $totalScore += $instanceResult['score'];
        }

        $avgScore = (int) round($totalScore / count($instances));

        return [
            'status' => $anyHealthy ? 'up' : 'down',
            'healthy' => $anyHealthy,
            'score' => $avgScore,
            'instances' => $results,
        ];
    }

    /**
     * Check Redis health
     *
     * @return array Redis health status
     */
    public function checkRedis(): array
    {
        try {
            $startTime = microtime(true);
            Redis::ping();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'up',
                'healthy' => true,
                'response_time_ms' => $responseTime,
                'score' => 100,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'down',
                'healthy' => false,
                'error' => $e->getMessage(),
                'score' => 0,
            ];
        }
    }

    /**
     * Check queue execution status
     *
     * @return array Queue execution health status
     */
    public function checkQueueExecution(): array
    {
        try {
            // Horizon is the canonical prod queue owner.
            $pgrep = Process::timeout(5)->run(['pgrep', '-f', 'artisan horizon$']);
            $systemd = Process::timeout(5)->run(['systemctl', 'is-active', 'laravel-horizon.service']);
            $isRunning = trim($pgrep->output()) !== '' || trim($systemd->output()) === 'active';

            if (config('queue.default') === 'redis') {
                $queueNames = array_values(array_unique(array_filter([
                    config('queue.connections.redis.queue', 'default'),
                    'high',
                    'default',
                    'low',
                    'long-running',
                    'workflow',
                    'speculative',
                ])));

                $queueSize = 0;
                foreach ($queueNames as $queueName) {
                    $queueSize += (int) Redis::llen("queues:{$queueName}");
                }
            } else {
                $sql = "SELECT COUNT(*) as count FROM jobs";
                $queueSize = DB::select($sql)[0]->count ?? 0;
            }

            // Use a recent failure window so historical residue does not keep health degraded forever.
            $failedSql = "SELECT COUNT(*) as count FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $failedJobs = DB::select($failedSql)[0]->count ?? 0;

            $score = 100;
            $status = 'running';
            $healthy = true;

            if (!$isRunning) {
                $score = 0;
                $status = 'stopped';
                $healthy = false;
            } elseif ($queueSize > 100) {
                $score = 70; // Queue backing up
                $status = 'degraded';
                $healthy = false;
            } elseif ($failedJobs > 10) {
                $score = 80; // Some failed jobs
                $status = 'degraded';
                $healthy = false;
            }

            return [
                'status' => $status,
                'healthy' => $healthy,
                'queue_size' => $queueSize,
                'failed_jobs' => $failedJobs,
                'score' => $score,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'healthy' => false,
                'error' => $e->getMessage(),
                'score' => 0,
            ];
        }
    }

    public function checkQueueWorker(): array
    {
        return $this->checkQueueExecution();
    }

    /**
     * Check disk space
     *
     * @return array Disk space status
     */
    public function checkDiskSpace(): array
    {
        try {
            $path = storage_path();
            $freeSpace = disk_free_space($path);
            $totalSpace = disk_total_space($path);

            $freeGB = round($freeSpace / 1024 / 1024 / 1024, 2);
            $totalGB = round($totalSpace / 1024 / 1024 / 1024, 2);
            $usedPercent = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2);

            $score = 100;
            if ($freeGB < 5) {
                $score = 20; // Critical
            } elseif ($freeGB < 10) {
                $score = 50; // Low
            } elseif ($freeGB < 20) {
                $score = 80; // Warning
            }

            return [
                'status' => 'ok',
                'healthy' => $freeGB >= 10,
                'free_gb' => $freeGB,
                'total_gb' => $totalGB,
                'used_percent' => $usedPercent,
                'score' => $score,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'healthy' => false,
                'error' => $e->getMessage(),
                'score' => 0,
            ];
        }
    }

    /**
     * Check workflow health using raw SQL
     *
     * @return array Workflow health status
     */
    public function checkWorkflows(): array
    {
        try {
            // Count active workflows
            $activeSql = "SELECT COUNT(*) as count FROM workflows WHERE active = 1";
            $activeCount = DB::select($activeSql)[0]->count ?? 0;

            // Count running workflows
            $runningSql = "SELECT COUNT(*) as count FROM workflow_runs WHERE status = 'running'";
            $runningCount = DB::select($runningSql)[0]->count ?? 0;

            // Count failed workflows in last 24h
            $failedSql = "SELECT COUNT(*) as count
                         FROM workflow_runs
                         WHERE status = 'failed'
                         AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $failedCount = DB::select($failedSql)[0]->count ?? 0;

            // Get average duration of completed workflows in last 24h
            $avgDurationSql = "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration
                              FROM workflow_runs
                              WHERE status = 'completed'
                              AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $avgDuration = DB::select($avgDurationSql)[0]->avg_duration ?? null;

            $score = 100;
            if ($failedCount > 10) {
                $score = 50; // Many failures
            } elseif ($failedCount > 5) {
                $score = 80; // Some failures
            }

            return [
                'status' => 'ok',
                'healthy' => $failedCount < 5,
                'active_workflows' => $activeCount,
                'running_workflows' => $runningCount,
                'failed_24h' => $failedCount,
                'avg_duration_sec' => $avgDuration ? round($avgDuration, 2) : null,
                'score' => $score,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'healthy' => false,
                'error' => $e->getMessage(),
                'score' => 0,
            ];
        }
    }

    /**
     * Check error rate using raw SQL
     *
     * @return array Error rate status
     */
    public function checkErrorRate(): array
    {
        try {
            // Errors in last hour
            $lastHourSql = "SELECT COUNT(*) as count
                           FROM system_errors
                           WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            $lastHourCount = DB::select($lastHourSql)[0]->count ?? 0;

            // Errors in last day
            $lastDaySql = "SELECT COUNT(*) as count
                          FROM system_errors
                          WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $lastDayCount = DB::select($lastDaySql)[0]->count ?? 0;

            // Critical unresolved errors
            $criticalSql = "SELECT COUNT(*) as count
                           FROM system_errors
                           WHERE resolved_at IS NULL
                           AND error_severity = 'critical'";
            $criticalCount = DB::select($criticalSql)[0]->count ?? 0;

            $errorRatePerHour = round($lastDayCount / 24, 2);

            $score = 100;
            if ($criticalCount > 0) {
                $score = 30; // Critical errors exist
            } elseif ($errorRatePerHour > 20) {
                $score = 50; // High error rate
            } elseif ($errorRatePerHour > 10) {
                $score = 80; // Elevated error rate
            }

            return [
                'status' => 'ok',
                'healthy' => $errorRatePerHour < 10 && $criticalCount === 0,
                'errors_last_hour' => $lastHourCount,
                'errors_last_day' => $lastDayCount,
                'error_rate_per_hour' => $errorRatePerHour,
                'critical_unresolved' => $criticalCount,
                'score' => $score,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'healthy' => false,
                'error' => $e->getMessage(),
                'score' => 0,
            ];
        }
    }

    /**
     * Calculate overall health score from individual checks
     *
     * @param array $checks All health check results
     * @return int Health score (0-100)
     */
    public function calculateHealthScore(array $checks): int
    {
        $totalScore = 0;
        $count = 0;

        foreach ($checks as $check) {
            if (isset($check['score'])) {
                $totalScore += $check['score'];
                $count++;
            }
        }

        return $count > 0 ? (int) round($totalScore / $count) : 0;
    }

    /**
     * Determine health status from score
     *
     * @param int $score Health score (0-100)
     * @return string Health status
     */
    private function determineHealthStatus(int $score): string
    {
        return match (true) {
            $score >= self::SCORE_HEALTHY => self::STATUS_HEALTHY,
            $score >= self::SCORE_DEGRADED => self::STATUS_DEGRADED,
            $score >= self::SCORE_UNHEALTHY => self::STATUS_UNHEALTHY,
            default => self::STATUS_CRITICAL,
        };
    }

    /**
     * Take system health snapshot and save to database using raw SQL
     *
     * @return int Snapshot ID
     */
    public function takeSnapshot(): int
    {
        $health = $this->checkHealth();
        $checks = $health['checks'];

        $sql = "INSERT INTO system_health_snapshots (
            health_score,
            health_status,
            services_status,
            errors_last_hour,
            errors_last_day,
            error_rate_per_hour,
            active_workflows,
            running_workflows,
            failed_workflows_24h,
            avg_workflow_duration_ms,
            disk_free_gb,
            memory_usage_mb,
            queue_size,
            queue_worker_status,
            avg_response_time_ms,
            slow_queries_count,
            alerts_generated,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $params = [
            $health['health_score'],
            $health['health_status'],
            json_encode($this->buildServicesStatus($checks)),
            $checks['errors']['errors_last_hour'] ?? 0,
            $checks['errors']['errors_last_day'] ?? 0,
            $checks['errors']['error_rate_per_hour'] ?? 0,
            $checks['workflows']['active_workflows'] ?? 0,
            $checks['workflows']['running_workflows'] ?? 0,
            $checks['workflows']['failed_24h'] ?? 0,
            isset($checks['workflows']['avg_duration_sec']) ? ($checks['workflows']['avg_duration_sec'] * 1000) : null,
            $checks['disk_space']['free_gb'] ?? null,
            $this->getMemoryUsage(),
            $checks['queue_execution']['queue_size'] ?? 0,
            $checks['queue_execution']['status'] ?? 'unknown',
            $this->calculateAvgResponseTime($checks),
            0, // slow_queries_count - placeholder
            json_encode([]), // alerts_generated
        ];

        DB::insert($sql, $params);
        $snapshotId = DB::getPdo()->lastInsertId();

        Log::info('Health snapshot taken', [
            'snapshot_id' => $snapshotId,
            'health_score' => $health['health_score'],
            'health_status' => $health['health_status'],
        ]);

        return (int) $snapshotId;
    }

    /**
     * Get health trend using raw SQL
     *
     * @param string $period Time period to analyze
     * @return string Trend: improving, degrading, stable, unknown
     */
    public function getHealthTrend(string $period = '24 hours'): string
    {
        try {
            $sql = "SELECT health_score, created_at
                    FROM system_health_snapshots
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                    ORDER BY created_at ASC";

            $hours = $this->periodToHours($period);
            $snapshots = DB::select($sql, [$hours]);

            if (count($snapshots) < 2) {
                return 'unknown';
            }

            $first = $snapshots[0]->health_score;
            $last = $snapshots[count($snapshots) - 1]->health_score;
            $diff = $last - $first;

            return match (true) {
                $diff > 10 => 'improving',
                $diff < -10 => 'degrading',
                default => 'stable',
            };
        } catch (Exception $e) {
            Log::debug('SystemHealthService: health trend calculation failed', ['error' => $e->getMessage()]);
            return 'unknown';
        }
    }

    /**
     * Get latest snapshot using raw SQL
     *
     * @return array|null Latest snapshot data
     */
    public function getLatestSnapshot(): ?array
    {
        $sql = "SELECT * FROM system_health_snapshots ORDER BY created_at DESC LIMIT 1";
        $results = DB::select($sql);

        if (empty($results)) {
            return null;
        }

        $snapshot = $results[0];
        return [
            'id' => $snapshot->id,
            'health_score' => $snapshot->health_score,
            'health_status' => $snapshot->health_status,
            'services_status' => json_decode($snapshot->services_status, true),
            'created_at' => $snapshot->created_at,
        ];
    }

    /**
     * Build services status map
     *
     * @param array $checks Health check results
     * @return array Services status map
     */
    private function buildServicesStatus(array $checks): array
    {
        $status = [];

        foreach ($checks as $service => $check) {
            $status[$service] = $check['status'] ?? 'unknown';
        }

        return $status;
    }

    /**
     * Get current memory usage in MB
     *
     * @return int Memory usage in MB
     */
    private function getMemoryUsage(): int
    {
        return (int) round(memory_get_usage(true) / 1024 / 1024);
    }

    /**
     * Calculate average response time from checks
     *
     * @param array $checks Health check results
     * @return int|null Average response time in milliseconds
     */
    private function calculateAvgResponseTime(array $checks): ?int
    {
        $responseTimes = [];

        foreach ($checks as $check) {
            if (isset($check['response_time_ms'])) {
                $responseTimes[] = $check['response_time_ms'];
            }
        }

        if (empty($responseTimes)) {
            return null;
        }

        return (int) round(array_sum($responseTimes) / count($responseTimes));
    }

    /**
     * Convert period string to hours
     *
     * @param string $period Period like '1 hour', '24 hours'
     * @return int Hours
     */
    private function periodToHours(string $period): int
    {
        if (str_contains($period, 'hour')) {
            return (int) filter_var($period, FILTER_SANITIZE_NUMBER_INT);
        }

        if (str_contains($period, 'day')) {
            return ((int) filter_var($period, FILTER_SANITIZE_NUMBER_INT)) * 24;
        }

        return 24;
    }

    /**
     * Get unhealthy snapshots using raw SQL
     *
     * @param int $limit Number of results to return
     * @return array Unhealthy snapshots
     */
    public function getUnhealthySnapshots(int $limit = 100): array
    {
        $sql = "SELECT * FROM system_health_snapshots
                WHERE health_status IN ('degraded', 'unhealthy', 'critical')
                ORDER BY created_at DESC
                LIMIT ?";

        return DB::select($sql, [$limit]);
    }

    /**
     * Get snapshots by status using raw SQL
     *
     * @param string $status Health status
     * @param int $limit Number of results to return
     * @return array Snapshots with specified status
     */
    public function getSnapshotsByStatus(string $status, int $limit = 100): array
    {
        $sql = "SELECT * FROM system_health_snapshots
                WHERE health_status = ?
                ORDER BY created_at DESC
                LIMIT ?";

        return DB::select($sql, [$status, $limit]);
    }

    /**
     * Get snapshots below health score threshold using raw SQL
     *
     * @param int $threshold Health score threshold
     * @param int $limit Number of results to return
     * @return array Snapshots below threshold
     */
    public function getSnapshotsBelowScore(int $threshold, int $limit = 100): array
    {
        $sql = "SELECT * FROM system_health_snapshots
                WHERE health_score < ?
                ORDER BY created_at DESC
                LIMIT ?";

        return DB::select($sql, [$threshold, $limit]);
    }

    /**
     * Get recent snapshots using raw SQL
     *
     * @param string $period Time period (e.g., '1 hour', '24 hours')
     * @param int $limit Number of results to return
     * @return array Recent snapshots
     */
    public function getRecentSnapshots(string $period = '24 hours', int $limit = 100): array
    {
        $hours = $this->periodToHours($period);

        $sql = "SELECT * FROM system_health_snapshots
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY created_at DESC
                LIMIT ?";

        return DB::select($sql, [$hours, $limit]);
    }
}

<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Error Tracking Service
 *
 * Centralized error logging and analysis service using RAW SQL with parameters.
 * NO Eloquent, NO Query Builder - only prepared statements for maximum performance and security.
 *
 * Features:
 * - Centralized error logging via raw SQL
 * - Parameterized queries (SQL injection safe)
 * - Context enrichment
 * - Error rate calculation
 * - Anomaly detection (error spikes)
 * - Pattern analysis
 *
 * Usage:
 * ```php
 * $errorTracking = app(ErrorTrackingService::class);
 * $errorId = $errorTracking->logError($exception, 'api', 'user-login');
 * ```
 */
class ErrorTrackingService
{
    /**
     * Error rate thresholds
     */
    private const ERROR_RATE_THRESHOLD = 10; // Fallback — config/health_thresholds.php is primary (SC-2.5)
    private const SPIKE_MULTIPLIER = 3;

    /**
     * Severity constants
     */
    private const SEVERITY_DEBUG = 'debug';
    private const SEVERITY_INFO = 'info';
    private const SEVERITY_WARNING = 'warning';
    private const SEVERITY_ERROR = 'error';
    private const SEVERITY_CRITICAL = 'critical';

    /**
     * Source type constants
     */
    private const SOURCE_WORKFLOW = 'workflow';
    private const SOURCE_JOB = 'job';
    private const SOURCE_COMMAND = 'command';
    private const SOURCE_API = 'api';

    /**
     * Log an error to centralized tracking using raw SQL
     *
     * @param Throwable $exception The exception/error that occurred
     * @param string $sourceType Source type: workflow, job, command, api
     * @param string|null $sourceId Specific identifier for the source
     * @param array $context Additional context data
     * @param string $severity Severity level
     * @return int The error ID
     */
    public function logError(
        Throwable $exception,
        string $sourceType,
        ?string $sourceId = null,
        array $context = [],
        string $severity = self::SEVERITY_ERROR
    ): int {
        try {
            $sql = "INSERT INTO system_errors (
                error_code,
                error_type,
                error_message,
                error_severity,
                context,
                stack_trace,
                source_type,
                source_id,
                workflow_id,
                workflow_run_id,
                node_id,
                node_type,
                occurred_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $params = [
                $this->generateErrorCode($exception),
                get_class($exception),
                $exception->getMessage(),
                $severity,
                $this->safeJsonEncode($context),
                $exception->getTraceAsString(),
                $sourceType,
                $sourceId,
                $context['workflow_id'] ?? null,
                $context['workflow_run_id'] ?? null,
                $context['node_id'] ?? null,
                $context['node_type'] ?? null,
                now()->toDateTimeString(),
            ];

            DB::insert($sql, $params);
            $errorId = DB::getPdo()->lastInsertId();

            Log::error('Error tracked', [
                'error_id' => $errorId,
                'error_type' => get_class($exception),
                'source' => $sourceType,
                'severity' => $severity,
            ]);

            return (int) $errorId;
        } catch (Exception $e) {
            // Fallback logging if error tracking fails
            Log::critical('Error tracking failed', [
                'original_error' => $exception->getMessage(),
                'tracking_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Log workflow-specific error using raw SQL
     *
     * @param Throwable $exception The exception that occurred
     * @param int $workflowId Workflow ID
     * @param int|null $workflowRunId Workflow run ID (optional)
     * @param int|null $nodeId Node ID if error occurred in specific node
     * @param string|null $nodeType Node class name
     * @return int The error ID
     */
    public function logWorkflowError(
        Throwable $exception,
        int $workflowId,
        ?int $workflowRunId = null,
        ?int $nodeId = null,
        ?string $nodeType = null
    ): int {
        return $this->logError(
            exception: $exception,
            sourceType: self::SOURCE_WORKFLOW,
            sourceId: (string) $workflowId,
            context: [
                'workflow_id' => $workflowId,
                'workflow_run_id' => $workflowRunId,
                'node_id' => $nodeId,
                'node_type' => $nodeType,
            ],
            severity: $this->determineWorkflowErrorSeverity($exception)
        );
    }

    /**
     * Mark error as resolved using raw SQL
     *
     * @param int $errorId Error ID
     * @param string|null $resolution Optional resolution description
     * @return bool Success
     */
    public function resolveError(int $errorId, ?string $resolution = null): bool
    {
        $sql = "UPDATE system_errors
                SET resolved_at = NOW(),
                    duration_ms = TIMESTAMPDIFF(MICROSECOND, occurred_at, NOW()) / 1000,
                    context = JSON_SET(COALESCE(context, '{}'), '$.resolution', ?),
                    updated_at = NOW()
                WHERE id = ? AND resolved_at IS NULL";

        $params = [
            $resolution ?? 'Resolved',
            $errorId,
        ];

        $affected = DB::update($sql, $params);

        if ($affected > 0) {
            Log::info('Error resolved', ['error_id' => $errorId]);
            return true;
        }

        return false;
    }

    /**
     * Get error rate for time period using raw SQL
     *
     * @param string $period Time period (e.g., '1 hour', '24 hours')
     * @param string|null $errorType Optional filter by error type
     * @return float Errors per hour
     */
    public function getErrorRate(string $period = '1 hour', ?string $errorType = null): float
    {
        $hours = $this->periodToHours($period);
        $since = now()->sub($period)->toDateTimeString();

        if ($errorType) {
            $sql = "SELECT COUNT(*) as count
                    FROM system_errors
                    WHERE occurred_at >= ? AND error_type = ?";
            $params = [$since, $errorType];
        } else {
            $sql = "SELECT COUNT(*) as count
                    FROM system_errors
                    WHERE occurred_at >= ?";
            $params = [$since];
        }

        $result = DB::select($sql, $params);
        $count = $result[0]->count ?? 0;

        return $hours > 0 ? round($count / $hours, 2) : 0;
    }

    /**
     * Detect error spike using raw SQL
     *
     * @return bool True if error spike detected
     */
    public function detectErrorSpike(): bool
    {
        $currentRate = $this->getErrorRate('1 hour');
        $baselineRate = $this->getErrorRate('24 hours');

        // Spike = current rate is 3x baseline
        if ($baselineRate > 0 && $currentRate >= ($baselineRate * config('health_thresholds.errors.spike_multiplier', self::SPIKE_MULTIPLIER))) {
            Log::warning('Error spike detected', [
                'current_rate' => $currentRate,
                'baseline_rate' => $baselineRate,
                'multiplier' => $currentRate / $baselineRate,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get most common errors using raw SQL
     *
     * @param int $limit Number of errors to return
     * @param string $period Time period
     * @return array Array of error types with counts
     */
    public function getTopErrors(int $limit = 10, string $period = '24 hours'): array
    {
        $since = now()->sub($period)->toDateTimeString();

        $sql = "SELECT error_type, COUNT(*) as count
                FROM system_errors
                WHERE occurred_at >= ?
                GROUP BY error_type
                ORDER BY count DESC
                LIMIT ?";

        $params = [$since, $limit];

        $results = DB::select($sql, $params);

        return array_map(fn($row) => [
            'error_type' => $row->error_type,
            'count' => $row->count,
        ], $results);
    }

    /**
     * Analyze error patterns using raw SQL
     *
     * @return array Pattern analysis results
     */
    public function analyzeErrorPatterns(): array
    {
        $period = '24 hours';
        $since = now()->sub($period)->toDateTimeString();

        // Total errors
        $totalSql = "SELECT COUNT(*) as count FROM system_errors WHERE occurred_at >= ?";
        $total = DB::select($totalSql, [$since])[0]->count ?? 0;

        // Unresolved errors
        $unresolvedSql = "SELECT COUNT(*) as count FROM system_errors WHERE resolved_at IS NULL";
        $unresolved = DB::select($unresolvedSql)[0]->count ?? 0;

        // Critical unresolved errors
        $criticalSql = "SELECT COUNT(*) as count FROM system_errors
                       WHERE resolved_at IS NULL AND error_severity = ?";
        $critical = DB::select($criticalSql, [self::SEVERITY_CRITICAL])[0]->count ?? 0;

        return [
            'total_errors' => $total,
            'error_rate' => $this->getErrorRate($period),
            'spike_detected' => $this->detectErrorSpike(),
            'top_errors' => $this->getTopErrors(5, $period),
            'severity_distribution' => $this->getSeverityDistribution($period),
            'source_distribution' => $this->getSourceDistribution($period),
            'unresolved_count' => $unresolved,
            'critical_count' => $critical,
        ];
    }

    /**
     * Get severity distribution using raw SQL
     *
     * @param string $period Time period
     * @return array Severity counts
     */
    private function getSeverityDistribution(string $period): array
    {
        $since = now()->sub($period)->toDateTimeString();

        $sql = "SELECT error_severity, COUNT(*) as count
                FROM system_errors
                WHERE occurred_at >= ?
                GROUP BY error_severity";

        $results = DB::select($sql, [$since]);

        $distribution = [];
        foreach ($results as $row) {
            $distribution[$row->error_severity] = $row->count;
        }

        return $distribution;
    }

    /**
     * Get source type distribution using raw SQL
     *
     * @param string $period Time period
     * @return array Source type counts
     */
    private function getSourceDistribution(string $period): array
    {
        $since = now()->sub($period)->toDateTimeString();

        $sql = "SELECT source_type, COUNT(*) as count
                FROM system_errors
                WHERE occurred_at >= ?
                GROUP BY source_type";

        $results = DB::select($sql, [$since]);

        $distribution = [];
        foreach ($results as $row) {
            $distribution[$row->source_type] = $row->count;
        }

        return $distribution;
    }

    /**
     * Record recovery attempt using raw SQL
     *
     * @param int $errorId Error ID
     * @param bool $successful Was recovery successful?
     * @param string $method Recovery method (retry, fallback, circuit_breaker)
     * @return bool Success
     */
    public function recordRecoveryAttempt(int $errorId, bool $successful, string $method): bool
    {
        $sql = "UPDATE system_errors
                SET recovery_attempted = 1,
                    recovery_successful = ?,
                    recovery_method = ?,
                    updated_at = NOW()
                WHERE id = ?";

        $params = [$successful ? 1 : 0, $method, $errorId];

        return DB::update($sql, $params) > 0;
    }

    /**
     * Get unresolved errors using raw SQL
     *
     * @param int $limit Maximum number of errors to return
     * @return array Array of error records
     */
    public function getUnresolvedErrors(int $limit = 100): array
    {
        $sql = "SELECT id, error_code, error_type, error_message, error_severity,
                       source_type, source_id, occurred_at
                FROM system_errors
                WHERE resolved_at IS NULL
                ORDER BY occurred_at DESC
                LIMIT ?";

        return DB::select($sql, [$limit]);
    }

    /**
     * Get errors by workflow using raw SQL
     *
     * @param int $workflowId Workflow ID
     * @param int $limit Maximum number of errors to return
     * @return array Array of error records
     */
    public function getWorkflowErrors(int $workflowId, int $limit = 100): array
    {
        $sql = "SELECT id, error_code, error_type, error_message, error_severity,
                       workflow_run_id, node_id, node_type, occurred_at, resolved_at
                FROM system_errors
                WHERE workflow_id = ?
                ORDER BY occurred_at DESC
                LIMIT ?";

        return DB::select($sql, [$workflowId, $limit]);
    }

    /**
     * Generate error code from exception
     *
     * @param Throwable $exception
     * @return string Error code (e.g., E001, E002)
     */
    private function generateErrorCode(Throwable $exception): string
    {
        $code = $exception->getCode();

        if ($code > 0 && $code < 1000) {
            return 'E' . str_pad((string) $code, 3, '0', STR_PAD_LEFT);
        }

        // Generate hash-based code
        $hash = crc32(get_class($exception));
        return 'E' . str_pad((string) ($hash % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Determine severity for workflow errors
     *
     * @param Throwable $exception
     * @return string Severity level
     */
    private function determineWorkflowErrorSeverity(Throwable $exception): string
    {
        $critical = [
            'DatabaseException',
            'FatalErrorException',
            'OutOfMemoryError',
        ];

        $warning = [
            'TimeoutException',
            'NetworkException',
        ];

        $exceptionClass = class_basename($exception);

        if (in_array($exceptionClass, $critical)) {
            return self::SEVERITY_CRITICAL;
        }

        if (in_array($exceptionClass, $warning)) {
            return self::SEVERITY_WARNING;
        }

        return self::SEVERITY_ERROR;
    }

    /**
     * Convert period string to hours
     *
     * @param string $period Period like '1 hour', '24 hours', '7 days'
     * @return float Hours
     */
    private function periodToHours(string $period): float
    {
        if (str_contains($period, 'hour')) {
            return (float) filter_var($period, FILTER_SANITIZE_NUMBER_INT);
        }

        if (str_contains($period, 'day')) {
            return ((float) filter_var($period, FILTER_SANITIZE_NUMBER_INT)) * 24;
        }

        if (str_contains($period, 'week')) {
            return ((float) filter_var($period, FILTER_SANITIZE_NUMBER_INT)) * 168;
        }

        return 1.0;
    }

    /**
     * Get resolved errors using raw SQL
     *
     * @param int $limit Maximum number of errors to return
     * @return array Array of error records
     */
    public function getResolvedErrors(int $limit = 100): array
    {
        $sql = "SELECT id, error_code, error_type, error_message, error_severity,
                       source_type, source_id, occurred_at, resolved_at, duration_ms
                FROM system_errors
                WHERE resolved_at IS NOT NULL
                ORDER BY resolved_at DESC
                LIMIT ?";

        return DB::select($sql, [$limit]);
    }

    /**
     * Get errors by severity using raw SQL
     *
     * @param string $severity Error severity level
     * @param int $limit Maximum number of errors to return
     * @return array Array of error records
     */
    public function getErrorsBySeverity(string $severity, int $limit = 100): array
    {
        $sql = "SELECT id, error_code, error_type, error_message, error_severity,
                       source_type, source_id, occurred_at, resolved_at
                FROM system_errors
                WHERE error_severity = ?
                ORDER BY occurred_at DESC
                LIMIT ?";

        return DB::select($sql, [$severity, $limit]);
    }

    /**
     * Get critical errors using raw SQL
     *
     * @param int $limit Maximum number of errors to return
     * @return array Array of critical error records
     */
    public function getCriticalErrors(int $limit = 100): array
    {
        return $this->getErrorsBySeverity(self::SEVERITY_CRITICAL, $limit);
    }

    /**
     * Get errors by source using raw SQL
     *
     * @param string $sourceType Source type (workflow, job, command, api)
     * @param string|null $sourceId Optional source ID
     * @param int $limit Maximum number of errors to return
     * @return array Array of error records
     */
    public function getErrorsBySource(string $sourceType, ?string $sourceId = null, int $limit = 100): array
    {
        if ($sourceId !== null) {
            $sql = "SELECT id, error_code, error_type, error_message, error_severity,
                           source_type, source_id, occurred_at, resolved_at
                    FROM system_errors
                    WHERE source_type = ? AND source_id = ?
                    ORDER BY occurred_at DESC
                    LIMIT ?";
            return DB::select($sql, [$sourceType, $sourceId, $limit]);
        } else {
            $sql = "SELECT id, error_code, error_type, error_message, error_severity,
                           source_type, source_id, occurred_at, resolved_at
                    FROM system_errors
                    WHERE source_type = ?
                    ORDER BY occurred_at DESC
                    LIMIT ?";
            return DB::select($sql, [$sourceType, $limit]);
        }
    }

    /**
     * Get recent errors using raw SQL
     *
     * @param string $period Time period (e.g., '1 hour', '24 hours')
     * @param int $limit Maximum number of errors to return
     * @return array Array of error records
     */
    public function getRecentErrors(string $period = '24 hours', int $limit = 100): array
    {
        $hours = $this->periodToHours($period);
        $sql = "SELECT id, error_code, error_type, error_message, error_severity,
                       source_type, source_id, occurred_at, resolved_at
                FROM system_errors
                WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY occurred_at DESC
                LIMIT ?";

        return DB::select($sql, [$hours, $limit]);
    }

    /**
     * Mark error as resolved (alias for resolveError for consistency)
     *
     * @param int $errorId Error ID
     * @param string|null $resolution Optional resolution description
     * @return bool Success
     */
    public function markErrorResolved(int $errorId, ?string $resolution = null): bool
    {
        return $this->resolveError($errorId, $resolution);
    }

    /**
     * Record error recovery attempt (alias for recordRecoveryAttempt for consistency)
     *
     * @param int $errorId Error ID
     * @param bool $successful Was recovery successful?
     * @param string $method Recovery method (retry, fallback, circuit_breaker)
     * @return bool Success
     */
    public function recordErrorRecoveryAttempt(int $errorId, bool $successful, string $method): bool
    {
        return $this->recordRecoveryAttempt($errorId, $successful, $method);
    }

    /**
     * Safely encode context to JSON, handling non-serializable values.
     */
    private function safeJsonEncode(array $context): string
    {
        $encoded = json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return json_encode(['_encoding_error' => json_last_error_msg()]);
        }
        return $encoded;
    }
}

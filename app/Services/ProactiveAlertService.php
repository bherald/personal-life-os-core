<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Proactive Alert Service
 *
 * Monitors system health and generates intelligent alerts using RAW SQL with parameters.
 * NO Eloquent, NO Query Builder - only prepared statements for maximum performance and security.
 *
 * Features:
 * - Error rate monitoring and spike detection
 * - Workflow failure alerts
 * - Service health alerts
 * - Alert deduplication with fingerprinting
 * - Cooldown periods to prevent alert flooding
 * - Multi-channel notification support
 * - Alert lifecycle management (acknowledge, resolve)
 *
 * Usage:
 * ```php
 * $alerts = app(ProactiveAlertService::class);
 * $alerts->checkErrorRates();
 * $alerts->checkWorkflowHealth();
 * ```
 */
class ProactiveAlertService
{
    /**
     * Alert type constants
     */
    private const ALERT_ERROR_SPIKE = 'error_spike';
    private const ALERT_HIGH_ERROR_RATE = 'high_error_rate';
    private const ALERT_WORKFLOW_FAILURE = 'workflow_failure';
    private const ALERT_SERVICE_DOWN = 'service_down';
    private const ALERT_LOW_HEALTH_SCORE = 'low_health_score';
    private const ALERT_DISK_SPACE_LOW = 'disk_space_low';
    private const ALERT_CONSECUTIVE_FAILURES = 'consecutive_failures';

    /**
     * Severity constants
     */
    private const SEVERITY_INFO = 'info';
    private const SEVERITY_WARNING = 'warning';
    private const SEVERITY_ERROR = 'error';
    private const SEVERITY_CRITICAL = 'critical';

    /**
     * Alert thresholds
     */
    private const ERROR_RATE_WARNING = 5;   // errors per hour
    private const ERROR_RATE_CRITICAL = 10; // errors per hour
    private const SPIKE_MULTIPLIER = 3;     // 3x baseline = spike
    private const HEALTH_SCORE_WARNING = 70;
    private const HEALTH_SCORE_CRITICAL = 50;
    private const COOLDOWN_MINUTES = 15;    // Alert cooldown period

    private ErrorTrackingService $errorTracking;
    private SystemHealthService $healthService;
    private WorkflowDiagnosticsService $diagnostics;

    public function __construct(
        ErrorTrackingService $errorTracking,
        SystemHealthService $healthService,
        WorkflowDiagnosticsService $diagnostics
    ) {
        $this->errorTracking = $errorTracking;
        $this->healthService = $healthService;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Generate alert using raw SQL
     *
     * @param string $alertType Alert type
     * @param string $severity Severity level
     * @param string $title Alert title
     * @param string $message Alert message
     * @param array $context Additional context
     * @param int|null $triggerValue Value that triggered alert
     * @param int|null $thresholdValue Threshold that was exceeded
     * @return int|null Alert ID, or null if deduplicated
     */
    public function generateAlert(
        string $alertType,
        string $severity,
        string $title,
        string $message,
        array $context = [],
        ?int $triggerValue = null,
        ?int $thresholdValue = null
    ): ?int {
        try {
            // Generate fingerprint for deduplication
            $fingerprint = $this->generateFingerprint($alertType, $context);

            // Check if alert is in cooldown
            if ($this->isInCooldown($fingerprint)) {
                // Update occurrence count
                $this->incrementOccurrenceCount($fingerprint);
                Log::debug('Alert deduplicated (in cooldown)', ['fingerprint' => $fingerprint]);
                return null;
            }

            // Create new alert
            $sql = "INSERT INTO system_alerts (
                alert_type,
                severity,
                title,
                message,
                context,
                source_type,
                source_id,
                workflow_id,
                error_id,
                trigger_value,
                threshold_value,
                metric_name,
                triggered_at,
                fingerprint,
                cooldown_until,
                occurrence_count,
                last_occurrence_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 1, NOW(), NOW(), NOW())";

            $cooldownUntil = now()->addMinutes(self::COOLDOWN_MINUTES)->toDateTimeString();

            $params = [
                $alertType,
                $severity,
                $title,
                $message,
                $this->safeJsonEncode($context),
                $context['source_type'] ?? null,
                $context['source_id'] ?? null,
                $context['workflow_id'] ?? null,
                $context['error_id'] ?? null,
                $triggerValue,
                $thresholdValue,
                $context['metric_name'] ?? null,
                $fingerprint,
                $cooldownUntil,
            ];

            DB::insert($sql, $params);
            $alertId = (int) DB::getPdo()->lastInsertId();

            Log::warning('Alert generated', [
                'alert_id' => $alertId,
                'type' => $alertType,
                'severity' => $severity,
                'title' => $title,
            ]);

            return $alertId;
        } catch (Exception $e) {
            Log::error('Alert generation failed', [
                'error' => $e->getMessage(),
                'alert_type' => $alertType,
            ]);

            return null;
        }
    }

    /**
     * Check error rates and generate alerts using raw SQL
     *
     * @return array Generated alerts
     */
    public function checkErrorRates(): array
    {
        $alerts = [];

        try {
            // Get current error rate
            $currentRate = $this->errorTracking->getErrorRate('1 hour');
            $baselineRate = $this->errorTracking->getErrorRate('24 hours');

            // Check for error spike (3x baseline)
            if ($baselineRate > 0 && $currentRate >= ($baselineRate * self::SPIKE_MULTIPLIER)) {
                $alertId = $this->generateAlert(
                    alertType: self::ALERT_ERROR_SPIKE,
                    severity: self::SEVERITY_CRITICAL,
                    title: 'Error Spike Detected',
                    message: sprintf(
                        'Current error rate (%.2f/hour) is %.1fx the baseline rate (%.2f/hour)',
                        $currentRate,
                        $currentRate / $baselineRate,
                        $baselineRate
                    ),
                    context: [
                        'source_type' => 'system',
                        'metric_name' => 'error_rate',
                    ],
                    triggerValue: (int) round($currentRate),
                    thresholdValue: (int) round($baselineRate * self::SPIKE_MULTIPLIER)
                );

                if ($alertId) {
                    $alerts[] = $alertId;
                }
            }

            // Check for high error rate
            if ($currentRate >= self::ERROR_RATE_CRITICAL) {
                $alertId = $this->generateAlert(
                    alertType: self::ALERT_HIGH_ERROR_RATE,
                    severity: self::SEVERITY_CRITICAL,
                    title: 'Critical Error Rate',
                    message: sprintf('Error rate (%.2f/hour) exceeds critical threshold (%d/hour)', $currentRate, self::ERROR_RATE_CRITICAL),
                    context: [
                        'source_type' => 'system',
                        'metric_name' => 'error_rate',
                    ],
                    triggerValue: (int) round($currentRate),
                    thresholdValue: self::ERROR_RATE_CRITICAL
                );

                if ($alertId) {
                    $alerts[] = $alertId;
                }
            } elseif ($currentRate >= self::ERROR_RATE_WARNING) {
                $alertId = $this->generateAlert(
                    alertType: self::ALERT_HIGH_ERROR_RATE,
                    severity: self::SEVERITY_WARNING,
                    title: 'Elevated Error Rate',
                    message: sprintf('Error rate (%.2f/hour) exceeds warning threshold (%d/hour)', $currentRate, self::ERROR_RATE_WARNING),
                    context: [
                        'source_type' => 'system',
                        'metric_name' => 'error_rate',
                    ],
                    triggerValue: (int) round($currentRate),
                    thresholdValue: self::ERROR_RATE_WARNING
                );

                if ($alertId) {
                    $alerts[] = $alertId;
                }
            }
        } catch (Exception $e) {
            Log::error('Error rate check failed', ['error' => $e->getMessage()]);
        }

        return $alerts;
    }

    /**
     * Check workflow health and generate alerts using raw SQL
     *
     * @return array Generated alerts
     */
    public function checkWorkflowHealth(): array
    {
        $alerts = [];

        try {
            // Get failing workflows
            $failingWorkflows = $this->diagnostics->getFailingWorkflows('failing');

            foreach ($failingWorkflows as $workflow) {
                // Alert for consecutive failures
                if ($workflow->consecutive_failures >= 5) {
                    $alertId = $this->generateAlert(
                        alertType: self::ALERT_CONSECUTIVE_FAILURES,
                        severity: self::SEVERITY_CRITICAL,
                        title: "Workflow #{$workflow->workflow_id} Consecutive Failures",
                        message: sprintf(
                            'Workflow has failed %d consecutive times. Success rate: %.2f%%',
                            $workflow->consecutive_failures,
                            $workflow->success_rate
                        ),
                        context: [
                            'source_type' => 'workflow',
                            'source_id' => (string) $workflow->workflow_id,
                            'workflow_id' => $workflow->workflow_id,
                            'metric_name' => 'consecutive_failures',
                        ],
                        triggerValue: $workflow->consecutive_failures,
                        thresholdValue: 5
                    );

                    if ($alertId) {
                        $alerts[] = $alertId;
                    }
                }

                // Alert for low success rate (skip if too few runs — likely new workflow)
                if ($workflow->success_rate < 50 && ($workflow->total_runs ?? 0) >= 3) {
                    $alertId = $this->generateAlert(
                        alertType: self::ALERT_WORKFLOW_FAILURE,
                        severity: self::SEVERITY_CRITICAL,
                        title: "Workflow #{$workflow->workflow_id} Critical Failure Rate",
                        message: sprintf(
                            'Workflow success rate (%.2f%%) is critically low. Failed runs: %d',
                            $workflow->success_rate,
                            $workflow->failed_runs
                        ),
                        context: [
                            'source_type' => 'workflow',
                            'source_id' => (string) $workflow->workflow_id,
                            'workflow_id' => $workflow->workflow_id,
                            'metric_name' => 'success_rate',
                            'most_common_error' => $workflow->most_common_error,
                        ],
                        triggerValue: (int) round($workflow->success_rate),
                        thresholdValue: 50
                    );

                    if ($alertId) {
                        $alerts[] = $alertId;
                    }
                }
            }
        } catch (Exception $e) {
            Log::error('Workflow health check failed', ['error' => $e->getMessage()]);
        }

        return $alerts;
    }

    /**
     * Check system health and generate alerts using raw SQL
     *
     * @return array Generated alerts
     */
    public function checkSystemHealth(): array
    {
        $alerts = [];

        try {
            $health = $this->healthService->checkHealth();

            // Check health score
            if ($health['health_score'] <= self::HEALTH_SCORE_CRITICAL) {
                $alertId = $this->generateAlert(
                    alertType: self::ALERT_LOW_HEALTH_SCORE,
                    severity: self::SEVERITY_CRITICAL,
                    title: 'Critical System Health Score',
                    message: sprintf('System health score (%d/100) is critically low', $health['health_score']),
                    context: [
                        'source_type' => 'system',
                        'metric_name' => 'health_score',
                        'failed_checks' => $health['failed_checks'],
                    ],
                    triggerValue: $health['health_score'],
                    thresholdValue: self::HEALTH_SCORE_CRITICAL
                );

                if ($alertId) {
                    $alerts[] = $alertId;
                }
            } elseif ($health['health_score'] <= self::HEALTH_SCORE_WARNING) {
                $alertId = $this->generateAlert(
                    alertType: self::ALERT_LOW_HEALTH_SCORE,
                    severity: self::SEVERITY_WARNING,
                    title: 'Low System Health Score',
                    message: sprintf('System health score (%d/100) is below normal', $health['health_score']),
                    context: [
                        'source_type' => 'system',
                        'metric_name' => 'health_score',
                        'failed_checks' => $health['failed_checks'],
                    ],
                    triggerValue: $health['health_score'],
                    thresholdValue: self::HEALTH_SCORE_WARNING
                );

                if ($alertId) {
                    $alerts[] = $alertId;
                }
            }

            // Check for service failures
            foreach ($health['checks'] as $serviceName => $check) {
                if (!$check['healthy']) {
                    $alertId = $this->generateAlert(
                        alertType: self::ALERT_SERVICE_DOWN,
                        severity: self::SEVERITY_CRITICAL,
                        title: "Service Down: {$serviceName}",
                        message: sprintf('Service %s is not responding: %s', $serviceName, $check['error'] ?? 'Unknown error'),
                        context: [
                            'source_type' => 'service',
                            'source_id' => $serviceName,
                            'service_name' => $serviceName,
                            'error_details' => $check['error'] ?? null,
                        ],
                        triggerValue: 0,
                        thresholdValue: 1
                    );

                    if ($alertId) {
                        $alerts[] = $alertId;
                    }
                }
            }

            // Check disk space
            if (isset($health['checks']['disk_space']['disk_free_gb']) && $health['checks']['disk_space']['disk_free_gb'] < 10) {
                $alertId = $this->generateAlert(
                    alertType: self::ALERT_DISK_SPACE_LOW,
                    severity: self::SEVERITY_WARNING,
                    title: 'Low Disk Space',
                    message: sprintf('Disk space is running low: %.2f GB remaining', $health['checks']['disk_space']['disk_free_gb']),
                    context: [
                        'source_type' => 'system',
                        'metric_name' => 'disk_space',
                    ],
                    triggerValue: (int) round($health['checks']['disk_space']['disk_free_gb']),
                    thresholdValue: 10
                );

                if ($alertId) {
                    $alerts[] = $alertId;
                }
            }
        } catch (Exception $e) {
            Log::error('System health check failed', ['error' => $e->getMessage()]);
        }

        return $alerts;
    }

    /**
     * Run all health checks and generate alerts using raw SQL
     *
     * @return array Summary of generated alerts
     */
    public function runAllChecks(): array
    {
        // Auto-resolve alerts whose conditions have cleared BEFORE generating new ones
        $autoResolved = $this->autoResolveRecoveredAlerts();

        $errorRateAlerts = $this->checkErrorRates();
        $workflowAlerts = $this->checkWorkflowHealth();
        $systemAlerts = $this->checkSystemHealth();

        $allAlerts = array_merge($errorRateAlerts, $workflowAlerts, $systemAlerts);

        return [
            'total_alerts' => count($allAlerts),
            'error_rate_alerts' => count($errorRateAlerts),
            'workflow_alerts' => count($workflowAlerts),
            'system_alerts' => count($systemAlerts),
            'auto_resolved' => $autoResolved,
            'alert_ids' => $allAlerts,
        ];
    }

    /**
     * Auto-resolve alerts whose triggering conditions have cleared.
     *
     * Checks each unresolved alert type against current system state.
     * If the condition that triggered the alert is no longer true, the
     * alert is resolved automatically with a note.
     *
     * @return int Number of alerts auto-resolved
     */
    public function autoResolveRecoveredAlerts(): int
    {
        $resolved = 0;

        try {
            $sql = "SELECT id, alert_type, context, workflow_id
                    FROM system_alerts
                    WHERE resolved_at IS NULL
                    ORDER BY triggered_at ASC";
            $activeAlerts = DB::select($sql);

            if (empty($activeAlerts)) {
                return 0;
            }

            // Gather current state once for efficiency
            $currentErrorRate = null;
            $currentHealth = null;
            $failingWorkflowIds = null;

            foreach ($activeAlerts as $alert) {
                $shouldResolve = false;
                $reason = '';

                switch ($alert->alert_type) {
                    case self::ALERT_ERROR_SPIKE:
                    case self::ALERT_HIGH_ERROR_RATE:
                        if ($currentErrorRate === null) {
                            $currentErrorRate = $this->errorTracking->getErrorRate('1 hour');
                        }
                        // Resolve if error rate dropped below warning threshold
                        if ($currentErrorRate < self::ERROR_RATE_WARNING) {
                            $shouldResolve = true;
                            $reason = sprintf('Error rate recovered to %.2f/hour (below %d threshold)', $currentErrorRate, self::ERROR_RATE_WARNING);
                        }
                        break;

                    case self::ALERT_WORKFLOW_FAILURE:
                    case self::ALERT_CONSECUTIVE_FAILURES:
                        if ($failingWorkflowIds === null) {
                            $failingWorkflowIds = [];
                            try {
                                $failing = $this->diagnostics->getFailingWorkflows('failing');
                                foreach ($failing as $wf) {
                                    $failingWorkflowIds[] = (int) $wf->workflow_id;
                                }
                            } catch (Exception $e) {
                                Log::debug('AutoResolve: Cannot check workflow state, skipping alert', [
                                    'alert_id' => $alert->id,
                                    'error' => $e->getMessage(),
                                ]);
                                continue 2; // Skip to next alert
                            }
                        }
                        $workflowId = (int) ($alert->workflow_id ?? 0);
                        if ($workflowId > 0 && !in_array($workflowId, $failingWorkflowIds)) {
                            $shouldResolve = true;
                            $reason = "Workflow #{$workflowId} no longer in failing state";
                        }
                        break;

                    case self::ALERT_SERVICE_DOWN:
                        if ($currentHealth === null) {
                            try {
                                $currentHealth = $this->healthService->checkHealth();
                            } catch (Exception $e) {
                                Log::debug('AutoResolve: Health check failed, skipping alert', [
                                    'alert_id' => $alert->id,
                                    'error' => $e->getMessage(),
                                ]);
                                continue 2;
                            }
                        }
                        $context = json_decode($alert->context ?? '{}', true);
                        $serviceName = $context['service_name'] ?? $context['source_id'] ?? null;
                        if ($serviceName && isset($currentHealth['checks'][$serviceName]) && $currentHealth['checks'][$serviceName]['healthy']) {
                            $shouldResolve = true;
                            $reason = "Service '{$serviceName}' is healthy again";
                        }
                        break;

                    case self::ALERT_LOW_HEALTH_SCORE:
                        if ($currentHealth === null) {
                            try {
                                $currentHealth = $this->healthService->checkHealth();
                            } catch (Exception $e) {
                                Log::debug('AutoResolve: Health check failed, skipping alert', [
                                    'alert_id' => $alert->id,
                                    'error' => $e->getMessage(),
                                ]);
                                continue 2;
                            }
                        }
                        if ($currentHealth['health_score'] > self::HEALTH_SCORE_WARNING) {
                            $shouldResolve = true;
                            $reason = sprintf('Health score recovered to %d (above %d threshold)', $currentHealth['health_score'], self::HEALTH_SCORE_WARNING);
                        }
                        break;

                    case self::ALERT_DISK_SPACE_LOW:
                        if ($currentHealth === null) {
                            try {
                                $currentHealth = $this->healthService->checkHealth();
                            } catch (Exception $e) {
                                Log::debug('AutoResolve: Health check failed, skipping alert', [
                                    'alert_id' => $alert->id,
                                    'error' => $e->getMessage(),
                                ]);
                                continue 2;
                            }
                        }
                        $freeGb = $currentHealth['checks']['disk_space']['disk_free_gb'] ?? 0;
                        if ($freeGb >= 10) {
                            $shouldResolve = true;
                            $reason = sprintf('Disk space recovered to %.1f GB free', $freeGb);
                        }
                        break;
                }

                if ($shouldResolve) {
                    $this->resolveAlert($alert->id, 'auto-resolve', $reason);
                    $resolved++;
                }
            }

            if ($resolved > 0) {
                Log::info('Auto-resolved recovered alerts', ['count' => $resolved]);
            }
        } catch (Exception $e) {
            Log::error('Auto-resolve check failed', ['error' => $e->getMessage()]);
        }

        return $resolved;
    }

    /**
     * Acknowledge alert using raw SQL
     *
     * @param int $alertId Alert ID
     * @param string $acknowledgedBy User who acknowledged
     * @return bool Success
     */
    public function acknowledgeAlert(int $alertId, string $acknowledgedBy = 'system'): bool
    {
        $sql = "UPDATE system_alerts
                SET acknowledged_at = NOW(),
                    acknowledged_by = ?,
                    updated_at = NOW()
                WHERE id = ? AND acknowledged_at IS NULL";

        $affected = DB::update($sql, [$acknowledgedBy, $alertId]);

        if ($affected > 0) {
            Log::info('Alert acknowledged', ['alert_id' => $alertId, 'by' => $acknowledgedBy]);
            return true;
        }

        return false;
    }

    /**
     * Resolve alert using raw SQL
     *
     * @param int $alertId Alert ID
     * @param string $resolvedBy User who resolved
     * @param string|null $resolutionNotes Resolution notes
     * @return bool Success
     */
    public function resolveAlert(int $alertId, string $resolvedBy = 'system', ?string $resolutionNotes = null): bool
    {
        $sql = "UPDATE system_alerts
                SET resolved_at = NOW(),
                    resolved_by = ?,
                    resolution_notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND resolved_at IS NULL";

        $affected = DB::update($sql, [$resolvedBy, $resolutionNotes, $alertId]);

        if ($affected > 0) {
            Log::info('Alert resolved', ['alert_id' => $alertId, 'by' => $resolvedBy]);
            return true;
        }

        return false;
    }

    /**
     * Get active alerts using raw SQL
     *
     * @param string|null $severity Filter by severity
     * @param int $limit Maximum alerts to return
     * @return array Active alerts
     */
    public function getActiveAlerts(?string $severity = null, int $limit = 100): array
    {
        if ($severity) {
            $sql = "SELECT id, alert_type, severity, title, message, context,
                           trigger_value, threshold_value, triggered_at,
                           occurrence_count, last_occurrence_at
                    FROM system_alerts
                    WHERE resolved_at IS NULL AND severity = ?
                    ORDER BY triggered_at DESC
                    LIMIT ?";
            $params = [$severity, $limit];
        } else {
            $sql = "SELECT id, alert_type, severity, title, message, context,
                           trigger_value, threshold_value, triggered_at,
                           occurrence_count, last_occurrence_at
                    FROM system_alerts
                    WHERE resolved_at IS NULL
                    ORDER BY triggered_at DESC
                    LIMIT ?";
            $params = [$limit];
        }

        return DB::select($sql, $params);
    }

    /**
     * Get alert statistics using raw SQL
     *
     * @param string $period Time period (e.g., '24 hours', '7 days')
     * @return array Alert statistics
     */
    public function getAlertStatistics(string $period = '24 hours'): array
    {
        $since = now()->sub($period)->toDateTimeString();

        // Total alerts
        $totalSql = "SELECT COUNT(*) as count FROM system_alerts WHERE triggered_at >= ?";
        $total = DB::select($totalSql, [$since])[0]->count ?? 0;

        // By severity
        $severitySql = "SELECT severity, COUNT(*) as count
                       FROM system_alerts
                       WHERE triggered_at >= ?
                       GROUP BY severity";
        $severityResults = DB::select($severitySql, [$since]);

        $bySeverity = [];
        foreach ($severityResults as $row) {
            $bySeverity[$row->severity] = $row->count;
        }

        // By type
        $typeSql = "SELECT alert_type, COUNT(*) as count
                   FROM system_alerts
                   WHERE triggered_at >= ?
                   GROUP BY alert_type
                   ORDER BY count DESC
                   LIMIT 10";
        $typeResults = DB::select($typeSql, [$since]);

        $byType = [];
        foreach ($typeResults as $row) {
            $byType[$row->alert_type] = $row->count;
        }

        // Active vs resolved
        $activeSql = "SELECT COUNT(*) as count FROM system_alerts WHERE triggered_at >= ? AND resolved_at IS NULL";
        $active = DB::select($activeSql, [$since])[0]->count ?? 0;

        $resolvedSql = "SELECT COUNT(*) as count FROM system_alerts WHERE triggered_at >= ? AND resolved_at IS NOT NULL";
        $resolved = DB::select($resolvedSql, [$since])[0]->count ?? 0;

        return [
            'period' => $period,
            'total_alerts' => $total,
            'active_alerts' => $active,
            'resolved_alerts' => $resolved,
            'by_severity' => $bySeverity,
            'by_type' => $byType,
        ];
    }

    /**
     * Generate fingerprint for alert deduplication
     *
     * @param string $alertType Alert type
     * @param array $context Context data
     * @return string SHA-256 fingerprint
     */
    private function generateFingerprint(string $alertType, array $context): string
    {
        $fingerprintData = [
            'type' => $alertType,
            'workflow_id' => $context['workflow_id'] ?? null,
            'source_type' => $context['source_type'] ?? null,
            'source_id' => $context['source_id'] ?? null,
            'metric_name' => $context['metric_name'] ?? null,
        ];

        return hash('sha256', json_encode($fingerprintData));
    }

    /**
     * Check if alert is in cooldown period using raw SQL
     *
     * @param string $fingerprint Alert fingerprint
     * @return bool True if in cooldown
     */
    private function isInCooldown(string $fingerprint): bool
    {
        // Deduplicate against ANY unresolved alert with same fingerprint,
        // not just those within cooldown window. Prevents alert spam when
        // the underlying condition persists across multiple check cycles.
        $sql = "SELECT id FROM system_alerts
                WHERE fingerprint = ?
                  AND resolved_at IS NULL
                LIMIT 1";

        $result = DB::select($sql, [$fingerprint]);

        return !empty($result);
    }

    /**
     * Increment occurrence count for deduplicated alert using raw SQL
     *
     * @param string $fingerprint Alert fingerprint
     * @return bool Success
     */
    private function incrementOccurrenceCount(string $fingerprint): bool
    {
        $sql = "UPDATE system_alerts
                SET occurrence_count = occurrence_count + 1,
                    last_occurrence_at = NOW(),
                    updated_at = NOW()
                WHERE fingerprint = ?
                  AND resolved_at IS NULL
                ORDER BY triggered_at DESC
                LIMIT 1";

        return DB::update($sql, [$fingerprint]) > 0;
    }

    /**
     * Clean up old resolved alerts using raw SQL
     *
     * @param int $daysToKeep Number of days to keep resolved alerts
     * @return int Number of alerts deleted
     */
    public function cleanupOldAlerts(int $daysToKeep = 30): int
    {
        $cutoff = now()->subDays($daysToKeep)->toDateTimeString();

        $sql = "DELETE FROM system_alerts
                WHERE resolved_at IS NOT NULL
                  AND resolved_at < ?";

        $deleted = DB::delete($sql, [$cutoff]);

        Log::info('Old alerts cleaned up', ['deleted' => $deleted, 'days_to_keep' => $daysToKeep]);

        return $deleted;
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

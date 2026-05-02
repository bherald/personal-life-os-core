<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use App\Services\ErrorTrackingService;
use App\Services\WorkflowDiagnosticsService;
use App\Services\ProactiveAlertService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DevToolsController extends Controller
{
    public function getDiagnostics(): JsonResponse
    {
        $diagnostics = [
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'database' => [
                'connection' => config('database.default'),
                'driver' => config('database.connections.' . config('database.default') . '.driver'),
            ],
            'cache' => [
                'driver' => config('cache.default'),
            ],
            'queue' => [
                'connection' => config('queue.default'),
            ],
            'disk_space' => [
                'storage' => disk_free_space(storage_path()) / 1024 / 1024 / 1024, // GB
            ],
            'paths' => [
                'base' => base_path(),
                'storage' => storage_path(),
                'public' => public_path(),
            ]
        ];

        // Check database connection
        try {
            \DB::connection()->getPdo();
            $diagnostics['database']['status'] = 'connected';
        } catch (\Exception $e) {
            $diagnostics['database']['status'] = 'disconnected';
            $diagnostics['database']['error'] = $e->getMessage();
        }

        // Check live scheduled workflow jobs from the DB scheduler source of truth.
        $sql = "SELECT COUNT(*) as count FROM scheduled_jobs WHERE job_type = 'workflow' AND enabled = true";
        $scheduledTasks = \DB::select($sql)[0]->count ?? 0;
        $diagnostics['scheduler'] = [
            'active_scheduled_workflows' => $scheduledTasks
        ];

        // Get recent errors from Laravel logs
        $diagnostics['recent_errors'] = $this->getRecentLogErrors();

        return response()->json([
            'success' => true,
            'data' => $diagnostics
        ]);
    }

    /**
     * Get recent errors from Laravel log file
     */
    private function getRecentLogErrors(int $limit = 10): array
    {
        $logFile = storage_path('logs/laravel.log');

        if (!file_exists($logFile)) {
            return [];
        }

        $errors = [];
        $lines = file($logFile);

        // Read log file in reverse to get most recent errors first
        for ($i = count($lines) - 1; $i >= 0 && count($errors) < $limit * 5; $i--) {
            $line = $lines[$i];

            // Match ERROR or CRITICAL level logs
            if (preg_match('/\[([\d\-\s:]+)\]\s+\w+\.(ERROR|CRITICAL):\s+(.+)/', $line, $matches)) {
                $timestamp = $matches[1];
                $level = $matches[2];
                $messagePart = trim($matches[3]);

                // Collect continuation lines if JSON spans multiple lines
                $fullMessage = $messagePart;
                $j = $i - 1;
                // Check if next line continues (doesn't start with timestamp)
                while ($j >= 0 && !preg_match('/^\[\d{4}-\d{2}-\d{2}/', $lines[$j])) {
                    $fullMessage .= "\n" . trim($lines[$j]);
                    $j--;
                }

                // Parse JSON context if present (Laravel style: "Message {json}")
                $message = $fullMessage;
                $context = [];

                // Try to extract JSON from end of message
                if (preg_match('/^(.+?)\s+(\{.+\})\s*$/s', $fullMessage, $msgMatch)) {
                    $jsonStr = $msgMatch[2];

                    // Attempt to decode the JSON
                    $contextJson = json_decode($jsonStr, true);
                    if ($contextJson !== null && json_last_error() === JSON_ERROR_NONE) {
                        $context = $contextJson;
                        $message = trim($msgMatch[1]);
                    } else {
                        // Try to unescape if it's escaped JSON (has \" instead of ")
                        $unescapedJson = str_replace(['\\"', '\\n'], ['"', "\n"], $jsonStr);
                        $contextJson = json_decode($unescapedJson, true);
                        if ($contextJson !== null && json_last_error() === JSON_ERROR_NONE) {
                            $context = $contextJson;
                            $message = trim($msgMatch[1]);
                        }
                    }
                }

                $errors[] = [
                    'timestamp' => $timestamp,
                    'level' => $level,
                    'message' => $message,
                    'context' => $context
                ];
            }
        }

        return array_slice($errors, 0, $limit);
    }

    /**
     * Get system health status
     */
    public function getSystemHealth(
        Request $request,
        SystemHealthService $healthService
    ): JsonResponse {
        try {
            $health = $healthService->checkHealth();

            return response()->json([
                'success' => true,
                'data' => $health
            ]);
        } catch (\Exception $e) {
            Log::error('System health check failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'HEALTH_CHECK_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get error statistics
     */
    public function getErrorStatistics(
        Request $request,
        ErrorTrackingService $errorTracking
    ): JsonResponse {
        $period = $request->input('period', '24 hours');
        $errorType = $request->input('type');

        try {
            $errorRate = $errorTracking->getErrorRate($period, $errorType);
            $spikeDetected = $errorTracking->detectErrorSpike();
            $topErrors = $errorTracking->getTopErrors(10, $period);
            $patterns = $errorTracking->analyzeErrorPatterns();

            return response()->json([
                'success' => true,
                'data' => [
                    'error_rate' => $errorRate,
                    'spike_detected' => $spikeDetected,
                    'top_errors' => $topErrors,
                    'patterns' => $patterns,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error statistics retrieval failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ERROR_STATS_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get workflow diagnostics
     */
    public function getWorkflowDiagnostics(
        Request $request,
        WorkflowDiagnosticsService $diagnostics
    ): JsonResponse {
        try {
            $workflowId = $request->input('workflow_id');
            $period = $request->input('period', '7 days');

            if ($workflowId) {
                // Specific workflow analysis
                $analysis = $diagnostics->analyzeWorkflow((int) $workflowId, $period);

                return response()->json([
                    'success' => true,
                    'data' => $analysis
                ]);
            } else {
                // Health summary for all workflows
                $summary = $diagnostics->getHealthSummary();
                $failing = $diagnostics->getFailingWorkflows('degraded');

                return response()->json([
                    'success' => true,
                    'data' => [
                        'summary' => $summary,
                        'failing_workflows' => $failing,
                    ]
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Workflow diagnostics failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DIAGNOSTICS_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get active alerts
     */
    public function getActiveAlerts(
        Request $request,
        ProactiveAlertService $alertService
    ): JsonResponse {
        try {
            $severity = $request->input('severity');
            $limit = (int) ($request->input('limit', 100));

            $alerts = $alertService->getActiveAlerts($severity, $limit);
            $stats = $alertService->getAlertStatistics($request->input('period', '24 hours'));

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts,
                    'statistics' => $stats,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Alert retrieval failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ALERT_RETRIEVAL_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Run alert checks
     */
    public function runAlertChecks(
        Request $request,
        ProactiveAlertService $alertService
    ): JsonResponse {
        try {
            $results = $alertService->runAllChecks();

            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Alert checks failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ALERT_CHECKS_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert(
        Request $request,
        ProactiveAlertService $alertService
    ): JsonResponse {
        $request->validate([
            'alert_id' => 'required|integer',
            'acknowledged_by' => 'nullable|string'
        ]);

        try {
            $alertId = (int) $request->input('alert_id');
            $acknowledgedBy = $request->input('acknowledged_by', 'api');

            $success = $alertService->acknowledgeAlert($alertId, $acknowledgedBy);

            return response()->json([
                'success' => $success,
                'data' => [
                    'alert_id' => $alertId,
                    'acknowledged' => $success
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Alert acknowledgement failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ACKNOWLEDGE_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Resolve alert
     */
    public function resolveAlert(
        Request $request,
        ProactiveAlertService $alertService
    ): JsonResponse {
        $request->validate([
            'alert_id' => 'required|integer',
            'resolved_by' => 'nullable|string',
            'resolution_notes' => 'nullable|string'
        ]);

        try {
            $alertId = (int) $request->input('alert_id');
            $resolvedBy = $request->input('resolved_by', 'api');
            $resolutionNotes = $request->input('resolution_notes');

            $success = $alertService->resolveAlert($alertId, $resolvedBy, $resolutionNotes);

            return response()->json([
                'success' => $success,
                'data' => [
                    'alert_id' => $alertId,
                    'resolved' => $success
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Alert resolution failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RESOLVE_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Take system health snapshot
     */
    public function takeHealthSnapshot(
        Request $request,
        SystemHealthService $healthService
    ): JsonResponse {
        try {
            $snapshotId = $healthService->takeSnapshot();

            return response()->json([
                'success' => true,
                'data' => [
                    'snapshot_id' => $snapshotId
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Health snapshot failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SNAPSHOT_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get backup status - shows database backup files and their status
     */
    public function getBackupStatus(): JsonResponse
    {
        try {
            $backupPath = storage_path('backups');
            $backups = [
                'mysql' => [],
                'postgres' => [],
            ];

            if (is_dir($backupPath)) {
                $files = scandir($backupPath);

                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;

                    $filePath = $backupPath . '/' . $file;
                    $fileInfo = [
                        'filename' => $file,
                        'size_mb' => round(filesize($filePath) / 1024 / 1024, 2),
                        'created_at' => date('Y-m-d H:i:s', filemtime($filePath)),
                        'age_hours' => round((time() - filemtime($filePath)) / 3600, 1),
                    ];

                    if (str_starts_with($file, 'mysql_backup')) {
                        $backups['mysql'][] = $fileInfo;
                    } elseif (str_starts_with($file, 'postgres_backup')) {
                        $backups['postgres'][] = $fileInfo;
                    }
                }

                // Sort by created_at descending (most recent first)
                usort($backups['mysql'], fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
                usort($backups['postgres'], fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
            }

            // Calculate summary
            $latestMysql = $backups['mysql'][0] ?? null;
            $latestPostgres = $backups['postgres'][0] ?? null;

            $summary = [
                'mysql_count' => count($backups['mysql']),
                'postgres_count' => count($backups['postgres']),
                'mysql_latest' => $latestMysql ? $latestMysql['created_at'] : null,
                'postgres_latest' => $latestPostgres ? $latestPostgres['created_at'] : null,
                'mysql_latest_age_hours' => $latestMysql ? $latestMysql['age_hours'] : null,
                'postgres_latest_age_hours' => $latestPostgres ? $latestPostgres['age_hours'] : null,
                'mysql_total_size_mb' => array_sum(array_column($backups['mysql'], 'size_mb')),
                'postgres_total_size_mb' => array_sum(array_column($backups['postgres'], 'size_mb')),
                'backup_healthy' => ($latestMysql && $latestMysql['age_hours'] < 25) &&
                                   ($latestPostgres && $latestPostgres['age_hours'] < 25),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'backups' => $backups,
                    'backup_path' => $backupPath,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Backup status check failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'BACKUP_STATUS_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get services status - Joplin, Queue, and other integrations
     */
    public function getServicesStatus(): JsonResponse
    {
        try {
            $services = [];

            // Joplin Health
            try {
                $joplinService = app(\App\Services\JoplinSyncService::class);
                $services['joplin'] = $joplinService->getHealth();
            } catch (\Exception $e) {
                $services['joplin'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }

            // Queue Stats
            try {
                $queueService = app(\App\Services\JoplinQueueService::class);
                $services['joplin_queue'] = $queueService->getStatistics();
            } catch (\Exception $e) {
                $services['joplin_queue'] = [
                    'error' => $e->getMessage(),
                ];
            }

            // Laravel Queue (failed jobs)
            try {
                $failedTotalResult = \DB::select('SELECT COUNT(*) as count FROM failed_jobs');
                $failedRecentResult = \DB::select(
                    'SELECT COUNT(*) as count FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
                );
                $failedJobs = $failedTotalResult[0]->count ?? 0;
                $failedJobs24h = $failedRecentResult[0]->count ?? 0;

                if (config('queue.default') === 'redis') {
                    $redis = \Redis::connection();
                    $queues = array_values(array_unique(array_filter([
                        config('queue.connections.redis.queue', 'default'),
                        'high',
                        'default',
                        'low',
                        'workflow',
                        'long-running',
                        'speculative',
                    ])));
                    $pendingJobs = 0;

                    foreach ($queues as $queue) {
                        $pendingJobs += (int) ($redis->llen("queues:{$queue}") ?? 0);
                    }
                } else {
                    $pendingResult = \DB::select('SELECT COUNT(*) as count FROM jobs');
                    $pendingJobs = $pendingResult[0]->count ?? 0;
                }

                $services['laravel_queue'] = [
                    'pending' => $pendingJobs,
                    'failed' => $failedJobs,
                    'failed_24h' => $failedJobs24h,
                    'healthy' => $failedJobs24h < 10,
                ];
            } catch (\Exception $e) {
                $services['laravel_queue'] = [
                    'error' => $e->getMessage(),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $services
            ]);
        } catch (\Exception $e) {
            Log::error('Services status check failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVICES_STATUS_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }
}

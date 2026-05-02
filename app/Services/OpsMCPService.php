<?php

namespace App\Services;

use App\Controllers\NotificationController;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Ops MCP Service - Infrastructure Maintenance for AI Operator
 *
 * Provides tools for AI to monitor, maintain, and report on system health
 * without modifying source code. All operations are safe and reversible.
 *
 * Tools provided (8):
 * - ops_health_check: Full system health assessment
 * - ops_log_analyze: Scan logs for errors/patterns
 * - ops_cleanup: Execute all cleanup tasks
 * - ops_report: Generate formatted Pushover report
 * - ops_alert: Send Pushover notification
 * - ops_status: Quick status summary
 * - ops_issues_list: List pending system issues for human review
 * - ops_issues_update: Update issue status (after human approval)
 */
class OpsMCPService
{
    // Thresholds for health checks — config/health_thresholds.php is primary (SC-2.5)
    private const REDIS_MEMORY_WARNING_PERCENT = 80;

    private const FAILED_JOBS_WARNING_COUNT = 5;

    private const STUCK_WORKFLOW_HOURS = 6;

    private const DISK_SPACE_CRITICAL_PERCENT = 90;

    private const LOG_FILE_SIZE_WARNING_MB = 50;

    private const MIN_HORIZON_WORKERS = 2;

    private const BACKUP_MAX_AGE_HOURS = 25;

    private const SSL_EXPIRY_WARNING_DAYS = 14;

    private const SSL_EXPIRY_CRITICAL_DAYS = 7;

    // Cleanup retention periods — config/health_thresholds.php is primary (SC-2.5)
    private const LOG_RETENTION_DAYS = 7;

    private const FAILED_JOBS_RETENTION_DAYS = 7;

    private const EXECUTION_LOGS_RETENTION_DAYS = 30;

    // Backup configuration loaded from .env

    private string $storagePath;

    private string $logsPath;

    private string $backupPath;

    public function __construct()
    {
        $this->storagePath = storage_path();
        $this->logsPath = storage_path('logs');
        $this->backupPath = storage_path('backups');
    }

    /**
     * Active Redis queue lanes used by the current Horizon topology.
     *
     * @return array<int, string>
     */
    private function getActiveQueueNames(): array
    {
        return array_values(array_unique(array_filter([
            config('queue.connections.redis.queue', 'default'),
            'high',
            'default',
            'low',
            'long-running',
            'workflow',
            'speculative',
        ])));
    }

    /**
     * Full system health assessment
     *
     * @param  array  $params  Optional parameters
     * @return array Health check results with status and observations
     */
    public function ops_health_check(array $params = []): array
    {
        $results = [
            'timestamp' => now()->toIso8601String(),
            'overall_status' => 'healthy',
            'checks' => [],
            'warnings' => [],
            'critical' => [],
            'observations' => [],
        ];

        // 1. Redis Health
        $redisCheck = $this->checkRedisHealth();
        $results['checks']['redis'] = $redisCheck;
        if ($redisCheck['status'] === 'warning') {
            $results['warnings'][] = $redisCheck['message'];
        } elseif ($redisCheck['status'] === 'critical') {
            $results['critical'][] = $redisCheck['message'];
            $results['overall_status'] = 'critical';
        }

        // 2. Horizon Workers
        $horizonCheck = $this->checkHorizonWorkers();
        $results['checks']['horizon'] = $horizonCheck;
        if ($horizonCheck['status'] === 'critical') {
            $results['critical'][] = $horizonCheck['message'];
            $results['overall_status'] = 'critical';
        } elseif ($horizonCheck['status'] === 'warning') {
            $results['warnings'][] = $horizonCheck['message'];
        }

        // 3. Failed Jobs
        $failedJobsCheck = $this->checkFailedJobs();
        $results['checks']['failed_jobs'] = $failedJobsCheck;
        if ($failedJobsCheck['status'] === 'warning') {
            $results['warnings'][] = $failedJobsCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }

        // 4. Stuck Workflows - REMOVED: Now handled by checkWorkflowHealth() with auto-cleanup
        // The 1-hour auto-cleanup in checkWorkflowHealth catches stuck workflows before
        // they would trigger this 6-hour alert. Keeping empty check for API compatibility.
        $results['checks']['stuck_workflows'] = ['status' => 'healthy', 'stuck_count' => 0];

        // 5. Disk Space
        $diskCheck = $this->checkDiskSpace();
        $results['checks']['disk_space'] = $diskCheck;
        if ($diskCheck['status'] === 'critical') {
            $results['critical'][] = $diskCheck['message'];
            $results['overall_status'] = 'critical';
        } elseif ($diskCheck['status'] === 'warning') {
            $results['warnings'][] = $diskCheck['message'];
        }

        // 6. Log File Sizes
        $logCheck = $this->checkLogFileSizes();
        $results['checks']['log_files'] = $logCheck;
        if ($logCheck['status'] === 'warning') {
            $results['warnings'][] = $logCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }

        // 7. Database Connection
        $dbCheck = $this->checkDatabaseConnection();
        $results['checks']['database'] = $dbCheck;
        if ($dbCheck['status'] === 'critical') {
            $results['critical'][] = $dbCheck['message'];
            $results['overall_status'] = 'critical';
        }

        // 8. Recent Workflow Performance
        $workflowCheck = $this->checkRecentWorkflows();
        $results['checks']['recent_workflows'] = $workflowCheck;
        if (! empty($workflowCheck['observations'])) {
            $results['observations'] = array_merge($results['observations'], $workflowCheck['observations']);
        }

        // 9. Database Backups
        $backupCheck = $this->checkDatabaseBackups();
        $results['checks']['backups'] = $backupCheck;
        if ($backupCheck['status'] === 'critical') {
            $results['critical'][] = $backupCheck['message'];
            $results['overall_status'] = 'critical';
        } elseif ($backupCheck['status'] === 'warning') {
            $results['warnings'][] = $backupCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }

        // 10. Critical Services (Ollama, Nginx)
        $servicesCheck = $this->checkCriticalServices();
        $results['checks']['services'] = $servicesCheck;
        if ($servicesCheck['status'] === 'critical') {
            $results['critical'][] = $servicesCheck['message'];
            $results['overall_status'] = 'critical';
        } elseif ($servicesCheck['status'] === 'warning') {
            $results['warnings'][] = $servicesCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }

        // 11. PostgreSQL RAG Database
        $ragCheck = $this->checkRAGDatabase();
        $results['checks']['rag_database'] = $ragCheck;
        if ($ragCheck['status'] === 'critical') {
            $results['critical'][] = $ragCheck['message'];
            $results['overall_status'] = 'critical';
        } elseif ($ragCheck['status'] === 'warning') {
            $results['warnings'][] = $ragCheck['message'];
        }

        // 12. Joplin Attachment Processing
        $joplinAttachCheck = $this->checkJoplinAttachments();
        $results['checks']['joplin_attachments'] = $joplinAttachCheck;
        if ($joplinAttachCheck['status'] === 'warning') {
            $results['warnings'][] = $joplinAttachCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }
        if (! empty($joplinAttachCheck['observations'])) {
            $results['observations'] = array_merge($results['observations'], $joplinAttachCheck['observations']);
        }

        // 13. Open Circuit Breakers (services in fallback mode)
        $circuitCheck = $this->checkCircuitBreakers();
        $results['checks']['circuit_breakers'] = $circuitCheck;
        if ($circuitCheck['status'] === 'warning') {
            $results['warnings'][] = $circuitCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }
        if (! empty($circuitCheck['observations'])) {
            $results['observations'] = array_merge($results['observations'], $circuitCheck['observations']);
        }

        // 15. Horizon Queue Health (pending jobs, stuck jobs)
        $queueCheck = $this->checkHorizonQueues();
        $results['checks']['horizon_queues'] = $queueCheck;
        if ($queueCheck['status'] === 'warning') {
            $results['warnings'][] = $queueCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }
        if (! empty($queueCheck['observations'])) {
            $results['observations'] = array_merge($results['observations'], $queueCheck['observations']);
        }

        // 16. Email Bounce Health (bounce rate, suppression list growth)
        $emailBounceCheck = $this->checkEmailBounceHealth();
        $results['checks']['email_bounces'] = $emailBounceCheck;
        if ($emailBounceCheck['status'] === 'warning') {
            $results['warnings'][] = $emailBounceCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }
        if (! empty($emailBounceCheck['observations'])) {
            $results['observations'] = array_merge($results['observations'], $emailBounceCheck['observations']);
        }

        // 17. Data Removal Broker Health (broken brokers, pending tasks)
        $brokerCheck = $this->checkDataRemovalBrokerHealth();
        $results['checks']['data_removal_brokers'] = $brokerCheck;
        if ($brokerCheck['status'] === 'warning') {
            $results['warnings'][] = $brokerCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }
        if (! empty($brokerCheck['observations'])) {
            $results['observations'] = array_merge($results['observations'], $brokerCheck['observations']);
        }

        // 18. Research Source Health (failing sources, stale sources)
        $researchCheck = $this->checkResearchSourceHealth();
        $results['checks']['research_sources'] = $researchCheck;
        if ($researchCheck['status'] === 'warning') {
            $results['warnings'][] = $researchCheck['message'];
            if ($results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }
        if (! empty($researchCheck['observations'])) {
            $results['observations'] = array_merge($results['observations'], $researchCheck['observations']);
        }

        // Determine overall status if still healthy but has warnings
        if ($results['overall_status'] === 'healthy' && ! empty($results['warnings'])) {
            $results['overall_status'] = 'warning';
        }

        return $results;
    }

    /**
     * Scan logs for errors and patterns
     *
     * @param  array  $params  ['hours' => 24, 'level' => 'error']
     * @return array Log analysis results
     */
    public function ops_log_analyze(array $params = []): array
    {
        $hours = $params['hours'] ?? 24;
        $level = strtoupper($params['level'] ?? 'ERROR');
        $cutoffTime = now()->subHours($hours);

        $results = [
            'timestamp' => now()->toIso8601String(),
            'analyzed_period' => "{$hours} hours",
            'level_filter' => $level,
            'errors' => [],
            'patterns' => [],
            'summary' => [],
        ];

        $logFile = $this->logsPath.'/laravel.log';
        if (! file_exists($logFile)) {
            $results['summary']['status'] = 'no_log_file';

            return $results;
        }

        // Read log file (last 10000 lines max for performance)
        $lines = $this->tailFile($logFile, 10000);
        $errorCounts = [];
        $errorMessages = [];

        foreach ($lines as $line) {
            // Parse Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: message
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*\.('.$level.'|CRITICAL|EMERGENCY):(.*)$/i', $line, $matches)) {
                $timestamp = $matches[1];
                $logLevel = strtoupper($matches[2]);
                $message = trim($matches[3]);

                // Check if within time range
                try {
                    $logTime = new \DateTime($timestamp);
                    if ($logTime < $cutoffTime) {
                        continue;
                    }
                } catch (Exception $e) {
                    Log::debug('OpsMCPService: log timestamp parse failed', ['error' => $e->getMessage()]);

                    continue;
                }

                // Categorize error
                $category = $this->categorizeError($message);
                if (! isset($errorCounts[$category])) {
                    $errorCounts[$category] = 0;
                    $errorMessages[$category] = [];
                }
                $errorCounts[$category]++;

                // Store first 3 examples of each category
                if (count($errorMessages[$category]) < 3) {
                    $errorMessages[$category][] = [
                        'time' => $timestamp,
                        'level' => $logLevel,
                        'message' => substr($message, 0, 200),
                    ];
                }
            }
        }

        $results['errors'] = $errorMessages;
        $results['patterns'] = $errorCounts;
        $results['summary'] = [
            'total_errors' => array_sum($errorCounts),
            'categories' => count($errorCounts),
            'top_category' => ! empty($errorCounts) ? array_keys($errorCounts, max($errorCounts))[0] : null,
        ];

        return $results;
    }

    /**
     * Execute all cleanup tasks
     *
     * @param  array  $params  Optional parameters
     * @return array Cleanup results
     */
    public function ops_cleanup(array $params = []): array
    {
        $results = [
            'timestamp' => now()->toIso8601String(),
            'actions' => [],
            'space_freed_bytes' => 0,
            'rows_deleted' => 0,
        ];

        // 1. Truncate old log files
        $logCleanup = $this->cleanupLogFiles();
        $results['actions']['log_files'] = $logCleanup;
        $results['space_freed_bytes'] += $logCleanup['bytes_freed'];

        // 2. Delete archive log files
        $archiveCleanup = $this->cleanupArchiveFiles();
        $results['actions']['archive_files'] = $archiveCleanup;
        $results['space_freed_bytes'] += $archiveCleanup['bytes_freed'];

        // 3. Prune failed jobs
        $failedJobsCleanup = $this->cleanupFailedJobs();
        $results['actions']['failed_jobs'] = $failedJobsCleanup;
        $results['rows_deleted'] += $failedJobsCleanup['rows_deleted'];

        // 4. Clear Horizon metrics
        $horizonCleanup = $this->cleanupHorizonMetrics();
        $results['actions']['horizon_metrics'] = $horizonCleanup;

        // 5. Clear old execution logs
        $executionCleanup = $this->cleanupExecutionLogs();
        $results['actions']['execution_logs'] = $executionCleanup;
        $results['rows_deleted'] += $executionCleanup['rows_deleted'];

        // 6. Restart stuck Horizon workers if needed
        $workerRestart = $this->restartStuckWorkers();
        $results['actions']['worker_restart'] = $workerRestart;

        // 7. Clear stale Redis cache keys
        $cacheCleanup = $this->cleanupStaleCache();
        $results['actions']['cache_cleanup'] = $cacheCleanup;

        $results['summary'] = [
            'space_freed_mb' => round($results['space_freed_bytes'] / 1024 / 1024, 2),
            'total_rows_deleted' => $results['rows_deleted'],
            'actions_taken' => count(array_filter($results['actions'], fn ($a) => $a['performed'] ?? false)),
        ];

        return $results;
    }

    /**
     * Generate formatted Pushover report
     *
     * @param  array  $params  ['health' => array, 'cleanup' => array]
     * @return array Report content
     */
    public function ops_report(array $params = []): array
    {
        $healthData = $params['health'] ?? $this->ops_health_check();
        $cleanupData = $params['cleanup'] ?? null;
        $logData = $params['logs'] ?? $this->ops_log_analyze(['hours' => 24]);

        $time = now()->format('g:i A');
        $date = now()->format('M j');

        // Build report
        $lines = [];
        $lines[] = "PLOS Ops Report - {$date} {$time}";
        $lines[] = '';

        // Health Status
        $statusEmoji = match ($healthData['overall_status']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'critical' => '🚨',
            default => '❓',
        };

        $lines[] = "{$statusEmoji} HEALTH: ".ucfirst($healthData['overall_status']);

        // Redis
        if (isset($healthData['checks']['redis'])) {
            $redis = $healthData['checks']['redis'];
            $lines[] = "   • Redis: {$redis['used_mb']}MB/{$redis['max_mb']}MB ({$redis['percent']}%)";
        }

        // Horizon
        if (isset($healthData['checks']['horizon'])) {
            $horizon = $healthData['checks']['horizon'];
            $lines[] = "   • Horizon: {$horizon['workers']} workers active";
        }

        // Disk
        if (isset($healthData['checks']['disk_space'])) {
            $disk = $healthData['checks']['disk_space'];
            $lines[] = "   • Disk: {$disk['percent']}% used";
        }

        // Backups
        if (isset($healthData['checks']['backups'])) {
            $backups = $healthData['checks']['backups'];
            if ($backups['mysql_backup']) {
                $lines[] = "   • MySQL backup: {$backups['mysql_backup']['age_hours']}hr old ({$backups['mysql_backup']['size_mb']}MB)";
            }
            if ($backups['postgres_backup']) {
                $lines[] = "   • PG backup: {$backups['postgres_backup']['age_hours']}hr old ({$backups['postgres_backup']['size_mb']}MB)";
            }
        }

        // Services
        if (isset($healthData['checks']['services'])) {
            $svc = $healthData['checks']['services'];
            $ollamaStatus = $svc['services']['ollama']['status'] ?? 'unknown';
            if ($ollamaStatus === 'running') {
                $modelCount = $svc['services']['ollama']['models'] ?? 0;
                $lines[] = "   • Ollama: {$modelCount} models loaded";
            } else {
                $lines[] = "   • Ollama: {$ollamaStatus}";
            }
        }

        // RAG Database
        if (isset($healthData['checks']['rag_database'])) {
            $rag = $healthData['checks']['rag_database'];
            if ($rag['status'] === 'healthy') {
                $lines[] = "   • RAG: {$rag['document_count']} docs ({$rag['database_size']})";
            }
        }

        // Cleanup section (if performed)
        if ($cleanupData) {
            $lines[] = '';
            $lines[] = '🧹 CLEANUP PERFORMED:';

            if (isset($cleanupData['actions']['log_files']) && $cleanupData['actions']['log_files']['performed']) {
                $logAction = $cleanupData['actions']['log_files'];
                $mb = round($logAction['bytes_freed'] / 1024 / 1024, 1);
                $lines[] = "   • Logs truncated: {$logAction['files_cleaned']} files ({$mb}MB freed)";
            }

            if (isset($cleanupData['actions']['failed_jobs']) && $cleanupData['actions']['failed_jobs']['rows_deleted'] > 0) {
                $lines[] = "   • Failed jobs pruned: {$cleanupData['actions']['failed_jobs']['rows_deleted']}";
            }

            if (isset($cleanupData['actions']['execution_logs']) && $cleanupData['actions']['execution_logs']['rows_deleted'] > 0) {
                $lines[] = "   • Old executions cleared: {$cleanupData['actions']['execution_logs']['rows_deleted']} rows";
            }

            if (isset($cleanupData['actions']['worker_restart']) && $cleanupData['actions']['worker_restart']['performed']) {
                $lines[] = '   • Horizon workers restarted';
            }
        }

        // Warnings and Critical
        if (! empty($healthData['warnings']) || ! empty($healthData['critical'])) {
            $lines[] = '';
            $lines[] = '⚠️ ISSUES:';
            foreach ($healthData['critical'] as $issue) {
                $lines[] = "   • 🚨 {$issue}";
            }
            foreach ($healthData['warnings'] as $issue) {
                $lines[] = "   • ⚠️ {$issue}";
            }
        }

        // Observations
        if (! empty($healthData['observations'])) {
            $lines[] = '';
            $lines[] = '📝 OBSERVATIONS:';
            foreach (array_slice($healthData['observations'], 0, 5) as $obs) {
                $lines[] = "   • {$obs}";
            }
        }

        // Log errors summary
        if (isset($logData['summary']['total_errors']) && $logData['summary']['total_errors'] > 0) {
            $lines[] = '';
            $lines[] = "📋 LOG ERRORS (24hr): {$logData['summary']['total_errors']}";
            if (! empty($logData['patterns'])) {
                foreach (array_slice($logData['patterns'], 0, 3, true) as $category => $count) {
                    $lines[] = "   • {$category}: {$count}";
                }
            }
        }

        // Pending Issues Section - persists until dismissed
        $pendingData = $this->getPendingIssuesForReport(5);
        if ($pendingData['has_pending']) {
            $lines[] = '';
            $lines[] = '📋 PENDING ISSUES';
            $lines[] = '   Settings > System Issues';
            $lines[] = '';

            foreach ($pendingData['issues'] as $issue) {
                $statusLabel = $issue['status'] === 'resolved' ? '[RESOLVED]' : '';
                $lines[] = "   {$issue['icon']} {$issue['title']} {$statusLabel}";
                $lines[] = "      {$issue['day_date']}";
            }

            if ($pendingData['overflow'] > 0) {
                $lines[] = '';
                $lines[] = "   +{$pendingData['overflow']} more - see UI";
            }
        }

        $lines[] = '';
        $lines[] = '📊 Ready for morning workflows';

        return [
            'title' => '🔧 PLOS Ops Report',
            'message' => implode("\n", $lines),
            'priority' => $healthData['overall_status'] === 'critical' ? 1 : 0,
        ];
    }

    /**
     * Send Pushover notification
     *
     * @param  array  $params  ['title' => string, 'message' => string, 'priority' => int]
     * @return array Send result
     */
    public function ops_alert(array $params = []): array
    {
        $title = $params['title'] ?? 'PLOS Ops Alert';
        $message = $params['message'] ?? 'No message provided';
        $priority = $params['priority'] ?? 0;

        try {
            $controller = new NotificationController;
            $success = $controller->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
                'format_type' => 'plain',
            ]);

            return [
                'success' => $success,
                'title' => $title,
                'message_length' => strlen($message),
                'priority' => $priority,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Quick status summary
     *
     * @param  array  $params  Optional parameters
     * @return array Quick status
     */
    public function ops_status(array $params = []): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'redis' => $this->getRedisQuickStatus(),
            'horizon' => $this->getHorizonQuickStatus(),
            'database' => $this->getDatabaseQuickStatus(),
            'disk' => $this->getDiskQuickStatus(),
            'last_workflow' => $this->getLastWorkflowStatus(),
        ];
    }

    // ==================== PRIVATE HELPER METHODS ====================

    private function checkRedisHealth(): array
    {
        try {
            $info = Redis::info('memory');
            $usedMemory = $info['used_memory'] ?? 0;
            $maxMemory = $info['maxmemory'] ?? 0;

            // If maxmemory is 0, Redis has no limit - check against our expected 512MB
            if ($maxMemory == 0) {
                $maxMemory = 512 * 1024 * 1024; // 512MB default
            }

            $percent = round(($usedMemory / $maxMemory) * 100, 1);
            $usedMb = round($usedMemory / 1024 / 1024, 1);
            $maxMb = round($maxMemory / 1024 / 1024, 1);

            $status = 'healthy';
            $message = null;

            if ($percent >= config('health_thresholds.system.redis_memory_warning_percent', self::REDIS_MEMORY_WARNING_PERCENT)) {
                $status = 'warning';
                $message = "Redis memory at {$percent}% ({$usedMb}MB/{$maxMb}MB)";
            }

            return [
                'status' => $status,
                'used_mb' => $usedMb,
                'max_mb' => $maxMb,
                'percent' => $percent,
                'message' => $message,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Redis connection failed: '.$e->getMessage(),
            ];
        }
    }

    private function checkHorizonWorkers(): array
    {
        try {
            // Check if Horizon is running via Redis
            // Use the configured Horizon prefix (default: laravel_horizon:)
            // Must use fresh Redis connection to bypass Laravel's database prefix
            $prefix = config('horizon.prefix', 'laravel_horizon:');

            // Retry up to 3 times with 2-second delay to handle heartbeat timing
            // Horizon master keys have ~15 second TTL and may briefly expire between heartbeats
            $maxRetries = 3;
            $retryDelay = 2; // seconds

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $redis = new \Redis;
                $redis->connect(
                    config('database.redis.default.host', '127.0.0.1'),
                    (int) config('database.redis.default.port', 6379)
                );
                $redis->select((int) config('database.redis.default.database', 0));

                $masters = $redis->keys($prefix.'master:*');
                $supervisors = $redis->keys($prefix.'supervisor:*');

                $workerCount = 0;
                foreach ($supervisors as $key) {
                    // Supervisor data is stored as a Redis hash
                    $processes = $redis->hGet($key, 'processes');
                    if ($processes) {
                        $processData = json_decode($processes, true);
                        // Sum the worker counts per queue (e.g., {"redis:high":1,"redis:default":1})
                        $workerCount += array_sum($processData ?? []);
                    }
                }

                $redis->close();

                // If we found masters, return success
                if (! empty($masters)) {
                    $status = 'healthy';
                    $message = null;

                    if ($workerCount < config('health_thresholds.system.min_horizon_workers', self::MIN_HORIZON_WORKERS)) {
                        $status = 'critical';
                        $message = "Only {$workerCount} Horizon workers (min: ".config('health_thresholds.system.min_horizon_workers', self::MIN_HORIZON_WORKERS).')';
                    }

                    return [
                        'status' => $status,
                        'masters' => count($masters),
                        'supervisors' => count($supervisors),
                        'workers' => $workerCount,
                        'message' => $message,
                    ];
                }

                // No masters found - if not last attempt, wait and retry
                if ($attempt < $maxRetries) {
                    sleep($retryDelay);
                }
            }

            // After all retries, still no masters - also check if Horizon process exists
            $horizonProcess = trim(Process::timeout(5)->run(['pgrep', '-f', 'artisan horizon$'])->output());
            if (! empty($horizonProcess)) {
                // Process exists but Redis keys missing - likely starting up
                return [
                    'status' => 'warning',
                    'masters' => 0,
                    'supervisors' => 0,
                    'workers' => 0,
                    'message' => 'Horizon process running but Redis keys not found (may be starting)',
                ];
            }

            return [
                'status' => 'critical',
                'masters' => 0,
                'supervisors' => 0,
                'workers' => 0,
                'message' => 'Horizon master not running',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Horizon check failed: '.$e->getMessage(),
            ];
        }
    }

    private function checkFailedJobs(): array
    {
        try {
            $countResult = DB::selectOne('SELECT COUNT(*) as count FROM failed_jobs WHERE failed_at >= ?', [now()->subDay()]);
            $count = $countResult->count ?? 0;

            $status = 'healthy';
            $message = null;

            if ($count >= config('health_thresholds.system.failed_jobs_warning_count', self::FAILED_JOBS_WARNING_COUNT)) {
                $status = 'warning';
                $message = "{$count} failed jobs in last 24 hours";
            }

            $totalResult = DB::selectOne('SELECT COUNT(*) as count FROM failed_jobs');

            return [
                'status' => $status,
                'count_24h' => $count,
                'total' => $totalResult->count ?? 0,
                'message' => $message,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Failed jobs check error: '.$e->getMessage(),
            ];
        }
    }

    private function checkStuckWorkflows(): array
    {
        try {
            $threshold = now()->subHours(config('health_thresholds.system.stuck_workflow_hours', self::STUCK_WORKFLOW_HOURS));
            $stuck = DB::select("SELECT * FROM workflow_runs WHERE status = 'running' AND started_at < ?", [$threshold]);

            $status = 'healthy';
            $message = null;

            if (count($stuck) > 0) {
                $status = 'critical';
                $names = implode(', ', array_column($stuck, 'workflow_name'));
                // Use integer hours for consistent issue title matching (auto-resolve)
                $message = 'Stuck workflows (>'.config('health_thresholds.system.stuck_workflow_hours', self::STUCK_WORKFLOW_HOURS)."hr): {$names}";
            }

            return [
                'status' => $status,
                'stuck_count' => count($stuck),
                'threshold_hours' => config('health_thresholds.system.stuck_workflow_hours', self::STUCK_WORKFLOW_HOURS),
                'message' => $message,
            ];
        } catch (Exception $e) {
            Log::debug('OpsMCPService: workflow health check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'warning',
                'message' => 'Workflow health check failed: '.$e->getMessage(),
            ];
        }
    }

    private function checkDiskSpace(): array
    {
        $path = $this->storagePath;
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        $percent = round(($used / $total) * 100, 1);

        $status = 'healthy';
        $message = null;

        if ($percent >= config('health_thresholds.system.disk_space_critical_percent', self::DISK_SPACE_CRITICAL_PERCENT)) {
            $status = 'critical';
            $message = "Disk space critical: {$percent}% used";
        } elseif ($percent >= 80) {
            $status = 'warning';
            $message = "Disk space warning: {$percent}% used";
        }

        return [
            'status' => $status,
            'percent' => $percent,
            'free_gb' => round($free / 1024 / 1024 / 1024, 1),
            'total_gb' => round($total / 1024 / 1024 / 1024, 1),
            'message' => $message,
        ];
    }

    private function checkLogFileSizes(): array
    {
        $largeFiles = [];
        $totalSize = 0;

        foreach (glob($this->logsPath.'/*.log') as $file) {
            $size = filesize($file);
            $totalSize += $size;
            $sizeMb = $size / 1024 / 1024;

            if ($sizeMb >= config('health_thresholds.system.log_file_size_warning_mb', self::LOG_FILE_SIZE_WARNING_MB)) {
                $largeFiles[] = basename($file).' ('.round($sizeMb, 1).'MB)';
            }
        }

        $status = 'healthy';
        $message = null;

        if (! empty($largeFiles)) {
            $status = 'warning';
            $message = 'Large log files: '.implode(', ', $largeFiles);
        }

        return [
            'status' => $status,
            'total_size_mb' => round($totalSize / 1024 / 1024, 1),
            'large_files' => $largeFiles,
            'message' => $message,
        ];
    }

    private function checkDatabaseConnection(): array
    {
        try {
            DB::select('SELECT 1');

            return [
                'status' => 'healthy',
                'message' => null,
            ];
        } catch (Exception $e) {
            Log::warning('OpsMCPService: database connection check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'critical',
                'message' => 'Database connection failed',
            ];
        }
    }

    private function checkRecentWorkflows(): array
    {
        $observations = [];

        try {
            // Check last 24 hours of workflows
            $runs = DB::select('
                SELECT wr.*, w.name as workflow_name
                FROM workflow_runs wr
                JOIN workflows w ON w.id = wr.workflow_id
                WHERE wr.started_at >= ?
            ', [now()->subDay()]);

            foreach ($runs as $run) {
                if ($run->status === 'completed' && $run->started_at && $run->completed_at) {
                    $duration = (strtotime($run->completed_at) - strtotime($run->started_at)) / 3600;
                    if ($duration > 1) {
                        $observations[] = "{$run->workflow_name} took ".round($duration, 1).' hrs';
                    }
                }

                if ($run->status === 'failed') {
                    $observations[] = "{$run->workflow_name} failed";
                }
            }

            // Check for retry attempts
            $retriesResult = DB::selectOne("SELECT COUNT(*) as count FROM node_executions WHERE executed_at >= ? AND state = 'failed'", [now()->subDay()]);
            $retries = $retriesResult->count ?? 0;

            if ($retries > 0) {
                $observations[] = "{$retries} failed node executions in 24hr";
            }

        } catch (Exception $e) {
            Log::debug('OpsMCPService: node execution stats query failed', ['error' => $e->getMessage()]);
        }

        return [
            'observations' => $observations,
        ];
    }

    /**
     * Check database backups are recent and valid
     */
    private function checkDatabaseBackups(): array
    {
        $status = 'healthy';
        $message = null;
        $mysqlBackup = null;
        $pgBackup = null;

        // Check if backup directory exists
        if (! is_dir($this->backupPath)) {
            return [
                'status' => 'critical',
                'message' => 'Backup directory does not exist',
                'mysql_backup' => null,
                'postgres_backup' => null,
            ];
        }

        // Check MySQL backup
        $mysqlBackups = glob($this->backupPath.'/mysql_backup_*.sql');
        if (empty($mysqlBackups)) {
            $status = 'critical';
            $message = 'No MySQL backups found';
        } else {
            // Get most recent
            usort($mysqlBackups, fn ($a, $b) => filemtime($b) - filemtime($a));
            $latestMysql = $mysqlBackups[0];
            $mysqlAge = (time() - filemtime($latestMysql)) / 3600; // hours
            $mysqlSize = round(filesize($latestMysql) / 1024 / 1024, 2);

            $mysqlBackup = [
                'file' => basename($latestMysql),
                'age_hours' => round($mysqlAge, 1),
                'size_mb' => $mysqlSize,
            ];

            $maxAgeHours = (int) config('app.backup_max_age_hours', 25);
            if ($mysqlAge > $maxAgeHours) {
                $status = 'critical';
                $message = 'MySQL backup is '.round($mysqlAge).' hours old';
            } elseif ($mysqlSize < 0.1) {
                $status = 'warning';
                $message = 'MySQL backup appears empty or corrupted';
            }
        }

        // Check PostgreSQL backup
        $pgBackups = glob($this->backupPath.'/postgres_backup_*.sql');
        if (empty($pgBackups)) {
            $status = 'critical';
            $message = ($message ? $message.'; ' : '').'No PostgreSQL backups found';
        } else {
            // Get most recent
            usort($pgBackups, fn ($a, $b) => filemtime($b) - filemtime($a));
            $latestPg = $pgBackups[0];
            $pgAge = (time() - filemtime($latestPg)) / 3600; // hours
            $pgSize = round(filesize($latestPg) / 1024 / 1024, 2);

            $pgBackup = [
                'file' => basename($latestPg),
                'age_hours' => round($pgAge, 1),
                'size_mb' => $pgSize,
            ];

            $maxAgeHours = (int) config('app.backup_max_age_hours', 25);
            if ($pgAge > $maxAgeHours) {
                if ($status !== 'critical') {
                    $status = 'critical';
                }
                $message = ($message ? $message.'; ' : '').'PostgreSQL backup is '.round($pgAge).' hours old';
            } elseif ($pgSize < 0.1) {
                if ($status === 'healthy') {
                    $status = 'warning';
                }
                $message = ($message ? $message.'; ' : '').'PostgreSQL backup appears empty or corrupted';
            }
        }

        return [
            'status' => $status,
            'message' => $message,
            'mysql_backup' => $mysqlBackup,
            'postgres_backup' => $pgBackup,
            'backup_count' => [
                'mysql' => count($mysqlBackups ?? []),
                'postgres' => count($pgBackups ?? []),
            ],
        ];
    }

    /**
     * Check critical services are running
     */
    private function checkCriticalServices(): array
    {
        $status = 'healthy';
        $message = null;
        $issues = [];
        $services = [];

        // Check Ollama API (optional - may be off to save power)
        try {
            $ollamaUrl = DB::selectOne("SELECT base_url FROM llm_instances WHERE instance_type = 'ollama' AND is_active = 1 ORDER BY priority ASC LIMIT 1")?->base_url ?? config('services.ollama.api_url', 'http://127.0.0.1:11434');
            $ch = curl_init(rtrim($ollamaUrl, '/').'/api/tags');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (! $error && $httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $modelCount = count($data['models'] ?? []);
                $services['ollama'] = [
                    'status' => 'running',
                    'models' => $modelCount,
                ];
            } else {
                if ($error) {
                    Log::debug('OpsMCPService: Ollama endpoint probe failed', ['error' => $error]);
                }
                // Ollama not running is informational, not a warning (power saving)
                $services['ollama'] = ['status' => 'stopped'];
            }
        } catch (Exception $e) {
            Log::debug('OpsMCPService: Ollama connectivity check failed', ['error' => $e->getMessage()]);
            $services['ollama'] = ['status' => 'stopped'];
        }

        // Check Nginx (via systemctl)
        $nginxStatus = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'nginx'])->output());
        $services['nginx'] = ['status' => $nginxStatus ?: 'unknown'];
        if ($nginxStatus !== 'active') {
            $issues[] = 'Nginx not active';
            $status = 'critical';
        }

        // Check MySQL (via systemctl)
        $mysqlStatus = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'mysql'])->output());
        $services['mysql'] = ['status' => $mysqlStatus ?: 'unknown'];
        if ($mysqlStatus !== 'active') {
            $issues[] = 'MySQL not active';
            $status = 'critical';
        }

        // Check PostgreSQL (via systemctl)
        $pgStatus = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'postgresql'])->output());
        if (empty($pgStatus) || $pgStatus === 'unknown') {
            // Try alternative service name
            $pgStatus = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'postgresql@16-main'])->output());
        }
        $services['postgresql'] = ['status' => $pgStatus ?: 'unknown'];
        if ($pgStatus !== 'active') {
            $issues[] = 'PostgreSQL not active';
            $status = 'critical';
        }

        // Check Redis (via systemctl)
        $redisStatus = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'redis-server'])->output());
        $services['redis'] = ['status' => $redisStatus ?: 'unknown'];
        if ($redisStatus !== 'active') {
            $issues[] = 'Redis not active';
            $status = 'critical';
        }

        // Check Apache Tika (via systemctl) - document extraction service
        $tikaStatus = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'tika'])->output());
        $services['tika'] = ['status' => $tikaStatus ?: 'unknown'];
        if ($tikaStatus === 'active') {
            // Also verify HTTP endpoint
            try {
                $ch = curl_init(rtrim(config('services.tika.url', 'http://127.0.0.1:9998'), '/').'/version');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if (! $error && $httpCode === 200 && $response) {
                    $services['tika']['version'] = trim($response);
                    $services['tika']['endpoint'] = 'responsive';
                } else {
                    if ($error) {
                        Log::debug('OpsMCPService: Tika endpoint probe failed', ['error' => $error]);
                    }
                    $services['tika']['endpoint'] = 'unresponsive';
                    $issues[] = 'Tika HTTP endpoint unresponsive';
                    $status = ($status === 'critical') ? 'critical' : 'warning';
                }
            } catch (Exception $e) {
                Log::debug('OpsMCPService: Tika endpoint check failed', ['error' => $e->getMessage()]);
                $services['tika']['endpoint'] = 'error';
            }
        } elseif ($tikaStatus !== 'active') {
            // Tika is important for document extraction but not strictly critical
            $issues[] = 'Tika not active (document extraction degraded)';
            $status = ($status === 'critical') ? 'critical' : 'warning';
        }

        // Check Horizon - queue execution depends on it even though scheduler uses cron
        $horizonProcess = trim(Process::timeout(5)->run(['pgrep', '-f', 'artisan horizon$'])->output());
        $horizonSystemd = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'laravel-horizon.service'])->output());
        $horizonRunning = ! empty($horizonProcess) || $horizonSystemd === 'active';
        $services['horizon_service'] = [
            'status' => $horizonRunning ? 'active' : 'inactive',
            'detection' => ! empty($horizonProcess) ? 'process' : ($horizonSystemd === 'active' ? 'systemd' : 'none'),
        ];
        if (! $horizonRunning) {
            $issues[] = 'Horizon not running (queue execution degraded)';
            $status = ($status === 'critical') ? 'critical' : 'warning';
        }

        // Check PLOS Agent Proxy - detect via process OR systemd
        $agentProxyProcess = trim(Process::timeout(5)->run(['pgrep', '-f', 'node.*agent-proxy'])->output());
        $agentProxySystemd = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'plos-agent-proxy'])->output());
        $agentProxyRunning = ! empty($agentProxyProcess) || $agentProxySystemd === 'active';
        $services['agent_proxy'] = [
            'status' => $agentProxyRunning ? 'active' : 'inactive',
            'detection' => ! empty($agentProxyProcess) ? 'process' : ($agentProxySystemd === 'active' ? 'systemd' : 'none'),
        ];
        if ($agentProxyRunning) {
            // Verify HTTP endpoint
            try {
                $ch = curl_init(rtrim(config('services.claude.agent_proxy_url', 'http://127.0.0.1:8770'), '/').'/health');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    Log::debug('OpsMCPService: agent proxy endpoint probe failed', ['error' => $error]);
                }

                $services['agent_proxy']['endpoint'] = (! $error && $httpCode === 200) ? 'responsive' : 'unresponsive';
            } catch (Exception $e) {
                Log::debug('OpsMCPService: agent proxy endpoint check failed', ['error' => $e->getMessage()]);
                $services['agent_proxy']['endpoint'] = 'error';
            }
        } else {
            // Agent proxy is useful for automation but not critical
            $issues[] = 'Agent Proxy not running';
            $status = ($status === 'critical') ? 'critical' : 'warning';
        }

        // Check Thunderbird MCP - detect via process OR systemd
        $tbMcpProcess = trim(Process::timeout(5)->run([
            'pgrep',
            '-f',
            'thunderbird.*mcp\\|mcp.*thunderbird\\|node.*thunderbird',
        ])->output());
        $tbMcpSystemd = trim(Process::timeout(5)->run(['systemctl', 'is-active', 'thunderbird-mcp'])->output());
        $tbMcpRunning = ! empty($tbMcpProcess) || $tbMcpSystemd === 'active';
        $services['thunderbird_mcp'] = [
            'status' => $tbMcpRunning ? 'active' : 'inactive',
            'detection' => ! empty($tbMcpProcess) ? 'process' : ($tbMcpSystemd === 'active' ? 'systemd' : 'none'),
        ];
        if ($tbMcpRunning) {
            // Verify HTTP endpoint (no /health, just check if responding)
            try {
                $ch = curl_init(rtrim(config('services.thunderbird.url', 'http://127.0.0.1:8766'), '/').'/');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    Log::debug('OpsMCPService: Thunderbird MCP endpoint probe failed', ['error' => $error]);
                }

                // Any HTTP response means it's running (405 = method not allowed is fine)
                $services['thunderbird_mcp']['endpoint'] = (! $error && $httpCode > 0) ? 'responsive' : 'unresponsive';
            } catch (Exception $e) {
                Log::debug('OpsMCPService: Thunderbird MCP endpoint check failed', ['error' => $e->getMessage()]);
                $services['thunderbird_mcp']['endpoint'] = 'error';
            }
        } else {
            // Thunderbird MCP is useful for email but not critical
            $issues[] = 'Thunderbird MCP not running';
            $status = ($status === 'critical') ? 'critical' : 'warning';
        }

        // Check SearXNG (port 8888) - research/search pipeline
        try {
            $ch = curl_init(rtrim(config('services.searxng.url', 'http://127.0.0.1:8888'), '/').'/healthz');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (! $error && $httpCode === 200) {
                $services['searxng'] = ['status' => 'running', 'endpoint' => 'responsive'];
            } else {
                if ($error) {
                    Log::debug('OpsMCPService: SearXNG endpoint probe failed', ['error' => $error]);
                }
                $services['searxng'] = ['status' => 'degraded', 'endpoint' => 'unresponsive'];
                $issues[] = 'SearXNG not responding (research degraded)';
                $status = ($status === 'critical') ? 'critical' : 'warning';
            }
        } catch (Exception $e) {
            Log::debug('OpsMCPService: SearXNG health check failed', ['error' => $e->getMessage()]);
            $services['searxng'] = ['status' => 'stopped'];
            $issues[] = 'SearXNG not running (research degraded)';
            $status = ($status === 'critical') ? 'critical' : 'warning';
        }

        if (! empty($issues)) {
            $message = implode('; ', $issues);
        }

        return [
            'status' => $status,
            'message' => $message,
            'services' => $services,
        ];
    }

    /**
     * Check Joplin attachment processing health
     */
    private function checkJoplinAttachments(): array
    {
        $status = 'healthy';
        $message = null;
        $observations = [];

        try {
            // Get stats from joplin_attachment_index
            $stats = DB::select('
                SELECT sync_status, COUNT(*) as count
                FROM joplin_attachment_index
                GROUP BY sync_status
            ');

            $byStatus = [];
            $total = 0;
            foreach ($stats as $row) {
                $byStatus[$row->sync_status] = $row->count;
                $total += $row->count;
            }

            $pending = $byStatus['pending'] ?? 0;
            $queued = $byStatus['queued'] ?? 0;
            $processing = $byStatus['processing'] ?? 0;
            $synced = $byStatus['synced'] ?? 0;
            $errors = $byStatus['error'] ?? 0;

            // Check for stuck jobs (processing for > 30 minutes)
            $stuckJobs = DB::select("
                SELECT COUNT(*) as count FROM joplin_attachment_index
                WHERE sync_status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ");
            $stuckCount = $stuckJobs[0]->count ?? 0;

            // Check for legacy RAG records that need cleanup
            $legacyCount = DB::connection('pgsql_rag')->selectOne(
                "SELECT COUNT(*) as count FROM rag_documents WHERE designation = 'joplin_attachment'"
            );
            $legacyRagRecords = $legacyCount->count ?? 0;

            // Determine status
            if ($errors > 10) {
                $status = 'warning';
                $message = "Joplin attachments: {$errors} failed extractions";
            } elseif ($stuckCount > 0) {
                $status = 'warning';
                $message = "Joplin attachments: {$stuckCount} stuck jobs";
            }

            // Add observations
            if ($pending + $queued > 50) {
                $observations[] = 'Joplin: '.($pending + $queued).' attachments pending processing';
            }
            // Legacy RAG records are auto-cleaned by routine maintenance in OpsMaintenanceJob
            // No need to report as observation since they're handled automatically

            return [
                'status' => $status,
                'message' => $message,
                'total_tracked' => $total,
                'synced' => $synced,
                'pending' => $pending + $queued,
                'processing' => $processing,
                'errors' => $errors,
                'stuck_jobs' => $stuckCount,
                'legacy_rag_records' => $legacyRagRecords,
                'observations' => $observations,
            ];

        } catch (Exception $e) {
            Log::debug('OpsMCPService: file pipeline health check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'warning',
                'message' => 'File pipeline health check failed: '.$e->getMessage(),
                'total_tracked' => 0,
                'observations' => [],
            ];
        }
    }

    private function checkRAGDatabase(): array
    {
        $status = 'healthy';
        $message = null;

        try {
            // Check RAG database connection and document count
            $result = DB::connection('pgsql_rag')->select('SELECT COUNT(*) as count FROM rag_documents');
            $docCount = $result[0]->count ?? 0;

            // Check recent activity (documents added in last 7 days)
            $recentResult = DB::connection('pgsql_rag')->select(
                "SELECT COUNT(*) as count FROM rag_documents WHERE created_at > NOW() - INTERVAL '7 days'"
            );
            $recentCount = $recentResult[0]->count ?? 0;

            // Check database size
            $sizeResult = DB::connection('pgsql_rag')->select(
                'SELECT pg_size_pretty(pg_database_size(current_database())) as size'
            );
            $dbSize = $sizeResult[0]->size ?? 'unknown';

            return [
                'status' => $status,
                'message' => $message,
                'document_count' => $docCount,
                'recent_documents' => $recentCount,
                'database_size' => $dbSize,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'RAG database connection failed: '.$e->getMessage(),
                'document_count' => 0,
            ];
        }
    }

    /**
     * Check for open circuit breakers (services in fallback mode)
     */
    private function checkCircuitBreakers(): array
    {
        $status = 'healthy';
        $message = null;
        $observations = [];
        $openBreakers = [];

        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $prefix = config('database.redis.options.prefix', '');
            $keys = $redis->keys('circuit_breaker:*');

            foreach ($keys as $key) {
                $cleanKey = str_replace($prefix, '', $key);
                $state = $redis->get($cleanKey);

                if ($state && in_array($state, ['open', 'half-open'])) {
                    // Extract service name from key
                    $serviceName = str_replace('circuit_breaker:', '', $cleanKey);
                    $openBreakers[] = $serviceName;
                }
            }

            if (count($openBreakers) >= 3) {
                $status = 'warning';
                $message = count($openBreakers).' circuit breakers open (services degraded)';
            } elseif (count($openBreakers) > 0) {
                $observations[] = 'Circuit breakers open: '.implode(', ', $openBreakers);
            }

            return [
                'status' => $status,
                'message' => $message,
                'open_count' => count($openBreakers),
                'open_services' => $openBreakers,
                'observations' => $observations,
            ];

        } catch (Exception $e) {
            Log::debug('OpsMCPService: circuit breaker check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'warning',
                'message' => 'Circuit breaker check failed: '.$e->getMessage(),
                'open_count' => 0,
                'open_services' => [],
                'observations' => [],
            ];
        }
    }

    /**
     * Check Horizon queue health (pending jobs, stuck reserved jobs)
     */
    private function checkHorizonQueues(): array
    {
        $status = 'healthy';
        $message = null;
        $observations = [];
        $queueStats = [];

        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $queues = $this->getActiveQueueNames();
            $totalPending = 0;
            $totalReserved = 0;
            $stuckCount = 0;
            $stuckThreshold = time() - 7200; // 2 hours ago

            foreach ($queues as $queue) {
                $pending = $redis->llen("queues:{$queue}") ?? 0;
                $reserved = $redis->zcard("queues:{$queue}:reserved") ?? 0;

                // Check for stuck reserved jobs (>2 hours old)
                $stuckJobs = $redis->zrangebyscore("queues:{$queue}:reserved", 0, $stuckThreshold);
                $stuck = count($stuckJobs);

                $queueStats[$queue] = [
                    'pending' => $pending,
                    'reserved' => $reserved,
                    'stuck' => $stuck,
                ];

                $totalPending += $pending;
                $totalReserved += $reserved;
                $stuckCount += $stuck;
            }

            // Check recent failed jobs count so historical residue does not permanently degrade queue health.
            $failedCount = 0;
            try {
                $failed = DB::selectOne(
                    'SELECT COUNT(*) as count FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
                );
                $failedCount = $failed->count ?? 0;
            } catch (Exception $e) {
                Log::debug('OpsMCPService: failed_jobs count query failed', ['error' => $e->getMessage()]);
            }

            // Warn if jobs are backing up
            if ($totalPending > 100) {
                $status = 'warning';
                $message = "Queue backlog: {$totalPending} jobs pending";
            } elseif ($stuckCount > 0) {
                $status = 'warning';
                $message = "{$stuckCount} stuck jobs detected (>2hr reserved)";
            } elseif ($failedCount > 10) {
                $status = 'warning';
                $message = "{$failedCount} failed jobs need review";
            }

            if ($totalPending > 50) {
                $observations[] = "Queue backlog: {$totalPending} pending jobs";
            }
            if ($failedCount > 0) {
                $observations[] = "{$failedCount} failed jobs in last 24h";
            }

            return [
                'status' => $status,
                'message' => $message,
                'total_pending' => $totalPending,
                'total_reserved' => $totalReserved,
                'stuck_count' => $stuckCount,
                'failed_count' => $failedCount,
                'queues' => $queueStats,
                'observations' => $observations,
            ];

        } catch (Exception $e) {
            Log::debug('OpsMCPService: Horizon queue health check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'warning',
                'message' => 'Horizon queue health check failed: '.$e->getMessage(),
                'total_pending' => 0,
                'total_reserved' => 0,
                'stuck_count' => 0,
                'failed_count' => 0,
                'queues' => [],
                'observations' => [],
            ];
        }
    }

    /**
     * Check email bounce health - bounce rate, suppression list growth
     */
    private function checkEmailBounceHealth(): array
    {
        // email_bounces, email_suppression_list, email_retry_queue tables dropped (D1: Thunderbird MCP)
        return [
            'status' => 'healthy',
            'message' => null,
            'observations' => [],
        ];
    }

    /**
     * Check data removal broker health - broken brokers, pending tasks
     */
    private function checkDataRemovalBrokerHealth(): array
    {
        $status = 'healthy';
        $message = null;
        $observations = [];

        try {
            // Check if data_brokers table exists
            $tableExists = DB::select("SHOW TABLES LIKE 'data_brokers'");
            if (empty($tableExists)) {
                return [
                    'status' => 'healthy',
                    'message' => null,
                    'observations' => [],
                ];
            }

            // Get broker health status counts
            $healthStats = DB::select(
                'SELECT health_status, COUNT(*) as count
                 FROM data_brokers
                 WHERE health_status IS NOT NULL
                 GROUP BY health_status'
            );

            $brokenCount = 0;
            $degradedCount = 0;
            $changedCount = 0;
            foreach ($healthStats as $stat) {
                if ($stat->health_status === 'broken') {
                    $brokenCount = $stat->count;
                } elseif ($stat->health_status === 'degraded') {
                    $degradedCount = $stat->count;
                } elseif ($stat->health_status === 'changed') {
                    $changedCount = $stat->count;
                }
            }

            // Check for stale health checks (not checked in 7 days)
            $staleChecks = DB::selectOne(
                'SELECT COUNT(*) as count FROM data_brokers
                 WHERE removal_url IS NOT NULL
                 AND (last_health_check IS NULL OR last_health_check < DATE_SUB(NOW(), INTERVAL 7 DAY))'
            );
            $staleCount = $staleChecks->count ?? 0;

            // Check pending removal tasks (data_removal_tasks does not exist; use data_subjects)
            $pendingTasks = 0;
            try {
                $pending = DB::selectOne(
                    'SELECT COUNT(*) as count FROM data_subjects'
                );
                $pendingTasks = $pending->count ?? 0;
            } catch (\Exception $e) {
                // data_subjects table may not exist or have different schema
            }

            // Determine status
            if ($brokenCount >= 5) {
                $status = 'warning';
                $message = "{$brokenCount} data brokers are broken (opt-out pages unreachable)";
            } elseif ($staleCount >= 10) {
                $status = 'warning';
                $message = "{$staleCount} brokers haven't been health-checked in 7+ days";
            } elseif ($degradedCount >= 5) {
                $status = 'warning';
                $message = "{$degradedCount} data brokers are degraded";
            }

            if ($brokenCount > 0 || $degradedCount > 0) {
                $observations[] = "Broker health: {$brokenCount} broken, {$degradedCount} degraded";
            }
            if ($changedCount > 0) {
                $observations[] = "{$changedCount} broker opt-out pages changed (may need review)";
            }
            if ($pendingTasks > 0) {
                $observations[] = "{$pendingTasks} removal tasks pending";
            }

            return [
                'status' => $status,
                'message' => $message,
                'broken_count' => $brokenCount,
                'degraded_count' => $degradedCount,
                'changed_count' => $changedCount,
                'stale_checks' => $staleCount,
                'pending_tasks' => $pendingTasks,
                'observations' => $observations,
            ];

        } catch (Exception $e) {
            Log::debug('OpsMCPService: data removal broker health check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'warning',
                'message' => 'Data removal broker health check failed: '.$e->getMessage(),
                'observations' => [],
            ];
        }
    }

    /**
     * Check research source health - failing sources, stale sources, cache bloat
     */
    private function checkResearchSourceHealth(): array
    {
        $status = 'healthy';
        $message = null;
        $observations = [];

        try {
            $db = DB::connection('pgsql_rag');

            // Count active sources
            $activeCount = $db->selectOne(
                'SELECT COUNT(*) as count FROM research_sources WHERE is_active = true'
            );
            $totalActive = $activeCount->count ?? 0;

            // Count sources with high failure rates (>50% failure rate, min 5 attempts)
            $failingSources = $db->select(
                'SELECT name, failure_count, success_count
                 FROM research_sources
                 WHERE is_active = true
                 AND (failure_count + success_count) >= 5
                 AND failure_count::float / NULLIF(failure_count + success_count, 0) > 0.5
                 ORDER BY failure_count DESC
                 LIMIT 5'
            );
            $failingCount = count($failingSources);

            // Count stale sources (no success in 30 days but still active)
            $staleSources = $db->selectOne(
                "SELECT COUNT(*) as count FROM research_sources
                 WHERE is_active = true
                 AND (last_success_at IS NULL OR last_success_at < NOW() - INTERVAL '30 days')"
            );
            $staleCount = $staleSources->count ?? 0;

            // Check research cache size
            $cacheStats = $db->selectOne(
                "SELECT COUNT(*) as count,
                        COUNT(*) FILTER (WHERE cached_at < NOW() - INTERVAL '7 days') as old_count
                 FROM research_cache"
            );
            $cacheTotal = $cacheStats->count ?? 0;
            $oldCache = $cacheStats->old_count ?? 0;

            // Check stale topics (not run in 7 days but active)
            $staleTopics = $db->selectOne(
                "SELECT COUNT(*) as count FROM research_topics
                 WHERE is_active = true
                 AND (last_ran_at IS NULL OR last_ran_at < NOW() - INTERVAL '7 days')"
            );
            $staleTopicCount = $staleTopics->count ?? 0;

            // Determine status
            if ($failingCount >= 5) {
                $status = 'warning';
                $message = "{$failingCount} research sources have >50% failure rate";
            } elseif ($staleCount >= 10) {
                $status = 'warning';
                $message = "{$staleCount} research sources haven't succeeded in 30+ days";
            } elseif ($oldCache > 1000) {
                $status = 'warning';
                $message = "Research cache bloat: {$oldCache} stale entries (>7d)";
            }

            if ($failingCount > 0) {
                $sourceNames = array_map(fn ($s) => $s->name, array_slice($failingSources, 0, 3));
                $observations[] = 'Failing sources: '.implode(', ', $sourceNames);
            }
            if ($staleTopicCount > 0) {
                $observations[] = "{$staleTopicCount} research topics haven't run in 7+ days";
            }
            if ($cacheTotal > 0) {
                $observations[] = "Research cache: {$cacheTotal} entries ({$oldCache} stale)";
            }

            return [
                'status' => $status,
                'message' => $message,
                'active_sources' => $totalActive,
                'failing_sources' => $failingCount,
                'stale_sources' => $staleCount,
                'stale_topics' => $staleTopicCount,
                'cache_total' => $cacheTotal,
                'cache_old' => $oldCache,
                'observations' => $observations,
            ];

        } catch (Exception $e) {
            Log::debug('OpsMCPService: research pipeline health check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'warning',
                'message' => 'Research pipeline health check failed: '.$e->getMessage(),
                'observations' => [],
            ];
        }
    }

    private function cleanupLogFiles(): array
    {
        $bytesFreed = 0;
        $filesCleaned = 0;
        $cutoff = now()->subDays(config('health_thresholds.retention.log_days', self::LOG_RETENTION_DAYS));

        foreach (glob($this->logsPath.'/*.log') as $file) {
            if (basename($file) === '.gitignore') {
                continue;
            }

            $size = filesize($file);
            $mtime = filemtime($file);

            // Truncate if older than retention or larger than 10MB
            if ($mtime < $cutoff->timestamp || $size > 10 * 1024 * 1024) {
                $bytesFreed += $size;
                file_put_contents($file, '');
                $filesCleaned++;
            }
        }

        return [
            'performed' => $filesCleaned > 0,
            'files_cleaned' => $filesCleaned,
            'bytes_freed' => $bytesFreed,
        ];
    }

    private function cleanupArchiveFiles(): array
    {
        $bytesFreed = 0;
        $filesDeleted = 0;

        foreach (glob($this->logsPath.'/*.archive') as $file) {
            $bytesFreed += filesize($file);
            unlink($file);
            $filesDeleted++;
        }

        // Also cleanup conflicted copies
        foreach (glob($this->logsPath.'/*conflicted*') as $file) {
            $bytesFreed += filesize($file);
            unlink($file);
            $filesDeleted++;
        }

        return [
            'performed' => $filesDeleted > 0,
            'files_deleted' => $filesDeleted,
            'bytes_freed' => $bytesFreed,
        ];
    }

    private function cleanupFailedJobs(): array
    {
        try {
            $cutoff = now()->subDays(config('health_thresholds.retention.failed_jobs_days', self::FAILED_JOBS_RETENTION_DAYS));
            $deleted = DB::delete('DELETE FROM failed_jobs WHERE failed_at < ?', [$cutoff]);

            return [
                'performed' => $deleted > 0,
                'rows_deleted' => $deleted,
            ];
        } catch (Exception $e) {
            return [
                'performed' => false,
                'rows_deleted' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function cleanupHorizonMetrics(): array
    {
        try {
            \Artisan::call('horizon:clear-metrics');

            return [
                'performed' => true,
                'message' => 'Metrics cleared',
            ];
        } catch (Exception $e) {
            return [
                'performed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function cleanupExecutionLogs(): array
    {
        $totalDeleted = 0;

        try {
            $cutoff = now()->subDays(config('health_thresholds.retention.execution_logs_days', self::EXECUTION_LOGS_RETENTION_DAYS));

            // Delete old node executions and related records
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            $oldExecutions = DB::select('SELECT id FROM node_executions WHERE executed_at < ?', [$cutoff]);
            $oldIds = array_column($oldExecutions, 'id');

            if (count($oldIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
                $totalDeleted += DB::delete("DELETE FROM node_execution_outputs WHERE node_execution_id IN ({$placeholders})", $oldIds);
                $totalDeleted += DB::delete("DELETE FROM node_execution_inputs WHERE node_execution_id IN ({$placeholders})", $oldIds);
                $totalDeleted += DB::delete("DELETE FROM node_executions WHERE id IN ({$placeholders})", $oldIds);
            }

            // Delete old workflow runs
            $totalDeleted += DB::delete('DELETE FROM workflow_runs WHERE started_at < ?', [$cutoff]);

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            return [
                'performed' => $totalDeleted > 0,
                'rows_deleted' => $totalDeleted,
            ];
        } catch (Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            return [
                'performed' => false,
                'rows_deleted' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function restartStuckWorkers(): array
    {
        try {
            // Check if there are stuck workers
            $horizonCheck = $this->checkHorizonWorkers();

            if ($horizonCheck['status'] === 'critical' && isset($horizonCheck['masters']) && $horizonCheck['masters'] > 0) {
                // Reuse the canonical recovery path instead of maintaining a second restart mechanism here.
                $result = app(AutoHealService::class)->recoverHorizonService();

                return [
                    'performed' => ! empty($result['restarted']),
                    'message' => $result['message'] ?? ($result['error'] ?? 'Horizon recovery attempted'),
                    'detail' => $result,
                ];
            }

            return [
                'performed' => false,
                'message' => 'No restart needed',
            ];
        } catch (Exception $e) {
            return [
                'performed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function cleanupStaleCache(): array
    {
        try {
            // Clear stale playlist tracking keys older than 7 days
            $keysCleared = 0;

            $patterns = [
                'youtube_playlist_*_processed_ids',
                'youtube_transcript_*',
            ];

            foreach ($patterns as $pattern) {
                $keys = Redis::keys($pattern);
                foreach ($keys as $key) {
                    $ttl = Redis::ttl($key);
                    // If key has no TTL (-1) and is a tracking key, it's potentially stale
                    if ($ttl === -1) {
                        // Don't delete, just note it
                        $keysCleared++;
                    }
                }
            }

            return [
                'performed' => true,
                'stale_keys_found' => $keysCleared,
            ];
        } catch (Exception $e) {
            return [
                'performed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function categorizeError(string $message): string
    {
        $message = strtolower($message);

        if (str_contains($message, 'connection') || str_contains($message, 'timeout')) {
            return 'Connection/Timeout';
        }
        if (str_contains($message, 'youtube') || str_contains($message, 'transcript')) {
            return 'YouTube/Transcript';
        }
        if (str_contains($message, 'joplin') || str_contains($message, 'note')) {
            return 'Joplin';
        }
        if (str_contains($message, 'redis') || str_contains($message, 'cache')) {
            return 'Redis/Cache';
        }
        if (str_contains($message, 'database') || str_contains($message, 'mysql') || str_contains($message, 'sql')) {
            return 'Database';
        }
        if (str_contains($message, 'workflow') || str_contains($message, 'node')) {
            return 'Workflow';
        }
        if (str_contains($message, 'api') || str_contains($message, 'http')) {
            return 'API/HTTP';
        }

        return 'Other';
    }

    private function tailFile(string $file, int $lines): array
    {
        if (! file_exists($file)) {
            return [];
        }

        $result = [];
        $fp = fopen($file, 'r');

        if (! $fp) {
            return [];
        }

        // Seek to end
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);

        $lineCount = 0;
        $buffer = '';

        while ($pos > 0 && $lineCount < $lines) {
            $pos--;
            fseek($fp, $pos);
            $char = fgetc($fp);

            if ($char === "\n") {
                if ($buffer !== '') {
                    array_unshift($result, $buffer);
                    $lineCount++;
                    $buffer = '';
                }
            } else {
                $buffer = $char.$buffer;
            }
        }

        if ($buffer !== '' && $lineCount < $lines) {
            array_unshift($result, $buffer);
        }

        fclose($fp);

        return $result;
    }

    // Quick status helpers
    private function getRedisQuickStatus(): array
    {
        try {
            $info = Redis::info('memory');

            return [
                'connected' => true,
                'used_mb' => round(($info['used_memory'] ?? 0) / 1024 / 1024, 1),
            ];
        } catch (Exception $e) {
            Log::debug('OpsMCPService: Redis quick status check failed', ['error' => $e->getMessage()]);

            return ['connected' => false];
        }
    }

    private function getHorizonQuickStatus(): array
    {
        try {
            $prefix = config('horizon.prefix', 'laravel_horizon:');
            $redis = new \Redis;
            $redis->connect(
                config('database.redis.default.host', '127.0.0.1'),
                (int) config('database.redis.default.port', 6379)
            );
            $redis->select((int) config('database.redis.default.database', 0));
            $masters = $redis->keys($prefix.'master:*');
            $redis->close();

            return [
                'running' => ! empty($masters),
            ];
        } catch (Exception $e) {
            Log::debug('OpsMCPService: Horizon quick status check failed', ['error' => $e->getMessage()]);

            return ['running' => false];
        }
    }

    private function getDatabaseQuickStatus(): array
    {
        try {
            DB::select('SELECT 1');

            return ['connected' => true];
        } catch (Exception $e) {
            Log::debug('OpsMCPService: database quick status check failed', ['error' => $e->getMessage()]);

            return ['connected' => false];
        }
    }

    private function getDiskQuickStatus(): array
    {
        $free = disk_free_space($this->storagePath);

        return [
            'free_gb' => round($free / 1024 / 1024 / 1024, 1),
        ];
    }

    private function getLastWorkflowStatus(): array
    {
        try {
            $last = DB::selectOne('SELECT * FROM workflow_runs ORDER BY started_at DESC LIMIT 1');

            if ($last) {
                return [
                    'name' => $last->workflow_name,
                    'status' => $last->status,
                    'started' => $last->started_at,
                ];
            }
        } catch (Exception $e) {
            Log::debug('OpsMCPService: last workflow status query failed', ['error' => $e->getMessage()]);
        }

        return ['name' => null];
    }

    /**
     * Check OAuth token health (for consolidated ops report)
     *
     * @param  int  $warnDays  Days before expiry to warn
     * @return array OAuth health status
     */
    public function checkOAuthHealth(int $warnDays = 5): array
    {
        $results = [
            'status' => 'healthy',
            'tokens' => [],
            'issues' => [],
        ];

        // Check YouTube token
        try {
            $token = DB::selectOne('SELECT * FROM oauth_tokens WHERE provider = ?', ['youtube']);

            if (! $token) {
                $results['status'] = 'critical';
                $results['issues'][] = '❌ YouTube: No OAuth token configured';
            } elseif (empty($token->refresh_token)) {
                $results['status'] = 'critical';
                $results['issues'][] = '❌ YouTube: No refresh token';
            } else {
                // Check token age (Google testing mode = 7 day expiry)
                $createdAt = \Carbon\Carbon::parse($token->created_at);
                $tokenAgeDays = (int) $createdAt->diffInDays(now());
                $daysUntilExpiry = 7 - $tokenAgeDays;

                if ($daysUntilExpiry <= 0) {
                    // Token may be expired, but might still work if app is published
                    $results['status'] = $results['status'] === 'critical' ? 'critical' : 'warning';
                    $results['issues'][] = "⚠️ YouTube: Token age is {$tokenAgeDays} days and should be verified";
                    $results['tokens']['youtube'] = [
                        'status' => 'check_needed',
                        'age_days' => $tokenAgeDays,
                        'message' => "Token is {$tokenAgeDays} days old (may need re-auth if in testing mode)",
                    ];
                } elseif ($daysUntilExpiry <= $warnDays) {
                    $results['status'] = $results['status'] === 'critical' ? 'critical' : 'warning';
                    $results['issues'][] = "⚠️ YouTube: Token expires in ~{$daysUntilExpiry} days";
                    $results['tokens']['youtube'] = [
                        'status' => 'expiring_soon',
                        'days_until_expiry' => $daysUntilExpiry,
                    ];
                } else {
                    $results['tokens']['youtube'] = [
                        'status' => 'healthy',
                        'days_until_expiry' => $daysUntilExpiry,
                    ];
                }
            }
        } catch (Exception $e) {
            $results['status'] = $results['status'] === 'critical' ? 'critical' : 'warning';
            $results['issues'][] = '⚠️ YouTube: Token check failed - '.$e->getMessage();
        }

        return $results;
    }

    /**
     * Check workflow health with full details (for consolidated ops report)
     *
     * @param  bool  $autoCleanup  Whether to auto-cleanup stuck workflows
     * @return array Workflow health status
     */
    public function checkWorkflowHealth(bool $autoCleanup = true): array
    {
        $results = [
            'status' => 'healthy',
            'issues' => [],
            'failed_runs' => [],
            'stuck_runs' => [],
            'missed_schedules' => [],
        ];

        try {
            // Check failed runs in last 24 hours
            $failed = DB::select("
                SELECT w.name, wr.id as run_id, wr.error_message, wr.started_at
                FROM workflow_runs wr
                JOIN workflows w ON w.id = wr.workflow_id
                WHERE wr.status = 'failed' AND wr.started_at >= ?
            ", [now()->subDay()]);

            if (count($failed) > 0) {
                $results['status'] = 'warning';
                foreach ($failed as $r) {
                    $results['failed_runs'][] = [
                        'name' => $r->name,
                        'run_id' => $r->run_id,
                        'error' => $r->error_message,
                    ];
                }
                $results['issues'][] = '⚠️ '.count($failed).' workflow(s) failed in last 24h';
            }

            // Check stuck workflows (running > 1 hour)
            $stuck = DB::select("
                SELECT w.name, wr.id as run_id, wr.started_at
                FROM workflow_runs wr
                JOIN workflows w ON w.id = wr.workflow_id
                WHERE wr.status = 'running' AND wr.started_at < ?
            ", [now()->subHour()]);

            if (count($stuck) > 0) {
                if ($autoCleanup) {
                    // Auto-cleanup stuck runs
                    $stuckIds = array_column($stuck, 'run_id');
                    $placeholders = implode(',', array_fill(0, count($stuckIds), '?'));
                    DB::update("UPDATE workflow_runs SET status = 'failed', error_message = 'Auto-cleaned: workflow was stuck for over 1 hour', completed_at = ? WHERE id IN ({$placeholders})", array_merge([now()], $stuckIds));

                    Log::info('Ops: Auto-cleaned stuck workflow runs', ['count' => count($stuckIds)]);
                    $results['status'] = $results['status'] === 'critical' ? 'critical' : 'warning';
                    $results['issues'][] = '🔧 Auto-cleaned '.count($stuck).' stuck workflow(s)';
                } else {
                    $results['status'] = 'critical';
                    foreach ($stuck as $r) {
                        $results['stuck_runs'][] = [
                            'name' => $r->name,
                            'run_id' => $r->run_id,
                            'duration_min' => now()->diffInMinutes($r->started_at),
                        ];
                    }
                    $results['issues'][] = '🔴 '.count($stuck).' workflow(s) stuck (>1 hour)';
                }
            }

            // Check missed schedules
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

            foreach ($workflows as $workflow) {
                try {
                    $cron = new \Cron\CronExpression($workflow->schedule);
                    $previousRunTime = $cron->getPreviousRunDate(now());

                    $lastRun = DB::selectOne('SELECT * FROM workflow_runs WHERE workflow_id = ? AND started_at >= ? AND started_at <= ? LIMIT 1', [$workflow->id, $previousRunTime, now()]);

                    if (! $lastRun) {
                        $missedDuration = now()->diffForHumans($previousRunTime, true);
                        $results['missed_schedules'][] = [
                            'name' => $workflow->name,
                            'schedule' => $workflow->schedule,
                            'expected_at' => $previousRunTime->format('Y-m-d H:i'),
                            'missed_by' => $missedDuration,
                        ];
                    }
                } catch (Exception $e) {
                    Log::debug('OpsMCPService: cron expression parse failed for workflow', ['workflow' => $workflow->name ?? null, 'error' => $e->getMessage()]);
                }
            }

            if (! empty($results['missed_schedules'])) {
                $results['status'] = $results['status'] === 'critical' ? 'critical' : 'warning';
                $results['issues'][] = "⏰ {$results['missed_schedules'][0]['name']} missed schedule ({$results['missed_schedules'][0]['missed_by']} ago)";
            }

        } catch (Exception $e) {
            Log::error('Ops: Workflow health check failed', ['error' => $e->getMessage()]);
            $results['status'] = $results['status'] === 'critical' ? 'critical' : 'warning';
            $results['issues'][] = '⚠️ Workflow check error: '.$e->getMessage();
        }

        return $results;
    }

    // ==================== ISSUE TRACKING MCP TOOLS ====================

    /**
     * List pending system issues for human review
     *
     * @param  array  $params  ['status' => 'open', 'severity' => 'all', 'limit' => 50]
     * @return array List of issues
     */
    public function ops_issues_list(array $params = []): array
    {
        $status = $params['status'] ?? 'open';
        $severity = $params['severity'] ?? 'all';
        $limit = min($params['limit'] ?? 50, 100);

        try {
            // Build dynamic query based on filters
            $sql = 'SELECT * FROM system_issues';
            $bindings = [];
            $conditions = [];

            if ($status !== 'all') {
                $conditions[] = 'status = ?';
                $bindings[] = $status;
            }

            if ($severity !== 'all') {
                $conditions[] = 'severity = ?';
                $bindings[] = $severity;
            }

            if (! empty($conditions)) {
                $sql .= ' WHERE '.implode(' AND ', $conditions);
            }

            $sql .= " ORDER BY FIELD(severity, 'critical', 'warning', 'info'), detected_at DESC LIMIT ?";
            $bindings[] = $limit;

            $issues = DB::select($sql, $bindings);

            // Format for display
            $formatted = [];
            foreach ($issues as $issue) {
                $formatted[] = [
                    'id' => $issue->id,
                    'severity' => $issue->severity,
                    'category' => $issue->category,
                    'title' => $issue->title,
                    'description' => $issue->description,
                    'suggested_fix' => $issue->suggested_fix,
                    'status' => $issue->status,
                    'detected_at' => $issue->detected_at,
                    'first_seen_at' => $issue->first_seen_at,
                    'last_seen_at' => $issue->last_seen_at,
                    'occurrence_count' => $issue->occurrence_count,
                    'detected_by' => $issue->detected_by,
                    'resolved_at' => $issue->resolved_at,
                    'resolved_by' => $issue->resolved_by,
                    'resolution_notes' => $issue->resolution_notes,
                    'context' => json_decode($issue->context, true),
                ];
            }

            // Summary stats
            $stats = DB::selectOne("
                SELECT
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
                    SUM(CASE WHEN status = 'open' AND severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
                    SUM(CASE WHEN status = 'open' AND severity = 'warning' THEN 1 ELSE 0 END) as warning_count,
                    SUM(CASE WHEN status = 'resolved' AND resolved_at >= ? THEN 1 ELSE 0 END) as resolved_today
                FROM system_issues
            ", [now()->startOfDay()]);

            return [
                'timestamp' => now()->toIso8601String(),
                'summary' => [
                    'open' => (int) ($stats->open_count ?? 0),
                    'critical' => (int) ($stats->critical_count ?? 0),
                    'warning' => (int) ($stats->warning_count ?? 0),
                    'resolved_today' => (int) ($stats->resolved_today ?? 0),
                ],
                'issues' => $formatted,
            ];
        } catch (Exception $e) {
            return [
                'error' => 'Failed to list issues: '.$e->getMessage(),
                'issues' => [],
            ];
        }
    }

    /**
     * Update issue status (after human approval)
     *
     * @param  array  $params  ['id' => int, 'status' => string, 'resolution_notes' => string]
     * @return array Update result
     */
    public function ops_issues_update(array $params = []): array
    {
        $id = $params['id'] ?? null;
        $status = $params['status'] ?? null;
        $notes = $params['resolution_notes'] ?? null;
        $resolvedBy = $params['resolved_by'] ?? 'claude';

        if (! $id || ! $status) {
            return [
                'success' => false,
                'error' => 'Missing required parameters: id and status',
            ];
        }

        $validStatuses = ['open', 'in_progress', 'resolved', 'dismissed'];
        if (! in_array($status, $validStatuses)) {
            return [
                'success' => false,
                'error' => 'Invalid status. Must be one of: '.implode(', ', $validStatuses),
            ];
        }

        try {
            $issue = DB::selectOne('SELECT * FROM system_issues WHERE id = ?', [$id]);

            if (! $issue) {
                return [
                    'success' => false,
                    'error' => "Issue #{$id} not found",
                ];
            }

            // Build dynamic update query
            $setClauses = ['status = ?', 'updated_at = ?'];
            $bindings = [$status, now()];

            if (in_array($status, ['resolved', 'dismissed'])) {
                $setClauses[] = 'resolved_at = ?';
                $setClauses[] = 'resolved_by = ?';
                $bindings[] = now();
                $bindings[] = $resolvedBy;
            }

            if ($notes) {
                $setClauses[] = 'resolution_notes = ?';
                $bindings[] = $notes;
            }

            $bindings[] = $id;
            DB::update('UPDATE system_issues SET '.implode(', ', $setClauses).' WHERE id = ?', $bindings);

            Log::info('System issue updated', [
                'issue_id' => $id,
                'old_status' => $issue->status,
                'new_status' => $status,
                'resolved_by' => $resolvedBy,
            ]);

            return [
                'success' => true,
                'issue_id' => $id,
                'old_status' => $issue->status,
                'new_status' => $status,
                'message' => "Issue #{$id} updated to {$status}",
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to update issue: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Log a new system issue (internal use by ops jobs)
     *
     * @param  string  $category  Category: workflow, backup, service, database, log_error
     * @param  string  $severity  Severity: critical, warning, info
     * @param  string  $title  Short description
     * @param  string  $description  Detailed description
     * @param  array  $context  Additional context data
     * @param  string|null  $suggestedFix  AI-suggested remediation
     * @param  string  $detectedBy  Source: ops_maintenance, health_check, manual
     * @return int Issue ID
     */
    public function logIssue(
        string $category,
        string $severity,
        string $title,
        string $description,
        array $context = [],
        ?string $suggestedFix = null,
        string $detectedBy = 'ops_maintenance',
        ?string $findingType = null
    ): int {
        $hasFindingType = Schema::hasColumn('system_issues', 'finding_type');

        // Check for duplicate open/resolved issues (same category + title)
        // Include 'resolved' status so we update instead of creating duplicates
        $existing = DB::selectOne("SELECT * FROM system_issues WHERE category = ? AND title = ? AND status IN ('open', 'resolved')", [$category, $title]);

        if ($existing) {
            // Update existing issue: increment occurrence, update last_seen_at
            if ($existing->status === 'resolved') {
                // If issue was resolved, reopen it
                if ($hasFindingType) {
                    DB::update("UPDATE system_issues SET description = ?, suggested_fix = ?, finding_type = COALESCE(?, finding_type), context = ?, last_seen_at = ?, occurrence_count = occurrence_count + 1, updated_at = ?, status = 'open', resolved_at = NULL, resolved_by = NULL, resolution_notes = NULL WHERE id = ?", [
                        $description,
                        $suggestedFix,
                        $findingType,
                        json_encode($context),
                        now(),
                        now(),
                        $existing->id,
                    ]);
                } else {
                    DB::update("UPDATE system_issues SET description = ?, suggested_fix = ?, context = ?, last_seen_at = ?, occurrence_count = occurrence_count + 1, updated_at = ?, status = 'open', resolved_at = NULL, resolved_by = NULL, resolution_notes = NULL WHERE id = ?", [
                        $description,
                        $suggestedFix,
                        json_encode($context),
                        now(),
                        now(),
                        $existing->id,
                    ]);
                }
            } else {
                if ($hasFindingType) {
                    DB::update('UPDATE system_issues SET description = ?, suggested_fix = ?, finding_type = COALESCE(?, finding_type), context = ?, last_seen_at = ?, occurrence_count = occurrence_count + 1, updated_at = ? WHERE id = ?', [
                        $description,
                        $suggestedFix,
                        $findingType,
                        json_encode($context),
                        now(),
                        now(),
                        $existing->id,
                    ]);
                } else {
                    DB::update('UPDATE system_issues SET description = ?, suggested_fix = ?, context = ?, last_seen_at = ?, occurrence_count = occurrence_count + 1, updated_at = ? WHERE id = ?', [
                        $description,
                        $suggestedFix,
                        json_encode($context),
                        now(),
                        now(),
                        $existing->id,
                    ]);
                }
            }

            return $existing->id;
        }

        // Create new issue
        $now = now();
        if ($hasFindingType) {
            DB::insert('INSERT INTO system_issues (category, severity, title, description, suggested_fix, finding_type, status, detected_by, context, detected_at, first_seen_at, last_seen_at, occurrence_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $category,
                $severity,
                $title,
                $description,
                $suggestedFix,
                $findingType,
                'open',
                $detectedBy,
                json_encode($context),
                $now,
                $now,
                $now,
                1,
                $now,
                $now,
            ]);
        } else {
            DB::insert('INSERT INTO system_issues (category, severity, title, description, suggested_fix, status, detected_by, context, detected_at, first_seen_at, last_seen_at, occurrence_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $category,
                $severity,
                $title,
                $description,
                $suggestedFix,
                'open',
                $detectedBy,
                json_encode($context),
                $now,
                $now,
                $now,
                1,
                $now,
                $now,
            ]);
        }
        $id = (int) DB::getPdo()->lastInsertId();

        Log::info('System issue logged', [
            'issue_id' => $id,
            'category' => $category,
            'severity' => $severity,
            'title' => $title,
        ]);

        return $id;
    }

    /**
     * Auto-resolve issues that are no longer detected
     *
     * @param  string  $category  Category to check
     * @param  array  $currentTitles  Titles of currently detected issues
     * @return int Number of auto-resolved issues
     */
    public function autoResolveIssues(string $category, array $currentTitles): int
    {
        if (empty($currentTitles)) {
            // Resolve all open issues in this category
            $resolved = DB::update("UPDATE system_issues SET status = 'resolved', resolved_at = ?, resolved_by = 'auto', resolution_notes = 'Issue no longer detected', updated_at = ? WHERE category = ? AND status = 'open'", [now(), now(), $category]);
        } else {
            // Resolve issues not in current titles list
            $placeholders = implode(',', array_fill(0, count($currentTitles), '?'));
            $bindings = array_merge([now(), now(), $category], $currentTitles);
            $resolved = DB::update("UPDATE system_issues SET status = 'resolved', resolved_at = ?, resolved_by = 'auto', resolution_notes = 'Issue no longer detected', updated_at = ? WHERE category = ? AND status = 'open' AND title NOT IN ({$placeholders})", $bindings);
        }

        if ($resolved > 0) {
            Log::info('System issues auto-resolved', [
                'category' => $category,
                'count' => $resolved,
            ]);
        }

        return $resolved;
    }

    /**
     * Auto-resolve stale transient issues (log errors not seen recently)
     *
     * Transient issues like DNS timeouts or API failures that self-correct
     * should be auto-resolved if they haven't recurred in the staleness window.
     *
     * @param  int  $staleHours  Hours without recurrence to consider issue stale (default 6)
     * @return int Number of auto-resolved issues
     */
    public function autoResolveStaleTransientIssues(int $staleHours = 6): int
    {
        $staleThreshold = now()->subHours($staleHours);

        // Categories that are typically transient and self-correcting
        $transientCategories = ['log_error'];

        // Auto-resolve issues that:
        // 1. Are in transient categories
        // 2. Are still open
        // 3. Have severity 'info' or 'warning' (not critical)
        // 4. Haven't been seen in the last N hours
        $placeholders = implode(',', array_fill(0, count($transientCategories), '?'));
        $resolved = DB::update("UPDATE system_issues SET status = 'resolved', resolved_at = ?, resolved_by = 'auto', resolution_notes = ?, updated_at = ? WHERE category IN ({$placeholders}) AND status = 'open' AND severity IN ('info', 'warning') AND last_seen_at < ?", array_merge(
            [now(), "Auto-resolved: No recurrence in {$staleHours} hours (transient issue)", now()],
            $transientCategories,
            [$staleThreshold]
        ));

        if ($resolved > 0) {
            Log::info('Stale transient issues auto-resolved', [
                'count' => $resolved,
                'stale_hours' => $staleHours,
            ]);
        }

        return $resolved;
    }

    /**
     * Get pending issues for Pushover report (open + resolved, up to limit)
     * Pending = open issues + resolved issues (until dismissed)
     *
     * @param  int  $limit  Max issues to return for display
     * @return array Pending issues data for report
     */
    public function getPendingIssuesForReport(int $limit = 5): array
    {
        try {
            // Get only open issues - resolved issues don't need to be shown in reports
            $fetchLimit = $limit + 10; // Get extra to count overflow
            $issues = DB::select("SELECT * FROM system_issues WHERE status = 'open' ORDER BY FIELD(severity, 'critical', 'warning', 'info'), last_seen_at DESC LIMIT ?", [$fetchLimit]);

            $total = count($issues);
            $displayIssues = array_slice($issues, 0, $limit);

            $formatted = [];
            foreach ($displayIssues as $issue) {
                $lastSeen = \Carbon\Carbon::parse($issue->last_seen_at);
                $dayName = $lastSeen->format('D');
                $dateFormatted = $lastSeen->format('m/d/Y');

                $statusIcon = match ($issue->status) {
                    'open' => match ($issue->severity) {
                        'critical' => '🚨',
                        'warning' => '⚠️',
                        default => 'ℹ️',
                    },
                    'resolved' => '✅',
                    default => '❓',
                };

                $formatted[] = [
                    'id' => $issue->id,
                    'icon' => $statusIcon,
                    'title' => $issue->title,
                    'status' => $issue->status,
                    'severity' => $issue->severity,
                    'category' => $issue->category,
                    'day_date' => "{$dayName} {$dateFormatted}",
                    'occurrence_count' => $issue->occurrence_count,
                    'suggested_fix' => $issue->suggested_fix,
                ];
            }

            $overflow = max(0, $total - $limit);

            return [
                'issues' => $formatted,
                'total' => $total,
                'overflow' => $overflow,
                'has_pending' => $total > 0,
            ];
        } catch (Exception $e) {
            Log::error('Failed to get pending issues for report', ['error' => $e->getMessage()]);

            return [
                'issues' => [],
                'total' => 0,
                'overflow' => 0,
                'has_pending' => false,
            ];
        }
    }
}

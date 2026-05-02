<?php

namespace App\Jobs;

use App\Services\BiasMaintenanceService;
use App\Services\FileRegistryService;
use App\Services\NewsArticleService;
use App\Services\NextcloudService;
use App\Services\Ops\AgentRecursionCallsRetentionService;
use App\Services\OpsMCPService;
use App\Services\WorkflowDiagnosticsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ops Maintenance Job - CONSOLIDATED NIGHTLY JOB with Checkpoint/Continuation
 *
 * Comprehensive nightly maintenance combining:
 * - Database maintenance (backups, cleanup, optimization)
 * - AI Operator monitoring (health checks, log analysis)
 * - Routine cleanup (Joplin RAG, circuit breakers)
 * - Issue tracking and Pushover alerts
 *
 * CHECKPOINT SYSTEM:
 * Job checkpoints progress and can dispatch continuation jobs to avoid timeout.
 * Each phase saves state before long-running operations.
 * If timeout approaches, job saves checkpoint and re-dispatches.
 *
 * Scheduled to run at 4 AM daily.
 * Replaces separate maintenance:daily command and 5:30 AM ops job.
 */
class OpsMaintenanceJob implements ShouldQueue
{
    use Queueable;

    /**
     * Job timeout in seconds (increased to 30 minutes for large scans)
     */
    public $timeout = 1800;

    /**
     * Number of retry attempts
     */
    public $tries = 3;

    /**
     * Retry backoff in seconds
     */
    public $backoff = [60, 120, 300];

    /**
     * Time threshold to trigger continuation (5 minutes before timeout)
     */
    private const CONTINUATION_THRESHOLD = 300;

    /**
     * Cache key prefix for checkpoints
     */
    private const CHECKPOINT_KEY = 'ops_maintenance_checkpoint';

    /**
     * Checkpoint data - tracks which phases are complete
     */
    private array $checkpoint = [];

    /**
     * Job start time for timeout detection
     */
    private float $jobStartTime = 0.0;

    /**
     * Session ID to group related continuation jobs
     */
    private string $sessionId = '';

    /**
     * Create a new job instance.
     *
     * @param  string|null  $sessionId  Session ID for continuation (null = new session)
     */
    public function __construct(?string $sessionId = null)
    {
        $this->onQueue('high');
        $this->sessionId = $sessionId ?? uniqid('ops_', true);
    }

    /**
     * Get middleware for the job.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ops-maintenance'))
                ->releaseAfter(300)
                ->expireAfter(1800),
        ];
    }

    /**
     * Check if we're approaching timeout and should dispatch continuation
     */
    private function shouldDispatchContinuation(): bool
    {
        $elapsed = microtime(true) - $this->jobStartTime;
        $remaining = $this->timeout - $elapsed;

        return $remaining < self::CONTINUATION_THRESHOLD;
    }

    /**
     * Save checkpoint and dispatch continuation job
     */
    private function dispatchContinuation(): void
    {
        Log::info('Ops: Approaching timeout, saving checkpoint and dispatching continuation', [
            'session_id' => $this->sessionId,
            'checkpoint' => $this->checkpoint,
            'elapsed_seconds' => round(microtime(true) - $this->jobStartTime, 2),
        ]);

        // Save checkpoint to cache (expires in 1 hour)
        Cache::put(self::CHECKPOINT_KEY.':'.$this->sessionId, $this->checkpoint, 3600);

        // Dispatch continuation job with same session ID
        self::dispatch($this->sessionId)->delay(now()->addSeconds(30));
    }

    /**
     * Load checkpoint from cache if this is a continuation
     */
    private function loadCheckpoint(): void
    {
        // Ensure sessionId is set (handles unserialization where constructor doesn't run)
        if (empty($this->sessionId)) {
            $this->sessionId = uniqid('ops_', true);
        }

        $cacheKey = self::CHECKPOINT_KEY.':'.$this->sessionId;
        $savedCheckpoint = Cache::get($cacheKey);

        if ($savedCheckpoint) {
            $this->checkpoint = $savedCheckpoint;
            Log::info('Ops: Resuming from checkpoint', [
                'session_id' => $this->sessionId,
                'checkpoint' => $this->checkpoint,
            ]);
            // Clear checkpoint after loading
            Cache::forget($cacheKey);
        } else {
            // New session - initialize empty checkpoint
            $this->checkpoint = [
                'phase1_complete' => false,
                'phase2_complete' => false,
                'routine_complete' => false,
                'file_registry_scan_offset' => 0,
                'maintenance_stats' => [],
                'all_issues' => [],
                'logged_issue_titles' => [],
            ];
        }
    }

    /**
     * Save current checkpoint to cache
     */
    private function saveCheckpoint(): void
    {
        Cache::put(self::CHECKPOINT_KEY.':'.$this->sessionId, $this->checkpoint, 3600);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->jobStartTime = microtime(true);
        $this->loadCheckpoint();

        $isResume = $this->checkpoint['phase1_complete'] || $this->checkpoint['phase2_complete'];
        Log::info($isResume ? 'Resuming Consolidated Nightly Maintenance Job' : 'Starting Consolidated Nightly Maintenance Job', [
            'session_id' => $this->sessionId,
        ]);

        $ops = new OpsMCPService;
        $allIssues = $this->checkpoint['all_issues'];
        $loggedIssueTitles = $this->checkpoint['logged_issue_titles'];
        $maintenanceStats = $this->checkpoint['maintenance_stats'];

        // ========================================
        // PHASE 1: Database Maintenance (from DailyMaintenance)
        // ========================================
        if (! $this->checkpoint['phase1_complete']) {
            Log::info('Ops: Phase 1 - Database Maintenance');

            try {
                // 1a. Check for failed scheduled jobs and alert
                Log::info('Ops: Phase 1a - checking failed scheduled jobs');
                $failedScheduledJobs = $this->checkFailedScheduledJobs();
                if (! empty($failedScheduledJobs)) {
                    $maintenanceStats['failed_scheduled_jobs'] = $failedScheduledJobs;
                    Log::warning('Ops: Failed scheduled jobs detected', $failedScheduledJobs);
                }

                // 1b. DB backups are handled by external/root cron on prod.
                // Keep ops maintenance out of the backup path to avoid duplicate work
                // and permission drift against root-owned backup directories.
                Log::info('Ops: Phase 1b - skipping DB backups (external cron)');
                $maintenanceStats['backups'] = ['skipped' => 'external cron'];

                // 1c. Clean old data
                Log::info('Ops: Phase 1c - cleaning failed jobs');
                $maintenanceStats['old_failed_jobs'] = $this->cleanOldFailedJobs();
                Log::info('Ops: Phase 1c - cleaning workflow runs');
                $maintenanceStats['old_workflow_runs'] = $this->cleanOldWorkflowRuns();
                Log::info('Ops: Phase 1c - cleaning orphaned workflow data');
                $maintenanceStats['orphaned_data'] = $this->cleanOrphanedData();
                Log::info('Ops: Phase 1c - expiring stale agent sessions');
                $maintenanceStats['stale_agent_sessions'] = $this->expireStaleAgentSessions();
                Log::info('Ops: Phase 1c - purging recursion calls');
                $maintenanceStats['recursion_calls_purged'] = $this->purgeOldRecursionCalls();

                // 1d. Rotate large logs
                Log::info('Ops: Phase 1d - rotating logs');
                $maintenanceStats['logs_rotated'] = $this->rotateLogs();

                // 1e. Refresh database statistics
                Log::info('Ops: Phase 1e - refreshing database statistics');
                $maintenanceStats['tables_optimized'] = $this->optimizeDatabase();

                Log::info('Ops: Database maintenance complete', $maintenanceStats);
            } catch (\Throwable $e) {
                Log::error('Ops: Phase 1 failed, continuing to next phase', [
                    'error' => $e->getMessage(),
                ]);
                $allIssues[] = 'Phase 1 (Database Maintenance) failed: '.$e->getMessage();
            }

            // Update checkpoint
            $this->checkpoint['phase1_complete'] = true;
            $this->checkpoint['maintenance_stats'] = $maintenanceStats;
            $this->saveCheckpoint();

            // Check if we should dispatch continuation
            if ($this->shouldDispatchContinuation()) {
                $this->dispatchContinuation();

                return;
            }
        } else {
            Log::info('Ops: Phase 1 already complete, skipping');
        }

        // ========================================
        // PHASE 2: AI Operator Monitoring
        // ========================================
        if (! $this->checkpoint['phase2_complete']) {
            Log::info('Ops: Phase 2 - AI Operator Monitoring');

            $healthResults = [];
            $workflowResults = [];
            $oauthResults = [];
            $logResults = [];
            $cleanupResults = [];

            try {
                // Step 1: System Health Check
                Log::info('Ops: Running system health check...');
                $healthResults = $ops->ops_health_check();
                Log::info('Ops: Health check complete', [
                    'status' => $healthResults['overall_status'],
                    'warnings' => count($healthResults['warnings']),
                    'critical' => count($healthResults['critical']),
                ]);
                $allIssues = array_merge($allIssues, $healthResults['warnings'], $healthResults['critical']);

                // Log critical issues to system_issues table
                foreach ($healthResults['critical'] as $issue) {
                    $this->logHealthIssue($ops, 'critical', $issue, $healthResults);
                    $cat = $this->categorizeHealthIssue($issue);
                    $loggedIssueTitles[$cat][] = $this->extractIssueTitle($issue);
                }
                foreach ($healthResults['warnings'] as $issue) {
                    $this->logHealthIssue($ops, 'warning', $issue, $healthResults);
                    $cat = $this->categorizeHealthIssue($issue);
                    $loggedIssueTitles[$cat][] = $this->extractIssueTitle($issue);
                }

                // Step 2: Workflow Health Check (with auto-cleanup)
                Log::info('Ops: Running workflow health check...');
                $workflowResults = $ops->checkWorkflowHealth(autoCleanup: true);
                Log::info('Ops: Workflow health complete', [
                    'status' => $workflowResults['status'],
                    'failed' => count($workflowResults['failed_runs']),
                    'missed' => count($workflowResults['missed_schedules']),
                ]);
                $allIssues = array_merge($allIssues, $workflowResults['issues']);

                // Log workflow issues
                foreach ($workflowResults['failed_runs'] as $run) {
                    $title = "Workflow failed: {$run['name']}";
                    $ops->logIssue(
                        'workflow',
                        'warning',
                        $title,
                        "Workflow '{$run['name']}' failed. Error: ".($run['error'] ?? 'Unknown'),
                        ['run_id' => $run['run_id'], 'error' => $run['error']],
                        "Review workflow logs and fix the underlying issue. Run ID: {$run['run_id']}"
                    );
                    $loggedIssueTitles['workflow'][] = $title;
                }

                // Step 3: OAuth Token Health Check
                Log::info('Ops: Checking OAuth tokens...');
                $oauthResults = $ops->checkOAuthHealth(warnDays: 5);
                Log::info('Ops: OAuth check complete', [
                    'status' => $oauthResults['status'],
                    'issues' => count($oauthResults['issues']),
                ]);
                $allIssues = array_merge($allIssues, $oauthResults['issues']);

                // Log OAuth issues
                foreach ($oauthResults['issues'] as $issue) {
                    $title = $this->extractIssueTitle($issue);
                    $severity = str_contains($issue, '❌') ? 'critical' : 'warning';
                    $ops->logIssue(
                        'service',
                        $severity,
                        $title,
                        $issue,
                        ['oauth' => $oauthResults['tokens'] ?? []],
                        'Re-authenticate with the OAuth provider to refresh tokens.'
                    );
                    $loggedIssueTitles['service'][] = $title;
                }

                // Step 4: Log Analysis
                Log::info('Ops: Analyzing logs...');
                $logResults = $ops->ops_log_analyze(['hours' => 24]);
                Log::info('Ops: Log analysis complete', [
                    'errors_found' => $logResults['summary']['total_errors'] ?? 0,
                ]);

                // Log significant error patterns (only if > 10 of same type)
                foreach ($logResults['patterns'] ?? [] as $category => $count) {
                    if ($count >= 10) {
                        $title = "High error rate: {$category}";
                        $ops->logIssue(
                            'log_error',
                            'info',
                            $title,
                            "{$count} errors of type '{$category}' in last 24 hours.",
                            ['count' => $count, 'category' => $category, 'examples' => $logResults['errors'][$category] ?? []],
                            "Review logs and address root cause of '{$category}' errors."
                        );
                        $loggedIssueTitles['log_error'][] = $title;
                    }
                }

                // Step 5: Cleanup
                Log::info('Ops: Running cleanup tasks...');
                $cleanupResults = $ops->ops_cleanup();
                Log::info('Ops: Cleanup complete', [
                    'space_freed_mb' => $cleanupResults['summary']['space_freed_mb'] ?? 0,
                    'rows_deleted' => $cleanupResults['summary']['total_rows_deleted'] ?? 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('Ops: Phase 2 failed, continuing to next phase', [
                    'error' => $e->getMessage(),
                ]);
                $allIssues[] = 'Phase 2 (AI Operator Monitoring) failed: '.$e->getMessage();
            }

            // Update checkpoint for Phase 2 complete
            $this->checkpoint['phase2_complete'] = true;
            $this->checkpoint['all_issues'] = $allIssues;
            $this->checkpoint['logged_issue_titles'] = $loggedIssueTitles;
            $this->checkpoint['maintenance_stats'] = $maintenanceStats;
            $this->checkpoint['health_results'] = $healthResults;
            $this->checkpoint['workflow_results'] = $workflowResults;
            $this->checkpoint['oauth_results'] = $oauthResults;
            $this->checkpoint['cleanup_results'] = $cleanupResults;
            $this->checkpoint['log_results'] = $logResults;
            $this->saveCheckpoint();

            // Check if we should dispatch continuation before routine maintenance
            if ($this->shouldDispatchContinuation()) {
                $this->dispatchContinuation();

                return;
            }
        } else {
            Log::info('Ops: Phase 2 already complete, loading cached results');
            // Load cached results for report generation
            $healthResults = $this->checkpoint['health_results'] ?? [];
            $workflowResults = $this->checkpoint['workflow_results'] ?? [];
            $oauthResults = $this->checkpoint['oauth_results'] ?? [];
            $cleanupResults = $this->checkpoint['cleanup_results'] ?? [];
            $logResults = $this->checkpoint['log_results'] ?? [];
        }

        // ========================================
        // PHASE 3: Routine Maintenance (with checkpointing for file registry)
        // ========================================
        if (! $this->checkpoint['routine_complete']) {
            try {
                // Step 5b: Run routine maintenance commands with continuation support
                Log::info('Ops: Running routine maintenance with checkpoint support...');
                $maintenanceResults = $this->runRoutineMaintenanceWithCheckpoint();

                if ($maintenanceResults['continuation_dispatched'] ?? false) {
                    // Job is continuing in another dispatch
                    return;
                }

                if (! empty($maintenanceResults['actions'])) {
                    Log::info('Ops: Routine maintenance complete', $maintenanceResults);
                }

                // INF-10b: Auto-heal cycle — detect issues, execute read-risk remediations
                try {
                    $autoHeal = app(\App\Services\AutoHealService::class);
                    $healResults = $autoHeal->run();
                    $maintenanceStats['auto_heal'] = [
                        'detected' => count($healResults['detected']),
                        'healed' => count($healResults['healed']),
                        'skipped' => count($healResults['skipped']),
                        'failed' => count($healResults['failed']),
                    ];
                    if (! empty($healResults['healed'])) {
                        Log::info('Ops: Auto-heal actions taken', $healResults['healed']);
                    }
                } catch (\Exception $e) {
                    Log::warning('Ops: Auto-heal failed', ['error' => $e->getMessage()]);
                    $maintenanceStats['auto_heal'] = ['error' => $e->getMessage()];
                }

                // Step 5c: Monthly bias data maintenance (1st of month only)
                if (now()->day === 1) {
                    if ($this->dedicatedBiasMaintenanceEnabled()) {
                        $maintenanceStats['bias_maintenance'] = [
                            'status' => 'skipped',
                            'reason' => 'dedicated_bias_data_refresh_enabled',
                        ];
                        Log::info('Ops: Skipping monthly bias maintenance; dedicated bias_data_refresh job is enabled.');
                    } else {
                        Log::info('Ops: Running monthly bias maintenance fallback...');
                        try {
                            $biasService = new BiasMaintenanceService;
                            $biasResults = $biasService->runMonthlyMaintenance('free');
                            $maintenanceStats['bias_maintenance'] = $biasResults;
                            Log::info('Ops: Bias maintenance complete', $biasResults);
                        } catch (\Exception $e) {
                            Log::warning('Ops: Bias maintenance failed', ['error' => $e->getMessage()]);
                            $maintenanceStats['bias_maintenance'] = ['error' => $e->getMessage()];
                        }
                    }

                    // FC-2: Monthly Bayesian source credibility recalculation
                    try {
                        $credService = app(\App\Services\SourceCredibilityService::class);
                        $bayesianResults = $credService->recalculateAllBayesian();
                        $maintenanceStats['bayesian_recalc'] = $bayesianResults;
                        Log::info('Ops: Bayesian source credibility recalc complete', $bayesianResults);
                    } catch (\Exception $e) {
                        Log::warning('Ops: Bayesian recalc failed', ['error' => $e->getMessage()]);
                        $maintenanceStats['bayesian_recalc'] = ['error' => $e->getMessage()];
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Ops: Phase 3 failed, continuing to reporting', [
                    'error' => $e->getMessage(),
                ]);
                $allIssues[] = 'Phase 3 (Routine Maintenance) failed: '.$e->getMessage();
            }

            // Mark routine as complete
            $this->checkpoint['routine_complete'] = true;
            $this->checkpoint['maintenance_stats'] = $maintenanceStats;
            $this->saveCheckpoint();
        } else {
            Log::info('Ops: Routine maintenance already complete, skipping');
        }

        // ========================================
        // PHASE 4: Issue Resolution & Reporting
        // ========================================

        try {
            // Step 6: Auto-resolve issues that are no longer detected
            foreach ($loggedIssueTitles as $category => $titles) {
                $ops->autoResolveIssues($category, $titles);
            }

            // Step 6b: Auto-resolve stale transient issues (e.g., log errors that self-corrected)
            // If a transient issue hasn't recurred in 6 hours, it's likely self-corrected
            $staleResolved = $ops->autoResolveStaleTransientIssues(6);
            if ($staleResolved > 0) {
                $maintenanceStats['stale_issues_resolved'] = $staleResolved;
            }

            // Step 7: Generate Consolidated Report
            Log::info('Ops: Generating consolidated report...');
            $duration = round(microtime(true) - $this->jobStartTime, 2);
            $report = $ops->ops_report([
                'health' => $healthResults,
                'workflow' => $workflowResults,
                'oauth' => $oauthResults,
                'cleanup' => $cleanupResults,
                'logs' => $logResults,
                'all_issues' => $allIssues,
                'maintenance' => $maintenanceStats,
                'duration_seconds' => $duration,
                'session_id' => $this->sessionId,
            ]);

            // Step 8: Cache report for daily digest (N118: consolidated into ops:daily-report)
            // No longer sends its own Pushover — daily report at 5:50 AM pulls this data.
            Log::info('Ops: Caching report for daily digest...');
            Cache::put('ops_maintenance_report', [
                'health' => $healthResults,
                'cleanup' => $cleanupResults,
                'logs' => $logResults,
                'report' => $report,
                'timestamp' => now()->toIso8601String(),
            ], now()->addHours(6));
            $alertResult = ['success' => true];

            // Get issue summary for logging
            $issueList = $ops->ops_issues_list(['status' => 'open']);
            $openIssues = $issueList['summary']['open'] ?? 0;
            $criticalIssues = $issueList['summary']['critical'] ?? 0;

            // Clear checkpoint on successful completion
            Cache::forget(self::CHECKPOINT_KEY.':'.$this->sessionId);

            if ($alertResult['success']) {
                Log::info('Ops Maintenance Job completed successfully', [
                    'session_id' => $this->sessionId,
                    'health_status' => $healthResults['overall_status'] ?? 'unknown',
                    'total_issues' => count($allIssues),
                    'open_system_issues' => $openIssues,
                    'critical_system_issues' => $criticalIssues,
                    'notification_sent' => true,
                ]);
            } else {
                Log::warning('Ops Maintenance Job completed but notification failed', [
                    'session_id' => $this->sessionId,
                    'health_status' => $healthResults['overall_status'] ?? 'unknown',
                    'notification_error' => $alertResult['error'] ?? 'Unknown',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Ops: Phase 4 (Reporting) failed', [
                'error' => $e->getMessage(),
            ]);
            // Clear checkpoint even on reporting failure so next run starts fresh
            Cache::forget(self::CHECKPOINT_KEY.':'.$this->sessionId);
        }
    }

    /**
     * Log a health check issue to system_issues table
     */
    private function logHealthIssue(OpsMCPService $ops, string $severity, string $issue, array $context): void
    {
        $title = $this->extractIssueTitle($issue);
        $category = $this->categorizeHealthIssue($issue);
        $suggestedFix = $this->suggestFix($issue);
        $findingType = $this->detectFindingType($issue);

        $ops->logIssue($category, $severity, $title, $issue, $context, $suggestedFix, 'ops_maintenance', $findingType);
    }

    /**
     * Extract a clean title from an issue message
     */
    private function extractIssueTitle(string $issue): string
    {
        // Remove emoji prefixes
        $title = preg_replace('/^[🚨⚠️❌✅🔧📋🔴⏰\s]+/', '', $issue);

        // Truncate at reasonable length
        return substr($title, 0, 100);
    }

    /**
     * Categorize a health issue
     */
    private function categorizeHealthIssue(string $issue): string
    {
        $lower = strtolower($issue);

        if (str_contains($lower, 'backup')) {
            return 'backup';
        }
        if (str_contains($lower, 'database') || str_contains($lower, 'mysql') || str_contains($lower, 'postgres')) {
            return 'database';
        }
        if (str_contains($lower, 'workflow') || str_contains($lower, 'stuck')) {
            return 'workflow';
        }
        if (str_contains($lower, 'horizon') || str_contains($lower, 'redis') || str_contains($lower, 'nginx')) {
            return 'service';
        }
        if (str_contains($lower, 'disk') || str_contains($lower, 'memory')) {
            return 'service';
        }

        return 'service';
    }

    /**
     * Suggest a fix for common issues
     */
    private function suggestFix(string $issue): ?string
    {
        $lower = strtolower($issue);

        if (str_contains($lower, 'horizon') && str_contains($lower, 'not running')) {
            return 'Restart Horizon with: sudo systemctl restart laravel-horizon.service';
        }
        if (str_contains($lower, 'backup') && str_contains($lower, 'old')) {
            return 'Check DailyMaintenance command and verify cron is running';
        }
        if (str_contains($lower, 'disk') && str_contains($lower, 'critical')) {
            return 'Run cleanup: php artisan maintenance:daily --cleanup-only';
        }
        if (str_contains($lower, 'stuck')) {
            return 'Investigate workflow run logs and consider increasing timeout';
        }
        if (str_contains($lower, 'redis') && str_contains($lower, 'memory')) {
            return 'Clear stale cache keys or increase Redis maxmemory';
        }

        return null;
    }

    private function detectFindingType(string $issue): ?string
    {
        $lower = strtolower($issue);

        if (str_contains($lower, 'horizon') && str_contains($lower, 'not running')) {
            return 'horizon_down';
        }

        if (str_contains($lower, 'circuit breakers open')) {
            return 'circuit_breaker_open';
        }

        if (str_contains($lower, 'stuck jobs detected')) {
            return 'stalled_job';
        }

        return null;
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Ops Maintenance Job failed', [
            'session_id' => $this->sessionId,
            'error' => $exception?->getMessage(),
            'attempts' => $this->attempts(),
            'checkpoint' => $this->checkpoint ?? [],
        ]);

        // Try to send a failure notification
        try {
            $ops = new OpsMCPService;
            $ops->ops_alert([
                'title' => '🚨 PLOS Ops Job Failed',
                'message' => "Ops maintenance job failed after {$this->attempts()} attempts.\n\nSession: {$this->sessionId}\nError: ".($exception?->getMessage() ?? 'Unknown'),
                'priority' => 1,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to send ops failure notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get tags for Horizon monitoring.
     */
    public function tags(): array
    {
        return ['ops', 'maintenance', 'daily', 'session:'.$this->sessionId];
    }

    /**
     * Run routine maintenance tasks that should happen automatically.
     * These are recurring cleanup operations that don't need human approval.
     */
    private function runRoutineMaintenance(): array
    {
        $results = [
            'actions' => [],
            'errors' => [],
        ];

        // 1. Joplin RAG cleanup - remove legacy joplin_attachment records from RAG
        try {
            $legacyCount = \DB::connection('pgsql_rag')->selectOne(
                "SELECT COUNT(*) as count FROM rag_documents WHERE designation = 'joplin_attachment'"
            );

            if ($legacyCount && $legacyCount->count > 0) {
                $deleted = \DB::connection('pgsql_rag')->delete(
                    'DELETE FROM rag_documents WHERE designation = ?', ['joplin_attachment']
                );

                $results['actions']['joplin_rag_cleanup'] = [
                    'performed' => true,
                    'records_deleted' => $deleted,
                ];

                Log::info('Ops: Cleaned up legacy Joplin RAG records', ['count' => $deleted]);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('joplin_rag_cleanup', $e, $results);
        }

        // 2. Clear expired circuit breakers (allow services to retry)
        try {
            $prefix = config('database.redis.options.prefix', '');
            $cleared = 0;

            // Check for stale circuit breaker keys (older than 1 hour)
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $keys = $redis->keys('circuit_breaker:*');

            foreach ($keys as $key) {
                // Remove prefix if present
                $cleanKey = str_replace($prefix, '', $key);
                $ttl = $redis->ttl($cleanKey);

                // If TTL is -1 (no expiry) or > 1 hour, it's stale
                if ($ttl === -1 || $ttl > 3600) {
                    $redis->del($cleanKey);
                    $cleared++;
                }
            }

            if ($cleared > 0) {
                $results['actions']['circuit_breaker_reset'] = [
                    'performed' => true,
                    'keys_cleared' => $cleared,
                ];
                Log::info('Ops: Reset stale circuit breakers', ['count' => $cleared]);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('circuit_breaker_reset', $e, $results);
        }

        // 3. File Catalog maintenance - verify existing files and scan for new ones
        try {
            $fileRegistry = app(FileRegistryService::class);

            // Clean up stuck sync jobs (running > 1 hour without heartbeat update)
            $stuckCleaned = $fileRegistry->cleanupStuckSyncRuns(60);
            if ($stuckCleaned > 0) {
                $results['actions']['file_catalog_stuck_cleanup'] = [
                    'performed' => true,
                    'jobs_cleaned' => $stuckCleaned,
                ];
                Log::info('Ops: Cleaned up stuck file catalog sync jobs', ['count' => $stuckCleaned]);
            }

            $stats = $fileRegistry->getStatistics();

            // Only run verification if we have registered files
            if ($stats['total_files'] > 0) {
                // Verify 100 oldest-verified files
                $verifyResults = $fileRegistry->verifyBatch(100);

                if ($verifyResults['verified'] > 0 || $verifyResults['orphaned'] > 0) {
                    $results['actions']['file_catalog_verify'] = [
                        'performed' => true,
                        'verified' => $verifyResults['verified'],
                        'orphaned' => $verifyResults['orphaned'],
                    ];
                    Log::info('Ops: File catalog verification complete', $verifyResults);
                }
            }

            // Scan for new files (simplified catalog scan)
            $scanResults = $fileRegistry->scanAndRegisterNew($this->nextcloudLibraryRoot(), 500);

            if ($scanResults['registered'] > 0) {
                $results['actions']['file_catalog_scan'] = [
                    'performed' => true,
                    'scanned' => $scanResults['scanned'],
                    'registered' => $scanResults['registered'],
                    'skipped' => $scanResults['skipped_unchanged'] ?? 0,
                ];
                Log::info('Ops: File catalog scan complete', $scanResults);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('file_catalog', $e, $results);
        }

        return $results;
    }

    /**
     * Run routine maintenance with checkpoint/continuation support
     * This version breaks up long-running operations and can resume from checkpoint
     */
    private function runRoutineMaintenanceWithCheckpoint(): array
    {
        $results = [
            'actions' => [],
            'errors' => [],
            'continuation_dispatched' => false,
        ];

        // 1. Joplin RAG cleanup - remove legacy joplin_attachment records from RAG
        try {
            $legacyCount = \DB::connection('pgsql_rag')->selectOne(
                "SELECT COUNT(*) as count FROM rag_documents WHERE designation = 'joplin_attachment'"
            );

            if ($legacyCount && $legacyCount->count > 0) {
                $deleted = \DB::connection('pgsql_rag')->delete(
                    'DELETE FROM rag_documents WHERE designation = ?', ['joplin_attachment']
                );

                $results['actions']['joplin_rag_cleanup'] = [
                    'performed' => true,
                    'records_deleted' => $deleted,
                ];

                Log::info('Ops: Cleaned up legacy Joplin RAG records', ['count' => $deleted]);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('joplin_rag_cleanup', $e, $results);
        }

        // 2. Clear expired circuit breakers (allow services to retry)
        try {
            $prefix = config('database.redis.options.prefix', '');
            $cleared = 0;

            // Check for stale circuit breaker keys (older than 1 hour)
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $keys = $redis->keys('circuit_breaker:*');

            foreach ($keys as $key) {
                // Remove prefix if present
                $cleanKey = str_replace($prefix, '', $key);
                $ttl = $redis->ttl($cleanKey);

                // If TTL is -1 (no expiry) or > 1 hour, it's stale
                if ($ttl === -1 || $ttl > 3600) {
                    $redis->del($cleanKey);
                    $cleared++;
                }
            }

            if ($cleared > 0) {
                $results['actions']['circuit_breaker_reset'] = [
                    'performed' => true,
                    'keys_cleared' => $cleared,
                ];
                Log::info('Ops: Reset stale circuit breakers', ['count' => $cleared]);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('circuit_breaker_reset', $e, $results);
        }

        // Check for continuation before file registry (the long operation)
        if ($this->shouldDispatchContinuation()) {
            $this->dispatchContinuation();
            $results['continuation_dispatched'] = true;

            return $results;
        }

        // 3. File Catalog maintenance with continuation support
        try {
            $fileRegistry = app(FileRegistryService::class);

            // Clean up stuck sync jobs (running > 1 hour without heartbeat update)
            $stuckCleaned = $fileRegistry->cleanupStuckSyncRuns(60);
            if ($stuckCleaned > 0) {
                $results['actions']['file_catalog_stuck_cleanup'] = [
                    'performed' => true,
                    'jobs_cleaned' => $stuckCleaned,
                ];
                Log::info('Ops: Cleaned up stuck file catalog sync jobs', ['count' => $stuckCleaned]);
            }

            $stats = $fileRegistry->getStatistics();

            // Only run verification if we have registered files
            if ($stats['total_files'] > 0) {
                // Verify 100 oldest-verified files
                $verifyResults = $fileRegistry->verifyBatch(100);

                if ($verifyResults['verified'] > 0 || $verifyResults['orphaned'] > 0) {
                    $results['actions']['file_catalog_verify'] = [
                        'performed' => true,
                        'verified' => $verifyResults['verified'],
                        'orphaned' => $verifyResults['orphaned'],
                    ];
                    Log::info('Ops: File catalog verification complete', $verifyResults);
                }
            }

            // Check continuation again after verification
            if ($this->shouldDispatchContinuation()) {
                $this->dispatchContinuation();
                $results['continuation_dispatched'] = true;

                return $results;
            }

            // Scan for new files (simplified catalog scan)
            $scanResults = $fileRegistry->scanAndRegisterNew($this->nextcloudLibraryRoot(), 500);

            if ($scanResults['registered'] > 0) {
                $results['actions']['file_catalog_scan'] = [
                    'performed' => true,
                    'scanned' => $scanResults['scanned'],
                    'registered' => $scanResults['registered'],
                    'skipped' => $scanResults['skipped_unchanged'] ?? 0,
                ];
                Log::info('Ops: File catalog scan complete', $scanResults);
            }

        } catch (\Exception $e) {
            $this->logPhaseError('file_catalog', $e, $results);
        }

        // 7. Update workflow diagnostics (for Ops monitoring dashboard)
        try {
            $diagnosticsService = app(WorkflowDiagnosticsService::class);
            $diagnosticsResults = $diagnosticsService->updateAllDiagnostics('7 days');

            $results['actions']['workflow_diagnostics'] = [
                'performed' => true,
                'updated' => $diagnosticsResults['updated'] ?? 0,
                'failed' => $diagnosticsResults['failed'] ?? 0,
            ];
            Log::info('Ops: Updated workflow diagnostics', $results['actions']['workflow_diagnostics']);
        } catch (\Exception $e) {
            $this->logPhaseError('workflow_diagnostics', $e, $results);
        }

        // 8. Authoritative Source Discovery & Health Check (Self-growing research sources)
        try {
            $sourceDiscovery = app(\App\Services\AuthoritativeSourceDiscoveryService::class);

            // Seed industry-standard sources (idempotent - only adds missing ones)
            $seedResults = $sourceDiscovery->seedIndustrySources();

            // Health check active sources and update trust scores
            $healthResults = $sourceDiscovery->healthCheckSources();

            $results['actions']['authoritative_sources'] = [
                'performed' => true,
                'sources_added' => $seedResults['added'] ?? 0,
                'sources_updated' => $seedResults['updated'] ?? 0,
                'health_checked' => $healthResults['checked'] ?? 0,
                'sources_promoted' => $healthResults['promoted'] ?? 0,
                'sources_demoted' => $healthResults['demoted'] ?? 0,
            ];
            Log::info('Ops: Authoritative source maintenance complete', $results['actions']['authoritative_sources']);
        } catch (\Exception $e) {
            $this->logPhaseError('authoritative_sources', $e, $results);
        }

        // 9. Ollama Model Registry Sync (Discover new models, alert for vetting)
        try {
            $modelRegistry = app(\App\Services\OllamaModelRegistryService::class);

            // Sync models from Ollama with database registry
            $syncResults = $modelRegistry->syncModels();

            $results['actions']['ollama_model_registry'] = [
                'performed' => true,
                'models_discovered' => count($syncResults['discovered'] ?? []),
                'models_updated' => count($syncResults['updated'] ?? []),
                'models_unavailable' => count($syncResults['unavailable'] ?? []),
                'new_models' => $syncResults['discovered'] ?? [],
                'by_instance' => $syncResults['by_instance'] ?? [],
            ];

            // Log new models that need vetting
            if (! empty($syncResults['discovered'])) {
                Log::notice('Ops: New Ollama models need vetting', [
                    'models' => $syncResults['discovered'],
                ]);
            }

            Log::info('Ops: Ollama model registry sync complete', $results['actions']['ollama_model_registry']);
        } catch (\Exception $e) {
            $this->logPhaseError('ollama_model_registry', $e, $results);
        }

        // 10. LLM Pool Health Check (All LLM instances - Ollama, Claude, future providers)
        try {
            $poolManager = app(\App\Services\LLMPoolManagerService::class);

            // Run health check on all instances
            $healthResults = $poolManager->healthCheckAllInstances();

            $results['actions']['llm_pool_health'] = [
                'performed' => true,
                'instances_checked' => $healthResults['checked'] ?? 0,
                'healthy' => $healthResults['healthy'] ?? 0,
                'unhealthy' => $healthResults['unhealthy'] ?? 0,
                'recovered' => $healthResults['recovered'] ?? 0,
                'degraded' => $healthResults['degraded'] ?? 0,
            ];

            // Alert if any instances are unhealthy
            if (($healthResults['unhealthy'] ?? 0) > 0) {
                Log::warning('Ops: LLM instances unhealthy', [
                    'unhealthy_count' => $healthResults['unhealthy'],
                    'details' => array_filter($healthResults['details'] ?? [], fn ($d) => ! $d['healthy']),
                ]);
            }

            // Log recoveries
            if (($healthResults['recovered'] ?? 0) > 0) {
                Log::info('Ops: LLM instances recovered', [
                    'recovered_count' => $healthResults['recovered'],
                ]);
            }

            Log::info('Ops: LLM pool health check complete', $results['actions']['llm_pool_health']);
        } catch (\Exception $e) {
            $this->logPhaseError('llm_pool_health', $e, $results);
        }

        // 10b. Compute Instance Health Check & Circuit Recovery
        try {
            $computeRouter = app(\App\Services\ComputeRouterService::class);
            $computeResults = $computeRouter->probeUnhealthyInstances();

            $circuitsReset = count(array_filter($computeResults, fn ($r) => $r['circuit_reset'] ?? false));
            $results['actions']['compute_health_check'] = [
                'performed' => true,
                'instances_probed' => count($computeResults),
                'circuits_reset' => $circuitsReset,
            ];

            if ($circuitsReset > 0) {
                Log::info('Ops: Compute circuits auto-recovered', [
                    'reset_count' => $circuitsReset,
                    'details' => $computeResults,
                ]);
            }

            Log::info('Ops: Compute instance health check complete', $results['actions']['compute_health_check']);
        } catch (\Exception $e) {
            $this->logPhaseError('compute_health_check', $e, $results);
        }

        // 11. RSS Feed Self-Healing (detect redirects, auto-correct, mark dead feeds)
        try {
            $rssHealthService = app(\App\Services\RssFeedHealthService::class);

            // First, check all workflow feeds for health
            $feedCheckResults = $rssHealthService->checkAllWorkflowFeeds();
            $failedFeeds = count(array_filter($feedCheckResults, fn ($r) => ! $r['success']));
            $redirectedFeeds = count(array_filter($feedCheckResults, fn ($r) => $r['redirect_detected'] ?? false));

            // Then run self-healing (auto-correct redirects, mark dead)
            $selfHealResults = $rssHealthService->runSelfHealing();

            $results['actions']['rss_self_healing'] = [
                'performed' => true,
                'feeds_checked' => count($feedCheckResults),
                'failed_feeds' => $failedFeeds,
                'redirected_feeds' => $redirectedFeeds,
                'auto_corrected' => $selfHealResults['auto_corrected'] ?? 0,
                'marked_dead' => $selfHealResults['marked_dead'] ?? 0,
                'errors' => count($selfHealResults['errors'] ?? []),
            ];

            if (! empty($selfHealResults['corrections'])) {
                Log::info('Ops: RSS feeds auto-corrected', [
                    'corrections' => array_map(fn ($c) => [
                        'old' => parse_url($c['old_url'], PHP_URL_HOST),
                        'new' => parse_url($c['new_url'], PHP_URL_HOST),
                    ], $selfHealResults['corrections']),
                ]);
            }

            if (! empty($selfHealResults['dead_feeds'])) {
                Log::warning('Ops: RSS feeds marked as dead', [
                    'feeds' => array_map(fn ($f) => parse_url($f['url'], PHP_URL_HOST), $selfHealResults['dead_feeds']),
                ]);
            }

            Log::info('Ops: RSS self-healing complete', $results['actions']['rss_self_healing']);
        } catch (\Exception $e) {
            $this->logPhaseError('rss_self_healing', $e, $results);
        }

        // 12. Dead Letter Queue Maintenance
        try {
            $results['actions']['dlq_maintenance'] = [
                'performed' => false,
                'disabled' => true,
                'message' => 'DLQ is decommissioned; maintenance skipped',
            ];
        } catch (\Exception $e) {
            $this->logPhaseError('dlq_maintenance', $e, $results);
        }

        // 13. Semantic Cache Pruning (prevent Redis memory bloat)
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection(config('cache.stores.redis.connection', 'cache'));
            $cachePrefix = config('cache.prefix', config('database.redis.options.prefix', ''));
            $dbPrefix = config('database.redis.options.prefix', '');

            // Count semantic cache keys
            $cacheKeys = $redis->keys($cachePrefix.'semantic_cache_*');
            $initialCount = count($cacheKeys);

            // If over threshold (1000 keys), prune oldest 20%
            if ($initialCount > 1000) {
                $keysWithTtl = [];
                foreach ($cacheKeys as $key) {
                    $cleanKey = str_starts_with($key, $dbPrefix) ? substr($key, strlen($dbPrefix)) : $key;
                    $ttl = $redis->ttl($cleanKey);
                    $keysWithTtl[$cleanKey] = $ttl;
                }

                // Sort by TTL ascending (shortest TTL = oldest/expiring soonest)
                asort($keysWithTtl);

                // Delete the oldest 20%
                $toDelete = array_slice(array_keys($keysWithTtl), 0, (int) ($initialCount * 0.2));
                foreach ($toDelete as $key) {
                    $redis->del($key);
                }

                $results['actions']['semantic_cache_pruning'] = [
                    'performed' => true,
                    'initial_count' => $initialCount,
                    'pruned' => count($toDelete),
                    'remaining' => $initialCount - count($toDelete),
                ];
                Log::info('Ops: Semantic cache pruned', $results['actions']['semantic_cache_pruning']);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('semantic_cache_pruning', $e, $results);
        }

        // 14. Research Rejections Cleanup (old rejection records)
        try {
            $cleanupCount = DB::connection('pgsql_rag')->delete(
                "DELETE FROM research_rejections WHERE created_at < NOW() - INTERVAL '30 days'"
            );

            if ($cleanupCount > 0) {
                $results['actions']['research_rejection_cleanup'] = [
                    'performed' => true,
                    'records_deleted' => $cleanupCount,
                ];
                Log::info('Ops: Research rejections cleaned up', ['count' => $cleanupCount]);
            }
        } catch (\Exception $e) {
            // Table may not exist - not critical
            $this->logPhaseError('research_rejection_cleanup', $e, $results);
        }

        // 15. GPU Lock Cleanup (stale ollama_busy_lock, whisper_gpu_lock preventing jobs)
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $prefix = config('database.redis.options.prefix', '');
            $staleLocks = [];

            $gpuLocks = ['ollama_busy_lock', 'whisper_gpu_lock', 'claude_cli_slots'];
            foreach ($gpuLocks as $lockName) {
                $ttl = $redis->ttl($lockName);
                // If lock exists with no TTL (-1) or abnormally long TTL (> 30 min for GPU, > 1 hour for CLI)
                $maxTtl = ($lockName === 'claude_cli_slots') ? 3600 : 1800;
                if ($ttl === -1 || $ttl > $maxTtl) {
                    $redis->del($lockName);
                    $staleLocks[] = $lockName;
                    Log::info('Ops: Cleared stale GPU lock', ['lock' => $lockName, 'ttl' => $ttl]);
                }
            }

            if (! empty($staleLocks)) {
                $results['actions']['gpu_lock_cleanup'] = [
                    'performed' => true,
                    'locks_cleared' => $staleLocks,
                ];
            }
        } catch (\Exception $e) {
            $this->logPhaseError('gpu_lock_cleanup', $e, $results);
        }

        // 16. Auto-resolve stale system issues (not seen in 24 hours)
        try {
            $resolved = DB::update(
                "UPDATE system_issues SET status = 'resolved', resolved_at = NOW(), resolved_by = 'auto', resolution_notes = 'Not detected in 24 hours', updated_at = NOW() WHERE status = 'open' AND severity IN ('info', 'warning') AND last_seen_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );

            if ($resolved > 0) {
                $results['actions']['stale_issue_resolution'] = [
                    'performed' => true,
                    'issues_resolved' => $resolved,
                ];
                Log::info('Ops: Auto-resolved stale issues', ['count' => $resolved]);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('stale_issue_resolution', $e, $results);
        }

        // 17. Horizon Queue Maintenance (stuck reserved jobs, failed job cleanup)
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $queues = array_values(array_unique(array_filter([
                config('queue.connections.redis.queue', 'default'),
                'high',
                'default',
                'low',
                'long-running',
                'workflow',
                'speculative',
            ])));
            $stuckJobsCleared = 0;
            $prefix = config('database.redis.options.prefix', '');

            foreach ($queues as $queue) {
                // Check reserved jobs (currently being processed)
                // Jobs stuck in reserved for >2 hours are likely orphaned
                $reservedKey = "queues:{$queue}:reserved";
                $stuckThreshold = time() - 7200; // 2 hours ago

                // ZRANGEBYSCORE returns jobs with score (reserved_at timestamp) older than threshold
                $stuckJobs = $redis->zrangebyscore($reservedKey, 0, $stuckThreshold);

                if (! empty($stuckJobs)) {
                    foreach ($stuckJobs as $job) {
                        // Remove from reserved and push back to queue for retry
                        $redis->zrem($reservedKey, $job);
                        $redis->rpush("queues:{$queue}", $job);
                        $stuckJobsCleared++;
                    }
                    Log::warning("Ops: Cleared stuck jobs from {$queue} queue", ['count' => count($stuckJobs)]);
                }
            }

            // Clean up old failed jobs (older than 7 days)
            $failedCleaned = 0;
            try {
                $failedCleaned = DB::delete(
                    'DELETE FROM failed_jobs WHERE failed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
                );
            } catch (\Exception $e) {
                // Table may not exist
            }

            // Check if Horizon is not running and try to restart
            $horizonProcess = trim(\Illuminate\Support\Facades\Process::timeout(5)->run([
                'pgrep',
                '-f',
                'artisan horizon$',
            ])->output());
            $horizonRestarted = false;

            if (empty($horizonProcess)) {
                // Try to restart Horizon via systemd if available
                $systemdStatus = trim(\Illuminate\Support\Facades\Process::timeout(5)->run([
                    'systemctl',
                    'is-enabled',
                    'laravel-horizon.service',
                ])->output());
                if ($systemdStatus === 'enabled') {
                    \Illuminate\Support\Facades\Process::timeout(15)->run([
                        'sudo',
                        'systemctl',
                        'restart',
                        'laravel-horizon.service',
                    ]);
                    $horizonRestarted = true;
                    Log::warning('Ops: Horizon was not running, attempted restart via systemd');
                }
            }

            if ($stuckJobsCleared > 0 || $failedCleaned > 0 || $horizonRestarted) {
                $results['actions']['horizon_maintenance'] = [
                    'performed' => true,
                    'stuck_jobs_requeued' => $stuckJobsCleared,
                    'old_failed_cleaned' => $failedCleaned,
                    'horizon_restarted' => $horizonRestarted,
                ];
                Log::info('Ops: Horizon maintenance complete', $results['actions']['horizon_maintenance']);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('horizon_maintenance', $e, $results);
        }

        // 18. Email bounce cleanup removed (D1: tables dropped, Thunderbird handles bounces)

        // 19. Data Broker Health Batch Check (weekly-ish, check oldest 20)
        try {
            $tableExists = DB::select("SHOW TABLES LIKE 'data_brokers'");
            if (! empty($tableExists)) {
                // Only run if it's been >24h since last batch check
                $lastBatchCheck = Cache::get('ops:broker_health_batch_last');
                if (! $lastBatchCheck || now()->diffInHours($lastBatchCheck) >= 24) {
                    $brokerHealthService = app(\App\Services\DataRemoval\BrokerHealthService::class);
                    $batchResult = $brokerHealthService->batchHealthCheck(20);

                    Cache::put('ops:broker_health_batch_last', now(), now()->addDays(7));

                    $results['actions']['broker_health_check'] = [
                        'performed' => true,
                        'checked' => $batchResult['checked'] ?? 0,
                        'broken' => $batchResult['broken'] ?? 0,
                        'degraded' => $batchResult['degraded'] ?? 0,
                    ];
                    Log::info('Ops: Broker health batch check complete', $results['actions']['broker_health_check']);
                }
            }
        } catch (\Exception $e) {
            $this->logPhaseError('broker_health_check', $e, $results);
        }

        // 20. YouTube Transcript Cleanup (orphaned transcripts, stale cache)
        try {
            $tableExists = DB::select("SHOW TABLES LIKE 'youtube_transcripts'");
            if (! empty($tableExists)) {
                // Clean orphaned transcripts (video no longer in any workflow/playlist)
                // Only clean transcripts older than 30 days that aren't in RAG
                $orphanedCleaned = 0;

                // rag_documents is PostgreSQL, youtube_transcripts is MySQL — cannot cross-join
                try {
                    $ragIds = DB::connection('pgsql_rag')->select(
                        "SELECT CAST(source_id AS INTEGER) as source_id FROM rag_documents
                         WHERE source_type = 'youtube_transcript' AND source_id IS NOT NULL"
                    );
                    $preserveIds = array_map(fn ($r) => $r->source_id, $ragIds);

                    if (! empty($preserveIds)) {
                        $placeholders = implode(',', array_fill(0, count($preserveIds), '?'));
                        $orphanedCleaned = DB::delete(
                            "DELETE FROM youtube_transcripts
                             WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                             AND id NOT IN ({$placeholders})",
                            $preserveIds
                        );
                    } else {
                        $orphanedCleaned = DB::delete(
                            'DELETE FROM youtube_transcripts
                             WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
                        );
                    }
                } catch (\Exception $e) {
                    $orphanedCleaned = DB::delete(
                        'DELETE FROM youtube_transcripts WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
                    );
                }

                if ($orphanedCleaned > 0) {
                    $results['actions']['youtube_cleanup'] = [
                        'performed' => true,
                        'transcripts_cleaned' => $orphanedCleaned,
                    ];
                    Log::info('Ops: YouTube cleanup complete', $results['actions']['youtube_cleanup']);
                }
            }
        } catch (\Exception $e) {
            $this->logPhaseError('youtube_cleanup', $e, $results);
        }

        // 21. Research Pipeline Cleanup (cache, old missions, failing sources reset)
        try {
            $db = DB::connection('pgsql_rag');

            // Clean old research cache (>7 days)
            $cacheDeleted = $db->delete(
                "DELETE FROM research_cache WHERE cached_at < NOW() - INTERVAL '7 days'"
            );

            // Clean old completed research missions (>30 days)
            $missionsDeleted = 0;
            try {
                $missionsDeleted = $db->delete(
                    "DELETE FROM research_missions WHERE status = 'completed' AND created_at < NOW() - INTERVAL '30 days'"
                );
            } catch (\Exception $e) {
                // Table may not exist
            }

            // Clean old research facts (>90 days, not referenced)
            $factsDeleted = 0;
            try {
                $factsDeleted = $db->delete(
                    "DELETE FROM research_facts WHERE created_at < NOW() - INTERVAL '90 days'"
                );
            } catch (\Exception $e) {
                // Table may not exist
            }

            // Reset high-failure sources for retry (failure_count > 10, last attempt >7 days ago)
            $sourcesReset = 0;
            try {
                $sourcesReset = $db->update(
                    "UPDATE research_sources
                     SET failure_count = 0, is_active = true
                     WHERE failure_count > 10
                     AND (last_failure_at IS NULL OR last_failure_at < NOW() - INTERVAL '7 days')"
                );
            } catch (\Exception $e) {
                // Column may not exist
            }

            if ($cacheDeleted > 0 || $missionsDeleted > 0 || $factsDeleted > 0 || $sourcesReset > 0) {
                $results['actions']['research_cleanup'] = [
                    'performed' => true,
                    'cache_deleted' => $cacheDeleted,
                    'missions_deleted' => $missionsDeleted,
                    'facts_deleted' => $factsDeleted,
                    'sources_reset' => $sourcesReset,
                ];
                Log::info('Ops: Research cleanup complete', $results['actions']['research_cleanup']);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('research_cleanup', $e, $results);
        }

        // 22. Nextcloud Calendar & Contacts Sync (Data Scanning Sprint)
        try {
            $nextcloudService = app(NextcloudService::class);

            // Sync calendar events to MySQL (12 months back, 6 months forward)
            $calendarResult = $nextcloudService->syncCalendarEventsToDatabase(12, 6);

            // Sync contacts to MySQL
            $contactsResult = $nextcloudService->syncContactsToDatabase();

            $results['actions']['nextcloud_sync'] = [
                'performed' => true,
                'calendar_fetched' => $calendarResult['fetched'] ?? 0,
                'calendar_inserted' => $calendarResult['persisted']['inserted'] ?? 0,
                'calendar_updated' => $calendarResult['persisted']['updated'] ?? 0,
                'contacts_fetched' => $contactsResult['fetched'] ?? 0,
                'contacts_inserted' => $contactsResult['persisted']['inserted'] ?? 0,
                'contacts_updated' => $contactsResult['persisted']['updated'] ?? 0,
            ];
            Log::info('Ops: Nextcloud sync complete', $results['actions']['nextcloud_sync']);
        } catch (\Exception $e) {
            $this->logPhaseError('nextcloud_sync', $e, $results);
        }

        // 23. Genealogy RAG Index (incremental, persons not yet indexed)
        try {
            // Run genealogy:rag-index command for new/updated persons
            $exitCode = \Illuminate\Support\Facades\Artisan::call('genealogy:rag-index', [
                '--limit' => 100,
            ]);

            $output = \Illuminate\Support\Facades\Artisan::output();
            $indexed = 0;
            if (preg_match('/Indexed (\d+)/', $output, $matches)) {
                $indexed = (int) $matches[1];
            }

            if ($indexed > 0 || $exitCode === 0) {
                $results['actions']['genealogy_rag'] = [
                    'performed' => true,
                    'indexed' => $indexed,
                    'exit_code' => $exitCode,
                ];
                Log::info('Ops: Genealogy RAG index complete', $results['actions']['genealogy_rag']);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('genealogy_rag', $e, $results);
        }

        // 24. System Errors & Alerts Cleanup
        try {
            // Resolved errors older than 30 days
            $errorsResolved = DB::delete(
                'DELETE FROM system_errors WHERE resolved_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            // Unresolved errors older than 90 days
            $errorsUnresolved = DB::delete(
                'DELETE FROM system_errors WHERE resolved_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
            );
            // Resolved alerts older than 30 days
            $alertsResolved = DB::delete(
                'DELETE FROM system_alerts WHERE resolved_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            // Unresolved alerts older than 90 days
            $alertsUnresolved = DB::delete(
                'DELETE FROM system_alerts WHERE resolved_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
            );

            $totalCleaned = $errorsResolved + $errorsUnresolved + $alertsResolved + $alertsUnresolved;
            if ($totalCleaned > 0) {
                $results['actions']['error_alert_cleanup'] = [
                    'performed' => true,
                    'errors_resolved' => $errorsResolved,
                    'errors_unresolved' => $errorsUnresolved,
                    'alerts_resolved' => $alertsResolved,
                    'alerts_unresolved' => $alertsUnresolved,
                ];
                Log::info('Ops: System errors/alerts cleanup complete', $results['actions']['error_alert_cleanup']);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('error_alert_cleanup', $e, $results);
        }

        // 25. News Articles Cleanup (old articles not in RAG)
        try {
            $newsService = app(NewsArticleService::class);
            $cleanedCount = $newsService->cleanupOldArticles(90);

            if ($cleanedCount > 0) {
                $results['actions']['news_cleanup'] = [
                    'performed' => true,
                    'deleted' => $cleanedCount,
                ];
                Log::info('Ops: News articles cleanup complete', $results['actions']['news_cleanup']);
            }
        } catch (\Exception $e) {
            $this->logPhaseError('news_cleanup', $e, $results);
        }

        return $results;
    }

    // ========================================
    // DATABASE MAINTENANCE METHODS (from DailyMaintenance)
    // ========================================

    /**
     * Backup databases (MySQL and PostgreSQL)
     */
    private function backupDatabases(): array
    {
        $results = ['mysql' => 'skipped', 'postgres' => 'skipped'];
        $backupDir = $this->resolveWritableBackupDir();
        $timestamp = now()->format('Y-m-d_His');

        // MySQL Backup — dump directly to file to avoid loading ~500MB into PHP memory
        try {
            $mysqlFile = "{$backupDir}/mysql_backup_{$timestamp}.sql";
            $mysqlHost = config('database.connections.mysql.host', '127.0.0.1');
            $mysqlPort = config('database.connections.mysql.port', '3306');
            $mysqlDatabase = config('database.connections.mysql.database');
            $mysqlUsername = config('database.connections.mysql.username');
            $mysqlPassword = config('database.connections.mysql.password', '');

            $stderrFile = tempnam(sys_get_temp_dir(), 'mysqlbackup_');
            $exitCode = $this->runDumpProcess(
                [
                    'mysqldump',
                    '-h', $mysqlHost,
                    '-P', (string) $mysqlPort,
                    '-u', $mysqlUsername,
                    $mysqlDatabase,
                ],
                ['MYSQL_PWD' => $mysqlPassword],
                $mysqlFile,
                $stderrFile
            );
            $stderr = file_exists($stderrFile) ? trim(file_get_contents($stderrFile)) : '';
            @unlink($stderrFile);
            $fileSize = file_exists($mysqlFile) ? filesize($mysqlFile) : 0;

            if ($exitCode === 0 && $fileSize > 1000) {
                $results['mysql'] = round($fileSize / 1024 / 1024, 2).'MB';
                $this->cleanOldBackups($backupDir, 'mysql_backup_*.sql');
                Log::info('MySQL backup created', ['file' => basename($mysqlFile), 'size' => $results['mysql']]);
            } else {
                $results['mysql'] = 'failed';
                Log::error('MySQL backup failed', ['stderr' => substr($stderr ?? '', 0, 500), 'fileSize' => $fileSize]);
                if (file_exists($mysqlFile) && $fileSize === 0) {
                    File::delete($mysqlFile);
                }
            }
        } catch (\Exception $e) {
            $results['mysql'] = 'error: '.$e->getMessage();
            Log::error('MySQL backup exception', ['error' => $e->getMessage()]);
        }

        // PostgreSQL Backup — dump directly to file, check stderr separately
        try {
            $pgFile = "{$backupDir}/postgres_backup_{$timestamp}.sql";
            $pgHost = config('database.connections.pgsql_rag.host', '127.0.0.1');
            $pgPort = config('database.connections.pgsql_rag.port', '5432');
            $pgDatabase = config('database.connections.pgsql_rag.database');
            $pgUsername = config('database.connections.pgsql_rag.username');
            $pgPassword = config('database.connections.pgsql_rag.password', '');

            $stderrFile = tempnam(sys_get_temp_dir(), 'pgbackup_');
            $exitCode = $this->runDumpProcess(
                [
                    'pg_dump',
                    '-h', $pgHost,
                    '-p', (string) $pgPort,
                    '-U', $pgUsername,
                    '-F', 'p',
                    $pgDatabase,
                ],
                ['PGPASSWORD' => $pgPassword],
                $pgFile,
                $stderrFile
            );
            $stderr = file_exists($stderrFile) ? trim(file_get_contents($stderrFile)) : '';
            @unlink($stderrFile);
            $fileSize = file_exists($pgFile) ? filesize($pgFile) : 0;

            if ($exitCode === 0 && $fileSize > 1000) {
                $results['postgres'] = round($fileSize / 1024 / 1024, 2).'MB';
                $this->cleanOldBackups($backupDir, 'postgres_backup_*.sql');
                Log::info('PostgreSQL backup created', ['file' => basename($pgFile), 'size' => $results['postgres']]);
            } else {
                $results['postgres'] = 'failed';
                Log::error('PostgreSQL backup failed', ['exitCode' => $exitCode, 'stderr' => substr($stderr, 0, 500), 'fileSize' => $fileSize]);
                if (file_exists($pgFile) && $fileSize === 0) {
                    File::delete($pgFile);
                }
            }
        } catch (\Exception $e) {
            $results['postgres'] = 'error: '.$e->getMessage();
            Log::error('PostgreSQL backup exception', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    private function resolveWritableBackupDir(): string
    {
        $candidates = [
            storage_path('backups'),
            storage_path('app/backups'),
        ];

        foreach ($candidates as $dir) {
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            clearstatcache(true, $dir);

            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }

            Log::warning('Ops: Backup directory not writable, trying fallback', [
                'dir' => $dir,
            ]);
        }

        throw new \RuntimeException('No writable backup directory available');
    }

    private function runDumpProcess(array $command, array $env, string $stdoutFile, string $stderrFile, int $timeoutSeconds = 300): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, array_merge($_ENV, $env));

        if (! is_resource($process)) {
            throw new \RuntimeException('Failed to start dump process');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $status = proc_get_status($process);
            if (! (($status['running'] ?? false) === true)) {
                return (int) ($status['exitcode'] ?? proc_close($process));
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
                throw new \RuntimeException("Dump process timed out after {$timeoutSeconds}s");
            }

            usleep(100_000);
        }
    }

    /**
     * Clean old backup files, keeping only the most recent N
     */
    private function cleanOldBackups(string $backupDir, string $pattern, int $keep = 5): void
    {
        $backups = glob("{$backupDir}/{$pattern}");
        if (count($backups) <= $keep) {
            return;
        }

        usort($backups, fn ($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($backups, $keep) as $file) {
            File::delete($file);
        }
    }

    /**
     * Check for failed scheduled jobs and send Pushover alert
     */
    private function checkFailedScheduledJobs(): array
    {
        try {
            // Find jobs that failed in last 24 hours or are still running
            $failedJobs = DB::select("
                SELECT id, name, command, last_run_status, last_run_at, last_run_output, timeout_minutes
                FROM scheduled_jobs
                WHERE enabled = 1
                  AND COALESCE(stall_exempt, 0) = 0
                  AND COALESCE(job_type, '') <> 'agent_task'
                  AND last_run_status IN ('failed', 'running')
                  AND last_run_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");

            if (empty($failedJobs)) {
                return [];
            }

            $alerts = [];
            $stuckJobIds = [];

            foreach ($failedJobs as $job) {
                // Check if 'running' status is actually stuck
                // Use job's configured timeout_minutes + 10 min buffer, or default 60 min if not set
                if ($job->last_run_status === 'running') {
                    // Check for adaptive timeout extension (Redis deadline key)
                    $deadline = \Illuminate\Support\Facades\Cache::get("scheduler:job:{$job->id}:deadline");
                    if ($deadline && $deadline > time()) {
                        continue; // Job has active timeout extension — not stuck
                    }

                    $runTime = now()->diffInMinutes(\Carbon\Carbon::parse($job->last_run_at));
                    $allowedRuntime = ($job->timeout_minutes ?? 60) + 10; // Job timeout + 10 min buffer
                    if ($runTime < $allowedRuntime) {
                        continue; // Still running within allowed time (job timeout + buffer)
                    }
                    // This job is stuck - mark for auto-fix
                    $stuckJobIds[] = $job->name;
                }

                $alerts[] = [
                    'name' => $job->name,
                    'status' => $job->last_run_status,
                    'last_run' => $job->last_run_at,
                    'timeout_minutes' => $job->timeout_minutes,
                    'output' => substr($job->last_run_output ?? '', 0, 200),
                ];
            }

            // Auto-fix stuck jobs using ScheduledJobService
            if (! empty($stuckJobIds)) {
                $schedulerService = app(\App\Services\ScheduledJobService::class);
                $fixed = $schedulerService->fixStuckJobs();
                Log::info('Ops: Auto-fixed stuck scheduled jobs', ['count' => $fixed, 'jobs' => $stuckJobIds]);
            }

            // Failed jobs logged — daily report covers visibility
            if (! empty($alerts)) {
                $jobNames = implode(', ', array_column($alerts, 'name'));
                Log::warning('Ops: Failed scheduled jobs detected', ['jobs' => $jobNames]);
            }

            return $alerts;

        } catch (\Exception $e) {
            Log::warning('Ops: Failed to check scheduled jobs', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Clean old failed jobs (>30 days)
     */
    private function cleanOldFailedJobs(): int
    {
        try {
            $threshold = now()->subDays(30);

            return DB::delete('DELETE FROM failed_jobs WHERE failed_at < ? LIMIT 10000', [$threshold]);
        } catch (\Exception $e) {
            Log::warning('Failed to clean old failed jobs', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Expire agent sessions stuck in 'active' with no activity for >2 hours.
     */
    private function expireStaleAgentSessions(): int
    {
        try {
            $expired = DB::update(
                "UPDATE agent_sessions SET status = 'expired', updated_at = NOW()
                 WHERE status = 'active'
                 AND last_activity_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
            );
            if ($expired > 0) {
                Log::info('Ops: Expired stale agent sessions', ['count' => $expired]);
            }

            return $expired;
        } catch (\Exception $e) {
            Log::warning('Ops: Failed to expire stale sessions', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Clean old workflow runs (>90 days)
     */
    private function cleanOldWorkflowRuns(): int
    {
        try {
            $threshold = now()->subDays(90);
            $oldRuns = DB::select('SELECT id FROM workflow_runs WHERE started_at < ?', [$threshold]);
            $oldIds = array_column($oldRuns, 'id');

            if (empty($oldIds)) {
                return 0;
            }

            $oldIds = array_slice($oldIds, 0, 5000);

            // Delete related data first (cascade should handle this but be safe)
            $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
            DB::delete("DELETE FROM workflow_run_outputs WHERE run_id IN ({$placeholders})", $oldIds);
            DB::delete("DELETE FROM workflow_run_inputs WHERE run_id IN ({$placeholders})", $oldIds);

            return DB::delete("DELETE FROM workflow_runs WHERE id IN ({$placeholders})", $oldIds);
        } catch (\Exception $e) {
            Log::warning('Failed to clean old workflow runs', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Clean orphaned workflow data
     */
    private function cleanOrphanedData(): array
    {
        $results = ['outputs' => 0, 'inputs' => 0];

        try {
            // Orphaned outputs
            $results['outputs'] = DB::statement('
                DELETE workflow_run_outputs FROM workflow_run_outputs
                LEFT JOIN workflow_runs ON workflow_run_outputs.run_id = workflow_runs.id
                WHERE workflow_runs.id IS NULL
            ') ? 1 : 0;

            // Orphaned inputs
            $results['inputs'] = DB::statement('
                DELETE workflow_run_inputs FROM workflow_run_inputs
                LEFT JOIN workflow_runs ON workflow_run_inputs.run_id = workflow_runs.id
                WHERE workflow_runs.id IS NULL
            ') ? 1 : 0;
        } catch (\Exception $e) {
            Log::warning('Failed to clean orphaned data', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Rotate large log files (>10MB)
     */
    private function rotateLogs(): int
    {
        $logPath = storage_path('logs');
        $maxSize = 10 * 1024 * 1024; // 10MB
        $rotatedCount = 0;

        if (! File::exists($logPath)) {
            return 0;
        }

        foreach (File::files($logPath) as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            if ($file->getSize() > $maxSize) {
                $archiveName = $file->getPath().'/'.
                    $file->getBasename('.log').
                    '-'.date('Y-m-d-His').'.log.archive';

                File::move($file->getPathname(), $archiveName);
                File::put($file->getPathname(), '');
                $rotatedCount++;

                Log::info('Rotated large log file', ['file' => $file->getFilename()]);
            }
        }

        return $rotatedCount;
    }

    /**
     * Optimize database tables
     */
    private function optimizeDatabase(): int
    {
        $tables = ['workflow_runs', 'workflow_run_outputs', 'workflow_run_inputs',
            'workflows', 'workflow_nodes', 'jobs', 'failed_jobs'];
        $optimized = 0;

        foreach ($tables as $table) {
            try {
                DB::statement("ANALYZE TABLE {$table}");
                $optimized++;
            } catch (\Exception $e) {
                // Table might not exist or optimization not supported
            }
        }

        return $optimized;
    }

    /**
     * Purge old agent_recursion_calls rows (INF-17).
     *
     * Table grows ~400K rows/day (11.6M rows, 12.8GB as of 2026-03-29).
     * Summary data already exists in recursion_effectiveness table.
     * Deletes in batches of 50K to avoid long table locks, max 500K per run.
     */
    private function purgeOldRecursionCalls(): int
    {
        $retentionDays = (int) config('recursion.retention_days', 30);

        try {
            $result = app(AgentRecursionCallsRetentionService::class)->collect(
                execute: true,
                retentionDays: $retentionDays,
                batchSize: 10_000,
                maxRows: 50_000,
                sleepMs: 100,
            );
            $totalDeleted = (int) ($result['deleted_rows'] ?? 0);

            if ($totalDeleted > 0) {
                Log::info('Ops: Purged old agent_recursion_calls', [
                    'deleted' => $totalDeleted,
                    'retention_days' => $retentionDays,
                    'status' => $result['status'] ?? 'unknown',
                    'stopped_reason' => $result['stopped_reason'] ?? 'unknown',
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Ops: Failed to purge agent_recursion_calls', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        return $totalDeleted;
    }

    private function dedicatedBiasMaintenanceEnabled(): bool
    {
        try {
            $row = DB::selectOne(
                "SELECT enabled FROM scheduled_jobs WHERE name = 'bias_data_refresh' LIMIT 1"
            );

            return (int) ($row->enabled ?? 0) === 1;
        } catch (\Throwable $e) {
            Log::warning('Ops: Failed to check dedicated bias maintenance schedule', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Log a phase error and store it in the results array.
     * Eliminates the silent-catch pattern where errors were stored but never logged.
     */
    private function logPhaseError(string $phase, \Exception $e, array &$results): void
    {
        $results['errors'][$phase] = $e->getMessage();
        Log::warning("OpsMaintenanceJob: {$phase} failed", [
            'error' => $e->getMessage(),
        ]);
    }

    private function nextcloudLibraryRoot(): string
    {
        return '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
    }
}

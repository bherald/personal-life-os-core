<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

/**
 * Deterministic pre-screen for monitoring agents (ai-ops, system-guardian, log-analyst).
 *
 * Runs SQL/threshold checks before the agent loop. If all healthy, returns
 * a structured "all clear" result so the agent can skip the LLM call entirely.
 * Only returns null (proceed with LLM) when anomalies are detected.
 *
 * Token savings: ~70% of monitoring agent runs result in "all clear" — this
 * avoids burning 8-18K tokens per run on threshold checks the LLM doesn't
 * need to reason about.
 */
class MonitoringPreScreenService
{
    /**
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

    private function getTotalQueueDepth(): int
    {
        $total = 0;

        foreach ($this->getActiveQueueNames() as $queueName) {
            $total += (int) Redis::llen("queues:{$queueName}");
        }

        return $total;
    }

    /**
     * Pre-screen for ai-ops agent. Returns structured result if healthy, null if LLM needed.
     */
    public function preScreenAiOps(): ?array
    {
        $issues = [];

        try {
            // Check for failed jobs in the last 30 minutes.
            $failedJobs = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM scheduled_jobs WHERE enabled = 1 AND last_run_status IN ('failed','timeout') AND last_run_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            )?->c ?? 0);
            if ($failedJobs > 0) {
                $issues[] = "failed_jobs:{$failedJobs}";
            }

            // Check for stalled jobs
            $stalledJobs = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM scheduled_jobs WHERE last_run_status = 'running' AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes, 30) MINUTE) AND stall_exempt = 0 AND COALESCE(job_type, '') <> 'agent_task' AND enabled = 1"
            )?->c ?? 0);
            if ($stalledJobs > 0) {
                $issues[] = "stalled_jobs:{$stalledJobs}";
            }

            // Check circuit breakers
            $openCircuits = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM llm_instances WHERE is_active = 1 AND circuit_state = 'open'"
            )?->c ?? 0);
            if ($openCircuits > 0) {
                $issues[] = "open_circuits:{$openCircuits}";
            }

            // Check system load
            $load = sys_getloadavg();
            $cpuCount = (int) trim(Process::timeout(5)->run(['nproc'])->output() ?: '4');
            if ($load[0] / $cpuCount > 3.0) {
                $issues[] = 'high_load:'.round($load[0], 1);
            }

            // Check queue depth
            try {
                $queueDepth = $this->getTotalQueueDepth();
                if ($queueDepth > 100) {
                    $issues[] = "queue_depth:{$queueDepth}";
                }
            } catch (\Exception $e) {
                $issues[] = 'redis_error';
            }

            // Check Claude CLI auth only when the provider is intentionally enabled.
            $aiOps = app(AIOperationsService::class);
            if ($aiOps->isClaudeCliEnabled()) {
                $claudeAuth = $aiOps->checkClaudeCliAuth();
                if (! ($claudeAuth['logged_in'] ?? false)) {
                    $issues[] = 'claude_cli_not_logged_in';
                }
            }

        } catch (\Exception $e) {
            // If pre-screen itself fails, let LLM handle it
            Log::debug('MonitoringPreScreen: ai-ops check failed, deferring to LLM', ['error' => $e->getMessage()]);

            return null;
        }

        if (! empty($issues)) {
            Log::info('MonitoringPreScreen: ai-ops anomalies detected, LLM needed', ['issues' => $issues]);

            return null; // Proceed with LLM
        }

        return [
            'status' => 'healthy',
            'message' => 'All systems nominal — no anomalies detected. Jobs running, circuits closed, load normal.',
            'checks' => ['failed_jobs' => 0, 'stalled_jobs' => 0, 'open_circuits' => 0, 'load' => round($load[0], 1), 'queue' => 0],
        ];
    }

    /**
     * Pre-screen for system-guardian agent.
     */
    public function preScreenSystemGuardian(): ?array
    {
        $issues = [];

        try {
            // Disk space
            $rootPct = (int) round((1 - disk_free_space('/') / disk_total_space('/')) * 100);
            if ($rootPct > 85) {
                $issues[] = "disk_root:{$rootPct}%";
            }

            // Active critical/high alerts
            $alerts = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM system_alerts WHERE severity IN ('critical','high') AND resolved_at IS NULL AND triggered_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            )?->c ?? 0);
            if ($alerts > 0) {
                $issues[] = "active_alerts:{$alerts}";
            }

            // Queue depth
            try {
                $queueDepth = $this->getTotalQueueDepth();
                if ($queueDepth > 500) {
                    $issues[] = "queue:{$queueDepth}";
                }
            } catch (\Exception $e) {
                $issues[] = 'redis_error';
            }

            // Workflow failures in last hour
            $wfFails = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM workflow_runs WHERE status = 'failed' AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            )?->c ?? 0);
            if ($wfFails > 0) {
                $issues[] = "workflow_fails:{$wfFails}";
            }

            // Memory check
            $memInfo = @file_get_contents('/proc/meminfo');
            if ($memInfo && preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m)) {
                $freeMb = (int) ($m[1] / 1024);
                if ($freeMb < 512) {
                    $issues[] = "low_memory:{$freeMb}MB";
                }
            }

        } catch (\Exception $e) {
            Log::debug('MonitoringPreScreen: system-guardian check failed, deferring to LLM', ['error' => $e->getMessage()]);

            return null;
        }

        if (! empty($issues)) {
            Log::info('MonitoringPreScreen: system-guardian anomalies detected, LLM needed', ['issues' => $issues]);

            return null;
        }

        return [
            'status' => 'healthy',
            'message' => 'System healthy — disk OK, no alerts, queues clear, memory adequate.',
            'checks' => ['disk_root' => $rootPct.'%', 'alerts' => 0, 'workflow_fails' => 0],
        ];
    }

    /**
     * Pre-screen for log-analyst agent.
     */
    public function preScreenLogAnalyst(): ?array
    {
        $issues = [];

        try {
            // Check cached log analysis from OpsMaintenanceJob
            $opsCache = \Illuminate\Support\Facades\Cache::get('ops_maintenance_report', []);
            $logData = $opsCache['logs'] ?? null;

            if ($logData) {
                $errorCount = $logData['summary']['total_errors'] ?? 0;
                if ($errorCount > 10) {
                    $issues[] = "log_errors:{$errorCount}";
                }

                // Check for new error patterns (not in baseline)
                $newPatterns = $logData['summary']['new_patterns'] ?? 0;
                if ($newPatterns > 0) {
                    $issues[] = "new_patterns:{$newPatterns}";
                }
            }

            // Quick check: any CRITICAL/ERROR in laravel.log last 2 hours
            $logFile = storage_path('logs/laravel.log');
            if (file_exists($logFile)) {
                $recentErrors = $this->countRecentProductionErrors($logFile, 2);
                if ($recentErrors > 20) {
                    $issues[] = "recent_errors:{$recentErrors}";
                }
            }

        } catch (\Exception $e) {
            Log::debug('MonitoringPreScreen: log-analyst check failed, deferring to LLM', ['error' => $e->getMessage()]);

            return null;
        }

        if (! empty($issues)) {
            Log::info('MonitoringPreScreen: log-analyst anomalies detected, LLM needed', ['issues' => $issues]);

            return null;
        }

        return [
            'status' => 'healthy',
            'message' => 'Logs nominal — error rate within baseline, no new patterns detected.',
            'checks' => ['error_count' => $logData['summary']['total_errors'] ?? 0],
        ];
    }

    private function countRecentProductionErrors(string $logFile, int $hours): int
    {
        $tail = Process::timeout(5)->run(['tail', '-n', '5000', $logFile]);
        if (! $tail->successful()) {
            return 0;
        }

        $cutoff = now()->subHours($hours);
        $count = 0;

        foreach (preg_split("/\r\n|\n|\r/", $tail->output()) as $line) {
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+production\.(ERROR|CRITICAL):/i', $line, $matches)) {
                continue;
            }

            try {
                if (\Carbon\Carbon::parse($matches[1])->greaterThanOrEqualTo($cutoff)) {
                    $count++;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $count;
    }
}

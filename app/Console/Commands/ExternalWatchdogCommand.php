<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class ExternalWatchdogCommand extends Command
{
    protected $signature = 'ops:external-watchdog {--json : Output machine-readable JSON}';

    protected $description = 'Concise external watchdog snapshot for cron, SSH, or out-of-band monitors';

    public function handle(SystemHealthService $health): int
    {
        $healthResult = $health->checkHealth();
        $heartbeatAgeMinutes = $this->readSchedulerHeartbeatAgeMinutes();
        $zombieRunning = $this->collectZombieRunningStatus();
        $deadlineStatus = $this->collectSchedulerDeadlineStatus();
        $overlapStatus = $this->collectSchedulerOverlapStatus();
        $syntheticProbe = $this->collectSyntheticProbeStatus();
        $recentQueueFailures = $this->collectRecentFailedJobStatus();

        $staleRunning = (int) (DB::selectOne(
            "SELECT COUNT(*) as c
             FROM scheduled_jobs
             WHERE enabled = 1
               AND stall_exempt = 0
               AND COALESCE(job_type, '') <> 'agent_task'
               AND last_run_status = 'running'
               AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes, 30) MINUTE)"
        )?->c ?? 0);

        $recentFailures = (int) (DB::selectOne(
            "SELECT COUNT(*) as c
             FROM scheduled_jobs
             WHERE enabled = 1
               AND last_run_status IN ('failed', 'timeout')
               AND last_run_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        )?->c ?? 0);

        $enabledJobs = (int) (DB::selectOne(
            'SELECT COUNT(*) as c
             FROM scheduled_jobs
             WHERE enabled = 1'
        )?->c ?? 0);

        $schedulerLagMinutes = (int) (DB::selectOne(
            'SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MAX(last_run_at), NOW()), 999999) as c
             FROM scheduled_jobs
             WHERE enabled = 1'
        )?->c ?? 0);

        if ($enabledJobs === 0 || $schedulerLagMinutes === 999999) {
            $schedulerLagMinutes = 0;
        }

        $oldestRunningMinutes = (int) (DB::selectOne(
            "SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MIN(last_run_at), NOW()), 0) as c
             FROM scheduled_jobs
             WHERE enabled = 1
               AND stall_exempt = 0
               AND COALESCE(job_type, '') <> 'agent_task'
               AND last_run_status = 'running'"
        )?->c ?? 0);

        $completionLagMinutes = (int) (DB::selectOne(
            "SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MAX(completed_at), NOW()), 999999) as c
             FROM scheduled_job_runs
             WHERE status = 'success'
               AND completed_at IS NOT NULL
               AND triggered_by = 'scheduler'"
        )?->c ?? 999999);

        if ($completionLagMinutes === 999999) {
            $completionLagMinutes = 0;
        }

        $dueJobsOverdue = (int) (DB::selectOne(
            'SELECT COUNT(*) as c
             FROM scheduled_jobs
             WHERE enabled = 1
               AND next_run_at IS NOT NULL
               AND next_run_at < NOW()'
        )?->c ?? 0);

        $queueDepth = 0;
        foreach (['high', 'default', 'low', 'long-running', 'workflow', 'speculative'] as $queue) {
            $queueDepth += (int) Redis::llen("queues:{$queue}");
        }

        $score = (int) ($healthResult['health_score'] ?? 0);
        $issues = [];
        if ($staleRunning > 0) {
            $issues[] = "stale_running={$staleRunning}";
        }
        if ($recentFailures > 0) {
            $issues[] = "recent_failures={$recentFailures}";
        }
        if (($recentQueueFailures['recent_failed_jobs'] ?? 0) > 0) {
            $issues[] = "recent_queue_failures={$recentQueueFailures['recent_failed_jobs']}";
        }
        if ($queueDepth > 250) {
            $issues[] = "queue_depth={$queueDepth}";
        }
        if ($score < 80) {
            $issues[] = "health_score={$score}";
        }
        if ($schedulerLagMinutes > 20) {
            $issues[] = "scheduler_lag={$schedulerLagMinutes}";
        }
        if ($completionLagMinutes > 30) {
            $issues[] = "completion_lag={$completionLagMinutes}";
        }
        if ($heartbeatAgeMinutes > 3) {
            $issues[] = "scheduler_heartbeat_age={$heartbeatAgeMinutes}";
        }
        if ($dueJobsOverdue > 0) {
            $issues[] = "due_jobs_overdue={$dueJobsOverdue}";
        }
        if (($zombieRunning['jobs'] ?? 0) > 0) {
            $issues[] = "zombie_running_jobs={$zombieRunning['jobs']}";
        }
        if (($zombieRunning['worker_pids'] ?? 0) > 0) {
            $issues[] = "zombie_running_pids={$zombieRunning['worker_pids']}";
        }
        if (($deadlineStatus['running_with_expired_deadline'] ?? 0) > 0) {
            $issues[] = "expired_scheduler_deadlines={$deadlineStatus['running_with_expired_deadline']}";
        }
        if (($deadlineStatus['non_running_with_deadline'] ?? 0) > 0) {
            $issues[] = "stale_scheduler_deadline_keys={$deadlineStatus['non_running_with_deadline']}";
        }
        if (($overlapStatus['blocked_due_jobs'] ?? 0) > 0) {
            $issues[] = "overlap_blocked_due_jobs={$overlapStatus['blocked_due_jobs']}";
        }
        if (($syntheticProbe['status'] ?? 'unknown') === 'degraded') {
            $issues[] = 'synthetic_probe=degraded';
        }
        if (($syntheticProbe['status'] ?? 'unknown') === 'critical') {
            $issues[] = 'synthetic_probe=critical';
        }

        $schedulerHost = $this->collectSchedulerHostStatus();
        $horizonHost = $this->collectHorizonHostStatus();

        if ($schedulerHost['cron_configured'] === false) {
            $issues[] = 'cron_missing';
        }
        if ($schedulerHost['cron_service_status'] === 'inactive') {
            $issues[] = 'cron_service=inactive';
        }
        if ($schedulerHost['cron_service_status'] === 'error') {
            $issues[] = 'cron_service=error';
        }
        if ($horizonHost['status'] !== 'running') {
            $issues[] = "horizon={$horizonHost['status']}";
        }

        $schedulerExecutionStatus = $this->determineSchedulerExecutionStatus(
            $heartbeatAgeMinutes,
            $dueJobsOverdue,
            $schedulerHost
        );
        $schedulerFlowStatus = $this->determineSchedulerFlowStatus(
            $heartbeatAgeMinutes,
            $dueJobsOverdue,
            $completionLagMinutes,
            $queueDepth,
            $schedulerHost
        );

        if ($schedulerFlowStatus === 'starved') {
            $issues[] = 'scheduler_flow=starved';
        }
        if ($schedulerFlowStatus === 'silent') {
            $issues[] = 'scheduler_flow=silent';
        }

        $status = match (true) {
            $score < 40
                || $staleRunning > 0
                || ($zombieRunning['jobs'] ?? 0) > 0
                || ($deadlineStatus['running_with_expired_deadline'] ?? 0) > 0
                || $schedulerLagMinutes > 60
                || $completionLagMinutes > 90
                || $schedulerExecutionStatus === 'critical'
                || ($syntheticProbe['status'] ?? null) === 'critical'
                || $schedulerFlowStatus === 'silent'
                || $horizonHost['status'] === 'critical' => 'critical',
            $score < 80
                || $recentFailures > 0
                || ($recentQueueFailures['recent_failed_jobs'] ?? 0) > 0
                || $queueDepth > 250
                || $schedulerLagMinutes > 20
                || $completionLagMinutes > 30
                || $schedulerExecutionStatus === 'degraded'
                || ($deadlineStatus['non_running_with_deadline'] ?? 0) > 0
                || ($syntheticProbe['status'] ?? null) === 'degraded'
                || $schedulerFlowStatus === 'starved'
                || $horizonHost['status'] === 'degraded' => 'degraded',
            default => 'healthy',
        };

        $payload = [
            'status' => $status,
            'health_score' => $score,
            'scheduler_lag_minutes' => $schedulerLagMinutes,
            'completion_lag_minutes' => $completionLagMinutes,
            'scheduler_heartbeat_age_minutes' => $heartbeatAgeMinutes,
            'due_jobs_overdue' => $dueJobsOverdue,
            'scheduler_execution' => [
                'status' => $schedulerExecutionStatus,
                'heartbeat_age_minutes' => $heartbeatAgeMinutes,
                'due_jobs_overdue' => $dueJobsOverdue,
                'cron_configured' => $schedulerHost['cron_configured'],
                'cron_service_status' => $schedulerHost['cron_service_status'],
                'zombie_running_jobs' => $zombieRunning['jobs'],
                'zombie_running_pids' => $zombieRunning['worker_pids'],
                'running_with_expired_deadline' => $deadlineStatus['running_with_expired_deadline'],
                'non_running_with_deadline' => $deadlineStatus['non_running_with_deadline'],
                'blocked_due_jobs' => $overlapStatus['blocked_due_jobs'],
            ],
            'scheduler_flow' => [
                'status' => $schedulerFlowStatus,
                'heartbeat_age_minutes' => $heartbeatAgeMinutes,
                'due_jobs_overdue' => $dueJobsOverdue,
                'completion_lag_minutes' => $completionLagMinutes,
                'queue_depth' => $queueDepth,
                'recent_queue_failures' => $recentQueueFailures['recent_failed_jobs'],
            ],
            'scheduler_probe' => $syntheticProbe,
            'scheduler_host' => $schedulerHost,
            'horizon_host' => $horizonHost,
            'queue_depth' => $queueDepth,
            'recent_failures' => $recentFailures,
            'recent_queue_failures' => $recentQueueFailures,
            'stale_running' => $staleRunning,
            'zombie_running_jobs' => $zombieRunning['jobs'],
            'zombie_running_pids' => $zombieRunning['worker_pids'],
            'scheduler_deadlines' => $deadlineStatus,
            'scheduler_overlaps' => $overlapStatus,
            'oldest_running_minutes' => $oldestRunningMinutes,
            'issues' => $issues,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $summary = sprintf(
                'status=%s health=%d scheduler_lag=%d completion_lag=%d scheduler_heartbeat_age=%d due_jobs_overdue=%d zombie_running_jobs=%d zombie_running_pids=%d cron_configured=%s cron_service=%s horizon=%s queue=%d recent_failures=%d recent_queue_failures=%d stale_running=%d oldest_running_min=%d',
                $status,
                $score,
                $schedulerLagMinutes,
                $completionLagMinutes,
                $heartbeatAgeMinutes,
                $dueJobsOverdue,
                $zombieRunning['jobs'],
                $zombieRunning['worker_pids'],
                $schedulerHost['cron_configured'] === null ? 'unknown' : ($schedulerHost['cron_configured'] ? 'yes' : 'no'),
                $schedulerHost['cron_service_status'],
                $horizonHost['status'],
                $queueDepth,
                $recentFailures,
                $recentQueueFailures['recent_failed_jobs'],
                $staleRunning,
                $oldestRunningMinutes
            );

            if (! empty($issues)) {
                $summary .= ' issues='.implode(',', $issues);
            }

            $this->line($summary);
        }

        return match ($status) {
            'healthy' => self::SUCCESS,
            'degraded' => 1,
            default => 2,
        };
    }

    protected function collectRecentFailedJobStatus(): array
    {
        $recentFailedJobs = (int) (DB::selectOne(
            "SELECT COUNT(*) as c
             FROM failed_jobs
             WHERE failed_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        )?->c ?? 0);

        $queueRows = DB::select(
            "SELECT COALESCE(NULLIF(queue, ''), 'unknown') as queue_name, COUNT(*) as c
             FROM failed_jobs
             WHERE failed_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             GROUP BY COALESCE(NULLIF(queue, ''), 'unknown')
             ORDER BY c DESC, queue_name ASC
             LIMIT 5"
        );

        $queues = array_map(static fn (object $row): array => [
            'queue' => (string) ($row->queue_name ?? 'unknown'),
            'count' => (int) ($row->c ?? 0),
        ], $queueRows);

        return [
            'recent_failed_jobs' => $recentFailedJobs,
            'queues' => $queues,
        ];
    }

    protected function collectZombieRunningStatus(): array
    {
        $runningJobs = DB::select(
            "SELECT id, last_pid, running_pids
             FROM scheduled_jobs
             WHERE enabled = 1
               AND last_run_status = 'running'
               AND (last_pid IS NOT NULL OR running_pids IS NOT NULL)"
        );

        $zombieJobs = 0;
        $zombieWorkerPids = 0;

        foreach ($runningJobs as $job) {
            $jobHasZombie = false;

            $lastPid = (int) ($job->last_pid ?? 0);
            if ($lastPid > 0 && ! $this->isProcessAlive($lastPid)) {
                $jobHasZombie = true;
                $zombieWorkerPids++;
            }

            $workerPids = json_decode($job->running_pids ?? '[]', true);
            if (! is_array($workerPids)) {
                $workerPids = [];
            }

            foreach ($workerPids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0 && ! $this->isProcessAlive($pid)) {
                    $jobHasZombie = true;
                    $zombieWorkerPids++;
                }
            }

            if ($jobHasZombie) {
                $zombieJobs++;
            }
        }

        return [
            'jobs' => $zombieJobs,
            'worker_pids' => $zombieWorkerPids,
        ];
    }

    protected function collectSchedulerDeadlineStatus(): array
    {
        $jobs = DB::select(
            "SELECT id, last_run_status
             FROM scheduled_jobs
             WHERE enabled = 1"
        );

        $runningWithExpiredDeadline = 0;
        $nonRunningWithDeadline = 0;
        $runningWithActiveDeadline = 0;

        foreach ($jobs as $job) {
            $deadline = Cache::get("scheduler:job:{$job->id}:deadline");
            if (! is_numeric($deadline)) {
                continue;
            }

            $deadline = (int) $deadline;

            if (($job->last_run_status ?? null) === 'running') {
                if ($deadline > time()) {
                    $runningWithActiveDeadline++;
                } else {
                    $runningWithExpiredDeadline++;
                }

                continue;
            }

            $nonRunningWithDeadline++;
        }

        return [
            'running_with_active_deadline' => $runningWithActiveDeadline,
            'running_with_expired_deadline' => $runningWithExpiredDeadline,
            'non_running_with_deadline' => $nonRunningWithDeadline,
        ];
    }

    protected function collectSchedulerOverlapStatus(): array
    {
        $rows = DB::select(
            "SELECT id, without_overlapping, max_parallel, running_count, next_run_at
             FROM scheduled_jobs
             WHERE enabled = 1
               AND last_run_status = 'running'
               AND next_run_at IS NOT NULL
               AND next_run_at < NOW()
               AND (
                    without_overlapping = 1
                    OR (COALESCE(max_parallel, 1) > 1 AND COALESCE(running_count, 0) >= COALESCE(max_parallel, 1))
               )"
        );

        $blockedSingleOverlap = 0;
        $blockedParallelCapacity = 0;
        $oldestBlockedMinutes = 0;

        foreach ($rows as $row) {
            if ((int) ($row->without_overlapping ?? 0) === 1) {
                $blockedSingleOverlap++;
            }

            if ((int) ($row->max_parallel ?? 1) > 1
                && (int) ($row->running_count ?? 0) >= (int) ($row->max_parallel ?? 1)) {
                $blockedParallelCapacity++;
            }

            $ageMinutes = $this->ageInMinutesFromTimestamp($row->next_run_at);
            if ($ageMinutes !== 999999) {
                $oldestBlockedMinutes = max($oldestBlockedMinutes, $ageMinutes);
            }
        }

        return [
            'blocked_due_jobs' => count($rows),
            'blocked_single_overlap' => $blockedSingleOverlap,
            'blocked_parallel_capacity' => $blockedParallelCapacity,
            'oldest_blocked_minutes' => $oldestBlockedMinutes,
        ];
    }

    protected function collectSyntheticProbeStatus(): array
    {
        $probeJob = DB::selectOne(
            "SELECT id, enabled, last_run_status, last_run_at
             FROM scheduled_jobs
             WHERE name = ?
             LIMIT 1",
            ['scheduler_synthetic_probe']
        );

        if (! $probeJob) {
            return [
                'status' => 'missing',
                'enabled' => false,
                'last_success_age_minutes' => null,
            ];
        }

        $lastSuccessAt = DB::selectOne(
            "SELECT completed_at
             FROM scheduled_job_runs
             WHERE scheduled_job_id = ?
               AND status = 'success'
               AND completed_at IS NOT NULL
             ORDER BY completed_at DESC
             LIMIT 1",
            [$probeJob->id]
        )?->completed_at;

        $lastSuccessAgeMinutes = $this->ageInMinutesFromTimestamp($lastSuccessAt);

        $status = match (true) {
            ! $probeJob->enabled => 'disabled',
            $lastSuccessAgeMinutes === 999999 || $lastSuccessAgeMinutes > 45 => 'critical',
            $lastSuccessAgeMinutes > 20 || ($probeJob->last_run_status ?? null) === 'failed' => 'degraded',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'enabled' => (bool) $probeJob->enabled,
            'last_run_status' => $probeJob->last_run_status,
            'last_run_at' => $probeJob->last_run_at,
            'last_success_age_minutes' => $lastSuccessAgeMinutes === 999999 ? null : $lastSuccessAgeMinutes,
        ];
    }

    protected function readSchedulerHeartbeatAgeMinutes(): int
    {
        $heartbeatValue = DB::selectOne(
            'SELECT config_value
             FROM system_configs
             WHERE section = ? AND config_key = ?
             LIMIT 1',
            ['scheduler', 'last_heartbeat_at']
        )?->config_value;

        return $this->ageInMinutesFromTimestamp(is_string($heartbeatValue) ? $heartbeatValue : null);
    }

    protected function ageInMinutesFromTimestamp(?string $value): int
    {
        if ($value === null || $value === '') {
            return 999999;
        }

        try {
            return max(
                0,
                (int) ceil(\Carbon\Carbon::parse($value)->diffInSeconds(now(), false) / 60)
            );
        } catch (\Throwable) {
            return 999999;
        }
    }

    protected function collectSchedulerHostStatus(): array
    {
        $crontab = $this->runHostCommand(['crontab', '-l']);
        $cronConfigured = null;

        if ($crontab['ok']) {
            $cronConfigured = str_contains($crontab['output'], 'artisan scheduler:run');
        }

        $cronServiceStatus = 'unknown';
        foreach ([
            ['systemctl', 'is-active', 'cron.service'],
            ['systemctl', 'is-active', 'crond.service'],
        ] as $command) {
            $result = $this->runHostCommand($command);
            if (! $result['ok']) {
                $cronServiceStatus = 'error';
                continue;
            }

            $serviceStatus = $result['output'];
            if ($serviceStatus === 'active') {
                $cronServiceStatus = 'active';
                break;
            }

            if (in_array($serviceStatus, ['inactive', 'failed'], true)) {
                $cronServiceStatus = 'inactive';
            }
        }

        return [
            'cron_configured' => $cronConfigured,
            'cron_service_status' => $cronServiceStatus,
        ];
    }

    protected function collectHorizonHostStatus(): array
    {
        $processResult = $this->runHostCommand(['pgrep', '-f', 'artisan horizon$']);
        $systemdResult = $this->runHostCommand(['systemctl', 'is-active', 'laravel-horizon.service']);

        $processRunning = $processResult['ok'] && $processResult['output'] !== '';
        $systemdStatus = $systemdResult['ok'] ? $systemdResult['output'] : 'error';

        $status = match (true) {
            $processRunning || $systemdStatus === 'active' => 'running',
            $systemdStatus === 'error' => 'degraded',
            default => 'critical',
        };

        return [
            'status' => $status,
            'process_running' => $processRunning,
            'systemd_status' => $systemdStatus,
        ];
    }

    protected function determineSchedulerExecutionStatus(
        int $heartbeatAgeMinutes,
        int $dueJobsOverdue,
        array $schedulerHost
    ): string {
        return match (true) {
            $heartbeatAgeMinutes === 999999
                || $heartbeatAgeMinutes > 10
                || $schedulerHost['cron_configured'] === false
                || $schedulerHost['cron_service_status'] === 'inactive'
                || $schedulerHost['cron_service_status'] === 'error' => 'critical',
            $heartbeatAgeMinutes > 3
                || $dueJobsOverdue > 0
                || $schedulerHost['cron_configured'] === null
                || $schedulerHost['cron_service_status'] === 'unknown' => 'degraded',
            default => 'healthy',
        };
    }

    protected function determineSchedulerFlowStatus(
        int $heartbeatAgeMinutes,
        int $dueJobsOverdue,
        int $completionLagMinutes,
        int $queueDepth,
        array $schedulerHost
    ): string {
        return match (true) {
            $heartbeatAgeMinutes === 999999
                || $heartbeatAgeMinutes > 3
                || $schedulerHost['cron_configured'] === false
                || $schedulerHost['cron_service_status'] === 'inactive'
                || $schedulerHost['cron_service_status'] === 'error' => 'silent',
            $dueJobsOverdue > 0
                && $heartbeatAgeMinutes <= 3
                && ($queueDepth > 0 || $completionLagMinutes > 30) => 'starved',
            $dueJobsOverdue > 0 || $completionLagMinutes > 30 => 'degraded',
            default => 'healthy',
        };
    }

    protected function runHostCommand(array $command): array
    {
        try {
            $result = Process::timeout(5)->run($command);

            return [
                'ok' => $result->successful(),
                'output' => trim($result->output()),
            ];
        } catch (\Throwable) {
            return [
                'ok' => false,
                'output' => '',
            ];
        }
    }

    protected function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return is_dir("/proc/{$pid}");
    }
}

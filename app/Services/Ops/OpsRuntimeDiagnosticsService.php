<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OpsRuntimeDiagnosticsService
{
    /** Recovery threshold for stale agent_sessions, in minutes. */
    private const STALE_SESSION_MINUTES = 30;

    /** Grace period past the job's declared timeout before it counts as past-deadline. */
    private const DEADLINE_GRACE_MINUTES = 15;

    public function buildEnvelope(array $window, string $focus): array
    {
        $windowMinutes = $window['minutes'];
        $result = [];

        if ($focus === 'tasks' || $focus === 'all') {
            $result['tasks'] = $this->buildTasksSection($windowMinutes);
        }

        if ($focus === 'runs' || $focus === 'all') {
            $result['runs'] = $this->buildRunsSection($windowMinutes);
        }

        if ($focus === 'recovery' || $focus === 'all') {
            $result['recovery'] = $this->buildRecoverySection();
        }

        return [
            'version' => 1,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'window' => $window['canonical'],
            'focus' => $focus,
            'host' => $this->resolveHostName(),
            'result' => $result,
        ];
    }

    /**
     * Parse a window string like 30m, 4h, 7d.
     *
     * @return array{minutes:int,canonical:string}|null
     */
    public function parseWindow(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (! preg_match('/^(\d+)([mhd])$/', $trimmed, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        if ($value <= 0) {
            return null;
        }

        $unit = $matches[2];
        $minutes = match ($unit) {
            'm' => $value,
            'h' => $value * 60,
            'd' => $value * 60 * 24,
        };

        return [
            'minutes' => $minutes,
            'canonical' => $value.$unit,
        ];
    }

    public function mysqlTableExists(string $table): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS c
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name = ?',
                [$table]
            );

            return (int) ($row->c ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildTasksSection(int $windowMinutes): array
    {
        try {
            $rows = DB::select(
                'SELECT id, name, runtime_mode, workload_family, resource_profile,
                        stall_policy, last_run_status, last_run_at, last_completed_at,
                        timeout_minutes, enabled
                   FROM scheduled_jobs
                  WHERE last_run_at IS NOT NULL
                    AND last_run_at >= (NOW() - INTERVAL ? MINUTE)
                  ORDER BY last_run_at DESC',
                [$windowMinutes]
            );
        } catch (\Throwable $e) {
            return [
                'result' => 'query_failed',
                'error' => $e->getMessage(),
                'jobs' => [],
            ];
        }

        $jobs = [];
        $counts = ['total' => 0, 'success' => 0, 'failed' => 0, 'running' => 0, 'timeout' => 0];
        $missingMetadataCount = 0;

        foreach ($rows as $row) {
            $runtimeMode = $row->runtime_mode ?? null;
            if ($runtimeMode === null) {
                $missingMetadataCount++;
            }

            $status = (string) ($row->last_run_status ?? '');
            $counts['total']++;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            $jobs[] = [
                'id' => (int) ($row->id ?? 0),
                'name' => (string) ($row->name ?? ''),
                'runtime_mode' => $runtimeMode !== null ? (string) $runtimeMode : null,
                'workload_family' => isset($row->workload_family) ? (string) $row->workload_family : null,
                'resource_profile' => isset($row->resource_profile) ? (string) $row->resource_profile : null,
                'stall_policy' => isset($row->stall_policy) ? (string) $row->stall_policy : null,
                'last_run_status' => $status !== '' ? $status : null,
                'last_run_at' => isset($row->last_run_at) ? (string) $row->last_run_at : null,
                'last_completed_at' => isset($row->last_completed_at) ? (string) $row->last_completed_at : null,
                'timeout_minutes' => isset($row->timeout_minutes) ? (int) $row->timeout_minutes : null,
                'enabled' => (int) ($row->enabled ?? 0),
            ];
        }

        return [
            'result' => 'ok',
            'jobs' => $jobs,
            'counts' => $counts,
            'missing_runtime_metadata_count' => $missingMetadataCount,
        ];
    }

    private function buildRunsSection(int $windowMinutes): array
    {
        if (! $this->mysqlTableExists('scheduled_job_runs')) {
            return ['result' => 'table_missing'];
        }

        try {
            $rows = DB::select(
                'SELECT scheduled_job_id, started_at, completed_at, status,
                        duration_seconds, output
                   FROM scheduled_job_runs
                  WHERE started_at >= (NOW() - INTERVAL ? MINUTE)
                  ORDER BY started_at DESC',
                [$windowMinutes]
            );
        } catch (\Throwable $e) {
            return [
                'result' => 'query_failed',
                'error' => $e->getMessage(),
            ];
        }

        $total = count($rows);
        $distribution = [];
        $durationsMs = [];
        $durationsWithId = [];
        $errorSignatureCounts = [];

        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $distribution[$status] = ($distribution[$status] ?? 0) + 1;

            $durationSeconds = $row->duration_seconds ?? null;
            if ($durationSeconds !== null && is_numeric($durationSeconds)) {
                $ms = (int) round(((float) $durationSeconds) * 1000);
                $durationsMs[] = $ms;
                $durationsWithId[] = [
                    'scheduled_job_id' => (int) ($row->scheduled_job_id ?? 0),
                    'started_at' => isset($row->started_at) ? (string) $row->started_at : null,
                    'duration_ms' => $ms,
                    'status' => $status,
                ];
            }

            if (in_array($status, ['failed', 'timeout'], true)) {
                $output = (string) ($row->output ?? '');
                $sig = $this->errorSignature($output);
                if ($sig !== '') {
                    $errorSignatureCounts[$sig] = ($errorSignatureCounts[$sig] ?? 0) + 1;
                }
            }
        }

        $successCount = $distribution['success'] ?? 0;
        $percentSuccess = $total > 0 ? round(($successCount / $total) * 100, 1) : null;

        usort($durationsWithId, static fn ($a, $b) => $b['duration_ms'] <=> $a['duration_ms']);
        $slowest = array_slice($durationsWithId, 0, 5);

        arsort($errorSignatureCounts);
        $topErrors = [];
        foreach (array_slice($errorSignatureCounts, 0, 5, true) as $sig => $count) {
            $topErrors[] = ['signature' => $sig, 'count' => $count];
        }

        return [
            'result' => 'ok',
            'total' => $total,
            'status_distribution' => $distribution,
            'percent_success' => $percentSuccess,
            'median_duration_ms' => $this->percentile($durationsMs, 50.0),
            'p95_duration_ms' => $this->percentile($durationsMs, 95.0),
            'slowest_runs' => $slowest,
            'top_error_signatures' => $topErrors,
        ];
    }

    private function buildRecoverySection(): array
    {
        return [
            'result' => 'ok',
            'stale_agent_sessions' => $this->collectStaleAgentSessions(),
            'past_deadline_jobs' => $this->collectPastDeadlineJobs(),
            'locks' => [
                'ollama_busy_lock' => Cache::has('ollama_busy_lock'),
                'whisper_gpu_lock' => Cache::has('whisper_gpu_lock'),
            ],
        ];
    }

    private function collectStaleAgentSessions(): array
    {
        if (! $this->mysqlTableExists('agent_sessions')) {
            return ['result' => 'table_missing', 'rows' => [], 'count' => 0];
        }

        try {
            $rows = DB::select(
                "SELECT id, session_id, agent_name, status, updated_at, last_activity_at
                   FROM agent_sessions
                  WHERE status = 'active'
                    AND updated_at < (NOW() - INTERVAL ? MINUTE)
                  ORDER BY updated_at ASC",
                [self::STALE_SESSION_MINUTES]
            );
        } catch (\Throwable $e) {
            return ['result' => 'query_failed', 'error' => $e->getMessage(), 'rows' => [], 'count' => 0];
        }

        $ids = [];
        $detail = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            $ids[] = $id;
            $detail[] = [
                'id' => $id,
                'session_id' => (string) ($row->session_id ?? ''),
                'agent_name' => isset($row->agent_name) ? (string) $row->agent_name : null,
                'status' => (string) ($row->status ?? ''),
                'updated_at' => isset($row->updated_at) ? (string) $row->updated_at : null,
                'last_activity_at' => isset($row->last_activity_at) ? (string) $row->last_activity_at : null,
            ];
        }

        return [
            'result' => 'ok',
            'count' => count($ids),
            'ids' => $ids,
            'rows' => $detail,
            'threshold_minutes' => self::STALE_SESSION_MINUTES,
        ];
    }

    private function collectPastDeadlineJobs(): array
    {
        try {
            $rows = DB::select(
                "SELECT id, name, last_run_at, timeout_minutes, last_pid
                   FROM scheduled_jobs
                  WHERE last_run_status = 'running'
                    AND last_run_at IS NOT NULL
                    AND timeout_minutes IS NOT NULL
                    AND last_run_at < (NOW() - INTERVAL (timeout_minutes + ?) MINUTE)
                  ORDER BY last_run_at ASC",
                [self::DEADLINE_GRACE_MINUTES]
            );
        } catch (\Throwable $e) {
            return ['result' => 'query_failed', 'error' => $e->getMessage(), 'rows' => [], 'count' => 0];
        }

        $ids = [];
        $detail = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            $ids[] = $id;
            $detail[] = [
                'id' => $id,
                'name' => (string) ($row->name ?? ''),
                'last_run_at' => isset($row->last_run_at) ? (string) $row->last_run_at : null,
                'timeout_minutes' => isset($row->timeout_minutes) ? (int) $row->timeout_minutes : null,
                'last_pid' => isset($row->last_pid) ? (int) $row->last_pid : null,
            ];
        }

        return [
            'result' => 'ok',
            'count' => count($ids),
            'ids' => $ids,
            'rows' => $detail,
            'grace_minutes' => self::DEADLINE_GRACE_MINUTES,
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, float $percent): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        if ($count === 1) {
            return $values[0];
        }

        $rank = ($percent / 100.0) * ($count - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $values[$low];
        }

        $fraction = $rank - $low;
        $interpolated = $values[$low] + ($values[$high] - $values[$low]) * $fraction;

        return (int) round($interpolated);
    }

    private function errorSignature(string $output): string
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return '';
        }

        $firstLine = '';
        foreach (preg_split('/\r?\n/', $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $firstLine = $line;
                break;
            }
        }

        if ($firstLine === '') {
            $firstLine = $trimmed;
        }

        return mb_substr($firstLine, 0, 80);
    }

    private function resolveHostName(): string
    {
        $fallback = gethostname() ?: 'unknown-host';

        return explode('.', $fallback)[0];
    }
}

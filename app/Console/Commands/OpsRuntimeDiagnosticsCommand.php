<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsRuntimeDiagnosticsService;
use Illuminate\Console\Command;

/**
 * ops:runtime-diagnostics — read-only time-windowed view of task/run/recovery state.
 *
 * Read-only operations diagnostics. Surfaces:
 *   - tasks:    scheduled_jobs runtime-metadata coverage inside the window
 *   - runs:     scheduled_job_runs activity (distribution, p95, top errors)
 *   - recovery: stale agent_sessions, past-deadline scheduled_jobs, live locks
 *
 * No DB writes. No routing changes. No agent execution. Degrades gracefully when
 * scheduled_job_runs is absent on the target environment.
 */
class OpsRuntimeDiagnosticsCommand extends Command
{
    protected $signature = 'ops:runtime-diagnostics
                            {--window=60m : time window, e.g. 30m, 4h, 24h, 7d}
                            {--focus=all : tasks|runs|recovery|all}
                            {--json : emit JSON envelope instead of pretty text}
                            {--compact : emit aggregate-only JSON without host, rows, ids, or raw output; requires --json}';

    protected $description = 'Read-only time-windowed task/run/recovery diagnostics (B8)';

    public function handle(OpsRuntimeDiagnosticsService $diagnostics): int
    {
        $window = $diagnostics->parseWindow((string) $this->option('window'));
        if ($window === null) {
            $this->error('Invalid --window. Use Nm (minutes), Nh (hours), or Nd (days).');

            return 2;
        }

        $focus = (string) $this->option('focus');
        if (! in_array($focus, ['tasks', 'runs', 'recovery', 'all'], true)) {
            $this->error('Invalid --focus. Use tasks|runs|recovery|all.');

            return 2;
        }

        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option requires --json.');

            return 2;
        }

        $envelope = $diagnostics->buildEnvelope($window, $focus);

        if ($this->option('json')) {
            $payload = $this->option('compact') ? $this->compactEnvelope($envelope) : $envelope;
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode diagnostics JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return self::SUCCESS;
        }

        $this->renderPrettyText($envelope);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function compactEnvelope(array $envelope): array
    {
        $result = is_array($envelope['result'] ?? null) ? $envelope['result'] : [];
        $tasks = is_array($result['tasks'] ?? null) ? $result['tasks'] : [];
        $taskCounts = is_array($tasks['counts'] ?? null) ? $tasks['counts'] : [];
        $runs = is_array($result['runs'] ?? null) ? $result['runs'] : [];
        $runDistribution = is_array($runs['status_distribution'] ?? null) ? $runs['status_distribution'] : [];
        $recovery = is_array($result['recovery'] ?? null) ? $result['recovery'] : [];
        $stale = is_array($recovery['stale_agent_sessions'] ?? null) ? $recovery['stale_agent_sessions'] : [];
        $past = is_array($recovery['past_deadline_jobs'] ?? null) ? $recovery['past_deadline_jobs'] : [];
        $locks = is_array($recovery['locks'] ?? null) ? $recovery['locks'] : [];

        $status = $this->compactStatus($tasks, $taskCounts, $runs, $runDistribution, $recovery, $stale, $past);

        return [
            'version' => (int) ($envelope['version'] ?? 1),
            'mode' => 'observe',
            'compact' => true,
            'read_only' => true,
            'status' => $status,
            'captured_at' => $envelope['captured_at'] ?? null,
            'window' => $envelope['window'] ?? null,
            'focus' => $envelope['focus'] ?? null,
            'summary' => [
                'tasks_result' => (string) ($tasks['result'] ?? 'not_requested'),
                'task_total' => (int) ($taskCounts['total'] ?? 0),
                'task_success' => (int) ($taskCounts['success'] ?? 0),
                'task_failed' => (int) ($taskCounts['failed'] ?? 0),
                'task_running' => (int) ($taskCounts['running'] ?? 0),
                'task_timeout' => (int) ($taskCounts['timeout'] ?? 0),
                'missing_runtime_metadata_count' => (int) ($tasks['missing_runtime_metadata_count'] ?? 0),
                'runs_result' => (string) ($runs['result'] ?? 'not_requested'),
                'run_total' => (int) ($runs['total'] ?? 0),
                'run_success_pct' => $runs['percent_success'] ?? null,
                'run_failed' => (int) ($runDistribution['failed'] ?? 0),
                'run_timeout' => (int) ($runDistribution['timeout'] ?? 0),
                'run_problem_recovered' => (int) ($runs['problem_runs_recovered'] ?? 0),
                'run_problem_unrecovered' => (int) ($runs['problem_runs_unrecovered']
                    ?? ((int) ($runDistribution['failed'] ?? 0) + (int) ($runDistribution['timeout'] ?? 0))),
                'run_p95_duration_ms' => $runs['p95_duration_ms'] ?? null,
                'top_error_signature_count' => count(is_array($runs['top_error_signatures'] ?? null) ? $runs['top_error_signatures'] : []),
                'recovery_result' => (string) ($recovery['result'] ?? 'not_requested'),
                'stale_agent_sessions' => (int) ($stale['count'] ?? 0),
                'past_deadline_jobs' => (int) ($past['count'] ?? 0),
                'ollama_busy_lock' => (bool) ($locks['ollama_busy_lock'] ?? false),
                'whisper_gpu_lock' => (bool) ($locks['whisper_gpu_lock'] ?? false),
            ],
            'posture' => [
                'aggregate_only' => true,
                'host_included' => false,
                'job_rows_included' => false,
                'run_rows_included' => false,
                'raw_outputs_included' => false,
                'recovery_rows_included' => false,
                'row_ids_included' => false,
                'writes_enabled' => false,
                'routing_changes_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $tasks
     * @param  array<string, mixed>  $taskCounts
     * @param  array<string, mixed>  $runs
     * @param  array<string, mixed>  $runDistribution
     * @param  array<string, mixed>  $recovery
     * @param  array<string, mixed>  $stale
     * @param  array<string, mixed>  $past
     */
    private function compactStatus(array $tasks, array $taskCounts, array $runs, array $runDistribution, array $recovery, array $stale, array $past): string
    {
        foreach ([$tasks, $runs, $recovery] as $section) {
            $result = (string) ($section['result'] ?? 'ok');
            if (! in_array($result, ['ok', 'not_requested'], true)) {
                return 'observe_warning';
            }
        }

        if ((int) ($taskCounts['failed'] ?? 0) > 0 || (int) ($taskCounts['timeout'] ?? 0) > 0) {
            return 'observe_warning';
        }

        $unrecoveredRunProblems = (int) ($runs['problem_runs_unrecovered']
            ?? ((int) ($runDistribution['failed'] ?? 0) + (int) ($runDistribution['timeout'] ?? 0)));

        if ($unrecoveredRunProblems > 0) {
            return 'observe_warning';
        }

        if ((int) ($stale['count'] ?? 0) > 0 || (int) ($past['count'] ?? 0) > 0) {
            return 'observe_warning';
        }

        return 'observe_ok';
    }

    private function renderPrettyText(array $envelope): void
    {
        $this->line(sprintf(
            'runtime-diagnostics  window=%s  focus=%s  host=%s  captured=%s',
            $envelope['window'],
            $envelope['focus'],
            $envelope['host'],
            $envelope['captured_at']
        ));

        $result = $envelope['result'] ?? [];

        if (isset($result['tasks'])) {
            $this->line('');
            $this->renderTasksPretty($result['tasks']);
        }

        if (isset($result['runs'])) {
            $this->line('');
            $this->renderRunsPretty($result['runs']);
        }

        if (isset($result['recovery'])) {
            $this->line('');
            $this->renderRecoveryPretty($result['recovery']);
        }
    }

    private function renderTasksPretty(array $tasks): void
    {
        $this->line('[tasks]');
        if (($tasks['result'] ?? '') !== 'ok') {
            $this->line('  result: '.($tasks['result'] ?? 'unknown'));

            return;
        }

        $counts = $tasks['counts'] ?? [];
        $this->line(sprintf(
            '  total=%d  success=%d  failed=%d  running=%d  timeout=%d  missing_runtime_metadata=%d',
            $counts['total'] ?? 0,
            $counts['success'] ?? 0,
            $counts['failed'] ?? 0,
            $counts['running'] ?? 0,
            $counts['timeout'] ?? 0,
            $tasks['missing_runtime_metadata_count'] ?? 0
        ));

        foreach ($tasks['jobs'] ?? [] as $job) {
            $this->line(sprintf(
                '  %-32s  status=%-8s  mode=%-12s  family=%-8s  last_run=%s',
                mb_substr((string) $job['name'], 0, 32),
                (string) ($job['last_run_status'] ?? '-'),
                (string) ($job['runtime_mode'] ?? '-'),
                (string) ($job['workload_family'] ?? '-'),
                (string) ($job['last_run_at'] ?? '-')
            ));
        }
    }

    private function renderRunsPretty(array $runs): void
    {
        $this->line('[runs]');
        if (($runs['result'] ?? '') !== 'ok') {
            $this->line('  result: '.($runs['result'] ?? 'unknown'));

            return;
        }

        $this->line(sprintf(
            '  total=%d  percent_success=%s  median_ms=%s  p95_ms=%s',
            $runs['total'] ?? 0,
            $runs['percent_success'] !== null ? $runs['percent_success'].'%' : '-',
            $runs['median_duration_ms'] !== null ? (string) $runs['median_duration_ms'] : '-',
            $runs['p95_duration_ms'] !== null ? (string) $runs['p95_duration_ms'] : '-'
        ));

        $dist = $runs['status_distribution'] ?? [];
        if ($dist !== []) {
            $parts = [];
            foreach ($dist as $status => $count) {
                $parts[] = $status.'='.$count;
            }
            $this->line('  distribution: '.implode('  ', $parts));
        }

        $slowest = $runs['slowest_runs'] ?? [];
        if ($slowest !== []) {
            $this->line('  slowest:');
            foreach ($slowest as $r) {
                $this->line(sprintf(
                    '    job_id=%-5d  duration_ms=%-8d  status=%-8s  started=%s',
                    $r['scheduled_job_id'] ?? 0,
                    $r['duration_ms'] ?? 0,
                    (string) ($r['status'] ?? '-'),
                    (string) ($r['started_at'] ?? '-')
                ));
            }
        }

        $errors = $runs['top_error_signatures'] ?? [];
        if ($errors !== []) {
            $this->line('  top errors:');
            foreach ($errors as $e) {
                $this->line(sprintf('    x%-4d  %s', (int) $e['count'], (string) $e['signature']));
            }
        }
    }

    private function renderRecoveryPretty(array $recovery): void
    {
        $this->line('[recovery]');
        if (($recovery['result'] ?? '') !== 'ok') {
            $this->line('  result: '.($recovery['result'] ?? 'unknown'));

            return;
        }

        $stale = $recovery['stale_agent_sessions'] ?? [];
        $past = $recovery['past_deadline_jobs'] ?? [];
        $locks = $recovery['locks'] ?? [];

        $this->line(sprintf(
            '  stale_agent_sessions=%d  past_deadline_jobs=%d  ollama_busy=%s  whisper_gpu=%s',
            $stale['count'] ?? 0,
            $past['count'] ?? 0,
            ! empty($locks['ollama_busy_lock']) ? 'yes' : 'no',
            ! empty($locks['whisper_gpu_lock']) ? 'yes' : 'no'
        ));

        if (($stale['count'] ?? 0) > 0) {
            $this->line('  stale session ids: '.implode(', ', $stale['ids'] ?? []));
        }
        if (($past['count'] ?? 0) > 0) {
            $this->line('  past-deadline job ids: '.implode(', ', $past['ids'] ?? []));
        }
    }
}

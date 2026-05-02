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
                            {--json : emit JSON envelope instead of pretty text}';

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

        $envelope = $diagnostics->buildEnvelope($window, $focus);

        if ($this->option('json')) {
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);
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

<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentDoctorService;
use Illuminate\Console\Command;

class AgentDoctorCommand extends Command
{
    protected $signature = 'ops:agent-doctor
        {--agent= : Limit diagnostics to one agent id}
        {--quick : Keep output compatible with quicker future probes}
        {--json : Emit machine-readable JSON}
        {--since=24 : Window size in hours, 1-168}';

    protected $description = 'Observe-only health summary for agent sessions, scheduled agent jobs, and review queues';

    public function handle(AgentDoctorService $doctor): int
    {
        $payload = $doctor->collect(
            windowHours: (int) $this->option('since'),
            agent: $this->option('agent') ? (string) $this->option('agent') : null,
            quick: (bool) $this->option('quick')
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent-doctor JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $summary = $payload['summary'];
        $this->line(sprintf(
            'Agent doctor: %s  agents=%d  active_sessions=%d  stalled=%d  pending_reviews=%d',
            strtoupper((string) $payload['overall_status']),
            (int) $summary['agents_total'],
            (int) $summary['sessions_active'],
            (int) $summary['sessions_stalled'],
            (int) $summary['review_queue_pending'],
        ));

        $recursion = $payload['recursion'] ?? null;
        if (is_array($recursion)) {
            $moveOnRate = $recursion['move_on_rate_7d'] ?? null;
            $masterEnabled = match ($recursion['master_enabled'] ?? null) {
                true => 'on',
                false => 'off',
                default => 'unknown',
            };
            $this->line(sprintf(
                'Recursion signal: %s  calls_7d=%d  move_on_7d=%s  master=%s',
                strtoupper((string) ($recursion['status'] ?? 'unknown')),
                (int) ($recursion['calls_7d'] ?? 0),
                $moveOnRate === null ? 'n/a' : number_format((float) $moveOnRate * 100, 1).'%',
                $masterEnabled,
            ));
        }

        foreach ($payload['agents'] as $agent) {
            $line = sprintf(
                '[%s] %s  sessions=%d/%d stalled  reviews=%d  job_failures=%d',
                strtoupper((string) $agent['status']),
                (string) $agent['agent_id'],
                (int) $agent['sessions']['active'],
                (int) $agent['sessions']['stalled'],
                (int) $agent['review_queue']['pending'],
                (int) $agent['scheduled_job']['consecutive_failures'],
            );

            if (($agent['status'] ?? '') === 'critical') {
                $this->error($line);
            } elseif (($agent['status'] ?? '') === 'warning') {
                $this->warn($line);
            } else {
                $this->info($line);
            }
        }

        foreach ($payload['checks'] as $check) {
            if (($check['status'] ?? '') === 'ok') {
                continue;
            }
            $this->warn(sprintf('%s: %s', (string) $check['id'], (string) $check['detail']));
        }

        // Observe-only by design: critical state is reported in the payload,
        // but does not fail shell polling unless a future strict flag adds that.
        return self::SUCCESS;
    }
}

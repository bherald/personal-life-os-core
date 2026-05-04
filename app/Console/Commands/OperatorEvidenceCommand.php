<?php

namespace App\Console\Commands;

use App\Services\OperatorEvidenceService;
use Illuminate\Console\Command;

class OperatorEvidenceCommand extends Command
{
    protected $signature = 'ops:operator-evidence
        {--json : Emit machine-readable JSON}
        {--compact : Emit routine-check compact output}';

    protected $description = 'Read-only operator evidence snapshot for queue, backlog, degraded state, and agent health';

    public function handle(OperatorEvidenceService $evidence): int
    {
        $payload = $evidence->collect();

        if ($this->option('json')) {
            $json = json_encode(
                $this->option('compact') ? $evidence->compactPayload($payload) : $payload,
                JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                $this->error('Failed to encode operator evidence JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->renderCompactText($evidence->compactPayload($payload));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Operator evidence: %s sampled=%s',
            $payload['status'] ?? 'unknown',
            $payload['captured_at'] ?? '-'
        ));

        foreach (($payload['sections'] ?? []) as $name => $section) {
            $this->line(sprintf(
                '%s: %s',
                str_replace('_', '-', (string) $name),
                (string) ($section['status'] ?? 'unknown')
            ));

            if (isset($section['next_action'])) {
                $this->line('  next: '.$section['next_action']);
            }
        }

        return self::SUCCESS;
    }

    private function renderCompactText(array $payload): void
    {
        $headlines = is_array($payload['headlines'] ?? null) ? $payload['headlines'] : [];

        $this->line(sprintf(
            'Operator evidence compact: %s sampled=%s',
            $payload['status'] ?? 'unknown',
            $payload['sampled_at'] ?? '-'
        ));

        $queue = is_array($headlines['queue'] ?? null) ? $headlines['queue'] : [];
        $this->line(sprintf(
            'queue: %s depth=%s stale_jobs=%s failed_30m=%s scheduler_lag=%s completion_lag=%s',
            $queue['status'] ?? 'unavailable',
            $queue['queue_depth_total'] ?? '-',
            $queue['stale_running_jobs'] ?? '-',
            $queue['recent_failed_jobs_30m'] ?? '-',
            $this->minutes($queue['scheduler_lag_minutes'] ?? null),
            $this->minutes($queue['completion_lag_minutes'] ?? null)
        ));

        $kgRag = is_array($headlines['kg_rag'] ?? null) ? $headlines['kg_rag'] : [];
        $this->line(sprintf(
            'kg-rag: kg=%s drain=%s scale=%s kg_pending=%s raptor=%s sentence=%s documents=%s',
            $kgRag['kg_status'] ?? 'unavailable',
            $kgRag['rag_drain_status'] ?? 'unavailable',
            $kgRag['rag_scale_status'] ?? 'unavailable',
            $kgRag['kg_pending'] ?? '-',
            $kgRag['raptor_pending'] ?? '-',
            $kgRag['sentence_pending'] ?? '-',
            $kgRag['documents'] ?? '-'
        ));

        $review = is_array($headlines['review_backlog'] ?? null) ? $headlines['review_backlog'] : [];
        $this->line(sprintf(
            'review-backlog: %s pending=%s stale=%s high_priority=%s typed=%s',
            $review['status'] ?? 'unavailable',
            $review['pending_total'] ?? '-',
            $review['stale_pending'] ?? '-',
            $review['high_priority_pending'] ?? '-',
            $review['typed_remediation_rows'] ?? '-'
        ));

        $face = is_array($headlines['face'] ?? null) ? $headlines['face'] : [];
        $this->line(sprintf(
            'face: %s pending=%s stale=%s named_only=%s candidate_decisions=%s',
            $face['status'] ?? 'unavailable',
            $face['pending_total'] ?? '-',
            $face['stale_pending'] ?? '-',
            $face['named_only_unlinked'] ?? '-',
            $face['candidate_decision_rows'] ?? '-'
        ));

        $offline = is_array($headlines['offline_runtime'] ?? null) ? $headlines['offline_runtime'] : [];
        $this->line(sprintf(
            'offline-runtime: %s profile=%s runtime=%s local=%s healthy_local=%s',
            $offline['status'] ?? 'unavailable',
            $offline['active_profile'] ?? '-',
            $offline['runtime_state'] ?? '-',
            $offline['local_runtime_status'] ?? '-',
            $offline['healthy_local_instances'] ?? '-'
        ));

        $scheduler = is_array($headlines['scheduler'] ?? null) ? $headlines['scheduler'] : [];
        $this->line(sprintf(
            'scheduler: %s jobs=%s recommendations=%s warnings=%s notices=%s',
            $scheduler['status'] ?? 'unavailable',
            $scheduler['job_count'] ?? '-',
            $scheduler['recommendation_count'] ?? '-',
            $scheduler['warning_recommendations'] ?? '-',
            $scheduler['notice_recommendations'] ?? '-'
        ));

        $dba = is_array($headlines['dba_arc'] ?? null) ? $headlines['dba_arc'] : [];
        $this->line(sprintf(
            'dba-arc: %s breaches=%s arc_rows=%s arc_gb=%s retention=%s',
            $dba['status'] ?? 'unavailable',
            $dba['threshold_breaches'] ?? '-',
            $dba['arc_rows_total_estimate'] ?? '-',
            $dba['arc_total_gb'] ?? '-',
            $dba['arc_retention_dry_run_status'] ?? '-'
        ));

        if (is_array($headlines['agent_doctor'] ?? null)) {
            $agentDoctor = $headlines['agent_doctor'];
            $this->line(sprintf(
                'agent-doctor: %s agents=%s active_sessions=%s stalled=%s pending_reviews=%s memory_errors=%s',
                $agentDoctor['status'] ?? 'unavailable',
                $agentDoctor['agents_total'] ?? '-',
                $agentDoctor['sessions_active'] ?? '-',
                $agentDoctor['sessions_stalled'] ?? '-',
                $agentDoctor['review_queue_pending'] ?? '-',
                $agentDoctor['memory_error_episodes_window'] ?? '-'
            ));
        }
    }

    private function minutes(mixed $value): string
    {
        return $value === null ? '-' : $value.'m';
    }
}

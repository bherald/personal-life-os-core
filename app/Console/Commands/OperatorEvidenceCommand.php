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
            'review-backlog: %s pending=%s stale=%s high_priority=%s typed=%s typed_blocked=%s typed_blockers=%s packets=%s packet_ready=%s packet_blocked=%s',
            $review['status'] ?? 'unavailable',
            $review['pending_total'] ?? '-',
            $review['stale_pending'] ?? '-',
            $review['high_priority_pending'] ?? '-',
            $review['typed_remediation_rows'] ?? '-',
            $this->yesNo($review['typed_remediation_validation_blocked'] ?? null),
            $review['typed_remediation_validation_blocker_count'] ?? '-',
            $review['packet_rows'] ?? '-',
            $review['packet_ready_rows'] ?? '-',
            $review['packet_blocked_rows'] ?? '-'
        ));

        $face = is_array($headlines['face'] ?? null) ? $headlines['face'] : [];
        $this->line(sprintf(
            'face: %s pending=%s stale=%s no_match=%s stale_no_match=%s named_only=%s open_named_only=%s stale_open_named_only=%s terminal_named_only=%s no_decision=%s nonterminal=%s age30d=%s candidate_decisions=%s',
            $face['status'] ?? 'unavailable',
            $face['pending_total'] ?? '-',
            $face['stale_pending'] ?? '-',
            $face['no_match_pending'] ?? '-',
            $face['stale_no_match_pending'] ?? '-',
            $face['named_only_unlinked'] ?? '-',
            $face['open_named_only_unlinked'] ?? '-',
            $face['stale_open_named_only_unlinked'] ?? '-',
            $face['terminal_decided_named_only_unlinked'] ?? '-',
            $face['named_only_open_without_candidate_decision'] ?? '-',
            $face['named_only_open_with_nonterminal_decision'] ?? '-',
            $face['named_only_open_over_thirty_days'] ?? '-',
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

        $genealogyTriage = is_array($headlines['genealogy_agent_triage'] ?? null)
            ? $headlines['genealogy_agent_triage']
            : [];
        $this->line(sprintf(
            'genealogy-triage: %s targets=%s disabled=%s missing=%s review_needed=%s sessions=%s reviews=%s awo=%s/%s rate=%s scheduler_enable_allowed=%s writeback_allowed=%s canonical_writeback_allowed=%s',
            $genealogyTriage['status'] ?? 'unavailable',
            $genealogyTriage['targets_total'] ?? '-',
            $genealogyTriage['disabled_targets'] ?? '-',
            $genealogyTriage['missing_targets'] ?? '-',
            $genealogyTriage['targets_needing_review_count'] ?? '-',
            $genealogyTriage['completed_sessions_window'] ?? '-',
            $genealogyTriage['review_outputs_window'] ?? '-',
            $genealogyTriage['awo_approval_worthy_reviews_window'] ?? '-',
            $genealogyTriage['awo_completed_reviews_window'] ?? '-',
            $this->valueOrDash($genealogyTriage['awo_approval_worthy_rate'] ?? null),
            $genealogyTriage['scheduler_enablement_allowed_targets'] ?? '-',
            $genealogyTriage['production_writeback_allowed_targets'] ?? '-',
            $genealogyTriage['canonical_genealogy_writeback_allowed_targets'] ?? '-'
        ));

        $genealogyGates = is_array($headlines['genealogy_no_decision_gates'] ?? null)
            ? $headlines['genealogy_no_decision_gates']
            : [];
        $this->line(sprintf(
            'genealogy-gates: %s state=%s mode=%s review_ready=%s pass=%s packet_ready=%s packet_blocked=%s named_only_open=%s no_decision=%s triage_review=%s guarded=%s next=%s automation_allowed=%s writeback_allowed=%s canonical_allowed=%s',
            $genealogyGates['status'] ?? 'unavailable',
            $genealogyGates['state'] ?? '-',
            $genealogyGates['mode'] ?? '-',
            $this->yesNo($genealogyGates['packet_review_ready'] ?? null),
            $this->yesNo($genealogyGates['packet_operator_pass_recorded'] ?? null),
            $genealogyGates['packet_ready_rows'] ?? '-',
            $genealogyGates['packet_blocked_rows'] ?? '-',
            $genealogyGates['open_named_only_unlinked'] ?? '-',
            $genealogyGates['named_only_without_candidate_decision'] ?? '-',
            $genealogyGates['triage_targets_needing_review'] ?? '-',
            $genealogyGates['scheduled_guarded_output_runs_window'] ?? '-',
            $genealogyGates['next_gate'] ?? '-',
            $this->yesNo($genealogyGates['automation_allowed'] ?? null),
            $this->yesNo($genealogyGates['production_writeback_allowed'] ?? null),
            $this->yesNo($genealogyGates['canonical_writeback_allowed'] ?? null)
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

    private function valueOrDash(mixed $value): string
    {
        return $value === null ? '-' : (string) $value;
    }

    private function yesNo(mixed $value): string
    {
        return $value === null ? '-' : ((bool) $value ? 'yes' : 'no');
    }
}

<?php

namespace App\Services;

use App\Services\AgentMetrics\AwoReplayService;
use App\Services\AgentMetrics\GenealogyAgentTriageService;
use App\Services\Genealogy\GenealogyEvidenceSprintReadinessService;
use App\Services\Ops\AgentDoctorService;
use App\Services\Ops\AgentRecursionCallsRetentionService;
use App\Services\Ops\DbaTelemetryReportService;
use App\Services\Ops\ReviewBacklogReportService;
use App\Services\Ops\SchedulerOptimizeReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperatorEvidenceService
{
    private const STATUSES = ['healthy' => 0, 'watch' => 1, 'degraded' => 2, 'blocked' => 3];

    private const QUEUES = ['high', 'default', 'low', 'long-running', 'workflow', 'speculative'];

    private const DBA_TELEMETRY_CACHE_KEY = 'operator_evidence:dba_telemetry:v1';

    private const ARC_RETENTION_CACHE_KEY = 'operator_evidence:arc_retention:v1';

    private const SCHEDULER_OPTIMIZATION_CACHE_KEY = 'operator_evidence:scheduler_optimization:v1';

    private const AWO_REPLAY_CACHE_KEY = 'operator_evidence:awo_replay:v1';

    private const NEWS_BIAS_COVERAGE_CACHE_KEY = 'operator_evidence:news_bias_coverage:v1';

    private const AGENT_DOCTOR_CACHE_KEY = 'operator_evidence:agent_doctor:v1';

    private const REVIEW_BACKLOG_CACHE_KEY = 'operator_evidence:review_backlog:v1';

    private const RAG_SCALE_CACHE_KEY = 'operator_evidence:rag_scale:v1';

    private const GENEALOGY_EVIDENCE_SPRINT_CACHE_KEY = 'operator_evidence:genealogy_evidence_sprint:v1';

    private const FACE_LINK_WEEKLY_REPORT_JOB = 'face_link_weekly_report';

    private const GENEALOGY_AUTOMATION_TARGETS = [
        'genealogy_analyst',
        'genealogy_auto_research',
        'genealogy_newspaper_research',
        'genealogy_research_colonial_fan',
    ];

    private ?array $genealogyAutomationTargetCounts = null;

    public function __construct(
        private readonly RagBacklogService $ragBacklog,
        private readonly OfflinePolicyService $offlinePolicy,
        private readonly OfflineAuditService $offlineAudit,
        private readonly AgentProceduralMemoryService $proceduralMemory,
        private readonly ?GenealogyAgentTriageService $genealogyTriage = null,
        private readonly ?DbaTelemetryReportService $dbaTelemetry = null,
        private readonly ?AwoReplayService $awoReplay = null,
        private readonly ?NewsBiasCoverageService $newsBiasCoverage = null,
        private readonly ?AgentDoctorService $agentDoctor = null,
        private readonly ?LLMPoolManagerService $llmPool = null,
        private readonly ?ReviewBacklogReportService $reviewBacklog = null,
        private readonly ?GenealogyEvidenceSprintReadinessService $genealogyEvidenceSprint = null,
        private readonly ?AgentRecursionCallsRetentionService $arcRetention = null,
        private readonly ?SchedulerOptimizeReportService $schedulerOptimize = null,
    ) {}

    public function collect(): array
    {
        $this->genealogyAutomationTargetCounts = null;

        $sampledAt = Carbon::now('UTC');
        $ragMetrics = $this->collectRagDigest();
        $ragNetBurn = $this->collectRagNetBurn();

        $sections = [
            'queue_health' => $this->collectQueueHealth($sampledAt),
            'scheduler_optimization' => $this->collectSchedulerOptimization($sampledAt),
            'dba_telemetry' => $this->collectDbaTelemetry($sampledAt),
            'kg_backlog' => $this->collectKgBacklog($sampledAt, $ragMetrics, $ragNetBurn),
            'raptor_sentence_drained' => $this->collectRaptorSentenceDrained($sampledAt, $ragMetrics, $ragNetBurn),
            'rag_scale_baseline' => $this->collectRagScaleBaseline($sampledAt),
            'genealogy_pending_approvals' => $this->collectGenealogyPendingApprovals($sampledAt),
            'genealogy_review_feedback' => $this->collectGenealogyReviewFeedback($sampledAt),
            'genealogy_evidence_sprint' => $this->collectGenealogyEvidenceSprint($sampledAt),
            'awo_replay' => $this->collectAwoReplay($sampledAt),
            'agent_doctor' => $this->collectAgentDoctor($sampledAt),
            'review_backlog' => $this->collectReviewBacklog($sampledAt),
            'news_bias_coverage' => $this->collectNewsBiasCoverage($sampledAt),
            'face_match_link_backlog' => $this->collectFaceMatchLinkBacklog($sampledAt),
            'offline_degraded_state' => $this->collectOfflineDegradedState($sampledAt),
            'disabled_genealogy_agents' => $this->collectDisabledGenealogyAgents($sampledAt),
            'genealogy_agent_triage' => $this->collectGenealogyAgentTriage($sampledAt),
            'agent_failures_stale_work' => $this->collectAgentFailuresStaleWork($sampledAt),
        ];

        return [
            'version' => 1,
            'captured_at' => $this->formatTimestamp($sampledAt),
            'status' => $this->worstStatus(array_column($sections, 'status')),
            'sections' => $sections,
        ];
    }

    public function collectOfflineStatus(): array
    {
        $sampledAt = Carbon::now('UTC');
        $section = $this->collectOfflineDegradedState($sampledAt);

        return [
            'version' => 1,
            'mode' => 'observe',
            'captured_at' => $this->formatTimestamp($sampledAt),
            'status' => $section['status'] ?? 'degraded',
            'section' => $section,
        ];
    }

    public function compactPayload(array $payload): array
    {
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];

        $headlines = [
            'queue' => $this->compactQueueHeadline($sections),
            'kg_rag' => $this->compactKgRagHeadline($sections),
            'review_backlog' => $this->compactReviewBacklogHeadline($sections),
            'face' => $this->compactFaceHeadline($sections),
            'offline_runtime' => $this->compactOfflineRuntimeHeadline($sections),
            'scheduler' => $this->compactSchedulerHeadline($sections),
            'dba_arc' => $this->compactDbaArcHeadline($sections),
            'genealogy_evidence_sprint' => $this->compactGenealogyEvidenceSprintHeadline($sections),
            'genealogy_agent_triage' => $this->compactGenealogyAgentTriageHeadline($sections),
        ];

        if (is_array($sections['agent_doctor'] ?? null)) {
            $headlines['agent_doctor'] = $this->compactAgentDoctorHeadline($sections);
        }

        return [
            'version' => (int) ($payload['version'] ?? 1),
            'mode' => 'observe',
            'compact' => true,
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'degraded')),
            'sampled_at' => $this->nullableString($payload['captured_at'] ?? $payload['sampled_at'] ?? null),
            'headlines' => $headlines,
        ];
    }

    private function compactQueueHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'queue_health');
        $counts = $this->compactCounts($section);
        $freshness = $this->compactFreshness($section);

        return [
            'status' => $this->compactStatus($section),
            'queue_depth_total' => (int) ($counts['queue_depth_total'] ?? 0),
            'queue_depths' => $this->integerCountMap($counts['queue_depths'] ?? []),
            'enabled_scheduled_jobs' => (int) ($counts['enabled_scheduled_jobs'] ?? 0),
            'stale_running_jobs' => (int) ($counts['stale_running_jobs'] ?? 0),
            'recent_scheduler_failures' => (int) ($counts['recent_scheduler_failures'] ?? 0),
            'recent_queue_failures' => $counts['recent_queue_failures'] ?? null,
            'recent_failed_jobs_30m' => $counts['recent_failed_jobs_30m'] ?? null,
            'due_jobs_overdue' => (int) ($counts['due_jobs_overdue'] ?? 0),
            'scheduler_lag_minutes' => $freshness['scheduler_lag_minutes'] ?? null,
            'completion_lag_minutes' => $freshness['completion_lag_minutes'] ?? null,
            'queue_source' => $this->nullableString($freshness['queue_source'] ?? null),
        ];
    }

    private function compactKgRagHeadline(array $sections): array
    {
        $kg = $this->compactSection($sections, 'kg_backlog');
        $kgCounts = $this->compactCounts($kg);
        $drain = $this->compactSection($sections, 'raptor_sentence_drained');
        $drainCounts = $this->compactCounts($drain);
        $scale = $this->compactSection($sections, 'rag_scale_baseline');
        $scaleCounts = $this->compactCounts($scale);

        return [
            'kg_status' => $this->compactStatus($kg),
            'rag_drain_status' => $this->compactStatus($drain),
            'rag_scale_status' => $this->compactStatus($scale),
            'documents' => (int) ($kgCounts['documents'] ?? $scaleCounts['documents'] ?? 0),
            'kg_pending' => (int) ($kgCounts['kg_pending'] ?? 0),
            'kg_fresh_pending' => (int) ($kgCounts['kg_fresh_pending'] ?? 0),
            'kg_stale_pending' => (int) ($kgCounts['kg_stale_pending'] ?? 0),
            'kg_entities' => (int) ($kgCounts['kg_entities'] ?? 0),
            'kg_throughput_per_day' => (int) ($kgCounts['throughput_per_day'] ?? 0),
            'kg_eta_days' => $kgCounts['eta_days'] ?? null,
            'kg_net_burn_per_day' => $kgCounts['kg_net_burn_per_day'] ?? null,
            'kg_net_burn_trend' => $this->nullableString($kgCounts['kg_net_burn_trend'] ?? null),
            'raptor_pending' => (int) ($drainCounts['raptor_pending'] ?? 0),
            'sentence_pending' => (int) ($drainCounts['sentence_pending'] ?? 0),
            'drained' => (bool) ($drainCounts['drained'] ?? false),
            'raptor_net_burn_trend' => $this->nullableString($drainCounts['raptor_net_burn_trend'] ?? null),
            'sentence_net_burn_trend' => $this->nullableString($drainCounts['sentence_net_burn_trend'] ?? null),
            'rag_documents_relation_mb' => $scaleCounts['rag_documents_relation_mb'] ?? null,
            'rag_scale_recommendations' => (int) ($scaleCounts['recommendations'] ?? 0),
        ];
    }

    private function compactReviewBacklogHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'review_backlog');
        $counts = $this->compactCounts($section);

        return [
            'status' => $this->compactStatus($section),
            'pending_total' => (int) ($counts['pending_total'] ?? 0),
            'stale_pending' => (int) ($counts['stale_pending'] ?? 0),
            'high_priority_pending' => (int) ($counts['high_priority_pending'] ?? 0),
            'pending_age_groups' => (int) ($counts['pending_age_groups'] ?? 0),
            'pending_type_groups' => (int) ($counts['pending_type_groups'] ?? 0),
            'triage_buckets' => (int) ($counts['triage_buckets'] ?? 0),
            'typed_remediation_rows' => (int) ($counts['typed_remediation_rows'] ?? 0),
            'preview_only_remediation_rows' => (int) ($counts['preview_only_remediation_rows'] ?? 0),
            'supported_preview_operation_rows' => (int) ($counts['supported_preview_operation_rows'] ?? 0),
            'packet_rows' => (int) ($counts['packet_rows'] ?? 0),
            'packet_ready_rows' => (int) ($counts['packet_ready_rows'] ?? 0),
            'packet_blocked_rows' => (int) ($counts['packet_blocked_rows'] ?? 0),
            'packet_preview_only_rows' => (int) ($counts['packet_preview_only_rows'] ?? 0),
            'packet_canonical_mutation_rows' => (int) ($counts['packet_canonical_mutation_rows'] ?? 0),
            'recommendations' => (int) ($counts['recommendations'] ?? 0),
        ];
    }

    private function compactFaceHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'face_match_link_backlog');
        $counts = $this->compactCounts($section);

        return [
            'status' => $this->compactStatus($section),
            'pending_total' => (int) ($counts['pending_total'] ?? 0),
            'stale_pending' => (int) ($counts['stale_pending'] ?? 0),
            'no_match_pending' => (int) ($counts['no_match_pending'] ?? 0),
            'stale_no_match_pending' => (int) ($counts['stale_no_match_pending'] ?? 0),
            'fuzzy_pending' => (int) ($counts['fuzzy_pending'] ?? 0),
            'named_only_unlinked' => (int) ($counts['named_only_unlinked'] ?? 0),
            'open_named_only_unlinked' => (int) ($counts['open_named_only_unlinked'] ?? 0),
            'stale_open_named_only_unlinked' => (int) ($counts['stale_open_named_only_unlinked'] ?? 0),
            'terminal_decided_named_only_unlinked' => (int) ($counts['terminal_decided_named_only_unlinked'] ?? 0),
            'named_only_open_without_candidate_decision' => (int) ($counts['named_only_open_without_candidate_decision'] ?? 0),
            'named_only_open_with_nonterminal_decision' => (int) ($counts['named_only_open_with_nonterminal_decision'] ?? 0),
            'named_only_pending_no_match_faces' => (int) ($counts['named_only_pending_no_match_faces'] ?? 0),
            'named_only_stale_pending_no_match_faces' => (int) ($counts['named_only_stale_pending_no_match_faces'] ?? 0),
            'named_only_open_over_thirty_days' => (int) ($counts['named_only_open_over_thirty_days'] ?? 0),
            'named_only_verified' => (int) ($counts['named_only_verified'] ?? 0),
            'approved_missing_person_media' => (int) ($counts['approved_missing_person_media'] ?? 0),
            'candidate_decision_rows' => (int) ($counts['candidate_decision_rows'] ?? 0),
            'candidate_recent_decisions' => (int) ($counts['candidate_recent_decisions'] ?? 0),
            'weekly_report_status' => $this->nullableString($counts['weekly_report_status'] ?? null),
            'weekly_report_enabled' => (bool) ($counts['weekly_report_enabled'] ?? false),
            'weekly_report_latest_run_status' => $this->nullableString($counts['weekly_report_latest_run_status'] ?? null),
            'weekly_report_latest_success_completed_at' => $this->nullableString($counts['weekly_report_latest_success_completed_at'] ?? null),
            'weekly_report_latest_success_age_hours' => $counts['weekly_report_latest_success_age_hours'] ?? null,
            'weekly_report_has_bridge_alignment' => (bool) ($counts['weekly_report_has_bridge_alignment'] ?? false),
            'weekly_report_has_candidate_decisions' => (bool) ($counts['weekly_report_has_candidate_decisions'] ?? false),
        ];
    }

    private function compactOfflineRuntimeHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'offline_degraded_state');
        $counts = $this->compactCounts($section);

        return [
            'status' => $this->compactStatus($section),
            'offline_mode_active' => (bool) ($counts['offline_mode_active'] ?? false),
            'active_profile' => $this->nullableString($counts['active_profile'] ?? null),
            'runtime_state' => $this->nullableString($counts['runtime_state'] ?? null),
            'audit_result' => $this->nullableString($counts['audit_result'] ?? null),
            'audit_total_24h' => (int) ($counts['audit_total_24h'] ?? 0),
            'policy_denials_24h' => (int) ($counts['policy_denials_24h'] ?? 0),
            'mode_changes_24h' => (int) ($counts['mode_changes_24h'] ?? 0),
            'local_runtime_status' => $this->nullableString($counts['local_runtime_status'] ?? null),
            'local_availability_state' => $this->nullableString($counts['local_availability_state'] ?? null),
            'local_instances' => $counts['local_instances'] ?? null,
            'healthy_local_instances' => $counts['healthy_local_instances'] ?? null,
            'selected_local_id' => $this->nullableString($counts['selected_local_id'] ?? null),
            'selected_local_model' => $this->nullableString($counts['selected_local_model'] ?? null),
        ];
    }

    private function compactSchedulerHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'scheduler_optimization');
        $counts = $this->compactCounts($section);

        return [
            'status' => $this->compactStatus($section),
            'window' => $this->nullableString($counts['window'] ?? null),
            'job_count' => (int) ($counts['job_count'] ?? 0),
            'recommendation_count' => (int) ($counts['recommendation_count'] ?? 0),
            'warning_recommendations' => (int) ($counts['warning_recommendations'] ?? 0),
            'notice_recommendations' => (int) ($counts['notice_recommendations'] ?? 0),
            'info_recommendations' => (int) ($counts['info_recommendations'] ?? 0),
            'reliability_recommendations' => (int) ($counts['reliability_recommendations'] ?? 0),
            'timeout_recommendations' => (int) ($counts['timeout_recommendations'] ?? 0),
            'spacing_recommendations' => (int) ($counts['spacing_recommendations'] ?? 0),
        ];
    }

    private function compactDbaArcHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'dba_telemetry');
        $counts = $this->compactCounts($section);

        return [
            'status' => $this->compactStatus($section),
            'threshold_breaches' => (int) ($counts['threshold_breaches'] ?? 0),
            'recommendations' => (int) ($counts['recommendations'] ?? 0),
            'arc_rows_total_estimate' => (int) ($counts['arc_rows_total_estimate'] ?? 0),
            'arc_total_gb' => (float) ($counts['arc_total_gb'] ?? 0.0),
            'arc_raw_recent_scan_skipped' => (bool) ($counts['arc_raw_recent_scan_skipped'] ?? false),
            'arc_retention_dry_run_status' => $this->nullableString($counts['arc_retention_dry_run_status'] ?? null),
            'arc_retention_execute' => (bool) ($counts['arc_retention_execute'] ?? false),
            'arc_retention_has_eligible_rows' => (bool) ($counts['arc_retention_has_eligible_rows'] ?? false),
            'arc_retention_retention_days' => (int) ($counts['arc_retention_retention_days'] ?? 0),
            'postgres_database_total_gb' => (float) ($counts['postgres_database_total_gb'] ?? 0.0),
            'redis_used_memory_mb' => (float) ($counts['redis_used_memory_mb'] ?? 0.0),
            'redis_fragmentation_ratio' => $counts['redis_fragmentation_ratio'] ?? null,
            'redis_key_count' => (int) ($counts['redis_key_count'] ?? 0),
        ];
    }

    private function compactGenealogyEvidenceSprintHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'genealogy_evidence_sprint');
        $counts = $this->compactCounts($section);

        return [
            'status' => $this->compactStatus($section),
            'source_status' => $this->nullableString($counts['source_status'] ?? null),
            'target_packets' => (int) ($counts['target_packets'] ?? 0),
            'source_backed_packets' => (int) ($counts['source_backed_packets'] ?? 0),
            'source_backed_pending' => (int) ($counts['source_backed_pending'] ?? 0),
            'reviewable_pending_packets' => (int) ($counts['reviewable_pending_packets'] ?? 0),
            'remaining_to_target' => (int) ($counts['remaining_to_target'] ?? 0),
            'remaining_reviewable_to_target' => (int) ($counts['remaining_reviewable_to_target'] ?? 0),
            'source_backed_pending_not_packet_pending' => (int) ($counts['source_backed_pending_not_packet_pending'] ?? 0),
            'source_backed_pending_missing_preview_only' => (int) ($counts['source_backed_pending_missing_preview_only'] ?? 0),
            'source_backed_pending_missing_identity' => (int) ($counts['source_backed_pending_missing_identity'] ?? 0),
            'source_backed_pending_missing_privacy_clearance' => (int) ($counts['source_backed_pending_missing_privacy_clearance'] ?? 0),
            'source_backed_pending_missing_claims' => (int) ($counts['source_backed_pending_missing_claims'] ?? 0),
            'source_backed_pending_missing_validation' => (int) ($counts['source_backed_pending_missing_validation'] ?? 0),
            'source_backed_pending_missing_boundary' => (int) ($counts['source_backed_pending_missing_boundary'] ?? 0),
            'source_locator_required_packets' => (int) ($counts['source_locator_required_packets'] ?? 0),
            'manual_only_source_packets' => (int) ($counts['manual_only_source_packets'] ?? 0),
            'source_realism_blocked_packets' => (int) ($counts['source_realism_blocked_packets'] ?? 0),
            'needs_reviewable_packet_details' => (bool) ($counts['needs_reviewable_packet_details'] ?? false),
            'needs_operator_boundary' => (bool) ($counts['needs_operator_boundary'] ?? true),
            'boundary_consistent' => (bool) ($counts['boundary_consistent'] ?? false),
            'mutation_guard_ok' => (bool) ($counts['mutation_guard_ok'] ?? true),
            'ready_for_five_packet_review' => (bool) ($counts['ready_for_five_packet_review'] ?? false),
            'recommendations' => (int) ($counts['recommendations'] ?? 0),
            'evidence_errors' => (int) ($counts['evidence_errors'] ?? 0),
        ];
    }

    private function compactGenealogyAgentTriageHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'genealogy_agent_triage');
        $counts = $this->compactCounts($section);
        $schedulerEnablementAllowed = (int) ($counts['scheduler_enablement_allowed_targets'] ?? 0);
        $productionWritebackAllowed = (int) ($counts['production_writeback_allowed_targets'] ?? 0);
        $canonicalWritebackAllowed = (int) ($counts['canonical_genealogy_writeback_allowed_targets'] ?? 0);

        return [
            'status' => $this->compactStatus($section),
            'window_days' => (int) ($counts['window_days'] ?? 0),
            'targets_total' => (int) ($counts['targets_total'] ?? 0),
            'configured_targets' => (int) ($counts['configured_targets'] ?? 0),
            'enabled_targets' => (int) ($counts['enabled_targets'] ?? 0),
            'disabled_targets' => (int) ($counts['disabled_targets'] ?? 0),
            'missing_targets' => (int) ($counts['missing_targets'] ?? 0),
            'blocked_targets' => (int) ($counts['blocked_targets'] ?? 0),
            'degraded_targets' => (int) ($counts['degraded_targets'] ?? 0),
            'watch_targets' => (int) ($counts['watch_targets'] ?? 0),
            'completed_sessions_window' => (int) ($counts['completed_sessions_window'] ?? 0),
            'review_outputs_window' => (int) ($counts['review_outputs_window'] ?? 0),
            'awo_completed_reviews_window' => (int) ($counts['awo_completed_reviews_window'] ?? 0),
            'awo_approval_worthy_reviews_window' => (int) ($counts['awo_approval_worthy_reviews_window'] ?? 0),
            'awo_approval_worthy_rate' => $counts['awo_approval_worthy_rate'] ?? null,
            'targets_needing_review_count' => (int) ($counts['targets_needing_review_count'] ?? 0),
            'source_backed_review_packets_required_targets' => (int) ($counts['source_backed_review_packets_required_targets'] ?? 0),
            'scenario_test_required_targets' => (int) ($counts['scenario_test_required_targets'] ?? 0),
            'operator_approval_required_targets' => (int) ($counts['operator_approval_required_targets'] ?? 0),
            'awo_sample_floor_met_targets' => (int) ($counts['awo_sample_floor_met_targets'] ?? 0),
            'awo_approval_worthy_present_targets' => (int) ($counts['awo_approval_worthy_present_targets'] ?? 0),
            'scheduler_enablement_allowed_targets' => $schedulerEnablementAllowed,
            'production_writeback_allowed_targets' => $productionWritebackAllowed,
            'canonical_genealogy_writeback_allowed_targets' => $canonicalWritebackAllowed,
            'scheduler_enablement_guard_ok' => $schedulerEnablementAllowed === 0,
            'production_writeback_guard_ok' => $productionWritebackAllowed === 0,
            'canonical_writeback_guard_ok' => $canonicalWritebackAllowed === 0,
        ];
    }

    private function compactAgentDoctorHeadline(array $sections): array
    {
        $section = $this->compactSection($sections, 'agent_doctor');
        $counts = $this->compactCounts($section);

        return [
            'status' => $this->compactStatus($section),
            'overall_status' => $this->nullableString($counts['overall_status'] ?? null),
            'agents_total' => (int) ($counts['agents_total'] ?? 0),
            'agents_enabled' => (int) ($counts['agents_enabled'] ?? 0),
            'agents_with_warnings' => (int) ($counts['agents_with_warnings'] ?? 0),
            'agents_with_critical' => (int) ($counts['agents_with_critical'] ?? 0),
            'sessions_active' => (int) ($counts['sessions_active'] ?? 0),
            'sessions_stalled' => (int) ($counts['sessions_stalled'] ?? 0),
            'review_queue_pending' => (int) ($counts['review_queue_pending'] ?? 0),
            'review_queue_aged' => (int) ($counts['review_queue_aged'] ?? 0),
            'memory_error_episodes_window' => (int) ($counts['memory_error_episodes_window'] ?? 0),
            'memory_undistilled_episodes_window' => (int) ($counts['memory_undistilled_episodes_window'] ?? 0),
            'procedures_low_quality_total' => (int) ($counts['procedures_low_quality_total'] ?? 0),
            'scheduled_success_runs_window' => (int) ($counts['scheduled_success_runs_window'] ?? 0),
            'scheduled_empty_success_outputs_window' => (int) ($counts['scheduled_empty_success_outputs_window'] ?? 0),
            'scheduled_non_ascii_output_runs_window' => (int) ($counts['scheduled_non_ascii_output_runs_window'] ?? 0),
            'scheduled_guarded_output_runs_window' => (int) ($counts['scheduled_guarded_output_runs_window'] ?? 0),
            'top_issue_codes' => array_values(array_filter(
                (array) ($counts['top_issue_codes'] ?? []),
                fn (mixed $code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $code) === 1
            )),
            'top_agent_reasons_critical' => $this->compactAgentReasonList($counts['top_agent_reasons_critical'] ?? []),
            'top_agent_reasons_warning' => $this->compactAgentReasonList($counts['top_agent_reasons_warning'] ?? []),
            'recursion_status' => $this->nullableString($counts['recursion_status'] ?? null),
            'trace_status' => $this->nullableString($counts['trace_status'] ?? null),
            'trace_files_over_retention' => $counts['trace_files_over_retention'] ?? null,
        ];
    }

    private function collectAgentDoctor(Carbon $sampledAt): array
    {
        try {
            $payload = Cache::remember(
                self::AGENT_DOCTOR_CACHE_KEY,
                now()->addMinutes(15),
                fn (): array => ($this->agentDoctor ?? app(AgentDoctorService::class))->collect(24)
            );
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $recursion = is_array($payload['recursion'] ?? null) ? $payload['recursion'] : [];
            $trace = is_array($payload['trace'] ?? null) ? $payload['trace'] : [];
            $checks = array_values(array_filter((array) ($payload['checks'] ?? []), 'is_array'));

            $criticalChecks = array_values(array_filter(array_map(
                fn (array $check): ?string => ($check['status'] ?? null) === 'critical' ? $this->nullableString($check['id'] ?? null) : null,
                $checks
            )));
            $warningChecks = array_values(array_filter(array_map(
                fn (array $check): ?string => ($check['status'] ?? null) === 'warning' ? $this->nullableString($check['id'] ?? null) : null,
                $checks
            )));

            $counts = [
                'window_hours' => (int) ($payload['window_hours'] ?? 24),
                'overall_status' => (string) ($payload['overall_status'] ?? 'unknown'),
                'agents_total' => (int) ($summary['agents_total'] ?? 0),
                'agents_enabled' => (int) ($summary['agents_enabled'] ?? 0),
                'agents_with_warnings' => (int) ($summary['agents_with_warnings'] ?? 0),
                'agents_with_critical' => (int) ($summary['agents_with_critical'] ?? 0),
                'sessions_active' => (int) ($summary['sessions_active'] ?? 0),
                'sessions_stalled' => (int) ($summary['sessions_stalled'] ?? 0),
                'review_queue_pending' => (int) ($summary['review_queue_pending'] ?? 0),
                'review_queue_aged' => (int) ($summary['review_queue_aged'] ?? 0),
                'tools_missing_total' => (int) ($summary['tools_missing_total'] ?? 0),
                'tools_blocked_total' => (int) ($summary['tools_blocked_total'] ?? 0),
                'memory_error_episodes_window' => (int) ($summary['memory_error_episodes_window'] ?? 0),
                'memory_undistilled_episodes_window' => (int) ($summary['memory_undistilled_episodes_window'] ?? 0),
                'procedures_low_quality_total' => (int) ($summary['procedures_low_quality_total'] ?? 0),
                'scheduled_success_runs_window' => (int) ($summary['scheduled_success_runs_window'] ?? 0),
                'scheduled_empty_success_outputs_window' => (int) ($summary['scheduled_empty_success_outputs_window'] ?? 0),
                'scheduled_cjk_output_runs_window' => (int) ($summary['scheduled_cjk_output_runs_window'] ?? 0),
                'scheduled_non_ascii_output_runs_window' => (int) ($summary['scheduled_non_ascii_output_runs_window'] ?? 0),
                'scheduled_guarded_output_runs_window' => (int) ($summary['scheduled_guarded_output_runs_window'] ?? 0),
                'issue_code_counts' => $this->integerCountMap($summary['issue_code_counts'] ?? []),
                'top_issue_codes' => array_values(array_filter(
                    (array) ($summary['top_issue_codes'] ?? []),
                    fn (mixed $code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $code) === 1
                )),
                'top_agent_reasons_critical' => AgentDoctorService::compactAgentReasonSummaries($payload, 'critical'),
                'top_agent_reasons_warning' => AgentDoctorService::compactAgentReasonSummaries($payload, 'warning'),
                'recursion_status' => $this->nullableString($recursion['status'] ?? null),
                'recursion_calls_7d' => (int) ($recursion['calls_7d'] ?? 0),
                'recursion_move_on_rate_7d' => $recursion['move_on_rate_7d'] ?? null,
                'recursion_master_enabled' => isset($recursion['master_enabled']) ? (bool) $recursion['master_enabled'] : null,
                'trace_status' => $this->nullableString($trace['status'] ?? null),
                'trace_enabled' => isset($trace['enabled']) ? (bool) $trace['enabled'] : null,
                'trace_directory_writable' => isset($trace['directory_writable']) ? (bool) $trace['directory_writable'] : null,
                'trace_retention_days' => isset($trace['retention_days']) ? (int) $trace['retention_days'] : null,
                'trace_files_over_retention' => isset($trace['files_over_retention']) ? (int) $trace['files_over_retention'] : null,
                'trace_events_24h' => $trace['events_24h'] ?? null,
                'trace_events_24h_exact' => isset($trace['events_24h_exact']) ? (bool) $trace['events_24h_exact'] : null,
                'trace_malformed_lines_24h' => $trace['malformed_lines_24h'] ?? null,
                'trace_scan_status' => $this->nullableString($trace['scan_status'] ?? null),
                'critical_checks' => $criticalChecks,
                'warning_checks' => $warningChecks,
            ];

            $status = match ((string) ($payload['overall_status'] ?? 'warning')) {
                'healthy' => 'healthy',
                'warning' => 'watch',
                'critical' => 'degraded',
                default => 'watch',
            };

            if ($counts['tools_blocked_total'] > 0) {
                $status = $this->maxStatus($status, 'degraded');
            }

            return $this->section(
                $status,
                $sampledAt,
                ['AgentDoctorService', 'agent_sessions', 'scheduled_jobs', 'agent_review_queue', 'agent_episodes', 'dev_agent_traces'],
                $counts,
                $status === 'healthy' ? null : 'Review ops:agent-doctor --json --since=24 before expanding agent autonomy.',
                [
                    'generated_at' => $this->nullableString($payload['generated_at'] ?? null),
                    'cache_ttl_minutes' => 15,
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['AgentDoctorService'], $e, 'Agent Doctor query failed.');
        }
    }

    private function collectNewsBiasCoverage(Carbon $sampledAt): array
    {
        try {
            $payload = Cache::remember(
                self::NEWS_BIAS_COVERAGE_CACHE_KEY,
                now()->addMinutes(15),
                fn (): array => ($this->newsBiasCoverage ?? app(NewsBiasCoverageService::class))->collect(7, 25)
            );
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $topUnmatched = array_values(array_filter((array) ($payload['top_unmatched_sources'] ?? []), 'is_array'));
            $status = $this->normalizeStatus((string) ($payload['status'] ?? 'watch'));

            $counts = [
                'window_days' => (int) ($payload['window_days'] ?? 7),
                'bias_ratings' => (int) ($summary['bias_ratings'] ?? 0),
                'aliases' => (int) ($summary['aliases'] ?? 0),
                'active_aliases' => (int) ($summary['active_aliases'] ?? 0),
                'orphaned_aliases' => (int) ($summary['orphaned_aliases'] ?? 0),
                'recent_articles' => (int) ($summary['recent_articles'] ?? 0),
                'recent_feeds' => (int) ($summary['recent_feeds'] ?? 0),
                'recent_bias_covered' => (int) ($summary['recent_bias_covered'] ?? 0),
                'recent_bias_missing' => (int) ($summary['recent_bias_missing'] ?? 0),
                'recent_bias_coverage_rate' => $summary['recent_bias_coverage_rate'] ?? null,
                'unmatched_sources' => (int) ($summary['unmatched_sources'] ?? 0),
                'top_unmatched_sources' => array_map(
                    fn (array $row): string => sprintf('%s (%d)', (string) ($row['source'] ?? 'unknown'), (int) ($row['count'] ?? 0)),
                    $topUnmatched
                ),
                'missing_tables' => array_values(array_filter((array) ($payload['missing_tables'] ?? []), 'is_string')),
            ];

            return $this->section(
                $status,
                $sampledAt,
                ['NewsBiasCoverageService', 'bias_ratings', 'bias_rating_aliases', 'news_articles', 'BiasRatingEnrich'],
                $counts,
                $status === 'healthy' ? null : 'Review news:source-inventory and bias:aliases --unmatched before adding or changing source aliases.',
                [
                    'latest_article_at' => $this->nullableString($summary['latest_article_at'] ?? null),
                    'cache_ttl_minutes' => 15,
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['NewsBiasCoverageService', 'bias_ratings'], $e, 'News bias coverage query failed.');
        }
    }

    private function collectReviewBacklog(Carbon $sampledAt): array
    {
        try {
            $payload = Cache::remember(
                self::REVIEW_BACKLOG_CACHE_KEY,
                now()->addMinutes(15),
                fn (): array => ($this->reviewBacklog ?? app(ReviewBacklogReportService::class))->collect()
            );
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $pendingByAge = array_values(array_filter((array) ($payload['pending_by_age'] ?? []), 'is_array'));
            $pendingByType = array_values(array_filter((array) ($payload['pending_by_type'] ?? []), 'is_array'));
            $pendingByAgent = array_values(array_filter((array) ($payload['pending_by_agent'] ?? []), 'is_array'));
            $triageBuckets = array_values(array_filter((array) ($payload['triage_buckets'] ?? []), 'is_array'));
            $statusCounts = array_values(array_filter((array) ($payload['status_counts'] ?? []), 'is_array'));
            $remediationReadiness = is_array($payload['remediation_readiness'] ?? null)
                ? $payload['remediation_readiness']
                : [];
            $packetReadiness = is_array($payload['packet_readiness'] ?? null)
                ? $payload['packet_readiness']
                : [];
            $recommendations = array_values(array_filter((array) ($payload['recommendations'] ?? []), 'is_string'));
            $status = $this->mapObserveStatus((string) ($payload['status'] ?? 'observe_warning'));

            $counts = [
                'mode' => $this->nullableString($payload['mode'] ?? null),
                'dry_run' => (bool) ($payload['dry_run'] ?? false),
                'stale_days' => (int) ($payload['stale_days'] ?? 7),
                'high_priority_threshold' => (int) ($payload['high_priority_threshold'] ?? 8),
                'pending_total' => (int) ($summary['pending_total'] ?? 0),
                'stale_pending' => (int) ($summary['stale_pending'] ?? 0),
                'high_priority_pending' => (int) ($summary['high_priority_pending'] ?? 0),
                'oldest_pending_at' => $this->nullableString($summary['oldest_pending_at'] ?? null),
                'newest_pending_at' => $this->nullableString($summary['newest_pending_at'] ?? null),
                'pending_age_groups' => count($pendingByAge),
                'pending_type_groups' => count($pendingByType),
                'pending_agent_groups' => count($pendingByAgent),
                'triage_buckets' => count($triageBuckets),
                'top_pending_age_buckets' => $this->reviewBacklogAgeSummary($pendingByAge),
                'top_pending_types' => $this->reviewBacklogTypeSummary($pendingByType),
                'top_pending_agents' => $this->reviewBacklogAgentSummary($pendingByAgent),
                'top_triage_buckets' => $this->reviewBacklogTriageSummary($triageBuckets),
                'status_counts' => $this->reviewBacklogStatusSummary($statusCounts),
                'typed_remediation_rows' => (int) ($remediationReadiness['pending_typed_remediation_rows'] ?? 0),
                'preview_only_remediation_rows' => (int) ($remediationReadiness['preview_only_rows'] ?? 0),
                'supported_preview_operation_rows' => (int) ($remediationReadiness['supported_preview_operation_rows'] ?? 0),
                'remediation_without_materialized_ids' => (int) ($remediationReadiness['without_materialized_ids'] ?? 0),
                'remediation_source_duplicate_candidates' => (int) ($remediationReadiness['source_duplicate_id_candidates'] ?? 0),
                'remediation_family_duplicate_candidates' => (int) ($remediationReadiness['family_duplicate_id_candidates'] ?? 0),
                'remediation_source_proposed_change_rows' => (int) ($remediationReadiness['source_proposed_change_id_rows'] ?? 0),
                'remediation_family_context_rows' => (int) ($remediationReadiness['family_context_rows'] ?? 0),
                'remediation_malformed_details' => (int) ($remediationReadiness['malformed_details'] ?? 0),
                'remediation_possible_change_type_typos' => $this->possibleChangeTypeTypoCounts($remediationReadiness['possible_change_type_typos'] ?? []),
                'remediation_possible_change_type_typo_suggestions' => $this->possibleChangeTypeTypoSuggestions($remediationReadiness['possible_change_type_typos'] ?? []),
                'remediation_change_types' => $this->integerCountMap($remediationReadiness['change_types'] ?? []),
                'remediation_supported_operations' => $this->integerCountMap($remediationReadiness['supported_operations'] ?? []),
                'packet_rows' => (int) ($packetReadiness['pending_packet_rows'] ?? 0),
                'packet_ready_rows' => (int) ($packetReadiness['ready_rows'] ?? 0),
                'packet_blocked_rows' => (int) ($packetReadiness['blocked_rows'] ?? 0),
                'packet_source_backed_rows' => (int) ($packetReadiness['source_backed_rows'] ?? 0),
                'packet_preview_only_rows' => (int) ($packetReadiness['preview_only_rows'] ?? 0),
                'packet_canonical_mutation_rows' => (int) ($packetReadiness['canonical_mutation_rows'] ?? 0),
                'packet_reason_code_counts' => $this->integerCountMap($packetReadiness['reason_code_counts'] ?? []),
                'packet_blocker_code_counts' => $this->integerCountMap($packetReadiness['blocker_code_counts'] ?? []),
                'recommendations' => count($recommendations),
            ];

            return $this->section(
                $status,
                $sampledAt,
                ['ReviewBacklogReportService', 'agent_review_queue'],
                $counts,
                $status === 'healthy'
                    ? null
                    : 'Review ops:review-backlog-report --json before clearing aged or high-priority review rows.',
                [
                    'captured_at' => $this->nullableString($payload['captured_at'] ?? null),
                    'cache_ttl_minutes' => 15,
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['ReviewBacklogReportService', 'agent_review_queue'], $e, 'Review backlog evidence query failed.');
        }
    }

    private function collectRagScaleBaseline(Carbon $sampledAt): array
    {
        try {
            $payload = Cache::remember(
                self::RAG_SCALE_CACHE_KEY,
                now()->addMinutes(30),
                fn (): array => $this->ragBacklog->getScaleBaseline()
            );

            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $storage = is_array($payload['storage'] ?? null) ? $payload['storage'] : [];
            $postgres = is_array($payload['postgres'] ?? null) ? $payload['postgres'] : [];
            $tableHealth = is_array($postgres['table_health'] ?? null) ? $postgres['table_health'] : [];
            $indexSummary = is_array($postgres['index_summary'] ?? null) ? $postgres['index_summary'] : [];
            $schema = is_array($payload['schema'] ?? null) ? $payload['schema'] : [];
            $embeddingTables = array_values(array_filter((array) ($payload['embedding_tables'] ?? []), 'is_array'));
            $recommendations = array_values(array_filter((array) ($payload['recommendations'] ?? []), 'is_string'));

            $tableRows = [];
            $missingTables = 0;
            foreach ($embeddingTables as $row) {
                $table = $this->nullableString($row['table'] ?? null);
                if ($table === null) {
                    continue;
                }
                if (($row['exists'] ?? true) === false) {
                    $missingTables++;

                    continue;
                }
                $tableRows[$table] = (int) ($row['rows'] ?? 0);
            }

            $counts = [
                'mode' => $this->nullableString($payload['mode'] ?? null),
                'documents' => (int) ($summary['documents'] ?? 0),
                'parent_documents' => (int) ($summary['parent_documents'] ?? 0),
                'child_documents' => (int) ($summary['child_documents'] ?? 0),
                'content_chars' => (int) ($summary['content_chars'] ?? 0),
                'avg_content_chars' => (int) ($summary['avg_content_chars'] ?? 0),
                'max_content_chars' => (int) ($summary['max_content_chars'] ?? 0),
                'compressed_documents' => (int) ($summary['compressed_documents'] ?? 0),
                'compressed_ratio' => $summary['compressed_ratio'] ?? null,
                'contextualized_documents' => (int) ($summary['contextualized_documents'] ?? 0),
                'contextualized_ratio' => $summary['contextualized_ratio'] ?? null,
                'sparse_documents' => (int) ($summary['sparse_documents'] ?? 0),
                'hype_documents' => (int) ($summary['hype_documents'] ?? 0),
                'image_embedding_documents' => (int) ($summary['image_embedding_documents'] ?? 0),
                'rag_documents_relation_mb' => $storage['total_relation_mb'] ?? null,
                'rag_documents_heap_mb' => $storage['heap_mb'] ?? null,
                'rag_documents_index_mb' => $storage['index_mb'] ?? null,
                'rag_documents_dead_tuples' => (int) ($tableHealth['dead_tuples'] ?? 0),
                'rag_documents_dead_tuple_ratio' => $tableHealth['dead_tuple_ratio'] ?? null,
                'rag_documents_last_autovacuum_at' => $this->nullableString($tableHealth['last_autovacuum_at'] ?? null),
                'rag_documents_last_autoanalyze_at' => $this->nullableString($tableHealth['last_autoanalyze_at'] ?? null),
                'rag_documents_index_count' => (int) ($indexSummary['index_count'] ?? 0),
                'rag_documents_zero_scan_indexes' => (int) ($indexSummary['zero_scan_indexes'] ?? 0),
                'rag_documents_invalid_indexes' => (int) ($indexSummary['invalid_indexes'] ?? 0),
                'rag_documents_unready_indexes' => (int) ($indexSummary['unready_indexes'] ?? 0),
                'rag_documents_largest_index_name' => $this->nullableString($indexSummary['largest_index_name'] ?? null),
                'rag_documents_largest_index_mb' => $indexSummary['largest_index_mb'] ?? null,
                'scale_tables_available' => count(array_filter($embeddingTables, fn (array $row): bool => ($row['exists'] ?? true) !== false)),
                'scale_tables_missing' => max($missingTables, count((array) ($schema['missing_tables'] ?? []))),
                'missing_optional_columns' => count((array) ($schema['missing_optional_columns'] ?? [])),
                'sentence_embedding_rows' => $tableRows['rag_sentence_embeddings'] ?? 0,
                'raptor_summary_rows' => $tableRows['raptor_summaries'] ?? 0,
                'kg_triple_rows' => $tableRows['knowledge_graph'] ?? 0,
                'kg_entity_rows' => $tableRows['knowledge_graph_entities'] ?? 0,
                'kg_entity_embedding_rows' => $tableRows['knowledge_graph_entity_embeddings'] ?? 0,
                'kg_hyperedge_rows' => $tableRows['knowledge_graph_hyperedges'] ?? 0,
                'recommendations' => count($recommendations),
            ];

            $status = $this->mapObserveStatus((string) ($payload['status'] ?? 'observe_warning'));
            if ($counts['max_content_chars'] > 100000 || $counts['missing_optional_columns'] > 0 || $counts['scale_tables_missing'] > 0) {
                $status = $this->maxStatus($status, 'watch');
            }
            if (
                $counts['rag_documents_invalid_indexes'] > 0
                || $counts['rag_documents_unready_indexes'] > 0
                || ((float) ($counts['rag_documents_dead_tuple_ratio'] ?? 0.0) >= 0.2 && ((int) ($tableHealth['live_tuples'] ?? 0) + $counts['rag_documents_dead_tuples']) > 10000)
            ) {
                $status = $this->maxStatus($status, 'watch');
            }

            return $this->section(
                $status,
                $sampledAt,
                ['RagBacklogService', 'pgsql_rag.rag_documents', 'pgsql_rag.information_schema', 'pgsql_rag.pg_stat_user_tables', 'pgsql_rag.pg_stat_user_indexes', 'pgsql_rag.pg_index', 'pgsql_rag.pg_class'],
                $counts,
                $status === 'healthy' ? null : 'Review rag:scale-baseline --json before RAG chunking, compression, vector, or indexing changes.',
                [
                    'captured_at' => $this->nullableString($payload['captured_at'] ?? null),
                    'cache_ttl_minutes' => 30,
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['RagBacklogService', 'pgsql_rag.rag_documents'], $e, 'RAG scale baseline query failed.');
        }
    }

    private function collectAwoReplay(Carbon $sampledAt): array
    {
        try {
            $payload = Cache::remember(
                self::AWO_REPLAY_CACHE_KEY,
                now()->addMinutes(15),
                fn (): array => ($this->awoReplay ?? app(AwoReplayService::class))->collect('7d', 500)
            );
            $replayService = $this->awoReplay ?? app(AwoReplayService::class);
            $comparison = $this->collectAwoScheduledComparison($replayService, $payload);
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $byAgent = array_values(array_filter((array) ($payload['by_agent'] ?? []), 'is_array'));
            $recordingEnabled = $this->readAwoRecordingEnabled();
            $status = $this->mapAwoReplayStatus((string) ($payload['status'] ?? 'insufficient_data'));
            $comparisonStatus = (string) ($comparison['status'] ?? 'unavailable');
            if ($comparisonStatus !== 'observe_ok') {
                $status = $this->maxStatus($status, 'watch');
            }

            $fieldMatches = array_values(array_filter((array) ($comparison['field_matches'] ?? []), 'is_array'));
            $matchingFields = count(array_filter($fieldMatches, fn (array $row): bool => (bool) ($row['matches'] ?? false)));
            $mismatchingFields = count($fieldMatches) - $matchingFields;
            $latestScheduledRun = is_array($comparison['latest_scheduled_run'] ?? null)
                ? $comparison['latest_scheduled_run']
                : null;
            $job = is_array($comparison['job'] ?? null) ? $comparison['job'] : null;

            $counts = [
                'window' => (string) ($payload['window'] ?? '7d'),
                'limit' => (int) ($payload['limit'] ?? 500),
                'recording_enabled' => $recordingEnabled,
                'rows_scanned' => (int) ($summary['rows_scanned'] ?? 0),
                'completed_reviews' => (int) ($summary['completed_reviews'] ?? 0),
                'approval_worthy_reviews' => (int) ($summary['approval_worthy_reviews'] ?? 0),
                'approval_worthy_rate' => $summary['approval_worthy_rate'] ?? null,
                'review_approval_yield' => $summary['review_approval_yield'] ?? null,
                'operator_rework_rate' => $summary['operator_rework_rate'] ?? null,
                'hard_fail_count' => (int) ($summary['hard_fail_count'] ?? 0),
                'insufficient_data' => (bool) ($summary['insufficient_data'] ?? true),
                'by_agent_count' => count($byAgent),
                'agents_with_hard_fails' => count(array_filter(
                    $byAgent,
                    fn (array $row): bool => (int) ($row['hard_fail_count'] ?? 0) > 0
                )),
                'promotion_decisions_count' => count((array) ($payload['promotion_decisions'] ?? [])),
                'scheduled_comparison_status' => $comparisonStatus,
                'scheduled_comparison_available' => $latestScheduledRun !== null,
                'scheduled_job_enabled' => is_array($job) && array_key_exists('enabled', $job) ? (bool) $job['enabled'] : null,
                'scheduled_fields_compared' => count($fieldMatches),
                'scheduled_field_matches' => $matchingFields,
                'scheduled_field_mismatches' => $mismatchingFields,
                'scheduled_next_run_at' => $this->nullableString($job['next_run_at'] ?? null),
                'scheduled_latest_run_at' => $this->nullableString($latestScheduledRun['completed_at'] ?? null),
            ];

            if ($recordingEnabled) {
                $status = $this->maxStatus($status, 'degraded');
            }

            return $this->section(
                $status,
                $sampledAt,
                ['AwoReplayService', 'agent_review_queue', 'system_configs'],
                $counts,
                $recordingEnabled
                    ? 'Confirm AWO recording signoff; prod should remain default-off until validation is complete.'
                    : ($status === 'healthy' ? null : 'Review awo:replay --window=7d --json and scheduled comparison evidence before expanding agent autonomy.'),
                [
                    'cutoff' => $this->nullableString($payload['cutoff'] ?? null),
                    'mode' => $this->nullableString($payload['mode'] ?? null),
                    'scheduled_comparison_generated_at' => $this->nullableString($comparison['generated_at'] ?? null),
                    'cache_ttl_minutes' => 15,
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['AwoReplayService', 'agent_review_queue'], $e, 'AWO replay query failed.');
        }
    }

    private function collectAwoScheduledComparison(AwoReplayService $replay, array $currentReplay): array
    {
        try {
            return $replay->collectScheduledComparison('7d', 500, 'awo_replay_weekly_report', $currentReplay);
        } catch (\Throwable $e) {
            return [
                'status' => 'unavailable',
                'generated_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'field_matches' => [],
                'job' => null,
                'latest_scheduled_run' => null,
                'warning' => class_basename($e).' '.$e->getMessage(),
            ];
        }
    }

    private function mapAwoReplayStatus(string $status): string
    {
        return match ($status) {
            'observe_ok' => 'healthy',
            'insufficient_data' => 'watch',
            'observe_warning' => 'degraded',
            default => 'degraded',
        };
    }

    private function readAwoRecordingEnabled(): bool
    {
        try {
            return filter_var(app(SystemConfigService::class)->get('awo.recording_enabled', false), FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable) {
            return false;
        }
    }

    private function collectDbaTelemetry(Carbon $sampledAt): array
    {
        try {
            $payload = Cache::remember(
                self::DBA_TELEMETRY_CACHE_KEY,
                now()->addMinutes(15),
                fn (): array => ($this->dbaTelemetry ?? app(DbaTelemetryReportService::class))->collect(
                    weekly: false,
                    dryRun: false,
                    deep: false
                )
            );
            $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
            $mysql = is_array($sections['mysql_storage'] ?? null) ? $sections['mysql_storage'] : [];
            $arc = is_array($sections['arc_growth']['summary'] ?? null) ? $sections['arc_growth']['summary'] : [];
            $postgres = is_array($sections['postgres_storage'] ?? null) ? $sections['postgres_storage'] : [];
            $redis = is_array($sections['redis_health'] ?? null) ? $sections['redis_health'] : [];
            $breaches = array_values(array_filter((array) ($payload['threshold_breaches'] ?? []), 'is_array'));
            $recommendations = array_values(array_filter((array) ($payload['recommendations'] ?? []), 'is_string'));
            $arcRetention = $this->collectArcRetentionDryRun();
            $arcOldestEligible = is_array($arcRetention['oldest_eligible'] ?? null) ? $arcRetention['oldest_eligible'] : null;
            $arcSafety = is_array($arcRetention['safety'] ?? null) ? $arcRetention['safety'] : [];

            $counts = [
                'deep' => (bool) ($payload['deep'] ?? false),
                'threshold_breaches' => count($breaches),
                'recommendations' => count($recommendations),
                'breach_ids' => array_values(array_filter(array_map(
                    fn (array $breach): ?string => isset($breach['id']) ? (string) $breach['id'] : null,
                    $breaches
                ))),
                'mysql_top20_total_gb' => (float) ($mysql['schema_top20_total_gb'] ?? 0.0),
                'arc_rows_total_estimate' => (int) ($arc['rows_total_estimate'] ?? 0),
                'arc_total_gb' => (float) ($arc['total_gb'] ?? 0.0),
                'arc_raw_recent_scan_skipped' => (bool) ($arc['raw_recent_scan_skipped'] ?? false),
                'arc_rows_7d' => $arc['rows_7d'] ?? null,
                'arc_move_on_rate_7d' => $arc['move_on_rate_7d'] ?? null,
                'arc_retention_dry_run_status' => (string) ($arcRetention['status'] ?? 'unknown'),
                'arc_retention_execute' => (bool) ($arcRetention['execute'] ?? true),
                'arc_retention_has_eligible_rows' => $arcOldestEligible !== null,
                'arc_retention_oldest_eligible_id' => $arcOldestEligible === null ? null : (int) ($arcOldestEligible['id'] ?? 0),
                'arc_retention_oldest_eligible_at' => $this->nullableString($arcOldestEligible['created_at'] ?? null),
                'arc_retention_retention_days' => (int) ($arcRetention['retention_days'] ?? 0),
                'arc_retention_cutoff' => $this->nullableString($arcRetention['cutoff'] ?? null),
                'arc_retention_batch_size' => (int) ($arcRetention['batch_size'] ?? 0),
                'arc_retention_max_rows' => (int) ($arcRetention['max_rows'] ?? 0),
                'arc_retention_bounded_batch_delete' => (bool) ($arcSafety['bounded_batch_delete'] ?? false),
                'arc_retention_count_first' => (bool) ($arcSafety['count_first'] ?? true),
                'postgres_database_total_gb' => (float) ($postgres['database_total_gb'] ?? 0.0),
                'postgres_dead_tuple_top' => $this->maxDeadTupleCount((array) ($postgres['dead_tuple_top'] ?? [])),
                'redis_used_memory_mb' => (float) ($redis['used_memory_mb'] ?? 0.0),
                'redis_memory_ratio' => $redis['memory_ratio'] ?? null,
                'redis_fragmentation_ratio' => $redis['fragmentation_ratio'] ?? null,
                'redis_key_count' => (int) ($redis['key_count'] ?? 0),
            ];

            $status = $this->worstStatus([
                $this->mapObserveStatus((string) ($payload['status'] ?? 'observe_warning')),
                $this->mapArcRetentionStatus((string) ($arcRetention['status'] ?? 'unknown')),
            ]);
            $nextAction = $status === 'healthy' ? null : 'Review ops:dba-telemetry-report --json before DBA cleanup, partition, or retention changes.';

            if ($counts['arc_retention_has_eligible_rows']) {
                $nextAction = 'Run ops:arc-retention --json; execute one bounded cleanup chunk only when heavy jobs are idle and health is clean.';
            }

            return $this->section(
                $status,
                $sampledAt,
                ['DbaTelemetryReportService', 'AgentRecursionCallsRetentionService', 'information_schema.tables', 'agent_recursion_calls', 'pgsql_rag.pg_catalog', 'redis_info'],
                $counts,
                $nextAction,
                [
                    'captured_at' => $this->nullableString($payload['captured_at'] ?? null),
                    'window' => $this->nullableString($payload['window'] ?? null),
                    'mode' => $this->nullableString($payload['mode'] ?? null),
                    'cache_ttl_minutes' => 15,
                    'arc_retention_captured_at' => $this->nullableString($arcRetention['completed_at'] ?? $arcRetention['started_at'] ?? null),
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['DbaTelemetryReportService'], $e, 'DBA telemetry query failed.');
        }
    }

    private function collectSchedulerOptimization(Carbon $sampledAt): array
    {
        try {
            $payload = Cache::remember(
                self::SCHEDULER_OPTIMIZATION_CACHE_KEY,
                now()->addMinutes(15),
                function (): array {
                    $service = $this->schedulerOptimize ?? app(SchedulerOptimizeReportService::class);
                    $window = $service->parseWindow('24h') ?? ['minutes' => 1440, 'canonical' => '24h'];

                    return $service->buildPayload($window);
                }
            );
            $recommendations = array_values(array_filter((array) ($payload['recommendations'] ?? []), 'is_array'));
            $severityCounts = $this->countByKey($recommendations, 'severity');
            $categoryCounts = $this->countByKey($recommendations, 'category');
            $status = $recommendations === [] ? 'healthy' : 'watch';

            $counts = [
                'window' => (string) ($payload['window'] ?? '24h'),
                'job_count' => (int) ($payload['job_count'] ?? 0),
                'recommendation_count' => count($recommendations),
                'warning_recommendations' => (int) ($severityCounts['warning'] ?? 0),
                'notice_recommendations' => (int) ($severityCounts['notice'] ?? 0),
                'info_recommendations' => (int) ($severityCounts['info'] ?? 0),
                'metadata_recommendations' => (int) ($categoryCounts['metadata'] ?? 0),
                'reliability_recommendations' => (int) ($categoryCounts['reliability'] ?? 0),
                'timeout_recommendations' => (int) ($categoryCounts['timeout'] ?? 0),
                'spacing_recommendations' => (int) ($categoryCounts['spacing'] ?? 0),
                'top_recommendation_ids' => array_values(array_filter(array_map(
                    fn (array $recommendation): ?string => $this->nullableString($recommendation['id'] ?? null),
                    array_slice($recommendations, 0, 5)
                ))),
            ];

            return $this->section(
                $status,
                $sampledAt,
                ['SchedulerOptimizeReportService', 'scheduled_jobs', 'scheduled_job_runs'],
                $counts,
                $status === 'healthy' ? null : 'Review scheduler:optimize-report --json before schedule, timeout, queue, or batch-size changes.',
                [
                    'captured_at' => $this->nullableString($payload['captured_at'] ?? null),
                    'cache_ttl_minutes' => 15,
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['SchedulerOptimizeReportService'], $e, 'Scheduler optimization evidence query failed.');
        }
    }

    private function collectArcRetentionDryRun(): array
    {
        try {
            return Cache::remember(
                self::ARC_RETENTION_CACHE_KEY,
                now()->addMinutes(15),
                fn (): array => ($this->arcRetention ?? app(AgentRecursionCallsRetentionService::class))->collect(
                    execute: false,
                    retentionDays: null,
                    batchSize: 10_000,
                    maxRows: 50_000,
                    sleepMs: 100,
                )
            );
        } catch (\Throwable $e) {
            return [
                'status' => 'unknown',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function mapArcRetentionStatus(string $status): string
    {
        return match ($status) {
            'observe_ok', 'cleanup_complete' => 'healthy',
            'review_required' => 'watch',
            'cleanup_incomplete' => 'degraded',
            default => 'degraded',
        };
    }

    private function mapObserveStatus(string $status): string
    {
        return match ($status) {
            'observe_ok' => 'healthy',
            'observe_warning' => 'watch',
            'review_required' => 'degraded',
            default => 'degraded',
        };
    }

    private function maxDeadTupleCount(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $max = max($max, (int) ($row['dead_tuples'] ?? 0));
            }
        }

        return $max;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countByKey(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = $this->nullableString($row[$key] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function reviewBacklogAgeSummary(array $rows): array
    {
        $summary = [];

        foreach (array_slice($rows, 0, 5) as $row) {
            $bucket = $this->nullableString($row['bucket'] ?? null) ?? 'unknown';
            $summary[$bucket] = (int) ($row['pending'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function reviewBacklogTypeSummary(array $rows): array
    {
        $summary = [];

        foreach (array_slice($rows, 0, 5) as $row) {
            $reviewType = $this->nullableString($row['review_type'] ?? null) ?? 'unknown';
            $findingType = $this->nullableString($row['finding_type'] ?? null);
            $key = $findingType === null ? $reviewType : $reviewType.'/'.$findingType;
            $summary[$key] = (int) ($row['pending'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function reviewBacklogAgentSummary(array $rows): array
    {
        $summary = [];

        foreach (array_slice($rows, 0, 5) as $row) {
            $agentId = $this->nullableString($row['agent_id'] ?? null) ?? 'unknown';
            $summary[$agentId] = (int) ($row['pending'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function reviewBacklogStatusSummary(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $status = $this->nullableString($row['status'] ?? null) ?? 'unknown';
            $summary[$status] = (int) ($row['rows'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function reviewBacklogTriageSummary(array $rows): array
    {
        $summary = [];

        foreach (array_slice($rows, 0, 5) as $row) {
            $category = $this->nullableString($row['category'] ?? null) ?? 'unknown';
            $summary[$category] = (int) ($row['pending'] ?? 0);
        }

        return $summary;
    }

    /**
     * @return array<string,int>
     */
    private function integerCountMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $summary = [];
        foreach ($value as $key => $count) {
            $name = $this->nullableString($key);
            if ($name === null) {
                continue;
            }

            $summary[$name] = (int) $count;
        }

        ksort($summary);

        return $summary;
    }

    /**
     * @return array<string,int>
     */
    private function possibleChangeTypeTypoCounts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $summary = [];
        foreach ($value as $changeType => $typo) {
            $name = $this->nullableString($changeType);
            if ($name === null) {
                continue;
            }

            $summary[$name] = is_array($typo)
                ? (int) ($typo['rows'] ?? 0)
                : (int) $typo;
        }

        ksort($summary);

        return $summary;
    }

    /**
     * @return array<string,string>
     */
    private function possibleChangeTypeTypoSuggestions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $summary = [];
        foreach ($value as $changeType => $typo) {
            if (! is_array($typo)) {
                continue;
            }

            $name = $this->nullableString($changeType);
            $suggestion = $this->nullableString($typo['suggested_change_type'] ?? null);
            if ($name === null || $suggestion === null) {
                continue;
            }

            $summary[$name] = $suggestion;
        }

        ksort($summary);

        return $summary;
    }

    private function collectQueueHealth(Carbon $sampledAt): array
    {
        $missing = $this->missingTables(['scheduled_jobs']);
        if ($missing !== []) {
            return $this->section(
                'blocked',
                $sampledAt,
                ['scheduled_jobs', 'jobs', 'failed_jobs', 'scheduled_job_runs'],
                ['missing_tables' => $missing],
                'Restore scheduler evidence tables before evaluating queue health.'
            );
        }
        $missingSupport = $this->missingTables(['jobs', 'failed_jobs', 'scheduled_job_runs']);

        try {
            $row = DB::selectOne(
                "SELECT
                    SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled_jobs,
                    SUM(CASE WHEN enabled = 1
                               AND stall_exempt = 0
                               AND COALESCE(job_type, '') <> 'agent_task'
                               AND last_run_status = 'running'
                               AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes, 30) MINUTE)
                             THEN 1 ELSE 0 END) AS stale_running,
                    SUM(CASE WHEN enabled = 1
                               AND last_run_status IN ('failed', 'timeout')
                               AND last_run_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                             THEN 1 ELSE 0 END) AS recent_scheduler_failures,
                    SUM(CASE WHEN enabled = 1
                               AND next_run_at IS NOT NULL
                               AND next_run_at < NOW()
                             THEN 1 ELSE 0 END) AS due_jobs_overdue,
                    COALESCE(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN enabled = 1 THEN last_run_at END), NOW()), 0) AS scheduler_lag_minutes,
                    MAX(CASE WHEN enabled = 1 THEN last_run_at END) AS latest_scheduler_run_at
                 FROM scheduled_jobs"
            );

            $queueDepth = $this->collectQueueDepth();
            $recentQueueFailures = $this->countRecentFailedJobs();
            $recentQueueFailures30m = $this->countRecentFailedJobs(30, 'MINUTE');
            $completionLag = $this->collectCompletionLagMinutes();

            $counts = [
                'queue_depth_total' => $queueDepth['total'],
                'queue_depths' => $queueDepth['queues'],
                'enabled_scheduled_jobs' => (int) ($row->enabled_jobs ?? 0),
                'stale_running_jobs' => (int) ($row->stale_running ?? 0),
                'recent_scheduler_failures' => (int) ($row->recent_scheduler_failures ?? 0),
                'recent_queue_failures' => $recentQueueFailures,
                'recent_failed_jobs_30m' => $recentQueueFailures30m,
                'due_jobs_overdue' => (int) ($row->due_jobs_overdue ?? 0),
                'missing_support_tables' => $missingSupport,
            ];

            $freshness = [
                'latest_scheduler_run_at' => $this->nullableString($row->latest_scheduler_run_at ?? null),
                'scheduler_lag_minutes' => (int) ($row->scheduler_lag_minutes ?? 0),
                'completion_lag_minutes' => $completionLag,
                'queue_source' => $queueDepth['source'],
            ];

            $status = 'healthy';
            $nextAction = null;
            if ($queueDepth['error'] !== null) {
                $status = 'degraded';
                $nextAction = 'Check Laravel queue storage; queue depth could not be sampled.';
            }
            if ($missingSupport !== []) {
                $status = $this->maxStatus($status, 'degraded');
                $nextAction ??= 'Restore queue failure/completion evidence tables before trusting a healthy queue snapshot.';
            }
            if ($counts['stale_running_jobs'] > 0) {
                $status = 'blocked';
                $nextAction = 'Review stale scheduled jobs before trusting downstream evidence.';
            } elseif ($counts['recent_scheduler_failures'] > 0 || ($recentQueueFailures ?? 0) > 0 || ($recentQueueFailures30m ?? 0) > 0) {
                $status = $this->maxStatus($status, 'degraded');
                $nextAction ??= 'Inspect recent failed scheduler or queue jobs.';
            } elseif ($counts['queue_depth_total'] > 250 || $freshness['scheduler_lag_minutes'] > 60) {
                $status = $this->maxStatus($status, 'degraded');
                $nextAction ??= 'Drain queues and verify scheduler execution.';
            } elseif ($counts['queue_depth_total'] > 20 || $counts['due_jobs_overdue'] > 0 || ($completionLag ?? 0) > 30) {
                $status = $this->maxStatus($status, 'watch');
                $nextAction ??= 'Monitor queue drain and scheduler flow.';
            } elseif ($completionLag === null) {
                $status = $this->maxStatus($status, 'watch');
                $nextAction ??= 'Wait for or restore successful scheduler completion evidence.';
            }

            return $this->section($status, $sampledAt, ['scheduled_jobs', 'jobs', 'failed_jobs', 'scheduled_job_runs'], $counts, $nextAction, $freshness);
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['scheduled_jobs'], $e, 'Queue health query failed.');
        }
    }

    private function collectKgBacklog(Carbon $sampledAt, array $ragMetrics, array $ragNetBurn): array
    {
        if (($ragMetrics['result'] ?? null) !== 'ok') {
            return $this->section('blocked', $sampledAt, ['RagBacklogService', 'pgsql_rag.rag_documents'], [
                'error' => $ragMetrics['error'] ?? 'rag_digest_unavailable',
                'evidence_errors' => $ragMetrics['evidence_errors'] ?? [],
            ], 'Restore RAG backlog evidence before evaluating KG state.');
        }

        $metrics = $ragMetrics['metrics'];
        $kg = $metrics['kg'] ?? [];
        $pending = (int) ($kg['pending'] ?? 0);
        $throughput = (int) ($kg['throughput_per_day'] ?? 0);
        $fresh = (int) ($kg['fresh'] ?? 0);
        $stale = (int) ($kg['stale'] ?? 0);

        $counts = [
            'documents' => (int) ($metrics['documents'] ?? 0),
            'kg_pending' => $pending,
            'kg_fresh_pending' => $fresh,
            'kg_stale_pending' => $stale,
            'kg_entities' => (int) ($kg['entities'] ?? 0),
            'throughput_per_day' => $throughput,
            'eta_days' => $kg['eta_days'] ?? null,
        ];
        $this->appendNetBurnCounts($counts, 'kg', $ragNetBurn['lanes']['kg'] ?? null, $ragNetBurn);

        $status = match (true) {
            $pending === 0 => 'healthy',
            $throughput <= 0 || $pending > 1000 => 'degraded',
            default => 'watch',
        };

        return $this->section(
            $status,
            $sampledAt,
            ['RagBacklogService', 'pgsql_rag.rag_documents', 'pgsql_rag.knowledge_graph_entities'],
            $counts,
            $pending > 0 ? 'Run or inspect the KG catch-up/build lane.' : null
        );
    }

    private function collectRaptorSentenceDrained(Carbon $sampledAt, array $ragMetrics, array $ragNetBurn): array
    {
        if (($ragMetrics['result'] ?? null) !== 'ok') {
            return $this->section('blocked', $sampledAt, ['RagBacklogService', 'pgsql_rag.rag_documents'], [
                'error' => $ragMetrics['error'] ?? 'rag_digest_unavailable',
                'evidence_errors' => $ragMetrics['evidence_errors'] ?? [],
            ], 'Restore RAG backlog evidence before evaluating drained state.');
        }

        $metrics = $ragMetrics['metrics'];
        $raptor = $metrics['raptor'] ?? [];
        $sentence = $metrics['sentence'] ?? [];
        $raptorPending = (int) ($raptor['pending'] ?? 0);
        $sentencePending = (int) ($sentence['pending'] ?? 0);

        $counts = [
            'raptor_pending' => $raptorPending,
            'raptor_throughput_per_day' => (int) ($raptor['throughput_per_day'] ?? 0),
            'raptor_eta_days' => $raptor['eta_days'] ?? null,
            'sentence_pending' => $sentencePending,
            'sentence_throughput_per_day' => (int) ($sentence['throughput_per_day'] ?? 0),
            'sentence_eta_days' => $sentence['eta_days'] ?? null,
            'drained' => $raptorPending === 0 && $sentencePending === 0,
        ];
        $this->appendNetBurnCounts($counts, 'raptor', $ragNetBurn['lanes']['raptor'] ?? null, $ragNetBurn);
        $this->appendNetBurnCounts($counts, 'sentence', $ragNetBurn['lanes']['sentence'] ?? null, $ragNetBurn);

        $status = 'healthy';
        if (! $counts['drained']) {
            $status = ($counts['raptor_throughput_per_day'] <= 0 && $raptorPending > 0)
                || ($counts['sentence_throughput_per_day'] <= 0 && $sentencePending > 0)
                    ? 'degraded'
                    : 'watch';
        }

        return $this->section(
            $status,
            $sampledAt,
            ['RagBacklogService', 'RaptorBuildCommand', 'SentenceEmbeddingsBuildCommand'],
            $counts,
            $counts['drained'] ? null : 'Run or inspect RAPTOR and sentence indexing lanes.'
        );
    }

    private function collectGenealogyPendingApprovals(Carbon $sampledAt): array
    {
        $missing = $this->missingTables(['genealogy_proposed_changes', 'genealogy_proposed_relationships']);
        if ($missing !== []) {
            return $this->section('blocked', $sampledAt, ['genealogy_proposed_changes', 'genealogy_proposed_relationships'], [
                'missing_tables' => $missing,
            ], 'Restore genealogy proposal tables before evaluating approvals.');
        }

        try {
            $changes = $this->proposalSummary('genealogy_proposed_changes');
            $relationships = $this->proposalSummary('genealogy_proposed_relationships');

            $pendingTotal = $changes['pending'] + $relationships['pending'];
            $evidenceGaps = $changes['evidence_gaps'] + $relationships['evidence_gaps'];
            $oldestAgeHours = $this->maxNullable($changes['oldest_pending_age_hours'], $relationships['oldest_pending_age_hours']);

            $counts = [
                'pending_total' => $pendingTotal,
                'pending_person_changes' => $changes['pending'],
                'pending_relationships' => $relationships['pending'],
                'evidence_gap_count' => $evidenceGaps,
                'oldest_pending_age_hours' => $oldestAgeHours,
            ];

            $status = match (true) {
                $pendingTotal === 0 => 'healthy',
                $evidenceGaps > 0 || ($oldestAgeHours ?? 0) > 336 => 'degraded',
                default => 'watch',
            };

            return $this->section(
                $status,
                $sampledAt,
                ['genealogy_proposed_changes', 'genealogy_proposed_relationships', 'GenealogyProposalReviewQueueService'],
                $counts,
                $pendingTotal > 0 ? 'Review pending genealogy proposals and evidence gaps.' : null
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['genealogy_proposed_changes', 'genealogy_proposed_relationships'], $e, 'Genealogy approval query failed.');
        }
    }

    private function collectGenealogyReviewFeedback(Carbon $sampledAt): array
    {
        $missing = $this->missingTables(['agent_review_queue']);
        if ($missing !== []) {
            return $this->section('blocked', $sampledAt, ['AgentProceduralMemoryService', 'agent_review_queue'], [
                'missing_tables' => $missing,
            ], 'Restore agent review evidence before evaluating genealogy review feedback.');
        }

        try {
            $windowDays = 7;
            $rollup = $this->proceduralMemory->getReviewerFeedbackDailyRollup($windowDays);

            $agents = [];
            $rejectHistogram = [];
            $totalReviews = 0;
            $acceptedProposals = 0;
            $rejectedProposals = 0;
            $latestReviewedAt = null;

            foreach ($rollup as $row) {
                $agentId = trim((string) ($row['agent_id'] ?? ''));
                if ($agentId !== '') {
                    $agents[$agentId] = true;
                }

                $totalReviews += (int) ($row['total_reviews'] ?? 0);
                $acceptedProposals += (int) ($row['accepted_proposals'] ?? 0);
                $rejectedProposals += (int) ($row['rejected_proposals'] ?? 0);

                $reviewedAt = $this->nullableString($row['latest_reviewed_at'] ?? null);
                if ($reviewedAt !== null && ($latestReviewedAt === null || strcmp($reviewedAt, $latestReviewedAt) > 0)) {
                    $latestReviewedAt = $reviewedAt;
                }

                $histogram = is_array($row['reject_reason_histogram'] ?? null)
                    ? $row['reject_reason_histogram']
                    : [];
                foreach ($histogram as $code => $count) {
                    $code = trim((string) $code) !== '' ? (string) $code : 'other';
                    $rejectHistogram[$code] = ($rejectHistogram[$code] ?? 0) + (int) $count;
                }
            }

            arsort($rejectHistogram);
            $decisionTotal = $acceptedProposals + $rejectedProposals;

            $counts = [
                'window_days' => $windowDays,
                'rollup_rows' => count($rollup),
                'agents' => count($agents),
                'total_reviews' => $totalReviews,
                'accepted_proposals' => $acceptedProposals,
                'rejected_proposals' => $rejectedProposals,
                'acceptance_rate' => $decisionTotal > 0 ? round($acceptedProposals / $decisionTotal, 4) : null,
                'top_reject_codes' => array_slice($rejectHistogram, 0, 5, true),
                'latest_reviewed_at' => $latestReviewedAt,
            ];

            return $this->section(
                'healthy',
                $sampledAt,
                ['AgentProceduralMemoryService', 'agent_review_queue'],
                $counts,
                $rejectedProposals > 0 ? 'Review top genealogy reject codes before expanding review-packet autonomy.' : null
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['AgentProceduralMemoryService', 'agent_review_queue'], $e, 'Genealogy review feedback rollup failed.');
        }
    }

    private function collectGenealogyEvidenceSprint(Carbon $sampledAt): array
    {
        $missing = $this->missingTables(['agent_review_queue']);
        if ($missing !== []) {
            return $this->section('blocked', $sampledAt, ['GenealogyEvidenceSprintReadinessService', 'agent_review_queue'], [
                'missing_tables' => $missing,
            ], 'Restore agent review evidence before evaluating the genealogy evidence sprint.');
        }

        try {
            $payload = Cache::remember(
                self::GENEALOGY_EVIDENCE_SPRINT_CACHE_KEY,
                now()->addMinutes(15),
                fn (): array => ($this->genealogyEvidenceSprint ?? app(GenealogyEvidenceSprintReadinessService::class))->collect(30, 500)
            );
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $readiness = is_array($payload['readiness'] ?? null) ? $payload['readiness'] : [];
            $recommendations = array_values(array_filter((array) ($payload['recommendations'] ?? []), 'is_string'));
            $errors = array_values(array_filter((array) ($payload['evidence_errors'] ?? []), 'is_string'));
            $sourceStatus = (string) ($payload['status'] ?? 'needs_source_backed_packets');

            $counts = [
                'source_status' => $sourceStatus,
                'window_days' => (int) ($payload['window_days'] ?? 30),
                'target_packets' => (int) ($payload['target_packets'] ?? 5),
                'packet_rows_total' => (int) ($summary['packet_rows_total'] ?? 0),
                'packet_rows_window' => (int) ($summary['packet_rows_window'] ?? 0),
                'source_backed_packets' => (int) ($summary['source_backed_packets'] ?? 0),
                'source_backed_pending' => (int) ($summary['source_backed_pending'] ?? 0),
                'source_backed_decided' => (int) ($summary['source_backed_decided'] ?? 0),
                'reviewable_pending_packets' => (int) ($summary['reviewable_pending_packets'] ?? 0),
                'source_backed_pending_not_packet_pending' => (int) ($summary['source_backed_pending_not_packet_pending'] ?? 0),
                'source_backed_pending_missing_preview_only' => (int) ($summary['source_backed_pending_missing_preview_only'] ?? 0),
                'source_backed_pending_missing_identity' => (int) ($summary['source_backed_pending_missing_identity'] ?? 0),
                'source_backed_pending_missing_privacy_clearance' => (int) ($summary['source_backed_pending_missing_privacy_clearance'] ?? 0),
                'source_backed_pending_missing_claims' => (int) ($summary['source_backed_pending_missing_claims'] ?? 0),
                'source_backed_pending_missing_validation' => (int) ($summary['source_backed_pending_missing_validation'] ?? 0),
                'source_backed_pending_missing_boundary' => (int) ($summary['source_backed_pending_missing_boundary'] ?? 0),
                'source_locator_required_packets' => (int) ($summary['source_locator_required_packets'] ?? 0),
                'manual_only_source_packets' => (int) ($summary['manual_only_source_packets'] ?? 0),
                'source_realism_blocked_packets' => (int) ($summary['source_realism_blocked_packets'] ?? 0),
                'pending_packets' => (int) ($summary['pending_packets'] ?? 0),
                'reviewed_preview_only' => (int) ($summary['reviewed_preview_only'] ?? 0),
                'deferred_packets' => (int) ($summary['deferred_packets'] ?? 0),
                'clarification_requested' => (int) ($summary['clarification_requested'] ?? 0),
                'rejected_packets' => (int) ($summary['rejected_packets'] ?? 0),
                'preview_only_packets' => (int) ($summary['preview_only_packets'] ?? 0),
                'mutating_preview_packets' => (int) ($summary['mutating_preview_packets'] ?? 0),
                'packets_with_identity' => (int) ($summary['packets_with_identity'] ?? 0),
                'packets_with_privacy_clearance' => (int) ($summary['packets_with_privacy_clearance'] ?? 0),
                'packets_with_claims' => (int) ($summary['packets_with_claims'] ?? 0),
                'packets_with_decision_log' => (int) ($summary['packets_with_decision_log'] ?? 0),
                'operator_boundary_packets' => (int) ($summary['operator_boundary_packets'] ?? 0),
                'packets_missing_boundary' => (int) ($summary['packets_missing_boundary'] ?? 0),
                'boundary_label_count' => (int) ($summary['boundary_label_count'] ?? 0),
                'boundary_mismatch_packets' => (int) ($summary['boundary_mismatch_packets'] ?? 0),
                'malformed_details' => (int) ($summary['malformed_details'] ?? 0),
                'remaining_to_target' => (int) ($readiness['remaining_to_target'] ?? 5),
                'remaining_reviewable_to_target' => (int) ($readiness['remaining_reviewable_to_target'] ?? 5),
                'needs_operator_boundary' => (bool) ($readiness['needs_operator_boundary'] ?? true),
                'needs_reviewable_packet_details' => (bool) ($readiness['needs_reviewable_packet_details'] ?? false),
                'boundary_consistent' => (bool) ($readiness['boundary_consistent'] ?? false),
                'mutation_guard_ok' => (bool) ($readiness['mutation_guard_ok'] ?? false),
                'ready_for_five_packet_review' => (bool) ($readiness['ready_for_five_packet_review'] ?? false),
                'top_reason_codes' => array_slice((array) ($payload['top_reason_codes'] ?? []), 0, 5, true),
                'recommendations' => count($recommendations),
                'evidence_errors' => count($errors),
                'truncated' => (bool) ($payload['truncated'] ?? false),
            ];

            $status = match ($sourceStatus) {
                'ready_for_review' => 'healthy',
                'blocked' => 'blocked',
                'in_progress', 'ready_for_operator_boundary', 'needs_source_backed_packets' => 'watch',
                default => 'degraded',
            };

            if ($counts['evidence_errors'] > 0 || ! $counts['mutation_guard_ok']) {
                $status = $this->maxStatus($status, 'blocked');
            }

            return $this->section(
                $status,
                $sampledAt,
                ['GenealogyEvidenceSprintReadinessService', 'agent_review_queue'],
                $counts,
                $status === 'healthy'
                    ? null
                    : 'Run genealogy:evidence-sprint-report --json before materializing or reviewing the five-packet sprint.',
                [
                    'generated_at' => $this->nullableString($payload['generated_at'] ?? null),
                    'cache_ttl_minutes' => 15,
                ]
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['GenealogyEvidenceSprintReadinessService', 'agent_review_queue'], $e, 'Genealogy evidence sprint readiness query failed.');
        }
    }

    private function collectFaceMatchLinkBacklog(Carbon $sampledAt): array
    {
        $missing = $this->missingTables(['genealogy_face_match_queue', 'file_registry_faces', 'genealogy_person_media']);
        if ($missing !== []) {
            return $this->section('blocked', $sampledAt, ['genealogy_face_match_queue', 'file_registry_faces', 'genealogy_person_media'], [
                'missing_tables' => $missing,
            ], 'Restore face/link evidence tables before evaluating backlog.');
        }

        try {
            $queue = DB::selectOne(
                "SELECT
                    COUNT(*) AS pending_total,
                    SUM(CASE WHEN match_type = 'no_match' THEN 1 ELSE 0 END) AS no_match_pending,
                    SUM(CASE WHEN match_type = 'no_match' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS stale_no_match_pending,
                    SUM(CASE WHEN match_type NOT IN ('exact', 'no_match') THEN 1 ELSE 0 END) AS fuzzy_pending,
                    SUM(CASE WHEN file_registry_face_id IS NOT NULL THEN 1 ELSE 0 END) AS bridge_eligible_pending,
                    SUM(CASE WHEN created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS stale_pending,
                    MAX(TIMESTAMPDIFF(HOUR, created_at, NOW())) AS oldest_pending_age_hours
                 FROM genealogy_face_match_queue
                 WHERE status = 'pending'"
            );
            $faces = DB::selectOne(
                "SELECT
                    COUNT(*) AS total_faces,
                    SUM(CASE WHEN f.hidden = 0 THEN 1 ELSE 0 END) AS visible_faces,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_faces,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL THEN 1 ELSE 0 END) AS unlinked_faces,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL THEN 1 ELSE 0 END) AS named_only_unlinked,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 THEN 1 ELSE 0 END) AS open_named_only_unlinked,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 AND f.updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS stale_open_named_only_unlinked,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 1 THEN 1 ELSE 0 END) AS terminal_decided_named_only_unlinked,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND candidate_decisions.file_registry_face_id IS NULL THEN 1 ELSE 0 END) AS named_only_open_without_candidate_decision,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND candidate_decisions.file_registry_face_id IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 THEN 1 ELSE 0 END) AS named_only_open_with_nonterminal_decision,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(queue_counts.pending_no_match_count, 0) > 0 THEN 1 ELSE 0 END) AS named_only_pending_no_match_faces,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(queue_counts.stale_pending_no_match_count, 0) > 0 THEN 1 ELSE 0 END) AS named_only_stale_pending_no_match_faces,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 AND TIMESTAMPDIFF(HOUR, f.updated_at, NOW()) < 24 THEN 1 ELSE 0 END) AS named_only_open_under_24h,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 AND TIMESTAMPDIFF(HOUR, f.updated_at, NOW()) >= 24 AND TIMESTAMPDIFF(HOUR, f.updated_at, NOW()) < 168 THEN 1 ELSE 0 END) AS named_only_open_one_to_seven_days,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 AND TIMESTAMPDIFF(HOUR, f.updated_at, NOW()) >= 168 AND TIMESTAMPDIFF(HOUR, f.updated_at, NOW()) < 720 THEN 1 ELSE 0 END) AS named_only_open_seven_to_thirty_days,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 AND TIMESTAMPDIFF(HOUR, f.updated_at, NOW()) >= 720 THEN 1 ELSE 0 END) AS named_only_open_over_thirty_days,
                    SUM(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL AND f.verified = 1 THEN 1 ELSE 0 END) AS named_only_verified,
                    MAX(CASE WHEN f.hidden = 0 AND f.genealogy_person_id IS NULL AND NULLIF(TRIM(f.person_name), '') IS NOT NULL THEN TIMESTAMPDIFF(HOUR, f.updated_at, NOW()) END) AS named_only_oldest_age_hours
                 FROM file_registry_faces f
                 ".$this->latestFaceCandidateDecisionJoinSql('f')."
                 LEFT JOIN (
                    SELECT
                        file_registry_face_id,
                        SUM(CASE WHEN status = 'pending' AND match_type = 'no_match' THEN 1 ELSE 0 END) AS pending_no_match_count,
                        SUM(CASE WHEN status = 'pending' AND match_type = 'no_match' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS stale_pending_no_match_count
                    FROM genealogy_face_match_queue
                    WHERE file_registry_face_id IS NOT NULL
                    GROUP BY file_registry_face_id
                 ) queue_counts ON queue_counts.file_registry_face_id = f.id"
            );
            $bridge = DB::selectOne(
                "SELECT COUNT(*) AS approved_missing_person_media
                 FROM genealogy_face_match_queue q
                 LEFT JOIN genealogy_person_media pm
                   ON pm.person_id = q.suggested_person_id
                  AND pm.media_id = q.media_id
                 WHERE q.status IN ('approved', 'auto_linked')
                   AND q.file_registry_face_id IS NOT NULL
                   AND q.suggested_person_id IS NOT NULL
                   AND q.media_id IS NOT NULL
                   AND pm.id IS NULL"
            );
            $decisions = DB::selectOne(
                "SELECT
                    COUNT(*) AS candidate_decision_rows,
                    COUNT(DISTINCT file_registry_face_id) AS candidate_decided_faces,
                    SUM(CASE WHEN action = 'keep_name_only' THEN 1 ELSE 0 END) AS candidate_keep_name_only,
                    SUM(CASE WHEN action = 'outside_tree' THEN 1 ELSE 0 END) AS candidate_outside_tree,
                    SUM(CASE WHEN action = 'too_vague' THEN 1 ELSE 0 END) AS candidate_too_vague,
                    SUM(CASE WHEN action = 'not_this_person' THEN 1 ELSE 0 END) AS candidate_not_this_person,
                    SUM(CASE WHEN action = 'defer' THEN 1 ELSE 0 END) AS candidate_deferred,
                    SUM(CASE WHEN terminal = 'true' THEN 1 ELSE 0 END) AS candidate_terminal_decisions,
                    SUM(CASE WHEN decided_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS candidate_recent_decisions,
                    DATE_FORMAT(MAX(decided_at), '%Y-%m-%dT%H:%i:%sZ') AS candidate_latest_decision_at
                 FROM (
                    SELECT
                        file_registry_face_id,
                        JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.latest_candidate_decision.action')) AS action,
                        JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.latest_candidate_decision.terminal')) AS terminal,
                        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.latest_candidate_decision.decided_at')), '%Y-%m-%dT%H:%i:%sZ') AS decided_at
                    FROM genealogy_face_match_queue
                 ) decisions
                 WHERE action IN ('keep_name_only', 'outside_tree', 'too_vague', 'not_this_person', 'defer')"
            );
            $weeklyReport = $this->collectFaceWeeklyReportProof();

            $counts = [
                'pending_total' => (int) ($queue->pending_total ?? 0),
                'no_match_pending' => (int) ($queue->no_match_pending ?? 0),
                'stale_no_match_pending' => (int) ($queue->stale_no_match_pending ?? 0),
                'fuzzy_pending' => (int) ($queue->fuzzy_pending ?? 0),
                'bridge_eligible_pending' => (int) ($queue->bridge_eligible_pending ?? 0),
                'stale_pending' => (int) ($queue->stale_pending ?? 0),
                'oldest_pending_age_hours' => isset($queue->oldest_pending_age_hours) ? (int) $queue->oldest_pending_age_hours : null,
                'visible_faces' => (int) ($faces->visible_faces ?? 0),
                'linked_faces' => (int) ($faces->linked_faces ?? 0),
                'unlinked_faces' => (int) ($faces->unlinked_faces ?? 0),
                'named_only_unlinked' => (int) ($faces->named_only_unlinked ?? 0),
                'open_named_only_unlinked' => (int) ($faces->open_named_only_unlinked ?? 0),
                'stale_open_named_only_unlinked' => (int) ($faces->stale_open_named_only_unlinked ?? 0),
                'terminal_decided_named_only_unlinked' => (int) ($faces->terminal_decided_named_only_unlinked ?? 0),
                'named_only_open_without_candidate_decision' => (int) ($faces->named_only_open_without_candidate_decision ?? 0),
                'named_only_open_with_nonterminal_decision' => (int) ($faces->named_only_open_with_nonterminal_decision ?? 0),
                'named_only_pending_no_match_faces' => (int) ($faces->named_only_pending_no_match_faces ?? 0),
                'named_only_stale_pending_no_match_faces' => (int) ($faces->named_only_stale_pending_no_match_faces ?? 0),
                'named_only_open_under_24h' => (int) ($faces->named_only_open_under_24h ?? 0),
                'named_only_open_one_to_seven_days' => (int) ($faces->named_only_open_one_to_seven_days ?? 0),
                'named_only_open_seven_to_thirty_days' => (int) ($faces->named_only_open_seven_to_thirty_days ?? 0),
                'named_only_open_over_thirty_days' => (int) ($faces->named_only_open_over_thirty_days ?? 0),
                'named_only_verified' => (int) ($faces->named_only_verified ?? 0),
                'named_only_oldest_age_hours' => isset($faces->named_only_oldest_age_hours) ? (int) $faces->named_only_oldest_age_hours : null,
                'approved_missing_person_media' => (int) ($bridge->approved_missing_person_media ?? 0),
                'candidate_decision_rows' => (int) ($decisions->candidate_decision_rows ?? 0),
                'candidate_decided_faces' => (int) ($decisions->candidate_decided_faces ?? 0),
                'candidate_keep_name_only' => (int) ($decisions->candidate_keep_name_only ?? 0),
                'candidate_outside_tree' => (int) ($decisions->candidate_outside_tree ?? 0),
                'candidate_too_vague' => (int) ($decisions->candidate_too_vague ?? 0),
                'candidate_not_this_person' => (int) ($decisions->candidate_not_this_person ?? 0),
                'candidate_deferred' => (int) ($decisions->candidate_deferred ?? 0),
                'candidate_terminal_decisions' => (int) ($decisions->candidate_terminal_decisions ?? 0),
                'candidate_recent_decisions' => (int) ($decisions->candidate_recent_decisions ?? 0),
                'candidate_latest_decision_at' => $decisions->candidate_latest_decision_at ?? null,
                'weekly_report_status' => (string) ($weeklyReport['status'] ?? 'missing'),
                'weekly_report_enabled' => (bool) ($weeklyReport['enabled'] ?? false),
                'weekly_report_latest_run_status' => $this->nullableString($weeklyReport['latest_run_status'] ?? null),
                'weekly_report_latest_success_completed_at' => $this->nullableString($weeklyReport['latest_success_completed_at'] ?? null),
                'weekly_report_latest_success_age_hours' => $weeklyReport['latest_success_age_hours'] ?? null,
                'weekly_report_next_run_at' => $this->nullableString($weeklyReport['next_run_at'] ?? null),
                'weekly_report_has_bridge_alignment' => (bool) ($weeklyReport['has_bridge_alignment'] ?? false),
                'weekly_report_has_candidate_decisions' => (bool) ($weeklyReport['has_candidate_decisions'] ?? false),
            ];

            $status = match (true) {
                $counts['stale_pending'] > 0 || $counts['approved_missing_person_media'] > 0 => 'degraded',
                $counts['pending_total'] > 0 || $counts['unlinked_faces'] > 0 => 'watch',
                default => 'healthy',
            };
            if ($counts['weekly_report_status'] !== 'success') {
                $status = $this->maxStatus($status, 'watch');
            }

            return $this->section(
                $status,
                $sampledAt,
                ['genealogy_face_match_queue', 'file_registry_faces', 'genealogy_person_media', 'FaceLinkBridgeService', 'scheduled_jobs', 'scheduled_job_runs'],
                $counts,
                $status === 'healthy' ? null : 'Review face match queue and bridge missing approved links.'
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['genealogy_face_match_queue', 'file_registry_faces'], $e, 'Face backlog query failed.');
        }
    }

    private function latestFaceCandidateDecisionJoinSql(string $faceAlias): string
    {
        return "
                 LEFT JOIN (
                    SELECT
                        latest.file_registry_face_id,
                        JSON_UNQUOTE(JSON_EXTRACT(latest.match_details, '$.latest_candidate_decision.terminal')) AS latest_candidate_terminal,
                        CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(latest.match_details, '$.latest_candidate_decision.terminal')) = 'true' THEN 1 ELSE 0 END AS has_terminal_candidate_decision
                    FROM genealogy_face_match_queue latest
                    WHERE latest.file_registry_face_id IS NOT NULL
                      AND JSON_UNQUOTE(JSON_EXTRACT(latest.match_details, '$.latest_candidate_decision.action')) IS NOT NULL
                      AND NOT EXISTS (
                        SELECT 1
                        FROM genealogy_face_match_queue newer
                        WHERE newer.file_registry_face_id = latest.file_registry_face_id
                          AND JSON_UNQUOTE(JSON_EXTRACT(newer.match_details, '$.latest_candidate_decision.action')) IS NOT NULL
                          AND (
                            newer.updated_at > latest.updated_at
                            OR (newer.updated_at = latest.updated_at AND newer.id > latest.id)
                          )
                      )
                 ) candidate_decisions ON candidate_decisions.file_registry_face_id = {$faceAlias}.id";
    }

    /**
     * @return array<string, mixed>
     */
    private function collectFaceWeeklyReportProof(): array
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasTable('scheduled_job_runs')) {
            return $this->missingFaceWeeklyReportProof('missing_support_table');
        }

        try {
            $row = DB::selectOne(
                "SELECT
                    j.enabled,
                    j.last_run_status AS job_last_run_status,
                    j.last_run_at AS job_last_run_at,
                    j.next_run_at,
                    latest.status AS latest_run_status,
                    latest.completed_at AS latest_completed_at,
                    success.completed_at AS latest_success_completed_at,
                    TIMESTAMPDIFF(HOUR, success.completed_at, NOW()) AS latest_success_age_hours,
                    CASE WHEN success.output LIKE '%Bridge Alignment%' THEN 1 ELSE 0 END AS has_bridge_alignment,
                    CASE WHEN success.output LIKE '%Candidate Decisions%' THEN 1 ELSE 0 END AS has_candidate_decisions
                 FROM scheduled_jobs j
                 LEFT JOIN scheduled_job_runs latest ON latest.id = (
                    SELECT r.id
                    FROM scheduled_job_runs r
                    WHERE r.scheduled_job_id = j.id
                    ORDER BY r.started_at DESC, r.id DESC
                    LIMIT 1
                 )
                 LEFT JOIN scheduled_job_runs success ON success.id = (
                    SELECT r.id
                    FROM scheduled_job_runs r
                    WHERE r.scheduled_job_id = j.id
                      AND r.status = 'success'
                    ORDER BY r.completed_at DESC, r.id DESC
                    LIMIT 1
                 )
                 WHERE j.name = ?
                 LIMIT 1",
                [self::FACE_LINK_WEEKLY_REPORT_JOB]
            );

            if ($row === null) {
                return $this->missingFaceWeeklyReportProof('missing');
            }

            $enabled = (int) ($row->enabled ?? 0) === 1;
            $latestSuccessCompletedAt = $this->nullableString($row->latest_success_completed_at ?? null);
            $hasBridgeAlignment = (int) ($row->has_bridge_alignment ?? 0) === 1;
            $hasCandidateDecisions = (int) ($row->has_candidate_decisions ?? 0) === 1;
            $latestRunStatus = $this->nullableString($row->latest_run_status ?? $row->job_last_run_status ?? null);

            $status = match (true) {
                ! $enabled => 'disabled',
                $latestSuccessCompletedAt === null && in_array((string) $latestRunStatus, ['failed', 'timeout'], true) => 'latest_failed',
                $latestSuccessCompletedAt === null => 'pending_first_success',
                ! $hasBridgeAlignment || ! $hasCandidateDecisions => 'success_missing_sections',
                default => 'success',
            };

            return [
                'status' => $status,
                'enabled' => $enabled,
                'latest_run_status' => $latestRunStatus,
                'job_last_run_at' => $this->nullableString($row->job_last_run_at ?? null),
                'latest_completed_at' => $this->nullableString($row->latest_completed_at ?? null),
                'latest_success_completed_at' => $latestSuccessCompletedAt,
                'latest_success_age_hours' => isset($row->latest_success_age_hours) ? (int) $row->latest_success_age_hours : null,
                'next_run_at' => $this->nullableString($row->next_run_at ?? null),
                'has_bridge_alignment' => $hasBridgeAlignment,
                'has_candidate_decisions' => $hasCandidateDecisions,
            ];
        } catch (\Throwable) {
            return $this->missingFaceWeeklyReportProof('unavailable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function missingFaceWeeklyReportProof(string $status): array
    {
        return [
            'status' => $status,
            'enabled' => false,
            'latest_run_status' => null,
            'job_last_run_at' => null,
            'latest_completed_at' => null,
            'latest_success_completed_at' => null,
            'latest_success_age_hours' => null,
            'next_run_at' => null,
            'has_bridge_alignment' => false,
            'has_candidate_decisions' => false,
        ];
    }

    private function collectOfflineDegradedState(Carbon $sampledAt): array
    {
        try {
            $profile = $this->offlinePolicy->activeProfile();
            $offlineModeActive = $this->offlinePolicy->isOfflineModeActive();
            $audit = $this->offlineAudit->summarizeWindow(24);
            $recentEvents = $this->offlineAudit->recentEvents(5, 24);
            $profileConfig = (array) config('offline_policy.profiles.'.$profile, []);
            $localRuntime = $this->collectLocalRuntimeScorecard();

            $counts = [
                'offline_mode_active' => $offlineModeActive,
                'active_profile' => $profile,
                'runtime_state' => $this->offlineRuntimeState($profile, $offlineModeActive),
                'audit_result' => $audit['result'] ?? 'unknown',
                'audit_total_24h' => (int) ($audit['total'] ?? 0),
                'policy_denials_24h' => (int) ($audit['denied'] ?? 0),
                'mode_changes_24h' => (int) ($audit['mode_changes'] ?? 0),
                'local_runtime_status' => $localRuntime['status'],
                'local_availability_state' => $localRuntime['availability_state'],
                'local_instances' => $localRuntime['local_instances'],
                'healthy_local_instances' => $localRuntime['healthy_local_instances'],
                'selected_local_id' => $localRuntime['selected_local_id'],
                'selected_local_model' => $localRuntime['selected_local_model'],
                'local_runtime' => $localRuntime,
                'capabilities' => [
                    'tool_classes' => array_values((array) ($profileConfig['allowed_tool_classes'] ?? [])),
                    'mcp_trust' => array_values((array) ($profileConfig['allowed_mcp_trust'] ?? [])),
                    'path_classes' => array_values((array) ($profileConfig['allowed_path_classes'] ?? [])),
                    'provider_classes' => $offlineModeActive
                        ? ['local_llm']
                        : array_values((array) ($profileConfig['allowed_provider_classes'] ?? [])),
                    'remote_domain_classes' => array_values((array) ($profileConfig['allowed_remote_domain_classes'] ?? [])),
                    'confirmation_required_for' => array_values((array) ($profileConfig['confirmation'] ?? [])),
                ],
                'recent_audit_events' => array_map(fn (array $event): array => [
                    'event_type' => $event['event_type'] ?? null,
                    'profile' => $event['profile'] ?? null,
                    'offline_mode_active' => (bool) ($event['offline_mode_active'] ?? false),
                    'operation' => $event['operation'] ?? null,
                    'tool_class' => $event['tool_class'] ?? null,
                    'provider_class' => $event['provider_class'] ?? null,
                    'remote_domain_class' => $event['remote_domain_class'] ?? null,
                    'reason' => $event['reason'] ?? null,
                    'created_at' => $event['created_at'] ?? null,
                ], $recentEvents),
            ];

            $status = match (true) {
                $offlineModeActive => 'degraded',
                ($audit['result'] ?? null) !== 'ok' => 'degraded',
                $counts['policy_denials_24h'] > 0 || $counts['mode_changes_24h'] > 0 || $profile !== 'default' => 'watch',
                default => 'healthy',
            };

            return $this->section(
                $status,
                $sampledAt,
                ['OfflinePolicyService', 'OfflineAuditService', 'LLMPoolManagerService', 'offline_audit_events', 'system_configs'],
                $counts,
                $status === 'healthy' ? null : 'Review offline policy profile, mode switch, and recent denial receipts.'
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['OfflinePolicyService', 'offline_audit_events'], $e, 'Offline/degraded state query failed.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function collectLocalRuntimeScorecard(): array
    {
        $empty = [
            'source' => LLMPoolManagerService::class,
            'status' => 'unavailable',
            'role' => 'coding',
            'availability_state' => 'unavailable',
            'primary_up' => null,
            'secondary_up' => null,
            'local_instances' => null,
            'healthy_local_instances' => null,
            'degraded_local_instances' => null,
            'selected_local_present' => false,
            'selected_local_id' => null,
            'selected_local_model' => null,
            'selection_method' => 'monitoring_rows_read_only',
        ];

        try {
            $llmPool = $this->llmPool;
            if ($llmPool === null) {
                if (app()->runningUnitTests()) {
                    return $empty;
                }

                $llmPool = app(LLMPoolManagerService::class);
            }

            $availability = $llmPool->describeLocalAvailability();
            $monitoringInstances = $llmPool->getInstancesForMonitoring();
            $instances = array_values(array_filter(
                $monitoringInstances,
                static fn (object $instance): bool => ((int) ($instance->is_active ?? 0)) === 1
                    && (($instance->routability ?? 'allowed') === 'allowed')
                    && (($instance->instance_type ?? null) === 'ollama')
            ));
            $healthy = array_values(array_filter(
                $instances,
                static fn (object $instance): bool => ((int) ($instance->is_healthy ?? 0)) === 1
                    && (($instance->circuit_state ?? 'closed') !== 'open')
            ));
            $selectedLocal = $this->serializeLocalRuntimeInstance($healthy[0] ?? null, 'coding');
            $availabilityState = (string) ($availability['state'] ?? 'unknown');
            $status = match ($availabilityState) {
                'all_locals_up' => 'healthy',
                'primary_down', 'secondary_down' => 'watch',
                default => 'degraded',
            };

            return [
                'source' => LLMPoolManagerService::class,
                'status' => $status,
                'role' => 'coding',
                'availability_state' => $availabilityState,
                'primary_up' => isset($availability['primary_up']) ? (bool) $availability['primary_up'] : null,
                'secondary_up' => isset($availability['secondary_up']) ? (bool) $availability['secondary_up'] : null,
                'local_instances' => count($instances),
                'healthy_local_instances' => count($healthy),
                'degraded_local_instances' => max(0, count($instances) - count($healthy)),
                'selected_local_present' => $selectedLocal !== null,
                'selected_local_id' => $selectedLocal['instance_id'] ?? null,
                'selected_local_model' => $selectedLocal['selected_model'] ?? null,
                'selection_method' => 'monitoring_rows_read_only',
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function serializeLocalRuntimeInstance(?object $instance, string $role): ?array
    {
        if ($instance === null || ($instance->instance_type ?? null) !== 'ollama') {
            return null;
        }

        $configValue = $instance->config ?? [];
        $config = match (true) {
            is_array($configValue) => $configValue,
            $configValue instanceof \stdClass => (array) $configValue,
            default => json_decode((string) $configValue, true) ?: [],
        };
        $models = (array) ($config['models'] ?? []);

        return [
            'instance_id' => (string) ($instance->instance_id ?? ''),
            'selected_model' => $models[$role] ?? $models['standard'] ?? null,
        ];
    }

    private function offlineRuntimeState(string $profile, bool $offlineModeActive): string
    {
        if ($offlineModeActive) {
            return 'offline_mode_enabled';
        }

        if (str_starts_with($profile, 'offline_')) {
            return 'offline_profile_without_kill_switch';
        }

        if (str_starts_with($profile, 'hybrid_') || $profile === 'cloud_escalation_only') {
            return 'hybrid_profile';
        }

        return 'normal';
    }

    private function collectDisabledGenealogyAgents(Carbon $sampledAt): array
    {
        if ($this->missingTables(['scheduled_jobs']) !== []) {
            return $this->section('blocked', $sampledAt, ['scheduled_jobs'], [
                'missing_tables' => ['scheduled_jobs'],
            ], 'Restore scheduled_jobs before evaluating genealogy agent state.');
        }

        try {
            $counts = $this->genealogyAutomationTargetCounts();

            $status = match (true) {
                $counts['missing'] > 0 => 'blocked',
                $counts['stale_running'] > 0 || $counts['recent_failed_24h'] > 0 => 'degraded',
                $counts['disabled_without_reason'] > 0 => 'degraded',
                $counts['disabled'] > 0 => 'watch',
                default => 'healthy',
            };

            return $this->section(
                $status,
                $sampledAt,
                ['scheduled_jobs'],
                $counts,
                $status === 'healthy' ? null : 'Review disabled or degraded genealogy automation targets before re-enabling autonomy.'
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['scheduled_jobs'], $e, 'Disabled genealogy agent query failed.');
        }
    }

    private function collectGenealogyAgentTriage(Carbon $sampledAt): array
    {
        try {
            $payload = ($this->genealogyTriage ?? app(GenealogyAgentTriageService::class))->collect(30);
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $targets = array_values(array_filter((array) ($payload['targets'] ?? []), 'is_array'));
            $targetsNeedingReview = array_values(array_filter(
                (array) ($summary['targets_needing_review'] ?? []),
                'is_string'
            ));
            $status = (string) ($payload['status'] ?? 'watch');
            if (! array_key_exists($status, self::STATUSES)) {
                $status = 'watch';
            }

            $counts = [
                'window_days' => (int) ($payload['window_days'] ?? 30),
                'targets_total' => (int) ($summary['targets_total'] ?? 0),
                'configured_targets' => (int) ($summary['configured_targets'] ?? 0),
                'enabled_targets' => (int) ($summary['enabled_targets'] ?? 0),
                'disabled_targets' => (int) ($summary['disabled_targets'] ?? 0),
                'missing_targets' => (int) ($summary['missing_targets'] ?? 0),
                'blocked_targets' => (int) ($summary['blocked_targets'] ?? 0),
                'degraded_targets' => (int) ($summary['degraded_targets'] ?? 0),
                'watch_targets' => (int) ($summary['watch_targets'] ?? 0),
                'completed_sessions_window' => (int) ($summary['completed_sessions_window'] ?? 0),
                'review_outputs_window' => (int) ($summary['review_outputs_window'] ?? 0),
                'awo_completed_reviews_window' => (int) ($summary['awo_completed_reviews_window'] ?? 0),
                'awo_approval_worthy_reviews_window' => (int) ($summary['awo_approval_worthy_reviews_window'] ?? 0),
                'awo_approval_worthy_rate' => $summary['awo_approval_worthy_rate'] ?? null,
                'targets_needing_review_count' => count($targetsNeedingReview),
                'targets_needing_review' => $targetsNeedingReview,
                'source_backed_review_packets_required_targets' => $this->countTriagePreEnableFlag($targets, 'source_backed_review_packets_required'),
                'scenario_test_required_targets' => $this->countTriageScenarioTestTargets($targets),
                'operator_approval_required_targets' => $this->countTriagePreEnableFlag($targets, 'operator_approval_required'),
                'scheduler_enablement_allowed_targets' => $this->countTriagePreEnableFlag($targets, 'scheduler_enablement_allowed'),
                'production_writeback_allowed_targets' => $this->countTriagePreEnableFlag($targets, 'production_writeback_allowed'),
                'canonical_genealogy_writeback_allowed_targets' => $this->countTriagePreEnableFlag($targets, 'canonical_genealogy_writeback_allowed'),
                'awo_sample_floor_met_targets' => $this->countTriageAwoFlag($targets, 'sample_floor_met'),
                'awo_approval_worthy_present_targets' => $this->countTriageAwoFlag($targets, 'approval_worthy_present'),
            ];

            return $this->section(
                $status,
                $sampledAt,
                ['GenealogyAgentTriageService', 'scheduled_jobs', 'agent_sessions', 'agent_episodes', 'agent_review_queue', 'AwoReplayService'],
                $counts,
                $status === 'healthy' ? null : 'Review genealogy:agent-triage before changing genealogy sub-agent scheduler state.'
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['GenealogyAgentTriageService', 'scheduled_jobs'], $e, 'Genealogy agent triage query failed.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $targets
     */
    private function countTriagePreEnableFlag(array $targets, string $flag): int
    {
        return count(array_filter(
            $targets,
            function (array $target) use ($flag): bool {
                $gates = is_array($target['pre_enable_gates'] ?? null) ? $target['pre_enable_gates'] : [];

                return ! empty($gates[$flag]);
            }
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $targets
     */
    private function countTriageScenarioTestTargets(array $targets): int
    {
        return count(array_filter(
            $targets,
            function (array $target): bool {
                $gates = is_array($target['pre_enable_gates'] ?? null) ? $target['pre_enable_gates'] : [];
                $tests = $gates['scenario_tests_required'] ?? [];

                return is_array($tests) && $tests !== [];
            }
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $targets
     */
    private function countTriageAwoFlag(array $targets, string $flag): int
    {
        return count(array_filter(
            $targets,
            function (array $target) use ($flag): bool {
                $awo = is_array($target['awo'] ?? null) ? $target['awo'] : [];

                return ! empty($awo[$flag]);
            }
        ));
    }

    private function collectAgentFailuresStaleWork(Carbon $sampledAt): array
    {
        $missing = $this->missingTables(['agent_sessions', 'agent_review_queue', 'agent_episodes', 'scheduled_jobs']);
        if ($missing !== []) {
            return $this->section('blocked', $sampledAt, ['agent_sessions', 'agent_review_queue', 'agent_episodes', 'scheduled_jobs'], [
                'missing_tables' => $missing,
            ], 'Restore agent evidence tables before evaluating failures.');
        }

        try {
            $sessions = DB::selectOne(
                "SELECT
                    COUNT(*) AS stale_active_sessions,
                    MAX(TIMESTAMPDIFF(MINUTE, COALESCE(last_activity_at, updated_at, created_at), NOW())) AS oldest_active_age_minutes
                 FROM agent_sessions
                 WHERE status IN ('active', 'running')
                   AND COALESCE(last_activity_at, updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );
            $reviews = DB::selectOne(
                "SELECT
                    COUNT(*) AS pending_reviews,
                    SUM(CASE WHEN priority >= 2 THEN 1 ELSE 0 END) AS urgent_pending_reviews
                 FROM agent_review_queue
                 WHERE status = 'pending'"
            );
            $episodes = DB::selectOne(
                "SELECT
                    COUNT(*) AS recent_episodes,
                    SUM(CASE WHEN event_type = 'error' THEN 1 ELSE 0 END) AS recent_errors,
                    MAX(created_at) AS latest_episode_at
                 FROM agent_episodes
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $jobs = DB::selectOne(
                "SELECT
                    SUM(CASE WHEN enabled = 1
                               AND (job_type = 'agent_task' OR name LIKE '%agent%')
                               AND last_run_status IN ('failed', 'timeout')
                               AND last_run_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                             THEN 1 ELSE 0 END) AS recent_failed_agent_jobs,
                    SUM(CASE WHEN enabled = 1
                               AND (job_type = 'agent_task' OR name LIKE '%agent%')
                               AND last_run_status = 'running'
                               AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes, 30) MINUTE)
                             THEN 1 ELSE 0 END) AS stale_agent_jobs
                 FROM scheduled_jobs"
            );

            $counts = [
                'stale_active_sessions' => (int) ($sessions->stale_active_sessions ?? 0),
                'oldest_active_session_age_minutes' => isset($sessions->oldest_active_age_minutes) ? (int) $sessions->oldest_active_age_minutes : null,
                'pending_reviews' => (int) ($reviews->pending_reviews ?? 0),
                'urgent_pending_reviews' => (int) ($reviews->urgent_pending_reviews ?? 0),
                'review_type_breakdown' => $this->pendingReviewTypeBreakdown(),
                'genealogy_automation_targets' => $this->genealogyAutomationTargetCounts(),
                'recent_failures' => $this->recentAgentFailures(),
                'agent_failures_by_agent_24h' => $this->agentFailuresByAgent24h(),
                'recent_agent_episodes_24h' => (int) ($episodes->recent_episodes ?? 0),
                'recent_agent_errors_24h' => (int) ($episodes->recent_errors ?? 0),
                'recent_failed_agent_jobs_24h' => (int) ($jobs->recent_failed_agent_jobs ?? 0),
                'stale_agent_jobs' => (int) ($jobs->stale_agent_jobs ?? 0),
            ];
            $freshness = [
                'latest_agent_episode_at' => $this->nullableString($episodes->latest_episode_at ?? null),
            ];

            $status = match (true) {
                $counts['stale_agent_jobs'] > 0 => 'blocked',
                $counts['stale_active_sessions'] > 0
                    || $counts['recent_agent_errors_24h'] > 0
                    || $counts['recent_failed_agent_jobs_24h'] > 0
                    || $counts['urgent_pending_reviews'] > 0 => 'degraded',
                $counts['pending_reviews'] > 0 || $counts['recent_agent_episodes_24h'] === 0 => 'watch',
                default => 'healthy',
            };

            return $this->section(
                $status,
                $sampledAt,
                ['agent_sessions', 'agent_review_queue', 'agent_episodes', 'scheduled_jobs'],
                $counts,
                $status === 'healthy' ? null : 'Inspect stale agent sessions, failed agent jobs, and pending review output.',
                $freshness
            );
        } catch (\Throwable $e) {
            return $this->failedSection($sampledAt, ['agent_sessions', 'agent_review_queue', 'agent_episodes'], $e, 'Agent failure query failed.');
        }
    }

    private function pendingReviewTypeBreakdown(): array
    {
        $rows = DB::select(
            "SELECT COALESCE(NULLIF(review_type, ''), 'unknown') AS review_type, COUNT(*) AS count
             FROM agent_review_queue
             WHERE status = 'pending'
             GROUP BY COALESCE(NULLIF(review_type, ''), 'unknown')
             ORDER BY review_type"
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[(string) $row->review_type] = (int) $row->count;
        }

        return $breakdown;
    }

    private function recentAgentFailures(): array
    {
        $rows = DB::select(
            "SELECT agent_id, event_type, summary, created_at
             FROM agent_episodes
             WHERE event_type = 'error'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY created_at DESC
             LIMIT 3"
        );

        return array_map(fn ($row) => [
            'agent_id' => $this->nonEmptyString($row->agent_id ?? null, 'unknown'),
            'event_type' => $this->nonEmptyString($row->event_type ?? null, 'error'),
            'summary' => $this->truncateSummary($row->summary ?? null),
            'created_at' => $this->nullableString($row->created_at ?? null),
        ], $rows);
    }

    private function agentFailuresByAgent24h(): array
    {
        $rows = DB::select(
            "SELECT COALESCE(NULLIF(agent_id, ''), 'unknown') AS agent_id, COUNT(*) AS count
             FROM agent_episodes
             WHERE event_type = 'error'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY COALESCE(NULLIF(agent_id, ''), 'unknown')
             ORDER BY count DESC, agent_id ASC
             LIMIT 5"
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[(string) $row->agent_id] = (int) $row->count;
        }

        return $breakdown;
    }

    private function genealogyAutomationTargetCounts(): array
    {
        if ($this->genealogyAutomationTargetCounts !== null) {
            return $this->genealogyAutomationTargetCounts;
        }

        $placeholders = implode(',', array_fill(0, count(self::GENEALOGY_AUTOMATION_TARGETS), '?'));
        $rows = DB::select(
            "SELECT
                name,
                enabled,
                CASE WHEN enabled = 0 THEN 1 ELSE 0 END AS disabled,
                CASE WHEN enabled = 0 AND NULLIF(TRIM(COALESCE(notes, '')), '') IS NULL THEN 1 ELSE 0 END AS disabled_without_reason,
                CASE WHEN enabled = 1
                           AND last_run_status = 'running'
                           AND last_run_at IS NOT NULL
                           AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes, 30) MINUTE)
                     THEN 1 ELSE 0 END AS stale_running,
                CASE WHEN enabled = 1
                           AND last_run_status IN ('failed', 'timeout')
                           AND last_run_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     THEN 1 ELSE 0 END AS recent_failed,
                notes
             FROM scheduled_jobs
             WHERE name IN ({$placeholders})
             ORDER BY name",
            self::GENEALOGY_AUTOMATION_TARGETS
        );

        // Keep this tied to scheduled_jobs only. Some names may exist as skills
        // or docs references, but this evidence surface should not infer runtime
        // automation state from anything other than scheduler rows.
        $seen = [];
        $disabled = [];
        $disabledWithoutReason = [];
        $lastDisabledReasons = [];
        $staleRunning = [];
        $recentFailed = [];
        $enabled = 0;

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $seen[] = $name;

            if ((int) ($row->enabled ?? 0) === 1) {
                $enabled++;
            }
            if ((int) ($row->disabled ?? 0) > 0) {
                $disabled[] = $name;
                $reason = trim((string) ($row->notes ?? ''));
                if ($reason !== '') {
                    $lastDisabledReasons[$name] = $reason;
                }
            }
            if ((int) ($row->disabled_without_reason ?? 0) > 0) {
                $disabledWithoutReason[] = $name;
            }
            if ((int) ($row->stale_running ?? 0) > 0) {
                $staleRunning[] = $name;
            }
            if ((int) ($row->recent_failed ?? 0) > 0) {
                $recentFailed[] = $name;
            }
        }

        $missing = array_values(array_diff(self::GENEALOGY_AUTOMATION_TARGETS, $seen));

        return $this->genealogyAutomationTargetCounts = [
            'configured' => count($seen),
            'enabled' => $enabled,
            'disabled' => count($disabled),
            'disabled_without_reason' => count($disabledWithoutReason),
            'stale_running' => count($staleRunning),
            'recent_failed_24h' => count($recentFailed),
            'missing' => count($missing),
            'disabled_names' => $disabled,
            'disabled_without_reason_names' => $disabledWithoutReason,
            'last_disabled_reasons' => $lastDisabledReasons,
            'stale_running_names' => $staleRunning,
            'recent_failed_names' => $recentFailed,
            'missing_names' => $missing,
        ];
    }

    private function collectRagDigest(): array
    {
        try {
            $metrics = $this->ragBacklog->getDigestMetrics();
            $evidenceErrors = $metrics['evidence_errors'] ?? [];
            if (is_array($evidenceErrors) && $evidenceErrors !== []) {
                return [
                    'result' => 'query_failed',
                    'error' => 'RAG digest has incomplete evidence.',
                    'evidence_errors' => array_slice($evidenceErrors, 0, 10),
                ];
            }

            $allZero = (int) ($metrics['documents'] ?? 0) === 0
                && (int) ($metrics['raptor']['pending'] ?? 0) === 0
                && (int) ($metrics['sentence']['pending'] ?? 0) === 0
                && (int) ($metrics['kg']['pending'] ?? 0) === 0;

            if ($allZero && $this->missingTables(['rag_documents'], 'pgsql_rag') !== []) {
                return [
                    'result' => 'missing_evidence',
                    'error' => 'pgsql_rag.rag_documents missing or unreadable',
                ];
            }

            return [
                'result' => 'ok',
                'metrics' => $metrics,
            ];
        } catch (\Throwable $e) {
            return [
                'result' => 'query_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function collectRagNetBurn(): array
    {
        try {
            $payload = $this->ragBacklog->getNetBurn(7);

            return [
                'result' => empty($payload['evidence_errors']) ? 'ok' : 'query_failed',
                'window_days' => (int) ($payload['window_days'] ?? 7),
                'lanes' => is_array($payload['lanes'] ?? null) ? $payload['lanes'] : [],
                'evidence_errors' => $payload['evidence_errors'] ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'result' => 'query_failed',
                'window_days' => 7,
                'lanes' => [],
                'evidence_errors' => [[
                    'code' => 'net_burn_unavailable',
                    'context' => ['error' => $e->getMessage()],
                ]],
            ];
        }
    }

    private function appendNetBurnCounts(array &$counts, string $prefix, ?array $lane, array $ragNetBurn): void
    {
        $counts["{$prefix}_net_burn_window_days"] = (int) ($ragNetBurn['window_days'] ?? 7);
        $counts["{$prefix}_net_burn_points"] = (int) ($lane['points'] ?? 0);
        $counts["{$prefix}_net_delta_total"] = $lane['delta_total'] ?? null;
        $counts["{$prefix}_net_delta_avg_per_day"] = $lane['delta_avg_per_day'] ?? null;
        $counts["{$prefix}_net_burn_per_day"] = $lane['net_burn_per_day'] ?? 0.0;
        $counts["{$prefix}_net_burn_trend"] = $lane['trend'] ?? 'insufficient_data';
    }

    private function proposalSummary(string $table): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'pending'
                           AND (
                               evidence_sources IS NULL
                               OR evidence_sources = ''
                               OR evidence_sources = '[]'
                               OR evidence_summary IS NULL
                               OR evidence_summary = ''
                           )
                         THEN 1 ELSE 0 END) AS evidence_gaps,
                MAX(CASE WHEN status = 'pending' THEN TIMESTAMPDIFF(HOUR, created_at, NOW()) ELSE NULL END) AS oldest_pending_age_hours
             FROM {$table}"
        );

        return [
            'total' => (int) ($row->total ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'evidence_gaps' => (int) ($row->evidence_gaps ?? 0),
            'oldest_pending_age_hours' => isset($row->oldest_pending_age_hours) ? (int) $row->oldest_pending_age_hours : null,
        ];
    }

    private function collectQueueDepth(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $depths = array_fill_keys(self::QUEUES, 0);

        try {
            if ($driver === 'redis') {
                $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
                foreach (self::QUEUES as $queue) {
                    $depths[$queue] = (int) ($redis->llen("queues:{$queue}") ?? 0)
                        + (int) ($redis->zcard("queues:{$queue}:delayed") ?? 0)
                        + (int) ($redis->zcard("queues:{$queue}:reserved") ?? 0);
                }

                return [
                    'source' => 'redis',
                    'queues' => $depths,
                    'total' => array_sum($depths),
                    'error' => null,
                ];
            }

            if ($driver === 'database' && $this->missingTables(['jobs']) === []) {
                $rows = DB::select('SELECT queue, COUNT(*) AS c FROM jobs GROUP BY queue');
                foreach ($rows as $row) {
                    $depths[(string) $row->queue] = (int) $row->c;
                }

                return [
                    'source' => 'database',
                    'queues' => $depths,
                    'total' => array_sum($depths),
                    'error' => null,
                ];
            }

            return [
                'source' => $driver,
                'queues' => $depths,
                'total' => 0,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'source' => $driver,
                'queues' => $depths,
                'total' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function countRecentFailedJobs(int $window = 24, string $unit = 'HOUR'): ?int
    {
        if ($this->missingTables(['failed_jobs']) !== []) {
            return null;
        }

        $unit = strtoupper($unit);
        if (! in_array($unit, ['MINUTE', 'HOUR'], true)) {
            $unit = 'HOUR';
        }
        $window = max(1, (int) $window);

        return (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL {$window} {$unit})"
        )?->c ?? 0);
    }

    private function collectCompletionLagMinutes(): ?int
    {
        if ($this->missingTables(['scheduled_job_runs']) !== []) {
            return null;
        }

        $row = DB::selectOne(
            "SELECT TIMESTAMPDIFF(MINUTE, MAX(completed_at), NOW()) AS c
             FROM scheduled_job_runs
             WHERE status = 'success'
               AND completed_at IS NOT NULL
               AND triggered_by = 'scheduler'"
        );

        return $row?->c === null ? null : (int) $row->c;
    }

    private function missingTables(array $tables, string $connection = 'mysql'): array
    {
        $missing = [];

        foreach ($tables as $table) {
            try {
                if (! Schema::connection($connection)->hasTable($table)) {
                    $missing[] = $table;
                }
            } catch (\Throwable) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    private function compactSection(array $sections, string $name): array
    {
        return is_array($sections[$name] ?? null) ? $sections[$name] : [];
    }

    private function compactCounts(array $section): array
    {
        return is_array($section['counts'] ?? null) ? $section['counts'] : [];
    }

    /**
     * @return list<array{agent_id:string,reason_codes:list<string>}>
     */
    private function compactAgentReasonList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $agentId = trim((string) ($row['agent_id'] ?? ''));
            if ($agentId === '' || preg_match('/^[A-Za-z0-9._:-]{1,96}$/', $agentId) !== 1) {
                continue;
            }

            $reasonCodes = array_values(array_filter(
                (array) ($row['reason_codes'] ?? []),
                fn (mixed $code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $code) === 1
            ));

            $rows[] = [
                'agent_id' => $agentId,
                'reason_codes' => array_slice($reasonCodes, 0, 8),
            ];

            if (count($rows) >= 5) {
                break;
            }
        }

        return $rows;
    }

    private function compactFreshness(array $section): array
    {
        return is_array($section['freshness'] ?? null) ? $section['freshness'] : [];
    }

    private function compactStatus(array $section): string
    {
        $status = $this->nullableString($section['status'] ?? null);

        return $status === null ? 'unavailable' : $this->normalizeStatus($status);
    }

    private function section(
        string $status,
        Carbon $sampledAt,
        array $sources,
        array $counts,
        ?string $nextAction = null,
        array $freshness = []
    ): array {
        $section = [
            'status' => $this->normalizeStatus($status),
            'sampled_at' => $this->formatTimestamp($sampledAt),
            'sources' => array_values($sources),
            'counts' => $counts,
        ];

        if ($freshness !== []) {
            $section['freshness'] = $freshness;
        }

        if ($nextAction !== null) {
            $section['next_action'] = $nextAction;
        }

        return $section;
    }

    private function failedSection(Carbon $sampledAt, array $sources, \Throwable $e, string $nextAction): array
    {
        return $this->section('blocked', $sampledAt, $sources, [
            'error' => $e->getMessage(),
        ], $nextAction);
    }

    private function worstStatus(array $statuses): string
    {
        $worst = 'healthy';

        foreach ($statuses as $status) {
            $worst = $this->maxStatus($worst, (string) $status);
        }

        return $worst;
    }

    private function maxStatus(string $left, string $right): string
    {
        $left = $this->normalizeStatus($left);
        $right = $this->normalizeStatus($right);

        return self::STATUSES[$right] > self::STATUSES[$left] ? $right : $left;
    }

    private function normalizeStatus(string $status): string
    {
        return array_key_exists($status, self::STATUSES) ? $status : 'degraded';
    }

    private function maxNullable(?int $left, ?int $right): ?int
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return max($left, $right);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function nonEmptyString(mixed $value, string $fallback): string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? $fallback : $string;
    }

    private function truncateSummary(mixed $value): ?string
    {
        $summary = trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')));
        if ($summary === '') {
            return null;
        }

        return Str::limit($summary, 180, '...');
    }

    private function formatTimestamp(Carbon $timestamp): string
    {
        return $timestamp->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}

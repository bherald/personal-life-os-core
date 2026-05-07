<template>
  <div class="max-w-7xl mx-auto px-4 py-4">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold text-ops-peach uppercase tracking-wider">Operator Evidence</h1>
        <div class="text-xs text-ops-text-muted uppercase tracking-wide mt-1">Read-only operational evidence</div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <span
          class="inline-flex max-w-full items-center gap-2 border px-3 py-1.5 rounded-full text-xs font-bold uppercase"
          :class="statusBadgeClass(offlineStatus)"
          :title="runtimeModeTitle"
        >
          <span class="h-2 w-2 rounded-full shrink-0" :class="statusDotClass(offlineStatus)"></span>
          <span class="text-ops-text-muted">Runtime</span>
          <span class="truncate">{{ runtimeModeLabel }}</span>
        </span>
        <button
          type="button"
          @click="refresh"
          :disabled="loading"
          class="inline-flex items-center gap-2 bg-ops-orange text-black px-4 py-1.5 rounded-r-full hover:bg-ops-peach disabled:opacity-60 disabled:cursor-not-allowed font-semibold uppercase text-xs"
        >
          <span aria-hidden="true">&#8635;</span>
          <span>{{ loading ? 'Loading' : 'Refresh' }}</span>
        </button>
      </div>
    </div>

    <div
      v-if="loading && !payload"
      class="bg-black border-2 border-ops-plum rounded-r-lg p-8 text-center text-ops-text-muted"
    >
      Loading operator evidence...
    </div>

    <div
      v-else-if="error && !payload"
      class="bg-ops-alert/20 border-2 border-ops-alert rounded-r-lg p-6 text-center"
    >
      <h2 class="text-lg font-semibold text-ops-alert uppercase tracking-wide">Evidence Unavailable</h2>
      <p class="text-sm text-ops-peach mt-2">{{ error }}</p>
      <button
        type="button"
        @click="refresh"
        class="mt-4 px-4 py-2 bg-ops-alert text-black rounded-r-full hover:bg-red-400 text-sm font-semibold uppercase"
      >
        Retry
      </button>
    </div>

    <template v-else>
      <div
        v-if="error"
        class="mb-4 bg-ops-butterscotch/10 border-2 border-ops-butterscotch rounded-r-lg px-4 py-3 text-sm text-ops-butterscotch"
      >
        Last refresh failed: {{ error }}
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-7 gap-3 mb-4">
        <div class="bg-black border-2 border-ops-plum rounded-r-lg px-4 py-3">
          <div class="text-[11px] text-ops-text-muted uppercase tracking-wide">Overall</div>
          <span class="mt-1 inline-flex items-center gap-2 px-2 py-0.5 border rounded-full text-xs font-bold uppercase" :class="statusBadgeClass(overallStatus)">
            <span class="h-2 w-2 rounded-full" :class="statusDotClass(overallStatus)"></span>
            {{ statusLabel(overallStatus) }}
          </span>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-lg px-4 py-3">
          <div class="text-[11px] text-ops-text-muted uppercase tracking-wide">Captured</div>
          <div class="mt-1 text-sm font-semibold text-ops-peach">{{ capturedAt }}</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-lg px-4 py-3">
          <div class="text-[11px] text-ops-text-muted uppercase tracking-wide">Queue Depth</div>
          <div class="mt-1 text-xl font-bold text-ops-sky">{{ topMetric('queue_health', 'counts.queue_depth_total') }}</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-lg px-4 py-3">
          <div class="text-[11px] text-ops-text-muted uppercase tracking-wide">Profile</div>
          <div class="mt-1 text-sm font-semibold text-ops-green truncate">{{ topMetric('offline_degraded_state', 'counts.active_profile') }}</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-lg px-4 py-3">
          <div class="text-[11px] text-ops-text-muted uppercase tracking-wide">Offline</div>
          <div class="mt-1 text-sm font-semibold text-ops-peach">{{ topMetric('offline_degraded_state', 'counts.offline_mode_active', formatBoolean) }}</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-lg px-4 py-3">
          <div class="text-[11px] text-ops-text-muted uppercase tracking-wide">KG ETA</div>
          <div class="mt-1 text-xl font-bold text-ops-butterscotch">{{ topMetric('kg_backlog', 'counts.eta_days', formatDays) }}</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-lg px-4 py-3">
          <div class="text-[11px] text-ops-text-muted uppercase tracking-wide">Pending Operator Reviews</div>
          <div class="mt-1 text-xl font-bold text-ops-lilac">{{ topMetric('review_backlog', 'counts.pending_total') }}</div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <section
          v-for="card in sectionCards"
          :key="card.key"
          class="bg-black border-2 rounded-r-lg overflow-hidden"
          :class="sectionBorderClass(card.status)"
        >
          <div class="flex items-start justify-between gap-3 bg-ops-plum/30 border-b border-ops-plum/50 px-4 py-2">
            <div class="min-w-0">
              <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider truncate">{{ card.title }}</h2>
              <div class="text-[11px] text-ops-text-muted uppercase tracking-wide mt-0.5">{{ card.code }}</div>
            </div>
            <span class="inline-flex shrink-0 items-center gap-2 px-2 py-0.5 border rounded-full text-[11px] font-bold uppercase" :class="statusBadgeClass(card.status)">
              <span class="h-2 w-2 rounded-full" :class="statusDotClass(card.status)"></span>
              {{ statusLabel(card.status) }}
            </span>
          </div>

          <div class="p-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
              <div
                v-for="metric in card.metrics"
                :key="metric.label"
                class="border border-ops-plum/40 rounded-r px-3 py-2 min-w-0"
              >
                <div class="text-[11px] text-ops-text-muted uppercase tracking-wide truncate">{{ metric.label }}</div>
                <div class="mt-1 text-sm font-semibold text-ops-peach truncate" :title="metric.value">{{ metric.value }}</div>
              </div>
            </div>

            <div
              v-if="card.key === 'agent_failures_stale_work' && recentFailures.length"
              class="mt-3 border-t border-ops-plum/40 pt-3"
            >
              <div class="text-[11px] text-ops-text-muted uppercase tracking-wide mb-2">Recent Failures</div>
              <div class="space-y-2">
                <div
                  v-for="failure in recentFailures"
                  :key="`${failure.agent_id || 'unknown'}-${failure.created_at || 'unknown'}-${failure.summary || failure.event_type || 'error'}`"
                  class="min-w-0"
                >
                  <div class="flex flex-wrap items-center gap-2 text-[11px] uppercase tracking-wide">
                    <span class="text-ops-orange font-semibold">{{ failure.agent_id || 'unknown' }}</span>
                    <span class="text-ops-text-muted">{{ formatTimestamp(failure.created_at) }}</span>
                  </div>
                  <div class="text-xs text-ops-peach truncate" :title="failure.summary || failure.event_type || 'error'">
                    {{ failure.summary || failure.event_type || 'error' }}
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="card.key === 'offline_degraded_state' && offlineCapabilities.length"
              class="mt-3 border-t border-ops-plum/40 pt-3"
            >
              <div class="text-[11px] text-ops-text-muted uppercase tracking-wide mb-2">Capability Map</div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div
                  v-for="capability in offlineCapabilities"
                  :key="capability.label"
                  class="border border-ops-plum/30 rounded-r px-3 py-2 min-w-0"
                >
                  <div class="text-[11px] text-ops-text-muted uppercase tracking-wide truncate">{{ capability.label }}</div>
                  <div class="mt-1 text-xs text-ops-peach truncate" :title="capability.value">{{ capability.value }}</div>
                </div>
              </div>
            </div>

            <div
              v-if="card.key === 'offline_degraded_state' && recentOfflineEvents.length"
              class="mt-3 border-t border-ops-plum/40 pt-3"
            >
              <div class="text-[11px] text-ops-text-muted uppercase tracking-wide mb-2">Recent Audit Events</div>
              <div class="space-y-2">
                <div
                  v-for="event in recentOfflineEvents"
                  :key="`${event.event_type || 'event'}-${event.created_at || 'unknown'}-${event.operation || event.reason || 'offline'}`"
                  class="min-w-0"
                >
                  <div class="flex flex-wrap items-center gap-2 text-[11px] uppercase tracking-wide">
                    <span class="text-ops-orange font-semibold">{{ event.event_type || 'event' }}</span>
                    <span class="text-ops-green">{{ event.profile || 'unknown' }}</span>
                    <span class="text-ops-text-muted">{{ formatTimestamp(event.created_at) }}</span>
                  </div>
                  <div class="text-xs text-ops-peach truncate" :title="offlineEventSummary(event)">
                    {{ offlineEventSummary(event) }}
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[11px] uppercase tracking-wide">
              <span class="text-ops-text-muted">Sampled {{ card.sampledAt }}</span>
              <span v-if="card.version" class="text-ops-text-muted">v{{ card.version }}</span>
            </div>

            <div
              v-if="card.nextAction"
              class="mt-3 border-l-4 border-ops-orange bg-ops-orange/10 px-3 py-2 text-xs text-ops-gold"
            >
              {{ card.nextAction }}
            </div>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../utils/api'
import { useTimezone } from '../composables/useTimezone'

const { formatDate } = useTimezone()

const payload = ref(null)
const loading = ref(false)
const error = ref('')

const SECTION_DEFINITIONS = [
  {
    key: 'queue_health',
    title: 'Queue Health',
    code: 'Scheduler / queue',
    metrics: [
      { label: 'Queue Depth', path: 'counts.queue_depth_total' },
      { label: 'Enabled Jobs', path: 'counts.enabled_scheduled_jobs' },
      { label: 'Scheduler Lag', path: 'freshness.scheduler_lag_minutes', format: formatMinutes },
      { label: 'Completion Lag', path: 'freshness.completion_lag_minutes', format: formatMinutes },
      { label: 'Queue Failures', path: 'counts.recent_queue_failures' },
      { label: 'Failed Jobs 30m', path: 'counts.recent_failed_jobs_30m' },
    ],
  },
  {
    key: 'scheduler_optimization',
    title: 'Scheduler Optimization',
    code: 'Observe-only',
    metrics: [
      { label: 'Window', path: 'counts.window' },
      { label: 'Jobs', path: 'counts.job_count' },
      { label: 'Recommendations', path: 'counts.recommendation_count' },
      { label: 'Warnings', path: 'counts.warning_recommendations' },
      { label: 'Notices', path: 'counts.notice_recommendations' },
      { label: 'Info', path: 'counts.info_recommendations' },
      { label: 'Reliability', path: 'counts.reliability_recommendations' },
      { label: 'Spacing', path: 'counts.spacing_recommendations' },
      { label: 'Timeout', path: 'counts.timeout_recommendations' },
      { label: 'Top IDs', path: 'counts.top_recommendation_ids' },
    ],
  },
  {
    key: 'dba_telemetry',
    title: 'DBA Telemetry',
    code: 'Observe-only',
    metrics: [
      { label: 'Breaches', path: 'counts.threshold_breaches' },
      { label: 'Recommendations', path: 'counts.recommendations' },
      { label: 'ARC Rows', path: 'counts.arc_rows_total_estimate' },
      { label: 'ARC GB', path: 'counts.arc_total_gb' },
      { label: 'Scan Skipped', path: 'counts.arc_raw_recent_scan_skipped', format: formatBoolean },
      { label: 'ARC Dry Run', path: 'counts.arc_retention_dry_run_status', format: formatRuntimeState },
      { label: 'ARC Eligible', path: 'counts.arc_retention_has_eligible_rows', format: formatBoolean },
      { label: 'ARC Oldest', path: 'counts.arc_retention_oldest_eligible_at' },
      { label: 'ARC Max Rows', path: 'counts.arc_retention_max_rows' },
      { label: 'ARC Bounded', path: 'counts.arc_retention_bounded_batch_delete', format: formatBoolean },
      { label: 'ARC Count First', path: 'counts.arc_retention_count_first', format: formatBoolean },
      { label: 'PG GB', path: 'counts.postgres_database_total_gb' },
      { label: 'Dead Tuples', path: 'counts.postgres_dead_tuple_top' },
      { label: 'Redis MB', path: 'counts.redis_used_memory_mb' },
      { label: 'Redis Ratio', path: 'counts.redis_memory_ratio', format: formatPercent },
      { label: 'Fragmentation', path: 'counts.redis_fragmentation_ratio' },
      { label: 'Keys', path: 'counts.redis_key_count' },
    ],
  },
  {
    key: 'kg_backlog',
    title: 'Knowledge Graph Backlog',
    code: 'RAG / KG',
    metrics: [
      { label: 'Pending', path: 'counts.kg_pending' },
      { label: 'Fresh Pending', path: 'counts.kg_fresh_pending' },
      { label: 'Stale Pending', path: 'counts.kg_stale_pending' },
      { label: 'Throughput / Day', path: 'counts.throughput_per_day' },
      { label: 'ETA', path: 'counts.eta_days', format: formatDays },
      { label: 'Net Burn / Day', path: 'counts.kg_net_burn_per_day' },
      { label: 'Net Delta / Day', path: 'counts.kg_net_delta_avg_per_day' },
      { label: 'Trend', path: 'counts.kg_net_burn_trend' },
      { label: 'Trend Points', path: 'counts.kg_net_burn_points' },
    ],
  },
  {
    key: 'raptor_sentence_drained',
    title: 'RAPTOR / Sentence Drain',
    code: 'Index lanes',
    metrics: [
      { label: 'Drained', path: 'counts.drained', format: formatBoolean },
      { label: 'RAPTOR Pending', path: 'counts.raptor_pending' },
      { label: 'Sentence Pending', path: 'counts.sentence_pending' },
      { label: 'RAPTOR ETA', path: 'counts.raptor_eta_days', format: formatDays },
      { label: 'Sentence ETA', path: 'counts.sentence_eta_days', format: formatDays },
      { label: 'RAPTOR Net / Day', path: 'counts.raptor_net_burn_per_day' },
      { label: 'RAPTOR Trend', path: 'counts.raptor_net_burn_trend' },
      { label: 'Sentence Net / Day', path: 'counts.sentence_net_burn_per_day' },
      { label: 'Sentence Trend', path: 'counts.sentence_net_burn_trend' },
    ],
  },
  {
    key: 'rag_scale_baseline',
    title: 'RAG Scale Baseline',
    code: 'TODO-018',
    metrics: [
      { label: 'Documents', path: 'counts.documents' },
      { label: 'Relation', path: 'counts.rag_documents_relation_mb', format: formatMegabytes },
      { label: 'Index', path: 'counts.rag_documents_index_mb', format: formatMegabytes },
      { label: 'Avg Chars', path: 'counts.avg_content_chars' },
      { label: 'Max Chars', path: 'counts.max_content_chars' },
      { label: 'Compressed', path: 'counts.compressed_ratio', format: formatPercent },
      { label: 'Context', path: 'counts.contextualized_ratio', format: formatPercent },
      { label: 'Sparse', path: 'counts.sparse_documents' },
      { label: 'HYPE', path: 'counts.hype_documents' },
      { label: 'Sentence Embeds', path: 'counts.sentence_embedding_rows' },
      { label: 'KG Triples', path: 'counts.kg_triple_rows' },
      { label: 'KG Entities', path: 'counts.kg_entity_rows' },
      { label: 'KG Vectors', path: 'counts.kg_entity_embedding_rows' },
      { label: 'Missing Tables', path: 'counts.scale_tables_missing' },
      { label: 'Missing Columns', path: 'counts.missing_optional_columns' },
      { label: 'Recommendations', path: 'counts.recommendations' },
    ],
  },
  {
    key: 'genealogy_pending_approvals',
    title: 'Genealogy Proposal Approvals',
    code: 'Proposal tables',
    metrics: [
      { label: 'Pending Total', path: 'counts.pending_total' },
      { label: 'Person Changes', path: 'counts.pending_person_changes' },
      { label: 'Relationships', path: 'counts.pending_relationships' },
      { label: 'Evidence Gaps', path: 'counts.evidence_gap_count' },
      { label: 'Oldest Pending', path: 'counts.oldest_pending_age_hours', format: formatHours },
    ],
  },
  {
    key: 'genealogy_review_feedback',
    title: 'Genealogy Review Feedback',
    code: 'Reject codes',
    metrics: [
      { label: 'Window', path: 'counts.window_days', format: formatDays },
      { label: 'Rows', path: 'counts.rollup_rows' },
      { label: 'Agents', path: 'counts.agents' },
      { label: 'Reviews', path: 'counts.total_reviews' },
      { label: 'Accepted', path: 'counts.accepted_proposals' },
      { label: 'Rejected', path: 'counts.rejected_proposals' },
      { label: 'Accept Rate', path: 'counts.acceptance_rate', format: formatPercent },
      { label: 'Top Reject Codes', path: 'counts.top_reject_codes', format: formatKeyValues },
      { label: 'Latest', path: 'counts.latest_reviewed_at', format: formatTimestamp },
    ],
  },
  {
    key: 'genealogy_evidence_sprint',
    title: 'Genealogy Evidence Sprint',
    code: 'Review packets',
    metrics: [
      { label: 'State', path: 'counts.source_status' },
      { label: 'Target', path: 'counts.target_packets' },
      { label: 'Source-Backed', path: 'counts.source_backed_packets' },
      { label: 'Pending', path: 'counts.source_backed_pending' },
      { label: 'Reviewable', path: 'counts.reviewable_pending_packets' },
      { label: 'Reviewed', path: 'counts.reviewed_preview_only' },
      { label: 'Deferred', path: 'counts.deferred_packets' },
      { label: 'Clarify', path: 'counts.clarification_requested' },
      { label: 'Rejected', path: 'counts.rejected_packets' },
      { label: 'Touched', path: 'counts.operator_touched_packets' },
      { label: 'Touched Safe', path: 'counts.operator_touched_preview_only_packets' },
      { label: 'Terminal', path: 'counts.terminal_outcome_packets' },
      { label: 'Follow-Up', path: 'counts.followup_outcome_packets' },
      { label: 'Remaining', path: 'counts.remaining_to_target' },
      { label: 'Reviewable Remaining', path: 'counts.remaining_reviewable_to_target' },
      { label: 'Packet Status Gaps', path: 'counts.source_backed_pending_not_packet_pending' },
      { label: 'Preview Gaps', path: 'counts.source_backed_pending_missing_preview_only' },
      { label: 'Identity Gaps', path: 'counts.source_backed_pending_missing_identity' },
      { label: 'Privacy Gaps', path: 'counts.source_backed_pending_missing_privacy_clearance' },
      { label: 'Claim Gaps', path: 'counts.source_backed_pending_missing_claims' },
      { label: 'Validation Gaps', path: 'counts.source_backed_pending_missing_validation' },
      { label: 'Boundary Gaps', path: 'counts.source_backed_pending_missing_boundary' },
      { label: 'Details Needed', path: 'counts.needs_reviewable_packet_details', format: formatBoolean },
      { label: 'Boundary Needed', path: 'counts.needs_operator_boundary', format: formatBoolean },
      { label: 'Boundary OK', path: 'counts.boundary_consistent', format: formatBoolean },
      { label: 'Boundary Labels', path: 'counts.boundary_label_count' },
      { label: 'Missing Boundary', path: 'counts.packets_missing_boundary' },
      { label: 'Boundary Mismatch', path: 'counts.boundary_mismatch_packets' },
      { label: 'Preview Guard', path: 'counts.mutation_guard_ok', format: formatBoolean },
      { label: 'Ready', path: 'counts.ready_for_five_packet_review', format: formatBoolean },
      { label: 'Pass Recorded', path: 'counts.operator_pass_recorded', format: formatBoolean },
      { label: 'Identity', path: 'counts.packets_with_identity' },
      { label: 'Privacy', path: 'counts.packets_with_privacy_clearance' },
      { label: 'Claims', path: 'counts.packets_with_claims' },
      { label: 'Reason Codes', path: 'counts.top_reason_codes', format: formatKeyValues },
      { label: 'Errors', path: 'counts.evidence_errors' },
    ],
  },
  {
    key: 'awo_replay',
    title: 'AWO Replay',
    code: 'Agent output quality',
    metrics: [
      { label: 'Window', path: 'counts.window' },
      { label: 'Rows', path: 'counts.rows_scanned' },
      { label: 'Completed', path: 'counts.completed_reviews' },
      { label: 'Approval Worthy', path: 'counts.approval_worthy_reviews' },
      { label: 'AWO Rate', path: 'counts.approval_worthy_rate', format: formatPercent },
      { label: 'Review Yield', path: 'counts.review_approval_yield', format: formatPercent },
      { label: 'Rework Rate', path: 'counts.operator_rework_rate', format: formatPercent },
      { label: 'Hard Fails', path: 'counts.hard_fail_count' },
      { label: 'Insufficient', path: 'counts.insufficient_data', format: formatBoolean },
      { label: 'Agents', path: 'counts.by_agent_count' },
      { label: 'Promotions', path: 'counts.promotion_decisions_count' },
      { label: 'Recording', path: 'counts.recording_enabled', format: formatBoolean },
      { label: 'Scheduled Compare', path: 'counts.scheduled_comparison_status' },
      { label: 'Scheduled Run', path: 'counts.scheduled_latest_run_at', format: formatTimestamp },
      { label: 'Next Run', path: 'counts.scheduled_next_run_at', format: formatTimestamp },
      { label: 'Mismatches', path: 'counts.scheduled_field_mismatches' },
    ],
  },
  {
    key: 'agent_doctor',
    title: 'Agent Doctor',
    code: 'Observe-only agents',
    metrics: [
      { label: 'Window', path: 'counts.window_hours', format: formatHours },
      { label: 'Status', path: 'counts.overall_status' },
      { label: 'Agents', path: 'counts.agents_total' },
      { label: 'Enabled', path: 'counts.agents_enabled' },
      { label: 'Warning', path: 'counts.agents_with_warnings' },
      { label: 'Critical', path: 'counts.agents_with_critical' },
      { label: 'Sessions', path: 'counts.sessions_active' },
      { label: 'Stalled', path: 'counts.sessions_stalled' },
      { label: 'Reviews', path: 'counts.review_queue_pending' },
      { label: 'Aged Reviews', path: 'counts.review_queue_aged' },
      { label: 'Tools Missing', path: 'counts.tools_missing_total' },
      { label: 'Tools Blocked', path: 'counts.tools_blocked_total' },
      { label: 'Memory Errors', path: 'counts.memory_error_episodes_window' },
      { label: 'Undistilled', path: 'counts.memory_undistilled_episodes_window' },
      { label: 'Low Quality Procedures', path: 'counts.procedures_low_quality_total' },
      { label: 'Scheduled Success Runs', path: 'counts.scheduled_success_runs_window' },
      { label: 'Empty Success Output', path: 'counts.scheduled_empty_success_outputs_window' },
      { label: 'CJK Output Signals', path: 'counts.scheduled_cjk_output_runs_window' },
      { label: 'Guarded Output', path: 'counts.scheduled_guarded_output_runs_window' },
      { label: 'Recursion', path: 'counts.recursion_status' },
      { label: 'Move-On 7d', path: 'counts.recursion_move_on_rate_7d', format: formatPercent },
      { label: 'Trace', path: 'counts.trace_status' },
      { label: 'Trace Retention', path: 'counts.trace_retention_days', format: formatDays },
      { label: 'Over Retention', path: 'counts.trace_files_over_retention' },
      { label: 'Trace Events 24h', path: 'counts.trace_events_24h' },
      { label: 'Trace Scan', path: 'counts.trace_scan_status' },
      { label: 'Trace Writable', path: 'counts.trace_directory_writable', format: formatBoolean },
      { label: 'Malformed Trace', path: 'counts.trace_malformed_lines_24h' },
      { label: 'Critical Checks', path: 'counts.critical_checks' },
      { label: 'Warning Checks', path: 'counts.warning_checks' },
    ],
  },
  {
    key: 'review_backlog',
    title: 'Review Backlog',
    code: 'Operator decisions',
    metrics: [
      { label: 'Mode', path: 'counts.mode' },
      { label: 'Pending', path: 'counts.pending_total' },
      { label: 'Stale Pending', path: 'counts.stale_pending' },
      { label: 'High Priority', path: 'counts.high_priority_pending' },
      { label: 'Stale Window', path: 'counts.stale_days', format: formatDays },
      { label: 'Priority Floor', path: 'counts.high_priority_threshold' },
      { label: 'Oldest', path: 'counts.oldest_pending_at', format: formatTimestamp },
      { label: 'Newest', path: 'counts.newest_pending_at', format: formatTimestamp },
      { label: 'Age Groups', path: 'counts.pending_age_groups' },
      { label: 'Type Groups', path: 'counts.pending_type_groups' },
      { label: 'Agent Groups', path: 'counts.pending_agent_groups' },
      { label: 'Triage Groups', path: 'counts.triage_buckets' },
      { label: 'Age Buckets', path: 'counts.top_pending_age_buckets', format: formatKeyValues },
      { label: 'Top Types', path: 'counts.top_pending_types', format: formatKeyValues },
      { label: 'Top Agents', path: 'counts.top_pending_agents', format: formatKeyValues },
      { label: 'Triage Buckets', path: 'counts.top_triage_buckets', format: formatKeyValues },
      { label: 'Status Counts', path: 'counts.status_counts', format: formatKeyValues },
      { label: 'Typed Remediation', path: 'counts.typed_remediation_rows' },
      { label: 'Preview Only', path: 'counts.preview_only_remediation_rows' },
      { label: 'Supported Preview Ops', path: 'counts.supported_preview_operation_rows' },
      { label: 'Needs IDs', path: 'counts.remediation_without_materialized_ids' },
      { label: 'Source ID Candidates', path: 'counts.remediation_source_duplicate_candidates' },
      { label: 'Family ID Candidates', path: 'counts.remediation_family_duplicate_candidates' },
      { label: 'Source Context Rows', path: 'counts.remediation_source_proposed_change_rows' },
      { label: 'Family Context Rows', path: 'counts.remediation_family_context_rows' },
      { label: 'Malformed Details', path: 'counts.remediation_malformed_details' },
      { label: 'Type Typo Signals', path: 'counts.remediation_possible_change_type_typos', format: formatKeyValues },
      { label: 'Type Typo Fixes', path: 'counts.remediation_possible_change_type_typo_suggestions', format: formatKeyValues },
      { label: 'Remediation Types', path: 'counts.remediation_change_types', format: formatKeyValues },
      { label: 'Supported Ops', path: 'counts.remediation_supported_operations', format: formatKeyValues },
      { label: 'Packet Rows', path: 'counts.packet_rows' },
      { label: 'Packet Ready', path: 'counts.packet_ready_rows' },
      { label: 'Packet Blocked', path: 'counts.packet_blocked_rows' },
      { label: 'Packet Preview Only', path: 'counts.packet_preview_only_rows' },
      { label: 'Packet Canonical', path: 'counts.packet_canonical_mutation_rows' },
      { label: 'Packet Reasons', path: 'counts.packet_reason_code_counts', format: formatKeyValues },
      { label: 'Packet Blockers', path: 'counts.packet_blocker_code_counts', format: formatKeyValues },
      { label: 'Source Packet Handoff', path: 'counts.next_safe_handoffs.source_backed_packet.available', format: formatBoolean },
      { label: 'Source Packet State', path: 'counts.next_safe_handoffs.source_backed_packet.query_state' },
      { label: 'Source Packet Pass', path: 'counts.next_safe_handoffs.source_backed_packet.review_pass_state' },
      { label: 'Source Packet Preview', path: 'counts.next_safe_handoffs.source_backed_packet.packet_preview_only', format: formatBoolean },
      { label: 'Source Packet Canonical', path: 'counts.next_safe_handoffs.source_backed_packet.packet_canonical_mutation', format: formatBoolean },
      { label: 'Remediation Handoff', path: 'counts.next_safe_handoffs.materializable_remediation.available', format: formatBoolean },
      { label: 'Remediation State', path: 'counts.next_safe_handoffs.materializable_remediation.query_state' },
      { label: 'Remediation Status', path: 'counts.next_safe_handoffs.materializable_remediation.materialization_status' },
      { label: 'Remediation Dry Run', path: 'counts.next_safe_handoffs.materializable_remediation.dry_run_available', format: formatBoolean },
      { label: 'Remediation Apply Held', path: 'counts.next_safe_handoffs.materializable_remediation.apply_held', format: formatBoolean },
      { label: 'Recommendations', path: 'counts.recommendations' },
    ],
  },
  {
    key: 'news_bias_coverage',
    title: 'News Bias Coverage',
    code: 'Source aliases',
    metrics: [
      { label: 'Window', path: 'counts.window_days', format: formatDays },
      { label: 'Ratings', path: 'counts.bias_ratings' },
      { label: 'Aliases', path: 'counts.aliases' },
      { label: 'Active Aliases', path: 'counts.active_aliases' },
      { label: 'Orphan Aliases', path: 'counts.orphaned_aliases' },
      { label: 'Articles', path: 'counts.recent_articles' },
      { label: 'Feeds', path: 'counts.recent_feeds' },
      { label: 'Covered', path: 'counts.recent_bias_covered' },
      { label: 'Missing', path: 'counts.recent_bias_missing' },
      { label: 'Coverage', path: 'counts.recent_bias_coverage_rate', format: formatPercent },
      { label: 'Unmatched', path: 'counts.unmatched_sources' },
      { label: 'Top Unmatched', path: 'counts.top_unmatched_sources' },
    ],
  },
  {
    key: 'face_match_link_backlog',
    title: 'Face / Link Backlog',
    code: 'Media genealogy',
    metrics: [
      { label: 'Pending Total', path: 'counts.pending_total' },
      { label: 'Stale Pending', path: 'counts.stale_pending' },
      { label: 'Bridge Eligible', path: 'counts.bridge_eligible_pending' },
      { label: 'Unlinked Faces', path: 'counts.unlinked_faces' },
      { label: 'Candidate Decisions', path: 'counts.candidate_decision_rows' },
      { label: 'Terminal Decisions', path: 'counts.candidate_terminal_decisions' },
      { label: 'Recent Decisions', path: 'counts.candidate_recent_decisions' },
      { label: 'Not This Person', path: 'counts.candidate_not_this_person' },
      { label: 'Keep Name Only', path: 'counts.candidate_keep_name_only' },
      { label: 'Outside Tree', path: 'counts.candidate_outside_tree' },
      { label: 'Too Vague', path: 'counts.candidate_too_vague' },
      { label: 'Deferred', path: 'counts.candidate_deferred' },
      { label: 'Oldest Pending', path: 'counts.oldest_pending_age_hours', format: formatHours },
    ],
  },
  {
    key: 'offline_degraded_state',
    title: 'Offline / Degraded State',
    code: 'Policy receipts',
    metrics: [
      { label: 'Offline Mode', path: 'counts.offline_mode_active', format: formatBoolean },
      { label: 'Active Profile', path: 'counts.active_profile' },
      { label: 'Runtime State', path: 'counts.runtime_state', format: formatRuntimeState },
      { label: 'Audit Result', path: 'counts.audit_result' },
      { label: 'Audit Total 24h', path: 'counts.audit_total_24h' },
      { label: 'Policy Denials 24h', path: 'counts.policy_denials_24h' },
      { label: 'Mode Changes 24h', path: 'counts.mode_changes_24h' },
      { label: 'Local Status', path: 'counts.local_runtime_status', format: formatRuntimeState },
      { label: 'Local State', path: 'counts.local_availability_state', format: formatRuntimeState },
      { label: 'Local Models', path: 'counts.local_instances' },
      { label: 'Healthy Locals', path: 'counts.healthy_local_instances' },
      { label: 'Selected Host', path: 'counts.selected_local_id' },
      { label: 'Selected Model', path: 'counts.selected_local_model' },
    ],
  },
  {
    key: 'disabled_genealogy_agents',
    title: 'Disabled Genealogy Agents',
    code: 'Automation targets',
    metrics: [
      { label: 'Configured', path: 'counts.configured' },
      { label: 'Enabled', path: 'counts.enabled' },
      { label: 'Disabled', path: 'counts.disabled' },
      { label: 'No Reason', path: 'counts.disabled_without_reason' },
      { label: 'Missing', path: 'counts.missing' },
    ],
  },
  {
    key: 'genealogy_agent_triage',
    title: 'Genealogy Agent Triage',
    code: 'Re-enablement gates',
    metrics: [
      { label: 'Window', path: 'counts.window_days', format: formatDays },
      { label: 'Targets', path: 'counts.targets_total' },
      { label: 'Enabled', path: 'counts.enabled_targets' },
      { label: 'Disabled', path: 'counts.disabled_targets' },
      { label: 'Missing', path: 'counts.missing_targets' },
      { label: 'Blocked', path: 'counts.blocked_targets' },
      { label: 'Watch', path: 'counts.watch_targets' },
      { label: 'Sessions', path: 'counts.completed_sessions_window' },
      { label: 'Review Output', path: 'counts.review_outputs_window' },
      { label: 'AWO Completed', path: 'counts.awo_completed_reviews_window' },
      { label: 'AWO Worthy', path: 'counts.awo_approval_worthy_reviews_window' },
      { label: 'AWO Rate', path: 'counts.awo_approval_worthy_rate', format: formatPercent },
      { label: 'AWO Sample Met', path: 'counts.awo_sample_floor_met_targets' },
      { label: 'AWO Worthy Present', path: 'counts.awo_approval_worthy_present_targets' },
      { label: 'Packet Gate', path: 'counts.source_backed_review_packets_required_targets' },
      { label: 'Scenario Gate', path: 'counts.scenario_test_required_targets' },
      { label: 'Approval Gate', path: 'counts.operator_approval_required_targets' },
      { label: 'Review Targets', path: 'counts.targets_needing_review_count' },
      { label: 'Scheduler Allowed', path: 'counts.scheduler_enablement_allowed_targets' },
      { label: 'Prod Writeback', path: 'counts.production_writeback_allowed_targets' },
      { label: 'Canonical Writeback', path: 'counts.canonical_genealogy_writeback_allowed_targets' },
    ],
  },
  {
    key: 'agent_failures_stale_work',
    title: 'Agent / Review Backlog',
    code: 'Agent evidence',
    metrics: [
      { label: 'Pending Operator Reviews', path: 'counts.pending_reviews' },
      { label: 'Urgent Reviews', path: 'counts.urgent_pending_reviews' },
      { label: 'Review Types', path: 'counts.review_type_breakdown', format: formatKeyValues },
      { label: 'Stale Sessions', path: 'counts.stale_active_sessions' },
      { label: 'Agent Errors 24h', path: 'counts.recent_agent_errors_24h' },
      { label: 'Failed Agent Jobs 24h', path: 'counts.recent_failed_agent_jobs_24h' },
      { label: 'Recent Failures', path: 'counts.recent_failures', format: formatArrayCount },
      { label: 'Top Error Agents', path: 'counts.agent_failures_by_agent_24h', format: formatKeyValues },
    ],
  },
]

const sections = computed(() => {
  const value = payload.value?.sections
  return isRecord(value) ? value : {}
})

const overallStatus = computed(() => payload.value?.status || 'unavailable')
const capturedAt = computed(() => formatTimestamp(payload.value?.captured_at))
const offlineSection = computed(() => {
  const section = sections.value.offline_degraded_state
  return isRecord(section) ? section : null
})
const offlineCounts = computed(() => {
  const counts = offlineSection.value?.counts
  return isRecord(counts) ? counts : {}
})
const offlineStatus = computed(() => offlineSection.value?.status || 'unavailable')
const runtimeModeLabel = computed(() => {
  const state = formatRuntimeState(offlineCounts.value.runtime_state || 'unknown')
  const profile = hasValue(offlineCounts.value.active_profile)
    ? String(offlineCounts.value.active_profile)
    : 'unknown'

  return `${state} / ${profile}`
})
const runtimeModeTitle = computed(() => {
  const offline = formatBoolean(Boolean(offlineCounts.value.offline_mode_active))
  return `offline=${offline}; profile=${offlineCounts.value.active_profile || 'unknown'}; state=${offlineCounts.value.runtime_state || 'unknown'}`
})
const recentFailures = computed(() => {
  const rows = getPath(sections.value.agent_failures_stale_work, 'counts.recent_failures')
  return Array.isArray(rows) ? rows.filter(isRecord).slice(0, 3) : []
})
const offlineCapabilities = computed(() => {
  const capabilities = offlineCounts.value.capabilities
  if (!isRecord(capabilities)) return []

  return [
    { label: 'Tools', value: formatList(capabilities.tool_classes) },
    { label: 'MCP Trust', value: formatList(capabilities.mcp_trust) },
    { label: 'Paths', value: formatList(capabilities.path_classes) },
    { label: 'Providers', value: formatList(capabilities.provider_classes) },
    { label: 'Remote Domains', value: formatList(capabilities.remote_domain_classes) },
    { label: 'Confirm', value: formatList(capabilities.confirmation_required_for) },
  ]
})
const recentOfflineEvents = computed(() => {
  const rows = offlineCounts.value.recent_audit_events
  return Array.isArray(rows) ? rows.filter(isRecord).slice(0, 3) : []
})

const sectionCards = computed(() => SECTION_DEFINITIONS.map((definition) => {
  const raw = sections.value[definition.key]
  const section = isRecord(raw) ? raw : null

  return {
    ...definition,
    status: section?.status || 'unavailable',
    sampledAt: formatTimestamp(section?.sampled_at),
    version: payload.value?.version,
    nextAction: section?.next_action || (section ? '' : 'Evidence section unavailable from current payload.'),
    metrics: definition.metrics.map((metric) => ({
      label: metric.label,
      value: formatMetric(section, metric),
    })),
  }
}))

onMounted(() => {
  refresh()
})

async function refresh() {
  if (loading.value) return

  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/ops/operator-evidence')
    const wrappedResponse = isRecord(response)
      && Object.prototype.hasOwnProperty.call(response, 'success')
      && Object.prototype.hasOwnProperty.call(response, 'data')

    if (wrappedResponse && response.success === false) {
      throw new Error(response.message || 'Operator evidence API returned an unsuccessful response.')
    }

    const nextPayload = wrappedResponse ? response.data : response
    if (!isRecord(nextPayload)) {
      throw new Error('Operator evidence API returned an invalid payload.')
    }

    payload.value = nextPayload
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

function topMetric(sectionKey, path, formatter = formatValue) {
  const section = sections.value[sectionKey]
  const value = getPath(section, path)
  return formatMetricValue(value, formatter)
}

function formatMetric(section, metric) {
  if (!section) return 'unavailable'

  const value = getPath(section, metric.path)
  return formatMetricValue(value, metric.format || formatValue)
}

function formatMetricValue(value, formatter) {
  if (!hasValue(value)) return 'unknown'
  return formatter(value)
}

function formatValue(value) {
  if (typeof value === 'boolean') return formatBoolean(value)
  if (typeof value === 'number') return formatNumber(value)
  if (Array.isArray(value)) return value.length ? value.join(', ') : 'none'
  return String(value)
}

function formatList(value) {
  return Array.isArray(value) && value.length
    ? value.map((entry) => formatRuntimeState(entry)).join(', ')
    : 'none'
}

function formatNumber(value) {
  const number = Number(value)
  return Number.isFinite(number) ? number.toLocaleString() : String(value)
}

function formatBoolean(value) {
  return value ? 'yes' : 'no'
}

function formatArrayCount(value) {
  return Array.isArray(value) ? formatNumber(value.length) : formatValue(value)
}

function formatKeyValues(value) {
  if (!isRecord(value)) return formatValue(value)
  const entries = Object.entries(value)
  if (!entries.length) return 'none'

  return entries.map(([key, count]) => `${key}: ${formatNumber(count)}`).join(', ')
}

function formatDays(value) {
  const number = Number(value)
  if (!Number.isFinite(number)) return String(value)
  return `${formatNumber(number)} ${number === 1 ? 'day' : 'days'}`
}

function formatHours(value) {
  const number = Number(value)
  if (!Number.isFinite(number)) return String(value)
  return `${formatNumber(number)} ${number === 1 ? 'hour' : 'hours'}`
}

function formatMinutes(value) {
  const number = Number(value)
  if (!Number.isFinite(number)) return String(value)
  return `${formatNumber(number)} min`
}

function formatMegabytes(value) {
  const number = Number(value)
  if (!Number.isFinite(number)) return String(value)
  return `${formatNumber(number)} MB`
}

function formatPercent(value) {
  const number = Number(value)
  if (!Number.isFinite(number)) return String(value)
  return `${formatNumber(Math.round(number * 100))}%`
}

function formatRuntimeState(value) {
  return String(value || 'unknown').replaceAll('_', ' ')
}

function offlineEventSummary(event) {
  const parts = [
    event.operation,
    event.tool_class,
    event.provider_class,
    event.remote_domain_class,
    event.reason,
  ].filter(hasValue)

  return parts.length ? parts.join(' / ') : 'No event detail'
}

function formatTimestamp(value) {
  if (!hasValue(value)) return 'unknown'

  const formatted = formatDate(value)
  return formatted === 'Invalid Date' || formatted === 'Error' || formatted === 'N/A'
    ? String(value)
    : formatted
}

function getPath(source, path) {
  if (!isRecord(source)) return undefined

  return path.split('.').reduce((current, key) => {
    if (!isRecord(current) && !Array.isArray(current)) return undefined
    return current?.[key]
  }, source)
}

function hasValue(value) {
  return value !== null && value !== undefined && value !== ''
}

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function normalizedStatus(status) {
  const value = String(status || 'unknown').toLowerCase()
  return ['healthy', 'watch', 'degraded', 'blocked', 'stale', 'unavailable', 'unknown'].includes(value)
    ? value
    : 'unknown'
}

function statusLabel(status) {
  return normalizedStatus(status).replace('_', ' ')
}

function statusBadgeClass(status) {
  const normalized = normalizedStatus(status)
  if (normalized === 'healthy') return 'bg-ops-green/20 text-ops-green border-ops-green/50'
  if (normalized === 'watch') return 'bg-ops-butterscotch/20 text-ops-butterscotch border-ops-butterscotch/50'
  if (normalized === 'degraded') return 'bg-ops-orange/20 text-ops-orange border-ops-orange/50'
  return 'bg-ops-alert/20 text-ops-alert border-ops-alert/50'
}

function statusDotClass(status) {
  const normalized = normalizedStatus(status)
  if (normalized === 'healthy') return 'bg-ops-green'
  if (normalized === 'watch') return 'bg-ops-butterscotch'
  if (normalized === 'degraded') return 'bg-ops-orange'
  return 'bg-ops-alert'
}

function sectionBorderClass(status) {
  const normalized = normalizedStatus(status)
  if (normalized === 'healthy') return 'border-ops-green/60'
  if (normalized === 'watch') return 'border-ops-butterscotch/70'
  if (normalized === 'degraded') return 'border-ops-orange/70'
  return 'border-ops-alert/70'
}

function errorMessage(e) {
  return e?.response?.data?.message || e?.message || 'Unable to load operator evidence.'
}
</script>

<template>
  <div class="finding-detail">
    <ConflictResolutionBar :field-diffs="fieldDiffs" />

    <div class="detail-header">
      <h3 class="detail-title">{{ context.item.title || 'Untitled finding' }}</h3>
      <div class="detail-meta">
        <span v-if="context.item.confidence !== null" class="meta-pill" :class="confidenceClass">
          {{ Math.round(context.item.confidence * 100) }}%
        </span>
        <span class="meta-pill meta-agent">{{ context.item.agent_id }}</span>
        <span v-if="context.proposals_summary?.total" class="meta-pill meta-count">
          {{ context.proposals_summary.total }} proposal{{ context.proposals_summary.total === 1 ? '' : 's' }}
        </span>
      </div>
    </div>

    <div class="detail-grid">
      <!-- ON FILE column -->
      <section class="detail-col">
        <div class="col-heading">On file</div>
        <PersonSnapshotCard :person="context.person" />
      </section>

      <!-- PROPOSED column -->
      <section class="detail-col">
        <div class="col-heading">Proposed</div>

        <!-- Prominent change card for change_proposal items: actual
             field/current/proposed visible without scrolling to the
             diff table. Falls back to summary text for other types. -->
        <div v-if="changeProposalView" class="proposed-change-card">
          <div class="proposed-change-header">
            <span class="proposed-change-type">{{ changeProposalView.changeType }}</span>
            <span v-if="changeProposalView.fieldName" class="proposed-change-field">
              {{ changeProposalView.fieldName }}
            </span>
          </div>
          <div class="proposed-change-grid">
            <div class="proposed-change-row">
              <div class="proposed-change-label">Current</div>
              <div class="proposed-change-value muted">
                {{ changeProposalView.currentValue || '—' }}
              </div>
            </div>
            <div class="proposed-change-row">
              <div class="proposed-change-label">Proposed</div>
              <div class="proposed-change-value highlight">
                {{ changeProposalView.proposedValue }}
              </div>
            </div>
          </div>
        </div>

        <div class="proposed-summary">
          <div v-if="context.item.summary" class="summary-text">{{ context.item.summary }}</div>
          <div v-else class="text-sm text-ops-text-muted italic">No summary available.</div>
        </div>

        <!-- Source media references (e.g., "media #13986") resolved
             to clickable view links. Operator can verify the source
             document/image without leaving the page. -->
        <div v-if="context.media_refs && context.media_refs.length" class="media-refs">
          <div class="media-refs-heading">Source media</div>
          <a
            v-for="m in context.media_refs"
            :key="m.id"
            :href="m.view_url || '#'"
            target="_blank"
            rel="noopener"
            class="media-ref-card"
            :class="{ 'media-ref-disabled': !m.view_url || !m.file_exists }"
            :title="m.nextcloud_path || ''"
          >
            <div class="media-ref-icon">{{ mediaIcon(m) }}</div>
            <div class="media-ref-meta">
              <div class="media-ref-title">{{ m.title }}</div>
              <div class="media-ref-sub">
                #{{ m.id }} · {{ m.file_format || m.mime_type || 'unknown' }}
                <span v-if="m.media_type">· {{ m.media_type }}</span>
                <span v-if="!m.file_exists" class="text-ops-red"> · file missing</span>
              </div>
            </div>
            <div class="media-ref-arrow">↗</div>
          </a>
        </div>
      </section>
    </div>

    <!-- Field-by-field diff table — Phase 3: per-field decisions wired -->
    <section v-if="fieldDiffs.length" class="diff-section">
      <div class="diff-heading">
        <div class="col-label">Field</div>
        <div class="col-label">On file</div>
        <div class="col-label">Proposed</div>
        <div class="col-label col-status">Status</div>
      </div>
      <FieldCompareRow
        v-for="(diff, idx) in fieldDiffs"
        :key="idx"
        :diff="diff"
        :classification="classificationForIndex(idx)"
        :interactive="canPerFieldApply"
        :decision="decisions[idx]?.decision || null"
        :reason-code="decisions[idx]?.reasonCode || 'other'"
        :conflict-choice="decisions[idx]?.conflictChoice || 'on_file'"
        @update:decision="setDecision(idx, $event)"
        @update:reason-code="setReasonCode(idx, $event)"
        @update:conflict-choice="setConflictChoice(idx, $event)"
      />
    </section>
    <section v-else class="diff-section empty">
      <div class="text-sm text-ops-text-muted italic">No structured field diffs available for this proposal.</div>
    </section>

    <!-- Phase 2: FAN cluster overlap (only when there are matches) -->
    <FANClusterOverlap :overlap="context.fan_overlap || []" />

    <!-- Phase 2: agent reasoning, drivers, search coverage (collapsed by default) -->
    <AgentReasoningPanel :reasoning="context.agent_reasoning || {}" />

    <!-- Phase 3: per-field decision summary + free-text notes + apply -->
    <div v-if="canPerFieldApply" class="phase3-summary">
      <div class="summary-row">
        <span class="summary-stat accept">{{ acceptedCount }} accept</span>
        <span class="summary-stat reject">{{ rejectedCount }} reject</span>
        <span class="summary-stat skip">{{ skippedCount }} undecided</span>
      </div>
      <div v-if="skippedCount > 0" class="summary-pending-note">
        ↳ {{ skippedCount }} undecided will keep this item <strong>pending</strong> for follow-up.
        Mark every row to fully approve.
      </div>
      <textarea
        v-model="freeTextNotes"
        rows="2"
        placeholder="Reviewer notes (optional) — captured into agent_review_queue.reviewer_notes"
        class="summary-notes"
      ></textarea>
      <div v-if="applyError" class="summary-error">{{ applyError }}</div>
    </div>

    <!-- Action footer -->
    <div class="detail-actions">
      <button
        v-if="canPerFieldApply"
        type="button"
        class="ops-btn ops-btn-sky"
        :disabled="actioning || !hasAnyDecision"
        @click="applyPerField"
        :title="hasAnyDecision ? 'POST per-field decisions to /apply-fields' : 'Mark at least one row accept or reject'"
      >Apply selected ({{ acceptedCount + rejectedCount }})</button>
      <button
        type="button"
        class="ops-btn ops-btn-green"
        :disabled="actioning"
        @click="$emit('approve', context.item.unified_id)"
      >Approve all</button>
      <button
        type="button"
        class="ops-btn ops-btn-red"
        :disabled="actioning"
        @click="$emit('reject', context.item.unified_id)"
      >Reject all</button>
      <button
        type="button"
        class="ops-btn ops-btn-plum"
        :disabled="actioning"
        @click="$emit('close')"
      >Close detail</button>
      <span v-if="actioning" class="text-xs text-ops-text-muted ml-2">working…</span>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import axios from 'axios'
import ConflictResolutionBar from './ConflictResolutionBar.vue'
import PersonSnapshotCard from './PersonSnapshotCard.vue'
import FieldCompareRow from './FieldCompareRow.vue'
import FANClusterOverlap from './FANClusterOverlap.vue'
import AgentReasoningPanel from './AgentReasoningPanel.vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
})

const emit = defineEmits(['approve', 'reject', 'close', 'applied'])

const fieldDiffs = computed(() => props.context?.comparison?.field_diffs ?? [])

const classifications = computed(() => props.context?.source_classifications ?? [])

function classificationForIndex(idx) {
  // The service emits classifications in the same proposal order as field_diffs,
  // and tags each with proposal_index. Match by index, fall back to position.
  const arr = classifications.value
  if (!Array.isArray(arr) || arr.length === 0) return null
  const exact = arr.find((c) => c.proposal_index === idx)
  return exact || arr[idx] || null
}

// Operator UX gap fix: change_proposal items carry exactly one
// proposal each, with current_value already known on the row.
// Surface field/current/proposed at the top of the PROPOSED column
// instead of burying it in the diff table below.
const changeProposalView = computed(() => {
  if (props.context?.item?.review_type !== 'change_proposal') return null
  const proposals = props.context?.item?.details?.proposals
  if (!Array.isArray(proposals) || proposals.length !== 1) return null
  const p = proposals[0]
  // current_value lives at the top of details (synthesized on the
  // backend from genealogy_proposed_changes.current_value).
  const currentValue = props.context?.item?.details?.on_file_value ?? null
  return {
    changeType: p.change_type || 'change',
    fieldName: p.field_name || null,
    currentValue: currentValue,
    proposedValue: formatProposedValue(p.proposed_value),
  }
})

function formatProposedValue(v) {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'string') {
    // Try to detect JSON and pretty-print briefly.
    if ((v.startsWith('{') && v.endsWith('}')) || (v.startsWith('[') && v.endsWith(']'))) {
      try {
        const parsed = JSON.parse(v)
        return Object.entries(parsed)
          .filter(([_, val]) => val !== null && val !== '')
          .map(([k, val]) => `${k}: ${val}`)
          .join(' · ') || v
      } catch (e) { return v }
    }
    return v
  }
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

function mediaIcon(m) {
  const type = (m.media_type || '').toLowerCase()
  const fmt = (m.file_format || '').toLowerCase()
  const mime = (m.mime_type || '').toLowerCase()
  if (type === 'photo' || mime.startsWith('image/')) return '🖼'
  if (type === 'video' || mime.startsWith('video/')) return '🎬'
  if (type === 'audio' || mime.startsWith('audio/')) return '🔊'
  if (fmt === 'pdf' || mime === 'application/pdf') return '📄'
  if (fmt === 'htm' || fmt === 'html' || mime === 'text/html') return '🌐'
  if (type === 'document' || type === 'certificate' || type === 'obituary') return '📄'
  return '📎'
}

// ============================================================
// Phase 3 — per-field decisions
// ============================================================
const decisions = reactive({})
const freeTextNotes = ref('')
const applyError = ref(null)

// Per-field accept is wired only for genealogy_finding (Phase 3 scope).
const canPerFieldApply = computed(
  () => props.context?.item?.review_type === 'genealogy_finding'
       && fieldDiffs.value.length > 0
)

watch(() => props.context?.item?.unified_id, () => {
  // Reset decision state when the operator switches to a different item.
  for (const k of Object.keys(decisions)) delete decisions[k]
  freeTextNotes.value = ''
  applyError.value = null
})

function ensureDecision(idx) {
  if (!decisions[idx]) {
    decisions[idx] = { decision: null, reasonCode: 'other', conflictChoice: 'on_file' }
  }
  return decisions[idx]
}
function setDecision(idx, value) {
  ensureDecision(idx).decision = value
}
function setReasonCode(idx, value) {
  ensureDecision(idx).reasonCode = value
}
function setConflictChoice(idx, value) {
  ensureDecision(idx).conflictChoice = value
}

const acceptedCount = computed(
  () => Object.values(decisions).filter((d) => d.decision === 'accept').length
)
const rejectedCount = computed(
  () => Object.values(decisions).filter((d) => d.decision === 'reject').length
)
const skippedCount = computed(
  () => Math.max(0, fieldDiffs.value.length - acceptedCount.value - rejectedCount.value)
)
const hasAnyDecision = computed(() => acceptedCount.value > 0 || rejectedCount.value > 0)

async function applyPerField() {
  if (!hasAnyDecision.value) return
  applyError.value = null

  const accepted_indices = []
  const rejected_indices = []
  const reject_reason_codes = {}
  const conflict_resolutions = {}

  for (const [idxStr, d] of Object.entries(decisions)) {
    const idx = Number(idxStr)
    if (d.decision === 'accept') {
      accepted_indices.push(idx)
      if (fieldDiffs.value[idx]?.match_status === 'conflict') {
        conflict_resolutions[idx] = d.conflictChoice || 'on_file'
      }
    } else if (d.decision === 'reject') {
      rejected_indices.push(idx)
      reject_reason_codes[idx] = d.reasonCode || 'other'
    }
  }

  try {
    const url = `/api/research-hub/items/${encodeURIComponent(props.context.item.unified_id)}/apply-fields`
    const { data } = await axios.post(url, {
      accepted_indices,
      rejected_indices,
      reject_reason_codes,
      conflict_resolutions,
      notes: freeTextNotes.value || null,
    })
    // F-03 fix: emit `applied` for any PROCESSED response (success OR
    // partial-pending). The parent (ResearchHubView.onDetailApplied)
    // keys off result.final_status to decide whether to remove the
    // item from the list (final_status='approved') or keep it visible
    // with a partial toast (final_status='pending'). Pre-fix the
    // partial-pending branch was unreachable because emit was gated
    // on data.success which is false whenever undecided > 0.
    // Genuine input errors carry no final_status — those land in the
    // else branch with a meaningful message instead of the literal
    // string "apply-fields failed".
    if (data.final_status) {
      emit('applied', { unifiedId: props.context.item.unified_id, result: data })
    } else {
      const errMsg = Array.isArray(data.errors) && data.errors.length
        ? data.errors.join('; ')
        : (data.error || 'apply-fields rejected (no final_status returned)')
      applyError.value = errMsg
    }
  } catch (e) {
    applyError.value = e.response?.data?.error || e.message || 'apply-fields network error'
  }
}

const confidenceClass = computed(() => {
  const c = props.context?.item?.confidence
  if (c === null || c === undefined) return ''
  if (c >= 0.8) return 'conf-high'
  if (c >= 0.5) return 'conf-med'
  return 'conf-low'
})
</script>

<style scoped>
.finding-detail { display: flex; flex-direction: column; gap: 1rem; }
.detail-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.detail-title {
  font-size: 1.1rem;
  font-weight: 700;
  color: #ffb47a;
  margin: 0;
}
.detail-meta { display: flex; gap: 0.4rem; align-items: center; }
.meta-pill {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.15rem 0.5rem;
  border-radius: 0.25rem;
}
.meta-agent { background: rgba(99, 51, 153, 0.30); color: #d4c2f0; }
.meta-count { background: rgba(99, 179, 237, 0.20); color: #bfe1ff; }
.conf-high  { background: rgba(0, 170, 0, 0.30);    color: #b5f5b5; }
.conf-med   { background: rgba(204, 136, 0, 0.30);  color: #ffd980; }
.conf-low   { background: rgba(204, 0, 0, 0.30);    color: #ffb5b5; }

.detail-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.75rem;
}
@media (min-width: 768px) {
  .detail-grid { grid-template-columns: 1fr 1fr; }
}
.detail-col { display: flex; flex-direction: column; gap: 0.5rem; }
.col-heading {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.proposed-summary {
  background: rgba(255, 180, 122, 0.08);
  border: 1px solid rgba(255, 180, 122, 0.25);
  border-radius: 0.5rem;
  padding: 1rem;
  min-height: 4rem;
}
.summary-text { color: #ffe5b3; font-size: 0.85rem; line-height: 1.4; white-space: pre-wrap; }

.proposed-change-card {
  background: rgba(255, 180, 122, 0.12);
  border: 1px solid rgba(255, 180, 122, 0.40);
  border-left: 4px solid #ffb47a;
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
  margin-bottom: 0.5rem;
}
.proposed-change-header { display: flex; gap: 0.5rem; align-items: baseline; margin-bottom: 0.5rem; }
.proposed-change-type {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #ffb47a;
  background: rgba(255, 180, 122, 0.20);
  padding: 0.1rem 0.5rem;
  border-radius: 0.25rem;
}
.proposed-change-field { font-size: 0.85rem; color: #ffe5b3; font-weight: 600; }
.proposed-change-grid { display: grid; gap: 0.4rem; }
.proposed-change-row { display: grid; grid-template-columns: 5rem 1fr; gap: 0.5rem; align-items: baseline; }
.proposed-change-label {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.proposed-change-value { font-size: 0.9rem; word-break: break-word; }
.proposed-change-value.muted { color: #888; font-style: italic; }
.proposed-change-value.highlight { color: #ffe5b3; font-weight: 600; }

.media-refs {
  margin-top: 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.media-refs-heading {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.media-ref-card {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.75rem;
  background: rgba(99, 179, 237, 0.10);
  border: 1px solid rgba(99, 179, 237, 0.30);
  border-radius: 0.375rem;
  text-decoration: none;
  color: inherit;
  transition: background 0.1s;
}
.media-ref-card:hover { background: rgba(99, 179, 237, 0.20); }
.media-ref-disabled { opacity: 0.5; pointer-events: none; }
.media-ref-icon { font-size: 1.5rem; line-height: 1; }
.media-ref-meta { flex: 1; min-width: 0; }
.media-ref-title { font-size: 0.85rem; color: #ffe5b3; font-weight: 600; word-break: break-word; }
.media-ref-sub { font-size: 0.7rem; color: #b39ddb; margin-top: 0.1rem; }
.media-ref-arrow { color: #5da9ff; font-size: 1rem; }

.diff-section {
  background: rgba(0, 0, 0, 0.20);
  border: 1px solid rgba(102, 102, 102, 0.30);
  border-radius: 0.5rem;
  overflow: hidden;
}
.diff-section.empty { padding: 1rem; }
.diff-heading {
  display: grid;
  grid-template-columns: 8rem 1fr 1.5fr auto;
  gap: 0.75rem;
  padding: 0.4rem 0.5rem;
  background: rgba(99, 51, 153, 0.20);
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.col-status { text-align: right; }
.detail-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.phase3-summary {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem;
  background: rgba(99, 51, 153, 0.10);
  border: 1px solid rgba(99, 51, 153, 0.30);
  border-radius: 0.5rem;
}
.summary-row { display: flex; gap: 0.5rem; align-items: center; }
.summary-stat {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
  padding: 0.15rem 0.5rem;
  border-radius: 0.25rem;
}
.summary-stat.accept { background: rgba(0, 170, 0, 0.20); color: #b5f5b5; }
.summary-stat.reject { background: rgba(204, 0, 0, 0.20); color: #ffb5b5; }
.summary-stat.skip   { background: rgba(128, 128, 128, 0.20); color: #aaa; }
.summary-pending-note {
  font-size: 0.7rem;
  color: #ffd980;
  background: rgba(204, 136, 0, 0.10);
  padding: 0.4rem 0.6rem;
  border-radius: 0.25rem;
  border-left: 3px solid #cc8800;
}
.summary-pending-note strong { color: #ffd980; }
.summary-notes {
  width: 100%;
  background: rgba(0, 0, 0, 0.30);
  color: #f0e6ff;
  border: 1px solid rgba(102, 102, 102, 0.40);
  border-radius: 0.25rem;
  padding: 0.4rem 0.6rem;
  font-size: 0.85rem;
  font-family: inherit;
  resize: vertical;
}
.summary-error {
  color: #ffb5b5;
  font-size: 0.8rem;
  background: rgba(204, 0, 0, 0.10);
  padding: 0.4rem 0.6rem;
  border-radius: 0.25rem;
}
</style>

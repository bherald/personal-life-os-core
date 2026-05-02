<template>
  <div class="packet-detail">
    <div class="packet-header">
      <div class="packet-heading-main">
        <h3 class="packet-title">{{ item.title || packetTitle || 'Genealogy review packet' }}</h3>
        <div v-if="item.summary" class="packet-summary">{{ item.summary }}</div>
      </div>
      <div class="packet-meta">
        <span class="meta-pill meta-type">packet</span>
        <span class="meta-pill" :class="statusClass">{{ item.status || 'pending' }}</span>
        <span v-if="confidencePercent !== null" class="meta-pill" :class="confidenceClass">
          {{ confidencePercent }}%
        </span>
      </div>
    </div>

    <section class="packet-section">
      <div class="section-heading">
        <span>Source locators</span>
        <span v-if="sourceLocators.length" class="section-count">{{ sourceLocators.length }}</span>
      </div>
      <div v-if="sourceLocators.length" class="locator-list">
        <template v-for="(locator, idx) in sourceLocators" :key="`locator-${idx}-${locator}`">
          <a
            v-if="locatorHref(locator)"
            :href="locatorHref(locator)"
            target="_blank"
            rel="noopener noreferrer"
            class="locator-row"
          >
            {{ locator }}
          </a>
          <div v-else class="locator-row">
            {{ locator }}
          </div>
        </template>
      </div>
      <div v-else class="empty-line">No source locator supplied.</div>

      <div v-if="sources.length" class="source-payloads">
        <div class="subheading">Source payloads</div>
        <div v-for="(source, idx) in sources" :key="sourceKey(source, idx)" class="source-row">
          <div class="source-row-title">{{ source.locator || source.source_locator || source.url || source.path || `Source ${idx + 1}` }}</div>
          <div class="kv-grid compact">
            <div v-for="row in kvRows(source)" :key="row.key" class="kv-row">
              <span class="kv-key">{{ row.label }}</span>
              <span class="kv-value">{{ row.value }}</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="packet-section">
      <div class="section-heading">
        <span>Claims</span>
        <span v-if="claims.length" class="section-count">{{ claims.length }}</span>
      </div>
      <div v-if="claims.length" class="claim-list">
        <div v-for="(claim, idx) in claims" :key="claimKey(claim, idx)" class="claim-row">
          <div class="claim-topline">
            <span class="claim-index">#{{ claim.index ?? idx }}</span>
            <span v-if="claim.change_type" class="claim-pill">{{ claim.change_type }}</span>
            <span v-if="claim.field_name" class="claim-pill muted">{{ claim.field_name }}</span>
            <span v-if="claim.relationship_type" class="claim-pill muted">{{ claim.relationship_type }}</span>
          </div>
          <div class="claim-text">{{ claimText(claim) || 'No claim text supplied.' }}</div>
          <div class="claim-meta">
            <span v-if="claim.person_id">person #{{ claim.person_id }}</span>
            <span v-if="claim.source_ref">source {{ claim.source_ref }}</span>
            <span v-if="claimConfidence(claim) !== null">{{ claimConfidence(claim) }}% confidence</span>
          </div>
        </div>
      </div>
      <div v-else class="empty-line">No packet claims supplied.</div>
    </section>

    <div class="packet-two-col">
      <section class="packet-section">
        <div class="section-heading">
          <span>Identity</span>
        </div>
        <div v-if="identityRows.length" class="kv-grid">
          <div v-for="row in identityRows" :key="row.key" class="kv-row">
            <span class="kv-key">{{ row.label }}</span>
            <span class="kv-value">{{ row.value }}</span>
          </div>
        </div>
        <div v-else class="empty-line">No identity payload supplied.</div>
      </section>

      <section class="packet-section">
        <div class="section-heading">
          <span>Privacy</span>
        </div>
        <div v-if="privacyRows.length" class="kv-grid">
          <div v-for="row in privacyRows" :key="row.key" class="kv-row">
            <span class="kv-key">{{ row.label }}</span>
            <span class="kv-value" :class="booleanValueClass(row.raw)">{{ row.value }}</span>
          </div>
        </div>
        <div v-else class="empty-line">No privacy payload supplied.</div>
      </section>
    </div>

    <section class="packet-section">
      <div class="section-heading">
        <span>Validation</span>
        <span class="section-status" :class="validationStatusClass">{{ validationStatus }}</span>
      </div>
      <div class="validation-grid">
        <div class="validation-col">
          <div class="subheading danger">Errors</div>
          <div v-if="validationErrors.length" class="issue-list">
            <div v-for="(issue, idx) in validationErrors" :key="issueKey(issue, idx)" class="issue-row danger">
              <span v-if="issueGate(issue)" class="issue-gate">{{ issueGate(issue) }}</span>
              <span v-if="issueCode(issue)" class="issue-code">{{ issueCode(issue) }}</span>
              <span class="issue-message">{{ issueMessage(issue) }}</span>
            </div>
          </div>
          <div v-else class="empty-line">No validation errors.</div>
        </div>
        <div class="validation-col">
          <div class="subheading warning">Warnings</div>
          <div v-if="validationWarnings.length" class="issue-list">
            <div v-for="(issue, idx) in validationWarnings" :key="issueKey(issue, idx)" class="issue-row warning">
              <span v-if="issueGate(issue)" class="issue-gate">{{ issueGate(issue) }}</span>
              <span v-if="issueCode(issue)" class="issue-code">{{ issueCode(issue) }}</span>
              <span class="issue-message">{{ issueMessage(issue) }}</span>
            </div>
          </div>
          <div v-else class="empty-line">No validation warnings.</div>
        </div>
      </div>
    </section>

    <section class="packet-section">
      <div class="section-heading">
        <span>Apply preview</span>
        <span class="section-status preview">preview only</span>
      </div>
      <div class="preview-note">
        mutates_accepted_facts:
        <strong :class="applyPreviewMutates ? 'text-danger' : 'text-ok'">{{ String(applyPreviewMutates) }}</strong>
      </div>
      <div class="preview-note muted">
        Accepted facts are not changed by this packet preview.
      </div>

      <div v-if="applyPreviewSummaryRows.length" class="kv-grid compact preview-summary">
        <div v-for="row in applyPreviewSummaryRows" :key="row.key" class="kv-row">
          <span class="kv-key">{{ row.label }}</span>
          <span class="kv-value">{{ row.value }}</span>
        </div>
      </div>

      <div v-if="previewOperations.length" class="operation-list">
        <div v-for="(operation, idx) in previewOperations" :key="operationKey(operation, idx)" class="operation-row">
          <div class="operation-head">
            <span class="operation-name">{{ operation.operation || `operation ${idx + 1}` }}</span>
            <span v-if="operation.target_table" class="operation-target">{{ operation.target_table }}</span>
          </div>
          <div class="kv-grid compact">
            <div v-for="row in operationRows(operation)" :key="row.key" class="kv-row">
              <span class="kv-key">{{ row.label }}</span>
              <span class="kv-value">{{ row.value }}</span>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="empty-line">No preview operations.</div>
    </section>

    <section class="packet-section">
      <div class="section-heading">
        <span>Decision log</span>
        <span v-if="decisionLog.length" class="section-count">{{ decisionLog.length }}</span>
      </div>
      <div v-if="decisionLog.length" class="decision-list">
        <div v-for="(entry, idx) in decisionLog" :key="decisionKey(entry, idx)" class="decision-row">
          <div class="decision-head">
            <span class="decision-action">{{ entry.action || 'event' }}</span>
            <span v-if="entry.actor" class="decision-actor">{{ entry.actor }}</span>
            <span v-if="entry.created_at" class="decision-time">{{ formatDate(entry.created_at) }}</span>
          </div>
          <div v-if="entry.note || entry.notes" class="decision-note">{{ entry.note || entry.notes }}</div>
          <pre v-if="entry.meta && objectKeys(entry.meta).length" class="inline-json">{{ formatJson(entry.meta) }}</pre>
        </div>
      </div>
      <div v-else class="empty-line">No decision log entries yet.</div>
    </section>

    <section class="packet-section decision-panel">
      <div class="section-heading">
        <span>Packet decision</span>
        <span class="section-status preview">preview only</span>
      </div>
      <textarea
        v-model="decisionNotes"
        class="decision-notes"
        rows="3"
        placeholder="Decision notes"
        :disabled="actioning"
      ></textarea>
      <label class="decision-reason">
        <span>Reject/Clarify/Defer reason</span>
        <select v-model="decisionReasonCode" class="decision-reason-select" :disabled="softDecisionDisabled">
          <option value="">No reason code</option>
          <option v-for="option in PACKET_REASON_OPTIONS" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <div class="decision-actions">
        <button class="decision-button approve" :disabled="approveDisabled" @click="emitDecision('approve')">
          Approve Preview
        </button>
        <button class="decision-button reject" :disabled="softDecisionDisabled" @click="emitDecision('reject')">
          Reject
        </button>
        <button class="decision-button clarify" :disabled="softDecisionDisabled" @click="emitDecision('clarify')">
          Clarify
        </button>
        <button class="decision-button defer" :disabled="softDecisionDisabled" @click="emitDecision('defer')">
          Defer
        </button>
      </div>
      <div v-if="approveBlockedByValidation" class="approve-blocked-note">
        Resolve {{ validationErrors.length }} validation {{ validationErrors.length === 1 ? 'error' : 'errors' }} before approving preview, or use Clarify/Reject.
      </div>
    </section>

    <details class="raw-details">
      <summary>Raw details JSON</summary>
      <pre>{{ formatJson(details) }}</pre>
    </details>

    <details class="raw-details">
      <summary>Packet JSON</summary>
      <pre>{{ formatJson(packet) }}</pre>
    </details>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
  decisionResetToken: { type: Number, default: 0 },
})

const emit = defineEmits(['approve', 'reject', 'clarify', 'defer', 'applied', 'close'])

const STATUS_CLASS_BY_STATUS = {
  accepted: 'status-ok',
  approved: 'status-ok',
  reviewed: 'status-ok',
  rejected: 'status-danger',
  failed: 'status-danger',
  error: 'status-danger',
  pending: 'status-pending',
}

const item = computed(() => objectValue(props.context?.item))
const details = computed(() => objectValue(props.context?.item?.details))
const packet = computed(() => objectValue(props.context?.packet, details.value.packet))
const claims = computed(() => arrayValue(props.context?.claims, details.value.claims))
const sources = computed(() => arrayValue(props.context?.sources, details.value.sources).filter(isPlainObject))
const identity = computed(() => objectValue(props.context?.identity, details.value.identity))
const privacy = computed(() => objectValue(props.context?.privacy, details.value.privacy))
const validation = computed(() => objectValue(props.context?.validation, details.value.validation))
const applyPreview = computed(() => objectValue(props.context?.apply_preview, details.value.apply_preview))
const decisionLog = computed(() => arrayValue(props.context?.decision_log, details.value.decision_log).filter(isPlainObject))

const packetTitle = computed(() => {
  return packet.value.packet_label
    || packet.value.title
    || details.value.packet_label
    || details.value.packet_key
    || packet.value.packet_key
    || null
})

const sourceLocators = computed(() => {
  const values = []
  addLocator(values, props.context?.source_locator)
  addLocator(values, details.value.source_locator)
  for (const locator of arrayValue(props.context?.source_locators, details.value.source_locators)) {
    addLocator(values, locator)
  }
  for (const source of sources.value) {
    addLocator(values, source.locator || source.source_locator || source.url || source.uri || source.path || source.citation)
  }
  return Array.from(new Set(values))
})

const confidencePercent = computed(() => {
  const confidence = item.value.confidence
  if (confidence === null || confidence === undefined || confidence === '') return null
  const numeric = Number(confidence)
  return Number.isFinite(numeric) ? Math.round(numeric * 100) : null
})

const confidenceClass = computed(() => {
  const pct = confidencePercent.value
  if (pct === null) return ''
  if (pct >= 80) return 'conf-high'
  if (pct >= 50) return 'conf-med'
  return 'conf-low'
})

const statusClass = computed(() => {
  const status = String(item.value.status || '').toLowerCase()
  return STATUS_CLASS_BY_STATUS[status] || 'status-pending'
})

const identityRows = computed(() => kvRows(identity.value))
const privacyRows = computed(() => kvRows(privacy.value))
const validationErrors = computed(() => arrayValue(validation.value.errors))
const validationWarnings = computed(() => arrayValue(validation.value.warnings))
const decisionNotes = ref('')
const decisionReasonCode = ref('')
const localDecisionPending = ref(false)
const softDecisionDisabled = computed(() => props.actioning || localDecisionPending.value || !item.value.unified_id)
const approveBlockedByValidation = computed(() => validationErrors.value.length > 0)
const approveDisabled = computed(() => softDecisionDisabled.value || approveBlockedByValidation.value)

const validationStatus = computed(() => {
  if (validation.value.valid === true) return 'valid'
  if (validationErrors.value.length) return 'errors'
  if (validationWarnings.value.length) return 'warnings'
  return 'unknown'
})

const validationStatusClass = computed(() => {
  if (validation.value.valid === true) return 'status-ok'
  if (validationErrors.value.length) return 'status-danger'
  if (validationWarnings.value.length) return 'status-warning'
  return 'status-pending'
})

watch(() => props.actioning, (value) => {
  if (!value) {
    localDecisionPending.value = false
  }
})

watch(() => props.decisionResetToken, () => {
  decisionNotes.value = ''
  decisionReasonCode.value = ''
})

watch(() => props.context?.item?.unified_id, (next, prev) => {
  if (next !== prev) {
    decisionNotes.value = ''
    decisionReasonCode.value = ''
  }
})

const applyPreviewMutates = computed(() => applyPreview.value.mutates_accepted_facts === true)
const previewOperations = computed(() => arrayValue(applyPreview.value.operations).filter(isPlainObject))
const PACKET_REASON_OPTIONS = [
  { value: 'missing_source_locator', label: 'Missing source locator' },
  { value: 'source_needs_review', label: 'Source needs review' },
  { value: 'identity_unclear', label: 'Identity unclear' },
  { value: 'weak_evidence', label: 'Weak evidence' },
  { value: 'privacy_review_needed', label: 'Privacy review needed' },
  { value: 'duplicate_packet', label: 'Duplicate packet' },
  { value: 'other', label: 'Other' },
]

const applyPreviewSummaryRows = computed(() => {
  const rows = []
  for (const key of ['status', 'operation_count']) {
    if (applyPreview.value[key] !== undefined) {
      rows.push(toKvRow(key, applyPreview.value[key]))
    }
  }
  const summary = objectValue(applyPreview.value.summary)
  for (const row of kvRows(summary)) {
    rows.push({
      ...row,
      key: `summary.${row.key}`,
      label: `summary ${row.label}`,
    })
  }
  return rows
})

function addLocator(values, value) {
  if (typeof value !== 'string') return
  const trimmed = value.trim()
  if (trimmed !== '') values.push(trimmed)
}

function objectValue(...candidates) {
  for (const value of candidates) {
    if (isPlainObject(value)) return value
  }
  return {}
}

function arrayValue(...candidates) {
  for (const value of candidates) {
    if (Array.isArray(value)) return value
  }
  return []
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function kvRows(value) {
  if (!isPlainObject(value)) return []
  return Object.entries(value)
    .filter(([_, val]) => val !== undefined)
    .map(([key, val]) => toKvRow(key, val))
}

function toKvRow(key, value) {
  return {
    key,
    label: labelize(key),
    raw: value,
    value: displayValue(value),
  }
}

function labelize(key) {
  return String(key).replace(/_/g, ' ')
}

function displayValue(value) {
  if (value === null || value === undefined || value === '') return '-'
  if (value === true) return 'true'
  if (value === false) return 'false'
  if (Array.isArray(value) || isPlainObject(value)) return compactJson(value)
  return String(value)
}

function compactJson(value) {
  try {
    return JSON.stringify(value)
  } catch (e) {
    return String(value)
  }
}

function formatJson(value) {
  try {
    return JSON.stringify(value ?? {}, null, 2)
  } catch (e) {
    return String(value)
  }
}

function locatorHref(locator) {
  return /^https?:\/\//i.test(locator) ? locator : null
}

function sourceKey(source, idx) {
  return `${source.locator || source.source_locator || source.url || source.path || source.id || 'source'}-${idx}`
}

function claimKey(claim, idx) {
  return `${claim.index ?? claim.field_name ?? 'claim'}-${idx}`
}

function claimText(claim) {
  if (typeof claim.claim === 'string' && claim.claim.trim() !== '') return claim.claim
  if (claim.claim !== undefined && claim.claim !== null) return displayValue(claim.claim)
  const raw = objectValue(claim.raw)
  for (const key of ['claim', 'claim_text', 'statement', 'extracted_claim', 'extracted_text', 'text', 'value', 'proposed_value']) {
    if (typeof raw[key] === 'string' && raw[key].trim() !== '') return raw[key]
  }
  if (Object.keys(raw).length) return compactJson(raw)
  return null
}

function claimConfidence(claim) {
  const raw = objectValue(claim.raw)
  const value = claim.confidence ?? raw.confidence
  if (value === null || value === undefined || value === '') return null
  const numeric = Number(value)
  return Number.isFinite(numeric) ? Math.round(numeric * 100) : null
}

function booleanValueClass(value) {
  if (value === true) return 'text-ok'
  if (value === false) return 'text-muted'
  return ''
}

function issueKey(issue, idx) {
  if (typeof issue === 'string') return `${issue}-${idx}`
  return `${issue.gate || 'issue'}-${issue.code || issue.type || idx}`
}

function issueGate(issue) {
  return isPlainObject(issue) ? issue.gate || null : null
}

function issueCode(issue) {
  return isPlainObject(issue) ? issue.code || issue.type || null : null
}

function issueMessage(issue) {
  if (typeof issue === 'string') return issue
  if (!isPlainObject(issue)) return displayValue(issue)
  return issue.message || issue.error || issue.warning || compactJson(issue)
}

function operationKey(operation, idx) {
  return `${operation.index ?? operation.operation ?? 'operation'}-${idx}`
}

function operationRows(operation) {
  return kvRows(operation).filter((row) => !['index', 'operation', 'target_table'].includes(row.key))
}

function decisionKey(entry, idx) {
  return `${entry.action || 'event'}-${entry.created_at || 'time'}-${idx}`
}

function formatDate(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString()
}

function objectKeys(value) {
  return isPlainObject(value) ? Object.keys(value) : []
}

function emitDecision(action) {
  if (action === 'approve' ? approveDisabled.value : softDecisionDisabled.value) return
  localDecisionPending.value = true
  emit(action, {
    unifiedId: item.value.unified_id,
    notes: decisionNotes.value,
    reasonCode: action === 'approve' ? null : decisionReasonCode.value,
  })
}
</script>

<style scoped>
.packet-detail {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  min-width: 0;
}

.packet-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
}

.packet-heading-main {
  min-width: 0;
  flex: 1;
}

.packet-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: #ffb47a;
  margin: 0;
  overflow-wrap: anywhere;
}

.packet-summary {
  margin-top: 0.25rem;
  color: #d4c2f0;
  font-size: 0.8rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.packet-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.meta-pill,
.section-count,
.section-status,
.claim-pill {
  font-size: 0.67rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.15rem 0.45rem;
  border-radius: 0.25rem;
}

.meta-type {
  background: rgba(99, 179, 237, 0.20);
  color: #bfe1ff;
}

.status-ok,
.conf-high {
  background: rgba(0, 170, 0, 0.25);
  color: #b5f5b5;
}

.status-pending,
.conf-med,
.status-warning {
  background: rgba(204, 136, 0, 0.25);
  color: #ffd980;
}

.status-danger,
.conf-low {
  background: rgba(204, 0, 0, 0.25);
  color: #ffb5b5;
}

.packet-section {
  background: rgba(0, 0, 0, 0.18);
  border: 1px solid rgba(102, 102, 102, 0.30);
  border-radius: 0.5rem;
  padding: 0.7rem 0.8rem;
  min-width: 0;
}

.section-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
  margin-bottom: 0.5rem;
  color: #b39ddb;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.section-count {
  background: rgba(99, 51, 153, 0.30);
  color: #d4c2f0;
}

.section-status.preview {
  background: rgba(99, 179, 237, 0.18);
  color: #bfe1ff;
}

.subheading {
  color: #b39ddb;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  margin: 0.35rem 0;
}

.subheading.danger { color: #ffb5b5; }
.subheading.warning { color: #ffd980; }

.locator-list,
.claim-list,
.operation-list,
.decision-list,
.issue-list {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  min-width: 0;
}

.locator-row {
  display: block;
  color: #bfe1ff;
  background: rgba(99, 179, 237, 0.08);
  border: 1px solid rgba(99, 179, 237, 0.22);
  border-radius: 0.35rem;
  padding: 0.4rem 0.5rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size: 0.74rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  word-break: break-word;
}

a.locator-row:hover {
  color: #ffffff;
  border-color: rgba(99, 179, 237, 0.55);
}

.source-payloads {
  margin-top: 0.65rem;
}

.source-row,
.claim-row,
.operation-row,
.decision-row {
  border-top: 1px solid rgba(102, 102, 102, 0.22);
  padding-top: 0.45rem;
  min-width: 0;
}

.source-row-title,
.operation-name,
.decision-action {
  color: #ffe5b3;
  font-size: 0.82rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.claim-topline,
.operation-head,
.decision-head,
.claim-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
}

.claim-index {
  color: #b39ddb;
  font-size: 0.72rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
}

.claim-pill {
  background: rgba(99, 51, 153, 0.28);
  color: #d4c2f0;
}

.claim-pill.muted {
  background: rgba(102, 102, 102, 0.18);
  color: #d8d0e4;
}

.claim-text {
  margin-top: 0.35rem;
  color: #f0e6ff;
  font-size: 0.85rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.claim-meta,
.decision-actor,
.decision-time,
.operation-target {
  color: #b39ddb;
  font-size: 0.72rem;
  overflow-wrap: anywhere;
}

.packet-two-col,
.validation-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.75rem;
}

@media (min-width: 900px) {
  .packet-two-col,
  .validation-grid {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
}

.kv-grid {
  display: grid;
  gap: 0.3rem;
  min-width: 0;
}

.kv-grid.compact {
  margin-top: 0.35rem;
  gap: 0.2rem;
}

.kv-row {
  display: grid;
  grid-template-columns: minmax(5.5rem, 0.35fr) minmax(0, 1fr);
  gap: 0.5rem;
  align-items: baseline;
  min-width: 0;
}

.kv-key {
  color: #b39ddb;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.kv-value {
  color: #f0e6ff;
  font-size: 0.78rem;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  min-width: 0;
}

.preview-note {
  color: #f0e6ff;
  font-size: 0.82rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.preview-note.muted,
.empty-line,
.text-muted {
  color: #9b8bb5;
}

.preview-summary {
  margin: 0.5rem 0;
}

.text-ok {
  color: #b5f5b5;
}

.text-danger {
  color: #ffb5b5;
}

.issue-row {
  border-left: 3px solid rgba(102, 102, 102, 0.4);
  padding: 0.35rem 0.5rem;
  background: rgba(0, 0, 0, 0.16);
  border-radius: 0.25rem;
  min-width: 0;
}

.issue-row.danger {
  border-left-color: #cc4444;
}

.issue-row.warning {
  border-left-color: #cc8800;
}

.issue-gate,
.issue-code {
  display: inline-block;
  margin-right: 0.3rem;
  color: #d4c2f0;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
}

.issue-message {
  color: #f0e6ff;
  font-size: 0.78rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.decision-note {
  margin-top: 0.25rem;
  color: #f0e6ff;
  font-size: 0.78rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.decision-panel {
  border-color: rgba(99, 179, 237, 0.28);
}

.decision-notes {
  width: 100%;
  min-width: 0;
  resize: vertical;
  color: #f0e6ff;
  background: rgba(0, 0, 0, 0.32);
  border: 1px solid rgba(99, 51, 153, 0.45);
  border-radius: 0.35rem;
  padding: 0.55rem;
  font-size: 0.82rem;
  line-height: 1.35;
}

.decision-notes:focus {
  outline: 2px solid rgba(99, 179, 237, 0.55);
  outline-offset: 1px;
}

.decision-reason {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  margin-top: 0.5rem;
  color: #b39ddb;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.decision-reason-select {
  min-width: 0;
  color: #f0e6ff;
  background: rgba(0, 0, 0, 0.32);
  border: 1px solid rgba(99, 51, 153, 0.45);
  border-radius: 0.35rem;
  padding: 0.4rem 0.5rem;
  font-size: 0.78rem;
  text-transform: none;
  letter-spacing: 0;
}

.decision-reason-select:focus {
  outline: 2px solid rgba(99, 179, 237, 0.55);
  outline-offset: 1px;
}

.decision-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 0.55rem;
}

.decision-button {
  border: 0;
  border-radius: 0.35rem;
  padding: 0.42rem 0.65rem;
  font-size: 0.75rem;
  font-weight: 700;
  line-height: 1;
  cursor: pointer;
}

.decision-button:disabled {
  cursor: not-allowed;
  opacity: 0.48;
}

.decision-button.approve {
  background: #8de6a8;
  color: #08140c;
}

.decision-button.reject {
  background: #ff9c9c;
  color: #1c0505;
}

.decision-button.clarify {
  background: #bfe1ff;
  color: #07121c;
}

.decision-button.defer {
  background: #ffd980;
  color: #1a1000;
}

.approve-blocked-note {
  margin-top: 0.45rem;
  color: #ffd980;
  font-size: 0.74rem;
  line-height: 1.35;
}

.inline-json,
.raw-details pre {
  margin: 0.4rem 0 0;
  color: #d8d0e4;
  background: rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(102, 102, 102, 0.25);
  border-radius: 0.35rem;
  padding: 0.55rem;
  font-size: 0.72rem;
  line-height: 1.35;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.raw-details {
  background: rgba(0, 0, 0, 0.14);
  border: 1px solid rgba(102, 102, 102, 0.25);
  border-radius: 0.5rem;
  padding: 0.55rem 0.7rem;
  min-width: 0;
}

.raw-details summary {
  color: #b39ddb;
  cursor: pointer;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
</style>

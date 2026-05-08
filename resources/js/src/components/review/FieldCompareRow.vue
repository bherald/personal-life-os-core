<template>
  <div class="field-row" :class="[rowClass, decisionClass]">
    <div class="field-label">{{ fieldLabel }}</div>
    <div class="field-on-file">
      <template v-if="diff.change_type === 'source_add'">
        <span class="text-xs text-ops-text-muted">{{ diff.on_file_count ?? 0 }} sources on file</span>
      </template>
      <template v-else>
        <span v-if="diff.on_file !== null && diff.on_file !== ''" class="value">{{ formatValue(diff.on_file) }}</span>
        <span v-else class="muted">—</span>
      </template>
    </div>
    <div class="field-proposed">
      <span v-if="isUrl(diff.proposed)">
        <a :href="diff.proposed" target="_blank" rel="noopener" class="value field-link">{{ shortUrl(diff.proposed) }}</a>
      </span>
      <span v-else-if="diff.proposed !== null && diff.proposed !== ''" class="value">{{ formatValue(diff.proposed) }}</span>
      <span v-else class="muted">—</span>
      <SourceClassificationChip v-if="classification" :classification="classification" class="mt-1" />
      <div v-if="diff.temporal_mismatch" class="field-temporal-warn">
        ⚠ Source year {{ diff.temporal_mismatch.worst_year }} is
        <strong>{{ diff.temporal_mismatch.gap_years }} year{{ diff.temporal_mismatch.gap_years === 1 ? '' : 's' }}</strong>
        outside this person's lifetime
        <span v-if="diff.temporal_mismatch.person_birth || diff.temporal_mismatch.person_death">
          ({{ diff.temporal_mismatch.person_birth || '?' }}–{{ diff.temporal_mismatch.person_death || '?' }})
        </span>
        — likely wrong person.
      </div>
      <div v-if="truncatedEvidence" class="field-evidence" :title="safeEvidenceText">
        {{ truncatedEvidence }}
      </div>
      <div v-if="diff.evidence_sources?.length" class="field-sources">
        <span
          v-for="(src, idx) in diff.evidence_sources"
          :key="safeEvidenceSourceKey(src, idx)"
          class="source-pill"
        >
          {{ safeEvidenceSourceLabel(src, idx) }}
        </span>
      </div>
    </div>
    <div class="field-badge">
      <MatchBadge :status="diff.match_status || 'unknown'" :delta="diff.delta || null" />
      <div v-if="diff.confidence !== null && diff.confidence !== undefined" class="field-conf">
        {{ Math.round(diff.confidence * 100) }}%
      </div>
    </div>

    <!-- Phase 3: per-field decision controls -->
    <div v-if="interactive" class="field-decision">
      <div class="decision-toggle" role="radiogroup" :aria-label="`Decision for ${fieldLabel}`">
        <button
          type="button"
          class="decision-btn"
          :class="{ active: decision === 'accept', accept: true }"
          @click="setDecision('accept')"
          title="Accept this proposal"
        >✓</button>
        <button
          type="button"
          class="decision-btn"
          :class="{ active: decision === 'reject', reject: true }"
          @click="setDecision('reject')"
          title="Reject this proposal with a reason code"
        >✗</button>
      </div>
      <div v-if="decision === 'accept' && diff.match_status === 'conflict'" class="decision-conflict">
        <label class="decision-label">Use:</label>
        <select :value="conflictChoice" @change="onConflictChange($event.target.value)" class="decision-select">
          <option value="on_file">On-file value (note conflict)</option>
          <option value="proposed">Proposed value (overwrite)</option>
        </select>
      </div>
      <div v-if="decision === 'reject'" class="decision-reason">
        <label class="decision-label">Reason:</label>
        <select :value="reasonCode" @change="onReasonChange($event.target.value)" class="decision-select">
          <option value="wrong_person">Wrong person</option>
          <option value="fan_mismatch">FAN mismatch</option>
          <option value="date_conflict">Date conflict</option>
          <option value="name_only_match">Name-only match</option>
          <option value="place_mismatch">Place mismatch</option>
          <option value="low_evidence">Low evidence</option>
          <option value="other">Other</option>
        </select>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import MatchBadge from './MatchBadge.vue'
import SourceClassificationChip from './SourceClassificationChip.vue'

const props = defineProps({
  diff: { type: Object, required: true },
  classification: { type: Object, default: null },
  // Phase 3: per-field decision state (interactive when true)
  interactive: { type: Boolean, default: false },
  decision: { type: String, default: null }, // 'accept' | 'reject' | null
  reasonCode: { type: String, default: 'other' },
  conflictChoice: { type: String, default: 'on_file' },
})

const emit = defineEmits(['update:decision', 'update:reasonCode', 'update:conflictChoice'])

const decisionClass = computed(() => {
  if (!props.interactive) return null
  if (props.decision === 'accept') return 'row-accepted'
  if (props.decision === 'reject') return 'row-rejected'
  return null
})

function setDecision(d) {
  emit('update:decision', props.decision === d ? null : d)
}
function onReasonChange(v) { emit('update:reasonCode', v) }
function onConflictChange(v) { emit('update:conflictChoice', v) }

const FIELD_LABELS = {
  birth_date: 'Birth date',
  birth_place: 'Birth place',
  death_date: 'Death date',
  death_place: 'Death place',
  marriage_date: 'Marriage date',
  burial_date: 'Burial date',
  given_name: 'Given name',
  surname: 'Surname',
  sex: 'Sex',
  occupation: 'Occupation',
  sources: 'Source',
}

const fieldLabel = computed(() => {
  if (props.diff.field) return FIELD_LABELS[props.diff.field] || props.diff.field
  if (props.diff.change_type) return props.diff.change_type
  return 'Field'
})

const rowClass = computed(() => {
  if (props.diff.match_status === 'conflict') return 'row-conflict'
  if (props.diff.match_status === 'different') return 'row-different'
  return null
})

const safeEvidenceText = computed(() => {
  return redactDisplayText(props.diff.evidence_summary || '')
})

const truncatedEvidence = computed(() => {
  const text = safeEvidenceText.value
  if (text.length <= 140) return text
  return text.slice(0, 137) + '…'
})

function safeEvidenceSourceKey(src, idx) {
  return `source-${idx}-${safeEvidenceSourceKind(src)}`
}

function safeEvidenceSourceLabel(src, idx) {
  const kind = safeEvidenceSourceKind(src)
  if (kind === 'external') return `External source ${idx + 1}`
  return `Source reference ${idx + 1}`
}

function safeEvidenceSourceKind(src) {
  const text = String(src || '').trim()
  if (/^https?:\/\//i.test(text) && !isSensitiveEvidenceText(text)) return 'external'
  return 'reference'
}

function formatValue(v) {
  if (typeof v === 'string') return redactDisplayText(v)
  if (typeof v === 'number') return String(v)
  if (Array.isArray(v)) return structuredValueLabel(v)
  if (v && typeof v === 'object') return structuredValueLabel(v)
  return redactDisplayText(String(v ?? ''))
}
function isUrl(v) {
  return typeof v === 'string' && /^https?:\/\//i.test(v)
}
function shortUrl(v) {
  try {
    const u = new URL(v)
    return u.host + (u.pathname && u.pathname !== '/' ? u.pathname : '')
  } catch (e) {
    return v
  }
}
function structuredValueLabel(value) {
  if (Array.isArray(value)) return `Structured value (${value.length} item${value.length === 1 ? '' : 's'})`
  if (value && typeof value === 'object') return `Structured value (${Object.keys(value).length} field${Object.keys(value).length === 1 ? '' : 's'})`
  return 'Structured value'
}
function redactDisplayText(value) {
  return String(value)
    .replace(/\bBearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [redacted]')
    .replace(/\b(?:api[_-]?(?:key|token)|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|token|secret|password|authorization)\s*[:=]\s*[^\s,;\]}]+/gi, '[redacted secret]')
    .replace(/([A-Za-z][A-Za-z0-9+.-]*:\/\/)([^:@/\s]+):([^@/\s]+)@/gi, '$1[redacted]@')
    .replace(/\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=-]{8,}\b/gi, '[redacted token]')
    .replace(/\/(?:home|Users|root)\/[^\s,"')\]}]+/g, '[redacted path]')
}

function isSensitiveEvidenceText(value) {
  const text = String(value || '')
  return /[?&](?:token|key|secret|password)=/i.test(text)
    || /\bBearer\s+/i.test(text)
    || /\/(?:home|Users|root)\//.test(text)
    || /\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=-]{8,}\b/i.test(text)
}
</script>

<style scoped>
.field-row {
  display: grid;
  grid-template-columns: 8rem 1fr 1.5fr auto;
  gap: 0.75rem;
  padding: 0.6rem 0.5rem;
  border-bottom: 1px solid rgba(102, 102, 102, 0.20);
  align-items: start;
}
.field-row:last-child { border-bottom: none; }
.row-conflict  { background: rgba(204, 0, 0, 0.06); }
.row-different { background: rgba(204, 136, 0, 0.05); }
.row-accepted  { box-shadow: inset 4px 0 0 0 #00aa00; }
.row-rejected  { box-shadow: inset 4px 0 0 0 #cc0000; opacity: 0.7; }
.field-decision {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  padding-top: 0.4rem;
  margin-top: 0.4rem;
  border-top: 1px dashed rgba(102, 102, 102, 0.20);
}
.decision-toggle { display: inline-flex; gap: 0.15rem; }
.decision-btn {
  width: 2rem;
  height: 1.6rem;
  border: 1px solid rgba(102, 102, 102, 0.40);
  background: rgba(0, 0, 0, 0.20);
  color: #888;
  font-weight: 700;
  cursor: pointer;
  border-radius: 0.25rem;
}
.decision-btn:hover { background: rgba(0, 0, 0, 0.40); }
.decision-btn.accept.active { background: rgba(0, 170, 0, 0.30); border-color: #00aa00; color: #b5f5b5; }
.decision-btn.reject.active { background: rgba(204, 0, 0, 0.30); border-color: #cc0000; color: #ffb5b5; }
.decision-label {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #b39ddb;
  font-weight: 600;
}
.decision-select {
  font-size: 0.75rem;
  padding: 0.15rem 0.35rem;
  background: rgba(0, 0, 0, 0.30);
  color: #f0e6ff;
  border: 1px solid rgba(102, 102, 102, 0.40);
  border-radius: 0.25rem;
}
.decision-conflict, .decision-reason { display: inline-flex; align-items: center; gap: 0.35rem; }

.field-label {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
  padding-top: 0.15rem;
}
.field-on-file, .field-proposed { font-size: 0.85rem; color: #f0e6ff; word-break: break-word; }
.field-on-file .value { color: #d4d4d4; }
.field-proposed .value { color: #ffe5b3; }
.field-link { color: #5da9ff; text-decoration: none; word-break: break-all; }
.field-link:hover { text-decoration: underline; }
.mt-1 { margin-top: 0.25rem; display: flex; }
.field-temporal-warn {
  margin-top: 0.4rem;
  padding: 0.35rem 0.5rem;
  background: rgba(204, 0, 0, 0.10);
  border-left: 3px solid #cc0000;
  color: #ffb5b5;
  font-size: 0.7rem;
  border-radius: 0.25rem;
  line-height: 1.3;
}
.field-temporal-warn strong { color: #ffb5b5; }
.muted { color: #555; }
.field-evidence {
  margin-top: 0.25rem;
  font-size: 0.7rem;
  color: #b39ddb;
  font-style: italic;
  line-height: 1.3;
}
.field-sources {
  margin-top: 0.25rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}
.source-pill {
  display: inline-block;
  font-size: 0.65rem;
  background: rgba(99, 179, 237, 0.20);
  color: #bfe1ff;
  padding: 0.05rem 0.4rem;
  border-radius: 0.25rem;
}
.field-badge { display: flex; flex-direction: column; align-items: flex-end; gap: 0.25rem; }
.field-conf { font-size: 0.65rem; color: #b39ddb; }
</style>

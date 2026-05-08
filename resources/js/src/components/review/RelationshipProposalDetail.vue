<template>
  <div class="relationship-detail">
    <div class="rel-header">
      <h3 class="rel-title">{{ safeTitle }}</h3>
      <div class="rel-meta">
        <span v-if="context.item.confidence !== null" class="meta-pill" :class="confidenceClass">
          {{ Math.round(context.item.confidence * 100) }}%
        </span>
        <span class="meta-pill meta-agent">{{ context.item.agent_id }}</span>
        <span class="meta-pill meta-rel">{{ relTypeLabel }}</span>
      </div>
    </div>

    <!-- Existing person card -->
    <section class="rel-section">
      <div class="rel-heading">On file (root person)</div>
      <PersonSnapshotCard :person="context.person" />
    </section>

    <!-- Proposed relative — mini-tree SVG fragment (one per proposal)
         plus citation details below each fragment. -->
    <section class="rel-section">
      <div class="rel-heading">Proposed new relative{{ relationshipProposals.length === 1 ? '' : 's' }}</div>
      <div v-if="relationshipProposals.length === 0" class="rel-proposed-card">
        <div class="text-sm text-ops-text-muted italic">
          No relationship-typed proposals in this review item.
        </div>
      </div>
      <div v-else class="rel-proposed-stack">
        <div v-for="prop in relationshipProposals" :key="prop.proposal_index" class="rel-tree-card">
          <MiniTreeFragment
            :root-name="rootDisplayName"
            :root-subtext="rootSubtext"
            :proposed-name="proposedDisplayName(prop)"
            :proposed-subtext="proposedSubtextFor(prop)"
            :role="extractRole(prop)"
          />
          <div class="rel-cite">
            <div v-if="safeEvidenceSummary(prop)" class="rel-evidence">{{ safeEvidenceSummary(prop) }}</div>
            <div v-if="prop.evidence_sources?.length" class="rel-sources">
              <span
                v-for="(s, idx) in prop.evidence_sources"
                :key="safeEvidenceSourceKey(s, idx)"
                class="source-pill"
              >
                {{ safeEvidenceSourceLabel(s, idx) }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <FANClusterOverlap :overlap="context.fan_overlap || []" />
    <AgentReasoningPanel :reasoning="context.agent_reasoning || {}" />

    <div class="detail-actions">
      <button
        type="button"
        class="ops-btn ops-btn-green"
        :disabled="actioning"
        @click="$emit('approve', context.item.unified_id)"
      >Approve relationship</button>
      <button
        type="button"
        class="ops-btn ops-btn-red"
        :disabled="actioning"
        @click="$emit('reject', context.item.unified_id)"
      >Reject</button>
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
import { computed } from 'vue'
import PersonSnapshotCard from './PersonSnapshotCard.vue'
import FANClusterOverlap from './FANClusterOverlap.vue'
import AgentReasoningPanel from './AgentReasoningPanel.vue'
import MiniTreeFragment from './MiniTreeFragment.vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
})

defineEmits(['approve', 'reject', 'applied', 'close'])

const REL_TYPE_LABELS = {
  add_parent: 'add parent',
  add_child: 'add child',
  add_sibling: 'add sibling',
  add_spouse: 'add spouse',
  add_relationship: 'add relative',
}

const relTypeLabel = computed(() => {
  const ft = props.context?.item?.details?.finding_type
       || props.context?.item?.review_type
  return REL_TYPE_LABELS[ft] || 'relationship'
})

const safeTitle = computed(() => {
  return displayText(props.context?.item?.title) || 'Proposed relationship'
})

const relationshipProposals = computed(() => {
  const proposals = props.context?.item?.details?.proposals || []
  return proposals
    .map((p, idx) => ({ ...p, proposal_index: idx }))
    .filter((p) => {
      const ct = (p.change_type || '').toLowerCase()
      return ct.startsWith('add_') || ct === 'relationship' || ct === 'add_relationship'
    })
})

function relationshipDirection(prop) {
  const ct = prop.change_type || ''
  return REL_TYPE_LABELS[ct] || ct.replace(/_/g, ' ') || 'relate'
}

// Map change_type → MiniTreeFragment role.
function extractRole(prop) {
  const ct = (prop.change_type || '').toLowerCase()
  if (ct === 'add_parent') return 'parent'
  if (ct === 'add_child') return 'child'
  if (ct === 'add_sibling') return 'sibling'
  if (ct === 'add_spouse') return 'spouse'
  return 'relative'
}

const rootDisplayName = computed(() => {
  const p = props.context?.person
  if (!p) return '(root)'
  const parts = [p.given_name, p.surname].filter(Boolean).join(' ').trim()
  return displayText(parts) || 'Person reference'
})

const rootSubtext = computed(() => {
  const p = props.context?.person
  if (!p) return null
  const bits = []
  if (p.birth_date) bits.push(`b. ${p.birth_date}`)
  if (p.death_date) bits.push(`d. ${p.death_date}`)
  return bits.join(' · ') || null
})

function proposedSubtextFor(prop) {
  const bits = []
  if (prop.proposed_birth_date) bits.push(`b. ${displayText(prop.proposed_birth_date)}`)
  if (prop.proposed_death_date) bits.push(`d. ${displayText(prop.proposed_death_date)}`)
  if (prop.confidence !== null && prop.confidence !== undefined) {
    bits.push(`${Math.round(prop.confidence * 100)}% conf`)
  }
  return bits.join(' · ') || null
}

function proposedDisplayName(prop) {
  return displayText(prop.proposed_value || prop.field_name) || '(unnamed)'
}

function safeEvidenceSummary(prop) {
  return displayText(prop.evidence_summary)
}

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

function displayText(value) {
  if (value === null || value === undefined) return ''
  if (Array.isArray(value)) return value.length ? `details available (${value.length} items)` : ''
  if (typeof value === 'object') return Object.keys(value).length ? `details available (${Object.keys(value).length} fields)` : ''
  return redactDisplayText(String(value).trim())
}

function redactDisplayText(value) {
  return String(value)
    .replace(/\s*\(#\d+\)/g, '')
    .replace(/\b(Person|Family|Media|Source|Face|Cluster)\s+#\d+\b/gi, '$1 reference')
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

const confidenceClass = computed(() => {
  const c = props.context?.item?.confidence
  if (c === null || c === undefined) return ''
  if (c >= 0.8) return 'conf-high'
  if (c >= 0.5) return 'conf-med'
  return 'conf-low'
})
</script>

<style scoped>
.relationship-detail { display: flex; flex-direction: column; gap: 1rem; }
.rel-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.rel-title { font-size: 1.1rem; font-weight: 700; color: #ffb47a; margin: 0; }
.rel-meta { display: flex; gap: 0.4rem; align-items: center; }
.meta-pill {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.15rem 0.5rem;
  border-radius: 0.25rem;
}
.meta-agent { background: rgba(99, 51, 153, 0.30); color: #d4c2f0; }
.meta-rel   { background: rgba(99, 179, 237, 0.20); color: #bfe1ff; }
.conf-high  { background: rgba(0, 170, 0, 0.30);    color: #b5f5b5; }
.conf-med   { background: rgba(204, 136, 0, 0.30);  color: #ffd980; }
.conf-low   { background: rgba(204, 0, 0, 0.30);    color: #ffb5b5; }

.rel-section { display: flex; flex-direction: column; gap: 0.5rem; }
.rel-heading {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.rel-proposed-card {
  background: rgba(255, 180, 122, 0.08);
  border: 1px solid rgba(255, 180, 122, 0.25);
  border-radius: 0.5rem;
  padding: 0.75rem;
}
.rel-proposed-stack {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.rel-tree-card {
  background: rgba(255, 180, 122, 0.08);
  border: 1px solid rgba(255, 180, 122, 0.25);
  border-radius: 0.5rem;
  padding: 0.6rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  align-items: center;
}
.rel-cite { width: 100%; }
.rel-evidence {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: #b39ddb;
  font-style: italic;
  line-height: 1.3;
}
.rel-sources { margin-top: 0.3rem; display: flex; flex-wrap: wrap; gap: 0.25rem; }
.source-pill {
  display: inline-block;
  font-size: 0.65rem;
  background: rgba(99, 179, 237, 0.20);
  color: #bfe1ff;
  padding: 0.05rem 0.4rem;
  border-radius: 0.25rem;
}
.detail-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
</style>

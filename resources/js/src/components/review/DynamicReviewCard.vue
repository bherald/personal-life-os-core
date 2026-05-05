<template>
  <div
    class="review-card"
    :class="[
      { 'selected': selected, 'horizontal': schema?.card?.layout === 'horizontal', 'review-packet': isReviewPacket }
    ]"
    :style="cardStyle"
    @click="$emit('select', item)"
  >
    <!-- Checkbox for batch selection -->
    <div v-if="canBatchSelect" class="card-checkbox" @click.stop>
      <input
        type="checkbox"
        :checked="selected"
        @change="$emit('toggle-select', item)"
        class="form-checkbox"
      />
    </div>

    <!-- Image (for horizontal layout) -->
    <div v-if="schema?.card?.image && schema.card.layout === 'horizontal'" class="card-image">
      <img
        v-if="getImageUrl(schema.card.image.source) && !imageError"
        :src="getImageUrl(schema.card.image.source)"
        :class="schema.card.image.class"
        @error="imageError = true"
      />
      <div v-else class="image-placeholder">
        <svg class="w-12 h-12 text-ops-text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
      </div>
    </div>

    <div class="card-content">
      <!-- UI-6: Tree name badge -->
      <div v-if="item.tree_name" class="tree-badge">
        {{ item.tree_name }}
      </div>
      <div v-if="intakeGeneratedBadge" class="intake-badge">
        Intake Generated
      </div>
      <div v-if="intakeDetailLines.length" class="intake-details">
        <div
          v-for="(line, idx) in intakeDetailLines"
          :key="idx"
          class="intake-detail-line"
        >
          {{ line }}
        </div>
      </div>

      <div v-if="packetContextItems.length" class="packet-context" aria-label="Review packet context">
        <span
          v-for="entry in packetContextItems"
          :key="entry.key"
          class="packet-context-chip"
          :class="`is-${entry.key}`"
          :title="entry.title || entry.value"
        >
          <span class="packet-context-label">{{ entry.label }}</span>
          <span class="packet-context-value">{{ entry.value }}</span>
        </span>
      </div>

      <!-- Header Row -->
      <div v-if="schema?.card?.header" class="card-header">
        <template v-for="(field, idx) in schema.card.header" :key="idx">
          <DynamicField :field="field" :item="item" />
        </template>
      </div>

      <!-- Body -->
      <div v-if="schema?.card?.body" class="card-body">
        <template v-for="(field, idx) in schema.card.body" :key="idx">
          <DynamicField :field="field" :item="item" />
        </template>
      </div>

      <!-- Footer -->
      <div v-if="schema?.card?.footer" class="card-footer">
        <template v-for="(field, idx) in schema.card.footer" :key="idx">
          <DynamicField :field="field" :item="item" />
        </template>
      </div>

      <!-- Actions Row -->
      <div class="card-actions">
        <button
          @click.stop="$emit('approve', item)"
          class="btn-approve"
          :disabled="inFlight || approvalDisabled"
          :title="approvalDisabledTitle"
        >
          {{ approvalLabel }}
        </button>
        <button @click.stop="$emit('reject', item)" class="btn-reject" :disabled="inFlight">
          Reject
        </button>
        <!-- INF-10d: Remediation execute button -->
        <button
          v-if="item.remediation?.executable"
          @click.stop="$emit('execute-remediation', item)"
          class="btn-remediate"
          :disabled="inFlight"
          :title="item.remediation.description"
        >
          <span v-if="item.remediation.risk_level === 'write'" class="risk-badge write">W</span>
          <span v-else class="risk-badge read">R</span>
          Fix
        </button>
        <span
          v-else-if="item.remediation && !item.remediation.executable"
          class="text-[10px] text-ops-text-muted italic ml-1"
          :title="item.remediation.risk_level === 'destructive' ? 'Requires escalation' : 'In cooldown'"
        >
          {{ item.remediation.risk_level === 'destructive' ? 'Escalate' : 'Cooldown' }}
        </span>
        <!-- Custom actions from schema -->
        <template v-if="schema?.actions">
          <button
            v-for="action in schema.actions"
            :key="action.name"
            @click.stop="$emit('action', item, action)"
            class="btn-custom"
            :class="action.variant"
            :disabled="inFlight"
          >
            {{ action.label }}
          </button>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import DynamicField from './DynamicField.vue'
import { resolveSurfaceThemeStyle } from '@/utils/reviewSchemaStyles'

const props = defineProps({
  item: { type: Object, required: true },
  selected: { type: Boolean, default: false },
  inFlight: { type: Boolean, default: false },
  approvalDisabled: { type: Boolean, default: false },
  approvalDisabledTitle: { type: String, default: '' },
  approvalLabel: { type: String, default: 'Approve' },
})

defineEmits(['select', 'toggle-select', 'approve', 'reject', 'action', 'execute-remediation'])

const imageError = ref(false)

const schema = computed(() => props.item.ui_schema || {})

const cardStyle = computed(() => resolveSurfaceThemeStyle(props.item.color, 'ops-plum'))

const isReviewPacket = computed(() => {
  return props.item?.review_type === 'genealogy_review_packet'
    || props.item?.source === 'genealogy_review_packet'
})

const canBatchSelect = computed(() => {
  return props.item?.batch_enabled === true && !isReviewPacket.value
})

const packetContextItems = computed(() => {
  if (!isReviewPacket.value) {
    return []
  }

  return [
    packetContextEntry('person', 'Person', formatPersonId(packetFieldValue('person_id'))),
    packetContextEntry('status', 'Status', formatPacketStatus(packetFieldValue('packet_status'))),
    packetContextEntry(
      'origin',
      'Origin',
      formatRemediationOriginValue(),
      formatRemediationOriginTitle()
    ),
    packetContextEntry(
      'boundary',
      'Boundary',
      compactText(packetFocusFieldValue('boundary_label')),
      stringOrNull(packetFocusFieldValue('boundary_label'))
    ),
    packetContextEntry(
      'source',
      'Source',
      compactLocator(packetFocusFieldValue('source_locator')),
      stringOrNull(packetFocusFieldValue('source_locator'))
    ),
    packetContextEntry('access', 'Access', formatPacketStatus(packetFocusFieldValue('source_access_class'))),
    packetContextEntry('media', 'Media', formatMediaHealth()),
    packetContextEntry('claims', 'Claims', formatCount(packetFieldValue('claim_count'))),
    packetContextEntry('sources', 'Sources', formatCount(packetFieldValue('source_count'))),
    packetContextEntry(
      'claim',
      'Claim',
      compactText(packetFocusFieldValue('claim_summary')),
      stringOrNull(packetFocusFieldValue('claim_summary'))
    ),
    packetContextEntry('preview', 'Preview', formatPacketStatus(packetFocusFieldValue('preview_status'))),
  ].filter(Boolean)
})

const intakeGeneratedBadge = computed(() => {
  return props.item.category === 'genealogy'
    && typeof props.item.agent_id === 'string'
    && props.item.agent_id.startsWith('genealogy-intake-')
})

const intakeDetailLines = computed(() => {
  if (!intakeGeneratedBadge.value) {
    return []
  }

  const intake = props.item.context?.intake || {}
  const lines = []

  if (intake.headline) {
    lines.push(intake.headline)
  } else if (intake.change_label) {
    lines.push(intake.change_label)
  }

  if (intake.value_transition) {
    lines.push(intake.value_transition)
  }

  if (intake.vital_summary) {
    lines.push(intake.vital_summary)
  }

  const meta = [intake.status_label, intake.confidence_label].filter(Boolean).join(' · ')
  if (meta) {
    lines.push(meta)
  }

  return lines
})

const cacheBuster = Date.now()

const getValue = (source) => {
  if (!source) return null
  return props.item[source]
}

const packetContextEntry = (key, label, value, title = null) => {
  if (value === null || value === undefined || value === '') {
    return null
  }

  return { key, label, value, title }
}

const packetFieldValue = (field) => {
  const value = props.item?.[field]
  if (value !== null && value !== undefined && value !== '') {
    return value
  }

  const detailValue = props.item?.details?.[field]
  return detailValue !== null && detailValue !== undefined && detailValue !== ''
    ? detailValue
    : null
}

const packetFocusFieldValue = (field) => {
  const focusValue = props.item?.review_focus?.[field]
  if (focusValue !== null && focusValue !== undefined && focusValue !== '') {
    return focusValue
  }

  return packetFieldValue(field)
}

const remediationOrigin = computed(() => {
  const origin = props.item?.review_focus?.remediation_origin
  return origin && typeof origin === 'object' && !Array.isArray(origin) ? origin : null
})

const stringOrNull = (value) => {
  if (value === null || value === undefined) {
    return null
  }

  const str = String(value).trim()
  return str === '' ? null : str
}

const formatPersonId = (value) => {
  const str = stringOrNull(value)
  return str ? `#${str}` : null
}

const formatPacketStatus = (value) => {
  const str = stringOrNull(value)
  return str ? str.replace(/[_-]+/g, ' ') : null
}

const formatCount = (value) => {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const numeric = Number(value)
  return Number.isFinite(numeric) ? String(numeric) : String(value)
}

const formatMediaHealth = () => {
  const total = numberOrNull(packetFocusFieldValue('media_ref_count'))
  const resolved = numberOrNull(packetFocusFieldValue('resolved_media_count'))
  const missing = numberOrNull(packetFocusFieldValue('missing_media_count'))

  if (total === null && resolved === null && missing === null) {
    return null
  }

  const count = total ?? resolved ?? 0
  return missing && missing > 0
    ? `${count} refs, ${missing} missing`
    : `${count} refs`
}

const formatRemediationOriginValue = () => {
  const origin = remediationOrigin.value
  if (!origin) {
    return null
  }

  return formatPacketStatus(origin.finding_type)
    || formatPacketStatus(origin.source_review_type)
    || (Array.isArray(origin.operation_types) ? formatPacketStatus(origin.operation_types[0]) : null)
    || null
}

const formatRemediationOriginTitle = () => {
  const origin = remediationOrigin.value
  if (!origin) {
    return null
  }

  const parts = [
    origin.source_review_type ? `Source: ${formatPacketStatus(origin.source_review_type)}` : null,
    origin.finding_type ? `Finding: ${formatPacketStatus(origin.finding_type)}` : null,
    Array.isArray(origin.operation_types) && origin.operation_types.length
      ? `Operations: ${origin.operation_types.map(formatPacketStatus).filter(Boolean).join(', ')}`
      : null,
    origin.apply_enabled === false ? 'Apply held' : null,
    origin.writeback === false ? 'Writeback off' : null,
  ].filter(Boolean)

  return parts.length ? parts.join(' · ') : null
}

const numberOrNull = (value) => {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

const compactLocator = (value) => {
  const str = stringOrNull(value)
  if (!str) {
    return null
  }

  if (str.length <= 64) {
    return str
  }

  return `${str.slice(0, 34)}...${str.slice(-24)}`
}

const compactText = (value) => {
  const str = stringOrNull(value)
  if (!str) {
    return null
  }

  return str.length <= 88 ? str : `${str.slice(0, 85)}...`
}

const getImageUrl = (source) => {
  const url = getValue(source)
  if (!url) return null
  const sep = url.includes('?') ? '&' : '?'
  return `${url}${sep}_cb=${cacheBuster}`
}
</script>

<style scoped>
.review-card {
  display: flex;
  border-radius: 0 16px 16px 0;
  padding: 1rem 1rem 1rem 1.25rem;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
  gap: 1rem;
  border-left: 8px solid var(--card-accent, var(--ops-lilac));
  background: var(--ops-black);
  color: var(--ops-peach);
}

.review-card:hover {
  box-shadow: inset 4px 0 0 var(--card-accent, var(--ops-lilac));
  border-left-color: var(--ops-orange);
}

.review-card.selected {
  border-left-width: 12px;
  border-left-color: var(--ops-orange);
  box-shadow: 0 0 0 2px var(--ops-orange);
}

.review-card.review-packet {
  gap: 0.875rem;
}

/* Body text on black — sky gives ~10.5:1 vs the muted-gray ~2.7:1 on plum */
.review-card :deep(.text-ops-text-muted) {
  color: var(--ops-sky) !important;
  opacity: 0.92;
}

.review-card :deep(.field-text) {
  color: var(--ops-peach);
}

.review-card :deep(.field-label) {
  color: var(--ops-gold);
  opacity: 0.85;
}

.review-card :deep(.field-timestamp) {
  color: var(--ops-sky);
  opacity: 0.85;
}

.review-card.horizontal {
  flex-direction: row;
}

.card-checkbox {
  flex-shrink: 0;
  display: flex;
  align-items: flex-start;
}

.form-checkbox {
  width: 1.25rem;
  height: 1.25rem;
  accent-color: var(--ops-orange);
}

.card-image {
  flex-shrink: 0;
  width: 100px;
  height: 100px;
  overflow: hidden;
  border-radius: 8px;
  background: var(--ops-black);
}

.card-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.image-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--ops-plum);
}

.card-content {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.intake-badge {
  align-self: flex-start;
  padding: 0.2rem 0.5rem;
  border-radius: 9999px;
  background: rgba(86, 196, 255, 0.18);
  color: var(--ops-sky);
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.intake-details {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.intake-detail-line {
  font-size: 0.78rem;
  line-height: 1.35;
  color: var(--ops-text-muted);
  white-space: pre-wrap;
}

.packet-context {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.packet-context-chip {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  max-width: 100%;
  padding: 0.18rem 0.45rem;
  border: 1px solid rgba(255, 204, 102, 0.32);
  border-radius: 4px;
  background: rgba(255, 204, 102, 0.08);
  color: var(--ops-peach);
  font-size: 0.68rem;
  line-height: 1.25;
}

.packet-context-chip.is-source {
  max-width: min(100%, 34rem);
}

.packet-context-chip.is-access,
.packet-context-chip.is-media,
.packet-context-chip.is-origin {
  border-color: rgba(99, 179, 237, 0.32);
  background: rgba(99, 179, 237, 0.08);
}

.packet-context-chip.is-claim {
  max-width: min(100%, 38rem);
}

.packet-context-chip.is-preview {
  border-color: rgba(99, 179, 237, 0.32);
  background: rgba(99, 179, 237, 0.08);
}

.packet-context-label {
  flex: 0 0 auto;
  color: var(--ops-gold);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0;
}

.packet-context-value {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.card-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.card-body {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.card-footer {
  display: flex;
  align-items: center;
  gap: 1rem;
  font-size: 0.75rem;
  opacity: 0.7;
  margin-top: auto;
}

.card-actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--ops-black);
}

.btn-approve {
  padding: 0.375rem 0.75rem;
  background: var(--ops-green);
  color: var(--ops-black);
  border-radius: 0 8px 8px 0;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  transition: filter 0.15s;
}

.btn-approve:hover {
  filter: brightness(1.1);
}

.btn-reject {
  padding: 0.375rem 0.75rem;
  background: var(--ops-sunset);
  color: var(--ops-black);
  border-radius: 0 8px 8px 0;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  transition: filter 0.15s;
}

.btn-reject:hover {
  filter: brightness(1.1);
}

.card-actions button:disabled,
.card-actions button:disabled:hover {
  cursor: not-allowed;
  filter: none;
  opacity: 0.5;
}

.btn-custom {
  padding: 0.375rem 0.75rem;
  background: var(--ops-sky);
  color: var(--ops-black);
  border-radius: 0 8px 8px 0;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  transition: filter 0.15s;
}

.btn-custom:hover {
  filter: brightness(1.1);
}

.btn-custom.secondary {
  background: var(--ops-plum);
  color: var(--ops-peach);
}

.btn-remediate {
  padding: 0.375rem 0.75rem;
  background: var(--ops-gold, #fc6);
  color: var(--ops-black);
  border-radius: 0 8px 8px 0;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  transition: filter 0.15s;
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.btn-remediate:hover {
  filter: brightness(1.1);
}

.risk-badge {
  font-size: 0.6rem;
  padding: 0 0.25rem;
  border-radius: 2px;
  font-weight: 700;
}

.risk-badge.read {
  background: var(--ops-green, #8f8);
  color: var(--ops-black);
}

.risk-badge.write {
  background: var(--ops-orange);
  color: var(--ops-black);
}

.tree-badge {
  font-size: 0.6rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ops-sky, #9cf);
  opacity: 0.8;
  margin-bottom: 0.25rem;
}
</style>

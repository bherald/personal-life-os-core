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
      <div v-if="showReadableSummary" class="readable-summary">
        <div class="readable-meta">
          <span v-if="readableKind" class="readable-kind">{{ readableKind }}</span>
          <span v-if="readableConfidence" class="readable-confidence">{{ readableConfidence }}</span>
        </div>
        <div v-if="readableTitle" class="readable-title">{{ readableTitle }}</div>
        <div v-if="readableSummary" class="readable-body">{{ readableSummary }}</div>
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
      <div
        v-if="packetReadinessLine"
        class="packet-readiness-line"
        :class="`is-${packetReadinessLine.state}`"
        :title="packetReadinessLine.title"
      >
        {{ packetReadinessLine.label }}
      </div>
      <div
        v-if="packetOutcomeLine"
        class="packet-outcome-line"
        :class="`is-${packetOutcomeLine.state}`"
        :title="packetOutcomeLine.title"
      >
        {{ packetOutcomeLine.label }}
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

      <div
        v-if="approvalDisabled && approvalDisabledTitle"
        class="text-[11px] text-ops-orange bg-ops-orange/10 border border-ops-orange/30 rounded px-2 py-1 mb-2"
      >
        {{ approvalDisabledTitle }}
      </div>
      <div v-if="decisionObjectLine" class="decision-object-line">
        {{ decisionObjectLine }}
      </div>

      <div v-if="capturePlans.length" class="capture-line-decisions" @click.stop>
        <div class="capture-line-header">
          Media candidates require one decision per line before approval.
        </div>
        <div
          v-for="(plan, index) in capturePlans"
          :key="`${item.unified_id || 'capture'}:${index}`"
          class="capture-line"
          :class="{ 'needs-identity': planNeedsIdentityReview(plan) }"
        >
          <div class="capture-line-copy">
            <div class="capture-line-title">{{ index + 1 }}. {{ planTitle(plan, index) }}</div>
            <div v-if="planMeta(plan)" class="capture-line-meta">{{ planMeta(plan) }}</div>
            <div v-if="planNeedsIdentityReview(plan)" class="capture-line-warning">
              Weak identity match: do not attach unless later evidence supports the full person.
            </div>
          </div>
          <div class="capture-line-controls">
            <select
              class="capture-line-select"
              :value="captureLineValue(index, 'action')"
              @change="setCaptureLineDecision(index, 'action', $event.target.value)"
            >
              <option value="">Decision</option>
              <option value="attach" :disabled="planNeedsIdentityReview(plan)">Attach to tree</option>
              <option value="reject">Reject</option>
              <option value="needs_research">Needs research</option>
              <option value="ignore_for_now">Ignore for now</option>
            </select>
            <select
              class="capture-line-select"
              :value="captureLineValue(index, 'reason_code')"
              @change="setCaptureLineDecision(index, 'reason_code', $event.target.value)"
            >
              <option value="">Reason</option>
              <option value="source_verified">Source verified</option>
              <option value="wrong_person">Wrong person</option>
              <option value="partial_name_only">Partial name only</option>
              <option value="date_conflict">Date conflict</option>
              <option value="place_conflict">Place conflict</option>
              <option value="duplicate">Duplicate</option>
              <option value="insufficient_evidence">Insufficient evidence</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>
        <div v-if="captureApproveHint" class="capture-approve-hint">
          {{ captureApproveHint }}
        </div>
      </div>

      <!-- Actions Row -->
      <div v-if="isReviewPacket" class="card-actions card-actions-inspection">
        <button
          @click.stop="$emit('select', item)"
          class="btn-custom btn-open-packet"
          :disabled="inFlight"
          title="Open packet detail to review source, claim, person, and preview context"
        >
          Open packet
        </button>
      </div>
      <div v-else class="card-actions">
        <button
          @click.stop="$emit('approve', approvalEmitItem)"
          class="btn-approve"
          :disabled="inFlight || approvalDisabled || captureApproveDisabled"
          :title="captureApproveHint || approvalDisabledTitle"
        >
          {{ approvalLabel }}
        </button>
        <button @click.stop="$emit('reject', approvalEmitItem)" class="btn-reject" :disabled="inFlight">
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
          :title="remediationHoldTitle(item.remediation)"
        >
          {{ remediationHoldLabel(item.remediation) }}
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

const readableKind = computed(() => {
  return stringOrNull(props.item?.review_type)
    || stringOrNull(props.item?.source)
    || stringOrNull(props.item?.category)
})

const readableTitle = computed(() => {
  return stringOrNull(props.item?.title) || stringOrNull(props.item?.label)
})

const readableSummary = computed(() => {
  return compactReadableText(
    stringOrNull(props.item?.summary)
      || stringOrNull(props.item?.details_human)
      || stringOrNull(props.item?.description)
  )
})

const readableConfidence = computed(() => {
  if (props.item?.confidence === null || props.item?.confidence === undefined) {
    return null
  }

  const value = Number.parseFloat(props.item.confidence)
  if (!Number.isFinite(value)) {
    return null
  }

  const percent = value > 1 ? Math.round(value) : Math.round(value * 100)

  return `${percent}%`
})

const showReadableSummary = computed(() => Boolean(readableTitle.value || readableSummary.value))

const packetContextItems = computed(() => {
  if (!isReviewPacket.value) {
    return []
  }

  return [
    packetContextEntry('target', 'Target', compactTargetRef(packetFieldValue('target_ref'))),
    packetContextEntry(
      'person',
      'Person',
      stringOrNull(packetFocusFieldValue('person_label')) || formatPersonId(packetFieldValue('person_id'))
    ),
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
      compactLocator(packetFocusFieldValue('source_locator'))
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

const packetReadinessLine = computed(() => {
  if (!isReviewPacket.value) {
    return null
  }

  const readiness = props.item?.review_focus?.review_readiness
  if (readiness && typeof readiness === 'object' && !Array.isArray(readiness)) {
    const label = stringOrNull(readiness.label)
    if (label) {
      const state = stringOrNull(readiness.state) || 'unknown'
      const blockerCount = numberOrNull(readiness.blocker_count)
      const reason = stringOrNull(readiness.reason_code)

      return {
        state: normalizeReadinessState(state),
        label,
        title: [
          reason ? `Reason: ${formatPacketStatus(reason)}` : null,
          blockerCount && blockerCount > 0 ? `${blockerCount} blocker${blockerCount === 1 ? '' : 's'}` : null,
        ].filter(Boolean).join(' · ') || label,
      }
    }
  }

  const approvalReady = props.item?.review_focus?.approval_ready
  if (approvalReady === true) {
    return { state: 'ready', label: 'Ready for review', title: 'Ready for review' }
  }

  if (approvalReady === false) {
    const blockers = Array.isArray(props.item?.review_focus?.approval_blockers)
      ? props.item.review_focus.approval_blockers
      : []
    const firstBlocker = blockers.find((blocker) => blocker && typeof blocker === 'object' && !Array.isArray(blocker))
    const label = stringOrNull(firstBlocker?.label) || formatPacketStatus(firstBlocker?.code) || 'Approval readiness blocked'

    return {
      state: 'blocked',
      label: `Blocked: ${label}`,
      title: blockers.length ? `${blockers.length} blocker${blockers.length === 1 ? '' : 's'}` : label,
    }
  }

  return null
})

const packetOutcomeLine = computed(() => {
  if (!isReviewPacket.value) {
    return null
  }

  const outcome = props.item?.packet_outcome
  if (!outcome || typeof outcome !== 'object' || Array.isArray(outcome)) {
    return null
  }

  const label = stringOrNull(outcome.progress_label) || stringOrNull(outcome.outcome_label)
  if (!label) {
    return null
  }

  const reason = stringOrNull(outcome.latest_reason_label)
  const action = stringOrNull(outcome.latest_action_label)
  const decisionCount = numberOrNull(outcome.decision_count)
  const title = [
    action ? `Latest: ${action}` : null,
    reason ? `Reason: ${reason}` : null,
    decisionCount !== null ? `${decisionCount} decision log entr${decisionCount === 1 ? 'y' : 'ies'}` : null,
    outcome.preview_only === true ? 'Preview-only' : null,
  ].filter(Boolean).join(' · ') || label

  return {
    state: normalizeOutcomeState(outcome.outcome_state),
    label,
    title,
  }
})

const decisionObjectLine = computed(() => {
  if (isReviewPacket.value) {
    return 'Decision object: packet. Open detail before marking reviewed, rejecting, clarifying, or deferring.'
  }

  if (props.item?.review_type === 'genealogy_evidence_asset_capture' || props.item?.source === 'genealogy_evidence_asset_capture') {
    return 'Decision object: one media-capture approval row. This does not approve every source in the packet.'
  }

  if (props.item?.review_type === 'genealogy_finding' || props.item?.source === 'genealogy_finding') {
    return 'Decision object: advisory genealogy finding. Use Diagnostics unless it is explicitly approval-ready.'
  }

  return null
})

const capturePlans = computed(() => {
  if (props.item?.review_type !== 'genealogy_evidence_asset_capture' && props.item?.source !== 'genealogy_evidence_asset_capture') {
    return []
  }

  const plans = props.item?.details?.plans
  return Array.isArray(plans) ? plans : []
})

const capturePlanCount = computed(() => {
  if (capturePlans.value.length) {
    return capturePlans.value.length
  }

  const raw = Number(props.item?.details?.capture_plan_count)
  return Number.isFinite(raw) && raw > 0 ? raw : 0
})

const captureLineDecisions = ref({})

const captureLineDecisionPayload = computed(() => {
  return capturePlans.value
    .map((plan, index) => {
      const line = captureLineDecisions.value[index] || {}
      const action = typeof line.action === 'string' ? line.action.trim() : ''

      if (!action) {
        return null
      }

      const payload = {
        plan_index: index,
        action,
      }

      if (typeof line.reason_code === 'string' && line.reason_code.trim()) {
        payload.reason_code = line.reason_code.trim()
      }

      if (typeof line.notes === 'string' && line.notes.trim()) {
        payload.notes = line.notes.trim().slice(0, 240)
      }

      return payload
    })
    .filter(Boolean)
})

const captureUnresolvedCount = computed(() => {
  if (!capturePlanCount.value) {
    return 0
  }

  return Math.max(0, capturePlanCount.value - captureLineDecisionPayload.value.length)
})

const captureAttachCount = computed(() => captureLineDecisionPayload.value.filter(line => line.action === 'attach').length)

const captureBlockedAttachCount = computed(() => {
  return captureLineDecisionPayload.value.filter((line) => {
    if (line.action !== 'attach') {
      return false
    }

    const plan = capturePlans.value[line.plan_index] || {}
    return planNeedsIdentityReview(plan)
  }).length
})

const captureApproveDisabled = computed(() => {
  if (!capturePlanCount.value) {
    return false
  }

  return captureUnresolvedCount.value > 0 || captureAttachCount.value < 1 || captureBlockedAttachCount.value > 0
})

const captureApproveHint = computed(() => {
  if (!capturePlanCount.value) {
    return ''
  }

  if (!capturePlans.value.length) {
    return 'Capture plan details are missing. Refresh or regenerate this review row before approving.'
  }

  if (captureUnresolvedCount.value > 0) {
    return `Choose a line decision for ${captureUnresolvedCount.value} remaining candidate${captureUnresolvedCount.value === 1 ? '' : 's'}.`
  }

  if (captureAttachCount.value < 1) {
    return 'Select at least one candidate to attach, or reject the capture row.'
  }

  if (captureBlockedAttachCount.value > 0) {
    return 'Weak identity candidates cannot be attached. Reject, ignore, or mark those lines as needing research.'
  }

  return ''
})

const approvalEmitItem = computed(() => {
  if (!capturePlans.value.length) {
    return props.item
  }

  return {
    ...props.item,
    _line_decisions: captureLineDecisionPayload.value,
  }
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

const remediationHoldLabel = (remediation) => {
  if (remediation?.preview_only === true) {
    return 'Preview-only'
  }

  return remediation?.risk_level === 'destructive' ? 'Escalate' : 'Cooldown'
}

const remediationHoldTitle = (remediation) => {
  if (remediation?.preview_only === true) {
    return 'Apply held for preview-only remediation'
  }

  return remediation?.risk_level === 'destructive' ? 'Requires escalation' : 'In cooldown'
}

function captureLineValue(index, key) {
  const line = captureLineDecisions.value[index] || {}
  return typeof line[key] === 'string' ? line[key] : ''
}

function setCaptureLineDecision(index, key, value) {
  captureLineDecisions.value = {
    ...captureLineDecisions.value,
    [index]: {
      ...(captureLineDecisions.value[index] || {}),
      [key]: value,
    },
  }
}

function planTitle(plan, index) {
  return stringOrNull(plan?.title)
    || stringOrNull(plan?.label)
    || stringOrNull(plan?.source_title)
    || stringOrNull(plan?.url)
    || `Media candidate ${index + 1}`
}

function planMeta(plan) {
  return [
    stringOrNull(plan?.provider),
    stringOrNull(plan?.asset_type),
    stringOrNull(plan?.capture_policy),
    stringOrNull(plan?.person_key || plan?.family_key),
  ].filter(Boolean).join(' | ')
}

function planNeedsIdentityReview(plan) {
  const identityFit = plan?.identity_fit || {}
  return identityFit.approval_ready === false || identityFit.partial_name_only === true
}

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
  return str ? 'person reference' : null
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

const normalizeReadinessState = (value) => {
  const state = stringOrNull(value)
  if (state === 'ready' || state === 'blocked') {
    return state
  }

  return 'unknown'
}

const normalizeOutcomeState = (value) => {
  const state = stringOrNull(value)
  if (state === 'terminal' || state === 'follow_up' || state === 'touched' || state === 'pending') {
    return state
  }

  return 'unknown'
}

const compactLocator = (value) => {
  const str = stringOrNull(value)
  if (!str) {
    return null
  }

  return /^https?:\/\//i.test(str) ? 'external source' : 'source reference'
}

const compactText = (value) => {
  const str = stringOrNull(value)
  if (!str) {
    return null
  }

  return str.length <= 88 ? str : `${str.slice(0, 85)}...`
}

const compactReadableText = (value) => {
  const str = stringOrNull(value)
  if (!str) {
    return null
  }

  const cleaned = str
    .replace(/```json?\s*\n[\s\S]*?```/g, '')
    .replace(/```json?\s*\n[\s\S]*$/g, '')
    .replace(/\s+/g, ' ')
    .trim()

  if (!cleaned) {
    return null
  }

  return cleaned.length <= 260 ? cleaned : `${cleaned.slice(0, 257)}...`
}

const compactTargetRef = (value) => {
  const str = stringOrNull(value)
  if (!str) {
    return null
  }

  return str.replace(/^genealogy_review_packet:/, '')
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

.packet-context-chip.is-target {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
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

.packet-readiness-line {
  display: flex;
  align-items: center;
  min-height: 1.5rem;
  padding: 0.25rem 0.5rem;
  border-left: 3px solid rgba(99, 179, 237, 0.7);
  border-radius: 4px;
  background: rgba(99, 179, 237, 0.08);
  color: var(--ops-peach);
  font-size: 0.74rem;
  line-height: 1.3;
  overflow-wrap: anywhere;
}

.packet-readiness-line.is-ready {
  border-left-color: var(--ops-green);
  background: rgba(113, 231, 158, 0.08);
}

.packet-readiness-line.is-blocked {
  border-left-color: var(--ops-gold);
  background: rgba(255, 204, 102, 0.08);
}

.packet-outcome-line {
  display: flex;
  align-items: center;
  min-height: 1.5rem;
  padding: 0.25rem 0.5rem;
  border-left: 3px solid rgba(255, 204, 102, 0.7);
  border-radius: 4px;
  background: rgba(255, 204, 102, 0.08);
  color: var(--ops-peach);
  font-size: 0.74rem;
  line-height: 1.3;
  overflow-wrap: anywhere;
}

.packet-outcome-line.is-terminal {
  border-left-color: var(--ops-green);
  background: rgba(113, 231, 158, 0.08);
}

.packet-outcome-line.is-follow_up,
.packet-outcome-line.is-touched {
  border-left-color: var(--ops-sky);
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

.capture-line-decisions {
  display: grid;
  gap: 0.55rem;
  padding: 0.7rem;
  border: 1px solid rgba(255, 204, 102, 0.26);
  border-radius: 0 12px 12px 0;
  background: rgba(255, 204, 102, 0.06);
}

.capture-line-header {
  color: var(--ops-gold);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.capture-line {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(14rem, 0.45fr);
  gap: 0.75rem;
  align-items: start;
  padding: 0.55rem;
  border-left: 3px solid var(--ops-sky);
  background: rgba(99, 179, 237, 0.07);
}

.capture-line.needs-identity {
  border-left-color: var(--ops-orange);
  background: rgba(255, 153, 0, 0.08);
}

.capture-line-title {
  color: var(--ops-peach);
  font-size: 0.82rem;
  font-weight: 800;
  line-height: 1.3;
  overflow-wrap: anywhere;
}

.capture-line-meta,
.capture-line-warning,
.capture-approve-hint {
  color: var(--ops-sky);
  font-size: 0.72rem;
  line-height: 1.35;
}

.capture-line-warning,
.capture-approve-hint {
  color: var(--ops-gold);
}

.capture-line-controls {
  display: grid;
  gap: 0.4rem;
}

.capture-line-select {
  width: 100%;
  min-height: 2rem;
  border: 1px solid rgba(255, 204, 102, 0.28);
  border-radius: 0 8px 8px 0;
  background: var(--ops-black);
  color: var(--ops-peach);
  font-size: 0.72rem;
  padding: 0.25rem 0.45rem;
}

@media (max-width: 760px) {
  .capture-line {
    grid-template-columns: 1fr;
  }
}

.card-actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--ops-black);
}

.decision-object-line {
  display: inline-flex;
  width: fit-content;
  max-width: 100%;
  padding: 0.28rem 0.55rem;
  border: 1px solid rgba(99, 179, 237, 0.28);
  border-radius: 0 8px 8px 0;
  background: rgba(99, 179, 237, 0.09);
  color: var(--ops-sky);
  font-size: 0.72rem;
  font-weight: 700;
  line-height: 1.3;
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

.readable-summary {
  display: grid;
  gap: 0.32rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid rgba(255, 204, 102, 0.22);
  border-radius: 0 12px 12px 0;
  background: linear-gradient(90deg, rgba(255, 153, 0, 0.13), rgba(99, 179, 237, 0.06));
}

.readable-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
}

.readable-kind,
.readable-confidence {
  display: inline-flex;
  align-items: center;
  width: fit-content;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  background: var(--card-accent, var(--ops-orange));
  color: var(--ops-black);
  font-size: 0.62rem;
  font-weight: 800;
  line-height: 1.2;
  text-transform: uppercase;
}

.readable-confidence {
  background: var(--ops-green);
}

.readable-title {
  color: var(--ops-peach);
  font-size: 0.92rem;
  font-weight: 800;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.readable-body {
  color: var(--ops-sky);
  font-size: 0.8rem;
  line-height: 1.38;
  overflow-wrap: anywhere;
}
</style>

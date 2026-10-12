<template>
  <div class="detail-pane">
    <div v-if="loading" class="pane-state">
      <div class="ops-spinner"></div>
      <span class="ml-3 text-ops-text-muted text-sm">Loading review context…</span>
    </div>

    <div v-else-if="error" class="pane-state pane-error">
      <div class="text-ops-red text-sm">Failed to load: {{ error }}</div>
      <button class="ops-btn ops-btn-plum text-xs mt-3" @click="fetchContext">Retry</button>
    </div>

    <div v-else-if="!context" class="pane-state">
      <div class="text-ops-text-muted text-sm">Select a review item from the list to see the side-by-side compare.</div>
    </div>

    <template v-else>
      <div v-if="decisionError" class="decision-error" role="alert">
        {{ decisionError }}
      </div>
      <div v-if="pendingDateRequest" class="decision-error" role="status">
        Date decision {{ pendingDateRequest.reference }} needs reconciliation.
        <button type="button" :disabled="actioning" @click="reconcileDateRequest">Reconcile this saved date decision</button>
      </div>

      <component
        :is="layoutComponent"
        :context="context"
        :actioning="actioning || !!pendingDateRequest"
        :decision-reset-token="decisionResetToken"
        @approve="handleApprove"
        @reject="handleReject"
        @clarify="handleClarify"
        @defer="handleDefer"
        @applied="handleApplied"
        @close="$emit('close')"
      />
      <PhotoReviewEvidence v-if="context.item?.review_type !== 'scan_date_estimate' && photoEvidenceSelector(context)" :selector="photoEvidenceSelector(context)" />
    </template>
  </div>
</template>

<script setup>
/**
 * Type dispatcher: routes the enriched context payload to the
 * type-appropriate detail layout.
 *   Phase 1 — Layout A (GenealogyFindingDetail) for genealogy_finding
 *   Phase 4 — Layout B (GenealogyMergeDetail) for genealogy_merge
 *   Phase 4 — Layout C (RelationshipProposalDetail) for relationship-
 *             type findings (proposals where change_type starts with
 *             "add_" — add_parent / add_child / add_sibling / add_spouse).
 *             Falls back to Layout A when no relationship proposal
 *             exists, since Layout A handles plain source_add cleanly.
 */
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { clearReviewRequest, readReviewRequest, reviewRequestKey, reviewStorage, saveReviewRequest } from '../../utils/reviewDesk'
import GenealogyFindingDetail from './GenealogyFindingDetail.vue'
import GenealogyMergeDetail from './GenealogyMergeDetail.vue'
import GenealogyReviewPacketDetail from './GenealogyReviewPacketDetail.vue'
import RelationshipProposalDetail from './RelationshipProposalDetail.vue'
import AiModelUpdateReviewDetail from './AiModelUpdateReviewDetail.vue'
import FaceMatchDetail from './FaceMatchDetail.vue'
import PrivacyRequestDetail from './PrivacyRequestDetail.vue'
import ScanDateEstimateReviewDetail from './ScanDateEstimateReviewDetail.vue'
import ScanDateLegacyMetadataDetail from './ScanDateLegacyMetadataDetail.vue'
import SkillAuditReviewDetail from './SkillAuditReviewDetail.vue'
import GenericReviewDetail from './GenericReviewDetail.vue'
import PhotoReviewEvidence from './PhotoReviewEvidence.vue'
import { photoEvidenceSelector } from './photoEvidenceSelector'

const props = defineProps({
  unifiedId: { type: String, default: null },
})
const emit = defineEmits(['approve', 'reject', 'clarify', 'defer', 'applied', 'close'])

const context = ref(null)
const loading = ref(false)
const error = ref(null)
const decisionError = ref(null)
const actioning = ref(false)
const decisionResetToken = ref(0)
const pendingDateRequest = ref(null)
const dateReview = ref(null)
const tabStorage = reviewStorage()
let contextRequest = 0

const layoutComponent = computed(() => {
  const ctx = context.value
  if (!ctx) return null
  const type = ctx.item?.review_type
  if (type === 'genealogy_merge') {
    return GenealogyMergeDetail
  }
  if (type === 'genealogy_review_packet') {
    return GenealogyReviewPacketDetail
  }
  if (type === 'ai_model_update') {
    return AiModelUpdateReviewDetail
  }
  if (type === 'face') {
    return FaceMatchDetail
  }
  if (type === 'privacy') {
    return PrivacyRequestDetail
  }
  if (type === 'scan_date_estimate') {
    return ScanDateEstimateReviewDetail
  }
  if (type === 'scan_date_legacy_metadata') {
    return ScanDateLegacyMetadataDetail
  }
  if (type === 'skill_optimization' && isSkillAuditReview(ctx)) {
    return SkillAuditReviewDetail
  }
  if (type === 'genealogy_finding' && hasRelationshipProposal(ctx)) {
    return RelationshipProposalDetail
  }
  if (type === 'genealogy_finding' || type === 'change_proposal') {
    return GenealogyFindingDetail
  }

  return GenericReviewDetail
})

function isSkillAuditReview(ctx) {
  return (ctx?.item?.finding_type || ctx?.item?.details?.finding_type) === 'skill_audit'
    || ctx?.item?.details?.schema === 'agent_skill_audit_review_queue.v2'
}

function hasRelationshipProposal(ctx) {
  const proposals = ctx?.item?.details?.proposals || []
  return proposals.some((p) => {
    const ct = (p.change_type || '').toLowerCase()
    return ct.startsWith('add_')
  })
}

watch(() => props.unifiedId, (id) => {
  if (id) fetchContext()
  else { contextRequest++; context.value = null; pendingDateRequest.value = null; dateReview.value = null }
}, { immediate: true })

async function fetchContext() {
  if (!props.unifiedId) return
  const request = ++contextRequest
  const unifiedId = props.unifiedId
  loading.value = true
  error.value = null
  decisionError.value = null
  pendingDateRequest.value = null
  dateReview.value = null
  try {
    const { data } = await axios.get(`/api/research-hub/items/${encodeURIComponent(unifiedId)}/context`)
    if (request !== contextRequest || props.unifiedId !== unifiedId) return
    context.value = data
    if (data.item?.review_type === 'scan_date_estimate') {
      const { data: current } = await axios.get(`/api/review-desk/items/${encodeURIComponent(unifiedId)}`)
      if (request !== contextRequest || props.unifiedId !== unifiedId) return
      if (current.context?.item?.unified_id !== unifiedId || current.review_type !== 'scan_date_estimate') throw new Error('The exact date review is unavailable.')
      // Display and submit the same retained proposal/revision. A lookup at
      // click time must not silently authorize a newer, unseen estimate.
      context.value = current.context
      dateReview.value = { reference: current.reference, revision: current.revision }
      pendingDateRequest.value = readReviewRequest(tabStorage, current.reference)
    }
  } catch (e) {
    if (request !== contextRequest) return
    error.value = e.response?.data?.error || e.message || 'unknown error'
    context.value = null
  } finally {
    if (request === contextRequest) loading.value = false
  }
}

async function handleApprove(payload) {
  await postDecision('approve', payload, 'approve')
}

async function handleReject(payload) {
  await postDecision('reject', payload, 'reject')
}

async function handleClarify(payload) {
  await postDecision('clarify', payload, 'clarify', { refresh: true })
}

async function handleDefer(payload) {
  await postDecision('defer', payload, 'defer', { refresh: true })
}

async function postDecision(action, payload, eventName, options = {}) {
  if (actioning.value || pendingDateRequest.value) return

  const decision = normalizeDecisionPayload(payload)
  if (!decision.unifiedId) return

  actioning.value = true
  decisionError.value = null
  try {
    let data
    if (context.value?.item?.review_type === 'scan_date_estimate' && ['approve', 'reject'].includes(action)) {
      if (!pendingDateRequest.value) {
        if (!dateReview.value) throw new Error('Load the exact date review before deciding.')
        const current = dateReview.value
        pendingDateRequest.value = { reference: current.reference, unifiedId: decision.unifiedId, body: { ...decisionBody(action, decision),
          action, revision: current.revision, idempotency_key: reviewRequestKey() } }
      }
      const request = pendingDateRequest.value
      if (!saveReviewRequest(tabStorage, request.reference, request)) { pendingDateRequest.value = null; throw new Error('Tab storage is unavailable; this date decision was not submitted.') }
      const response = await axios.post(`/api/review-desk/items/${encodeURIComponent(request.reference)}/decisions`, request.body)
      data = response.data
      clearReviewRequest(tabStorage, request.reference)
      pendingDateRequest.value = null
    } else {
      const response = await axios.post(`/api/research-hub/${action}/${encodeURIComponent(decision.unifiedId)}`, decisionBody(action, decision))
      data = response.data
    }
    if (options.refresh) {
      await fetchContext()
    }
    emit(eventName, {
      unifiedId: decision.unifiedId,
      result: data,
    })
    decisionResetToken.value++
  } catch (e) {
    const payload = e.response?.data || {}
    if (e.response && e.response.status < 500 && !['processing', 'unknown'].includes(payload.receipt?.state)) {
      if (pendingDateRequest.value) clearReviewRequest(tabStorage, pendingDateRequest.value.reference)
      pendingDateRequest.value = null
    }
    decisionError.value = payload.receipt?.error || payload.error || payload.message || e.message || `${action} failed`
  } finally {
    actioning.value = false
  }
}

async function reconcileDateRequest() {
  if (actioning.value || !pendingDateRequest.value) return
  const request = pendingDateRequest.value
  actioning.value = true
  try {
    const { data } = await axios.post(`/api/review-desk/items/${encodeURIComponent(request.reference)}/decisions`, request.body)
    clearReviewRequest(tabStorage, request.reference)
    pendingDateRequest.value = null
    decisionError.value = null
    emit(request.body.action, { unifiedId: request.unifiedId, result: data })
    decisionResetToken.value++
  } catch (e) {
    const payload = e.response?.data || {}
    if (e.response && e.response.status < 500 && !['processing', 'unknown'].includes(payload.receipt?.state)) {
      clearReviewRequest(tabStorage, request.reference)
      pendingDateRequest.value = null
    }
    decisionError.value = payload.receipt?.error || payload.error || payload.message || 'The date decision remains unconfirmed.'
  } finally { actioning.value = false }
}

function normalizeDecisionPayload(payload) {
  if (payload && typeof payload === 'object') {
    return {
      unifiedId: payload.unifiedId || payload.unified_id || props.unifiedId,
      notes: typeof payload.notes === 'string' ? payload.notes.trim() : null,
      reasonCode: typeof payload.reasonCode === 'string'
        ? payload.reasonCode.trim()
        : (typeof payload.reason_code === 'string' ? payload.reason_code.trim() : null),
      fieldsToSubmit: Array.isArray(payload.fieldsToSubmit)
        ? payload.fieldsToSubmit
        : (Array.isArray(payload.fields_to_submit) ? payload.fields_to_submit : []),
      profileUrl: typeof payload.profileUrl === 'string'
        ? payload.profileUrl.trim()
        : (typeof payload.profile_url === 'string' ? payload.profile_url.trim() : null),
      decisionScope: typeof payload.decisionScope === 'string'
        ? payload.decisionScope.trim()
        : (typeof payload.decision_scope === 'string' ? payload.decision_scope.trim() : null),
      sourceSha256: typeof payload.sourceSha256 === 'string' ? payload.sourceSha256 : null,
      assetUuid: typeof payload.assetUuid === 'string' ? payload.assetUuid : null,
    }
  }

  return {
    unifiedId: payload || props.unifiedId,
    notes: null,
    reasonCode: null,
    fieldsToSubmit: [],
    profileUrl: null,
    decisionScope: null,
  }
}

function decisionBody(action, decision) {
  const body = {}
  if (decision.notes) {
    body[action === 'reject' ? 'reason' : 'notes'] = decision.notes
  }
  if (decision.reasonCode) {
    body.reason_code = decision.reasonCode
  }
  if (decision.fieldsToSubmit.length) {
    body.fields_to_submit = decision.fieldsToSubmit
  }
  if (decision.profileUrl) {
    body.profile_url = decision.profileUrl
  }
  if (decision.decisionScope) {
    body.decision_scope = decision.decisionScope
  }
  if (decision.decisionScope === 'write_scan_estimated_year') {
    body.source_sha256 = decision.sourceSha256
    body.asset_uuid = decision.assetUuid
  }
  return body
}

// Phase 3: per-field apply already POSTed inside the layout; just bubble.
function handleApplied(payload) {
  emit('applied', payload)
}
</script>

<style scoped>
.detail-pane {
  background: var(--color-bg-tertiary);
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  padding: 1rem;
  min-height: 20rem;
  position: sticky;
  top: 1rem;
  max-height: calc(100vh - 2rem);
  overflow-y: auto;
}
.pane-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 16rem;
  text-align: center;
}
.pane-error { color: var(--ops-danger); }
.decision-error {
  border: 1px solid var(--ops-danger);
  background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));
  color: var(--ops-danger);
  border-radius: 0.375rem;
  padding: 0.75rem;
  margin-bottom: 0.875rem;
  font-size: 0.875rem;
  line-height: 1.4;
}
@media (max-width: 1023px) {
  .detail-pane {
    position: relative;
    top: auto;
    max-height: none;
    overflow-y: visible;
  }
}
</style>

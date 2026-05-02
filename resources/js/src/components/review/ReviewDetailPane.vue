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

    <component
      v-else
      :is="layoutComponent"
      :context="context"
      :actioning="actioning"
      :decision-reset-token="decisionResetToken"
      @approve="handleApprove"
      @reject="handleReject"
      @clarify="handleClarify"
      @defer="handleDefer"
      @applied="handleApplied"
      @close="$emit('close')"
    />
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
import GenealogyFindingDetail from './GenealogyFindingDetail.vue'
import GenealogyMergeDetail from './GenealogyMergeDetail.vue'
import GenealogyReviewPacketDetail from './GenealogyReviewPacketDetail.vue'
import RelationshipProposalDetail from './RelationshipProposalDetail.vue'

const props = defineProps({
  unifiedId: { type: String, default: null },
})
const emit = defineEmits(['approve', 'reject', 'clarify', 'defer', 'applied', 'close'])

const context = ref(null)
const loading = ref(false)
const error = ref(null)
const actioning = ref(false)
const decisionResetToken = ref(0)

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
  if (type === 'genealogy_finding' && hasRelationshipProposal(ctx)) {
    return RelationshipProposalDetail
  }
  // Default for genealogy_finding (and any future single-person types).
  return GenealogyFindingDetail
})

function hasRelationshipProposal(ctx) {
  const proposals = ctx?.item?.details?.proposals || []
  return proposals.some((p) => {
    const ct = (p.change_type || '').toLowerCase()
    return ct.startsWith('add_')
  })
}

watch(() => props.unifiedId, (id) => {
  if (id) fetchContext()
  else context.value = null
}, { immediate: true })

async function fetchContext() {
  if (!props.unifiedId) return
  loading.value = true
  error.value = null
  try {
    const { data } = await axios.get(`/api/research-hub/items/${encodeURIComponent(props.unifiedId)}/context`)
    context.value = data
  } catch (e) {
    error.value = e.response?.data?.error || e.message || 'unknown error'
    context.value = null
  } finally {
    loading.value = false
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
  if (actioning.value) return

  const decision = normalizeDecisionPayload(payload)
  if (!decision.unifiedId) return

  actioning.value = true
  try {
    const { data } = await axios.post(
      `/api/research-hub/${action}/${encodeURIComponent(decision.unifiedId)}`,
      decisionBody(action, decision.notes, decision.reasonCode)
    )
    if (options.refresh) {
      await fetchContext()
    }
    emit(eventName, {
      unifiedId: decision.unifiedId,
      result: data,
    })
    decisionResetToken.value++
  } catch (e) {
    error.value = e.response?.data?.error || e.message || `${action} failed`
  } finally {
    actioning.value = false
  }
}

function normalizeDecisionPayload(payload) {
  if (payload && typeof payload === 'object') {
    return {
      unifiedId: payload.unifiedId || payload.unified_id || props.unifiedId,
      notes: typeof payload.notes === 'string' ? payload.notes.trim() : null,
      reasonCode: typeof payload.reasonCode === 'string'
        ? payload.reasonCode.trim()
        : (typeof payload.reason_code === 'string' ? payload.reason_code.trim() : null),
    }
  }

  return {
    unifiedId: payload || props.unifiedId,
    notes: null,
    reasonCode: null,
  }
}

function decisionBody(action, notes, reasonCode) {
  const body = {}
  if (notes) {
    body[action === 'reject' ? 'reason' : 'notes'] = notes
  }
  if (reasonCode) {
    body.reason_code = reasonCode
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
  background: rgba(0, 0, 0, 0.30);
  border: 1px solid rgba(99, 51, 153, 0.40);
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
.pane-error { color: #ff8080; }
</style>

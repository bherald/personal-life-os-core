<template>
  <section class="face-review" aria-labelledby="face-review-title">
    <header class="face-header">
      <div class="min-w-0">
        <div class="face-kind">Picture identity</div>
        <h2 id="face-review-title">Is this {{ suggestedPersonName }}?</h2>
        <p v-if="lifeDates">{{ lifeDates }}</p>
      </div>
      <button type="button" class="close-button" aria-label="Close picture match review" @click="emit('close')">&times;</button>
    </header>

    <div v-if="!review.approval_ready" class="blocking-banner" role="alert">
      <strong>Approval blocked</strong>
      <span>{{ blockerText }}</span>
    </div>

    <section class="identity-proposal" aria-label="Suggested identity">
      <div>
        <span class="fact-label">Suggested person</span>
        <strong>{{ suggestedPersonName }}</strong>
      </div>
      <div>
        <span class="fact-label">PLOS estimate</span>
        <strong>{{ confidenceLabel }}</strong>
      </div>
    </section>

    <FaceReviewEvidence :item="item" />

    <section class="decision-dock" aria-labelledby="identity-decision-title">
      <div class="decision-heading">
        <h3 id="identity-decision-title">Your decision</h3>
      </div>

      <div class="decision-actions">
        <button
          type="button"
          class="approve-button"
          :disabled="actioning || !review.approval_ready"
          :title="review.confirmation_label"
          @click="approve"
        >
          Yes, link to {{ suggestedPersonName }}
        </button>
        <button type="button" class="reject-button" :disabled="actioning" @click="reject">
          No, not this person
        </button>
        <button type="button" class="close-action" :disabled="actioning" @click="emit('close')">Skip</button>
      </div>

      <details class="optional-details">
        <summary>Suggestion details and note</summary>
        <div class="detail-facts">
          <span>Current label</span><strong>{{ item.face_name || 'Unlabeled' }}</strong>
          <span>Match method</span><strong>{{ matchTypeLabel || 'Not recorded' }}</strong>
          <span>Approve</span><strong>{{ review.approval_effect || 'Links this face to the suggested FT person.' }}</strong>
          <span>Reject</span><strong>{{ review.rejection_effect || 'Leaves this face without that identity link.' }}</strong>
        </div>
        <label class="notes-field">
          <span>Optional note</span>
          <textarea
            v-model="notes"
            rows="2"
            maxlength="1000"
            :disabled="actioning"
            placeholder="Optional approval context or rejection reason"
          ></textarea>
        </label>
      </details>
    </section>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import FaceReviewEvidence from './FaceReviewEvidence.vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
  decisionResetToken: { type: Number, default: 0 },
})
const emit = defineEmits(['approve', 'reject', 'close'])

const notes = ref('')
const item = computed(() => props.context?.item || {})
const review = computed(() => props.context?.face_review || {})
const suggestedPersonName = computed(() => item.value.suggested_person_name || 'the suggested person')
const blockerText = computed(() => {
  const labels = {
    suggested_identity_missing: 'No suggested person is available.',
    visible_image_missing: 'The image evidence is not available.',
    detected_region_missing: 'The detected face region is not available.',
    approval_route_unavailable: 'The identity approval route is unavailable.',
  }

  return (review.value.blockers || []).map(blocker => labels[blocker] || `${blocker}`.replaceAll('_', ' ')).join(' ')
    || 'Required identity evidence is incomplete.'
})
const confidenceLabel = computed(() => {
  const value = Number(item.value.confidence)
  return Number.isFinite(value) ? `${Math.round(value * 100)}% confidence` : 'Not scored'
})
const matchTypeLabel = computed(() => `${item.value.match_type || ''}`.replaceAll('_', ' '))
const lifeDates = computed(() => {
  const birth = item.value.suggested_person_birth_date || '?'
  const death = item.value.suggested_person_death_date || '?'
  return birth !== '?' || death !== '?' ? `${birth} - ${death}` : ''
})

watch(() => props.context?.item?.unified_id, resetDecisionInputs)
watch(() => props.decisionResetToken, resetDecisionInputs)

function resetDecisionInputs() {
  notes.value = ''
}

function approve() {
  if (!review.value.approval_ready) return
  emit('approve', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    decisionScope: review.value.decision_scope,
  })
}

function reject() {
  emit('reject', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    decisionScope: review.value.rejection_scope,
  })
}
</script>

<style scoped>
.face-review { color: var(--color-text-primary); min-width: 0; }
.face-header { display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid var(--color-border); padding-bottom: .8rem; }
.face-kind { color: var(--color-accent); font-size: .68rem; font-weight: 800; text-transform: uppercase; }
.face-header h2 { margin: .15rem 0 .35rem; font-size: 1.08rem; line-height: 1.35; overflow-wrap: anywhere; }
.face-header p { margin: 0; color: var(--color-text-primary); font-size: .78rem; line-height: 1.45; }
.close-button { margin-left: auto; width: 2rem; height: 2rem; flex: 0 0 2rem; border: 1px solid var(--color-border); color: var(--color-text-primary); font-size: 1.25rem; line-height: 1; }
.blocking-banner { display: grid; gap: .2rem; margin-top: 1rem; border-left: 3px solid var(--ops-danger); background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary)); padding: .7rem; font-size: .76rem; }
.blocking-banner strong { color: var(--ops-danger); text-transform: uppercase; font-size: .68rem; }
.identity-proposal { display: grid; grid-template-columns: minmax(0, 2fr) minmax(7rem, 1fr); gap: .75rem; margin: 1rem 0; }
.identity-proposal > div { display: grid; align-content: start; gap: .2rem; min-width: 0; border-left: 2px solid var(--color-border); padding-left: .65rem; }
.identity-proposal strong { font-size: .86rem; overflow-wrap: anywhere; }
.fact-label { color: var(--color-text-secondary); font-size: .62rem; font-weight: 700; text-transform: uppercase; }
.life-dates, .match-type { color: var(--color-text-primary); font-size: .7rem; overflow-wrap: anywhere; }
.decision-dock { margin-top: 1rem; border: 1px solid var(--ops-success); background: var(--color-bg-secondary); padding: .85rem; }
.decision-heading { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; }
.decision-heading h3 { margin: 0; font-size: .84rem; text-transform: uppercase; }
.decision-heading span { color: var(--color-text-secondary); font-size: .64rem; text-transform: uppercase; }
.optional-details { margin-top: .7rem; border-top: 1px solid var(--color-border); padding-top: .55rem; }
.optional-details summary { width: fit-content; cursor: pointer; color: var(--color-text-primary); font-size: .72rem; }
.detail-facts { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: .3rem .65rem; margin-top: .6rem; font-size: .7rem; line-height: 1.4; }
.detail-facts span { color: var(--color-text-secondary); }
.detail-facts strong { overflow-wrap: anywhere; }
.notes-field { display: grid; gap: .3rem; color: var(--color-text-secondary); font-size: .64rem; font-weight: 700; text-transform: uppercase; }
.optional-details .notes-field { margin-top: .65rem; }
.notes-field textarea { width: 100%; resize: vertical; border: 1px solid var(--color-border); background: var(--color-bg-secondary); color: var(--color-text-primary); padding: .5rem; font-size: .78rem; text-transform: none; }
.decision-actions { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .7rem; }
.decision-actions button { min-height: 2.45rem; padding: .5rem .8rem; font-size: .76rem; font-weight: 800; }
.approve-button { background: var(--ops-success); color: var(--color-text-secondary); }
.reject-button { border: 1px solid var(--ops-danger); color: var(--ops-danger); }
.close-action { border: 1px solid var(--color-border); color: var(--color-text-primary); }
button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 640px) {
  .identity-proposal { grid-template-columns: 1fr; }
  .detail-facts { grid-template-columns: 1fr; gap: .1rem; }
  .detail-facts strong { margin-bottom: .35rem; }
  .decision-actions button { width: 100%; }
}
</style>

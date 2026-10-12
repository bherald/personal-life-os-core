<template>
  <section class="scan-date-review" aria-labelledby="scan-date-review-title">
    <header class="review-header">
      <div class="min-w-0">
        <div class="review-kind">Scanned photo date</div>
        <h2 id="scan-date-review-title">Is this estimated photo date correct?</h2>
        <p>{{ details.filename || item.title || 'Scanned photograph' }}</p>
      </div>
      <button type="button" class="close-button" aria-label="Close scan-date review" @click="emit('close')">&times;</button>
    </header>

    <div class="evidence-layout">
      <section class="date-comparison" aria-label="Date comparison">
        <div class="date-row scan-date">
          <span>Digitized</span>
          <strong>{{ readableDate(details.scan_date) }}</strong>
          <small>Scanner or import date retained separately</small>
        </div>
        <div class="date-row proposed-date">
          <span>{{ isYearRange ? 'Proposed original-photo year range' : 'Proposed original-photo year' }}</span>
          <strong>{{ readableEstimate }}</strong>
          <small>{{ isYearRange ? 'Approximate interval; no endpoint is treated as an exact date' : 'Approximate year only' }} - {{ confidenceLabel }}</small>
        </div>
        <div class="reasoning">
          <h3>Visual evidence assessment</h3>
          <p>{{ details.ai_reasoning || 'No reasoning was retained with this estimate.' }}</p>
        </div>
      </section>
    </div>
    <PhotoReviewEvidence
      :selector="photoSelector"
      @evidence-loaded="onEvidenceLoaded"
      @preview-ready="onPreviewReady"
    />

    <section class="effect-boundary" aria-labelledby="scan-date-effect-title">
      <h3 id="scan-date-effect-title">Choose the photo-date outcome</h3>
      <div class="effect-grid">
        <div class="approve-effect">
          <strong>{{ isYearRange ? 'Accept approximate interval' : 'Accept approximate year' }}</strong>
          <span v-if="isYearRange">Keeps the full interval for catalog sorting and search. No single endpoint is written as the photo date.</span>
          <span v-else>Accepts the approximate year for catalog sorting and search only. The photo file is not changed.</span>
          <span>The scanner date remains preserved separately.</span>
        </div>
        <div v-if="canWriteYear" class="write-effect">
          <strong>Accept and write approximate year</strong>
          <span>Separately approves this year for the verified current source. It is written to XMP with year precision; no exact day is invented.</span>
          <span>The digitization date remains in its separate metadata field.</span>
        </div>
        <div class="reject-effect">
          <strong>Reject suggestion</strong>
          <span>Clears only the AI date suggestion and preserves the separate digitization date.</span>
          <span>The image file is not changed now.</span>
        </div>
      </div>

      <div class="decision-inputs">
        <label>
          <span>Rejection reason <strong>Required to reject</strong></span>
          <select v-model="reasonCode" :disabled="actioning">
            <option value="">Choose reason</option>
            <option value="wrong_date">Wrong date</option>
            <option value="insufficient_visual_evidence">Insufficient visual evidence</option>
            <option value="not_a_scan">Not a scanned photograph</option>
            <option value="duplicate">Duplicate review</option>
            <option value="other">Other</option>
          </select>
        </label>
        <details class="optional-note">
          <summary>Add a note</summary>
          <label>
            <span>Decision note <small>Optional</small></span>
            <textarea v-model="notes" rows="2" maxlength="2000" :disabled="actioning"></textarea>
          </label>
        </details>
      </div>

      <div class="decision-actions">
        <button type="button" class="approve-button" :disabled="actioning" @click="approveCatalog">
          Accept {{ readableEstimate }} for catalog
        </button>
        <button
          v-if="canWriteYear"
          type="button"
          class="write-button"
          :disabled="actioning || !writeEvidenceReady"
          @click="approveAndWrite"
        >Accept and write approximate year</button>
        <button type="button" class="reject-button" :disabled="actioning || !reasonCode" @click="reject">
          Reject suggestion
        </button>
        <button type="button" class="close-action" :disabled="actioning" @click="emit('close')">Close</button>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import PhotoReviewEvidence from './PhotoReviewEvidence.vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
  decisionResetToken: { type: Number, default: 0 },
})
const emit = defineEmits(['approve', 'reject', 'close'])
const reasonCode = ref('')
const notes = ref('')
const evidenceBundle = ref(null)
const previewProof = ref(null)
const item = computed(() => props.context?.item || {})
const details = computed(() => item.value.details || {})
const photoSelector = computed(() => details.value.asset_uuid ? { asset_uuid: details.value.asset_uuid } : null)
const writeEvidenceReady = computed(() => {
  const uuid = details.value.asset_uuid
  const currentHash = evidenceBundle.value?.source?.source_sha256
  return !!uuid
    && evidenceBundle.value?.source?.status === 'verified_current'
    && evidenceBundle.value?.asset?.asset_uuid === uuid
    && /^[a-f0-9]{64}$/i.test(currentHash || '')
    && previewProof.value?.asset_uuid === uuid
    && previewProof.value?.source_sha256 === currentHash
})
const confidenceLabel = computed(() => {
  const confidence = Number(details.value.ai_confidence)
  const source = `${details.value.ai_source || 'AI visual estimate'}`.replaceAll('_', ' ')
  return Number.isFinite(confidence) ? `${Math.round(confidence * 100)}% confidence - ${source}` : source
})
const readableYear = computed(() => {
  const year = Number(details.value.proposed_year)
  if (Number.isInteger(year) && year >= 1000 && year <= 9999) return `${year}`
  const match = `${details.value.proposed_date || ''}`.match(/^(\d{4})/)
  return match ? match[1] : 'Unknown year'
})
const proposedPrecision = computed(() => `${details.value.proposed_precision || 'year'}`)
const canWriteYear = computed(() => proposedPrecision.value === 'year')
const yearRange = computed(() => {
  const typedRange = details.value.year_range
  if (typedRange && Number.isInteger(Number(typedRange.start)) && Number.isInteger(Number(typedRange.end))) {
    return [Number(typedRange.start), Number(typedRange.end)]
  }
  const match = `${details.value.ai_reasoning || ''}`.match(/Estimated year range:\s*(\d{4})-(\d{4})/i)
  return match ? [Number(match[1]), Number(match[2])] : null
})
const isYearRange = computed(() => proposedPrecision.value === 'year_range')
const readableEstimate = computed(() => {
  if (!isYearRange.value) return readableYear.value
  return yearRange.value ? `${yearRange.value[0]}–${yearRange.value[1]}` : 'Range unavailable'
})

watch(() => item.value.unified_id, resetInputs, { immediate: true })
watch(() => props.decisionResetToken, resetInputs)

function resetInputs() {
  reasonCode.value = ''
  notes.value = ''
  evidenceBundle.value = null
  previewProof.value = null
}

function onEvidenceLoaded(bundle) {
  evidenceBundle.value = bundle?.source?.status === 'verified_current' ? bundle : null
  if (!bundle) previewProof.value = null
}

function onPreviewReady(proof) {
  previewProof.value = proof && typeof proof === 'object' ? proof : null
}

function readableDate(value) {
  if (!value) return 'Unknown'
  const match = `${value}`.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!match) return `${value}`
  if (match[1] === '1970' && match[2] === '01' && match[3] === '01') return 'Unknown'
  return `${match[2]}/${match[3]}/${match[1]}`
}

function approveCatalog() {
  emit('approve', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    decisionScope: 'catalog_date_only',
  })
}

function approveAndWrite() {
  if (!writeEvidenceReady.value || !canWriteYear.value) return
  emit('approve', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    decisionScope: 'write_scan_estimated_year',
    assetUuid: details.value.asset_uuid,
    sourceSha256: previewProof.value.source_sha256,
  })
}

function reject() {
  if (!reasonCode.value) return
  emit('reject', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    reasonCode: reasonCode.value,
    decisionScope: 'reject_scan_date_suggestion',
  })
}
</script>

<style scoped>
.scan-date-review { color: var(--color-text-primary); min-width: 0; }
.scan-date-review, .scan-date-review * { box-sizing: border-box; }
.review-header { display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid var(--color-border); padding-bottom: .8rem; }
.review-kind { color: var(--color-accent); font-size: .68rem; font-weight: 800; text-transform: uppercase; }
.review-header h2 { margin: .15rem 0 .3rem; font-size: 1.08rem; line-height: 1.35; }
.review-header p { margin: 0; color: var(--color-text-primary); font-size: .76rem; overflow-wrap: anywhere; }
.close-button { margin-left: auto; width: 2rem; height: 2rem; flex: 0 0 2rem; border: 1px solid var(--color-border); color: var(--color-text-primary); font-size: 1.25rem; line-height: 1; }
.evidence-layout { display: block; margin-top: 1rem; }
.date-comparison { display: grid; align-content: start; gap: .7rem; min-width: 0; }
.date-row { display: grid; gap: .15rem; border-left: 3px solid var(--color-border); background: var(--color-bg-tertiary); padding: .65rem; }
.date-row span, .reasoning h3, .effect-boundary h3 { color: var(--color-text-secondary); font-size: .64rem; font-weight: 800; text-transform: uppercase; }
.date-row strong { font-size: 1.02rem; }
.date-row small { color: var(--color-text-primary); font-size: .68rem; overflow-wrap: anywhere; }
.proposed-date { border-color: var(--color-border); background: var(--color-bg-tertiary); }
.reasoning { border-top: 1px solid var(--color-border); padding-top: .7rem; }
.reasoning h3 { margin: 0; }
.reasoning p { margin: .35rem 0 0; color: var(--color-text-primary); font-size: .75rem; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
.effect-boundary { margin-top: 1rem; border: 1px solid var(--ops-success); background: var(--color-bg-secondary); padding: .85rem; }
.effect-boundary > h3 { margin: 0; color: var(--color-text-primary); }
.effect-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: .65rem; margin-top: .7rem; }
.effect-grid > div { display: grid; gap: .25rem; min-width: 0; padding: .65rem; font-size: .72rem; line-height: 1.45; }
.approve-effect { border-left: 3px solid var(--ops-success); background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary)); }
.write-effect { border-left: 3px solid var(--ops-sky); background: color-mix(in srgb, var(--ops-sky) 12%, var(--color-bg-primary)); }
.reject-effect { border-left: 3px solid var(--ops-danger); background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary)); }
.approve-effect strong { color: var(--ops-success); }
.write-effect strong { color: var(--ops-sky); }
.reject-effect strong { color: var(--ops-danger); }
.decision-inputs { display: grid; grid-template-columns: 1fr 1.4fr; gap: .65rem; }
.decision-inputs label { display: grid; gap: .3rem; margin-top: .8rem; color: var(--color-text-primary); font-size: .68rem; }
.decision-inputs select, .decision-inputs textarea { width: 100%; border: 1px solid var(--color-border); background: var(--color-bg-secondary); color: var(--color-text-primary); padding: .5rem; font-size: .78rem; }
.decision-inputs textarea { resize: vertical; }
.optional-note { margin-top: .8rem; color: var(--color-text-primary); font-size: .72rem; }
.optional-note summary { cursor: pointer; min-height: 2rem; padding: .45rem 0; font-weight: 700; }
.decision-actions { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .75rem; }
.decision-actions button { min-height: 2.45rem; padding: .5rem .8rem; font-size: .75rem; font-weight: 800; }
.approve-button { background: var(--ops-success); color: var(--color-text-secondary); }
.write-button { border: 1px solid var(--ops-sky); color: var(--ops-sky); }
.reject-button { border: 1px solid var(--ops-danger); color: var(--ops-danger); }
.close-action { border: 1px solid var(--color-border); color: var(--color-text-primary); }
button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 720px) {
  .effect-grid, .decision-inputs { grid-template-columns: 1fr; }
  .decision-actions button { width: 100%; }
}
</style>

<template>
  <section class="generic-review" aria-labelledby="generic-review-title">
    <header class="review-header">
      <div class="min-w-0">
        <div class="review-kind">{{ readableType }}</div>
        <h2 id="generic-review-title">{{ item.title || 'Review item' }}</h2>
      </div>
      <button type="button" class="close-button" aria-label="Close review detail" @click="$emit('close')">&times;</button>
    </header>

    <p v-if="item.summary" class="review-summary">{{ item.summary }}</p>

    <dl class="review-metadata">
      <div v-if="item.agent_id">
        <dt>Produced by</dt>
        <dd>{{ item.agent_id }}</dd>
      </div>
      <div v-if="item.created_at">
        <dt>Created</dt>
        <dd>{{ formatDate(item.created_at) }}</dd>
      </div>
      <div>
        <dt>Status</dt>
        <dd>{{ item.status || 'pending' }}</dd>
      </div>
    </dl>

    <div class="held-boundary" :class="{ 'inspection-only': inspectionOnly }" role="status">
      <strong>{{ inspectionOnly ? 'Inspection only' : 'Purpose-built review required' }}</strong>
      <span v-if="inspectionOnly">Inspect the source evidence. This receipt provides no repair or approval action.</span>
      <span v-else>
        PLOS has not yet defined the evidence comparison, operator question, and exact effects for this review type.
        It is held from generic approval so you are not asked to decide from implementation data.
      </span>
    </div>

    <section v-if="sourceEvidence.length" class="source-evidence" aria-label="Source inspection receipt">
      <h3>Source inspection receipt</h3>
      <dl class="review-metadata">
        <div v-for="field in sourceEvidence" :key="field.label">
          <dt>{{ field.label }}</dt>
          <dd>{{ field.value }}</dd>
        </div>
      </dl>
      <p>Unsupported formats and resource limits require review; they do not establish damaged image bytes. A missing or empty source needs independently verified recovery evidence.</p>
    </section>

    <div v-else-if="hasDetails" class="evidence-status">
      <strong>Structured evidence is attached</strong>
      <span>{{ detailFieldCount }} fields are preserved for a type-specific review screen. Raw JSON is hidden from the operator workflow.</span>
    </div>

    <div class="decision-actions">
      <button type="button" class="close-detail-button" :disabled="actioning" @click="$emit('close')">Close review</button>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
  decisionResetToken: { type: Number, default: 0 },
})
defineEmits(['close'])

const item = computed(() => props.context?.item || {})
const hasDetails = computed(() => Object.keys(item.value.details || {}).length > 0)
const detailFieldCount = computed(() => Object.keys(item.value.details || {}).length)
const readableType = computed(() => `${item.value.review_type || item.value.source || 'review'}`.replaceAll('_', ' '))
const inspectionOnly = computed(() => item.value.ui_schema?.inspection_only === true)
const sourceEvidence = computed(() => {
  const evidence = props.context?.file_source_recovery
  if (!inspectionOnly.value || !evidence || item.value.review_type !== 'file_source_recovery') return []
  return [
    ['filename', 'File'], ['asset_uuid', 'Asset reference'], ['classification', 'Finding'],
    ['reason', 'Reason'], ['actual_size', 'Measured bytes'], ['extension', 'Format'],
    ['header_status', 'Header check'], ['decode_status', 'Pixel decode'],
    ['source_sha256', 'Measured SHA-256'], ['expected_sha256', 'Earlier catalog SHA-256'],
    ['observed_at', 'Observed'], ['inspection_method', 'Inspection method'],
    ['source_label', 'Evidence source'], ['receipt_sha256', 'Receipt SHA-256'],
  ].filter(([key]) => ['string', 'number'].includes(typeof evidence[key]))
    .map(([key, label]) => ({ label, value: evidence[key] }))
})

function formatDate(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? `${value}` : date.toLocaleString()
}
</script>

<style scoped>
.generic-review { color: var(--color-text-primary); }
.review-header { display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid var(--color-border); padding-bottom: .8rem; }
.review-header h2 { margin: .15rem 0 0; font-size: 1.05rem; line-height: 1.35; font-weight: 700; overflow-wrap: anywhere; }
.review-kind { color: var(--color-accent); font-size: .68rem; font-weight: 700; text-transform: uppercase; }
.close-button { margin-left: auto; width: 2rem; height: 2rem; border: 1px solid var(--color-border); color: var(--color-text-primary); font-size: 1.25rem; line-height: 1; }
.review-summary { margin: 1rem 0; color: var(--color-text-primary); white-space: pre-wrap; overflow-wrap: anywhere; }
.review-metadata { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .75rem; margin: 1rem 0; }
.review-metadata div { border-left: 2px solid var(--color-border); padding-left: .6rem; min-width: 0; }
dt { color: var(--color-text-secondary); font-size: .65rem; text-transform: uppercase; }
dd { margin: .15rem 0 0; overflow-wrap: anywhere; }
.held-boundary,
.evidence-status { display: grid; gap: .3rem; margin-top: 1rem; padding: .7rem .8rem; font-size: .8rem; line-height: 1.4; }
.held-boundary { border-left: 3px solid var(--ops-danger); background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary)); }
.held-boundary strong { color: var(--ops-danger); text-transform: uppercase; font-size: .68rem; }
.held-boundary.inspection-only { border-left-color: var(--color-accent); background: var(--color-bg-tertiary); }
.held-boundary.inspection-only strong { color: var(--color-accent); }
.evidence-status { border-left: 3px solid var(--color-border); background: var(--color-bg-tertiary); }
.evidence-status strong { color: var(--color-accent); }
.source-evidence { margin-top: 1rem; padding: .8rem; border-left: 3px solid var(--color-accent); background: var(--color-bg-tertiary); }
.source-evidence h3 { margin: 0; color: var(--color-accent); font-size: .85rem; }
.source-evidence p { font-size: .78rem; line-height: 1.45; }
.decision-actions { display: flex; flex-wrap: wrap; gap: .65rem; margin-top: 1rem; }
.decision-actions button { min-height: 2.45rem; padding: .55rem .9rem; font-weight: 700; }
.close-detail-button { border: 1px solid var(--color-border); color: var(--color-text-primary); background: var(--color-bg-tertiary); }
button:disabled { cursor: not-allowed; opacity: .45; }
</style>

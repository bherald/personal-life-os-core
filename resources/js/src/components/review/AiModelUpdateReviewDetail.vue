<template>
  <section class="portfolio-review" aria-labelledby="portfolio-review-title">
    <header class="portfolio-header">
      <div class="min-w-0">
        <div class="portfolio-kind">AI model portfolio</div>
        <h2 id="portfolio-review-title">{{ item.title || 'LLM portfolio review' }}</h2>
        <p>{{ item.summary }}</p>
      </div>
      <button type="button" class="close-button" aria-label="Close portfolio review" @click="$emit('close')">&times;</button>
    </header>

    <div v-if="!portfolio.review_ready" class="blocking-banner" role="alert">
      <strong>Approval blocked</strong>
      <span>{{ blockerText }}</span>
    </div>

    <div class="scope-grid" aria-label="Portfolio approval boundary">
      <section>
        <h3>Approval authorizes</h3>
        <ul>
          <li>Record the operator decision for all actions shown</li>
          <li>Retain current resources and begin bounded candidate validation</li>
          <li>Use exact PLOS evaluation, canary, and rollback gates later</li>
        </ul>
      </section>
      <section class="scope-excluded">
        <h3>Approval does not perform</h3>
        <ul>
          <li>Model installation, promotion, retirement, or route changes</li>
          <li>Privacy widening, media publication, or identity writeback</li>
          <li>Prompt compression or precompaction</li>
        </ul>
      </section>
    </div>

    <div class="portfolio-counts">
      <span v-if="summary.retain_count"><strong>{{ summary.retain_count }}</strong> retain</span>
      <span v-if="summary.candidate_count"><strong>{{ summary.candidate_count }}</strong> candidates</span>
      <span v-if="summary.high_impact_count" class="is-warning"><strong>{{ summary.high_impact_count }}</strong> controlled changes</span>
      <span><strong>{{ summary.action_count || 0 }}</strong> total</span>
    </div>

    <section v-for="group in actionGroups" :key="group.key" class="action-group">
      <header>
        <h3>{{ group.label }}</h3>
        <span>{{ group.actions.length }}</span>
      </header>

      <article v-for="action in group.actions" :key="action.resource_id" class="action-row">
        <div class="action-heading">
          <div class="min-w-0">
            <div class="action-badges">
              <span :class="`priority-${action.priority}`">{{ action.priority }}</span>
              <span>{{ decisionLabel(action.decision) }}</span>
              <span>grade {{ action.evidence_grade }}</span>
            </div>
            <h4>{{ action.resource_id }}</h4>
          </div>
          <span class="fit-badge">{{ action.fit?.overall || 'unknown fit' }}</span>
        </div>

        <p class="action-benefit">{{ action.benefit }}</p>

        <dl class="action-facts">
          <div>
            <dt>Exact revision</dt>
            <dd>{{ action.exact_model_revision }}</dd>
          </div>
          <div>
            <dt>License or terms</dt>
            <dd>{{ action.license }}</dd>
          </div>
          <div>
            <dt>Targets</dt>
            <dd>{{ joinValues(action.target_instance_ids) || 'Not assigned' }}</dd>
          </div>
          <div>
            <dt>Hardware / privacy</dt>
            <dd>{{ action.fit?.hardware || 'unknown' }} / {{ action.fit?.privacy || 'unknown' }}</dd>
          </div>
        </dl>

        <div v-if="action.capability_lanes?.length" class="lane-list" aria-label="Capability lanes">
          <span v-for="lane in action.capability_lanes" :key="lane">{{ readableCode(lane) }}</span>
        </div>

        <div v-if="action.source_urls?.length" class="source-list">
          <a
            v-for="(url, index) in action.source_urls"
            :key="url"
            :href="url"
            target="_blank"
            rel="noopener noreferrer"
          >
            Source {{ index + 1 }}
          </a>
        </div>

        <details v-if="hasActionDetail(action)" class="action-detail">
          <summary>Risks and required validation</summary>
          <div v-if="action.risks?.length">
            <h5>Risks</h5>
            <ul><li v-for="risk in action.risks" :key="risk">{{ risk }}</li></ul>
          </div>
          <div v-if="action.required_validation?.length">
            <h5>Required validation</h5>
            <ol><li v-for="step in action.required_validation" :key="step">{{ step }}</li></ol>
          </div>
          <p v-if="action.fit?.failure_domain_effect"><strong>Failure domain:</strong> {{ action.fit.failure_domain_effect }}</p>
        </details>
      </article>
    </section>

    <div v-if="discardedSourceCount" class="warning-line">
      {{ discardedSourceCount }} malformed source {{ discardedSourceCount === 1 ? 'entry was' : 'entries were' }} discarded before review.
    </div>

    <label class="acknowledgement">
      <input v-model="acknowledged" type="checkbox" :disabled="!portfolio.review_ready" />
      <span>{{ portfolio.confirmation_label }}</span>
    </label>

    <label class="notes-field">
      <span>Operator note</span>
      <textarea v-model="notes" rows="2" maxlength="1000" placeholder="Optional approval note or rejection reason"></textarea>
    </label>

    <div class="decision-actions">
      <button
        type="button"
        class="approve-button"
        :disabled="actioning || !acknowledged || !portfolio.review_ready"
        @click="approve"
      >
        {{ portfolio.approval_label || 'Approve portfolio review' }}
      </button>
      <button type="button" class="reject-button" :disabled="actioning" @click="reject">
        Reject packet
      </button>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
  decisionResetToken: { type: Number, default: 0 },
})
const emit = defineEmits(['approve', 'reject', 'close'])

const acknowledged = ref(false)
const notes = ref('')
const item = computed(() => props.context?.item || {})
const portfolio = computed(() => props.context?.ai_model_update || {})
const summary = computed(() => portfolio.value.summary || {})
const discardedSourceCount = computed(() => Number(portfolio.value.validation_warnings?.discarded_source_url_count || 0))
const blockerText = computed(() => (portfolio.value.blockers || []).map(readableCode).join(', ') || 'Packet validation is incomplete.')
const actionGroups = computed(() => {
  const actions = Array.isArray(portfolio.value.actions) ? portfolio.value.actions : []
  return [
    { key: 'retain', label: 'Keep current resources', actions: actions.filter(action => action.decision === 'retain') },
    { key: 'candidate', label: 'Add evaluation candidates', actions: actions.filter(action => action.decision === 'add_candidate') },
    { key: 'controlled', label: 'Controlled changes', actions: actions.filter(action => !['retain', 'add_candidate'].includes(action.decision)) },
  ].filter(group => group.actions.length > 0)
})

watch(() => props.decisionResetToken, () => {
  acknowledged.value = false
  notes.value = ''
})

function approve() {
  if (!acknowledged.value || !portfolio.value.review_ready) return
  emit('approve', { unifiedId: item.value.unified_id, notes: notes.value.trim() })
}

function reject() {
  emit('reject', { unifiedId: item.value.unified_id, notes: notes.value.trim() })
}

function joinValues(values) {
  return Array.isArray(values) ? values.join(', ') : ''
}

function readableCode(value) {
  return `${value || ''}`.replaceAll('_', ' ')
}

function decisionLabel(value) {
  return readableCode(value)
}

function hasActionDetail(action) {
  return Boolean(action.risks?.length || action.required_validation?.length || action.fit?.failure_domain_effect)
}
</script>

<style scoped>
.portfolio-review { color: var(--color-text-primary); }
.portfolio-header { display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid var(--color-border); padding-bottom: .8rem; }
.portfolio-kind { color: var(--color-accent); font-size: .68rem; font-weight: 800; text-transform: uppercase; }
.portfolio-header h2 { margin: .15rem 0 .35rem; font-size: 1.08rem; line-height: 1.3; overflow-wrap: anywhere; }
.portfolio-header p { margin: 0; color: var(--color-text-primary); font-size: .8rem; white-space: pre-wrap; overflow-wrap: anywhere; }
.close-button { margin-left: auto; width: 2rem; height: 2rem; flex: 0 0 2rem; border: 1px solid var(--color-border); color: var(--color-text-primary); font-size: 1.25rem; line-height: 1; }
.blocking-banner { display: grid; gap: .2rem; margin-top: 1rem; border-left: 3px solid var(--ops-danger); background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary)); padding: .7rem; }
.blocking-banner strong { color: var(--ops-danger); text-transform: uppercase; font-size: .7rem; }
.scope-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1rem 0; border-block: 1px solid var(--color-border); padding: .9rem 0; }
.scope-grid section { min-width: 0; }
.scope-grid h3 { margin: 0 0 .4rem; color: var(--ops-success); font-size: .72rem; text-transform: uppercase; }
.scope-excluded h3 { color: var(--ops-warning); }
.scope-grid ul, .action-detail ul, .action-detail ol { margin: 0; padding-left: 1.15rem; color: var(--color-text-primary); font-size: .74rem; }
.scope-grid li + li, .action-detail li + li { margin-top: .25rem; }
.portfolio-counts { display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: 1rem; }
.portfolio-counts span, .action-badges span, .lane-list span, .fit-badge { border: 1px solid var(--color-border); padding: .2rem .45rem; font-size: .66rem; text-transform: uppercase; }
.portfolio-counts .is-warning { border-color: var(--ops-warning); color: var(--ops-warning); }
.action-group { margin-top: 1.1rem; }
.action-group > header { display: flex; align-items: center; gap: .5rem; border-bottom: 1px solid var(--color-border); padding-bottom: .35rem; }
.action-group > header h3 { margin: 0; color: var(--color-accent); font-size: .78rem; text-transform: uppercase; }
.action-group > header span { color: var(--color-text-secondary); font-size: .7rem; }
.action-row { border-bottom: 1px solid var(--color-border); padding: .9rem 0; }
.action-heading { display: flex; align-items: flex-start; gap: .75rem; }
.action-heading h4 { margin: .35rem 0 0; font-size: .9rem; overflow-wrap: anywhere; }
.action-badges { display: flex; flex-wrap: wrap; gap: .3rem; }
.action-badges .priority-high { border-color: var(--ops-danger); color: var(--ops-danger); }
.action-badges .priority-medium { border-color: var(--ops-warning); color: var(--ops-warning); }
.fit-badge { margin-left: auto; flex: 0 0 auto; color: var(--ops-success); }
.action-benefit { margin: .65rem 0; color: var(--color-text-primary); font-size: .8rem; }
.action-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem 1rem; }
.action-facts div { min-width: 0; }
.action-facts dt { color: var(--color-text-secondary); font-size: .62rem; text-transform: uppercase; }
.action-facts dd { margin: .1rem 0 0; font-size: .72rem; overflow-wrap: anywhere; }
.lane-list, .source-list { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .65rem; }
.lane-list span { color: var(--color-text-primary); }
.source-list a { border-bottom: 1px solid var(--color-border); color: var(--color-accent); font-size: .72rem; }
.action-detail { margin-top: .65rem; color: var(--color-text-primary); font-size: .74rem; }
.action-detail summary { cursor: pointer; color: var(--ops-warning); font-weight: 700; }
.action-detail h5 { margin: .65rem 0 .3rem; color: var(--color-text-primary); font-size: .68rem; text-transform: uppercase; }
.action-detail p { margin: .65rem 0 0; }
.warning-line { margin-top: .8rem; color: var(--ops-warning); font-size: .75rem; }
.acknowledgement { display: flex; align-items: flex-start; gap: .55rem; margin-top: 1rem; border-left: 3px solid var(--ops-success); background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary)); padding: .7rem; font-size: .78rem; }
.acknowledgement input { margin-top: .18rem; accent-color: var(--ops-success); }
.notes-field { display: grid; gap: .35rem; margin-top: 1rem; color: var(--color-text-secondary); font-size: .68rem; text-transform: uppercase; }
.notes-field textarea { width: 100%; resize: vertical; border: 1px solid var(--color-border); background: var(--color-bg-secondary); color: var(--color-text-primary); padding: .55rem; font-size: .8rem; text-transform: none; }
.decision-actions { display: flex; flex-wrap: wrap; gap: .65rem; margin-top: 1rem; }
.decision-actions button { min-height: 2.55rem; padding: .55rem .9rem; font-weight: 800; }
.approve-button { background: var(--ops-success); color: var(--color-text-secondary); }
.reject-button { border: 1px solid var(--ops-danger); color: var(--ops-danger); }
button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 640px) {
  .scope-grid, .action-facts { grid-template-columns: 1fr; }
  .action-heading { flex-direction: column; }
  .fit-badge { margin-left: 0; }
  .decision-actions button { width: 100%; }
}
</style>

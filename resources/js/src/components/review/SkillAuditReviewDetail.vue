<template>
  <section class="skill-audit-review" aria-labelledby="skill-audit-title">
    <header class="audit-header">
      <div>
        <p class="eyebrow">Agent skill audit</p>
        <h2 id="skill-audit-title">{{ item.title || 'Skill audit report' }}</h2>
        <p>{{ item.summary || 'Review the recorded static and SkillSpector findings.' }}</p>
      </div>
      <span class="recommendation" :class="recommendationClass(summary.top_recommendation)">
        {{ readable(summary.top_recommendation || 'unknown') }}
      </span>
    </header>

    <section class="fact-band" aria-label="Audit summary">
      <dl>
        <div>
          <dt>Skills scanned</dt>
          <dd>{{ summary.skills_scanned ?? 0 }}</dd>
        </div>
        <div>
          <dt>Static findings</dt>
          <dd>{{ summary.static_findings ?? 0 }}</dd>
        </div>
        <div>
          <dt>SkillSpector</dt>
          <dd>{{ readable(summary.sidecar_recommendation || summary.sidecar_status || 'not available') }}</dd>
        </div>
        <div>
          <dt>Maximum score</dt>
          <dd>{{ summary.sidecar_max_score ?? 'Not scored' }}</dd>
        </div>
      </dl>
      <p v-if="packet.blocked_if_enforced" class="blocked-line">
        This bundle would be blocked if the advisory sidecar were enforced. Enforcement is currently unchanged.
      </p>
    </section>

    <section class="audit-section" aria-labelledby="advisory-title">
      <div class="section-heading">
        <h3 id="advisory-title">Items needing attention</h3>
        <span>{{ advisories.length }} advisory{{ advisories.length === 1 ? '' : ' items' }}</span>
      </div>
      <p v-if="advisories.length === 0" class="empty-line">No sidecar advisories were attached to this packet.</p>
      <article v-for="(advisory, index) in advisories" :key="`${advisory.skill || 'skill'}-${index}`" class="advisory-row">
        <div class="advisory-heading">
          <strong>{{ advisory.skill || 'Unnamed skill' }}</strong>
          <span :class="recommendationClass(advisory.recommendation)">{{ readable(advisory.recommendation) }}</span>
        </div>
        <div class="advisory-meta">
          <span>Score {{ advisory.score ?? 'not scored' }}</span>
          <span>Severity {{ readable(advisory.severity || 'not reported') }}</span>
          <span>{{ advisory.issues_count ?? advisory.issues?.length ?? 0 }} issue{{ Number(advisory.issues_count ?? advisory.issues?.length ?? 0) === 1 ? '' : 's' }}</span>
        </div>
        <ul v-if="advisory.issues?.length" class="issue-list">
          <li v-for="(issue, issueIndex) in advisory.issues" :key="`${issue.id || 'issue'}-${issueIndex}`">
            <span>{{ issue.id || issue.category || issue.pattern || 'Finding' }}</span>
            <small>{{ issueLocation(issue) }}</small>
          </li>
        </ul>
        <p v-if="advisory.issues_truncated" class="truncated-line">Additional issue metadata is retained in the report artifact.</p>
      </article>
    </section>

    <section class="audit-section artifact-section" aria-labelledby="artifact-title">
      <div class="section-heading">
        <h3 id="artifact-title">Evidence receipt</h3>
        <span>{{ artifact.bytes ? `${artifact.bytes} bytes` : 'metadata only' }}</span>
      </div>
      <dl>
        <div>
          <dt>Report</dt>
          <dd>{{ artifact.relative_path || artifact.path || 'Not recorded' }}</dd>
        </div>
        <div>
          <dt>Report SHA-256</dt>
          <dd class="hash-value">{{ artifact.sha256 || 'Not recorded' }}</dd>
        </div>
        <div>
          <dt>Audited content SHA-256</dt>
          <dd class="hash-value">{{ scope.content_sha256 || 'Not recorded' }}</dd>
        </div>
        <div>
          <dt>Integrity scan</dt>
          <dd>{{ scope.integrity_complete ? 'Complete' : 'Incomplete or not recorded' }}</dd>
        </div>
      </dl>
    </section>

    <section class="decision-section" aria-labelledby="audit-decision-title">
      <div class="section-heading">
        <h3 id="audit-decision-title">Choose report outcome</h3>
        <span>no skill mutation</span>
      </div>
      <div class="effect-grid">
        <div class="approve-effect">
          <strong>Mark report reviewed</strong>
          <span>Records the approval and removes this packet from the pending queue.</span>
          <span>Does not edit SKILL.md, enable or import a skill, install dependencies, or enforce SkillSpector.</span>
        </div>
        <div class="reject-effect">
          <strong>Reject report</strong>
          <span>Dismisses this packet only. No skill, dependency, route, or sidecar setting changes.</span>
        </div>
      </div>

      <label class="acknowledgement">
        <input v-model="acknowledged" type="checkbox" :disabled="actioning" />
        <span>I understand that marking this report reviewed acknowledges the audit only and applies no skill changes.</span>
      </label>

      <label class="note-field">
        <span>Decision note <small>Optional</small></span>
        <textarea v-model="notes" rows="3" maxlength="2000" :disabled="actioning"></textarea>
      </label>

      <div class="decision-actions">
        <button type="button" class="approve-button" :disabled="actioning || !acknowledged" @click="approve">
          Mark report reviewed
        </button>
        <button type="button" class="reject-button" :disabled="actioning" @click="reject">
          Reject report
        </button>
        <button type="button" class="close-button" :disabled="actioning" @click="emit('close')">Close</button>
      </div>
    </section>
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
const details = computed(() => item.value.details || {})
const summary = computed(() => details.value.audit_summary || details.value.review_packet?.summary || {})
const packet = computed(() => details.value.review_packet || {})
const advisories = computed(() => Array.isArray(packet.value.sidecar_advisories) ? packet.value.sidecar_advisories : [])
const artifact = computed(() => details.value.report_artifact || {})
const scope = computed(() => details.value.review_scope || {})

watch(() => props.context?.item?.unified_id, resetDecisionInputs, { immediate: true })
watch(() => props.decisionResetToken, resetDecisionInputs)

function resetDecisionInputs() {
  acknowledged.value = false
  notes.value = ''
}

function readable(value) {
  return `${value || 'unknown'}`.replaceAll('_', ' ').replace(/^./, char => char.toUpperCase())
}

function recommendationClass(value) {
  const normalized = `${value || ''}`.toUpperCase()
  if (normalized === 'DO_NOT_INSTALL') return 'status-blocked'
  if (normalized === 'CAUTION') return 'status-caution'
  if (normalized === 'SAFE') return 'status-safe'
  return 'status-neutral'
}

function issueLocation(issue) {
  const location = [issue.file, issue.start_line ? `line ${issue.start_line}` : null].filter(Boolean).join(', ')
  return [readable(issue.severity || issue.category || 'advisory'), location].filter(Boolean).join(' - ')
}

function approve() {
  if (!acknowledged.value) return
  emit('approve', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    decisionScope: 'acknowledge_skill_audit_report',
  })
}

function reject() {
  emit('reject', { unifiedId: item.value.unified_id, notes: notes.value.trim() })
}
</script>

<style scoped>
.skill-audit-review { color: var(--color-text-primary); min-width: 0; }
.skill-audit-review, .skill-audit-review * { box-sizing: border-box; }
.audit-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding-bottom: .9rem; border-bottom: 1px solid var(--color-border); }
.audit-header > div { min-width: 0; }
.eyebrow { margin: 0 0 .25rem; color: var(--color-accent); font-size: .72rem; font-weight: 700; text-transform: uppercase; }
h2, h3, p { margin-top: 0; }
h2 { margin-bottom: .35rem; font-size: 1.25rem; line-height: 1.25; overflow-wrap: anywhere; }
h3 { margin-bottom: 0; font-size: .95rem; }
.audit-header p:last-child { margin: 0; color: var(--color-text-secondary); line-height: 1.45; }
.recommendation, .advisory-heading span { flex: 0 0 auto; border: 1px solid currentColor; border-radius: .25rem; padding: .2rem .45rem; font-size: .7rem; font-weight: 700; text-transform: uppercase; }
.status-blocked { color: var(--ops-danger); }
.status-caution { color: var(--ops-warning); }
.status-safe { color: var(--ops-success); }
.status-neutral { color: var(--color-text-secondary); }
.fact-band { padding: .9rem 0; border-bottom: 1px solid var(--color-border); }
.fact-band dl { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .8rem; margin: 0; }
dt { color: var(--color-text-secondary); font-size: .68rem; text-transform: uppercase; }
dd { margin: .2rem 0 0; overflow-wrap: anywhere; font-weight: 650; }
.blocked-line { margin: .8rem 0 0; border-left: 3px solid var(--ops-danger); padding-left: .7rem; color: var(--ops-danger); font-size: .84rem; }
.audit-section, .decision-section { padding: 1rem 0; border-bottom: 1px solid var(--color-border); }
.section-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin-bottom: .65rem; }
.section-heading > span { color: var(--color-text-secondary); font-size: .7rem; text-transform: uppercase; }
.empty-line, .truncated-line { margin: 0; color: var(--color-text-secondary); font-size: .82rem; }
.advisory-row { padding: .75rem 0; border-top: 1px solid var(--color-border); }
.advisory-heading { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.advisory-heading strong { overflow-wrap: anywhere; }
.advisory-meta { display: flex; flex-wrap: wrap; gap: .4rem 1rem; margin-top: .4rem; color: var(--color-text-secondary); font-size: .78rem; }
.issue-list { margin: .55rem 0 0; padding-left: 1.1rem; }
.issue-list li { margin-top: .3rem; color: var(--color-text-secondary); font-size: .8rem; }
.issue-list small { display: block; color: var(--color-text-secondary); }
.truncated-line { margin-top: .45rem; }
.artifact-section dl { display: grid; gap: .65rem; margin: 0; }
.artifact-section dl > div { display: grid; grid-template-columns: minmax(8rem, .35fr) minmax(0, 1fr); gap: .75rem; }
.artifact-section dd { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; font-weight: 500; }
.hash-value { word-break: break-all; }
.decision-section { border-bottom: 0; padding-bottom: 0; }
.effect-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--color-border); border-block: 1px solid var(--color-border); }
.effect-grid > div { display: flex; flex-direction: column; gap: .35rem; padding: .8rem; background: var(--color-bg-secondary); font-size: .82rem; line-height: 1.4; }
.approve-effect strong { color: var(--ops-success); }
.reject-effect strong { color: var(--ops-danger); }
.acknowledgement { display: flex; align-items: flex-start; gap: .55rem; margin: .9rem 0; font-size: .84rem; line-height: 1.4; cursor: pointer; }
.acknowledgement input { margin-top: .18rem; accent-color: var(--ops-success); }
.note-field { display: grid; gap: .35rem; color: var(--color-text-secondary); font-size: .8rem; }
.note-field small { color: var(--color-text-secondary); }
textarea { width: 100%; resize: vertical; border: 1px solid var(--color-border); border-radius: .25rem; background: var(--color-bg-secondary); color: var(--color-text-primary); padding: .55rem; }
.decision-actions { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .85rem; }
.decision-actions button { min-height: 2.25rem; border: 1px solid currentColor; border-radius: .25rem; padding: .45rem .75rem; font-weight: 700; cursor: pointer; }
.approve-button { background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary)); color: var(--ops-success); }
.reject-button { background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary)); color: var(--ops-danger); }
.close-button { margin-left: auto; background: transparent; color: var(--color-text-secondary); }
.decision-actions button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 720px) {
  .audit-header { flex-direction: column; }
  .fact-band dl, .effect-grid { grid-template-columns: 1fr 1fr; }
  .artifact-section dl > div { grid-template-columns: 1fr; gap: .15rem; }
}
@media (max-width: 460px) {
  .fact-band dl, .effect-grid { grid-template-columns: 1fr; }
  .decision-actions button { width: 100%; }
  .close-button { margin-left: 0; }
}
</style>

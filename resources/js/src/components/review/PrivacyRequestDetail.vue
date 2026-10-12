<template>
  <section class="privacy-review" aria-labelledby="privacy-review-title">
    <header class="privacy-header">
      <div class="min-w-0">
        <div class="privacy-kind">Operator privacy authorization</div>
        <h2 id="privacy-review-title">Authorize later removal from {{ brokerName }}?</h2>
        <p>This prepares one request for {{ subjectName }}. It does not contact the broker now.</p>
      </div>
      <button type="button" class="close-button" aria-label="Close privacy request review" @click="emit('close')">&times;</button>
    </header>

    <section class="request-facts" aria-label="Privacy request target">
      <div>
        <span class="fact-label">Subject</span>
        <strong>{{ subjectName }}</strong>
      </div>
      <div>
        <span class="fact-label">Broker</span>
        <strong>{{ brokerName }}</strong>
        <a v-if="item.broker_url" :href="item.broker_url" target="_blank" rel="noopener noreferrer">Open broker site</a>
      </div>
      <div>
        <span class="fact-label">Later method</span>
        <strong>{{ methodLabel }}</strong>
      </div>
    </section>

    <div v-if="blockingText" class="blocking-banner" role="alert">
      <strong>Approval needs attention</strong>
      <span>{{ blockingText }}</span>
    </div>
    <div v-if="warningText" class="warning-banner">
      <strong>Later submission may need operator help</strong>
      <span>{{ warningText }}</span>
    </div>

    <section class="field-selection" aria-labelledby="field-selection-title">
      <div class="section-heading">
        <h3 id="field-selection-title">Information authorized</h3>
        <span>{{ selectedFieldSummary }}</span>
      </div>
      <p>Only category names are shown. Personal values stay hidden.</p>
      <div class="selected-fields" aria-label="Selected personal-data categories">
        <span v-for="field in selectedFields" :key="field">{{ fieldLabel(field) }}</span>
      </div>

      <details class="field-options">
        <summary>Adjust information used</summary>
        <div class="field-grid">
          <label v-for="field in review.available_fields || []" :key="field" :class="{ required: isRequired(field) }">
            <input
              v-model="selectedFields"
              type="checkbox"
              :value="field"
              :disabled="actioning || isRequired(field)"
            />
            <span>{{ fieldLabel(field) }}</span>
            <small>{{ isRequired(field) ? 'Required by broker' : 'Optional' }}</small>
          </label>
        </div>
      </details>
    </section>

    <label v-if="review.profile_url_required" class="profile-field">
      <span>Broker profile URL <strong>Required</strong></span>
      <input
        v-model.trim="profileUrl"
        type="url"
        maxlength="500"
        autocomplete="off"
        placeholder="https://broker.example/profile/..."
        :disabled="actioning"
        @input="profileUrlEdited = true"
      />
      <small>This identifies the public listing to remove. Credentials and local-network URLs are rejected.</small>
    </label>

    <section class="decision-dock" aria-label="Privacy request decision">
      <button
        type="button"
        class="approve-button"
        :disabled="actioning || !approvalReady"
        @click="approve"
      >Authorize later removal</button>
      <p>{{ review.approval_non_effect }}</p>

      <details class="secondary-actions">
        <summary>Reject or add a note</summary>
        <div class="decision-inputs">
          <label>
            <span>Decision note <small>Optional</small></span>
            <textarea v-model="notes" rows="2" maxlength="2000" :disabled="actioning"></textarea>
          </label>
          <label>
            <span>Rejection reason <strong>Required to reject</strong></span>
            <select v-model="reasonCode" :disabled="actioning">
              <option value="">Choose reason</option>
              <option value="wrong_target">Wrong broker or listing</option>
              <option value="no_personal_data">No personal data found</option>
              <option value="insufficient_evidence">Insufficient evidence</option>
              <option value="duplicate">Duplicate request</option>
              <option value="do_not_submit">Do not submit</option>
              <option value="other">Other</option>
            </select>
          </label>
        </div>
        <button type="button" class="reject-button" :disabled="actioning || !reasonCode" @click="reject">
          Reject request
        </button>
      </details>
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

const notes = ref('')
const reasonCode = ref('')
const profileUrl = ref('')
const profileUrlEdited = ref(false)
const selectedFields = ref([])
const item = computed(() => props.context?.item || {})
const review = computed(() => props.context?.privacy_review || {})
const subjectName = computed(() => item.value.subject_name || 'this person')
const brokerName = computed(() => item.value.broker_name || 'this broker')
const methodLabel = computed(() => `${item.value.removal_method || 'unknown'}`.replaceAll('_', ' '))
const selectedFieldSummary = computed(() => selectedFields.value.map(fieldLabel).join(', ') || 'None selected')
const unresolvedBlockers = computed(() => (review.value.blockers || []).filter((blocker) => {
  if (blocker === 'profile_url_required' && validPublicUrl.value) return false
  return true
}))
const approvalReady = computed(() => {
  const required = review.value.required_fields || []
  return unresolvedBlockers.value.length === 0
    && selectedFields.value.length > 0
    && required.every(field => selectedFields.value.includes(field))
})
const validPublicUrl = computed(() => {
  if (!review.value.profile_url_required) return true
  try {
    const parsed = new URL(profileUrl.value)
    return ['http:', 'https:'].includes(parsed.protocol)
      && !parsed.username
      && !parsed.password
      && parsed.hostname !== 'localhost'
      && !parsed.hostname.endsWith('.local')
  } catch {
    return false
  }
})
const blockingText = computed(() => {
  const labels = {
    subject_missing: 'The subject record is unavailable.',
    broker_missing: 'The broker record is unavailable.',
    required_personal_data_missing: 'One or more broker-required personal-data categories are unavailable.',
    removal_destination_missing: 'The broker removal destination is missing or unsupported.',
    profile_url_required: 'Enter the public broker profile URL for this listing.',
    approval_route_unavailable: 'The guarded approval route is unavailable.',
  }
  return unresolvedBlockers.value.map(blocker => labels[blocker] || `${blocker}`.replaceAll('_', ' ')).join(' ')
})
const warningText = computed(() => {
  const labels = {
    captcha_or_manual_step_expected: 'A CAPTCHA or manual browser step is expected.',
    broker_authentication_expected: 'Broker authentication may be required.',
    ai_confidence_not_scored: 'This request has no AI confidence score.',
  }
  return (review.value.warnings || []).map(warning => labels[warning] || `${warning}`.replaceAll('_', ' ')).join(' ')
})

watch(() => props.context?.item?.unified_id, resetDecisionInputs, { immediate: true })
watch(() => props.decisionResetToken, resetDecisionInputs)

function resetDecisionInputs() {
  notes.value = ''
  reasonCode.value = ''
  profileUrl.value = item.value.profile_url || ''
  profileUrlEdited.value = false
  selectedFields.value = [...(review.value.selected_fields || [])]
}

function isRequired(field) {
  return (review.value.required_fields || []).includes(field)
}

function fieldLabel(field) {
  const labels = { dob: 'Date of birth', zip: 'ZIP code' }
  return labels[field] || `${field}`.replaceAll('_', ' ').replace(/^./, char => char.toUpperCase())
}

function approve() {
  if (!approvalReady.value) return
  emit('approve', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    fieldsToSubmit: [...selectedFields.value],
    profileUrl: profileUrlEdited.value ? (profileUrl.value.trim() || null) : null,
  })
}

function reject() {
  if (!reasonCode.value) return
  emit('reject', {
    unifiedId: item.value.unified_id,
    notes: notes.value.trim(),
    reasonCode: reasonCode.value,
  })
}
</script>

<style scoped>
.privacy-review { color: var(--color-text-primary); min-width: 0; }
.privacy-review, .privacy-review * { box-sizing: border-box; }
.privacy-header { display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid var(--color-border); padding-bottom: .8rem; }
.privacy-kind { color: var(--color-accent); font-size: .68rem; font-weight: 800; text-transform: uppercase; }
.privacy-header h2 { margin: .15rem 0 .35rem; font-size: 1.08rem; line-height: 1.35; overflow-wrap: anywhere; }
.privacy-header p { margin: 0; color: var(--color-text-primary); font-size: .78rem; line-height: 1.45; }
.close-button { margin-left: auto; width: 2rem; height: 2rem; flex: 0 0 2rem; border: 1px solid var(--color-border); color: var(--color-text-primary); font-size: 1.25rem; line-height: 1; }
.request-facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin: 1rem 0; }
.request-facts > div { display: grid; align-content: start; gap: .2rem; min-width: 0; border-left: 2px solid var(--color-border); padding-left: .65rem; }
.request-facts strong, .request-facts a, .request-facts span { overflow-wrap: anywhere; }
.request-facts strong { font-size: .86rem; }
.request-facts a { color: var(--color-accent); font-size: .72rem; }
.request-facts div > span:last-child { color: var(--color-text-primary); font-size: .7rem; }
.fact-label { color: var(--color-text-secondary) !important; font-size: .62rem !important; font-weight: 700; text-transform: uppercase; }
.field-selection h3 { margin: 0; font-size: .76rem; text-transform: uppercase; }
.field-selection > p { margin: .35rem 0 0; color: var(--color-text-primary); font-size: .75rem; line-height: 1.5; overflow-wrap: anywhere; }
.blocking-banner, .warning-banner { display: grid; gap: .2rem; margin-top: .75rem; border-left: 3px solid var(--ops-danger); background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary)); padding: .65rem; font-size: .74rem; }
.warning-banner { border-color: var(--ops-warning); background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary)); }
.blocking-banner strong { color: var(--ops-danger); }
.warning-banner strong { color: var(--ops-warning); }
.field-selection { margin-top: 1rem; }
.section-heading { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; }
.section-heading > span { color: var(--color-text-primary); font-size: .7rem; overflow-wrap: anywhere; text-align: right; }
.selected-fields { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .6rem; }
.selected-fields span { border: 1px solid var(--ops-success); background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary)); color: var(--ops-success); padding: .25rem .45rem; font-size: .7rem; font-weight: 700; }
.field-options, .secondary-actions { margin-top: .7rem; border-top: 1px solid var(--color-border); padding-top: .55rem; }
.field-options summary, .secondary-actions summary { color: var(--color-accent); cursor: pointer; font-size: .72rem; font-weight: 700; }
.field-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; margin-top: .65rem; }
.field-grid label { display: grid; grid-template-columns: auto 1fr; gap: .12rem .4rem; min-width: 0; border: 1px solid var(--color-border); padding: .5rem; font-size: .75rem; }
.field-grid label.required { border-color: var(--ops-success); }
.field-grid input { grid-row: 1 / 3; align-self: center; accent-color: var(--ops-success); }
.field-grid span { font-weight: 700; overflow-wrap: anywhere; }
.field-grid small { color: var(--color-text-secondary); font-size: .6rem; }
.profile-field, .decision-inputs label { display: grid; gap: .3rem; margin-top: .8rem; color: var(--color-text-primary); font-size: .68rem; }
.profile-field input, .decision-inputs select, .decision-inputs textarea { width: 100%; border: 1px solid var(--color-border); background: var(--color-bg-secondary); color: var(--color-text-primary); padding: .5rem; font-size: .78rem; }
.profile-field small { color: var(--color-text-secondary); line-height: 1.4; }
.decision-dock { position: sticky; bottom: -.05rem; z-index: 3; margin-top: 1rem; border: 1px solid var(--ops-success); background: var(--color-bg-secondary); padding: .85rem; box-shadow: 0 -8px 18px rgba(0, 0, 0, .42); }
.decision-dock > p { margin: .45rem 0 0; color: var(--color-text-primary); font-size: .7rem; line-height: 1.4; }
.decision-inputs { display: grid; grid-template-columns: 1.4fr 1fr; gap: .65rem; }
.decision-inputs textarea { resize: vertical; }
.approve-button { width: 100%; min-height: 2.6rem; background: var(--ops-success); color: var(--color-text-secondary); padding: .55rem .8rem; font-size: .78rem; font-weight: 800; }
.reject-button { min-height: 2.3rem; margin-top: .65rem; border: 1px solid var(--ops-danger); color: var(--ops-danger); padding: .45rem .7rem; font-size: .72rem; font-weight: 800; }
button:disabled { cursor: not-allowed; opacity: .45; }
@media (max-width: 640px) {
  .request-facts, .field-grid, .decision-inputs { grid-template-columns: 1fr; }
  .decision-dock { position: static; }
  .reject-button { width: 100%; }
}
</style>

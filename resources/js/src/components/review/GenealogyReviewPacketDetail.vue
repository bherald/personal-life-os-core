<template>
  <div class="packet-detail">
    <div class="packet-header">
      <div class="packet-heading-main">
        <h3 class="packet-title">{{ item.title || packetTitle || 'Genealogy review packet' }}</h3>
        <div v-if="item.summary" class="packet-summary">{{ item.summary }}</div>
      </div>
      <div class="packet-meta">
        <span class="meta-pill meta-type">packet</span>
        <span class="meta-pill" :class="statusClass">{{ packetStatusLabel }}</span>
        <span v-if="confidencePercent !== null" class="meta-pill" :class="confidenceClass">
          {{ confidencePercent }}%
        </span>
      </div>
    </div>

    <section class="packet-section review-focus-section">
      <div class="section-heading">
        <span>Review focus</span>
        <span class="section-status preview">one packet</span>
      </div>
      <div class="focus-grid">
        <div v-for="row in reviewFocusRows" :key="row.key" class="focus-tile" :class="row.className">
          <span class="focus-label">{{ row.label }}</span>
          <span class="focus-value" :title="row.title || row.value">{{ row.value }}</span>
        </div>
      </div>
      <div v-if="reviewFocusClaim" class="focus-line">
        <span class="focus-label">Claim</span>
        <span class="focus-line-value">{{ reviewFocusClaim }}</span>
      </div>
      <div v-if="reviewFocusSource" class="focus-line">
        <span class="focus-label">Source</span>
        <span class="focus-line-value mono" :title="reviewFocusSource">{{ reviewFocusSource }}</span>
      </div>
      <div class="focus-boundary" :class="{ danger: reviewFocusCanonicalMutation === true }">
        <span class="focus-label">Canonical mutation</span>
        <span>{{ canonicalMutationLabel }}</span>
      </div>
    </section>

    <section class="packet-section status-section">
      <div class="section-heading">
        <span>Packet status</span>
        <span class="section-status" :class="statusClass">{{ packetStatusLabel }}</span>
      </div>
      <div v-if="latestDecision" class="latest-decision">
        <div class="kv-grid compact">
          <div class="kv-row">
            <span class="kv-key">latest action</span>
            <span class="kv-value">{{ latestDecision.action || 'event' }}</span>
          </div>
          <div class="kv-row">
            <span class="kv-key">reason</span>
            <span class="kv-value">{{ latestDecisionReason || '-' }}</span>
          </div>
          <div v-if="latestDecision.actor" class="kv-row">
            <span class="kv-key">actor</span>
            <span class="kv-value">{{ latestDecision.actor }}</span>
          </div>
          <div v-if="latestDecision.created_at" class="kv-row">
            <span class="kv-key">time</span>
            <span class="kv-value">{{ formatDate(latestDecision.created_at) }}</span>
          </div>
        </div>
      </div>
      <div v-else class="empty-line">No decision has been recorded.</div>
    </section>

    <div v-if="hasPersonSnapshot || fieldDiffs.length" class="packet-context-grid">
      <section v-if="hasPersonSnapshot" class="packet-section">
        <div class="section-heading">
          <span>On-file person</span>
        </div>
        <PersonSnapshotCard :person="personSnapshot" />
      </section>

      <section v-if="fieldDiffs.length" class="packet-section">
        <div class="section-heading">
          <span>Claim diffs</span>
          <span class="section-count">{{ fieldDiffs.length }}</span>
        </div>
        <div class="packet-diff-heading">
          <div class="col-label">Field</div>
          <div class="col-label">On file</div>
          <div class="col-label">Proposed</div>
          <div class="col-label col-status">Status</div>
        </div>
        <div class="packet-diff-list">
          <FieldCompareRow
            v-for="(diff, idx) in fieldDiffs"
            :key="fieldDiffKey(diff, idx)"
            :diff="diff"
            :classification="classificationForIndex(idx)"
            :interactive="false"
          />
        </div>
      </section>
    </div>

    <section class="packet-section">
      <div class="section-heading">
        <span>Source locators</span>
        <span v-if="sourceLocators.length" class="section-count">{{ sourceLocators.length }}</span>
      </div>
      <div v-if="sourceLocators.length" class="locator-list">
        <template v-for="(locator, idx) in sourceLocators" :key="`locator-${idx}-${locator}`">
          <a
            v-if="locatorHref(locator)"
            :href="locatorHref(locator)"
            target="_blank"
            rel="noopener noreferrer"
            class="locator-row"
          >
            {{ locator }}
          </a>
          <div v-else class="locator-row">
            {{ locator }}
          </div>
        </template>
      </div>
      <div v-else class="empty-line">No source locator supplied.</div>

      <div v-if="sources.length" class="source-payloads">
        <div class="subheading">Source payloads</div>
        <div v-for="(source, idx) in sources" :key="sourceKey(source, idx)" class="source-row">
          <div class="source-row-title">{{ source.locator || source.source_locator || source.url || source.path || `Source ${idx + 1}` }}</div>
          <div class="kv-grid compact">
            <div v-for="row in kvRows(source)" :key="row.key" class="kv-row">
              <span class="kv-key">{{ row.label }}</span>
              <span class="kv-value">{{ row.value }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="mediaRefs.length" class="media-refs">
        <div class="subheading">Resolved source media</div>
        <div class="media-ref-list">
          <template v-for="media in mediaRefs" :key="media.id">
            <a
              v-if="mediaRefHref(media)"
              :href="mediaRefHref(media)"
              target="_blank"
              rel="noopener noreferrer"
              class="media-ref-card"
              :title="media.nextcloud_path || ''"
            >
              <span class="media-ref-id">#{{ media.id }}</span>
              <span class="media-ref-main">
                <span class="media-ref-title">{{ media.title || `Media #${media.id}` }}</span>
                <span class="media-ref-sub">
                  {{ media.file_format || media.mime_type || 'unknown format' }}
                  <span v-if="media.media_type"> · {{ media.media_type }}</span>
                </span>
              </span>
              <span class="media-ref-open">Open</span>
            </a>
            <div v-else class="media-ref-card media-ref-disabled" :title="media.nextcloud_path || ''">
              <span class="media-ref-id">#{{ media.id }}</span>
              <span class="media-ref-main">
                <span class="media-ref-title">{{ media.title || `Media #${media.id}` }}</span>
                <span class="media-ref-sub">
                  {{ media.file_format || media.mime_type || 'unknown format' }}
                  <span v-if="media.media_type"> · {{ media.media_type }}</span>
                  <span v-if="media.file_exists === false"> · file missing</span>
                </span>
              </span>
            </div>
          </template>
        </div>
      </div>
    </section>

    <section class="packet-section">
      <div class="section-heading">
        <span>Claims</span>
        <span v-if="claims.length" class="section-count">{{ claims.length }}</span>
      </div>
      <div v-if="claims.length" class="claim-list">
        <div v-for="(claim, idx) in claims" :key="claimKey(claim, idx)" class="claim-row">
          <div class="claim-topline">
            <span class="claim-index">#{{ claim.index ?? idx }}</span>
            <span v-if="claim.change_type" class="claim-pill">{{ claim.change_type }}</span>
            <span v-if="claim.field_name" class="claim-pill muted">{{ claim.field_name }}</span>
            <span v-if="claim.relationship_type" class="claim-pill muted">{{ claim.relationship_type }}</span>
          </div>
          <div class="claim-text">{{ claimText(claim) || 'No claim text supplied.' }}</div>
          <div class="claim-meta">
            <span v-if="claim.person_id">person #{{ claim.person_id }}</span>
            <span v-if="claim.source_ref">source {{ claim.source_ref }}</span>
            <span v-if="claimConfidence(claim) !== null">{{ claimConfidence(claim) }}% confidence</span>
          </div>
        </div>
      </div>
      <div v-else class="empty-line">No packet claims supplied.</div>
    </section>

    <div class="packet-two-col">
      <section class="packet-section">
        <div class="section-heading">
          <span>Identity</span>
        </div>
        <div v-if="identityRows.length" class="kv-grid">
          <div v-for="row in identityRows" :key="row.key" class="kv-row">
            <span class="kv-key">{{ row.label }}</span>
            <span class="kv-value">{{ row.value }}</span>
          </div>
        </div>
        <div v-else class="empty-line">No identity payload supplied.</div>
      </section>

      <section class="packet-section">
        <div class="section-heading">
          <span>Privacy</span>
        </div>
        <div v-if="privacyRows.length" class="kv-grid">
          <div v-for="row in privacyRows" :key="row.key" class="kv-row">
            <span class="kv-key">{{ row.label }}</span>
            <span class="kv-value" :class="booleanValueClass(row.raw)">{{ row.value }}</span>
          </div>
        </div>
        <div v-else class="empty-line">No privacy payload supplied.</div>
      </section>
    </div>

    <section class="packet-section">
      <div class="section-heading">
        <span>Validation</span>
        <span class="section-status" :class="validationStatusClass">{{ validationStatus }}</span>
      </div>
      <div class="validation-grid">
        <div class="validation-col">
          <div class="subheading danger">Errors</div>
          <div v-if="validationErrors.length" class="issue-list">
            <div v-for="(issue, idx) in validationErrors" :key="issueKey(issue, idx)" class="issue-row danger">
              <span v-if="issueGate(issue)" class="issue-gate">{{ issueGate(issue) }}</span>
              <span v-if="issueCode(issue)" class="issue-code">{{ issueCode(issue) }}</span>
              <span class="issue-message">{{ issueMessage(issue) }}</span>
            </div>
          </div>
          <div v-else class="empty-line">No validation errors.</div>
        </div>
        <div class="validation-col">
          <div class="subheading warning">Warnings</div>
          <div v-if="validationWarnings.length" class="issue-list">
            <div v-for="(issue, idx) in validationWarnings" :key="issueKey(issue, idx)" class="issue-row warning">
              <span v-if="issueGate(issue)" class="issue-gate">{{ issueGate(issue) }}</span>
              <span v-if="issueCode(issue)" class="issue-code">{{ issueCode(issue) }}</span>
              <span class="issue-message">{{ issueMessage(issue) }}</span>
            </div>
          </div>
          <div v-else class="empty-line">No validation warnings.</div>
        </div>
      </div>
    </section>

    <section class="packet-section">
      <div class="section-heading">
        <span>Apply preview</span>
        <span class="section-status preview">preview only</span>
      </div>
      <div class="preview-note">
        mutates_accepted_facts:
        <strong :class="applyPreviewMutates ? 'text-danger' : 'text-ok'">{{ String(applyPreviewMutates) }}</strong>
      </div>
      <div class="preview-note muted">
        Accepted facts are not changed by this packet preview.
      </div>

      <div v-if="applyPreviewSummaryRows.length" class="kv-grid compact preview-summary">
        <div v-for="row in applyPreviewSummaryRows" :key="row.key" class="kv-row">
          <span class="kv-key">{{ row.label }}</span>
          <span class="kv-value">{{ row.value }}</span>
        </div>
      </div>

      <div v-if="previewOperations.length" class="operation-list">
        <div v-for="(operation, idx) in previewOperations" :key="operationKey(operation, idx)" class="operation-row">
          <div class="operation-head">
            <span class="operation-name">{{ operation.operation || `operation ${idx + 1}` }}</span>
            <span v-if="operation.target_table" class="operation-target">{{ operation.target_table }}</span>
          </div>
          <div class="kv-grid compact">
            <div v-for="row in operationRows(operation)" :key="row.key" class="kv-row">
              <span class="kv-key">{{ row.label }}</span>
              <span class="kv-value">{{ row.value }}</span>
            </div>
          </div>

          <div v-if="isStructuredRemediationOperation(operation)" class="remediation-preview">
            <div v-if="arrayValue(operation.guards).length" class="remediation-block">
              <div class="subheading">Guard results</div>
              <div class="guard-list">
                <div
                  v-for="(guard, guardIdx) in arrayValue(operation.guards)"
                  :key="guardKey(guard, guardIdx)"
                  class="guard-row"
                  :class="guardStatusClass(guard)"
                >
                  <span class="guard-name">{{ guard.name || `guard ${guardIdx + 1}` }}</span>
                  <span class="guard-status">{{ guard.status || 'unknown' }}</span>
                  <span class="guard-message">{{ guard.message || '-' }}</span>
                </div>
              </div>
            </div>

            <div v-if="isFamilyRemediationOperation(operation)" class="remediation-grid">
              <div v-if="objectKeys(operation.current_state?.suspect_family).length" class="remediation-block">
                <div class="subheading">Suspect family</div>
                <div class="kv-grid compact">
                  <div v-for="row in familyRows(operation.current_state.suspect_family)" :key="`suspect-${row.key}`" class="kv-row">
                    <span class="kv-key">{{ row.label }}</span>
                    <span class="kv-value">{{ row.value }}</span>
                  </div>
                </div>
                <div v-if="familyChildren(operation.current_state.suspect_family).length" class="child-list">
                  <div v-for="child in familyChildren(operation.current_state.suspect_family)" :key="`suspect-child-${child.id || child.person_id}`" class="child-row">
                    {{ childLabel(child) }}
                  </div>
                </div>
              </div>

              <div v-if="objectKeys(operation.current_state?.retained_family).length" class="remediation-block">
                <div class="subheading">Retained family</div>
                <div class="kv-grid compact">
                  <div v-for="row in familyRows(operation.current_state.retained_family)" :key="`retained-${row.key}`" class="kv-row">
                    <span class="kv-key">{{ row.label }}</span>
                    <span class="kv-value">{{ row.value }}</span>
                  </div>
                </div>
                <div v-if="familyChildren(operation.current_state.retained_family).length" class="child-list">
                  <div v-for="child in familyChildren(operation.current_state.retained_family)" :key="`retained-child-${child.id || child.person_id}`" class="child-row">
                    {{ childLabel(child) }}
                  </div>
                </div>
              </div>
            </div>

            <div v-if="isSourceRemediationOperation(operation)" class="remediation-grid">
              <div v-if="objectKeys(operation.current_state?.suspect_source).length" class="remediation-block">
                <div class="subheading">Suspect source</div>
                <div class="kv-grid compact">
                  <div v-for="row in sourceRows(operation.current_state.suspect_source)" :key="`suspect-source-${row.key}`" class="kv-row">
                    <span class="kv-key">{{ row.label }}</span>
                    <span class="kv-value">{{ row.value }}</span>
                  </div>
                </div>
              </div>

              <div v-if="objectKeys(operation.current_state?.retained_source).length" class="remediation-block">
                <div class="subheading">Retained source</div>
                <div class="kv-grid compact">
                  <div v-for="row in sourceRows(operation.current_state.retained_source)" :key="`retained-source-${row.key}`" class="kv-row">
                    <span class="kv-key">{{ row.label }}</span>
                    <span class="kv-value">{{ row.value }}</span>
                  </div>
                </div>
              </div>

              <div v-if="sourceLocatorGroups(operation).length" class="remediation-block wide">
                <div class="subheading">Duplicate locator groups</div>
                <div class="locator-group-list">
                  <div v-for="(group, groupIdx) in sourceLocatorGroups(operation)" :key="locatorGroupKey(group, groupIdx)" class="locator-group-row">
                    <div class="locator-group-title">{{ group.locator || group.locator_key || `group ${groupIdx + 1}` }}</div>
                    <div class="locator-group-meta">{{ locatorGroupMeta(group) }}</div>
                  </div>
                </div>
              </div>

              <div v-if="sourceResolutionRows(operation).length" class="remediation-block wide">
                <div class="subheading">Proposed source changes</div>
                <div class="source-resolution-list">
                  <div v-for="(row, rowIdx) in sourceResolutionRows(operation)" :key="sourceResolutionKey(row, rowIdx)" class="source-resolution-row">
                    {{ sourceResolutionLabel(row) }}
                  </div>
                </div>
              </div>
            </div>

            <div class="remediation-block">
              <div class="subheading">Proposed effect</div>
              <div class="kv-grid compact">
                <div v-for="row in proposedEffectRows(operation.proposed_effect)" :key="`effect-${row.key}`" class="kv-row">
                  <span class="kv-key">{{ row.label }}</span>
                  <span class="kv-value">{{ row.value }}</span>
                </div>
                <div v-if="isFamilyRemediationOperation(operation)" class="kv-row">
                  <span class="kv-key">shared child ids</span>
                  <span class="kv-value">{{ sharedChildText(operation) }}</span>
                </div>
                <div v-if="isSourceRemediationOperation(operation)" class="kv-row">
                  <span class="kv-key">matching fields</span>
                  <span class="kv-value">{{ matchingFieldText(operation) }}</span>
                </div>
                <div class="kv-row">
                  <span class="kv-key">stale hash</span>
                  <span class="kv-value mono">{{ staleHashShort(operation.stale_hash) }}</span>
                </div>
              </div>
              <div v-if="touchedRows(operation.proposed_effect).length" class="touch-list">
                <div v-for="(row, touchIdx) in touchedRows(operation.proposed_effect)" :key="`touch-${touchIdx}`" class="touch-row">
                  {{ row.table || 'row' }} #{{ row.id || '-' }} · {{ row.action || 'inspect' }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="empty-line">No preview operations.</div>
    </section>

    <section class="packet-section">
      <div class="section-heading">
        <span>Decision log</span>
        <span v-if="decisionLog.length" class="section-count">{{ decisionLog.length }}</span>
      </div>
      <div v-if="decisionLog.length" class="decision-list">
        <div v-for="(entry, idx) in decisionLog" :key="decisionKey(entry, idx)" class="decision-row">
          <div class="decision-head">
            <span class="decision-action">{{ entry.action || 'event' }}</span>
            <span v-if="entry.actor" class="decision-actor">{{ entry.actor }}</span>
            <span v-if="entry.created_at" class="decision-time">{{ formatDate(entry.created_at) }}</span>
          </div>
          <div v-if="entry.note || entry.notes" class="decision-note">{{ entry.note || entry.notes }}</div>
          <pre v-if="entry.meta && objectKeys(entry.meta).length" class="inline-json">{{ formatJson(entry.meta) }}</pre>
        </div>
      </div>
      <div v-else class="empty-line">No decision log entries yet.</div>
    </section>

    <details class="raw-details">
      <summary>Raw details JSON</summary>
      <pre>{{ formatJson(details) }}</pre>
    </details>

    <details class="raw-details">
      <summary>Packet JSON</summary>
      <pre>{{ formatJson(packet) }}</pre>
    </details>

    <section class="packet-section packet-actions">
      <div class="section-heading">
        <span>Packet action</span>
        <span class="section-status preview">preview only</span>
      </div>

      <div class="action-fields">
        <label class="action-field">
          <span class="action-label">Notes</span>
          <textarea
            v-model="decisionNotes"
            class="action-notes"
            :disabled="actioning || !canAct"
            rows="3"
            maxlength="1200"
          ></textarea>
        </label>
        <label class="action-field action-reason">
          <span class="action-label">Reason</span>
          <select v-model="decisionReasonCode" class="action-select" :disabled="actioning || !canAct">
            <option value="">None</option>
            <option v-for="option in reasonCodeOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
      </div>

      <div class="action-buttons">
        <button
          type="button"
          class="packet-action primary"
          :disabled="actioning || !canMarkReviewed"
          :title="markReviewedDisabledTitle"
          @click="submitDecision('approve')"
        >
          Mark reviewed
        </button>
        <button
          type="button"
          class="packet-action danger"
          :disabled="actioning || !canAct"
          @click="submitDecision('reject')"
        >
          Reject
        </button>
        <button
          type="button"
          class="packet-action"
          :disabled="actioning || !canAct"
          @click="submitDecision('clarify')"
        >
          Clarify
        </button>
        <button
          type="button"
          class="packet-action"
          :disabled="actioning || !canAct"
          @click="submitDecision('defer')"
        >
          Defer
        </button>
        <button type="button" class="packet-action ghost" :disabled="actioning" @click="emit('close')">
          Close
        </button>
      </div>

      <div v-if="approvalBlockers.length" class="approval-blockers">
        <span class="approval-blockers-label">Approval blockers</span>
        <span v-for="blocker in approvalBlockers" :key="blocker.code || blocker.label" class="approval-blocker">
          {{ blocker.label || labelize(blocker.code || blocker) }}
        </span>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import PersonSnapshotCard from './PersonSnapshotCard.vue'
import FieldCompareRow from './FieldCompareRow.vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
  decisionResetToken: { type: Number, default: 0 },
})

const emit = defineEmits(['approve', 'reject', 'clarify', 'defer', 'close'])

const decisionNotes = ref('')
const decisionReasonCode = ref('')

const STATUS_CLASS_BY_STATUS = {
  accepted: 'status-ok',
  approved: 'status-ok',
  reviewed: 'status-ok',
  reviewed_preview_only: 'status-ok',
  rejected: 'status-danger',
  failed: 'status-danger',
  error: 'status-danger',
  clarification_requested: 'status-warning',
  deferred: 'status-warning',
  pending: 'status-pending',
}

const reasonCodeOptions = [
  { value: 'missing_source_locator', label: 'Missing source locator' },
  { value: 'source_needs_review', label: 'Source needs review' },
  { value: 'identity_unclear', label: 'Identity unclear' },
  { value: 'weak_evidence', label: 'Weak evidence' },
  { value: 'privacy_review_needed', label: 'Privacy review needed' },
  { value: 'duplicate_packet', label: 'Duplicate packet' },
  { value: 'other', label: 'Other' },
]

const item = computed(() => objectValue(props.context?.item))
const details = computed(() => objectValue(props.context?.item?.details))
const packet = computed(() => objectValue(props.context?.packet, details.value.packet))
const claims = computed(() => arrayValue(props.context?.claims, details.value.claims))
const sources = computed(() => arrayValue(props.context?.sources, details.value.sources).filter(isPlainObject))
const identity = computed(() => objectValue(props.context?.identity, details.value.identity))
const privacy = computed(() => objectValue(props.context?.privacy, details.value.privacy))
const validation = computed(() => objectValue(props.context?.validation, details.value.validation))
const applyPreview = computed(() => objectValue(props.context?.apply_preview, details.value.apply_preview))
const decisionLog = computed(() => arrayValue(props.context?.decision_log, details.value.decision_log).filter(isPlainObject))
const mediaRefs = computed(() => arrayValue(props.context?.media_refs).filter(isPlainObject))
const reviewFocus = computed(() => objectValue(props.context?.review_focus))
const personSnapshot = computed(() => {
  const person = props.context?.person
  return isPlainObject(person) && Object.keys(person).length ? person : null
})
const hasPersonSnapshot = computed(() => personSnapshot.value !== null)
const fieldDiffs = computed(() => arrayValue(props.context?.comparison?.field_diffs).filter(isPlainObject))
const classifications = computed(() => arrayValue(props.context?.source_classifications).filter(isPlainObject))

const packetStatus = computed(() => {
  const value = props.context?.packet_status ?? details.value.packet_status
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null
})

const packetStatusLabel = computed(() => packetStatus.value || item.value.status || 'pending')

const canAct = computed(() => String(item.value.status || '').toLowerCase() === 'pending')
const canMarkReviewed = computed(() => canAct.value && reviewFocusApprovalReady.value)

const markReviewedDisabledTitle = computed(() => {
  if (!canAct.value) return 'Packet is not pending.'
  if (validationMissing.value) return 'Review packet validation is missing.'
  if (validationErrors.value.length) return 'Resolve validation errors before marking reviewed.'
  if (reviewFocusApprovalReady.value === false) return 'Preview metadata is not approval-ready.'
  return ''
})

const reviewFocusApprovalReady = computed(() => {
  if (typeof reviewFocus.value.approval_ready === 'boolean') {
    return reviewFocus.value.approval_ready
  }

  return validation.value.valid === true && validationErrors.value.length === 0 && applyPreviewIsPreviewOnly.value
})

const approvalBlockers = computed(() => {
  const blockers = arrayValue(reviewFocus.value.approval_blockers)
    .map((blocker) => {
      if (typeof blocker === 'string') {
        return { code: blocker, label: labelize(blocker) }
      }
      if (!isPlainObject(blocker)) return null
      return {
        code: stringOrNull(blocker.code) || stringOrNull(blocker.label) || compactJson(blocker),
        label: stringOrNull(blocker.label) || labelize(blocker.code || 'approval blocker'),
      }
    })
    .filter(Boolean)

  if (blockers.length) return blockers
  if (reviewFocusApprovalReady.value) return []
  if (validationMissing.value) return [{ code: 'validation_missing', label: 'Validation missing' }]
  if (validationErrors.value.length) return [{ code: 'validation_errors', label: 'Validation errors present' }]
  if (!applyPreviewIsPreviewOnly.value) return [{ code: 'preview_not_preview_only', label: 'Preview is not preview-only' }]
  return [{ code: 'approval_ready_unknown', label: 'Approval readiness unknown' }]
})

const reviewFocusRows = computed(() => [
  focusRow('person', 'Person', reviewFocusPersonLabel.value),
  focusRow('status', 'Packet status', packetStatusLabel.value),
  focusRow('boundary', 'Boundary', reviewFocusBoundaryLabel.value, null, reviewFocusBoundaryLabel.value),
  focusRow('sources', 'Sources', formatCount(reviewFocus.value.source_count ?? sourceLocators.value.length)),
  focusRow('claims', 'Claims', formatCount(reviewFocus.value.claim_count ?? claims.value.length)),
  focusRow('preview', 'Preview', previewStatusLabel.value, previewStatusClass.value),
].filter(Boolean))

const reviewFocusPersonLabel = computed(() => {
  const label = stringOrNull(reviewFocus.value.person_label)
  if (label) return label

  if (personSnapshot.value) {
    const name = [personSnapshot.value.given_name, personSnapshot.value.surname].filter(Boolean).join(' ').trim()
    if (name) return `${name} (#${personSnapshot.value.id})`
  }

  const personId = reviewFocus.value.person_id ?? identity.value.person_id ?? claims.value.find((claim) => claim?.person_id)?.person_id
  return formatPersonId(personId)
})

const reviewFocusClaim = computed(() => {
  const summary = stringOrNull(reviewFocus.value.claim_summary)
  if (summary) return summary
  const first = claims.value.find(isPlainObject)
  return first ? claimText(first) : null
})

const reviewFocusBoundaryLabel = computed(() => {
  return stringOrNull(reviewFocus.value.boundary_label)
    || stringOrNull(details.value?.sprint?.boundary_label)
    || stringOrNull(packet.value?.sprint_boundary)
    || stringOrNull(packet.value?.operator_boundary)
    || stringOrNull(packet.value?.boundary_label)
    || stringOrNull(packet.value?.boundary)
    || null
})

const reviewFocusSource = computed(() => stringOrNull(reviewFocus.value.source_locator) || sourceLocators.value[0] || null)

const reviewFocusCanonicalMutation = computed(() => {
  if (typeof reviewFocus.value.canonical_mutation === 'boolean') {
    return reviewFocus.value.canonical_mutation
  }
  if (applyPreview.value.mutates_accepted_facts === true) {
    return true
  }
  if (applyPreview.value.mutates_accepted_facts === false) {
    return false
  }
  return null
})

const canonicalMutationLabel = computed(() => {
  if (reviewFocusCanonicalMutation.value === true) return 'possible'
  if (reviewFocusCanonicalMutation.value === false) return 'none'
  return 'unknown'
})

const previewStatusLabel = computed(() => formatPacketStatus(reviewFocus.value.preview_status) || (applyPreviewIsPreviewOnly.value ? 'preview only' : 'unknown'))

const previewStatusClass = computed(() => {
  if (reviewFocusCanonicalMutation.value === true || !applyPreviewIsPreviewOnly.value) return 'danger'
  return 'ok'
})

const latestDecision = computed(() => {
  for (let idx = decisionLog.value.length - 1; idx >= 0; idx--) {
    const entry = decisionLog.value[idx]
    if (entry && Object.keys(entry).length) return entry
  }
  return null
})

const latestDecisionReason = computed(() => decisionReason(latestDecision.value))

const packetTitle = computed(() => {
  return packet.value.packet_label
    || packet.value.title
    || details.value.packet_label
    || details.value.packet_key
    || packet.value.packet_key
    || null
})

const sourceLocators = computed(() => {
  const values = []
  addLocator(values, props.context?.source_locator)
  addLocator(values, details.value.source_locator)
  for (const locator of arrayValue(props.context?.source_locators, details.value.source_locators)) {
    addLocator(values, locator)
  }
  for (const source of sources.value) {
    addLocator(values, source.locator || source.source_locator || source.url || source.uri || source.path || source.citation)
  }
  return Array.from(new Set(values))
})

const confidencePercent = computed(() => {
  const confidence = item.value.confidence
  if (confidence === null || confidence === undefined || confidence === '') return null
  const numeric = Number(confidence)
  return Number.isFinite(numeric) ? Math.round(numeric * 100) : null
})

const confidenceClass = computed(() => {
  const pct = confidencePercent.value
  if (pct === null) return ''
  if (pct >= 80) return 'conf-high'
  if (pct >= 50) return 'conf-med'
  return 'conf-low'
})

const statusClass = computed(() => {
  const status = String(packetStatusLabel.value || '').toLowerCase()
  return STATUS_CLASS_BY_STATUS[status] || 'status-pending'
})

const identityRows = computed(() => kvRows(identity.value))
const privacyRows = computed(() => kvRows(privacy.value))
const validationErrors = computed(() => arrayValue(validation.value.errors))
const validationWarnings = computed(() => arrayValue(validation.value.warnings))
const validationMissing = computed(() => !validation.value || Object.keys(validation.value).length === 0)

const validationStatus = computed(() => {
  if (validation.value.valid === true) return 'valid'
  if (validationErrors.value.length) return 'errors'
  if (validationWarnings.value.length) return 'warnings'
  if (validationMissing.value) return 'missing'
  return 'unknown'
})

const validationStatusClass = computed(() => {
  if (validation.value.valid === true) return 'status-ok'
  if (validationErrors.value.length) return 'status-danger'
  if (validationWarnings.value.length) return 'status-warning'
  return 'status-pending'
})

const applyPreviewMutates = computed(() => applyPreview.value.mutates_accepted_facts === true)
const previewOperations = computed(() => arrayValue(applyPreview.value.operations).filter(isPlainObject))
const applyPreviewIsPreviewOnly = computed(() => {
  if (applyPreview.value.mutates_accepted_facts !== false) {
    return false
  }

  if (hasAcceptedFactMutations(applyPreview.value.accepted_fact_mutations)) {
    return false
  }

  return previewOperations.value.every((operation) => {
    return !previewFlagEnabled(operation.mutates_accepted_facts)
      && !previewFlagEnabled(operation.apply_enabled)
  })
})

watch(() => props.context?.item?.unified_id, () => resetDecisionInputs())
watch(() => props.decisionResetToken, () => resetDecisionInputs())

const applyPreviewSummaryRows = computed(() => {
  const rows = []
  for (const key of ['status', 'operation_count']) {
    if (applyPreview.value[key] !== undefined) {
      rows.push(toKvRow(key, applyPreview.value[key]))
    }
  }
  const summary = objectValue(applyPreview.value.summary)
  for (const row of kvRows(summary)) {
    rows.push({
      ...row,
      key: `summary.${row.key}`,
      label: `summary ${row.label}`,
    })
  }
  return rows
})

function addLocator(values, value) {
  if (typeof value !== 'string') return
  const trimmed = value.trim()
  if (trimmed !== '') values.push(trimmed)
}

function focusRow(key, label, value, className = null, title = null) {
  if (value === null || value === undefined || value === '') return null
  return { key, label, value, className, title }
}

function objectValue(...candidates) {
  for (const value of candidates) {
    if (isPlainObject(value)) return value
  }
  return {}
}

function arrayValue(...candidates) {
  for (const value of candidates) {
    if (Array.isArray(value)) return value
  }
  return []
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function kvRows(value) {
  if (!isPlainObject(value)) return []
  return Object.entries(value)
    .filter(([_, val]) => val !== undefined)
    .map(([key, val]) => toKvRow(key, val))
}

function toKvRow(key, value) {
  return {
    key,
    label: labelize(key),
    raw: value,
    value: displayValue(value),
  }
}

function labelize(key) {
  return String(key).replace(/_/g, ' ')
}

function displayValue(value) {
  if (value === null || value === undefined || value === '') return '-'
  if (value === true) return 'true'
  if (value === false) return 'false'
  if (Array.isArray(value) || isPlainObject(value)) return compactJson(value)
  return String(value)
}

function compactJson(value) {
  try {
    return JSON.stringify(value)
  } catch (e) {
    return String(value)
  }
}

function formatJson(value) {
  try {
    return JSON.stringify(value ?? {}, null, 2)
  } catch (e) {
    return String(value)
  }
}

function locatorHref(locator) {
  return /^https?:\/\//i.test(locator) ? locator : null
}

function mediaRefHref(media) {
  if (!media || media.file_exists === false) return null
  return typeof media.view_url === 'string' && media.view_url.trim() !== '' ? media.view_url : null
}

function sourceKey(source, idx) {
  return `${source.locator || source.source_locator || source.url || source.path || source.id || 'source'}-${idx}`
}

function fieldDiffKey(diff, idx) {
  return `${diff.field || diff.change_type || 'diff'}-${idx}`
}

function classificationForIndex(idx) {
  if (!classifications.value.length) return null
  const exact = classifications.value.find((classification) => classification.proposal_index === idx)
  return exact || classifications.value[idx] || null
}

function claimKey(claim, idx) {
  return `${claim.index ?? claim.field_name ?? 'claim'}-${idx}`
}

function claimText(claim) {
  if (typeof claim.claim === 'string' && claim.claim.trim() !== '') return claim.claim
  if (claim.claim !== undefined && claim.claim !== null) return displayValue(claim.claim)
  const raw = objectValue(claim.raw)
  for (const key of ['claim', 'claim_text', 'statement', 'extracted_claim', 'extracted_text', 'text', 'value', 'proposed_value']) {
    if (typeof raw[key] === 'string' && raw[key].trim() !== '') return raw[key]
  }
  if (Object.keys(raw).length) return compactJson(raw)
  return null
}

function claimConfidence(claim) {
  const raw = objectValue(claim.raw)
  const value = claim.confidence ?? raw.confidence
  if (value === null || value === undefined || value === '') return null
  const numeric = Number(value)
  return Number.isFinite(numeric) ? Math.round(numeric * 100) : null
}

function booleanValueClass(value) {
  if (value === true) return 'text-ok'
  if (value === false) return 'text-muted'
  return ''
}

function issueKey(issue, idx) {
  if (typeof issue === 'string') return `${issue}-${idx}`
  return `${issue.gate || 'issue'}-${issue.code || issue.type || idx}`
}

function issueGate(issue) {
  return isPlainObject(issue) ? issue.gate || null : null
}

function issueCode(issue) {
  return isPlainObject(issue) ? issue.code || issue.type || null : null
}

function issueMessage(issue) {
  if (typeof issue === 'string') return issue
  if (!isPlainObject(issue)) return displayValue(issue)
  return issue.message || issue.error || issue.warning || compactJson(issue)
}

function operationKey(operation, idx) {
  return `${operation.index ?? operation.operation ?? 'operation'}-${idx}`
}

function operationRows(operation) {
  const hidden = ['index', 'operation', 'target_table']
  if (isStructuredRemediationOperation(operation)) {
    hidden.push('operation_type', 'guards', 'current_state', 'proposed_effect')
  }

  return kvRows(operation).filter((row) => !hidden.includes(row.key))
}

function hasAcceptedFactMutations(value) {
  if (Array.isArray(value)) return value.length > 0
  return value !== null && value !== undefined && value !== false && value !== ''
}

function previewFlagEnabled(value) {
  if (value === null || value === undefined || value === false || value === 0 || value === '') {
    return false
  }
  if (typeof value === 'string') {
    return !['0', 'false', 'no', 'off'].includes(value.trim().toLowerCase())
  }
  return true
}

function isStructuredRemediationOperation(operation) {
  return isFamilyRemediationOperation(operation) || isSourceRemediationOperation(operation)
}

function isFamilyRemediationOperation(operation) {
  return operation?.operation_type === 'family_duplicate_mark'
    || operation?.operation_type === 'family_child_unlink'
    || operation?.operation === 'family_duplicate_mark_preview'
    || operation?.operation === 'family_child_unlink_preview'
}

function isSourceRemediationOperation(operation) {
  return operation?.operation_type === 'source_duplicate_mark'
    || operation?.operation_type === 'source_duplicate_cleanup'
    || operation?.operation === 'source_duplicate_mark_preview'
    || operation?.operation === 'source_add_duplicate_cluster_preview'
}

function guardKey(guard, idx) {
  return `${guard?.name || 'guard'}-${guard?.status || 'status'}-${idx}`
}

function guardStatusClass(guard) {
  const status = String(guard?.status || '').toLowerCase()
  if (status === 'pass') return 'guard-pass'
  if (status === 'fail') return 'guard-fail'
  return 'guard-warn'
}

function familyRows(family) {
  const value = objectValue(family)
  return [
    'id',
    'tree_id',
    'gedcom_id',
    'husband_id',
    'husband_name',
    'wife_id',
    'wife_name',
    'marriage_date',
    'marriage_place',
  ]
    .filter((key) => value[key] !== undefined)
    .map((key) => toKvRow(key, value[key]))
}

function familyChildren(family) {
  return arrayValue(objectValue(family).children).filter(isPlainObject)
}

function sourceRows(source) {
  const value = objectValue(source)
  return [
    'id',
    'tree_id',
    'gedcom_id',
    'uid',
    'title',
    'author',
    'publication',
    'repository',
    'call_number',
    'url',
    'source_quality',
    'source_category',
    'information_quality',
  ]
    .filter((key) => value[key] !== undefined)
    .map((key) => toKvRow(key, value[key]))
    .concat(sourceUsageRows(value.usage_counts))
}

function sourceUsageRows(counts) {
  const value = objectValue(counts)
  return Object.keys(value).map((key) => toKvRow(key, value[key]))
}

function sourceLocatorGroups(operation) {
  const state = objectValue(operation?.current_state)
  const resolution = objectValue(state.proposed_change_resolution)
  return arrayValue(resolution.locator_groups).filter(isPlainObject)
}

function sourceResolutionRows(operation) {
  const state = objectValue(operation?.current_state)
  const resolution = objectValue(state.proposed_change_resolution)
  return arrayValue(resolution.rows).filter(isPlainObject)
}

function locatorGroupKey(group, idx) {
  return `${group.locator_key || group.locator || 'locator'}-${idx}`
}

function locatorGroupMeta(group) {
  const parts = []
  if (group.proposal_count !== undefined) parts.push(`${group.proposal_count} proposals`)
  const statuses = objectValue(group.proposal_statuses)
  const statusText = Object.entries(statuses).map(([key, value]) => `${key}: ${value}`).join(', ')
  if (statusText) parts.push(statusText)
  const sourceIds = arrayValue(group.resolved_source_ids)
  if (sourceIds.length) parts.push(`source rows ${sourceIds.join(', ')}`)
  const proposalIds = arrayValue(group.proposed_change_ids)
  if (proposalIds.length) parts.push(`proposal ids ${proposalIds.join(', ')}`)
  return parts.length ? parts.join(' · ') : '-'
}

function sourceResolutionKey(row, idx) {
  return `${row.proposed_change_id || 'proposal'}-${idx}`
}

function sourceResolutionLabel(row) {
  const parts = []
  if (row.proposed_change_id) parts.push(`#${row.proposed_change_id}`)
  if (row.proposal_status) parts.push(row.proposal_status)
  if (row.resolution_status) parts.push(`resolution ${row.resolution_status}`)
  if (row.resolution_method) parts.push(row.resolution_method)
  if (row.resolved_source_id) parts.push(`source #${row.resolved_source_id}`)
  if (row.locator || row.url) parts.push(row.locator || row.url)
  if (row.message) parts.push(row.message)
  return parts.length ? parts.join(' · ') : displayValue(row)
}

function childLabel(child) {
  const parts = [
    child.name || `person #${child.person_id || '-'}`,
    child.person_id ? `person #${child.person_id}` : null,
    child.birth_date ? `b. ${child.birth_date}` : null,
    child.birth_order !== null && child.birth_order !== undefined ? `order ${child.birth_order}` : null,
  ].filter(Boolean)

  return parts.join(' · ')
}

function proposedEffectRows(effect) {
  return kvRows(objectValue(effect)).filter((row) => row.key !== 'rows_that_would_be_touched')
}

function touchedRows(effect) {
  return arrayValue(objectValue(effect).rows_that_would_be_touched).filter(isPlainObject)
}

function sharedChildText(operation) {
  const state = objectValue(operation?.current_state)
  const shared = arrayValue(state.shared_child_ids)
  if (!shared.length) {
    const retainedLinks = arrayValue(state.retained_child_links)
    const suspectLinks = arrayValue(state.suspect_child_links)
    const linkedIds = retainedLinks.length ? retainedLinks : suspectLinks
    return linkedIds.length ? linkedIds.map((link) => link.person_id).filter(Boolean).join(', ') : '-'
  }

  return shared.length ? shared.join(', ') : '-'
}

function matchingFieldText(operation) {
  const state = objectValue(operation?.current_state)
  const fields = arrayValue(objectValue(state.duplicate_signals).matching_fields)
  return fields.length ? fields.join(', ') : '-'
}

function staleHashShort(hash) {
  const value = String(hash || '')
  if (value === '') return '-'
  return value.length > 16 ? `${value.slice(0, 16)}...` : value
}

function decisionKey(entry, idx) {
  return `${entry.action || 'event'}-${entry.created_at || 'time'}-${idx}`
}

function decisionReason(entry) {
  if (!isPlainObject(entry)) return null
  const meta = objectValue(entry.meta)
  for (const value of [entry.reason_code, meta.reason_code, entry.reason, meta.reason, entry.note, entry.notes]) {
    if (typeof value === 'string' && value.trim() !== '') return value.trim()
  }
  return null
}

function submitDecision(action) {
  const notes = decisionNotes.value.trim()
  const reasonCode = decisionReasonCode.value.trim()
  const payload = {
    unifiedId: item.value.unified_id,
    notes,
  }
  if (action !== 'approve' && reasonCode) {
    payload.reasonCode = reasonCode
  }
  emit(action, payload)
}

function resetDecisionInputs() {
  decisionNotes.value = ''
  decisionReasonCode.value = ''
}

function formatDate(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString()
}

function objectKeys(value) {
  return isPlainObject(value) ? Object.keys(value) : []
}
</script>

<style scoped>
.packet-detail {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  min-width: 0;
}

.packet-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
}

.packet-heading-main {
  min-width: 0;
  flex: 1;
}

.packet-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: #ffb47a;
  margin: 0;
  overflow-wrap: anywhere;
}

.packet-summary {
  margin-top: 0.25rem;
  color: #d4c2f0;
  font-size: 0.8rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.packet-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.meta-pill,
.section-count,
.section-status,
.claim-pill {
  font-size: 0.67rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.15rem 0.45rem;
  border-radius: 0.25rem;
}

.meta-type {
  background: rgba(99, 179, 237, 0.20);
  color: #bfe1ff;
}

.status-ok,
.conf-high {
  background: rgba(0, 170, 0, 0.25);
  color: #b5f5b5;
}

.status-pending,
.conf-med,
.status-warning {
  background: rgba(204, 136, 0, 0.25);
  color: #ffd980;
}

.status-danger,
.conf-low {
  background: rgba(204, 0, 0, 0.25);
  color: #ffb5b5;
}

.packet-section {
  background: rgba(0, 0, 0, 0.18);
  border: 1px solid rgba(102, 102, 102, 0.30);
  border-radius: 0.5rem;
  padding: 0.7rem 0.8rem;
  min-width: 0;
}

.status-section {
  border-color: rgba(99, 179, 237, 0.24);
}

.review-focus-section {
  border-color: rgba(255, 204, 102, 0.26);
}

.focus-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(7.5rem, 1fr));
  gap: 0.45rem;
  min-width: 0;
}

.focus-tile,
.focus-line,
.focus-boundary {
  min-width: 0;
  border: 1px solid rgba(255, 204, 102, 0.18);
  border-radius: 0.35rem;
  background: rgba(255, 204, 102, 0.06);
  padding: 0.45rem 0.5rem;
}

.focus-tile {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.focus-tile.ok {
  border-color: rgba(47, 158, 68, 0.35);
  background: rgba(47, 158, 68, 0.10);
}

.focus-tile.danger,
.focus-boundary.danger {
  border-color: rgba(204, 68, 68, 0.45);
  background: rgba(204, 68, 68, 0.12);
}

.focus-label {
  color: #b39ddb;
  font-size: 0.64rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.focus-value,
.focus-line-value,
.focus-boundary {
  color: #f0e6ff;
  font-size: 0.8rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.focus-value {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.focus-line,
.focus-boundary {
  display: grid;
  grid-template-columns: minmax(4.75rem, 0.2fr) minmax(0, 1fr);
  gap: 0.5rem;
  align-items: baseline;
  margin-top: 0.45rem;
}

.section-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
  margin-bottom: 0.5rem;
  color: #b39ddb;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.section-count {
  background: rgba(99, 51, 153, 0.30);
  color: #d4c2f0;
}

.section-status.preview {
  background: rgba(99, 179, 237, 0.18);
  color: #bfe1ff;
}

.subheading {
  color: #b39ddb;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  margin: 0.35rem 0;
}

.subheading.danger { color: #ffb5b5; }
.subheading.warning { color: #ffd980; }

.locator-list,
.claim-list,
.operation-list,
.decision-list,
.issue-list {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  min-width: 0;
}

.locator-row {
  display: block;
  color: #bfe1ff;
  background: rgba(99, 179, 237, 0.08);
  border: 1px solid rgba(99, 179, 237, 0.22);
  border-radius: 0.35rem;
  padding: 0.4rem 0.5rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size: 0.74rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  word-break: break-word;
}

a.locator-row:hover {
  color: #ffffff;
  border-color: rgba(99, 179, 237, 0.55);
}

.source-payloads {
  margin-top: 0.65rem;
}

.media-refs {
  margin-top: 0.65rem;
}

.media-ref-list {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  min-width: 0;
}

.media-ref-card {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 0.5rem;
  align-items: center;
  color: #bfe1ff;
  background: rgba(99, 179, 237, 0.08);
  border: 1px solid rgba(99, 179, 237, 0.22);
  border-radius: 0.35rem;
  padding: 0.42rem 0.5rem;
  text-decoration: none;
  min-width: 0;
}

a.media-ref-card:hover {
  color: #ffffff;
  border-color: rgba(99, 179, 237, 0.55);
}

.media-ref-disabled {
  color: #9b8bb5;
  border-color: rgba(102, 102, 102, 0.25);
  background: rgba(0, 0, 0, 0.12);
}

.media-ref-id,
.media-ref-open {
  color: #b39ddb;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
}

.media-ref-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.media-ref-title {
  color: #ffe5b3;
  font-size: 0.8rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.media-ref-sub {
  color: #cabde0;
  font-size: 0.72rem;
  overflow-wrap: anywhere;
}

.source-row,
.claim-row,
.operation-row,
.decision-row {
  border-top: 1px solid rgba(102, 102, 102, 0.22);
  padding-top: 0.45rem;
  min-width: 0;
}

.source-row-title,
.operation-name,
.decision-action {
  color: #ffe5b3;
  font-size: 0.82rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.claim-topline,
.operation-head,
.decision-head,
.claim-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
}

.claim-index {
  color: #b39ddb;
  font-size: 0.72rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
}

.claim-pill {
  background: rgba(99, 51, 153, 0.28);
  color: #d4c2f0;
}

.claim-pill.muted {
  background: rgba(102, 102, 102, 0.18);
  color: #d8d0e4;
}

.claim-text {
  margin-top: 0.35rem;
  color: #f0e6ff;
  font-size: 0.85rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.claim-meta,
.decision-actor,
.decision-time,
.operation-target {
  color: #b39ddb;
  font-size: 0.72rem;
  overflow-wrap: anywhere;
}

.packet-two-col,
.validation-grid,
.packet-context-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.75rem;
}

@media (min-width: 900px) {
  .packet-two-col,
  .validation-grid,
  .packet-context-grid {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
}

.packet-diff-heading {
  display: none;
}

.packet-diff-list {
  min-width: 0;
  overflow-x: auto;
}

@media (min-width: 760px) {
  .packet-diff-heading {
    display: grid;
    grid-template-columns: 8rem 1fr 1.5fr auto;
    gap: 0.75rem;
    padding: 0 0.5rem 0.35rem;
    color: #b39ddb;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
  }
}

.kv-grid {
  display: grid;
  gap: 0.3rem;
  min-width: 0;
}

.kv-grid.compact {
  margin-top: 0.35rem;
  gap: 0.2rem;
}

.kv-row {
  display: grid;
  grid-template-columns: minmax(5.5rem, 0.35fr) minmax(0, 1fr);
  gap: 0.5rem;
  align-items: baseline;
  min-width: 0;
}

.kv-key {
  color: #b39ddb;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.kv-value {
  color: #f0e6ff;
  font-size: 0.78rem;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  min-width: 0;
}

.preview-note {
  color: #f0e6ff;
  font-size: 0.82rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.preview-note.muted,
.empty-line,
.text-muted {
  color: #9b8bb5;
}

.preview-summary {
  margin: 0.5rem 0;
}

.remediation-preview {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin-top: 0.65rem;
  padding: 0.6rem;
  border: 1px solid rgba(99, 179, 237, 0.22);
  border-radius: 0.35rem;
  background: rgba(99, 179, 237, 0.06);
  min-width: 0;
}

.remediation-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.6rem;
  min-width: 0;
}

@media (min-width: 900px) {
  .remediation-grid {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
}

.remediation-block {
  min-width: 0;
}

.remediation-block.wide {
  grid-column: 1 / -1;
}

.guard-list,
.child-list,
.touch-list,
.locator-group-list,
.source-resolution-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}

.guard-row {
  display: grid;
  grid-template-columns: minmax(5.5rem, 0.28fr) minmax(4rem, 0.16fr) minmax(0, 1fr);
  gap: 0.4rem;
  align-items: baseline;
  padding: 0.3rem 0.4rem;
  border-left: 3px solid rgba(102, 102, 102, 0.45);
  border-radius: 0.25rem;
  background: rgba(0, 0, 0, 0.18);
  min-width: 0;
}

.guard-pass {
  border-left-color: #2f9e44;
}

.guard-fail {
  border-left-color: #cc4444;
}

.guard-name,
.guard-status {
  color: #d4c2f0;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  overflow-wrap: anywhere;
}

.guard-status {
  color: #ffe5b3;
}

.guard-message,
.child-row,
.touch-row,
.locator-group-row,
.source-resolution-row {
  color: #f0e6ff;
  font-size: 0.76rem;
  line-height: 1.3;
  overflow-wrap: anywhere;
}

.child-list,
.touch-list,
.locator-group-list,
.source-resolution-list {
  margin-top: 0.45rem;
}

.child-row,
.touch-row,
.locator-group-row,
.source-resolution-row {
  padding: 0.28rem 0.4rem;
  border-radius: 0.25rem;
  background: rgba(0, 0, 0, 0.16);
}

.locator-group-title {
  color: #ffe5b3;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.locator-group-meta {
  margin-top: 0.1rem;
  color: #cabde0;
  overflow-wrap: anywhere;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
}

.text-ok {
  color: #b5f5b5;
}

.text-danger {
  color: #ffb5b5;
}

.issue-row {
  border-left: 3px solid rgba(102, 102, 102, 0.4);
  padding: 0.35rem 0.5rem;
  background: rgba(0, 0, 0, 0.16);
  border-radius: 0.25rem;
  min-width: 0;
}

.issue-row.danger {
  border-left-color: #cc4444;
}

.issue-row.warning {
  border-left-color: #cc8800;
}

.issue-gate,
.issue-code {
  display: inline-block;
  margin-right: 0.3rem;
  color: #d4c2f0;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
}

.issue-message {
  color: #f0e6ff;
  font-size: 0.78rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.decision-note {
  margin-top: 0.25rem;
  color: #f0e6ff;
  font-size: 0.78rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.inline-json,
.raw-details pre {
  margin: 0.4rem 0 0;
  color: #d8d0e4;
  background: rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(102, 102, 102, 0.25);
  border-radius: 0.35rem;
  padding: 0.55rem;
  font-size: 0.72rem;
  line-height: 1.35;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.raw-details {
  background: rgba(0, 0, 0, 0.14);
  border: 1px solid rgba(102, 102, 102, 0.25);
  border-radius: 0.5rem;
  padding: 0.55rem 0.7rem;
  min-width: 0;
}

.raw-details summary {
  color: #b39ddb;
  cursor: pointer;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.packet-actions {
  border-color: rgba(99, 179, 237, 0.24);
}

.action-fields {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.6rem;
  min-width: 0;
}

@media (min-width: 900px) {
  .action-fields {
    grid-template-columns: minmax(0, 1fr) minmax(11rem, 0.32fr);
  }
}

.action-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}

.action-label {
  color: #b39ddb;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.action-notes,
.action-select {
  width: 100%;
  min-width: 0;
  color: #f0e6ff;
  background: rgba(0, 0, 0, 0.22);
  border: 1px solid rgba(102, 102, 102, 0.38);
  border-radius: 0.35rem;
  font-size: 0.82rem;
}

.action-notes {
  resize: vertical;
  min-height: 4.5rem;
  padding: 0.55rem 0.6rem;
  line-height: 1.35;
}

.action-select {
  height: 2.35rem;
  padding: 0 0.55rem;
}

.action-notes:focus,
.action-select:focus {
  border-color: rgba(99, 179, 237, 0.70);
  outline: none;
}

.action-notes:disabled,
.action-select:disabled {
  color: #9b8bb5;
  background: rgba(0, 0, 0, 0.14);
}

.action-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 0.65rem;
}

.approval-blockers {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.65rem;
  min-width: 0;
}

.approval-blockers-label {
  color: #b39ddb;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.approval-blocker {
  max-width: 100%;
  padding: 0.22rem 0.45rem;
  border: 1px solid rgba(204, 136, 0, 0.42);
  border-radius: 0.35rem;
  color: #ffe3b0;
  background: rgba(204, 136, 0, 0.14);
  font-size: 0.72rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.packet-action {
  min-height: 2.25rem;
  border: 1px solid rgba(99, 179, 237, 0.28);
  border-radius: 0.35rem;
  padding: 0.35rem 0.65rem;
  color: #d8efff;
  background: rgba(99, 179, 237, 0.12);
  font-size: 0.76rem;
  font-weight: 700;
  cursor: pointer;
}

.packet-action.primary {
  border-color: rgba(47, 158, 68, 0.55);
  color: #d9ffd9;
  background: rgba(47, 158, 68, 0.22);
}

.packet-action.danger {
  border-color: rgba(204, 68, 68, 0.55);
  color: #ffd9d9;
  background: rgba(204, 68, 68, 0.20);
}

.packet-action.ghost {
  border-color: rgba(102, 102, 102, 0.34);
  color: #d8d0e4;
  background: rgba(0, 0, 0, 0.14);
}

.packet-action:disabled {
  cursor: not-allowed;
  opacity: 0.52;
}

.packet-action:not(:disabled):hover {
  border-color: rgba(255, 255, 255, 0.45);
}
</style>

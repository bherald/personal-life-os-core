<template>
  <div class="packet-detail" :class="{ 'face-packet': isFacePersonSourcePacket }">
    <div class="packet-header">
      <div class="packet-heading-main">
        <h3 class="packet-title">{{ displayTitle }}</h3>
        <div v-if="displaySummary" class="packet-summary">{{ displaySummary }}</div>
      </div>
      <div v-if="!isFacePersonSourcePacket" class="packet-meta">
        <span class="meta-pill meta-type">packet</span>
        <span class="meta-pill" :class="statusClass">{{ packetStatusLabel }}</span>
        <span v-if="confidencePercent !== null" class="meta-pill" :class="confidenceClass">
          {{ confidencePercent }}%
        </span>
      </div>
      <div v-if="targetRef && !isFacePersonSourcePacket" class="target-ref-actions">
        <button
          type="button"
          class="target-ref-copy"
          title="Copy target ref"
          @click="copyTargetRef"
        >
          {{ targetRefCopied ? 'Copied' : 'Copy ref' }}
        </button>
        <button
          type="button"
          class="target-ref-copy"
          title="Copy target link"
          @click="copyTargetLink"
        >
          {{ targetLinkCopied ? 'Copied' : 'Copy link' }}
        </button>
      </div>
    </div>

    <section v-if="isPhotoAuditHold" class="packet-section face-review-overview">
      <div class="section-heading">
        <span>Photo review hold</span>
        <span class="section-status status-warning">awaiting evidence review</span>
      </div>
      <p class="face-review-lead">{{ auditHoldReason }}</p>
      <div class="face-review-boundary">
        <strong>Identity and photo links remain unchanged.</strong>
        <span>{{ auditHoldHasRegion ? 'This hold refers to a recorded face region.' : 'This hold refers to the photo; a face region has not been specified.' }}</span>
      </div>
      <div v-for="media in auditHoldMedia" :key="media.id" class="audit-hold-evidence">
        <a v-if="mediaRefHref(media)" class="face-candidate-photo" :href="mediaRefHref(media)" target="_blank" rel="noopener noreferrer">
          <img v-if="mediaRefThumbnail(media)" :src="mediaRefThumbnail(media)" :alt="media.title || media.local_filename || 'Photo awaiting review'" loading="lazy" />
          <span v-else>Open full photo</span>
        </a>
        <div class="audit-hold-context">
          <strong>{{ media.title || media.local_filename || 'Photo awaiting review' }}</strong>
          <p>Review the recorded source and operator decisions below before deciding this hold.</p>
        </div>
      </div>
      <a class="picture-match-link" :href="pictureMatchApprovalHref">Go to picture approvals</a>
    </section>

    <section v-if="inspectionOnly && !isFacePersonSourcePacket && !isPhotoAuditHold" class="packet-section inspection-only-notice" role="status">
      <div class="section-heading">
        <span>Preview only</span>
        <span class="section-status preview">inspection</span>
      </div>
      <p>This packet records a review of the preview; it does not apply the proposed Family Tree change. No approval or rejection action is available here.</p>
    </section>

    <template v-if="isFacePersonSourcePacket">
      <section class="packet-section face-review-overview" :class="{ blocked: !faceCandidatePacketReady }">
        <div class="section-heading">
          <span>{{ faceCandidatePacketReady ? 'Photo comparison available' : 'No decision needed' }}</span>
          <span class="section-status" :class="faceCandidatePacketReady ? 'status-ok' : 'status-danger'">
            {{ faceCandidatePacketReady ? `${faceRepairCandidates.length} candidates` : 'incomplete data' }}
          </span>
        </div>
        <p class="face-review-lead">{{ faceReviewLead }}</p>
        <div class="face-review-boundary">
          <strong>{{ faceCandidatePacketReady ? 'Use Picture Match Approval to decide identity.' : 'PLOS maintenance must rebuild this diagnostic.' }}</strong>
          <span>{{ faceCandidatePacketReady
            ? 'This packet is supporting context only; it cannot approve or reject a person.'
            : 'It is missing the two-name comparison needed for a reliable human decision. You do not need to review or close it.' }}</span>
        </div>
        <a class="picture-match-link" :href="pictureMatchApprovalHref">Go to picture approvals</a>
      </section>

      <section v-if="activeFaceCandidate && faceCandidatePacketReady" class="packet-section face-candidate-section">
        <div class="face-candidate-nav">
          <button
            type="button"
            class="candidate-nav-button"
            :disabled="activeFaceCandidateIndex <= 0"
            title="Previous photo candidate"
            aria-label="Previous photo candidate"
            @click="activeFaceCandidateIndex--"
          >
            &larr;
          </button>
          <div>
            <span>Candidate {{ activeFaceCandidateIndex + 1 }} of {{ faceRepairCandidates.length }}</span>
            <strong>{{ activeFaceCandidate.filename }}</strong>
          </div>
          <button
            type="button"
            class="candidate-nav-button"
            :disabled="activeFaceCandidateIndex >= faceRepairCandidates.length - 1"
            title="Next photo candidate"
            aria-label="Next photo candidate"
            @click="activeFaceCandidateIndex++"
          >
            &rarr;
          </button>
        </div>

        <div class="face-candidate-evidence">
          <a
            v-if="activeFaceCandidate.media && mediaRefHref(activeFaceCandidate.media)"
            class="face-candidate-photo"
            :href="mediaRefHref(activeFaceCandidate.media)"
            target="_blank"
            rel="noopener noreferrer"
            title="Open full photo"
          >
            <img
              v-if="mediaRefThumbnail(activeFaceCandidate.media)"
              :src="mediaRefThumbnail(activeFaceCandidate.media)"
              :alt="`Photo candidate ${activeFaceCandidateIndex + 1}: ${activeFaceCandidate.filename}`"
              loading="lazy"
              @error="markFaceCandidateThumbError(activeFaceCandidate)"
            />
            <span v-else>Open full photo</span>
          </a>
          <div v-else class="face-candidate-photo missing">Photo is not available in this packet.</div>

          <div class="face-person-comparison">
            <div class="person-side current">
              <span>Currently linked person</span>
              <strong>{{ activeFaceCandidate.linkedPerson || 'Missing from packet' }}</strong>
            </div>
            <div class="comparison-arrow" aria-hidden="true">vs</div>
            <div class="person-side proposed" :class="{ missing: !activeFaceCandidate.comparedPerson }">
              <span>Person named by exported record</span>
              <strong>{{ activeFaceCandidate.comparedPerson || 'Missing from packet' }}</strong>
            </div>
            <p class="candidate-explanation" :class="{ blocked: !activeFaceCandidate.ready }">
              {{ activeFaceCandidate.explanation }}
            </p>
          </div>
        </div>
      </section>
    </template>

    <section v-if="!isFacePersonSourcePacket && !isPhotoAuditHold && !inspectionOnly" class="packet-section operator-question-section">
      <div class="section-heading">
        <span>Operator question</span>
        <span class="section-status preview">packet-level</span>
      </div>
      <div class="operator-question">
        {{ operatorQuestion }}
      </div>
      <div class="operator-question-note">
        <template v-if="isFacePersonSourcePacket">
          This action only changes the packet's workflow status. It never identifies a person, links a face, or changes Family Tree data.
        </template>
        <template v-else>
          Packet actions affect the packet review state. They are not line-item source decisions and do not update accepted FT facts.
          If any candidate line is uncertain, use Reject, Clarify, or Defer rather than treating packet review as an attach-all action.
        </template>
      </div>
    </section>

    <section v-if="!isFacePersonSourcePacket && !isPhotoAuditHold && !inspectionOnly" class="packet-section packet-actions decision-dock" aria-labelledby="packet-decision-title">
      <div class="section-heading">
        <span id="packet-decision-title">Choose packet outcome</span>
        <span class="section-status preview">review state only</span>
      </div>

      <div class="decision-scope" :class="{ 'face-scope': isFacePersonSourcePacket }">
        <strong>{{ decisionScopeTitle }}</strong>
        <span>{{ decisionScopeDescription }}</span>
      </div>

      <div class="decision-readiness" :class="decisionReadinessClass" aria-label="Packet decision readiness">
        <div class="decision-readiness-state">
          <span>Decision readiness</span>
          <strong>{{ decisionReadinessLabel }}</strong>
        </div>
        <div v-for="fact in decisionReadinessFacts" :key="fact.key" class="decision-readiness-fact">
          <span>{{ fact.label }}</span>
          <strong>{{ fact.value }}</strong>
        </div>
      </div>

      <div class="action-buttons decision-primary-actions">
        <button
          type="button"
          class="packet-action primary"
          :disabled="actioning || !canMarkReviewed"
          :title="markReviewedDisabledTitle"
          aria-describedby="packet-decision-scope"
          @click="submitDecision('approve')"
        >
          {{ markReviewedLabel }}
        </button>
        <button
          type="button"
          class="packet-action danger"
          :class="{ selected: pendingAlternateAction === 'reject' }"
          :disabled="actioning || !canAct"
          title="Add a reason, then confirm rejection"
          @click="prepareAlternateDecision('reject')"
        >
          Reject packet
        </button>
        <button
          type="button"
          class="packet-action"
          :class="{ selected: pendingAlternateAction === 'clarify' }"
          :disabled="actioning || !canAct"
          title="Add a reason, then confirm the clarification request"
          @click="prepareAlternateDecision('clarify')"
        >
          Request clarification
        </button>
        <button
          type="button"
          class="packet-action"
          :class="{ selected: pendingAlternateAction === 'defer' }"
          :disabled="actioning || !canAct"
          title="Add a reason, then confirm deferral"
          @click="prepareAlternateDecision('defer')"
        >
          Defer
        </button>
        <button type="button" class="packet-action ghost" :disabled="actioning" @click="emit('close')">
          Close
        </button>
      </div>

      <p id="packet-decision-scope" class="decision-effect">
        {{ markReviewedEffect }}
      </p>

      <div v-if="pendingAlternateAction" class="decision-options" role="group" aria-label="Confirm alternate packet outcome">
        <div class="decision-options-heading">
          <div>
            <span>Reason and note for an alternate outcome</span>
            <strong>{{ pendingAlternateActionLabel }}</strong>
          </div>
          <button type="button" class="packet-action ghost" :disabled="actioning" @click="cancelAlternateDecision">
            Cancel
          </button>
        </div>
        <div class="action-fields">
          <label class="action-field action-reason">
            <span class="action-label">Reason</span>
            <select v-model="decisionReasonCode" class="action-select" :disabled="actioning || !canAct">
              <option value="">Select a reason</option>
              <option v-for="option in reasonCodeOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label class="action-field">
            <span class="action-label">Decision note</span>
            <textarea
              v-model="decisionNotes"
              class="action-notes"
              :disabled="actioning || !canAct"
              rows="2"
              maxlength="1200"
              placeholder="Optional context for this decision"
            ></textarea>
          </label>
        </div>
        <div v-if="canAct && !selectedDecisionReasonCode" class="action-hint">
          Select a reason to enable {{ pendingAlternateActionConfirmLabel }}.
        </div>
        <button
          type="button"
          class="packet-action alternate-confirm"
          :class="{ danger: pendingAlternateAction === 'reject' }"
          :disabled="actioning || !canConfirmAlternateOutcome"
          @click="confirmAlternateDecision"
        >
          {{ pendingAlternateActionConfirmLabel }}
        </button>
      </div>

      <div v-if="approvalBlockers.length" class="approval-blockers" role="status">
        <span class="approval-blockers-label">Review blockers</span>
        <span v-for="blocker in approvalBlockers" :key="blocker.code || blocker.label" class="approval-blocker">
          {{ blocker.label || labelize(blocker.code || blocker) }}
        </span>
      </div>
    </section>

    <details
      class="packet-technical-details"
      :class="{ 'face-technical-details': isFacePersonSourcePacket }"
      :open="!isFacePersonSourcePacket"
    >
      <summary v-if="isFacePersonSourcePacket">Audit details (optional)</summary>

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
      <div v-if="reviewFocusSourceLabel" class="focus-line">
        <span class="focus-label">Source</span>
        <span class="focus-line-value">{{ reviewFocusSourceLabel }}</span>
      </div>
      <div class="focus-boundary" :class="{ danger: reviewFocusCanonicalMutation === true }">
        <span class="focus-label">Canonical mutation</span>
        <span>{{ canonicalMutationLabel }}</span>
      </div>
    </section>

    <section v-if="reviewPassRows.length" class="packet-section review-pass-section">
      <div class="section-heading">
        <span>Review pass</span>
        <span class="section-status preview">display only</span>
      </div>
      <div class="checklist-grid proof-grid">
        <div
          v-for="row in reviewPassRows"
          :key="row.key"
          class="checklist-row proof-row"
          :class="checklistStateClass(row.state)"
        >
          <span class="checklist-state">{{ checklistStateLabel(row.state) }}</span>
          <span class="checklist-label">{{ row.label }}</span>
          <span class="checklist-value" :title="row.value">{{ row.value }}</span>
        </div>
      </div>
    </section>

    <section v-if="reviewProofRows.length" class="packet-section review-proof-section">
      <div class="section-heading">
        <span>Review proof</span>
        <span class="section-status preview">display only</span>
      </div>
      <div class="checklist-grid proof-grid">
        <div
          v-for="row in reviewProofRows"
          :key="row.key"
          class="checklist-row proof-row"
          :class="checklistStateClass(row.state)"
        >
          <span class="checklist-state">{{ checklistStateLabel(row.state) }}</span>
          <span class="checklist-label">{{ row.label }}</span>
          <span class="checklist-value" :title="row.value">{{ row.value }}</span>
        </div>
      </div>
    </section>

    <section v-if="workTrailRows.length" class="packet-section work-trail-section">
      <div class="section-heading">
        <span>Work trail</span>
        <span class="section-status preview">display only</span>
      </div>
      <div v-if="workTrailSummaryRows.length" class="kv-grid compact work-trail-summary">
        <div v-for="row in workTrailSummaryRows" :key="row.key" class="kv-row">
          <span class="kv-key">{{ row.label }}</span>
          <span class="kv-value">{{ row.value }}</span>
        </div>
      </div>
      <div class="checklist-grid work-trail-grid">
        <div
          v-for="row in workTrailRows"
          :key="row.key"
          class="checklist-row work-trail-row"
          :class="checklistStateClass(row.state)"
        >
          <span class="checklist-state">{{ checklistStateLabel(row.state) }}</span>
          <span class="checklist-label">{{ row.label }}</span>
          <span class="checklist-value" :title="row.value">{{ row.value }}</span>
        </div>
      </div>
    </section>

    <section v-if="packetChecklistRows.length" class="packet-section review-checklist-section">
      <div class="section-heading">
        <span>Review checklist</span>
        <span class="section-status preview">display only</span>
      </div>
      <div class="checklist-grid">
        <div
          v-for="row in packetChecklistRows"
          :key="row.key"
          class="checklist-row"
          :class="checklistStateClass(row.state)"
        >
          <span class="checklist-state">{{ checklistStateLabel(row.state) }}</span>
          <span class="checklist-label">{{ row.label }}</span>
          <span class="checklist-value" :title="row.value">{{ row.value }}</span>
        </div>
      </div>
    </section>

    <section v-if="hasRemediationOrigin" class="packet-section remediation-origin-section">
      <div class="section-heading">
        <span>Remediation origin</span>
        <span class="section-status preview">display only</span>
      </div>
      <div class="kv-grid compact">
        <div v-for="row in remediationOriginRows" :key="row.key" class="kv-row">
          <span class="kv-key">{{ row.label }}</span>
          <span class="kv-value">{{ row.value }}</span>
        </div>
      </div>
      <div v-if="remediationOriginOperationTypes.length" class="origin-op-list">
        <span
          v-for="operationType in remediationOriginOperationTypes"
          :key="operationType"
          class="claim-pill"
        >
          {{ operationType }}
        </span>
      </div>
    </section>

    <section class="packet-section status-section">
      <div class="section-heading">
        <span>Packet status</span>
        <span class="section-status" :class="statusClass">{{ packetStatusLabel }}</span>
      </div>
      <div v-if="packetOutcomeRows.length" class="outcome-progress">
        <div class="outcome-head" :class="outcomeStateClass">
          <span class="outcome-label">{{ packetOutcomeLabel }}</span>
          <span v-if="packetOutcomePreviewOnly" class="outcome-preview">preview-only</span>
        </div>
        <div class="kv-grid compact">
          <div v-for="row in packetOutcomeRows" :key="row.key" class="kv-row">
            <span class="kv-key">{{ row.label }}</span>
            <span class="kv-value">{{ row.value }}</span>
          </div>
        </div>
      </div>
      <div v-else-if="!decisionLogRows.length" class="empty-line">No decision has been recorded.</div>
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
        <template v-for="(locator, idx) in sourceLocators" :key="`locator-${idx}`">
          <a
            v-if="locatorHref(locator)"
            :href="locatorHref(locator)"
            target="_blank"
            rel="noopener noreferrer"
            class="locator-row"
            :title="`Open source ${idx + 1}`"
          >
            <span>{{ sourcePointerLabel(locator, idx) }}</span>
            <span class="locator-kind">{{ sourcePointerKind(locator) }}</span>
          </a>
          <div v-else class="locator-row">
            <span>{{ sourcePointerLabel(locator, idx) }}</span>
            <span class="locator-kind">{{ sourcePointerKind(locator) }}</span>
          </div>
        </template>
      </div>
      <div v-else class="empty-line">No source locator supplied.</div>

      <div v-if="sources.length" class="source-payloads">
        <div class="subheading">Source payloads</div>
        <div v-for="(source, idx) in sources" :key="sourceKey(source, idx)" class="source-row">
          <div class="source-row-title">{{ sourceDisplayLabel(source, idx) }}</div>
          <div class="kv-grid compact">
            <div v-for="row in sourcePayloadRows(source)" :key="row.key" class="kv-row">
              <span class="kv-key">{{ row.label }}</span>
              <span class="kv-value">{{ row.value }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="mediaRefs.length" class="media-refs">
        <div class="subheading">Resolved source media</div>
        <div class="media-ref-list">
          <template v-for="(media, mediaIdx) in mediaRefs" :key="mediaRefKey(media, mediaIdx)">
            <a
              v-if="mediaRefHref(media)"
              :href="mediaRefHref(media)"
              target="_blank"
              rel="noopener noreferrer"
              class="media-ref-card"
              :title="media.title || 'Open media item'"
            >
              <span class="media-ref-thumb" :class="{ empty: !mediaRefThumbnail(media) }">
                <img
                  v-if="mediaRefThumbnail(media)"
                  :src="mediaRefThumbnail(media)"
                  alt=""
                  loading="lazy"
                  @error="markMediaThumbError(mediaRefKey(media, mediaIdx))"
                />
                <span v-else>file</span>
              </span>
              <span class="media-ref-id">{{ mediaDisplayLabel(media, mediaIdx) }}</span>
              <span class="media-ref-main">
                <span class="media-ref-title">{{ media.title || 'Media item' }}</span>
                <span class="media-ref-sub">
                  {{ media.file_format || media.mime_type || 'unknown format' }}
                  <span v-if="media.media_type"> · {{ media.media_type }}</span>
                </span>
              </span>
              <span class="media-ref-open">Open</span>
            </a>
            <div v-else class="media-ref-card media-ref-disabled" :title="media.title || 'Media item'">
              <span class="media-ref-thumb empty">
                <span>file</span>
              </span>
              <span class="media-ref-id">{{ mediaDisplayLabel(media, mediaIdx) }}</span>
              <span class="media-ref-main">
                <span class="media-ref-title">{{ media.title || 'Media item' }}</span>
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

    <section v-if="evidenceLensRows.length" class="packet-section evidence-lens-section">
      <div class="section-heading">
        <span>Evidence lens</span>
        <span class="section-status preview">display only</span>
      </div>
      <div class="checklist-grid evidence-lens-grid">
        <div
          v-for="row in evidenceLensRows"
          :key="row.key"
          class="checklist-row evidence-lens-row"
          :class="checklistStateClass(row.state)"
        >
          <span class="checklist-state">{{ checklistStateLabel(row.state) }}</span>
          <span class="checklist-label">{{ row.label }}</span>
          <span class="checklist-value" :title="row.value">{{ row.value }}</span>
        </div>
      </div>
    </section>

    <section v-if="claimEvidenceRows.length" class="packet-section">
      <div class="section-heading">
        <span>Claim to source</span>
        <span class="section-count">{{ claimEvidenceRows.length }}</span>
      </div>
      <div class="claim-source-list">
        <div v-for="row in claimEvidenceRows" :key="row.key" class="claim-source-row">
          <div class="claim-source-main">
            <div class="claim-topline">
              <span class="claim-index">#{{ row.displayIndex }}</span>
              <span v-if="row.changeType" class="claim-pill">{{ row.changeType }}</span>
              <span v-if="row.fieldName" class="claim-pill muted">{{ row.fieldName }}</span>
              <span v-if="row.personLabel" class="claim-pill person">{{ row.personLabel }}</span>
            </div>
            <div class="claim-source-text">{{ row.claimText || 'No claim text supplied.' }}</div>
          </div>
          <div class="claim-source-ref">
            <div v-if="row.sourceRefLabel" class="claim-source-ref-line">
              <span class="claim-source-label">ref</span>
              <span class="claim-source-value">{{ row.sourceRefLabel }}</span>
            </div>
            <div class="claim-source-ref-line">
              <span class="claim-source-label">source</span>
              <a
                v-if="row.sourceHref"
                class="claim-source-value claim-source-link"
                :href="row.sourceHref"
                target="_blank"
                rel="noopener noreferrer"
                title="Open linked source"
              >
                {{ row.sourceLabel }}
              </a>
              <span v-else class="claim-source-value">
                {{ row.sourceLabel }}
              </span>
            </div>
            <div v-if="row.sourceAccessClass" class="claim-source-ref-line">
              <span class="claim-source-label">access</span>
              <span class="claim-source-value">{{ row.sourceAccessClass }}</span>
            </div>
            <div v-if="row.mediaRefs.length" class="claim-source-ref-line media-line">
              <span class="claim-source-label">media</span>
              <span class="claim-media-list">
                <template v-for="media in row.mediaRefs" :key="`claim-media-${row.key}-${media.id || media.title}`">
                  <a
                    v-if="mediaRefHref(media)"
                    :href="mediaRefHref(media)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="claim-media-pill"
                    :title="media.title || 'Open media item'"
                  >
                    {{ media.title || 'Media item' }}
                  </a>
                  <span
                    v-else
                    class="claim-media-pill disabled"
                    :title="media.title || 'Media item'"
                  >
                    {{ media.title || 'Media item' }}
                  </span>
                </template>
              </span>
            </div>
            <div v-if="row.mediaStatusLabel" class="claim-source-ref-line">
              <span class="claim-source-label">media status</span>
              <span class="claim-source-value">{{ row.mediaStatusLabel }}</span>
            </div>
          </div>
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
            <span v-if="claim.person_id">person reference present</span>
            <span v-if="claim.source_ref">source reference present</span>
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
        <div v-if="redactedIdentityRows.length" class="kv-grid">
          <div v-for="row in redactedIdentityRows" :key="row.key" class="kv-row">
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
        <div v-if="redactedPrivacyRows.length" class="kv-grid">
          <div v-for="row in redactedPrivacyRows" :key="row.key" class="kv-row">
            <span class="kv-key">{{ row.label }}</span>
            <span class="kv-value" :class="row.valueClass || ''">{{ row.value }}</span>
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
        <span class="section-status" :class="applyPreviewStatusClass">{{ previewStatusLabel }}</span>
      </div>
      <div class="preview-note">
        mutates_accepted_facts:
        <strong :class="applyPreviewMutates ? 'text-danger' : 'text-ok'">{{ String(applyPreviewMutates) }}</strong>
      </div>
      <div class="preview-note muted" :class="applyPreviewSafetyClass">
        {{ applyPreviewSafetyNote }}
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
            <span v-if="operationTargetLabel(operation)" class="operation-target">{{ operationTargetLabel(operation) }}</span>
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
                    <div class="locator-group-title">{{ locatorGroupTitle(groupIdx) }}</div>
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

            <div v-if="isDataQualityTodoOperation(operation)" class="remediation-grid">
              <div class="remediation-block">
                <div class="subheading">Data-quality research-task recommendation</div>
                <div class="kv-grid compact">
                  <div v-for="row in dataQualityTodoRows(operation)" :key="`todo-${row.key}`" class="kv-row">
                    <span class="kv-key">{{ row.label }}</span>
                    <span class="kv-value">{{ row.value }}</span>
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
                  <span class="kv-value">{{ staleHashLabel(operation.stale_hash) }}</span>
                </div>
              </div>
              <div v-if="touchedRows(operation.proposed_effect).length" class="touch-list">
                <div v-for="(row, touchIdx) in touchedRows(operation.proposed_effect)" :key="`touch-${touchIdx}`" class="touch-row">
                  {{ touchedRowLabel(row, touchIdx) }}
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
        <span v-if="decisionLogRows.length" class="section-count">{{ decisionLogRows.length }}</span>
      </div>
      <div v-if="decisionLogRows.length" class="decision-list">
        <div v-for="entry in decisionLogRows" :key="entry.key" class="decision-row">
          <div class="decision-head">
            <span class="decision-action">{{ entry.actionLabel }}</span>
            <span v-if="entry.reasonLabel" class="decision-actor">{{ entry.reasonLabel }}</span>
            <span v-if="entry.actorLabel" class="decision-actor">{{ entry.actorLabel }}</span>
            <span v-if="entry.timeLabel" class="decision-time">{{ entry.timeLabel }}</span>
          </div>
          <div v-if="entry.metaSummary" class="decision-note muted">{{ entry.metaSummary }}</div>
        </div>
      </div>
      <div v-else class="empty-line">No decision log entries yet.</div>
    </section>

    </details>

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
const pendingAlternateAction = ref('')
const activeFaceCandidateIndex = ref(0)
const targetRefCopied = ref(false)
const targetLinkCopied = ref(false)
const mediaThumbErrors = ref(new Set())

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
  { value: 'source_verified', label: 'Source verified' },
  { value: 'missing_source_locator', label: 'Missing source locator' },
  { value: 'locator_mismatch', label: 'Locator mismatch' },
  { value: 'source_needs_review', label: 'Source needs review' },
  { value: 'citation_incomplete', label: 'Citation incomplete' },
  { value: 'identity_unclear', label: 'Identity unclear' },
  { value: 'weak_evidence', label: 'Weak evidence' },
  { value: 'privacy_review_needed', label: 'Privacy review needed' },
  { value: 'duplicate_packet', label: 'Duplicate packet' },
  { value: 'other', label: 'Other' },
]

const DECISION_ACTION_LABELS = {
  queued: 'Queued',
  packet_reviewed_preview_only: 'Packet reviewed preview-only',
  packet_rejected: 'Packet rejected',
  packet_clarification_requested: 'Clarification requested',
  packet_deferred: 'Packet deferred',
  reviewed_preview_only: 'Reviewed preview-only',
  rejected: 'Rejected',
  clarification_requested: 'Clarification requested',
  deferred: 'Deferred',
  unknown: 'Unknown action',
}

const DECISION_REASON_LABELS = Object.fromEntries(reasonCodeOptions.map((option) => [option.value, option.label]))

const DECISION_OUTCOME_LABELS = {
  terminal: 'Terminal',
  follow_up: 'Follow-up',
  touched: 'Touched',
  pending: 'Pending',
  unknown: 'Unknown',
}

const RAW_SOURCE_PAYLOAD_KEYS = new Set([
  'id',
  'source_id',
  'media_id',
  'person_id',
  'family_id',
  'tree_id',
  'locator',
  'source_locator',
  'url',
  'uri',
  'path',
  'nextcloud_path',
  'citation',
  'uid',
  'uuid',
  'token',
  'hash',
  'gedcom_id',
  'proposed_change_id',
])

const RAW_PREVIEW_PAYLOAD_KEYS = new Set([
  ...RAW_SOURCE_PAYLOAD_KEYS,
  'command',
  'selector',
  'query',
  'payload',
  'raw',
  'details',
])

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
const claimContexts = computed(() => arrayValue(props.context?.claim_contexts).filter(isPlainObject))
const reviewFocus = computed(() => objectValue(props.context?.review_focus))
const packetOutcome = computed(() => objectValue(props.context?.packet_outcome))
const reviewProof = computed(() => objectValue(props.context?.review_proof))
const reviewPass = computed(() => objectValue(props.context?.review_pass))
const workTrail = computed(() => objectValue(props.context?.work_trail))
const packetChecklist = computed(() => objectValue(props.context?.review_checklist))
const evidenceLens = computed(() => objectValue(props.context?.evidence_lens))
const remediationOrigin = computed(() => objectValue(reviewFocus.value.remediation_origin))
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

const packetOutcomeLabel = computed(() => {
  return stringOrNull(packetOutcome.value.progress_label)
    || stringOrNull(packetOutcome.value.outcome_label)
    || 'Outcome state unknown'
})

const packetOutcomePreviewOnly = computed(() => packetOutcome.value.preview_only === true)

const packetOutcomeRows = computed(() => [
  outcomeRow('outcome', 'Outcome', packetOutcome.value.outcome_label),
  outcomeRow('decision_count', 'Decision log entries', packetOutcome.value.decision_count),
  outcomeRow('latest_action', 'Latest action', packetOutcome.value.latest_action_label),
  outcomeRow('reason', 'Reason', packetOutcome.value.latest_reason_label),
  outcomeRow('actor', 'Actor', packetOutcome.value.latest_actor),
  outcomeRow('time', 'Time', packetOutcome.value.latest_at ? formatDate(packetOutcome.value.latest_at) : null),
].filter(Boolean))

const decisionLogRows = computed(() => decisionLog.value
  .map((entry, idx) => decisionLogDisplayRow(entry, idx))
  .filter(Boolean))

const reviewPassRows = computed(() => {
  const selfCheck = objectValue(reviewPass.value.self_check)
  const counts = objectValue(reviewPass.value.counts)
  const signals = objectValue(reviewPass.value.signals)
  const posture = objectValue(reviewPass.value.posture)
  const blockerCodes = arrayValue(reviewPass.value.blocker_codes)
    .map((code) => stringOrNull(code))
    .filter(Boolean)

  return [
    reviewPassRow('state', 'State', reviewPass.value.label || reviewPass.value.state, reviewPassState(reviewPass.value.state)),
    reviewPassRow('self_check_score', 'Self-check', selfCheckScoreLabel(selfCheck), selfCheckState(selfCheck)),
    reviewPassRow('self_check_counts', 'Self-check counts', selfCheckCountLabel(selfCheck), selfCheckState(selfCheck)),
    reviewPassRow('self_check_signals', 'Self-check signals', selfCheckSignalLabel(selfCheck), Number(selfCheck.signal_watch_count || 0) > 0 ? 'warning' : 'ok'),
    reviewPassRow('reason', 'Reason', reviewPass.value.reason_code ? labelize(reviewPass.value.reason_code) : null, 'warning'),
    reviewPassRow('blockers', 'Blockers', reviewPass.value.blocker_count, Number(reviewPass.value.blocker_count || 0) > 0 ? 'blocked' : 'ok'),
    reviewPassRow('blocker_codes', 'Blocker codes', blockerCodes.map(labelize).join(', '), blockerCodes.length > 0 ? 'blocked' : 'ok'),
    reviewPassRow('claims', 'Claims', counts.claim_count, 'ok'),
    reviewPassRow('sources', 'Sources', counts.source_count, 'ok'),
    reviewPassRow('claim_source_coverage', 'Claim/source coverage', claimSourceCoverageLabel(counts), signals.all_claims_source_linked === true ? 'ok' : 'warning'),
    reviewPassRow('media', 'Media', mediaReviewPassLabel(counts), (Number(counts.missing_media_count || 0) > 0) ? 'warning' : 'ok'),
    reviewPassRow('checklist', 'Checklist rows', counts.checklist_row_count, 'ok'),
    reviewPassRow('source_backed', 'Source-backed', displayBoolean(signals.source_backed), signals.source_backed === true ? 'ok' : 'blocked'),
    reviewPassRow('all_claims_source_linked', 'All claims source-linked', displayBoolean(signals.all_claims_source_linked), signals.all_claims_source_linked === true ? 'ok' : 'warning'),
    reviewPassRow('boundary', 'Boundary', displayBoolean(signals.boundary_present), signals.boundary_present === true ? 'ok' : 'warning'),
    reviewPassRow('identity', 'Identity', displayBoolean(signals.identity_present), signals.identity_present === true ? 'ok' : 'warning'),
    reviewPassRow('privacy', 'Privacy', displayBoolean(signals.privacy_cleared), signals.privacy_cleared === true ? 'ok' : 'warning'),
    reviewPassRow('validation', 'Validation', signals.validation_state, signals.validation_state === 'valid' ? 'ok' : 'warning'),
    reviewPassRow('conflict_signal', 'Conflict signal', displayBoolean(signals.conflict_signal), signals.conflict_signal === true ? 'warning' : 'ok'),
    reviewPassRow('negative_evidence', 'Negative evidence', displayBoolean(signals.negative_evidence), signals.negative_evidence === true ? 'warning' : 'ok'),
    reviewPassRow('locator_present', 'Locator present', displayBoolean(signals.locator_present), signals.locator_present === true ? 'ok' : 'warning'),
    reviewPassRow('extract_present', 'Extract present', displayBoolean(signals.extract_present), signals.extract_present === true ? 'ok' : 'warning'),
    reviewPassRow('preview_only', 'Preview only', displayBoolean(signals.preview_only), signals.preview_only === true ? 'ok' : 'blocked'),
    reviewPassRow('canonical_mutation', 'Canonical mutation', displayBoolean(signals.canonical_mutation), signals.canonical_mutation === true ? 'blocked' : 'ok'),
    reviewPassRow('canonical_write', 'Canonical write', displayBoolean(posture.canonical_write_allowed), posture.canonical_write_allowed === true ? 'blocked' : 'ok'),
    reviewPassRow('batch_review', 'Batch review', displayBoolean(posture.batch_review_allowed), posture.batch_review_allowed === true ? 'blocked' : 'ok'),
    reviewPassRow('automation', 'Automation', displayBoolean(posture.automation_allowed), posture.automation_allowed === true ? 'blocked' : 'ok'),
    reviewPassRow('details', 'Details included', displayBoolean(posture.details_included), posture.details_included === true ? 'blocked' : 'ok'),
    reviewPassRow('raw_identifiers', 'Raw identifiers', displayBoolean(posture.raw_identifiers_included), posture.raw_identifiers_included === true ? 'blocked' : 'ok'),
    reviewPassRow('tokens', 'Tokens included', displayBoolean(posture.tokens_included), posture.tokens_included === true ? 'blocked' : 'ok'),
    reviewPassRow('locators', 'Locators included', displayBoolean(posture.locators_included), posture.locators_included === true ? 'blocked' : 'ok'),
  ].filter(Boolean)
})

const workTrailRows = computed(() => arrayValue(workTrail.value.rows)
  .filter(isPlainObject)
  .map((row) => ({
    key: stringOrNull(row.key) || compactJson(row),
    label: stringOrNull(row.label) || labelize(row.key || 'work trail'),
    value: displayValue(row.value),
    state: stringOrNull(row.state) || 'warning',
  })))

const workTrailSummaryRows = computed(() => {
  const summary = objectValue(workTrail.value.summary)

  return [
    workTrailSummaryRow('mode', 'Mode', workTrail.value.mode),
    workTrailSummaryRow('sources', 'Sources', summary.source_count),
    workTrailSummaryRow('claims', 'Claims', summary.claim_count),
    workTrailSummaryRow('media', 'Media', workTrailMediaSummary(summary)),
    workTrailSummaryRow('validation', 'Validation', summary.validation_state),
    workTrailSummaryRow('preview', 'Preview', workTrailPreviewSummary(summary)),
    workTrailSummaryRow('outcome', 'Outcome', summary.outcome_state),
  ].filter(Boolean)
})

const packetChecklistRows = computed(() => arrayValue(packetChecklist.value.rows)
  .filter(isPlainObject)
  .map((row) => ({
    key: stringOrNull(row.key) || compactJson(row),
    label: stringOrNull(row.label) || labelize(row.key || 'check'),
    value: displayValue(row.value),
    state: stringOrNull(row.state) || 'warning',
  })))

const reviewProofRows = computed(() => arrayValue(reviewProof.value.rows)
  .filter(isPlainObject)
  .map((row) => ({
    key: stringOrNull(row.key) || compactJson(row),
    label: stringOrNull(row.label) || labelize(row.key || 'proof'),
    value: displayValue(row.value),
    state: stringOrNull(row.state) || 'warning',
  })))

const evidenceLensRows = computed(() => arrayValue(evidenceLens.value.rows)
  .filter(isPlainObject)
  .map((row) => ({
    key: stringOrNull(row.key) || compactJson(row),
    label: stringOrNull(row.label) || labelize(row.key || 'evidence'),
    value: displayValue(row.value),
    state: stringOrNull(row.state) || 'warning',
  })))

const outcomeStateClass = computed(() => {
  const state = stringOrNull(packetOutcome.value.outcome_state)
  if (state === 'terminal') return 'status-ok'
  if (state === 'follow_up' || state === 'touched') return 'status-warning'
  return 'status-pending'
})

const inspectionOnly = computed(() => item.value.ui_schema?.inspection_only === true
  || reviewFocus.value.operator_review_surface === 'preview'
  || (reviewFocus.value.preview_only === true && reviewFocus.value.canonical_mutation === false))
const canAct = computed(() => !inspectionOnly.value && String(item.value.status || '').toLowerCase() === 'pending')
const auditHold = computed(() => objectValue(packet.value.metadata?.audit_hold))
const isPhotoAuditHold = computed(() => Object.keys(auditHold.value).length > 0)
const auditHoldReason = computed(() => {
  const reasons = arrayValue(auditHold.value.reason_codes).filter((reason) => typeof reason === 'string')
  return stringOrNull(auditHold.value.reason)
    || stringOrNull(auditHold.value.hold_reason)
    || (reasons.length ? reasons.map((reason) => reason.replaceAll('_', ' ')).join('; ') : null)
    || stringOrNull(auditHold.value.disposition)?.replaceAll('_', ' ')
    || displaySummary.value
    || 'The available evidence does not settle this photo association.'
})
const auditHoldHasRegion = computed(() => {
  const region = objectValue(auditHold.value.region, auditHold.value.current_link_region)
  return ['x', 'y', 'w', 'h'].every((axis) => region[axis] !== null && region[axis] !== undefined)
})
const auditHoldMedia = computed(() => {
  const mediaId = Number(auditHold.value.media_id ?? auditHold.value.media?.id ?? packet.value.media_id)
  return mediaRefs.value.filter((media) => Number(media.id) === mediaId)
})
const isFacePersonSourcePacket = computed(() => {
  if (isPhotoAuditHold.value) return false
  return stringOrNull(item.value.finding_type) === 'face_person_source_repair_plan'
    || stringOrNull(details.value.finding_type) === 'face_person_source_repair_plan'
    || stringOrNull(packet.value.finding_type) === 'face_person_source_repair_plan'
})
const faceRepairPlans = computed(() => {
  const metadata = objectValue(packet.value.metadata)
  return arrayValue(metadata.plans).filter(isPlainObject)
})
const faceRepairCandidates = computed(() => faceRepairPlans.value.map((plan, index) => {
  const evidence = objectValue(plan.evidence)
  const selector = objectValue(plan.selector)
  const linkedPerson = stringOrNull(evidence.linked_canonical_name) || stringOrNull(evidence.person_name)
  const comparedPerson = stringOrNull(evidence.matched_export_name) || stringOrNull(evidence.matched_variant_name)
  const mediaIds = arrayValue(selector.media_ids).map((id) => Number(id)).filter(Number.isFinite)
  const media = mediaRefs.value.find((candidate) => mediaIds.includes(Number(candidate.id))) || null
  const ready = Boolean(linkedPerson && comparedPerson && linkedPerson.toLowerCase() !== comparedPerson.toLowerCase() && media)
  let explanation = 'Both names and the candidate photo are present. Identity approval still belongs in Picture Match Approval.'
  if (!comparedPerson) {
    explanation = 'PLOS did not save the different exported person name, so there is no comparison you can judge. This row must be regenerated.'
  } else if (!linkedPerson) {
    explanation = 'PLOS did not save the currently linked person, so there is no comparison you can judge. This row must be regenerated.'
  } else if (!media) {
    explanation = 'The candidate-specific photo is not attached to this row. This row must be regenerated before review.'
  } else if (linkedPerson.toLowerCase() === comparedPerson.toLowerCase()) {
    explanation = 'Both sides name the same person, so the claimed conflict is not explained. This row must be regenerated.'
  }

  return {
    key: stringOrNull(plan.plan_key) || `face-candidate-${index}`,
    filename: stringOrNull(evidence.filename) || stringOrNull(evidence.media_title) || `Photo candidate ${index + 1}`,
    linkedPerson,
    comparedPerson,
    media,
    ready,
    explanation,
  }
}))
const activeFaceCandidate = computed(() => faceRepairCandidates.value[activeFaceCandidateIndex.value] || null)
const faceCandidatePacketReady = computed(() => {
  const serverReadiness = objectValue(reviewFocus.value.candidate_reviewability)
  if (typeof serverReadiness.ready === 'boolean') return serverReadiness.ready
  return faceRepairCandidates.value.length > 0 && faceRepairCandidates.value.every((candidate) => candidate.ready)
})
const faceReviewLead = computed(() => {
  if (faceCandidatePacketReady.value) {
    return 'PLOS found a possible disagreement between a photo link and an exported name.'
  }

  const serverReadiness = objectValue(reviewFocus.value.candidate_reviewability)
  const missing = numberOrNull(serverReadiness.missing_comparison_count)
  if (missing !== null && missing > 0) {
    return `PLOS did not retain both names for ${missing} of ${faceRepairCandidates.value.length} photo candidates, so there is nothing reliable for you to decide.`
  }

  return 'This diagnostic is incomplete, so there is nothing reliable for you to decide.'
})
const pictureMatchApprovalHref = computed(() => {
  if (typeof window === 'undefined') return '/knowledge?source=review&picture_review=matches'
  const url = new URL(window.location.pathname, window.location.origin)
  url.searchParams.set('source', 'review')
  url.searchParams.set('picture_review', 'matches')
  return `${url.pathname}${url.search}`
})
const decisionScopeTitle = computed(() => isFacePersonSourcePacket.value
  ? 'This does not approve a picture identity'
  : 'This does not apply genealogy changes')
const decisionScopeDescription = computed(() => isFacePersonSourcePacket.value
  ? 'Confirming records that you reviewed this evidence packet. It does not identify a person, link a face, approve a photo match, or write Family Tree data.'
  : 'Confirming records the packet review outcome only. Candidate facts, links, relationships, sources, and media remain unchanged.')
const operatorQuestion = computed(() => isFacePersonSourcePacket.value
  ? (faceCandidatePacketReady.value
      ? 'After inspecting every candidate, is this diagnostic packet complete?'
      : 'This packet cannot be closed as reviewed until PLOS supplies the missing comparison evidence.')
  : 'Does this source or evidence belong with the target person?')
const markReviewedLabel = computed(() => isFacePersonSourcePacket.value
  ? 'Close packet as reviewed'
  : 'Confirm packet reviewed')
const markReviewedEffect = computed(() => isFacePersonSourcePacket.value
  ? 'Closing records only that this provenance packet was reviewed. Picture identity remains undecided and must be handled in Match approval.'
  : 'Confirming closes this review packet as reviewed. It does not apply candidate lines or change accepted genealogy data.')
const operatorVetable = computed(() => reviewFocus.value.operator_vetable !== false)
const operatorBlockerLabel = computed(() => stringOrNull(reviewFocus.value.operator_blocker_label))
const canMarkReviewed = computed(() => canAct.value && reviewFocusApprovalReady.value && operatorVetable.value)
const selectedDecisionReasonCode = computed(() => {
  const code = safeDecisionCode(decisionReasonCode.value)
  return code && DECISION_REASON_LABELS[code] ? code : null
})
const canConfirmAlternateOutcome = computed(() => canAct.value
  && ['reject', 'clarify', 'defer'].includes(pendingAlternateAction.value)
  && selectedDecisionReasonCode.value !== null)
const pendingAlternateActionLabel = computed(() => ({
  reject: 'Reject this packet',
  clarify: 'Request clarification',
  defer: 'Defer this packet',
})[pendingAlternateAction.value] || 'Alternate outcome')
const pendingAlternateActionConfirmLabel = computed(() => ({
  reject: 'Confirm rejection',
  clarify: 'Confirm clarification request',
  defer: 'Confirm deferral',
})[pendingAlternateAction.value] || 'Confirm outcome')

const markReviewedDisabledTitle = computed(() => {
  if (!canAct.value) return 'Packet is not pending.'
  if (!operatorVetable.value) return operatorBlockerLabel.value || 'No visible evidence is attached.'
  const blockerLabels = approvalBlockerLabels()
  if (blockerLabels.length) return `Approval blockers: ${blockerLabels.slice(0, 3).join(', ')}.`
  if (validationMissing.value) return 'Review packet validation is missing.'
  if (validationErrors.value.length) return 'Resolve validation errors before marking reviewed.'
  if (reviewFocusApprovalReady.value === false) return 'Preview metadata is not approval-ready.'
  return ''
})

const reviewFocusApprovalReady = computed(() => {
  if (reviewFocus.value.approval_ready === false) return false

  return validation.value.valid === true
    && validationErrors.value.length === 0
    && applyPreviewIsPreviewOnly.value
    && reviewFocusCanonicalMutation.value !== true
})

const approvalBlockers = computed(() => {
  if (!operatorVetable.value) {
    return [{
      code: stringOrNull(reviewFocus.value.operator_blocker_code) || 'no_visible_evidence',
      label: operatorBlockerLabel.value || 'No visible evidence is attached',
    }]
  }

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
  if (reviewFocusCanonicalMutation.value === true) return [{ code: 'canonical_mutation_possible', label: 'Canonical mutation possible' }]
  return [{ code: 'approval_ready_unknown', label: 'Approval readiness unknown' }]
})

const reviewFocusRows = computed(() => [
  focusRow('target', 'Target ref', targetRefLabel.value, 'mono', targetRef.value),
  focusRow('person', 'Person', reviewFocusPersonLabel.value),
  focusRow('status', 'Packet status', packetStatusLabel.value),
  focusRow('operator', 'Review action', reviewFocus.value.operator_action_label, operatorVetable.value ? null : 'danger'),
  focusRow('boundary', 'Boundary', reviewFocusBoundaryLabel.value, null, reviewFocusBoundaryLabel.value),
  focusRow('access', 'Access', formatPacketStatus(reviewFocus.value.source_access_class)),
  focusRow('sources', 'Sources', formatCount(reviewFocus.value.source_count ?? sourceLocators.value.length)),
  focusRow('media', 'Media', reviewFocusMediaLabel.value),
  focusRow('claims', 'Claims', formatCount(reviewFocus.value.claim_count ?? claims.value.length)),
  focusRow('preview', 'Preview', previewStatusLabel.value, previewStatusClass.value),
].filter(Boolean))

const remediationOriginOperationTypes = computed(() => arrayValue(remediationOrigin.value.operation_types)
  .map((value) => stringOrNull(value))
  .filter(Boolean))

const remediationOriginRows = computed(() => [
  originRow('source', 'Source', remediationOrigin.value.source),
  originRow('source_review_type', 'Review type', remediationOrigin.value.source_review_type),
  originRow('finding_type', 'Finding type', remediationOrigin.value.finding_type),
  originRow('source_status', 'Source status', remediationOrigin.value.source_status),
  originRow('target_review_type', 'Packet type', remediationOrigin.value.target_review_type),
  originRow('apply_enabled', 'Apply enabled', remediationOrigin.value.apply_enabled),
  originRow('writeback', 'Writeback', remediationOrigin.value.writeback),
  originRow('execute_effect', 'Effect', remediationOrigin.value.execute_effect),
].filter(Boolean))

const hasRemediationOrigin = computed(() => remediationOriginRows.value.length > 0 || remediationOriginOperationTypes.value.length > 0)

const reviewFocusPersonLabel = computed(() => {
  const label = stringOrNull(reviewFocus.value.person_label)
  if (label) return label

  if (personSnapshot.value) {
    const name = [personSnapshot.value.given_name, personSnapshot.value.surname].filter(Boolean).join(' ').trim()
    if (name) return name
  }

  const personId = reviewFocus.value.person_id ?? identity.value.person_id ?? claims.value.find((claim) => claim?.person_id)?.person_id
  return formatPersonId(personId)
})

const targetRef = computed(() => {
  return stringOrNull(props.context?.target_ref)
    || stringOrNull(item.value.target_ref)
    || stringOrNull(reviewFocus.value.target_ref)
})

const targetRefLabel = computed(() => {
  return targetRef.value ? targetRef.value.replace(/^genealogy_review_packet:/, '') : null
})

const targetRefShareUrl = computed(() => {
  if (!targetRef.value || typeof window === 'undefined') return ''
  const url = new URL(window.location.pathname, window.location.origin)
  url.searchParams.set('target_ref', targetRef.value)
  return url.toString()
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

const reviewFocusSourceLabel = computed(() => {
  return reviewFocusSource.value ? sourcePointerLabel(reviewFocusSource.value, 0) : null
})

const reviewFocusMediaLabel = computed(() => {
  const total = numberOrNull(reviewFocus.value.media_ref_count)
  const resolved = numberOrNull(reviewFocus.value.resolved_media_count)
  const missing = numberOrNull(reviewFocus.value.missing_media_count)

  if (total === null && resolved === null && missing === null) {
    return null
  }

  const count = total ?? resolved ?? 0
  return missing && missing > 0
    ? `${count} refs, ${missing} missing`
    : `${count} refs`
})

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

const applyPreviewStatusClass = computed(() => {
  if (previewStatusClass.value === 'danger') return 'status-danger'
  if (previewStatusClass.value === 'ok') return 'status-ok'
  return 'status-warning'
})

const applyPreviewSafetyClass = computed(() => {
  return applyPreviewStatusClass.value === 'status-danger' ? 'text-danger' : ''
})

const applyPreviewSafetyNote = computed(() => {
  if (reviewFocusCanonicalMutation.value === true) {
    return 'Preview metadata indicates a possible canonical genealogy mutation; Mark reviewed stays blocked.'
  }

  if (!applyPreviewIsPreviewOnly.value) {
    return 'Preview metadata is not approval-ready; Mark reviewed stays blocked until preview-only status is proven.'
  }

  return 'Accepted facts are not changed by this packet preview.'
})

const packetTitle = computed(() => {
  return packet.value.packet_label
    || packet.value.title
    || details.value.packet_label
    || details.value.packet_key
    || packet.value.packet_key
    || null
})
const displayTitle = computed(() => isFacePersonSourcePacket.value
  ? 'Photo diagnostic'
  : item.value.title || packetTitle.value || 'Genealogy review packet')
const displaySummary = computed(() => {
  if (!isFacePersonSourcePacket.value) return stringOrNull(item.value.summary)
  const count = faceRepairCandidates.value.length
  return faceCandidatePacketReady.value
    ? `PLOS found ${count} photo ${count === 1 ? 'comparison' : 'comparisons'} to inspect in Picture Match Approval.`
    : 'This is a maintenance diagnostic, not a person or picture approval.'
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

const redactedIdentityRows = computed(() => redactedIdentityProjection(identity.value))
const redactedPrivacyRows = computed(() => redactedPrivacyProjection(privacy.value))
const validationErrors = computed(() => arrayValue(validation.value.errors))
const validationWarnings = computed(() => arrayValue(validation.value.warnings))
const validationMissing = computed(() => !validation.value || Object.keys(validation.value).length === 0)

const claimEvidenceRows = computed(() => {
  if (claimContexts.value.length) {
    return claimContexts.value
      .map((context, idx) => claimEvidenceRowFromContext(context, idx))
      .filter(Boolean)
  }

  return claims.value
    .filter(isPlainObject)
    .map((claim, idx) => claimEvidenceRow(claim, idx))
    .filter(Boolean)
})

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

const decisionReadinessLabel = computed(() => {
  if (canMarkReviewed.value) return 'Ready to record review'
  if (!operatorVetable.value) return 'Visible evidence required'
  if (approvalBlockers.value.length) return 'Needs attention'
  return 'Review unavailable'
})

const decisionReadinessClass = computed(() => canMarkReviewed.value ? 'ready' : 'blocked')

const decisionReadinessFacts = computed(() => {
  const claimCount = numberOrNull(reviewFocus.value.claim_count) ?? claims.value.length
  const mediaCount = numberOrNull(reviewFocus.value.media_ref_count) ?? 0
  const visibleMediaCount = numberOrNull(reviewFocus.value.visible_evidence_count)
    ?? numberOrNull(reviewFocus.value.resolved_media_count)
    ?? 0
  const missingMediaCount = numberOrNull(reviewFocus.value.missing_media_count) ?? 0

  return [
    { key: 'candidates', label: 'Candidates', value: formatCount(claimCount) },
    { key: 'visible_media', label: 'Visible media', value: `${formatCount(visibleMediaCount)} / ${formatCount(mediaCount)}` },
    { key: 'missing_media', label: 'Missing media', value: formatCount(missingMediaCount) },
    { key: 'validation', label: 'Validation', value: validationStatus.value },
    { key: 'writes', label: 'Accepted-data writes', value: reviewFocusCanonicalMutation.value === false ? 'none' : canonicalMutationLabel.value },
  ]
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
watch(() => props.context?.item?.unified_id, () => {
  activeFaceCandidateIndex.value = 0
  mediaThumbErrors.value = new Set()
})
watch(() => faceRepairCandidates.value.length, (length) => {
  if (activeFaceCandidateIndex.value >= length) {
    activeFaceCandidateIndex.value = Math.max(0, length - 1)
  }
})
watch(() => props.decisionResetToken, () => resetDecisionInputs())
watch(targetRef, () => {
  targetRefCopied.value = false
  targetLinkCopied.value = false
})

const applyPreviewSummaryRows = computed(() => {
  const rows = []
  for (const key of ['status', 'operation_count']) {
    if (applyPreview.value[key] !== undefined) {
      rows.push(toPreviewKvRow(key, applyPreview.value[key]))
    }
  }
  const summary = objectValue(applyPreview.value.summary)
  for (const row of previewKvRows(summary)) {
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

function originRow(key, label, value) {
  if (value === null || value === undefined || value === '') return null
  return { key, label, raw: value, value: displayValue(value) }
}

function outcomeRow(key, label, value) {
  if (value === null || value === undefined || value === '') return null
  return { key, label, raw: value, value: displayValue(value) }
}

function workTrailSummaryRow(key, label, value) {
  if (value === null || value === undefined || value === '') return null
  return { key, label, value: displayValue(value) }
}

function workTrailMediaSummary(summary) {
  const resolved = numberOrNull(summary.resolved_media_count)
  const missing = numberOrNull(summary.missing_media_count)
  if (resolved === null && missing === null) return null
  return `${resolved ?? 0} resolved, ${missing ?? 0} missing`
}

function workTrailPreviewSummary(summary) {
  if (summary.apply_preview_persisted === true) return 'persisted'
  if (summary.apply_preview_generated === true) return 'generated'
  return 'missing'
}

function reviewPassRow(key, label, value, state = 'warning') {
  if (value === null || value === undefined || value === '') return null
  return {
    key,
    label,
    raw: value,
    value: displayValue(value),
    state,
  }
}

function reviewPassState(state) {
  const value = stringOrNull(state)
  if (value === 'ready') return 'ok'
  if (value === 'blocked') return 'blocked'
  if (value === 'empty') return 'missing'
  return 'warning'
}

function mediaReviewPassLabel(counts) {
  const resolved = numberOrNull(counts.resolved_media_count)
  const missing = numberOrNull(counts.missing_media_count)
  if (resolved === null && missing === null) return null
  return `${resolved ?? 0} resolved, ${missing ?? 0} missing`
}

function claimSourceCoverageLabel(counts) {
  const claimCount = numberOrNull(counts.claim_count)
  const linkedCount = numberOrNull(counts.claims_with_source_context)
  if (claimCount === null && linkedCount === null) return null
  return `${linkedCount ?? 0}/${claimCount ?? 0}`
}

function selfCheckScoreLabel(selfCheck) {
  const score = numberOrNull(selfCheck.score_percent)
  const totalRows = numberOrNull(selfCheck.total_rows)
  if (score === null && totalRows === null) return null
  return score === null ? `${totalRows ?? 0} rows` : `${score}%`
}

function selfCheckCountLabel(selfCheck) {
  const totalRows = numberOrNull(selfCheck.total_rows)
  if (totalRows === null) return null
  return `${selfCheck.ok_count ?? 0} ok, ${selfCheck.warning_count ?? 0} warn, ${selfCheck.blocked_count ?? 0} block, ${selfCheck.missing_count ?? 0} missing`
}

function selfCheckSignalLabel(selfCheck) {
  const total = numberOrNull(selfCheck.signal_total)
  if (total === null) return null
  return `${selfCheck.signal_ok_count ?? 0}/${total} signals`
}

function selfCheckState(selfCheck) {
  const status = stringOrNull(selfCheck.status)
  if (status === 'ok') return 'ok'
  if (status === 'blocked') return 'blocked'
  if (status === 'missing') return 'missing'
  return 'warning'
}

function checklistStateClass(state) {
  const value = String(state || '').toLowerCase()
  if (value === 'ok') return 'check-ok'
  if (value === 'blocked') return 'check-blocked'
  if (value === 'missing') return 'check-missing'
  return 'check-warning'
}

function checklistStateLabel(state) {
  const value = String(state || '').toLowerCase()
  if (value === 'ok') return 'ok'
  if (value === 'blocked') return 'block'
  if (value === 'missing') return 'wait'
  return 'check'
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

function previewKvRows(value) {
  if (!isPlainObject(value)) return []
  return Object.entries(value)
    .filter(([_, val]) => val !== undefined)
    .map(([key, val]) => toPreviewKvRow(key, val))
    .filter((row) => !isRawPreviewPayloadKey(row.key))
}

function toPreviewKvRow(key, value) {
  return {
    key,
    label: labelize(key),
    raw: value,
    value: displayPreviewValue(value),
  }
}

function redactedIdentityProjection(value) {
  if (!isPlainObject(value) || Object.keys(value).length === 0) return []

  const rows = [
    projectionRow('identity_present', 'Identity present', 'yes', 'text-ok'),
    projectionRow(
      'person_reference',
      'Person reference',
      hasAnyReferenceValue(value, ['person_id', 'person_ref', 'person_reference', 'target_person_ref']) ? 'present' : 'not supplied',
    ),
  ]

  const contextCount = projectedContextCount(value, ['target_context', 'target_contexts', 'person_context', 'person_contexts'])
  if (contextCount > 0) {
    rows.push(projectionRow('target_context_count', 'Target context count', String(contextCount)))
  }

  return rows
}

function redactedPrivacyProjection(value) {
  if (!isPlainObject(value) || Object.keys(value).length === 0) return []

  const cleared = value.cleared
  const livingKnown = typeof value.living_person_risk === 'boolean'

  return [
    projectionRow('privacy_present', 'Privacy present', 'yes', 'text-ok'),
    projectionRow(
      'privacy_cleared',
      'Privacy cleared',
      displayBoolean(cleared) || 'unknown',
      cleared === true ? 'text-ok' : cleared === false ? 'text-muted' : '',
    ),
    projectionRow(
      'living_status_known',
      'Living status known',
      livingKnown ? 'yes' : 'no',
      livingKnown ? 'text-ok' : 'text-muted',
    ),
  ]
}

function projectionRow(key, label, value, valueClass = '') {
  return { key, label, value, valueClass }
}

function hasAnyReferenceValue(source, keys) {
  return keys.some((key) => {
    const value = source?.[key]
    const numeric = numberOrNull(value)
    if (numeric !== null) return numeric > 0
    return stringOrNull(value) !== null
  })
}

function projectedContextCount(source, keys) {
  for (const key of keys) {
    const value = source?.[key]
    if (Array.isArray(value)) return value.length
    if (isPlainObject(value)) return Object.keys(value).length
  }
  return 0
}

function labelize(key) {
  return String(key).replace(/_/g, ' ')
}

function stringOrNull(value) {
  if (value === null || value === undefined) return null
  const text = String(value).trim()
  return text !== '' ? text : null
}

function formatCount(value) {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? String(numeric) : displayValue(value)
}

function numberOrNull(value) {
  if (value === null || value === undefined || value === '') return null
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

function formatPersonId(value) {
  const text = stringOrNull(value)
  return text ? 'person reference present' : null
}

function formatPacketStatus(value) {
  const text = stringOrNull(value)
  return text ? labelize(text) : null
}

function displayValue(value) {
  if (value === null || value === undefined || value === '') return '-'
  if (value === true) return 'true'
  if (value === false) return 'false'
  if (Array.isArray(value) || isPlainObject(value)) return structuredDisplayLabel(value)
  return redactDisplayText(String(value))
}

function displayPreviewValue(value) {
  if (Array.isArray(value) || isPlainObject(value)) return structuredPreviewLabel(value)
  if (typeof value === 'string' && isSensitivePreviewValue(value)) return 'value withheld'
  return displayValue(value)
}

function structuredDisplayLabel(value) {
  if (Array.isArray(value)) {
    return value.length ? `details available (${value.length} items)` : 'no details'
  }

  const safeFieldCount = Object.keys(value).filter((key) => !isRawPreviewPayloadKey(key)).length
  return safeFieldCount ? `details available (${safeFieldCount} fields)` : 'details available'
}

function structuredPreviewLabel(value) {
  if (Array.isArray(value)) {
    return value.length ? `details available (${value.length} items)` : 'no details'
  }

  return Object.keys(value).length ? 'details available' : 'no details'
}

function redactDisplayText(value) {
  return String(value)
    .replace(/\bBearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [redacted]')
    .replace(/\b(?:api[_-]?(?:key|token)|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|token|secret|password|authorization)\s*[:=]\s*[^\s,;\]}]+/gi, '[redacted secret]')
    .replace(/([A-Za-z][A-Za-z0-9+.-]*:\/\/)([^:@/\s]+):([^@/\s]+)@/gi, '$1[redacted]@')
    .replace(/\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=-]{8,}\b/gi, '[redacted token]')
    .replace(/\/(?:home|Users|root)\/[^\s,"')\]}]+/g, '[redacted path]')
}

function isSensitivePreviewValue(value) {
  const text = value.trim()
  if (text === '') return false
  return /^https?:\/\//i.test(text)
    || /^[a-z]+:\/\//i.test(text)
    || /(^|[/?&])token=/i.test(text)
    || /^bearer\s+/i.test(text)
    || text.includes('/home/')
    || text.includes('/storage/')
    || text.includes('/private/')
}

function displayBoolean(value) {
  if (value === true) return 'yes'
  if (value === false) return 'no'
  return null
}

function compactJson(value) {
  try {
    return JSON.stringify(value)
  } catch (e) {
    return String(value)
  }
}

function locatorHref(locator) {
  return /^https?:\/\//i.test(locator) ? locator : null
}

function sourcePointerLabel(locator, idx) {
  return `Source ${idx + 1}`
}

function sourcePointerKind(locator) {
  const text = stringOrNull(locator)
  if (!text) return 'source reference'
  if (locatorHref(text)) return 'external link'
  if (/[\\/]/.test(text)) return 'private locator'
  return 'source reference'
}

function mediaRefHref(media) {
  if (!media || media.file_available === false) return null
  if (media.file_available !== true && media.file_exists === false) return null
  return typeof media.view_url === 'string' && media.view_url.trim() !== '' ? media.view_url : null
}

function mediaRefKey(media, idx) {
  return `${media?.id || media?.view_url || media?.title || 'media'}-${idx}`
}

function mediaRefThumbnail(media) {
  if (!media || media.file_available === false) return null
  if (media.file_available !== true && media.file_exists === false) return null
  const url = typeof media.thumbnail_url === 'string' && media.thumbnail_url.trim() !== ''
    ? media.thumbnail_url.trim()
    : null
  if (!url) return null
  const key = mediaRefKey(media, mediaRefs.value.indexOf(media))
  return mediaThumbErrors.value.has(key) ? null : url
}

function markMediaThumbError(key) {
  const next = new Set(mediaThumbErrors.value)
  next.add(key)
  mediaThumbErrors.value = next
}

function markFaceCandidateThumbError(candidate) {
  const media = candidate?.media
  markMediaThumbError(mediaRefKey(media, mediaRefs.value.indexOf(media)))
}

function mediaDisplayLabel(media, idx) {
  return `Media ${idx + 1}`
}

function sourceKey(source, idx) {
  return `source-${idx}`
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

function claimEvidenceRow(claim, idx) {
  const raw = objectValue(claim.raw)
  const sourceRef = firstStringValue(claim, raw, [
    'source_ref',
    'source_locator',
    'evidence_source',
    'citation',
    'source_id',
    'media_id',
  ])
  const matched = matchClaimSource(sourceRef, idx)
  const sourceLocator = matched?.locator || locatorFromRef(sourceRef) || sourceLocators.value[idx] || sourceLocators.value[0] || null
  const sourceLabel = claimSourceDisplayLabel(matched, sourceRef, sourceLocator, idx)

  return {
    key: `claim-source-${claimKey(claim, idx)}`,
    displayIndex: claim.index ?? idx + 1,
    changeType: stringOrNull(claim.change_type ?? raw.change_type),
    fieldName: stringOrNull(claim.field_name ?? raw.field_name),
    personLabel: formatPersonId(claim.person_id ?? raw.person_id),
    claimText: claimText(claim),
    sourceRef,
    sourceRefLabel: sourceRef ? 'source reference present' : null,
    sourceLocator,
    sourceLabel,
    sourceHref: sourceLocator ? locatorHref(sourceLocator) : null,
    sourceAccessClass: null,
    mediaRefs: [],
    mediaStatusLabel: null,
  }
}

function claimEvidenceRowFromContext(context, idx) {
  const sourceLocator = stringOrNull(context.source_locator)
  const mediaRefs = arrayValue(context.media_refs).filter(isPlainObject)
  const sourceLabel = stringOrNull(context.source_label)
    || sourceRefDisplayLabel(context.source_ref)
    || (sourceLocator ? sourcePointerLabel(sourceLocator, idx) : null)
    || 'No source supplied'

  return {
    key: `claim-context-${context.claim_index ?? context.display_index ?? idx}`,
    displayIndex: context.display_index ?? context.claim_index ?? idx + 1,
    changeType: stringOrNull(context.change_type),
    fieldName: stringOrNull(context.field_name),
    personLabel: stringOrNull(context.person_label) || formatPersonId(context.person_id),
    claimText: stringOrNull(context.claim_text),
    sourceRef: stringOrNull(context.source_ref),
    sourceRefLabel: stringOrNull(context.source_ref) ? 'source reference present' : null,
    sourceLocator,
    sourceLabel,
    sourceHref: sourceLocator ? locatorHref(sourceLocator) : null,
    sourceAccessClass: formatPacketStatus(context.source_access_class),
    mediaRefs,
    mediaStatusLabel: claimMediaStatusLabel(context, mediaRefs),
  }
}

function approvalBlockerLabels() {
  return approvalBlockers.value
    .map((blocker) => stringOrNull(blocker?.label) || stringOrNull(blocker?.code) || stringOrNull(blocker))
    .filter(Boolean)
}

function claimMediaStatusLabel(context, mediaRefs) {
  const total = numberOrNull(context.media_ref_count)
  const resolved = numberOrNull(context.resolved_media_count)
  const missing = numberOrNull(context.missing_media_count)
  const inferredTotal = total ?? mediaRefs.length

  if (inferredTotal <= 0 && (resolved === null || resolved <= 0) && (missing === null || missing <= 0)) {
    return null
  }

  const parts = [`${inferredTotal} media ref${inferredTotal === 1 ? '' : 's'}`]
  if (resolved !== null) parts.push(`${resolved} resolved`)
  if (missing !== null && missing > 0) parts.push(`${missing} missing`)

  return parts.join(', ')
}

function matchClaimSource(sourceRef, idx) {
  const ref = normalizeSourceMatch(sourceRef)
  if (ref) {
    for (let sourceIdx = 0; sourceIdx < sources.value.length; sourceIdx++) {
      const source = sources.value[sourceIdx]
      const candidates = sourceMatchCandidates(source)
      if (candidates.some((candidate) => sourceMatches(ref, candidate))) {
        return {
          source,
          locator: sourceLocator(source),
          label: sourceLabel(source, sourceIdx),
        }
      }
    }

    const locator = sourceLocators.value.find((candidate) => sourceMatches(ref, candidate))
    if (locator) {
      return { locator, label: sourcePointerLabel(locator, idx) }
    }
  }

  if (sources.value.length === 1) {
    return {
      source: sources.value[0],
      locator: sourceLocator(sources.value[0]),
      label: sourceLabel(sources.value[0], 0),
    }
  }

  if (sources.value[idx]) {
    return {
      source: sources.value[idx],
      locator: sourceLocator(sources.value[idx]),
      label: sourceLabel(sources.value[idx], idx),
    }
  }

  return null
}

function claimSourceDisplayLabel(matched, sourceRef, sourceLocator, idx) {
  if (matched?.label) return matched.label
  const refLabel = sourceRefDisplayLabel(sourceRef)
  if (refLabel) return refLabel
  if (sourceLocator) return sourcePointerLabel(sourceLocator, idx)
  return 'No source supplied'
}

function sourceRefDisplayLabel(sourceRef) {
  return stringOrNull(sourceRef) ? 'source reference present' : null
}

function sourceMatchCandidates(source) {
  return [
    source.id,
    source.source_id,
    source.locator,
    source.source_locator,
    source.url,
    source.uri,
    source.path,
    source.citation,
    source.title,
    source.name,
    source.label,
  ].map((value) => stringOrNull(value)).filter(Boolean)
}

function sourceMatches(ref, candidate) {
  const value = normalizeSourceMatch(candidate)
  if (!value) return false
  if (value === ref) return true
  if (ref.length < 3 || value.length < 3) return false
  return value.includes(ref) || ref.includes(value)
}

function normalizeSourceMatch(value) {
  const text = stringOrNull(value)
  return text ? text.toLowerCase() : null
}

function sourceLocator(source) {
  return stringOrNull(source.locator)
    || stringOrNull(source.source_locator)
    || stringOrNull(source.url)
    || stringOrNull(source.uri)
    || stringOrNull(source.path)
    || stringOrNull(source.citation)
    || null
}

function sourceLabel(source, idx) {
  return sourceLabelCandidate(source.title)
    || sourceLabelCandidate(source.name)
    || sourceLabelCandidate(source.label)
    || `Source ${idx + 1}`
}

function sourceDisplayLabel(source, idx) {
  return sourceLabel(source, idx)
}

function sourceLabelCandidate(value) {
  const text = stringOrNull(value)
  if (!text || isSensitiveSourceLabel(text)) return null
  return text
}

function isSensitiveSourceLabel(value) {
  const text = value.trim()
  if (text === '') return false

  return isSensitivePreviewValue(text)
    || /^[-+a-z0-9.]+:[^\s]+/i.test(text)
    || /^\/[^\s]+/.test(text)
    || /^[a-z]:\\/i.test(text)
    || /[\\/](home|storage|private|tmp|var|mnt|media|users)[\\/]/i.test(text)
    || /(?:^|[/?&])token=/i.test(text)
    || /^[a-z0-9_-]{32,}$/i.test(text)
}

function sourcePayloadRows(source) {
  const rows = kvRows(source)
    .filter((row) => !isRawSourcePayloadKey(row.key))

  return rows.length
    ? rows
    : [{ key: 'payload', label: 'payload', value: 'available' }]
}

function isRawSourcePayloadKey(key) {
  const normalized = String(key || '').toLowerCase()
  return RAW_SOURCE_PAYLOAD_KEYS.has(normalized)
    || normalized.endsWith('_id')
    || normalized.includes('locator')
    || normalized.includes('url')
    || normalized.includes('uri')
    || normalized.includes('href')
    || normalized.includes('link')
    || normalized.includes('path')
    || normalized.includes('token')
    || normalized.includes('hash')
}

function isRawPreviewPayloadKey(key) {
  const normalized = String(key || '').toLowerCase()
  return RAW_PREVIEW_PAYLOAD_KEYS.has(normalized)
    || normalized.endsWith('_id')
    || normalized.includes('locator')
    || normalized.includes('url')
    || normalized.includes('uri')
    || normalized.includes('href')
    || normalized.includes('link')
    || normalized.includes('path')
    || normalized.includes('token')
    || normalized.includes('hash')
    || normalized.includes('command')
    || normalized.includes('selector')
}

function locatorFromRef(sourceRef) {
  const ref = stringOrNull(sourceRef)
  if (!ref) return null
  return locatorHref(ref) ? ref : null
}

function firstStringValue(primary, raw, keys) {
  for (const key of keys) {
    const value = stringOrNull(primary[key] ?? raw[key])
    if (value) return value
  }
  return null
}

function claimText(claim) {
  if (typeof claim.claim === 'string' && claim.claim.trim() !== '') return claim.claim
  if (claim.claim !== undefined && claim.claim !== null) return displayValue(claim.claim)
  const raw = objectValue(claim.raw)
  for (const key of ['claim', 'claim_text', 'statement', 'extracted_claim', 'extracted_text', 'text', 'value', 'proposed_value']) {
    if (typeof raw[key] === 'string' && raw[key].trim() !== '') return raw[key]
  }
  if (Object.keys(raw).length) return 'Claim details available; no display text supplied.'
  return null
}

function claimConfidence(claim) {
  const raw = objectValue(claim.raw)
  const value = claim.confidence ?? raw.confidence
  if (value === null || value === undefined || value === '') return null
  const numeric = Number(value)
  return Number.isFinite(numeric) ? Math.round(numeric * 100) : null
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
  const message = issue.message || issue.error || issue.warning
  return message ? displayValue(message) : 'Validation details available; no display message supplied.'
}

function operationKey(operation, idx) {
  return `${operation.index ?? operation.operation ?? 'operation'}-${idx}`
}

function operationTargetLabel(operation) {
  if (isDataQualityTodoOperation(operation)) {
    return 'preview only'
  }

  return operation.target_table ? 'target table selected' : null
}

function operationRows(operation) {
  const hidden = ['index', 'operation', 'target_table']
  if (isStructuredRemediationOperation(operation)) {
    hidden.push('operation_type', 'guards', 'current_state', 'proposed_effect')
  }

  return previewKvRows(operation).filter((row) => !hidden.includes(row.key))
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
  return isFamilyRemediationOperation(operation)
    || isSourceRemediationOperation(operation)
    || isDataQualityTodoOperation(operation)
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

function isDataQualityTodoOperation(operation) {
  return operation?.operation_type === 'genealogy_todo_create'
    || operation?.operation === 'genealogy_todo_create_preview'
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
  const rows = referencePresenceRows(value, {
    id: 'family reference',
    tree_id: 'tree reference',
    gedcom_id: 'GEDCOM reference',
    husband_id: 'husband reference',
    wife_id: 'wife reference',
  })

  return rows.concat([
    'husband_name',
    'wife_name',
    'marriage_date',
    'marriage_place',
  ]
    .filter((key) => value[key] !== undefined)
    .map((key) => toKvRow(key, value[key])))
}

function familyChildren(family) {
  return arrayValue(objectValue(family).children).filter(isPlainObject)
}

function sourceRows(source) {
  const value = objectValue(source)
  const rows = referencePresenceRows(value, {
    id: 'source reference',
    tree_id: 'tree reference',
    gedcom_id: 'GEDCOM reference',
    uid: 'source UID',
    url: 'source URL',
  })

  return rows.concat([
    'title',
    'author',
    'publication',
    'repository',
    'call_number',
    'source_quality',
    'source_category',
    'information_quality',
  ]
    .filter((key) => value[key] !== undefined)
    .map((key) => toKvRow(key, value[key]))
    .concat(sourceUsageRows(value.usage_counts)))
}

function sourceUsageRows(counts) {
  const value = objectValue(counts)
  return Object.keys(value).map((key) => toKvRow(key, value[key]))
}

function dataQualityTodoRows(operation) {
  const state = objectValue(operation?.current_state)
  const context = objectValue(state.target_context)
  const rows = referencePresenceRows(context, {
    tree_id: 'tree reference',
    person_id: 'person reference',
    family_id: 'family reference',
    source_id: 'source reference',
  })
  const targetContextTypes = dataQualityTargetContextTypes(context)

  if (targetContextTypes.length) {
    rows.unshift({
      key: 'target_context_types',
      label: 'target context',
      value: targetContextTypes.join(', '),
    })
  }

  rows.push({
    key: 'person_context',
    label: 'person context',
    value: context.person_id !== null && context.person_id !== undefined && context.person_id !== ''
      ? 'present'
      : 'not required for this preview',
  })

  for (const key of ['task_type', 'priority', 'question_present']) {
    if (state[key] !== null && state[key] !== undefined && state[key] !== '') {
      rows.push(toKvRow(key, state[key]))
    }
  }

  if (state.research_question !== null && state.research_question !== undefined && state.research_question !== '') {
    rows.push(toKvRow('research_question', state.research_question))
  }

  rows.push({ key: 'creation_status', label: 'creation status', value: 'no task created' })
  rows.push({ key: 'canonical_genealogy', label: 'canonical genealogy', value: 'no mutation' })

  return rows
}

function dataQualityTargetContextTypes(context) {
  const value = objectValue(context)
  const labels = []
  for (const [key, label] of [
    ['tree_id', 'tree'],
    ['person_id', 'person'],
    ['family_id', 'family'],
    ['source_id', 'source'],
  ]) {
    if (value[key] !== null && value[key] !== undefined && value[key] !== '') {
      labels.push(label)
    }
  }
  return labels
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
  return `locator-group-${idx}`
}

function locatorGroupTitle(idx) {
  return `Duplicate locator group ${idx + 1}`
}

function locatorGroupMeta(group) {
  const parts = []
  if (group.proposal_count !== undefined) parts.push(`${group.proposal_count} proposals`)
  const statuses = objectValue(group.proposal_statuses)
  const statusText = Object.entries(statuses).map(([key, value]) => `${key}: ${value}`).join(', ')
  if (statusText) parts.push(statusText)
  const sourceIds = arrayValue(group.resolved_source_ids)
  if (sourceIds.length) parts.push(`${sourceIds.length} source row${sourceIds.length === 1 ? '' : 's'} resolved`)
  const proposalIds = arrayValue(group.proposed_change_ids)
  if (proposalIds.length) parts.push(`${proposalIds.length} proposal${proposalIds.length === 1 ? '' : 's'} linked`)
  return parts.length ? parts.join(' · ') : '-'
}

function sourceResolutionKey(row, idx) {
  return `source-resolution-${idx}`
}

function sourceResolutionLabel(row) {
  const parts = []
  if (row.proposed_change_id) parts.push('proposal linked')
  const proposalStatus = sourceResolutionCodeLabel(row.proposal_status)
  if (proposalStatus) parts.push(proposalStatus)
  const resolutionStatus = sourceResolutionCodeLabel(row.resolution_status)
  if (resolutionStatus) parts.push(`resolution ${resolutionStatus}`)
  const resolutionMethod = sourceResolutionCodeLabel(row.resolution_method)
  if (resolutionMethod) parts.push(resolutionMethod)
  if (row.resolved_source_id) parts.push('source row resolved')
  if (sourceResolutionHasNote(row)) parts.push('resolution note recorded')
  return parts.length ? parts.join(' · ') : 'source resolution row'
}

function sourceResolutionCodeLabel(value) {
  const text = stringOrNull(value)
  if (!text) return null
  const normalized = text.toLowerCase()
  if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(normalized)) {
    return 'status recorded'
  }
  return normalized.replace(/[_-]+/g, ' ')
}

function sourceResolutionHasNote(row) {
  return stringOrNull(row?.message) !== null
}

function childLabel(child) {
  const parts = [
    child.name || (child.person_id ? 'child reference present' : 'child'),
    child.birth_date ? `b. ${child.birth_date}` : null,
    child.birth_order !== null && child.birth_order !== undefined ? `order ${child.birth_order}` : null,
  ].filter(Boolean)

  return parts.join(' · ')
}

function proposedEffectRows(effect) {
  return kvRows(objectValue(effect))
    .filter((row) => row.key !== 'rows_that_would_be_touched')
    .filter((row) => !isRawSourcePayloadKey(row.key))
}

function touchedRows(effect) {
  return arrayValue(objectValue(effect).rows_that_would_be_touched).filter(isPlainObject)
}

function touchedRowLabel(row, idx) {
  const action = stringOrNull(row.action) || 'inspect'
  return `Affected row ${idx + 1} · ${labelize(action)}`
}

function sharedChildText(operation) {
  const state = objectValue(operation?.current_state)
  const shared = arrayValue(state.shared_child_ids)
  if (!shared.length) {
    const retainedLinks = arrayValue(state.retained_child_links)
    const suspectLinks = arrayValue(state.suspect_child_links)
    const linkedIds = retainedLinks.length ? retainedLinks : suspectLinks
    return linkedIds.length ? `${linkedIds.length} linked child reference${linkedIds.length === 1 ? '' : 's'}` : '-'
  }

  return shared.length ? `${shared.length} shared child reference${shared.length === 1 ? '' : 's'}` : '-'
}

function matchingFieldText(operation) {
  const state = objectValue(operation?.current_state)
  const fields = arrayValue(objectValue(state.duplicate_signals).matching_fields)
  return fields.length ? fields.join(', ') : '-'
}

function staleHashLabel(hash) {
  const value = String(hash || '')
  if (value === '') return '-'
  return 'recorded'
}

function decisionLogDisplayRow(entry, idx) {
  if (!isPlainObject(entry)) return null
  const meta = objectValue(entry.meta)

  const actionCode = safeDecisionActionCode(entry.action)
  const reasonCode = safeDecisionReasonCode(entry.reason_code)
    || safeDecisionReasonCode(meta.reason_code)
    || safeDecisionReasonCode(entry.reason)
    || safeDecisionReasonCode(meta.reason)
  const actorLabel = safeDecisionActor(entry.actor)
  const timestamp = safeDecisionTimestamp(entry.created_at)

  return {
    key: `decision-${idx}-${actionCode || 'event'}`,
    actionLabel: actionCode ? DECISION_ACTION_LABELS[actionCode] : 'Decision recorded',
    reasonLabel: reasonCode ? DECISION_REASON_LABELS[reasonCode] : null,
    actorLabel,
    timeLabel: timestamp ? formatDate(timestamp) : null,
    metaSummary: decisionMetaSummary(entry),
  }
}

function decisionMetaSummary(entry) {
  if (!isPlainObject(entry)) return null
  const meta = objectValue(entry.meta)
  const parts = []
  const reasonCode = safeDecisionReasonCode(meta.reason_code)
  const outcomeState = safeDecisionOutcomeState(meta.outcome_state)

  if (reasonCode) {
    parts.push(`reason: ${DECISION_REASON_LABELS[reasonCode]}`)
  }

  if (outcomeState) {
    parts.push(`outcome: ${DECISION_OUTCOME_LABELS[outcomeState]}`)
  }

  for (const key of ['preview_only', 'canonical_mutation']) {
    if (typeof meta[key] === 'boolean') {
      parts.push(`${labelize(key)}: ${displayBoolean(meta[key])}`)
    }
  }

  const blockerCount = numberOrNull(meta.blocker_count)
  if (blockerCount !== null) {
    parts.push(`blockers: ${blockerCount}`)
  }

  return parts.length ? parts.join(' · ') : null
}

function safeDecisionCode(value) {
  const text = stringOrNull(value)
  if (!text) return null
  const code = text.toLowerCase()
  return /^[a-z0-9][a-z0-9_-]{0,63}$/.test(code) ? code : null
}

function safeDecisionActionCode(value) {
  const code = safeDecisionCode(value)
  if (!code) return null
  return DECISION_ACTION_LABELS[code] ? code : 'unknown'
}

function safeDecisionReasonCode(value) {
  const code = safeDecisionCode(value)
  if (!code) return null
  return DECISION_REASON_LABELS[code] ? code : 'other'
}

function safeDecisionOutcomeState(value) {
  const code = safeDecisionCode(value)
  return code && DECISION_OUTCOME_LABELS[code] ? code : null
}

function safeDecisionActor(value) {
  const text = stringOrNull(value)
  if (!text || text.length > 64 || text.includes('://') || text.includes('/')) return null
  return /^[A-Za-z0-9][A-Za-z0-9 ._-]*$/.test(text) ? text : 'unknown'
}

function safeDecisionTimestamp(value) {
  const text = stringOrNull(value)
  if (!text) return null
  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/.test(text) ? text : null
}

function referencePresenceRows(value, labels) {
  return Object.entries(labels)
    .filter(([key]) => value[key] !== null && value[key] !== undefined && value[key] !== '')
    .map(([key, label]) => ({
      key,
      label,
      raw: value[key],
      value: 'present',
    }))
}

function submitDecision(action) {
  const notes = decisionNotes.value.trim()
  const reasonCode = selectedDecisionReasonCode.value
  if (requiresDecisionReason(action) && reasonCode === null) return

  const payload = {
    unifiedId: item.value.unified_id,
    notes,
  }
  if (reasonCode) {
    payload.reasonCode = reasonCode
  }
  emit(action, payload)
}

function prepareAlternateDecision(action) {
  if (!canAct.value || !['reject', 'clarify', 'defer'].includes(action)) return
  pendingAlternateAction.value = action
}

function confirmAlternateDecision() {
  if (!canConfirmAlternateOutcome.value) return
  submitDecision(pendingAlternateAction.value)
}

function cancelAlternateDecision() {
  pendingAlternateAction.value = ''
  decisionNotes.value = ''
  decisionReasonCode.value = ''
}

function requiresDecisionReason(action) {
  return action === 'reject' || action === 'clarify' || action === 'defer'
}

async function copyTargetRef() {
  if (!targetRef.value || typeof navigator === 'undefined' || !navigator.clipboard) return
  try {
    await navigator.clipboard.writeText(targetRef.value)
  } catch (e) {
    return
  }
  targetRefCopied.value = true
  const timer = typeof window === 'undefined' ? null : window.setTimeout(() => {
    targetRefCopied.value = false
  }, 1600)
  if (timer === null) {
    targetRefCopied.value = false
  }
}

async function copyTargetLink() {
  if (
    !targetRefShareUrl.value
    || typeof navigator === 'undefined'
    || !navigator.clipboard
  ) return
  try {
    await navigator.clipboard.writeText(targetRefShareUrl.value)
  } catch (e) {
    return
  }
  targetLinkCopied.value = true
  const timer = window.setTimeout(() => {
    targetLinkCopied.value = false
  }, 1600)
  if (timer === null) {
    targetLinkCopied.value = false
  }
}

function resetDecisionInputs() {
  pendingAlternateAction.value = ''
  decisionNotes.value = ''
  decisionReasonCode.value = ''
  targetRefCopied.value = false
  targetLinkCopied.value = false
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
  flex: 1 0 100%;
}

@media (min-width: 760px) {
  .packet-heading-main {
    flex-basis: 0;
  }
}

.packet-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--ops-warning);
  margin: 0;
  overflow-wrap: anywhere;
}

.packet-summary {
  margin-top: 0.25rem;
  color: var(--color-text-primary);
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
  background: color-mix(in srgb, var(--color-accent) 10%, var(--color-bg-primary));
  color: var(--color-text-primary);
}

.status-ok,
.conf-high {
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
  color: var(--ops-success);
}

.status-pending,
.conf-med,
.status-warning {
  background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));
  color: var(--ops-warning);
}

.status-danger,
.conf-low {
  background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));
  color: var(--ops-danger);
}

.packet-section {
  background: var(--color-bg-tertiary);
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  padding: 0.7rem 0.8rem;
  min-width: 0;
}

.inspection-only-notice {
  border-color: var(--ops-warning);
  background: color-mix(in srgb, var(--ops-warning) 9%, var(--color-bg-primary));
}

.inspection-only-notice p {
  margin: 0;
  color: var(--color-text-primary);
  line-height: 1.45;
}

.face-review-overview {
  border-color: var(--ops-success);
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
}

.face-review-overview.blocked {
  border-color: var(--ops-danger);
  background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));
}

.face-review-lead {
  margin: 0 0 0.65rem;
  color: var(--color-text-primary);
  font-size: 0.88rem;
  font-weight: 700;
  line-height: 1.45;
}

.face-review-boundary {
  display: grid;
  gap: 0.2rem;
  border-left: 0.2rem solid var(--color-border);
  padding: 0.25rem 0.6rem;
}

.face-review-boundary span,
.person-side span,
.face-candidate-nav span {
  display: block;
  color: var(--color-accent);
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
}

.face-review-boundary strong {
  display: block;
  color: var(--color-text-primary);
  font-size: 0.82rem;
  line-height: 1.35;
}

.picture-match-link {
  display: inline-flex;
  align-items: center;
  min-height: 2.25rem;
  margin-top: 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  padding: 0.35rem 0.7rem;
  color: var(--color-text-primary);
  background: var(--color-bg-tertiary);
  font-size: 0.76rem;
  font-weight: 800;
  text-decoration: none;
}

.face-candidate-section {
  border-color: var(--color-border);
}

.face-candidate-nav {
  display: grid;
  grid-template-columns: 2.4rem minmax(0, 1fr) 2.4rem;
  gap: 0.65rem;
  align-items: center;
  margin-bottom: 0.7rem;
}

.face-candidate-nav > div {
  min-width: 0;
  text-align: center;
}

.face-candidate-nav strong {
  display: block;
  margin-top: 0.12rem;
  color: var(--color-text-secondary);
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}

.candidate-nav-button {
  width: 2.4rem;
  height: 2.4rem;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  color: var(--color-text-primary);
  background: var(--color-bg-tertiary);
  font-size: 1.05rem;
  font-weight: 800;
  cursor: pointer;
}

.candidate-nav-button:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.audit-hold-evidence {
  display: grid;
  grid-template-columns: minmax(0, 20rem) minmax(0, 1fr);
  gap: 0.8rem;
  align-items: center;
  min-width: 0;
}

.audit-hold-context {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  min-width: 0;
  color: var(--color-text-primary);
  overflow-wrap: anywhere;
}

.audit-hold-context p {
  margin: 0;
}

@media (max-width: 760px) {
  .audit-hold-evidence {
    grid-template-columns: minmax(0, 1fr);
  }
}

.face-candidate-evidence {
  display: grid;
  grid-template-columns: minmax(13rem, 0.8fr) minmax(16rem, 1.2fr);
  gap: 0.8rem;
  align-items: stretch;
}

.face-candidate-photo {
  display: grid;
  place-items: center;
  min-height: 14rem;
  max-height: 24rem;
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  color: var(--color-text-primary);
  background: #111;
  text-decoration: none;
}

.face-candidate-photo img {
  width: 100%;
  height: 100%;
  min-height: 14rem;
  max-height: 24rem;
  object-fit: contain;
}

.face-candidate-photo.missing {
  padding: 1rem;
  color: var(--ops-danger);
  text-align: center;
}

.face-person-comparison {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  gap: 0.55rem;
  align-content: center;
  min-width: 0;
}

.person-side {
  min-width: 0;
  border: 1px solid var(--ops-success);
  border-radius: 0.35rem;
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
  padding: 0.75rem;
}

.person-side.proposed {
  border-color: var(--ops-warning);
  background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));
}

.person-side.missing {
  border-color: var(--ops-danger);
  background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));
}

.person-side strong {
  display: block;
  margin-top: 0.35rem;
  color: var(--color-text-primary);
  font-size: 0.95rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.comparison-arrow {
  align-self: center;
  color: var(--color-accent);
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
}

.candidate-explanation {
  grid-column: 1 / -1;
  margin: 0;
  border-left: 0.2rem solid var(--ops-success);
  padding: 0.45rem 0.6rem;
  color: var(--ops-success);
  font-size: 0.8rem;
  line-height: 1.4;
}

.candidate-explanation.blocked {
  border-color: var(--ops-danger);
  color: var(--ops-danger);
}

.packet-technical-details:not(.face-technical-details) {
  display: contents;
}

.face-technical-details {
  min-width: 0;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  padding: 0.65rem 0.75rem;
  background: var(--color-bg-tertiary);
}

.face-technical-details > summary {
  color: var(--color-text-primary);
  font-size: 0.76rem;
  font-weight: 800;
  cursor: pointer;
}

.face-technical-details[open] {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.face-technical-details[open] > summary {
  margin-bottom: 0.1rem;
}

@media (max-width: 760px) {
  .face-review-steps,
  .face-candidate-evidence {
    grid-template-columns: minmax(0, 1fr);
  }

  .face-person-comparison {
    grid-template-columns: minmax(0, 1fr);
  }

  .comparison-arrow {
    justify-self: center;
  }

  .candidate-explanation {
    grid-column: auto;
  }
}

.status-section {
  border-color: var(--color-border);
}

.outcome-progress {
  margin-bottom: 0.6rem;
  padding: 0.55rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 0.4rem;
  background: var(--color-bg-tertiary);
}

.outcome-head {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.45rem;
}

.outcome-label {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--ops-warning);
}

.outcome-preview {
  font-size: 0.67rem;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--color-text-primary);
}

.review-focus-section {
  border-color: var(--ops-warning);
}

.work-trail-section {
  border-color: var(--color-border);
}

.work-trail-summary {
  margin-bottom: 0.55rem;
}

.work-trail-grid {
  margin-top: 0.2rem;
}

.operator-question-section {
  border-color: var(--ops-warning);
  background: color-mix(in srgb, var(--ops-warning) 10%, var(--color-bg-primary));
}

.operator-question {
  color: var(--ops-warning);
  font-size: 1rem;
  font-weight: 800;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.operator-question-note {
  margin-top: 0.4rem;
  color: var(--color-text-primary);
  font-size: 0.8rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.review-checklist-section {
  border-color: var(--color-border);
}

.review-proof-section {
  border-color: var(--ops-success);
}

.evidence-lens-section {
  border-color: var(--color-border);
}

.proof-row {
  border-color: var(--ops-success);
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
}

.evidence-lens-row {
  border-color: var(--color-border);
  background: var(--color-bg-tertiary);
}

.checklist-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.4rem;
  min-width: 0;
}

.checklist-row {
  display: grid;
  grid-template-columns: auto minmax(5rem, 0.6fr) minmax(0, 1fr);
  gap: 0.4rem;
  align-items: baseline;
  min-width: 0;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  background: var(--color-bg-tertiary);
  padding: 0.42rem 0.5rem;
}

.checklist-row.check-ok {
  border-color: var(--ops-success);
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
}

.checklist-row.check-warning,
.checklist-row.check-missing {
  border-color: var(--ops-warning);
  background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));
}

.checklist-row.check-blocked {
  border-color: var(--ops-danger);
  background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));
}

.checklist-state {
  min-width: 2.65rem;
  border-radius: 0.25rem;
  background: var(--color-bg-tertiary);
  color: var(--color-text-primary);
  font-size: 0.62rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  padding: 0.12rem 0.28rem;
  text-align: center;
  text-transform: uppercase;
}

.checklist-label {
  color: var(--color-accent);
  font-size: 0.67rem;
  font-weight: 700;
  text-transform: uppercase;
}

.checklist-value {
  min-width: 0;
  color: var(--color-text-primary);
  font-size: 0.76rem;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
  border: 1px solid var(--ops-warning);
  border-radius: 0.35rem;
  background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));
  padding: 0.45rem 0.5rem;
}

.focus-tile {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.focus-tile.ok {
  border-color: var(--ops-success);
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
}

.focus-tile.danger,
.focus-boundary.danger {
  border-color: var(--ops-danger);
  background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));
}

.focus-label {
  color: var(--color-accent);
  font-size: 0.64rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.focus-value,
.focus-line-value,
.focus-boundary {
  color: var(--color-text-primary);
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

.target-ref-actions {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  justify-content: flex-end;
  margin-top: 0;
}

.target-ref-copy {
  border: 1px solid var(--color-border);
  border-radius: 0.3rem;
  background: var(--color-bg-tertiary);
  color: var(--color-text-primary);
  cursor: pointer;
  font-size: 0.68rem;
  font-weight: 700;
  padding: 0.28rem 0.5rem;
  text-transform: uppercase;
}

.target-ref-copy:hover {
  background: var(--color-bg-tertiary);
}

.section-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
  margin-bottom: 0.5rem;
  color: var(--color-accent);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: normal;
  text-transform: none;
}

.section-count {
  background: var(--color-bg-tertiary);
  color: var(--color-text-primary);
}

.section-status.preview {
  background: var(--color-bg-tertiary);
  color: var(--color-text-primary);
}

.subheading {
  color: var(--color-accent);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: normal;
  text-transform: none;
  margin: 0.35rem 0;
}

.subheading.danger { color: var(--ops-danger); }
.subheading.warning { color: var(--ops-warning); }

	.locator-list,
	.claim-source-list,
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
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  color: var(--color-text-primary);
  background: var(--color-bg-tertiary);
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  padding: 0.4rem 0.5rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size: 0.74rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.locator-kind {
  flex: 0 0 auto;
  color: var(--color-text-secondary);
}

	a.locator-row:hover {
	  color: var(--color-text-primary);
	  border-color: var(--color-border);
	}

	.claim-source-row {
	  display: grid;
	  grid-template-columns: minmax(0, 1fr);
	  gap: 0.55rem;
	  padding: 0.5rem;
	  border: 1px solid var(--color-border);
	  border-radius: 0.35rem;
	  background: var(--color-bg-tertiary);
	  min-width: 0;
	}

	@media (min-width: 820px) {
	  .claim-source-row {
	    grid-template-columns: minmax(0, 1.2fr) minmax(14rem, 0.8fr);
	  }
	}

	.claim-source-main,
	.claim-source-ref {
	  min-width: 0;
	}

	.claim-source-text {
	  margin-top: 0.3rem;
	  color: var(--color-text-primary);
	  font-size: 0.8rem;
	  line-height: 1.35;
	  overflow-wrap: anywhere;
	  white-space: pre-wrap;
	}

	.claim-source-ref {
	  display: flex;
	  flex-direction: column;
	  gap: 0.3rem;
	}

	.claim-source-ref-line {
	  display: grid;
	  grid-template-columns: minmax(3.25rem, auto) minmax(0, 1fr);
	  gap: 0.45rem;
	  align-items: baseline;
	  min-width: 0;
	}

	.claim-source-ref-line.media-line {
	  align-items: start;
	}

	.claim-source-label {
	  color: var(--color-accent);
	  font-size: 0.66rem;
	  font-weight: 700;
	  letter-spacing: 0.04em;
	  text-transform: uppercase;
	}

	.claim-source-value {
	  color: var(--color-text-primary);
	  font-size: 0.76rem;
	  line-height: 1.3;
	  overflow-wrap: anywhere;
	  word-break: break-word;
	  min-width: 0;
	}

	.claim-source-link:hover {
	  color: var(--color-text-primary);
	}

	.claim-pill.person {
	  border: 1px solid var(--color-border);
	  background: var(--color-bg-tertiary);
	  color: var(--color-text-primary);
	}

	.claim-media-list {
	  display: flex;
	  flex-wrap: wrap;
	  gap: 0.3rem;
	  min-width: 0;
	}

	.claim-media-pill {
	  max-width: 100%;
	  border: 1px solid var(--color-border);
	  border-radius: 0.25rem;
	  background: var(--color-bg-tertiary);
	  color: var(--color-text-primary);
	  font-size: 0.72rem;
	  line-height: 1.25;
	  padding: 0.15rem 0.35rem;
	  text-decoration: none;
	  overflow-wrap: anywhere;
	}

	.claim-media-pill:hover {
	  color: var(--color-text-primary);
	  border-color: var(--color-border);
	}

	.claim-media-pill.disabled {
	  color: var(--color-text-secondary);
	  border-color: var(--color-border);
	  background: var(--color-bg-tertiary);
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
  grid-template-columns: auto auto minmax(0, 1fr) auto;
  gap: 0.5rem;
  align-items: center;
  color: var(--color-text-primary);
  background: var(--color-bg-tertiary);
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  padding: 0.42rem 0.5rem;
  text-decoration: none;
  min-width: 0;
}

a.media-ref-card:hover {
  color: var(--color-text-primary);
  border-color: var(--color-border);
}

.media-ref-disabled {
  color: var(--color-text-secondary);
  border-color: var(--color-border);
  background: var(--color-bg-tertiary);
}

.media-ref-thumb {
  display: inline-flex;
  width: 2.5rem;
  height: 2.5rem;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: 0.3rem;
  border: 1px solid var(--color-border);
  background: var(--color-bg-tertiary);
  color: var(--color-text-secondary);
  font-size: 0.62rem;
  font-weight: 800;
  text-transform: uppercase;
}

.media-ref-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.media-ref-thumb.empty {
  border-color: var(--color-border);
}

.media-ref-id,
.media-ref-open {
  color: var(--color-accent);
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
  color: var(--ops-warning);
  font-size: 0.8rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.media-ref-sub {
  color: var(--color-text-primary);
  font-size: 0.72rem;
  overflow-wrap: anywhere;
}

.source-row,
.claim-row,
.operation-row,
.decision-row {
  border-top: 1px solid var(--color-border);
  padding-top: 0.45rem;
  min-width: 0;
}

.source-row-title,
.operation-name,
.decision-action {
  color: var(--ops-warning);
  font-size: 0.82rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.claim-topline,
.operation-head,
.decision-head,
.claim-meta,
.origin-op-list {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
}

.origin-op-list {
  margin-top: 0.55rem;
}

.claim-index {
  color: var(--color-accent);
  font-size: 0.72rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
}

.claim-pill {
  background: var(--color-bg-tertiary);
  color: var(--color-text-primary);
}

.claim-pill.muted {
  background: var(--color-bg-tertiary);
  color: var(--color-text-primary);
}

.claim-text {
  margin-top: 0.35rem;
  color: var(--color-text-primary);
  font-size: 0.85rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.claim-meta,
.decision-actor,
.decision-time,
.operation-target {
  color: var(--color-accent);
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
    color: var(--color-accent);
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: normal;
    text-transform: none;
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
  color: var(--color-accent);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.kv-value {
  color: var(--color-text-primary);
  font-size: 0.78rem;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  min-width: 0;
}

.preview-note {
  color: var(--color-text-primary);
  font-size: 0.82rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.preview-note.muted,
.empty-line,
.text-muted {
  color: var(--color-text-secondary);
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
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  background: var(--color-bg-tertiary);
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
  border-left: 3px solid var(--color-border);
  border-radius: 0.25rem;
  background: var(--color-bg-tertiary);
  min-width: 0;
}

.guard-pass {
  border-left-color: var(--ops-success);
}

.guard-fail {
  border-left-color: var(--ops-danger);
}

.guard-name,
.guard-status {
  color: var(--color-text-primary);
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  overflow-wrap: anywhere;
}

.guard-status {
  color: var(--ops-warning);
}

.guard-message,
.child-row,
.touch-row,
.locator-group-row,
.source-resolution-row {
  color: var(--color-text-primary);
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
  background: var(--color-bg-tertiary);
}

.locator-group-title {
  color: var(--ops-warning);
  font-weight: 700;
  overflow-wrap: anywhere;
}

.locator-group-meta {
  margin-top: 0.1rem;
  color: var(--color-text-primary);
  overflow-wrap: anywhere;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
}

.text-ok {
  color: var(--ops-success);
}

.text-danger {
  color: var(--ops-danger);
}

.issue-row {
  border-left: 3px solid var(--color-border);
  padding: 0.35rem 0.5rem;
  background: var(--color-bg-tertiary);
  border-radius: 0.25rem;
  min-width: 0;
}

.issue-row.danger {
  border-left-color: var(--ops-danger);
}

.issue-row.warning {
  border-left-color: var(--ops-warning);
}

.issue-gate,
.issue-code {
  display: inline-block;
  margin-right: 0.3rem;
  color: var(--color-text-primary);
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
}

.issue-message {
  color: var(--color-text-primary);
  font-size: 0.78rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.decision-note {
  margin-top: 0.25rem;
  color: var(--color-text-primary);
  font-size: 0.78rem;
  line-height: 1.35;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.packet-actions {
  border-color: var(--color-border);
}

.decision-dock {
  position: sticky;
  top: 0;
  z-index: 8;
  border-color: var(--color-border);
  background: var(--color-bg-secondary);
  box-shadow: 0 0.55rem 1.2rem rgba(0, 0, 0, 0.42);
}

.decision-scope {
  display: grid;
  gap: 0.2rem;
  border-left: 3px solid var(--ops-success);
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
  padding: 0.55rem 0.7rem;
  color: var(--ops-success);
  font-size: 0.78rem;
  line-height: 1.4;
}

.decision-scope.face-scope {
  border-left-color: var(--ops-warning);
  background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));
  color: var(--ops-warning);
}

.decision-scope strong {
  font-size: 0.76rem;
}

.decision-readiness {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  margin-top: 0.55rem;
  border: 1px solid var(--color-border);
  background: var(--color-bg-tertiary);
}

.decision-readiness-state,
.decision-readiness-fact {
  display: grid;
  align-content: center;
  gap: 0.12rem;
  min-width: 0;
  min-height: 3rem;
  padding: 0.45rem 0.55rem;
  background: var(--color-bg-secondary);
}

.decision-readiness-state {
  grid-column: 1 / -1;
  border-left: 3px solid var(--ops-warning);
}

.decision-readiness.ready .decision-readiness-state {
  border-left-color: var(--ops-success);
}

.decision-readiness-fact:last-child {
  grid-column: 1 / -1;
}

.decision-readiness span {
  color: var(--color-text-secondary);
  font-size: 0.62rem;
  font-weight: 700;
  text-transform: uppercase;
}

.decision-readiness strong {
  color: var(--color-text-primary);
  font-size: 0.76rem;
  overflow-wrap: anywhere;
}

@media (min-width: 900px) {
  .decision-readiness {
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }

  .decision-readiness-state {
    grid-column: auto;
  }

  .decision-readiness-fact:last-child {
    grid-column: auto;
  }
}

.decision-effect {
  margin: 0.55rem 0 0;
  color: var(--color-text-primary);
  font-size: 0.74rem;
  line-height: 1.4;
}

.decision-options {
  margin-top: 0.6rem;
  border-top: 1px solid var(--color-border);
  padding-top: 0.5rem;
}

.decision-options-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
}

.decision-options-heading > div {
  display: grid;
  gap: 0.15rem;
}

.decision-options-heading span {
  color: var(--color-text-primary);
  font-size: 0.66rem;
  font-weight: 700;
  text-transform: none;
}

.decision-options-heading strong {
  color: var(--color-text-primary);
  font-size: 0.82rem;
}

.decision-options .action-fields {
  margin-top: 0.55rem;
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

  .decision-options .action-fields {
    grid-template-columns: minmax(11rem, 0.32fr) minmax(0, 1fr);
  }
}

.action-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}

.action-label {
  color: var(--color-accent);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.action-notes,
.action-select {
  width: 100%;
  min-width: 0;
  color: var(--color-text-primary);
  background: var(--color-bg-tertiary);
  border: 1px solid var(--color-border);
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
  border-color: var(--color-accent);
  outline: none;
}

.action-notes:disabled,
.action-select:disabled {
  color: var(--color-text-secondary);
  background: var(--color-bg-tertiary);
}

.action-hint {
  margin-top: 0.5rem;
  color: var(--ops-warning);
  font-size: 0.78rem;
}

.alternate-confirm {
  width: 100%;
  margin-top: 0.55rem;
}

.action-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 0.65rem;
}

.decision-primary-actions {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

.decision-primary-actions .packet-action.primary {
  grid-column: 1 / -1;
}

@media (min-width: 760px) {
  .decision-primary-actions {
    display: flex;
  }

  .decision-primary-actions .packet-action.primary {
    grid-column: auto;
  }
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
  color: var(--color-accent);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.approval-blocker {
  max-width: 100%;
  padding: 0.22rem 0.45rem;
  border: 1px solid var(--ops-warning);
  border-radius: 0.35rem;
  color: var(--color-text-secondary);
  background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));
  font-size: 0.72rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.packet-action {
  min-height: 2.25rem;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  padding: 0.35rem 0.65rem;
  color: var(--color-text-primary);
  background: var(--color-bg-tertiary);
  font-size: 0.76rem;
  font-weight: 700;
  cursor: pointer;
}

.packet-action.primary {
  border-color: var(--ops-success);
  color: var(--ops-success);
  background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));
}

.packet-action.danger {
  border-color: var(--ops-danger);
  color: var(--ops-danger);
  background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));
}

.packet-action.ghost {
  border-color: var(--color-border);
  color: var(--color-text-primary);
  background: var(--color-bg-tertiary);
}

.packet-action.selected {
  border-color: var(--color-accent);
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.18);
}

.packet-action:disabled {
  cursor: not-allowed;
  opacity: 0.52;
}

.packet-action:not(:disabled):hover {
  border-color: var(--color-border);
}
</style>

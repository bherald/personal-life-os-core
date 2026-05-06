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
                  <span class="kv-value mono">{{ staleHashShort(operation.stale_hash) }}</span>
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
          <div v-if="decisionMetaSummary(entry)" class="decision-note muted">{{ decisionMetaSummary(entry) }}</div>
        </div>
      </div>
      <div v-else class="empty-line">No decision log entries yet.</div>
    </section>

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
  { value: 'source_verified', label: 'Source verified' },
  { value: 'missing_source_locator', label: 'Missing source locator' },
  { value: 'source_needs_review', label: 'Source needs review' },
  { value: 'identity_unclear', label: 'Identity unclear' },
  { value: 'weak_evidence', label: 'Weak evidence' },
  { value: 'privacy_review_needed', label: 'Privacy review needed' },
  { value: 'duplicate_packet', label: 'Duplicate packet' },
  { value: 'other', label: 'Other' },
]

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

const reviewPassRows = computed(() => {
  const counts = objectValue(reviewPass.value.counts)
  const signals = objectValue(reviewPass.value.signals)
  const posture = objectValue(reviewPass.value.posture)

  return [
    reviewPassRow('state', 'State', reviewPass.value.label || reviewPass.value.state, reviewPassState(reviewPass.value.state)),
    reviewPassRow('reason', 'Reason', reviewPass.value.reason_code ? labelize(reviewPass.value.reason_code) : null, 'warning'),
    reviewPassRow('blockers', 'Blockers', reviewPass.value.blocker_count, Number(reviewPass.value.blocker_count || 0) > 0 ? 'blocked' : 'ok'),
    reviewPassRow('claims', 'Claims', counts.claim_count, 'ok'),
    reviewPassRow('sources', 'Sources', counts.source_count, 'ok'),
    reviewPassRow('media', 'Media', mediaReviewPassLabel(counts), (Number(counts.missing_media_count || 0) > 0) ? 'warning' : 'ok'),
    reviewPassRow('checklist', 'Checklist rows', counts.checklist_row_count, 'ok'),
    reviewPassRow('validation', 'Validation', signals.validation_state, signals.validation_state === 'valid' ? 'ok' : 'warning'),
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
  focusRow('target', 'Target ref', targetRefLabel.value, 'mono', targetRef.value),
  focusRow('person', 'Person', reviewFocusPersonLabel.value),
  focusRow('status', 'Packet status', packetStatusLabel.value),
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

function originRow(key, label, value) {
  if (value === null || value === undefined || value === '') return null
  return { key, label, raw: value, value: displayValue(value) }
}

function outcomeRow(key, label, value) {
  if (value === null || value === undefined || value === '') return null
  return { key, label, raw: value, value: displayValue(value) }
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
  if (Array.isArray(value) || isPlainObject(value)) return compactJson(value)
  return String(value)
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
  if (!media || media.file_exists === false) return null
  return typeof media.view_url === 'string' && media.view_url.trim() !== '' ? media.view_url : null
}

function mediaRefKey(media, idx) {
  return `${media?.view_url || media?.title || 'media'}-${idx}`
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
  const sourceLabel = matched?.label || sourceRef || sourceLocator || 'No source supplied'

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
  }
}

function claimEvidenceRowFromContext(context, idx) {
  const sourceLocator = stringOrNull(context.source_locator)
  const sourceLabel = stringOrNull(context.source_label)
    || stringOrNull(context.source_ref)
    || sourceLocator
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
    mediaRefs: arrayValue(context.media_refs).filter(isPlainObject),
  }
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
  return stringOrNull(source.title)
    || stringOrNull(source.name)
    || stringOrNull(source.label)
    || `Source ${idx + 1}`
}

function sourceDisplayLabel(source, idx) {
  return sourceLabel(source, idx)
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
    || normalized.includes('path')
    || normalized.includes('token')
    || normalized.includes('hash')
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
  if (row.proposal_status) parts.push(row.proposal_status)
  if (row.resolution_status) parts.push(`resolution ${row.resolution_status}`)
  if (row.resolution_method) parts.push(row.resolution_method)
  if (row.resolved_source_id) parts.push('source row resolved')
  if (row.locator || row.url) parts.push('source locator matched')
  if (row.message) parts.push(row.message)
  return parts.length ? parts.join(' · ') : displayValue(row)
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

function decisionMetaSummary(entry) {
  if (!isPlainObject(entry)) return null
  const meta = objectValue(entry.meta)
  const parts = []

  for (const key of ['reason_code', 'outcome_state', 'preview_only', 'canonical_mutation', 'blocker_count']) {
    if (meta[key] !== null && meta[key] !== undefined && meta[key] !== '') {
      parts.push(`${labelize(key)}: ${displayValue(meta[key])}`)
    }
  }

  return parts.length ? parts.join(' · ') : null
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
  const reasonCode = decisionReasonCode.value.trim()
  const payload = {
    unifiedId: item.value.unified_id,
    notes,
  }
  if (reasonCode) {
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

.outcome-progress {
  margin-bottom: 0.6rem;
  padding: 0.55rem 0.6rem;
  border: 1px solid rgba(99, 179, 237, 0.22);
  border-radius: 0.4rem;
  background: rgba(99, 179, 237, 0.07);
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
  color: #ffde9a;
}

.outcome-preview {
  font-size: 0.67rem;
  font-weight: 700;
  text-transform: uppercase;
  color: #bfe1ff;
}

.review-focus-section {
  border-color: rgba(255, 204, 102, 0.26);
}

.review-checklist-section {
  border-color: rgba(99, 179, 237, 0.24);
}

.review-proof-section {
  border-color: rgba(47, 158, 68, 0.28);
}

.evidence-lens-section {
  border-color: rgba(99, 179, 237, 0.28);
}

.proof-row {
  border-color: rgba(47, 158, 68, 0.22);
  background: rgba(47, 158, 68, 0.06);
}

.evidence-lens-row {
  border-color: rgba(99, 179, 237, 0.22);
  background: rgba(99, 179, 237, 0.06);
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
  border: 1px solid rgba(99, 179, 237, 0.18);
  border-radius: 0.35rem;
  background: rgba(99, 179, 237, 0.06);
  padding: 0.42rem 0.5rem;
}

.checklist-row.check-ok {
  border-color: rgba(47, 158, 68, 0.35);
  background: rgba(47, 158, 68, 0.08);
}

.checklist-row.check-warning,
.checklist-row.check-missing {
  border-color: rgba(204, 136, 0, 0.32);
  background: rgba(204, 136, 0, 0.08);
}

.checklist-row.check-blocked {
  border-color: rgba(204, 68, 68, 0.45);
  background: rgba(204, 68, 68, 0.12);
}

.checklist-state {
  min-width: 2.65rem;
  border-radius: 0.25rem;
  background: rgba(0, 0, 0, 0.18);
  color: #bfe1ff;
  font-size: 0.62rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  padding: 0.12rem 0.28rem;
  text-align: center;
  text-transform: uppercase;
}

.checklist-label {
  color: #b39ddb;
  font-size: 0.67rem;
  font-weight: 700;
  text-transform: uppercase;
}

.checklist-value {
  min-width: 0;
  color: #f0e6ff;
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

.locator-kind {
  flex: 0 0 auto;
  color: #9b8bb5;
}

	a.locator-row:hover {
	  color: #ffffff;
	  border-color: rgba(99, 179, 237, 0.55);
	}

	.claim-source-row {
	  display: grid;
	  grid-template-columns: minmax(0, 1fr);
	  gap: 0.55rem;
	  padding: 0.5rem;
	  border: 1px solid rgba(99, 179, 237, 0.18);
	  border-radius: 0.35rem;
	  background: rgba(99, 179, 237, 0.06);
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
	  color: #f0e6ff;
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
	  color: #b39ddb;
	  font-size: 0.66rem;
	  font-weight: 700;
	  letter-spacing: 0.04em;
	  text-transform: uppercase;
	}

	.claim-source-value {
	  color: #d8efff;
	  font-size: 0.76rem;
	  line-height: 1.3;
	  overflow-wrap: anywhere;
	  word-break: break-word;
	  min-width: 0;
	}

	.claim-source-link:hover {
	  color: #ffffff;
	}

	.claim-pill.person {
	  border: 1px solid rgba(99, 179, 237, 0.24);
	  background: rgba(99, 179, 237, 0.10);
	  color: #d8efff;
	}

	.claim-media-list {
	  display: flex;
	  flex-wrap: wrap;
	  gap: 0.3rem;
	  min-width: 0;
	}

	.claim-media-pill {
	  max-width: 100%;
	  border: 1px solid rgba(99, 179, 237, 0.24);
	  border-radius: 0.25rem;
	  background: rgba(99, 179, 237, 0.10);
	  color: #d8efff;
	  font-size: 0.72rem;
	  line-height: 1.25;
	  padding: 0.15rem 0.35rem;
	  text-decoration: none;
	  overflow-wrap: anywhere;
	}

	.claim-media-pill:hover {
	  color: #ffffff;
	  border-color: rgba(99, 179, 237, 0.55);
	}

	.claim-media-pill.disabled {
	  color: #9b8bb5;
	  border-color: rgba(102, 102, 102, 0.25);
	  background: rgba(0, 0, 0, 0.12);
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

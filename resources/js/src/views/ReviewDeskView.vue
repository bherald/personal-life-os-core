<template>
    <section class="review-desk" :class="{ 'desk-detail-open': mobileDetail }" :style="{ '--desk-action-height': `${actionDockHeight}px` }" aria-labelledby="desk-title">
        <header class="desk-page-heading"><div><h1 id="desk-title">Review</h1><p>Open one item, check the photo or evidence, then choose what to do.</p></div><button class="desk-button" @click="openGlobalIdentity">Person names &amp; aliases</button><button class="desk-button" @click="openLookup">Find a reference <kbd>Ctrl / ⌘ K</kbd></button></header>
        <nav class="desk-modes" aria-label="Review areas"><button class="desk-button" :aria-pressed="mode === 'approvals'" @click="changeMode('approvals')">Approvals <span>{{ total }}</span></button><button class="desk-button" :aria-pressed="mode === 'photos'" @click="changeMode('photos')">Photo identification <span v-if="photoTotal !== null">{{ photoTotal }} pending</span></button></nav>
        <div v-if="mode === 'approvals'" class="desk-photo-entry"><div><strong>Review people in your photos</strong><p>Choose the face and the person you recognize, one photo at a time.</p></div><button class="desk-button primary" @click="changeMode('photos')">Review photos<span v-if="photoTotal !== null"> ({{ photoTotal }} pending)</span> →</button></div>
        <p v-if="!online" class="desk-warning" role="status">Offline. Notes stay in this tab. Decisions will not be sent or replayed automatically.</p>
        <p v-if="notice" class="desk-notice" role="status">{{ notice }}</p>
        <p v-if="pendingBatch" class="desk-warning" role="status">A previous batch needs reconciliation. <button class="desk-link" @click="openBulk">Review the saved batch</button></p>
        <div v-if="warnings.length" class="desk-warning" role="status"><strong>Some sources need attention.</strong><p v-for="(warning, index) in warnings" :key="index">{{ warningText(warning) }}</p><button class="desk-link" @click="refresh">Refresh sources</button></div>
        <GlobalFaceIdentityEditor v-if="globalIdentityOpen" :face="globalIdentityFace" @close="closeGlobalIdentity" @staged="identityProposalStaged" />
        <div class="desk-workspace">
            <aside ref="queuePanel" class="desk-queue" aria-label="Review queue">
                <FacesUnidentified v-if="mode === 'photos'" ref="photoQueue" embedded :selected-reference="active?.reference || ''" @open="selectItem($event, true)" @count="photoTotal = $event" @references="photoReferences = $event" />
                <template v-else>
                <div class="desk-queue-heading"><h2>Decisions ready</h2><span>{{ total }} {{ total === 1 ? 'item' : 'items' }}</span></div>
                <div class="desk-filters"><label class="desk-sr-only" for="desk-search">Search items or references</label><input id="desk-search" v-model="query" type="search" placeholder="Search items or paste a reference…" @input="scheduleSearch"><label class="desk-sr-only" for="desk-type">Filter review area</label><select id="desk-type" v-model="type" @change="typeChanged"><option value="">All areas</option><option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
                <p class="desk-queue-hint">Choose an item below. Each button explains the change it makes.</p>
                <div v-if="listError" class="desk-load-error" role="alert"><p>{{ listError }}</p><button class="desk-button" @click="loadItems(false)">Retry queue</button></div>
                <p v-else-if="loading && !items.length" class="desk-empty" role="status">Loading decisions…</p>
                <p v-else-if="!items.length" class="desk-empty">No decisions ready in this view.</p>
                <div class="desk-items" :aria-busy="loading">
                    <div v-for="item in items" :key="item.reference" class="desk-queue-item" :class="{ selected: active?.reference === item.reference }">
                        <label v-if="canBulkReview(item)" class="desk-item-select"><input type="checkbox" :aria-label="`Select ${item.question || item.title}`" :checked="!!selected[item.reference]" @change="toggleSelection(item, $event.target.checked)"></label>
                        <button class="desk-item-open" :aria-current="active?.reference === item.reference ? 'true' : undefined" @click="selectItem(item.reference, true)"><span class="desk-item-area">{{ item.category || readableValue(item.review_type).replaceAll('_', ' ') }}</span><strong>{{ queueQuestion(item) }}</strong><span v-if="item.title && item.title !== item.question" class="desk-item-title">{{ item.title }}</span><span class="desk-item-meta"><span class="desk-risk" :class="{ routine: item.readiness?.state === 'ready' }">{{ readinessLabel(item) }}</span></span></button>
                    </div>
                </div>
                <button v-if="hasMore" class="desk-button desk-more" :disabled="loading" @click="loadItems(true)">Load more</button>
                <div v-if="selection.length" class="desk-bulk-bar"><strong>{{ selection.length }} selected</strong><button class="desk-button primary" @click="openBulk">Review selected</button><button class="desk-link" @click="selected = {}">Clear</button></div>
                <p class="desk-queue-foot">Routine eligibility is checked again by the server. Sensitive items stay individual.</p>
                </template>
            </aside>
            <article ref="detailPanel" class="desk-detail" aria-labelledby="desk-decision-heading" :aria-busy="detailLoading">
                <nav v-if="active && canShowActive" class="desk-browse" aria-label="Browse review items"><button class="desk-button" :disabled="busy || detailLoading || activeQueueIndex <= 0" @click="browseItem(-1)">← Previous item</button><span>{{ activeQueueIndex >= 0 ? `${activeQueueIndex + 1} of ${browseReferences.length}` : 'Photo opened from your library' }}</span><button class="desk-button" :disabled="busy || detailLoading || activeQueueIndex < 0 || activeQueueIndex >= browseReferences.length - 1" @click="browseItem(1)">Next item →</button></nav>
                <button class="desk-back" @click="backToQueue">← Back to queue</button>
                <p v-if="detailLoading" class="desk-empty" role="status">Loading evidence…</p>
                <div v-else-if="detailError" class="desk-load-error" role="alert"><h2 id="desk-decision-heading" tabindex="-1">Unable to open this item</h2><p>{{ detailError }}</p><button class="desk-button" @click="selectItem(requestedReference)">Retry evidence</button></div>
                <template v-else-if="active && canShowActive">
                    <div class="desk-detail-main">
                        <div class="desk-item-topline"><span class="desk-risk" :class="{ routine: !faceSourceUnavailable && active.readiness?.state === 'ready' }">{{ faceSourceUnavailable ? 'Photo unavailable' : isPhotoFace && !hasFaceCandidate ? 'Choose who is in this photo' : readinessLabel(active) }}</span><span>{{ active.category }}</span></div>
                        <h2 id="desk-decision-heading" class="desk-question" tabindex="-1">{{ faceSourceUnavailable ? 'Photo unavailable' : isPersonNameProposal ? `Save the person name “${context.global_identity_review.canonical_name}”?` : isPhotoFace && !hasFaceCandidate ? 'Who is in this photo?' : active.question || active.title }}</h2>
                        <p v-if="active.status === 'pending' && active.readiness?.state !== 'ready' && !allows('refresh_region') && !allows('link') && !isRegistryFace" class="desk-warning"><strong>Not a decision yet.</strong> This item needs preparation or its source-specific review before any change can be approved here.</p>
                        <p class="desk-effect"><strong>{{ allows('approve') ? 'What approval does:' : 'Decision effect:' }}</strong> {{ frontEffect }}</p>
                        <div v-if="active.status !== 'pending'" class="desk-receipt"><strong>{{ statusLabel(active.status) }}</strong><p>Evidence, feedback and receipts remain attached to this reference.</p><button v-if="allows('reopen')" class="desk-button" :disabled="busy || !online" @click="decide('reopen')">Return to decision queue</button></div>
                        <div v-if="!compactReview && (active.readiness?.explanation || active.blockers?.length || operatorView.missingEvidence.length)" class="desk-warning"><strong>What this needs</strong><p v-if="active.readiness?.explanation">{{ active.readiness.explanation }}</p><p v-for="(blocker, index) in active.blockers || []" :key="`blocker-${index}`">{{ blockerText(blocker) }}</p><p v-for="(missing, index) in operatorView.missingEvidence" :key="`missing-${index}`">{{ missing }}</p></div>
                        <p v-if="faceProofError" class="desk-warning" role="alert">{{ faceProofError }}</p>
                        <div class="desk-evidence-grid" :class="{ 'has-image': imageSource && (!isPhotoFace || hasFaceCandidate) }">
                            <div v-if="images.length" class="desk-image-gallery"><ReviewEvidenceViewer v-for="image in images" :key="`${active.reference}:${active.revision}:${image.src}:${suggestionSelectionVersion}`" :src="image.src" :title="isPhotoFace ? 'Current source photo' : image.title" :open-label="isGlobalFace ? 'Open face crop' : isFace && allows('refresh_region') ? 'Open photo and choose a face' : 'Open full image'" :region="isRegistryFace ? faceSourceProof?.region : isFace ? selectedRegion || (context.face_review?.region_reviewed ? context.item?.face_region : null) : null" :proposed-name="isGlobalFace ? context.global_identity_review?.canonical_name : isFace && hasFaceCandidate && !selectedRegion ? context.item?.suggested_person_name : ''" :reference="active.reference" :revision="active.revision" :selectable="isFace && !faceSourceUnavailable && allows('refresh_region') && canDecide" @region-selected="selectedRegion = $event" @ready="(isPhotoFace || isGlobalFace) && (imageReady = $event)" @error="registryPreviewFailed(image.src)" /></div>
                            <div v-if="!compactReview" class="desk-evidence-summary"><h3>Why this is being asked</h3><p>{{ evidenceSummary }}</p><p v-if="uncertainty" class="desk-uncertainty">{{ uncertainty }}</p></div>
                        </div>
                        <p v-if="isGlobalFace && !imageReady" class="desk-warning" role="status">The selected current face must load before approval. Missing or changed image evidence cannot confirm an identity.</p>
                        <section v-if="isFace && active.status === 'pending' && !faceSourceUnavailable" class="desk-face-review" aria-label="Current region and person review">

                            <div v-if="allows('refresh_region')" class="desk-face-suggestions">
                                <h3>1. Choose the face</h3>
                                <p class="desk-help">Choose a box below, or open the photo and draw around the face.</p>
                                <div v-if="anonymousRegions?.length" class="desk-face-suggestion-buttons"><button v-for="candidate in anonymousRegions" :key="candidate.face_index" type="button" class="desk-button" :aria-pressed="selectedSuggestionIndex === candidate.face_index" :disabled="!canSelectSuggestion" @click="selectAnonymousRegion(candidate.face_index)">Select face {{ candidate.face_index + 1 }}</button></div>
                                <p v-else class="desk-help">Open the photo to draw a face box.</p>
                            </div>
                            <p v-if="!allows('link') && !context.face_review?.global_face" class="desk-help"><strong>2. Choose the person.</strong> Save the face first, then choose the person you recognize.</p>
                            <template v-if="allows('link')">
                                <label for="desk-person-search">2. Choose the person in the family tree</label>
                                <input id="desk-person-search" v-model="personQuery" type="search" class="desk-person-input" :disabled="!canDecide" @input="searchFacePersons" placeholder="Search name…">
                                <p v-if="personError" role="alert">{{ personError }}</p>
                                <ul class="desk-person-results"><li v-for="person in personResults" :key="person.id"><button class="desk-button" :disabled="!canDecide" @click="selectedPerson = person">{{ person.given_name }} {{ person.surname }} <span v-if="person.birth_date">· {{ person.birth_date }}</span></button></li></ul>
                                <p v-if="selectedPerson">Selected: {{ selectedPerson.given_name }} {{ selectedPerson.surname }}.</p>
                                <label v-if="selectedPerson" class="desk-override-check"><input v-model="personConfirmed" type="checkbox" :disabled="!canDecide"> I reviewed this saved region and confirm this person’s identity.</label>
                            </template>
                        </section>
                        <PhotoReviewEvidence v-if="!isPhotoFace && photoEvidenceSelector(context, active)" :key="active.reference + active.revision" :selector="photoEvidenceSelector(context, active)" @evidence-loaded="dateEvidence = $event" @preview-ready="datePreviewReady = $event" />
                        <div v-if="!isPhotoFace || hasFaceCandidate" class="desk-comparison"><div><span>On file now</span><strong>{{ isPersonNameProposal ? 'This new person name has not been saved' : readableValue(active.current) }}</strong></div><div><span>Proposed change</span><strong>{{ isPersonNameProposal ? context.global_identity_review.canonical_name : readableValue(active.proposed) }}</strong></div></div>

                        <p v-if="!isRegistryFace && nativeSourceUrl && !allows('approve') && !allows('refresh_region') && !allows('link') && active.status === 'pending'" class="desk-warning">This item needs its specialized review. No blanket approval is available here. <a :href="nativeSourceUrl" class="desk-source-button">Open the exact source review →</a></p>
                        <section v-if="canOfferDateWriteRetry" class="desk-date-write-retry" aria-label="Retry separately approved photo year write">
                            <h3>Retry the approved approximate-year write</h3>
                            <p><strong>Original authorized value:</strong> Approximate year {{ authorizedRetryYear || 'recorded in the original decision' }}.</p>
                            <p>The original operator approval remains in effect. This retries only that scanned-photo year write against the same authorized source bytes.</p>
                            <p v-if="!canRetryDateWrite" class="desk-help" role="status">Load the current photo and its verified preview. The asset and source hash must still match the original authorization.</p>
                            <button class="desk-button" :disabled="!canRetryDateWrite" @click="retryApprovedYearWrite">Retry authorized year write</button>
                        </section>
                        <details class="desk-disclosure"><summary>Details &amp; original source</summary>
                            <p v-if="compactReview" class="desk-effect"><strong>Full decision effect:</strong> {{ active.effect }}</p>
                            <div v-if="compactReview && (active.readiness?.explanation || active.blockers?.length || operatorView.missingEvidence.length)" class="desk-warning"><strong>What this needs</strong><p v-if="active.readiness?.explanation">{{ active.readiness.explanation }}</p><p v-for="(blocker, index) in active.blockers || []" :key="`blocker-${index}`">{{ blockerText(blocker) }}</p><p v-for="(missing, index) in operatorView.missingEvidence" :key="`missing-${index}`">{{ missing }}</p></div>
                            <div v-if="compactReview" class="desk-evidence-summary"><h3>Why this is being asked</h3><p>{{ evidenceSummary }}</p><p v-if="uncertainty" class="desk-uncertainty">{{ uncertainty }}</p><p v-if="isGlobalFace" class="desk-uncertainty">This crop shows the selected face. The proposed person remains unconfirmed until your separate approval.</p><p v-if="isFace && hasFaceCandidate" class="desk-uncertainty">Only you can confirm this identity. The proposed name and model confidence are not proof.</p></div>
                            <p class="desk-help">Reference {{ active.reference }} · revision {{ shortRevision(active.revision) }} <button class="desk-link" @click="copy(active.reference)">Copy reference</button></p>
                        <section class="desk-operator-view" aria-labelledby="desk-operator-heading"><h3 id="desk-operator-heading">Supporting explanation</h3><p>{{ operatorView.summary }}</p><p v-if="operatorView.observedAt" class="desk-observed">Observed {{ formatDate(operatorView.observedAt) || operatorView.observedAt }}. Check current state before acting.</p><section v-if="operatorView.proposedActions.length" class="desk-proposed-actions" aria-labelledby="desk-actions-heading"><h4 id="desk-actions-heading">Proposed actions to assess</h4><ol><li v-for="(action, index) in operatorView.proposedActions" :key="index"><strong>{{ action.label }}</strong><p>{{ action.description }}</p><p><b>Effect:</b> {{ action.effect }}</p><a v-if="actionUrl(action.sourceUrl)" :href="actionUrl(action.sourceUrl)" target="_blank" rel="noopener noreferrer">Open exact action source ↗</a></li></ol><p>These actions are not approved as a packet. Use each source-specific review.</p></section></section>
                            <PhotoReviewEvidence v-if="isFace && photoEvidenceSelector(context, active)" :key="active.reference + active.revision" :selector="photoEvidenceSelector(context, active)" />
                            <div class="desk-operator-details"><p v-for="(omission, index) in operatorView.omissions" :key="`omission-${index}`" class="desk-omission" role="note">{{ omission }}</p><section v-for="(section, index) in operatorView.sections" :key="index" class="desk-operator-section"><h4>{{ section.title }}</h4><dl><template v-for="(field, fieldIndex) in section.fields" :key="fieldIndex"><dt>{{ field.label }}</dt><dd>{{ field.value }}</dd></template></dl></section></div>
                            <dl v-if="evidenceFields.length" class="desk-evidence-fields"><template v-for="field in evidenceFields" :key="field.label"><dt>{{ field.label }}</dt><dd>{{ field.value }}</dd></template></dl>
                            <ul v-if="evidenceLinks.length" class="desk-source-links"><li v-for="link in evidenceLinks" :key="link.url"><a :href="link.url" target="_blank" rel="noopener noreferrer">{{ link.label }} ↗</a></li></ul>
                            <div v-if="fieldDiffs.length" class="desk-diff-scroll"><table><thead><tr><th>Field</th><th>Current</th><th>Proposed</th><th>Evidence status</th></tr></thead><tbody><tr v-for="(difference, index) in fieldDiffs" :key="index"><td>{{ difference.field_label || difference.field || difference.field_name }}</td><td>{{ readableValue(difference.on_file_value ?? difference.current_value ?? difference.on_file) }}</td><td>{{ readableValue(difference.proposed_value ?? difference.proposed) }}</td><td>{{ readableValue(difference.match_status || difference.status) }}</td></tr></tbody></table></div>
                            <p v-if="!operatorView.sections.length && !evidenceFields.length && !evidenceLinks.length && !fieldDiffs.length" class="desk-help">No additional attachments or field-level comparison are available in this view. Check the summary above and open the exact source review.</p>
                            <a v-if="nativeSourceUrl" :href="nativeSourceUrl" class="desk-source-button">Open source-specific details →</a>
                        </details>
                        <details class="desk-disclosure"><summary>Decision history &amp; AI handoff <span>{{ historyRows.length }} records</span></summary><p class="desk-help">Reference {{ active.reference }} stays available after closure. AI lookup is read-only and cannot approve for you.</p><ol class="desk-history"><li v-for="(entry, index) in historyRows" :key="entry.id || index"><strong>{{ statusLabel(entry.action || entry.status || entry.label) }}</strong><span>{{ formatDate(entry.created_at || entry.recorded_at) }}</span><p v-if="entry.notes || entry.note">{{ entry.notes || entry.note }}</p><p v-if="entry.message || entry.outcome">{{ readableValue(entry.message || entry.outcome) }}</p></li><li v-if="!historyRows.length">No recorded operator decision.</li></ol><div class="desk-handoff-actions"><button class="desk-link" @click="copy(aiReviewHandoff(active, notes))">Copy AI handoff</button><button class="desk-link" @click="copy(itemLink(active.reference))">Copy item link</button></div></details>
                        <label v-if="active.review_type === 'scan_date_estimate' && active.status === 'pending'" class="desk-reason">Rejection reason <select v-model="reasonCode"><option value="">Choose if denying this estimate</option><option value="wrong_date">Wrong date</option><option value="insufficient_visual_evidence">Insufficient visual evidence</option><option value="not_a_scan">Not a scanned photograph</option><option value="duplicate">Duplicate review</option><option value="other">Other</option></select></label>
                        <div class="desk-notes"><div><label for="desk-note">{{ active.status === 'pending' ? 'Your response / follow-up' : 'Recorded response' }}</label><span>{{ draftState }}</span></div><textarea id="desk-note" ref="noteInput" v-model="notes" rows="3" maxlength="4000" :readonly="active.status !== 'pending'" :disabled="busy || !!pendingRequest" placeholder="Tell the agent what to check, what is wrong, or why you decided…" @input="saveDraft"></textarea></div>
                        <div v-if="decisionError" class="desk-warning" role="alert"><p>{{ decisionError }}</p><button v-if="stale" class="desk-button" @click="reloadEvidence">Load current evidence</button><button v-if="pendingRequest && !stale" class="desk-button" :disabled="!online || busy" @click="retryDecision">Reconcile / retry this exact request</button></div>
                    </div>
                    <footer v-if="active.status === 'pending' && (!isRegistryFace || (faceSourceProof && imageReady))" ref="actionDock" class="desk-actions"><button v-if="isFace && !faceSourceUnavailable && allows('refresh_region') && (!context.face_review?.region_reviewed || hasUnsavedRegion)" class="desk-button primary" :disabled="!canDecide || !imageReady || !selectedRegion || !faceSourceProof" @click="saveCurrentRegion">Save selected face</button><button v-if="isFace && !faceSourceUnavailable && allows('link') && !hasUnsavedRegion" class="desk-button primary" :disabled="!canDecide || !imageReady || !selectedPerson || !personConfirmed" @click="linkSelectedPerson">Confirm this person for this face</button><button v-if="!faceSourceUnavailable && (isRegistryFace ? faceSourceProof?.global_face : isFace && context.face_review?.global_face && !selectedPerson)" class="desk-button" :class="{ primary: !allows('link') }" :disabled="!canNameFace" @click="openFaceIdentity">Name this face</button><button v-if="isFace && !faceSourceUnavailable && allows('false_detection')" class="desk-button deny" :disabled="!canDecide || !imageReady || !selectedRegion || !faceSourceProof" @click="dismissSelectedRegion">Not a face</button><button v-if="!faceSourceUnavailable && (allows('approve') || (isGlobalFace && active.actions?.includes('approve')))" class="desk-button primary" :disabled="!canDecide || (isFace && (!imageReady || !normalizeFaceRegion(context.item?.face_region))) || (isGlobalFace && !imageReady)" @click="beginApproval">{{ approvalLabel }}</button><button v-if="isScanDate && allows('approve') && (context.item?.details?.proposed_precision || 'year') === 'year'" class="desk-button" :disabled="!canDecide || !canWriteDate" @click="writeDateYear">Accept and write approximate year</button><button v-if="allows('reject')" class="desk-button deny" :disabled="!canDecide" @click="decide('reject')">{{ isFace ? 'Reject this name' : 'Reject change' }}</button><button v-if="allows('skip')" class="desk-button" :disabled="!canDecide" @click="decide('skip')">Not sure — review later</button><button v-if="allows('followup')" class="desk-button" :disabled="!canDecide" @click="decide('followup')">Request follow-up</button><p>{{ isRegistryFace ? 'Name only a person you recognize in the highlighted face.' : isFace ? (hasFaceCandidate ? 'Confirm only if you recognize this person.' : context.face_review?.region_reviewed && !hasUnsavedRegion ? 'Choose the person you recognize, then confirm.' : selectedRegion ? 'Save the selected face, then choose the person.' : 'Open the photo and choose a face to continue.') : 'Your decision applies to this change. Your note stays with the item.' }}</p></footer>
                </template>
                <div v-else-if="active" class="desk-empty"><h2 id="desk-decision-heading" tabindex="-1">Not ready for action</h2><p>This reference has no decision ready here.</p><div v-if="pendingRequest" class="desk-warning" role="alert"><p>{{ decisionError }}</p><button class="desk-button" :disabled="!online || busy" @click="retryDecision">Reconcile / retry this exact request</button></div></div>
                <div v-else class="desk-empty"><h2 id="desk-decision-heading" tabindex="-1">Choose an item</h2><p>Open an item from the list, or choose Photo identification to review people in your pictures.</p></div>
            </article>
        </div>
        <details v-if="sourceLinks.length" class="desk-source-directory"><summary>Other PLOS approval areas</summary><p>These source-specific workflows retain their own exact scope and controls.</p><a v-for="source in sourceLinks" :key="source.label" :href="source.url">{{ source.label }} →</a></details>
        <ReviewDialog v-model="lookupOpen" title="Find an item or ask for help" @opened="lookupInput?.focus()"><p class="desk-help">Use the permanent reference from any area, including completed items.</p><form class="desk-lookup-form" @submit.prevent="findReference"><label for="desk-lookup-input">Item reference</label><input id="desk-lookup-input" ref="lookupInput" v-model="lookupReference" autocomplete="off" placeholder="Paste a review reference"><button class="desk-button primary" :disabled="detailLoading">Find item</button></form><p v-if="lookupError" class="desk-warning" role="alert">{{ lookupError }}</p><p class="desk-help">In your AI conversation: “Use plos_review_lookup to explain this reference.” No approval is implied.</p></ReviewDialog>
        <ReviewDialog v-model="bulkOpen" title="Check these exact changes" :busy="bulkBusy"><p class="desk-help">Routine items only. Each revision and action is checked again by the server.</p><ol class="desk-bulk-preview"><li v-for="item in bulkSnapshot" :key="item.reference"><strong>{{ item.question || item.title }}</strong><span>{{ item.reference }} · revision {{ shortRevision(item.revision) }}</span><p>{{ item.effect }}</p></li></ol><label for="desk-bulk-note">Note for these decisions (optional)</label><textarea id="desk-bulk-note" v-model="bulkNotes" rows="2" maxlength="4000" :disabled="bulkBusy || !!pendingBatch || !!bulkResults.length"></textarea><div class="desk-modal-actions"><button class="desk-button" :disabled="bulkBusy" @click="bulkOpen = false">Close</button><button v-if="!bulkResults.length" class="desk-button primary" :disabled="bulkBusy || !online" @click="submitBulk">{{ bulkBusy ? 'Saving…' : `Approve these ${bulkSnapshot.length} changes` }}</button></div><div v-if="bulkError" class="desk-warning" role="alert">{{ bulkError }}<button v-if="pendingBatch" class="desk-link" :disabled="bulkBusy || !online" @click="submitBulk">Reconcile / retry exact batch</button></div><ul v-if="bulkResults.length" class="desk-bulk-results" aria-live="polite"><li v-for="(result, index) in bulkResults" :key="result.reference || index"><strong>{{ result.reference }}</strong> · {{ result.success ? 'Decision recorded' : 'Not completed' }}<p>{{ result.message || result.error || result.receipt?.message || result.receipt?.error || (result.success ? 'See the saved receipt for execution status.' : 'Re-open this item before trying again.') }}</p></li></ul></ReviewDialog>
        <ReviewDialog v-model="copyOpen" title="Select and copy" @opened="copyInput?.select()"><p class="desk-help">Clipboard access is unavailable. This text stays here until you close it.</p><textarea ref="copyInput" :value="copyValue" readonly rows="6" aria-label="Text to copy"></textarea></ReviewDialog>
        <ReviewDialog v-model="overrideOpen" title="Confirm this exact action" :busy="busy"><p class="desk-help">{{ active?.reference }} · revision {{ shortRevision(active?.revision) }}</p><p>{{ active?.effect }}</p><p class="desk-override-content">{{ readableValue(active?.override_content || active?.proposed) }}</p><label for="desk-override-note">Reason for this scoped override</label><textarea id="desk-override-note" v-model="overrideNote" rows="3" maxlength="4000"></textarea><label class="desk-override-check"><input v-model="overrideConfirmed" type="checkbox"> Authorize only this displayed target, content and revision.</label><p v-if="overrideError" class="desk-warning">{{ overrideError }}</p><div class="desk-modal-actions"><button class="desk-button" :disabled="busy" @click="overrideOpen = false">Cancel</button><button class="desk-button primary" :disabled="busy || !online" @click="confirmOverride">Confirm scoped approval</button></div></ReviewDialog>
    </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../utils/api'
import ReviewDialog from '../components/review/ReviewDialog.vue'
import FacesUnidentified from '../components/faces/FacesUnidentified.vue'
import GlobalFaceIdentityEditor from '../components/review/GlobalFaceIdentityEditor.vue'
import ReviewEvidenceViewer from '../components/review/ReviewEvidenceViewer.vue'
import PhotoReviewEvidence from '../components/review/PhotoReviewEvidence.vue'
import { photoEvidenceSelector } from '../components/review/photoEvidenceSelector'
import { aiReviewHandoff, canBulkReview as serverBulkEligible, clearReviewRequest, currentFaceSourceProof, currentRegistryFaceSourceProof, evidenceUrl, normalizeFaceRegion, readableValue, readReviewDraft, readReviewRequest, reviewError, reviewImages, reviewRequestKey, reviewStorage, saveReviewDraft, saveReviewRequest } from '../utils/reviewDesk'
import { operatorPresentation, readinessLabel } from '../utils/reviewOperatorPresentation'

const route = useRoute()
const router = useRouter()
const tabStorage = reviewStorage()
function reviewMode(query) { return query.mode === 'photos' || (query.mode !== 'approvals' && typeof query.ref === 'string' && /^(?:face|registry_face):[1-9]\d*$/.test(query.ref)) ? 'photos' : 'approvals' }
const mode = ref(reviewMode(route.query))
const photoTotal = ref(null)
const photoReferences = ref([])
const photoQueue = ref(null)
const pendingPhotoAdvance = ref(null)
const query = ref('')
const type = ref(typeof route.query.type === 'string' ? route.query.type : '')
const items = ref([])
const total = ref(0)
const hasMore = ref(false)
const typeOptions = ref([])
const warnings = ref([])
const sourceLinks = ref([])
const active = ref(null)
function readyDecision(item) {
    return item?.status === 'pending' && item.readiness?.state === 'ready'
        && Array.isArray(item.actions) && item.actions.some(action => ['approve', 'reject'].includes(action))
}
const canShowActive = computed(() => active.value && (active.value.status !== 'pending' || readyDecision(active.value)
    || (active.value.review_type === 'registry_face' && active.value.status === 'pending')
    || (active.value.review_type === 'face' && Array.isArray(active.value.actions) && active.value.actions.some(action => ['refresh_region', 'link', 'false_detection'].includes(action)))))
const context = ref({})
const faceSourceProof = ref(null)
const dateEvidence = ref(null)
const datePreviewReady = ref(null)
const faceProofError = ref('')
const selectedRegion = ref(null)
const personQuery = ref('')
const personResults = ref([])
const selectedPerson = ref(null)
const personConfirmed = ref(false)
const personError = ref('')
let personRequest = 0
const historyRows = ref([])
const requestedReference = ref('')
const loading = ref(false)
const detailLoading = ref(false)
const listError = ref('')
const detailError = ref('')
const decisionError = ref('')
const stale = ref(false)
const busy = ref(false)
const online = ref(navigator.onLine)
const notice = ref('')
const imageReady = ref(false)
const mobileDetail = ref(Boolean(route.query.ref || route.query.unified_id || route.query.item || route.query.target_ref))
const actionDock = ref(null)
const actionDockHeight = ref(0)
let actionDockObserver
watch(actionDock, element => { actionDockObserver?.disconnect(); if (!element) { actionDockHeight.value = 0; return }; actionDockObserver = new ResizeObserver(() => { actionDockHeight.value = element.getBoundingClientRect().height }); actionDockObserver.observe(element); actionDockHeight.value = element.getBoundingClientRect().height }, { flush: 'post' })
const notes = ref('')
const reasonCode = ref('')
const draftState = ref('Optional · required for follow-up')
const noteInput = ref(null)
const queuePanel = ref(null)
const detailPanel = ref(null)
const selected = ref({})
const selection = computed(() => Object.values(selected.value))
const pendingRequest = ref(null)
const globalIdentityOpen = ref(false)
const globalIdentityFace = ref(null)
const lookupOpen = ref(false)
const lookupReference = ref('')
const lookupInput = ref(null)
const lookupError = ref('')
const copyOpen = ref(false)
const copyInput = ref(null)
const copyValue = ref('')
const bulkOpen = ref(false)
const bulkSnapshot = ref([])
const bulkNotes = ref('')
const bulkResults = ref([])
const bulkError = ref('')
const bulkBusy = ref(false)
const pendingBatch = ref(readReviewRequest(tabStorage, 'batch'))
const overrideOpen = ref(false)
const overrideConfirmed = ref(false)
const overrideNote = ref('')
const overrideError = ref('')
let queueRequest = 0
let detailRequest = 0
let searchTimer
let queueScroll = 0
let routeNavigation = false

const isPersonNameProposal = computed(() => active.value?.review_type === 'global_face_identity' && context.value.global_identity_review?.global_identity_decision?.operation === 'create_identity')
const isFace = computed(() => active.value?.review_type === 'face')
const isRegistryFace = computed(() => active.value?.review_type === 'registry_face')
const isPhotoFace = computed(() => isFace.value || isRegistryFace.value)
const isScanDate = computed(() => active.value?.review_type === 'scan_date_estimate')
const scanDateWriteRetry = computed(() => active.value?.scan_date_write_retry || null)
const authorizedRetryYear = computed(() => /^\d{4}/.exec(String(scanDateWriteRetry.value?.proposed_date || ''))?.[0] || null)
const canOfferDateWriteRetry = computed(() => active.value?.status === 'history' && isScanDate.value
    && active.value?.actions?.includes('retry_scan_year_write')
    && scanDateWriteRetry.value?.eligible === true
    && scanDateWriteRetry.value?.proposed_precision === 'year'
    && Number.isSafeInteger(scanDateWriteRetry.value?.date_decision_id)
    && scanDateWriteRetry.value?.date_decision_id > 0
    && isUuid(scanDateWriteRetry.value?.authorization_receipt_id)
    && isUuid(scanDateWriteRetry.value?.asset_uuid)
    && /^[a-f0-9]{64}$/.test(scanDateWriteRetry.value?.source_sha256 || ''))
const canRetryDateWrite = computed(() => canOfferDateWriteRetry.value && canDecide.value
    && dateEvidence.value?.source?.status === 'verified_current'
    && dateEvidence.value?.source?.source_sha256 === scanDateWriteRetry.value?.source_sha256
    && dateEvidence.value?.asset?.asset_uuid?.toLowerCase() === scanDateWriteRetry.value?.asset_uuid?.toLowerCase()
    && context.value.item?.details?.asset_uuid?.toLowerCase() === scanDateWriteRetry.value?.asset_uuid?.toLowerCase()
    && datePreviewReady.value?.source_sha256 === scanDateWriteRetry.value?.source_sha256
    && datePreviewReady.value?.asset_uuid?.toLowerCase() === scanDateWriteRetry.value?.asset_uuid?.toLowerCase())
const canWriteDate = computed(() => isScanDate.value && dateEvidence.value?.source?.status === 'verified_current'
    && (context.value.item?.details?.proposed_precision || 'year') === 'year'
    && /^[a-f0-9]{64}$/.test(dateEvidence.value?.source?.source_sha256 || '')
    && dateEvidence.value.source.source_sha256 === datePreviewReady.value?.source_sha256
    && dateEvidence.value.asset?.asset_uuid === context.value.item?.details?.asset_uuid
    && datePreviewReady.value?.asset_uuid === context.value.item?.details?.asset_uuid)
const isGlobalFace = computed(() => active.value?.review_type === 'global_face_identity' && context.value.global_identity_review?.global_identity_decision?.operation === 'confirm_face')
const globalFaceCrop = computed(() => {
    if (!isGlobalFace.value) return null
    const proof = context.value.global_identity_review?.selected_face_evidence
    const decision = context.value.global_identity_review?.global_identity_decision
    if (!proof || !Number.isSafeInteger(proof.face_id) || proof.face_id < 1 || proof.face_id !== decision.face_id
        || !/^[a-f0-9]{64}$/.test(proof.source_sha256 || '') || proof.source_sha256 !== decision.source_sha256
        || !/^[a-f0-9]{64}$/.test(proof.region_fingerprint || '')
        || proof.face_region_convention !== 'normalized_top_left' || proof.coordinate_basis !== 'encoded_pixels'
        || !normalizeFaceRegion(proof.region) || ['x','y','w','h'].some(axis => proof.region[axis] !== decision.region?.[axis])) return null
    const source = evidenceUrl(proof.crop_url, window.location.origin, true)
    if (!source) return null
    const url = new URL(source)
    if (url.pathname !== `/api/media/faces/registry-crop/${proof.face_id}` || url.hash
        || url.searchParams.getAll('source_sha256').length !== 1 || url.searchParams.get('source_sha256') !== proof.source_sha256
        || url.searchParams.getAll('region_fingerprint').length !== 1 || url.searchParams.get('region_fingerprint') !== proof.region_fingerprint
        || [...url.searchParams.keys()].some(key => !['source_sha256','region_fingerprint'].includes(key))) return null
    return source
})
const hasFaceCandidate = computed(() => context.value.face_review?.has_identity_candidate === true)
const faceSourceUnavailable = computed(() => isPhotoFace.value && !!faceProofError.value && !faceSourceProof.value)
const compactReview = computed(() => isPhotoFace.value || active.value?.review_type === 'global_face_identity')
const frontEffect = computed(() => {
    if (faceSourceUnavailable.value) return 'A face or person cannot be confirmed until the current photo is available.'
    if (isPersonNameProposal.value) return `Adds ${context.value.global_identity_review.canonical_name} to your person names. Photos and family trees stay unchanged.`
    if (isRegistryFace.value) return 'Choose a person you recognize. Review the naming proposal before approving it.'
    if (isGlobalFace.value) return 'Confirms this selected face for the proposed person.'
    if (isFace.value && allows('refresh_region') && (!context.value.face_review?.region_reviewed || hasUnsavedRegion.value)) return 'Saves this face box. You choose the person next.'
    if (isFace.value && allows('link')) return 'Confirms the person you choose for this saved face.'
    if (isFace.value && hasFaceCandidate.value && allows('approve')) return 'Confirms this face as the proposed person.'
    return active.value?.effect || 'This item requires its source-specific review before a change can be authorized.'
})
function queueQuestion(item) {
    if (item?.review_type === 'global_face_identity') {
        const proposal = item.context?.global_identity_review
        const operation = proposal?.global_identity_decision?.operation
        if (operation === 'create_identity' && proposal.canonical_name) return `Save person name: ${proposal.canonical_name}`
        if (operation === 'add_alias' && proposal.canonical_name && proposal.global_identity_decision.alias) return `Add alias ${proposal.global_identity_decision.alias} for ${proposal.canonical_name}`
        if (operation === 'map_genealogy' && proposal.canonical_name) return `Add family tree mapping for ${proposal.canonical_name}`
        if (item.current === 'No independent person record created by this proposal.' && typeof item.proposed === 'string' && item.proposed.startsWith('Canonical name: ')) return `Save person name: ${item.proposed.slice('Canonical name: '.length)}`
    }
    return item?.question || item?.title
}
const operatorView = computed(() => operatorPresentation(active.value))
function canBulkReview(item) { return item?.readiness?.state === 'ready' && serverBulkEligible(item) }
const images = computed(() => isGlobalFace.value ? (globalFaceCrop.value ? [{ src: globalFaceCrop.value, title: 'Selected current face' }] : []) : isPhotoFace.value ? (faceSourceProof.value ? [{ src: faceSourceProof.value.preview_url, title: 'Current source photo' }] : []) : reviewImages(active.value, context.value, window.location.origin))
const imageSource = computed(() => images.value[0]?.src)
const canDecide = computed(() => !busy.value && !bulkBusy.value && !detailLoading.value && online.value && !stale.value && !pendingRequest.value)
const selectedSuggestionIndex = ref(null)
const suggestionSelectionVersion = ref(0)
const anonymousRegions = computed(() => {
    const proof = faceSourceProof.value
    const packet = proof?.anonymous_suggestions
    if (!packet || packet.state !== 'available' || !/^[a-f0-9]{64}$/.test(proof.source_sha256 || '')
        || proof.coordinate_basis !== 'encoded_pixels' || proof.face_region_convention !== 'normalized_top_left'
        || packet.source_sha256 !== proof.source_sha256 || packet.face_region_convention !== 'normalized_top_left'
        || packet.coordinate_basis !== 'encoded_pixels' || !Array.isArray(proof.source_dimensions) || proof.source_dimensions.length !== 2
        || !proof.source_dimensions.every(value => Number.isSafeInteger(value) && value > 0)
        || !Array.isArray(packet.source_dimensions) || packet.source_dimensions.length !== 2
        || packet.source_dimensions.some((value, index) => value !== proof.source_dimensions[index])
        || !Array.isArray(packet.regions) || packet.regions.length > 100) return null
    const indices = new Set()
    for (const candidate of packet.regions) {
        const region = candidate?.face_region
        if (!Number.isSafeInteger(candidate?.face_index) || candidate.face_index < 0 || !Number.isSafeInteger(candidate.face_index + 1) || indices.has(candidate.face_index)
            || !region || Array.isArray(region) || Object.keys(region).length !== 4
            || ['x','y','w','h'].some(axis => typeof region[axis] !== 'number' || !Number.isFinite(region[axis]))
            || region.x < 0 || region.y < 0 || region.w <= 0 || region.h <= 0 || region.x + region.w > 1 || region.y + region.h > 1) return null
        indices.add(candidate.face_index)
    }
    return packet.regions
})
const canSelectSuggestion = computed(() => isFace.value && active.value?.status === 'pending' && allows('refresh_region') && canDecide.value && imageReady.value && !!anonymousRegions.value)
const hasUnsavedRegion = computed(() => !!selectedRegion.value && (context.value.face_review?.region_reviewed !== true
    || ['x','y','w','h'].some(axis => selectedRegion.value[axis] !== (isRegistryFace.value ? faceSourceProof.value.region : context.value.item?.face_region)?.[axis])))
function selectAnonymousRegion(index) {
    if (!canSelectSuggestion.value) return
    const candidate = anonymousRegions.value.find(region => region.face_index === index)
    if (!candidate) return
    selectedRegion.value = { ...candidate.face_region }
    selectedSuggestionIndex.value = index
    imageReady.value = false
    suggestionSelectionVersion.value++
}
const currentGlobalFace = computed(() => {
    if (!isPhotoFace.value || active.value?.status !== 'pending' || (!isRegistryFace.value && context.value.face_review?.region_reviewed !== true) || !faceSourceProof.value) return null
    const face = isRegistryFace.value ? faceSourceProof.value.global_face : context.value.face_review.global_face
    const evidence = face?.global_face_evidence
    if (!face || !evidence || !Number.isSafeInteger(face.face_id) || face.face_id < 1 || face.face_id !== evidence.face_id
        || face.file_status !== 'active' || ![true,1].includes(face.source_current) || face.superseded_at || face.hidden
        || !/^[a-f0-9]{64}$/.test(face.source_sha256 || '') || face.source_sha256 !== faceSourceProof.value.source_sha256 || evidence.source_sha256 !== face.source_sha256
        || face.face_region_convention !== 'normalized_top_left' || evidence.face_region_convention !== face.face_region_convention
        || evidence.coordinate_basis !== 'encoded_pixels' || !normalizeFaceRegion(evidence.region) || Object.keys(evidence.region).length !== 4
        || evidence.region.x + evidence.region.w > 1 || evidence.region.y + evidence.region.h > 1
        || ['x','y','w','h'].some(axis => typeof evidence.region[axis] !== 'number' || evidence.region[axis] !== (isRegistryFace.value ? faceSourceProof.value.region : context.value.item?.face_region)?.[axis])) return null
    const source = evidenceUrl(evidence.crop_url, window.location.origin, true)
    if (!source || !/^[a-f0-9]{64}$/.test(evidence.region_fingerprint || '')) return null
    const url = new URL(source)
    if (url.pathname !== `/api/media/faces/registry-crop/${face.face_id}` || url.hash || [...url.searchParams.keys()].length !== 2
        || url.searchParams.getAll('source_sha256').length !== 1 || url.searchParams.get('source_sha256') !== face.source_sha256
        || url.searchParams.getAll('region_fingerprint').length !== 1 || url.searchParams.get('region_fingerprint') !== evidence.region_fingerprint) return null
    return face
})
const canNameFace = computed(() => canDecide.value && imageReady.value && !hasUnsavedRegion.value && !!currentGlobalFace.value)
const approvalLabel = computed(() => active.value?.review_type === 'global_face_identity' && context.value.global_identity_review?.global_identity_decision?.operation === 'create_identity' ? 'Save this person name' : active.value?.approve_label || context.value.face_review?.approval_label || 'Approve change')
const browseReferences = computed(() => mode.value === 'photos' ? photoReferences.value : items.value.map(item => item.reference))
const activeQueueIndex = computed(() => browseReferences.value.indexOf(active.value?.reference))
function browseItem(direction) { const reference = browseReferences.value[activeQueueIndex.value + direction]; if (!busy.value && !detailLoading.value && activeQueueIndex.value >= 0 && reference) selectItem(reference, true) }
async function changeMode(value) { if (busy.value || detailLoading.value || bulkBusy.value) return; pendingPhotoAdvance.value = null; mode.value = value; active.value = null; mobileDetail.value = false; detailRequest++; await routeTo(null); if (value === 'approvals') await loadItems(false) }
async function advanceToVerifiedPhoto() {
    const pending = pendingPhotoAdvance.value
    if (!pending) return
    if (mode.value !== 'photos' || detailRequest !== pending.request) { pendingPhotoAdvance.value = null; return }
    if (!photoReferences.value.length) return
    pendingPhotoAdvance.value = null
    await selectItem(photoReferences.value[0], pending.focus)
}
watch(photoReferences, advanceToVerifiedPhoto, { flush: 'post' })
async function loadPhotoCount() { try { const result = await api.get('/media/faces/unidentified', { params: { page: 1, per_page: 1, for_review: 1 } }); if (Number.isFinite(Number(result.pending_total ?? result.total))) photoTotal.value = Number(result.pending_total ?? result.total) } catch { photoTotal.value = null } }
const nativeSourceUrl = computed(() => evidenceUrl(active.value?.source_url, window.location.origin, true))
const evidenceSummary = computed(() => active.value?.evidence_summary || context.value.item?.summary || context.value.item?.details?.ai_reasoning || 'Inspect the source evidence and exact proposed change before deciding.')
const uncertainty = computed(() => active.value?.uncertainty || context.value.item?.details?.uncertainty || context.value.item?.details?.conflict_summary || '')
const fieldDiffs = computed(() => context.value.comparison?.field_diffs || [])
const evidenceFields = computed(() => {
    const details = context.value.item?.details || {}
    const labels = { source_title: 'Source', source_name: 'Source name', source_citation: 'Citation', citation: 'Citation', record_text: 'Record text', evidence_text: 'Evidence', transcript: 'Transcript', evidence_summary: 'Evidence summary', rationale: 'Rationale', ai_reasoning: 'AI assessment — not proof' }
    return Object.entries(labels).filter(([key]) => typeof details[key] === 'string' && details[key].trim()).map(([key, label]) => ({ label, value: details[key] }))
})
const evidenceLinks = computed(() => {
    const details = context.value.item?.details || {}
    const candidates = [...(context.value.media_refs || []), ...(active.value?.evidence_links || []), ...(context.value.research_fact?.source_urls || []).map((url, index) => ({ url, label: `Research source ${index + 1}` })), { url: details.source_url, label: 'Original source' }]
    const found = new Map()
    for (const candidate of candidates) {
        const url = evidenceUrl(candidate.url || candidate.media_url || candidate.view_url || candidate.source_url, window.location.origin)
        if (url) found.set(url, { url, label: candidate.label || candidate.title || candidate.name || 'Open source evidence' })
    }
    return [...found.values()]
})

function allows(action) {
    if (!active.value?.actions?.includes(action)) return false
    if (isRegistryFace.value && ['skip', 'followup'].includes(action) && (!faceSourceProof.value || !imageReady.value)) return false
    if (action === 'approve' && active.value.readiness?.state !== 'ready') return false
    if (action === 'approve' && isGlobalFace.value && (!globalFaceCrop.value || !imageReady.value)) return false
    if (isFace.value && action === 'approve' && !hasFaceCandidate.value) return false
    if (isFace.value && action === 'approve' && hasUnsavedRegion.value) return false
    if (isFace.value && action === 'reject' && hasUnsavedRegion.value) return false
    if (isFace.value && action === 'reject' && context.value.face_review?.rejection_ready !== true) return false
    return true
}
function shortRevision(revision) { return revision == null ? 'unknown' : String(revision).slice(0, 12) }
function warningText(value) { return typeof value === 'string' ? value.replaceAll('_', ' ') : value?.message || value?.error || value?.label || 'A review source is unavailable. Refresh before deciding.' }
const blockerLabels = { suggested_identity_missing: 'No suggested identity is available to confirm.', visible_image_missing: 'A source image was not returned for this review; check the media record before deciding.', detected_region_missing: 'A face region was not returned for this review; check the media record before deciding.', approval_route_unavailable: 'No identity approval route is available for this item.', missing_suggested_identity: 'No suggested identity is available to confirm.', missing_image: 'A source image was not returned for this review; check the media record before deciding.', missing_face_region: 'A face region was not returned for this review; check the media record before deciding.', missing_evidence: 'Supporting evidence is missing.', missing_source: 'The original source is missing.', stale_observation: 'This observation may be stale; verify current state.', unsupported_review_type: 'This item requires its source-specific review.' }
function blockerText(blocker) {
    const value = typeof blocker === 'string' ? blocker : blocker?.label || blocker?.message || blocker?.code
    if (!value) return 'Supporting evidence is unavailable.'
    if (blockerLabels[value]) return blockerLabels[value]
    if (/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/.test(value)) return `Review requirement: ${value.replaceAll('_', ' ')}. Verify the source before deciding.`
    return value
}
function actionUrl(url) { return evidenceUrl(url, window.location.origin) }
function statusLabel(value) { return ({ pending: 'Awaiting decision', followup: 'Follow-up requested', later: 'Skipped for now', history: 'Decision history', approve: 'Approved', reject: 'Denied', skip: 'Skipped for now', reopen: 'Returned to queue' })[value] || String(value || 'Recorded').replaceAll('_', ' ') }
function formatDate(value) { if (!value) return ''; const date = new Date(value); return Number.isNaN(date.getTime()) ? '' : date.toLocaleString() }
function itemLink(reference) { return new URL(router.resolve({ path: '/reviews', query: { ref: reference, ...(/^(?:face|registry_face):[1-9]\d*$/.test(reference) ? { mode: 'photos' } : {}) } }).href, window.location.origin).href }
async function identityProposalStaged(result) {
    notice.value = globalIdentityFace.value ? 'Face naming proposal staged. Review the selected face and person before approving.' : 'Person record proposal staged. Review the exact change below before approving.'
    await loadItems(false)
    await selectItem(result.reference || result.unified_id, true)
}
function closeGlobalIdentity() { globalIdentityOpen.value = false; globalIdentityFace.value = null }
function openGlobalIdentity() { closeGlobalIdentity(); globalIdentityOpen.value = true }
function registryPreviewFailed(source) {
    if (!isRegistryFace.value || faceSourceProof.value?.preview_url !== source) return
    faceSourceProof.value = null; imageReady.value = false
    faceProofError.value = 'The current photo is unavailable. This face needs preparation before review.'
    photoQueue.value?.markUnavailable(`registry_face:${context.value.item.id}`)
}
async function openFaceIdentity() { if (!canNameFace.value) return; globalIdentityFace.value = currentGlobalFace.value; globalIdentityOpen.value = true; await nextTick(); document.querySelector('.identity-editor')?.scrollIntoView({ block: 'start' }) }
watch(() => [active.value?.reference, active.value?.revision], () => { if (globalIdentityFace.value) closeGlobalIdentity() })
watch(() => [active.value?.reference, active.value?.revision], () => { selectedRegion.value = null; selectedSuggestionIndex.value = null; suggestionSelectionVersion.value = 0 })
watch(() => route.query.ref, () => { selectedRegion.value = null; selectedSuggestionIndex.value = null; suggestionSelectionVersion.value = 0 })
watch(selectedRegion, () => { selectedSuggestionIndex.value = null; personRequest++; personQuery.value = ''; personResults.value = []; personError.value = ''; selectedPerson.value = null; personConfirmed.value = false }, { flush: 'sync' })
watch(canNameFace, ready => { if (!ready && globalIdentityFace.value) closeGlobalIdentity() })
async function routeTo(reference, push = false) {
    routeNavigation = true
    try { await router[push ? 'push' : 'replace']({ path: '/reviews', query: { ...(reference ? { ref: reference } : {}), ...(type.value ? { type: type.value } : {}), readiness: 'ready', ...(mode.value === 'photos' ? { mode: 'photos' } : {}) } }) }
    finally { routeNavigation = false }
}
async function loadItems(append = false) {
    const request = ++queueRequest
    loading.value = true; listError.value = ''
    try {
        const data = await api.get('/review-desk/items', { params: { status: 'pending', q: query.value, type: type.value, readiness: 'ready', offset: append ? items.value.length : 0, limit: 50 } })
        if (request !== queueRequest) return
        const returned = Array.isArray(data.items) ? data.items : []
        const loaded = returned.filter(readyDecision)
        items.value = append ? [...items.value, ...loaded.filter(item => !items.value.some(previous => previous.reference === item.reference))] : loaded
        const filteredResponse = loaded.length === returned.length
        total.value = filteredResponse ? Number(data.total || 0) : Math.min(Number(data.total || 0), items.value.length)
        hasMore.value = filteredResponse && data.has_more === true
        warnings.value = data.warnings || []
        const types = Array.isArray(data.types) ? data.types : Object.values(data.types || {})
        typeOptions.value = types.length
            ? types.filter(type => type.inspection_only !== true).map(entry => ({ value: entry.value || entry.name, label: entry.label || entry.name }))
            : [...new Map(items.value.map(item => [item.review_type, { value: item.review_type, label: item.category || item.review_type.replaceAll('_', ' ') }])).values()]
        sourceLinks.value = (data.sources || []).map(source => ({ ...source, label: source.label || source.source?.replaceAll('_', ' ') || 'Source-specific review', url: evidenceUrl(source.url || source.source_url, window.location.origin, true) })).filter(source => source.url)
        if (mode.value === 'approvals' && !append && !active.value && !route.query.ref && items.value.length && !matchMedia('(max-width:760px)').matches) await selectItem(items.value[0].reference, false, false)
    } catch (error) { if (request === queueRequest) listError.value = reviewError(error) }
    finally { if (request === queueRequest) loading.value = false }
}
async function selectItem(reference, focus = false, navigate = true) {
    if (!reference || busy.value || bulkBusy.value) return
    const request = ++detailRequest
    const changedReference = active.value?.reference !== reference
    if (focus) queueScroll = window.scrollY
    requestedReference.value = reference; detailLoading.value = true; detailError.value = ''; decisionError.value = ''; imageReady.value = false; stale.value = false
    faceSourceProof.value = null; faceProofError.value = ''; selectedRegion.value = null
    dateEvidence.value = null; datePreviewReady.value = null
    personRequest++; personQuery.value = ''; personResults.value = []; selectedPerson.value = null; personConfirmed.value = false; personError.value = ''
    try {
        const data = await api.get(`/review-desk/items/${encodeURIComponent(reference)}`)
        if (request !== detailRequest) return
        active.value = data.item || data
        context.value = data.context || active.value.context || {}
        if (['face', 'registry_face'].includes(active.value.review_type) && canShowActive.value) {
            const registry = active.value.review_type === 'registry_face'
            try {
                const proof = await api.get(`/media/${registry ? 'registry-face-source' : 'face-match-source'}/${context.value.item.id}/proof`)
                if (request !== detailRequest) return
                const currentProof = (registry ? currentRegistryFaceSourceProof : currentFaceSourceProof)(proof, context.value.item.id, window.location.origin)
                if (!currentProof) throw new Error('The current photo could not be verified. Reopen this photo before selecting a face.')
                faceSourceProof.value = currentProof
            } catch (error) { if (request === detailRequest) { faceProofError.value = reviewError(error); if (registry) photoQueue.value?.markUnavailable(`registry_face:${context.value.item.id}`) } }
        }
        historyRows.value = data.history || data.receipts || []
        reasonCode.value = ''; pendingRequest.value = readReviewRequest(tabStorage, active.value.reference)
        if (pendingRequest.value) decisionError.value = 'An earlier request has no confirmed outcome in this tab. Reconcile the exact saved request before deciding again.'
        const draft = readReviewDraft(tabStorage, active.value.reference)
        notes.value = active.value.status === 'pending' ? draft?.notes || '' : historyRows.value.at(-1)?.notes || ''
        draftState.value = active.value.status !== 'pending' ? 'Recorded response · read-only' : draft ? (String(draft.revision) === String(active.value.revision) ? 'Draft restored in this tab' : 'Draft from earlier evidence — check before submitting') : 'Optional · required for follow-up'
        if (changedReference) { await nextTick(); if (detailPanel.value) detailPanel.value.scrollTop = 0 }
        if (focus) mobileDetail.value = true
        if (navigate) await routeTo(active.value.reference, focus)
        if (focus) { await nextTick(); document.getElementById('desk-decision-heading')?.focus({ preventScroll: true }); if (matchMedia('(max-width:760px)').matches) window.scrollTo(0, 0) }
    } catch (error) { if (request === detailRequest) { active.value = null; detailError.value = reviewError(error) } }
    finally { if (request === detailRequest) detailLoading.value = false }
}
function saveDraft() {
    if (!active.value || active.value.status !== 'pending') return
    const saved = saveReviewDraft(tabStorage, active.value.reference, active.value.revision, notes.value)
    draftState.value = saved ? 'Saved in this tab · cleared on logout' : 'Not saved to tab storage — keep this page open'
}
function scheduleSearch() { clearTimeout(searchTimer); searchTimer = setTimeout(filterChanged, 250) }
async function filterChanged() { if (busy.value || bulkBusy.value) return; selected.value = {}; active.value = null; detailRequest++; await routeTo(null); await loadItems(false) }
async function typeChanged() { await filterChanged() }
async function backToQueue() { mobileDetail.value = false; await routeTo(null); await nextTick(); window.scrollTo(0, queueScroll) }
function toggleSelection(item, checked) { if (!canBulkReview(item)) return; const next = { ...selected.value }; if (checked) next[item.reference] = { ...item }; else delete next[item.reference]; selected.value = next }
async function refresh() { await loadItems(false); if (active.value) await selectItem(active.value.reference, false, false) }
async function reloadEvidence() { const reference = active.value?.reference || requestedReference.value; await selectItem(reference, false, false) }
function networkChanged() { online.value = navigator.onLine; if (online.value) notice.value = 'Connection restored. No decision was replayed. Refresh evidence before trying again.' }
function openLookup() { if (busy.value || bulkBusy.value || document.querySelector('dialog[open]')) return; lookupReference.value = ''; lookupError.value = ''; lookupOpen.value = true }
async function findReference() {
    const reference = lookupReference.value.trim()
    if (!reference) { lookupError.value = 'Enter a review reference.'; return }
    await selectItem(reference, true)
    if (detailError.value) lookupError.value = detailError.value
    else lookupOpen.value = false
}
async function copy(text) {
    try { await navigator.clipboard.writeText(text); notice.value = 'Copied. Lookup does not grant approval authority.' }
    catch { copyValue.value = text; copyOpen.value = true }
}
function decisionScope(action) {
    if (isFace.value && action === 'false_detection') return 'dismiss_false_face_detection'
    if (isFace.value && action === 'refresh_region') return 'review_current_face_region'
    if (isFace.value && action === 'link') return 'confirm_face_identity_link'
    if (isFace.value) return action === 'approve' ? context.value.face_review?.decision_scope : context.value.face_review?.rejection_scope
    if (action === 'reject' && active.value?.rejection_scope) return active.value.rejection_scope
    return active.value?.decision_scope || (active.value?.review_type === 'scan_date_estimate' ? 'catalog_date_only' : undefined)
}
function isUuid(value) { return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value) }
async function saveCurrentRegion() {
    if (!selectedRegion.value || !faceSourceProof.value || !imageReady.value) return
    await decide('refresh_region', { region: { ...selectedRegion.value }, source_sha256: faceSourceProof.value.source_sha256, face_region_convention: 'normalized_top_left' })
}
async function dismissSelectedRegion() {
    if (!selectedRegion.value || !faceSourceProof.value || !imageReady.value) return
    await decide('false_detection', { region: { ...selectedRegion.value }, source_sha256: faceSourceProof.value.source_sha256, face_region_convention: 'normalized_top_left', reason_code: 'false_detection' })
}
async function searchFacePersons() {
    const request = ++personRequest
    selectedPerson.value = null; personConfirmed.value = false; personError.value = ''
    const treeId = context.value.item?.tree_id
    const query = personQuery.value.trim()
    if (!treeId || query.length < 2) { personResults.value = []; return }
    try {
        const data = await api.get(`/genealogy/trees/${treeId}/persons/search`, { params: { q: query, limit: 20 } })
        if (request !== personRequest) return
        personResults.value = data.data?.persons || []
    } catch (error) { if (request === personRequest) { personError.value = reviewError(error); personResults.value = [] } }
}
async function linkSelectedPerson() {
    if (!selectedPerson.value || !personConfirmed.value || !imageReady.value || hasUnsavedRegion.value) return
    await decide('link', { person_id: selectedPerson.value.id })
}
watch(selectedPerson, () => { personConfirmed.value = false })
async function writeDateYear() {
    if (!canWriteDate.value || !canDecide.value) return
    await decide('approve', { decision_scope: 'write_scan_estimated_year', source_sha256: dateEvidence.value.source.source_sha256, asset_uuid: dateEvidence.value.asset.asset_uuid })
}
async function retryApprovedYearWrite() {
    if (!canRetryDateWrite.value) return
    const binding = scanDateWriteRetry.value
    await decide('retry_scan_year_write', {
        decision_scope: 'retry_original_authorized_scan_estimated_year',
        date_decision_id: binding.date_decision_id,
        authorization_receipt_id: binding.authorization_receipt_id,
        asset_uuid: binding.asset_uuid,
        source_sha256: binding.source_sha256,
        notes: '',
    })
}
async function decide(action, extra = {}) {
    if (!active.value || !allows(action) || !online.value || busy.value || stale.value || pendingRequest.value) return
    if (action === 'approve' && isGlobalFace.value && (!globalFaceCrop.value || !imageReady.value)) return
    if (action === 'followup' && !notes.value.trim()) { decisionError.value = 'Add what you want checked in the response field.'; noteInput.value?.focus(); return }
    if (action === 'reject' && active.value.review_type === 'scan_date_estimate' && !reasonCode.value) { decisionError.value = 'Choose a rejection reason before denying this estimate.'; return }
    const request = { reference: active.value.reference, body: { action, revision: active.value.revision, idempotency_key: reviewRequestKey(), notes: notes.value.trim(), decision_scope: decisionScope(action), reason_code: reasonCode.value || undefined, ...extra } }
    if (!saveReviewRequest(tabStorage, request.reference, request)) { decisionError.value = 'Tab storage is unavailable. Enable it before submitting so an interrupted decision can be safely reconciled.'; return }
    pendingRequest.value = request
    await submitDecision(request)
}
async function submitDecision(request) {
    busy.value = true; decisionError.value = ''
    try {
        const data = await api.post(`/review-desk/items/${encodeURIComponent(request.reference)}/decisions`, request.body)
        if (data.success === false) throw { response: { data, status: data.status_code || 422 } }
        saveReviewDraft(tabStorage, request.reference, request.body.revision, '')
        clearReviewRequest(tabStorage, request.reference)
        pendingRequest.value = null; overrideOpen.value = false
        notice.value = `${request.reference}: ${data.receipt?.message || 'Decision recorded. Check history for execution status.'}`
        const completionMode = mode.value, completionRequest = detailRequest
        busy.value = false
        if (request.body.action === 'refresh_region') {
            notice.value = 'Current region saved. Choose and confirm the person separately.'
            await selectItem(request.reference, false, false)
            await loadItems(false)
            return
        }
        if (completionMode === 'photos') {
            active.value = null; await routeTo(null)
            if (mode.value !== completionMode || detailRequest !== completionRequest) return
            mobileDetail.value = false
            await photoQueue.value?.reload()
            if (mode.value !== completionMode || detailRequest !== completionRequest) return
            pendingPhotoAdvance.value = { request: completionRequest, focus: true }
            await advanceToVerifiedPhoto()
            return
        }
        active.value = null; await routeTo(null)
        if (mode.value !== completionMode || detailRequest !== completionRequest) return
        await loadItems(false)
        if (mode.value !== completionMode || detailRequest !== completionRequest) return
        if (items.value.length) await selectItem(items.value[0].reference, mobileDetail.value)
        else mobileDetail.value = false
    } catch (error) {
        const code = error.response?.status
        const receipt = error.response?.data?.receipt
        if (!error.response || code >= 500 || ['processing', 'unknown'].includes(receipt?.state)) decisionError.value = receipt?.error || 'The result is not confirmed. Do not assume it failed or succeeded. Reconcile by retrying this exact request.'
        else {
            clearReviewRequest(tabStorage, request.reference); pendingRequest.value = null
            if (code === 409) { stale.value = true; decisionError.value = `${reviewError(error)} Your note is preserved. Load current evidence before deciding.` }
            else decisionError.value = receipt?.error || reviewError(error)
        }
    } finally { busy.value = false }
}
function retryDecision() { if (pendingRequest.value && online.value && !busy.value) submitDecision(pendingRequest.value) }
function beginApproval() {
    if (isGlobalFace.value && (!globalFaceCrop.value || !imageReady.value)) return
    if (active.value?.requires_override) { overrideNote.value = notes.value; overrideConfirmed.value = false; overrideError.value = ''; overrideOpen.value = true }
    else decide('approve')
}
function confirmOverride() {
    if (!overrideConfirmed.value || !overrideNote.value.trim()) { overrideError.value = 'Add a reason and confirm this exact scope.'; return }
    notes.value = overrideNote.value; saveDraft()
    decide('approve', { operator_override: { reference: active.value.reference, revision: active.value.revision, confirmed: true, reason: overrideNote.value.trim() } })
}
function openBulk() {
    if (busy.value || bulkBusy.value) return
    bulkSnapshot.value = pendingBatch.value?.snapshot || selection.value.filter(item => canBulkReview(item)).map(item => ({ ...item, idempotency_key: reviewRequestKey() }))
    if (!bulkSnapshot.value.length) return
    bulkNotes.value = pendingBatch.value?.body.notes || ''; bulkResults.value = []; bulkError.value = pendingBatch.value ? 'An earlier batch has an unconfirmed outcome. Reconcile these exact requests; no changes are replayed automatically.' : ''; bulkOpen.value = true
}
async function submitBulk() {
    if (bulkBusy.value || !online.value) return
    if (!pendingBatch.value) {
        const batch = { snapshot: bulkSnapshot.value, body: { action: 'approve', items: bulkSnapshot.value.map(item => ({ reference: item.reference, revision: item.revision, idempotency_key: item.idempotency_key })), notes: bulkNotes.value.trim() } }
        if (!saveReviewRequest(tabStorage, 'batch', batch)) { bulkError.value = 'Tab storage is unavailable. Enable it before submitting to preserve safe retry keys.'; return }
        pendingBatch.value = batch
    }
    bulkBusy.value = true; bulkError.value = ''
    try {
        const data = await api.post('/review-desk/batch', pendingBatch.value.body)
        bulkResults.value = data.results || []
        const unresolved = pendingBatch.value.body.items.some(item => !bulkResults.value.some(result => result.reference === item.reference && (result.success || (result.status < 500 && !['processing', 'unknown'].includes(result.receipt?.state)) || ['completed', 'failed'].includes(result.receipt?.state))))
        if (unresolved) bulkError.value = 'Some outcomes remain unconfirmed. Reconcile this exact batch; completed requests will not execute twice.'
        else { clearReviewRequest(tabStorage, 'batch'); pendingBatch.value = null }
        const next = { ...selected.value }
        for (const result of bulkResults.value) if (result.success) { delete next[result.reference]; saveReviewDraft(tabStorage, result.reference, '', '') }
        selected.value = next; bulkBusy.value = false; await loadItems(false)
        if (active.value) await selectItem(active.value.reference, false, false)
    } catch (error) { bulkError.value = `${reviewError(error)} Do not assume the whole batch failed; retry uses the same item request keys.` }
    finally { bulkBusy.value = false }
}
watch(() => [route.query.mode, route.query.ref], () => { mode.value = reviewMode(route.query) })
watch(() => route.query.ref, (reference) => {
    if (routeNavigation) return
    if (!reference) { mobileDetail.value = false; return }
    if (reference !== active.value?.reference) selectItem(String(reference), true, false)
})
onMounted(async () => {
    window.addEventListener('online', networkChanged); window.addEventListener('offline', networkChanged); window.addEventListener('plos:review-lookup', openLookup)
    const reference = route.query.ref || route.query.unified_id || route.query.item || route.query.target_ref
    if (reference) await selectItem(String(reference), true)
    else await routeTo(null)
    await Promise.all([loadItems(false), loadPhotoCount()])
})
onBeforeUnmount(() => { actionDockObserver?.disconnect(); queueRequest++; detailRequest++; clearTimeout(searchTimer); window.removeEventListener('online', networkChanged); window.removeEventListener('offline', networkChanged); window.removeEventListener('plos:review-lookup', openLookup) })
</script>

<style scoped>
.review-desk { max-width: 1540px; margin: auto; color: var(--desk-ink); }
.desk-photo-entry { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; padding: 16px 18px; border: 1px solid var(--desk-line); border-radius: 10px; background: var(--desk-teal-soft); }
.desk-photo-entry strong { font-size: 16px; }
.desk-photo-entry p { margin: 5px 0 0; color: var(--desk-muted); font-size: 13px; line-height: 1.5; }
.desk-photo-entry > button { flex-shrink: 0; }
.desk-modes { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.desk-modes button[aria-pressed=true] { border-color: var(--desk-teal); background: var(--desk-teal-soft); color: var(--desk-teal); }
.desk-modes span { margin-left: 3px; }
.desk-browse { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 18px; border-bottom: 1px solid var(--desk-line); }
.desk-browse span { font-size: 12px; color: var(--desk-muted); }
@media (max-width: 760px) { .desk-photo-entry { display: grid; } .desk-photo-entry > button { width: 100%; } .desk-detail-open .desk-photo-entry { display: none; } .desk-browse span { order: 3; width: 100%; text-align: center; } }
.desk-page-heading { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 12px 0 24px; }
.desk-eyebrow { color: var(--desk-teal); font-size: 10px; font-weight: 750; letter-spacing: 1.4px; margin-bottom: 8px; }
.desk-page-heading h1 { font-size: clamp(25px, 2.2vw, 34px); letter-spacing: -1px; text-transform: none; }
.desk-page-heading p:not(.desk-eyebrow) { color: var(--desk-muted); margin-top: 7px; font-size: 14px; }
.desk-button { min-height: 44px; padding: 10px 14px; display: inline-flex; align-items: center; justify-content: center; gap: 9px; background: var(--desk-paper); color: var(--desk-ink); border: 1px solid var(--desk-line); border-radius: 8px; font-weight: 650; font-size: 13px; text-transform: none; letter-spacing: normal; }
.desk-button.primary { background: var(--desk-teal); border-color: var(--desk-teal); color: white; }
.desk-button.primary:hover:not(:disabled) { background: var(--desk-teal-hover); }
.desk-button.deny { color: var(--desk-red); }
button:disabled { opacity: .55; cursor: not-allowed; }
kbd { color: var(--desk-muted); background: var(--desk-canvas); border: 1px solid var(--desk-line); border-radius: 4px; padding: 3px; font: 10px inherit; }
.desk-workspace { display: grid; grid-template-columns: 355px minmax(0, 1fr); align-items: stretch; height: max(540px, calc(100dvh - 185px)); max-height: 900px; min-height: 0; overflow: hidden; background: var(--desk-paper); border: 1px solid var(--desk-line); border-radius: 14px; box-shadow: 0 7px 28px #15344406; }
.desk-queue { background: var(--desk-subtle); align-self: stretch; min-width: 0; min-height: 0; overflow-y: auto; overscroll-behavior: contain; border-right: 1px solid var(--desk-line); border-radius: 14px 0 0 14px; }
.desk-queue-heading { padding: 23px 22px 16px; display: flex; justify-content: space-between; align-items: center; }
.desk-queue-heading h2 { font-size: 18px; text-transform: none; letter-spacing: -.3px; }
.desk-queue-heading > span { font-size: 12px; background: var(--desk-canvas); border-radius: 20px; padding: 3px 9px; color: var(--desk-muted); }
.desk-status-tabs { display: flex; padding: 0 12px; border-bottom: 1px solid var(--desk-line); }
.desk-status-tabs button { flex: 1; min-height: 44px; padding: 10px 3px; border-bottom: 3px solid transparent; font-size: 11px; color: var(--desk-muted); white-space: nowrap; }
.desk-status-tabs button[aria-pressed="true"] { color: var(--desk-teal); border-bottom-color: var(--desk-teal); font-weight: 750; }
.desk-lane-summary { margin: 10px 16px 0; color: var(--desk-muted); font-size: 11px; line-height: 1.5; }
.desk-readiness-filter { padding: 10px 14px 0; display: grid; gap: 8px; }
.desk-readiness-filter > div { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; }
.desk-readiness-filter strong { flex-basis: 100%; color: var(--desk-muted); font-size: 10px; font-weight: 700; }
.desk-readiness-filter button { min-height: 35px; padding: 5px 8px; border: 1px solid var(--desk-line); border-radius: 7px; color: var(--desk-muted); background: var(--desk-paper); font-size: 11px; text-align: left; }
.desk-readiness-filter button[aria-pressed="true"] { color: var(--desk-teal); border-color: var(--desk-teal); background: var(--desk-teal-soft); font-weight: 700; }
.desk-readiness-filter span { font-variant-numeric: tabular-nums; }
.desk-readiness-filter .desk-all-lane { justify-self: start; }
.desk-filters { padding: 16px 18px 10px; display: grid; gap: 8px; }
.desk-filters input, .desk-filters select { font-size: 12px; }
.desk-queue-hint, .desk-queue-foot { color: var(--desk-muted); padding: 0 20px 12px; font-size: 11px; line-height: 1.6; }
.desk-queue-foot { padding: 18px 20px; }
.desk-items { padding: 0 10px; }
.desk-queue-item { display: flex; align-items: center; background: var(--desk-paper); border: 1px solid transparent; border-radius: 9px; margin-bottom: 6px; }
.desk-queue-item.selected { background: var(--desk-teal-soft); border-color: #afd2c4; box-shadow: inset 3px 0 var(--desk-teal); }
.desk-item-select { min-width: 42px; min-height: 60px; display: grid; place-items: center; }
.desk-item-select input { width: 20px; height: 20px; accent-color: var(--desk-teal); }
.desk-item-open { width: 100%; min-width: 0; text-align: left; padding: 15px 14px; border-radius: 9px; }
.desk-item-select + .desk-item-open { padding-left: 0; }
.desk-item-open strong { font-size: 13px; line-height: 1.5; display: block; overflow-wrap: anywhere; }
.desk-item-area { font-size: 10px; color: var(--desk-muted); display: block; margin-bottom: 7px; }
.desk-item-title { display: block; font-size: 12px; color: var(--desk-muted); margin-top: 6px; overflow-wrap: anywhere; }
.desk-item-meta { display: flex; gap: 7px; align-items: center; flex-wrap: wrap; font-size: 10px; color: var(--desk-muted); margin-top: 8px; overflow-wrap: anywhere; }
.desk-risk { border-radius: 4px; background: var(--desk-amber-soft); color: var(--desk-amber); padding: 3px 6px; font-size: 10px; }
.desk-risk.routine { color: var(--desk-teal); background: var(--desk-teal-soft); }
.desk-more { margin: 12px 20px; }
.desk-bulk-bar { position: sticky; bottom: 0; background: var(--desk-teal-soft); padding: 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 12px; border-top: 1px solid var(--desk-line); }
.desk-detail { min-width: 0; min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
.desk-detail-main { padding: 25px 30px 18px; }
.desk-back { display: none; }
.desk-item-topline { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; color: var(--desk-muted); font-size: 11px; margin-bottom: 18px; }
.desk-ref { margin-left: auto; padding: 8px; min-height: 44px; border: 1px solid var(--desk-line); border-radius: 8px; font-family: ui-monospace, monospace; font-size: 11px; overflow-wrap: anywhere; }
.desk-question { font-size: clamp(23px, 2vw, 29px); text-transform: none; letter-spacing: -.6px; line-height: 1.3; margin-bottom: 10px; }
.desk-effect { font-size: 14px; color: var(--desk-muted); line-height: 1.65; margin-bottom: 20px; }
.desk-effect strong { color: var(--desk-ink); }
.desk-comparison { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid var(--desk-line); border-radius: 10px; overflow: hidden; margin-bottom: 20px; }
.desk-comparison > div { padding: 15px 17px; min-width: 0; }
.desk-comparison > div + div { border-left: 1px solid var(--desk-line); background: var(--desk-teal-soft); }
.desk-comparison span { display: block; font-size: 10px; letter-spacing: .9px; text-transform: uppercase; color: var(--desk-muted); margin-bottom: 7px; font-weight: 750; }
.desk-comparison strong { font-size: 14px; overflow-wrap: anywhere; }
.desk-operator-view { margin: 20px 0; padding: 18px; background: var(--desk-subtle); border: 1px solid var(--desk-line); border-radius: 10px; font-size: 13px; line-height: 1.65; }
.desk-operator-view h3 { font-size: 16px; margin-bottom: 8px; text-transform: none; }
.desk-operator-view h4 { font-size: 14px; margin: 14px 0 7px; text-transform: none; }
.desk-operator-view p, .desk-operator-view dd { white-space: pre-wrap; overflow-wrap: anywhere; }
.desk-operator-view .desk-observed { color: var(--desk-amber); margin-top: 8px; }
.desk-operator-view .desk-omission { color: var(--desk-amber); margin-top: 8px; }
.desk-operator-details { font-size: 13px; line-height: 1.65; padding-bottom: 12px; }
.desk-operator-details .desk-omission { color: var(--desk-amber); margin: 8px 0; }
.desk-operator-details h4 { font-size: 14px; margin: 14px 0 7px; text-transform: none; }
.desk-operator-details dd { white-space: pre-wrap; overflow-wrap: anywhere; }
.desk-operator-section { border-top: 1px solid var(--desk-line); margin-top: 14px; }
.desk-operator-section dl { display: grid; grid-template-columns: minmax(120px, 28%) minmax(0, 1fr); gap: 8px 14px; }
.desk-operator-section dt { font-weight: 700; }
.desk-operator-section dd { color: var(--desk-muted); margin: 0; }
.desk-proposed-actions { border-top: 1px solid var(--desk-line); margin-top: 14px; }
.desk-proposed-actions ol { padding-left: 20px; }
.desk-proposed-actions li { padding: 10px 0; border-bottom: 1px solid var(--desk-line); }
.desk-proposed-actions li p { margin-top: 5px; }
.desk-proposed-actions a { display: inline-flex; align-items: center; min-height: 44px; color: var(--desk-teal); }
.desk-image-gallery { display: grid; gap: 16px; min-width: 0; align-content: start; }
.desk-evidence-grid { display: grid; gap: 20px; margin: 20px 0; }
.desk-evidence-grid.has-image { grid-template-columns: minmax(200px, 1.1fr) minmax(170px, 1fr); }
.desk-evidence-summary h3 { font-size: 15px; text-transform: none; letter-spacing: normal; margin-bottom: 10px; }
.desk-evidence-summary p { font-size: 13px; color: var(--desk-muted); line-height: 1.7; white-space: pre-wrap; overflow-wrap: anywhere; }
.desk-evidence-summary .desk-uncertainty { border-left: 3px solid #daa557; background: var(--desk-amber-soft); color: var(--desk-amber); padding: 10px; border-radius: 0 6px 6px 0; margin-top: 12px; }
.desk-disclosure { border-top: 1px solid var(--desk-line); }
.desk-disclosure summary { min-height: 44px; padding: 13px 0; font-size: 12px; font-weight: 650; cursor: pointer; }
.desk-disclosure summary > span { float: right; font-size: 11px; color: var(--desk-muted); font-weight: 400; }
.desk-evidence-fields dt { font-size: 12px; font-weight: 700; margin-top: 12px; }
.desk-evidence-fields dd { font-size: 13px; white-space: pre-wrap; line-height: 1.7; color: var(--desk-muted); margin: 4px 0 15px; overflow-wrap: anywhere; }
.desk-source-links { list-style: disc; padding: 12px 20px; font-size: 13px; }
.desk-diff-scroll { overflow-x: auto; max-width: 100%; margin-bottom: 15px; }
.desk-diff-scroll table { width: 100%; font-size: 12px; border-collapse: collapse; }
.desk-diff-scroll th, .desk-diff-scroll td { padding: 10px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--desk-line); }
.desk-notes { margin-top: 20px; }
.desk-notes > div { display: flex; align-items: baseline; gap: 10px; justify-content: space-between; flex-wrap: wrap; margin-bottom: 8px; }
.desk-notes label { font-size: 12px; font-weight: 650; }
.desk-notes span { color: var(--desk-muted); font-size: 10px; }
.review-desk textarea, .review-desk input:not([type="checkbox"]):not([type="radio"]), .review-desk select { background: var(--desk-paper); color: var(--desk-ink); border: 1px solid var(--desk-line); border-radius: 8px; padding: 11px 12px; width: 100%; min-height: 44px; }
.review-desk textarea { font-size: 13px; line-height: 1.6; resize: vertical; }
.desk-reason { display: grid; gap: 6px; margin-top: 12px; font-size: 12px; }
.desk-actions { position: sticky; bottom: 0; padding: 16px 30px; border-top: 1px solid var(--desk-line); background: var(--desk-paper); display: flex; gap: 8px; flex-wrap: wrap; z-index: 2; border-radius: 0 0 14px 0; }
.desk-actions .desk-button { flex: 1; font-size: 12px; }
.desk-actions .primary { flex: 1.5; }
.desk-actions p { width: 100%; font-size: 10px; color: var(--desk-muted); line-height: 1.6; }
.desk-link { min-height: 44px; padding: 8px; color: var(--desk-teal); text-decoration: underline; text-underline-offset: 3px; font-size: 12px; }
.desk-empty, .desk-load-error { padding: 28px 22px; font-size: 14px; line-height: 1.7; color: var(--desk-muted); }
.desk-empty h2 { text-transform: none; font-size: 20px; letter-spacing: normal; }
.desk-load-error { color: var(--desk-red); }
.desk-load-error button { margin-top: 12px; }
.desk-warning, .desk-notice, .desk-receipt { border: 1px solid var(--desk-line); padding: 13px 16px; border-radius: 8px; margin-bottom: 15px; font-size: 13px; line-height: 1.65; }
.desk-warning { background: var(--desk-amber-soft); color: var(--desk-amber); }
.desk-notice, .desk-receipt { background: var(--desk-teal-soft); color: var(--desk-teal); }
.desk-receipt button { margin-top: 8px; }
.desk-help { font-size: 13px; color: var(--desk-muted); line-height: 1.7; margin: 10px 0; }
.desk-history { padding: 0 0 0 20px; font-size: 12px; line-height: 1.6; }
.desk-date-write-retry { margin: 20px 0; padding: 16px; border: 1px solid var(--desk-line); border-radius: 12px; background: var(--desk-paper); }
.desk-date-write-retry h3 { margin: 0 0 8px; color: var(--desk-ink); font-size: 16px; }
.desk-date-write-retry p { margin: 8px 0; }
.desk-history li { margin: 12px 0; }
.desk-history li > span { margin-left: 12px; color: var(--desk-muted); }
.desk-history p { white-space: pre-wrap; overflow-wrap: anywhere; }
.desk-source-button { display: inline-flex; align-items: center; min-height: 44px; font-size: 13px; color: var(--desk-teal); }
.desk-source-directory { margin-top: 20px; padding: 15px; border: 1px solid var(--desk-line); border-radius: 10px; font-size: 13px; }
.desk-source-directory summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; font-weight: 650; }
.desk-source-directory a { display: inline-flex; min-height: 44px; align-items: center; margin-right: 22px; }
.desk-lookup-form { display: grid; gap: 10px; margin-top: 15px; }
.desk-bulk-preview { padding-left: 20px; }
.desk-bulk-preview li { padding: 13px 0; border-bottom: 1px solid var(--desk-line); font-size: 13px; }
.desk-bulk-preview span { display: block; color: var(--desk-muted); font-size: 11px; margin: 6px 0; }
.desk-bulk-results { padding: 10px 0; font-size: 13px; }
.desk-bulk-results li { padding: 10px 0; border-bottom: 1px solid var(--desk-line); }
.desk-modal-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
.desk-override-content { white-space: pre-wrap; margin: 15px 0; }
.desk-override-check { display: flex; align-items: center; gap: 10px; margin-top: 15px; font-size: 13px; }
.desk-sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
.desk-face-review { display: grid; gap: 12px; padding: 16px; margin: 16px 0; border: 1px solid var(--desk-line); border-radius: 8px; }
.desk-face-suggestions { display: grid; gap: 10px; }
.desk-face-suggestions h3 { margin: 0; font-size: 16px; }
.desk-face-suggestion-buttons { display: flex; flex-wrap: wrap; gap: 8px; }
.desk-face-suggestion-buttons [aria-pressed="true"] { background: var(--desk-teal-soft); border-color: var(--desk-teal); color: var(--desk-teal); }
.desk-person-input { width: 100%; min-height: 44px; padding: 10px; background: var(--desk-paper); border: 1px solid var(--desk-line); border-radius: 6px; color: var(--desk-ink); }
.desk-person-results { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.desk-person-results button { width: 100%; text-align: left; }
@media (max-width: 1100px) { .desk-workspace { grid-template-columns: 300px minmax(0, 1fr); } .desk-detail-main { padding: 20px; } .desk-evidence-grid.has-image { grid-template-columns: 1fr; } .desk-actions { padding: 15px 20px; } }
@media (max-width: 760px) { .desk-page-heading { display: block; padding: 5px 0 20px; } .desk-page-heading h1 { font-size: 28px; } .desk-page-heading .desk-button { margin-top: 16px; width: 100%; justify-content: space-between; } .desk-workspace { display: block; height: auto; max-height: none; min-height: 0; overflow: visible; } .desk-queue { border: 0; border-radius: 14px; overflow: visible; } .desk-detail { display: none; overflow: visible; } .desk-detail-open .desk-detail { display: block; } .desk-detail-open .desk-queue, .desk-detail-open .desk-page-heading { display: none; } .desk-back { display: block; width: 100%; min-height: 44px; padding: 12px 18px; text-align: left; border-bottom: 1px solid var(--desk-line); color: var(--desk-teal); font-size: 13px; } .desk-detail-main { padding: 16px 16px calc(var(--desk-action-height, 180px) + 16px); } .desk-ref { margin-left: 0; } .desk-question { font-size: 25px; } .desk-comparison > div { padding: 12px; } .desk-comparison strong { font-size: 13px; } .desk-operator-section dl { grid-template-columns: 1fr; gap: 2px; } .desk-operator-section dd { margin-bottom: 10px; } .desk-actions { padding: 12px 12px max(12px, env(safe-area-inset-bottom)); display: grid; grid-template-columns: 1fr 1fr; border-radius: 0 0 14px 14px; } .desk-actions .desk-button { min-height: 48px; padding: 10px 6px; } .desk-actions .primary { grid-column: 1/-1; } .desk-actions p { grid-column: 1/-1; } }
</style>

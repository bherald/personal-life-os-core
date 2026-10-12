<template>
  <section class="photo-review-evidence" aria-label="Photo metadata evidence" :aria-busy="loading">
    <h3>Photo evidence</h3>
    <p v-if="!normalizedSelector" class="pre-note">No exact photo identifier is available for this review.</p>
    <p v-else-if="loading" class="pre-note" role="status">Loading current photo evidence…</p>
    <p v-else-if="error" class="pre-note hold-note" role="alert">{{ error }}</p>
    <template v-else-if="bundle">
      <p v-if="bundle.source.status === 'held'" class="pre-note hold-note" role="status">
        Source evidence is held{{ bundle.source.error_code ? ` (${bundle.source.error_code})` : '' }}. No current source preview is available.
      </p>
      <p v-else-if="bundle.source.catalog_hash_state === 'different'" class="pre-note mismatch-note" role="status">
        The verified source hash differs from the catalog hash. This is an evidence mismatch; it is not a corruption finding.
      </p>
      <p v-else-if="bundle.source.catalog_hash_state === 'unknown'" class="pre-note mismatch-note" role="status">
        The current catalog hash could not be compared with the verified source.
      </p>
      <div class="asset-line">
        <strong>{{ bundle.asset.filename || 'Photo source' }}</strong>
        <span v-if="bundle.asset.file_registry_id">File registry {{ bundle.asset.file_registry_id }}</span>
        <span v-if="bundle.asset.asset_uuid">Asset {{ bundle.asset.asset_uuid }}</span>
      </div>
      <figure v-if="bundle.source.status === 'verified_current' && previewUrl" class="source-proof">
        <img :key="`${requestSequence}:${previewUrl}`" :src="previewUrl" :alt="`Verified current source: ${bundle.asset.filename || 'photo'}`" @load="previewLoaded" @error="previewFailed" />
        <figcaption v-if="previewError" class="preview-hold" role="alert">The pinned source preview did not load; visual review is held.</figcaption>
        <figcaption>Verified current source · {{ dimensionsLabel }}</figcaption>
      </figure>
      <section v-if="faceAdvice.length" class="evidence-block face-advice" aria-label="Technical face comparison advisory">
        <h4>Technical face comparison advisory</h4>
        <p class="meta-line">Uncalibrated model advice. Operator review is required; this does not confirm identity.</p>
        <article v-for="(advice, index) in faceAdvice" :key="index" class="face-row">
          <template v-if="advice.current">
            <p><b>Comparison proposal</b><span>{{ stateLabel(advice.item.decision) }} · {{ advice.item.confidence === null ? 'Confidence unavailable' : `Uncalibrated confidence ${Math.round(advice.item.confidence * 100)}%` }}</span></p>
            <p class="meta-line">{{ advice.item.provider }} · {{ advice.item.model }}<span v-if="advice.item.recorded_at"> · Recorded {{ advice.item.recorded_at }}</span></p>
            <div class="comparison-sources">
              <ReviewEvidenceViewer v-for="(source, sourceIndex) in advice.sources" :key="`${requestSequence}:${source.url}:${sourceIndex}`" :src="source.url" :region="source.region" :title="`Comparison source ${sourceIndex + 1}`" :reference="`File registry ${source.file_registry_id}`" :revision="source.source_sha256" />
            </div>
          </template>
          <p v-else class="review-flag" role="status">Comparison advice held: stale or invalid evidence. No comparison preview is loaded.</p>
        </article>
        <p v-if="bundle.face_comparisons?.truncated || bundle.face_comparisons?.items?.length > 10" class="limit-note">Comparison advice display is limited.</p>
      </section>
      <div class="evidence-grid">
        <section class="evidence-block">
          <h4>Descriptions</h4>
          <p><b>Stored catalog caption</b><span>{{ bundle.captions.source_caption?.text || 'None stored' }}</span></p>
          <p><b>Catalog description</b><span>{{ bundle.captions.catalog_description?.text || 'None stored' }}</span></p>
          <p><b>AI caption proposal · {{ captionInputState }}</b><span>{{ bundle.captions.generated?.text || 'No generated caption' }}</span></p>
          <p v-if="bundle.captions.generated?.version || bundle.captions.generated?.recorded_at" class="meta-line">
            <span v-if="bundle.captions.generated.version">Version {{ bundle.captions.generated.version }}</span>
            <span v-if="bundle.captions.generated.recorded_at"> · Recorded {{ bundle.captions.generated.recorded_at }}</span>
          </p>
        </section>
        <section class="evidence-block">
          <h4>Text recognition proposal</h4>
          <p><b>OCR · {{ stateLabel(bundle.ocr.input_state) }}</b><span>{{ bundle.ocr.text || 'No OCR text' }}</span></p>
          <p class="meta-line">{{ bundle.ocr.output_verified ? 'Stored text matches recorded checksum' : 'Stored text checksum unverified' }}<span v-if="bundle.ocr.provider"> · {{ bundle.ocr.provider }}</span></p>
        </section>
        <section v-for="field in ['caption', 'ocr']" :key="field" class="evidence-block field-review" :data-field="field">
          <h4>{{ field === 'caption' ? 'Caption' : 'OCR' }} operator review</h4>
          <template v-if="reviewed(field)">
            <p><b>Reviewed decision · {{ stateLabel(reviewed(field).state) }} · {{ stateLabel(reviewed(field).decision) }}</b><span>{{ reviewed(field).text ?? 'Proposal rejected; no reviewed text' }}</span></p>
            <p v-if="reviewed(field).evidence_changed" class="meta-line">Raw proposal evidence has changed since this decision. The reviewed value stays separate from that proposal.</p>
            <p v-if="reviewed(field).state !== 'current'" class="review-flag">Reviewed decision is not bound to the current physical source.</p>
          </template>
          <p v-else class="meta-line">No operator decision recorded.</p>
          <p class="meta-line">Save reviewed text in PLOS.</p>
          <template v-if="pending[field]">
            <p role="status">Unacknowledged {{ pending[field].body.photo_field_decision.decision }} request retained. Retry sends the same original intent and key; it does not assert that the decision applies to current bytes.</p>
            <button type="button" :disabled="!!writing" @click="submitField(field, null)">Retry exact {{ field }} request</button>
          </template>
          <template v-else>
            <label>Corrected {{ field }} text
              <textarea v-model="corrections[field]" rows="3" :maxlength="fieldTextLimit(field)" :disabled="!fieldReady(field) || !!writing" />
            </label>
            <div class="field-actions">
              <button type="button" :disabled="!fieldReady(field) || !bundle.field_review?.[field]?.can_accept || !!writing" @click="submitField(field, 'accept')">Accept generated {{ field }}</button>
              <button type="button" :disabled="!fieldReady(field) || !validCorrection(field) || !!writing" @click="submitField(field, 'correct')">Save corrected {{ field }}</button>
              <button type="button" :disabled="!fieldReady(field) || !!writing" @click="submitField(field, 'reject')">Reject {{ field }} proposal</button>
            </div>
            <p v-if="!fieldReady(field)" class="meta-line">New decisions require a current registered source, valid field evidence and a loaded pinned source preview.</p>
          </template>
          <p v-if="fieldErrors[field]" role="alert" class="review-flag">{{ fieldErrors[field] }}</p>
          <p v-if="acknowledgments[field]" role="status">{{ acknowledgments[field] }}</p>
        </section>
        <section class="evidence-block">
          <h4>Date information</h4>
          <p><b>Date taken</b><span>{{ dateTakenLabel }}</span></p>
          <p v-if="bundle.date.source || bundle.date.confidence"><b>Date evidence</b><span>{{ [bundle.date.source, bundle.date.confidence].filter(Boolean).join(' · ') }}</span></p>
          <p v-if="bundle.date.reasoning"><b>Reasoning</b><span>{{ bundle.date.reasoning }}</span></p>
          <p><b>Digitized date</b><span>{{ dateValue(bundle.date.scan_date) }}<template v-if="bundle.date.is_scan === true"> · scanned photo</template></span></p>
          <p v-if="bundle.date.needs_review === true" class="review-flag">Date needs review</p>
        </section>
        <section class="evidence-block face-block">
          <h4>Face labels and identity records</h4>
          <p v-if="!bundle.faces.length">No face records returned.</p>
          <article v-for="face in bundle.faces" :key="face.id" class="face-row">
            <p><b>Source label</b><span>{{ face.source_label || 'Unlabelled' }}</span></p>
            <p><b>Identity state</b><span>{{ stateLabel(face.identity_state) }} · source {{ stateLabel(face.source_state) }}</span></p>
            <p v-if="face.canonical_name"><b>{{ face.identity_state === 'operator_confirmed' ? 'Confirmed UUID name' : 'Unconfirmed canonical name' }}</b><span>{{ face.canonical_name }}</span></p>
            <figure v-if="cropUrl(face)" class="face-crop">
              <img :src="cropUrl(face)" :alt="`Current pinned face region ${face.id}`" @error="cropFailed(face.id)" />
              <figcaption>Current face region · source hash and region fingerprint matched</figcaption>
            </figure>
            <p v-else-if="face.source_state === 'current' && face.crop_url" class="crop-hold" role="status">
              {{ cropErrors[face.id] ? 'Current face crop failed to load; region review is held.' : 'Face crop proof unavailable; region review is held.' }}
            </p>
            <div v-for="mapping in face.tree_mappings || []" :key="`${mapping.tree_id}:${mapping.person_id}`" class="face-mapping">
              <b>Tree {{ mapping.tree_id }} person ID {{ mapping.person_id }}</b><span>{{ mapping.name || 'No name returned' }}</span>
            </div>
            <ul v-if="face.decisions?.length" class="decision-list"><li v-for="decision in face.decisions" :key="decision.id">{{ stateLabel(decision.status) }}<template v-if="decision.name"> · {{ decision.name }}</template><template v-if="decision.reviewed_at"> · {{ decision.reviewed_at }}</template></li></ul>
          </article>
        </section>
      </div>
      <p v-if="bundle.limits.faces_truncated || bundle.limits.decisions_truncated || bundle.limits.text_truncated" class="limit-note">
        Evidence display is limited<span v-if="bundle.limits.faces_truncated"> · face records truncated</span><span v-if="bundle.limits.decisions_truncated"> · decisions truncated</span><span v-if="bundle.limits.text_truncated"> · text truncated</span>.
      </p>
    </template>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import api from '../../utils/api'
import ReviewEvidenceViewer from './ReviewEvidenceViewer.vue'
import { normalizePhotoEvidenceSelector } from './photoEvidenceSelector'
import { reviewStorage, saveReviewRequest, readReviewRequest, clearReviewRequest } from '../../utils/reviewDesk'

const props = defineProps({ selector: { type: Object, default: null } })
const emit = defineEmits(['evidence-loaded', 'preview-ready'])
const loading = ref(false)
const error = ref('')
const bundle = ref(null)
const cropErrors = ref({})
const previewError = ref(false)
const normalizedSelector = computed(() => normalizePhotoEvidenceSelector(props.selector))
const selectorKey = computed(() => normalizedSelector.value ? JSON.stringify(normalizedSelector.value) : '')
const previewUrl = computed(() => safePreviewUrl(bundle.value?.source?.preview_url, bundle.value))
const faceAdvice = computed(() => {
  const data = bundle.value?.face_comparisons
  if (!data) return []
  if (data.schema !== 'plos.face_comparison_advice.v1' || !Array.isArray(data.items)) return [{ current: false }]
  return data.items.slice(0, 10).map(item => {
    const sources = Array.isArray(item?.source_evidence) && item.source_evidence.length === 2
      ? item.source_evidence.map(comparisonSource) : []
    const matchingDecision = item?.decision === 'same_person' ? item.same_person === true
      : item?.decision === 'different_person' ? item.same_person === false
      : item?.decision === 'abstain' && item.same_person === null
    const current = bundle.value?.source?.status === 'verified_current' && item?.state === 'current'
      && item.operator_review_required === true && matchingDecision
      && (item.confidence === null || (typeof item.confidence === 'number' && Number.isFinite(item.confidence) && item.confidence >= 0 && item.confidence <= 1))
      && sources.length === 2 && sources.every(Boolean)
    return { item, sources, current }
  })
})
const dimensionsLabel = computed(() => {
  const dimensions = bundle.value?.source?.source_dimensions
  return Array.isArray(dimensions) && dimensions.length === 2 ? `${dimensions[0]} × ${dimensions[1]} pixels` : 'dimensions unavailable'
})
const dateTakenLabel = computed(() => {
  const date = bundle.value?.date || {}
  if (date.precision === 'year') return String(date.date_taken || '').match(/\b\d{4}\b/)?.[0] || 'Year recorded; value unavailable'
  if (date.precision === 'year_range') {
    const range = String(date.reasoning || '').match(/Estimated year range:\s*(\d{4})\s*[-–]\s*(\d{4})/i)
    return range ? `${range[1]}–${range[2]} · approximate range` : 'Approximate range; endpoints unavailable'
  }
  return `${dateValue(date.date_taken)}${date.precision ? ` · ${stateLabel(date.precision)}` : ''}`
})
const captionInputState = computed(() => ['current', 'stale', 'unknown'].includes(bundle.value?.captions?.generated?.input_state)
  ? bundle.value.captions.generated.input_state : 'unknown')
const previewReady = ref(false)
const pending = ref({})
const corrections = ref({ caption: '', ocr: '' })
const fieldErrors = ref({})
const acknowledgments = ref({})
const writing = ref('')
const storage = reviewStorage()
const uuidPattern = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i
const hashPattern = /^[a-f0-9]{64}$/
let requestSequence = 0
let activeController = null

async function loadEvidence(key, preserveAcknowledgments = false) {
  const sequence = ++requestSequence
  activeController?.abort()
  activeController = null
  bundle.value = null
  error.value = ''
  loading.value = false
  previewError.value = false
  cropErrors.value = {}
  previewReady.value = false
  pending.value = {}
  corrections.value = { caption: '', ocr: '' }
  fieldErrors.value = {}
  writing.value = ''
  if (!preserveAcknowledgments) acknowledgments.value = {}
  emit('evidence-loaded', null)
  emit('preview-ready', null)
  if (!key) return

  const selector = JSON.parse(key)
  const controller = new AbortController()
  activeController = controller
  loading.value = true
  try {
    const data = await api.get('/media/photo-evidence', { params: selector, signal: controller.signal })
    if (sequence !== requestSequence) return
    if (!validResponse(data, selector)) throw new Error('Photo evidence response was incomplete or did not match this selector.')
    bundle.value = data
    for (const field of ['caption', 'ocr']) {
      const saved = readReviewRequest(storage, requestReference(data.asset.asset_uuid, field))
      if (validSavedRequest(saved, data.asset.asset_uuid, field)) pending.value[field] = saved
    }
    previewError.value = false
    cropErrors.value = {}
    emit('preview-ready', null)
    if (data.source.status === 'verified_current') emit('evidence-loaded', data)
    else emit('evidence-loaded', null)
  } catch (cause) {
    if (sequence !== requestSequence || cause?.name === 'CanceledError' || cause?.name === 'AbortError') return
    error.value = cause?.response?.data?.message || cause?.message || 'Photo evidence could not be loaded.'
    bundle.value = null
    emit('evidence-loaded', null)
    emit('preview-ready', null)
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

watch(selectorKey, key => loadEvidence(key), { immediate: true })

onBeforeUnmount(() => {
  requestSequence++
  activeController?.abort()
  emit('preview-ready', null)
})

function previewLoaded(event) {
  if (!currentPreviewEvent(event) || !event.target.naturalWidth) return
  if (!bundle.value || bundle.value.source.status !== 'verified_current' || previewError.value || !previewUrl.value) return
  previewReady.value = true
  emit('preview-ready', { source_sha256: bundle.value.source.source_sha256, asset_uuid: bundle.value.asset.asset_uuid || null })
}

function previewFailed(event) {
  if (!currentPreviewEvent(event)) return
  previewReady.value = false
  previewError.value = true
  emit('preview-ready', null)
}

function currentPreviewEvent(event) {
  return !!previewUrl.value && event?.target?.getAttribute('src') === previewUrl.value
    && event.target.isConnected
}

function reviewed(field) {
  return field === 'caption' ? bundle.value?.captions?.reviewed : bundle.value?.ocr?.reviewed
}

function fieldTextLimit(field) {
  const limit = bundle.value?.field_review?.[field]?.max_text_chars
  return Number.isSafeInteger(limit) && limit > 0 ? limit : undefined
}

function validCorrection(field) {
  const text = corrections.value[field]
  const limit = fieldTextLimit(field)
  return !!text.trim() && (limit === undefined || [...text].length <= limit)
}

function fieldReady(field) {
  const data = bundle.value
  const review = data?.field_review?.[field]
  return !!(data && uuidPattern.test(data.asset.asset_uuid || '') && Number.isSafeInteger(data.asset.file_registry_id)
    && data.asset.file_registry_id > 0 && data.source.status === 'verified_current' && previewReady.value && !previewError.value
    && hashPattern.test(data.source.source_sha256 || '') && review && review.held !== true
    && Number.isSafeInteger(review.revision) && review.revision >= 0 && hashPattern.test(review.evidence_sha256 || ''))
}

function requestReference(uuid, field) { return `photo-field:${uuid}:${field}` }

function validSavedRequest(request, uuid, field) {
  const intent = request?.body?.photo_field_decision
  const keys = ['field', 'decision', 'source_sha256', 'evidence_sha256', 'expected_revision', 'idempotency_key']
  if (intent?.decision === 'correct') keys.push('corrected_text')
  return !!(uuidPattern.test(uuid || '') && request?.endpoint === `/media/${uuid}/metadata`
    && Object.keys(request.body || {}).length === 1 && intent?.field === field
    && ['accept', 'correct', 'reject'].includes(intent.decision) && hashPattern.test(intent.source_sha256 || '')
    && hashPattern.test(intent.evidence_sha256 || '') && Number.isSafeInteger(intent.expected_revision) && intent.expected_revision >= 0
    && uuidPattern.test(intent.idempotency_key || '') && Object.keys(intent).length === keys.length
    && keys.every(key => Object.hasOwn(intent, key))
    && (intent.decision !== 'accept' || typeof request.expected_accept_text === 'string')
    && (intent.decision !== 'correct' || (typeof intent.corrected_text === 'string' && intent.corrected_text.trim())))
}

function matchingReceipt(data, request) {
  const receipt = data?.receipt
  const intent = request.body.photo_field_decision
  return data?.success === true && data.file_written === null && data.source_replaced === false && data.repair_required === false
    && receipt?.state === 'completed' && receipt.asset_uuid === request.endpoint.split('/')[2]
    && Number.isSafeInteger(receipt.file_registry_id) && receipt.file_registry_id > 0
    && ['idempotency_key', 'field', 'decision', 'source_sha256', 'evidence_sha256', 'expected_revision'].every(key => receipt[key] === intent[key])
    && receipt.revision === intent.expected_revision + 1 && Number.isSafeInteger(receipt.actor_id) && receipt.actor_id > 0
    && typeof receipt.created_at === 'string' && !!receipt.created_at
    && (intent.decision === 'reject' ? receipt.text === null : typeof receipt.text === 'string')
    && (intent.decision !== 'correct' || receipt.text === intent.corrected_text)
    && (intent.decision !== 'accept' || receipt.text === request.expected_accept_text)
}

function clearMatchingRequest(uuid, field, request) {
  const stored = readReviewRequest(storage, requestReference(uuid, field))
  if (stored?.body?.photo_field_decision?.idempotency_key === request.body.photo_field_decision.idempotency_key) {
    clearReviewRequest(storage, requestReference(uuid, field))
  }
}

async function submitField(field, decision) {
  if (writing.value) return
  let request = pending.value[field]
  const uuid = bundle.value?.asset.asset_uuid
  const key = selectorKey.value
  const sequence = requestSequence
  if (request) {
    if (!validSavedRequest(request, uuid, field)) return
  } else {
    if (!fieldReady(field) || !['accept', 'correct', 'reject'].includes(decision)
      || (decision === 'accept' && (bundle.value.field_review[field].can_accept !== true || typeof bundle.value.field_review[field].accept_text !== 'string'))
      || (decision === 'correct' && !validCorrection(field))) return
    try {
      const review = bundle.value.field_review[field]
      request = { endpoint: `/media/${uuid}/metadata`, ...(decision === 'accept' ? { expected_accept_text: review.accept_text } : {}), body: { photo_field_decision: {
        field, decision, source_sha256: bundle.value.source.source_sha256,
        evidence_sha256: review.evidence_sha256, expected_revision: review.revision,
        idempotency_key: crypto.randomUUID(),
        ...(decision === 'correct' ? { corrected_text: corrections.value[field] } : {}),
      } } }
      if (!saveReviewRequest(storage, requestReference(uuid, field), request)) throw new Error('The exact request could not be retained in this tab. No decision was sent.')
      pending.value = { ...pending.value, [field]: request }
    } catch (cause) {
      fieldErrors.value = { ...fieldErrors.value, [field]: cause.message || 'A safe request key could not be created.' }
      return
    }
  }
  writing.value = field
  fieldErrors.value = { ...fieldErrors.value, [field]: '' }
  try {
    const data = await api.post(request.endpoint, request.body)
    if (!matchingReceipt(data, request)) throw new Error('No matching completed receipt was returned. The original request is retained for exact retry.')
    clearMatchingRequest(uuid, field, request)
    if (sequence !== requestSequence || key !== selectorKey.value) return
    acknowledgments.value = { ...acknowledgments.value, [field]: 'Review saved. Check the updated evidence for this photo.' }
    await loadEvidence(key, true)
  } catch (cause) {
    const rejected = cause?.response?.data
    if ([409, 422].includes(cause?.response?.status) && rejected?.success === false
      && rejected.review_required === true && rejected.decision_applied === false) {
      clearMatchingRequest(uuid, field, request)
      if (sequence !== requestSequence || key !== selectorKey.value) return
      await loadEvidence(key)
      if (key === selectorKey.value) fieldErrors.value = { ...fieldErrors.value, [field]: rejected.error || rejected.message || 'Decision was not applied. Review the reloaded evidence before choosing a new intent.' }
      return
    }
    if (sequence !== requestSequence || key !== selectorKey.value) return
    fieldErrors.value = { ...fieldErrors.value, [field]: cause?.response?.data?.message || cause?.message || 'Response uncertain. Retry the retained exact request to reconcile its receipt.' }
  } finally {
    if (sequence === requestSequence && key === selectorKey.value) writing.value = ''
  }
}

function cropUrl(face) {
  if (!bundle.value || bundle.value.source.status !== 'verified_current' || !/^[a-f0-9]{64}$/i.test(bundle.value.source.source_sha256 || '')
    || cropErrors.value[face?.id] || face?.source_state !== 'current' || !face?.crop_url
    || !Number.isSafeInteger(Number(face.id)) || Number(face.id) <= 0) return ''
  try {
    const url = new URL(face.crop_url, window.location.origin)
    const faceId = String(Number(face.id))
    const expectedPath = `/api/media/faces/registry-crop/${faceId}`
    if (url.origin !== window.location.origin || url.pathname !== expectedPath || url.username || url.password || url.hash) return ''
    const expected = { source_sha256: bundle.value.source.source_sha256 }
    if (!/^[a-f0-9]{64}$/i.test(url.searchParams.get('region_fingerprint') || '')) return ''
    expected.region_fingerprint = url.searchParams.get('region_fingerprint')
    if (!exactQuery(url.searchParams, expected)) return ''
    return `${url.pathname}${url.search}`
  } catch { return '' }
}

function cropFailed(faceId) {
  cropErrors.value = { ...cropErrors.value, [faceId]: true }
}

function validResponse(data, selector) {
  if (!data || data.success !== true || data.schema !== 'plos.photo_review_evidence.v1') return false
  if (JSON.stringify(normalizePhotoEvidenceSelector(data.selector)) !== JSON.stringify(selector)) return false
  if (selector.asset_uuid && data.asset?.asset_uuid !== selector.asset_uuid) return false
  if (!data.asset || !data.source || !['verified_current', 'held'].includes(data.source.status)) return false
  if (!['matching', 'different', 'unknown'].includes(data.source.catalog_hash_state)) return false
  if (data.source.status === 'verified_current') {
    if (!/^[a-f0-9]{64}$/i.test(data.source.source_sha256 || '')) return false
    if (!Array.isArray(data.source.source_dimensions) || data.source.source_dimensions.length !== 2
      || !data.source.source_dimensions.every(value => Number.isSafeInteger(value) && value > 0)) return false
  }
  return !!data.captions && !!data.ocr && !!data.date && Array.isArray(data.faces) && !!data.limits
}

function safePreviewUrl(value, response) {
  if (typeof value !== 'string' || !value) return ''
  try {
    const url = new URL(value, window.location.origin)
    const expected = { source_sha256: response?.source?.source_sha256, ...normalizePhotoEvidenceSelector(response?.selector) }
    if (url.origin !== window.location.origin || url.username || url.password || url.pathname !== '/api/media/photo-evidence/preview' || url.hash) return ''
    if (!exactQuery(url.searchParams, expected)) return ''
    return `${url.pathname}${url.search}`
  } catch { return '' }
}

function comparisonSource(source) {
  if (!source || !Number.isSafeInteger(source.file_registry_id) || source.file_registry_id <= 0
    || !hashPattern.test(source.source_sha256 || '') || source.face_region_convention !== 'normalized_top_left'
    || source.coordinate_basis !== 'encoded_pixels') return null
  const region = source.region
  if (!region || !['x', 'y', 'w', 'h'].every(key => typeof region[key] === 'number' && Number.isFinite(region[key]))
    || region.x < 0 || region.y < 0 || region.w <= 0 || region.h <= 0 || region.x + region.w > 1 || region.y + region.h > 1) return null
  if (typeof source.preview_url !== 'string') return null
  try {
    const url = new URL(source.preview_url, window.location.origin)
    if (url.origin !== window.location.origin || url.username || url.password || url.hash || url.pathname !== '/api/media/photo-evidence/preview'
      || !exactQuery(url.searchParams, { file_registry_id: source.file_registry_id, source_sha256: source.source_sha256 })) return null
    return { ...source, url: `${url.pathname}${url.search}` }
  } catch { return null }
}

function exactQuery(params, expected) {
  const entries = [...params.entries()]
  if (entries.length !== Object.keys(expected).length) return false
  return Object.entries(expected).every(([key, value]) => params.getAll(key).length === 1 && params.get(key) === String(value))
}

function stateLabel(value) {
  if (!value) return 'unknown'
  return String(value).replaceAll('_', ' ')
}

function dateValue(value) {
  return value || 'Not recorded'
}
</script>

<style scoped>
.photo-review-evidence{--pre-ink:var(--desk-ink,#18343d);--pre-muted:var(--desk-muted,#536b72);--pre-line:var(--desk-line,#cbd9dc);--pre-paper:var(--desk-paper,#fff);--pre-canvas:var(--desk-canvas,#f3f7f8);--pre-teal:var(--desk-teal,#087e83);color:var(--pre-ink);background:var(--pre-paper);border:1px solid var(--pre-line);border-radius:.65rem;padding:1rem;margin-top:1rem;min-width:0;overflow-wrap:anywhere}
.photo-review-evidence h3{font-size:1.05rem;font-weight:700;margin:0 0 .65rem}.photo-review-evidence h4{font-size:.9rem;font-weight:700;margin:0 0 .5rem}.pre-note{color:var(--pre-muted);font-size:.88rem;margin:.5rem 0}.hold-note{padding:.65rem;border-left:3px solid #a54c2f;background:#fff4ef;color:#71301f}.mismatch-note{padding:.65rem;border-left:3px solid #b47c16;background:#fff9e8;color:#65480d}.asset-line{display:flex;gap:.4rem 1rem;flex-wrap:wrap;font-size:.82rem;color:var(--pre-muted);margin:.7rem 0}.asset-line strong{color:var(--pre-ink)}.source-proof{margin:.5rem 0 1rem;padding:.5rem;background:var(--pre-canvas);border-radius:.45rem}.source-proof img{display:block;width:100%;max-height:280px;object-fit:contain}.source-proof figcaption{font-size:.75rem;color:var(--pre-muted);padding-top:.35rem}.evidence-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}.evidence-block{min-width:0;padding:.7rem;border:1px solid var(--pre-line);border-radius:.5rem;background:var(--pre-canvas)}.evidence-block p{display:grid;gap:.15rem;margin:.45rem 0;font-size:.82rem;min-width:0}.evidence-block p b,.face-mapping b{font-size:.73rem;color:var(--pre-muted);font-weight:650}.evidence-block p span,.face-mapping span{white-space:pre-wrap;overflow-wrap:anywhere}.evidence-block .meta-line{display:block;color:var(--pre-muted);font-size:.75rem}.face-block{grid-column:1/-1}.face-row{border-top:1px solid var(--pre-line);padding:.45rem 0}.face-mapping{display:grid;gap:.1rem;margin:.35rem 0;font-size:.8rem}.decision-list{font-size:.76rem;padding-left:1.1rem;color:var(--pre-muted);overflow-wrap:anywhere}.review-flag{color:#71301f;font-weight:700}.limit-note{font-size:.77rem;color:var(--pre-muted);margin:.65rem 0 0}
.field-review label{display:block;font-size:.8rem;margin:.5rem 0 .25rem}.field-review textarea{box-sizing:border-box;width:100%;min-width:0;color:var(--pre-ink);background:var(--pre-paper);border:1px solid var(--pre-line);border-radius:.35rem;padding:.5rem;font:inherit;resize:vertical}.field-actions{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem}.field-review button{color:var(--pre-teal);border:1px solid var(--pre-teal);border-radius:.35rem;background:var(--pre-paper);padding:.45rem .6rem;font:inherit;font-size:.8rem;cursor:pointer}.field-review button:disabled{opacity:.5;cursor:not-allowed}.field-review button:focus-visible,.field-review textarea:focus-visible{outline:2px solid var(--pre-teal);outline-offset:2px}
.face-advice{margin-bottom:.7rem}.comparison-sources{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}
@media(max-width:640px){.comparison-sources{grid-template-columns:minmax(0,1fr)}.photo-review-evidence{padding:.75rem}.evidence-grid{grid-template-columns:minmax(0,1fr)}.face-block{grid-column:auto}.source-proof img{max-height:220px}}
</style>

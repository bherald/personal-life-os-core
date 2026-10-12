<template>
  <section class="identity-editor" aria-labelledby="identity-editor-title">
    <header><h2 id="identity-editor-title">{{ face ? 'Choose a person for this face' : 'Person names and aliases' }}</h2><button type="button" class="desk-button" :disabled="busy" @click="emit('close')">Close</button></header>
    <p>Prepare one change, then review it before saving. Saving a person name does not identify a face in a photo.</p>
    <p v-if="error" class="identity-message" role="alert">{{ error }}</p>
    <button v-if="heldOutcome" type="button" class="desk-button" @click="prepareAnother">Prepare another proposal</button>
    <div v-if="staged" class="identity-message" role="status"><p>Proposal staged. Review and approve this individual change in Review Desk.</p><a v-if="reviewUrl" :href="reviewUrl">Review this proposal</a><button type="button" class="desk-button" @click="prepareAnother">Prepare another proposal</button></div>
    <div v-if="pendingRequest" class="identity-message" role="status"><p>This exact submission has no confirmed outcome. Retry it to check the saved proposal.</p><p v-if="pendingRequest.summary">{{ pendingRequest.summary }}</p><button type="button" class="desk-button" :disabled="busy || !online" @click="submitPending">Retry this exact submission</button></div>
    <div v-if="face" class="identity-face">
      <img v-if="cropUrl" :key="cropUrl" :src="cropUrl" alt="Selected current face" @load="cropLoaded" @error="cropFailed">
      <p v-if="face.person_name">Stored label claim: {{ face.person_name }}</p>
      <p v-if="!faceEvidence || cropError" role="alert">Current face preview is unavailable. Reload the photo before confirming this face.</p>
      <p v-else-if="!cropReady">Loading the selected face…</p>
      <p v-else>This preview is for the selected face. Choose its person record explicitly; the stored label remains a claim.</p>
    </div>
    <form @submit.prevent="stageProposal">
      <fieldset :disabled="locked"><legend>Choose one change</legend><label v-if="face"><input v-model="operation" type="radio" value="confirm_face"> Confirm this face for a selected person</label><label><input v-model="operation" type="radio" value="create_identity"> Create a person record</label><label><input v-model="operation" type="radio" value="add_alias"> Add an alias to a selected person</label><label><input v-model="operation" type="radio" value="map_genealogy"> Add an optional family tree mapping</label></fieldset>
      <template v-if="operation === 'create_identity'"><label for="identity-canonical-name">Full person name</label><input id="identity-canonical-name" v-model="canonicalName" maxlength="255" required :disabled="locked"><p class="identity-help">Use the person’s full name. A matching name does not merge existing records.</p></template>
      <template v-else>
        <label for="identity-search">Find the individual by name or alias</label><input id="identity-search" v-model="query" type="search" maxlength="255" :disabled="locked" @input="searchIdentities">
        <ul class="identity-results"><li v-for="identity in identities" :key="identity.identity_uuid"><button type="button" class="desk-button identity-result" :disabled="locked" :aria-pressed="selectedIdentity?.identity_uuid === identity.identity_uuid" @click="chooseIdentity(identity)"><strong>{{ identity.canonical_name }}</strong><span v-if="identity.aliases.length">Aliases: {{ identity.aliases.join(', ') }}</span><span v-for="mapping in identity.mappings" :key="`${mapping.tree_id}:${mapping.genealogy_person_id}`">{{ mapping.tree_name || 'Family tree' }} · {{ mapping.person_name || 'Person unavailable' }}</span><span v-if="!identity.mappings.length">No family tree mapping</span><small>Record {{ identity.identity_uuid.slice(-8) }}</small></button></li></ul>
        <p v-if="selectedIdentity">Selected individual: <strong>{{ selectedIdentity.canonical_name }}</strong> · record {{ selectedIdentity.identity_uuid.slice(-8) }}</p>
        <template v-if="operation === 'add_alias'"><label for="identity-alias">Alias for this selected person</label><input id="identity-alias" v-model="alias" maxlength="255" required :disabled="locked"><p class="identity-help">This alias belongs only to the selected individual.</p></template>
        <template v-else-if="operation === 'map_genealogy'">
          <label for="identity-tree">Existing family tree</label><select id="identity-tree" v-model="treeId" :disabled="locked" @change="resetPerson"><option value="">Choose a tree</option><option v-for="tree in trees" :key="tree.id" :value="tree.id">{{ tree.name }}</option></select>
          <label for="identity-tree-person">Find a person in this tree</label><input id="identity-tree-person" v-model="personQuery" type="search" :disabled="locked || !treeId" @input="searchPersons">
          <ul class="identity-results"><li v-for="person in persons" :key="person.id"><button type="button" class="desk-button" :disabled="locked" @click="selectedPerson = person">{{ person.given_name }} {{ person.surname }} <span v-if="person.birth_date">· {{ person.birth_date }}</span></button></li></ul>
          <p v-if="selectedPerson">Selected tree person: <strong>{{ personName(selectedPerson) }}</strong> in <strong>{{ selectedTree?.name }}</strong>.</p>
        </template>
      </template>
      <p v-if="proposalSummary" class="identity-summary">{{ proposalSummary }}</p>
      <p v-if="!online" class="identity-message">Offline. No proposal will be sent automatically.</p>
      <button type="submit" class="desk-button primary" :disabled="locked || !online || !validProposal">{{ operation === 'confirm_face' ? 'Review this face and person' : operation === 'create_identity' ? 'Review new person name' : 'Review this name change' }}</button>
    </form>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api from '../../utils/api'
import { clearReviewRequest, evidenceUrl, readReviewRequest, reviewError, reviewRequestKey, reviewStorage, saveReviewRequest } from '../../utils/reviewDesk'

const props = defineProps({ initialIdentityUuid: { type: String, default: '' }, face: { type: Object, default: null } })
const emit = defineEmits(['staged', 'close'])
const storage = reviewStorage()
const requestSlot = 'global-face-identity-stage'
const pendingRequest = ref(readReviewRequest(storage, requestSlot))
const operation = ref(props.face ? 'confirm_face' : 'create_identity')
const canonicalName = ref('')
const alias = ref('')
const query = ref('')
const identities = ref([])
const selectedIdentity = ref(null)
const trees = ref([])
const treeId = ref('')
const personQuery = ref('')
const persons = ref([])
const selectedPerson = ref(null)
const busy = ref(false)
const staged = ref(null)
const heldOutcome = ref(false)
const error = ref('')
const online = ref(navigator.onLine)
const loadedCropUrl = ref('')
const cropError = ref(false)
let identityRequest = 0
let personRequest = 0
let mounted = true
const locked = computed(() => busy.value || !!pendingRequest.value || !!staged.value || heldOutcome.value)
const selectedTree = computed(() => trees.value.find(tree => Number(tree.id) === Number(treeId.value)))
const faceEvidence = computed(() => {
  const face = props.face
  const evidence = face?.global_face_evidence
  if (!evidence || !Number.isSafeInteger(evidence.face_id) || evidence.face_id <= 0 || evidence.face_id !== Number(face.face_id)
    || face.file_status !== 'active' || ![true, 1].includes(face.source_current) || face.superseded_at || face.hidden
    || !/^[a-f0-9]{64}$/.test(evidence.source_sha256) || evidence.source_sha256 !== face.source_sha256
    || evidence.face_region_convention !== 'normalized_top_left' || face.face_region_convention !== evidence.face_region_convention || evidence.coordinate_basis !== 'encoded_pixels') return null
  const region = evidence.region
  if (!region || Object.keys(region).length !== 4 || !['x', 'y', 'w', 'h'].every(axis => typeof region[axis] === 'number' && Number.isFinite(region[axis]))
    || region.x < 0 || region.y < 0 || region.w <= 0 || region.h <= 0 || region.x + region.w > 1 || region.y + region.h > 1) return null
  const crop = evidenceUrl(evidence.crop_url, window.location.origin, true)
  if (!crop) return null
  const url = new URL(crop, window.location.origin)
  if (url.pathname !== `/api/media/faces/registry-crop/${evidence.face_id}` || url.hash
    || [...url.searchParams.keys()].length !== 2 || url.searchParams.getAll('source_sha256').length !== 1 || url.searchParams.getAll('region_fingerprint').length !== 1
    || url.searchParams.get('source_sha256') !== evidence.source_sha256 || !/^[a-f0-9]{64}$/.test(evidence.region_fingerprint || '')
    || url.searchParams.get('region_fingerprint') !== evidence.region_fingerprint) return null
  return { ...evidence, crop_url: crop, region: { x: region.x, y: region.y, w: region.w, h: region.h } }
})
const cropUrl = computed(() => faceEvidence.value?.crop_url || '')
const cropReady = computed(() => !!cropUrl.value && loadedCropUrl.value === cropUrl.value && !cropError.value)
const validName = value => value.trim().length > 0 && value.trim().length <= 255 && !/[\u0000-\u001f\u007f]/.test(value)
const validProposal = computed(() => operation.value === 'create_identity' ? validName(canonicalName.value) : !!selectedIdentity.value && (operation.value === 'confirm_face' ? cropReady.value : operation.value === 'add_alias' ? validName(alias.value) : !!selectedTree.value && !!selectedPerson.value))
const proposalSummary = computed(() => operation.value === 'create_identity' && validName(canonicalName.value) ? `Create ${canonicalName.value.trim()} with no family tree mapping.` : operation.value === 'confirm_face' && selectedIdentity.value && faceEvidence.value ? `Confirm face ${faceEvidence.value.face_id} as ${selectedIdentity.value.canonical_name} (record ${selectedIdentity.value.identity_uuid.slice(-8)}). No family tree mapping is required.` : operation.value === 'add_alias' && selectedIdentity.value && validName(alias.value) ? `Add alias ${alias.value.trim()} only to ${selectedIdentity.value.canonical_name} (record ${selectedIdentity.value.identity_uuid.slice(-8)}).` : operation.value === 'map_genealogy' && selectedIdentity.value && selectedPerson.value && selectedTree.value ? `Map ${selectedIdentity.value.canonical_name} (record ${selectedIdentity.value.identity_uuid.slice(-8)}) to ${personName(selectedPerson.value)} in ${selectedTree.value.name}.` : '')
const reviewUrl = computed(() => evidenceUrl(staged.value?.review_url, window.location.origin, true))
const personName = person => `${person.given_name || ''} ${person.surname || ''}`.trim()
function networkChanged() { online.value = navigator.onLine }
function cropLoaded(event) { if (event.currentTarget.getAttribute('src') === cropUrl.value && event.currentTarget.naturalWidth > 0) { loadedCropUrl.value = cropUrl.value; cropError.value = false } }
function cropFailed(event) { if (event.currentTarget.getAttribute('src') === cropUrl.value) { loadedCropUrl.value = ''; cropError.value = true } }
function chooseIdentity(identity) { selectedIdentity.value = identity; alias.value = ''; resetPerson() }
function resetPerson() { personRequest++; personQuery.value = ''; persons.value = []; selectedPerson.value = null }
async function searchIdentities() {
  if (locked.value) return
  const request = ++identityRequest
  selectedIdentity.value = null
  try { const data = await api.get('/global-face-identities', { params: { q: query.value.trim(), limit: 20 } }); if (mounted && request === identityRequest) identities.value = data.identities || [] }
  catch (failure) { if (mounted && request === identityRequest) { identities.value = []; error.value = reviewError(failure) } }
}
async function loadIdentity(uuid) {
  if (!uuid || locked.value) return
  const request = ++identityRequest
  selectedIdentity.value = null
  try { const data = await api.get(`/global-face-identities/${encodeURIComponent(uuid)}`); if (mounted && request === identityRequest) { selectedIdentity.value = data.identity; identities.value = [data.identity]; operation.value = props.face ? 'confirm_face' : 'add_alias' } }
  catch (failure) { if (mounted && request === identityRequest) error.value = reviewError(failure) }
}
async function searchPersons() {
  if (locked.value) return
  const request = ++personRequest
  selectedPerson.value = null
  if (!treeId.value || personQuery.value.trim().length < 2) { persons.value = []; return }
  try { const data = await api.get(`/genealogy/trees/${Number(treeId.value)}/persons/search`, { params: { q: personQuery.value.trim(), limit: 20 } }); if (mounted && request === personRequest) persons.value = data.data?.persons || [] }
  catch (failure) { if (mounted && request === personRequest) { persons.value = []; error.value = reviewError(failure) } }
}
function prepareAnother() { staged.value = null; heldOutcome.value = false; error.value = ''; if (operation.value === 'confirm_face') selectedIdentity.value = null }
async function stageProposal() {
  if (locked.value || !online.value || !validProposal.value) return
  const body = { operation: operation.value, request_key: reviewRequestKey() }
  if (operation.value === 'create_identity') body.canonical_name = canonicalName.value.trim()
  else {
    body.identity_uuid = selectedIdentity.value.identity_uuid
    if (operation.value === 'add_alias') body.alias = alias.value.trim()
    else if (operation.value === 'confirm_face') {
      const evidence = faceEvidence.value
      Object.assign(body, { face_id: evidence.face_id, source_sha256: evidence.source_sha256, region: { ...evidence.region }, face_region_convention: evidence.face_region_convention, coordinate_basis: evidence.coordinate_basis })
    } else { body.tree_id = Number(treeId.value); body.genealogy_person_id = Number(selectedPerson.value.id) }
  }
  const request = { body, summary: proposalSummary.value }
  if (!saveReviewRequest(storage, requestSlot, request)) { error.value = 'Tab storage is unavailable. Enable it before submitting so the exact request can be retried safely.'; return }
  pendingRequest.value = request
  await submitPending()
}
async function submitPending() {
  if (!pendingRequest.value?.body || busy.value || !online.value) return
  busy.value = true; error.value = ''
  try {
    const result = await api.post('/global-face-identities/proposals', pendingRequest.value.body)
    if (result.success !== true || typeof result.unified_id !== 'string') throw new Error('Proposal outcome is unconfirmed.')
    clearReviewRequest(storage, requestSlot); pendingRequest.value = null
    if (mounted) { staged.value = result; emit('staged', result) }
  } catch (failure) {
    if (!mounted) return
    if (failure.response?.status && failure.response.status < 500) { heldOutcome.value = pendingRequest.value.body.operation === 'confirm_face'; clearReviewRequest(storage, requestSlot); pendingRequest.value = null }
    error.value = reviewError(failure)
  } finally { if (mounted) busy.value = false }
}
watch(() => props.initialIdentityUuid, uuid => { loadIdentity(uuid) })
watch(cropUrl, () => { loadedCropUrl.value = ''; cropError.value = false }, { flush: 'sync' })
watch(operation, () => { personRequest++; persons.value = []; selectedPerson.value = null; error.value = '' })
onMounted(async () => {
  window.addEventListener('online', networkChanged); window.addEventListener('offline', networkChanged)
  try { const data = await api.get('/genealogy/trees'); if (mounted) trees.value = data.data?.trees || [] } catch (failure) { if (mounted) error.value = reviewError(failure) }
  if (props.initialIdentityUuid) await loadIdentity(props.initialIdentityUuid)
  else await searchIdentities()
})
onBeforeUnmount(() => { mounted = false; identityRequest++; personRequest++; window.removeEventListener('online', networkChanged); window.removeEventListener('offline', networkChanged) })
</script>

<style scoped>
.identity-editor { width: 100%; max-width: 760px; padding: 20px; color: var(--desk-ink); background: var(--desk-paper); border: 1px solid var(--desk-line); border-radius: 12px; }
.identity-editor header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.identity-editor h2 { font-size: 21px; margin: 0; }
.identity-editor p { line-height: 1.5; }
.identity-editor form { display: grid; gap: 10px; }
.identity-face { padding: 12px; border: 1px solid var(--desk-line); border-radius: 8px; margin-bottom: 12px; }
.identity-face img { width: 160px; height: 160px; object-fit: contain; border-radius: 8px; }
.identity-editor fieldset { display: grid; gap: 10px; border: 1px solid var(--desk-line); padding: 12px; }
.identity-editor legend, .identity-editor label { font-weight: 650; }
.identity-editor fieldset label { display: flex; align-items: center; gap: 10px; min-height: 44px; }
.identity-editor input[type=radio] { width: 18px; height: 18px; margin: 0; flex-shrink: 0; color: var(--desk-teal); accent-color: var(--desk-teal); }
.identity-editor input:not([type=radio]), .identity-editor select { width: 100%; min-height: 44px; padding: 10px; border: 1px solid var(--desk-line); border-radius: 7px; background: var(--desk-paper); color: var(--desk-ink); font-size: 16px; }
.identity-editor .desk-button { min-height: 44px; padding: 10px 14px; border: 1px solid var(--desk-line); border-radius: 8px; color: var(--desk-ink); background: var(--desk-paper); font-weight: 650; }
.identity-editor .primary { color: white; background: var(--desk-teal); border-color: var(--desk-teal); }
.identity-editor button:disabled { opacity: .55; cursor: not-allowed; }
.identity-results { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.identity-result { width: 100%; display: grid; gap: 5px; text-align: left; overflow-wrap: anywhere; }
.identity-result[aria-pressed=true] { border-color: var(--desk-teal); background: var(--desk-teal-soft); }
.identity-help, .identity-result small { color: var(--desk-muted); font-size: 13px; }
.identity-summary, .identity-message { padding: 12px; border: 1px solid var(--desk-line); border-radius: 8px; background: var(--desk-teal-soft); }
.identity-message button { margin: 8px 0 0 10px; }
@media (max-width: 600px) { .identity-editor { padding: 14px; } .identity-editor header { align-items: start; } }
</style>

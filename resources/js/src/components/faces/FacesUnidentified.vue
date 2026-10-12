<template>
  <section class="unidentified-faces" :class="{ embedded }" aria-labelledby="unidentified-heading">
    <header class="unidentified-heading"><div><h2 id="unidentified-heading">People to identify</h2><p>{{ total }} face{{ total !== 1 ? 's' : '' }} awaiting review. A photo can contain several faces.</p></div><button class="face-button" :disabled="loading" @click="reload">Refresh photos</button></header>
    <p class="unidentified-guide">Open a photo, choose the face, then select the person you recognize. You can leave an unfamiliar face for later.</p>
    <p v-if="unavailableCount" class="unidentified-guide" role="status">{{ unavailableCount }} photo{{ unavailableCount !== 1 ? 's' : '' }} on this page need preparation before review.</p>
    <p v-if="loadError" class="unidentified-error" role="alert">{{ loadError }}</p>
    <p v-else-if="loading" role="status">Loading photos…</p>
    <p v-else-if="!faces.length" class="unidentified-empty">No unidentified faces in this view.</p>
    <div v-if="!loading && !loadError" class="unidentified-grid" :aria-busy="loading">
      <article v-for="(face, index) in faces" :key="faceReference(face)" :data-face-id="face.id" v-show="faceStates[faceReference(face)] !== 'unavailable'" class="unidentified-card" :class="{ selected: selectedReference === faceReference(face) }">
        <button v-if="embedded" :disabled="faceStates[faceReference(face)] !== 'available'" class="unidentified-photo" :aria-label="`Open photo ${index + 1}: ${face.filename || 'unnamed photo'}`" @click="openFace(face)"><UnidentifiedFacePreview :key="`${faceReference(face)}:${previewGeneration}`" :face-id="face.id" :review-type="face.review_type || 'face'" :generation="previewGeneration" @state="updateFaceState(faceReference(face), $event)" /></button>
        <a v-else :href="faceStates[faceReference(face)] === 'available' ? reviewUrl(face) : undefined" :aria-disabled="faceStates[faceReference(face)] !== 'available'" class="unidentified-photo" :aria-label="`Review photo ${index + 1}: ${face.filename || 'unnamed photo'}`"><UnidentifiedFacePreview :key="`${faceReference(face)}:${previewGeneration}`" :face-id="face.id" :review-type="face.review_type || 'face'" :generation="previewGeneration" @state="updateFaceState(faceReference(face), $event)" /></a>
        <div class="unidentified-card-info"><h3>{{ face.filename || 'Photo without a filename' }}</h3><p>{{ face.review_type === 'registry_face' ? 'Name the highlighted face' : 'Choose the face in this photo' }}</p><button v-if="embedded" :disabled="faceStates[faceReference(face)] !== 'available'" class="face-button primary" @click="openFace(face)">Review this face →</button><a v-else-if="faceStates[faceReference(face)] === 'available'" :href="reviewUrl(face)" class="face-button primary">Review this face →</a></div>
      </article>
    </div>
    <nav v-if="pages > 1" class="unidentified-pages" aria-label="Photo pages"><button class="face-button" :disabled="loading || currentPage <= 1" @click="changePage(currentPage - 1)">← Previous photos</button><span>Page {{ currentPage }} of {{ pages }}</span><button class="face-button" :disabled="loading || currentPage >= pages" @click="changePage(currentPage + 1)">Next photos →</button></nav>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useFacesData } from '../../composables/useFacesData'
import UnidentifiedFacePreview from './UnidentifiedFacePreview.vue'
const props = defineProps({ embedded: Boolean, selectedReference: { type: String, default: '' } })
const emit = defineEmits(['open', 'count', 'references'])
const { unidentifiedFaces: faces, unidentifiedPage: currentPage, unidentifiedTotal: total, unidentifiedPendingTotal: pendingTotal, unidentifiedLoading: loading, unidentifiedPages: pages, loadUnidentified } = useFacesData()
const loadError = ref('')
const faceStates = ref({})
const previewGeneration = ref(0)
const availableReferences = computed(() => loading.value || loadError.value ? [] : faces.value.filter(face => faceStates.value[faceReference(face)] === 'available').map(face => faceReference(face)))
const unavailableCount = computed(() => faces.value.filter(face => faceStates.value[faceReference(face)] === 'unavailable').length)
function faceReference(face) { return face.review_type === 'registry_face' ? `registry_face:${face.id}` : `face:${face.id}` }
function reviewUrl(face) { return `/reviews?mode=photos&ref=${encodeURIComponent(faceReference(face))}` }
function updateFaceState(id, result) { if (result.generation === previewGeneration.value && faces.value.some(face => faceReference(face) === id) && faceStates.value[id] !== 'unavailable') faceStates.value[id] = result.state }
function openFace(face) { if (faceStates.value[faceReference(face)] === 'available' && !loading.value && !loadError.value) emit('open', faceReference(face)) }
async function reload() {
  faceStates.value = {}; previewGeneration.value++
  loadError.value = ''
  if (await loadUnidentified(currentPage.value, 12, props.embedded) === false) loadError.value = 'The photos could not be loaded. Use Refresh photos to try again.'
}
async function changePage(page) {
  if (loading.value || page < 1 || page > pages.value) return
  faceStates.value = {}; previewGeneration.value++
  loadError.value = ''
  if (await loadUnidentified(page, 12, props.embedded) === false) loadError.value = 'The next photos could not be loaded. Use Refresh photos to try again.'
  document.getElementById('unidentified-heading')?.scrollIntoView({ block: 'start' })
}
watch(pendingTotal, value => emit('count', value), { immediate: true })
watch(availableReferences, value => emit('references', value), { immediate: true })
function markUnavailable(reference) { if (faces.value.some(face => faceReference(face) === reference)) faceStates.value[reference] = 'unavailable' }
defineExpose({ reload, markUnavailable })
onMounted(reload)
</script>

<style scoped>
.unidentified-faces { padding: 20px; color: var(--desk-ink); }
.unidentified-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.unidentified-heading h2 { font-size: 24px; text-transform: none; margin: 0 0 6px; }
.unidentified-heading p, .unidentified-guide { color: var(--desk-muted); font-size: 14px; line-height: 1.6; margin: 5px 0; }
.unidentified-guide { background: var(--desk-teal-soft); border: 1px solid var(--desk-line); border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
.unidentified-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px; }
.unidentified-card { min-width: 0; background: var(--desk-paper); border: 1px solid var(--desk-line); border-radius: 12px; overflow: hidden; }
.unidentified-card.selected { border-color: var(--desk-teal); background: var(--desk-teal-soft); }
.unidentified-photo { display: block; width: 100%; padding: 0; border: 0; background: var(--desk-subtle); }
.unidentified-card-info { padding: 14px; }
.unidentified-card-info h3 { font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; margin: 0; text-transform: none; }
.unidentified-card-info p { color: var(--desk-muted); margin: 5px 0 12px; font-size: 13px; }
.face-button { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border: 1px solid var(--desk-line); border-radius: 8px; background: var(--desk-paper); color: var(--desk-ink); font-size: 14px; font-weight: 650; }
.face-button.primary { width: 100%; color: white; background: var(--desk-teal); border-color: var(--desk-teal); }
.face-button:disabled { opacity: .55; cursor: not-allowed; }
.face-button:focus-visible, .unidentified-photo:focus-visible { outline: 3px solid var(--desk-teal); outline-offset: 3px; }
.unidentified-pages { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 14px; margin-top: 22px; }
.unidentified-pages span { color: var(--desk-muted); font-size: 13px; }
.unidentified-empty, .unidentified-error { padding: 18px; border: 1px solid var(--desk-line); border-radius: 8px; }
.unidentified-held { color: var(--desk-amber); }
.unidentified-error { color: var(--desk-red); background: var(--desk-red-soft); }
.embedded { padding: 18px; }.embedded .unidentified-heading h2 { font-size: 18px; }.embedded .unidentified-grid { grid-template-columns: 1fr; }.embedded .unidentified-heading p, .embedded .unidentified-guide { font-size: 13px; }
@media (max-width: 600px) { .unidentified-faces { padding: 14px; } .unidentified-grid { grid-template-columns: 1fr; } .unidentified-pages span { order: 3; width: 100%; text-align: center; } }
</style>

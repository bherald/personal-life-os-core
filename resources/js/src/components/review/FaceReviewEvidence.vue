<template>
  <section class="face-evidence" :class="{ compact }" aria-label="Face match evidence">
    <figure class="face-crop">
      <figcaption>Recorded face crop</figcaption>
      <img
        v-if="item.image_url && !cropError"
        :src="item.image_url"
        :alt="item.face_name ? `Face crop with historical label ${item.face_name}` : 'Recorded face crop'"
        @error="cropError = true"
      />
      <div v-else class="evidence-unavailable">Crop unavailable</div>
    </figure>

    <figure v-if="!compact" class="source-photo">
      <figcaption>Current source photo</figcaption>
      <ReviewEvidenceViewer
        v-if="sourcePhotoUrl"
        :key="sourcePhotoUrl"
        :src="sourcePhotoUrl"
        title="Current source photo"
        :region="currentRegion"
        :reference="item.reference || item.unified_id || `face:${item.id}`"
        @error="sourceFailed"
      />
      <p v-if="sourceLoading" class="region-unavailable" role="status">Loading current photo…</p>
      <p v-if="sourceError" class="region-unavailable" role="alert">{{ sourceError }} <button type="button" @click="loadSourceProof">Reload photo</button></p>
      <p v-if="item.face_region && !currentRegion" class="region-unavailable">The recorded face region is historical; it has not been verified on this current photo.</p>
      <div v-if="!normalizeFaceRegion(item.face_region)" class="region-unavailable">Face region unavailable</div>
    </figure>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ReviewEvidenceViewer from './ReviewEvidenceViewer.vue'
import api from '../../utils/api'
import { normalizeFaceRegion, reviewError } from '../../utils/reviewDesk'

const props = defineProps({
  item: { type: Object, required: true },
  compact: { type: Boolean, default: false },
})

const cropError = ref(false)
const sourceProof = ref(null)
const sourceLoading = ref(false)
const sourceError = ref('')
let sourceRequest = 0
const sourcePhotoUrl = computed(() => sourceProof.value
  ? `/api/media/face-match-source/${encodeURIComponent(props.item.id)}?source_sha256=${sourceProof.value.source_sha256}` : null)
const currentRegion = computed(() => sourceProof.value && props.item.source_sha256 === sourceProof.value.source_sha256
  && props.item.face_region_convention === 'normalized_top_left' && normalizeFaceRegion(props.item.face_region) ? props.item.face_region : null)
async function loadSourceProof() {
  const request = ++sourceRequest
  sourceProof.value = null; sourceError.value = ''; sourceLoading.value = false
  if (props.compact) return
  const id = Number(props.item.id)
  if (!Number.isSafeInteger(id) || id <= 0) { sourceError.value = 'Current source photo is unavailable.'; return }
  sourceLoading.value = true
  try {
    const proof = await api.get(`/media/face-match-source/${id}/proof`)
    if (request !== sourceRequest) return
    if (proof.success !== true || !/^[a-f0-9]{64}$/.test(proof.source_sha256 || '')
      || proof.coordinate_basis !== 'encoded_pixels' || proof.face_region_convention !== 'normalized_top_left') throw new Error('Current source photo could not be verified.')
    sourceProof.value = proof
  } catch (error) { if (request === sourceRequest) sourceError.value = reviewError(error) }
  finally { if (request === sourceRequest) sourceLoading.value = false }
}
function sourceFailed() { sourceProof.value = null; sourceError.value = 'Photo changed or is unavailable; reload the current photo.' }
watch([() => props.item.id, () => props.item.revision, () => props.compact], () => { cropError.value = false; loadSourceProof() }, { immediate: true })
onBeforeUnmount(() => { sourceRequest++ })
</script>

<style scoped>
.face-evidence {
  display: grid;
  grid-template-columns: 7rem minmax(14rem, 32rem);
  gap: 0.75rem;
  align-items: start;
  max-width: 40rem;
}

.face-evidence.compact {
  display: block;
  max-width: 7rem;
}

figure {
  min-width: 0;
  margin: 0;
}

figcaption {
  margin-bottom: 0.3rem;
  color: var(--desk-muted);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0;
  text-transform: uppercase;
}

.face-crop img,
.face-crop .evidence-unavailable {
  width: 7rem;
  height: 7rem;
  border: 1px solid var(--desk-line);
  border-radius: 6px;
  object-fit: cover;
}

.evidence-unavailable {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 7rem;
  padding: 0.5rem;
  border: 1px dashed var(--desk-line);
  border-radius: 6px;
  color: var(--ops-sky);
  font-size: 0.72rem;
  line-height: 1.3;
  text-align: center;
}

.source-photo .evidence-unavailable {
  min-height: 9rem;
}

.region-unavailable {
  margin-top: 0.3rem;
  color: var(--ops-sky);
  font-size: 0.68rem;
}

@media (max-width: 640px) {
  .face-evidence {
    grid-template-columns: 5.5rem minmax(0, 1fr);
    gap: 0.5rem;
  }

  .face-crop img,
  .face-crop .evidence-unavailable {
    width: 5.5rem;
    height: 5.5rem;
  }

  .face-evidence.compact {
    max-width: 5.5rem;
  }
}
</style>

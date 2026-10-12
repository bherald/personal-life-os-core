<template>
  <figure ref="preview" class="unknown-preview" :aria-busy="loading">
    <img v-if="source && !failed" :src="source" alt="Current source photo; select the face during review" loading="lazy" @load="loaded" @error="imageFailed">
    <p v-if="failed" class="unknown-image-message">Photo preview unavailable.<br>Open this face to check its source.</p>
    <p v-else-if="!ready" class="unknown-image-message">{{ loading ? 'Loading current photo…' : 'Current photo preview' }}</p>
  </figure>
</template>
<script>
// Hash verification is bounded independently of how many cards enter the viewport.
const proofQueue = []
let activeProofs = 0
function pumpProofs() {
  while (activeProofs < 2 && proofQueue.length) {
    const task = proofQueue.shift()
    activeProofs++
    Promise.resolve().then(task.run).then(task.resolve, task.reject).finally(() => { activeProofs--; pumpProofs() })
  }
}
function enqueueProof(run) { return new Promise((resolve, reject) => { proofQueue.push({ run, resolve, reject }); pumpProofs() }) }
</script>
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import api from '../../utils/api'
import { currentFaceSourceProof, currentRegistryFaceSourceProof } from '../../utils/reviewDesk'
const emit = defineEmits(['state'])
const props = defineProps({ faceId: { type: Number, required: true }, generation: { type: Number, default: 0 }, reviewType: { type: String, default: 'face' } })
const preview = ref(null)
const source = ref(null)
const ready = ref(false)
const loading = ref(false)
const failed = ref(false)
let observer
let mounted = true
async function load() {
  observer?.disconnect()
  loading.value = true
  try {
    const registry = props.reviewType === 'registry_face'
    const proof = await enqueueProof(() => mounted ? api.get(`/media/${registry ? 'registry-face-source' : 'face-match-source'}/${props.faceId}/proof`) : null)
    if (!mounted) return
    const currentProof = (registry ? currentRegistryFaceSourceProof : currentFaceSourceProof)(proof, props.faceId, window.location.origin)
    if (!currentProof) throw new Error('Current source proof unavailable')
    source.value = currentProof.preview_url
  } catch { if (mounted) { failed.value = true; emit('state', { generation: props.generation, state: 'unavailable' }) } }
  finally { if (mounted) loading.value = false }
}
function imageFailed() { if (!mounted) return; failed.value = true; emit('state', { generation: props.generation, state: 'unavailable' }) }
function loaded(event) { if (!mounted) return; ready.value = event.target.naturalWidth > 0 && event.target.naturalHeight > 0; if (!ready.value) imageFailed(); else emit('state', { generation: props.generation, state: 'available' }) }
onMounted(() => {
  if (!('IntersectionObserver' in window)) { load(); return }
  observer = new IntersectionObserver(entries => { if (entries.some(entry => entry.isIntersecting)) load() }, { rootMargin: '150px' })
  observer.observe(preview.value)
})
onBeforeUnmount(() => { mounted = false; observer?.disconnect() })
</script>
<style scoped>
.unknown-preview { position: relative; margin: 0; aspect-ratio: 4/3; display: grid; place-items: center; overflow: hidden; background: var(--desk-canvas); }
.unknown-preview img { display: block; width: 100%; height: 100%; object-fit: contain; image-orientation: from-image; }
.unknown-image-message { position: absolute; padding: 18px; text-align: center; font-size: 14px; line-height: 1.6; color: var(--desk-muted); }
</style>

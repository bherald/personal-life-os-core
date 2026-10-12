<template>
    <figure class="evidence-viewer">
        <button v-if="safeSource && !failed" ref="opener" type="button" class="evidence-preview" :aria-label="`Open ${title} with zoom and pan`" @click="opened = true">
            <img :src="safeSource" :alt="title" referrerpolicy="no-referrer" @load="loaded" @error="imageFailed">
            <span v-if="normalizedRegion" class="evidence-region" :style="regionStyle"><span v-if="proposedName">Proposed: {{ proposedName }}</span></span>
            <span class="evidence-open-label">{{ openLabel }} · zoom &amp; pan</span>
        </button>
        <p v-else class="evidence-error" role="status">Image evidence unavailable. Do not confirm identity from a missing image.</p>
        <figcaption v-if="proposedName">Proposed: {{ proposedName }} · unconfirmed. You decide the identity.</figcaption>
        <ReviewDialog v-model="opened" :title="proposedName ? `Proposed: ${proposedName} — unconfirmed` : title" wide @opened="fit" @closed="stopDrag">
            <p class="evidence-reference">{{ reference }}<span v-if="revision"> · revision {{ revision }}</span></p>
            <p class="evidence-instructions">{{ proposedName ? 'Only the outlined face is under review. The suggestion is not a confirmed identity.' : 'Inspect the original evidence before deciding.' }}</p>
            <div class="evidence-toolbar" role="group" aria-label="Image controls">
                <button type="button" aria-label="Zoom out" @click="changeZoom(-.25)">−</button><output aria-live="polite">{{ Math.round(scale * 100) }}%</output><button type="button" aria-label="Zoom in" @click="changeZoom(.25)">+</button>
                <button type="button" @click="fit">Fit image</button><button v-if="normalizedRegion" type="button" @click="fitFace">Fit face</button><button type="button" :disabled="selecting" @click="rotation = (rotation + 90) % 360">Rotate 90°</button>
                <button v-if="selectable" type="button" :aria-pressed="selecting" @click="beginSelection">{{ selecting ? 'Drawing face box' : 'Select this face' }}</button>
                <button type="button" aria-label="Pan left" @click="panX -= 40">←</button><button type="button" aria-label="Pan up" @click="panY -= 40">↑</button><button type="button" aria-label="Pan down" @click="panY += 40">↓</button><button type="button" aria-label="Pan right" @click="panX += 40">→</button>
            </div>
            <div ref="viewport" class="evidence-viewport" tabindex="0" aria-label="Image viewer. Toolbar provides zoom and pan without dragging." @pointerdown="startDrag" @pointermove="drag" @pointerup="stopDrag" @pointercancel="cancelSelection">
                <div v-if="!failed" ref="stage" class="evidence-stage" :style="stageStyle">
                    <img :src="safeSource" :alt="title" draggable="false" referrerpolicy="no-referrer" @load="loaded" @error="imageFailed">
                    <span v-if="normalizedRegion" class="evidence-region" :style="regionStyle"><span v-if="proposedName">Proposed: {{ proposedName }}</span></span>
                </div><p v-else class="viewer-error">The original image could not be loaded. Approval needs available evidence.</p>
            </div>
            <p v-if="selecting" class="evidence-instructions">Drag a box around the face you are reviewing. This selects a region; it does not identify anyone.</p>
            <div class="evidence-footer"><span>{{ selectable && selection ? 'New region selected. Save it before choosing an identity.' : 'Image and face rectangle share the same transform.' }}</span><button type="button" @click="opened = false">Back to decision</button></div>
        </ReviewDialog>
    </figure>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ReviewDialog from './ReviewDialog.vue'
import { evidenceUrl, normalizeFaceRegion } from '../../utils/reviewDesk'

const props = defineProps({ src: String, title: { type: String, default: 'Source image' }, openLabel: { type: String, default: 'Open full image' }, proposedName: String, region: Object, reference: String, revision: [String, Number], selectable: Boolean })
const emit = defineEmits(['ready', 'error', 'region-selected'])
const opened = ref(false)
const failed = ref(false)
const viewport = ref(null)
const stage = ref(null)
const opener = ref(null)
const dimensions = ref(null)
const scale = ref(1)
const panX = ref(0)
const panY = ref(0)
const rotation = ref(0)
const selecting = ref(false)
const selection = ref(null)
let pointer = null
let observer = null
const safeSource = computed(() => evidenceUrl(props.src, window.location.origin, true))
const normalizedRegion = computed(() => selecting.value || selection.value ? normalizeFaceRegion(selection.value) : normalizeFaceRegion(props.region))
const regionStyle = computed(() => normalizedRegion.value ? { left: `${normalizedRegion.value.left * 100}%`, top: `${normalizedRegion.value.top * 100}%`, width: `${normalizedRegion.value.width * 100}%`, height: `${normalizedRegion.value.height * 100}%` } : {})
const stageStyle = computed(() => ({ aspectRatio: dimensions.value ? `${dimensions.value.width} / ${dimensions.value.height}` : '4 / 3', transform: `translate(${panX.value}px, ${panY.value}px) scale(${scale.value}) rotate(${rotation.value}deg)` }))

watch(() => [props.src, props.revision], () => { failed.value = false; dimensions.value = null; opened.value = false; selecting.value = false; selection.value = null; emit('region-selected', null); emit('ready', false) })
function loaded(event) {
    const image = event.target
    if (!image.naturalWidth || !image.naturalHeight) return imageFailed()
    dimensions.value = { width: image.naturalWidth, height: image.naturalHeight }
    emit('ready', true)
    if (opened.value) nextTick(fit)
}
function imageFailed() { failed.value = true; emit('ready', false); emit('error') }
async function fit() {
    await nextTick()
    if (!stage.value || !viewport.value) return
    scale.value = Math.min(1, viewport.value.clientWidth * .96 / stage.value.offsetWidth, viewport.value.clientHeight * .96 / stage.value.offsetHeight)
    panX.value = 0; panY.value = 0; rotation.value = 0
}
function fitFace() {
    if (!stage.value || !normalizedRegion.value) return
    const region = normalizedRegion.value
    rotation.value = 0
    scale.value = Math.min(5, .7 / Math.max(region.width, region.height))
    panX.value = (.5 - region.left - region.width / 2) * stage.value.offsetWidth * scale.value
    panY.value = (.5 - region.top - region.height / 2) * stage.value.offsetHeight * scale.value
}
function changeZoom(delta) { scale.value = Math.min(8, Math.max(.1, scale.value + delta)) }
function beginSelection() { selecting.value = true; selection.value = null; rotation.value = 0; emit('region-selected', null) }
function sourcePoint(event) {
    const box = stage.value?.getBoundingClientRect()
    if (!box?.width || !box?.height) return null
    return { x: Math.min(1, Math.max(0, (event.clientX - box.left) / box.width)), y: Math.min(1, Math.max(0, (event.clientY - box.top) / box.height)) }
}
function startDrag(event) {
    if (event.button !== 0) return
    if (selecting.value) {
        const point = sourcePoint(event)
        if (!point) return
        pointer = { id: event.pointerId, regionStart: point }
        viewport.value.setPointerCapture(event.pointerId)
        return
    }
    pointer = { id: event.pointerId, startX: event.clientX, startY: event.clientY, originX: panX.value, originY: panY.value }
    viewport.value.setPointerCapture(event.pointerId)
}
function drag(event) {
    if (pointer?.id !== event.pointerId) return
    if (pointer.regionStart) {
        const point = sourcePoint(event)
        if (!point) return
        const start = pointer.regionStart
        selection.value = { x: Math.min(start.x, point.x), y: Math.min(start.y, point.y), w: Math.abs(point.x - start.x), h: Math.abs(point.y - start.y) }
        return
    }
    panX.value = pointer.originX + event.clientX - pointer.startX; panY.value = pointer.originY + event.clientY - pointer.startY
}
function stopDrag() {
    if (pointer?.regionStart) emit('region-selected', normalizeFaceRegion(selection.value) ? { ...selection.value } : null)
    pointer = null
}
function cancelSelection() { if (pointer?.regionStart) { selection.value = null; emit('region-selected', null) }; pointer = null }
onMounted(() => { observer = new ResizeObserver(() => { if (opened.value) fit() }); if (viewport.value) observer.observe(viewport.value) })
onBeforeUnmount(() => observer?.disconnect())
</script>

<style scoped>
.evidence-viewer { margin: 0; min-width: 0; }
.evidence-preview { position: relative; display: block; width: 100%; padding: 0; border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden; background: var(--color-bg-tertiary); }
.evidence-preview img { width: 100%; height: auto; display: block; image-orientation: from-image; }
.evidence-region { position: absolute; border: 2px solid #fff2a7; box-shadow: 0 0 0 1px #655020; pointer-events: none; }
.evidence-region > span { position: absolute; bottom: 100%; left: -2px; color: #403017; background: #fff2a7; padding: 3px 6px; font-size: 11px; white-space: nowrap; border-radius: 3px 3px 0 0; }
.evidence-open-label { position: absolute; bottom: 8px; left: 8px; background: #173b3ae8; color: white; padding: 7px 9px; border-radius: 6px; font-size: 11px; }
figcaption { margin-top: 8px; font-size: 12px; color: var(--color-text-secondary); }
.evidence-error { padding: 18px; border: 1px solid var(--color-border); border-radius: 8px; color: var(--ops-danger); }
.evidence-reference { font-family: ui-monospace, monospace; font-size: 12px; color: var(--color-text-secondary); }
.evidence-instructions { margin: 12px 0; font-size: 14px; color: var(--color-text-secondary); }
.evidence-toolbar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.evidence-toolbar button, .evidence-footer button { min-width: 44px; min-height: 44px; padding: 8px 12px; color: var(--color-text-primary); background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 8px; font-size: 13px; }
.evidence-toolbar output { min-width: 55px; text-align: center; font-size: 12px; }
.evidence-viewport { height: 48dvh; min-height: 220px; max-height: 570px; background: #1c2b32; overflow: hidden; border-radius: 10px; display: grid; place-items: center; cursor: grab; touch-action: none; }
.evidence-stage { position: relative; width: min(95%, 720px); transform-origin: center; user-select: none; }
.evidence-stage img { width: 100%; height: 100%; display: block; pointer-events: none; image-orientation: from-image; }
.viewer-error { color: #fff2a7; padding: 20px; }
.evidence-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 16px; font-size: 12px; color: var(--color-text-secondary); }
@media (max-width: 600px) { .evidence-viewport { height: 38dvh; } .evidence-footer button { width: 100%; } }
</style>

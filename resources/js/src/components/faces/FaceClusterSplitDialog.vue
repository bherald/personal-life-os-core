<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="$emit('close')">
    <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-2xl mx-4 p-6 max-h-[80vh] flex flex-col">
      <h3 class="text-ops-peach text-lg font-bold mb-1">
        Split {{ cluster?.name || `Cluster #${cluster?.id}` }}
      </h3>
      <p class="text-xs text-ops-text-muted mb-3">
        Select faces to move to a new cluster. {{ selectedFaces.size }} of {{ faces.length }} selected.
      </p>

      <!-- Mode toggle -->
      <div class="flex gap-2 mb-3">
        <button
          @click="mode = 'select'"
          class="px-3 py-1 text-xs rounded uppercase font-semibold"
          :class="mode === 'select' ? 'bg-ops-gold text-black' : 'bg-ops-plum/30 text-ops-text-muted'"
        >
          Select to split
        </button>
        <button
          @click="mode = 'wrong'"
          class="px-3 py-1 text-xs rounded uppercase font-semibold"
          :class="mode === 'wrong' ? 'bg-red-600 text-white' : 'bg-ops-plum/30 text-ops-text-muted'"
        >
          Wrong face
        </button>
      </div>

      <p class="text-xs text-ops-text-muted mb-2">
        {{ mode === 'select' ? 'Click faces to move them to a new cluster.' : 'Click faces that do NOT belong in this cluster.' }}
      </p>

      <!-- Face grid -->
      <div class="flex-1 overflow-y-auto">
        <div v-if="loadingFaces" class="text-center py-8">
          <div class="inline-block w-6 h-6 border-2 border-ops-peach border-t-transparent rounded-full animate-spin"></div>
        </div>
        <div v-else class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-2">
          <div
            v-for="face in faces"
            :key="face.id"
            class="aspect-square relative overflow-hidden rounded-lg border-2 cursor-pointer transition-all"
            :class="faceClass(face.id)"
            @click="toggleFace(face.id)"
          >
            <img
              :src="face.crop_url || `/api/media/face-crop/${face.id}`"
              class="w-full h-full object-cover"
              loading="lazy"
              @error="onImgError"
            />
            <!-- Selection indicator -->
            <div v-if="selectedFaces.has(face.id)" class="absolute inset-0 bg-ops-gold/20 flex items-center justify-center">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-ops-gold" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
            </div>
            <!-- Confidence -->
            <div v-if="face.match_confidence" class="absolute bottom-0.5 right-0.5 px-1 py-0.5 bg-black/70 rounded text-[9px] text-ops-text-muted">
              {{ Math.round(face.match_confidence * 100) }}%
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center justify-between mt-4 pt-3 border-t border-ops-plum/30">
        <div class="text-xs text-ops-text-muted">
          <button @click="selectAll" class="hover:text-ops-peach mr-3">Select all</button>
          <button @click="selectNone" class="hover:text-ops-peach">Select none</button>
        </div>
        <div class="flex gap-3">
          <button @click="$emit('close')" class="px-4 py-2 text-sm text-ops-text-muted hover:text-ops-text border border-ops-plum/40 rounded">
            Cancel
          </button>
          <button
            @click="submit"
            :disabled="selectedFaces.size === 0 || selectedFaces.size === faces.length"
            class="px-4 py-2 text-sm bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange disabled:opacity-40"
          >
            {{ mode === 'wrong' ? 'Remove Selected' : 'Split Selected' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  visible: Boolean,
  cluster: Object,
  faces: { type: Array, default: () => [] },
  loadingFaces: Boolean,
})

const emit = defineEmits(['close', 'confirm'])

const selectedFaces = ref(new Set())
const mode = ref('select')

watch(() => props.visible, (v) => {
  if (v) {
    selectedFaces.value = new Set()
    mode.value = 'select'
  }
})

function toggleFace(faceId) {
  const newSet = new Set(selectedFaces.value)
  if (newSet.has(faceId)) {
    newSet.delete(faceId)
  } else {
    newSet.add(faceId)
  }
  selectedFaces.value = newSet
}

function selectAll() {
  selectedFaces.value = new Set(props.faces.map(f => f.id))
}

function selectNone() {
  selectedFaces.value = new Set()
}

function faceClass(faceId) {
  if (selectedFaces.value.has(faceId)) {
    return mode.value === 'wrong' ? 'border-red-500' : 'border-ops-gold'
  }
  return 'border-transparent hover:border-ops-peach/40'
}

function submit() {
  emit('confirm', {
    faceIds: [...selectedFaces.value],
    mode: mode.value,
  })
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

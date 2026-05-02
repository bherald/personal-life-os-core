<template>
  <div v-if="visible" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center" @click.self="$emit('close')">
    <!-- Close button -->
    <button @click="$emit('close')" class="absolute top-4 right-4 text-white/60 hover:text-white z-10">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>

    <!-- Loading -->
    <div v-if="loading" class="text-center">
      <div class="inline-block w-8 h-8 border-2 border-ops-peach border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Photo with face bounding boxes -->
    <div v-else-if="photoData" class="relative max-w-[90vw] max-h-[90vh]">
      <img
        ref="photoImg"
        :src="`/api/media/${photoData.file.uuid}/thumbnail/large`"
        class="max-w-full max-h-[90vh] object-contain"
        @load="onImageLoad"
        @error="onImgError"
      />

      <!-- Face bounding boxes -->
      <template v-if="imageLoaded">
        <div
          v-for="face in photoData.faces"
          :key="face.id"
          class="absolute border-2 rounded transition-colors"
          :class="faceBoxClass(face)"
          :style="faceBoxStyle(face.region)"
          @click.stop="$emit('face-click', face)"
        >
          <!-- Label -->
          <div
            class="absolute -top-5 left-0 px-1.5 py-0.5 text-[10px] font-semibold rounded whitespace-nowrap"
            :class="faceLabelClass(face)"
          >
            {{ face.cluster_name || (face.is_current ? 'This face' : 'Unknown') }}
          </div>
        </div>
      </template>

      <!-- File info -->
      <div class="absolute bottom-0 left-0 right-0 bg-black/70 px-4 py-2 text-xs text-ops-text-muted">
        {{ photoData.file.filename }}
        <span class="ml-2">·</span>
        <span class="ml-2">{{ photoData.faces.length }} face{{ photoData.faces.length !== 1 ? 's' : '' }}</span>
      </div>
    </div>

    <!-- Error state -->
    <div v-else class="text-center text-ops-text-muted">
      <p>Could not load photo context</p>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  visible: Boolean,
  photoData: Object,
  loading: Boolean,
})

const emit = defineEmits(['close', 'face-click'])

const photoImg = ref(null)
const imageLoaded = ref(false)

watch(() => props.visible, (v) => {
  if (!v) imageLoaded.value = false
})

function onImageLoad() {
  imageLoaded.value = true
}

function faceBoxStyle(region) {
  if (!region) return {}
  return {
    left: `${region.x * 100}%`,
    top: `${region.y * 100}%`,
    width: `${region.w * 100}%`,
    height: `${region.h * 100}%`,
  }
}

function faceBoxClass(face) {
  if (face.is_current) return 'border-ops-gold'
  switch (face.cluster_status) {
    case 'confirmed': return 'border-green-400'
    case 'unreviewed': return 'border-yellow-400'
    case 'ignored': return 'border-gray-500'
    default: return 'border-ops-peach/50'
  }
}

function faceLabelClass(face) {
  if (face.is_current) return 'bg-ops-gold text-black'
  switch (face.cluster_status) {
    case 'confirmed': return 'bg-green-900 text-green-300'
    case 'unreviewed': return 'bg-yellow-900 text-yellow-300'
    case 'ignored': return 'bg-gray-700 text-gray-400'
    default: return 'bg-black/80 text-ops-text-muted'
  }
}

function onImgError(e) {
  e.target.src = ''
}
</script>

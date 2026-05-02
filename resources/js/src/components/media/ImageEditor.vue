<template>
  <div class="fixed inset-0 z-[60] bg-black/95 flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between p-3 bg-gray-900 border-b border-gray-700">
      <div class="text-white text-sm font-medium">Edit: {{ filename }}</div>
      <div class="flex items-center gap-2">
        <button @click="resetAll" class="px-3 py-1.5 text-sm text-gray-300 hover:text-white bg-gray-700 rounded">
          Reset
        </button>
        <button @click="save" :disabled="saving || operations.length === 0" class="px-4 py-1.5 text-sm text-white bg-blue-600 hover:bg-blue-700 rounded disabled:opacity-50">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
        <button @click="$emit('close')" class="px-3 py-1.5 text-sm text-gray-300 hover:text-white">
          Cancel
        </button>
      </div>
    </div>

    <!-- Main: Preview + Controls -->
    <div class="flex-1 flex overflow-hidden">
      <!-- Preview Area -->
      <div class="flex-1 flex items-center justify-center p-4 relative" ref="previewContainer">
        <img
          v-if="previewUrl"
          :src="previewUrl"
          class="max-w-full max-h-full object-contain"
          ref="previewImg"
          @load="onPreviewLoad"
        />
        <div v-else class="text-gray-500">Loading preview...</div>
        <div v-if="loadingPreview" class="absolute inset-0 flex items-center justify-center bg-black/50">
          <div class="text-white text-sm">Updating preview...</div>
        </div>
      </div>

      <!-- Controls Sidebar -->
      <div class="w-72 bg-gray-900 border-l border-gray-700 overflow-y-auto p-4 space-y-5">
        <!-- Operations Queue -->
        <div v-if="operations.length > 0">
          <h3 class="text-xs uppercase text-gray-500 mb-2">Pending Operations ({{ operations.length }})</h3>
          <div class="space-y-1">
            <div v-for="(op, i) in operations" :key="i" class="flex items-center justify-between bg-gray-800 rounded px-2 py-1 text-sm text-gray-300">
              <span>{{ opLabel(op) }}</span>
              <button @click="removeOp(i)" class="text-gray-500 hover:text-red-400 ml-2">&times;</button>
            </div>
          </div>
        </div>

        <!-- Rotate -->
        <div>
          <h3 class="text-xs uppercase text-gray-500 mb-2">Rotate</h3>
          <div class="flex gap-2">
            <button @click="addOp({ type: 'rotate', degrees: -90 })" class="flex-1 px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm">
              90&deg; CCW
            </button>
            <button @click="addOp({ type: 'rotate', degrees: 90 })" class="flex-1 px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm">
              90&deg; CW
            </button>
          </div>
        </div>

        <!-- Flip -->
        <div>
          <h3 class="text-xs uppercase text-gray-500 mb-2">Flip</h3>
          <div class="flex gap-2">
            <button @click="addOp({ type: 'flip', direction: 'horizontal' })" class="flex-1 px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm">
              Horizontal
            </button>
            <button @click="addOp({ type: 'flip', direction: 'vertical' })" class="flex-1 px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm">
              Vertical
            </button>
          </div>
        </div>

        <!-- Auto Orient -->
        <div>
          <h3 class="text-xs uppercase text-gray-500 mb-2">Auto</h3>
          <button @click="addOp({ type: 'autoOrient' })" class="w-full px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm">
            Auto-Orient (EXIF)
          </button>
        </div>

        <!-- Resize -->
        <div>
          <h3 class="text-xs uppercase text-gray-500 mb-2">Resize</h3>
          <div class="space-y-2">
            <div class="flex gap-2">
              <div class="flex-1">
                <label class="text-xs text-gray-500">Width</label>
                <input v-model.number="resizeWidth" type="number" min="1" class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-white" />
              </div>
              <div class="flex-1">
                <label class="text-xs text-gray-500">Height</label>
                <input v-model.number="resizeHeight" type="number" min="1" class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-white" />
              </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-400">
              <input type="checkbox" v-model="resizeAspectLock" class="rounded" />
              Lock aspect ratio
            </label>
            <button @click="addResizeOp" :disabled="!resizeWidth && !resizeHeight" class="w-full px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm disabled:opacity-50">
              Apply Resize
            </button>
          </div>
        </div>

        <!-- Crop -->
        <div>
          <h3 class="text-xs uppercase text-gray-500 mb-2">Crop</h3>
          <div class="space-y-2">
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-xs text-gray-500">X</label>
                <input v-model.number="cropX" type="number" min="0" class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-white" />
              </div>
              <div>
                <label class="text-xs text-gray-500">Y</label>
                <input v-model.number="cropY" type="number" min="0" class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-white" />
              </div>
              <div>
                <label class="text-xs text-gray-500">Width</label>
                <input v-model.number="cropWidth" type="number" min="1" class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-white" />
              </div>
              <div>
                <label class="text-xs text-gray-500">Height</label>
                <input v-model.number="cropHeight" type="number" min="1" class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-white" />
              </div>
            </div>
            <button @click="addCropOp" :disabled="!cropWidth || !cropHeight" class="w-full px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm disabled:opacity-50">
              Apply Crop
            </button>
          </div>
        </div>

        <!-- Brightness & Contrast -->
        <div>
          <h3 class="text-xs uppercase text-gray-500 mb-2">Adjustments</h3>
          <div class="space-y-3">
            <div>
              <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>Brightness</span>
                <span>{{ adjustBrightness }}</span>
              </div>
              <input type="range" v-model.number="adjustBrightness" min="-100" max="100" class="w-full accent-blue-500" />
            </div>
            <div>
              <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>Contrast</span>
                <span>{{ adjustContrast }}</span>
              </div>
              <input type="range" v-model.number="adjustContrast" min="-100" max="100" class="w-full accent-blue-500" />
            </div>
            <button @click="addAdjustOp" :disabled="adjustBrightness === 0 && adjustContrast === 0" class="w-full px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm disabled:opacity-50">
              Apply Adjustments
            </button>
          </div>
        </div>

        <!-- Version History -->
        <div>
          <h3 class="text-xs uppercase text-gray-500 mb-2">Version History</h3>
          <button @click="loadVersions" class="w-full px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 text-sm mb-2">
            {{ versions ? 'Refresh' : 'Load History' }}
          </button>
          <div v-if="versions" class="space-y-1 max-h-40 overflow-y-auto">
            <div v-if="versions.length === 0" class="text-xs text-gray-500">No previous versions</div>
            <div v-for="v in versions" :key="v.id" class="flex items-center justify-between bg-gray-800 rounded px-2 py-1.5 text-xs text-gray-300">
              <div>
                <div>v{{ v.version_number }}</div>
                <div class="text-gray-500">{{ v.change_description }}</div>
              </div>
              <button @click="restoreVersion(v.id)" class="text-blue-400 hover:text-blue-300 text-xs">Restore</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  uuid: { type: String, required: true },
  filename: { type: String, default: '' },
})

const emit = defineEmits(['close', 'saved'])

// Preview state
const previewUrl = ref(null)
const loadingPreview = ref(false)
const previewImg = ref(null)

// Operations queue
const operations = ref([])

// Resize controls
const resizeWidth = ref(null)
const resizeHeight = ref(null)
const resizeAspectLock = ref(true)

// Crop controls
const cropX = ref(0)
const cropY = ref(0)
const cropWidth = ref(null)
const cropHeight = ref(null)

// Adjustment controls
const adjustBrightness = ref(0)
const adjustContrast = ref(0)

// Save state
const saving = ref(false)

// Version history
const versions = ref(null)

// Initial preview load
onMounted(() => {
  loadPreview()
  document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  if (previewUrl.value && previewUrl.value.startsWith('blob:')) {
    URL.revokeObjectURL(previewUrl.value)
  }
  document.addEventListener('keydown', onKeydown)
})

function onKeydown(e) {
  if (e.key === 'Escape') {
    emit('close')
  }
}

async function loadPreview() {
  loadingPreview.value = true
  try {
    const response = await fetch(`/api/media/${props.uuid}/edit-preview`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ operations: operations.value }),
    })
    if (response.ok) {
      const blob = await response.blob()
      if (previewUrl.value && previewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(previewUrl.value)
      }
      previewUrl.value = URL.createObjectURL(blob)
    }
  } catch (e) {
    console.error('Preview load failed:', e)
  } finally {
    loadingPreview.value = false
  }
}

function onPreviewLoad() {
  // Could use this to set crop dimensions based on actual loaded image
}

function addOp(op) {
  operations.value.push(op)
  loadPreview()
}

function removeOp(index) {
  operations.value.splice(index, 1)
  loadPreview()
}

function addResizeOp() {
  addOp({
    type: 'resize',
    width: resizeWidth.value || null,
    height: resizeHeight.value || null,
    aspectLock: resizeAspectLock.value,
  })
  resizeWidth.value = null
  resizeHeight.value = null
}

function addCropOp() {
  addOp({
    type: 'crop',
    x: cropX.value,
    y: cropY.value,
    width: cropWidth.value,
    height: cropHeight.value,
  })
  cropX.value = 0
  cropY.value = 0
  cropWidth.value = null
  cropHeight.value = null
}

function addAdjustOp() {
  const adjustments = {}
  if (adjustBrightness.value !== 0) adjustments.brightness = adjustBrightness.value
  if (adjustContrast.value !== 0) adjustments.contrast = adjustContrast.value
  addOp({ type: 'adjust', adjustments })
  adjustBrightness.value = 0
  adjustContrast.value = 0
}

function resetAll() {
  operations.value = []
  loadPreview()
}

async function save() {
  if (operations.value.length === 0) return
  saving.value = true
  try {
    const response = await fetch(`/api/media/${props.uuid}/edit`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ operations: operations.value }),
    })
    const data = await response.json()
    if (data.success) {
      emit('saved')
      emit('close')
    } else {
      alert('Save failed: ' + (data.error || 'Unknown error'))
    }
  } catch (e) {
    alert('Save failed: ' + e.message)
  } finally {
    saving.value = false
  }
}

async function loadVersions() {
  try {
    const response = await fetch(`/api/media/${props.uuid}/versions`)
    const data = await response.json()
    if (data.success) {
      versions.value = data.data
    }
  } catch (e) {
    console.error('Failed to load versions:', e)
  }
}

async function restoreVersion(versionId) {
  if (!confirm('Restore this version? Current state will be saved as a new version first.')) return
  try {
    const response = await fetch(`/api/media/${props.uuid}/versions/${versionId}/restore`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
    })
    const data = await response.json()
    if (data.success) {
      operations.value = []
      loadPreview()
      loadVersions()
    } else {
      alert('Restore failed: ' + (data.error || 'Unknown error'))
    }
  } catch (e) {
    alert('Restore failed: ' + e.message)
  }
}

function opLabel(op) {
  switch (op.type) {
    case 'rotate': return `Rotate ${op.degrees > 0 ? op.degrees + '° CW' : Math.abs(op.degrees) + '° CCW'}`
    case 'flip': return `Flip ${op.direction}`
    case 'crop': return `Crop ${op.width}x${op.height}`
    case 'resize': return `Resize ${op.width || '?'}x${op.height || '?'}`
    case 'adjust': return 'Adjust B/C'
    case 'autoOrient': return 'Auto-Orient'
    default: return op.type
  }
}
</script>

<template>
  <div class="p-4">
    <!-- Empty state -->
    <div v-if="newFaces.length === 0 && !loading" class="text-center py-12 text-ops-text-muted">
      <p class="text-lg">All faces named!</p>
      <p class="text-sm mt-1">No unnamed faces remaining</p>
    </div>

    <!-- Face grid -->
    <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10 gap-2">
      <div
        v-for="face in newFaces"
        :key="face.face_id"
        class="group relative bg-ops-plum/20 rounded-lg overflow-hidden border transition-colors"
        :class="isSelected(face.face_id) ? 'border-ops-gold' : 'border-transparent hover:border-ops-peach/30'"
      >
        <!-- Face image -->
        <div
          class="aspect-square relative overflow-hidden cursor-pointer"
          @click="handleFaceClick(face, $event)"
        >
          <img
            :src="`/api/media/faces/registry-crop/${face.face_id}`"
            :alt="`Face ${face.face_id}`"
            class="w-full h-full object-cover"
            loading="lazy"
            @error="onImgError"
          />

          <!-- Hover overlay -->
          <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-start justify-end p-1 gap-1">
            <button
              @click.stop="$emit('hide', face.face_id)"
              class="p-1 rounded-full bg-black/60 text-ops-text-muted hover:text-ops-peach"
              title="Hide (H)"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
            </button>
          </div>

          <!-- Selection indicator -->
          <div v-if="isSelected(face.face_id)" class="absolute top-1 left-1 w-4 h-4 rounded-full bg-ops-gold flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5 text-black" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
          </div>

          <!-- Confidence badge -->
          <div v-if="face.confidence" class="absolute bottom-1 right-1 px-1 py-0.5 bg-black/70 rounded text-[10px] text-ops-text-muted">
            {{ Math.round(face.confidence) }}%
          </div>
        </div>

        <!-- Combobox input -->
        <div class="relative">
          <input
            :ref="el => { if (el) inputRefs[face.face_id] = el }"
            v-model="inputValues[face.face_id]"
            type="text"
            placeholder="Name..."
            class="w-full bg-black/40 border-t border-ops-plum/30 px-2 py-1.5 text-xs text-ops-text placeholder-ops-text-muted/40 focus:outline-none focus:border-ops-peach"
            @input="onInput(face.face_id)"
            @keydown.enter="onSubmit(face.face_id)"
            @keydown.escape="closeDropdown(face.face_id)"
            @focus="onFocus(face.face_id)"
            @blur="onBlur(face.face_id)"
          />

          <!-- Autocomplete dropdown -->
          <div
            v-if="activeDropdown === face.face_id && suggestions.length > 0"
            class="absolute left-0 right-0 bottom-full bg-black border border-ops-peach/40 rounded-t max-h-40 overflow-y-auto z-30"
          >
            <button
              v-for="(suggestion, idx) in suggestions"
              :key="idx"
              class="w-full px-2 py-1.5 text-xs text-left text-ops-text hover:bg-ops-plum/40 truncate"
              @mousedown.prevent="selectSuggestion(face.face_id, suggestion)"
            >
              {{ suggestion.name }}
              <span v-if="suggestion.media_count" class="text-ops-text-muted ml-1">({{ suggestion.media_count }})</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading indicator -->
    <div v-if="loading" class="text-center py-8">
      <div class="inline-block w-6 h-6 border-2 border-ops-peach border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Infinite scroll sentinel -->
    <div ref="sentinel" class="h-4"></div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import api from '../../utils/api'

const props = defineProps({
  newFaces: { type: Array, default: () => [] },
  loading: Boolean,
  hasMore: Boolean,
  selectedIds: { type: Set, default: () => new Set() },
})

const emit = defineEmits(['load-more', 'hide', 'name', 'select'])

const sentinel = ref(null)
const inputRefs = reactive({})
const inputValues = reactive({})
const suggestions = ref([])
const activeDropdown = ref(null)
let observer = null
let searchTimer = null
let blurTimer = null

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting && props.hasMore && !props.loading) {
        emit('load-more')
      }
    },
    { rootMargin: '200px' }
  )
  if (sentinel.value) observer.observe(sentinel.value)
})

onUnmounted(() => {
  observer?.disconnect()
  clearTimeout(searchTimer)
  clearTimeout(blurTimer)
})

function handleFaceClick(face, event) {
  if (event.shiftKey) {
    emit('select', { faceId: face.face_id, shift: true })
  } else if (props.selectedIds.size > 0) {
    emit('select', { faceId: face.face_id, shift: false })
  } else {
    // Focus the name input for this face
    const input = inputRefs[face.face_id]
    if (input) input.focus()
  }
}

function isSelected(faceId) {
  return props.selectedIds.has(faceId)
}

function onInput(faceId) {
  const query = (inputValues[faceId] || '').trim()
  clearTimeout(searchTimer)
  if (query.length < 2) {
    suggestions.value = []
    activeDropdown.value = null
    return
  }
  activeDropdown.value = faceId
  searchTimer = setTimeout(async () => {
    try {
      const result = await api.get('/media/genealogy-persons', { params: { search: query, limit: 10 } })
      suggestions.value = result.data || []
    } catch (e) {
      suggestions.value = []
    }
  }, 200)
}

function onFocus(faceId) {
  clearTimeout(blurTimer)
  const query = (inputValues[faceId] || '').trim()
  if (query.length >= 2) {
    activeDropdown.value = faceId
  }
}

function onBlur(faceId) {
  blurTimer = setTimeout(() => {
    if (activeDropdown.value === faceId) {
      activeDropdown.value = null
      suggestions.value = []
    }
    // Auto-submit on blur if name was typed
    const name = (inputValues[faceId] || '').trim()
    if (name.length > 0) {
      submitName(faceId, name)
    }
  }, 200)
}

function closeDropdown(faceId) {
  activeDropdown.value = null
  suggestions.value = []
  inputRefs[faceId]?.blur()
}

function selectSuggestion(faceId, suggestion) {
  const name = suggestion.name || suggestion.person_name
  inputValues[faceId] = name
  activeDropdown.value = null
  suggestions.value = []
  submitName(faceId, name, suggestion.genealogy_person_id || suggestion.id || null)
}

function onSubmit(faceId) {
  const name = (inputValues[faceId] || '').trim()
  if (!name) return
  activeDropdown.value = null
  suggestions.value = []
  submitName(faceId, name)
}

function submitName(faceId, name, genealogyPersonId = null) {
  if (!name) return
  emit('name', { faceId, name, genealogyPersonId })
  delete inputValues[faceId]
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

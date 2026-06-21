<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="$emit('close')">
    <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-4xl mx-4 p-6 max-h-[84vh] flex flex-col">
      <h3 class="text-ops-peach text-lg font-bold mb-1">
        Split {{ cluster?.name || 'selected cluster' }}
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
            class="group aspect-square relative overflow-hidden rounded-lg border-2 cursor-pointer transition-all"
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
            <button
              type="button"
              class="absolute top-1 right-1 rounded bg-black/75 p-1 text-ops-peach opacity-0 transition-opacity hover:bg-ops-peach hover:text-black group-hover:opacity-100 focus:opacity-100"
              title="Assign this face"
              @click.stop="openAssign([face.id])"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M8 9a3 3 0 100-6 3 3 0 000 6z" />
                <path fill-rule="evenodd" d="M8 11a6 6 0 00-5.917 5H8a5 5 0 013.72-4.832A5.98 5.98 0 008 11zm7-1a1 1 0 011 1v2h2a1 1 0 110 2h-2v2a1 1 0 11-2 0v-2h-2a1 1 0 110-2h2v-2a1 1 0 011-1z" clip-rule="evenodd" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <div v-if="assignmentVisible" class="mt-4 rounded border border-ops-peach/40 bg-black/40 p-3">
        <div class="mb-2 flex items-center justify-between gap-3">
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ops-peach">
              Assign {{ assignmentFaceIds.length }} face{{ assignmentFaceIds.length === 1 ? '' : 's' }}
            </div>
            <div class="text-[11px] text-ops-text-muted">
              FT selections run the genealogy bridge; standalone labels stay name-only and queue face metadata writeback.
            </div>
          </div>
          <button class="text-xs text-ops-text-muted hover:text-ops-peach" @click="closeAssign">Close</button>
        </div>

        <div class="relative">
          <input
            ref="assignmentInput"
            v-model="assignmentName"
            type="text"
            placeholder="Known face label or FT person..."
            class="w-full rounded border border-ops-peach/40 bg-black/50 px-3 py-2 text-sm text-ops-text outline-none focus:border-ops-peach"
            @input="onAssignmentInput"
            @keydown.enter="submitAssign"
            @keydown.escape="closeAssign"
          />
          <div v-if="assignmentSuggestions.length > 0" class="absolute left-0 right-0 top-full z-30 max-h-44 overflow-y-auto rounded-b border border-ops-peach/40 bg-black">
            <button
              v-for="suggestion in assignmentSuggestions"
              :key="suggestion.identity_key || `${suggestion.identity_type}:${suggestion.name}`"
              class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-ops-text hover:bg-ops-plum/40"
              @click="selectAssignmentSuggestion(suggestion)"
            >
              <span class="min-w-0 truncate">{{ suggestion.person_name || suggestion.face_label || suggestion.name }}</span>
              <span class="shrink-0 text-[10px] uppercase tracking-wide text-ops-text-muted">
                {{ suggestion.genealogy_person_id ? 'FT' : 'Face label' }} · {{ suggestion.face_count || suggestion.media_count || 0 }}
              </span>
            </button>
          </div>
        </div>

        <div class="mt-2 flex items-center justify-between gap-3">
          <span class="text-[11px]" :class="assignmentGenealogyPersonId ? 'text-ops-gold' : 'text-ops-text-muted'">
            {{ assignmentGenealogyPersonId ? `FT person ${assignmentGenealogyPersonId}` : 'Standalone name-only identity' }}
          </span>
          <button
            class="rounded bg-ops-peach px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-black hover:bg-ops-orange disabled:opacity-40"
            :disabled="!assignmentName.trim() || assignmentFaceIds.length === 0"
            @click="submitAssign"
          >
            Assign
          </button>
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
            @click="openAssign([...selectedFaces])"
            :disabled="selectedFaces.size === 0"
            class="px-4 py-2 text-sm border border-ops-peach/50 text-ops-peach rounded font-semibold hover:bg-ops-peach/10 disabled:opacity-40"
          >
            Assign Selected
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
import { nextTick, ref, watch } from 'vue'
import api from '../../utils/api'

const props = defineProps({
  visible: Boolean,
  cluster: Object,
  faces: { type: Array, default: () => [] },
  loadingFaces: Boolean,
  treeId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close', 'confirm'])

const selectedFaces = ref(new Set())
const mode = ref('select')
const assignmentVisible = ref(false)
const assignmentFaceIds = ref([])
const assignmentName = ref('')
const assignmentGenealogyPersonId = ref(null)
const assignmentSuggestions = ref([])
const assignmentInput = ref(null)
let assignmentSearchTimer = null

watch(() => props.visible, (v) => {
  if (v) {
    selectedFaces.value = new Set()
    mode.value = 'select'
    closeAssign()
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

function openAssign(faceIds) {
  assignmentFaceIds.value = [...new Set(faceIds.map(id => Number.parseInt(String(id), 10)).filter(id => Number.isFinite(id) && id > 0))]
  if (assignmentFaceIds.value.length === 0) return
  assignmentVisible.value = true
  assignmentName.value = ''
  assignmentGenealogyPersonId.value = null
  assignmentSuggestions.value = []
  nextTick(() => assignmentInput.value?.focus())
}

function closeAssign() {
  assignmentVisible.value = false
  assignmentFaceIds.value = []
  assignmentName.value = ''
  assignmentGenealogyPersonId.value = null
  assignmentSuggestions.value = []
  clearTimeout(assignmentSearchTimer)
}

function onAssignmentInput() {
  assignmentGenealogyPersonId.value = null
  clearTimeout(assignmentSearchTimer)
  const query = assignmentName.value.trim()
  if (query.length < 2) {
    assignmentSuggestions.value = []
    return
  }

  assignmentSearchTimer = setTimeout(async () => {
    try {
      const params = { search: query, limit: 20 }
      const treeId = parsePositiveInt(props.treeId)
      if (treeId) params.tree_id = treeId

      const result = await api.get('/media/genealogy-persons', { params })
      assignmentSuggestions.value = result.data || []
    } catch (e) {
      assignmentSuggestions.value = []
    }
  }, 200)
}

function selectAssignmentSuggestion(suggestion) {
  assignmentName.value = suggestion.person_name || suggestion.face_label || suggestion.name || suggestion.genealogy_name || ''
  assignmentGenealogyPersonId.value = parsePositiveInt(suggestion.genealogy_person_id) || parsePositiveInt(suggestion.id)
  assignmentSuggestions.value = []
}

function submitAssign() {
  const name = assignmentName.value.trim()
  if (!name || assignmentFaceIds.value.length === 0) return

  emit('confirm', {
    faceIds: [...assignmentFaceIds.value],
    mode: 'assign',
    assignment: {
      name,
      genealogyPersonId: assignmentGenealogyPersonId.value,
      treeId: parsePositiveInt(props.treeId),
    },
  })
}

function parsePositiveInt(value) {
  const parsed = Number.parseInt(String(value || ''), 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

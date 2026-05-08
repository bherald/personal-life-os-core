<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="$emit('close')">
    <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-md mx-4 p-6">
      <h3 class="text-ops-peach text-lg font-bold mb-1">
        {{ batch ? `Identify ${clusterIds.length} clusters` : 'Identify Cluster' }}
      </h3>
      <p v-if="cluster && !batch" class="text-xs text-ops-text-muted mb-4">
        {{ cluster.face_count }} faces · Selected cluster
      </p>

      <!-- Sample faces preview -->
      <div v-if="cluster?.sample_faces?.length && !batch" class="flex gap-1 mb-4">
        <img
          v-for="face in cluster.sample_faces.slice(0, 4)"
          :key="face.id"
          :src="`/api/media/face-crop/${face.id}`"
          class="w-12 h-12 rounded object-cover border border-ops-plum/40"
          @error="onImgError"
        />
      </div>

      <!-- Name input with autocomplete -->
      <div class="relative">
        <input
          ref="nameInput"
          v-model="name"
          type="text"
          placeholder="Person name..."
          class="w-full bg-black/50 border border-ops-peach/40 rounded px-3 py-2 text-ops-text focus:border-ops-peach focus:outline-none"
          @input="onNameInput"
          @keydown.enter="submit"
          @keydown.escape="$emit('close')"
        />
        <div v-if="suggestions.length > 0" class="absolute left-0 right-0 top-full bg-black border border-ops-peach/40 rounded-b max-h-40 overflow-y-auto z-30">
          <button
            v-for="(s, i) in suggestions"
            :key="i"
            class="w-full px-3 py-2 text-sm text-left text-ops-text hover:bg-ops-plum/40 flex items-center justify-between"
            @click="selectSuggestion(s)"
          >
            <span>{{ s.name }}</span>
            <span class="text-ops-text-muted text-xs">{{ s.media_count }} photos</span>
          </button>
        </div>
      </div>

      <!-- Genealogy person picker (optional) -->
      <div class="mt-3">
        <label class="text-xs text-ops-text-muted">Link to genealogy person (optional)</label>
        <input
          v-model="genealogySearch"
          type="text"
          placeholder="Search genealogy..."
          class="w-full bg-black/50 border border-ops-plum/30 rounded px-3 py-1.5 text-sm text-ops-text focus:border-ops-peach focus:outline-none mt-1"
          @input="onGenealogyInput"
        />
        <div v-if="genealogyResults.length > 0" class="bg-black border border-ops-plum/30 rounded-b max-h-32 overflow-y-auto">
          <button
            v-for="p in genealogyResults"
            :key="p.id"
            class="w-full px-3 py-1.5 text-xs text-left text-ops-text hover:bg-ops-plum/40"
            @click="selectGenealogyPerson(p)"
          >
            {{ personLabel(p) }}
            <span v-if="p.birth_year" class="text-ops-text-muted">(b. {{ p.birth_year }})</span>
          </button>
        </div>
        <div v-if="selectedGenealogy" class="mt-1 text-xs text-ops-gold">
          Linked: {{ personLabel(selectedGenealogy) }}
          <button @click="selectedGenealogy = null; genealogyPersonId = null" class="ml-1 text-ops-text-muted hover:text-red-400">×</button>
        </div>
      </div>

      <!-- Write to media checkbox -->
      <label class="flex items-center gap-2 mt-3 text-xs text-ops-text-muted cursor-pointer">
        <input v-model="writeToMedia" type="checkbox" class="rounded border-ops-plum/40 bg-black/50 text-ops-gold" />
        Write face names to image files (XMP metadata)
      </label>

      <!-- Actions -->
      <div class="flex justify-end gap-3 mt-5">
        <button @click="$emit('close')" class="px-4 py-2 text-sm text-ops-text-muted hover:text-ops-text border border-ops-plum/40 rounded">
          Cancel
        </button>
        <button @click="submit" :disabled="!name.trim()" class="px-4 py-2 text-sm bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange disabled:opacity-40">
          {{ batch ? 'Identify All' : 'Identify' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'
import api from '../../utils/api'

const props = defineProps({
  visible: Boolean,
  cluster: Object,
  clusterIds: { type: Array, default: () => [] },
  batch: { type: Boolean, default: false },
  treeId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close', 'confirm'])

const name = ref('')
const suggestions = ref([])
const genealogySearch = ref('')
const genealogyResults = ref([])
const genealogyPersonId = ref(null)
const selectedGenealogy = ref(null)
const writeToMedia = ref(true)
const nameInput = ref(null)
let searchTimer = null
let genealogyTimer = null

watch(() => props.visible, (v) => {
  if (v) {
    name.value = props.cluster?.name || ''
    suggestions.value = []
    genealogySearch.value = ''
    genealogyResults.value = []
    genealogyPersonId.value = null
    selectedGenealogy.value = null
    nextTick(() => nameInput.value?.focus())
  }
})

function onNameInput() {
  const q = name.value.trim()
  clearTimeout(searchTimer)
  if (q.length < 2) { suggestions.value = []; return }
  searchTimer = setTimeout(async () => {
    try {
      const params = { search: q, limit: 10 }
      const treeId = parsePositiveInt(props.treeId)
      if (treeId) params.tree_id = treeId

      const result = await api.get('/media/genealogy-persons', { params })
      suggestions.value = result.data || []
    } catch (e) {
      suggestions.value = []
    }
  }, 200)
}

function selectSuggestion(s) {
  name.value = s.name || s.person_name
  genealogyPersonId.value = s.genealogy_person_id || s.id || null
  selectedGenealogy.value = genealogyPersonId.value ? s : null
  suggestions.value = []
}

function onGenealogyInput() {
  const q = genealogySearch.value.trim()
  clearTimeout(genealogyTimer)
  if (q.length < 2) { genealogyResults.value = []; return }
  genealogyTimer = setTimeout(async () => {
    try {
      const params = { search: q, limit: 10 }
      const treeId = parsePositiveInt(props.treeId)
      if (treeId) params.tree_id = treeId

      const result = await api.get('/media/genealogy-persons', { params })
      genealogyResults.value = result.data || result.persons || []
    } catch (e) {
      genealogyResults.value = []
    }
  }, 300)
}

function selectGenealogyPerson(p) {
  genealogyPersonId.value = p.genealogy_person_id || p.id
  selectedGenealogy.value = p
  genealogyResults.value = []
  genealogySearch.value = ''
  if (!name.value.trim()) {
    name.value = personLabel(p)
  }
}

function personLabel(p) {
  return p?.name || p?.genealogy_name || `${p?.given_name || ''} ${p?.surname || ''}`.trim()
}

function submit() {
  const n = name.value.trim()
  if (!n) return
  emit('confirm', {
    name: n,
    genealogyPersonId: genealogyPersonId.value,
    writeToMedia: writeToMedia.value,
    treeId: parsePositiveInt(props.treeId),
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

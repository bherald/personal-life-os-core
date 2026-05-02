<template>
  <OpsPageWrapper title="PERSON FACES" :subtitle="personName" sectionCode="FC" colorScheme="peach" :showSidebar="false">
    <template #header-actions>
      <div class="flex items-center gap-2">
        <button @click="goBack" class="bg-ops-plum/50 text-ops-text px-4 py-1.5 rounded-r-full hover:bg-ops-plum font-semibold uppercase text-xs">
          &larr; Back
        </button>
        <button @click="refresh" class="bg-ops-orange text-black px-4 py-1.5 rounded-r-full hover:bg-ops-peach font-semibold uppercase text-xs">
          Refresh
        </button>
      </div>
    </template>

    <div class="flex flex-col h-[calc(100vh-8rem)]">
      <!-- Person header -->
      <div class="px-4 pt-3 pb-2 flex items-center gap-4">
        <div class="text-ops-text">
          <span class="text-lg font-semibold">{{ personName }}</span>
          <span class="text-sm text-ops-text-muted ml-2">{{ total }} face{{ total !== 1 ? 's' : '' }}</span>
        </div>
        <div class="flex-1"></div>
        <div v-if="excludedCount > 0" class="text-xs text-ops-gold">
          {{ excludedCount }} removed this session
        </div>
      </div>

      <!-- Face grid -->
      <div class="flex-1 overflow-y-auto p-4">
        <div v-if="faces.length === 0 && !loading" class="text-center py-12 text-ops-text-muted">
          <p class="text-lg">No faces found for this person</p>
        </div>

        <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10 gap-3">
          <div
            v-for="face in faces"
            :key="face.face_id"
            class="group relative bg-ops-plum/20 rounded-lg overflow-hidden border transition-colors"
            :class="selectedIds.has(face.face_id) ? 'border-ops-gold' : 'border-transparent hover:border-ops-peach/40'"
          >
            <!-- Face image -->
            <div class="aspect-square relative overflow-hidden cursor-pointer" @click="handleFaceClick(face, $event)">
              <img
                :src="`/api/media/faces/registry-crop/${face.face_id}`"
                :alt="face.filename"
                class="w-full h-full object-cover"
                loading="lazy"
                @error="onImgError"
              />

              <!-- Confidence badge -->
              <div class="absolute bottom-1 left-1 px-1.5 py-0.5 text-[10px] rounded bg-black/60 text-ops-text-muted">
                {{ Math.round((face.confidence || 0) * 100) }}%
              </div>

              <!-- Hover overlay with actions -->
              <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-3">
                <!-- Reassign to different person -->
                <button
                  @click.stop="openRenameSingle(face)"
                  class="p-2 rounded-full bg-ops-peach/80 text-black hover:bg-ops-orange"
                  title="Reassign to different person"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </button>
                <!-- Exclude (not this person) -->
                <button
                  @click.stop="excludeFace(face)"
                  class="p-2 rounded-full bg-red-900/80 text-red-300 hover:bg-red-800 hover:text-red-200"
                  title="Not this person — remove from group"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <!-- Selection indicator -->
              <div v-if="selectedIds.has(face.face_id)" class="absolute top-1 right-1 w-5 h-5 rounded-full bg-ops-gold flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-black" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
            </div>

            <!-- Filename -->
            <div class="px-1.5 py-1">
              <span class="text-[10px] text-ops-text-muted truncate block" :title="face.filename">
                {{ face.filename }}
              </span>
            </div>
          </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-8">
          <div class="inline-block w-6 h-6 border-2 border-ops-peach border-t-transparent rounded-full animate-spin"></div>
        </div>

        <!-- Infinite scroll sentinel -->
        <div ref="sentinel" class="h-4"></div>
      </div>

      <!-- Footer -->
      <div class="px-4 py-2 border-t border-ops-peach/20 flex items-center justify-between text-xs text-ops-text-muted">
        <span>{{ faces.length }} of {{ total }} face{{ total !== 1 ? 's' : '' }} loaded</span>
        <span v-if="selectedIds.size > 0" class="text-ops-gold">{{ selectedIds.size }} selected</span>
      </div>
    </div>

    <!-- Rename dialog -->
    <div v-if="renameDialogVisible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="renameDialogVisible = false">
      <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-sm mx-4 p-6">
        <h3 class="text-ops-peach text-lg font-bold mb-1">
          {{ renameBatch ? `Reassign ${selectedIds.size} faces` : 'Reassign face' }}
        </h3>
        <p class="text-xs text-ops-text-muted mb-4">Currently: {{ personName }}</p>
        <div class="relative">
          <input
            ref="renameInput"
            v-model="renameValue"
            type="text"
            placeholder="New person name..."
            class="w-full bg-black/50 border border-ops-peach/40 rounded px-3 py-2 text-ops-text focus:border-ops-peach focus:outline-none"
            @input="onRenameInput"
            @keydown.enter="submitRename"
            @keydown.escape="renameDialogVisible = false"
          />
          <div v-if="renameSuggestions.length > 0" class="absolute left-0 right-0 top-full bg-black border border-ops-peach/40 rounded-b max-h-40 overflow-y-auto z-30">
            <button
              v-for="(s, i) in renameSuggestions"
              :key="i"
              class="w-full px-3 py-2 text-sm text-left text-ops-text hover:bg-ops-plum/40"
              @click="renameValue = s.name; renameSuggestions = []; submitRename()"
            >
              {{ s.name }} <span class="text-ops-text-muted">({{ s.media_count }})</span>
            </button>
          </div>
        </div>
        <div class="flex justify-end gap-3 mt-4">
          <button @click="renameDialogVisible = false" class="px-4 py-2 text-sm text-ops-text-muted hover:text-ops-text border border-ops-plum/40 rounded">Cancel</button>
          <button @click="submitRename" :disabled="!renameValue.trim() || renameValue.trim() === personName" class="px-4 py-2 text-sm bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange disabled:opacity-40">Reassign</button>
        </div>
      </div>
    </div>

    <!-- Batch action bar -->
    <transition name="slide-up">
      <div v-if="selectedIds.size > 0" class="fixed bottom-4 left-1/2 -translate-x-1/2 z-40 bg-ops-plum border-t-2 border-ops-orange rounded-lg px-6 py-3 flex items-center gap-4 shadow-lg">
        <span class="text-ops-text font-semibold text-sm">{{ selectedIds.size }} selected</span>
        <div class="h-6 w-px bg-ops-orange/30"></div>
        <button @click="openRenameBatch" class="px-3 py-1.5 text-sm bg-ops-peach/80 text-black rounded font-semibold hover:bg-ops-peach">
          Reassign
        </button>
        <button @click="excludeSelected" class="px-3 py-1.5 text-sm bg-red-900/80 text-red-200 rounded font-semibold hover:bg-red-800">
          Remove All
        </button>
        <button @click="selectedIds.clear(); selectedIds = new Set(selectedIds)" class="ml-2 text-ops-text-muted hover:text-ops-text">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
        </button>
      </div>
    </transition>
  </OpsPageWrapper>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../utils/api'
import OpsPageWrapper from '../components/layout/OpsPageWrapper.vue'

const route = useRoute()
const router = useRouter()

const personName = ref(route.query.name || '')
const faces = ref([])
const total = ref(0)
const loading = ref(false)
const selectedIds = ref(new Set())
const excludedCount = ref(0)

const PAGE_SIZE = 60
const page = ref(0)
const hasMore = ref(true)

// Rename dialog
const renameDialogVisible = ref(false)
const renameValue = ref('')
const renameSuggestions = ref([])
const renameInput = ref(null)
const renameFaceId = ref(null)
const renameBatch = ref(false)
let renameSearchTimer = null

const sentinel = ref(null)
let observer = null

onMounted(() => {
  if (!personName.value) {
    router.replace('/media/faces')
    return
  }
  loadFaces(true)

  nextTick(() => {
    if (!sentinel.value) return
    const scrollParent = sentinel.value.closest('.overflow-y-auto')
    observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting && hasMore.value && !loading.value) {
          loadFaces(false)
        }
      },
      { root: scrollParent || null, rootMargin: '200px' }
    )
    observer.observe(sentinel.value)
  })
})

onUnmounted(() => {
  observer?.disconnect()
})

async function loadFaces(reset = false) {
  if (loading.value && !reset) return
  loading.value = true

  if (reset) {
    page.value = 0
    faces.value = []
    hasMore.value = true
  }

  try {
    const result = await api.get('/media/faces/person-faces', {
      params: {
        name: personName.value,
        limit: PAGE_SIZE,
        offset: page.value * PAGE_SIZE,
      }
    })

    const items = result.data || []
    if (reset) {
      faces.value = items
    } else {
      faces.value = [...faces.value, ...items]
    }
    total.value = result.total || 0
    hasMore.value = items.length >= PAGE_SIZE
    page.value++
  } catch (e) {
    console.error('Failed to load person faces', e)
  } finally {
    loading.value = false
  }
}

function handleFaceClick(face, event) {
  if (event.shiftKey) {
    // Shift+click always toggles selection
    if (selectedIds.value.has(face.face_id)) {
      selectedIds.value.delete(face.face_id)
    } else {
      selectedIds.value.add(face.face_id)
    }
    selectedIds.value = new Set(selectedIds.value)
  } else if (selectedIds.value.size > 0) {
    // If items selected, click toggles selection
    if (selectedIds.value.has(face.face_id)) {
      selectedIds.value.delete(face.face_id)
    } else {
      selectedIds.value.add(face.face_id)
    }
    selectedIds.value = new Set(selectedIds.value)
  } else {
    // Plain click opens rename dialog
    openRenameSingle(face)
  }
}

async function excludeFace(face) {
  try {
    await api.post(`/media/faces/${face.face_id}/exclude`)
    faces.value = faces.value.filter(f => f.face_id !== face.face_id)
    total.value--
    excludedCount.value++
  } catch (e) {
    console.error('Failed to exclude face', e)
  }
}

async function excludeSelected() {
  const ids = [...selectedIds.value]
  for (const id of ids) {
    try {
      await api.post(`/media/faces/${id}/exclude`)
    } catch (e) {
      console.error('Failed to exclude face', id, e)
    }
  }
  faces.value = faces.value.filter(f => !ids.includes(f.face_id))
  total.value -= ids.length
  excludedCount.value += ids.length
  selectedIds.value = new Set()
}

function openRenameSingle(face) {
  renameFaceId.value = face.face_id
  renameBatch.value = false
  renameValue.value = ''
  renameSuggestions.value = []
  renameDialogVisible.value = true
  nextTick(() => renameInput.value?.focus())
}

function openRenameBatch() {
  renameFaceId.value = null
  renameBatch.value = true
  renameValue.value = ''
  renameSuggestions.value = []
  renameDialogVisible.value = true
  nextTick(() => renameInput.value?.focus())
}

function onRenameInput() {
  clearTimeout(renameSearchTimer)
  const q = renameValue.value.trim()
  if (q.length < 2) { renameSuggestions.value = []; return }
  renameSearchTimer = setTimeout(async () => {
    try {
      const result = await api.get('/media/genealogy-persons', { params: { search: q, limit: 20 } })
      renameSuggestions.value = (result.data || []).filter(s => s.name !== personName.value)
    } catch (e) {
      renameSuggestions.value = []
    }
  }, 200)
}

async function submitRename() {
  const newName = renameValue.value.trim()
  if (!newName || newName === personName.value) return
  renameDialogVisible.value = false

  const ids = renameBatch.value ? [...selectedIds.value] : [renameFaceId.value]
  let renamed = 0
  for (const id of ids) {
    try {
      await api.post(`/media/faces/${id}/name`, { person_name: newName })
      renamed++
    } catch (e) {
      console.error('Failed to rename face', id, e)
    }
  }
  if (renamed > 0) {
    faces.value = faces.value.filter(f => !ids.includes(f.face_id))
    total.value -= renamed
    if (renameBatch.value) {
      selectedIds.value = new Set()
    }
  }
}

function refresh() {
  loadFaces(true)
}

function goBack() {
  router.push('/media/faces')
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.2s ease, opacity 0.2s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  transform: translate(-50%, 100%);
  opacity: 0;
}
</style>

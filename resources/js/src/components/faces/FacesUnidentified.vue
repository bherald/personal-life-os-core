<template>
  <div class="p-4">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <span class="text-xs text-ops-text-muted uppercase tracking-wide">
        {{ total }} unidentified face{{ total !== 1 ? 's' : '' }}
      </span>
      <div class="flex items-center gap-3">
        <!-- Pagination controls -->
        <div v-if="pages > 1" class="flex items-center gap-2 text-xs text-ops-text-muted">
          <button
            @click="changePage(currentPage - 1)"
            :disabled="currentPage <= 1"
            class="px-2 py-1 rounded border border-ops-plum/40 hover:border-ops-peach/40 disabled:opacity-30 disabled:cursor-not-allowed"
          >
            &lsaquo;
          </button>
          <span>{{ currentPage }} / {{ pages }}</span>
          <button
            @click="changePage(currentPage + 1)"
            :disabled="currentPage >= pages"
            class="px-2 py-1 rounded border border-ops-plum/40 hover:border-ops-peach/40 disabled:opacity-30 disabled:cursor-not-allowed"
          >
            &rsaquo;
          </button>
        </div>
      </div>
    </div>

    <!-- Batch actions bar -->
    <div v-if="selectedIds.size > 0" class="mb-4 flex items-center gap-3 p-3 bg-ops-plum/30 rounded-lg">
      <span class="text-ops-peach text-sm">{{ selectedIds.size }} selected</span>
      <button @click="handleBatchDismiss" class="ops-btn ops-btn-plum text-xs">Dismiss Selected</button>
      <button @click="selectedIds = new Set()" class="ops-btn ops-btn-plum text-xs">Clear</button>
    </div>

    <!-- Empty state -->
    <div v-if="faces.length === 0 && !loading" class="text-center py-16 text-ops-text-muted">
      <div class="text-4xl mb-3">✓</div>
      <p class="text-lg">No unidentified faces</p>
      <p class="text-sm mt-1 opacity-60">All faces have been reviewed</p>
    </div>

    <!-- Face grid -->
    <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10 gap-3">
      <div
        v-for="face in faces"
        :key="face.id"
        class="group relative bg-ops-plum/20 rounded-lg overflow-hidden border transition-colors"
        :class="selectedIds.has(face.id) ? 'border-ops-peach' : 'border-transparent hover:border-ops-peach/40'"
        @click="toggleSelect(face.id)"
      >
        <!-- Selection checkbox -->
        <div
          class="absolute top-1 left-1 z-10 w-4 h-4 rounded border flex items-center justify-center transition-opacity"
          :class="selectedIds.has(face.id) ? 'bg-ops-peach border-ops-peach opacity-100' : 'bg-black/50 border-white/40 opacity-0 group-hover:opacity-100'"
        >
          <svg v-if="selectedIds.has(face.id)" class="w-3 h-3 text-black" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
          </svg>
        </div>

        <!-- Face crop image -->
        <div class="aspect-square relative overflow-hidden">
          <img
            :src="`/api/media/face-match-crop/${face.id}`"
            alt="Unidentified face"
            class="w-full h-full object-cover"
            loading="lazy"
            @error="onImgError"
          />

          <!-- Hover overlay with actions -->
          <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center gap-1.5 p-1" @click.stop>
            <button
              @click.stop="openLinkModal(face.id)"
              class="w-full px-2 py-1 text-[10px] bg-ops-sky/90 text-black font-semibold rounded hover:bg-ops-sky leading-tight"
              title="Link to a person"
            >
              Link
            </button>
            <button
              @click.stop="handleDismiss(face.id)"
              class="w-full px-2 py-1 text-[10px] bg-ops-plum text-ops-text font-semibold rounded hover:bg-ops-plum/80 leading-tight"
              title="Dismiss this face"
            >
              Dismiss
            </button>
          </div>
        </div>

        <!-- Info -->
        <div class="px-1.5 py-1">
          <span class="text-[10px] text-ops-text-muted truncate block">
            {{ face.filename || formatDate(face.created_at) }}
          </span>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8">
      <div class="inline-block w-6 h-6 border-2 border-ops-peach border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Bottom pagination -->
    <div v-if="pages > 1 && !loading" class="flex items-center justify-center gap-3 mt-6">
      <button
        @click="changePage(currentPage - 1)"
        :disabled="currentPage <= 1"
        class="ops-btn ops-btn-plum text-sm disabled:opacity-30 disabled:cursor-not-allowed"
      >
        Previous
      </button>
      <span class="text-xs text-ops-text-muted">Page {{ currentPage }} of {{ pages }}</span>
      <button
        @click="changePage(currentPage + 1)"
        :disabled="currentPage >= pages"
        class="ops-btn ops-btn-plum text-sm disabled:opacity-30 disabled:cursor-not-allowed"
      >
        Next
      </button>
    </div>

    <!-- Link to Person Modal -->
    <Teleport to="body">
      <div v-if="showLinkModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" @click.self="closeLinkModal">
        <div class="bg-ops-black border border-ops-plum rounded-xl p-6 w-full max-w-sm mx-4 shadow-2xl">
          <h3 class="text-ops-peach font-bold uppercase tracking-wide mb-4">Link Face to Person</h3>
          <input
            ref="personSearchInput"
            v-model="personQuery"
            @input="onPersonSearch"
            placeholder="Search by name..."
            class="w-full bg-ops-plum/30 border border-ops-plum/60 rounded px-3 py-2 text-sm text-ops-text placeholder-ops-text-muted/50 focus:outline-none focus:border-ops-peach mb-3"
          />
          <div v-if="personResults.length > 0" class="max-h-48 overflow-y-auto space-y-1">
            <button
              v-for="p in personResults"
              :key="p.id"
              @click="handleLink(p)"
              class="w-full text-left px-3 py-2 rounded text-sm hover:bg-ops-plum/40 text-ops-text"
            >
              <span class="font-semibold">{{ p.given_name }} {{ p.surname }}</span>
              <span v-if="p.birth_date || p.death_date" class="text-ops-text-muted text-xs ml-2">
                {{ p.birth_date || '?' }} – {{ p.death_date || '' }}
              </span>
            </button>
          </div>
          <div v-else-if="personQuery.length >= 2 && !personSearching" class="text-sm text-ops-text-muted py-2">
            No persons found
          </div>
          <div v-if="personSearching" class="text-sm text-ops-text-muted py-2">Searching...</div>
          <div class="flex justify-end mt-4">
            <button @click="closeLinkModal" class="ops-btn ops-btn-plum text-sm">Cancel</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { useFacesData } from '../../composables/useFacesData'

const {
  unidentifiedFaces: faces,
  unidentifiedPage: currentPage,
  unidentifiedTotal: total,
  unidentifiedLoading: loading,
  unidentifiedPages: pages,
  loadUnidentified,
  dismissUnidentified,
  bulkDismissUnidentified,
  linkUnidentified,
  searchPersons,
} = useFacesData()

// Batch selection
const selectedIds = ref(new Set())

function toggleSelect(id) {
  const next = new Set(selectedIds.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  selectedIds.value = next
}

async function handleBatchDismiss() {
  const ids = [...selectedIds.value]
  selectedIds.value = new Set()
  await bulkDismissUnidentified(ids)
}

onMounted(() => {
  if (faces.value.length === 0) {
    loadUnidentified(1)
  }
})

function changePage(page) {
  if (page < 1 || page > pages.value) return
  selectedIds.value = new Set()
  loadUnidentified(page)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function handleDismiss(id) {
  selectedIds.value.delete(id)
  await dismissUnidentified(id)
}

// Link to person modal
const showLinkModal = ref(false)
const linkTargetId = ref(null)
const personQuery = ref('')
const personResults = ref([])
const personSearching = ref(false)
const personSearchInput = ref(null)

let searchTimer = null

function openLinkModal(id) {
  linkTargetId.value = id
  personQuery.value = ''
  personResults.value = []
  showLinkModal.value = true
  nextTick(() => personSearchInput.value?.focus())
}

function closeLinkModal() {
  showLinkModal.value = false
  linkTargetId.value = null
  personQuery.value = ''
  personResults.value = []
}

function onPersonSearch() {
  clearTimeout(searchTimer)
  if (personQuery.value.length < 2) {
    personResults.value = []
    return
  }
  personSearching.value = true
  searchTimer = setTimeout(async () => {
    personResults.value = await searchPersons(personQuery.value)
    personSearching.value = false
  }, 300)
}

async function handleLink(person) {
  const id = linkTargetId.value
  closeLinkModal()
  await linkUnidentified(id, person.id)
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  try {
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
  } catch {
    return dateStr
  }
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

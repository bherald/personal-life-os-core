<template>
  <OpsPageWrapper title="PERSON FACES" :subtitle="personName" sectionCode="FC" colorScheme="peach" :showSidebar="false" :class="{ 'global-person-gallery': isGlobal }">
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
      <div class="person-gallery-header px-4 pt-3 pb-2 flex items-center gap-4">
        <div class="text-ops-text">
          <span class="text-lg font-semibold">{{ personName }}</span>
          <span class="text-sm text-ops-text-muted ml-2">{{ total }} face{{ total !== 1 ? 's' : '' }}</span>
          <span v-if="linkedMediaTotal > 0" class="text-sm text-ops-gold ml-2">{{ linkedMediaTotal }} FT-linked photo{{ linkedMediaTotal === 1 ? '' : 's' }}</span>
          <div v-if="isGlobal && identity" class="global-person-summary">
            <p>Confirmed person · Record {{ identity.identity_uuid.slice(-8) }}</p>
            <p v-if="identity.aliases?.length">Aliases: {{ identity.aliases.join(', ') }}</p>
            <p v-for="mapping in identity.mappings" :key="`${mapping.tree_id}:${mapping.genealogy_person_id}`">{{ mapping.tree_name || 'Family tree' }} · {{ mapping.person_name || 'Person unavailable' }}</p>
            <p v-if="!identity.mappings?.length">No family tree mapping</p>
            <button type="button" class="global-person-button" @click="identityEditorVisible = true">Person names and aliases</button>
          </div>
        </div>
        <div class="flex-1"></div>
        <div v-if="excludedCount > 0" class="text-xs text-ops-gold">
          {{ excludedCount }} removed this session
        </div>
      </div>
      <p v-if="loadError" class="global-person-error" role="alert">{{ loadError }}</p>

      <!-- Face grid -->
      <div class="flex-1 overflow-y-auto p-4">
        <div v-if="faces.length === 0 && linkedMedia.length === 0 && !loading" class="text-center py-12 text-ops-text-muted">
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
              <img v-if="!isGlobal || (faceCropUrl(face) && !failedCrops.has(face.face_id))"
                :src="isGlobal ? faceCropUrl(face) : `/api/media/faces/registry-crop/${face.face_id}`"
                :alt="face.filename"
                class="w-full h-full object-cover"
                loading="lazy"
                @error="onFaceImgError($event, face)"
              />
              <p v-else class="global-crop-error">Photo changed or preview unavailable. Refresh.</p>

              <!-- Confidence badge -->
              <div v-if="!isGlobal" class="absolute bottom-1 left-1 px-1.5 py-0.5 text-[10px] rounded bg-black/60 text-ops-text-muted">
                {{ Math.round((face.confidence || 0) * 100) }}%
              </div>
              <div
                class="absolute left-1 top-1 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                :class="assignmentBadgeClass(face)"
              >
                {{ assignmentLabel(face) }}
              </div>

              <!-- Hover overlay with actions -->
              <div v-if="!isGlobal" class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-3">
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

        <div v-if="linkedMedia.length > 0" class="mt-6 border-t border-ops-peach/20 pt-4">
          <div class="mb-3 flex items-center justify-between gap-3">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wide text-ops-gold">
                FT-linked photos without confirmed face
              </div>
              <div class="text-[11px] text-ops-text-muted">
                These are linked to the FT person, but no visible face row matches this identity yet.
              </div>
            </div>
            <span class="text-xs text-ops-text-muted">{{ linkedMedia.length }} of {{ linkedMediaTotal }}</span>
          </div>

          <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10 gap-3">
            <div
              v-for="media in linkedMedia"
              :key="media.person_media_id"
              class="group relative overflow-hidden rounded-lg border border-ops-gold/30 bg-ops-plum/20"
            >
              <div class="aspect-square overflow-hidden bg-black/40">
                <img
                  v-if="media.thumbnail_url"
                  :src="media.thumbnail_url"
                  :alt="media.filename"
                  class="h-full w-full object-cover"
                  loading="lazy"
                  @error="onImgError"
                />
                <div v-else class="flex h-full w-full items-center justify-center text-xs text-ops-text-muted">
                  No preview
                </div>
              </div>
              <div class="absolute left-1 top-1 rounded bg-ops-gold/90 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-black">
                FT link
              </div>
              <div class="px-1.5 py-1">
                <span class="block truncate text-[10px] text-ops-text-muted" :title="media.filename">
                  {{ media.filename }}
                </span>
              </div>
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
    <div v-if="!isGlobal && renameDialogVisible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="renameDialogVisible = false">
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
            class="w-full bg-black/50 border border-ops-peach/40 rounded px-3 py-2 text-ops-text focus:border-ops-peach focus:outline-hidden"
            @input="onRenameInput"
            @keydown.enter="submitRename"
            @keydown.escape="renameDialogVisible = false"
          />
          <div v-if="renameSuggestions.length > 0" class="absolute left-0 right-0 top-full bg-black border border-ops-peach/40 rounded-b max-h-40 overflow-y-auto z-30">
            <button
              v-for="(s, i) in renameSuggestions"
              :key="i"
              class="w-full px-3 py-2 text-sm text-left text-ops-text hover:bg-ops-plum/40"
              @click="selectRenameSuggestion(s)"
            >
              {{ s.person_name || s.face_label || s.name }} <span class="text-ops-text-muted">({{ s.media_count || s.face_count || 0 }})</span>
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
      <div v-if="!isGlobal && selectedIds.size > 0" class="fixed bottom-4 left-1/2 -translate-x-1/2 z-40 bg-ops-plum border-t-2 border-ops-orange rounded-lg px-6 py-3 flex items-center gap-4 shadow-lg">
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
    <div v-if="isGlobal && identity && identityEditorVisible" class="global-person-editor" role="dialog" aria-modal="true" aria-label="Person names and aliases">
      <GlobalFaceIdentityEditor :initial-identity-uuid="identity.identity_uuid" @close="identityEditorVisible = false" />
    </div>
  </OpsPageWrapper>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../utils/api'
import OpsPageWrapper from '../components/layout/OpsPageWrapper.vue'
import GlobalFaceIdentityEditor from '../components/review/GlobalFaceIdentityEditor.vue'

const route = useRoute()
const router = useRouter()

const personName = ref(route.query.name || '')
const genealogyPersonId = ref(route.query.genealogy_person_id || '')
const identityUuid = computed(() => route.query.identity_uuid)
const isGlobal = computed(() => identityUuid.value !== undefined)
const identity = ref(null)
const identityEditorVisible = ref(false)
const failedCrops = ref(new Set())
const loadError = ref('')
let loadRequest = 0
const faces = ref([])
const total = ref(0)
const linkedMedia = ref([])
const linkedMediaTotal = ref(0)
const loading = ref(false)
const selectedIds = ref(new Set())
const excludedCount = ref(0)

const PAGE_SIZE = 60
const page = ref(0)
const hasMore = ref(true)

// Rename dialog
const renameDialogVisible = ref(false)
const renameValue = ref('')
const renameGenealogyPersonId = ref(null)
const renameSuggestions = ref([])
const renameInput = ref(null)
const renameFaceId = ref(null)
const renameBatch = ref(false)
let renameSearchTimer = null

const sentinel = ref(null)
let observer = null

onMounted(() => {
  if (!isGlobal.value && !personName.value && !genealogyPersonId.value) {
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
  loadRequest++
  observer?.disconnect()
})

async function loadFaces(reset = false) {
  if (loading.value && !reset) return
  loading.value = true
  const request = ++loadRequest
  const requestedUuid = identityUuid.value
  loadError.value = ''

  if (reset) {
    page.value = 0
    faces.value = []
    linkedMedia.value = []
    linkedMediaTotal.value = 0
    hasMore.value = true
    failedCrops.value = new Set()
    if (isGlobal.value) { identity.value = null; personName.value = 'Loading person…' }
  }

  try {
    const result = await api.get('/media/faces/person-faces', {
      params: isGlobal.value ? { identity_uuid: requestedUuid, limit: PAGE_SIZE, offset: page.value * PAGE_SIZE } : {
        name: personName.value,
        genealogy_person_id: genealogyPersonId.value || undefined,
        include_linked_media: reset && genealogyPersonId.value ? 1 : undefined,
        limit: PAGE_SIZE,
        offset: page.value * PAGE_SIZE,
      }
    })
    if (request !== loadRequest) return
    if (isGlobal.value) {
      if (result.identity_uuid !== requestedUuid || result.identity?.identity_uuid !== requestedUuid || result.trust_mode !== 'operator_confirmed') throw new Error('Individual record changed')
      identity.value = result.identity
    }

    const items = result.data || []
    if (reset) {
      faces.value = items
    } else {
      faces.value = [...faces.value, ...items]
    }
    total.value = result.total || 0
    if (reset) {
      linkedMedia.value = isGlobal.value ? [] : (result.linked_media || [])
      linkedMediaTotal.value = isGlobal.value ? 0 : (result.linked_media_total || linkedMedia.value.length)
    }
    if (result.person_name) {
      personName.value = isGlobal.value ? identity.value.canonical_name : result.person_name
    }
    hasMore.value = items.length >= PAGE_SIZE
    page.value++
  } catch (e) {
    if (request !== loadRequest) return
    if (isGlobal.value) { identity.value = null; faces.value = []; total.value = 0; personName.value = 'Person unavailable'; loadError.value = 'Person record unavailable. Refresh to try again.' }
    console.error('Failed to load person faces', e)
  } finally {
    if (request === loadRequest) loading.value = false
  }
}

function handleFaceClick(face, event) {
  if (isGlobal.value) return
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
  if (isGlobal.value) return
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
  if (isGlobal.value) return
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
  if (isGlobal.value) return
  renameFaceId.value = face.face_id
  renameBatch.value = false
  renameValue.value = ''
  renameGenealogyPersonId.value = null
  renameSuggestions.value = []
  renameDialogVisible.value = true
  nextTick(() => renameInput.value?.focus())
}

function openRenameBatch() {
  if (isGlobal.value) return
  renameFaceId.value = null
  renameBatch.value = true
  renameValue.value = ''
  renameGenealogyPersonId.value = null
  renameSuggestions.value = []
  renameDialogVisible.value = true
  nextTick(() => renameInput.value?.focus())
}

function onRenameInput() {
  if (isGlobal.value) return
  renameGenealogyPersonId.value = null
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

function selectRenameSuggestion(s) {
  if (isGlobal.value) return
  renameValue.value = s.person_name || s.face_label || s.name || s.genealogy_name || ''
  renameGenealogyPersonId.value = parsePositiveInt(s.genealogy_person_id) || parsePositiveInt(s.id)
  renameSuggestions.value = []
  submitRename()
}

async function submitRename() {
  if (isGlobal.value) return
  const newName = renameValue.value.trim()
  if (!newName || newName === personName.value) return
  renameDialogVisible.value = false

  const ids = renameBatch.value ? [...selectedIds.value] : [renameFaceId.value]
  try {
    const payload = { face_ids: ids, person_name: newName }
    if (renameGenealogyPersonId.value) {
      payload.genealogy_person_id = renameGenealogyPersonId.value
    }
    const result = await api.post('/media/faces/bulk-name', payload)
    const renamed = Number.parseInt(String(result?.updated ?? ids.length), 10) || ids.length

    if (renamed > 0) {
      faces.value = faces.value.filter(f => !ids.includes(f.face_id))
      total.value -= renamed
      if (renameBatch.value) {
        selectedIds.value = new Set()
      }
    }
  } catch (e) {
    console.error('Failed to reassign faces', ids, e)
  }
}

function parsePositiveInt(value) {
  const parsed = Number.parseInt(String(value || ''), 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
}

function assignmentState(face) {
  if (isGlobal.value) return 'global'
  const currentPersonId = parsePositiveInt(genealogyPersonId.value)
  const facePersonId = parsePositiveInt(face?.genealogy_person_id)

  if (currentPersonId && facePersonId === currentPersonId) {
    return 'ft'
  }

  if (facePersonId) {
    return 'other-ft'
  }

  return 'name-only'
}

function assignmentLabel(face) {
  if (face?.assignment_label) return face.assignment_label

  const state = assignmentState(face)
  if (state === 'ft') return 'FT'
  if (state === 'other-ft') return 'Other FT'

  return 'Name only'
}

function assignmentBadgeClass(face) {
  const state = assignmentState(face)
  if (state === 'global') return 'global-confirmed-badge'
  if (state === 'ft') return 'bg-ops-gold/90 text-black'
  if (state === 'other-ft') return 'bg-red-900/85 text-red-100'

  return 'bg-black/75 text-ops-peach'
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

function faceCropUrl(face) {
  const proof = face?.global_face_evidence
  if (face?.identity_uuid !== identityUuid.value || ![1, true].includes(face?.source_current)
    || face?.superseded_at !== null || ![0, false].includes(face?.hidden)
    || face?.source_sha256 !== proof?.source_sha256 || face?.face_region_convention !== 'normalized_top_left') return null
  try {
    const url = new URL(face.crop_url, location.origin)
    const keys = [...url.searchParams.keys()]
    if (!proof || proof.face_id !== face.face_id || proof.crop_url !== face.crop_url
      || url.origin !== location.origin || url.hash || url.username || url.password
      || url.pathname !== `/api/media/faces/registry-crop/${face.face_id}` || keys.length !== 2
      || !keys.includes('source_sha256') || !keys.includes('region_fingerprint')
      || !/^[a-f0-9]{64}$/.test(proof.source_sha256 || '') || !/^[a-f0-9]{64}$/.test(proof.region_fingerprint || '')
      || url.searchParams.get('source_sha256') !== proof.source_sha256 || url.searchParams.get('region_fingerprint') !== proof.region_fingerprint
      || proof.face_region_convention !== 'normalized_top_left' || proof.coordinate_basis !== 'encoded_pixels') return null
    return url.href
  } catch { return null }
}

function onFaceImgError(event, face) {
  if (!isGlobal.value) return onImgError(event)
  failedCrops.value = new Set([...failedCrops.value, face.face_id])
}

watch(() => [route.query.identity_uuid, route.query.name, route.query.genealogy_person_id], () => {
  identityEditorVisible.value = false
  renameDialogVisible.value = false
  selectedIds.value = new Set()
  personName.value = isGlobal.value ? '' : (route.query.name || '')
  genealogyPersonId.value = isGlobal.value ? '' : (route.query.genealogy_person_id || '')
  loadFaces(true)
})
</script>

<style scoped>
.global-person-summary { display: grid; gap: 5px; margin-top: 8px; color: var(--desk-muted); font-size: 13px; overflow-wrap: anywhere; }
.global-person-button { padding: 10px 14px; min-height: 44px; width: fit-content; border: 1px solid var(--desk-line); border-radius: 8px; color: var(--desk-ink); background: var(--desk-paper); font-weight: 650; }
.global-person-editor { position: fixed; inset: 0; z-index: 60; overflow-y: auto; padding: 20px; background: rgba(13, 34, 52, .65); display: grid; justify-items: center; align-content: start; }
.global-confirmed-badge { background: var(--desk-teal); color: white; }
.global-person-error, .global-crop-error { color: var(--desk-muted); padding: 12px; font-size: 12px; }
.global-person-gallery .person-gallery-header { flex-wrap: wrap; }
@media (max-width: 600px) { .global-person-editor { padding: 10px; } }
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

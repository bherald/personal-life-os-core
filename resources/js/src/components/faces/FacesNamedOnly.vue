<template>
  <div class="flex h-full min-h-0 flex-col lg:flex-row">
    <div class="min-w-0 flex-1 overflow-y-auto p-4">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
          <span class="text-xs uppercase tracking-wide text-ops-text-muted">
            {{ namedOnlyTotal }} {{ namedOnlyDecisionLabel }} named-only face{{ namedOnlyTotal === 1 ? '' : 's' }}
          </span>
          <div class="inline-flex rounded border border-ops-plum/40 bg-black/20 p-0.5">
            <button
              v-for="state in namedOnlyDecisionFilters"
              :key="state.value"
              class="min-w-[4rem] rounded px-2.5 py-1 text-xs font-semibold uppercase tracking-wide transition-colors"
              :class="namedOnlyDecisionState === state.value ? 'bg-ops-gold text-black' : 'text-ops-text-muted hover:text-ops-peach'"
              :disabled="namedOnlyLoading"
              @click="changeDecisionState(state.value)"
            >
              {{ state.label }}
            </button>
          </div>
          <label class="inline-flex items-center gap-1.5 rounded border border-ops-plum/40 px-2 py-1 text-xs uppercase tracking-wide text-ops-text-muted">
            <input
              type="checkbox"
              class="h-3.5 w-3.5 accent-ops-gold"
              :checked="namedOnlyStaleOnly"
              :disabled="namedOnlyLoading"
              @change="changeStaleOnly($event.target.checked)"
            />
            Stale
          </label>
          <div class="inline-flex rounded border border-ops-plum/40 bg-black/20 p-0.5">
            <button
              v-for="sort in namedOnlySortOptions"
              :key="sort.value"
              class="min-w-[4.5rem] rounded px-2.5 py-1 text-xs font-semibold uppercase tracking-wide transition-colors"
              :class="namedOnlySort === sort.value ? 'bg-ops-gold text-black' : 'text-ops-text-muted hover:text-ops-peach'"
              :disabled="namedOnlyLoading"
              @click="changeSort(sort.value)"
            >
              {{ sort.label }}
            </button>
          </div>
          <label class="inline-flex items-center gap-1.5 rounded border border-ops-plum/40 px-2 py-1 text-xs uppercase tracking-wide text-ops-text-muted">
            <input
              type="checkbox"
              class="h-3.5 w-3.5 accent-ops-gold"
              :checked="namedOnlyActiveOnly"
              :disabled="namedOnlyLoading"
              @change="changeActiveOnly($event.target.checked)"
            />
            Active files
          </label>
          <label class="inline-flex items-center gap-1.5 rounded border border-ops-plum/40 px-2 py-1 text-xs uppercase tracking-wide text-ops-text-muted">
            <input
              type="checkbox"
              class="h-3.5 w-3.5 accent-ops-gold"
              :checked="namedOnlyClusterScope === 'mixed'"
              :disabled="namedOnlyLoading"
              @change="changeMixedClusterOnly($event.target.checked)"
            />
            Mixed clusters
          </label>
          <form class="flex min-w-[14rem] items-center gap-2" @submit.prevent="applySearch">
            <input
              v-model="searchDraft"
              type="search"
              maxlength="100"
              class="min-w-0 flex-1 rounded border border-ops-plum/40 bg-black/20 px-2 py-1 text-xs text-ops-text outline-none placeholder:text-ops-text-muted/70 focus:border-ops-peach/50"
              placeholder="Name or file"
              :disabled="namedOnlyLoading"
            />
            <button
              class="rounded border border-ops-plum/40 px-2 py-1 text-xs uppercase tracking-wide text-ops-text-muted hover:border-ops-peach/40 hover:text-ops-peach disabled:opacity-40"
              :disabled="namedOnlyLoading"
              type="submit"
            >
              Search
            </button>
          </form>
        </div>
        <button
          class="rounded border border-ops-plum/40 px-3 py-1.5 text-xs uppercase tracking-wide text-ops-text-muted hover:border-ops-peach/40 hover:text-ops-peach disabled:opacity-40"
          :disabled="namedOnlyLoading"
          @click="loadNamedOnly(true)"
        >
          Refresh
        </button>
      </div>

      <div v-if="faces.length === 0 && !namedOnlyLoading" class="py-16 text-center text-ops-text-muted">
        <p class="text-lg">No {{ namedOnlyDecisionLabel }} named-only faces</p>
        <p class="mt-1 text-sm opacity-60">Named faces are linked, filtered, or awaiting new detections</p>
      </div>

      <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
        <button
          v-for="face in faces"
          :key="face.face_id"
          class="group min-w-0 overflow-hidden rounded-lg border bg-ops-plum/20 text-left transition-colors"
          :class="selectedFaceId === face.face_id ? 'border-ops-gold' : 'border-transparent hover:border-ops-peach/40'"
          @click="selectFace(face)"
        >
          <div class="relative aspect-square overflow-hidden">
            <img
              :src="`/api/media/faces/registry-crop/${face.face_id}`"
              :alt="faceImageAlt(face)"
              class="h-full w-full object-cover"
              loading="lazy"
              @error="onImgError"
            />
            <div v-if="face.confidence" class="absolute bottom-1 right-1 rounded bg-black/70 px-1 py-0.5 text-[10px] text-ops-text-muted">
              {{ Math.round(face.confidence) }}%
            </div>
            <div class="absolute left-1 top-1 flex max-w-[calc(100%-0.5rem)] flex-wrap gap-1">
              <span
                v-if="face.is_stale_named_only"
                class="rounded bg-ops-orange/90 px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-black"
              >
                Stale
              </span>
              <span
                v-if="hasCandidateDecision(face)"
                class="rounded bg-black/75 px-1 py-0.5 text-[10px] uppercase tracking-wide text-ops-gold"
              >
                {{ decisionBadge(face) }}
              </span>
              <span
                v-if="face.is_mixed_name_cluster"
                class="rounded bg-ops-gold/90 px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-black"
              >
                Mixed
              </span>
            </div>
          </div>
          <div class="min-w-0 px-2 py-1.5">
            <div class="truncate text-xs font-semibold text-ops-text" :title="face.person_name">
              {{ face.person_name || 'Unnamed' }}
            </div>
            <div class="truncate text-[10px] text-ops-text-muted" :title="faceFileLabel(face)">
              {{ faceFileLabel(face) }}
            </div>
            <div class="mt-1 flex items-center gap-1 text-[10px] text-ops-text-muted">
              <span>{{ formatAge(face.backlog_age_hours) }}</span>
              <span v-if="isTerminalDecision(face)">Done</span>
            </div>
            <div class="mt-1">
              <span
                class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                :class="photoDateBadgeClass(facePhotoDateBadge(face))"
              >
                {{ facePhotoDateBadge(face).label }}
              </span>
            </div>
          </div>
        </button>
      </div>

      <div v-if="namedOnlyLoading" class="py-8 text-center">
        <div class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-ops-peach border-t-transparent"></div>
      </div>

      <div v-if="hasMoreNamedOnly && !namedOnlyLoading" class="mt-6 flex justify-center">
        <button class="ops-btn ops-btn-plum text-sm" @click="loadMoreNamedOnly">
          Load More
        </button>
      </div>
    </div>

    <aside class="w-full border-t border-ops-peach/20 bg-black/20 p-4 lg:w-[26rem] lg:border-l lg:border-t-0">
      <div v-if="!selectedFace" class="py-10 text-center text-sm text-ops-text-muted">
        Select a named-only face
      </div>

      <div v-else class="space-y-4">
        <div class="flex gap-3">
          <img
            :src="`/api/media/faces/registry-crop/${selectedFace.face_id}`"
            :alt="faceImageAlt(selectedFace)"
            class="h-16 w-16 rounded border border-ops-plum/40 object-cover"
            @error="onImgError"
          />
          <div class="min-w-0 flex-1">
            <div class="truncate font-semibold text-ops-peach" :title="selectedFace.person_name">
              {{ selectedFace.person_name || 'Unnamed' }}
            </div>
            <div class="mt-1 text-xs text-ops-text-muted">Named-only face</div>
            <div class="mt-1 flex flex-wrap gap-1">
              <span class="rounded border border-ops-plum/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-ops-text-muted">
                {{ formatAge(selectedFace.backlog_age_hours) }}
              </span>
              <span
                class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                :class="photoDateBadgeClass(facePhotoDateBadge(selectedFace))"
              >
                {{ facePhotoDateBadge(selectedFace).label }}
              </span>
              <span
                v-if="selectedFace.is_stale_named_only"
                class="rounded border border-ops-orange/50 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-ops-orange"
              >
                Stale
              </span>
              <span
                v-if="hasCandidateDecision(selectedFace)"
                class="rounded border border-ops-gold/50 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-ops-gold"
              >
                {{ decisionBadge(selectedFace) }}
              </span>
            </div>
            <div class="mt-2 flex items-center gap-2">
              <button
                class="rounded border border-ops-plum/40 px-2 py-1 text-[10px] uppercase tracking-wide text-ops-text-muted hover:border-ops-peach/40 hover:text-ops-peach disabled:opacity-40"
                :disabled="candidateLoading"
                @click="loadCandidates(selectedFace.face_id)"
              >
                Refresh
              </button>
              <span v-if="candidatePayload?.suggested_action" class="text-[10px] uppercase tracking-wide text-ops-text-muted">
                {{ candidatePayload.suggested_action }}
              </span>
              <span v-if="candidatePayload?.candidate_state" class="text-[10px] uppercase tracking-wide text-ops-text-muted">
                {{ formatReason(candidatePayload.candidate_state) }}
              </span>
            </div>
          </div>
        </div>

        <div
          v-if="faceGenealogyPostureBadges(selectedFace).length"
          class="rounded border border-ops-plum/40 bg-black/30 p-3"
        >
          <div class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-ops-text-muted">
            Genealogy posture
          </div>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="badge in faceGenealogyPostureBadges(selectedFace)"
              :key="badge.key"
              class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
              :class="facePostureBadgeClass(badge)"
            >
              {{ badge.label }}
            </span>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="action in faceDecisionActions"
            :key="action.value"
            class="rounded border border-ops-plum/40 px-2 py-1.5 text-xs font-semibold uppercase tracking-wide text-ops-text-muted hover:border-ops-peach/40 hover:text-ops-peach disabled:opacity-40"
            :disabled="decisionLoadingKey !== ''"
            @click="recordDecision(action.value)"
          >
            {{ decisionLoadingKey === action.value ? 'Saving' : action.label }}
          </button>
        </div>

        <div
          v-if="hasCandidateDecision(selectedFace)"
          class="rounded border border-ops-plum/40 bg-black/30 p-3 text-xs text-ops-text-muted"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="uppercase tracking-wide">Decision</span>
            <span class="font-semibold text-ops-gold">{{ formatDecisionAction(selectedFace.candidate_decision_action) }}</span>
          </div>
          <div class="mt-2 flex items-center justify-between gap-3">
            <span class="uppercase tracking-wide">State</span>
            <span>{{ isTerminalDecision(selectedFace) ? 'Terminal' : 'Open' }}</span>
          </div>
          <div v-if="selectedFace.candidate_decision_at" class="mt-2 flex items-center justify-between gap-3">
            <span class="uppercase tracking-wide">Recorded</span>
            <span>{{ formatDateTime(selectedFace.candidate_decision_at) }}</span>
          </div>
        </div>

        <div v-if="candidateLoading" class="py-6 text-center">
          <div class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-ops-peach border-t-transparent"></div>
        </div>

        <div v-else-if="candidateError" class="rounded border border-ops-orange/40 bg-ops-orange/10 p-3 text-sm text-ops-orange">
          {{ candidateError }}
        </div>

        <div v-else-if="candidates.length === 0" class="rounded border border-ops-plum/40 p-3 text-sm text-ops-text-muted">
          No candidate matches found
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="candidate in candidates"
            :key="candidate.genealogy_person_id"
            class="rounded border border-ops-plum/40 bg-black/30 p-3"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-ops-text" :title="candidateDisplayName(candidate)">
                  {{ candidateDisplayName(candidate) }}
                </div>
                <div class="mt-0.5 text-xs text-ops-text-muted">
                  {{ formatLifeSpan(candidate) }}
                  <span v-if="candidate.face_count"> | {{ candidate.face_count }} linked face{{ candidate.face_count === 1 ? '' : 's' }}</span>
                </div>
              </div>
              <div class="shrink-0 text-right">
                <div class="text-sm font-semibold text-ops-gold">{{ formatScore(candidate.score) }}</div>
                <div class="text-[10px] uppercase text-ops-text-muted">score</div>
              </div>
            </div>

            <div v-if="candidate.reasons?.length" class="mt-2 flex flex-wrap gap-1">
              <span
                v-for="reason in candidate.reasons"
                :key="reason"
                class="rounded border border-ops-plum/40 px-1.5 py-0.5 text-[10px] text-ops-text-muted"
              >
                {{ formatReason(reason) }}
              </span>
            </div>

            <div v-if="candidateLifespanBadges(candidate).length" class="mt-2 flex flex-wrap gap-1">
              <span
                v-for="badge in candidateLifespanBadges(candidate)"
                :key="badge.key"
                class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                :class="lifespanBadgeClass(badge)"
              >
                {{ badge.label }}
              </span>
            </div>

            <div v-if="candidatePrivacyBadges(candidate).length" class="mt-2 flex flex-wrap gap-1">
              <span
                v-for="badge in candidatePrivacyBadges(candidate)"
                :key="badge.key"
                class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                :class="privacyBadgeClass(badge)"
              >
                {{ badge.label }}
              </span>
            </div>

            <div class="mt-3 flex justify-end gap-2">
              <button
                class="rounded border border-ops-orange/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-ops-orange hover:border-ops-peach/40 hover:text-ops-peach disabled:opacity-50"
                :disabled="decisionLoadingKey !== '' || linkingCandidateId !== null"
                @click="recordDecision('not_this_person', candidate)"
              >
                {{ decisionLoadingKey === `not_this_person:${candidate.genealogy_person_id}` ? 'Saving' : 'Not This Person' }}
              </button>
              <button
                class="rounded bg-ops-gold px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-black hover:bg-ops-peach disabled:opacity-50"
                :disabled="linkingCandidateId !== null || decisionLoadingKey !== ''"
                @click="linkCandidate(candidate)"
              >
                {{ linkingCandidateId === candidate.genealogy_person_id ? 'Linking' : 'Link' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </aside>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../../utils/api'
import { useFacesData } from '../../composables/useFacesData'

const props = defineProps({
  treeId: { type: [Number, String], default: null },
})

const emit = defineEmits(['linked', 'decided'])

const {
  namedOnlyFaces: faces,
  namedOnlyTotal,
  namedOnlyLoading,
  namedOnlyDecisionState,
  namedOnlyStaleOnly,
  namedOnlySort,
  namedOnlyActiveOnly,
  namedOnlyClusterScope,
  namedOnlySearch,
  hasMoreNamedOnly,
  loadNamedOnly,
  loadMoreNamedOnly,
  setNamedOnlyDecisionState,
  setNamedOnlyStaleOnly,
  setNamedOnlySort,
  setNamedOnlyActiveOnly,
  setNamedOnlyClusterScope,
  setNamedOnlySearch,
  linkNamedOnlyFace,
  decideNamedOnlyFace,
} = useFacesData()

const selectedFaceId = ref(null)
const searchDraft = ref(namedOnlySearch.value)
const candidatePayload = ref(null)
const candidateLoading = ref(false)
const candidateError = ref('')
const linkingCandidateId = ref(null)
const decisionLoadingKey = ref('')

const faceDecisionActions = [
  { value: 'keep_name_only', label: 'Keep Name Only' },
  { value: 'outside_tree', label: 'Outside Tree' },
  { value: 'too_vague', label: 'Too Vague' },
  { value: 'defer', label: 'Defer' },
]

const namedOnlyDecisionFilters = [
  { value: 'open', label: 'Open' },
  { value: 'decided', label: 'Decided' },
  { value: 'all', label: 'All' },
]
const namedOnlySortOptions = [
  { value: 'recent', label: 'Recent' },
  { value: 'oldest', label: 'Oldest' },
]

const selectedFace = computed(() => faces.value.find(face => face.face_id === selectedFaceId.value) || null)
const candidates = computed(() => candidatePayload.value?.candidates || [])
const namedOnlyDecisionLabel = computed(() => {
  const filter = namedOnlyDecisionFilters.find(item => item.value === namedOnlyDecisionState.value)
  return (filter?.label || 'Open').toLowerCase()
})

onMounted(() => {
  if (faces.value.length === 0) {
    loadNamedOnly(true)
  }
})

watch(faces, () => {
  if (selectedFaceId.value && !selectedFace.value) {
    clearCandidatePanel()
  }
})

async function selectFace(face) {
  if (!face?.face_id) return
  selectedFaceId.value = face.face_id
  await loadCandidates(face.face_id)
}

async function changeDecisionState(decisionState) {
  if (namedOnlyDecisionState.value === decisionState) return
  clearCandidatePanel()
  await setNamedOnlyDecisionState(decisionState)
}

async function changeStaleOnly(staleOnly) {
  clearCandidatePanel()
  await setNamedOnlyStaleOnly(staleOnly)
}

async function changeSort(sort) {
  if (namedOnlySort.value === sort) return
  clearCandidatePanel()
  await setNamedOnlySort(sort)
}

async function changeActiveOnly(activeOnly) {
  clearCandidatePanel()
  await setNamedOnlyActiveOnly(activeOnly)
}

async function changeMixedClusterOnly(mixedOnly) {
  clearCandidatePanel()
  await setNamedOnlyClusterScope(mixedOnly ? 'mixed' : 'all')
}

async function applySearch() {
  clearCandidatePanel()
  await setNamedOnlySearch(searchDraft.value)
}

async function loadCandidates(faceId = selectedFaceId.value) {
  if (!faceId) return

  candidateLoading.value = true
  candidateError.value = ''
  candidatePayload.value = null

  try {
    const params = { limit: 8 }
    const treeId = parsePositiveInt(props.treeId)
    if (treeId) params.tree_id = treeId

    candidatePayload.value = await api.get(`/media/faces/${faceId}/candidates`, { params })
  } catch (error) {
    candidateError.value = candidateErrorMessage(error)
  } finally {
    candidateLoading.value = false
  }
}

async function linkCandidate(candidate) {
  if (!selectedFaceId.value || !candidate?.genealogy_person_id) return

  linkingCandidateId.value = candidate.genealogy_person_id
  candidateError.value = ''

  try {
    const treeId = parsePositiveInt(candidate.tree_id)
      || parsePositiveInt(candidatePayload.value?.tree_id)
      || parsePositiveInt(props.treeId)

    await linkNamedOnlyFace(selectedFaceId.value, candidate.genealogy_person_id, treeId)
    emit('linked', {
      face_id: selectedFaceId.value,
      genealogy_person_id: candidate.genealogy_person_id,
      tree_id: treeId,
    })
    clearCandidatePanel()
  } catch (error) {
    candidateError.value = candidateErrorMessage(error)
  } finally {
    linkingCandidateId.value = null
  }
}

async function recordDecision(action, candidate = null) {
  if (!selectedFaceId.value || decisionLoadingKey.value !== '') return

  const key = candidate?.genealogy_person_id ? `${action}:${candidate.genealogy_person_id}` : action
  decisionLoadingKey.value = key
  candidateError.value = ''

  try {
    const treeId = parsePositiveInt(candidate?.tree_id)
      || parsePositiveInt(candidatePayload.value?.tree_id)
      || parsePositiveInt(props.treeId)
    const payload = { action }
    if (treeId) payload.tree_id = treeId
    if (candidate?.genealogy_person_id) payload.genealogy_person_id = candidate.genealogy_person_id

    const result = await decideNamedOnlyFace(selectedFaceId.value, payload)
    emit('decided', {
      face_id: selectedFaceId.value,
      action,
      terminal: result.decision?.terminal || false,
    })

    if (result.decision?.terminal) {
      clearCandidatePanel()
    } else {
      await loadCandidates(selectedFaceId.value)
    }
  } catch (error) {
    candidateError.value = candidateErrorMessage(error)
  } finally {
    decisionLoadingKey.value = ''
  }
}

function clearCandidatePanel() {
  selectedFaceId.value = null
  candidatePayload.value = null
  candidateError.value = ''
}

function candidateErrorMessage(error) {
  const data = error?.response?.data || {}

  if (data.error === 'tree_id_required') {
    return data.tree_count > 1
      ? `Select a genealogy tree before candidate review (${data.tree_count} trees available)`
      : 'Genealogy tree required'
  }

  if (data.error === 'face_hidden') return 'Face is hidden'
  if (data.error === 'face_already_linked') return 'Face is already linked'
  if (data.error === 'face_not_found') return 'Face not found'
  if (data.error === 'genealogy_person_not_found') return 'Genealogy person not found in this tree'
  if (data.error === 'genealogy_person_id_required') return 'Select a candidate first'
  if (data.error === 'genealogy_media_not_found') return 'Genealogy media row required before recording this decision'
  if (data.error === 'invalid_action') return 'Decision action is not available'

  return data.error || error?.message || 'Candidate lookup failed'
}

function parsePositiveInt(value) {
  const parsed = Number.parseInt(String(value || ''), 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
}

function formatScore(score) {
  const value = Number(score)
  if (!Number.isFinite(value)) return '0%'
  return `${Math.round(value * 100)}%`
}

function formatLifeSpan(candidate) {
  const birth = candidate.birth_date || '?'
  const death = candidate.death_date || ''
  return `${birth} - ${death || '?'}`
}

function candidateDisplayName(candidate) {
  const name = String(candidate?.name || '').trim()
  return name || 'Person reference'
}

function formatReason(reason) {
  return String(reason || '').replaceAll('_', ' ')
}

function numberValue(value) {
  if (value === null || value === undefined || value === '') return null

  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

const decisionActionLabels = {
  keep_name_only: 'keep name only',
  outside_tree: 'outside tree',
  too_vague: 'too vague',
  not_this_person: 'not this person',
  defer: 'defer',
}

function formatDecisionAction(action) {
  return decisionActionLabels[String(action || '').trim()] || ''
}

function hasCandidateDecision(face) {
  return Boolean(formatDecisionAction(face?.candidate_decision_action))
}

function faceGenealogyPostureBadges(face) {
  const posture = face?.face_genealogy_posture || {}
  const badges = []

  if (posture.projection_only === true) {
    badges.push({ key: 'projection-only', label: 'Review only', tone: 'safe' })
  }

  if (posture.operator_review_required === true || posture.operator_decision_available === true) {
    badges.push({ key: 'operator-decision', label: 'Manual decision', tone: 'safe' })
  }

  if (posture.operator_link_available === true) {
    badges.push({ key: 'operator-link', label: 'Manual link', tone: 'safe' })
  }

  if (posture.automation_allowed === false && posture.automatic_link_allowed === false) {
    badges.push({ key: 'no-automation', label: 'No automation', tone: 'hold' })
  }

  if (posture.create_person_allowed === false) {
    badges.push({ key: 'no-new-person', label: 'No new person', tone: 'hold' })
  }

  if (posture.metadata_writeback_allowed === false) {
    badges.push({ key: 'metadata-unchanged', label: 'Metadata unchanged', tone: 'hold' })
  }

  return badges
}

function facePostureBadgeClass(badge) {
  if (badge?.tone === 'hold') {
    return 'border-ops-gold/40 bg-ops-gold/10 text-ops-gold'
  }

  return 'border-ops-plum/40 bg-black/20 text-ops-text-muted'
}

function facePhotoDateBadge(face) {
  const photoYear = numberValue(face?.photo_date_context?.photo_year)

  if (photoYear !== null) {
    return { key: 'photo-year', label: `Photo ${photoYear}`, tone: 'muted' }
  }

  return { key: 'photo-date-missing', label: 'No photo date', tone: 'hold' }
}

function photoDateBadgeClass(badge) {
  if (badge?.tone === 'hold') {
    return 'border-ops-gold/40 bg-ops-gold/10 text-ops-gold'
  }

  return 'border-ops-plum/40 bg-black/20 text-ops-text-muted'
}

function candidateLifespanBadges(candidate) {
  const badges = []
  const photoYear = numberValue(candidate?.photo_date_context?.photo_year)
  const ageAtPhoto = numberValue(candidate?.age_at_photo)
  const fit = String(candidate?.lifespan_fit || '')

  if (photoYear !== null) {
    badges.push({ key: 'photo-year', label: `Photo ${photoYear}`, tone: 'muted' })
  }

  if (ageAtPhoto !== null) {
    badges.push({ key: 'age-at-photo', label: `Age ${ageAtPhoto}`, tone: 'muted' })
  }

  if (fit === 'before_birth') {
    badges.push({ key: 'photo-before-birth', label: 'Before birth', tone: 'warning' })
  } else if (fit === 'after_death') {
    badges.push({ key: 'photo-after-death', label: 'After death', tone: 'warning' })
  } else if (fit === 'unknown_lifespan' && photoYear !== null) {
    badges.push({ key: 'lifespan-unknown', label: 'Lifespan unknown', tone: 'muted' })
  }

  return badges
}

function lifespanBadgeClass(badge) {
  if (badge?.tone === 'warning') {
    return 'border-ops-orange/40 bg-ops-orange/10 text-ops-orange'
  }

  return 'border-ops-plum/40 bg-black/20 text-ops-text-muted'
}

function candidatePrivacyBadges(candidate) {
  const postureBadges = Array.isArray(candidate?.review_posture?.badges)
    ? candidate.review_posture.badges
    : []
  if (postureBadges.length) {
    return postureBadges.filter(badge => badge?.key && badge?.label)
  }

  const badges = []

  if (candidate?.requires_elevated_review) {
    badges.push({ key: 'elevated-review', label: 'Extra review', tone: 'warning' })
  }

  if (candidate?.living === true || candidate?.living_status === 'living') {
    badges.push({ key: 'living', label: 'Living', tone: 'warning' })
  } else if (candidate?.living_status === 'unknown') {
    badges.push({ key: 'living-unknown', label: 'Living unknown', tone: 'muted' })
  }

  if (candidate?.privacy_override && candidate.privacy_override !== 'default') {
    badges.push({
      key: `privacy-override-${candidate.privacy_override}`,
      label: `Person ${formatReason(candidate.privacy_override)}`,
      tone: candidate.privacy_override === 'public' ? 'muted' : 'warning',
    })
  }

  if (candidate?.tree_privacy) {
    badges.push({
      key: `tree-privacy-${candidate.tree_privacy}`,
      label: `Tree ${formatReason(candidate.tree_privacy)}`,
      tone: 'muted',
    })
  }

  if (candidate?.living_privacy && (candidate?.living === true || candidate?.living_status === 'unknown')) {
    badges.push({
      key: `living-privacy-${candidate.living_privacy}`,
      label: `Living ${formatReason(candidate.living_privacy)}`,
      tone: candidate.living_privacy === 'show_all' ? 'muted' : 'warning',
    })
  }

  return badges
}

function privacyBadgeClass(badge) {
  if (badge?.tone === 'warning') {
    return 'border-ops-orange/40 bg-ops-orange/10 text-ops-orange'
  }

  return 'border-ops-plum/40 bg-black/20 text-ops-text-muted'
}

function isTerminalDecision(face) {
  return String(face?.candidate_decision_terminal || '').toLowerCase() === 'true'
}

function decisionBadge(face) {
  const action = formatDecisionAction(face?.candidate_decision_action)
  if (!action) return ''

  return isTerminalDecision(face) ? action : `${action} pending`
}

function faceImageAlt(face) {
  return face?.person_name || 'Named-only face'
}

function faceFileLabel(face) {
  return face?.filename || 'Photo reference'
}

function formatAge(hours) {
  const value = Number(hours)
  if (!Number.isFinite(value) || value <= 0) return '0h'
  if (value < 24) return `${Math.round(value)}h`

  const days = Math.floor(value / 24)
  const remainder = Math.round(value % 24)
  return remainder > 0 ? `${days}d ${remainder}h` : `${days}d`
}

function formatDateTime(value) {
  if (!value) return ''

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

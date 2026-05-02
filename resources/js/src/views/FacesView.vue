<template>
  <component :is="embedded ? 'div' : OpsPageWrapper"
    v-bind="embedded ? { class: 'h-full flex flex-col' } : { title: 'FACES', subtitle: 'Face Management', sectionCode: 'FC', colorScheme: 'peach', showSidebar: false }"
  >
    <template v-if="!embedded" #header-actions>
      <div class="flex items-center gap-2">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search people..."
          class="bg-black/50 border border-ops-peach/40 rounded-r-full px-3 py-1.5 text-xs text-ops-text placeholder-ops-text-muted/40 focus:outline-none focus:border-ops-peach w-48"
          @input="debouncedSearch"
        />
        <button @click="handleRefresh" class="bg-ops-orange text-black px-4 py-1.5 rounded-r-full hover:bg-ops-peach font-semibold uppercase text-xs">
          Refresh
        </button>
      </div>
    </template>

    <div :class="embedded ? 'flex flex-col h-full' : 'flex flex-col h-[calc(100vh-8rem)]'">
      <!-- Tab bar -->
      <div class="px-4 pt-3 pb-2">
        <div class="flex items-center gap-1">
          <button
            v-for="tab in tabs"
            :key="tab.value"
            @click="switchTab(tab.value)"
            class="ops-nav-btn px-4 py-2 text-sm font-semibold uppercase tracking-wider whitespace-nowrap rounded-r-full transition-all"
            :class="activeTab === tab.value
              ? 'bg-ops-gold text-black'
              : 'bg-ops-plum/30 text-ops-text-muted hover:bg-ops-plum/50 hover:text-ops-peach'"
          >
            {{ tab.label }}
            <span v-if="tab.count !== undefined" class="ml-1 text-xs opacity-70">({{ formatCount(tab.count) }})</span>
          </button>

          <!-- Keyboard shortcut hint -->
          <button
            @click="helpVisible = true"
            class="ml-2 px-2 py-1 text-[10px] text-ops-text-muted hover:text-ops-peach border border-ops-plum/30 hover:border-ops-peach/40 rounded transition-colors"
            title="Keyboard shortcuts (?)"
          >
            <kbd class="font-mono">?</kbd> <span class="hidden sm:inline">shortcuts</span>
          </button>

          <div class="flex-1"></div>

          <!-- Search (embedded mode) -->
          <input
            v-if="embedded"
            v-model="searchQuery"
            type="text"
            placeholder="Search..."
            class="bg-black/50 border border-ops-peach/40 rounded px-3 py-1.5 text-xs text-ops-text placeholder-ops-text-muted/40 focus:outline-none focus:border-ops-peach w-40"
            @input="debouncedSearch"
          />

          <!-- Undo button -->
          <button
            v-if="currentHasUndo"
            @click="handleUndo"
            class="px-3 py-1.5 text-xs bg-ops-plum/30 text-ops-text-muted hover:text-ops-peach rounded-r-full"
            title="Undo (z)"
          >
            Undo
          </button>

          <!-- Session stats -->
          <div v-if="hasSessionActivity" class="text-xs text-ops-text-muted ml-2">
            <template v-if="activeTab === 'clusters'">
              <span v-if="clusterSessionStats.identified > 0" class="text-ops-gold">+{{ clusterSessionStats.identified }} identified</span>
              <span v-if="clusterSessionStats.hidden > 0" class="ml-1">{{ clusterSessionStats.hidden }} hidden</span>
              <span v-if="clusterSessionStats.merged > 0" class="ml-1 text-ops-peach">{{ clusterSessionStats.merged }} merged</span>
            </template>
            <template v-else>
              <span v-if="sessionStats.named > 0" class="text-ops-gold">+{{ sessionStats.named }} named</span>
              <span v-if="sessionStats.hidden > 0" class="ml-1">{{ sessionStats.hidden }} hidden</span>
            </template>
          </div>
        </div>
      </div>

      <div v-if="!embedded && genealogyBridgeTreeId" class="px-4 pb-2">
        <div class="border-y border-ops-peach/20 bg-black/30 px-3 py-2">
          <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
            <div class="min-w-0 pr-1">
              <div class="text-[10px] uppercase tracking-wider text-ops-text-muted">Genealogy Bridge</div>
              <div class="max-w-[16rem] truncate font-semibold text-ops-peach">
                {{ genealogyBridgeTreeName || `Tree ${genealogyBridgeTreeId}` }}
              </div>
            </div>

            <div
              v-for="item in genealogyBridgeItems"
              :key="item.label"
              class="flex items-baseline gap-1 whitespace-nowrap"
            >
              <span class="text-ops-text-muted">{{ item.label }}</span>
              <span :class="item.alert ? 'font-semibold text-ops-orange' : 'font-semibold text-ops-text'">
                {{ item.value }}
              </span>
            </div>

            <div class="flex-1"></div>

            <div v-if="genealogyBridgeStatsLoading" class="text-ops-text-muted">Loading</div>
            <button
              class="px-2 py-1 text-[10px] uppercase tracking-wider text-ops-text-muted hover:text-ops-peach border border-ops-plum/30 hover:border-ops-peach/40 rounded"
              :disabled="genealogyBridgeStatsLoading"
              @click="loadGenealogyBridgeStats"
            >
              Refresh
            </button>
          </div>

          <div v-if="genealogyBridgeWarnings.length" class="mt-2 flex flex-wrap gap-2 text-[11px]">
            <span
              v-for="warning in genealogyBridgeWarnings"
              :key="warning"
              class="rounded border border-ops-orange/40 bg-ops-orange/10 px-2 py-0.5 text-ops-orange"
            >
              {{ warning }}
            </span>
          </div>
          <div v-else-if="genealogyBridgeStatsError" class="mt-2 text-[11px] text-ops-orange">
            {{ genealogyBridgeStatsError }}
          </div>
        </div>
      </div>

      <!-- Tab content with optional sidebar -->
      <div class="flex-1 flex overflow-hidden">
        <!-- Main content area -->
        <div class="flex-1 overflow-y-auto">
          <!-- Clusters tab -->
          <FacesClustered
            v-if="activeTab === 'clusters'"
            :clusters="clusters"
            :loading="clusterLoading"
            :hasMore="hasMoreClusters"
            :filter="clusterFilter"
            :sort="clusterSort"
            :minFaces="clusterMinFaces"
            :total="clusterTotal"
            :selectedIds="selectedClusterIds"
            :focusedIndex="focusedClusterIndex"
            @load-more="loadMoreClusters"
            @identify="openClusterIdentifyDialog"
            @split="openClusterSplitDialog"
            @hide="handleClusterHide"
            @restore="handleClusterRestore"
            @toggle-select="toggleClusterSelect"
            @focus="handleClusterFocus"
            @photo-overlay="openPhotoOverlay"
            @filter-change="setClusterFilter"
            @sort-change="setClusterSort"
            @min-faces-change="v => { clusterMinFaces = v; loadClusters(true) }"
          />

          <!-- People tab -->
          <FacesRecognized
            v-if="activeTab === 'recognized'"
            :recognized="recognized"
            :loading="loading"
            :hasMore="hasMoreRecognized"
            @load-more="loadMoreRecognized"
            @edit="openEditDialog"
            @click="viewPersonMedia"
          />

          <!-- New tab -->
          <FacesNew
            v-if="activeTab === 'new'"
            :new-faces="newFaces"
            :loading="loading"
            :has-more="hasMoreNew"
            :selected-ids="selectedIds"
            @load-more="loadMoreNew"
            @hide="toggleHide"
            @name="handleNameFace"
            @select="handleNewFaceSelect"
          />

          <!-- Named-only tab -->
          <FacesNamedOnly
            v-if="activeTab === 'named_only'"
            :tree-id="genealogyBridgeTreeId"
            @linked="handleNamedOnlyLinked"
            @decided="handleNamedOnlyDecided"
          />

          <!-- Hidden tab -->
          <FacesHidden
            v-if="activeTab === 'hidden'"
            :faces="hiddenFaces"
            :loading="loading"
            :hasMore="hasMoreHidden"
            @load-more="loadMoreHidden"
            @unhide="handleUnhideFace"
          />

          <!-- Unidentified tab (N63) -->
          <FacesUnidentified v-if="activeTab === 'unidentified'" />
        </div>

        <!-- Similarity sidebar (only on clusters tab when a cluster is focused) -->
        <FaceSimilaritySidebar
          v-if="activeTab === 'clusters' && focusedCluster"
          :clusterId="focusedCluster.id"
          :clusterName="focusedCluster.name"
          :suggestions="sidebarSuggestions"
          :loading="sidebarLoading"
          :threshold="similarityThreshold"
          @scroll-to="scrollToCluster"
          @quick-merge="handleQuickMerge"
          @threshold-change="handleThresholdChange"
        />
      </div>

      <!-- Footer stats + keyboard hints -->
      <div v-if="!embedded" class="px-4 py-2 border-t border-ops-peach/20 flex items-center justify-between text-xs text-ops-text-muted">
        <template v-if="activeTab === 'clusters'">
          <span>{{ clusterStats.clusters_confirmed || 0 }} identified / {{ clusterStats.total_clusters || 0 }} clusters ({{ clusterStats.total_faces || 0 }} faces)</span>
        </template>
        <template v-else>
          <span>{{ stats.named_count }} named / {{ stats.total }} total ({{ progressPercent }}%)</span>
        </template>
        <div class="flex items-center gap-3">
          <span class="opacity-50">
            <template v-if="activeTab === 'clusters'">
              <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">j/k</kbd> navigate
              <span class="mx-1">|</span>
              <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">Enter</kbd> identify
              <span class="mx-1">|</span>
              <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">z</kbd> undo
              <span class="mx-1">|</span>
              <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">?</kbd> help
            </template>
            <template v-else>
              <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">Ctrl+Z</kbd> undo
              <span class="mx-1">|</span>
              <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">Esc</kbd> deselect
              <span class="mx-1">|</span>
              <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">Shift+click</kbd> multi-select
            </template>
          </span>
          <span>{{ stats.unique_people }} people | {{ stats.hidden_count }} hidden</span>
        </div>
      </div>
    </div>

    <!-- Face-level dialogs -->
    <FaceEditDialog
      :visible="editDialogVisible"
      :person="editDialogPerson"
      @close="editDialogVisible = false"
      @confirm="handleEditConfirm"
    />

    <FaceMergeDialog
      :visible="mergeDialogVisible"
      :sourceName="mergeSource"
      :targetName="mergeTarget"
      :sourceCount="mergeSourceCount"
      :targetCount="mergeTargetCount"
      @close="mergeDialogVisible = false"
      @confirm="handleMergeConfirm"
    />

    <!-- Bulk naming dialog (faces) -->
    <div v-if="bulkNameDialogVisible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="bulkNameDialogVisible = false">
      <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-sm mx-4 p-6">
        <h3 class="text-ops-peach text-lg font-bold mb-4">Name {{ selectedCount }} faces</h3>
        <div class="relative">
          <input
            ref="bulkNameInput"
            v-model="bulkNameValue"
            type="text"
            placeholder="Person name..."
            class="w-full bg-black/50 border border-ops-peach/40 rounded px-3 py-2 text-ops-text focus:border-ops-peach focus:outline-none"
            @input="onBulkNameInput"
            @keydown.enter="submitBulkName"
            @keydown.escape="bulkNameDialogVisible = false"
          />
          <div v-if="bulkNameSuggestions.length > 0" class="absolute left-0 right-0 top-full bg-black border border-ops-peach/40 rounded-b max-h-40 overflow-y-auto z-30">
            <button
              v-for="(s, i) in bulkNameSuggestions"
              :key="i"
              class="w-full px-3 py-2 text-sm text-left text-ops-text hover:bg-ops-plum/40"
              @click="selectBulkNameSuggestion(s)"
            >
              {{ s.name }} <span class="text-ops-text-muted">({{ s.media_count }})</span>
            </button>
          </div>
        </div>
        <div class="flex justify-end gap-3 mt-4">
          <button @click="bulkNameDialogVisible = false" class="px-4 py-2 text-sm text-ops-text-muted hover:text-ops-text border border-ops-plum/40 rounded">Cancel</button>
          <button @click="submitBulkName" :disabled="!bulkNameValue.trim()" class="px-4 py-2 text-sm bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange disabled:opacity-40">Name</button>
        </div>
      </div>
    </div>

    <!-- Cluster-level dialogs -->
    <FaceClusterIdentifyDialog
      :visible="identifyDialogVisible"
      :cluster="identifyDialogCluster"
      :clusterIds="identifyDialogBatchIds"
      :batch="identifyDialogBatchIds.length > 1"
      :tree-id="genealogyBridgeTreeId"
      @close="identifyDialogVisible = false"
      @confirm="handleIdentifyConfirm"
    />

    <FaceClusterSplitDialog
      :visible="splitDialogVisible"
      :cluster="splitDialogCluster"
      :faces="splitDialogFaces"
      :loadingFaces="splitDialogLoading"
      @close="splitDialogVisible = false"
      @confirm="handleSplitConfirm"
    />

    <FacePhotoOverlay
      :visible="photoOverlayVisible"
      :photoData="photoOverlayData"
      :loading="photoOverlayLoading"
      @close="photoOverlayVisible = false"
    />

    <FaceHelpOverlay
      :visible="showClusterHelp"
      :shortcuts="clusterShortcuts"
      @close="showClusterHelp = false"
    />

    <!-- Clipboard / batch action bar -->
    <FaceClipboard
      :selectedCount="currentSelectedCount"
      :isClusterTab="activeTab === 'clusters'"
      @name-all="handleBatchAction('name')"
      @hide-all="handleBatchAction('hide')"
      @identify-all="handleBatchAction('identify')"
      @merge-all="handleBatchAction('merge')"
      @clear="handleClearSelection"
    />
  </component>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFacesData } from '../composables/useFacesData'
import { useFaceClusterData } from '../composables/useFaceClusterData'
import api from '../utils/api'
import OpsPageWrapper from '../components/layout/OpsPageWrapper.vue'
import FacesRecognized from '../components/faces/FacesRecognized.vue'
import FacesNew from '../components/faces/FacesNew.vue'
import FacesNamedOnly from '../components/faces/FacesNamedOnly.vue'
import FacesClustered from '../components/faces/FacesClustered.vue'
import FaceEditDialog from '../components/faces/FaceEditDialog.vue'
import FaceMergeDialog from '../components/faces/FaceMergeDialog.vue'
import FaceClipboard from '../components/faces/FaceClipboard.vue'
import FacesHidden from '../components/faces/FacesHidden.vue'
import FacesUnidentified from '../components/faces/FacesUnidentified.vue'
import FaceClusterIdentifyDialog from '../components/faces/FaceClusterIdentifyDialog.vue'
import FaceClusterSplitDialog from '../components/faces/FaceClusterSplitDialog.vue'
import FacePhotoOverlay from '../components/faces/FacePhotoOverlay.vue'
import FaceSimilaritySidebar from '../components/faces/FaceSimilaritySidebar.vue'
import FaceHelpOverlay from '../components/faces/FaceHelpOverlay.vue'

const props = defineProps({
  embedded: { type: Boolean, default: false },
})

const router = useRouter()
const route = useRoute()

// Face-level composable
const {
  stats, recognized, newFaces, loading, searchQuery,
  selectedIds, sessionStats, activeTab,
  hasMoreRecognized, hasMoreNew, hasMoreHidden, hiddenFaces,
  namedOnlyTotal,
  selectedCount, hasUndo, progressPercent,
  loadRecognized, loadNewFaces, loadMoreRecognized, loadMoreNew,
  loadHidden, loadMoreHidden, loadNamedOnly, unhideFace,
  nameFace, bulkName, bulkHide, toggleHide,
  renamePerson, searchPersons, undoLast,
  debouncedSearch, toggleSelect, selectRange, clearSelection,
  handleKeydown, refreshAll,
} = useFacesData()

// Cluster-level composable
const {
  clusters, clusterStats, selectedClusterIds, focusedClusterIndex,
  clusterLoading, clusterFilter, clusterSort, clusterMinFaces,
  clusterTotal, hasMoreClusters,
  sidebarSuggestions, sidebarLoading, similarityThreshold,
  clusterSessionStats, showClusterHelp, clusterShortcuts,
  selectedClusterCount, hasClusterUndo, focusedCluster,
  loadClusters, loadMoreClusters,
  identifyCluster, batchIdentifyClusters,
  mergeClusterIds, splitCluster, hideCluster, restoreCluster,
  loadSimilarClusters, loadClusterFaces, loadPhotoContext,
  undoLastClusterAction,
  toggleClusterSelect, clearClusterSelection,
  handleClusterKeydown, setClusterFilter, setClusterSort,
} = useFaceClusterData()

// Edit dialog (faces)
const editDialogVisible = ref(false)
const editDialogPerson = ref(null)

// Merge dialog (faces)
const mergeDialogVisible = ref(false)
const mergeSource = ref('')
const mergeTarget = ref('')
const mergeSourceCount = ref(0)
const mergeTargetCount = ref(0)

// Bulk name dialog (faces)
const bulkNameDialogVisible = ref(false)
const bulkNameValue = ref('')
const bulkNameGenealogyPersonId = ref(null)
const bulkNameSuggestions = ref([])
const bulkNameInput = ref(null)
let bulkSearchTimer = null

// Cluster identify dialog
const identifyDialogVisible = ref(false)
const identifyDialogCluster = ref(null)
const identifyDialogBatchIds = ref([])

// Cluster split dialog
const splitDialogVisible = ref(false)
const splitDialogCluster = ref(null)
const splitDialogFaces = ref([])
const splitDialogLoading = ref(false)

// Photo overlay
const photoOverlayVisible = ref(false)
const photoOverlayData = ref(null)
const photoOverlayLoading = ref(false)

// Genealogy bridge observability
const genealogyBridgeTreeId = ref(null)
const genealogyBridgeTreeName = ref('')
const genealogyBridgeStats = ref(null)
const genealogyBridgeStatsLoading = ref(false)
const genealogyBridgeStatsError = ref('')

// --- Tabs ---
const tabs = computed(() => [
  { label: 'Clusters', value: 'clusters', count: clusterStats.value.clusters_unreviewed },
  { label: 'People', value: 'recognized', count: stats.value.unique_people },
  { label: 'New', value: 'new', count: stats.value.unnamed_count },
  { label: 'Named Only', value: 'named_only', count: namedOnlyTabCount.value },
  { label: 'Hidden', value: 'hidden', count: stats.value.hidden_count },
  { label: 'Unidentified', value: 'unidentified', count: stats.value.unidentified_count },
])

// Default to clusters tab
activeTab.value = 'clusters'

// --- Computed ---
const currentHasUndo = computed(() => {
  if (activeTab.value === 'clusters') return hasClusterUndo.value
  return hasUndo.value
})

const currentSelectedCount = computed(() => {
  if (activeTab.value === 'clusters') return selectedClusterCount.value
  return selectedCount.value
})

const hasSessionActivity = computed(() => {
  if (activeTab.value === 'clusters') {
    const cs = clusterSessionStats.value
    return cs.identified > 0 || cs.hidden > 0 || cs.merged > 0 || cs.split > 0
  }
  return sessionStats.value.named > 0 || sessionStats.value.hidden > 0
})

const namedOnlyTabCount = computed(() => {
  if (namedOnlyTotal.value > 0) return namedOnlyTotal.value

  const faceRegistry = genealogyBridgeStats.value?.face_registry || {}
  const named = Number(faceRegistry.named_faces || 0)
  const linked = Number(faceRegistry.linked_faces || 0)

  return Math.max(0, named - linked)
})

const genealogyBridgeItems = computed(() => {
  const data = genealogyBridgeStats.value || {}
  const faceRegistry = data.face_registry || {}
  const queueHealth = data.queue_health || {}
  const bridgeIssues = data.bridge_issues || {}

  return [
    { label: 'Named', value: formatCount(faceRegistry.named_faces || 0) },
    { label: 'Linked', value: formatCount(faceRegistry.linked_faces || 0) },
    { label: 'Pending', value: formatCount(queueHealth.pending_total || 0), alert: (queueHealth.pending_total || 0) > 0 },
    { label: 'Fuzzy', value: formatCount(queueHealth.fuzzy_pending || 0), alert: (queueHealth.fuzzy_pending || 0) > 0 },
    { label: 'Oldest', value: formatAgeHours(queueHealth.oldest_pending_age_hours) },
    {
      label: 'Bridge Issues',
      value: formatCount(bridgeIssues.approved_missing_person_media || 0),
      alert: (bridgeIssues.approved_missing_person_media || 0) > 0,
    },
  ]
})

const genealogyBridgeWarnings = computed(() => {
  const data = genealogyBridgeStats.value || {}
  const queueHealth = data.queue_health || {}
  const bridgeIssues = data.bridge_issues || {}
  const warnings = []

  if ((queueHealth.stale_pending || 0) > 0) {
    warnings.push(`${formatCount(queueHealth.stale_pending)} stale pending`)
  }

  if ((queueHealth.stale_fuzzy_pending || 0) > 0) {
    warnings.push(`${formatCount(queueHealth.stale_fuzzy_pending)} stale fuzzy`)
  }

  if ((bridgeIssues.approved_missing_person_media || 0) > 0) {
    warnings.push(`${formatCount(bridgeIssues.approved_missing_person_media)} approved without person-media link`)
  }

  return warnings
})

// --- Lifecycle ---

onMounted(() => {
  loadClusters(true)
  loadRecognized(true)
  loadNewFaces(true) // populates stats (unique_people, hidden_count) for tab badges
  loadNamedOnly(true)
  initializeGenealogyBridgeStats()
  document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
})

// --- Unified keyboard handler ---
function onKeydown(e) {
  if (activeTab.value === 'clusters') {
    // Let cluster composable handle navigation keys
    const handled = handleClusterKeydown(e)
    if (handled) return

    // Handle dialog-triggering keys here
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return

    switch (e.key) {
      case 'Enter':
        e.preventDefault()
        { const c = focusedCluster.value; if (c) openClusterIdentifyDialog(c) }
        break
      case 'Delete':
      case 'Backspace':
        e.preventDefault()
        { const c = focusedCluster.value; if (c) handleClusterHide(c.id) }
        break
      case 'b':
        e.preventDefault()
        if (selectedClusterIds.value.size > 0) {
          identifyDialogBatchIds.value = [...selectedClusterIds.value]
          identifyDialogCluster.value = null
          identifyDialogVisible.value = true
        }
        break
      case 's':
        e.preventDefault()
        { const c = focusedCluster.value; if (c) openClusterSplitDialog(c) }
        break
      case 'r':
        if (clusterFilter.value === 'hidden') {
          e.preventDefault()
          const c = focusedCluster.value
          if (c) handleClusterRestore(c.id)
        }
        break
    }
  } else {
    handleKeydown(e)
  }
}

// --- Tab switching ---
function switchTab(tab) {
  activeTab.value = tab
  clearSelection()
  clearClusterSelection()

  if (tab === 'clusters') {
    loadClusters(true)
  } else if (tab === 'hidden') {
    loadHidden(true)
  } else if (tab === 'recognized') {
    loadRecognized(true)
  } else if (tab === 'new') {
    loadNewFaces(true)
  } else if (tab === 'named_only') {
    loadNamedOnly(true)
  }
}

function handleRefresh() {
  if (activeTab.value === 'clusters') {
    loadClusters(true)
  } else if (activeTab.value === 'new') {
    loadNewFaces(true)
  } else if (activeTab.value === 'named_only') {
    loadNamedOnly(true)
  } else {
    refreshAll()
  }
  loadGenealogyBridgeStats()
}

// --- Cluster event handlers ---

function handleClusterFocus({ cluster, index }) {
  focusedClusterIndex.value = index
  loadSimilarClusters(cluster.id)
}

function openClusterIdentifyDialog(cluster) {
  identifyDialogCluster.value = cluster
  identifyDialogBatchIds.value = [cluster.id]
  identifyDialogVisible.value = true
}

async function handleIdentifyConfirm({ name, genealogyPersonId, writeToMedia, treeId }) {
  identifyDialogVisible.value = false

  if (identifyDialogBatchIds.value.length > 1) {
    await batchIdentifyClusters(identifyDialogBatchIds.value, name, genealogyPersonId, treeId)
  } else if (identifyDialogBatchIds.value.length === 1) {
    await identifyCluster(identifyDialogBatchIds.value[0], name, genealogyPersonId, writeToMedia, treeId)
  }
  loadGenealogyBridgeStats()
}

async function openClusterSplitDialog(cluster) {
  splitDialogCluster.value = cluster
  splitDialogVisible.value = true
  splitDialogLoading.value = true
  splitDialogFaces.value = await loadClusterFaces(cluster.id)
  splitDialogLoading.value = false
}

async function handleSplitConfirm({ faceIds, mode }) {
  splitDialogVisible.value = false
  if (splitDialogCluster.value) {
    await splitCluster(splitDialogCluster.value.id, faceIds)
  }
}

async function handleClusterHide(clusterId) {
  await hideCluster(clusterId)
}

async function handleClusterRestore(clusterId) {
  await restoreCluster(clusterId)
}

async function openPhotoOverlay(face) {
  if (!face?.id) return
  photoOverlayVisible.value = true
  photoOverlayLoading.value = true
  photoOverlayData.value = await loadPhotoContext(face.id)
  photoOverlayLoading.value = false
}

function scrollToCluster(clusterId) {
  const idx = clusters.value.findIndex(c => c.id === clusterId)
  if (idx >= 0) {
    focusedClusterIndex.value = idx
    // Scroll the card into view
    nextTick(() => {
      const cards = document.querySelectorAll('[data-cluster-id]')
      cards[idx]?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    })
  }
}

async function handleQuickMerge(sourceClusterId) {
  if (!focusedCluster.value) return
  await mergeClusterIds(focusedCluster.value.id, [sourceClusterId])
  loadSimilarClusters(focusedCluster.value.id) // Refresh sidebar
}

function handleThresholdChange(value) {
  similarityThreshold.value = value
  if (focusedCluster.value) {
    loadSimilarClusters(focusedCluster.value.id)
  }
}

// --- Face event handlers ---

async function handleNameFace({ faceId, name, genealogyPersonId = null }) {
  await nameFace(faceId, name, genealogyPersonId)
  loadGenealogyBridgeStats()
}

function handleNewFaceSelect({ faceId, shift }) {
  if (shift) {
    const allIds = newFaces.value.map(f => f.face_id)
    selectRange(faceId, allIds)
  } else {
    toggleSelect(faceId)
  }
}

function viewPersonMedia(person) {
  router.push({ path: '/media/faces/person', query: { name: person.person_name } })
}

async function handleUnhideFace(faceId) {
  await unhideFace(faceId)
}

function handleNamedOnlyLinked() {
  loadGenealogyBridgeStats()
}

function handleNamedOnlyDecided() {
  loadGenealogyBridgeStats()
}

// --- Edit dialog ---

function openEditDialog(person) {
  editDialogPerson.value = person
  editDialogVisible.value = true
}

async function handleEditConfirm(updated) {
  editDialogVisible.value = false
  if (updated.person_name !== updated.originalName) {
    const existingPerson = recognized.value.find(p => p.person_name === updated.person_name)
    if (existingPerson) {
      mergeSource.value = updated.originalName
      mergeTarget.value = updated.person_name
      mergeSourceCount.value = editDialogPerson.value?.face_count || 0
      mergeTargetCount.value = existingPerson.face_count || 0
      mergeDialogVisible.value = true
    } else {
      await renamePerson(updated.originalName, updated.person_name)
    }
  }
}

async function handleMergeConfirm() {
  mergeDialogVisible.value = false
  await renamePerson(mergeSource.value, mergeTarget.value)
}

// --- Batch actions (unified) ---

function handleBatchAction(action) {
  if (activeTab.value === 'clusters') {
    if (action === 'identify' || action === 'name') {
      if (selectedClusterIds.value.size > 0) {
        identifyDialogBatchIds.value = [...selectedClusterIds.value]
        identifyDialogCluster.value = null
        identifyDialogVisible.value = true
      }
    } else if (action === 'hide') {
      const ids = [...selectedClusterIds.value]
      ids.forEach(id => hideCluster(id))
      clearClusterSelection()
    } else if (action === 'merge') {
      const ids = [...selectedClusterIds.value]
      if (ids.length >= 2) {
        mergeClusterIds(ids[0], ids.slice(1))
        clearClusterSelection()
      }
    }
  } else {
    if (action === 'name') openBulkNameDialog()
    else if (action === 'hide') handleBulkHide()
  }
}

function handleClearSelection() {
  if (activeTab.value === 'clusters') {
    clearClusterSelection()
  } else {
    clearSelection()
  }
}

function handleUndo() {
  if (activeTab.value === 'clusters') {
    undoLastClusterAction()
  } else {
    undoLast()
  }
}

// --- Bulk name dialog ---

function openBulkNameDialog() {
  bulkNameValue.value = ''
  bulkNameGenealogyPersonId.value = null
  bulkNameSuggestions.value = []
  bulkNameDialogVisible.value = true
  nextTick(() => bulkNameInput.value?.focus())
}

function onBulkNameInput() {
  bulkNameGenealogyPersonId.value = null
  clearTimeout(bulkSearchTimer)
  const q = bulkNameValue.value.trim()
  if (q.length < 2) { bulkNameSuggestions.value = []; return }
  bulkSearchTimer = setTimeout(async () => {
    bulkNameSuggestions.value = await searchPersons(q)
  }, 200)
}

function selectBulkNameSuggestion(s) {
  bulkNameValue.value = s.name || s.person_name
  bulkNameGenealogyPersonId.value = s.genealogy_person_id || s.id || null
  bulkNameSuggestions.value = []
  submitBulkName()
}

async function submitBulkName() {
  const name = bulkNameValue.value.trim()
  if (!name || selectedIds.value.size === 0) return
  bulkNameDialogVisible.value = false
  await bulkName([...selectedIds.value], name, bulkNameGenealogyPersonId.value)
  loadGenealogyBridgeStats()
}

async function handleBulkHide() {
  if (selectedIds.value.size === 0) return
  await bulkHide([...selectedIds.value])
}

// --- Helpers ---

function formatCount(n) {
  if (n == null) return ''
  if (n >= 1000) return (n / 1000).toFixed(1) + 'k'
  return n
}

function formatAgeHours(hours) {
  if (hours === null || hours === undefined) return 'n/a'
  const value = Number(hours)
  if (!Number.isFinite(value)) return 'n/a'
  if (value >= 48) return `${Math.round(value / 24)}d`
  return `${Math.max(0, Math.round(value))}h`
}

function parseTreeId(value) {
  const parsed = Number.parseInt(String(value || ''), 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
}

async function initializeGenealogyBridgeStats() {
  if (props.embedded) return

  try {
    const result = await api.get('/genealogy/trees')
    const trees = result.data?.trees || []
    if (!trees.length) return

    const requestedTreeId = parseTreeId(route.query.tree_id || route.query.treeId)
    const savedTreeId = parseTreeId(localStorage.getItem('genealogy_selected_tree'))
    const selectedTree = trees.find(tree => tree.id === requestedTreeId)
      || trees.find(tree => tree.id === savedTreeId)
      || trees[0]

    genealogyBridgeTreeId.value = selectedTree.id
    genealogyBridgeTreeName.value = selectedTree.name || ''
    await loadGenealogyBridgeStats()
  } catch (error) {
    console.error('Failed to initialize genealogy bridge stats', error)
    genealogyBridgeStatsError.value = 'Genealogy bridge stats unavailable'
  }
}

async function loadGenealogyBridgeStats() {
  if (!genealogyBridgeTreeId.value || genealogyBridgeStatsLoading.value) return

  genealogyBridgeStatsLoading.value = true
  genealogyBridgeStatsError.value = ''

  try {
    const result = await api.get(`/genealogy/trees/${genealogyBridgeTreeId.value}/face-match-queue/stats`)
    genealogyBridgeStats.value = result.data || null
  } catch (error) {
    console.error('Failed to load genealogy bridge stats', error)
    genealogyBridgeStatsError.value = 'Genealogy bridge stats unavailable'
  } finally {
    genealogyBridgeStatsLoading.value = false
  }
}
</script>

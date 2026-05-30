import { ref, computed } from 'vue'
import api from '../utils/api'

// Shared cluster state
const clusters = ref([])
const clusterStats = ref({ total_faces: 0, total_clusters: 0, clusters_unreviewed: 0, clusters_confirmed: 0, clusters_ignored: 0 })
const selectedClusterIds = ref(new Set())
const focusedClusterIndex = ref(-1)
const clusterLoading = ref(false)
const clusterFilter = ref('all') // all, mixed, unidentified, identified, hidden
const clusterSort = ref('size_desc')
const clusterMinFaces = ref(1)
const clusterTotal = ref(0)
const clusterPage = ref(0)
const hasMoreClusters = ref(true)
const PAGE_SIZE = 50

// Sidebar
const sidebarClusterId = ref(null)
const sidebarSuggestions = ref([])
const sidebarLoading = ref(false)
const similarityThreshold = ref(0.5)

// Undo
const clusterUndoStack = ref([])
const MAX_UNDO = 10

// Session
const clusterSessionStats = ref({ identified: 0, hidden: 0, merged: 0, split: 0, undone: 0 })
const sessionStartTime = ref(Date.now())

// Help overlay
const showClusterHelp = ref(false)

// Shortcuts reference
const clusterShortcuts = [
  { key: 'j / →', label: 'Next cluster' },
  { key: 'k / ←', label: 'Previous cluster' },
  { key: 'Enter', label: 'Identify focused cluster' },
  { key: 'Space', label: 'Skip to next' },
  { key: 'Del', label: 'Hide cluster' },
  { key: 'x', label: 'Toggle selection' },
  { key: 'b', label: 'Batch identify selected' },
  { key: 's', label: 'Split cluster' },
  { key: 'w', label: 'Wrong face (remove from cluster)' },
  { key: 'z', label: 'Undo last action' },
  { key: 'r', label: 'Restore (hidden clusters)' },
  { key: '?', label: 'Toggle keyboard help' },
  { key: 'Esc', label: 'Close modal / deselect' },
]

export function useFaceClusterData() {

  // --- Load clusters ---

  async function loadClusters(reset = false) {
    if (clusterLoading.value && !reset) return
    clusterLoading.value = true

    if (reset) {
      clusterPage.value = 0
      clusters.value = []
      hasMoreClusters.value = true
    }

    try {
      const result = await api.get('/media/face-clusters', {
        params: {
          filter: clusterFilter.value,
          sort: clusterSort.value,
          min_faces: clusterMinFaces.value,
          limit: PAGE_SIZE,
          offset: clusterPage.value * PAGE_SIZE,
        }
      })

      const items = result.data || []
      if (reset) {
        clusters.value = items
      } else {
        clusters.value = [...clusters.value, ...items]
      }
      hasMoreClusters.value = items.length >= PAGE_SIZE
      clusterTotal.value = result.total || 0

      if (result.stats) {
        clusterStats.value = result.stats
      }

      clusterPage.value++
    } catch (e) {
      console.error('Failed to load clusters', e)
    } finally {
      clusterLoading.value = false
    }
  }

  async function loadMoreClusters() {
    if (!hasMoreClusters.value || clusterLoading.value) return
    await loadClusters(false)
  }

  // --- Identify ---

  async function identifyCluster(clusterId, name, genealogyPersonId = null, writeToMedia = true, treeId = null) {
    try {
      const payload = {
        name,
        genealogy_person_id: genealogyPersonId,
        write_to_media: writeToMedia,
        auto_propagate: true,
      }
      if (treeId) payload.tree_id = treeId

      const result = await api.post(`/media/faces/clusters/${clusterId}/identify`, payload)

      if (result.success) {
        // Save undo state
        const cluster = clusters.value.find(c => c.id === clusterId)
        if (cluster) {
          pushUndo({
            type: 'identify',
            clusterId,
            previousStatus: cluster.status,
            previousName: cluster.name,
          })
        }

        // Optimistic update
        if (result.action === 'merged') {
          clusters.value = clusters.value.filter(c => c.id !== clusterId)
        } else {
          const idx = clusters.value.findIndex(c => c.id === clusterId)
          if (idx >= 0) {
            clusters.value[idx] = { ...clusters.value[idx], name, status: 'confirmed' }
          }
        }

        clusterSessionStats.value.identified++

        // Auto-advance focus
        if (focusedClusterIndex.value >= 0 && focusedClusterIndex.value >= clusters.value.length) {
          focusedClusterIndex.value = Math.max(0, clusters.value.length - 1)
        }
      }

      return result
    } catch (e) {
      console.error('Failed to identify cluster', e)
      return { success: false, error: e.message }
    }
  }

  async function batchIdentifyClusters(clusterIds, name, genealogyPersonId = null, treeId = null) {
    try {
      const payload = {
        cluster_ids: clusterIds,
        name,
        genealogy_person_id: genealogyPersonId,
      }
      if (treeId) payload.tree_id = treeId

      const result = await api.post('/media/faces/clusters/batch-identify', payload)

      if (result.success) {
        pushUndo({
          type: 'batch_identify',
          clusterIds: [...clusterIds],
          previousStates: clusterIds.map(id => {
            const c = clusters.value.find(cl => cl.id === id)
            return { id, status: c?.status, name: c?.name }
          }),
        })

        // Remove identified from list if on unidentified filter
        if (clusterFilter.value === 'unidentified') {
          clusters.value = clusters.value.filter(c => !clusterIds.includes(c.id))
        }

        clusterSessionStats.value.identified += result.confirmed || clusterIds.length
        selectedClusterIds.value = new Set()
      }

      return result
    } catch (e) {
      console.error('Failed to batch identify', e)
      return { success: false, error: e.message }
    }
  }

  // --- Merge ---

  async function mergeClusterIds(targetId, sourceIds) {
    try {
      const result = await api.post('/media/face-clusters/merge', {
        target_id: targetId,
        source_ids: sourceIds,
      })

      if (result.success) {
        pushUndo({
          type: 'merge',
          targetId,
          sourceIds: [...sourceIds],
        })

        clusters.value = clusters.value.filter(c => !sourceIds.includes(c.id))
        const target = clusters.value.find(c => c.id === targetId)
        if (target) {
          target.face_count += sourceIds.reduce((sum, sid) => {
            const sc = clusters.value.find(c => c.id === sid)
            return sum + (sc?.face_count || 0)
          }, 0)
        }

        clusterSessionStats.value.merged++
      }

      return result
    } catch (e) {
      console.error('Failed to merge clusters', e)
      return { success: false }
    }
  }

  // --- Split ---

  async function splitCluster(clusterId, faceIds) {
    try {
      const result = await api.post(`/media/face-clusters/${clusterId}/split`, {
        face_ids: faceIds,
      })

      if (result.success) {
        pushUndo({
          type: 'split',
          sourceClusterId: clusterId,
          newClusterId: result.new_cluster_id,
          faceIds: [...faceIds],
        })

        // Update source cluster face count
        const source = clusters.value.find(c => c.id === clusterId)
        if (source) {
          source.face_count -= result.faces_moved || faceIds.length
        }

        clusterSessionStats.value.split++
      }

      return result
    } catch (e) {
      console.error('Failed to split cluster', e)
      return { success: false }
    }
  }

  // --- Hide ---

  async function hideCluster(clusterId) {
    try {
      const result = await api.post(`/media/faces/clusters/${clusterId}/hide`)

      if (result.success) {
        const cluster = clusters.value.find(c => c.id === clusterId)
        pushUndo({
          type: 'hide',
          clusterId,
          previousStatus: cluster?.status || 'unreviewed',
          previousName: cluster?.name,
        })

        if (clusterFilter.value !== 'hidden') {
          clusters.value = clusters.value.filter(c => c.id !== clusterId)
        } else {
          const idx = clusters.value.findIndex(c => c.id === clusterId)
          if (idx >= 0) {
            clusters.value[idx] = { ...clusters.value[idx], status: 'ignored' }
          }
        }

        clusterSessionStats.value.hidden++
      }

      return result
    } catch (e) {
      console.error('Failed to hide cluster', e)
      return { success: false }
    }
  }

  // --- Restore ---

  async function restoreCluster(clusterId) {
    try {
      const result = await api.post(`/media/faces/clusters/${clusterId}/restore`)

      if (result.success) {
        if (clusterFilter.value === 'hidden') {
          clusters.value = clusters.value.filter(c => c.id !== clusterId)
        }
      }

      return result
    } catch (e) {
      console.error('Failed to restore cluster', e)
      return { success: false }
    }
  }

  // --- Similarity sidebar ---

  async function loadSimilarClusters(clusterId) {
    sidebarClusterId.value = clusterId
    sidebarLoading.value = true
    sidebarSuggestions.value = []

    try {
      const result = await api.get(`/media/face-clusters/${clusterId}/similar`, {
        params: { tolerance: similarityThreshold.value, limit: 20 }
      })

      sidebarSuggestions.value = result.suggestions || []
    } catch (e) {
      console.error('Failed to load similar clusters', e)
    } finally {
      sidebarLoading.value = false
    }
  }

  // --- Cluster faces (for split UI) ---

  async function loadClusterFaces(clusterId) {
    try {
      const result = await api.get(`/media/face-clusters/${clusterId}/faces`)
      return result.faces || []
    } catch (e) {
      console.error('Failed to load cluster faces', e)
      return []
    }
  }

  // --- Photo context ---

  async function loadPhotoContext(faceEmbeddingId) {
    try {
      const result = await api.get(`/media/face/${faceEmbeddingId}/photo-context`)
      return result.data || null
    } catch (e) {
      console.error('Failed to load photo context', e)
      return null
    }
  }

  // --- Undo ---

  function pushUndo(action) {
    clusterUndoStack.value.push(action)
    if (clusterUndoStack.value.length > MAX_UNDO) {
      clusterUndoStack.value.shift()
    }
  }

  async function undoLastClusterAction() {
    if (clusterUndoStack.value.length === 0) return false

    const action = clusterUndoStack.value.pop()
    clusterSessionStats.value.undone++

    try {
      switch (action.type) {
        case 'identify':
        case 'hide':
          await api.post(`/media/face-clusters/${action.clusterId}/revert`, {
            previous_status: action.previousStatus || 'unreviewed',
            previous_name: action.previousName || null,
          })
          // Reload to get fresh data
          await loadClusters(true)
          break

        case 'batch_identify':
          for (const prev of action.previousStates) {
            await api.post(`/media/face-clusters/${prev.id}/revert`, {
              previous_status: prev.status || 'unreviewed',
              previous_name: prev.name || null,
            })
          }
          await loadClusters(true)
          break

        case 'merge':
          // Can't easily undo merges via API in batch, reload
          await loadClusters(true)
          break

        case 'split':
          if (action.newClusterId && action.sourceClusterId) {
            await api.post('/media/face-clusters/merge', {
              target_id: action.sourceClusterId,
              source_ids: [action.newClusterId],
            })
            await loadClusters(true)
          }
          break
      }

      return true
    } catch (e) {
      console.error('Undo failed', e)
      return false
    }
  }

  // --- Selection ---

  function toggleClusterSelect(clusterId) {
    const newSet = new Set(selectedClusterIds.value)
    if (newSet.has(clusterId)) {
      newSet.delete(clusterId)
    } else {
      newSet.add(clusterId)
    }
    selectedClusterIds.value = newSet
  }

  function clearClusterSelection() {
    selectedClusterIds.value = new Set()
  }

  // --- Focus / Keyboard ---

  function focusNext() {
    if (clusters.value.length === 0) return
    focusedClusterIndex.value = Math.min(focusedClusterIndex.value + 1, clusters.value.length - 1)
    const cluster = clusters.value[focusedClusterIndex.value]
    if (cluster) loadSimilarClusters(cluster.id)
  }

  function focusPrev() {
    if (clusters.value.length === 0) return
    focusedClusterIndex.value = Math.max(focusedClusterIndex.value - 1, 0)
    const cluster = clusters.value[focusedClusterIndex.value]
    if (cluster) loadSimilarClusters(cluster.id)
  }

  function getFocusedCluster() {
    if (focusedClusterIndex.value < 0 || focusedClusterIndex.value >= clusters.value.length) return null
    return clusters.value[focusedClusterIndex.value]
  }

  // Main keyboard handler for cluster tab
  function handleClusterKeydown(e) {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable) {
      if (e.key === 'Escape') {
        e.target.blur()
      }
      return false // not handled
    }

    if (e.key === '?' || (e.key === '/' && e.shiftKey)) {
      e.preventDefault()
      showClusterHelp.value = !showClusterHelp.value
      return true
    }

    if (e.key === 'Escape') {
      if (showClusterHelp.value) { showClusterHelp.value = false; e.preventDefault(); return true }
      clearClusterSelection()
      focusedClusterIndex.value = -1
      return true
    }

    switch (e.key) {
      case 'ArrowRight':
      case 'j':
        e.preventDefault()
        focusNext()
        return true

      case 'ArrowLeft':
      case 'k':
        e.preventDefault()
        focusPrev()
        return true

      case ' ':
        e.preventDefault()
        focusNext()
        return true

      case 'x':
        e.preventDefault()
        { const c = getFocusedCluster(); if (c) toggleClusterSelect(c.id) }
        return true

      case 'z':
        e.preventDefault()
        undoLastClusterAction()
        return true

      // Enter, Del, b, s, w, r handled by parent (they open dialogs)
      case 'Enter':
      case 'Delete':
      case 'Backspace':
      case 'b':
      case 's':
      case 'w':
      case 'r':
        return false // let parent handle
    }

    return false
  }

  // --- Computed ---

  const selectedClusterCount = computed(() => selectedClusterIds.value.size)
  const hasClusterUndo = computed(() => clusterUndoStack.value.length > 0)
  const sessionElapsed = computed(() => {
    const ms = Date.now() - sessionStartTime.value
    const min = Math.floor(ms / 60000)
    return min < 1 ? '<1m' : `${min}m`
  })
  const focusedCluster = computed(() => getFocusedCluster())

  // --- Filter change ---

  function setClusterFilter(filter) {
    clusterFilter.value = filter
    loadClusters(true)
  }

  function setClusterSort(sort) {
    clusterSort.value = sort
    loadClusters(true)
  }

  return {
    // State
    clusters,
    clusterStats,
    selectedClusterIds,
    focusedClusterIndex,
    clusterLoading,
    clusterFilter,
    clusterSort,
    clusterMinFaces,
    clusterTotal,
    hasMoreClusters,
    sidebarClusterId,
    sidebarSuggestions,
    sidebarLoading,
    similarityThreshold,
    clusterUndoStack,
    clusterSessionStats,
    showClusterHelp,
    clusterShortcuts,

    // Computed
    selectedClusterCount,
    hasClusterUndo,
    sessionElapsed,
    focusedCluster,

    // Methods
    loadClusters,
    loadMoreClusters,
    identifyCluster,
    batchIdentifyClusters,
    mergeClusterIds,
    splitCluster,
    hideCluster,
    restoreCluster,
    loadSimilarClusters,
    loadClusterFaces,
    loadPhotoContext,
    undoLastClusterAction,
    toggleClusterSelect,
    clearClusterSelection,
    focusNext,
    focusPrev,
    handleClusterKeydown,
    setClusterFilter,
    setClusterSort,
  }
}

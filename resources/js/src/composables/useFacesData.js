import { ref, computed } from 'vue'
import api from '../utils/api'

// Shared state across components
const stats = ref({ total: 0, named_count: 0, unnamed_count: 0, hidden_count: 0, unique_people: 0, unidentified_count: 0 })
const recognized = ref([])
const newFaces = ref([])
const loading = ref(false)
const searchQuery = ref('')
const showHidden = ref(false)
const selectedIds = ref(new Set())
const undoStack = ref([])
const sessionStats = ref({ named: 0, hidden: 0, undone: 0 })

// Pagination
const recognizedPage = ref(0)
const newFacesPage = ref(0)
const hiddenPage = ref(0)
const namedOnlyPage = ref(0)
const hasMoreRecognized = ref(true)
const hasMoreNew = ref(true)
const hasMoreHidden = ref(true)
const hasMoreNamedOnly = ref(true)
const hiddenFaces = ref([])
const namedOnlyFaces = ref([])
const namedOnlyTotal = ref(0)
const namedOnlyLoading = ref(false)
const PAGE_SIZE_RECOGNIZED = 60
const PAGE_SIZE_NEW = 50
const PAGE_SIZE_HIDDEN = 60
const PAGE_SIZE_NAMED_ONLY = 50

// Unidentified faces (N63)
const unidentifiedFaces = ref([])
const unidentifiedPage = ref(1)
const unidentifiedTotal = ref(0)
const unidentifiedLoading = ref(false)
const unidentifiedPages = ref(0)
const PAGE_SIZE_UNIDENTIFIED = 50

// Active tab
const activeTab = ref('new')

// Debounce timer
let searchTimer = null

export function useFacesData() {

  // --- API calls ---

  async function loadRecognized(reset = false) {
    if (loading.value && !reset) return
    loading.value = true

    if (reset) {
      recognizedPage.value = 0
      recognized.value = []
      hasMoreRecognized.value = true
    }

    try {
      const result = await api.get('/media/faces/recognized', {
        params: {
          limit: PAGE_SIZE_RECOGNIZED,
          offset: recognizedPage.value * PAGE_SIZE_RECOGNIZED,
          search: searchQuery.value || undefined,
          hidden: showHidden.value ? 1 : 0,
        }
      })

      const items = result.data || []
      if (reset) {
        recognized.value = items
      } else {
        recognized.value = [...recognized.value, ...items]
      }
      hasMoreRecognized.value = items.length >= PAGE_SIZE_RECOGNIZED
      recognizedPage.value++
    } catch (e) {
      console.error('Failed to load recognized faces', e)
    } finally {
      loading.value = false
    }
  }

  async function loadNewFaces(reset = false) {
    if (loading.value && !reset) return
    loading.value = true

    if (reset) {
      newFacesPage.value = 0
      newFaces.value = []
      hasMoreNew.value = true
    }

    try {
      const result = await api.get('/media/faces/new', {
        params: {
          limit: PAGE_SIZE_NEW,
          offset: newFacesPage.value * PAGE_SIZE_NEW,
        }
      })

      const items = result.data || []
      if (reset) {
        newFaces.value = items
      } else {
        newFaces.value = [...newFaces.value, ...items]
      }
      hasMoreNew.value = items.length >= PAGE_SIZE_NEW

      if (result.stats) {
        stats.value = result.stats
        stats.value.unidentified_count = result.stats.unidentified_count ?? stats.value.unidentified_count
      }
      newFacesPage.value++
    } catch (e) {
      console.error('Failed to load new faces', e)
    } finally {
      loading.value = false
    }
  }

  async function loadMoreRecognized() {
    if (!hasMoreRecognized.value || loading.value) return
    await loadRecognized(false)
  }

  async function loadMoreNew() {
    if (!hasMoreNew.value || loading.value) return
    await loadNewFaces(false)
  }

  async function loadHidden(reset = false) {
    if (loading.value && !reset) return
    loading.value = true

    if (reset) {
      hiddenPage.value = 0
      hiddenFaces.value = []
      hasMoreHidden.value = true
    }

    try {
      const result = await api.get('/media/faces/hidden', {
        params: {
          limit: PAGE_SIZE_HIDDEN,
          offset: hiddenPage.value * PAGE_SIZE_HIDDEN,
        }
      })

      const items = result.data || []
      if (reset) {
        hiddenFaces.value = items
      } else {
        hiddenFaces.value = [...hiddenFaces.value, ...items]
      }
      hasMoreHidden.value = items.length >= PAGE_SIZE_HIDDEN
      hiddenPage.value++
    } catch (e) {
      console.error('Failed to load hidden faces', e)
    } finally {
      loading.value = false
    }
  }

  async function loadMoreHidden() {
    if (!hasMoreHidden.value || loading.value) return
    await loadHidden(false)
  }

  async function loadNamedOnly(reset = false) {
    if (namedOnlyLoading.value && !reset) return
    namedOnlyLoading.value = true

    if (reset) {
      namedOnlyPage.value = 0
      namedOnlyFaces.value = []
      hasMoreNamedOnly.value = true
    }

    try {
      const result = await api.get('/media/faces/named-only', {
        params: {
          limit: PAGE_SIZE_NAMED_ONLY,
          offset: namedOnlyPage.value * PAGE_SIZE_NAMED_ONLY,
          decision_state: 'open',
        }
      })

      const items = result.data || []
      namedOnlyFaces.value = reset ? items : [...namedOnlyFaces.value, ...items]
      namedOnlyTotal.value = Number(result.total || 0)
      hasMoreNamedOnly.value = namedOnlyFaces.value.length < namedOnlyTotal.value
      namedOnlyPage.value++
    } catch (e) {
      console.error('Failed to load named-only faces', e)
    } finally {
      namedOnlyLoading.value = false
    }
  }

  async function loadMoreNamedOnly() {
    if (!hasMoreNamedOnly.value || namedOnlyLoading.value) return
    await loadNamedOnly(false)
  }

  async function linkNamedOnlyFace(faceId, personId, treeId = null) {
    try {
      const payload = { face_id: faceId, person_id: personId }
      if (treeId) payload.tree_id = treeId

      const result = await api.post('/media/faces/link', payload)
      if (result.success) {
        namedOnlyFaces.value = namedOnlyFaces.value.filter(f => f.face_id !== faceId)
        namedOnlyTotal.value = Math.max(0, namedOnlyTotal.value - 1)
        hasMoreNamedOnly.value = namedOnlyFaces.value.length < namedOnlyTotal.value
      }

      return result
    } catch (e) {
      console.error('Failed to link named-only face', e)
      throw e
    }
  }

  async function decideNamedOnlyFace(faceId, decision) {
    try {
      const result = await api.post(`/media/faces/${faceId}/candidate-decision`, decision)
      if (result.success) {
        if (result.decision?.terminal) {
          namedOnlyFaces.value = namedOnlyFaces.value.filter(f => f.face_id !== faceId)
          namedOnlyTotal.value = Math.max(0, namedOnlyTotal.value - 1)
          hasMoreNamedOnly.value = namedOnlyFaces.value.length < namedOnlyTotal.value
        } else {
          namedOnlyFaces.value = namedOnlyFaces.value.map(face => {
            if (face.face_id !== faceId) return face

            return {
              ...face,
              candidate_decision_status: result.queue?.status || face.candidate_decision_status,
              candidate_decision_action: result.decision?.action || face.candidate_decision_action,
              candidate_decision_terminal: result.decision?.terminal ? 'true' : 'false',
              candidate_decision_at: result.decision?.decided_at || face.candidate_decision_at,
            }
          })
        }
      }

      return result
    } catch (e) {
      console.error('Failed to record named-only face decision', e)
      throw e
    }
  }

  async function unhideFace(faceId) {
    try {
      await api.post('/media/faces/bulk-hide', { face_ids: [faceId], hidden: false })
      hiddenFaces.value = hiddenFaces.value.filter(f => f.face_id !== faceId)
      stats.value.hidden_count--
      return true
    } catch (e) {
      console.error('Failed to unhide face', e)
      return false
    }
  }

  // --- Naming ---

  async function nameFace(faceId, name, genealogyPersonId = null) {
    try {
      const payload = { person_name: name }
      if (genealogyPersonId) payload.genealogy_person_id = genealogyPersonId
      await api.post(`/media/faces/${faceId}/name`, payload)

      // Push undo entry
      const face = newFaces.value.find(f => f.face_id === faceId)
      if (face) {
        undoStack.value.push({ type: 'name', faceId, face: { ...face }, previousName: face.person_name || '' })
      }

      // Optimistic: remove from new, update stats
      newFaces.value = newFaces.value.filter(f => f.face_id !== faceId)
      stats.value.named_count++
      stats.value.unnamed_count--
      sessionStats.value.named++

      return true
    } catch (e) {
      console.error('Failed to name face', e)
      return false
    }
  }

  async function bulkName(faceIds, name, genealogyPersonId = null) {
    try {
      const payload = { face_ids: faceIds, person_name: name }
      if (genealogyPersonId) payload.genealogy_person_id = genealogyPersonId
      await api.post('/media/faces/bulk-name', payload)

      // Push undo entry
      const faces = newFaces.value.filter(f => faceIds.includes(f.face_id))
      undoStack.value.push({ type: 'bulk-name', faceIds: [...faceIds], faces: faces.map(f => ({ ...f })), name })

      // Optimistic update
      newFaces.value = newFaces.value.filter(f => !faceIds.includes(f.face_id))
      stats.value.named_count += faceIds.length
      stats.value.unnamed_count -= faceIds.length
      sessionStats.value.named += faceIds.length
      selectedIds.value.clear()

      return true
    } catch (e) {
      console.error('Failed to bulk name faces', e)
      return false
    }
  }

  // --- Hiding ---

  async function bulkHide(faceIds, hidden = true) {
    try {
      await api.post('/media/faces/bulk-hide', { face_ids: faceIds, hidden })

      // Push undo entry
      const faces = newFaces.value.filter(f => faceIds.includes(f.face_id))
      undoStack.value.push({ type: 'hide', faceIds: [...faceIds], faces: faces.map(f => ({ ...f })), hidden })

      // Optimistic update
      if (hidden) {
        newFaces.value = newFaces.value.filter(f => !faceIds.includes(f.face_id))
        stats.value.unnamed_count -= faceIds.length
        stats.value.hidden_count += faceIds.length
        sessionStats.value.hidden += faceIds.length
      }
      selectedIds.value.clear()

      return true
    } catch (e) {
      console.error('Failed to hide faces', e)
      return false
    }
  }

  async function toggleHide(faceId) {
    return bulkHide([faceId], true)
  }

  // --- Person management ---

  async function renamePerson(oldName, newName) {
    try {
      const result = await api.post('/media/faces/rename-person', { old_name: oldName, new_name: newName })
      // Refresh recognized list to reflect changes
      await loadRecognized(true)
      return result
    } catch (e) {
      console.error('Failed to rename person', e)
      return null
    }
  }

  async function toggleFavorite(personName, currentFavorite) {
    // Toggle favorite on all faces for this person
    // Uses bulk-hide endpoint pattern but for favorite toggle
    // For now, we'll handle this through rename-person or direct update
    // This would need a dedicated endpoint; skip for MVP
  }

  // --- Autocomplete ---

  async function searchPersons(query) {
    if (!query || query.length < 2) return []
    try {
      const result = await api.get('/media/genealogy-persons', { params: { search: query, limit: 20 } })
      return result.data || []
    } catch (e) {
      console.error('Failed to search persons', e)
      return []
    }
  }

  // --- Undo ---

  async function undoLast() {
    if (undoStack.value.length === 0) return false

    const entry = undoStack.value.pop()
    sessionStats.value.undone++

    try {
      if (entry.type === 'name') {
        // Undo single name — clear name via bulk-name (allows empty)
        await api.post('/media/faces/bulk-name', { face_ids: [entry.faceId], person_name: entry.previousName || '' })
        newFaces.value.unshift(entry.face)
        stats.value.named_count--
        stats.value.unnamed_count++
        sessionStats.value.named--
      } else if (entry.type === 'bulk-name') {
        // Undo bulk name
        for (const face of entry.faces) {
          newFaces.value.unshift(face)
        }
        stats.value.named_count -= entry.faceIds.length
        stats.value.unnamed_count += entry.faceIds.length
        sessionStats.value.named -= entry.faceIds.length
        // Clear names on server
        await api.post('/media/faces/bulk-name', { face_ids: entry.faceIds, person_name: '' })
      } else if (entry.type === 'hide') {
        // Undo hide
        await api.post('/media/faces/bulk-hide', { face_ids: entry.faceIds, hidden: false })
        for (const face of entry.faces) {
          newFaces.value.unshift(face)
        }
        stats.value.unnamed_count += entry.faceIds.length
        stats.value.hidden_count -= entry.faceIds.length
        sessionStats.value.hidden -= entry.faceIds.length
      }

      return true
    } catch (e) {
      console.error('Undo failed', e)
      return false
    }
  }

  // --- Unidentified faces (N63) ---

  async function loadUnidentified(page = 1) {
    unidentifiedLoading.value = true
    try {
      const result = await api.get('/media/faces/unidentified', {
        params: { page, per_page: PAGE_SIZE_UNIDENTIFIED }
      })
      unidentifiedFaces.value = result.faces || []
      unidentifiedTotal.value = result.total || 0
      unidentifiedPages.value = result.pages || 0
      unidentifiedPage.value = page
      stats.value.unidentified_count = result.total || 0
    } catch (e) {
      console.error('Failed to load unidentified faces', e)
    } finally {
      unidentifiedLoading.value = false
    }
  }

  async function dismissUnidentified(id) {
    try {
      await api.patch(`/media/face-match/${id}/status`, { status: 'dismissed' })
      unidentifiedFaces.value = unidentifiedFaces.value.filter(f => f.id !== id)
      unidentifiedTotal.value = Math.max(0, unidentifiedTotal.value - 1)
      stats.value.unidentified_count = Math.max(0, stats.value.unidentified_count - 1)
      return true
    } catch (e) {
      console.error('Failed to dismiss unidentified face', e)
      return false
    }
  }

  async function bulkDismissUnidentified(ids) {
    let dismissed = 0
    for (const id of ids) {
      const ok = await dismissUnidentified(id)
      if (ok) dismissed++
    }
    return dismissed
  }

  async function linkUnidentified(id, personId) {
    try {
      await api.post(`/genealogy/face-match-queue/${id}/link`, { person_id: personId })
      unidentifiedFaces.value = unidentifiedFaces.value.filter(f => f.id !== id)
      unidentifiedTotal.value = Math.max(0, unidentifiedTotal.value - 1)
      stats.value.unidentified_count = Math.max(0, stats.value.unidentified_count - 1)
      return true
    } catch (e) {
      console.error('Failed to link unidentified face to person', e)
      return false
    }
  }

  // --- Search ---

  function debouncedSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      if (activeTab.value === 'recognized' || activeTab.value === 'hidden') {
        loadRecognized(true)
      }
    }, 300)
  }

  // --- Selection ---

  function toggleSelect(faceId) {
    if (selectedIds.value.has(faceId)) {
      selectedIds.value.delete(faceId)
    } else {
      selectedIds.value.add(faceId)
    }
    // Force reactivity
    selectedIds.value = new Set(selectedIds.value)
  }

  function selectRange(faceId, allFaceIds) {
    const currentSelected = [...selectedIds.value]
    if (currentSelected.length === 0) {
      selectedIds.value.add(faceId)
    } else {
      const lastSelected = currentSelected[currentSelected.length - 1]
      const startIdx = allFaceIds.indexOf(lastSelected)
      const endIdx = allFaceIds.indexOf(faceId)
      if (startIdx >= 0 && endIdx >= 0) {
        const [from, to] = startIdx < endIdx ? [startIdx, endIdx] : [endIdx, startIdx]
        for (let i = from; i <= to; i++) {
          selectedIds.value.add(allFaceIds[i])
        }
      }
    }
    selectedIds.value = new Set(selectedIds.value)
  }

  function clearSelection() {
    selectedIds.value = new Set()
  }

  // --- Keyboard ---

  function handleKeydown(e) {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
      if (e.key === 'Escape') {
        e.target.blur()
        clearSelection()
      }
      return
    }

    switch (e.key) {
      case 'z':
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault()
          undoLast()
        }
        break
      case 'Escape':
        clearSelection()
        break
    }
  }

  // --- Refresh ---

  async function refreshAll() {
    await Promise.all([
      loadRecognized(true),
      loadNewFaces(true),
    ])
  }

  // --- Computed ---

  const selectedCount = computed(() => selectedIds.value.size)
  const hasUndo = computed(() => undoStack.value.length > 0)
  const progressPercent = computed(() => {
    if (stats.value.total === 0) return 0
    return Math.round((stats.value.named_count / stats.value.total) * 100)
  })

  return {
    // State
    stats,
    recognized,
    newFaces,
    loading,
    searchQuery,
    showHidden,
    selectedIds,
    undoStack,
    sessionStats,
    activeTab,
    hasMoreRecognized,
    hasMoreNew,
    hasMoreHidden,
    hasMoreNamedOnly,
    hiddenFaces,
    namedOnlyFaces,
    namedOnlyTotal,
    namedOnlyLoading,
    unidentifiedFaces,
    unidentifiedPage,
    unidentifiedTotal,
    unidentifiedLoading,
    unidentifiedPages,

    // Computed
    selectedCount,
    hasUndo,
    progressPercent,

    // Methods
    loadRecognized,
    loadNewFaces,
    loadMoreRecognized,
    loadMoreNew,
    loadHidden,
    loadMoreHidden,
    loadNamedOnly,
    loadMoreNamedOnly,
    linkNamedOnlyFace,
    decideNamedOnlyFace,
    unhideFace,
    nameFace,
    bulkName,
    bulkHide,
    toggleHide,
    renamePerson,
    searchPersons,
    undoLast,
    debouncedSearch,
    toggleSelect,
    selectRange,
    clearSelection,
    handleKeydown,
    refreshAll,
    loadUnidentified,
    dismissUnidentified,
    bulkDismissUnidentified,
    linkUnidentified,
  }
}

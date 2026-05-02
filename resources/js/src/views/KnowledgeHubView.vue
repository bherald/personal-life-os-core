<template>
  <OpsPageWrapper title="KNOWLEDGE HUB" subtitle="Unified Search & Browse" sectionCode="KB" colorScheme="gold" :showSidebar="false">
    <template #header-actions>
      <button
        v-if="tabUsesSidebar"
        @click="showFacets = !showFacets"
        class="ops-nav-btn px-3 py-1.5 text-xs font-semibold uppercase rounded-r-full transition-colors"
        :class="showFacets ? 'bg-ops-lilac text-black' : 'bg-ops-plum/30 text-ops-text-muted hover:bg-ops-plum/50'"
      >
        {{ showFacets ? 'Hide Sidebar' : 'Show Sidebar' }}
      </button>
      <button @click="refresh" class="bg-ops-orange text-black px-4 py-1.5 rounded-r-full hover:bg-ops-peach font-semibold uppercase text-xs">
        Refresh
      </button>
    </template>

    <div class="flex flex-col h-[calc(100vh-8rem)]">
      <!-- Source Tabs (always visible) -->
      <div class="px-4 pt-4 pb-3">
        <KnowledgeSourceTabs
          v-model="sourceTab"
          :tabs="sourceTabs"
          :activeFilters="sourceTab !== 'ai' && sourceTab !== 'review' && sourceTab !== 'agents' && sourceTab !== 'faces' && sourceTab !== 'graph' ? activeFilterChips : []"
          @remove-filter="removeFilter"
        />
      </div>

      <!-- AI Mode: Full AI Hub -->
      <div v-if="sourceTab === 'ai'" class="flex-1 overflow-hidden">
        <AIHubView />
      </div>

      <!-- Review Mode: Research Hub content -->
      <div v-else-if="sourceTab === 'review'" class="flex-1 overflow-y-auto">
        <ResearchHubContent :embedded="true" />
      </div>

      <!-- Faces Mode -->
      <div v-else-if="sourceTab === 'faces'" class="flex-1 overflow-y-auto">
        <FacesView :embedded="true" />
      </div>

      <!-- Agents Mode: Agent Reports Dashboard -->
      <div v-else-if="sourceTab === 'agents'" class="flex-1 overflow-y-auto">
        <AgentReportsView :embedded="true" />
      </div>

      <!-- Graph Mode: Knowledge Graph Explorer -->
      <div v-else-if="sourceTab === 'graph'" class="flex-1 overflow-hidden">
        <KnowledgeGraphExplorer />
      </div>

      <!-- Search/Browse Mode -->
      <template v-else>
        <!-- Search Bar -->
        <div class="px-4 pb-2">
          <KnowledgeSearchBar
            ref="searchBarRef"
            v-model="query"
            @search="performSearch"
            @clear="clearSearch"
          />
        </div>

        <!-- Search result summary -->
        <div v-if="hasSearched && !searching" class="px-4 pb-1 flex items-center gap-3 text-xs text-ops-text-muted">
          <span>{{ results.length }}{{ hasMore ? '+' : '' }} results</span>
          <span v-if="searchTimeMs">in {{ searchTimeMs }}ms</span>
          <span v-if="query && query !== '*'" class="text-ops-lilac">for "{{ query }}"</span>
        </div>

        <!-- Main content area (3-column layout) -->
        <div class="flex flex-1 overflow-hidden">
          <!-- Left panel: Facets with integrated folder tree -->
          <KnowledgeFacetPanel
            v-if="showFacets && tabUsesSidebar"
            :facets="facets"
            :activeFilters="filters"
            :showFolderTree="true"
            :showNotebookTree="true"
            @filter="onFacetFilter"
          >
            <template #folder-tree>
              <KnowledgeFolderBrowser
                ref="folderBrowserRef"
                :selectedFolder="selectedFolder"
                @select="onFolderSelect"
              />
            </template>
            <template #notebook-tree>
              <KnowledgeNotebookBrowser
                :selectedNotebook="selectedNotebook"
                @select="onNotebookSelect"
              />
            </template>
          </KnowledgeFacetPanel>

          <!-- Center: Content Grid, Browse, or Landing State -->
          <div class="flex-1 overflow-y-auto px-4 pb-4">
            <!-- Browse mode: breadcrumb + toolbar + folder/file grid -->
            <template v-if="isBrowsing">
              <div class="flex items-center gap-2 mb-2">
                <KnowledgeBreadcrumb
                  class="flex-1"
                  :breadcrumb="browseData.breadcrumb"
                  :totalFiles="browseData.total_files"
                  @navigate="onBreadcrumbNav"
                />
                <div class="flex items-center gap-1 flex-shrink-0">
                  <!-- View mode toggle -->
                  <button @click="viewMode = 'grid'" class="p-1.5 rounded transition-colors" :class="viewMode === 'grid' ? 'bg-ops-gold/20 text-ops-gold' : 'text-ops-text-muted hover:text-ops-peach'" title="Grid view">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                  </button>
                  <button @click="viewMode = 'gallery'" class="p-1.5 rounded transition-colors" :class="viewMode === 'gallery' ? 'bg-ops-gold/20 text-ops-gold' : 'text-ops-text-muted hover:text-ops-peach'" title="Gallery view">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                  </button>
                  <!-- Slideshow -->
                  <button @click="startSlideshow" class="p-1.5 rounded text-ops-text-muted hover:text-ops-sky transition-colors" title="Slideshow">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  </button>
                </div>
              </div>
              <KnowledgeContentGrid
                :items="browseData.files"
                :folders="browseData.subfolders"
                :loading="browseLoading"
                :viewMode="viewMode"
                :emptyTitle="'Empty folder'"
                :emptySubtext="'No files in this directory'"
                @select="openPreview"
                @open-folder="onGridOpenFolder"
                @delete="onDeleteGridItem"
              />
            </template>

            <!-- Search results mode -->
            <KnowledgeContentGrid
              v-else-if="hasSearched"
              :items="results"
              :loading="searching"
              :loadingMore="loadingMore"
              :hasMore="hasMore"
              :selectedId="previewItem?.id || lightboxItem?.id"
              :emptyTitle="emptyTitle"
              :emptySubtext="emptySubtext"
              @select="openPreview"
              @load-more="loadMore"
              @delete="onDeleteGridItem"
            />

            <!-- Landing state -->
            <KnowledgeLandingState
              v-else
              :data="landingData"
              :loading="loadingLanding"
              @select="openPreview"
              @quick-search="onQuickSearch"
              @open-faces="goToFaces"
            />
          </div>

          <!-- Right panel: Preview (non-media only) -->
          <KnowledgePreviewPanel
            v-if="previewItem && !isMediaItem(previewItem)"
            :item="previewItem"
            @close="previewItem = null"
          />
        </div>
      </template>

      <!-- Media Lightbox (photos/videos/audio) -->
      <MediaLightbox
        v-if="lightboxItem"
        :item="lightboxItem"
        @close="lightboxItem = null; stopSlideshow()"
        @next="slideshowActive ? slideshowAdvance(1) : lightboxNav(1)"
        @prev="slideshowActive ? slideshowAdvance(-1) : lightboxNav(-1)"
        @refresh="performSearch()"
        @deleted="onItemDeleted"
      />
    </div>
  </OpsPageWrapper>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import axios from 'axios'
import OpsPageWrapper from '../components/layout/OpsPageWrapper.vue'
import KnowledgeSearchBar from '../components/knowledge/KnowledgeSearchBar.vue'
import KnowledgeSourceTabs from '../components/knowledge/KnowledgeSourceTabs.vue'
import KnowledgeFacetPanel from '../components/knowledge/KnowledgeFacetPanel.vue'
import KnowledgeFolderBrowser from '../components/knowledge/KnowledgeFolderBrowser.vue'
import KnowledgeNotebookBrowser from '../components/knowledge/KnowledgeNotebookBrowser.vue'
import KnowledgeBreadcrumb from '../components/knowledge/KnowledgeBreadcrumb.vue'
import KnowledgeContentGrid from '../components/knowledge/KnowledgeContentGrid.vue'
import KnowledgePreviewPanel from '../components/knowledge/KnowledgePreviewPanel.vue'
import KnowledgeLandingState from '../components/knowledge/KnowledgeLandingState.vue'
import MediaLightbox from '../components/media/MediaLightbox.vue'
import { defineAsyncComponent } from 'vue'

const AIHubView = defineAsyncComponent(() => import('./AIHubView.vue'))
const ResearchHubContent = defineAsyncComponent(() => import('./ResearchHubView.vue'))
const AgentReportsView = defineAsyncComponent(() => import('./AgentReportsView.vue'))
const FacesView = defineAsyncComponent(() => import('./FacesView.vue'))
const KnowledgeGraphExplorer = defineAsyncComponent(() => import('../components/knowledge/KnowledgeGraphExplorer.vue'))

const router = useRouter()
const route = useRoute()

// State
const query = ref('')
const sourceTab = ref('all')
const results = ref([])
const facets = ref({ types: {}, years: {}, people: {} })
const filters = ref({})
const searching = ref(false)
const loadingMore = ref(false)
const loadingLanding = ref(false)
const hasSearched = ref(false)
const showFacets = ref(true)
const sidebarTabs = ['all', 'files', 'media']
const tabUsesSidebar = computed(() => sidebarTabs.includes(sourceTab.value))
const selectedFolder = ref(null)
const selectedNotebook = ref(null)
const previewItem = ref(null)
const lightboxItem = ref(null)
const searchBarRef = ref(null)
const landingData = ref({ recent_files: [], recent_notes: [], face_queue_count: 0, stats: {} })
const searchTimeMs = ref(null)

// Browse mode state
const browseFolder = ref(null) // current browse path string from the media folder API
const browseData = ref({ breadcrumb: [], subfolders: [], files: [], total_files: 0 })
const browseLoading = ref(false)
const browseSort = ref('name_asc')
const folderBrowserRef = ref(null)
const viewMode = ref('grid') // 'grid' or 'gallery'
const slideshowActive = ref(false)
let slideshowTimer = null

const page = ref(1)
const totalResults = ref(0)
const pageSize = 30

let searchAbortController = null

// Computed
const hasMore = computed(() => results.value.length < totalResults.value)
const isBrowsing = computed(() => browseFolder.value !== null)

const emptyTitle = computed(() => query.value && query.value !== '*' ? 'No results found' : 'No content')
const emptySubtext = computed(() => query.value && query.value !== '*' ? `No matches for "${query.value}"` : 'No items found in this category')

const pendingReviewCount = ref(null)

const sourceTabs = computed(() => [
  { label: 'All', value: 'all' },
  { label: 'Files', value: 'files' },
  { label: 'Media', value: 'media' },
  { label: 'Faces', value: 'faces' },
  { label: 'Review', value: 'review', count: pendingReviewCount.value || undefined },
  { label: 'Graph', value: 'graph' },
  { label: 'Agents', value: 'agents' },
  { label: 'AI', value: 'ai' },
])

const activeFilterChips = computed(() => {
  const chips = []
  if (filters.value.type) chips.push({ key: 'type', label: `Type: ${filters.value.type}` })
  if (filters.value.year) chips.push({ key: 'year', label: `Year: ${filters.value.year}` })
  if (filters.value.person) chips.push({ key: 'person', label: `Person: ${filters.value.person}` })
  if (selectedFolder.value) chips.push({ key: 'folder', label: `Folder: ${selectedFolder.value.name}` })
  if (selectedNotebook.value) chips.push({ key: 'notebook', label: `Notebook: ${selectedNotebook.value.title}` })
  return chips
})

// Methods
async function performSearch(searchQuery) {
  if (!searchQuery && !query.value) return

  // Exit browse mode when searching
  browseFolder.value = null
  browseData.value = { breadcrumb: [], subfolders: [], files: [], total_files: 0 }

  const q = searchQuery || query.value
  query.value = q
  hasSearched.value = true
  page.value = 1
  results.value = []

  // Abort previous search
  if (searchAbortController) searchAbortController.abort()
  searchAbortController = new AbortController()

  searching.value = true
  searchTimeMs.value = null
  const searchStart = performance.now()

  try {
    // Map facet type (singular) to backend search type
    const facetTypeMap = {
      photo: 'photos',
      video: 'videos',
      audio: 'media',
      document: 'files',
      spreadsheet: 'files',
      presentation: 'files',
      code: 'files',
      archive: 'files',
      ebook: 'files',
      other: 'files',
      rag_note: 'notes',
      rag_transcript: 'transcripts',
      rag_document: 'files',
    }

    // Facet type overrides tab type when active
    const effectiveType = filters.value.type
      ? (facetTypeMap[filters.value.type] || sourceTab.value)
      : (sourceTab.value !== 'all' ? sourceTab.value : undefined)

    // Pass media_subtype for facet type narrowing within file_registry
    const mediaSubtype = filters.value.type && ['photo', 'video', 'audio', 'document', 'spreadsheet', 'presentation', 'code', 'archive', 'ebook'].includes(filters.value.type)
      ? filters.value.type
      : undefined

    const params = {
      q,
      type: effectiveType,
      media_subtype: mediaSubtype,
      limit: pageSize,
      folder: selectedFolder.value?.path || undefined,
      notebook: selectedNotebook.value?.id || undefined,
      date_from: filters.value.year ? `${filters.value.year}-01-01` : undefined,
      date_to: filters.value.year ? `${filters.value.year}-12-31` : undefined,
      person_name: filters.value.person || undefined,
    }

    const [searchRes, facetRes] = await Promise.all([
      axios.get('/api/search', { params, signal: searchAbortController.signal }),
      axios.get('/api/search/facets', { params: { q }, signal: searchAbortController.signal }),
    ])

    if (searchRes.data.success !== false) {
      results.value = searchRes.data.results || []
      // If we got a full page, assume there are more results to load
      totalResults.value = results.value.length >= pageSize
        ? results.value.length + 1  // Signals hasMore=true
        : results.value.length      // No more results
    }

    if (facetRes.data.facets) {
      facets.value = facetRes.data.facets
    }

    searchTimeMs.value = Math.round(performance.now() - searchStart)
    updateUrl()
  } catch (err) {
    if (err.name !== 'CanceledError') {
      console.error('Search failed:', err)
    }
  } finally {
    searching.value = false
  }
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  loadingMore.value = true
  page.value++

  try {
    // Same facet type mapping as performSearch
    const facetTypeMap = {
      photo: 'photos',
      video: 'videos',
      audio: 'media',
      document: 'files',
      spreadsheet: 'files',
      presentation: 'files',
      code: 'files',
      archive: 'files',
      ebook: 'files',
      other: 'files',
      rag_note: 'notes',
      rag_transcript: 'transcripts',
      rag_document: 'files',
    }

    const effectiveType = filters.value.type
      ? (facetTypeMap[filters.value.type] || sourceTab.value)
      : (sourceTab.value !== 'all' ? sourceTab.value : undefined)

    const mediaSubtype = filters.value.type && ['photo', 'video', 'audio', 'document', 'spreadsheet', 'presentation', 'code', 'archive', 'ebook'].includes(filters.value.type)
      ? filters.value.type
      : undefined

    const params = {
      q: query.value,
      type: effectiveType,
      media_subtype: mediaSubtype,
      limit: pageSize,
      offset: results.value.length,
      folder: selectedFolder.value?.path || undefined,
      notebook: selectedNotebook.value?.id || undefined,
      date_from: filters.value.year ? `${filters.value.year}-01-01` : undefined,
      date_to: filters.value.year ? `${filters.value.year}-12-31` : undefined,
      person_name: filters.value.person || undefined,
    }

    const { data } = await axios.get('/api/search', { params })
    if (data.results?.length > 0) {
      results.value.push(...data.results)
    }
    // Update totalResults: if full page returned, signal more exist
    if (data.results?.length >= pageSize) {
      totalResults.value = results.value.length + 1
    } else {
      totalResults.value = results.value.length
    }
  } catch (err) {
    console.error('Load more failed:', err)
  } finally {
    loadingMore.value = false
  }
}

function clearSearch() {
  hasSearched.value = false
  results.value = []
  facets.value = { types: {}, years: {}, people: {} }
  filters.value = {}
  selectedFolder.value = null
  selectedNotebook.value = null
  browseFolder.value = null
  browseData.value = { breadcrumb: [], subfolders: [], files: [], total_files: 0 }
  updateUrl()
  loadLanding()
}

function isMediaItem(item) {
  const type = (item?.type || '').toLowerCase()
  return ['photo', 'image', 'video', 'audio'].includes(type)
}

function openPreview(item) {
  if (isMediaItem(item) && item.asset_uuid) {
    // Derive extension from filename if not set
    const ext = item.extension || (item.filename || '').split('.').pop() || ''
    // Map search result to MediaLightbox's expected shape
    lightboxItem.value = {
      ...item,
      current_path: item.path || item.current_path || '',
      extension: ext.toLowerCase(),
    }
    previewItem.value = null
  } else {
    previewItem.value = item
    lightboxItem.value = null
  }
}

function lightboxNav(direction) {
  if (!lightboxItem.value || results.value.length === 0) return
  const mediaItems = results.value.filter(r => isMediaItem(r) && r.asset_uuid)
  const idx = mediaItems.findIndex(r => r.id === lightboxItem.value.id)
  if (idx < 0) return
  const next = idx + direction
  if (next >= 0 && next < mediaItems.length) {
    lightboxItem.value = { ...mediaItems[next], current_path: mediaItems[next].path || '', extension: mediaItems[next].extension || '' }
  }
}

function onFacetFilter({ key, value }) {
  if (key === 'notebook') {
    selectedNotebook.value = value ? { id: value } : null
    if (hasSearched.value) performSearch()
    return
  }
  if (value === null) {
    delete filters.value[key]
    filters.value = { ...filters.value }
  } else {
    filters.value = { ...filters.value, [key]: value }
  }
  if (hasSearched.value) performSearch()
}

function removeFilter(key) {
  if (key === 'folder') {
    selectedFolder.value = null
  } else if (key === 'notebook') {
    selectedNotebook.value = null
  } else {
    delete filters.value[key]
    filters.value = { ...filters.value }
  }
  if (hasSearched.value) performSearch()
}

function onFolderSelect(folder) {
  selectedFolder.value = folder
  // Enter browse mode — show folder contents directly
  browseTo(folder.path)
}

function onNotebookSelect(notebook) {
  selectedNotebook.value = notebook
  // Auto-switch to notes tab when selecting a notebook
  if (sourceTab.value === 'all' || sourceTab.value === 'files' || sourceTab.value === 'media') {
    sourceTab.value = 'notes'
  }
  performSearch(hasSearched.value ? undefined : '*')
}

// Browse mode
async function browseTo(path) {
  browseFolder.value = path
  browseLoading.value = true
  hasSearched.value = false
  results.value = []

  try {
    const { data } = await axios.get('/api/media/browse', {
      params: { path, sort: browseSort.value, limit: 50, offset: 0 }
    })
    if (data.success) {
      browseData.value = {
        breadcrumb: data.breadcrumb || [],
        subfolders: data.subfolders || [],
        files: data.files || [],
        total_files: data.total_files || 0,
      }
    }
  } catch (err) {
    console.error('Browse failed:', err)
  } finally {
    browseLoading.value = false
  }

  // Sync sidebar tree selection
  selectedFolder.value = path ? { path, name: path.split('/').pop() } : null
}

function onBreadcrumbNav(path) {
  if (!path) {
    // Home clicked — exit browse mode
    browseFolder.value = null
    browseData.value = { breadcrumb: [], subfolders: [], files: [], total_files: 0 }
    selectedFolder.value = null
    loadLanding()
    return
  }
  browseTo(path)
}

function onGridOpenFolder(folder) {
  browseTo(folder.path)
}

function onQuickSearch(chipQuery) {
  // Handle type: prefix
  if (chipQuery.startsWith('type:')) {
    const type = chipQuery.replace('type:', '')
    sourceTab.value = type
    query.value = ''
    performSearch('*')
    return
  }
  query.value = chipQuery
  performSearch(chipQuery)
}

function onItemDeleted(itemId) {
  // Mark item as deleted locally - shows X overlay, no page reload
  const item = results.value.find(r => r.id === itemId)
  if (item) item._deleted = true
  // Also check browse mode files
  const browseItem = browseData.value.files.find(r => r.id === itemId)
  if (browseItem) browseItem._deleted = true
}

async function onDeleteGridItem(item) {
  const uuid = item.asset_uuid
  if (!uuid) return
  try {
    await axios.delete(`/api/media/${uuid}`)
    // Mark deleted in both search results and browse files
    const searchItem = results.value.find(r => r.id === item.id)
    if (searchItem) searchItem._deleted = true
    const browseItem = browseData.value.files.find(r => r.id === item.id)
    if (browseItem) browseItem._deleted = true
  } catch (err) {
    alert('Delete failed: ' + (err.response?.data?.error || err.message))
  }
}

function startSlideshow() {
  // Collect visual items from current context
  const source = isBrowsing.value ? browseData.value.files : results.value
  const visuals = source.filter(r => {
    const type = (r.type || '').toLowerCase()
    return ['photo', 'image'].includes(type) && r.asset_uuid
  })
  if (visuals.length === 0) return
  slideshowActive.value = true
  lightboxItem.value = { ...visuals[0], current_path: visuals[0].path || '', extension: visuals[0].extension || '' }
  previewItem.value = null
  slideshowTimer = setInterval(() => {
    slideshowAdvance(1)
  }, 5000)
}

function slideshowAdvance(direction) {
  if (!lightboxItem.value) { stopSlideshow(); return }
  const source = isBrowsing.value ? browseData.value.files : results.value
  const visuals = source.filter(r => ['photo', 'image'].includes((r.type || '').toLowerCase()) && r.asset_uuid)
  const idx = visuals.findIndex(r => r.id === lightboxItem.value.id || r.asset_uuid === lightboxItem.value.asset_uuid)
  const next = idx + direction
  if (next >= 0 && next < visuals.length) {
    lightboxItem.value = { ...visuals[next], current_path: visuals[next].path || '', extension: visuals[next].extension || '' }
  } else {
    // Wrap around
    const wrap = direction > 0 ? 0 : visuals.length - 1
    lightboxItem.value = { ...visuals[wrap], current_path: visuals[wrap].path || '', extension: visuals[wrap].extension || '' }
  }
}

function stopSlideshow() {
  slideshowActive.value = false
  if (slideshowTimer) { clearInterval(slideshowTimer); slideshowTimer = null }
}

function goToFaces() {
  sourceTab.value = 'faces'
}

function refresh() {
  if (isBrowsing.value) {
    browseTo(browseFolder.value)
  } else if (hasSearched.value) {
    performSearch()
  } else {
    loadLanding()
  }
}

async function loadReviewCount() {
  try {
    const { data } = await axios.get('/api/research-hub/stats')
    pendingReviewCount.value = data.reviews?._total || 0
  } catch (err) {
    // Non-critical, badge just won't show
  }
}

async function loadLanding() {
  loadingLanding.value = true
  try {
    const { data } = await axios.get('/api/search/landing')
    if (data.success && data.data) {
      landingData.value = data.data
    }
  } catch (err) {
    console.error('Failed to load landing data:', err)
  } finally {
    loadingLanding.value = false
  }
}

function updateUrl() {
  const params = {}
  if (query.value) params.q = query.value
  if (sourceTab.value !== 'all') params.source = sourceTab.value
  if (filters.value.year) params.year = filters.value.year
  if (filters.value.type) params.type = filters.value.type

  router.replace({ path: '/knowledge', query: params })
}

function restoreFromUrl() {
  const q = route.query
  if (q.q) { query.value = q.q; hasSearched.value = true }
  if (q.source) sourceTab.value = q.source
  if (q.year) filters.value.year = q.year
  if (q.type) filters.value.type = q.type
}

// Watchers
watch(sourceTab, (newTab) => {
  if (newTab === 'ai' || newTab === 'review' || newTab === 'agents' || newTab === 'faces') return // Embedded views handled separately
  if (isBrowsing.value) return // Don't disrupt browse mode
  // Always browse/search when switching tabs
  performSearch(hasSearched.value ? undefined : '*')
})

// Lifecycle
onMounted(() => {
  restoreFromUrl()
  loadReviewCount()
  if (sourceTab.value !== 'ai' && sourceTab.value !== 'review' && sourceTab.value !== 'faces' && sourceTab.value !== 'agents' && sourceTab.value !== 'graph') {
    // Always show content - browse all if no query
    performSearch(hasSearched.value ? undefined : '*')
  }
  // Handle focus=search from global Ctrl+K
  if (route.query.focus === 'search') {
    if (sourceTab.value === 'ai') sourceTab.value = 'all'
    nextTick(() => {
      searchBarRef.value?.focus?.()
      router.replace({ path: '/knowledge', query: { ...route.query, focus: undefined } })
    })
  }
})
</script>

<style scoped>
/* Mobile: hide side panels, simplify layout */
@media (max-width: 768px) {
  .knowledge-facet-panel,
  .knowledge-folder-browser {
    display: none;
  }
}
</style>

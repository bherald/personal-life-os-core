<template>
  <div class="knowledge-content-grid">
    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="ops-loading"><span class="ops-loading-dot"></span><span class="ops-loading-dot"></span><span class="ops-loading-dot"></span></div>
    </div>

    <!-- Results grid -->
    <div v-else-if="items.length > 0 || folders.length > 0"
         :class="viewMode === 'gallery'
           ? 'columns-2 md:columns-3 lg:columns-4 gap-4 space-y-4'
           : 'grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4'"
    >
      <!-- Folder cards -->
      <div
        v-for="folder in folders"
        :key="'folder-' + folder.path"
        @click="$emit('open-folder', folder)"
        class="group bg-black border-2 border-ops-plum hover:border-ops-butterscotch rounded-r-lg overflow-hidden cursor-pointer transition-colors break-inside-avoid"
      >
        <div class="aspect-[4/3] bg-ops-plum/10 flex flex-col items-center justify-center gap-2">
          <svg class="w-12 h-12 text-ops-butterscotch group-hover:text-ops-gold transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
          </svg>
          <span v-if="folder.has_subfolders" class="text-[10px] text-ops-text-muted/50">contains folders</span>
        </div>
        <div class="p-3">
          <div class="text-sm font-medium text-ops-butterscotch truncate group-hover:text-ops-gold transition-colors">
            {{ folder.name }}
          </div>
          <div class="text-xs text-ops-text-muted mt-1">
            {{ folder.file_count }} file{{ folder.file_count !== 1 ? 's' : '' }}
          </div>
        </div>
      </div>

      <!-- File cards -->
      <div
        v-for="item in items"
        :key="item.id"
        @click="!item._deleted && $emit('select', item)"
        class="group bg-black border-2 rounded-r-lg overflow-hidden transition-colors break-inside-avoid"
        :class="[
          item._deleted ? 'opacity-50 cursor-not-allowed border-red-900' : 'cursor-pointer',
          !item._deleted && item.id === selectedId ? 'border-ops-gold ring-2 ring-ops-gold/40' : !item._deleted ? 'border-ops-plum hover:border-ops-gold' : ''
        ]"
      >
        <!-- Thumbnail / Preview area -->
        <div :class="viewMode === 'gallery' ? 'bg-ops-plum/20 relative overflow-hidden' : 'aspect-[4/3] bg-ops-plum/20 relative overflow-hidden'">
          <img
            v-if="item.thumbnail_url && (isVisualType(item) || item.has_thumbnail)"
            :src="viewMode === 'gallery' ? item.thumbnail_url?.replace('/medium', '/large') : item.thumbnail_url"
            :alt="item.title"
            :class="viewMode === 'gallery' ? 'w-full object-cover' : 'w-full h-full object-cover'"
            loading="lazy"
            @error="onImageError($event)"
          />
          <div v-else :class="viewMode === 'gallery' ? 'py-8 flex flex-col items-center justify-center gap-2' : 'w-full h-full flex flex-col items-center justify-center gap-2'">
            <div class="text-3xl">{{ typeIcon(item) }}</div>
            <span class="text-xs text-ops-text-muted uppercase">{{ item.type || item.extension }}</span>
          </div>

          <!-- Deleted overlay -->
          <div v-if="item._deleted" class="absolute inset-0 flex items-center justify-center bg-black/60">
            <svg class="w-16 h-16 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </div>

          <!-- Type badge -->
          <div v-if="!item._deleted" class="absolute top-2 left-2 px-2 py-0.5 text-xs font-semibold uppercase rounded-full"
               :class="typeBadgeClass(item)">
            {{ item.type || 'file' }}
          </div>

          <!-- Face count badge -->
          <div v-if="item.face_count > 0 && !item._deleted" class="absolute bottom-2 right-2 bg-ops-sky text-black text-xs px-2 py-0.5 rounded-full font-semibold">
            {{ item.face_count }}
          </div>

          <!-- Options menu button -->
          <div v-if="!item._deleted" class="absolute top-1 right-1 z-20">
            <button
              @click.stop="toggleMenu(item, $event)"
              class="w-6 h-6 flex items-center justify-center rounded opacity-0 group-hover:opacity-100 transition-opacity bg-black/60 hover:bg-black/80 text-ops-text"
              title="Options"
            >⋮</button>
            <div
              v-if="openMenuId != null && (openMenuId === item.id || openMenuId === item.asset_uuid)"
              class="absolute right-0 top-7 w-40 bg-gray-900 border border-ops-plum rounded shadow-xl z-30"
            >
              <a v-if="item.asset_uuid"
                :href="`/api/media/${item.asset_uuid}/stream`" target="_blank"
                @click.stop="openMenuId = null"
                class="block w-full text-left px-3 py-2 text-sm text-ops-sky hover:bg-ops-plum/30"
              >Open in Tab</a>
              <button v-if="item.asset_uuid"
                @click.stop="downloadItem(item)"
                class="w-full text-left px-3 py-2 text-sm text-ops-gold hover:bg-ops-plum/30"
              >Download</button>
              <button
                @click.stop="confirmDelete(item)"
                class="w-full text-left px-3 py-2 text-sm text-red-400 hover:bg-red-900/30 hover:text-red-300 border-t border-ops-plum/30"
              >Delete File</button>
            </div>
          </div>
        </div>

        <!-- Card info -->
        <div class="p-3">
          <div class="text-sm font-medium text-ops-peach truncate group-hover:text-ops-gold transition-colors">
            {{ item.title || item.filename || 'Untitled' }}
          </div>
          <div v-if="item.path || item.current_path" class="text-[10px] text-ops-text-muted/60 truncate mt-0.5" :title="displayKnowledgePath(item.path || item.current_path)">
            {{ displayKnowledgePath(item.path || item.current_path) }}
          </div>
          <div v-if="item.snippet" class="text-xs text-ops-text-muted mt-1 line-clamp-2">
            {{ item.snippet }}
          </div>
          <div class="flex items-center justify-between mt-2 text-xs text-ops-text-muted">
            <span>{{ formatDate(item.date) }}</span>
            <span v-if="item.file_size" class="text-ops-violet">{{ formatSize(item.file_size) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="ops-empty-state text-center py-16">
      <div class="ops-empty-icon text-4xl mb-4">{{ emptyIcon }}</div>
      <div class="ops-empty-text text-ops-peach text-lg">{{ emptyTitle }}</div>
      <div class="ops-empty-subtext text-ops-text-muted text-sm mt-2">{{ emptySubtext }}</div>
    </div>

    <!-- Infinite scroll sentinel -->
    <div v-if="hasMore && items.length > 0" ref="scrollSentinel" class="flex justify-center mt-6 py-4">
      <div v-if="loadingMore" class="text-ops-text-muted text-sm">Loading more...</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'

const scrollSentinel = ref(null)
let observer = null
const openMenuId = ref(null)

const props = defineProps({
  items: { type: Array, default: () => [] },
  folders: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  loadingMore: { type: Boolean, default: false },
  hasMore: { type: Boolean, default: false },
  selectedId: { type: String, default: null },
  viewMode: { type: String, default: 'grid' }, // 'grid' or 'gallery'
  emptyIcon: { type: String, default: '' },
  emptyTitle: { type: String, default: 'No results found' },
  emptySubtext: { type: String, default: 'Try adjusting your search or filters' }
})

const emit = defineEmits(['select', 'load-more', 'delete', 'open-folder', 'slideshow'])

function setupObserver() {
  if (observer) observer.disconnect()
  if (!scrollSentinel.value) return
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && props.hasMore && !props.loadingMore) {
      emit('load-more')
    }
  }, { rootMargin: '200px' })
  observer.observe(scrollSentinel.value)
}

function toggleMenu(item, event) {
  const id = item.id || item.asset_uuid
  openMenuId.value = openMenuId.value === id ? null : id
}

function confirmDelete(item) {
  openMenuId.value = null
  const name = item.title || item.filename || 'this file'
  if (!window.confirm(`Permanently delete "${name}"?\n\nThis will remove the file from Nextcloud, file registry, and RAG index.`)) return
  emit('delete', item)
}

function downloadItem(item) {
  if (!item.asset_uuid) return
  const a = document.createElement('a')
  a.href = `/api/media/${item.asset_uuid}/stream`
  a.download = item.filename || item.title || 'download'
  a.click()
  openMenuId.value = null
}

function onClickOutside() {
  openMenuId.value = null
}

onMounted(() => {
  setupObserver()
  document.addEventListener('click', onClickOutside)
})
onUnmounted(() => {
  if (observer) observer.disconnect()
  document.removeEventListener('click', onClickOutside)
})
watch(() => [props.hasMore, props.items.length], () => {
  setTimeout(setupObserver, 100)
})

function isVisualType(item) {
  const type = (item.type || '').toLowerCase()
  const ext = (item.extension || '').toLowerCase()
  return ['photo', 'video', 'image', 'document', 'pdf', 'presentation', 'spreadsheet'].includes(type)
    || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'pdf', 'doc', 'docx'].includes(ext)
}

function typeIcon(item) {
  const type = (item.type || '').toLowerCase()
  const icons = {
    photo: '🖼️', video: '🎬', audio: '🎵', note: '📝', transcript: '📜',
    document: '📄', spreadsheet: '📊', presentation: '📽️', code: '💻',
    archive: '📦', ebook: '📚', email: '📧', webpage: '🌐', file: '📁'
  }
  return icons[type] || '📄'
}

function typeBadgeClass(item) {
  const type = (item.type || '').toLowerCase()
  const classes = {
    photo: 'bg-ops-green/80 text-black',
    video: 'bg-ops-sky/80 text-black',
    audio: 'bg-ops-violet/80 text-white',
    note: 'bg-ops-gold/80 text-black',
    transcript: 'bg-ops-butterscotch/80 text-black',
    document: 'bg-ops-peach/80 text-black',
    code: 'bg-ops-teal/80 text-black',
  }
  return classes[type] || 'bg-ops-plum/80 text-ops-text'
}

function onImageError(e) {
  e.target.style.display = 'none'
}

function formatDate(date) {
  if (!date) return ''
  try {
    return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
  } catch { return '' }
}

function displayKnowledgePath(path) {
  if (!path) return ''
  // Strip machine/user roots and filename, show only nearby library context.
  let p = String(path).replace(/\\/g, '/').replace(/^\/+/, '')
  p = p.replace(/^[A-Za-z]:\//, '')
  p = p.replace(/^(home|users)\/[^/]+\//i, '')
  p = p.replace(/^mnt\/[^/]+\//i, '')
  const lastSlash = p.lastIndexOf('/')
  if (lastSlash > 0) p = p.substring(0, lastSlash)
  const parts = p.split('/').filter(Boolean)
  if (parts.length === 0) return 'Configured file location'
  return parts.slice(Math.max(0, parts.length - 3)).join('/')
}

function formatSize(bytes) {
  if (!bytes) return ''
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  let size = bytes
  while (size >= 1024 && i < units.length - 1) { size /= 1024; i++ }
  return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i]
}
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>

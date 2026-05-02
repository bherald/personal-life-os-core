<template>
  <div class="knowledge-landing-state">
    <!-- Quick stats -->
    <div v-if="data.stats" class="grid grid-cols-3 gap-4 mb-8">
      <div class="ops-stat-pill bg-ops-plum/20 border-2 border-ops-violet rounded-r-lg p-4 text-center">
        <div class="ops-stat-value text-2xl font-bold text-ops-gold">{{ formatCount(data.stats.total_files) }}</div>
        <div class="ops-stat-label text-xs text-ops-text-muted uppercase tracking-widest">Files</div>
      </div>
      <div class="ops-stat-pill bg-ops-plum/20 border-2 border-ops-violet rounded-r-lg p-4 text-center">
        <div class="ops-stat-value text-2xl font-bold text-ops-sky">{{ formatCount(data.stats.total_docs) }}</div>
        <div class="ops-stat-label text-xs text-ops-text-muted uppercase tracking-widest">Documents</div>
      </div>
      <div class="ops-stat-pill bg-ops-plum/20 border-2 border-ops-violet rounded-r-lg p-4 text-center">
        <div class="ops-stat-value text-2xl font-bold text-ops-lilac">{{ formatCount(data.stats.total_notes) }}</div>
        <div class="ops-stat-label text-xs text-ops-text-muted uppercase tracking-widest">Notes</div>
      </div>
    </div>

    <!-- Face review queue banner -->
    <div v-if="data.face_queue_count > 0"
         @click="$emit('open-faces')"
         class="mb-6 p-4 bg-ops-butterscotch/10 border-2 border-ops-butterscotch rounded-r-lg flex items-center justify-between cursor-pointer hover:bg-ops-butterscotch/20 transition-colors">
      <div class="flex items-center gap-3">
        <span class="text-2xl">👤</span>
        <div>
          <div class="text-ops-peach font-semibold">{{ data.face_queue_count }} faces pending review</div>
          <div class="text-xs text-ops-text-muted">Click to review face matches</div>
        </div>
      </div>
      <div class="bg-ops-butterscotch text-black px-3 py-1 rounded-full text-sm font-bold">
        {{ data.face_queue_count }}
      </div>
    </div>

    <!-- Quick search chips -->
    <div class="mb-6">
      <h3 class="text-xs font-semibold uppercase tracking-widest text-ops-lilac mb-3">Quick Search</h3>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="chip in quickChips"
          :key="chip.query"
          @click="$emit('quick-search', chip.query)"
          class="px-4 py-2 bg-ops-plum/30 text-ops-peach border border-ops-violet rounded-r-full text-sm hover:bg-ops-plum/50 hover:text-ops-gold transition-colors"
        >
          {{ chip.label }}
        </button>
      </div>
    </div>

    <!-- Recent files section -->
    <div v-if="data.recent_files?.length > 0" class="mb-8">
      <h3 class="text-xs font-semibold uppercase tracking-widest text-ops-lilac mb-3">Recent Files</h3>
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
        <div
          v-for="file in data.recent_files"
          :key="file.asset_uuid"
          @click="$emit('select', file)"
          class="cursor-pointer bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden hover:border-ops-gold transition-colors group"
        >
          <div class="aspect-[4/3] bg-ops-plum/20">
            <img
              v-if="file.has_thumbnail || isImage(file)"
              :src="file.thumbnail_url"
              :alt="file.title"
              class="w-full h-full object-cover"
              loading="lazy"
            />
            <div v-else class="w-full h-full flex items-center justify-center text-2xl">
              {{ typeIcon(file.type) }}
            </div>
          </div>
          <div class="p-2">
            <div class="text-xs text-ops-peach truncate group-hover:text-ops-gold transition-colors">{{ file.title || file.filename }}</div>
            <div class="text-xs text-ops-text-muted mt-0.5">{{ formatDate(file.date) }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent notes section -->
    <div v-if="data.recent_notes?.length > 0">
      <h3 class="text-xs font-semibold uppercase tracking-widest text-ops-lilac mb-3">Recent Notes</h3>
      <div class="space-y-2">
        <div
          v-for="note in data.recent_notes"
          :key="note.id"
          @click="$emit('select', note)"
          class="cursor-pointer p-3 bg-black border-2 border-ops-plum rounded-r-lg hover:border-ops-gold transition-colors group"
        >
          <div class="flex items-center gap-3">
            <span class="text-lg">📝</span>
            <div class="flex-1 min-w-0">
              <div class="text-sm text-ops-peach font-medium truncate group-hover:text-ops-gold transition-colors">{{ note.title }}</div>
              <div v-if="note.snippet" class="text-xs text-ops-text-muted truncate mt-0.5">{{ note.snippet }}</div>
            </div>
            <span class="text-xs text-ops-text-muted flex-shrink-0">{{ formatDate(note.date) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="ops-loading"><span class="ops-loading-dot"></span><span class="ops-loading-dot"></span><span class="ops-loading-dot"></span></div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  data: { type: Object, default: () => ({ recent_files: [], recent_notes: [], face_queue_count: 0, stats: {} }) },
  loading: { type: Boolean, default: false }
})

defineEmits(['select', 'quick-search', 'open-faces'])

const quickChips = [
  { label: 'Photos Today', query: 'photos today' },
  { label: 'Recent Notes', query: 'type:notes' },
  { label: 'YouTube Transcripts', query: 'type:transcripts' },
  { label: 'Documents', query: 'type:documents' },
  { label: 'Family Photos', query: 'family' },
]

function isImage(file) {
  return ['photo', 'image'].includes(file.type?.toLowerCase())
}

function typeIcon(type) {
  const icons = { photo: '🖼️', video: '🎬', audio: '🎵', document: '📄', code: '💻', spreadsheet: '📊' }
  return icons[type] || '📁'
}

function formatCount(n) {
  if (!n) return '0'
  if (n >= 1000) return (n / 1000).toFixed(1) + 'k'
  return n.toLocaleString()
}

function formatDate(date) {
  if (!date) return ''
  try { return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }
  catch { return '' }
}
</script>

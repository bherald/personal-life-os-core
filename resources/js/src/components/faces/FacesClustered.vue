<template>
  <div class="p-4">
    <!-- Sub-filter bar -->
    <div class="flex items-center gap-2 mb-3">
      <select
        :value="filter"
        @change="$emit('filter-change', $event.target.value)"
        class="bg-black/50 border border-ops-peach/40 rounded px-2 py-1 text-xs text-ops-text"
      >
        <option value="all">All Clusters</option>
        <option value="unidentified">Unidentified</option>
        <option value="identified">Identified</option>
      </select>

      <select
        :value="sort"
        @change="$emit('sort-change', $event.target.value)"
        class="bg-black/50 border border-ops-peach/40 rounded px-2 py-1 text-xs text-ops-text"
      >
        <option value="size_desc">Largest first</option>
        <option value="size_asc">Smallest first</option>
        <option value="recent">Most recent</option>
        <option value="name">By name</option>
      </select>

      <select
        :value="minFaces"
        @change="$emit('min-faces-change', parseInt($event.target.value))"
        class="bg-black/50 border border-ops-peach/40 rounded px-2 py-1 text-xs text-ops-text"
      >
        <option :value="1">All sizes</option>
        <option :value="2">2+ faces</option>
        <option :value="5">5+ faces</option>
        <option :value="10">10+ faces</option>
      </select>

      <div class="flex-1"></div>
      <span class="text-xs text-ops-text-muted">{{ total }} clusters</span>
    </div>

    <!-- Empty state -->
    <div v-if="clusters.length === 0 && !loading" class="text-center py-12 text-ops-text-muted">
      <p class="text-lg">No clusters found</p>
      <p class="text-sm mt-1">Run <code class="text-ops-peach">faces:cluster</code> to generate clusters</p>
    </div>

    <!-- Cluster card grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
      <div
        v-for="(cluster, idx) in clusters"
        :key="cluster.id"
        class="group bg-ops-plum/20 rounded-lg overflow-hidden border-2 transition-all cursor-pointer"
        :class="cardClass(cluster, idx)"
        @click="handleCardClick(cluster, idx, $event)"
      >
        <!-- Header -->
        <div class="flex items-center justify-between px-3 py-2 border-b border-ops-plum/30">
          <div class="flex items-center gap-2 min-w-0">
            <!-- Checkbox -->
            <input
              type="checkbox"
              :checked="isSelected(cluster.id)"
              @click.stop="$emit('toggle-select', cluster.id)"
              class="rounded border-ops-peach/40 bg-black/50 text-ops-gold focus:ring-ops-peach"
            />
            <!-- Name or ID -->
            <span class="text-sm font-semibold truncate" :class="cluster.name ? 'text-ops-peach' : 'text-ops-text-muted'">
              {{ cluster.name || `Cluster #${cluster.id}` }}
            </span>
          </div>
          <div class="flex items-center gap-2 flex-shrink-0">
            <span :class="statusBadgeClass(cluster.status)" class="px-1.5 py-0.5 rounded text-[10px] uppercase font-semibold">
              {{ statusLabel(cluster.status) }}
            </span>
            <span class="text-xs text-ops-text-muted">{{ cluster.face_count }}</span>
          </div>
        </div>

        <!-- Face grid (up to 6 samples) -->
        <div class="grid grid-cols-3 gap-0.5 p-1">
          <div
            v-for="face in (cluster.sample_faces || []).slice(0, 6)"
            :key="face.id"
            class="aspect-square relative overflow-hidden rounded"
          >
            <img
              :src="faceCropUrl(face)"
              :alt="`Face ${face.id}`"
              class="w-full h-full object-cover"
              loading="lazy"
              @error="onImgError"
            />
            <!-- Confidence dot -->
            <div
              v-if="face.match_confidence"
              class="absolute bottom-0.5 right-0.5 w-2 h-2 rounded-full"
              :class="confidenceDotClass(face.match_confidence)"
              :title="`${Math.round(face.match_confidence * 100)}% confidence`"
            ></div>
          </div>
          <!-- Empty slots -->
          <div
            v-for="n in Math.max(0, 6 - (cluster.sample_faces || []).length)"
            :key="`empty-${n}`"
            class="aspect-square bg-ops-plum/10 rounded"
          ></div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between px-3 py-1.5 border-t border-ops-plum/30">
          <div class="flex gap-1">
            <button
              v-if="cluster.status !== 'confirmed'"
              @click.stop="$emit('identify', cluster)"
              class="px-2 py-1 text-[10px] bg-ops-gold/80 text-black rounded font-semibold hover:bg-ops-gold uppercase"
            >
              Identify
            </button>
            <button
              @click.stop="$emit('split', cluster)"
              class="px-2 py-1 text-[10px] bg-ops-plum/40 text-ops-text-muted rounded hover:text-ops-peach uppercase"
            >
              Split
            </button>
            <button
              v-if="cluster.status !== 'ignored'"
              @click.stop="$emit('hide', cluster.id)"
              class="px-2 py-1 text-[10px] bg-ops-plum/40 text-ops-text-muted rounded hover:text-red-400 uppercase"
            >
              Hide
            </button>
            <button
              v-if="cluster.status === 'ignored'"
              @click.stop="$emit('restore', cluster.id)"
              class="px-2 py-1 text-[10px] bg-ops-plum/40 text-ops-text-muted rounded hover:text-ops-peach uppercase"
            >
              Restore
            </button>
          </div>
          <button
            v-if="cluster.sample_faces?.length > 0"
            @click.stop="$emit('photo-overlay', cluster.sample_faces[0])"
            class="text-ops-text-muted hover:text-ops-peach"
            title="View in photo"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </button>
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
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  clusters: { type: Array, default: () => [] },
  loading: Boolean,
  hasMore: Boolean,
  filter: { type: String, default: 'all' },
  sort: { type: String, default: 'size_desc' },
  minFaces: { type: Number, default: 1 },
  total: { type: Number, default: 0 },
  selectedIds: { type: Set, default: () => new Set() },
  focusedIndex: { type: Number, default: -1 },
})

const emit = defineEmits([
  'load-more', 'identify', 'split', 'hide', 'restore',
  'toggle-select', 'focus', 'photo-overlay',
  'filter-change', 'sort-change', 'min-faces-change',
])

const sentinel = ref(null)
let observer = null

onMounted(() => {
  nextTick(() => {
    if (!sentinel.value) return
    const scrollParent = sentinel.value.closest('.overflow-y-auto')
    observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting && props.hasMore && !props.loading) {
          emit('load-more')
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

function isSelected(id) {
  return props.selectedIds.has(id)
}

function handleCardClick(cluster, idx, event) {
  if (event.shiftKey) {
    emit('toggle-select', cluster.id)
  } else {
    emit('focus', { cluster, index: idx })
  }
}

function cardClass(cluster, idx) {
  if (idx === props.focusedIndex) return 'border-ops-gold ring-1 ring-ops-gold/50'
  if (isSelected(cluster.id)) return 'border-ops-gold/60'
  return 'border-transparent hover:border-ops-peach/30'
}

function statusBadgeClass(status) {
  switch (status) {
    case 'confirmed': return 'bg-green-900/50 text-green-300'
    case 'unreviewed': return 'bg-yellow-900/50 text-yellow-300'
    case 'ignored': return 'bg-gray-700/50 text-gray-400'
    case 'merged': return 'bg-gray-700/50 text-gray-500'
    default: return 'bg-gray-700/50 text-gray-400'
  }
}

function statusLabel(status) {
  switch (status) {
    case 'confirmed': return 'identified'
    case 'unreviewed': return 'new'
    case 'ignored': return 'hidden'
    case 'merged': return 'merged'
    default: return status
  }
}

function faceCropUrl(face) {
  if (face.id) return `/api/media/face-crop/${face.id}`
  if (face.file_registry_face_id) return `/api/media/faces/registry-crop/${face.file_registry_face_id}`
  return 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}

function confidenceDotClass(confidence) {
  if (confidence >= 0.85) return 'bg-green-400'
  if (confidence >= 0.65) return 'bg-yellow-400'
  return 'bg-red-400'
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

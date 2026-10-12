<template>
  <div class="p-4">
    <!-- Grid of named people -->
    <div v-if="recognized.length === 0 && !loading" class="text-center py-12 text-ops-text-muted">
      <p class="text-lg">No named people yet</p>
      <p class="text-sm mt-1">Name faces in the "New" tab to see them here</p>
    </div>

    <div class="face-people-grid grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8 gap-3" :class="{ 'has-global-people': recognized.some(isGlobal) }">
      <div
        v-for="person in recognized"
        :key="person.identity_key || person.person_name"
        class="group relative bg-ops-plum/20 rounded-lg overflow-hidden border border-transparent hover:border-ops-peach/40 transition-colors cursor-pointer"
        :class="{ 'global-person-card': isGlobal(person) }"
        @click="handleClick(person)"
      >
        <!-- Face image -->
        <div class="aspect-square relative overflow-hidden">
          <img
            :src="personImageSrc(person)"
            :alt="person.person_name"
            class="w-full h-full object-cover"
            loading="lazy"
            @error="onImgError"
          />

          <!-- Hover overlay — click to review faces -->
          <div class="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
            <span class="text-xs text-white/80 font-semibold bg-black/50 px-2 py-1 rounded">Review faces</span>
          </div>
        </div>

        <!-- Name + count -->
        <div class="p-2">
          <div class="flex items-center gap-1 min-w-0">
            <span
              class="text-sm text-ops-text truncate block min-w-0 flex-1"
              :title="person.person_name"
            >
              {{ person.person_name }}
            </span>
            <button
              @click.stop="$emit('edit', person)"
              class="shrink-0 w-6 h-6 rounded border border-ops-peach/25 text-ops-text-muted hover:text-ops-peach hover:border-ops-peach/60 transition-colors"
              :title="isGlobal(person) ? 'Person names and aliases' : 'Rename face label'"
              :aria-label="isGlobal(person) ? 'Person names and aliases' : 'Rename face label'"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
          </div>
          <div v-if="isGlobal(person)" class="global-person-details">
            <span>Confirmed person</span>
            <span v-if="person.aliases?.length">Aliases: {{ person.aliases.join(', ') }}</span>
            <small>Record {{ person.identity_uuid?.slice(-8) }}</small>
          </div>
          <div class="mt-1 flex flex-wrap gap-1">
            <span
              v-if="person.has_face_rows"
              class="rounded border border-ops-peach/25 px-1.5 py-0.5 text-[10px] uppercase text-ops-text-muted"
            >
              Faces
            </span>
            <span
              v-if="person.has_ft_media"
              class="rounded border border-ops-gold/40 bg-ops-gold/10 px-1.5 py-0.5 text-[10px] uppercase text-ops-gold"
            >
              FT photos
            </span>
          </div>
          <div
            v-if="person.face_name_variant_count > 1"
            class="mt-0.5 text-[10px] text-ops-gold truncate"
            :title="person.face_name_variants"
          >
            {{ person.face_name_variant_count }} labels
          </div>
          <div class="text-xs text-ops-text-muted">
            <span>{{ person.face_count || 0 }} face{{ person.face_count === 1 ? '' : 's' }}</span>
            <span v-if="person.linked_media_count > 0" class="ml-1">{{ person.linked_media_count }} FT photo{{ person.linked_media_count === 1 ? '' : 's' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading indicator -->
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
  recognized: { type: Array, default: () => [] },
  loading: Boolean,
  hasMore: Boolean,
})

const emit = defineEmits(['load-more', 'edit', 'click'])

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

function handleClick(person) {
  emit('click', person)
}

function personImageSrc(person) {
  if (isGlobal(person)) {
    try {
      const url = new URL(person.representative_crop_url, location.origin)
      const keys = [...url.searchParams.keys()]
      if (url.origin === location.origin && !url.hash && !url.username && !url.password
        && url.pathname === `/api/media/faces/registry-crop/${person.representative_face_id}`
        && keys.length === 2 && keys.includes('source_sha256') && keys.includes('region_fingerprint')
        && /^[a-f0-9]{64}$/.test(url.searchParams.get('source_sha256') || '')
        && /^[a-f0-9]{64}$/.test(url.searchParams.get('region_fingerprint') || '')) return url.href
    } catch {}
    return placeholderImage()
  }
  if (person?.representative_face_id) {
    return `/api/media/faces/registry-crop/${person.representative_face_id}`
  }

  return person?.representative_thumbnail_url || placeholderImage()
}

function isGlobal(person) { return person?.scope_type === 'global_identity' || person?.identity_key?.startsWith('global:') }

function onImgError(e) {
  e.target.src = placeholderImage()
}

function placeholderImage() {
  return 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

<style scoped>
.global-person-card { background: var(--desk-paper); border-color: var(--desk-line); color: var(--desk-ink); }
.global-person-card:hover { border-color: var(--desk-teal); }
.global-person-card .text-ops-text { color: var(--desk-ink); }
.global-person-card .text-sm { overflow: visible; white-space: normal; overflow-wrap: anywhere; }
.global-person-card button { min-width: 44px; min-height: 44px; border-color: var(--desk-line); color: var(--desk-ink); }
.global-person-details { display: grid; gap: 3px; margin-top: 6px; font-size: 11px; color: var(--desk-muted); overflow-wrap: anywhere; }
@media (max-width: 600px) { .face-people-grid.has-global-people { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>

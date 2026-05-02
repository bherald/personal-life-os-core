<template>
  <div class="p-4">
    <div v-if="faces.length === 0 && !loading" class="text-center py-12 text-ops-text-muted">
      <p class="text-lg">No hidden faces</p>
    </div>

    <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10 gap-3">
      <div
        v-for="face in faces"
        :key="face.face_id"
        class="group relative bg-ops-plum/20 rounded-lg overflow-hidden border border-transparent hover:border-ops-peach/40 transition-colors"
      >
        <!-- Face image -->
        <div class="aspect-square relative overflow-hidden">
          <img
            :src="`/api/media/faces/registry-crop/${face.face_id}`"
            :alt="face.person_name || 'Unknown'"
            class="w-full h-full object-cover opacity-60"
            loading="lazy"
            @error="onImgError"
          />

          <!-- Hover overlay -->
          <div class="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
            <button
              @click.stop="$emit('unhide', face.face_id)"
              class="px-3 py-1.5 text-xs bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange"
              title="Restore this face"
            >
              Restore
            </button>
          </div>
        </div>

        <!-- Name / info -->
        <div class="px-1.5 py-1">
          <span v-if="face.person_name" class="text-xs text-ops-text truncate block">{{ face.person_name }}</span>
          <span v-else class="text-xs text-ops-text-muted truncate block italic">unnamed</span>
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
  faces: { type: Array, default: () => [] },
  loading: Boolean,
  hasMore: Boolean,
})

const emit = defineEmits(['load-more', 'unhide'])

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

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

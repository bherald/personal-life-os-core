<template>
  <div class="p-4">
    <!-- Grid of named people -->
    <div v-if="recognized.length === 0 && !loading" class="text-center py-12 text-ops-text-muted">
      <p class="text-lg">No named people yet</p>
      <p class="text-sm mt-1">Name faces in the "New" tab to see them here</p>
    </div>

    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8 gap-3">
      <div
        v-for="person in recognized"
        :key="person.person_name"
        class="group relative bg-ops-plum/20 rounded-lg overflow-hidden border border-transparent hover:border-ops-peach/40 transition-colors cursor-pointer"
        @click="handleClick(person)"
      >
        <!-- Face image -->
        <div class="aspect-square relative overflow-hidden">
          <img
            :src="`/api/media/faces/registry-crop/${person.representative_face_id}`"
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
          <button
            @click.stop="$emit('edit', person)"
            class="text-sm text-ops-text hover:text-ops-peach truncate block w-full text-left"
            :title="person.person_name"
          >
            {{ person.person_name }}
          </button>
          <span class="text-xs text-ops-text-muted">{{ person.face_count }} photos</span>
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

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

<template>
  <div class="w-80 border-l border-ops-peach/20 bg-black/50 flex flex-col h-full overflow-hidden">
    <!-- Header -->
    <div class="px-4 py-3 border-b border-ops-peach/20">
      <h3 class="text-sm font-semibold text-ops-peach uppercase">Similar Clusters</h3>
      <p v-if="clusterId" class="text-[10px] text-ops-text-muted mt-0.5">
        Selected cluster · {{ clusterName || 'Unnamed' }}
      </p>
    </div>

    <!-- Threshold slider -->
    <div class="px-4 py-2 border-b border-ops-plum/20">
      <label class="flex items-center justify-between text-[10px] text-ops-text-muted">
        <span>Similarity threshold</span>
        <span>{{ Math.round(threshold * 100) }}%</span>
      </label>
      <input
        :value="threshold"
        @input="$emit('threshold-change', parseFloat($event.target.value))"
        type="range" min="0.30" max="0.95" step="0.05"
        class="w-full h-1 mt-1 accent-ops-peach"
      />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="inline-block w-5 h-5 border-2 border-ops-peach border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!clusterId" class="flex-1 flex items-center justify-center text-ops-text-muted text-xs px-4 text-center">
      Focus a cluster to see similar matches
    </div>

    <div v-else-if="suggestions.length === 0" class="flex-1 flex items-center justify-center text-ops-text-muted text-xs px-4 text-center">
      No similar clusters found at this threshold
    </div>

    <!-- Suggestions list -->
    <div v-else class="flex-1 overflow-y-auto">
      <div
        v-for="suggestion in suggestions"
        :key="suggestion.cluster_id"
        class="px-4 py-3 border-b border-ops-plum/20 hover:bg-ops-plum/20 cursor-pointer transition-colors"
        @click="$emit('scroll-to', suggestion.cluster_id)"
      >
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-xs font-semibold" :class="suggestion.name ? 'text-ops-peach' : 'text-ops-text-muted'">
            {{ similarClusterLabel(suggestion) }}
          </span>
          <span class="text-[10px] font-mono" :class="confidenceClass(suggestion.max_confidence)">
            {{ Math.round(suggestion.max_confidence * 100) }}%
          </span>
        </div>

        <!-- Sample faces -->
        <div class="flex gap-1 mb-1.5">
          <img
            v-for="face in (suggestion.sample_faces || []).slice(0, 4)"
            :key="face.id"
            :src="face.crop_url || `/api/media/face-crop/${face.id}`"
            class="w-8 h-8 rounded object-cover"
            loading="lazy"
            @error="onImgError"
          />
        </div>

        <div class="flex items-center justify-between">
          <span class="text-[10px] text-ops-text-muted">{{ suggestion.face_count }} faces</span>
          <button
            @click.stop="$emit('quick-merge', suggestion.cluster_id)"
            class="px-2 py-0.5 text-[10px] bg-ops-gold/80 text-black rounded font-semibold hover:bg-ops-gold uppercase"
          >
            Merge
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  clusterId: Number,
  clusterName: String,
  suggestions: { type: Array, default: () => [] },
  loading: Boolean,
  threshold: { type: Number, default: 0.5 },
})

defineEmits(['scroll-to', 'quick-merge', 'threshold-change'])

function confidenceClass(confidence) {
  if (confidence >= 0.85) return 'text-green-400'
  if (confidence >= 0.65) return 'text-yellow-400'
  return 'text-red-400'
}

function similarClusterLabel(suggestion) {
  return suggestion?.name || 'Similar cluster'
}

function onImgError(e) {
  e.target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23333" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23666" font-size="14">?</text></svg>'
}
</script>

<template>
  <div
    class="review-item face-match"
    :class="{ selected }"
    @click="$emit('select', item)"
  >
    <!-- Checkbox for batch selection -->
    <div class="item-checkbox">
      <input
        type="checkbox"
        :checked="selected"
        @click.stop="$emit('toggle-select', item)"
        class="w-4 h-4 rounded border-ops-plum text-ops-orange focus:ring-ops-orange"
      />
    </div>

    <!-- Face Image -->
    <div class="item-image">
      <img
        v-if="item.image_url && !imageError"
        :src="item.image_url"
        :alt="item.face_name || 'Face'"
        class="w-full h-full object-cover"
        @error="imageError = true"
      />
      <div v-else class="w-full h-full flex items-center justify-center bg-ops-plum/30">
        <svg class="w-8 h-8 text-ops-text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        </svg>
      </div>
    </div>

    <!-- Content -->
    <div class="item-content">
      <div class="flex items-center gap-2 mb-1">
        <span class="item-badge bg-ops-green/20 text-ops-green">Face Match</span>
        <span v-if="confidencePercent" class="item-confidence" :class="confidenceClass">
          {{ confidencePercent }}%
        </span>
        <span v-if="item.match_type" class="text-xs text-ops-text-muted">
          {{ item.match_type }}
        </span>
      </div>

      <div class="item-title">{{ item.face_name || 'Unknown Face' }}</div>

      <div class="item-summary">
        <template v-if="item.suggested_person_name">
          Suggested: <span class="text-ops-sky">{{ item.suggested_person_name }}</span>
        </template>
        <template v-else>
          New face detected - no match suggestion
        </template>
      </div>

      <div class="item-meta">
        <span v-if="item.tree_id" class="text-xs text-ops-text-muted">Tree #{{ item.tree_id }}</span>
        <span v-if="item.created_at" class="text-xs text-ops-text-muted">{{ formatDate(item.created_at) }}</span>
      </div>
    </div>

    <!-- Actions -->
    <div class="item-actions">
      <button @click.stop="$emit('approve', item)" class="action-btn approve" title="Approve match">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
      </button>
      <button @click.stop="$emit('action', { item, action: { name: 'link', label: 'Link' } })" class="action-btn link" title="Link to different person">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
        </svg>
      </button>
      <button @click.stop="$emit('reject', item)" class="action-btn reject" title="Reject / Ignore">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  item: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})

defineEmits(['select', 'toggle-select', 'approve', 'reject', 'action'])

const imageError = ref(false)

const confidencePercent = computed(() => {
  if (!props.item.confidence) return null
  return Math.round(props.item.confidence * 100)
})

const confidenceClass = computed(() => {
  const pct = confidencePercent.value
  if (pct >= 80) return 'high'
  if (pct >= 50) return 'medium'
  return 'low'
})

const formatDate = (dateStr) => {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
</script>

<style scoped>
.review-item {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem;
  background: var(--ops-plum);
  border-radius: 0 16px 16px 0;
  cursor: pointer;
  transition: all 0.15s ease;
}

.review-item:hover {
  background: var(--ops-plum-light, #5c4a72);
  transform: translateX(4px);
}

.review-item.selected {
  background: var(--ops-magenta);
  box-shadow: 0 0 0 2px var(--ops-orange);
}

.item-checkbox {
  flex-shrink: 0;
}

.item-image {
  width: 64px;
  height: 64px;
  border-radius: 8px;
  overflow: hidden;
  flex-shrink: 0;
  background: var(--ops-black);
}

.item-content {
  flex: 1;
  min-width: 0;
}

.item-badge {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  border-radius: 999px;
  font-size: 0.625rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.item-confidence {
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
  background: var(--ops-sky);
  color: var(--ops-black);
}

.item-confidence.high { background: var(--ops-green); }
.item-confidence.medium { background: var(--ops-gold); }
.item-confidence.low { background: var(--ops-sunset); color: var(--ops-white); }

.item-title {
  font-size: 0.9375rem;
  font-weight: 500;
  color: var(--ops-peach);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.item-summary {
  font-size: 0.8125rem;
  color: var(--ops-text-muted);
  margin-top: 0.25rem;
}

.item-meta {
  display: flex;
  gap: 0.75rem;
  margin-top: 0.5rem;
}

.item-actions {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  flex-shrink: 0;
}

.action-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}

.action-btn.approve {
  background: var(--ops-green);
  color: var(--ops-black);
}

.action-btn.approve:hover {
  filter: brightness(1.2);
}

.action-btn.link {
  background: var(--ops-sky);
  color: var(--ops-black);
}

.action-btn.link:hover {
  filter: brightness(1.2);
}

.action-btn.reject {
  background: var(--ops-sunset);
  color: var(--ops-white);
}

.action-btn.reject:hover {
  filter: brightness(1.2);
}
</style>

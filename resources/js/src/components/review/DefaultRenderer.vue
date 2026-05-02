<template>
  <div
    class="review-item default"
    :class="{ selected }"
    @click="$emit('select', item)"
  >
    <!-- Checkbox -->
    <div class="item-checkbox">
      <input
        type="checkbox"
        :checked="selected"
        @click.stop="$emit('toggle-select', item)"
        class="w-4 h-4 rounded border-ops-plum text-ops-orange focus:ring-ops-orange"
      />
    </div>

    <!-- Color indicator -->
    <div class="item-indicator" :class="item.color || 'bg-ops-sky'"></div>

    <!-- Content -->
    <div class="item-content">
      <div class="flex items-center gap-2 mb-1">
        <span class="item-badge" :class="item.color || 'bg-ops-sky/20'">
          {{ item.source }}
        </span>
        <span v-if="confidencePercent" class="item-confidence" :class="confidenceClass">
          {{ confidencePercent }}%
        </span>
        <span v-if="item.priority" class="item-priority" :class="'priority-' + item.priority">
          {{ item.priority }}
        </span>
      </div>

      <div class="item-title">{{ item.title || 'Untitled' }}</div>
      <div class="item-summary">{{ item.summary || 'No description' }}</div>

      <div class="item-meta">
        <span v-if="item.created_at" class="text-xs text-ops-text-muted">
          {{ formatDate(item.created_at) }}
        </span>
      </div>
    </div>

    <!-- Actions -->
    <div class="item-actions">
      <button @click.stop="$emit('approve', item)" class="action-btn approve" title="Approve">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
      </button>
      <button @click.stop="$emit('reject', item)" class="action-btn reject" title="Reject">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})

defineEmits(['select', 'toggle-select', 'approve', 'reject', 'action'])

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
  transform: translateX(4px);
  filter: brightness(1.1);
}

.review-item.selected {
  background: var(--ops-magenta);
  box-shadow: 0 0 0 2px var(--ops-orange);
}

.item-checkbox {
  flex-shrink: 0;
}

.item-indicator {
  width: 8px;
  height: 48px;
  border-radius: 4px;
  flex-shrink: 0;
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
  color: var(--ops-black);
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

.item-priority {
  font-size: 0.625rem;
  font-weight: 600;
  text-transform: uppercase;
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
}

.priority-high { background: var(--ops-sunset); color: var(--ops-white); }
.priority-medium { background: var(--ops-gold); color: var(--ops-black); }
.priority-low { background: var(--ops-plum-light, #5c4a72); color: var(--ops-peach); }

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

.action-btn.reject {
  background: var(--ops-sunset);
  color: var(--ops-white);
}

.action-btn:hover {
  filter: brightness(1.2);
}
</style>

<template>
  <div
    class="review-item agent-finding"
    :class="{ selected, expiring: isExpiringSoon }"
    @click="$emit('select', item)"
  >
    <div class="item-checkbox">
      <input
        type="checkbox"
        :checked="selected"
        @click.stop="$emit('toggle-select', item)"
        class="w-4 h-4 rounded border-ops-plum text-ops-orange focus:ring-ops-orange"
      />
    </div>

    <div class="item-indicator bg-ops-sky"></div>

    <div class="item-content">
      <div class="flex items-center gap-2 mb-1">
        <span class="item-badge bg-ops-sky/20 text-ops-sky">Agent</span>
        <span v-if="item.review_type" class="text-xs text-ops-text-muted">
          {{ item.review_type }}
        </span>
        <span v-if="item.priority" class="item-priority" :class="'priority-' + item.priority">
          {{ item.priority }}
        </span>
        <span v-if="isExpiringSoon" class="item-expiring">
          Expires {{ formatTimeLeft }}
        </span>
      </div>

      <div class="item-title">{{ item.title || 'Agent Finding' }}</div>
      <div class="item-summary">{{ item.summary || 'No details' }}</div>

      <div class="item-meta">
        <span v-if="item.agent_id" class="text-xs text-ops-lilac">{{ item.agent_id }}</span>
        <span v-if="item.created_at" class="text-xs text-ops-text-muted">{{ formatDate(item.created_at) }}</span>
      </div>
    </div>

    <div class="item-actions">
      <button @click.stop="$emit('approve', item)" class="action-btn approve">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
      </button>
      <button @click.stop="$emit('reject', item)" class="action-btn reject">
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

const isExpiringSoon = computed(() => {
  if (!props.item.expires_at) return false
  const expires = new Date(props.item.expires_at)
  const sixHours = 6 * 60 * 60 * 1000
  return expires.getTime() - Date.now() < sixHours
})

const formatTimeLeft = computed(() => {
  if (!props.item.expires_at) return ''
  const expires = new Date(props.item.expires_at)
  const diff = expires.getTime() - Date.now()
  const hours = Math.floor(diff / (60 * 60 * 1000))
  if (hours < 1) return 'soon'
  return `in ${hours}h`
})

const formatDate = (dateStr) => {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
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

.review-item.expiring {
  border-left: 4px solid var(--ops-sunset);
}

.item-checkbox { flex-shrink: 0; }

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
}

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

.item-expiring {
  font-size: 0.625rem;
  font-weight: 600;
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
  background: var(--ops-sunset);
  color: var(--ops-white);
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.7; }
}

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
  white-space: pre-line;
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

.action-btn.approve { background: var(--ops-green); color: var(--ops-black); }
.action-btn.reject { background: var(--ops-sunset); color: var(--ops-white); }
.action-btn:hover { filter: brightness(1.2); }
</style>

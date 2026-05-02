<template>
  <div
    class="review-item research-fact"
    :class="{ selected }"
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

    <div class="item-indicator bg-ops-butterscotch"></div>

    <div class="item-content">
      <div class="flex items-center gap-2 mb-1">
        <span class="item-badge bg-ops-butterscotch/20 text-ops-butterscotch">Research</span>
        <span v-if="item.fact_type" class="item-type">{{ item.fact_type }}</span>
        <span v-if="item.domain_category" class="item-domain">{{ item.domain_category }}</span>
        <span v-if="confidencePercent" class="item-confidence" :class="confidenceClass">
          {{ confidencePercent }}%
        </span>
      </div>

      <div class="item-title">{{ item.title || truncatedStatement }}</div>
      <div class="item-summary">{{ item.summary || item.fact_statement }}</div>

      <div v-if="item.mission_title" class="item-mission">
        Mission: {{ item.mission_title }}
      </div>

      <div v-if="item.verification_summary" class="item-verification">
        {{ item.verification_summary }}
      </div>

      <div class="item-meta">
        <span v-if="sourceCount" class="text-xs text-ops-sky">{{ sourceCount }} sources</span>
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

const truncatedStatement = computed(() => {
  const stmt = props.item.fact_statement || ''
  return stmt.length > 80 ? stmt.slice(0, 80) + '...' : stmt
})

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

const sourceCount = computed(() => {
  const urls = props.item.source_urls
  if (Array.isArray(urls)) return urls.length
  return 0
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
  align-items: flex-start;
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

.item-checkbox { flex-shrink: 0; margin-top: 0.25rem; }

.item-indicator {
  width: 8px;
  min-height: 48px;
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

.item-type, .item-domain {
  font-size: 0.625rem;
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
  background: var(--ops-plum-light, #5c4a72);
  color: var(--ops-peach);
  text-transform: capitalize;
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
}

.item-summary {
  font-size: 0.8125rem;
  color: var(--ops-text-muted);
  margin-top: 0.25rem;
}

.item-mission {
  font-size: 0.75rem;
  color: var(--ops-lilac);
  margin-top: 0.375rem;
}

.item-verification {
  font-size: 0.75rem;
  color: var(--ops-sky);
  margin-top: 0.375rem;
  font-style: italic;
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

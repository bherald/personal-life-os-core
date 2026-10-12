<template>
  <span class="match-badge" :class="badgeClass" :title="tooltip">
    <span class="match-badge-icon" aria-hidden="true">{{ icon }}</span>
    <span class="match-badge-label">{{ label }}</span>
    <span v-if="delta" class="match-badge-delta">{{ delta }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    required: true,
    validator: (v) => ['same', 'match', 'different', 'new', 'missing', 'conflict', 'unknown'].includes(v),
  },
  delta: { type: String, default: null },
})

const META = {
  same:      { label: 'Same',      icon: '=',  cls: 'badge-same',      tip: 'Proposed value matches the on-file record' },
  match:     { label: 'Match',     icon: '✓',  cls: 'badge-match',     tip: 'Proposed value normalizes to the on-file value' },
  different: { label: 'Different', icon: '≠',  cls: 'badge-different', tip: 'Proposed value differs from the on-file record' },
  new:       { label: 'New',       icon: '+',  cls: 'badge-new',       tip: 'On-file record has no value for this field; proposal would add it' },
  missing:   { label: 'Missing',   icon: '–',  cls: 'badge-missing',   tip: 'Proposed record omits a value that exists on file' },
  conflict:  { label: 'Conflict',  icon: '⚠',  cls: 'badge-conflict',  tip: 'Proposed value contradicts a fact on file — requires resolution' },
  unknown:   { label: 'Other',     icon: '?',  cls: 'badge-unknown',   tip: 'Unrecognized proposal shape; surfaced for visibility' },
}

const meta = computed(() => META[props.status] ?? META.unknown)
const label = computed(() => meta.value.label)
const icon = computed(() => meta.value.icon)
const badgeClass = computed(() => meta.value.cls)
const tooltip = computed(() => meta.value.tip)
</script>

<style scoped>
.match-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.15rem 0.5rem;
  border-radius: 0.375rem;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  white-space: nowrap;
}
.match-badge-icon { font-size: 0.85rem; line-height: 1; }
.match-badge-delta { opacity: 0.85; font-weight: 500; }

.badge-same      { background: var(--color-bg-tertiary); color: var(--color-text-primary); }
.badge-match     { background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));    color: var(--ops-success); }
.badge-different { background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));  color: var(--ops-warning); }
.badge-new       { background: var(--color-bg-tertiary); color: var(--color-text-primary); }
.badge-missing   { background: var(--color-bg-tertiary); color: var(--color-text-secondary); }
.badge-conflict  { background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));     color: var(--ops-danger); }
.badge-unknown   { background: var(--color-bg-tertiary); color: var(--color-text-primary); }
</style>

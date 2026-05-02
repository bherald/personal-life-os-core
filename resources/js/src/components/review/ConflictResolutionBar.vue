<template>
  <div v-if="conflicts.length" class="conflict-bar">
    <div class="conflict-icon" aria-hidden="true">⚠</div>
    <div class="conflict-content">
      <div class="conflict-title">
        {{ conflicts.length }} conflicting field{{ conflicts.length === 1 ? '' : 's' }} — review before deciding
      </div>
      <ul class="conflict-list">
        <li v-for="c in conflicts" :key="c.field">
          <span class="conflict-field">{{ formatField(c.field) }}</span>
          <span class="conflict-values">{{ formatValue(c.on_file) }} → {{ formatValue(c.proposed) }}</span>
          <span v-if="c.delta" class="conflict-delta">({{ c.delta }})</span>
        </li>
      </ul>
      <div class="conflict-note">
        Phase 1 surfaces conflicts read-only; per-field resolution lands in Phase 3.
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  fieldDiffs: { type: Array, default: () => [] },
})

const conflicts = computed(() =>
  props.fieldDiffs.filter((d) => d.match_status === 'conflict'),
)

const FIELD_LABELS = {
  birth_date: 'Birth date',
  death_date: 'Death date',
  marriage_date: 'Marriage date',
  burial_date: 'Burial date',
}

function formatField(field) { return FIELD_LABELS[field] || field }
function formatValue(v) {
  if (v === null || v === undefined || v === '') return '—'
  if (Array.isArray(v)) return v.join(', ')
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
</script>

<style scoped>
.conflict-bar {
  display: flex;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  background: rgba(204, 0, 0, 0.12);
  border: 1px solid rgba(204, 0, 0, 0.40);
  border-left: 4px solid #cc0000;
  border-radius: 0.375rem;
  margin-bottom: 1rem;
}
.conflict-icon { font-size: 1.5rem; line-height: 1; color: #ff8080; }
.conflict-content { flex: 1; }
.conflict-title {
  font-weight: 700;
  color: #ffb5b5;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.conflict-list { margin: 0.5rem 0 0 0; padding: 0; list-style: none; }
.conflict-list li {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  font-size: 0.8rem;
  padding: 0.15rem 0;
}
.conflict-field { color: #b39ddb; font-weight: 600; min-width: 7rem; }
.conflict-values { color: #f0e6ff; }
.conflict-delta { color: #ffb5b5; font-weight: 600; }
.conflict-note {
  margin-top: 0.5rem;
  font-size: 0.7rem;
  color: #b39ddb;
  font-style: italic;
}
</style>

<template>
  <div class="merge-detail">
    <div v-if="!mergeContext" class="merge-state-warn">
      <strong>Merge context unavailable.</strong>
      Could not load both persons from the proposal payload — the review item
      may be malformed or one of the referenced persons has been deleted.
    </div>

    <template v-else>
      <div v-if="mergeContext.warning" class="merge-state-warn">
        <strong>Warning:</strong> {{ mergeContext.warning }}
      </div>

      <div class="merge-header">
        <h3 class="merge-title">{{ context.item.title || 'Proposed merge' }}</h3>
        <div class="merge-meta">
          <span v-if="context.item.confidence !== null" class="meta-pill" :class="confidenceClass">
            {{ Math.round(context.item.confidence * 100) }}%
          </span>
          <span class="meta-pill meta-agent">{{ context.item.agent_id }}</span>
          <span class="meta-pill meta-merge">merge</span>
        </div>
      </div>

      <!-- Side-by-side person columns -->
      <div class="merge-grid">
        <section class="merge-col">
          <div class="col-heading">Person A reference</div>
          <PersonSnapshotCard :person="mergeContext.persons[0]" />
        </section>
        <section class="merge-col">
          <div class="col-heading">Person B reference</div>
          <PersonSnapshotCard :person="mergeContext.persons[1]" />
        </section>
      </div>

      <!-- Field-by-field diff -->
      <section v-if="mergeContext.field_diffs.length" class="diff-section">
        <div class="diff-heading">
          <div class="col-label">Field</div>
          <div class="col-label">Person A</div>
          <div class="col-label">Person B</div>
          <div class="col-label col-status">Status</div>
        </div>
        <FieldCompareRow
          v-for="(diff, idx) in mergeContext.field_diffs"
          :key="idx"
          :diff="diff"
        />
      </section>

      <!-- Downstream impact -->
      <section class="merge-impact">
        <div class="impact-heading">Downstream impact (counts)</div>
        <table class="impact-table">
          <thead>
            <tr><th>Attached</th><th>Person A</th><th>Person B</th><th>After merge</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in impactRows" :key="row.label">
              <th>{{ row.label }}</th>
              <td>{{ row.a }}</td>
              <td>{{ row.b }}</td>
              <td><strong>{{ row.a + row.b }}</strong></td>
            </tr>
          </tbody>
        </table>
        <div class="impact-note">
          Merge will move every attached row from one person to the other, then mark the donor as merged.
          Operator picks the surviving person via approve/reject below.
        </div>
      </section>

      <AgentReasoningPanel :reasoning="context.agent_reasoning || {}" />
    </template>

    <!-- Action footer -->
    <div class="detail-actions">
      <button
        type="button"
        class="ops-btn ops-btn-green"
        :disabled="actioning"
        @click="$emit('approve', context.item.unified_id)"
      >Approve merge</button>
      <button
        type="button"
        class="ops-btn ops-btn-red"
        :disabled="actioning"
        @click="$emit('reject', context.item.unified_id)"
      >Reject merge</button>
      <button
        type="button"
        class="ops-btn ops-btn-plum"
        :disabled="actioning"
        @click="$emit('close')"
      >Close detail</button>
      <span v-if="actioning" class="text-xs text-ops-text-muted ml-2">working…</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import PersonSnapshotCard from './PersonSnapshotCard.vue'
import FieldCompareRow from './FieldCompareRow.vue'
import AgentReasoningPanel from './AgentReasoningPanel.vue'

const props = defineProps({
  context: { type: Object, required: true },
  actioning: { type: Boolean, default: false },
})

defineEmits(['approve', 'reject', 'applied', 'close'])

const mergeContext = computed(() => props.context?.merge_context ?? null)

const impactRows = computed(() => {
  const i = mergeContext.value?.impact ?? {}
  return [
    { label: 'Sources / media',     a: i.sources?.[0] ?? 0,            b: i.sources?.[1] ?? 0 },
    { label: 'Families as spouse',  a: i.families_as_spouse?.[0] ?? 0, b: i.families_as_spouse?.[1] ?? 0 },
    { label: 'Children',            a: i.children?.[0] ?? 0,           b: i.children?.[1] ?? 0 },
    { label: 'Events',              a: i.events?.[0] ?? 0,             b: i.events?.[1] ?? 0 },
  ]
})

const confidenceClass = computed(() => {
  const c = props.context?.item?.confidence
  if (c === null || c === undefined) return ''
  if (c >= 0.8) return 'conf-high'
  if (c >= 0.5) return 'conf-med'
  return 'conf-low'
})
</script>

<style scoped>
.merge-detail { display: flex; flex-direction: column; gap: 1rem; }
.merge-state-warn {
  background: rgba(204, 136, 0, 0.12);
  border: 1px solid rgba(204, 136, 0, 0.40);
  border-left: 4px solid #cc8800;
  padding: 0.6rem 0.75rem;
  border-radius: 0.375rem;
  color: #ffd980;
  font-size: 0.85rem;
}
.merge-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.merge-title { font-size: 1.1rem; font-weight: 700; color: #ffb47a; margin: 0; }
.merge-meta { display: flex; gap: 0.4rem; align-items: center; }
.meta-pill {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.15rem 0.5rem;
  border-radius: 0.25rem;
}
.meta-agent { background: rgba(99, 51, 153, 0.30); color: #d4c2f0; }
.meta-merge { background: rgba(204, 136, 0, 0.30); color: #ffd980; }
.conf-high  { background: rgba(0, 170, 0, 0.30);    color: #b5f5b5; }
.conf-med   { background: rgba(204, 136, 0, 0.30);  color: #ffd980; }
.conf-low   { background: rgba(204, 0, 0, 0.30);    color: #ffb5b5; }

.merge-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.75rem;
}
@media (min-width: 768px) {
  .merge-grid { grid-template-columns: 1fr 1fr; }
}
.merge-col { display: flex; flex-direction: column; gap: 0.5rem; }
.col-heading {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}

.diff-section {
  background: rgba(0, 0, 0, 0.20);
  border: 1px solid rgba(102, 102, 102, 0.30);
  border-radius: 0.5rem;
  overflow: hidden;
}
.diff-heading {
  display: grid;
  grid-template-columns: 8rem 1fr 1.5fr auto;
  gap: 0.75rem;
  padding: 0.4rem 0.5rem;
  background: rgba(99, 51, 153, 0.20);
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.col-status { text-align: right; }

.merge-impact {
  background: rgba(99, 51, 153, 0.10);
  border: 1px solid rgba(99, 51, 153, 0.30);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
}
.impact-heading {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
  margin-bottom: 0.5rem;
}
.impact-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
  color: #f0e6ff;
}
.impact-table th, .impact-table td {
  padding: 0.3rem 0.5rem;
  text-align: left;
  border-bottom: 1px solid rgba(102, 102, 102, 0.20);
}
.impact-table thead th {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.impact-note {
  margin-top: 0.5rem;
  font-size: 0.7rem;
  color: #b39ddb;
  font-style: italic;
}
.detail-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
</style>

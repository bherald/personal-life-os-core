<template>
  <div class="person-snapshot">
    <div v-if="!person" class="snapshot-empty">
      <div class="text-sm text-ops-text-muted italic">No on-file person attached to this review item.</div>
    </div>
    <template v-else>
      <div class="snapshot-header">
        <div class="snapshot-name">{{ displayName }}</div>
        <div v-if="person.tree_name" class="snapshot-tree">{{ person.tree_name }}</div>
      </div>

      <dl class="snapshot-facts">
        <div v-if="person.sex" class="snapshot-fact">
          <dt>Sex</dt>
          <dd>{{ person.sex }}</dd>
        </div>
        <div class="snapshot-fact">
          <dt>Born</dt>
          <dd>
            <span v-if="person.birth_date">{{ person.birth_date }}</span>
            <span v-else class="muted">unknown</span>
            <span v-if="person.birth_place" class="snapshot-place">· {{ person.birth_place }}</span>
          </dd>
        </div>
        <div class="snapshot-fact">
          <dt>Died</dt>
          <dd>
            <span v-if="person.death_date">{{ person.death_date }}</span>
            <span v-else-if="person.living === 0" class="muted">unknown</span>
            <span v-else class="muted">— living or unknown</span>
            <span v-if="person.death_place" class="snapshot-place">· {{ person.death_place }}</span>
          </dd>
        </div>

        <div v-if="parentNames" class="snapshot-fact">
          <dt>Parents</dt>
          <dd>{{ parentNames }}</dd>
        </div>

        <div v-if="spouseSummary" class="snapshot-fact">
          <dt>Spouse{{ spouseCount > 1 ? 's' : '' }}</dt>
          <dd>{{ spouseSummary }}</dd>
        </div>

        <div v-if="childCount > 0" class="snapshot-fact">
          <dt>Children</dt>
          <dd>{{ childCount }} on file</dd>
        </div>

        <div class="snapshot-fact">
          <dt>Sources</dt>
          <dd>{{ mediaCount }} attached</dd>
        </div>

        <div v-if="eventCount > 0" class="snapshot-fact">
          <dt>Events</dt>
          <dd>{{ eventCount }}</dd>
        </div>
      </dl>

      <div class="snapshot-footer">
        <a :href="`/genealogy?person=${person.id}`" target="_blank" class="snapshot-link">
          View full record →
        </a>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  person: { type: Object, default: null },
})

const displayName = computed(() => {
  if (!props.person) return ''
  const parts = [props.person.given_name, props.person.surname].filter(Boolean)
  const name = parts.join(' ').trim()
  return name || 'Person reference'
})

const parentNames = computed(() => {
  const fac = props.person?.family_as_child
  if (!fac) return null
  const names = [fac.father_name, fac.mother_name].filter(Boolean)
  return names.length ? names.join(' / ') : null
})

const spouseList = computed(() => {
  const families = props.person?.families_as_spouse
  if (!Array.isArray(families)) return []
  return families.map((f) => f.spouse_name).filter(Boolean)
})

const spouseCount = computed(() => spouseList.value.length)
const spouseSummary = computed(() => {
  if (!spouseCount.value) return null
  return spouseList.value.slice(0, 3).join(', ') + (spouseCount.value > 3 ? ` (+${spouseCount.value - 3})` : '')
})

const childCount = computed(() => {
  const families = props.person?.families_as_spouse
  if (!Array.isArray(families)) return 0
  return families.reduce((sum, f) => sum + (Array.isArray(f.children) ? f.children.length : 0), 0)
})

const mediaCount = computed(() => Array.isArray(props.person?.media) ? props.person.media.length : 0)
const eventCount = computed(() => Array.isArray(props.person?.events) ? props.person.events.length : 0)
</script>

<style scoped>
.person-snapshot {
  background: rgba(99, 51, 153, 0.10);
  border: 1px solid rgba(99, 51, 153, 0.30);
  border-radius: 0.5rem;
  padding: 1rem;
}
.snapshot-empty {
  padding: 1rem;
  text-align: center;
}
.snapshot-header {
  border-bottom: 1px solid rgba(99, 51, 153, 0.30);
  padding-bottom: 0.5rem;
  margin-bottom: 0.75rem;
}
.snapshot-name {
  font-size: 1rem;
  font-weight: 700;
  color: #ffb47a;
}
.snapshot-tree {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
}
.snapshot-facts { display: grid; gap: 0.4rem; margin: 0; }
.snapshot-fact { display: grid; grid-template-columns: 5rem 1fr; gap: 0.5rem; align-items: baseline; }
.snapshot-fact dt {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
}
.snapshot-fact dd {
  font-size: 0.85rem;
  color: #f0e6ff;
  margin: 0;
}
.snapshot-place { color: #b39ddb; font-size: 0.75rem; margin-left: 0.25rem; }
.muted { color: #888; font-style: italic; }
.snapshot-footer {
  margin-top: 0.75rem;
  padding-top: 0.5rem;
  border-top: 1px solid rgba(99, 51, 153, 0.30);
}
.snapshot-link {
  font-size: 0.75rem;
  color: #5da9ff;
  text-decoration: none;
}
.snapshot-link:hover { text-decoration: underline; }
</style>

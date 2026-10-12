<template>
  <section v-if="overlap.length" class="fan-overlap">
    <div class="fan-heading">
      <span class="fan-icon" aria-hidden="true">○</span>
      FAN cluster — {{ overlap.length }} overlap{{ overlap.length === 1 ? '' : 's' }} with this tree
    </div>
    <ul class="fan-list">
      <li v-for="(m, i) in overlap" :key="`${m.matched_person_id}-${i}`" class="fan-item">
        <a :href="`/genealogy?tree_id=${m.tree_id}&person=${m.matched_person_id}`" target="_blank" class="fan-link">
          {{ m.matched_person_name }}
        </a>
        <span v-if="m.name && m.name.toLowerCase() !== m.matched_person_name.toLowerCase()" class="fan-mention">
          (mentioned as “{{ m.name }}”)
        </span>
      </li>
    </ul>
    <div class="fan-note">
      Heuristic match — proposed evidence names a tree member. Strong identity confirmation per Mills' FAN principle.
    </div>
  </section>
</template>

<script setup>
defineProps({
  overlap: { type: Array, default: () => [] },
})
</script>

<style scoped>
.fan-overlap {
  background: var(--color-bg-tertiary);
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
}
.fan-heading {
  font-size: 0.7rem;
  text-transform: none;
  letter-spacing: normal;
  color: var(--color-text-primary);
  font-weight: 600;
  margin-bottom: 0.5rem;
}
.fan-icon { color: var(--color-accent); margin-right: 0.4rem; }
.fan-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; }
.fan-item { font-size: 0.85rem; color: var(--color-text-primary); }
.fan-link { color: var(--color-accent); text-decoration: none; font-weight: 600; }
.fan-link:hover { text-decoration: underline; }
.fan-mention { color: var(--color-accent); font-size: 0.75rem; margin-left: 0.5rem; }
.fan-note { margin-top: 0.5rem; font-size: 0.7rem; color: var(--color-accent); font-style: italic; }
</style>

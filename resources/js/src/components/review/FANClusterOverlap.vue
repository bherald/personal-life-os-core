<template>
  <section v-if="overlap.length" class="fan-overlap">
    <div class="fan-heading">
      <span class="fan-icon" aria-hidden="true">○</span>
      FAN cluster — {{ overlap.length }} overlap{{ overlap.length === 1 ? '' : 's' }} with this tree
    </div>
    <ul class="fan-list">
      <li v-for="(m, i) in overlap" :key="`${m.matched_person_id}-${i}`" class="fan-item">
        <a :href="`/genealogy?person=${m.matched_person_id}`" target="_blank" class="fan-link">
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
  background: rgba(99, 179, 237, 0.08);
  border: 1px solid rgba(99, 179, 237, 0.30);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
}
.fan-heading {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #bfe1ff;
  font-weight: 600;
  margin-bottom: 0.5rem;
}
.fan-icon { color: #5da9ff; margin-right: 0.4rem; }
.fan-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; }
.fan-item { font-size: 0.85rem; color: #f0e6ff; }
.fan-link { color: #5da9ff; text-decoration: none; font-weight: 600; }
.fan-link:hover { text-decoration: underline; }
.fan-mention { color: #b39ddb; font-size: 0.75rem; margin-left: 0.5rem; }
.fan-note { margin-top: 0.5rem; font-size: 0.7rem; color: #b39ddb; font-style: italic; }
</style>

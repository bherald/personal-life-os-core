<template>
  <section class="reasoning-panel">
    <button type="button" class="reasoning-toggle" @click="open = !open">
      <span>Agent reasoning · drivers · search coverage</span>
      <span class="reasoning-caret">{{ open ? '▾' : '▸' }}</span>
    </button>

    <Transition name="fade">
      <div v-if="open" class="reasoning-body">
        <div v-if="reasoning.narrative" class="reasoning-section">
          <div class="reasoning-heading">Narrative</div>
          <div class="reasoning-text">{{ reasoning.narrative }}</div>
        </div>
        <div v-else class="reasoning-section muted">
          No agent narrative available — proposal payload did not include explicit reasoning or filter counts.
        </div>

        <div v-if="reasoning.confidence_drivers?.length" class="reasoning-section">
          <div class="reasoning-heading">Confidence drivers</div>
          <ul class="driver-list">
            <li v-for="d in reasoning.confidence_drivers" :key="d.feature" class="driver-row">
              <span class="driver-feature">{{ d.feature }}</span>
              <span class="driver-bar-wrap">
                <span class="driver-bar" :style="{ width: Math.round((d.weight || 0) * 100) + '%' }"></span>
              </span>
              <span class="driver-weight">{{ Math.round((d.weight || 0) * 100) }}</span>
              <span class="driver-note">{{ d.note }}</span>
            </li>
          </ul>
        </div>

        <div class="reasoning-section">
          <div class="reasoning-heading">Search coverage</div>
          <div class="coverage-line">
            <span class="coverage-stat">{{ reasoning.search_coverage?.episode_count ?? 0 }} episodes</span>
            <span class="coverage-window">in last {{ reasoning.search_coverage?.window_hours ?? 24 }}h</span>
          </div>
          <div v-if="reasoning.search_coverage?.repositories_consulted?.length" class="coverage-repos">
            <span v-for="r in reasoning.search_coverage.repositories_consulted" :key="r" class="repo-pill">{{ r }}</span>
          </div>
          <div v-else class="reasoning-text muted">
            No tool/repository signals captured this window.
          </div>
        </div>
      </div>
    </Transition>
  </section>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  reasoning: { type: Object, default: () => ({}) },
})

const open = ref(false)
</script>

<style scoped>
.reasoning-panel {
  background: rgba(0, 0, 0, 0.20);
  border: 1px solid rgba(102, 102, 102, 0.30);
  border-radius: 0.5rem;
  overflow: hidden;
}
.reasoning-toggle {
  width: 100%;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem 0.75rem;
  background: rgba(99, 51, 153, 0.20);
  color: #d4c2f0;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
  border: none;
  cursor: pointer;
}
.reasoning-toggle:hover { background: rgba(99, 51, 153, 0.30); }
.reasoning-caret { font-size: 0.85rem; }
.reasoning-body { padding: 0.75rem; display: flex; flex-direction: column; gap: 0.75rem; }
.reasoning-section.muted { color: #888; font-style: italic; font-size: 0.8rem; }
.reasoning-heading {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #b39ddb;
  font-weight: 600;
  margin-bottom: 0.25rem;
}
.reasoning-text { font-size: 0.85rem; color: #f0e6ff; line-height: 1.4; }
.reasoning-text.muted { color: #888; font-style: italic; }
.driver-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; }
.driver-row {
  display: grid;
  grid-template-columns: 8rem 6rem 2.5rem 1fr;
  gap: 0.5rem;
  align-items: center;
  font-size: 0.75rem;
}
.driver-feature { color: #ffe5b3; font-weight: 600; }
.driver-bar-wrap {
  height: 0.5rem;
  background: rgba(99, 51, 153, 0.20);
  border-radius: 0.25rem;
  overflow: hidden;
}
.driver-bar {
  display: block;
  height: 100%;
  background: linear-gradient(90deg, #5da9ff, #b39ddb);
}
.driver-weight { font-size: 0.7rem; color: #b39ddb; text-align: right; }
.driver-note { color: #d4c2f0; font-size: 0.7rem; }
.coverage-line { display: flex; gap: 0.5rem; align-items: baseline; font-size: 0.85rem; color: #f0e6ff; }
.coverage-window { font-size: 0.7rem; color: #b39ddb; }
.coverage-repos { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.5rem; }
.repo-pill {
  display: inline-block;
  font-size: 0.7rem;
  background: rgba(99, 179, 237, 0.20);
  color: #bfe1ff;
  padding: 0.1rem 0.5rem;
  border-radius: 0.25rem;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>

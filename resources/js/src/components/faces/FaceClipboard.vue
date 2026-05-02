<template>
  <transition name="slide-up">
    <div v-if="selectedCount > 0" class="fixed bottom-4 left-1/2 -translate-x-1/2 z-40 bg-ops-plum border-t-2 border-ops-orange rounded-lg px-6 py-3 flex items-center gap-4 shadow-lg">
      <span class="text-ops-text font-semibold text-sm">
        {{ selectedCount }} selected
      </span>

      <div class="h-6 w-px bg-ops-orange/30"></div>

      <!-- Cluster-mode actions -->
      <template v-if="isClusterTab">
        <button @click="$emit('identify-all')" class="px-3 py-1.5 text-sm bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange">
          Identify All
        </button>

        <button v-if="selectedCount >= 2" @click="$emit('merge-all')" class="px-3 py-1.5 text-sm bg-ops-gold/80 text-black rounded font-semibold hover:bg-ops-gold">
          Merge
        </button>

        <button @click="$emit('hide-all')" class="px-3 py-1.5 text-sm bg-ops-plum/80 text-ops-text rounded border border-ops-orange/40 hover:bg-ops-plum">
          Hide All
        </button>
      </template>

      <!-- Face-mode actions -->
      <template v-else>
        <button @click="$emit('name-all')" class="px-3 py-1.5 text-sm bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange">
          Name All
        </button>

        <button @click="$emit('hide-all')" class="px-3 py-1.5 text-sm bg-ops-plum/80 text-ops-text rounded border border-ops-orange/40 hover:bg-ops-plum">
          Hide All
        </button>
      </template>

      <button @click="$emit('clear')" class="ml-2 text-ops-text-muted hover:text-ops-text">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>
  </transition>
</template>

<script setup>
defineProps({
  selectedCount: { type: Number, default: 0 },
  isClusterTab: { type: Boolean, default: false },
})

defineEmits(['name-all', 'hide-all', 'identify-all', 'merge-all', 'clear'])
</script>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.2s ease, opacity 0.2s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  transform: translate(-50%, 100%);
  opacity: 0;
}
</style>

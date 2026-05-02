<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" @click.self="$emit('close')">
    <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-md mx-4 p-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-ops-peach text-lg font-bold">Keyboard Shortcuts</h3>
        <button @click="$emit('close')" class="text-ops-text-muted hover:text-ops-text">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
        </button>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1.5">
        <div
          v-for="shortcut in shortcuts"
          :key="shortcut.key"
          class="flex items-center gap-3 py-1"
        >
          <kbd class="px-2 py-1 bg-ops-plum/40 border border-ops-plum/60 rounded text-xs font-mono min-w-[2.5rem] text-center text-ops-text">
            {{ shortcut.key }}
          </kbd>
          <span class="text-sm text-ops-text-muted">{{ shortcut.label }}</span>
        </div>
      </div>

      <div class="mt-4 pt-3 border-t border-ops-plum/30 text-center">
        <span class="text-xs text-ops-text-muted">Press <kbd class="px-1 border border-ops-plum/40 rounded text-[10px]">?</kbd> to toggle this overlay</span>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  visible: Boolean,
  shortcuts: { type: Array, default: () => [] },
})

defineEmits(['close'])
</script>

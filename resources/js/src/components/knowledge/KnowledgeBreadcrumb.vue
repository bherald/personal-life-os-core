<template>
  <nav class="knowledge-breadcrumb flex items-center gap-1 text-sm py-2 px-1 overflow-x-auto">
    <!-- Home/root button -->
    <button
      @click="$emit('navigate', null)"
      class="flex-shrink-0 text-ops-text-muted hover:text-ops-gold transition-colors"
      title="Back to search"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4"/>
      </svg>
    </button>

    <template v-for="(crumb, i) in breadcrumb" :key="crumb.path">
      <svg class="w-3 h-3 flex-shrink-0 text-ops-plum" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
      </svg>

      <button
        v-if="i < breadcrumb.length - 1"
        @click="$emit('navigate', crumb.path)"
        class="flex-shrink-0 text-ops-text-muted hover:text-ops-gold transition-colors whitespace-nowrap"
      >
        {{ crumb.name }}
      </button>
      <span v-else class="flex-shrink-0 text-ops-peach font-semibold whitespace-nowrap">
        {{ crumb.name }}
      </span>
    </template>

    <!-- File/folder count -->
    <span v-if="totalFiles != null" class="ml-auto flex-shrink-0 text-xs text-ops-text-muted/60">
      {{ totalFiles }} file{{ totalFiles !== 1 ? 's' : '' }}
    </span>
  </nav>
</template>

<script setup>
defineProps({
  breadcrumb: { type: Array, default: () => [] },
  totalFiles: { type: Number, default: null }
})

defineEmits(['navigate'])
</script>

<template>
  <div class="knowledge-source-tabs">
    <div class="flex items-center gap-1 overflow-x-auto pb-1">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        @click="$emit('update:modelValue', tab.value)"
        class="ops-nav-btn px-4 py-2 text-sm font-semibold uppercase tracking-wider whitespace-nowrap rounded-r-full transition-all"
        :class="modelValue === tab.value
          ? 'bg-ops-gold text-black'
          : 'bg-ops-plum/30 text-ops-text-muted hover:bg-ops-plum/50 hover:text-ops-peach'"
      >
        {{ tab.label }}
        <span v-if="tab.count !== undefined" class="ml-1 text-xs opacity-70">({{ formatCount(tab.count) }})</span>
      </button>
    </div>

    <!-- Active filter chips -->
    <div v-if="activeFilters.length > 0" class="flex flex-wrap gap-2 mt-3">
      <span
        v-for="filter in activeFilters"
        :key="filter.key"
        class="inline-flex items-center gap-1 px-3 py-1 text-xs font-medium rounded-full bg-ops-violet/30 text-ops-lilac border border-ops-violet"
      >
        {{ filter.label }}
        <button @click="$emit('remove-filter', filter.key)" class="hover:text-ops-alert ml-1">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  modelValue: { type: String, default: 'all' },
  tabs: {
    type: Array,
    default: () => [
      { label: 'All', value: 'all' },
      { label: 'Files', value: 'files' },
      { label: 'Media', value: 'media' },
      { label: 'Notes', value: 'notes' },
      { label: 'Transcripts', value: 'transcripts' },
      { label: 'Research', value: 'research' },
    ]
  },
  activeFilters: { type: Array, default: () => [] }
})

defineEmits(['update:modelValue', 'remove-filter'])

function formatCount(n) {
  if (n >= 1000) return (n / 1000).toFixed(1) + 'k'
  return n
}
</script>

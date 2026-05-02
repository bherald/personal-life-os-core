<template>
  <div class="knowledge-facet-panel w-48 lg:w-56 flex-shrink-0 border-r-2 border-ops-plum overflow-y-auto bg-black/50 hidden md:block">

    <!-- Folder tree browser -->
    <div v-if="showFolderTree" class="border-b border-ops-plum/50">
      <button @click="folderTreeOpen = !folderTreeOpen"
        class="w-full flex items-center justify-between px-3 lg:px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-ops-lilac hover:bg-ops-plum/20 transition-colors">
        <span>Folders</span>
        <svg class="w-3.5 h-3.5 transition-transform" :class="folderTreeOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>
      <div v-show="folderTreeOpen" class="px-2 lg:px-3 pb-3">
        <button v-if="activeFilters.folder"
          @click="$emit('filter', { key: 'folder', value: null })"
          class="w-full text-left px-2 py-1 mb-1 text-xs text-ops-gold hover:bg-ops-plum/30 rounded">
          Clear folder filter
        </button>
        <slot name="folder-tree"></slot>
      </div>
    </div>

    <!-- Notebook tree browser -->
    <div v-if="showNotebookTree" class="border-b border-ops-plum/50">
      <button @click="notebookTreeOpen = !notebookTreeOpen"
        class="w-full flex items-center justify-between px-3 lg:px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-ops-lilac hover:bg-ops-plum/20 transition-colors">
        <span>Notebooks</span>
        <svg class="w-3.5 h-3.5 transition-transform" :class="notebookTreeOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>
      <div v-show="notebookTreeOpen" class="px-2 lg:px-3 pb-3">
        <button v-if="activeFilters.notebook"
          @click="$emit('filter', { key: 'notebook', value: null })"
          class="w-full text-left px-2 py-1 mb-1 text-xs text-ops-sky hover:bg-ops-plum/30 rounded">
          Clear notebook filter
        </button>
        <slot name="notebook-tree"></slot>
      </div>
    </div>

    <!-- Type facets -->
    <div v-if="facets.types && Object.keys(facets.types).length > 0" class="border-b border-ops-plum/50">
      <button @click="typeOpen = !typeOpen"
        class="w-full flex items-center justify-between px-3 lg:px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-ops-lilac hover:bg-ops-plum/20 transition-colors">
        <span>Type</span>
        <svg class="w-3.5 h-3.5 transition-transform" :class="typeOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>
      <div v-show="typeOpen" class="px-2 lg:px-3 pb-3 space-y-0.5">
        <button
          v-for="(count, type) in facets.types"
          :key="type"
          @click="toggleFilter('type', type)"
          class="w-full flex items-center justify-between px-2 py-1.5 rounded-r-full text-sm transition-colors"
          :class="isActive('type', type)
            ? 'bg-ops-gold/20 text-ops-gold border border-ops-gold/40'
            : 'text-ops-text-muted hover:bg-ops-plum/30 hover:text-ops-peach'"
        >
          <span class="capitalize">{{ formatType(type) }}</span>
          <span class="text-xs opacity-60">{{ count }}</span>
        </button>
      </div>
    </div>

    <!-- Year histogram -->
    <div v-if="facets.years && Object.keys(facets.years).length > 0" class="border-b border-ops-plum/50">
      <button @click="yearOpen = !yearOpen"
        class="w-full flex items-center justify-between px-3 lg:px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-ops-lilac hover:bg-ops-plum/20 transition-colors">
        <span>Year ({{ Object.keys(facets.years).length }})</span>
        <svg class="w-3.5 h-3.5 transition-transform" :class="yearOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>
      <div v-show="yearOpen" class="px-2 lg:px-3 pb-3 space-y-0.5 max-h-64 overflow-y-auto">
        <button
          v-for="(count, year) in sortedYears"
          :key="year"
          @click="toggleFilter('year', year)"
          class="w-full flex items-center justify-between px-2 py-1.5 rounded-r-full text-sm transition-colors"
          :class="isActive('year', year)
            ? 'bg-ops-sky/20 text-ops-sky border border-ops-sky/40'
            : 'text-ops-text-muted hover:bg-ops-plum/30 hover:text-ops-peach'"
        >
          <span>{{ year }}</span>
          <div class="flex items-center gap-2">
            <div class="w-12 h-1.5 bg-ops-plum rounded-full overflow-hidden">
              <div class="h-full bg-ops-sky rounded-full" :style="{ width: barWidth(count) + '%' }"></div>
            </div>
            <span class="text-xs opacity-60 w-8 text-right">{{ count }}</span>
          </div>
        </button>
      </div>
    </div>

    <!-- People filter -->
    <div v-if="facets.people && Object.keys(facets.people).length > 0" class="border-b border-ops-plum/50">
      <button @click="peopleOpen = !peopleOpen"
        class="w-full flex items-center justify-between px-3 lg:px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-ops-lilac hover:bg-ops-plum/20 transition-colors">
        <span>People</span>
        <svg class="w-3.5 h-3.5 transition-transform" :class="peopleOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>
      <div v-show="peopleOpen" class="px-2 lg:px-3 pb-3 space-y-0.5">
        <button
          v-for="(count, person) in facets.people"
          :key="person"
          @click="toggleFilter('person', person)"
          class="w-full flex items-center justify-between px-2 py-1.5 rounded-r-full text-sm transition-colors"
          :class="isActive('person', person)
            ? 'bg-ops-peach/20 text-ops-peach border border-ops-peach/40'
            : 'text-ops-text-muted hover:bg-ops-plum/30 hover:text-ops-peach'"
        >
          <span class="truncate mr-2">{{ person }}</span>
          <span class="text-xs opacity-60">{{ count }}</span>
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="isEmpty" class="text-center py-8 px-3 text-ops-text-muted text-sm">
      Search to see filters
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  facets: { type: Object, default: () => ({ types: {}, years: {}, people: {} }) },
  activeFilters: { type: Object, default: () => ({}) },
  showFolderTree: { type: Boolean, default: false },
  showNotebookTree: { type: Boolean, default: false }
})

const emit = defineEmits(['filter'])

// Accordion state — type open by default, others closed
const folderTreeOpen = ref(false)
const notebookTreeOpen = ref(false)
const typeOpen = ref(true)
const yearOpen = ref(false)
const peopleOpen = ref(false)

const isEmpty = computed(() => {
  const f = props.facets
  return (!f.types || Object.keys(f.types).length === 0)
    && (!f.years || Object.keys(f.years).length === 0)
    && (!f.people || Object.keys(f.people).length === 0)
})

const sortedYears = computed(() => {
  if (!props.facets.years) return {}
  const entries = Object.entries(props.facets.years)
  entries.sort((a, b) => parseInt(b[0]) - parseInt(a[0]))
  return Object.fromEntries(entries)
})

const maxYearCount = computed(() => {
  if (!props.facets.years) return 1
  return Math.max(...Object.values(props.facets.years), 1)
})

function barWidth(count) {
  return Math.max(5, (count / maxYearCount.value) * 100)
}

function isActive(facetKey, value) {
  return props.activeFilters[facetKey] === value
}

function toggleFilter(facetKey, value) {
  if (isActive(facetKey, value)) {
    emit('filter', { key: facetKey, value: null })
  } else {
    emit('filter', { key: facetKey, value })
  }
}

function formatType(type) {
  return type.replace(/^rag_/, '').replace(/_/g, ' ')
}
</script>

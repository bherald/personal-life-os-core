<template>
  <div class="knowledge-search-bar">
    <div class="relative">
      <div class="flex items-center bg-black border-2 rounded-r-full overflow-hidden transition-colors"
           :class="focused ? 'border-ops-gold' : 'border-ops-violet'">
        <div class="pl-4 pr-2 text-ops-gold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
        </div>
        <input
          ref="searchInput"
          v-model="localQuery"
          type="text"
          :placeholder="placeholder"
          class="flex-1 bg-transparent text-ops-peach placeholder-ops-text-muted py-3 px-2 outline-none text-lg font-medium tracking-wide"
          @input="onInput"
          @keydown.enter="emitSearch"
          @keydown.escape="onEscape"
          @focus="focused = true"
          @blur="onBlur"
        />
        <div v-if="localQuery" class="pr-2">
          <button @click="clearSearch" class="text-ops-text-muted hover:text-ops-peach p-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <button @click="emitSearch"
          class="bg-ops-gold text-black px-4 py-1.5 mr-1 rounded-r-full hover:bg-ops-peach font-semibold uppercase text-xs transition-colors">
          Search
        </button>
      </div>

      <!-- Suggestions dropdown -->
      <div v-if="showSuggestions && suggestions.length > 0"
           class="absolute top-full left-0 right-0 mt-1 bg-black border-2 border-ops-violet rounded-r-lg shadow-xl z-50 overflow-hidden">
        <button
          v-for="(suggestion, idx) in suggestions"
          :key="idx"
          class="w-full text-left px-4 py-2 hover:bg-ops-plum/30 flex items-center gap-3 transition-colors"
          :class="idx === highlightedIndex ? 'bg-ops-plum/30' : ''"
          @mousedown.prevent="selectSuggestion(suggestion)"
        >
          <span class="text-xs uppercase text-ops-text-muted w-16">{{ suggestion.type }}</span>
          <span class="text-ops-peach">{{ suggestion.text }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Search files, notes, transcripts, research...' }
})

const emit = defineEmits(['update:modelValue', 'search', 'clear'])

const searchInput = ref(null)
const localQuery = ref(props.modelValue)
const focused = ref(false)
const suggestions = ref([])
const showSuggestions = ref(false)
const highlightedIndex = ref(-1)
let debounceTimer = null

watch(() => props.modelValue, (val) => {
  localQuery.value = val
})

function onInput() {
  emit('update:modelValue', localQuery.value)
  clearTimeout(debounceTimer)

  if (localQuery.value.length >= 2) {
    debounceTimer = setTimeout(loadSuggestions, 200)
  } else {
    suggestions.value = []
    showSuggestions.value = false
  }
}

function emitSearch() {
  showSuggestions.value = false
  emit('search', localQuery.value)
}

function clearSearch() {
  localQuery.value = ''
  emit('update:modelValue', '')
  emit('clear')
  searchInput.value?.focus()
}

function onEscape() {
  if (showSuggestions.value) {
    showSuggestions.value = false
  } else {
    searchInput.value?.blur()
  }
}

function onBlur() {
  focused.value = false
  setTimeout(() => { showSuggestions.value = false }, 200)
}

async function loadSuggestions() {
  try {
    const { data } = await axios.get('/api/search/suggestions', {
      params: { q: localQuery.value, limit: 8 }
    })
    suggestions.value = data.suggestions || []
    showSuggestions.value = suggestions.value.length > 0
    highlightedIndex.value = -1
  } catch {
    suggestions.value = []
  }
}

function selectSuggestion(suggestion) {
  localQuery.value = suggestion.text
  emit('update:modelValue', suggestion.text)
  showSuggestions.value = false
  emit('search', suggestion.text)
}

// Global Ctrl+K shortcut
function onKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault()
    searchInput.value?.focus()
  }
}

function focus() {
  searchInput.value?.focus()
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  clearTimeout(debounceTimer)
})

defineExpose({ focus })
</script>

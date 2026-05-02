<template>
  <div class="h-full flex flex-col bg-black border-r-2 border-ops-plum">
    <!-- Header -->
    <div class="p-4 border-b-2 border-ops-plum">
      <h2 class="text-lg font-semibold text-ops-peach mb-3 uppercase tracking-wider">Joplin Notes</h2>

      <!-- Actions -->
      <div class="flex gap-2">
        <button
          @click="$emit('createNote')"
          class="flex-1 px-3 py-2 bg-ops-orange text-black text-sm rounded-r-full hover:bg-ops-peach transition-colors flex items-center justify-center gap-2 font-semibold uppercase tracking-wide"
          title="New Note"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
          </svg>
          Note
        </button>
        <button
          @click="$emit('createNotebook')"
          class="flex-1 px-3 py-2 bg-ops-lilac text-black text-sm rounded-r-full hover:bg-ops-lavender transition-colors flex items-center justify-center gap-2 font-semibold uppercase tracking-wide"
          title="New Notebook"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"></path>
          </svg>
          Notebook
        </button>
      </div>
    </div>

    <!-- Search -->
    <div class="p-3 border-b-2 border-ops-plum">
      <div class="relative">
        <input
          v-model="searchQuery"
          @input="handleSearch"
          type="text"
          placeholder="Search notes..."
          class="w-full pl-9 pr-3 py-2 text-sm bg-black/50 border-2 border-ops-violet rounded-r-full focus:ring-2 focus:ring-ops-orange focus:border-ops-orange text-ops-text placeholder-ops-gray"
        />
        <svg class="w-4 h-4 absolute left-3 top-2.5 text-ops-violet" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin rounded-full h-8 w-8 border-4 border-ops-orange border-t-transparent mx-auto"></div>
        <p class="text-sm text-ops-text-muted mt-2 uppercase tracking-wider">Loading...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="flex-1 p-4">
      <div class="bg-red-900/30 border-2 border-red-500 rounded p-3">
        <p class="text-sm text-red-300">{{ error }}</p>
        <button
          @click="$emit('refresh')"
          class="mt-2 text-sm text-ops-orange hover:text-ops-peach underline"
        >
          Try again
        </button>
      </div>
    </div>

    <!-- Tree View -->
    <div v-else class="flex-1 overflow-y-auto ops-scroll">
      <!-- Search Results -->
      <div v-if="searchQuery && searchResults.length" class="p-2">
        <div class="text-xs font-semibold text-ops-violet px-2 py-1 uppercase tracking-wider">Search Results</div>
        <button
          v-for="result in searchResults"
          :key="result.id"
          @click="$emit('selectNote', result)"
          :class="[
            'w-full text-left px-3 py-2 rounded-r-lg hover:bg-ops-plum/30 transition-colors',
            selectedNoteId === result.id ? 'bg-ops-orange/20 border-l-4 border-ops-orange' : ''
          ]"
        >
          <div class="text-sm font-medium text-ops-peach truncate">{{ result.title }}</div>
          <div class="text-xs text-ops-text-muted truncate mt-1">{{ result.preview }}</div>
        </button>
      </div>

      <!-- No Search Results -->
      <div v-else-if="searchQuery" class="p-4 text-center text-ops-text-muted text-sm uppercase">
        No results found
      </div>

      <!-- Notebooks and Notes Tree -->
      <div v-else class="p-2 space-y-1">
        <!-- Root Notes (no parent) -->
        <div v-if="rootNotes.length">
          <div class="text-xs font-semibold text-ops-violet px-2 py-1 uppercase tracking-wider">Notes</div>
          <button
            v-for="note in rootNotes"
            :key="note.id"
            @click="$emit('selectNote', note)"
            :class="[
              'w-full text-left px-3 py-2 rounded-r-lg hover:bg-ops-plum/30 transition-colors flex items-start gap-2',
              selectedNoteId === note.id ? 'bg-ops-orange/20 border-l-4 border-ops-orange' : ''
            ]"
          >
            <svg class="w-4 h-4 text-ops-sky mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-ops-peach truncate">{{ note.title }}</div>
              <div class="text-xs text-ops-text-muted">{{ formatDate(note.updated_time) }}</div>
            </div>
          </button>
        </div>

        <!-- Notebooks -->
        <div v-if="notebooks.length">
          <div class="text-xs font-semibold text-ops-violet px-2 py-1 mt-3 uppercase tracking-wider">Notebooks</div>
          <div
            v-for="notebook in notebooks"
            :key="notebook.id"
            class="space-y-1"
          >
            <!-- Notebook Header -->
            <button
              @click="toggleNotebook(notebook.id)"
              class="w-full text-left px-3 py-2 rounded-r-lg hover:bg-ops-plum/30 transition-colors flex items-center gap-2"
            >
              <svg
                class="w-4 h-4 text-ops-lilac transition-transform"
                :class="{ 'rotate-90': expandedNotebooks.includes(notebook.id) }"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
              </svg>
              <svg class="w-4 h-4 text-ops-sky" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
              </svg>
              <div class="flex-1 text-sm font-medium text-ops-gold truncate">
                {{ notebook.title }}
              </div>
              <div class="text-xs text-ops-text-muted">
                {{ getNotebookNoteCount(notebook) }}
              </div>
            </button>

            <!-- Notebook Notes -->
            <div v-if="expandedNotebooks.includes(notebook.id)" class="ml-6 space-y-1">
              <button
                v-for="note in getNotebookNotes(notebook.id)"
                :key="note.id"
                @click="$emit('selectNote', note)"
                :class="[
                  'w-full text-left px-3 py-2 rounded-r-lg hover:bg-ops-plum/30 transition-colors flex items-start gap-2',
                  selectedNoteId === note.id ? 'bg-ops-orange/20 border-l-4 border-ops-orange' : ''
                ]"
              >
                <svg class="w-4 h-4 text-ops-sky mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium text-ops-peach truncate">{{ note.title }}</div>
                  <div class="text-xs text-ops-text-muted">{{ formatDate(note.updated_time) }}</div>
                </div>
              </button>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-if="!rootNotes.length && !notebooks.length" class="p-4 text-center text-ops-text-muted">
          <svg class="w-16 h-16 mx-auto mb-3 text-ops-plum" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
          <p class="text-sm uppercase">No notes yet</p>
          <p class="text-xs mt-1">Create your first note to get started</p>
        </div>
      </div>
    </div>

    <!-- Status Footer -->
    <div v-if="lockStatus || queueStats" class="p-3 border-t-2 border-ops-plum bg-black/50">
      <div class="text-xs space-y-1">
        <div v-if="lockStatus" class="flex items-center justify-between">
          <span class="text-ops-text-muted uppercase">Lock Status:</span>
          <span :class="lockStatus.active ? 'text-amber-400' : 'text-ops-green'">
            {{ lockStatus.active ? 'LOCKED' : 'AVAILABLE' }}
          </span>
        </div>
        <div v-if="queueStats && queueStats.pending > 0" class="flex items-center justify-between">
          <span class="text-ops-text-muted uppercase">Queued:</span>
          <span class="text-ops-orange">{{ queueStats.pending }} pending</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  notes: {
    type: Array,
    default: () => []
  },
  notebooks: {
    type: Array,
    default: () => []
  },
  selectedNoteId: {
    type: String,
    default: null
  },
  loading: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: null
  },
  lockStatus: {
    type: Object,
    default: null
  },
  queueStats: {
    type: Object,
    default: null
  }
});

defineEmits(['selectNote', 'createNote', 'createNotebook', 'search', 'refresh']);

const searchQuery = ref('');
const searchResults = ref([]);
const expandedNotebooks = ref([]);

// Computed: Root notes (notes without parent_id)
const rootNotes = computed(() => {
  return props.notes.filter(note => !note.parent_id);
});

// Get notes for a specific notebook
const getNotebookNotes = (notebookId) => {
  return props.notes.filter(note => note.parent_id === notebookId);
};

// Get count of notes in a notebook (use cached count from API or fallback to local count)
const getNotebookNoteCount = (notebook) => {
  // If API provided note_count, use it; otherwise count locally
  const count = notebook.note_count !== undefined
    ? notebook.note_count
    : getNotebookNotes(notebook.id).length;
  return count === 1 ? '1 note' : `${count} notes`;
};

// Toggle notebook expansion
const toggleNotebook = (notebookId) => {
  const index = expandedNotebooks.value.indexOf(notebookId);
  if (index > -1) {
    expandedNotebooks.value.splice(index, 1);
  } else {
    expandedNotebooks.value.push(notebookId);
  }
};

// Handle search (debounced)
let searchTimeout;
const handleSearch = () => {
  clearTimeout(searchTimeout);
  if (!searchQuery.value) {
    searchResults.value = [];
    return;
  }

  searchTimeout = setTimeout(() => {
    // Simple client-side search for now
    const query = searchQuery.value.toLowerCase();
    searchResults.value = props.notes.filter(note =>
      note.title.toLowerCase().includes(query) ||
      (note.preview && note.preview.toLowerCase().includes(query))
    );
  }, 300);
};

// Format date
const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  const now = new Date();
  const diff = now - date;
  const days = Math.floor(diff / (1000 * 60 * 60 * 24));

  if (days === 0) return 'Today';
  if (days === 1) return 'Yesterday';
  if (days < 7) return `${days} days ago`;
  return date.toLocaleDateString();
};
</script>

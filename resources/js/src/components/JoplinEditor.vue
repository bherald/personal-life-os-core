<template>
  <div class="h-full flex flex-col bg-black">
    <!-- Header with Title -->
    <div class="p-4 border-b-2 border-ops-plum">
      <input
        v-model="localTitle"
        @blur="handleTitleUpdate"
        type="text"
        placeholder="Note title..."
        class="w-full text-2xl font-bold bg-transparent border-none focus:outline-none focus:ring-0 p-0 text-ops-peach placeholder-ops-plum"
      />
      <div class="flex items-center gap-4 mt-2 text-sm text-ops-text-muted">
        <span>{{ formatDate(note?.updated_time) }}</span>
        <span v-if="note?.parent_id" class="flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
          </svg>
          In notebook
        </span>
        <button
          v-if="hasUnsavedChanges"
          @click="$emit('save')"
          class="ml-auto px-3 py-1 bg-ops-orange text-black rounded-full hover:bg-ops-peach transition-colors text-xs font-semibold uppercase"
        >
          Save
        </button>
        <span v-if="saving" class="ml-auto text-xs text-ops-text-muted flex items-center gap-1 uppercase">
          <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-ops-orange"></div>
          Saving...
        </span>
        <span v-else-if="!hasUnsavedChanges && note" class="ml-auto text-xs text-ops-green uppercase">
          Saved
        </span>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="px-4 py-2 border-b-2 border-ops-plum flex items-center gap-2 flex-wrap bg-ops-plum/20">
      <!-- Format Buttons -->
      <div class="flex items-center gap-1">
        <button
          @click="insertMarkdown('**', '**', 'bold text')"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-ops-text"
          title="Bold"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 12h9a4 4 0 014 4 4 4 0 01-4 4H6z"></path>
          </svg>
        </button>
        <button
          @click="insertMarkdown('*', '*', 'italic text')"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-ops-text"
          title="Italic"
        >
          <svg class="w-4 h-4 italic font-serif" fill="currentColor" viewBox="0 0 24 24">
            <text x="6" y="18" font-size="16" font-family="serif" font-style="italic">I</text>
          </svg>
        </button>
        <button
          @click="insertMarkdown('`', '`', 'code')"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors font-mono text-xs text-ops-text"
          title="Inline Code"
        >
          &lt;/&gt;
        </button>
      </div>

      <div class="w-px h-6 bg-ops-plum"></div>

      <!-- Heading Buttons -->
      <div class="flex items-center gap-1">
        <button
          @click="insertHeading(1)"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-xs font-bold text-ops-text"
          title="Heading 1"
        >
          H1
        </button>
        <button
          @click="insertHeading(2)"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-xs font-bold text-ops-text"
          title="Heading 2"
        >
          H2
        </button>
        <button
          @click="insertHeading(3)"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-xs font-bold text-ops-text"
          title="Heading 3"
        >
          H3
        </button>
      </div>

      <div class="w-px h-6 bg-ops-plum"></div>

      <!-- List Buttons -->
      <div class="flex items-center gap-1">
        <button
          @click="insertList('unordered')"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-ops-text"
          title="Bulleted List"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
          </svg>
        </button>
        <button
          @click="insertList('ordered')"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-ops-text"
          title="Numbered List"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
          </svg>
        </button>
        <button
          @click="insertList('checklist')"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-ops-text"
          title="Checklist"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
          </svg>
        </button>
      </div>

      <div class="w-px h-6 bg-ops-plum"></div>

      <!-- Insert Buttons -->
      <div class="flex items-center gap-1">
        <button
          @click="insertLink"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-ops-text"
          title="Insert Link"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
          </svg>
        </button>
        <button
          @click="insertCodeBlock"
          class="p-1.5 hover:bg-ops-plum/50 rounded transition-colors text-ops-text"
          title="Code Block"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
          </svg>
        </button>
      </div>

      <div class="ml-auto flex items-center gap-2">
        <!-- View Toggle -->
        <div class="flex bg-ops-plum/30 rounded-full p-0.5">
          <button
            @click="viewMode = 'edit'"
            :class="[
              'px-3 py-1 text-xs rounded-full transition-colors uppercase font-semibold',
              viewMode === 'edit' ? 'bg-ops-orange text-black' : 'hover:bg-ops-plum/50 text-ops-text'
            ]"
          >
            Edit
          </button>
          <button
            @click="viewMode = 'split'"
            :class="[
              'px-3 py-1 text-xs rounded-full transition-colors uppercase font-semibold',
              viewMode === 'split' ? 'bg-ops-orange text-black' : 'hover:bg-ops-plum/50 text-ops-text'
            ]"
          >
            Split
          </button>
          <button
            @click="viewMode = 'preview'"
            :class="[
              'px-3 py-1 text-xs rounded-full transition-colors uppercase font-semibold',
              viewMode === 'preview' ? 'bg-ops-orange text-black' : 'hover:bg-ops-plum/50 text-ops-text'
            ]"
          >
            Preview
          </button>
        </div>
      </div>
    </div>

    <!-- Editor and Preview Area -->
    <div class="flex-1 flex overflow-hidden">
      <!-- Editor Pane -->
      <div
        v-if="viewMode === 'edit' || viewMode === 'split'"
        :class="[
          'flex-1 flex flex-col overflow-hidden',
          viewMode === 'split' ? 'border-r-2 border-ops-plum' : ''
        ]"
      >
        <textarea
          ref="editorTextarea"
          v-model="localContent"
          @input="handleContentUpdate"
          class="flex-1 w-full p-6 resize-none border-none focus:outline-none focus:ring-0 font-mono text-sm bg-black text-ops-text placeholder-ops-plum"
          placeholder="Start writing..."
        ></textarea>
      </div>

      <!-- Preview Pane -->
      <div
        v-if="viewMode === 'preview' || viewMode === 'split'"
        class="flex-1 overflow-y-auto p-6 ops-prose ops-scroll"
        v-html="renderedContent"
      ></div>
    </div>

    <!-- E17/EA1: Attachments Bar -->
    <div v-if="note && attachments.length > 0" class="px-4 py-3 border-t-2 border-ops-plum bg-ops-green/10">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs text-ops-text-muted flex items-center gap-1 uppercase">
          <svg class="w-4 h-4 text-ops-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
          </svg>
          Attachments:
        </span>
        <a
          v-for="attachment in attachments"
          :key="attachment.id"
          :href="attachment.media_url"
          target="_blank"
          rel="noopener noreferrer"
          @click.stop
          :class="[
            'px-2 py-1 text-xs rounded-full flex items-center gap-1 transition-colors',
            attachment.media_url ? 'bg-ops-green/20 text-ops-green hover:bg-ops-green/30 border border-ops-green/50' : 'bg-ops-plum/20 text-ops-text-muted'
          ]"
          :title="attachment.media_url ? 'Open in Nextcloud' : 'No source URL available'"
        >
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
          </svg>
          {{ attachment.filename }}
          <span v-if="attachment.file_size" class="text-xs opacity-75">({{ formatFileSize(attachment.file_size) }})</span>
        </a>
      </div>
    </div>

    <!-- Tags Bar -->
    <div v-if="note" class="px-4 py-3 border-t-2 border-ops-plum bg-ops-plum/10">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs text-ops-text-muted uppercase">Tags:</span>
        <span
          v-for="tag in tags"
          :key="tag.id"
          class="px-2 py-1 bg-ops-sky/20 text-ops-sky text-xs rounded-full border border-ops-sky/50"
        >
          {{ tag.title }}
        </span>
        <button
          @click="$emit('addTag')"
          class="px-2 py-1 text-xs text-ops-text-muted hover:bg-ops-plum/30 rounded-full transition-colors"
        >
          + Add tag
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import MarkdownIt from 'markdown-it';

const props = defineProps({
  note: {
    type: Object,
    default: null
  },
  tags: {
    type: Array,
    default: () => []
  },
  attachments: {
    type: Array,
    default: () => []
  },
  saving: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['update:title', 'update:content', 'save', 'addTag']);

const localTitle = ref('');
const localContent = ref('');
const viewMode = ref('split'); // 'edit', 'split', 'preview'
const editorTextarea = ref(null);
const hasUnsavedChanges = ref(false);

// Initialize markdown renderer
const md = new MarkdownIt({
  breaks: true,
  linkify: true
});

// Watch for note changes from parent
watch(() => props.note, (newNote) => {
  if (newNote) {
    localTitle.value = newNote.title || '';
    localContent.value = newNote.content || '';
    hasUnsavedChanges.value = false;
  } else {
    localTitle.value = '';
    localContent.value = '';
    hasUnsavedChanges.value = false;
  }
}, { immediate: true });

// Computed: Rendered markdown content
const renderedContent = computed(() => {
  return md.render(localContent.value || '');
});

// Handle title update
const handleTitleUpdate = () => {
  if (localTitle.value !== props.note?.title) {
    hasUnsavedChanges.value = true;
    emit('update:title', localTitle.value);
  }
};

// Handle content update
const handleContentUpdate = () => {
  if (localContent.value !== props.note?.content) {
    hasUnsavedChanges.value = true;
    emit('update:content', localContent.value);
  }
};

// Insert markdown syntax
const insertMarkdown = (before, after, placeholder) => {
  const textarea = editorTextarea.value;
  if (!textarea) return;

  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const selectedText = localContent.value.substring(start, end);
  const textToInsert = selectedText || placeholder;

  const newContent =
    localContent.value.substring(0, start) +
    before + textToInsert + after +
    localContent.value.substring(end);

  localContent.value = newContent;
  hasUnsavedChanges.value = true;
  emit('update:content', newContent);

  nextTick(() => {
    textarea.focus();
    textarea.selectionStart = start + before.length;
    textarea.selectionEnd = start + before.length + textToInsert.length;
  });
};

// Insert heading
const insertHeading = (level) => {
  const textarea = editorTextarea.value;
  if (!textarea) return;

  const start = textarea.selectionStart;
  const lineStart = localContent.value.lastIndexOf('\n', start - 1) + 1;
  const prefix = '#'.repeat(level) + ' ';

  const newContent =
    localContent.value.substring(0, lineStart) +
    prefix +
    localContent.value.substring(lineStart);

  localContent.value = newContent;
  hasUnsavedChanges.value = true;
  emit('update:content', newContent);

  nextTick(() => {
    textarea.focus();
    textarea.selectionStart = lineStart + prefix.length;
    textarea.selectionEnd = lineStart + prefix.length;
  });
};

// Insert list
const insertList = (type) => {
  const textarea = editorTextarea.value;
  if (!textarea) return;

  const start = textarea.selectionStart;
  const lineStart = localContent.value.lastIndexOf('\n', start - 1) + 1;

  let prefix;
  switch (type) {
    case 'ordered':
      prefix = '1. ';
      break;
    case 'checklist':
      prefix = '- [ ] ';
      break;
    default:
      prefix = '- ';
  }

  const newContent =
    localContent.value.substring(0, lineStart) +
    prefix +
    localContent.value.substring(lineStart);

  localContent.value = newContent;
  hasUnsavedChanges.value = true;
  emit('update:content', newContent);

  nextTick(() => {
    textarea.focus();
    textarea.selectionStart = lineStart + prefix.length;
    textarea.selectionEnd = lineStart + prefix.length;
  });
};

// Insert link
const insertLink = () => {
  insertMarkdown('[', '](url)', 'link text');
};

// Insert code block
const insertCodeBlock = () => {
  const textarea = editorTextarea.value;
  if (!textarea) return;

  const start = textarea.selectionStart;
  const codeBlock = '```\ncode here\n```\n';

  const newContent =
    localContent.value.substring(0, start) +
    codeBlock +
    localContent.value.substring(start);

  localContent.value = newContent;
  hasUnsavedChanges.value = true;
  emit('update:content', newContent);

  nextTick(() => {
    textarea.focus();
    textarea.selectionStart = start + 4; // Position after ```\n
    textarea.selectionEnd = start + 13; // Select "code here"
  });
};

// Format date
const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleString();
};

// E17/EA1: Format file size for attachments
const formatFileSize = (bytes) => {
  if (!bytes) return '';
  const units = ['B', 'KB', 'MB', 'GB'];
  let size = bytes;
  let unitIndex = 0;
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }
  return `${size.toFixed(1)} ${units[unitIndex]}`;
};
</script>

<style scoped>
/* Ops Console prose styles for markdown preview */
.ops-prose {
  color: var(--ops-peach, #ff9966);
}

.ops-prose :deep(h1),
.ops-prose :deep(h2),
.ops-prose :deep(h3),
.ops-prose :deep(h4),
.ops-prose :deep(h5),
.ops-prose :deep(h6) {
  color: var(--ops-orange, #ff9900);
  font-weight: 600;
  margin-top: 1.5em;
  margin-bottom: 0.5em;
}

.ops-prose :deep(h1) { font-size: 1.75rem; }
.ops-prose :deep(h2) { font-size: 1.5rem; }
.ops-prose :deep(h3) { font-size: 1.25rem; }

.ops-prose :deep(p) {
  margin-bottom: 1em;
  line-height: 1.7;
}

.ops-prose :deep(a) {
  color: var(--ops-sky, #99ccff);
  text-decoration: underline;
}

.ops-prose :deep(a:hover) {
  color: var(--ops-ice, #aaccff);
}

.ops-prose :deep(strong) {
  color: var(--ops-gold, #ffcc99);
  font-weight: 600;
}

.ops-prose :deep(em) {
  color: var(--ops-lilac, #cc99cc);
  font-style: italic;
}

.ops-prose :deep(code) {
  background-color: var(--ops-plum, #774477);
  color: var(--ops-peach, #ff9966);
  padding: 0.125rem 0.375rem;
  border-radius: 0.25rem;
  font-size: 0.875em;
}

.ops-prose :deep(pre) {
  background-color: rgba(119, 68, 119, 0.3);
  border: 2px solid var(--ops-plum, #774477);
  border-radius: 0.5rem;
  padding: 1rem;
  overflow-x: auto;
  margin: 1em 0;
}

.ops-prose :deep(pre code) {
  background-color: transparent;
  padding: 0;
  color: var(--ops-green, #99cc66);
}

.ops-prose :deep(ul),
.ops-prose :deep(ol) {
  margin: 1em 0;
  padding-left: 1.5em;
}

.ops-prose :deep(li) {
  margin-bottom: 0.5em;
}

.ops-prose :deep(blockquote) {
  border-left: 4px solid var(--ops-violet, #9977aa);
  padding-left: 1rem;
  margin: 1em 0;
  color: var(--ops-lilac, #cc99cc);
  font-style: italic;
}

.ops-prose :deep(hr) {
  border: none;
  border-top: 2px solid var(--ops-plum, #774477);
  margin: 2em 0;
}

.ops-prose :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 1em 0;
}

.ops-prose :deep(th),
.ops-prose :deep(td) {
  border: 1px solid var(--ops-plum, #774477);
  padding: 0.5rem;
  text-align: left;
}

.ops-prose :deep(th) {
  background-color: var(--ops-plum, #774477);
  color: var(--ops-peach, #ff9966);
}

.ops-prose :deep(img) {
  max-width: 100%;
  border-radius: 0.5rem;
  border: 2px solid var(--ops-plum, #774477);
}
</style>

<template>
  <transition name="fade">
    <div v-if="item" class="fixed inset-0 z-40 flex items-center justify-center" @click.self="$emit('close')">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/70"></div>

      <!-- Modal — wider for inline previews -->
      <div class="relative bg-black border-2 border-ops-gold rounded-r-lg shadow-2xl w-full max-h-[90vh] flex flex-col mx-4"
           :class="hasInlinePreview ? 'max-w-4xl' : 'max-w-2xl'">
        <!-- Header -->
        <div class="p-4 border-b-2 border-ops-plum flex items-center justify-between flex-shrink-0">
          <div class="flex-1 min-w-0 mr-3">
            <h3 class="text-lg font-semibold text-ops-gold truncate">{{ item.title || item.filename || 'Preview' }}</h3>
            <p class="text-xs text-ops-text-muted uppercase mt-0.5">{{ item.type }} {{ item.extension ? '• ' + item.extension.toUpperCase() : '' }}</p>
          </div>
          <button @click="$emit('close')" class="text-ops-text-muted hover:text-ops-peach text-2xl flex-shrink-0">&times;</button>
        </div>

        <!-- Content area -->
        <div class="flex-1 overflow-y-auto p-5">
          <!-- Note/Markdown preview -->
          <div v-if="isNote" class="mb-4">
            <div v-if="noteContent" class="prose prose-invert prose-sm max-w-none text-ops-text" v-html="renderedMarkdown"></div>
            <div v-else-if="loadingContent" class="text-center py-8 text-ops-text-muted">Loading note...</div>
            <a v-if="item.source_id" :href="`/joplin?note=${item.source_id}`"
               class="inline-flex items-center gap-2 mt-3 px-3 py-1.5 bg-ops-gold/20 text-ops-gold rounded-r-full text-sm hover:bg-ops-gold/30 transition-colors">
              Edit in Notes
            </a>
          </div>

          <!-- PDF inline viewer -->
          <div v-else-if="isPdf && streamUrl" class="mb-4">
            <iframe
              :src="streamUrl"
              class="w-full rounded-r-lg border-2 border-ops-violet bg-white"
              style="height: 65vh;"
              title="PDF Preview"
            ></iframe>
          </div>

          <!-- Audio inline player -->
          <div v-else-if="isAudioFile && streamUrl" class="mb-4">
            <div class="bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet p-6">
              <div class="text-center mb-4">
                <div class="text-5xl mb-2">🎵</div>
                <div class="text-ops-peach font-medium">{{ item.filename || item.title }}</div>
              </div>
              <audio :src="streamUrl" controls class="w-full" preload="metadata"></audio>
            </div>
          </div>

          <!-- Text/code inline viewer -->
          <div v-else-if="isTextFile" class="mb-4">
            <div v-if="loadingTextContent" class="text-center py-8 text-ops-text-muted">Loading file...</div>
            <div v-else-if="textContent !== null" class="relative">
              <div class="absolute top-2 right-2 flex gap-1 z-10">
                <span class="px-2 py-0.5 bg-ops-plum/80 text-ops-text-muted text-[10px] uppercase rounded">{{ item.extension }}</span>
                <button @click="copyText" class="px-2 py-0.5 bg-ops-plum/80 text-ops-text-muted text-[10px] uppercase rounded hover:bg-ops-sky/30 hover:text-ops-sky transition-colors">
                  {{ copied ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <pre class="bg-ops-plum/10 border-2 border-ops-violet rounded-r-lg p-4 overflow-x-auto text-sm text-ops-text font-mono max-h-[60vh] overflow-y-auto leading-relaxed"><code>{{ textContent }}</code></pre>
            </div>
            <div v-else class="mb-4 p-6 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet text-center">
              <div class="text-5xl mb-3">{{ typeIcon }}</div>
              <div class="text-ops-text-muted text-sm">Could not load file content</div>
            </div>
          </div>

          <!-- Generic document -->
          <div v-else class="mb-4 p-6 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet text-center">
            <div class="text-5xl mb-3">{{ typeIcon }}</div>
            <div class="text-ops-peach font-medium">{{ item.filename || item.title }}</div>
          </div>

          <!-- Attachments -->
          <div v-if="attachments.length > 0" class="mb-4">
            <h4 class="text-xs font-semibold uppercase tracking-widest text-ops-lilac mb-2">Attachments ({{ attachments.length }})</h4>
            <div class="space-y-1">
              <a
                v-for="att in attachments"
                :key="att.resource_id"
                :href="`/api/media/joplin/${att.resource_id}`"
                target="_blank"
                class="flex items-center gap-2 px-3 py-2 bg-ops-plum/20 border border-ops-plum rounded-r-lg hover:border-ops-sky hover:bg-ops-plum/30 transition-colors group"
              >
                <span class="text-lg flex-shrink-0">{{ attachmentIcon(att) }}</span>
                <div class="flex-1 min-w-0">
                  <div class="text-sm text-ops-peach truncate group-hover:text-ops-gold">{{ displayAttachmentName(att) }}</div>
                  <div v-if="att.file_size" class="text-[10px] text-ops-text-muted/60">{{ formatSize(att.file_size) }}</div>
                </div>
                <svg class="w-4 h-4 text-ops-text-muted flex-shrink-0 group-hover:text-ops-sky" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
              </a>
            </div>
          </div>

          <!-- Snippet (hidden when full note/text content is loaded) -->
          <div v-if="item.snippet && !noteContent && !textContent" class="mb-4 p-3 bg-ops-plum/10 rounded-r-lg border border-ops-violet">
            <p class="text-sm text-ops-text leading-relaxed">{{ cleanSnippet(item.snippet) }}</p>
          </div>

          <!-- Metadata -->
          <div class="space-y-2 text-sm">
            <div v-if="item.date" class="flex justify-between">
              <span class="text-ops-text-muted uppercase text-xs">Date</span>
              <span class="text-ops-peach">{{ formatDate(item.date) }}</span>
            </div>
            <div v-if="item.file_size" class="flex justify-between">
              <span class="text-ops-text-muted uppercase text-xs">Size</span>
              <span class="text-ops-peach">{{ formatSize(item.file_size) }}</span>
            </div>
            <div v-if="item.path" class="flex justify-between">
              <span class="text-ops-text-muted uppercase text-xs">Path</span>
              <span class="text-ops-peach text-xs truncate max-w-[350px]" :title="displayKnowledgePath(item.path)">{{ displayKnowledgePath(item.path) }}</span>
            </div>
            <div v-if="item.people" class="flex justify-between">
              <span class="text-ops-text-muted uppercase text-xs">People</span>
              <span class="text-ops-sky">{{ item.people }}</span>
            </div>
            <div v-if="item.tags" class="flex justify-between">
              <span class="text-ops-text-muted uppercase text-xs">Tags</span>
              <span class="text-ops-lilac text-xs">{{ item.tags }}</span>
            </div>
            <div v-if="item.similarity" class="flex justify-between">
              <span class="text-ops-text-muted uppercase text-xs">Relevance</span>
              <span class="text-ops-green">{{ (item.similarity * 100).toFixed(0) }}%</span>
            </div>
          </div>
        </div>

        <!-- Footer actions -->
        <div class="p-3 border-t-2 border-ops-plum flex-shrink-0 flex gap-2">
          <a v-if="item.preview_url && !item.preview_url.startsWith('/api/')"
             :href="item.preview_url" target="_blank"
             class="flex-1 text-center px-3 py-2 bg-ops-sky/20 text-ops-sky rounded-r-full text-sm hover:bg-ops-sky/30 transition-colors font-semibold uppercase">
            Open Source
          </a>
          <a v-if="streamUrl"
             :href="streamUrl" target="_blank"
             class="flex-1 text-center px-3 py-2 bg-ops-sky/20 text-ops-sky rounded-r-full text-sm hover:bg-ops-sky/30 transition-colors font-semibold uppercase">
            Open in Tab
          </a>
          <button v-if="streamUrl"
                  @click="downloadFile"
                  class="flex-1 text-center px-3 py-2 bg-ops-gold/20 text-ops-gold rounded-r-full text-sm hover:bg-ops-gold/30 transition-colors font-semibold uppercase">
            Download
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const props = defineProps({
  item: { type: Object, default: null }
})

const emit = defineEmits(['close'])

function onKeydown(e) {
  if (e.key === 'Escape' && props.item) emit('close')
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

const noteContent = ref('')
const loadingContent = ref(false)
const attachments = ref([])
const loadingAttachments = ref(false)
const textContent = ref(null)
const loadingTextContent = ref(false)
const copied = ref(false)

const TEXT_EXTENSIONS = new Set([
  'txt', 'md', 'csv', 'log', 'json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'cfg', 'conf',
  'js', 'ts', 'jsx', 'tsx', 'vue', 'css', 'scss', 'less', 'html', 'htm', 'svg',
  'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'swift',
  'sh', 'bash', 'zsh', 'bat', 'ps1', 'sql', 'r', 'lua', 'pl', 'env',
  'gitignore', 'dockerignore', 'editorconfig', 'htaccess', 'makefile',
])
const MAX_TEXT_SIZE = 512 * 1024 // 512 KB max for inline text preview

const isNote = computed(() => ['note', 'transcript'].includes(props.item?.type?.toLowerCase()))
const isPdf = computed(() => {
  const ext = (props.item?.extension || '').toLowerCase()
  return ext === 'pdf' || (props.item?.type?.toLowerCase() === 'document' && ext === 'pdf')
})
const isTextFile = computed(() => {
  if (!props.item?.asset_uuid) return false
  const ext = (props.item?.extension || '').toLowerCase()
  if (TEXT_EXTENSIONS.has(ext)) return true
  if (props.item?.type?.toLowerCase() === 'code') return true
  return false
})
const isAudioFile = computed(() => {
  if (!props.item?.asset_uuid) return false
  const type = (props.item?.type || '').toLowerCase()
  const ext = (props.item?.extension || '').toLowerCase()
  return type === 'audio' || ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'opus'].includes(ext)
})
const hasInlinePreview = computed(() => isPdf.value || isTextFile.value || isAudioFile.value)

const streamUrl = computed(() => {
  if (!props.item?.asset_uuid) return null
  return `/api/media/${props.item.asset_uuid}/stream`
})

const typeIcon = computed(() => {
  const icons = { photo: '🖼️', video: '🎬', audio: '🎵', note: '📝', transcript: '📜', document: '📄', code: '💻', archive: '📦' }
  return icons[props.item?.type?.toLowerCase()] || '📄'
})

const renderedMarkdown = computed(() => {
  let text = noteContent.value
  // Strip Source/Category/Last Synced metadata lines (with or without **bold** markers)
  text = text.replace(/^\*{0,2}Source:?\*{0,2}\s*\{\{WINFILES\}\}.*$/gm, '')
  text = text.replace(/^\*{0,2}Category:?\*{0,2}\s*.+$/gm, '')
  text = text.replace(/^\*{0,2}Last Synced:?\*{0,2}\s*.+$/gm, '')
  // Strip Joplin inline images/resources — attachments section shows these properly
  text = text.replace(/<img\s+[^>]*src=":\/?[a-f0-9]{32}"[^>]*>/gi, '')
  // Strip markdown image refs to Joplin resources: ![alt](:/resourceid)
  text = text.replace(/!\[[^\]]*\]\(:\/[a-f0-9]{32}\)/g, '')
  // Strip &nbsp; entities
  text = text.replace(/&nbsp;/g, ' ')
  // Clean up leftover blank lines from stripping
  text = text.replace(/\n{3,}/g, '\n\n')

  // Convert Joplin resource links [filename](:/resourceid) to clickable links
  // Must happen before HTML escaping — collect replacements with placeholders
  const linkPlaceholders = []
  text = text.replace(/\[([^\]]+)\]\(:\/([a-f0-9]{32})\)/g, (match, filename, resourceId) => {
    const idx = linkPlaceholders.length
    linkPlaceholders.push(
      `<a href="/api/media/joplin/${resourceId}" target="_blank" class="text-ops-sky hover:text-ops-gold underline transition-colors">📎 ${escapeHtml(filename)}</a>`
    )
    return `%%LINK_${idx}%%`
  })

  // Simple markdown rendering
  let html = text
  html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>')
  html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>')
  html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>')
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
  html = html.replace(/\*(.+?)\*/g, '<em>$1</em>')
  html = html.replace(/`(.+?)`/g, '<code class="text-ops-green bg-ops-plum/30 px-1 rounded">$1</code>')
  html = html.replace(/^- (.+)$/gm, '<li>$1</li>')
  html = html.replace(/\n/g, '<br/>')

  // Restore link placeholders
  for (let i = 0; i < linkPlaceholders.length; i++) {
    html = html.replace(`%%LINK_${i}%%`, linkPlaceholders[i])
  }

  return html
})

watch(() => props.item, async (newItem) => {
  noteContent.value = ''
  attachments.value = []
  textContent.value = null
  copied.value = false

  if (!newItem) return

  // Load note content + attachments
  if (isNote.value && newItem.source_id) {
    loadingContent.value = true
    loadingAttachments.value = true
    try {
      const [noteRes, attachRes] = await Promise.all([
        axios.get(`/api/joplin/notes/${newItem.source_id}`),
        axios.get(`/api/joplin/notes/${newItem.source_id}/attachments`).catch(() => ({ data: { data: [] } }))
      ])
      noteContent.value = noteRes.data?.data?.body || noteRes.data?.data?.content || ''
      attachments.value = attachRes.data?.data || []
    } catch {
      noteContent.value = newItem.snippet || 'Could not load note content.'
    } finally {
      loadingContent.value = false
      loadingAttachments.value = false
    }
  }

  // Load text/code file content
  if (isTextFile.value && newItem.asset_uuid) {
    // Skip if file is too large
    if (newItem.file_size && newItem.file_size > MAX_TEXT_SIZE) {
      textContent.value = null
      return
    }
    loadingTextContent.value = true
    try {
      const { data } = await axios.get(`/api/media/${newItem.asset_uuid}/stream`, {
        responseType: 'text',
        headers: { 'Accept': 'text/plain' }
      })
      textContent.value = typeof data === 'string' ? data : describeStructuredContent(data)
    } catch {
      textContent.value = null
    } finally {
      loadingTextContent.value = false
    }
  }
}, { immediate: true })

function downloadFile() {
  if (streamUrl.value) {
    const a = document.createElement('a')
    a.href = streamUrl.value
    a.download = props.item?.filename || 'download'
    a.click()
  }
}

function copyText() {
  if (textContent.value) {
    navigator.clipboard.writeText(textContent.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  }
}

function cleanSnippet(text) {
  if (!text) return ''
  text = text.replace(/<img\s+[^>]*>/gi, '')
  text = text.replace(/&nbsp;/g, ' ')
  text = text.replace(/\s{2,}/g, ' ').trim()
  return text
}

function escapeHtml(value) {
  return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

function displayAttachmentName(att) {
  if (att?.filename && String(att.filename).trim() !== '') return att.filename
  return 'Attachment'
}

function displayKnowledgePath(path) {
  if (!path) return ''
  let value = String(path).replace(/\\/g, '/').replace(/^\/+/, '')
  value = value.replace(/^[A-Za-z]:\//, '')
  value = value.replace(/^(home|users)\/[^/]+\//i, '')
  value = value.replace(/^mnt\/[^/]+\//i, '')
  const parts = value.split('/').filter(Boolean)
  if (parts.length === 0) return 'Configured file location'
  if (parts.length === 1) return parts[0]
  return parts.slice(Math.max(0, parts.length - 3)).join('/')
}

function describeStructuredContent(data) {
  if (Array.isArray(data)) return `Structured response (${data.length} items)`
  if (data && typeof data === 'object') return `Structured response (${Object.keys(data).length} fields)`
  return String(data ?? '')
}

function attachmentIcon(att) {
  const ext = (att.extension || att.filename?.split('.').pop() || '').toLowerCase()
  const map = {
    pdf: '📄', jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️', webp: '🖼️',
    mp3: '🎵', wav: '🎵', mp4: '🎬', mov: '🎬',
    doc: '📝', docx: '📝', xls: '📊', xlsx: '📊', csv: '📊',
    zip: '📦', rar: '📦', '7z': '📦',
  }
  return map[ext] || '📎'
}

function formatDate(date) {
  if (!date) return ''
  try { return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) }
  catch { return '' }
}

function formatSize(bytes) {
  if (!bytes) return ''
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0; let size = bytes
  while (size >= 1024 && i < units.length - 1) { size /= 1024; i++ }
  return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i]
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>

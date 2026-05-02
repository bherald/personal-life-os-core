<template>
  <!-- Badge -->
  <span v-if="field.type === 'badge' && value" class="field-badge" :style="resolvedFieldStyle" :title="badgeTooltip">
    {{ value }}
  </span>

  <!-- Text with optional label -->
  <div v-else-if="field.type === 'text'" class="field-text" :class="{ 'preserve-lines': shouldPreserveLines }" :style="resolvedFieldStyle">
    <span v-if="field.label" class="field-label">{{ field.label }}: </span>
    <span v-if="field.prefix">{{ field.prefix }}</span>
    <span>{{ cleanText(value) || field.fallback || '' }}</span>
  </div>

  <!-- Confidence bar -->
  <div v-else-if="field.type === 'confidence' && value != null" class="field-confidence">
    <div class="confidence-bar">
      <div class="confidence-fill" :style="{ width: confidencePercent + '%' }" :class="confidenceClass"></div>
    </div>
    <span class="confidence-text">{{ confidencePercent }}%</span>
  </div>

  <!-- Timestamp -->
  <div v-else-if="field.type === 'timestamp' && value" class="field-timestamp" :class="{ 'warn': field.warn_if_soon && isExpiringSoon }">
    <span v-if="field.label" class="field-label">{{ field.label }}: </span>
    <span>{{ formatDate(value) }}</span>
    <span v-if="field.warn_if_soon && isExpiringSoon" class="warn-badge">SOON</span>
  </div>

  <!-- External link -->
  <a v-else-if="field.type === 'link' && value" :href="value" target="_blank" rel="noopener" class="field-link" :style="resolvedFieldStyle" @click.stop>
    <span v-if="field.label" class="field-label">{{ field.label }}: </span>
    <span class="link-text">{{ linkText }}</span>
    <svg class="link-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
    </svg>
  </a>

  <!-- Link list -->
  <div v-else-if="field.type === 'link_list' && value?.length" class="field-link-list">
    <span v-if="field.label" class="field-label">{{ field.label }}: </span>
    <div class="links">
      <a v-for="(url, idx) in value.slice(0, 3)" :key="idx" :href="url" target="_blank" rel="noopener" class="link-item" @click.stop>
        {{ truncateUrl(url) }}
      </a>
      <span v-if="value.length > 3" class="more-links">+{{ value.length - 3 }} more</span>
    </div>
  </div>

  <!-- JSON viewer (collapsible) - hidden when empty array/object -->
  <div v-else-if="field.type === 'json' && hasJsonContent" class="field-json" :class="{ 'collapsible': field.collapsible }">
    <button v-if="field.collapsible" @click.stop="jsonExpanded = !jsonExpanded" class="json-toggle">
      <span v-if="field.label">{{ field.label }}</span>
      <svg :class="{ 'rotate-180': jsonExpanded }" class="toggle-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </button>
    <div v-if="field.label && !field.collapsible" class="field-label">{{ field.label }}:</div>
    <pre v-show="!field.collapsible || jsonExpanded" class="json-content" :class="{ 'compact': field.compact }">{{ formatJson(value) }}</pre>
  </div>

  <!-- Diff (current → proposed) -->
  <div v-else-if="field.type === 'diff'" class="field-diff">
    <span v-if="field.label" class="field-label">{{ field.label }}: </span>
    <span v-if="fieldLabel" class="diff-field-name">{{ fieldLabel }}</span>
    <div class="diff-values">
      <span v-if="currentVal" class="diff-current">{{ currentVal }}</span>
      <span v-if="currentVal" class="diff-arrow">&rarr;</span>
      <span class="diff-proposed">{{ proposedVal || '(empty)' }}</span>
    </div>
  </div>

  <!-- Image -->
  <div v-else-if="field.type === 'image' && value" class="field-image" :class="`size-${field.size || 'md'}`" :style="resolvedFieldStyle">
    <img v-if="!imageError" :src="value" @error="imageError = true" />
    <div v-if="imageError" class="image-fallback">
      <svg class="fallback-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
      </svg>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { resolveSchemaClassStyle } from '@/utils/reviewSchemaStyles'

const props = defineProps({
  field: { type: Object, required: true },
  item: { type: Object, required: true }
})

const jsonExpanded = ref(false)
const imageError = ref(false)

const BADGE_TOOLTIPS = {
  // Review types (shown as review_type badge)
  agent: 'General agent finding — approve to acknowledge, reject to dismiss',
  tool_proposal: 'Agent proposes a new tool registration — approve to add to tool registry',
  skill_optimization: 'Agent proposes a SKILL.md amendment — approve to update agent config',
  tool_composition: 'Agent discovered a reusable tool chain — approve to register composition',
  genealogy_finding: 'Per-person research report with embedded proposals — approve applies all changes, reject cascade-rejects all pending proposals for this person',
  research: 'Verified research fact — approve to accept into knowledge base',
  // Change types (shown as change_type badge on change_proposal cards)
  fact_update: 'Updates a field on the person record (name, date, place, occupation, etc.)',
  event_add: 'Adds a life event (census, immigration, baptism, military, etc.)',
  source_add: 'Links person to a source record or appends research URL to notes',
  media_link: 'Links an existing photo/document to this person',
  notes_append: 'Appends a dated research note to the person\'s notes field',
  residence_add: 'Adds a residence record (date + place, typically from census)',
  family_event_update: 'Updates a family record (marriage date/place, divorce date)',
  external_record_link: 'Links person to an external service (WikiTree, FindAGrave, Ancestry, etc.)',
  source_create: 'Creates a new source record (archive, census roll, newspaper, etc.)',
  clipping_link: 'Links person to an existing newspaper clipping',
  media_metadata_update: 'Updates media metadata (title, description, date, transcription)',
  // Relationship types (shown as relationship_type badge on proposal cards)
  spouse: 'Creates a new person and links as spouse — adds family record with marriage data',
  parent: 'Creates a new person and links as parent — updates or creates family record',
  child: 'Creates a new person and links as child — adds to existing or new family',
  sibling: 'Creates a new person and links as sibling — adds to same family as existing person',
  // Face match types (shown as match_type badge on face cards)
  typo: 'Name typo detected — face label doesn\'t exactly match any person (e.g., "Becky" vs "Rebecca")',
  nickname: 'Nickname detected — face labeled with informal name (e.g., "Alex" vs "Alexander")',
  suggested: 'AI-suggested face-to-person match based on clustering similarity',
  unlinked: 'Face detected but not linked to any person — needs manual identification',
}

const badgeTooltip = computed(() => {
  if (props.field.type !== 'badge' || !value.value) return undefined
  const key = String(value.value).toLowerCase().replace(/[\s-]/g, '_')
  return BADGE_TOOLTIPS[key] || undefined
})

const resolvedFieldStyle = computed(() => resolveSchemaClassStyle(props.field.class))

const value = computed(() => {
  if (!props.field.source) return null
  return props.item[props.field.source]
})

const hasJsonContent = computed(() => {
  const v = value.value
  if (!v) return false
  if (Array.isArray(v) && v.length === 0) return false
  if (typeof v === 'object' && Object.keys(v).length === 0) return false
  if (typeof v === 'string') {
    const trimmed = v.trim()
    if (trimmed === '[]' || trimmed === '{}' || trimmed === 'null' || trimmed === '') return false
  }
  return true
})

const shouldPreserveLines = computed(() => {
  if (props.field.type !== 'text') return false
  if (props.field.multiline === true) return true
  return typeof value.value === 'string' && value.value.includes('\n')
})

const confidencePercent = computed(() => {
  if (value.value == null) return 0
  const v = parseFloat(value.value)
  // Handle both 0-1 and 0-100 scales
  return v > 1 ? Math.round(v) : Math.round(v * 100)
})

const linkText = computed(() => {
  if (props.field.type !== 'link' || !value.value) return ''
  if (props.field.text_source && props.item[props.field.text_source]) {
    return String(props.item[props.field.text_source])
  }
  if (props.field.text) {
    return String(props.field.text)
  }
  return truncateUrl(value.value)
})

const confidenceClass = computed(() => {
  const p = confidencePercent.value
  if (p >= 80) return 'high'
  if (p >= 50) return 'medium'
  return 'low'
})

const currentVal = computed(() => {
  if (props.field.type !== 'diff' || !props.field.current) return null
  return props.item[props.field.current] || null
})

const proposedVal = computed(() => {
  if (props.field.type !== 'diff' || !props.field.proposed) return null
  return props.item[props.field.proposed] || null
})

const fieldLabel = computed(() => {
  if (props.field.type !== 'diff' || !props.field.field_label) return null
  return props.item[props.field.field_label] || null
})

const isExpiringSoon = computed(() => {
  if (!value.value) return false
  const expiry = new Date(value.value)
  const now = new Date()
  const hoursUntil = (expiry - now) / (1000 * 60 * 60)
  return hoursUntil > 0 && hoursUntil < 24
})

const formatDate = (dateStr) => {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const truncateUrl = (url) => {
  if (!url) return ''
  try {
    const u = new URL(url)
    const path = u.pathname.length > 30 ? u.pathname.slice(0, 30) + '...' : u.pathname
    return u.hostname + path
  } catch {
    return url.length > 50 ? url.slice(0, 50) + '...' : url
  }
}

const cleanText = (val) => {
  if (!val || typeof val !== 'string') return val
  // Strip matched code fences and their content
  let cleaned = val.replace(/```json?\s*\n[\s\S]*?```/g, '').trim()
  // Strip unclosed code fences (last ``` to end of string — agent phase output leak)
  cleaned = cleaned.replace(/```json?\s*\n[\s\S]*$/, '').trim()
  // Strip markdown headers
  cleaned = cleaned.replace(/^#{1,3}\s+/gm, '')
  // Strip raw inline JSON blobs > 80 chars (global)
  cleaned = cleaned.replace(/[\[{][\s\S]{80,}[\]}]/g, '[...]')
  // Deduplicate repeated paragraphs (e.g. notes repeated 3x from joined rows)
  const paragraphs = cleaned.split(/\n{2,}/)
  const seen = new Set()
  const deduped = paragraphs.filter(p => { const k = p.trim(); if (!k || seen.has(k)) return false; seen.add(k); return true })
  cleaned = deduped.join('\n\n').trim()
  // No truncation — full content shown for human review
  return cleaned || val
}

const formatJson = (val) => {
  if (typeof val === 'string') {
    try { val = JSON.parse(val) } catch { return val }
  }
  return JSON.stringify(val, null, 2)
}
</script>

<style scoped>
.field-badge {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  border-radius: 4px;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  background: var(--ops-plum);
  color: var(--ops-peach);
}

.field-badge[title] {
  cursor: help;
}

.field-text {
  color: inherit;
}

.field-text.preserve-lines {
  white-space: pre-line;
}

.field-label {
  opacity: 0.7;
  font-size: 0.75rem;
}

.field-confidence {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.confidence-bar {
  width: 60px;
  height: 6px;
  background: var(--ops-black);
  border-radius: 3px;
  overflow: hidden;
}

.confidence-fill {
  height: 100%;
  transition: width 0.3s ease;
}

.confidence-fill.high { background: var(--ops-green); }
.confidence-fill.medium { background: var(--ops-gold); }
.confidence-fill.low { background: var(--ops-sunset); }

.confidence-text {
  font-size: 0.75rem;
  font-weight: 600;
  color: inherit;
  opacity: 0.8;
  min-width: 2.5rem;
}

.field-timestamp {
  font-size: 0.75rem;
  opacity: 0.7;
}

.field-timestamp.warn {
  color: var(--ops-sunset);
}

.warn-badge {
  background: var(--ops-sunset);
  color: var(--ops-black);
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
  font-size: 0.625rem;
  font-weight: 700;
  margin-left: 0.5rem;
}

.field-link {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  color: var(--ops-sky);
  font-size: 0.875rem;
  text-decoration: none;
}

.field-link:hover {
  text-decoration: underline;
}

.link-icon {
  width: 0.875rem;
  height: 0.875rem;
  flex-shrink: 0;
}

.field-link-list {
  font-size: 0.75rem;
}

.field-link-list .links {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.link-item {
  color: var(--ops-sky);
  text-decoration: none;
}

.link-item:hover {
  text-decoration: underline;
}

.more-links {
  opacity: 0.7;
}

.field-json {
  font-size: 0.75rem;
}

.json-toggle {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  opacity: 0.7;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
}

.toggle-icon {
  width: 1rem;
  height: 1rem;
  transition: transform 0.2s;
}

.json-content {
  background: var(--ops-black);
  padding: 0.5rem;
  border-radius: 4px;
  overflow-x: auto;
  max-height: 200px;
  overflow-y: auto;
  color: var(--ops-text-muted);
  font-family: monospace;
  font-size: 0.7rem;
  margin: 0.25rem 0 0;
}

.json-content.compact {
  max-height: 80px;
}

.field-image {
  overflow: hidden;
  border-radius: 8px;
  background: var(--ops-black);
}

.field-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.field-image.size-sm { width: 48px; height: 48px; }
.field-image.size-md { width: 80px; height: 80px; }
.field-image.size-lg { width: 120px; height: 120px; }

.image-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.fallback-icon {
  width: 50%;
  height: 50%;
  color: var(--ops-text-muted);
}

.field-diff {
  font-size: 0.85rem;
}

.diff-field-name {
  display: block;
  font-size: 0.7rem;
  text-transform: uppercase;
  opacity: 0.6;
  margin-bottom: 0.25rem;
}

.diff-values {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.diff-current {
  text-decoration: line-through;
  opacity: 0.5;
  color: var(--ops-sunset);
}

.diff-arrow {
  opacity: 0.4;
  font-size: 0.75rem;
}

.diff-proposed {
  color: var(--ops-green);
  font-weight: 600;
}
</style>

<template>
  <section class="legacy-metadata" aria-labelledby="legacy-metadata-title">
    <header class="review-header">
      <div class="min-w-0">
        <div class="review-kind">Inspection only</div>
        <h2 id="legacy-metadata-title">Legacy scan metadata</h2>
        <p>{{ details.filename || item.title || 'Scanned photograph' }}</p>
      </div>
      <button type="button" class="close-button" aria-label="Close metadata inspection" @click="emit('close')">&times;</button>
    </header>

    <div class="evidence-layout">
      <figure class="photo-evidence">
        <img
          v-if="thumbnailUrl && !imageError"
          :src="thumbnailUrl"
          :alt="`Scanned photograph ${details.filename || ''}`"
          @error="imageError = true"
        />
        <div v-else class="photo-unavailable">Photo preview unavailable</div>
        <figcaption>Current catalog image. This inspection does not infer identity or alter the file.</figcaption>
      </figure>

      <section class="inspection-summary" aria-label="Inspection summary">
        <div class="classification" :class="classificationClass">
          <span>Current finding</span>
          <strong>{{ classificationLabel }}</strong>
          <p>{{ classificationExplanation }}</p>
        </div>
        <dl class="catalog-facts">
          <div><dt>Catalog estimate</dt><dd>{{ readableDate(audit.catalog_estimate_date || details.catalog_estimate_date) }}</dd></div>
          <div><dt>Digitized</dt><dd>{{ readableDate(audit.catalog_scan_date || details.catalog_scan_date) }}</dd></div>
          <div><dt>AI evidence</dt><dd>{{ confidenceLabel }}</dd></div>
          <div><dt>File state</dt><dd>{{ fileStateLabel }}</dd></div>
        </dl>
      </section>
    </div>

    <section class="metadata-comparison" aria-labelledby="current-fields-title">
      <div class="section-heading">
        <div>
          <span>Live read-only comparison</span>
          <h3 id="current-fields-title">Current metadata fields</h3>
        </div>
        <span class="state-badge">No write performed</span>
      </div>
      <div class="metadata-grid">
        <div><span>XMP created</span><strong>{{ readableDate(metadata.xmp_created) }}</strong></div>
        <div><span>IPTC created</span><strong>{{ readableDate(metadata.iptc_created) }}</strong></div>
        <div><span>EXIF original</span><strong>{{ readableDate(metadata.exif_original) }}</strong></div>
        <div><span>EXIF digitized</span><strong>{{ readableDate(metadata.exif_digitized) }}</strong></div>
        <div><span>Legacy note</span><strong>{{ metadata.legacy_comment_marker ? 'Present' : 'Not present' }}</strong></div>
        <div><span>Format</span><strong>{{ fileState.extension?.toUpperCase() || 'Unknown' }}</strong></div>
      </div>
    </section>

    <section class="recovery-boundary" aria-labelledby="recovery-title">
      <div class="boundary-copy">
        <span>Recovery boundary</span>
        <h3 id="recovery-title">No correction decision is available</h3>
        <p>The previous writer did not retain the field values that existed before its update. Current tags can be inspected, but removing them would not reconstruct the earlier metadata.</p>
      </div>
      <ul>
        <li>External pre-write version or explicitly approved replacement values</li>
        <li>Full-file backup hash and field-level before snapshot</li>
        <li>Tested restore receipt and a separate per-file operator proposal</li>
      </ul>
    </section>

    <details class="audit-receipt">
      <summary>Inspection receipt</summary>
      <dl>
        <div><dt>Target</dt><dd>{{ audit.target_ref || 'Unavailable' }}</dd></div>
        <div><dt>Metadata SHA-256</dt><dd>{{ audit.metadata_snapshot_sha256 || 'No targeted metadata' }}</dd></div>
        <div><dt>File modified</dt><dd>{{ readableTimestamp(fileState.modified_at) }}</dd></div>
        <div><dt>Catalog hash checked</dt><dd>{{ readableTimestamp(details.content_hash_verified_at) }}</dd></div>
      </dl>
    </details>

    <div class="inspection-actions">
      <button type="button" @click="emit('close')">Close inspection</button>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  context: { type: Object, required: true },
})
const emit = defineEmits(['close'])
const imageError = ref(false)
const item = computed(() => props.context?.item || {})
const details = computed(() => item.value.details || {})
const audit = computed(() => props.context?.legacy_scan_date_metadata || {})
const metadata = computed(() => audit.value.metadata || {})
const fileState = computed(() => audit.value.file_state || {})
const thumbnailUrl = computed(() => {
  const uuid = details.value.asset_uuid
  return uuid ? `/api/media/${encodeURIComponent(uuid)}/thumbnail/large` : null
})
const classification = computed(() => audit.value.classification || 'metadata_unavailable')
const classificationLabel = computed(() => ({
  confirmed_legacy_write: 'Matching metadata with legacy marker',
  strong_legacy_write: 'XMP and IPTC match the catalog',
  partial_legacy_write: 'Only part of the legacy metadata matches',
  metadata_conflict: 'File metadata conflicts with the catalog',
  no_legacy_evidence: 'No targeted legacy fields are present',
  file_missing: 'Catalog file is unavailable',
  metadata_unreadable: 'Metadata could not be read',
}[classification.value] || 'Live inspection unavailable'))
const classificationExplanation = computed(() => ({
  confirmed_legacy_write: 'The current fields and retained marker agree with the catalog estimate.',
  strong_legacy_write: 'Both current creation-date fields agree with the catalog estimate; the attempted note and digitized field are absent.',
  partial_legacy_write: 'Some current fields agree, but the legacy write is incomplete and requires manual metadata forensics.',
  metadata_conflict: 'Existing creation-date fields contain a different value. Nothing should be overwritten or cleared automatically.',
  no_legacy_evidence: 'The catalog write flag is present, but the targeted fields are absent. The file may have been replaced or metadata may have been stripped.',
  file_missing: 'The catalog row exists, but the physical file could not be inspected.',
  metadata_unreadable: 'The file exists, but exiftool did not return a usable metadata record.',
}[classification.value] || 'The live comparison did not return an inspection packet.'))
const classificationClass = computed(() => ({
  strong_legacy_write: 'is-match',
  confirmed_legacy_write: 'is-match',
  no_legacy_evidence: 'is-absent',
  metadata_conflict: 'is-conflict',
  partial_legacy_write: 'is-conflict',
}[classification.value] || 'is-unavailable'))
const confidenceLabel = computed(() => {
  const value = Number(audit.value.catalog_confidence ?? details.value.catalog_confidence)
  const source = `${audit.value.catalog_source || details.value.catalog_source || 'AI visual estimate'}`.replaceAll('_', ' ')
  return Number.isFinite(value) ? `${Math.round(value * 100)}% - ${source}` : source
})
const fileStateLabel = computed(() => {
  const size = formatBytes(fileState.value.size_bytes)
  const modified = readableTimestamp(fileState.value.modified_at)
  return size === 'Unknown' ? modified : `${size}; ${modified}`
})

watch(() => item.value.unified_id, () => { imageError.value = false }, { immediate: true })

function readableDate(value) {
  if (!value) return 'Not present'
  const match = `${value}`.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/)
  if (!match) return `${value}`
  const time = match[4] ? ` ${match[4]}:${match[5]}:${match[6]}` : ''
  return `${match[2]}/${match[3]}/${match[1]}${time}`
}

function readableTimestamp(value) {
  if (!value) return 'Unknown'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? `${value}` : date.toLocaleString()
}

function formatBytes(value) {
  const bytes = Number(value)
  if (!Number.isFinite(bytes) || bytes < 0) return 'Unknown'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 ** 2)).toFixed(1)} MB`
}
</script>

<style scoped>
.legacy-metadata, .legacy-metadata * { box-sizing: border-box; }
.legacy-metadata { color: var(--color-text-primary); min-width: 0; }
.review-header { display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid var(--color-border); padding-bottom: .8rem; }
.review-kind, .section-heading span, .boundary-copy > span { color: var(--ops-warning); font-size: .66rem; font-weight: 800; text-transform: uppercase; }
.review-header h2 { margin: .15rem 0 .3rem; font-size: 1.08rem; line-height: 1.35; }
.review-header p { margin: 0; color: var(--color-text-primary); font-size: .76rem; overflow-wrap: anywhere; }
.close-button { margin-left: auto; width: 2rem; height: 2rem; flex: 0 0 2rem; border: 1px solid var(--color-border); color: var(--color-text-primary); font-size: 1.25rem; line-height: 1; }
.evidence-layout { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(15rem, .8fr); gap: 1rem; margin-top: 1rem; }
.photo-evidence { margin: 0; min-width: 0; }
.photo-evidence img, .photo-unavailable { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: contain; border: 1px solid var(--color-border); }
.photo-evidence img { background: #050505; }
.photo-unavailable { background: var(--color-bg-secondary); }
.photo-unavailable { display: grid; place-items: center; color: var(--color-text-secondary); font-size: .75rem; }
.photo-evidence figcaption { margin-top: .35rem; color: var(--color-text-secondary); font-size: .65rem; line-height: 1.4; }
.inspection-summary { display: grid; align-content: start; gap: .7rem; min-width: 0; }
.classification { border-left: 3px solid var(--color-border); background: var(--color-bg-tertiary); padding: .75rem; }
.classification > span { color: var(--color-text-secondary); font-size: .62rem; font-weight: 800; text-transform: uppercase; }
.classification strong { display: block; margin-top: .2rem; font-size: .92rem; }
.classification p { margin: .4rem 0 0; color: var(--color-text-primary); font-size: .72rem; line-height: 1.45; }
.classification.is-match { border-color: var(--ops-success); background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary)); }
.classification.is-absent { border-color: var(--ops-warning); background: color-mix(in srgb, var(--ops-warning) 10%, var(--color-bg-primary)); }
.classification.is-conflict { border-color: var(--ops-danger); background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary)); }
.catalog-facts { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin: 0; }
.catalog-facts div { min-width: 0; border-top: 1px solid var(--color-border); padding-top: .45rem; }
.catalog-facts dt { color: var(--color-text-secondary); font-size: .61rem; text-transform: uppercase; }
.catalog-facts dd { margin: .18rem 0 0; color: var(--color-text-primary); font-size: .72rem; overflow-wrap: anywhere; }
.metadata-comparison { margin-top: 1rem; border-top: 1px solid var(--color-border); padding-top: .85rem; }
.section-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
.section-heading h3, .boundary-copy h3 { margin: .15rem 0 0; font-size: .88rem; }
.state-badge { flex: 0 0 auto; border: 1px solid var(--ops-success); color: var(--ops-success) !important; padding: .25rem .4rem; }
.metadata-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .55rem; margin-top: .7rem; }
.metadata-grid > div { min-width: 0; background: var(--color-bg-tertiary); border-left: 2px solid var(--color-border); padding: .55rem; }
.metadata-grid span { display: block; color: var(--color-text-secondary); font-size: .6rem; text-transform: uppercase; }
.metadata-grid strong { display: block; margin-top: .18rem; font-size: .72rem; overflow-wrap: anywhere; }
.recovery-boundary { display: grid; grid-template-columns: 1.1fr .9fr; gap: .8rem; margin-top: 1rem; border: 1px solid var(--color-border); background: var(--color-bg-tertiary); padding: .8rem; }
.boundary-copy p, .recovery-boundary li { color: var(--color-text-primary); font-size: .71rem; line-height: 1.45; }
.boundary-copy p { margin: .4rem 0 0; }
.recovery-boundary ul { margin: 0; padding-left: 1rem; }
.recovery-boundary li + li { margin-top: .25rem; }
.audit-receipt { margin-top: .8rem; border-block: 1px solid var(--color-border); padding: .55rem 0; }
.audit-receipt summary { color: var(--color-accent); cursor: pointer; font-size: .7rem; font-weight: 700; }
.audit-receipt dl { display: grid; gap: .35rem; margin: .6rem 0 0; }
.audit-receipt div { display: grid; grid-template-columns: 8rem minmax(0, 1fr); gap: .5rem; }
.audit-receipt dt { color: var(--color-text-secondary); font-size: .62rem; }
.audit-receipt dd { margin: 0; color: var(--color-text-primary); font-family: ui-monospace, monospace; font-size: .62rem; overflow-wrap: anywhere; }
.inspection-actions { display: flex; justify-content: flex-end; margin-top: .8rem; }
.inspection-actions button { border: 1px solid var(--color-border); color: var(--color-text-primary); padding: .5rem .8rem; font-size: .72rem; }
@media (max-width: 760px) {
  .evidence-layout, .recovery-boundary { grid-template-columns: 1fr; }
  .metadata-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 460px) {
  .catalog-facts, .metadata-grid { grid-template-columns: 1fr; }
  .audit-receipt div { grid-template-columns: 1fr; }
}
</style>

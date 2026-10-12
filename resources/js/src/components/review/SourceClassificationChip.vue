<template>
  <div class="src-chip-row" :title="tooltip">
    <span class="src-chip src-chip-label">{{ classification.label }}</span>
    <span class="src-chip" :class="srcClass">{{ srcLabel }}</span>
    <span class="src-chip" :class="infoClass">{{ infoLabel }}</span>
    <span class="src-chip" :class="evClass">{{ evLabel }}</span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

/**
 * Renders the Mills classification trio for one proposal:
 *   Source type · Information type · Evidence type
 *
 * Surfaces the operator-facing distinction professional genealogists
 * use to weigh evidence (Genealogical Proof Standard element 3).
 */
const props = defineProps({
  classification: { type: Object, required: true },
})

const SRC_TYPES = {
  original:   { label: 'Original',   cls: 'chip-original' },
  derivative: { label: 'Derivative', cls: 'chip-derivative' },
  authored:   { label: 'Authored',   cls: 'chip-authored' },
  unknown:    { label: 'Source ?',   cls: 'chip-unknown' },
}
const INFO_TYPES = {
  primary:      { label: 'Primary',     cls: 'chip-primary' },
  secondary:    { label: 'Secondary',   cls: 'chip-secondary' },
  undetermined: { label: 'Info ?',      cls: 'chip-unknown' },
  unknown:      { label: 'Info ?',      cls: 'chip-unknown' },
}
const EV_TYPES = {
  direct:   { label: 'Direct',    cls: 'chip-direct' },
  indirect: { label: 'Indirect',  cls: 'chip-indirect' },
  negative: { label: 'Negative',  cls: 'chip-negative' },
  unknown:  { label: 'Evidence ?', cls: 'chip-unknown' },
}

const srcMeta = computed(() => SRC_TYPES[props.classification.source_type] ?? SRC_TYPES.unknown)
const infoMeta = computed(() => INFO_TYPES[props.classification.information_type] ?? INFO_TYPES.unknown)
const evMeta = computed(() => EV_TYPES[props.classification.evidence_type] ?? EV_TYPES.unknown)

const srcLabel = computed(() => srcMeta.value.label)
const srcClass = computed(() => srcMeta.value.cls)
const infoLabel = computed(() => infoMeta.value.label)
const infoClass = computed(() => infoMeta.value.cls)
const evLabel = computed(() => evMeta.value.label)
const evClass = computed(() => evMeta.value.cls)

const tooltip = computed(() => {
  const c = props.classification
  return `Source type: ${c.source_type} · Information: ${c.information_type} · Evidence: ${c.evidence_type}`
})
</script>

<style scoped>
.src-chip-row { display: inline-flex; gap: 0.25rem; flex-wrap: wrap; align-items: center; }
.src-chip {
  display: inline-block;
  font-size: 0.62rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.1rem 0.4rem;
  border-radius: 0.25rem;
  white-space: nowrap;
}
.src-chip-label   { background: var(--color-bg-tertiary);  color: var(--color-text-primary); }
.chip-original    { background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));    color: var(--ops-success); }
.chip-derivative  { background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));  color: var(--ops-warning); }
.chip-authored    { background: var(--color-bg-tertiary); color: var(--color-text-primary); }
.chip-primary     { background: color-mix(in srgb, var(--ops-success) 12%, var(--color-bg-primary));    color: var(--ops-success); }
.chip-secondary   { background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));  color: var(--ops-warning); }
.chip-direct      { background: var(--color-bg-tertiary); color: var(--color-text-primary); }
.chip-indirect    { background: var(--color-bg-tertiary); color: var(--color-accent); }
.chip-negative    { background: color-mix(in srgb, var(--ops-danger) 12%, var(--color-bg-primary));    color: var(--ops-danger); }
.chip-unknown     { background: var(--color-bg-tertiary); color: var(--color-text-secondary); }
</style>

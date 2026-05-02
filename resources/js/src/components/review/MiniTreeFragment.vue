<template>
  <svg :width="width" :height="height" class="mini-tree" :viewBox="`0 0 ${width} ${height}`">
    <defs>
      <marker id="mini-arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
        <path d="M 0 0 L 10 5 L 0 10 z" :fill="arrowColor" />
      </marker>
    </defs>

    <!-- Connection line(s) — drawn first so the nodes paint over them -->
    <g v-if="layout === 'vertical'">
      <line
        :x1="nodeW / 2 + midX" y1="proposedAbove ? nodeH : nodeH + spacing"
        :x2="nodeW / 2 + midX" y2="proposedAbove ? nodeH + spacing : height - nodeH - spacing"
        stroke="#5da9ff" stroke-width="2" stroke-dasharray="4,3" marker-end="url(#mini-arrow)" />
    </g>
    <g v-else>
      <line
        :x1="spacing + nodeW" :y1="nodeH / 2 + midY"
        :x2="width - spacing - nodeW" :y2="nodeH / 2 + midY"
        stroke="#5da9ff" stroke-width="2" stroke-dasharray="4,3" marker-end="url(#mini-arrow)" />
    </g>

    <!-- Root person node -->
    <g :transform="`translate(${rootX}, ${rootY})`" class="tree-node tree-node-solid">
      <rect :width="nodeW" :height="nodeH" rx="6" fill="rgba(99, 51, 153, 0.40)" stroke="#b39ddb" stroke-width="1.5" />
      <text :x="nodeW / 2" :y="nodeH / 2 - 3" text-anchor="middle" class="tree-label">{{ rootName }}</text>
      <text v-if="rootSubtext" :x="nodeW / 2" :y="nodeH / 2 + 13" text-anchor="middle" class="tree-sub">{{ rootSubtext }}</text>
    </g>

    <!-- Proposed relative node (dotted outline) -->
    <g :transform="`translate(${propX}, ${propY})`" class="tree-node tree-node-proposed">
      <rect :width="nodeW" :height="nodeH" rx="6" fill="rgba(255, 180, 122, 0.15)" stroke="#ffb47a" stroke-width="1.5" stroke-dasharray="5,3" />
      <text :x="nodeW / 2" :y="nodeH / 2 - 3" text-anchor="middle" class="tree-label tree-label-proposed">{{ proposedName || '(unnamed)' }}</text>
      <text v-if="proposedSubtext" :x="nodeW / 2" :y="nodeH / 2 + 13" text-anchor="middle" class="tree-sub">{{ proposedSubtext }}</text>
    </g>

    <!-- Role label on the connection -->
    <text
      :x="layout === 'vertical' ? nodeW / 2 + midX + 8 : width / 2"
      :y="layout === 'vertical' ? nodeH + spacing + (proposedAbove ? -6 : 14) : nodeH / 2 + midY - 6"
      class="tree-edge-label"
      :text-anchor="layout === 'vertical' ? 'start' : 'middle'"
    >{{ roleLabel }}</text>
  </svg>
</template>

<script setup>
/**
 * Two-generation mini-tree for an add_* relationship proposal.
 *
 * Root person = existing (solid border, plum fill).
 * Proposed relative = dashed outline with peach tint.
 * An arrow connects root ↔ proposed, labelled with the relationship role.
 * Layout vertical for parent/child, horizontal for spouse/sibling.
 *
 * Purpose-built for the review detail pane — Topola was the originally
 * planned option but is overkill (~87 KB) for a single-proposal fragment
 * that needs one line + two nodes. This is ~2 KB of pure inline SVG.
 */
import { computed } from 'vue'

const props = defineProps({
  rootName: { type: String, required: true },
  rootSubtext: { type: String, default: null },
  proposedName: { type: String, default: null },
  proposedSubtext: { type: String, default: null },
  role: {
    type: String,
    required: true,
    validator: (v) => ['parent', 'child', 'sibling', 'spouse', 'relative'].includes(v),
  },
})

const nodeW = 140
const nodeH = 46
const spacing = 36

const layout = computed(() => (props.role === 'parent' || props.role === 'child' ? 'vertical' : 'horizontal'))
const proposedAbove = computed(() => props.role === 'parent')

// For vertical layout the nodes are stacked (48 top, 36 gap, 48 bottom).
// For horizontal, side-by-side.
const width = computed(() => (layout.value === 'vertical' ? nodeW + 80 : nodeW * 2 + spacing * 2))
const height = computed(() => (layout.value === 'vertical' ? nodeH * 2 + spacing + 16 : nodeH + 40))

const midX = computed(() => (width.value - nodeW) / 2)
const midY = computed(() => (height.value - nodeH) / 2 - 8)

const rootX = computed(() => {
  if (layout.value === 'vertical') return midX.value
  return spacing
})
const rootY = computed(() => {
  if (layout.value === 'vertical') return proposedAbove.value ? height.value - nodeH - 4 : 4
  return midY.value
})
const propX = computed(() => {
  if (layout.value === 'vertical') return midX.value
  return width.value - nodeW - spacing
})
const propY = computed(() => {
  if (layout.value === 'vertical') return proposedAbove.value ? 4 : height.value - nodeH - 4
  return midY.value
})

const arrowColor = '#5da9ff'

const roleLabel = computed(() => {
  const map = { parent: 'parent of →', child: '↓ child of', sibling: '↔ sibling', spouse: '↔ spouse', relative: '↔ relative' }
  return map[props.role] || props.role
})
</script>

<style scoped>
.mini-tree { display: block; max-width: 100%; }
.tree-label {
  font-size: 12px;
  font-weight: 600;
  fill: #f0e6ff;
  font-family: inherit;
}
.tree-label-proposed { fill: #ffe5b3; }
.tree-sub {
  font-size: 10px;
  fill: #b39ddb;
  font-family: inherit;
}
.tree-edge-label {
  font-size: 10px;
  fill: #b39ddb;
  font-family: inherit;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
</style>

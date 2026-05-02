<template>
  <div class="kg-explorer">
    <!-- LEFT SIDEBAR -->
    <aside class="kg-sidebar">
      <div class="kg-sidebar-inner">
        <!-- Search -->
        <div class="kg-section">
          <div class="kg-section-bar bg-ops-orange"></div>
          <label class="kg-label">ENTITY SEARCH</label>
          <div class="kg-search-wrap">
            <input
              v-model="searchQuery"
              type="text"
              class="kg-search-input"
              placeholder="SEARCH ENTITIES..."
              @keydown.enter="onSearch"
            />
            <button v-if="searchQuery" class="kg-search-clear" @click="searchQuery = ''; onSearch()">X</button>
          </div>
        </div>

        <!-- Stats -->
        <div class="kg-section">
          <div class="kg-section-bar bg-ops-lilac"></div>
          <label class="kg-label">GRAPH STATISTICS</label>
          <div v-if="loadingStats" class="kg-stats-skeleton">
            <div class="kg-skel-line" v-for="i in 4" :key="i"></div>
          </div>
          <div v-else class="kg-stats-grid">
            <div class="kg-stat">
              <span class="kg-stat-value">{{ graphStats.total_entities ?? 0 }}</span>
              <span class="kg-stat-label">ENTITIES</span>
            </div>
            <div class="kg-stat">
              <span class="kg-stat-value">{{ graphStats.total_triples ?? 0 }}</span>
              <span class="kg-stat-label">TRIPLES</span>
            </div>
            <div class="kg-stat">
              <span class="kg-stat-value">{{ communityStats.total_communities ?? 0 }}</span>
              <span class="kg-stat-label">COMMUNITIES</span>
            </div>
            <div class="kg-stat">
              <span class="kg-stat-value">{{ graphStats.average_confidence ?? 0 }}</span>
              <span class="kg-stat-label">AVG CONF</span>
            </div>
          </div>
        </div>

        <!-- Entity Type Filters -->
        <div class="kg-section">
          <div class="kg-section-bar bg-ops-teal"></div>
          <label class="kg-label">ENTITY TYPES</label>
          <div class="kg-type-filters">
            <label
              v-for="t in entityTypes"
              :key="t.value"
              class="kg-type-check"
              :class="{ 'kg-type-active': activeTypes.includes(t.value) }"
            >
              <input
                type="checkbox"
                :value="t.value"
                v-model="activeTypes"
                class="sr-only"
                @change="onFilterChange"
              />
              <span class="kg-type-dot" :style="{ background: typeColorMap[t.value] }"></span>
              <span class="kg-type-name">{{ t.label }}</span>
              <span class="kg-type-count">{{ typeCounts[t.value] || 0 }}</span>
            </label>
          </div>
        </div>

        <!-- Confidence Slider -->
        <div class="kg-section">
          <div class="kg-section-bar bg-ops-sky"></div>
          <label class="kg-label">MIN CONFIDENCE: {{ minConfidence.toFixed(1) }}</label>
          <input
            type="range"
            min="0"
            max="1"
            step="0.1"
            v-model.number="minConfidence"
            class="kg-slider"
            @change="reloadGraph"
          />
        </div>

        <!-- Communities -->
        <div class="kg-section" v-if="communities.length > 0">
          <div class="kg-section-bar bg-ops-gold"></div>
          <label class="kg-label">COMMUNITIES ({{ communities.length }})</label>
          <div class="kg-community-list">
            <button
              v-for="c in communities.slice(0, communityLimit)"
              :key="c.id"
              class="kg-community-item"
              :class="{ 'kg-community-active': highlightedCommunity === c.id }"
              @click="toggleCommunityHighlight(c.id)"
            >
              <span class="kg-comm-dot" :style="{ background: communityColor(c.id) }"></span>
              <span class="kg-comm-title">{{ c.title || `COMMUNITY ${c.community_id}` }}</span>
              <span class="kg-comm-count">{{ c.entity_count }}</span>
            </button>
            <button
              v-if="communities.length > communityLimit"
              class="kg-community-item kg-show-more"
              @click="communityLimit += 50"
            >
              <span class="text-xs text-[#95a5a6]">Show more ({{ communities.length - communityLimit }} remaining)</span>
            </button>
          </div>
        </div>
      </div>
    </aside>

    <!-- CENTER GRAPH -->
    <main class="kg-graph-area" ref="graphContainer">
      <!-- Loading overlay -->
      <div v-if="loadingGraph" class="kg-loading">
        <div class="kg-loading-ring"></div>
        <div class="kg-loading-text">LOADING KNOWLEDGE GRAPH</div>
      </div>

      <!-- Empty state -->
      <div v-else-if="graphNodes.length === 0 && !loadingGraph" class="kg-empty">
        <div class="kg-empty-icon">
          <svg viewBox="0 0 80 80" width="80" height="80">
            <circle cx="20" cy="20" r="8" fill="none" stroke="var(--ops-plum)" stroke-width="2"/>
            <circle cx="60" cy="20" r="6" fill="none" stroke="var(--ops-plum)" stroke-width="2"/>
            <circle cx="40" cy="55" r="10" fill="none" stroke="var(--ops-plum)" stroke-width="2"/>
            <line x1="27" y1="24" x2="34" y2="47" stroke="var(--ops-plum)" stroke-width="1.5" stroke-dasharray="4,3"/>
            <line x1="53" y1="24" x2="46" y2="47" stroke="var(--ops-plum)" stroke-width="1.5" stroke-dasharray="4,3"/>
            <line x1="28" y1="20" x2="52" y2="20" stroke="var(--ops-plum)" stroke-width="1.5" stroke-dasharray="4,3"/>
          </svg>
        </div>
        <div class="kg-empty-title">KNOWLEDGE GRAPH EMPTY</div>
        <div class="kg-empty-sub">NO ENTITIES OR RELATIONSHIPS DETECTED</div>
        <div class="kg-empty-sub">THE KG BUILD JOB POPULATES THIS AUTOMATICALLY</div>
      </div>

      <!-- Graph SVG (always mounted for D3) -->
      <svg ref="svgRef" class="kg-svg" :class="{ 'kg-svg-hidden': loadingGraph || graphNodes.length === 0 }"></svg>

      <!-- Tooltip -->
      <div
        v-if="tooltip.visible"
        class="kg-tooltip"
        :style="{ left: tooltip.x + 'px', top: tooltip.y + 'px' }"
      >
        <div class="kg-tooltip-name">{{ tooltip.name }}</div>
        <div class="kg-tooltip-meta">{{ tooltip.type }} &middot; DEGREE {{ tooltip.degree }}</div>
        <div v-if="tooltip.predicate" class="kg-tooltip-edge">{{ tooltip.predicate }}</div>
      </div>

      <!-- Zoom controls -->
      <div class="kg-zoom-controls">
        <button class="kg-zoom-btn" @click="zoomIn" title="Zoom in">+</button>
        <button class="kg-zoom-btn" @click="zoomOut" title="Zoom out">&minus;</button>
        <button class="kg-zoom-btn kg-zoom-fit" @click="zoomFit" title="Fit to view">FIT</button>
      </div>

      <!-- Legend bar -->
      <div class="kg-legend">
        <span v-for="t in entityTypes" :key="t.value" class="kg-legend-item">
          <span class="kg-legend-dot" :style="{ background: typeColorMap[t.value] }"></span>
          {{ t.label }}
        </span>
      </div>
    </main>

    <!-- RIGHT PANEL (Entity Detail) -->
    <transition name="kg-panel">
      <aside v-if="selectedEntity" class="kg-detail">
        <div class="kg-detail-inner">
          <div class="kg-detail-header">
            <div class="kg-detail-bar bg-ops-orange"></div>
            <button class="kg-detail-close" @click="selectedEntity = null">X</button>
          </div>

          <h2 class="kg-detail-name">{{ selectedEntity.label }}</h2>

          <div class="kg-detail-badges">
            <span class="kg-badge" :style="{ background: typeColorMap[selectedEntity.type] }">
              {{ selectedEntity.type }}
            </span>
            <span v-if="selectedEntity.community_id" class="kg-badge" :style="{ background: communityColor(selectedEntity.community_id) }">
              COMMUNITY {{ selectedEntity.community_id }}
            </span>
          </div>

          <div class="kg-detail-metrics">
            <div class="kg-detail-metric">
              <span class="kg-detail-metric-val">{{ selectedEntity.degree }}</span>
              <span class="kg-detail-metric-lbl">DEGREE</span>
            </div>
            <div class="kg-detail-metric">
              <span class="kg-detail-metric-val">{{ selectedEntity.pagerank?.toFixed(4) || '0' }}</span>
              <span class="kg-detail-metric-lbl">PAGERANK</span>
            </div>
          </div>

          <!-- Relationships -->
          <div class="kg-detail-section" v-if="selectedRelationships.length > 0">
            <div class="kg-section-bar bg-ops-lilac"></div>
            <label class="kg-label">RELATIONSHIPS ({{ selectedRelationships.length }})</label>
            <div class="kg-rel-list">
              <div
                v-for="(rel, i) in selectedRelationships.slice(0, relationshipLimit)"
                :key="i"
                class="kg-rel-item"
              >
                <span class="kg-rel-subject" :class="{ 'kg-rel-self': isSelectedEntity(rel.source) }">
                  {{ getNodeLabel(rel.source) }}
                </span>
                <span class="kg-rel-predicate">{{ rel.predicate }}</span>
                <span class="kg-rel-object" :class="{ 'kg-rel-self': isSelectedEntity(rel.target) }">
                  {{ getNodeLabel(rel.target) }}
                </span>
              </div>
              <button
                v-if="selectedRelationships.length > relationshipLimit"
                class="kg-rel-item kg-show-more"
                @click="relationshipLimit += 50"
              >
                <span class="text-xs text-[#95a5a6]">Show {{ Math.min(50, selectedRelationships.length - relationshipLimit) }} more of {{ selectedRelationships.length }} total</span>
              </button>
            </div>
          </div>

          <!-- Actions -->
          <div class="kg-detail-actions">
            <button class="kg-action-btn" @click="exploreEntity(selectedEntity.label)">
              EXPLORE SUBGRAPH
            </button>
          </div>
        </div>
      </aside>
    </transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import * as d3 from 'd3'
import api from '../../utils/api'

// ── State ────────────────────────────────────────────────────────────────

const graphContainer = ref(null)
const svgRef = ref(null)

const loadingGraph = ref(true)
const loadingStats = ref(true)
const searchQuery = ref('')
const minConfidence = ref(0.3)
const highlightedCommunity = ref(null)
const selectedEntity = ref(null)
const communityLimit = ref(25)
const relationshipLimit = ref(30)

// Reset relationship limit when entity selection changes
watch(selectedEntity, () => { relationshipLimit.value = 30 })

const graphStats = ref({})
const communityStats = ref({})
const graphNodes = ref([])
const graphLinks = ref([])
const communities = ref([])

const activeTypes = ref([
  'person', 'organization', 'location', 'concept', 'event',
  'document', 'date', 'product', 'technology', 'other'
])

const tooltip = reactive({
  visible: false,
  x: 0,
  y: 0,
  name: '',
  type: '',
  degree: 0,
  predicate: ''
})

// ── Constants ────────────────────────────────────────────────────────────

const entityTypes = [
  { value: 'person', label: 'PERSON' },
  { value: 'organization', label: 'ORG' },
  { value: 'location', label: 'LOCATION' },
  { value: 'concept', label: 'CONCEPT' },
  { value: 'event', label: 'EVENT' },
  { value: 'document', label: 'DOCUMENT' },
  { value: 'date', label: 'DATE' },
  { value: 'product', label: 'PRODUCT' },
  { value: 'technology', label: 'TECH' },
  { value: 'other', label: 'OTHER' },
]

const typeColorMap = {
  person: '#ff9900',
  organization: '#cc99cc',
  location: '#99cc66',
  concept: '#99ccff',
  event: '#ffcc99',
  document: '#6688cc',
  date: '#66cccc',
  product: '#ff9966',
  technology: '#9977aa',
  other: '#8899aa',
}

const COMMUNITY_COLORS = [
  '#ff9900', '#cc99cc', '#99ccff', '#99cc66', '#66cccc',
  '#ffcc99', '#9977aa', '#6688cc', '#ff9966', '#774477',
  '#aabb44', '#44aacc', '#cc6644', '#88cc88', '#ccaa55',
]

// ── Computed ─────────────────────────────────────────────────────────────

const typeCounts = computed(() => {
  const counts = {}
  for (const n of graphNodes.value) {
    counts[n.type] = (counts[n.type] || 0) + 1
  }
  return counts
})

const filteredNodes = computed(() => {
  return graphNodes.value.filter(n => activeTypes.value.includes(n.type))
})

const filteredNodeIds = computed(() => new Set(filteredNodes.value.map(n => n.id)))

const filteredLinks = computed(() => {
  return graphLinks.value.filter(
    l => filteredNodeIds.value.has(l.source?.id ?? l.source) &&
         filteredNodeIds.value.has(l.target?.id ?? l.target)
  )
})

const selectedRelationships = computed(() => {
  if (!selectedEntity.value) return []
  const id = selectedEntity.value.id
  return graphLinks.value.filter(
    l => (l.source?.id ?? l.source) === id || (l.target?.id ?? l.target) === id
  )
})

// ── D3 refs ──────────────────────────────────────────────────────────────

let simulation = null
let svgSelection = null
let gMain = null
let linkGroup = null
let nodeGroup = null
let labelGroup = null
let zoomBehavior = null
let resizeObserver = null

// ── Methods ──────────────────────────────────────────────────────────────

function communityColor(communityId) {
  if (!communityId) return '#8899aa'
  const idx = communities.value.findIndex(c => c.id === communityId)
  return COMMUNITY_COLORS[idx >= 0 ? idx % COMMUNITY_COLORS.length : Math.abs(communityId) % COMMUNITY_COLORS.length]
}

function nodeColor(node) {
  if (highlightedCommunity.value !== null && node.community_id !== highlightedCommunity.value) {
    return '#333344'
  }
  if (node.community_id) return communityColor(node.community_id)
  return typeColorMap[node.type] || '#8899aa'
}

function nodeRadius(node) {
  const deg = node.degree || 1
  return Math.max(5, Math.min(22, 4 + Math.sqrt(deg) * 3))
}

function getNodeLabel(idOrObj) {
  const id = idOrObj?.id ?? idOrObj
  const node = graphNodes.value.find(n => n.id === id)
  return node?.label || String(id)
}

function isSelectedEntity(idOrObj) {
  if (!selectedEntity.value) return false
  return (idOrObj?.id ?? idOrObj) === selectedEntity.value.id
}

function toggleCommunityHighlight(id) {
  highlightedCommunity.value = highlightedCommunity.value === id ? null : id
  updateVisuals()
}

// ── API ──────────────────────────────────────────────────────────────────

async function loadStats() {
  loadingStats.value = true
  try {
    const [gs, cs] = await Promise.all([
      api.get('/rag/graph/stats'),
      api.get('/rag/graph/communities/stats'),
    ])
    graphStats.value = gs
    communityStats.value = cs
  } catch (e) {
    console.error('Failed to load graph stats:', e)
  } finally {
    loadingStats.value = false
  }
}

async function loadGraph() {
  loadingGraph.value = true
  try {
    const data = await api.get('/rag/graph/full-graph', {
      params: {
        min_confidence: minConfidence.value,
        max_nodes: 200,
      }
    })
    graphNodes.value = data.nodes || []
    graphLinks.value = (data.links || []).map(l => ({ ...l }))
    communities.value = data.communities || []
    await nextTick()
    initGraph()
  } catch (e) {
    console.error('Failed to load graph:', e)
    graphNodes.value = []
    graphLinks.value = []
  } finally {
    loadingGraph.value = false
  }
}

async function reloadGraph() {
  await loadGraph()
}

async function onSearch() {
  if (!searchQuery.value.trim()) {
    reloadGraph()
    return
  }
  try {
    const data = await api.get('/rag/graph/entities', {
      params: { query: searchQuery.value.trim() }
    })
    const matched = data.entities || []
    if (matched.length > 0) {
      // Highlight matched entities
      const matchedNames = new Set(matched.map(e => e.canonical_name.toLowerCase()))
      updateVisuals(matchedNames)
    }
  } catch (e) {
    console.error('Entity search failed:', e)
  }
}

async function exploreEntity(entityName) {
  loadingGraph.value = true
  try {
    const data = await api.get('/rag/knowledge-graph/graph/' + encodeURIComponent(entityName), {
      params: { depth: 2, max_nodes: 100, min_confidence: minConfidence.value }
    })
    if (data.nodes && data.nodes.length > 0) {
      // Convert getEntityGraph format to full-graph format
      graphNodes.value = data.nodes.map(n => ({
        id: n.id,
        label: n.label,
        type: n.type,
        degree: 0,
        pagerank: 0,
        community_id: null,
      }))
      graphLinks.value = (data.edges || []).map(e => ({
        source: e.source,
        target: e.target,
        predicate: e.predicate,
        confidence: e.confidence,
      }))
      // Compute degrees
      for (const link of graphLinks.value) {
        const srcNode = graphNodes.value.find(n => n.id === link.source)
        const tgtNode = graphNodes.value.find(n => n.id === link.target)
        if (srcNode) srcNode.degree++
        if (tgtNode) tgtNode.degree++
      }
      communities.value = []
      await nextTick()
      initGraph()
    }
  } catch (e) {
    console.error('Explore entity failed:', e)
  } finally {
    loadingGraph.value = false
  }
}

function onFilterChange() {
  updateVisuals()
}

// ── D3 Graph ─────────────────────────────────────────────────────────────

function initGraph() {
  if (!svgRef.value || graphNodes.value.length === 0) return

  const svg = d3.select(svgRef.value)
  svg.selectAll('*').remove()

  const rect = svgRef.value.getBoundingClientRect()
  const width = rect.width || 800
  const height = rect.height || 600

  svg.attr('width', width).attr('height', height)

  // Defs for arrow markers
  const defs = svg.append('defs')
  defs.append('marker')
    .attr('id', 'kg-arrow')
    .attr('viewBox', '0 -4 8 8')
    .attr('refX', 20)
    .attr('refY', 0)
    .attr('markerWidth', 6)
    .attr('markerHeight', 6)
    .attr('orient', 'auto')
    .append('path')
    .attr('d', 'M0,-3L7,0L0,3')
    .attr('fill', '#555566')

  // Zoom
  zoomBehavior = d3.zoom()
    .scaleExtent([0.1, 6])
    .on('zoom', (event) => {
      gMain.attr('transform', event.transform)
    })
  svg.call(zoomBehavior)

  svgSelection = svg
  gMain = svg.append('g')

  // Make deep copies of nodes/links for D3 mutation
  const nodes = graphNodes.value.map(n => ({ ...n }))
  const links = graphLinks.value.map(l => ({
    ...l,
    source: l.source,
    target: l.target,
  }))

  // Links
  linkGroup = gMain.append('g').attr('class', 'kg-links')
    .selectAll('line')
    .data(links)
    .enter()
    .append('line')
    .attr('stroke', '#444455')
    .attr('stroke-width', d => Math.max(0.5, d.confidence * 2))
    .attr('stroke-opacity', d => 0.2 + d.confidence * 0.5)
    .attr('marker-end', 'url(#kg-arrow)')
    .on('mouseenter', (event, d) => {
      tooltip.visible = true
      tooltip.predicate = d.predicate
      tooltip.name = `${getNodeLabel(d.source)} -> ${getNodeLabel(d.target)}`
      tooltip.type = ''
      tooltip.degree = 0
      const containerRect = graphContainer.value.getBoundingClientRect()
      tooltip.x = event.clientX - containerRect.left + 10
      tooltip.y = event.clientY - containerRect.top - 10
    })
    .on('mouseleave', () => {
      tooltip.visible = false
      tooltip.predicate = ''
    })

  // Nodes
  nodeGroup = gMain.append('g').attr('class', 'kg-nodes')
    .selectAll('g')
    .data(nodes)
    .enter()
    .append('g')
    .attr('class', 'kg-node-g')
    .call(d3.drag()
      .on('start', dragStarted)
      .on('drag', dragged)
      .on('end', dragEnded)
    )
    .on('mouseenter', (event, d) => {
      tooltip.visible = true
      tooltip.name = d.label
      tooltip.type = d.type?.toUpperCase() || 'OTHER'
      tooltip.degree = d.degree || 0
      tooltip.predicate = ''
      const containerRect = graphContainer.value.getBoundingClientRect()
      tooltip.x = event.clientX - containerRect.left + 10
      tooltip.y = event.clientY - containerRect.top - 10
      highlightNeighbors(d)
    })
    .on('mouseleave', (event, d) => {
      tooltip.visible = false
      unhighlightNeighbors()
    })
    .on('click', (event, d) => {
      event.stopPropagation()
      selectedEntity.value = d
      highlightSelected(d)
    })
    .on('dblclick', (event, d) => {
      event.stopPropagation()
      exploreEntity(d.label)
    })

  // Draw shapes based on entity type
  nodeGroup.each(function(d) {
    const g = d3.select(this)
    const r = nodeRadius(d)
    const color = nodeColor(d)

    switch (d.type) {
      case 'organization':
        // Rounded rectangle
        g.append('rect')
          .attr('x', -r).attr('y', -r)
          .attr('width', r * 2).attr('height', r * 2)
          .attr('rx', 3)
          .attr('fill', color)
          .attr('stroke', '#1a1a2e')
          .attr('stroke-width', 1.5)
          .attr('class', 'kg-node-shape')
        break
      case 'location':
        // Diamond
        g.append('polygon')
          .attr('points', `0,${-r} ${r},0 0,${r} ${-r},0`)
          .attr('fill', color)
          .attr('stroke', '#1a1a2e')
          .attr('stroke-width', 1.5)
          .attr('class', 'kg-node-shape')
        break
      case 'concept':
        // Hexagon
        {
          const pts = []
          for (let i = 0; i < 6; i++) {
            const angle = (Math.PI / 3) * i - Math.PI / 6
            pts.push(`${r * Math.cos(angle)},${r * Math.sin(angle)}`)
          }
          g.append('polygon')
            .attr('points', pts.join(' '))
            .attr('fill', color)
            .attr('stroke', '#1a1a2e')
            .attr('stroke-width', 1.5)
            .attr('class', 'kg-node-shape')
        }
        break
      default:
        // Circle
        g.append('circle')
          .attr('r', r)
          .attr('fill', color)
          .attr('stroke', '#1a1a2e')
          .attr('stroke-width', 1.5)
          .attr('class', 'kg-node-shape')
    }
  })

  // Labels (only for high-degree nodes)
  labelGroup = gMain.append('g').attr('class', 'kg-labels')
    .selectAll('text')
    .data(nodes.filter(n => (n.degree || 0) >= 3))
    .enter()
    .append('text')
    .text(d => d.label?.length > 18 ? d.label.substring(0, 16) + '..' : d.label)
    .attr('class', 'kg-node-label')
    .attr('text-anchor', 'middle')
    .attr('dy', d => nodeRadius(d) + 12)
    .attr('fill', '#aabbcc')
    .attr('font-size', '9px')
    .attr('font-family', 'Antonio, sans-serif')
    .attr('text-transform', 'uppercase')
    .attr('pointer-events', 'none')

  // Force simulation
  simulation = d3.forceSimulation(nodes)
    .force('link', d3.forceLink(links).id(d => d.id).distance(80).strength(0.3))
    .force('charge', d3.forceManyBody().strength(-200).distanceMax(400))
    .force('center', d3.forceCenter(width / 2, height / 2))
    .force('collision', d3.forceCollide().radius(d => nodeRadius(d) + 4))
    .force('x', d3.forceX(width / 2).strength(0.03))
    .force('y', d3.forceY(height / 2).strength(0.03))
    .on('tick', ticked)

  // Click on background to deselect
  svg.on('click', () => {
    selectedEntity.value = null
    unhighlightNeighbors()
  })
}

function ticked() {
  if (linkGroup) {
    linkGroup
      .attr('x1', d => d.source.x)
      .attr('y1', d => d.source.y)
      .attr('x2', d => d.target.x)
      .attr('y2', d => d.target.y)
  }
  if (nodeGroup) {
    nodeGroup.attr('transform', d => `translate(${d.x},${d.y})`)
  }
  if (labelGroup) {
    labelGroup
      .attr('x', d => d.x)
      .attr('y', d => d.y)
  }
}

function dragStarted(event, d) {
  if (!event.active) simulation.alphaTarget(0.3).restart()
  d.fx = d.x
  d.fy = d.y
}

function dragged(event, d) {
  d.fx = event.x
  d.fy = event.y
}

function dragEnded(event, d) {
  if (!event.active) simulation.alphaTarget(0)
  d.fx = null
  d.fy = null
}

function highlightNeighbors(d) {
  const connectedIds = new Set()
  connectedIds.add(d.id)
  graphLinks.value.forEach(l => {
    const sid = l.source?.id ?? l.source
    const tid = l.target?.id ?? l.target
    if (sid === d.id) connectedIds.add(tid)
    if (tid === d.id) connectedIds.add(sid)
  })

  if (nodeGroup) {
    nodeGroup.select('.kg-node-shape')
      .attr('opacity', n => connectedIds.has(n.id) ? 1 : 0.15)
  }
  if (linkGroup) {
    linkGroup
      .attr('stroke-opacity', l => {
        const sid = l.source?.id ?? l.source
        const tid = l.target?.id ?? l.target
        return (sid === d.id || tid === d.id) ? 0.8 : 0.05
      })
  }
  if (labelGroup) {
    labelGroup.attr('opacity', n => connectedIds.has(n.id) ? 1 : 0.1)
  }
}

function highlightSelected(d) {
  highlightNeighbors(d)
}

function unhighlightNeighbors() {
  if (!selectedEntity.value) {
    if (nodeGroup) nodeGroup.select('.kg-node-shape').attr('opacity', 1)
    if (linkGroup) linkGroup.attr('stroke-opacity', d => 0.2 + d.confidence * 0.5)
    if (labelGroup) labelGroup.attr('opacity', 1)
  }
}

function updateVisuals(highlightSet = null) {
  if (!nodeGroup) return

  const activeSet = new Set(activeTypes.value)

  nodeGroup.select('.kg-node-shape')
    .attr('fill', d => nodeColor(d))
    .attr('opacity', d => {
      if (!activeSet.has(d.type)) return 0.05
      if (highlightSet && !highlightSet.has(d.label.toLowerCase())) return 0.15
      return 1
    })

  if (linkGroup) {
    linkGroup.attr('stroke-opacity', d => {
      const sid = d.source?.id ?? d.source
      const tid = d.target?.id ?? d.target
      const srcNode = graphNodes.value.find(n => n.id === sid)
      const tgtNode = graphNodes.value.find(n => n.id === tid)
      if (srcNode && !activeSet.has(srcNode.type)) return 0.02
      if (tgtNode && !activeSet.has(tgtNode.type)) return 0.02
      return 0.2 + d.confidence * 0.5
    })
  }
}

function zoomIn() {
  if (svgSelection && zoomBehavior) {
    svgSelection.transition().duration(300).call(zoomBehavior.scaleBy, 1.4)
  }
}

function zoomOut() {
  if (svgSelection && zoomBehavior) {
    svgSelection.transition().duration(300).call(zoomBehavior.scaleBy, 0.7)
  }
}

function zoomFit() {
  if (!svgSelection || !zoomBehavior || !gMain) return
  const rect = svgRef.value.getBoundingClientRect()
  const bounds = gMain.node().getBBox()
  if (bounds.width === 0 || bounds.height === 0) return

  const fullWidth = rect.width
  const fullHeight = rect.height
  const scale = 0.85 * Math.min(fullWidth / bounds.width, fullHeight / bounds.height)
  const tx = fullWidth / 2 - scale * (bounds.x + bounds.width / 2)
  const ty = fullHeight / 2 - scale * (bounds.y + bounds.height / 2)

  svgSelection.transition().duration(500)
    .call(zoomBehavior.transform, d3.zoomIdentity.translate(tx, ty).scale(scale))
}

function handleResize() {
  if (!svgRef.value) return
  const rect = svgRef.value.parentElement.getBoundingClientRect()
  d3.select(svgRef.value).attr('width', rect.width).attr('height', rect.height)
  if (simulation) {
    simulation.force('center', d3.forceCenter(rect.width / 2, rect.height / 2))
    simulation.force('x', d3.forceX(rect.width / 2).strength(0.03))
    simulation.force('y', d3.forceY(rect.height / 2).strength(0.03))
    simulation.alpha(0.1).restart()
  }
}

// ── Lifecycle ────────────────────────────────────────────────────────────

onMounted(async () => {
  await Promise.all([loadStats(), loadGraph()])

  if (graphContainer.value) {
    resizeObserver = new ResizeObserver(handleResize)
    resizeObserver.observe(graphContainer.value)
  }
})

onBeforeUnmount(() => {
  if (simulation) simulation.stop()
  if (resizeObserver) resizeObserver.disconnect()
})
</script>

<style scoped>
.kg-explorer {
  display: flex;
  height: 100%;
  width: 100%;
  background: var(--ops-bg, #1a1a2e);
  overflow: hidden;
  font-family: 'Antonio', sans-serif;
}

/* ── LEFT SIDEBAR ─────────────────────────────────────────────────────── */

.kg-sidebar {
  width: 264px;
  min-width: 264px;
  border-right: 3px solid var(--ops-plum, #774477);
  overflow-y: auto;
  background: rgba(0, 0, 0, 0.25);
}

.kg-sidebar-inner {
  padding: 12px 10px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.kg-section {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.kg-section-bar {
  height: 4px;
  border-radius: 2px;
  width: 60px;
}

.kg-label {
  font-size: 10px;
  letter-spacing: 1.5px;
  color: var(--ops-text-muted, #8899aa);
  text-transform: uppercase;
}

/* Search */
.kg-search-wrap {
  position: relative;
}

.kg-search-input {
  width: 100%;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid var(--ops-plum, #774477);
  color: var(--ops-text, #ff9966);
  font-family: 'Antonio', sans-serif;
  font-size: 13px;
  letter-spacing: 1px;
  text-transform: uppercase;
  padding: 8px 32px 8px 10px;
  border-radius: 0 16px 16px 0;
  outline: none;
  transition: border-color 0.2s;
}

.kg-search-input::placeholder {
  color: var(--ops-text-muted, #8899aa);
  opacity: 0.5;
}

.kg-search-input:focus {
  border-color: var(--ops-orange, #ff9900);
}

.kg-search-clear {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--ops-text-muted, #8899aa);
  font-family: 'Antonio', sans-serif;
  font-size: 12px;
  cursor: pointer;
}

/* Stats */
.kg-stats-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.kg-stat {
  display: flex;
  flex-direction: column;
  background: rgba(255, 255, 255, 0.04);
  border-radius: 0 10px 10px 0;
  padding: 8px 10px;
  border-left: 3px solid var(--ops-violet, #9977aa);
}

.kg-stat-value {
  font-size: 20px;
  color: var(--ops-text, #ff9966);
  line-height: 1;
}

.kg-stat-label {
  font-size: 8px;
  letter-spacing: 1.5px;
  color: var(--ops-text-muted, #8899aa);
  margin-top: 2px;
}

.kg-stats-skeleton {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.kg-skel-line {
  height: 18px;
  background: rgba(255, 255, 255, 0.06);
  border-radius: 4px;
  animation: kg-pulse 1.2s ease-in-out infinite;
}

@keyframes kg-pulse {
  0%, 100% { opacity: 0.3; }
  50% { opacity: 0.7; }
}

/* Type filters */
.kg-type-filters {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.kg-type-check {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 3px 6px;
  border-radius: 0 8px 8px 0;
  cursor: pointer;
  transition: background 0.15s;
  opacity: 0.4;
}

.kg-type-check:hover {
  background: rgba(255, 255, 255, 0.05);
}

.kg-type-active {
  opacity: 1;
}

.kg-type-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.kg-type-name {
  font-size: 11px;
  letter-spacing: 1px;
  color: var(--ops-text, #ff9966);
  flex: 1;
}

.kg-type-count {
  font-size: 10px;
  color: var(--ops-text-muted, #8899aa);
}

/* Slider */
.kg-slider {
  -webkit-appearance: none;
  width: 100%;
  height: 4px;
  border-radius: 2px;
  background: rgba(255, 255, 255, 0.1);
  outline: none;
}

.kg-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--ops-sky, #99ccff);
  cursor: pointer;
  border: 2px solid var(--ops-bg, #1a1a2e);
}

/* Communities */
.kg-community-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 180px;
  overflow-y: auto;
}

.kg-community-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 6px;
  background: none;
  border: none;
  border-radius: 0 8px 8px 0;
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}

.kg-community-item:hover {
  background: rgba(255, 255, 255, 0.06);
}

.kg-community-active {
  background: rgba(255, 255, 255, 0.1) !important;
}

.kg-comm-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.kg-comm-title {
  font-size: 10px;
  letter-spacing: 0.5px;
  color: var(--ops-text, #ff9966);
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-transform: uppercase;
}

.kg-comm-count {
  font-size: 10px;
  color: var(--ops-text-muted, #8899aa);
}

/* ── CENTER GRAPH ─────────────────────────────────────────────────────── */

.kg-graph-area {
  flex: 1;
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(circle at 30% 40%, rgba(119, 68, 119, 0.06) 0%, transparent 60%),
    radial-gradient(circle at 70% 60%, rgba(102, 136, 204, 0.04) 0%, transparent 50%),
    var(--ops-bg, #1a1a2e);
}

.kg-svg {
  width: 100%;
  height: 100%;
  display: block;
}

.kg-svg-hidden {
  opacity: 0;
  pointer-events: none;
}

/* Loading */
.kg-loading {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  z-index: 10;
}

.kg-loading-ring {
  width: 48px;
  height: 48px;
  border: 3px solid rgba(255, 153, 0, 0.15);
  border-top-color: var(--ops-orange, #ff9900);
  border-radius: 50%;
  animation: kg-spin 1s linear infinite;
}

@keyframes kg-spin {
  to { transform: rotate(360deg); }
}

.kg-loading-text {
  font-size: 12px;
  letter-spacing: 3px;
  color: var(--ops-text-muted, #8899aa);
}

/* Empty */
.kg-empty {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  z-index: 10;
}

.kg-empty-title {
  font-size: 16px;
  letter-spacing: 3px;
  color: var(--ops-text-muted, #8899aa);
}

.kg-empty-sub {
  font-size: 11px;
  letter-spacing: 1px;
  color: var(--ops-plum, #774477);
}

/* Tooltip */
.kg-tooltip {
  position: absolute;
  background: rgba(10, 10, 20, 0.92);
  border: 1px solid var(--ops-plum, #774477);
  border-radius: 0 10px 10px 0;
  padding: 8px 12px;
  pointer-events: none;
  z-index: 20;
  max-width: 260px;
  border-left: 3px solid var(--ops-orange, #ff9900);
}

.kg-tooltip-name {
  font-size: 12px;
  color: var(--ops-text, #ff9966);
  text-transform: uppercase;
  letter-spacing: 1px;
}

.kg-tooltip-meta {
  font-size: 10px;
  color: var(--ops-text-muted, #8899aa);
  letter-spacing: 0.5px;
}

.kg-tooltip-edge {
  font-size: 10px;
  color: var(--ops-sky, #99ccff);
  margin-top: 2px;
  text-transform: uppercase;
}

/* Zoom controls */
.kg-zoom-controls {
  position: absolute;
  bottom: 50px;
  right: 16px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  z-index: 10;
}

.kg-zoom-btn {
  width: 36px;
  height: 28px;
  background: rgba(119, 68, 119, 0.5);
  border: 1px solid var(--ops-plum, #774477);
  border-radius: 0 8px 8px 0;
  color: var(--ops-text, #ff9966);
  font-family: 'Antonio', sans-serif;
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s;
}

.kg-zoom-btn:hover {
  background: rgba(255, 153, 0, 0.4);
}

.kg-zoom-fit {
  font-size: 10px;
  letter-spacing: 1px;
}

/* Legend */
.kg-legend {
  position: absolute;
  bottom: 8px;
  left: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  z-index: 10;
  padding: 6px 10px;
  background: rgba(10, 10, 20, 0.7);
  border-radius: 0 10px 10px 0;
  border-left: 3px solid var(--ops-plum, #774477);
}

.kg-legend-item {
  font-size: 9px;
  letter-spacing: 1px;
  color: var(--ops-text-muted, #8899aa);
  display: flex;
  align-items: center;
  gap: 4px;
}

.kg-legend-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}

/* ── RIGHT PANEL ──────────────────────────────────────────────────────── */

.kg-detail {
  width: 300px;
  min-width: 300px;
  border-left: 3px solid var(--ops-plum, #774477);
  background: rgba(0, 0, 0, 0.3);
  overflow-y: auto;
}

.kg-detail-inner {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.kg-detail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.kg-detail-bar {
  height: 4px;
  border-radius: 2px;
  flex: 1;
  margin-right: 12px;
}

.kg-detail-close {
  background: rgba(255, 255, 255, 0.1);
  border: none;
  color: var(--ops-text-muted, #8899aa);
  font-family: 'Antonio', sans-serif;
  font-size: 14px;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s;
}

.kg-detail-close:hover {
  background: rgba(204, 0, 0, 0.3);
  color: var(--ops-text, #ff9966);
}

.kg-detail-name {
  font-size: 20px;
  color: var(--ops-orange, #ff9900);
  text-transform: uppercase;
  letter-spacing: 1px;
  line-height: 1.2;
  word-break: break-word;
}

.kg-detail-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.kg-badge {
  font-size: 9px;
  letter-spacing: 1.5px;
  color: #1a1a2e;
  padding: 3px 10px;
  border-radius: 0 8px 8px 0;
  text-transform: uppercase;
  font-weight: 700;
}

.kg-detail-metrics {
  display: flex;
  gap: 10px;
}

.kg-detail-metric {
  display: flex;
  flex-direction: column;
  background: rgba(255, 255, 255, 0.04);
  padding: 8px 12px;
  border-radius: 0 10px 10px 0;
  border-left: 3px solid var(--ops-violet, #9977aa);
  flex: 1;
}

.kg-detail-metric-val {
  font-size: 18px;
  color: var(--ops-text, #ff9966);
  line-height: 1;
}

.kg-detail-metric-lbl {
  font-size: 8px;
  letter-spacing: 1.5px;
  color: var(--ops-text-muted, #8899aa);
  margin-top: 2px;
}

.kg-detail-section {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.kg-rel-list {
  display: flex;
  flex-direction: column;
  gap: 3px;
  max-height: 300px;
  overflow-y: auto;
}

.kg-rel-item {
  display: flex;
  align-items: baseline;
  gap: 4px;
  font-size: 10px;
  padding: 3px 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  flex-wrap: wrap;
}

.kg-rel-subject,
.kg-rel-object {
  color: var(--ops-text, #ff9966);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.kg-rel-self {
  color: var(--ops-orange, #ff9900);
  font-weight: 700;
}

.kg-rel-predicate {
  color: var(--ops-sky, #99ccff);
  font-size: 9px;
  letter-spacing: 1px;
}

.kg-detail-actions {
  padding-top: 8px;
}

.kg-action-btn {
  width: 100%;
  padding: 8px;
  background: var(--ops-plum, #774477);
  color: var(--ops-text, #ff9966);
  border: none;
  border-radius: 0 12px 12px 0;
  font-family: 'Antonio', sans-serif;
  font-size: 12px;
  letter-spacing: 2px;
  cursor: pointer;
  text-transform: uppercase;
  transition: background 0.15s;
}

.kg-action-btn:hover {
  background: var(--ops-violet, #9977aa);
}

/* ── Transitions ──────────────────────────────────────────────────────── */

.kg-panel-enter-active,
.kg-panel-leave-active {
  transition: transform 0.25s ease, opacity 0.25s ease;
}

.kg-panel-enter-from,
.kg-panel-leave-to {
  transform: translateX(100%);
  opacity: 0;
}

/* ── Scrollbar ────────────────────────────────────────────────────────── */

.kg-sidebar::-webkit-scrollbar,
.kg-detail::-webkit-scrollbar,
.kg-rel-list::-webkit-scrollbar,
.kg-community-list::-webkit-scrollbar {
  width: 4px;
}

.kg-sidebar::-webkit-scrollbar-track,
.kg-detail::-webkit-scrollbar-track,
.kg-rel-list::-webkit-scrollbar-track,
.kg-community-list::-webkit-scrollbar-track {
  background: transparent;
}

.kg-sidebar::-webkit-scrollbar-thumb,
.kg-detail::-webkit-scrollbar-thumb,
.kg-rel-list::-webkit-scrollbar-thumb,
.kg-community-list::-webkit-scrollbar-thumb {
  background: var(--ops-plum, #774477);
  border-radius: 2px;
}

/* Tailwind utility for Ops Console colors */
.bg-ops-orange { background: var(--ops-orange, #ff9900); }
.bg-ops-lilac { background: var(--ops-lilac, #cc99cc); }
.bg-ops-teal { background: var(--ops-teal, #66cccc); }
.bg-ops-sky { background: var(--ops-sky, #99ccff); }
.bg-ops-gold { background: var(--ops-gold, #ffcc99); }
</style>

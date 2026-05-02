<template>
  <div class="workflow-editor">
    <!-- Top Toolbar -->
    <div class="editor-toolbar">
      <div class="toolbar-left">
        <button @click="goBack" class="btn-back" title="Back to workflows">
          ← Back
        </button>
        <input
          v-model="workflow.name"
          @input="markUnsaved"
          class="workflow-name-input"
          placeholder="Workflow Name"
        />
        <span v-if="workflow.active" class="badge-active">Active</span>
        <span v-else class="badge-inactive">Inactive</span>
      </div>

      <div class="toolbar-center">
        <div class="auto-backup-status">
          <span v-if="isSaving" class="status-saving">💾 Saving...</span>
          <span v-else-if="hasUnsavedChanges" class="status-unsaved">⚠️ Unsaved changes</span>
          <span v-else-if="lastBackupTime" class="status-saved">
            ✓ Saved {{ formatTimeAgo(lastBackupTime) }}
          </span>
        </div>
      </div>

      <div class="toolbar-right">
        <button @click="toggleViewMode" class="btn-secondary" title="Toggle between graph and list view">
          {{ viewMode === 'graph' ? '📋 List View' : '🎨 Graph View' }}
        </button>
        <button @click="showBackupsModal = true" class="btn-secondary" :disabled="!workflow.id" title="View backups">
          📜 Backups
        </button>
        <button @click="saveWorkflow" class="btn-primary" :disabled="isSaving">
          💾 {{ workflow.id ? 'Save' : 'Create' }}
        </button>
        <button @click="testWorkflow" class="btn-secondary" :disabled="!workflow.id" title="Test this workflow">
          ▶️ Test
        </button>
        <button @click="runDryRun" class="btn-secondary" :disabled="!workflow.id || dryRunning" title="Validate without running">
          {{ dryRunning ? '...' : '🔍' }} Dry Run
        </button>
      </div>
    </div>

    <!-- Dry Run Results Modal -->
    <div v-if="dryRunResults" class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50 p-4" @click.self="dryRunResults = null">
      <div class="bg-[#2d2d2d] rounded-lg shadow-2xl max-w-lg w-full border-2 border-accent max-h-[70vh] overflow-y-auto">
        <div class="p-4 border-b border-[#444] flex justify-between items-center sticky top-0 bg-[#2d2d2d]">
          <h3 class="text-lg font-bold text-[#e0e0e0]">Dry Run Results</h3>
          <button @click="dryRunResults = null" class="text-[#95a5a6] hover:text-[#e0e0e0] text-xl">&times;</button>
        </div>
        <div class="p-4 space-y-3">
          <div class="text-sm text-[#95a5a6] mb-2">{{ dryRunResults.node_count }} nodes validated</div>
          <div v-for="result in dryRunResults.results" :key="result.node_id" class="p-3 rounded border"
               :class="result.validation.valid && !result.validation.warnings.length ? 'border-[#27ae60] bg-[#27ae60]/10' : 'border-[#f39c12] bg-[#f39c12]/10'">
            <div class="font-medium text-[#e0e0e0]">{{ result.name || result.type }}</div>
            <div class="text-xs text-[#95a5a6]">{{ result.type }}</div>
            <div v-for="warn in result.validation.warnings" :key="warn" class="text-xs text-[#f39c12] mt-1">⚠ {{ warn }}</div>
            <div v-if="result.validation.valid && !result.validation.warnings.length" class="text-xs text-[#27ae60] mt-1">✓ Valid</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Metrics & Cache Collapsibles -->
    <div v-if="workflow.id" class="flex gap-2 px-4 py-2 bg-[#1a1a1a] border-b border-[#444]">
      <button @click="toggleMetrics" class="text-xs px-3 py-1 rounded bg-[#2d2d2d] text-[#95a5a6] hover:text-[#e0e0e0] border border-[#444]">
        {{ showMetrics ? '▼' : '▶' }} Metrics
      </button>
      <button @click="toggleCacheStats" class="text-xs px-3 py-1 rounded bg-[#2d2d2d] text-[#95a5a6] hover:text-[#e0e0e0] border border-[#444]">
        {{ showCacheStats ? '▼' : '▶' }} Cache Stats
      </button>
    </div>

    <!-- Metrics Panel -->
    <div v-if="showMetrics && metricsData" class="px-4 py-3 bg-[#252525] border-b border-[#444]">
      <div class="grid grid-cols-4 gap-4 text-center">
        <div>
          <div class="text-xs text-[#95a5a6]">Total Runs</div>
          <div class="text-lg font-bold text-[#e0e0e0]">{{ metricsData.stats?.total_runs || 0 }}</div>
        </div>
        <div>
          <div class="text-xs text-[#95a5a6]">Success Rate</div>
          <div class="text-lg font-bold" :class="(metricsData.stats?.completion_rate || 0) > 80 ? 'text-[#27ae60]' : 'text-[#f39c12]'">
            {{ Math.round(metricsData.stats?.completion_rate || 0) }}%
          </div>
        </div>
        <div>
          <div class="text-xs text-[#95a5a6]">Avg Duration</div>
          <div class="text-lg font-bold text-[#e0e0e0]">{{ Math.round(metricsData.stats?.avg_duration_ms || 0) }}ms</div>
        </div>
        <div>
          <div class="text-xs text-[#95a5a6]">Slow Nodes</div>
          <div class="text-lg font-bold text-[#f39c12]">{{ metricsData.slow_nodes?.length || 0 }}</div>
        </div>
      </div>
      <div v-if="metricsData.slow_nodes?.length" class="mt-2 text-xs text-[#95a5a6]">
        Slow: {{ metricsData.slow_nodes.map(n => `${n.node_type} (${Math.round(n.avg_duration)}ms)`).join(', ') }}
      </div>
    </div>

    <!-- Cache Stats Panel -->
    <div v-if="showCacheStats && cacheData" class="px-4 py-3 bg-[#252525] border-b border-[#444]">
      <div class="grid grid-cols-4 gap-4 text-center">
        <div>
          <div class="text-xs text-[#95a5a6]">Hit Rate</div>
          <div class="text-lg font-bold text-[#27ae60]">{{ cacheData.hit_rate }}%</div>
        </div>
        <div>
          <div class="text-xs text-[#95a5a6]">Hits</div>
          <div class="text-lg font-bold text-[#e0e0e0]">{{ cacheData.hits }}</div>
        </div>
        <div>
          <div class="text-xs text-[#95a5a6]">Misses</div>
          <div class="text-lg font-bold text-[#e0e0e0]">{{ cacheData.misses }}</div>
        </div>
        <div>
          <div class="text-xs text-[#95a5a6]">Cache Keys</div>
          <div class="text-lg font-bold text-[#e0e0e0]">{{ cacheData.cache_keys }}</div>
        </div>
      </div>
      <div v-if="cacheData.node_types?.length" class="mt-2 text-xs text-[#95a5a6]">
        Node types: {{ cacheData.node_types.map(n => `${n.type} (${n.count})`).join(', ') }}
      </div>
    </div>

    <!-- Main Editor Area -->
    <div class="editor-main">
      <!-- Left Sidebar - Node Palette -->
      <div class="editor-sidebar-left">
        <h3>Available Nodes</h3>
        <div class="node-categories">
          <div v-for="category in nodeCategories" :key="category.name" class="node-category">
            <h4 :style="{ color: category.color }">{{ category.icon }} {{ category.name }}</h4>
            <div class="node-list">
              <div
                v-for="nodeType in category.nodes"
                :key="nodeType"
                class="node-item"
                draggable="true"
                @dragstart="handleDragStart(nodeType, $event)"
                @click="addNode(nodeType)"
              >
                {{ nodeType }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Center - Workflow Canvas (Phase 1: Simple list, Phase 2: Visual graph) -->
      <div class="editor-canvas">
        <div class="canvas-header">
          <h2>Workflow: {{ workflow.name }}</h2>
          <p class="workflow-description">{{ workflow.description || 'No description' }}</p>
        </div>

        <!-- Graph View (Phase 2 - Visual node graph) -->
        <div v-if="viewMode === 'graph'" class="graph-container" @drop="handleDrop" @dragover.prevent>
          <VueFlow
            :nodes="nodes"
            :edges="edges"
            :fit-view-on-init="true"
            @node-click="onNodeClick"
            @nodes-change="onNodesChange"
            @edges-change="onEdgesChange"
            class="vue-flow-canvas"
          >
            <Background pattern-color="#444" :gap="16" />
            <Controls />
            <MiniMap />
          </VueFlow>

          <div v-if="workflow.nodes.length === 0" class="empty-state-overlay">
            <p>👈 Drag nodes from the left to get started</p>
          </div>
        </div>

        <!-- List View (Phase 1 - Simple list) -->
        <div v-else class="nodes-container" @drop="handleDrop" @dragover.prevent">
          <div
            v-for="(node, index) in workflow.nodes"
            :key="index"
            class="workflow-node"
            :class="{ 'node-selected': selectedNodeIndex === index }"
            @click="selectNode(index)"
          >
            <div class="node-header">
              <span class="node-order">{{ index + 1 }}</span>
              <span class="node-type">{{ node.node_type }}</span>
              <div class="node-actions">
                <button @click.stop="moveNodeUp(index)" :disabled="index === 0" class="btn-icon" title="Move up">
                  ↑
                </button>
                <button @click.stop="moveNodeDown(index)" :disabled="index === workflow.nodes.length - 1" class="btn-icon" title="Move down">
                  ↓
                </button>
                <button @click.stop="deleteNode(index)" class="btn-icon btn-danger" title="Delete">
                  🗑️
                </button>
              </div>
            </div>
            <div class="node-config-summary" v-if="node.config && Object.keys(JSON.parse(node.config)).length > 0">
              {{ Object.keys(JSON.parse(node.config)).length }} config(s)
            </div>
          </div>

          <div v-if="workflow.nodes.length === 0" class="empty-state">
            <p>👈 Drag nodes from the left or click to add them</p>
          </div>
        </div>
      </div>

      <!-- Right Sidebar - Node Configuration -->
      <div class="editor-sidebar-right">
        <div v-if="selectedNodeIndex !== null && workflow.nodes[selectedNodeIndex]">
          <div class="config-header">
            <h3>Node Configuration</h3>
            <div class="config-meta">
              <span class="meta-label">Type:</span>
              <span class="meta-value">{{ workflow.nodes[selectedNodeIndex].node_type }}</span>
            </div>
            <div class="config-meta">
              <span class="meta-label">Order:</span>
              <span class="meta-value">#{{ selectedNodeIndex + 1 }}</span>
            </div>
          </div>

          <NodeConfigForm
            :node-type="workflow.nodes[selectedNodeIndex].node_type"
            :config="workflow.nodes[selectedNodeIndex].config"
            @update:config="updateNodeConfig"
          />
        </div>
        <div v-else class="empty-state">
          <p>Select a node to configure it</p>
        </div>
      </div>
    </div>

    <!-- Backups Modal -->
    <div v-if="showBackupsModal" class="modal-overlay" @click="showBackupsModal = false">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h2>Workflow Backups</h2>
          <button @click="showBackupsModal = false" class="btn-close">✕</button>
        </div>
        <div class="modal-body">
          <div v-if="loadingBackups" class="loading">Loading backups...</div>
          <div v-else-if="backups.length === 0" class="empty-state">
            <p>No backups found</p>
          </div>
          <div v-else class="backups-list">
            <div v-for="backup in backups" :key="backup.id" class="backup-item">
              <div class="backup-info">
                <span class="backup-type" :class="`backup-${backup.backup_type}`">
                  {{ backup.backup_type }}
                </span>
                <span class="backup-time">{{ formatDateTime(backup.created_at) }}</span>
                <span v-if="backup.description" class="backup-desc">{{ backup.description }}</span>
              </div>
              <div class="backup-actions">
                <button @click="restoreFromBackup(backup.id)" class="btn-primary btn-small">
                  Restore
                </button>
                <button @click="deleteBackup(backup.id)" class="btn-danger btn-small">
                  Delete
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { VueFlow, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import NodeConfigForm from '../components/NodeConfigForm.vue';
import axios from 'axios';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';

const route = useRoute();
const router = useRouter();

// State
const workflow = ref({
  id: null,
  name: '',
  description: '',
  active: true,
  nodes: []
});

const selectedNodeIndex = ref(null);
const hasUnsavedChanges = ref(false);
const isSaving = ref(false);
const lastBackupTime = ref(null);
const showBackupsModal = ref(false);
const backups = ref([]);
const loadingBackups = ref(false);

// Dry run state
const dryRunning = ref(false);
const dryRunResults = ref(null);

// Metrics & Cache state
const showMetrics = ref(false);
const metricsData = ref(null);
const showCacheStats = ref(false);
const cacheData = ref(null);

// View mode: 'list' or 'graph'
const viewMode = ref('graph');

// Vue Flow state
const nodes = ref([]);
const edges = ref([]);
const { onNodesChange, onEdgesChange, onConnect, addEdges, setNodes, setEdges, fitView } = useVueFlow();

// Auto-backup timer
let autoBackupInterval = null;

// Node categories for the palette
const nodeCategories = ref([
  {
    name: 'Sources',
    icon: '📁',
    color: '#3498db',
    nodes: ['RSSFeed', 'WebScraper', 'EmailFetch']
  },
  {
    name: 'AI Processing',
    icon: '🤖',
    color: '#9b59b6',
    nodes: ['AIFormatter', 'BatchProcessor', 'BiasRatingEnrich']
  },
  {
    name: 'Notifications',
    icon: '🔔',
    color: '#e74c3c',
    nodes: ['Pushover', 'EmailNotification', 'SlackNotification']
  },
  {
    name: 'Logic',
    icon: '⚡',
    color: '#f39c12',
    nodes: ['Conditional', 'Filter', 'Delay']
  },
  {
    name: 'Transform',
    icon: '🔄',
    color: '#1abc9c',
    nodes: ['Merge', 'Split', 'Transform']
  }
]);

// Lifecycle
onMounted(async () => {
  await loadWorkflow();
  startAutoBackup();
  window.addEventListener('beforeunload', handleBeforeUnload);
});

onBeforeUnmount(() => {
  stopAutoBackup();
  window.removeEventListener('beforeunload', handleBeforeUnload);
});

// Methods
async function loadWorkflow() {
  try {
    // Check if we're in create mode (no workflow ID)
    if (!route.params.id) {
      // Initialize blank workflow for create mode
      workflow.value = {
        id: null,
        name: 'New Workflow',
        description: '',
        active: true,
        schedule: '',
        error_handling: 'stop',
        nodes: []
      };
      hasUnsavedChanges.value = false;
      convertToGraphNodes();
      return;
    }

    // Edit mode - load existing workflow
    const response = await axios.get(`/api/workflows/${route.params.id}`);
    const data = response.data.data;

    workflow.value = {
      id: data.workflow.id,
      name: data.workflow.name,
      description: data.workflow.description,
      active: data.workflow.active,
      schedule: data.workflow.schedule,
      error_handling: data.workflow.error_handling,
      nodes: data.nodes.map(node => ({
        id: node.id,
        node_type: node.node_type,
        node_order: node.node_order,
        config: node.configs && node.configs.length > 0
          ? JSON.stringify(
              node.configs.reduce((acc, cfg) => {
                acc[cfg.config_key] = cfg.config_value;
                return acc;
              }, {}),
              null,
              2
            )
          : '{}'
      }))
    };

    hasUnsavedChanges.value = false;

    // Convert workflow nodes to graph nodes
    convertToGraphNodes();
  } catch (error) {
    console.error('Failed to load workflow:', error);
    alert('Failed to load workflow');
  }
}

// Convert workflow nodes array to Vue Flow nodes and edges
function convertToGraphNodes() {
  const flowNodes = [];
  const flowEdges = [];

  workflow.value.nodes.forEach((node, index) => {
    // Create node with auto-layout position
    flowNodes.push({
      id: `node-${index}`,
      type: 'default',
      position: { x: 100, y: index * 120 + 50 },
      data: {
        label: `${index + 1}. ${node.node_type}`,
        nodeData: node,
        nodeIndex: index
      },
      style: {
        background: getCategoryColor(node.node_type),
        color: '#fff',
        border: '2px solid #3498db',
        borderRadius: '8px',
        padding: '10px',
        minWidth: '180px'
      }
    });

    // Create edge connecting this node to the next one
    if (index < workflow.value.nodes.length - 1) {
      flowEdges.push({
        id: `edge-${index}`,
        source: `node-${index}`,
        target: `node-${index + 1}`,
        type: 'smoothstep',
        animated: true,
        style: { stroke: '#3498db', strokeWidth: 2 }
      });
    }
  });

  setNodes(flowNodes);
  setEdges(flowEdges);

  // Fit view after a short delay to ensure nodes are rendered
  setTimeout(() => {
    fitView({ padding: 0.2, duration: 300 });
  }, 100);
}

// Get color for node based on category
function getCategoryColor(nodeType) {
  const category = nodeCategories.value.find(cat =>
    cat.nodes.includes(nodeType)
  );

  if (!category) return '#34495e';

  const colorMap = {
    'Sources': '#3498db',
    'AI Processing': '#9b59b6',
    'Notifications': '#e74c3c',
    'Logic': '#f39c12',
    'Transform': '#1abc9c'
  };

  return colorMap[category.name] || '#34495e';
}

// Handle node selection in graph
function onNodeClick(event) {
  const nodeIndex = event.node.data.nodeIndex;
  selectedNodeIndex.value = nodeIndex;
}

// Toggle view mode
function toggleViewMode() {
  viewMode.value = viewMode.value === 'list' ? 'graph' : 'list';
  if (viewMode.value === 'graph') {
    convertToGraphNodes();
  }
}

function markUnsaved() {
  hasUnsavedChanges.value = true;
}

async function saveWorkflow() {
  isSaving.value = true;
  try {
    // Create a backup if editing existing workflow
    if (workflow.value.id) {
      await createBackup('pre_edit', 'Auto-backup before save');
    }

    // Prepare workflow data
    const nodes = workflow.value.nodes.map((node, index) => ({
      node_type: node.node_type,
      node_order: index + 1,
      config: node.config || '{}'
    }));

    const workflowData = {
      name: workflow.value.name,
      description: workflow.value.description,
      active: workflow.value.active,
      schedule: workflow.value.schedule,
      error_handling: workflow.value.error_handling,
      nodes: nodes
    };

    // Check if we're creating or updating
    if (workflow.value.id) {
      // Update existing workflow
      await axios.put(`/api/workflows/${workflow.value.id}`, workflowData);
      hasUnsavedChanges.value = false;
      lastBackupTime.value = new Date();
      alert('Workflow updated successfully!');
    } else {
      // Create new workflow
      const response = await axios.post('/api/workflows', workflowData);
      const newWorkflow = response.data.data;

      // Update workflow ID and navigate to edit route
      workflow.value.id = newWorkflow.id;
      hasUnsavedChanges.value = false;
      lastBackupTime.value = new Date();

      // Navigate to edit route with the new workflow ID
      router.replace(`/workflows/${newWorkflow.id}/edit`);
      alert('Workflow created successfully!');
    }
  } catch (error) {
    console.error('Failed to save workflow:', error);
    alert('Failed to save workflow: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    isSaving.value = false;
  }
}

async function createBackup(type = 'auto', description = null) {
  try {
    await axios.post(`/api/workflows/${workflow.value.id}/backups`, {
      backup_type: type,
      description: description
    });
  } catch (error) {
    console.error('Failed to create backup:', error);
  }
}

function startAutoBackup() {
  // Auto-backup every 30 seconds if there are unsaved changes
  autoBackupInterval = setInterval(async () => {
    if (hasUnsavedChanges.value && !isSaving.value) {
      await saveWorkflow();
    }
  }, 30000); // 30 seconds
}

function stopAutoBackup() {
  if (autoBackupInterval) {
    clearInterval(autoBackupInterval);
  }
}

function handleBeforeUnload(e) {
  if (hasUnsavedChanges.value) {
    e.preventDefault();
    e.returnValue = '';
  }
}

function goBack() {
  if (hasUnsavedChanges.value) {
    if (confirm('You have unsaved changes. Are you sure you want to leave?')) {
      router.push('/workflows');
    }
  } else {
    router.push('/workflows');
  }
}

function selectNode(index) {
  selectedNodeIndex.value = index;
}

function addNode(nodeType) {
  workflow.value.nodes.push({
    node_type: nodeType,
    node_order: workflow.value.nodes.length + 1,
    config: '{}'
  });
  markUnsaved();
  selectedNodeIndex.value = workflow.value.nodes.length - 1;

  // Update graph view if currently active
  if (viewMode.value === 'graph') {
    convertToGraphNodes();
  }
}

function deleteNode(index) {
  if (confirm('Delete this node?')) {
    workflow.value.nodes.splice(index, 1);
    if (selectedNodeIndex.value === index) {
      selectedNodeIndex.value = null;
    }
    markUnsaved();

    // Update graph view if currently active
    if (viewMode.value === 'graph') {
      convertToGraphNodes();
    }
  }
}

function moveNodeUp(index) {
  if (index > 0) {
    const temp = workflow.value.nodes[index];
    workflow.value.nodes[index] = workflow.value.nodes[index - 1];
    workflow.value.nodes[index - 1] = temp;
    selectedNodeIndex.value = index - 1;
    markUnsaved();

    // Update graph view if currently active
    if (viewMode.value === 'graph') {
      convertToGraphNodes();
    }
  }
}

function moveNodeDown(index) {
  if (index < workflow.value.nodes.length - 1) {
    const temp = workflow.value.nodes[index];
    workflow.value.nodes[index] = workflow.value.nodes[index + 1];
    workflow.value.nodes[index + 1] = temp;
    selectedNodeIndex.value = index + 1;
    markUnsaved();

    // Update graph view if currently active
    if (viewMode.value === 'graph') {
      convertToGraphNodes();
    }
  }
}

function formatNodeConfig(index) {
  try {
    const config = JSON.parse(workflow.value.nodes[index].config);
    workflow.value.nodes[index].config = JSON.stringify(config, null, 2);
  } catch (error) {
    alert('Invalid JSON in config');
  }
}

function updateNodeConfig(newConfig) {
  if (selectedNodeIndex.value !== null) {
    workflow.value.nodes[selectedNodeIndex.value].config = newConfig;
    markUnsaved();
  }
}

function handleDragStart(nodeType, event) {
  event.dataTransfer.setData('nodeType', nodeType);
}

function handleDrop(event) {
  event.preventDefault();
  const nodeType = event.dataTransfer.getData('nodeType');
  if (nodeType) {
    addNode(nodeType);
  }
}

async function testWorkflow() {
  if (hasUnsavedChanges.value) {
    if (!confirm('Save changes before testing?')) {
      return;
    }
    await saveWorkflow();
  }

  try {
    await axios.post(`/api/workflows/${workflow.value.id}/run`, {}, { params: { async: 'true' } });
    alert('Workflow test queued! Check executions page for results.');
  } catch (error) {
    console.error('Failed to test workflow:', error);
    alert('Failed to test workflow');
  }
}

async function runDryRun() {
  if (!workflow.value.id) return;
  dryRunning.value = true;
  try {
    const { data } = await axios.post(`/api/workflows/${workflow.value.id}/dry-run`);
    if (data.success) dryRunResults.value = data.data;
  } catch (error) {
    console.error('Dry run failed:', error);
    alert('Dry run failed');
  } finally { dryRunning.value = false; }
}

async function toggleMetrics() {
  showMetrics.value = !showMetrics.value;
  if (showMetrics.value && !metricsData.value && workflow.value.id) {
    try {
      const { data } = await axios.get(`/api/workflows/${workflow.value.id}/metrics`);
      if (data.success) metricsData.value = data.data;
    } catch (error) { console.error('Failed to load metrics:', error); }
  }
}

async function toggleCacheStats() {
  showCacheStats.value = !showCacheStats.value;
  if (showCacheStats.value && !cacheData.value && workflow.value.id) {
    try {
      const { data } = await axios.get(`/api/workflows/${workflow.value.id}/cache-stats`);
      if (data.success) cacheData.value = data.data;
    } catch (error) { console.error('Failed to load cache stats:', error); }
  }
}

async function loadBackups() {
  loadingBackups.value = true;
  try {
    const response = await axios.get(`/api/workflows/${workflow.value.id}/backups`);
    backups.value = response.data.data;
  } catch (error) {
    console.error('Failed to load backups:', error);
    alert('Failed to load backups');
  } finally {
    loadingBackups.value = false;
  }
}

async function restoreFromBackup(backupId) {
  if (!confirm('Restore from this backup? Current changes will be lost.')) {
    return;
  }

  try {
    await axios.post(`/api/workflows/${workflow.value.id}/backups/${backupId}/restore`);
    alert('Workflow restored successfully!');
    showBackupsModal.value = false;
    await loadWorkflow();
  } catch (error) {
    console.error('Failed to restore backup:', error);
    alert('Failed to restore backup');
  }
}

async function deleteBackup(backupId) {
  if (!confirm('Delete this backup?')) {
    return;
  }

  try {
    await axios.delete(`/api/workflows/backups/${backupId}`);
    await loadBackups();
  } catch (error) {
    console.error('Failed to delete backup:', error);
    alert('Failed to delete backup');
  }
}

// Watch for backups modal opening
watch(showBackupsModal, (newValue) => {
  if (newValue) {
    loadBackups();
  }
});

// Utility functions
function formatTimeAgo(date) {
  const seconds = Math.floor((new Date() - new Date(date)) / 1000);

  if (seconds < 60) return 'just now';
  if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
  if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
  return Math.floor(seconds / 86400) + 'd ago';
}

function formatDateTime(dateStr) {
  const date = new Date(dateStr);
  return date.toLocaleString();
}
</script>

<style scoped>
.workflow-editor {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #1a1a1a;
  color: #e0e0e0;
}

.editor-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  background: #2d2d2d;
  border-bottom: 2px solid #3498db;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.toolbar-left, .toolbar-center, .toolbar-right {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.workflow-name-input {
  font-size: 1.2rem;
  padding: 0.5rem 1rem;
  background: #1a1a1a;
  border: 2px solid #3498db;
  border-radius: 4px;
  color: #fff;
  min-width: 300px;
}

.workflow-name-input:focus {
  outline: none;
  border-color: #5dade2;
}

.badge-active, .badge-inactive {
  padding: 0.25rem 0.75rem;
  border-radius: 12px;
  font-size: 0.85rem;
  font-weight: 600;
}

.badge-active {
  background: #27ae60;
  color: white;
}

.badge-inactive {
  background: #7f8c8d;
  color: white;
}

.auto-backup-status {
  font-size: 0.9rem;
}

.status-saving {
  color: #3498db;
}

.status-unsaved {
  color: #f39c12;
}

.status-saved {
  color: #27ae60;
}

.btn-back, .btn-primary, .btn-secondary, .btn-danger, .btn-icon, .btn-small {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.2s;
}

.btn-back {
  background: #34495e;
  color: white;
}

.btn-back:hover {
  background: #2c3e50;
}

.btn-primary {
  background: #3498db;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #2980b9;
}

.btn-primary:disabled {
  background: #7f8c8d;
  cursor: not-allowed;
}

.btn-secondary {
  background: #95a5a6;
  color: white;
}

.btn-secondary:hover {
  background: #7f8c8d;
}

.btn-danger {
  background: #e74c3c;
  color: white;
}

.btn-danger:hover {
  background: #c0392b;
}

.btn-icon {
  padding: 0.25rem 0.5rem;
  background: transparent;
  color: #3498db;
  font-size: 1rem;
}

.btn-icon:hover:not(:disabled) {
  background: rgba(52, 152, 219, 0.1);
}

.btn-icon:disabled {
  color: #7f8c8d;
  cursor: not-allowed;
}

.btn-small {
  padding: 0.25rem 0.75rem;
  font-size: 0.85rem;
}

.editor-main {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.editor-sidebar-left {
  width: 250px;
  background: #2d2d2d;
  border-right: 1px solid #444;
  overflow-y: auto;
  padding: 1rem;
}

.editor-sidebar-right {
  width: 400px;
  background: #2d2d2d;
  border-left: 1px solid #444;
  overflow-y: auto;
  padding: 1rem;
}

.editor-canvas {
  flex: 1;
  overflow-y: auto;
  padding: 2rem;
  background: #1a1a1a;
}

.canvas-header h2 {
  color: #3498db;
  margin-bottom: 0.5rem;
}

.workflow-description {
  color: #95a5a6;
  margin-bottom: 2rem;
}

.node-categories h3, .config-panel h3 {
  color: #3498db;
  margin-bottom: 1rem;
}

.node-category {
  margin-bottom: 1.5rem;
}

.node-category h4 {
  font-size: 0.9rem;
  margin-bottom: 0.5rem;
}

.node-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.node-item {
  padding: 0.5rem;
  background: #34495e;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.9rem;
  transition: all 0.2s;
}

.node-item:hover {
  background: #3498db;
  transform: translateX(5px);
}

.nodes-container {
  min-height: 400px;
  padding: 1rem;
  background: #2d2d2d;
  border-radius: 8px;
  border: 2px dashed #444;
}

.workflow-node {
  background: #34495e;
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
  cursor: pointer;
  transition: all 0.2s;
  border: 2px solid transparent;
}

.workflow-node:hover {
  background: #3d566e;
  border-color: #3498db;
}

.workflow-node.node-selected {
  border-color: #3498db;
  box-shadow: 0 0 12px rgba(52, 152, 219, 0.5);
}

.node-header {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.node-order {
  font-weight: 700;
  color: #3498db;
  font-size: 1.2rem;
  min-width: 30px;
}

.node-type {
  flex: 1;
  font-weight: 600;
}

.node-actions {
  display: flex;
  gap: 0.25rem;
}

.node-config-summary {
  margin-top: 0.5rem;
  font-size: 0.85rem;
  color: #95a5a6;
}

.config-header {
  padding: 1rem;
  background: #34495e;
  border-radius: 8px 8px 0 0;
  margin-bottom: 1rem;
}

.config-header h3 {
  color: #3498db;
  margin: 0 0 0.75rem 0;
}

.config-meta {
  display: flex;
  gap: 0.5rem;
  margin: 0.25rem 0;
  font-size: 0.9rem;
}

.meta-label {
  color: #95a5a6;
  font-weight: 600;
}

.meta-value {
  color: #e0e0e0;
  font-family: 'Courier New', monospace;
}

.empty-state {
  text-align: center;
  padding: 3rem;
  color: #7f8c8d;
  font-size: 1.1rem;
}

/* Modal Styles */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.8);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: #2d2d2d;
  border-radius: 8px;
  width: 90%;
  max-width: 700px;
  max-height: 80vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem;
  border-bottom: 1px solid #444;
}

.modal-header h2 {
  color: #3498db;
  margin: 0;
}

.btn-close {
  background: transparent;
  border: none;
  color: #e0e0e0;
  font-size: 1.5rem;
  cursor: pointer;
  padding: 0;
  width: 30px;
  height: 30px;
}

.btn-close:hover {
  color: #e74c3c;
}

.modal-body {
  padding: 1.5rem;
  overflow-y: auto;
}

.backups-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.backup-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background: #34495e;
  border-radius: 4px;
}

.backup-info {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.backup-type {
  padding: 0.15rem 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
  display: inline-block;
  width: fit-content;
}

.backup-auto {
  background: #3498db;
  color: white;
}

.backup-manual {
  background: #9b59b6;
  color: white;
}

.backup-pre_edit {
  background: #f39c12;
  color: white;
}

.backup-time {
  font-size: 0.9rem;
  color: #95a5a6;
}

.backup-desc {
  font-size: 0.85rem;
  color: #bdc3c7;
}

.backup-actions {
  display: flex;
  gap: 0.5rem;
}

.loading {
  text-align: center;
  padding: 2rem;
  color: #95a5a6;
}

/* Graph View Styles */
.graph-container {
  position: relative;
  height: calc(100vh - 200px);
  background: #1a1a1a;
  border-radius: 8px;
  border: 2px solid #3498db;
  overflow: hidden;
}

.vue-flow-canvas {
  height: 100%;
  width: 100%;
}

.empty-state-overlay {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
  padding: 3rem;
  color: #7f8c8d;
  font-size: 1.1rem;
  pointer-events: none;
  z-index: 1;
}

/* Override Vue Flow dark theme colors */
:deep(.vue-flow__node) {
  cursor: pointer;
}

:deep(.vue-flow__node.selected) {
  box-shadow: 0 0 0 3px #3498db !important;
}

:deep(.vue-flow__edge-path) {
  stroke: #3498db;
  stroke-width: 2;
}

:deep(.vue-flow__edge.selected .vue-flow__edge-path) {
  stroke: #5dade2;
  stroke-width: 3;
}

:deep(.vue-flow__controls) {
  background: #2d2d2d;
  border: 1px solid #444;
}

:deep(.vue-flow__controls-button) {
  background: #34495e;
  border: none;
  color: #e0e0e0;
}

:deep(.vue-flow__controls-button:hover) {
  background: #3498db;
}

:deep(.vue-flow__minimap) {
  background: #2d2d2d;
  border: 1px solid #444;
}

:deep(.vue-flow__background) {
  background: #1a1a1a;
}
</style>

<template>
  <OpsPageWrapper
    title="Node Browser"
    subtitle="Available workflow components"
    section-code="04"
    color-scheme="lilac"
    :show-sidebar="true"
  >
    <!-- Header Actions -->
    <template #header-actions>
      <button
        @click="refreshNodes"
        class="ops-btn ops-btn-orange"
        :class="{ 'opacity-50': loading }"
        :disabled="loading"
      >
        <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh
      </button>
    </template>

    <!-- Main Content -->
    <div class="ops-nodes-content">
      <!-- Search and Filter Bar -->
      <div class="ops-search-panel">
        <div class="ops-search-bar">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search nodes by name or description..."
            class="ops-input"
          />
          <select v-model="filterType" class="ops-select">
            <option value="">All Types</option>
            <option value="ai">AI</option>
            <option value="api">API</option>
            <option value="notification">Notification</option>
            <option value="trigger">Trigger</option>
            <option value="transform">Transform</option>
            <option value="utility">Utility</option>
          </select>
        </div>
        <div class="ops-node-count">
          <span class="ops-count-value">{{ filteredNodes.length }}</span>
          <span class="ops-count-label">node{{ filteredNodes.length !== 1 ? 's' : '' }} available</span>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="ops-loading-container">
        <div class="ops-loading">
          <div class="ops-loading-dot"></div>
          <div class="ops-loading-dot"></div>
          <div class="ops-loading-dot"></div>
        </div>
        <div class="ops-loading-text">Scanning node registry...</div>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="ops-panel-v2 magenta">
        <div class="ops-panel-v2-header">
          <span class="ops-panel-v2-title">System Error</span>
        </div>
        <div class="ops-panel-v2-body">
          <p class="text-[var(--ops-peach)]">{{ error }}</p>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else-if="filteredNodes.length === 0" class="ops-empty-state">
        <div class="ops-empty-icon">&#128204;</div>
        <div class="ops-empty-title">No nodes found</div>
        <div class="ops-empty-text">Try adjusting your search or filter criteria</div>
      </div>

      <!-- Node Grid -->
      <div v-else class="ops-nodes-grid">
        <div
          v-for="node in filteredNodes"
          :key="node.name"
          @click="openNodeModal(node)"
          class="ops-node-card"
          :class="getNodeCardClass(node.type)"
        >
          <!-- Node Type Bar -->
          <div class="ops-node-type-bar" :class="getNodeBarClass(node.type)"></div>

          <!-- Node Content -->
          <div class="ops-node-content">
            <div class="ops-node-header">
              <h3 class="ops-node-name">{{ node.name }}</h3>
              <span class="ops-node-badge" :class="getNodeBadgeClass(node.type)">
                {{ node.type }}
              </span>
            </div>

            <p class="ops-node-description">{{ node.description }}</p>

            <div class="ops-node-meta">
              <span class="ops-node-params">
                {{ node.config.required.length }} required
                <template v-if="node.config.optional.length > 0">
                  • {{ node.config.optional.length }} optional
                </template>
              </span>
              <span class="ops-node-action">View Details →</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Node Documentation Modal -->
    <div
      v-if="showModal && selectedNode"
      class="ops-modal-overlay"
      @click.self="closeModal"
    >
      <div class="ops-modal ops-scroll">
        <!-- Modal Header -->
        <div class="ops-modal-header" :class="getNodeBarClass(selectedNode.type)">
          <div class="ops-modal-title-section">
            <h3 class="ops-modal-title">{{ selectedNode.name }}</h3>
            <span class="ops-modal-badge" :class="getNodeBadgeClass(selectedNode.type)">
              {{ selectedNode.type }}
            </span>
          </div>
          <button @click="closeModal" class="ops-btn ops-btn-red">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Modal Body -->
        <div class="ops-modal-body">
          <!-- Description -->
          <div class="ops-panel-v2 peach mb-4">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Description</span>
            </div>
            <div class="ops-panel-v2-body">
              <p class="text-[var(--ops-peach)]">{{ selectedNode.description }}</p>
            </div>
          </div>

          <!-- Required Configuration -->
          <div v-if="selectedNode.config.required.length > 0" class="ops-panel-v2 magenta mb-4">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Required Configuration</span>
              <span class="ops-badge ops-badge-red">{{ selectedNode.config.required.length }}</span>
            </div>
            <div class="ops-panel-v2-body space-y-3">
              <div
                v-for="param in selectedNode.config.required"
                :key="param.name"
                class="ops-param-card required"
              >
                <div class="ops-param-header">
                  <code class="ops-param-name">{{ param.name }}</code>
                  <span class="ops-param-type">{{ param.type }}</span>
                </div>
                <p class="ops-param-desc">{{ param.description }}</p>
              </div>
            </div>
          </div>

          <!-- Optional Configuration -->
          <div v-if="selectedNode.config.optional.length > 0" class="ops-panel-v2 blue mb-4">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Optional Configuration</span>
              <span class="ops-badge ops-badge-blue">{{ selectedNode.config.optional.length }}</span>
            </div>
            <div class="ops-panel-v2-body space-y-3">
              <div
                v-for="param in selectedNode.config.optional"
                :key="param.name"
                class="ops-param-card optional"
              >
                <div class="ops-param-header">
                  <code class="ops-param-name">{{ param.name }}</code>
                  <span class="ops-param-type">{{ param.type }}</span>
                </div>
                <p class="ops-param-desc">{{ param.description }}</p>
                <p v-if="param.default" class="ops-param-default">
                  Default: <code>{{ param.default }}</code>
                </p>
              </div>
            </div>
          </div>

          <!-- No Configuration -->
          <div v-if="selectedNode.config.required.length === 0 && selectedNode.config.optional.length === 0" class="ops-panel-v2 gold mb-4">
            <div class="ops-panel-v2-body">
              <p class="text-[var(--ops-peach)]">This node does not require any configuration parameters.</p>
            </div>
          </div>

          <!-- Output Structure -->
          <div class="ops-panel-v2 green mb-4">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Output Structure</span>
            </div>
            <div class="ops-panel-v2-body">
              <div class="space-y-2">
                <div v-for="(desc, key) in selectedNode.outputs" :key="key" class="ops-output-item">
                  <code class="ops-output-key">{{ key }}</code>
                  <span class="ops-output-desc">{{ desc }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Implementation -->
          <div class="ops-panel-v2 lilac">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Implementation</span>
            </div>
            <div class="ops-panel-v2-body">
              <code class="ops-class-name">{{ selectedNode.className }}</code>
            </div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="ops-modal-footer">
          <button @click="closeModal" class="ops-btn ops-btn-lilac">
            Close
          </button>
        </div>
      </div>
    </div>
  </OpsPageWrapper>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import OpsPageWrapper from '../components/layout/OpsPageWrapper.vue';
import api from '../utils/api';

// State
const nodes = ref([]);
const loading = ref(false);
const error = ref(null);
const searchQuery = ref('');
const filterType = ref('');
const showModal = ref(false);
const selectedNode = ref(null);

// Computed
const filteredNodes = computed(() => {
  let filtered = nodes.value;

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    filtered = filtered.filter(node =>
      node.name.toLowerCase().includes(query) ||
      node.description.toLowerCase().includes(query)
    );
  }

  if (filterType.value) {
    filtered = filtered.filter(node => node.type === filterType.value);
  }

  return filtered;
});

// Methods
const fetchNodes = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await api.get('/nodes/types');
    if (response.success) {
      nodes.value = response.data;
    } else {
      error.value = response.error?.message || 'Failed to fetch nodes';
    }
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Failed to fetch nodes';
  } finally {
    loading.value = false;
  }
};

const refreshNodes = () => fetchNodes();

const openNodeModal = (node) => {
  selectedNode.value = node;
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  selectedNode.value = null;
};

const getNodeCardClass = (type) => ({
  'ops-node-ai': type === 'ai',
  'ops-node-api': type === 'api',
  'ops-node-notification': type === 'notification',
  'ops-node-trigger': type === 'trigger',
  'ops-node-transform': type === 'transform',
  'ops-node-utility': type === 'utility',
});

const getNodeBarClass = (type) => ({
  'bg-grape': type === 'ai',
  'bg-sky': type === 'api',
  'bg-green': type === 'notification',
  'bg-gold': type === 'trigger',
  'bg-orange': type === 'transform',
  'bg-lilac': type === 'utility',
});

const getNodeBadgeClass = (type) => ({
  'ops-badge-grape': type === 'ai',
  'ops-badge-blue': type === 'api',
  'ops-badge-green': type === 'notification',
  'ops-badge-gold': type === 'trigger',
  'ops-badge-orange': type === 'transform',
  'ops-badge-lilac': type === 'utility',
});

// Lifecycle
onMounted(() => fetchNodes());
</script>

<style scoped>
/* Search Panel */
.ops-search-panel {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1.5rem;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
}

.ops-search-bar {
  display: flex;
  gap: 0.75rem;
  flex: 1;
  min-width: 300px;
}

.ops-input {
  flex: 1;
  background-color: var(--color-bg-secondary);
  border: 2px solid var(--ops-plum);
  border-radius: 0 15px 15px 0;
  color: var(--ops-peach);
  padding: 0.625rem 1rem;
  font-family: 'Antonio', sans-serif;
  font-size: 0.875rem;
  transition: border-color 0.15s ease;
}

.ops-input:focus {
  outline: none;
  border-color: var(--ops-lilac);
}

.ops-input::placeholder {
  color: var(--ops-violet);
}

.ops-select {
  background-color: var(--color-bg-secondary);
  border: 2px solid var(--ops-plum);
  border-radius: 15px;
  color: var(--ops-peach);
  padding: 0.625rem 1rem;
  font-family: 'Antonio', sans-serif;
  font-size: 0.875rem;
  cursor: pointer;
}

.ops-select:focus {
  outline: none;
  border-color: var(--ops-lilac);
}

.ops-node-count {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
}

.ops-count-value {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--ops-lilac);
}

.ops-count-label {
  font-size: 0.75rem;
  color: var(--ops-violet);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

/* Loading State */
.ops-loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
}

.ops-loading-text {
  margin-top: 1.5rem;
  color: var(--ops-lilac);
  font-size: 0.875rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

/* Empty State */
.ops-empty-state {
  text-align: center;
  padding: 4rem 2rem;
}

.ops-empty-icon {
  font-size: 4rem;
  opacity: 0.5;
  margin-bottom: 1rem;
}

.ops-empty-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--ops-peach);
  text-transform: uppercase;
  margin-bottom: 0.5rem;
}

.ops-empty-text {
  color: var(--ops-violet);
  font-size: 0.875rem;
}

/* Node Grid */
.ops-nodes-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 1rem;
}

.ops-node-card {
  display: flex;
  background-color: var(--color-bg-secondary);
  border-radius: 0 20px 20px 0;
  overflow: hidden;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
  border: 1px solid var(--ops-plum);
}

.ops-node-card:hover {
  transform: translateX(4px);
  box-shadow: -4px 0 20px rgba(0, 0, 0, 0.3);
  border-color: var(--ops-lilac);
}

.ops-node-type-bar {
  width: 8px;
  flex-shrink: 0;
}

.ops-node-content {
  flex: 1;
  padding: 1rem;
  display: flex;
  flex-direction: column;
}

.ops-node-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.ops-node-name {
  font-size: 1rem;
  font-weight: 700;
  color: var(--ops-peach);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 0;
}

.ops-node-badge {
  padding: 0.125rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.625rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  flex-shrink: 0;
}

.ops-badge-grape { background-color: var(--ops-grape); color: var(--ops-black); }
.ops-badge-gold { background-color: var(--ops-gold); color: var(--ops-black); }
.ops-badge-lilac { background-color: var(--ops-lilac); color: var(--ops-black); }

.ops-node-description {
  color: var(--ops-lilac);
  font-size: 0.8125rem;
  line-height: 1.4;
  margin: 0 0 auto;
  flex: 1;
}

.ops-node-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--ops-plum);
}

.ops-node-params {
  font-size: 0.6875rem;
  color: var(--ops-violet);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.ops-node-action {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--ops-lilac);
}

/* Background colors for type bars */
.bg-grape { background-color: var(--ops-grape); }
.bg-sky { background-color: var(--ops-sky); }
.bg-green { background-color: var(--ops-green); }
.bg-gold { background-color: var(--ops-gold); }
.bg-orange { background-color: var(--ops-orange); }
.bg-lilac { background-color: var(--ops-lilac); }

/* Modal Styles */
.ops-modal-overlay {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: 1rem;
}

.ops-modal {
  background-color: var(--color-bg-primary);
  border: 2px solid var(--ops-plum);
  border-radius: 0 25px 25px 0;
  max-width: 800px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
}

.ops-modal-header {
  position: sticky;
  top: 0;
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  z-index: 10;
}

.ops-modal-title-section {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.ops-modal-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin: 0;
}

.ops-modal-badge {
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
}

.ops-modal-body {
  padding: 1.5rem;
}

.ops-modal-footer {
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--ops-plum);
  display: flex;
  justify-content: flex-end;
}

/* Parameter Cards */
.ops-param-card {
  background-color: var(--color-bg-tertiary);
  border-radius: 0 12px 12px 0;
  padding: 0.75rem 1rem;
  border-left: 4px solid var(--ops-plum);
}

.ops-param-card.required {
  border-left-color: var(--ops-red-alert);
}

.ops-param-card.optional {
  border-left-color: var(--ops-sky);
}

.ops-param-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
}

.ops-param-name {
  font-family: monospace;
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--ops-peach);
}

.ops-param-type {
  font-size: 0.6875rem;
  color: var(--ops-violet);
  background-color: var(--color-bg-secondary);
  padding: 0.125rem 0.5rem;
  border-radius: 6px;
}

.ops-param-desc {
  color: var(--ops-lilac);
  font-size: 0.8125rem;
  margin: 0;
}

.ops-param-default {
  color: var(--ops-violet);
  font-size: 0.75rem;
  margin: 0.375rem 0 0;
}

.ops-param-default code {
  background-color: var(--color-bg-secondary);
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
  color: var(--ops-sky);
}

/* Output Items */
.ops-output-item {
  display: flex;
  gap: 0.75rem;
  align-items: baseline;
}

.ops-output-key {
  font-family: monospace;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--ops-green);
  flex-shrink: 0;
}

.ops-output-desc {
  color: var(--ops-lilac);
  font-size: 0.8125rem;
}

.ops-class-name {
  font-family: monospace;
  font-size: 0.75rem;
  color: var(--ops-sky);
  word-break: break-all;
}

/* Responsive */
@media (max-width: 768px) {
  .ops-search-panel {
    flex-direction: column;
    align-items: stretch;
  }

  .ops-search-bar {
    flex-direction: column;
    min-width: 0;
  }

  .ops-node-count {
    justify-content: center;
  }

  .ops-nodes-grid {
    grid-template-columns: 1fr;
  }
}
</style>

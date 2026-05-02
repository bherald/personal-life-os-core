<template>
  <OpsPageWrapper
    title="Execution History"
    subtitle="Workflow run monitoring"
    section-code="E1"
    color-scheme="orange"
    :show-sidebar="true"
  >
    <!-- Header Actions -->
    <template #header-actions>
      <button @click="clearFilters" class="ops-btn ops-btn-lilac">
        Clear Filters
      </button>
    </template>

    <!-- Main Content -->
    <div class="ops-executions-content">
      <!-- Stats Cards -->
      <div v-if="stats" class="ops-stats-row">
        <div class="ops-stat-block">
          <div class="ops-stat-value blue">{{ stats.total_runs }}</div>
          <div class="ops-stat-label">Total Runs</div>
        </div>
        <div class="ops-stat-block">
          <div class="ops-stat-value green">{{ stats.completed }}</div>
          <div class="ops-stat-label">Completed</div>
        </div>
        <div class="ops-stat-block">
          <div class="ops-stat-value" style="color: var(--ops-red-alert)">{{ stats.failed }}</div>
          <div class="ops-stat-label">Failed</div>
        </div>
        <div class="ops-stat-block">
          <div class="ops-stat-value orange">{{ stats.running }}</div>
          <div class="ops-stat-label">Running</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="ops-panel-v2 lilac mb-6">
        <div class="ops-panel-v2-header">
          <span class="ops-panel-v2-title">Filters</span>
        </div>
        <div class="ops-panel-v2-body">
          <div class="ops-filters-grid">
            <div class="ops-form-group">
              <label class="ops-label">Workflow</label>
              <select v-model="filters.workflow_id" @change="applyFilters" class="ops-select">
                <option :value="null">All Workflows</option>
                <option v-for="workflow in workflows" :key="workflow.id" :value="workflow.id">
                  {{ workflow.name }}
                </option>
              </select>
            </div>
            <div class="ops-form-group">
              <label class="ops-label">Status</label>
              <select v-model="filters.status" @change="applyFilters" class="ops-select">
                <option :value="null">All Statuses</option>
                <option value="running">Running</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
              </select>
            </div>
            <div class="ops-form-group">
              <label class="ops-label">Date From</label>
              <input type="date" v-model="filters.date_from" @change="applyFilters" class="ops-input" />
            </div>
            <div class="ops-form-group">
              <label class="ops-label">Date To</label>
              <input type="date" v-model="filters.date_to" @change="applyFilters" class="ops-input" />
            </div>
          </div>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="loading && !executions.length" class="ops-loading-container">
        <div class="ops-loading">
          <div class="ops-loading-dot"></div>
          <div class="ops-loading-dot"></div>
          <div class="ops-loading-dot"></div>
        </div>
        <div class="ops-loading-text">Loading executions...</div>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="ops-panel-v2 magenta">
        <div class="ops-panel-v2-body">
          <p class="text-peach">{{ error }}</p>
        </div>
      </div>

      <!-- Executions Table -->
      <div v-else-if="executions.length > 0" class="ops-panel-v2 blue">
        <div class="ops-panel-v2-header">
          <span class="ops-panel-v2-title">Executions</span>
          <span class="ops-badge ops-badge-blue">{{ executions.length }}</span>
        </div>
        <div class="ops-panel-v2-body p-0">
          <table class="ops-table">
            <thead>
              <tr>
                <th>Run ID</th>
                <th>Workflow</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Started At</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="execution in executions"
                :key="execution.id"
                @click="viewDetails(execution.id)"
                class="cursor-pointer"
              >
                <td class="font-bold">#{{ execution.id }}</td>
                <td>{{ execution.workflow_name }}</td>
                <td>
                  <span class="ops-status" :class="getStatusClass(execution.status)">
                    {{ execution.status }}
                  </span>
                </td>
                <td>{{ formatDuration(execution) }}</td>
                <td>{{ formatDate(execution.started_at) }}</td>
                <td>
                  <button
                    v-if="execution.status === 'failed'"
                    @click.stop="retryExecution(execution.id)"
                    :disabled="retrying === execution.id"
                    class="ops-btn ops-btn-blue text-xs"
                  >
                    {{ retrying === execution.id ? 'Retrying...' : 'Retry' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="ops-empty-state">
        <div class="ops-empty-icon">&#128203;</div>
        <div class="ops-empty-title">No executions found</div>
        <div class="ops-empty-text">Try adjusting your filters or run a workflow to see executions here</div>
      </div>
    </div>

    <!-- Execution Details Modal -->
    <div
      v-if="showDetailsModal"
      class="ops-modal-overlay"
      @click.self="closeDetailsModal"
    >
      <div class="ops-modal ops-modal-lg ops-scroll">
        <!-- Modal Header -->
        <div class="ops-modal-header bg-orange">
          <div class="ops-modal-title-section">
            <h3 class="ops-modal-title">Execution #{{ currentExecution?.run?.id }}</h3>
            <span class="text-xs opacity-70">{{ currentExecution?.run?.workflow_name }}</span>
          </div>
          <button @click="closeDetailsModal" class="ops-btn ops-btn-red">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Modal Body -->
        <div class="ops-modal-body">
          <div v-if="currentExecution">
            <!-- Run Overview -->
            <div class="ops-stats-row mb-6">
              <div class="ops-stat-block">
                <span class="ops-status" :class="getStatusClass(currentExecution.run.status)">
                  {{ currentExecution.run.status }}
                </span>
                <div class="ops-stat-label mt-2">Status</div>
              </div>
              <div class="ops-stat-block">
                <div class="ops-stat-value peach text-sm">{{ formatDate(currentExecution.run.started_at) }}</div>
                <div class="ops-stat-label">Started At</div>
              </div>
              <div class="ops-stat-block">
                <div class="ops-stat-value peach text-sm">{{ currentExecution.run.completed_at ? formatDate(currentExecution.run.completed_at) : 'N/A' }}</div>
                <div class="ops-stat-label">Completed At</div>
              </div>
              <div class="ops-stat-block">
                <div class="ops-stat-value lilac">{{ formatDuration(currentExecution.run) }}</div>
                <div class="ops-stat-label">Duration</div>
              </div>
            </div>

            <!-- Run Inputs -->
            <div v-if="currentExecution.inputs?.length" class="ops-panel-v2 peach mb-4">
              <div class="ops-panel-v2-header">
                <span class="ops-panel-v2-title">Workflow Inputs</span>
              </div>
              <div class="ops-panel-v2-body">
                <div v-for="input in currentExecution.inputs" :key="input.id" class="ops-input-row">
                  <span class="font-bold text-peach">{{ input.key }}:</span>
                  <span class="text-lilac ml-2">{{ input.value }}</span>
                </div>
              </div>
            </div>

            <!-- Run Outputs -->
            <div v-if="currentExecution.outputs?.length" class="ops-panel-v2 green mb-4">
              <div class="ops-panel-v2-header">
                <span class="ops-panel-v2-title">Workflow Outputs</span>
              </div>
              <div class="ops-panel-v2-body space-y-3">
                <JsonViewer
                  v-for="output in currentExecution.outputs"
                  :key="output.id"
                  :data="output.value"
                  :label="output.key"
                />
              </div>
            </div>

            <!-- Node Executions -->
            <div v-if="currentExecution.node_executions?.length" class="ops-panel-v2 blue">
              <div class="ops-panel-v2-header">
                <span class="ops-panel-v2-title">Node Executions</span>
                <span class="ops-badge ops-badge-blue">{{ currentExecution.node_executions.length }}</span>
              </div>
              <div class="ops-panel-v2-body space-y-3">
                <div
                  v-for="node in currentExecution.node_executions"
                  :key="node.id"
                  class="ops-node-execution"
                >
                  <!-- Node Header -->
                  <div
                    class="ops-node-header"
                    @click="toggleNodeDetails(node.id)"
                  >
                    <div class="ops-node-info">
                      <span class="ops-node-order">#{{ node.node_order }}</span>
                      <span class="ops-node-name">{{ node.node_name }}</span>
                      <span class="ops-node-type">({{ node.node_type }})</span>
                      <span class="ops-status" :class="getStatusClass(node.status)">
                        {{ node.status }}
                      </span>
                    </div>
                    <svg
                      class="w-5 h-5 transition-transform"
                      :class="{ 'rotate-180': expandedNodes.has(node.id) }"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                  </div>

                  <!-- Node Details (Expandable) -->
                  <div v-if="expandedNodes.has(node.id)" class="ops-node-details">
                    <div class="ops-node-meta">
                      <div>
                        <span class="ops-label">Started:</span>
                        <span class="text-peach">{{ formatDate(node.started_at) }}</span>
                      </div>
                      <div>
                        <span class="ops-label">Completed:</span>
                        <span class="text-peach">{{ node.completed_at ? formatDate(node.completed_at) : 'N/A' }}</span>
                      </div>
                      <div>
                        <span class="ops-label">Duration:</span>
                        <span class="text-peach">{{ formatDuration(node) }}</span>
                      </div>
                    </div>

                    <!-- Node Outputs -->
                    <div v-if="node.outputs?.length" class="mt-3">
                      <div class="ops-label mb-2">Outputs</div>
                      <div class="space-y-2">
                        <JsonViewer
                          v-for="output in node.outputs"
                          :key="output.id"
                          :data="output.value"
                          :label="output.key"
                        />
                      </div>
                    </div>

                    <!-- Error Message -->
                    <div v-if="node.error_message" class="ops-node-error">
                      <div class="ops-label">Error</div>
                      <pre class="ops-error-pre">{{ node.error_message }}</pre>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Loading State -->
          <div v-else class="ops-loading-container">
            <div class="ops-loading">
              <div class="ops-loading-dot"></div>
              <div class="ops-loading-dot"></div>
              <div class="ops-loading-dot"></div>
            </div>
            <div class="ops-loading-text">Loading execution details...</div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="ops-modal-footer">
          <button
            v-if="currentExecution?.run?.status === 'failed'"
            @click="retryFromModal"
            :disabled="retrying === currentExecution.run.id"
            class="ops-btn ops-btn-blue"
          >
            {{ retrying === currentExecution.run.id ? 'Retrying...' : 'Retry Execution' }}
          </button>
          <button @click="closeDetailsModal" class="ops-btn ops-btn-lilac">
            Close
          </button>
        </div>
      </div>
    </div>
  </OpsPageWrapper>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import OpsPageWrapper from '../components/layout/OpsPageWrapper.vue';
import { useExecutionsStore } from '../stores/executions';
import { useWorkflowsStore } from '../stores/workflows';
import { useTimezone } from '../composables/useTimezone';
import JsonViewer from '../components/JsonViewer.vue';

const executionsStore = useExecutionsStore();
const workflowsStore = useWorkflowsStore();
const { formatDate: formatDateTz, formatDuration: formatDurationTz } = useTimezone();

const showDetailsModal = ref(false);
const expandedNodes = ref(new Set());
const retrying = ref(null);

const filters = ref({
  workflow_id: null,
  status: null,
  date_from: null,
  date_to: null,
});

const executions = computed(() => executionsStore.executions);
const currentExecution = computed(() => executionsStore.currentExecution);
const stats = computed(() => executionsStore.stats);
const loading = computed(() => executionsStore.loading);
const error = computed(() => executionsStore.error);
const workflows = computed(() => workflowsStore.workflows);

onMounted(async () => {
  await Promise.all([
    executionsStore.fetchExecutions(),
    executionsStore.fetchStats(),
    workflowsStore.fetchWorkflows(),
  ]);
});

const applyFilters = async () => {
  executionsStore.setFilters(filters.value);
  await executionsStore.fetchExecutions(filters.value);
};

const clearFilters = async () => {
  filters.value = { workflow_id: null, status: null, date_from: null, date_to: null };
  executionsStore.clearFilters();
  await executionsStore.fetchExecutions();
};

const viewDetails = async (id) => {
  showDetailsModal.value = true;
  expandedNodes.value.clear();
  await executionsStore.fetchExecutionDetails(id);
};

const closeDetailsModal = () => {
  showDetailsModal.value = false;
  executionsStore.currentExecution = null;
  expandedNodes.value.clear();
};

const toggleNodeDetails = (nodeId) => {
  if (expandedNodes.value.has(nodeId)) {
    expandedNodes.value.delete(nodeId);
  } else {
    expandedNodes.value.add(nodeId);
  }
};

const retryExecution = async (id) => {
  if (confirm('Are you sure you want to retry this execution?')) {
    retrying.value = id;
    const result = await executionsStore.retryExecution(id);
    retrying.value = null;
    if (!result.success) {
      alert(`Failed to retry execution: ${result.error}`);
    }
  }
};

const retryFromModal = async () => {
  if (currentExecution.value?.run?.id) {
    await retryExecution(currentExecution.value.run.id);
    closeDetailsModal();
  }
};

const getStatusClass = (status) => {
  const classes = {
    running: 'ops-status-warning',
    completed: 'ops-status-ok',
    failed: 'ops-status-alert',
  };
  return classes[status] || '';
};

const formatDate = (dateString) => formatDateTz(dateString);
const formatDuration = (execution) => {
  if (!execution.started_at) return 'N/A';
  return formatDurationTz(execution.started_at, execution.completed_at);
};
</script>

<style scoped>
/* Stats Row */
.ops-stats-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
  .ops-stats-row {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* Filters Grid */
.ops-filters-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
}

@media (max-width: 1024px) {
  .ops-filters-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 640px) {
  .ops-filters-grid {
    grid-template-columns: 1fr;
  }
}

.ops-form-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ops-label {
  color: var(--ops-lilac);
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

.ops-select,
.ops-input {
  background-color: var(--color-bg-primary);
  border: 2px solid var(--ops-plum);
  border-radius: 0 12px 12px 0;
  color: var(--ops-peach);
  padding: 0.5rem 0.75rem;
  font-family: 'Antonio', sans-serif;
  font-size: 0.8125rem;
}

.ops-select:focus,
.ops-input:focus {
  outline: none;
  border-color: var(--ops-lilac);
}

/* Loading & Empty States */
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

/* Table Styles */
.p-0 { padding: 0 !important; }

/* Node Execution Styles */
.ops-node-execution {
  background-color: var(--color-bg-tertiary);
  border-radius: 0 15px 15px 0;
  border-left: 4px solid var(--ops-plum);
  overflow: hidden;
}

.ops-node-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.ops-node-header:hover {
  background-color: var(--color-bg-hover);
}

.ops-node-info {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.ops-node-order {
  color: var(--ops-violet);
  font-size: 0.75rem;
}

.ops-node-name {
  color: var(--ops-peach);
  font-weight: 600;
  font-size: 0.875rem;
}

.ops-node-type {
  color: var(--ops-violet);
  font-size: 0.75rem;
}

.ops-node-details {
  padding: 1rem;
  background-color: var(--color-bg-secondary);
  border-top: 1px solid var(--ops-plum);
}

.ops-node-meta {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

@media (max-width: 640px) {
  .ops-node-meta {
    grid-template-columns: 1fr;
  }
}

.ops-node-error {
  margin-top: 1rem;
  padding: 0.75rem;
  background-color: rgba(204, 0, 0, 0.1);
  border-left: 3px solid var(--ops-red-alert);
  border-radius: 0 10px 10px 0;
}

.ops-error-pre {
  color: var(--ops-peach);
  font-size: 0.75rem;
  white-space: pre-wrap;
  margin: 0.5rem 0 0;
}

/* Modal */
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
  max-width: 900px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.ops-modal-lg {
  max-width: 1100px;
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

.bg-orange { background-color: var(--ops-orange); }

.ops-modal-title-section {
  display: flex;
  flex-direction: column;
}

.ops-modal-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin: 0;
}

.ops-modal-body {
  padding: 1.5rem;
  flex: 1;
  overflow-y: auto;
}

.ops-modal-footer {
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--ops-plum);
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

/* Utilities */
.cursor-pointer { cursor: pointer; }
.font-bold { font-weight: 700; }
.text-peach { color: var(--ops-peach); }
.text-lilac { color: var(--ops-lilac); }
.text-xs { font-size: 0.75rem; }
.text-sm { font-size: 0.875rem; }
.ml-2 { margin-left: 0.5rem; }
.mt-2 { margin-top: 0.5rem; }
.mt-3 { margin-top: 0.75rem; }
.mb-4 { margin-bottom: 1rem; }
.mb-6 { margin-bottom: 1.5rem; }
.space-y-2 > * + * { margin-top: 0.5rem; }
.space-y-3 > * + * { margin-top: 0.75rem; }
.rotate-180 { transform: rotate(180deg); }
.transition-transform { transition: transform 0.15s ease; }
</style>

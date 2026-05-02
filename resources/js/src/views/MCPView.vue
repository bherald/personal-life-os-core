<template>
  <OpsPageWrapper
    title="MCP Tool Calling"
    subtitle="Model Context Protocol Interface"
    section-code="M1"
    color-scheme="sky"
    :show-sidebar="true"
  >
    <!-- Header Actions -->
    <template #header-actions>
      <button @click="refreshTools" class="ops-btn ops-btn-orange">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh
      </button>
    </template>

    <!-- Main Content -->
    <div class="ops-mcp-content">
      <!-- Status Stats -->
      <div class="ops-stats-row">
        <div class="ops-stat-block">
          <div class="ops-stat-value" :class="status.available ? 'green' : 'text-red'">
            {{ status.available ? 'ONLINE' : 'OFFLINE' }}
          </div>
          <div class="ops-stat-label">Tool Calling</div>
        </div>
        <div class="ops-stat-block">
          <div class="ops-stat-value blue">{{ status.total_tools || 0 }}</div>
          <div class="ops-stat-label">Total Tools</div>
        </div>
        <div class="ops-stat-block">
          <div class="ops-stat-value peach">{{ activeServerCount }}</div>
          <div class="ops-stat-label">Active Servers</div>
        </div>
      </div>

      <!-- Section Divider -->
      <div class="ops-section-divider">
        <div class="ops-section-line"></div>
        <span class="ops-section-title">MCP Servers</span>
        <div class="ops-section-line"></div>
      </div>

      <!-- Servers Status -->
      <div class="ops-panel-v2 lilac mb-6">
        <div class="ops-panel-v2-header">
          <span class="ops-panel-v2-title">Server Configuration</span>
        </div>
        <div class="ops-panel-v2-body">
          <div class="ops-servers-list">
            <div
              v-for="(server, name) in servers"
              :key="name"
              class="ops-server-item"
              :class="{ 'active': server.enabled }"
            >
              <div class="ops-server-bar" :class="server.enabled ? 'bg-green' : 'bg-dim'"></div>
              <div class="ops-server-content">
                <div class="ops-server-header">
                  <span class="ops-server-name">{{ name.toUpperCase() }}</span>
                  <div class="ops-server-badges">
                    <span class="ops-status" :class="server.enabled ? 'ops-status-ok' : 'ops-status-warning'">
                      {{ server.enabled ? 'Enabled' : 'Disabled' }}
                    </span>
                    <span class="ops-badge ops-badge-blue">{{ server.tools || 0 }} tools</span>
                  </div>
                </div>
                <p class="ops-server-desc">{{ server.description }}</p>
              </div>
              <button
                @click="toggleServer(name, !server.enabled)"
                class="ops-btn"
                :class="server.enabled ? 'ops-btn-red' : 'ops-btn-green'"
              >
                {{ server.enabled ? 'Disable' : 'Enable' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Section Divider -->
      <div class="ops-section-divider">
        <div class="ops-section-line"></div>
        <span class="ops-section-title">Tool Catalog</span>
        <div class="ops-section-line"></div>
      </div>

      <!-- Tool Catalog -->
      <div class="ops-panel-v2 blue mb-6">
        <div class="ops-panel-v2-header">
          <span class="ops-panel-v2-title">Available Tools</span>
        </div>
        <div class="ops-panel-v2-body">
          <div v-for="(serverTools, serverName) in toolsByServer" :key="serverName" class="ops-tool-group">
            <div class="ops-tool-group-header">
              <span class="ops-tool-group-name">{{ serverName }}</span>
              <span class="ops-badge ops-badge-blue">{{ serverTools.length }} tools</span>
            </div>
            <div class="ops-tools-grid">
              <div
                v-for="tool in serverTools"
                :key="tool.name"
                class="ops-tool-card"
                @click="selectTool(tool)"
              >
                <div class="ops-tool-name">{{ tool.name }}</div>
                <div class="ops-tool-desc">{{ tool.description }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Section Divider -->
      <div class="ops-section-divider">
        <div class="ops-section-line"></div>
        <span class="ops-section-title">Tool Tester</span>
        <div class="ops-section-line"></div>
      </div>

      <!-- Tool Tester -->
      <div class="ops-panel-v2 peach">
        <div class="ops-panel-v2-header">
          <span class="ops-panel-v2-title">Direct Tool Call</span>
        </div>
        <div class="ops-panel-v2-body">
          <div class="ops-tester-form">
            <!-- Select Server -->
            <div class="ops-form-group">
              <label class="ops-label">Server</label>
              <select v-model="testServer" @change="onServerChange" class="ops-select">
                <option value="">-- Select a server --</option>
                <option v-for="(serverTools, serverName) in toolsByServer" :key="serverName" :value="serverName">
                  {{ serverName }} ({{ serverTools.length }} tools)
                </option>
              </select>
            </div>

            <!-- Select Tool -->
            <div v-if="testServer" class="ops-form-group">
              <label class="ops-label">Tool</label>
              <select v-model="testToolName" @change="onToolChange" class="ops-select">
                <option value="">-- Select a tool --</option>
                <option v-for="tool in toolsByServer[testServer]" :key="tool.name" :value="tool.name">
                  {{ tool.name }}
                </option>
              </select>
            </div>

            <!-- Tool Description -->
            <div v-if="testToolName && currentTestTool" class="ops-tool-info">
              <div class="ops-tool-info-name">{{ currentTestTool.name }}</div>
              <div class="ops-tool-info-desc">{{ currentTestTool.description }}</div>
            </div>

            <!-- Parameters JSON Editor -->
            <div v-if="testToolName" class="ops-form-group">
              <label class="ops-label">Parameters (JSON)</label>
              <textarea
                v-model="testParameters"
                rows="6"
                class="ops-textarea"
                placeholder='{"param1": "value1"}'
              ></textarea>
            </div>

            <!-- Call Tool Button -->
            <button
              @click="callDirectTool"
              :disabled="!testServer || !testToolName || callingTool"
              class="ops-btn ops-btn-orange w-full"
              :class="{ 'opacity-50': !testServer || !testToolName || callingTool }"
            >
              <svg v-if="callingTool" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ callingTool ? 'Calling...' : 'Call Tool' }}
            </button>
          </div>

          <!-- Result -->
          <div v-if="testResult" class="ops-result-panel">
            <div class="ops-result-header">
              <span class="ops-result-label">Result</span>
              <div class="ops-result-actions">
                <span class="ops-result-duration">{{ testResult.duration_ms }}ms</span>
                <button @click="copyResult" class="ops-btn ops-btn-lilac text-xs">
                  {{ resultCopied ? 'Copied!' : 'Copy' }}
                </button>
              </div>
            </div>
            <pre class="ops-result-code">{{ JSON.stringify(testResult.response, null, 2) }}</pre>
          </div>

          <!-- Error -->
          <div v-if="testError" class="ops-error-panel">
            <div class="ops-error-indicator"></div>
            <div class="ops-error-content">
              <span class="ops-error-title">Error</span>
              <span class="ops-error-text">{{ testError }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Selected Tool Details Modal -->
    <div v-if="selectedTool" class="ops-modal-overlay" @click.self="selectedTool = null">
      <div class="ops-modal ops-scroll">
        <div class="ops-modal-header bg-sky">
          <h3 class="ops-modal-title">{{ selectedTool.name }}</h3>
          <button @click="selectedTool = null" class="ops-btn ops-btn-red">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="ops-modal-body">
          <div class="ops-panel-v2 peach mb-4">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Server</span>
            </div>
            <div class="ops-panel-v2-body">
              <p class="text-peach">{{ selectedTool.server }}</p>
            </div>
          </div>

          <div class="ops-panel-v2 lilac mb-4">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Description</span>
            </div>
            <div class="ops-panel-v2-body">
              <p class="text-peach">{{ selectedTool.description }}</p>
            </div>
          </div>

          <div v-if="selectedTool.parameters" class="ops-panel-v2 blue mb-4">
            <div class="ops-panel-v2-header">
              <span class="ops-panel-v2-title">Parameters</span>
            </div>
            <div class="ops-panel-v2-body">
              <pre class="ops-code">{{ JSON.stringify(selectedTool.parameters, null, 2) }}</pre>
            </div>
          </div>

          <button @click="testSelectedTool" class="ops-btn ops-btn-orange w-full">
            Test This Tool
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

const status = ref({});
const servers = ref({});
const tools = ref([]);
const testResult = ref(null);
const testError = ref(null);
const selectedTool = ref(null);

// Tool tester state
const testServer = ref('');
const testToolName = ref('');
const testParameters = ref('{}');
const currentTestTool = ref(null);
const callingTool = ref(false);
const resultCopied = ref(false);

const activeServerCount = computed(() => {
  return Object.values(servers.value).filter(s => s.enabled).length;
});

const toolsByServer = computed(() => {
  const grouped = {};
  tools.value.forEach(tool => {
    const server = tool.server || 'unknown';
    if (!grouped[server]) grouped[server] = [];
    grouped[server].push(tool);
  });
  return grouped;
});

const fetchStatus = async () => {
  try {
    const response = await api.get('/mcp/status');
    status.value = response;
  } catch (err) {
    console.error('Failed to fetch status:', err);
  }
};

const fetchServers = async () => {
  try {
    const response = await api.get('/mcp/servers');
    servers.value = response;
  } catch (err) {
    console.error('Failed to fetch servers:', err);
  }
};

const fetchTools = async () => {
  try {
    const response = await api.get('/mcp/tools');
    tools.value = response.tools || [];
  } catch (err) {
    console.error('Failed to fetch tools:', err);
  }
};

const refreshTools = async () => {
  await api.post('/mcp/clear-cache');
  await fetchTools();
  await fetchStatus();
};

const toggleServer = async (name, enabled) => {
  try {
    await api.put(`/mcp/servers/${name}`, { enabled });
    await fetchServers();
    await refreshTools();
  } catch (err) {
    console.error('Failed to toggle server:', err);
  }
};

const selectTool = (tool) => {
  selectedTool.value = tool;
};

const testSelectedTool = () => {
  if (selectedTool.value) {
    testServer.value = selectedTool.value.server;
    testToolName.value = selectedTool.value.name;
    onToolChange();
    selectedTool.value = null;
  }
};

const onServerChange = () => {
  testToolName.value = '';
  testParameters.value = '{}';
  currentTestTool.value = null;
  testResult.value = null;
  testError.value = null;
};

const onToolChange = () => {
  const tool = toolsByServer.value[testServer.value]?.find(t => t.name === testToolName.value);
  currentTestTool.value = tool;

  if (tool?.parameters?.properties) {
    const defaultParams = {};
    Object.keys(tool.parameters.properties).forEach(key => {
      const prop = tool.parameters.properties[key];
      if (prop.type === 'string') defaultParams[key] = prop.default || '';
      else if (prop.type === 'number' || prop.type === 'integer') defaultParams[key] = prop.default || 0;
      else if (prop.type === 'boolean') defaultParams[key] = prop.default || false;
      else if (prop.type === 'array') defaultParams[key] = prop.default || [];
      else if (prop.type === 'object') defaultParams[key] = prop.default || {};
    });
    testParameters.value = JSON.stringify(defaultParams, null, 2);
  } else {
    testParameters.value = '{}';
  }

  testResult.value = null;
  testError.value = null;
};

const callDirectTool = async () => {
  if (!testServer.value || !testToolName.value) return;

  testResult.value = null;
  testError.value = null;
  callingTool.value = true;

  try {
    const params = JSON.parse(testParameters.value);
    const response = await api.post('/mcp/call-direct', {
      server: testServer.value,
      tool: testToolName.value,
      params: params
    });
    testResult.value = response;
  } catch (err) {
    if (err instanceof SyntaxError) {
      testError.value = 'Invalid JSON in parameters: ' + err.message;
    } else {
      testError.value = err.response?.data?.error || err.message || 'Failed to call tool';
    }
  } finally {
    callingTool.value = false;
  }
};

const copyResult = async () => {
  if (!testResult.value) return;
  try {
    await navigator.clipboard.writeText(JSON.stringify(testResult.value.response, null, 2));
    resultCopied.value = true;
    setTimeout(() => { resultCopied.value = false; }, 2000);
  } catch (err) {
    console.error('Failed to copy:', err);
  }
};

onMounted(() => {
  fetchStatus();
  fetchServers();
  fetchTools();
});
</script>

<style scoped>
/* Stats Row */
.ops-stats-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
  .ops-stats-row {
    grid-template-columns: 1fr;
  }
}

.text-red {
  color: var(--ops-red-alert);
}

/* Section Divider */
.ops-section-divider {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 1.5rem 0;
}

.ops-section-line {
  flex: 1;
  height: 4px;
  background-color: var(--ops-plum);
  border-radius: 2px;
}

.ops-section-title {
  color: var(--ops-lilac);
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  white-space: nowrap;
  padding: 0.25rem 0.75rem;
  background-color: var(--color-bg-secondary);
  border-radius: 10px;
}

/* Servers List */
.ops-servers-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.ops-server-item {
  display: flex;
  align-items: center;
  gap: 1rem;
  background-color: var(--color-bg-tertiary);
  border-radius: 0 15px 15px 0;
  overflow: hidden;
}

.ops-server-bar {
  width: 6px;
  align-self: stretch;
}

.bg-green { background-color: var(--ops-green); }
.bg-dim { background-color: var(--ops-plum); }
.bg-sky { background-color: var(--ops-sky); }

.ops-server-content {
  flex: 1;
  padding: 0.75rem 0;
}

.ops-server-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.ops-server-name {
  font-weight: 700;
  color: var(--ops-peach);
  font-size: 0.875rem;
}

.ops-server-badges {
  display: flex;
  gap: 0.5rem;
}

.ops-server-desc {
  color: var(--ops-violet);
  font-size: 0.75rem;
  margin-top: 0.25rem;
}

/* Tool Groups */
.ops-tool-group {
  margin-bottom: 1.5rem;
}

.ops-tool-group:last-child {
  margin-bottom: 0;
}

.ops-tool-group-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.ops-tool-group-name {
  font-weight: 700;
  color: var(--ops-peach);
  font-size: 0.875rem;
  text-transform: uppercase;
}

.ops-tools-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 0.75rem;
}

.ops-tool-card {
  background-color: var(--color-bg-tertiary);
  border-left: 4px solid var(--ops-sky);
  border-radius: 0 12px 12px 0;
  padding: 0.75rem 1rem;
  cursor: pointer;
  transition: all 0.15s ease;
}

.ops-tool-card:hover {
  background-color: var(--color-bg-hover);
  border-left-color: var(--ops-orange);
  transform: translateX(4px);
}

.ops-tool-name {
  font-weight: 600;
  color: var(--ops-peach);
  font-size: 0.8125rem;
  margin-bottom: 0.25rem;
}

.ops-tool-desc {
  color: var(--ops-violet);
  font-size: 0.6875rem;
  line-height: 1.3;
}

/* Tester Form */
.ops-tester-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.ops-form-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ops-label {
  color: var(--ops-lilac);
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

.ops-select {
  background-color: var(--color-bg-primary);
  border: 2px solid var(--ops-plum);
  border-radius: 0 15px 15px 0;
  color: var(--ops-peach);
  padding: 0.625rem 1rem;
  font-family: 'Antonio', sans-serif;
  font-size: 0.875rem;
  cursor: pointer;
}

.ops-select:focus {
  outline: none;
  border-color: var(--ops-peach);
}

.ops-textarea {
  background-color: var(--color-bg-primary);
  border: 2px solid var(--ops-plum);
  border-radius: 0 15px 15px 0;
  color: var(--ops-peach);
  padding: 0.75rem 1rem;
  font-family: monospace;
  font-size: 0.75rem;
  resize: vertical;
}

.ops-textarea:focus {
  outline: none;
  border-color: var(--ops-peach);
}

.ops-tool-info {
  background-color: var(--color-bg-tertiary);
  border-left: 4px solid var(--ops-sky);
  border-radius: 0 12px 12px 0;
  padding: 0.75rem 1rem;
}

.ops-tool-info-name {
  font-weight: 600;
  color: var(--ops-sky);
  font-size: 0.875rem;
  margin-bottom: 0.25rem;
}

.ops-tool-info-desc {
  color: var(--ops-lilac);
  font-size: 0.8125rem;
}

/* Result Panel */
.ops-result-panel {
  margin-top: 1rem;
  background-color: var(--color-bg-tertiary);
  border-left: 4px solid var(--ops-green);
  border-radius: 0 15px 15px 0;
  overflow: hidden;
}

.ops-result-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  background-color: var(--ops-plum);
}

.ops-result-label {
  color: var(--ops-lilac);
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
}

.ops-result-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.ops-result-duration {
  color: var(--ops-sky);
  font-size: 0.75rem;
  font-weight: 700;
}

.ops-result-code {
  padding: 1rem;
  color: var(--ops-peach);
  font-family: monospace;
  font-size: 0.6875rem;
  overflow-x: auto;
  max-height: 300px;
  overflow-y: auto;
  margin: 0;
}

/* Error Panel */
.ops-error-panel {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  margin-top: 1rem;
  padding: 1rem;
  background-color: var(--color-bg-secondary);
  border-left: 4px solid var(--ops-red-alert);
  border-radius: 0 15px 15px 0;
}

.ops-error-indicator {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background-color: var(--ops-red-alert);
  flex-shrink: 0;
  animation: ops-pulse 1.5s ease-in-out infinite;
}

.ops-error-content {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.ops-error-title {
  color: var(--ops-red-alert);
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
}

.ops-error-text {
  color: var(--ops-peach);
  font-size: 0.8125rem;
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
  max-width: 700px;
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
}

.ops-code {
  color: var(--ops-sky);
  font-family: monospace;
  font-size: 0.6875rem;
  overflow-x: auto;
  margin: 0;
}

.text-peach {
  color: var(--ops-peach);
}

.w-full {
  width: 100%;
}

/* Animations */
@keyframes ops-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.animate-spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>

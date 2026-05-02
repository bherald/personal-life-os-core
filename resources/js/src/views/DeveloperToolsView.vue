<template>
  <OpsPageWrapper
    title="Developer Tools"
    subtitle="System diagnostics and utilities"
    section-code="DEV"
    color-scheme="magenta"
    :show-sidebar="true"
  >
    <!-- Alert slot for errors -->
    <template #alert v-if="diagnosticsError">
      <div class="ops-alert ops-alert-error">
        {{ diagnosticsError }}
      </div>
    </template>

    <div class="space-y-6">
      <!-- Failed Jobs Monitor Section -->
      <div class="ops-panel">
        <div class="ops-panel-header">
          <div class="ops-panel-header-bar bg-ops-alert"></div>
          <h3 class="ops-panel-title">FAILED JOBS MONITOR</h3>
          <button
            @click="loadFailedJobs"
            :disabled="loadingFailedJobs"
            class="ops-button ops-button-small ops-button-sky ml-auto"
          >
            <svg v-if="loadingFailedJobs" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span v-else>REFRESH</span>
          </button>
        </div>
        <div class="ops-panel-content">
          <!-- Loading State -->
          <div v-if="loadingFailedJobs && failedJobs.length === 0" class="text-center py-8">
            <div class="ops-spinner"></div>
            <p class="mt-2 text-ops-text-muted">LOADING FAILED JOBS...</p>
          </div>

          <!-- Empty State -->
          <div v-else-if="failedJobs.length === 0" class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-ops-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-semibold text-ops-text">NO FAILED JOBS</h3>
            <p class="mt-1 text-sm text-ops-text-muted">All queue jobs are running successfully</p>
          </div>

          <!-- Failed Jobs List -->
          <div v-else class="space-y-3">
            <div v-for="job in failedJobs" :key="job.id" class="ops-failed-job-card">
              <div class="flex justify-between items-start mb-2">
                <div class="flex-1">
                  <h4 class="font-semibold text-ops-alert">{{ job.queue }}</h4>
                  <p class="text-xs text-ops-text-muted mt-1">FAILED: {{ new Date(job.failed_at).toLocaleString() }}</p>
                  <p class="text-xs text-ops-text-muted">CONNECTION: {{ job.connection }}</p>
                </div>
                <div class="flex gap-2">
                  <button
                    @click="retryJob(job.id)"
                    :disabled="retryingJob === job.id"
                    class="ops-button ops-button-small ops-button-green"
                  >
                    {{ retryingJob === job.id ? 'RETRYING...' : 'RETRY' }}
                  </button>
                  <button
                    @click="deleteJob(job.id)"
                    :disabled="deletingJob === job.id"
                    class="ops-button ops-button-small ops-button-alert"
                  >
                    {{ deletingJob === job.id ? 'DELETING...' : 'DELETE' }}
                  </button>
                </div>
              </div>

              <!-- Exception Details (Collapsible) -->
              <div class="mt-3">
                <button
                  @click="toggleJobException(job.id)"
                  class="text-xs text-ops-sky hover:text-ops-sky-light flex items-center gap-1"
                >
                  <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-90': expandedJobs.has(job.id) }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                  </svg>
                  {{ expandedJobs.has(job.id) ? 'HIDE' : 'SHOW' }} EXCEPTION DETAILS
                </button>
                <div v-if="expandedJobs.has(job.id)" class="mt-2 ops-exception-details">
                  <pre class="text-xs text-ops-text whitespace-pre-wrap overflow-x-auto">{{ job.exception }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- OAuth Client Management Section -->
      <div class="ops-panel">
        <div class="ops-panel-header">
          <div class="ops-panel-header-bar bg-ops-gold"></div>
          <h3 class="ops-panel-title">API TOKEN MANAGEMENT</h3>
          <div class="flex gap-2 ml-auto">
            <button
              @click="showCreateTokenModal = true"
              class="ops-button ops-button-small ops-button-green"
            >
              CREATE TOKEN
            </button>
            <button
              @click="loadTokens"
              :disabled="loadingTokens"
              class="ops-button ops-button-small ops-button-sky"
            >
              <svg v-if="loadingTokens" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span v-else>REFRESH</span>
            </button>
          </div>
        </div>
        <div class="ops-panel-content">
          <!-- Loading State -->
          <div v-if="loadingTokens && accessTokens.length === 0" class="text-center py-8">
            <div class="ops-spinner"></div>
            <p class="mt-2 text-ops-text-muted">LOADING TOKENS...</p>
          </div>

          <!-- Empty State -->
          <div v-else-if="accessTokens.length === 0" class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-ops-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-semibold text-ops-text">NO API TOKENS</h3>
            <p class="mt-1 text-sm text-ops-text-muted">Create an API token to access the API programmatically</p>
          </div>

          <!-- Tokens List -->
          <div v-else class="space-y-3">
            <div v-for="token in accessTokens" :key="token.id" class="ops-token-card">
              <div class="flex justify-between items-start">
                <div class="flex-1">
                  <h4 class="font-semibold text-ops-gold">{{ token.name }}</h4>
                  <p class="text-xs text-ops-text-muted mt-1">CREATED: {{ new Date(token.created_at).toLocaleString() }}</p>
                  <p class="text-xs text-ops-text-muted">
                    EXPIRES: {{ token.expires_at ? new Date(token.expires_at).toLocaleString() : 'NEVER' }}
                  </p>
                  <p v-if="token.revoked" class="text-xs text-ops-alert font-semibold mt-1">REVOKED</p>
                </div>
                <button
                  @click="revokeToken(token.id)"
                  :disabled="revokingToken === token.id || token.revoked"
                  class="ops-button ops-button-small ops-button-alert"
                >
                  {{ revokingToken === token.id ? 'REVOKING...' : token.revoked ? 'REVOKED' : 'REVOKE' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- System Diagnostics Section -->
      <div class="ops-panel">
        <div class="ops-panel-header">
          <div class="ops-panel-header-bar bg-ops-sky"></div>
          <h3 class="ops-panel-title">SYSTEM DIAGNOSTICS</h3>
          <button
            @click="loadDiagnostics"
            :disabled="loadingDiagnostics"
            class="ops-button ops-button-small ops-button-sky ml-auto"
          >
            <svg v-if="loadingDiagnostics" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span v-else>REFRESH</span>
          </button>
        </div>
        <div class="ops-panel-content">
          <!-- Loading State -->
          <div v-if="loadingDiagnostics && !diagnostics" class="text-center py-8">
            <div class="ops-spinner"></div>
            <p class="mt-2 text-ops-text-muted">LOADING DIAGNOSTICS...</p>
          </div>

          <!-- Diagnostics Data -->
          <div v-else-if="diagnostics" class="space-y-6">
            <!-- System Info -->
            <div>
              <h4 class="ops-section-title">SYSTEM INFORMATION</h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="ops-stat-card">
                  <p class="ops-stat-label">LARAVEL VERSION</p>
                  <p class="ops-stat-value text-ops-sky">{{ diagnostics.laravel_version }}</p>
                </div>
                <div class="ops-stat-card">
                  <p class="ops-stat-label">PHP VERSION</p>
                  <p class="ops-stat-value text-ops-sky">{{ diagnostics.php_version }}</p>
                </div>
                <div class="ops-stat-card">
                  <p class="ops-stat-label">ENVIRONMENT</p>
                  <p class="ops-stat-value text-ops-peach">{{ diagnostics.environment }}</p>
                </div>
                <div class="ops-stat-card">
                  <p class="ops-stat-label">DEBUG MODE</p>
                  <span
                    :class="diagnostics.debug_mode ? 'ops-badge ops-badge-warning' : 'ops-badge ops-badge-success'"
                  >
                    {{ diagnostics.debug_mode ? 'ENABLED' : 'DISABLED' }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Database Info -->
            <div>
              <h4 class="ops-section-title">DATABASE</h4>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="ops-stat-card">
                  <p class="ops-stat-label">CONNECTION</p>
                  <p class="ops-stat-value text-ops-lilac">{{ diagnostics.database.connection }}</p>
                </div>
                <div class="ops-stat-card">
                  <p class="ops-stat-label">DRIVER</p>
                  <p class="ops-stat-value text-ops-lilac">{{ diagnostics.database.driver }}</p>
                </div>
                <div class="ops-stat-card">
                  <p class="ops-stat-label">STATUS</p>
                  <span
                    :class="diagnostics.database.status === 'connected' ? 'ops-badge ops-badge-success' : 'ops-badge ops-badge-error'"
                  >
                    {{ diagnostics.database.status.toUpperCase() }}
                  </span>
                </div>
              </div>
              <div v-if="diagnostics.database.error" class="mt-2 ops-output-error">
                <p class="text-xs">{{ diagnostics.database.error }}</p>
              </div>
            </div>

            <!-- Cache & Queue Info -->
            <div>
              <h4 class="ops-section-title">CACHE & QUEUE</h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="ops-stat-card">
                  <p class="ops-stat-label">CACHE DRIVER</p>
                  <p class="ops-stat-value text-ops-gold">{{ diagnostics.cache.driver }}</p>
                </div>
                <div class="ops-stat-card">
                  <p class="ops-stat-label">QUEUE CONNECTION</p>
                  <p class="ops-stat-value text-ops-gold">{{ diagnostics.queue.connection }}</p>
                </div>
              </div>
            </div>

            <!-- Scheduler Info -->
            <div>
              <h4 class="ops-section-title">SCHEDULER</h4>
              <div class="ops-stat-card">
                <p class="ops-stat-label">ACTIVE SCHEDULED WORKFLOWS</p>
                <p class="ops-stat-value text-ops-orange">{{ diagnostics.scheduler.active_scheduled_workflows }}</p>
              </div>
            </div>

            <!-- Storage Info -->
            <div>
              <h4 class="ops-section-title">STORAGE</h4>
              <div class="ops-stat-card">
                <p class="ops-stat-label">FREE STORAGE SPACE</p>
                <p class="ops-stat-value text-ops-green">{{ formatStorageSize(diagnostics.disk_space.storage) }} GB</p>
              </div>
            </div>

            <!-- Paths Info -->
            <div>
              <h4 class="ops-section-title">PATHS</h4>
              <div class="space-y-2">
                <div class="ops-path-card">
                  <p class="ops-stat-label">BASE PATH</p>
                  <p class="text-sm font-mono text-ops-text break-all">{{ diagnostics.paths.base }}</p>
                </div>
                <div class="ops-path-card">
                  <p class="ops-stat-label">STORAGE PATH</p>
                  <p class="text-sm font-mono text-ops-text break-all">{{ diagnostics.paths.storage }}</p>
                </div>
                <div class="ops-path-card">
                  <p class="ops-stat-label">PUBLIC PATH</p>
                  <p class="text-sm font-mono text-ops-text break-all">{{ diagnostics.paths.public }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Create Token Modal -->
    <div v-if="showCreateTokenModal" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center z-50">
      <div class="ops-modal">
        <div class="ops-modal-header">
          <div class="ops-modal-header-bar bg-ops-gold"></div>
          <h3 class="ops-modal-title">CREATE API TOKEN</h3>
        </div>
        <div class="ops-modal-content">
          <div class="space-y-4">
            <div>
              <label class="ops-label">TOKEN NAME</label>
              <input
                v-model="newTokenName"
                type="text"
                placeholder="e.g., Mobile App Token"
                class="ops-input"
              />
            </div>

            <!-- Success - Show Token (only shown once) -->
            <div v-if="createdToken" class="ops-output-success">
              <p class="text-sm font-semibold text-green-300 mb-2">TOKEN CREATED SUCCESSFULLY</p>
              <p class="text-xs text-green-400 mb-2">Copy this token now - it won't be shown again!</p>
              <div class="bg-black/50 border border-green-500/50 rounded p-2 font-mono text-xs break-all text-green-300">
                {{ createdToken }}
              </div>
              <button
                @click="copyToken"
                class="mt-2 w-full ops-button ops-button-green"
              >
                {{ tokenCopied ? 'COPIED!' : 'COPY TO CLIPBOARD' }}
              </button>
            </div>

            <div class="flex gap-3">
              <button
                v-if="!createdToken"
                @click="createToken"
                :disabled="!newTokenName || creatingToken"
                class="flex-1 ops-button ops-button-gold"
              >
                {{ creatingToken ? 'CREATING...' : 'CREATE' }}
              </button>
              <button
                @click="closeCreateTokenModal"
                class="flex-1 ops-button ops-button-secondary"
              >
                {{ createdToken ? 'DONE' : 'CANCEL' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </OpsPageWrapper>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../utils/api';
import OpsPageWrapper from '../components/layout/OpsPageWrapper.vue';

// Diagnostics State
const diagnostics = ref(null);
const diagnosticsError = ref('');
const loadingDiagnostics = ref(true);

// Failed Jobs State
const failedJobs = ref([]);
const loadingFailedJobs = ref(false);
const retryingJob = ref(null);
const deletingJob = ref(null);
const expandedJobs = ref(new Set());

// OAuth Token State
const accessTokens = ref([]);
const loadingTokens = ref(false);
const revokingToken = ref(null);
const showCreateTokenModal = ref(false);
const newTokenName = ref('');
const creatingToken = ref(false);
const createdToken = ref('');
const tokenCopied = ref(false);

// Load diagnostics
const loadDiagnostics = async () => {
  try {
    loadingDiagnostics.value = true;
    diagnosticsError.value = '';

    const response = await api.get('/dev-tools/diagnostics');

    if (response.success) {
      diagnostics.value = response.data;
    } else {
      diagnosticsError.value = 'Failed to load diagnostics';
    }
  } catch (err) {
    console.error('Error loading diagnostics:', err);
    diagnosticsError.value = err.response?.data?.error?.message || 'Failed to load diagnostics';
  } finally {
    loadingDiagnostics.value = false;
  }
};

// Format storage size
const formatStorageSize = (gb) => {
  if (gb === null || gb === undefined) return 'N/A';
  return gb.toFixed(2);
};

// Load failed jobs
const loadFailedJobs = async () => {
  try {
    loadingFailedJobs.value = true;
    const response = await api.get('/queue/failed');
    if (response.success) {
      failedJobs.value = response.data || [];
    }
  } catch (err) {
    console.error('Error loading failed jobs:', err);
  } finally {
    loadingFailedJobs.value = false;
  }
};

// Retry failed job
const retryJob = async (id) => {
  try {
    retryingJob.value = id;
    const response = await api.post(`/queue/retry/${id}`);
    if (response.success) {
      await loadFailedJobs();
    }
  } catch (err) {
    console.error('Error retrying job:', err);
    alert('Failed to retry job');
  } finally {
    retryingJob.value = null;
  }
};

// Delete failed job
const deleteJob = async (id) => {
  if (!confirm('Are you sure you want to delete this failed job?')) return;

  try {
    deletingJob.value = id;
    const response = await api.delete(`/queue/failed/${id}`);
    if (response.success) {
      await loadFailedJobs();
    }
  } catch (err) {
    console.error('Error deleting job:', err);
    alert('Failed to delete job');
  } finally {
    deletingJob.value = null;
  }
};

// Toggle job exception visibility
const toggleJobException = (id) => {
  if (expandedJobs.value.has(id)) {
    expandedJobs.value.delete(id);
  } else {
    expandedJobs.value.add(id);
  }
};

// Load API tokens
const loadTokens = async () => {
  try {
    loadingTokens.value = true;
    const response = await api.get('/oauth/tokens');
    if (response.success) {
      accessTokens.value = response.data || [];
    }
  } catch (err) {
    console.error('Error loading tokens:', err);
  } finally {
    loadingTokens.value = false;
  }
};

// Create API token
const createToken = async () => {
  if (!newTokenName.value) return;

  try {
    creatingToken.value = true;
    const response = await api.post('/oauth/tokens', { name: newTokenName.value });
    if (response.success) {
      createdToken.value = response.data.access_token;
      await loadTokens();
    }
  } catch (err) {
    console.error('Error creating token:', err);
    alert('Failed to create token');
  } finally {
    creatingToken.value = false;
  }
};

// Revoke API token
const revokeToken = async (id) => {
  if (!confirm('Are you sure you want to revoke this token?')) return;

  try {
    revokingToken.value = id;
    const response = await api.delete(`/oauth/tokens/${id}`);
    if (response.success) {
      await loadTokens();
    }
  } catch (err) {
    console.error('Error revoking token:', err);
    alert('Failed to revoke token');
  } finally {
    revokingToken.value = null;
  }
};

// Copy token to clipboard
const copyToken = async () => {
  try {
    await navigator.clipboard.writeText(createdToken.value);
    tokenCopied.value = true;
    setTimeout(() => {
      tokenCopied.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy token:', err);
  }
};

// Close create token modal
const closeCreateTokenModal = () => {
  showCreateTokenModal.value = false;
  newTokenName.value = '';
  createdToken.value = '';
  tokenCopied.value = false;
};

// Load data on mount
onMounted(async () => {
  await Promise.all([
    loadDiagnostics(),
    loadFailedJobs(),
    loadTokens()
  ]);
});
</script>

<style scoped>
/* Ops Console-styled form elements */
.ops-label {
  @apply block text-xs font-semibold text-ops-peach mb-2 tracking-wider;
  font-family: var(--ops-font);
}

.ops-input {
  @apply w-full px-4 py-2 bg-black/50 border-2 border-ops-frame rounded text-ops-text;
  @apply focus:outline-none focus:border-ops-orange focus:ring-1 focus:ring-ops-orange/50;
}

.ops-select {
  @apply w-full px-4 py-2 bg-black/50 border-2 border-ops-frame rounded text-ops-text;
  @apply focus:outline-none focus:border-ops-orange focus:ring-1 focus:ring-ops-orange/50;
}

.ops-select option {
  @apply bg-black text-ops-text;
}

/* Output containers */
.ops-output-error {
  @apply bg-red-900/30 border-2 border-red-500/50 rounded p-4 text-red-200;
}

.ops-output-success {
  @apply bg-green-900/30 border-2 border-green-500/50 rounded p-4 text-green-400;
}

/* Failed job card */
.ops-failed-job-card {
  @apply border-2 border-ops-alert/50 rounded p-4 bg-black/30;
}

/* Token card */
.ops-token-card {
  @apply border-2 border-ops-gold/30 rounded p-4 bg-black/30 hover:border-ops-gold/60 transition-colors;
}

/* Exception details */
.ops-exception-details {
  @apply bg-black/50 border border-ops-frame rounded p-3;
}

/* Section title */
.ops-section-title {
  @apply text-sm font-semibold text-ops-peach mb-3 tracking-wider;
  font-family: var(--ops-font);
}

/* Stat card */
.ops-stat-card {
  @apply bg-black/30 border border-ops-frame/50 rounded p-4;
}

.ops-stat-label {
  @apply text-xs text-ops-text-muted mb-1 tracking-wider;
  font-family: var(--ops-font);
}

.ops-stat-value {
  @apply text-lg font-semibold;
  font-family: var(--ops-font);
}

/* Path card */
.ops-path-card {
  @apply bg-black/30 border border-ops-frame/50 rounded p-3;
}

/* Badges */
.ops-badge {
  @apply px-2 py-1 text-xs font-semibold rounded;
  font-family: var(--ops-font);
}

.ops-badge-success {
  @apply bg-ops-green/20 text-ops-green border border-ops-green/50;
}

.ops-badge-warning {
  @apply bg-yellow-500/20 text-yellow-400 border border-yellow-500/50;
}

.ops-badge-error {
  @apply bg-red-500/20 text-red-400 border border-red-500/50;
}

/* Button variants */
.ops-button-magenta {
  @apply bg-ops-magenta hover:bg-ops-magenta/80 text-black;
}

.ops-button-lilac {
  @apply bg-ops-lilac hover:bg-ops-lilac/80 text-black;
}

.ops-button-gold {
  @apply bg-ops-gold hover:bg-ops-gold/80 text-black;
}

.ops-button-green {
  @apply bg-ops-green hover:bg-ops-green/80 text-black;
}

.ops-button-alert {
  @apply bg-ops-alert hover:bg-ops-alert/80 text-black;
}

.ops-button-sky {
  @apply bg-ops-sky hover:bg-ops-sky/80 text-black;
}

.ops-button-secondary {
  @apply bg-ops-frame hover:bg-ops-frame/80 text-black;
}

.ops-button-small {
  @apply px-3 py-1.5 text-xs;
}

/* Modal */
.ops-modal {
  @apply bg-black border-4 border-ops-gold rounded-lg max-w-md w-full mx-4;
}

.ops-modal-header {
  @apply flex items-center gap-4 px-6 py-4 border-b-2 border-ops-frame;
}

.ops-modal-header-bar {
  @apply w-4 h-8 rounded-full;
}

.ops-modal-title {
  @apply text-xl font-bold text-ops-gold tracking-wider;
  font-family: var(--ops-font);
}

.ops-modal-content {
  @apply p-6;
}

/* Spinner */
.ops-spinner {
  @apply inline-block animate-spin rounded-full h-8 w-8 border-4 border-ops-orange border-t-transparent;
}
</style>

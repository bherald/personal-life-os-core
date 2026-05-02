<template>
  <div class="max-w-7xl mx-auto px-4 py-6">
    <div class="mb-6">
      <h2 class="text-3xl font-bold text-gray-200">🏥 System Diagnostics</h2>
      <p class="text-gray-400 mt-1">Advanced monitoring and diagnostic tools</p>
    </div>

    <!-- Tabs -->
    <div class="mb-6">
      <div class="border-b-2 border-ops-plum">
        <nav class="-mb-px flex space-x-8 overflow-x-auto">
          <button @click="activeTab = 'health'"
                  :class="activeTab === 'health' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            System Health
          </button>
          <button @click="activeTab = 'errors'"
                  :class="activeTab === 'errors' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            Error Tracking
          </button>
          <button @click="activeTab = 'workflows'"
                  :class="activeTab === 'workflows' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            Workflows
          </button>
          <button @click="activeTab = 'services'"
                  :class="activeTab === 'services' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            Services
          </button>
          <button @click="activeTab = 'backups'"
                  :class="activeTab === 'backups' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            Backups
          </button>
        </nav>
      </div>
    </div>

    <!-- System Health Tab -->
    <div v-show="activeTab === 'health'" class="space-y-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-lg p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-2xl font-semibold text-ops-peach uppercase tracking-wide">System Health</h3>
          <button @click="loadHealth" :disabled="loading" class="bg-ops-orange text-black px-4 py-2 rounded-r-full hover:bg-ops-peach disabled:opacity-50 font-semibold uppercase">
            Refresh
          </button>
        </div>

        <div v-if="health" class="space-y-4">
          <!-- Health Score -->
          <div class="text-center py-6 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet">
            <div class="text-6xl font-bold" :class="getHealthClass(health.health_status)">
              {{ health.health_score }}/100
            </div>
            <div class="text-xl mt-2 capitalize text-ops-peach">{{ health.health_status }}</div>
            <div class="text-sm text-ops-text-muted mt-1">{{ health.timestamp }}</div>
          </div>

          <!-- Service Checks Grid -->
          <div>
            <h4 class="text-lg font-semibold mb-3 text-ops-lilac uppercase tracking-wide">Service Status</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div v-for="(check, name) in health.checks" :key="name"
                   class="border-2 rounded-r-lg p-4"
                   :class="check.healthy ? 'border-ops-green bg-ops-green/10' : 'border-ops-alert bg-ops-alert/10'">
                <div class="flex items-center justify-between">
                  <span class="font-medium capitalize text-ops-text">{{ formatServiceName(name) }}</span>
                  <span :class="check.healthy ? 'text-ops-green' : 'text-ops-alert'">
                    {{ check.healthy ? '✅' : '❌' }}
                  </span>
                </div>
                <div class="text-sm text-ops-text-muted mt-2">
                  Score: {{ check.score }}/100
                </div>
                <div v-if="check.response_time_ms" class="text-xs text-ops-text-muted">
                  {{ check.response_time_ms }}ms
                </div>
                <div v-if="check.error" class="text-xs text-ops-alert mt-1 truncate" :title="check.error">
                  {{ check.error.substring(0, 50) }}...
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else-if="loading" class="text-center py-12">
          <div class="text-ops-text-muted">Loading health data...</div>
        </div>
      </div>
    </div>

    <!-- Error Tracking Tab -->
    <div v-show="activeTab === 'errors'" class="space-y-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-lg p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-2xl font-semibold text-ops-peach uppercase tracking-wide">Error Tracking</h3>
          <div class="flex items-center space-x-2">
            <select v-model="errorPeriod" @change="loadErrors" class="px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-peach">
              <option value="1 hour">Last Hour</option>
              <option value="24 hours">Last 24 Hours</option>
              <option value="7 days">Last 7 Days</option>
              <option value="30 days">Last 30 Days</option>
            </select>
            <button @click="loadErrors" :disabled="loading" class="bg-ops-orange text-black px-4 py-2 rounded-r-full hover:bg-ops-peach disabled:opacity-50 font-semibold uppercase">
              Refresh
            </button>
          </div>
        </div>

        <div v-if="errors" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="text-center p-4 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet">
              <p class="text-sm text-ops-text-muted uppercase">Error Rate</p>
              <p class="text-2xl font-bold text-ops-peach">{{ errors.error_rate }}/hr</p>
            </div>
            <div class="text-center p-4 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet">
              <p class="text-sm text-ops-text-muted uppercase">Spike Detected</p>
              <p class="text-2xl font-bold" :class="errors.spike_detected ? 'text-ops-alert' : 'text-ops-green'">
                {{ errors.spike_detected ? 'Yes' : 'No' }}
              </p>
            </div>
            <div class="text-center p-4 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet">
              <p class="text-sm text-ops-text-muted uppercase">Top Errors</p>
              <p class="text-2xl font-bold text-ops-peach">{{ errors.top_errors?.length || 0 }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Workflow Diagnostics Tab -->
    <div v-show="activeTab === 'workflows'" class="space-y-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-lg p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-2xl font-semibold text-ops-peach uppercase tracking-wide">Workflow Health</h3>
          <button @click="loadWorkflows" :disabled="loading" class="bg-ops-orange text-black px-4 py-2 rounded-r-full hover:bg-ops-peach disabled:opacity-50 font-semibold uppercase">
            Refresh
          </button>
        </div>

        <div v-if="workflows" class="space-y-4">
          <div v-if="workflows.summary" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="text-center p-4 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet">
              <p class="text-sm text-ops-text-muted uppercase">Total Workflows</p>
              <p class="text-2xl font-bold text-ops-peach">{{ workflows.summary.total_workflows || 0 }}</p>
            </div>
            <div class="text-center p-4 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet">
              <p class="text-sm text-ops-text-muted uppercase">Healthy</p>
              <p class="text-2xl font-bold text-ops-green">{{ workflows.summary.healthy_count || 0 }}</p>
            </div>
            <div class="text-center p-4 bg-ops-plum/20 rounded-r-lg border-2 border-ops-violet">
              <p class="text-sm text-ops-text-muted uppercase">Avg Success Rate</p>
              <p class="text-2xl font-bold text-ops-peach">{{ workflows.summary.avg_success_rate?.toFixed(2) || 0 }}%</p>
            </div>
          </div>
        </div>
      </div>
    </div>


    <!-- Services Tab -->
    <div v-show="activeTab === 'services'" class="space-y-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-lg p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-2xl font-semibold text-ops-peach uppercase tracking-wide">Integration Services</h3>
          <button @click="loadServices" :disabled="loading" class="bg-ops-orange text-black px-4 py-2 rounded-r-full hover:bg-ops-peach disabled:opacity-50 font-semibold uppercase">
            Refresh
          </button>
        </div>

        <div v-if="services" class="space-y-6">
          <!-- Joplin Health -->
          <div class="border-2 rounded-r-lg p-4" :class="services.joplin?.status === 'healthy' ? 'border-ops-green bg-ops-green/10' : services.joplin?.status === 'degraded' ? 'border-ops-butterscotch bg-ops-butterscotch/10' : 'border-ops-alert bg-ops-alert/10'">
            <div class="flex items-center justify-between mb-3">
              <h4 class="text-lg font-semibold text-ops-peach">Joplin Sync</h4>
              <span class="px-3 py-1 rounded-full text-sm font-medium uppercase"
                    :class="services.joplin?.status === 'healthy' ? 'bg-ops-green/20 text-ops-green' : services.joplin?.status === 'degraded' ? 'bg-ops-butterscotch/20 text-ops-butterscotch' : 'bg-ops-alert/20 text-ops-alert'">
                {{ services.joplin?.status || 'unknown' }}
              </span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <span class="text-ops-text-muted">Last Sync:</span>
                <div class="font-medium text-ops-text">{{ services.joplin?.last_sync || 'Never' }}</div>
              </div>
              <div>
                <span class="text-ops-text-muted">Connection:</span>
                <div class="font-medium">{{ services.joplin?.connection_ok ? '✅ OK' : '❌ Failed' }}</div>
              </div>
              <div v-if="services.joplin?.queue_stats">
                <span class="text-ops-text-muted">Queue Pending:</span>
                <div class="font-medium text-ops-text">{{ services.joplin.queue_stats.pending || 0 }}</div>
              </div>
              <div v-if="services.joplin?.queue_stats">
                <span class="text-ops-text-muted">Queue Failed:</span>
                <div class="font-medium" :class="services.joplin.queue_stats.failed > 0 ? 'text-ops-alert' : 'text-ops-text'">{{ services.joplin.queue_stats.failed || 0 }}</div>
              </div>
            </div>
            <div v-if="services.joplin?.error" class="mt-2 text-sm text-ops-alert">
              Error: {{ services.joplin.error }}
            </div>
          </div>

          <!-- Joplin Queue -->
          <div v-if="services.joplin_queue" class="border-2 rounded-r-lg p-4 border-ops-violet bg-ops-plum/10">
            <h4 class="text-lg font-semibold mb-3 text-ops-lilac uppercase">Joplin Queue Status</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div class="text-center p-3 bg-black rounded-r-lg border-2 border-ops-violet">
                <div class="text-2xl font-bold text-ops-sky">{{ services.joplin_queue.pending || 0 }}</div>
                <div class="text-sm text-ops-text-muted uppercase">Pending</div>
              </div>
              <div class="text-center p-3 bg-black rounded-r-lg border-2 border-ops-violet">
                <div class="text-2xl font-bold text-ops-butterscotch">{{ services.joplin_queue.processing || 0 }}</div>
                <div class="text-sm text-ops-text-muted uppercase">Processing</div>
              </div>
              <div class="text-center p-3 bg-black rounded-r-lg border-2 border-ops-violet">
                <div class="text-2xl font-bold text-ops-green">{{ services.joplin_queue.completed || 0 }}</div>
                <div class="text-sm text-ops-text-muted uppercase">Completed</div>
              </div>
              <div class="text-center p-3 bg-black rounded-r-lg border-2 border-ops-violet">
                <div class="text-2xl font-bold" :class="services.joplin_queue.failed > 0 ? 'text-ops-alert' : 'text-ops-gray'">{{ services.joplin_queue.failed || 0 }}</div>
                <div class="text-sm text-ops-text-muted uppercase">Failed</div>
              </div>
            </div>
            <div v-if="services.joplin_queue.oldest_pending" class="mt-2 text-sm text-ops-text-muted">
              Oldest pending: {{ services.joplin_queue.oldest_pending }}
            </div>
          </div>

          <!-- Laravel Queue -->
          <div v-if="services.laravel_queue" class="border-2 rounded-r-lg p-4" :class="services.laravel_queue.healthy ? 'border-ops-green bg-ops-green/10' : 'border-ops-alert bg-ops-alert/10'">
            <div class="flex items-center justify-between mb-3">
              <h4 class="text-lg font-semibold text-ops-peach">Laravel Queue</h4>
              <span class="px-3 py-1 rounded-full text-sm font-medium uppercase"
                    :class="services.laravel_queue.healthy ? 'bg-ops-green/20 text-ops-green' : 'bg-ops-alert/20 text-ops-alert'">
                {{ services.laravel_queue.healthy ? 'Healthy' : 'Issues' }}
              </span>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div class="text-center p-3 bg-black rounded-r-lg border-2 border-ops-violet">
                <div class="text-2xl font-bold text-ops-sky">{{ services.laravel_queue.pending || 0 }}</div>
                <div class="text-sm text-ops-text-muted uppercase">Pending Jobs</div>
              </div>
              <div class="text-center p-3 bg-black rounded-r-lg border-2 border-ops-violet">
                <div class="text-2xl font-bold" :class="services.laravel_queue.failed > 0 ? 'text-ops-alert' : 'text-ops-gray'">{{ services.laravel_queue.failed || 0 }}</div>
                <div class="text-sm text-ops-text-muted uppercase">Failed Jobs</div>
              </div>
            </div>
          </div>
        </div>
        <div v-else-if="loading" class="text-center py-12">
          <div class="text-ops-text-muted">Loading services status...</div>
        </div>
      </div>
    </div>

    <!-- Backups Tab -->
    <div v-show="activeTab === 'backups'" class="space-y-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-lg p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-2xl font-semibold text-ops-peach uppercase tracking-wide">Database Backups</h3>
          <button @click="loadBackups" :disabled="loading" class="bg-ops-orange text-black px-4 py-2 rounded-r-full hover:bg-ops-peach disabled:opacity-50 font-semibold uppercase">
            Refresh
          </button>
        </div>

        <div v-if="backups" class="space-y-6">
          <!-- Summary -->
          <div class="p-4 rounded-r-lg border-2" :class="backups.summary?.backup_healthy ? 'bg-ops-green/10 border-ops-green' : 'bg-ops-alert/10 border-ops-alert'">
            <div class="flex items-center justify-between">
              <div class="flex items-center space-x-2">
                <span class="text-3xl">{{ backups.summary?.backup_healthy ? '✅' : '⚠️' }}</span>
                <div>
                  <div class="font-semibold text-lg" :class="backups.summary?.backup_healthy ? 'text-ops-green' : 'text-ops-alert'">{{ backups.summary?.backup_healthy ? 'Backups Healthy' : 'Backup Attention Needed' }}</div>
                  <div class="text-sm text-ops-text-muted">Last backup should be less than 25 hours old</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Backup Stats Grid -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- MySQL -->
            <div class="border-2 border-ops-violet rounded-r-lg p-4 bg-ops-plum/10">
              <h4 class="text-lg font-semibold mb-3 text-ops-sky uppercase">MySQL Backups</h4>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Total Backups:</span>
                  <span class="font-medium text-ops-text">{{ backups.summary?.mysql_count || 0 }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Latest Backup:</span>
                  <span class="font-medium text-ops-text">{{ backups.summary?.mysql_latest || 'Never' }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Age:</span>
                  <span class="font-medium" :class="backups.summary?.mysql_latest_age_hours > 25 ? 'text-ops-alert' : 'text-ops-green'">
                    {{ backups.summary?.mysql_latest_age_hours?.toFixed(1) || 'N/A' }} hours
                  </span>
                </div>
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Total Size:</span>
                  <span class="font-medium text-ops-text">{{ backups.summary?.mysql_total_size_mb?.toFixed(1) || 0 }} MB</span>
                </div>
              </div>
            </div>

            <!-- PostgreSQL -->
            <div class="border-2 border-ops-violet rounded-r-lg p-4 bg-ops-plum/10">
              <h4 class="text-lg font-semibold mb-3 text-ops-teal uppercase">PostgreSQL Backups</h4>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Total Backups:</span>
                  <span class="font-medium text-ops-text">{{ backups.summary?.postgres_count || 0 }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Latest Backup:</span>
                  <span class="font-medium text-ops-text">{{ backups.summary?.postgres_latest || 'Never' }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Age:</span>
                  <span class="font-medium" :class="backups.summary?.postgres_latest_age_hours > 25 ? 'text-ops-alert' : 'text-ops-green'">
                    {{ backups.summary?.postgres_latest_age_hours?.toFixed(1) || 'N/A' }} hours
                  </span>
                </div>
                <div class="flex justify-between">
                  <span class="text-ops-text-muted">Total Size:</span>
                  <span class="font-medium text-ops-text">{{ backups.summary?.postgres_total_size_mb?.toFixed(1) || 0 }} MB</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Backup Files List -->
          <div class="border-2 border-ops-violet rounded-r-lg p-4 bg-ops-plum/10">
            <h4 class="text-lg font-semibold mb-3 text-ops-lilac uppercase">Recent Backup Files</h4>
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead>
                  <tr class="border-b-2 border-ops-plum">
                    <th class="text-left py-2 px-3 text-ops-lilac uppercase">Filename</th>
                    <th class="text-left py-2 px-3 text-ops-lilac uppercase">Size</th>
                    <th class="text-left py-2 px-3 text-ops-lilac uppercase">Created</th>
                    <th class="text-left py-2 px-3 text-ops-lilac uppercase">Age</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="backup in [...(backups.backups?.mysql || []), ...(backups.backups?.postgres || [])].slice(0, 10)" :key="backup.filename" class="border-b border-ops-plum hover:bg-ops-plum/20">
                    <td class="py-2 px-3 font-mono text-xs text-ops-text">{{ backup.filename }}</td>
                    <td class="py-2 px-3 text-ops-text">{{ backup.size_mb }} MB</td>
                    <td class="py-2 px-3 text-ops-text">{{ backup.created_at }}</td>
                    <td class="py-2 px-3 text-ops-text-muted">{{ backup.age_hours?.toFixed(1) }}h</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div v-else-if="loading" class="text-center py-12">
          <div class="text-ops-text-muted">Loading backup status...</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const activeTab = ref('health');
const loading = ref(false);
const health = ref(null);
const errors = ref(null);
const workflows = ref(null);
const errorPeriod = ref('24 hours');
const services = ref(null);
const backups = ref(null);

onMounted(() => {
  loadHealth();
});

const loadHealth = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/diagnostics/health');
    health.value = response.data.data;
  } catch (error) {
    console.error('Failed to load health data:', error);
  } finally {
    loading.value = false;
  }
};

const loadErrors = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/diagnostics/errors', {
      params: { period: errorPeriod.value }
    });
    errors.value = response.data.data;
  } catch (error) {
    console.error('Failed to load error data:', error);
  } finally {
    loading.value = false;
  }
};

const loadWorkflows = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/diagnostics/workflows');
    workflows.value = response.data.data;
  } catch (error) {
    console.error('Failed to load workflow data:', error);
  } finally {
    loading.value = false;
  }
};

const loadServices = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/diagnostics/services');
    services.value = response.data.data;
  } catch (error) {
    console.error('Failed to load services status:', error);
  } finally {
    loading.value = false;
  }
};

const loadBackups = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/diagnostics/backups');
    backups.value = response.data.data;
  } catch (error) {
    console.error('Failed to load backup status:', error);
  } finally {
    loading.value = false;
  }
};

const getHealthClass = (status) => {
  const classes = {
    healthy: 'text-green-600',
    degraded: 'text-yellow-600',
    unhealthy: 'text-red-600',
    critical: 'text-red-800'
  };
  return classes[status] || 'text-gray-400';
};

const formatServiceName = (name) => {
  return name.split('_').map(word =>
    word.charAt(0).toUpperCase() + word.slice(1)
  ).join(' ');
};

</script>

<style scoped>
/* Additional custom styles if needed */
</style>

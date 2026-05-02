<template>
  <div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="mb-6">
      <h2 class="text-3xl font-bold text-gray-200">Scheduled Jobs</h2>
      <p class="text-gray-400 mt-1">Centralized job scheduling - manage all automated tasks in one place</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-sky">{{ stats.total_jobs }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Total Jobs</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-green">{{ stats.enabled_jobs }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Enabled</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-gray">{{ stats.disabled_jobs }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Disabled</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="stats.running_jobs > 0 ? 'text-ops-butterscotch' : 'text-ops-gray'">
          {{ stats.running_jobs }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Actionable Running</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="stats.failed_jobs > 0 ? 'text-ops-alert' : 'text-ops-gray'">
          {{ stats.failed_jobs }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Failed</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-grape">{{ stats.runs_last_24h }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Runs (24h)</div>
      </div>
    </div>

    <!-- Filters and Actions -->
    <div class="bg-black border-2 border-ops-plum rounded-r-lg p-4 mb-6 flex flex-wrap items-center gap-4">
      <div>
        <label class="text-sm text-ops-text-muted mr-2 uppercase">Module:</label>
        <select v-model="filter.module" @change="loadJobs" class="px-3 py-1 bg-black border-2 border-ops-violet rounded-r-full text-ops-peach text-sm">
          <option value="">All Modules</option>
          <option v-for="m in modules" :key="m.name" :value="m.name">
            {{ m.name }} ({{ m.job_count }})
          </option>
        </select>
      </div>
      <div>
        <label class="text-sm text-ops-text-muted mr-2 uppercase">Status:</label>
        <select v-model="filter.status" @change="loadJobs" class="px-3 py-1 bg-black border-2 border-ops-violet rounded-r-full text-ops-peach text-sm">
          <option value="">All</option>
          <option value="enabled">Enabled</option>
          <option value="disabled">Disabled</option>
          <option value="running">Running</option>
          <option value="failed">Failed</option>
        </select>
      </div>
      <div class="flex-1"></div>
      <button @click="loadJobs" class="px-4 py-2 bg-ops-violet text-black rounded-r-full hover:bg-ops-lilac text-sm font-semibold uppercase">
        Refresh
      </button>
      <button @click="showCreateModal = true" class="px-4 py-2 bg-ops-orange text-black rounded-r-full hover:bg-ops-peach text-sm font-semibold uppercase">
        + New Job
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12">
      <div class="text-gray-400">Loading jobs...</div>
    </div>

    <!-- Error -->
    <div v-else-if="loadError" class="bg-ops-alert/20 border-2 border-ops-alert rounded-r-lg p-6 text-center">
      <div class="text-4xl mb-4">!</div>
      <h3 class="text-xl font-semibold text-ops-alert uppercase">Error Loading Jobs</h3>
      <p class="text-ops-peach mt-2">{{ loadError }}</p>
      <button @click="loadJobs" class="mt-4 px-4 py-2 bg-ops-alert text-black rounded-r-full hover:bg-red-400 font-semibold uppercase">
        Retry
      </button>
    </div>

    <!-- Jobs by Module -->
    <div v-else class="space-y-3">
      <div v-for="(moduleJobs, moduleName) in groupedJobs" :key="moduleName" class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
        <!-- Module Header -->
        <div class="bg-ops-plum/30 px-4 py-2 border-b-2 border-ops-plum flex items-center justify-between cursor-pointer"
             @click="toggleModule(moduleName)">
          <div class="flex items-center space-x-3">
            <span class="text-lg font-semibold text-ops-peach uppercase">{{ moduleName }}</span>
            <span class="text-sm text-ops-text-muted">({{ moduleJobs.length }} jobs)</span>
          </div>
          <svg class="w-5 h-5 text-ops-violet transition-transform"
               :class="{ 'rotate-180': expandedModules[moduleName] }"
               fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
          </svg>
        </div>

        <!-- Jobs Table -->
        <div v-show="expandedModules[moduleName]">
          <table class="min-w-full">
            <thead class="bg-ops-plum/20 border-b-2 border-ops-plum">
              <tr>
                <th class="px-3 py-1.5 text-left text-xs font-medium text-ops-lilac uppercase">Name</th>
                <th class="px-3 py-1.5 text-left text-xs font-medium text-ops-lilac uppercase">Schedule</th>
                <th class="px-3 py-1.5 text-left text-xs font-medium text-ops-lilac uppercase">Status</th>
                <th class="px-3 py-1.5 text-left text-xs font-medium text-ops-lilac uppercase">Last Run</th>
                <th class="px-3 py-1.5 text-left text-xs font-medium text-ops-lilac uppercase">Next Run</th>
                <th class="px-3 py-1.5 text-left text-xs font-medium text-ops-lilac uppercase">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-ops-plum">
              <tr v-for="job in moduleJobs" :key="job.id" class="hover:bg-ops-plum/20">
                <td class="px-3 py-1.5">
                  <div class="font-medium text-ops-peach text-sm">{{ job.name }}</div>
                  <div class="text-xs text-ops-text-muted truncate max-w-xs" :title="job.description">
                    {{ job.description || job.command }}
                  </div>
                </td>
                <td class="px-3 py-1.5">
                  <div class="text-sm text-ops-text">{{ job.schedule_description }}</div>
                  <div class="text-xs text-ops-text-muted font-mono">{{ job.cron_expression }}</div>
                </td>
                <td class="px-3 py-1.5">
                  <div class="flex items-center space-x-2">
                    <span class="w-3 h-3 rounded-full"
                          :class="{
                            'bg-ops-green': job.enabled && job.last_run_status !== 'failed',
                            'bg-ops-alert': job.last_run_status === 'failed',
                            'bg-ops-butterscotch': job.last_run_status === 'running',
                            'bg-ops-gray': !job.enabled
                          }"></span>
                    <span class="text-sm" :class="{
                      'text-ops-green': job.enabled && job.last_run_status !== 'failed',
                      'text-ops-alert': job.last_run_status === 'failed',
                      'text-ops-butterscotch': job.last_run_status === 'running',
                      'text-ops-gray': !job.enabled
                    }">
                      {{ getStatusText(job) }}
                    </span>
                  </div>
                </td>
                <td class="px-3 py-1.5 text-sm text-ops-text-muted">
                  {{ formatDate(job.last_run_at) }}
                </td>
                <td class="px-3 py-1.5 text-sm text-ops-text-muted">
                  {{ job.enabled ? formatDate(job.next_run_at) : '-' }}
                </td>
                <td class="px-3 py-1.5">
                  <div class="flex items-center space-x-2">
                    <span class="text-ops-text-muted text-xs uppercase tracking-wide" title="Manual run disabled">
                      UI run off
                    </span>
                    <button @click="toggleJob(job)" class="text-sm"
                            :class="job.enabled ? 'text-ops-butterscotch hover:text-ops-gold' : 'text-ops-green hover:text-ops-green-bright'"
                            :title="job.enabled ? 'Disable' : 'Enable'">
                      <svg v-if="job.enabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                      </svg>
                      <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                      </svg>
                    </button>
                    <button @click="editJob(job)" class="text-ops-violet hover:text-ops-lilac text-sm" title="Edit">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                      </svg>
                    </button>
                    <button @click="viewHistory(job)" class="text-ops-grape hover:text-ops-lavender text-sm" title="History">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="Object.keys(groupedJobs).length === 0" class="bg-black border-2 border-ops-plum rounded-r-lg p-12 text-center">
        <div class="text-6xl mb-4 text-ops-violet">--</div>
        <h3 class="text-xl font-semibold text-ops-peach uppercase">No Jobs Found</h3>
        <p class="text-ops-text-muted mt-2">No scheduled jobs match your current filters</p>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="showCreateModal || editingJob" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center z-50 p-4">
      <div class="bg-black border-2 border-ops-orange rounded-r-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto ops-scroll">
        <div class="p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold text-ops-peach uppercase tracking-wide">{{ editingJob ? 'Edit Job' : 'Create New Job' }}</h3>
            <button @click="closeModal" class="text-ops-text-muted hover:text-ops-orange">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="saveJob" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Name *</label>
                <input v-model="jobForm.name" type="text" required
                       class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange"
                       placeholder="unique_job_name">
              </div>
              <div>
                <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Job Type *</label>
                <select v-model="jobForm.job_type" required
                        class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange">
                  <option value="command">Artisan Command</option>
                  <option value="agent_task">Agent Task</option>
                  <option value="workflow">Workflow</option>
                  <option value="job_class">Job Class</option>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">
                {{ jobForm.job_type === 'workflow' ? 'Workflow Name' : jobForm.job_type === 'job_class' ? 'Job Class' : jobForm.job_type === 'agent_task' ? 'Agent Skill Name' : 'Command' }} *
              </label>
              <input v-model="jobForm.command" type="text" required
                     class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange"
                     :placeholder="jobForm.job_type === 'workflow' ? 'morning_weather' : jobForm.job_type === 'job_class' ? 'App\\Jobs\\MyJob' : jobForm.job_type === 'agent_task' ? 'genealogy-researcher' : 'mycommand:run --option=value'">
            </div>

            <div>
              <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Cron Expression *</label>
              <div class="flex space-x-2">
                <input v-model="jobForm.cron_expression" type="text" required
                       @blur="validateCron"
                       class="flex-1 px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange font-mono"
                       placeholder="0 * * * *">
                <button type="button" @click="validateCron"
                        class="px-3 py-2 bg-ops-violet text-black rounded-r-full hover:bg-ops-lilac font-semibold uppercase">
                  Validate
                </button>
              </div>
              <div v-if="cronValidation" class="mt-1 text-sm"
                   :class="cronValidation.valid ? 'text-ops-green' : 'text-ops-alert'">
                {{ cronValidation.valid ? cronValidation.description + ' - Next: ' + cronValidation.next_run : cronValidation.error }}
              </div>
              <div class="mt-1 text-xs text-ops-text-muted">
                Format: minute hour day month weekday (e.g., "0 4 * * *" = 4 AM daily, "*/5 * * * *" = every 5 minutes)
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Description</label>
              <textarea v-model="jobForm.description" rows="2"
                        class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-lg text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange"
                        placeholder="What does this job do?"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Source Module</label>
                <input v-model="jobForm.source_module" type="text"
                       class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange"
                       placeholder="e.g., E13 File Registry">
              </div>
              <div>
                <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Category</label>
                <input v-model="jobForm.category" type="text"
                       class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange"
                       placeholder="e.g., Maintenance">
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Timeout (minutes)</label>
                <input v-model.number="jobForm.timeout_minutes" type="number" min="1" max="1440"
                       class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange">
              </div>
              <div class="flex items-center pt-6">
                <label class="flex items-center cursor-pointer">
                  <input v-model="jobForm.enabled" type="checkbox" class="mr-2 accent-ops-orange">
                  <span class="text-sm text-ops-text">Enabled</span>
                </label>
              </div>
              <div class="flex items-center pt-6">
                <label class="flex items-center cursor-pointer">
                  <input v-model="jobForm.without_overlapping" type="checkbox" class="mr-2 accent-ops-orange">
                  <span class="text-sm text-ops-text">Prevent Overlap</span>
                </label>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Notes</label>
              <textarea v-model="jobForm.notes" rows="2"
                        class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-lg text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange"
                        placeholder="Any notes about this job (why disabled, issues, etc)"></textarea>
            </div>

            <div class="flex justify-end space-x-3 pt-4">
              <button type="button" @click="closeModal"
                      class="px-4 py-2 border-2 border-ops-violet text-ops-text rounded-r-full hover:bg-ops-plum/30 uppercase font-semibold">
                Cancel
              </button>
              <button v-if="editingJob" type="button" @click="deleteJob"
                      class="px-4 py-2 bg-ops-alert text-black rounded-r-full hover:bg-red-400 uppercase font-semibold">
                Delete
              </button>
              <button type="submit" :disabled="saving"
                      class="px-4 py-2 bg-ops-orange text-black rounded-r-full hover:bg-ops-peach disabled:opacity-50 uppercase font-semibold">
                {{ saving ? 'Saving...' : (editingJob ? 'Update' : 'Create') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- History Modal -->
    <div v-if="historyJob" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center z-50 p-4">
      <div class="bg-black border-2 border-ops-grape rounded-r-lg max-w-3xl w-full max-h-[90vh] overflow-y-auto ops-scroll">
        <div class="p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold text-ops-peach uppercase tracking-wide">Run History: {{ historyJob.name }}</h3>
            <button @click="historyJob = null" class="text-ops-text-muted hover:text-ops-grape">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <div class="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
              <span class="text-ops-text-muted">Runs (24h):</span>
              <span class="font-medium ml-1 text-ops-peach">{{ historyJob.runs_24h ?? 0 }}</span>
            </div>
            <div>
              <span class="text-ops-text-muted">Failures (24h):</span>
              <span class="font-medium ml-1 text-ops-alert">{{ historyJob.failures_24h ?? 0 }}</span>
            </div>
            <div>
              <span class="text-ops-text-muted">Success Rate (24h):</span>
              <span class="font-medium ml-1 text-ops-green">
                {{ historyJob.success_rate_24h ?? 0 }}%
              </span>
            </div>
          </div>

          <div class="mb-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
              <span class="text-ops-text-muted">Consecutive Failures:</span>
              <span class="font-medium ml-1" :class="(historyJob.consecutive_failures ?? 0) > 0 ? 'text-ops-alert' : 'text-ops-green'">
                {{ historyJob.consecutive_failures ?? 0 }}
              </span>
            </div>
            <div>
              <span class="text-ops-text-muted">Lifetime Totals:</span>
              <span class="font-medium ml-1 text-ops-text">
                {{ historyJob.run_count }} runs / {{ historyJob.fail_count }} failures
              </span>
            </div>
          </div>

          <table class="min-w-full">
            <thead class="bg-ops-plum/20">
              <tr>
                <th class="px-4 py-2 text-left text-xs font-medium text-ops-lilac uppercase">Started</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-ops-lilac uppercase">Duration</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-ops-lilac uppercase">Status</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-ops-lilac uppercase">Triggered By</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-ops-plum">
              <tr v-for="run in jobHistory" :key="run.id" class="hover:bg-ops-plum/20">
                <td class="px-4 py-2 text-sm text-ops-text">{{ formatDateTime(run.started_at) }}</td>
                <td class="px-4 py-2 text-sm text-ops-text">{{ run.duration_seconds ? run.duration_seconds + 's' : '-' }}</td>
                <td class="px-4 py-2">
                  <span class="px-2 py-1 rounded-full text-xs font-medium"
                        :class="{
                          'bg-ops-green/20 text-ops-green': run.status === 'success',
                          'bg-ops-alert/20 text-ops-alert': run.status === 'failed',
                          'bg-ops-butterscotch/20 text-ops-butterscotch': run.status === 'running',
                          'bg-ops-orange/20 text-ops-orange': run.status === 'timeout'
                        }">
                    {{ run.status }}
                  </span>
                </td>
                <td class="px-4 py-2 text-sm text-ops-text-muted capitalize">{{ run.triggered_by }}</td>
              </tr>
              <tr v-if="jobHistory.length === 0">
                <td colspan="4" class="px-4 py-8 text-center text-ops-text-muted">No run history yet</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const loading = ref(false);
const loadError = ref(null);
const jobs = ref([]);
const modules = ref([]);
const stats = ref({
  total_jobs: 0,
  enabled_jobs: 0,
  disabled_jobs: 0,
  running_jobs: 0,
  failed_jobs: 0,
  runs_last_24h: 0
});

const filter = ref({
  module: '',
  status: ''
});

const expandedModules = ref({});
const showCreateModal = ref(false);
const editingJob = ref(null);
const historyJob = ref(null);
const jobHistory = ref([]);
const saving = ref(false);
const cronValidation = ref(null);

const jobForm = ref({
  name: '',
  description: '',
  job_type: 'command',
  command: '',
  cron_expression: '',
  enabled: true,
  run_in_background: true,
  without_overlapping: true,
  timeout_minutes: 60,
  notes: '',
  category: '',
  source_module: ''
});

const groupedJobs = computed(() => {
  const grouped = {};
  for (const job of jobs.value) {
    const module = job.source_module || 'Uncategorized';
    if (!grouped[module]) {
      grouped[module] = [];
    }
    grouped[module].push(job);
  }

  // Only auto-expand when filtering (so results are visible)
  // Default: all collapsed for a compact overview
  if (filter.value.module || filter.value.status) {
    for (const module of Object.keys(grouped)) {
      expandedModules.value[module] = true;
    }
  }

  // Sort modules alphabetically
  const sorted = {};
  Object.keys(grouped).sort().forEach(key => {
    sorted[key] = grouped[key];
  });
  return sorted;
});

onMounted(() => {
  loadJobs();
  loadModules();
  loadStats();
});

const loadJobs = async () => {
  loading.value = true;
  loadError.value = null;
  try {
    const params = {};
    if (filter.value.module) params.module = filter.value.module;
    if (filter.value.status) params.status = filter.value.status;

    const response = await axios.get('/api/scheduled-jobs', { params });
    if (response.data.success) {
      jobs.value = response.data.data;
    }
  } catch (error) {
    loadError.value = error.response?.data?.error || error.message;
  } finally {
    loading.value = false;
  }
};

const loadModules = async () => {
  try {
    const response = await axios.get('/api/scheduled-jobs/modules');
    if (response.data.success) {
      modules.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to load modules:', error);
  }
};

const loadStats = async () => {
  try {
    const response = await axios.get('/api/scheduled-jobs/stats');
    if (response.data.success) {
      stats.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to load stats:', error);
  }
};

const toggleModule = (moduleName) => {
  expandedModules.value[moduleName] = !expandedModules.value[moduleName];
};

const getStatusText = (job) => {
  if (!job.enabled) return 'Disabled';
  if (job.last_run_status === 'running') return 'Running';
  if (job.last_run_status === 'failed') return 'Failed';
  if (job.last_run_status === 'success') return 'Success';
  return 'Pending';
};

const formatDate = (dateStr) => {
  if (!dateStr) return 'Never';
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMs < 0) {
    // Future date
    const futureMins = Math.floor(-diffMs / 60000);
    const futureHours = Math.floor(-diffMs / 3600000);
    if (futureMins < 60) return `in ${futureMins}m`;
    if (futureHours < 24) return `in ${futureHours}h`;
    return date.toLocaleDateString();
  }

  if (diffMins < 60) return `${diffMins}m ago`;
  if (diffHours < 24) return `${diffHours}h ago`;
  if (diffDays < 7) return `${diffDays}d ago`;
  return date.toLocaleDateString();
};

const formatDateTime = (dateStr) => {
  if (!dateStr) return '-';
  return new Date(dateStr).toLocaleString();
};

const toggleJob = async (job) => {
  try {
    const response = await axios.post(`/api/scheduled-jobs/${job.id}/toggle`);
    if (response.data.success) {
      job.enabled = response.data.data.enabled;
      loadStats();
    }
  } catch (error) {
    alert('Error toggling job: ' + (error.response?.data?.error || error.message));
  }
};

const editJob = (job) => {
  editingJob.value = job;
  jobForm.value = {
    name: job.name,
    description: job.description || '',
    job_type: job.job_type,
    command: job.command,
    cron_expression: job.cron_expression,
    enabled: !!job.enabled,
    run_in_background: !!job.run_in_background,
    without_overlapping: !!job.without_overlapping,
    timeout_minutes: job.timeout_minutes || 60,
    notes: job.notes || '',
    category: job.category || '',
    source_module: job.source_module || ''
  };
  cronValidation.value = null;
};

const viewHistory = async (job) => {
  historyJob.value = job;
  try {
    const response = await axios.get(`/api/scheduled-jobs/${job.id}/history`);
    if (response.data.success) {
      jobHistory.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to load history:', error);
  }
};

const validateCron = async () => {
  if (!jobForm.value.cron_expression) return;
  try {
    const response = await axios.post('/api/scheduled-jobs/validate-cron', {
      cron_expression: jobForm.value.cron_expression
    });
    cronValidation.value = response.data.data;
  } catch (error) {
    cronValidation.value = { valid: false, error: 'Validation failed' };
  }
};

const saveJob = async () => {
  saving.value = true;
  try {
    if (editingJob.value) {
      await axios.put(`/api/scheduled-jobs/${editingJob.value.id}`, jobForm.value);
    } else {
      await axios.post('/api/scheduled-jobs', jobForm.value);
    }
    closeModal();
    loadJobs();
    loadStats();
    loadModules();
  } catch (error) {
    alert('Error saving job: ' + (error.response?.data?.error || error.message));
  } finally {
    saving.value = false;
  }
};

const deleteJob = async () => {
  if (!confirm(`Delete "${editingJob.value.name}"? This cannot be undone.`)) return;

  try {
    await axios.delete(`/api/scheduled-jobs/${editingJob.value.id}`);
    closeModal();
    loadJobs();
    loadStats();
    loadModules();
  } catch (error) {
    alert('Error deleting job: ' + (error.response?.data?.error || error.message));
  }
};

const closeModal = () => {
  showCreateModal.value = false;
  editingJob.value = null;
  cronValidation.value = null;
  jobForm.value = {
    name: '',
    description: '',
    job_type: 'command',
    command: '',
    cron_expression: '',
    enabled: true,
    run_in_background: true,
    without_overlapping: true,
    timeout_minutes: 60,
    notes: '',
    category: '',
    source_module: ''
  };
};
</script>

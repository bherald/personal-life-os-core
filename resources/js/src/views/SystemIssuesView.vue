<template>
  <div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="mb-6">
      <h2 class="text-3xl font-bold text-gray-200">System Issues</h2>
      <p class="text-gray-400 mt-1">AI-detected issues requiring human review and collaboration</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="stats.pending > 0 ? 'text-ops-sky' : 'text-ops-gray'">
          {{ stats.pending }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Pending</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="stats.critical > 0 ? 'text-ops-alert' : 'text-ops-gray'">
          {{ stats.critical }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Critical</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="stats.warning > 0 ? 'text-ops-butterscotch' : 'text-ops-gray'">
          {{ stats.warning }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Warning</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-green">{{ stats.resolved }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Resolved</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-gray">{{ stats.dismissed }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Dismissed</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="mb-6">
      <div class="border-b-2 border-ops-plum">
        <nav class="-mb-px flex space-x-8">
          <button @click="activeTab = 'pending'"
                  :class="activeTab === 'pending' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Pending Review
            <span v-if="stats.pending > 0" class="ml-2 bg-ops-sky/20 text-ops-sky px-2 py-0.5 rounded-full text-xs">
              {{ stats.pending }}
            </span>
          </button>
          <button @click="activeTab = 'history'"
                  :class="activeTab === 'history' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            History
          </button>
          <button @click="activeTab = 'alerts'; loadAlerts()"
                  :class="activeTab === 'alerts' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach hover:border-ops-violet'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            System Alerts
            <span v-if="alertCount > 0" class="ml-2 bg-ops-butterscotch/20 text-ops-butterscotch px-2 py-0.5 rounded-full text-xs">
              {{ alertCount }}
            </span>
          </button>
        </nav>
      </div>
    </div>

    <!-- Pending Tab -->
    <div v-show="activeTab === 'pending'">
      <div v-if="loading" class="text-center py-12">
        <div class="text-gray-400">Loading issues...</div>
      </div>

      <div v-else-if="loadError" class="bg-ops-alert/20 border-2 border-ops-alert rounded-r-lg p-6 text-center">
        <div class="text-4xl mb-4">⚠️</div>
        <h3 class="text-xl font-semibold text-ops-alert">Error Loading Issues</h3>
        <p class="text-ops-peach mt-2">{{ loadError }}</p>
        <button @click="loadPending" class="mt-4 px-4 py-2 bg-ops-alert text-black rounded-r-full hover:bg-red-400 font-semibold uppercase">
          Retry
        </button>
      </div>

      <div v-else-if="pendingIssues.length === 0" class="bg-black border-2 border-ops-plum rounded-r-lg p-12 text-center">
        <div class="text-6xl mb-4">✅</div>
        <h3 class="text-xl font-semibold text-ops-green uppercase">No Pending Issues</h3>
        <p class="text-ops-text-muted mt-2">All system issues have been addressed</p>
      </div>

      <div v-else class="space-y-4">
        <div v-for="issue in pendingIssues" :key="issue.id"
             class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden transition-all duration-200"
             :class="{'ring-2 ring-ops-orange': expandedIssue === issue.id}">

          <!-- Issue Header (always visible) -->
          <div class="p-4 cursor-pointer hover:bg-ops-plum/20" @click="toggleExpand(issue.id)">
            <div class="flex items-start justify-between">
              <div class="flex items-start space-x-3">
                <!-- Severity Icon -->
                <div class="text-2xl flex-shrink-0">
                  {{ getSeverityIcon(issue.severity, issue.status) }}
                </div>

                <!-- Title and Meta -->
                <div class="flex-1">
                  <div class="flex items-center space-x-2">
                    <h3 class="font-semibold text-gray-200">{{ issue.title }}</h3>
                    <span v-if="issue.status === 'resolved'"
                          class="px-2 py-0.5 bg-ops-green/20 text-ops-green rounded-full text-xs font-medium">
                      Resolved
                    </span>
                    <span v-if="issue.occurrence_count > 1"
                          class="px-2 py-0.5 bg-ops-orange/20 text-ops-orange rounded-full text-xs font-medium">
                      {{ issue.occurrence_count }}x
                    </span>
                  </div>

                  <div class="flex items-center space-x-4 mt-1 text-sm text-gray-400">
                    <span class="capitalize">{{ issue.category }}</span>
                    <span>{{ issue.last_seen_formatted }}</span>
                  </div>
                </div>
              </div>

              <!-- Expand Arrow -->
              <svg class="w-5 h-5 text-gray-400 transition-transform"
                   :class="{'rotate-180': expandedIssue === issue.id}"
                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
              </svg>
            </div>
          </div>

          <!-- Expanded Details -->
          <div v-show="expandedIssue === issue.id" class="border-t-2 border-ops-plum">
            <!-- Description -->
            <div class="p-4 bg-ops-plum/10">
              <h4 class="text-sm font-semibold text-ops-lilac mb-2 uppercase tracking-wide">Description</h4>
              <p class="text-ops-text text-sm whitespace-pre-wrap">{{ issue.description }}</p>
            </div>

            <!-- AI Recommendation -->
            <div v-if="issue.suggested_fix" class="p-4 bg-ops-sky/10 border-t-2 border-ops-sky/30">
              <h4 class="text-sm font-semibold text-ops-sky mb-2 flex items-center uppercase tracking-wide">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                </svg>
                AI Recommendation
              </h4>
              <p class="text-ops-sky-light text-sm whitespace-pre-wrap">{{ issue.suggested_fix }}</p>
            </div>

            <!-- Timeline Info -->
            <div class="p-4 border-t-2 border-ops-plum">
              <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                  <span class="text-gray-400">First Seen</span>
                  <div class="font-medium">{{ issue.first_seen_formatted }}</div>
                </div>
                <div>
                  <span class="text-gray-400">Last Seen</span>
                  <div class="font-medium">{{ issue.last_seen_formatted }}</div>
                </div>
                <div>
                  <span class="text-gray-400">Detected By</span>
                  <div class="font-medium capitalize">{{ issue.detected_by?.replace('_', ' ') }}</div>
                </div>
                <div>
                  <span class="text-gray-400">Occurrences</span>
                  <div class="font-medium">{{ issue.occurrence_count }}</div>
                </div>
              </div>
            </div>

            <!-- Resolution Info (if resolved) -->
            <div v-if="issue.status === 'resolved'" class="p-4 border-t-2 border-ops-plum bg-ops-green/10">
              <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span class="text-ops-text-muted">Resolved At</span>
                  <div class="font-medium text-ops-peach">{{ issue.resolved_at_formatted }}</div>
                </div>
                <div>
                  <span class="text-ops-text-muted">Resolved By</span>
                  <div class="font-medium text-ops-peach capitalize">{{ issue.resolved_by }}</div>
                </div>
              </div>
              <div v-if="issue.resolution_notes" class="mt-2">
                <span class="text-ops-text-muted text-sm">Notes:</span>
                <p class="text-ops-text text-sm">{{ issue.resolution_notes }}</p>
              </div>
            </div>

            <!-- Actions -->
            <div class="p-4 border-t-2 border-ops-plum bg-ops-plum/10 flex items-center justify-end space-x-3">
              <!-- Run Fix button - only show if suggested_fix exists and issue is open -->
              <button v-if="issue.can_run_fix && issue.status === 'open'"
                      @click.stop="runFixForIssue(issue)"
                      :disabled="runningFix === issue.id"
                      class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition flex items-center text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                <svg v-if="runningFix !== issue.id" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                <svg v-else class="w-4 h-4 mr-1 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ runningFix === issue.id ? 'Running...' : 'Run Fix' }}
              </button>

              <button v-if="issue.status === 'open'"
                      @click.stop="resolveIssue(issue.id)"
                      class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center text-sm font-medium">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                Resolve
              </button>

              <button v-if="issue.status === 'resolved'"
                      @click.stop="reopenIssue(issue.id)"
                      class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition flex items-center text-sm font-medium">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Reopen
              </button>

              <button @click.stop="dismissIssue(issue.id)"
                      class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition flex items-center text-sm font-medium">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                Dismiss
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- History Tab -->
    <div v-show="activeTab === 'history'">
      <!-- Filters -->
      <div class="bg-black border-2 border-ops-plum rounded-r-lg p-4 mb-4 flex items-center space-x-4">
        <div>
          <label class="text-sm text-ops-text-muted uppercase">Status</label>
          <select v-model="historyFilter.status" @change="loadHistory" class="ml-2 px-3 py-1 bg-black border-2 border-ops-violet rounded-r-full text-ops-peach text-sm">
            <option value="all">All</option>
            <option value="open">Open</option>
            <option value="resolved">Resolved</option>
            <option value="dismissed">Dismissed</option>
          </select>
        </div>
        <div>
          <label class="text-sm text-ops-text-muted uppercase">Severity</label>
          <select v-model="historyFilter.severity" @change="loadHistory" class="ml-2 px-3 py-1 bg-black border-2 border-ops-violet rounded-r-full text-ops-peach text-sm">
            <option value="all">All</option>
            <option value="critical">Critical</option>
            <option value="warning">Warning</option>
            <option value="info">Info</option>
          </select>
        </div>
        <button @click="loadHistory" class="px-4 py-1 bg-ops-orange text-black rounded-r-full hover:bg-ops-peach text-sm font-semibold uppercase">
          Refresh
        </button>
      </div>

      <!-- History Table -->
      <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
        <table class="min-w-full">
          <thead class="bg-ops-plum/30">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wider">Issue</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wider">Category</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wider">Status</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wider">Last Seen</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wider">Count</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-ops-plum">
            <tr v-for="issue in historyIssues" :key="issue.id" class="hover:bg-ops-plum/20">
              <td class="px-4 py-3">
                <div class="flex items-center space-x-2">
                  <span class="text-lg">{{ getSeverityIcon(issue.severity, issue.status) }}</span>
                  <span class="text-sm font-medium text-ops-peach">{{ issue.title }}</span>
                </div>
              </td>
              <td class="px-4 py-3 text-sm text-ops-text-muted capitalize">{{ issue.category }}</td>
              <td class="px-4 py-3">
                <span class="px-2 py-1 rounded-full text-xs font-medium"
                      :class="{
                        'bg-ops-alert/20 text-ops-alert': issue.status === 'open',
                        'bg-ops-green/20 text-ops-green': issue.status === 'resolved',
                        'bg-ops-gray/20 text-ops-gray': issue.status === 'dismissed'
                      }">
                  {{ issue.status }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-ops-text-muted">{{ issue.last_seen_formatted }}</td>
              <td class="px-4 py-3 text-sm text-ops-text-muted">{{ issue.occurrence_count }}</td>
              <td class="px-4 py-3">
                <button @click="viewIssue(issue)" class="text-ops-sky hover:text-ops-sky-light text-sm">
                  View
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="historyIssues.length === 0" class="text-center py-12 text-ops-text-muted">
          No issues found matching your filters
        </div>
      </div>
    </div>

    <!-- System Alerts Tab -->
    <div v-show="activeTab === 'alerts'">
      <div class="bg-black border-2 border-ops-plum rounded-r-lg p-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-2xl font-semibold text-ops-peach uppercase tracking-wide">Active Alerts</h3>
          <button @click="loadAlerts" :disabled="loadingAlerts" class="bg-ops-orange text-black px-4 py-2 rounded-r-full hover:bg-ops-peach disabled:opacity-50 font-semibold uppercase">
            Refresh
          </button>
        </div>

        <div v-if="loadingAlerts" class="text-center py-12">
          <div class="text-ops-text-muted">Loading alerts...</div>
        </div>

        <div v-else-if="alertsData">
          <div v-if="alertsData.alerts && alertsData.alerts.length > 0" class="space-y-2">
            <div v-for="a in alertsData.alerts" :key="a.id"
                 class="border-2 rounded-r-lg p-4"
                 :class="getAlertSeverityClass(a.severity)">
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2">
                  <span class="text-2xl">{{ getAlertSeverityEmoji(a.severity) }}</span>
                  <div>
                    <div class="font-medium text-ops-peach">{{ a.title }}</div>
                    <div class="text-sm text-ops-text-muted">{{ a.alert_type }}</div>
                  </div>
                </div>
                <div class="flex space-x-2">
                  <button @click="acknowledgeAlert(a.id)"
                          class="px-3 py-1 bg-ops-butterscotch text-black rounded-r-full text-sm hover:bg-ops-gold font-semibold uppercase">
                    Acknowledge
                  </button>
                  <button @click="resolveSystemAlert(a.id)"
                          class="px-3 py-1 bg-ops-green text-black rounded-r-full text-sm hover:bg-ops-green-bright font-semibold uppercase">
                    Resolve
                  </button>
                </div>
              </div>
              <div class="text-sm text-ops-text-muted mt-2">
                Triggered: {{ a.triggered_at }} | Occurrences: {{ a.occurrence_count }}
              </div>
            </div>
          </div>
          <div v-else class="text-center py-12 text-ops-green">
            No active alerts
          </div>
        </div>
      </div>
    </div>

    <!-- Issue Detail Modal -->
    <div v-if="selectedIssue" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center z-50 p-4">
      <div class="bg-black border-2 border-ops-orange rounded-r-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto ops-scroll">
        <div class="p-6">
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center space-x-3">
              <span class="text-3xl">{{ getSeverityIcon(selectedIssue.severity, selectedIssue.status) }}</span>
              <div>
                <h3 class="text-xl font-bold text-ops-peach">{{ selectedIssue.title }}</h3>
                <p class="text-sm text-ops-text-muted capitalize">{{ selectedIssue.category }} - {{ selectedIssue.status }}</p>
              </div>
            </div>
            <button @click="selectedIssue = null" class="text-ops-text-muted hover:text-ops-orange">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <div class="space-y-4">
            <div>
              <h4 class="font-semibold text-ops-lilac mb-1 uppercase tracking-wide">Description</h4>
              <p class="text-ops-text whitespace-pre-wrap">{{ selectedIssue.description }}</p>
            </div>

            <div v-if="selectedIssue.suggested_fix" class="p-4 bg-ops-sky/10 border-2 border-ops-sky/30 rounded-r-lg">
              <h4 class="font-semibold text-ops-sky mb-1 uppercase tracking-wide">AI Recommendation</h4>
              <p class="text-ops-sky-light whitespace-pre-wrap">{{ selectedIssue.suggested_fix }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <span class="text-ops-text-muted">First Seen</span>
                <div class="font-medium text-ops-peach">{{ selectedIssue.first_seen_formatted }}</div>
              </div>
              <div>
                <span class="text-ops-text-muted">Last Seen</span>
                <div class="font-medium text-ops-peach">{{ selectedIssue.last_seen_formatted }}</div>
              </div>
              <div>
                <span class="text-ops-text-muted">Occurrences</span>
                <div class="font-medium text-ops-peach">{{ selectedIssue.occurrence_count }}</div>
              </div>
              <div>
                <span class="text-ops-text-muted">Detected By</span>
                <div class="font-medium text-ops-peach capitalize">{{ selectedIssue.detected_by?.replace('_', ' ') }}</div>
              </div>
            </div>

            <div v-if="selectedIssue.resolution_notes" class="p-4 bg-ops-green/10 border-2 border-ops-green/30 rounded-r-lg">
              <h4 class="font-semibold text-ops-green mb-1 uppercase tracking-wide">Resolution Notes</h4>
              <p class="text-ops-green">{{ selectedIssue.resolution_notes }}</p>
              <p class="text-sm text-ops-green/70 mt-2">
                Resolved by {{ selectedIssue.resolved_by }} on {{ selectedIssue.resolved_at_formatted }}
              </p>
            </div>
          </div>

          <div class="mt-6 flex justify-end space-x-3">
            <button @click="selectedIssue = null" class="px-4 py-2 border-2 border-ops-violet text-ops-text rounded-r-full hover:bg-ops-plum/30">
              Close
            </button>
          </div>
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
const activeTab = ref('pending');
const pendingIssues = ref([]);
const historyIssues = ref([]);
const stats = ref({
  pending: 0,
  open: 0,
  resolved: 0,
  dismissed: 0,
  critical: 0,
  warning: 0,
  info: 0
});
const expandedIssue = ref(null);
const selectedIssue = ref(null);
const runningFix = ref(null);
const historyFilter = ref({
  status: 'all',
  severity: 'all'
});
const alertsData = ref(null);
const loadingAlerts = ref(false);
const alertCount = computed(() => alertsData.value?.alerts?.length || 0);

onMounted(() => {
  loadPending();
});

const loadPending = async () => {
  loading.value = true;
  loadError.value = null;
  try {
    const response = await axios.get('/api/system-issues/pending');
    if (response.data.success) {
      pendingIssues.value = response.data.data.issues;
      stats.value = response.data.data.stats;
    } else {
      loadError.value = response.data.error || 'Failed to load issues';
    }
  } catch (error) {
    console.error('Failed to load pending issues:', error);
    loadError.value = error.response?.data?.error || error.message || 'Network error loading issues';
  } finally {
    loading.value = false;
  }
};

const loadHistory = async () => {
  loading.value = true;
  try {
    const params = {};
    if (historyFilter.value.status !== 'all') params.status = historyFilter.value.status;
    if (historyFilter.value.severity !== 'all') params.severity = historyFilter.value.severity;

    const response = await axios.get('/api/system-issues', { params });
    if (response.data.success) {
      historyIssues.value = response.data.data.issues;
      stats.value = response.data.data.stats;
    }
  } catch (error) {
    console.error('Failed to load history:', error);
  } finally {
    loading.value = false;
  }
};

const toggleExpand = (issueId) => {
  expandedIssue.value = expandedIssue.value === issueId ? null : issueId;
};

const resolveIssue = async (issueId) => {
  try {
    const response = await axios.post(`/api/system-issues/${issueId}/resolve`, {
      resolved_by: 'human'
    });
    if (response.data.success) {
      loadPending();
    }
  } catch (error) {
    console.error('Failed to resolve issue:', error);
    alert('Failed to resolve issue');
  }
};

const dismissIssue = async (issueId) => {
  if (!confirm('Dismiss this issue? It will be removed from the pending list but kept in history.')) {
    return;
  }
  try {
    const response = await axios.post(`/api/system-issues/${issueId}/dismiss`, {
      dismissed_by: 'human'
    });
    if (response.data.success) {
      loadPending();
    }
  } catch (error) {
    console.error('Failed to dismiss issue:', error);
    alert('Failed to dismiss issue');
  }
};

const reopenIssue = async (issueId) => {
  try {
    const response = await axios.post(`/api/system-issues/${issueId}/reopen`);
    if (response.data.success) {
      loadPending();
    }
  } catch (error) {
    console.error('Failed to reopen issue:', error);
    alert('Failed to reopen issue');
  }
};

const runFixForIssue = async (issue) => {
  if (issue.remediation_requires_confirmation) {
    const confirmed = confirm(
      'This fix performs a write-risk remediation and should only be run with human approval. Continue?'
    );
    if (!confirmed) {
      return;
    }
  }

  runningFix.value = issue.id;
  try {
    const response = await axios.post(`/api/system-issues/${issue.id}/run-fix`, {
      confirmed: !!issue.remediation_requires_confirmation
    });
    if (response.data.success) {
      alert('Fix executed successfully!\n\n' + (response.data.data?.output || 'Issue resolved.'));
      loadPending();
    } else {
      alert('Fix failed: ' + (response.data.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Failed to run fix:', error);
    alert('Failed to run fix: ' + (error.response?.data?.error || error.message));
  } finally {
    runningFix.value = null;
  }
};

const viewIssue = (issue) => {
  selectedIssue.value = issue;
};

const getSeverityIcon = (severity, status) => {
  if (status === 'resolved') return '✅';
  if (status === 'dismissed') return '⬜';

  switch (severity) {
    case 'critical': return '🚨';
    case 'warning': return '⚠️';
    case 'info': return 'ℹ️';
    default: return '❓';
  }
};

const loadAlerts = async () => {
  loadingAlerts.value = true;
  try {
    const response = await axios.get('/api/diagnostics/alerts');
    alertsData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load alerts:', error);
  } finally {
    loadingAlerts.value = false;
  }
};

const acknowledgeAlert = async (alertId) => {
  try {
    await axios.post(`/api/diagnostics/alerts/${alertId}/acknowledge`, {
      alert_id: alertId,
      acknowledged_by: 'ui'
    });
    loadAlerts();
  } catch (error) {
    console.error('Failed to acknowledge alert:', error);
  }
};

const resolveSystemAlert = async (alertId) => {
  try {
    await axios.post(`/api/diagnostics/alerts/${alertId}/resolve`, {
      alert_id: alertId,
      resolved_by: 'ui',
      resolution_notes: 'Resolved via UI'
    });
    loadAlerts();
  } catch (error) {
    console.error('Failed to resolve alert:', error);
  }
};

const getAlertSeverityClass = (severity) => {
  const classes = {
    critical: 'border-ops-alert bg-ops-alert/10',
    error: 'border-ops-alert bg-ops-alert/10',
    warning: 'border-ops-butterscotch bg-ops-butterscotch/10',
    info: 'border-ops-sky bg-ops-sky/10'
  };
  return classes[severity] || 'border-ops-plum bg-ops-plum/10';
};

const getAlertSeverityEmoji = (severity) => {
  const emojis = { critical: '🚨', error: '❌', warning: '⚠️', info: 'ℹ️' };
  return emojis[severity] || '•';
};

// Watch for tab changes
import { watch } from 'vue';
watch(activeTab, (newTab) => {
  if (newTab === 'history' && historyIssues.value.length === 0) {
    loadHistory();
  }
});
</script>

<style scoped>
/* Smooth transitions */
.rotate-180 {
  transform: rotate(180deg);
}
</style>

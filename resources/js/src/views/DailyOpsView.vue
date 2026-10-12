<template>
  <div class="ops-page">
    <!-- Ops Console Page Header -->
    <div class="ops-page-header">
      <div class="ops-page-header-elbow"></div>
      <div class="ops-page-header-bar">
        <h1 class="ops-page-title">Daily Operations</h1>
        <div class="ops-page-subtitle">System shortcuts &amp; tools</div>
      </div>
    </div>

    <!-- Ops Console Divider Bar -->
    <div class="ops-bar ops-bar-thick ops-bar-magenta mb-6"></div>

    <section class="workboard" aria-labelledby="workboard-heading">
      <div class="workboard-header">
        <div>
          <h2 id="workboard-heading">PLOS Workboard</h2>
          <p>{{ workboardUpdated }}</p>
        </div>
        <button type="button" :disabled="workboardLoading" @click="loadWorkboard">
          {{ workboardLoading ? 'Refreshing' : 'Refresh' }}
        </button>
      </div>

      <div class="workboard-tabs" role="tablist" aria-label="Workboard status">
        <button
          v-for="tab in workboardTabs"
          :key="tab.value"
          type="button"
          role="tab"
          :aria-selected="workboardFilter === tab.value"
          :class="{ active: workboardFilter === tab.value }"
          @click="workboardFilter = tab.value"
        >
          {{ tab.label }} <span>{{ tab.count }}</span>
        </button>
      </div>

      <div v-if="workboardError" class="workboard-state workboard-error">{{ workboardError }}</div>
      <div v-else-if="workboardLoading && !workboardItems.length" class="workboard-state">Loading workboard</div>
      <div v-else class="workboard-table" role="table" aria-label="Canonical PLOS work list">
        <div class="workboard-row workboard-column-headings" role="row">
          <span role="columnheader">Priority</span>
          <span role="columnheader">Work item</span>
          <span role="columnheader">Status</span>
          <span role="columnheader">Current direction</span>
        </div>
        <div v-for="item in filteredWorkboardItems" :key="item.id" class="workboard-row" role="row">
          <span class="workboard-priority" role="cell">{{ item.priority }}</span>
          <span class="workboard-id" role="cell">
            <strong>{{ item.id }}</strong>
            <small>{{ item.area }}</small>
          </span>
          <span role="cell"><span class="workboard-status" :data-group="item.status_group">{{ item.status }}</span></span>
          <span class="workboard-summary" role="cell">{{ item.summary }}</span>
        </div>
      </div>
    </section>

    <!-- Ops Console Divider - Automation Section -->
    <div class="ops-divider my-8">
      <div class="ops-divider-line"></div>
      <span class="ops-divider-text">Automation</span>
      <div class="ops-divider-line"></div>
    </div>

    <!-- Automation Links -->
    <div class="ops-quick-links">
      <router-link
        v-for="link in automationLinks"
        :key="link.route"
        :to="link.route"
        class="ops-quick-link"
        :class="link.colorClass"
      >
        <span class="ops-quick-link-icon" v-html="link.icon"></span>
        <span class="ops-quick-link-label">{{ link.label }}</span>
      </router-link>
    </div>

    <!-- Ops Console Divider - System Section -->
    <div class="ops-divider my-8">
      <div class="ops-divider-line"></div>
      <span class="ops-divider-text">System</span>
      <div class="ops-divider-line"></div>
    </div>

    <!-- System Links -->
    <div class="ops-quick-links">
      <router-link
        v-for="link in systemLinks"
        :key="link.route"
        :to="link.route"
        class="ops-quick-link"
        :class="link.colorClass"
      >
        <span class="ops-quick-link-icon" v-html="link.icon"></span>
        <span class="ops-quick-link-label">{{ link.label }}</span>
      </router-link>
      <a
        href="/horizon"
        target="_blank"
        rel="noopener"
        class="ops-quick-link ops-link-external"
      >
        <span class="ops-quick-link-icon">&#128202;</span>
        <span class="ops-quick-link-label">Horizon</span>
      </a>
    </div>

    <!-- Ops Console Footer Bar -->
    <div class="ops-page-footer">
      <div class="ops-bar ops-bar-lilac"></div>
      <div class="ops-page-footer-text">PERSONAL LIFE OS</div>
      <div class="ops-page-footer-elbow"></div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../utils/api'

const workboardItems = ref([])
const workboardCounts = ref({})
const workboardFilter = ref('all')
const workboardLoading = ref(false)
const workboardError = ref('')
const workboardUpdatedAt = ref(null)

const workboardTabs = computed(() => [
  { value: 'all', label: 'All', count: workboardItems.value.length },
  { value: 'active', label: 'Active', count: workboardCounts.value.active || 0 },
  { value: 'operator', label: 'Operator', count: workboardCounts.value.operator || 0 },
  { value: 'watch', label: 'Watch', count: workboardCounts.value.watch || 0 },
  { value: 'parked', label: 'Parked', count: workboardCounts.value.parked || 0 },
])

const filteredWorkboardItems = computed(() => workboardFilter.value === 'all'
  ? workboardItems.value
  : workboardItems.value.filter((item) => item.status_group === workboardFilter.value))

const workboardUpdated = computed(() => {
  if (!workboardUpdatedAt.value) return 'Canonical work list'
  const date = new Date(workboardUpdatedAt.value)
  if (Number.isNaN(date.getTime())) return 'Canonical work list'
  return `Updated ${date.toLocaleString()}`
})

onMounted(loadWorkboard)

async function loadWorkboard() {
  if (workboardLoading.value) return
  workboardLoading.value = true
  workboardError.value = ''

  try {
    const response = await api.get('/ops/workboard')
    const payload = response?.data && response?.success !== undefined ? response.data : response
    workboardItems.value = Array.isArray(payload?.items) ? payload.items : []
    workboardCounts.value = payload?.counts && typeof payload.counts === 'object' ? payload.counts : {}
    workboardUpdatedAt.value = payload?.updated_at || null
  } catch (error) {
    workboardError.value = error?.response?.data?.message || error?.message || 'Workboard unavailable'
  } finally {
    workboardLoading.value = false
  }
}

// Automation section links
const automationLinks = [
  { label: 'Workflows', route: '/workflows', icon: '&#9889;', colorClass: 'ops-link-orange' },
  { label: 'Scheduled Jobs', route: '/scheduled-jobs', icon: '&#128337;', colorClass: 'ops-link-peach' },
  { label: 'Nodes', route: '/nodes', icon: '&#128279;', colorClass: 'ops-link-lilac' },
  { label: 'MCP Tools', route: '/mcp', icon: '&#128295;', colorClass: 'ops-link-sky' },
];

// System section links
const systemLinks = [
  { label: 'Knowledge Hub', route: '/knowledge', icon: '&#128218;', colorClass: 'ops-link-gold' },
  { label: 'Operator Evidence', route: '/operator-evidence', icon: '&#128269;', colorClass: 'ops-link-green' },
  { label: 'Configuration', route: '/config', icon: '&#9881;', colorClass: 'ops-link-lilac' },
  { label: 'AI Observability', route: '/ai-observability', icon: '&#129302;', colorClass: 'ops-link-sky' },
  { label: 'Diagnostics', route: '/diagnostics', icon: '&#128200;', colorClass: 'ops-link-sky' },
  { label: 'Executions', route: '/executions', icon: '&#9654;', colorClass: 'ops-link-peach' },
  { label: 'Dev Tools', route: '/dev-tools', icon: '&#128187;', colorClass: 'ops-link-orange' },
  { label: 'System Issues', route: '/system-issues', icon: '&#9888;', colorClass: 'ops-link-orange' },
  { label: 'File Catalog', route: '/file-catalog', icon: '&#128193;', colorClass: 'ops-link-gold' },
];
</script>

<style scoped>
/* Ops Console Page Layout */
.ops-page {
  min-height: 100vh;
  background-color: var(--color-bg-primary);
  padding: 0 1.5rem 2rem;
}

.ops-page > .ops-bar,
.ops-page-footer .ops-bar {
  display: none;
}

/* Ops Console Page Header */
.ops-page-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.5rem 0;
}

.ops-page-header-elbow {
  display: none;
}

.ops-page-header-bar {
  flex: 1;
  background: var(--color-bg-secondary);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 0.5rem 1rem;
}

.ops-page-title {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--color-text-primary);
  text-transform: none;
  letter-spacing: normal;
  margin: 0;
}

.ops-page-subtitle {
  font-size: 0.75rem;
  color: var(--color-accent);
  text-transform: none;
  letter-spacing: normal;
  margin-top: 0.25rem;
}

/* Ops Console Divider */
.ops-divider {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.ops-divider-line {
  flex: 1;
  height: 2px;
  background-color: var(--color-accent);
}

.ops-divider-text {
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--color-accent);
  text-transform: uppercase;
  letter-spacing: 0.15em;
  white-space: nowrap;
}

/* Ops Console Quick Links Panel */
.ops-quick-links {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
  background-color: var(--color-bg-secondary);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 1rem;
  position: relative;
}

/* Panel left accent bar */
.ops-quick-links::before {
  content: none;
}

.ops-quick-link {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 1.25rem 1rem;
  background-color: var(--color-bg-tertiary);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  text-decoration: none;
  transition: all 0.15s ease;
}

.ops-quick-link:hover {
  background-color: var(--color-bg-hover);
  border-color: var(--color-accent);
}

/* Link color variants */
.ops-link-orange { border-left-color: var(--ops-warning); }
.ops-link-peach { border-left-color: var(--color-text-primary); }
.ops-link-lilac { border-left-color: var(--color-accent); }
.ops-link-gold { border-left-color: var(--ops-warning); }
.ops-link-sky { border-left-color: var(--color-accent); }
.ops-link-green { border-left-color: var(--ops-success); }
.ops-link-external { border-left-color: var(--ops-success); }

.ops-quick-link-icon {
  font-size: 1.75rem;
}

.ops-quick-link-label {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-primary);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

/* Ops Console Page Footer */
.ops-page-footer {
  display: flex;
  align-items: center;
  margin-top: 3rem;
  gap: 0;
}

.ops-page-footer .ops-bar {
  flex: 1;
  height: 8px;
}

.ops-page-footer-text {
  padding: 0 1rem;
  font-size: 0.625rem;
  color: var(--color-accent);
  text-transform: uppercase;
  letter-spacing: 0.15em;
  white-space: nowrap;
}

.ops-page-footer-elbow {
  display: none;
}

.workboard {
  margin: 1.5rem 0 2rem;
  border-top: 3px solid var(--ops-warning);
  border-bottom: 1px solid var(--color-accent);
  background: var(--color-bg-secondary);
}

.workboard-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem;
}

.workboard-header h2 {
  margin: 0;
  color: var(--color-text-primary);
  font-size: 1.125rem;
  letter-spacing: normal;
}

.workboard-header p {
  margin: 0.25rem 0 0;
  color: var(--color-text-secondary);
  font-size: 0.75rem;
}

.workboard-header button,
.workboard-tabs button {
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-bg-secondary);
  color: var(--color-text-primary);
  cursor: pointer;
}

.workboard-header button {
  min-width: 6.5rem;
  padding: 0.55rem 0.8rem;
}

.workboard-header button:disabled {
  cursor: wait;
  opacity: 0.65;
}

.workboard-tabs {
  display: flex;
  gap: 0;
  overflow-x: auto;
  padding: 0 1rem 0.75rem;
}

.workboard-tabs button {
  border-radius: 0;
  padding: 0.45rem 0.7rem;
  white-space: nowrap;
}

.workboard-tabs button:first-child {
  border-radius: 4px 0 0 4px;
}

.workboard-tabs button:last-child {
  border-radius: 0 4px 4px 0;
}

.workboard-tabs button + button {
  border-left: 0;
}

.workboard-tabs button.active {
  background: var(--ops-warning);
  color: var(--color-text-secondary);
  font-weight: 700;
}

.workboard-tabs span {
  margin-left: 0.25rem;
  font-variant-numeric: tabular-nums;
}

.workboard-table {
  width: 100%;
  border-top: 1px solid var(--color-border);
}

.workboard-row {
  display: grid;
  grid-template-columns: 4.5rem minmax(13rem, 1fr) minmax(8rem, 0.7fr) minmax(18rem, 2fr);
  gap: 0.75rem;
  align-items: start;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--color-border);
  color: var(--color-text-primary);
  font-size: 0.8125rem;
}

.workboard-column-headings {
  background: var(--color-bg-secondary);
  color: var(--color-text-secondary);
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: none;
}

.workboard-priority {
  color: var(--ops-warning);
  font-size: 1rem;
  font-variant-numeric: tabular-nums;
}

.workboard-id {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 0.15rem;
}

.workboard-id strong {
  overflow-wrap: anywhere;
  color: var(--color-text-primary);
  letter-spacing: 0;
}

.workboard-id small,
.workboard-summary {
  color: var(--color-text-secondary);
  line-height: 1.4;
}

.workboard-status {
  display: inline-block;
  border-left: 3px solid var(--color-border);
  padding-left: 0.45rem;
  color: var(--color-text-secondary);
}

.workboard-status[data-group="operator"] {
  border-left-color: var(--color-border);
  color: var(--color-text-secondary);
}

.workboard-status[data-group="watch"] {
  border-left-color: var(--color-border);
  color: var(--color-text-primary);
}

.workboard-status[data-group="parked"] {
  border-left-color: var(--color-border);
  color: var(--color-text-secondary);
}

.workboard-state {
  padding: 1rem;
  border-top: 1px solid var(--color-border);
  color: var(--color-text-secondary);
}

.workboard-error {
  color: var(--ops-danger);
}

/* Mobile Adjustments */
@media (max-width: 768px) {
  .ops-page {
    padding: 0 1rem 1.5rem;
  }

  .ops-page-header {
    flex-wrap: wrap;
  }

  .ops-page-header-elbow {
    width: 50px;
    height: 40px;
  }

  .ops-page-title {
    font-size: 1.25rem;
  }

  .ops-quick-links {
    grid-template-columns: repeat(2, 1fr);
  }

  .workboard-header {
    align-items: flex-start;
  }

  .workboard-row {
    grid-template-columns: 2.5rem minmax(0, 1fr);
  }

  .workboard-column-headings {
    display: none;
  }

  .workboard-row > :nth-child(3),
  .workboard-row > :nth-child(4) {
    grid-column: 2;
  }
}
</style>

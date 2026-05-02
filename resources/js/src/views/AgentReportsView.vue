<template>
  <div class="px-4 py-4 space-y-6" :class="{ 'max-w-7xl mx-auto': !embedded }">
    <!-- Header with range selector -->
    <div class="flex items-center justify-between">
      <h2 v-if="!embedded" class="text-xl font-bold text-ops-peach uppercase tracking-wider">Agent Reports</h2>
      <div class="flex gap-2">
        <button
          v-for="r in ranges"
          :key="r.value"
          @click="selectedRange = r.value"
          class="px-3 py-1 text-xs font-semibold uppercase rounded-r-full transition-colors"
          :class="selectedRange === r.value
            ? 'bg-ops-lilac text-black'
            : 'bg-ops-plum/30 text-ops-text-muted hover:bg-ops-plum/50'"
        >
          {{ r.label }}
        </button>
        <button @click="loadReports" class="bg-ops-orange text-black px-3 py-1 rounded-r-full hover:bg-ops-peach font-semibold uppercase text-xs ml-2">
          Refresh
        </button>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="text-center py-12 text-ops-text-muted">Loading agent reports...</div>

    <!-- Error state -->
    <div v-else-if="error" class="text-center py-12 text-ops-alert">{{ error }}</div>

    <template v-else-if="data">
      <!-- Stats Row -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold text-ops-sky">{{ data.totals?.active_agents ?? 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Active Agents</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold text-ops-green">{{ data.totals?.total_runs ?? 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Total Runs</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold text-ops-butterscotch">{{ data.totals?.completed ?? 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Completed</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold" :class="(data.totals?.errors ?? 0) > 0 ? 'text-ops-alert' : 'text-ops-green'">{{ data.totals?.errors ?? 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Errors</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold text-ops-peach">{{ data.totals?.tool_calls ?? 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Tool Calls</div>
        </div>
      </div>

      <!-- Activity Timeline (text-based sparkline) -->
      <div v-if="data.timeline?.length" class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
        <div class="bg-ops-plum/30 px-4 py-2">
          <h3 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Activity Timeline</h3>
        </div>
        <div class="px-4 py-3">
          <div class="flex items-end gap-px h-24">
            <div
              v-for="(point, idx) in data.timeline"
              :key="idx"
              class="flex-1 flex flex-col items-stretch gap-px"
              :title="`${point.period}\n${point.completed} completed, ${point.errors} errors, ${point.tool_calls} tools`"
            >
              <div
                class="bg-ops-green/80 rounded-t-sm"
                :style="{ height: barHeight(point.completed, timelineMax) + 'px' }"
              ></div>
              <div
                v-if="point.errors > 0"
                class="bg-ops-alert/80 rounded-t-sm"
                :style="{ height: barHeight(point.errors, timelineMax) + 'px' }"
              ></div>
            </div>
          </div>
          <div class="flex justify-between text-[10px] text-ops-text-muted mt-1">
            <span>{{ data.timeline[0]?.period }}</span>
            <span class="flex gap-4">
              <span><span class="inline-block w-2 h-2 bg-ops-green/80 rounded-sm mr-1"></span>Completed</span>
              <span><span class="inline-block w-2 h-2 bg-ops-alert/80 rounded-sm mr-1"></span>Errors</span>
            </span>
            <span>{{ data.timeline[data.timeline.length - 1]?.period }}</span>
          </div>
        </div>
      </div>

      <!-- Per-Agent Cards -->
      <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
        <div class="bg-ops-plum/30 px-4 py-2">
          <h3 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Per-Agent Summary</h3>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-ops-plum/20">
            <tr>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-left">Agent</th>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-right">Runs</th>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-right">Done</th>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-right">Errors</th>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-right">Tools</th>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-right hidden sm:table-cell">Avg Time</th>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-right">Health</th>
              <th class="text-xs font-medium text-ops-lilac uppercase px-4 py-2 text-right hidden md:table-cell">Last Active</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-ops-plum/20">
            <tr
              v-for="agent in data.agents"
              :key="agent.agent_id"
              class="hover:bg-ops-plum/10 cursor-pointer"
              @click="toggleAgent(agent.agent_id)"
            >
              <td class="px-4 py-2">
                <span class="text-ops-peach font-semibold">{{ agent.agent_id }}</span>
                <span v-if="agent.hallucinations > 0" class="ml-2 text-[10px] text-ops-butterscotch" title="Hallucinations blocked">
                  {{ agent.hallucinations }} blocked
                </span>
              </td>
              <td class="px-4 py-2 text-right text-ops-sky">{{ agent.total_runs }}</td>
              <td class="px-4 py-2 text-right text-ops-green">{{ agent.completed }}</td>
              <td class="px-4 py-2 text-right" :class="agent.errors > 0 ? 'text-ops-alert' : 'text-ops-text-muted'">{{ agent.errors }}</td>
              <td class="px-4 py-2 text-right text-ops-text-muted">{{ agent.tool_calls }}</td>
              <td class="px-4 py-2 text-right text-ops-text-muted hidden sm:table-cell">{{ formatDuration(agent.avg_duration_ms) }}</td>
              <td class="px-4 py-2 text-right">
                <span
                  class="inline-block w-2.5 h-2.5 rounded-full"
                  :class="healthColor(agent)"
                  :title="healthLabel(agent)"
                ></span>
              </td>
              <td class="px-4 py-2 text-right text-ops-text-muted text-xs hidden md:table-cell">{{ timeAgo(agent.last_activity) }}</td>
            </tr>
            <tr v-if="!data.agents?.length">
              <td colspan="8" class="px-4 py-6 text-center text-ops-text-muted">No agent activity in selected range</td>
            </tr>
          </tbody>
        </table>

        <!-- Expanded agent detail: tool breakdown + recent episodes -->
        <div v-if="expandedAgent" class="border-t-2 border-ops-plum bg-ops-plum/5 px-4 py-3 space-y-3">
          <div>
            <h4 class="text-xs font-bold text-ops-butterscotch uppercase mb-2">Tool Usage: {{ expandedAgent }}</h4>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="tool in agentTools(expandedAgent)"
                :key="tool.tool_name"
                class="bg-ops-plum/30 text-ops-text px-2 py-1 rounded text-xs"
              >
                {{ tool.tool_name }} <span class="text-ops-sky">({{ tool.call_count }})</span>
              </span>
              <span v-if="!agentTools(expandedAgent).length" class="text-ops-text-muted text-xs">No tool data</span>
            </div>
          </div>
          <div v-if="expandedEpisodes.length || loadingEpisodes">
            <h4 class="text-xs font-bold text-ops-butterscotch uppercase mb-2">Recent Episodes</h4>
            <div v-if="loadingEpisodes" class="text-ops-text-muted text-xs">Loading...</div>
            <div v-else class="space-y-2">
              <div
                v-for="ep in expandedEpisodes"
                :key="ep.id"
                class="bg-black/40 border border-ops-plum/30 rounded px-3 py-2 text-xs"
              >
                <div class="flex justify-between items-start gap-2 mb-1">
                  <span class="text-ops-sky font-semibold">{{ ep.event_type }}</span>
                  <span class="text-ops-text-muted shrink-0">{{ new Date(ep.created_at).toLocaleString() }}</span>
                </div>
                <div class="text-ops-text leading-relaxed whitespace-pre-wrap">{{ ep.summary }}</div>
                <div v-if="ep.tokens_used || ep.duration_ms" class="mt-1 text-ops-text-muted">
                  <span v-if="ep.tokens_used">{{ ep.tokens_used }} tokens</span>
                  <span v-if="ep.tokens_used && ep.duration_ms"> · </span>
                  <span v-if="ep.duration_ms">{{ Math.round(ep.duration_ms / 1000) }}s</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Agent Handoffs -->
      <div v-if="handoffs" class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
        <div class="bg-ops-plum/30 px-4 py-2 flex justify-between items-center">
          <h3 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Agent Handoffs</h3>
          <div class="flex gap-3 text-xs">
            <span class="text-ops-sky">{{ handoffs.stats?.total_handoffs ?? 0 }} total</span>
            <span class="text-ops-green">{{ handoffs.stats?.completed ?? 0 }} completed</span>
            <span :class="(handoffs.stats?.failed ?? 0) > 0 ? 'text-ops-alert' : 'text-ops-text-muted'">{{ handoffs.stats?.failed ?? 0 }} failed</span>
            <span class="text-ops-text-muted">{{ handoffs.agents?.length ?? 0 }} registered agents</span>
            <span class="text-ops-text-muted">{{ handoffs.routing_rules_count ?? 0 }} rules</span>
          </div>
        </div>
        <!-- Recent handoff history -->
        <div v-if="handoffs.history?.length" class="divide-y divide-ops-plum/20">
          <div v-for="h in handoffs.history.slice(0, 10)" :key="h.handoff_id" class="px-4 py-2 flex items-center gap-3 text-sm">
            <span
              class="inline-block w-2 h-2 rounded-full flex-shrink-0"
              :class="h.status === 'completed' ? 'bg-ops-green' : h.status === 'failed' ? 'bg-ops-alert' : 'bg-ops-butterscotch'"
            ></span>
            <span class="text-ops-peach font-semibold">{{ h.source_agent_id }}</span>
            <span class="text-ops-text-muted">&rarr;</span>
            <span class="text-ops-sky font-semibold">{{ h.target_agent_id }}</span>
            <span class="text-ops-text-muted text-xs flex-1 truncate">{{ h.reason }}</span>
            <span v-if="h.duration_ms" class="text-ops-text-muted text-xs">{{ formatDuration(h.duration_ms) }}</span>
            <span class="text-ops-text-muted text-xs">{{ timeAgo(h.created_at) }}</span>
          </div>
        </div>
        <div v-else class="px-4 py-6 text-center text-ops-text-muted text-sm">No handoffs in selected range</div>
      </div>

      <!-- Two-column: Top Tools + Schedule Status -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Tools -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
          <div class="bg-ops-plum/30 px-4 py-2">
            <h3 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Top Tools</h3>
          </div>
          <div class="divide-y divide-ops-plum/20">
            <div v-for="tool in topTools" :key="tool.tool_name" class="px-4 py-2 flex justify-between items-center">
              <span class="text-ops-text text-sm">{{ tool.tool_name }}</span>
              <div class="flex items-center gap-3">
                <div class="w-24 bg-ops-plum/20 rounded-full h-1.5">
                  <div class="bg-ops-sky h-1.5 rounded-full" :style="{ width: toolBarWidth(tool.total) + '%' }"></div>
                </div>
                <span class="text-ops-sky text-xs w-8 text-right">{{ tool.total }}</span>
              </div>
            </div>
            <div v-if="!topTools.length" class="px-4 py-6 text-center text-ops-text-muted text-sm">No tool data</div>
          </div>
        </div>

        <!-- Schedule Status -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
          <div class="bg-ops-plum/30 px-4 py-2">
            <h3 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Scheduled Agents</h3>
          </div>
          <div class="divide-y divide-ops-plum/20">
            <div v-for="job in data.schedules" :key="job.id" class="px-4 py-2 flex justify-between items-center">
              <div>
                <span class="text-ops-text text-sm">{{ formatJobName(job.name) }}</span>
                <span class="text-ops-text-muted text-xs ml-2">{{ job.cron_expression }}</span>
              </div>
              <div class="flex items-center gap-2">
                <span
                  class="inline-block w-2 h-2 rounded-full"
                  :class="job.last_status === 'completed' ? 'bg-ops-green' : job.last_status === 'failed' ? 'bg-ops-alert' : 'bg-ops-text-muted'"
                ></span>
                <span class="text-ops-text-muted text-xs">{{ job.last_run ? timeAgo(job.last_run) : 'never' }}</span>
              </div>
            </div>
            <div v-if="!data.schedules?.length" class="px-4 py-6 text-center text-ops-text-muted text-sm">No scheduled agents</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'

const props = defineProps({
  embedded: { type: Boolean, default: false },
})

const route = useRoute()

const ranges = [
  { label: '1H', value: '1h' },
  { label: '24H', value: '24h' },
  { label: '7D', value: '7d' },
  { label: '30D', value: '30d' },
]

const selectedRange = ref('24h')
const loading = ref(false)
const error = ref(null)
const data = ref(null)
const handoffs = ref(null)
const expandedAgent = ref(null)
const expandedEpisodes = ref([])
const loadingEpisodes = ref(false)

async function loadEpisodes(agentId) {
  loadingEpisodes.value = true
  expandedEpisodes.value = []
  try {
    const resp = await axios.get(`/api/research-hub/agents/${agentId}/episodes`)
    expandedEpisodes.value = resp.data.episodes || []
  } catch (e) {
    expandedEpisodes.value = []
  } finally {
    loadingEpisodes.value = false
  }
}

watch(expandedAgent, (agentId) => {
  if (agentId) loadEpisodes(agentId)
  else expandedEpisodes.value = []
})

const timelineMax = computed(() => {
  if (!data.value?.timeline?.length) return 1
  return Math.max(...data.value.timeline.map(p => (p.completed || 0) + (p.errors || 0)), 1)
})

const topTools = computed(() => {
  if (!data.value?.tool_usage?.length) return []
  const byTool = {}
  for (const t of data.value.tool_usage) {
    byTool[t.tool_name] = (byTool[t.tool_name] || 0) + t.call_count
  }
  return Object.entries(byTool)
    .map(([tool_name, total]) => ({ tool_name, total }))
    .sort((a, b) => b.total - a.total)
    .slice(0, 15)
})

const maxToolCount = computed(() => topTools.value.length ? topTools.value[0].total : 1)

function toolBarWidth(count) {
  return Math.round((count / maxToolCount.value) * 100)
}

function barHeight(value, max) {
  if (!max || !value) return 0
  return Math.max(Math.round((value / max) * 80), 2)
}

function toggleAgent(agentId) {
  expandedAgent.value = expandedAgent.value === agentId ? null : agentId
}

function agentTools(agentId) {
  if (!data.value?.tool_usage) return []
  return data.value.tool_usage
    .filter(t => t.agent_id === agentId)
    .sort((a, b) => b.call_count - a.call_count)
}

function healthColor(agent) {
  if (agent.errors > 0 && agent.completed === 0) return 'bg-ops-alert'
  if (agent.errors > 0) return 'bg-ops-butterscotch'
  if (agent.completed > 0) return 'bg-ops-green'
  return 'bg-ops-text-muted'
}

function healthLabel(agent) {
  if (agent.errors > 0 && agent.completed === 0) return 'Failing'
  if (agent.errors > 0) return 'Degraded'
  if (agent.completed > 0) return 'Healthy'
  return 'Idle'
}

function formatDuration(ms) {
  if (!ms) return '-'
  if (ms < 1000) return ms + 'ms'
  const s = Math.round(ms / 1000)
  if (s < 60) return s + 's'
  const m = Math.floor(s / 60)
  return m + 'm ' + (s % 60) + 's'
}

function timeAgo(dateStr) {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now - d
  const diffMin = Math.round(diffMs / 60000)
  if (diffMin < 1) return 'just now'
  if (diffMin < 60) return diffMin + 'm ago'
  const diffH = Math.floor(diffMin / 60)
  if (diffH < 24) return diffH + 'h ago'
  const diffD = Math.floor(diffH / 24)
  return diffD + 'd ago'
}

function formatJobName(name) {
  return name.replace(/_agent$/, '').replace(/_/g, '-')
}

async function loadReports() {
  loading.value = true
  error.value = null
  try {
    const [reportsResp, handoffsResp] = await Promise.all([
      axios.get('/api/research-hub/agents/reports', { params: { range: selectedRange.value } }),
      axios.get('/api/research-hub/agents/handoffs', { params: { range: selectedRange.value } }).catch(() => ({ data: null })),
    ])
    data.value = reportsResp.data
    handoffs.value = handoffsResp.data
  } catch (e) {
    error.value = e.response?.data?.error || e.message
  } finally {
    loading.value = false
  }
}

watch(selectedRange, () => loadReports())

onMounted(async () => {
  await loadReports()
  // Auto-expand agent specified via ?agent= query param (e.g. from Research Hub "View agent session" link)
  if (route.query.agent) {
    expandedAgent.value = route.query.agent
  }
})
</script>

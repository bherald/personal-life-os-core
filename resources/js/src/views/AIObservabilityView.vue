<template>
  <div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-ops-peach uppercase tracking-wider">AI Observability</h1>
      <button @click="refresh" class="bg-ops-orange text-black rounded-r-full px-4 py-1 text-xs font-semibold uppercase hover:bg-ops-peach">
        Refresh
      </button>
    </div>

    <div v-if="loading" class="text-ops-text-muted text-center py-12">Loading...</div>
    <div v-else-if="error" class="text-ops-alert text-center py-12">{{ error }}</div>

    <template v-else>
      <!-- Summary Stats Row -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold text-ops-sky">{{ data.providers?.length || 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Providers</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold text-ops-green">{{ data.audit_summary?.total_events || 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Events 24h</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold" :class="(data.audit_summary?.failures || 0) > 0 ? 'text-ops-alert' : 'text-ops-green'">{{ data.audit_summary?.failures || 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Failures</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold" :class="(data.audit_summary?.denied || 0) > 0 ? 'text-ops-butterscotch' : 'text-ops-green'">{{ data.audit_summary?.denied || 0 }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Denied</div>
        </div>
        <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
          <div class="text-2xl font-bold text-ops-lilac">{{ activeProviders }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Active</div>
        </div>
      </div>

      <!-- Providers Panel -->
      <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden mb-6">
        <div class="bg-ops-plum/30 px-4 py-2">
          <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">LLM Providers</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-ops-text-muted text-xs uppercase border-b border-ops-plum/30">
                <th class="px-4 py-2 text-left">Provider</th>
                <th class="px-4 py-2 text-left">Type</th>
                <th class="px-4 py-2 text-center">Circuit</th>
                <th class="px-4 py-2 text-right">Requests</th>
                <th class="px-4 py-2 text-right">Success %</th>
                <th class="px-4 py-2 text-center">Capabilities</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in data.providers" :key="p.instance_id" class="border-b border-ops-plum/10 hover:bg-ops-plum/10">
                <td class="px-4 py-2 text-ops-peach font-semibold">{{ p.instance_name }}</td>
                <td class="px-4 py-2 text-ops-text-muted">{{ p.instance_type }}</td>
                <td class="px-4 py-2 text-center">
                  <span :class="circuitClass(p.circuit_state)" class="px-2 py-0.5 rounded-full text-xs font-bold">
                    {{ p.circuit_state || 'closed' }}
                  </span>
                </td>
                <td class="px-4 py-2 text-right text-ops-sky">{{ formatNum(p.total_requests) }}</td>
                <td class="px-4 py-2 text-right" :class="successRateClass(p.success_rate)">
                  {{ p.success_rate ? Number(p.success_rate).toFixed(1) + '%' : '-' }}
                </td>
                <td class="px-4 py-2 text-center text-xs text-ops-text-muted">
                  <span v-if="p.has_embedding" class="text-ops-green mr-1">EMB</span>
                  <span v-if="p.has_vision" class="text-ops-butterscotch">VIS</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Two column: Agent Activity + Tool Stats -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Agent Activity -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
          <div class="bg-ops-plum/30 px-4 py-2">
            <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Agent Activity (24h)</h2>
          </div>
          <div class="divide-y divide-ops-plum/30">
            <div v-for="a in data.tokens_by_agent" :key="a.agent_name" class="px-4 py-3 hover:bg-ops-plum/10">
              <div class="flex justify-between items-center">
                <span class="text-ops-peach font-semibold">{{ a.agent_name }}</span>
                <span class="text-ops-sky text-sm">{{ a.total_events }} events</span>
              </div>
              <div class="flex gap-3 mt-1 text-xs">
                <span class="text-ops-green">{{ a.successes }} ok</span>
                <span v-if="a.failures > 0" class="text-ops-alert">{{ a.failures }} fail</span>
                <span v-if="a.denied > 0" class="text-ops-butterscotch">{{ a.denied }} denied</span>
              </div>
            </div>
            <div v-if="!data.tokens_by_agent?.length" class="px-4 py-6 text-center text-ops-text-muted">
              No agent activity in last 24h
            </div>
          </div>
        </div>

        <!-- Top Tool Calls -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
          <div class="bg-ops-plum/30 px-4 py-2">
            <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Tool Calls (24h)</h2>
          </div>
          <div class="divide-y divide-ops-plum/30 max-h-96 overflow-y-auto">
            <div v-for="t in data.tool_stats_24h" :key="t.tool_name + t.outcome" class="px-4 py-2 hover:bg-ops-plum/10 flex justify-between items-center">
              <div>
                <span class="text-ops-peach text-sm">{{ t.tool_name }}</span>
                <span class="text-xs ml-2" :class="t.outcome === 'success' ? 'text-ops-green' : 'text-ops-alert'">{{ t.outcome }}</span>
              </div>
              <div class="text-right text-xs">
                <span class="text-ops-sky">{{ t.calls }}x</span>
                <span class="text-ops-text-muted ml-2">{{ Math.round(t.avg_ms) }}ms avg</span>
              </div>
            </div>
            <div v-if="!data.tool_stats_24h?.length" class="px-4 py-6 text-center text-ops-text-muted">
              No tool calls in last 24h
            </div>
          </div>
        </div>
      </div>

      <!-- Two column: Guardrails + Reviews -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Guardrail Events -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
          <div class="bg-ops-plum/30 px-4 py-2">
            <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Guardrail Events (24h)</h2>
          </div>
          <div class="divide-y divide-ops-plum/30">
            <div v-for="g in data.guardrail_events" :key="g.action_detail + g.outcome" class="px-4 py-2 hover:bg-ops-plum/10 flex justify-between">
              <span class="text-ops-butterscotch text-sm">{{ g.action_detail }}</span>
              <span class="text-ops-alert text-xs">{{ g.count }}x {{ g.outcome }}</span>
            </div>
            <div v-if="!data.guardrail_events?.length" class="px-4 py-6 text-center text-ops-green">
              No guardrail events — all clear
            </div>
          </div>
        </div>

        <!-- Review Submissions -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
          <div class="bg-ops-plum/30 px-4 py-2">
            <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Review Submissions (24h)</h2>
          </div>
          <div class="divide-y divide-ops-plum/30">
            <div v-for="r in data.review_stats" :key="r.review_type" class="px-4 py-2 hover:bg-ops-plum/10 flex justify-between">
              <span class="text-ops-peach text-sm">{{ r.review_type }}</span>
              <div class="text-right text-xs">
                <span class="text-ops-sky">{{ r.count }}x</span>
                <span class="text-ops-text-muted ml-2" v-if="r.avg_confidence">{{ (r.avg_confidence * 100).toFixed(0) }}% conf</span>
              </div>
            </div>
            <div v-if="!data.review_stats?.length" class="px-4 py-6 text-center text-ops-text-muted">
              No reviews submitted in last 24h
            </div>
          </div>
        </div>
      </div>

      <!-- Hourly Volume Chart (text-based) -->
      <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden mb-6" v-if="data.hourly_volume?.length">
        <div class="bg-ops-plum/30 px-4 py-2">
          <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Event Volume (24h)</h2>
        </div>
        <div class="px-4 py-3">
          <div v-for="h in data.hourly_volume" :key="h.hour" class="flex items-center gap-2 mb-1">
            <span class="text-xs text-ops-text-muted w-14 shrink-0">{{ formatHour(h.hour) }}</span>
            <div class="flex-1 flex items-center gap-1">
              <div class="h-3 bg-ops-green rounded-r" :style="{ width: barWidth(h.success) }"></div>
              <div v-if="h.failure > 0" class="h-3 bg-ops-alert rounded-r" :style="{ width: barWidth(h.failure) }"></div>
            </div>
            <span class="text-xs text-ops-sky w-8 text-right shrink-0">{{ h.events }}</span>
          </div>
        </div>
      </div>

      <!-- Circuit Breakers -->
      <div class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden mb-6">
        <div class="bg-ops-plum/30 px-4 py-2">
          <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">Circuit Breakers</h2>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 p-4">
          <div v-for="cb in data.circuit_breakers" :key="cb.instance_name"
               class="border border-ops-plum/30 rounded-r-lg p-3"
               :class="cb.circuit_state === 'open' ? 'border-ops-alert' : ''">
            <div class="text-ops-peach text-sm font-semibold truncate">{{ cb.instance_name }}</div>
            <div class="mt-1">
              <span :class="circuitClass(cb.circuit_state)" class="px-2 py-0.5 rounded-full text-xs font-bold">
                {{ cb.circuit_state || 'closed' }}
              </span>
            </div>
            <div class="text-xs text-ops-text-muted mt-1">
              {{ cb.circuit_failures || 0 }} failures
            </div>
            <div class="text-xs" :class="successRateClass(cb.success_rate)">
              {{ cb.success_rate ? Number(cb.success_rate).toFixed(1) + '%' : '-' }}
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const data = ref({})
const loading = ref(true)
const error = ref(null)

const activeProviders = computed(() =>
  (data.value.providers || []).filter(p => p.is_active && p.is_healthy).length
)

const maxHourlyEvents = computed(() =>
  Math.max(...(data.value.hourly_volume || []).map(h => h.events), 1)
)

function circuitClass(state) {
  if (state === 'open') return 'bg-ops-alert/20 text-ops-alert'
  if (state === 'half_open') return 'bg-ops-butterscotch/20 text-ops-butterscotch'
  return 'bg-ops-green/20 text-ops-green'
}

function successRateClass(rate) {
  if (!rate) return 'text-ops-text-muted'
  const n = Number(rate)
  if (n >= 95) return 'text-ops-green'
  if (n >= 80) return 'text-ops-butterscotch'
  return 'text-ops-alert'
}

function formatNum(n) {
  if (!n) return '0'
  return Number(n).toLocaleString()
}

function formatHour(h) {
  if (!h) return ''
  const parts = h.split(' ')
  return parts[1] || h
}

function barWidth(val) {
  const pct = (val / maxHourlyEvents.value) * 100
  return Math.max(pct, 1) + '%'
}

async function refresh() {
  loading.value = true
  error.value = null
  try {
    const res = await fetch('/api/dashboard/ai-observability')
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    data.value = await res.json()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(refresh)
</script>

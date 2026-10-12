<template>
  <div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
      <div>
        <h1 class="text-2xl font-bold text-ops-peach uppercase">AI Observability</h1>
        <div class="text-xs text-ops-text-muted mt-1">Provider, model, role, and request-kind evidence</div>
      </div>
      <div class="flex items-center gap-2">
        <div class="flex border border-ops-plum/60 rounded overflow-hidden" aria-label="Model request window">
          <button
            v-for="range in ranges"
            :key="range.hours"
            @click="selectHours(range.hours)"
            class="px-3 py-1.5 text-xs font-semibold"
            :class="selectedHours === range.hours ? 'bg-ops-lilac text-black' : 'bg-black text-ops-text-muted hover:text-ops-peach'"
          >
            {{ range.label }}
          </button>
        </div>
        <button @click="refresh" class="bg-ops-orange text-black rounded px-3 py-1.5 text-xs font-semibold uppercase hover:bg-ops-peach">
          Refresh
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-ops-text-muted text-center py-12">Loading...</div>
    <div v-else-if="error" class="text-ops-alert text-center py-12">{{ error }}</div>

    <template v-else>
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-sky">{{ formatNum(metrics.summary?.calls) }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Requests {{ rangeLabel }}</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold" :class="successRateClass(metrics.summary?.success_rate)">{{ formatPercent(metrics.summary?.success_rate) }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Success</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold" :class="(metrics.summary?.failures || 0) > 0 ? 'text-ops-alert' : 'text-ops-green'">{{ formatNum(metrics.summary?.failures) }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Failures</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-peach">{{ formatLatency(metrics.summary?.avg_latency_ms) }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Average latency</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-butterscotch">{{ formatLatency(metrics.summary?.p95_latency_ms) }}</div>
          <div class="text-xs text-ops-text-muted uppercase">P95 latency</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-lilac">{{ formatNum(metrics.summary?.escalated) }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Escalated</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-sky">{{ formatTokenUsage(metrics.summary, 'input_tokens') }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Input tokens</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-green">{{ formatTokenUsage(metrics.summary, 'cached_input_tokens') }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Cache read</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-butterscotch">{{ formatTokenUsage(metrics.summary, 'cache_write_input_tokens') }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Cache write</div>
        </div>
        <div class="bg-black border border-ops-plum rounded p-3">
          <div class="text-xl font-bold text-ops-peach">{{ formatTokenUsage(metrics.summary, 'output_tokens') }}</div>
          <div class="text-xs text-ops-text-muted uppercase">Output tokens</div>
        </div>
      </div>

      <!-- Providers Panel -->
      <div class="bg-black border border-ops-plum rounded overflow-hidden mb-6">
        <div class="bg-ops-plum/30 px-4 py-2">
          <h2 class="text-sm font-bold text-ops-lilac uppercase tracking-wider">LLM Providers</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[48rem] text-sm">
            <thead>
              <tr class="text-ops-text-muted text-xs uppercase border-b border-ops-plum/30">
                <th class="px-4 py-2 text-left">Provider</th>
                <th class="px-4 py-2 text-left">Type</th>
                <th class="px-4 py-2 text-center">Circuit</th>
                <th class="px-4 py-2 text-right">Window calls</th>
                <th class="px-4 py-2 text-right">Fail %</th>
                <th class="px-4 py-2 text-right">P95</th>
                <th class="px-4 py-2 text-center">Capabilities</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in data.providers" :key="p.instance_id" class="border-b border-ops-plum/10 hover:bg-ops-plum/10">
                <td class="px-4 py-2 text-ops-peach font-semibold whitespace-nowrap">{{ p.instance_name }}</td>
                <td class="px-4 py-2 text-ops-text-muted">{{ p.instance_type }}</td>
                <td class="px-4 py-2 text-center">
                  <span :class="circuitClass(p.circuit_state)" class="px-2 py-0.5 rounded text-xs font-bold">
                    {{ p.circuit_state || 'closed' }}
                  </span>
                </td>
                <td class="px-4 py-2 text-right text-ops-sky">{{ formatNum(providerMetric(p.instance_id).calls) }}</td>
                <td class="px-4 py-2 text-right" :class="failureRateClass(providerMetric(p.instance_id).failure_rate)">{{ formatPercent(providerMetric(p.instance_id).failure_rate) }}</td>
                <td class="px-4 py-2 text-right text-ops-text-muted">{{ formatLatency(providerMetric(p.instance_id).p95_latency_ms) }}</td>
                <td class="px-4 py-2 text-center text-xs text-ops-text-muted">
                  <span v-if="p.has_embedding" class="text-ops-green mr-1">EMB</span>
                  <span v-if="p.has_vision" class="text-ops-butterscotch">VIS</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-black border border-ops-plum rounded overflow-hidden mb-6">
        <div class="bg-ops-plum/30 px-4 py-2 flex items-center justify-between gap-3">
          <h2 class="text-sm font-bold text-ops-lilac uppercase">Model Routes</h2>
          <span class="text-xs text-ops-text-muted">{{ metrics.sampled ? 'Latest 100k requests sampled' : `${formatNum(metrics.total_rows)} requests` }}</span>
        </div>
        <div class="overflow-x-auto max-h-[32rem]">
          <table class="w-full min-w-[88rem] text-sm">
            <thead class="sticky top-0 bg-black">
              <tr class="text-ops-text-muted text-xs uppercase border-b border-ops-plum/30">
                <th class="px-3 py-2 text-left">Provider</th>
                <th class="px-3 py-2 text-left">Model</th>
                <th class="px-3 py-2 text-left">Role</th>
                <th class="px-3 py-2 text-left">Kind</th>
                <th class="px-3 py-2 text-right">Calls</th>
                <th class="px-3 py-2 text-right">Fail %</th>
                <th class="px-3 py-2 text-right">Avg</th>
                <th class="px-3 py-2 text-right">P95</th>
                <th class="px-3 py-2 text-right">Tok/s</th>
                <th class="px-3 py-2 text-right">Input</th>
                <th class="px-3 py-2 text-right">Cache R</th>
                <th class="px-3 py-2 text-right">Cache W</th>
                <th class="px-3 py-2 text-right">Output</th>
                <th class="px-3 py-2 text-left">State</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="route in metrics.routes" :key="`${route.instance_id}|${route.model_name}|${route.model_role}|${route.request_kind}`" class="border-b border-ops-plum/10 hover:bg-ops-plum/10">
                <td class="px-3 py-2 text-ops-peach font-semibold">{{ route.instance_id }}</td>
                <td class="px-3 py-2 text-ops-text max-w-56 truncate" :title="route.model_name">{{ route.model_name }}</td>
                <td class="px-3 py-2 text-ops-lilac">{{ route.model_role }}</td>
                <td class="px-3 py-2 text-ops-sky">{{ route.request_kind }}</td>
                <td class="px-3 py-2 text-right">{{ formatNum(route.calls) }}</td>
                <td class="px-3 py-2 text-right" :class="failureRateClass(route.failure_rate)">{{ formatPercent(route.failure_rate) }}</td>
                <td class="px-3 py-2 text-right text-ops-text-muted">{{ formatLatency(route.avg_latency_ms) }}</td>
                <td class="px-3 py-2 text-right text-ops-text-muted">{{ formatLatency(route.p95_latency_ms) }}</td>
                <td class="px-3 py-2 text-right text-ops-text-muted">{{ route.avg_tokens_per_second ?? '-' }}</td>
                <td class="px-3 py-2 text-right text-ops-text-muted">{{ formatTokenUsage(route, 'input_tokens') }}</td>
                <td class="px-3 py-2 text-right text-ops-green">{{ formatTokenUsage(route, 'cached_input_tokens') }}</td>
                <td class="px-3 py-2 text-right text-ops-butterscotch">{{ formatTokenUsage(route, 'cache_write_input_tokens') }}</td>
                <td class="px-3 py-2 text-right text-ops-text-muted">{{ formatTokenUsage(route, 'output_tokens') }}</td>
                <td class="px-3 py-2"><span class="text-xs font-semibold" :class="healthStateClass(route.health_state)">{{ route.health_state }}</span></td>
              </tr>
              <tr v-if="!metrics.routes?.length">
                <td colspan="14" class="px-4 py-8 text-center text-ops-text-muted">No model request telemetry in this window</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-black border border-ops-plum rounded overflow-hidden mb-6">
        <div class="bg-ops-plum/30 px-4 py-2 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-bold text-ops-lilac uppercase">Host / Model Baseline</h2>
          <span class="text-xs text-ops-text-muted">Current {{ rangeLabel }} compared with prior {{ rangeLabel }}</span>
        </div>
        <div class="md:hidden divide-y divide-ops-plum/20">
          <div v-for="route in comparison.routes" :key="`mobile-baseline|${route.instance_id}|${route.model_name}|${route.model_role}|${route.request_kind}`" class="px-4 py-3">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm text-ops-peach font-semibold truncate">{{ route.instance_id }}</div>
                <div class="text-xs text-ops-text-muted truncate">{{ route.model_name }}</div>
              </div>
              <div class="text-right text-xs shrink-0">
                <div class="text-ops-sky">{{ formatNum(route.current?.calls) }} calls</div>
                <div class="text-ops-text-muted">{{ route.baseline ? `${formatNum(route.baseline.calls)} prior` : 'no baseline' }}</div>
              </div>
            </div>
            <div class="text-xs mt-1">
              <span class="text-ops-lilac">{{ route.model_role }}</span>
              <span class="text-ops-text-muted mx-1">/</span>
              <span class="text-ops-sky">{{ route.request_kind }}</span>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 mt-3 text-xs">
              <div>
                <div class="text-ops-text-muted uppercase">Fail delta</div>
                <div class="font-semibold" :class="lowerDeltaClass(route.delta?.failure_rate_points)">{{ formatSigned(route.delta?.failure_rate_points, 'pp') }}</div>
              </div>
              <div>
                <div class="text-ops-text-muted uppercase">Avg latency</div>
                <div class="font-semibold" :class="lowerDeltaClass(route.delta?.avg_latency_percent)">{{ formatSigned(route.delta?.avg_latency_percent, '%') }}</div>
              </div>
              <div>
                <div class="text-ops-text-muted uppercase">P95 latency</div>
                <div class="font-semibold" :class="lowerDeltaClass(route.delta?.p95_latency_percent)">{{ formatSigned(route.delta?.p95_latency_percent, '%') }}</div>
              </div>
              <div>
                <div class="text-ops-text-muted uppercase">Throughput</div>
                <div class="font-semibold" :class="higherDeltaClass(route.delta?.tokens_per_second_percent)">{{ formatSigned(route.delta?.tokens_per_second_percent, '%') }}</div>
              </div>
            </div>
          </div>
          <div v-if="!comparison.routes?.length" class="px-4 py-8 text-center text-ops-text-muted">No current model routes to compare</div>
        </div>
        <div class="hidden md:block overflow-x-auto max-h-[28rem]">
          <table class="w-full min-w-[70rem] text-sm">
            <thead class="sticky top-0 bg-black">
              <tr class="text-ops-text-muted text-xs uppercase border-b border-ops-plum/30">
                <th class="px-3 py-2 text-left">Provider</th>
                <th class="px-3 py-2 text-left">Model</th>
                <th class="px-3 py-2 text-left">Role / Kind</th>
                <th class="px-3 py-2 text-right">Calls now</th>
                <th class="px-3 py-2 text-right">Prior</th>
                <th class="px-3 py-2 text-right">Fail delta</th>
                <th class="px-3 py-2 text-right">Avg latency</th>
                <th class="px-3 py-2 text-right">P95 latency</th>
                <th class="px-3 py-2 text-right">Throughput</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="route in comparison.routes" :key="`baseline|${route.instance_id}|${route.model_name}|${route.model_role}|${route.request_kind}`" class="border-b border-ops-plum/10 hover:bg-ops-plum/10">
                <td class="px-3 py-2 text-ops-peach font-semibold">{{ route.instance_id }}</td>
                <td class="px-3 py-2 text-ops-text max-w-56 truncate" :title="route.model_name">{{ route.model_name }}</td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <span class="text-ops-lilac">{{ route.model_role }}</span>
                  <span class="text-ops-text-muted mx-1">/</span>
                  <span class="text-ops-sky">{{ route.request_kind }}</span>
                </td>
                <td class="px-3 py-2 text-right">{{ formatNum(route.current?.calls) }}</td>
                <td class="px-3 py-2 text-right text-ops-text-muted">{{ route.baseline ? formatNum(route.baseline.calls) : '-' }}</td>
                <td class="px-3 py-2 text-right font-semibold" :class="lowerDeltaClass(route.delta?.failure_rate_points)">{{ formatSigned(route.delta?.failure_rate_points, 'pp') }}</td>
                <td class="px-3 py-2 text-right font-semibold" :class="lowerDeltaClass(route.delta?.avg_latency_percent)">{{ formatSigned(route.delta?.avg_latency_percent, '%') }}</td>
                <td class="px-3 py-2 text-right font-semibold" :class="lowerDeltaClass(route.delta?.p95_latency_percent)">{{ formatSigned(route.delta?.p95_latency_percent, '%') }}</td>
                <td class="px-3 py-2 text-right font-semibold" :class="higherDeltaClass(route.delta?.tokens_per_second_percent)">{{ formatSigned(route.delta?.tokens_per_second_percent, '%') }}</td>
              </tr>
              <tr v-if="!comparison.routes?.length">
                <td colspan="9" class="px-4 py-8 text-center text-ops-text-muted">No current model routes to compare</td>
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

      <div class="bg-black border border-ops-plum rounded overflow-hidden mb-6">
        <div class="bg-ops-plum/30 px-4 py-2 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-bold text-ops-lilac uppercase">Guardrail Coverage</h2>
          <span class="text-xs text-ops-text-muted">
            {{ formatNum(guardrailMonitor.activity?.total) }} events {{ rangeLabel }}
          </span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 border-b border-ops-plum/30">
          <div class="px-4 py-3 border-r border-ops-plum/20">
            <div class="text-xs text-ops-text-muted uppercase">Active profile</div>
            <div class="text-sm text-ops-peach font-semibold truncate" :title="guardrailMonitor.active_profile">
              {{ guardrailMonitor.active_profile || 'unknown' }}
            </div>
          </div>
          <div class="px-4 py-3 md:border-r border-ops-plum/20">
            <div class="text-xs text-ops-text-muted uppercase">Database rules</div>
            <div class="text-sm text-ops-sky font-semibold">{{ formatNum(guardrailMonitor.source_counts?.database) }}</div>
          </div>
          <div class="px-4 py-3 border-r border-t md:border-t-0 border-ops-plum/20">
            <div class="text-xs text-ops-text-muted uppercase">Config rules</div>
            <div class="text-sm text-ops-green font-semibold">{{ formatNum(guardrailMonitor.source_counts?.config) }}</div>
          </div>
          <div class="px-4 py-3 border-t md:border-t-0 border-ops-plum/20">
            <div class="text-xs text-ops-text-muted uppercase">Policy profiles</div>
            <div class="text-sm text-ops-butterscotch font-semibold">{{ formatNum(guardrailMonitor.source_counts?.offline_policy_profiles) }}</div>
          </div>
        </div>
        <div class="overflow-x-auto max-h-72">
          <table class="w-full min-w-[42rem] text-sm">
            <thead class="sticky top-0 bg-black">
              <tr class="text-ops-text-muted text-xs uppercase border-b border-ops-plum/30">
                <th class="px-4 py-2 text-left">Rule</th>
                <th class="px-4 py-2 text-left">Source</th>
                <th class="px-4 py-2 text-left">Operation</th>
                <th class="px-4 py-2 text-left">Action</th>
                <th class="px-4 py-2 text-left">Severity</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rule in guardrailMonitor.rules" :key="rule.id" class="border-b border-ops-plum/10 hover:bg-ops-plum/10">
                <td class="px-4 py-2 text-ops-peach font-semibold">{{ rule.name }}</td>
                <td class="px-4 py-2 text-ops-sky">{{ rule.source }}</td>
                <td class="px-4 py-2 text-ops-text-muted font-mono text-xs">{{ rule.operation_pattern || '-' }}</td>
                <td class="px-4 py-2 text-ops-lilac">{{ rule.action }}</td>
                <td class="px-4 py-2" :class="rule.severity === 'critical' || rule.severity === 'high' ? 'text-ops-alert' : 'text-ops-butterscotch'">
                  {{ rule.severity }}
                </td>
              </tr>
              <tr v-if="!guardrailMonitor.rules?.length">
                <td colspan="5" class="px-4 py-6 text-center text-ops-text-muted">
                  No database or config rules; source-enforced offline policy remains active
                </td>
              </tr>
            </tbody>
          </table>
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
const selectedHours = ref(24)
const ranges = [
  { label: '1h', hours: 1 },
  { label: '24h', hours: 24 },
  { label: '7d', hours: 168 },
]

const metrics = computed(() => data.value.request_metrics || { summary: {}, routes: [], instances: [] })
const comparison = computed(() => metrics.value.comparison || { routes: [] })
const guardrailMonitor = computed(() => data.value.guardrail_monitor || { source_counts: {}, rules: [], activity: {} })
const rangeLabel = computed(() => selectedHours.value === 168 ? '7d' : `${selectedHours.value}h`)

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
  if (rate === null || rate === undefined) return 'text-ops-text-muted'
  const n = Number(rate)
  if (n >= 95) return 'text-ops-green'
  if (n >= 80) return 'text-ops-butterscotch'
  return 'text-ops-alert'
}

function failureRateClass(rate) {
  if (rate === null || rate === undefined) return 'text-ops-text-muted'
  const n = Number(rate)
  if (n >= 25) return 'text-ops-alert'
  if (n >= 10) return 'text-ops-butterscotch'
  return 'text-ops-green'
}

function healthStateClass(state) {
  if (state === 'failing') return 'text-ops-alert'
  if (state === 'degraded') return 'text-ops-butterscotch'
  if (state === 'healthy') return 'text-ops-green'
  return 'text-ops-text-muted'
}

function providerMetric(instanceId) {
  return (metrics.value.instances || []).find(item => item.instance_id === instanceId) || {}
}

function formatPercent(value) {
  if (value === null || value === undefined) return '-'
  return `${Number(value).toFixed(1)}%`
}

function formatLatency(ms) {
  if (ms === null || ms === undefined) return '-'
  const value = Number(ms)
  if (value < 1000) return `${Math.round(value)}ms`
  if (value < 60000) return `${(value / 1000).toFixed(1)}s`
  return `${(value / 60000).toFixed(1)}m`
}

function formatSigned(value, suffix) {
  if (value === null || value === undefined) return '-'
  const number = Number(value)
  const prefix = number > 0 ? '+' : ''
  return `${prefix}${number.toFixed(1)}${suffix}`
}

function lowerDeltaClass(value) {
  if (value === null || value === undefined || Number(value) === 0) return 'text-ops-text-muted'
  return Number(value) < 0 ? 'text-ops-green' : 'text-ops-alert'
}

function higherDeltaClass(value) {
  if (value === null || value === undefined || Number(value) === 0) return 'text-ops-text-muted'
  return Number(value) > 0 ? 'text-ops-green' : 'text-ops-alert'
}

function formatNum(n) {
  if (!n) return '0'
  return Number(n).toLocaleString()
}

function formatTokenUsage(bucket, field) {
  return (bucket?.usage_reported_calls || 0) > 0 ? formatNum(bucket?.[field]) : '-'
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
    const res = await fetch(`/api/dashboard/ai-observability?hours=${selectedHours.value}`)
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    data.value = await res.json()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function selectHours(hours) {
  if (selectedHours.value === hours) return
  selectedHours.value = hours
  refresh()
}

onMounted(refresh)
</script>

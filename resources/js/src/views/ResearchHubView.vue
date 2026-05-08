<template>
  <div :class="{ 'min-h-screen bg-ops-black': !props.embedded }">
    <!-- Ops Console Header (hidden when embedded) -->
    <div v-if="!props.embedded" class="ops-header">
      <div class="ops-elbow"></div>
      <div class="ops-title-bar">
        <h1 class="ops-title">Research Hub</h1>
        <div class="ops-bar-segments">
          <div class="ops-segment bg-ops-gold"></div>
          <div class="ops-segment bg-ops-tan"></div>
          <div class="ops-segment flex-1 bg-ops-magenta"></div>
        </div>
      </div>
      <div class="ops-cap"></div>
    </div>

    <div :class="props.embedded ? 'px-4 pb-4' : 'px-4 pb-6'">
      <!-- Stats Row -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <div
          v-for="(stat, key) in reviewStats"
          :key="key"
          v-show="key !== '_total' && !EXCLUDED_CATEGORIES.includes(stat.category) && (stat.total || 0) > 0"
          class="ops-stat-card"
          :style="getStatCardStyle(stat)"
          @click="filterByType(key)"
        >
          <div class="text-2xl font-bold">{{ stat.total || 0 }}</div>
          <div class="text-xs uppercase tracking-wide opacity-70">{{ stat.label }}</div>
        </div>
      </div>

      <!-- Agent Status Panel (collapsible) -->
      <div class="mb-6">
        <button
          @click="showAgentPanel = !showAgentPanel"
          class="ops-btn ops-btn-sky w-full text-left flex items-center justify-between"
        >
          <span>Agent Status</span>
          <span class="text-sm">
            {{ agentStats.active }} active | {{ agentStats.completed_24h }} completed (24h)
          </span>
        </button>
        <Transition name="slide">
          <div v-if="showAgentPanel" class="mt-2">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div class="ops-mini-stat bg-ops-green">
                <div class="text-xl font-bold">{{ agentStats.active }}</div>
                <div class="text-xs uppercase">Active</div>
              </div>
              <div class="ops-mini-stat bg-ops-sky">
                <div class="text-xl font-bold">{{ agentStats.completed_24h }}</div>
                <div class="text-xs uppercase">Completed</div>
              </div>
              <div class="ops-mini-stat bg-ops-sunset">
                <div class="text-xl font-bold">{{ agentStats.failed_24h }}</div>
                <div class="text-xs uppercase">Failed</div>
              </div>
              <div class="ops-mini-stat bg-ops-lilac">
                <div class="text-xl font-bold">{{ agentStats.pending_reviews }}</div>
                <div class="text-xs uppercase">Pending</div>
              </div>
            </div>

            <!-- Item 1: per-agent reviewer-feedback rollup (Phase 3 audit blob signal).
                 Same data the morning digest now surfaces — visible live in the UI. -->
            <div v-if="reviewerFeedback.length" class="mt-3 p-3 bg-ops-plum/15 rounded">
              <div class="text-xs uppercase tracking-wide text-ops-text-muted mb-2">
                Reviewer Feedback (last 30 days)
              </div>
              <div class="space-y-1">
                <div
                  v-for="r in reviewerFeedback"
                  :key="r.agent_id"
                  class="flex items-center gap-3 text-xs"
                >
                  <span class="font-mono text-ops-peach min-w-[12rem]">{{ r.agent_id }}</span>
                  <span
                    class="font-bold min-w-[3rem] text-right"
                    :class="acceptanceColor(r.acceptance_rate)"
                  >{{ acceptancePct(r.acceptance_rate) }}</span>
                  <span class="text-ops-text-muted">
                    {{ r.accepted_proposals }} ✓ / {{ r.rejected_proposals }} ✗
                  </span>
                  <span v-if="topRejectReason(r)" class="text-ops-text-muted italic">
                    top: {{ topRejectReason(r) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </Transition>
      </div>

      <!-- Category Tabs + Show Expired -->
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <button
          @click="activeCategory = null"
          class="ops-tab"
          :class="{ active: activeCategory === null }"
        >
          All ({{ totalPending }})
        </button>
        <button
          v-for="(types, category) in typesByCategory"
          :key="category"
          @click="activeCategory = category"
          class="ops-tab"
          :class="{ active: activeCategory === category }"
        >
          {{ category }} ({{ getCategoryCount(category) }})
        </button>
        <label class="flex items-center gap-1.5 cursor-pointer ml-auto">
          <input
            type="checkbox"
            v-model="showExpired"
            @change="loadData"
            class="w-3.5 h-3.5 accent-ops-peach cursor-pointer"
          />
          <span class="text-xs text-ops-text-muted uppercase">Include Expired</span>
        </label>
      </div>

      <!-- Phase 5: queue ergonomics — sort + confidence threshold + daily quota nudge -->
      <div class="flex flex-wrap items-center gap-3 mb-4 p-2 bg-ops-plum/10 rounded">
        <label class="flex items-center gap-1.5">
          <span class="text-xs text-ops-text-muted uppercase">Sort</span>
          <select v-model="sortMode" class="bg-ops-black border border-ops-plum/40 text-ops-peach text-xs rounded px-2 py-0.5">
            <option value="confidence_desc">Confidence high → low</option>
            <option value="recent_first">Newest first</option>
            <option value="by_person">Group by person</option>
          </select>
        </label>
        <label class="flex items-center gap-1.5">
          <span class="text-xs text-ops-text-muted uppercase">Min conf</span>
          <input
            type="range"
            min="0" max="95" step="5"
            v-model.number="confidenceThreshold"
            class="w-32"
          />
          <span class="text-xs text-ops-peach w-8">{{ confidenceThreshold }}%</span>
        </label>
        <div class="ml-auto flex items-center gap-2 text-xs">
          <span class="text-ops-text-muted uppercase">Today</span>
          <span class="text-ops-peach font-bold">{{ dailyReviewedCount }}</span>
          <span class="text-ops-text-muted">/ {{ dailyQuotaTarget }}</span>
          <span v-if="dailyReviewedCount >= dailyQuotaTarget" class="text-green-400 font-bold">✓ goal met</span>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2 mb-4 p-2 bg-ops-sky/10 border border-ops-sky/20 rounded">
        <label class="flex flex-1 min-w-[18rem] items-center gap-2">
          <span class="text-xs text-ops-text-muted uppercase whitespace-nowrap">Target ref</span>
          <input
            v-model="targetRefQuery"
            type="search"
            class="flex-1 min-w-0 bg-ops-black border border-ops-sky/40 text-ops-peach text-xs rounded px-2 py-1 font-mono"
            placeholder="genealogy_review_packet:target-..."
            autocomplete="off"
          />
        </label>
        <span v-if="targetRefActive" class="text-xs text-ops-text-muted">
          {{ targetRefMatchCount }} match{{ targetRefMatchCount === 1 ? '' : 'es' }}
        </span>
        <span v-if="targetRefLookupLoading" class="text-xs text-ops-sky">
          loading target
        </span>
        <span v-else-if="targetRefLookupError" class="text-xs text-ops-orange">
          {{ targetRefLookupError }}
        </span>
        <button
          v-if="targetRefLinkAvailable"
          type="button"
          class="ops-btn ops-btn-sky text-xs"
          title="Copy target review link"
          @click="copyTargetRefLink"
        >
          Copy link
        </button>
        <button
          v-if="targetRefActive"
          type="button"
          class="ops-btn ops-btn-plum text-xs"
          @click="clearTargetRefFilter"
        >
          Clear
        </button>
      </div>

      <!-- Phase 5: keyboard shortcut hint -->
      <div class="text-[10px] text-ops-text-muted mb-2 uppercase tracking-wide">
        Shortcuts: <kbd>J</kbd>/<kbd>K</kbd> next/prev · <kbd>A</kbd> approve · <kbd>R</kbd> reject · <kbd>X</kbd> close detail
      </div>

      <!-- Main Content — Master/detail layout when a genealogy item is open
           (Phase 1 of Genealogy Review UI redesign). Falls back to full-width
           when no detail is open so the existing card list keeps its layout. -->
      <div :class="detailUnifiedId ? 'grid grid-cols-1 lg:grid-cols-12 gap-4' : ''">
        <div :class="detailUnifiedId ? 'lg:col-span-5 min-w-0' : ''">
          <!-- Batch Actions -->
          <div v-if="selectedItems.length > 0" class="mb-4 flex items-center gap-3 p-3 bg-ops-plum/30 rounded-lg">
            <span class="text-ops-peach">{{ selectedItems.length }} selected</span>
            <button @click="batchApprove" class="ops-btn ops-btn-green text-sm">Approve All</button>
            <button @click="batchReject" class="ops-btn ops-btn-red text-sm">Reject All</button>
            <button @click="selectedItems = []" class="ops-btn ops-btn-plum text-sm">Clear</button>
          </div>

          <!-- Loading State (initial) -->
          <div v-if="loading && items.length === 0" class="flex items-center justify-center py-16">
            <div class="ops-spinner"></div>
          </div>

          <!-- Empty State -->
          <div v-else-if="!loading && filteredItems.length === 0" class="text-center py-16 text-ops-text-muted">
            <div class="text-5xl mb-4">0</div>
            <div>{{ emptyStateMessage }}</div>
          </div>

          <!-- Item Cards -->
          <div v-else class="space-y-3" ref="itemListRef">
            <div
              v-for="item in filteredItems"
              :key="item.unified_id"
              class="relative rounded-lg"
              :class="{ 'ring-2 ring-ops-sky ring-offset-2 ring-offset-ops-black': selectedItem?.unified_id === item.unified_id }"
              :data-unified-id="item.unified_id"
            >
              <DynamicReviewCard
                :item="item"
                :selected="selectedItems.includes(item.unified_id)"
                :in-flight="isDecisionInFlight(item)"
                :approval-disabled="isApproveBlocked(item)"
                :approval-disabled-title="approveBlockedTitle(item)"
                :approval-label="approvalLabel(item)"
                @select="selectItem(item)"
                @toggle-select="toggleItemSelection(item)"
                @approve="handleApprove"
                @reject="handleReject"
                @action="handleCustomAction"
                @execute-remediation="handleExecuteRemediation"
              />
              <!-- N60: Duplicate proposal warning badge -->
              <span
                v-if="hasDuplicate(item)"
                class="absolute top-1 right-1 text-[10px] bg-ops-orange/90 text-black px-1.5 py-0.5 rounded font-semibold pointer-events-none"
                title="Multiple pending proposals exist for this person"
              >
                ⚠ Multiple proposals
              </span>
            </div>

            <!-- Load more trigger -->
            <div v-if="hasMore && !loadingMore" ref="scrollSentinelRef" class="h-1"></div>

            <!-- Loading more indicator -->
            <div v-if="loadingMore" class="flex items-center justify-center py-6">
              <div class="ops-spinner ops-spinner-sm"></div>
              <span class="ml-3 text-ops-text-muted text-sm">Loading more...</span>
            </div>

            <!-- End of list -->
            <div v-if="!hasMore && items.length > 0" class="text-center py-4 text-ops-text-muted text-xs uppercase tracking-wide">
              All {{ items.length }} items loaded
            </div>
          </div>
        </div>

        <!-- Phase 1: master/detail compare pane (visible when detailUnifiedId is set) -->
        <div v-if="detailUnifiedId" class="lg:col-span-7 min-w-0">
          <ReviewDetailPane
            :unified-id="detailUnifiedId"
            @approve="onDetailApprove"
            @reject="onDetailReject"
            @clarify="onDetailClarify"
            @defer="onDetailDefer"
            @applied="onDetailApplied"
            @close="closeDetailPane"
          />
        </div>
      </div>
    </div>

    <!-- Link to Person Modal (no Teleport - stays in component DOM for event binding) -->
    <Transition name="fade">
      <div v-if="showLinkModal" class="link-modal-overlay" @click.self="closeLinkModal">
        <div class="link-modal">
          <div class="link-modal-header">
            <span class="text-sm uppercase tracking-wide">Link Face to Person</span>
            <button @click="closeLinkModal" class="text-ops-black font-bold text-lg">&times;</button>
          </div>
          <div class="link-modal-body">
            <!-- Current face info -->
            <div v-if="linkTarget" class="mb-4 flex items-center gap-3">
              <img
                v-if="linkTarget.image_url"
                :src="linkTarget.image_url + '?_cb=' + Date.now()"
                class="w-16 h-16 rounded-lg object-cover"
                @error="handleImageError"
              />
              <div>
                <div class="text-ops-peach text-sm">{{ linkTarget.title }}</div>
                <div class="text-ops-text-muted text-xs">{{ linkTarget.summary }}</div>
              </div>
            </div>

            <!-- Search input -->
            <input
              type="text"
              :value="linkSearch"
              @input="searchPersons($event.target.value)"
              placeholder="Search by name..."
              class="link-search-input"
              autofocus
            />

            <!-- Loading -->
            <div v-if="linkLoading" class="py-4 text-center text-ops-text-muted text-sm">
              Searching...
            </div>

            <!-- Results -->
            <div v-else-if="linkResults.length" class="link-results">
              <div
                v-for="person in linkResults"
                :key="person.id"
                @click="linkToPerson(person)"
                class="link-result-item"
              >
                <div class="text-sm text-ops-peach">
                  {{ person.given_name }} {{ person.surname }}
                </div>
                <div class="text-xs text-ops-text-muted">
                  <span v-if="person.birth_date">b. {{ person.birth_date }}</span>
                  <span v-if="person.birth_date && person.death_date"> &ndash; </span>
                  <span v-if="person.death_date">d. {{ person.death_date }}</span>
                  <span v-if="!person.birth_date && !person.death_date">No dates</span>
                </div>
              </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="linkSearch.length >= 2" class="py-4 text-center text-ops-text-muted text-sm">
              No persons found
            </div>
            <div v-else class="py-4 text-center text-ops-text-muted text-sm">
              Type at least 2 characters to search
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Rename Face Modal -->
    <Transition name="fade">
      <div v-if="showRenameModal" class="link-modal-overlay" @click.self="closeRenameModal">
        <div class="link-modal">
          <div class="link-modal-header">
            <span class="text-sm uppercase tracking-wide">Correct Face Name</span>
            <button @click="closeRenameModal" class="text-ops-black font-bold text-lg">&times;</button>
          </div>
          <div class="link-modal-body">
            <!-- Current face info -->
            <div v-if="renameTarget" class="mb-4 flex items-center gap-3">
              <img
                v-if="renameTarget.image_url"
                :src="renameTarget.image_url + '?_cb=' + Date.now()"
                class="w-16 h-16 rounded-lg object-cover"
                @error="handleImageError"
              />
              <div>
                <div class="text-ops-peach text-sm">Current: {{ renameTarget.face_name || 'Unknown' }}</div>
                <div class="text-ops-text-muted text-xs">{{ renameTarget.summary }}</div>
              </div>
            </div>

            <!-- Name input with incremental search -->
            <input
              type="text"
              :value="renameSearch"
              @input="searchRenamePersons($event.target.value)"
              placeholder="Search existing names or type new..."
              class="link-search-input"
              autofocus
              @keyup.enter="renameFace"
            />

            <!-- Searching -->
            <div v-if="renameSearchLoading" class="py-2 text-center text-ops-text-muted text-sm">Searching...</div>

            <!-- Person suggestions -->
            <div v-else-if="renameResults.length" class="link-results">
              <div
                v-for="person in renameResults"
                :key="person.id"
                @click="selectRenamePerson(person)"
                class="link-result-item"
              >
                <div class="text-sm text-ops-peach">{{ person.given_name }} {{ person.surname }}</div>
                <div class="text-xs text-ops-text-muted">
                  <span v-if="person.birth_date">b. {{ person.birth_date }}</span>
                  <span v-if="person.birth_date && person.death_date"> &ndash; </span>
                  <span v-if="person.death_date">d. {{ person.death_date }}</span>
                  <span v-if="!person.birth_date && !person.death_date">No dates</span>
                </div>
              </div>
            </div>

            <div class="mt-4 flex gap-3 justify-end">
              <button @click="closeRenameModal" class="px-4 py-2 text-sm rounded-lg bg-ops-plum text-ops-text-muted hover:bg-ops-plum/80">
                Cancel
              </button>
              <button
                @click="renameFace"
                :disabled="renameLoading || !renameValue.trim()"
                class="px-4 py-2 text-sm rounded-lg font-semibold"
                :class="renameLoading || !renameValue.trim() ? 'bg-ops-gold/40 text-ops-black/50 cursor-not-allowed' : 'bg-ops-gold text-ops-black hover:brightness-110'"
              >
                {{ renameLoading ? 'Saving...' : 'Save Name' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- INF-10d: Remediation confirmation dialog -->
    <Transition name="fade">
      <div v-if="remediationConfirm" class="link-modal-overlay" @click.self="cancelRemediation">
        <div class="link-modal" style="max-width: 420px;">
          <div class="link-modal-header">
            <span class="text-sm uppercase tracking-wide">Confirm Remediation</span>
            <button @click="cancelRemediation" class="text-ops-black font-bold text-lg">&times;</button>
          </div>
          <div class="link-modal-body">
            <div class="mb-3 text-ops-peach text-sm font-semibold">
              {{ remediationConfirm.remediation?.description }}
            </div>
            <div class="mb-4 text-ops-text-muted text-xs">
              <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold mr-1"
                :class="remediationConfirm.remediation?.risk_level === 'write' ? 'bg-ops-orange text-black' : 'bg-ops-green text-black'"
              >
                {{ remediationConfirm.remediation?.risk_level?.toUpperCase() }}
              </span>
              This action will modify system state. Are you sure?
            </div>
            <div class="flex gap-3 justify-end">
              <button @click="cancelRemediation" class="px-4 py-2 text-sm rounded-lg bg-ops-plum text-ops-text-muted hover:bg-ops-plum/80">
                Cancel
              </button>
              <button @click="confirmRemediation" class="px-4 py-2 text-sm rounded-lg font-semibold bg-ops-gold text-ops-black hover:brightness-110">
                Execute
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Toast notification -->
    <Transition name="fade">
      <div
        v-if="toastMessage"
        class="fixed bottom-6 right-6 z-[10001] px-5 py-3 rounded-lg text-sm font-medium shadow-lg"
        :class="toastType === 'error' ? 'bg-red-600 text-white' : 'bg-ops-green text-ops-black'"
      >
        {{ toastMessage }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/utils/api'
import DynamicReviewCard from '@/components/review/DynamicReviewCard.vue'
import ReviewDetailPane from '@/components/review/ReviewDetailPane.vue'
import { resolveSurfaceThemeStyle } from '@/utils/reviewSchemaStyles'

// Props for embedded mode (inside Knowledge Hub)
const props = defineProps({
  embedded: { type: Boolean, default: false }
})
const route = useRoute()
const router = useRouter()

// State
const loading = ref(true)
const reviewTypes = ref({})
const typesByCategory = ref({})
const reviewStats = ref({})
const agentStats = ref({ active: 0, completed_24h: 0, failed_24h: 0, pending_reviews: 0 })
// Item 1: per-agent reviewer-feedback rollup from agentStatus endpoint.
// Same shape the morning digest surfaces — array sorted by acceptance desc.
const reviewerFeedback = ref([])
function acceptancePct(rate) {
  if (rate === null || rate === undefined) return 'n/a'
  return Math.round(rate * 100) + '%'
}
function acceptanceColor(rate) {
  if (rate === null || rate === undefined) return 'text-ops-text-muted'
  if (rate >= 0.70) return 'text-green-400'
  if (rate >= 0.40) return 'text-yellow-400'
  return 'text-red-400'
}
function topRejectReason(r) {
  const h = r.reject_reason_histogram
  if (!h || typeof h !== 'object') return null
  const keys = Object.keys(h)
  return keys.length ? keys[0] : null
}
const items = ref([])
// Phase 1 (Genealogy Review UI redesign): which item is currently open
// in the side-by-side detail pane. Null = pane closed, list takes full width.
const detailUnifiedId = ref(null)
// Phase 5 (queue ergonomics): sort + threshold + per-day quota nudge.
const sortMode = ref('confidence_desc')   // 'confidence_desc' | 'recent_first' | 'by_person'
const confidenceThreshold = ref(0)        // 0..95 in 5% increments
const dailyQuotaTarget = ref(20)
const targetRefQuery = ref('')
const targetRefLookupLoading = ref(false)
const targetRefLookupMiss = ref(null)
const targetRefLookupError = ref('')

// F4 fix: build the storage key from LOCAL date components on each
// access (not UTC, not via a Vue computed). The previous version used
// `new Date().toISOString().slice(0,10)` inside a computed with no
// reactive dependencies, so a tab open past midnight kept yesterday's
// key and counted today's actions against yesterday's storage row.
// Using local date so the quota matches the operator's calendar day.
function todayQuotaKey() {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `plos_review_quota_${y}-${m}-${day}`
}
const dailyReviewedCount = ref(parseInt(localStorage.getItem(todayQuotaKey()) || '0', 10))
let lastSeenQuotaKey = todayQuotaKey()
function bumpDailyReviewed() {
  const key = todayQuotaKey()
  if (key !== lastSeenQuotaKey) {
    // Date rolled over while the tab was open — reset to whatever's on
    // disk for the new day (usually 0) before incrementing.
    dailyReviewedCount.value = parseInt(localStorage.getItem(key) || '0', 10)
    lastSeenQuotaKey = key
  }
  dailyReviewedCount.value++
  try { localStorage.setItem(key, String(dailyReviewedCount.value)) } catch (e) { /* localStorage may be disabled */ }
}
const activeCategory = ref(null)
const selectedItem = ref(null)
const selectedItems = ref([])
const inFlightDecisions = ref(new Set())
const showAgentPanel = ref(false)
const showExpired = ref(false)
const initializingFromRoute = ref(true)

// Pagination / infinite scroll
const PAGE_SIZE = 25
const hasMore = ref(false)
const loadingMore = ref(false)
const itemListRef = ref(null)
const scrollSentinelRef = ref(null)
let scrollObserver = null

// Link to Person modal state
const showLinkModal = ref(false)
const linkTarget = ref(null)
const linkSearch = ref('')
const linkResults = ref([])
const linkLoading = ref(false)
let linkSearchTimeout = null

// Rename Face modal state
const showRenameModal = ref(false)
const renameTarget = ref(null)
const renameValue = ref('')
const renameLoading = ref(false)
const renameSearch = ref('')
const renameResults = ref([])
const renameSearchLoading = ref(false)
let renameSearchTimeout = null

// Agent session inline viewer state
const showAgentSession = ref(false)
const agentSessionAgentId = ref(null)
const agentSessionEpisodes = ref([])
const agentSessionLoading = ref(false)

async function toggleAgentSession(agentId) {
  if (agentSessionAgentId.value === agentId && showAgentSession.value) {
    showAgentSession.value = false
    return
  }
  agentSessionAgentId.value = agentId
  showAgentSession.value = true
  agentSessionLoading.value = true
  agentSessionEpisodes.value = []
  try {
    const resp = await api.get(`/research-hub/agents/${agentId}/episodes`, { params: { limit: 10 } })
    agentSessionEpisodes.value = resp.episodes || []
  } catch {
    agentSessionEpisodes.value = []
  } finally {
    agentSessionLoading.value = false
  }
}

// Toast notification
const toastMessage = ref('')
const toastType = ref('success')
let toastTimeout = null
const showToast = (msg, type = 'success') => {
  toastMessage.value = msg
  toastType.value = type
  clearTimeout(toastTimeout)
  toastTimeout = setTimeout(() => { toastMessage.value = '' }, 4000)
}

// All categories shown — face match items (typo/nickname) are actionable in review panel
const EXCLUDED_CATEGORIES = []

const normalizeRouteString = (value) => {
  const normalized = `${value ?? ''}`.trim()
  return normalized === '' ? null : normalized
}

const normalizeTargetRef = (value) => {
  const text = `${value ?? ''}`.trim()
  if (text === '') return null
  const full = text.match(/^genealogy_review_packet:target-[a-f0-9]{12}$/i)
  if (full) return full[0].toLowerCase()
  const short = text.match(/^target-[a-f0-9]{12}$/i)
  return short ? `genealogy_review_packet:${short[0].toLowerCase()}` : null
}

const itemTargetRef = (item) => {
  return normalizeTargetRef(item?.target_ref)
    || normalizeTargetRef(item?.review_focus?.target_ref)
    || normalizeTargetRef(item?.details?.target_ref)
}

const scrollItemIntoView = async (unifiedId) => {
  if (!unifiedId) return
  await nextTick()
  const selector = `[data-unified-id="${unifiedId}"]`
  const element = itemListRef.value?.querySelector(selector) || document.querySelector(selector)
  if (element) {
    element.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
}

const focusLinkedItem = async () => {
  const unifiedId = normalizeRouteString(route.query.unified_id)
  if (!unifiedId) {
    return
  }

  let target = items.value.find((item) => item.unified_id === unifiedId) || null

  if (!target) {
    try {
      const response = await api.get(`/reviews/${encodeURIComponent(unifiedId)}`)
      target = response.item || response.data?.item || null
    } catch (error) {
      console.error('Failed to load focused review item:', error)
      return
    }

    if (!target) {
      return
    }

    const existingIndex = items.value.findIndex((item) => item.unified_id === target.unified_id)
    if (existingIndex === -1) {
      items.value.unshift(target)
    } else {
      items.value.splice(existingIndex, 1, target)
    }
  }

  selectedItem.value = target
  await scrollItemIntoView(unifiedId)
}

const focusTargetRefItem = async (item) => {
  if (!item?.unified_id) return

  selectedItem.value = item
  if (isReviewPacket(item)) {
    detailUnifiedId.value = item.unified_id
  }
  await scrollItemIntoView(item.unified_id)
}

const loadedTargetRefItem = (value) => {
  if (!value) return null
  return items.value.find(item => itemTargetRef(item) === value) || null
}

const upsertReviewItem = (item) => {
  if (!item?.unified_id) return null

  const existingIndex = items.value.findIndex(candidate => candidate.unified_id === item.unified_id)
  if (existingIndex === -1) {
    items.value.unshift(item)
  } else {
    items.value.splice(existingIndex, 1, item)
  }

  return items.value.find(candidate => candidate.unified_id === item.unified_id) || item
}

const ensureTargetRefLoaded = async () => {
  const requested = targetRef.value
  if (!requested) return

  const existing = loadedTargetRefItem(requested)
  if (existing) {
    await focusTargetRefItem(existing)
    return
  }

  if (targetRefLookupLoading.value || targetRefLookupMiss.value === requested) {
    return
  }

  targetRefLookupLoading.value = true
  targetRefLookupError.value = ''

  try {
    const response = await api.get('/research-hub/items/by-target-ref', {
      params: { target_ref: requested },
    })

    if (targetRef.value !== requested) {
      return
    }

    const item = response.item || response.data?.item || null
    if (item && itemTargetRef(item) === requested) {
      targetRefLookupMiss.value = null
      const inserted = upsertReviewItem(item)
      await focusTargetRefItem(inserted)
      return
    }

    targetRefLookupMiss.value = requested
    targetRefLookupError.value = 'Target not found'
  } catch (error) {
    if (targetRef.value !== requested) {
      return
    }
    if (error?.response?.status === 404) {
      targetRefLookupMiss.value = requested
      targetRefLookupError.value = 'Target not found'
    } else {
      targetRefLookupError.value = 'Target lookup failed'
    }
  } finally {
    targetRefLookupLoading.value = false
    if (targetRef.value && targetRef.value !== requested) {
      await ensureTargetRefLoaded()
    }
  }
}

// Computed
const totalPending = computed(() => {
  if (!EXCLUDED_CATEGORIES.length) return reviewStats.value._total || 0
  return Object.entries(reviewStats.value)
    .filter(([key, s]) => key !== '_total' && !EXCLUDED_CATEGORIES.includes(s.category))
    .reduce((sum, [, s]) => sum + (s.total || 0), 0)
})

const emptyStateMessage = computed(() => {
  if (targetRefActive.value) {
    if (targetRefLookupLoading.value) {
      return `Loading packet for ${targetRef.value}.`
    }
    if (targetRefLookupMiss.value === targetRef.value) {
      return `No pending packet was found for ${targetRef.value}.`
    }
    return targetRef.value
      ? `No loaded packet matches ${targetRef.value}.`
      : 'Enter a full genealogy review packet target ref.'
  }

  const requestedId = normalizeRouteString(route.query.unified_id)
  if (requestedId) {
    return `No pending review item is available for ${requestedId} with the current filters.`
  }

  if (activeCategory.value === 'genealogy') {
    return 'No pending genealogy review items.'
  }

  return 'No pending items'
})

const filteredItems = computed(() => {
  let result = items.value.filter(item => !EXCLUDED_CATEGORIES.includes(item.category))
  if (targetRefQuery.value.trim() !== '' && !targetRef.value) {
    return []
  }
  if (targetRef.value) {
    return result.filter(item => itemTargetRef(item) === targetRef.value)
  }
  if (activeCategory.value) result = result.filter(item => item.category === activeCategory.value)
  // Phase 5: confidence threshold filter (NULL confidence always passes — system alerts etc.)
  if (confidenceThreshold.value > 0) {
    const min = confidenceThreshold.value / 100
    result = result.filter(item => item.confidence === null || item.confidence === undefined || (item.confidence ?? 1) >= min)
  }
  // Phase 5: sort
  const sorted = [...result]
  if (sortMode.value === 'confidence_desc') {
    sorted.sort((a, b) => (b.confidence ?? -1) - (a.confidence ?? -1))
  } else if (sortMode.value === 'recent_first') {
    sorted.sort((a, b) => (new Date(b.created_at).getTime() || 0) - (new Date(a.created_at).getTime() || 0))
  } else if (sortMode.value === 'by_person') {
    // Cluster items by person_id, then within each cluster by confidence desc.
    sorted.sort((a, b) => {
      const ap = a.person_id ?? 0, bp = b.person_id ?? 0
      if (ap !== bp) return ap - bp
      return (b.confidence ?? -1) - (a.confidence ?? -1)
    })
  }
  return sorted
})

const targetRef = computed(() => normalizeTargetRef(targetRefQuery.value))
const targetRefActive = computed(() => targetRefQuery.value.trim() !== '')
const targetRefMatchCount = computed(() => {
  if (!targetRef.value) return 0
  return items.value.filter(item => itemTargetRef(item) === targetRef.value).length
})
const targetRefLinkAvailable = computed(() => Boolean(targetRef.value))
const targetRefShareUrl = computed(() => {
  if (!targetRef.value || typeof window === 'undefined') return ''
  const url = new URL(window.location.pathname, window.location.origin)
  url.searchParams.set('target_ref', targetRef.value)
  return url.toString()
})

const clearTargetRefFilter = () => {
  targetRefQuery.value = ''
  targetRefLookupMiss.value = null
  targetRefLookupError.value = ''
}

const copyTargetRefLink = async () => {
  if (!targetRefShareUrl.value) {
    showToast('Enter a valid target ref first', 'error')
    return
  }
  if (!navigator?.clipboard?.writeText) {
    showToast('Clipboard unavailable', 'error')
    return
  }

  try {
    await navigator.clipboard.writeText(targetRefShareUrl.value)
    showToast('Target link copied')
  } catch (error) {
    showToast('Failed to copy target link', 'error')
  }
}

const syncTargetRefQueryParam = () => {
  if (!targetRefActive.value) {
    if (route.query.target_ref !== undefined) {
      router.replace({ query: { ...route.query, target_ref: undefined } }).catch(() => {})
    }
    return
  }

  const normalized = targetRef.value
  if (!normalized || normalizeTargetRef(route.query.target_ref) === normalized) {
    return
  }

  router.replace({ query: { ...route.query, target_ref: normalized } }).catch(() => {})
}

// N60: Duplicate proposal warning — persons with >1 pending proposal
const duplicatePersonIds = computed(() => {
  const counts = {}
  items.value.forEach(item => {
    const pid = item.person_id
    if (pid) counts[pid] = (counts[pid] || 0) + 1
  })
  return new Set(Object.keys(counts).filter(pid => counts[pid] > 1))
})

const hasDuplicate = (item) => item.person_id && duplicatePersonIds.value.has(String(item.person_id))

// Strip raw JSON blobs from agent phase output stored in summary fields
const cleanSummary = (val) => {
  if (!val || typeof val !== 'string') return val
  // Strip matched code fences
  let cleaned = val.replace(/```json?\s*\n[\s\S]*?```/g, '').trim()
  // Strip unclosed code fences (last ``` to end of string — agent phase output leak)
  cleaned = cleaned.replace(/```json?\s*\n[\s\S]*$/, '').trim()
  // Strip large JSON blobs (global)
  cleaned = cleaned.replace(/[\[{][\s\S]{80,}[\]}]/g, '[research data]')
  // Deduplicate repeated paragraphs
  const paragraphs = cleaned.split(/\n{2,}/)
  const seen = new Set()
  const deduped = paragraphs.filter(p => { const k = p.trim(); if (!k || seen.has(k)) return false; seen.add(k); return true })
  cleaned = deduped.join('\n\n').trim()
  if (cleaned.length > 600) cleaned = cleaned.slice(0, 600).trimEnd() + '...'
  return cleaned || val
}

// Methods
const getCategoryCount = (category) => {
  return Object.values(reviewStats.value)
    .filter(s => s.category === category)
    .reduce((sum, s) => sum + (s.total || 0), 0)
}

const filterByType = (typeName) => {
  const type = reviewTypes.value[typeName]
  if (type) {
    activeCategory.value = type.category
  }
}

const getStatCardStyle = (stat) => resolveSurfaceThemeStyle(stat?.color, 'ops-plum')

const loadData = async () => {
  loading.value = true
  try {
    const itemParams = { limit: PAGE_SIZE, offset: 0 }
    if (showExpired.value) itemParams.include_expired = true
    // Item 1: also fetch agents/status to pick up reviewer_feedback rollup.
    // Done in parallel — no extra latency. Failure is non-blocking.
    const [statsResp, itemsResp, agentStatusResp] = await Promise.all([
      api.get('/research-hub/stats'),
      api.get('/research-hub/items', { params: itemParams }),
      api.get('/research-hub/agents/status').catch(() => null),
    ])

    reviewStats.value = statsResp.reviews || {}
    agentStats.value = statsResp.agents || agentStats.value
    items.value = itemsResp.items || []
    hasMore.value = itemsResp.has_more || false
    reviewTypes.value = itemsResp.types || {}
    reviewerFeedback.value = (agentStatusResp && Array.isArray(agentStatusResp.reviewer_feedback))
      ? agentStatusResp.reviewer_feedback
      : []

    // Group types by category (excluding faces when embedded)
    const grouped = {}
    for (const [name, type] of Object.entries(reviewTypes.value)) {
      const cat = type.category
      if (EXCLUDED_CATEGORIES.includes(cat)) continue
      if (!grouped[cat]) grouped[cat] = {}
      grouped[cat][name] = type
    }
    typesByCategory.value = grouped

    // Set up scroll observer after items render
    await nextTick()
    setupScrollObserver()
    await focusLinkedItem()
    await ensureTargetRefLoaded()
  } catch (e) {
    console.error('Failed to load research hub data:', e)
  } finally {
    loading.value = false
  }
}

const loadMore = async () => {
  if (loadingMore.value || !hasMore.value) return
  loadingMore.value = true
  try {
    const params = { limit: PAGE_SIZE, offset: items.value.length }
    if (activeCategory.value) params.category = activeCategory.value
    if (showExpired.value) params.include_expired = true
    const resp = await api.get('/research-hub/items', { params })
    const newItems = resp.items || []
    if (newItems.length > 0) {
      items.value.push(...newItems)
    }
    hasMore.value = resp.has_more || false
    // Re-observe after new items render
    await nextTick()
    setupScrollObserver()
    await ensureTargetRefLoaded()
  } catch (e) {
    console.error('Failed to load more items:', e)
  } finally {
    loadingMore.value = false
  }
}

const findScrollParent = (el) => {
  let parent = el?.parentElement
  while (parent) {
    const style = getComputedStyle(parent)
    if (style.overflowY === 'auto' || style.overflowY === 'scroll') return parent
    parent = parent.parentElement
  }
  return null
}

const setupScrollObserver = () => {
  // Clean up previous observer
  if (scrollObserver) {
    scrollObserver.disconnect()
  }
  if (!scrollSentinelRef.value) return

  const scrollRoot = props.embedded ? findScrollParent(scrollSentinelRef.value) : null

  scrollObserver = new IntersectionObserver(
    (entries) => {
      if (entries[0]?.isIntersecting && hasMore.value && !loadingMore.value) {
        loadMore()
      }
    },
    { root: scrollRoot, rootMargin: '200px' }
  )
  scrollObserver.observe(scrollSentinelRef.value)
}

const selectItem = (item) => {
  selectedItem.value = item
  // Open the master/detail compare for any genealogy review type the
  // enrichment service understands. Phase 1 only wired finding+merge;
  // change_proposal was added in the post-deploy gap fix after the
  // operator's screenshot showed event_add (change_proposal) items
  // not opening the pane.
  const detailTypes = ['genealogy_finding', 'genealogy_merge', 'change_proposal', 'genealogy_review_packet']
  const detailType = item?.source || item?.review_type
  if (detailType && detailTypes.includes(detailType)) {
    detailUnifiedId.value = item.unified_id
  }
}

const closeDetailPane = () => {
  detailUnifiedId.value = null
}

const onDetailApprove = (payload) => {
  const unifiedId = decisionPayloadUnifiedId(payload)
  if (!unifiedId) return

  items.value = items.value.filter(i => i.unified_id !== unifiedId)
  if (selectedItem.value?.unified_id === unifiedId) selectedItem.value = null
  detailUnifiedId.value = null
  bumpDailyReviewed()
  showToast('Item approved')
  updateStats()
}

const onDetailReject = (payload) => {
  const unifiedId = decisionPayloadUnifiedId(payload)
  if (!unifiedId) return

  items.value = items.value.filter(i => i.unified_id !== unifiedId)
  if (selectedItem.value?.unified_id === unifiedId) selectedItem.value = null
  detailUnifiedId.value = null
  bumpDailyReviewed()
  showToast('Item rejected')
  updateStats()
}

const onDetailClarify = (payload) => {
  onDetailSoftDecision(payload, 'Clarification requested')
}

const onDetailDefer = (payload) => {
  onDetailSoftDecision(payload, 'Item deferred')
}

const onDetailSoftDecision = (payload, message) => {
  const unifiedId = decisionPayloadUnifiedId(payload)
  if (!unifiedId) return

  const item = items.value.find(i => i.unified_id === unifiedId)
  if (item && payload?.result?.status) {
    item.status = payload.result.status
  }
  updatePacketStatus(item, payload?.result?.packet_status)
  showToast(message)
  updateStats()
}

const decisionPayloadUnifiedId = (payload) => {
  if (payload && typeof payload === 'object') {
    return payload.unifiedId || payload.unified_id || null
  }
  return payload || null
}

// Phase 3: per-field apply succeeded — close detail and refresh list.
const onDetailApplied = ({ unifiedId, result }) => {
  // Remove from the list only when the row went to approved server-side.
  // Stays-pending (undecided > 0 or failed > 0) keeps the row visible so
  // the operator can return to it. Toast is built from the structured
  // result fields so the operator sees what actually happened
  // (F1+F2+F3 introduced applied/kept_on_file/undecided/failed counters).
  if (result?.final_status === 'approved') {
    items.value = items.value.filter(i => i.unified_id !== unifiedId)
    if (selectedItem.value?.unified_id === unifiedId) selectedItem.value = null
    detailUnifiedId.value = null
    bumpDailyReviewed()
    const parts = [`${result.applied ?? 0} applied`, `${result.rejected ?? 0} rejected`]
    if (result.kept_on_file) parts.push(`${result.kept_on_file} kept on-file`)
    showToast(`Approved — ${parts.join(', ')}`)
  } else {
    const parts = []
    if (result?.applied) parts.push(`${result.applied} applied`)
    if (result?.kept_on_file) parts.push(`${result.kept_on_file} kept on-file`)
    if (result?.rejected) parts.push(`${result.rejected} rejected`)
    if (result?.undecided) parts.push(`${result.undecided} undecided`)
    if (result?.failed) parts.push(`${result.failed} failed`)
    const summary = parts.length ? parts.join(', ') : 'no change'
    showToast(`Pending — ${summary}`, 'warn')
  }
  updateStats()
}

const toggleItemSelection = (item) => {
  if (!isBatchSelectable(item)) return

  const idx = selectedItems.value.indexOf(item.unified_id)
  if (idx >= 0) {
    selectedItems.value.splice(idx, 1)
  } else {
    selectedItems.value.push(item.unified_id)
  }
}

const isDecisionInFlight = (item) => {
  return Boolean(item?.unified_id && inFlightDecisions.value.has(item.unified_id))
}

const beginDecision = (item) => {
  const unifiedId = item?.unified_id
  if (!unifiedId || inFlightDecisions.value.has(unifiedId)) return null

  const next = new Set(inFlightDecisions.value)
  next.add(unifiedId)
  inFlightDecisions.value = next

  return unifiedId
}

const finishDecision = (unifiedId) => {
  if (!unifiedId) return

  const next = new Set(inFlightDecisions.value)
  next.delete(unifiedId)
  inFlightDecisions.value = next
}

const handleApprove = async (item) => {
  if (isReviewPacket(item)) {
    selectItem(item)
    showToast('Open packet detail to review source, claim, and preview context before marking reviewed', 'info')
    return
  }

  const blockedReason = packetApprovalBlockReason(item)
  if (blockedReason) {
    showToast(blockedReason, 'error')
    return
  }

  const unifiedId = beginDecision(item)
  if (!unifiedId) return

  try {
    const resp = await api.post(`/research-hub/approve/${unifiedId}`)
    if (resp.success === false) {
      showToast(resp.error || 'Failed to approve', 'error')
      return
    }
    items.value = items.value.filter(i => i.unified_id !== unifiedId)
    if (selectedItem.value?.unified_id === unifiedId) {
      selectedItem.value = null
    }
    if (detailUnifiedId.value === unifiedId) {
      detailUnifiedId.value = null
    }
    bumpDailyReviewed()
    showToast('Item approved')
    updateStats()
  } catch (e) {
    console.error('Approve failed:', e)
    showToast(e.response?.data?.error || 'Failed to approve item', 'error')
  } finally {
    finishDecision(unifiedId)
  }
}

function isReviewPacket(item) {
  return item?.review_type === 'genealogy_review_packet' || item?.source === 'genealogy_review_packet'
}

function isBatchSelectable(item) {
  return item?.batch_enabled === true && !isReviewPacket(item)
}

function approvalLabel(item) {
  return isReviewPacket(item) ? 'Open packet' : 'Approve'
}

function updatePacketStatus(item, packetStatus) {
  if (!item || !isReviewPacket(item) || !packetStatus) return

  item.packet_status = packetStatus
  if (item.details && typeof item.details === 'object') {
    item.details.packet_status = packetStatus
  }
  if (item.review_focus && typeof item.review_focus === 'object') {
    item.review_focus.packet_status = packetStatus
  }
}

function packetValidationErrorCount(item) {
  if (!isReviewPacket(item)) return 0
  const errors = item?.details?.validation?.errors
  return Array.isArray(errors) ? errors.length : 0
}

function isApproveBlocked(item) {
  return packetApprovalBlockReason(item) !== null
}

function approveBlockedTitle(item) {
  return packetApprovalBlockReason(item) || ''
}

function packetApprovalBlockReason(item) {
  if (!isReviewPacket(item)) return null

  const count = packetValidationErrorCount(item)
  if (count > 0) {
    return `Resolve ${count} validation ${count === 1 ? 'error' : 'errors'} before approving preview`
  }

  const focus = item?.review_focus
  if (focus && focus.approval_ready === false) {
    return 'Open the packet detail and resolve preview-only readiness before marking reviewed'
  }

  return null
}

const handleReject = async (item) => {
  if (isReviewPacket(item)) {
    selectItem(item)
    showToast('Open packet detail to reject or request clarification with packet context visible', 'info')
    return
  }

  const unifiedId = beginDecision(item)
  if (!unifiedId) return

  try {
    const resp = await api.post(`/research-hub/reject/${unifiedId}`)
    if (resp.success === false) {
      showToast(resp.error || 'Failed to reject', 'error')
      return
    }
    items.value = items.value.filter(i => i.unified_id !== unifiedId)
    if (selectedItem.value?.unified_id === unifiedId) {
      selectedItem.value = null
    }
    if (detailUnifiedId.value === unifiedId) {
      detailUnifiedId.value = null
    }
    bumpDailyReviewed()
    showToast('Item rejected')
    updateStats()
  } catch (e) {
    console.error('Reject failed:', e)
    showToast(e.response?.data?.error || 'Failed to reject item', 'error')
  } finally {
    finishDecision(unifiedId)
  }
}

const handleIgnore = async (item) => {
  const unifiedId = beginDecision(item)
  if (!unifiedId) return

  try {
    const resp = await api.post(`/research-hub/ignore/${unifiedId}`)
    if (resp.success === false) {
      showToast(resp.error || 'Failed to dismiss', 'error')
      return
    }
    items.value = items.value.filter(i => i.unified_id !== unifiedId)
    if (selectedItem.value?.unified_id === unifiedId) {
      selectedItem.value = null
    }
    if (detailUnifiedId.value === unifiedId) {
      detailUnifiedId.value = null
    }
    showToast('Item dismissed')
    updateStats()
  } catch (e) {
    console.error('Ignore failed:', e)
    showToast(e.response?.data?.error || 'Failed to dismiss item', 'error')
  } finally {
    finishDecision(unifiedId)
  }
}

// INF-10d: Execute remediation action
const remediationConfirm = ref(null)

const handleExecuteRemediation = async (item) => {
  const rem = item.remediation
  if (!rem?.executable) {
    showToast('Action not available', 'error')
    return
  }

  // Write-risk: show confirmation
  if (rem.requires_confirmation) {
    remediationConfirm.value = item
    return
  }

  await doExecuteRemediation(item, false)
}

const confirmRemediation = async () => {
  if (remediationConfirm.value) {
    await doExecuteRemediation(remediationConfirm.value, true)
    remediationConfirm.value = null
  }
}

const cancelRemediation = () => {
  remediationConfirm.value = null
}

const doExecuteRemediation = async (item, confirmed = false) => {
  const unifiedId = beginDecision(item)
  if (!unifiedId) return

  try {
    const resp = await api.post(`/research-hub/remediation/${unifiedId}/execute`, { confirmed })
    if (resp.success === false) {
      showToast(resp.error || 'Remediation failed', 'error')
      return
    }
    items.value = items.value.filter(i => i.unified_id !== unifiedId)
    if (selectedItem.value?.unified_id === unifiedId) {
      selectedItem.value = null
    }
    if (detailUnifiedId.value === unifiedId) {
      detailUnifiedId.value = null
    }
    showToast(resp.message || 'Remediation executed')
    updateStats()
  } catch (e) {
    console.error('Remediation failed:', e)
    showToast(e.response?.data?.error || 'Failed to execute remediation', 'error')
  } finally {
    finishDecision(unifiedId)
  }
}

const handleCustomAction = async (item, action) => {
  if (action.name === 'ignore') {
    await handleIgnore(item)
  } else if (action.name === 'clarify' || action.name === 'defer') {
    await handleSoftDecision(item, action.name)
  } else if (action.name === 'link') {
    linkTarget.value = item
    linkSearch.value = ''
    linkResults.value = []
    linkLoading.value = false
    showLinkModal.value = true
  } else if (action.name === 'rename') {
    renameTarget.value = item
    renameValue.value = item.face_name || ''
    renameSearch.value = item.face_name || ''
    renameResults.value = []
    renameLoading.value = false
    showRenameModal.value = true
  }
}

const handleSoftDecision = async (item, actionName) => {
  const unifiedId = beginDecision(item)
  if (!unifiedId) return

  try {
    const resp = await api.post(`/research-hub/${actionName}/${unifiedId}`)
    if (resp.success === false) {
      showToast(resp.error || `Failed to ${actionName}`, 'error')
      return
    }
    if (resp.status) {
      item.status = resp.status
    }
    updatePacketStatus(item, resp.packet_status)
    showToast(actionName === 'clarify' ? 'Clarification requested' : 'Item deferred')
    updateStats()
  } catch (e) {
    console.error(`${actionName} failed:`, e)
    showToast(e.response?.data?.error || `Failed to ${actionName} item`, 'error')
  } finally {
    finishDecision(unifiedId)
  }
}

const batchApprove = async () => {
  const ids = selectableSelectedIds()
  if (!ids.length) return

  try {
    await api.post('/research-hub/batch/approve', { ids })
    items.value = items.value.filter(i => !ids.includes(i.unified_id))
    selectedItems.value = []
    selectedItem.value = null
    updateStats()
  } catch (e) {
    console.error('Batch approve failed:', e)
  }
}

const batchReject = async () => {
  const ids = selectableSelectedIds()
  if (!ids.length) return

  try {
    await api.post('/research-hub/batch/reject', { ids })
    items.value = items.value.filter(i => !ids.includes(i.unified_id))
    selectedItems.value = []
    selectedItem.value = null
    updateStats()
  } catch (e) {
    console.error('Batch reject failed:', e)
  }
}

function selectableSelectedIds() {
  const selected = new Set(selectedItems.value)
  return items.value
    .filter(item => selected.has(item.unified_id) && isBatchSelectable(item))
    .map(item => item.unified_id)
}

const updateStats = async () => {
  try {
    const resp = await api.get('/research-hub/stats')
    reviewStats.value = resp.reviews || {}
    agentStats.value = resp.agents || agentStats.value
  } catch (e) {
    console.error('Stats update failed:', e)
  }
}

const formatContextValue = (value) => {
  if (Array.isArray(value)) return value.join(', ')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

const handleImageError = (e) => {
  e.target.style.display = 'none'
}

// Link to Person methods
const searchPersons = (query) => {
  clearTimeout(linkSearchTimeout)
  linkSearch.value = query
  if (query.length < 2) {
    linkResults.value = []
    return
  }
  linkSearchTimeout = setTimeout(async () => {
    linkLoading.value = true
    try {
      const treeId = linkTarget.value?.tree_id
      if (!treeId) return
      const resp = await api.get(`/genealogy/trees/${treeId}/persons/search`, { params: { q: query, limit: 20 } })
      linkResults.value = resp.data?.persons || resp.persons || (Array.isArray(resp.data) ? resp.data : []) || []
    } catch (e) {
      console.error('Person search failed:', e)
      linkResults.value = []
    } finally {
      linkLoading.value = false
    }
  }, 300)
}

const linkToPerson = async (person) => {
  if (!linkTarget.value) return
  try {
    const itemId = linkTarget.value.id
    const resp = await api.post(`/genealogy/face-match-queue/${itemId}/link`, { person_id: person.id })
    showToast(resp.message || `Linked to ${person.given_name} ${person.surname}`)
    // Remove from list
    items.value = items.value.filter(i => i.unified_id !== linkTarget.value.unified_id)
    if (selectedItem.value?.unified_id === linkTarget.value.unified_id) {
      selectedItem.value = null
    }
    showLinkModal.value = false
    linkTarget.value = null
    updateStats()
  } catch (e) {
    console.error('Link to person failed:', e)
    showToast(e.response?.data?.error?.message || 'Failed to link face to person', 'error')
  }
}

const closeLinkModal = () => {
  showLinkModal.value = false
  linkTarget.value = null
}

const renameFace = async () => {
  if (!renameTarget.value || !renameValue.value.trim()) return
  renameLoading.value = true
  try {
    const itemId = renameTarget.value.id
    const resp = await api.post(`/genealogy/face-match-queue/${itemId}/rename`, { new_name: renameValue.value.trim() })
    showToast(resp.message || `Face renamed to ${renameValue.value.trim()}`)
    // Update the item in-place
    const idx = items.value.findIndex(i => i.unified_id === renameTarget.value.unified_id)
    if (idx >= 0) {
      items.value[idx].face_name = renameValue.value.trim()
      items.value[idx].title = `Face: ${renameValue.value.trim()}`
    }
    showRenameModal.value = false
    renameTarget.value = null
  } catch (e) {
    console.error('Rename face failed:', e)
    showToast(e.response?.data?.error?.message || 'Failed to rename face', 'error')
  } finally {
    renameLoading.value = false
  }
}

const searchRenamePersons = (query) => {
  renameSearch.value = query
  renameValue.value = query
  clearTimeout(renameSearchTimeout)
  if (query.length < 2) { renameResults.value = []; return }
  renameSearchTimeout = setTimeout(async () => {
    renameSearchLoading.value = true
    try {
      const treeId = renameTarget.value?.tree_id
      const resp = await api.get(`/genealogy/trees/${treeId}/persons/search`, { params: { q: query, limit: 20 } })
      renameResults.value = resp.data?.persons || resp.persons || (Array.isArray(resp.data) ? resp.data : []) || []
    } catch (e) {
      renameResults.value = []
    } finally {
      renameSearchLoading.value = false
    }
  }, 300)
}

const selectRenamePerson = (person) => {
  renameValue.value = `${person.given_name} ${person.surname}`.trim()
  renameSearch.value = renameValue.value
  renameResults.value = []
}

const closeRenameModal = () => {
  showRenameModal.value = false
  renameTarget.value = null
  renameSearch.value = ''
  renameResults.value = []
}

// When category changes, reload from offset 0
watch(activeCategory, async () => {
  if (initializingFromRoute.value) {
    return
  }

  items.value = []
  hasMore.value = false
  selectedItem.value = null
  selectedItems.value = []
  loading.value = true
  try {
    const params = { limit: PAGE_SIZE, offset: 0 }
    if (activeCategory.value) params.category = activeCategory.value
    if (showExpired.value) params.include_expired = true
    const resp = await api.get('/research-hub/items', { params })
    items.value = resp.items || []
    hasMore.value = resp.has_more || false
    reviewTypes.value = resp.types || reviewTypes.value
    await nextTick()
    setupScrollObserver()
    await focusLinkedItem()
    await ensureTargetRefLoaded()
  } catch (e) {
    console.error('Failed to reload items:', e)
  } finally {
    loading.value = false
  }
})

watch(
  () => route.query.unified_id,
  async () => {
    await focusLinkedItem()
  }
)

watch(
  () => route.query.target_ref,
  (value) => {
    targetRefQuery.value = normalizeRouteString(value) || ''
  }
)

watch(targetRefQuery, syncTargetRefQueryParam)

watch(
  targetRef,
  async (value, oldValue) => {
    if (value !== oldValue) {
      targetRefLookupMiss.value = null
      targetRefLookupError.value = ''
    }
    if (value) {
      await ensureTargetRefLoaded()
    }
  }
)

// Lifecycle
onMounted(async () => {
  const requestedCategory = normalizeRouteString(route.query.category)
  const includeExpired = ['1', 'true', 'yes'].includes(`${route.query.include_expired ?? ''}`.toLowerCase())
  const requestedTargetRef = normalizeRouteString(route.query.target_ref)

  if (requestedCategory) {
    activeCategory.value = requestedCategory
  }

  if (includeExpired) {
    showExpired.value = true
  }

  if (requestedTargetRef) {
    targetRefQuery.value = requestedTargetRef
  }

  await loadData()
  initializingFromRoute.value = false
})

// Auto-refresh every 60s
let refreshInterval
onMounted(() => {
  refreshInterval = setInterval(updateStats, 60000)
})

// Phase 5: keyboard shortcuts (J/K navigate, A approve, R reject, X close detail)
function onKeyDown(e) {
  // Ignore when typing in an input/textarea/select/contenteditable
  const t = e.target
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) {
    return
  }
  if (e.metaKey || e.ctrlKey || e.altKey) return

  const list = filteredItems.value
  if (list.length === 0) return
  const currentIdx = selectedItem.value
    ? list.findIndex(i => i.unified_id === selectedItem.value.unified_id)
    : -1

  if (e.key === 'j' || e.key === 'J') {
    e.preventDefault()
    const next = list[Math.min(currentIdx + 1, list.length - 1)]
    if (next) selectItem(next)
  } else if (e.key === 'k' || e.key === 'K') {
    e.preventDefault()
    const prev = list[Math.max(currentIdx - 1, 0)]
    if (prev) selectItem(prev)
  } else if (e.key === 'a' || e.key === 'A') {
    if (selectedItem.value) {
      e.preventDefault()
      handleApprove(selectedItem.value)
    }
  } else if (e.key === 'r' || e.key === 'R') {
    if (selectedItem.value) {
      e.preventDefault()
      handleReject(selectedItem.value)
    }
  } else if (e.key === 'x' || e.key === 'X') {
    if (detailUnifiedId.value) {
      e.preventDefault()
      detailUnifiedId.value = null
    }
  }
}
onMounted(() => window.addEventListener('keydown', onKeyDown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeyDown))

onBeforeUnmount(() => {
  if (scrollObserver) scrollObserver.disconnect()
  clearInterval(refreshInterval)
})
</script>

<style scoped>
/* Ops Console Header */
.ops-header {
  display: flex;
  align-items: stretch;
  background: var(--ops-black);
  min-height: 56px;
  margin-bottom: 1rem;
}

.ops-elbow {
  width: 100px;
  min-height: 56px;
  background: var(--ops-magenta);
  border-radius: 0 0 32px 0;
  flex-shrink: 0;
}

.ops-title-bar {
  flex: 1;
  display: flex;
  align-items: center;
  position: relative;
}

.ops-title-bar::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 28px;
  background: var(--ops-magenta);
}

.ops-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--ops-black);
  background: var(--ops-orange);
  padding: 0.25rem 1rem;
  border-radius: 0 0 14px 0;
  position: relative;
  z-index: 1;
}

.ops-bar-segments {
  display: flex;
  gap: 3px;
  height: 28px;
  flex: 1;
  padding-left: 8px;
}

.ops-segment {
  width: 40px;
  border-radius: 0 0 8px 8px;
}

.ops-cap {
  width: 28px;
  height: 28px;
  background: var(--ops-lilac);
  border-radius: 0 0 0 14px;
  flex-shrink: 0;
}

/* Stats Card */
.ops-stat-card {
  padding: 1rem;
  border-radius: 0 16px 16px 0;
  cursor: pointer;
  transition: all 0.15s ease;
}

.ops-stat-card:hover {
  filter: brightness(1.1);
  transform: translateX(4px);
}

.ops-mini-stat {
  padding: 0.75rem;
  border-radius: 8px;
  text-align: center;
  color: var(--ops-black);
}

/* Tabs */
.ops-tab {
  padding: 0.5rem 1rem;
  background: var(--ops-plum);
  color: var(--ops-peach);
  border-radius: 0 12px 12px 0;
  font-size: 0.875rem;
  font-weight: 500;
  text-transform: capitalize;
  transition: all 0.15s ease;
}

.ops-tab:hover {
  background: var(--ops-lilac);
  color: var(--ops-black);
}

.ops-tab.active {
  background: var(--ops-orange);
  color: var(--ops-black);
}

/* Detail Panel */
.ops-detail-panel {
  background: var(--ops-plum);
  border-radius: 0 24px 24px 0;
  overflow: hidden;
}

.ops-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  background: var(--ops-magenta);
  color: var(--ops-black);
}

.ops-panel-body {
  padding: 1rem;
}

.ops-confidence {
  background: var(--ops-sky);
  padding: 0.125rem 0.5rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}

/* Spinner */
.ops-spinner {
  width: 48px;
  height: 48px;
  border: 4px solid var(--ops-plum);
  border-top-color: var(--ops-orange);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.ops-spinner-sm {
  width: 24px;
  height: 24px;
  border-width: 3px;
}

/* Transitions */
.slide-enter-active,
.slide-leave-active {
  transition: all 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

/* Fade transition for modal */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Link to Person Modal */
.link-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.link-modal {
  background: var(--ops-black);
  border: 2px solid var(--ops-magenta);
  border-radius: 0 24px 24px 0;
  width: 90%;
  max-width: 480px;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.link-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  background: var(--ops-magenta);
  color: var(--ops-black);
}

.link-modal-body {
  padding: 1rem;
  overflow-y: auto;
}

.link-search-input {
  width: 100%;
  padding: 0.625rem 0.75rem;
  background: var(--ops-plum);
  border: 1px solid var(--ops-lilac);
  border-radius: 0 12px 12px 0;
  color: var(--ops-peach);
  font-size: 0.875rem;
  outline: none;
  margin-bottom: 0.75rem;
}

.link-search-input:focus {
  border-color: var(--ops-orange);
}

.link-search-input::placeholder {
  color: var(--ops-text-muted);
}

.link-results {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  max-height: 320px;
  overflow-y: auto;
}

.link-result-item {
  padding: 0.625rem 0.75rem;
  background: var(--ops-plum);
  border-radius: 0 8px 8px 0;
  cursor: pointer;
  transition: all 0.15s ease;
}

.link-result-item:hover {
  background: var(--ops-lilac);
  transform: translateX(4px);
}

.link-result-item:hover .text-ops-peach {
  color: var(--ops-black);
}
</style>

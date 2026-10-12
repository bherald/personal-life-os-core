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

    <div :class="props.embedded ? 'review-content-shell px-4 pb-4' : 'px-4 pb-6'">
      <div
        v-if="pictureReviewMode"
        class="picture-review-header mb-4 border-y border-ops-peach/30 bg-ops-peach/5 px-3 py-3 text-sm text-ops-text"
      >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
          <div class="min-w-0 sm:mr-auto">
            <div class="text-xs font-bold uppercase text-ops-peach">
              Picture Match Approval
            </div>
            Approve &amp; link confirms the suggested person. Reject changes no identity link.
          </div>
          <div class="picture-review-header-actions">
            <div class="picture-review-switch" role="tablist" aria-label="Picture review queue">
              <button
                type="button"
                role="tab"
                :aria-selected="pictureReviewMode === 'matches'"
                :class="{ active: pictureReviewMode === 'matches' }"
                @click="switchPictureReviewMode('matches')"
              >
                <span>Match approval</span>
                <span class="picture-review-count">{{ pictureReviewCount('matches') }}</span>
              </button>
            </div>
            <a
              :href="`/genealogy${pictureReviewTreeId ? `?tree_id=${pictureReviewTreeId}` : ''}`"
              class="ops-btn ops-btn-sky shrink-0 text-xs"
            >
              Back to Genealogy
            </a>
          </div>
        </div>
      </div>

      <section class="review-run-bar" aria-labelledby="review-run-title">
        <div class="review-run-copy">
          <div class="review-run-kicker">Operator review</div>
          <h2 id="review-run-title">{{ reviewRunTitle }}</h2>
          <div class="review-run-status">{{ reviewRunStatus }}</div>
        </div>
        <div class="review-run-counts" aria-label="Current review queue counts">
          <div>
            <strong>{{ filteredItems.length }}</strong>
            <span>loaded</span>
          </div>
          <div>
            <strong>{{ scopedPendingCount }}</strong>
            <span>{{ scopedPendingLabel }}</span>
          </div>
        </div>
        <button
          type="button"
          class="review-next-button"
          :disabled="loading"
          @click="openNextReview"
        >
          {{ nextReviewItem ? 'Review next' : 'No actionable items' }}
        </button>
      </section>

      <details
        class="review-tools"
        :open="reviewToolsOpen"
        @toggle="reviewToolsOpen = $event.currentTarget.open"
      >
        <summary>
          <span>Queue controls</span>
          <span>{{ reviewRunTitle }} · {{ scopedPendingCount }} {{ scopedPendingLabel }}</span>
        </summary>
        <div class="review-tools-body">
      <!-- Stats Row -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <div
          v-for="(stat, key) in reviewStats"
          :key="key"
          v-show="key !== '_total' && stat.inspection_only !== true && !EXCLUDED_CATEGORIES.includes(stat.category) && (stat.total || 0) > 0"
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
          @click="selectReviewCategory(null)"
          class="ops-tab"
          :class="{ active: activeCategory === null }"
        >
          All ({{ totalPending }})
        </button>
        <button
          v-for="(types, category) in typesByCategory"
          :key="category"
          @click="selectReviewCategory(category)"
          class="ops-tab"
          :class="{ active: activeCategory === category }"
        >
          {{ reviewCategoryLabel(category) }} ({{ getCategoryCount(category) }})
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

      <div v-if="visibleTypeFilters.length" class="type-filter-row">
        <button
          type="button"
          class="type-filter-chip"
          :class="{ active: !activeType }"
          @click="activeType = null"
        >
          All review types
        </button>
        <button
          v-for="entry in visibleTypeFilters"
          :key="entry.key"
          type="button"
          class="type-filter-chip"
          :class="{ active: activeType === entry.key }"
          @click="filterByType(entry.key)"
        >
          <span>{{ entry.label }}</span>
          <strong>{{ entry.total }}</strong>
        </button>
      </div>

      <div
        v-if="activeType === DEFAULT_OPERATOR_REVIEW_TYPE"
        class="mb-4 p-3 bg-ops-green/10 border border-ops-green/30 rounded text-sm text-ops-text"
      >
        <div class="font-bold text-ops-green uppercase tracking-wide text-xs mb-1">Operator-ready queue</div>
        <div>
          Showing evidence media capture approvals only. Advisory genealogy findings are hidden here because they need a
          materialized remediation preview before approval can apply data changes.
        </div>
      </div>
      <div
        v-else-if="activeType === 'genealogy_finding'"
        class="mb-4 p-3 bg-ops-orange/10 border border-ops-orange/30 rounded text-sm text-ops-text"
      >
        <div class="font-bold text-ops-orange uppercase tracking-wide text-xs mb-1">Mixed advisory queue</div>
        <div>
          Many research findings are not direct approvals. If a row says it needs a preview, reject it if wrong or leave
          it pending; do not treat it as the current media-capture approval work.
        </div>
      </div>

      <!-- Phase 5: queue ergonomics — sort + confidence threshold + daily quota nudge -->
      <div class="flex flex-wrap items-center gap-3 mb-4 p-2 bg-ops-plum/10 rounded">
        <label class="flex items-center gap-1.5">
          <span class="text-xs text-ops-text-muted uppercase">Sort</span>
          <select v-model="sortMode" class="bg-ops-black border border-ops-plum/40 text-ops-peach text-xs rounded px-2 py-0.5">
            <option value="review_priority">Review priority</option>
            <option value="confidence_desc">Confidence high → low</option>
            <option value="oldest_first">Oldest first</option>
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
        <label v-if="producerOptions.length" class="flex items-center gap-1.5 min-w-0">
          <span class="text-xs text-ops-text-muted uppercase">Producer</span>
          <select
            v-model="producerId"
            class="max-w-56 min-w-0 bg-ops-black border border-ops-plum/40 text-ops-peach text-xs rounded px-2 py-0.5"
            title="Filter pending review items by the agent or model that produced them"
          >
            <option value="">All producers</option>
            <option v-for="producer in producerOptions" :key="producer.id" :value="producer.id">
              {{ producer.id }} ({{ producer.count }})
            </option>
          </select>
        </label>
        <div class="ml-auto flex items-center gap-2 text-xs">
          <span class="text-ops-text-muted uppercase">Today</span>
          <span class="text-ops-peach font-bold">{{ dailyReviewedCount }}</span>
          <span class="text-ops-text-muted">/ {{ dailyQuotaTarget }}</span>
          <span v-if="dailyReviewedCount >= dailyQuotaTarget" class="text-green-400 font-bold">✓ goal met</span>
        </div>
      </div>

        </div>
      </details>

      <div
        v-if="agentStatsUnavailable || agentFeedbackUnavailable"
        role="status"
        class="ops-panel mb-4 px-3 py-2 text-sm"
        :style="{ color: 'var(--desk-amber)' }"
      >
        Agent summary unavailable.
      </div>

      <section
        v-if="activeCategory === 'genealogy' && !activeType && !pictureReviewMode"
        class="genealogy-decision-summary"
        aria-labelledby="genealogy-decision-summary-title"
      >
        <div class="genealogy-decision-summary-copy">
          <span class="genealogy-decision-kicker">FT4 people and facts</span>
          <strong id="genealogy-decision-summary-title">
            {{ genealogyDecisionCount === 0
              ? 'No person or fact decisions are waiting'
              : `${genealogyDecisionCount} person or fact ${genealogyDecisionCount === 1 ? 'decision' : 'decisions'} waiting` }}
          </strong>
          <span>
            PLOS sends an item here only when a proposed person, relationship, fact, source, or media change needs your judgment.
          </span>
        </div>
      </section>

      <!-- Main Content — Master/detail layout when a genealogy item is open
           (Phase 1 of Genealogy Review UI redesign). Falls back to full-width
           when no detail is open so the existing card list keeps its layout. -->
      <div :class="detailUnifiedId ? 'grid grid-cols-1 lg:grid-cols-12 gap-4' : ''">
        <div :class="detailUnifiedId ? 'hidden lg:block lg:col-span-5 min-w-0' : ''">
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
            <button
              v-if="hasMore"
              type="button"
              class="ops-btn ops-btn-sky text-xs mt-4"
              :disabled="loadingMore"
              @click="loadMore"
            >
              {{ loadingMore ? 'Loading...' : 'Load more action-ready rows' }}
            </button>
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
import { loadResearchHubData } from '@/utils/researchHubLoader'
import DynamicReviewCard from '@/components/review/DynamicReviewCard.vue'
import ReviewDetailPane from '@/components/review/ReviewDetailPane.vue'
import { nextReviewStreamItem, sortReviewQueueItems } from '@/utils/reviewQueue'
import { resolveSurfaceThemeStyle } from '@/utils/reviewSchemaStyles'

// Props for embedded mode (inside Knowledge Hub)
const props = defineProps({
  embedded: { type: Boolean, default: false }
})
const emit = defineEmits(['review-counts-updated'])
const route = useRoute()
const router = useRouter()

// State
const loading = ref(true)
const reviewTypes = ref({})
const typesByCategory = ref({})
const reviewStats = ref({})
const agentStats = ref({ active: 0, completed_24h: 0, failed_24h: 0, pending_reviews: 0 })
const agentStatsUnavailable = ref(false)
const agentFeedbackUnavailable = ref(false)
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
const itemsTotal = ref(0)
// Phase 1 (Genealogy Review UI redesign): which item is currently open
// in the side-by-side detail pane. Null = pane closed, list takes full width.
const detailUnifiedId = ref(null)
// Phase 5 (queue ergonomics): sort + threshold + per-day quota nudge.
const sortMode = ref('review_priority')
const confidenceThreshold = ref(0)        // 0..95 in 5% increments
const producerId = ref('')
const producerCounts = ref({})
const dailyQuotaTarget = ref(20)

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
const activeType = ref(null)
const selectedItem = ref(null)
const selectedItems = ref([])
const inFlightDecisions = ref(new Set())
const showAgentPanel = ref(false)
const reviewToolsOpen = ref(false)
const showExpired = ref(false)
const initializingFromRoute = ref(true)
const pictureReviewMode = ref(null)
const pictureReviewTreeId = ref(null)

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
const DEFAULT_OPERATOR_REVIEW_TYPE = 'genealogy_evidence_asset_capture'
const DETAIL_REVIEW_TYPES = new Set([
  'genealogy_finding',
  'genealogy_merge',
  'change_proposal',
  'genealogy_review_packet',
  'ai_model_update',
  'privacy',
  'scan_date_legacy_metadata',
  DEFAULT_OPERATOR_REVIEW_TYPE,
])
const PREVIEW_REQUIRED_GENEALOGY_CHANGE_TYPES = new Set([
  'data_quality_review',
  'source_duplicate_cleanup',
  'source_quality_review',
  'identity_review',
  'date_quality_review',
  'sex_value_review',
  'missing_name_review',
])
const ACK_ONLY_GENEALOGY_CHANGE_TYPES = new Set([
  'search_complete',
])

const normalizeRouteString = (value) => {
  const normalized = `${value ?? ''}`.trim()
  return normalized === '' ? null : normalized
}

const normalizePositiveRouteInt = (value) => {
  const parsed = Number.parseInt(`${value ?? ''}`, 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
}

const reviewItemTreeId = (item) => normalizePositiveRouteInt(
  item?.tree_id
    ?? item?.details?.tree_id
    ?? item?.details?.filters?.tree_id
    ?? item?.review_focus?.tree_id
    ?? item?.details?.metadata?.tree_id
)

const reviewItemFindingType = (item) => `${
  item?.finding_type
    ?? item?.details?.finding_type
    ?? item?.details?.posture?.finding_type
    ?? item?.review_focus?.finding_type
    ?? ''
}`.trim()

const isPhotoAuditHold = (item) => {
  const hold = item?.details?.packet?.metadata?.audit_hold
    ?? item?.details?.metadata?.audit_hold
    ?? item?.packet?.metadata?.audit_hold
  return hold && typeof hold === 'object' && !Array.isArray(hold) && Object.keys(hold).length > 0
}

const filterPictureReviewItems = (sourceItems) => {
  if (!pictureReviewMode.value) return sourceItems

  return sourceItems.filter((item) => {
    if (pictureReviewTreeId.value && reviewItemTreeId(item) !== pictureReviewTreeId.value) {
      return false
    }

    if (pictureReviewMode.value === 'matches') {
      return reviewItemType(item) === 'face'
    }

    return isReviewPacket(item) && (reviewItemFindingType(item) === 'face_person_source_repair_plan' || isPhotoAuditHold(item))
  })
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

const reviewItemType = (item) => item?.source || item?.review_type || null

const pictureReviewCount = () => Number(reviewStats.value?.face?.visible_total ?? reviewStats.value?.face?.total ?? 0)

const switchPictureReviewMode = (mode) => {
  if (mode !== 'matches') return

  pictureReviewMode.value = mode
  activeType.value = 'face'
  activeCategory.value = 'faces'
  selectedItem.value = null
  detailUnifiedId.value = null

  router.replace({
    query: {
      ...route.query,
      picture_review: mode,
      type: undefined,
      category: undefined,
      target_ref: undefined,
      unified_id: undefined,
    },
  }).catch(() => {})
}

const canOpenDetailForItem = (item) => {
  const type = reviewItemType(item)
  return Boolean(type && (DETAIL_REVIEW_TYPES.has(type) || reviewTypes.value[type]))
}

const reviewItemFromContext = (payload, unifiedId) => {
  const item = payload?.item || payload?.data?.item || null
  if (!item) return null

  const reviewType = item.review_type || item.source || `${unifiedId}`.split(':', 1)[0]
  const type = reviewTypes.value[reviewType] || {}
  const normalized = {
    ...item,
    unified_id: item.unified_id || unifiedId,
    source: item.source || reviewType,
    category: item.category || type.category || (reviewType?.startsWith('genealogy') ? 'genealogy' : null),
    ui_schema: item.ui_schema || type.ui_schema || {},
    actions: item.actions || type.actions || [],
    color: item.color || type.color || null,
    batch_enabled: item.batch_enabled ?? Boolean(type.batch_enabled),
    details: item.details || {},
  }

  if (payload?.target_ref && !normalized.target_ref) normalized.target_ref = payload.target_ref
  if (payload?.review_focus && !normalized.review_focus) normalized.review_focus = payload.review_focus
  if (payload?.packet_outcome && !normalized.packet_outcome) normalized.packet_outcome = payload.packet_outcome
  if (payload?.packet_status && !normalized.packet_status) normalized.packet_status = payload.packet_status
  if (payload?.source_locator && !normalized.source_locator) normalized.source_locator = payload.source_locator
  if (Array.isArray(payload?.claims) && normalized.claim_count === undefined) normalized.claim_count = payload.claims.length
  if (Array.isArray(payload?.source_locators) && normalized.source_count === undefined) normalized.source_count = payload.source_locators.length

  return normalized
}

const loadFocusedReviewItem = async (unifiedId) => {
  try {
    const contextPayload = await api.get(`/research-hub/items/${encodeURIComponent(unifiedId)}/context`)
    const contextItem = reviewItemFromContext(contextPayload, unifiedId)
    if (contextItem) return contextItem
  } catch (error) {
    if (error?.response?.status !== 404) {
      console.error('Failed to load focused review context:', error)
    }
  }

  try {
    const response = await api.get(`/reviews/${encodeURIComponent(unifiedId)}`)
    return response.item || response.data?.item || null
  } catch (error) {
    console.error('Failed to load focused review item:', error)
    return null
  }
}

const focusLinkedItem = async () => {
  const unifiedId = normalizeRouteString(route.query.unified_id)
  if (!unifiedId) {
    return
  }

  let target = items.value.find((item) => item.unified_id === unifiedId) || null

  if (!target) {
    target = await loadFocusedReviewItem(unifiedId)
    if (!target) {
      return
    }

    if (!isActionReadyInboxItem(target)) {
      return
    }

    const existingIndex = items.value.findIndex((item) => item.unified_id === target.unified_id)
    if (existingIndex === -1) {
      items.value.unshift(target)
    } else {
      items.value.splice(existingIndex, 1, target)
    }
  }

  if (!isActionReadyInboxItem(target)) {
    return
  }

  selectedItem.value = target
  if (canOpenDetailForItem(target)) {
    detailUnifiedId.value = target.unified_id
  }
  await scrollItemIntoView(unifiedId)
}

// Computed
const totalPending = computed(() => {
  return Object.entries(reviewStats.value)
    .filter(([key, stat]) => key !== '_total' && stat?.inspection_only !== true && !EXCLUDED_CATEGORIES.includes(stat.category))
    .reduce((sum, [, stat]) => sum + Number(stat.total || 0), 0)
})

// Review lists and Next contain only rows with a supported operator action.
const emptyStateMessage = computed(() => {
  const requestedId = normalizeRouteString(route.query.unified_id)
  if (requestedId) {
    return `No pending review item is available for ${requestedId} with the current filters.`
  }

  if (pictureReviewMode.value === 'matches') {
    return 'No picture matches are waiting for identity approval.'
  }

  if (activeCategory.value === 'genealogy') {
    return 'No pending genealogy review items.'
  }

  return 'No pending items'
})

const filteredItems = computed(() => {
  let result = items.value.filter(item => !EXCLUDED_CATEGORIES.includes(item.category))
  if (activeType.value) result = result.filter(item => item.source === activeType.value || item.review_type === activeType.value)
  if (activeCategory.value) result = result.filter(item => item.category === activeCategory.value)
  result = filterPictureReviewItems(result)
  result = result.filter(item => isActionReadyInboxItem(item))
  // Phase 5: confidence threshold filter (NULL confidence always passes — system alerts etc.)
  if (confidenceThreshold.value > 0) {
    const min = confidenceThreshold.value / 100
    result = result.filter(item => item.confidence === null || item.confidence === undefined || (item.confidence ?? 1) >= min)
  }
  return sortReviewQueueItems(result, sortMode.value)
})

const scopedPendingCount = computed(() => {
  if (pictureReviewMode.value) {
    return pictureReviewCount(pictureReviewMode.value)
  }
  if (activeType.value) {
    const stat = reviewStats.value?.[activeType.value]
    return stat?.inspection_only === true ? 0 : Number(stat?.visible_total ?? stat?.total ?? 0)
  }
  if (activeCategory.value) {
    return getCategoryCount(activeCategory.value)
  }

  return totalPending.value
})

const scopedPendingLabel = computed(() => 'pending')

const reviewRunTitle = computed(() => {
  if (pictureReviewMode.value === 'matches') return 'Picture match decisions'
  if (activeType.value) return reviewStats.value?.[activeType.value]?.label || 'Selected review queue'
  if (activeCategory.value) return reviewCategoryLabel(activeCategory.value)
  return 'All review work'
})

const nextReviewItem = computed(() => filteredItems.value[0] || null)

const reviewRunStatus = computed(() => {
  if (loading.value) return 'Loading current review work.'
  if (nextReviewItem.value) {
    const title = `${nextReviewItem.value.title || 'Review item'}`.trim().slice(0, 160)
    return detailUnifiedId.value === nextReviewItem.value.unified_id
      ? `Open: ${title}`
      : `Next: ${title}`
  }
  return 'No item is waiting in this scope.'
})

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
  return Object.entries(reviewStats.value)
    .filter(([, stat]) => stat.category === category && stat?.inspection_only !== true)
    .reduce((sum, [, stat]) => sum + Number(stat.total || 0), 0)
}

const genealogyDecisionCount = computed(() => getCategoryCount('genealogy'))
const REVIEW_CATEGORY_LABELS = {
  agent: 'AI changes',
  faces: 'Picture matches',
  files: 'Files',
  genealogy: 'Genealogy facts',
  privacy: 'Privacy',
  research: 'Research',
}

const reviewCategoryLabel = (category) => REVIEW_CATEGORY_LABELS[category]
  || `${category || 'Other'}`.replaceAll('_', ' ')

const visibleTypeFilters = computed(() => {
  return Object.entries(reviewStats.value)
    .filter(([key, stat]) => key !== '_total' && stat?.inspection_only !== true && !EXCLUDED_CATEGORIES.includes(stat.category) && (stat.total || 0) > 0)
    .map(([key, stat]) => ({
      key,
      label: stat.label || key.replace(/_/g, ' '),
      total: stat.total || 0,
      category: stat.category || null,
    }))
})

const producerOptions = computed(() => Object.entries({
  ...(producerId.value && !producerCounts.value?.[producerId.value] ? { [producerId.value]: 0 } : {}),
  ...(producerCounts.value || {}),
})
  .map(([id, count]) => ({ id, count: Number(count || 0) }))
  .filter((producer) => producer.id && (producer.count > 0 || producer.id === producerId.value))
  .sort((left, right) => left.id.localeCompare(right.id, undefined, { sensitivity: 'base' })))

const resetFocusedReview = () => {
  selectedItem.value = null
  selectedItems.value = []
  detailUnifiedId.value = null
}

const clearReviewRouteFocus = (query = {}) => {
  router.replace({
    query: {
      ...route.query,
      picture_review: undefined,
      unified_id: undefined,
      target_ref: undefined,
      ...query,
    },
  }).catch(() => {})
}

const selectReviewCategory = (category) => {
  pictureReviewMode.value = null
  pictureReviewTreeId.value = null
  activeCategory.value = category
  activeType.value = null
  resetFocusedReview()
  clearReviewRouteFocus({
    category: category || undefined,
    type: undefined,
  })
}

const filterByType = (typeName) => {
  const type = reviewTypes.value[typeName]
  pictureReviewMode.value = null
  pictureReviewTreeId.value = null
  activeType.value = typeName
  activeCategory.value = type?.category || null
  resetFocusedReview()
  clearReviewRouteFocus({
    category: undefined,
    type: typeName,
  })
}

const getStatCardStyle = (stat) => resolveSurfaceThemeStyle(stat?.color, 'ops-plum')

function emitReviewCounts() {
  const actionableStats = Object.values(reviewStats.value || {})
    .filter(stat => stat && typeof stat === 'object' && stat.inspection_only !== true)

  emit('review-counts-updated', {
    total: actionableStats.reduce((sum, stat) => sum + Number(stat.total || 0), 0),
    categories: actionableStats
      .reduce((counts, stat) => {
        const category = stat.category || 'uncategorized'
        counts[category] = (counts[category] || 0) + Number(stat.total || 0)
        return counts
      }, {}),
  })
}

const buildItemParams = (offset = 0) => {
  const params = { limit: PAGE_SIZE, offset }
  if (activeType.value) {
    params.type = activeType.value
  } else if (activeCategory.value) {
    params.category = activeCategory.value
  }
  if (showExpired.value) params.include_expired = true
  if (producerId.value) params.agent_id = producerId.value

  return params
}

let loadGeneration = 0

const loadData = async () => {
  const generation = ++loadGeneration
  loading.value = true
  loadingMore.value = false
  agentStatsUnavailable.value = false
  agentFeedbackUnavailable.value = false
  try {
    const itemParams = buildItemParams(0)
    // Queue data owns the loading state. Slow optional summaries update on
    // their own so they cannot keep an otherwise usable review queue blank.
    await loadResearchHubData(api, itemParams, {
      onStats: (statsResp) => {
        reviewStats.value = statsResp.visible_reviews || statsResp.reviews || {}
        agentStats.value = statsResp.agents || agentStats.value
        agentStatsUnavailable.value = false
        emitReviewCounts()
      },
      onStatsError: (error) => {
        agentStatsUnavailable.value = true
        console.warn('Failed to load research hub stats:', error)
      },
      onAgentStatus: (agentStatusResp) => {
        reviewerFeedback.value = Array.isArray(agentStatusResp.reviewer_feedback)
          ? agentStatusResp.reviewer_feedback
          : []
        agentFeedbackUnavailable.value = false
      },
      onAgentStatusError: (error) => {
        agentFeedbackUnavailable.value = true
        console.warn('Failed to load research hub agent status:', error)
      },
      onItems: (response) => {
        items.value = response.items || []
        itemsTotal.value = Number(response.total || 0)
        producerCounts.value = response.producer_counts || {}
        hasMore.value = response.has_more || false
        reviewTypes.value = response.types || {}
      },
    }, () => generation === loadGeneration)

    if (generation !== loadGeneration) return

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
    if (generation !== loadGeneration) return
    setupScrollObserver()
    await focusLinkedItem()
    if (generation !== loadGeneration) return
    await maybeOpenFirstScopedReview()
  } catch (e) {
    if (generation === loadGeneration) {
      console.error('Failed to load research hub data:', e)
    }
  } finally {
    if (generation === loadGeneration) loading.value = false
  }
}

const loadMore = async () => {
  if (loadingMore.value || !hasMore.value) return
  const generation = loadGeneration
  loadingMore.value = true
  try {
    const params = buildItemParams(items.value.length)
    const resp = await api.get('/research-hub/items', { params })
    if (generation !== loadGeneration) return
    const newItems = resp.items || []
    if (newItems.length > 0) {
      items.value.push(...newItems)
    }
    hasMore.value = resp.has_more || false
    itemsTotal.value = Number(resp.total || itemsTotal.value)
    // Re-observe after new items render
    await nextTick()
    if (generation !== loadGeneration) return
    setupScrollObserver()
  } catch (e) {
    if (generation === loadGeneration) console.error('Failed to load more items:', e)
  } finally {
    if (generation === loadGeneration) loadingMore.value = false
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
  if (canOpenDetailForItem(item)) {
    detailUnifiedId.value = item.unified_id
  }
}

const maybeOpenFirstScopedReview = async () => {
  const first = filteredItems.value[0]
  const shouldOpenPictureMatch = pictureReviewMode.value === 'matches'
  const shouldOpenPurposeBuiltReview = Boolean(activeType.value && first && requiresDetailDecision(first))
  if ((!shouldOpenPictureMatch && !shouldOpenPurposeBuiltReview) || detailUnifiedId.value) {
    return
  }

  if (!first) return

  selectItem(first)
  await nextTick()
}

const openNextReview = async () => {
  if (!nextReviewItem.value) {
    reviewToolsOpen.value = true
    return
  }

  selectItem(nextReviewItem.value)
  await nextTick()
  document.querySelector('.detail-pane')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const closeDetailPane = () => {
  detailUnifiedId.value = null
}

const finishTerminalDetailDecision = async (unifiedId) => {
  const isQueueStream = detailUnifiedId.value === unifiedId
    && !normalizeRouteString(route.query.unified_id)
  let nextItem = isQueueStream
    ? nextReviewStreamItem(filteredItems.value, unifiedId)
    : null

  items.value = items.value.filter(item => item.unified_id !== unifiedId)

  if (!nextItem && isQueueStream && hasMore.value) {
    await loadMore()
    nextItem = filteredItems.value[0] || null
  }

  if (nextItem && items.value.some(item => item.unified_id === nextItem.unified_id)) {
    selectItem(nextItem)
    await scrollItemIntoView(nextItem.unified_id)
    return
  }

  if (selectedItem.value?.unified_id === unifiedId) selectedItem.value = null
  detailUnifiedId.value = null
}

const onDetailApprove = async (payload) => {
  const unifiedId = decisionPayloadUnifiedId(payload)
  if (!unifiedId) return

  await finishTerminalDetailDecision(unifiedId)
  bumpDailyReviewed()
  showToast(decisionSuccessMessage(payload?.result, null, 'Item approved'))
  updateStats()
}

const onDetailReject = async (payload) => {
  const unifiedId = decisionPayloadUnifiedId(payload)
  if (!unifiedId) return

  await finishTerminalDetailDecision(unifiedId)
  bumpDailyReviewed()
  showToast(decisionSuccessMessage(payload?.result, null, 'Item rejected'))
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
const onDetailApplied = async ({ unifiedId, result }) => {
  // Remove from the list only when the row went to approved server-side.
  // Stays-pending (undecided > 0 or failed > 0) keeps the row visible so
  // the operator can return to it. Toast is built from the structured
  // result fields so the operator sees what actually happened
  // (F1+F2+F3 introduced applied/kept_on_file/undecided/failed counters).
  if (result?.final_status === 'approved') {
    await finishTerminalDetailDecision(unifiedId)
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
  if (requiresDetailDecision(item)) {
    selectItem(item)
    showToast(detailDecisionPrompt(item, 'approve'), 'info')
    return
  }

  const blockedReason = approvalBlockReason(item)
  if (blockedReason) {
    showToast(blockedReason, 'error')
    return
  }

  const unifiedId = beginDecision(item)
  if (!unifiedId) return

  try {
    const resp = await api.post(`/research-hub/approve/${unifiedId}`, decisionRequestBody(item, 'approve'))
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
    showToast(decisionSuccessMessage(resp, item, 'Item approved'))
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

function isAiModelUpdate(item) {
  return item?.review_type === 'ai_model_update' || item?.source === 'ai_model_update'
}

function isPrivacyReview(item) {
  return item?.review_type === 'privacy' || item?.source === 'privacy'
}

function isScanDateReview(item) {
  return item?.review_type === 'scan_date_estimate' || item?.source === 'scan_date_estimate'
}

function isLegacyScanMetadata(item) {
  return item?.review_type === 'scan_date_legacy_metadata' || item?.source === 'scan_date_legacy_metadata'
}

function isSkillAuditReview(item) {
  return reviewItemType(item) === 'skill_optimization'
    && (reviewItemFindingType(item) === 'skill_audit'
      || item?.details?.schema === 'agent_skill_audit_review_queue.v2')
}

function requiresDetailDecision(item) {
  return item?.ui_schema?.inspection_only === true || isReviewPacket(item) || isAiModelUpdate(item) || isPrivacyReview(item) || isScanDateReview(item) || isLegacyScanMetadata(item) || isSkillAuditReview(item)
}

function detailDecisionPrompt(item, action) {
  if (item?.ui_schema?.inspection_only === true) return 'Inspect the source evidence; no change action is available'
  if (isAiModelUpdate(item)) {
    return action === 'reject'
      ? 'Open the portfolio detail to review all actions and enter a rejection reason'
      : 'Open the portfolio detail to review sources, risks, validation, and the bounded approval scope'
  }

  if (isPrivacyReview(item)) {
    return action === 'reject'
      ? 'Open the privacy request to choose a rejection reason with the broker target visible'
      : 'Open the privacy request to review the broker target and authorize specific field categories'
  }

  if (isScanDateReview(item)) {
    return action === 'reject'
      ? 'Open the scan-date review to inspect the photo and choose a rejection reason'
      : 'Open the scan-date review to inspect the photo, evidence, and catalog-only approval boundary'
  }

  if (isLegacyScanMetadata(item)) {
    return 'Open the live metadata inspection; this item has no approve, reject, or correction action'
  }

  if (isSkillAuditReview(item)) {
    return action === 'reject'
      ? 'Open the audit detail to review the findings and confirm the acknowledgement-only rejection effect'
      : 'Open the audit detail to review advisories and confirm that approval applies no skill changes'
  }

  return action === 'reject'
    ? 'Open packet detail to reject or request clarification with packet context visible'
    : 'Open packet detail to review source, claim, and preview context before marking reviewed'
}

function decisionSuccessMessage(result, item, fallback) {
  const message = typeof result?.message === 'string' ? result.message.trim() : ''
  if (message) return message
  if (item && isAckOnlyGenealogyFinding(item)) return 'Acknowledged; no accepted FT facts were changed.'
  if (item && isReviewPacket(item)) return 'Packet marked reviewed; no accepted FT facts were changed.'
  if (item && isAiModelUpdate(item)) return 'Portfolio review approved for bounded validation only; no model or route changed.'
  if (item && isPrivacyReview(item)) return 'Privacy request authorized for later submission; no external action occurred.'
  if (item && isScanDateReview(item)) return 'Scan date reviewed; no image metadata was written.'
  if (item && isSkillAuditReview(item)) return 'Skill audit acknowledged; no skill or sidecar settings changed.'
  return fallback
}

function isBatchSelectable(item) {
  return item?.batch_enabled === true && !requiresDetailDecision(item) && !isApproveBlocked(item)
}

function approvalLabel(item) {
  if (!isEvidenceCaptureReview(item) && partialNameOnlyBlockReason(item)) {
    return 'Needs identity'
  }

  if (isEvidenceCaptureReview(item)) {
    return 'Approve capture row'
  }

  if (reviewItemType(item) === 'face') {
    return 'Approve & link'
  }

  if (genealogyFindingApprovalBlockReason(item)) {
    return 'Needs preview'
  }

  if (isAckOnlyGenealogyFinding(item)) {
    return 'Acknowledge'
  }

  if (isAiModelUpdate(item)) return 'Open portfolio review'
  if (isPrivacyReview(item)) return 'Review request'
  if (isScanDateReview(item)) return 'Review photo date'
  if (isSkillAuditReview(item)) return 'Review audit'

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
  const compactCount = Number.parseInt(`${item?.validation_error_count ?? ''}`, 10)
  if (Number.isFinite(compactCount) && compactCount >= 0) return compactCount
  const errors = item?.details?.validation?.errors
  return Array.isArray(errors) ? errors.length : 0
}

function isApproveBlocked(item) {
  return approvalBlockReason(item) !== null
}

function approveBlockedTitle(item) {
  return approvalBlockReason(item) || ''
}

function approvalBlockReason(item) {
  return partialNameOnlyBlockReason(item)
    || packetApprovalBlockReason(item)
    || genealogyFindingApprovalBlockReason(item)
    || null
}

function genealogyFindingChangeTypes(item) {
  const proposals = Array.isArray(item?.details?.proposals) ? item.details.proposals : []
  return proposals
    .map(proposal => `${proposal?.change_type || ''}`.trim())
    .filter(Boolean)
}

function isAckOnlyGenealogyFinding(item) {
  if (item?.review_type !== 'genealogy_finding' && item?.source !== 'genealogy_finding') {
    return false
  }

  const changeTypes = genealogyFindingChangeTypes(item)
  return changeTypes.length > 0 && changeTypes.every(type => ACK_ONLY_GENEALOGY_CHANGE_TYPES.has(type))
}

function genealogyFindingApprovalBlockReason(item) {
  if (item?.review_type !== 'genealogy_finding' && item?.source !== 'genealogy_finding') {
    return null
  }

  const changeTypes = genealogyFindingChangeTypes(item)

  if (!changeTypes.some(type => PREVIEW_REQUIRED_GENEALOGY_CHANGE_TYPES.has(type))) {
    return null
  }

  return 'Advisory genealogy finding: materialize a typed remediation preview before approval can apply data changes.'
}

function packetApprovalBlockReason(item) {
  if (!isReviewPacket(item)) return null

  const count = packetValidationErrorCount(item)
  if (count > 0) {
    return `Resolve ${count} validation ${count === 1 ? 'error' : 'errors'} before approving preview`
  }

  const focus = item?.review_focus
  if (focus && focus.operator_vetable === false) {
    return focus.operator_blocker_label || 'No viewable source image, document, or external source link is attached.'
  }

  if (focus && focus.approval_ready === false) {
    return 'Open the packet detail and resolve preview-only readiness before marking reviewed'
  }

  return null
}

function packetOperatorVetable(item) {
  const focus = item?.review_focus || {}
  if (focus.operator_vetable === false) return false
  if (focus.operator_vetable === true) return true
  const visibleEvidenceCount = Number(focus.visible_evidence_count ?? 0)
  return Number.isFinite(visibleEvidenceCount) && visibleEvidenceCount > 0
}

function isEvidenceCaptureReview(item) {
  return item?.review_type === DEFAULT_OPERATOR_REVIEW_TYPE || item?.source === DEFAULT_OPERATOR_REVIEW_TYPE
}

function isActionReadyInboxItem(item) {
  if (item?.ui_schema?.inspection_only === true) return false

  const actions = Array.isArray(item?.actions) ? item.actions : []
  if (!actions.some(action => action === 'approve' || action === 'reject')) return false

  const reviewType = item?.review_type || item?.source || ''

  if (isReviewPacket(item)) {
    const focus = item?.review_focus || {}
    if (focus.operator_review_surface !== 'review') return false
    if (focus.preview_only === true && focus.canonical_mutation === false) return false
  }

  if (isEvidenceCaptureReview(item)) {
    return approvalBlockReason(item) === null
  }

  if (isReviewPacket(item)) {
    return approvalBlockReason(item) === null
      && item?.review_focus?.approval_ready !== false
      && item?.review_focus?.preview_only === true
      && packetOperatorVetable(item)
  }

  if (reviewType === 'face') {
    const imageAvailable = Number(item?.image_available ?? 1)
    return Boolean(item?.image_url)
      && imageAvailable !== 0
      && approvalBlockReason(item) === null
  }

  if (reviewType === 'genealogy_finding') {
    return item?.review_focus?.approval_ready === true && approvalBlockReason(item) === null
  }

  if (item?.remediation?.preview_only === true || item?.remediation?.apply_held === true) {
    return false
  }

  return approvalBlockReason(item) === null
}

function partialNameOnlyBlockReason(item) {
  if (!item || typeof item !== 'object') {
    return null
  }

  if (isEvidenceCaptureReview(item)) {
    return null
  }

  const explicit = item?.review_focus?.identity_fit || item?.details?.identity_fit || item?.details?.match_quality
  if (explicit && typeof explicit === 'object' && !Array.isArray(explicit)) {
    const exactName = explicit.exact_name_match ?? explicit.full_name_match ?? explicit.exact_full_name_match
    const partialOnly = explicit.partial_name_only ?? explicit.partial_only ?? explicit.given_name_only
    const supportCount = Number(explicit.supporting_signal_count ?? explicit.support_count ?? 0)
    if ((partialOnly === true || exactName === false) && supportCount <= 0) {
      return 'Partial-name-only match needs date, place, family, or source support before approval'
    }
  }

  const scan = JSON.stringify({
    review_focus: item.review_focus || null,
    capture: item.details?.capture || item.details?.capture_plan || item.details?.plan || null,
    plans: item.details?.plans || null,
    match_quality: item.details?.match_quality || null,
    blockers: item.details?.approval_blockers || item.review_focus?.approval_blockers || null,
  }).toLowerCase()

  if (
    scan.includes('partial-name-only')
    || scan.includes('partial name only')
    || scan.includes('given name only')
    || scan.includes('full_name_match\":false')
    || scan.includes('exact_name_match\":false')
  ) {
    return 'Partial-name-only match needs date, place, family, or source support before approval'
  }

  return null
}

const handleReject = async (item) => {
  if (requiresDetailDecision(item)) {
    selectItem(item)
    showToast(detailDecisionPrompt(item, 'reject'), 'info')
    return
  }

  const unifiedId = beginDecision(item)
  if (!unifiedId) return

  try {
    const resp = await api.post(`/research-hub/reject/${unifiedId}`, decisionRequestBody(item, 'reject'))
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

function decisionRequestBody(item, action) {
  const body = {}
  const lineDecisions = Array.isArray(item?._line_decisions) ? item._line_decisions : []

  if (lineDecisions.length > 0) {
    body.decision_scope = 'line_items'
    body.line_decisions = lineDecisions
  }

  if (action === 'reject' && lineDecisions.length > 0) {
    body.reason_code = 'line_item_rejections'
  }

  return body
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
  const generation = loadGeneration
  try {
    const resp = await api.get('/research-hub/stats')
    if (generation !== loadGeneration) return
    reviewStats.value = resp.visible_reviews || resp.reviews || {}
    agentStats.value = resp.agents || agentStats.value
    emitReviewCounts()
  } catch (e) {
    if (generation === loadGeneration) console.error('Stats update failed:', e)
  }
}

const formatContextValue = (value) => {
  if (value === null || value === undefined || value === '') return '-'
  if (Array.isArray(value)) {
    return value.length ? `Structured context (${value.length} items)` : 'No structured context'
  }
  if (typeof value === 'object') {
    const keys = Object.keys(value).filter((key) => !isSensitiveContextKey(key))
    return keys.length ? `Structured context (${keys.length} fields)` : 'Structured context recorded'
  }
  return redactContextText(String(value))
}

const isSensitiveContextKey = (key) => {
  return /(?:token|secret|password|authorization|credential|locator|path|url|uri|payload|details|raw)/i.test(String(key || ''))
}

const redactContextText = (value) => {
  return String(value || '')
    .replace(/\bBearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [redacted]')
    .replace(/\b(?:api[_-]?(?:key|token)|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|token|secret|password|authorization)\s*[:=]\s*[^\s,;\]}]+/gi, '[redacted secret]')
    .replace(/([A-Za-z][A-Za-z0-9+.-]*:\/\/)([^:@/\s]+):([^@/\s]+)@/gi, '$1[redacted]@')
    .replace(/\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=-]{8,}\b/gi, '[redacted token]')
    .replace(/\/(?:home|Users|root)\/[^\s,"')\]}]+/g, '[redacted path]')
    .replace(/[A-Za-z]:\\[^\s,"')\]}]+/g, '[redacted path]')
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

// Server-backed queue facets must reload from offset 0 so pagination remains exact.
watch([activeCategory, activeType, producerId], async () => {
  if (initializingFromRoute.value) {
    return
  }

  const generation = ++loadGeneration
  items.value = []
  itemsTotal.value = 0
  hasMore.value = false
  loadingMore.value = false
  selectedItem.value = null
  selectedItems.value = []
  detailUnifiedId.value = null
  loading.value = true
  try {
    const params = buildItemParams(0)
    const resp = await api.get('/research-hub/items', { params })
    if (generation !== loadGeneration) return
    items.value = resp.items || []
    itemsTotal.value = Number(resp.total || 0)
    producerCounts.value = resp.producer_counts || {}
    hasMore.value = resp.has_more || false
    reviewTypes.value = resp.types || reviewTypes.value
    await nextTick()
    if (generation !== loadGeneration) return
    setupScrollObserver()
    await focusLinkedItem()
    if (generation !== loadGeneration) return
    await maybeOpenFirstScopedReview()
  } catch (e) {
    if (generation === loadGeneration) console.error('Failed to reload items:', e)
  } finally {
    if (generation === loadGeneration) loading.value = false
  }
})

watch(
  () => route.query.unified_id,
  async () => {
    await focusLinkedItem()
  }
)

// Lifecycle
onMounted(async () => {
  const requestedCategory = normalizeRouteString(route.query.category)
  const requestedType = normalizeRouteString(route.query.type)
  const requestedPictureReview = normalizeRouteString(route.query.picture_review)
  const includeExpired = ['1', 'true', 'yes'].includes(`${route.query.include_expired ?? ''}`.toLowerCase())
  if (requestedPictureReview === 'matches') {
    pictureReviewMode.value = 'matches'
    pictureReviewTreeId.value = normalizePositiveRouteInt(route.query.tree_id)
    activeType.value = 'face'
    activeCategory.value = 'faces'
  } else if (requestedType) {
    activeType.value = requestedType
  } else if (requestedCategory) {
    activeCategory.value = requestedCategory
  }

  if (includeExpired) {
    showExpired.value = true
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
.review-run-bar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 1rem;
  margin-bottom: .75rem;
  border: 1px solid var(--color-border);
  border-left: 4px solid var(--color-accent);
  border-radius: .25rem;
  background: color-mix(in srgb, var(--color-accent) 8%, var(--color-bg-primary));
  padding: .85rem 1rem;
  color: var(--color-text-primary);
}

.review-run-copy {
  min-width: 0;
}

.review-run-kicker {
  color: var(--color-accent);
  font-size: .65rem;
  font-weight: 800;
  text-transform: uppercase;
}

.review-run-copy h2 {
  margin: .1rem 0;
  color: var(--color-text-primary);
  font-size: 1rem;
  font-weight: 800;
  line-height: 1.3;
  overflow-wrap: anywhere;
}

.review-run-status {
  color: var(--color-text-secondary);
  font-size: .75rem;
  line-height: 1.4;
}

.review-run-counts {
  display: flex;
  align-items: stretch;
  gap: .4rem;
}

.review-run-counts > div {
  display: grid;
  min-width: 3.75rem;
  justify-items: center;
  border-left: 1px solid var(--color-border);
  padding: .1rem .55rem;
}

.review-run-counts strong {
  color: var(--color-text-primary);
  font-size: 1.05rem;
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
}

.review-run-counts span {
  color: var(--color-text-secondary);
  font-size: .6rem;
  text-transform: uppercase;
}

.review-next-button {
  min-height: 2.5rem;
  border: 1px solid var(--color-accent);
  border-radius: .25rem;
  background: var(--color-accent);
  color: white;
  padding: .55rem .9rem;
  font-size: .78rem;
  font-weight: 800;
  white-space: nowrap;
}

.review-next-button:disabled {
  cursor: wait;
  opacity: .45;
}

.review-tools {
  margin-bottom: .85rem;
  border-block: 1px solid var(--color-border);
  color: var(--color-text-primary);
}

.review-tools > summary {
  display: flex;
  min-height: 2.5rem;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  cursor: pointer;
  padding: .55rem .25rem;
  color: var(--color-accent);
  font-size: .72rem;
  font-weight: 800;
  text-transform: uppercase;
}

.review-tools > summary span:last-child {
  min-width: 0;
  color: var(--color-text-secondary);
  font-size: .65rem;
  font-weight: 600;
  overflow-wrap: anywhere;
  text-align: right;
}

.review-tools-body {
  padding-top: .75rem;
}

@media (max-width: 720px) {
  .review-run-bar {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .review-run-counts {
    grid-column: 1 / -1;
    grid-row: 2;
    justify-content: flex-start;
    order: 3;
  }

  .review-next-button {
    align-self: stretch;
  }
}

@media (max-width: 430px) {
  .review-run-bar {
    grid-template-columns: 1fr;
  }

  .review-run-counts,
  .review-next-button {
    grid-column: 1;
  }

  .review-next-button {
    width: 100%;
  }

  .review-tools > summary {
    align-items: flex-start;
    flex-direction: column;
    gap: .15rem;
  }

  .review-tools > summary span:last-child {
    text-align: left;
  }
}

.picture-review-header-actions {
  display: flex;
  flex: 0 0 auto;
  flex-wrap: wrap;
  align-items: center;
  gap: .65rem;
}

.picture-review-switch {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  min-width: min(100%, 22rem);
  border: 1px solid var(--color-border);
  border-radius: .25rem;
  overflow: hidden;
}

.picture-review-switch button {
  display: flex;
  min-height: 2.25rem;
  align-items: center;
  justify-content: center;
  gap: .5rem;
  border: 0;
  background: var(--color-bg-tertiary);
  color: var(--color-text-secondary);
  padding: .4rem .65rem;
  font-size: .75rem;
  font-weight: 700;
  cursor: pointer;
}

.picture-review-switch button + button {
  border-left: 1px solid var(--color-border);
}

.picture-review-switch button.active {
  background: var(--color-accent);
  color: white;
}

.picture-review-count {
  display: inline-flex;
  min-width: 1.5rem;
  min-height: 1.5rem;
  align-items: center;
  justify-content: center;
  border: 1px solid currentColor;
  border-radius: 50%;
  padding: 0 .3rem;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 639px) {
  .picture-review-header-actions,
  .picture-review-switch {
    width: 100%;
  }

  .picture-review-header-actions > .ops-btn {
    width: 100%;
    justify-content: center;
  }
}

/* Ops Console Header */
.ops-header {
  display: flex;
  align-items: stretch;
  background: var(--color-bg-secondary);
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
  color: var(--color-text-primary);
  background: var(--ops-warning);
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
  background: var(--color-accent);
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
  color: var(--color-text-primary);
}

/* Tabs */
.ops-tab {
  padding: 0.5rem 1rem;
  background: var(--color-accent);
  color: white;
  border-radius: 0 12px 12px 0;
  font-size: 0.875rem;
  font-weight: 500;
  text-transform: capitalize;
  transition: all 0.15s ease;
}

.ops-tab:hover {
  background: var(--color-accent);
  color: white;
}

.ops-tab.active {
  background: var(--ops-warning);
  color: white;
}

.type-filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  align-items: center;
  margin-bottom: 1rem;
  padding: 0.55rem;
  border: 1px solid var(--ops-warning);
  border-radius: 0 12px 12px 0;
  background: var(--color-bg-hover);
}

.type-filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.6rem;
  border: 1px solid var(--ops-warning);
  border-radius: 0 9px 9px 0;
  background: color-mix(in srgb, var(--color-accent) 10%, var(--color-bg-primary));
  color: var(--color-text-primary);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  transition: filter 0.15s, transform 0.15s, background 0.15s;
}

.type-filter-chip strong {
  color: var(--ops-warning);
  font-weight: 900;
}

.type-filter-chip:hover {
  filter: brightness(1.12);
  transform: translateX(2px);
}

.type-filter-chip.active {
  background: var(--ops-warning);
  color: white;
  border-color: var(--ops-warning);
}

.type-filter-chip.active strong {
  color: var(--color-text-primary);
}

@media (max-width: 640px) {
  .review-content-shell {
    padding-left: 0;
    padding-right: 0;
  }
}

/* Detail Panel */
.ops-detail-panel {
  background: var(--color-accent);
  border-radius: 0 24px 24px 0;
  overflow: hidden;
}

.ops-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  background: var(--ops-magenta);
  color: var(--color-text-primary);
}

.ops-panel-body {
  padding: 1rem;
}

.ops-confidence {
  background: var(--color-accent);
  padding: 0.125rem 0.5rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}

/* Spinner */
.ops-spinner {
  width: 48px;
  height: 48px;
  border: 4px solid var(--color-accent);
  border-top-color: var(--ops-warning);
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

.genealogy-decision-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
  border: 1px solid var(--color-border);
  border-radius: 0.4rem;
  background: var(--color-bg-tertiary);
  padding: 0.85rem 1rem;
}

.genealogy-decision-summary-copy {
  display: grid;
  gap: 0.2rem;
  min-width: 0;
  color: var(--color-text-primary);
  font-size: 0.82rem;
  line-height: 1.4;
}

.genealogy-decision-summary-copy strong {
  color: var(--color-text-primary);
  font-size: 1rem;
}

.genealogy-decision-kicker {
  color: var(--color-accent);
  font-size: 0.68rem;
  font-weight: 800;
  text-transform: uppercase;
}

.genealogy-diagnostic-link {
  display: grid;
  grid-template-columns: auto auto;
  align-items: baseline;
  gap: 0.15rem 0.45rem;
  flex: 0 0 auto;
  min-width: 10rem;
  border: 1px solid var(--ops-warning);
  border-radius: 0.35rem;
  background: color-mix(in srgb, var(--ops-warning) 12%, var(--color-bg-primary));
  padding: 0.55rem 0.7rem;
  color: var(--color-text-primary);
  text-decoration: none;
}

.genealogy-diagnostic-link strong {
  color: var(--ops-warning);
  font-size: 1.1rem;
}

.genealogy-diagnostic-link span {
  font-size: 0.75rem;
  font-weight: 800;
  text-transform: uppercase;
}

.genealogy-diagnostic-link small {
  grid-column: 1 / -1;
  color: var(--color-text-secondary);
  font-size: 0.67rem;
}

@media (max-width: 720px) {
  .genealogy-decision-summary {
    align-items: stretch;
    flex-direction: column;
  }

  .genealogy-diagnostic-link {
    width: 100%;
  }
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
  background: var(--color-bg-tertiary);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.link-modal {
  background: var(--color-bg-secondary);
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
  color: var(--color-text-primary);
}

.link-modal-body {
  padding: 1rem;
  overflow-y: auto;
}

.link-search-input {
  width: 100%;
  padding: 0.625rem 0.75rem;
  background: var(--color-accent);
  border: 1px solid var(--color-accent);
  border-radius: 0 12px 12px 0;
  color: white;
  font-size: 0.875rem;
  outline: none;
  margin-bottom: 0.75rem;
}

.link-search-input:focus {
  border-color: var(--ops-warning);
}

.link-search-input::placeholder {
  color: var(--color-text-secondary);
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
  background: var(--color-accent);
  border-radius: 0 8px 8px 0;
  cursor: pointer;
  transition: all 0.15s ease;
}

.link-result-item:hover {
  background: var(--color-accent);
  transform: translateX(4px);
}

.link-result-item:hover .text-ops-peach {
  color: var(--color-text-primary);
}
</style>

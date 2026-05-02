<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Browse Queue</h3>
      <div class="flex items-center gap-2">
        <button @click="loadQueue" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400">
          Refresh
        </button>
        <button @click="showAddForm = !showAddForm" class="px-3 py-1 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">
          + Add URLs
        </button>
      </div>
    </div>

    <!-- Stats -->
    <div v-if="stats" class="grid grid-cols-4 gap-2 mb-4">
      <div class="text-center p-2 bg-gray-50 dark:bg-gray-700 rounded">
        <div class="text-lg font-bold text-gray-900 dark:text-white">{{ stats.total }}</div>
        <div class="text-xs text-gray-500">Total</div>
      </div>
      <div class="text-center p-2 bg-yellow-50 dark:bg-yellow-900/20 rounded">
        <div class="text-lg font-bold text-yellow-600">{{ stats.pending }}</div>
        <div class="text-xs text-gray-500">Pending</div>
      </div>
      <div class="text-center p-2 bg-green-50 dark:bg-green-900/20 rounded">
        <div class="text-lg font-bold text-green-600">{{ stats.completed }}</div>
        <div class="text-xs text-gray-500">Done</div>
      </div>
      <div class="text-center p-2 bg-red-50 dark:bg-red-900/20 rounded">
        <div class="text-lg font-bold text-red-600">{{ stats.failed }}</div>
        <div class="text-xs text-gray-500">Failed</div>
      </div>
    </div>

    <!-- Add Form -->
    <div v-if="showAddForm" class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
      <textarea
        v-model="newUrls"
        placeholder="Enter URLs (one per line)"
        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
        rows="3"
      ></textarea>
      <div class="flex items-center gap-2 mt-2">
        <select v-model="newPurpose" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-2 py-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
          <option value="genealogy_research">Genealogy Research</option>
          <option value="cookie_capture">Cookie Capture</option>
          <option value="record_extraction">Record Extraction</option>
          <option value="general">General</option>
        </select>
        <button @click="addUrls" :disabled="!newUrls.trim()" class="px-3 py-1 text-sm bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50">
          Add to Queue
        </button>
      </div>
    </div>

    <!-- Queue Items -->
    <div v-if="loading" class="text-center py-4 text-gray-500">Loading...</div>
    <div v-else-if="items.length === 0" class="text-center py-4 text-gray-500">No pending items in queue</div>
    <div v-else class="space-y-2 max-h-96 overflow-y-auto">
      <div
        v-for="item in items"
        :key="item.id"
        class="flex items-start gap-3 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg"
      >
        <div class="flex-1 min-w-0">
          <a :href="item.url" target="_blank" class="text-sm text-blue-600 dark:text-blue-400 hover:underline truncate block">
            {{ item.url }}
          </a>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-xs px-2 py-0.5 bg-gray-200 dark:bg-gray-600 rounded text-gray-600 dark:text-gray-300">
              {{ item.domain }}
            </span>
            <span class="text-xs text-gray-500">{{ item.purpose }}</span>
          </div>
        </div>
        <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap"
          :class="{
            'bg-yellow-100 text-yellow-700': item.status === 'pending',
            'bg-green-100 text-green-700': item.status === 'completed',
            'bg-red-100 text-red-700': item.status === 'failed',
          }"
        >
          {{ item.status }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const items = ref([])
const stats = ref(null)
const loading = ref(true)
const showAddForm = ref(false)
const newUrls = ref('')
const newPurpose = ref('genealogy_research')

onMounted(() => {
  loadQueue()
  loadStats()
})

async function loadQueue() {
  loading.value = true
  try {
    const response = await fetch('/api/extension/genealogy/browse-queue')
    const data = await response.json()
    if (data.success) {
      items.value = data.items
    }
  } catch (e) {
    console.error('Failed to load queue:', e)
  } finally {
    loading.value = false
  }
}

async function loadStats() {
  try {
    const response = await fetch('/api/extension/genealogy/browse-queue/stats')
    const data = await response.json()
    if (data.success) {
      stats.value = data.data
    }
  } catch (e) {
    console.error('Failed to load stats:', e)
  }
}

async function addUrls() {
  const urls = newUrls.value.trim().split('\n').filter(u => u.trim())
  if (urls.length === 0) return

  try {
    const response = await fetch('/api/extension/genealogy/browse-queue', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        items: urls.map(url => ({
          url: url.trim(),
          purpose: newPurpose.value,
        }))
      })
    })
    const data = await response.json()
    if (data.success) {
      newUrls.value = ''
      showAddForm.value = false
      loadQueue()
      loadStats()
    }
  } catch (e) {
    console.error('Failed to add URLs:', e)
  }
}
</script>

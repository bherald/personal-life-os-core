<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">File Catalog</h1>
      <div class="flex gap-2">
        <button
          @click="triggerScan"
          :disabled="loading.scan"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          <span v-if="loading.scan">Scanning...</span>
          <span v-else>Scan Now</span>
        </button>
        <button
          @click="triggerRagSync"
          :disabled="loading.ragSync"
          class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
        >
          <span v-if="loading.ragSync">Syncing...</span>
          <span v-else>Sync to RAG</span>
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
      <nav class="-mb-px flex space-x-8">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'py-4 px-1 border-b-2 font-medium text-sm',
            activeTab === tab.id
              ? 'border-blue-500 text-blue-600 dark:text-blue-400'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'
          ]"
        >
          {{ tab.label }}
          <span
            v-if="tab.badge"
            class="ml-2 py-0.5 px-2 rounded-full text-xs"
            :class="tab.badgeClass"
          >
            {{ tab.badge }}
          </span>
        </button>
      </nav>
    </div>

    <!-- Dashboard Tab -->
    <div v-if="activeTab === 'dashboard'" class="space-y-6">
      <!-- Stats Cards -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <div class="text-sm text-gray-500 dark:text-gray-400">Total Files</div>
          <div class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ formatNumber(dashboard.fileStats?.total_files || 0) }}
          </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <div class="text-sm text-gray-500 dark:text-gray-400">Active Files</div>
          <div class="text-2xl font-bold text-green-600">
            {{ formatNumber(dashboard.fileStats?.active_files || 0) }}
          </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <div class="text-sm text-gray-500 dark:text-gray-400">RAG Indexed</div>
          <div class="text-2xl font-bold text-blue-600">
            {{ formatNumber(dashboard.ragStats?.total_indexed || 0) }}
          </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <div class="text-sm text-gray-500 dark:text-gray-400">Pending Sync</div>
          <div class="text-2xl font-bold text-yellow-600">
            {{ formatNumber(dashboard.ragStats?.pending_indexing || 0) }}
          </div>
        </div>
      </div>

      <!-- Running Scan Status -->
      <div v-if="dashboard.runningScan" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
        <div class="flex items-center gap-2">
          <div class="animate-spin rounded-full h-4 w-4 border-2 border-blue-500 border-t-transparent"></div>
          <span class="text-blue-700 dark:text-blue-300 font-medium">Scan Running</span>
        </div>
        <div class="text-sm text-blue-600 dark:text-blue-400 mt-1">
          {{ dashboard.runningScan.run_type }} - {{ dashboard.runningScan.files_scanned || 0 }} files scanned
        </div>
      </div>

      <!-- Recent Scans -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Scans</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Scanned</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Registered</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Started</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr v-for="scan in dashboard.recentScans" :key="scan.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ scan.run_type }}</td>
                <td class="px-4 py-3">
                  <span
                    class="px-2 py-1 text-xs rounded-full"
                    :class="getStatusClass(scan.status)"
                  >
                    {{ scan.status }}
                  </span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ scan.files_scanned || 0 }}</td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ scan.files_registered || 0 }}</td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ formatDate(scan.started_at) }}</td>
              </tr>
              <tr v-if="!dashboard.recentScans?.length">
                <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                  No recent scans
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Browse Files Tab -->
    <div v-if="activeTab === 'browse'" class="space-y-4">
      <!-- Search/Filter -->
      <div class="flex gap-4 items-center bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search files..."
          class="flex-1 px-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          @keyup.enter="loadFiles"
        />
        <select
          v-model="filters.category"
          class="px-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
        >
          <option value="">All Categories</option>
          <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
        </select>
        <button
          @click="loadFiles"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
        >
          Search
        </button>
      </div>

      <!-- Files Table -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">File</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Path</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Size</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Category</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">RAG</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr v-for="file in files" :key="file.asset_uuid" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                <td class="px-4 py-3">
                  <div class="text-sm font-medium text-gray-900 dark:text-white">{{ file.filename }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">{{ file.category || file.extension }}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-xs truncate">
                  {{ file.current_path }}
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                  {{ formatBytes(file.file_size) }}
                </td>
                <td class="px-4 py-3">
                  <span v-if="file.category" class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-600 rounded">
                    {{ file.category }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <span
                    class="px-2 py-1 text-xs rounded-full"
                    :class="file.rag_indexed_at ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300'"
                  >
                    {{ file.rag_indexed_at ? 'Indexed' : 'Pending' }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <button
                    @click="viewFile(file)"
                    class="text-blue-600 hover:text-blue-800 dark:text-blue-400 text-sm mr-2"
                  >
                    View
                  </button>
                  <button
                    @click="downloadFile(file)"
                    class="text-green-600 hover:text-green-800 dark:text-green-400 text-sm"
                  >
                    Download
                  </button>
                  <button
                    @click="describeFile(file)"
                    :disabled="loading.describe[file.asset_uuid]"
                    class="text-purple-600 hover:text-purple-800 dark:text-purple-400 text-sm ml-2 disabled:opacity-50"
                  >
                    {{ loading.describe[file.asset_uuid] ? 'Describing...' : 'AI Describe' }}
                  </button>
                  <button
                    @click="loadVersions(file)"
                    class="text-yellow-600 hover:text-yellow-800 dark:text-yellow-400 text-sm ml-2"
                  >
                    Versions
                  </button>
                  <button
                    @click="deleteFile(file)"
                    :disabled="loading.delete[file.asset_uuid]"
                    class="text-red-600 hover:text-red-800 dark:text-red-400 text-sm ml-2 disabled:opacity-50"
                  >
                    {{ loading.delete[file.asset_uuid] ? 'Deleting...' : 'Delete' }}
                  </button>
                </td>
              </tr>
              <tr v-if="!files.length && !loading.files">
                <td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                  No files found
                </td>
              </tr>
              <tr v-if="loading.files">
                <td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                  Loading...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
          <div class="text-sm text-gray-600 dark:text-gray-400">
            Showing {{ files.length }} of {{ filesTotal }} files
          </div>
          <div class="flex gap-2">
            <button
              @click="loadFiles(filesOffset - 50)"
              :disabled="filesOffset === 0"
              class="px-3 py-1 border rounded disabled:opacity-50 dark:border-gray-600 dark:text-gray-300"
            >
              Previous
            </button>
            <button
              @click="loadFiles(filesOffset + 50)"
              :disabled="filesOffset + 50 >= filesTotal"
              class="px-3 py-1 border rounded disabled:opacity-50 dark:border-gray-600 dark:text-gray-300"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Settings Tab -->
    <div v-if="activeTab === 'settings'" class="space-y-6">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Catalog Settings</h2>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Base Path (Read-Only)
            </label>
            <input
              :value="settings.base_path"
              disabled
              class="w-full px-4 py-2 border rounded-lg bg-gray-100 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-400"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Exclusion Patterns (one per line)
            </label>
            <textarea
              v-model="exclusionPatternsText"
              rows="5"
              class="w-full px-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="/Library/Temp&#10;/Library/.trash"
            ></textarea>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Include Patterns (override exclusions)
            </label>
            <textarea
              v-model="includePatternsText"
              rows="3"
              class="w-full px-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="/Library/Temp/Important"
            ></textarea>
          </div>

          <button
            @click="saveSettings"
            :disabled="loading.settings"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            Save Settings
          </button>
        </div>
      </div>
    </div>

    <!-- Quarantine Tab -->
    <div v-if="activeTab === 'quarantine'" class="space-y-4">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">File</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Reason</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr v-for="qf in quarantineFiles" :key="qf.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ qf.filename || qf.file_id }}</td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ qf.reason }}</td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ formatDate(qf.quarantined_at || qf.created_at) }}</td>
                <td class="px-4 py-3">
                  <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">
                    {{ qf.status || 'quarantined' }}
                  </span>
                </td>
                <td class="px-4 py-3 flex gap-2">
                  <button @click="reviewQuarantined(qf.id, 'release')" class="text-green-600 hover:text-green-800 dark:text-green-400 text-sm">Release</button>
                  <button @click="reviewQuarantined(qf.id, 'delete')" class="text-red-600 hover:text-red-800 dark:text-red-400 text-sm">Delete</button>
                </td>
              </tr>
              <tr v-if="!quarantineFiles.length && !loading.quarantine">
                <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No quarantined files</td>
              </tr>
              <tr v-if="loading.quarantine">
                <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">Loading...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Bundles Tab -->
    <div v-if="activeTab === 'bundles'" class="space-y-4">
      <div class="flex gap-2 mb-4">
        <button @click="detectBundles(true)" :disabled="loading.bundles" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
          Detect Bundles (Dry Run)
        </button>
        <button @click="detectBundles(false)" :disabled="loading.bundles" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
          Detect & Create
        </button>
      </div>
      <div v-for="bundle in bundles" :key="bundle.id" class="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div class="p-4 flex justify-between items-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700" @click="expandedBundle = expandedBundle === bundle.id ? null : bundle.id">
          <div>
            <div class="font-medium text-gray-900 dark:text-white">{{ bundle.name || `Bundle #${bundle.id}` }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ bundle.member_count || 0 }} files</div>
          </div>
          <span class="text-gray-400">{{ expandedBundle === bundle.id ? '&#9660;' : '&#9654;' }}</span>
        </div>
        <div v-if="expandedBundle === bundle.id" class="border-t border-gray-200 dark:border-gray-700 p-4">
          <div v-for="member in (bundle.members || [])" :key="member.id" class="text-sm text-gray-600 dark:text-gray-300 py-1">
            {{ member.filename || member.asset_uuid }}
          </div>
          <div v-if="!bundle.members?.length" class="text-sm text-gray-500 dark:text-gray-400">No members loaded</div>
        </div>
      </div>
      <div v-if="!bundles.length && !loading.bundles" class="text-center py-8 text-gray-500 dark:text-gray-400">
        No bundles detected. Click "Detect Bundles" to scan.
      </div>
    </div>

    <!-- Collections Tab -->
    <div v-if="activeTab === 'collections'" class="space-y-4">
      <div class="flex justify-end mb-4">
        <button @click="showCreateCollection = true" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          Create Collection
        </button>
      </div>
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Items</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="col in collections" :key="col.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
              <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ col.name }}</td>
              <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ col.type || 'manual' }}</td>
              <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ col.item_count || 0 }}</td>
              <td class="px-4 py-3 flex gap-2">
                <button @click="viewCollectionItems(col)" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 text-sm">View Items</button>
                <button @click="deleteCollection(col.id)" class="text-red-600 hover:text-red-800 dark:text-red-400 text-sm">Delete</button>
              </td>
            </tr>
            <tr v-if="!collections.length">
              <td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No collections</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Collection Items Viewer -->
      <div v-if="viewingCollection" class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <div class="flex justify-between items-center mb-4">
          <h3 class="font-semibold text-gray-900 dark:text-white">{{ viewingCollection.name }} - Items</h3>
          <button @click="viewingCollection = null" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">&times;</button>
        </div>
        <div v-for="item in collectionItems" :key="item.id" class="py-2 border-b border-gray-100 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-300">
          {{ item.filename || item.asset_uuid }}
        </div>
        <div v-if="!collectionItems.length" class="text-center py-4 text-gray-500 dark:text-gray-400">No items in this collection</div>
      </div>
    </div>

    <!-- Create Collection Modal -->
    <div v-if="showCreateCollection" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full mx-4">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Create Collection</h3>
          <button @click="showCreateCollection = false" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">&times;</button>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
            <input v-model="newCollection.name" class="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
            <textarea v-model="newCollection.description" rows="2" class="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
            <select v-model="newCollection.type" class="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white">
              <option value="manual">Manual</option>
              <option value="smart">Smart (criteria-based)</option>
            </select>
          </div>
          <div v-if="newCollection.type === 'smart'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Smart Criteria (JSON)</label>
            <textarea v-model="newCollection.criteria" rows="3" placeholder='{"category": "photo"}' class="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white font-mono text-sm"></textarea>
          </div>
        </div>
        <div class="p-4 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-2">
          <button @click="showCreateCollection = false" class="px-4 py-2 border rounded-lg dark:border-gray-600 dark:text-gray-300">Cancel</button>
          <button @click="createCollection" :disabled="!newCollection.name" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">Create</button>
        </div>
      </div>
    </div>

    <!-- Semantic Search Tab -->
    <div v-if="activeTab === 'semantic-search'" class="space-y-4">
      <div class="flex gap-4 items-center bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <input v-model="semanticQuery" type="text" placeholder="Describe what you're looking for..."
          class="flex-1 px-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          @keyup.enter="runSemanticSearch" />
        <button @click="runSemanticSearch" :disabled="loading.semanticSearch || !semanticQuery.trim()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
          {{ loading.semanticSearch ? 'Searching...' : 'Search' }}
        </button>
      </div>
      <div v-for="result in semanticResults" :key="result.asset_uuid || result.id" class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <div class="flex justify-between items-start">
          <div>
            <div class="font-medium text-gray-900 dark:text-white">{{ result.filename }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ result.current_path || result.path }}</div>
            <div v-if="result.ai_description" class="text-sm text-gray-600 dark:text-gray-300 mt-2">{{ result.ai_description }}</div>
          </div>
          <div v-if="result.relevance_score || result.similarity" class="text-sm font-medium px-2 py-1 bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 rounded">
            {{ Math.round((result.relevance_score || result.similarity) * 100) }}%
          </div>
        </div>
      </div>
      <div v-if="!semanticResults.length && !loading.semanticSearch" class="text-center py-8 text-gray-500 dark:text-gray-400">
        Enter a natural language query to find files
      </div>
    </div>

    <!-- Versions Modal -->
    <div v-if="showVersionsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[70vh] overflow-y-auto">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0 bg-white dark:bg-gray-800">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">File Versions</h3>
          <button @click="showVersionsModal = false" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">&times;</button>
        </div>
        <div class="p-4">
          <div v-for="(ver, i) in fileVersions" :key="i" class="py-3 border-b border-gray-100 dark:border-gray-700 last:border-0">
            <div class="flex justify-between items-center">
              <div class="text-sm text-gray-900 dark:text-white">Version {{ ver.version || fileVersions.length - i }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(ver.created_at) }}</div>
            </div>
            <div v-if="ver.file_size" class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ formatBytes(ver.file_size) }}</div>
          </div>
          <div v-if="!fileVersions.length" class="text-center py-4 text-gray-500 dark:text-gray-400">No version history</div>
        </div>
      </div>
    </div>

    <!-- File Detail Modal -->
    <div v-if="selectedFile" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">File Details</h3>
          <button @click="selectedFile = null" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">
            &times;
          </button>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Filename</div>
            <div class="text-gray-900 dark:text-white font-medium">{{ selectedFile.file?.filename }}</div>
          </div>
          <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Asset UUID</div>
            <div class="text-gray-900 dark:text-white font-mono text-sm">{{ selectedFile.file?.asset_uuid }}</div>
          </div>
          <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Path</div>
            <div class="text-gray-900 dark:text-white">{{ selectedFile.file?.current_path }}</div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <div class="text-sm text-gray-500 dark:text-gray-400">Size</div>
              <div class="text-gray-900 dark:text-white">{{ formatBytes(selectedFile.file?.file_size) }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-500 dark:text-gray-400">MIME Type</div>
              <div class="text-gray-900 dark:text-white">{{ selectedFile.file?.mime_type || 'Unknown' }}</div>
            </div>
          </div>
          <div v-if="selectedFile.pathHistory?.length">
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">Path History</div>
            <div class="space-y-1">
              <div v-for="(h, i) in selectedFile.pathHistory" :key="i" class="text-sm text-gray-600 dark:text-gray-300">
                {{ formatDate(h.changed_at) }}: {{ h.change_type }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

const activeTab = ref('dashboard')
const tabs = computed(() => [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'browse', label: 'Browse Files', badge: filesTotal.value, badgeClass: 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' },
  { id: 'quarantine', label: 'Quarantine', badge: quarantineFiles.value.length || null, badgeClass: 'bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300' },
  { id: 'bundles', label: 'Bundles' },
  { id: 'collections', label: 'Collections' },
  { id: 'semantic-search', label: 'Semantic Search' },
  { id: 'settings', label: 'Settings' }
])

const loading = ref({
  dashboard: false,
  files: false,
  scan: false,
  ragSync: false,
  settings: false,
  quarantine: false,
  bundles: false,
  collections: false,
  semanticSearch: false,
  describe: {},
  delete: {}
})

const dashboard = ref({
  fileStats: {},
  ragStats: {},
  recentScans: [],
  runningScan: null
})

const files = ref([])
const filesTotal = ref(0)
const filesOffset = ref(0)
const categories = ref([])
const filters = ref({
  search: '',
  category: ''
})

const settings = ref({
  base_path: '/Library',
  exclusion_patterns: [],
  include_patterns: []
})

const exclusionPatternsText = computed({
  get: () => settings.value.exclusion_patterns.join('\n'),
  set: (val) => { settings.value.exclusion_patterns = val.split('\n').filter(p => p.trim()) }
})

const includePatternsText = computed({
  get: () => settings.value.include_patterns.join('\n'),
  set: (val) => { settings.value.include_patterns = val.split('\n').filter(p => p.trim()) }
})

const selectedFile = ref(null)

// Quarantine state
const quarantineFiles = ref([])
const quarantineReview = ref(null)

// Bundles state
const bundles = ref([])
const expandedBundle = ref(null)

// Collections state
const collections = ref([])
const showCreateCollection = ref(false)
const newCollection = ref({ name: '', description: '', type: 'manual', criteria: '' })
const collectionItems = ref([])
const viewingCollection = ref(null)

// Semantic Search state
const semanticQuery = ref('')
const semanticResults = ref([])

// Versions state
const showVersionsModal = ref(false)
const fileVersions = ref([])

// Tab lazy loading
const tabLoaded = ref({ quarantine: false, bundles: false, collections: false })
import { watch } from 'vue'
watch(activeTab, (tab) => {
  if (tab === 'quarantine' && !tabLoaded.value.quarantine) { loadQuarantine(); tabLoaded.value.quarantine = true }
  if (tab === 'bundles' && !tabLoaded.value.bundles) { loadBundles(); tabLoaded.value.bundles = true }
  if (tab === 'collections' && !tabLoaded.value.collections) { loadCollections(); tabLoaded.value.collections = true }
})

onMounted(() => {
  loadDashboard()
  loadFiles()
  loadSettings()
})

async function loadDashboard() {
  loading.value.dashboard = true
  try {
    const { data } = await axios.get('/api/file-catalog/dashboard')
    if (data.success) {
      dashboard.value = data.data
    }
  } catch (err) {
    console.error('Failed to load dashboard:', err)
  } finally {
    loading.value.dashboard = false
  }
}

async function loadFiles(offset = 0) {
  loading.value.files = true
  filesOffset.value = Math.max(0, offset)
  try {
    const params = {
      offset: filesOffset.value,
      limit: 50,
      search: filters.value.search || undefined,
      category: filters.value.category || undefined
    }
    const { data } = await axios.get('/api/file-catalog/files', { params })
    if (data.success) {
      files.value = data.data
      filesTotal.value = data.total

      // Extract unique categories
      const cats = new Set(files.value.map(f => f.category).filter(Boolean))
      if (cats.size > categories.value.length) {
        categories.value = [...cats].sort()
      }
    }
  } catch (err) {
    console.error('Failed to load files:', err)
  } finally {
    loading.value.files = false
  }
}

async function loadSettings() {
  try {
    const { data } = await axios.get('/api/file-catalog/settings')
    if (data.success) {
      settings.value = data.data
    }
  } catch (err) {
    console.error('Failed to load settings:', err)
  }
}

async function saveSettings() {
  loading.value.settings = true
  try {
    await axios.put('/api/file-catalog/settings', {
      exclusion_patterns: settings.value.exclusion_patterns,
      include_patterns: settings.value.include_patterns
    })
  } catch (err) {
    console.error('Failed to save settings:', err)
  } finally {
    loading.value.settings = false
  }
}

async function triggerScan() {
  loading.value.scan = true
  try {
    await axios.post('/api/file-catalog/scan', { limit: 500 })
    console.info('File registry scan queued')
  } catch (err) {
    console.error('Failed to trigger scan:', err)
  } finally {
    loading.value.scan = false
  }
}

async function triggerRagSync() {
  loading.value.ragSync = true
  try {
    await axios.post('/api/file-catalog/rag/sync', { limit: 100 })
    console.info('File catalog RAG sync queued')
  } catch (err) {
    console.error('Failed to trigger RAG sync:', err)
  } finally {
    loading.value.ragSync = false
  }
}

async function viewFile(file) {
  try {
    const { data } = await axios.get(`/api/file-catalog/files/${file.asset_uuid}`)
    if (data.success) {
      selectedFile.value = data.data
    }
  } catch (err) {
    console.error('Failed to load file details:', err)
  }
}

async function downloadFile(file) {
  try {
    const { data } = await axios.get(`/api/file-catalog/files/${file.asset_uuid}/download`)
    if (data.success && data.data.download_url) {
      window.open(data.data.download_url, '_blank')
    }
  } catch (err) {
    console.error('Failed to get download URL:', err)
  }
}

// Quarantine
async function loadQuarantine() {
  loading.value.quarantine = true
  try {
    const { data } = await axios.get('/api/file-catalog/quarantine')
    if (data.success) quarantineFiles.value = data.data || []
  } catch (err) { console.error('Failed to load quarantine:', err) }
  finally { loading.value.quarantine = false }
}

async function reviewQuarantined(id, action) {
  try {
    await axios.post(`/api/file-catalog/quarantine/${id}/review`, { action })
    loadQuarantine()
  } catch (err) { console.error('Failed to review quarantined file:', err) }
}

// Bundles
async function loadBundles() {
  loading.value.bundles = true
  try {
    const { data } = await axios.get('/api/file-catalog/bundles')
    if (data.success) bundles.value = data.data || []
  } catch (err) { console.error('Failed to load bundles:', err) }
  finally { loading.value.bundles = false }
}

async function detectBundles(dryRun = true) {
  loading.value.bundles = true
  try {
    const { data } = await axios.post('/api/file-catalog/bundles/detect', { dry_run: dryRun })
    if (data.success) { loadBundles() }
  } catch (err) { console.error('Failed to detect bundles:', err) }
  finally { loading.value.bundles = false }
}

// Collections
async function loadCollections() {
  loading.value.collections = true
  try {
    const { data } = await axios.get('/api/file-catalog/collections')
    if (data.success) collections.value = data.data || []
  } catch (err) { console.error('Failed to load collections:', err) }
  finally { loading.value.collections = false }
}

async function createCollection() {
  try {
    const payload = { ...newCollection.value }
    if (payload.criteria) payload.criteria = JSON.parse(payload.criteria)
    await axios.post('/api/file-catalog/collections', payload)
    showCreateCollection.value = false
    newCollection.value = { name: '', description: '', type: 'manual', criteria: '' }
    loadCollections()
  } catch (err) { console.error('Failed to create collection:', err) }
}

async function viewCollectionItems(col) {
  viewingCollection.value = col
  try {
    const { data } = await axios.get(`/api/file-catalog/collections/${col.id}/items`)
    if (data.success) collectionItems.value = data.data || []
  } catch (err) { console.error('Failed to load collection items:', err) }
}

async function deleteCollection(id) {
  if (!confirm('Delete this collection?')) return
  try {
    await axios.delete(`/api/file-catalog/collections/${id}`)
    loadCollections()
    viewingCollection.value = null
  } catch (err) { console.error('Failed to delete collection:', err) }
}

// Semantic Search
async function runSemanticSearch() {
  if (!semanticQuery.value.trim()) return
  loading.value.semanticSearch = true
  try {
    const { data } = await axios.get('/api/file-catalog/semantic-search', {
      params: { query: semanticQuery.value, limit: 20 }
    })
    if (data.success) semanticResults.value = data.data || []
  } catch (err) { console.error('Failed to run semantic search:', err) }
  finally { loading.value.semanticSearch = false }
}

// AI Describe
async function describeFile(file) {
  loading.value.describe = { ...loading.value.describe, [file.asset_uuid]: true }
  try {
    const { data } = await axios.post(`/api/file-catalog/files/${file.asset_uuid}/describe`)
    if (data.success) {
      file.ai_description = data.data?.description || 'Description generated'
    }
  } catch (err) { console.error('Failed to describe file:', err) }
  finally { loading.value.describe = { ...loading.value.describe, [file.asset_uuid]: false } }
}

// Delete
async function deleteFile(file) {
  if (!confirm(`Permanently delete "${file.filename}"?\n\nThis will remove the file from Nextcloud, file registry, and RAG index.`)) return
  loading.value.delete = { ...loading.value.delete, [file.asset_uuid]: true }
  try {
    await axios.delete(`/api/media/${file.asset_uuid}`)
    files.value = files.value.filter(f => f.asset_uuid !== file.asset_uuid)
    filesTotal.value = Math.max(0, (filesTotal.value || 0) - 1)
  } catch (err) {
    alert('Delete failed: ' + (err.response?.data?.error || err.message))
  } finally {
    loading.value.delete = { ...loading.value.delete, [file.asset_uuid]: false }
  }
}

// Versions
async function loadVersions(file) {
  try {
    const { data } = await axios.get(`/api/file-catalog/files/${file.asset_uuid}/versions`)
    if (data.success) {
      fileVersions.value = data.data || []
      showVersionsModal.value = true
    }
  } catch (err) { console.error('Failed to load versions:', err) }
}

function formatNumber(num) {
  return new Intl.NumberFormat().format(num)
}

function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function formatDate(date) {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString()
}

function getStatusClass(status) {
  const classes = {
    completed: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    running: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'
  }
  return classes[status] || 'bg-gray-100 text-gray-700 dark:bg-gray-900 dark:text-gray-300'
}
</script>

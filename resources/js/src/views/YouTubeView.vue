<template>
  <div class="container mx-auto p-6">
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold">YouTube Integration</h1>
        <p class="mt-2 text-sm text-gray-400">Manage YouTube OAuth connection, channel filtering, and video processing</p>
      </div>
      <div class="flex gap-2">
        <button
          v-if="connectionStatus.connected"
          @click="testConnection"
          :disabled="loading"
          class="btn btn-secondary"
        >
          Test Connection
        </button>
      </div>
    </div>

    <!-- Alert Messages -->
    <div v-if="alert.show" :class="alertClasses" class="mb-6 p-4 rounded-lg">
      <div class="flex items-start">
        <div class="flex-shrink-0">
          <svg v-if="alert.type === 'success'" class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
          <svg v-else class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <p class="text-sm font-medium">{{ alert.message }}</p>
        </div>
        <div class="ml-auto pl-3">
          <button @click="alert.show = false" class="inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2">
            <span class="sr-only">Dismiss</span>
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="card">
      <div class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    </div>

    <!-- Content -->
    <div v-else class="space-y-6">
      <!-- OAuth Connection Status -->
      <div class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">YouTube Account Connection</h2>
          <p class="mt-1 text-sm text-gray-400">Manage your YouTube OAuth connection</p>
        </div>

        <div v-if="!connectionStatus.connected" class="space-y-4">
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                </svg>
              </div>
              <div class="ml-3 flex-1">
                <h3 class="text-sm font-medium text-blue-800">No YouTube account connected</h3>
                <div class="mt-2 text-sm text-blue-700">
                  <p>Connect your YouTube account to enable:</p>
                  <ul class="list-disc list-inside mt-2 space-y-1">
                    <li>Automatic subscription monitoring</li>
                    <li>Video transcript extraction</li>
                    <li>Watch Later playlist processing</li>
                    <li>Manual URL processing</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <button
            @click="connectYouTube"
            class="btn btn-primary w-full sm:w-auto"
          >
            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
              <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
            </svg>
            Connect with Google
          </button>
        </div>

        <div v-else class="space-y-4">
          <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
              </div>
              <div class="ml-3 flex-1">
                <h3 class="text-sm font-medium text-green-800">YouTube Account Connected</h3>
                <div class="mt-2 text-sm text-green-700 space-y-1">
                  <p><strong>Connected:</strong> {{ formatDate(connectionStatus.created_at) }}</p>
                  <p><strong>Last Updated:</strong> {{ formatDate(connectionStatus.updated_at) }}</p>
                  <p v-if="connectionStatus.access_token_expires_at">
                    <strong>Token Expires:</strong> {{ formatDate(connectionStatus.access_token_expires_at) }}
                    <span v-if="connectionStatus.is_expired" class="text-red-600 font-semibold ml-2">(Expired - will refresh automatically)</span>
                  </p>
                </div>
              </div>
            </div>
          </div>

          <button
            @click="disconnectYouTube"
            :disabled="loading"
            class="btn btn-danger"
          >
            Disconnect YouTube Account
          </button>
        </div>
      </div>

      <!-- Channel Configuration (only shown when connected) -->
      <div v-if="connectionStatus.connected" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">Multi-Tier Channel Configuration</h2>
          <p class="mt-1 text-sm text-gray-400">Configure which channels to process automatically</p>
        </div>

        <div class="space-y-6">
          <!-- Tier 1: Priority Channels -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <div>
                <h3 class="text-lg font-medium text-gray-100 flex items-center">
                  <span class="inline-flex items-center justify-center px-2 py-1 mr-2 text-xs font-bold leading-none text-white bg-yellow-500 rounded">Tier 1</span>
                  Priority Channels
                </h3>
                <p class="text-sm text-gray-400 mt-1">Always process all videos from these channels</p>
              </div>
              <button
                @click="loadSubscriptions"
                :disabled="loadingSubscriptions"
                class="btn btn-secondary btn-sm"
              >
                {{ loadingSubscriptions ? 'Loading...' : 'Load Subscriptions' }}
              </button>
            </div>

            <div v-if="subscriptions.length > 0" class="space-y-2 max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-3">
              <label
                v-for="channel in subscriptions"
                :key="channel.channel_id"
                class="flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer"
              >
                <input
                  type="checkbox"
                  :value="channel.channel_id"
                  v-model="channelConfig.tier1_channels"
                  @change="markConfigChanged"
                  class="mr-3 h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                >
                <img
                  v-if="channel.thumbnail"
                  :src="channel.thumbnail"
                  :alt="channel.channel_title"
                  class="w-8 h-8 rounded-full mr-3"
                >
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-100 truncate">{{ channel.channel_title }}</p>
                  <p class="text-xs text-gray-400 truncate">{{ channel.description }}</p>
                </div>
              </label>
            </div>

            <div v-else class="text-sm text-gray-400 italic p-4 border border-gray-200 rounded-lg">
              Click "Load Subscriptions" to see your YouTube subscriptions
            </div>

            <p class="text-xs text-gray-400 mt-2">
              Selected: {{ channelConfig.tier1_channels.length }} channel(s)
            </p>
          </div>

          <!-- Tier 2: Keyword-Filtered Channels -->
          <div>
            <h3 class="text-lg font-medium text-gray-100 flex items-center mb-3">
              <span class="inline-flex items-center justify-center px-2 py-1 mr-2 text-xs font-bold leading-none text-white bg-blue-500 rounded">Tier 2</span>
              Keyword-Filtered Channels
            </h3>
            <p class="text-sm text-gray-400 mb-3">Process videos from other subscriptions only if title matches these keywords</p>

            <div class="space-y-2">
              <div class="flex gap-2">
                <input
                  v-model="newKeyword"
                  @keyup.enter="addKeyword"
                  type="text"
                  placeholder="Enter keyword and press Enter"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                >
                <button
                  @click="addKeyword"
                  class="btn btn-secondary"
                >
                  Add
                </button>
              </div>

              <div class="flex flex-wrap gap-2">
                <span
                  v-for="(keyword, index) in channelConfig.tier2_keywords"
                  :key="index"
                  class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800"
                >
                  {{ keyword }}
                  <button
                    @click="removeKeyword(index)"
                    class="ml-2 inline-flex items-center p-0.5 rounded-full text-blue-400 hover:bg-blue-200 hover:text-blue-600 focus:outline-none"
                  >
                    <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                  </button>
                </span>
              </div>

              <p v-if="channelConfig.tier2_keywords.length === 0" class="text-xs text-gray-400 italic">
                No keywords configured. All other subscriptions will be ignored.
              </p>
            </div>
          </div>

          <!-- Content Filters -->
          <div>
            <h3 class="text-lg font-medium text-gray-100 mb-3">Content Filters (All Tiers)</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-300 mb-1">
                  Min Duration (minutes)
                </label>
                <input
                  v-model.number="channelConfig.min_duration"
                  @input="markConfigChanged"
                  type="number"
                  min="0"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                >
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-300 mb-1">
                  Max Duration (minutes)
                </label>
                <input
                  v-model.number="channelConfig.max_duration"
                  @input="markConfigChanged"
                  type="number"
                  min="0"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                >
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-300 mb-1">
                  Max Age (hours)
                </label>
                <input
                  v-model.number="channelConfig.max_age_hours"
                  @input="markConfigChanged"
                  type="number"
                  min="1"
                  max="168"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                >
                <p class="text-xs text-gray-400 mt-1">Only process videos uploaded within this timeframe</p>
              </div>
            </div>
          </div>

          <!-- Save Configuration Button -->
          <div class="flex justify-end">
            <button
              v-if="configChanged"
              @click="saveChannelConfig"
              :disabled="loading || saving"
              class="btn btn-primary"
            >
              {{ saving ? 'Saving...' : 'Save Configuration' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Manual URL Processing -->
      <div v-if="connectionStatus.connected" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">Manual Video Processing</h2>
          <p class="mt-1 text-sm text-gray-400">Process a single YouTube video on-demand</p>
        </div>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">
              YouTube URL
            </label>
            <div class="flex gap-2">
              <input
                v-model="manualUrl"
                type="text"
                placeholder="https://youtube.com/watch?v=..."
                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
              >
              <button
                @click="processVideo"
                :disabled="!manualUrl || processing"
                class="btn btn-primary"
              >
                {{ processing ? 'Processing...' : 'Process Video' }}
              </button>
            </div>
          </div>

          <div v-if="processingResult" :class="processingResult.success ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'" class="border rounded-lg p-4">
            <p class="text-sm font-medium" :class="processingResult.success ? 'text-green-800' : 'text-red-800'">
              {{ processingResult.message }}
            </p>
            <p v-if="processingResult.video_id" class="text-xs text-gray-400 mt-1">
              Video ID: {{ processingResult.video_id }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

// State
const loading = ref(true);
const saving = ref(false);
const processing = ref(false);
const loadingSubscriptions = ref(false);
const configChanged = ref(false);

const alert = ref({
  show: false,
  type: 'success',
  message: ''
});

const connectionStatus = ref({
  connected: false,
  has_refresh_token: false,
  has_access_token: false,
  access_token_expires_at: null,
  is_expired: false,
  created_at: null,
  updated_at: null,
});

const subscriptions = ref([]);
const newKeyword = ref('');
const manualUrl = ref('');
const processingResult = ref(null);

const channelConfig = ref({
  tier1_channels: [],
  tier2_keywords: [],
  max_age_hours: 24,
  min_duration: 10,
  max_duration: 60,
  limit: 10,
});

// Computed
const alertClasses = computed(() => ({
  'bg-green-50 border-green-200': alert.value.type === 'success',
  'bg-red-50 border-red-200': alert.value.type === 'error',
}));

// Methods
const showAlert = (message, type = 'success') => {
  alert.value = { show: true, message, type };
  setTimeout(() => {
    alert.value.show = false;
  }, 5000);
};

const formatDate = (date) => {
  if (!date) return 'N/A';
  return new Date(date).toLocaleString();
};

const loadConnectionStatus = async () => {
  try {
    const response = await axios.get('/api/youtube/connection-status');
    connectionStatus.value = response.data;
  } catch (error) {
    console.error('Failed to load connection status:', error);
    connectionStatus.value.connected = false;
  }
};

const loadChannelConfig = async () => {
  try {
    const response = await axios.get('/api/youtube/config');
    if (response.data.success) {
      channelConfig.value = response.data.config;
    }
  } catch (error) {
    console.error('Failed to load channel config:', error);
    showAlert('Failed to load channel configuration', 'error');
  }
};

const connectYouTube = () => {
  window.location.href = '/api/youtube/auth';
};

const disconnectYouTube = async () => {
  if (!confirm('Are you sure you want to disconnect your YouTube account?')) {
    return;
  }

  try {
    loading.value = true;
    const response = await axios.post('/api/youtube/disconnect');

    if (response.data.success) {
      showAlert('YouTube account disconnected successfully', 'success');
      connectionStatus.value.connected = false;
      subscriptions.value = [];
      channelConfig.value = {
        tier1_channels: [],
        tier2_keywords: [],
        max_age_hours: 24,
        min_duration: 10,
        max_duration: 60,
        limit: 10,
      };
    } else {
      showAlert(response.data.message || 'Failed to disconnect', 'error');
    }
  } catch (error) {
    console.error('Failed to disconnect:', error);
    showAlert('Failed to disconnect YouTube account', 'error');
  } finally {
    loading.value = false;
  }
};

const testConnection = async () => {
  try {
    loading.value = true;
    const response = await axios.get('/api/youtube/subscriptions', {
      params: { maxResults: 1 }
    });

    if (response.data.success) {
      showAlert(`Connection successful! ${response.data.total_results} total subscriptions found.`, 'success');
    } else {
      showAlert('Connection test failed', 'error');
    }
  } catch (error) {
    console.error('Connection test failed:', error);
    showAlert('Connection test failed', 'error');
  } finally {
    loading.value = false;
  }
};

const loadSubscriptions = async () => {
  try {
    loadingSubscriptions.value = true;
    const response = await axios.get('/api/youtube/subscriptions');

    if (response.data.success) {
      subscriptions.value = response.data.subscriptions;
      showAlert(`Loaded ${subscriptions.value.length} subscriptions`, 'success');
    } else {
      showAlert('Failed to load subscriptions', 'error');
    }
  } catch (error) {
    console.error('Failed to load subscriptions:', error);
    showAlert('Failed to load subscriptions', 'error');
  } finally {
    loadingSubscriptions.value = false;
  }
};

const markConfigChanged = () => {
  configChanged.value = true;
};

const addKeyword = () => {
  if (newKeyword.value.trim()) {
    channelConfig.value.tier2_keywords.push(newKeyword.value.trim());
    newKeyword.value = '';
    markConfigChanged();
  }
};

const removeKeyword = (index) => {
  channelConfig.value.tier2_keywords.splice(index, 1);
  markConfigChanged();
};

const saveChannelConfig = async () => {
  try {
    saving.value = true;
    const response = await axios.post('/api/youtube/config', channelConfig.value);

    if (response.data.success) {
      showAlert('Channel configuration saved successfully', 'success');
      configChanged.value = false;
    } else {
      showAlert(response.data.message || 'Failed to save configuration', 'error');
    }
  } catch (error) {
    console.error('Failed to save configuration:', error);
    showAlert('Failed to save configuration', 'error');
  } finally {
    saving.value = false;
  }
};

const processVideo = async () => {
  if (!manualUrl.value.trim()) return;

  try {
    processing.value = true;
    processingResult.value = null;

    const response = await axios.post('/api/youtube/process', {
      url: manualUrl.value
    });

    processingResult.value = {
      success: response.data.success,
      message: response.data.success
        ? 'Video processing queued successfully.'
        : response.data.error,
      video_id: response.data.video_id
    };

    if (response.data.success) {
      manualUrl.value = '';
    }
  } catch (error) {
    console.error('Failed to process video:', error);
    processingResult.value = {
      success: false,
      message: error.response?.data?.error || 'Failed to process video'
    };
  } finally {
    processing.value = false;
  }
};

// Lifecycle
onMounted(async () => {
  loading.value = true;

  await loadConnectionStatus();

  if (connectionStatus.value.connected) {
    await loadChannelConfig();
  }

  loading.value = false;
});
</script>

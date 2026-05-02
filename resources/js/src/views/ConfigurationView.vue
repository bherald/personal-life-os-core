<template>
  <div class="container mx-auto p-6">
    <div class="mb-6 flex items-center justify-between">
      <h1 class="text-3xl font-bold">System Configuration</h1>
      <div class="flex gap-2">
        <button
          v-if="!isInitialized"
          @click="initializeDefaults"
          :disabled="loading"
          class="btn btn-secondary"
        >
          Initialize Defaults
        </button>
        <button
          v-if="hasChanges"
          @click="saveChanges"
          :disabled="loading || saving"
          class="btn btn-primary"
        >
          {{ saving ? 'Saving...' : 'Save Changes' }}
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

    <!-- Configuration Sections -->
    <div v-else class="space-y-6">
      <!-- System Section -->
      <div v-if="configurations.system" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">System Settings</h2>
          <p class="mt-1 text-sm text-gray-400">General application settings</p>
        </div>
        <div class="space-y-4">
          <ConfigField
            v-for="config in configurations.system"
            :key="config.id"
            :config="config"
            v-model="config.value"
            @update:modelValue="markAsChanged(config)"
          />
        </div>
      </div>

      <!-- Workflow Defaults Section -->
      <div v-if="configurations.workflow_defaults" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">Workflow Defaults</h2>
          <p class="mt-1 text-sm text-gray-400">Default settings for new workflows</p>
        </div>
        <div class="space-y-4">
          <ConfigField
            v-for="config in configurations.workflow_defaults"
            :key="config.id"
            :config="config"
            v-model="config.value"
            @update:modelValue="markAsChanged(config)"
          />
        </div>
      </div>

      <!-- AI Settings Section -->
      <div v-if="configurations.ai_settings" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">AI Settings</h2>
          <p class="mt-1 text-sm text-gray-400">Configuration for AI services</p>
        </div>
        <div class="space-y-4">
          <ConfigField
            v-for="config in configurations.ai_settings"
            :key="config.id"
            :config="config"
            v-model="config.value"
            @update:modelValue="markAsChanged(config)"
          />
        </div>
      </div>

      <!-- Notifications Section -->
      <div v-if="configurations.notifications" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">Notifications</h2>
          <p class="mt-1 text-sm text-gray-400">Email and notification settings</p>
        </div>
        <div class="space-y-4">
          <ConfigField
            v-for="config in configurations.notifications"
            :key="config.id"
            :config="config"
            v-model="config.value"
            @update:modelValue="markAsChanged(config)"
          />
        </div>
      </div>

      <!-- Integrations Section -->
      <div v-if="configurations.integrations" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">Integrations</h2>
          <p class="mt-1 text-sm text-gray-400">External service integration settings</p>
        </div>
        <div class="space-y-4">
          <ConfigField
            v-for="config in configurations.integrations"
            :key="config.id"
            :config="config"
            v-model="config.value"
            @update:modelValue="markAsChanged(config)"
          />
        </div>
      </div>

      <!-- Performance Section -->
      <div v-if="configurations.performance" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">Performance</h2>
          <p class="mt-1 text-sm text-gray-400">Performance and optimization settings</p>
        </div>
        <div class="space-y-4">
          <ConfigField
            v-for="config in configurations.performance"
            :key="config.id"
            :config="config"
            v-model="config.value"
            @update:modelValue="markAsChanged(config)"
          />
        </div>
      </div>

      <!-- Security Section -->
      <div v-if="configurations.security" class="card">
        <div class="border-b border-gray-200 pb-4 mb-4">
          <h2 class="text-xl font-semibold text-gray-100">Security</h2>
          <p class="mt-1 text-sm text-gray-400">Security and authentication settings</p>
        </div>
        <div class="space-y-4">
          <ConfigField
            v-for="config in configurations.security"
            :key="config.id"
            :config="config"
            v-model="config.value"
            @update:modelValue="markAsChanged(config)"
          />
        </div>
      </div>

      <!-- Empty State -->
      <div v-if="!isInitialized" class="card text-center py-12">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-100">No configuration found</h3>
        <p class="mt-1 text-sm text-gray-400">Get started by initializing default configuration values.</p>
        <div class="mt-6">
          <button @click="initializeDefaults" class="btn btn-primary">
            Initialize Default Configuration
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';

// Component for individual configuration fields
import ConfigField from '../components/ConfigField.vue';

const configurations = ref({});
const loading = ref(true);
const saving = ref(false);
const changedConfigs = reactive(new Set());

const alert = reactive({
  show: false,
  type: 'success',
  message: ''
});

const isInitialized = computed(() => {
  return Object.keys(configurations.value).length > 0;
});

const hasChanges = computed(() => {
  return changedConfigs.size > 0;
});

const alertClasses = computed(() => {
  return alert.type === 'success'
    ? 'bg-green-50 text-green-800 border border-green-200'
    : 'bg-red-50 text-red-800 border border-red-200';
});

onMounted(async () => {
  await fetchConfigurations();
});

async function fetchConfigurations() {
  loading.value = true;
  try {
    const response = await axios.get('/api/configuration');
    if (response.data.success) {
      configurations.value = response.data.data;
    }
  } catch (error) {
    showAlert('error', error.response?.data?.error?.message || 'Failed to fetch configurations');
  } finally {
    loading.value = false;
  }
}

async function initializeDefaults() {
  loading.value = true;
  try {
    const response = await axios.post('/api/configuration/initialize');
    if (response.data.success) {
      showAlert('success', 'Default configurations initialized successfully');
      await fetchConfigurations();
    }
  } catch (error) {
    showAlert('error', error.response?.data?.error?.message || 'Failed to initialize defaults');
  } finally {
    loading.value = false;
  }
}

async function saveChanges() {
  if (!hasChanges.value) return;

  saving.value = true;
  try {
    const configsToUpdate = [];

    // Collect all changed configurations
    changedConfigs.forEach(configId => {
      for (const section in configurations.value) {
        const config = configurations.value[section].find(c => c.id === configId);
        if (config) {
          configsToUpdate.push({
            id: config.id,
            value: config.value
          });
        }
      }
    });

    const response = await axios.post('/api/configuration/update-multiple', {
      configs: configsToUpdate
    });

    if (response.data.success) {
      showAlert('success', 'Configuration saved successfully');
      changedConfigs.clear();
      await fetchConfigurations();
    }
  } catch (error) {
    showAlert('error', error.response?.data?.error?.message || 'Failed to save configuration');
  } finally {
    saving.value = false;
  }
}

function markAsChanged(config) {
  changedConfigs.add(config.id);
}

function showAlert(type, message) {
  alert.type = type;
  alert.message = message;
  alert.show = true;

  // Auto-hide after 5 seconds
  setTimeout(() => {
    alert.show = false;
  }, 5000);
}
</script>

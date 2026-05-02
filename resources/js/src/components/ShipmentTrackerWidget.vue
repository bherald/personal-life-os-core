<template>
  <div class="card">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-[#e0e0e0] flex items-center gap-2">
        <svg class="w-5 h-5 text-[#f39c12]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </svg>
        Active Shipments
      </h3>
      <div class="flex items-center gap-2">
        <button
          @click="scanEmails"
          class="text-accent hover:text-[#2980b9] text-xs flex items-center gap-1"
          :disabled="scanning"
          title="Scan emails for new shipments"
        >
          <svg class="w-3 h-3" :class="{ 'animate-spin': scanning }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Scan
        </button>
        <span class="text-[#666]">|</span>
        <span class="text-xs text-[#95a5a6]">{{ shipments.length }} active</span>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="text-center py-6">
      <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-accent"></div>
      <p class="mt-2 text-[#95a5a6] text-sm">Loading shipments...</p>
    </div>

    <!-- Empty State -->
    <div v-else-if="shipments.length === 0" class="text-center py-8">
      <svg class="w-12 h-12 mx-auto text-[#444] mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
      </svg>
      <p class="text-[#95a5a6]">No active shipments</p>
      <p class="text-xs text-[#666] mt-1">Shipping emails will be tracked automatically</p>
    </div>

    <!-- Shipments List -->
    <div v-else class="space-y-2 max-h-72 overflow-y-auto">
      <div
        v-for="shipment in shipments"
        :key="shipment.id"
        class="flex items-center justify-between p-3 rounded-lg bg-[#34495e]/50 hover:bg-[#34495e] transition-colors"
      >
        <div class="flex items-center gap-3 flex-1 min-w-0">
          <!-- Carrier Icon -->
          <div class="text-xl" :title="shipment.carrier">{{ getCarrierIcon(shipment.carrier) }}</div>

          <div class="flex-1 min-w-0">
            <p class="font-medium text-[#e0e0e0] text-sm truncate">{{ shipment.description || 'Package' }}</p>
            <div class="flex items-center gap-2 mt-0.5">
              <span class="text-xs text-[#666] uppercase">{{ shipment.carrier }}</span>
              <span v-if="shipment.expected_delivery_at" class="text-xs text-[#95a5a6]">
                {{ formatExpectedDelivery(shipment.expected_delivery_at) }}
              </span>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <!-- Status Badge -->
          <span :class="getStatusClass(shipment.status)" class="px-2 py-0.5 text-xs rounded whitespace-nowrap">
            {{ formatStatus(shipment.status) }}
          </span>

          <!-- Actions -->
          <div class="flex items-center gap-1">
            <button
              @click="markReceived(shipment)"
              class="p-1 text-[#27ae60] hover:bg-[#27ae60]/20 rounded transition-colors"
              title="Mark as received"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
            </button>
            <button
              @click="archiveShipment(shipment)"
              class="p-1 text-[#95a5a6] hover:bg-[#95a5a6]/20 rounded transition-colors"
              title="Archive"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Stats Footer -->
    <div v-if="stats && (stats.out_for_delivery > 0 || stats.stale > 0)" class="mt-3 pt-3 border-t border-[#444] flex justify-between text-xs">
      <span v-if="stats.out_for_delivery > 0" class="text-[#27ae60]">
        {{ stats.out_for_delivery }} out for delivery
      </span>
      <span v-if="stats.stale > 0" class="text-[#e74c3c]">
        {{ stats.stale }} stale
      </span>
    </div>

    <!-- Scan Result Toast -->
    <div v-if="scanResult" class="mt-3 p-2 rounded text-xs bg-accent/20 text-accent">
      Scanned {{ scanResult.scanned }} emails: {{ scanResult.new }} new, {{ scanResult.updated }} updated
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../utils/api';

const loading = ref(true);
const scanning = ref(false);
const shipments = ref([]);
const stats = ref(null);
const scanResult = ref(null);

const fetchShipments = async () => {
  try {
    loading.value = true;
    const [shipmentsResponse, statsResponse] = await Promise.all([
      api.get('/email/v2/shipments'),
      api.get('/email/v2/shipments/stats')
    ]);

    if (shipmentsResponse.success) {
      shipments.value = shipmentsResponse.data || [];
    }

    if (statsResponse.success) {
      stats.value = statsResponse.data || {};
    }
  } catch (err) {
    console.error('Error fetching shipments:', err);
  } finally {
    loading.value = false;
  }
};

const scanEmails = async () => {
  try {
    scanning.value = true;
    scanResult.value = null;

    const response = await api.post('/email/v2/shipments/scan', {
      folder: 'Inbox',
      limit: 50
    });

    if (response.success) {
      scanResult.value = {
        scanned: response.scanned || 0,
        new: response.new || 0,
        updated: response.updated || 0
      };

      // Refresh shipments list
      await fetchShipments();

      // Clear scan result after 5 seconds
      setTimeout(() => {
        scanResult.value = null;
      }, 5000);
    }
  } catch (err) {
    console.error('Error scanning emails:', err);
  } finally {
    scanning.value = false;
  }
};

const markReceived = async (shipment) => {
  try {
    const response = await api.post(`/email/v2/shipments/${shipment.id}/received`);
    if (response.success) {
      // Remove from list
      shipments.value = shipments.value.filter(s => s.id !== shipment.id);
    }
  } catch (err) {
    console.error('Error marking shipment as received:', err);
  }
};

const archiveShipment = async (shipment) => {
  try {
    const response = await api.post(`/email/v2/shipments/${shipment.id}/archive`);
    if (response.success) {
      // Remove from list
      shipments.value = shipments.value.filter(s => s.id !== shipment.id);
    }
  } catch (err) {
    console.error('Error archiving shipment:', err);
  }
};

const getCarrierIcon = (carrier) => {
  const icons = {
    amazon: '📦',
    ups: '🟤',
    fedex: '📮',
    usps: '✉️'
  };
  return icons[carrier?.toLowerCase()] || '📬';
};

const getStatusClass = (status) => {
  const classes = {
    delivered: 'bg-[#27ae60]/20 text-[#27ae60]',
    out_for_delivery: 'bg-[#f39c12]/20 text-[#f39c12]',
    in_transit: 'bg-accent/20 text-accent',
    shipped: 'bg-[#95a5a6]/20 text-[#95a5a6]'
  };
  return classes[status] || 'bg-[#95a5a6]/20 text-[#95a5a6]';
};

const formatStatus = (status) => {
  const labels = {
    delivered: 'Delivered',
    out_for_delivery: 'Out for Delivery',
    in_transit: 'In Transit',
    shipped: 'Shipped'
  };
  return labels[status] || status;
};

const formatExpectedDelivery = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const tomorrow = new Date(today);
  tomorrow.setDate(tomorrow.getDate() + 1);

  const dateOnly = new Date(date);
  dateOnly.setHours(0, 0, 0, 0);

  if (dateOnly.getTime() === today.getTime()) {
    return 'Today';
  }
  if (dateOnly.getTime() === tomorrow.getTime()) {
    return 'Tomorrow';
  }
  return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
};

onMounted(() => {
  fetchShipments();
});
</script>

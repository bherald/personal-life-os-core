<template>
  <div class="min-h-screen bg-[#1a1a1a]">
    <div class="max-w-7xl mx-auto px-4 py-6">
      <!-- Header -->
      <div class="flex justify-between items-center mb-6">
        <div>
          <h2 class="text-3xl font-bold text-[#e0e0e0] border-b-2 border-accent pb-2">Contacts</h2>
          <p class="text-[#95a5a6] mt-1">Nextcloud CardDAV Integration</p>
        </div>
        <div class="flex items-center gap-3">
          <!-- Cache Status Indicator -->
          <div v-if="cacheInfo" class="flex items-center gap-2 text-sm">
            <span v-if="cacheInfo.fromCache" class="px-2 py-1 bg-[#27ae60]/20 text-[#27ae60] rounded text-xs">
              Cached
            </span>
            <span v-else class="px-2 py-1 bg-accent/20 text-accent rounded text-xs">
              Fresh
            </span>
            <span v-if="cacheInfo.lastUpdated" class="text-[#666]">
              {{ formatCacheTime(cacheInfo.lastUpdated) }}
            </span>
          </div>

          <!-- Address Book Selector -->
          <select
            v-model="selectedAddressBook"
            class="px-4 py-2 bg-[#2d2d2d] border border-[#444] rounded-lg text-[#e0e0e0] focus:outline-none focus:ring-2 focus:ring-accent"
          >
            <option value="all">All Address Books</option>
            <option v-for="book in addressBooks" :key="book.id" :value="book.id">
              {{ book.displayName }}
            </option>
          </select>

          <!-- Refresh Button -->
          <button @click="refreshContacts(false)" class="btn-primary flex items-center gap-2" :disabled="loading">
            <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
            </svg>
            Refresh
          </button>

          <!-- Force Refresh Button -->
          <button
            @click="refreshContacts(true)"
            class="px-3 py-2 bg-[#e67e22] hover:bg-[#d35400] text-white rounded-lg text-sm flex items-center gap-2 transition-colors"
            :disabled="loading"
            title="Force refresh from Nextcloud (bypasses cache)"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            Force
          </button>
        </div>
      </div>

      <!-- Search Bar -->
      <div class="mb-6">
        <div class="relative">
          <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-[#95a5a6]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search contacts by name, email, phone, or address..."
            class="w-full pl-10 pr-4 py-3 bg-[#2d2d2d] border border-[#444] rounded-lg text-[#e0e0e0] focus:outline-none focus:ring-2 focus:ring-accent placeholder-[#666]"
          />
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="initialLoading" class="card text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-accent"></div>
        <p class="mt-4 text-[#95a5a6]">Loading contacts...</p>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="alert alert-danger mb-6">
        <div class="flex items-center gap-3">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span>{{ error }}</span>
        </div>
      </div>

      <!-- Contacts Table -->
      <div v-else class="card overflow-hidden">
        <!-- Stats Bar -->
        <div class="flex items-center justify-between mb-4 pb-4 border-b border-[#444]">
          <p class="text-[#95a5a6]">
            <span class="text-[#e0e0e0] font-semibold">{{ filteredContacts.length }}</span> contacts
            <span v-if="searchQuery"> matching "{{ searchQuery }}"</span>
          </p>
          <div class="text-sm text-[#666]">
            Click column headers to sort
          </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="text-left border-b border-[#444]">
                <th
                  @click="sortBy('displayName')"
                  class="px-4 py-3 text-[#95a5a6] font-medium cursor-pointer hover:text-[#e0e0e0] transition-colors"
                >
                  <div class="flex items-center gap-2">
                    Name
                    <span v-if="sortColumn === 'displayName'" class="text-accent">
                      {{ sortDirection === 'asc' ? '↑' : '↓' }}
                    </span>
                  </div>
                </th>
                <th
                  @click="sortBy('phone')"
                  class="px-4 py-3 text-[#95a5a6] font-medium cursor-pointer hover:text-[#e0e0e0] transition-colors"
                >
                  <div class="flex items-center gap-2">
                    Phone
                    <span v-if="sortColumn === 'phone'" class="text-accent">
                      {{ sortDirection === 'asc' ? '↑' : '↓' }}
                    </span>
                  </div>
                </th>
                <th
                  @click="sortBy('email')"
                  class="px-4 py-3 text-[#95a5a6] font-medium cursor-pointer hover:text-[#e0e0e0] transition-colors"
                >
                  <div class="flex items-center gap-2">
                    Email
                    <span v-if="sortColumn === 'email'" class="text-accent">
                      {{ sortDirection === 'asc' ? '↑' : '↓' }}
                    </span>
                  </div>
                </th>
                <th
                  @click="sortBy('address')"
                  class="px-4 py-3 text-[#95a5a6] font-medium cursor-pointer hover:text-[#e0e0e0] transition-colors"
                >
                  <div class="flex items-center gap-2">
                    Address
                    <span v-if="sortColumn === 'address'" class="text-accent">
                      {{ sortDirection === 'asc' ? '↑' : '↓' }}
                    </span>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="contact in filteredContacts"
                :key="contact.uid"
                @click="selectContact(contact)"
                class="border-b border-[#333] hover:bg-[#34495e] cursor-pointer transition-colors"
              >
                <td class="px-4 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-accent flex items-center justify-center text-white font-semibold">
                      {{ getInitials(contact.displayName) }}
                    </div>
                    <div>
                      <p class="text-[#e0e0e0] font-medium">{{ contact.displayName }}</p>
                      <p v-if="contact.organization" class="text-sm text-[#666]">{{ contact.organization }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-4">
                  <a v-if="contact.phone" :href="'tel:' + contact.phone" class="text-accent hover:text-[#2980b9]" @click.stop>
                    {{ contact.phone }}
                  </a>
                  <span v-else class="text-[#666]">-</span>
                </td>
                <td class="px-4 py-4">
                  <a v-if="contact.email" :href="'mailto:' + contact.email" class="text-accent hover:text-[#2980b9]" @click.stop>
                    {{ contact.email }}
                  </a>
                  <span v-else class="text-[#666]">-</span>
                </td>
                <td class="px-4 py-4">
                  <span v-if="contact.address" class="text-[#95a5a6] text-sm">{{ contact.address }}</span>
                  <span v-else class="text-[#666]">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Empty State -->
        <div v-if="filteredContacts.length === 0 && !initialLoading" class="text-center py-12">
          <svg class="w-16 h-16 mx-auto text-[#444]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
          </svg>
          <p class="mt-4 text-[#95a5a6]">
            {{ searchQuery ? 'No contacts match your search' : 'No contacts found' }}
          </p>
        </div>
      </div>

      <!-- Contact Detail Modal -->
      <div v-if="selectedContact" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="selectedContact = null">
        <div class="bg-[#2d2d2d] rounded-lg shadow-xl max-w-lg w-full mx-4 border border-[#444]">
          <div class="p-6">
            <div class="flex justify-between items-start mb-6">
              <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-accent flex items-center justify-center text-white text-2xl font-semibold">
                  {{ getInitials(selectedContact.displayName) }}
                </div>
                <div>
                  <h3 class="text-xl font-semibold text-[#e0e0e0]">{{ selectedContact.displayName }}</h3>
                  <p v-if="selectedContact.title || selectedContact.organization" class="text-[#95a5a6]">
                    {{ [selectedContact.title, selectedContact.organization].filter(Boolean).join(' at ') }}
                  </p>
                </div>
              </div>
              <button @click="selectedContact = null" class="text-[#95a5a6] hover:text-[#e0e0e0]">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div class="space-y-4">
              <!-- Phone -->
              <div v-if="selectedContact.phone" class="flex items-center gap-4 p-3 bg-[#1a1a1a] rounded-lg">
                <svg class="w-5 h-5 text-[#27ae60]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                <a :href="'tel:' + selectedContact.phone" class="text-[#e0e0e0] hover:text-accent">
                  {{ selectedContact.phone }}
                </a>
              </div>

              <!-- Email -->
              <div v-if="selectedContact.email" class="flex items-center gap-4 p-3 bg-[#1a1a1a] rounded-lg">
                <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <a :href="'mailto:' + selectedContact.email" class="text-[#e0e0e0] hover:text-accent">
                  {{ selectedContact.email }}
                </a>
              </div>

              <!-- Address -->
              <div v-if="selectedContact.address" class="flex items-start gap-4 p-3 bg-[#1a1a1a] rounded-lg">
                <svg class="w-5 h-5 text-[#e74c3c] mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="text-[#e0e0e0]">{{ selectedContact.address }}</span>
              </div>

              <!-- Notes -->
              <div v-if="selectedContact.note" class="flex items-start gap-4 p-3 bg-[#1a1a1a] rounded-lg">
                <svg class="w-5 h-5 text-[#f39c12] mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span class="text-[#95a5a6] text-sm">{{ selectedContact.note }}</span>
              </div>

              <!-- Address Book Source -->
              <div v-if="selectedContact.addressBook" class="text-center text-sm text-[#666] pt-2 border-t border-[#444]">
                From: {{ selectedContact.addressBook }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../utils/api';

const loading = ref(false);
const initialLoading = ref(true);
const error = ref(null);
const contacts = ref([]);
const addressBooks = ref([]);
const selectedAddressBook = ref('all');
const searchQuery = ref('');
const selectedContact = ref(null);
const sortColumn = ref('displayName');
const sortDirection = ref('asc');
const cacheInfo = ref(null);

// Format cache time for display
const formatCacheTime = (isoString) => {
  if (!isoString) return '';
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);

  if (diffMins < 1) return 'just now';
  if (diffMins < 60) return `${diffMins}m ago`;

  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h ago`;

  return date.toLocaleDateString();
};

// Fetch contacts
const fetchContacts = async (forceRefresh = false) => {
  loading.value = true;
  try {
    let endpoint = selectedAddressBook.value === 'all'
      ? '/contacts/all'
      : `/contacts?addressBook=${selectedAddressBook.value}`;

    // Add force parameter if needed
    if (forceRefresh) {
      endpoint += endpoint.includes('?') ? '&force=true' : '?force=true';
    }

    const response = await api.get(endpoint);

    if (response.success) {
      contacts.value = response.data.contacts;
      if (response.data.addressBooks) {
        addressBooks.value = response.data.addressBooks;
      }
      // Update cache info
      if (response.cache) {
        cacheInfo.value = response.cache;
      }
    }
  } catch (err) {
    console.error('Failed to fetch contacts:', err);
    error.value = 'Failed to load contacts. Check Nextcloud connection.';
  } finally {
    loading.value = false;
    initialLoading.value = false;
  }
};

// Fetch address books
const fetchAddressBooks = async () => {
  try {
    const response = await api.get('/contacts/addressbooks');
    if (response.success) {
      addressBooks.value = response.data.addressBooks;
    }
  } catch (err) {
    console.error('Failed to fetch address books:', err);
  }
};

// Filtered and sorted contacts
const filteredContacts = computed(() => {
  let result = [...contacts.value];

  // Apply search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    result = result.filter(contact => {
      return (
        (contact.displayName?.toLowerCase().includes(query)) ||
        (contact.email?.toLowerCase().includes(query)) ||
        (contact.phone?.toLowerCase().includes(query)) ||
        (contact.address?.toLowerCase().includes(query)) ||
        (contact.organization?.toLowerCase().includes(query))
      );
    });
  }

  // Apply sorting
  result.sort((a, b) => {
    const aVal = (a[sortColumn.value] || '').toLowerCase();
    const bVal = (b[sortColumn.value] || '').toLowerCase();

    if (sortDirection.value === 'asc') {
      return aVal.localeCompare(bVal);
    } else {
      return bVal.localeCompare(aVal);
    }
  });

  return result;
});

// Sort by column
const sortBy = (column) => {
  if (sortColumn.value === column) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortColumn.value = column;
    sortDirection.value = 'asc';
  }
};

// Get initials for avatar
const getInitials = (name) => {
  if (!name) return '?';
  const parts = name.split(' ');
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  return name.substring(0, 2).toUpperCase();
};

// Select contact to view details
const selectContact = (contact) => {
  selectedContact.value = contact;
};

// Refresh contacts
const refreshContacts = (forceRefresh = false) => {
  fetchContacts(forceRefresh);
};

// Watch for address book changes
import { watch } from 'vue';
watch(selectedAddressBook, () => {
  fetchContacts();
});

// Initialize
onMounted(async () => {
  await fetchAddressBooks();
  await fetchContacts();
});
</script>

<style scoped>
/* Additional styles */
table {
  border-collapse: collapse;
}

tbody tr:last-child {
  border-bottom: none;
}
</style>

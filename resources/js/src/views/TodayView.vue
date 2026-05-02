<template>
  <div class="ops-dashboard">
    <!-- Dashboard Header -->
    <div class="ops-dashboard-header">
      <div class="ops-header-left">
        <h1 class="ops-greeting">Good {{ greeting }}</h1>
        <p class="ops-date">{{ currentDate }}</p>
      </div>
      <div class="ops-header-stats">
        <div class="ops-stat-pill green">
          <span class="ops-stat-value">{{ workflowStats.completedToday }}</span>
          <span class="ops-stat-label">workflows</span>
        </div>
        <div class="ops-stat-pill blue">
          <span class="ops-stat-value">{{ contactsCount }}</span>
          <span class="ops-stat-label">contacts</span>
        </div>
      </div>
      <button @click="refreshAll" class="ops-btn ops-btn-orange" :disabled="loading">
        <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
        </svg>
        <span>Refresh</span>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="initialLoading" class="ops-panel-v2 text-center py-12">
      <div class="ops-loading mx-auto justify-center">
        <div class="ops-loading-dot"></div>
        <div class="ops-loading-dot"></div>
        <div class="ops-loading-dot"></div>
      </div>
      <p class="mt-4 text-[var(--ops-lilac)] uppercase text-sm tracking-wider">Loading dashboard data...</p>
    </div>

    <!-- Main Dashboard Grid - Two Column Ops Console Layout -->
    <div v-else class="ops-dashboard-grid">

      <!-- LEFT COLUMN: Calendar Command Center -->
      <div class="ops-dashboard-col">
        <!-- Calendar Panel - Full Featured -->
        <div class="ops-panel-framed blue">
          <!-- Top Header Bar -->
          <div class="ops-frame-top">
            <div class="ops-frame-elbow-top"></div>
            <div class="ops-frame-header">
              <div class="ops-frame-title-group">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="ops-frame-title">Calendar</span>
              </div>
              <div class="ops-frame-decorators">
                <span class="ops-frame-pill">{{ todaysEvents.length }} TODAY</span>
                <span class="ops-frame-pill">{{ upcomingEvents.length }} UPCOMING</span>
              </div>
              <router-link to="/calendar" class="ops-frame-action">
                Full Calendar →
              </router-link>
            </div>
          </div>

          <!-- Middle Section: Sidebar + Content -->
          <div class="ops-frame-middle">
            <div class="ops-frame-sidebar"></div>
            <div class="ops-frame-content">
            <!-- Today's Events Section -->
            <div class="ops-subsection">
              <div class="ops-subsection-header">
                <span class="ops-subsection-label">Today's Schedule</span>
                <span class="ops-subsection-date">{{ todayFormatted }}</span>
              </div>

              <div v-if="todaysEvents.length === 0" class="ops-empty-state">
                <div class="ops-empty-icon">
                  <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                  </svg>
                </div>
                <p class="ops-empty-text">No events scheduled</p>
                <p class="ops-empty-subtext">Your day is clear</p>
              </div>

              <div v-else class="ops-event-list">
                <div
                  v-for="event in todaysEvents"
                  :key="event.id"
                  class="ops-event-item"
                  :class="{ 'all-day': event.allDay }"
                >
                  <div class="ops-event-time-block" :style="{ backgroundColor: event.color || 'var(--ops-sky)' }">
                    <span v-if="event.allDay" class="ops-event-allday">ALL DAY</span>
                    <span v-else class="ops-event-time">{{ event.time }}</span>
                  </div>
                  <div class="ops-event-details">
                    <p class="ops-event-title">{{ event.title }}</p>
                    <p v-if="event.location" class="ops-event-location">
                      <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                      </svg>
                      {{ event.location }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Upcoming Events Section -->
            <div v-if="upcomingEvents.length > 0" class="ops-subsection">
              <div class="ops-subsection-header">
                <span class="ops-subsection-label">Coming Up</span>
                <span class="ops-subsection-indicator"></span>
              </div>

              <div class="ops-upcoming-list">
                <div
                  v-for="event in upcomingEvents.slice(0, 5)"
                  :key="event.id"
                  class="ops-upcoming-item"
                >
                  <div class="ops-upcoming-date">
                    <span class="ops-upcoming-day">{{ getDayOfMonth(event.start) }}</span>
                    <span class="ops-upcoming-month">{{ getMonthAbbr(event.start) }}</span>
                  </div>
                  <div class="ops-upcoming-details">
                    <p class="ops-upcoming-title">{{ event.title }}</p>
                    <p class="ops-upcoming-weekday">{{ getWeekday(event.start) }}</p>
                  </div>
                  <div class="ops-upcoming-indicator" :style="{ backgroundColor: event.color || 'var(--ops-lilac)' }"></div>
                </div>
              </div>
            </div>
            </div>
          </div>

          <!-- Bottom Footer Bar -->
          <div class="ops-frame-bottom">
            <div class="ops-frame-elbow-bottom"></div>
            <div class="ops-frame-footer"></div>
          </div>
        </div>

        <!-- Shipment Tracker Widget -->
        <div class="mt-4">
          <ShipmentTrackerWidget />
        </div>
      </div>

      <!-- RIGHT COLUMN: Contacts Command Center -->
      <div class="ops-dashboard-col">
        <!-- Contacts Panel - Full Featured -->
        <div class="ops-panel-framed lilac">
          <!-- Top Header Bar -->
          <div class="ops-frame-top">
            <div class="ops-frame-elbow-top"></div>
            <div class="ops-frame-header">
              <div class="ops-frame-title-group">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="ops-frame-title">Contacts</span>
              </div>
              <div class="ops-frame-decorators">
                <span class="ops-frame-pill">{{ contactsCount }} TOTAL</span>
              </div>
              <router-link to="/contacts" class="ops-frame-action">
                All Contacts →
              </router-link>
            </div>
          </div>

          <!-- Middle Section: Sidebar + Content -->
          <div class="ops-frame-middle">
            <div class="ops-frame-sidebar"></div>
            <div class="ops-frame-content">
            <!-- Search Section -->
            <div class="ops-search-section">
              <div class="ops-search-bar">
                <svg class="ops-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                  v-model="contactSearch"
                  type="text"
                  placeholder="Search contacts..."
                  class="ops-search-input"
                />
                <span v-if="contactSearch" class="ops-search-count">{{ filteredContacts.length }} found</span>
              </div>
            </div>

            <!-- Contacts Grid -->
            <div v-if="filteredContacts.length === 0" class="ops-empty-state">
              <div class="ops-empty-icon">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
              </div>
              <p class="ops-empty-text">{{ contactSearch ? 'No matches found' : 'No contacts' }}</p>
              <p class="ops-empty-subtext">{{ contactSearch ? 'Try a different search' : 'Add contacts to get started' }}</p>
            </div>

            <div v-else class="ops-contacts-grid ops-scroll">
              <div
                v-for="(contact, index) in filteredContacts.slice(0, 12)"
                :key="contact.uid"
                @click="showContactDetail(contact)"
                class="ops-contact-card"
                :class="avatarColors[index % avatarColors.length]"
              >
                <div class="ops-contact-avatar" :class="avatarColors[index % avatarColors.length]">
                  {{ getInitials(contact.displayName) }}
                </div>
                <div class="ops-contact-info">
                  <p class="ops-contact-name">{{ contact.displayName }}</p>
                  <p class="ops-contact-detail">{{ contact.phone || contact.email || '' }}</p>
                </div>
                <div class="ops-contact-actions">
                  <a v-if="contact.phone" :href="'tel:' + contact.phone" @click.stop class="ops-contact-action green">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                  </a>
                  <a v-if="contact.email" :href="'mailto:' + contact.email" @click.stop class="ops-contact-action blue">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                  </a>
                </div>
              </div>
            </div>

            <!-- Alphabet Quick Nav -->
            <div class="ops-alpha-nav">
              <button
                v-for="letter in 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')"
                :key="letter"
                @click="jumpToLetter(letter)"
                class="ops-alpha-btn"
                :class="{ active: hasContactsForLetter(letter) }"
              >
                {{ letter }}
              </button>
            </div>
            </div>
          </div>

          <!-- Bottom Footer Bar -->
          <div class="ops-frame-bottom">
            <div class="ops-frame-elbow-bottom"></div>
            <div class="ops-frame-footer"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Contact Detail Modal -->
    <div v-if="selectedContact" class="ops-modal-overlay" @click.self="selectedContact = null">
      <div class="ops-modal featured">
        <div class="ops-modal-header">
          <div class="ops-modal-elbow"></div>
          <div class="ops-modal-title">Contact Details</div>
          <button @click="selectedContact = null" class="ops-modal-close">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="ops-modal-body">
          <div class="ops-modal-profile">
            <div class="ops-modal-avatar orange">
              {{ getInitials(selectedContact.displayName) }}
            </div>
            <div class="ops-modal-name-block">
              <h3 class="ops-modal-name">{{ selectedContact.displayName }}</h3>
              <p v-if="selectedContact.organization" class="ops-modal-org">{{ selectedContact.organization }}</p>
            </div>
          </div>

          <div class="ops-modal-actions">
            <a v-if="selectedContact.phone" :href="'tel:' + selectedContact.phone" class="ops-modal-action-btn green">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
              </svg>
              <span>{{ selectedContact.phone }}</span>
            </a>
            <a v-if="selectedContact.email" :href="'mailto:' + selectedContact.email" class="ops-modal-action-btn blue">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
              </svg>
              <span>{{ selectedContact.email }}</span>
            </a>
            <div v-if="selectedContact.address" class="ops-modal-action-btn peach">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
              </svg>
              <span>{{ selectedContact.address }}</span>
            </div>
          </div>
        </div>
        <div class="ops-modal-footer"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../utils/api';
import ShipmentTrackerWidget from '../components/ShipmentTrackerWidget.vue';

const loading = ref(false);
const initialLoading = ref(true);
const contactSearch = ref('');
const selectedContact = ref(null);

// Avatar color rotation
const avatarColors = ['orange', 'peach', 'lilac', 'blue', 'green', 'gold', 'sky', 'violet'];

// Data
const todaysEvents = ref([]);
const upcomingEvents = ref([]);
const contacts = ref([]);
const workflowStats = ref({ completedToday: 0 });

const greeting = computed(() => {
  const hour = new Date().getHours();
  if (hour < 12) return 'Morning';
  if (hour < 17) return 'Afternoon';
  return 'Evening';
});

const currentDate = computed(() => {
  const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
  return new Date().toLocaleDateString('en-US', options);
});

const todayFormatted = computed(() => {
  return new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
});

const contactsCount = computed(() => contacts.value.length);

const filteredContacts = computed(() => {
  if (!contactSearch.value) return contacts.value;
  const query = contactSearch.value.toLowerCase();
  return contacts.value.filter(c =>
    (c.displayName?.toLowerCase().includes(query)) ||
    (c.phone?.toLowerCase().includes(query)) ||
    (c.email?.toLowerCase().includes(query))
  );
});

const fetchTodayData = async () => {
  try {
    loading.value = true;

    const [dashResponse, calendarResponse, contactsResponse] = await Promise.all([
      api.get('/dashboard/stats').catch(() => ({ success: false })),
      api.get('/calendar/events/all', {
        params: { start: getWeekStart(), end: getMonthEnd() }
      }).catch(() => ({ success: false })),
      api.get('/contacts/all').catch(() => ({ success: false }))
    ]);

    if (dashResponse.success) {
      workflowStats.value.completedToday = dashResponse.data.stats.recent_runs_24h || 0;
    }

    if (calendarResponse.success && calendarResponse.data.events) {
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const parseEventDate = (dateStr) => {
        if (!dateStr) return new Date();
        if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
          const [year, month, day] = dateStr.split('-').map(Number);
          return new Date(year, month - 1, day);
        }
        return new Date(dateStr);
      };

      const allEvents = calendarResponse.data.events.map(event => ({
        id: event.id,
        title: event.title,
        start: parseEventDate(event.start),
        time: event.allDay ? 'All Day' : formatEventTime(event.start),
        allDay: event.allDay,
        location: event.extendedProps?.location,
        color: event.backgroundColor
      }));

      const getLocalDateString = (date) => {
        const d = new Date(date);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      };

      const todayStr = getLocalDateString(today);

      todaysEvents.value = allEvents
        .filter(e => getLocalDateString(e.start) === todayStr)
        .sort((a, b) => a.start - b.start);

      upcomingEvents.value = allEvents
        .filter(e => {
          const eventDateStr = getLocalDateString(e.start);
          return eventDateStr > todayStr;
        })
        .sort((a, b) => a.start - b.start)
        .slice(0, 7);
    }

    if (contactsResponse.success && contactsResponse.data.contacts) {
      contacts.value = contactsResponse.data.contacts;
    }

  } catch (err) {
    console.error('Error fetching dashboard data:', err);
  } finally {
    loading.value = false;
    initialLoading.value = false;
  }
};

const getWeekStart = () => {
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  return now.toISOString();
};

const getMonthEnd = () => {
  const now = new Date();
  now.setDate(now.getDate() + 30);
  now.setHours(23, 59, 59, 999);
  return now.toISOString();
};

const formatEventTime = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
};

const getDayOfMonth = (date) => {
  return new Date(date).getDate();
};

const getMonthAbbr = (date) => {
  return new Date(date).toLocaleDateString('en-US', { month: 'short' }).toUpperCase();
};

const getWeekday = (date) => {
  return new Date(date).toLocaleDateString('en-US', { weekday: 'long' });
};

const getInitials = (name) => {
  if (!name) return '?';
  const parts = name.split(' ');
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  return name.substring(0, 2).toUpperCase();
};

const showContactDetail = (contact) => {
  selectedContact.value = contact;
};

const hasContactsForLetter = (letter) => {
  return contacts.value.some(c =>
    c.displayName?.toUpperCase().startsWith(letter)
  );
};

const jumpToLetter = (letter) => {
  contactSearch.value = letter;
};

const refreshAll = async () => {
  await fetchTodayData();
};

onMounted(async () => {
  await fetchTodayData();
});
</script>

<style scoped>
.ops-dashboard {
  min-height: calc(100vh - 80px);
  padding: 1rem 1.25rem;
  background-color: var(--ops-black);
}

/* Dashboard Header */
.ops-dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  gap: 1rem;
}

.ops-header-left {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.ops-greeting {
  font-family: 'Antonio', sans-serif;
  font-size: 1.75rem;
  font-weight: 500;
  color: var(--ops-peach);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin: 0;
  line-height: 1.2;
}

.ops-date {
  font-family: 'Antonio', sans-serif;
  font-size: 0.85rem;
  color: var(--ops-lilac);
  letter-spacing: 0.05em;
  margin: 0;
}

.ops-header-stats {
  display: flex;
  gap: 0.75rem;
}

.ops-stat-pill {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 1000px;
  font-family: 'Antonio', sans-serif;
}

.ops-stat-pill.green {
  background-color: var(--ops-green);
  color: var(--ops-black);
}

.ops-stat-pill.blue {
  background-color: var(--ops-sky);
  color: var(--ops-black);
}

.ops-stat-pill .ops-stat-value {
  font-size: 1.25rem;
  font-weight: 700;
}

.ops-stat-pill .ops-stat-label {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ops-black);
}

/* Dashboard Grid */
.ops-dashboard-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;
}

.ops-dashboard-col {
  display: flex;
  flex-direction: column;
}

/* ========================================
   Ops Console FRAMED PANEL - Full Border Design
   Authentic operations console panel with
   top bar, left sidebar, and bottom bar
   ======================================== */

.ops-panel-framed {
  display: flex;
  flex-direction: column;
  min-height: 520px;
  position: relative;
}

/* Top Section: Elbow + Header Bar + Cap */
.ops-frame-top {
  display: flex;
  align-items: stretch;
  height: 48px;
}

.ops-frame-elbow-top {
  width: 20px;
  height: 48px;
  flex-shrink: 0;
  position: relative;
}

.ops-panel-framed.blue .ops-frame-elbow-top { background-color: var(--ops-sky); }
.ops-panel-framed.lilac .ops-frame-elbow-top { background-color: var(--ops-lilac); }

.ops-frame-header {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0 1rem;
  border-radius: 0 0 0 24px;
}

.ops-panel-framed.blue .ops-frame-header {
  background: linear-gradient(to right, var(--ops-sky) 0%, var(--ops-blue) 100%);
}

.ops-panel-framed.lilac .ops-frame-header {
  background: linear-gradient(to right, var(--ops-lilac) 0%, var(--ops-lavender) 100%);
}

.ops-frame-title-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--ops-black);
}

.ops-frame-title {
  font-family: 'Antonio', sans-serif;
  font-size: 1.1rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

.ops-frame-decorators {
  display: flex;
  gap: 0.5rem;
  margin-left: auto;
}

.ops-frame-pill {
  padding: 0.25rem 0.75rem;
  background-color: rgba(0, 0, 0, 0.2);
  border-radius: 1000px;
  font-family: 'Antonio', sans-serif;
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  color: var(--ops-white);
}

.ops-frame-action {
  padding: 0.375rem 0.75rem;
  background-color: var(--ops-black);
  color: var(--ops-peach);
  border-radius: 1000px;
  font-family: 'Antonio', sans-serif;
  font-size: 0.7rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  text-decoration: none;
  transition: all 0.15s ease;
  white-space: nowrap;
}

.ops-frame-action:hover {
  background-color: var(--ops-plum);
  color: var(--ops-orange);
}

/* Middle Section: Sidebar + Content */
.ops-frame-middle {
  flex: 1;
  display: flex;
  min-height: 0;
}

.ops-frame-sidebar {
  width: 20px;
  flex-shrink: 0;
  position: relative;
}

.ops-panel-framed.blue .ops-frame-sidebar { background-color: var(--ops-sky); }
.ops-panel-framed.lilac .ops-frame-sidebar { background-color: var(--ops-lilac); }

.ops-frame-content {
  flex: 1;
  padding: 1rem 1.25rem;
  overflow-y: auto;
  background-color: var(--ops-black);
}

/* Bottom Section: Elbow + Footer Bar + Cap */
.ops-frame-bottom {
  display: flex;
  align-items: stretch;
  height: 28px;
}

.ops-frame-elbow-bottom {
  width: 20px;
  height: 28px;
  flex-shrink: 0;
  position: relative;
}

.ops-panel-framed.blue .ops-frame-elbow-bottom { background-color: var(--ops-sky); }
.ops-panel-framed.lilac .ops-frame-elbow-bottom { background-color: var(--ops-lilac); }

.ops-frame-footer {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 8px;
  border-radius: 24px 0 0 0;
}

.ops-panel-framed.blue .ops-frame-footer { background-color: var(--ops-sky); }
.ops-panel-framed.lilac .ops-frame-footer { background-color: var(--ops-lilac); }

/* Subsection Styling */
.ops-subsection {
  margin-bottom: 1.5rem;
}

.ops-subsection-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
  padding-bottom: 0.5rem;
  border-bottom: 2px solid var(--ops-plum);
}

.ops-subsection-label {
  font-family: 'Antonio', sans-serif;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--ops-violet);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

.ops-subsection-date {
  font-family: 'Antonio', sans-serif;
  font-size: 0.7rem;
  color: var(--ops-lilac);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.ops-subsection-indicator {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: var(--ops-green);
  animation: pulse-glow 2s ease-in-out infinite;
}

@keyframes pulse-glow {
  0%, 100% { opacity: 1; box-shadow: 0 0 4px var(--ops-green); }
  50% { opacity: 0.6; box-shadow: 0 0 8px var(--ops-green); }
}

/* Empty State */
.ops-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  text-align: center;
}

.ops-empty-icon {
  color: var(--ops-plum);
  margin-bottom: 1rem;
}

.ops-empty-text {
  font-family: 'Antonio', sans-serif;
  font-size: 1rem;
  color: var(--ops-violet);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin: 0;
}

.ops-empty-subtext {
  font-size: 0.8rem;
  color: var(--ops-plum);
  margin: 0.25rem 0 0;
}

/* Event List */
.ops-event-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ops-event-item {
  display: flex;
  align-items: stretch;
  background-color: var(--ops-plum);
  border-radius: 0 12px 12px 0;
  overflow: hidden;
  transition: all 0.15s ease;
}

.ops-event-item:hover {
  background-color: rgba(var(--ops-plum-rgb), 0.8);
  transform: translateX(4px);
}

.ops-event-time-block {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 70px;
  padding: 0.5rem;
  color: var(--ops-black);
  font-family: 'Antonio', sans-serif;
  text-align: center;
}

.ops-event-allday {
  font-size: 0.6rem;
  font-weight: 600;
  letter-spacing: 0.05em;
}

.ops-event-time {
  font-size: 0.8rem;
  font-weight: 600;
}

.ops-event-details {
  flex: 1;
  padding: 0.5rem 0.75rem;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.ops-event-title {
  font-size: 0.9rem;
  font-weight: 500;
  color: var(--ops-peach);
  margin: 0;
}

.ops-event-location {
  font-size: 0.75rem;
  color: var(--ops-lilac);
  margin: 0.125rem 0 0;
}

/* Upcoming List */
.ops-upcoming-list {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.ops-upcoming-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem;
  background-color: rgba(var(--ops-plum-rgb), 0.3);
  border-radius: 0 8px 8px 0;
  transition: all 0.15s ease;
}

.ops-upcoming-item:hover {
  background-color: var(--ops-plum);
}

.ops-upcoming-date {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 40px;
  padding: 0.25rem;
  background-color: var(--ops-violet);
  border-radius: 6px;
  color: var(--ops-white);
}

.ops-upcoming-day {
  font-family: 'Antonio', sans-serif;
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1;
}

.ops-upcoming-month {
  font-family: 'Antonio', sans-serif;
  font-size: 0.55rem;
  font-weight: 600;
  letter-spacing: 0.05em;
}

.ops-upcoming-details {
  flex: 1;
}

.ops-upcoming-title {
  font-size: 0.85rem;
  color: var(--ops-peach);
  margin: 0;
}

.ops-upcoming-weekday {
  font-size: 0.7rem;
  color: var(--ops-violet);
  margin: 0;
}

.ops-upcoming-indicator {
  width: 6px;
  height: 24px;
  border-radius: 3px;
}

/* Search Section */
.ops-search-section {
  margin-bottom: 1rem;
}

.ops-search-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background-color: var(--ops-plum);
  border-radius: 1000px;
  padding: 0.5rem 1rem;
}

.ops-search-icon {
  width: 1.25rem;
  height: 1.25rem;
  color: var(--ops-violet);
  flex-shrink: 0;
}

.ops-search-input {
  flex: 1;
  background: transparent;
  border: none;
  outline: none;
  color: var(--ops-peach);
  font-family: 'Antonio', sans-serif;
  font-size: 0.9rem;
}

.ops-search-input::placeholder {
  color: var(--ops-violet);
}

.ops-search-count {
  font-family: 'Antonio', sans-serif;
  font-size: 0.7rem;
  color: var(--ops-lilac);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

/* Contacts Grid */
.ops-contacts-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.5rem;
  max-height: 340px;
  overflow-y: auto;
  padding-right: 0.5rem;
}

.ops-contact-card {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem;
  background-color: var(--ops-plum);
  border-radius: 0 10px 10px 0;
  cursor: pointer;
  transition: all 0.15s ease;
  position: relative;
}

.ops-contact-card:hover {
  background-color: rgba(var(--ops-plum-rgb), 0.8);
  transform: translateX(4px);
}

.ops-contact-avatar {
  width: 36px;
  height: 36px;
  border-radius: 0 8px 8px 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Antonio', sans-serif;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--ops-black);
  flex-shrink: 0;
}

.ops-contact-avatar.orange { background-color: var(--ops-orange); }
.ops-contact-avatar.peach { background-color: var(--ops-peach); }
.ops-contact-avatar.lilac { background-color: var(--ops-lilac); }
.ops-contact-avatar.blue { background-color: var(--ops-sky); }
.ops-contact-avatar.green { background-color: var(--ops-green); }
.ops-contact-avatar.gold { background-color: var(--ops-gold); }
.ops-contact-avatar.sky { background-color: var(--ops-ice); }
.ops-contact-avatar.violet { background-color: var(--ops-violet); color: var(--ops-white); }

.ops-contact-info {
  flex: 1;
  min-width: 0;
}

.ops-contact-name {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--ops-peach);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ops-contact-detail {
  font-size: 0.65rem;
  color: var(--ops-violet);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ops-contact-actions {
  display: flex;
  gap: 0.25rem;
  opacity: 0;
  transition: opacity 0.15s ease;
}

.ops-contact-card:hover .ops-contact-actions {
  opacity: 1;
}

.ops-contact-action {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ops-black);
  transition: all 0.15s ease;
}

.ops-contact-action.green { background-color: var(--ops-green); }
.ops-contact-action.blue { background-color: var(--ops-sky); }

.ops-contact-action:hover {
  transform: scale(1.1);
}

/* Alphabet Navigation */
.ops-alpha-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  margin-top: 1rem;
  padding-top: 0.75rem;
  border-top: 2px solid var(--ops-plum);
}

.ops-alpha-btn {
  width: 22px;
  height: 22px;
  border: none;
  background-color: var(--ops-plum);
  color: var(--ops-violet);
  font-family: 'Antonio', sans-serif;
  font-size: 0.65rem;
  font-weight: 600;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.ops-alpha-btn.active {
  background-color: var(--ops-lilac);
  color: var(--ops-black);
}

.ops-alpha-btn:hover {
  background-color: var(--ops-violet);
  color: var(--ops-white);
}

/* Modal Styling */
.ops-modal-overlay {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.ops-modal.featured {
  background-color: var(--ops-black);
  border-radius: 0 var(--ops-border-radius) var(--ops-border-radius) 0;
  max-width: 420px;
  width: 90%;
  overflow: hidden;
}

.ops-modal-header {
  display: flex;
  align-items: center;
  background: linear-gradient(to right, var(--ops-magenta) 0%, var(--ops-violet) 100%);
  min-height: 50px;
}

.ops-modal-elbow {
  width: 50px;
  height: 50px;
  background-color: var(--ops-magenta);
  border-radius: 0 0 25px 0;
  flex-shrink: 0;
}

.ops-modal-title {
  flex: 1;
  font-family: 'Antonio', sans-serif;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  padding: 0 1rem;
}

.ops-modal-close {
  width: 40px;
  height: 40px;
  background-color: var(--ops-black);
  border: none;
  border-radius: 50%;
  color: var(--ops-peach);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 0.5rem;
  cursor: pointer;
  transition: all 0.15s ease;
}

.ops-modal-close:hover {
  background-color: var(--ops-plum);
  color: var(--ops-orange);
}

.ops-modal-body {
  padding: 1.5rem;
}

.ops-modal-profile {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.ops-modal-avatar {
  width: 64px;
  height: 64px;
  border-radius: 0 16px 16px 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Antonio', sans-serif;
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--ops-black);
  flex-shrink: 0;
}

.ops-modal-avatar.orange { background-color: var(--ops-orange); }

.ops-modal-name-block {
  flex: 1;
}

.ops-modal-name {
  font-family: 'Antonio', sans-serif;
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--ops-peach);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 0;
}

.ops-modal-org {
  font-size: 0.85rem;
  color: var(--ops-lilac);
  margin: 0.25rem 0 0;
}

.ops-modal-actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ops-modal-action-btn {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border-radius: 0 12px 12px 0;
  text-decoration: none;
  transition: all 0.15s ease;
  color: var(--ops-black);
}

.ops-modal-action-btn.green { background-color: var(--ops-green); }
.ops-modal-action-btn.blue { background-color: var(--ops-sky); }
.ops-modal-action-btn.peach { background-color: var(--ops-peach); }

.ops-modal-action-btn:hover {
  filter: brightness(1.1);
  transform: translateX(4px);
}

.ops-modal-action-btn span {
  font-family: 'Antonio', sans-serif;
  font-size: 0.9rem;
  font-weight: 500;
}

.ops-modal-footer {
  height: 12px;
  background: linear-gradient(to right, var(--ops-magenta) 0%, var(--ops-violet) 100%);
  margin-left: 50px;
}

/* Scrollbar */
.ops-scroll::-webkit-scrollbar {
  width: 6px;
}

.ops-scroll::-webkit-scrollbar-track {
  background: var(--ops-black);
}

.ops-scroll::-webkit-scrollbar-thumb {
  background: var(--ops-plum);
  border-radius: 3px;
}

.ops-scroll::-webkit-scrollbar-thumb:hover {
  background: var(--ops-violet);
}

/* Responsive */
@media (max-width: 1200px) {
  .ops-dashboard-grid {
    grid-template-columns: 1fr;
    gap: 1rem;
  }

  .ops-contacts-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .ops-dashboard {
    padding: 1rem;
  }

  .ops-dashboard-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }

  .ops-header-stats {
    width: 100%;
    justify-content: flex-start;
  }

  .ops-greeting {
    font-size: 1.5rem;
  }

  .ops-panel-body {
    padding: 1rem 1rem 1rem 2rem;
  }

  .ops-contacts-grid {
    grid-template-columns: 1fr;
  }

  .ops-alpha-nav {
    justify-content: center;
  }
}
</style>

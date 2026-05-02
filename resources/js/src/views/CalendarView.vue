<template>
  <div class="min-h-screen bg-[#1a1a1a]">
    <div class="max-w-7xl mx-auto px-4 py-6">
      <!-- Header -->
      <div class="flex justify-between items-center mb-6">
        <div>
          <h2 class="text-3xl font-bold text-[#e0e0e0] border-b-2 border-accent pb-2">Calendar</h2>
          <p class="text-[#95a5a6] mt-1">Nextcloud CalDAV Integration</p>
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

          <!-- Calendar Selector -->
          <select
            v-model="selectedCalendars"
            multiple
            class="px-4 py-2 bg-[#2d2d2d] border border-[#444] rounded-lg text-[#e0e0e0] focus:outline-none focus:ring-2 focus:ring-accent"
          >
            <option v-for="cal in calendars" :key="cal.id" :value="cal.id">
              {{ cal.displayName }}
            </option>
          </select>

          <!-- Refresh Button with Force Option -->
          <div class="relative">
            <button @click="refreshEvents(false)" class="btn-primary flex items-center gap-2" :disabled="loading">
              <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
              </svg>
              Refresh
            </button>
          </div>

          <!-- Force Refresh Button -->
          <button
            @click="refreshEvents(true)"
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

      <!-- Loading State -->
      <div v-if="initialLoading" class="card text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-accent"></div>
        <p class="mt-4 text-[#95a5a6]">Loading calendars...</p>
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

      <!-- Calendar -->
      <div v-else class="card">
        <FullCalendar
          ref="fullCalendar"
          :options="calendarOptions"
          class="fc-dark-theme"
        />
      </div>

      <!-- Event Detail Modal -->
      <div v-if="selectedEvent" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="selectedEvent = null">
        <div class="bg-[#2d2d2d] rounded-lg shadow-xl max-w-md w-full mx-4 border border-[#444]">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-semibold text-[#e0e0e0]">{{ selectedEvent.title }}</h3>
              <button @click="selectedEvent = null" class="text-[#95a5a6] hover:text-[#e0e0e0]">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div class="space-y-3 text-[#95a5a6]">
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 mt-0.5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <div>
                  <p class="text-[#e0e0e0]">{{ formatEventDate(selectedEvent) }}</p>
                  <p v-if="!selectedEvent.allDay" class="text-sm">{{ formatEventTime(selectedEvent) }}</p>
                </div>
              </div>

              <div v-if="selectedEvent.extendedProps?.location" class="flex items-start gap-3">
                <svg class="w-5 h-5 mt-0.5 text-[#27ae60]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <p>{{ selectedEvent.extendedProps.location }}</p>
              </div>

              <div v-if="selectedEvent.extendedProps?.description" class="flex items-start gap-3">
                <svg class="w-5 h-5 mt-0.5 text-[#9b59b6]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/>
                </svg>
                <p>{{ selectedEvent.extendedProps.description }}</p>
              </div>

              <div v-if="selectedEvent.extendedProps?.calendar" class="flex items-start gap-3">
                <svg class="w-5 h-5 mt-0.5 text-[#f39c12]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                <p>{{ selectedEvent.extendedProps.calendar }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import api from '../utils/api';

const fullCalendar = ref(null);
const loading = ref(false);
const initialLoading = ref(true);
const error = ref(null);
const calendars = ref([]);
const selectedCalendars = ref([]);
const events = ref([]);
const selectedEvent = ref(null);
const cacheInfo = ref(null);

// Handle event click
const handleEventClick = (info) => {
  selectedEvent.value = {
    title: info.event.title,
    start: info.event.start,
    end: info.event.end,
    allDay: info.event.allDay,
    extendedProps: info.event.extendedProps
  };
};

// Fetch events for date range
const fetchEvents = async (start, end, forceRefresh = false) => {
  if (!start || !end) return;

  loading.value = true;
  try {
    const params = { start, end };
    if (forceRefresh) {
      params.force = true;
    }

    const response = await api.get('/calendar/events/all', { params });

    if (response.success) {
      events.value = response.data.events;

      // Update cache info
      if (response.cache) {
        cacheInfo.value = response.cache;
      }

      // Update calendars list if returned
      if (response.data.calendars) {
        calendars.value = response.data.calendars;
      }

      // Update FullCalendar events via API
      if (fullCalendar.value) {
        const calendarApi = fullCalendar.value.getApi();
        calendarApi.removeAllEvents();
        response.data.events.forEach(event => {
          calendarApi.addEvent(event);
        });
      }
    }
  } catch (err) {
    console.error('Failed to fetch events:', err);
    error.value = 'Failed to load calendar events.';
  } finally {
    loading.value = false;
  }
};

// Handle calendar date range change
const handleDatesSet = (dateInfo) => {
  const start = dateInfo.startStr;
  const end = dateInfo.endStr;
  fetchEvents(start, end);
};

// Calendar options for FullCalendar
const calendarOptions = ref({
  plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
  initialView: 'dayGridMonth',
  timeZone: 'local', // Display events in browser's local timezone
  headerToolbar: {
    left: 'prev,next today',
    center: 'title',
    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
  },
  buttonText: {
    today: 'Today',
    month: 'Month',
    week: 'Week',
    day: 'Day',
    list: 'List'
  },
  events: [],
  editable: false,
  selectable: true,
  selectMirror: true,
  dayMaxEvents: true,
  weekends: true,
  nowIndicator: true,
  height: 'auto',
  eventClick: handleEventClick,
  datesSet: handleDatesSet,
  // Dark theme styling
  themeSystem: 'standard',
  eventDisplay: 'block',
  eventTimeFormat: {
    hour: 'numeric',
    minute: '2-digit',
    meridiem: 'short'
  }
});

// Fetch available calendars
const fetchCalendars = async () => {
  try {
    const response = await api.get('/calendar/calendars');
    if (response.success) {
      calendars.value = response.data.calendars;
      // Select all calendars by default
      selectedCalendars.value = calendars.value.map(c => c.id);
    }
  } catch (err) {
    console.error('Failed to fetch calendars:', err);
    error.value = 'Failed to load calendars. Check Nextcloud connection.';
  } finally {
    // Allow calendar to render - events will load via datesSet
    initialLoading.value = false;
  }
};

// Format event date for display
const formatEventDate = (event) => {
  if (!event.start) return '';

  const start = new Date(event.start);
  const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };

  if (event.allDay) {
    return start.toLocaleDateString('en-US', options);
  }

  if (event.end) {
    const end = new Date(event.end);
    if (start.toDateString() === end.toDateString()) {
      return start.toLocaleDateString('en-US', options);
    } else {
      return `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${end.toLocaleDateString('en-US', options)}`;
    }
  }

  return start.toLocaleDateString('en-US', options);
};

// Format event time for display
const formatEventTime = (event) => {
  if (!event.start || event.allDay) return '';

  const start = new Date(event.start);
  const timeOptions = { hour: 'numeric', minute: '2-digit', hour12: true };

  if (event.end) {
    const end = new Date(event.end);
    return `${start.toLocaleTimeString('en-US', timeOptions)} - ${end.toLocaleTimeString('en-US', timeOptions)}`;
  }

  return start.toLocaleTimeString('en-US', timeOptions);
};

// Refresh events
const refreshEvents = (forceRefresh = false) => {
  if (fullCalendar.value) {
    const calendarApi = fullCalendar.value.getApi();
    const view = calendarApi.view;
    fetchEvents(view.activeStart.toISOString(), view.activeEnd.toISOString(), forceRefresh);
  }
};

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

// Initialize
onMounted(async () => {
  await fetchCalendars();
  // Events will be fetched when datesSet fires
});

// Watch for calendar selection changes
watch(selectedCalendars, () => {
  refreshEvents();
});
</script>

<style>
/* FullCalendar Dark Theme Overrides */
.fc-dark-theme {
  --fc-border-color: #444;
  --fc-button-bg-color: #3498db;
  --fc-button-border-color: #3498db;
  --fc-button-hover-bg-color: #2980b9;
  --fc-button-hover-border-color: #2980b9;
  --fc-button-active-bg-color: #2471a3;
  --fc-button-active-border-color: #2471a3;
  --fc-event-bg-color: #3498db;
  --fc-event-border-color: #3498db;
  --fc-today-bg-color: rgba(52, 152, 219, 0.1);
  --fc-page-bg-color: #2d2d2d;
  --fc-neutral-bg-color: #1a1a1a;
  --fc-list-event-hover-bg-color: #34495e;
}

.fc-dark-theme .fc {
  background-color: #2d2d2d;
  border-radius: 0.5rem;
}

.fc-dark-theme .fc-toolbar-title {
  color: #e0e0e0 !important;
  font-size: 1.5rem !important;
}

.fc-dark-theme .fc-col-header-cell-cushion,
.fc-dark-theme .fc-daygrid-day-number,
.fc-dark-theme .fc-list-day-text,
.fc-dark-theme .fc-list-day-side-text {
  color: #e0e0e0 !important;
}

.fc-dark-theme .fc-daygrid-day {
  background-color: #2d2d2d;
}

.fc-dark-theme .fc-daygrid-day:hover {
  background-color: #34495e;
}

.fc-dark-theme .fc-day-today {
  background-color: rgba(52, 152, 219, 0.15) !important;
}

.fc-dark-theme .fc-daygrid-day-frame {
  min-height: 100px;
}

.fc-dark-theme .fc-event {
  border-radius: 4px;
  padding: 2px 4px;
  font-size: 0.85rem;
}

.fc-dark-theme .fc-event-title {
  font-weight: 500;
}

.fc-dark-theme .fc-list-event {
  background-color: #2d2d2d;
}

.fc-dark-theme .fc-list-event:hover td {
  background-color: #34495e;
}

.fc-dark-theme .fc-list-event-title a {
  color: #e0e0e0 !important;
}

.fc-dark-theme .fc-list-event-time {
  color: #95a5a6 !important;
}

.fc-dark-theme .fc-timegrid-slot {
  height: 40px;
}

.fc-dark-theme .fc-timegrid-slot-label {
  color: #95a5a6;
}

.fc-dark-theme .fc-scrollgrid {
  border-color: #444;
}

.fc-dark-theme .fc-scrollgrid td,
.fc-dark-theme .fc-scrollgrid th {
  border-color: #444;
}

.fc-dark-theme .fc-button {
  text-transform: capitalize;
  font-weight: 500;
}

.fc-dark-theme .fc-button-primary:disabled {
  background-color: #34495e;
  border-color: #34495e;
}

.fc-dark-theme .fc-day-other .fc-daygrid-day-number {
  color: #666 !important;
}

.fc-dark-theme .fc-popover {
  background-color: #2d2d2d;
  border-color: #444;
}

.fc-dark-theme .fc-popover-header {
  background-color: #1a1a1a;
  color: #e0e0e0;
}

.fc-dark-theme .fc-more-link {
  color: #3498db;
}

.fc-dark-theme .fc-now-indicator {
  border-color: #e74c3c;
}
</style>

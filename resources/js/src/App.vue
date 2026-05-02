<template>
  <div id="app" class="ops-app-container">
    <!-- Login page - no Ops console frame -->
    <template v-if="!showNav">
      <router-view />
    </template>

    <!-- Authenticated pages - full Ops console frame -->
    <template v-else>
      <!-- Ops console Top Header Bar -->
      <NavigationMenu />

      <!-- Ops console Main Frame Structure -->
      <div class="ops-main-structure">
        <!-- Left Sidebar Column -->
        <div class="ops-left-column">
          <!-- Vertical continuation from header elbow -->
          <div class="ops-left-bar"></div>

          <!-- Sidebar decorative blocks -->
          <div class="ops-sidebar-blocks">
            <div class="ops-sidebar-block ops-sidebar-block-1"></div>
            <div class="ops-sidebar-block ops-sidebar-block-2"></div>
            <div class="ops-sidebar-block ops-sidebar-block-3"></div>
            <div class="ops-sidebar-block ops-sidebar-block-4"></div>
            <div class="ops-sidebar-block ops-sidebar-block-5"></div>
            <div class="ops-sidebar-block ops-sidebar-block-spacer"></div>
            <div class="ops-sidebar-block ops-sidebar-block-6"></div>
            <div class="ops-sidebar-block ops-sidebar-block-7"></div>
          </div>

          <!-- Bottom left elbow -->
          <div class="ops-bottom-left-elbow">
            <div class="ops-elbow-cutout"></div>
          </div>
        </div>

        <!-- Content Area -->
        <div class="ops-content-column">
          <div class="ops-content-area ops-scroll">
            <router-view />
          </div>

          <!-- Bottom Footer Bar -->
          <div class="ops-footer-bar">
            <div class="ops-footer-segment ops-footer-segment-1"></div>
            <div class="ops-footer-segment ops-footer-segment-2"></div>
            <div class="ops-footer-spacer"></div>
            <div class="ops-footer-segment ops-footer-segment-3"></div>
            <div class="ops-footer-segment ops-footer-segment-4"></div>
            <div class="ops-footer-cap"></div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth';
import { useThemeStore } from './stores/theme';
import { useTimezone } from './composables/useTimezone';
import api from './utils/api';
import NavigationMenu from './components/layout/NavigationMenu.vue';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const { initializeTimezone } = useTimezone();

const showNav = computed(() => {
  return route.path !== '/login' && authStore.isAuthenticated;
});

// Global Ctrl+K to focus Knowledge Hub search from any page
const handleGlobalKeydown = (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault();
    if (route.path !== '/knowledge') {
      router.push('/knowledge?focus=search');
    }
  }
};

onMounted(() => {
  document.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
  document.removeEventListener('keydown', handleGlobalKeydown);
});

// Initialize theme and timezone on app mount
onMounted(async () => {
  // Initialize theme (defaults to dark mode)
  themeStore.init();

  // Initialize timezone from API
  try {
    const response = await api.get('/dashboard/stats');
    if (response.success && response.data?.config?.timezone) {
      await initializeTimezone(response.data.config.timezone);
    }
  } catch (error) {
    console.error('Failed to initialize timezone:', error);
    // Continue with default UTC timezone
  }
});
</script>

<style>
/* ========================================
   Ops console GLOBAL APP FRAME
   Full-screen operations console interface
   ======================================== */

.ops-app-container {
  min-height: 100vh;
  background-color: var(--ops-black, #000000);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Main structure - sidebar + content */
.ops-main-structure {
  flex: 1;
  display: flex;
  overflow: hidden;
}

/* ========================================
   LEFT SIDEBAR COLUMN
   ======================================== */
.ops-left-column {
  width: 140px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background-color: var(--ops-black, #000000);
}

/* Vertical bar continuation from header */
.ops-left-bar {
  width: 100%;
  height: 8px;
  background-color: var(--ops-magenta, #cc6699);
}

/* Sidebar decorative blocks container */
.ops-sidebar-blocks {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 6px 6px 6px 0;
  background-color: var(--ops-black, #000000);
}

/* Individual sidebar blocks */
.ops-sidebar-block {
  width: 100%;
  border-radius: 0 16px 16px 0;
  flex-shrink: 0;
}

.ops-sidebar-block-1 {
  height: 60px;
  background-color: var(--ops-orange, #ff9900);
}

.ops-sidebar-block-2 {
  height: 40px;
  background-color: var(--ops-peach, #ff9966);
}

.ops-sidebar-block-3 {
  height: 50px;
  background-color: var(--ops-tan, #cc9966);
}

.ops-sidebar-block-4 {
  height: 35px;
  background-color: var(--ops-lilac, #cc99cc);
}

.ops-sidebar-block-5 {
  height: 45px;
  background-color: var(--ops-sky, #99ccff);
}

/* Flexible spacer block */
.ops-sidebar-block-spacer {
  flex: 1;
  min-height: 40px;
  background-color: var(--ops-magenta, #cc6699);
}

.ops-sidebar-block-6 {
  height: 55px;
  background-color: var(--ops-gold, #ffcc99);
}

.ops-sidebar-block-7 {
  height: 40px;
  background-color: var(--ops-violet, #9977aa);
}

/* Bottom left elbow */
.ops-bottom-left-elbow {
  width: 100%;
  height: 60px;
  background-color: var(--ops-tan, #cc9966);
  border-radius: 0 32px 0 0;
  position: relative;
  flex-shrink: 0;
}

.ops-bottom-left-elbow .ops-elbow-cutout {
  position: absolute;
  right: 0;
  top: 0;
  width: 48px;
  height: 32px;
  background-color: var(--ops-black, #000000);
  border-radius: 0 0 0 16px;
}

/* ========================================
   CONTENT COLUMN
   ======================================== */
.ops-content-column {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background-color: var(--ops-black, #000000);
}

/* Main content area - scrollable */
.ops-content-area {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 16px;
  background-color: var(--ops-black, #000000);
}

/* Custom scrollbar for Ops console - thick and clickable */
.ops-scroll::-webkit-scrollbar {
  width: 18px;
}

.ops-scroll::-webkit-scrollbar-track {
  background: var(--ops-black, #000000);
  border-left: 2px solid var(--ops-plum, #774477);
  border-radius: 9px;
}

.ops-scroll::-webkit-scrollbar-thumb {
  background: var(--ops-violet, #9977aa);
  border-radius: 9px;
  border: 3px solid var(--ops-black, #000000);
  min-height: 50px;
}

.ops-scroll::-webkit-scrollbar-thumb:hover {
  background: var(--ops-lilac, #cc99cc);
}

.ops-scroll::-webkit-scrollbar-thumb:active {
  background: var(--ops-lavender, #ddaadd);
}

/* Firefox scrollbar */
.ops-scroll {
  scrollbar-width: auto;
  scrollbar-color: var(--ops-violet, #9977aa) var(--ops-black, #000000);
}

/* ========================================
   FOOTER BAR
   ======================================== */
.ops-footer-bar {
  height: 32px;
  display: flex;
  align-items: stretch;
  gap: 6px;
  padding: 0 6px 6px 0;
  background-color: var(--ops-black, #000000);
  flex-shrink: 0;
}

.ops-footer-segment {
  height: 100%;
  border-radius: 16px 16px 0 0;
}

.ops-footer-segment-1 {
  width: 80px;
  background-color: var(--ops-peach, #ff9966);
}

.ops-footer-segment-2 {
  width: 60px;
  background-color: var(--ops-lilac, #cc99cc);
}

/* Footer spacer */
.ops-footer-spacer {
  flex: 1;
  background-color: var(--ops-tan, #cc9966);
  border-radius: 16px 16px 0 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ops-footer-segment-3 {
  width: 50px;
  background-color: var(--ops-sky, #99ccff);
}

.ops-footer-segment-4 {
  width: 40px;
  background-color: var(--ops-gold, #ffcc99);
}

/* Footer end cap */
.ops-footer-cap {
  width: 24px;
  height: 100%;
  background-color: var(--ops-lilac, #cc99cc);
  border-radius: 16px 0 0 0;
}

/* ========================================
   RESPONSIVE ADJUSTMENTS
   ======================================== */

/* Tablet and smaller */
@media (max-width: 1024px) {
  .ops-left-column {
    width: 100px;
  }

  .ops-sidebar-block-1 { height: 45px; }
  .ops-sidebar-block-2 { height: 30px; }
  .ops-sidebar-block-3 { height: 35px; }
  .ops-sidebar-block-4 { height: 25px; }
  .ops-sidebar-block-5 { height: 30px; }
  .ops-sidebar-block-6 { height: 40px; }
  .ops-sidebar-block-7 { height: 30px; }

  .ops-bottom-left-elbow {
    height: 45px;
  }

  .ops-footer-bar {
    height: 28px;
  }

  .ops-footer-segment-1 { width: 60px; }
  .ops-footer-segment-2 { width: 45px; }
  .ops-footer-segment-3 { width: 35px; }
  .ops-footer-segment-4 { width: 30px; }
}

/* Mobile - hide sidebar, simplified frame */
@media (max-width: 768px) {
  .ops-left-column {
    display: none;
  }

  .ops-content-area {
    padding: 12px;
  }

  .ops-footer-bar {
    height: 24px;
    padding: 0 4px 4px 4px;
    gap: 4px;
  }

  .ops-footer-segment-1 { width: 40px; }
  .ops-footer-segment-2 { width: 30px; }
  .ops-footer-segment-3 { width: 25px; }
  .ops-footer-segment-4 { width: 20px; }
  .ops-footer-cap { width: 16px; }

}

/* Large screens - more comfortable sizing */
@media (min-width: 1600px) {
  .ops-left-column {
    width: 160px;
  }

  .ops-sidebar-block-1 { height: 70px; }
  .ops-sidebar-block-2 { height: 50px; }
  .ops-sidebar-block-3 { height: 60px; }
  .ops-sidebar-block-4 { height: 40px; }
  .ops-sidebar-block-5 { height: 55px; }
  .ops-sidebar-block-6 { height: 65px; }
  .ops-sidebar-block-7 { height: 45px; }

  .ops-bottom-left-elbow {
    height: 70px;
  }

  .ops-footer-bar {
    height: 36px;
  }

  .ops-content-area {
    padding: 20px;
  }
}
</style>

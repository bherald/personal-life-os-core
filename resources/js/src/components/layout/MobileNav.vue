<template>
  <!-- Ops Console Mobile Navigation Overlay -->
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/70 z-40 md:hidden"
        @click="$emit('close')"
      ></div>
    </Transition>
  </Teleport>

  <!-- Ops Console Mobile Navigation Drawer -->
  <Transition name="slide">
    <div
      v-if="isOpen"
      class="fixed top-0 left-0 h-full w-80 z-50 md:hidden overflow-hidden ops-mobile-drawer"
    >
      <!-- Ops Console Header with Elbow -->
      <div class="ops-mobile-header">
        <div class="ops-mobile-elbow"></div>
        <div class="ops-mobile-title">PLOS</div>
        <button @click="$emit('close')" class="ops-btn ops-btn-peach ml-auto">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Ops Console Sidebar Bar -->
      <div class="ops-mobile-sidebar-bar"></div>

      <!-- Navigation Content -->
      <div class="ops-mobile-content ops-scroll">
        <!-- Primary Navigation -->
        <div class="ops-mobile-section">
          <div class="ops-mobile-section-header">Main Systems</div>

          <router-link to="/today" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Today
          </router-link>

          <router-link to="/knowledge" class="ops-mobile-item ops-mobile-featured" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Knowledge Hub
          </router-link>

          <router-link to="/email-queue" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Email
          </router-link>

          <router-link to="/genealogy" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Genealogy
          </router-link>

          <router-link to="/youtube" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            YouTube
          </router-link>

          <router-link to="/daily-ops" class="ops-mobile-item ops-mobile-alert" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Ops
          </router-link>
        </div>

        <!-- System Tools -->
        <div class="ops-mobile-section">
          <div class="ops-mobile-section-header">System Tools</div>

          <router-link to="/workflows" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Workflows
          </router-link>

          <router-link to="/scheduled-jobs" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Scheduled Jobs
          </router-link>

          <router-link to="/system-issues" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            System Issues
          </router-link>

          <router-link to="/operator-evidence" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Operator Evidence
          </router-link>

          <router-link to="/diagnostics" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Diagnostics
          </router-link>

          <router-link to="/dev-tools" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Dev Tools
          </router-link>

          <router-link to="/config" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Configuration
          </router-link>

          <router-link to="/data-removal" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            Privacy
          </router-link>

          <router-link to="/file-catalog" class="ops-mobile-item" @click="$emit('close')">
            <span class="ops-mobile-indicator"></span>
            File Catalog
          </router-link>
        </div>
      </div>

      <!-- Ops Console Footer with Controls -->
      <div class="ops-mobile-footer">
        <div class="ops-mobile-footer-bar"></div>
        <div class="ops-mobile-footer-controls">
          <button @click="toggleTheme" class="ops-btn ops-btn-blue flex-1">
            <svg v-if="themeStore.isDark" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            <svg v-else class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
            </svg>
            {{ themeStore.isDark ? 'Light' : 'Dark' }}
          </button>

          <button @click="handleLogout" class="ops-btn ops-btn-red flex-1">
            Logout
          </button>
        </div>
        <div class="ops-mobile-footer-elbow"></div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useThemeStore } from '../../stores/theme';

defineProps({
  isOpen: {
    type: Boolean,
    default: false
  }
});

defineEmits(['close']);

const router = useRouter();
const authStore = useAuthStore();
const themeStore = useThemeStore();

const toggleTheme = () => {
  themeStore.toggle();
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
/* Ops Console Mobile Drawer */
.ops-mobile-drawer {
  background-color: var(--ops-black);
  display: flex;
  flex-direction: column;
}

/* Ops Console Mobile Header */
.ops-mobile-header {
  display: flex;
  align-items: center;
  padding: 0;
  background-color: var(--ops-black);
  min-height: 70px;
}

.ops-mobile-elbow {
  width: 60px;
  height: 70px;
  background-color: var(--ops-magenta);
  border-radius: 0 0 35px 0;
  flex-shrink: 0;
}

.ops-mobile-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--ops-orange);
  text-transform: uppercase;
  letter-spacing: 0.2em;
  padding: 0 1rem;
}

/* Ops Console Sidebar Bar */
.ops-mobile-sidebar-bar {
  width: 12px;
  background-color: var(--ops-magenta);
  position: absolute;
  left: 0;
  top: 70px;
  bottom: 100px;
}

/* Ops Console Mobile Content */
.ops-mobile-content {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1rem 1rem 2rem;
}

/* Ops Console Mobile Section */
.ops-mobile-section {
  margin-bottom: 1.5rem;
}

.ops-mobile-section-header {
  font-size: 0.625rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.15em;
  color: var(--ops-black);
  background-color: var(--ops-lilac);
  padding: 0.25rem 0.75rem;
  border-radius: 0 10px 10px 0;
  margin-bottom: 0.5rem;
  margin-left: -0.5rem;
}

/* Ops Console Mobile Item */
.ops-mobile-item {
  display: flex;
  align-items: center;
  padding: 0.75rem 1rem;
  color: var(--ops-peach);
  font-size: 0.9375rem;
  font-weight: 500;
  text-decoration: none;
  border-radius: 0 15px 15px 0;
  transition: all 0.15s ease;
  margin-bottom: 0.25rem;
}

.ops-mobile-item:hover,
.ops-mobile-item:active {
  background-color: var(--ops-plum);
  color: var(--ops-orange);
}

.ops-mobile-item.router-link-active {
  background-color: var(--ops-orange);
  color: var(--ops-black);
}

.ops-mobile-indicator {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background-color: var(--ops-plum);
  margin-right: 0.75rem;
  flex-shrink: 0;
  transition: background-color 0.15s ease;
}

.ops-mobile-item:hover .ops-mobile-indicator,
.ops-mobile-item.router-link-active .ops-mobile-indicator {
  background-color: var(--ops-green);
}

.ops-mobile-featured {
  background-color: var(--ops-sky);
  color: var(--ops-black);
}

.ops-mobile-featured:hover {
  background-color: var(--ops-ice);
}

.ops-mobile-alert {
  background-color: var(--ops-sunset);
  color: var(--ops-white);
}

.ops-mobile-alert:hover {
  background-color: var(--ops-orange);
  color: var(--ops-black);
}

.ops-mobile-external {
  color: var(--ops-sky);
}

/* Ops Console Mobile Footer */
.ops-mobile-footer {
  background-color: var(--ops-black);
  padding: 0;
}

.ops-mobile-footer-bar {
  height: 8px;
  background-color: var(--ops-lilac);
  margin-left: 12px;
}

.ops-mobile-footer-controls {
  display: flex;
  gap: 0.5rem;
  padding: 0.75rem 1rem 0.75rem 2rem;
}

.ops-mobile-footer-elbow {
  width: 60px;
  height: 40px;
  background-color: var(--ops-lilac);
  border-radius: 0 35px 0 0;
  margin-left: auto;
}

/* Ops Console Scroll Styling */
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

/* Transitions */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  transform: translateX(-100%);
}
</style>

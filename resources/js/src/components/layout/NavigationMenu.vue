<template>
  <!-- Ops Console Frame Structure - Authentic operations console Design -->
  <div class="ops-header-bar">
    <!-- Mobile Hamburger -->
    <button @click="mobileMenuOpen = true" class="md:hidden ops-btn ops-btn-orange p-2">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
      </svg>
    </button>

    <!-- Ops Console Header Bar - Desktop -->
    <div class="hidden md:flex items-stretch w-full">
      <!-- Left Elbow with inner cutout -->
      <div class="ops-header-elbow"></div>

      <!-- Main Header Content Area -->
      <div class="ops-header-content">
        <!-- Top bar section -->
        <div class="ops-bar-section">
          <!-- Logo/Title - pill hanging from bar -->
          <div class="ops-header-title">PLOS</div>

          <!-- Decorative bar segments -->
          <div class="ops-bar-segment ops-bar-segment-gold"></div>
          <div class="ops-bar-segment ops-bar-segment-tan"></div>

          <!-- Spacer -->
          <div class="ops-bar-spacer"></div>

          <!-- Right decorative segments -->
          <div class="ops-bar-segment ops-bar-segment-lilac"></div>
          <div class="ops-bar-segment ops-bar-segment-sky"></div>
        </div>

        <!-- Navigation buttons hanging below bar -->
        <nav class="ops-nav">
          <router-link to="/today" class="ops-nav-btn ops-nav-btn-peach">
            Today
          </router-link>

          <router-link to="/knowledge" class="ops-nav-btn ops-nav-btn-gold">
            Knowledge
          </router-link>

          <router-link to="/email-queue" class="ops-nav-btn ops-nav-btn-violet">
            Email
          </router-link>

          <router-link to="/genealogy" class="ops-nav-btn ops-nav-btn-green">
            Genealogy
          </router-link>

          <router-link to="/youtube" class="ops-nav-btn ops-nav-btn-sunset">
            YouTube
          </router-link>

          <router-link to="/daily-ops" class="ops-nav-btn ops-nav-btn-sunset">
            Ops
          </router-link>

          <router-link to="/data-removal" class="ops-nav-btn ops-nav-btn-lilac">
            Privacy
          </router-link>
        </nav>

        <!-- Right Controls -->
        <div class="ops-header-controls">
          <button @click="toggleTheme" class="ops-ctrl-btn" :title="themeStore.isDark ? 'Light mode' : 'Dark mode'">
            <svg v-if="themeStore.isDark" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
            </svg>
          </button>

          <button @click="handleLogout" class="ops-ctrl-btn">
            Exit
          </button>
        </div>
      </div>

      <!-- Right Cap - pill end -->
      <div class="ops-header-cap"></div>
    </div>
  </div>

  <!-- Mobile Navigation Drawer -->
  <MobileNav :is-open="mobileMenuOpen" @close="mobileMenuOpen = false" />
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useThemeStore } from '../../stores/theme';
import MobileNav from './MobileNav.vue';

const router = useRouter();
const authStore = useAuthStore();
const themeStore = useThemeStore();

const mobileMenuOpen = ref(false);

const toggleTheme = () => {
  themeStore.toggle();
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
/* ========================================
   AUTHENTIC Ops Console HEADER STRUCTURE
   Based on operations console interface design
   Key elements: Elbow with cutout, pill-shaped bars,
   condensed text, pastel colors on black
   ======================================== */

.ops-header-bar {
  background-color: var(--ops-black);
  padding: 0;
  display: flex;
  align-items: stretch;
  min-height: 56px;
}

/* Ops Console Elbow - The iconic curved corner piece with inner cutout */
.ops-header-elbow {
  width: 140px;
  min-height: 56px;
  background-color: var(--ops-magenta);
  border-radius: 0 0 32px 0;
  flex-shrink: 0;
  position: relative;
}

/* Inner cutout that creates the authentic elbow shape */
.ops-header-elbow::after {
  content: '';
  position: absolute;
  right: 0;
  bottom: 0;
  width: 48px;
  height: 28px;
  background-color: var(--ops-black);
  border-radius: 16px 0 0 0;
}

/* Header content area with horizontal bar styling */
.ops-header-content {
  flex: 1;
  display: flex;
  align-items: stretch;
  gap: 0;
  padding: 0;
  position: relative;
}

/* Top bar background that sits behind nav items */
.ops-header-content::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 28px;
  background-color: var(--ops-magenta);
}

/* Bar section - contains decorative segments */
.ops-bar-section {
  display: flex;
  align-items: stretch;
  height: 28px;
  flex: 1;
}

/* Title block - Ops Console style pill shape integrated into bar */
.ops-header-title {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.12em;
  padding: 0.25rem 1rem;
  background-color: var(--ops-orange);
  display: flex;
  align-items: center;
  white-space: nowrap;
  border-radius: 0 0 14px 0; /* Subtle curve on bottom-right */
}

/* Decorative bar segments - characteristic Ops Console colored blocks */
.ops-bar-segment {
  width: 40px;
  margin-left: 3px;
  border-radius: 0 0 8px 8px;
}

.ops-bar-segment-gold { background-color: var(--ops-gold); }
.ops-bar-segment-tan { background-color: var(--ops-tan); }
.ops-bar-segment-lilac { background-color: var(--ops-lilac); }
.ops-bar-segment-sky { background-color: var(--ops-sky); }
.ops-bar-segment-peach { background-color: var(--ops-peach); }

/* Flexible spacer between decorative segments */
.ops-bar-spacer {
  flex: 1;
  background-color: var(--ops-magenta);
  margin-left: 3px;
}

/* Right cap - Pill-shaped end terminator */
.ops-header-cap {
  width: 28px;
  height: 28px;
  background-color: var(--ops-lilac);
  border-radius: 0 0 0 14px; /* Half-circle on left */
  flex-shrink: 0;
}

/* Controls area - positioned absolutely in bottom-right */
.ops-header-controls {
  position: absolute;
  right: 40px;
  top: 32px;
  display: flex;
  align-items: center;
  gap: 4px;
  z-index: 2;
}

/* Control button style */
.ops-ctrl-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  padding: 0.25rem 0.75rem;
  background-color: var(--ops-sky);
  color: var(--ops-black);
  border: none;
  border-radius: 1000px; /* Full pill */
  font-family: 'Antonio', sans-serif;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  cursor: pointer;
  transition: all 0.15s ease;
  min-height: 24px;
}

.ops-ctrl-btn:hover {
  background-color: var(--ops-orange);
}

/* Ops Console Navigation - positioned below the bar */
.ops-nav {
  position: absolute;
  top: 28px;
  left: 0;
  display: flex;
  align-items: flex-start;
  gap: 3px;
  padding-left: 8px;
  z-index: 1;
}

/* Ops Console Nav Buttons - Authentic pill-shaped hanging from bar */
.ops-nav-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  padding: 0.375rem 0.625rem 0.375rem;
  font-family: 'Antonio', sans-serif;
  font-size: 0.7rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  text-decoration: none;
  border-radius: 0 0 10px 10px; /* Rounded bottom corners */
  transition: all 0.15s ease;
  position: relative;
  min-width: 44px;
  min-height: 32px;
}

/* Indicator light - small dot at bottom */
.ops-nav-btn::after {
  content: '';
  margin-top: 2px;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background-color: transparent;
  transition: all 0.2s ease;
  flex-shrink: 0;
}

/* Color variants - all buttons are solid colored */
.ops-nav-btn-peach {
  background-color: var(--ops-peach);
  color: var(--ops-black);
}

.ops-nav-btn-lilac {
  background-color: var(--ops-lilac);
  color: var(--ops-black);
}

.ops-nav-btn-gold {
  background-color: var(--ops-gold);
  color: var(--ops-black);
}

.ops-nav-btn-sky {
  background-color: var(--ops-sky);
  color: var(--ops-black);
}

.ops-nav-btn-sunset {
  background-color: var(--ops-sunset);
  color: var(--ops-white);
}

.ops-nav-btn-green {
  background-color: var(--ops-green);
  color: var(--ops-black);
}

.ops-nav-btn-ice {
  background-color: var(--ops-ice);
  color: var(--ops-black);
}

.ops-nav-btn-magenta {
  background-color: var(--ops-magenta);
  color: var(--ops-white);
}

.ops-nav-btn-tan {
  background-color: var(--ops-tan);
  color: var(--ops-black);
}

.ops-nav-btn-blue {
  background-color: var(--ops-blue);
  color: var(--ops-white);
}

.ops-nav-btn-lavender {
  background-color: var(--ops-lavender);
  color: var(--ops-black);
}

.ops-nav-btn-violet {
  background-color: var(--ops-violet);
  color: var(--ops-white);
}

.ops-nav-btn-grape {
  background-color: var(--ops-grape);
  color: var(--ops-white);
}

/* Hover effect - brighten */
.ops-nav-btn:hover {
  filter: brightness(1.15);
}

/* Active state - orange highlight with lighted indicator */
.ops-nav-btn.router-link-active {
  background-color: var(--ops-orange);
  color: var(--ops-black);
  box-shadow: 0 4px 12px rgba(255, 153, 0, 0.4);
}

/* Glowing indicator light when active */
.ops-nav-btn.router-link-active::after {
  background-color: var(--ops-green-bright);
  box-shadow:
    0 0 4px var(--ops-green-bright),
    0 0 8px var(--ops-green-bright),
    0 0 12px rgba(102, 255, 102, 0.5);
  animation: ops-indicator-pulse 2s ease-in-out infinite;
}

@keyframes ops-indicator-pulse {
  0%, 100% {
    opacity: 1;
    box-shadow:
      0 0 4px var(--ops-green-bright),
      0 0 8px var(--ops-green-bright),
      0 0 12px rgba(102, 255, 102, 0.5);
  }
  50% {
    opacity: 0.8;
    box-shadow:
      0 0 6px var(--ops-green-bright),
      0 0 12px var(--ops-green-bright),
      0 0 20px rgba(102, 255, 102, 0.7);
  }
}

/* Mobile Adjustments */
@media (max-width: 768px) {
  .ops-header-bar {
    padding: 0.5rem 1rem;
    justify-content: space-between;
    background: linear-gradient(
      to right,
      var(--ops-magenta) 0%,
      var(--ops-magenta) 60px,
      var(--ops-black) 60px
    );
    border-radius: 0 0 20px 0;
  }
}

/* Wide screen - more comfortable spacing */
@media (min-width: 1400px) {
  .ops-nav-btn {
    padding: 0.375rem 0.75rem 0.375rem;
    font-size: 0.75rem;
    min-width: 50px;
  }

  .ops-bar-segment {
    width: 50px;
  }
}

/* Medium screens - tighter layout */
@media (max-width: 1200px) {
  .ops-nav-btn {
    padding: 0.3rem 0.5rem 0.3rem;
    font-size: 0.65rem;
    min-width: 38px;
  }

  .ops-header-elbow {
    width: 100px;
  }

  .ops-bar-segment {
    width: 30px;
  }
}
</style>

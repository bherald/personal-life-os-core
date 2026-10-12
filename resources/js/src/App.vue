<template>
  <div id="app" class="decision-app">
    <template v-if="!showNav">
      <router-view />
    </template>
    <template v-else>
      <a class="decision-skip-link" href="#main-content">Skip to content</a>
      <NavigationMenu />
      <main id="main-content" class="decision-content">
        <router-view />
      </main>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth';
import { useThemeStore } from './stores/theme';
import { useTimezone } from './composables/useTimezone';
import NavigationMenu from './components/layout/NavigationMenu.vue';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const { initializeTimezone } = useTimezone();

const showNav = computed(() => route.path !== '/login' && authStore.isAuthenticated);

const handleGlobalKeydown = (event) => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    if (!showNav.value) return;
    if (route.path === '/reviews') {
      event.preventDefault();
      window.dispatchEvent(new CustomEvent('plos:review-lookup'));
    } else if (route.path !== '/knowledge') {
      event.preventDefault();
      router.push('/knowledge?focus=search');
    }
  }
};

onMounted(() => {
  document.addEventListener('keydown', handleGlobalKeydown);
  themeStore.init();
  initializeTimezone(document.querySelector('meta[name="plos-timezone"]')?.content);
});

onUnmounted(() => document.removeEventListener('keydown', handleGlobalKeydown));
</script>

<style scoped>
.decision-app {
  min-height: 100vh;
  background: var(--desk-canvas);
}
.decision-content {
  width: 100%;
  max-width: 1600px;
  min-width: 0;
  margin: 0 auto;
  padding: 30px 36px 48px;
}
.decision-skip-link {
  position: fixed;
  top: -100px;
  left: 12px;
  z-index: 100;
  padding: 12px 16px;
  color: var(--desk-ink);
  background: var(--desk-paper);
  border-radius: 8px;
}
.decision-skip-link:focus { top: 12px; }
@media (max-width: 760px) {
  .decision-content { padding: 22px 14px 36px; }
}
</style>

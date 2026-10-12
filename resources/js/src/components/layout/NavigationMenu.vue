<template>
  <header class="decision-header">
    <router-link to="/today" class="decision-brand" aria-label="PLOS home">
      <span class="decision-brand-mark" aria-hidden="true">P</span>PLOS
    </router-link>
    <nav class="decision-nav" aria-label="Main navigation">
      <router-link v-for="item in primaryLinks" :key="item.to" :to="item.to" class="decision-nav-link">{{ item.label }}</router-link>
    </nav>
    <div class="decision-actions">
      <button
        type="button"
        class="decision-menu-button"
        aria-label="Open all sections"
        aria-controls="decision-navigation-drawer"
        :aria-expanded="mobileMenuOpen"
        @click="mobileMenuOpen = true"
      ><span class="decision-more-label">More</span><span class="decision-mobile-label">Menu</span></button>
      <button type="button" class="decision-logout" @click="handleLogout">Logout</button>
    </div>
    <MobileNav :is-open="mobileMenuOpen" @close="mobileMenuOpen = false" />
  </header>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import MobileNav from './MobileNav.vue';

const router = useRouter();
const authStore = useAuthStore();
const mobileMenuOpen = ref(false);
const primaryLinks = [
  { to: '/today', label: 'Today' },
  { to: '/reviews', label: 'Reviews' },
  { to: '/knowledge', label: 'Knowledge' },
  { to: '/email-queue', label: 'Email' },
  { to: '/genealogy', label: 'Genealogy' },
  { to: '/youtube', label: 'YouTube' },
  { to: '/daily-ops', label: 'Ops' },
  { to: '/data-removal', label: 'Privacy' },
];

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
.decision-header {
  min-height: 72px;
  display: flex;
  align-items: center;
  gap: clamp(20px, 3vw, 48px);
  padding: 0 clamp(16px, 2.5vw, 36px);
  background: #132e3c;
  color: white;
}
.decision-brand {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  flex: none;
  color: white;
  font-size: 20px;
  font-weight: 800;
  letter-spacing: 2px;
  text-decoration: none;
}
.decision-brand-mark {
  display: grid;
  place-items: center;
  width: 32px;
  height: 34px;
  border-radius: 8px;
  background: #8dd7ba;
  color: #173e3c;
  font-size: 22px;
  letter-spacing: 0;
}
.decision-nav { display: flex; align-items: stretch; align-self: stretch; gap: clamp(8px, 1.5vw, 28px); min-width: 0; }
.decision-nav-link {
  display: flex;
  align-items: center;
  padding: 0 4px;
  border-bottom: 3px solid transparent;
  color: #c6d9df;
  font-size: 14px;
  white-space: nowrap;
  text-decoration: none;
}
.decision-nav-link:hover, .decision-nav-link.router-link-active { color: white; }
.decision-nav-link.router-link-active { border-bottom-color: #8dd7ba; font-weight: 650; }
.decision-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }
.decision-logout, .decision-menu-button {
  min-height: 44px;
  padding: 9px 13px;
  border: 1px solid #55717e;
  border-radius: 8px;
  background: transparent;
  color: white;
  font-weight: 600;
}
.decision-logout:hover, .decision-menu-button:hover { background: #375565; }
.decision-menu-button { display: inline-flex; align-items: center; }
.decision-mobile-label { display: none; }
@media (max-width: 1100px) {
  .decision-nav { display: none; }
  .decision-more-label { display: none; }
  .decision-mobile-label { display: inline; }
}
@media (max-width: 760px) {
  .decision-header { min-height: 62px; }
  .decision-brand { font-size: 17px; gap: 8px; }
  .decision-brand-mark { width: 27px; height: 29px; font-size: 19px; }
  .decision-logout { display: none; }
}
</style>

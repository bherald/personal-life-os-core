<template>
  <Teleport to="body">
    <div v-if="isOpen" class="decision-overlay" @click="$emit('close')"></div>
    <aside
      v-if="isOpen"
      id="decision-navigation-drawer"
      ref="drawer"
      class="decision-drawer"
      role="dialog"
      aria-modal="true"
      aria-label="All sections"
    >
      <div class="decision-drawer-header">
        <strong>PLOS</strong>
        <button ref="closeButton" type="button" aria-label="Close menu" @click="$emit('close')">Close</button>
      </div>
      <nav class="decision-drawer-links" aria-label="All sections">
        <section v-for="group in groups" :key="group.title" class="decision-drawer-group">
          <h2>{{ group.title }}</h2>
          <router-link v-for="item in group.links" :key="item.to" :to="item.to" @click="$emit('close')">{{ item.label }}</router-link>
        </section>
      </nav>
      <button type="button" class="decision-drawer-logout" @click="handleLogout">Logout</button>
    </aside>
  </Teleport>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';

const props = defineProps({ isOpen: { type: Boolean, default: false } });
const emit = defineEmits(['close']);
const router = useRouter();
const authStore = useAuthStore();
const drawer = ref(null);
const closeButton = ref(null);
const groups = [
  { title: 'Workspace', links: [
    { to: '/today', label: 'Today' },
    { to: '/reviews', label: 'Reviews' },
    { to: '/knowledge', label: 'Knowledge Hub' },
    { to: '/calendar', label: 'Calendar' },
    { to: '/contacts', label: 'Contacts' },
    { to: '/email-queue', label: 'Email' },
    { to: '/health', label: 'Health' },
    { to: '/finance', label: 'Finance' },
  ] },
  { title: 'Research & Media', links: [
    { to: '/genealogy', label: 'Genealogy' },
    { to: '/media/faces', label: 'Faces' },
    { to: '/file-catalog', label: 'File Catalog' },
    { to: '/youtube', label: 'YouTube' },
  ] },
  { title: 'Operations', links: [
    { to: '/daily-ops', label: 'Ops' },
    { to: '/workflows', label: 'Workflows' },
    { to: '/scheduled-jobs', label: 'Scheduled Jobs' },
    { to: '/nodes', label: 'Nodes' },
    { to: '/executions', label: 'Executions' },
    { to: '/operator-evidence', label: 'Operator Evidence' },
    { to: '/system-issues', label: 'System Issues' },
  ] },
  { title: 'System', links: [
    { to: '/data-removal', label: 'Privacy' },
    { to: '/diagnostics', label: 'Diagnostics' },
    { to: '/ai-observability', label: 'AI Observability' },
    { to: '/mcp', label: 'MCP Tools' },
    { to: '/dev-tools', label: 'Dev Tools' },
    { to: '/config', label: 'Configuration' },
  ] },
];
let returnFocus = null;
let previousOverflow = '';
const focusable = () => Array.from(drawer.value?.querySelectorAll('a[href], button:not([disabled])') ?? []);
const handleKeydown = (event) => {
  if (!props.isOpen) return;
  if (event.key === 'Escape') {
    event.preventDefault();
    emit('close');
  } else if (event.key === 'Tab') {
    const elements = focusable();
    if (!elements.length) return;
    const first = elements[0];
    const last = elements[elements.length - 1];
    if (event.shiftKey && (document.activeElement === first || !drawer.value?.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !drawer.value?.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  }
};
const handleFocusIn = (event) => {
  if (props.isOpen && !drawer.value?.contains(event.target)) closeButton.value?.focus();
};
watch(() => props.isOpen, (isOpen) => {
  if (isOpen) {
    returnFocus = document.activeElement;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    closeButton.value?.focus();
  } else {
    document.body.style.overflow = previousOverflow;
    returnFocus?.focus();
    returnFocus = null;
  }
}, { flush: 'post' });
onMounted(() => {
  document.addEventListener('keydown', handleKeydown);
  document.addEventListener('focusin', handleFocusIn);
});
onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown);
  document.removeEventListener('focusin', handleFocusIn);
  if (props.isOpen) {
    document.body.style.overflow = previousOverflow;
    returnFocus?.focus();
  }
});
const handleLogout = async () => {
  emit('close');
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
.decision-overlay { position: fixed; inset: 0; z-index: 40; background: #102733b8; }
.decision-drawer {
  position: fixed;
  inset: 0 auto 0 0;
  z-index: 50;
  display: flex;
  flex-direction: column;
  width: min(340px, 90vw);
  background: var(--desk-paper);
  color: var(--desk-ink);
  box-shadow: 0 24px 80px #06192055;
}
.decision-drawer-header { display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid var(--desk-line); }
.decision-drawer-header strong { letter-spacing: 2px; font-size: 20px; }
.decision-drawer-header button, .decision-drawer-logout { min-height: 44px; padding: 9px 13px; border: 1px solid var(--desk-line); border-radius: 8px; background: white; color: var(--desk-ink); font-weight: 600; }
.decision-drawer-links { overflow: auto; padding: 12px; flex: 1; }
.decision-drawer-links a { display: block; padding: 12px 14px; border-radius: 8px; color: var(--desk-ink); font-weight: 600; text-decoration: none; }
.decision-drawer-links a:hover, .decision-drawer-links a.router-link-active { background: var(--desk-teal-soft); color: var(--desk-teal); }
.decision-drawer-group + .decision-drawer-group { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--desk-line); }
.decision-drawer-group h2 { padding: 5px 14px 8px; color: var(--desk-muted); font-size: 12px; font-weight: 750; letter-spacing: 0.06em; text-transform: uppercase; }
.decision-drawer-logout { margin: 12px; }
</style>

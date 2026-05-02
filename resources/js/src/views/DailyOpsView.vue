<template>
  <div class="ops-page">
    <!-- Ops Console Page Header -->
    <div class="ops-page-header">
      <div class="ops-page-header-elbow"></div>
      <div class="ops-page-header-bar">
        <h1 class="ops-page-title">Daily Operations</h1>
        <div class="ops-page-subtitle">System shortcuts &amp; tools</div>
      </div>
    </div>

    <!-- Ops Console Divider Bar -->
    <div class="ops-bar ops-bar-thick ops-bar-magenta mb-6"></div>

    <!-- Ops Console Divider - Automation Section -->
    <div class="ops-divider my-8">
      <div class="ops-divider-line"></div>
      <span class="ops-divider-text">Automation</span>
      <div class="ops-divider-line"></div>
    </div>

    <!-- Automation Links -->
    <div class="ops-quick-links">
      <router-link
        v-for="link in automationLinks"
        :key="link.route"
        :to="link.route"
        class="ops-quick-link"
        :class="link.colorClass"
      >
        <span class="ops-quick-link-icon" v-html="link.icon"></span>
        <span class="ops-quick-link-label">{{ link.label }}</span>
      </router-link>
    </div>

    <!-- Ops Console Divider - System Section -->
    <div class="ops-divider my-8">
      <div class="ops-divider-line"></div>
      <span class="ops-divider-text">System</span>
      <div class="ops-divider-line"></div>
    </div>

    <!-- System Links -->
    <div class="ops-quick-links">
      <router-link
        v-for="link in systemLinks"
        :key="link.route"
        :to="link.route"
        class="ops-quick-link"
        :class="link.colorClass"
      >
        <span class="ops-quick-link-icon" v-html="link.icon"></span>
        <span class="ops-quick-link-label">{{ link.label }}</span>
      </router-link>
      <a
        href="/horizon"
        target="_blank"
        rel="noopener"
        class="ops-quick-link ops-link-external"
      >
        <span class="ops-quick-link-icon">&#128202;</span>
        <span class="ops-quick-link-label">Horizon</span>
      </a>
    </div>

    <!-- Ops Console Footer Bar -->
    <div class="ops-page-footer">
      <div class="ops-bar ops-bar-lilac"></div>
      <div class="ops-page-footer-text">PERSONAL LIFE OS</div>
      <div class="ops-page-footer-elbow"></div>
    </div>
  </div>
</template>

<script setup>
// Automation section links
const automationLinks = [
  { label: 'Workflows', route: '/workflows', icon: '&#9889;', colorClass: 'ops-link-orange' },
  { label: 'Scheduled Jobs', route: '/scheduled-jobs', icon: '&#128337;', colorClass: 'ops-link-peach' },
  { label: 'Nodes', route: '/nodes', icon: '&#128279;', colorClass: 'ops-link-lilac' },
  { label: 'MCP Tools', route: '/mcp', icon: '&#128295;', colorClass: 'ops-link-sky' },
];

// System section links
const systemLinks = [
  { label: 'Knowledge Hub', route: '/knowledge', icon: '&#128218;', colorClass: 'ops-link-gold' },
  { label: 'Operator Evidence', route: '/operator-evidence', icon: '&#128269;', colorClass: 'ops-link-green' },
  { label: 'Configuration', route: '/config', icon: '&#9881;', colorClass: 'ops-link-lilac' },
  { label: 'AI Observability', route: '/ai-observability', icon: '&#129302;', colorClass: 'ops-link-sky' },
  { label: 'Diagnostics', route: '/diagnostics', icon: '&#128200;', colorClass: 'ops-link-sky' },
  { label: 'Executions', route: '/executions', icon: '&#9654;', colorClass: 'ops-link-peach' },
  { label: 'Dev Tools', route: '/dev-tools', icon: '&#128187;', colorClass: 'ops-link-orange' },
  { label: 'System Issues', route: '/system-issues', icon: '&#9888;', colorClass: 'ops-link-orange' },
  { label: 'File Catalog', route: '/file-catalog', icon: '&#128193;', colorClass: 'ops-link-gold' },
];
</script>

<style scoped>
/* Ops Console Page Layout */
.ops-page {
  min-height: 100vh;
  background-color: var(--color-bg-primary);
  padding: 0 1.5rem 2rem;
}

/* Ops Console Page Header */
.ops-page-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.5rem 0;
}

.ops-page-header-elbow {
  width: 80px;
  height: 50px;
  background-color: var(--ops-orange);
  border-radius: 25px 0 0 0;
  flex-shrink: 0;
}

.ops-page-header-bar {
  flex: 1;
  background: linear-gradient(
    to bottom,
    var(--ops-orange) 0%,
    var(--ops-orange) 60%,
    transparent 60%
  );
  padding: 0.5rem 1rem;
}

.ops-page-title {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin: 0;
}

.ops-page-subtitle {
  font-size: 0.75rem;
  color: var(--ops-lilac);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-top: 0.25rem;
}

/* Ops Console Divider */
.ops-divider {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.ops-divider-line {
  flex: 1;
  height: 2px;
  background-color: var(--ops-plum);
}

.ops-divider-text {
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--ops-lilac);
  text-transform: uppercase;
  letter-spacing: 0.15em;
  white-space: nowrap;
}

/* Ops Console Quick Links Panel */
.ops-quick-links {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
  background-color: var(--color-bg-secondary);
  border: 2px solid var(--ops-plum);
  border-radius: 0 20px 20px 0;
  padding: 1rem;
  position: relative;
}

/* Panel left accent bar */
.ops-quick-links::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 8px;
  background-color: var(--ops-lilac);
  border-radius: 0 0 0 18px;
}

.ops-quick-link {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 1.25rem 1rem;
  background-color: var(--color-bg-tertiary);
  border-left: 4px solid var(--ops-lilac);
  border-radius: 0 15px 15px 0;
  text-decoration: none;
  transition: all 0.15s ease;
}

.ops-quick-link:hover {
  background-color: var(--ops-plum);
  border-left-color: var(--ops-orange);
  transform: translateX(4px);
  box-shadow: -4px 0 12px rgba(255, 153, 0, 0.3);
}

/* Link color variants */
.ops-link-orange { border-left-color: var(--ops-orange); }
.ops-link-peach { border-left-color: var(--ops-peach); }
.ops-link-lilac { border-left-color: var(--ops-lilac); }
.ops-link-gold { border-left-color: var(--ops-gold); }
.ops-link-sky { border-left-color: var(--ops-sky); }
.ops-link-green { border-left-color: var(--ops-green); }
.ops-link-external { border-left-color: var(--ops-green); }

.ops-quick-link-icon {
  font-size: 1.75rem;
}

.ops-quick-link-label {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--ops-peach);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

/* Ops Console Page Footer */
.ops-page-footer {
  display: flex;
  align-items: center;
  margin-top: 3rem;
  gap: 0;
}

.ops-page-footer .ops-bar {
  flex: 1;
  height: 8px;
}

.ops-page-footer-text {
  padding: 0 1rem;
  font-size: 0.625rem;
  color: var(--ops-lilac);
  text-transform: uppercase;
  letter-spacing: 0.15em;
  white-space: nowrap;
}

.ops-page-footer-elbow {
  width: 60px;
  height: 40px;
  background-color: var(--ops-lilac);
  border-radius: 20px 0 0 0;
  flex-shrink: 0;
}

/* Mobile Adjustments */
@media (max-width: 768px) {
  .ops-page {
    padding: 0 1rem 1.5rem;
  }

  .ops-page-header {
    flex-wrap: wrap;
  }

  .ops-page-header-elbow {
    width: 50px;
    height: 40px;
  }

  .ops-page-title {
    font-size: 1.25rem;
  }

  .ops-quick-links {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>

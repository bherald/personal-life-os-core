<template>
  <div class="ops-frame-container">
    <!-- ============================================
         Ops console TOP FRAME - Header Bar with Elbow
         Operational display frame
         ============================================ -->
    <div class="ops-header-frame">
      <!-- Top-Left Elbow (curved corner piece) -->
      <div class="ops-header-elbow" :class="elbowColorClass">
        <div class="ops-elbow-cutout"></div>
        <div class="ops-elbow-label" v-if="sectionCode">{{ sectionCode }}</div>
      </div>

      <!-- Top Bar with Title -->
      <div class="ops-header-bar" :class="barColorClass">
        <div class="ops-title-section">
          <h1 class="ops-page-title">{{ title }}</h1>
          <div class="ops-page-subtitle" v-if="subtitle">{{ subtitle }}</div>
        </div>

        <!-- Header Actions Slot -->
        <div class="ops-header-actions">
          <slot name="header-actions"></slot>
        </div>
      </div>

      <!-- Top-Right Cap (pill end) -->
      <div class="ops-header-cap" :class="capColorClass"></div>
    </div>

    <!-- ============================================
         Ops console NAVIGATION BAR - Segment Buttons
         ============================================ -->
    <div class="ops-nav-frame" v-if="$slots['nav-items']">
      <div class="ops-nav-spacer" :class="elbowColorClass"></div>
      <div class="ops-nav-bar">
        <slot name="nav-items"></slot>
      </div>
    </div>

    <!-- ============================================
         Ops console MAIN CONTENT AREA WITH SIDEBAR
         ============================================ -->
    <div class="ops-main-frame">
      <!-- Left Sidebar with Decorative Blocks -->
      <div class="ops-sidebar" v-if="showSidebar">
        <div class="ops-sidebar-block ops-block-1" :class="sidebarColor1Class"></div>
        <div class="ops-sidebar-block ops-block-2" :class="sidebarColor2Class"></div>
        <div class="ops-sidebar-block ops-block-3" :class="sidebarColor3Class"></div>
        <div class="ops-sidebar-block ops-block-4" :class="sidebarColor4Class"></div>

        <!-- Sidebar Content Slot (optional) -->
        <div class="ops-sidebar-content" v-if="$slots['sidebar']">
          <slot name="sidebar"></slot>
        </div>

        <div class="ops-sidebar-block ops-block-5" :class="sidebarColor5Class"></div>
      </div>

      <!-- Main Content Area -->
      <div class="ops-content-area ops-scroll">
        <!-- Alert Banner Slot (for notifications at top of content) -->
        <div class="ops-alert-slot" v-if="$slots['alert']">
          <slot name="alert"></slot>
        </div>

        <!-- Main Content -->
        <slot></slot>
      </div>
    </div>

    <!-- ============================================
         Ops console BOTTOM FRAME - Footer Bar with Elbow
         ============================================ -->
    <div class="ops-footer-frame">
      <!-- Bottom-Left Elbow -->
      <div class="ops-footer-elbow" :class="footerElbowColorClass">
        <div class="ops-footer-elbow-cutout"></div>
      </div>

      <!-- Footer Bar -->
      <div class="ops-footer-bar" :class="footerBarColorClass">
        <div class="ops-footer-content">
          <slot name="footer">
            <span class="ops-footer-text">{{ footerText }}</span>
          </slot>
        </div>

      </div>

      <!-- Bottom-Right Cap -->
      <div class="ops-footer-cap" :class="footerCapColorClass"></div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  // Page title displayed in header bar
  title: {
    type: String,
    required: true
  },
  // Optional subtitle below title
  subtitle: {
    type: String,
    default: ''
  },
  // Section code displayed in elbow (e.g., "01", "A1", etc.)
  sectionCode: {
    type: String,
    default: ''
  },
  // Color scheme: 'orange', 'sky', 'lilac', 'gold', 'peach', 'magenta', 'green', 'tan'
  colorScheme: {
    type: String,
    default: 'orange'
  },
  // Whether to show the left sidebar with colored blocks
  showSidebar: {
    type: Boolean,
    default: true
  },
  // Footer text
  footerText: {
    type: String,
    default: 'PERSONAL LIFE OS'
  },
});

// Color scheme mappings for different elements
const colorSchemes = {
  orange: {
    elbow: 'ops-bg-orange',
    bar: 'ops-bar-orange-gradient',
    cap: 'ops-bg-lilac',
    sidebar1: 'ops-bg-orange',
    sidebar2: 'ops-bg-peach',
    sidebar3: 'ops-bg-gold',
    sidebar4: 'ops-bg-tan',
    sidebar5: 'ops-bg-orange',
    footerElbow: 'ops-bg-tan',
    footerBar: 'ops-bg-tan',
    footerCap: 'ops-bg-peach'
  },
  sky: {
    elbow: 'ops-bg-sky',
    bar: 'ops-bar-sky-gradient',
    cap: 'ops-bg-lilac',
    sidebar1: 'ops-bg-sky',
    sidebar2: 'ops-bg-ice',
    sidebar3: 'ops-bg-azure',
    sidebar4: 'ops-bg-navy',
    sidebar5: 'ops-bg-sky',
    footerElbow: 'ops-bg-azure',
    footerBar: 'ops-bg-azure',
    footerCap: 'ops-bg-sky'
  },
  lilac: {
    elbow: 'ops-bg-lilac',
    bar: 'ops-bar-lilac-gradient',
    cap: 'ops-bg-magenta',
    sidebar1: 'ops-bg-lilac',
    sidebar2: 'ops-bg-violet',
    sidebar3: 'ops-bg-magenta',
    sidebar4: 'ops-bg-plum',
    sidebar5: 'ops-bg-lilac',
    footerElbow: 'ops-bg-violet',
    footerBar: 'ops-bg-violet',
    footerCap: 'ops-bg-lilac'
  },
  gold: {
    elbow: 'ops-bg-gold',
    bar: 'ops-bar-gold-gradient',
    cap: 'ops-bg-tan',
    sidebar1: 'ops-bg-gold',
    sidebar2: 'ops-bg-butterscotch',
    sidebar3: 'ops-bg-orange',
    sidebar4: 'ops-bg-sunset',
    sidebar5: 'ops-bg-gold',
    footerElbow: 'ops-bg-butterscotch',
    footerBar: 'ops-bg-butterscotch',
    footerCap: 'ops-bg-gold'
  },
  peach: {
    elbow: 'ops-bg-peach',
    bar: 'ops-bar-peach-gradient',
    cap: 'ops-bg-gold',
    sidebar1: 'ops-bg-peach',
    sidebar2: 'ops-bg-orange',
    sidebar3: 'ops-bg-gold',
    sidebar4: 'ops-bg-tan',
    sidebar5: 'ops-bg-peach',
    footerElbow: 'ops-bg-gold',
    footerBar: 'ops-bg-gold',
    footerCap: 'ops-bg-peach'
  },
  magenta: {
    elbow: 'ops-bg-magenta',
    bar: 'ops-bar-magenta-gradient',
    cap: 'ops-bg-lilac',
    sidebar1: 'ops-bg-magenta',
    sidebar2: 'ops-bg-lilac',
    sidebar3: 'ops-bg-violet',
    sidebar4: 'ops-bg-plum',
    sidebar5: 'ops-bg-magenta',
    footerElbow: 'ops-bg-lilac',
    footerBar: 'ops-bg-lilac',
    footerCap: 'ops-bg-magenta'
  },
  green: {
    elbow: 'ops-bg-green',
    bar: 'ops-bar-green-gradient',
    cap: 'ops-bg-teal',
    sidebar1: 'ops-bg-green',
    sidebar2: 'ops-bg-teal',
    sidebar3: 'ops-bg-sky',
    sidebar4: 'ops-bg-azure',
    sidebar5: 'ops-bg-green',
    footerElbow: 'ops-bg-teal',
    footerBar: 'ops-bg-teal',
    footerCap: 'ops-bg-green'
  },
  tan: {
    elbow: 'ops-bg-tan',
    bar: 'ops-bar-tan-gradient',
    cap: 'ops-bg-gold',
    sidebar1: 'ops-bg-tan',
    sidebar2: 'ops-bg-sunset',
    sidebar3: 'ops-bg-orange',
    sidebar4: 'ops-bg-peach',
    sidebar5: 'ops-bg-tan',
    footerElbow: 'ops-bg-sunset',
    footerBar: 'ops-bg-sunset',
    footerCap: 'ops-bg-tan'
  }
};

// Computed color classes based on scheme
const scheme = computed(() => colorSchemes[props.colorScheme] || colorSchemes.orange);
const elbowColorClass = computed(() => scheme.value.elbow);
const barColorClass = computed(() => scheme.value.bar);
const capColorClass = computed(() => scheme.value.cap);
const sidebarColor1Class = computed(() => scheme.value.sidebar1);
const sidebarColor2Class = computed(() => scheme.value.sidebar2);
const sidebarColor3Class = computed(() => scheme.value.sidebar3);
const sidebarColor4Class = computed(() => scheme.value.sidebar4);
const sidebarColor5Class = computed(() => scheme.value.sidebar5);
const footerElbowColorClass = computed(() => scheme.value.footerElbow);
const footerBarColorClass = computed(() => scheme.value.footerBar);
const footerCapColorClass = computed(() => scheme.value.footerCap);

</script>

<style scoped>
/* ============================================
   Ops console FRAME CONTAINER - Full Page Layout
   Operational display frame
   ============================================ */
.ops-frame-container {
  min-height: 100vh;
  height: auto; /* Allow growth beyond viewport */
  background-color: var(--ops-black);
  display: flex;
  flex-direction: column;
  font-family: 'Antonio', sans-serif;
}

/* ============================================
   HEADER FRAME - Top Bar with Elbow
   ============================================ */
.ops-header-frame {
  display: flex;
  padding: 0 var(--ops-gap);
  height: 90px;
}

.ops-header-elbow {
  width: 180px;
  height: 90px;
  border-radius: 0 0 50px 0;
  flex-shrink: 0;
  position: relative;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding-bottom: 8px;
}

.ops-elbow-cutout {
  position: absolute;
  right: 0;
  bottom: 0;
  width: 70px;
  height: 45px;
  background-color: var(--ops-black);
  border-radius: 25px 0 0 0;
}

.ops-elbow-label {
  position: relative;
  z-index: 1;
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.15em;
  margin-bottom: 4px;
  margin-right: 60px;
}

.ops-header-bar {
  flex: 1;
  height: 45px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 1.5rem;
  margin-left: -1px; /* Seamless connection */
}

.ops-title-section {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.ops-page-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.12em;
  line-height: 1.1;
  margin: 0;
}

.ops-page-subtitle {
  font-size: 0.65rem;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.15em;
  opacity: 0.7;
  margin-top: 2px;
}

.ops-header-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.ops-header-cap {
  width: 50px;
  height: 45px;
  border-radius: 0 0 0 25px;
  flex-shrink: 0;
}

/* ============================================
   NAVIGATION FRAME - Button Bar
   ============================================ */
.ops-nav-frame {
  display: flex;
  padding: 0 var(--ops-gap);
  margin-top: var(--ops-gap);
}

.ops-nav-spacer {
  width: 110px;
  height: 35px;
  border-radius: 0 0 18px 0;
  flex-shrink: 0;
}

.ops-nav-bar {
  flex: 1;
  display: flex;
  gap: var(--ops-gap);
  padding-left: var(--ops-gap);
  align-items: flex-start;
}

/* ============================================
   MAIN CONTENT FRAME - Sidebar + Content
   ============================================ */
.ops-main-frame {
  flex: 1 0 auto; /* Grow and don't shrink, auto basis allows content to expand */
  display: flex;
  gap: var(--ops-gap);
  padding: var(--ops-gap);
  padding-top: calc(var(--ops-gap) * 2);
  min-height: 0; /* Enable flex shrinking when needed */
}

.ops-sidebar {
  width: 110px;
  display: flex;
  flex-direction: column;
  gap: var(--ops-gap);
  flex-shrink: 0;
  align-self: stretch; /* Stretch to match content height */
}

.ops-sidebar-block {
  border-radius: var(--ops-border-radius);
  min-height: 40px;
  transition: opacity 0.3s ease;
}

.ops-sidebar-block:hover {
  opacity: 0.85;
}

.ops-block-1 { flex: 2; }
.ops-block-2 { flex: 1.5; }
.ops-block-3 { flex: 1; }
.ops-block-4 { flex: 0.8; }
.ops-block-5 { flex: 1.2; }

.ops-sidebar-content {
  background-color: var(--color-bg-secondary);
  border-radius: var(--ops-border-radius);
  padding: 0.75rem;
  flex: 1;
}

.ops-content-area {
  flex: 1;
  background-color: var(--color-bg-primary);
  border-radius: 30px 0 0 30px;
  padding: 1.5rem;
  overflow-y: visible; /* Let content expand container instead of scrolling internally */
  min-height: fit-content; /* Ensure it expands to fit content */
}

.ops-alert-slot {
  margin-bottom: 1rem;
}

/* ============================================
   FOOTER FRAME - Bottom Bar with Elbow
   ============================================ */
.ops-footer-frame {
  display: flex;
  padding: 0 var(--ops-gap);
  height: 55px;
  flex-shrink: 0; /* Prevent footer from shrinking */
  margin-top: var(--ops-gap); /* Consistent spacing instead of auto-pushing */
}

.ops-footer-elbow {
  width: 180px;
  height: 55px;
  border-radius: 0 50px 0 0;
  flex-shrink: 0;
  position: relative;
}

.ops-footer-elbow-cutout {
  position: absolute;
  right: 0;
  top: 0;
  width: 70px;
  height: 30px;
  background-color: var(--ops-black);
  border-radius: 0 0 25px 0;
}

.ops-footer-bar {
  flex: 1;
  height: 25px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 1.5rem;
  margin-top: 30px;
  margin-left: -1px;
}

.ops-footer-content {
  display: flex;
  align-items: center;
}

.ops-footer-text {
  font-size: 0.625rem;
  font-weight: 600;
  color: var(--ops-black);
  text-transform: uppercase;
  letter-spacing: 0.2em;
}


.ops-footer-cap {
  width: 50px;
  height: 25px;
  border-radius: 0 0 0 15px;
  flex-shrink: 0;
  margin-top: 30px;
}

/* ============================================
   COLOR CLASSES - Ops console Palette
   ============================================ */
.ops-bg-orange { background-color: var(--ops-orange); }
.ops-bg-peach { background-color: var(--ops-peach); }
.ops-bg-gold { background-color: var(--ops-gold); }
.ops-bg-tan { background-color: var(--ops-tan); }
.ops-bg-sunset { background-color: var(--ops-sunset); }
.ops-bg-butterscotch { background-color: var(--ops-butterscotch); }
.ops-bg-lilac { background-color: var(--ops-lilac); }
.ops-bg-violet { background-color: var(--ops-violet); }
.ops-bg-magenta { background-color: var(--ops-magenta); }
.ops-bg-plum { background-color: var(--ops-plum); }
.ops-bg-grape { background-color: var(--ops-grape); }
.ops-bg-sky { background-color: var(--ops-sky); }
.ops-bg-ice { background-color: var(--ops-ice); }
.ops-bg-azure { background-color: var(--ops-azure); }
.ops-bg-navy { background-color: var(--ops-navy); }
.ops-bg-teal { background-color: var(--ops-teal); }
.ops-bg-green { background-color: var(--ops-green); }

/* Gradient bars for header */
.ops-bar-orange-gradient { background: linear-gradient(180deg, var(--ops-orange) 0%, var(--ops-butterscotch) 100%); }
.ops-bar-sky-gradient { background: linear-gradient(180deg, var(--ops-sky) 0%, var(--ops-ice) 100%); }
.ops-bar-lilac-gradient { background: linear-gradient(180deg, var(--ops-lilac) 0%, var(--ops-lavender) 100%); }
.ops-bar-gold-gradient { background: linear-gradient(180deg, var(--ops-gold) 0%, var(--ops-yellow) 100%); }
.ops-bar-peach-gradient { background: linear-gradient(180deg, var(--ops-peach) 0%, var(--ops-gold) 100%); }
.ops-bar-magenta-gradient { background: linear-gradient(180deg, var(--ops-magenta) 0%, var(--ops-lilac) 100%); }
.ops-bar-green-gradient { background: linear-gradient(180deg, var(--ops-green) 0%, var(--ops-green-bright) 100%); }
.ops-bar-tan-gradient { background: linear-gradient(180deg, var(--ops-tan) 0%, var(--ops-gold) 100%); }

/* ============================================
   RESPONSIVE DESIGN - Mobile Adjustments
   ============================================ */
@media (max-width: 1024px) {
  .ops-header-elbow {
    width: 140px;
  }

  .ops-sidebar {
    width: 80px;
  }

  .ops-nav-spacer {
    width: 80px;
  }

  .ops-footer-elbow {
    width: 140px;
  }

  .ops-page-title {
    font-size: 1.25rem;
  }
}

@media (max-width: 768px) {
  .ops-header-frame {
    height: auto;
    flex-wrap: wrap;
    padding: var(--ops-gap);
  }

  .ops-header-elbow {
    display: none;
  }

  .ops-header-bar {
    flex: 1;
    width: 100%;
    border-radius: 20px;
    height: 50px;
  }

  .ops-header-cap {
    display: none;
  }

  .ops-nav-frame {
    padding: var(--ops-gap);
  }

  .ops-nav-spacer {
    display: none;
  }

  .ops-nav-bar {
    padding-left: 0;
    flex-wrap: wrap;
  }

  .ops-sidebar {
    display: none;
  }

  .ops-content-area {
    border-radius: 20px;
  }

  .ops-footer-frame {
    height: auto;
    flex-wrap: wrap;
    padding: var(--ops-gap);
  }

  .ops-footer-elbow {
    display: none;
  }

  .ops-footer-bar {
    flex: 1;
    width: 100%;
    border-radius: 15px;
    margin-top: 0;
    height: 35px;
  }

  .ops-footer-cap {
    display: none;
  }

  .ops-page-title {
    font-size: 1.1rem;
  }

  .ops-content-area {
    padding: 1rem;
  }
}

/* ============================================
   Ops console SCROLLBAR STYLING
   ============================================ */
.ops-scroll::-webkit-scrollbar {
  width: 10px;
}

.ops-scroll::-webkit-scrollbar-track {
  background: var(--color-bg-primary);
  border-radius: 5px;
}

.ops-scroll::-webkit-scrollbar-thumb {
  background: var(--ops-plum);
  border-radius: 5px;
  border: 2px solid var(--color-bg-primary);
}

.ops-scroll::-webkit-scrollbar-thumb:hover {
  background: var(--ops-violet);
}

/* ============================================
   UTILITY CLASSES FOR CHILD COMPONENTS
   ============================================ */

/* Navigation button style */
:deep(.ops-nav-btn) {
  padding: 0.375rem 0.875rem;
  font-family: 'Antonio', sans-serif;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  border-radius: 0 0 12px 12px;
  min-height: 32px;
  background-color: var(--ops-orange);
  color: var(--ops-black);
  border: none;
  cursor: pointer;
  transition: all 0.15s ease;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
}

:deep(.ops-nav-btn:hover) {
  background-color: var(--ops-gold);
  transform: translateY(2px);
}

:deep(.ops-nav-btn.active) {
  background-color: var(--ops-peach);
}

:deep(.ops-nav-btn-lilac) { background-color: var(--ops-lilac); }
:deep(.ops-nav-btn-lilac:hover) { background-color: var(--ops-lavender); }

:deep(.ops-nav-btn-sky) { background-color: var(--ops-sky); }
:deep(.ops-nav-btn-sky:hover) { background-color: var(--ops-ice); }

:deep(.ops-nav-btn-peach) { background-color: var(--ops-peach); }
:deep(.ops-nav-btn-peach:hover) { background-color: var(--ops-gold); }

/* Section divider for content area */
:deep(.ops-section-divider) {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 1.5rem 0;
}

:deep(.ops-section-line) {
  flex: 1;
  height: 4px;
  background-color: var(--ops-plum);
  border-radius: 2px;
}

:deep(.ops-section-title) {
  color: var(--ops-lilac);
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  white-space: nowrap;
  padding: 0.25rem 0.75rem;
  background-color: var(--color-bg-secondary);
  border-radius: 10px;
}
</style>

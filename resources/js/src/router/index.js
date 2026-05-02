import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/LoginView.vue'),
    meta: { requiresAuth: false }
  },
  // Life-Centric Routes (Primary Navigation)
  {
    path: '/today',
    name: 'Today',
    component: () => import('../views/TodayView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/ai',
    redirect: '/knowledge?source=ai'
  },
  {
    path: '/knowledge',
    name: 'KnowledgeHub',
    component: () => import('../views/KnowledgeHubView.vue'),
    meta: { requiresAuth: true }
  },
  // Backwards compatibility redirects
  {
    path: '/chat',
    redirect: '/ai'
  },
  {
    path: '/assistant',
    redirect: '/ai'
  },
  {
    path: '/search',
    redirect: '/knowledge'
  },
  {
    path: '/rag',
    redirect: '/knowledge'
  },
  {
    path: '/joplin',
    redirect: '/knowledge'
  },
  {
    path: '/calendar',
    name: 'Calendar',
    component: () => import('../views/CalendarView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/contacts',
    name: 'Contacts',
    component: () => import('../views/ContactsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/health',
    name: 'Health',
    component: () => import('../views/HealthView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/finance',
    name: 'Finance',
    component: () => import('../views/FinanceView.vue'),
    meta: { requiresAuth: true }
  },
  // Redirect old orchestrator route to unified AI Assistant
  {
    path: '/orchestrator',
    redirect: '/ai'
  },
  // Research now part of AI Hub
  {
    path: '/research',
    redirect: '/ai'
  },
  {
    path: '/research-topics',
    redirect: '/ai'
  },
  {
    path: '/research-missions',
    redirect: '/ai'
  },
  {
    path: '/data-removal',
    name: 'DataRemoval',
    component: () => import('../views/DataRemovalView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/genealogy',
    name: 'Genealogy',
    component: () => import('../views/GenealogyView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/email-queue',
    name: 'EmailQueue',
    component: () => import('../views/EmailQueueView.vue'),
    meta: { requiresAuth: true }
  },
  // System/Technical Routes (Settings Dropdown)
  {
    path: '/workflows',
    name: 'Workflows',
    component: () => import('../views/WorkflowsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/workflows/create',
    name: 'WorkflowCreate',
    component: () => import('../views/WorkflowEditorView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/workflows/:id/edit',
    name: 'WorkflowEditor',
    component: () => import('../views/WorkflowEditorView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/nodes',
    name: 'Nodes',
    component: () => import('../views/NodesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/executions',
    name: 'Executions',
    component: () => import('../views/ExecutionsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/config',
    name: 'Configuration',
    component: () => import('../views/ConfigurationView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/diagnostics',
    name: 'Diagnostics',
    component: () => import('../views/DiagnosticsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/dev-tools',
    name: 'DeveloperTools',
    component: () => import('../views/DeveloperToolsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/mcp',
    name: 'MCPTools',
    component: () => import('../views/MCPView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/ai-status',
    redirect: '/diagnostics'
  },
  {
    path: '/system-issues',
    name: 'SystemIssues',
    component: () => import('../views/SystemIssuesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/scheduled-jobs',
    name: 'ScheduledJobs',
    component: () => import('../views/ScheduledJobsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/file-catalog',
    name: 'FileCatalog',
    component: () => import('../views/FileCatalogView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/media/faces',
    name: 'Faces',
    component: () => import('../views/FacesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/media/faces/person',
    name: 'PersonFaces',
    component: () => import('../views/PersonFacesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/media/face-clusters',
    redirect: '/media/faces'
  },
  {
    path: '/media',
    redirect: '/knowledge'
  },
  {
    path: '/file-organizer',
    redirect: '/knowledge'
  },
  {
    path: '/youtube',
    name: 'YouTube',
    component: () => import('../views/YouTubeView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/daily-ops',
    name: 'DailyOps',
    component: () => import('../views/DailyOpsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/operator-evidence',
    name: 'OperatorEvidence',
    component: () => import('../views/OperatorEvidenceView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/ai-observability',
    name: 'AIObservability',
    component: () => import('../views/AIObservabilityView.vue'),
    meta: { requiresAuth: true }
  },
  // Hub consolidated into Knowledge → Review tab
  {
    path: '/hub',
    redirect: '/knowledge?source=review'
  },
  {
    path: '/agents',
    redirect: '/knowledge?source=agents'
  },
  {
    path: '/reviews',
    redirect: '/knowledge?source=review'
  },
  {
    path: '/research-hub',
    redirect: '/knowledge?source=review'
  },
  {
    path: '/graph',
    redirect: '/knowledge?source=graph'
  },
  // Default redirect to Today view
  {
    path: '/',
    redirect: '/today'
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore();
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth);

  if (requiresAuth && !authStore.isAuthenticated) {
    next('/login');
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next('/');
  } else {
    next();
  }
});

export default router;

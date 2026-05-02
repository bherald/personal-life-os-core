<template>
  <div class="min-h-screen bg-[#1a1a1a]">
    <div class="max-w-7xl mx-auto px-4 py-8">
      <!-- Header with Search and Create Button -->
      <div class="mb-6">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-2xl font-bold text-[#e0e0e0] border-b-2 border-accent pb-2">Workflows</h2>
          <button @click="router.push('/workflows/create')" class="btn-primary flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create Workflow
          </button>
        </div>

        <!-- Search and Filter Bar -->
        <div class="flex gap-4">
          <div class="flex-1">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Search workflows..."
              class="input"
              @input="handleSearch"
            />
          </div>
          <select
            v-model="filterActive"
            class="form-select"
            @change="handleSearch"
          >
            <option value="">All Status</option>
            <option value="true">Active Only</option>
            <option value="false">Inactive Only</option>
          </select>
          <button @click="refreshWorkflows" class="btn-secondary flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Refresh
          </button>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="workflowsStore.loading" class="card">
        <div class="flex items-center justify-center py-12">
          <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-accent"></div>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="workflowsStore.error" class="alert alert-danger">
        <div class="flex items-center gap-3">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span>{{ workflowsStore.error }}</span>
        </div>
      </div>

      <!-- Pending Approvals Banner -->
      <div v-if="pendingApprovals.length > 0" class="mb-4 bg-[#f39c12]/10 border-2 border-[#f39c12] rounded-lg p-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="text-2xl">&#9888;</span>
          <div>
            <div class="font-bold text-[#f39c12]">{{ pendingApprovals.length }} Pending Approval{{ pendingApprovals.length > 1 ? 's' : '' }}</div>
            <div class="text-sm text-[#95a5a6]">
              {{ pendingApprovals.map(a => a.workflow_name || `Run #${a.run_id}`).join(', ') }}
            </div>
          </div>
        </div>
        <button @click="showApprovalsPanel = !showApprovalsPanel" class="btn-secondary text-sm">
          {{ showApprovalsPanel ? 'Hide' : 'Review' }}
        </button>
      </div>

      <!-- Approvals Panel -->
      <div v-if="showApprovalsPanel && pendingApprovals.length > 0" class="mb-4 space-y-2">
        <div v-for="approval in pendingApprovals" :key="approval.id" class="card p-4 border border-[#f39c12] flex justify-between items-center">
          <div>
            <div class="font-medium text-[#e0e0e0]">{{ approval.workflow_name || `Run #${approval.run_id}` }}</div>
            <div class="text-sm text-[#95a5a6]">Requested: {{ new Date(approval.requested_at).toLocaleString() }}</div>
            <div v-if="approval.context" class="text-xs text-[#95a5a6] mt-1">{{ JSON.stringify(approval.context).substring(0, 100) }}</div>
          </div>
          <div class="flex gap-2">
            <button @click="approveGate(approval.id)" class="px-3 py-1 text-sm bg-[#27ae60] text-white rounded hover:bg-[#229954]">Approve</button>
            <button @click="rejectGate(approval.id)" class="px-3 py-1 text-sm bg-[#e74c3c] text-white rounded hover:bg-[#c0392b]">Reject</button>
          </div>
        </div>
      </div>

      <!-- Templates Section -->
      <div v-if="templates.length > 0" class="mb-4">
        <div class="card">
          <div class="p-4 flex justify-between items-center cursor-pointer" @click="showTemplates = !showTemplates">
            <h3 class="text-lg font-medium text-[#e0e0e0]">Templates ({{ templates.length }})</h3>
            <span class="text-[#95a5a6]">{{ showTemplates ? '&#9660;' : '&#9654;' }}</span>
          </div>
          <div v-if="showTemplates" class="border-t border-[#444]">
            <div v-for="tmpl in templates" :key="tmpl.id" class="p-4 border-b border-[#444] last:border-0 flex justify-between items-center hover:bg-[#34495e]/20">
              <div>
                <div class="font-medium text-[#e0e0e0]">{{ tmpl.name }}</div>
                <div class="text-sm text-[#95a5a6]">{{ tmpl.description || 'No description' }} | Used {{ tmpl.usage_count || 0 }} times</div>
              </div>
              <button @click="useTemplate(tmpl.id)" class="px-3 py-1 text-sm bg-accent text-white rounded hover:bg-accent-dark">
                Use Template
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Workflows Table -->
      <div v-else-if="!workflowsStore.loading && !workflowsStore.error" class="text-sm text-[#95a5a6] mb-4 text-center">
        <button @click="loadTemplates" class="text-accent hover:underline">Load workflow templates</button>
      </div>
      <div v-if="!workflowsStore.loading && !workflowsStore.error" class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Schedule</th>
                <th>Error Handling</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="workflow in filteredWorkflows"
                :key="workflow.id"
              >
                <td class="font-medium text-[#e0e0e0]">{{ workflow.name }}</td>
                <td class="text-[#95a5a6]">{{ workflow.description || '-' }}</td>
                <td class="font-mono text-sm text-[#95a5a6]">{{ workflow.schedule || '-' }}</td>
                <td>
                  <span class="badge" :class="workflow.error_handling === 'stop' ? 'badge-danger' : 'badge-success'">
                    {{ workflow.error_handling || 'stop' }}
                  </span>
                </td>
                <td>
                  <span class="badge" :class="workflow.active ? 'badge-success' : 'badge-gray'">
                    {{ workflow.active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td>
                  <div class="flex gap-2">
                    <button
                      @click="runWorkflow(workflow.id)"
                      class="px-3 py-1 text-sm bg-accent text-white rounded hover:bg-accent-dark transition-colors font-semibold"
                      title="Run workflow"
                    >
                      ▶️ Run
                    </button>
                    <button
                      @click="router.push(`/workflows/${workflow.id}/edit`)"
                      class="px-3 py-1 text-sm bg-accent text-white rounded hover:bg-accent-dark transition-colors font-semibold"
                      title="Edit workflow in full-page editor"
                    >
                      ✏️ Edit
                    </button>
                    <button
                      @click="toggleWorkflow(workflow.id)"
                      class="px-3 py-1 text-sm border border-[#444] text-[#95a5a6] rounded hover:bg-[#34495e] hover:text-[#e0e0e0] transition-colors"
                      :title="workflow.active ? 'Disable workflow' : 'Enable workflow'"
                    >
                      {{ workflow.active ? 'Disable' : 'Enable' }}
                    </button>
                    <button
                      @click="confirmDelete(workflow)"
                      class="btn-danger px-3 py-1 text-sm"
                      title="Delete workflow"
                    >
                      🗑️ Delete
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <div v-if="filteredWorkflows.length === 0" class="py-12 text-center text-[#95a5a6]">
            <svg class="w-16 h-16 mx-auto mb-4 text-[#444]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
            </svg>
            <p class="text-lg font-medium mb-2 text-[#e0e0e0]">No workflows found</p>
            <p class="text-sm">Create your first workflow to get started</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div
      v-if="showDeleteModal"
      class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50 p-4"
      @click.self="closeDeleteModal"
    >
      <div class="bg-[#2d2d2d] rounded-lg shadow-2xl max-w-md w-full border-2 border-[#e74c3c]">
        <div class="p-6">
          <div class="flex items-center gap-3 text-[#e74c3c] mb-4">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
              <h3 class="text-xl font-bold text-[#e0e0e0]">Confirm Delete</h3>
              <p class="text-[#95a5a6] mt-1">Are you sure you want to delete this workflow?</p>
            </div>
          </div>

          <div class="bg-[#1a1a1a] p-3 rounded mb-4 border border-[#444]">
            <p class="font-medium text-[#e0e0e0]">{{ workflowToDelete?.name }}</p>
            <p class="text-sm text-[#95a5a6]">{{ workflowToDelete?.description }}</p>
          </div>

          <p class="text-sm text-[#e74c3c] mb-4 font-semibold">⚠️ This action cannot be undone.</p>

          <div class="flex justify-end gap-3">
            <button
              @click="closeDeleteModal"
              class="btn-secondary"
            >
              Cancel
            </button>
            <button
              @click="deleteWorkflow"
              class="btn-danger flex items-center gap-2"
              :disabled="deleting"
            >
              <span v-if="deleting" class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></span>
              {{ deleting ? 'Deleting...' : 'Delete Workflow' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useWorkflowsStore } from '../stores/workflows';
import axios from 'axios';

const router = useRouter();
const authStore = useAuthStore();
const workflowsStore = useWorkflowsStore();

// Search and filter
const searchQuery = ref('');
const filterActive = ref('');

// Delete modal state
const showDeleteModal = ref(false);
const deleting = ref(false);
const workflowToDelete = ref(null);

// Templates & Approvals state
const templates = ref([]);
const showTemplates = ref(false);
const pendingApprovals = ref([]);
const showApprovalsPanel = ref(false);

// Computed
const filteredWorkflows = computed(() => {
  let filtered = workflowsStore.workflows;

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    filtered = filtered.filter(w =>
      w.name.toLowerCase().includes(query) ||
      (w.description && w.description.toLowerCase().includes(query))
    );
  }

  if (filterActive.value !== '') {
    const isActive = filterActive.value === 'true';
    filtered = filtered.filter(w => w.active === isActive);
  }

  return filtered;
});

// Methods
const refreshWorkflows = async () => {
  await workflowsStore.fetchWorkflows();
};

const handleSearch = () => {
  // Filtering is done via computed property
};

const confirmDelete = (workflow) => {
  workflowToDelete.value = workflow;
  showDeleteModal.value = true;
};

const closeDeleteModal = () => {
  showDeleteModal.value = false;
  workflowToDelete.value = null;
};

const deleteWorkflow = async () => {
  if (!workflowToDelete.value) return;

  deleting.value = true;

  try {
    const result = await workflowsStore.deleteWorkflow(workflowToDelete.value.id);

    if (result.success) {
      closeDeleteModal();
      await refreshWorkflows();
    } else {
      alert('Error: ' + result.error);
    }
  } finally {
    deleting.value = false;
  }
};

const runWorkflow = async (id) => {
  if (confirm('Run this workflow now?')) {
    const result = await workflowsStore.runWorkflow(id);
    if (result.success) {
      alert('Workflow started successfully!');
    } else {
      alert('Error: ' + result.error);
    }
  }
};

const toggleWorkflow = async (id) => {
  const result = await workflowsStore.toggleWorkflow(id);
  if (result.success) {
    alert(`Workflow ${result.active ? 'enabled' : 'disabled'}`);
  } else {
    alert('Error: ' + result.error);
  }
};

// Templates & Approvals methods
const loadTemplates = async () => {
  try {
    const { data } = await axios.get('/api/workflows/templates');
    if (data.success) templates.value = data.data || [];
  } catch (err) { console.error('Failed to load templates:', err); }
};

const useTemplate = async (id) => {
  try {
    const { data } = await axios.post(`/api/workflows/from-template/${id}`);
    if (data.success) {
      alert('Workflow created from template!');
      refreshWorkflows();
    }
  } catch (err) { alert('Failed to create from template: ' + (err.response?.data?.error?.message || err.message)); }
};

const loadApprovals = async () => {
  try {
    const { data } = await axios.get('/api/workflows/pending-approvals');
    if (data.success) pendingApprovals.value = data.data || [];
  } catch (err) { console.error('Failed to load approvals:', err); }
};

const approveGate = async (id) => {
  try {
    await axios.post(`/api/workflows/pending-approvals`, { gate_id: id, action: 'approve' });
    loadApprovals();
  } catch (err) { console.error('Failed to approve:', err); }
};

const rejectGate = async (id) => {
  try {
    await axios.post(`/api/workflows/pending-approvals`, { gate_id: id, action: 'reject' });
    loadApprovals();
  } catch (err) { console.error('Failed to reject:', err); }
};

onMounted(() => {
  refreshWorkflows();
  loadApprovals();
  loadTemplates();
});
</script>

import { defineStore } from 'pinia';
import api from '../utils/api';

export const useWorkflowsStore = defineStore('workflows', {
  state: () => ({
    workflows: [],
    currentWorkflow: null,
    loading: false,
    error: null,
  }),

  actions: {
    async fetchWorkflows(filters = {}) {
      this.loading = true;
      this.error = null;
      try {
        const params = new URLSearchParams(filters).toString();
        const response = await api.get(`/workflows?${params}`);
        if (response.success) {
          this.workflows = response.data;
        }
      } catch (error) {
        this.error = error.response?.data?.error?.message || 'Failed to fetch workflows';
      } finally {
        this.loading = false;
      }
    },

    async fetchWorkflow(id) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.get(`/workflows/${id}`);
        if (response.success) {
          this.currentWorkflow = response.data;
          return response.data;
        }
      } catch (error) {
        this.error = error.response?.data?.error?.message || 'Failed to fetch workflow';
        return null;
      } finally {
        this.loading = false;
      }
    },

    async createWorkflow(workflowData) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.post('/workflows', workflowData);
        if (response.success) {
          await this.fetchWorkflows();
          return { success: true, data: response.data };
        }
      } catch (error) {
        this.error = error.response?.data?.error?.message || 'Failed to create workflow';
        return {
          success: false,
          error: this.error
        };
      } finally {
        this.loading = false;
      }
    },

    async updateWorkflow(id, workflowData) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.put(`/workflows/${id}`, workflowData);
        if (response.success) {
          await this.fetchWorkflows();
          return { success: true };
        }
      } catch (error) {
        this.error = error.response?.data?.error?.message || 'Failed to update workflow';
        return {
          success: false,
          error: this.error
        };
      } finally {
        this.loading = false;
      }
    },

    async deleteWorkflow(id) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.delete(`/workflows/${id}`);
        if (response.success) {
          await this.fetchWorkflows();
          return { success: true };
        }
      } catch (error) {
        this.error = error.response?.data?.error?.message || 'Failed to delete workflow';
        return {
          success: false,
          error: this.error
        };
      } finally {
        this.loading = false;
      }
    },

    async runWorkflow(id) {
      try {
        const response = await api.post(`/workflows/${id}/run`);
        return response;
      } catch (error) {
        return {
          success: false,
          error: error.response?.data?.error?.message || 'Failed to run workflow'
        };
      }
    },

    async toggleWorkflow(id) {
      try {
        const response = await api.post(`/workflows/${id}/toggle`);
        if (response.success) {
          await this.fetchWorkflows();
          return { success: true, active: response.data.active };
        }
      } catch (error) {
        return {
          success: false,
          error: error.response?.data?.error?.message || 'Failed to toggle workflow'
        };
      }
    },

    clearError() {
      this.error = null;
    },

    clearCurrentWorkflow() {
      this.currentWorkflow = null;
    },
  },
});

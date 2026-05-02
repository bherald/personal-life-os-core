import { defineStore } from 'pinia';
import api from '../utils/api';

export const useExecutionsStore = defineStore('executions', {
  state: () => ({
    executions: [],
    currentExecution: null,
    stats: null,
    loading: false,
    error: null,
    filters: {
      workflow_id: null,
      status: null,
      date_from: null,
      date_to: null,
    },
  }),

  actions: {
    async fetchExecutions(filters = {}) {
      this.loading = true;
      this.error = null;
      try {
        const params = new URLSearchParams(
          Object.fromEntries(
            Object.entries(filters).filter(([_, v]) => v != null && v !== '')
          )
        ).toString();
        const response = await api.get(`/executions?${params}`);
        if (response.success) {
          this.executions = response.data;
        }
      } catch (error) {
        this.error = error.response?.data?.error?.message || 'Failed to fetch executions';
      } finally {
        this.loading = false;
      }
    },

    async fetchExecutionDetails(id) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.get(`/executions/${id}`);
        if (response.success) {
          this.currentExecution = response.data;
        }
      } catch (error) {
        this.error = error.response?.data?.error?.message || 'Failed to fetch execution details';
      } finally {
        this.loading = false;
      }
    },

    async fetchStats() {
      try {
        const response = await api.get('/executions/stats');
        if (response.success) {
          this.stats = response.data;
        }
      } catch (error) {
        console.error('Failed to fetch execution stats:', error);
      }
    },

    async retryExecution(id) {
      try {
        const response = await api.post(`/executions/${id}/retry`);
        if (response.success) {
          await this.fetchExecutions(this.filters);
        }
        return response;
      } catch (error) {
        return {
          success: false,
          error: error.response?.data?.error?.message || 'Failed to retry execution'
        };
      }
    },

    setFilters(filters) {
      this.filters = { ...this.filters, ...filters };
    },

    clearFilters() {
      this.filters = {
        workflow_id: null,
        status: null,
        date_from: null,
        date_to: null,
      };
    },
  },
});

import { defineStore } from 'pinia';
import api from '../utils/api';

export const useOrchestratorStore = defineStore('orchestrator', {
  state: () => ({
    // Request history
    history: [],
    currentRequest: null,

    // Status
    status: null,
    capabilities: null,
    loading: false,
    processing: false,
    error: null,

    // Current result
    result: null,
    intent: null,
    metadata: null,
  }),

  getters: {
    /**
     * Get recent successful requests
     */
    recentRequests: (state) => {
      return state.history
        .filter(item => item.result?.success)
        .slice(-10)
        .reverse();
    },

    /**
     * Check if orchestrator is available
     */
    isAvailable: (state) => {
      return state.status?.available || false;
    },

    /**
     * Get supported intents
     */
    supportedIntents: (state) => {
      return state.capabilities?.intents || [];
    },
  },

  actions: {
    /**
     * Fetch orchestrator status
     */
    async fetchStatus() {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.get('/orchestrator/status');
        if (response) {
          // Store the entire response as status
          this.status = {
            status: response.status,
            available: response.available,
            services: response.services,
            conversations: response.conversations,
            total_messages: response.total_messages,
          };
          this.capabilities = response.capabilities;
        }
        return { success: true };
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch status';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Fetch help information
     */
    async fetchHelp() {
      try {
        const response = await api.get('/orchestrator/help');
        return { success: true, data: response };
      } catch (error) {
        return {
          success: false,
          error: error.response?.data?.message || 'Failed to fetch help'
        };
      }
    },

    /**
     * Process a request through the orchestrator
     */
    async processRequest(request, conversationId = null, options = {}) {
      if (!request?.trim()) {
        this.error = 'Request cannot be empty';
        return { success: false, error: this.error };
      }

      this.processing = true;
      this.error = null;
      this.currentRequest = request;

      try {
        const response = await api.post('/orchestrator/process', {
          request: request.trim(),
          conversation_id: conversationId,
          options,
        });

        if (response.success) {
          // Store result
          this.result = response.result;
          this.intent = response.intent;
          this.metadata = response.metadata;

          // Add to history
          this.history.push({
            request,
            result: response.result,
            intent: response.intent,
            timestamp: new Date().toISOString(),
          });

          return {
            success: true,
            result: response.result,
            intent: response.intent,
            metadata: response.metadata,
          };
        }

        return { success: false, error: 'Unknown error occurred' };
      } catch (error) {
        this.error = error.response?.data?.message || error.message || 'Failed to process request';
        return { success: false, error: this.error };
      } finally {
        this.processing = false;
      }
    },

    /**
     * Clear current result
     */
    clearResult() {
      this.result = null;
      this.intent = null;
      this.metadata = null;
      this.currentRequest = null;
      this.error = null;
    },

    /**
     * Clear history
     */
    clearHistory() {
      this.history = [];
    },
  },
});

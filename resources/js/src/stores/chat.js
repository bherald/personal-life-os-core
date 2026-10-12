import { defineStore } from 'pinia';
import { markRaw } from 'vue';
import api from '../utils/api';
import { normalizeModelRouteResponse } from '../utils/modelRouteBootstrap';
import { SseEventDecoder } from '../utils/sseEventDecoder';
import {
  applyChatStreamEvent,
  createChatStreamActivity,
  formatChatStreamStatus,
} from '../utils/chatStreamActivity';
import {
  applyChatToolEvent,
  normalizeChatToolActivities,
} from '../utils/chatToolActivity';
import {
  createLocalChatRunFailure,
  normalizeChatRunFailure,
  normalizeChatRunId,
} from '../utils/chatRunFailure';
import {
  invalidateConversationLoads,
  loadLatestConversation,
} from '../utils/latestConversationLoad';

export const useChatStore = defineStore('chat', {
  state: () => ({
    conversations: [],
    currentConversation: null,
    messages: [],
    modelModes: [],
    modelModesLoading: false,
    modelModesLoaded: false,
    modelModesError: null,
    privateSupported: false,
    overrideSupported: false,
    loading: false,
    sending: false,
    streamStatus: '',
    activeStreamController: null,
    activeStreamRunId: null,
    activeStreamServerCancelable: false,
    activeStreamCancellationRequested: false,
    error: null,
    conversationLoadGeneration: 0,
  }),

  getters: {
    /**
     * Calculate total tokens in current conversation
     */
    totalTokens: (state) => {
      if (!state.messages.length) return 0;
      return state.messages.reduce((sum, msg) => sum + (msg.tokens || 0), 0);
    },

    /**
     * Estimate context size (4 chars ≈ 1 token for llama3.1)
     */
    estimatedContextTokens: (state) => {
      if (!state.messages.length) return 0;
      const totalChars = state.messages.reduce(
        (sum, msg) => sum + (msg.content?.length || 0),
        0
      );
      return Math.ceil(totalChars / 4);
    },

    /**
     * Check if context is getting large (>7000 tokens)
     */
    isContextLarge: (state) => {
      return state.messages.reduce((sum, msg) => sum + (msg.content?.length || 0), 0) / 4 > 7000;
    },
  },

  actions: {
    /**
     * Fetch model profiles and capability metadata for the selector.
     */
    async fetchModelModes() {
      this.modelModesLoading = true;
      this.modelModesError = null;
      try {
        const response = await api.get('/chat/model-modes');
        const routes = normalizeModelRouteResponse(response);
        const modes = routes.modes;
        if (!modes.length) {
          this.modelModes = [];
          this.modelModesLoaded = false;
          this.privateSupported = false;
          this.overrideSupported = false;
          this.modelModesError = 'No model routes are available';
          return { success: false, error: this.modelModesError };
        }
        this.modelModes = modes;
        this.modelModesLoaded = true;
        this.privateSupported = routes.privateSupported;
        this.overrideSupported = routes.overrideSupported;
        return { success: true, modes: this.modelModes };
      } catch (error) {
        this.modelModes = [];
        this.modelModesLoaded = false;
        this.privateSupported = false;
        this.overrideSupported = false;
        this.modelModesError = error.response?.data?.message || 'Failed to load model routes';
        return { success: false, error: this.modelModesError };
      } finally {
        this.modelModesLoading = false;
      }
    },

    /**
     * Fetch all conversations
     */
    async fetchConversations() {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.get('/chat/conversations');
        if (response.success) {
          this.conversations = response.conversations;
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch conversations';
      } finally {
        this.loading = false;
      }
    },

    /**
     * Create a new conversation
     */
    async createConversation(title = null, modelMode = 'standard', isPrivate = false, modelOverride = false) {
      if (this.sending) {
        return { success: false, error: 'Wait for the active response to finish' };
      }

      invalidateConversationLoads(this);
      const generation = this.conversationLoadGeneration;
      this.loading = true;
      this.error = null;
      try {
        const response = await api.post('/chat/conversations', {
          title,
          model_mode: modelMode,
          is_private: isPrivate || modelMode === 'uncensored',
          model_override: modelOverride,
        });
        if (this.conversationLoadGeneration !== generation) {
          return { success: false, stale: true };
        }

        if (response.success) {
          this.currentConversation = response.conversation;
          this.messages = [];
          await this.fetchConversations();
          return { success: true, conversation: response.conversation };
        }
      } catch (error) {
        if (this.conversationLoadGeneration !== generation) {
          return { success: false, stale: true };
        }

        this.error = error.response?.data?.message || 'Failed to create conversation';
        return { success: false, error: this.error };
      } finally {
        if (this.conversationLoadGeneration === generation) {
          this.loading = false;
        }
      }
    },

    /**
     * Load a conversation with its messages
     */
    async loadConversation(id) {
      if (this.sending) {
        return { success: false, error: 'Wait for the active response to finish' };
      }

      return loadLatestConversation(
        this,
        id,
        conversationId => api.get(`/chat/conversations/${conversationId}`),
        messages => messages.map(msg => {
          // Parse metadata to extract ragSources and intent for each message
          msg.tool_calls = normalizeChatToolActivities(msg.tool_calls);
          if (msg.metadata) {
            try {
              const meta = typeof msg.metadata === 'string' ? JSON.parse(msg.metadata) : msg.metadata;
              if (meta.ragSources) {
                msg.ragSources = meta.ragSources;
              }
              if (meta.intent) {
                msg.intent = meta.intent;
              }
              if (meta.stream_interruption) {
                msg.streamInterruption = meta.stream_interruption;
              }
            } catch (e) {
              console.warn('Failed to parse message metadata:', e);
            }
          }
          return msg;
        }),
      );
    },

    /**
     * Clear a conversation (soft delete)
     */
    async clearConversation(id) {
      if (this.sending) {
        return { success: false, error: 'Wait for the active response to finish' };
      }

      this.loading = true;
      this.error = null;
      try {
        const response = await api.delete(`/chat/conversations/${id}`);
        if (response.success) {
          // If clearing current conversation, reset state
          if (this.currentConversation?.id === id) {
            this.currentConversation = null;
            this.messages = [];
          }
          await this.fetchConversations();
          return { success: true };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to clear conversation';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Clear all messages in a conversation (keep conversation, delete messages)
     */
    async clearMessageHistory(id) {
      if (this.sending) {
        return { success: false, error: 'Wait for the active response to finish' };
      }

      this.loading = true;
      this.error = null;
      try {
        const response = await api.delete(`/chat/conversations/${id}/messages`);
        if (response.success) {
          // Clear local messages if this is the current conversation
          if (this.currentConversation?.id === id) {
            this.messages = [];
          }
          return { success: true, deleted_count: response.deleted_count };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to clear message history';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Send a message and get AI response (non-streaming fallback)
     */
    async sendMessage(content, attachments = []) {
      if (!this.currentConversation) {
        // Create a new conversation if none exists
        const result = await this.createConversation();
        if (!result.success) {
          return result;
        }
      }

      this.sending = true;
      this.error = null;

      try {
        const hasAttachments = attachments.length > 0;
        const payload = hasAttachments ? this.buildMessageFormData(content, attachments) : { content };
        const response = await api.post(
          `/chat/conversations/${this.currentConversation.id}/messages`,
          payload,
          hasAttachments ? { headers: { 'Content-Type': 'multipart/form-data' } } : undefined
        );

        if (response.success) {
          // Add both user and assistant messages to the store
          this.messages.push(response.user_message);
          this.messages.push(response.assistant_message);

          return {
            success: true,
            userMessage: response.user_message,
            assistantMessage: response.assistant_message,
          };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to send message';
        return { success: false, error: this.error };
      } finally {
        this.sending = false;
      }
    },

    /**
     * Send a message and stream AI response in real-time
     */
    async sendMessageStream(content, attachments = []) {
      if (!this.currentConversation) {
        // Create a new conversation if none exists
        const result = await this.createConversation();
        if (!result.success) {
          return result;
        }
      }

      this.sending = true;
      const streamController = markRaw(new AbortController());
      this.activeStreamController = streamController;
      let streamActivity = createChatStreamActivity();
      this.streamStatus = formatChatStreamStatus(streamActivity);
      this.error = null;

      try {
        // Add user message optimistically
        const userMessage = {
          id: Date.now(), // Temporary ID
          conversation_id: this.currentConversation.id,
          role: 'user',
          content: content || this.attachmentOnlyContent(attachments),
          attachments: attachments.map((file, index) => this.fileToAttachmentPayload(file, index)),
          created_at: new Date().toISOString(),
        };
        this.messages.push(userMessage);

        // Create placeholder for assistant message
        const assistantMessage = {
          id: Date.now() + 1, // Temporary ID
          conversation_id: this.currentConversation.id,
          role: 'assistant',
          content: '',
          tool_calls: null,
          tokens: null,
          created_at: new Date().toISOString(),
          streaming: true, // Flag to indicate streaming in progress
        };
        this.messages.push(assistantMessage);

        const assistantMessageIndex = this.messages.length - 1;

        let toolCallsData = [];
        let streamStarted = false;

        // Use fetch with ReadableStream for POST support
        return new Promise(async (resolve) => {
          const statusTimer = setInterval(() => {
            this.streamStatus = this.activeStreamCancellationRequested
              ? 'Cancellation requested — waiting for the local runtime'
              : formatChatStreamStatus(streamActivity);
          }, 1000);
          const observeStreamEvent = (event) => {
            streamActivity = applyChatStreamEvent(streamActivity, event);
            this.streamStatus = formatChatStreamStatus(streamActivity);
          };
          const finishStream = () => {
            clearInterval(statusTimer);
            this.streamStatus = '';
            if (this.activeStreamController === streamController) {
              this.activeStreamController = null;
              this.activeStreamRunId = null;
              this.activeStreamServerCancelable = false;
              this.activeStreamCancellationRequested = false;
            }
          };
          const failStream = (failure, localTransport = false) => {
            finishStream();
            const assistantIndex = this.messages.indexOf(assistantMessage);
            const streamFailure = localTransport
              ? createLocalChatRunFailure(failure.error_code, failure.retryable)
              : normalizeChatRunFailure(failure, assistantMessage.runId);
            if (assistantIndex !== -1) {
              this.messages.splice(assistantIndex, 1, {
                ...assistantMessage,
                streaming: undefined,
                streamFailure,
              });
            }

            if (!streamStarted) {
              const userIndex = this.messages.indexOf(userMessage);
              if (userIndex !== -1) {
                this.messages.splice(userIndex, 1);
              }
            }

            this.error = streamFailure.message;
            this.sending = false;
            resolve({
              success: false,
              error: streamFailure.message,
              error_code: streamFailure.error_code,
              run_id: streamFailure.run_id,
              retryable: streamFailure.retryable,
            });
          };

          try {
            const hasAttachments = attachments.length > 0;
            const response = await fetch(
              `/api/chat/conversations/${this.currentConversation.id}/messages/stream`,
              {
                method: 'POST',
                headers: hasAttachments
                  ? { 'Accept': 'text/event-stream' }
                  : { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
                body: hasAttachments
                  ? this.buildMessageFormData(content, attachments)
                  : JSON.stringify({ content }),
                signal: streamController.signal,
              }
            );

            if (!response.ok) {
              const errorCode = response.status === 429
                ? 'provider_rate_limited'
                : [408, 504].includes(response.status)
                  ? 'provider_timeout'
                  : response.status >= 500
                    ? 'provider_unavailable'
                    : [401, 403].includes(response.status)
                      ? 'provider_authentication_failed'
                      : 'provider_request_failed';
              failStream({
                error_code: errorCode,
                retryable: response.status === 429 || [408, 504].includes(response.status) || response.status >= 500,
              }, true);
              return;
            }

            const reader = response.body.getReader();
            const eventDecoder = new SseEventDecoder();

            while (true) {
              const { done, value } = await reader.read();
              const events = done ? eventDecoder.finish() : eventDecoder.feed(value);

              for (const event of events) {

                try {
                  const data = JSON.parse(event.data);
                  observeStreamEvent(data);

                  switch (data.type) {
                    case 'start':
                      // Update user message with real ID from server
                      streamStarted = true;
                      assistantMessage.runId = normalizeChatRunId(data.run_id);
                      this.activeStreamRunId = assistantMessage.runId;
                      userMessage.id = data.user_message_id;
                      if (data.user_attachments) {
                        userMessage.attachments = data.user_attachments;
                      }
                      break;

                    case 'provider':
                      this.activeStreamServerCancelable = data.fallback_enabled === false
                        && typeof data.provider === 'string'
                        && data.provider.length > 0;
                      break;

                    case 'content':
                      // Append content chunk to assistant message
                      this.messages[assistantMessageIndex].content += data.content;
                      break;

                    case 'content_replace':
                      // Replace accumulated content with cleaned version (JSON extraction)
                      this.messages[assistantMessageIndex].content = data.content;
                      break;

                    case 'tool_call':
                    case 'tool_result':
                    case 'tool_error':
                      toolCallsData = applyChatToolEvent(toolCallsData, data);
                      this.messages[assistantMessageIndex].tool_calls = toolCallsData;
                      break;

                    case 'tool_end':
                      break;

                    case 'stream_interrupted':
                      this.messages[assistantMessageIndex].streamInterruption = {
                        message: data.message || 'Response interrupted after partial output.',
                        fallback_suppressed: data.fallback_suppressed !== false,
                        commit_kind: data.commit_kind || null,
                        correlation_id: data.correlation_id || null,
                      };
                      break;

                    case 'intent_detected':
                      // Store intent info for display
                      this.messages[assistantMessageIndex].intent = {
                        type: data.intent,
                        confidence: data.confidence,
                        reasoning: data.reasoning
                      };
                      break;

                    case 'rag_search':
                      // Legacy: RAG search event during streaming (no longer used)
                      // Sources now sent with 'complete' event to appear after response
                      this.messages[assistantMessageIndex].ragResultsCount = data.results_count;
                      // Don't set ragSources here - wait for complete event
                      break;

                    case 'complete':
                      finishStream();
                      // Update assistant message with real ID and final data
                      // Use Vue-reactive update by replacing the entire message object
                      const completedMessage = {
                        ...this.messages[assistantMessageIndex],
                        id: data.assistant_message_id,
                        tokens: data.tokens,
                        intent: data.intent || this.messages[assistantMessageIndex].intent,
                        attachmentProcessing: data.attachment_processing || [],
                        tool_calls: normalizeChatToolActivities(data.tool_calls || toolCallsData),
                        streaming: undefined, // Remove streaming flag
                      };

                      // RAG sources sent with complete event (after response)
                      if (data.ragSources && data.ragSources.length > 0) {
                        completedMessage.ragSources = data.ragSources;
                        console.log('Setting ragSources on completed message:', data.ragSources);
                      }
                      if (data.assistant_attachments && data.assistant_attachments.length > 0) {
                        completedMessage.attachments = data.assistant_attachments;
                      }
                      if (data.stream_interruption) {
                        completedMessage.streamInterruption = data.stream_interruption;
                      }

                      // Replace the message using splice to guarantee Vue reactivity
                      this.messages.splice(assistantMessageIndex, 1, completedMessage);
                      console.log('Message after splice:', this.messages[assistantMessageIndex]);

                      this.sending = false;

                      resolve({
                        success: true,
                        userMessage: userMessage,
                        assistantMessage: this.messages[assistantMessageIndex],
                        intent: data.intent
                      });
                      return;

                    case 'run_error':
                      failStream(data);
                      return;

                    case 'done':
                      finishStream();
                      // Fallback completion event
                      delete this.messages[assistantMessageIndex].streaming;
                      this.sending = false;
                      resolve({
                        success: true,
                        userMessage: userMessage,
                        assistantMessage: this.messages[assistantMessageIndex],
                      });
                      return;
                  }
                } catch (error) {
                  console.error('Error parsing SSE event:', error);
                }
              }

              if (done) break;
            }

            failStream({
              type: 'run_error',
              schema: 'plos.chat_run_failure.v1',
              run_id: assistantMessage.runId,
              error_code: 'chat_run_failed',
              retryable: true,
              raw_error_included: false,
            }, assistantMessage.runId == null);
          } catch (error) {
            if (error?.name === 'AbortError') {
              finishStream();
              const assistantIndex = this.messages.indexOf(assistantMessage);
              if (assistantIndex !== -1) {
                this.messages.splice(assistantIndex, 1, {
                  ...this.messages[assistantIndex],
                  streaming: undefined,
                  streamInterruption: {
                    message: this.messages[assistantIndex].content
                      ? 'The browser disconnected. Partial output remains only in this browser until the conversation is reloaded.'
                      : 'The browser disconnected before response content was received.',
                    fallback_suppressed: true,
                    commit_kind: this.messages[assistantIndex].content ? 'content' : null,
                    correlation_id: null,
                  },
                });
              }
              this.sending = false;
              resolve({
                success: true,
                cancelled: true,
                userMessage,
                assistantMessage: assistantIndex !== -1 ? this.messages[assistantIndex] : null,
              });
              return;
            }
            console.error('Fetch error:', error);
            failStream({
              error_code: 'provider_connection_failed',
              retryable: true,
            }, true);
          }
        });
      } catch (error) {
        this.error = error.message || 'Failed to send message';
        this.sending = false;
        this.streamStatus = '';
        if (this.activeStreamController === streamController) {
          this.activeStreamController = null;
        }
        return { success: false, error: this.error };
      }
    },

    async cancelActiveStream() {
      if (!this.activeStreamController) {
        return false;
      }
      this.streamStatus = 'Cancelling response';

      if (
        this.activeStreamServerCancelable
        && this.activeStreamRunId
        && this.currentConversation?.id
        && !this.activeStreamCancellationRequested
      ) {
        this.activeStreamCancellationRequested = true;
        try {
          const response = await fetch(
            `/api/chat/conversations/${this.currentConversation.id}/runs/${this.activeStreamRunId}/cancel`,
            {
              method: 'POST',
              headers: { 'Accept': 'application/json' },
            }
          );
          if (response.ok) {
            this.streamStatus = 'Cancellation requested — waiting for the local runtime';
            return true;
          }
        } catch (error) {
          console.warn('Server-aware stream cancellation failed; disconnecting the browser stream.', error);
        }
        this.activeStreamCancellationRequested = false;
      }

      this.activeStreamController.abort();
      return true;
    },

    /**
     * Start a new conversation (creates one and sets as current)
     */
    async startNewConversation(modelMode = 'standard', isPrivate = false, modelOverride = false) {
      return await this.createConversation(null, modelMode, isPrivate || modelMode === 'uncensored', modelOverride);
    },

    /**
     * Reset current conversation state
     */
    resetCurrentConversation() {
      invalidateConversationLoads(this);
      this.currentConversation = null;
      this.messages = [];
      this.loading = false;
      this.streamStatus = '';
      this.activeStreamController?.abort();
      this.activeStreamController = null;
      this.activeStreamRunId = null;
      this.activeStreamServerCancelable = false;
      this.activeStreamCancellationRequested = false;
      this.error = null;
    },

    /**
     * Get system prompt info for current conversation
     */
    async getSystemPromptInfo() {
      if (!this.currentConversation) {
        return { success: false, error: 'No active conversation' };
      }

      this.loading = true;
      this.error = null;

      try {
        // Get conversation system prompt
        const convResponse = await api.get(`/system-prompts/conversations/${this.currentConversation.id}`);
        // Get default system prompt
        const defaultResponse = await api.get('/system-prompts/default');

        if (convResponse.success && defaultResponse.success) {
          return {
            success: true,
            conversationPrompt: convResponse.system_prompt,
            isUsingDefault: convResponse.is_using_default,
            defaultPrompt: defaultResponse.default_system_prompt,
            hardcodedFallback: defaultResponse.hardcoded_fallback,
            description: defaultResponse.description,
          };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch system prompt info';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Update system prompt for current conversation
     */
    async updateSystemPrompt(systemPrompt) {
      if (!this.currentConversation) {
        return { success: false, error: 'No active conversation' };
      }

      this.loading = true;
      this.error = null;

      try {
        const response = await api.put(
          `/system-prompts/conversations/${this.currentConversation.id}`,
          { system_prompt: systemPrompt || null }
        );

        if (response.success) {
          // Update local conversation object
          this.currentConversation.system_prompt = systemPrompt;
          return { success: true };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to update system prompt';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Clear system prompt for current conversation (revert to default)
     */
    async clearSystemPrompt() {
      if (!this.currentConversation) {
        return { success: false, error: 'No active conversation' };
      }

      this.loading = true;
      this.error = null;

      try {
        const response = await api.delete(
          `/system-prompts/conversations/${this.currentConversation.id}`
        );

        if (response.success) {
          // Update local conversation object
          this.currentConversation.system_prompt = null;
          return { success: true };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to clear system prompt';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Save a message to RAG knowledge base
     */
    async saveMessageToRAG(messageId, options = {}) {
      if (!this.currentConversation) {
        return { success: false, error: 'No active conversation' };
      }

      try {
        const response = await api.post(
          `/chat/conversations/${this.currentConversation.id}/messages/${messageId}/save-to-rag`,
          {
            title: options.title || null,
            designation: options.designation || 'chat_saved',
          }
        );

        if (response.success) {
          // Mark message as saved in local state
          const messageIndex = this.messages.findIndex(m => m.id === messageId);
          if (messageIndex !== -1) {
            this.messages[messageIndex].savedToRAG = true;
            this.messages[messageIndex].ragDocumentId = response.document?.id;
          }
          return { success: true, document: response.document };
        }
        return { success: false, error: response.message };
      } catch (error) {
        const errorMsg = error.response?.data?.message || 'Failed to save to knowledge base';
        return { success: false, error: errorMsg };
      }
    },

    async recordResponseFeedback(messageId, value, reason = null) {
      if (!this.currentConversation) {
        return { success: false, error: 'No active conversation' };
      }

      try {
        const response = await api.post(
          `/chat/conversations/${this.currentConversation.id}/messages/${messageId}/feedback`,
          { value, reason }
        );

        if (response.success) {
          const message = this.messages.find(item => item.id === messageId);
          if (message) {
            message.response_feedback = response.feedback;
          }
          return { success: true, feedback: response.feedback };
        }

        return { success: false, error: response.message || 'Failed to record feedback' };
      } catch (error) {
        return {
          success: false,
          error: error.response?.data?.message || 'Failed to record feedback',
        };
      }
    },

    buildMessageFormData(content, attachments = []) {
      const formData = new FormData();
      formData.append('content', content || '');
      attachments.forEach((file) => formData.append('attachments[]', file));
      return formData;
    },

    attachmentOnlyContent(attachments = []) {
      if (!attachments.length) return '';
      return `Please review the attached file(s): ${attachments.map(file => file.name).join(', ')}`;
    },

    fileToAttachmentPayload(file, index) {
      return {
        id: `local-${Date.now()}-${index}`,
        name: file.name,
        mime_type: file.type || 'application/octet-stream',
        media_type: this.classifyFileMediaType(file),
        capability: 'pending_upload',
        size_bytes: file.size || 0,
        processing_status: 'uploading',
        local: true,
      };
    },

    classifyFileMediaType(file) {
      const type = (file.type || '').toLowerCase();
      const name = (file.name || '').toLowerCase();
      if (type.startsWith('image/') || /\.(jpg|jpeg|png|gif|bmp|tiff|tif|webp|heic)$/.test(name)) return 'image';
      if (type.startsWith('audio/') || /\.(mp3|wav|flac|aac|m4a|ogg|wma|aiff)$/.test(name)) return 'audio';
      if (type.startsWith('video/') || /\.(mp4|mkv|avi|mov|wmv|flv|webm|m4v|mpg|mpeg)$/.test(name)) return 'video';
      if (type === 'application/pdf' || name.endsWith('.pdf')) return 'pdf';
      if (type.startsWith('text/') || /\.(txt|md|csv|json|xml|html|htm|log)$/.test(name)) return 'text';
      if (/\.(doc|docx|odt|rtf|xls|xlsx|ppt|pptx)$/.test(name)) return 'document';
      return 'file';
    },
  },
});

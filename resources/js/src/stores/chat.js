import { defineStore } from 'pinia';
import api from '../utils/api';

export const useChatStore = defineStore('chat', {
  state: () => ({
    conversations: [],
    currentConversation: null,
    messages: [],
    loading: false,
    sending: false,
    error: null,
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
    async createConversation(title = null, modelMode = 'standard', isPrivate = false) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.post('/chat/conversations', {
          title,
          model_mode: modelMode,
          is_private: isPrivate || modelMode === 'uncensored',
        });
        if (response.success) {
          this.currentConversation = response.conversation;
          this.messages = [];
          await this.fetchConversations();
          return { success: true, conversation: response.conversation };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to create conversation';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Load a conversation with its messages
     */
    async loadConversation(id) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.get(`/chat/conversations/${id}`);
        if (response.success) {
          this.currentConversation = response.conversation;
          // Parse metadata to extract ragSources and intent for each message
          this.messages = response.messages.map(msg => {
            if (msg.metadata) {
              try {
                const meta = typeof msg.metadata === 'string' ? JSON.parse(msg.metadata) : msg.metadata;
                if (meta.ragSources) {
                  msg.ragSources = meta.ragSources;
                }
                if (meta.intent) {
                  msg.intent = meta.intent;
                }
              } catch (e) {
                console.warn('Failed to parse message metadata:', e);
              }
            }
            return msg;
          });
          return { success: true };
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to load conversation';
        return { success: false, error: this.error };
      } finally {
        this.loading = false;
      }
    },

    /**
     * Clear a conversation (soft delete)
     */
    async clearConversation(id) {
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
    async sendMessage(content) {
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
        const response = await api.post(
          `/chat/conversations/${this.currentConversation.id}/messages`,
          { content }
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
    async sendMessageStream(content) {
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
        // Add user message optimistically
        const userMessage = {
          id: Date.now(), // Temporary ID
          conversation_id: this.currentConversation.id,
          role: 'user',
          content: content,
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

        // Use fetch with ReadableStream for POST support
        return new Promise(async (resolve, reject) => {
          try {
            const response = await fetch(
              `/api/chat/conversations/${this.currentConversation.id}/messages/stream`,
              {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'Accept': 'text/event-stream',
                },
                body: JSON.stringify({ content }),
              }
            );

            if (!response.ok) {
              throw new Error(`HTTP error! status: ${response.status}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
              const { done, value } = await reader.read();

              if (done) {
                this.sending = false;
                break;
              }

              // Decode chunk and add to buffer
              buffer += decoder.decode(value, { stream: true });

              // Process complete SSE messages in buffer
              const lines = buffer.split('\n');
              buffer = lines.pop() || ''; // Keep incomplete line in buffer

              for (const line of lines) {
                if (!line.startsWith('data: ')) continue;

                try {
                  const data = JSON.parse(line.slice(6)); // Remove 'data: ' prefix

                  switch (data.type) {
                    case 'start':
                      // Update user message with real ID from server
                      userMessage.id = data.user_message_id;
                      break;

                    case 'content':
                      // Append content chunk to assistant message
                      this.messages[assistantMessageIndex].content += data.content;
                      break;

                    case 'content_replace':
                      // Replace accumulated content with cleaned version (JSON extraction)
                      this.messages[assistantMessageIndex].content = data.content;
                      break;

                    case 'tool_start':
                      toolCallsData = [];
                      break;

                    case 'tool_call':
                      toolCallsData.push({
                        server: data.server,
                        tool: data.tool,
                        arguments: data.arguments,
                      });
                      break;

                    case 'tool_end':
                      // Update assistant message with tool calls
                      if (toolCallsData.length > 0) {
                        this.messages[assistantMessageIndex].tool_calls = JSON.stringify(toolCallsData);
                      }
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
                      // Update assistant message with real ID and final data
                      // Use Vue-reactive update by replacing the entire message object
                      const completedMessage = {
                        ...this.messages[assistantMessageIndex],
                        id: data.assistant_message_id,
                        tokens: data.tokens,
                        intent: data.intent || this.messages[assistantMessageIndex].intent,
                        streaming: undefined, // Remove streaming flag
                      };

                      // RAG sources sent with complete event (after response)
                      if (data.ragSources && data.ragSources.length > 0) {
                        completedMessage.ragSources = data.ragSources;
                        console.log('Setting ragSources on completed message:', data.ragSources);
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

                    case 'error':
                      this.error = data.message || data.content || 'Streaming error';
                      this.sending = false;
                      reject({ success: false, error: this.error });
                      return;

                    case 'done':
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
            }
          } catch (error) {
            console.error('Fetch error:', error);
            this.error = 'Connection error during streaming';
            this.sending = false;
            reject({ success: false, error: this.error });
          }
        });
      } catch (error) {
        this.error = error.message || 'Failed to send message';
        this.sending = false;
        return { success: false, error: this.error };
      }
    },

    /**
     * Start a new conversation (creates one and sets as current)
     */
    async startNewConversation(modelMode = 'standard') {
      return await this.createConversation(null, modelMode, modelMode === 'uncensored');
    },

    /**
     * Reset current conversation state
     */
    resetCurrentConversation() {
      this.currentConversation = null;
      this.messages = [];
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
  },
});

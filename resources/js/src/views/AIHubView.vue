<template>
  <div class="ai-hub-container flex bg-[#1a1a1a]">
    <!-- Left Sidebar -->
    <div class="w-72 bg-[#2d2d2d] border-r border-[#444] flex flex-col">
      <!-- Mode Selector -->
      <div class="p-3 border-b border-[#444]">
        <div class="flex gap-1 bg-[#1a1a1a] rounded-lg p-1">
          <button
            @click="mode = 'chat'"
            :class="[
              'flex-1 px-3 py-2 rounded-md text-sm font-medium transition',
              mode === 'chat' ? 'bg-accent text-white' : 'text-[#95a5a6] hover:text-[#e0e0e0]'
            ]"
          >
            Chat
          </button>
          <button
            @click="mode = 'research'"
            :class="[
              'flex-1 px-3 py-2 rounded-md text-sm font-medium transition relative',
              mode === 'research' ? 'bg-accent text-white' : 'text-[#95a5a6] hover:text-[#e0e0e0]'
            ]"
          >
            Research
            <span
              v-if="researchStats.total_pending > 0"
              class="absolute -top-1 -right-1 bg-[#e74c3c] text-white text-xs rounded-full px-1.5 py-0.5 min-w-[18px] text-center"
            >
              {{ researchStats.total_pending }}
            </span>
          </button>
        </div>
      </div>

      <!-- Chat Mode Sidebar -->
      <div v-if="mode === 'chat'" class="flex-1 flex flex-col overflow-hidden">
        <!-- New Chat Button + Mode Selector -->
        <div class="p-3 border-b border-[#444] space-y-2">
          <div class="flex gap-2">
            <button
              @click="handleNewConversation"
              class="btn-primary flex-1 flex items-center justify-center gap-2"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
              </svg>
              New Chat
            </button>
          </div>
          <select
            v-model="chatMode"
            class="w-full bg-[#1a1a1a] text-[#e0e0e0] border border-[#555] rounded-md px-3 py-1.5 text-xs focus:border-accent focus:outline-none"
          >
            <option value="standard">Standard</option>
            <option value="fast">Fast</option>
            <option value="quality">Quality</option>
            <option value="uncensored">Uncensored (Private)</option>
          </select>
        </div>

        <!-- Conversation List -->
        <div class="flex-1 overflow-y-auto">
          <div v-if="chatStore.loading && !chatStore.conversations.length" class="p-4 text-center text-[#95a5a6]">
            Loading...
          </div>
          <div v-else-if="!chatStore.conversations.length" class="p-4 text-center text-[#95a5a6]">
            No conversations yet
          </div>
          <div v-else class="divide-y divide-[#444]">
            <button
              v-for="conversation in chatStore.conversations"
              :key="conversation.id"
              @click="handleSelectConversation(conversation.id)"
              :class="[
                'w-full px-4 py-3 text-left hover:bg-[#34495e] transition-colors',
                chatStore.currentConversation?.id === conversation.id ? 'bg-[#34495e] border-l-4 border-accent' : ''
              ]"
            >
              <div class="font-medium text-sm text-[#e0e0e0] truncate flex items-center gap-1">
                <span v-if="conversation.is_private" class="text-[#e74c3c]" title="Private - no history saved">&#128274;</span>
                {{ conversation.title || 'New Conversation' }}
              </div>
              <div class="text-xs text-[#95a5a6] mt-1 flex justify-between">
                <span>{{ formatDate(conversation.created_at) }}</span>
                <span v-if="conversation.model_mode && conversation.model_mode !== 'standard'" class="text-[#f39c12]">{{ conversation.model_mode }}</span>
              </div>
            </button>
          </div>
        </div>

        <!-- Research Quick Stats (always visible) -->
        <div class="p-3 border-t border-[#444] bg-[#252525]">
          <div class="text-xs text-[#95a5a6] mb-2">Research Status</div>
          <div class="grid grid-cols-2 gap-2 text-center">
            <div class="bg-[#1a1a1a] rounded p-2">
              <div class="text-sm font-bold text-[#e74c3c]">{{ researchStats.total_pending || 0 }}</div>
              <div class="text-xs text-[#95a5a6]">Pending</div>
            </div>
            <div class="bg-[#1a1a1a] rounded p-2">
              <div class="text-sm font-bold text-accent">{{ topicStats.total_topics || 0 }}</div>
              <div class="text-xs text-[#95a5a6]">Topics</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Research Mode Sidebar -->
      <div v-else class="flex-1 flex flex-col overflow-hidden">
        <!-- Research Actions -->
        <div class="p-3 border-b border-[#444] space-y-2">
          <button @click="openTopicModal" class="btn-secondary w-full flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            New Topic
          </button>
        </div>

        <!-- Research Stats -->
        <div class="p-3 border-b border-[#444]">
          <div class="grid grid-cols-3 gap-2">
            <div class="bg-[#1a1a1a] rounded p-2 border-l-2 border-[#e74c3c]">
              <div class="text-lg font-bold text-[#e0e0e0]">{{ researchStats.total_pending || 0 }}</div>
              <div class="text-xs text-[#95a5a6]">Pending</div>
            </div>
            <div class="bg-[#1a1a1a] rounded p-2 border-l-2 border-[#27ae60]">
              <div class="text-lg font-bold text-[#e0e0e0]">{{ researchStats.approved_today || 0 }}</div>
              <div class="text-xs text-[#95a5a6]">Approved</div>
            </div>
            <div class="bg-[#1a1a1a] rounded p-2 border-l-2 border-accent">
              <div class="text-lg font-bold text-[#e0e0e0]">{{ topicStats.total_topics || 0 }}</div>
              <div class="text-xs text-[#95a5a6]">Topics</div>
            </div>
          </div>
        </div>

        <!-- Research Tabs -->
        <div class="flex border-b border-[#444]">
          <button
            v-for="tab in researchTabs"
            :key="tab.id"
            @click="researchTab = tab.id"
            :class="[
              'flex-1 px-2 py-2 text-xs font-medium transition relative',
              researchTab === tab.id ? 'text-accent border-b-2 border-accent' : 'text-[#95a5a6] hover:text-[#e0e0e0]'
            ]"
          >
            {{ tab.label }}
            <span
              v-if="tab.id === 'queue' && researchStats.total_pending > 0"
              class="ml-1 bg-[#e74c3c] text-white text-xs rounded-full px-1"
            >
              {{ researchStats.total_pending }}
            </span>
          </button>
        </div>

        <!-- Quick Chat Access -->
        <div class="p-3 border-t border-[#444] bg-[#252525] mt-auto">
          <div class="text-xs text-[#95a5a6] mb-2">Quick AI Chat</div>
          <button
            @click="mode = 'chat'"
            class="w-full px-3 py-2 bg-[#1a1a1a] rounded text-left text-sm text-[#e0e0e0] hover:bg-[#34495e] transition"
          >
            {{ chatStore.currentConversation?.title || 'Start a conversation...' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col bg-[#1a1a1a] overflow-hidden">
      <!-- ================== CHAT MODE ================== -->
      <template v-if="mode === 'chat'">
        <!-- No Conversation Selected -->
        <div v-if="!chatStore.currentConversation" class="flex-1 flex items-center justify-center text-[#95a5a6]">
          <div class="text-center">
            <svg class="w-16 h-16 mx-auto mb-4 text-[#444]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
            <p class="text-lg">Select a conversation or start a new one</p>
          </div>
        </div>

        <!-- Chat Active -->
        <template v-else>
          <!-- Chat Header -->
          <div class="bg-[#2d2d2d] border-b border-[#444] px-6 py-3 flex items-center justify-between">
            <div class="flex-1">
              <h2 class="text-lg font-semibold text-[#e0e0e0] flex items-center gap-2">
                {{ chatStore.currentConversation.title || 'New Conversation' }}
                <span
                  v-if="chatStore.currentConversation.is_private"
                  class="text-xs bg-[#e74c3c] text-white px-2 py-0.5 rounded-full font-normal"
                >Private</span>
                <span
                  v-if="chatStore.currentConversation.model_mode === 'uncensored'"
                  class="text-xs bg-[#f39c12] text-[#1a1a1a] px-2 py-0.5 rounded-full font-normal"
                >Uncensored</span>
              </h2>
              <p class="text-sm text-[#95a5a6]">
                {{ chatStore.currentConversation.is_private ? 'Messages not saved' : chatStore.messages.length + ' messages' }}
                <span v-if="lastDetectedIntent" class="ml-2 text-accent">
                  {{ getIntentEmoji(lastDetectedIntent.intent) }} {{ lastDetectedIntent.intent.replace(/_/g, ' ') }}
                </span>
              </p>
            </div>
            <div class="flex gap-2">
              <button
                @click="showSystemPromptModal = true"
                class="px-3 py-1.5 text-xs text-accent hover:bg-[#34495e] rounded transition flex items-center gap-1"
              >
                System
              </button>
              <button
                @click="handleClearHistory"
                class="px-3 py-1.5 text-xs text-[#f39c12] hover:bg-[#34495e] rounded transition"
              >
                Clear
              </button>
              <button
                @click="handleClearConversation"
                class="px-3 py-1.5 text-xs text-[#e74c3c] hover:bg-[#34495e] rounded transition"
              >
                Delete
              </button>
            </div>
          </div>

          <!-- Messages Area -->
          <div ref="messagesContainer" class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
            <div v-if="!chatStore.messages.length" class="text-center text-[#95a5a6] mt-8">
              Start a conversation by typing a message below
            </div>

            <div
              v-for="message in chatStore.messages"
              :key="message.id"
              :class="['flex', message.role === 'user' ? 'justify-end' : 'justify-start']"
            >
              <div
                :class="[
                  'max-w-3xl rounded-lg px-4 py-3',
                  message.role === 'user'
                    ? 'bg-accent text-white'
                    : 'bg-[#2d2d2d] border border-[#444] text-[#e0e0e0]'
                ]"
              >
                <!-- Intent Badge -->
                <div v-if="message.role === 'assistant' && message.intent" class="mb-2 flex items-center gap-2">
                  <span class="text-xs px-2 py-0.5 rounded-full bg-[#34495e] text-[#95a5a6]">
                    {{ getIntentEmoji(message.intent.type || message.intent.intent) }}
                    {{ (message.intent.type || message.intent.intent || '').replace(/_/g, ' ') }}
                  </span>
                </div>

                <div class="prose prose-sm max-w-none" v-html="renderMarkdown(message.content)" @click="handleCodeCopy"></div>

                <!-- RAG Sources Display (clickable to view full content) -->
                <div v-if="message.ragSources && message.ragSources.length > 0" class="mt-3 pt-3 border-t border-[#444]">
                  <div class="text-xs font-semibold text-[#95a5a6] mb-2">📚 Sources Used:</div>
                  <div class="space-y-1">
                    <div
                      v-for="source in message.ragSources"
                      :key="source.num"
                      class="text-xs flex items-start gap-2"
                    >
                      <span class="text-[#3498db] font-mono">[{{ source.num }}]</span>
                      <div class="flex-1">
                        <button
                          @click="handleViewRAGDoc(source)"
                          class="text-[#e0e0e0] hover:text-accent hover:underline text-left"
                          :title="'Click to view full content'"
                        >
                          {{ source.title }}
                        </button>
                        <span v-if="source.type" class="text-[#666] ml-2">({{ source.type }})</span>
                        <a
                          v-if="source.url"
                          :href="source.url"
                          target="_blank"
                          class="ml-2 text-accent hover:underline"
                          @click.stop
                        >
                          🔗
                        </a>
                        <span v-if="source.similarity" class="text-[#666] ml-2">
                          {{ Math.round(source.similarity * 100) }}% match
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Save to RAG -->
                <div v-if="message.role === 'assistant' && !message.streaming" class="mt-3 pt-2 border-t border-[#444]">
                  <button
                    v-if="!message.savedToRAG"
                    @click="handleSaveToRAG(message)"
                    :disabled="savingToRAG === message.id"
                    class="flex items-center gap-2 text-xs text-[#95a5a6] hover:text-accent transition"
                  >
                    {{ savingToRAG === message.id ? 'Saving...' : 'Save to Knowledge Base' }}
                  </button>
                  <span v-else class="text-xs text-[#2ecc71]">Saved</span>
                </div>
              </div>
            </div>

            <!-- Thinking Indicator -->
            <div v-if="chatStore.sending" class="flex justify-start">
              <div class="max-w-3xl rounded-lg px-4 py-3 bg-[#2d2d2d] border border-[#444]">
                <div class="flex items-center gap-2 text-[#95a5a6]">
                  <div class="animate-pulse">.</div>
                  <div class="animate-pulse">.</div>
                  <div class="animate-pulse">.</div>
                  <span class="ml-2">Thinking...</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Input Area -->
          <div class="bg-[#2d2d2d] border-t border-[#444] px-6 py-4">
            <form @submit.prevent="handleSendMessage" class="flex gap-2">
              <textarea
                v-model="messageInput"
                @keydown.enter.exact.prevent="handleSendMessage"
                placeholder="Type your message... (Enter to send)"
                class="flex-1 px-4 py-3 bg-[#1a1a1a] border border-[#444] rounded-lg focus:ring-2 focus:ring-accent resize-none text-[#e0e0e0] placeholder-[#95a5a6]"
                rows="2"
                :disabled="chatStore.sending"
              ></textarea>
              <button
                type="submit"
                :disabled="!messageInput.trim() || chatStore.sending"
                class="btn-primary px-6 py-3 disabled:bg-[#444] disabled:cursor-not-allowed"
              >
                Send
              </button>
            </form>
          </div>
        </template>
      </template>

      <!-- ================== RESEARCH MODE ================== -->
      <template v-else>
        <div class="flex-1 overflow-y-auto p-6">
          <!-- Loading -->
          <div v-if="researchLoading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            <p class="mt-2 text-[#95a5a6]">Loading...</p>
          </div>

          <!-- ===== REVIEW QUEUE TAB ===== -->
          <div v-else-if="researchTab === 'queue'" class="space-y-4">
            <div v-if="reviewQueue.length === 0" class="text-center py-12 bg-[#2d2d2d] rounded-lg border-2 border-[#444]">
              <svg class="w-16 h-16 mx-auto text-[#27ae60] mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <p class="text-[#e0e0e0] font-medium">All caught up!</p>
              <p class="text-sm text-[#95a5a6] mt-2">No items pending review</p>
            </div>

            <!-- Research Result Cards -->
            <div v-for="item in reviewQueue" :key="item.id" class="bg-[#2d2d2d] rounded-lg border border-[#444] overflow-hidden">
              <div class="p-4 border-b border-[#444] flex justify-between items-start">
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-1 flex-wrap">
                    <span class="bg-accent text-white text-xs px-2 py-0.5 rounded">TOPIC</span>
                    <span class="text-[#e0e0e0] font-medium">{{ item.parent_name }}</span>
                  </div>
                  <div class="text-sm text-[#95a5a6]">{{ formatDate(item.created_at) }}</div>
                </div>
                <div class="flex gap-2">
                  <button @click="approveItem(item)" :disabled="processingItem === item.id" class="btn-success-sm">Approve</button>
                  <button @click="rejectItem(item)" :disabled="processingItem === item.id" class="btn-danger-sm">Reject</button>
                </div>
              </div>

              <div class="p-4 bg-[#252525]">
                <div class="prose prose-invert prose-sm max-w-none text-[#e0e0e0] max-h-64 overflow-y-auto" v-html="renderResearchMarkdown(item.content)"></div>
              </div>
            </div>
          </div>

          <!-- ===== TOPICS TAB ===== -->
          <div v-else-if="researchTab === 'topics'" class="space-y-4">
            <div v-if="topics.length === 0" class="text-center py-12 bg-[#2d2d2d] rounded-lg border-2 border-[#444]">
              <p class="text-[#95a5a6]">No research topics yet</p>
              <button @click="openTopicModal" class="btn-primary mt-4">Create First Topic</button>
            </div>

            <div v-for="topic in topics" :key="topic.id" class="bg-[#2d2d2d] rounded-lg border border-[#444] p-4">
              <div class="flex justify-between items-start">
                <div class="flex-1">
                  <div class="flex items-center gap-2">
                    <h3 class="text-lg font-bold text-[#e0e0e0]">{{ topic.description }}</h3>
                    <span v-if="!topic.is_active" class="text-xs bg-[#f39c12] text-white px-2 py-0.5 rounded">Inactive</span>
                    <span v-if="topic.is_due" class="text-xs bg-accent text-white px-2 py-0.5 rounded">Due</span>
                    <span v-if="topic.pending_results_count > 0" class="text-xs bg-[#9b59b6] text-white px-2 py-0.5 rounded">
                      {{ topic.pending_results_count }} pending
                    </span>
                  </div>
                  <p class="text-sm text-[#95a5a6] mt-1">{{ topic.frequency_label }} | Last ran: {{ formatDate(topic.last_ran_at) }}</p>
                </div>
                <div class="flex gap-1">
                  <button @click="editTopic(topic)" class="btn-icon" title="Edit">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                  </button>
                  <button @click="toggleTopic(topic)" class="btn-icon">
                    <svg :class="topic.is_active ? 'text-[#27ae60]' : 'text-[#95a5a6]'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                  </button>
                  <button @click="confirmDeleteTopic(topic)" class="btn-icon text-[#e74c3c]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Sources and Rules tabs removed — internal infrastructure, not user-facing -->
        </div>
      </template>
    </div>

    <!-- ================== MODALS ================== -->

    <!-- System Prompt Modal -->
    <div v-if="showSystemPromptModal" class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50 p-4" @click.self="showSystemPromptModal = false">
      <div class="bg-[#2d2d2d] rounded-lg shadow-2xl max-w-lg w-full max-h-[80vh] overflow-y-auto border border-[#444]">
        <div class="border-b border-[#444] px-6 py-4 flex items-center justify-between">
          <h3 class="text-lg font-semibold text-[#e0e0e0]">System Prompt</h3>
          <button @click="showSystemPromptModal = false" class="text-[#95a5a6] hover:text-[#e0e0e0]">X</button>
        </div>
        <div class="px-6 py-4">
          <textarea
            v-model="editedSystemPrompt"
            class="form-textarea"
            rows="6"
            placeholder="Custom system prompt for this conversation..."
          ></textarea>
        </div>
        <div class="border-t border-[#444] px-6 py-4 flex justify-end gap-2">
          <button @click="showSystemPromptModal = false" class="btn-secondary">Cancel</button>
          <button @click="handleSaveSystemPrompt" class="btn-primary">Save</button>
        </div>
      </div>
    </div>

    <!-- RAG Document Viewer Modal -->
    <div v-if="showRAGDocModal" class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50 p-4" @click.self="showRAGDocModal = false">
      <div class="bg-[#2d2d2d] rounded-lg shadow-2xl max-w-4xl w-full max-h-[85vh] overflow-hidden border border-[#444] flex flex-col">
        <!-- Modal Header -->
        <div class="border-b border-[#444] px-6 py-4 flex items-center justify-between flex-shrink-0">
          <div class="flex-1 min-w-0">
            <h3 class="text-lg font-semibold text-[#e0e0e0] truncate">{{ viewingRAGDoc?.title || 'Document' }}</h3>
            <div class="flex items-center gap-3 text-xs text-[#95a5a6] mt-1">
              <span v-if="viewingRAGDoc?.document_type" class="bg-[#34495e] px-2 py-0.5 rounded">{{ viewingRAGDoc.document_type }}</span>
              <span v-if="viewingRAGDoc?.source_type">Source: {{ viewingRAGDoc.source_type }}</span>
            </div>
          </div>
          <button @click="showRAGDocModal = false" class="text-[#95a5a6] hover:text-[#e0e0e0] transition-colors ml-4">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <!-- Modal Content -->
        <div class="flex-1 overflow-y-auto p-6">
          <div v-if="ragDocLoading" class="flex items-center justify-center py-12">
            <div class="animate-spin w-8 h-8 border-2 border-accent border-t-transparent rounded-full"></div>
          </div>
          <div v-else-if="viewingRAGDoc" class="prose prose-invert prose-sm max-w-none">
            <div v-html="renderMarkdown(viewingRAGDoc.content || 'No content available')"></div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="border-t border-[#444] px-6 py-3 flex items-center justify-between flex-shrink-0 bg-[#252525]">
          <div class="text-xs text-[#95a5a6]">
            <span v-if="viewingRAGDoc?.media_url">
              <a :href="viewingRAGDoc.media_url" target="_blank" class="text-accent hover:underline">View Original</a>
            </span>
          </div>
          <button @click="showRAGDocModal = false" class="btn-secondary text-sm">Close</button>
        </div>
      </div>
    </div>

    <!-- AI-Guided Topic Refine Modal -->
    <TopicRefineModal
      :visible="showRefineModal"
      :existing-topic="refineExistingTopic"
      @saved="onRefineSaved"
      @close="showRefineModal = false"
      @skip-to-simple="onRefineSkipToSimple"
    />

    <!-- Topic Modal (simple form fallback) -->
    <div v-if="showTopicModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-[#2d2d2d] rounded-lg p-6 w-full max-w-lg border-2 border-[#444] max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-bold text-[#e0e0e0] mb-4">{{ editingTopic ? 'Edit Topic' : 'New Topic' }}</h3>
        <div class="space-y-4">
          <div>
            <label class="label">Description</label>
            <input v-model="topicForm.description" type="text" class="form-input" placeholder="e.g., Daily News Brief">
          </div>
          <div>
            <label class="label">Topic Content</label>
            <textarea v-model="topicForm.topic_content" class="form-textarea" rows="4" placeholder="What should be researched..."></textarea>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Frequency</label>
              <select v-model="topicForm.frequency" class="form-select">
                <option value="once">One-time</option>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="biannually">Biannually</option>
              </select>
            </div>
            <div>
              <label class="label">RAG Category</label>
              <div v-if="!showNewRagCategoryInput">
                <div class="flex gap-2">
                  <select v-model="topicForm.rag_category" class="form-select flex-1">
                    <option value="">Auto-generate from description</option>
                    <option v-for="(label, value) in ragCategories" :key="value" :value="value">
                      {{ label }}
                    </option>
                  </select>
                  <button
                    type="button"
                    @click="showNewRagCategoryInput = true"
                    class="px-3 py-2 bg-accent text-white rounded text-sm hover:bg-accent/80"
                    title="Add new category"
                  >
                    +
                  </button>
                </div>
              </div>
              <div v-else class="flex gap-2">
                <input
                  v-model="newRagCategory"
                  type="text"
                  class="form-input flex-1"
                  placeholder="New category name"
                  @keyup.enter="addNewRagCategory"
                >
                <button
                  type="button"
                  @click="addNewRagCategory"
                  class="px-3 py-2 bg-[#27ae60] text-white rounded text-sm hover:bg-[#27ae60]/80"
                >
                  Add
                </button>
                <button
                  type="button"
                  @click="showNewRagCategoryInput = false; newRagCategory = ''"
                  class="px-3 py-2 bg-[#444] text-white rounded text-sm hover:bg-[#555]"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <input v-model="topicForm.is_active" type="checkbox" class="form-checkbox">
            <label class="text-[#e0e0e0]">Active</label>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-6">
          <button @click="closeTopicModal" class="btn-secondary">Cancel</button>
          <button @click="saveTopic" :disabled="savingTopic" class="btn-primary">{{ savingTopic ? 'Saving...' : 'Save' }}</button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick, watch } from 'vue';
import { useChatStore } from '../stores/chat';
import TopicRefineModal from '../components/TopicRefineModal.vue';
import MarkdownIt from 'markdown-it';
import hljs from '@/utils/highlight.js';

const chatStore = useChatStore();

// Mode: 'chat' or 'research'
const mode = ref('chat');

// Research tabs
const researchTabs = [
  { id: 'queue', label: 'Queue' },
  { id: 'topics', label: 'Topics' },
];
const researchTab = ref('queue');

// Chat state
const chatMode = ref('standard');
const messageInput = ref('');
const messagesContainer = ref(null);
const lastDetectedIntent = ref(null);
const showSystemPromptModal = ref(false);
const editedSystemPrompt = ref('');
const savingToRAG = ref(null);

// RAG Document viewer modal state
const showRAGDocModal = ref(false);
const viewingRAGDoc = ref(null);
const ragDocLoading = ref(false);

// Research state
const researchLoading = ref(true);
const reviewQueue = ref([]);
const researchStats = ref({});
const topics = ref([]);
const topicStats = ref({});
// sources and rules refs removed — tabs dropped (internal infrastructure)
const ragCategories = ref({});
const showNewRagCategoryInput = ref(false);
const newRagCategory = ref('');
const processingItem = ref(null);

// Topic modal
const showTopicModal = ref(false);
const editingTopic = ref(null);
const savingTopic = ref(false);
const topicForm = reactive({
  description: '',
  topic_content: '',
  frequency: 'daily',
  is_active: true,
  rag_category: '',
});

// AI-guided topic refinement
const showRefineModal = ref(false);
const refineExistingTopic = ref(null);

// Markdown
const md = new MarkdownIt({
  highlight: function (str, lang) {
    if (lang && hljs.getLanguage(lang)) {
      try {
        return hljs.highlight(str, { language: lang }).value;
      } catch (__) {}
    }
    return '';
  }
});

const renderMarkdown = (content) => md.render(content || '');

const renderResearchMarkdown = (text) => {
  if (!text) return '';
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/^### (.+)$/gm, '<h3 class="text-lg font-bold text-[#e0e0e0] mt-4 mb-2">$1</h3>')
    .replace(/^## (.+)$/gm, '<h2 class="text-xl font-bold text-[#e0e0e0] mt-4 mb-2">$1</h2>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>')
    .replace(/\n/g, '<br />');
};

// Date formatting
const formatDate = (dateStr) => {
  if (!dateStr) return 'Never';
  const date = new Date(dateStr);
  const now = new Date();
  const diff = now - date;
  const days = Math.floor(diff / (1000 * 60 * 60 * 24));
  if (days === 0) return 'Today';
  if (days === 1) return 'Yesterday';
  if (days < 7) return `${days} days ago`;
  return date.toLocaleDateString();
};

// Intent emoji
const getIntentEmoji = (intent) => {
  const emojis = {
    'workflow_execution': '*',
    'rag_search': '?',
    'mcp_tool': '#',
    'general_conversation': '>'
  };
  return emojis[intent] || '>';
};

// Chat handlers
const handleNewConversation = async () => {
  const result = await chatStore.startNewConversation(chatMode.value);
  if (result.success) messageInput.value = '';
};

const handleSelectConversation = async (id) => {
  await chatStore.loadConversation(id);
  await nextTick();
  scrollToBottom();
};

const handleSendMessage = async () => {
  if (!messageInput.value.trim() || chatStore.sending) return;
  const content = messageInput.value;
  messageInput.value = '';
  const result = await chatStore.sendMessageStream(content);
  if (result.success && result.intent) {
    lastDetectedIntent.value = result.intent;
  }
  await nextTick();
  scrollToBottom();
};

const handleClearConversation = async () => {
  if (!chatStore.currentConversation) return;
  if (confirm('Delete this conversation?')) {
    await chatStore.clearConversation(chatStore.currentConversation.id);
  }
};

const handleClearHistory = async () => {
  if (!chatStore.currentConversation) return;
  if (confirm('Clear all messages?')) {
    await chatStore.clearMessageHistory(chatStore.currentConversation.id);
    lastDetectedIntent.value = null;
  }
};

const handleSaveToRAG = async (message) => {
  if (!message || savingToRAG.value === message.id) return;
  savingToRAG.value = message.id;
  try {
    await chatStore.saveMessageToRAG(message.id);
  } catch (e) {
    alert('Failed to save');
  }
  savingToRAG.value = null;
};

/**
 * Handle viewing a RAG document in modal
 */
const handleViewRAGDoc = async (source) => {
  if (!source.id) {
    console.warn('RAG source missing ID, cannot view:', source);
    return;
  }

  showRAGDocModal.value = true;
  ragDocLoading.value = true;
  viewingRAGDoc.value = null;

  try {
    const response = await fetch(`/api/rag/documents/${source.id}`);
    if (!response.ok) throw new Error('Failed to fetch document');
    const data = await response.json();
    if (data.document) {
      viewingRAGDoc.value = data.document;
    } else if (data.error) {
      throw new Error(data.error);
    } else {
      throw new Error('Invalid response format');
    }
  } catch (error) {
    console.error('Error loading RAG document:', error);
    alert('Failed to load document: ' + error.message);
    showRAGDocModal.value = false;
  } finally {
    ragDocLoading.value = false;
  }
};

const handleSaveSystemPrompt = async () => {
  await chatStore.updateSystemPrompt(editedSystemPrompt.value);
  showSystemPromptModal.value = false;
};

const handleCodeCopy = (event) => {
  if (event.target.classList.contains('copy-button')) {
    const code = event.target.getAttribute('data-code');
    if (code) {
      navigator.clipboard.writeText(code);
      event.target.textContent = 'Copied!';
      setTimeout(() => event.target.textContent = 'Copy', 2000);
    }
  }
};

const scrollToBottom = () => {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
  }
};

// Research data loading
const loadResearchData = async () => {
  researchLoading.value = true;
  await Promise.all([
    loadReviewQueue(),
    loadTopics(),
    loadRagCategories(),
  ]);
  researchLoading.value = false;
};

const loadRagCategories = async () => {
  try {
    const response = await fetch('/api/research-topics/rag-categories');
    const data = await response.json();
    if (data.success) {
      ragCategories.value = data.categories;
    }
  } catch (e) {
    console.error('Failed to load RAG categories:', e);
    // Fallback to defaults
    ragCategories.value = {
      general: 'General',
      genealogy: 'Genealogy',
      health: 'Health',
      finance: 'Finance',
      news: 'News',
      technology: 'Technology',
    };
  }
};

const addNewRagCategory = () => {
  if (newRagCategory.value.trim()) {
    const key = newRagCategory.value.trim().toLowerCase().replace(/\s+/g, '_');
    const label = newRagCategory.value.trim();
    ragCategories.value[key] = label;
    topicForm.rag_category = key;
    newRagCategory.value = '';
    showNewRagCategoryInput.value = false;
  }
};

const loadReviewQueue = async () => {
  try {
    const response = await fetch('/api/research/review-queue');
    const data = await response.json();
    if (data.success) {
      reviewQueue.value = data.items;
      researchStats.value = data.stats;
    }
  } catch (e) { console.error(e); }
};

const loadTopics = async () => {
  try {
    const [topicsRes, statsRes] = await Promise.all([
      fetch('/api/research-topics'),
      fetch('/api/research-topics/stats'),
    ]);
    const topicsData = await topicsRes.json();
    const statsData = await statsRes.json();
    topics.value = topicsData.topics || [];
    topicStats.value = statsData;
  } catch (e) { console.error(e); }
};

// loadSources and loadRules removed — tabs dropped

// Review queue actions
const approveItem = async (item) => {
  processingItem.value = item.id;
  try {
    const response = await fetch(`/api/research-results/${item.id}/approve`, { method: 'POST' });
    if (response.ok) {
      reviewQueue.value = reviewQueue.value.filter(i => i.id !== item.id);
      await loadReviewQueue();
    }
  } catch (e) { console.error(e); }
  processingItem.value = null;
};

const skipItem = async (item) => {
  processingItem.value = item.id;
  try {
    const response = await fetch(`/api/research-results/${item.id}/skip`, { method: 'POST' });
    if (response.ok) {
      reviewQueue.value = reviewQueue.value.filter(i => i.id !== item.id);
      await loadReviewQueue();
    }
  } catch (e) { console.error(e); }
  processingItem.value = null;
};

// Reject item
const rejectItem = async (item) => {
  processingItem.value = item.id;
  try {
    const response = await fetch(`/api/research-results/${item.id}/skip`, { method: 'POST' });
    if (response.ok) {
      reviewQueue.value = reviewQueue.value.filter(i => i.id !== item.id);
      await loadReviewQueue();
    }
  } catch (e) { console.error(e); }
  processingItem.value = null;
};

// URL truncation helper
const truncateUrl = (url) => {
  try {
    const parsed = new URL(url);
    const path = parsed.pathname.length > 30 ? parsed.pathname.substring(0, 30) + '...' : parsed.pathname;
    return parsed.hostname + path;
  } catch {
    return url.substring(0, 50) + (url.length > 50 ? '...' : '');
  }
};

// Topic actions
const openTopicModal = () => {
  refineExistingTopic.value = null;
  showRefineModal.value = true;
};

const editTopic = (topic) => {
  refineExistingTopic.value = topic;
  showRefineModal.value = true;
};

const onRefineSaved = () => {
  showRefineModal.value = false;
  loadTopics();
};

const onRefineSkipToSimple = () => {
  showRefineModal.value = false;
  editingTopic.value = null;
  topicForm.description = '';
  topicForm.topic_content = '';
  topicForm.frequency = 'daily';
  topicForm.is_active = true;
  topicForm.rag_category = '';
  showTopicModal.value = true;
};

const closeTopicModal = () => {
  showTopicModal.value = false;
  editingTopic.value = null;
};

const saveTopic = async () => {
  savingTopic.value = true;
  try {
    const url = editingTopic.value ? `/api/research-topics/${editingTopic.value.id}` : '/api/research-topics';
    const method = editingTopic.value ? 'PUT' : 'POST';
    const response = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(topicForm),
    });
    if (response.ok) {
      closeTopicModal();
      await loadTopics();
    }
  } catch (e) { console.error(e); }
  savingTopic.value = false;
};

const toggleTopic = async (topic) => {
  try {
    const response = await fetch(`/api/research-topics/${topic.id}/toggle`, { method: 'POST' });
    if (response.ok) {
      const data = await response.json();
      topic.is_active = data.is_active;
    }
  } catch (e) { console.error(e); }
};

const confirmDeleteTopic = async (topic) => {
  if (confirm(`Delete topic "${topic.description}"?`)) {
    await fetch(`/api/research-topics/${topic.id}`, { method: 'DELETE' });
    await loadTopics();
  }
};

// Helpers
const getStatusBadgeClass = (status) => {
  const classes = {
    pending: 'text-xs px-2 py-0.5 rounded bg-[#f39c12] text-white',
    active: 'text-xs px-2 py-0.5 rounded bg-accent text-white',
    completed: 'text-xs px-2 py-0.5 rounded bg-[#27ae60] text-white',
    failed: 'text-xs px-2 py-0.5 rounded bg-[#e74c3c] text-white',
  };
  return classes[status] || classes.pending;
};

const getScoreClass = (score) => {
  if (score >= 0.8) return 'text-[#27ae60] font-medium';
  if (score >= 0.5) return 'text-[#f39c12]';
  return 'text-[#e74c3c]';
};

const formatRuleType = (type) => {
  const types = {
    tld_trust: 'TLD Trust',
    whitelist_pattern: 'Whitelist',
    blacklist_pattern: 'Blacklist',
  };
  return types[type] || type;
};

// Watch for new messages
watch(() => chatStore.messages.length, () => {
  nextTick(() => scrollToBottom());
});

// Load on mount
onMounted(async () => {
  await Promise.all([
    chatStore.fetchConversations(),
    loadResearchData(),
  ]);
});
</script>

<style>
@import 'highlight.js/styles/atom-one-dark.css';

.ai-hub-container {
  height: 100%;
  min-height: 400px;
}

.ai-hub-container ::-webkit-scrollbar {
  width: 12px;
}

.ai-hub-container ::-webkit-scrollbar-track {
  background: #1a1a1a;
}

.ai-hub-container ::-webkit-scrollbar-thumb {
  background: #666;
  border-radius: 6px;
  border: 2px solid #1a1a1a;
}

.prose { max-width: none; color: #e0e0e0; }
.prose p { margin-bottom: 0.5rem; }
.prose code { background-color: rgba(255,255,255,0.1); padding: 0.2rem 0.4rem; border-radius: 0.25rem; }
.prose pre { background-color: #1a1a1a; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; border: 1px solid #444; }
.prose pre code { background-color: transparent; padding: 0; }
.bg-accent .prose { color: white; }
.bg-accent .prose code { background-color: rgba(255,255,255,0.2); }

.label { display: block; font-size: 0.875rem; font-weight: 500; color: #95a5a6; margin-bottom: 0.25rem; }
.form-input { width: 100%; padding: 0.5rem 1rem; background-color: #1a1a1a; border: 1px solid #444; border-radius: 0.5rem; color: #e0e0e0; }
.form-input:focus { outline: none; ring: 2px; ring-color: var(--accent); }
.form-textarea { width: 100%; padding: 0.5rem 1rem; background-color: #1a1a1a; border: 1px solid #444; border-radius: 0.5rem; color: #e0e0e0; resize: vertical; }
.form-select { width: 100%; padding: 0.5rem 1rem; background-color: #1a1a1a; border: 1px solid #444; border-radius: 0.5rem; color: #e0e0e0; }
.form-checkbox { width: 1rem; height: 1rem; }

.btn-primary { padding: 0.5rem 1rem; background-color: var(--accent, #3498db); color: white; border-radius: 0.5rem; font-weight: 500; transition: all 0.15s; }
.btn-primary:hover { filter: brightness(1.1); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { padding: 0.5rem 1rem; background-color: #34495e; color: #e0e0e0; border-radius: 0.5rem; transition: all 0.15s; }
.btn-secondary:hover { background-color: #2c3e50; }
.btn-icon { padding: 0.5rem; border-radius: 0.5rem; color: #95a5a6; transition: all 0.15s; }
.btn-icon:hover { background-color: #34495e; color: #e0e0e0; }
.btn-success-sm { padding: 0.25rem 0.75rem; background-color: #27ae60; color: white; border-radius: 0.25rem; font-size: 0.875rem; }
.btn-success-sm:hover { background-color: #229954; }
.btn-success-sm:disabled { opacity: 0.5; }
.btn-danger-sm { padding: 0.25rem 0.75rem; background-color: #e74c3c; color: white; border-radius: 0.25rem; font-size: 0.875rem; }
.btn-danger-sm:hover { background-color: #c0392b; }
.btn-danger-sm:disabled { opacity: 0.5; }
.btn-warning-sm { padding: 0.25rem 0.75rem; background-color: #f39c12; color: white; border-radius: 0.25rem; font-size: 0.875rem; }
.btn-warning-sm:hover { background-color: #d68910; }
.btn-warning-sm:disabled { opacity: 0.5; }
</style>

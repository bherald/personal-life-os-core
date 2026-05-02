<template>
  <div v-if="visible" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-[#2d2d2d] rounded-lg w-full max-w-2xl border-2 border-[#444] max-h-[90vh] flex flex-col">
      <!-- Header -->
      <div class="px-6 py-4 border-b border-[#444] flex justify-between items-center shrink-0">
        <h3 class="text-xl font-bold text-[#e0e0e0]">
          {{ existingTopic ? 'Refine Research Topic' : 'New Research Topic' }}
        </h3>
        <button @click="$emit('close')" class="text-[#95a5a6] hover:text-[#e0e0e0] text-2xl leading-none">&times;</button>
      </div>

      <!-- Phase: Input -->
      <div v-if="phase === 'input'" class="p-6 space-y-4">
        <p class="text-[#95a5a6] text-sm">
          Describe what you want to research. AI will help refine it into a structured research brief.
        </p>
        <textarea
          v-model="rawIdea"
          class="form-textarea"
          rows="4"
          placeholder="e.g., Track developments in lithium battery recycling technology..."
          @keydown.ctrl.enter="startRefine"
          ref="ideaInput"
        ></textarea>
        <div v-if="error" class="text-red-400 text-sm">{{ error }}</div>
        <div class="flex justify-between items-center">
          <button @click="$emit('skip-to-simple')" class="text-sm text-[#95a5a6] hover:text-[#e0e0e0]">
            Skip to simple form
          </button>
          <button @click="startRefine" :disabled="!rawIdea.trim() || loading" class="btn-primary">
            {{ loading ? 'Analyzing...' : 'Analyze with AI' }}
          </button>
        </div>
      </div>

      <!-- Phase: Analyzing (spinner) -->
      <div v-else-if="phase === 'analyzing'" class="p-6 text-center py-12">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[#3498db]"></div>
        <p class="mt-3 text-[#95a5a6]">Analyzing your research idea...</p>
      </div>

      <!-- Phase: Conversation -->
      <div v-else-if="phase === 'conversation'" class="flex flex-col flex-1 overflow-hidden">
        <div class="flex-1 overflow-y-auto p-6 space-y-4" ref="messagesContainer">
          <div
            v-for="(msg, i) in conversation"
            :key="i"
            :class="[
              'rounded-lg px-4 py-3 text-sm',
              msg.role === 'user'
                ? 'bg-[#3498db] bg-opacity-20 text-[#e0e0e0] ml-12 border border-[#3498db] border-opacity-30'
                : 'bg-[#1a1a1a] text-[#e0e0e0] mr-12 border border-[#444]'
            ]"
          >
            <div class="text-xs text-[#95a5a6] mb-1">{{ msg.role === 'user' ? 'You' : 'Research Assistant' }}</div>
            <div v-html="renderMarkdown(msg.content)"></div>
          </div>
          <div v-if="loading" class="bg-[#1a1a1a] rounded-lg px-4 py-3 mr-12 border border-[#444]">
            <div class="text-xs text-[#95a5a6] mb-1">Research Assistant</div>
            <span class="text-[#95a5a6] animate-pulse">Thinking...</span>
          </div>
        </div>

        <!-- Reply Input -->
        <div class="p-4 border-t border-[#444] space-y-3 shrink-0">
          <div v-if="error" class="text-red-400 text-sm">{{ error }}</div>
          <textarea
            v-model="userReply"
            class="form-textarea"
            rows="2"
            placeholder="Answer the question above, or type 'save it' to generate the brief now..."
            @keydown.ctrl.enter="sendReply"
            ref="replyInput"
          ></textarea>
          <div class="flex justify-between items-center">
            <button
              @click="forceSave"
              class="text-sm text-[#3498db] hover:underline"
              :disabled="loading"
            >
              Generate brief now
            </button>
            <button @click="sendReply" :disabled="!userReply.trim() || loading" class="btn-primary">
              {{ loading ? 'Processing...' : 'Send' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Phase: Proposing (spinner) -->
      <div v-else-if="phase === 'proposing'" class="p-6 text-center py-12">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[#3498db]"></div>
        <p class="mt-3 text-[#95a5a6]">Generating research brief...</p>
      </div>

      <!-- Phase: Editing -->
      <div v-else-if="phase === 'editing'" class="flex-1 overflow-y-auto p-6 space-y-4">
        <p class="text-sm text-[#95a5a6]">Review and edit the proposed research brief. All fields are editable.</p>

        <div>
          <label class="label">Description</label>
          <input v-model="proposal.description" type="text" class="form-input" placeholder="Research topic title">
        </div>

        <div>
          <label class="label">Research Brief</label>
          <textarea v-model="proposal.topic_content" class="form-textarea" rows="12" placeholder="Detailed research brief..."></textarea>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Frequency</label>
            <select v-model="proposal.frequency" class="form-select">
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="biannually">Twice a Year</option>
            </select>
          </div>
          <div>
            <label class="label">RAG Category</label>
            <input v-model="proposal.rag_category" type="text" class="form-input" placeholder="e.g., health, technology">
          </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="label">Search Depth</label>
            <input v-model.number="proposal.search_depth" type="number" min="1" max="10" class="form-input">
          </div>
          <div>
            <label class="label">Max Sources</label>
            <input v-model.number="proposal.max_sources" type="number" min="1" max="50" class="form-input">
          </div>
          <div>
            <label class="label">Date Filter (days)</label>
            <input v-model.number="proposal.date_filter_days" type="number" min="1" max="365" class="form-input">
          </div>
        </div>

        <div class="flex items-center gap-6">
          <label class="flex items-center gap-2">
            <input v-model="proposal.is_active" type="checkbox" class="form-checkbox">
            <span class="text-[#e0e0e0] text-sm">Active</span>
          </label>
          <label class="flex items-center gap-2">
            <input v-model="proposal.require_recent_only" type="checkbox" class="form-checkbox">
            <span class="text-[#e0e0e0] text-sm">Recent Only</span>
          </label>
        </div>

        <!-- AI summary of what was configured -->
        <div v-if="proposalSummary" class="bg-[#1a1a1a] rounded-lg px-4 py-3 border border-[#444] text-sm">
          <div class="text-xs text-[#95a5a6] mb-1">AI Notes</div>
          <div class="text-[#e0e0e0]" v-html="renderMarkdown(proposalSummary)"></div>
        </div>

        <div class="flex justify-between items-center pt-4 border-t border-[#444]">
          <button @click="phase = 'conversation'" class="text-sm text-[#95a5a6] hover:text-[#e0e0e0]">
            &larr; Back to conversation
          </button>
          <div class="flex gap-2">
            <button @click="$emit('close')" class="btn-secondary">Cancel</button>
            <button @click="saveTopic" :disabled="saving" class="btn-primary">
              {{ saving ? 'Saving...' : (existingTopic ? 'Update Topic' : 'Save Topic') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, nextTick, watch } from 'vue';

const props = defineProps({
  visible: { type: Boolean, default: false },
  existingTopic: { type: Object, default: null },
});

const emit = defineEmits(['saved', 'close', 'skip-to-simple']);

// State
const phase = ref('input');
const rawIdea = ref('');
const conversation = ref([]);
const userReply = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const proposalSummary = ref('');

const proposal = reactive({
  description: '',
  topic_content: '',
  frequency: 'weekly',
  rag_category: '',
  is_active: true,
  search_depth: 3,
  max_sources: 15,
  date_filter_days: 30,
  require_recent_only: true,
  preferred_categories: [],
  excluded_domains: [],
});

// Refs
const messagesContainer = ref(null);
const ideaInput = ref(null);
const replyInput = ref(null);

// Reset when modal opens
watch(() => props.visible, (val) => {
  if (val) {
    error.value = '';
    conversation.value = [];
    proposalSummary.value = '';
    loading.value = false;
    saving.value = false;

    if (props.existingTopic) {
      // Edit mode: pre-populate and start conversation with existing data
      rawIdea.value = props.existingTopic.topic_content || props.existingTopic.description || '';
      Object.assign(proposal, {
        description: props.existingTopic.description || '',
        topic_content: props.existingTopic.topic_content || '',
        frequency: props.existingTopic.frequency || 'weekly',
        rag_category: props.existingTopic.rag_category || '',
        is_active: props.existingTopic.is_active ?? true,
        search_depth: props.existingTopic.search_depth || 3,
        max_sources: props.existingTopic.max_sources || 15,
        date_filter_days: props.existingTopic.date_filter_days || 30,
        require_recent_only: props.existingTopic.require_recent_only ?? true,
        preferred_categories: props.existingTopic.preferred_categories || [],
        excluded_domains: props.existingTopic.excluded_domains || [],
      });
      phase.value = 'input';
    } else {
      rawIdea.value = '';
      phase.value = 'input';
    }

    nextTick(() => {
      if (ideaInput.value) ideaInput.value.focus();
    });
  }
});

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
  });
};

const callRefine = async (extraConversation = [], forcePropose = false) => {
  loading.value = true;
  error.value = '';

  try {
    const body = {
      raw_idea: rawIdea.value,
      conversation: extraConversation,
    };

    if (props.existingTopic) {
      body.existing_topic = {
        description: props.existingTopic.description,
        topic_content: props.existingTopic.topic_content,
        frequency: props.existingTopic.frequency,
        rag_category: props.existingTopic.rag_category,
        search_depth: props.existingTopic.search_depth,
        max_sources: props.existingTopic.max_sources,
        date_filter_days: props.existingTopic.date_filter_days,
      };
    }

    // If forcing save, append a save trigger to conversation
    if (forcePropose) {
      body.conversation = [
        ...extraConversation,
        { role: 'user', content: 'Generate the research brief now. Save it.' },
      ];
    }

    const response = await fetch('/api/research-topics/refine', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    const data = await response.json();

    if (!data.success) {
      error.value = data.error || 'AI processing failed';
      return null;
    }

    return data;
  } catch (e) {
    error.value = 'Connection failed: ' + e.message;
    return null;
  } finally {
    loading.value = false;
  }
};

const startRefine = async () => {
  if (!rawIdea.value.trim()) return;

  phase.value = 'analyzing';
  const data = await callRefine([]);

  if (data) {
    conversation.value = [
      { role: 'user', content: rawIdea.value },
      { role: 'assistant', content: data.message },
    ];

    if (data.proposal) {
      applyProposal(data.proposal, data.message);
    } else {
      phase.value = 'conversation';
      scrollToBottom();
      nextTick(() => { if (replyInput.value) replyInput.value.focus(); });
    }
  } else {
    phase.value = 'input';
  }
};

const sendReply = async () => {
  if (!userReply.value.trim() || loading.value) return;

  const reply = userReply.value.trim();
  conversation.value.push({ role: 'user', content: reply });
  userReply.value = '';
  scrollToBottom();

  const data = await callRefine(conversation.value);

  if (data) {
    conversation.value.push({ role: 'assistant', content: data.message });
    scrollToBottom();

    if (data.proposal) {
      applyProposal(data.proposal, data.message);
    } else {
      nextTick(() => { if (replyInput.value) replyInput.value.focus(); });
    }
  }
};

const forceSave = async () => {
  phase.value = 'proposing';
  const data = await callRefine(conversation.value, true);

  if (data && data.proposal) {
    applyProposal(data.proposal, data.message);
  } else if (data) {
    // AI responded but no JSON proposal — add to conversation and retry
    conversation.value.push(
      { role: 'user', content: 'Generate the research brief now.' },
      { role: 'assistant', content: data.message },
    );
    phase.value = 'conversation';
    error.value = 'AI did not generate a proposal. Try again or edit manually.';
  } else {
    phase.value = 'conversation';
  }
};

const applyProposal = (proposalData, summary) => {
  Object.assign(proposal, proposalData);
  proposalSummary.value = summary || '';
  phase.value = 'editing';
};

const saveTopic = async () => {
  if (!proposal.description.trim() || !proposal.topic_content.trim()) {
    error.value = 'Description and research brief are required';
    return;
  }

  saving.value = true;
  error.value = '';

  try {
    const isEdit = !!props.existingTopic;
    const url = isEdit
      ? `/api/research-topics/${props.existingTopic.id}`
      : '/api/research-topics';
    const method = isEdit ? 'PUT' : 'POST';

    const response = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        description: proposal.description,
        topic_content: proposal.topic_content,
        frequency: proposal.frequency,
        rag_category: proposal.rag_category || null,
        is_active: proposal.is_active,
        search_depth: proposal.search_depth,
        max_sources: proposal.max_sources,
        date_filter_days: proposal.date_filter_days,
        require_recent_only: proposal.require_recent_only,
        preferred_categories: proposal.preferred_categories,
        excluded_domains: proposal.excluded_domains,
      }),
    });

    if (response.ok) {
      emit('saved');
    } else {
      const errData = await response.json();
      error.value = errData.message || errData.error || 'Failed to save topic';
    }
  } catch (e) {
    error.value = 'Failed to save: ' + e.message;
  } finally {
    saving.value = false;
  }
};

const renderMarkdown = (text) => {
  if (!text) return '';
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/^### (.+)$/gm, '<h3 class="text-base font-bold text-[#e0e0e0] mt-3 mb-1">$1</h3>')
    .replace(/^## (.+)$/gm, '<h2 class="text-lg font-bold text-[#e0e0e0] mt-3 mb-1">$1</h2>')
    .replace(/^# (.+)$/gm, '<h1 class="text-xl font-bold text-[#e0e0e0] mt-3 mb-1">$1</h1>')
    .replace(/\*\*(.+?)\*\*/g, '<strong class="text-[#e0e0e0]">$1</strong>')
    .replace(/^\d+\.\s+(.+)$/gm, '<li class="ml-4 list-decimal text-[#e0e0e0]">$1</li>')
    .replace(/^- (.+)$/gm, '<li class="ml-4 list-disc text-[#e0e0e0]">$1</li>')
    .replace(/`([^`]+)`/g, '<code class="bg-[#1a1a1a] px-1 rounded text-[#3498db]">$1</code>')
    .replace(/\n/g, '<br />');
};
</script>

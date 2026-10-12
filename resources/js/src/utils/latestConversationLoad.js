export function invalidateConversationLoads(state) {
  state.conversationLoadGeneration = (state.conversationLoadGeneration || 0) + 1;
}

export async function loadLatestConversation(
  state,
  id,
  fetchConversation,
  normalizeMessages = messages => messages,
) {
  invalidateConversationLoads(state);
  const generation = state.conversationLoadGeneration;

  state.currentConversation = null;
  state.messages = [];
  state.loading = true;
  state.error = null;

  try {
    const response = await fetchConversation(id);
    if (state.conversationLoadGeneration !== generation) {
      return { success: false, stale: true };
    }

    if (!response?.success) {
      state.error = response?.message || 'Failed to load conversation';

      return { success: false, error: state.error };
    }

    state.currentConversation = response.conversation;
    state.messages = normalizeMessages(response.messages || []);

    return { success: true };
  } catch (error) {
    if (state.conversationLoadGeneration !== generation) {
      return { success: false, stale: true };
    }

    state.error = error.response?.data?.message || 'Failed to load conversation';

    return { success: false, error: state.error };
  } finally {
    if (state.conversationLoadGeneration === generation) {
      state.loading = false;
    }
  }
}

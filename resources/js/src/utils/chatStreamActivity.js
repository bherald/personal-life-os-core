const SILENCE_NOTICE_MS = 30_000;

const providerLabel = (activity) => {
  const provider = String(activity.provider || '').trim();
  const model = String(activity.model || '').trim();

  if (provider && model) return `${provider} / ${model}`;
  return provider || model || 'the AI provider';
};

export const createChatStreamActivity = (now = Date.now()) => ({
  phase: 'preparing',
  label: 'Preparing request',
  provider: null,
  model: null,
  lastEventAt: now,
});

export const applyChatStreamEvent = (activity, event, now = Date.now()) => {
  const next = { ...activity, lastEventAt: now };

  switch (event?.type) {
    case 'start':
      return { ...next, phase: 'routing', label: 'Selecting route' };
    case 'provider':
      next.provider = event.provider || null;
      next.model = event.model || null;
      return { ...next, phase: 'waiting', label: `Waiting on ${providerLabel(next)}` };
    case 'progress': {
      const queueWait = Number(event.queue_wait_ms);
      const queueLabel = Number.isFinite(queueWait) && queueWait > 0
        ? `Queue wait ${queueWait}ms; generating response`
        : 'Generating response';
      return { ...next, phase: 'waiting', label: queueLabel };
    }
    case 'intent_detected':
      return { ...next, phase: 'routing', label: `Routing ${String(event.intent || 'request').replace(/_/g, ' ')}` };
    case 'web_search_fallback':
      return { ...next, phase: 'tool', label: 'Searching external sources' };
    case 'tool_start':
      return { ...next, phase: 'tool', label: 'Starting tool execution' };
    case 'tool_call':
      return { ...next, phase: 'tool', label: `Running ${event.tool || 'tool'}` };
    case 'tool_result':
      return { ...next, phase: 'tool', label: `Completed ${event.tool || 'tool'}` };
    case 'tool_error':
      return { ...next, phase: 'tool', label: `Failed ${event.tool || 'tool'}` };
    case 'content':
    case 'content_replace':
      return { ...next, phase: 'receiving', label: 'Receiving response' };
    case 'stream_interrupted':
      return { ...next, phase: 'interrupted', label: 'Response interrupted' };
    case 'run_error':
      return { ...next, phase: 'failed', label: 'Request failed' };
    default:
      return next;
  }
};

export const formatChatStreamStatus = (activity, now = Date.now()) => {
  if (!activity) return '';

  const silentMs = Math.max(0, now - activity.lastEventAt);
  if (silentMs < SILENCE_NOTICE_MS || activity.phase === 'interrupted') {
    return activity.label;
  }

  const silentSeconds = Math.floor(silentMs / 1000);
  if (activity.phase === 'receiving') {
    return `Response paused for ${silentSeconds}s; waiting for the next chunk`;
  }

  return `Still waiting on ${providerLabel(activity)} (${silentSeconds}s); it may be loading or busy`;
};

import assert from 'node:assert/strict';
import test from 'node:test';
import {
  applyChatStreamEvent,
  createChatStreamActivity,
  formatChatStreamStatus,
} from '../resources/js/src/utils/chatStreamActivity.js';

test('stream activity names route, provider, tool, and response phases', () => {
  let activity = createChatStreamActivity(1_000);
  assert.equal(formatChatStreamStatus(activity, 1_000), 'Preparing request');

  activity = applyChatStreamEvent(activity, { type: 'start' }, 2_000);
  assert.equal(formatChatStreamStatus(activity, 2_000), 'Selecting route');

  activity = applyChatStreamEvent(activity, { type: 'provider', provider: 'ollama', model: 'qwen3:8b' }, 3_000);
  assert.equal(formatChatStreamStatus(activity, 3_000), 'Waiting on ollama / qwen3:8b');

  activity = applyChatStreamEvent(activity, { type: 'tool_call', tool: 'web_search_parallel' }, 4_000);
  assert.equal(formatChatStreamStatus(activity, 4_000), 'Running web_search_parallel');

  activity = applyChatStreamEvent(activity, { type: 'tool_result', tool: 'web_search_parallel' }, 4_500);
  assert.equal(formatChatStreamStatus(activity, 4_500), 'Completed web_search_parallel');

  activity = applyChatStreamEvent(activity, { type: 'content', content: 'answer' }, 5_000);
  assert.equal(formatChatStreamStatus(activity, 5_000), 'Receiving response');
});

test('stream activity reports tool failure without exposing error content', () => {
  const activity = applyChatStreamEvent(
    createChatStreamActivity(1_000),
    { type: 'tool_error', tool: 'private_lookup', error: 'private upstream response' },
    2_000,
  );

  assert.equal(formatChatStreamStatus(activity, 2_000), 'Failed private_lookup');
  assert.equal(formatChatStreamStatus(activity, 2_000).includes('private upstream response'), false);
});

test('stream activity reports content-free Colibri queue progress', () => {
  const queued = applyChatStreamEvent(
    createChatStreamActivity(1_000),
    { type: 'progress', state: 'generating', queue_wait_ms: 1250 },
    2_000,
  );

  assert.equal(formatChatStreamStatus(queued, 2_000), 'Queue wait 1250ms; generating response');
});

test('stream activity reaches a terminal failed phase without rendering error content', () => {
  const activity = applyChatStreamEvent(
    createChatStreamActivity(1_000),
    { type: 'run_error', message: 'private provider body' },
    2_000,
  );

  assert.equal(activity.phase, 'failed');
  assert.equal(formatChatStreamStatus(activity, 2_000), 'Request failed');
  assert.equal(formatChatStreamStatus(activity, 2_000).includes('private provider body'), false);
});

test('stream activity explains provider silence and paused content without changing content', () => {
  const waiting = applyChatStreamEvent(
    createChatStreamActivity(1_000),
    { type: 'provider', provider: 'ollama', model: 'qwen3:8b' },
    2_000,
  );
  assert.equal(
    formatChatStreamStatus(waiting, 34_000),
    'Still waiting on ollama / qwen3:8b (32s); it may be loading or busy',
  );

  const receiving = applyChatStreamEvent(waiting, { type: 'content', content: 'unchanged' }, 40_000);
  assert.equal(
    formatChatStreamStatus(receiving, 75_000),
    'Response paused for 35s; waiting for the next chunk',
  );
});

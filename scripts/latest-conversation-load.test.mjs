import assert from 'node:assert/strict';
import test from 'node:test';

import {
  invalidateConversationLoads,
  loadLatestConversation,
} from '../resources/js/src/utils/latestConversationLoad.js';

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });

  return { promise, resolve, reject };
}

function conversation(id) {
  return {
    success: true,
    conversation: { id },
    messages: [{ id: `${id}-message`, content: id }],
  };
}

test('only the latest conversation response may populate the transcript', async () => {
  const state = {
    conversationLoadGeneration: 0,
    currentConversation: { id: 'old' },
    messages: [{ id: 'old-message' }],
    loading: false,
    error: null,
  };
  const first = deferred();
  const second = deferred();
  const requests = new Map([
    ['first', first.promise],
    ['second', second.promise],
  ]);

  const firstLoad = loadLatestConversation(state, 'first', id => requests.get(id));
  assert.equal(state.currentConversation, null);
  assert.deepEqual(state.messages, []);

  const secondLoad = loadLatestConversation(state, 'second', id => requests.get(id));
  second.resolve(conversation('second'));
  assert.deepEqual(await secondLoad, { success: true });

  first.resolve(conversation('first'));
  assert.deepEqual(await firstLoad, { success: false, stale: true });
  assert.equal(state.currentConversation.id, 'second');
  assert.deepEqual(state.messages, [{ id: 'second-message', content: 'second' }]);
  assert.equal(state.loading, false);
  assert.equal(state.error, null);
});

test('a stale failure cannot replace the current state or loading status', async () => {
  const state = {
    conversationLoadGeneration: 0,
    currentConversation: null,
    messages: [],
    loading: false,
    error: null,
  };
  const first = deferred();
  const second = deferred();

  const firstLoad = loadLatestConversation(state, 'first', () => first.promise);
  const secondLoad = loadLatestConversation(state, 'second', () => second.promise);
  first.reject(new Error('stale private failure'));

  assert.deepEqual(await firstLoad, { success: false, stale: true });
  assert.equal(state.loading, true);
  assert.equal(state.error, null);

  second.resolve(conversation('second'));
  assert.deepEqual(await secondLoad, { success: true });
  assert.equal(state.currentConversation.id, 'second');
  assert.equal(state.loading, false);
});

test('explicit invalidation prevents a pending load from restoring a reset thread', async () => {
  const state = {
    conversationLoadGeneration: 0,
    currentConversation: null,
    messages: [],
    loading: false,
    error: null,
  };
  const pending = deferred();
  const load = loadLatestConversation(state, 'first', () => pending.promise);

  invalidateConversationLoads(state);
  state.loading = false;
  pending.resolve(conversation('first'));

  assert.deepEqual(await load, { success: false, stale: true });
  assert.equal(state.currentConversation, null);
  assert.deepEqual(state.messages, []);
});

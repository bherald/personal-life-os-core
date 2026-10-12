import assert from 'node:assert/strict';
import test from 'node:test';

import type { ProgressNotification } from '@modelcontextprotocol/sdk/types.js';
import { runToolWithProgress } from './tool-progress.js';

const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

function recorder() {
  const notifications: ProgressNotification[] = [];

  return {
    notifications,
    sendNotification: async (notification: ProgressNotification) => {
      notifications.push(notification);
    },
  };
}

test('tools without a progress token emit no notifications', async () => {
  const recorded = recorder();

  const result = await runToolWithProgress(async () => 'done', {
    signal: new AbortController().signal,
    heartbeatMs: 5,
    sendNotification: recorded.sendNotification,
  });

  assert.equal(result, 'done');
  assert.deepEqual(recorded.notifications, []);
});

test('progress is token-bound, content-free, and monotonic', async () => {
  const recorded = recorder();

  await runToolWithProgress(async () => {
    await delay(16);
  }, {
    progressToken: 'request-token',
    signal: new AbortController().signal,
    heartbeatMs: 5,
    sendNotification: recorded.sendNotification,
  });

  assert.equal(recorded.notifications[0]?.params.message, 'started');
  assert.equal(recorded.notifications.at(-1)?.params.message, 'completed');
  assert.ok(recorded.notifications.some(notification => notification.params.message === 'running'));
  assert.ok(recorded.notifications.every(notification => notification.params.progressToken === 'request-token'));
  assert.ok(recorded.notifications.every(notification => notification.params.total === undefined));

  const progress = recorded.notifications.map(notification => notification.params.progress);
  assert.deepEqual(progress, [...progress].sort((left, right) => left - right));
});

test('failed tools emit a terminal content-free failure state', async () => {
  const recorded = recorder();

  await assert.rejects(runToolWithProgress(async () => {
    throw new Error('private failure detail');
  }, {
    progressToken: 7,
    signal: new AbortController().signal,
    heartbeatMs: 5,
    sendNotification: recorded.sendNotification,
  }));

  assert.deepEqual(recorded.notifications.map(notification => notification.params.message), ['started', 'failed']);
  assert.equal(JSON.stringify(recorded.notifications).includes('private failure detail'), false);
});

test('cancellation stops heartbeats without a late terminal notification', async () => {
  const recorded = recorder();
  const controller = new AbortController();

  const pending = runToolWithProgress(async () => {
    await delay(25);
  }, {
    progressToken: 'cancelled',
    signal: controller.signal,
    heartbeatMs: 5,
    sendNotification: recorded.sendNotification,
  });

  await delay(8);
  controller.abort();
  const countAtCancellation = recorded.notifications.length;
  await pending;
  await delay(10);

  assert.equal(recorded.notifications.length, countAtCancellation);
  assert.ok(recorded.notifications.every(notification => ! ['completed', 'failed'].includes(notification.params.message ?? '')));
});

test('notification transport failure never changes tool completion', async () => {
  const result = await runToolWithProgress(async () => 'complete', {
    progressToken: 'transport-failure',
    signal: new AbortController().signal,
    heartbeatMs: 5,
    sendNotification: async () => {
      throw new Error('notification unavailable');
    },
  });

  assert.equal(result, 'complete');
});

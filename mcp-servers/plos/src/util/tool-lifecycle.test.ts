import assert from 'node:assert/strict';
import test from 'node:test';
import {
  runToolWithLifecycle,
  ToolCancelledError,
  ToolTimeoutError,
} from './tool-lifecycle.js';

const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

test('successful tools clear their timeout without a late abort', async () => {
  let aborted = false;

  const value = await runToolWithLifecycle(async signal => {
    signal.addEventListener('abort', () => { aborted = true; });
    return 'complete';
  }, { toolName: 'fast_tool', timeoutMs: 10 });

  await delay(25);
  assert.equal(value, 'complete');
  assert.equal(aborted, false);
});

test('server timeout aborts cooperating tools and returns a typed error', async () => {
  let aborted = false;

  await assert.rejects(
    runToolWithLifecycle(async signal => {
      signal.addEventListener('abort', () => { aborted = true; });
      await delay(100);
      return 'late';
    }, { toolName: 'slow_tool', timeoutMs: 10 }),
    ToolTimeoutError,
  );

  assert.equal(aborted, true);
});

test('client cancellation stops a tool even when its handler ignores signals', async () => {
  const client = new AbortController();
  const started = Date.now();

  const pending = runToolWithLifecycle(async () => {
    await delay(1_000);
    return 'late';
  }, { toolName: 'cancelled_tool', timeoutMs: 5_000, clientSignal: client.signal });

  client.abort();
  await assert.rejects(pending, ToolCancelledError);
  assert.ok(Date.now() - started < 250, 'client cancellation should fail promptly');
});

test('an already-cancelled request never starts useful work', async () => {
  const client = new AbortController();
  client.abort();
  let handlerStarted = false;

  await assert.rejects(
    runToolWithLifecycle(async () => {
      handlerStarted = true;
      return 'ignored';
    }, { toolName: 'pre_cancelled_tool', timeoutMs: 100, clientSignal: client.signal }),
    ToolCancelledError,
  );

  assert.equal(handlerStarted, false);
});

import assert from 'node:assert/strict';
import test from 'node:test';

import { agentStatusReport } from './plos-agent-status.js';

const session = (sessionId: string, status = 'completed') => ({
  session_id: sessionId,
  agent_name: 'genealogy-records',
  status,
  total_tokens: 1778,
  message_count: 1,
  duration_sec: 14,
  created_at: '2026-09-26 07:00:21',
  last_activity_at: '2026-09-26 07:00:35',
});

test('failed research stays failed when its session lifecycle is completed', async () => {
  const calls: Array<{ sql: string; params: unknown[] }> = [];
  const output = await agentStatusReport({ agent: 'genealogy', hours: 144, showMessages: false }, async (sql, params = []) => {
    calls.push({ sql, params });
    if (calls.length === 1) return [session('failed-session'), session('other-session')];
    return [{ session_id: 'failed-session', status: 'failed', last_task_id: 29343,
      last_outcome_state: 'requeue', findings_count: 0, review_items_count: 0 }];
  });

  assert.match(output, /\[FAIL\] genealogy-records \(failed-session\)/);
  assert.match(output, /Research: failed \| Outcome: requeue \| Task: 29343 \| Findings: 0 \| Reviews: 0/);
  assert.match(output, /\[ENDED\] genealogy-records \(other-session\)/);
  assert.match(output, /Estimated message tokens: 1778/);
  assert.doesNotMatch(output, /\[OK\]/);
  assert.deepEqual(calls[0].params, [144, '%genealogy%']);
  assert.deepEqual(calls[1].params, ['failed-session', 'other-session']);
  assert.match(calls[1].sql, /WHERE session_id IN \(\?, \?\)/);
  assert.match(calls[1].sql, /SELECT MAX\(id\)/);
});

test('active sessions and successful research retain distinct lifecycle and outcome', async () => {
  let call = 0;
  const output = await agentStatusReport({ hours: 24, showMessages: false }, async () => {
    if (++call === 1) return [session('active-session', 'active'), session('done-session')];
    return [{ session_id: 'done-session', status: 'completed', last_task_id: 44,
      last_outcome_state: 'needs_human_review', findings_count: 2, review_items_count: 1 }];
  });
  assert.match(output, /\[RUN\].*active-session/);
  assert.match(output, /\[ENDED\].*done-session/);
  assert.match(output, /Research: completed \| Outcome: needs_human_review.*Findings: 2 \| Reviews: 1/);
});

test('agent review history is not represented as session-specific evidence', async () => {
  let call = 0;
  const output = await agentStatusReport({ hours: 24, showMessages: true }, async () => {
    if (++call === 1) return [session('ended')];
    if (call === 2) return [];
    return [{ status: 'pending', review_type: 'genealogy_finding', title: 'Review evidence', confidence: 0.8, priority: 2 }];
  });
  assert.match(output, /Recent review items for this agent \(not session-specific\)/);
  assert.doesNotMatch(output, /Research:/);
});

test('empty history needs no research or review queries', async () => {
  let calls = 0;
  assert.equal(await agentStatusReport({ hours: 24, showMessages: true }, async () => {
    calls++;
    return [];
  }), 'No agent sessions in last 24h.');
  assert.equal(calls, 1);
});

import test from 'node:test';
import assert from 'node:assert/strict';

import { buildDiagnosticSearchTerms, summarizeFailurePattern } from './plos-job-diagnostic.js';

test('summarizeFailurePattern counts SIGALRM, stalled, and zombie failures as timeout/stall', () => {
  const lines = summarizeFailurePattern({
    total_24h: 12,
    failed_24h: 9,
    timeout_24h: 7,
    sigalrm_24h: 5,
    stalled_24h: 1,
    zombie_24h: 1,
    avg_duration: 123.4,
    max_duration: 456.7,
  });

  assert.equal(lines.length, 2);
  assert.match(lines[0], /12 runs, 9 failed, 7 timeout\/stall/);
  assert.match(lines[0], /5 SIGALRM/);
  assert.match(lines[0], /1 stalled/);
  assert.match(lines[0], /1 zombie/);
  assert.match(lines[1], /Avg duration: 123s \| Max: 457s/);
});

test('buildDiagnosticSearchTerms includes agent skill and ScheduledJobService markers for agent jobs', () => {
  const terms = buildDiagnosticSearchTerms({
    name: 'system_guardian_agent',
    job_type: 'agent_task',
    command: 'system-guardian',
  });

  assert.deepEqual(terms, [
    'system_guardian_agent',
    'system-guardian',
    'ScheduledJobService: Starting agent task',
    'ScheduledJobService: Agent task returned',
    'ScheduledJobService: Returning failed agent task result',
    'ScheduledJobService: Agent task exception',
  ]);
});

test('buildDiagnosticSearchTerms keeps only the job name for non-agent jobs', () => {
  const terms = buildDiagnosticSearchTerms({
    name: 'rag_file_bulk_index',
    job_type: 'command',
    command: 'file-catalog:sync --rag-sync --limit=250',
  });

  assert.deepEqual(terms, ['rag_file_bulk_index']);
});

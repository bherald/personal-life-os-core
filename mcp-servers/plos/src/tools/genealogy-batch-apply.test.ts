import assert from 'node:assert/strict';
import test from 'node:test';
import type mysql from 'mysql2/promise';
import { closeMysql, getMysqlPool } from '../db/mysql.js';
import { genealogyBatchApply, genealogyBatchApplyInput } from './genealogy-batch-apply.js';

test('genealogy_batch_apply dry-run reports planned counts without DB access', async () => {
  const output = await genealogyBatchApply({
    tree_id: 4,
    dry_run: true,
    confirm: false,
    reason: 'unit dry-run batch',
    persons: [{ key: 'p1', given_name: 'Test', surname: 'Person' }],
    families: [{ key: 'f1', husband_key: 'p1' }],
    person_media: [{ person_key: 'p1', media_id: 10 }],
    citations: [{ source_id: 20, person_key: 'p1', fact_type: 'NOTE' }],
  });

  const result = JSON.parse(output);

  assert.equal(result.dry_run, true);
  assert.deepEqual(result.graph_guard, {
    schema: 'plos.genealogy_graph_guard.v1',
    status: 'planned',
    transactional_rollback: true,
    root_person_id: null,
  });
  assert.deepEqual(result.planned, {
    sources: 0,
    media_updates: 0,
    persons: 1,
    families: 1,
    children: 0,
    person_media: 1,
    family_media: 0,
    citations: 1,
    person_sources: 1,
  });
});

test('genealogy_batch_apply family schema preserves omission and accepts explicit null', () => {
  const parsed = genealogyBatchApplyInput.parse({
    tree_id: 4,
    dry_run: true,
    reason: 'unit family patch batch',
    families: [{ id: 9, notes: 'metadata only' }, { id: 10, husband_id: null }],
  });

  assert.equal(Object.hasOwn(parsed.families?.[0] ?? {}, 'husband_id'), false);
  assert.equal(parsed.families?.[1]?.husband_id, null);
});

test('genealogy_batch_apply schema requires a reason and positive tree_id', () => {
  assert.throws(
    () => genealogyBatchApplyInput.parse({ tree_id: 0, reason: 'bad tree' }),
    /Number must be greater than 0/
  );

  assert.throws(
    () => genealogyBatchApplyInput.parse({ tree_id: 4 }),
    /Required/
  );
});

test('genealogy_batch_apply source schema accepts URL fields', () => {
  const parsed = genealogyBatchApplyInput.parse({
    tree_id: 4,
    dry_run: true,
    reason: 'unit source url batch',
    sources: [{
      key: 's1',
      title: 'Source with URL',
      url: 'https://example.test/source.pdf',
    }],
  });

  assert.equal(parsed.sources?.[0]?.url, 'https://example.test/source.pdf');
});

test('genealogy_batch_apply person schema accepts burial fields and rejects unknown fields', () => {
  const parsed = genealogyBatchApplyInput.parse({
    tree_id: 4,
    dry_run: true,
    reason: 'unit burial field batch',
    persons: [{
      id: 42,
      burial_date: '3 JAN 1901',
      burial_place: 'Example Cemetery, Example County, Pennsylvania, USA',
    }],
  });

  assert.equal(parsed.persons?.[0]?.burial_date, '3 JAN 1901');
  assert.equal(parsed.persons?.[0]?.burial_place, 'Example Cemetery, Example County, Pennsylvania, USA');
  assert.throws(
    () => genealogyBatchApplyInput.parse({
      tree_id: 4,
      dry_run: true,
      reason: 'unit unknown person field batch',
      persons: [{ id: 42, unsupported_fact: 'must not be silently discarded' }],
    }),
    /Unrecognized key/
  );
});


test('media date is optional bounded GEDCOM text and dry-run has no database access', async () => {
  const parsed = genealogyBatchApplyInput.parse({
    tree_id: 4, reason: 'Operator-confirmed date-only projection',
    media_updates: [{ id: 1605, media_date: '21 JUN 2021' }, { id: 12484, media_date: '20 MAR 2000' }, { id: 9 }],
  });
  assert.equal(parsed.media_updates?.[0].media_date, '21 JUN 2021');
  assert.equal(Object.hasOwn(parsed.media_updates?.[2] ?? {}, 'media_date'), false);
  assert.equal(Object.hasOwn(parsed.media_updates?.[0] ?? {}, 'date_taken'), false);
  assert.throws(() => genealogyBatchApplyInput.parse({
    tree_id: 4, reason: 'Invalid oversized date', media_updates: [{ id: 1605, media_date: 'x'.repeat(51) }],
  }), /at most 50/);
  const result = JSON.parse(await genealogyBatchApply(parsed));
  assert.equal(result.dry_run, true);
  assert.equal(result.planned.media_updates, 3);
  assert.equal(result.updated.media, 0);
  assert.equal(result.touched_for_rag.media, 0);
});

test('media date apply retains tree admission, scoped update and existing RAG invalidation', async (t) => {
  const queries: Array<{ sql: string; params: unknown[] }> = [];
  let admitted = true;
  let commits = 0;
  let rollbacks = 0;
  const connection = {
    execute: async () => [[], []],
    beginTransaction: async () => {},
    commit: async () => { commits++; },
    rollback: async () => { rollbacks++; },
    release: () => {},
    query: async (sql: string, params: unknown[] = []) => {
      queries.push({ sql, params });
      if (sql.startsWith('SELECT id FROM genealogy_trees')) return [[{ id: 4 }], []];
      if (sql.startsWith('SELECT id FROM genealogy_media')) return [admitted ? [{ id: 1605 }] : [], []];
      if (sql.startsWith('SELECT gedcom_id')) return [[], []];
      if (sql.startsWith('UPDATE genealogy_media')) return [{ affectedRows: 1 }, []];
      throw new Error(`Unexpected boundary query: ${sql}`);
    },
  };
  t.mock.method(getMysqlPool(), 'getConnection', async () => connection as unknown as mysql.PoolConnection);
  try {
    const input = genealogyBatchApplyInput.parse({
      tree_id: 4, dry_run: false, confirm: true, reason: 'Verified operator catalog date projection',
      media_updates: [{ id: 1605, media_date: '21 JUN 2021' }],
    });
    const result = JSON.parse(await genealogyBatchApply(input));
    const admission = queries.find(q => q.sql.startsWith('SELECT id FROM genealogy_media'));
    assert.equal(admission?.sql, 'SELECT id FROM genealogy_media WHERE tree_id = ? AND id = ?');
    assert.deepEqual(admission?.params, [4, 1605]);
    const update = queries.find(q => q.sql.startsWith('UPDATE genealogy_media SET ?'));
    assert.equal(update?.sql, 'UPDATE genealogy_media SET ? WHERE tree_id = ? AND id = ?');
    const fields = update?.params[0] as Record<string, unknown>;
    assert.equal(fields.media_date, '21 JUN 2021');
    assert.equal(fields.rag_indexed_at, null);
    assert.equal(Object.hasOwn(fields, 'date_taken'), false);
    assert.deepEqual(update?.params.slice(1), [4, 1605]);
    assert.equal(result.updated.media, 1);
    assert.equal(result.touched_for_rag.media, 1);
    assert.equal(commits, 1);
    assert.deepEqual(queries.find(q => q.sql.includes('id IN (?)'))?.params, [4, [1605]]);

    queries.length = 0;
    admitted = false;
    await assert.rejects(genealogyBatchApply(input), /Media 1605 is not in tree 4/);
    assert.equal(queries.some(q => q.sql.startsWith('UPDATE')), false);
    assert.equal(rollbacks, 1);
    assert.equal(commits, 1);
  } finally {
    t.mock.restoreAll();
    await closeMysql();
  }
});

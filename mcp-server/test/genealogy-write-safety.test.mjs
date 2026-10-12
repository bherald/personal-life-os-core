import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildGraphSnapshot,
  evaluateGraphGuard,
  resolveOptionalReference,
} from '../dist/integrations/genealogyWriteSafety.js';

const people = [1, 2, 3, 4, 5, 6];
const families = [
  { id: 10, husband_id: 2, wife_id: 3 },
  { id: 11, husband_id: 1, wife_id: 4 },
  { id: 12, husband_id: 5, wife_id: 6 },
];
const children = [
  { family_id: 10, person_id: 1 },
  { family_id: 11, person_id: 5 },
];

test('allows additive graph changes and reports content-free deltas', () => {
  const before = buildGraphSnapshot(1, people, families, children);
  const after = buildGraphSnapshot(
    1,
    [...people, 7],
    [...families, { id: 13, husband_id: 5, wife_id: null }],
    [...children, { family_id: 13, person_id: 7 }]
  );

  const report = evaluateGraphGuard(before, after);
  assert.equal(report.status, 'passed');
  assert.deepEqual(report.delta, { people: 1, ancestors: 0, reachable: 1 });
  assert.equal(Object.hasOwn(report.before ?? {}, 'ancestor_ids'), false);
  assert.equal(Object.hasOwn(report.after ?? {}, 'reachable_ids'), false);
});

test('rejects direct ancestor regression', () => {
  const before = buildGraphSnapshot(1, people, families, children);
  const after = buildGraphSnapshot(1, people, families, children.filter((row) => row.family_id !== 10));

  assert.throws(
    () => evaluateGraphGuard(before, after),
    /ancestor count fell 2 -> 0/
  );
});

test('rejects a severed collateral branch even when ancestors are unchanged', () => {
  const before = buildGraphSnapshot(1, people, families, children);
  const after = buildGraphSnapshot(1, people, families.filter((row) => row.id !== 12), children);

  assert.equal(before.ancestors, after.ancestors);
  assert.throws(
    () => evaluateGraphGuard(before, after),
    /reachable count fell 6 -> 5/
  );
});

test('rejects equal-count collateral replacement', () => {
  const before = buildGraphSnapshot(1, people, families, children);
  const after = buildGraphSnapshot(
    1,
    [...people, 7],
    [...families.filter((row) => row.id !== 12), { id: 13, husband_id: 5, wife_id: 7 }],
    children
  );
  assert.equal(before.reachable, after.reachable);
  assert.throws(() => evaluateGraphGuard(before, after), /reachable membership regressed/);
});

test('treats an unconfigured tree root as observable but non-blocking', () => {
  const before = buildGraphSnapshot(null, people, families, children);
  const after = buildGraphSnapshot(null, [...people, 7], families, children);

  const report = evaluateGraphGuard(before, after);
  assert.equal(report.status, 'not_configured');
  assert.equal(report.delta.people, 1);
  assert.equal(report.delta.ancestors, null);
});

test('fails a configured but missing root pre-check', () => {
  const snapshot = buildGraphSnapshot(99, people, families, children);
  assert.throws(() => evaluateGraphGuard(snapshot, snapshot), /pre-check failed/);
});

test('family patch references distinguish omission, explicit null, and keys', () => {
  assert.deepEqual(
    resolveOptionalReference({ notes: 'keep links' }, 'husband_id', 'husband_key', {}, 'husband'),
    { specified: false, id: null }
  );
  assert.deepEqual(
    resolveOptionalReference({ husband_id: null }, 'husband_id', 'husband_key', {}, 'husband'),
    { specified: true, id: null }
  );
  assert.deepEqual(
    resolveOptionalReference({ husband_key: 'p1' }, 'husband_id', 'husband_key', { p1: 42 }, 'husband'),
    { specified: true, id: 42 }
  );
  assert.throws(
    () => resolveOptionalReference({ husband_key: 'missing' }, 'husband_id', 'husband_key', {}, 'husband'),
    /Missing husband reference for key missing/
  );
});

import test from 'node:test';
import assert from 'node:assert/strict';

import { guardQuery } from './query-guard.js';

test('blocks multiple SQL statements', () => {
  const result = guardQuery('SELECT 1; SELECT 2');
  assert.equal(result.allowed, false);
  assert.match(result.reason ?? '', /Multiple SQL statements/);
});

test('adds LIMIT to WITH queries', () => {
  const result = guardQuery('WITH sample AS (SELECT 1 AS id) SELECT * FROM sample');
  assert.equal(result.allowed, true);
  assert.match(result.sanitizedSql ?? '', /\sLIMIT 500$/);
});

test('blocks non-read statements by default', () => {
  const result = guardQuery('CALL rotate_logs()');
  assert.equal(result.allowed, false);
  assert.match(result.reason ?? '', /Only read-only SQL statements/);
});

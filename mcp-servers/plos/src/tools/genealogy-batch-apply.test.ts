import assert from 'node:assert/strict';
import test from 'node:test';
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
  assert.deepEqual(result.planned, {
    sources: 0,
    media_updates: 0,
    persons: 1,
    families: 1,
    children: 0,
    person_media: 1,
    family_media: 0,
    citations: 1,
  });
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

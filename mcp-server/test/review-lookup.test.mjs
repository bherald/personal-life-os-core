import assert from 'node:assert/strict';
import test from 'node:test';
import { ToolHandlers } from '../dist/handlers/tools.js';
import { ArtisanExecutor } from '../dist/integrations/artisan.js';
import { schemas, validateInput } from '../dist/security/validator.js';

test('review lookup validates an exact reference and returns the parent payload', async () => {
  const calls = [];
  const artisan = {
    executeCommand: async (command, args) => {
      calls.push({ command, args });
      return { exitCode: 0, stdout: JSON.stringify({ reference: 'review:1', revision: 2, status: 'history' }), stderr: '' };
    },
  };
  const handlers = new ToolHandlers({}, artisan);
  const result = await handlers.handleReviewLookup(validateInput(schemas.reviewLookup, { reference: ' review:1 ' }));
  assert.deepEqual(calls, [{ command: 'review:lookup', args: ['--', 'review:1'] }]);
  assert.deepEqual(JSON.parse(result.content[0].text), { reference: 'review:1', revision: 2, status: 'history' });
  assert.throws(() => validateInput(schemas.reviewLookup, { reference: '', approve: true }));
  assert.throws(() => validateInput(schemas.reviewLookup, { reference: 'x', approve: true }));
  for (const reference of ['--env=production', '--help', '-v', 'review:\n1', 'review:\u00001']) {
    assert.throws(() => validateInput(schemas.reviewLookup, { reference }));
  }
});

test('review lookup does not expose command errors or admit command variants', async () => {
  const handlers = new ToolHandlers({}, { executeCommand: async () => ({ exitCode: 1, stdout: '', stderr: '/private/path' }) });
  await assert.rejects(handlers.handleReviewLookup({ reference: 'x' }), /^Error: Review lookup failed$/);
  const executor = new ArtisanExecutor();
  assert.equal(executor.isCommandAllowed('review:lookup'), true);
  assert.equal(executor.isCommandAllowed('review:lookup-other'), false);
});

test('review lookup preserves null for an unknown reference', async () => {
  const handlers = new ToolHandlers({}, { executeCommand: async () => ({ exitCode: 0, stdout: 'null', stderr: '' }) });
  const result = await handlers.handleReviewLookup({ reference: 'missing' });
  assert.equal(result.content[0].text, 'null');
});

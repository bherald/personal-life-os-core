import assert from 'node:assert/strict';
import test from 'node:test';
import { buildToolSuccessResult, toolAnnotations } from './tool-result.js';

test('JSON object results preserve text and add structured content', async () => {
  const text = '{"tree_id":4,"people":[]}';
  const result = await buildToolSuccessResult(text);

  assert.equal(result.content[0].type, 'text');
  assert.deepEqual(result.structuredContent, { tree_id: 4, people: [] });
});

test('plain text and JSON arrays remain text-only for current MCP compatibility', async () => {
  assert.equal((await buildToolSuccessResult('plain text')).structuredContent, undefined);
  assert.equal((await buildToolSuccessResult('[1,2,3]')).structuredContent, undefined);
});

test('advertised output schemas fail closed on missing or malformed structured content', async () => {
  const outputSchema = {
    type: 'object',
    properties: {
      tree_id: { type: 'integer', minimum: 1 },
      people: { type: 'array' },
    },
    required: ['tree_id', 'people'],
    additionalProperties: false,
  };

  assert.deepEqual(
    (await buildToolSuccessResult('{"tree_id":4,"people":[]}', outputSchema)).structuredContent,
    { tree_id: 4, people: [] },
  );
  await assert.rejects(
    buildToolSuccessResult('plain text', outputSchema),
    /requires structured JSON content/,
  );
  await assert.rejects(
    buildToolSuccessResult('{"tree_id":"4","people":[]}', outputSchema),
    /does not conform/,
  );
  await assert.rejects(
    buildToolSuccessResult('{"tree_id":4,"people":[],"unexpected":true}', outputSchema),
    /does not conform/,
  );
});

test('tool annotations are conservative for read, write, and destructive tools', () => {
  assert.deepEqual(toolAnnotations('read'), {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  });
  assert.deepEqual(toolAnnotations('write'), {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  });
  assert.deepEqual(toolAnnotations('destructive', true), {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: true,
  });
});

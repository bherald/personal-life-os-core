import assert from 'node:assert/strict';
import test from 'node:test';

import { ErrorCode, McpError } from '@modelcontextprotocol/sdk/types.js';

import {
  classifyProbeFailure,
  readLiveToolContractWithReceipt,
} from './tool-contract-probe.js';

test('official SDK stdio client repeatedly lists tools and closes the child process', async () => {
  for (let attempt = 0; attempt < 8; attempt += 1) {
    const probe = await readLiveToolContractWithReceipt();
    const tools = probe.tools;

    assert.equal(tools.length, 31);
    assert.equal(new Set(tools.map(tool => tool.name)).size, tools.length);
    assert.deepEqual(probe.receipt, {
      attempts: 1,
      automatic_retry: false,
      request_timeout_ms: 8_000,
      max_pages: 64,
      failure_code: null,
      diagnostic_sha256: null,
    });
  }
});

test('classifies SDK and process failures without retaining their content', () => {
  assert.equal(
    classifyProbeFailure(new McpError(ErrorCode.ConnectionClosed, 'private connection detail')),
    'connection_closed',
  );
  assert.equal(
    classifyProbeFailure(new McpError(ErrorCode.RequestTimeout, 'private timeout detail')),
    'request_timeout',
  );
  assert.equal(
    classifyProbeFailure(new McpError(ErrorCode.InvalidRequest, 'private protocol detail')),
    'protocol_error',
  );

  const childError = new Error('private child detail') as NodeJS.ErrnoException;
  childError.code = 'ENOENT';
  assert.equal(classifyProbeFailure(childError), 'child_process_error');
  assert.equal(classifyProbeFailure(new Error('private unknown detail')), 'unknown');
});

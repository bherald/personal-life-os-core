import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import {
  getDefaultEnvironment,
  StdioClientTransport,
} from '@modelcontextprotocol/sdk/client/stdio.js';
import { ErrorCode, McpError } from '@modelcontextprotocol/sdk/types.js';

import type { McpToolContract } from './tool-contract.js';

const PROBE_TIMEOUT_MS = 8_000;
const MAX_TOOL_PAGES = 64;
const MAX_DIAGNOSTIC_BYTES = 64_000;

export type ToolContractProbeFailureCode =
  | 'connection_closed'
  | 'request_timeout'
  | 'child_process_error'
  | 'protocol_error'
  | 'unknown';

export type ToolContractProbeReceipt = {
  attempts: 1;
  automatic_retry: false;
  request_timeout_ms: number;
  max_pages: number;
  failure_code: ToolContractProbeFailureCode | null;
  diagnostic_sha256: string | null;
};

export class ToolContractProbeError extends Error {
  constructor(public readonly receipt: ToolContractProbeReceipt) {
    super(`PLOS MCP tools/list probe failed: ${receipt.failure_code ?? 'unknown'}`);
    this.name = 'ToolContractProbeError';
  }
}

export type LiveToolContractProbe = {
  tools: McpToolContract[];
  receipt: ToolContractProbeReceipt;
};

export async function readLiveToolContract(): Promise<McpToolContract[]> {
  return (await readLiveToolContractWithReceipt()).tools;
}

export async function readLiveToolContractWithReceipt(): Promise<LiveToolContractProbe> {
  const serverPath = fileURLToPath(new URL('./index.js', import.meta.url));
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [serverPath],
    cwd: process.cwd(),
    env: {
      ...getDefaultEnvironment(),
      LOG_FILE: '/tmp/plos-mcp-tool-contract-audit.log',
      NODE_ENV: 'development',
      PLOS_MCP_DOTENV_MODE: 'skip',
      SKIP_SSH_TUNNEL: 'true',
    },
    stderr: 'pipe',
  });
  const client = new Client({ name: 'plos-tool-contract-audit', version: '1.0.0' });
  const signal = AbortSignal.timeout(PROBE_TIMEOUT_MS);
  let diagnostics = '';
  let failure: unknown = null;
  let transportFailure: Error | null = null;
  let tools: McpToolContract[] | null = null;

  const recordDiagnostic = (value: unknown): void => {
    if (diagnostics.length >= MAX_DIAGNOSTIC_BYTES) return;

    const text = value instanceof Error ? `${value.name}:${value.message}` : String(value);
    diagnostics += text.slice(0, MAX_DIAGNOSTIC_BYTES - diagnostics.length);
  };

  transport.stderr?.on('data', recordDiagnostic);
  client.onerror = error => {
    transportFailure ??= error;
    recordDiagnostic(error);
  };

  try {
    await client.connect(transport, { signal });

    const discovered: McpToolContract[] = [];
    let cursor: string | undefined;
    let pageCount = 0;
    do {
      pageCount += 1;
      if (pageCount > MAX_TOOL_PAGES) {
        throw new Error('MCP tools/list pagination exceeded the bounded page limit.');
      }

      const page = await client.listTools(
        cursor === undefined ? undefined : { cursor },
        { signal },
      );
      discovered.push(...page.tools as McpToolContract[]);
      cursor = page.nextCursor;
    } while (cursor !== undefined);

    if (transportFailure !== null) throw transportFailure;
    tools = discovered;
  } catch (error) {
    failure = error;
    recordDiagnostic(error);
  }

  try {
    await client.close();
  } catch (error) {
    failure ??= error;
    recordDiagnostic(error);
  }

  if (failure !== null || tools === null) {
    throw new ToolContractProbeError(receiptForFailure(failure, diagnostics));
  }

  return {
    tools,
    receipt: {
      attempts: 1,
      automatic_retry: false,
      request_timeout_ms: PROBE_TIMEOUT_MS,
      max_pages: MAX_TOOL_PAGES,
      failure_code: null,
      diagnostic_sha256: null,
    },
  };
}

function fingerprint(value: string): string {
  return createHash('sha256').update(value, 'utf8').digest('hex');
}

function receiptForFailure(failure: unknown, diagnostics: string): ToolContractProbeReceipt {
  return {
    attempts: 1,
    automatic_retry: false,
    request_timeout_ms: PROBE_TIMEOUT_MS,
    max_pages: MAX_TOOL_PAGES,
    failure_code: classifyProbeFailure(failure),
    diagnostic_sha256: fingerprint(diagnostics),
  };
}

export function classifyProbeFailure(failure: unknown): ToolContractProbeFailureCode {
  if (failure instanceof McpError) {
    if (failure.code === ErrorCode.ConnectionClosed) return 'connection_closed';
    if (failure.code === ErrorCode.RequestTimeout) return 'request_timeout';

    return 'protocol_error';
  }

  if (failure instanceof Error) {
    const message = failure.message.toLowerCase();
    if (failure.name === 'TimeoutError' || message.includes('timed out')) {
      return 'request_timeout';
    }
    if (message.includes('connection closed')) return 'connection_closed';

    const code = (failure as NodeJS.ErrnoException).code;
    if (code !== undefined || message.includes('spawn ') || message.includes('epipe')) {
      return 'child_process_error';
    }
  }

  return 'unknown';
}

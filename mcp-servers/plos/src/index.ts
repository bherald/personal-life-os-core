#!/usr/bin/env node

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';

import { CONFIG } from './config.js';
import { startTunnels, stopTunnels } from './db/ssh-tunnel.js';
import { closeMysql } from './db/mysql.js';
import { closePg } from './db/postgres.js';
import { logger } from './util/logger.js';
import type { ToolContext } from './util/tool-context.js';

// Tools - PLOS data access
import { plosSchema, plosSchemaInput } from './tools/plos-schema.js';
import { plosQueryTool, plosQueryInput } from './tools/plos-query.js';
import { plosHealth, plosHealthInput } from './tools/plos-health.js';
import { plosJobStatus, plosJobStatusInput } from './tools/plos-job-status.js';
import { plosAgentStatus, plosAgentStatusInput } from './tools/plos-agent-status.js';
import { plosLogSearch, plosLogSearchInput } from './tools/plos-log-search.js';
import { plosConfig, plosConfigInput } from './tools/plos-config.js';
import { plosArtisan, plosArtisanInput } from './tools/plos-artisan.js';
import { plosCacheClear, plosCacheClearInput } from './tools/plos-cache-clear.js';
import { plosJobDiagnostic, plosJobDiagnosticInput } from './tools/plos-job-diagnostic.js';
import { plosTinker, plosTinkerInput } from './tools/plos-tinker.js';
import { plosSsh, plosSshInput } from './tools/plos-ssh.js';
import { plosDecompose, plosDecomposeInput } from './tools/plos-decompose.js';
import { genealogyContext, genealogyContextInput } from './tools/genealogy-context.js';
import { genealogyBatchApply, genealogyBatchApplyInput } from './tools/genealogy-batch-apply.js';

// Tools - Ollama delegation
import { ollamaSummarize, ollamaSummarizeInput } from './tools/ollama-summarize.js';
import { ollamaClassifyError, ollamaClassifyErrorInput } from './tools/ollama-classify-error.js';
import { ollamaExplainDiff, ollamaExplainDiffInput } from './tools/ollama-explain-diff.js';
import { ollamaDescribeCode, ollamaDescribeCodeInput } from './tools/ollama-describe-code.js';
import { ollamaDraftCommit, ollamaDraftCommitInput } from './tools/ollama-draft-commit.js';
import { ollamaDraft, ollamaDraftInput } from './tools/ollama-draft.js';

// Convert zod schema to JSON Schema for MCP tool definitions
function zodToJsonSchema(schema: z.ZodObject<any>): Record<string, unknown> {
  const shape = schema.shape;
  const properties: Record<string, unknown> = {};
  const required: string[] = [];

  for (const [key, value] of Object.entries(shape)) {
    const zodField = value as z.ZodTypeAny;
    const def = zodField._def;

    let prop: Record<string, unknown> = {};

    // Unwrap optional/default (recursive — handles z.number().optional().default(24) etc.)
    let innerDef = def;
    let isOptional = false;
    while (innerDef.typeName === 'ZodOptional' || innerDef.typeName === 'ZodDefault') {
      isOptional = true;
      innerDef = innerDef.innerType?._def ?? innerDef;
      if (!innerDef.typeName || innerDef === def) break; // safety valve
    }

    // Map types
    switch (innerDef.typeName) {
      case 'ZodString':
        prop.type = 'string';
        break;
      case 'ZodNumber':
        prop.type = 'number';
        break;
      case 'ZodBoolean':
        prop.type = 'boolean';
        break;
      case 'ZodEnum':
        prop.type = 'string';
        prop.enum = innerDef.values;
        break;
      case 'ZodArray':
        prop.type = 'array';
        break;
      default:
        prop.type = 'string';
    }

    // Add description from zod .describe()
    if (zodField.description) prop.description = zodField.description;
    if (def.description) prop.description = def.description;
    // Check inner type description too
    const inner = def.innerType;
    if (inner?.description) prop.description = inner.description;
    if (inner?._def?.innerType?.description) prop.description = inner._def.innerType.description;

    properties[key] = prop;
    if (!isOptional) required.push(key);
  }

  return {
    type: 'object',
    properties,
    ...(required.length > 0 ? { required } : {}),
  };
}

// Tool registry
type ToolDefinition = {
  name: string;
  description: string;
  inputSchema: Record<string, unknown>;
  zodSchema: z.ZodObject<any>;
  handler: (input: any, context?: ToolContext) => Promise<string>;
};

const TOOLS: ToolDefinition[] = [
  {
    name: 'plos_schema',
    description: 'Look up table schema from schema-reference.md or live DESCRIBE. Use before writing any SQL.',
    inputSchema: zodToJsonSchema(plosSchemaInput),
    zodSchema: plosSchemaInput,
    handler: plosSchema,
  },
  {
    name: 'plos_query',
    description: 'Execute SQL query against configured MySQL or PostgreSQL. Read-only by default. Auto-adds LIMIT 500 to unbounded SELECTs.',
    inputSchema: zodToJsonSchema(plosQueryInput),
    zodSchema: plosQueryInput,
    handler: plosQueryTool,
  },
  {
    name: 'plos_health',
    description: 'System health snapshot: jobs, agents, queues, errors, GPU/disk. Single call replaces multiple SSH commands.',
    inputSchema: zodToJsonSchema(plosHealthInput),
    zodSchema: plosHealthInput,
    handler: plosHealth,
  },
  {
    name: 'plos_job_status',
    description: 'Scheduled job status with run history. Filter by name or status.',
    inputSchema: zodToJsonSchema(plosJobStatusInput),
    zodSchema: plosJobStatusInput,
    handler: plosJobStatus,
  },
  {
    name: 'plos_agent_status',
    description: 'Agent session health and recent activity. Shows tokens, messages, review items.',
    inputSchema: zodToJsonSchema(plosAgentStatusInput),
    zodSchema: plosAgentStatusInput,
    handler: plosAgentStatus,
  },
  {
    name: 'plos_log_search',
    description: 'Search the configured PLOS Laravel log by pattern and log level. Faster than SSH + grep.',
    inputSchema: zodToJsonSchema(plosLogSearchInput),
    zodSchema: plosLogSearchInput,
    handler: plosLogSearch,
  },
  {
    name: 'plos_config',
    description: 'Read/write SystemConfig values from system_configs table.',
    inputSchema: zodToJsonSchema(plosConfigInput),
    zodSchema: plosConfigInput,
    handler: plosConfig,
  },
  {
    name: 'plos_artisan',
    description: 'Run whitelisted artisan commands on the configured remote instance or local dev. Use command "list" to see available commands. Covers: ops validation, smoke tests, health gates, file/RAG/graph stats, workflow listing.',
    inputSchema: zodToJsonSchema(plosArtisanInput),
    zodSchema: plosArtisanInput,
    handler: plosArtisan,
  },
  {
    name: 'plos_cache_clear',
    description: 'Clear Laravel caches on the configured remote instance (cache, config, route, view, event) + optional Redis flush + Horizon/queue restart.',
    inputSchema: zodToJsonSchema(plosCacheClearInput),
    zodSchema: plosCacheClearInput,
    handler: plosCacheClear,
  },
  {
    name: 'plos_job_diagnostic',
    description: 'Deep diagnostic for a scheduled job: config, last N runs with output, 24h failure patterns, related alerts, system errors, and log context. Single call replaces 5-6 queries.',
    inputSchema: zodToJsonSchema(plosJobDiagnosticInput),
    zodSchema: plosJobDiagnosticInput,
    handler: plosJobDiagnostic,
  },
  {
    name: 'plos_tinker',
    description: 'Execute PHP code via artisan tinker on the configured remote instance or local dev. Full access to Laravel app container, services, DB facades. Use for service calls, data inspection, one-off operations. Blocks DROP DATABASE, rm -rf /, .env writes.',
    inputSchema: zodToJsonSchema(plosTinkerInput),
    zodSchema: plosTinkerInput,
    handler: plosTinker,
  },
  {
    name: 'plos_ssh',
    description: 'Execute shell commands on the configured remote PLOS instance via SSH. For npm builds, service checks, file ops, and anything not covered by other PLOS tools. Blocks dangerous commands (rm -rf /, sudo, reboot, etc.).',
    inputSchema: zodToJsonSchema(plosSshInput),
    zodSchema: plosSshInput,
    handler: plosSsh,
  },
  {
    name: 'plos_decompose',
    description: 'Process text through PLOS AIService with RLM auto-decompose. Large prompts (>8K tokens) are automatically split into smaller chunks, processed with fast local models, and synthesized with a quality model. Use for analyzing large documents, research content, or any text that benefits from context shrinkage.',
    inputSchema: zodToJsonSchema(plosDecomposeInput),
    zodSchema: plosDecomposeInput,
    handler: plosDecompose,
  },
  {
    name: 'genealogy_context',
    description: 'Return compact genealogy context for selected person, family, media, source, and citation IDs.',
    inputSchema: zodToJsonSchema(genealogyContextInput),
    zodSchema: genealogyContextInput,
    handler: genealogyContext,
  },
  {
    name: 'genealogy_batch_apply',
    description: 'Apply guarded tree-scoped genealogy batches for sources, persons, families, child links, media links, citations, media metadata, and RAG reindex touches. Defaults to dry_run.',
    inputSchema: zodToJsonSchema(genealogyBatchApplyInput),
    zodSchema: genealogyBatchApplyInput,
    handler: genealogyBatchApply,
  },
  {
    name: 'ollama_summarize',
    description: 'Summarize text using a configured local Ollama instance. Useful for log analysis, file review, and other context-reduction tasks.',
    inputSchema: zodToJsonSchema(ollamaSummarizeInput),
    zodSchema: ollamaSummarizeInput,
    handler: ollamaSummarize,
  },
  {
    name: 'ollama_classify_error',
    description: 'Classify and triage error/stack trace using Ollama. Returns: type, root cause, fix suggestion.',
    inputSchema: zodToJsonSchema(ollamaClassifyErrorInput),
    zodSchema: ollamaClassifyErrorInput,
    handler: ollamaClassifyError,
  },
  {
    name: 'ollama_explain_diff',
    description: 'Summarize a git diff into bullet points using Ollama code model.',
    inputSchema: zodToJsonSchema(ollamaExplainDiffInput),
    zodSchema: ollamaExplainDiffInput,
    handler: ollamaExplainDiff,
  },
  {
    name: 'ollama_describe_code',
    description: 'Describe a code file: purpose, methods, dependencies, patterns. Uses Ollama code model.',
    inputSchema: zodToJsonSchema(ollamaDescribeCodeInput),
    zodSchema: ollamaDescribeCodeInput,
    handler: ollamaDescribeCode,
  },
  {
    name: 'ollama_draft_commit',
    description: 'Draft a git commit message from a diff using Ollama.',
    inputSchema: zodToJsonSchema(ollamaDraftCommitInput),
    zodSchema: ollamaDraftCommitInput,
    handler: ollamaDraftCommit,
  },
  {
    name: 'ollama_draft',
    description: 'Draft code, SQL, or content using Ollama. Routes to best model by type (codestral for code, sqlcoder for SQL).',
    inputSchema: zodToJsonSchema(ollamaDraftInput),
    zodSchema: ollamaDraftInput,
    handler: ollamaDraft,
  },
];

async function main() {
  logger.info('PLOS MCP Server starting', { tools: TOOLS.length });

  // Start SSH tunnels for DB access
  try {
    await startTunnels();
  } catch (err) {
    logger.error('SSH tunnel failed — DB tools will error on use', { error: (err as Error).message });
    // Don't exit — Ollama tools still work without DB
  }

  const server = new Server(
    { name: 'plos', version: '1.0.0' },
    { capabilities: { tools: {} } }
  );

  // List tools
  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: TOOLS.map(t => ({
      name: t.name,
      description: t.description,
      inputSchema: t.inputSchema,
    })),
  }));

  // Handle tool calls — every handler wrapped with a global timeout safety net
  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args } = request.params;
    const tool = TOOLS.find(t => t.name === name);

    if (!tool) {
      return {
        content: [{ type: 'text' as const, text: `Unknown tool: ${name}` }],
        isError: true,
      };
    }

    let timedOut = false;

    try {
      const parsed = tool.zodSchema.parse(args);
      const abortController = new AbortController();

      // Global timeout guard — abort cooperating tools and fail fast for the MCP caller.
      const timeoutPromise = new Promise<never>((_, reject) => {
        const t = setTimeout(() => {
          timedOut = true;
          abortController.abort();
          reject(new Error(`Tool "${name}" timed out after ${CONFIG.toolTimeoutMs / 1000}s`));
        }, CONFIG.toolTimeoutMs);
        if (typeof t === 'object' && 'unref' in t) t.unref();
      });

      const result = await Promise.race([
        tool.handler(parsed as any, { signal: abortController.signal }),
        timeoutPromise,
      ]);

      logger.info('Tool call success', { tool: name });

      return {
        content: [{ type: 'text' as const, text: result }],
      };
    } catch (err) {
      const msg = (err as Error).name === 'AbortError'
        ? timedOut
          ? `Tool "${name}" timed out after ${CONFIG.toolTimeoutMs / 1000}s`
          : `Tool "${name}" aborted`
        : (err as Error).message;
      logger.error('Tool call failed', { tool: name, error: msg });

      return {
        content: [{ type: 'text' as const, text: `Error: ${msg}` }],
        isError: true,
      };
    }
  });

  // Graceful shutdown
  process.on('SIGINT', async () => {
    logger.info('Shutting down');
    await closeMysql();
    await closePg();
    stopTunnels();
    process.exit(0);
  });

  process.on('SIGTERM', async () => {
    await closeMysql();
    await closePg();
    stopTunnels();
    process.exit(0);
  });

  // Start
  const transport = new StdioServerTransport();
  await server.connect(transport);
  logger.info('PLOS MCP Server running on stdio');
}

// Prevent unhandled errors from silently killing the MCP process
process.on('unhandledRejection', (reason) => {
  logger.error('Unhandled rejection', { error: String(reason) });
});

process.on('uncaughtException', (err) => {
  logger.error('Uncaught exception', { error: err.message, stack: err.stack });
  // Don't exit — let MCP transport continue serving other tools
});

main().catch((err) => {
  logger.error('Fatal error', { error: (err as Error).message });
  process.exit(1);
});

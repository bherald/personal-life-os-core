#!/usr/bin/env node

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ErrorCode,
  ListResourcesRequestSchema,
  ListToolsRequestSchema,
  McpError,
  ReadResourceRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { zodToJsonSchema as convertZodToJsonSchema } from 'zod-to-json-schema';

import { CONFIG } from './config.js';
import { startTunnels, stopTunnels } from './db/ssh-tunnel.js';
import { closeMysql } from './db/mysql.js';
import { closePg } from './db/postgres.js';
import { logger } from './util/logger.js';
import type { ToolContext } from './util/tool-context.js';
import {
  runToolWithLifecycle,
  ToolCancelledError,
  ToolTimeoutError,
} from './util/tool-lifecycle.js';
import { runToolWithProgress } from './util/tool-progress.js';
import { buildToolSuccessResult, toolAnnotations, type ToolRisk } from './util/tool-result.js';

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
import {
  plosOfflineCapabilities,
  plosOfflineCapabilitiesInput,
  plosOfflineImprovement,
  plosOfflineImprovementInput,
  plosOfflinePipelineTools,
  plosOfflinePipelineToolsInput,
  plosOfflineSidecar,
  plosOfflineSidecarInput,
  plosColibriLocalResearch,
  plosColibriLocalResearchInput,
  plosColibriResearch,
  plosColibriResearchInput,
} from './tools/plos-offline-codex.js';
import {
  plosAudioTts,
  plosAudioTtsInput,
  plosRerank,
  plosRerankInput,
} from './tools/plos-offline-modalities.js';
import {
  plosImageGenerate,
  plosImageGenerateInput,
  plosMediaAnalyze,
  plosMediaAnalyzeInput,
} from './tools/plos-offline-media.js';
import { genealogyContext, genealogyContextInput } from './tools/genealogy-context.js';
import { genealogySearch, genealogySearchInput } from './tools/genealogy-search.js';
import { genealogyBatchApply, genealogyBatchApplyInput } from './tools/genealogy-batch-apply.js';

// Tools - Ollama delegation
import { ollamaSummarize, ollamaSummarizeInput } from './tools/ollama-summarize.js';
import { ollamaClassifyError, ollamaClassifyErrorInput } from './tools/ollama-classify-error.js';
import { ollamaExplainDiff, ollamaExplainDiffInput } from './tools/ollama-explain-diff.js';
import { ollamaDescribeCode, ollamaDescribeCodeInput } from './tools/ollama-describe-code.js';
import { ollamaDraftCommit, ollamaDraftCommitInput } from './tools/ollama-draft-commit.js';
import { ollamaDraft, ollamaDraftInput } from './tools/ollama-draft.js';

// Convert the full Zod contract, including nested arrays, unions, defaults,
// bounds, and descriptions, into the schema MCP clients actually receive.
function zodToJsonSchema(schema: z.ZodObject<any>): Record<string, unknown> {
  const converted = convertZodToJsonSchema(schema, {
    $refStrategy: 'none',
    target: 'jsonSchema7',
  }) as Record<string, unknown>;

  delete converted.$schema;
  delete converted.definitions;

  return converted;
}

const nonNegativeCountMapSchema = {
  type: 'object',
  additionalProperties: { type: 'integer', minimum: 0 },
};

const genealogySearchOutputSchema = {
  type: 'object',
  properties: {
    schema: { const: 'plos.genealogy_search.v1' },
    read_only: { const: true },
    tree_id: { type: 'integer', minimum: 1 },
    query: { type: 'string' },
    entity_type: { enum: ['all', 'person', 'family', 'source', 'media'] },
    result_count: { type: 'integer', minimum: 0 },
    duration_ms: { type: 'integer', minimum: 0 },
    living_person_details_redacted: { const: true },
    results: {
      type: 'object',
      additionalProperties: { type: 'array' },
    },
  },
  required: [
    'schema',
    'read_only',
    'tree_id',
    'query',
    'entity_type',
    'result_count',
    'duration_ms',
    'living_person_details_redacted',
    'results',
  ],
  additionalProperties: false,
};

const genealogyContextOutputSchema = {
  type: 'object',
  properties: {
    tree_id: { type: 'integer', minimum: 1 },
    people: { type: 'array' },
    families: { type: 'array' },
    children: { type: 'array' },
    media: { type: 'array' },
    media_links: { type: 'array' },
    citations: { type: 'array' },
    sources: { type: 'array' },
  },
  required: ['tree_id'],
  additionalProperties: false,
};

const genealogyGraphSnapshotSchema = {
  type: 'object',
  properties: {
    root_person_id: { type: ['integer', 'null'], minimum: 1 },
    root_present: { type: 'boolean' },
    people: { type: 'integer', minimum: 0 },
    ancestors: { type: ['integer', 'null'], minimum: 0 },
    reachable: { type: ['integer', 'null'], minimum: 0 },
  },
  required: ['root_person_id', 'root_present', 'people', 'ancestors', 'reachable'],
  additionalProperties: false,
};

const genealogyGraphDeltaSchema = {
  type: 'object',
  properties: {
    people: { type: 'integer' },
    ancestors: { type: ['integer', 'null'] },
    reachable: { type: ['integer', 'null'] },
  },
  required: ['people', 'ancestors', 'reachable'],
  additionalProperties: false,
};

const genealogyBatchApplyOutputSchema = {
  type: 'object',
  properties: {
    dry_run: { type: 'boolean' },
    tree_id: { type: 'integer', minimum: 1 },
    reason: { type: 'string', minLength: 1 },
    inserted: nonNegativeCountMapSchema,
    updated: nonNegativeCountMapSchema,
    touched_for_rag: nonNegativeCountMapSchema,
    keys: {
      type: 'object',
      additionalProperties: { type: 'integer', minimum: 1 },
    },
    planned: nonNegativeCountMapSchema,
    graph_guard: {
      type: 'object',
      properties: {
        schema: { const: 'plos.genealogy_graph_guard.v1' },
        status: { enum: ['not_required', 'planned', 'not_configured', 'passed'] },
        transactional_rollback: { const: true },
        root_person_id: { type: ['integer', 'null'], minimum: 1 },
        before: genealogyGraphSnapshotSchema,
        after: genealogyGraphSnapshotSchema,
        delta: genealogyGraphDeltaSchema,
      },
      required: ['schema', 'status', 'transactional_rollback', 'root_person_id'],
      additionalProperties: false,
    },
  },
  required: ['dry_run', 'tree_id', 'reason', 'inserted', 'updated', 'touched_for_rag', 'keys', 'graph_guard'],
  additionalProperties: false,
};

// Tool registry
type ToolDefinition = {
  name: string;
  description: string;
  inputSchema: Record<string, unknown>;
  zodSchema: z.ZodObject<any>;
  handler: (input: any, context?: ToolContext) => Promise<string>;
  risk: ToolRisk;
  openWorld?: boolean;
  timeoutMs?: number;
  outputSchema?: Record<string, unknown>;
};

const TOOLS: ToolDefinition[] = [
  {
    name: 'plos_schema',
    description: 'Look up table schema from schema-reference.md or live DESCRIBE. Use before writing any SQL.',
    inputSchema: zodToJsonSchema(plosSchemaInput),
    zodSchema: plosSchemaInput,
    handler: plosSchema,
    risk: 'read',
  },
  {
    name: 'plos_query',
    description: 'Execute SQL query against configured MySQL or PostgreSQL. Read-only by default. Auto-adds LIMIT 500 to unbounded SELECTs.',
    inputSchema: zodToJsonSchema(plosQueryInput),
    zodSchema: plosQueryInput,
    handler: plosQueryTool,
    risk: 'write',
  },
  {
    name: 'plos_health',
    description: 'System health snapshot: jobs, agents, queues, errors, GPU/disk. Single call replaces multiple SSH commands.',
    inputSchema: zodToJsonSchema(plosHealthInput),
    zodSchema: plosHealthInput,
    handler: plosHealth,
    risk: 'read',
  },
  {
    name: 'plos_job_status',
    description: 'Scheduled job status with run history. Filter by name or status.',
    inputSchema: zodToJsonSchema(plosJobStatusInput),
    zodSchema: plosJobStatusInput,
    handler: plosJobStatus,
    risk: 'read',
  },
  {
    name: 'plos_agent_status',
    description: 'Agent session health and recent activity. Shows tokens, messages, review items.',
    inputSchema: zodToJsonSchema(plosAgentStatusInput),
    zodSchema: plosAgentStatusInput,
    handler: plosAgentStatus,
    risk: 'read',
  },
  {
    name: 'plos_log_search',
    description: 'Search the configured PLOS Laravel log by pattern and log level. Faster than SSH + grep.',
    inputSchema: zodToJsonSchema(plosLogSearchInput),
    zodSchema: plosLogSearchInput,
    handler: plosLogSearch,
    risk: 'read',
  },
  {
    name: 'plos_config',
    description: 'Read/write SystemConfig values from system_configs table.',
    inputSchema: zodToJsonSchema(plosConfigInput),
    zodSchema: plosConfigInput,
    handler: plosConfig,
    risk: 'write',
  },
  {
    name: 'plos_artisan',
    description: 'Run whitelisted artisan commands on the configured remote instance or local dev. Use command "list" to see available commands. Covers: ops validation, smoke tests, health gates, file/RAG/graph stats, workflow listing.',
    inputSchema: zodToJsonSchema(plosArtisanInput),
    zodSchema: plosArtisanInput,
    handler: plosArtisan,
    risk: 'write',
    openWorld: true,
  },
  {
    name: 'plos_cache_clear',
    description: 'Invalidate named Laravel configuration/inventory snapshots and file caches on the configured remote instance. Global Redis flushing is held; Horizon/queue restart requires explicit disruptive confirmation.',
    inputSchema: zodToJsonSchema(plosCacheClearInput),
    zodSchema: plosCacheClearInput,
    handler: plosCacheClear,
    risk: 'destructive',
  },
  {
    name: 'plos_job_diagnostic',
    description: 'Deep diagnostic for a scheduled job: config, last N runs with output, 24h failure patterns, related alerts, system errors, and log context. Single call replaces 5-6 queries.',
    inputSchema: zodToJsonSchema(plosJobDiagnosticInput),
    zodSchema: plosJobDiagnosticInput,
    handler: plosJobDiagnostic,
    risk: 'read',
  },
  {
    name: 'plos_tinker',
    description: 'Execute PHP code via artisan tinker on the configured remote instance or local dev. Full access to Laravel app container, services, DB facades. Use for service calls, data inspection, one-off operations. Blocks DROP DATABASE, rm -rf /, .env writes.',
    inputSchema: zodToJsonSchema(plosTinkerInput),
    zodSchema: plosTinkerInput,
    handler: plosTinker,
    risk: 'destructive',
    openWorld: true,
    // Preserve the handler's 120s subprocess cap, with bounded time to return timeout output.
    timeoutMs: 125_000,
  },
  {
    name: 'plos_ssh',
    description: 'Execute shell commands on the configured remote PLOS instance via SSH. For npm builds, service checks, file ops, and anything not covered by other PLOS tools. Blocks dangerous commands (rm -rf /, sudo, reboot, etc.).',
    inputSchema: zodToJsonSchema(plosSshInput),
    zodSchema: plosSshInput,
    handler: plosSsh,
    risk: 'destructive',
    openWorld: true,
  },
  {
    name: 'plos_offline_capabilities',
    description: 'Return the offline Codex/PLOS capability manifest: SDK-like modality surface, Codex profile, MCP posture, sidecar coordination, media input/output posture, transcription, embeddings, model catalog, and operator gates.',
    inputSchema: zodToJsonSchema(plosOfflineCapabilitiesInput),
    zodSchema: plosOfflineCapabilitiesInput,
    handler: plosOfflineCapabilities,
    risk: 'read',
  },
  {
    name: 'plos_offline_pipeline_tools',
    description: 'Return the ordered PLOS AI pipeline inventory for offline agents: AIService execution order, tool-calling order, configured ollama_pipeline task stages/options, MCP tool order, route/provider controls, and live DB inventory when available.',
    inputSchema: zodToJsonSchema(plosOfflinePipelineToolsInput),
    zodSchema: plosOfflinePipelineToolsInput,
    handler: plosOfflinePipelineTools,
    risk: 'read',
  },
  {
    name: 'plos_offline_sidecar',
    description: 'Plan or run a read-only multi-model offline Codex sidecar split. Uses primary local Codex for planning and Jetson Codex for independent review; writeback remains outside this tool behind the normal approval/editor gate.',
    inputSchema: zodToJsonSchema(plosOfflineSidecarInput),
    zodSchema: plosOfflineSidecarInput,
    handler: plosOfflineSidecar,
    risk: 'read',
    timeoutMs: 1_260_000,
  },
  {
    name: 'plos_colibri_research',
    description: 'Plan or run the fail-closed Colibri public-web research canary. PLOS executes only its exact read-only SearXNG allowlist; normal Colibri routes stay tool-free. Defaults to the configured local checkout; runtime and source access require installation qualification.',
    inputSchema: zodToJsonSchema(plosColibriResearchInput),
    zodSchema: plosColibriResearchInput,
    handler: plosColibriResearch,
    risk: 'read',
    openWorld: true,
    timeoutMs: 1_830_000,
  },
  {
    name: 'plos_colibri_local_research',
    description: 'Plan or run the separately gated Colibri local-offline research canary. PLOS exposes only exact read-only RAG search, similar-document, and resumable-read operations; indexing, SQL, filesystem, public web, writes, and fallback remain unavailable.',
    inputSchema: zodToJsonSchema(plosColibriLocalResearchInput),
    zodSchema: plosColibriLocalResearchInput,
    handler: plosColibriLocalResearch,
    risk: 'read',
    openWorld: false,
    timeoutMs: 1_830_000,
  },
  {
    name: 'plos_offline_improvement',
    description: 'Create a review-gated offline improvement proposal from a task lesson. Defaults observe-only; write_report=true stores a JSON review packet but does not mutate skills, memory, or production data.',
    inputSchema: zodToJsonSchema(plosOfflineImprovementInput),
    zodSchema: plosOfflineImprovementInput,
    handler: plosOfflineImprovement,
    risk: 'write',
  },
  {
    name: 'plos_audio_tts',
    description: 'Probe or run local offline text-to-speech through the PLOS audio:tts adapter. Default is dry-run/probe; execute=true is required to create an audio file.',
    inputSchema: zodToJsonSchema(plosAudioTtsInput),
    zodSchema: plosAudioTtsInput,
    handler: plosAudioTts,
    risk: 'write',
    timeoutMs: 180_000,
  },
  {
    name: 'plos_rerank',
    description: 'Rerank supplied documents with the PLOS generic offline reranker. Defaults to lexical-only and can optionally use table-routed embeddings.',
    inputSchema: zodToJsonSchema(plosRerankInput),
    zodSchema: plosRerankInput,
    handler: plosRerank,
    risk: 'read',
    timeoutMs: 300_000,
  },
  {
    name: 'plos_media_analyze',
    description: 'Analyze a local image with an installed offline Ollama vision model. Default is dry-run validation; execute=true is required to send the image to the local model.',
    inputSchema: zodToJsonSchema(plosMediaAnalyzeInput),
    zodSchema: plosMediaAnalyzeInput,
    handler: plosMediaAnalyze,
    risk: 'write',
    timeoutMs: 300_000,
  },
  {
    name: 'plos_image_generate',
    description: 'Generate an image through the configured local PLOS image adapter. Default is dry-run validation; execute=true is required to create an image file.',
    inputSchema: zodToJsonSchema(plosImageGenerateInput),
    zodSchema: plosImageGenerateInput,
    handler: plosImageGenerate,
    risk: 'write',
    timeoutMs: 900_000,
  },
  {
    name: 'genealogy_search',
    description: 'Discover tree-scoped person, family, source, and media IDs with bounded literal search before requesting detailed genealogy context.',
    inputSchema: zodToJsonSchema(genealogySearchInput),
    zodSchema: genealogySearchInput,
    handler: genealogySearch,
    risk: 'read',
    outputSchema: genealogySearchOutputSchema,
  },
  {
    name: 'genealogy_context',
    description: 'Return compact genealogy context for selected person, family, media, source, and citation IDs.',
    inputSchema: zodToJsonSchema(genealogyContextInput),
    zodSchema: genealogyContextInput,
    handler: genealogyContext,
    risk: 'read',
    outputSchema: genealogyContextOutputSchema,
  },
  {
    name: 'genealogy_batch_apply',
    description: 'Apply guarded tree-scoped genealogy batches in one transaction. Defaults to dry_run, preserves omitted family links, and rolls back protected-root ancestor or reachability regressions.',
    inputSchema: zodToJsonSchema(genealogyBatchApplyInput),
    zodSchema: genealogyBatchApplyInput,
    handler: genealogyBatchApply,
    risk: 'write',
    outputSchema: genealogyBatchApplyOutputSchema,
  },
  {
    name: 'ollama_summarize',
    description: 'Summarize text using a configured local Ollama instance. Useful for log analysis, file review, and other context-reduction tasks.',
    inputSchema: zodToJsonSchema(ollamaSummarizeInput),
    zodSchema: ollamaSummarizeInput,
    handler: ollamaSummarize,
    risk: 'read',
  },
  {
    name: 'ollama_classify_error',
    description: 'Classify and triage error/stack trace using Ollama. Returns: type, root cause, fix suggestion.',
    inputSchema: zodToJsonSchema(ollamaClassifyErrorInput),
    zodSchema: ollamaClassifyErrorInput,
    handler: ollamaClassifyError,
    risk: 'read',
  },
  {
    name: 'ollama_explain_diff',
    description: 'Summarize a git diff into bullet points using Ollama code model.',
    inputSchema: zodToJsonSchema(ollamaExplainDiffInput),
    zodSchema: ollamaExplainDiffInput,
    handler: ollamaExplainDiff,
    risk: 'read',
  },
  {
    name: 'ollama_describe_code',
    description: 'Describe a code file: purpose, methods, dependencies, patterns. Uses Ollama code model.',
    inputSchema: zodToJsonSchema(ollamaDescribeCodeInput),
    zodSchema: ollamaDescribeCodeInput,
    handler: ollamaDescribeCode,
    risk: 'read',
  },
  {
    name: 'ollama_draft_commit',
    description: 'Draft a git commit message from a diff using Ollama.',
    inputSchema: zodToJsonSchema(ollamaDraftCommitInput),
    zodSchema: ollamaDraftCommitInput,
    handler: ollamaDraftCommit,
    risk: 'read',
  },
  {
    name: 'ollama_draft',
    description: 'Draft code, SQL, or content using Ollama. Routes to best model by type (codestral for code, sqlcoder for SQL).',
    inputSchema: zodToJsonSchema(ollamaDraftInput),
    zodSchema: ollamaDraftInput,
    handler: ollamaDraft,
    risk: 'read',
  },
];

const SERVER_INSTRUCTIONS = [
  'This is the PLOS MCP server. PLOS and Genea/genealogy are domains on this server, not separate MCP servers or resources.',
  'For an operator-configured family tree, call genealogy_search with the explicitly selected tree_id and entity_type person first, then genealogy_context for selected IDs.',
  'Do not call list_mcp_resources for genealogy and do not invent a genealogy MCP server.',
  'Private PLOS data may be processed by the explicitly selected local-only model. In offline mode, never use or suggest a cloud or backup LLM.',
  'Genealogy writes must use genealogy_batch_apply dry-run first; face and photo identity decisions remain operator-owned.',
].join(' ');

const GENEALOGY_TOOL_GUIDE_URI = 'plos://genealogy/tool-guide';
const GENEALOGY_TOOL_GUIDE = [
  'Genea and genealogy are provided by the PLOS MCP server; there is no separate genealogy MCP server.',
  'Use the genealogy_search tool for bounded discovery, then genealogy_context for selected result IDs.',
  'Use the operator-configured family tree. A surname lookup uses genealogy_search with the explicitly selected tree_id, entity_type "person", and query set to the surname.',
  'Private results may be processed by the selected local-only model. Offline mode must not use a cloud or backup LLM.',
  'Writes use genealogy_batch_apply with dry_run first. Face and photo identity decisions remain operator-owned.',
].join(' ');

async function main() {
  logger.info('PLOS MCP Server starting', { tools: TOOLS.length });
  const stdioKeepAlive = setInterval(() => {}, 60_000);

  // Start SSH tunnels for DB access
  try {
    await startTunnels();
  } catch (err) {
    logger.error('SSH tunnel failed — DB tools will error on use', { error: (err as Error).message });
    // Don't exit — Ollama tools still work without DB
  }

  const server = new Server(
    { name: 'plos', version: '1.0.0' },
    {
      capabilities: {
        resources: {},
        tools: {},
      },
      instructions: SERVER_INSTRUCTIONS,
    }
  );

  let shuttingDown = false;
  const shutdown = async (): Promise<void> => {
    if (shuttingDown) return;
    shuttingDown = true;
    clearInterval(stdioKeepAlive);
    await closeMysql();
    await closePg();
    stopTunnels();
    process.exit(0);
  };

  // List tools
  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: TOOLS.map(t => ({
      name: t.name,
      description: t.description,
      inputSchema: t.inputSchema,
      ...(t.outputSchema ? { outputSchema: t.outputSchema } : {}),
      annotations: toolAnnotations(t.risk, t.openWorld ?? false),
    })),
  }));

  // Static, non-sensitive compatibility guide for local models that try MCP
  // resource discovery before selecting a PLOS genealogy tool.
  server.setRequestHandler(ListResourcesRequestSchema, async () => ({
    resources: [
      {
        uri: GENEALOGY_TOOL_GUIDE_URI,
        name: 'PLOS genealogy tool guide',
        description: 'How to use the PLOS genealogy search/context tools in local-only offline sessions.',
        mimeType: 'text/plain',
      },
    ],
  }));

  server.setRequestHandler(ReadResourceRequestSchema, async (request) => {
    if (request.params.uri !== GENEALOGY_TOOL_GUIDE_URI) {
      throw new McpError(ErrorCode.InvalidParams, 'Unknown PLOS resource URI.');
    }

    return {
      contents: [
        {
          uri: GENEALOGY_TOOL_GUIDE_URI,
          mimeType: 'text/plain',
          text: GENEALOGY_TOOL_GUIDE,
        },
      ],
    };
  });

  // Handle tool calls — every handler wrapped with a global timeout safety net
  server.setRequestHandler(CallToolRequestSchema, async (request, extra) => {
    const { name, arguments: args } = request.params;
    const tool = TOOLS.find(t => t.name === name);

    if (!tool) {
      return {
        content: [{ type: 'text' as const, text: `Unknown tool: ${name}` }],
        isError: true,
      };
    }

    try {
      const parsed = tool.zodSchema.parse(args);
      const toolTimeoutMs = tool.timeoutMs ?? CONFIG.toolTimeoutMs;
      const result = await runToolWithLifecycle(
        signal => runToolWithProgress(
          () => tool.handler(parsed as any, { signal }),
          {
            progressToken: extra._meta?.progressToken,
            signal,
            heartbeatMs: CONFIG.toolProgressHeartbeatMs,
            sendNotification: extra.sendNotification,
          },
        ),
        {
          toolName: name,
          timeoutMs: toolTimeoutMs,
          clientSignal: extra.signal,
        },
      );

      logger.info('Tool call success', { tool: name });

      return buildToolSuccessResult(result, tool.outputSchema);
    } catch (err) {
      const msg = err instanceof ToolTimeoutError || err instanceof ToolCancelledError
        ? err.message
        : (err as Error).name === 'AbortError'
          ? `Tool "${name}" aborted`
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
    await shutdown();
  });

  process.on('SIGTERM', async () => {
    await shutdown();
  });

  process.stdin.once('end', () => {
    setTimeout(() => void shutdown(), 100);
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

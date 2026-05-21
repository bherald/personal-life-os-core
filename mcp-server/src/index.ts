#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  ListResourcesRequestSchema,
  ReadResourceRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import dotenv from 'dotenv';
import { DatabaseManager } from './integrations/database.js';
import { ArtisanExecutor } from './integrations/artisan.js';
import { ToolHandlers } from './handlers/tools.js';
import { AuditLogger } from './security/logger.js';
import { validateInput, schemas } from './security/validator.js';

// Load environment variables
dotenv.config();

const server = new Server(
  {
    name: 'plos-workflow-mcp',
    version: '1.0.0',
  },
  {
    capabilities: {
      tools: {},
      resources: {},
    },
  }
);

// Initialize components
const db = new DatabaseManager();
const artisan = new ArtisanExecutor();
const toolHandlers = new ToolHandlers(db, artisan);
const logger = new AuditLogger();

// List available tools
server.setRequestHandler(ListToolsRequestSchema, async () => {
  return {
    tools: [
      {
        name: 'workflow_list',
        description: 'List all workflows in the database',
        inputSchema: {
          type: 'object',
          properties: {
            active_only: {
              type: 'boolean',
              description: 'Only return active workflows',
            },
          },
        },
      },
      {
        name: 'workflow_get',
        description: 'Get details of a specific workflow by name',
        inputSchema: {
          type: 'object',
          properties: {
            name: { type: 'string', description: 'Workflow name' },
          },
          required: ['name'],
        },
      },
      {
        name: 'workflow_run',
        description: 'Execute a workflow by name',
        inputSchema: {
          type: 'object',
          properties: {
            name: { type: 'string', description: 'Workflow name' },
          },
          required: ['name'],
        },
      },
      {
        name: 'execution_list',
        description: 'List workflow execution history',
        inputSchema: {
          type: 'object',
          properties: {
            workflow_name: { type: 'string', description: 'Filter by workflow name' },
            limit: { type: 'number', description: 'Maximum results (1-500)', default: 50 },
          },
        },
      },
      {
        name: 'execution_get',
        description: 'Get details of a specific execution run',
        inputSchema: {
          type: 'object',
          properties: {
            run_id: { type: 'number', description: 'Run ID' },
          },
          required: ['run_id'],
        },
      },
      {
        name: 'artisan_execute',
        description: 'Execute a whitelisted Laravel artisan command',
        inputSchema: {
          type: 'object',
          properties: {
            command: { type: 'string', description: 'Artisan command' },
            args: { type: 'array', items: { type: 'string' }, description: 'Command arguments' },
          },
          required: ['command'],
        },
      },
      {
        name: 'node_create',
        description: 'Create a new workflow node',
        inputSchema: {
          type: 'object',
          properties: {
            name: { type: 'string', description: 'Node name (PascalCase)' },
          },
          required: ['name'],
        },
      },
      {
        name: 'schedule_list',
        description: 'List all scheduled tasks',
        inputSchema: {
          type: 'object',
          properties: {},
        },
      },
      {
        name: 'system_diagnostics',
        description: 'Get system diagnostics and statistics',
        inputSchema: {
          type: 'object',
          properties: {},
        },
      },
      {
        name: 'genealogy_context',
        description: 'Return compact FT genealogy context for selected person, family, media, source, and citation IDs',
        inputSchema: {
          type: 'object',
          properties: {
            tree_id: { type: 'number', description: 'Genealogy tree ID' },
            person_ids: { type: 'array', items: { type: 'number' }, description: 'Person IDs to include' },
            family_ids: { type: 'array', items: { type: 'number' }, description: 'Family IDs to include' },
            media_ids: { type: 'array', items: { type: 'number' }, description: 'Media IDs to include' },
            source_ids: { type: 'array', items: { type: 'number' }, description: 'Source IDs to include' },
            text_limit: { type: 'number', description: 'Max text chars per long field, 0-5000', default: 1200 },
          },
          required: ['tree_id'],
        },
      },
      {
        name: 'genealogy_person_get',
        description: 'Return one tree-scoped person snapshot with spouse/parent families, children, media, sources, and citations',
        inputSchema: {
          type: 'object',
          properties: {
            tree_id: { type: 'number', description: 'Genealogy tree ID' },
            person_id: { type: 'number', description: 'Person ID' },
            person_key: { type: 'string', description: 'Person GEDCOM ID, for example I123' },
            text_limit: { type: 'number', description: 'Max text chars per long field, 0-5000', default: 1200 },
          },
          required: ['tree_id'],
        },
      },
      {
        name: 'genealogy_family_get',
        description: 'Return one tree-scoped family snapshot with spouses, children, media, sources, and citations',
        inputSchema: {
          type: 'object',
          properties: {
            tree_id: { type: 'number', description: 'Genealogy tree ID' },
            family_id: { type: 'number', description: 'Family ID' },
            family_key: { type: 'string', description: 'Family GEDCOM ID, for example F123' },
            text_limit: { type: 'number', description: 'Max text chars per long field, 0-5000', default: 1200 },
          },
          required: ['tree_id'],
        },
      },
      {
        name: 'genealogy_source_get',
        description: 'Return one tree-scoped source snapshot with linked people, families, and citations',
        inputSchema: {
          type: 'object',
          properties: {
            tree_id: { type: 'number', description: 'Genealogy tree ID' },
            source_id: { type: 'number', description: 'Source ID' },
            source_key: { type: 'string', description: 'Source GEDCOM ID, for example S123' },
            text_limit: { type: 'number', description: 'Max text chars per long field, 0-5000', default: 1200 },
          },
          required: ['tree_id'],
        },
      },
      {
        name: 'genealogy_search',
        description: 'Search tree-scoped genealogy people, families, sources, or media with capped compact results',
        inputSchema: {
          type: 'object',
          properties: {
            tree_id: { type: 'number', description: 'Genealogy tree ID' },
            kind: { type: 'string', enum: ['person', 'family', 'source', 'media'], description: 'Entity type to search' },
            query: { type: 'string', description: 'Search text' },
            limit: { type: 'number', description: 'Maximum results, 1-100', default: 25 },
            text_limit: { type: 'number', description: 'Max text chars per long field, 0-5000', default: 1200 },
          },
          required: ['tree_id', 'kind', 'query'],
        },
      },
      {
        name: 'genealogy_tree_stats',
        description: 'Return compact counts and RAG/media readiness signals for a genealogy tree',
        inputSchema: {
          type: 'object',
          properties: {
            tree_id: { type: 'number', description: 'Genealogy tree ID' },
          },
          required: ['tree_id'],
        },
      },
      {
        name: 'genealogy_batch_apply',
        description: 'Apply guarded genealogy batch changes in one transaction; defaults to dry_run, requires confirm=true for writes, and supports compact keys for new records',
        inputSchema: {
          type: 'object',
          properties: {
            tree_id: { type: 'number' },
            dry_run: { type: 'boolean', default: true },
            confirm: { type: 'boolean', default: false },
            reason: { type: 'string', description: 'Short audit reason for the batch' },
            sources: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  id: { type: 'number' },
                  key: { type: 'string' },
                  title: { type: 'string' },
                  author: { type: 'string' },
                  publication: { type: 'string' },
                  repository: { type: 'string' },
                  url: { type: 'string' },
                  notes: { type: 'string' },
                  source_quality: { type: 'string', enum: ['original', 'derivative', 'authored'] },
                  source_category: { type: 'string', enum: ['original', 'derivative', 'authored'] },
                  information_quality: { type: 'string', enum: ['primary', 'secondary', 'undetermined'] },
                },
              },
            },
            media_updates: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  id: { type: 'number' },
                  media_type: { type: 'string' },
                  analysis_status: { type: 'string' },
                  enrichment_status: { type: 'string' },
                  enrichment_error: { type: ['string', 'null'] },
                  ai_description: { type: 'string' },
                  transcription_text: { type: 'string' },
                  transcription_source: { type: 'string', enum: ['manual', 'ocr', 'ai'] },
                },
                required: ['id'],
              },
            },
            persons: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  id: { type: 'number' },
                  key: { type: 'string' },
                  given_name: { type: 'string' },
                  surname: { type: 'string' },
                  sex: { type: 'string', enum: ['M', 'F', 'U'] },
                  nickname: { type: 'string' },
                  birth_date: { type: 'string' },
                  birth_place: { type: 'string' },
                  death_date: { type: 'string' },
                  death_place: { type: 'string' },
                  notes_append: { type: 'string' },
                  living: { type: 'boolean' },
                },
              },
            },
            families: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  id: { type: 'number' },
                  key: { type: 'string' },
                  husband_id: { type: 'number' },
                  husband_key: { type: 'string' },
                  wife_id: { type: 'number' },
                  wife_key: { type: 'string' },
                  marriage_date: { type: 'string' },
                  marriage_place: { type: 'string' },
                  notes: { type: 'string' },
                },
              },
            },
            children: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  family_id: { type: 'number' },
                  family_key: { type: 'string' },
                  person_id: { type: 'number' },
                  person_key: { type: 'string' },
                  father_relationship: { type: 'string', enum: ['Natural', 'Adopted', 'Step', 'Foster', 'Unknown'] },
                  mother_relationship: { type: 'string', enum: ['Natural', 'Adopted', 'Step', 'Foster', 'Unknown'] },
                  birth_order: { type: ['number', 'null'] },
                },
              },
            },
            person_media: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  person_id: { type: 'number' },
                  person_key: { type: 'string' },
                  media_id: { type: 'number' },
                  notes: { type: 'string' },
                  face_confirmed: { type: 'boolean' },
                },
                required: ['media_id'],
              },
            },
            family_media: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  family_id: { type: 'number' },
                  family_key: { type: 'string' },
                  media_id: { type: 'number' },
                },
                required: ['media_id'],
              },
            },
            citations: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  source_id: { type: 'number' },
                  source_key: { type: 'string' },
                  person_id: { type: 'number' },
                  person_key: { type: 'string' },
                  family_id: { type: 'number' },
                  family_key: { type: 'string' },
                  media_id: { type: 'number' },
                  fact_type: { type: 'string' },
                  page: { type: 'string' },
                  quality: { type: 'number' },
                  evidence_type: { type: 'string', enum: ['direct', 'indirect', 'negative'] },
                  information_type: { type: 'string', enum: ['primary', 'secondary', 'indeterminate'] },
                  evidence_analysis: { type: 'string' },
                  text: { type: 'string' },
                },
                required: ['fact_type'],
              },
            },
            rag_touch: {
              type: 'object',
              properties: {
                person_ids: { type: 'array', items: { type: 'number' } },
                person_keys: { type: 'array', items: { type: 'string' } },
                source_ids: { type: 'array', items: { type: 'number' } },
                source_keys: { type: 'array', items: { type: 'string' } },
                media_ids: { type: 'array', items: { type: 'number' } },
              },
            },
          },
          required: ['tree_id', 'reason'],
        },
      },
    ],
  };
});

// Handle tool calls
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  logger.logToolUse(name, args || {});

  try {
    switch (name) {
      case 'workflow_list':
        return await toolHandlers.handleWorkflowList(
          validateInput(schemas.workflowList, args)
        );

      case 'workflow_get':
        return await toolHandlers.handleWorkflowGet(
          validateInput(schemas.workflowGet, args)
        );

      case 'workflow_run':
        return await toolHandlers.handleWorkflowRun(
          validateInput(schemas.workflowRun, args)
        );

      case 'execution_list':
        return await toolHandlers.handleExecutionList(
          validateInput(schemas.executionList, args)
        );

      case 'execution_get':
        return await toolHandlers.handleExecutionGet(
          validateInput(schemas.executionGet, args)
        );

      case 'artisan_execute':
        return await toolHandlers.handleArtisanExecute(
          validateInput(schemas.artisanExecute, args)
        );

      case 'node_create':
        return await toolHandlers.handleNodeCreate(
          validateInput(schemas.nodeCreate, args)
        );

      case 'schedule_list':
        return await toolHandlers.handleScheduleList();

      case 'system_diagnostics':
        return await toolHandlers.handleSystemDiagnostics();

      case 'genealogy_context':
        return await toolHandlers.handleGenealogyContext(
          validateInput(schemas.genealogyContext, args)
        );

      case 'genealogy_person_get':
        return await toolHandlers.handleGenealogyPersonGet(
          validateInput(schemas.genealogyPersonGet, args)
        );

      case 'genealogy_family_get':
        return await toolHandlers.handleGenealogyFamilyGet(
          validateInput(schemas.genealogyFamilyGet, args)
        );

      case 'genealogy_source_get':
        return await toolHandlers.handleGenealogySourceGet(
          validateInput(schemas.genealogySourceGet, args)
        );

      case 'genealogy_search':
        return await toolHandlers.handleGenealogySearch(
          validateInput(schemas.genealogySearch, args)
        );

      case 'genealogy_tree_stats':
        return await toolHandlers.handleGenealogyTreeStats(
          validateInput(schemas.genealogyTreeStats, args)
        );

      case 'genealogy_batch_apply':
        return await toolHandlers.handleGenealogyBatchApply(
          validateInput(schemas.genealogyBatch, args)
        );

      default:
        throw new Error(`Unknown tool: ${name}`);
    }
  } catch (error) {
    logger.logError(name, error as Error);
    throw error;
  }
});

// List resources
server.setRequestHandler(ListResourcesRequestSchema, async () => {
  return {
    resources: [
      {
        uri: 'docs://developer-guide',
        name: 'Developer Guide',
        description: 'Complete developer documentation',
        mimeType: 'text/markdown',
      },
      {
        uri: 'docs://implementation-plan',
        name: 'Implementation Plan',
        description: 'Project implementation roadmap',
        mimeType: 'text/markdown',
      },
    ],
  };
});

// Read resources
server.setRequestHandler(ReadResourceRequestSchema, async (request) => {
  const { uri } = request.params;

  const fs = await import('fs/promises');
  const path = await import('path');

  const projectRoot = process.env.PROJECT_ROOT || '';

  if (uri === 'docs://developer-guide') {
    const content = await fs.readFile(
      path.join(projectRoot, 'docs/DEVELOPER_GUIDE.md'),
      'utf-8'
    );
    return {
      contents: [{ uri, mimeType: 'text/markdown', text: content }],
    };
  }

  if (uri === 'docs://implementation-plan') {
    const content = await fs.readFile(
      path.join(projectRoot, 'docs/IMPLEMENTATION_PLAN.md'),
      'utf-8'
    );
    return {
      contents: [{ uri, mimeType: 'text/markdown', text: content }],
    };
  }

  throw new Error(`Unknown resource: ${uri}`);
});

// Start server
async function main() {
  // Test database connection
  const connected = await db.testConnection();
  if (!connected) {
    console.error('Failed to connect to database');
    process.exit(1);
  }

  const transport = new StdioServerTransport();
  await server.connect(transport);

  console.error('PLOS Workflow MCP Server running');
}

main().catch((error) => {
  console.error('Server error:', error);
  process.exit(1);
});

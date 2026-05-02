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

import { z } from 'zod';
import { DatabaseManager } from '../integrations/database.js';
import { ArtisanExecutor } from '../integrations/artisan.js';

export class ToolHandlers {
  constructor(
    private db: DatabaseManager,
    private artisan: ArtisanExecutor
  ) {}

  // Tool: workflow_list
  async handleWorkflowList(args: { active_only?: boolean }) {
    const workflows = await this.db.getWorkflows(args.active_only || false);
    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(workflows, null, 2),
        },
      ],
    };
  }

  // Tool: workflow_get
  async handleWorkflowGet(args: { name: string }) {
    const workflow = await this.db.getWorkflowByName(args.name);
    if (!workflow) {
      throw new Error(`Workflow not found: ${args.name}`);
    }

    const nodes = await this.db.getWorkflowNodes(workflow.id);

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify({ workflow, nodes }, null, 2),
        },
      ],
    };
  }

  // Tool: workflow_run
  async handleWorkflowRun(args: { name: string }) {
    const result = await this.artisan.executeCommand('workflow:run', [args.name]);

    if (result.exitCode !== 0) {
      throw new Error(`Workflow execution failed: ${result.stderr}`);
    }

    return {
      content: [
        {
          type: 'text',
          text: `Workflow "${args.name}" executed successfully.\n\n${result.stdout}`,
        },
      ],
    };
  }

  // Tool: execution_list
  async handleExecutionList(args: { workflow_name?: string; limit?: number }) {
    let workflowId: number | undefined;

    if (args.workflow_name) {
      const workflow = await this.db.getWorkflowByName(args.workflow_name);
      if (!workflow) {
        throw new Error(`Workflow not found: ${args.workflow_name}`);
      }
      workflowId = workflow.id;
    }

    const runs = await this.db.getWorkflowRuns(workflowId, args.limit || 50);

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(runs, null, 2),
        },
      ],
    };
  }

  // Tool: execution_get
  async handleExecutionGet(args: { run_id: number }) {
    const details = await this.db.getRunDetails(args.run_id);

    if (!details) {
      throw new Error(`Execution not found: ${args.run_id}`);
    }

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(details, null, 2),
        },
      ],
    };
  }

  // Tool: artisan_execute
  async handleArtisanExecute(args: { command: string; args?: string[] }) {
    const result = await this.artisan.executeCommand(
      args.command,
      args.args || []
    );

    return {
      content: [
        {
          type: 'text',
          text: `Command: php artisan ${args.command}\n\nOutput:\n${result.stdout}\n\n${
            result.stderr ? `Errors:\n${result.stderr}` : ''
          }`,
        },
      ],
    };
  }

  // Tool: system_diagnostics
  async handleSystemDiagnostics() {
    const stats = await this.db.getExecutionStats();
    const activeWorkflows = await this.db.getActiveWorkflowCount();

    const diagnostics = {
      database_status: 'connected',
      active_workflows: activeWorkflows,
      execution_stats: stats,
      project_root: process.env.PROJECT_ROOT,
    };

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(diagnostics, null, 2),
        },
      ],
    };
  }

  // Tool: node_create
  async handleNodeCreate(args: { name: string }) {
    const result = await this.artisan.executeCommand('make:node', [args.name]);

    if (result.exitCode !== 0) {
      throw new Error(`Node creation failed: ${result.stderr}`);
    }

    return {
      content: [
        {
          type: 'text',
          text: `Node "${args.name}" created successfully.\n\n${result.stdout}`,
        },
      ],
    };
  }

  // Tool: schedule_list
  async handleScheduleList() {
    const result = await this.artisan.executeCommand('schedule:list');

    return {
      content: [
        {
          type: 'text',
          text: result.stdout,
        },
      ],
    };
  }
}

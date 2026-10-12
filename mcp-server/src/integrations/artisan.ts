import { spawn } from 'child_process';
import { ArtisanCommand } from '../types.js';

export class ArtisanExecutor {
  private readonly projectRoot: string;

  // Whitelisted commands
  private readonly allowedCommands: ArtisanCommand[] = [
    { command: 'workflow:list', description: 'List all workflows', category: 'Workflow' },
    { command: 'workflow:run', description: 'Execute a workflow', category: 'Workflow' },
    { command: 'workflow:create', description: 'Create a new workflow', category: 'Workflow' },
    { command: 'workflow:export', description: 'Export workflow to JSON', category: 'Workflow' },
    { command: 'workflow:import', description: 'Import workflow from JSON', category: 'Workflow' },
    { command: 'make:node', description: 'Create a new node', category: 'Development' },
    { command: 'schedule:list', description: 'List scheduled tasks', category: 'System' },
    { command: 'db:show', description: 'Show database information', category: 'Database' },
    { command: 'route:list', description: 'List all routes', category: 'System' },
    { command: 'codex:offline-capabilities', description: 'Report local/offline Codex, PLOS automation, and sidecar capabilities', category: 'Offline AI' },
    { command: 'codex:offline-pipeline-tools', description: 'List PLOS AI pipeline functions, tools, and options in execution order', category: 'Offline AI' },
    { command: 'os-tools:discover', description: 'Discover current-environment executable names and packages without running discovered programs', category: 'Local Tools' },
    { command: 'os-tools:review', description: 'Read local documentation and prepare a tool approval candidate without enabling it', category: 'Local Tools' },
    { command: 'os-tools:inventory', description: 'Observe known local tool categories and dependencies without executing scanners', category: 'Local Tools' },
    { command: 'codex:offline-sidecar', description: 'Plan or run a read-only multi-model offline Codex sidecar split', category: 'Offline AI' },
    { command: 'review:lookup', description: 'Read one Review Desk item by exact reference or known alias', category: 'Review' },
  ];

  // Blacklisted commands (never allowed)
  private readonly blacklistedCommands = [
    'migrate',
    'migrate:fresh',
    'migrate:reset',
    'migrate:rollback',
    'db:wipe',
    'queue:clear',
    'cache:clear',
    'config:clear',
    'key:generate',
    'passport:install',
  ];

  constructor() {
    this.projectRoot = process.env.PROJECT_ROOT || process.cwd();
  }

  getAvailableCommands(): ArtisanCommand[] {
    return this.allowedCommands;
  }

  isCommandAllowed(command: string): boolean {
    // Check blacklist first
    if (this.blacklistedCommands.some(cmd => command.startsWith(cmd))) {
      return false;
    }

    // Check whitelist
    // OS discovery commands accept separate typed arguments, never prefix variants.
    if (command.startsWith('os-tools:') || command.startsWith('review:')) {
      return this.allowedCommands.some(cmd => command === cmd.command);
    }
    return this.allowedCommands.some(cmd => command.startsWith(cmd.command));
  }

  async executeCommand(
    command: string,
    args: string[] = []
  ): Promise<{ stdout: string; stderr: string; exitCode: number }> {
    if (!this.isCommandAllowed(command)) {
      throw new Error(`Command not allowed: ${command}`);
    }

    return new Promise((resolve, reject) => {
      const artisan = spawn('php', ['artisan', command, ...args], {
        cwd: this.projectRoot,
        env: process.env,
      });

      let stdout = '';
      let stderr = '';

      artisan.stdout.on('data', (data) => {
        stdout += data.toString();
      });

      artisan.stderr.on('data', (data) => {
        stderr += data.toString();
      });

      artisan.on('close', (code) => {
        resolve({
          stdout,
          stderr,
          exitCode: code || 0,
        });
      });

      artisan.on('error', (error) => {
        reject(error);
      });
    });
  }
}

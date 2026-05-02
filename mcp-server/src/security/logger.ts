import fs from 'fs';
import path from 'path';

export class AuditLogger {
  private logFile: string;

  constructor() {
    this.logFile = process.env.LOG_FILE ||
      path.join(process.env.PROJECT_ROOT || '', 'storage/logs/mcp.log');
  }

  log(action: string, details: Record<string, any>): void {
    const timestamp = new Date().toISOString();
    const entry = {
      timestamp,
      action,
      ...details,
    };

    const logLine = JSON.stringify(entry) + '\n';

    try {
      // Ensure directory exists
      const dir = path.dirname(this.logFile);
      if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
      }

      fs.appendFileSync(this.logFile, logLine);
    } catch (error) {
      console.error('Failed to write audit log:', error);
    }
  }

  logToolUse(toolName: string, args: Record<string, any>): void {
    this.log('tool_use', { tool: toolName, args });
  }

  logError(action: string, error: Error): void {
    this.log('error', { action, error: error.message, stack: error.stack });
  }
}

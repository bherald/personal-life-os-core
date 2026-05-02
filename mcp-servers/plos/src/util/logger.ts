import { appendFileSync } from 'fs';

const LOG_FILE = process.env.LOG_FILE || '/tmp/plos-mcp.log';

export function log(level: string, message: string, data?: Record<string, unknown>): void {
  const ts = new Date().toISOString();
  const line = `[${ts}] [${level}] ${message}${data ? ' ' + JSON.stringify(data) : ''}\n`;
  try {
    appendFileSync(LOG_FILE, line);
  } catch {
    // MCP uses stdio — can't write to stdout/stderr for logging
  }
}

export const logger = {
  info: (msg: string, data?: Record<string, unknown>) => log('INFO', msg, data),
  warn: (msg: string, data?: Record<string, unknown>) => log('WARN', msg, data),
  error: (msg: string, data?: Record<string, unknown>) => log('ERROR', msg, data),
  debug: (msg: string, data?: Record<string, unknown>) => log('DEBUG', msg, data),
};

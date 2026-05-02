import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosLogSearchInput = z.object({
  pattern: z.string().describe('Search pattern (grep regex)'),
  level: z.enum(['ERROR', 'WARNING', 'INFO', 'DEBUG', 'all']).optional().default('all')
    .describe('Log level filter'),
  lines: z.coerce.number().optional().default(100).describe('Max lines to return'),
  tail: z.coerce.number().optional().default(5000).describe('Read last N lines of log before searching'),
});

export type PlosLogSearchInput = z.infer<typeof plosLogSearchInput>;

export async function plosLogSearch(input: PlosLogSearchInput, context?: ToolContext): Promise<string> {
  const { pattern, level, lines, tail } = input;

  // Sanitize pattern for shell safety
  const safePattern = pattern.replace(/['"\\$`!]/g, '\\$&');
  const levelFilter = level !== 'all' ? ` | grep '${level}'` : '';

  const logPath = shellQuote(`${CONFIG.paths.remoteRoot}/storage/logs/laravel.log`);
  const cmd = `${sshCmd()} "tail -n ${tail} ${logPath} | grep -i '${safePattern}'${levelFilter} | tail -n ${lines}"`;

  try {
    const { stdout, stderr } = await execCommand(cmd, { timeout: 15_000, signal: context?.signal });
    if (!stdout.trim()) return `No log entries matching "${pattern}"${level !== 'all' ? ` at ${level} level` : ''}.`;

    const resultLines = stdout.trim().split('\n');
    return `${resultLines.length} matches (searched last ${tail} lines):\n\n${stdout.trim()}`;
  } catch (err: unknown) {
    const error = err as { code?: number; stderr?: string; message?: string };
    // grep returns exit code 1 for no matches
    if (error.code === 1) return `No log entries matching "${pattern}".`;
    return `Log search error: ${error.stderr || error.message}`;
  }
}

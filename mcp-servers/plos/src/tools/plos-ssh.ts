import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

// Blocked commands — destructive or dangerous
const BLOCKED_PATTERNS = [
  /\brm\s+-rf\s+\/(?!\S)/,          // rm -rf /
  /\bdd\s+if=/,                      // dd if=
  /\bmkfs\b/,                        // mkfs
  /\bchmod\s+777\b/,                 // chmod 777
  /\bsudo\s/,                        // sudo anything
  />\s*\/etc\//,                     // write to /etc
  />\s*~?\/?\.ssh\//,               // write to .ssh
  />\s*\.env\b/,                     // overwrite .env
  /\breboot\b/,                      // reboot
  /\bshutdown\b/,                    // shutdown
  /\bsystemctl\s+(stop|disable)\b/,  // stop/disable services
  /\|.*\bsh\b/,                      // pipe to sh
  /\|.*\bbash\b/,                    // pipe to bash
  /\bcurl\b.*\|\s*(sh|bash)\b/,     // curl | sh
];

// Max output to prevent flooding
const MAX_OUTPUT_BYTES = 100_000; // 100KB

export function buildSshCommand(command: string, cwd: string, sshPrefix = sshCmd()): string {
  const remoteCommand = `cd -- ${shellQuote(cwd)} && ${command}`;

  return `${sshPrefix} ${shellQuote(remoteCommand)}`;
}

export const plosSshInput = z.object({
  command: z.string().describe(
    'Shell command to execute on the configured remote PLOS instance. Examples: "npm run build", "ls -la public/build/", "nvidia-smi", "df -h", "systemctl status nginx"'
  ),
  cwd: z.string().optional()
    .describe('Optional working directory; the configured remote project root is used when omitted'),
  timeout: z.coerce.number().optional().default(60)
    .describe('Timeout in seconds (max 120, default 60)'),
});

export type PlosSshInput = z.infer<typeof plosSshInput>;

export async function plosSsh(input: PlosSshInput, context?: ToolContext): Promise<string> {
  const { command, cwd, timeout: timeoutSec } = input;
  const timeoutMs = Math.min(timeoutSec, 120) * 1000;
  const normalizedCwd = (cwd ?? CONFIG.paths.remoteRoot).trim();

  if (!normalizedCwd.startsWith('/') || /[\r\n\0]/.test(normalizedCwd)) {
    return `[BLOCKED] Invalid cwd: ${cwd}`;
  }

  // These destroy live cache/queue custody even without sudo. They have no
  // SSH override; use plos_cache_clear for scoped release invalidation.
  if (/\bphp(?:\d+(?:\.\d+)*)?\b[^;&|\r\n]*\bartisan\b[^;&|\r\n]*\b(?:cache:clear|optimize:clear)\b/i.test(command)
    || /\bredis-cli(?:\s+(?:-[hpsanu]|--(?:host|port|user|pass|uri|cacert|cert|key|sni|socket|pattern|count))\s+(?:"[^"]*"|'[^']*'|[^\s;&|]+)|\s+--cluster\s+call\s+[^\s;&|]+|\s+-[^\s;&|]+)*\s+["']?flush(?:db|all)["']?(?=\s|[;&|]|$)/i.test(command)) {
    return '[HELD] global_cache_flush_not_permitted; use plos_cache_clear for scoped invalidation';
  }

  // Safety: block dangerous commands
  for (const pattern of BLOCKED_PATTERNS) {
    if (pattern.test(command)) {
      return `[BLOCKED] Command matches dangerous pattern: ${pattern}\nCommand: ${command}`;
    }
  }

  // Build SSH command with cd to working directory
  const shellCmd = buildSshCommand(command, normalizedCwd);

  logger.info('SSH command', { command, cwd: normalizedCwd, timeoutMs });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: timeoutMs,
      maxBuffer: MAX_OUTPUT_BYTES,
      signal: context?.signal,
    });

    let output = stdout.trim();
    const errOutput = stderr.trim();

    // Truncate if needed
    if (output.length > MAX_OUTPUT_BYTES) {
      output = output.slice(0, MAX_OUTPUT_BYTES) + '\n\n... [truncated at 100KB]';
    }

    const parts = [`[REMOTE] $ ${command}`];
    if (normalizedCwd !== CONFIG.paths.remoteRoot) parts.push(`  cwd: ${normalizedCwd}`);
    parts.push('');
    if (output) parts.push(output);
    if (errOutput) parts.push(`\n[stderr]\n${errOutput}`);

    return parts.join('\n');
  } catch (err: unknown) {
    const error = err as { stdout?: string; stderr?: string; message?: string; code?: number | string; killed?: boolean };

    if (error.killed) {
      return `[TIMEOUT] Command killed after ${timeoutSec}s\nCommand: ${command}`;
    }

    const output = error.stdout?.trim() || '';
    const errMsg = error.stderr?.trim() || error.message || 'Unknown error';
    return `[ERROR] $ ${command}\nExit code: ${error.code}\n\n${output}\n\n${errMsg}`;
  }
}

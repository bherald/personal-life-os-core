import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosCacheClearInput = z.object({
  restart_workers: z.coerce.boolean().optional().default(false)
    .describe('Also terminate Horizon and restart queue workers'),
  flush_redis: z.coerce.boolean().optional().default(false)
    .describe('Also flush the Redis database'),
  confirm_disruptive: z.coerce.boolean().optional().default(false)
    .describe('Required when flush_redis or restart_workers is true'),
});

export type PlosCacheClearInput = z.infer<typeof plosCacheClearInput>;

export async function plosCacheClear(input: PlosCacheClearInput, context?: ToolContext): Promise<string> {
  const { restart_workers, flush_redis, confirm_disruptive } = input;
  const remotePath = CONFIG.paths.remoteRoot;
  const sshPrefix = sshCmd();

  if ((restart_workers || flush_redis) && !confirm_disruptive) {
    return 'BLOCKED: confirm_disruptive=true is required when restart_workers or flush_redis is requested';
  }

  const steps: Array<{ label: string; cmd: string }> = [
    { label: 'cache:clear', cmd: 'php artisan cache:clear' },
    { label: 'config:clear', cmd: 'php artisan config:clear' },
    { label: 'route:clear', cmd: 'php artisan route:clear' },
    { label: 'view:clear', cmd: 'php artisan view:clear' },
    { label: 'event:clear', cmd: 'php artisan event:clear' },
  ];

  if (flush_redis) {
    steps.push({ label: 'redis FLUSHDB', cmd: 'redis-cli FLUSHDB' });
  }

  if (restart_workers) {
    steps.push(
      { label: 'horizon:terminate', cmd: 'php artisan horizon:terminate' },
      { label: 'queue:restart', cmd: 'php artisan queue:restart' },
    );
  }

  // Build a single SSH command that chains all steps
  const chainedCmds = steps.map(s => s.cmd).join(' && ');
  const fullCmd = `${sshPrefix} "cd -- ${shellQuote(remotePath)} && ${chainedCmds}"`;

  logger.info('Cache clear', { restart_workers, flush_redis, steps: steps.length });

  const results: string[] = [];
  try {
    const { stdout, stderr } = await execCommand(fullCmd, { timeout: 30_000, signal: context?.signal });

    for (const step of steps) {
      results.push(`  [OK] ${step.label}`);
    }

    if (stdout.trim()) {
      results.push(`\nOutput:\n${stdout.trim()}`);
    }
    if (stderr?.trim()) {
      results.push(`\n[stderr] ${stderr.trim()}`);
    }

    return `[REMOTE] Cache clear complete (${steps.length} steps)\n\n${results.join('\n')}`;
  } catch (err: unknown) {
    const error = err as { stdout?: string; stderr?: string; message?: string; code?: number };
    return `[ERROR] Cache clear failed at step\nExit code: ${error.code}\n\n${error.stdout?.trim() || ''}\n${error.stderr?.trim() || error.message}`;
  }
}

import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosCacheClearInput = z.object({
  restart_workers: z.coerce.boolean().optional().default(false)
    .describe('Also terminate Horizon and restart queue workers'),
  flush_redis: z.coerce.boolean().optional().default(false)
    .describe('Legacy option: always held; global Redis flushing is not permitted'),
  confirm_disruptive: z.coerce.boolean().optional().default(false)
    .describe('Required when restart_workers is true; does not authorize Redis flushing'),
});

export type PlosCacheClearInput = z.infer<typeof plosCacheClearInput>;

export async function plosCacheClear(input: PlosCacheClearInput, context?: ToolContext): Promise<string> {
  const { restart_workers, flush_redis, confirm_disruptive } = input;
  const remotePath = CONFIG.paths.remoteRoot;
  const sshPrefix = sshCmd();

  if (flush_redis) {
    return 'HELD: global_redis_flush_not_permitted';
  }
  if (restart_workers && !confirm_disruptive) {
    return 'BLOCKED: confirm_disruptive=true is required when restart_workers is requested';
  }

  const steps: Array<{ label: string; cmd: string }> = [
    { label: 'ops:release-cache-invalidate', cmd: 'php artisan ops:release-cache-invalidate --json' },
    { label: 'config:clear', cmd: 'php artisan config:clear' },
    { label: 'route:clear', cmd: 'php artisan route:clear' },
    { label: 'view:clear', cmd: 'php artisan view:clear' },
    { label: 'event:clear', cmd: 'php artisan event:clear' },
  ];

  if (restart_workers) {
    steps.push(
      { label: 'horizon:terminate', cmd: 'php artisan horizon:terminate' },
      { label: 'queue:restart', cmd: 'php artisan queue:restart' },
    );
  }

  // Build a single SSH command that chains all steps
  const chainedCmds = steps.map(s => s.cmd).join(' && ');
  const fullCmd = `${sshPrefix} "cd -- ${shellQuote(remotePath)} && ${chainedCmds}"`;

  logger.info('Scoped cache invalidation', { restart_workers, flush_redis, steps: steps.length });

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

    return `[REMOTE] Scoped cache invalidation complete (${steps.length} steps)\n\n${results.join('\n')}`;
  } catch (err: unknown) {
    const error = err as { stdout?: string; stderr?: string; message?: string; code?: number };
    return `[ERROR] Scoped cache invalidation failed; remaining steps held\nExit code: ${error.code}\n\n${error.stdout?.trim() || ''}\n${error.stderr?.trim() || error.message}`;
  }
}

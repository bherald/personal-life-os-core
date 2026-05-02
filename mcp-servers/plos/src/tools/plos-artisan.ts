import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

// Whitelisted artisan commands — read-only diagnostics + safe ops
export const ALLOWED_COMMANDS: Record<string, { description: string; timeout: number }> = {
  'ops:validate-sql':            { description: 'Static SQL validation (pre-deploy)', timeout: 60_000 },
  'ops:validate-sql --explain':  { description: 'EXPLAIN SQL validation (remote/post-deploy)', timeout: 120_000 },
  'ops:smoke-test':              { description: 'Post-deploy smoke test', timeout: 60_000 },
  'ops:smoke-test --quick':      { description: 'Quick smoke test', timeout: 30_000 },
  'ops:health-gate':             { description: 'All validation gates (deploy blocker)', timeout: 60_000 },
  'ops:health-gate --quick':     { description: 'Quick health gate', timeout: 30_000 },
  'ops:daily-report --dry-run':  { description: 'Morning digest preview (no send)', timeout: 60_000 },
  'ops:operator-evidence --json': { description: 'Operator evidence dashboard payload', timeout: 30_000 },
  'ops:offline-status --json':   { description: 'Offline/degraded runtime status', timeout: 15_000 },
  'ops:offline-smoke --json':    { description: 'Manual read-only offline smoke report', timeout: 30_000 },
  'ops:agent-doctor --json --since=24': { description: 'Observe-only agent health diagnostics', timeout: 30_000 },
  'ops:agent-doctor-snapshot --dry-run --json': { description: 'Dry-run aggregate Agent Doctor readiness snapshot', timeout: 30_000 },
  'ops:agent-doctor-history --json --days=7': { description: 'Read-only Agent Doctor readiness snapshot history', timeout: 30_000 },
  'ops:capacity-report --json':   { description: 'Observe-only capacity evidence report', timeout: 30_000 },
  'ops:runtime-diagnostics --window=60m --focus=all --json': { description: 'Read-only runtime recovery diagnostics', timeout: 30_000 },
  'ops:face-telemetry-report --json': { description: 'Face/genealogy telemetry report', timeout: 30_000 },
  'ops:face-telemetry-report --markdown --hours=168': { description: 'Weekly face/genealogy telemetry markdown', timeout: 30_000 },
  'ops:dba-telemetry-report --json': { description: 'Observe-only DBA telemetry report', timeout: 60_000 },
  'ops:audit-privacy-routing --json': { description: 'Sensitive-provider privacy routing audit', timeout: 30_000 },
  'ops:sync-schema-reference':   { description: 'Regenerate schema-reference.md from live DB', timeout: 30_000 },
  'ops:sync-schema-reference --diff': { description: 'Show schema drift without writing', timeout: 30_000 },
  'files:registry --status':     { description: 'File registry status', timeout: 15_000 },
  'files:thumbnails --stats':    { description: 'Thumbnail pipeline stats', timeout: 15_000 },
  'genealogy:reject-codes --json': { description: 'Genealogy reviewer reject-code rollup', timeout: 30_000 },
  'genealogy:reject-codes --json --days=30': { description: 'Genealogy reviewer reject-code 30-day rollup', timeout: 30_000 },
  'genealogy:review-feedback --days=30 --json': { description: 'Genealogy reviewer feedback rollup', timeout: 30_000 },
  'genealogy:packet-reason-codes --days=30 --json': { description: 'Genealogy review-packet reason-code rollup', timeout: 30_000 },
  'genealogy:agent-triage --json': { description: 'Read-only genealogy agent re-enablement triage', timeout: 30_000 },
  'awo:replay --window=7d --json': { description: 'Approval-worthy-output read-only replay', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --json': { description: 'Approval-worthy-output read-only replay with weekly evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --markdown': { description: 'Weekly approval-worthy-output read-only markdown report', timeout: 30_000 },
  'awo:replay --compare-scheduled --window=7d --limit=500 --json': { description: 'Read-only AWO scheduled report comparison', timeout: 30_000 },
  'scheduler:optimize-report --json': { description: 'Observe-only scheduler optimization recommendations', timeout: 30_000 },
  'news:bias-tags-audit --json': { description: 'Read-only news-bias tag audit', timeout: 30_000 },
  'news:source-inventory --workflow=news_brief --days=7 --strict --json': { description: 'Read-only news source inventory and bias coverage', timeout: 30_000 },
  'bias:aliases --unmatched --limit=50 --json': { description: 'Read-only unmatched news-bias source aliases', timeout: 30_000 },
  'rag:raptor-build --stats':    { description: 'RAPTOR pipeline stats', timeout: 15_000 },
  'rag:build-sentences --stats': { description: 'Sentence embedding stats', timeout: 15_000 },
  'rag:build-knowledge-graph --stats': { description: 'Knowledge graph stats', timeout: 15_000 },
  'rag:backlog-report --json':   { description: 'RAG/RAPTOR/sentence/KG backlog evidence', timeout: 30_000 },
  'graph:audit-provenance --json': { description: 'Knowledge graph provenance audit', timeout: 60_000 },
  'graph:detect-communities --stats':  { description: 'Community detection stats', timeout: 15_000 },
  'graph:quality-metrics --stats':     { description: 'Graph quality metrics', timeout: 15_000 },
  'entity:resolve --stats':     { description: 'Entity resolution stats', timeout: 15_000 },
  'workflow:list':               { description: 'List all workflows', timeout: 15_000 },
  'agent:procedures --stats':   { description: 'Procedural memory stats', timeout: 15_000 },
  'episodic:memory --stats':    { description: 'Episodic memory stats', timeout: 15_000 },
  'skill:versions --stats':     { description: 'Skill version stats', timeout: 15_000 },
  'horizon:status':             { description: 'Queue worker status', timeout: 10_000 },
  'schedule:list':              { description: 'List scheduled commands', timeout: 15_000 },
};

export const plosArtisanInput = z.object({
  command: z.string().describe(
    'Artisan command to run. Use "list" to see allowed commands. Examples: "ops:validate-sql", "files:registry --status"'
  ),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for dev-only commands like ops:validate-sql.'),
});

export type PlosArtisanInput = z.infer<typeof plosArtisanInput>;

export async function plosArtisan(input: PlosArtisanInput, context?: ToolContext): Promise<string> {
  const { command, on_prod } = input;

  // Special: list available commands
  if (command === 'list') {
    const lines = ['Available artisan commands:\n'];
    for (const [cmd, meta] of Object.entries(ALLOWED_COMMANDS)) {
      lines.push(`  php artisan ${cmd}`);
      lines.push(`    ${meta.description}\n`);
    }
    return lines.join('\n');
  }

  // Validate against whitelist
  const match = Object.entries(ALLOWED_COMMANDS).find(([allowed]) => command === allowed);
  if (!match) {
    const suggestions = Object.keys(ALLOWED_COMMANDS)
      .filter(k => k.includes(command.split(' ')[0].split(':')[0]))
      .slice(0, 5);
    return `Blocked: "${command}" is not in the allowed command list.\n`
      + (suggestions.length ? `Did you mean: ${suggestions.join(', ')}?\n` : '')
      + `Use command "list" to see all available commands.`;
  }

  const [allowedCmd, meta] = match;
  const remotePath = CONFIG.paths.remoteRoot;

  let shellCmd: string;
  if (on_prod) {
    shellCmd = `${sshCmd()} "cd -- ${shellQuote(remotePath)} && php artisan ${allowedCmd}"`;
  } else {
    shellCmd = `cd -- ${shellQuote(CONFIG.paths.projectRoot)} && php artisan ${allowedCmd}`;
  }

  logger.info('Artisan command', { command: allowedCmd, on_prod });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, { timeout: meta.timeout, signal: context?.signal });
    const output = (stdout + (stderr ? `\n[stderr] ${stderr}` : '')).trim();
    const env = on_prod ? 'REMOTE' : 'DEV';
    return `[${env}] php artisan ${allowedCmd}\n\n${output}`;
  } catch (err: unknown) {
    const error = err as { stdout?: string; stderr?: string; message?: string; code?: number };
    const output = error.stdout?.trim() || '';
    const errMsg = error.stderr?.trim() || error.message || 'Unknown error';
    return `[ERROR] php artisan ${allowedCmd}\nExit code: ${error.code}\n\n${output}\n\n${errMsg}`;
  }
}

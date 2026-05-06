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
  'ops:operator-evidence --compact': { description: 'Compact operator evidence dashboard summary', timeout: 30_000 },
  'ops:operator-evidence --json --compact': { description: 'Compact operator evidence dashboard payload', timeout: 30_000 },
  'ops:review-backlog-report --json': { description: 'Read-only review backlog triage report', timeout: 30_000 },
  'ops:review-backlog-report --markdown': { description: 'Read-only review backlog triage markdown', timeout: 30_000 },
  'ops:review-backlog-report --dry-run': { description: 'Read-only review backlog dry-run report', timeout: 30_000 },
  'ops:review-backlog-report --compact': { description: 'Compact read-only review backlog triage report', timeout: 30_000 },
  'ops:review-backlog-report --json --compact': { description: 'Compact read-only review backlog triage JSON', timeout: 30_000 },
  'ops:review-backlog-report --markdown --compact': { description: 'Compact read-only review backlog triage markdown', timeout: 30_000 },
  'ops:review-backlog-report --next-target': { description: 'Read-only sanitized next review target', timeout: 30_000 },
  'ops:review-backlog-report --json --next-target': { description: 'Read-only sanitized next review target JSON', timeout: 30_000 },
  'ops:review-backlog-report --next-target --focus=typed-remediation --json': { description: 'Read-only sanitized typed-remediation review target JSON', timeout: 30_000 },
  'ops:review-backlog-report --next-target --focus=materializable-remediation --json': { description: 'Read-only sanitized materializable remediation review target JSON', timeout: 30_000 },
  'ops:review-backlog-report --next-target --focus=source-backed-packet --json': { description: 'Read-only sanitized source-backed review packet target JSON', timeout: 30_000 },
  'ops:offline-status --json':   { description: 'Offline/degraded runtime status', timeout: 15_000 },
  'ops:offline-smoke --json':    { description: 'Manual read-only offline smoke report', timeout: 30_000 },
  'ops:agent-doctor --json --since=24': { description: 'Observe-only agent health diagnostics', timeout: 30_000 },
  'ops:agent-doctor --compact': { description: 'Compact observe-only agent health diagnostics', timeout: 30_000 },
  'ops:agent-doctor --json --compact': { description: 'Compact observe-only agent health diagnostics JSON', timeout: 30_000 },
  'plos:agent-doctor --compact': { description: 'Compact PLOS operator-facing agent diagnostics', timeout: 30_000 },
  'plos:agent-doctor --json --compact': { description: 'Compact PLOS operator-facing agent diagnostics JSON', timeout: 30_000 },
  'ops:agent-doctor-snapshot --dry-run --json': { description: 'Dry-run aggregate Agent Doctor readiness snapshot', timeout: 30_000 },
  'ops:agent-doctor-history --json --days=7': { description: 'Read-only Agent Doctor readiness snapshot history', timeout: 30_000 },
  'plos:agent-trace-tail --limit=20 --since=24 --json': { description: 'Read-only redacted recent agent trace tail JSON', timeout: 30_000 },
  'ops:mcp-health --compact': { description: 'Compact observe-only MCP configuration and process health scorecard', timeout: 30_000 },
  'ops:mcp-health --json --compact': { description: 'Compact observe-only MCP configuration and process health JSON', timeout: 30_000 },
  'ops:capacity-report --json':   { description: 'Observe-only capacity evidence report', timeout: 30_000 },
  'ops:runtime-diagnostics --window=60m --focus=all --json': { description: 'Read-only runtime recovery diagnostics', timeout: 30_000 },
  'ops:face-telemetry-report --json': { description: 'Face/genealogy telemetry report', timeout: 30_000 },
  'ops:face-telemetry-report --markdown --hours=168': { description: 'Weekly face/genealogy telemetry markdown', timeout: 30_000 },
  'ops:face-telemetry-report --compact': { description: 'Compact face/genealogy telemetry report', timeout: 30_000 },
  'ops:face-telemetry-report --json --compact': { description: 'Compact face/genealogy telemetry JSON', timeout: 30_000 },
  'ops:face-telemetry-report --markdown --compact': { description: 'Compact face/genealogy telemetry markdown', timeout: 30_000 },
  'ops:dba-telemetry-report --json': { description: 'Observe-only DBA telemetry report', timeout: 60_000 },
  'ops:dba-telemetry-report --compact': { description: 'Compact observe-only DBA telemetry report', timeout: 60_000 },
  'ops:dba-telemetry-report --json --compact': { description: 'Compact observe-only DBA telemetry JSON', timeout: 60_000 },
  'ops:dba-telemetry-report --markdown --compact': { description: 'Compact observe-only DBA telemetry markdown', timeout: 60_000 },
  'ops:dba-telemetry-report --markdown --dry-run': { description: 'Dry-run observe-only DBA telemetry markdown', timeout: 60_000 },
  'ops:arc-retention --json': { description: 'Read-only ARC retention dry-run evidence', timeout: 30_000 },
  'ops:audit-privacy-routing --json': { description: 'Sensitive-provider privacy routing audit', timeout: 30_000 },
  'ops:sync-schema-reference':   { description: 'Regenerate schema-reference.md from live DB', timeout: 30_000 },
  'ops:sync-schema-reference --diff': { description: 'Show schema drift without writing', timeout: 30_000 },
  'files:registry --status':     { description: 'File registry status', timeout: 15_000 },
  'files:thumbnails --stats':    { description: 'Thumbnail pipeline stats', timeout: 15_000 },
  'genealogy:reject-codes --json': { description: 'Genealogy reviewer reject-code rollup', timeout: 30_000 },
  'genealogy:reject-codes --json --days=30': { description: 'Genealogy reviewer reject-code 30-day rollup', timeout: 30_000 },
  'genealogy:reject-codes --json --compact': { description: 'Compact genealogy reviewer reject-code rollup', timeout: 30_000 },
  'genealogy:review-feedback --days=30 --json': { description: 'Genealogy reviewer feedback rollup', timeout: 30_000 },
  'genealogy:review-feedback --json --compact': { description: 'Compact genealogy reviewer feedback rollup', timeout: 30_000 },
  'genealogy:packet-reason-codes --days=30 --json': { description: 'Genealogy review-packet reason-code rollup', timeout: 30_000 },
  'genealogy:packet-reason-codes --json --compact': { description: 'Compact genealogy review-packet reason-code rollup', timeout: 30_000 },
  'genealogy:evidence-sprint-report --json': { description: 'Read-only genealogy evidence sprint readiness report', timeout: 30_000 },
  'genealogy:evidence-sprint-report --json --compact': { description: 'Compact genealogy evidence sprint readiness report', timeout: 30_000 },
  'genealogy:evidence-sprint-report --markdown': { description: 'Read-only genealogy evidence sprint readiness markdown', timeout: 30_000 },
  'genealogy:agent-triage --json': { description: 'Read-only genealogy agent re-enablement triage', timeout: 30_000 },
  'genealogy:agent-triage --compact': { description: 'Compact genealogy agent re-enablement triage text', timeout: 30_000 },
  'genealogy:agent-triage --json --compact': { description: 'Compact genealogy agent re-enablement triage', timeout: 30_000 },
  'genealogy:source-registry --validate': { description: 'Read-only genealogy source registry posture validation', timeout: 30_000 },
  'awo:replay --window=7d --json': { description: 'Approval-worthy-output read-only replay', timeout: 30_000 },
  'awo:replay --window=7d --compact': { description: 'Compact approval-worthy-output read-only replay with default evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --json --compact': { description: 'Compact approval-worthy-output read-only replay JSON with default evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --markdown --compact': { description: 'Compact approval-worthy-output read-only markdown report with default evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --json': { description: 'Approval-worthy-output read-only replay with weekly evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --compact': { description: 'Compact approval-worthy-output read-only replay', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --json --compact': { description: 'Compact approval-worthy-output read-only replay JSON', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --markdown --compact': { description: 'Compact approval-worthy-output read-only markdown report', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --markdown': { description: 'Weekly approval-worthy-output read-only markdown report', timeout: 30_000 },
  'awo:replay --compare-scheduled --window=7d --limit=500 --json': { description: 'Read-only AWO scheduled report comparison', timeout: 30_000 },
  'scheduler:optimize-report --json': { description: 'Observe-only scheduler optimization recommendations', timeout: 30_000 },
  'scheduler:optimize-report --window=7d --json': { description: 'Observe-only 7-day scheduler optimization recommendations', timeout: 30_000 },
  'scheduler:optimize-report --compact': { description: 'Compact scheduler optimization recommendations', timeout: 30_000 },
  'scheduler:optimize-report --json --compact': { description: 'Compact scheduler optimization recommendations JSON', timeout: 30_000 },
  'news:bias-tags-audit --json': { description: 'Read-only news-bias tag audit', timeout: 30_000 },
  'news:bias-tags-audit --compact': { description: 'Compact read-only news-bias tag audit', timeout: 30_000 },
  'news:bias-tags-audit --json --compact': { description: 'Compact read-only news-bias tag audit JSON', timeout: 30_000 },
  'news:pushover-proof --workflow=news_brief --compact': { description: 'Compact Pushover proof for the latest natural news brief run', timeout: 30_000 },
  'news:pushover-proof --workflow=news_brief --json --compact': { description: 'Compact Pushover proof JSON for the latest natural news brief run', timeout: 30_000 },
  'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --compact': { description: 'Compact Pushover proof for the latest natural Press Enterprise run', timeout: 30_000 },
  'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --json --compact': { description: 'Compact Pushover proof JSON for the latest natural Press Enterprise run', timeout: 30_000 },
  'news:source-inventory --workflow=news_brief --days=7 --strict --json': { description: 'Read-only news source inventory and bias coverage', timeout: 30_000 },
  'bias:aliases --unmatched --limit=50 --json': { description: 'Read-only unmatched news-bias source aliases', timeout: 30_000 },
  'rag:raptor-build --stats':    { description: 'RAPTOR pipeline stats', timeout: 15_000 },
  'rag:build-sentences --stats': { description: 'Sentence embedding stats', timeout: 15_000 },
  'rag:build-knowledge-graph --stats': { description: 'Knowledge graph stats', timeout: 15_000 },
  'rag:backlog-report --json':   { description: 'RAG/RAPTOR/sentence/KG backlog evidence', timeout: 30_000 },
  'rag:backlog-report --compact': { description: 'Compact RAG/RAPTOR/sentence/KG backlog evidence', timeout: 30_000 },
  'rag:backlog-report --json --compact': { description: 'Compact RAG/RAPTOR/sentence/KG backlog evidence', timeout: 30_000 },
  'rag:scale-baseline --json':   { description: 'Read-only RAG scale baseline evidence', timeout: 60_000 },
  'rag:scale-baseline --markdown': { description: 'Read-only RAG scale baseline markdown', timeout: 60_000 },
  'rag:scale-review --json':   { description: 'Read-only RAG scale review evidence without saved retrieval file', timeout: 60_000 },
  'rag:scale-review --markdown': { description: 'Read-only RAG scale review markdown without saved retrieval file', timeout: 60_000 },
  'rag:scale-review --compact': { description: 'Compact read-only RAG scale review evidence without saved retrieval file', timeout: 60_000 },
  'rag:scale-review --json --compact': { description: 'Compact read-only RAG scale review JSON without saved retrieval file', timeout: 60_000 },
  'graph:audit-provenance --json': { description: 'Knowledge graph provenance audit', timeout: 60_000 },
  'graph:detect-communities --stats':  { description: 'Community detection stats', timeout: 15_000 },
  'graph:quality-metrics --stats':     { description: 'Graph quality metrics', timeout: 15_000 },
  'graph:quality-metrics --stats --json': { description: 'Graph quality metrics JSON stats only', timeout: 30_000 },
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

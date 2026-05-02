import { z } from 'zod';
import { mysqlQuery } from '../db/mysql.js';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosJobDiagnosticInput = z.object({
  name: z.string().describe('Job name or partial match (e.g., "raptor", "file_enrich")'),
  runs: z.coerce.number().optional().default(5).describe('Number of recent runs to show'),
  include_logs: z.coerce.boolean().optional().default(true).describe('Search laravel.log for related errors'),
});

export type PlosJobDiagnosticInput = z.infer<typeof plosJobDiagnosticInput>;

export function summarizeFailurePattern(row: Record<string, unknown>): string[] {
  const total24h = Number(row.total_24h ?? 0);
  const failed24h = Number(row.failed_24h ?? 0);
  const timeout24h = Number(row.timeout_24h ?? 0);
  const sigalrm24h = Number(row.sigalrm_24h ?? 0);
  const stalled24h = Number(row.stalled_24h ?? 0);
  const zombie24h = Number(row.zombie_24h ?? 0);
  const avgDuration = Number(row.avg_duration ?? 0);
  const maxDuration = Number(row.max_duration ?? 0);

  const markers: string[] = [];
  if (sigalrm24h > 0) markers.push(`${sigalrm24h} SIGALRM`);
  if (stalled24h > 0) markers.push(`${stalled24h} stalled`);
  if (zombie24h > 0) markers.push(`${zombie24h} zombie`);

  const headline = markers.length > 0
    ? `\n24h pattern: ${total24h} runs, ${failed24h} failed, ${timeout24h} timeout/stall (${markers.join(', ')})`
    : `\n24h pattern: ${total24h} runs, ${failed24h} failed, ${timeout24h} timeout/stall`;

  return [
    headline,
    `  Avg duration: ${Math.round(avgDuration)}s | Max: ${Math.round(maxDuration)}s`,
  ];
}

export function buildDiagnosticSearchTerms(job: Record<string, unknown>): string[] {
  const terms = new Set<string>();

  const name = String(job.name ?? '').trim();
  const command = String(job.command ?? '').trim();
  const jobType = String(job.job_type ?? '').trim();

  if (name) terms.add(name);
  if (jobType === 'agent_task' && command) {
    terms.add(command);
    terms.add('ScheduledJobService: Starting agent task');
    terms.add('ScheduledJobService: Agent task returned');
    terms.add('ScheduledJobService: Returning failed agent task result');
    terms.add('ScheduledJobService: Agent task exception');
  }

  return [...terms];
}

export async function plosJobDiagnostic(input: PlosJobDiagnosticInput, context?: ToolContext): Promise<string> {
  const { name, runs, include_logs } = input;
  const sections: string[] = [];

  // 1. Find the job
  const jobs = await mysqlQuery(`
    SELECT id, name, description, cron_expression, enabled, timeout_minutes,
           job_type, command,
           last_run_at, last_completed_at, last_run_status, last_pid,
           next_run_at, run_count, fail_count, notes, category
    FROM scheduled_jobs
    WHERE name LIKE ?
    ORDER BY name ASC
    LIMIT 5
  `, [`%${name}%`]) as Array<Record<string, unknown>>;

  if (jobs.length === 0) {
    return `No scheduled job matching "${name}".`;
  }

  for (const job of jobs) {
    const lines: string[] = [];
    const status = job.enabled ? 'ENABLED' : 'DISABLED';
    const runStatus = job.last_run_status === 'failed' ? 'FAIL' :
                      job.last_run_status === 'running' ? 'RUNNING' : 'OK';

    lines.push(`=== ${job.name} (id:${job.id}) [${status}] ===`);
    lines.push(`Schedule: ${job.cron_expression} | Timeout: ${job.timeout_minutes}min`);
    lines.push(`Last run: ${job.last_run_at ?? 'never'} [${runStatus}] | PID: ${job.last_pid ?? 'N/A'}`);
    lines.push(`Last completed: ${job.last_completed_at ?? 'never'}`);
    lines.push(`Next run: ${job.next_run_at ?? 'N/A'}`);
    lines.push(`Lifetime: ${job.run_count} runs, ${job.fail_count} failures`);
    if (job.notes) lines.push(`Notes: ${job.notes}`);

    // 2. Recent runs
    const recentRuns = await mysqlQuery(`
      SELECT status, started_at, completed_at,
             duration_seconds,
             LEFT(output, 500) as output_preview,
             items_processed
      FROM scheduled_job_runs
      WHERE scheduled_job_id = ?
      ORDER BY started_at DESC
      LIMIT ${Math.min(Math.max(1, runs), 20)}
    `, [job.id]) as Array<Record<string, unknown>>;

    if (recentRuns.length > 0) {
      lines.push(`\nLast ${recentRuns.length} runs:`);
      for (const r of recentRuns) {
        const dur = r.duration_seconds ? `${r.duration_seconds}s` : '?';
        const items = r.items_processed ? ` (${r.items_processed} items)` : '';
        lines.push(`  ${r.status} | ${r.started_at} | ${dur}${items}`);
        if (r.status === 'failed' && r.output_preview) {
          lines.push(`    Output: ${(r.output_preview as string).substring(0, 200)}`);
        }
      }

      // 3. Failure pattern analysis
      const failPattern = await mysqlQuery(`
        SELECT COUNT(*) as total_24h,
               SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_24h,
               SUM(CASE
                     WHEN status = 'timeout'
                       OR (status = 'failed' AND (
                            output LIKE '%[SIGALRM]%'
                         OR output LIKE '%[STALLED]%'
                         OR output LIKE '%[ZOMBIE]%'
                         OR output LIKE '%timeout%'
                       ))
                     THEN 1 ELSE 0
                   END) as timeout_24h,
               SUM(CASE WHEN status = 'failed' AND output LIKE '%[SIGALRM]%' THEN 1 ELSE 0 END) as sigalrm_24h,
               SUM(CASE WHEN status = 'failed' AND output LIKE '%[STALLED]%' THEN 1 ELSE 0 END) as stalled_24h,
               SUM(CASE WHEN status = 'failed' AND output LIKE '%[ZOMBIE]%' THEN 1 ELSE 0 END) as zombie_24h,
               AVG(duration_seconds) as avg_duration,
               MAX(duration_seconds) as max_duration
        FROM scheduled_job_runs
        WHERE scheduled_job_id = ?
          AND started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
      `, [job.id]) as Array<Record<string, unknown>>;

      if (failPattern[0]) {
        lines.push(...summarizeFailurePattern(failPattern[0]));
      }
    } else {
      lines.push('\nNo recent runs found.');
    }

    // 4. Related system alerts
    const alerts = await mysqlQuery(`
      SELECT alert_type, message, severity, triggered_at, resolved_at
      FROM system_alerts
      WHERE message LIKE ?
        AND triggered_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
      ORDER BY triggered_at DESC
      LIMIT 5
    `, [`%${job.name}%`]) as Array<Record<string, unknown>>;

    if (alerts.length > 0) {
      lines.push(`\nRelated alerts (24h):`);
      for (const a of alerts) {
        const resolved = a.resolved_at ? ' [resolved]' : ' [active]';
        lines.push(`  [${a.severity}] ${a.alert_type}: ${(a.message as string).substring(0, 150)}${resolved}`);
      }
    }

    // 5. Related system errors
    const errors = await mysqlQuery(`
      SELECT error_type, COUNT(*) as cnt, MAX(created_at) as latest,
             LEFT(GROUP_CONCAT(DISTINCT error_message SEPARATOR ' | '), 300) as messages
      FROM system_errors
      WHERE (error_message LIKE ? OR context LIKE ?)
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
      GROUP BY error_type
      ORDER BY cnt DESC
      LIMIT 5
    `, [`%${job.name}%`, `%${job.name}%`]) as Array<Record<string, unknown>>;

    if (errors.length > 0) {
      lines.push(`\nRelated errors (24h):`);
      for (const e of errors) {
        lines.push(`  ${e.error_type}: ${e.cnt}x (latest: ${e.latest})`);
        lines.push(`    ${(e.messages as string).substring(0, 200)}`);
      }
    }

    // 6. Log search (optional)
    if (include_logs) {
      const searchTerms = buildDiagnosticSearchTerms(job);

      for (const term of searchTerms) {
        try {
          const safeTerm = term.replace(/[^a-zA-Z0-9:_ .-]/g, ' ');
          const logPath = shellQuote(`${CONFIG.paths.remoteRoot}/storage/logs/laravel.log`);
          const cmd = `${sshCmd()} "tail -n 5000 ${logPath} | grep -i '${safeTerm}' | grep -i 'error\\|exception\\|fail\\|timeout\\|ScheduledJobService:' | tail -n 5"`;
          const { stdout } = await execCommand(cmd, { timeout: 10_000, signal: context?.signal });
          if (stdout.trim()) {
            lines.push(`\nRecent log errors (${term}):`);
            lines.push(stdout.trim());
            break;
          }
        } catch {
          // grep no matches or SSH timeout — try next term
        }
      }
    }

    sections.push(lines.join('\n'));
  }

  return sections.join('\n\n');
}

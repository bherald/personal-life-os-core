import { z } from 'zod';
import { mysqlQuery } from '../db/mysql.js';

export const plosJobStatusInput = z.object({
  name: z.string().optional().describe('Filter by job name (partial match)'),
  status: z.enum(['running', 'success', 'failed', 'timeout', 'all']).optional().default('all')
    .describe('Filter by last run status'),
  showRuns: z.coerce.boolean().optional().default(false).describe('Include last 5 run details per job'),
});

export type PlosJobStatusInput = z.infer<typeof plosJobStatusInput>;

export async function plosJobStatus(input: PlosJobStatusInput): Promise<string> {
  const { name, status, showRuns } = input;

  let where = 'WHERE 1=1';
  const params: unknown[] = [];

  if (name) {
    where += ' AND sj.name LIKE ?';
    params.push(`%${name}%`);
  }
  if (status && status !== 'all') {
    where += ' AND sj.last_run_status = ?';
    params.push(status);
  }

  const jobs = await mysqlQuery(`
    SELECT sj.id, sj.name, sj.cron_expression, sj.enabled, sj.timeout_minutes,
           sj.last_run_status, sj.last_run_at, sj.next_run_at, sj.last_pid,
           sj.notes,
           (SELECT COUNT(*) FROM scheduled_job_runs sjr
            WHERE sjr.scheduled_job_id = sj.id
              AND sjr.started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as runs_24h,
           (SELECT COUNT(*) FROM scheduled_job_runs sjr
            WHERE sjr.scheduled_job_id = sj.id
              AND sjr.status = 'failed'
              AND sjr.started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as fails_24h,
           (SELECT COUNT(*)
            FROM scheduled_job_runs sjr
            WHERE sjr.scheduled_job_id = sj.id
              AND sjr.status = 'failed'
              AND sjr.started_at > COALESCE(
                (SELECT MAX(s2.started_at)
                 FROM scheduled_job_runs s2
                 WHERE s2.scheduled_job_id = sj.id
                   AND s2.status = 'success'),
                '2000-01-01'
              )) as consecutive_failures
    FROM scheduled_jobs sj
    ${where}
    ORDER BY sj.name ASC
    LIMIT 50
  `, params) as Array<Record<string, unknown>>;

  if (jobs.length === 0) return 'No matching jobs found.';

  const lines: string[] = [];
  for (const j of jobs) {
    const active = j.enabled ? '' : ' [DISABLED]';
    const statusIcon = j.last_run_status === 'failed' ? 'FAIL' : j.last_run_status === 'running' ? 'RUN' : 'OK';
    const consecutiveFailures = Number(j.consecutive_failures ?? 0);
    lines.push(`[${statusIcon}] ${j.name}${active} (id:${j.id})`);
    lines.push(`  Schedule: ${j.cron_expression} | Timeout: ${j.timeout_minutes}min`);
    lines.push(`  Last: ${j.last_run_at ?? 'never'} | Next: ${j.next_run_at ?? 'N/A'}`);
    lines.push(`  24h: ${j.runs_24h} runs, ${j.fails_24h} failures${consecutiveFailures > 0 ? ` | ${consecutiveFailures} consecutive` : ''}`);

    if (showRuns) {
      const runs = await mysqlQuery(`
        SELECT status, started_at, completed_at,
               duration_seconds,
               LEFT(output, 200) as output_preview
        FROM scheduled_job_runs
        WHERE scheduled_job_id = ?
        ORDER BY started_at DESC
        LIMIT 5
      `, [j.id]) as Array<Record<string, unknown>>;

      for (const r of runs) {
        lines.push(`    ${r.status} ${r.started_at} (${r.duration_seconds}s)${r.output_preview ? ': ' + (r.output_preview as string).substring(0, 80) : ''}`);
      }
    }

    lines.push('');
  }

  return lines.join('\n');
}

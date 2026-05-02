import { z } from 'zod';
import { mysqlQuery } from '../db/mysql.js';
import { CONFIG, sshCmd } from '../config.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosHealthInput = z.object({
  section: z.enum(['all', 'jobs', 'agents', 'system', 'queues', 'errors']).optional().default('all')
    .describe('Which health section to return'),
});

export type PlosHealthInput = z.infer<typeof plosHealthInput>;

export async function plosHealth(input: PlosHealthInput, context?: ToolContext): Promise<string> {
  const { section } = input;
  const sections: string[] = [];

  try {
    if (section === 'all' || section === 'system') {
      sections.push(await getSystemHealth(context?.signal));
    }
    if (section === 'all' || section === 'jobs') {
      sections.push(await getJobsHealth());
    }
    if (section === 'all' || section === 'agents') {
      sections.push(await getAgentsHealth());
    }
    if (section === 'all' || section === 'queues') {
      sections.push(await getQueueHealth());
    }
    if (section === 'all' || section === 'errors') {
      sections.push(await getRecentErrors());
    }
  } catch (err) {
    sections.push(`Error: ${(err as Error).message}`);
  }

  return sections.join('\n\n');
}

async function getSystemHealth(signal?: AbortSignal): Promise<string> {
  const lines = ['=== SYSTEM ==='];
  try {
    const { stdout } = await execCommand(
      `${sshCmd()} "uptime; echo '---'; df -h / | tail -1; echo '---'; nvidia-smi --query-gpu=name,utilization.gpu,memory.used,memory.total --format=csv,noheader 2>/dev/null || echo 'No GPU'"`,
      { timeout: 10_000, signal }
    );
    const parts = stdout.split('---');
    lines.push(`Uptime: ${parts[0]?.trim()}`);
    lines.push(`Disk: ${parts[1]?.trim()}`);
    lines.push(`GPU: ${parts[2]?.trim()}`);
  } catch {
    lines.push('(SSH to remote instance unavailable)');
  }
  return lines.join('\n');
}

async function getJobsHealth(): Promise<string> {
  const lines = ['=== SCHEDULED JOBS ==='];

  const jobs = await mysqlQuery(`
    SELECT sj.name, sj.cron_expression, sj.enabled, sj.last_run_status,
           sj.last_run_at, sj.next_run_at,
           (SELECT COUNT(*) FROM scheduled_job_runs sjr
            WHERE sjr.scheduled_job_id = sj.id
              AND sjr.status = 'failed'
              AND sjr.started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as failures_24h,
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
    WHERE sj.enabled = 1
    ORDER BY sj.next_run_at ASC
    LIMIT 30
  `) as Array<Record<string, unknown>>;

  for (const j of jobs) {
    const status = j.last_run_status === 'failed' ? 'FAIL' : j.last_run_status === 'running' ? 'RUN' : 'OK';
    const consecutiveFailures = Number(j.consecutive_failures ?? 0);
    const recentFailures = Number(j.failures_24h ?? 0);

    let annotation = '';
    if (consecutiveFailures > 0) {
      annotation = ` (${consecutiveFailures} consecutive fails)`;
    } else if (j.last_run_status === 'failed' && recentFailures > 0) {
      annotation = ` (${recentFailures} fails/24h)`;
    }

    lines.push(`  [${status}] ${j.name}${annotation} — last: ${j.last_run_at ?? 'never'}, next: ${j.next_run_at ?? 'N/A'}`);
  }

  return lines.join('\n');
}

async function getAgentsHealth(): Promise<string> {
  const lines = ['=== AGENTS ==='];

  const agents = await mysqlQuery(`
    SELECT agent_name,
           status,
           total_tokens,
           message_count,
           last_activity_at,
           created_at
    FROM agent_sessions
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY created_at DESC
    LIMIT 20
  `) as Array<Record<string, unknown>>;

  if (agents.length === 0) {
    lines.push('  No agent sessions in last 24h');
  } else {
    for (const a of agents) {
      lines.push(`  [${a.status}] ${a.agent_name} — ${a.message_count} msgs, ${a.total_tokens} tokens, ${a.last_activity_at}`);
    }
  }

  return lines.join('\n');
}

async function getQueueHealth(): Promise<string> {
  const lines = ['=== QUEUES ==='];
  try {
    const pending = await mysqlQuery(`SELECT COUNT(*) as cnt FROM jobs`) as Array<Record<string, number>>;
    const failed = await mysqlQuery(`SELECT COUNT(*) as cnt FROM failed_jobs`) as Array<Record<string, number>>;
    lines.push(`  Pending: ${pending[0]?.cnt ?? 0}`);
    lines.push(`  Failed: ${failed[0]?.cnt ?? 0}`);
  } catch (err) {
    lines.push(`  Error: ${(err as Error).message}`);
  }
  return lines.join('\n');
}

async function getRecentErrors(): Promise<string> {
  const lines = ['=== ERRORS (last 4h) ==='];

  const errors = await mysqlQuery(`
    SELECT error_type, COUNT(*) as cnt,
           MAX(created_at) as latest,
           LEFT(GROUP_CONCAT(DISTINCT error_message SEPARATOR ' | '), 200) as messages
    FROM system_errors
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 4 HOUR)
    GROUP BY error_type
    ORDER BY cnt DESC
    LIMIT 10
  `) as Array<Record<string, unknown>>;

  if (errors.length === 0) {
    lines.push('  No errors in last 4 hours');
  } else {
    for (const e of errors) {
      lines.push(`  ${e.error_type}: ${e.cnt}x (latest: ${e.latest})`);
      lines.push(`    ${(e.messages as string)?.substring(0, 150)}`);
    }
  }

  return lines.join('\n');
}

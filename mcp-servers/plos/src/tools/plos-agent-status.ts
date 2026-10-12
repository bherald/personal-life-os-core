import { z } from 'zod';
import { mysqlQuery } from '../db/mysql.js';

export const plosAgentStatusInput = z.object({
  agent: z.string().optional().describe('Filter by agent name (partial match)'),
  hours: z.coerce.number().optional().default(24).describe('How many hours back to look'),
  showMessages: z.coerce.boolean().optional().default(false).describe('Include message summary'),
});

export type PlosAgentStatusInput = z.infer<typeof plosAgentStatusInput>;

export async function plosAgentStatus(input: PlosAgentStatusInput): Promise<string> {
  return agentStatusReport(input, mysqlQuery);
}

export async function agentStatusReport(input: PlosAgentStatusInput, query: typeof mysqlQuery): Promise<string> {
  const { agent, hours, showMessages } = input;

  let where = 'WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)';
  const params: unknown[] = [hours];

  if (agent) {
    where += ' AND agent_name LIKE ?';
    params.push(`%${agent}%`);
  }

  const sessions = await query(`
    SELECT session_id, agent_name, status, total_tokens, message_count,
           last_activity_at, created_at,
           TIMESTAMPDIFF(SECOND, created_at, COALESCE(last_activity_at, NOW())) as duration_sec
    FROM agent_sessions
    ${where}
    ORDER BY created_at DESC
    LIMIT 30
  `, params) as Array<Record<string, unknown>>;

  if (sessions.length === 0) return `No agent sessions in last ${hours}h.`;

  const sessionIds = sessions.map(s => s.session_id);
  const researchRows = await query(`
    SELECT q.session_id, q.status, q.last_task_id, q.last_outcome_state,
           q.findings_count, q.review_items_count
    FROM genealogy_research_queue q
    JOIN (
      SELECT MAX(id) AS id FROM genealogy_research_queue
      WHERE session_id IN (${sessionIds.map(() => '?').join(', ')})
      GROUP BY session_id
    ) latest ON latest.id = q.id
  `, sessionIds) as Array<Record<string, unknown>>;
  const researchBySession = new Map(researchRows.map(row => [row.session_id, row]));
  const lines: string[] = [`=== Agent Sessions (last ${hours}h) ===`];

  for (const s of sessions) {
    const research = researchBySession.get(s.session_id);
    const statusIcon = research?.status === 'failed' ? 'FAIL'
      : s.status === 'completed' ? 'ENDED' : s.status === 'active' ? 'RUN' : String(s.status).toUpperCase();
    lines.push(`[${statusIcon}] ${s.agent_name} (${s.session_id})`);
    lines.push(`  Duration: ${s.duration_sec}s | Estimated message tokens: ${s.total_tokens} | Messages: ${s.message_count}`);
    lines.push(`  Started: ${s.created_at} | Last: ${s.last_activity_at}`);
    if (research) {
      lines.push(`  Research: ${research.status} | Outcome: ${research.last_outcome_state ?? 'unrecorded'} | Task: ${research.last_task_id ?? 'none'} | Findings: ${research.findings_count} | Reviews: ${research.review_items_count}`);
    }

    if (showMessages) {
      const reviews = await query(`
        SELECT review_type, title, status, confidence, priority
        FROM agent_review_queue
        WHERE agent_id = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY created_at DESC
        LIMIT 5
      `, [s.agent_name, hours]) as Array<Record<string, unknown>>;

      if (reviews.length > 0) {
        lines.push('  Recent review items for this agent (not session-specific):');
        for (const r of reviews) {
          lines.push(`    [${r.status}] ${r.review_type}: ${r.title} (conf:${r.confidence}, pri:${r.priority})`);
        }
      }
    }

    lines.push('');
  }

  return lines.join('\n');
}

import { z } from 'zod';
import { CONFIG } from '../config.js';
import { guardQuery } from '../db/query-guard.js';
import { mysqlQuery } from '../db/mysql.js';
import { pgQuery } from '../db/postgres.js';
import { logger } from '../util/logger.js';

export const plosQueryInput = z.object({
  sql: z.string().describe('SQL query to execute'),
  database: z.enum(['mysql', 'pgsql']).optional().default('mysql').describe('Target database'),
  params: z.array(z.unknown()).optional().default([]).describe('Query parameters (positional)'),
  allowWrite: z.coerce.boolean().optional().default(false).describe('Allow UPDATE/INSERT (default: read-only)'),
});

export type PlosQueryInput = z.infer<typeof plosQueryInput>;

export async function plosQueryTool(input: PlosQueryInput): Promise<string> {
  const { sql, database, params, allowWrite } = input;

  // Safety check
  const guard = guardQuery(sql, allowWrite);
  if (!guard.allowed) {
    return `BLOCKED: ${guard.reason}`;
  }

  const safeSql = guard.sanitizedSql ?? sql;
  logger.info('Query executed', { database, sql: safeSql.substring(0, 200) });

  try {
    const start = Date.now();
    const rows = database === 'pgsql'
      ? await pgQuery(safeSql, params as unknown[])
      : await mysqlQuery(safeSql, params as unknown[]);

    const duration = Date.now() - start;
    const resultRows = Array.isArray(rows) ? rows : [];
    const rowCount = resultRows.length;

    // Format output
    if (rowCount === 0) {
      return `No results (${duration}ms)`;
    }

    // JSON output for structured consumption
    const header = `${rowCount} row${rowCount > 1 ? 's' : ''} (${duration}ms)`;

    // For small result sets, return full JSON
    const previewRows = resultRows.slice(0, CONFIG.query.maxRows);
    const json = JSON.stringify(previewRows, null, 2);
    if (json.length <= 10_000) {
      return `${header}\n${json}`;
    }

    // For large results, truncate
    return `${header} (truncated — showing first 5000 chars)\n${json.substring(0, 5000)}\n...`;
  } catch (err) {
    const msg = (err as Error).message;
    logger.error('Query failed', { database, error: msg });
    return `ERROR: ${msg}`;
  }
}

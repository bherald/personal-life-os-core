import pg from 'pg';
import { CONFIG } from '../config.js';
import { logger } from '../util/logger.js';

let pool: pg.Pool | null = null;

export function getPgPool(): pg.Pool {
  if (!pool) {
    pool = new pg.Pool({
      host: CONFIG.pgsql.host,
      port: CONFIG.pgsql.port,
      user: CONFIG.pgsql.user,
      password: CONFIG.pgsql.password,
      database: CONFIG.pgsql.database,
      max: 5,
      connectionTimeoutMillis: CONFIG.query.timeoutMs,
      idleTimeoutMillis: 30_000,
      // Per-query timeout — kills queries that exceed this threshold
      statement_timeout: CONFIG.query.timeoutMs,
      query_timeout: CONFIG.query.timeoutMs,
    });
    logger.info('PostgreSQL pool created', { host: CONFIG.pgsql.host, port: CONFIG.pgsql.port });
  }
  return pool;
}

export async function pgQuery(sql: string, params: unknown[] = []): Promise<unknown[]> {
  const pool = getPgPool();
  const result = await pool.query(sql, params);
  return result.rows;
}

export async function pgDescribe(table: string): Promise<unknown[]> {
  const safeName = table.replace(/[^a-zA-Z0-9_]/g, '');
  return pgQuery(`
    SELECT column_name, data_type, is_nullable, column_default,
           character_maximum_length
    FROM information_schema.columns
    WHERE table_name = $1
    ORDER BY ordinal_position
  `, [safeName]);
}

export async function closePg(): Promise<void> {
  if (pool) {
    await pool.end();
    pool = null;
  }
}

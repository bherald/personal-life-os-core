import mysql from 'mysql2/promise';
import { CONFIG } from '../config.js';
import { logger } from '../util/logger.js';

let pool: mysql.Pool | null = null;

export function getMysqlPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool({
      host: CONFIG.mysql.host,
      port: CONFIG.mysql.port,
      user: CONFIG.mysql.user,
      password: CONFIG.mysql.password,
      database: CONFIG.mysql.database,
      waitForConnections: true,
      connectionLimit: 5,
      connectTimeout: CONFIG.query.timeoutMs,
      enableKeepAlive: true,
    });
    logger.info('MySQL pool created', { host: CONFIG.mysql.host, port: CONFIG.mysql.port });
  }
  return pool;
}

/**
 * Execute a MySQL query with a timeout guard.
 * Sets session-level wait_timeout and max_execution_time to prevent
 * stalled queries from blocking the MCP transport indefinitely.
 */
export async function mysqlQuery(sql: string, params: unknown[] = []): Promise<unknown[]> {
  const p = getMysqlPool();
  const conn = await p.getConnection();
  try {
    // Set per-query timeout hint (MySQL 5.7.8+ / MariaDB 10.1.1+)
    const timeoutSec = Math.ceil(CONFIG.query.timeoutMs / 1000);
    await conn.execute(`SET SESSION max_execution_time = ${timeoutSec * 1000}`);
    const [rows] = await conn.execute(sql, params as any[]);
    return rows as unknown[];
  } finally {
    conn.release();
  }
}

export async function mysqlDescribe(table: string): Promise<unknown[]> {
  return mysqlQuery(`DESCRIBE \`${table.replace(/[^a-zA-Z0-9_]/g, '')}\``);
}

export async function closeMysql(): Promise<void> {
  if (pool) {
    await pool.end();
    pool = null;
  }
}

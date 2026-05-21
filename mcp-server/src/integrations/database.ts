import mysql from 'mysql2/promise';
import { Workflow, WorkflowRun, NodeExecution } from '../types.js';

export class DatabaseManager {
  private pool: mysql.Pool;

  // Whitelisted tables for direct queries
  private readonly allowedTables = [
    'workflows',
    'workflow_nodes',
    'workflow_node_configs',
    'workflow_runs',
    'node_executions',
    'node_execution_outputs',
    'retry_configs',
  ];

  constructor() {
    this.pool = mysql.createPool({
      host: process.env.DB_HOST || '127.0.0.1',
      port: parseInt(process.env.DB_PORT || '3306'),
      user: process.env.DB_USER || 'plos',
      password: process.env.DB_PASSWORD || '',
      database: process.env.DB_NAME || 'plos',
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0,
    });
  }

  async testConnection(): Promise<boolean> {
    try {
      const connection = await this.pool.getConnection();
      connection.release();
      return true;
    } catch (error) {
      console.error('Database connection failed:', error);
      return false;
    }
  }

  async getWorkflows(activeOnly = false): Promise<Workflow[]> {
    const query = activeOnly
      ? 'SELECT * FROM workflows WHERE active = 1 ORDER BY name'
      : 'SELECT * FROM workflows ORDER BY name';

    const [rows] = await this.pool.query(query);
    return rows as Workflow[];
  }

  async getWorkflowById(id: number): Promise<Workflow | null> {
    const [rows] = await this.pool.query<mysql.RowDataPacket[]>(
      'SELECT * FROM workflows WHERE id = ?',
      [id]
    );
    return rows.length > 0 ? (rows[0] as Workflow) : null;
  }

  async getWorkflowByName(name: string): Promise<Workflow | null> {
    const [rows] = await this.pool.query<mysql.RowDataPacket[]>(
      'SELECT * FROM workflows WHERE name = ?',
      [name]
    );
    return rows.length > 0 ? (rows[0] as Workflow) : null;
  }

  async getWorkflowNodes(workflowId: number) {
    const [rows] = await this.pool.query(
      'SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order',
      [workflowId]
    );
    return rows;
  }

  async getWorkflowRuns(
    workflowId?: number,
    limit = 50
  ): Promise<WorkflowRun[]> {
    const query = workflowId
      ? 'SELECT * FROM workflow_runs WHERE workflow_id = ? ORDER BY id DESC LIMIT ?'
      : 'SELECT * FROM workflow_runs ORDER BY id DESC LIMIT ?';

    const params = workflowId ? [workflowId, limit] : [limit];
    const [rows] = await this.pool.query(query, params);
    return rows as WorkflowRun[];
  }

  async getRunDetails(runId: number) {
    const [runRows] = await this.pool.query<mysql.RowDataPacket[]>(
      'SELECT wr.*, w.name as workflow_name FROM workflow_runs wr ' +
        'JOIN workflows w ON wr.workflow_id = w.id WHERE wr.id = ?',
      [runId]
    );

    if (runRows.length === 0) {
      return null;
    }

    const [executions] = await this.pool.query(
      'SELECT * FROM node_executions WHERE run_id = ? ORDER BY node_order',
      [runId]
    );

    return {
      run: runRows[0],
      executions,
    };
  }

  async getExecutionStats() {
    const [stats] = await this.pool.query<mysql.RowDataPacket[]>(`
      SELECT
        COUNT(*) as total_runs,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running
      FROM workflow_runs
    `);

    return stats[0];
  }

  async getActiveWorkflowCount(): Promise<number> {
    const [rows] = await this.pool.query<mysql.RowDataPacket[]>(
      'SELECT COUNT(*) as count FROM workflows WHERE active = 1'
    );
    return rows[0].count;
  }

  async query<T = mysql.RowDataPacket[]>(
    sql: string,
    params: any[] = []
  ): Promise<T> {
    const [rows] = await this.pool.query(sql, params);
    return rows as T;
  }

  async execute(
    sql: string,
    params: any[] = []
  ): Promise<mysql.ResultSetHeader> {
    const [result] = await this.pool.execute<mysql.ResultSetHeader>(sql, params);
    return result;
  }

  async transaction<T>(
    callback: (connection: mysql.PoolConnection) => Promise<T>
  ): Promise<T> {
    const connection = await this.pool.getConnection();

    try {
      await connection.beginTransaction();
      const result = await callback(connection);
      await connection.commit();
      return result;
    } catch (error) {
      await connection.rollback();
      throw error;
    } finally {
      connection.release();
    }
  }

  async close(): Promise<void> {
    await this.pool.end();
  }
}

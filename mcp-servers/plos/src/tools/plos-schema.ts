import { z } from 'zod';
import { CONFIG } from '../config.js';
import { getSchemaCache, clearSchemaCache, formatSchema } from '../util/schema-parser.js';
import { mysqlDescribe } from '../db/mysql.js';
import { pgDescribe } from '../db/postgres.js';

export const plosSchemaInput = z.object({
  table: z.string().describe('Table name to look up'),
  database: z.enum(['mysql', 'pgsql']).optional().describe('Database to query (auto-detected from schema-reference if omitted)'),
  live: z.coerce.boolean().optional().default(false).describe('If true, query live database via DESCRIBE instead of cached schema-reference.md'),
  refresh: z.coerce.boolean().optional().default(false).describe('If true, reload schema-reference.md cache'),
});

export type PlosSchemaInput = z.infer<typeof plosSchemaInput>;

export async function plosSchema(input: PlosSchemaInput): Promise<string> {
  const { table, database, live, refresh } = input;

  if (refresh) clearSchemaCache();

  // Try cached schema-reference first (unless live requested)
  if (!live) {
    const cache = getSchemaCache(CONFIG.paths.schemaReference);
    const schema = cache.get(table);
    if (schema) {
      return formatSchema(schema);
    }

    // Not found in cache — try fuzzy match
    const fuzzy = [...cache.keys()].filter(k => k.includes(table) || table.includes(k));
    if (fuzzy.length > 0 && fuzzy.length <= 10) {
      return `Table "${table}" not found. Did you mean:\n${fuzzy.map(f => `  - ${f}`).join('\n')}`;
    }

    if (fuzzy.length > 10) {
      return `Table "${table}" not found. ${fuzzy.length} partial matches found — be more specific.`;
    }
  }

  // Live query
  try {
    const db = database ?? detectDatabase(table);
    if (db === 'pgsql') {
      const cols = await pgDescribe(table);
      if ((cols as unknown[]).length === 0) return `Table "${table}" not found in PostgreSQL.`;
      return `Table: ${table} (pgsql, LIVE)\n` +
        (cols as Array<Record<string, string>>).map(c =>
          `  ${c.column_name} (${c.data_type}${c.character_maximum_length ? `(${c.character_maximum_length})` : ''}) ${c.is_nullable === 'YES' ? 'NULL' : 'NOT NULL'}${c.column_default ? ` DEFAULT ${c.column_default}` : ''}`
        ).join('\n');
    }

    const cols = await mysqlDescribe(table);
    if ((cols as unknown[]).length === 0) return `Table "${table}" not found in MySQL.`;
    return `Table: ${table} (mysql, LIVE)\n` +
      (cols as Array<Record<string, string>>).map(c =>
        `  ${c.Field} (${c.Type}) ${c.Null === 'YES' ? 'NULL' : 'NOT NULL'}${c.Default ? ` DEFAULT ${c.Default}` : ''} ${c.Key ? `[${c.Key}]` : ''}`
      ).join('\n');
  } catch (err) {
    return `Error describing "${table}": ${(err as Error).message}`;
  }
}

/**
 * Detect which database a table belongs to based on cached schema
 */
function detectDatabase(table: string): 'mysql' | 'pgsql' {
  const cache = getSchemaCache(CONFIG.paths.schemaReference);
  const schema = cache.get(table);
  if (schema) return schema.database;

  // Known PostgreSQL prefixes
  if (/^(rag_|raptor_|claims|evidence|verdicts|research_)/.test(table)) return 'pgsql';
  return 'mysql';
}

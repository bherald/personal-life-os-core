import { readFileSync } from 'fs';
import { logger } from './logger.js';

export interface ColumnDef {
  name: string;
  type: string;
}

export interface TableSchema {
  name: string;
  database: 'mysql' | 'pgsql';
  columns: ColumnDef[];
}

let cache: Map<string, TableSchema> | null = null;

/**
 * Parse schema-reference.md into a lookup map.
 * Format: `table_name: col1 (type1), col2 (type2), ...`
 * Sections: `## MySQL Tables` and `## PostgreSQL Tables`
 */
export function parseSchemaReference(filePath: string): Map<string, TableSchema> {
  const content = readFileSync(filePath, 'utf-8');
  const lines = content.split('\n');
  const schemas = new Map<string, TableSchema>();

  let currentDb: 'mysql' | 'pgsql' = 'mysql';

  for (const line of lines) {
    if (line.startsWith('## MySQL Tables')) {
      currentDb = 'mysql';
      continue;
    }
    if (line.startsWith('## PostgreSQL Tables')) {
      currentDb = 'pgsql';
      continue;
    }

    // Match: table_name: col1 (type1), col2 (type2)
    const match = line.match(/^(\w+): (.+)$/);
    if (!match) continue;

    const [, tableName, colsRaw] = match;
    const columns: ColumnDef[] = [];

    // Split on "), " to handle types with parentheses like varchar(255)
    const colParts = colsRaw.split('), ');
    for (let i = 0; i < colParts.length; i++) {
      let part = colParts[i];
      // Add back the closing paren except for the last part which has it
      if (i < colParts.length - 1) part += ')';

      const colMatch = part.match(/^(\w+)\s+\((.+)\)$/);
      if (colMatch) {
        columns.push({ name: colMatch[1], type: colMatch[2] });
      }
    }

    schemas.set(tableName, { name: tableName, database: currentDb, columns });
  }

  logger.info('Schema parsed', { tables: schemas.size });
  return schemas;
}

export function getSchemaCache(filePath: string): Map<string, TableSchema> {
  if (!cache) {
    cache = parseSchemaReference(filePath);
  }
  return cache;
}

export function clearSchemaCache(): void {
  cache = null;
}

/**
 * Format a table schema for display
 */
export function formatSchema(schema: TableSchema): string {
  const lines = [
    `Table: ${schema.name} (${schema.database})`,
    `Columns (${schema.columns.length}):`,
  ];
  for (const col of schema.columns) {
    lines.push(`  ${col.name} (${col.type})`);
  }
  return lines.join('\n');
}

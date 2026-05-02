import { z } from 'zod';
import { mysqlQuery } from '../db/mysql.js';
import { logger } from '../util/logger.js';

export const plosConfigInput = z.object({
  action: z.enum(['get', 'set', 'list']).describe('Action to perform'),
  section: z.string().optional().describe('Config section filter'),
  key: z.string().optional().describe('Config key (required for get/set)'),
  value: z.string().optional().describe('New value (required for set)'),
  confirm_write: z.coerce.boolean().optional().default(false)
    .describe('Required for set actions. Must be true to modify live config.'),
});

export type PlosConfigInput = z.infer<typeof plosConfigInput>;

const ALLOWED_WRITE_SECTIONS = new Set([
  'agents',
  'alerts',
  'genealogy',
  'monitoring',
  'ops',
  'rag',
  'research',
  'workflow',
]);

const BLOCKED_KEY_PATTERNS = [
  /secret/i,
  /token/i,
  /password/i,
  /credential/i,
  /api[_-]?key/i,
  /private[_-]?key/i,
];

export async function plosConfig(input: PlosConfigInput): Promise<string> {
  const { action, section, key, value, confirm_write } = input;

  switch (action) {
    case 'list': {
      let where = '';
      const params: unknown[] = [];
      if (section) {
        where = 'WHERE section = ?';
        params.push(section);
      }
      const rows = await mysqlQuery(`
        SELECT section, config_key, config_value, description
        FROM system_configs
        ${where}
        ORDER BY section, config_key
        LIMIT 100
      `, params) as Array<Record<string, string>>;

      if (rows.length === 0) return 'No config entries found.';

      let currentSection = '';
      const lines: string[] = [];
      for (const r of rows) {
        if (r.section !== currentSection) {
          currentSection = r.section;
          lines.push(`\n[${currentSection}]`);
        }
        const desc = r.description ? ` # ${r.description}` : '';
        lines.push(`  ${r.config_key} = ${r.config_value}${desc}`);
      }
      return lines.join('\n').trim();
    }

    case 'get': {
      if (!key) return 'ERROR: key is required for get action';
      let where = 'WHERE config_key = ?';
      const params: unknown[] = [key];
      if (section) {
        where += ' AND section = ?';
        params.push(section);
      }
      const rows = await mysqlQuery(`
        SELECT section, config_key, config_value, description
        FROM system_configs ${where} LIMIT 5
      `, params) as Array<Record<string, string>>;

      if (rows.length === 0) return `Config key "${key}" not found.`;
      return rows.map(r => `[${r.section}] ${r.config_key} = ${r.config_value}${r.description ? ` (${r.description})` : ''}`).join('\n');
    }

    case 'set': {
      if (!key) return 'ERROR: key is required for set action';
      if (value === undefined) return 'ERROR: value is required for set action';
      if (!section) return 'ERROR: section is required for set action';
      if (!confirm_write) return 'BLOCKED: set requires confirm_write=true';
      if (!ALLOWED_WRITE_SECTIONS.has(section)) {
        return `BLOCKED: section "${section}" is not writable via plos_config`;
      }
      if (BLOCKED_KEY_PATTERNS.some((pattern) => pattern.test(key))) {
        return `BLOCKED: key "${key}" is not writable via plos_config`;
      }

      const existingRows = await mysqlQuery(`
        SELECT config_value
        FROM system_configs
        WHERE section = ? AND config_key = ?
        LIMIT 2
      `, [section, key]) as Array<Record<string, string>>;

      if (existingRows.length === 0) return `ERROR: config key [${section}] ${key} not found`;
      if (existingRows.length > 1) return `ERROR: config key [${section}] ${key} is not unique`;

      await mysqlQuery(`
        UPDATE system_configs SET config_value = ?, updated_at = NOW()
        WHERE section = ? AND config_key = ?
      `, [value, section, key]);

      logger.info('Config updated', { section, key, value });
      return `Updated [${section}] ${key}: ${existingRows[0].config_value} -> ${value}`;
    }
  }
}

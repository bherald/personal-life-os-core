import { config } from 'dotenv';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(__dirname, '..', '..', '..');
const dotenvMode = process.env.PLOS_MCP_DOTENV_MODE ?? 'default';
if (dotenvMode !== 'skip') {
  config({ path: resolve(__dirname, '..', '.env') });
  config({ path: resolve(__dirname, '..', '..', '..', '.env') });

  const privateEnvPath = process.env.PLOS_MCP_ENV_FILE
    ?? (process.env.HOME ? resolve(process.env.HOME, '.config', 'plos', 'mcp-plos.env') : '');

  if (privateEnvPath !== '') {
    config({ path: privateEnvPath, override: true });
  }
}

function env(key: string, fallback?: string): string {
  const val = process.env[key] ?? fallback;
  if (val === undefined) throw new Error(`Missing env: ${key}`);
  return val;
}

function envAny(keys: string[], fallback?: string): string {
  for (const key of keys) {
    const val = process.env[key];
    if (val !== undefined) return val;
  }
  if (fallback !== undefined) return fallback;
  throw new Error(`Missing env: ${keys.join('|')}`);
}

export const CONFIG = {
  ssh: {
    host: envAny(['PLOS_REMOTE_HOST', 'REMOTE_SSH_HOST', 'PROD_SSH_HOST'], '127.0.0.1'),
    user: envAny(['PLOS_REMOTE_USER', 'REMOTE_SSH_USER', 'PROD_SSH_USER'], process.env.USER ?? 'plos'),
    keyPath: envAny(['PLOS_REMOTE_SSH_KEY', 'REMOTE_SSH_KEY', 'PROD_SSH_KEY'], process.env.SSH_KEY_PATH ?? ''),
    strictHostKeyChecking: env('SSH_STRICT_HOST_KEY_CHECKING', 'accept-new'),
  },
  mysql: {
    host: envAny(['MYSQL_HOST', 'DB_HOST'], '127.0.0.1'),
    port: parseInt(envAny(['MYSQL_PORT', 'DB_PORT'], '3306')),
    user: envAny(['MYSQL_USER', 'DB_USERNAME'], 'plos'),
    password: envAny(['MYSQL_PASSWORD', 'DB_PASSWORD'], ''),
    database: envAny(['MYSQL_DATABASE', 'DB_DATABASE'], 'plos'),
  },
  pgsql: {
    host: envAny(['PGSQL_HOST', 'RAG_DB_HOST'], '127.0.0.1'),
    port: parseInt(envAny(['PGSQL_PORT', 'RAG_DB_PORT'], '5432')),
    user: envAny(['PGSQL_USER', 'RAG_DB_USERNAME'], 'plos_rag'),
    password: envAny(['PGSQL_PASSWORD', 'RAG_DB_PASSWORD'], ''),
    database: envAny(['PGSQL_DATABASE', 'RAG_DB_DATABASE'], 'plos_rag'),
  },
  ollama: {
    host: env('OLLAMA_HOST', 'http://127.0.0.1:11434'),
    discoverTimeoutMs: 10_000,   // model list fetch
    generateTimeoutMs: 60_000,   // LLM generation (most models finish in 30-40s)
  },
  paths: {
    schemaReference: env('SCHEMA_REFERENCE_PATH', resolve(repoRoot, 'docs/schema-reference.md')),
    projectRoot: env('PROJECT_ROOT', repoRoot),
    remoteRoot: envAny(['PLOS_REMOTE_PROJECT_ROOT', 'REMOTE_PROJECT_ROOT', 'PROD_PROJECT_ROOT'], env('PROJECT_ROOT', repoRoot)),
  },
  // Query safety limits
  query: {
    maxRows: 500,
    timeoutMs: 30_000,       // connect + query timeout for DB calls
  },
  // Global tool-level timeout — prevents any single tool from blocking MCP forever
  toolTimeoutMs: 90_000,
  // Token-gated elapsed-time heartbeat. No percentage or payload content is emitted.
  toolProgressHeartbeatMs: 15_000,
} as const;

export function shellQuote(value: string): string {
  return `'${value.replace(/'/g, `'\\''`)}'`;
}

/** SSH command prefix with optional configured key and no interactive prompts */
export function sshCmd(): string {
  const keyArg = CONFIG.ssh.keyPath === '' ? '' : ` -i ${shellQuote(CONFIG.ssh.keyPath)}`;

  return `ssh${keyArg} -o BatchMode=yes -o StrictHostKeyChecking=${CONFIG.ssh.strictHostKeyChecking} ${shellQuote(`${CONFIG.ssh.user}@${CONFIG.ssh.host}`)}`;
}

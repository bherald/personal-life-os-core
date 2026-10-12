import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
import { tmpdir } from 'node:os';

const ENV_KEYS = [
  'HOME',
  'DB_HOST',
  'DB_PORT',
  'DB_USERNAME',
  'DB_PASSWORD',
  'DB_DATABASE',
  'MYSQL_HOST',
  'MYSQL_PORT',
  'MYSQL_USER',
  'MYSQL_PASSWORD',
  'MYSQL_DATABASE',
  'RAG_DB_HOST',
  'RAG_DB_PORT',
  'RAG_DB_USERNAME',
  'RAG_DB_PASSWORD',
  'RAG_DB_DATABASE',
  'PGSQL_HOST',
  'PGSQL_PORT',
  'PGSQL_USER',
  'PGSQL_PASSWORD',
  'PGSQL_DATABASE',
  'PLOS_REMOTE_HOST',
  'PLOS_REMOTE_USER',
  'PLOS_REMOTE_SSH_KEY',
  'PLOS_REMOTE_PROJECT_ROOT',
  'PLOS_MCP_ENV_FILE',
  'REMOTE_SSH_HOST',
  'REMOTE_SSH_USER',
  'REMOTE_SSH_KEY',
  'REMOTE_PROJECT_ROOT',
  'PROD_SSH_HOST',
  'PROD_SSH_USER',
  'PROD_SSH_KEY',
  'PROD_PROJECT_ROOT',
  'SSH_KEY_PATH',
  'PROJECT_ROOT',
  'PLOS_MCP_DOTENV_MODE',
];

async function withEnv<T>(
  values: Record<string, string | undefined>,
  callback: () => Promise<T>,
  dotenvMode = 'skip',
): Promise<T> {
  const previous = new Map<string, string | undefined>();
  for (const key of ENV_KEYS) {
    previous.set(key, process.env[key]);
    delete process.env[key];
  }

  for (const [key, value] of Object.entries(values)) {
    if (value === undefined) {
      delete process.env[key];
    } else {
      process.env[key] = value;
    }
  }

  try {
    process.env.PLOS_MCP_DOTENV_MODE = dotenvMode;

    return await callback();
  } finally {
    for (const key of ENV_KEYS) {
      const value = previous.get(key);
      if (value === undefined) {
        delete process.env[key];
      } else {
        process.env[key] = value;
      }
    }
  }
}

async function freshConfig(caseName: string) {
  return import(`../config.js?case=${caseName}-${Date.now()}-${Math.random()}`);
}

test('neutral remote env names win over legacy prod names', async () => {
  await withEnv({
    PLOS_REMOTE_HOST: 'remote.example.test',
    PLOS_REMOTE_USER: 'remote-user',
    PLOS_REMOTE_SSH_KEY: '/tmp/remote-key',
    PLOS_REMOTE_PROJECT_ROOT: '/srv/plos-remote',
    PROD_SSH_HOST: 'legacy.example.test',
    PROD_SSH_USER: 'legacy-user',
    PROD_SSH_KEY: '/tmp/legacy-key',
    PROD_PROJECT_ROOT: '/srv/plos-legacy',
    PROJECT_ROOT: '/srv/plos-local',
  }, async () => {
    const { CONFIG } = await freshConfig('neutral-wins');

    assert.equal(CONFIG.ssh.host, 'remote.example.test');
    assert.equal(CONFIG.ssh.user, 'remote-user');
    assert.equal(CONFIG.ssh.keyPath, '/tmp/remote-key');
    assert.equal(CONFIG.paths.remoteRoot, '/srv/plos-remote');
  });
});

test('private env file can override repo dotenv values', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'plos-mcp-env-'));
  const privateEnv = join(dir, 'mcp-plos.env');

  writeFileSync(privateEnv, [
    'PLOS_REMOTE_HOST=private.example.test',
    'PLOS_REMOTE_USER=private-user',
    'PLOS_REMOTE_SSH_KEY=/tmp/private-key',
    'PLOS_REMOTE_PROJECT_ROOT=/srv/private-plos',
    'MYSQL_HOST=private-mysql',
    '',
  ].join('\n'));

  try {
    await withEnv({
      PLOS_MCP_ENV_FILE: privateEnv,
      PLOS_REMOTE_HOST: 'process.example.test',
      PLOS_REMOTE_USER: 'process-user',
      PLOS_REMOTE_SSH_KEY: '/tmp/process-key',
      PLOS_REMOTE_PROJECT_ROOT: '/srv/process-plos',
      MYSQL_HOST: 'process-mysql',
      PROJECT_ROOT: '/srv/plos-local',
    }, async () => {
      const { CONFIG } = await freshConfig('private-env-override');

      assert.equal(CONFIG.ssh.host, 'private.example.test');
      assert.equal(CONFIG.ssh.user, 'private-user');
      assert.equal(CONFIG.ssh.keyPath, '/tmp/private-key');
      assert.equal(CONFIG.paths.remoteRoot, '/srv/private-plos');
      assert.equal(CONFIG.mysql.host, 'private-mysql');
    }, 'default');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('legacy prod env names remain accepted as fallback aliases', async () => {
  await withEnv({
    PROD_SSH_HOST: 'legacy.example.test',
    PROD_SSH_USER: 'legacy-user',
    PROD_SSH_KEY: '/tmp/legacy-key',
    PROD_PROJECT_ROOT: '/srv/plos-legacy',
    PROJECT_ROOT: '/srv/plos-local',
  }, async () => {
    const { CONFIG } = await freshConfig('legacy-fallback');

    assert.equal(CONFIG.ssh.host, 'legacy.example.test');
    assert.equal(CONFIG.ssh.user, 'legacy-user');
    assert.equal(CONFIG.ssh.keyPath, '/tmp/legacy-key');
    assert.equal(CONFIG.paths.remoteRoot, '/srv/plos-legacy');
  });
});

test('Laravel database env names provide production-checkout fallbacks', async () => {
  await withEnv({
    DB_HOST: 'laravel-mysql',
    DB_PORT: '3307',
    DB_USERNAME: 'laravel-user',
    DB_PASSWORD: 'test-laravel-password',
    DB_DATABASE: 'laravel-database',
    RAG_DB_HOST: 'laravel-pgsql',
    RAG_DB_PORT: '5433',
    RAG_DB_USERNAME: 'rag-user',
    RAG_DB_PASSWORD: 'test-rag-password',
    RAG_DB_DATABASE: 'rag-database',
  }, async () => {
    const { CONFIG } = await freshConfig('laravel-db-fallbacks');

    assert.deepEqual(CONFIG.mysql, {
      host: 'laravel-mysql',
      port: 3307,
      user: 'laravel-user',
      password: 'test-laravel-password',
      database: 'laravel-database',
    });
    assert.deepEqual(CONFIG.pgsql, {
      host: 'laravel-pgsql',
      port: 5433,
      user: 'rag-user',
      password: 'test-rag-password',
      database: 'rag-database',
    });
  });
});

test('MCP-specific database env names win over Laravel aliases', async () => {
  await withEnv({
    DB_HOST: 'laravel-mysql',
    DB_PASSWORD: 'test-laravel-password',
    MYSQL_HOST: 'mcp-mysql',
    MYSQL_PASSWORD: 'test-mcp-password',
    RAG_DB_HOST: 'laravel-pgsql',
    PGSQL_HOST: 'mcp-pgsql',
  }, async () => {
    const { CONFIG } = await freshConfig('mcp-db-precedence');

    assert.equal(CONFIG.mysql.host, 'mcp-mysql');
    assert.equal(CONFIG.mysql.password, 'test-mcp-password');
    assert.equal(CONFIG.pgsql.host, 'mcp-pgsql');
  });
});

test('sshCmd omits optional key argument unless a key path is configured', async () => {
  await withEnv({
    PLOS_REMOTE_HOST: 'remote.example.test',
    PLOS_REMOTE_USER: 'remote-user',
    PLOS_REMOTE_SSH_KEY: '',
  }, async () => {
    const { sshCmd } = await freshConfig('no-key');

    assert.doesNotMatch(sshCmd(), / -i /);
  });

  await withEnv({
    PLOS_REMOTE_HOST: 'remote.example.test',
    PLOS_REMOTE_USER: 'remote-user',
    PLOS_REMOTE_SSH_KEY: '/tmp/key with spaces',
  }, async () => {
    const { sshCmd } = await freshConfig('with-key');

    assert.match(sshCmd(), / -i '\/tmp\/key with spaces' /);
  });
});

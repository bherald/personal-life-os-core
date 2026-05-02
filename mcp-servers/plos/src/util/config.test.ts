import assert from 'node:assert/strict';
import test from 'node:test';

const ENV_KEYS = [
  'PLOS_REMOTE_HOST',
  'PLOS_REMOTE_USER',
  'PLOS_REMOTE_SSH_KEY',
  'PLOS_REMOTE_PROJECT_ROOT',
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

async function withEnv<T>(values: Record<string, string | undefined>, callback: () => Promise<T>): Promise<T> {
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
    process.env.PLOS_MCP_DOTENV_MODE = 'skip';

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

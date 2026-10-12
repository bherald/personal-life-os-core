import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import test from 'node:test';

const root = process.env.FIXTURE_SOURCE_ROOT ?? resolve(fileURLToPath(new URL('../../../../', import.meta.url)));

function fixture(): { directory: string; env: NodeJS.ProcessEnv; read: (name: string) => string[] } {
  const directory = mkdtempSync(join(tmpdir(), 'plos-cache-custody-'));
  writeFileSync(join(directory, 'ssh'), `#!/usr/bin/env node
const {appendFileSync}=require('node:fs');const {spawnSync}=require('node:child_process');
const command=process.argv.at(-1);appendFileSync(process.env.FIXTURE_SSH_LOG,command+'\\n');
const result=spawnSync('/bin/sh',['-c',command],{env:process.env,encoding:'utf8'});
process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');process.exit(result.status??1);
`, { mode: 0o755 });
  writeFileSync(join(directory, 'php'), `#!/usr/bin/env node
const {appendFileSync}=require('node:fs');const args=process.argv.slice(2);
appendFileSync(process.env.FIXTURE_ARTISAN_LOG,args.join(' ')+'\\n');
if(args[0]!=='artisan')process.exit(98);if(args[1]===process.env.FIXTURE_FAIL_COMMAND)process.exit(42);
if(args[1]==='ops:release-cache-invalidate')process.stdout.write(JSON.stringify({schema:'plos.release_cache_invalidation.v1',success:true,reason_code:null})+'\\n');
else process.stdout.write('fixed fixture success\\n');
`, { mode: 0o755 });
  writeFileSync(join(directory, 'redis-cli'), `#!/usr/bin/env node
const {appendFileSync}=require('node:fs');appendFileSync(process.env.FIXTURE_REDIS_LOG,process.argv.slice(2).join(' ')+'\\n');process.stdout.write('fixed Redis read fixture\\n');
`, { mode: 0o755 });
  writeFileSync(join(directory, 'chown'), '#!/bin/sh\nexit 0\n', { mode: 0o755 });
  const env = { ...process.env, PLOS_MCP_DOTENV_MODE: 'skip', PATH: `${directory}:${process.env.PATH}`, PLOS_REMOTE_PROJECT_ROOT: directory,
    PLOS_REMOTE_HOST: 'fixture.invalid', PLOS_REMOTE_USER: 'fixture', PLOS_REMOTE_SSH_KEY: '', LOG_FILE: join(directory, 'tool.log'),
    FIXTURE_SSH_LOG: join(directory, 'ssh.log'), FIXTURE_ARTISAN_LOG: join(directory, 'artisan.log'), FIXTURE_REDIS_LOG: join(directory, 'redis.log'), FIXTURE_FAIL_COMMAND: '' };
  return { directory, env, read: name => existsSync(join(directory, name)) ? readFileSync(join(directory, name), 'utf8').trim().split('\n') : [] };
}

function invoke(kind: 'cache' | 'ssh', input: Record<string, unknown>, failCommand = ''): { result: string; calls: string[]; remote: string[]; redis: string[] } {
  const f = fixture();
  try {
    const name = kind === 'cache' ? 'plos-cache-clear' : 'plos-ssh';
    const functionName = kind === 'cache' ? 'plosCacheClear' : 'plosSsh';
    const schemaName = kind === 'cache' ? 'plosCacheClearInput' : 'plosSshInput';
    const moduleUrl = new URL(`./${name}.js`, import.meta.url).href;
    const script = `const m=await import(${JSON.stringify(moduleUrl)});process.stdout.write(JSON.stringify(await m.${functionName}(m.${schemaName}.parse(${JSON.stringify(input)}))));`;
    const result = JSON.parse(execFileSync(process.execPath, ['--input-type=module', '-e', script], { encoding: 'utf8', env: { ...f.env, FIXTURE_FAIL_COMMAND: failCommand } }));
    return { result, calls: f.read('artisan.log'), remote: f.read('ssh.log'), redis: f.read('redis.log') };
  } finally { rmSync(f.directory, { recursive: true, force: true }); }
}

test('default MCP execution invalidates only named release snapshots and file caches', () => {
  const observed = invoke('cache', {});
  assert.deepEqual(observed.calls, ['artisan ops:release-cache-invalidate --json', 'artisan config:clear', 'artisan route:clear', 'artisan view:clear', 'artisan event:clear'], observed.result);
  for (const forbidden of ['cache:clear', 'optimize:clear', 'redis-cli', 'FLUSHDB', 'FLUSHALL', 'horizon:terminate', 'queue:restart']) assert.ok(!observed.remote.join(' ').includes(forbidden));
  assert.ok(observed.result.includes('Scoped cache invalidation complete (5 steps)'));
});

test('legacy Redis flush is held before any calls even when confirmed or combined with worker restart', () => {
  for (const confirm_disruptive of [false, true]) {
    const observed = invoke('cache', { flush_redis: true, confirm_disruptive, restart_workers: true });
    assert.equal(observed.result, 'HELD: global_redis_flush_not_permitted');
    assert.deepEqual(observed.calls, []); assert.deepEqual(observed.remote, []);
  }
});

test('failed scoped invalidation halts every subsequent cleanup/restart without an OK claim', () => {
  const observed = invoke('cache', { restart_workers: true, confirm_disruptive: true }, 'ops:release-cache-invalidate');
  assert.deepEqual(observed.calls, ['artisan ops:release-cache-invalidate --json']);
  assert.match(observed.result, /remaining steps held/); assert.ok(!observed.result.includes('[OK]'));
});

test('failed config invalidation halts remaining file caches and worker restart', () => {
  const observed = invoke('cache', { restart_workers: true, confirm_disruptive: true }, 'config:clear');
  assert.deepEqual(observed.calls, ['artisan ops:release-cache-invalidate --json', 'artisan config:clear']);
  assert.match(observed.result, /remaining steps held/); assert.ok(!observed.result.includes('[OK]') && !observed.result.includes('complete'));
});

test('worker restart still requires confirmation and follows successful invalidation', () => {
  const denied = invoke('cache', { restart_workers: true });
  assert.match(denied.result, /^BLOCKED:/); assert.deepEqual(denied.remote, []);
  const observed = invoke('cache', { restart_workers: true, confirm_disruptive: true });
  assert.deepEqual(observed.calls, ['artisan ops:release-cache-invalidate --json', 'artisan config:clear', 'artisan route:clear', 'artisan view:clear', 'artisan event:clear', 'artisan horizon:terminate', 'artisan queue:restart']);
  assert.ok(observed.result.includes('Scoped cache invalidation complete (7 steps)'));
});

test('direct SSH global Laravel/Redis flush variants are held before any SSH invocation', () => {
  for (const command of ['php artisan cache:clear', 'php artisan --env=production optimize:clear',
    '/usr/bin/php8.3 -d memory_limit=512M /srv/app/artisan CACHE:CLEAR --store=redis',
    'redis-cli FLUSHDB', 'redis-cli -h 127.0.0.1 -p 6379 -n 0 flushall ASYNC',
    "redis-cli --raw 'FLUSHDB'", 'redis-cli --cluster call fixture:6379 FLUSHALL',
    'true && redis-cli --tls --user fixture --pass synthetic-only FLUSHDB',
    'true; PHP ARTISAN OPTIMIZE:CLEAR']) {
    const observed = invoke('ssh', { command });
    assert.ok(observed.result.startsWith('[HELD] global_cache_flush_not_permitted'));
    assert.deepEqual(observed.remote, []); assert.deepEqual(observed.redis, []); assert.deepEqual(observed.calls, []);
  }
});

test('normal SSH Redis read/status remains permitted including a key named FLUSHDB', () => {
  for (const command of ['redis-cli PING', 'redis-cli --raw INFO', 'redis-cli -h fixture GET custody-key', 'redis-cli --raw GET FLUSHDB']) {
    const observed = invoke('ssh', { command });
    assert.equal(observed.remote.length, 1); assert.equal(observed.redis.length, 1);
    assert.ok(!observed.result.startsWith('[HELD]') && !observed.result.startsWith('[BLOCKED]'));
  }
});

test('app-update uses scoped invalidation and stops before migration if it is unavailable', { skip: !existsSync(join(root, 'dev-ops/scripts/app-update.sh')) }, () => {
  const f = fixture();
  try {
    mkdirSync(join(f.directory, 'storage/framework'), { recursive: true });
    writeFileSync(join(f.directory, 'storage/framework/down'), 'fixture maintenance marker');
    const result = spawnSync('/bin/bash', [join(root, 'dev-ops/scripts/app-update.sh'), '--skip-composer', '--skip-npm', '--skip-python', '--skip-tools'], {
      encoding: 'utf8', env: { ...f.env, PLOS_APP_DIR: f.directory, PLOS_APP_OWNER: 'fixture', PLOS_APP_GROUP: 'fixture', FIXTURE_FAIL_COMMAND: 'ops:release-cache-invalidate' },
    });
    assert.equal(result.status, 42, result.stderr);
    assert.deepEqual(f.read('artisan.log'), ['artisan config:clear', 'artisan route:clear', 'artisan view:clear', 'artisan ops:release-cache-invalidate --json']);
  } finally { rmSync(f.directory, { recursive: true, force: true }); }
});

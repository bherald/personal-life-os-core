import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import { root } from './frontend-build.mjs';
import { checkRuntime } from './runtime-check.mjs';

const execute = promisify(execFile);

test('read-only PHP probe reports real interpreter components without starting the app', async () => {
    const result = await checkRuntime();
    assert.equal(result.php.available, true);
    assert.equal(result.php.dependenciesAvailable, true);
    assert.deepEqual(result.php.missingExtensions, []);
    assert.equal(result.php.ready, true);
    assert.equal(result.bridgeAvailable, true);
    assert.equal(result.ready, result.node.supported && result.platformQualified);
});

test('disabled PHP extensions are named without creating a database', async () => {
    const { stdout } = await execute('php', ['-n', path.join(root, 'scripts/plos-cli/check-runtime.php')]);
    const report = JSON.parse(stdout);
    assert.equal(report.ready, false);
    assert.ok(report.missingExtensions.includes('pdo_sqlite'));
    assert.ok(report.missingExtensions.includes('fileinfo'));
});

test('an unavailable selected interpreter produces a bounded sanitized report', async () => {
    const result = await checkRuntime(path.join(root, 'no-such-php-interpreter'));
    assert.deepEqual(result.php, { available: false, ready: false });
    assert.equal(result.ready, false);
});

test('missing or directory PHP configuration cannot silently use the default configuration', async () => {
    for (const filename of [path.join(root, 'missing-php.ini'), root]) {
        const result = await checkRuntime('php', filename);
        assert.equal(result.php.available, false);
        assert.equal(result.ready, false);
    }
    const doctor = await execute(process.execPath, [path.join(root, 'scripts/plos-cli/launch.mjs'),
        '--doctor', '--php-ini', '']).catch(error => error);
    assert.equal(doctor.code, 1);
    assert.equal(JSON.parse(doctor.stdout).runtime.php.available, false);
});

test('doctor reports incomplete prerequisites and launch refuses before state or sync', async t => {
    const directory = await mkdtemp(path.join(tmpdir(), 'plos-runtime-test-'));
    t.after(() => rm(directory, { recursive: true, force: true }));
    const state = path.join(directory, 'untouched-state');
    const args = [path.join(root, 'scripts/plos-cli/launch.mjs'), '--state-dir', state,
        '--php', path.join(directory, 'missing-php'), '--sync-source', 'must-not-run'];
    const doctor = await execute(process.execPath, [...args, '--doctor']).catch(error => error);
    assert.equal(doctor.code, 1);
    const report = JSON.parse(doctor.stdout);
    assert.equal(report.runtime.php.available, false);
    assert.equal(report.launchReady, false);
    assert.equal(report.primaryAgentAdmitted, false);
    const launch = await execute(process.execPath, args).catch(error => error);
    assert.equal(launch.code, 1);
    assert.match(launch.stderr, /runtime prerequisites are incomplete/);
    assert.doesNotMatch(launch.stderr, /checking the permitted registry source/);
    await assert.rejects(stat(state), { code: 'ENOENT' });
});

test('an explicit interpreter path preserves arguments and its diagnostics stay private',
    { skip: process.platform === 'win32' }, async t => {
        const directory = await mkdtemp(path.join(tmpdir(), 'plos-runtime-path-'));
        t.after(() => rm(directory, { recursive: true, force: true }));
        const interpreter = path.join(directory, 'php space ; interpreter');
        const argument = path.join(directory, 'argument');
        const escaped = argument.replaceAll("'", "'\\''");
        await writeFile(interpreter, `#!/bin/sh\nprintf '%s' "$1" > '${escaped}'\necho 'PRIVATE_DIAGNOSTIC' >&2\nexit 1\n`, { mode: 0o700 });
        const result = await checkRuntime(interpreter);
        assert.equal(await readFile(argument, 'utf8'), path.join(root, 'scripts/plos-cli/check-runtime.php'));
        assert.equal(result.php.available, false);
        assert.doesNotMatch(JSON.stringify(result), /PRIVATE_DIAGNOSTIC/);
    });


test('selected SSH configuration refuses unavailable files before state or source access', async t => {
    const directory = await mkdtemp(path.join(tmpdir(), 'plos-ssh-selection-'));
    t.after(() => rm(directory, { recursive: true, force: true }));
    const state = path.join(directory, 'untouched');
    for (const selected of ['', directory, path.join(directory, 'missing')]) {
        const launch = await execute(process.execPath, [path.join(root, 'scripts/plos-cli/launch.mjs'),
            '--state-dir', state, '--sync-source', 'must-not-run', '--sync-ssh-config', selected]).catch(error => error);
        assert.equal(launch.code, 1);
        assert.match(launch.stderr, /Selected SSH configuration is unavailable/);
        assert.doesNotMatch(launch.stderr, /checking the permitted registry source/);
        await assert.rejects(stat(state), { code: 'ENOENT' });
    }
    const config = path.join(directory, 'central ssh ; config');
    await writeFile(config, 'Host synthetic\n HostName 10.23.45.10\n');
    const result = await execute(process.execPath, [path.join(root, 'scripts/plos-cli/launch.mjs'),
        '--doctor', '--php', path.join(directory, 'missing-php'), '--sync-ssh-config', config]).catch(error => error);
    assert.equal(JSON.parse(result.stdout).syncSshConfig, config);
    await assert.rejects(stat(state), { code: 'ENOENT' });
});

test('selected PHP scan directory controls the actual prerequisite probe', { skip: process.platform !== 'linux' }, async t => {
    const directory = await mkdtemp(path.join(tmpdir(), 'plos-php-scan-'));
    t.after(() => rm(directory, { recursive: true, force: true }));
    const result = await checkRuntime('php', null, { ...process.env, PHP_INI_SCAN_DIR: directory });
    assert.equal(result.php.available, true);
    assert.equal(result.php.ready, false);
    assert.ok(result.php.missingExtensions.includes('pdo_sqlite'));
    const restored = await checkRuntime();
    assert.equal(restored.php.ready, true);
});

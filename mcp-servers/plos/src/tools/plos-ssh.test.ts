import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
import { tmpdir } from 'node:os';

import { buildSshCommand, plosSsh } from './plos-ssh.js';

test('remote command is one literal SSH argument, including shell syntax and newlines', () => {
  const directory = mkdtempSync(join(tmpdir(), 'plos-ssh-argv-'));

  try {
    const fakeSsh = join(directory, 'ssh');
    writeFileSync(fakeSsh, '#!/usr/bin/env node\nprocess.stdout.write(JSON.stringify(process.argv.slice(2)));\n', { mode: 0o755 });

    const remoteCommand = `php -r '$value = "$HOME"; echo $(printf sub); echo \`printf tick\`; echo \'quoted\';'\nprintf next-line`;
    const shellCommand = buildSshCommand(remoteCommand, '/srv/project', 'ssh test@example.test');
    const result = execFileSync('/bin/sh', ['-c', shellCommand], {
      encoding: 'utf8',
      env: { ...process.env, PATH: `${directory}:${process.env.PATH ?? ''}` },
    });

    assert.deepEqual(JSON.parse(result), [
      'test@example.test',
      `cd -- '/srv/project' && ${remoteCommand}`,
    ]);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('sudo remains blocked before any SSH command is built or executed', async () => {
  const result = await plosSsh({ command: 'sudo systemctl start laravel-horizon.service', timeout: 1 });

  assert.match(result, /^\[BLOCKED\] Command matches dangerous pattern:/);
});

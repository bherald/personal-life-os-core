#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { access, lstat, realpath } from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { readHostProfile, launcherValueOptions } from './host-profile.mjs';

if (process.argv.slice(2).includes('--help')) await import('./launch.mjs');

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const forwarded = [];
const args = process.argv.slice(2);
let setupFrom = null;
let explicitProfile = false;
let selectedNode = process.env.PLOS_CLI_NODE ?? null;
let selectedProfile = process.env.PLOS_CLI_HOST_PROFILE ?? null;
while (args.length) {
    const argument = args.shift();
    if (argument === '--node' || argument === '--host-profile' || argument === '--setup-from') {
        if (!args.length || !args[0]) throw new Error(`Missing value for ${argument}.`);
        if (argument === '--node') selectedNode = args.shift();
        else if (argument === '--setup-from') setupFrom = args.shift();
        else { selectedProfile = args.shift(); explicitProfile = true; }
    } else {
        forwarded.push(argument);
        if (argument.startsWith('--') && launcherValueOptions.includes(argument.slice(2))) {
            if (!args.length) throw new Error(`Missing value for ${argument}.`);
            forwarded.push(args.shift());
        }
    }
}
if (setupFrom !== null) {
    if (explicitProfile) throw new Error('--setup-from and --host-profile cannot be combined.');
    selectedProfile = setupFrom;
    if (forwarded.length !== 0 && (forwarded.length !== 2 || forwarded[0] !== '--frontend')) {
        throw new Error('--setup-from accepts only --node and --frontend overrides.');
    }
}
if (selectedProfile === null) {
    const local = path.join(root, 'storage/app/private/plos-cli/host-profile.json');
    if (await lstat(local).catch(() => null)) selectedProfile = local;
}
const profile = selectedProfile === null ? null : await readHostProfile(selectedProfile);
const node = selectedNode ?? profile?.node ?? process.execPath;
const executable = await realpath(node).catch(() => null);
if (executable === null || !(await lstat(executable)).isFile()) throw new Error('Selected Node executable is unavailable; no fallback attempted.');
await access(executable, constants.X_OK);
const env = { ...process.env };
delete env.PLOS_CLI_HOST_PROFILE_SHA256;
if (profile) {
    if (setupFrom === null) forwarded.push('--host-profile', profile.filename);
    else forwarded.push('--from', profile.filename);
    env.PLOS_CLI_HOST_PROFILE_SHA256 = profile.sha256;
}
const child = spawn(executable, [path.join(root, setupFrom === null ? 'scripts/plos-cli/launch.mjs' : 'scripts/plos-cli/adopt-profile.mjs'), ...forwarded],
    { stdio: 'inherit', shell: false, env });
child.once('error', () => { process.stderr.write('Selected Node runtime could not start.\n'); process.exitCode = 1; });
child.once('exit', code => { process.exitCode = code ?? 1; });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal));

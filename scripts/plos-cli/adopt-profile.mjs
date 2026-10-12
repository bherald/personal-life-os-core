#!/usr/bin/env node
import { writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { readHostProfile, hostEnvironment } from './host-profile.mjs';
import { prepareStateDirectory } from './state-directory.mjs';
import { verifyFrontend } from './frontend-build.mjs';
import { checkRuntime } from './runtime-check.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

export function releaseProfile(source, frontend, node) {
    if (!source.options['state-dir']) throw new Error('The source profile must explicitly select its state directory.');
    return { schema: 'plos.cli.host-profile.v1', node: path.resolve(node),
        options: { ...source.options, frontend: path.resolve(frontend) }, bindings: { ...source.bindings },
        ...(source.sshTunnel ? { sshTunnel: { ...source.sshTunnel } } : {}) };
}

export async function adoptProfile(sourceFile, { frontend = path.join(root, 'frontend', process.platform === 'win32' ? 'plos-codex.exe' : 'plos-codex'),
    destination = path.join(root, 'storage/app/private/plos-cli/host-profile.json'),
    verify = verifyFrontend, runtimeCheck = checkRuntime } = {}) {
    const source = await readHostProfile(sourceFile, process.env.PLOS_CLI_HOST_PROFILE_SHA256 ?? null);
    const document = releaseProfile(source, frontend, process.execPath);
    const build = await verify(document.options.frontend);
    const runtime = await runtimeCheck(document.options.php ?? 'php', document.options['php-ini'] ?? null);
    if (!runtime.ready) throw new Error('Selected host runtime prerequisites are incomplete; the new profile was not written.');
    // Copy resolved host paths, not private state or credentials. Source settings stay unchanged.
    await readHostProfile(source.filename, source.sha256);
    const directory = await prepareStateDirectory(path.dirname(path.resolve(destination)));
    const output = path.join(directory, path.basename(destination));
    await writeFile(output, JSON.stringify(document, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
    const saved = await readHostProfile(output);
    return { configured: true, hostProfile: saved.filename, hostProfileSha256: saved.sha256,
        frontend: saved.options.frontend, frontendSha256: build.sha256,
        stateDirectory: saved.options['state-dir'], sourceProfileUnchanged: true,
        stateCopied: false, activated: false, primaryAgentAdmitted: false };
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    const args = process.argv.slice(2);
    const options = {};
    while (args.length) {
        const flag = args.shift();
        if (!['--from', '--frontend'].includes(flag) || !args.length || !args[0]) {
            throw new Error('Use --from FILE with optional --frontend PATH.');
        }
        options[flag.slice(2)] = args.shift();
    }
    if (!options.from) throw new Error('--from FILE is required.');
    console.log(JSON.stringify(await adoptProfile(options.from, { frontend: options.frontend }), null, 2));
}

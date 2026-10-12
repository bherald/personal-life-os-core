#!/usr/bin/env node
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { randomBytes } from 'node:crypto';
import { realpath, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { verifyFrontend } from './frontend-build.mjs';
import { checkRuntime, phpArguments } from './runtime-check.mjs';
import { prepareStateDirectory } from './state-directory.mjs';
import { resolveSyncPython } from './sync-runtime.mjs';
import { frontendArguments, resumeArguments } from './frontend-arguments.mjs';
import { readHostProfile, hostEnvironment, hostBindingNames } from './host-profile.mjs';
import { isRecoveryInput, scriptedInput, scriptedOverride, runScripted } from './scripted.mjs';
import { startSshTunnel } from './ssh-tunnel.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2);
if (args.includes('--help')) {
    console.log('Scripted tasks: ./offline exec --use-saved-defaults --input FILE|- [--input-json FILE|- instead] [--set KEY=JSON] [--json] [--resume SESSION_UUID]. Uses the same PLOS pipeline and approvals; no unattended approval or automatic action replay.');
    console.log('Usage: ./offline [--node PATH] [--host-profile FILE] [--host-binding NAME=PATH] [--workspace PATH] [--state-dir PATH] [--frontend PATH] [--native-codex PATH] [--php EXECUTABLE] [--php-ini FILE] [--sync-source HOST] [--sync-scope registry|development] [--sync-python EXECUTABLE] [--sync-ssh-config FILE] [--network offline|lan|online] [--resume SESSION_UUID|last] [--doctor]\nConfigure a new release: ./offline --setup-from EXISTING_HOST_PROFILE [--node PATH] [--frontend PATH]. This saves host paths for this release without copying sessions or starting the CLI.\nDevelopment preview: pinned Codex frontend with PLOS-owned sessions and settings. A configured sync source refreshes only the registry at startup. --sync-scope development explicitly refreshes a bound development database; /sync --remote refreshes the registry.');
    process.exit(0);
}
let options = {};
const explicitBindings = {};
const scriptedOverrides = [];
const scripted = args[0] === 'exec';
if (scripted) args.shift();
while (args.length) {
    const key = args.shift();
    if (scripted && ['--use-saved-defaults', '--json'].includes(key)) { options[key.slice(2)] = true; continue; }
    if (scripted && ['--input', '--input-json', '--set'].includes(key)) {
        if (!args.length) throw new Error(`Missing value for ${key}.`);
        const value = args.shift();
        if (key === '--set') scriptedOverrides.push(scriptedOverride(value));
        else {
            if (options[key.slice(2)] !== undefined) throw new Error(`Duplicate ${key}.`);
            options[key.slice(2)] = value;
        }
        continue;
    }
    if (key === '--host-binding') {
        const value = args.shift() ?? '';
        const separator = value.indexOf('=');
        if (separator <= 0) throw new Error('Host binding requires NAME=PATH.');
        explicitBindings[value.slice(0, separator)] = value.slice(separator + 1);
        continue;
    }
    if (key === '--doctor') { options.doctor = true; continue; }
    if (!['--host-profile', '--workspace', '--state-dir', '--frontend', '--native-codex', '--php', '--php-ini', '--sync-source', '--sync-scope', '--sync-python', '--sync-ssh-config', '--network', '--resume'].includes(key) || !args.length) throw new Error(`Unknown or incomplete option: ${key}`);
    options[key.slice(2)] = args.shift();
}
const hostProfile = options['host-profile'] === undefined ? null
    : await readHostProfile(options['host-profile'], process.env.PLOS_CLI_HOST_PROFILE_SHA256 ?? null);
options = { ...hostProfile?.options, ...options };
const environment = hostEnvironment(hostProfile, explicitBindings);
const syncScope = options['sync-scope'] ?? 'registry';
if (!['registry', 'development'].includes(syncScope)) throw new Error('Sync scope must be registry or development.');
if (syncScope === 'development' && !options['sync-source']) throw new Error('Development sync requires an explicit sync source.');
const selectedSshConfig = options['sync-ssh-config'] ?? environment.PLOS_CLI_SYNC_SSH_CONFIG ?? null;
let syncSshConfig = null;
if (selectedSshConfig !== null) {
    if (!selectedSshConfig || !(await stat(selectedSshConfig).catch(() => null))?.isFile()) {
        throw new Error('Selected SSH configuration is unavailable; no default configuration substituted.');
    }
    syncSshConfig = await realpath(selectedSshConfig);
}
const resume = options.resume ?? null;
resumeArguments(resume); // Reject malformed selections before state, sync or transport startup.
const input = scripted && !options.doctor ? await scriptedInput(options) : null;
if (isRecoveryInput(input) && (resume === null || scriptedOverrides.length > 0)) {
    throw new Error('Scripted /recover requires --resume SESSION_UUID and cannot be combined with --set. Recovery preserves the session configuration.');
}
const workspace = await realpath(options.workspace ?? process.cwd());
const explicitNetwork = scriptedOverrides.filter(({ key }) => key === 'cli.network').at(-1);
let network = explicitNetwork ? explicitNetwork.value : options.network ?? '';
if (explicitNetwork && !['offline', 'lan', 'online'].includes(network)) throw new Error('Network mode must be offline, lan or online.');
if (network && !['offline', 'lan', 'online'].includes(network)) throw new Error('Network mode must be offline, lan or online.');
const stateDirectory = path.resolve(options['state-dir'] ?? path.join(root, 'storage/app/private/plos-cli'));
const frontend = path.resolve(options.frontend ?? path.join(stateDirectory, 'bin', process.platform === 'win32' ? 'plos-codex.exe' : 'plos-codex'));
const php = options.php ?? 'php';
const nativeCodex = options['native-codex'] === undefined ? null : await realpath(options['native-codex']);
const phpIni = options['php-ini'] === undefined ? null : path.resolve(options['php-ini']);
const [frontendVerified, runtime, syncPython] = await Promise.all([
    scripted ? Promise.resolve(null) : verifyFrontend(frontend).then(() => true).catch(() => false), checkRuntime(php, phpIni, environment),
    resolveSyncPython(options['sync-python'] ?? environment.PLOS_CLI_SYNC_PYTHON ?? null),
]);
if (options.doctor) {
    console.log(JSON.stringify({ hostProfile: hostProfile === null ? null : { filename: hostProfile.filename, sha256: hostProfile.sha256 },
        hostBindings: Object.fromEntries(Object.entries(environment).filter(([key]) => hostBindingNames.includes(key))), workspace, stateDirectory, frontend, frontendVerified, php, phpIni, syncPython, syncSshConfig, syncScope, network,
        sshTunnel: { configured: Boolean(hostProfile?.sshTunnel), started: false },
        runtime, nativeCodex, mode: scripted ? 'exec' : 'interactive', launchReady: (scripted || frontendVerified) && runtime.ready,
        implementationStage: 'CLI-01 development preview', primaryAgentAdmitted: false }, null, 2));
    process.exit((scripted || frontendVerified) && runtime.ready ? 0 : 1);
}
if (!runtime.ready) throw new Error('CLI runtime prerequisites are incomplete. Use --doctor for required components and platform status.');
if (!scripted && !frontendVerified) throw new Error('A verified PLOS frontend build is required. Stock Codex cannot enforce this CLI’s input contract. Use --doctor.');
const canonicalState = await prepareStateDirectory(stateDirectory);
const remoteForwards = hostProfile?.sshTunnel?.forwards.map(({ localPort, remotePort }) => ({ localPort, remotePort, remoteHost: hostProfile.sshTunnel.host, active: false }));
if (remoteForwards) environment.PLOS_CLI_REMOTE_FORWARDS = JSON.stringify(remoteForwards);
let policy;
try {
    const { stdout } = await promisify(execFile)(php, phpArguments(path.join(root, 'scripts/plos-cli/tunnel-policy.php'),
        [canonicalState, network, resume ?? ''], phpIni), { env: environment, timeout: 10000, maxBuffer: 65536 });
    policy = JSON.parse(stdout);
    network = policy.network;
} catch {
    process.stderr.write('PLOS: startup network boundary could not be verified. Check the selected session, mode and SSH host address; no sync or SSH connection was started.\n');
    process.exit(1);
}
const frontendState = scripted ? null : await prepareStateDirectory(path.join(canonicalState, 'frontend-state'));
const syncSource = options['sync-source'] ?? '';
if (syncSource && !/^[A-Za-z0-9][A-Za-z0-9_.-]*(?:@[A-Za-z0-9][A-Za-z0-9_.-]*)?$/.test(syncSource)) throw new Error('Use an SSH alias or explicit user@host for sync.');
if (syncSource) {
    process.stderr.write('PLOS: checking the permitted registry source; saved configuration remains available.\n');
    const sync = spawn(php, phpArguments(path.join(root, 'scripts/plos-cli/startup-sync.php'), [canonicalState, syncSource, network, syncScope], phpIni), {
        stdio: ['ignore', 'pipe', 'pipe'], shell: false,
        env: { ...environment, PLOS_CLI_SYNC_PYTHON: syncPython.executable ?? '',
            ...(syncSshConfig === null ? {} : { PLOS_CLI_SYNC_SSH_CONFIG: syncSshConfig }) },
    });
    sync.stdout.on('data', chunk => process.stderr.write(chunk));
    sync.stderr.on('data', () => {});
    const progress = setInterval(() => process.stderr.write('PLOS: still checking the registry source...\n'), 10000);
    try {
        await new Promise(resolve => {
            sync.once('error', () => {
                process.stderr.write('PLOS: registry refresh could not start; using saved configuration.\n');
                resolve();
            });
            sync.once('exit', resolve);
        });
    } finally {
        clearInterval(progress);
    }
}
const token = randomBytes(32).toString('hex');
const { startTransport } = await import('./transport.mjs');
const controller = new AbortController();
let tunnel;
let transport;
let frontendChild;
let connectionLost = false;
const interrupt = () => {
    controller.abort();
    frontendChild?.kill('SIGTERM');
};
process.on('SIGINT', interrupt);
process.on('SIGTERM', interrupt);
try {
    if (hostProfile?.sshTunnel) {
        if (!policy.start) {
            process.stderr.write('PLOS: offline mode leaves SSH closed; forwarded remote engines are unavailable. Local models, tools and help remain usable.\n');
        } else {
            try {
                process.stderr.write('PLOS: opening the configured SSH connection.\n');
                tunnel = await startSshTunnel(hostProfile.sshTunnel, { signal: controller.signal, connectionAddress: policy.ssh_address ?? null });
                environment.PLOS_CLI_REMOTE_FORWARDS = JSON.stringify(remoteForwards.map(forward => ({ ...forward, active: true })));
            } catch (error) {
                if (hostProfile.sshTunnel.optional !== true || controller.signal.aborted) throw error;
                process.stderr.write('PLOS: optional SSH connection unavailable; its forwarded engines are disabled for this launch. Other models, tools and help remain usable.\n');
            }
            tunnel?.lost.then(() => {
                connectionLost = true;
                process.stderr.write('PLOS: SSH connection lost; ending this launch without replay. Use /host status on reconnect before further host actions.\n');
                interrupt();
            });
        }
    }
    transport = await startTransport({ workspace, stateDirectory: canonicalState, token, backendPath: path.join(root, 'scripts/plos-cli/backend.php'), php, phpIni, syncSource, syncPython: syncPython.executable ?? '', syncSshConfig, network, environment, nativeCodex });
    if (controller.signal.aborted) throw new Error('Launch interrupted before client startup.');
    if (scripted) {
        await runScripted({ endpoint: transport.endpoint, token, workspace, input, resume,
            overrides: [...(network ? [{ key: 'cli.network', value: network }] : []), ...scriptedOverrides],
            json: options.json === true, signal: controller.signal });
    } else {
        frontendChild = spawn(frontend, frontendArguments(transport.endpoint, workspace, resume),
            { cwd: workspace, stdio: 'inherit', shell: false,
                env: { ...environment, CODEX_HOME: frontendState, PLOS_CLI_TRANSPORT_TOKEN: token } });
        process.exitCode = await new Promise((resolve, reject) => {
            frontendChild.once('error', () => reject(new Error('The selected frontend could not start.')));
            frontendChild.once('exit', code => resolve(code ?? 1));
        });
    }
} catch (error) {
    process.stderr.write(`PLOS${scripted ? ' exec' : ''}: ${error.message}\n`);
    process.exitCode = controller.signal.aborted && !connectionLost ? 130 : 1;
} finally {
    try { await transport?.close(); } finally { await tunnel?.close(); }
    process.off('SIGINT', interrupt);
    process.off('SIGTERM', interrupt);
    if (connectionLost) process.exitCode = 1;
}

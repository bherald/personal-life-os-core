import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { constants } from 'node:fs';
import { lstat, open } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { tunnelConfiguration } from './ssh-tunnel.mjs';

const execute = promisify(execFile);
export const hostBindingNames = ['PHP_INI_SCAN_DIR', 'PLOS_CLI_BWRAP', 'PLOS_CLI_WINDOWS_MEDIA_ROOT', 'PLOS_CLI_WINDOWS_MEDIA_WORK',
    'PLOS_CLI_WINDOWS_SANDBOX', 'PLOS_CLI_WINDOWS_SANDBOX_STATE'];
const optionNames = ['workspace', 'state-dir', 'frontend', 'native-codex', 'php', 'php-ini', 'sync-source',
    'sync-python', 'sync-ssh-config', 'sync-scope', 'network', 'resume'];
export const launcherValueOptions = [...optionNames, 'host-profile', 'host-binding', 'input', 'input-json', 'set'];
const pathOptions = optionNames.filter(name => !['sync-source', 'sync-scope', 'network', 'resume'].includes(name));
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const text = value => typeof value === 'string' && value.length > 0 && !value.includes('\0');
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

export async function readHostProfile(filename, expectedSha256 = null) {
    const selected = path.resolve(filename);
    const info = await lstat(selected).catch(() => null);
    if (!info?.isFile() || info.isSymbolicLink()) throw new Error('Selected host profile must be a regular local file.');
    let verified;
    if (process.platform === 'win32') {
        const system = process.env.SystemRoot ?? process.env.SYSTEMROOT;
        if (!system) throw new Error('Host profile permissions cannot be verified.');
        try {
            const { stdout } = await execute(path.join(system, 'System32/WindowsPowerShell/v1.0/powershell.exe'),
                ['-NoProfile', '-NonInteractive', '-File', fileURLToPath(new URL('./check-private-file.ps1', import.meta.url)),
                    '-PathBase64', Buffer.from(selected, 'utf8').toString('base64')],
                { timeout: 15000, maxBuffer: 4096, encoding: 'utf8', windowsHide: true, shell: false });
            verified = JSON.parse(stdout);
            if (verified.schema !== 'plos.cli.private-file.v1') throw new Error();
        } catch { throw new Error('Host profile must have private operator permissions.'); }
    }
    const handle = await open(selected, constants.O_RDONLY | (constants.O_NOFOLLOW ?? 0));
    let bytes;
    try {
        const current = await handle.stat();
        if (!current.isFile() || (process.platform !== 'win32'
            && (current.uid !== process.getuid() || (current.mode & 0o077)))) {
            throw new Error('Host profile must be an owned private file (0600).');
        }
        bytes = await handle.readFile();
    } finally { await handle.close(); }
    const sha256 = digest(bytes);
    if ((verified && (verified.sha256 !== sha256 || verified.bytes !== bytes.length))
        || (expectedSha256 !== null && expectedSha256 !== sha256)) {
        throw new Error('Host profile changed during startup; no settings were applied.');
    }
    let profile;
    try { profile = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes)); }
    catch { throw new Error('Host profile must contain valid UTF-8 JSON.'); }
    if (!object(profile) || profile.schema !== 'plos.cli.host-profile.v1'
        || Object.keys(profile).some(key => !['schema', 'node', 'options', 'bindings', 'sshTunnel'].includes(key))
        || !object(profile.options ?? {}) || !object(profile.bindings ?? {})) {
        throw new Error('Unsupported host profile structure.');
    }
    const base = path.dirname(selected);
    const options = {};
    for (const [key, value] of Object.entries(profile.options ?? {})) {
        if (!optionNames.includes(key) || !text(value)) throw new Error('Unsupported host profile option.');
        options[key] = pathOptions.includes(key) ? path.resolve(base, value) : value;
    }
    const bindings = {};
    for (const [key, value] of Object.entries(profile.bindings ?? {})) {
        if (!hostBindingNames.includes(key) || !text(value)) throw new Error('Unsupported host profile binding.');
        bindings[key] = path.resolve(base, value);
    }
    if (profile.node !== undefined && !text(profile.node)) throw new Error('Host profile Node path is invalid.');
    return { filename: selected, sha256, node: profile.node === undefined ? null : path.resolve(base, profile.node), options, bindings,
        sshTunnel: tunnelConfiguration(profile.sshTunnel, base) };
}

export function hostEnvironment(profile, explicit = {}, inherited = process.env) {
    const result = { ...inherited };
    for (const [key, value] of Object.entries(profile?.bindings ?? {})) {
        if (result[key] === undefined) result[key] = value;
    }
    for (const [key, value] of Object.entries(explicit)) {
        if (!hostBindingNames.includes(key) || !text(value)) throw new Error('Unsupported CLI host binding.');
        result[key] = path.resolve(value);
    }
    return result;
}

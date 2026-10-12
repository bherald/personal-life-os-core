import { execFile } from 'node:child_process';
import { createRequire } from 'node:module';
import { stat } from 'node:fs/promises';
import path from 'node:path';
import { promisify } from 'node:util';
import { root } from './frontend-build.mjs';

const execute = promisify(execFile);
const require = createRequire(import.meta.url);

export function phpArguments(script, args = [], phpIni = null) {
    return [...(phpIni === null ? [] : ['-c', phpIni]), script, ...args];
}

export async function checkRuntime(php = 'php', phpIni = null, environment = process.env) {
    const [major, minor] = process.versions.node.split('.').map(Number);
    const nodeSupported = (major === 22 && minor >= 12) || major === 23 || major === 24;
    let bridgeAvailable = false;
    try { require.resolve('ws'); bridgeAvailable = true; } catch { /* Report missing dependency below. */ }
    let phpStatus = { available: false, ready: false };
    try {
        if (phpIni !== null && !(await stat(phpIni)).isFile()) throw new Error('PHP configuration must be a file.');
        const { stdout } = await execute(php, phpArguments(path.join(root, 'scripts/plos-cli/check-runtime.php'), [], phpIni), {
            env: environment, encoding: 'utf8', timeout: 15000, maxBuffer: 65536, windowsHide: true, shell: false,
        });
        const result = JSON.parse(stdout);
        if (result.schema !== 'plos.cli.runtime.v1' || typeof result.version !== 'string'
            || typeof result.versionSupported !== 'boolean' || typeof result.dependenciesAvailable !== 'boolean'
            || !Array.isArray(result.missingExtensions) || !result.missingExtensions.every(name => typeof name === 'string')
            || result.ready !== (result.versionSupported && result.dependenciesAvailable && result.missingExtensions.length === 0)) {
            throw new Error('Unrecognized PHP prerequisite report.');
        }
        phpStatus = { available: true, ...result };
    } catch { /* Never expose interpreter stderr or environment in the report. */ }
    // Windows x64 VT input, native dependencies, private installation and resume
    // are qualified. Frontend identity and live-model admission remain separate checks.
    const platformQualified = process.platform !== 'win32' || process.arch === 'x64';
    return { node: { version: process.versions.node, supported: nodeSupported, required: '>=22.12 <25' },
        php: phpStatus, bridgeAvailable, platformQualified,
        platformReason: platformQualified ? null : 'Native Windows qualification currently covers x64 only.',
        ready: nodeSupported && phpStatus.ready && bridgeAvailable && platformQualified };
}

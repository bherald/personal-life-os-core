import { execFile } from 'node:child_process';
import { stat } from 'node:fs/promises';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify(execFile);
const probe = 'import json,sys; print(json.dumps({"schema":"plos.cli.sync-python.v1","executable":sys.executable,"version":list(sys.version_info[:3])}))';

async function windowsCandidates(selection) {
    if (selection && path.isAbsolute(selection)) return [{ executable: selection, args: path.basename(selection).toLowerCase() === 'py.exe' ? ['-3'] : [] }];
    const names = selection ? [selection.toLowerCase().endsWith('.exe') ? selection : `${selection}.exe`]
        : ['python.exe', 'python3.exe', 'py.exe'];
    const candidates = [];
    for (const name of names) {
        if (path.basename(name) !== name) continue;
        for (const directory of (process.env.PATH ?? '').split(path.delimiter)) {
            // App-execution aliases may open the Store instead of an interpreter.
            if (!path.isAbsolute(directory) || /[\\/]WindowsApps(?:[\\/]|$)/i.test(directory)) continue;
            const executable = path.join(directory, name);
            if (await stat(executable).then(info => info.isFile()).catch(() => false)) {
                candidates.push({ executable, args: name.toLowerCase() === 'py.exe' ? ['-3'] : [] });
                if (selection) return candidates;
            }
        }
    }
    return candidates;
}

/** Optional registry-sync plumbing. An explicit selection never falls back. */
export async function resolveSyncPython(selection = null) {
    const failure = { available: false, executable: null, version: null, selection: selection === null ? 'automatic' : 'explicit' };
    if (selection !== null && (typeof selection !== 'string' || !selection || selection.includes('\0'))) return failure;
    const candidates = process.platform === 'win32' ? await windowsCandidates(selection)
        : (selection === null ? ['python3', 'python'] : [selection]).map(executable => ({ executable, args: [] }));
    for (const candidate of candidates) {
        try {
            const { stdout } = await execute(candidate.executable, [...candidate.args, '-c', probe], {
                encoding: 'utf8', timeout: 3000, maxBuffer: 8192, shell: false, windowsHide: true,
            });
            const result = JSON.parse(stdout);
            if (result.schema !== 'plos.cli.sync-python.v1' || typeof result.executable !== 'string'
                || !path.isAbsolute(result.executable) || !Array.isArray(result.version) || result.version.length !== 3
                || !result.version.every(Number.isInteger) || result.version[0] !== 3 || result.version[1] < 10
                || !(await stat(result.executable)).isFile()) continue;
            return { available: true, executable: result.executable, version: result.version.join('.'), selection: failure.selection };
        } catch { /* Do not expose interpreter stderr, user environment or credentials. */ }
    }
    return failure;
}

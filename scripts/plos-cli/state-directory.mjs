import { execFile } from 'node:child_process';
import { lstat, mkdir, realpath } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execute = promisify(execFile);

export async function prepareStateDirectory(directory, { create = true } = {}) {
    const requested = path.resolve(directory);
    if (requested === path.parse(requested).root) throw new Error('A filesystem root cannot hold CLI state.');
    if (process.platform === 'win32') {
        const helper = fileURLToPath(new URL('./state-directory.ps1', import.meta.url));
        let result;
        try {
            const { stdout } = await execute('powershell.exe', ['-NoProfile', '-NonInteractive',
                '-ExecutionPolicy', 'Bypass', '-File', helper,
                '-PathBase64', Buffer.from(requested, 'utf8').toString('base64'), ...(create ? ['-Create'] : [])],
            { encoding: 'utf8', timeout: 15000, maxBuffer: 65536, windowsHide: true, shell: false });
            result = JSON.parse(stdout);
        } catch {
            throw new Error('CLI state directory must be private local storage with inheritable operator access.');
        }
        if (result.schema !== 'plos.cli.state-directory.v1' || result.private !== true
            || typeof result.created !== 'boolean' || typeof result.path !== 'string'
            || path.resolve(result.path).toLowerCase() !== requested.toLowerCase()) {
            throw new Error('Windows state directory verification returned an invalid identity.');
        }
    } else {
        if (create) await mkdir(requested, { recursive: true, mode: 0o700 });
        const info = await lstat(requested);
        if (!info.isDirectory() || info.uid !== process.getuid() || (info.mode & 0o077)) {
            throw new Error('CLI state must be an owned private directory (0700).');
        }
    }
    return realpath(requested);
}

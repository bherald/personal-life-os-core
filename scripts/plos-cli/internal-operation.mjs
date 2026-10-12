import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { runManagedInput } from './managed-input.mjs';

// Trusted PLOS PHP adapter only. The caller supplies its selected PHP and a
// private stdin request; neither model arguments nor credentials become argv.
export async function runInternalOperation({ command, args, cwd, env, input, timeout_ms }, { signal } = {}) {
    if (typeof command !== 'string' || !command || !Array.isArray(args)
        || args.some(arg => typeof arg !== 'string' || arg.includes('\0'))
        || typeof cwd !== 'string' || !cwd || typeof input !== 'string'
        || !env || Array.isArray(env) || Object.entries(env).some(([key, value]) =>
            !/^[A-Za-z_][A-Za-z0-9_]*$/.test(key) || typeof value !== 'string' || value.includes('\0'))
        || !Number.isInteger(timeout_ms) || timeout_ms < 50 || timeout_ms > 3600000) {
        throw new Error('invalid_internal_launch');
    }
    const receipt = { schema: 'plos.cli.internal-operation.v1', started: false, cancelled: false,
        completed: false, exit: null, stdout_base64: '', stderr_base64: '' };
    if (signal?.aborted) return { ...receipt, cancelled: true };
    const stdout = [], stderr = [];
    const child = spawn(command, args, { cwd, env, shell: false, windowsHide: true,
        detached: process.platform !== 'win32', stdio: ['pipe', 'pipe', 'pipe'] });
    let timer, stopping;
    const cancel = () => {
        receipt.cancelled = true;
        if (receipt.exit !== null || !receipt.started || stopping) return;
        // PHP may be waiting for its private-file ACL helper or another bound
        // service child. Cancel that owned tree, not just the immediate PHP PID.
        if (process.platform === 'win32') {
            stopping = new Promise(resolve => {
                const stop = spawn(path.win32.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'taskkill.exe'),
                    ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true, shell: false, stdio: 'ignore' });
                stop.on('error', () => { receipt.failure = 'internal_tree_shutdown_unconfirmed'; child.kill(); });
                stop.once('close', code => {
                    if (code !== 0) receipt.failure = 'internal_tree_shutdown_unconfirmed';
                    resolve();
                });
            });
        } else {
            try { process.kill(-child.pid, 'SIGKILL'); }
            catch (error) {
                if (error.code !== 'ESRCH') { receipt.failure = 'internal_tree_shutdown_unconfirmed'; child.kill('SIGKILL'); }
            }
            stopping = Promise.resolve();
        }
    };
    try {
        await new Promise(resolve => {
            child.stdout.on('data', bytes => stdout.push(Buffer.from(bytes)));
            child.stderr.on('data', bytes => stderr.push(Buffer.from(bytes)));
            child.stdin.on('error', cancel);
            child.on('error', () => { receipt.failure = 'internal_process_failed'; });
            child.once('spawn', () => {
                receipt.started = true;
                if (signal?.aborted || receipt.cancelled) cancel();
                else child.stdin.end(input);
            });
            child.once('close', (code, terminationSignal) => {
                receipt.exit = { code, signal: terminationSignal };
                resolve();
            });
            signal?.addEventListener('abort', cancel, { once: true });
            timer = setTimeout(cancel, timeout_ms);
        });
        await stopping;
        receipt.completed = receipt.started && !receipt.cancelled && !receipt.failure && receipt.exit.code === 0;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', cancel);
        receipt.stdout_base64 = Buffer.concat(stdout).toString('base64');
        receipt.stderr_base64 = Buffer.concat(stderr).toString('base64');
    }
    return receipt;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    await runManagedInput(runInternalOperation);
}

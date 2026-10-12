import { spawn } from 'node:child_process';
import path from 'node:path';
import { isIP } from 'node:net';

// Host transport only: routing, admission and model parameters stay in the registry.
export function tunnelConfiguration(value, base = process.cwd()) {
    if (value === undefined) return null;
    const allowed = ['host', 'user', 'identityFile', 'knownHostsFile', 'executable', 'port', 'forwards',
        'connectTimeoutSeconds', 'startupTimeoutMs', 'serverAliveInterval', 'serverAliveCountMax', 'optional'];
    const object = item => item !== null && typeof item === 'object' && !Array.isArray(item);
    const invalid = () => { throw new Error('Invalid SSH tunnel host configuration.'); };
    if (!object(value) || Object.keys(value).some(key => !allowed.includes(key))) invalid();
    if (typeof value.host !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9.:-]*$/.test(value.host)
        || typeof value.user !== 'string' || !/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/.test(value.user)) invalid();
    const result = { host: value.host, user: value.user };
    if (value.optional !== undefined) {
        if (typeof value.optional !== 'boolean') invalid();
        result.optional = value.optional;
    }
    for (const key of ['identityFile', 'knownHostsFile']) {
        if (typeof value[key] !== 'string' || !value[key] || /[\0\r\n]/.test(value[key])) invalid();
        result[key] = path.resolve(base, value[key]);
    }
    if (value.executable !== undefined && (typeof value.executable !== 'string' || !value.executable
        || /[\0\r\n]/.test(value.executable))) invalid();
    result.executable = value.executable === undefined || value.executable === 'ssh' ? 'ssh' : path.resolve(base, value.executable);
    for (const [key, fallback, maximum] of [['port', 22, 65535], ['connectTimeoutSeconds', 15, 300],
        ['startupTimeoutMs', 30000, 300000], ['serverAliveInterval', 15, 300], ['serverAliveCountMax', 3, 10]]) {
        const selected = value[key] ?? fallback;
        if (!Number.isInteger(selected) || selected < 1 || selected > maximum) invalid();
        result[key] = selected;
    }
    if (!Array.isArray(value.forwards) || value.forwards.length === 0 || value.forwards.length > 16) invalid();
    const ports = new Set();
    result.forwards = value.forwards.map(forward => {
        if (!object(forward) || Object.keys(forward).some(key => !['localPort', 'remotePort'].includes(key))) invalid();
        for (const key of ['localPort', 'remotePort']) {
            if (!Number.isInteger(forward[key]) || forward[key] < 1 || forward[key] > 65535) invalid();
        }
        if (ports.has(forward.localPort)) invalid();
        ports.add(forward.localPort);
        return { localPort: forward.localPort, remotePort: forward.remotePort };
    });
    return result;
}

export function tunnelArguments(config, connectionAddress = null) {
    if (connectionAddress !== null && (typeof connectionAddress !== 'string' || !isIP(connectionAddress))) throw new Error('Invalid SSH pinned address.');
    // Ignore inherited configuration/forwards, multiplexing and agent credentials.
    return ['-F', 'none', '-N', '-T', '-v', '-S', 'none', '-a', '-x',
        '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ExitOnForwardFailure=yes',
        '-o', 'ControlMaster=no', '-o', 'ControlPersist=no', '-o', 'ForkAfterAuthentication=no',
        '-o', 'PermitLocalCommand=no', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
        '-o', `UserKnownHostsFile="${config.knownHostsFile.replaceAll('\\', '\\\\').replaceAll('"', '\\"')}"`, '-o', 'GlobalKnownHostsFile=none',
        '-o', `ConnectTimeout=${config.connectTimeoutSeconds}`, '-o', 'ConnectionAttempts=1',
        '-o', `ServerAliveInterval=${config.serverAliveInterval}`, '-o', `ServerAliveCountMax=${config.serverAliveCountMax}`,
        ...(connectionAddress === null ? [] : ['-o', `Hostname=${connectionAddress}`, '-o', `HostKeyAlias=${config.host}`]),
        '-i', config.identityFile, '-p', String(config.port), '-l', config.user,
        ...config.forwards.flatMap(({ localPort, remotePort }) => ['-L', `127.0.0.1:${localPort}:127.0.0.1:${remotePort}`]), config.host];
}

export async function startSshTunnel(config, { signal, spawnProcess = spawn, connectionAddress = null } = {}) {
    if (signal?.aborted) throw new Error('SSH connection startup cancelled.');
    const child = spawnProcess(config.executable, tunnelArguments(config, connectionAddress), {
        stdio: ['ignore', 'ignore', 'pipe'], shell: false, windowsHide: true,
    });
    let ready = false;
    let closing = false;
    let ended = false;
    let buffer = '';
    let resolveLost;
    const lost = new Promise(resolve => { resolveLost = resolve; });
    let resolveExit;
    const exited = new Promise(resolve => { resolveExit = resolve; });
    let rejectReady;
    let resolveReady;
    const started = new Promise((resolve, reject) => { resolveReady = resolve; rejectReady = reject; });
    const failure = () => new Error('SSH connection failed; check the selected host, key, known-host entry and free local ports. No request was replayed.');
    const finish = () => {
        if (ended) return;
        ended = true;
        if (!ready) rejectReady(failure());
        resolveExit();
        if (!closing) resolveLost();
    };
    child.once('error', finish);
    child.once('exit', finish);
    const close = async () => {
        closing = true;
        if (!ended) {
            child.kill('SIGTERM');
            const force = setTimeout(() => { if (!ended) child.kill('SIGKILL'); }, 2000);
            try { await exited; } finally { clearTimeout(force); }
        }
    };
    const abort = () => { rejectReady(new Error('SSH connection startup cancelled.')); void close(); };
    signal?.addEventListener('abort', abort, { once: true });
    const timer = setTimeout(() => {
        rejectReady(new Error('SSH connection startup timed out; no model request was sent.'));
        void close();
    }, config.startupTimeoutMs);
    child.stderr.on('data', bytes => {
        // OpenSSH enters its session only after authentication and all required listeners.
        // Do not publish SSH debug output (which contains private paths and host details).
        buffer += bytes.toString('utf8');
        let newline;
        while ((newline = buffer.indexOf('\n')) !== -1) {
            const line = buffer.slice(0, newline).trimEnd();
            buffer = buffer.slice(newline + 1);
            if (!closing && !ended && line === 'debug1: Entering interactive session.') {
                ready = true;
                resolveReady();
            }
        }
        if (buffer.length > 8192) buffer = buffer.slice(-8192);
    });
    try {
        await started;
        if (ended || closing || signal?.aborted) throw failure();
        return { close, lost };
    } catch (error) {
        await close();
        throw error;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
    }
}

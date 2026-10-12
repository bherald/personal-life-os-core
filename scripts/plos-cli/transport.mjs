import http from 'node:http';
import { spawn } from 'node:child_process';
import { timingSafeEqual } from 'node:crypto';
import { createInterface } from 'node:readline';
import { WebSocketServer, WebSocket } from 'ws';
import { phpArguments } from './runtime-check.mjs';
import { NativeProcesses } from './native-processes.mjs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** Loopback-only presentation bridge. Business operations remain in PHP/PLOS. */
export async function startTransport({ workspace, stateDirectory, token, backendPath, php = 'php', phpIni = null, syncSource = '', syncPython = null, syncSshConfig = null, network = '', environment = process.env, nativeCodex = null }) {
    if (typeof token !== 'string' || token.length < 32) throw new Error('A private transport token is required.');
    const server = http.createServer((_request, response) => {
        response.writeHead(404);
        response.end();
    });
    const sockets = new Set();
    const workers = new Map();
    const nativeOwners = new Set();
    const expected = Buffer.from(`Bearer ${token}`);
    const wss = new WebSocketServer({ noServer: true, perMessageDeflate: false, maxPayload: 64 * 1024 * 1024 });
    server.on('upgrade', (request, socket, head) => {
        const supplied = Buffer.from(request.headers.authorization ?? '');
        if (request.url !== '/' || request.headers.origin || supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) {
            socket.end('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
            return;
        }
        wss.handleUpgrade(request, socket, head, ws => wss.emit('connection', ws));
    });
    wss.on('connection', ws => {
        sockets.add(ws);
        const native = new NativeProcesses({ executable: nativeCodex, cwd: workspace, stateDirectory,
            env: { PATH: environment.PATH, HOME: environment.HOME, CODEX_HOME: path.join(stateDirectory, 'native-state') },
            onPresentation: event => { if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(event)); } });
        nativeOwners.add(native);
        let nativeClosed;
        const closeNative = () => nativeClosed ??= native.close().finally(() => nativeOwners.delete(native));
        const launchWorker = script => {
            const worker = spawn(php, phpArguments(script, [workspace, stateDirectory], phpIni), {
                cwd: workspace, stdio: ['pipe', 'pipe', 'pipe', 'pipe', 'pipe'], shell: false,
                env: { ...environment, ...(syncSshConfig === null ? {} : { PLOS_CLI_SYNC_SSH_CONFIG: syncSshConfig }),
                    ...(syncPython === null ? {} : { PLOS_CLI_SYNC_PYTHON: syncPython }),
                    PLOS_CLI_SYNC_SOURCE: syncSource, PLOS_CLI_NETWORK: network, PLOS_CLI_NODE: process.execPath,
                    PLOS_CLI_NATIVE_CHANNEL: 'pipes-v1' },
            });
            // These descriptors are private to PHP; frontend RPC cannot call the executor.
            const nativeLines = createInterface({ input: worker.stdio[3], crlfDelay: Infinity });
            let nativeQueue = Promise.resolve();
            nativeLines.on('line', line => {
                nativeQueue = nativeQueue.then(async () => {
                    let request;
                    try {
                        request = JSON.parse(line);
                        const result = await native.dispatch(request.sessionId, request.operation, request.arguments, request.permissions, request.turnId ?? null);
                        worker.stdio[4].write(JSON.stringify({ id: request.id, result }) + '\n');
                    } catch (error) {
                        worker.stdio[4].write(JSON.stringify({ id: request?.id ?? null, error: { message: error.message } }) + '\n');
                    }
                }).catch(() => { ws.close(1011, 'Native execution channel failed.'); });
            });
            worker.stdio[4].on('error', () => { closeNative().catch(() => {}); });
            workers.set(worker, new Promise(resolve => worker.once('close', () => {
                workers.delete(worker);
                resolve();
            })));
            // PHP diagnostics stay out of the protocol and never include request bodies.
            worker.stderr.on('data', () => {});
            worker.on('error', () => ws.close(1011, 'PLOS backend could not start.'));
            const lines = createInterface({ input: worker.stdout, crlfDelay: Infinity });
            lines.on('line', line => {
                if (ws.readyState === WebSocket.OPEN) ws.send(line);
            });
            worker.stdin.on('drain', () => ws.resume());
            worker.stdin.on('error', () => ws.close(1011, 'PLOS backend input closed.'));
            worker.on('exit', () => {
                nativeLines.close();
                closeNative().catch(() => {});
                if (ws.readyState === WebSocket.OPEN) ws.close(1011, 'PLOS backend stopped.');
            });
            return { worker, lines };
        };
        const { worker, lines } = launchWorker(backendPath);
        let controls;
        const controlIds = new Set();
        // Operator RPC runs independently of the model worker, but can reach
        // the native executor only through the same registered PHP tools.
        ws.on('message', (bytes, binary) => {
            if (binary) return ws.close(1003, 'JSON text frames are required.');
            let request;
            try { request = JSON.parse(bytes.toString('utf8')); } catch { return ws.close(1007, 'Invalid JSON.'); }
            if (request === null || Array.isArray(request) || typeof request !== 'object') return ws.close(1007, 'RPC object required.');
            let target = worker;
            if (request.method === 'plos/process' || request.method === 'turn/interrupt') {
                if (!['string', 'number'].includes(typeof request.id) || controlIds.has(JSON.stringify(request.id))
                    || typeof request.params?.threadId !== 'string'
                    || (request.method === 'turn/interrupt' ? typeof request.params?.turnId !== 'string' : typeof request.params?.command !== 'string')) {
                    ws.send(JSON.stringify({ id: request.id ?? null, error: { code: -32602, message: 'Invalid or repeated process control; no action replayed.' } }));
                    return;
                }
                controlIds.add(JSON.stringify(request.id));
                controls ??= launchWorker(fileURLToPath(new URL('./native-control.php', import.meta.url)));
                target = controls.worker;
                request = { id: request.id, method: request.method,
                    params: { threadId: request.params.threadId, ...(request.method === 'turn/interrupt'
                        ? { turnId: request.params.turnId } : { command: request.params.command }) },
                    ownedIds: [...native.processes.values()].filter(process => process.sessionId === request.params.threadId).map(process => process.id) };
            }
            if (!target.stdin.write(`${JSON.stringify(request)}\n`)) ws.pause();
        });
        ws.on('error', () => {});
        ws.on('close', () => {
            sockets.delete(ws);
            lines.close();
            worker.stdin.end();
            worker.kill('SIGTERM');
            controls?.lines.close();
            controls?.worker.kill('SIGTERM');
            closeNative().catch(() => {});
        });
    });
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    return {
        endpoint: `ws://127.0.0.1:${server.address().port}/`,
        async close() {
            const pending = [...workers.entries()];
            const nativePending = [...nativeOwners].map(owner => owner.close());
            for (const socket of sockets) socket.terminate();
            for (const [worker] of pending) worker.kill('SIGTERM');
            await new Promise(resolve => wss.close(resolve));
            await new Promise(resolve => server.close(resolve));
            // Windows retains the workspace handle until process/stdio close.
            // Wait for our children before reporting that state is released.
            await Promise.all(pending.map(async ([worker, closed]) => {
                const timer = setTimeout(() => worker.kill('SIGKILL'), 5000);
                timer.unref();
                try { await closed; } finally { clearTimeout(timer); }
            }));
            await Promise.all(nativePending);
        },
    };
}

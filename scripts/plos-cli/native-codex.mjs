import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { StringDecoder } from 'node:string_decoder';

/** Native App Server transport. The trusted PLOS caller supplies current policy. */
export class NativeCodexClient {
    constructor({ executable, args = [], cwd, env, authorize, onNotification = () => {}, onRequest = null, onOutput = () => {} }) {
        if (typeof authorize !== 'function') throw new Error('A PLOS authorization callback is required.');
        this.launch = { executable, args, cwd, env };
        this.authorize = authorize;
        this.onNotification = onNotification;
        this.onRequest = onRequest;
        this.onOutput = onOutput;
        this.pending = new Map();
        this.nextId = 1;
        this.stderr = [];
        this.decoder = new StringDecoder('utf8');
        this.buffer = '';
        this.closed = false;
    }

    async start() {
        if (this.child || this.closed) throw new Error('Native Codex connection cannot be restarted or replayed.');
        const { executable, args, cwd, env } = this.launch;
        this.child = spawn(executable, [...args, 'app-server', '--listen', 'stdio://'], {
            cwd, env, stdio: ['pipe', 'pipe', 'pipe'], shell: false,
        });
        this.exited = new Promise(resolve => this.child.once('close', (code, signal) => {
            this.exit = { code, signal };
            this.closed = true;
            this.rejectPending(new Error('Native Codex connection closed; action outcomes may be unknown.'));
            resolve(this.exit);
        }));
        this.child.stderr.on('data', bytes => {
            this.stderr.push(Buffer.from(bytes));
            this.observeOutput('stderr', bytes);
        });
        this.child.stdout.on('data', bytes => {
            this.observeOutput('stdout', bytes);
            this.buffer += this.decoder.write(bytes);
            let index;
            while ((index = this.buffer.indexOf('\n')) >= 0) {
                const line = this.buffer.slice(0, index);
                this.buffer = this.buffer.slice(index + 1);
                if (!line.trim()) continue;
                try { this.receive(JSON.parse(line)); }
                catch { this.fail(new Error('Native Codex returned an invalid protocol message.')); }
            }
        });
        this.child.stdin.on('error', () => this.fail(new Error('Native Codex input closed; no request replayed.')));
        this.child.on('error', () => this.fail(new Error('Native Codex could not start.')));
        await once(this.child, 'spawn');
        try {
            const result = await this.request('initialize', {
                clientInfo: { name: 'plos_native', title: 'PLOS', version: '0.1.0' },
                capabilities: { experimentalApi: true },
            });
            this.send({ method: 'initialized', params: {} });
            return result;
        } catch (error) {
            await this.close();
            throw error;
        }
    }

    observeOutput(stream, bytes) {
        try { this.onOutput(stream, Buffer.from(bytes)); }
        catch { this.fail(new Error('Native Codex output could not be retained.')); }
    }

    async request(method, params, { timeoutMs = 30000 } = {}) {
        if (this.closed || !this.child) throw new Error('Native Codex is not connected.');
        if (!Number.isSafeInteger(timeoutMs) || timeoutMs < 1) throw new Error('A positive request timeout is required.');
        // Capture exact bytes before asynchronous approval, preventing argument substitution.
        const encoded = JSON.stringify(params);
        const approved = await this.authorize(method, JSON.parse(encoded));
        if (approved !== true) throw new Error('PLOS denied the native Codex operation.');
        if (this.closed) throw new Error('Native Codex closed during authorization.');
        const id = this.nextId++;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                // Closing the connection lets native Codex terminate its owned commands.
                this.fail(new Error('Native Codex request timed out; action outcome is unknown, not replayed.'));
            }, timeoutMs);
            this.pending.set(id, { resolve, reject, timer });
            try { this.send({ id, method, params: JSON.parse(encoded) }); }
            catch (error) { this.fail(error); }
        });
    }

    receive(message) {
        if (!message || typeof message !== 'object' || Array.isArray(message)) throw new Error('Invalid RPC message.');
        if (typeof message.method === 'string') {
            if (Object.hasOwn(message, 'id')) {
                const handler = this.onRequest;
                Promise.resolve().then(() => {
                    if (!handler) throw new Error('No approval handler.');
                    return handler(message.method, message.params);
                }).then(result => this.send({ id: message.id, result }),
                    () => this.send({ id: message.id, error: { code: -32601, message: 'PLOS has no approved handler for this request.' } }))
                    .catch(() => this.fail(new Error('Native Codex approval response failed.')));
            } else {
                this.onNotification(message.method, message.params);
            }
            return;
        }
        const pending = this.pending.get(message.id);
        if (!pending || (Object.hasOwn(message, 'result') === Object.hasOwn(message, 'error'))) throw new Error('Unexpected RPC response.');
        this.pending.delete(message.id);
        clearTimeout(pending.timer);
        if (Object.hasOwn(message, 'error')) {
            const error = new Error('Native Codex rejected the operation.');
            error.rpcError = message.error; // Private caller may retain the exact diagnostic.
            pending.reject(error);
        } else pending.resolve(message.result);
    }

    send(message) {
        if (this.closed || !this.child?.stdin.writable) throw new Error('Native Codex input is unavailable.');
        this.child.stdin.write(JSON.stringify(message) + '\n');
    }

    rejectPending(error) {
        for (const pending of this.pending.values()) {
            clearTimeout(pending.timer);
            pending.reject(error);
        }
        this.pending.clear();
    }

    fail(error) {
        this.failure ??= error;
        this.rejectPending(error);
        this.closed = true;
        this.child?.stdin.end();
    }

    async close() {
        this.closed = true;
        this.rejectPending(new Error('Native Codex connection closed by its owner.'));
        if (!this.child || this.exit) return;
        this.child.stdin.end();
        const wait = async () => {
            let timer;
            try { await Promise.race([this.exited, new Promise(resolve => { timer = setTimeout(resolve, 1000); })]); }
            finally { clearTimeout(timer); }
        };
        await wait();
        if (!this.exit) { this.child.kill('SIGTERM'); await wait(); }
        if (!this.exit) { this.child.kill('SIGKILL'); await wait(); }
        if (!this.exit) throw new Error('Native Codex shutdown is unconfirmed.');
    }
}

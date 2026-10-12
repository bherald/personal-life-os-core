import { randomUUID } from 'node:crypto';
import { appendFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { hostname } from 'node:os';
import { NativeCodexClient } from './native-codex.mjs';
import { NativePresentation } from './native-presentation.mjs';

/** Private PHP-to-Node executor. PHP's shared PLOS registry authorizes every call. */
export class NativeProcesses {
    constructor({ executable, cwd, stateDirectory, env, onPresentation = () => {} }) {
        this.options = { executable, cwd, env };
        this.directory = path.join(stateDirectory, 'native-processes');
        this.processes = new Map();
        this.closing = false;
        this.onPresentation = onPresentation;
    }

    async connect() {
        if (this.closing) throw new Error('Native process owner is closed; no actions replayed.');
        if (!this.options.executable) throw new Error('Select the installed native Codex executable with --native-codex.');
        if (!this.connection) {
            mkdirSync(this.directory, { recursive: true, mode: 0o700 });
            if (this.options.env?.CODEX_HOME) mkdirSync(this.options.env.CODEX_HOME, { recursive: true, mode: 0o700 });
            this.client = new NativeCodexClient({ ...this.options,
                authorize: method => ['initialize', 'command/exec', 'command/exec/write',
                    'command/exec/terminate', 'command/exec/resize'].includes(method),
                onNotification: (method, params) => {
                    if (method !== 'command/exec/outputDelta') return;
                    const process = this.processes.get(params.processId);
                    if (!process) throw new Error('Native output has no owning process.');
                    this.record(process, { type: 'output', ...params });
                    this.present(process, () => process.presentation?.output(params.stream, Buffer.from(params.deltaBase64, 'base64')));
                    process.status = 'running';
                    if (params.capReached) process.integrity_error = 'Native output was capped; result is incomplete.';
                },
            });
            // A failed start remains failed. Never recreate a connection and replay work.
            this.connection = this.client.start();
        }
        await this.connection;
    }

    record(process, event) {
        appendFileSync(process.file, JSON.stringify(event) + '\n', { mode: 0o600 });
    }

    present(process, render) {
        if (process.presentation_error) return;
        try { render(); }
        catch {
            process.presentation_error = 'Native display failed; inspect the original process journal.';
            this.record(process, { type: 'presentation_error', message: process.presentation_error });
        }
    }

    owned(sessionId, processId) {
        const process = this.processes.get(processId);
        if (!process || process.sessionId !== sessionId) throw new Error('Process does not belong to this active session; saved actions are not resumed.');
        return process;
    }

    snapshot(process, offset = 0) {
        if (!Number.isSafeInteger(offset) || offset < 0) throw new Error('Output offset must be a nonnegative integer.');
        const events = readFileSync(process.file, 'utf8').trimEnd().split('\n').map(line => JSON.parse(line));
        if (offset > events.length) throw new Error('Output offset is beyond the retained events.');
        return { process_id: process.id, execution_host: hostname(), status: process.status,
            exit_code: process.result?.exitCode ?? null, error: process.error ?? null,
            integrity_error: process.integrity_error ?? null, events: events.slice(offset),
            presentation_error: process.presentation_error ?? null,
            next_offset: events.length, journal: process.file };
    }

    async dispatch(sessionId, operation, args, permissions = null, turnId = null) {
        if (this.closing) throw new Error('Native process owner is closing.');
        if (typeof sessionId !== 'string' || !sessionId) throw new Error('A bound PLOS session is required.');
        if (operation === 'start') {
            if (turnId !== null && (typeof turnId !== 'string' || !/^[a-zA-Z0-9_-]{1,128}$/.test(turnId))) {
                throw new Error('Native command presentation requires a valid PLOS turn identity.');
            }
            // Permissions come only from the trusted PHP session, never tool arguments.
            if (!permissions || !['readOnly', 'workspaceWrite', 'dangerFullAccess'].includes(permissions.type)) {
                throw new Error('An explicit PLOS native execution policy is required.');
            }
            await this.connect();
            const id = randomUUID();
            const process = { id, sessionId, permissions: structuredClone(permissions), status: 'starting', file: path.join(this.directory, `${id}.jsonl`) };
            writeFileSync(process.file, '', { flag: 'wx', mode: 0o600 });
            this.processes.set(id, process);
            this.record(process, { type: 'start', session_id: sessionId, process_id: id,
                turn_id: turnId, execution_host: hostname(), arguments: args, permissions });
            if (turnId !== null) {
                this.present(process, () => { process.presentation = new NativePresentation({ processId: id, sessionId, turnId,
                    command: args.command, cwd: args.path, host: hostname(),
                    journal: path.join(this.directory, `${id}.ui.jsonl`), notify: this.onPresentation }); });
            }
            const command = ['/bin/bash', '-c', args.command];
            process.done = this.client.request('command/exec', {
                command, cwd: args.path, processId: id, tty: args.tty, streamStdin: true,
                streamStdoutStderr: true, disableOutputCap: true, timeoutMs: args.timeout_ms,
                sandboxPolicy: permissions,
            }, { timeoutMs: args.timeout_ms + 10000 }).then(result => {
                process.result = result;
                process.status = 'completed';
                this.record(process, { type: 'completed', result });
                this.present(process, () => process.presentation?.complete(result, Boolean(process.integrity_error)));
            }, error => {
                process.status = error.rpcError ? 'failed' : 'unknown';
                process.error = error.rpcError ?? { message: error.message };
                this.record(process, { type: process.status, error: process.error });
                this.present(process, () => process.presentation?.complete(null, true));
            }).catch(() => {
                // A journal failure must not become an unhandled rejection or
                // cause a completed command to be sent again.
                process.integrity_error = 'Native process journal could not be updated; outcome recording is incomplete.';
            });
            return this.snapshot(process);
        }
        const process = this.owned(sessionId, args.process_id);
        if (operation === 'output') return this.snapshot(process, args.offset ?? 0);
        if (!['input', 'cancel', 'resize'].includes(operation)) throw new Error('Unknown native process operation.');
        if (!['starting', 'running'].includes(process.status)) throw new Error('Native process is no longer running.');
        if (operation === 'input' && JSON.stringify(permissions) !== JSON.stringify(process.permissions)) {
            throw new Error('Native permissions changed since launch; cancel or start a newly approved process.');
        }
        const method = { input: 'write', cancel: 'terminate', resize: 'resize' }[operation];
        const params = { processId: process.id,
            ...(operation === 'input' ? { deltaBase64: args.input_base64, closeStdin: args.close_stdin } : {}),
            ...(operation === 'resize' ? { size: { rows: args.rows, cols: args.columns } } : {}) };
        // Record before sending. If acknowledgement is lost, do not retry input.
        this.record(process, { type: 'requested', operation, arguments: args });
        try {
            await this.client.request(`command/exec/${method}`, params);
            this.record(process, { type: 'acknowledged', operation });
        } catch (error) {
            this.record(process, { type: 'operation_error', operation, error: error.rpcError ?? { message: error.message } });
            throw error;
        }
        return this.snapshot(process);
    }

    close() {
        return this.shutdown ??= (async () => {
            this.closing = true;
            await this.client?.close();
            await Promise.all([...this.processes.values()].map(process => process.done));
        })();
    }
}

import { readFile } from 'node:fs/promises';
import { WebSocket } from 'ws';

/** Scripted presentation of the same PLOS App Server protocol as the TUI. */
export async function scriptedInput(options, stdin = process.stdin) {
    if (options['use-saved-defaults'] !== true) throw new Error('exec requires --use-saved-defaults to explicitly select saved startup settings. Session --set overrides remain available.');
    if (options.resume === 'last') throw new Error('Scripted resume requires an explicit session UUID, not last.');
    if ((options.input === undefined) === (options['input-json'] === undefined)) throw new Error('exec requires exactly one --input FILE|- or --input-json FILE|-.');
    const filename = options.input ?? options['input-json'];
    let bytes;
    if (filename === '-') {
        if (stdin.isTTY) throw new Error('Pipe input on stdin or select a file; exec does not prompt for input.');
        const parts = [];
        for await (const part of stdin) parts.push(Buffer.from(part));
        bytes = Buffer.concat(parts);
    } else bytes = await readFile(filename);
    // Reject invalid UTF-8 instead of silently replacing bytes. Preserve a BOM
    // as original text; JSON syntax validation is left to JSON.parse below.
    const text = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes);
    const input = options.input === undefined ? JSON.parse(text) : [{ type: 'text', text }];
    if (!Array.isArray(input) || !input.length || input.some(item => item === null || typeof item !== 'object' || Array.isArray(item))) {
        throw new Error('Input JSON must be a nonempty list of complete Codex input items.');
    }
    return input;
}

export function scriptedOverride(value) {
    const split = value.indexOf('=');
    const key = value.slice(0, split);
    if (split < 1 || !/^[A-Za-z0-9_.-]+$/.test(key)) throw new Error('Use --set KEY=JSON; string values must be JSON quoted.');
    return { key, value: JSON.parse(value.slice(split + 1)) };
}

export function isRecoveryInput(input) {
    return Array.isArray(input) && input.length === 1 && input[0]?.type === 'text'
        && typeof input[0].text === 'string' && /^\/recover(?:\r\n|\n|\r)?$/.test(input[0].text);
}

export async function runScripted({ endpoint, token, workspace, input, resume = null, overrides = [], json = false,
    stdout = process.stdout, stderr = process.stderr, signal }) {
    const ws = new WebSocket(endpoint, { headers: { Authorization: `Bearer ${token}` }, maxPayload: 0 });
    const pending = new Map();
    let sequence = 0, activeTurn = null, closed = false, displayAnswer = false;
    const fail = error => {
        for (const { reject } of pending.values()) reject(error);
        pending.clear();
        activeTurn?.reject(error);
        activeTurn = null;
    };
    const abort = () => { fail(new Error('Script interrupted. Inspect the saved session before continuing; no action was replayed.')); ws.terminate(); };
    const call = (method, params = {}) => new Promise((resolve, reject) => {
        if (closed || ws.readyState !== WebSocket.OPEN) return reject(new Error('PLOS transport is closed; no request was replayed.'));
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }), error => { if (error) { pending.delete(id); reject(error); } });
    });
    ws.on('message', bytes => {
        try {
            const message = JSON.parse(bytes.toString('utf8'));
            if (!message.method && Object.hasOwn(message, 'id') && pending.has(message.id)) {
                const waiting = pending.get(message.id);
                pending.delete(message.id);
                if (message.error) waiting.reject(new Error(message.error.message));
                else waiting.resolve(message.result);
            } else if (message.method) {
                if (Object.hasOwn(message, 'id')) {
                    // No unattended confirmation, elicitation or request for
                    // credentials is treated as operator approval.
                    ws.send(JSON.stringify({ id: message.id, error: { code: -32000, message: 'Operator input required; unattended approval is unavailable.' } }));
                    return;
                }
                if (json) stdout.write(JSON.stringify(message) + '\n');
                const params = message.params ?? {};
                const selectedThread = activeTurn !== null && activeTurn.threadId === params.threadId;
                if (selectedThread && message.method === 'turn/started') activeTurn.turnId = params.turnId ?? params.turn?.id;
                const selectedTurn = selectedThread && activeTurn.turnId === params.turnId;
                if (!json && displayAnswer && selectedTurn) {
                    if (message.method === 'item/agentMessage/delta' && typeof params.delta === 'string'
                        && params.delta.length > 0 && !activeTurn.completedItems.has(params.itemId)) {
                        stdout.write(params.delta);
                        activeTurn.deltaItems.add(params.itemId);
                    } else if (message.method === 'item/completed' && params.item?.type === 'agentMessage'
                        && typeof params.item.text === 'string' && !activeTurn.completedItems.has(params.item.id)) {
                        if (!activeTurn.deltaItems.has(params.item.id)) stdout.write(params.item.text);
                        activeTurn.completedItems.add(params.item.id);
                    }
                }
                if (message.method === 'turn/completed' && selectedTurn) {
                    activeTurn.resolve(message.params.turn);
                    activeTurn = null;
                }
            }
        } catch (error) { fail(error); ws.terminate(); }
    });
    ws.on('error', () => fail(new Error('PLOS transport failed; no request was replayed.')));
    ws.on('close', () => { closed = true; fail(new Error('PLOS transport closed before completion; inspect the saved session before continuing.')); });
    const turn = async (threadId, items) => {
        let completion;
        const done = new Promise((resolve, reject) => { completion = { threadId, turnId: null, deltaItems: new Set(), completedItems: new Set(), resolve, reject }; });
        activeTurn = completion;
        // Attach both consumers before awaiting the initial acknowledgement;
        // a fast completion or disconnect must not be lost.
        const [, result] = await Promise.all([call('turn/start', { threadId, input: items }), done]);
        if (result.status !== 'completed') throw new Error(result.error?.message ?? `PLOS turn ${result.status}.`);
        return result;
    };
    try {
        await new Promise((resolve, reject) => {
            ws.once('open', resolve);
            ws.once('error', () => reject(new Error('PLOS transport could not connect.')));
            ws.once('close', () => reject(new Error('PLOS transport closed during connection.')));
            signal?.addEventListener('abort', abort, { once: true });
            if (signal?.aborted) abort();
        });
        await call('initialize', { clientInfo: { name: 'plos-exec', version: '0.1' } });
        const started = await call(resume === null ? 'thread/start' : 'thread/resume',
            resume === null ? { cwd: workspace } : { threadId: resume });
        const threadId = started.thread.id;
        stderr.write(`PLOS session: ${threadId}\n`);
        if (isRecoveryInput(input)) {
            if (resume === null) throw new Error('Scripted /recover requires an explicit session UUID.');
            displayAnswer = true;
            await turn(threadId, input);
            return { threadId };
        }
        for (const { key, value } of overrides) {
            await turn(threadId, [{ type: 'text', text: `/config set ${key} ${JSON.stringify(value)}` }]);
        }
        displayAnswer = true;
        await turn(threadId, input);
        return { threadId };
    } finally {
        signal?.removeEventListener('abort', abort);
        ws.terminate();
    }
}

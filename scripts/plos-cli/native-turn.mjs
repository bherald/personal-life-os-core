import { NativeCodexClient } from './native-codex.mjs';

/** One native turn. The caller resolves PLOS policy and configuration before launch. */
export async function runNativeTurn({
    executable, args = [], cwd, env, model, effort, sandbox, profile = null, ephemeral = true,
    prompt, developerInstructions, outputSchema, timeoutMs, signal,
    onText = () => {}, onEvent = () => {}, onOutput = () => {},
}) {
    if (typeof prompt !== 'string' || typeof developerInstructions !== 'string') {
        throw new Error('Native turn requires separate instruction and user strings.');
    }
    if (!Number.isSafeInteger(timeoutMs) || timeoutMs < 1) throw new Error('A positive turn timeout is required.');
    if (!['read-only', 'workspace-write', 'danger-full-access'].includes(sandbox)) {
        throw new Error('A resolved native sandbox is required.');
    }
    if (profile !== null && (typeof profile !== 'string' || !/^[A-Za-z0-9_-]+$/.test(profile))) {
        throw new Error('A valid native configuration profile name is required.');
    }
    if (signal?.aborted) throw new Error('Native turn cancelled before launch.');

    let threadId, turnId, lastItemId, completed, timer, abort;
    const messages = new Map();
    let finish;
    const completion = new Promise(resolve => { finish = resolve; });
    const response = () => messages.get(lastItemId) ?? '';
    const retain = (id, value) => {
        messages.set(id, value);
        lastItemId = id;
        onText(value);
    };
    const client = new NativeCodexClient({
        // Stock binaries reject this unknown feature before starting the server.
        // The compatible executor also honors the selected profile at this entrypoint.
        executable, args: [...args, ...(profile === null ? [] : ['-p', profile]), '--enable', 'plos_manual_compaction', '--enable', 'plos_lossless_skills', '--enable', 'plos_lossless_tool_output'], cwd, env, onOutput,
        // Pipeline requests are noninteractive. Native approval requests have no handler.
        authorize: method => ['initialize', 'thread/start', 'turn/start'].includes(method),
        onNotification(method, params) {
            onEvent(method, params);
            if (!threadId || params?.threadId !== threadId) return;
            if (turnId && params.turnId && params.turnId !== turnId) return;
            if (method === 'item/agentMessage/delta') {
                if (typeof params.itemId !== 'string' || typeof params.delta !== 'string') throw new Error('Invalid agent delta.');
                retain(params.itemId, (messages.get(params.itemId) ?? '') + params.delta);
            } else if (method === 'item/completed' && params.item?.type === 'agentMessage') {
                if (typeof params.item.id !== 'string' || typeof params.item.text !== 'string') throw new Error('Invalid agent message.');
                retain(params.item.id, params.item.text);
            } else if (method === 'turn/completed') {
                completed = params.turn;
                if (turnId && completed?.id === turnId) finish(completed);
            }
        },
    });

    let status = 'failed', error = null;
    const interrupted = new Promise((_, reject) => {
        timer = setTimeout(() => { status = 'timed_out'; reject(new Error('Native turn timed out; no request replayed.')); }, timeoutMs);
        abort = () => { status = 'cancelled'; reject(new Error('Native turn cancelled; no request replayed.')); };
        signal?.addEventListener('abort', abort, { once: true });
    });
    try {
        await Promise.race([(async () => {
            await client.start();
            const started = await client.request('thread/start', {
                model, cwd, sandbox, approvalPolicy: 'never', ephemeral, developerInstructions,
                // Preserve original tool/resource events in the caller's private
                // process capture, including results without a rendered UI item.
                experimentalRawEvents: true,
                // Leave native base instructions intact; never flatten caller roles.
            }, { timeoutMs });
            threadId = started.thread?.id;
            if (typeof threadId !== 'string' || !threadId) throw new Error('Native thread ID is missing.');
            const startedTurn = await client.request('turn/start', {
                threadId, input: [{ type: 'text', text: prompt }], effort,
                ...(outputSchema === undefined ? {} : { outputSchema }),
            }, { timeoutMs });
            turnId = startedTurn.turn?.id;
            if (typeof turnId !== 'string' || !turnId) throw new Error('Native turn ID is missing.');
            if (completed?.id === turnId) finish(completed);
            const turn = await Promise.race([completion, client.exited.then(() => {
                throw new Error('Native connection closed before turn completion; outcome is unknown.');
            })]);
            status = turn.status;
            if (status !== 'completed') {
                if (turn.error?.message?.includes('PLOS requires an operator decision before compaction:')) {
                    throw new Error('Context limit reached. Confirm /compact, choose another model, or start a new session with /clear. Original context was not compacted.');
                }
                throw new Error('Native turn did not complete successfully.');
            }
            if (client.failure) throw client.failure;
        })(), interrupted]);
    } catch (failure) {
        error = failure.message;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
        // EOF lets Codex stop its owned work; close verifies exit and escalates only
        // against this child if it does not stop. Never leave an unobserved server.
        await client.close();
    }
    // Stream callbacks can still fail while the child drains during shutdown.
    if (client.failure && error === null) error = client.failure.message;
    return { success: error === null && status === 'completed', status, error,
        response: response(), threadId, turnId, exit: client.exit };
}

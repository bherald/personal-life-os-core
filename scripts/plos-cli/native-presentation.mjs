import { appendFileSync } from 'node:fs';
import { StringDecoder } from 'node:string_decoder';

/** Native UI events only; command authority and original bytes remain in the executor. */
export class NativePresentation {
    constructor({ processId, sessionId, turnId, command, cwd, host, journal, notify }) {
        this.params = { threadId: sessionId, turnId };
        this.journal = journal;
        this.notify = notify;
        this.decoders = { stdout: new StringDecoder('utf8'), stderr: new StringDecoder('utf8') };
        this.item = { type: 'commandExecution', id: processId, command, cwd, processId,
            source: 'agent', status: 'inProgress', commandActions: [], aggregatedOutput: '', exitCode: null, durationMs: null };
        this.startedAt = Date.now();
        const notice = { type: 'agentMessage', id: processId + '-host',
            text: `Native command on ${host}; process ${processId}. Output display is UTF-8; the process journal retains every original byte.` };
        this.publish('item/started', { item: notice });
        this.publish('item/completed', { item: notice });
        this.publish('item/started', { item: this.item });
    }

    publish(method, fields) {
        const now = Date.now();
        const lifecycle = method === 'item/started' ? { startedAtMs: now }
            : method === 'item/completed' ? { completedAtMs: now } : {};
        const event = { method, params: { ...this.params, ...lifecycle, ...fields } };
        // A separate display journal avoids feeding duplicate rendered text
        // back into the model's original tool-result ledger.
        appendFileSync(this.journal, JSON.stringify({ ...event, recorded_at: now }) + '\n', { mode: 0o600 });
        this.notify(event);
    }

    output(stream, bytes) {
        if (!Object.hasOwn(this.decoders, stream)) throw new Error('Unknown native output stream.');
        this.delta(this.decoders[stream].write(bytes));
    }

    delta(text) {
        if (!text) return;
        this.item.aggregatedOutput += text;
        this.publish('item/commandExecution/outputDelta', { itemId: this.item.id, delta: text });
    }

    complete(result, uncertain = false) {
        for (const decoder of Object.values(this.decoders)) this.delta(decoder.end());
        this.item.exitCode = result?.exitCode ?? null;
        this.item.status = !uncertain && result?.exitCode === 0 ? 'completed' : 'failed';
        this.item.durationMs = Date.now() - this.startedAt;
        if (uncertain) this.delta('\nPLOS: command outcome is unverified. Review the retained process journal before continuing; no action was replayed.\n');
        this.publish('item/completed', { item: this.item });
    }
}

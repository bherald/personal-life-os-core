import { appendFileSync, writeFileSync } from 'node:fs';
import { runNativeTurn } from './native-turn.mjs';

// Private one-shot connector input. Prompt/instructions never appear in argv.
const chunks = [];
for await (const chunk of process.stdin) chunks.push(chunk);
const cancellation = new AbortController();
const cancel = () => cancellation.abort();
process.once('SIGTERM', cancel);
process.once('SIGINT', cancel);
try {
    const request = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    const { outputPath, ...options } = request;
    if (typeof outputPath !== 'string' || !outputPath) throw new Error('Missing private output path.');
    // The file exists even for an empty/failed turn: raw JSON-RPC is not a response.
    writeFileSync(outputPath, '', { mode: 0o600 });
    let retained = '';
    const result = await runNativeTurn({
        ...options, signal: cancellation.signal,
        onText: text => {
            if (text === retained) return;
            if (text.startsWith(retained)) appendFileSync(outputPath, text.slice(retained.length));
            else writeFileSync(outputPath, text, { mode: 0o600 });
            retained = text;
        },
        onOutput: (stream, bytes) => process[stream].write(bytes),
    });
    process.stdout.write(JSON.stringify({ type: 'plos.native.turn.result', success: result.success, status: result.status, error: result.error }) + '\n');
    if (!result.success) process.stderr.write('\n' + result.error + '\n');
    process.exitCode = result.success ? 0 : result.status === 'timed_out' ? 124 : 1;
} catch {
    // Raw provider/protocol diagnostics already went to the private capture.
    process.stderr.write('Native Codex turn could not execute; verify its compatible executor and configuration.\n');
    process.exitCode = 1;
} finally {
    process.removeListener('SIGTERM', cancel);
    process.removeListener('SIGINT', cancel);
}

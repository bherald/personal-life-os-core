import { createInterface } from 'node:readline';
import { createConnection } from 'node:net';
import { writeFileSync } from 'node:fs';

const mode = process.env.PLOS_MCP_FIXTURE_MODE;
let initialized = false;
let call;
const send = message => process.stdout.write(`${JSON.stringify({ jsonrpc: '2.0', ...message })}\n`);
const definition = name => ({ name,
    inputSchema: { type: 'object', properties: { text: { type: 'string' } }, required: ['text'], additionalProperties: false },
    outputSchema: { type: 'object', properties: { original: { type: 'string' } }, required: ['original'], additionalProperties: false },
});
createInterface({ input: process.stdin }).on('line', line => {
    const request = JSON.parse(line);
    if (request.method === 'initialize') {
        setTimeout(() => send({ id: request.id, result: { protocolVersion: request.params.protocolVersion,
            capabilities: { tools: {} }, serverInfo: { name: 'plos-fixture', version: '1.0' }, instructions: '  exact server instructions\r\n ' } }), 20);
    } else if (request.method === 'notifications/initialized') {
        initialized = true;
    } else if (request.method === 'tools/list') {
        if (!initialized) throw new Error('operation_before_initialized');
        if (request.params?.cursor) send({ id: request.id, result: { tools: [definition('second')] } });
        else send({ id: request.id, result: { tools: [definition('echo')], nextCursor: 'second-page' } });
    } else if (request.method === 'tools/call') {
        call = request;
        if (process.env.PLOS_MCP_FIXTURE_MARKER) writeFileSync(process.env.PLOS_MCP_FIXTURE_MARKER, String(process.pid));
        process.stderr.write(Buffer.from([0, 255, 10, 32]));
        if (mode === 'hang') return;
        if (mode === 'malformed') { process.stdout.write(' { BROKEN ORIGINAL \xff\n'); return; }
        if (mode === 'sampling') {
            send({ id: 'server-sampling', method: 'sampling/createMessage', params: { messages: [], maxTokens: 1 } });
            return;
        }
        if (mode === 'network-probe') {
            const socket = createConnection({ host: '127.0.0.1', port: Number(process.env.PLOS_MCP_FIXTURE_PORT) });
            const finish = original => {
                socket.destroy();
                send({ id: request.id, result: { content: [{ type: 'text', text: original }], structuredContent: { original } } });
            };
            socket.once('connect', () => finish('connected'));
            socket.once('error', error => finish(error.code));
            return;
        }
        const result = { content: [
            { type: 'text', text: request.params.arguments.text },
            { type: 'image', mimeType: 'image/png', data: 'AAECA//+' },
            { type: 'resource', resource: { uri: 'fixture://original', mimeType: 'application/octet-stream', blob: 'AAECA//+' } },
        ], structuredContent: { original: mode === 'bad-output' ? 7 : request.params.arguments.text } };
        if (mode === 'tool-error') result.isError = true;
        if (mode === 'slow') setTimeout(() => send({ id: request.id, result }), 900);
        else send({ id: request.id, result });
    } else if (request.id === 'server-sampling') {
        send({ id: call.id, result: { content: [{ type: 'text', text: String(request.error?.code) }],
            structuredContent: { original: String(request.error?.code) } } });
    }
});

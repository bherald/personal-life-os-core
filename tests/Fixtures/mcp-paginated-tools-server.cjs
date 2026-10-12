'use strict';

const readline = require('node:readline');

const mode = process.argv[2] ?? 'valid';
const lines = readline.createInterface({ input: process.stdin });

function send(payload) {
    process.stdout.write(`${JSON.stringify(payload)}\n`);
}

function tool(name) {
    return {
        name,
        description: `Fixture tool ${name}.`,
        inputSchema: { type: 'object', properties: {} },
    };
}

lines.on('line', line => {
    const request = JSON.parse(line);

    if (request.method === 'initialize') {
        send({
            jsonrpc: '2.0',
            id: request.id,
            result: {
                protocolVersion: '2025-11-25',
                capabilities: { tools: {} },
                serverInfo: { name: 'plos-paginated-tools-fixture', version: '1.0.0' },
            },
        });
        return;
    }

    if (request.method !== 'tools/list') {
        return;
    }

    const cursor = request.params?.cursor;

    if (mode === 'invalid-cursor') {
        send({ jsonrpc: '2.0', id: request.id, result: { tools: [tool('first')], nextCursor: 2 } });
        return;
    }

    if (mode === 'invalid-payload') {
        send({ jsonrpc: '2.0', id: request.id, result: { tools: [{ name: '' }] } });
        return;
    }

    if (mode === 'repeated-cursor') {
        send({ jsonrpc: '2.0', id: request.id, result: { tools: [tool(`page-${request.id}`)], nextCursor: 'again' } });
        return;
    }

    if (mode === 'endless') {
        send({ jsonrpc: '2.0', id: request.id, result: { tools: [tool(`page-${request.id}`)], nextCursor: `cursor-${request.id}` } });
        return;
    }

    if (mode === 'duplicate') {
        send({
            jsonrpc: '2.0',
            id: request.id,
            result: cursor === undefined
                ? { tools: [tool('duplicate')], nextCursor: 'second-page' }
                : { tools: [tool('duplicate')] },
        });
        return;
    }

    send({
        jsonrpc: '2.0',
        id: request.id,
        result: cursor === undefined
            ? { tools: [tool('first')], nextCursor: 'second-page' }
            : { tools: [tool('second')] },
    });
});

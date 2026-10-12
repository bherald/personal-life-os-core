'use strict';

const readline = require('node:readline');

const lines = readline.createInterface({ input: process.stdin });

function send(payload) {
    process.stdout.write(`${JSON.stringify(payload)}\n`);
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
                serverInfo: { name: 'plos-output-schema-fixture', version: '1.0.0' },
            },
        });
        return;
    }

    if (request.method === 'tools/list') {
        send({
            jsonrpc: '2.0',
            id: request.id,
            result: {
                tools: [{
                    name: 'structured_echo',
                    description: 'Return a fixture count.',
                    inputSchema: {
                        type: 'object',
                        additionalProperties: false,
                        properties: { invalid: { type: 'boolean' } },
                    },
                    outputSchema: {
                        type: 'object',
                        additionalProperties: false,
                        required: ['count'],
                        properties: { count: { type: 'integer' } },
                    },
                }],
            },
        });
        return;
    }

    if (request.method === 'tools/call') {
        const invalid = request.params?.arguments?.invalid === true;
        send({
            jsonrpc: '2.0',
            id: request.id,
            result: {
                content: [{ type: 'text', text: invalid ? '{"count":"do-not-expose"}' : '{"count":1}' }],
                structuredContent: { count: invalid ? 'do-not-expose' : 1 },
                isError: false,
            },
        });
    }
});

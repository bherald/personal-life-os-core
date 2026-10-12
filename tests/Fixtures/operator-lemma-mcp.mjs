import { createInterface } from 'node:readline';

const send = (id, result) => process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, result }) + '\n');
const tools = ['lemma_status', 'lemma_start'].map(name => ({ name,
  inputSchema: { type: 'object', properties: {}, additionalProperties: false } }));
for await (const line of createInterface({ input: process.stdin })) {
  const request = JSON.parse(line);
  if (request.method === 'initialize') send(request.id, { protocolVersion: request.params.protocolVersion,
    capabilities: { tools: {} }, serverInfo: { name: 'lemma-test', version: '1' } });
  else if (request.method === 'tools/list') send(request.id, { tools });
  else if (request.method === 'tools/call') send(request.id, { content: [{ type: 'text', text: request.params.name }],
    structuredContent: { action: request.params.name }, isError: false });
}

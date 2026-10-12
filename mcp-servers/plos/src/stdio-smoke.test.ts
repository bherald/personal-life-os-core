import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { auditToolContract, parseBaseline } from './tool-contract.js';

test('stdio server stays alive and lists offline pipeline tools', async () => {
  const child = spawn(process.execPath, ['dist/index.js'], {
    cwd: process.cwd(),
    env: {
      ...process.env,
      LOG_FILE: '/tmp/plos-mcp-stdio-smoke-test.log',
      NODE_ENV: 'development',
      PLOS_MCP_DOTENV_MODE: 'skip',
      SKIP_SSH_TUNNEL: 'true',
    },
    stdio: ['pipe', 'pipe', 'pipe'],
  });

  let buffer = '';
  let stderr = '';
  const notifications: any[] = [];
  const pending = new Map<number, {
    resolve: (message: any) => void;
    reject: (error: Error) => void;
    timeout: ReturnType<typeof setTimeout>;
  }>();

  const responseFor = (id: number) => new Promise<any>((resolve, reject) => {
    const timeout = setTimeout(() => {
      pending.delete(id);
      reject(new Error(`Timed out waiting for response ${id}; stderr=${stderr}`));
    }, 5000);
    pending.set(id, { resolve, reject, timeout });
  });

  child.stderr.on('data', chunk => {
    stderr += chunk.toString();
  });

  child.on('exit', code => {
    for (const request of pending.values()) {
      clearTimeout(request.timeout);
      request.reject(new Error(`MCP process exited before response; code=${code}; stderr=${stderr}`));
    }
    pending.clear();
  });

  child.stdout.on('data', chunk => {
    buffer += chunk.toString();

    for (;;) {
      const newline = buffer.indexOf('\n');
      if (newline === -1) break;

      const line = buffer.slice(0, newline);
      buffer = buffer.slice(newline + 1);
      if (line.trim() === '') continue;

      const message = JSON.parse(line);
      if (message.method === 'notifications/progress') {
        notifications.push(message);
      }
      const request = pending.get(message.id);
      if (request) {
        clearTimeout(request.timeout);
        pending.delete(message.id);
        request.resolve(message);
      }
    }
  });

  const send = (message: Record<string, unknown>) => {
    child.stdin.write(`${JSON.stringify(message)}\n`);
  };

  try {
    const initializeResponse = responseFor(1);
    send({
      jsonrpc: '2.0',
      id: 1,
      method: 'initialize',
      params: {
        protocolVersion: '2025-06-18',
        capabilities: {},
        clientInfo: { name: 'stdio-smoke-test', version: '1.0.0' },
      },
    });
    const initialized = await initializeResponse;
    assert.equal(initialized.result.protocolVersion, '2025-06-18');
    assert.equal(initialized.result.serverInfo.name, 'plos');
    assert.ok(initialized.result.capabilities.tools);
    assert.match(initialized.result.instructions, /Genea\/genealogy are domains on this server/);
    assert.match(initialized.result.instructions, /genealogy_search with the explicitly selected tree_id/);
    assert.match(initialized.result.instructions, /Do not call list_mcp_resources for genealogy/);
    assert.match(initialized.result.instructions, /never use or suggest a cloud or backup LLM/);

    send({ jsonrpc: '2.0', method: 'notifications/initialized', params: {} });
    const resourcesList = responseFor(10);
    send({ jsonrpc: '2.0', id: 10, method: 'resources/list', params: {} });
    const resourcesResponse = await resourcesList;
    assert.deepEqual(resourcesResponse.result.resources, [
      {
        uri: 'plos://genealogy/tool-guide',
        name: 'PLOS genealogy tool guide',
        description: 'How to use the PLOS genealogy search/context tools in local-only offline sessions.',
        mimeType: 'text/plain',
      },
    ]);

    const resourceRead = responseFor(11);
    send({
      jsonrpc: '2.0',
      id: 11,
      method: 'resources/read',
      params: { uri: 'plos://genealogy/tool-guide' },
    });
    const resourceResponse = await resourceRead;
    assert.match(resourceResponse.result.contents[0].text, /Use the genealogy_search tool/);
    assert.match(resourceResponse.result.contents[0].text, /must not use a cloud or backup LLM/);

    const toolsList = responseFor(2);
    send({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} });
    const response = await toolsList;
    const tools = response.result.tools as Array<{
      name: string;
      description: string;
      inputSchema: Record<string, any>;
      outputSchema?: Record<string, any>;
      annotations: {
        readOnlyHint: boolean;
        destructiveHint: boolean;
        idempotentHint: boolean;
        openWorldHint: boolean;
      };
    }>;
    const toolNames = tools.map(tool => tool.name);
    const baseline = parseBaseline(JSON.parse(readFileSync(
      new URL('../tool-contract-baseline.json', import.meta.url),
      'utf8',
    )));
    const contractAudit = auditToolContract(tools, baseline);

    assert.ok(toolNames.includes('plos_offline_pipeline_tools'));
    assert.ok(toolNames.includes('plos_offline_capabilities'));
    assert.ok(toolNames.includes('plos_offline_sidecar'));
    assert.ok(toolNames.includes('plos_colibri_research'));
    assert.ok(toolNames.includes('plos_colibri_local_research'));
    assert.equal(new Set(toolNames).size, toolNames.length, 'tool names must be unique');
    assert.equal(contractAudit.status, 'pass');
    assert.equal(contractAudit.expected_digest_matched, true);
    assert.equal((contractAudit.summary as Record<string, unknown>).violations, 0);

    for (const tool of tools) {
      assert.match(tool.name, /^[a-z][a-z0-9_]*$/, `${tool.name} must use stable snake_case`);
      assert.ok(tool.description.trim().length >= 20, `${tool.name} needs a useful description`);
      assert.equal(
        tool.inputSchema.type,
        'object',
        `${tool.name} input must be an object: ${JSON.stringify(tool.inputSchema)}`,
      );
      assert.ok(tool.inputSchema.properties && typeof tool.inputSchema.properties === 'object');
      assert.equal(JSON.stringify(tool.inputSchema).includes('"$ref"'), false, `${tool.name} schema must be self-contained`);
      assert.equal(typeof tool.annotations.readOnlyHint, 'boolean');
      assert.equal(typeof tool.annotations.destructiveHint, 'boolean');
      assert.equal(typeof tool.annotations.idempotentHint, 'boolean');
      assert.equal(typeof tool.annotations.openWorldHint, 'boolean');
      if (tool.annotations.readOnlyHint) {
        assert.equal(tool.annotations.destructiveHint, false, `${tool.name} cannot be read-only and destructive`);
      }

      for (const required of tool.inputSchema.required ?? []) {
        assert.ok(required in tool.inputSchema.properties, `${tool.name}.${required} must exist in properties`);
      }
    }

    const genealogyBatch = tools.find(tool => tool.name === 'genealogy_batch_apply');
    assert.ok(genealogyBatch);
    assert.equal(genealogyBatch.annotations.readOnlyHint, false);
    assert.equal(genealogyBatch.annotations.destructiveHint, false);
    assert.equal(genealogyBatch.outputSchema?.type, 'object');
    assert.equal(genealogyBatch.outputSchema?.additionalProperties, false);
    assert.deepEqual(genealogyBatch.outputSchema?.required, [
      'dry_run', 'tree_id', 'reason', 'inserted', 'updated', 'touched_for_rag', 'keys', 'graph_guard',
    ]);
    assert.equal(genealogyBatch.outputSchema?.properties.graph_guard.additionalProperties, false);
    assert.equal(genealogyBatch.outputSchema?.properties.graph_guard.properties.before.additionalProperties, false);
    assert.equal(genealogyBatch.inputSchema.properties.dry_run.default, true);
    assert.equal(genealogyBatch.inputSchema.properties.confirm.default, false);
    assert.equal(genealogyBatch.inputSchema.properties.sources.type, 'array');
    assert.equal(genealogyBatch.inputSchema.properties.sources.items.type, 'object');
    assert.ok(genealogyBatch.inputSchema.properties.sources.items.properties.title);
    assert.ok(genealogyBatch.inputSchema.properties.citations.items.properties.source_id);

    const genealogySearch = tools.find(tool => tool.name === 'genealogy_search');
    assert.ok(genealogySearch);
    assert.equal(genealogySearch.annotations.readOnlyHint, true);
    assert.equal(genealogySearch.annotations.idempotentHint, true);
    assert.equal(genealogySearch.outputSchema?.type, 'object');
    assert.equal(genealogySearch.outputSchema?.additionalProperties, false);
    assert.ok(genealogySearch.outputSchema?.required?.includes('result_count'));
    assert.ok(genealogySearch.outputSchema?.required?.includes('results'));
    assert.deepEqual(genealogySearch.inputSchema.properties.entity_type.enum, ['all', 'person', 'family', 'source', 'media']);
    assert.equal(genealogySearch.inputSchema.properties.limit.maximum, 50);

    const genealogyRead = tools.find(tool => tool.name === 'genealogy_context');
    assert.ok(genealogyRead);
    assert.equal(genealogyRead.annotations.readOnlyHint, true);
    assert.equal(genealogyRead.annotations.idempotentHint, true);
    assert.equal(genealogyRead.outputSchema?.type, 'object');
    assert.equal(genealogyRead.outputSchema?.additionalProperties, false);
    assert.deepEqual(genealogyRead.outputSchema?.required, ['tree_id']);

    const ssh = tools.find(tool => tool.name === 'plos_ssh');
    assert.ok(ssh);
    assert.equal(ssh.annotations.destructiveHint, true);
    assert.equal(ssh.annotations.openWorldHint, true);
    assert.equal(ssh.inputSchema.properties.cwd.default, undefined);
    assert.match(ssh.inputSchema.properties.cwd.description, /configured remote project root/);

    const rerank = tools.find(tool => tool.name === 'plos_rerank');
    assert.ok(rerank);
    assert.equal(rerank.inputSchema.properties.documents.type, 'array');
    const rerankDocumentVariants = rerank.inputSchema.properties.documents.items.anyOf;
    assert.ok(Array.isArray(rerankDocumentVariants));
    assert.ok(rerankDocumentVariants.some((variant: Record<string, any>) => variant.type === 'string'));
    const rerankObject = rerankDocumentVariants.find((variant: Record<string, any>) => variant.type === 'object');
    assert.ok(rerankObject?.properties.text);

    const mediaAnalyze = tools.find(tool => tool.name === 'plos_media_analyze');
    assert.ok(mediaAnalyze);
    assert.equal(mediaAnalyze.annotations.readOnlyHint, false);
    assert.equal(mediaAnalyze.annotations.destructiveHint, false);
    assert.deepEqual(Object.keys(mediaAnalyze.inputSchema.properties).sort(), [
      'execute', 'model', 'path', 'prompt',
    ]);
    assert.equal(mediaAnalyze.inputSchema.properties.execute.default, false);
    assert.equal(mediaAnalyze.inputSchema.properties.path.type, 'string');
    assert.equal(mediaAnalyze.inputSchema.properties.url, undefined);
    assert.equal(mediaAnalyze.inputSchema.properties.token, undefined);

    const imageGenerate = tools.find(tool => tool.name === 'plos_image_generate');
    assert.ok(imageGenerate);
    assert.equal(imageGenerate.annotations.readOnlyHint, false);
    assert.equal(imageGenerate.annotations.destructiveHint, false);
    assert.deepEqual(Object.keys(imageGenerate.inputSchema.properties).sort(), [
      'execute', 'guidance_scale', 'height', 'model', 'prompt', 'seed', 'steps', 'width',
    ]);
    assert.equal(imageGenerate.inputSchema.properties.execute.default, false);
    assert.equal(imageGenerate.inputSchema.properties.model.default, 'sdxl-lightning');
    assert.deepEqual(imageGenerate.inputSchema.properties.model.enum, [
      'sdxl-lightning',
      'sdxl-base-1.0',
      'pixart-sigma-1024',
      'flux2-klein-4b',
      'sana-sprint-1.6b',
    ]);
    assert.equal(imageGenerate.inputSchema.properties.width.default, 1024);
    assert.equal(imageGenerate.inputSchema.properties.width.minimum, 256);
    assert.equal(imageGenerate.inputSchema.properties.width.maximum, 1024);
    assert.equal(imageGenerate.inputSchema.properties.width.multipleOf, 64);
    assert.equal(imageGenerate.inputSchema.properties.height.default, 1024);
    assert.equal(imageGenerate.inputSchema.properties.height.minimum, 256);
    assert.equal(imageGenerate.inputSchema.properties.height.maximum, 1024);
    assert.equal(imageGenerate.inputSchema.properties.height.multipleOf, 64);
    assert.equal(imageGenerate.inputSchema.properties.steps.default, undefined);
    assert.equal(imageGenerate.inputSchema.properties.steps.minimum, 1);
    assert.equal(imageGenerate.inputSchema.properties.steps.maximum, 100);
    assert.equal(imageGenerate.inputSchema.properties.guidance_scale.default, undefined);
    assert.equal(imageGenerate.inputSchema.properties.guidance_scale.minimum, 0);
    assert.equal(imageGenerate.inputSchema.properties.guidance_scale.maximum, 30);
    assert.equal(imageGenerate.inputSchema.properties.url, undefined);
    assert.equal(imageGenerate.inputSchema.properties.token, undefined);

    const progressCall = responseFor(3);
    send({
      jsonrpc: '2.0',
      id: 3,
      method: 'tools/call',
      params: {
        name: 'plos_offline_capabilities',
        arguments: { probe_sidecars: false, on_prod: false },
        _meta: { progressToken: 'stdio-progress-token' },
      },
    });
    const progressResponse = await progressCall;
    assert.notEqual(progressResponse.result.isError, true);
    assert.ok(progressResponse.result.content?.length > 0);

    const progress = notifications.filter(notification => (
      notification.params?.progressToken === 'stdio-progress-token'
    ));
    assert.deepEqual(progress.map(notification => notification.params.message), ['started', 'completed']);
    assert.ok(progress.every(notification => notification.params.total === undefined));
    assert.ok(progress[1].params.progress >= progress[0].params.progress);
  } finally {
    child.kill('SIGTERM');
  }
});

import assert from 'node:assert/strict';
import test from 'node:test';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import { runMcpOperation, encodeWindowsLaunchPlan } from './mcp-operation.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const original = '  José\r\n\t' + 'unchanged original '.repeat(2000) + '  end  ';
const request = (mode = '', text = original) => ({ launch: { command: process.execPath,
    args: [path.join(root, 'tests/fixtures/plos-cli-mcp-server.mjs')], cwd: root,
    env: { PATH: process.env.PATH, PLOS_MCP_FIXTURE_MODE: mode } },
    tool: { name: 'echo', arguments: { text } }, timeout_ms: 2500 });

test('Windows launch prefix retains Unicode and whitespace without leaking into child input', () => {
    const plan = { schema: 'plos.cli.windows-process.v1', env: { EXAMPLE: original } };
    const prefix = encodeWindowsLaunchPlan(plan);
    assert.equal(prefix.readUInt32LE(0), prefix.length - 4);
    assert.deepEqual(JSON.parse(prefix.subarray(4).toString('utf8')), plan);
    assert.throws(() => encodeWindowsLaunchPlan({ schema: plan.schema, env: { EXAMPLE: 'x'.repeat(16 * 1024 * 1024) } }),
        /metadata_too_large/);
});

test('a launch prefix cannot be attached to an ordinary executable argument list', async () => {
    const operation = request();
    operation.launch.windows_plan = { schema: 'plos.cli.windows-process.v1' };
    await assert.rejects(runMcpOperation(operation), /invalid_windows_launch/);
});

test('real stdio handshake, paginated discovery and mixed media preserve full original values', async () => {
    const receipt = await runMcpOperation(request());
    assert.equal(receipt.protocol_valid, true);
    assert.equal(receipt.calls_sent, 1);
    assert.equal(receipt.response_received, true);
    assert.deepEqual(receipt.tools.map(tool => tool.name), ['echo', 'second']);
    assert.equal(receipt.instructions, '  exact server instructions\r\n ');
    assert.equal(receipt.result.content[0].text, original);
    assert.equal(receipt.result.content[1].data, 'AAECA//+');
    assert.equal(receipt.result.content[2].resource.blob, 'AAECA//+');
    assert.equal(receipt.result.structuredContent.original, original);
    assert.deepEqual(Buffer.from(receipt.stderr_base64, 'base64'), Buffer.from([0, 255, 10, 32]));
    assert.notEqual(receipt.exit, null);
});

test('tool error retains the complete structured and media result', async () => {
    const receipt = await runMcpOperation(request('tool-error'));
    assert.equal(receipt.protocol_valid, true);
    assert.equal(receipt.result.isError, true);
    assert.equal(receipt.result.content[0].text, original);
    assert.equal(receipt.result.structuredContent.original, original);
});

test('invalid input is rejected before tools/call without coercion', async () => {
    const receipt = await runMcpOperation(request('', 123));
    assert.equal(receipt.protocol_valid, false);
    assert.equal(receipt.failure, 'mcp_input_validation_failed');
    assert.equal(receipt.calls_sent, 0);
});

test('output schema validation includes an earlier page and retains the rejected original', async () => {
    const receipt = await runMcpOperation(request('bad-output'));
    assert.equal(receipt.protocol_valid, false);
    assert.equal(receipt.calls_sent, 1);
    assert.equal(receipt.response_received, true);
    assert.equal(receipt.result.structuredContent.original, 7);
    assert.equal(receipt.result.content[0].text, original);
});

test('malformed protocol is retained privately and never repaired or called again', async () => {
    const operation = request('malformed');
    operation.timeout_ms = 400;
    const receipt = await runMcpOperation(operation);
    assert.equal(receipt.protocol_valid, false);
    assert.equal(receipt.failure, 'invalid_mcp_protocol');
    assert.equal(receipt.calls_sent, 1);
    assert.equal(receipt.response_received, false);
    assert.match(Buffer.from(receipt.stdout_base64, 'base64').toString(), /BROKEN ORIGINAL/);
});

test('deadline preserves an unknown action outcome and shuts down the child', async () => {
    const operation = request('hang');
    operation.timeout_ms = 400;
    const receipt = await runMcpOperation(operation);
    assert.equal(receipt.failure, 'mcp_deadline_exceeded');
    assert.equal(receipt.calls_sent, 1);
    assert.equal(receipt.response_received, false);
    assert.notEqual(receipt.exit, null);
});

test('server-initiated model sampling is denied without a model request', async () => {
    const receipt = await runMcpOperation(request('sampling'));
    assert.equal(receipt.protocol_valid, true);
    assert.equal(receipt.calls_sent, 1);
    assert.equal(receipt.result.content[0].text, '-32601');
});

test('actual PHP process plan isolates offline sockets and honors explicit online mode', async () => {
    const workspace = await mkdtemp(path.join(os.tmpdir(), 'plos-mcp-network-test-'));
    let connections = 0;
    const server = createServer(socket => { connections++; socket.end(); });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const network of ['offline', 'online']) {
            const operation = request('network-probe', 'probe');
            operation.launch.env.PLOS_MCP_FIXTURE_PORT = String(server.address().port);
            const result = spawnSync('php', ['-r',
                'require "vendor/autoload.php"; $input=json_decode(stream_get_contents(STDIN),true); echo json_encode((new App\\Services\\UnifiedCli\\CliMcpProcessPlan)->build($input["bound"],$input["network"],$input["workspace"]));'],
            { cwd: root, input: JSON.stringify({ bound: { ...operation.launch, network_required: network === 'offline' ? 'none' : 'localhost' }, network, workspace }), encoding: 'utf8' });
            assert.equal(result.status, 0, `PHP process admission failed: ${result.stderr}`);
            operation.launch = JSON.parse(result.stdout);
            const receipt = await runMcpOperation(operation);
            assert.equal(receipt.protocol_valid, true, receipt.failure);
            assert.equal(receipt.result.content[0].text, network === 'offline' ? 'ECONNREFUSED' : 'connected');
            assert.equal(connections, network === 'offline' ? 0 : 1);
        }
    } finally {
        await new Promise(resolve => server.close(resolve));
        await rm(workspace, { recursive: true, force: true });
    }
});


test('a cancelled owner cannot start an MCP child or send a tool call', async () => {
    const controller = new AbortController();
    controller.abort();
    const receipt = await runMcpOperation(request(), { signal: controller.signal });
    assert.equal(receipt.failure, 'mcp_parent_cancelled');
    assert.equal(receipt.calls_sent, 0);
    assert.equal(receipt.exit, null);
    assert.equal(receipt.stdout_base64, '');
});

test('managed parent cancellation and pipe loss stop an in-flight call with exact evidence', async t => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'plos-mcp-managed-'));
    t.after(() => rm(directory, { recursive: true, force: true }));
    for (const cancel of ['message', 'eof']) {
        const marker = path.join(directory, cancel);
        const operation = request('hang');
        operation.timeout_ms = 10000;
        operation.launch.env.PLOS_MCP_FIXTURE_MARKER = marker;
        const child = spawn(process.execPath, [path.join(root, 'scripts/plos-cli/mcp-operation.mjs'), '--managed-stdin'],
            { stdio: ['pipe', 'pipe', 'pipe'] });
        const stdout = [];
        const stderr = [];
        child.stdout.on('data', chunk => stdout.push(chunk));
        child.stderr.on('data', chunk => stderr.push(chunk));
        const closed = new Promise((resolve, reject) => {
            child.once('close', (code, signal) => resolve({ code, signal }));
            child.once('error', reject);
        });
        const timer = setTimeout(() => child.kill('SIGKILL'), 7000);
        let pid;
        try {
            child.stdin.write(`${JSON.stringify(operation)}\n`);
            for (let tries = 0; tries < 100; tries++) {
                pid = await readFile(marker, 'utf8').catch(() => null);
                if (pid !== null) break;
                await new Promise(resolve => setTimeout(resolve, 20));
            }
            assert.notEqual(pid, null, 'actual tool call reached the fixture');
            if (cancel === 'message') child.stdin.write('{"cancel":true}\n');
            else child.stdin.end();
            assert.deepEqual(await closed, { code: 0, signal: null });
            const receipt = JSON.parse(Buffer.concat(stdout).toString('utf8'));
            assert.equal(receipt.failure, 'mcp_parent_cancelled');
            assert.equal(receipt.calls_sent, 1);
            assert.equal(receipt.response_received, false);
            assert.notEqual(receipt.exit, null);
            assert.deepEqual(Buffer.from(receipt.stderr_base64, 'base64'), Buffer.from([0, 255, 10, 32]));
            assert.equal(Buffer.concat(stderr).length, 0);
            assert.throws(() => process.kill(Number(pid), 0), { code: 'ESRCH' });
        } finally {
            clearTimeout(timer);
            if (child.exitCode === null && child.signalCode === null) { child.kill('SIGKILL'); await closed; }
        }
    }
});

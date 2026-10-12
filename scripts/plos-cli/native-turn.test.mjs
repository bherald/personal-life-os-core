import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { gunzipSync, zstdDecompressSync } from 'node:zlib';
import { runNativeTurn } from './native-turn.mjs';

const server = String.raw`
import json, os, sys
def send(value):
    sys.stdout.write(json.dumps(value, ensure_ascii=False)+'\n');sys.stdout.flush()
def event(method, params):send(dict(method=method,params=params))
with open('pid','w') as f:f.write(str(os.getpid()))
for line in sys.stdin:
    request=json.loads(line)
    with open('requests.jsonl','a') as f:f.write(json.dumps(request)+'\n')
    method=request.get('method');params=request.get('params',{})
    if method=='initialize':send(dict(id=request['id'],result=dict(userAgent='owned-fixture')))
    elif method=='thread/start':send(dict(id=request['id'],result=dict(thread=dict(id='thread-1'))))
    elif method=='turn/start':
        send(dict(id=request['id'],result=dict(turn=dict(id='turn-1'))))
        text=params['input'][0]['text']
        mode=os.environ.get('FIXTURE_MODE','completed')
        event('item/agentMessage/delta',dict(threadId='thread-other',turnId='turn-other',itemId='bad',delta='WRONG THREAD'))
        event('item/agentMessage/delta',dict(threadId='thread-1',turnId='turn-1',itemId='message-1',delta=text[:17]))
        event('item/agentMessage/delta',dict(threadId='thread-1',turnId='turn-1',itemId='message-1',delta=text[17:]))
        sys.stderr.buffer.write(b'diagnostics\x00\xff'+b'x'*70000);sys.stderr.buffer.flush()
        if mode=='closed':sys.exit(7)
        if mode=='invalid':sys.stdout.buffer.write(b'not-json\n');sys.stdout.buffer.flush();continue
        if mode=='approval':
            send(dict(id=900,method='item/commandExecution/requestApproval',params=dict(command='must not run')))
            continue
        if mode=='wait':continue
        event('item/completed',dict(threadId='thread-1',turnId='turn-1',item=dict(type='agentMessage',id='message-1',text=text)))
        event('turn/completed',dict(threadId='thread-1',turn=dict(id='turn-1',status=mode,items=[])))
`;

async function fixture(t, mode = 'completed') {
    const directory = await mkdtemp(path.join(tmpdir(), 'plos-native-turn-'));
    await writeFile(path.join(directory, 'server.py'), server);
    t.after(async () => {
        const pid = Number(await readFile(path.join(directory, 'pid'), 'utf8'));
        assert.throws(() => process.kill(pid, 0), { code: 'ESRCH' }, 'Owned native process must be stopped.');
        await rm(directory, { recursive: true, force: true });
    });
    const streams = { stdout: [], stderr: [] }, text = [], events = [];
    return {
        directory, streams, text, events,
        options: {
            executable: '/usr/bin/python3', args: [path.join(directory, 'server.py')], cwd: directory,
            env: { PATH: process.env.PATH, FIXTURE_MODE: mode },
            model: 'synthetic-model', effort: 'medium', sandbox: 'read-only', ephemeral: true,
            developerInstructions: '  instructions\r\n', prompt: '  original input\r\n', timeoutMs: 5000,
            onOutput: (stream, bytes) => streams[stream].push(bytes),
            onText: value => text.push(value), onEvent: (method, params) => events.push({ method, params }),
        },
        async requests() {
            return (await readFile(path.join(directory, 'requests.jsonl'), 'utf8')).trim().split('\n').map(JSON.parse);
        },
    };
}

test('invalid profile names are rejected before native launch', async () => {
    for (const profile of ['', '../work', '--work=other', 'work name', false]) {
        await assert.rejects(runNativeTurn({
            executable: '/must-not-launch', profile, prompt: 'user', developerInstructions: 'instructions',
            timeoutMs: 1000, sandbox: 'read-only',
        }), /valid native configuration profile/);
    }
});

test('native turn keeps large caller instructions separate from exact user text and retains complete output', async t => {
    const f = await fixture(t);
    const developerInstructions = '  '+ 'instructions José 日本語\r\n'.repeat(7000) + '\t END ';
    const prompt = '  '+ 'input José 日本語\r\n'.repeat(7000) + '\t END ';
    const outputSchema = { type: 'object', properties: { answer: { type: 'string' } }, required: ['answer'], additionalProperties: false };
    const result = await runNativeTurn({ ...f.options, developerInstructions, prompt, outputSchema });
    assert.equal(result.success, true, JSON.stringify(result));
    assert.equal(result.response, prompt);
    assert.equal(f.text.at(-1), prompt);
    assert.equal(result.exit.code, 0);
    const requests = await f.requests();
    assert.deepEqual(requests.map(r => r.method), ['initialize', 'initialized', 'thread/start', 'turn/start']);
    const start = requests[2].params;
    assert.equal(start.developerInstructions, developerInstructions);
    assert.equal(Object.hasOwn(start, 'baseInstructions'), false);
    assert.equal(start.approvalPolicy, 'never');
    assert.equal(start.model, 'synthetic-model');
    assert.equal(start.sandbox, 'read-only');
    assert.equal(start.ephemeral, true);
    assert.deepEqual(requests[3].params.input, [{ type: 'text', text: prompt }]);
    assert.deepEqual(requests[3].params.outputSchema, outputSchema);
    assert.deepEqual(Buffer.concat(f.streams.stderr), Buffer.concat([Buffer.from('diagnostics\0'), Buffer.from([255]), Buffer.alloc(70000, 120)]));
    assert.ok(Buffer.concat(f.streams.stdout).includes(Buffer.from('turn/completed')));
});

for (const mode of ['failed', 'closed', 'wait', 'invalid']) {
    test(`native ${mode} retains partial text and stops the owned child without replay`, async t => {
        const f = await fixture(t, mode);
        const result = await runNativeTurn({ ...f.options, timeoutMs: mode === 'wait' ? 500 : 5000 });
        assert.equal(result.success, false);
        assert.equal(result.response, f.options.prompt);
        assert.ok(result.error);
        assert.ok(result.exit);
        if (mode === 'wait') assert.equal(result.status, 'timed_out');
        assert.equal((await f.requests()).filter(r => r.method === 'turn/start').length, 1);
    });
}

test('native turn cancellation denies native approval requests and preserves the available text', async t => {
    const f = await fixture(t, 'approval');
    const abort = new AbortController();
    const resultPromise = runNativeTurn({ ...f.options, signal: abort.signal, onOutput(stream, bytes) {
        f.options.onOutput(stream, bytes);
        if (stream === 'stdout' && bytes.includes(Buffer.from('requestApproval'))) {
            // Give the native denial response time to reach this owned fixture.
            setTimeout(() => abort.abort(), 100);
        }
    } });
    const result = await resultPromise;
    assert.equal(result.status, 'cancelled');
    assert.equal(result.success, false);
    assert.equal(result.response, f.options.prompt);
    const reply = (await f.requests()).find(r => r.id === 900);
    assert.equal(reply.error.code, -32601);
});

test('failure to retain native output cannot be reported as a successful turn', async t => {
    const f = await fixture(t);
    const result = await runNativeTurn({ ...f.options, onOutput(stream, bytes) {
        if (stream === 'stderr') throw new Error('Synthetic full disk');
        f.options.onOutput(stream, bytes);
    } });
    assert.equal(result.success, false);
    assert.ok(result.exit);
});

for (const compact of [false, true]) test(`compatible Codex preserves the selected profile and ${compact ? 'stops context exhaustion before automatic compaction' : 'sends complete caller roles with native guidance intact'}`, {
    skip: !process.env.PLOS_CLI_TEST_NATIVE_TURN_CODEX, timeout: 25000,
}, async t => {
    const directory = await mkdtemp(path.join(tmpdir(), 'plos-native-turn-wire-'));
    const requests = [], paths = [], stderr = [], observed = [];
    const answer = '  response José\r\n'.repeat(5000) + '\t exact tail  ';
    const http = createServer(async (request, response) => {
        paths.push(request.url);
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        let bytes = Buffer.concat(chunks);
        if (request.headers['content-encoding'] === 'gzip') bytes = gunzipSync(bytes);
        if (request.headers['content-encoding'] === 'zstd') bytes = zstdDecompressSync(bytes);
        if (request.url !== '/v1/responses' || request.method !== 'POST') {
            response.writeHead(404); response.end(); return;
        }
        requests.push(JSON.parse(bytes));
        const events = [
            { type: 'response.created', response: { id: 'response-owned' } },
            { type: 'response.output_item.done', item: { type: 'message', role: 'assistant', id: 'message-owned', phase: compact ? 'commentary' : 'final_answer',
                content: [{ type: 'output_text', text: answer }] } },
            // A bounded printf in the owned directory exercises the native tool
            // continuation path where reported usage triggers compaction.
            ...(compact ? [{ type: 'response.output_item.done', item: { type: 'function_call',
                name: 'exec_command', call_id: 'owned-call', arguments: JSON.stringify({ cmd: '/usr/bin/printf context-budget-fixture', max_output_tokens: 1000 }) } }] : []),
            { type: 'response.completed', response: { id: 'response-owned', end_turn: !compact,
                usage: { input_tokens: compact ? 200000 : 100, output_tokens: 10, total_tokens: compact ? 200010 : 110 } } },
        ];
        response.writeHead(200, { 'Content-Type': 'text/event-stream' });
        response.end(events.map(event => `event: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`).join(''));
    });
    t.after(async () => {
        http.closeAllConnections();
        await new Promise(resolve => http.close(resolve));
        await rm(directory, { recursive: true, force: true });
    });
    http.listen(0, '127.0.0.1');
    await once(http, 'listening');
    const skillDirectory = path.join(directory, 'skills', 'full-native-fixture');
    const disabledDirectory = path.join(directory, 'skills', 'disabled-native-fixture');
    await mkdir(skillDirectory, { recursive: true });
    await mkdir(disabledDirectory, { recursive: true });
    const description = 'Full skill description José '.repeat(25) + 'EXACT_DESCRIPTION_TAIL';
    const skillBody = '# Owned fixture\n\n' + '  Skill body José 日本語\r\n'.repeat(900) + '\t EXACT_SKILL_BODY_TAIL  ';
    const skillText = `---\nname: full-native-fixture\ndescription: ${JSON.stringify(description)}\n---\n${skillBody}`;
    await writeFile(path.join(skillDirectory, 'SKILL.md'), skillText);
    await writeFile(path.join(disabledDirectory, 'SKILL.md'), '---\nname: disabled-native-fixture\ndescription: DISABLED_METADATA_MUST_STAY_ABSENT\n---\nDISABLED_BODY_MUST_STAY_ABSENT\n');
    // The base provider is deliberately unusable. Only the selected profile points
    // at the owned fixture; a silently ignored profile therefore cannot pass.
    await writeFile(path.join(directory, 'config.toml'), `model_provider = "unavailable-base"
model = "mock-model"
[features]
plugins = false
recommended_plugins = false
[model_providers.unavailable-base]
name = "Must not be selected"
base_url = "http://127.0.0.1:1/v1"
wire_api = "responses"
requires_openai_auth = false
request_max_retries = 0
stream_max_retries = 0
`);
    const profileText = `model_provider = "fixture"
model = "mock-model"
${compact ? 'model_context_window = 100\nmodel_auto_compact_token_limit = 1' : ''}
[skills]
max_context_tokens = 1
[[skills.config]]
path = ${JSON.stringify(path.join(disabledDirectory, 'SKILL.md'))}
enabled = false
[features]
plugins = false
recommended_plugins = false
[model_providers.fixture]
name = "Owned synthetic provider"
base_url = "http://127.0.0.1:${http.address().port}/v1"
wire_api = "responses"
requires_openai_auth = false
request_max_retries = 0
stream_max_retries = 0
`;
    await writeFile(path.join(directory, 'workflow.config.toml'), profileText);
    const developerInstructions = '  caller instructions José 日本語\r\n'.repeat(6000) + '\t instruction end  ';
    const prompt = '$full-native-fixture\n' + '  user text José 日本語\r\n'.repeat(6000) + '\t user end  ';
    const result = await runNativeTurn({
        executable: process.env.PLOS_CLI_TEST_NATIVE_TURN_CODEX, cwd: directory, profile: 'workflow',
        env: { PATH: process.env.PATH, HOME: directory, CODEX_HOME: directory },
        model: 'mock-model', effort: 'medium', sandbox: 'read-only', ephemeral: true,
        developerInstructions, prompt, timeoutMs: 18000,
        onOutput: (stream, bytes) => { if (stream === 'stderr') stderr.push(bytes); },
        onEvent: (method, params) => observed.push({ method, item: params?.item?.type, exitCode: params?.item?.exitCode,
            output: params?.item?.type === 'commandExecution' ? params.item.aggregatedOutput : undefined, warning: params?.message, usage: params?.tokenUsage,
            status: params?.turn?.status, error: params?.error ?? params?.turn?.error }),
    });
    assert.equal(await readFile(path.join(directory, 'workflow.config.toml'), 'utf8'), profileText);
    assert.ok(result.exit);
    const wire = requests[0];
    const texts = role => wire.input.filter(item => item.role === role)
        .flatMap(item => item.content.filter(part => part.type === 'input_text').map(part => part.text));
    assert.ok(texts('developer').some(text => text.includes(developerInstructions)), 'Complete caller instructions remain in the developer role.');
    assert.ok(texts('user').includes(prompt), 'User input retains its full bytes and role.');
    const allText = wire.input.flatMap(item => item.content ?? []).map(part => part.text ?? '').join('\n');
    assert.ok(allText.includes(description), 'The complete enabled skill description survives a one-token catalog budget.');
    assert.ok(allText.includes(skillBody), 'The complete selected skill body survives beyond the old 8 KB boundary.');
    assert.ok(!allText.includes('DISABLED_METADATA_MUST_STAY_ABSENT'));
    assert.ok(!allText.includes('DISABLED_BODY_MUST_STAY_ABSENT'));
    assert.equal(await readFile(path.join(skillDirectory, 'SKILL.md'), 'utf8'), skillText);
    assert.ok(!observed.some(event => /skills context budget|Skill descriptions were shortened|main prompt context limit/.test(event.warning ?? '')));
    if (compact) {
        assert.equal(result.success, false, JSON.stringify({ resultStatus: result.status, paths, observed,
            tools: requests[0]?.tools?.map(tool => ({ name: tool.name, type: tool.type, tools: tool.tools?.map(t => t.name) })) }));
        assert.match(result.error, /Confirm \/compact/);
        assert.equal(requests.length, 1, 'The original request is not followed by a compacting or continuation request.');
        assert.deepEqual(paths, ['/v1/responses']);
        assert.ok(observed.some(event => event.method === 'item/completed' && event.item === 'commandExecution'
            && event.exitCode === 0 && event.output?.includes('context-budget-fixture')), 'The owned native printf completed successfully.');
        assert.equal(result.response, answer, 'Available response text remains intact after the context stop.');
        return;
    }
    assert.equal(result.success, true, JSON.stringify({ result, stderr: Buffer.concat(stderr).toString() }));
    assert.equal(result.response, answer);
    assert.ok(result.exit);
    assert.equal(requests.length, 1, 'A single request, with no retry or compaction call.');
    assert.equal(wire.model, 'mock-model');
    assert.ok(typeof wire.instructions === 'string' && wire.instructions.length > 100, 'Native base guidance remains present.');
});

for (const exitCode of [0, 7]) test(`compatible Codex preserves large native command output through the next model request after exit ${exitCode}`, {
    skip: !process.env.PLOS_CLI_TEST_NATIVE_TURN_CODEX, timeout: 30000,
}, async t => {
    const directory = await mkdtemp(path.join(tmpdir(), 'plos-native-output-wire-'));
    const expected = Array.from({ length: 40000 }, (_, i) => `${i}: full output José 日本語\t unique row ${i}\r\n`).join('') + ' EXACT_OUTPUT_TAIL  ';
    assert.ok(Buffer.byteLength(expected) > 2 * 1024 * 1024);
    const requests = [], observed = [], errors = [];
    const http = createServer(async (request, response) => {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        let bytes = Buffer.concat(chunks);
        if (request.headers['content-encoding'] === 'gzip') bytes = gunzipSync(bytes);
        if (request.headers['content-encoding'] === 'zstd') bytes = zstdDecompressSync(bytes);
        if (request.url !== '/v1/responses' || request.method !== 'POST') {
            response.writeHead(404); response.end(); return;
        }
        requests.push(JSON.parse(bytes));
        const first = requests.length === 1;
        const events = [
            { type: 'response.created', response: { id: `output-response-${requests.length}` } },
            { type: 'response.output_item.done', item: first ? {
                type: 'function_call', name: 'exec_command', call_id: 'full-output-call',
                arguments: JSON.stringify({ cmd: '/usr/bin/python3 emit.py', max_output_tokens: 1, yield_time_ms: 1000 }),
            } : {
                type: 'message', role: 'assistant', id: 'output-answer', phase: 'final_answer',
                content: [{ type: 'output_text', text: 'Owned output received.' }],
            } },
            { type: 'response.completed', response: { id: `output-response-${requests.length}`, end_turn: !first,
                usage: { input_tokens: 100, output_tokens: 10, total_tokens: 110 } } },
        ];
        response.writeHead(200, { 'Content-Type': 'text/event-stream' });
        response.end(events.map(event => `event: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`).join(''));
    });
    t.after(async () => {
        http.closeAllConnections();
        await new Promise(resolve => http.close(resolve));
        await rm(directory, { recursive: true, force: true });
    });
    http.listen(0, '127.0.0.1');
    await once(http, 'listening');
    await writeFile(path.join(directory, 'payload.txt'), expected);
    await writeFile(path.join(directory, 'emit.py'), `from pathlib import Path\nimport sys\nsys.stdout.buffer.write(Path('payload.txt').read_bytes())\nsys.stdout.buffer.flush()\nsys.exit(${exitCode})\n`);
    // Unknown native model metadata caps context at 272k. This owned provider
    // explicitly advertises enough context for the full multi-megabyte result.
    await writeFile(path.join(directory, 'models.json'), JSON.stringify({ models: [{
        slug: 'mock-model', display_name: 'Owned large-output model',
        model_messages: { instructions_template: 'Run the owned output fixture and preserve its result.' },
        supported_reasoning_levels: [], shell_type: 'unified_exec', visibility: 'list',
        supported_in_api: true, priority: 1, support_verbosity: false,
        truncation_policy: { mode: 'tokens', limit: 1 }, experimental_supported_tools: [],
        context_window: 2000000, max_context_window: 2000000,
    }] }));
    await writeFile(path.join(directory, 'config.toml'), `model_catalog_json = ${JSON.stringify(path.join(directory, 'models.json'))}
model_provider = "fixture"
model = "mock-model"
model_context_window = 2000000
model_auto_compact_token_limit = 1900000
tool_output_token_limit = 1
[features]
plugins = false
recommended_plugins = false
[model_providers.fixture]
name = "Owned output fixture"
base_url = "http://127.0.0.1:${http.address().port}/v1"
wire_api = "responses"
requires_openai_auth = false
request_max_retries = 0
stream_max_retries = 0
`);
    const result = await runNativeTurn({
        executable: process.env.PLOS_CLI_TEST_NATIVE_TURN_CODEX, cwd: directory,
        env: { PATH: process.env.PATH, HOME: directory, CODEX_HOME: directory },
        model: 'mock-model', effort: 'medium', sandbox: 'read-only', ephemeral: true,
        developerInstructions: 'Preserve the complete owned tool result.', prompt: 'Read payload.txt.', timeoutMs: 23000,
        onOutput: (stream, bytes) => { if (stream === 'stderr') errors.push(bytes); },
        onEvent: (method, params) => { if (method === 'item/completed' && params.item?.type === 'commandExecution') observed.push(params.item); },
    });
    assert.equal(result.success, true, JSON.stringify({ status: result.status, error: result.error, stderr: Buffer.concat(errors).toString() }));
    assert.ok(result.exit, 'The owned native child has stopped.');
    assert.equal(result.response, 'Owned output received.');
    assert.equal(requests.length, 2, 'One tool turn followed by one final answer, without retries or compaction.');
    const output = requests[1].input.find(item => item.type === 'function_call_output' && item.call_id === 'full-output-call')?.output;
    const text = typeof output === 'string' ? output : (output ?? []).map(part => part.text ?? '').join('');
    assert.ok(text.includes(expected), `Full ${Buffer.byteLength(expected)}-byte output must survive collection, formatting and history; received ${Buffer.byteLength(text)} bytes. ${text.length < 1000 ? text : ""}`);
    assert.ok(!text.includes('Warning: truncated output') && !/\.\.\. \d+ bytes omitted \.\.\./.test(text));
    assert.ok(observed.some(item => item.exitCode === exitCode), 'The actual native command reports its exact exit code.');
    assert.ok(text.includes(`Process exited with code ${exitCode}`));
    assert.ok(observed.some(item => item.aggregatedOutput === expected), 'The complete command output also reaches the retained native CLI event.');
    assert.equal(await readFile(path.join(directory, 'payload.txt'), 'utf8'), expected, 'Input file remains unchanged.');
});

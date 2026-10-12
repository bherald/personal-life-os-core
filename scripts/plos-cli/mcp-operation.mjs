import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { runManagedInput } from './managed-input.mjs';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { AjvJsonSchemaValidator } from '@modelcontextprotocol/sdk/validation/ajv';
import { JSONRPCMessageSchema } from '@modelcontextprotocol/sdk/types.js';

// This transport supplies byte custody around the installed official SDK's
// negotiation, request matching, cancellation and result validation. Credentials
// belong only in launch.env; the returned evidence must stay in private storage.
class CapturedStdioTransport {
    constructor(launch) {
        this.launch = launch;
        this.stdout = [];
        this.stderr = [];
        this.buffer = Buffer.alloc(0);
        this.requests = new Map();
        this.responses = [];
        this.callsSent = 0;
        this.protocolError = false;
    }

    async start() {
        if (this.child) throw new Error('transport_already_started');
        this.child = spawn(this.launch.command, this.launch.args, {
            cwd: this.launch.cwd, env: this.launch.env,
            stdio: ['pipe', 'pipe', 'pipe'], shell: false, windowsHide: true,
        });
        this.closed = new Promise(resolve => this.child.once('close', (code, signal) => {
            this.exit = { code, signal };
            if (this.buffer.length) this.protocolError = true;
            this.onclose?.();
            resolve();
        }));
        this.child.stderr.on('data', bytes => this.stderr.push(Buffer.from(bytes)));
        this.child.stdout.on('data', bytes => {
            this.stdout.push(Buffer.from(bytes));
            this.buffer = Buffer.concat([this.buffer, bytes]);
            let end;
            while ((end = this.buffer.indexOf(10)) !== -1) {
                const line = this.buffer.subarray(0, end);
                this.buffer = this.buffer.subarray(end + 1);
                try {
                    const message = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(line));
                    JSONRPCMessageSchema.parse(message);
                    if (Object.hasOwn(message, 'id') && !Object.hasOwn(message, 'method')) {
                        const method = this.requests.get(message.id);
                        if (!method) throw new Error('unexpected_response');
                        this.requests.delete(message.id);
                        this.responses.push({ method, message });
                    }
                    this.onmessage?.(message);
                } catch {
                    this.protocolError = true;
                    this.onerror?.(new Error('invalid_mcp_protocol'));
                }
            }
        });
        this.child.stdin.on('error', () => this.onerror?.(new Error('mcp_input_closed')));
        this.child.on('error', () => this.onerror?.(new Error('mcp_process_failed')));
        await once(this.child, 'spawn');
        if (this.launch.windows_plan !== undefined) {
            // Complete launch metadata before the SDK sends initialize. Secrets
            // cross only this pipe, never argv, environment or a plan file.
            const prefix = encodeWindowsLaunchPlan(this.launch.windows_plan);
            await new Promise((resolve, reject) => this.child.stdin.write(prefix,
                error => error ? reject(new Error('mcp_launch_input_failed')) : resolve()));
        }
    }

    async send(message) {
        if (this.protocolError) throw new Error('invalid_mcp_protocol');
        if (Object.hasOwn(message, 'id') && message.method) {
            this.requests.set(message.id, message.method);
            if (message.method === 'tools/call') this.callsSent++;
        }
        if (!this.child.stdin.write(`${JSON.stringify(message)}\n`)) await once(this.child.stdin, 'drain');
    }

    async close() {
        if (!this.child || this.exit) return;
        this.child.stdin.end();
        const wait = async () => {
            let timer;
            try { await Promise.race([this.closed, new Promise(resolve => { timer = setTimeout(resolve, 500); })]); }
            finally { clearTimeout(timer); }
        };
        await wait();
        if (!this.exit) { this.child.kill('SIGTERM'); await wait(); }
        if (!this.exit) { this.child.kill('SIGKILL'); await wait(); }
        if (!this.exit) throw new Error('mcp_process_shutdown_unconfirmed');
    }
}

function validateLaunch(launch) {
    if (!launch || typeof launch.command !== 'string' || !launch.command || !Array.isArray(launch.args)
        || launch.args.some(arg => typeof arg !== 'string' || arg.includes('\0'))
        || typeof launch.cwd !== 'string' || !launch.cwd || !launch.env || Array.isArray(launch.env)
        || Object.entries(launch.env).some(([key, value]) => !/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)
            || typeof value !== 'string' || value.includes('\0'))) throw new Error('invalid_mcp_launch');
    if (launch.windows_plan !== undefined) {
        if (launch.args.length !== 1 || launch.args[0] !== '--plan-stdin') throw new Error('invalid_windows_launch');
        encodeWindowsLaunchPlan(launch.windows_plan);
    }
}

export function encodeWindowsLaunchPlan(plan) {
    if (!plan || plan.schema !== 'plos.cli.windows-process.v1' || Array.isArray(plan)) {
        throw new Error('invalid_windows_launch');
    }
    const bytes = Buffer.from(JSON.stringify(plan), 'utf8');
    if (bytes.length === 0 || bytes.length > 16 * 1024 * 1024) throw new Error('windows_launch_metadata_too_large');
    const header = Buffer.alloc(4);
    header.writeUInt32LE(bytes.length);
    return Buffer.concat([header, bytes]);
}

/** One discovery/call transaction. The caller must supply an admitted OS launch plan. */
export async function runMcpOperation({ launch, tool = null, timeout_ms = 30000 }, { signal } = {}) {
    validateLaunch(launch);
    if (!Number.isInteger(timeout_ms) || timeout_ms < 50 || timeout_ms > 3600000) throw new Error('invalid_mcp_timeout');
    if (tool !== null && (!tool || typeof tool.name !== 'string' || !tool.name || !tool.arguments
        || typeof tool.arguments !== 'object' || Array.isArray(tool.arguments))) throw new Error('invalid_mcp_call');
    const transport = new CapturedStdioTransport(launch);
    const client = new Client({ name: 'plos-unified-cli', version: '0.1.0' }, { capabilities: {} });
    const controller = new AbortController();
    const cancel = () => controller.abort();
    signal?.addEventListener('abort', cancel, { once: true });
    if (signal?.aborted) cancel();
    const timer = setTimeout(() => controller.abort(), timeout_ms);
    const options = { signal: controller.signal, timeout: timeout_ms, maxTotalTimeout: timeout_ms, resetTimeoutOnProgress: false };
    const receipt = { schema: 'plos.cli.mcp-operation.v1', protocol_valid: false,
        stage: 'initialize', tools: [], result: null, calls_sent: 0, response_received: false };
    try {
        controller.signal.throwIfAborted();
        await client.connect(transport, options);
        receipt.server = client.getServerVersion();
        receipt.instructions = client.getInstructions() ?? null;
        receipt.capabilities = client.getServerCapabilities();
        receipt.stage = 'discovery';
        const cursors = new Set();
        const names = new Set();
        let cursor;
        do {
            const page = await client.listTools(cursor === undefined ? {} : { cursor }, options);
            for (const definition of page.tools) {
                if (names.has(definition.name)) throw new Error('duplicate_mcp_tool');
                names.add(definition.name);
                receipt.tools.push(definition);
            }
            cursor = page.nextCursor;
            if (cursor !== undefined && cursors.has(cursor)) throw new Error('repeated_mcp_cursor');
            cursors.add(cursor);
        } while (cursor !== undefined);
        if (tool !== null) {
            receipt.stage = 'input_validation';
            const definition = receipt.tools.find(item => item.name === tool.name);
            if (!definition) throw new Error('mcp_tool_not_advertised');
            // No defaults, coercion or removal of extra fields is configured.
            const validator = new AjvJsonSchemaValidator();
            if (!validator.getValidator(definition.inputSchema)(tool.arguments).valid) throw new Error('mcp_input_schema_rejected');
            // listTools caches only its most recent page; restore all pages so
            // output validation and task capability checks cover the selected tool.
            client.cacheToolMetadata(receipt.tools);
            receipt.stage = 'call';
            await client.callTool(tool, undefined, options);
        }
        if (transport.protocolError) throw new Error('invalid_mcp_protocol');
        receipt.protocol_valid = true;
        receipt.stage = 'complete';
    } catch {
        // Error text may contain remote data or credentials. Full received bytes
        // remain in private evidence; expose a controlled stage/code only.
        receipt.failure = signal?.aborted ? 'mcp_parent_cancelled'
            : controller.signal.aborted ? 'mcp_deadline_exceeded' : `mcp_${receipt.stage}_failed`;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', cancel);
        try { await client.close(); } catch { receipt.failure = 'mcp_shutdown_failed'; receipt.protocol_valid = false; }
        receipt.calls_sent = transport.callsSent;
        const response = transport.responses.find(item => item.method === 'tools/call')?.message;
        receipt.response_received = response !== undefined;
        receipt.result = response?.result ?? null;
        receipt.rpc_error = response?.error ?? null;
        receipt.stdout_base64 = Buffer.concat(transport.stdout).toString('base64');
        receipt.stderr_base64 = Buffer.concat(transport.stderr).toString('base64');
        receipt.exit = transport.exit ?? null;
        if (transport.protocolError) { receipt.protocol_valid = false; receipt.failure = 'invalid_mcp_protocol'; }
    }
    return receipt;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    if (process.argv[2] === '--managed-stdin') {
        await runManagedInput(runMcpOperation);
    } else {
        try {
            const chunks = [];
            for await (const chunk of process.stdin) chunks.push(chunk);
            const request = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            const receipt = await runMcpOperation(request);
            process.stdout.write(`${JSON.stringify(receipt)}\n`);
        } catch {
            process.stderr.write('MCP operation could not start.\n');
            process.exitCode = 1;
        }
    }
}

import assert from 'node:assert/strict';
import { access, readFile } from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
const executablePath = process.env.PUPPETEER_EXECUTABLE_PATH;

test('locked Puppeteer MCP preserves its seven tools with maintained Puppeteer', {
    skip: !executablePath && 'Set PUPPETEER_EXECUTABLE_PATH to an installed system browser.',
    timeout: 60000,
}, async () => {
    await access(executablePath, constants.X_OK);
    const manifest = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'));
    const installed = JSON.parse(await readFile(path.join(root, 'node_modules/puppeteer/package.json'), 'utf8'));
    assert.equal(installed.version, manifest.overrides.puppeteer);
    const transport = new StdioClientTransport({
        command: process.execPath,
        args: [path.join(root, 'node_modules/@modelcontextprotocol/server-puppeteer/dist/index.js')],
        cwd: root,
        env: {
            PATH: process.env.PATH,
            HOME: process.env.HOME,
            PUPPETEER_EXECUTABLE_PATH: executablePath,
            PUPPETEER_LAUNCH_OPTIONS: JSON.stringify({
                headless: true,
                args: ['--disable-background-networking'],
            }),
        },
        stderr: 'pipe',
    });
    const client = new Client({ name: 'plos-browser-maintenance-test', version: '1.0.0' });
    try {
        await client.connect(transport);
        const { tools } = await client.listTools();
        const required = {
            puppeteer_navigate: ['url'], puppeteer_screenshot: ['name'],
            puppeteer_click: ['selector'], puppeteer_fill: ['selector', 'value'],
            puppeteer_select: ['selector', 'value'], puppeteer_hover: ['selector'],
            puppeteer_evaluate: ['script'],
        };
        assert.deepEqual(tools.map(({ name }) => name).sort(), Object.keys(required).sort());
        for (const tool of tools) assert.deepEqual(tool.inputSchema.required, required[tool.name]);
        const call = async (name, args) => {
            const result = await client.callTool({ name, arguments: args });
            assert.equal(result.isError, false, `${name}: ${JSON.stringify(result.content).slice(0, 500)}`);
            return result;
        };
        const html = '<!doctype html><title>PLOS maintenance</title><input id="text"><select id="choice"><option value="a">A</option><option value="b">B</option></select><button id="button" onclick="this.dataset.clicked=\'yes\'">Test</button>';
        await call('puppeteer_navigate', { url: `data:text/html,${encodeURIComponent(html)}` });
        await call('puppeteer_fill', { selector: '#text', value: 'MCP compatibility' });
        await call('puppeteer_select', { selector: '#choice', value: 'b' });
        await call('puppeteer_hover', { selector: '#button' });
        await call('puppeteer_click', { selector: '#button' });
        const evaluated = await call('puppeteer_evaluate', {
            script: '({text:document.querySelector("#text").value,choice:document.querySelector("#choice").value,clicked:document.querySelector("#button").dataset.clicked})',
        });
        assert.match(evaluated.content[0].text, /"text": "MCP compatibility"/);
        assert.match(evaluated.content[0].text, /"choice": "b"/);
        assert.match(evaluated.content[0].text, /"clicked": "yes"/);
        const screenshot = await call('puppeteer_screenshot', { name: 'maintenance', width: 400, height: 300 });
        const image = screenshot.content.find(({ type }) => type === 'image');
        assert.equal(image.mimeType, 'image/png');
        assert.equal(Buffer.from(image.data, 'base64').subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
        const encoded = await call('puppeteer_screenshot', { name: 'encoded', selector: '#button', encoded: true });
        assert.ok(encoded.content.some(({ text }) => text?.startsWith('data:image/png;base64,')));
        const resources = await client.listResources();
        assert.ok(resources.resources.some(({ uri }) => uri === 'screenshot://maintenance'));
        const resource = await client.readResource({ uri: 'screenshot://maintenance' });
        assert.equal(resource.contents[0].blob, image.data);
        await assert.rejects(
            client.callTool({ name: 'puppeteer_navigate', arguments: { url: 'about:blank', launchOptions: { args: ['--no-sandbox'] } } }),
            /Dangerous browser arguments/,
        );
    } finally {
        await client.close();
    }
});

test('existing CommonJS stealth client remains compatible', {
    skip: !executablePath && 'Set PUPPETEER_EXECUTABLE_PATH to an installed system browser.',
    timeout: 30000,
}, async () => {
    const { createRequire } = await import('node:module');
    const require = createRequire(import.meta.url);
    const puppeteer = require('puppeteer-extra');
    puppeteer.use(require('puppeteer-extra-plugin-stealth')());
    const browser = await puppeteer.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage();
        await page.goto('data:text/html,<title>PLOS compatibility</title>');
        assert.equal(await page.title(), 'PLOS compatibility');
        assert.equal(typeof await page.screenshot({ encoding: 'base64' }), 'string');
    } finally {
        await browser.close();
    }
});

test('HTTP browser service reports health and reuses the browser after page close', {
    skip: !executablePath && 'Set PUPPETEER_EXECUTABLE_PATH to an installed system browser.',
    timeout: 30000,
}, async () => {
    const { spawn } = await import('node:child_process');
    const { randomBytes } = await import('node:crypto');
    const { mkdtemp, rm } = await import('node:fs/promises');
    const { createServer } = await import('node:net');
    const { tmpdir } = await import('node:os');
    const { setTimeout: delay } = await import('node:timers/promises');
    const reservation = createServer();
    await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
    const { port } = reservation.address();
    await new Promise(resolve => reservation.close(resolve));
    const screenshots = await mkdtemp(path.join(tmpdir(), 'plos-browser-compat-'));
    const token = randomBytes(32).toString('hex');
    const child = spawn(process.execPath, [path.join(root, 'scripts/browser-server/puppeteer-server.cjs'), String(port)], {
        cwd: root,
        env: {
            PATH: process.env.PATH,
            HOME: process.env.HOME,
            PUPPETEER_EXECUTABLE_PATH: executablePath,
            BROWSER_AUTOMATION_TOKEN: token,
            SCREENSHOT_DIR: screenshots,
        },
        stdio: ['ignore', 'ignore', 'pipe'],
    });
    let diagnostic = '';
    child.stderr.on('data', data => { diagnostic = (diagnostic + data).slice(-2000); });
    const exited = new Promise(resolve => child.once('exit', resolve));
    const request = async (route, body) => {
        const response = await fetch(`http://127.0.0.1:${port}${route}`, {
            method: body === undefined ? 'GET' : 'POST',
            headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: AbortSignal.timeout(10000),
        });
        const value = await response.json();
        assert.equal(response.status, 200, `${route}: ${JSON.stringify(value)} ${diagnostic}`);
        return value;
    };
    try {
        let started = false;
        for (let attempt = 0; attempt < 50; attempt++) {
            if (child.exitCode !== null) break;
            try {
                const health = await request('/health');
                assert.equal(health.browser, 'disconnected');
                started = true;
                break;
            } catch (error) {
                if (error.code === 'ERR_ASSERTION') throw error;
                await delay(100);
            }
        }
        assert.ok(started, `HTTP service did not start: ${diagnostic}`);
        const first = await request('/screenshot', { name: 'first' });
        assert.equal(first.success, true);
        assert.equal(Buffer.from(first.base64, 'base64').subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
        const active = await request('/health');
        assert.equal(active.browser, 'connected');
        assert.equal(active.page, 'active');
        assert.equal(active.security.sandboxEnabled, true);
        assert.equal(active.security.authRequired, true);
        await request('/close', {});
        const closedPage = await request('/health');
        assert.equal(closedPage.browser, 'connected');
        assert.equal(closedPage.page, 'none');
        assert.equal((await request('/screenshot', { name: 'reused' })).success, true);
        const reused = await request('/health');
        assert.equal(reused.browser, 'connected');
        assert.equal(reused.page, 'active');
        assert.equal(reused.security.sessionCount, active.security.sessionCount + 1);
    } finally {
        if (child.exitCode === null) {
            await request('/shutdown', {}).catch(() => {});
            await Promise.race([exited, delay(3000)]);
            if (child.exitCode === null) child.kill('SIGTERM');
            await exited;
        }
        await rm(screenshots, { recursive: true, force: true });
    }
});

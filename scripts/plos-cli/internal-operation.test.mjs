import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInternalOperation } from './internal-operation.mjs';

const original = '  José\r\n\tOriginal tail  '.repeat(5000);
function request(delay = 0) {
    return { command: process.execPath, args: ['-e', `
        const chunks = [];
        process.stdin.on('data', bytes => chunks.push(bytes));
        process.stdin.on('end', () => {
            process.stderr.write(Buffer.from([0, 255, 10, 32]));
            process.stdout.write(JSON.stringify({pid: process.pid})+'\\n');
            setTimeout(() => process.stdout.write(Buffer.concat(chunks)), ${delay});
        });`], cwd: process.cwd(), env: {}, input: original, timeout_ms: 3000 };
}
function checkStopped(receipt) {
    const pid = JSON.parse(Buffer.from(receipt.stdout_base64, 'base64').toString().split('\n')[0]).pid;
    assert.throws(() => process.kill(pid, 0), error => error.code === 'ESRCH');
}
test('blocking adapter preserves all input/output bytes and confirms child exit', async () => {
    const receipt = await runInternalOperation(request(200));
    assert.equal(receipt.completed, true);
    const bytes = Buffer.from(receipt.stdout_base64, 'base64');
    assert.deepEqual(bytes.subarray(bytes.indexOf(10) + 1), Buffer.from(original));
    assert.deepEqual(Buffer.from(receipt.stderr_base64, 'base64'), Buffer.from([0, 255, 10, 32]));
    checkStopped(receipt);
});
test('deadline stops a silent child and retains partial output without replay', async () => {
    const receipt = await runInternalOperation({ ...request(10000), timeout_ms: 700 });
    assert.equal(receipt.completed, false);
    assert.equal(receipt.cancelled, true);
    checkStopped(receipt);
    assert.equal(Buffer.from(receipt.stdout_base64, 'base64').toString().split('\n').length, 2);
});
test('custody loss before start launches no process', async () => {
    const controller = new AbortController();
    controller.abort();
    const receipt = await runInternalOperation(request(), { signal: controller.signal });
    assert.equal(receipt.started, false);
    assert.equal(receipt.exit, null);
});
test('cancellation stops an adapter descendant as well as the immediate child', async () => {
    const operation = { ...request(), timeout_ms: 900 };
    operation.args = ['-e', `
        const {spawn} = require('node:child_process');
        const sub = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)'], {stdio: 'ignore'});
        sub.once('spawn', () => process.stdout.write(JSON.stringify({pid:process.pid, descendant:sub.pid})+'\\n'));
        setInterval(()=>{},1000);`];
    const receipt = await runInternalOperation(operation);
    assert.equal(receipt.cancelled, true);
    assert.equal(receipt.failure, undefined);
    checkStopped(receipt);
    const pid = JSON.parse(Buffer.from(receipt.stdout_base64, 'base64').toString()).descendant;
    try {
        process.kill(pid, 0);
        // A killed orphan may await Linux init reaping; it is already terminal.
        assert.equal(process.platform, 'linux');
        assert.match(readFileSync(`/proc/${pid}/stat`, 'utf8'), /\) Z /);
    } catch (error) {
        if (error.code !== 'ESRCH' && error.code !== 'ENOENT') throw error;
    }
});
for (const mode of ['cancel', 'eof']) {
    test(`managed parent ${mode} stops the blocking adapter and retains evidence`, async () => {
        const worker = spawn(process.execPath, [fileURLToPath(new URL('./internal-operation.mjs', import.meta.url))],
            { stdio: ['pipe', 'pipe', 'pipe'] });
        const chunks = [];
        worker.stdout.on('data', data => chunks.push(data));
        const done = once(worker, 'close');
        worker.stdin.write(`${JSON.stringify(request(10000))}\n`);
        const timer = setTimeout(() => mode === 'eof' ? worker.stdin.end() : worker.stdin.write('{"cancel":true}\n'), 900);
        try {
            const [code] = await done;
            assert.equal(code, 0);
            const receipt = JSON.parse(Buffer.concat(chunks).toString());
            assert.equal(receipt.cancelled, true);
            assert.equal(receipt.completed, false);
            checkStopped(receipt);
        } finally { clearTimeout(timer); }
    });
}

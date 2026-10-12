import { createInterface } from 'node:readline';

// The parent owns this private pipe. No control frame reaches the MCP server.
export async function runManagedInput(operation) {
    const lines = createInterface({ input: process.stdin, crlfDelay: Infinity });
    const controller = new AbortController();
    let started = false;
    let finished = false;
    await new Promise(resolve => {
        lines.on('line', line => {
            if (started) {
                // Any unexpected parent input also cancels; it cannot request another call.
                controller.abort();
                return;
            }
            started = true;
            (async () => {
                try {
                    const request = JSON.parse(line);
                    const receipt = await operation(request, { signal: controller.signal });
                    process.stdout.write(`${JSON.stringify(receipt)}\n`);
                } catch {
                    process.stderr.write('MCP operation could not start.\n');
                    process.exitCode = 1;
                } finally {
                    finished = true;
                    lines.close();
                    process.stdin.destroy();
                    resolve();
                }
            })();
        });
        lines.on('close', () => {
            if (!finished) controller.abort();
            if (!started) { process.exitCode = 1; resolve(); }
        });
        process.stdin.on('error', () => controller.abort());
    });
}

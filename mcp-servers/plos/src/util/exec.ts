import { exec, type ExecOptions } from 'child_process';

export interface ExecCommandResult {
  stdout: string;
  stderr: string;
}

export interface ExecCommandError extends Error {
  code?: number | string | null;
  killed?: boolean;
  signal?: NodeJS.Signals | null;
  stdout?: string;
  stderr?: string;
}

export function execCommand(
  command: string,
  options: ExecOptions & { signal?: AbortSignal } = {},
): Promise<ExecCommandResult> {
  const { signal, ...execOptions } = options;

  return new Promise((resolve, reject) => {
    let aborted = false;

    const cleanup = () => {
      if (signal) {
        signal.removeEventListener('abort', onAbort);
      }
    };

    const child = exec(command, execOptions, (error, stdout, stderr) => {
      cleanup();
      const stdoutText = typeof stdout === 'string' ? stdout : stdout.toString('utf8');
      const stderrText = typeof stderr === 'string' ? stderr : stderr.toString('utf8');

      if (aborted) {
        const abortError = new Error('Command aborted') as ExecCommandError;
        abortError.name = 'AbortError';
        abortError.killed = true;
        abortError.stdout = stdoutText;
        abortError.stderr = stderrText;
        reject(abortError);
        return;
      }

      if (error) {
        const execError = error as ExecCommandError;
        execError.stdout = stdoutText;
        execError.stderr = stderrText;
        reject(execError);
        return;
      }

      resolve({ stdout: stdoutText, stderr: stderrText });
    });

    const onAbort = () => {
      aborted = true;
      child.kill('SIGTERM');
      const killTimer = setTimeout(() => {
        child.kill('SIGKILL');
      }, 1000);
      if ('unref' in killTimer) killTimer.unref();
    };

    if (signal) {
      if (signal.aborted) {
        onAbort();
      } else {
        signal.addEventListener('abort', onAbort, { once: true });
      }
    }
  });
}

export class ToolTimeoutError extends Error {
  constructor(toolName: string, timeoutMs: number) {
    super(`Tool "${toolName}" timed out after ${timeoutMs / 1000}s`);
    this.name = 'ToolTimeoutError';
  }
}

export class ToolCancelledError extends Error {
  constructor(toolName: string) {
    super(`Tool "${toolName}" cancelled by client`);
    this.name = 'ToolCancelledError';
  }
}

type LifecycleOptions = {
  toolName: string;
  timeoutMs: number;
  clientSignal?: AbortSignal;
};

export async function runToolWithLifecycle<T>(
  handler: (signal: AbortSignal) => Promise<T>,
  options: LifecycleOptions,
): Promise<T> {
  const controller = new AbortController();
  if (options.clientSignal?.aborted) {
    controller.abort();
    throw new ToolCancelledError(options.toolName);
  }

  let timeout: ReturnType<typeof setTimeout> | undefined;
  let rejectCancellation: ((reason: ToolCancelledError) => void) | undefined;

  const cancellationPromise = new Promise<never>((_resolve, reject) => {
    rejectCancellation = reject;
  });

  const cancelFromClient = () => {
    rejectCancellation?.(new ToolCancelledError(options.toolName));
    controller.abort();
  };

  options.clientSignal?.addEventListener('abort', cancelFromClient, { once: true });

  const timeoutPromise = new Promise<never>((_resolve, reject) => {
    timeout = setTimeout(() => {
      reject(new ToolTimeoutError(options.toolName, options.timeoutMs));
      controller.abort();
    }, options.timeoutMs);
    if (typeof timeout === 'object' && 'unref' in timeout) timeout.unref();
  });

  try {
    return await Promise.race([
      handler(controller.signal),
      timeoutPromise,
      cancellationPromise,
    ]);
  } finally {
    if (timeout !== undefined) clearTimeout(timeout);
    options.clientSignal?.removeEventListener('abort', cancelFromClient);
  }
}

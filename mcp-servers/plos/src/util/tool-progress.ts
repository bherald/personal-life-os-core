import type { ProgressNotification } from '@modelcontextprotocol/sdk/types.js';

type ProgressToken = string | number;

type ToolProgressOptions = {
  progressToken?: ProgressToken;
  signal: AbortSignal;
  heartbeatMs: number;
  sendNotification: (notification: ProgressNotification) => Promise<void>;
};

export async function runToolWithProgress<T>(
  handler: () => Promise<T>,
  options: ToolProgressOptions,
): Promise<T> {
  if (options.progressToken === undefined) {
    return handler();
  }

  const startedAt = Date.now();
  let accepting = true;
  let lastProgress = 0;
  let notificationChain = Promise.resolve();

  const elapsedSeconds = (): number => {
    const elapsed = Math.max(0, (Date.now() - startedAt) / 1000);
    lastProgress = Math.max(lastProgress, Math.round(elapsed * 1000) / 1000);

    return lastProgress;
  };

  const enqueue = (message: 'started' | 'running' | 'completed' | 'failed'): Promise<void> => {
    const notification: ProgressNotification = {
      method: 'notifications/progress',
      params: {
        progressToken: options.progressToken as ProgressToken,
        progress: elapsedSeconds(),
        message,
      },
    };

    notificationChain = notificationChain
      .then(() => options.sendNotification(notification))
      .catch(() => undefined);

    return notificationChain;
  };

  await enqueue('started');

  const heartbeat = setInterval(() => {
    if (accepting) void enqueue('running');
  }, options.heartbeatMs);
  if (typeof heartbeat === 'object' && 'unref' in heartbeat) heartbeat.unref();

  const stop = () => {
    accepting = false;
    clearInterval(heartbeat);
  };
  options.signal.addEventListener('abort', stop, { once: true });

  try {
    const result = await handler();
    const shouldReport = ! options.signal.aborted;
    stop();
    if (shouldReport) await enqueue('completed');

    return result;
  } catch (error) {
    const shouldReport = ! options.signal.aborted;
    stop();
    if (shouldReport) await enqueue('failed');
    throw error;
  } finally {
    options.signal.removeEventListener('abort', stop);
  }
}

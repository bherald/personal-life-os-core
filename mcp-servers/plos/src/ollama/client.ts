import { CONFIG } from '../config.js';
import { logger } from '../util/logger.js';

export interface OllamaModel {
  name: string;
  size: number;
  modified_at: string;
}

export interface OllamaGenerateResponse {
  model: string;
  response: string;
  done: boolean;
  total_duration?: number;
  eval_count?: number;
}

let modelCache: OllamaModel[] | null = null;

/**
 * Create an AbortSignal that fires after `ms` milliseconds.
 */
function timeoutSignal(ms: number): AbortSignal {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), ms);
  // Unref so the timer doesn't keep the process alive
  if (typeof timer === 'object' && 'unref' in timer) timer.unref();
  return controller.signal;
}

/**
 * Discover available models from Ollama API
 */
export async function discoverModels(): Promise<OllamaModel[]> {
  if (modelCache) return modelCache;

  try {
    const resp = await fetch(`${CONFIG.ollama.host}/api/tags`, {
      signal: timeoutSignal(CONFIG.ollama.discoverTimeoutMs),
    });
    if (!resp.ok) throw new Error(`Ollama tags: ${resp.status}`);

    const data = await resp.json() as { models: OllamaModel[] };
    modelCache = data.models;
    logger.info('Ollama models discovered', { count: modelCache.length });
    return modelCache;
  } catch (err) {
    const msg = (err as Error).name === 'AbortError'
      ? `Ollama model discovery timed out after ${CONFIG.ollama.discoverTimeoutMs}ms`
      : (err as Error).message;
    logger.error('Ollama model discovery failed', { error: msg });
    return [];
  }
}

export function clearModelCache(): void {
  modelCache = null;
}

/**
 * Generate a completion from Ollama (non-streaming)
 */
export async function generate(model: string, prompt: string, options?: {
  system?: string;
  temperature?: number;
  num_predict?: number;
}): Promise<OllamaGenerateResponse> {
  const resp = await fetch(`${CONFIG.ollama.host}/api/generate`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    signal: timeoutSignal(CONFIG.ollama.generateTimeoutMs),
    body: JSON.stringify({
      model,
      prompt,
      stream: false,
      system: options?.system,
      options: {
        temperature: options?.temperature ?? 0.3,
        num_predict: options?.num_predict ?? 2048,
      },
    }),
  });

  if (!resp.ok) {
    const text = await resp.text();
    throw new Error(`Ollama generate failed: ${resp.status} ${text}`);
  }

  return await resp.json() as OllamaGenerateResponse;
}

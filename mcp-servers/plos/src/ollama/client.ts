import { CONFIG } from '../config.js';
import { plosTinker } from '../tools/plos-tinker.js';

export interface OllamaModel {
  name: string;
  size: number;
  modified_at: string;
}

export interface OllamaRoute {
  instanceId: string;
  model: string;
  role: string;
}

export interface OllamaGenerateResponse {
  model: string;
  response: string;
  done: boolean;
}

export function buildGenerationCode(route: OllamaRoute, prompt: string, options?: {
  system?: string;
  temperature?: number;
  num_predict?: number;
  signal?: AbortSignal;
}): string {
  if (!route.instanceId || !route.model || !route.role || typeof prompt !== 'string') {
    throw new Error('An explicit registered instance, model and role are required.');
  }
  const request = Buffer.from(JSON.stringify({ prompt, config: {
    model: route.model, model_override: route.model, model_role: route.role,
    allowed_ollama_instance_ids: [route.instanceId], preferred_ollama_instance_ids: [route.instanceId],
    ollama_required_no_fallback: true, disable_cascade: true, local_only: true,
    disable_external_fallback: true, use_cache: false, suppressAlert: true,
    system_prompt: options?.system, temperature: options?.temperature ?? 0.3,
    max_tokens: options?.num_predict ?? 2048,
  }})).toString('base64');
  return `$request = json_decode(base64_decode('${request}'), true, 512, JSON_THROW_ON_ERROR); `
    + `$result = app(App\\Services\\AIService::class)->process($request['prompt'], $request['config']); `
    + `echo json_encode($result, JSON_THROW_ON_ERROR);`;
}

/** Reuse PLOS's existing Laravel bridge and admission pipeline; never contact an env-selected GPU host. */
export async function generate(route: OllamaRoute, prompt: string, options?: {
  system?: string;
  temperature?: number;
  num_predict?: number;
  signal?: AbortSignal;
}, run: (code: string) => Promise<string> = code => plosTinker({ code, on_prod: true, timeout: Math.ceil(CONFIG.ollama.generateTimeoutMs / 1000) }, { signal: options?.signal })): Promise<OllamaGenerateResponse> {
  const output = await run(buildGenerationCode(route, prompt, options));
  const body = output.match(/^\[REMOTE\] tinker \(\d+ chars\)\n\n([\s\S]+)$/)?.[1];
  if (!body) throw new Error('PLOS inference bridge did not return a complete response; no fallback was attempted.');
  let result: { success?: boolean; model?: string; provider?: string; response?: string };
  try { result = JSON.parse(body); } catch { throw new Error('PLOS inference bridge response is incomplete or invalid.'); }
  if (result.success !== true || result.model !== route.model || result.provider !== route.instanceId || typeof result.response !== 'string') {
    throw new Error('PLOS did not complete the exact registered inference route.');
  }
  return { model: result.model, response: result.response, done: true };
}

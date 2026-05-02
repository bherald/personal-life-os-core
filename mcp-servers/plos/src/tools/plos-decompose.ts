import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosDecomposeInput = z.object({
  prompt: z.string().describe(
    'The full prompt to process. If >8K tokens, auto-decompose splits it into smaller chunks, ' +
    'processes each with a fast local model, and synthesizes results with a quality model. ' +
    'Below threshold, processes normally through the full LLM provider chain.'
  ),
  task: z.string().optional().describe(
    'Optional task instruction prepended to each chunk (e.g., "Summarize the key findings", ' +
    '"Extract all dates and persons"). If omitted, the prompt is used as-is.'
  ),
  model_role: z.enum(['fast', 'standard', 'quality']).optional().default('standard')
    .describe('Model role for processing. fast=local Ollama, standard=default, quality=best available.'),
  max_tokens: z.coerce.number().optional().default(2000)
    .describe('Max tokens for the response (default 2000)'),
  temperature: z.coerce.number().optional().default(0.3)
    .describe('Temperature for generation (0.0-1.0, default 0.3)'),
  timeout: z.coerce.number().optional().default(90)
    .describe('Timeout in seconds (max 300)'),
});

export type PlosDecomposeInput = z.infer<typeof plosDecomposeInput>;

export async function plosDecompose(input: PlosDecomposeInput, context?: ToolContext): Promise<string> {
  const { prompt, task, model_role, max_tokens, temperature, timeout: timeoutSec } = input;
  const effectiveTimeout = Math.min(Math.max(10, timeoutSec), 300) * 1000;

  // Build the full prompt: task prefix + content
  const fullPrompt = task ? `${task}\n\n${prompt}` : prompt;
  const estimatedTokens = Math.ceil(fullPrompt.length / 4);

  logger.info('Decompose request', {
    prompt_length: fullPrompt.length,
    estimated_tokens: estimatedTokens,
    model_role,
    has_task: !!task,
  });

  const payload = Buffer.from(JSON.stringify({
    prompt: fullPrompt,
    model_role,
    max_tokens,
    temperature,
  })).toString('base64');
  const remotePath = CONFIG.paths.remoteRoot;
  const shellCmd = `${sshCmd()} "cd -- ${shellQuote(remotePath)} && php artisan ops:mcp-decompose --payload=${shellQuote(payload)}"`;

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: effectiveTimeout,
      maxBuffer: 2 * 1024 * 1024, // 2MB — decomposed results can be large
      signal: context?.signal,
    });

    const output = stdout.trim();

    // Try to parse JSON response
    try {
      const parsed = JSON.parse(output);
      const lines: string[] = [];

      if (parsed.rlm_auto_decompose) {
        lines.push(`[RLM Auto-Decompose] ${parsed.rlm_chunks} chunks, ~${parsed.rlm_sub_tokens_avg} tokens/chunk`);
      }

      lines.push(`[Provider: ${parsed.provider}] [Model: ${parsed.model}] [${parsed.duration_ms}ms]`);
      if (parsed.from_cache) lines.push('[FROM CACHE]');
      lines.push('');
      lines.push(parsed.response || '(empty response)');

      return lines.join('\n');
    } catch {
      // Not JSON — return raw output
      return `[REMOTE] AIService response:\n\n${output}`;
    }
  } catch (err: unknown) {
    const error = err as { stdout?: string; stderr?: string; message?: string; code?: number; killed?: boolean };

    if (error.killed) {
      return `[TIMEOUT] Decompose exceeded ${timeoutSec}s limit (estimated ${estimatedTokens} tokens).\n\nPartial: ${error.stdout?.trim() || '(none)'}`;
    }

    const errMsg = error.stderr?.trim() || error.message || 'Unknown error';
    return `[ERROR] Decompose failed (exit ${error.code})\n\n${errMsg}`;
  }
}

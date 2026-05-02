import { z } from 'zod';
import { generate } from '../ollama/client.js';
import { selectModel } from '../ollama/model-selector.js';

export const ollamaClassifyErrorInput = z.object({
  error: z.string().describe('Error message or stack trace to classify'),
  context: z.string().optional().describe('Additional context (e.g., which service, what was happening)'),
});

export type OllamaClassifyErrorInput = z.infer<typeof ollamaClassifyErrorInput>;

export async function ollamaClassifyError(input: OllamaClassifyErrorInput): Promise<string> {
  const model = await selectModel('code');

  const system = `You are a Laravel/PHP error classifier for PLOS (Personal Life OS).
Classify the error into one of: DB_ERROR, CODE_BUG, CONFIG_ERROR, NETWORK_ERROR, TIMEOUT, RESOURCE_EXHAUSTION, EXTERNAL_SERVICE, PERMISSION_ERROR, DATA_INTEGRITY, UNKNOWN.
Then provide: (1) classification, (2) likely root cause in 1 sentence, (3) suggested fix in 1 sentence.
Format:
Classification: <type>
Root cause: <explanation>
Fix: <suggestion>`;

  const prompt = input.context
    ? `Context: ${input.context}\n\nError:\n${input.error}`
    : input.error;

  const result = await generate(model, prompt, { system, temperature: 0.1 });
  return `[${model}]\n${result.response.trim()}`;
}

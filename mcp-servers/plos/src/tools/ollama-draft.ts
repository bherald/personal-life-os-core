import { z } from 'zod';
import { generate } from '../ollama/client.js';
import { selectModel } from '../ollama/model-selector.js';
import type { ModelCapability } from '../ollama/model-selector.js';

export const ollamaDraftInput = z.object({
  prompt: z.string().describe('What to draft'),
  type: z.enum(['code', 'sql', 'content', 'general']).optional().default('general')
    .describe('Type of content — routes to appropriate model'),
  system: z.string().optional().describe('Optional system prompt override'),
  temperature: z.coerce.number().optional().default(0.3).describe('Temperature (0-1)'),
  max_tokens: z.coerce.number().optional().default(2048).describe('Max tokens to generate'),
});

export type OllamaDraftInput = z.infer<typeof ollamaDraftInput>;

const TYPE_TO_CAPABILITY: Record<string, ModelCapability> = {
  code: 'code',
  sql: 'sql',
  content: 'general',
  general: 'general',
};

const TYPE_SYSTEM_PROMPTS: Record<string, string> = {
  code: 'You are a PHP/Laravel code generator for PLOS. Use raw SQL (DB::select/insert/update/delete), no Eloquent. Output only the code.',
  sql: 'You are a MySQL/PostgreSQL query writer. Output only the SQL query. Use parameterized placeholders (? for MySQL, $1 for PostgreSQL).',
  content: 'You are a technical writer. Output only the requested content.',
  general: 'Output only the requested content.',
};

export async function ollamaDraft(input: OllamaDraftInput): Promise<string> {
  const { prompt, type, system, temperature, max_tokens } = input;
  const capability = TYPE_TO_CAPABILITY[type] ?? 'general';
  const model = await selectModel(capability);

  const systemPrompt = system ?? TYPE_SYSTEM_PROMPTS[type] ?? TYPE_SYSTEM_PROMPTS.general;

  const result = await generate(model, prompt, {
    system: systemPrompt,
    temperature,
    num_predict: max_tokens,
  });

  return `[${model}]\n${result.response.trim()}`;
}

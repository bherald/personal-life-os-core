import { z } from 'zod';
import { generate } from '../ollama/client.js';
import { selectModel } from '../ollama/model-selector.js';

export const ollamaSummarizeInput = z.object({
  text: z.string().describe('Text to summarize'),
  max_length: z.coerce.number().optional().default(500).describe('Approximate max length of summary in characters'),
  style: z.enum(['brief', 'detailed', 'bullets', 'technical']).optional().default('brief')
    .describe('Summary style'),
  context: z.string().optional().describe('Optional context about what this text is (e.g., "laravel log", "git diff", "PHP service")'),
});

export type OllamaSummarizeInput = z.infer<typeof ollamaSummarizeInput>;

const STYLE_PROMPTS: Record<string, string> = {
  brief: 'Provide a concise summary in 2-3 sentences.',
  detailed: 'Provide a thorough summary covering all key points.',
  bullets: 'Summarize as a bulleted list of key points.',
  technical: 'Provide a technical summary focusing on implementation details, errors, and actionable items.',
};

export async function ollamaSummarize(input: OllamaSummarizeInput): Promise<string> {
  const { text, max_length, style, context } = input;
  const model = await selectModel('general');

  const system = `You are a precise summarizer. ${STYLE_PROMPTS[style]}
Keep the summary under ${max_length} characters.
${context ? `Context: this text is a ${context}.` : ''}
Output ONLY the summary, no preamble.`;

  const result = await generate(model, text, {
    system,
    temperature: 0.2,
    num_predict: Math.ceil(max_length / 3),
  });

  return `[${model}] ${result.response.trim()}`;
}

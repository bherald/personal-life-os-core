import { z } from 'zod';
import { generate } from '../ollama/client.js';
import { selectModel } from '../ollama/model-selector.js';

export const ollamaExplainDiffInput = z.object({
  diff: z.string().describe('Git diff output to explain'),
  context: z.string().optional().describe('What this change is for'),
});

export type OllamaExplainDiffInput = z.infer<typeof ollamaExplainDiffInput>;

export async function ollamaExplainDiff(input: OllamaExplainDiffInput): Promise<string> {
  const model = await selectModel('code');

  const system = `Summarize this git diff as a bullet list.
For each file changed, provide one line: what was changed and why (if inferable).
Keep it concise — 1 line per file, max 10 lines total.
${input.context ? `Context: ${input.context}` : ''}`;

  const result = await generate(model, input.diff, { system, temperature: 0.1, num_predict: 1024 });
  return `[${model}]\n${result.response.trim()}`;
}

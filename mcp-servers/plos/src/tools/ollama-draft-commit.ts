import { z } from 'zod';
import { generate } from '../ollama/client.js';
import { selectModel } from '../ollama/model-selector.js';

export const ollamaDraftCommitInput = z.object({
  diff: z.string().describe('Git diff to generate commit message for'),
  context: z.string().optional().describe('Additional context about the change'),
});

export type OllamaDraftCommitInput = z.infer<typeof ollamaDraftCommitInput>;

export async function ollamaDraftCommit(input: OllamaDraftCommitInput): Promise<string> {
  const model = await selectModel('code');

  const system = `Generate a git commit message for this diff.
Format: <type>: <short description>

Types: fix, feat, refactor, docs, chore, perf, test
Rules:
- First line under 72 chars
- Use imperative mood ("add" not "added")
- If the change has a ticket ID like N123, SC-3, DI-1 etc., include it
- Optionally add a blank line and 1-2 detail sentences
${input.context ? `\nContext: ${input.context}` : ''}
Output ONLY the commit message, nothing else.`;

  const result = await generate(model, input.diff, { system, temperature: 0.2, num_predict: 256 });
  return `[${model}]\n${result.response.trim()}`;
}

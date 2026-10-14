import { z } from 'zod';
import type { ToolContext } from '../util/tool-context.js';
import { readFileSync } from 'fs';
import { generate } from '../ollama/client.js';
import { selectRoute } from '../ollama/model-selector.js';

export const ollamaDescribeCodeInput = z.object({
  file_path: z.string().describe('Absolute path to the file to describe'),
  focus: z.string().optional().describe('Specific aspect to focus on (e.g., "public methods", "dependencies", "SQL queries")'),
});

export type OllamaDescribeCodeInput = z.infer<typeof ollamaDescribeCodeInput>;

export async function ollamaDescribeCode(input: OllamaDescribeCodeInput, toolContext?: ToolContext): Promise<string> {
  const route = await selectRoute('code');
  const model = route.model;

  let content: string;
  try {
    content = readFileSync(input.file_path, 'utf-8');
  } catch (err) {
    return `Error reading file: ${(err as Error).message}`;
  }

  const focusLine = input.focus ? `\nFocus on: ${input.focus}` : '';

  const system = `Describe this code file concisely. Include:
1. Purpose (1 sentence)
2. Key public methods with brief description
3. Dependencies (services, DB tables referenced)
4. Notable patterns or concerns${focusLine}
Keep total output under 500 characters.`;

  const result = await generate(route, content, { signal: toolContext?.signal, system, temperature: 0.1, num_predict: 1024 });
  return `[${model}] ${input.file_path}\n${result.response.trim()}`;
}

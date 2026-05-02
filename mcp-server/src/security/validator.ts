import { z } from 'zod';

// Input validation schemas
export const schemas = {
  workflowList: z.object({
    active_only: z.boolean().optional(),
  }),

  workflowGet: z.object({
    name: z.string().min(1),
  }),

  workflowRun: z.object({
    name: z.string().min(1),
  }),

  executionList: z.object({
    workflow_name: z.string().optional(),
    limit: z.number().min(1).max(500).optional(),
  }),

  executionGet: z.object({
    run_id: z.number().positive(),
  }),

  artisanExecute: z.object({
    command: z.string().min(1),
    args: z.array(z.string()).optional(),
  }),

  nodeCreate: z.object({
    name: z.string().regex(/^[A-Z][a-zA-Z0-9]*$/, 'Must be PascalCase'),
  }),
};

export function validateInput<T>(schema: z.ZodSchema<T>, input: unknown): T {
  try {
    return schema.parse(input);
  } catch (error) {
    if (error instanceof z.ZodError) {
      const messages = error.errors.map(e => `${e.path.join('.')}: ${e.message}`);
      throw new Error(`Validation failed: ${messages.join(', ')}`);
    }
    throw error;
  }
}

import type { CallToolResult, ToolAnnotations } from '@modelcontextprotocol/sdk/types.js';
import type { JsonSchemaType, JsonSchemaValidator } from '@modelcontextprotocol/sdk/validation';

export type ToolRisk = 'read' | 'write' | 'destructive';

const outputValidators = new WeakMap<Record<string, unknown>, JsonSchemaValidator<unknown>>();
let schemaValidatorPromise: Promise<import('@modelcontextprotocol/sdk/validation/ajv').AjvJsonSchemaValidator> | null = null;

function schemaValidator() {
  schemaValidatorPromise ??= import('@modelcontextprotocol/sdk/validation/ajv')
    .then(({ AjvJsonSchemaValidator }) => new AjvJsonSchemaValidator());

  return schemaValidatorPromise;
}

export function toolAnnotations(risk: ToolRisk, openWorld = false): ToolAnnotations {
  return {
    readOnlyHint: risk === 'read',
    destructiveHint: risk === 'destructive',
    idempotentHint: risk === 'read',
    openWorldHint: openWorld,
  };
}

export async function buildToolSuccessResult(
  text: string,
  outputSchema?: Record<string, unknown>,
): Promise<CallToolResult> {
  const result: CallToolResult = {
    content: [{ type: 'text', text }],
  };

  try {
    const parsed = JSON.parse(text) as unknown;
    if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
      result.structuredContent = parsed as Record<string, unknown>;
    }
  } catch {
    // Text remains the backward-compatible result for non-JSON tools.
  }

  if (outputSchema) {
    if (!result.structuredContent) {
      throw new Error('Tool output schema requires structured JSON content');
    }

    let validate = outputValidators.get(outputSchema);
    if (!validate) {
      validate = (await schemaValidator()).getValidator(outputSchema as JsonSchemaType);
      outputValidators.set(outputSchema, validate);
    }

    if (!validate(result.structuredContent).valid) {
      throw new Error('Tool structured content does not conform to its advertised output schema');
    }
  }

  return result;
}

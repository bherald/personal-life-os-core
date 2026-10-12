import { createHash } from 'node:crypto';

export type McpToolContract = {
  name?: unknown;
  description?: unknown;
  inputSchema?: unknown;
  outputSchema?: unknown;
  annotations?: unknown;
  [key: string]: unknown;
};

export type ToolContractBaseline = {
  schema: 'plos.mcp_tool_contract_baseline.v1';
  content_sha256: string;
  tool_count: number;
  tools: Array<{ name: string; content_sha256: string }>;
};

type ContractViolation = {
  severity: 'block';
  code: string;
  tool: string;
};

type ContractDrift = {
  detected: boolean;
  added: string[];
  removed: string[];
  changed: string[];
};

const TOOL_NAME_PATTERN = /^[a-z][a-z0-9_]{0,63}$/;
const SHA256_PATTERN = /^[a-f0-9]{64}$/;
const HIDDEN_CHARACTER_PATTERN = /[\p{Cc}\p{Cf}\p{Cs}]/u;
const INJECTION_PATTERNS: Array<{ code: string; pattern: RegExp }> = [
  {
    code: 'instruction_override',
    pattern: /\b(ignore|disregard|override|bypass)\b.{0,80}\b(previous|system|developer|safety|policy)\b/i,
  },
  {
    code: 'hidden_action_request',
    pattern: /\b(do not tell|without (?:the )?user knowing|hide this (?:from|for))\b/i,
  },
  {
    code: 'secret_exfiltration_request',
    pattern: /\b(send|upload|post|export)\b.{0,80}\b(secret|token|password|credential|api[_ -]?key)\b/i,
  },
];

export function auditToolContract(
  tools: McpToolContract[],
  baseline?: ToolContractBaseline,
): Record<string, unknown> {
  const violations: ContractViolation[] = [];
  const safeNames = tools.map((tool, index) => safeToolIdentifier(tool.name, index));
  const validNames = tools.map(tool => typeof tool.name === 'string' && TOOL_NAME_PATTERN.test(tool.name)
    ? tool.name
    : null);
  const normalizedOwners = new Map<string, number>();
  const exactOwners = new Map<string, number>();

  tools.forEach((tool, index) => {
    const safeName = safeNames[index];
    const name = validNames[index];
    const description = typeof tool.description === 'string' ? tool.description : '';
    const inputSchema = isRecord(tool.inputSchema) ? tool.inputSchema : null;
    const outputSchema = tool.outputSchema === undefined
      ? null
      : (isRecord(tool.outputSchema) ? tool.outputSchema : null);
    const annotations = isRecord(tool.annotations) ? tool.annotations : null;

    if (name === null) {
      violations.push(block('invalid_tool_name', safeName));
    } else {
      const exactOwner = exactOwners.get(name);
      if (exactOwner !== undefined) {
        violations.push(block('duplicate_tool_name', safeName));
        violations.push(block('duplicate_tool_name', safeNames[exactOwner]));
      } else {
        exactOwners.set(name, index);
      }

      const normalized = name.replace(/[^a-z0-9]/g, '');
      const normalizedOwner = normalizedOwners.get(normalized);
      if (normalizedOwner !== undefined && validNames[normalizedOwner] !== name) {
        violations.push(block('confusable_tool_name', safeName));
        violations.push(block('confusable_tool_name', safeNames[normalizedOwner]));
      } else {
        normalizedOwners.set(normalized, index);
      }
    }

    if (description.trim().length < 20) {
      violations.push(block('missing_or_short_description', safeName));
    }

    const metadataText = `${description}${canonicalJson(tool.inputSchema)}${canonicalJson(tool.outputSchema)}`;
    if (HIDDEN_CHARACTER_PATTERN.test(metadataText)) {
      violations.push(block('hidden_metadata_character', safeName));
    }
    for (const injection of INJECTION_PATTERNS) {
      if (injection.pattern.test(metadataText)) {
        violations.push(block(`metadata_${injection.code}`, safeName));
      }
    }

    if (name !== null && description !== '') {
      for (const otherName of validNames) {
        if (otherName === null || otherName === name) continue;
        if (new RegExp(`(^|[^a-z0-9_])${escapeRegExp(otherName)}([^a-z0-9_]|$)`, 'i').test(description)) {
          violations.push(block('cross_tool_description_reference', safeName));
          break;
        }
      }
    }

    if (inputSchema === null || inputSchema.type !== 'object' || !isRecord(inputSchema.properties)) {
      violations.push(block('invalid_input_schema_root', safeName));
    } else {
      const properties = inputSchema.properties;
      const required = Array.isArray(inputSchema.required) ? inputSchema.required : [];
      if (required.some(key => typeof key !== 'string' || !(key in properties))) {
        violations.push(block('input_schema_required_property_missing', safeName));
      }
      if (canonicalJson(inputSchema).includes('"$ref"')) {
        violations.push(block('input_schema_external_reference', safeName));
      }
    }

    if (tool.outputSchema !== undefined && (outputSchema === null || outputSchema.type !== 'object')) {
      violations.push(block('invalid_output_schema_root', safeName));
    }

    const annotationKeys = ['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'];
    if (annotations === null || annotationKeys.some(key => typeof annotations[key] !== 'boolean')) {
      violations.push(block('invalid_tool_annotations', safeName));
    } else if (annotations.readOnlyHint === true && annotations.destructiveHint === true) {
      violations.push(block('conflicting_tool_annotations', safeName));
    }
  });

  const toolDigests = tools.map((tool, index) => ({
    name: safeNames[index],
    content_sha256: sha256(canonicalJson(tool)),
  })).sort((left, right) => left.name.localeCompare(right.name));
  const contentSha256 = sha256(canonicalJson(toolDigests));
  const baselineState = compareBaseline(toolDigests, contentSha256, baseline);
  const uniqueViolations = uniqueSortedViolations(violations);
  const passed = uniqueViolations.length === 0 && baselineState.matched !== false;

  return {
    schema: 'plos.mcp_tool_contract_audit.v1',
    status: passed ? 'pass' : 'fail',
    tool_count: tools.length,
    content_sha256: contentSha256,
    expected_content_sha256: baseline?.content_sha256 ?? null,
    expected_digest_matched: baselineState.matched,
    summary: {
      violations: uniqueViolations.length,
      drift_detected: baselineState.drift.detected,
      tools_added: baselineState.drift.added.length,
      tools_removed: baselineState.drift.removed.length,
      tools_changed: baselineState.drift.changed.length,
    },
    drift: baselineState.drift,
    violations: uniqueViolations,
    tools: toolDigests,
    posture: {
      live_tools_list_exercised: true,
      arbitrary_mcp_configs_executed: false,
      network_used: false,
      metadata_uploaded: false,
      raw_descriptions_or_schemas_emitted: false,
      hidden_unicode_blocked: true,
      metadata_injection_blocked: true,
      reviewed_baseline_required: baseline !== undefined,
    },
  };
}

export function baselineFromAudit(audit: Record<string, unknown>): ToolContractBaseline {
  const tools = Array.isArray(audit.tools)
    ? audit.tools.filter((tool): tool is { name: string; content_sha256: string } => (
      isRecord(tool)
      && typeof tool.name === 'string'
      && TOOL_NAME_PATTERN.test(tool.name)
      && typeof tool.content_sha256 === 'string'
      && SHA256_PATTERN.test(tool.content_sha256)
    ))
    : [];
  const contentSha256 = typeof audit.content_sha256 === 'string' ? audit.content_sha256 : '';

  if (!SHA256_PATTERN.test(contentSha256) || tools.length !== audit.tool_count) {
    throw new Error('Cannot create baseline from an incomplete or invalid tool-contract audit.');
  }

  return {
    schema: 'plos.mcp_tool_contract_baseline.v1',
    content_sha256: contentSha256,
    tool_count: tools.length,
    tools,
  };
}

export function parseBaseline(value: unknown): ToolContractBaseline {
  if (!isRecord(value)
    || value.schema !== 'plos.mcp_tool_contract_baseline.v1'
    || typeof value.content_sha256 !== 'string'
    || !SHA256_PATTERN.test(value.content_sha256)
    || !Number.isInteger(value.tool_count)
    || !Array.isArray(value.tools)
  ) {
    throw new Error('Invalid MCP tool-contract baseline.');
  }

  const tools = value.tools.map((tool, index) => {
    if (!isRecord(tool)
      || typeof tool.name !== 'string'
      || !TOOL_NAME_PATTERN.test(tool.name)
      || typeof tool.content_sha256 !== 'string'
      || !SHA256_PATTERN.test(tool.content_sha256)
    ) {
      throw new Error(`Invalid MCP tool-contract baseline entry at index ${index}.`);
    }

    return { name: tool.name, content_sha256: tool.content_sha256 };
  });

  if (tools.length !== value.tool_count || new Set(tools.map(tool => tool.name)).size !== tools.length) {
    throw new Error('MCP tool-contract baseline count or names are inconsistent.');
  }

  return {
    schema: value.schema,
    content_sha256: value.content_sha256,
    tool_count: value.tool_count,
    tools,
  };
}

function compareBaseline(
  current: Array<{ name: string; content_sha256: string }>,
  contentSha256: string,
  baseline?: ToolContractBaseline,
): { matched: boolean | null; drift: ContractDrift } {
  if (baseline === undefined) {
    return {
      matched: null,
      drift: { detected: false, added: [], removed: [], changed: [] },
    };
  }

  const expected = new Map(baseline.tools.map(tool => [tool.name, tool.content_sha256]));
  const actual = new Map(current.map(tool => [tool.name, tool.content_sha256]));
  const added = [...actual.keys()].filter(name => !expected.has(name)).sort();
  const removed = [...expected.keys()].filter(name => !actual.has(name)).sort();
  const changed = [...actual.keys()].filter(name => (
    expected.has(name) && expected.get(name) !== actual.get(name)
  )).sort();
  const matched = baseline.tool_count === current.length
    && baseline.content_sha256 === contentSha256
    && added.length === 0
    && removed.length === 0
    && changed.length === 0;

  return {
    matched,
    drift: { detected: !matched, added, removed, changed },
  };
}

function uniqueSortedViolations(violations: ContractViolation[]): ContractViolation[] {
  const unique = new Map<string, ContractViolation>();
  for (const violation of violations) {
    unique.set(`${violation.code}\0${violation.tool}`, violation);
  }

  return [...unique.values()].sort((left, right) => (
    left.tool.localeCompare(right.tool) || left.code.localeCompare(right.code)
  ));
}

function block(code: string, tool: string): ContractViolation {
  return { severity: 'block', code, tool };
}

function safeToolIdentifier(name: unknown, index: number): string {
  if (typeof name === 'string' && TOOL_NAME_PATTERN.test(name)) return name;

  return `invalid_tool_${index}_${sha256(String(name)).slice(0, 12)}`;
}

function canonicalJson(value: unknown): string {
  return JSON.stringify(canonicalize(value)) ?? 'null';
}

function canonicalize(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (!isRecord(value)) return value;

  return Object.fromEntries(
    Object.keys(value)
      .filter(key => value[key] !== undefined)
      .sort()
      .map(key => [key, canonicalize(value[key])]),
  );
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function sha256(value: string): string {
  return createHash('sha256').update(value, 'utf8').digest('hex');
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

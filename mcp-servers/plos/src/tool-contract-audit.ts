import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

import {
  auditToolContract,
  baselineFromAudit,
  parseBaseline,
  type ToolContractBaseline,
} from './tool-contract.js';
import {
  readLiveToolContractWithReceipt,
  ToolContractProbeError,
  type ToolContractProbeReceipt,
} from './tool-contract-probe.js';

const args = new Set(process.argv.slice(2));
const writeBaseline = args.has('--write-baseline');
const failOnViolations = args.has('--fail-on-violations');
const failOnDrift = args.has('--fail-on-drift');
const baselinePath = fileURLToPath(new URL('../tool-contract-baseline.json', import.meta.url));

async function main(): Promise<void> {
  const baseline = writeBaseline ? undefined : await loadBaseline(baselinePath);
  const probe = await readLiveToolContractWithReceipt();
  const tools = probe.tools;
  const audit = auditToolContract(tools, baseline);

  if (writeBaseline) {
    if (audit.status !== 'pass') {
      process.stdout.write(`${JSON.stringify(compactAudit(audit, probe.receipt))}\n`);
      process.stderr.write('Refusing to write a baseline for a tool contract with security violations.\n');
      process.exitCode = 1;
      return;
    }
    const nextBaseline = baselineFromAudit(audit);
    await writeFile(baselinePath, `${JSON.stringify(nextBaseline, null, 2)}\n`, { encoding: 'utf8', mode: 0o644 });
    audit.expected_content_sha256 = nextBaseline.content_sha256;
    audit.expected_digest_matched = true;
    audit.baseline_written = true;
  }

  const compact = compactAudit(audit, probe.receipt);
  process.stdout.write(`${JSON.stringify(compact)}\n`);

  const violationCount = Number((audit.summary as Record<string, unknown>).violations ?? 0);
  const driftDetected = Boolean((audit.summary as Record<string, unknown>).drift_detected);
  if ((failOnViolations && violationCount > 0) || (failOnDrift && driftDetected)) {
    process.exitCode = 1;
  }
}

async function loadBaseline(path: string): Promise<ToolContractBaseline> {
  const raw = await readFile(path, 'utf8');
  return parseBaseline(JSON.parse(raw));
}

/*
 * The audit starts only the trusted PLOS server through the official SDK
 * stdio client. It never reads or executes arbitrary MCP configuration files.
 */
function compactAudit(
  audit: Record<string, unknown>,
  probe: ToolContractProbeReceipt,
): Record<string, unknown> {
  return {
    schema: audit.schema,
    status: audit.status,
    compact: true,
    tool_count: audit.tool_count,
    content_sha256: audit.content_sha256,
    expected_content_sha256: audit.expected_content_sha256,
    expected_digest_matched: audit.expected_digest_matched,
    summary: audit.summary,
    drift: audit.drift,
    violations: audit.violations,
    posture: audit.posture,
    probe,
    ...(audit.baseline_written === true ? { baseline_written: true } : {}),
  };
}

main().catch(error => {
  if (error instanceof ToolContractProbeError) {
    process.stdout.write(`${JSON.stringify(probeErrorAudit(error.receipt))}\n`);
    process.exitCode = 1;
    return;
  }

  process.stderr.write('MCP tool-contract audit failed before classified evidence was produced.\n');
  process.exitCode = 2;
});

function probeErrorAudit(probe: ToolContractProbeReceipt): Record<string, unknown> {
  return {
    schema: 'plos.mcp_tool_contract_audit.v1',
    status: 'error',
    compact: true,
    tool_count: 0,
    content_sha256: null,
    expected_content_sha256: null,
    expected_digest_matched: null,
    summary: {
      violations: 1,
      drift_detected: false,
      tools_added: 0,
      tools_removed: 0,
      tools_changed: 0,
    },
    drift: { detected: false, added: [], removed: [], changed: [] },
    violations: [{
      severity: 'block',
      code: `probe_${probe.failure_code ?? 'unknown'}`,
      tool: 'plos_mcp_server',
    }],
    posture: {
      live_tools_list_exercised: false,
      arbitrary_mcp_configs_executed: false,
      network_used: false,
      metadata_uploaded: false,
      raw_descriptions_or_schemas_emitted: false,
      raw_diagnostics_emitted: false,
    },
    probe,
  };
}

import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosOfflineCapabilitiesInput = z.object({
  probe_sidecars: z.coerce.boolean().optional().default(false)
    .describe('Probe configured local/LAN sidecar health endpoints. Default false keeps the call observe-only with no sidecar HTTP probes.'),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for the local checkout/offline laptop.'),
});

export const plosOfflinePipelineToolsInput = z.object({
  compact: z.coerce.boolean().optional().default(false)
    .describe('Omit long descriptions and optional inventories. Default false returns the full ordered pipeline inventory.'),
  task: z.string().optional().default('')
    .describe('Optional substring filter for configured ollama_pipeline task names.'),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for the local checkout/offline laptop.'),
});

export const plosOfflineSidecarInput = z.object({
  prompt: z.string().optional().default('Inspect this repository and suggest one small next step. Do not modify files.')
    .describe('Task for the read-only offline sidecar lanes. The tool sends it to artisan as base64 to avoid shell quoting issues.'),
  execute: z.coerce.boolean().optional().default(false)
    .describe('Run the read-only Codex lanes. Default false only returns the sidecar plan.'),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for the local checkout/offline laptop.'),
  timeout_seconds: z.coerce.number().int().optional().default(900)
    .describe('Per-lane timeout for execute=true. Capped to 1200 seconds.'),
  effort: z.enum(['minimal', 'low', 'medium', 'high']).optional().default('low')
    .describe('Codex reasoning effort passed to both read-only lanes.'),
  primary_profile: z.string().optional().default('plos-offline-coding')
    .describe('Codex profile for the primary planning lane.'),
  primary_model: z.string().optional().default('qwen3-coder:30b')
    .describe('Model for the primary planning lane.'),
  sidecar_profile: z.string().optional().default('plos-offline-jetson')
    .describe('Codex profile for the independent sidecar review lane.'),
  sidecar_model: z.string().optional().default('qwen3-coder:30b')
    .describe('Model for the sidecar review lane.'),
});

export const plosColibriResearchInput = z.object({
  question: z.string().optional().default('')
    .describe('Public-information research question. Required for execute=true and transported as base64 to avoid shell exposure.'),
  execute: z.coerce.boolean().optional().default(false)
    .describe('Run the operator-selected Colibri canary. Default false returns only the effective policy and exact tool catalog.'),
  public_web_confirmed: z.coerce.boolean().optional().default(false)
    .describe('Assert that the question may be sent to public search engines. Required for execute=true.'),
  max_iterations: z.coerce.number().int().min(1).max(4).optional().default(4)
    .describe('Lower or retain the four-turn policy ceiling; cannot widen it.'),
  max_tool_calls: z.coerce.number().int().min(1).max(4).optional().default(4)
    .describe('Lower or retain the four-call policy ceiling; cannot widen it.'),
  on_prod: z.coerce.boolean().optional().default(false)
    .describe('Default false runs in the local checkout for Hyper-V offline use. Set true deliberately for the configured PLOS production instance.'),
  timeout_seconds: z.coerce.number().int().min(30).max(1800).optional().default(900)
    .describe('Bounded MCP wait for patient Colibri execution. Plan mode uses a short fixed timeout.'),
});

export const plosColibriLocalResearchInput = z.object({
  question: z.string().optional().default('')
    .describe('Local/private PLOS research question. Required for execute=true and transported as base64 to avoid shell exposure.'),
  execute: z.coerce.boolean().optional().default(false)
    .describe('Run the operator-selected local-offline Colibri canary. Default false returns only its policy and exact RAG read catalog.'),
  max_iterations: z.coerce.number().int().min(1).max(4).optional().default(4)
    .describe('Lower or retain the four-turn policy ceiling; cannot widen it.'),
  max_tool_calls: z.coerce.number().int().min(1).max(4).optional().default(4)
    .describe('Lower or retain the four-call policy ceiling; cannot widen it.'),
  on_prod: z.coerce.boolean().optional().default(false)
    .describe('Default false runs in the local checkout. Set true deliberately for the configured PLOS production instance.'),
  timeout_seconds: z.coerce.number().int().min(30).max(1800).optional().default(900)
    .describe('Bounded MCP wait for patient Colibri execution. Plan mode uses a short fixed timeout.'),
});

export const plosOfflineImprovementInput = z.object({
  summary: z.string().describe('Task outcome or lesson text for a review-gated skill/memory/tool/test proposal.'),
  area: z.string().optional().default('offline_dev')
    .describe('Improvement area, such as offline_dev, genealogy, media, ops, or ai_pipeline.'),
  write_report: z.coerce.boolean().optional().default(false)
    .describe('Persist the proposal JSON under storage/app/offline-improvement-proposals. Default false is observe-only.'),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for the local checkout/offline laptop.'),
});

export type PlosOfflineCapabilitiesInput = z.infer<typeof plosOfflineCapabilitiesInput>;
export type PlosOfflinePipelineToolsInput = z.infer<typeof plosOfflinePipelineToolsInput>;
export type PlosOfflineSidecarInput = z.infer<typeof plosOfflineSidecarInput>;
export type PlosColibriResearchInput = z.infer<typeof plosColibriResearchInput>;
export type PlosColibriLocalResearchInput = z.infer<typeof plosColibriLocalResearchInput>;
export type PlosOfflineImprovementInput = z.infer<typeof plosOfflineImprovementInput>;

export function buildOfflineCapabilitiesCommand(input: PlosOfflineCapabilitiesInput): string {
  const args = ['codex:offline-capabilities', '--json'];
  if (input.probe_sidecars) {
    args.push('--probe-sidecars');
  }

  return phpArtisan(args, input.on_prod);
}

export function buildOfflinePipelineToolsCommand(input: PlosOfflinePipelineToolsInput): string {
  const args = ['codex:offline-pipeline-tools', '--json'];
  if (input.compact) {
    args.push('--compact');
  }
  const task = input.task.trim();
  if (task !== '') {
    args.push(`--task=${task}`);
  }

  return phpArtisan(args, input.on_prod);
}

export function buildOfflineSidecarCommand(input: PlosOfflineSidecarInput): string {
  const timeout = Math.min(Math.max(1, input.timeout_seconds), 1200);
  const prompt = input.prompt.trim() === ''
    ? 'Inspect this repository and suggest one small next step. Do not modify files.'
    : input.prompt;
  const promptB64 = Buffer.from(prompt, 'utf8').toString('base64');
  const args = [
    'codex:offline-sidecar',
    '--json',
    `--prompt-b64=${promptB64}`,
    `--timeout=${timeout}`,
    `--effort=${input.effort}`,
    `--primary-profile=${input.primary_profile}`,
    `--primary-model=${input.primary_model}`,
    `--sidecar-profile=${input.sidecar_profile}`,
    `--sidecar-model=${input.sidecar_model}`,
  ];

  args.push(input.execute ? '--execute' : '--dry-run');

  return phpArtisan(args, input.on_prod);
}

export function buildColibriResearchCommand(input: PlosColibriResearchInput): string {
  const args = [
    'ai:colibri-research',
    '--json',
    '--compact',
    `--max-iterations=${input.max_iterations}`,
    `--max-tool-calls=${input.max_tool_calls}`,
  ];
  const question = input.question.trim();
  if (question !== '') {
    args.push(`--question-b64=${Buffer.from(question, 'utf8').toString('base64')}`);
  }
  if (input.execute) {
    args.push('--execute');
  }
  if (input.public_web_confirmed) {
    args.push('--public-web');
  }
  return phpArtisan(args, input.on_prod);
}

export function buildColibriLocalResearchCommand(input: PlosColibriLocalResearchInput): string {
  const args = [
    'ai:colibri-research',
    '--json',
    '--compact',
    '--local-offline',
    `--max-iterations=${input.max_iterations}`,
    `--max-tool-calls=${input.max_tool_calls}`,
  ];
  const question = input.question.trim();
  if (question !== '') {
    args.push(`--question-b64=${Buffer.from(question, 'utf8').toString('base64')}`);
  }
  if (input.execute) {
    args.push('--execute');
  }

  return phpArtisan(args, input.on_prod);
}

export function buildOfflineImprovementCommand(input: PlosOfflineImprovementInput): string {
  const summaryB64 = Buffer.from(input.summary, 'utf8').toString('base64');
  const args = [
    'codex:offline-improvement',
    '--json',
    `--summary-b64=${summaryB64}`,
    `--area=${input.area}`,
  ];

  if (input.write_report) {
    args.push('--write-report');
  }

  return phpArtisan(args, input.on_prod);
}

export async function plosOfflineCapabilities(
  input: PlosOfflineCapabilitiesInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildOfflineCapabilitiesCommand(input);

  logger.info('Offline capabilities manifest', {
    on_prod: input.on_prod,
    probe_sidecars: input.probe_sidecars,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: input.probe_sidecars ? 45_000 : 30_000,
      maxBuffer: 2 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan codex:offline-capabilities --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('codex:offline-capabilities', err);
  }
}

export async function plosOfflinePipelineTools(
  input: PlosOfflinePipelineToolsInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildOfflinePipelineToolsCommand(input);

  logger.info('Offline AI pipeline inventory', {
    on_prod: input.on_prod,
    compact: input.compact,
    task: input.task,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: 30_000,
      maxBuffer: 2 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan codex:offline-pipeline-tools --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('codex:offline-pipeline-tools', err);
  }
}

export async function plosOfflineSidecar(
  input: PlosOfflineSidecarInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildOfflineSidecarCommand(input);
  const timeout = Math.min(Math.max(1, input.timeout_seconds), 1200);

  logger.info('Offline sidecar coordinator', {
    on_prod: input.on_prod,
    execute: input.execute,
    timeout_seconds: timeout,
    primary_profile: input.primary_profile,
    sidecar_profile: input.sidecar_profile,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: (input.execute ? timeout + 30 : 30) * 1000,
      maxBuffer: 4 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan codex:offline-sidecar --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('codex:offline-sidecar', err);
  }
}

export async function plosColibriResearch(
  input: PlosColibriResearchInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildColibriResearchCommand(input);

  logger.info('Colibri read-only research canary', {
    on_prod: input.on_prod,
    execute: input.execute,
    public_web_confirmed: input.public_web_confirmed,
    question_bytes: Buffer.byteLength(input.question, 'utf8'),
    max_iterations: input.max_iterations,
    max_tool_calls: input.max_tool_calls,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: (input.execute ? input.timeout_seconds : 30) * 1000,
      maxBuffer: 4 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan ai:colibri-research --json --compact', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('ai:colibri-research', err);
  }
}

export async function plosColibriLocalResearch(
  input: PlosColibriLocalResearchInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildColibriLocalResearchCommand(input);

  logger.info('Colibri local-offline read-only research canary', {
    on_prod: input.on_prod,
    execute: input.execute,
    question_bytes: Buffer.byteLength(input.question, 'utf8'),
    max_iterations: input.max_iterations,
    max_tool_calls: input.max_tool_calls,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: (input.execute ? input.timeout_seconds : 30) * 1000,
      maxBuffer: 4 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan ai:colibri-research --local-offline --json --compact', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('ai:colibri-research --local-offline', err);
  }
}

export async function plosOfflineImprovement(
  input: PlosOfflineImprovementInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildOfflineImprovementCommand(input);

  logger.info('Offline improvement proposal', {
    on_prod: input.on_prod,
    write_report: input.write_report,
    area: input.area,
    summary_bytes: Buffer.byteLength(input.summary, 'utf8'),
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: 30_000,
      maxBuffer: 2 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan codex:offline-improvement --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('codex:offline-improvement', err);
  }
}

function phpArtisan(args: string[], onProd: boolean): string {
  const command = `php artisan ${args.map(shellQuote).join(' ')}`;

  if (onProd) {
    return `${sshCmd()} "cd -- ${shellQuote(CONFIG.paths.remoteRoot)} && ${command}"`;
  }

  return `cd -- ${shellQuote(CONFIG.paths.projectRoot)} && ${command}`;
}

function formatToolOutput(onProd: boolean, label: string, stdout: string, stderr: string): string {
  const env = onProd ? 'REMOTE' : 'DEV';
  const output = (stdout + (stderr ? `\n[stderr] ${stderr}` : '')).trim();

  return `[${env}] ${label}\n\n${output}`;
}

function formatToolError(label: string, err: unknown): string {
  const error = err as { stdout?: string; stderr?: string; message?: string; code?: number | string | null; killed?: boolean };
  const output = error.stdout?.trim() || '';
  const errMsg = error.stderr?.trim() || error.message || 'Unknown error';
  const prefix = error.killed ? '[TIMEOUT]' : '[ERROR]';

  return `${prefix} php artisan ${label}\nExit code: ${error.code ?? 'unknown'}\n\n${output}\n\n${errMsg}`;
}

import { z } from 'zod';
import { CONFIG, shellQuote } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosMediaAnalyzeInput = z.object({
  path: z.string().min(1)
    .describe('Local path to an image available to the offline PLOS checkout.'),
  prompt: z.string().optional().default('Describe this image in detail.')
    .describe('Instruction for the installed vision model. Encoded before it is passed to Artisan.'),
  model: z.string().optional()
    .describe('Optional installed vision model override; omit to use the registered vision role.'),
  execute: z.coerce.boolean().optional().default(false)
    .describe('Run image analysis. Default false validates a dry-run and makes no model request.'),
});

export const plosImageGenerateInput = z.object({
  prompt: z.string().min(1)
    .describe('Text description of the image to generate. Encoded before it is passed to Artisan.'),
  model: z.enum([
    'sdxl-lightning',
    'sdxl-base-1.0',
    'pixart-sigma-1024',
    'flux2-klein-4b',
    'sana-sprint-1.6b',
  ]).optional().default('sdxl-lightning')
    .describe('Installed, allowlisted image-generator alias.'),
  width: z.coerce.number().int().min(256).max(1024).multipleOf(64).optional().default(1024)
    .describe('Requested output width in pixels.'),
  height: z.coerce.number().int().min(256).max(1024).multipleOf(64).optional().default(1024)
    .describe('Requested output height in pixels.'),
  steps: z.coerce.number().int().min(1).max(100).optional()
    .describe('Requested diffusion step count; omit for the selected model default.'),
  guidance_scale: z.coerce.number().min(0).max(30).optional()
    .describe('Requested guidance scale; omit for the selected model default.'),
  seed: z.coerce.number().int().min(0).max(4_294_967_295).optional()
    .describe('Optional deterministic image seed. Omit to let the adapter choose.'),
  execute: z.coerce.boolean().optional().default(false)
    .describe('Generate and store an image. Default false validates a dry-run and writes nothing.'),
});

export type PlosMediaAnalyzeInput = z.infer<typeof plosMediaAnalyzeInput>;
export type PlosImageGenerateInput = z.infer<typeof plosImageGenerateInput>;

export function buildMediaAnalyzeCommand(input: PlosMediaAnalyzeInput): string {
  const args = [
    'media:offline-analyze',
    '--json',
    `--path=${input.path}`,
    `--prompt-b64=${Buffer.from(input.prompt, 'utf8').toString('base64')}`,
    input.execute ? '--execute' : '--dry-run',
  ];

  if (input.model !== undefined) {
    args.push(`--model=${input.model}`);
  }

  return localPhpArtisan(args);
}

export function buildImageGenerateCommand(input: PlosImageGenerateInput): string {
  const args = [
    'media:image-generate',
    '--json',
    `--prompt-b64=${Buffer.from(input.prompt, 'utf8').toString('base64')}`,
    `--model=${input.model}`,
    `--width=${input.width}`,
    `--height=${input.height}`,
  ];

  if (input.steps !== undefined) {
    args.push(`--steps=${input.steps}`);
  }
  if (input.guidance_scale !== undefined) {
    args.push(`--guidance-scale=${input.guidance_scale}`);
  }

  if (input.seed !== undefined) {
    args.push(`--seed=${input.seed}`);
  }

  args.push(input.execute ? '--execute' : '--dry-run');

  return localPhpArtisan(args);
}

export async function plosMediaAnalyze(
  input: PlosMediaAnalyzeInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildMediaAnalyzeCommand(input);

  logger.info('Offline media analysis', {
    execute: input.execute,
    model: input.model,
    prompt_bytes: Buffer.byteLength(input.prompt, 'utf8'),
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: input.execute ? 300_000 : 30_000,
      maxBuffer: 4 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput('php artisan media:offline-analyze --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('media:offline-analyze', err);
  }
}

export async function plosImageGenerate(
  input: PlosImageGenerateInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildImageGenerateCommand(input);

  logger.info('Offline image generation', {
    execute: input.execute,
    model: input.model,
    prompt_bytes: Buffer.byteLength(input.prompt, 'utf8'),
    width: input.width,
    height: input.height,
    steps: input.steps,
    has_seed: input.seed !== undefined,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: input.execute ? 900_000 : 30_000,
      maxBuffer: 4 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput('php artisan media:image-generate --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('media:image-generate', err);
  }
}

function localPhpArtisan(args: string[]): string {
  return `cd -- ${shellQuote(CONFIG.paths.projectRoot)} && php artisan ${args.map(shellQuote).join(' ')}`;
}

function formatToolOutput(label: string, stdout: string, stderr: string): string {
  const output = (stdout + (stderr ? `\n[stderr] ${stderr}` : '')).trim();

  return `[DEV] ${label}\n\n${output}`;
}

function formatToolError(label: string, err: unknown): string {
  const error = err as { stdout?: string; stderr?: string; message?: string; code?: number | string | null; killed?: boolean };
  const output = error.stdout?.trim() || '';
  const errMsg = error.stderr?.trim() || error.message || 'Unknown error';
  const prefix = error.killed ? '[TIMEOUT]' : '[ERROR]';

  return `${prefix} php artisan ${label}\nExit code: ${error.code ?? 'unknown'}\n\n${output}\n\n${errMsg}`;
}

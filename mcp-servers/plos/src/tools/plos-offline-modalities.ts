import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

const rerankDocument = z.union([
  z.string(),
  z.object({
    id: z.union([z.string(), z.number()]).optional(),
    title: z.string().optional(),
    text: z.string().optional(),
    content: z.string().optional(),
    body: z.string().optional(),
    description: z.string().optional(),
    score: z.number().optional(),
    similarity: z.number().optional(),
  }).passthrough(),
]);

export const plosAudioTtsInput = z.object({
  text: z.string().optional().default('')
    .describe('Text to synthesize. Leave blank with probe=true to only report local TTS readiness.'),
  execute: z.coerce.boolean().optional().default(false)
    .describe('Create an audio file. Default false is a dry-run/probe and writes nothing.'),
  probe: z.coerce.boolean().optional().default(false)
    .describe('Only report TTS capability and skip synthesis planning.'),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for the local checkout/offline laptop.'),
  voice: z.string().optional().default('')
    .describe('Optional local TTS voice override.'),
  model: z.string().optional().default('')
    .describe('Optional local TTS model path/name override, commonly required for piper.'),
  output: z.string().optional().default('')
    .describe('Optional output path for execute=true. Leave blank to use storage/app/offline-tts.'),
});

export const plosRerankInput = z.object({
  query: z.string().describe('Query text used to rank the supplied documents.'),
  documents: z.array(rerankDocument).min(1)
    .describe('Documents as strings or objects with id/title/text/content/body/description.'),
  limit: z.coerce.number().int().optional().default(10)
    .describe('Maximum ranked results to return. Capped to 50.'),
  use_embeddings: z.coerce.boolean().optional().default(false)
    .describe('Blend lexical scores with table-routed PLOS AIService embeddings. Default false stays fast and local-command only.'),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for the local checkout/offline laptop.'),
});

export type PlosAudioTtsInput = z.infer<typeof plosAudioTtsInput>;
export type PlosRerankInput = z.infer<typeof plosRerankInput>;

export function buildAudioTtsCommand(input: PlosAudioTtsInput): string {
  const args = ['audio:tts', '--json'];
  const text = input.text.trim();

  if (text !== '') {
    args.push(`--text-b64=${Buffer.from(text, 'utf8').toString('base64')}`);
  }
  if (input.voice.trim() !== '') {
    args.push(`--voice=${input.voice.trim()}`);
  }
  if (input.model.trim() !== '') {
    args.push(`--model=${input.model.trim()}`);
  }
  if (input.output.trim() !== '') {
    args.push(`--output=${input.output.trim()}`);
  }

  if (input.probe) {
    args.push('--probe');
  }
  args.push(input.execute && !input.probe ? '--execute' : '--dry-run');

  return phpArtisan(args, input.on_prod);
}

export function buildRerankCommand(input: PlosRerankInput): string {
  const limit = Math.min(Math.max(1, input.limit), 50);
  const args = [
    'rag:rerank',
    '--json',
    `--query-b64=${Buffer.from(input.query, 'utf8').toString('base64')}`,
    `--documents-json-b64=${Buffer.from(JSON.stringify(input.documents), 'utf8').toString('base64')}`,
    `--limit=${limit}`,
    input.use_embeddings ? '--use-embeddings' : '--no-embeddings',
  ];

  return phpArtisan(args, input.on_prod);
}

export async function plosAudioTts(
  input: PlosAudioTtsInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildAudioTtsCommand(input);

  logger.info('Offline audio TTS', {
    on_prod: input.on_prod,
    execute: input.execute,
    probe: input.probe,
    text_bytes: Buffer.byteLength(input.text, 'utf8'),
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: input.execute ? 180_000 : 30_000,
      maxBuffer: 2 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan audio:tts --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('audio:tts', err);
  }
}

export async function plosRerank(
  input: PlosRerankInput,
  context?: ToolContext,
): Promise<string> {
  const shellCmd = buildRerankCommand(input);

  logger.info('Offline RAG rerank', {
    on_prod: input.on_prod,
    use_embeddings: input.use_embeddings,
    document_count: input.documents.length,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: input.use_embeddings ? 300_000 : 30_000,
      maxBuffer: 2 * 1024 * 1024,
      signal: context?.signal,
    });

    return formatToolOutput(input.on_prod, 'php artisan rag:rerank --json', stdout, stderr);
  } catch (err: unknown) {
    return formatToolError('rag:rerank', err);
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

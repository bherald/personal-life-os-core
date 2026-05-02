import { z } from 'zod';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

export const plosTinkerInput = z.object({
  code: z.string().describe(
    'PHP code to execute via artisan tinker --execute. Do NOT include <?php tags. ' +
    'Example: "$s = app(App\\\\Services\\\\ContactsPersistenceService::class); echo json_encode($s->getStats());"'
  ),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for dev.'),
  timeout: z.coerce.number().optional().default(30)
    .describe('Timeout in seconds (max 120)'),
});

export type PlosTinkerInput = z.infer<typeof plosTinkerInput>;

// Patterns that are never allowed even with full trust
const BLOCKED_PATTERNS = [
  /DROP\s+DATABASE/i,
  /TRUNCATE\s+TABLE/i,
  /rm\s+-rf\s+\//,
  /unlink.*\.env/i,
  /file_put_contents.*\.env/i,
  /\b(exec|system|passthru|shell_exec|proc_open|popen|pcntl_exec)\s*\(/i,
  /\b(curl_exec|fsockopen|stream_socket_client)\s*\(/i,
  /\b(file_put_contents|fopen|fwrite|unlink|rename|copy|mkdir|rmdir|chmod|chown)\s*\(/i,
  /\b(Storage|File)::(put|delete|move|copy|makeDirectory|deleteDirectory|cleanDirectory)\b/,
  /\bDB::(statement|unprepared|insert|update|delete|transaction)\b/,
  /->\s*(save|update|delete|forceDelete|restore|increment|decrement|attach|detach|sync)\s*\(/,
  /::\s*(create|insert|upsert|truncate|destroy)\s*\(/,
  /\b(Artisan|Queue|Bus)::/i,
  /\bdispatch\s*\(/i,
];

export async function plosTinker(input: PlosTinkerInput, context?: ToolContext): Promise<string> {
  const { code, on_prod, timeout: timeoutSec } = input;
  const effectiveTimeout = Math.min(Math.max(5, timeoutSec), 120) * 1000;

  // Safety check — block destructive patterns
  for (const pattern of BLOCKED_PATTERNS) {
    if (pattern.test(code)) {
      return `BLOCKED: Code matches dangerous pattern: ${pattern.source}`;
    }
  }

  if (on_prod && !/\b(echo|print|json_encode|var_export|dump|dd)\b/.test(code)) {
    return 'BLOCKED: Remote-instance tinker code must explicitly emit read-only output (for example via echo or json_encode).';
  }

  const remotePath = CONFIG.paths.remoteRoot;

  // Escape the PHP code for shell embedding
  // Use base64 to avoid shell escaping nightmares
  const b64 = Buffer.from(code).toString('base64');
  const phpWrapper = `eval(base64_decode('${b64}'))`;

  let shellCmd: string;
  if (on_prod) {
    // Double-escape for SSH
    const escaped = phpWrapper.replace(/'/g, "'\\''");
    shellCmd = `${sshCmd()} "cd -- ${shellQuote(remotePath)} && php artisan tinker --execute='${escaped}'"`;
  } else {
    shellCmd = `cd -- ${shellQuote(CONFIG.paths.projectRoot)} && php artisan tinker --execute="${phpWrapper.replace(/"/g, '\\"')}"`;
  }

  logger.info('Tinker execute', {
    on_prod,
    code_length: code.length,
    timeout_ms: effectiveTimeout,
  });

  try {
    const { stdout, stderr } = await execCommand(shellCmd, {
      timeout: effectiveTimeout,
      maxBuffer: 1024 * 1024, // 1MB output
      signal: context?.signal,
    });

    const output = stdout.trim();
    const warnings = stderr?.trim();
    const env = on_prod ? 'REMOTE' : 'DEV';

    let result = `[${env}] tinker (${code.length} chars)\n\n${output}`;
    if (warnings) {
      result += `\n\n[warnings]\n${warnings}`;
    }

    // Truncate if too large
    if (result.length > 50_000) {
      result = result.substring(0, 50_000) + '\n\n... [truncated at 50KB]';
    }

    return result;
  } catch (err: unknown) {
    const error = err as { stdout?: string; stderr?: string; message?: string; code?: number; killed?: boolean };

    if (error.killed) {
      return `[TIMEOUT] Tinker execution exceeded ${timeoutSec}s limit.\n\nPartial output:\n${error.stdout?.trim() || '(none)'}`;
    }

    const output = error.stdout?.trim() || '';
    const errMsg = error.stderr?.trim() || error.message || 'Unknown error';
    return `[ERROR] Tinker failed (exit ${error.code})\n\n${output}\n\n${errMsg}`;
  }
}

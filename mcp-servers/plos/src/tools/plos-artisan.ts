import { z } from 'zod';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { CONFIG, shellQuote, sshCmd } from '../config.js';
import { logger } from '../util/logger.js';
import { execCommand } from '../util/exec.js';
import type { ToolContext } from '../util/tool-context.js';

// Whitelisted artisan commands — diagnostics, bounded previews, and safe ops
export const ALLOWLIST_REVISION = 'public-v2.1-neutral-installation';

const ALLOWLIST_REVISION_PATTERN = /ALLOWLIST_REVISION\s*=\s*['"]([^'"]+)['"]/;

export type AllowlistFreshness = {
  status: 'current' | 'stale' | 'unknown';
  loaded_revision: string;
  source_revision: string | null;
  dist_revision: string | null;
  mismatched_revision_count: number;
};

type AllowedCommandMeta = { description: string; timeout: number; maxBuffer?: number };

type AllowedCommands = Record<string, AllowedCommandMeta>;

export type DynamicAllowedCommand = { command: string; meta: AllowedCommandMeta };

export type DynamicCommandResolver = (command: string, support?: { boundedExecutor: boolean }) => DynamicAllowedCommand | null;

type AllowlistModuleCandidate = {
  ALLOWLIST_REVISION?: unknown;
  ALLOWED_COMMANDS?: unknown;
  COMPACT_SCORECARD_COMMANDS?: unknown;
  resolveDynamicAllowedCommand?: unknown;
};

type ResolvedAllowlistPolicy = {
  source: 'loaded' | 'dist';
  revision: string;
  commands: AllowedCommands;
  compactCommands: readonly string[];
  dynamicCommandResolver: DynamicCommandResolver;
  freshness: AllowlistFreshness;
};

export function extractAllowlistRevision(contents: string): string | null {
  return contents.match(ALLOWLIST_REVISION_PATTERN)?.[1] ?? null;
}

export function allowlistContentFingerprint(contents: string): string {
  return createHash('sha256').update(contents).digest('hex').slice(0, 16);
}

export function allowlistFreshness(input: {
  sourceText?: string | null;
  distText?: string | null;
} = {}): AllowlistFreshness {
  const moduleDir = dirname(fileURLToPath(import.meta.url));
  const sourceText = Object.hasOwn(input, 'sourceText')
    ? input.sourceText
    : readTextIfPresent(resolve(moduleDir, '../../src/tools/plos-artisan.ts'));
  const distText = Object.hasOwn(input, 'distText')
    ? input.distText
    : readTextIfPresent(resolve(moduleDir, 'plos-artisan.js'));

  const sourceRevision = sourceText === null || sourceText === undefined ? null : extractAllowlistRevision(sourceText);
  const distRevision = distText === null || distText === undefined ? null : extractAllowlistRevision(distText);
  const revisions = [sourceRevision, distRevision].filter((revision): revision is string => revision !== null);
  const mismatchedRevisionCount = revisions.filter(revision => revision !== ALLOWLIST_REVISION).length;

  return {
    status: revisions.length === 0 ? 'unknown' : (mismatchedRevisionCount > 0 ? 'stale' : 'current'),
    loaded_revision: ALLOWLIST_REVISION,
    source_revision: sourceRevision,
    dist_revision: distRevision,
    mismatched_revision_count: mismatchedRevisionCount,
  };
}

function readTextIfPresent(path: string): string | null {
  if (!existsSync(path)) {
    return null;
  }

  return readFileSync(path, 'utf8');
}

function allowlistFreshnessLine(freshness: AllowlistFreshness = allowlistFreshness()): string {
  return `Allowlist freshness: status=${freshness.status} loaded=${freshness.loaded_revision} source=${freshness.source_revision ?? 'unknown'} dist=${freshness.dist_revision ?? 'unknown'} mismatches=${freshness.mismatched_revision_count}`;
}

export const COMPACT_SCORECARD_COMMANDS = [
  'ops:offline-smoke --json --compact',
  'ops:mcp-health --json --compact',
  'ops:mcp-tool-contract-audit --json --compact --fail-on-violations',
  'ops:agent-doctor --json --compact --since=24',
  'ops:agent-doctor-history --json --compact --days=7',
  'plos:agent-trace-tail --limit=20 --since=24 --json --compact',
  'episodic:memory --stats --json --compact',
  'agent:procedures --stats --json --compact',
  'llm:sync-providers --json --compact',
  'codex:exec-smoke --json',
  'ops:local-runtime-scorecard --json --compact',
  'ops:scheduled-output-audit --json --compact --fail-on-violations',
  'ops:runtime-diagnostics --json --compact',
  'ops:queue-fit-audit --json --compact',
  'ops:capacity-utilization-manager --json --compact',
  'ollama:drift-check --json --compact --no-fail',
  'ollama:eval-scorecard --json --compact',
] as const;

export const ALLOWED_COMMANDS: Record<string, { description: string; timeout: number }> = {
  'files:face-coverage --status --json': { description: 'Read-only aggregate anonymous photo coverage status without detection or identity decisions', timeout: 30_000 },
  'files:image-audit --status --json': { description: 'Read-only aggregate image audit status without starting a worker or changing sources or identities', timeout: 30_000 },
  'ops:validate-sql':            { description: 'Static SQL validation (pre-deploy)', timeout: 60_000 },
  'ops:validate-sql --explain':  { description: 'EXPLAIN SQL validation (remote/post-deploy)', timeout: 120_000 },
  'ops:smoke-test':              { description: 'Post-deploy smoke test', timeout: 60_000 },
  'ops:smoke-test --quick':      { description: 'Quick smoke test', timeout: 30_000 },
  'ops:health-gate':             { description: 'All validation gates (deploy blocker)', timeout: 60_000 },
  'ops:health-gate --quick':     { description: 'Quick health gate', timeout: 30_000 },
  'ops:daily-report --dry-run':  { description: 'Morning digest preview (no send)', timeout: 60_000 },
  'ops:operator-evidence --compact': { description: 'Compact operator evidence dashboard summary', timeout: 30_000 },
  'ops:operator-evidence --json --compact': { description: 'Compact operator evidence dashboard payload', timeout: 30_000 },
  'ops:agent-tool-policy-refresh --json --compact': { description: 'Dry-run table-backed agent tool policy metadata refresh summary', timeout: 30_000 },
  'ops:agent-tool-policy-refresh --apply --json --compact': { description: 'Apply deterministic agent tool policy metadata refresh without tool execution', timeout: 60_000 },
  'ops:review-backlog-report --json': { description: 'Read-only review backlog triage report', timeout: 30_000 },
  'ops:review-backlog-report --markdown': { description: 'Read-only review backlog triage markdown', timeout: 30_000 },
  'ops:review-backlog-report --dry-run': { description: 'Read-only review backlog dry-run report', timeout: 30_000 },
  'ops:review-backlog-report --dry-run --json --compact': { description: 'Compact read-only review backlog dry-run JSON without queries', timeout: 30_000 },
  'ops:review-backlog-report --compact': { description: 'Compact read-only review backlog triage report', timeout: 30_000 },
  'ops:review-backlog-report --json --compact': { description: 'Compact read-only review backlog triage JSON', timeout: 30_000 },
  'ops:review-backlog-report --markdown --compact': { description: 'Compact read-only review backlog triage markdown', timeout: 30_000 },
  'ops:review-backlog-report --next-target': { description: 'Read-only sanitized next review target', timeout: 30_000 },
  'ops:review-backlog-report --json --next-target': { description: 'Read-only sanitized next review target JSON', timeout: 30_000 },
  'ops:review-backlog-report --next-target --focus=typed-remediation --json': { description: 'Read-only sanitized typed-remediation review target JSON', timeout: 30_000 },
  'ops:review-backlog-report --next-target --focus=materializable-remediation --json': { description: 'Read-only sanitized materializable remediation review target JSON', timeout: 30_000 },
  'ops:review-backlog-report --next-target --focus=source-backed-packet --json': { description: 'Read-only sanitized source-backed review packet target JSON', timeout: 30_000 },
  'ops:review-backlog-report --next-target --focus=aged-review --json': { description: 'Read-only sanitized oldest aged review target JSON', timeout: 30_000 },
  'ops:review-backlog-report --json --compact --next-target --focus=face-photo-repair-packet': { description: 'Compact read-only sanitized face/photo repair packet review target JSON', timeout: 30_000 },
  'ops:offline-status --json':   { description: 'Offline/degraded runtime status', timeout: 15_000 },
  'ops:offline-smoke --json --compact': { description: 'Compact read-only offline smoke report without raw details', timeout: 30_000 },
  'ops:agent-doctor --compact': { description: 'Compact observe-only agent health diagnostics', timeout: 30_000 },
  'ops:agent-doctor --json --compact': { description: 'Compact observe-only agent health diagnostics JSON', timeout: 30_000 },
  'ops:agent-doctor --json --compact --since=24': { description: 'Compact observe-only 24-hour agent health diagnostics JSON', timeout: 30_000 },
  'plos:agent-doctor --compact': { description: 'Compact PLOS operator-facing agent diagnostics', timeout: 30_000 },
  'plos:agent-doctor --json --compact': { description: 'Compact PLOS operator-facing agent diagnostics JSON', timeout: 30_000 },
  'ops:agent-doctor-snapshot --dry-run --json': { description: 'Dry-run aggregate Agent Doctor readiness snapshot', timeout: 30_000 },
  'ops:agent-doctor-history --json --compact --days=7': { description: 'Compact read-only Agent Doctor readiness snapshot history', timeout: 30_000 },
  'plos:agent-trace-tail --limit=20 --since=24 --json --compact': { description: 'Compact read-only recent agent trace tail counts without events, ids, actor ids, paths, or payload details', timeout: 30_000 },
  'ops:mcp-health --compact': { description: 'Compact observe-only MCP configuration and process health scorecard', timeout: 30_000 },
  'ops:mcp-health --json --compact': { description: 'Compact observe-only MCP configuration and process health JSON', timeout: 30_000 },
  'ops:mcp-tool-contract-audit --json --compact --fail-on-violations': { description: 'Strict content-free live PLOS MCP tools/list security and baseline-drift audit', timeout: 30_000 },
  'llm:sync-providers --json --compact': { description: 'Compact read-only LLM provider model diff and role/capability review', timeout: 60_000 },
  'llm:sync-providers --json --compact --no-live': { description: 'Compact read-only LLM provider role/capability review without network probes', timeout: 30_000 },
  'codex:exec-smoke --json': { description: 'Dry-run table-backed Codex Exec connector smoke without invoking Codex', timeout: 30_000 },
  'codex:offline-capabilities --json': { description: 'Read-only offline Codex/PLOS media, pipeline, MCP, and operator-gate capability manifest', timeout: 30_000 },
  'codex:offline-capabilities --json --probe-sidecars': { description: 'Offline Codex/PLOS capability manifest with configured local/LAN sidecar health probes', timeout: 45_000 },
  'codex:offline-pipeline-tools --json': { description: 'Read-only ordered PLOS AI pipeline function/tool/option inventory for offline agents', timeout: 30_000 },
  'codex:offline-pipeline-tools --json --compact': { description: 'Compact ordered PLOS AI pipeline function/tool/option inventory for offline agents', timeout: 30_000 },
  'codex:offline-sidecar --json --dry-run': { description: 'Read-only offline Codex sidecar coordinator plan with default prompt; use plos_offline_sidecar for custom prompts', timeout: 30_000 },
  'codex:offline-improvement --json --example': { description: 'Read-only Hermes-inspired offline skill/memory/tool/test improvement proposal example', timeout: 30_000 },
  'codex:offline-prompt-candidate-eval --json --compact --example --fail-on-ineligible': { description: 'Read-only hashes-only multi-metric prompt candidate evaluation example with disjoint held-out evidence', timeout: 30_000 },
  'content:ocr-candidate-eval --example --json --compact --fail-on-ineligible': { description: 'Read-only content-free OCR fidelity, exact-fact, page-provenance, hallucination, latency, and VRAM gate example', timeout: 30_000 },
  'content:stt-candidate-eval --example --json --compact --fail-on-ineligible': { description: 'Read-only content-free heterogeneous ASR WER, CER, language, silence, and real-time-factor gate example', timeout: 30_000 },
  'audio:tts-candidate-eval --example --json --compact --fail-on-ineligible': { description: 'Read-only content-free TTS intelligibility, naturalness, audio-defect, and real-time-factor gate example', timeout: 30_000 },
  'content:extraction-provenance-audit --json --compact': { description: 'Compact read-only content-free extraction contract, confidence, page coverage, and review-state audit', timeout: 30_000 },
  'rag:chunking-provenance-audit --json --compact': { description: 'Compact read-only semantic chunk configuration and output-set provenance audit without chunk content', timeout: 30_000 },
  'codex:offline-improvement-review --json --compact': { description: 'Read-only summary of saved offline improvement proposals pending review', timeout: 30_000 },
  'codex:offline-improvement-review --json --compact --stage-review': { description: 'Read-only preview of the review queue item that would lift saved offline improvements to operator attention', timeout: 30_000 },
  'audio:tts --json --probe': { description: 'Read-only offline audio/TTS capability probe; use plos_audio_tts for custom text or execute mode', timeout: 30_000 },
  'rag:rerank --json --example --no-embeddings': { description: 'Read-only generic RAG rerank smoke example without live embedding calls; use plos_rerank for custom documents', timeout: 30_000 },
  'rag:rerank-eval --json --compact --fail-on-regression': { description: 'Read-only content-free RAG ranking plus claim-recall/context-precision regression gate', timeout: 30_000 },
  'bitllm:privacy-gate --eval --json': { description: 'Run the redacted local BitLLM privacy eval pack through table-routed providers', timeout: 120_000 },
  'ops:local-runtime-scorecard --json --compact': { description: 'Compact observe-only local/runtime LLM privacy and readiness scorecard without probes', timeout: 30_000 },
  'ops:scheduled-output-audit --json --compact --fail-on-violations': { description: 'Compact retained scheduled-output key hygiene audit without raw output or job rows', timeout: 30_000 },
  'ops:scheduled-output-audit --json --compact': { description: 'Compact retained scheduled-output key hygiene audit without raw output or job rows', timeout: 30_000 },
  'ollama:drift-check --json --compact --no-fail': { description: 'Compact read-only Ollama live-vs-DB model drift summary without model names or host URLs', timeout: 60_000 },
  'ollama:eval-scorecard --json --compact': { description: 'Compact read-only Ollama Sprint A routing, candidate, and compression scorecard', timeout: 30_000 },
  'ops:capacity-report --json':   { description: 'Observe-only capacity evidence report', timeout: 30_000 },
  'ops:capacity-checkpoint --json': { description: 'Observe-only no-decision capacity checkpoint JSON', timeout: 60_000 },
  'ops:capacity-checkpoint --json --compact': { description: 'Compact observe-only no-decision capacity checkpoint JSON', timeout: 60_000 },
  'ops:capacity-checkpoint --markdown': { description: 'Observe-only no-decision capacity checkpoint markdown', timeout: 60_000 },
  'ops:capacity-checkpoint --dry-run --json': { description: 'Dry-run capacity checkpoint wiring and shape JSON', timeout: 30_000 },
  'ops:capacity-utilization-manager --json --compact': { description: 'Compact dry-run capacity utilization manager posture without starting scheduler lanes', timeout: 120_000 },
  'ops:capacity-utilization-manager --dry-run --json --compact': { description: 'Explicit compact dry-run capacity utilization manager posture without starting scheduler lanes', timeout: 120_000 },
  'ops:runtime-diagnostics --window=60m --focus=all --json': { description: 'Read-only runtime recovery diagnostics', timeout: 30_000 },
  'ops:runtime-diagnostics --json --compact': { description: 'Compact read-only runtime recovery diagnostics with default 60m window', timeout: 30_000 },
  'ops:runtime-diagnostics --window=60m --focus=all --json --compact': { description: 'Compact read-only 60m runtime recovery diagnostics', timeout: 30_000 },
  'ops:runtime-diagnostics --window=24h --json --compact': { description: 'Compact read-only 24h runtime recovery diagnostics', timeout: 60_000 },
  'ops:runtime-diagnostics --window=24h --focus=all --json --compact': { description: 'Compact read-only 24h runtime recovery diagnostics with explicit focus', timeout: 60_000 },
  'ops:queue-fit-audit --json --compact': { description: 'Compact inferential queue fit audit without job names, row IDs, or reasons', timeout: 30_000 },
  'ops:runtime-policy-leak-audit --json --compact': { description: 'Compact observe-only audit for hardcoded runtime policy candidates', timeout: 120_000 },
  'ops:runtime-policy-leak-audit --json --findings-limit=40': { description: 'Bounded observe-only runtime policy candidate samples for manual review', timeout: 120_000 },
  'ops:runtime-policy-leak-audit --json --compact --findings-limit=40': { description: 'Compact observe-only runtime policy audit with bounded omitted-detail counts', timeout: 120_000 },
  'ops:face-telemetry-report --json': { description: 'Face/genealogy telemetry report', timeout: 30_000 },
  'ops:face-telemetry-report --markdown --hours=168': { description: 'Weekly face/genealogy telemetry markdown', timeout: 30_000 },
  'ops:face-telemetry-report --compact': { description: 'Compact face/genealogy telemetry report', timeout: 30_000 },
  'ops:face-telemetry-report --json --compact': { description: 'Compact face/genealogy telemetry JSON', timeout: 30_000 },
  'ops:face-telemetry-report --markdown --compact': { description: 'Compact face/genealogy telemetry markdown', timeout: 30_000 },
  'ops:face-mixed-cluster-report --limit=5 --sample-faces=3 --json': { description: 'Read-only face mixed-cluster operator packet with sanitized face samples', timeout: 30_000 },
  'ops:face-mixed-cluster-report --limit=10 --sample-faces=0 --compact --json': { description: 'Compact read-only mixed-cluster face report without face samples', timeout: 30_000 },
  'ops:face-integrity-audit --json --limit=20': { description: 'Read-only face integrity audit with bounded operator samples', timeout: 30_000 },
  'ops:face-integrity-audit --json --compact --limit=20': { description: 'Compact read-only face integrity audit without samples', timeout: 30_000 },
  'ops:face-named-only-slice --limit=10 --json': { description: 'Read-only named-only face operator slice without writeback', timeout: 30_000 },
  'ops:face-named-only-slice --limit=10 --cluster-scope=mixed --sort=oldest --compact --json': { description: 'Compact read-only named-only mixed-cluster face review slice without writeback', timeout: 30_000 },
  'ops:audit-privacy-routing --json --compact': { description: 'Compact read-only privacy routing audit without provider or tool lists', timeout: 30_000 },
  'ops:dba-telemetry-report --compact': { description: 'Compact observe-only DBA telemetry report', timeout: 60_000 },
  'ops:dba-telemetry-report --json --compact': { description: 'Compact observe-only DBA telemetry JSON', timeout: 60_000 },
  'ops:dba-telemetry-report --markdown --compact': { description: 'Compact observe-only DBA telemetry markdown', timeout: 60_000 },
  'ops:dba-telemetry-report --markdown --dry-run': { description: 'Dry-run observe-only DBA telemetry markdown', timeout: 60_000 },
  'ops:arc-retention --json --compact': { description: 'Compact read-only ARC retention dry-run evidence without environment names, row IDs, or raw timestamps', timeout: 30_000 },
  'ops:audit-privacy-routing --json': { description: 'Sensitive-provider privacy routing audit', timeout: 30_000 },
  'ops:sync-schema-reference':   { description: 'Regenerate schema-reference.md from live DB', timeout: 30_000 },
  'ops:sync-schema-reference --diff': { description: 'Show schema drift without writing', timeout: 30_000 },
  'files:registry --status':     { description: 'File registry status', timeout: 15_000 },
  'files:reconcile-lifecycle --compact': { description: 'Compact read-only file lifecycle reconciliation evidence', timeout: 30_000 },
  'files:reconcile-lifecycle --json --compact': { description: 'Compact JSON read-only file lifecycle reconciliation evidence', timeout: 30_000 },
  'files:reconcile-lifecycle --markdown --compact': { description: 'Compact markdown read-only file lifecycle reconciliation evidence', timeout: 30_000 },
  'files:resolve-duplicates --status --json --compact': { description: 'Compact read-only duplicate resolver status for the default Family Tree Maker scope without paths or row selectors', timeout: 30_000 },
  'files:materialize-duplicate-candidates --json --compact --limit=1000': { description: 'Compact dry-run duplicate candidate materialization evidence without file paths or raw samples', timeout: 60_000 },
  'joplin:rag-reconcile --json --event-hours=24': { description: 'Read-only Joplin RAG reconcile dry-run for 24-hour context events', timeout: 60_000 },
  'joplin:rag-reconcile --json --event-hours=24 --triggered-only': { description: 'Read-only Joplin RAG reconcile dry-run scoped to triggered context events', timeout: 60_000 },
  'joplin:rag-reconcile --json --event-hours=24 --triggered-only --limit=50 --max-delete-candidates=50 --max-dependent-rows=5000': { description: 'Capped scheduled Joplin RAG reconcile dry-run without execute/writeback', timeout: 60_000 },
  'joplin:rag-refresh-touch --json --compact --limit=50 --max-candidates=200': { description: 'Compact dry-run Joplin RAG timestamp refresh-touch verifier without writeback', timeout: 120_000 },
  'files:thumbnails --stats':    { description: 'Thumbnail pipeline stats', timeout: 15_000 },
  'genealogy:reject-codes --json': { description: 'Genealogy reviewer reject-code rollup', timeout: 30_000 },
  'genealogy:reject-codes --json --days=30': { description: 'Genealogy reviewer reject-code 30-day rollup', timeout: 30_000 },
  'genealogy:reject-codes --compact': { description: 'Compact genealogy reviewer reject-code text rollup', timeout: 30_000 },
  'genealogy:reject-codes --json --compact': { description: 'Compact genealogy reviewer reject-code rollup', timeout: 30_000 },
  'genealogy:review-feedback --days=30 --json': { description: 'Genealogy reviewer feedback rollup', timeout: 30_000 },
  'genealogy:review-feedback --compact': { description: 'Compact genealogy reviewer feedback text rollup', timeout: 30_000 },
  'genealogy:review-feedback --json --compact': { description: 'Compact genealogy reviewer feedback rollup', timeout: 30_000 },
  'genealogy:packet-reason-codes --days=30 --json': { description: 'Genealogy review-packet reason-code rollup', timeout: 30_000 },
  'genealogy:packet-reason-codes --compact': { description: 'Compact genealogy review-packet reason-code text rollup', timeout: 30_000 },
  'genealogy:packet-reason-codes --json --compact': { description: 'Compact genealogy review-packet reason-code rollup', timeout: 30_000 },
  'genealogy:evidence-sprint-report --json': { description: 'Read-only genealogy evidence sprint readiness report', timeout: 30_000 },
  'genealogy:evidence-sprint-report --json --compact': { description: 'Compact genealogy evidence sprint readiness report', timeout: 30_000 },
  'genealogy:evidence-sprint-report --markdown': { description: 'Read-only genealogy evidence sprint readiness markdown', timeout: 30_000 },
  'genealogy:evidence-sprint-report --markdown --compact': { description: 'Compact genealogy evidence sprint readiness markdown', timeout: 30_000 },
  'genealogy:evidence-asset-candidates --compact': { description: 'Compact read-only genealogy review evidence asset candidate report', timeout: 30_000 },
  'genealogy:evidence-asset-candidates --json --compact': { description: 'Compact read-only genealogy review evidence asset candidate JSON', timeout: 30_000 },
  'genealogy:evidence-asset-candidates --markdown --compact': { description: 'Compact read-only genealogy review evidence asset candidate markdown', timeout: 30_000 },
  'genealogy:evidence-asset-candidates --dry-run --json --compact': { description: 'Dry-run compact genealogy review evidence asset candidate JSON without queries', timeout: 30_000 },
  'genealogy:evidence-asset-capture-plan --json --compact': { description: 'Compact read-only genealogy evidence asset capture plan JSON', timeout: 30_000 },
  'genealogy:evidence-asset-capture-plan --dry-run --json --compact': { description: 'Dry-run compact genealogy evidence asset capture plan JSON without queries', timeout: 30_000 },
  'genealogy:evidence-asset-capture-review --json --compact': { description: 'Compact dry-run genealogy evidence asset capture review materialization plan', timeout: 30_000 },
  'genealogy:evidence-asset-capture-executor --json --compact': { description: 'Compact read-only genealogy evidence asset capture executor preflight', timeout: 30_000 },
  'genealogy:media-intake-report --json --compact': { description: 'Compact read-only genealogy media intake gap report JSON', timeout: 30_000 },
  'genealogy:media-intake-report --markdown --compact': { description: 'Compact read-only genealogy media intake gap markdown', timeout: 30_000 },
  'genealogy:media-intake-report --dry-run --json --compact': { description: 'Dry-run compact genealogy media intake report JSON without row queries', timeout: 30_000 },
  'genealogy:media-intake-run --dry-run --json --compact': { description: 'Dry-run compact genealogy media intake wrapper without row queries or subcommands', timeout: 30_000 },
  'genealogy:media-intake-run --dry-run --json --compact --stage --transcribe-dry-run --enrich-dry-run --evidence-assets': { description: 'Dry-run compact genealogy media intake wrapper with all no-write stages planned', timeout: 30_000 },
  'genealogy:enrich-media --status': { description: 'Read-only genealogy media enrichment status with aggregate eligibility blockers', timeout: 30_000 },
  'genealogy:transcribe-media --status': { description: 'Read-only genealogy HTR status with aggregate eligibility blockers', timeout: 30_000 },
  'genealogy:agent-triage --json': { description: 'Read-only genealogy agent re-enablement triage', timeout: 30_000 },
  'genealogy:agent-triage --compact': { description: 'Compact genealogy agent re-enablement triage text', timeout: 30_000 },
  'genealogy:agent-triage --json --compact': { description: 'Compact genealogy agent re-enablement triage', timeout: 30_000 },
  'genealogy:agent-triage --days=30 --json --compact': { description: 'Compact 30-day genealogy agent re-enablement triage', timeout: 30_000 },
  'genealogy:source-registry --validate': { description: 'Read-only genealogy source registry posture validation', timeout: 30_000 },
  'genealogy:source-registry --validate --json --compact': { description: 'Compact read-only genealogy source registry posture validation JSON', timeout: 30_000 },
  'awo:replay --window=7d --json': { description: 'Approval-worthy-output read-only replay', timeout: 30_000 },
  'awo:replay --window=7d --compact': { description: 'Compact approval-worthy-output read-only replay with default evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --json --compact': { description: 'Compact approval-worthy-output read-only replay JSON with default evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --markdown --compact': { description: 'Compact approval-worthy-output read-only markdown report with default evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --json': { description: 'Approval-worthy-output read-only replay with weekly evidence limit', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --compact': { description: 'Compact approval-worthy-output read-only replay', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --json --compact': { description: 'Compact approval-worthy-output read-only replay JSON', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --markdown --compact': { description: 'Compact approval-worthy-output read-only markdown report', timeout: 30_000 },
  'awo:replay --window=7d --limit=500 --markdown': { description: 'Weekly approval-worthy-output read-only markdown report', timeout: 30_000 },
  'awo:replay --compare-scheduled --window=7d --limit=500 --json': { description: 'Read-only AWO scheduled report comparison', timeout: 30_000 },
  'scheduler:optimize-report --json': { description: 'Observe-only scheduler optimization recommendations', timeout: 30_000 },
  'scheduler:optimize-report --window=7d --json': { description: 'Observe-only 7-day scheduler optimization recommendations', timeout: 30_000 },
  'scheduler:optimize-report --compact': { description: 'Compact scheduler optimization recommendations', timeout: 30_000 },
  'scheduler:optimize-report --json --compact': { description: 'Compact scheduler optimization recommendations JSON', timeout: 30_000 },
  'news:bias-tags-audit --json': { description: 'Read-only news-bias tag audit', timeout: 30_000 },
  'news:bias-tags-audit --compact': { description: 'Compact read-only news-bias tag audit', timeout: 30_000 },
  'news:bias-tags-audit --json --compact': { description: 'Compact read-only news-bias tag audit JSON', timeout: 30_000 },
  'news:pushover-proof --workflow=news_brief --compact': { description: 'Compact Pushover proof for the latest natural news brief run', timeout: 30_000 },
  'news:pushover-proof --workflow=news_brief --json --compact': { description: 'Compact Pushover proof JSON for the latest natural news brief run', timeout: 30_000 },
  'news:pushover-proof --workflow=\'Cybersecurity News Brief\' --compact': { description: 'Compact Pushover proof for the latest natural cybersecurity news run', timeout: 30_000 },
  'news:pushover-proof --workflow=\'Cybersecurity News Brief\' --json --compact': { description: 'Compact Pushover proof JSON for the latest natural cybersecurity news run', timeout: 30_000 },
  'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --compact': { description: 'Compact Pushover proof for the latest natural Press Enterprise run', timeout: 30_000 },
  'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --json --compact': { description: 'Compact Pushover proof JSON for the latest natural Press Enterprise run', timeout: 30_000 },
  'news:source-inventory --workflow=news_brief --days=7 --strict --json': { description: 'Read-only news source inventory and bias coverage', timeout: 30_000 },
  'news:source-inventory --workflow=news_brief --days=7 --strict --json --compact': { description: 'Compact read-only news source inventory and bias coverage', timeout: 30_000 },
  'bias:aliases --unmatched --limit=50 --json': { description: 'Read-only unmatched news-bias source aliases', timeout: 30_000 },
  'rag:raptor-build --stats':    { description: 'RAPTOR pipeline stats', timeout: 15_000 },
  'rag:build-sentences --stats': { description: 'Sentence embedding stats', timeout: 15_000 },
  'rag:build-knowledge-graph --stats': { description: 'Knowledge graph stats', timeout: 15_000 },
  'rag:backlog-report --json':   { description: 'RAG/RAPTOR/sentence/KG backlog evidence', timeout: 30_000 },
  'rag:backlog-report --compact': { description: 'Compact RAG/RAPTOR/sentence/KG backlog evidence', timeout: 30_000 },
  'rag:backlog-report --json --compact': { description: 'Compact RAG/RAPTOR/sentence/KG backlog evidence', timeout: 30_000 },
  'rag:scale-baseline --json':   { description: 'Read-only RAG scale baseline evidence', timeout: 60_000 },
  'rag:scale-baseline --markdown': { description: 'Read-only RAG scale baseline markdown', timeout: 60_000 },
  'rag:scale-review --json':   { description: 'Read-only RAG scale review evidence without saved retrieval file', timeout: 60_000 },
  'rag:scale-review --markdown': { description: 'Read-only RAG scale review markdown without saved retrieval file', timeout: 60_000 },
  'rag:scale-review --compact': { description: 'Compact read-only RAG scale review evidence without saved retrieval file', timeout: 60_000 },
  'rag:scale-review --json --compact': { description: 'Compact read-only RAG scale review JSON without saved retrieval file', timeout: 60_000 },
  'graph:audit-provenance --json --compact': { description: 'Compact knowledge graph provenance audit without sample rows or raw document titles', timeout: 60_000 },
  'graph:snapshot-provenance --dry-run --json': { description: 'Dry-run knowledge graph provenance snapshot payload', timeout: 60_000 },
  'graph:detect-communities --stats':  { description: 'Community detection stats', timeout: 15_000 },
  'graph:quality-metrics --stats':     { description: 'Graph quality metrics', timeout: 15_000 },
  'graph:quality-metrics --stats --json': { description: 'Graph quality metrics JSON stats only', timeout: 30_000 },
  'entity:resolve --stats':     { description: 'Entity resolution stats', timeout: 15_000 },
  'scheduler:run --job=workflow_youtube_watch_later --sync': { description: 'Operator-authorized single YouTube nightly execution with normal enabled, overlap, custody, controller and delivery gates; never force or retry automatically', timeout: 30_000 },
  'workflow:list':               { description: 'List all workflows', timeout: 15_000 },
  'agent:procedures --stats':   { description: 'Procedural memory stats', timeout: 15_000 },
  'agent:procedures --stats --json --compact': { description: 'Compact read-only procedural memory stats JSON', timeout: 15_000 },
  'episodic:memory --stats':    { description: 'Episodic memory stats', timeout: 15_000 },
  'episodic:memory --stats --json --compact': { description: 'Compact read-only episodic memory stats JSON', timeout: 15_000 },
  'skill:versions --stats':     { description: 'Skill version stats', timeout: 15_000 },
  'horizon:status':             { description: 'Queue worker status', timeout: 10_000 },
  'schedule:list':              { description: 'List scheduled commands', timeout: 15_000 },
};

const FACE_PREVIEW_PREFIX = ['files:enrich', '--type=faces', '--face-preview'] as const;
const FACE_PREVIEW_SELECTOR = /^--(?:media-id|face-review-id)=([1-9]\d*)$/;
const MAX_FACE_PREVIEW_SELECTORS = 5;
const FACE_REFERENCE_READINESS_COMMAND = 'files:face-reference-readiness';
const FACE_REFERENCE_SELECTOR = /^--face-id=([1-9]\d*)$/;
const MAX_FACE_REFERENCE_SELECTORS = 5;
const FACE_REFERENCE_MAX_BUFFER = 262144;
const PHP_INT_MAX = 9_223_372_036_854_775_807n;

function isPositiveInteger(value: string): boolean {
  if (!/^[1-9]\d*$/.test(value)) {
    return false;
  }

  try {
    return BigInt(value) <= PHP_INT_MAX;
  } catch {
    return false;
  }
}

/** Resolve only the canonical, explicit, bounded anonymous face-preview command form. */
export function resolveDynamicAllowedCommand(command: string, support?: { boundedExecutor: boolean }): DynamicAllowedCommand | null {
  // An old loaded executor calls the refreshed resolver without support and must stay denied.
  if (command === 'os-tools:review python3 --json' || command === 'os-tools:review prlimit --json') {
    return support?.boundedExecutor === true ? { command, meta: {
      description: 'Read bounded complete local OS-tool environment, executable and documentation evidence; no discovered executable execution or approval',
      timeout: 60_000, maxBuffer: 262144,
    } } : null;
  }
  const tokens = command.split(' ');
  if (tokens[0] === FACE_REFERENCE_READINESS_COMMAND) {
    if (support?.boundedExecutor !== true || tokens.join(' ') !== command || tokens.at(-1) !== '--json') {
      return null;
    }
    const selectors = tokens.slice(1, -1);
    if (selectors.length < 1 || selectors.length > MAX_FACE_REFERENCE_SELECTORS) {
      return null;
    }
    const ids: string[] = [];
    for (const selector of selectors) {
      const match = selector.match(FACE_REFERENCE_SELECTOR);
      if (!match || !isPositiveInteger(match[1]) || BigInt(match[1]) > BigInt(Number.MAX_SAFE_INTEGER)) {
        return null;
      }
      ids.push(match[1]);
    }
    if (new Set(ids).size !== ids.length) {
      return null;
    }

    return { command, meta: {
      description: 'Read private benchmark-readiness evidence for 1–5 explicit face IDs; no identity decisions or writes',
      timeout: 60_000, maxBuffer: FACE_REFERENCE_MAX_BUFFER,
    } };
  }
  if (tokens.length < 6 || FACE_PREVIEW_PREFIX.some((prefix, index) => tokens[index] !== prefix)) {
    return null;
  }

  if (tokens.at(-1) !== '--json') {
    return null;
  }

  let treeIndex = tokens.length - 2;
  const hasDryRun = tokens[treeIndex] === '--dry-run';
  if (hasDryRun) {
    treeIndex -= 1;
  }

  const treeMatch = tokens[treeIndex]?.match(/^--tree-id=([1-9]\d*)$/);
  if (!treeMatch || !isPositiveInteger(treeMatch[1])) {
    return null;
  }

  const selectorTokens = tokens.slice(FACE_PREVIEW_PREFIX.length, treeIndex);
  if (selectorTokens.length < 1 || selectorTokens.length > MAX_FACE_PREVIEW_SELECTORS) {
    return null;
  }

  const selectorIds: string[] = [];
  for (const token of selectorTokens) {
    const selectorMatch = token.match(FACE_PREVIEW_SELECTOR);
    if (!selectorMatch || !isPositiveInteger(selectorMatch[1])) {
      return null;
    }

    selectorIds.push(selectorMatch[1]);
  }

  if (new Set(selectorIds).size !== selectorIds.length) {
    return null;
  }

  return {
    command: tokens.join(' '),
    meta: {
      description: 'Bounded anonymous face preview for 1–5 explicit media/review IDs; no identity decisions or database writes',
      timeout: 120_000,
    },
  };
}

export function resolveAllowedCommand(
  command: string,
  commands: AllowedCommands = ALLOWED_COMMANDS,
  dynamicCommandResolver: DynamicCommandResolver = resolveDynamicAllowedCommand,
): DynamicAllowedCommand | null {
  const exactMeta = Object.hasOwn(commands, command) ? commands[command] : undefined;
  if (exactMeta) {
    return { command, meta: exactMeta };
  }

  const dynamicMatch = dynamicCommandResolver(command, { boundedExecutor: true });

  return dynamicMatch?.command === command ? dynamicMatch : null;
}

export const plosArtisanInput = z.object({
  command: z.string().describe(
    'Artisan command to run. Use "list" to see allowed commands. Examples: "ops:validate-sql", "files:registry --status"'
  ),
  on_prod: z.coerce.boolean().optional().default(true)
    .describe('Run on the configured remote PLOS instance. Set false for dev-only commands like ops:validate-sql.'),
});

export type PlosArtisanInput = z.infer<typeof plosArtisanInput>;

export function selectAllowlistPolicy(input: {
  loadedRevision?: string;
  loadedCommands?: AllowedCommands;
  loadedCompactCommands?: readonly string[];
  loadedDynamicCommandResolver?: DynamicCommandResolver;
  freshness: AllowlistFreshness;
  candidate?: AllowlistModuleCandidate | null;
}): ResolvedAllowlistPolicy {
  const loadedRevision = input.loadedRevision ?? ALLOWLIST_REVISION;
  const loadedCommands = input.loadedCommands ?? ALLOWED_COMMANDS;
  const loadedCompactCommands = input.loadedCompactCommands ?? COMPACT_SCORECARD_COMMANDS;
  const loadedDynamicCommandResolver = input.loadedDynamicCommandResolver ?? resolveDynamicAllowedCommand;
  const candidate = input.candidate;
  const candidateRevision = typeof candidate?.ALLOWLIST_REVISION === 'string'
    ? candidate.ALLOWLIST_REVISION
    : null;

  if (
    input.freshness.status === 'stale'
    && input.freshness.dist_revision !== null
    && candidateRevision === input.freshness.dist_revision
    && candidateRevision !== loadedRevision
    && isAllowedCommands(candidate?.ALLOWED_COMMANDS)
  ) {
    return {
      source: 'dist',
      revision: candidateRevision,
      commands: candidate.ALLOWED_COMMANDS,
      compactCommands: isStringArray(candidate.COMPACT_SCORECARD_COMMANDS)
        ? candidate.COMPACT_SCORECARD_COMMANDS
        : loadedCompactCommands,
      dynamicCommandResolver: typeof candidate.resolveDynamicAllowedCommand === 'function'
        ? candidate.resolveDynamicAllowedCommand as DynamicCommandResolver
        : loadedDynamicCommandResolver,
      freshness: input.freshness,
    };
  }

  return {
    source: 'loaded',
    revision: loadedRevision,
    commands: loadedCommands,
    compactCommands: loadedCompactCommands,
    dynamicCommandResolver: loadedDynamicCommandResolver,
    freshness: input.freshness,
  };
}

function isAllowedCommands(value: unknown): value is AllowedCommands {
  if (value === null || typeof value !== 'object') {
    return false;
  }

  return Object.values(value as Record<string, unknown>).every((meta) => {
    if (meta === null || typeof meta !== 'object') {
      return false;
    }

    const record = meta as Record<string, unknown>;

    return typeof record.description === 'string'
      && typeof record.timeout === 'number'
      && Number.isFinite(record.timeout)
      && (record.maxBuffer === undefined || (typeof record.maxBuffer === 'number'
        && Number.isSafeInteger(record.maxBuffer) && record.maxBuffer > 0));
  });
}

function isStringArray(value: unknown): value is readonly string[] {
  return Array.isArray(value) && value.every(item => typeof item === 'string');
}

async function resolveAllowlistPolicy(): Promise<ResolvedAllowlistPolicy> {
  const moduleDir = dirname(fileURLToPath(import.meta.url));
  const sourceText = readTextIfPresent(resolve(moduleDir, '../../src/tools/plos-artisan.ts'));
  const distPath = resolve(moduleDir, 'plos-artisan.js');
  const distText = readTextIfPresent(distPath);
  const freshness = allowlistFreshness({ sourceText, distText });

  if (freshness.status !== 'stale' || freshness.dist_revision === null) {
    return selectAllowlistPolicy({ freshness });
  }

  try {
    const distUrl = pathToFileURL(distPath);
    distUrl.searchParams.set('allowlist_revision', freshness.dist_revision);
    distUrl.searchParams.set(
      'allowlist_fingerprint',
      distText === null ? 'missing' : allowlistContentFingerprint(distText),
    );

    const candidate = await import(distUrl.href) as AllowlistModuleCandidate;

    return selectAllowlistPolicy({ freshness, candidate });
  } catch (error) {
    logger.warn('PLOS artisan allowlist refresh failed', {
      loaded_revision: freshness.loaded_revision,
      dist_revision: freshness.dist_revision,
      error: error instanceof Error ? error.message : String(error),
    });

    return selectAllowlistPolicy({ freshness });
  }
}

export async function plosArtisan(input: PlosArtisanInput, context?: ToolContext,
  executor: typeof execCommand = execCommand): Promise<string> {
  const { command, on_prod } = input;
  const policy = await resolveAllowlistPolicy();

  // Special: list available commands
  if (command === 'list') {
    const lines = [
      'Available artisan commands:\n',
      `Allowlist revision: ${policy.revision}`,
      `Allowlist source: ${policy.source}`,
      allowlistFreshnessLine(policy.freshness),
      `Allowlist entries: ${Object.keys(policy.commands).length}\n`,
      'Compact scorecard commands (exact forms only):',
      '\nBounded dynamic command form (canonical order only):',
      '  php artisan files:enrich --type=faces --face-preview [1–5 unique --media-id=<positive-id> or --face-review-id=<positive-id>] --tree-id=<positive-id> [--dry-run] --json\n',
    ];

    for (const cmd of ['os-tools:review python3 --json', 'os-tools:review prlimit --json']) {
      if (resolveAllowedCommand(cmd, policy.commands, policy.dynamicCommandResolver)) {
        lines.push(`  php artisan ${cmd} (bounded read-only evidence)`);
      }
    }

    for (const cmd of policy.compactCommands) {
      lines.push(`  php artisan ${cmd}`);
    }

    lines.push(
      '\nNo reordered flags, wider windows, trace ids, detail output, archive/consolidate, or writeback variants are allowlisted.\n',
      '  php artisan files:face-reference-readiness [1–5 unique --face-id=<positive-id>] --json (bounded private read-only readiness report)\n',
      'All allowed commands:\n',
    );

    for (const [cmd, meta] of Object.entries(policy.commands)) {
      lines.push(`  php artisan ${cmd}`);
      lines.push(`    ${meta.description}\n`);
    }
    return lines.join('\n');
  }

  // Validate against whitelist
  const match = resolveAllowedCommand(command, policy.commands, policy.dynamicCommandResolver);
  if (!match) {
    const suggestions = Object.keys(policy.commands)
      .filter(k => k.includes(command.split(' ')[0].split(':')[0]))
      .slice(0, 5);
    return `Blocked: "${command}" is not in the allowed command list.\n`
      + `Allowlist revision: ${policy.revision}\n`
      + `Allowlist source: ${policy.source}\n`
      + `${allowlistFreshnessLine(policy.freshness)}\n`
      + `Allowlist entries: ${Object.keys(policy.commands).length}\n`
      + (suggestions.length ? `Did you mean: ${suggestions.join(', ')}?\n` : '')
      + `Use command "list" to see all available commands.`;
  }

  const { command: allowedCmd, meta } = match;
  const isFaceReferenceReadiness = allowedCmd.startsWith(`${FACE_REFERENCE_READINESS_COMMAND} `);
  const remotePath = CONFIG.paths.remoteRoot;

  let shellCmd: string;
  if (on_prod) {
    shellCmd = `${sshCmd()} "cd -- ${shellQuote(remotePath)} && php artisan ${allowedCmd}"`;
  } else {
    shellCmd = `cd -- ${shellQuote(CONFIG.paths.projectRoot)} && php artisan ${allowedCmd}`;
  }

  logger.info('Artisan command', { command: allowedCmd, on_prod });

  try {
    const { stdout, stderr } = await executor(shellCmd, { timeout: meta.timeout, signal: context?.signal,
      ...(meta.maxBuffer === undefined ? {} : { maxBuffer: meta.maxBuffer }) });
    if (isFaceReferenceReadiness) {
      if (meta.maxBuffer !== FACE_REFERENCE_MAX_BUFFER
        || Buffer.byteLength(stdout, 'utf8') + Buffer.byteLength(stderr, 'utf8') > FACE_REFERENCE_MAX_BUFFER) {
        return faceReferenceReadinessTransportHold('review_transport_output_limit');
      }
      if (stderr !== '' || !validFaceReferenceReadinessReport(allowedCmd, stdout)) {
        return faceReferenceReadinessTransportHold('review_transport_unavailable');
      }
      const env = on_prod ? 'REMOTE' : 'DEV';
      return `[${env}] php artisan ${allowedCmd}\n\n${stdout.trim()}`;
    }
    if (meta.maxBuffer !== undefined && Buffer.byteLength(stdout, 'utf8') + Buffer.byteLength(stderr, 'utf8') > meta.maxBuffer) {
      return boundedReviewTransportHold('review_transport_output_limit', { stdout, stderr });
    }
    const output = (stdout + (stderr ? `\n[stderr] ${stderr}` : '')).trim();
    const env = on_prod ? 'REMOTE' : 'DEV';
    return `[${env}] php artisan ${allowedCmd}\n\n${output}`;
  } catch (err: unknown) {
    const error = err as { stdout?: string; stderr?: string; message?: string; code?: number | string };
    if (isFaceReferenceReadiness) {
      return faceReferenceReadinessTransportHold(error.code === 'ERR_CHILD_PROCESS_STDIO_MAXBUFFER'
        ? 'review_transport_output_limit' : 'review_transport_unavailable');
    }
    if (meta.maxBuffer !== undefined) {
      return boundedReviewTransportHold(error.code === 'ERR_CHILD_PROCESS_STDIO_MAXBUFFER'
        ? 'review_transport_output_limit' : 'review_transport_unavailable',
        { stdout: error.stdout ?? '', stderr: error.stderr ?? '' });
    }
    const output = error.stdout?.trim() || '';
    const errMsg = error.stderr?.trim() || error.message || 'Unknown error';
    return `[ERROR] php artisan ${allowedCmd}\nExit code: ${error.code}\n\n${output}\n\n${errMsg}`;
  }
}

function validFaceReferenceReadinessReport(command: string, stdout: string): boolean {
  const tokens = command.split(' ');
  const expectedIds = tokens.slice(1, -1).map(token => token.match(FACE_REFERENCE_SELECTOR)?.[1] ?? '');
  try {
    const report = JSON.parse(stdout) as {
      read_only?: unknown;
      identity_assignments?: unknown;
      items?: Array<{ face_id?: unknown; holds?: unknown; benchmark_holds?: unknown; benchmark_eligible?: unknown }>;
    };
    return report.read_only === true && report.identity_assignments === false
      && Array.isArray(report.items) && report.items.length === expectedIds.length
      && report.items.every((item, index) => item.face_id === Number(expectedIds[index])
        && Array.isArray(item.holds) && item.holds.every(hold => typeof hold === 'string')
        && Array.isArray(item.benchmark_holds) && item.benchmark_holds.every(hold => typeof hold === 'string')
        && typeof item.benchmark_eligible === 'boolean'
        && (!item.benchmark_eligible || (item.holds.length === 0 && item.benchmark_holds.length === 0)));
  } catch {
    return false;
  }
}

function faceReferenceReadinessTransportHold(
  reason: 'review_transport_output_limit' | 'review_transport_unavailable',
): string {
  return JSON.stringify({ schema: 'plos.face-reference-readiness.transport.v1', success: false,
    status: 'incomplete', complete: false, read_only: true, identity_assignments: false,
    items: [], reason_code: reason, raw_failed_report_returned: false });
}


function boundedReviewTransportHold(
  reason: 'review_transport_output_limit' | 'review_transport_unavailable',
  observed: { stdout: string; stderr: string },
): string {
  return JSON.stringify({ schema: 'plos.os-tools.review-transport-held.v1', success: false,
    reason_code: reason, review_binding_complete: false, evidence_binding: { complete: false },
    execution_authorized: false, raw_failed_report_returned: false, raw_failed_report_durably_retained: false,
    observed_output: { diagnostic_only: true, complete: false,
      stdout_utf8_bytes: Buffer.byteLength(observed.stdout, 'utf8'),
      stderr_utf8_bytes: Buffer.byteLength(observed.stderr, 'utf8'),
      stdout_diagnostic_sha256: createHash('sha256').update(observed.stdout, 'utf8').digest('hex'),
      stderr_diagnostic_sha256: createHash('sha256').update(observed.stderr, 'utf8').digest('hex'),
    },
  });
}

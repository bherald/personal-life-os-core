import assert from 'node:assert/strict';
import test from 'node:test';
import { execCommand, type ExecCommandError } from '../util/exec.js';

import {
  ALLOWED_COMMANDS,
  ALLOWLIST_REVISION,
  COMPACT_SCORECARD_COMMANDS,
  allowlistContentFingerprint,
  allowlistFreshness,
  extractAllowlistRevision,
  plosArtisan,
  resolveAllowedCommand,
  resolveDynamicAllowedCommand,
  selectAllowlistPolicy,
} from './plos-artisan.js';

test('allowlist freshness detects loaded source and dist revision drift', () => {
  const current = `export const ALLOWLIST_REVISION = '${ALLOWLIST_REVISION}';`;
  const stale = "export const ALLOWLIST_REVISION = '2026-05-01-stale-test';";

  assert.equal(extractAllowlistRevision(current), ALLOWLIST_REVISION);
  assert.equal(extractAllowlistRevision(stale), '2026-05-01-stale-test');

  assert.deepEqual(allowlistFreshness({ sourceText: current, distText: current }), {
    status: 'current',
    loaded_revision: ALLOWLIST_REVISION,
    source_revision: ALLOWLIST_REVISION,
    dist_revision: ALLOWLIST_REVISION,
    mismatched_revision_count: 0,
  });

  assert.deepEqual(allowlistFreshness({ sourceText: stale, distText: current }), {
    status: 'stale',
    loaded_revision: ALLOWLIST_REVISION,
    source_revision: '2026-05-01-stale-test',
    dist_revision: ALLOWLIST_REVISION,
    mismatched_revision_count: 1,
  });

  assert.equal(allowlistFreshness({ sourceText: null, distText: null }).status, 'unknown');
});

test('allowlist content fingerprint changes when commands change without revision bump', () => {
  const first = `export const ALLOWLIST_REVISION = '${ALLOWLIST_REVISION}';
export const ALLOWED_COMMANDS = { 'ops:first --json': { description: 'First', timeout: 1_000 } };`;
  const second = `export const ALLOWLIST_REVISION = '${ALLOWLIST_REVISION}';
export const ALLOWED_COMMANDS = { 'ops:second --json': { description: 'Second', timeout: 1_000 } };`;

  assert.notEqual(allowlistContentFingerprint(first), allowlistContentFingerprint(second));
});

test('face coverage admits and lists only the exact read-only aggregate status form', async () => {
  const command = 'files:face-coverage --status --json';
  assert.deepEqual(resolveAllowedCommand(command), { command, meta: ALLOWED_COMMANDS[command] });
  const listing = await plosArtisan({ command: 'list', on_prod: false });
  assert.match(listing, /php artisan files:face-coverage --status --json\n/);
  assert.match(listing, /Read-only aggregate anonymous photo coverage status without detection or identity decisions/);

  for (const rejected of [
    'files:face-coverage',
    'files:face-coverage --json',
    'files:face-coverage --status',
    'files:face-coverage --json --status',
    'files:face-coverage --slice --scheduler-run=1 --json',
    `${command} --slice`,
    `${command} --scheduler-run=1`,
    `${command} --json`,
    `${command} --status`,
    `${command} --compact`,
    `${command} --no-interaction`,
    `${command}; echo bad`,
    `${command} && echo bad`,
    `${command} | cat`,
    `${command} $(echo bad)`,
    `${command}\necho bad`,
  ]) {
    assert.equal(resolveAllowedCommand(rejected), null, `${rejected} must not reach execution`);
    assert.match(await plosArtisan({ command: rejected, on_prod: false }), /^Blocked:/);
  }
});

test('image audit admits only exact aggregate status and blocks workers or conflicting options', async () => {
  const command = 'files:image-audit --status --json';
  assert.deepEqual(resolveAllowedCommand(command), { command, meta: ALLOWED_COMMANDS[command] });
  assert.equal(ALLOWED_COMMANDS[command].timeout, 30_000);
  const listing = await plosArtisan({ command: 'list', on_prod: false });
  assert.match(listing, /php artisan files:image-audit --status --json\n/);
  assert.match(listing, /Read-only aggregate image audit status without starting a worker or changing sources or identities/);

  for (const rejected of [
    'files:image-audit',
    'files:image-audit --json',
    'files:image-audit --status',
    'files:image-audit --json --status',
    'files:image-audit --slice --json',
    `${command} --slice`,
    `${command} --status=0`,
    `${command} --status=false`,
    `${command} --status`,
    `${command} --json`,
    `${command} --no-interaction`,
    `${command} --force`,
    `${command}; echo bad`,
    `${command} && echo bad`,
    `${command} | cat`,
    `${command} $(echo bad)`,
    `${command}\necho bad`,
  ]) {
    assert.equal(resolveAllowedCommand(rejected), null, `${rejected} must not reach execution`);
    assert.match(await plosArtisan({ command: rejected, on_prod: false }), /^Blocked:/);
  }
});

test('face preview resolver admits only canonical bounded explicit selectors', () => {
  const one = 'files:enrich --type=faces --face-preview --media-id=16576 --tree-id=4 --dry-run --json';
  const resolved = resolveDynamicAllowedCommand(one);

  assert.deepEqual(resolved, {
    command: one,
    meta: {
      description: 'Bounded anonymous face preview for 1–5 explicit media/review IDs; no identity decisions or database writes',
      timeout: 120_000,
    },
  });
  assert.equal(ALLOWED_COMMANDS[one], undefined, 'dynamic selectors stay outside the exact static allowlist');
  assert.equal(resolveAllowedCommand(one)?.command, one, 'the tool command matcher admits the resolved dynamic command');

  const five = 'files:enrich --type=faces --face-preview --media-id=16576 --face-review-id=2201 --media-id=2202 --face-review-id=2203 --media-id=2204 --tree-id=4 --json';
  assert.equal(resolveDynamicAllowedCommand(five)?.command, five);

  const withoutDryRun = 'files:enrich --type=faces --face-preview --face-review-id=2201 --tree-id=4 --json';
  assert.equal(resolveDynamicAllowedCommand(withoutDryRun)?.command, withoutDryRun);

  for (const command of [
    'files:enrich --type=faces --face-preview --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=1 --media-id=2 --media-id=3 --media-id=4 --media-id=5 --media-id=6 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=1 --face-review-id=1 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=0 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=01 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=9223372036854775808 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=1 --tree-id=0 --json',
    'files:enrich --type=faces --face-preview --media-id=1 --tree-id=4 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=1 --tree-id=4 --dry-run --dry-run --json',
    'files:enrich --type=faces --face-preview --media-id=1 --tree-id=4 --json --json',
    'files:enrich --type=faces --face-preview --media-id=1 --dry-run --tree-id=4 --json',
    'files:enrich --face-preview --type=faces --media-id=1 --tree-id=4 --json',
    'files:enrich --type=all --face-preview --media-id=1 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=1 --tree-id=4 --status --json',
    'files:enrich --type=faces --face-preview --media-id=1 --tree-id=4 --worker-id=x --json',
    'files:enrich --type=faces --face-preview --media-id=1;echo bad --tree-id=4 --json',
    'files:enrich  --type=faces --face-preview --media-id=1 --tree-id=4 --json',
    'files:enrich --type=faces --face-preview --media-id=1 --tree-id=4 --json ',
  ]) {
    assert.equal(resolveDynamicAllowedCommand(command), null, `${command} must be rejected`);
    assert.equal(resolveAllowedCommand(command), null, `${command} must not reach command execution`);
  }
});

test('face reference readiness resolver admits only bounded canonical IDs for the current executor', async () => {
  const one = 'files:face-reference-readiness --face-id=17 --json';
  assert.equal(Object.hasOwn(ALLOWED_COMMANDS, one), false);
  assert.equal(resolveDynamicAllowedCommand(one), null, 'old loaded executors do not inherit the new form');
  assert.equal(resolveDynamicAllowedCommand(one, { boundedExecutor: false }), null);
  assert.deepEqual(resolveAllowedCommand(one), {
    command: one,
    meta: {
      description: 'Read private benchmark-readiness evidence for 1–5 explicit face IDs; no identity decisions or writes',
      timeout: 60_000,
      maxBuffer: 262144,
    },
  });
  const five = 'files:face-reference-readiness --face-id=17 --face-id=29 --face-id=31 --face-id=41 --face-id=53 --json';
  assert.equal(resolveAllowedCommand(five)?.command, five);

  for (const rejected of [
    'files:face-reference-readiness --json',
    `${one} --face-id=29`,
    `${one} --face-id=17`,
    'files:face-reference-readiness --face-id=0 --json',
    'files:face-reference-readiness --face-id=01 --json',
    'files:face-reference-readiness --face-id=9007199254740992 --json',
    'files:face-reference-readiness --face-id=9223372036854775808 --json',
    `${five} --face-id=59`,
    'files:face-reference-readiness --face-id=17',
    'files:face-reference-readiness --json --face-id=17',
    `${one} --compact`, `${one} --execute`, `${one}; echo unsafe`,
    `${one} && echo unsafe`, `${one} | cat`, `${one} $(echo unsafe)`, `${one}\\necho unsafe`,
    'files:face-reference-readiness  --face-id=17 --json',
    `${one} `,
  ]) {
    assert.equal(resolveAllowedCommand(rejected), null, `${rejected} must be rejected`);
    let calls = 0;
    assert.match(await plosArtisan({ command: rejected, on_prod: false }, undefined, async () => {
      calls++; throw new Error('executor must not run');
    }), /^Blocked:/);
    assert.equal(calls, 0);
  }

  const listing = await plosArtisan({ command: 'list', on_prod: false }, undefined, async () => {
    throw new Error('list must not execute a command');
  });
  assert.match(listing, /face-reference-readiness \[1–5 unique --face-id=<positive-id>\] --json/);
});

test('face reference readiness passes exact report only within timeout and aggregate output bounds', async () => {
  const command = 'files:face-reference-readiness --face-id=17 --face-id=29 --json';
  const report = { read_only: true, identity_assignments: false, items: [
    { face_id: 17, holds: ['held-private'], benchmark_holds: ['checkpoint_unknown'], benchmark_eligible: false },
    { face_id: 29, holds: [], benchmark_holds: [], benchmark_eligible: true },
  ] };
  const stdout = JSON.stringify(report);
  let calls = 0;
  const response = await plosArtisan({ command, on_prod: false }, undefined, async (assembled, options) => {
    calls++;
    assert.match(assembled, /php artisan files:face-reference-readiness --face-id=17 --face-id=29 --json$/);
    assert.equal(options?.timeout, 60_000);
    assert.equal(options?.maxBuffer, 262144);
    return { stdout, stderr: '' };
  });
  assert.equal(calls, 1);
  assert.equal(response.split('\n\n').slice(1).join('\n\n'), stdout);

  const overflow = await plosArtisan({ command: 'files:face-reference-readiness --face-id=17 --json', on_prod: false }, undefined,
    async (_assembled, options) => {
      assert.equal(options?.timeout, 60_000);
      assert.equal(options?.maxBuffer, 262144);
      return { stdout: 'private report secret', stderr: 'x'.repeat(262144) };
    });
  assert.deepEqual(JSON.parse(overflow), {
    schema: 'plos.face-reference-readiness.transport.v1', success: false,
    status: 'incomplete', complete: false, read_only: true, identity_assignments: false,
    items: [], reason_code: 'review_transport_output_limit', raw_failed_report_returned: false,
  });
  assert.doesNotMatch(overflow, /private report secret|sha256|x{32}/);
});

test('face reference readiness transport errors return a fixed incomplete holder without diagnostics', async () => {
  const response = await plosArtisan({
    command: 'files:face-reference-readiness --face-id=17 --json', on_prod: false,
  }, undefined, async () => { throw Object.assign(new Error('private transport diagnostic'), {
    code: 'ETIMEDOUT', stdout: '{"person_name":"private partial"}', stderr: 'private transport diagnostic',
  }); });
  const holder = JSON.parse(response);
  assert.equal(holder.status, 'incomplete');
  assert.equal(holder.complete, false);
  assert.equal(holder.reason_code, 'review_transport_unavailable');
  assert.equal(holder.raw_failed_report_returned, false);
  assert.doesNotMatch(response, /private partial|private transport diagnostic|sha256/);
});

test('face reference readiness rejects malformed, unsafe-eligible, or reordered item reports', async () => {
  const command = 'files:face-reference-readiness --face-id=17 --face-id=29 --json';
  const valid = { read_only: true, identity_assignments: false, items: [
    { face_id: 17, holds: [], benchmark_holds: [], benchmark_eligible: true },
    { face_id: 29, holds: [], benchmark_holds: [], benchmark_eligible: true },
  ] };
  const invalidReports = [
    { ...valid, items: [{ face_id: 17, benchmark_holds: [], benchmark_eligible: false }, valid.items[1]] },
    { ...valid, items: [{ face_id: 17, holds: [], benchmark_eligible: false }, valid.items[1]] },
    { ...valid, items: [{ face_id: 17, holds: [17], benchmark_holds: [], benchmark_eligible: false }, valid.items[1]] },
    { ...valid, items: [{ face_id: 17, holds: ['current_reference_held'], benchmark_holds: [], benchmark_eligible: true }, valid.items[1]] },
    { ...valid, items: [...valid.items].reverse() },
  ];

  for (const report of invalidReports) {
    const response = await plosArtisan({ command, on_prod: false }, undefined, async () => ({
      stdout: JSON.stringify(report), stderr: '',
    }));
    assert.deepEqual(JSON.parse(response), {
      schema: 'plos.face-reference-readiness.transport.v1', success: false,
      status: 'incomplete', complete: false, read_only: true, identity_assignments: false,
      items: [], reason_code: 'review_transport_unavailable', raw_failed_report_returned: false,
    });
  }
});

test('stale policy freshness candidate can supply its bounded dynamic resolver', () => {
  const distResolver = (command: string) => command === 'dist-preview'
    ? { command, meta: { description: 'dist preview', timeout: 1_000 } }
    : null;
  const policy = selectAllowlistPolicy({
    loadedRevision: 'loaded-old',
    loadedCommands: { 'ops:loaded-only --json': { description: 'Loaded command', timeout: 1_000 } },
    loadedDynamicCommandResolver: resolveDynamicAllowedCommand,
    freshness: {
      status: 'stale',
      loaded_revision: 'loaded-old',
      source_revision: 'dist-new',
      dist_revision: 'dist-new',
      mismatched_revision_count: 2,
    },
    candidate: {
      ALLOWLIST_REVISION: 'dist-new',
      ALLOWED_COMMANDS: { 'ops:dist-only --json': { description: 'Dist command', timeout: 2_000 } },
      resolveDynamicAllowedCommand: distResolver,
    },
  });

  assert.equal(policy.source, 'dist');
  assert.equal(policy.dynamicCommandResolver, distResolver);
  assert.equal(policy.dynamicCommandResolver('dist-preview')?.meta.description, 'dist preview');
});


test('allowlist policy uses newer compiled dist snapshot when loaded policy is stale', () => {
  const loadedCommands = {
    'ops:loaded-only --json': { description: 'Loaded command', timeout: 1_000 },
  };
  const distCommands = {
    ...loadedCommands,
    'ops:new-dist-only --json --compact': { description: 'Fresh dist command', timeout: 2_000 },
  };

  const policy = selectAllowlistPolicy({
    loadedRevision: '2026-05-27-trace-tail-compact',
    loadedCommands,
    loadedCompactCommands: ['ops:loaded-only --json'],
    freshness: {
      status: 'stale',
      loaded_revision: '2026-05-27-trace-tail-compact',
      source_revision: '2026-06-05-compact-rts-observe',
      dist_revision: '2026-06-05-compact-rts-observe',
      mismatched_revision_count: 2,
    },
    candidate: {
      ALLOWLIST_REVISION: '2026-06-05-compact-rts-observe',
      ALLOWED_COMMANDS: distCommands,
      COMPACT_SCORECARD_COMMANDS: ['ops:new-dist-only --json --compact'],
    },
  });

  assert.equal(policy.source, 'dist');
  assert.equal(policy.revision, '2026-06-05-compact-rts-observe');
  assert.equal(policy.commands['ops:new-dist-only --json --compact']?.timeout, 2_000);
  assert.deepEqual(policy.compactCommands, ['ops:new-dist-only --json --compact']);
});

test('allowlist policy fails closed when stale dist snapshot is invalid', () => {
  const loadedCommands = {
    'ops:loaded-only --json': { description: 'Loaded command', timeout: 1_000 },
  };

  const policy = selectAllowlistPolicy({
    loadedRevision: '2026-05-27-trace-tail-compact',
    loadedCommands,
    loadedCompactCommands: ['ops:loaded-only --json'],
    freshness: {
      status: 'stale',
      loaded_revision: '2026-05-27-trace-tail-compact',
      source_revision: '2026-06-05-compact-rts-observe',
      dist_revision: '2026-06-05-compact-rts-observe',
      mismatched_revision_count: 2,
    },
    candidate: {
      ALLOWLIST_REVISION: '2026-06-05-compact-rts-observe',
      ALLOWED_COMMANDS: {
        'ops:new-dist-only --json --compact': { description: 'Fresh dist command', timeout: 'bad' },
      },
      COMPACT_SCORECARD_COMMANDS: ['ops:new-dist-only --json --compact'],
    },
  });

  assert.equal(policy.source, 'loaded');
  assert.equal(policy.revision, '2026-05-27-trace-tail-compact');
  assert.deepEqual(Object.keys(policy.commands), ['ops:loaded-only --json']);
  assert.deepEqual(policy.compactCommands, ['ops:loaded-only --json']);
});

test('allowlist policy does not authorize from source-only freshness', () => {
  const loadedCommands = {
    'ops:loaded-only --json': { description: 'Loaded command', timeout: 1_000 },
  };

  const policy = selectAllowlistPolicy({
    loadedRevision: '2026-05-27-trace-tail-compact',
    loadedCommands,
    loadedCompactCommands: ['ops:loaded-only --json'],
    freshness: {
      status: 'stale',
      loaded_revision: '2026-05-27-trace-tail-compact',
      source_revision: '2026-06-05-compact-rts-observe',
      dist_revision: null,
      mismatched_revision_count: 1,
    },
    candidate: {
      ALLOWLIST_REVISION: '2026-06-05-compact-rts-observe',
      ALLOWED_COMMANDS: {
        'ops:source-only --json --compact': { description: 'Source-only command', timeout: 2_000 },
      },
    },
  });

  assert.equal(policy.source, 'loaded');
  assert.equal(policy.revision, '2026-05-27-trace-tail-compact');
  assert.equal(policy.commands['ops:source-only --json --compact'], undefined);
});

test('read-only planning evidence commands stay allowlisted', async () => {
  for (const command of [
    'ops:operator-evidence --compact',
    'ops:operator-evidence --json --compact',
    'ops:review-backlog-report --json',
    'ops:review-backlog-report --markdown',
    'ops:review-backlog-report --dry-run',
    'ops:review-backlog-report --dry-run --json --compact',
    'ops:review-backlog-report --compact',
    'ops:review-backlog-report --json --compact',
    'ops:review-backlog-report --markdown --compact',
    'ops:review-backlog-report --next-target',
    'ops:review-backlog-report --json --next-target',
    'ops:review-backlog-report --next-target --focus=typed-remediation --json',
    'ops:review-backlog-report --next-target --focus=materializable-remediation --json',
    'ops:review-backlog-report --next-target --focus=source-backed-packet --json',
    'ops:review-backlog-report --next-target --focus=aged-review --json',
    'ops:review-backlog-report --json --compact --next-target --focus=face-photo-repair-packet',
    'ops:offline-status --json',
    'ops:agent-doctor --compact',
    'ops:agent-doctor --json --compact',
    'ops:agent-doctor --json --compact --since=24',
    'ops:offline-smoke --json --compact',
    'plos:agent-doctor --compact',
    'plos:agent-doctor --json --compact',
    'ops:agent-doctor-snapshot --dry-run --json',
    'ops:agent-doctor-history --json --compact --days=7',
    'plos:agent-trace-tail --limit=20 --since=24 --json --compact',
    'ops:mcp-health --compact',
    'ops:mcp-health --json --compact',
    'ops:mcp-tool-contract-audit --json --compact --fail-on-violations',
    'llm:sync-providers --json --compact',
    'llm:sync-providers --json --compact --no-live',
    'codex:exec-smoke --json',
    'codex:offline-capabilities --json',
    'codex:offline-capabilities --json --probe-sidecars',
    'codex:offline-pipeline-tools --json',
    'codex:offline-pipeline-tools --json --compact',
    'codex:offline-sidecar --json --dry-run',
    'codex:offline-improvement --json --example',
    'codex:offline-prompt-candidate-eval --json --compact --example --fail-on-ineligible',
    'content:ocr-candidate-eval --example --json --compact --fail-on-ineligible',
    'content:extraction-provenance-audit --json --compact',
    'codex:offline-improvement-review --json --compact',
    'codex:offline-improvement-review --json --compact --stage-review',
    'audio:tts --json --probe',
    'rag:rerank --json --example --no-embeddings',
    'rag:rerank-eval --json --compact --fail-on-regression',
    'ops:local-runtime-scorecard --json --compact',
    'ops:scheduled-output-audit --json --compact --fail-on-violations',
    'ops:scheduled-output-audit --json --compact',
    'ollama:drift-check --json --compact --no-fail',
    'ollama:eval-scorecard --json --compact',
    'ops:capacity-report --json',
    'ops:capacity-checkpoint --json',
    'ops:capacity-checkpoint --json --compact',
    'ops:capacity-checkpoint --markdown',
    'ops:capacity-checkpoint --dry-run --json',
    'ops:capacity-utilization-manager --json --compact',
    'ops:capacity-utilization-manager --dry-run --json --compact',
    'ops:runtime-diagnostics --window=60m --focus=all --json',
    'ops:runtime-diagnostics --json --compact',
    'ops:runtime-diagnostics --window=60m --focus=all --json --compact',
    'ops:runtime-diagnostics --window=24h --json --compact',
    'ops:runtime-diagnostics --window=24h --focus=all --json --compact',
    'ops:queue-fit-audit --json --compact',
    'ops:runtime-policy-leak-audit --json --compact',
    'ops:runtime-policy-leak-audit --json --findings-limit=40',
    'ops:runtime-policy-leak-audit --json --compact --findings-limit=40',
    'ops:face-telemetry-report --json',
    'ops:face-telemetry-report --markdown --hours=168',
    'ops:face-telemetry-report --compact',
    'ops:face-telemetry-report --json --compact',
    'ops:face-telemetry-report --markdown --compact',
    'ops:face-mixed-cluster-report --limit=5 --sample-faces=3 --json',
    'ops:face-mixed-cluster-report --limit=10 --sample-faces=0 --compact --json',
    'ops:face-integrity-audit --json --limit=20',
    'ops:face-integrity-audit --json --compact --limit=20',
    'ops:face-named-only-slice --limit=10 --json',
    'ops:face-named-only-slice --limit=10 --cluster-scope=mixed --sort=oldest --compact --json',
    'ops:audit-privacy-routing --json --compact',
    'ops:dba-telemetry-report --compact',
    'ops:dba-telemetry-report --json --compact',
    'ops:dba-telemetry-report --markdown --compact',
    'ops:dba-telemetry-report --markdown --dry-run',
    'ops:arc-retention --json --compact',
    'ops:audit-privacy-routing --json',
    'genealogy:reject-codes --json',
    'genealogy:reject-codes --json --days=30',
    'genealogy:reject-codes --compact',
    'genealogy:reject-codes --json --compact',
    'genealogy:review-feedback --days=30 --json',
    'genealogy:review-feedback --compact',
    'genealogy:review-feedback --json --compact',
    'genealogy:packet-reason-codes --days=30 --json',
    'genealogy:packet-reason-codes --compact',
    'genealogy:packet-reason-codes --json --compact',
    'genealogy:evidence-sprint-report --json',
    'genealogy:evidence-sprint-report --json --compact',
    'genealogy:evidence-sprint-report --markdown',
    'genealogy:evidence-sprint-report --markdown --compact',
    'genealogy:evidence-asset-candidates --compact',
    'genealogy:evidence-asset-candidates --json --compact',
    'genealogy:evidence-asset-candidates --markdown --compact',
    'genealogy:evidence-asset-candidates --dry-run --json --compact',
    'genealogy:evidence-asset-capture-plan --json --compact',
    'genealogy:evidence-asset-capture-plan --dry-run --json --compact',
    'genealogy:evidence-asset-capture-review --json --compact',
    'genealogy:evidence-asset-capture-executor --json --compact',
    'genealogy:media-intake-report --json --compact',
    'genealogy:media-intake-report --markdown --compact',
    'genealogy:media-intake-report --dry-run --json --compact',
    'genealogy:media-intake-run --dry-run --json --compact',
    'genealogy:media-intake-run --dry-run --json --compact --stage --transcribe-dry-run --enrich-dry-run --evidence-assets',
    'genealogy:enrich-media --status',
    'genealogy:transcribe-media --status',
    'genealogy:agent-triage --json',
    'genealogy:agent-triage --compact',
    'genealogy:agent-triage --json --compact',
    'genealogy:agent-triage --days=30 --json --compact',
    'genealogy:source-registry --validate',
    'genealogy:source-registry --validate --json --compact',
    'awo:replay --window=7d --json',
    'awo:replay --window=7d --compact',
    'awo:replay --window=7d --json --compact',
    'awo:replay --window=7d --markdown --compact',
    'awo:replay --window=7d --limit=500 --json',
    'awo:replay --window=7d --limit=500 --compact',
    'awo:replay --window=7d --limit=500 --json --compact',
    'awo:replay --window=7d --limit=500 --markdown --compact',
    'awo:replay --window=7d --limit=500 --markdown',
    'awo:replay --compare-scheduled --window=7d --limit=500 --json',
    'scheduler:optimize-report --json',
    'scheduler:optimize-report --window=7d --json',
    'scheduler:optimize-report --compact',
    'scheduler:optimize-report --json --compact',
    'news:bias-tags-audit --json',
    'news:bias-tags-audit --compact',
    'news:bias-tags-audit --json --compact',
    'news:pushover-proof --workflow=news_brief --compact',
    'news:pushover-proof --workflow=news_brief --json --compact',
    'news:pushover-proof --workflow=\'Cybersecurity News Brief\' --compact',
    'news:pushover-proof --workflow=\'Cybersecurity News Brief\' --json --compact',
    'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --compact',
    'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --json --compact',
    'news:source-inventory --workflow=news_brief --days=7 --strict --json',
    'news:source-inventory --workflow=news_brief --days=7 --strict --json --compact',
    'bias:aliases --unmatched --limit=50 --json',
    'rag:backlog-report --json',
    'rag:backlog-report --compact',
    'rag:backlog-report --json --compact',
    'files:reconcile-lifecycle --compact',
    'files:reconcile-lifecycle --json --compact',
    'files:reconcile-lifecycle --markdown --compact',
    'files:resolve-duplicates --status --json --compact',
    'files:materialize-duplicate-candidates --json --compact --limit=1000',
    'joplin:rag-reconcile --json --event-hours=24',
    'joplin:rag-reconcile --json --event-hours=24 --triggered-only',
    'joplin:rag-reconcile --json --event-hours=24 --triggered-only --limit=50 --max-delete-candidates=50 --max-dependent-rows=5000',
    'joplin:rag-refresh-touch --json --compact --limit=50 --max-candidates=200',
    'rag:scale-baseline --json',
    'rag:scale-baseline --markdown',
    'rag:scale-review --json',
    'rag:scale-review --markdown',
    'rag:scale-review --compact',
    'rag:scale-review --json --compact',
    'graph:audit-provenance --json --compact',
    'graph:snapshot-provenance --dry-run --json',
    'graph:quality-metrics --stats --json',
    'agent:procedures --stats --json --compact',
    'episodic:memory --stats --json --compact',
  ]) {
    assert.ok(ALLOWED_COMMANDS[command], `${command} should be allowlisted`);
  }

  const listing = await plosArtisan({ command: 'list', on_prod: false });

  assert.match(listing, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
  assert.match(
    listing,
    new RegExp(`Allowlist freshness: status=current loaded=${ALLOWLIST_REVISION} source=${ALLOWLIST_REVISION} dist=${ALLOWLIST_REVISION} mismatches=0`),
  );
  assert.match(listing, new RegExp(`Allowlist entries: ${Object.keys(ALLOWED_COMMANDS).length}`));
  assert.match(listing, /Compact scorecard commands \(exact forms only\):/);
  assert.match(listing, /Bounded dynamic command form \(canonical order only\):/);
  assert.match(listing, /files:enrich --type=faces --face-preview \[1–5 unique --media-id=<positive-id> or --face-review-id=<positive-id>\]/);
  assert.match(
    listing,
    /No reordered flags, wider windows, trace ids, detail output, archive\/consolidate, or writeback variants are allowlisted\./,
  );
  assert.match(listing, /All allowed commands:/);
  for (const command of COMPACT_SCORECARD_COMMANDS) {
    assert.ok(ALLOWED_COMMANDS[command], `${command} should be allowlisted`);
    assert.match(
      listing,
      new RegExp(`php artisan ${command.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`),
    );
  }
  assert.match(listing, /php artisan ops:operator-evidence --compact/);
  assert.match(listing, /php artisan ops:operator-evidence --json --compact/);
  assert.match(listing, /php artisan ops:agent-tool-policy-refresh --json --compact/);
  assert.match(listing, /php artisan ops:agent-tool-policy-refresh --apply --json --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --json/);
  assert.match(listing, /php artisan ops:review-backlog-report --dry-run --json --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --json --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --markdown --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --next-target/);
  assert.match(listing, /php artisan ops:review-backlog-report --json --next-target/);
  assert.match(listing, /php artisan ops:review-backlog-report --next-target --focus=typed-remediation --json/);
  assert.match(listing, /php artisan ops:review-backlog-report --next-target --focus=materializable-remediation --json/);
  assert.match(listing, /php artisan ops:review-backlog-report --next-target --focus=source-backed-packet --json/);
  assert.match(listing, /php artisan ops:review-backlog-report --next-target --focus=aged-review --json/);
  assert.match(listing, /php artisan ops:review-backlog-report --json --compact --next-target --focus=face-photo-repair-packet/);
  assert.match(listing, /php artisan ops:offline-smoke --json --compact/);
  assert.match(listing, /php artisan ops:agent-doctor --compact/);
  assert.match(listing, /php artisan ops:agent-doctor --json --compact/);
  assert.match(listing, /php artisan ops:agent-doctor --json --compact --since=24/);
  assert.match(listing, /php artisan plos:agent-doctor --compact/);
  assert.match(listing, /php artisan plos:agent-doctor --json --compact/);
  assert.match(listing, /php artisan ops:agent-doctor-snapshot --dry-run --json/);
  assert.match(listing, /php artisan ops:agent-doctor-history --json --compact --days=7/);
  assert.match(listing, /php artisan plos:agent-trace-tail --limit=20 --since=24 --json --compact/);
  assert.match(listing, /php artisan ops:mcp-health --compact/);
  assert.match(listing, /php artisan ops:mcp-health --json --compact/);
  assert.match(listing, /php artisan llm:sync-providers --json --compact/);
  assert.match(listing, /php artisan llm:sync-providers --json --compact --no-live/);
  assert.match(listing, /php artisan codex:exec-smoke --json/);
  assert.match(listing, /php artisan codex:offline-capabilities --json/);
  assert.match(listing, /php artisan codex:offline-capabilities --json --probe-sidecars/);
  assert.match(listing, /php artisan codex:offline-pipeline-tools --json/);
  assert.match(listing, /php artisan codex:offline-pipeline-tools --json --compact/);
  assert.match(listing, /php artisan codex:offline-sidecar --json --dry-run/);
  assert.match(listing, /php artisan codex:offline-improvement --json --example/);
  assert.match(listing, /php artisan codex:offline-prompt-candidate-eval --json --compact --example --fail-on-ineligible/);
  assert.match(listing, /php artisan content:ocr-candidate-eval --example --json --compact --fail-on-ineligible/);
  assert.match(listing, /php artisan codex:offline-improvement-review --json --compact/);
  assert.match(listing, /php artisan codex:offline-improvement-review --json --compact --stage-review/);
  assert.match(listing, /php artisan audio:tts --json --probe/);
  assert.match(listing, /php artisan rag:rerank --json --example --no-embeddings/);
  assert.match(listing, /php artisan rag:rerank-eval --json --compact --fail-on-regression/);
  assert.match(listing, /php artisan bitllm:privacy-gate --eval --json/);
  assert.match(listing, /php artisan ops:local-runtime-scorecard --json --compact/);
  assert.match(listing, /php artisan ops:scheduled-output-audit --json --compact --fail-on-violations/);
  assert.match(listing, /php artisan ops:runtime-diagnostics --json --compact/);
  assert.match(listing, /php artisan ops:runtime-diagnostics --window=60m --focus=all --json --compact/);
  assert.match(listing, /php artisan ops:runtime-diagnostics --window=24h --json --compact/);
  assert.match(listing, /php artisan ops:runtime-diagnostics --window=24h --focus=all --json --compact/);
  assert.match(listing, /php artisan ops:queue-fit-audit --json --compact/);
  assert.match(listing, /php artisan ops:runtime-policy-leak-audit --json --compact/);
  assert.match(listing, /php artisan ollama:drift-check --json --compact --no-fail/);
  assert.match(listing, /php artisan ollama:eval-scorecard --json --compact/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --json/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --json --compact/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --markdown/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --dry-run --json/);
  assert.match(listing, /php artisan ops:capacity-utilization-manager --json --compact/);
  assert.match(listing, /php artisan ops:capacity-utilization-manager --dry-run --json --compact/);
  assert.match(listing, /php artisan ops:arc-retention --json --compact/);
  assert.match(listing, /php artisan ops:face-telemetry-report --compact/);
  assert.match(listing, /php artisan ops:face-mixed-cluster-report --limit=5 --sample-faces=3 --json/);
  assert.match(listing, /php artisan ops:face-named-only-slice --limit=10 --json/);
  assert.match(listing, /php artisan ops:dba-telemetry-report --compact/);
  assert.match(listing, /php artisan ops:dba-telemetry-report --markdown --dry-run/);
  assert.match(listing, /php artisan files:reconcile-lifecycle --compact/);
  assert.match(listing, /php artisan files:reconcile-lifecycle --json --compact/);
  assert.match(listing, /php artisan files:reconcile-lifecycle --markdown --compact/);
  assert.match(listing, /php artisan files:resolve-duplicates --status --json --compact/);
  assert.match(listing, /php artisan files:materialize-duplicate-candidates --json --compact --limit=1000/);
  assert.match(listing, /php artisan joplin:rag-reconcile --json --event-hours=24/);
  assert.match(listing, /php artisan joplin:rag-reconcile --json --event-hours=24 --triggered-only/);
  assert.match(listing, /php artisan joplin:rag-reconcile --json --event-hours=24 --triggered-only --limit=50 --max-delete-candidates=50 --max-dependent-rows=5000/);
  assert.match(listing, /php artisan joplin:rag-refresh-touch --json --compact --limit=50 --max-candidates=200/);
  assert.match(listing, /php artisan rag:scale-baseline --json/);
  assert.match(listing, /php artisan rag:scale-review --json/);
  assert.match(listing, /php artisan rag:scale-review --markdown/);
  assert.match(listing, /php artisan rag:scale-review --compact/);
  assert.match(listing, /php artisan rag:scale-review --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --json/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --markdown --compact/);
  assert.match(listing, /php artisan genealogy:evidence-asset-candidates --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-asset-candidates --dry-run --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-asset-capture-plan --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-asset-capture-plan --dry-run --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-asset-capture-review --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-asset-capture-executor --json --compact/);
  assert.match(listing, /php artisan genealogy:media-intake-report --json --compact/);
  assert.match(listing, /php artisan genealogy:media-intake-report --dry-run --json --compact/);
  assert.match(listing, /php artisan genealogy:media-intake-run --dry-run --json --compact/);
  assert.match(listing, /php artisan genealogy:enrich-media --status/);
  assert.match(listing, /php artisan genealogy:transcribe-media --status/);
  assert.match(listing, /php artisan genealogy:agent-triage --json/);
  assert.match(listing, /php artisan genealogy:agent-triage --compact/);
  assert.match(listing, /php artisan genealogy:agent-triage --json --compact/);
  assert.match(listing, /php artisan genealogy:source-registry --validate/);
  assert.match(listing, /php artisan genealogy:source-registry --validate --json --compact/);
  assert.match(listing, /php artisan genealogy:packet-reason-codes --days=30 --json/);
  assert.match(listing, /php artisan genealogy:packet-reason-codes --compact/);
  assert.match(listing, /php artisan genealogy:packet-reason-codes --json --compact/);
  assert.match(listing, /php artisan genealogy:review-feedback --compact/);
  assert.match(listing, /php artisan genealogy:review-feedback --json --compact/);
  assert.match(listing, /php artisan genealogy:reject-codes --compact/);
  assert.match(listing, /php artisan genealogy:reject-codes --json --compact/);
  assert.match(listing, /php artisan news:source-inventory --workflow=news_brief --days=7 --strict --json/);
  assert.match(listing, /php artisan news:source-inventory --workflow=news_brief --days=7 --strict --json --compact/);
  assert.match(listing, /php artisan scheduler:optimize-report --compact/);
  assert.match(listing, /php artisan news:bias-tags-audit --compact/);
  assert.match(listing, /php artisan news:pushover-proof --workflow=news_brief --compact/);
  assert.match(listing, /php artisan news:pushover-proof --workflow='Cybersecurity News Brief' --json --compact/);
  assert.match(listing, /php artisan news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --json --compact/);
  assert.match(listing, /php artisan rag:backlog-report --compact/);
  assert.match(listing, /php artisan rag:backlog-report --json --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --json --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --markdown --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --json/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --json --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --markdown --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --markdown/);
  assert.match(listing, /php artisan awo:replay --compare-scheduled --window=7d --limit=500 --json/);
  assert.match(listing, /php artisan graph:audit-provenance --json --compact/);
  assert.match(listing, /php artisan graph:snapshot-provenance --dry-run --json/);
  assert.match(listing, /php artisan graph:quality-metrics --stats --json/);
  assert.match(listing, /php artisan agent:procedures --stats --json --compact/);
  assert.match(listing, /php artisan episodic:memory --stats --json --compact/);
});

test('next-target focus variants stay blocked unless they are canonical allowlist entries', async () => {
  for (const command of [
    'ops:review-backlog-report --next-target --focus=source-backed-packet --markdown',
    'ops:review-backlog-report --next-target --focus=source-backed-packet --markdown --compact',
    'ops:review-backlog-report --next-target --focus=source-backed-packet --json --compact',
    'ops:review-backlog-report --next-target --focus=source-backed-packet --json --include-details',
    'ops:review-backlog-report --next-target --focus=source-backed-packet --markdown --include-details',
    'ops:review-backlog-report --next-target --focus=source-backed-packet --json --details',
    'ops:review-backlog-report --next-target --focus=typed-remediation --compact',
    'ops:review-backlog-report --next-target --focus=typed-remediation --compact --json',
    'ops:review-backlog-report --next-target --focus=typed-remediation --json --compact',
    'ops:review-backlog-report --next-target --focus=typed-remediation --markdown --compact',
    'ops:review-backlog-report --next-target --focus=aged-review --compact',
    'ops:review-backlog-report --next-target --focus=aged-review --json --compact',
    'ops:review-backlog-report --next-target --focus=aged-review --markdown',
    'ops:review-backlog-report --json --next-target --focus=aged-review',
    'ops:review-backlog-report --next-target --json --focus=aged-review',
    'ops:review-backlog-report --next-target --focus=face-photo-repair-packet --json --compact',
    'ops:review-backlog-report --json --next-target --focus=face-photo-repair-packet',
    'ops:review-backlog-report --json --compact --focus=face-photo-repair-packet --next-target',
  ]) {
    assert.equal(ALLOWED_COMMANDS[command], undefined, `${command} should not be allowlisted`);

    const result = await plosArtisan({ command, on_prod: false });

    assert.match(result, /Blocked:/, `${command} should be blocked`);
    assert.match(result, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
    assert.match(result, /Allowlist freshness: status=current /);
    assert.match(result, new RegExp(`Allowlist entries: ${Object.keys(ALLOWED_COMMANDS).length}`));
    assert.match(result, /Use command "list"/, `${command} should show allowlist guidance`);
  }
});


test('public allowlist excludes embedded personal tree, person and research boundaries', () => {
  for (const command of Object.keys(ALLOWED_COMMANDS)) {
    assert.doesNotMatch(command, /--tree=\d+|--person-id=\d+|--boundary=/);
  }
});

test('near-miss write commands remain blocked by exact allowlist matching', async () => {
  for (const command of [
    'files:reconcile-lifecycle --samples --json',
    'files:reconcile-lifecycle --json --samples',
    'files:reconcile-lifecycle --limit=100 --json --compact',
    'files:reconcile-lifecycle --execute',
    'files:reconcile-lifecycle --compact --json',
    'files:materialize-duplicate-candidates --json --limit=1000',
    'files:materialize-duplicate-candidates --json --compact --limit=5000',
    'files:materialize-duplicate-candidates --compact --json --limit=1000',
    'files:materialize-duplicate-candidates --json --compact --limit=1000 --execute',
    'joplin:rag-reconcile --execute --json --event-hours=24',
    'joplin:rag-reconcile --json --event-hours=24 --execute',
    'joplin:rag-reconcile --json --event-hours=24 --triggered-only --execute',
    'joplin:rag-reconcile --json --event-hours=24 --triggered-only --limit=100 --max-delete-candidates=50 --max-dependent-rows=5000',
    'joplin:rag-reconcile --json --event-hours=72 --triggered-only',
    'joplin:rag-refresh-touch --execute --confirm --json --compact --limit=50 --max-candidates=200',
    'joplin:rag-refresh-touch --json --compact --limit=50 --max-candidates=200 --execute',
    'joplin:rag-refresh-touch --compact --json --limit=50 --max-candidates=200',
    'joplin:rag-refresh-touch --json --compact --limit=100 --max-candidates=200',
    'ops:face-mixed-cluster-report --limit=5 --sample-faces=3 --json --execute',
    'ops:face-mixed-cluster-report --limit=10 --sample-faces=3 --json',
    'ops:face-mixed-cluster-report --limit=5 --sample-faces=10 --json',
    'ops:face-named-only-slice --limit=25 --json',
    'ops:face-named-only-slice --limit=10 --json --cluster=80',
    'ops:scheduled-output-audit --compact --json --fail-on-violations',
    'ops:scheduled-output-audit --json --fail-on-violations --compact',
    'ops:scheduled-output-audit --json --compact --details --fail-on-violations',
    'ops:capacity-utilization-manager --execute --json --compact',
    'ops:capacity-utilization-manager --json --compact --execute',
    'ops:capacity-utilization-manager --compact --json',
    'ops:capacity-utilization-manager --dry-run --compact --json',
    'ops:runtime-policy-leak-audit --compact --json',
    'ops:runtime-policy-leak-audit --json --compact --strict',
    'ops:operator-evidence --json',
    'ops:offline-smoke --json',
    'ops:agent-doctor --json --since=24',
    'ops:agent-doctor-history --json --days=7',
    'ops:dba-telemetry-report --json',
    'codex:offline-sidecar --json',
    'codex:offline-sidecar --json --execute',
    'codex:offline-sidecar --json --dry-run --prompt-b64=dGVzdA==',
    'codex:offline-sidecar --json --prompt-b64=dGVzdA== --dry-run',
    'codex:offline-capabilities --probe-sidecars --json',
    'codex:offline-capabilities --json --probe-sidecars --details',
    'codex:offline-pipeline-tools --compact --json',
    'codex:offline-pipeline-tools --json --task=source_triage',
    'codex:offline-improvement --json --summary-b64=dGVzdA==',
    'codex:offline-prompt-candidate-eval --json --example',
    'codex:offline-prompt-candidate-eval --json --compact --example',
    'codex:offline-prompt-candidate-eval --json --compact --example --fail-on-ineligible --artifact=test.json',
    'content:ocr-candidate-eval --json --compact --example',
    'content:ocr-candidate-eval --example --json --compact',
    'content:ocr-candidate-eval --example --json --compact --fail-on-ineligible --artifact=test.json',
    'content:extraction-provenance-audit --compact --json',
    'content:extraction-provenance-audit --json --compact --limit=100',
    'codex:offline-improvement --json --example --write-report',
    'codex:offline-improvement-review --json --compact --queue-review',
    'codex:offline-improvement-review --json --stage-review --compact',
    'audio:tts --json --execute --text-b64=dGVzdA==',
    'rag:rerank --json --example --use-embeddings',
    'graph:audit-provenance --json',
    'graph:audit-provenance --compact --json',
    'graph:audit-provenance --json --compact --samples=50',
    'graph:audit-provenance --json --compact --strict',
  ]) {
    const result = await plosArtisan({ command, on_prod: false });

    assert.match(result, /Blocked:/, `${command} should be blocked`);
    assert.match(result, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
    assert.match(result, /Use command "list"/);
  }

  const notifyResult = await plosArtisan({
    command: 'ops:face-telemetry-report --markdown --hours=168 --notify',
    on_prod: false,
  });

  assert.match(notifyResult, /Blocked:/);
  assert.match(notifyResult, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
  assert.match(notifyResult, /Use command "list"/);

  const aliasWriteResult = await plosArtisan({
    command: 'bias:aliases --add=example.com --canonical=Example --json',
    on_prod: false,
  });

  assert.match(aliasWriteResult, /Blocked:/);
  assert.match(aliasWriteResult, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
  assert.match(aliasWriteResult, /Use command "list"/);

  const historyWindowResult = await plosArtisan({
    command: 'ops:agent-doctor-history --json --days=90',
    on_prod: false,
  });

  assert.match(historyWindowResult, /Blocked:/);
  assert.match(historyWindowResult, /Use command "list"/);

  const historyCompactReorderedResult = await plosArtisan({
    command: 'ops:agent-doctor-history --json --days=7 --compact',
    on_prod: false,
  });

  assert.match(historyCompactReorderedResult, /Blocked:/);
  assert.match(historyCompactReorderedResult, /Use command "list"/);

  const historyCompactWideWindowResult = await plosArtisan({
    command: 'ops:agent-doctor-history --json --compact --days=90',
    on_prod: false,
  });

  assert.match(historyCompactWideWindowResult, /Blocked:/);
  assert.match(historyCompactWideWindowResult, /Use command "list"/);

  const agentDoctorReorderedSinceResult = await plosArtisan({
    command: 'ops:agent-doctor --json --since=24 --compact',
    on_prod: false,
  });

  assert.match(agentDoctorReorderedSinceResult, /Blocked:/);
  assert.match(agentDoctorReorderedSinceResult, /Use command "list"/);

  const capacityWriteResult = await plosArtisan({
    command: 'ops:capacity-checkpoint --write --json',
    on_prod: false,
  });

  assert.match(capacityWriteResult, /Blocked:/);
  assert.match(capacityWriteResult, /Use command "list"/);

  const capacityReorderedDryRunResult = await plosArtisan({
    command: 'ops:capacity-checkpoint --json --dry-run',
    on_prod: false,
  });

  assert.match(capacityReorderedDryRunResult, /Blocked:/);
  assert.match(capacityReorderedDryRunResult, /Use command "list"/);

  const capacityReorderedCompactResult = await plosArtisan({
    command: 'ops:capacity-checkpoint --compact --json',
    on_prod: false,
  });

  assert.match(capacityReorderedCompactResult, /Blocked:/);
  assert.match(capacityReorderedCompactResult, /Use command "list"/);

  const capacityCompactMarkdownResult = await plosArtisan({
    command: 'ops:capacity-checkpoint --markdown --compact',
    on_prod: false,
  });

  assert.match(capacityCompactMarkdownResult, /Blocked:/);
  assert.match(capacityCompactMarkdownResult, /Use command "list"/);

  const kgProvenanceWriteResult = await plosArtisan({
    command: 'graph:snapshot-provenance --json',
    on_prod: false,
  });

  assert.match(kgProvenanceWriteResult, /Blocked:/);
  assert.match(kgProvenanceWriteResult, /Use command "list"/);

  const kgProvenanceReorderedDryRunResult = await plosArtisan({
    command: 'graph:snapshot-provenance --json --dry-run',
    on_prod: false,
  });

  assert.match(kgProvenanceReorderedDryRunResult, /Blocked:/);
  assert.match(kgProvenanceReorderedDryRunResult, /Use command "list"/);

  const agentDoctorWideWindowResult = await plosArtisan({
    command: 'ops:agent-doctor --json --compact --since=168',
    on_prod: false,
  });

  assert.match(agentDoctorWideWindowResult, /Blocked:/);
  assert.match(agentDoctorWideWindowResult, /Use command "list"/);

  const agentDoctorDetailsResult = await plosArtisan({
    command: 'ops:agent-doctor --json --compact --since=24 --include-agents',
    on_prod: false,
  });

  assert.match(agentDoctorDetailsResult, /Blocked:/);
  assert.match(agentDoctorDetailsResult, /Use command "list"/);

  const sourceRegistryReorderedResult = await plosArtisan({
    command: 'genealogy:source-registry --json --compact --validate',
    on_prod: false,
  });

  assert.match(sourceRegistryReorderedResult, /Blocked:/);
  assert.match(sourceRegistryReorderedResult, /Use command "list"/);

  const episodicArchiveResult = await plosArtisan({
    command: 'episodic:memory --archive --json --compact',
    on_prod: false,
  });

  assert.match(episodicArchiveResult, /Blocked:/);
  assert.match(episodicArchiveResult, /Use command "list"/);

  const proceduralConsolidateResult = await plosArtisan({
    command: 'agent:procedures --consolidate --json --compact',
    on_prod: false,
  });

  assert.match(proceduralConsolidateResult, /Blocked:/);
  assert.match(proceduralConsolidateResult, /Use command "list"/);

  const proceduralReorderedResult = await plosArtisan({
    command: 'agent:procedures --json --compact --stats',
    on_prod: false,
  });

  assert.match(proceduralReorderedResult, /Blocked:/);
  assert.match(proceduralReorderedResult, /Use command "list"/);

  const proceduralDetailsResult = await plosArtisan({
    command: 'agent:procedures --stats --json --compact --include-procedure-names',
    on_prod: false,
  });

  assert.match(proceduralDetailsResult, /Blocked:/);
  assert.match(proceduralDetailsResult, /Use command "list"/);

  const memoryReorderedResult = await plosArtisan({
    command: 'episodic:memory --json --compact --stats',
    on_prod: false,
  });

  assert.match(memoryReorderedResult, /Blocked:/);
  assert.match(memoryReorderedResult, /Use command "list"/);

  const memoryArchiveSuffixResult = await plosArtisan({
    command: 'episodic:memory --stats --json --compact --archive',
    on_prod: false,
  });

  assert.match(memoryArchiveSuffixResult, /Blocked:/);
  assert.match(memoryArchiveSuffixResult, /Use command "list"/);

  const traceTailWiderWindowResult = await plosArtisan({
    command: 'plos:agent-trace-tail --limit=20 --since=168 --json --compact',
    on_prod: false,
  });

  assert.match(traceTailWiderWindowResult, /Blocked:/);
  assert.match(traceTailWiderWindowResult, /Use command "list"/);

  const traceTailReorderedResult = await plosArtisan({
    command: 'plos:agent-trace-tail --since=24 --limit=20 --json --compact',
    on_prod: false,
  });

  assert.match(traceTailReorderedResult, /Blocked:/);
  assert.match(traceTailReorderedResult, /Use command "list"/);

  const traceTailSpecificTraceResult = await plosArtisan({
    command: 'plos:agent-trace-tail --limit=20 --since=24 --trace=trc_example --json --compact',
    on_prod: false,
  });

  assert.match(traceTailSpecificTraceResult, /Blocked:/);
  assert.match(traceTailSpecificTraceResult, /Use command "list"/);

  const traceTailHigherLimitResult = await plosArtisan({
    command: 'plos:agent-trace-tail --limit=50 --since=24 --json --compact',
    on_prod: false,
  });

  assert.match(traceTailHigherLimitResult, /Blocked:/);
  assert.match(traceTailHigherLimitResult, /Use command "list"/);

  const traceTailRawResult = await plosArtisan({
    command: 'plos:agent-trace-tail --limit=20 --since=24 --json',
    on_prod: false,
  });

  assert.match(traceTailRawResult, /Blocked:/);
  assert.match(traceTailRawResult, /Use command "list"/);

  const traceReadResult = await plosArtisan({
    command: 'plos:agent-trace-read trc_example --since=24 --json',
    on_prod: false,
  });

  assert.match(traceReadResult, /Blocked:/);
  assert.match(traceReadResult, /Use command "list"/);

  const traceReadIdOptionResult = await plosArtisan({
    command: 'plos:agent-trace-read --id=trc_example --json',
    on_prod: false,
  });

  assert.match(traceReadIdOptionResult, /Blocked:/);
  assert.match(traceReadIdOptionResult, /Use command "list"/);

  const compareMarkdownResult = await plosArtisan({
    command: 'awo:replay --compare-scheduled --window=7d --limit=500 --markdown',
    on_prod: false,
  });

  assert.match(compareMarkdownResult, /Blocked:/);
  assert.match(compareMarkdownResult, /Use command "list"/);

  const compareCompactResult = await plosArtisan({
    command: 'awo:replay --compare-scheduled --window=7d --limit=500 --json --compact',
    on_prod: false,
  });

  assert.match(compareCompactResult, /Blocked:/);
  assert.match(compareCompactResult, /Use command "list"/);

  const reorderedAwoCompactResult = await plosArtisan({
    command: 'awo:replay --window=7d --limit=500 --compact --json',
    on_prod: false,
  });

  assert.match(reorderedAwoCompactResult, /Blocked:/);
  assert.match(reorderedAwoCompactResult, /Use command "list"/);

  const arbitraryAwoCompactLimitResult = await plosArtisan({
    command: 'awo:replay --window=7d --limit=1000 --json --compact',
    on_prod: false,
  });

  assert.match(arbitraryAwoCompactLimitResult, /Blocked:/);
  assert.match(arbitraryAwoCompactLimitResult, /Use command "list"/);

  const arcExecuteResult = await plosArtisan({
    command: 'ops:arc-retention --execute --json',
    on_prod: false,
  });

  assert.match(arcExecuteResult, /Blocked:/);
  assert.match(arcExecuteResult, /Use command "list"/);

  const arcRawResult = await plosArtisan({
    command: 'ops:arc-retention --json',
    on_prod: false,
  });

  assert.match(arcRawResult, /Blocked:/);
  assert.match(arcRawResult, /Use command "list"/);

  const arcReorderedCompactResult = await plosArtisan({
    command: 'ops:arc-retention --compact --json',
    on_prod: false,
  });

  assert.match(arcReorderedCompactResult, /Blocked:/);
  assert.match(arcReorderedCompactResult, /Use command "list"/);

  const duplicateResolverRawResult = await plosArtisan({
    command: 'files:resolve-duplicates --status',
    on_prod: false,
  });

  assert.match(duplicateResolverRawResult, /Blocked:/);
  assert.match(duplicateResolverRawResult, /Use command "list"/);

  const duplicateResolverReorderedResult = await plosArtisan({
    command: 'files:resolve-duplicates --status --compact --json',
    on_prod: false,
  });

  assert.match(duplicateResolverReorderedResult, /Blocked:/);
  assert.match(duplicateResolverReorderedResult, /Use command "list"/);

  const duplicateResolverCustomScopeResult = await plosArtisan({
    command: 'files:resolve-duplicates --status --json --compact --folder-filter=Family Tree Maker',
    on_prod: false,
  });

  assert.match(duplicateResolverCustomScopeResult, /Blocked:/);
  assert.match(duplicateResolverCustomScopeResult, /Use command "list"/);

  const dbaDeepDryRunResult = await plosArtisan({
    command: 'ops:dba-telemetry-report --markdown --dry-run --deep',
    on_prod: false,
  });

  assert.match(dbaDeepDryRunResult, /Blocked:/);
  assert.match(dbaDeepDryRunResult, /Use command "list"/);

  const reorderedCompactResult = await plosArtisan({
    command: 'ops:review-backlog-report --compact --json',
    on_prod: false,
  });

  assert.match(reorderedCompactResult, /Blocked:/);
  assert.match(reorderedCompactResult, /Use command "list"/);

  const reorderedCompactDryRunResult = await plosArtisan({
    command: 'ops:review-backlog-report --json --compact --dry-run',
    on_prod: false,
  });

  assert.match(reorderedCompactDryRunResult, /Blocked:/);
  assert.match(reorderedCompactDryRunResult, /Use command "list"/);

  const arbitraryNextTargetVariantResult = await plosArtisan({
    command: 'ops:review-backlog-report --next-target --include-details',
    on_prod: false,
  });

  assert.match(arbitraryNextTargetVariantResult, /Blocked:/);
  assert.match(arbitraryNextTargetVariantResult, /Use command "list"/);

  const arbitraryNextTargetFocusResult = await plosArtisan({
    command: 'ops:review-backlog-report --json --next-target --focus=all',
    on_prod: false,
  });

  assert.match(arbitraryNextTargetFocusResult, /Blocked:/);
  assert.match(arbitraryNextTargetFocusResult, /Use command "list"/);

  const arbitraryCanonicalNextTargetFocusResult = await plosArtisan({
    command: 'ops:review-backlog-report --next-target --focus=all --json',
    on_prod: false,
  });

  assert.match(arbitraryCanonicalNextTargetFocusResult, /Blocked:/);
  assert.match(arbitraryCanonicalNextTargetFocusResult, /Use command "list"/);

  const typedFocusOldOrderResult = await plosArtisan({
    command: 'ops:review-backlog-report --json --next-target --focus=typed-remediation',
    on_prod: false,
  });

  assert.match(typedFocusOldOrderResult, /Blocked:/);
  assert.match(typedFocusOldOrderResult, /Use command "list"/);

  const materializableFocusOldOrderResult = await plosArtisan({
    command: 'ops:review-backlog-report --json --next-target --focus=materializable-remediation',
    on_prod: false,
  });

  assert.match(materializableFocusOldOrderResult, /Blocked:/);
  assert.match(materializableFocusOldOrderResult, /Use command "list"/);

  const reorderedNextTargetFocusResult = await plosArtisan({
    command: 'ops:review-backlog-report --next-target --json --focus=typed-remediation',
    on_prod: false,
  });

  assert.match(reorderedNextTargetFocusResult, /Blocked:/);
  assert.match(reorderedNextTargetFocusResult, /Use command "list"/);

  const reorderedMaterializableFocusResult = await plosArtisan({
    command: 'ops:review-backlog-report --next-target --json --focus=materializable-remediation',
    on_prod: false,
  });

  assert.match(reorderedMaterializableFocusResult, /Blocked:/);
  assert.match(reorderedMaterializableFocusResult, /Use command "list"/);

  const sourceBackedPacketOldOrderResult = await plosArtisan({
    command: 'ops:review-backlog-report --json --next-target --focus=source-backed-packet',
    on_prod: false,
  });

  assert.match(sourceBackedPacketOldOrderResult, /Blocked:/);
  assert.match(sourceBackedPacketOldOrderResult, /Use command "list"/);

  const reorderedSourceBackedPacketFocusResult = await plosArtisan({
    command: 'ops:review-backlog-report --next-target --json --focus=source-backed-packet',
    on_prod: false,
  });

  assert.match(reorderedSourceBackedPacketFocusResult, /Blocked:/);
  assert.match(reorderedSourceBackedPacketFocusResult, /Use command "list"/);

  const canonicalNextTargetFocusExtraOptionResult = await plosArtisan({
    command: 'ops:review-backlog-report --next-target --focus=typed-remediation --json --include-details',
    on_prod: false,
  });

  assert.match(canonicalNextTargetFocusExtraOptionResult, /Blocked:/);
  assert.match(canonicalNextTargetFocusExtraOptionResult, /Use command "list"/);

  const arbitraryRunProofResult = await plosArtisan({
    command: 'news:pushover-proof --workflow=news_brief --run-id=1185 --json --compact',
    on_prod: false,
  });

  assert.match(arbitraryRunProofResult, /Blocked:/);
  assert.match(arbitraryRunProofResult, /Use command "list"/);

  const materializeDryRunResult = await plosArtisan({
    command: 'genealogy:materialize-typed-remediation --id=123 --json',
    on_prod: false,
  });

  assert.match(materializeDryRunResult, /Blocked:/);
  assert.match(materializeDryRunResult, /Use command "list"/);

  const materializeCompactIdResult = await plosArtisan({
    command: 'genealogy:materialize-typed-remediation --id=123 --json --compact',
    on_prod: false,
  });

  assert.match(materializeCompactIdResult, /Blocked:/);
  assert.match(materializeCompactIdResult, /Use command "list"/);

  const materializeCompactTokenResult = await plosArtisan({
    command: 'genealogy:materialize-typed-remediation --token=abc --json --compact',
    on_prod: false,
  });

  assert.match(materializeCompactTokenResult, /Blocked:/);
  assert.match(materializeCompactTokenResult, /Use command "list"/);

  const materializeExecuteResult = await plosArtisan({
    command: 'genealogy:materialize-typed-remediation --id=123 --execute --json',
    on_prod: false,
  });

  assert.match(materializeExecuteResult, /Blocked:/);
  assert.match(materializeExecuteResult, /Use command "list"/);

  for (const command of [
    'genealogy:media-rag-index --tree=5 --limit=20 --max-seconds=45',
    'genealogy:media-rag-index --stats',
  ]) {
    const result = await plosArtisan({ command, on_prod: false });

    assert.match(result, /Blocked:/, `${command} should be blocked`);
    assert.match(result, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
    assert.match(result, /Use command "list"/);
  }

  const packetReasonReorderedCompactResult = await plosArtisan({
    command: 'genealogy:packet-reason-codes --compact --json',
    on_prod: false,
  });

  assert.match(packetReasonReorderedCompactResult, /Blocked:/);
  assert.match(packetReasonReorderedCompactResult, /Use command "list"/);

  const rejectCodesDetailResult = await plosArtisan({
    command: 'genealogy:reject-codes --compact --daily',
    on_prod: false,
  });

  assert.match(rejectCodesDetailResult, /Blocked:/);
  assert.match(rejectCodesDetailResult, /Use command "list"/);

  const reviewFeedbackWindowResult = await plosArtisan({
    command: 'genealogy:review-feedback --compact --days=365',
    on_prod: false,
  });

  assert.match(reviewFeedbackWindowResult, /Blocked:/);
  assert.match(reviewFeedbackWindowResult, /Use command "list"/);

  const evidenceSprintReorderedMarkdownCompactResult = await plosArtisan({
    command: 'genealogy:evidence-sprint-report --compact --markdown',
    on_prod: false,
  });

  assert.match(evidenceSprintReorderedMarkdownCompactResult, /Blocked:/);
  assert.match(evidenceSprintReorderedMarkdownCompactResult, /Use command "list"/);

  const ragScaleReviewFileResult = await plosArtisan({
    command: 'rag:scale-review --json --retrieval-file=/tmp/evidence.json',
    on_prod: false,
  });

  assert.match(ragScaleReviewFileResult, /Blocked:/);
  assert.match(ragScaleReviewFileResult, /Use command "list"/);

  const ragScaleReviewReorderedCompactResult = await plosArtisan({
    command: 'rag:scale-review --compact --json',
    on_prod: false,
  });

  assert.match(ragScaleReviewReorderedCompactResult, /Blocked:/);
  assert.match(ragScaleReviewReorderedCompactResult, /Use command "list"/);

  const ragScaleReviewCompactFileResult = await plosArtisan({
    command: 'rag:scale-review --json --compact --retrieval-file=/tmp/evidence.json',
    on_prod: false,
  });

  assert.match(ragScaleReviewCompactFileResult, /Blocked:/);
  assert.match(ragScaleReviewCompactFileResult, /Use command "list"/);

  const mcpHealthFullJsonResult = await plosArtisan({
    command: 'ops:mcp-health --json',
    on_prod: false,
  });

  assert.match(mcpHealthFullJsonResult, /Blocked:/);
  assert.match(mcpHealthFullJsonResult, /Use command "list"/);

  const mcpHealthReorderedCompactResult = await plosArtisan({
    command: 'ops:mcp-health --compact --json',
    on_prod: false,
  });

  assert.match(mcpHealthReorderedCompactResult, /Blocked:/);
  assert.match(mcpHealthReorderedCompactResult, /Use command "list"/);

  const mcpHealthProcessDetailsResult = await plosArtisan({
    command: 'ops:mcp-health --json --compact --include-process-lines',
    on_prod: false,
  });

  assert.match(mcpHealthProcessDetailsResult, /Blocked:/);
  assert.match(mcpHealthProcessDetailsResult, /Use command "list"/);

  for (const command of [
    'ops:offline-smoke --compact',
    'ops:offline-smoke --compact --json',
    'ops:offline-smoke --json --compact --hours=168',
    'ops:offline-smoke --json --compact --profile=default',
  ]) {
    const result = await plosArtisan({ command, on_prod: false });

    assert.match(result, /Blocked:/, `${command} should be blocked`);
    assert.match(result, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
    assert.match(result, /Use command "list"/);
  }

  const llmSyncReorderedResult = await plosArtisan({
    command: 'llm:sync-providers --compact --json',
    on_prod: false,
  });

  assert.match(llmSyncReorderedResult, /Blocked:/);
  assert.match(llmSyncReorderedResult, /Use command "list"/);

  const llmSyncStrictResult = await plosArtisan({
    command: 'llm:sync-providers --json --compact --strict',
    on_prod: false,
  });

  assert.match(llmSyncStrictResult, /Blocked:/);
  assert.match(llmSyncStrictResult, /Use command "list"/);

  const ollamaDriftReorderedResult = await plosArtisan({
    command: 'ollama:drift-check --compact --json --no-fail',
    on_prod: false,
  });

  assert.match(ollamaDriftReorderedResult, /Blocked:/);
  assert.match(ollamaDriftReorderedResult, /Use command "list"/);

  const ollamaDriftFullJsonResult = await plosArtisan({
    command: 'ollama:drift-check --json --no-fail',
    on_prod: false,
  });

  assert.match(ollamaDriftFullJsonResult, /Blocked:/);
  assert.match(ollamaDriftFullJsonResult, /Use command "list"/);

  for (const command of [
    'ops:local-runtime-scorecard --json',
    'ops:local-runtime-scorecard --compact',
    'ops:local-runtime-scorecard --compact --json',
    'ops:local-runtime-scorecard --json --compact --details',
    'ops:local-runtime-scorecard --json --compact --execute',
  ]) {
    const result = await plosArtisan({ command, on_prod: false });

    assert.match(result, /Blocked:/, `${command} should be blocked`);
    assert.match(result, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
    assert.match(result, /Use command "list"/);
  }

  const ollamaScorecardFullJsonResult = await plosArtisan({
    command: 'ollama:eval-scorecard --json',
    on_prod: false,
  });

  assert.match(ollamaScorecardFullJsonResult, /Blocked:/);
  assert.match(ollamaScorecardFullJsonResult, /Use command "list"/);

  const ollamaScorecardModelResult = await plosArtisan({
    command: 'ollama:eval-scorecard qwen3:8b --json --compact',
    on_prod: false,
  });

  assert.match(ollamaScorecardModelResult, /Blocked:/);
  assert.match(ollamaScorecardModelResult, /Use command "list"/);

  const graphQualityRunResult = await plosArtisan({
    command: 'graph:quality-metrics --run --json',
    on_prod: false,
  });

  assert.match(graphQualityRunResult, /Blocked:/);
  assert.match(graphQualityRunResult, /Use command "list"/);
});


test('YouTube rerun admits one exact scheduled job and rejects wider execution or overrides', async () => {
  const command = 'scheduler:run --job=workflow_youtube_watch_later --sync';
  assert.deepEqual(resolveAllowedCommand(command), { command, meta: ALLOWED_COMMANDS[command] });
  assert.equal(ALLOWED_COMMANDS[command].timeout, 30_000);
  for (const rejected of [
    'workflow:run youtube_watch_later', 'scheduler:run',
    'scheduler:run --job=other --sync', 'scheduler:run --job=28 --sync',
    'scheduler:run --sync --job=workflow_youtube_watch_later',
    `${command} --force`, `${command} --worker-id=1`, `${command} --input={}`,
    `${command}; echo bad`, `${command} && echo bad`, `${command}\necho bad`,
  ]) {
    assert.equal(resolveAllowedCommand(rejected), null);
    assert.match(await plosArtisan({ command: rejected, on_prod: true }), /^Blocked:/);
  }
});


test('OS review bridge requires explicit bounded executor support and only two exact forms', async () => {
  for (const tool of ['python3', 'prlimit']) {
    const command = `os-tools:review ${tool} --json`;
    assert.equal(Object.hasOwn(ALLOWED_COMMANDS, command), false);
    assert.equal(resolveDynamicAllowedCommand(command), null); // Actual old loaded call signature.
    assert.equal(resolveDynamicAllowedCommand(command, { boundedExecutor: false }), null);
    assert.equal(resolveAllowedCommand(command)?.meta.maxBuffer, 262144);
    for (const rejected of [
      `os-tools:review ${tool}`, `os-tools:review --json ${tool}`, `${command} --compact`,
      `${command} --against=${'a'.repeat(64)}`, `${command} --no-interaction`, `${command} --json`,
      `${command}; echo unsafe`, `${command} && echo unsafe`, `${command} | cat`,
      `${command} $(echo unsafe)`, `${command}\necho unsafe`, `os-tools:review /usr/bin/${tool} --json`,
    ]) {
      assert.equal(resolveAllowedCommand(rejected), null);
      let calls = 0;
      assert.match(await plosArtisan({ command: rejected, on_prod: false }, undefined, async () => {
        calls++; throw new Error('must not execute');
      }), /^Blocked:/);
      assert.equal(calls, 0);
    }
  }
  assert.equal(resolveAllowedCommand('os-tools:review shellcheck --json'), null);
  assert.equal(resolveAllowedCommand('os-tools:discover --json'), null);
  const listing = await plosArtisan({ command: 'list', on_prod: false });
  assert.match(listing, /os-tools:review python3 --json \(bounded read-only evidence\)/);
});

test('OS read passes maxBuffer and preserves entire pretty JSON report binding', async () => {
  const report = { success: true, review_binding_complete: true, evidence_sha256: 'a'.repeat(64),
    evidence_binding: { complete: true, executables: [{ content_sha256: 'b'.repeat(64) }],
      execution_environment: { host_fingerprint: 'c'.repeat(64) }, documents: ['retained'.repeat(1000)] } };
  const stdout = JSON.stringify(report, null, 2);
  let calls = 0;
  const response = await plosArtisan({ command: 'os-tools:review python3 --json', on_prod: false }, undefined,
    async (command, options) => {
      calls++; assert.match(command, /php artisan os-tools:review python3 --json$/);
      assert.equal(options?.maxBuffer, 262144); assert.equal(options?.timeout, 60000);
      return { stdout, stderr: '' };
    });
  assert.equal(calls, 1);
  assert.equal(response.split('\n\n').slice(1).join('\n\n'), stdout);
  assert.deepEqual(JSON.parse(response.split('\n\n').slice(1).join('\n\n')), report);
});

test('OS read failed/overflow transport never forwards partial JSON as complete', async () => {
  for (const code of ['ERR_CHILD_PROCESS_STDIO_MAXBUFFER', 'ETIMEDOUT']) {
    const response = await plosArtisan({ command: 'os-tools:review prlimit --json', on_prod: false }, undefined,
      async () => { throw Object.assign(new Error('private diagnostic'), {
        code, stdout: '{"evidence_sha256":"partial-private', stderr: 'private diagnostic',
      }); });
    const receipt = JSON.parse(response);
    assert.equal(receipt.success, false); assert.equal(receipt.review_binding_complete, false);
    assert.equal(receipt.evidence_binding.complete, false);
    assert.equal(receipt.reason_code, code === 'ETIMEDOUT' ? 'review_transport_unavailable' : 'review_transport_output_limit');
    assert.equal(Object.hasOwn(receipt, 'evidence_sha256'), false);
    assert.doesNotMatch(response, /partial-private|private diagnostic/);
  }
});

test('OS read checks UTF-8 bytes on resolved outputs and leaves ordinary commands unchanged', async () => {
  const response = await plosArtisan({ command: 'os-tools:review python3 --json', on_prod: false }, undefined,
    async () => ({ stdout: 'é'.repeat(131073), stderr: '' }));
  assert.equal(JSON.parse(response).reason_code, 'review_transport_output_limit');
  const ordinary = await plosArtisan({ command: 'ops:validate-sql', on_prod: false }, undefined,
    async (_command, options) => { assert.equal(Object.hasOwn(options ?? {}, 'maxBuffer'), false);
      return { stdout: 'ordinary result', stderr: '' }; });
  assert.match(ordinary, /ordinary result$/);
});


test('actual Node exec maxBuffer overflow produces incomplete OS read receipt', async () => {
  let partialBytes = 0;
  const response = await plosArtisan({ command: 'os-tools:review python3 --json', on_prod: false }, undefined,
    async (_command, options) => {
      // Synthetic local Node only; never run the assembled artisan/SSH command.
      try {
        return await execCommand(`"${process.execPath}" -e "process.stdout.write('x'.repeat(262145))"`, options);
      } catch (error) {
        partialBytes = Buffer.byteLength((error as ExecCommandError).stdout ?? '', 'utf8');
        assert.equal((error as ExecCommandError).code, 'ERR_CHILD_PROCESS_STDIO_MAXBUFFER');
        throw error;
      }
    });
  assert.ok(partialBytes > 0 && partialBytes <= 262144);
  const receipt = JSON.parse(response);
  assert.equal(receipt.success, false);
  assert.equal(receipt.reason_code, 'review_transport_output_limit');
  assert.equal(receipt.review_binding_complete, false);
  assert.equal(Object.hasOwn(receipt, 'evidence_sha256'), false);
});

test('actual old executor lookup cannot admit new refreshed dynamic read form', () => {
  const command = 'os-tools:review python3 --json';
  const oldLookup = (value: string) => ALLOWED_COMMANDS[value] ?? resolveDynamicAllowedCommand(value);
  assert.equal(oldLookup(command), null);
  assert.equal(resolveAllowedCommand(command)?.meta.maxBuffer, 262144);
  assert.ok(oldLookup('ops:validate-sql'));
});


test('OS read caps aggregate stdout plus stderr and accepts the exact combined boundary', async () => {
  const held = await plosArtisan({ command: 'os-tools:review python3 --json', on_prod: false }, undefined,
    async () => ({ stdout: 'x'.repeat(204800), stderr: 'y'.repeat(204800) }));
  const receipt = JSON.parse(held);
  assert.equal(receipt.reason_code, 'review_transport_output_limit');
  assert.equal(receipt.observed_output.stdout_utf8_bytes, 204800);
  assert.equal(receipt.observed_output.stderr_utf8_bytes, 204800);
  assert.equal(receipt.observed_output.diagnostic_only, true);
  assert.equal(receipt.observed_output.complete, false);
  assert.equal(receipt.raw_failed_report_returned, false);
  assert.equal(receipt.raw_failed_report_durably_retained, false);
  assert.equal(Object.hasOwn(receipt, 'evidence_sha256'), false);
  const boundary = await plosArtisan({ command: 'os-tools:review python3 --json', on_prod: false }, undefined,
    async () => ({ stdout: 'x'.repeat(131072), stderr: 'y'.repeat(131072) }));
  assert.match(boundary, /^\[DEV\]/);
  const over = await plosArtisan({ command: 'os-tools:review python3 --json', on_prod: false }, undefined,
    async () => ({ stdout: 'x'.repeat(131073), stderr: 'y'.repeat(131072) }));
  assert.equal(JSON.parse(over).reason_code, 'review_transport_output_limit');
});

test('pure stdout complete JSON at exact byte cap is preserved without relabeling', async () => {
  const template = JSON.stringify({ success: true, evidence_binding: { complete: true }, padding: '' });
  const stdout = JSON.stringify({ success: true, evidence_binding: { complete: true },
    padding: 'x'.repeat(262144 - Buffer.byteLength(template)) });
  assert.equal(Buffer.byteLength(stdout), 262144);
  const response = await plosArtisan({ command: 'os-tools:review prlimit --json', on_prod: false }, undefined,
    async () => ({ stdout, stderr: '' }));
  assert.equal(response.split('\n\n').slice(1).join('\n\n'), stdout);
  assert.equal(JSON.parse(response.split('\n\n').slice(1).join('\n\n')).evidence_binding.complete, true);
});

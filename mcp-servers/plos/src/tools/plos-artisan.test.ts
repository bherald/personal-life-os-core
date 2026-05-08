import assert from 'node:assert/strict';
import test from 'node:test';

import {
  ALLOWED_COMMANDS,
  ALLOWLIST_REVISION,
  COMPACT_SCORECARD_COMMANDS,
  plosArtisan,
} from './plos-artisan.js';

test('read-only planning evidence commands stay allowlisted', async () => {
  for (const command of [
    'ops:operator-evidence --json',
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
    'ops:offline-status --json',
    'ops:offline-smoke --json',
    'ops:agent-doctor --json --since=24',
    'ops:agent-doctor --compact',
    'ops:agent-doctor --json --compact',
    'ops:agent-doctor --json --compact --since=24',
    'plos:agent-doctor --compact',
    'plos:agent-doctor --json --compact',
    'ops:agent-doctor-snapshot --dry-run --json',
    'ops:agent-doctor-history --json --days=7',
    'plos:agent-trace-tail --limit=20 --since=24 --json',
    'ops:mcp-health --compact',
    'ops:mcp-health --json --compact',
    'ops:capacity-report --json',
    'ops:capacity-checkpoint --json',
    'ops:capacity-checkpoint --json --compact',
    'ops:capacity-checkpoint --markdown',
    'ops:capacity-checkpoint --dry-run --json',
    'ops:runtime-diagnostics --window=60m --focus=all --json',
    'ops:face-telemetry-report --json',
    'ops:face-telemetry-report --markdown --hours=168',
    'ops:face-telemetry-report --compact',
    'ops:face-telemetry-report --json --compact',
    'ops:face-telemetry-report --markdown --compact',
    'ops:dba-telemetry-report --json',
    'ops:dba-telemetry-report --compact',
    'ops:dba-telemetry-report --json --compact',
    'ops:dba-telemetry-report --markdown --compact',
    'ops:dba-telemetry-report --markdown --dry-run',
    'ops:arc-retention --json',
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
    'genealogy:agent-triage --json',
    'genealogy:agent-triage --compact',
    'genealogy:agent-triage --json --compact',
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
    'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --compact',
    'news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --json --compact',
    'news:source-inventory --workflow=news_brief --days=7 --strict --json',
    'news:source-inventory --workflow=news_brief --days=7 --strict --json --compact',
    'bias:aliases --unmatched --limit=50 --json',
    'rag:backlog-report --json',
    'rag:backlog-report --compact',
    'rag:backlog-report --json --compact',
    'rag:scale-baseline --json',
    'rag:scale-baseline --markdown',
    'rag:scale-review --json',
    'rag:scale-review --markdown',
    'rag:scale-review --compact',
    'rag:scale-review --json --compact',
    'graph:audit-provenance --json',
    'graph:quality-metrics --stats --json',
    'agent:procedures --stats --json --compact',
    'episodic:memory --stats --json --compact',
  ]) {
    assert.ok(ALLOWED_COMMANDS[command], `${command} should be allowlisted`);
  }

  const listing = await plosArtisan({ command: 'list', on_prod: false });

  assert.match(listing, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
  assert.match(listing, new RegExp(`Allowlist entries: ${Object.keys(ALLOWED_COMMANDS).length}`));
  assert.match(listing, /Compact scorecard commands \(exact forms only\):/);
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
  assert.match(listing, /php artisan ops:operator-evidence --json/);
  assert.match(listing, /php artisan ops:operator-evidence --compact/);
  assert.match(listing, /php artisan ops:operator-evidence --json --compact/);
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
  assert.match(listing, /php artisan ops:offline-smoke --json/);
  assert.match(listing, /php artisan ops:agent-doctor --json --since=24/);
  assert.match(listing, /php artisan ops:agent-doctor --compact/);
  assert.match(listing, /php artisan ops:agent-doctor --json --compact/);
  assert.match(listing, /php artisan ops:agent-doctor --json --compact --since=24/);
  assert.match(listing, /php artisan plos:agent-doctor --compact/);
  assert.match(listing, /php artisan plos:agent-doctor --json --compact/);
  assert.match(listing, /php artisan ops:agent-doctor-snapshot --dry-run --json/);
  assert.match(listing, /php artisan ops:agent-doctor-history --json --days=7/);
  assert.match(listing, /php artisan ops:agent-doctor-history --json --compact --days=7/);
  assert.match(listing, /php artisan plos:agent-trace-tail --limit=20 --since=24 --json/);
  assert.match(listing, /php artisan ops:mcp-health --compact/);
  assert.match(listing, /php artisan ops:mcp-health --json --compact/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --json/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --json --compact/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --markdown/);
  assert.match(listing, /php artisan ops:capacity-checkpoint --dry-run --json/);
  assert.match(listing, /php artisan ops:arc-retention --json/);
  assert.match(listing, /php artisan ops:face-telemetry-report --compact/);
  assert.match(listing, /php artisan ops:dba-telemetry-report --compact/);
  assert.match(listing, /php artisan ops:dba-telemetry-report --markdown --dry-run/);
  assert.match(listing, /php artisan rag:scale-baseline --json/);
  assert.match(listing, /php artisan rag:scale-review --json/);
  assert.match(listing, /php artisan rag:scale-review --markdown/);
  assert.match(listing, /php artisan rag:scale-review --compact/);
  assert.match(listing, /php artisan rag:scale-review --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --json/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --json --compact/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --markdown --compact/);
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
  assert.match(listing, /php artisan graph:audit-provenance --json/);
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
  ]) {
    assert.equal(ALLOWED_COMMANDS[command], undefined, `${command} should not be allowlisted`);

    const result = await plosArtisan({ command, on_prod: false });

    assert.match(result, /Blocked:/, `${command} should be blocked`);
    assert.match(result, new RegExp(`Allowlist revision: ${ALLOWLIST_REVISION}`));
    assert.match(result, new RegExp(`Allowlist entries: ${Object.keys(ALLOWED_COMMANDS).length}`));
    assert.match(result, /Use command "list"/, `${command} should show allowlist guidance`);
  }
});

test('near-miss write commands remain blocked by exact allowlist matching', async () => {
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
    command: 'plos:agent-trace-tail --limit=20 --since=168 --json',
    on_prod: false,
  });

  assert.match(traceTailWiderWindowResult, /Blocked:/);
  assert.match(traceTailWiderWindowResult, /Use command "list"/);

  const traceTailReorderedResult = await plosArtisan({
    command: 'plos:agent-trace-tail --since=24 --limit=20 --json',
    on_prod: false,
  });

  assert.match(traceTailReorderedResult, /Blocked:/);
  assert.match(traceTailReorderedResult, /Use command "list"/);

  const traceTailSpecificTraceResult = await plosArtisan({
    command: 'plos:agent-trace-tail --limit=20 --since=24 --trace=trc_example --json',
    on_prod: false,
  });

  assert.match(traceTailSpecificTraceResult, /Blocked:/);
  assert.match(traceTailSpecificTraceResult, /Use command "list"/);

  const traceTailHigherLimitResult = await plosArtisan({
    command: 'plos:agent-trace-tail --limit=50 --since=24 --json',
    on_prod: false,
  });

  assert.match(traceTailHigherLimitResult, /Blocked:/);
  assert.match(traceTailHigherLimitResult, /Use command "list"/);

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

  const graphQualityRunResult = await plosArtisan({
    command: 'graph:quality-metrics --run --json',
    on_prod: false,
  });

  assert.match(graphQualityRunResult, /Blocked:/);
  assert.match(graphQualityRunResult, /Use command "list"/);
});

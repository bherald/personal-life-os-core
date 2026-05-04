import assert from 'node:assert/strict';
import test from 'node:test';

import { ALLOWED_COMMANDS, plosArtisan } from './plos-artisan.js';

test('read-only planning evidence commands stay allowlisted', async () => {
  for (const command of [
    'ops:operator-evidence --json',
    'ops:operator-evidence --compact',
    'ops:operator-evidence --json --compact',
    'ops:review-backlog-report --json',
    'ops:review-backlog-report --markdown',
    'ops:review-backlog-report --dry-run',
    'ops:review-backlog-report --compact',
    'ops:review-backlog-report --json --compact',
    'ops:review-backlog-report --markdown --compact',
    'ops:offline-status --json',
    'ops:offline-smoke --json',
    'ops:agent-doctor --json --since=24',
    'ops:agent-doctor --compact',
    'ops:agent-doctor --json --compact',
    'plos:agent-doctor --compact',
    'plos:agent-doctor --json --compact',
    'ops:agent-doctor-snapshot --dry-run --json',
    'ops:agent-doctor-history --json --days=7',
    'ops:capacity-report --json',
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
    'genealogy:reject-codes --json --compact',
    'genealogy:review-feedback --days=30 --json',
    'genealogy:review-feedback --json --compact',
    'genealogy:packet-reason-codes --days=30 --json',
    'genealogy:packet-reason-codes --json --compact',
    'genealogy:evidence-sprint-report --json',
    'genealogy:evidence-sprint-report --json --compact',
    'genealogy:evidence-sprint-report --markdown',
    'genealogy:agent-triage --json',
    'genealogy:agent-triage --json --compact',
    'awo:replay --window=7d --json',
    'awo:replay --window=7d --limit=500 --json',
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
    'bias:aliases --unmatched --limit=50 --json',
    'rag:backlog-report --json',
    'rag:backlog-report --compact',
    'rag:backlog-report --json --compact',
    'rag:scale-baseline --json',
    'rag:scale-baseline --markdown',
    'rag:scale-review --json',
    'rag:scale-review --markdown',
    'graph:audit-provenance --json',
    'graph:quality-metrics --stats --json',
  ]) {
    assert.ok(ALLOWED_COMMANDS[command], `${command} should be allowlisted`);
  }

  const listing = await plosArtisan({ command: 'list', on_prod: false });

  assert.match(listing, /php artisan ops:operator-evidence --json/);
  assert.match(listing, /php artisan ops:operator-evidence --compact/);
  assert.match(listing, /php artisan ops:operator-evidence --json --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --json/);
  assert.match(listing, /php artisan ops:review-backlog-report --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --json --compact/);
  assert.match(listing, /php artisan ops:review-backlog-report --markdown --compact/);
  assert.match(listing, /php artisan ops:offline-smoke --json/);
  assert.match(listing, /php artisan ops:agent-doctor --json --since=24/);
  assert.match(listing, /php artisan ops:agent-doctor --compact/);
  assert.match(listing, /php artisan ops:agent-doctor --json --compact/);
  assert.match(listing, /php artisan plos:agent-doctor --compact/);
  assert.match(listing, /php artisan ops:agent-doctor-snapshot --dry-run --json/);
  assert.match(listing, /php artisan ops:agent-doctor-history --json --days=7/);
  assert.match(listing, /php artisan ops:arc-retention --json/);
  assert.match(listing, /php artisan ops:face-telemetry-report --compact/);
  assert.match(listing, /php artisan ops:dba-telemetry-report --compact/);
  assert.match(listing, /php artisan ops:dba-telemetry-report --markdown --dry-run/);
  assert.match(listing, /php artisan rag:scale-baseline --json/);
  assert.match(listing, /php artisan rag:scale-review --json/);
  assert.match(listing, /php artisan rag:scale-review --markdown/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --json/);
  assert.match(listing, /php artisan genealogy:evidence-sprint-report --json --compact/);
  assert.match(listing, /php artisan genealogy:agent-triage --json/);
  assert.match(listing, /php artisan genealogy:agent-triage --json --compact/);
  assert.match(listing, /php artisan genealogy:packet-reason-codes --days=30 --json/);
  assert.match(listing, /php artisan genealogy:packet-reason-codes --json --compact/);
  assert.match(listing, /php artisan genealogy:review-feedback --json --compact/);
  assert.match(listing, /php artisan genealogy:reject-codes --json --compact/);
  assert.match(listing, /php artisan news:source-inventory --workflow=news_brief --days=7 --strict --json/);
  assert.match(listing, /php artisan scheduler:optimize-report --compact/);
  assert.match(listing, /php artisan news:bias-tags-audit --compact/);
  assert.match(listing, /php artisan news:pushover-proof --workflow=news_brief --compact/);
  assert.match(listing, /php artisan news:pushover-proof --workflow=Press_Enterprise_Headlines_Today --json --compact/);
  assert.match(listing, /php artisan rag:backlog-report --compact/);
  assert.match(listing, /php artisan rag:backlog-report --json --compact/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --json/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --markdown/);
  assert.match(listing, /php artisan awo:replay --compare-scheduled --window=7d --limit=500 --json/);
  assert.match(listing, /php artisan graph:audit-provenance --json/);
  assert.match(listing, /php artisan graph:quality-metrics --stats --json/);
});

test('near-miss write commands remain blocked by exact allowlist matching', async () => {
  const notifyResult = await plosArtisan({
    command: 'ops:face-telemetry-report --markdown --hours=168 --notify',
    on_prod: false,
  });

  assert.match(notifyResult, /Blocked:/);
  assert.match(notifyResult, /Use command "list"/);

  const aliasWriteResult = await plosArtisan({
    command: 'bias:aliases --add=example.com --canonical=Example --json',
    on_prod: false,
  });

  assert.match(aliasWriteResult, /Blocked:/);
  assert.match(aliasWriteResult, /Use command "list"/);

  const historyWindowResult = await plosArtisan({
    command: 'ops:agent-doctor-history --json --days=90',
    on_prod: false,
  });

  assert.match(historyWindowResult, /Blocked:/);
  assert.match(historyWindowResult, /Use command "list"/);

  const compareMarkdownResult = await plosArtisan({
    command: 'awo:replay --compare-scheduled --window=7d --limit=500 --markdown',
    on_prod: false,
  });

  assert.match(compareMarkdownResult, /Blocked:/);
  assert.match(compareMarkdownResult, /Use command "list"/);

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

  const arbitraryRunProofResult = await plosArtisan({
    command: 'news:pushover-proof --workflow=news_brief --run-id=1185 --json --compact',
    on_prod: false,
  });

  assert.match(arbitraryRunProofResult, /Blocked:/);
  assert.match(arbitraryRunProofResult, /Use command "list"/);

  const ragScaleReviewFileResult = await plosArtisan({
    command: 'rag:scale-review --json --retrieval-file=/tmp/evidence.json',
    on_prod: false,
  });

  assert.match(ragScaleReviewFileResult, /Blocked:/);
  assert.match(ragScaleReviewFileResult, /Use command "list"/);

  const graphQualityRunResult = await plosArtisan({
    command: 'graph:quality-metrics --run --json',
    on_prod: false,
  });

  assert.match(graphQualityRunResult, /Blocked:/);
  assert.match(graphQualityRunResult, /Use command "list"/);
});

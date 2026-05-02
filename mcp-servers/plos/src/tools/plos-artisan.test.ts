import assert from 'node:assert/strict';
import test from 'node:test';

import { ALLOWED_COMMANDS, plosArtisan } from './plos-artisan.js';

test('read-only planning evidence commands stay allowlisted', async () => {
  for (const command of [
    'ops:operator-evidence --json',
    'ops:offline-status --json',
    'ops:offline-smoke --json',
    'ops:agent-doctor --json --since=24',
    'ops:agent-doctor-snapshot --dry-run --json',
    'ops:agent-doctor-history --json --days=7',
    'ops:capacity-report --json',
    'ops:runtime-diagnostics --window=60m --focus=all --json',
    'ops:face-telemetry-report --json',
    'ops:face-telemetry-report --markdown --hours=168',
    'ops:dba-telemetry-report --json',
    'ops:audit-privacy-routing --json',
    'genealogy:reject-codes --json',
    'genealogy:reject-codes --json --days=30',
    'genealogy:review-feedback --days=30 --json',
    'genealogy:packet-reason-codes --days=30 --json',
    'genealogy:agent-triage --json',
    'awo:replay --window=7d --json',
    'awo:replay --window=7d --limit=500 --json',
    'awo:replay --window=7d --limit=500 --markdown',
    'awo:replay --compare-scheduled --window=7d --limit=500 --json',
    'scheduler:optimize-report --json',
    'news:bias-tags-audit --json',
    'news:source-inventory --workflow=news_brief --days=7 --strict --json',
    'bias:aliases --unmatched --limit=50 --json',
    'rag:backlog-report --json',
    'graph:audit-provenance --json',
  ]) {
    assert.ok(ALLOWED_COMMANDS[command], `${command} should be allowlisted`);
  }

  const listing = await plosArtisan({ command: 'list', on_prod: false });

  assert.match(listing, /php artisan ops:operator-evidence --json/);
  assert.match(listing, /php artisan ops:offline-smoke --json/);
  assert.match(listing, /php artisan ops:agent-doctor --json --since=24/);
  assert.match(listing, /php artisan ops:agent-doctor-snapshot --dry-run --json/);
  assert.match(listing, /php artisan ops:agent-doctor-history --json --days=7/);
  assert.match(listing, /php artisan genealogy:agent-triage --json/);
  assert.match(listing, /php artisan genealogy:packet-reason-codes --days=30 --json/);
  assert.match(listing, /php artisan news:source-inventory --workflow=news_brief --days=7 --strict --json/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --json/);
  assert.match(listing, /php artisan awo:replay --window=7d --limit=500 --markdown/);
  assert.match(listing, /php artisan awo:replay --compare-scheduled --window=7d --limit=500 --json/);
  assert.match(listing, /php artisan graph:audit-provenance --json/);
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
});

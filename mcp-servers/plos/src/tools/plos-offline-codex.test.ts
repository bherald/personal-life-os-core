import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildOfflineCapabilitiesCommand,
  buildColibriLocalResearchCommand,
  buildColibriResearchCommand,
  buildOfflineImprovementCommand,
  buildOfflinePipelineToolsCommand,
  buildOfflineSidecarCommand,
} from './plos-offline-codex.js';

test('offline capabilities command exposes manifest and optional sidecar probes', () => {
  const normal = buildOfflineCapabilitiesCommand({
    on_prod: false,
    probe_sidecars: false,
  });
  const probing = buildOfflineCapabilitiesCommand({
    on_prod: false,
    probe_sidecars: true,
  });

  assert.match(normal, /php artisan 'codex:offline-capabilities' '--json'/);
  assert.doesNotMatch(normal, /--probe-sidecars/);
  assert.match(probing, /'--probe-sidecars'/);
});

test('offline sidecar command base64-encodes custom prompts for shell safety', () => {
  const prompt = 'Inspect docs; then say "done" && touch /tmp/should-not-run';
  const command = buildOfflineSidecarCommand({
    prompt,
    execute: false,
    on_prod: false,
    timeout_seconds: 25,
    effort: 'low',
    primary_profile: 'plos-offline-coding',
    primary_model: 'qwen3-coder:30b',
    sidecar_profile: 'plos-offline-jetson',
    sidecar_model: 'qwen3-coder:30b',
  });

  assert.match(command, /php artisan 'codex:offline-sidecar'/);
  assert.match(command, /'--json'/);
  assert.match(command, /'--dry-run'/);
  assert.match(command, /'--timeout=25'/);
  assert.match(command, /'--prompt-b64=/);
  assert.doesNotMatch(command, /touch \/tmp\/should-not-run/);
  assert.doesNotMatch(command, /Inspect docs/);
});

test('offline pipeline tools command supports compact output and shell-safe task filters', () => {
  const command = buildOfflinePipelineToolsCommand({
    compact: true,
    task: 'source_triage; touch /tmp/should-not-run',
    on_prod: false,
  });

  assert.match(command, /php artisan 'codex:offline-pipeline-tools'/);
  assert.match(command, /'--json'/);
  assert.match(command, /'--compact'/);
  assert.match(command, /'--task=source_triage; touch \/tmp\/should-not-run'/);
  assert.doesNotMatch(command, /; touch \/tmp\/should-not-run(?!')/);
});

test('offline sidecar command caps execute timeout', () => {
  const command = buildOfflineSidecarCommand({
    prompt: 'check something',
    execute: true,
    on_prod: false,
    timeout_seconds: 9999,
    effort: 'medium',
    primary_profile: 'primary',
    primary_model: 'dev-model',
    sidecar_profile: 'sidecar',
    sidecar_model: 'review-model',
  });

  assert.match(command, /'--execute'/);
  assert.match(command, /'--timeout=1200'/);
  assert.match(command, /'--effort=medium'/);
  assert.match(command, /'--primary-profile=primary'/);
  assert.match(command, /'--sidecar-model=review-model'/);
});

test('Colibri research defaults to local and hides the question behind base64', () => {
  const command = buildColibriResearchCommand({
    question: 'Boston history; touch /tmp/should-not-run',
    execute: true,
    public_web_confirmed: true,
    max_iterations: 3,
    max_tool_calls: 2,
    on_prod: false,
    timeout_seconds: 900,
  });

  assert.match(command, /^cd -- /);
  assert.match(command, /php artisan 'ai:colibri-research'/);
  assert.match(command, /'--execute'/);
  assert.match(command, /'--public-web'/);
  assert.match(command, /'--question-b64=/);
  assert.match(command, /'--max-iterations=3'/);
  assert.match(command, /'--max-tool-calls=2'/);
  assert.doesNotMatch(command, /Boston history/);
  assert.doesNotMatch(command, /touch \/tmp\/should-not-run/);
});

test('Colibri research plan mode performs no execution and needs no question', () => {
  const command = buildColibriResearchCommand({
    question: '',
    execute: false,
    public_web_confirmed: false,
    max_iterations: 4,
    max_tool_calls: 4,
    on_prod: false,
    timeout_seconds: 900,
  });

  assert.doesNotMatch(command, /--execute/);
  assert.doesNotMatch(command, /--public-web/);
  assert.doesNotMatch(command, /--question-b64/);
});

test('Colibri local research selects the offline pack without public-web confirmation', () => {
  const command = buildColibriLocalResearchCommand({
    question: 'What do local documents say about the project?',
    execute: true,
    max_iterations: 2,
    max_tool_calls: 2,
    on_prod: false,
    timeout_seconds: 900,
  });

  assert.match(command, /'--execute'/);
  assert.match(command, /'--local-offline'/);
  assert.doesNotMatch(command, /--public-web/);
  assert.match(command, /'--question-b64=/);
});

test('offline improvement command base64-encodes lessons and stays observe-only by default', () => {
  const command = buildOfflineImprovementCommand({
    summary: 'Missed MCP allowlist && touch /tmp/should-not-run',
    area: 'offline_dev',
    write_report: false,
    on_prod: false,
  });

  assert.match(command, /php artisan 'codex:offline-improvement'/);
  assert.match(command, /'--json'/);
  assert.match(command, /'--summary-b64=/);
  assert.match(command, /'--area=offline_dev'/);
  assert.doesNotMatch(command, /touch \/tmp\/should-not-run/);
  assert.doesNotMatch(command, /--write-report/);
});

test('offline improvement command exposes explicit write-report gate', () => {
  const command = buildOfflineImprovementCommand({
    summary: 'Add a memory note after operator review.',
    area: 'ops',
    write_report: true,
    on_prod: false,
  });

  assert.match(command, /'--write-report'/);
});

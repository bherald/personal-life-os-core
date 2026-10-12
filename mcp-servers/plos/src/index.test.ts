import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

test('genealogy_context is registered in the MCP tool registry', () => {
  const indexSource = readFileSync(join(process.cwd(), 'src/index.ts'), 'utf8');

  assert.match(indexSource, /import \{ genealogyContext, genealogyContextInput \} from '\.\/tools\/genealogy-context\.js';/);
  assert.match(indexSource, /name: 'genealogy_context'/);
  assert.match(indexSource, /handler: genealogyContext/);
});

test('genealogy_search is registered as the bounded discovery step before context', () => {
  const indexSource = readFileSync(join(process.cwd(), 'src/index.ts'), 'utf8');
  const readme = readFileSync(join(process.cwd(), 'README.md'), 'utf8');

  assert.match(indexSource, /import \{ genealogySearch, genealogySearchInput \} from '\.\/tools\/genealogy-search\.js';/);
  assert.match(indexSource, /name: 'genealogy_search'/);
  assert.match(indexSource, /handler: genealogySearch/);
  assert.ok(indexSource.indexOf("name: 'genealogy_search'") < indexSource.indexOf("name: 'genealogy_context'"));
  assert.match(readme, /`genealogy_search` discovers tree-scoped person, family, source, and media IDs/i);
});

test('server instructions route offline Genea work through the PLOS MCP tools', () => {
  const indexSource = readFileSync(join(process.cwd(), 'src/index.ts'), 'utf8');

  assert.match(indexSource, /const SERVER_INSTRUCTIONS =/);
  assert.match(indexSource, /Genea\/genealogy are domains on this server/);
  assert.match(indexSource, /genealogy_search with the explicitly selected tree_id/);
  assert.match(indexSource, /Do not call list_mcp_resources for genealogy/);
  assert.match(indexSource, /never use or suggest a cloud or backup LLM/);
  assert.match(indexSource, /instructions: SERVER_INSTRUCTIONS/);
});

test('static genealogy resource guide supports local-model MCP discovery', () => {
  const indexSource = readFileSync(join(process.cwd(), 'src/index.ts'), 'utf8');

  assert.match(indexSource, /const GENEALOGY_TOOL_GUIDE_URI = 'plos:\/\/genealogy\/tool-guide'/);
  assert.match(indexSource, /server\.setRequestHandler\(ListResourcesRequestSchema/);
  assert.match(indexSource, /server\.setRequestHandler\(ReadResourceRequestSchema/);
  assert.match(indexSource, /Use the genealogy_search tool for bounded discovery/);
  assert.match(indexSource, /Offline mode must not use a cloud or backup LLM/);
});

test('offline Codex sidecar tools are registered in the MCP tool registry', () => {
  const indexSource = readFileSync(join(process.cwd(), 'src/index.ts'), 'utf8');

  assert.match(indexSource, /plosOfflineCapabilitiesInput/);
  assert.match(indexSource, /plosOfflinePipelineToolsInput/);
  assert.match(indexSource, /plosOfflineSidecarInput/);
  assert.match(indexSource, /plosColibriResearchInput/);
  assert.match(indexSource, /plosColibriLocalResearchInput/);
  assert.match(indexSource, /name: 'plos_offline_capabilities'/);
  assert.match(indexSource, /name: 'plos_offline_pipeline_tools'/);
  assert.match(indexSource, /name: 'plos_offline_sidecar'/);
  assert.match(indexSource, /name: 'plos_colibri_research'/);
  assert.match(indexSource, /name: 'plos_colibri_local_research'/);
  assert.match(indexSource, /handler: plosOfflineCapabilities/);
  assert.match(indexSource, /handler: plosOfflinePipelineTools/);
  assert.match(indexSource, /handler: plosOfflineSidecar/);
  assert.match(indexSource, /handler: plosColibriResearch/);
  assert.match(indexSource, /handler: plosColibriLocalResearch/);
  assert.match(indexSource, /timeoutMs: 1_260_000/);
});

test('genealogy_context includes suffixes in compact person display names', () => {
  const contextSource = readFileSync(join(process.cwd(), 'src/tools/genealogy-context.ts'), 'utf8');

  assert.match(contextSource, /SELECT id, gedcom_id, given_name, surname, suffix, nickname/);
  assert.match(contextSource, /COALESCE\(h\.suffix,''\)/);
  assert.match(contextSource, /COALESCE\(w\.suffix,''\)/);
  assert.match(contextSource, /COALESCE\(p\.suffix,''\).*AS child/s);
  assert.match(contextSource, /COALESCE\(p\.suffix,''\).*AS entity_name/s);
  assert.match(contextSource, /death_date, death_place, burial_date, burial_place/);
});

test('genealogy_batch_apply is documented and guarded in the active source contract', () => {
  const read = (relativePath: string) => readFileSync(join(process.cwd(), relativePath), 'utf8');
  const readme = read('README.md');
  const activeIndex = read('src/index.ts');
  const genealogy = read('src/tools/genealogy-batch-apply.ts');

  assert.match(readme, /`genealogy_batch_apply` batches guarded genealogy updates with a dry-run\s+default, `confirm=true` for writes/i);
  assert.match(readme, /tree-scoped write checks/i);
  assert.match(readme, /sources,\s+persons,\s+families,\s+child links,\s+media links,\s+citations,\s+and RAG\s+touch targets/i);

  assert.match(activeIndex, /import \{ genealogyBatchApply, genealogyBatchApplyInput \} from '\.\/tools\/genealogy-batch-apply\.js';/);
  assert.match(activeIndex, /name: 'genealogy_batch_apply'/);
  assert.match(activeIndex, /Defaults to dry_run/);
  assert.match(activeIndex, /handler: genealogyBatchApply/);

  assert.match(genealogy, /dry_run: z\.boolean\(\)\.optional\(\)\.default\(true\)/);
  assert.match(genealogy, /confirm: z\.boolean\(\)\.optional\(\)\.default\(false\)/);
  assert.match(genealogy, /requires confirm=true when dry_run=false/);
  assert.match(genealogy, /father_relationship: z\.enum\(\['Natural', 'Adopted', 'Step', 'Foster', 'Unknown'\]\)\.optional\(\)/);
  assert.match(genealogy, /mother_relationship: z\.enum\(\['Natural', 'Adopted', 'Step', 'Foster', 'Unknown'\]\)\.optional\(\)/);
  assert.match(genealogy, /tree_id: z\.number\(\)\.int\(\)\.positive\(\)/);
  assert.match(genealogy, /reason: z\.string\(\)\.min\(8\)\.max\(500\)/);
  for (const field of [
    'sources',
    'media_updates',
    'persons',
    'families',
    'children',
    'person_media',
    'family_media',
    'citations',
    'rag_touch',
  ]) {
    assert.match(genealogy, new RegExp(`${field}: z\\.`, 'm'));
  }

  assert.match(genealogy, /const dryRun = input\.dry_run \?\? true;/);
  assert.match(genealogy, /await assertTreeExists\(conn, input\.tree_id\);/);
  assert.match(genealogy, /await assertSourceInTree\(conn, treeId, source\.id\);/);
  assert.match(genealogy, /await assertMediaInTree\(conn, input\.tree_id, media\.id\);/);
  assert.match(genealogy, /await assertPersonInTree\(conn, input\.tree_id, personId\);/);
  assert.match(genealogy, /await assertFamilyInTree\(conn, input\.tree_id, familyId\);/);
  assert.match(genealogy, /INSERT IGNORE INTO genealogy_person_sources/);
  assert.match(genealogy, /UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW\(\) WHERE tree_id = \? AND id IN \(\?\)/);
  assert.match(genealogy, /UPDATE genealogy_sources SET rag_indexed_at = NULL, updated_at = NOW\(\) WHERE tree_id = \? AND id IN \(\?\)/);
  assert.match(genealogy, /UPDATE genealogy_media SET rag_indexed_at = NULL, updated_at = NOW\(\) WHERE tree_id = \? AND id IN \(\?\)/);
});

test('schema-bearing tools validate handler results before returning success', () => {
  const activeIndex = readFileSync(join(process.cwd(), 'src/index.ts'), 'utf8');

  assert.match(activeIndex, /buildToolSuccessResult\(result, tool\.outputSchema\)/);
  assert.match(activeIndex, /const genealogySearchOutputSchema =/);
  assert.match(activeIndex, /const genealogyContextOutputSchema =/);
  assert.match(activeIndex, /const genealogyBatchApplyOutputSchema =/);
});

test('tinker outer deadline allows its capped subprocess to finish without changing other tool defaults', () => {
  const read = (path: string) => readFileSync(join(process.cwd(), path), 'utf8');
  const indexSource = read('src/index.ts');
  const registration = indexSource.match(/name: 'plos_tinker',[\s\S]*?\n  },/)?.[0] ?? '';
  const outerMs = Number(registration.match(/timeoutMs: ([\d_]+)/)?.[1].replaceAll('_', ''));
  const tinkerSource = read('src/tools/plos-tinker.ts');
  const capSeconds = Number(tinkerSource.match(/Math\.min\(Math\.max\(5, timeoutSec\), (\d+)\)/)?.[1]);

  assert.equal(capSeconds, 120);
  assert.ok(outerMs > capSeconds * 1000, 'outer deadline must leave time for capped subprocess cleanup');
  assert.ok(outerMs <= (capSeconds + 5) * 1000, 'cleanup allowance remains bounded');
  assert.match(tinkerSource, /timeout: effectiveTimeout,[\s\S]*?signal: context\?\.signal/);
  assert.match(read('src/config.ts'), /toolTimeoutMs: 90_000/);
  assert.match(indexSource, /tool\.timeoutMs \?\? CONFIG\.toolTimeoutMs/);
  const sshRegistration = indexSource.match(/name: 'plos_ssh',[\s\S]*?\n  },/)?.[0] ?? '';
  assert.doesNotMatch(sshRegistration, /timeoutMs:/);
});

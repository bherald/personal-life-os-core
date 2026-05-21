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

test('genealogy_batch_apply is documented and guarded in the active source contract', () => {
  const read = (relativePath: string) => readFileSync(join(process.cwd(), relativePath), 'utf8');
  const readme = read('README.md');
  const activeIndex = read('src/index.ts');
  const genealogy = read('src/tools/genealogy-batch-apply.ts');

  assert.match(readme, /`genealogy_batch_apply` batches guarded genealogy updates with a dry-run\s+default, `confirm=true` for writes/i);
  assert.match(readme, /tree-scoped write checks/i);
  assert.match(readme, /sources, persons, families, child links, media links, citations, and RAG touch targets/i);

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
  assert.match(genealogy, /UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW\(\) WHERE tree_id = \? AND id IN \(\?\)/);
  assert.match(genealogy, /UPDATE genealogy_sources SET rag_indexed_at = NULL, updated_at = NOW\(\) WHERE tree_id = \? AND id IN \(\?\)/);
  assert.match(genealogy, /UPDATE genealogy_media SET rag_indexed_at = NULL, updated_at = NOW\(\) WHERE tree_id = \? AND id IN \(\?\)/);
});

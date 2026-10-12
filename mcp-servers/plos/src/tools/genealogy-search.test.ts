import assert from 'node:assert/strict';
import test from 'node:test';
import { buildGenealogySearchPlans, genealogySearchInput } from './genealogy-search.js';

test('genealogy_search validates bounded tree-scoped literal searches', () => {
  const parsed = genealogySearchInput.parse({ tree_id: 4, query: 'Alex Rowan' });
  assert.equal(parsed.entity_type, 'all');
  assert.equal(parsed.limit, 20);
  assert.throws(() => genealogySearchInput.parse({ tree_id: 0, query: 'Smith' }));
  assert.throws(() => genealogySearchInput.parse({ tree_id: 4, query: '%' }));
  assert.throws(() => genealogySearchInput.parse({ tree_id: 4, query: 'Smith', limit: 51 }));
  assert.throws(() => genealogySearchInput.parse({ tree_id: 4, query: 'Smith', sql: 'SELECT *' }));
});

test('genealogy_search all route is fixed, bound, and capped per entity', () => {
  const plans = buildGenealogySearchPlans(genealogySearchInput.parse({
    tree_id: 4,
    query: 'Cochran',
    entity_type: 'all',
    limit: 50,
  }));

  assert.deepEqual(plans.map(plan => plan.entityType), ['person', 'family', 'source', 'media']);
  assert.deepEqual(plans.map(plan => plan.key), ['people', 'families', 'sources', 'media']);
  for (const plan of plans) {
    assert.match(plan.sql, /tree_id = \?/);
    assert.match(plan.sql, /LOCATE\(LOWER\(\?\)/);
    assert.equal(plan.params[0], 4);
    assert.equal(plan.params[1], 'Cochran');
    assert.equal(plan.params.at(-1), 10);
  }
});

test('genealogy_search person route redacts living detail and avoids long text output', () => {
  const [plan] = buildGenealogySearchPlans(genealogySearchInput.parse({
    tree_id: 4,
    query: 'I42',
    entity_type: 'person',
    limit: 3,
  }));

  assert.match(plan.sql, /CASE WHEN living = 1 THEN NULL ELSE birth_date END/);
  assert.match(plan.sql, /CASE WHEN living = 1 THEN NULL ELSE death_place END/);
  assert.match(plan.sql, /CONCAT_WS\(' ', id, gedcom_id/);
  assert.doesNotMatch(plan.sql, /notes|transcription/i);
  assert.deepEqual(plan.params, [4, 'I42', 'I42', 3]);
});

test('genealogy_search media route searches filenames without returning paths or content', () => {
  const [plan] = buildGenealogySearchPlans(genealogySearchInput.parse({
    tree_id: 4,
    query: 'certificate',
    entity_type: 'media',
  }));
  const selectClause = plan.sql.slice(0, plan.sql.indexOf('FROM genealogy_media'));

  assert.match(plan.sql, /m\.local_filename/);
  assert.doesNotMatch(selectClause, /local_filename|nextcloud_path|transcription|description/);
});

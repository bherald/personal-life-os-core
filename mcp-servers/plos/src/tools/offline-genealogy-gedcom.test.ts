import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import {
  contextOfflineGedcom,
  isOfflineGenealogyDataError,
  searchOfflineGedcom,
} from './offline-genealogy-gedcom.js';

const FIXTURE = `0 HEAD
1 CHAR UTF-8
0 @I101@ INDI
1 NAME Alex /Rowan/
1 SEX M
1 BIRT
2 DATE 26 AUG 1950
2 PLAC Example City
1 FAMS @F101@
0 @I102@ INDI
1 NAME Morgan /Rowan/
1 SEX M
1 BIRT
2 DATE 14 JUL 1980
2 PLAC Example Town
1 DEAT
2 DATE 23 MAR 2020
2 PLAC Example District
1 FAMC @F101@
0 @I103@ INDI
1 NAME Private /Rowan/
1 BIRT
2 DATE 1 JAN 2000
0 @F101@ FAM
1 HUSB @I101@
1 CHIL @I102@
1 CHIL @I103@
1 MARR
2 DATE 1 JAN 1978
2 PLAC Example County
0 @S101@ SOUR
1 TITL Rowan family register
1 AUTH Example Archive
0 @M101@ OBJE
1 FILE C:\\Private\\Rowan portrait.jpg
1 TITL Rowan portrait
1 FORM jpg
0 TRLR
`;

test('offline GEDCOM fallback searches a synthetic configured tree and redacts likely living detail', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'plos-gedcom-test-'));
  const path = join(directory, 'tree.ged');
  await writeFile(path, FIXTURE, 'utf8');

  try {
    const payload = await searchOfflineGedcom({
      tree_id: 4,
      query: 'Rowan',
      entity_type: 'person',
      limit: 20,
    }, path);

    assert.ok(payload);
    assert.equal(payload.source, 'local_gedcom_snapshot');
    assert.equal(payload.database_available, false);
    assert.equal(payload.id_scope, 'gedcom_numeric');
    const people = (payload.results as { people: Array<Record<string, unknown>> }).people;
    assert.equal(people.length, 3);
    assert.equal(people.find(person => person.id === 102)?.display_name, 'Morgan Rowan');
    assert.equal(people.find(person => person.id === 102)?.death_date, '23 MAR 2020');
    assert.equal(people.find(person => person.id === 103)?.birth_date, null);
    assert.deepEqual(people.find(person => person.id === 102)?.context_call, {
      tool: 'genealogy_context',
      arguments: { tree_id: 4, person_ids: [102] },
    });
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test('offline GEDCOM context returns selected family and child records without private paths', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'plos-gedcom-test-'));
  const path = join(directory, 'tree.ged');
  await writeFile(path, FIXTURE, 'utf8');

  try {
    const payload = await contextOfflineGedcom({
      tree_id: 4,
      family_ids: [101],
      media_ids: [101],
      text_limit: 1200,
    }, path);

    assert.ok(payload);
    assert.deepEqual((payload.families as Array<Record<string, unknown>>)[0], {
      id: 101,
      gedcom_id: 'F101',
      husband_id: 101,
      husband: 'Alex Rowan',
      wife_id: null,
      wife: '',
      marriage_date: '1 JAN 1978',
      marriage_place: 'Example County',
    });
    assert.equal((payload.children as unknown[]).length, 2);
    assert.equal((payload.media as Array<Record<string, unknown>>)[0].local_filename, 'Rowan portrait.jpg');
    assert.doesNotMatch(JSON.stringify(payload), /plos-gedcom-test-/);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test('offline GEDCOM fallback is limited to connectivity and missing-data errors', () => {
  assert.equal(isOfflineGenealogyDataError({ code: 'ER_NO_SUCH_TABLE' }), true);
  assert.equal(isOfflineGenealogyDataError({ code: 'ECONNREFUSED' }), true);
  assert.equal(isOfflineGenealogyDataError({ code: 'ER_PARSE_ERROR' }), false);
});

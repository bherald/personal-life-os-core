import { randomUUID } from 'node:crypto';
import mysql from 'mysql2/promise';
import { z } from 'zod';
import { mysqlTransaction } from '../db/mysql.js';

type Row = Record<string, any>;

const keyRef = z.object({
  id: z.number().int().positive().optional(),
  key: z.string().min(1).max(100).optional(),
});

export const genealogyBatchApplyInput = z.object({
  tree_id: z.number().int().positive().describe('Genealogy tree ID. Every write is scoped to this tree.'),
  dry_run: z.boolean().optional().default(true).describe('Preview only by default; set false to write.'),
  confirm: z.boolean().optional().default(false).describe('Required when dry_run is false.'),
  reason: z.string().min(8).max(500).describe('Research or cleanup reason for the batch.'),
  sources: z.array(keyRef.extend({
    title: z.string().min(1).max(1000).optional(),
    author: z.string().max(500).optional(),
    publication: z.string().max(5000).optional(),
    repository: z.string().max(255).optional(),
    notes: z.string().max(10000).optional(),
    source_quality: z.enum(['original', 'derivative', 'authored']).optional(),
    source_category: z.enum(['original', 'derivative', 'authored']).optional(),
    information_quality: z.enum(['primary', 'secondary', 'undetermined']).optional(),
  })).max(50).optional(),
  media_updates: z.array(z.object({
    id: z.number().int().positive(),
    media_type: z.enum(['photo', 'document', 'certificate', 'census', 'military', 'obituary', 'headstone', 'video', 'audio', 'other']).optional(),
    analysis_status: z.enum(['pending', 'processing', 'completed', 'failed', 'skipped']).optional(),
    enrichment_status: z.enum(['pending', 'processing', 'completed', 'failed', 'skipped']).optional(),
    enrichment_error: z.string().max(10000).nullable().optional(),
    ai_description: z.string().max(50000).optional(),
    transcription_text: z.string().max(500000).optional(),
    transcription_source: z.enum(['manual', 'ocr', 'ai']).optional(),
  })).max(200).optional(),
  persons: z.array(keyRef.extend({
    given_name: z.string().max(255).optional(),
    surname: z.string().max(255).optional(),
    sex: z.enum(['M', 'F', 'U']).optional(),
    nickname: z.string().max(255).optional(),
    birth_date: z.string().max(50).optional(),
    birth_place: z.string().max(1000).optional(),
    death_date: z.string().max(50).optional(),
    death_place: z.string().max(1000).optional(),
    notes_append: z.string().max(20000).optional(),
    living: z.boolean().optional(),
  })).max(300).optional(),
  families: z.array(keyRef.extend({
    husband_id: z.number().int().positive().optional(),
    husband_key: z.string().min(1).max(100).optional(),
    wife_id: z.number().int().positive().optional(),
    wife_key: z.string().min(1).max(100).optional(),
    marriage_date: z.string().max(50).optional(),
    marriage_place: z.string().max(1000).optional(),
    notes: z.string().max(20000).optional(),
  })).max(300).optional(),
  children: z.array(z.object({
    family_id: z.number().int().positive().optional(),
    family_key: z.string().min(1).max(100).optional(),
    person_id: z.number().int().positive().optional(),
    person_key: z.string().min(1).max(100).optional(),
    father_relationship: z.enum(['Natural', 'Adopted', 'Step', 'Foster', 'Unknown']).optional(),
    mother_relationship: z.enum(['Natural', 'Adopted', 'Step', 'Foster', 'Unknown']).optional(),
    birth_order: z.number().int().nullable().optional(),
  })).max(1000).optional(),
  person_media: z.array(z.object({
    person_id: z.number().int().positive().optional(),
    person_key: z.string().min(1).max(100).optional(),
    media_id: z.number().int().positive(),
    notes: z.string().max(500).optional(),
    face_confirmed: z.boolean().optional(),
  })).max(1000).optional(),
  family_media: z.array(z.object({
    family_id: z.number().int().positive().optional(),
    family_key: z.string().min(1).max(100).optional(),
    media_id: z.number().int().positive(),
  })).max(1000).optional(),
  citations: z.array(z.object({
    source_id: z.number().int().positive().optional(),
    source_key: z.string().min(1).max(100).optional(),
    person_id: z.number().int().positive().optional(),
    person_key: z.string().min(1).max(100).optional(),
    family_id: z.number().int().positive().optional(),
    family_key: z.string().min(1).max(100).optional(),
    media_id: z.number().int().positive().optional(),
    fact_type: z.string().min(1).max(50),
    page: z.string().max(255).optional(),
    quality: z.number().int().min(0).max(5).optional(),
    evidence_type: z.enum(['direct', 'indirect', 'negative']).optional(),
    information_type: z.enum(['primary', 'secondary', 'indeterminate']).optional(),
    evidence_analysis: z.string().max(20000).optional(),
    text: z.string().max(20000).optional(),
  })).max(1500).optional(),
  rag_touch: z.object({
    person_ids: z.array(z.number().int().positive()).max(1000).optional(),
    person_keys: z.array(z.string().min(1).max(100)).max(1000).optional(),
    source_ids: z.array(z.number().int().positive()).max(500).optional(),
    source_keys: z.array(z.string().min(1).max(100)).max(500).optional(),
    media_ids: z.array(z.number().int().positive()).max(1000).optional(),
  }).optional(),
});

export type GenealogyBatchApplyInput = z.infer<typeof genealogyBatchApplyInput>;

type Summary = {
  dry_run: boolean;
  tree_id: number;
  reason: string;
  inserted: Record<'sources' | 'persons' | 'families' | 'children' | 'citations' | 'person_media' | 'family_media', number>;
  updated: Record<'sources' | 'persons' | 'families' | 'media', number>;
  touched_for_rag: Record<'persons' | 'sources' | 'media', number>;
  keys: Record<string, number>;
  planned?: ReturnType<typeof plannedCounts>;
};

export async function genealogyBatchApply(input: GenealogyBatchApplyInput): Promise<string> {
  const dryRun = input.dry_run ?? true;
  const summary: Summary = {
    dry_run: dryRun,
    tree_id: input.tree_id,
    reason: input.reason,
    inserted: { sources: 0, persons: 0, families: 0, children: 0, citations: 0, person_media: 0, family_media: 0 },
    updated: { sources: 0, persons: 0, families: 0, media: 0 },
    touched_for_rag: { persons: 0, sources: 0, media: 0 },
    keys: {},
  };

  if (dryRun) {
    summary.planned = plannedCounts(input);
    return JSON.stringify(summary, null, 2);
  }

  if (!input.confirm) {
    throw new Error('genealogy_batch_apply requires confirm=true when dry_run=false');
  }

  await mysqlTransaction(async (conn) => {
    await assertTreeExists(conn, input.tree_id);

    let nextPersonGedcom = await nextGedcomNumber(conn, 'genealogy_persons', input.tree_id, 'I');
    let nextFamilyGedcom = await nextGedcomNumber(conn, 'genealogy_families', input.tree_id, 'F');

    for (const source of input.sources ?? []) {
      const id = await upsertSource(conn, input.tree_id, source);
      if (source.key) summary.keys[source.key] = id;
      source.id ? summary.updated.sources++ : summary.inserted.sources++;
    }

    for (const media of input.media_updates ?? []) {
      await assertMediaInTree(conn, input.tree_id, media.id);
      const fields = pickDefined(media, [
        'media_type', 'analysis_status', 'enrichment_status', 'enrichment_error',
        'ai_description', 'transcription_text', 'transcription_source',
      ]);
      if (Object.keys(fields).length > 0) {
        if (fields.transcription_text !== undefined) fields.transcription = fields.transcription_text;
        if (fields.transcription_source !== undefined) fields.transcription_date = new Date();
        fields.rag_indexed_at = null;
        fields.updated_at = new Date();
        await conn.query('UPDATE genealogy_media SET ? WHERE tree_id = ? AND id = ?', [fields, input.tree_id, media.id]);
        summary.updated.media++;
      }
    }

    for (const person of input.persons ?? []) {
      const id = await upsertPerson(conn, input.tree_id, person, () => `I${nextPersonGedcom++}`);
      if (person.key) summary.keys[person.key] = id;
      person.id ? summary.updated.persons++ : summary.inserted.persons++;
    }

    for (const family of input.families ?? []) {
      const id = await upsertFamily(conn, input.tree_id, family, summary.keys, () => `F${nextFamilyGedcom++}`);
      if (family.key) summary.keys[family.key] = id;
      family.id ? summary.updated.families++ : summary.inserted.families++;
    }

    for (const link of input.children ?? []) {
      const familyId = resolveId(link.family_id, link.family_key, summary.keys, 'family');
      const personId = resolveId(link.person_id, link.person_key, summary.keys, 'person');
      await assertFamilyInTree(conn, input.tree_id, familyId);
      await assertPersonInTree(conn, input.tree_id, personId);
      if (await exists(conn, 'SELECT id FROM genealogy_children WHERE family_id = ? AND person_id = ?', [familyId, personId])) {
        const fields = pickDefined(link, ['birth_order', 'father_relationship', 'mother_relationship']);
        if (Object.keys(fields).length > 0) {
          await conn.query('UPDATE genealogy_children SET ? WHERE family_id = ? AND person_id = ?', [fields, familyId, personId]);
        }
      } else {
        await conn.query(
          `INSERT INTO genealogy_children
           (family_id, person_id, father_relationship, mother_relationship, birth_order, created_at)
           VALUES (?, ?, ?, ?, ?, NOW())`,
          [
            familyId,
            personId,
            link.father_relationship ?? 'Natural',
            link.mother_relationship ?? 'Natural',
            link.birth_order ?? null,
          ]
        );
        summary.inserted.children++;
      }
    }

    for (const link of input.person_media ?? []) {
      const personId = resolveId(link.person_id, link.person_key, summary.keys, 'person');
      await assertPersonInTree(conn, input.tree_id, personId);
      await assertMediaInTree(conn, input.tree_id, link.media_id);
      if (!(await exists(conn, 'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?', [personId, link.media_id]))) {
        await conn.query(
          `INSERT INTO genealogy_person_media
           (person_id, media_id, is_primary, face_confirmed, notes, created_at)
           VALUES (?, ?, 0, ?, ?, NOW())`,
          [personId, link.media_id, link.face_confirmed ? 1 : 0, link.notes ?? null]
        );
        summary.inserted.person_media++;
      }
    }

    for (const link of input.family_media ?? []) {
      const familyId = resolveId(link.family_id, link.family_key, summary.keys, 'family');
      await assertFamilyInTree(conn, input.tree_id, familyId);
      await assertMediaInTree(conn, input.tree_id, link.media_id);
      if (!(await exists(conn, 'SELECT id FROM genealogy_family_media WHERE family_id = ? AND media_id = ?', [familyId, link.media_id]))) {
        await conn.query(
          'INSERT INTO genealogy_family_media (family_id, media_id, created_at) VALUES (?, ?, NOW())',
          [familyId, link.media_id]
        );
        summary.inserted.family_media++;
      }
    }

    for (const citation of input.citations ?? []) {
      const sourceId = resolveId(citation.source_id, citation.source_key, summary.keys, 'source');
      const personId = citation.person_id || citation.person_key
        ? resolveId(citation.person_id, citation.person_key, summary.keys, 'person')
        : null;
      const familyId = citation.family_id || citation.family_key
        ? resolveId(citation.family_id, citation.family_key, summary.keys, 'family')
        : null;
      if (!personId && !familyId && !citation.media_id) {
        throw new Error(`Citation ${citation.fact_type} needs person_id, family_id, or media_id`);
      }
      await assertSourceInTree(conn, input.tree_id, sourceId);
      if (personId) await assertPersonInTree(conn, input.tree_id, personId);
      if (familyId) await assertFamilyInTree(conn, input.tree_id, familyId);
      if (citation.media_id) await assertMediaInTree(conn, input.tree_id, citation.media_id);

      if (!(await citationExists(conn, sourceId, citation.media_id ?? null, personId, familyId, citation.fact_type))) {
        await conn.query(
          `INSERT INTO genealogy_citations
           (source_id, person_id, family_id, media_id, fact_type, page, quality, evidence_type,
            information_type, evidence_analysis, text, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())`,
          [
            sourceId, personId, familyId, citation.media_id ?? null, citation.fact_type,
            citation.page ?? null, citation.quality ?? null, citation.evidence_type ?? null,
            citation.information_type ?? null, citation.evidence_analysis ?? null, citation.text ?? null,
          ]
        );
        summary.inserted.citations++;
      }
    }

    const touch = collectRagTouch(input, summary.keys);
    if (touch.personIds.length > 0) {
      await conn.query('UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN (?)', [input.tree_id, touch.personIds]);
      summary.touched_for_rag.persons = touch.personIds.length;
    }
    if (touch.sourceIds.length > 0) {
      await conn.query('UPDATE genealogy_sources SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN (?)', [input.tree_id, touch.sourceIds]);
      summary.touched_for_rag.sources = touch.sourceIds.length;
    }
    if (touch.mediaIds.length > 0) {
      await conn.query('UPDATE genealogy_media SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN (?)', [input.tree_id, touch.mediaIds]);
      summary.touched_for_rag.media = touch.mediaIds.length;
    }
  });

  return JSON.stringify(summary, null, 2);
}

function plannedCounts(input: GenealogyBatchApplyInput) {
  return {
    sources: input.sources?.length ?? 0,
    media_updates: input.media_updates?.length ?? 0,
    persons: input.persons?.length ?? 0,
    families: input.families?.length ?? 0,
    children: input.children?.length ?? 0,
    person_media: input.person_media?.length ?? 0,
    family_media: input.family_media?.length ?? 0,
    citations: input.citations?.length ?? 0,
  };
}

function pickDefined(source: Row, keys: string[]): Row {
  const picked: Row = {};
  for (const key of keys) {
    if (source[key] !== undefined) picked[key] = source[key];
  }
  return picked;
}

async function upsertSource(
  conn: mysql.PoolConnection,
  treeId: number,
  source: NonNullable<GenealogyBatchApplyInput['sources']>[number]
): Promise<number> {
  const now = new Date();
  const fields = pickDefined(source, [
    'author', 'publication', 'repository', 'notes', 'source_quality',
    'source_category', 'information_quality',
  ]);
  fields.classification_method = 'manual';
  fields.classified_at = now;
  fields.rag_indexed_at = null;
  fields.updated_at = now;

  if (source.id) {
    await assertSourceInTree(conn, treeId, source.id);
    if (source.title !== undefined) fields.title = source.title;
    await conn.query('UPDATE genealogy_sources SET ? WHERE tree_id = ? AND id = ?', [fields, treeId, source.id]);
    return source.id;
  }

  if (!source.title) throw new Error('New source requires title');
  const [existing] = await conn.query<mysql.RowDataPacket[]>(
    'SELECT id FROM genealogy_sources WHERE tree_id = ? AND title = ? LIMIT 1',
    [treeId, source.title]
  );
  if (existing.length > 0) {
    await conn.query('UPDATE genealogy_sources SET ? WHERE tree_id = ? AND id = ?', [fields, treeId, existing[0].id]);
    return existing[0].id as number;
  }

  const [result] = await conn.query<mysql.ResultSetHeader>(
    'INSERT INTO genealogy_sources SET ?',
    [{ ...fields, tree_id: treeId, title: source.title, created_at: now }]
  );
  return result.insertId;
}

async function upsertPerson(
  conn: mysql.PoolConnection,
  treeId: number,
  person: NonNullable<GenealogyBatchApplyInput['persons']>[number],
  nextGedcom: () => string
): Promise<number> {
  const fields = pickDefined(person, [
    'given_name', 'surname', 'sex', 'nickname', 'birth_date', 'birth_place',
    'death_date', 'death_place',
  ]);
  if (person.living !== undefined) fields.living = person.living ? 1 : 0;
  fields.rag_indexed_at = null;
  fields.updated_at = new Date();

  if (person.id) {
    await assertPersonInTree(conn, treeId, person.id);
    if (person.notes_append) await appendPersonNotes(conn, person.id, person.notes_append);
    if (Object.keys(fields).length > 0) {
      await conn.query('UPDATE genealogy_persons SET ? WHERE tree_id = ? AND id = ?', [fields, treeId, person.id]);
    }
    return person.id;
  }

  if (!person.given_name && !person.surname) throw new Error('New person requires given_name or surname');
  const [result] = await conn.query<mysql.ResultSetHeader>(
    'INSERT INTO genealogy_persons SET ?',
    [{
      tree_id: treeId,
      gedcom_id: nextGedcom(),
      uid: randomUUID(),
      given_name: person.given_name ?? '',
      surname: person.surname ?? '',
      sex: person.sex ?? null,
      nickname: person.nickname ?? '',
      birth_date: person.birth_date ?? '',
      birth_place: person.birth_place ?? '',
      death_date: person.death_date ?? '',
      death_place: person.death_place ?? '',
      notes: person.notes_append ?? '',
      living: person.living === undefined ? null : (person.living ? 1 : 0),
      rag_indexed_at: null,
      created_at: new Date(),
      updated_at: new Date(),
    }]
  );
  return result.insertId;
}

async function upsertFamily(
  conn: mysql.PoolConnection,
  treeId: number,
  family: NonNullable<GenealogyBatchApplyInput['families']>[number],
  keyMap: Record<string, number>,
  nextGedcom: () => string
): Promise<number> {
  const husbandSupplied = family.husband_id !== undefined || family.husband_key !== undefined;
  const wifeSupplied = family.wife_id !== undefined || family.wife_key !== undefined;
  const husbandId = husbandSupplied ? resolveId(family.husband_id, family.husband_key, keyMap, 'husband') : null;
  const wifeId = wifeSupplied ? resolveId(family.wife_id, family.wife_key, keyMap, 'wife') : null;

  if (husbandId) await assertPersonInTree(conn, treeId, husbandId);
  if (wifeId) await assertPersonInTree(conn, treeId, wifeId);

  const fields = pickDefined(family, ['marriage_date', 'marriage_place', 'notes']);
  if (husbandSupplied) fields.husband_id = husbandId;
  if (wifeSupplied) fields.wife_id = wifeId;
  fields.updated_at = new Date();

  if (family.id) {
    await assertFamilyInTree(conn, treeId, family.id);
    await conn.query('UPDATE genealogy_families SET ? WHERE tree_id = ? AND id = ?', [fields, treeId, family.id]);
    return family.id;
  }

  if (!husbandId && !wifeId) throw new Error('New family requires husband or wife reference');
  const [existing] = await conn.query<mysql.RowDataPacket[]>(
    'SELECT id FROM genealogy_families WHERE tree_id = ? AND husband_id <=> ? AND wife_id <=> ? LIMIT 1',
    [treeId, husbandId, wifeId]
  );
  if (existing.length > 0) {
    await conn.query('UPDATE genealogy_families SET ? WHERE tree_id = ? AND id = ?', [fields, treeId, existing[0].id]);
    return existing[0].id as number;
  }

  const [result] = await conn.query<mysql.ResultSetHeader>(
    'INSERT INTO genealogy_families SET ?',
    [{
      ...fields,
      tree_id: treeId,
      husband_id: husbandId,
      wife_id: wifeId,
      gedcom_id: nextGedcom(),
      uid: randomUUID(),
      created_at: new Date(),
    }]
  );
  return result.insertId;
}

async function appendPersonNotes(conn: mysql.PoolConnection, personId: number, note: string): Promise<void> {
  const [rows] = await conn.query<mysql.RowDataPacket[]>('SELECT notes FROM genealogy_persons WHERE id = ?', [personId]);
  const current = rows[0]?.notes ?? '';
  if (!String(current).includes(note)) {
    const next = [String(current).trim(), note].filter(Boolean).join(' ');
    await conn.query('UPDATE genealogy_persons SET notes = ?, rag_indexed_at = NULL, updated_at = NOW() WHERE id = ?', [next, personId]);
  }
}

function resolveId(
  id: number | undefined,
  key: string | undefined,
  keyMap: Record<string, number>,
  label: string
): number {
  if (id) return id;
  if (key && keyMap[key]) return keyMap[key];
  throw new Error(`Missing ${label} reference${key ? ` for key ${key}` : ''}`);
}

async function nextGedcomNumber(
  conn: mysql.PoolConnection,
  table: 'genealogy_persons' | 'genealogy_families',
  treeId: number,
  prefix: 'I' | 'F'
): Promise<number> {
  const [rows] = await conn.query<mysql.RowDataPacket[]>(
    `SELECT gedcom_id FROM ${table}
      WHERE tree_id = ? AND gedcom_id REGEXP ?
      ORDER BY CAST(SUBSTRING(gedcom_id, 2) AS UNSIGNED) DESC
      LIMIT 1`,
    [treeId, `^${prefix}[0-9]+$`]
  );
  const last = rows[0]?.gedcom_id ? Number(String(rows[0].gedcom_id).slice(1)) : 0;
  return last + 1;
}

function collectRagTouch(input: GenealogyBatchApplyInput, keyMap: Record<string, number>) {
  const personIds = new Set<number>();
  const sourceIds = new Set<number>();
  const mediaIds = new Set<number>();

  for (const person of input.persons ?? []) {
    if (person.id) personIds.add(person.id);
    else if (person.key && keyMap[person.key]) personIds.add(keyMap[person.key]);
  }
  for (const source of input.sources ?? []) {
    if (source.id) sourceIds.add(source.id);
    else if (source.key && keyMap[source.key]) sourceIds.add(keyMap[source.key]);
  }
  for (const media of input.media_updates ?? []) mediaIds.add(media.id);
  for (const link of input.person_media ?? []) {
    if (link.person_id) personIds.add(link.person_id);
    else if (link.person_key && keyMap[link.person_key]) personIds.add(keyMap[link.person_key]);
    mediaIds.add(link.media_id);
  }
  for (const link of input.family_media ?? []) mediaIds.add(link.media_id);
  for (const citation of input.citations ?? []) {
    if (citation.source_id) sourceIds.add(citation.source_id);
    else if (citation.source_key && keyMap[citation.source_key]) sourceIds.add(keyMap[citation.source_key]);
    if (citation.person_id) personIds.add(citation.person_id);
    else if (citation.person_key && keyMap[citation.person_key]) personIds.add(keyMap[citation.person_key]);
    if (citation.media_id) mediaIds.add(citation.media_id);
  }
  for (const id of input.rag_touch?.person_ids ?? []) personIds.add(id);
  for (const key of input.rag_touch?.person_keys ?? []) if (keyMap[key]) personIds.add(keyMap[key]);
  for (const id of input.rag_touch?.source_ids ?? []) sourceIds.add(id);
  for (const key of input.rag_touch?.source_keys ?? []) if (keyMap[key]) sourceIds.add(keyMap[key]);
  for (const id of input.rag_touch?.media_ids ?? []) mediaIds.add(id);

  return {
    personIds: [...personIds],
    sourceIds: [...sourceIds],
    mediaIds: [...mediaIds],
  };
}

async function citationExists(
  conn: mysql.PoolConnection,
  sourceId: number,
  mediaId: number | null,
  personId: number | null,
  familyId: number | null,
  factType: string
): Promise<boolean> {
  const [rows] = await conn.query<mysql.RowDataPacket[]>(
    `SELECT id FROM genealogy_citations
      WHERE source_id = ?
        AND media_id <=> ?
        AND person_id <=> ?
        AND family_id <=> ?
        AND fact_type = ?
      LIMIT 1`,
    [sourceId, mediaId, personId, familyId, factType]
  );
  return rows.length > 0;
}

async function exists(conn: mysql.PoolConnection, sql: string, params: unknown[]): Promise<boolean> {
  const [rows] = await conn.query<mysql.RowDataPacket[]>(sql, params);
  return rows.length > 0;
}

async function assertTreeExists(conn: mysql.PoolConnection, treeId: number): Promise<void> {
  if (!(await exists(conn, 'SELECT id FROM genealogy_trees WHERE id = ?', [treeId]))) {
    throw new Error(`Genealogy tree not found: ${treeId}`);
  }
}

async function assertPersonInTree(conn: mysql.PoolConnection, treeId: number, personId: number): Promise<void> {
  if (!(await exists(conn, 'SELECT id FROM genealogy_persons WHERE tree_id = ? AND id = ?', [treeId, personId]))) {
    throw new Error(`Person ${personId} is not in tree ${treeId}`);
  }
}

async function assertFamilyInTree(conn: mysql.PoolConnection, treeId: number, familyId: number): Promise<void> {
  if (!(await exists(conn, 'SELECT id FROM genealogy_families WHERE tree_id = ? AND id = ?', [treeId, familyId]))) {
    throw new Error(`Family ${familyId} is not in tree ${treeId}`);
  }
}

async function assertMediaInTree(conn: mysql.PoolConnection, treeId: number, mediaId: number): Promise<void> {
  if (!(await exists(conn, 'SELECT id FROM genealogy_media WHERE tree_id = ? AND id = ?', [treeId, mediaId]))) {
    throw new Error(`Media ${mediaId} is not in tree ${treeId}`);
  }
}

async function assertSourceInTree(conn: mysql.PoolConnection, treeId: number, sourceId: number): Promise<void> {
  if (!(await exists(conn, 'SELECT id FROM genealogy_sources WHERE tree_id = ? AND id = ?', [treeId, sourceId]))) {
    throw new Error(`Source ${sourceId} is not in tree ${treeId}`);
  }
}

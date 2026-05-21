import { randomUUID } from 'crypto';
import mysql from 'mysql2/promise';
import { DatabaseManager } from './database.js';

type Row = Record<string, any>;

type GenealogyContextArgs = {
  tree_id: number;
  person_ids?: number[];
  family_ids?: number[];
  media_ids?: number[];
  source_ids?: number[];
  text_limit?: number;
};

type GenealogyPersonGetArgs = {
  tree_id: number;
  person_id?: number;
  person_key?: string;
  text_limit?: number;
};

type GenealogyFamilyGetArgs = {
  tree_id: number;
  family_id?: number;
  family_key?: string;
  text_limit?: number;
};

type GenealogySourceGetArgs = {
  tree_id: number;
  source_id?: number;
  source_key?: string;
  text_limit?: number;
};

type GenealogySearchArgs = {
  tree_id: number;
  kind: 'person' | 'family' | 'source' | 'media';
  query: string;
  limit?: number;
  text_limit?: number;
};

type KeyRef = {
  id?: number;
  key?: string;
};

type GenealogyBatchArgs = {
  tree_id: number;
  dry_run?: boolean;
  confirm?: boolean;
  reason: string;
  sources?: Array<KeyRef & Partial<{
    title: string;
    author: string;
    publication: string;
    repository: string;
    notes: string;
    url: string;
    source_quality: 'original' | 'derivative' | 'authored';
    source_category: 'original' | 'derivative' | 'authored';
    information_quality: 'primary' | 'secondary' | 'undetermined';
  }>>;
  media_updates?: Array<{
    id: number;
    media_type?: 'photo' | 'document' | 'certificate' | 'census' | 'military' | 'obituary' | 'headstone' | 'video' | 'audio' | 'other';
    analysis_status?: 'pending' | 'processing' | 'completed' | 'failed' | 'skipped';
    enrichment_status?: 'pending' | 'processing' | 'completed' | 'failed' | 'skipped';
    enrichment_error?: string | null;
    ai_description?: string;
    transcription_text?: string;
    transcription_source?: 'manual' | 'ocr' | 'ai';
  }>;
  persons?: Array<KeyRef & Partial<{
    given_name: string;
    surname: string;
    sex: 'M' | 'F' | 'U';
    nickname: string;
    birth_date: string;
    birth_place: string;
    death_date: string;
    death_place: string;
    notes_append: string;
    living: boolean;
  }>>;
  families?: Array<KeyRef & Partial<{
    husband_id: number;
    husband_key: string;
    wife_id: number;
    wife_key: string;
    marriage_date: string;
    marriage_place: string;
    notes: string;
  }>>;
  children?: Array<{
    family_id?: number;
    family_key?: string;
    person_id?: number;
    person_key?: string;
    father_relationship?: 'Natural' | 'Adopted' | 'Step' | 'Foster' | 'Unknown';
    mother_relationship?: 'Natural' | 'Adopted' | 'Step' | 'Foster' | 'Unknown';
    birth_order?: number | null;
  }>;
  person_media?: Array<{
    person_id?: number;
    person_key?: string;
    media_id: number;
    notes?: string;
    face_confirmed?: boolean;
  }>;
  family_media?: Array<{
    family_id?: number;
    family_key?: string;
    media_id: number;
  }>;
  citations?: Array<{
    source_id?: number;
    source_key?: string;
    person_id?: number;
    person_key?: string;
    family_id?: number;
    family_key?: string;
    media_id?: number;
    fact_type: string;
    page?: string;
    quality?: number;
    evidence_type?: 'direct' | 'indirect' | 'negative';
    information_type?: 'primary' | 'secondary' | 'indeterminate';
    evidence_analysis?: string;
    text?: string;
  }>;
  rag_touch?: {
    person_ids?: number[];
    person_keys?: string[];
    source_ids?: number[];
    source_keys?: string[];
    media_ids?: number[];
  };
};

export class GenealogyService {
  constructor(private db: DatabaseManager) {}

  async getPerson(args: GenealogyPersonGetArgs) {
    const person = await this.resolvePerson(args.tree_id, args.person_id, args.person_key);
    const limit = this.textLimit(args.text_limit);

    const spouseFamilies = await this.db.query<Row[]>(
      `SELECT f.id, f.gedcom_id, f.husband_id,
              TRIM(CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,''))) AS husband,
              f.wife_id,
              TRIM(CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,''))) AS wife,
              f.marriage_date, f.marriage_place, LEFT(COALESCE(f.notes,''), ?) AS notes
         FROM genealogy_families f
         LEFT JOIN genealogy_persons h ON h.id = f.husband_id
         LEFT JOIN genealogy_persons w ON w.id = f.wife_id
        WHERE f.tree_id = ? AND (f.husband_id = ? OR f.wife_id = ?)
        ORDER BY f.id`,
      [limit, args.tree_id, person.id, person.id]
    );

    const parentFamilies = await this.db.query<Row[]>(
      `SELECT f.id, f.gedcom_id, f.husband_id,
              TRIM(CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,''))) AS father,
              f.wife_id,
              TRIM(CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,''))) AS mother,
              c.father_relationship, c.mother_relationship, c.birth_order,
              f.marriage_date, f.marriage_place
         FROM genealogy_children c
         JOIN genealogy_families f ON f.id = c.family_id
         LEFT JOIN genealogy_persons h ON h.id = f.husband_id
         LEFT JOIN genealogy_persons w ON w.id = f.wife_id
        WHERE f.tree_id = ? AND c.person_id = ?
        ORDER BY f.id`,
      [args.tree_id, person.id]
    );

    const familyIds = [...new Set([
      ...spouseFamilies.map((row) => row.id as number),
      ...parentFamilies.map((row) => row.id as number),
    ])];

    const children = familyIds.length
      ? await this.db.query<Row[]>(
          `SELECT c.family_id, c.person_id,
                  TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.surname,''))) AS child,
                  p.birth_date, p.death_date, p.living,
                  c.father_relationship, c.mother_relationship, c.birth_order
             FROM genealogy_children c
             JOIN genealogy_persons p ON p.id = c.person_id
             JOIN genealogy_families f ON f.id = c.family_id
            WHERE f.tree_id = ? AND c.family_id IN (?)
            ORDER BY c.family_id, c.birth_order, c.id`,
          [args.tree_id, familyIds]
        )
      : [];

    const media = await this.personMedia(args.tree_id, [person.id], familyIds, limit);
    const sources = await this.personSourceLinks(args.tree_id, [person.id], familyIds, limit);
    const citations = await this.citationLinks(args.tree_id, [person.id], familyIds, [], [], limit);

    return {
      tree_id: args.tree_id,
      person,
      spouse_families: spouseFamilies,
      parent_families: parentFamilies,
      children,
      media,
      sources,
      citations,
    };
  }

  async getFamily(args: GenealogyFamilyGetArgs) {
    const family = await this.resolveFamily(args.tree_id, args.family_id, args.family_key);
    const limit = this.textLimit(args.text_limit);
    const personIds = [family.husband_id, family.wife_id].filter(Boolean) as number[];

    const children = await this.db.query<Row[]>(
      `SELECT c.family_id, c.person_id,
              TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.surname,''))) AS child,
              p.birth_date, p.death_date, p.living,
              c.father_relationship, c.mother_relationship, c.birth_order
         FROM genealogy_children c
         JOIN genealogy_persons p ON p.id = c.person_id
        WHERE c.family_id = ?
        ORDER BY c.birth_order, c.id`,
      [family.id]
    );

    for (const row of children) {
      if (row.person_id) personIds.push(row.person_id as number);
    }

    const media = await this.personMedia(args.tree_id, [...new Set(personIds)], [family.id], limit);
    const sources = await this.personSourceLinks(args.tree_id, [...new Set(personIds)], [family.id], limit);
    const citations = await this.citationLinks(args.tree_id, [...new Set(personIds)], [family.id], [], [], limit);

    return {
      tree_id: args.tree_id,
      family,
      children,
      media,
      sources,
      citations,
    };
  }

  async getSource(args: GenealogySourceGetArgs) {
    const source = await this.resolveSource(args.tree_id, args.source_id, args.source_key);
    const limit = this.textLimit(args.text_limit);
    const citations = await this.citationLinks(args.tree_id, [], [], [], [source.id], limit);

    const personLinks = await this.db.query<Row[]>(
      `SELECT ps.id, ps.person_id,
              TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.surname,''))) AS person,
              ps.page, ps.quality
         FROM genealogy_person_sources ps
         JOIN genealogy_persons p ON p.id = ps.person_id
        WHERE p.tree_id = ? AND ps.source_id = ?
        ORDER BY ps.id DESC
        LIMIT 200`,
      [args.tree_id, source.id]
    );

    const familyLinks = await this.db.query<Row[]>(
      `SELECT fs.id, fs.family_id,
              TRIM(CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,''))) AS husband,
              TRIM(CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,''))) AS wife,
              fs.page, fs.quality
         FROM genealogy_family_sources fs
         JOIN genealogy_families f ON f.id = fs.family_id
         LEFT JOIN genealogy_persons h ON h.id = f.husband_id
         LEFT JOIN genealogy_persons w ON w.id = f.wife_id
        WHERE f.tree_id = ? AND fs.source_id = ?
        ORDER BY fs.id DESC
        LIMIT 200`,
      [args.tree_id, source.id]
    );

    return {
      tree_id: args.tree_id,
      source,
      person_links: personLinks,
      family_links: familyLinks,
      citations,
    };
  }

  async search(args: GenealogySearchArgs) {
    const limit = Math.max(1, Math.min(args.limit ?? 25, 100));
    const textLimit = this.textLimit(args.text_limit);
    const like = `%${this.escapeLike(args.query.trim())}%`;

    if (args.kind === 'person') {
      return {
        tree_id: args.tree_id,
        kind: args.kind,
        results: await this.db.query<Row[]>(
          `SELECT id, gedcom_id, given_name, surname, nickname, sex, birth_date,
                  death_date, living, LEFT(COALESCE(notes,''), ?) AS notes
             FROM genealogy_persons
            WHERE tree_id = ?
              AND (gedcom_id LIKE ? ESCAPE '\\\\'
                   OR given_name LIKE ? ESCAPE '\\\\'
                   OR surname LIKE ? ESCAPE '\\\\'
                   OR nickname LIKE ? ESCAPE '\\\\'
                   OR CONCAT(COALESCE(given_name,''),' ',COALESCE(surname,'')) LIKE ? ESCAPE '\\\\')
            ORDER BY surname, given_name, id
            LIMIT ?`,
          [textLimit, args.tree_id, like, like, like, like, like, limit]
        ),
      };
    }

    if (args.kind === 'family') {
      return {
        tree_id: args.tree_id,
        kind: args.kind,
        results: await this.db.query<Row[]>(
          `SELECT f.id, f.gedcom_id, f.husband_id,
                  TRIM(CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,''))) AS husband,
                  f.wife_id,
                  TRIM(CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,''))) AS wife,
                  f.marriage_date, f.marriage_place, LEFT(COALESCE(f.notes,''), ?) AS notes
             FROM genealogy_families f
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
            WHERE f.tree_id = ?
              AND (f.gedcom_id LIKE ? ESCAPE '\\\\'
                   OR f.marriage_place LIKE ? ESCAPE '\\\\'
                   OR CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,'')) LIKE ? ESCAPE '\\\\'
                   OR CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,'')) LIKE ? ESCAPE '\\\\')
            ORDER BY f.id
            LIMIT ?`,
          [textLimit, args.tree_id, like, like, like, like, limit]
        ),
      };
    }

    if (args.kind === 'source') {
      return {
        tree_id: args.tree_id,
        kind: args.kind,
        results: await this.db.query<Row[]>(
          `SELECT id, gedcom_id, title, author, publication, repository, url,
                  source_quality, source_category, information_quality,
                  LEFT(COALESCE(notes,''), ?) AS notes
             FROM genealogy_sources
            WHERE tree_id = ?
              AND (gedcom_id LIKE ? ESCAPE '\\\\'
                   OR title LIKE ? ESCAPE '\\\\'
                   OR author LIKE ? ESCAPE '\\\\'
                   OR publication LIKE ? ESCAPE '\\\\'
                   OR repository LIKE ? ESCAPE '\\\\'
                   OR url LIKE ? ESCAPE '\\\\')
            ORDER BY id DESC
            LIMIT ?`,
          [textLimit, args.tree_id, like, like, like, like, like, like, limit]
        ),
      };
    }

    return {
      tree_id: args.tree_id,
      kind: args.kind,
      results: await this.db.query<Row[]>(
        `SELECT id, gedcom_id, title, media_type, mime_type, local_filename,
                nextcloud_path, original_path, analysis_status, enrichment_status,
                rag_indexed_at, LEFT(COALESCE(transcription_text, transcription, description, ai_description, ''), ?) AS text
           FROM genealogy_media
          WHERE tree_id = ?
            AND (gedcom_id LIKE ? ESCAPE '\\\\'
                 OR title LIKE ? ESCAPE '\\\\'
                 OR local_filename LIKE ? ESCAPE '\\\\'
                 OR nextcloud_path LIKE ? ESCAPE '\\\\'
                 OR original_path LIKE ? ESCAPE '\\\\'
                 OR description LIKE ? ESCAPE '\\\\'
                 OR ai_description LIKE ? ESCAPE '\\\\'
                 OR transcription_text LIKE ? ESCAPE '\\\\'
                 OR transcription LIKE ? ESCAPE '\\\\')
          ORDER BY id DESC
          LIMIT ?`,
        [textLimit, args.tree_id, like, like, like, like, like, like, like, like, like, limit]
      ),
    };
  }

  async treeStats(args: { tree_id: number }) {
    const [people] = await this.db.query<Row[]>(
      `SELECT COUNT(*) AS total,
              SUM(CASE WHEN living = 1 THEN 1 ELSE 0 END) AS living,
              SUM(CASE WHEN living = 0 THEN 1 ELSE 0 END) AS deceased,
              SUM(CASE WHEN living IS NULL THEN 1 ELSE 0 END) AS living_unknown,
              SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) AS rag_pending
         FROM genealogy_persons
        WHERE tree_id = ?`,
      [args.tree_id]
    );

    const [families] = await this.db.query<Row[]>(
      `SELECT COUNT(*) AS total
         FROM genealogy_families
        WHERE tree_id = ?`,
      [args.tree_id]
    );

    const [children] = await this.db.query<Row[]>(
      `SELECT COUNT(*) AS total
         FROM genealogy_children c
         JOIN genealogy_families f ON f.id = c.family_id
        WHERE f.tree_id = ?`,
      [args.tree_id]
    );

    const [sources] = await this.db.query<Row[]>(
      `SELECT COUNT(*) AS total,
              SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) AS rag_pending
         FROM genealogy_sources
        WHERE tree_id = ?`,
      [args.tree_id]
    );

    const [media] = await this.db.query<Row[]>(
      `SELECT COUNT(*) AS total,
              SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) AS rag_pending,
              SUM(CASE WHEN local_filename IS NULL AND nextcloud_path IS NULL THEN 1 ELSE 0 END) AS path_missing
         FROM genealogy_media
        WHERE tree_id = ?`,
      [args.tree_id]
    );

    const [citations] = await this.db.query<Row[]>(
      `SELECT COUNT(*) AS total
         FROM genealogy_citations c
         JOIN genealogy_sources s ON s.id = c.source_id
        WHERE s.tree_id = ?`,
      [args.tree_id]
    );

    return {
      tree_id: args.tree_id,
      people,
      families,
      children,
      sources,
      media,
      citations,
    };
  }

  async compactContext(args: GenealogyContextArgs) {
    const limit = Math.max(0, Math.min(args.text_limit ?? 1200, 5000));
    const result: Record<string, unknown> = { tree_id: args.tree_id };

    if (args.person_ids?.length) {
      result.people = await this.db.query<Row[]>(
        `SELECT id, gedcom_id, given_name, surname, nickname, sex, birth_date, birth_place,
                death_date, death_place, living, LEFT(COALESCE(notes,''), ?) AS notes
           FROM genealogy_persons
          WHERE tree_id = ? AND id IN (?)
          ORDER BY id`,
        [limit, args.tree_id, args.person_ids]
      );
    }

    if (args.family_ids?.length) {
      result.families = await this.db.query<Row[]>(
        `SELECT f.id, f.gedcom_id, f.husband_id,
                TRIM(CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,''))) AS husband,
                f.wife_id,
                TRIM(CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,''))) AS wife,
                f.marriage_date, f.marriage_place, LEFT(COALESCE(f.notes,''), ?) AS notes
           FROM genealogy_families f
           LEFT JOIN genealogy_persons h ON h.id = f.husband_id
           LEFT JOIN genealogy_persons w ON w.id = f.wife_id
          WHERE f.tree_id = ? AND f.id IN (?)
          ORDER BY f.id`,
        [limit, args.tree_id, args.family_ids]
      );

      result.children = await this.db.query<Row[]>(
        `SELECT c.family_id, c.person_id,
                TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.surname,''))) AS child,
                p.birth_date, c.birth_order
           FROM genealogy_children c
           JOIN genealogy_families f ON f.id = c.family_id
           JOIN genealogy_persons p ON p.id = c.person_id
          WHERE f.tree_id = ? AND c.family_id IN (?)
          ORDER BY c.family_id, c.birth_order, c.id`,
        [args.tree_id, args.family_ids]
      );
    }

    if (args.media_ids?.length) {
      result.media = await this.db.query<Row[]>(
        `SELECT id, title, media_type, mime_type, local_filename, nextcloud_path,
                analysis_status, enrichment_status, rag_indexed_at,
                LEFT(COALESCE(transcription_text, transcription, description, ai_description, ''), ?) AS text
           FROM genealogy_media
          WHERE tree_id = ? AND id IN (?)
          ORDER BY id`,
        [limit, args.tree_id, args.media_ids]
      );

      result.media_links = await this.db.query<Row[]>(
        `SELECT 'person' AS link_type, pm.media_id, pm.person_id AS entity_id,
                TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.surname,''))) AS entity_name,
                pm.notes
           FROM genealogy_person_media pm
           JOIN genealogy_media m ON m.id = pm.media_id
           JOIN genealogy_persons p ON p.id = pm.person_id
          WHERE m.tree_id = ? AND pm.media_id IN (?)
          UNION ALL
         SELECT 'family' AS link_type, fm.media_id, fm.family_id AS entity_id,
                CONCAT('family ', fm.family_id) AS entity_name, NULL AS notes
           FROM genealogy_family_media fm
           JOIN genealogy_media m ON m.id = fm.media_id
          WHERE m.tree_id = ? AND fm.media_id IN (?)
          ORDER BY media_id, link_type, entity_id`,
        [args.tree_id, args.media_ids, args.tree_id, args.media_ids]
      );
    }

    const citationClauses: string[] = [];
    const citationParams: unknown[] = [];
    if (args.person_ids?.length) {
      citationClauses.push('c.person_id IN (?)');
      citationParams.push(args.person_ids);
    }
    if (args.family_ids?.length) {
      citationClauses.push('c.family_id IN (?)');
      citationParams.push(args.family_ids);
    }
    if (args.media_ids?.length) {
      citationClauses.push('c.media_id IN (?)');
      citationParams.push(args.media_ids);
    }
    if (args.source_ids?.length) {
      citationClauses.push('c.source_id IN (?)');
      citationParams.push(args.source_ids);
    }
    if (citationClauses.length) {
      result.citations = await this.db.query<Row[]>(
        `SELECT c.id, c.source_id, s.title AS source_title, c.person_id, c.family_id,
                c.media_id, c.fact_type, c.quality, c.evidence_type, c.information_type,
                LEFT(COALESCE(c.text,''), ?) AS text
           FROM genealogy_citations c
           JOIN genealogy_sources s ON s.id = c.source_id
          WHERE s.tree_id = ? AND (${citationClauses.join(' OR ')})
          ORDER BY c.id DESC
          LIMIT 200`,
        [limit, args.tree_id, ...citationParams]
      );
    }

    if (args.source_ids?.length) {
      result.sources = await this.db.query<Row[]>(
        `SELECT id, title, author, publication, repository, source_quality,
                source_category, information_quality, LEFT(COALESCE(notes,''), ?) AS notes
           FROM genealogy_sources
          WHERE tree_id = ? AND id IN (?)
          ORDER BY id`,
        [limit, args.tree_id, args.source_ids]
      );
    }

    return result;
  }

  async applyBatch(args: GenealogyBatchArgs) {
    const dryRun = args.dry_run ?? true;
    const summary = {
      dry_run: dryRun,
      tree_id: args.tree_id,
      reason: args.reason,
      inserted: { sources: 0, persons: 0, families: 0, children: 0, citations: 0, person_media: 0, family_media: 0 },
      updated: { sources: 0, persons: 0, families: 0, media: 0 },
      touched_for_rag: { persons: 0, sources: 0, media: 0 },
      keys: {} as Record<string, number>,
    };

    if (dryRun) {
      return {
        ...summary,
        planned: this.plannedCounts(args),
      };
    }

    if (!args.confirm) {
      throw new Error('genealogy_batch_apply requires confirm=true when dry_run=false');
    }

    await this.db.transaction(async (conn) => {
      await this.assertTreeExists(conn, args.tree_id);
      const keyMap = summary.keys;
      let nextPersonGedcom = await this.nextGedcomNumber(conn, 'genealogy_persons', args.tree_id, 'I');
      let nextFamilyGedcom = await this.nextGedcomNumber(conn, 'genealogy_families', args.tree_id, 'F');

      for (const source of args.sources ?? []) {
        const id = await this.upsertSource(conn, args.tree_id, source);
        if (source.key) keyMap[source.key] = id;
        source.id ? summary.updated.sources++ : summary.inserted.sources++;
      }

      for (const media of args.media_updates ?? []) {
        await this.assertMediaInTree(conn, args.tree_id, media.id);
        const fields = this.pickDefined(media, [
          'media_type', 'analysis_status', 'enrichment_status', 'enrichment_error',
          'ai_description', 'transcription_text', 'transcription_source',
        ]);
        if (Object.keys(fields).length) {
          if (fields.transcription_text !== undefined) fields.transcription = fields.transcription_text;
          if (fields.transcription_source !== undefined) fields.transcription_date = new Date();
          fields.rag_indexed_at = null;
          fields.updated_at = new Date();
          await conn.query('UPDATE genealogy_media SET ? WHERE id = ?', [fields, media.id]);
          summary.updated.media++;
        }
      }

      for (const person of args.persons ?? []) {
        const id = await this.upsertPerson(conn, args.tree_id, person, () => `I${nextPersonGedcom++}`);
        if (person.key) keyMap[person.key] = id;
        person.id ? summary.updated.persons++ : summary.inserted.persons++;
      }

      for (const family of args.families ?? []) {
        const id = await this.upsertFamily(conn, args.tree_id, family, keyMap, () => `F${nextFamilyGedcom++}`);
        if (family.key) keyMap[family.key] = id;
        family.id ? summary.updated.families++ : summary.inserted.families++;
      }

      for (const link of args.children ?? []) {
        const familyId = this.resolveId(link.family_id, link.family_key, keyMap, 'family');
        const personId = this.resolveId(link.person_id, link.person_key, keyMap, 'person');
        await this.assertFamilyInTree(conn, args.tree_id, familyId);
        await this.assertPersonInTree(conn, args.tree_id, personId);
        const exists = await this.exists(conn, 'SELECT id FROM genealogy_children WHERE family_id = ? AND person_id = ?', [familyId, personId]);
        if (exists) {
          const fields = this.pickDefined(link, ['birth_order', 'father_relationship', 'mother_relationship']);
          if (Object.keys(fields).length) {
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

      for (const link of args.person_media ?? []) {
        const personId = this.resolveId(link.person_id, link.person_key, keyMap, 'person');
        await this.assertPersonInTree(conn, args.tree_id, personId);
        await this.assertMediaInTree(conn, args.tree_id, link.media_id);
        const exists = await this.exists(conn, 'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?', [personId, link.media_id]);
        if (!exists) {
          await conn.query(
            `INSERT INTO genealogy_person_media
             (person_id, media_id, is_primary, face_confirmed, notes, created_at)
             VALUES (?, ?, 0, ?, ?, NOW())`,
            [personId, link.media_id, link.face_confirmed ? 1 : 0, link.notes ?? null]
          );
          summary.inserted.person_media++;
        }
      }

      for (const link of args.family_media ?? []) {
        const familyId = this.resolveId(link.family_id, link.family_key, keyMap, 'family');
        await this.assertFamilyInTree(conn, args.tree_id, familyId);
        await this.assertMediaInTree(conn, args.tree_id, link.media_id);
        const exists = await this.exists(conn, 'SELECT id FROM genealogy_family_media WHERE family_id = ? AND media_id = ?', [familyId, link.media_id]);
        if (!exists) {
          await conn.query(
            'INSERT INTO genealogy_family_media (family_id, media_id, created_at) VALUES (?, ?, NOW())',
            [familyId, link.media_id]
          );
          summary.inserted.family_media++;
        }
      }

      for (const citation of args.citations ?? []) {
        const sourceId = this.resolveId(citation.source_id, citation.source_key, keyMap, 'source');
        const personId = citation.person_id || citation.person_key
          ? this.resolveId(citation.person_id, citation.person_key, keyMap, 'person')
          : null;
        const familyId = citation.family_id || citation.family_key
          ? this.resolveId(citation.family_id, citation.family_key, keyMap, 'family')
          : null;
        if (!personId && !familyId && !citation.media_id) {
          throw new Error(`Citation ${citation.fact_type} needs person_id, family_id, or media_id`);
        }
        await this.assertSourceInTree(conn, args.tree_id, sourceId);
        if (personId) await this.assertPersonInTree(conn, args.tree_id, personId);
        if (familyId) await this.assertFamilyInTree(conn, args.tree_id, familyId);
        if (citation.media_id) await this.assertMediaInTree(conn, args.tree_id, citation.media_id);

        const exists = await this.citationExists(conn, sourceId, citation.media_id ?? null, personId, familyId, citation.fact_type);
        if (!exists) {
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

      const touch = this.collectRagTouch(args, keyMap);
      if (touch.personIds.length) {
        await conn.query('UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN (?)', [args.tree_id, touch.personIds]);
        summary.touched_for_rag.persons = touch.personIds.length;
      }
      if (touch.sourceIds.length) {
        await conn.query('UPDATE genealogy_sources SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN (?)', [args.tree_id, touch.sourceIds]);
        summary.touched_for_rag.sources = touch.sourceIds.length;
      }
      if (touch.mediaIds.length) {
        await conn.query('UPDATE genealogy_media SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN (?)', [args.tree_id, touch.mediaIds]);
        summary.touched_for_rag.media = touch.mediaIds.length;
      }
    });

    return summary;
  }

  private plannedCounts(args: GenealogyBatchArgs) {
    return {
      sources: args.sources?.length ?? 0,
      media_updates: args.media_updates?.length ?? 0,
      persons: args.persons?.length ?? 0,
      families: args.families?.length ?? 0,
      children: args.children?.length ?? 0,
      person_media: args.person_media?.length ?? 0,
      family_media: args.family_media?.length ?? 0,
      citations: args.citations?.length ?? 0,
    };
  }

  private pickDefined(source: Row, keys: string[]) {
    const picked: Row = {};
    for (const key of keys) {
      if (source[key] !== undefined) picked[key] = source[key];
    }
    return picked;
  }

  private async upsertSource(conn: mysql.PoolConnection, treeId: number, source: NonNullable<GenealogyBatchArgs['sources']>[number]) {
    const now = new Date();
    const fields = this.pickDefined(source, [
      'author', 'publication', 'repository', 'url', 'notes', 'source_quality',
      'source_category', 'information_quality',
    ]);
    fields.classification_method = 'manual';
    fields.classified_at = now;
    fields.rag_indexed_at = null;
    fields.updated_at = now;

    if (source.id) {
      await this.assertSourceInTree(conn, treeId, source.id);
      if (source.title !== undefined) fields.title = source.title;
      await conn.query('UPDATE genealogy_sources SET ? WHERE id = ?', [fields, source.id]);
      return source.id;
    }

    if (!source.title) throw new Error('New source requires title');
    const [existing] = await conn.query<mysql.RowDataPacket[]>(
      'SELECT id FROM genealogy_sources WHERE tree_id = ? AND title = ? LIMIT 1',
      [treeId, source.title]
    );
    if (existing.length) {
      await conn.query('UPDATE genealogy_sources SET ? WHERE id = ?', [fields, existing[0].id]);
      return existing[0].id as number;
    }

    const [result] = await conn.query<mysql.ResultSetHeader>(
      'INSERT INTO genealogy_sources SET ?',
      [{ ...fields, tree_id: treeId, title: source.title, created_at: now }]
    );
    return result.insertId;
  }

  private async upsertPerson(
    conn: mysql.PoolConnection,
    treeId: number,
    person: NonNullable<GenealogyBatchArgs['persons']>[number],
    nextGedcom: () => string
  ) {
    const fields = this.pickDefined(person, [
      'given_name', 'surname', 'sex', 'nickname', 'birth_date', 'birth_place',
      'death_date', 'death_place',
    ]);
    if (person.living !== undefined) fields.living = person.living ? 1 : 0;
    fields.rag_indexed_at = null;
    fields.updated_at = new Date();

    if (person.id) {
      await this.assertPersonInTree(conn, treeId, person.id);
      if (person.notes_append) await this.appendPersonNotes(conn, person.id, person.notes_append);
      if (Object.keys(fields).length) await conn.query('UPDATE genealogy_persons SET ? WHERE id = ?', [fields, person.id]);
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
        created_at: new Date(),
        updated_at: new Date(),
      }]
    );
    return result.insertId;
  }

  private async upsertFamily(
    conn: mysql.PoolConnection,
    treeId: number,
    family: NonNullable<GenealogyBatchArgs['families']>[number],
    keyMap: Record<string, number>,
    nextGedcom: () => string
  ) {
    const husbandId = family.husband_id || (family.husband_key ? keyMap[family.husband_key] : null);
    const wifeId = family.wife_id || (family.wife_key ? keyMap[family.wife_key] : null);
    if (husbandId) await this.assertPersonInTree(conn, treeId, husbandId);
    if (wifeId) await this.assertPersonInTree(conn, treeId, wifeId);

    const fields = this.pickDefined(family, ['marriage_date', 'marriage_place', 'notes']);
    fields.husband_id = husbandId ?? null;
    fields.wife_id = wifeId ?? null;
    fields.updated_at = new Date();

    if (family.id) {
      await this.assertFamilyInTree(conn, treeId, family.id);
      await conn.query('UPDATE genealogy_families SET ? WHERE id = ?', [fields, family.id]);
      return family.id;
    }

    const [existing] = await conn.query<mysql.RowDataPacket[]>(
      'SELECT id FROM genealogy_families WHERE tree_id = ? AND husband_id <=> ? AND wife_id <=> ? LIMIT 1',
      [treeId, husbandId ?? null, wifeId ?? null]
    );
    if (existing.length) {
      if (Object.keys(fields).length) await conn.query('UPDATE genealogy_families SET ? WHERE id = ?', [fields, existing[0].id]);
      return existing[0].id as number;
    }

    const [result] = await conn.query<mysql.ResultSetHeader>(
      'INSERT INTO genealogy_families SET ?',
      [{ ...fields, tree_id: treeId, gedcom_id: nextGedcom(), created_at: new Date() }]
    );
    return result.insertId;
  }

  private async appendPersonNotes(conn: mysql.PoolConnection, personId: number, note: string) {
    const [rows] = await conn.query<mysql.RowDataPacket[]>('SELECT notes FROM genealogy_persons WHERE id = ?', [personId]);
    const current = rows[0]?.notes ?? '';
    if (!current.includes(note)) {
      const next = [current.trim(), note].filter(Boolean).join(' ');
      await conn.query('UPDATE genealogy_persons SET notes = ?, rag_indexed_at = NULL, updated_at = NOW() WHERE id = ?', [next, personId]);
    }
  }

  private resolveId(id: number | undefined, key: string | undefined, keyMap: Record<string, number>, label: string) {
    if (id) return id;
    if (key && keyMap[key]) return keyMap[key];
    throw new Error(`Missing ${label} reference${key ? ` for key ${key}` : ''}`);
  }

  private async nextGedcomNumber(conn: mysql.PoolConnection, table: string, treeId: number, prefix: 'I' | 'F') {
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

  private collectRagTouch(args: GenealogyBatchArgs, keyMap: Record<string, number>) {
    const personIds = new Set<number>();
    const sourceIds = new Set<number>();
    const mediaIds = new Set<number>();
    for (const person of args.persons ?? []) if (person.id) personIds.add(person.id); else if (person.key && keyMap[person.key]) personIds.add(keyMap[person.key]);
    for (const source of args.sources ?? []) if (source.id) sourceIds.add(source.id); else if (source.key && keyMap[source.key]) sourceIds.add(keyMap[source.key]);
    for (const media of args.media_updates ?? []) mediaIds.add(media.id);
    for (const link of args.person_media ?? []) { if (link.person_id) personIds.add(link.person_id); else if (link.person_key && keyMap[link.person_key]) personIds.add(keyMap[link.person_key]); mediaIds.add(link.media_id); }
    for (const link of args.family_media ?? []) mediaIds.add(link.media_id);
    for (const citation of args.citations ?? []) {
      if (citation.source_id) sourceIds.add(citation.source_id); else if (citation.source_key && keyMap[citation.source_key]) sourceIds.add(keyMap[citation.source_key]);
      if (citation.person_id) personIds.add(citation.person_id); else if (citation.person_key && keyMap[citation.person_key]) personIds.add(keyMap[citation.person_key]);
      if (citation.media_id) mediaIds.add(citation.media_id);
    }
    for (const id of args.rag_touch?.person_ids ?? []) personIds.add(id);
    for (const key of args.rag_touch?.person_keys ?? []) if (keyMap[key]) personIds.add(keyMap[key]);
    for (const id of args.rag_touch?.source_ids ?? []) sourceIds.add(id);
    for (const key of args.rag_touch?.source_keys ?? []) if (keyMap[key]) sourceIds.add(keyMap[key]);
    for (const id of args.rag_touch?.media_ids ?? []) mediaIds.add(id);
    return {
      personIds: [...personIds],
      sourceIds: [...sourceIds],
      mediaIds: [...mediaIds],
    };
  }

  private async citationExists(
    conn: mysql.PoolConnection,
    sourceId: number,
    mediaId: number | null,
    personId: number | null,
    familyId: number | null,
    factType: string
  ) {
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

  private async exists(conn: mysql.PoolConnection, sql: string, params: unknown[]) {
    const [rows] = await conn.query<mysql.RowDataPacket[]>(sql, params);
    return rows.length > 0;
  }

  private textLimit(limit?: number) {
    return Math.max(0, Math.min(limit ?? 1200, 5000));
  }

  private escapeLike(value: string) {
    return value.replace(/[\\%_]/g, (match) => `\\${match}`);
  }

  private async resolvePerson(treeId: number, id?: number, key?: string) {
    const rows = id
      ? await this.db.query<Row[]>(
          `SELECT id, gedcom_id, given_name, surname, nickname, sex, birth_date, birth_place,
                  death_date, death_place, living, LEFT(COALESCE(notes,''), 5000) AS notes,
                  rag_indexed_at, updated_at
             FROM genealogy_persons
            WHERE tree_id = ? AND id = ?
            LIMIT 1`,
          [treeId, id]
        )
      : await this.db.query<Row[]>(
          `SELECT id, gedcom_id, given_name, surname, nickname, sex, birth_date, birth_place,
                  death_date, death_place, living, LEFT(COALESCE(notes,''), 5000) AS notes,
                  rag_indexed_at, updated_at
             FROM genealogy_persons
            WHERE tree_id = ? AND gedcom_id = ?
            LIMIT 1`,
          [treeId, key]
        );
    if (!rows.length) throw new Error(`Person not found in tree ${treeId}`);
    return rows[0];
  }

  private async resolveFamily(treeId: number, id?: number, key?: string) {
    const rows = id
      ? await this.db.query<Row[]>(
          `SELECT f.id, f.gedcom_id, f.husband_id,
                  TRIM(CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,''))) AS husband,
                  f.wife_id,
                  TRIM(CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,''))) AS wife,
                  f.marriage_date, f.marriage_place, LEFT(COALESCE(f.notes,''), 5000) AS notes,
                  f.updated_at
             FROM genealogy_families f
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
            WHERE f.tree_id = ? AND f.id = ?
            LIMIT 1`,
          [treeId, id]
        )
      : await this.db.query<Row[]>(
          `SELECT f.id, f.gedcom_id, f.husband_id,
                  TRIM(CONCAT(COALESCE(h.given_name,''),' ',COALESCE(h.surname,''))) AS husband,
                  f.wife_id,
                  TRIM(CONCAT(COALESCE(w.given_name,''),' ',COALESCE(w.surname,''))) AS wife,
                  f.marriage_date, f.marriage_place, LEFT(COALESCE(f.notes,''), 5000) AS notes,
                  f.updated_at
             FROM genealogy_families f
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
            WHERE f.tree_id = ? AND f.gedcom_id = ?
            LIMIT 1`,
          [treeId, key]
        );
    if (!rows.length) throw new Error(`Family not found in tree ${treeId}`);
    return rows[0];
  }

  private async resolveSource(treeId: number, id?: number, key?: string) {
    const rows = id
      ? await this.db.query<Row[]>(
          `SELECT id, gedcom_id, title, author, publication, repository, url,
                  source_quality, source_category, information_quality,
                  classification_method, classification_confidence, classification_notes,
                  classified_at, rag_indexed_at, LEFT(COALESCE(notes,''), 5000) AS notes,
                  updated_at
             FROM genealogy_sources
            WHERE tree_id = ? AND id = ?
            LIMIT 1`,
          [treeId, id]
        )
      : await this.db.query<Row[]>(
          `SELECT id, gedcom_id, title, author, publication, repository, url,
                  source_quality, source_category, information_quality,
                  classification_method, classification_confidence, classification_notes,
                  classified_at, rag_indexed_at, LEFT(COALESCE(notes,''), 5000) AS notes,
                  updated_at
             FROM genealogy_sources
            WHERE tree_id = ? AND gedcom_id = ?
            LIMIT 1`,
          [treeId, key]
        );
    if (!rows.length) throw new Error(`Source not found in tree ${treeId}`);
    return rows[0];
  }

  private async personMedia(treeId: number, personIds: number[], familyIds: number[], limit: number) {
    const clauses: string[] = [];
    const params: unknown[] = [];
    if (personIds.length) {
      clauses.push(
        `SELECT 'person' AS link_type, pm.person_id AS entity_id, pm.media_id, pm.is_primary,
                pm.face_confirmed, pm.notes,
                m.title, m.media_type, m.mime_type, m.local_filename, m.nextcloud_path,
                LEFT(COALESCE(m.transcription_text, m.transcription, m.description, m.ai_description, ''), ?) AS text
           FROM genealogy_person_media pm
           JOIN genealogy_media m ON m.id = pm.media_id
          WHERE m.tree_id = ? AND pm.person_id IN (?)`
      );
      params.push(limit, treeId, personIds);
    }
    if (familyIds.length) {
      clauses.push(
        `SELECT 'family' AS link_type, fm.family_id AS entity_id, fm.media_id, NULL AS is_primary,
                NULL AS face_confirmed, NULL AS notes,
                m.title, m.media_type, m.mime_type, m.local_filename, m.nextcloud_path,
                LEFT(COALESCE(m.transcription_text, m.transcription, m.description, m.ai_description, ''), ?) AS text
           FROM genealogy_family_media fm
           JOIN genealogy_media m ON m.id = fm.media_id
          WHERE m.tree_id = ? AND fm.family_id IN (?)`
      );
      params.push(limit, treeId, familyIds);
    }
    if (!clauses.length) return [];
    return await this.db.query<Row[]>(`${clauses.join(' UNION ALL ')} ORDER BY media_id DESC LIMIT 200`, params);
  }

  private async personSourceLinks(treeId: number, personIds: number[], familyIds: number[], limit: number) {
    const clauses: string[] = [];
    const params: unknown[] = [];
    if (personIds.length) {
      clauses.push(
        `SELECT 'person' AS link_type, ps.person_id AS entity_id, ps.source_id,
                s.title, s.url, ps.page, ps.quality,
                LEFT(COALESCE(s.notes,''), ?) AS notes
           FROM genealogy_person_sources ps
           JOIN genealogy_sources s ON s.id = ps.source_id
           JOIN genealogy_persons p ON p.id = ps.person_id
          WHERE p.tree_id = ? AND ps.person_id IN (?)`
      );
      params.push(limit, treeId, personIds);
    }
    if (familyIds.length) {
      clauses.push(
        `SELECT 'family' AS link_type, fs.family_id AS entity_id, fs.source_id,
                s.title, s.url, fs.page, fs.quality,
                LEFT(COALESCE(s.notes,''), ?) AS notes
           FROM genealogy_family_sources fs
           JOIN genealogy_sources s ON s.id = fs.source_id
           JOIN genealogy_families f ON f.id = fs.family_id
          WHERE f.tree_id = ? AND fs.family_id IN (?)`
      );
      params.push(limit, treeId, familyIds);
    }
    if (!clauses.length) return [];
    return await this.db.query<Row[]>(`${clauses.join(' UNION ALL ')} ORDER BY source_id DESC LIMIT 200`, params);
  }

  private async citationLinks(
    treeId: number,
    personIds: number[],
    familyIds: number[],
    mediaIds: number[],
    sourceIds: number[],
    limit: number
  ) {
    const clauses: string[] = [];
    const params: unknown[] = [];
    if (personIds.length) {
      clauses.push('c.person_id IN (?)');
      params.push(personIds);
    }
    if (familyIds.length) {
      clauses.push('c.family_id IN (?)');
      params.push(familyIds);
    }
    if (mediaIds.length) {
      clauses.push('c.media_id IN (?)');
      params.push(mediaIds);
    }
    if (sourceIds.length) {
      clauses.push('c.source_id IN (?)');
      params.push(sourceIds);
    }
    if (!clauses.length) return [];

    return await this.db.query<Row[]>(
      `SELECT c.id, c.source_id, s.title AS source_title, s.url,
              c.person_id, c.family_id, c.media_id, c.fact_type, c.page, c.quality,
              c.evidence_type, c.information_type, LEFT(COALESCE(c.text,''), ?) AS text,
              LEFT(COALESCE(c.evidence_analysis,''), ?) AS evidence_analysis
         FROM genealogy_citations c
         JOIN genealogy_sources s ON s.id = c.source_id
        WHERE s.tree_id = ? AND (${clauses.join(' OR ')})
        ORDER BY c.id DESC
        LIMIT 200`,
      [limit, limit, treeId, ...params]
    );
  }

  private async assertTreeExists(conn: mysql.PoolConnection, treeId: number) {
    if (!(await this.exists(conn, 'SELECT id FROM genealogy_trees WHERE id = ?', [treeId]))) {
      throw new Error(`Genealogy tree not found: ${treeId}`);
    }
  }

  private async assertPersonInTree(conn: mysql.PoolConnection, treeId: number, personId: number) {
    if (!(await this.exists(conn, 'SELECT id FROM genealogy_persons WHERE tree_id = ? AND id = ?', [treeId, personId]))) {
      throw new Error(`Person ${personId} is not in tree ${treeId}`);
    }
  }

  private async assertFamilyInTree(conn: mysql.PoolConnection, treeId: number, familyId: number) {
    if (!(await this.exists(conn, 'SELECT id FROM genealogy_families WHERE tree_id = ? AND id = ?', [treeId, familyId]))) {
      throw new Error(`Family ${familyId} is not in tree ${treeId}`);
    }
  }

  private async assertMediaInTree(conn: mysql.PoolConnection, treeId: number, mediaId: number) {
    if (!(await this.exists(conn, 'SELECT id FROM genealogy_media WHERE tree_id = ? AND id = ?', [treeId, mediaId]))) {
      throw new Error(`Media ${mediaId} is not in tree ${treeId}`);
    }
  }

  private async assertSourceInTree(conn: mysql.PoolConnection, treeId: number, sourceId: number) {
    if (!(await this.exists(conn, 'SELECT id FROM genealogy_sources WHERE tree_id = ? AND id = ?', [treeId, sourceId]))) {
      throw new Error(`Source ${sourceId} is not in tree ${treeId}`);
    }
  }
}

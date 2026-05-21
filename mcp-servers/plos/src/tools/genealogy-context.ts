import { z } from 'zod';
import { mysqlRawQuery } from '../db/mysql.js';

export const genealogyContextInput = z.object({
  tree_id: z.number().int().positive().describe('Genealogy tree ID'),
  person_ids: z.array(z.number().int().positive()).max(100).optional().describe('Person IDs to include'),
  family_ids: z.array(z.number().int().positive()).max(100).optional().describe('Family IDs to include'),
  media_ids: z.array(z.number().int().positive()).max(100).optional().describe('Media IDs to include'),
  source_ids: z.array(z.number().int().positive()).max(100).optional().describe('Source IDs to include'),
  text_limit: z.number().int().min(0).max(5000).optional().default(1200).describe('Maximum characters per long text field'),
});

export type GenealogyContextInput = z.infer<typeof genealogyContextInput>;

export async function genealogyContext(input: GenealogyContextInput): Promise<string> {
  const treeId = input.tree_id;
  const textLimit = input.text_limit;
  const result: Record<string, unknown> = { tree_id: treeId };

  if (input.person_ids?.length) {
    result.people = await mysqlRawQuery(
      `SELECT id, gedcom_id, given_name, surname, nickname, sex, birth_date, birth_place,
              death_date, death_place, living, LEFT(COALESCE(notes,''), ?) AS notes
         FROM genealogy_persons
        WHERE tree_id = ? AND id IN (?)
        ORDER BY id`,
      [textLimit, treeId, input.person_ids]
    );
  }

  if (input.family_ids?.length) {
    result.families = await mysqlRawQuery(
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
      [textLimit, treeId, input.family_ids]
    );

    result.children = await mysqlRawQuery(
      `SELECT c.family_id, c.person_id,
              TRIM(CONCAT(COALESCE(p.given_name,''),' ',COALESCE(p.surname,''))) AS child,
              p.birth_date, c.birth_order
         FROM genealogy_children c
         JOIN genealogy_families f ON f.id = c.family_id
         JOIN genealogy_persons p ON p.id = c.person_id
        WHERE f.tree_id = ? AND c.family_id IN (?)
        ORDER BY c.family_id, c.birth_order, c.id`,
      [treeId, input.family_ids]
    );
  }

  if (input.media_ids?.length) {
    result.media = await mysqlRawQuery(
      `SELECT id, title, media_type, mime_type, local_filename, nextcloud_path,
              analysis_status, enrichment_status, rag_indexed_at,
              LEFT(COALESCE(transcription_text, transcription, description, ai_description, ''), ?) AS text
         FROM genealogy_media
        WHERE tree_id = ? AND id IN (?)
        ORDER BY id`,
      [textLimit, treeId, input.media_ids]
    );

    result.media_links = await mysqlRawQuery(
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
      [treeId, input.media_ids, treeId, input.media_ids]
    );
  }

  const citationClauses: string[] = [];
  const citationParams: unknown[] = [];
  if (input.person_ids?.length) {
    citationClauses.push('c.person_id IN (?)');
    citationParams.push(input.person_ids);
  }
  if (input.family_ids?.length) {
    citationClauses.push('c.family_id IN (?)');
    citationParams.push(input.family_ids);
  }
  if (input.media_ids?.length) {
    citationClauses.push('c.media_id IN (?)');
    citationParams.push(input.media_ids);
  }
  if (input.source_ids?.length) {
    citationClauses.push('c.source_id IN (?)');
    citationParams.push(input.source_ids);
  }

  if (citationClauses.length) {
    result.citations = await mysqlRawQuery(
      `SELECT c.id, c.source_id, s.title AS source_title, c.person_id, c.family_id,
              c.media_id, c.fact_type, c.quality, c.evidence_type, c.information_type,
              LEFT(COALESCE(c.text,''), ?) AS text
         FROM genealogy_citations c
         JOIN genealogy_sources s ON s.id = c.source_id
        WHERE s.tree_id = ? AND (${citationClauses.join(' OR ')})
        ORDER BY c.id DESC
        LIMIT 200`,
      [textLimit, treeId, ...citationParams]
    );
  }

  if (input.source_ids?.length) {
    result.sources = await mysqlRawQuery(
      `SELECT id, title, author, publication, repository, source_quality,
              source_category, information_quality, LEFT(COALESCE(notes,''), ?) AS notes
         FROM genealogy_sources
        WHERE tree_id = ? AND id IN (?)
        ORDER BY id`,
      [textLimit, treeId, input.source_ids]
    );
  }

  return JSON.stringify(result, null, 2);
}

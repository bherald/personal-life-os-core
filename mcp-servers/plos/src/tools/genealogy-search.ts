import { z } from 'zod';
import { mysqlRawQuery } from '../db/mysql.js';
import { isOfflineGenealogyDataError, searchOfflineGedcom } from './offline-genealogy-gedcom.js';

const genealogyEntityType = z.enum(['all', 'person', 'family', 'source', 'media']);

export const genealogySearchInput = z.object({
  tree_id: z.number().int().positive().describe('Genealogy tree ID'),
  query: z.string().trim().min(2).max(200).describe('Literal name, identifier, title, place, or filename fragment'),
  entity_type: genealogyEntityType.optional().default('all').describe('Entity route to search'),
  limit: z.number().int().min(1).max(50).optional().default(20).describe('Maximum results for one route; all searches cap each entity route at 10 (40 total)'),
}).strict();

export type GenealogySearchInput = z.infer<typeof genealogySearchInput>;
type GenealogyEntityType = z.infer<typeof genealogyEntityType>;

export type GenealogySearchPlan = {
  key: 'people' | 'families' | 'sources' | 'media';
  entityType: Exclude<GenealogyEntityType, 'all'>;
  sql: string;
  params: unknown[];
};

const SEARCH_SQL: Record<Exclude<GenealogyEntityType, 'all'>, Omit<GenealogySearchPlan, 'params'>> = {
  person: {
    key: 'people',
    entityType: 'person',
    sql: `SELECT id, gedcom_id,
                 TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE(given_name,'')), ''), NULLIF(TRIM(COALESCE(surname,'')), ''), NULLIF(TRIM(COALESCE(suffix,'')), ''))) AS display_name,
                 nickname, sex, living,
                 CASE WHEN living = 1 THEN NULL ELSE birth_date END AS birth_date,
                 CASE WHEN living = 1 THEN NULL ELSE birth_place END AS birth_place,
                 CASE WHEN living = 1 THEN NULL ELSE death_date END AS death_date,
                 CASE WHEN living = 1 THEN NULL ELSE death_place END AS death_place
            FROM genealogy_persons
           WHERE tree_id = ?
             AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', id, gedcom_id, given_name, surname, suffix, nickname,
                 CASE WHEN living = 1 THEN NULL ELSE birth_date END,
                 CASE WHEN living = 1 THEN NULL ELSE birth_place END,
                 CASE WHEN living = 1 THEN NULL ELSE death_date END,
                 CASE WHEN living = 1 THEN NULL ELSE death_place END))) > 0
           ORDER BY CASE WHEN LOWER(TRIM(CONCAT_WS(' ', given_name, surname, suffix))) = LOWER(?) THEN 0 ELSE 1 END,
                    surname, given_name, id
           LIMIT ?`,
  },
  family: {
    key: 'families',
    entityType: 'family',
    sql: `SELECT f.id, f.gedcom_id, f.husband_id,
                 TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE(h.given_name,'')), ''), NULLIF(TRIM(COALESCE(h.surname,'')), ''), NULLIF(TRIM(COALESCE(h.suffix,'')), ''))) AS husband,
                 f.wife_id,
                 TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE(w.given_name,'')), ''), NULLIF(TRIM(COALESCE(w.surname,'')), ''), NULLIF(TRIM(COALESCE(w.suffix,'')), ''))) AS wife
            FROM genealogy_families f
            LEFT JOIN genealogy_persons h ON h.id = f.husband_id AND h.tree_id = f.tree_id
            LEFT JOIN genealogy_persons w ON w.id = f.wife_id AND w.tree_id = f.tree_id
           WHERE f.tree_id = ?
             AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', f.id, f.gedcom_id, h.gedcom_id, h.given_name, h.surname, h.suffix, w.gedcom_id, w.given_name, w.surname, w.suffix))) > 0
           ORDER BY f.id
           LIMIT ?`,
  },
  source: {
    key: 'sources',
    entityType: 'source',
    sql: `SELECT id, title, author, publication, repository, source_quality,
                 source_category, information_quality
            FROM genealogy_sources
           WHERE tree_id = ?
             AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', id, title, author, publication, repository, source_category))) > 0
           ORDER BY CASE WHEN LOWER(title) = LOWER(?) THEN 0 ELSE 1 END, title, id
           LIMIT ?`,
  },
  media: {
    key: 'media',
    entityType: 'media',
    sql: `SELECT m.id, m.title, m.media_type, m.mime_type, m.analysis_status,
                 m.enrichment_status, (m.rag_indexed_at IS NOT NULL) AS rag_indexed,
                 (SELECT COUNT(*) FROM genealogy_person_media pm WHERE pm.media_id = m.id) AS person_links,
                 (SELECT COUNT(*) FROM genealogy_family_media fm WHERE fm.media_id = m.id) AS family_links
            FROM genealogy_media m
           WHERE m.tree_id = ?
             AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', m.id, m.title, m.local_filename, m.media_type, m.mime_type, m.description, m.ai_description))) > 0
           ORDER BY CASE WHEN LOWER(m.title) = LOWER(?) THEN 0 ELSE 1 END, m.title, m.id
           LIMIT ?`,
  },
};

export function buildGenealogySearchPlans(input: GenealogySearchInput): GenealogySearchPlan[] {
  const routes: Exclude<GenealogyEntityType, 'all'>[] = input.entity_type === 'all'
    ? ['person', 'family', 'source', 'media']
    : [input.entity_type];
  const routeLimit = input.entity_type === 'all' ? Math.min(input.limit, 10) : input.limit;

  return routes.map((route) => {
    const definition = SEARCH_SQL[route];
    const exactRank = route === 'person' || route === 'source' || route === 'media';
    return {
      ...definition,
      params: exactRank
        ? [input.tree_id, input.query, input.query, routeLimit]
        : [input.tree_id, input.query, routeLimit],
    };
  });
}

function contextCall(treeId: number, entityType: GenealogySearchPlan['entityType'], id: number): Record<string, unknown> {
  const idField = `${entityType}_ids`;
  return {
    tool: 'genealogy_context',
    arguments: { tree_id: treeId, [idField]: [id] },
  };
}

export async function genealogySearch(input: GenealogySearchInput): Promise<string> {
  const startedAt = Date.now();
  const plans = buildGenealogySearchPlans(input);
  const results: Record<string, unknown[]> = {};
  let resultCount = 0;

  try {
    for (const plan of plans) {
      const rows = await mysqlRawQuery(plan.sql, plan.params) as Array<Record<string, unknown>>;
      results[plan.key] = rows.map((row) => ({
        ...row,
        context_call: contextCall(input.tree_id, plan.entityType, Number(row.id)),
      }));
      resultCount += rows.length;
    }
  } catch (error) {
    if (!isOfflineGenealogyDataError(error)) throw error;
    const fallback = await searchOfflineGedcom(input);
    if (fallback === null) throw error;

    return JSON.stringify({
      ...fallback,
      duration_ms: Date.now() - startedAt,
    }, null, 2);
  }

  return JSON.stringify({
    schema: 'plos.genealogy_search.v1',
    read_only: true,
    tree_id: input.tree_id,
    query: input.query,
    entity_type: input.entity_type,
    result_count: resultCount,
    duration_ms: Date.now() - startedAt,
    living_person_details_redacted: true,
    results,
  }, null, 2);
}

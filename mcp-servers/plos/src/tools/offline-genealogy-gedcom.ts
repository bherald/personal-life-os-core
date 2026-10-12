import { createHash } from 'node:crypto';
import { readFile, readdir, stat } from 'node:fs/promises';
import { basename, isAbsolute, resolve } from 'node:path';
import { CONFIG } from '../config.js';

type EventName = 'birth' | 'death' | 'burial' | 'marriage';

type GedcomPerson = {
  id: number;
  gedcom_id: string;
  given_name: string;
  surname: string;
  suffix: string;
  nickname: string;
  sex: string | null;
  birth_date: string;
  birth_place: string;
  death_date: string;
  death_place: string;
  burial_date: string;
  burial_place: string;
  family_spouse_ids: number[];
  family_child_ids: number[];
};

type GedcomFamily = {
  id: number;
  gedcom_id: string;
  husband_id: number | null;
  wife_id: number | null;
  child_ids: number[];
  marriage_date: string;
  marriage_place: string;
};

type GedcomSource = {
  id: number;
  gedcom_id: string;
  title: string;
  author: string;
  publication: string;
  repository: string;
};

type GedcomMedia = {
  id: number;
  gedcom_id: string;
  title: string;
  file: string;
  format: string;
};

type GedcomSnapshot = {
  path: string;
  file: string;
  sha256: string;
  people: GedcomPerson[];
  families: GedcomFamily[];
  sources: GedcomSource[];
  media: GedcomMedia[];
};

export type OfflineGedcomSearchInput = {
  tree_id: number;
  query: string;
  entity_type: 'all' | 'person' | 'family' | 'source' | 'media';
  limit: number;
};

export type OfflineGedcomContextInput = {
  tree_id: number;
  person_ids?: number[];
  family_ids?: number[];
  media_ids?: number[];
  source_ids?: number[];
  text_limit: number;
};

let cachedSnapshot: { key: string; value: GedcomSnapshot } | null = null;

function numericId(value: string | undefined): number | null {
  const match = value?.match(/[0-9]+/);
  if (!match) return null;
  const id = Number(match[0]);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

function pointerId(value: string): number | null {
  return numericId(value.match(/^@([^@]+)@$/)?.[1]);
}

function parseName(value: string): { given: string; surname: string; suffix: string } {
  const match = value.match(/^(.*?)\s*\/(.*?)\/\s*(.*)$/);
  if (!match) {
    return { given: value.trim(), surname: '', suffix: '' };
  }

  return {
    given: match[1].trim(),
    surname: match[2].trim(),
    suffix: match[3].trim(),
  };
}

function lineParts(line: string): { level: number; tag: string; value: string } | null {
  const match = line.match(/^([0-9]+)\s+([A-Z0-9_]+)(?:\s+(.*))?$/);
  if (!match) return null;

  return {
    level: Number(match[1]),
    tag: match[2],
    value: (match[3] ?? '').trim(),
  };
}

function recordChunks(content: string): Array<{ xref: string; type: string; lines: string[] }> {
  const chunks: Array<{ xref: string; type: string; lines: string[] }> = [];
  let current: { xref: string; type: string; lines: string[] } | null = null;

  for (const rawLine of content.replace(/^\uFEFF/, '').split(/\r?\n/)) {
    const header = rawLine.match(/^0 @([^@]+)@ (INDI|FAM|SOUR|OBJE)$/);
    if (header) {
      if (current) chunks.push(current);
      current = { xref: header[1], type: header[2], lines: [] };
      continue;
    }
    if (rawLine.startsWith('0 ')) {
      if (current) chunks.push(current);
      current = null;
      continue;
    }
    if (current) current.lines.push(rawLine);
  }
  if (current) chunks.push(current);

  return chunks;
}

function parsePerson(xref: string, lines: string[]): GedcomPerson | null {
  const id = numericId(xref);
  if (id === null) return null;

  const person: GedcomPerson = {
    id,
    gedcom_id: xref,
    given_name: '',
    surname: '',
    suffix: '',
    nickname: '',
    sex: null,
    birth_date: '',
    birth_place: '',
    death_date: '',
    death_place: '',
    burial_date: '',
    burial_place: '',
    family_spouse_ids: [],
    family_child_ids: [],
  };
  let event: EventName | null = null;

  for (const line of lines) {
    const part = lineParts(line);
    if (!part) continue;
    if (part.level === 1) {
      event = null;
      if (part.tag === 'NAME') {
        const name = parseName(part.value);
        person.given_name = name.given;
        person.surname = name.surname;
        person.suffix = name.suffix;
      } else if (part.tag === 'SEX') {
        person.sex = part.value || null;
      } else if (part.tag === 'BIRT') {
        event = 'birth';
      } else if (part.tag === 'DEAT') {
        event = 'death';
      } else if (part.tag === 'BURI') {
        event = 'burial';
      } else if (part.tag === 'FAMS') {
        const familyId = pointerId(part.value);
        if (familyId !== null) person.family_spouse_ids.push(familyId);
      } else if (part.tag === 'FAMC') {
        const familyId = pointerId(part.value);
        if (familyId !== null) person.family_child_ids.push(familyId);
      }
      continue;
    }
    if (part.level === 2 && part.tag === 'GIVN') person.given_name = part.value;
    if (part.level === 2 && part.tag === 'SURN') person.surname = part.value;
    if (part.level === 2 && part.tag === 'NSFX') person.suffix = part.value;
    if (part.level === 2 && part.tag === 'NICK') person.nickname = part.value;
    if (part.level === 2 && event && (part.tag === 'DATE' || part.tag === 'PLAC')) {
      const key = `${event}_${part.tag === 'DATE' ? 'date' : 'place'}` as keyof GedcomPerson;
      (person[key] as string) = part.value;
    }
  }

  return person;
}

function parseFamily(xref: string, lines: string[]): GedcomFamily | null {
  const id = numericId(xref);
  if (id === null) return null;
  const family: GedcomFamily = {
    id,
    gedcom_id: xref,
    husband_id: null,
    wife_id: null,
    child_ids: [],
    marriage_date: '',
    marriage_place: '',
  };
  let event: EventName | null = null;

  for (const line of lines) {
    const part = lineParts(line);
    if (!part) continue;
    if (part.level === 1) {
      event = part.tag === 'MARR' ? 'marriage' : null;
      if (part.tag === 'HUSB') family.husband_id = pointerId(part.value);
      if (part.tag === 'WIFE') family.wife_id = pointerId(part.value);
      if (part.tag === 'CHIL') {
        const childId = pointerId(part.value);
        if (childId !== null) family.child_ids.push(childId);
      }
      continue;
    }
    if (part.level === 2 && event === 'marriage' && part.tag === 'DATE') family.marriage_date = part.value;
    if (part.level === 2 && event === 'marriage' && part.tag === 'PLAC') family.marriage_place = part.value;
  }

  return family;
}

function parseSource(xref: string, lines: string[]): GedcomSource | null {
  const id = numericId(xref);
  if (id === null) return null;
  const source: GedcomSource = {
    id,
    gedcom_id: xref,
    title: '',
    author: '',
    publication: '',
    repository: '',
  };

  for (const line of lines) {
    const part = lineParts(line);
    if (!part || part.level !== 1) continue;
    if (part.tag === 'TITL') source.title = part.value;
    if (part.tag === 'AUTH') source.author = part.value;
    if (part.tag === 'PUBL') source.publication = part.value;
    if (part.tag === 'REPO') source.repository = part.value;
  }

  return source;
}

function parseMedia(xref: string, lines: string[]): GedcomMedia | null {
  const id = numericId(xref);
  if (id === null) return null;
  const media: GedcomMedia = {
    id,
    gedcom_id: xref,
    title: '',
    file: '',
    format: '',
  };

  for (const line of lines) {
    const part = lineParts(line);
    if (!part || part.level !== 1) continue;
    if (part.tag === 'TITL') media.title = part.value;
    if (part.tag === 'FILE') media.file = part.value;
    if (part.tag === 'FORM') media.format = part.value;
  }

  return media;
}

async function discoverGedcomPath(treeId: number): Promise<string | null> {
  const configured = process.env[`PLOS_GENEALOGY_OFFLINE_GEDCOM_TREE_${treeId}`]
    ?? process.env.PLOS_GENEALOGY_OFFLINE_GEDCOM;
  if (configured && configured.trim() !== '') {
    return isAbsolute(configured) ? configured : resolve(CONFIG.paths.projectRoot, configured);
  }
  if (treeId !== 4) return null;

  const directory = resolve(CONFIG.paths.projectRoot, 'storage/app/imports/genealogy');
  let entries: string[];
  try {
    entries = (await readdir(directory))
      .filter(entry => /\.(ged|gedcom)$/i.test(entry))
      .sort();
  } catch {
    return null;
  }

  return entries.length === 1 ? resolve(directory, entries[0]) : null;
}

async function loadSnapshot(treeId: number, pathOverride?: string): Promise<GedcomSnapshot | null> {
  const path = pathOverride ?? await discoverGedcomPath(treeId);
  if (path === null) return null;
  const info = await stat(path);
  const cacheKey = `${path}:${info.size}:${info.mtimeMs}`;
  if (cachedSnapshot?.key === cacheKey) return cachedSnapshot.value;

  const content = await readFile(path, 'utf8');
  const snapshot: GedcomSnapshot = {
    path,
    file: basename(path),
    sha256: createHash('sha256').update(content).digest('hex'),
    people: [],
    families: [],
    sources: [],
    media: [],
  };

  for (const chunk of recordChunks(content)) {
    if (chunk.type === 'INDI') {
      const person = parsePerson(chunk.xref, chunk.lines);
      if (person) snapshot.people.push(person);
    } else if (chunk.type === 'FAM') {
      const family = parseFamily(chunk.xref, chunk.lines);
      if (family) snapshot.families.push(family);
    } else if (chunk.type === 'SOUR') {
      const source = parseSource(chunk.xref, chunk.lines);
      if (source) snapshot.sources.push(source);
    } else if (chunk.type === 'OBJE') {
      const media = parseMedia(chunk.xref, chunk.lines);
      if (media) snapshot.media.push(media);
    }
  }

  cachedSnapshot = { key: cacheKey, value: snapshot };
  return snapshot;
}

function displayName(person: GedcomPerson | undefined): string {
  if (!person) return '';
  return [person.given_name, person.surname, person.suffix].filter(Boolean).join(' ').trim();
}

function likelyLiving(person: GedcomPerson): boolean {
  if (person.death_date !== '') return false;
  const years = person.birth_date.match(/\b(1[0-9]{3}|20[0-9]{2})\b/g);
  const year = years?.length ? Number(years.at(-1)) : null;
  return year === null || year > new Date().getUTCFullYear() - 100;
}

function includesLiteral(values: unknown[], query: string): boolean {
  return values.some(value => String(value ?? '').toLocaleLowerCase().includes(query));
}

function provenance(snapshot: GedcomSnapshot): Record<string, unknown> {
  return {
    source: 'local_gedcom_snapshot',
    snapshot_file: snapshot.file,
    snapshot_sha256: snapshot.sha256,
    database_available: false,
    read_only: true,
  };
}

export function isOfflineGenealogyDataError(error: unknown): boolean {
  const code = typeof error === 'object' && error !== null && 'code' in error
    ? String((error as { code?: unknown }).code ?? '')
    : '';

  return [
    'ECONNREFUSED',
    'ENETUNREACH',
    'EHOSTUNREACH',
    'ENOTFOUND',
    'ETIMEDOUT',
    'ER_BAD_DB_ERROR',
    'ER_NO_SUCH_TABLE',
  ].includes(code);
}

export async function searchOfflineGedcom(
  input: OfflineGedcomSearchInput,
  pathOverride?: string,
): Promise<Record<string, unknown> | null> {
  const snapshot = await loadSnapshot(input.tree_id, pathOverride);
  if (!snapshot) return null;
  const query = input.query.toLocaleLowerCase();
  const routeLimit = input.entity_type === 'all' ? Math.min(input.limit, 10) : input.limit;
  const routes = input.entity_type === 'all'
    ? ['person', 'family', 'source', 'media'] as const
    : [input.entity_type];
  const peopleById = new Map(snapshot.people.map(person => [person.id, person]));
  const results: Record<string, unknown[]> = {};

  if (routes.includes('person')) {
    results.people = snapshot.people
      .filter(person => includesLiteral([
        person.id,
        person.gedcom_id,
        person.given_name,
        person.surname,
        person.suffix,
        person.nickname,
        person.birth_date,
        person.birth_place,
        person.death_date,
        person.death_place,
      ], query))
      .sort((a, b) => {
        const exact = Number(displayName(a).toLocaleLowerCase() !== query)
          - Number(displayName(b).toLocaleLowerCase() !== query);
        return exact || a.surname.localeCompare(b.surname) || a.given_name.localeCompare(b.given_name) || a.id - b.id;
      })
      .slice(0, routeLimit)
      .map(person => {
        const living = likelyLiving(person);
        return {
          id: person.id,
          gedcom_id: person.gedcom_id,
          display_name: displayName(person),
          nickname: person.nickname,
          sex: person.sex,
          living: living ? 1 : 0,
          birth_date: living ? null : person.birth_date,
          birth_place: living ? null : person.birth_place,
          death_date: living ? null : person.death_date,
          death_place: living ? null : person.death_place,
          context_call: {
            tool: 'genealogy_context',
            arguments: { tree_id: input.tree_id, person_ids: [person.id] },
          },
        };
      });
  }

  if (routes.includes('family')) {
    results.families = snapshot.families
      .filter(family => includesLiteral([
        family.id,
        family.gedcom_id,
        displayName(peopleById.get(family.husband_id ?? -1)),
        displayName(peopleById.get(family.wife_id ?? -1)),
        family.marriage_date,
        family.marriage_place,
      ], query))
      .sort((a, b) => a.id - b.id)
      .slice(0, routeLimit)
      .map(family => ({
        id: family.id,
        gedcom_id: family.gedcom_id,
        husband_id: family.husband_id,
        husband: displayName(peopleById.get(family.husband_id ?? -1)),
        wife_id: family.wife_id,
        wife: displayName(peopleById.get(family.wife_id ?? -1)),
        context_call: {
          tool: 'genealogy_context',
          arguments: { tree_id: input.tree_id, family_ids: [family.id] },
        },
      }));
  }

  if (routes.includes('source')) {
    results.sources = snapshot.sources
      .filter(source => includesLiteral(Object.values(source), query))
      .sort((a, b) => a.title.localeCompare(b.title) || a.id - b.id)
      .slice(0, routeLimit)
      .map(source => ({
        ...source,
        context_call: {
          tool: 'genealogy_context',
          arguments: { tree_id: input.tree_id, source_ids: [source.id] },
        },
      }));
  }

  if (routes.includes('media')) {
    results.media = snapshot.media
      .filter(media => includesLiteral(Object.values(media), query))
      .sort((a, b) => a.title.localeCompare(b.title) || a.id - b.id)
      .slice(0, routeLimit)
      .map(media => ({
        id: media.id,
        gedcom_id: media.gedcom_id,
        title: media.title,
        media_type: media.format || null,
        context_call: {
          tool: 'genealogy_context',
          arguments: { tree_id: input.tree_id, media_ids: [media.id] },
        },
      }));
  }

  return {
    schema: 'plos.genealogy_search.v1',
    tree_id: input.tree_id,
    query: input.query,
    entity_type: input.entity_type,
    result_count: Object.values(results).reduce((count, rows) => count + rows.length, 0),
    living_person_details_redacted: true,
    id_scope: 'gedcom_numeric',
    ...provenance(snapshot),
    results,
  };
}

export async function contextOfflineGedcom(
  input: OfflineGedcomContextInput,
  pathOverride?: string,
): Promise<Record<string, unknown> | null> {
  const snapshot = await loadSnapshot(input.tree_id, pathOverride);
  if (!snapshot) return null;
  const peopleById = new Map(snapshot.people.map(person => [person.id, person]));
  const result: Record<string, unknown> = {
    tree_id: input.tree_id,
    id_scope: 'gedcom_numeric',
    ...provenance(snapshot),
  };

  if (input.person_ids?.length) {
    const selected = new Set(input.person_ids);
    result.people = snapshot.people
      .filter(person => selected.has(person.id))
      .map(person => ({
        id: person.id,
        gedcom_id: person.gedcom_id,
        given_name: person.given_name,
        surname: person.surname,
        suffix: person.suffix,
        nickname: person.nickname,
        sex: person.sex,
        birth_date: person.birth_date,
        birth_place: person.birth_place,
        death_date: person.death_date,
        death_place: person.death_place,
        burial_date: person.burial_date,
        burial_place: person.burial_place,
        living: likelyLiving(person) ? 1 : 0,
      }));
  }

  if (input.family_ids?.length) {
    const selected = new Set(input.family_ids);
    const families = snapshot.families.filter(family => selected.has(family.id));
    result.families = families.map(family => ({
      id: family.id,
      gedcom_id: family.gedcom_id,
      husband_id: family.husband_id,
      husband: displayName(peopleById.get(family.husband_id ?? -1)),
      wife_id: family.wife_id,
      wife: displayName(peopleById.get(family.wife_id ?? -1)),
      marriage_date: family.marriage_date,
      marriage_place: family.marriage_place,
    }));
    result.children = families.flatMap(family => family.child_ids.map((personId, index) => ({
      family_id: family.id,
      person_id: personId,
      child: displayName(peopleById.get(personId)),
      birth_date: peopleById.get(personId)?.birth_date ?? '',
      birth_order: index + 1,
    })));
  }

  if (input.source_ids?.length) {
    const selected = new Set(input.source_ids);
    result.sources = snapshot.sources.filter(source => selected.has(source.id));
  }

  if (input.media_ids?.length) {
    const selected = new Set(input.media_ids);
    result.media = snapshot.media
      .filter(media => selected.has(media.id))
      .map(media => ({
        id: media.id,
        gedcom_id: media.gedcom_id,
        title: media.title,
        media_type: media.format || null,
        local_filename: basename(media.file.replace(/\\/g, '/')),
      }));
  }

  result.limitations = [
    'Offline GEDCOM fallback does not include database-only citation, RAG, analysis, or media-link state.',
    'Online source verification is unavailable while off-grid.',
  ];

  return result;
}

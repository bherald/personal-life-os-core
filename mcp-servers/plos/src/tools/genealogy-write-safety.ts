export type GraphFamily = { id: number; husband_id: number | null; wife_id: number | null };
export type GraphChild = { family_id: number; person_id: number };

export type GraphMetrics = {
  root_person_id: number | null;
  root_present: boolean;
  people: number;
  ancestors: number | null;
  reachable: number | null;
};

export type GraphSnapshot = GraphMetrics & {
  ancestor_ids: Set<number>;
  reachable_ids: Set<number>;
};

export type GraphGuardReport = {
  schema: 'plos.genealogy_graph_guard.v1';
  status: 'not_required' | 'planned' | 'not_configured' | 'passed';
  transactional_rollback: true;
  root_person_id: number | null;
  before?: GraphMetrics;
  after?: GraphMetrics;
  delta?: { people: number; ancestors: number | null; reachable: number | null };
};

export type OptionalReference = { specified: boolean; id: number | null };

const SCHEMA = 'plos.genealogy_graph_guard.v1' as const;

export function plannedGraphGuard(): GraphGuardReport {
  return { schema: SCHEMA, status: 'planned', transactional_rollback: true, root_person_id: null };
}

export function inactiveGraphGuard(): GraphGuardReport {
  return { schema: SCHEMA, status: 'not_required', transactional_rollback: true, root_person_id: null };
}

export function resolveOptionalReference(
  record: Record<string, unknown>,
  idField: string,
  keyField: string,
  keyMap: Record<string, number>,
  label: string
): OptionalReference {
  if (Object.prototype.hasOwnProperty.call(record, idField)) {
    const value = record[idField];
    if (value === null) return { specified: true, id: null };
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value <= 0) {
      throw new Error(`${label} must be a positive integer or null`);
    }
    return { specified: true, id: value };
  }
  if (Object.prototype.hasOwnProperty.call(record, keyField)) {
    const key = record[keyField];
    if (typeof key !== 'string' || !key || !keyMap[key]) {
      throw new Error(`Missing ${label} reference${typeof key === 'string' && key ? ` for key ${key}` : ''}`);
    }
    return { specified: true, id: keyMap[key] };
  }
  return { specified: false, id: null };
}

export function buildGraphSnapshot(
  rootPersonId: number | null,
  personIds: number[],
  families: GraphFamily[],
  children: GraphChild[]
): GraphSnapshot {
  const people = new Set(personIds);
  if (rootPersonId === null) {
    return {
      root_person_id: null,
      root_present: false,
      people: people.size,
      ancestors: null,
      reachable: null,
      ancestor_ids: new Set<number>(),
      reachable_ids: new Set<number>(),
    };
  }

  const familyMembers = new Map<number, Set<number>>();
  const parentsByFamily = new Map<number, number[]>();
  const parentsByChild = new Map<number, Set<number>>();
  for (const family of families) {
    const members = familyMembers.get(family.id) ?? new Set<number>();
    const parents = [family.husband_id, family.wife_id]
      .filter((id): id is number => id !== null && people.has(id));
    for (const parent of parents) members.add(parent);
    familyMembers.set(family.id, members);
    parentsByFamily.set(family.id, parents);
  }
  for (const child of children) {
    if (!people.has(child.person_id)) continue;
    const members = familyMembers.get(child.family_id);
    if (!members) continue;
    members.add(child.person_id);
    const childParents = parentsByChild.get(child.person_id) ?? new Set<number>();
    for (const parent of parentsByFamily.get(child.family_id) ?? []) childParents.add(parent);
    if (childParents.size) parentsByChild.set(child.person_id, childParents);
  }

  const adjacency = new Map<number, Set<number>>();
  for (const members of familyMembers.values()) {
    const memberIds = [...members];
    const anchor = memberIds[0];
    if (anchor === undefined) continue;
    if (!adjacency.has(anchor)) adjacency.set(anchor, new Set<number>());
    for (const member of memberIds.slice(1)) {
      adjacency.get(anchor)!.add(member);
      const edges = adjacency.get(member) ?? new Set<number>();
      edges.add(anchor);
      adjacency.set(member, edges);
    }
  }

  const rootPresent = people.has(rootPersonId);
  const ancestorIds = rootPresent ? walkIds(rootPersonId, parentsByChild) : new Set<number>();
  ancestorIds.delete(rootPersonId);
  const reachableIds = rootPresent ? walkIds(rootPersonId, adjacency) : new Set<number>();
  return {
    root_person_id: rootPersonId,
    root_present: rootPresent,
    people: people.size,
    ancestors: ancestorIds.size,
    reachable: reachableIds.size,
    ancestor_ids: ancestorIds,
    reachable_ids: reachableIds,
  };
}

export function evaluateGraphGuard(before: GraphSnapshot, after: GraphSnapshot): GraphGuardReport {
  const beforeMetrics = graphMetrics(before);
  const afterMetrics = graphMetrics(after);
  if (before.root_person_id === null) {
    return {
      schema: SCHEMA,
      status: 'not_configured',
      transactional_rollback: true,
      root_person_id: null,
      before: beforeMetrics,
      after: afterMetrics,
      delta: { people: after.people - before.people, ancestors: null, reachable: null },
    };
  }
  if (!before.root_present) {
    throw new Error(`genealogy_batch_apply graph guard pre-check failed: protected root ${before.root_person_id} is missing`);
  }
  if (after.root_person_id !== before.root_person_id || !after.root_present) {
    throw new Error('genealogy_batch_apply graph guard rollback: protected root changed or is missing');
  }
  if ((after.ancestors ?? 0) < (before.ancestors ?? 0)) {
    throw new Error(`genealogy_batch_apply graph guard rollback: protected-root ancestor count fell ${before.ancestors} -> ${after.ancestors}`);
  }
  if (!isSubset(before.ancestor_ids, after.ancestor_ids)) {
    throw new Error('genealogy_batch_apply graph guard rollback: protected-root ancestor membership regressed');
  }
  if ((after.reachable ?? 0) < (before.reachable ?? 0)) {
    throw new Error(`genealogy_batch_apply graph guard rollback: protected-root reachable count fell ${before.reachable} -> ${after.reachable}`);
  }
  if (!isSubset(before.reachable_ids, after.reachable_ids)) {
    throw new Error('genealogy_batch_apply graph guard rollback: protected-root reachable membership regressed');
  }
  return {
    schema: SCHEMA,
    status: 'passed',
    transactional_rollback: true,
    root_person_id: before.root_person_id,
    before: beforeMetrics,
    after: afterMetrics,
    delta: {
      people: after.people - before.people,
      ancestors: (after.ancestors ?? 0) - (before.ancestors ?? 0),
      reachable: (after.reachable ?? 0) - (before.reachable ?? 0),
    },
  };
}

function walkIds(start: number, edges: Map<number, Set<number>>): Set<number> {
  const visited = new Set<number>([start]);
  const queue = [start];
  for (let offset = 0; offset < queue.length; offset++) {
    for (const next of edges.get(queue[offset]) ?? []) {
      if (visited.has(next)) continue;
      visited.add(next);
      queue.push(next);
    }
  }
  return visited;
}

function graphMetrics(snapshot: GraphSnapshot): GraphMetrics {
  return {
    root_person_id: snapshot.root_person_id,
    root_present: snapshot.root_present,
    people: snapshot.people,
    ancestors: snapshot.ancestors,
    reachable: snapshot.reachable,
  };
}

function isSubset(before: Set<number>, after: Set<number>): boolean {
  for (const id of before) if (!after.has(id)) return false;
  return true;
}

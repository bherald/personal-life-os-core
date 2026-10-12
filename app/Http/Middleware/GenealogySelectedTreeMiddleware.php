<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Require explicit tree context for genealogy routes that address records by ID. */
class GenealogySelectedTreeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $segments = $request->segments();
        $index = array_search('genealogy', $segments, true);
        if ($index === false) {
            return $next($request);
        }

        $parts = array_slice($segments, $index + 1);
        $entity = $parts[0] ?? null;
        $id = $parts[1] ?? null;
        if ($entity === 'intake-runs') {
            return $this->handleIntakeRun($request, $next, $parts);
        }

        $checks = [];
        $pathTreeId = $entity === 'trees' && is_string($id) && ctype_digit($id) ? (int) $id : null;

        if (is_string($entity) && is_string($id) && ctype_digit($id)) {
            $checks[] = [$entity, (int) $id];
            if (in_array($entity, ['media', 'families', 'persons', 'clippings'], true) && isset($parts[2], $parts[3]) && ctype_digit($parts[3])) {
                $checks[] = [$parts[2], (int) $parts[3]];
            }
        } elseif ($entity === 'citations' && $request->isMethod('post')) {
            $sourceId = $request->input('source_id');
            if (is_numeric($sourceId)) {
                $checks[] = ['sources', (int) $sourceId];
            }
        }

        if ($request->isMethod('post') || $request->isMethod('put')) {
            $bodyTargets = match (true) {
                $entity === 'families' || ($entity === 'trees' && ($parts[2] ?? null) === 'families') => [
                    'husband_id' => 'persons', 'wife_id' => 'persons',
                    'person_id' => 'persons', 'child_ids' => 'persons', 'source_id' => 'sources',
                ],
                $entity === 'persons' => ['source_id' => 'sources', 'media_id' => 'media'],
                $entity === 'media' => ['person_id' => 'persons', 'family_id' => 'families'],
                $entity === 'face-match-queue' || $entity === 'fan-cluster-members' => ['person_id' => 'persons'],
                $entity === 'fan-clusters' => ['person_id' => 'persons', 'member_person_id' => 'persons'],
                $entity === 'external-records' || $entity === 'research-tasks' => ['person_id' => 'persons'],
                in_array($entity, ['events', 'residences', 'family-events'], true) => ['source_id' => 'sources'],
                $entity === 'citations' => [
                    'source_id' => 'sources', 'person_id' => 'persons',
                    'family_id' => 'families', 'media_id' => 'media',
                ],
                $entity === 'trees' && in_array($parts[2] ?? null, ['persons', 'media'], true) => [
                    'person_id' => 'persons', 'person_ids' => 'persons',
                    'person1_id' => 'persons', 'person2_id' => 'persons',
                    'primary_id' => 'persons', 'secondary_id' => 'persons',
                    'media_ids' => 'media',
                ],
                $entity === 'trees' && ($parts[2] ?? null) === 'relationship' => [
                    'person1_id' => 'persons', 'person2_id' => 'persons',
                ],
                $entity === 'trees' && in_array($parts[2] ?? null, ['research-tasks', 'external-records'], true) => [
                    'person_id' => 'persons',
                ],
                default => [],
            };
            foreach ($bodyTargets as $field => $type) {
                $value = $request->input($field);
                foreach (is_array($value) ? $value : [$value] as $recordId) {
                    if (is_numeric($recordId) && (int) $recordId > 0) {
                        $checks[] = [$type, (int) $recordId];
                    }
                }
            }
        }

        $checks = array_values(array_filter($checks, fn (array $check): bool => $this->supports($check[0])));
        if ($checks === []) {
            return $next($request);
        }

        $treeId = $this->selectedTreeId($request, $pathTreeId);
        if ($treeId === null) {
            return response()->json(['success' => false, 'error' => ['message' => 'A single positive tree_id is required']], 400);
        }

        foreach ($checks as [$type, $recordId]) {
            if (! $this->belongsToTree($type, $recordId, $treeId)) {
                return response()->json(['success' => false, 'error' => ['message' => 'Genealogy record not found in tree']], 404);
            }
        }

        return $next($request);
    }

    private function handleIntakeRun(Request $request, Closure $next, array $parts): Response
    {
        $treeId = $this->selectedTreeId($request, null);
        if ($treeId === null) {
            return response()->json(['success' => false, 'error' => ['message' => 'A single positive tree_id is required']], 400);
        }

        $tree = DB::selectOne('SELECT id FROM genealogy_trees WHERE id = ?', [$treeId]);
        if ($tree === null) {
            return response()->json(['success' => false, 'error' => ['message' => 'Genealogy tree not found']], 404);
        }

        $runKey = $parts[1] ?? null;
        if ($runKey === null) {
            $request->query->set('tree_id', (string) $treeId);
        } elseif ($runKey !== 'stage') {
            $run = DB::selectOne('SELECT tree_id FROM genealogy_intake_runs WHERE run_key = ?', [$runKey]);
            if ($run === null || (int) $run->tree_id !== $treeId) {
                return response()->json(['success' => false, 'error' => ['message' => 'Intake run not found in tree']], 404);
            }
        }

        return $next($request);
    }

    private function selectedTreeId(Request $request, ?int $pathTreeId): ?int
    {
        $values = array_filter([
            $request->header('X-Genealogy-Tree-Id'),
            $request->query('tree_id'),
            $request->input('tree_id'),
            $pathTreeId,
        ], static fn ($value): bool => $value !== null && $value !== '');
        $treeIds = array_values(array_unique(array_map('strval', $values)));

        return count($treeIds) === 1 && ctype_digit($treeIds[0]) && (int) $treeIds[0] > 0
            ? (int) $treeIds[0]
            : null;
    }

    private function supports(string $type): bool
    {
        return in_array($type, [
            'persons', 'families', 'sources', 'media', 'repositories',
            'events', 'residences', 'family-events', 'citations',
            'clippings', 'face-match-queue', 'fan-clusters', 'fan-cluster-members',
            'research-hints', 'research-tasks', 'name-variations', 'smart-matches',
            'external-connections', 'external-records', 'external-syncs',
            'collaborators', 'invitations',
        ], true);
    }

    private function belongsToTree(string $type, int $id, int $treeId): bool
    {
        $direct = [
            'persons' => 'genealogy_persons',
            'families' => 'genealogy_families',
            'sources' => 'genealogy_sources',
            'media' => 'genealogy_media',
            'repositories' => 'genealogy_repositories',
            'clippings' => 'genealogy_newspaper_clippings',
            'face-match-queue' => 'genealogy_face_match_queue',
            'research-hints' => 'genealogy_research_hints',
            'research-tasks' => 'genealogy_research_tasks',
            'name-variations' => 'genealogy_name_variations',
            'smart-matches' => 'genealogy_smart_matches',
            'external-connections' => 'genealogy_external_connections',
            'external-records' => 'genealogy_external_records',
            'collaborators' => 'genealogy_tree_collaborators',
            'invitations' => 'genealogy_tree_invitations',
        ];

        if (isset($direct[$type])) {
            return DB::selectOne("SELECT id FROM {$direct[$type]} WHERE id = ? AND tree_id = ?", [$id, $treeId]) !== null;
        }

        $sql = match ($type) {
            'events' => 'SELECT e.id FROM genealogy_events e JOIN genealogy_persons p ON p.id = e.person_id WHERE e.id = ? AND p.tree_id = ?',
            'residences' => 'SELECT r.id FROM genealogy_residences r JOIN genealogy_persons p ON p.id = r.person_id WHERE r.id = ? AND p.tree_id = ?',
            'family-events' => 'SELECT e.id FROM genealogy_family_events e JOIN genealogy_families f ON f.id = e.family_id WHERE e.id = ? AND f.tree_id = ?',
            'citations' => 'SELECT c.id FROM genealogy_citations c JOIN genealogy_sources s ON s.id = c.source_id WHERE c.id = ? AND s.tree_id = ?',
            'fan-clusters' => 'SELECT c.id FROM genealogy_fan_clusters c JOIN genealogy_persons p ON p.id = c.person_id WHERE c.id = ? AND p.tree_id = ?',
            'fan-cluster-members' => 'SELECT m.id FROM genealogy_fan_members m JOIN genealogy_fan_clusters c ON c.id = m.cluster_id JOIN genealogy_persons p ON p.id = c.person_id WHERE m.id = ? AND p.tree_id = ?',
            'external-syncs' => 'SELECT s.id FROM genealogy_external_syncs s JOIN genealogy_external_connections c ON c.id = s.connection_id WHERE s.id = ? AND c.tree_id = ?',
        };

        return DB::selectOne($sql, [$id, $treeId]) !== null;
    }
}

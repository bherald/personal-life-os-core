<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * DNA Triangulation Group Service (N06)
 *
 * Advanced triangulation analysis for DNA matches:
 * - Group matches sharing overlapping segments
 * - Identify potential common ancestors
 * - Cluster analysis for endogamous populations
 * - Visual cluster data for chromosome painting
 */
class TriangulationGroupService
{
    private ?DnaMatchService $dnaService = null;

    private function getDnaService(): DnaMatchService
    {
        if ($this->dnaService === null) {
            $this->dnaService = app(DnaMatchService::class);
        }
        return $this->dnaService;
    }

    /**
     * Build triangulation groups for a kit
     * Groups matches that share overlapping DNA segments, indicating common ancestry
     *
     * @param int $kitId Kit ID
     * @param array $options Options: min_overlap_cm, min_group_size, rebuild
     * @return array Triangulation groups
     */
    public function buildTriangulationGroups(int $kitId = 0, array $options = []): array
    {
        if ($kitId <= 0) {
            $total = DB::selectOne("SELECT COUNT(*) as cnt FROM genealogy_dna_kits")->cnt ?? 0;
            if ($total === 0) {
                return ['error' => 'No DNA kits configured. Upload a raw DNA file via the DNA section to get started.', 'groups' => []];
            }
            return ['error' => 'kitId is required. Use list_dna_kits to find available kit IDs.', 'groups' => []];
        }

        $minOverlapCm = $options['min_overlap_cm'] ?? 7;
        $minGroupSize = $options['min_group_size'] ?? 3;
        $rebuild = $options['rebuild'] ?? false;

        if ($rebuild) {
            DB::delete("DELETE FROM genealogy_dna_triangulation_groups WHERE kit_id = ?", [$kitId]);
        }

        // First, ensure triangulations are calculated
        $triangulations = $this->getDnaService()->findTriangulations($kitId, $minOverlapCm);

        // Build adjacency graph of matches that share segments
        $adjacency = [];
        foreach ($triangulations as $tri) {
            $m1 = $tri['match_1']['id'];
            $m2 = $tri['match_2']['id'];
            $chr = $tri['chromosome'];

            if (!isset($adjacency[$m1])) {
                $adjacency[$m1] = [];
            }
            if (!isset($adjacency[$m2])) {
                $adjacency[$m2] = [];
            }

            $key = "{$m1}_{$m2}_{$chr}";
            $adjacency[$m1][$m2] = [
                'chromosome' => $chr,
                'overlap_cm' => $tri['overlap_cm'],
                'overlap_start' => $tri['overlap_start'],
                'overlap_end' => $tri['overlap_end'],
            ];
            $adjacency[$m2][$m1] = $adjacency[$m1][$m2];
        }

        // Find connected components (groups)
        $groups = $this->findConnectedComponents($adjacency);

        // Filter by minimum group size
        $groups = array_filter($groups, fn($g) => count($g) >= $minGroupSize);

        // Enrich groups with metadata
        $enrichedGroups = [];
        $groupNumber = 1;

        foreach ($groups as $matchIds) {
            $group = $this->enrichGroup($kitId, $matchIds, $adjacency, $groupNumber);
            if ($group) {
                $enrichedGroups[] = $group;
                $this->storeGroup($kitId, $group);
                $groupNumber++;
            }
        }

        Log::info('TriangulationGroup: Built groups', [
            'kit_id' => $kitId,
            'triangulations' => count($triangulations),
            'groups' => count($enrichedGroups),
        ]);

        return $enrichedGroups;
    }

    /**
     * Find connected components using BFS
     */
    private function findConnectedComponents(array $adjacency): array
    {
        $visited = [];
        $components = [];

        foreach (array_keys($adjacency) as $node) {
            if (isset($visited[$node])) {
                continue;
            }

            $component = [];
            $queue = [$node];

            while (!empty($queue)) {
                $current = array_shift($queue);
                if (isset($visited[$current])) {
                    continue;
                }

                $visited[$current] = true;
                $component[] = $current;

                foreach (array_keys($adjacency[$current] ?? []) as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $queue[] = $neighbor;
                    }
                }
            }

            if (count($component) > 1) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * Enrich a group with match details and analysis
     */
    private function enrichGroup(int $kitId, array $matchIds, array $adjacency, int $groupNumber): ?array
    {
        if (empty($matchIds)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));

        $matches = DB::select(
            "SELECT m.id, m.match_name, m.shared_cm, m.predicted_relationship,
                    m.confirmed_relationship, m.common_ancestor_id, m.is_starred,
                    p.given_name as ancestor_given_names, p.surname as ancestor_surname
             FROM genealogy_dna_matches m
             LEFT JOIN genealogy_persons p ON p.id = m.common_ancestor_id
             WHERE m.id IN ({$placeholders})
             ORDER BY m.shared_cm DESC",
            $matchIds
        );

        // Calculate group statistics
        $totalCm = array_sum(array_column($matches, 'shared_cm'));
        $avgCm = count($matches) > 0 ? $totalCm / count($matches) : 0;

        // Identify chromosomes involved
        $chromosomes = [];
        foreach ($matchIds as $m1) {
            foreach ($adjacency[$m1] ?? [] as $m2 => $data) {
                $chromosomes[$data['chromosome']] = true;
            }
        }

        // Find common ancestors already identified
        $commonAncestors = [];
        foreach ($matches as $match) {
            if ($match->common_ancestor_id) {
                $ancestorKey = $match->common_ancestor_id;
                if (!isset($commonAncestors[$ancestorKey])) {
                    $commonAncestors[$ancestorKey] = [
                        'person_id' => $match->common_ancestor_id,
                        'name' => trim($match->ancestor_given_names . ' ' . $match->ancestor_surname),
                        'match_count' => 0,
                    ];
                }
                $commonAncestors[$ancestorKey]['match_count']++;
            }
        }

        // Calculate group cohesion (how interconnected the matches are)
        $possibleConnections = count($matchIds) * (count($matchIds) - 1) / 2;
        $actualConnections = 0;
        foreach ($matchIds as $i => $m1) {
            for ($j = $i + 1; $j < count($matchIds); $j++) {
                $m2 = $matchIds[$j];
                if (isset($adjacency[$m1][$m2])) {
                    $actualConnections++;
                }
            }
        }
        $cohesion = $possibleConnections > 0 ? round($actualConnections / $possibleConnections * 100, 1) : 0;

        // Estimate relationship to group
        $groupRelationship = $this->estimateGroupRelationship($avgCm);

        return [
            'group_number' => $groupNumber,
            'match_count' => count($matches),
            'matches' => array_map(fn($m) => [
                'id' => $m->id,
                'name' => $m->match_name,
                'shared_cm' => (float) $m->shared_cm,
                'relationship' => $m->confirmed_relationship ?? $m->predicted_relationship,
                'is_starred' => (bool) $m->is_starred,
            ], $matches),
            'statistics' => [
                'total_shared_cm' => round($totalCm, 2),
                'avg_shared_cm' => round($avgCm, 2),
                'min_shared_cm' => min(array_column($matches, 'shared_cm')),
                'max_shared_cm' => max(array_column($matches, 'shared_cm')),
            ],
            'chromosomes' => array_keys($chromosomes),
            'chromosome_count' => count($chromosomes),
            'cohesion_percent' => $cohesion,
            'estimated_relationship' => $groupRelationship,
            'common_ancestors' => array_values($commonAncestors),
            'common_ancestor_count' => count($commonAncestors),
        ];
    }

    /**
     * Estimate relationship level for a group based on average shared cM
     */
    private function estimateGroupRelationship(float $avgCm): string
    {
        if ($avgCm >= 1700) {
            return 'close_family';
        }
        if ($avgCm >= 400) {
            return 'first_cousins';
        }
        if ($avgCm >= 100) {
            return 'second_cousins';
        }
        if ($avgCm >= 50) {
            return 'third_cousins';
        }
        if ($avgCm >= 20) {
            return 'fourth_cousins';
        }
        return 'distant_cousins';
    }

    /**
     * Store a triangulation group in the database
     */
    private function storeGroup(int $kitId, array $group): int
    {
        // Check for existing group with same matches
        $matchIds = array_column($group['matches'], 'id');
        sort($matchIds);
        $matchHash = md5(implode(',', $matchIds));

        $existing = DB::selectOne(
            "SELECT id FROM genealogy_dna_triangulation_groups WHERE kit_id = ? AND match_hash = ?",
            [$kitId, $matchHash]
        );

        if ($existing) {
            // Update existing
            DB::update(
                "UPDATE genealogy_dna_triangulation_groups SET
                    group_data = ?, avg_shared_cm = ?, chromosome_count = ?,
                    cohesion_percent = ?, estimated_relationship = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    json_encode($group),
                    $group['statistics']['avg_shared_cm'],
                    $group['chromosome_count'],
                    $group['cohesion_percent'],
                    $group['estimated_relationship'],
                    $existing->id,
                ]
            );
            return $existing->id;
        }

        DB::insert(
            "INSERT INTO genealogy_dna_triangulation_groups
             (kit_id, group_number, match_count, match_hash, match_ids, group_data,
              avg_shared_cm, chromosome_count, cohesion_percent, estimated_relationship, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $kitId,
                $group['group_number'],
                $group['match_count'],
                $matchHash,
                json_encode($matchIds),
                json_encode($group),
                $group['statistics']['avg_shared_cm'],
                $group['chromosome_count'],
                $group['cohesion_percent'],
                $group['estimated_relationship'],
            ]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Get all triangulation groups for a kit
     *
     * @param int $kitId Kit ID
     * @param array $options Filter options
     * @return array Groups
     */
    public function getGroups(int $kitId, array $options = []): array
    {
        $sql = "SELECT * FROM genealogy_dna_triangulation_groups WHERE kit_id = ?";
        $params = [$kitId];

        if (!empty($options['min_matches'])) {
            $sql .= " AND match_count >= ?";
            $params[] = $options['min_matches'];
        }

        if (!empty($options['relationship'])) {
            $sql .= " AND estimated_relationship = ?";
            $params[] = $options['relationship'];
        }

        $sql .= " ORDER BY avg_shared_cm DESC";

        if (!empty($options['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $options['limit'];
        }

        $groups = DB::select($sql, $params);

        return array_map(function ($g) {
            $data = json_decode($g->group_data, true);
            $data['id'] = $g->id;
            return $data;
        }, $groups);
    }

    /**
     * Get a specific triangulation group with full details
     *
     * @param int $groupId Group ID
     * @return array|null Group details
     */
    public function getGroup(int $groupId): ?array
    {
        $group = DB::selectOne(
            "SELECT * FROM genealogy_dna_triangulation_groups WHERE id = ?",
            [$groupId]
        );

        if (!$group) {
            return null;
        }

        $data = json_decode($group->group_data, true);
        $data['id'] = $group->id;
        $data['kit_id'] = $group->kit_id;

        // Get fresh match data
        $matchIds = json_decode($group->match_ids, true);
        if (!empty($matchIds)) {
            $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
            $matches = DB::select(
                "SELECT m.*, p.given_name as ancestor_given_names, p.surname as ancestor_surname
                 FROM genealogy_dna_matches m
                 LEFT JOIN genealogy_persons p ON p.id = m.common_ancestor_id
                 WHERE m.id IN ({$placeholders})
                 ORDER BY m.shared_cm DESC",
                $matchIds
            );

            $data['matches'] = array_map(fn($m) => [
                'id' => $m->id,
                'name' => $m->match_name,
                'shared_cm' => (float) $m->shared_cm,
                'shared_segments' => $m->shared_segments,
                'predicted_relationship' => $m->predicted_relationship,
                'confirmed_relationship' => $m->confirmed_relationship,
                'common_ancestor_id' => $m->common_ancestor_id,
                'common_ancestor_name' => $m->common_ancestor_id
                    ? trim($m->ancestor_given_names . ' ' . $m->ancestor_surname)
                    : null,
                'is_starred' => (bool) $m->is_starred,
                'notes' => $m->notes,
            ], $matches);
        }

        return $data;
    }

    /**
     * Suggest potential common ancestors for a triangulation group
     *
     * @param int $groupId Group ID
     * @return array Suggested ancestors
     */
    public function suggestCommonAncestors(int $groupId): array
    {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return [];
        }

        $matchIds = array_column($group['matches'], 'id');
        if (empty($matchIds)) {
            return [];
        }

        // Get any existing common ancestors
        $existing = [];
        foreach ($group['matches'] as $match) {
            if ($match['common_ancestor_id']) {
                $existing[$match['common_ancestor_id']] = $match['common_ancestor_name'];
            }
        }

        // Look for ancestor hints from matches
        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
        $hints = DB::select(
            "SELECT m.shared_ancestor_hints FROM genealogy_dna_matches m
             WHERE m.id IN ({$placeholders}) AND m.shared_ancestor_hints IS NOT NULL",
            $matchIds
        );

        $hintedAncestors = [];
        foreach ($hints as $hint) {
            $ancestors = json_decode($hint->shared_ancestor_hints, true) ?? [];
            foreach ($ancestors as $ancestor) {
                $key = $ancestor['name'] ?? $ancestor;
                if (!isset($hintedAncestors[$key])) {
                    $hintedAncestors[$key] = 0;
                }
                $hintedAncestors[$key]++;
            }
        }

        // Sort by frequency
        arsort($hintedAncestors);

        // Estimate relationship level for the group
        $avgCm = $group['statistics']['avg_shared_cm'] ?? 0;
        $estimatedGenerations = $this->estimateGenerationsBack($avgCm);

        return [
            'group_id' => $groupId,
            'match_count' => count($matchIds),
            'existing_common_ancestors' => $existing,
            'hinted_ancestors' => $hintedAncestors,
            'estimated_generations_back' => $estimatedGenerations,
            'search_suggestion' => "Look for ancestors approximately {$estimatedGenerations} generations back " .
                                   "who lived in areas where multiple matches have connections.",
        ];
    }

    /**
     * Estimate generations back based on shared cM
     */
    private function estimateGenerationsBack(float $avgCm): int
    {
        if ($avgCm >= 1700) {
            return 1;
        }
        if ($avgCm >= 800) {
            return 2;
        }
        if ($avgCm >= 400) {
            return 3;
        }
        if ($avgCm >= 100) {
            return 4;
        }
        if ($avgCm >= 50) {
            return 5;
        }
        if ($avgCm >= 25) {
            return 6;
        }
        return 7;
    }

    /**
     * Assign a common ancestor to all matches in a group
     *
     * @param int $groupId Group ID
     * @param int $ancestorId Person ID of common ancestor
     * @return int Number of matches updated
     */
    public function assignCommonAncestor(int $groupId, int $ancestorId): int
    {
        $group = DB::selectOne(
            "SELECT match_ids FROM genealogy_dna_triangulation_groups WHERE id = ?",
            [$groupId]
        );

        if (!$group) {
            return 0;
        }

        $matchIds = json_decode($group->match_ids, true);
        if (empty($matchIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
        $params = array_merge([$ancestorId], $matchIds);

        $updated = DB::update(
            "UPDATE genealogy_dna_matches SET common_ancestor_id = ?, updated_at = NOW()
             WHERE id IN ({$placeholders})",
            $params
        );

        Log::info('TriangulationGroup: Assigned common ancestor', [
            'group_id' => $groupId,
            'ancestor_id' => $ancestorId,
            'matches_updated' => $updated,
        ]);

        return $updated;
    }

    /**
     * Get cluster visualization data for chromosome painting
     *
     * @param int $kitId Kit ID
     * @return array Visualization data
     */
    public function getClusterVisualization(int $kitId): array
    {
        $groups = $this->getGroups($kitId);

        // Assign colors to groups
        $colors = [
            '#e6194B', '#3cb44b', '#ffe119', '#4363d8', '#f58231',
            '#911eb4', '#42d4f4', '#f032e6', '#bfef45', '#fabed4',
            '#469990', '#dcbeff', '#9A6324', '#fffac8', '#800000',
            '#aaffc3', '#808000', '#ffd8b1', '#000075', '#a9a9a9',
        ];

        $visualData = [
            'kit_id' => $kitId,
            'groups' => [],
            'chromosome_segments' => [],
        ];

        foreach ($groups as $index => $group) {
            $color = $colors[$index % count($colors)];

            $visualData['groups'][] = [
                'id' => $group['id'] ?? $index,
                'group_number' => $group['group_number'],
                'match_count' => $group['match_count'],
                'color' => $color,
                'estimated_relationship' => $group['estimated_relationship'],
                'chromosomes' => $group['chromosomes'],
            ];

            // Get segments for this group's matches
            $matchIds = array_column($group['matches'], 'id');
            if (!empty($matchIds)) {
                $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
                $segments = DB::select(
                    "SELECT s.*, m.match_name
                     FROM genealogy_dna_segments s
                     JOIN genealogy_dna_matches m ON m.id = s.match_id
                     WHERE s.match_id IN ({$placeholders})
                     ORDER BY s.chromosome, s.start_position",
                    $matchIds
                );

                foreach ($segments as $seg) {
                    $visualData['chromosome_segments'][] = [
                        'chromosome' => $seg->chromosome,
                        'start' => $seg->start_position,
                        'end' => $seg->end_position,
                        'cm' => (float) $seg->cm_length,
                        'match_id' => $seg->match_id,
                        'match_name' => $seg->match_name,
                        'group_index' => $index,
                        'color' => $color,
                    ];
                }
            }
        }

        return $visualData;
    }

    /**
     * Get statistics about triangulation groups for a kit
     *
     * @param int $kitId Kit ID
     * @return array Statistics
     */
    public function getStatistics(int $kitId): array
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total_groups,
                SUM(match_count) as total_matches_in_groups,
                AVG(match_count) as avg_group_size,
                MAX(match_count) as largest_group,
                AVG(cohesion_percent) as avg_cohesion,
                AVG(chromosome_count) as avg_chromosomes
             FROM genealogy_dna_triangulation_groups
             WHERE kit_id = ?",
            [$kitId]
        );

        $byRelationship = DB::select(
            "SELECT estimated_relationship, COUNT(*) as count
             FROM genealogy_dna_triangulation_groups
             WHERE kit_id = ?
             GROUP BY estimated_relationship
             ORDER BY count DESC",
            [$kitId]
        );

        $totalMatches = DB::selectOne(
            "SELECT COUNT(*) as count FROM genealogy_dna_matches WHERE kit_id = ?",
            [$kitId]
        );

        return [
            'total_groups' => (int) ($stats->total_groups ?? 0),
            'total_matches_in_groups' => (int) ($stats->total_matches_in_groups ?? 0),
            'total_matches' => (int) ($totalMatches->count ?? 0),
            'coverage_percent' => $totalMatches->count > 0
                ? round(($stats->total_matches_in_groups / $totalMatches->count) * 100, 1)
                : 0,
            'avg_group_size' => round($stats->avg_group_size ?? 0, 1),
            'largest_group' => (int) ($stats->largest_group ?? 0),
            'avg_cohesion_percent' => round($stats->avg_cohesion ?? 0, 1),
            'avg_chromosomes_per_group' => round($stats->avg_chromosomes ?? 0, 1),
            'by_relationship' => array_column(
                array_map(fn($r) => ['relationship' => $r->estimated_relationship, 'count' => $r->count], $byRelationship),
                'count', 'relationship'
            ),
        ];
    }
}

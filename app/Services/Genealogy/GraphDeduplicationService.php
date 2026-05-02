<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N99 — Relationship Graph Deduplication (BYU Wilson 2001 method)
 *
 * Disambiguates common-name persons by using shared rare-surname relatives
 * as graph anchors. Two "John Smith" records that share a parent "Elijah Bennyhoff"
 * are almost certainly the same person — the rare surname is the anchor.
 *
 * Algorithm:
 * 1. Find all persons with name frequency above threshold ("common names")
 * 2. For each common-name group, build a bipartite graph:
 *    - person nodes on one side
 *    - relative-name nodes (surname × generation) on the other
 * 3. Persons that share ≥1 rare-surname relative (frequency < RARE_THRESHOLD)
 *    are candidate duplicates
 * 4. Score by: shared rare relatives × birth year proximity × place proximity
 * 5. Output: scored duplicate pairs for human review
 *
 * Reference: Wilson, E.G. (2001). "Disambiguating genealogical records using
 * relative graph matching." Brigham Young University Computer Science Dept.
 */
class GraphDeduplicationService
{
    /** Surname frequency threshold: if surname appears < N times in tree → "rare" */
    private const RARE_THRESHOLD = 5;

    /** Minimum score to flag as candidate duplicate */
    private const MIN_SCORE = 0.65;

    /**
     * Find potential duplicate persons using graph-anchor deduplication.
     *
     * @param int $treeId
     * @param int $limit  Maximum candidates to return
     * @return array ['candidates' => [...], 'total' => int]
     */
    public function findGraphDuplicates(int $treeId, int $limit = 50): array
    {
        // Step 1: Build surname frequency map for this tree
        $surnameFreq = $this->buildSurnameFrequency($treeId);
        $rareSurnames = array_keys(array_filter($surnameFreq, fn($f) => $f < self::RARE_THRESHOLD));

        if (empty($rareSurnames)) {
            return ['candidates' => [], 'total' => 0, 'message' => 'No rare surnames found in tree'];
        }

        // Step 2: Find common-name groups (persons with same given+surname, multiple instances)
        $commonNameGroups = $this->findCommonNameGroups($treeId, $limit);

        if (empty($commonNameGroups)) {
            return ['candidates' => [], 'total' => 0, 'message' => 'No common-name duplicates found'];
        }

        $candidates = [];

        foreach ($commonNameGroups as $group) {
            $personIds = array_column($group['persons'], 'id');
            if (count($personIds) < 2) continue;

            // Step 3: Get rare-surname relatives for each person in the group
            $personRelatives = [];
            foreach ($personIds as $pid) {
                $personRelatives[$pid] = $this->getRareSurnameRelatives($pid, $rareSurnames);
            }

            // Step 4: Score all pairs within the group
            for ($i = 0; $i < count($personIds); $i++) {
                for ($j = $i + 1; $j < count($personIds); $j++) {
                    $pidA = $personIds[$i];
                    $pidB = $personIds[$j];

                    $score = $this->scorePair(
                        $group['persons'][$i],
                        $group['persons'][$j],
                        $personRelatives[$pidA],
                        $personRelatives[$pidB]
                    );

                    if ($score >= self::MIN_SCORE) {
                        $candidates[] = [
                            'person_a' => $group['persons'][$i],
                            'person_b' => $group['persons'][$j],
                            'score' => round($score, 3),
                            'shared_rare_relatives' => $this->sharedRelatives($personRelatives[$pidA], $personRelatives[$pidB]),
                            'method' => 'graph_anchor_byu2001',
                        ];
                    }
                }
            }
        }

        // Sort by score descending
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, $limit);

        Log::info('GraphDeduplicationService: Scan complete', [
            'tree_id' => $treeId,
            'common_name_groups' => count($commonNameGroups),
            'candidates' => count($candidates),
        ]);

        return [
            'tree_id' => $treeId,
            'rare_surname_count' => count($rareSurnames),
            'common_name_groups' => count($commonNameGroups),
            'candidates' => $candidates,
            'total' => count($candidates),
        ];
    }

    private function buildSurnameFrequency(int $treeId): array
    {
        $rows = DB::select("
            SELECT surname, COUNT(*) AS frequency
            FROM genealogy_persons
            WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
            GROUP BY surname
        ", [$treeId]);

        $freq = [];
        foreach ($rows as $row) {
            $freq[$row->surname] = (int) $row->frequency;
        }
        return $freq;
    }

    private function findCommonNameGroups(int $treeId, int $limit = 100): array
    {
        $rows = DB::select("
            SELECT given_name, surname,
                   COUNT(*) AS count,
                   GROUP_CONCAT(id) AS person_ids
            FROM genealogy_persons
            WHERE tree_id = ? AND given_name IS NOT NULL AND surname IS NOT NULL
            GROUP BY given_name, surname
            HAVING COUNT(*) >= 2
            ORDER BY COUNT(*) DESC
            LIMIT ?
        ", [$treeId, $limit]);

        $groups = [];
        foreach ($rows as $row) {
            $ids = explode(',', $row->person_ids);
            $persons = [];
            foreach ($ids as $pid) {
                $p = DB::selectOne(
                    "SELECT id, given_name, surname, birth_date, birth_place, death_date, death_place
                     FROM genealogy_persons WHERE id = ?",
                    [(int) $pid]
                );
                if ($p) $persons[] = (array) $p;
            }
            $groups[] = [
                'name' => "{$row->given_name} {$row->surname}",
                'count' => (int) $row->count,
                'persons' => $persons,
            ];
        }
        return $groups;
    }

    private function getRareSurnameRelatives(int $personId, array $rareSurnames): array
    {
        if (empty($rareSurnames)) return [];

        $placeholders = implode(',', array_fill(0, count($rareSurnames), '?'));

        return DB::select("
            SELECT DISTINCT p.id, p.given_name, p.surname, 'spouse' AS rel_type
            FROM genealogy_families f
            JOIN genealogy_persons p ON (
                (f.husband_id = ? AND p.id = f.wife_id) OR
                (f.wife_id = ? AND p.id = f.husband_id)
            )
            WHERE p.surname IN ({$placeholders})
            UNION
            SELECT DISTINCT p.id, p.given_name, p.surname, 'parent' AS rel_type
            FROM genealogy_children gc
            JOIN genealogy_families f ON f.id = gc.family_id
            JOIN genealogy_persons p ON p.id IN (f.husband_id, f.wife_id)
            WHERE gc.person_id = ? AND p.surname IN ({$placeholders})
        ", array_merge([$personId, $personId], $rareSurnames, [$personId], $rareSurnames));
    }

    private function scorePair(array $pA, array $pB, array $relA, array $relB): float
    {
        $score = 0.0;

        // Shared rare relatives (highest weight — Wilson 2001 anchor signal)
        $shared = $this->sharedRelatives($relA, $relB);
        if (!empty($shared)) {
            $score += min(count($shared) * 0.30, 0.60); // up to 0.60 for 2+ shared
        }

        // Birth year proximity
        $yearA = $this->extractYear($pA['birth_date'] ?? '');
        $yearB = $this->extractYear($pB['birth_date'] ?? '');
        if ($yearA && $yearB) {
            $diff = abs($yearA - $yearB);
            if ($diff === 0) $score += 0.20;
            elseif ($diff <= 5) $score += 0.10;
        }

        // Birth place similarity
        $placeA = strtolower($pA['birth_place'] ?? '');
        $placeB = strtolower($pB['birth_place'] ?? '');
        if ($placeA && $placeB) {
            if ($placeA === $placeB) $score += 0.20;
            elseif (str_contains($placeA, $placeB) || str_contains($placeB, $placeA)) $score += 0.10;
        }

        return $score;
    }

    private function sharedRelatives(array $relA, array $relB): array
    {
        $idsA = array_column($relA, 'id');
        $idsB = array_column($relB, 'id');
        return array_values(array_filter($relA, fn($r) => in_array($r->id, $idsB)));
    }

    private function extractYear(string $date): ?int
    {
        if (preg_match('/(\d{4})/', $date, $m)) {
            $y = (int) $m[1];
            return ($y >= 1500 && $y <= 2100) ? $y : null;
        }
        return null;
    }
}

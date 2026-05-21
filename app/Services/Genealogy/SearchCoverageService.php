<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N97/N71 — Negative Evidence Coverage Model (GPS Element 1)
 *
 * GPS Element 1 requires exhaustive search documentation: not just what was found,
 * but what was searched and found nothing.
 *
 * This service maintains genealogy_search_coverage — a per-repository-type
 * tracking table summarizing search history for each person.
 *
 * N71 additions:
 *   - getCoverageForTree(): aggregate stats across all persons in a tree
 *   - getGapReport(): persons with critical repo-type gaps, priority-ordered
 */
class SearchCoverageService
{
    private const VALID_REPO_TYPES = [
        'vital_records', 'census', 'church', 'military', 'immigration',
        'land', 'probate', 'newspaper', 'cemetery', 'dna', 'newspaper_digital',
        'family_tree_aggregator', 'state_archives', 'county_records', 'other',
    ];

    /**
     * Get the search coverage map for a person.
     * Returns per-repository-type search history with GPS compliance flags.
     */
    public function getCoverageForPerson(int $personId): array
    {
        $coverage = DB::select("
            SELECT sc.*,
                   ROUND((positive_count / GREATEST(search_count, 1)) * 100, 1) AS positive_rate_pct
            FROM genealogy_search_coverage sc
            WHERE sc.person_id = ?
            ORDER BY sc.search_count DESC
        ", [$personId]);

        // Compute which core repository types are still uncovered
        $coveredTypes = array_map(fn($r) => $r->repository_type, $coverage);
        $coreTypes = ['vital_records', 'census', 'church', 'military', 'immigration', 'land', 'probate', 'newspaper'];
        $uncoveredTypes = array_values(array_diff($coreTypes, $coveredTypes));

        $totalSearches   = array_sum(array_column($coverage, 'search_count'));
        $satisfiedCount  = count(array_filter($coverage, fn($r) => $r->gps_satisfactory));

        return [
            'person_id' => $personId,
            'total_searches' => $totalSearches,
            'repositories_covered' => count($coverage),
            'gps_satisfactory_count' => $satisfiedCount,
            'core_uncovered' => $uncoveredTypes,
            'gps_coverage_complete' => empty($uncoveredTypes),
            'coverage' => $coverage,
        ];
    }

    /**
     * Update (or create) a coverage record after a search.
     * Call after every search — positive or negative.
     *
     * @param int    $personId
     * @param string $repositoryType  One of VALID_REPO_TYPES
     * @param string $repositoryName  Specific repository (e.g. "FamilySearch", "NARA")
     * @param bool   $positive        true = found results, false = negative
     * @param string|null $notes      What was searched, coverage gaps, access issues
     * @return array ['success' => bool]
     */
    public function updateCoverage(
        int $personId,
        string $repositoryType,
        string $repositoryName,
        bool $positive,
        ?string $notes = null
    ): array {
        if (!in_array($repositoryType, self::VALID_REPO_TYPES)) {
            $repositoryType = 'other';
        }

        $person = DB::selectOne("SELECT tree_id FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            return ['success' => false, 'error' => 'Person not found'];
        }

        try {
            DB::statement("
                INSERT INTO genealogy_search_coverage
                    (person_id, tree_id, repository_type, repository_name,
                     search_count, positive_count, negative_count,
                     coverage_notes, last_searched_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    search_count   = search_count + 1,
                    positive_count = positive_count + VALUES(positive_count),
                    negative_count = negative_count + VALUES(negative_count),
                    last_searched_at = NOW(),
                    coverage_notes = CASE
                        WHEN VALUES(coverage_notes) IS NOT NULL
                        THEN CONCAT(COALESCE(coverage_notes, ''), IF(coverage_notes IS NOT NULL, '\\n', ''), VALUES(coverage_notes))
                        ELSE coverage_notes
                    END,
                    updated_at = NOW()
            ", [
                $personId,
                (int) $person->tree_id,
                $repositoryType,
                $repositoryName,
                (int) $positive,
                (int) !$positive,
                $notes,
            ]);

            return ['success' => true, 'repository_type' => $repositoryType, 'positive' => $positive];
        } catch (\Exception $e) {
            Log::warning('SearchCoverageService: updateCoverage failed', [
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Mark a repository coverage entry as GPS-satisfactory.
     * Call when agent or human determines this repository type has been adequately covered.
     */
    public function markSatisfactory(int $personId, string $repositoryType, string $repositoryName): bool
    {
        return DB::update("
            UPDATE genealogy_search_coverage
            SET gps_satisfactory = 1, updated_at = NOW()
            WHERE person_id = ? AND repository_type = ? AND repository_name = ?
        ", [$personId, $repositoryType, $repositoryName]) > 0;
    }

    /**
     * Backfill coverage records from existing gps_research_logs for a tree.
     * Run once after migration to populate from existing data.
     */
    public function backfillFromResearchLogs(int $treeId): array
    {
        $logs = DB::select("
            SELECT rl.person_id, p.tree_id,
                   rl.repository_searched,
                   SUM(CASE WHEN rl.negative_result = 0 THEN 1 ELSE 0 END) AS positive_count,
                   SUM(rl.negative_result) AS negative_count,
                   COUNT(*) AS search_count,
                   MAX(rl.searched_at) AS last_searched
            FROM gps_research_logs rl
            JOIN genealogy_persons p ON p.id = rl.person_id
            WHERE p.tree_id = ?
            GROUP BY rl.person_id, p.tree_id, rl.repository_searched
        ", [$treeId]);

        $upserted = 0;
        foreach ($logs as $row) {
            $repoType = $this->inferRepositoryType($row->repository_searched ?? '');
            try {
                DB::statement("
                    INSERT INTO genealogy_search_coverage
                        (person_id, tree_id, repository_type, repository_name,
                         search_count, positive_count, negative_count, last_searched_at,
                         created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        search_count   = search_count + VALUES(search_count),
                        positive_count = positive_count + VALUES(positive_count),
                        negative_count = negative_count + VALUES(negative_count),
                        last_searched_at = GREATEST(last_searched_at, VALUES(last_searched_at)),
                        updated_at = NOW()
                ", [
                    $row->person_id, $row->tree_id, $repoType,
                    $row->repository_searched,
                    $row->search_count, $row->positive_count, $row->negative_count,
                    $row->last_searched,
                ]);
                $upserted++;
            } catch (\Exception $e) {
                Log::warning('SearchCoverageService: backfill upsert failed', ['error' => $e->getMessage()]);
            }
        }

        return ['tree_id' => $treeId, 'log_groups' => count($logs), 'upserted' => $upserted];
    }

    /**
     * Aggregate coverage statistics for an entire tree.
     *
     * Returns:
     *   total_persons, persons_with_any_coverage, total_searches,
     *   gps_complete_persons (all 8 core types covered),
     *   per_type breakdown (covered_persons, total_searches, positive_rate_pct),
     *   core_uncovered_summary (how many persons missing each core type)
     */
    public function getCoverageForTree(int $treeId): array
    {
        $totalPersons = DB::selectOne(
            "SELECT COUNT(*) AS cnt FROM genealogy_persons WHERE tree_id = ?",
            [$treeId]
        )->cnt ?? 0;

        $personStats = DB::select("
            SELECT person_id,
                   COUNT(DISTINCT repository_type) AS repo_types_covered,
                   SUM(search_count) AS total_searches,
                   SUM(positive_count) AS total_positive,
                   SUM(negative_count) AS total_negative
            FROM genealogy_search_coverage
            WHERE tree_id = ?
            GROUP BY person_id
        ", [$treeId]);

        $personsWithCoverage = count($personStats);
        $totalSearches = array_sum(array_column($personStats, 'total_searches'));

        // Per-type breakdown
        $typeStats = DB::select("
            SELECT repository_type,
                   COUNT(DISTINCT person_id) AS covered_persons,
                   SUM(search_count) AS total_searches,
                   SUM(positive_count) AS total_positive,
                   SUM(gps_satisfactory) AS gps_satisfactory_count
            FROM genealogy_search_coverage
            WHERE tree_id = ?
            GROUP BY repository_type
            ORDER BY covered_persons DESC
        ", [$treeId]);

        $typeBreakdown = [];
        foreach ($typeStats as $t) {
            $t = (array) $t;
            $typeBreakdown[$t['repository_type']] = [
                'covered_persons'       => (int) $t['covered_persons'],
                'total_searches'        => (int) $t['total_searches'],
                'positive_rate_pct'     => $t['total_searches'] > 0
                    ? round(($t['total_positive'] / $t['total_searches']) * 100, 1)
                    : 0,
                'gps_satisfactory_count' => (int) $t['gps_satisfactory_count'],
            ];
        }

        // How many persons are missing each core type
        $coreTypes = ['vital_records', 'census', 'church', 'military', 'immigration', 'land', 'probate', 'newspaper'];
        $coreGaps = [];
        foreach ($coreTypes as $type) {
            $covered = $typeBreakdown[$type]['covered_persons'] ?? 0;
            $coreGaps[$type] = [
                'covered_persons' => $covered,
                'missing_persons' => max(0, $totalPersons - $covered),
                'coverage_pct'    => $totalPersons > 0 ? round(($covered / $totalPersons) * 100, 1) : 0,
            ];
        }

        // GPS complete = all 8 core types documented for a person
        $coveredTypeSets = [];
        $coveredRows = DB::select("
            SELECT person_id, GROUP_CONCAT(repository_type) AS types
            FROM genealogy_search_coverage
            WHERE tree_id = ? AND repository_type IN (
                'vital_records','census','church','military','immigration','land','probate','newspaper'
            )
            GROUP BY person_id
        ", [$treeId]);
        $gpsCompleteCount = 0;
        foreach ($coveredRows as $row) {
            $types = explode(',', $row->types);
            if (count(array_intersect($coreTypes, $types)) === count($coreTypes)) {
                $gpsCompleteCount++;
            }
        }

        return [
            'tree_id'                => $treeId,
            'total_persons'          => (int) $totalPersons,
            'persons_with_coverage'  => $personsWithCoverage,
            'total_searches'         => (int) $totalSearches,
            'gps_complete_persons'   => $gpsCompleteCount,
            'gps_complete_pct'       => $totalPersons > 0 ? round(($gpsCompleteCount / $totalPersons) * 100, 1) : 0,
            'by_type'                => $typeBreakdown,
            'core_gaps'              => $coreGaps,
        ];
    }

    /**
     * Return persons with critical coverage gaps, priority-ordered.
     *
     * Persons are ranked by: most existing coverage (evidence present) but missing
     * the requested repository type — these are highest-value targets for next searches.
     *
     * @param int    $treeId
     * @param string|null $repositoryType  Filter to a specific gap type, or null for all core types
     * @param int    $limit
     * @return array ['gaps' => [...], 'repository_type' => string|null, 'total_gap_persons' => int]
     */
    public function getGapReport(int $treeId, ?string $repositoryType = null, int $limit = 50): array
    {
        if ($repositoryType && !in_array($repositoryType, self::VALID_REPO_TYPES)) {
            $repositoryType = null;
        }

        $coreTypes = ['vital_records', 'census', 'church', 'military', 'immigration', 'land', 'probate', 'newspaper'];
        $targetTypes = $repositoryType ? [$repositoryType] : $coreTypes;
        $placeholders = implode(',', array_fill(0, count($targetTypes), '?'));

        // Find persons who are missing one or more target types.
        // Keep the alias filter in an outer query so the report works across
        // both MySQL and SQLite-backed regression tests.
        $gaps = DB::select("
            SELECT *
            FROM (
                SELECT p.id AS person_id,
                       p.given_name,
                       p.surname,
                       p.birth_date,
                       p.death_date,
                       COALESCE(existing.repo_types_covered, 0) AS repo_types_covered,
                       COALESCE(existing.total_searches, 0) AS total_searches,
                       (
                           SELECT COUNT(*) FROM (
                               SELECT DISTINCT sc2.repository_type
                               FROM genealogy_search_coverage sc2
                               WHERE sc2.person_id = p.id AND sc2.repository_type IN ({$placeholders})
                           ) covered_sub
                       ) AS target_types_covered
                FROM genealogy_persons p
                LEFT JOIN (
                    SELECT person_id,
                           COUNT(DISTINCT repository_type) AS repo_types_covered,
                           SUM(search_count) AS total_searches
                    FROM genealogy_search_coverage
                    WHERE tree_id = ?
                    GROUP BY person_id
                ) existing ON existing.person_id = p.id
                WHERE p.tree_id = ?
            ) gap_rows
            WHERE target_types_covered < ?
            ORDER BY total_searches DESC, repo_types_covered DESC, given_name ASC, surname ASC
            LIMIT ?
        ", array_merge($targetTypes, [$treeId, $treeId, count($targetTypes), $limit]));

        $result = [];
        foreach ($gaps as $row) {
            $row = (array) $row;
            // Which specific types are missing for this person?
            $covered = DB::select("
                SELECT DISTINCT repository_type
                FROM genealogy_search_coverage
                WHERE person_id = ? AND repository_type IN ({$placeholders})
            ", array_merge([(int)$row['person_id']], $targetTypes));
            $coveredTypes = array_column($covered, 'repository_type');
            $missing = array_values(array_diff($targetTypes, $coveredTypes));

            $result[] = [
                'person_id'          => (int) $row['person_id'],
                'person_name'        => trim(($row['given_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
                'birth_date'         => $row['birth_date'],
                'death_date'         => $row['death_date'],
                'total_searches'     => (int) $row['total_searches'],
                'repo_types_covered' => (int) $row['repo_types_covered'],
                'missing_types'      => $missing,
                'gap_count'          => count($missing),
            ];
        }

        return [
            'tree_id'          => $treeId,
            'repository_type'  => $repositoryType,
            'total_gap_persons' => count($result),
            'gaps'             => $result,
        ];
    }

    private function inferRepositoryType(string $repoName): string
    {
        $lower = strtolower($repoName);
        return match (true) {
            str_contains($lower, 'census') => 'census',
            str_contains($lower, 'vital') || str_contains($lower, 'birth') || str_contains($lower, 'death') => 'vital_records',
            str_contains($lower, 'church') || str_contains($lower, 'parish') || str_contains($lower, 'bapti') => 'church',
            str_contains($lower, 'military') || str_contains($lower, 'draft') || str_contains($lower, 'veteran') => 'military',
            str_contains($lower, 'passenger') || str_contains($lower, 'immigration') || str_contains($lower, 'naturali') => 'immigration',
            str_contains($lower, 'land') || str_contains($lower, 'deed') || str_contains($lower, 'grantor') => 'land',
            str_contains($lower, 'probate') || str_contains($lower, 'will') || str_contains($lower, 'estate') => 'probate',
            str_contains($lower, 'newspaper') || str_contains($lower, 'chronicling') || str_contains($lower, 'chronicl') => 'newspaper',
            str_contains($lower, 'cemetery') || str_contains($lower, 'findagrave') => 'cemetery',
            str_contains($lower, 'dna') || str_contains($lower, 'ancestry dna') => 'dna',
            str_contains($lower, 'familysearch') || str_contains($lower, 'ancestry') || str_contains($lower, 'myheritage') => 'family_tree_aggregator',
            str_contains($lower, 'archive') || str_contains($lower, 'nara') => 'state_archives',
            default => 'other',
        };
    }
}

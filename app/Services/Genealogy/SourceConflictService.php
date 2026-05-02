<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N95 — Source Conflict Detection (GPS Element 4)
 *
 * Detects and records conflicts between sources claiming different facts for
 * the same person. GPS Element 4 requires resolving conflicting evidence
 * before reaching a genealogical conclusion.
 *
 * Currently conflicts are silent — this makes them visible and actionable.
 */
class SourceConflictService
{
    // Fields that should be compared for conflicts
    private const COMPARABLE_FIELDS = [
        'birth_date', 'birth_place', 'death_date', 'death_place',
        'burial_date', 'burial_place', 'occupation', 'religion',
        'given_name', 'surname', 'sex',
    ];

    /**
     * Detect fact conflicts across all sources for a person.
     * Compares data from genealogy_citations linked to genealogy_sources.
     *
     * @param int $personId
     * @return array ['new_conflicts' => int, 'conflicts' => array]
     */
    public function detectConflictsForPerson(int $personId): array
    {
        // Get person base facts
        $person = DB::selectOne(
            "SELECT id, tree_id, given_name, surname, sex, birth_date, birth_place,
                    death_date, death_place, burial_date, burial_place, occupation, religion
             FROM genealogy_persons WHERE id = ?",
            [$personId]
        );
        if (!$person) {
            return ['new_conflicts' => 0, 'conflicts' => [], 'error' => 'Person not found'];
        }

        // Get all citation-linked source data for this person
        // citation_data column does not exist in prod genealogy_citations — NULL placeholder
        $citations = DB::select("
            SELECT c.id AS citation_id, c.source_id, NULL AS citation_data,
                   s.title AS source_title, s.source_quality, s.information_quality,
                   s.url AS source_url
            FROM genealogy_citations c
            JOIN genealogy_sources s ON s.id = c.source_id
            WHERE c.person_id = ?
            ORDER BY s.source_quality DESC, s.information_quality DESC
        ", [$personId]);

        $newConflicts = 0;
        $allConflicts = [];

        // Compare citation data against person record and against each other
        foreach ($citations as $i => $citA) {
            $dataA = is_string($citA->citation_data) ? json_decode($citA->citation_data, true) : (array)$citA->citation_data;
            if (!is_array($dataA)) continue;

            foreach (self::COMPARABLE_FIELDS as $field) {
                if (!isset($dataA[$field])) continue;
                $valueA = trim($dataA[$field]);
                if (empty($valueA)) continue;

                // Compare against person record
                $personValue = trim($person->{$field} ?? '');
                if (!empty($personValue) && !$this->valuesAgree($field, $valueA, $personValue)) {
                    $result = $this->recordConflict(
                        $personId, (int)$person->tree_id, $field,
                        (int)$citA->source_id, $valueA, $citA->source_quality,
                        null, $personValue, null,
                        $this->severityFor($field, $valueA, $personValue),
                        'genealogy-researcher'
                    );
                    if ($result) {
                        $newConflicts++;
                        $allConflicts[] = $result;
                    }
                }

                // Compare against other citations
                foreach (array_slice($citations, $i + 1) as $citB) {
                    $dataB = is_string($citB->citation_data) ? json_decode($citB->citation_data, true) : (array)$citB->citation_data;
                    if (!is_array($dataB) || !isset($dataB[$field])) continue;
                    $valueB = trim($dataB[$field]);
                    if (empty($valueB)) continue;

                    if (!$this->valuesAgree($field, $valueA, $valueB)) {
                        $result = $this->recordConflict(
                            $personId, (int)$person->tree_id, $field,
                            (int)$citA->source_id, $valueA, $citA->source_quality,
                            (int)$citB->source_id, $valueB, $citB->source_quality,
                            $this->severityFor($field, $valueA, $valueB),
                            'genealogy-researcher'
                        );
                        if ($result) {
                            $newConflicts++;
                            $allConflicts[] = $result;
                        }
                    }
                }
            }
        }

        Log::info('SourceConflictService: Detection complete', [
            'person_id' => $personId,
            'new_conflicts' => $newConflicts,
        ]);

        return [
            'person_id' => $personId,
            'new_conflicts' => $newConflicts,
            'conflicts' => $allConflicts,
        ];
    }

    /**
     * Get conflicts for a person filtered by resolution status.
     */
    public function getConflictsForPerson(int $personId, string $status = 'unresolved'): array
    {
        $validStatuses = ['unresolved', 'resolved', 'ignored'];
        if (!in_array($status, $validStatuses)) {
            $status = 'unresolved';
        }

        $conflicts = DB::select("
            SELECT sc.*,
                   sa.title AS source_a_title, sa.url AS source_a_url,
                   sb.title AS source_b_title, sb.url AS source_b_url
            FROM genealogy_source_conflicts sc
            LEFT JOIN genealogy_sources sa ON sa.id = sc.source_a_id
            LEFT JOIN genealogy_sources sb ON sb.id = sc.source_b_id
            WHERE sc.person_id = ? AND sc.resolution_status = ?
            ORDER BY sc.conflict_severity DESC, sc.created_at DESC
        ", [$personId, $status]);

        return [
            'person_id' => $personId,
            'status' => $status,
            'total' => count($conflicts),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Determine if two values for a field are in agreement (allowing for minor variation).
     */
    private function valuesAgree(string $field, string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if ($a === $b) return true;

        // Date fields: extract years and compare within tolerance
        if (str_contains($field, 'date')) {
            $yearA = $this->extractYear($a);
            $yearB = $this->extractYear($b);
            if ($yearA && $yearB) {
                return abs($yearA - $yearB) <= 2; // ±2 years = minor discrepancy, not a conflict
            }
            return false;
        }

        // Place fields: check if one contains the other (e.g. "Pennsylvania" vs "Berks Co, PA")
        if (str_contains($field, 'place')) {
            return str_contains($a, $b) || str_contains($b, $a)
                || similar_text($a, $b) / max(strlen($a), strlen($b), 1) > 0.70;
        }

        // Name fields: allow minor spelling variants
        if ($field === 'given_name' || $field === 'surname') {
            $sim = similar_text($a, $b) / max(strlen($a), strlen($b), 1);
            return $sim > 0.80;
        }

        return false;
    }

    /**
     * Classify conflict severity.
     */
    private function severityFor(string $field, string $valueA, string $valueB): string
    {
        if (str_contains($field, 'date')) {
            $yearA = $this->extractYear($valueA);
            $yearB = $this->extractYear($valueB);
            if ($yearA && $yearB) {
                $diff = abs($yearA - $yearB);
                return match (true) {
                    $diff <= 5 => 'minor',
                    $diff <= 15 => 'moderate',
                    default => 'major',
                };
            }
            return 'moderate';
        }

        if ($field === 'sex') return 'major';
        if ($field === 'given_name' || $field === 'surname') return 'minor';

        return 'moderate';
    }

    /**
     * Insert a conflict record (deduped by UNIQUE constraint).
     * Returns the conflict data if newly inserted, null if already existed.
     */
    private function recordConflict(
        int $personId,
        int $treeId,
        string $fieldName,
        ?int $sourceAId,
        string $valueA,
        ?string $qualityA,
        ?int $sourceBId,
        string $valueB,
        ?string $qualityB,
        string $severity,
        string $detectedBy
    ): ?array {
        try {
            // Try insert (UNIQUE KEY uq_conflict prevents duplicates)
            DB::insert("
                INSERT INTO genealogy_source_conflicts
                    (person_id, tree_id, field_name, source_a_id, source_a_value, source_a_quality,
                     source_b_id, source_b_value, source_b_quality, conflict_severity, detected_by,
                     resolution_status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unresolved', NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = updated_at
            ", [
                $personId, $treeId, $fieldName,
                $sourceAId, $valueA, $qualityA,
                $sourceBId, $valueB, $qualityB,
                $severity, $detectedBy,
            ]);

            $id = (int) DB::getPdo()->lastInsertId();
            if (!$id) return null; // ON DUPLICATE — already existed

            return [
                'id' => $id,
                'field_name' => $fieldName,
                'value_a' => $valueA,
                'value_b' => $valueB,
                'severity' => $severity,
            ];
        } catch (\Exception $e) {
            Log::warning('SourceConflictService: recordConflict failed', ['error' => $e->getMessage()]);
            return null;
        }
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

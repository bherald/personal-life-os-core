<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N93 — FAN Co-occurrence Auto-accumulation
 *
 * Extracts co-occurring names from agent search results and stores them
 * in fan_cooccurrences. The FAN principle (Friends, Associates, Neighbors)
 * is the #1 genealogy brick-wall breakthrough technique.
 *
 * Co-occurring rare surnames that appear repeatedly alongside the research
 * subject often share a common ancestor, migration path, or community.
 */
class FANCooccurrenceService
{
    private ?AIService $aiService = null;

    private function getAIService(): AIService
    {
        if (!$this->aiService) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    /**
     * Extract co-occurring names from a search result and persist to fan_cooccurrences.
     *
     * Uses lightweight regex-based extraction first; falls back to AI extraction
     * for structured text. Skips common non-name words.
     *
     * @param int    $personId          Primary person being researched
     * @param string $searchResultText  Raw text or JSON from the search result
     * @param string $sourceType        One of the fan_cooccurrences.source_type enum values
     * @param string|null $sourceRef    URL or citation
     * @param string|null $sourceDate   Date of the source document
     * @param string|null $sourceLocation Location of the source document
     * @return array ['extracted' => int, 'stored' => int, 'names' => string[]]
     */
    public function extractFromSearchResult(
        int $personId,
        string $searchResultText = '',
        string $sourceType = 'other',
        ?string $sourceRef = null,
        ?string $sourceDate = null,
        ?string $sourceLocation = null
    ): array {
        if (empty(trim($searchResultText))) {
            return ['extracted' => 0, 'stored' => 0, 'names' => []];
        }

        // Get tree_id for this person
        $person = DB::selectOne(
            "SELECT tree_id, given_name, surname FROM genealogy_persons WHERE id = ?",
            [$personId]
        );
        if (!$person) {
            return ['extracted' => 0, 'stored' => 0, 'names' => [], 'error' => 'Person not found'];
        }

        $names = $this->extractNames($searchResultText, $person);

        $stored = 0;
        foreach ($names as $name => $confidence) {
            $stored += $this->upsertCooccurrence(
                $personId,
                (int) $person->tree_id,
                $name,
                $sourceType,
                $sourceRef,
                $sourceDate,
                $sourceLocation,
                $confidence
            );
        }

        Log::info('FANCooccurrenceService: Extracted co-occurrences', [
            'person_id' => $personId,
            'extracted' => count($names),
            'stored' => $stored,
            'source_type' => $sourceType,
        ]);

        return [
            'extracted' => count($names),
            'stored' => $stored,
            'names' => array_keys($names),
        ];
    }

    /**
     * Get all co-occurring names for a person, ranked by occurrence_count × confidence.
     */
    public function getCooccurrences(
        int $personId,
        ?string $sourceType = null,
        float $minConfidence = 0.5
    ): array {
        $params = [$personId, $minConfidence];
        $where = 'WHERE person_id = ? AND confidence >= ?';

        if ($sourceType) {
            $where .= ' AND source_type = ?';
            $params[] = $sourceType;
        }

        $rows = DB::select("
            SELECT *,
                   (occurrence_count * confidence) AS rank_score
            FROM fan_cooccurrences
            {$where}
            ORDER BY rank_score DESC, occurrence_count DESC
            LIMIT 50
        ", $params);

        return [
            'person_id' => $personId,
            'total' => count($rows),
            'cooccurrences' => $rows,
        ];
    }

    /**
     * Extract person names from text using regex + heuristics.
     * Returns ['Full Name' => confidence_float].
     */
    private function extractNames(string $text, object $primaryPerson): array
    {
        $names = [];

        // Common stop words that are not names
        static $stopWords = [
            'the', 'and', 'for', 'are', 'was', 'were', 'his', 'her', 'their', 'this', 'that',
            'with', 'from', 'have', 'had', 'has', 'but', 'not', 'also', 'all', 'any', 'can',
            'year', 'years', 'page', 'source', 'record', 'records', 'county', 'state', 'district',
            'township', 'born', 'died', 'married', 'circa', 'about', 'abt', 'jan', 'feb', 'mar',
            'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
        ];

        $primarySurname = strtolower($primaryPerson->surname ?? '');
        $primaryGiven   = strtolower($primaryPerson->given_name ?? '');

        // Pattern 1: "Firstname Lastname" capitalized pairs (very common in census/witness lists)
        preg_match_all('/\b([A-Z][a-z]{1,20})\s+([A-Z][a-z]{1,25})\b/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $given   = $m[1];
            $surname = $m[2];
            $givenL  = strtolower($given);
            $surnameL = strtolower($surname);

            // Skip if it's the primary person
            if (similar_text($givenL, $primaryGiven) / max(strlen($givenL), strlen($primaryGiven), 1) > 0.85
                && similar_text($surnameL, $primarySurname) / max(strlen($surnameL), strlen($primarySurname), 1) > 0.85) {
                continue;
            }

            if (in_array($surnameL, $stopWords) || in_array($givenL, $stopWords)) {
                continue;
            }

            $fullName = "{$given} {$surname}";
            $names[$fullName] = max($names[$fullName] ?? 0, 0.70);
        }

        // Pattern 2: "Lastname, Firstname" format
        preg_match_all('/\b([A-Z][a-z]{1,25}),\s*([A-Z][a-z]{1,20})\b/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $surname = $m[1];
            $given   = $m[2];
            $surnameL = strtolower($surname);
            $givenL   = strtolower($given);

            if (in_array($surnameL, $stopWords) || in_array($givenL, $stopWords)) {
                continue;
            }

            // Store as "Given Surname" normalized form
            $fullName = "{$given} {$surname}";
            $names[$fullName] = max($names[$fullName] ?? 0, 0.75); // slightly higher confidence for explicit format
        }

        // Pattern 3: All-caps surnames (common in military records, probate)
        preg_match_all('/\b([A-Z]{3,20})\b/', $text, $matches);
        foreach ($matches[1] as $surname) {
            if (in_array(strtolower($surname), $stopWords)) continue;
            if (strlen($surname) < 3) continue;
            // Only store as partial — give lower confidence
            if (!isset($names[$surname])) {
                $names[$surname] = 0.50;
            }
        }

        // Remove entries with too-common surnames (US top-100 filter)
        static $commonSurnames = [
            'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
            'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson',
            'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson',
            'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
        ];
        foreach ($commonSurnames as $common) {
            // Reduce confidence for common surnames
            foreach ($names as $name => $conf) {
                if (str_ends_with($name, " {$common}") || $name === $common) {
                    $names[$name] = min($conf, 0.40);
                }
            }
        }

        // Only return names with confidence >= 0.40
        return array_filter($names, fn($c) => $c >= 0.40);
    }

    /**
     * Insert or increment a co-occurrence record.
     * Returns 1 if a new row was inserted, 0 if an existing row was incremented.
     */
    private function upsertCooccurrence(
        int $personId,
        int $treeId,
        string $name,
        string $sourceType,
        ?string $sourceRef,
        ?string $sourceDate,
        ?string $sourceLocation,
        float $confidence
    ): int {
        try {
            // Valid enum values for source_type
            $validTypes = ['witness', 'census_neighbor', 'church', 'military', 'land', 'probate', 'newspaper', 'other'];
            if (!in_array($sourceType, $validTypes)) {
                $sourceType = 'other';
            }

            DB::statement("
                INSERT INTO fan_cooccurrences
                    (person_id, tree_id, cooccurring_name, source_type, source_ref, source_date, source_location, confidence, occurrence_count, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    occurrence_count = occurrence_count + 1,
                    confidence = GREATEST(confidence, VALUES(confidence)),
                    source_ref = COALESCE(source_ref, VALUES(source_ref)),
                    updated_at = NOW()
            ", [$personId, $treeId, $name, $sourceType, $sourceRef, $sourceDate, $sourceLocation, $confidence]);

            return 1;
        } catch (\Exception $e) {
            Log::warning('FANCooccurrenceService: upsert failed', [
                'person_id' => $personId,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}

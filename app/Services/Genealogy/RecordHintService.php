<?php

namespace App\Services\Genealogy;

use App\Services\Genealogy\Support\GivenNameVariants;
use App\Services\Genealogy\Support\ProximityNameMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Record Hint Service — N92
 *
 * Generates record hints by matching persons against configured record sources.
 * Uses weighted confidence scoring to rank matches.
 *
 * N92 upgrades:
 *   - DM-Soundex + Double Metaphone + NYSIIS + Cologne Phonetic via NameVariantService
 *   - Extended family feature matching (spouse, parents, children names)
 *
 * Scoring weights:
 *   Name exact: 0.30, Name phonetic: 0.15, Birth year ±2yr: 0.20,
 *   Birth place: 0.15, Death year: 0.10, Relationship: 0.10
 */
class RecordHintService
{
    private GenealogyService $genealogyService;
    private ?NameVariantService $nameVariantService = null;
    private float $minConfidence;

    private const CACHE_PREFIX = 'record_hints:';
    private const CACHE_TTL = 3600; // 1 hour

    // Confidence scoring weights
    private const WEIGHT_NAME_EXACT = 0.30;
    private const WEIGHT_NAME_SOUNDEX = 0.15;
    private const WEIGHT_BIRTH_YEAR = 0.20;
    private const WEIGHT_BIRTH_PLACE = 0.15;
    private const WEIGHT_DEATH_YEAR = 0.10;
    private const WEIGHT_RELATIONSHIP = 0.10;

    public function __construct(GenealogyService $genealogyService)
    {
        $this->genealogyService = $genealogyService;
        $this->minConfidence = 0.5;
    }

    private function getNameVariantService(): NameVariantService
    {
        if (!$this->nameVariantService) {
            $this->nameVariantService = app(NameVariantService::class);
        }
        return $this->nameVariantService;
    }

    /**
     * Generate record hints for a single person
     *
     * @param int $personId Person ID
     * @param float $minConfidence Minimum confidence threshold
     * @return array Generated hints
     */
    public function generateRecordHints(int $personId, float $minConfidence = 0.5): array
    {
        $this->minConfidence = $minConfidence;

        $person = $this->genealogyService->getPerson($personId);
        if (!$person) {
            return ['success' => false, 'error' => 'Person not found', 'hints' => []];
        }

        $hints = [];
        $sources = $this->getAvailableSources($person->tree_id ?? null);

        foreach ($sources as $sourceId => $source) {
            try {
                $sourceHints = $this->searchSource($source, $person);
                $hints = array_merge($hints, $sourceHints);
            } catch (Exception $e) {
                Log::warning("RecordHintService: Source {$sourceId} failed", [
                    'person_id' => $personId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Deduplicate against existing hints
        $hints = $this->deduplicateHints($personId, $hints);

        // Persist hints
        $created = 0;
        foreach ($hints as $hint) {
            $hintId = $this->createRecordHint($hint);
            if ($hintId) {
                $created++;
            }
        }

        Log::info('RecordHintService: Generated hints', [
            'person_id' => $personId,
            'candidates' => count($hints) + $created, // before dedup
            'created' => $created,
        ]);

        return [
            'success' => true,
            'hints_generated' => $created,
            'hints' => $hints,
        ];
    }

    /**
     * Generate record hints for all persons in a tree
     */
    public function generateTreeRecordHints(int $treeId, int $limit = 50, float $minConfidence = 0.5): array
    {
        $this->minConfidence = $minConfidence;

        $persons = DB::select("
            SELECT id, given_name, surname
            FROM genealogy_persons
            WHERE tree_id = ?
            ORDER BY updated_at ASC
            LIMIT ?
        ", [$treeId, $limit]);

        $totalHints = 0;
        $errors = 0;

        foreach ($persons as $person) {
            try {
                $result = $this->generateRecordHints($person->id, $minConfidence);
                $totalHints += $result['hints_generated'] ?? 0;
            } catch (Exception $e) {
                $errors++;
                Log::warning("RecordHintService: Failed for person {$person->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => true,
            'persons_checked' => count($persons),
            'hints_generated' => $totalHints,
            'errors' => $errors,
        ];
    }

    /**
     * Score a match between a person and an external record
     *
     * N92: Uses DM-Soundex + Double Metaphone + NYSIIS + Cologne via NameVariantService
     *      for far better European genealogy surname matching than standard Soundex (33% accurate).
     *
     * @param object $person Local person record
     * @param array $record External record data (may include 'spouse_name', 'father_name', 'mother_name')
     * @return array ['score' => float, 'criteria' => array]
     */
    public function scoreMatch(object $person, array $record): array
    {
        // Proximity gate: reject candidates where the target's given and
        // surname do not co-occur within a small token window. Catches
        // cross-document token co-occurrence (e.g., "Michael" in one article
        // and "Smith" in another). Runs before phonetic/partial-given
        // pathways so a proximity failure cannot be salvaged by surname-only
        // phonetic tricks.
        $gateReject = $this->proximityGateReject($person, $record);
        if ($gateReject !== null) {
            return $gateReject;
        }

        $score = 0.0;
        $criteria = [];
        $nvs = $this->getNameVariantService();

        // Name exact match
        $personName = strtolower(trim(($person->given_name ?? '') . ' ' . ($person->surname ?? '')));
        $recordName = strtolower(trim(($record['given_name'] ?? '') . ' ' . ($record['surname'] ?? '')));

        if (!empty($personName) && !empty($recordName) && $personName === $recordName) {
            $score += self::WEIGHT_NAME_EXACT;
            $criteria['name_exact'] = true;
        } elseif (!empty($person->surname) && !empty($record['surname'])) {
            // N92: Multi-phonetic surname matching — much better than soundex alone
            $matched = $this->phoneticsMatch($nvs, $person->surname, $record['surname']);
            if ($matched) {
                $score += self::WEIGHT_NAME_SOUNDEX;
                $criteria['name_phonetic'] = $matched; // which algorithm matched

                // Partial given name bonus (first initial match)
                if (!empty($person->given_name) && !empty($record['given_name'])) {
                    $pGiven = strtolower($person->given_name);
                    $rGiven = strtolower($record['given_name']);
                    if (str_starts_with($pGiven, $rGiven) || str_starts_with($rGiven, $pGiven)) {
                        $score += self::WEIGHT_NAME_EXACT * 0.5;
                        $criteria['given_name_partial'] = true;
                    }
                }
            }
        }

        // Birth year ±2 years
        $personBirthYear = $this->extractYear($person->birth_date ?? null);
        $recordBirthYear = $this->extractYear($record['birth_date'] ?? null);
        if ($personBirthYear && $recordBirthYear) {
            $yearDiff = abs($personBirthYear - $recordBirthYear);
            if ($yearDiff <= 2) {
                $score += self::WEIGHT_BIRTH_YEAR * (1 - $yearDiff * 0.25);
                $criteria['birth_year'] = ['diff' => $yearDiff];
            }
        }

        // Birth place
        if (!empty($person->birth_place) && !empty($record['birth_place'])) {
            $similarity = $this->placeSimilarity($person->birth_place, $record['birth_place']);
            if ($similarity > 0.5) {
                $score += self::WEIGHT_BIRTH_PLACE * $similarity;
                $criteria['birth_place'] = ['similarity' => round($similarity, 2)];
            }
        }

        // Death year ±2 years
        $personDeathYear = $this->extractYear($person->death_date ?? null);
        $recordDeathYear = $this->extractYear($record['death_date'] ?? null);
        if ($personDeathYear && $recordDeathYear) {
            $yearDiff = abs($personDeathYear - $recordDeathYear);
            if ($yearDiff <= 2) {
                $score += self::WEIGHT_DEATH_YEAR * (1 - $yearDiff * 0.25);
                $criteria['death_year'] = ['diff' => $yearDiff];
            }
        }

        // Relationship context — N92: includes spouse_name, father_name, mother_name from record
        $relScore = $this->scoreRelationships($person, $record['relationships'] ?? [], $record);
        if ($relScore > 0) {
            $score += self::WEIGHT_RELATIONSHIP * $relScore;
            $criteria['relationship'] = ['score' => round($relScore, 2)];
        }

        return [
            'score' => round(min($score, 1.0), 3),
            'criteria' => $criteria,
        ];
    }

    /**
     * N92: Multi-phonetic surname matching using NameVariantService algorithms.
     * Returns the matching algorithm name, or false if none match.
     *
     * Checks: Double Metaphone, NYSIIS, Cologne Phonetic, Standard Soundex.
     * Any match = surname is considered phonetically equivalent.
     */
    private function phoneticsMatch(NameVariantService $nvs, string $surname1, string $surname2): string|false
    {
        // Double Metaphone (best for European names: German, Irish, Slavic)
        $dm1 = $nvs->doubleMetaphone($surname1);
        $dm2 = $nvs->doubleMetaphone($surname2);
        if (!empty($dm1[0]) && !empty($dm2[0]) && $dm1[0] === $dm2[0]) {
            return 'double_metaphone';
        }
        // Secondary DM codes also match
        if (!empty($dm1[1]) && !empty($dm2[1]) && $dm1[1] === $dm2[1]) {
            return 'double_metaphone_secondary';
        }

        // NYSIIS (better for multi-ethnic American names)
        if ($nvs->nysiis($surname1) === $nvs->nysiis($surname2)) {
            return 'nysiis';
        }

        // Cologne Phonetic (best for German surnames specifically)
        if ($nvs->colognePhonetic($surname1) === $nvs->colognePhonetic($surname2)) {
            return 'cologne_phonetic';
        }

        // Standard Soundex (baseline fallback)
        if (soundex($surname1) === soundex($surname2)) {
            return 'soundex';
        }

        return false;
    }

    /**
     * Search a specific source for matching records
     */
    private function searchSource(array $source, object $person): array
    {
        $hints = [];
        $provider = $source['provider'] ?? null;

        if (!$provider || !$provider->isAuthenticated()) {
            return [];
        }

        $criteria = [
            'given_name' => $person->given_name ?? '',
            'surname' => $person->surname ?? '',
        ];

        if (!empty($person->birth_date)) {
            $year = $this->extractYear($person->birth_date);
            if ($year) {
                $criteria['birth_year'] = (string) $year;
            }
        }

        if (!empty($person->birth_place)) {
            $criteria['birth_place'] = $person->birth_place;
        }

        // N92: Add extended family member names to search criteria
        $extendedFamily = $this->getExtendedFamilyForSearch($person->id);
        if (!empty($extendedFamily['spouse_name'])) {
            $criteria['spouse_name'] = $extendedFamily['spouse_name'];
        }
        if (!empty($extendedFamily['father_name'])) {
            $criteria['father_name'] = $extendedFamily['father_name'];
        }
        if (!empty($extendedFamily['mother_name'])) {
            $criteria['mother_name'] = $extendedFamily['mother_name'];
        }

        $searchResult = $provider->searchRecords($criteria, ['limit' => 10]);

        if (!($searchResult['success'] ?? false)) {
            return [];
        }

        foreach ($searchResult['results'] ?? [] as $record) {
            // Extract person data from record content if available
            $recordPerson = $this->extractPersonFromRecord($record, $source['id'] ?? null);
            $matchResult = $this->scoreMatch($person, $recordPerson);

            if ($matchResult['score'] >= $this->minConfidence) {
                $hints[] = [
                    'tree_id' => $person->tree_id ?? null,
                    'person_id' => $person->id,
                    'hint_type' => 'record_match',
                    'title' => $record['title'] ?? 'External Record Match',
                    'description' => sprintf(
                        'Potential match found in %s (confidence: %d%%)',
                        $source['name'] ?? 'external source',
                        round($matchResult['score'] * 100)
                    ),
                    'confidence' => $matchResult['score'],
                    'source_info' => [
                        'provider' => $source['id'] ?? 'unknown',
                        'record_title' => $record['title'] ?? null,
                        'search_score' => $record['score'] ?? null,
                    ],
                    'record_source' => $source['id'] ?? 'unknown',
                    'external_record_id' => $record['id'] ?? null,
                    'matching_criteria' => $matchResult['criteria'],
                    'suggested_record_type' => $this->inferRecordType($record),
                    'record_url' => $record['url'] ?? null,
                    'auto_generated' => 1,
                ];
            }
        }

        return $hints;
    }

    /**
     * Get available record sources for a tree
     */
    private function getAvailableSources(?int $treeId): array
    {
        return [];
    }

    /**
     * Extract person-like data from a record result
     */
    private function extractPersonFromRecord(array $record, ?string $providerId = null): array
    {
        $content = $record['content'] ?? [];
        $gedcomx = $content['gedcomx'] ?? $content;
        $persons = $gedcomx['persons'] ?? [];
        $person = $persons[0] ?? [];

        $normalized = [
            'given_name' => $person['given_name'] ?? ($record['given_name'] ?? null),
            'surname' => $person['surname'] ?? ($record['surname'] ?? null),
            'birth_date' => $person['birth_date'] ?? ($record['birth_date'] ?? null),
            'birth_place' => $person['birth_place'] ?? ($record['birth_place'] ?? null),
            'death_date' => $person['death_date'] ?? ($record['death_date'] ?? null),
            'death_place' => $person['death_place'] ?? ($record['death_place'] ?? null),
            'relationships' => $person['relationships'] ?? [],
            'spouse_name' => $record['spouse_name'] ?? null,
            'father_name' => $record['father_name'] ?? null,
            'mother_name' => $record['mother_name'] ?? null,
            'parent_name' => $record['parent_name'] ?? null,
        ];

        // Candidate text buffer for the proximity gate in scoreMatch().
        // Excludes relationship-name fields (spouse_name/father_name/mother_name)
        // because those are scored separately and including them would let
        // the spouse's full name satisfy the target's proximity check.
        $normalized['candidate_text'] = $this->buildCandidateText($record, $normalized);

        // 2.1e: provenance classification drives scoreRelationships branching.
        // Structured providers tie relationship fields to the primary entity;
        // full-text providers may have scraped tokens from anywhere in the
        // body and need proximity verification. Missing provider → strict.
        $normalized['extraction_mode'] = $providerId !== null
            ? (string) config("genealogy.provider_extraction_mode.{$providerId}", 'full_text')
            : 'full_text';
        $normalized['provider_id'] = $providerId;

        return $normalized;
    }

    /**
     * Build the free-text buffer used by the proximity gate. Deliberately
     * excludes structured given_name/surname fields — those are claims the
     * provider makes about the record's identity, and the gate's job is to
     * verify the body corroborates them (not to echo them back).
     */
    private function buildCandidateText(array $rawRecord, array $normalized): string
    {
        $parts = [];

        foreach (['name', 'full_name', 'display_name', 'title', 'description', 'snippet', 'summary', 'body'] as $field) {
            if (!empty($rawRecord[$field]) && is_string($rawRecord[$field])) {
                $parts[] = $rawRecord[$field];
            }
        }

        $content = $rawRecord['content'] ?? null;
        if (is_array($content)) {
            foreach (['text', 'body', 'ocr', 'snippet'] as $nested) {
                if (!empty($content[$nested]) && is_string($content[$nested])) {
                    $parts[] = $content[$nested];
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Enforce the proximity rule on the candidate record. Returns a
     * score-zero result with a structured rejection reason when the
     * candidate fails, or null when the candidate passes (scoring flows on).
     *
     * Intentionally skips the gate when the target person has incomplete
     * given/surname — there is nothing to verify and the existing scoring
     * path handles empty-name targets defensively.
     */
    private function proximityGateReject(object $person, array $record): ?array
    {
        $given = trim((string) ($person->given_name ?? ''));
        $surname = trim((string) ($person->surname ?? ''));

        if ($given === '' || $surname === '') {
            return null;
        }

        $candidateText = trim((string) ($record['candidate_text'] ?? ''));
        $extractionMode = (string) ($record['extraction_mode'] ?? 'full_text');

        // Empty candidate_text handling:
        //   - structured providers (NARA Census, Ellis Island, FS) — trust
        //     the structured fields, let existing scoring run.
        //   - full_text or unknown providers — reject. An empty body on
        //     a full-text result means there is nothing to verify against
        //     the provider's structural claim, so the gate must stay
        //     closed (2.1d operator directive: STRICT).
        if ($candidateText === '') {
            if ($extractionMode === 'structured') {
                return null;
            }

            Log::info('RecordHintService: proximity gate rejected — empty candidate_text on full_text record', [
                'person_given' => $given,
                'person_surname' => $surname,
                'extraction_mode' => $extractionMode,
            ]);

            return [
                'score' => 0.0,
                'criteria' => [
                    'rejected_reason' => 'name_proximity',
                    'rejection_detail' => 'empty candidate_text on full_text provider',
                    'nearest_gap_tokens' => null,
                ],
            ];
        }

        // Accept nicknames (Mike ↔ Michael) so phonetic-variant matches
        // aren't rejected outright. The flaw we're catching is body-level
        // token scatter, not name variation — variant-aware matching
        // keeps valid records on the phonetic scoring path.
        $givenVariants = GivenNameVariants::variantsFor($given);
        $explanation = ProximityNameMatcher::explain(
            $candidateText,
            $given,
            $surname,
            null,
            $givenVariants
        );

        if ($explanation['matched']) {
            return null;
        }

        Log::info('RecordHintService: proximity gate rejected candidate', [
            'person_given' => $given,
            'person_surname' => $surname,
            'reason' => $explanation['reason'],
            'nearest_gap_tokens' => $explanation['nearest_gap_tokens'],
            'candidate_text_length' => strlen($candidateText),
        ]);

        return [
            'score' => 0.0,
            'criteria' => [
                'rejected_reason' => 'name_proximity',
                'rejection_detail' => $explanation['reason'],
                'nearest_gap_tokens' => $explanation['nearest_gap_tokens'],
            ],
        ];
    }

    /**
     * Deduplicate hints against existing ones in the database
     */
    private function deduplicateHints(int $personId, array $hints): array
    {
        if (empty($hints)) {
            return [];
        }

        // Get existing external record IDs for this person
        $existing = DB::select("
            SELECT external_record_id, record_source
            FROM genealogy_research_hints
            WHERE person_id = ?
              AND external_record_id IS NOT NULL
        ", [$personId]);

        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[$row->record_source . ':' . $row->external_record_id] = true;
        }

        return array_filter($hints, function ($hint) use ($existingKeys) {
            if (empty($hint['external_record_id'])) {
                return true;
            }
            $key = ($hint['record_source'] ?? '') . ':' . $hint['external_record_id'];
            return !isset($existingKeys[$key]);
        });
    }

    /**
     * Create a record hint in the database
     */
    private function createRecordHint(array $data): ?int
    {
        try {
            DB::insert("
                INSERT INTO genealogy_research_hints
                (tree_id, person_id, hint_type, title, description, confidence, source_info,
                 record_source, external_record_id, matching_criteria, suggested_record_type,
                 record_url, auto_generated, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
            ", [
                $data['tree_id'],
                $data['person_id'] ?? null,
                $data['hint_type'] ?? 'record_match',
                $data['title'],
                $data['description'] ?? null,
                $data['confidence'] ?? 0.50,
                isset($data['source_info']) ? json_encode($data['source_info']) : null,
                $data['record_source'] ?? null,
                $data['external_record_id'] ?? null,
                isset($data['matching_criteria']) ? json_encode($data['matching_criteria']) : null,
                $data['suggested_record_type'] ?? null,
                $data['record_url'] ?? null,
                $data['auto_generated'] ?? 0,
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (Exception $e) {
            Log::warning('RecordHintService: Failed to create hint', [
                'error' => $e->getMessage(),
                'person_id' => $data['person_id'] ?? null,
            ]);
            return null;
        }
    }

    /**
     * Extract a 4-digit year from a date string
     */
    private function extractYear(?string $dateStr): ?int
    {
        if (empty($dateStr)) {
            return null;
        }

        if (preg_match('/(\d{4})/', $dateStr, $matches)) {
            $year = (int) $matches[1];
            return ($year >= 1000 && $year <= 2100) ? $year : null;
        }

        return null;
    }

    /**
     * Compare place names with fuzzy matching
     */
    private function placeSimilarity(string $place1, string $place2): float
    {
        $p1 = strtolower(trim($place1));
        $p2 = strtolower(trim($place2));

        if ($p1 === $p2) {
            return 1.0;
        }

        // Check if one contains the other
        if (str_contains($p1, $p2) || str_contains($p2, $p1)) {
            return 0.8;
        }

        // Compare last parts (usually most specific: city, county, state)
        $parts1 = array_map('trim', explode(',', $p1));
        $parts2 = array_map('trim', explode(',', $p2));

        $matching = 0;
        $total = max(count($parts1), count($parts2));

        foreach ($parts1 as $part) {
            foreach ($parts2 as $rPart) {
                if ($part === $rPart || similar_text($part, $rPart) / max(strlen($part), strlen($rPart)) > 0.8) {
                    $matching++;
                    break;
                }
            }
        }

        return $total > 0 ? $matching / $total : 0.0;
    }

    /**
     * N92: Score relationship matches — extended family feature matching.
     *
     * 2.1e branching:
     *   - structured provider → existing behavior (trust relationship fields)
     *   - full_text provider → require relative's full name to appear as a
     *     proximity-valid phrase in the candidate text AND within the
     *     relationship_proximity_window of the target's name span.
     *     Catches body-level token scatter that the spouse_name/father_name
     *     fields alone cannot detect.
     */
    private function scoreRelationships(object $person, array $relationships, array $record = []): float
    {
        $familyMembers = DB::select("
            SELECT p.id, p.given_name, p.surname, 'spouse' AS role
            FROM genealogy_families f
            JOIN genealogy_persons p ON (
                (f.husband_id = ? AND p.id = f.wife_id) OR
                (f.wife_id = ? AND p.id = f.husband_id)
            )
            WHERE (f.husband_id = ? OR f.wife_id = ?)
            UNION
            SELECT p.id, p.given_name, p.surname, 'parent' AS role
            FROM genealogy_children gc
            JOIN genealogy_families f ON f.id = gc.family_id
            JOIN genealogy_persons p ON p.id IN (f.husband_id, f.wife_id)
            WHERE gc.person_id = ?
            LIMIT " . config('genealogy.record_hint_rel_scoring_limit', 12) . "
        ", [$person->id, $person->id, $person->id, $person->id, $person->id]);

        if (empty($familyMembers)) {
            return 0.0;
        }

        $extractionMode = $record['extraction_mode'] ?? 'full_text';
        if ($extractionMode === 'full_text') {
            return $this->scoreRelationshipsFullText($person, $familyMembers, $record);
        }

        return $this->scoreRelationshipsStructured($familyMembers, $relationships, $record);
    }

    /**
     * Structured-provider relationship scoring — relies on the provider
     * having tied relationship fields to the primary entity. Trusts the
     * provider's extraction.
     */
    protected function scoreRelationshipsStructured(array $familyMembers, array $relationships, array $record): float
    {
        $recordNames = [];
        foreach ($relationships as $rel) {
            $n = trim($rel['name'] ?? '');
            if ($n) $recordNames[] = strtolower($n);
        }
        foreach (['spouse_name', 'father_name', 'mother_name', 'parent_name'] as $field) {
            $n = trim($record[$field] ?? '');
            if ($n) $recordNames[] = strtolower($n);
        }

        if (empty($recordNames)) {
            return 0.0;
        }

        $nvs = $this->getNameVariantService();
        $matchCount = 0;
        foreach ($familyMembers as $member) {
            $memberSurname = $member->surname ?? '';
            $memberFull = strtolower(trim(($member->given_name ?? '') . ' ' . $memberSurname));
            foreach ($recordNames as $relName) {
                if (!empty($memberFull) && $memberFull === $relName) {
                    $matchCount++;
                    break;
                }
                $relParts = explode(' ', $relName);
                $relSurname = end($relParts);
                if (!empty($memberSurname) && !empty($relSurname)
                    && $this->phoneticsMatch($nvs, $memberSurname, $relSurname)) {
                    $matchCount++;
                    break;
                }
            }
        }

        return min($matchCount / max(count($familyMembers), 1), 1.0);
    }

    /**
     * Full-text-provider relationship scoring — requires each candidate
     * family member's full name to:
     *   1. Appear in the candidate text as a proximity-valid phrase
     *      (given + surname within the default proximity window).
     *   2. Fall within the relationship_proximity_window of the target's
     *      name span (so spouse on page 10 of OCR doesn't count toward a
     *      target on page 1).
     */
    protected function scoreRelationshipsFullText(object $person, array $familyMembers, array $record): float
    {
        $candidateText = (string) ($record['candidate_text'] ?? '');
        $targetGiven = trim((string) ($person->given_name ?? ''));
        $targetSurname = trim((string) ($person->surname ?? ''));

        if ($candidateText === '' || $targetGiven === '' || $targetSurname === '') {
            return 0.0;
        }

        $relationshipWindow = (int) config('genealogy.name_match.relationship_proximity_window', 8);
        $targetTokens = array_values(array_filter([strtolower($targetGiven), strtolower($targetSurname)]));

        $matchCount = 0;
        foreach ($familyMembers as $member) {
            $memberGiven = trim((string) ($member->given_name ?? ''));
            $memberSurname = trim((string) ($member->surname ?? ''));
            if ($memberGiven === '' || $memberSurname === '') {
                continue;
            }

            // Check 1 — member's full name is proximity-valid in the body.
            if (!ProximityNameMatcher::matchesFullName($candidateText, $memberGiven, $memberSurname)) {
                continue;
            }

            // Check 2 — member's name is close to the target's name span.
            // Use member tokens that differ from target tokens so a shared
            // surname does not self-match at distance 0.
            $memberTokens = array_values(array_filter(
                array_map('strtolower', [$memberGiven, $memberSurname]),
                fn ($t) => !in_array($t, $targetTokens, true)
            ));
            if (empty($memberTokens)) {
                // Member shares both given and surname with target — highly
                // unusual; fall back to gating on member's given alone even
                // when it collides (accept the proximity-valid self-proximity
                // hit from check 1 as sufficient signal).
                $matchCount++;
                continue;
            }

            $gap = ProximityNameMatcher::minCrossSetGap($candidateText, $targetTokens, $memberTokens);
            if ($gap === null || $gap > $relationshipWindow) {
                continue;
            }

            $matchCount++;
        }

        return min($matchCount / max(count($familyMembers), 1), 1.0);
    }

    /**
     * N92: Get spouse, father, mother names for extended search criteria.
     * Ancestry uses 400+ features; we add the 3 highest-value extended family fields.
     */
    private function getExtendedFamilyForSearch(int $personId): array
    {
        $result = [];

        // Spouses (from families where person is husband or wife)
        $spouse = DB::selectOne("
            SELECT p.given_name, p.surname
            FROM genealogy_families f
            JOIN genealogy_persons p ON (
                (f.husband_id = ? AND p.id = f.wife_id) OR
                (f.wife_id = ? AND p.id = f.husband_id)
            )
            WHERE (f.husband_id = ? OR f.wife_id = ?)
            LIMIT 1
        ", [$personId, $personId, $personId, $personId]);

        if ($spouse) {
            $result['spouse_name'] = trim(($spouse->given_name ?? '') . ' ' . ($spouse->surname ?? ''));
        }

        // Parents (from family where person is a child)
        $parents = DB::selectOne("
            SELECT
                h.given_name AS father_given, h.surname AS father_surname,
                w.given_name AS mother_given, w.surname AS mother_surname
            FROM genealogy_children gc
            JOIN genealogy_families f ON f.id = gc.family_id
            LEFT JOIN genealogy_persons h ON h.id = f.husband_id
            LEFT JOIN genealogy_persons w ON w.id = f.wife_id
            WHERE gc.person_id = ?
            LIMIT 1
        ", [$personId]);

        if ($parents) {
            if ($parents->father_given || $parents->father_surname) {
                $result['father_name'] = trim(($parents->father_given ?? '') . ' ' . ($parents->father_surname ?? ''));
            }
            if ($parents->mother_given || $parents->mother_surname) {
                $result['mother_name'] = trim(($parents->mother_given ?? '') . ' ' . ($parents->mother_surname ?? ''));
            }
        }

        return $result;
    }

    /**
     * Infer record type from record data
     */
    private function inferRecordType(array $record): ?string
    {
        $title = strtolower($record['title'] ?? '');

        $types = [
            'birth' => ['birth', 'christening', 'baptism'],
            'death' => ['death', 'burial', 'obituary', 'cemetery'],
            'marriage' => ['marriage', 'wedding', 'banns'],
            'census' => ['census', 'population'],
            'military' => ['military', 'draft', 'service', 'veteran', 'war'],
            'immigration' => ['immigration', 'emigration', 'passenger', 'naturalization'],
            'probate' => ['probate', 'will', 'estate'],
            'land' => ['land', 'deed', 'property'],
        ];

        foreach ($types as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($title, $keyword)) {
                    return $type;
                }
            }
        }

        return null;
    }
}

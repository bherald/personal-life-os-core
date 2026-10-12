<?php

namespace App\Services;

use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\Genealogy\Support\GivenNameVariants;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FaceMatcherService - Enhanced face-to-genealogy matching
 *
 * Matches face names from EXIF metadata to genealogy_persons with:
 * - Exact name matching (review queue by default)
 * - SOUNDEX phonetic matching (queue for approval)
 * - Nickname/variant detection (queue for approval)
 * - AI disambiguation when multiple candidates (suggest best)
 *
 * Flow:
 * 1. Exact match with 1 result → queue for review
 * 2. Exact match with multiple → AI picks best suggestion for review
 * 3. Fuzzy match → queue for human approval
 * 4. No match → queue with no_match status
 */
class FaceMatcherService
{
    private ?AIService $aiService = null;

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }

        return $this->aiService;
    }

    /**
     * Match a face name to genealogy person(s)
     *
     * @param  string  $faceName  Name from EXIF face region
     * @param  int  $treeId  Genealogy tree ID (default 4)
     * @return array Match result with person_id or queue action
     */
    public function matchFaceName(string $faceName, int $treeId = 4, array $excludedPersonIds = []): array
    {
        $faceName = trim($faceName);
        if (empty($faceName) || strtolower($faceName) === 'unknown') {
            return ['match_type' => 'skip', 'reason' => 'Empty or unknown name'];
        }

        $excludedPersonIds = $this->normalizePersonIds($excludedPersonIds);

        // Step 1: Try exact match
        $exactMatches = $this->excludePersonMatches(
            $this->findExactMatches($faceName, $treeId),
            $excludedPersonIds
        );

        if (count($exactMatches) === 1) {
            // Embedded names are evidence, not proof. Default to operator review.
            if ($this->allowMetadataAutoLink()) {
                return [
                    'match_type' => $exactMatches[0]->match_type ?? 'exact',
                    'person_id' => $exactMatches[0]->id,
                    'person_name' => $exactMatches[0]->given_name.' '.$exactMatches[0]->surname,
                    'confidence' => $exactMatches[0]->confidence ?? 100,
                    'action' => 'auto_link',
                ];
            }

            return $this->queueSuggestion($exactMatches[0], $exactMatches[0]->match_type ?? 'exact', $exactMatches[0]->confidence ?? 100);
        }

        if (count($exactMatches) > 1) {
            // Multiple exact matches - use AI to pick best
            return $this->aiDisambiguate($faceName, $exactMatches, 'exact_multiple');
        }

        // Step 2: Try fuzzy matches (SOUNDEX, nicknames, typos)
        $fuzzyMatches = $this->excludePersonMatches(
            $this->findFuzzyMatches($faceName, $treeId),
            $excludedPersonIds
        );

        if (count($fuzzyMatches) === 1 && $fuzzyMatches[0]->confidence >= 90) {
            if ($this->allowMetadataAutoLink()) {
                return [
                    'match_type' => $fuzzyMatches[0]->match_type,
                    'person_id' => $fuzzyMatches[0]->id,
                    'person_name' => $fuzzyMatches[0]->given_name.' '.$fuzzyMatches[0]->surname,
                    'confidence' => $fuzzyMatches[0]->confidence,
                    'action' => 'auto_link',
                ];
            }

            return $this->queueSuggestion($fuzzyMatches[0], $fuzzyMatches[0]->match_type, $fuzzyMatches[0]->confidence);
        }

        if (count($fuzzyMatches) > 1) {
            // Multiple fuzzy matches - use AI to pick best
            return $this->aiDisambiguate($faceName, $fuzzyMatches, 'fuzzy_multiple');
        }

        if (count($fuzzyMatches) === 1) {
            // Single fuzzy match but low confidence - queue for approval
            return [
                'match_type' => $fuzzyMatches[0]->match_type,
                'suggested_person_id' => $fuzzyMatches[0]->id,
                'suggested_name' => $fuzzyMatches[0]->given_name.' '.$fuzzyMatches[0]->surname,
                'confidence' => $fuzzyMatches[0]->confidence,
                'action' => 'queue',
            ];
        }

        // Step 3: No match found. Queue the named face; embedded EXIF/XMP names
        // are no longer treated as authoritative during metadata-audit posture.
        if ($this->allowStandaloneAutoVerify()) {
            return [
                'match_type' => 'no_match',
                'action' => 'done',
                'confidence' => 100,
            ];
        }

        return [
            'match_type' => 'no_match',
            'action' => 'queue',
            'confidence' => 0,
        ];
    }

    /**
     * Find exact name matches
     */
    private function findExactMatches(string $faceName, int $treeId): array
    {
        // Parse name parts
        $parts = preg_split('/\s+/', $faceName);
        $firstName = $parts[0] ?? '';
        $middleName = count($parts) > 2 ? $parts[1] : '';
        $lastName = end($parts);

        // Full name variations
        $fullName1 = $firstName.' '.($middleName ? $middleName.' ' : '').$lastName;
        $fullName2 = $lastName.', '.$firstName.($middleName ? ' '.$middleName : '');

        $directMatches = DB::select("
            SELECT id, given_name, surname, birth_date, death_date, 'exact' AS match_type, 100 AS confidence
            FROM genealogy_persons
            WHERE tree_id = ?
            AND (
                CONCAT_WS(' ', given_name, surname) = ?
                OR CONCAT(surname, ', ', given_name) = ?
                OR (given_name = ? AND surname = ?)
                OR CONCAT_WS(' ', nickname, surname) = ?
            )
        ", [$treeId, $fullName1, $fullName2, $firstName.($middleName ? ' '.$middleName : ''), $lastName, $fullName1]);

        $variantMatches = DB::select("
            SELECT gp.id, gp.given_name, gp.surname, gp.birth_date, gp.death_date,
                   'exact_variant' AS match_type, 97 AS confidence
            FROM genealogy_name_variants gnv
            JOIN genealogy_persons gp ON gp.id = gnv.person_id
            WHERE gp.tree_id = ?
            AND LOWER(TRIM(COALESCE(NULLIF(gnv.full_name, ''), CONCAT_WS(' ', gnv.given_names, gnv.surname)))) = LOWER(TRIM(?))
        ", [$treeId, $faceName]);

        $matches = [];
        foreach ($directMatches as $match) {
            $matches[(int) $match->id] = $match;
        }
        foreach ($variantMatches as $match) {
            $matches[(int) $match->id] ??= $match;
        }

        return array_values($matches);
    }

    /**
     * Find fuzzy matches using SOUNDEX, nicknames, and similarity
     */
    private function findFuzzyMatches(string $faceName, int $treeId): array
    {
        $parts = preg_split('/\s+/', $faceName);
        $firstName = strtolower($parts[0] ?? '');
        $lastName = strtolower(end($parts));

        $matches = [];

        $firstNameVariants = GivenNameVariants::variantsFor($firstName);

        // SOUNDEX matching on surname
        $soundexMatches = DB::select('
            SELECT id, given_name, surname, nickname, birth_date, death_date
            FROM genealogy_persons
            WHERE tree_id = ?
            AND SOUNDEX(surname) = SOUNDEX(?)
        ', [$treeId, $lastName]);

        foreach ($soundexMatches as $person) {
            $personFirstName = strtolower(explode(' ', (string) ($person->given_name ?? ''))[0]);
            $personNickname = strtolower(explode(' ', (string) ($person->nickname ?? ''))[0] ?? '');
            $personNameVariants = GivenNameVariants::variantsFor($personFirstName);
            if ($personNickname !== '') {
                $personNameVariants = array_values(array_unique(array_merge(
                    $personNameVariants,
                    GivenNameVariants::variantsFor($personNickname)
                )));
            }

            // Check if first name matches or is a variant
            $firstNameMatch = in_array($personFirstName, $firstNameVariants, true)
                || in_array($firstName, $personNameVariants, true)
                || ($personNickname !== '' && in_array($personNickname, $firstNameVariants, true));
            $firstNameSoundex = soundex($personFirstName) === soundex($firstName);

            if ($firstNameMatch) {
                $person->match_type = 'nickname';
                $person->confidence = 85;
                $matches[] = $person;
            } elseif ($firstNameSoundex) {
                $person->match_type = 'soundex';
                $person->confidence = 70;
                $matches[] = $person;
            }
        }

        // Levenshtein distance for typo detection
        $allPersons = DB::select('
            SELECT id, given_name, surname, birth_date, death_date
            FROM genealogy_persons
            WHERE tree_id = ?
        ', [$treeId]);

        foreach ($allPersons as $person) {
            // Skip if already matched
            $alreadyMatched = false;
            foreach ($matches as $m) {
                if ($m->id === $person->id) {
                    $alreadyMatched = true;
                    break;
                }
            }
            if ($alreadyMatched) {
                continue;
            }

            $personFullName = strtolower($person->given_name.' '.$person->surname);
            $faceNameLower = strtolower($faceName);

            $distance = levenshtein($faceNameLower, $personFullName);
            $maxLen = max(strlen($faceNameLower), strlen($personFullName));

            // Accept if similarity > 80%
            $similarity = 1 - ($distance / $maxLen);
            if ($similarity > 0.80) {
                $person->match_type = 'typo';
                $person->confidence = (int) ($similarity * 100);
                $matches[] = $person;
            }
        }

        // Sort by confidence descending
        usort($matches, fn ($a, $b) => $b->confidence <=> $a->confidence);

        return $matches;
    }

    /**
     * Use AI to pick the best match from multiple candidates
     */
    private function aiDisambiguate(string $faceName, array $candidates, string $matchType): array
    {
        // Build context for AI
        $candidateList = [];
        foreach ($candidates as $i => $person) {
            $dates = '';
            if ($person->birth_date) {
                $dates .= 'b.'.substr($person->birth_date, 0, 4);
            }
            if ($person->death_date) {
                $dates .= ' d.'.substr($person->death_date, 0, 4);
            }

            $candidateList[] = [
                'index' => $i + 1,
                'id' => $person->id,
                'name' => $person->given_name.' '.$person->surname,
                'dates' => $dates ?: 'unknown',
            ];
        }

        $prompt = "Given face name from photo metadata: \"{$faceName}\"\n\n";
        $prompt .= "Select the most likely match from these genealogy persons:\n";
        foreach ($candidateList as $c) {
            $prompt .= "{$c['index']}. {$c['name']} ({$c['dates']})\n";
        }
        $prompt .= "\nRespond with ONLY the number of the best match (1-".count($candidateList).'), or 0 if none are likely correct.';

        try {
            $result = $this->getAIService()->process($prompt, [
                'max_tokens' => 10,
                'system' => 'You are a genealogy assistant. Select the person most likely to match the face name based on name similarity and lifespan plausibility. Respond with only a number.',
            ]);

            $response = trim($result['response'] ?? '0');
            $selectedIndex = (int) preg_replace('/[^0-9]/', '', $response);

            if ($selectedIndex >= 1 && $selectedIndex <= count($candidates)) {
                $selected = $candidates[$selectedIndex - 1];

                if (! $this->allowMetadataAutoLink()) {
                    return [
                        'match_type' => $matchType,
                        'suggested_person_id' => $selected->id,
                        'suggested_name' => $selected->given_name.' '.$selected->surname,
                        'confidence' => $selected->confidence ?? 80,
                        'action' => 'queue',
                        'ai_selected' => true,
                        'candidates_count' => count($candidates),
                    ];
                }

                return [
                    'match_type' => $matchType,
                    'person_id' => $selected->id,
                    'person_name' => $selected->given_name.' '.$selected->surname,
                    'confidence' => $selected->confidence ?? 80,
                    'action' => 'auto_link',
                    'ai_selected' => true,
                    'candidates_count' => count($candidates),
                ];
            }
        } catch (Exception $e) {
            Log::warning('FaceMatcherService: AI disambiguation failed', [
                'face_name' => $faceName,
                'error' => $e->getMessage(),
            ]);
        }

        // AI couldn't decide or failed - queue for human review
        return [
            'match_type' => $matchType,
            'suggested_person_id' => $candidates[0]->id,
            'suggested_name' => $candidates[0]->given_name.' '.$candidates[0]->surname,
            'confidence' => $candidates[0]->confidence ?? 50,
            'action' => 'queue',
            'candidates' => array_map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->given_name.' '.$c->surname,
            ], $candidates),
        ];
    }

    private function queueSuggestion(object $person, string $matchType, int|float $confidence): array
    {
        return [
            'match_type' => $matchType,
            'suggested_person_id' => $person->id,
            'suggested_name' => $person->given_name.' '.$person->surname,
            'confidence' => $confidence,
            'action' => 'queue',
        ];
    }

    private function allowMetadataAutoLink(): bool
    {
        return $this->xmpIdentityTrustMode() === 'trusted_auto_link';
    }

    private function allowStandaloneAutoVerify(): bool
    {
        return in_array($this->xmpIdentityTrustMode(), ['trusted_auto_link', 'trusted_standalone'], true);
    }

    private function xmpIdentityTrustMode(): string
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['faces', 'xmp_identity_trust_mode']
            );
        } catch (Exception $e) {
            return 'review_only';
        }

        $mode = is_scalar($row->config_value ?? null) ? trim((string) $row->config_value) : 'review_only';

        return in_array($mode, ['review_only', 'trusted_auto_link', 'trusted_standalone'], true)
            ? $mode
            : 'review_only';
    }

    /**
     * Process a batch of unmatched faces
     *
     * @param  int  $limit  Max faces to process
     * @param  int  $treeId  Genealogy tree ID
     * @return array Processing results
     */
    public function processBatch(int $limit = 100, int $treeId = 4): array
    {
        $results = [
            'processed' => 0,
            'auto_linked' => 0,
            'standalone' => 0,
            'queued' => 0,
            'skipped' => 0,
        ];

        // Get unlinked faces
        $faces = DB::select("
            SELECT frf.*, fr.current_path, fr.status AS file_status
            FROM file_registry_faces frf JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.genealogy_person_id IS NULL
            AND frf.person_name IS NOT NULL
            AND frf.person_name != ''
            AND frf.person_name != 'Unknown'
            AND fr.status = 'active' AND COALESCE(frf.hidden, 0) = 0 AND frf.source_current = 1 AND frf.superseded_at IS NULL
            ORDER BY frf.id ASC
            LIMIT ?
        ", [$limit]);

        foreach ($faces as $face) {
            $results['processed']++;

            $face->source_evidence = app(FaceLinkBridgeService::class)->validateFaceSourceEvidence($face);
            if (! ($face->source_evidence['success'] ?? false)) {
                $this->addToQueue($face, ['match_type' => 'no_match', 'confidence' => 0,
                    'details' => ['metadata' => ['audit_hold' => ['reason_codes' => [$face->source_evidence['reason']]]]]], $treeId);
                $results['queued']++;
                $results['skipped']++;

                continue;
            }

            $match = $this->matchFaceName(
                $face->person_name,
                $treeId,
                $this->rejectedPersonIdsForFace((int) $face->id)
            );

            if ($match['match_type'] === 'skip') {
                $results['skipped']++;

                continue;
            }

            if ($match['action'] === 'auto_link' && isset($match['person_id'])) {
                $bridgeResult = app(FaceLinkBridgeService::class)->syncAutomaticFaceLink(
                    (int) $face->id,
                    (int) $match['person_id']
                );

                if (in_array($bridgeResult['reason'] ?? null, [
                    'operator_identity_confirmation_required', 'face_region_confirmation_required',
                ], true)) {
                    $match['suggested_person_id'] = $match['person_id'];
                    $this->addToQueue($face, $match, $treeId);
                    $results['queued']++;
                } else {
                    $results['skipped']++;
                }

                Log::info('FaceMatcherService: Automatic genealogy bridge checked', [
                    'face_id' => $face->id,
                    'face_name' => $face->person_name,
                    'person_id' => $match['person_id'],
                    'match_type' => $match['match_type'],
                    'bridge_result' => $bridgeResult,
                ]);

            } elseif ($match['action'] === 'done') {
                // Explicit trust mode allows standalone verification without a genealogy link.
                DB::update('
                    UPDATE file_registry_faces
                    SET verified = 1, updated_at = NOW()
                    WHERE id = ?
                ', [$face->id]);

                $results['standalone']++;

                Log::info('FaceMatcherService: Verified standalone face (not a genealogy relative)', [
                    'face_id' => $face->id,
                    'face_name' => $face->person_name,
                ]);

            } elseif ($match['action'] === 'queue') {
                // Add to approval queue for genealogy linkage decision
                $this->addToQueue($face, $match, $treeId);
                $results['queued']++;
            }
        }

        Log::info('FaceMatcherService: Batch processing complete', $results);

        return $results;
    }

    /**
     * Add a face match to the approval queue
     */
    private function addToQueue(object $face, array $match, int $treeId): void
    {
        // Guard: reject concatenated multi-name values
        if (! empty($face->person_name) && str_contains($face->person_name, ',')) {
            Log::warning('FaceMatcherService: Rejecting concatenated face name', [
                'face_id' => $face->id, 'person_name' => $face->person_name,
            ]);

            return;
        }

        // Get media_id from file_registry if linked to genealogy_media
        $mediaLink = DB::selectOne('
            SELECT gm.id as media_id
            FROM file_registry fr
            JOIN genealogy_media gm ON gm.nextcloud_path = fr.current_path
            WHERE fr.id = ? AND gm.tree_id = ?
        ', [$face->file_registry_id, $treeId]);

        $suggestedPersonId = isset($match['suggested_person_id']) ? (int) $match['suggested_person_id'] : null;
        if ($suggestedPersonId !== null && $suggestedPersonId > 0 && $this->faceHasRejectedPerson((int) $face->id, $suggestedPersonId)) {
            Log::info('FaceMatcherService: Suppressed previously rejected face/person suggestion', [
                'face_id' => $face->id,
                'face_name' => $face->person_name,
                'person_id' => $suggestedPersonId,
            ]);

            return;
        }

        // Check if already in queue (pending or ignored)
        $existing = DB::selectOne("
            SELECT id FROM genealogy_face_match_queue
            WHERE file_registry_face_id = ? AND tree_id = ? AND status IN ('pending', 'ignored')
        ", [$face->id, $treeId]);

        if ($existing) {
            return; // Already queued
        }

        DB::insert("
            INSERT INTO genealogy_face_match_queue
            (tree_id, media_id, file_registry_face_id, face_name, suggested_person_id,
             match_type, confidence_score, face_region, match_details, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ", [
            $treeId,
            $mediaLink->media_id ?? null,
            $face->id,
            $face->person_name,
            $match['suggested_person_id'] ?? null,
            $match['match_type'],
            $match['confidence'] ?? 0,
            json_encode(['x' => $face->region_x ?? null, 'y' => $face->region_y ?? null, 'w' => $face->region_w ?? null, 'h' => $face->region_h ?? null]),
            json_encode(array_merge($match['details'] ?? [], ($face->source_evidence['success'] ?? false) ? [
                'source_sha256' => $face->source_evidence['source_sha256'], 'source_path' => $face->source_evidence['source_path'],
                'face_region_convention' => 'normalized_top_left',
            ] : ['evidence_hold_reason' => $face->source_evidence['reason'] ?? 'face_source_provenance_missing'])),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $personIds
     * @return list<int>
     */
    private function normalizePersonIds(array $personIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $personIds),
            fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @param  array<int, object>  $matches
     * @param  list<int>  $excludedPersonIds
     * @return array<int, object>
     */
    private function excludePersonMatches(array $matches, array $excludedPersonIds): array
    {
        if ($excludedPersonIds === []) {
            return $matches;
        }

        return array_values(array_filter(
            $matches,
            fn (object $match): bool => ! in_array((int) ($match->id ?? 0), $excludedPersonIds, true)
        ));
    }

    /**
     * @return list<int>
     */
    private function rejectedPersonIdsForFace(int $fileRegistryFaceId): array
    {
        try {
            $rows = DB::select('
                SELECT suggested_person_id, status, match_details
                FROM genealogy_face_match_queue
                WHERE file_registry_face_id = ?
            ', [$fileRegistryFaceId]);
        } catch (Exception $e) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (($row->status ?? null) === 'rejected' && $row->suggested_person_id !== null) {
                $ids[] = (int) $row->suggested_person_id;
            }

            $details = json_decode((string) ($row->match_details ?? ''), true);
            if (! is_array($details)) {
                continue;
            }

            foreach (($details['rejected_candidate_ids'] ?? []) as $id) {
                $ids[] = (int) $id;
            }

            $latest = $details['latest_candidate_decision'] ?? null;
            if (
                is_array($latest)
                && ($latest['action'] ?? null) === 'not_this_person'
                && isset($latest['genealogy_person_id'])
            ) {
                $ids[] = (int) $latest['genealogy_person_id'];
            }
        }

        return $this->normalizePersonIds($ids);
    }

    private function faceHasRejectedPerson(int $fileRegistryFaceId, int $personId): bool
    {
        return in_array($personId, $this->rejectedPersonIdsForFace($fileRegistryFaceId), true);
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(int $treeId = 4): array
    {
        $stats = DB::select('
            SELECT status, match_type, COUNT(*) as count, AVG(confidence_score) as avg_confidence
            FROM genealogy_face_match_queue
            WHERE tree_id = ?
            GROUP BY status, match_type
        ', [$treeId]);

        $result = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'by_type' => [],
        ];

        foreach ($stats as $row) {
            $result[$row->status] = ($result[$row->status] ?? 0) + $row->count;
            $result['by_type'][$row->match_type] = [
                'count' => $row->count,
                'avg_confidence' => round($row->avg_confidence, 1),
            ];
        }

        return $result;
    }
}

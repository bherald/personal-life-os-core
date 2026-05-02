<?php

namespace App\Services;

use App\Services\Genealogy\FaceLinkBridgeService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FaceMatcherService - Enhanced face-to-genealogy matching
 *
 * Matches face names from EXIF metadata to genealogy_persons with:
 * - Exact name matching (auto-link)
 * - SOUNDEX phonetic matching (queue for approval)
 * - Nickname/variant detection (queue for approval)
 * - AI disambiguation when multiple candidates (auto-select best)
 *
 * Flow:
 * 1. Exact match with 1 result → auto-link
 * 2. Exact match with multiple → AI picks best
 * 3. Fuzzy match → queue for human approval
 * 4. No match → queue with no_match status
 */
class FaceMatcherService
{
    private ?AIService $aiService = null;

    /** Known nickname mappings */
    private const NICKNAMES = [
        'william' => ['bill', 'will', 'willy', 'billy', 'liam'],
        'robert' => ['bob', 'rob', 'bobby', 'robbie'],
        'richard' => ['rick', 'dick', 'rich', 'ricky'],
        'james' => ['jim', 'jimmy', 'jamie'],
        'john' => ['jack', 'johnny', 'jon'],
        'michael' => ['mike', 'mikey', 'mick'],
        'elizabeth' => ['liz', 'beth', 'betty', 'lizzy', 'eliza'],
        'margaret' => ['maggie', 'peggy', 'marge', 'meg'],
        'catherine' => ['kate', 'katie', 'kathy', 'cathy', 'cat'],
        'katherine' => ['kate', 'katie', 'kathy', 'cathy', 'cat'],
        'kathryn' => ['kate', 'katie', 'kathy', 'cathy', 'cat'],
        'patricia' => ['pat', 'patty', 'trish', 'tricia'],
        'jennifer' => ['jen', 'jenny', 'jenn'],
        'thomas' => ['tom', 'tommy'],
        'joseph' => ['joe', 'joey'],
        'charles' => ['charlie', 'chuck', 'chas'],
        'david' => ['dave', 'davey'],
        'daniel' => ['dan', 'danny'],
        'edward' => ['ed', 'eddie', 'ted', 'teddy', 'ned'],
        'anthony' => ['tony', 'ant'],
        'donald' => ['don', 'donnie', 'donny'],
        'laura' => ['laurie', 'lori'],
        'marjorie' => ['margie', 'marge'],
        'dorothy' => ['dot', 'dotty', 'dottie'],
        'deborah' => ['deb', 'debbie', 'debby'],
        'susan' => ['sue', 'susie', 'suzy'],
        'nancy' => ['nan', 'nana'],
        'helen' => ['nellie', 'nell'],
        'ann' => ['annie', 'anna', 'anne'],
    ];

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
    public function matchFaceName(string $faceName, int $treeId = 4): array
    {
        $faceName = trim($faceName);
        if (empty($faceName) || strtolower($faceName) === 'unknown') {
            return ['match_type' => 'skip', 'reason' => 'Empty or unknown name'];
        }

        // Step 1: Try exact match
        $exactMatches = $this->findExactMatches($faceName, $treeId);

        if (count($exactMatches) === 1) {
            // Single exact match - auto-link
            return [
                'match_type' => 'exact',
                'person_id' => $exactMatches[0]->id,
                'person_name' => $exactMatches[0]->given_name.' '.$exactMatches[0]->surname,
                'confidence' => 100,
                'action' => 'auto_link',
            ];
        }

        if (count($exactMatches) > 1) {
            // Multiple exact matches - use AI to pick best
            return $this->aiDisambiguate($faceName, $exactMatches, 'exact_multiple');
        }

        // Step 2: Try fuzzy matches (SOUNDEX, nicknames, typos)
        $fuzzyMatches = $this->findFuzzyMatches($faceName, $treeId);

        if (count($fuzzyMatches) === 1 && $fuzzyMatches[0]->confidence >= 90) {
            // High-confidence single fuzzy match - auto-link
            return [
                'match_type' => $fuzzyMatches[0]->match_type,
                'person_id' => $fuzzyMatches[0]->id,
                'person_name' => $fuzzyMatches[0]->given_name.' '.$fuzzyMatches[0]->surname,
                'confidence' => $fuzzyMatches[0]->confidence,
                'action' => 'auto_link',
            ];
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

        // Step 3: No match found - face is STANDALONE (not a genealogy relative)
        // Embedded EXIF/XMP name is authoritative, no approval needed
        return [
            'match_type' => 'no_match',
            'action' => 'done',  // Don't queue - face is valid standalone entity
            'confidence' => 100, // EXIF name is trusted
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

        return DB::select("
            SELECT id, given_name, surname, birth_date, death_date
            FROM genealogy_persons
            WHERE tree_id = ?
            AND (
                CONCAT(given_name, ' ', surname) = ?
                OR CONCAT(surname, ', ', given_name) = ?
                OR (given_name = ? AND surname = ?)
            )
        ", [$treeId, $fullName1, $fullName2, $firstName.($middleName ? ' '.$middleName : ''), $lastName]);
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

        // Build nickname variations
        $firstNameVariants = [$firstName];
        foreach (self::NICKNAMES as $canonical => $variants) {
            if ($firstName === $canonical || in_array($firstName, $variants)) {
                $firstNameVariants = array_merge([$canonical], $variants);
                break;
            }
        }

        // SOUNDEX matching on surname
        $soundexMatches = DB::select('
            SELECT id, given_name, surname, birth_date, death_date
            FROM genealogy_persons
            WHERE tree_id = ?
            AND SOUNDEX(surname) = SOUNDEX(?)
        ', [$treeId, $lastName]);

        foreach ($soundexMatches as $person) {
            $personFirstName = strtolower(explode(' ', $person->given_name)[0]);

            // Check if first name matches or is a variant
            $firstNameMatch = in_array($personFirstName, $firstNameVariants);
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
            'standalone' => 0,  // EXIF name trusted, not a genealogy relative
            'queued' => 0,
            'skipped' => 0,
        ];

        // Get unlinked faces
        $faces = DB::select("
            SELECT frf.id, frf.file_registry_id, frf.person_name
            FROM file_registry_faces frf
            WHERE frf.genealogy_person_id IS NULL
            AND frf.person_name IS NOT NULL
            AND frf.person_name != ''
            AND frf.person_name != 'Unknown'
            ORDER BY frf.id ASC
            LIMIT ?
        ", [$limit]);

        foreach ($faces as $face) {
            $results['processed']++;

            $match = $this->matchFaceName($face->person_name, $treeId);

            if ($match['match_type'] === 'skip') {
                $results['skipped']++;

                continue;
            }

            if ($match['action'] === 'auto_link' && isset($match['person_id'])) {
                $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink(
                    (int) $face->id,
                    (int) $match['person_id']
                );

                if ($bridgeResult['success'] ?? false) {
                    $results['auto_linked']++;
                } else {
                    $results['skipped']++;
                }

                Log::info('FaceMatcherService: Auto-linked face via genealogy bridge', [
                    'face_id' => $face->id,
                    'face_name' => $face->person_name,
                    'person_id' => $match['person_id'],
                    'match_type' => $match['match_type'],
                    'bridge_result' => $bridgeResult,
                ]);

            } elseif ($match['action'] === 'done') {
                // EXIF name is authoritative - mark as verified standalone face
                // No genealogy link needed - this person is not in the family tree
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
            WHERE fr.id = ?
        ', [$face->file_registry_id]);

        // Check if already in queue (pending or ignored)
        $existing = DB::selectOne("
            SELECT id FROM genealogy_face_match_queue
            WHERE face_name = ? AND tree_id = ? AND status IN ('pending', 'ignored')
        ", [$face->person_name, $treeId]);

        if ($existing) {
            return; // Already queued
        }

        DB::insert("
            INSERT INTO genealogy_face_match_queue
            (tree_id, media_id, file_registry_face_id, face_name, suggested_person_id,
             match_type, confidence_score, face_region, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ", [
            $treeId,
            $mediaLink->media_id ?? null,
            $face->id,
            $face->person_name,
            $match['suggested_person_id'] ?? null,
            $match['match_type'],
            $match['confidence'] ?? 0,
            json_encode(['file_registry_face_id' => $face->id]),
        ]);
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

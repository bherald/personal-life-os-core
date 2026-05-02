<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Duplicate Detection Service
 *
 * Handles duplicate person detection, scoring, and merging.
 * Extracted from GenealogyService as part of Priority 2.1 refactoring.
 *
 * @see docs/genealogy-module-review.md Priority 2.1
 */
class DuplicateDetectionService
{
    /**
     * Find potential duplicate persons in a tree
     *
     * @param int $treeId Tree ID
     * @param array $options Options: minScore (float), limit (int), includeResolved (bool)
     * @return array List of potential duplicate pairs with scores
     */
    public function findDuplicatePersons(int $treeId, array $options = []): array
    {
        $minScore = $options['minScore'] ?? 0.6;
        $limit = $options['limit'] ?? 100;
        $includeResolved = $options['includeResolved'] ?? false;

        // Get all persons with relevant data
        $sql = "
            SELECT
                p.id,
                p.gedcom_id,
                p.given_name,
                p.surname,
                p.birth_date,
                p.birth_place,
                p.death_date,
                p.death_place,
                p.sex as gender,
                SOUNDEX(p.surname) as surname_soundex,
                SOUNDEX(p.given_name) as given_soundex
            FROM genealogy_persons p
            WHERE p.tree_id = ?
            ORDER BY p.surname, p.given_name
        ";

        $persons = DB::select($sql, [$treeId]);

        // Get already-resolved duplicate pairs to exclude
        $resolvedPairs = [];
        if (!$includeResolved) {
            $resolvedSql = "
                SELECT person1_id, person2_id
                FROM genealogy_duplicate_pairs
                WHERE tree_id = ? AND status IN ('resolved', 'rejected', 'merged')
            ";
            $resolved = DB::select($resolvedSql, [$treeId]);
            foreach ($resolved as $r) {
                $key = min($r->person1_id, $r->person2_id) . '-' . max($r->person1_id, $r->person2_id);
                $resolvedPairs[$key] = true;
            }
        }

        $duplicates = [];
        $count = count($persons);

        // Compare each person with every other person (O(n²) but necessary)
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $p1 = $persons[$i];
                $p2 = $persons[$j];

                // Skip if already resolved
                $pairKey = min($p1->id, $p2->id) . '-' . max($p1->id, $p2->id);
                if (isset($resolvedPairs[$pairKey])) {
                    continue;
                }

                // Calculate similarity score
                $score = $this->calculateDuplicateScore($p1, $p2);

                if ($score >= $minScore) {
                    $duplicates[] = [
                        'person1' => [
                            'id' => $p1->id,
                            'gedcom_id' => $p1->gedcom_id,
                            'name' => trim(($p1->given_name ?? '') . ' ' . ($p1->surname ?? '')),
                            'birth_date' => $p1->birth_date,
                            'birth_place' => $p1->birth_place,
                            'death_date' => $p1->death_date,
                            'death_place' => $p1->death_place,
                            'gender' => $p1->gender,
                        ],
                        'person2' => [
                            'id' => $p2->id,
                            'gedcom_id' => $p2->gedcom_id,
                            'name' => trim(($p2->given_name ?? '') . ' ' . ($p2->surname ?? '')),
                            'birth_date' => $p2->birth_date,
                            'birth_place' => $p2->birth_place,
                            'death_date' => $p2->death_date,
                            'death_place' => $p2->death_place,
                            'gender' => $p2->gender,
                        ],
                        'score' => round($score, 3),
                        'reasons' => $this->getDuplicateReasons($p1, $p2),
                    ];
                }
            }
        }

        // Sort by score descending
        usort($duplicates, fn($a, $b) => $b['score'] <=> $a['score']);

        // Apply limit
        return array_slice($duplicates, 0, $limit);
    }

    /**
     * Calculate similarity score between two persons
     *
     * Enhanced scoring includes:
     * - Exact and fuzzy name matching (Levenshtein distance)
     * - Multiple phonetic algorithms (Soundex, Metaphone)
     * - Relationship context (same parents)
     * - Place matching for additional confidence
     */
    private function calculateDuplicateScore(object $p1, object $p2): float
    {
        $score = 0.0;
        $weights = [
            'surname_exact' => 0.20,
            'surname_phonetic' => 0.10,
            'surname_fuzzy' => 0.08,
            'given_exact' => 0.18,
            'given_phonetic' => 0.08,
            'given_fuzzy' => 0.06,
            'birth_date' => 0.12,
            'death_date' => 0.08,
            'birth_place' => 0.05,
            'gender' => 0.03,
            'same_parents' => 0.12, // Strong indicator
        ];

        // Surname comparison with multiple matching methods
        if (!empty($p1->surname) && !empty($p2->surname)) {
            $s1 = strtolower(trim($p1->surname));
            $s2 = strtolower(trim($p2->surname));

            if ($s1 === $s2) {
                $score += $weights['surname_exact'];
            } else {
                // Phonetic matching (Soundex + Metaphone)
                $phoneticScore = $this->phoneticMatch($s1, $s2);
                if ($phoneticScore > 0) {
                    $score += $weights['surname_phonetic'] * $phoneticScore;
                }

                // Levenshtein fuzzy matching
                $fuzzyScore = $this->levenshteinSimilarity($s1, $s2);
                if ($fuzzyScore >= 0.8) {
                    $score += $weights['surname_fuzzy'] * $fuzzyScore;
                }
            }
        }

        // Given name comparison with multiple matching methods
        if (!empty($p1->given_name) && !empty($p2->given_name)) {
            $g1 = strtolower(trim($p1->given_name));
            $g2 = strtolower(trim($p2->given_name));

            if ($g1 === $g2) {
                $score += $weights['given_exact'];
            } else {
                // Check first name specifically
                $first1 = explode(' ', $g1)[0];
                $first2 = explode(' ', $g2)[0];

                if ($first1 === $first2) {
                    $score += $weights['given_exact'] * 0.8;
                } else {
                    // Phonetic matching on first names
                    $phoneticScore = $this->phoneticMatch($first1, $first2);
                    if ($phoneticScore > 0) {
                        $score += $weights['given_phonetic'] * $phoneticScore;
                    }

                    // Levenshtein fuzzy matching on first names
                    $fuzzyScore = $this->levenshteinSimilarity($first1, $first2);
                    if ($fuzzyScore >= 0.75) {
                        $score += $weights['given_fuzzy'] * $fuzzyScore;
                    }

                    // Check for common nickname patterns (e.g., William/Bill, Robert/Bob)
                    if ($this->areNicknameVariants($first1, $first2)) {
                        $score += $weights['given_exact'] * 0.7;
                    }
                }
            }
        }

        // Birth date comparison
        if (!empty($p1->birth_date) && !empty($p2->birth_date)) {
            $dateScore = $this->compareDates($p1->birth_date, $p2->birth_date);
            $score += $weights['birth_date'] * $dateScore;
        }

        // Death date comparison
        if (!empty($p1->death_date) && !empty($p2->death_date)) {
            $dateScore = $this->compareDates($p1->death_date, $p2->death_date);
            $score += $weights['death_date'] * $dateScore;
        }

        // Birth place comparison
        if (!empty($p1->birth_place) && !empty($p2->birth_place)) {
            $placeScore = $this->comparePlaces($p1->birth_place, $p2->birth_place);
            $score += $weights['birth_place'] * $placeScore;
        }

        // Gender match
        if (!empty($p1->gender) && !empty($p2->gender)) {
            if ($p1->gender === $p2->gender) {
                $score += $weights['gender'];
            }
        }

        // Same parents check (relationship context) - strong indicator
        if (isset($p1->id) && isset($p2->id)) {
            if ($this->haveSameParents($p1->id, $p2->id)) {
                $score += $weights['same_parents'];
            }
        }

        return min(1.0, $score); // Cap at 1.0
    }

    /**
     * Calculate phonetic similarity using multiple algorithms
     *
     * @param string $s1 First string
     * @param string $s2 Second string
     * @return float Score between 0 and 1
     */
    private function phoneticMatch(string $s1, string $s2): float
    {
        $matches = 0;
        $total = 2;

        // Soundex match
        if (soundex($s1) === soundex($s2)) {
            $matches++;
        }

        // Metaphone match (more accurate than soundex for many names)
        if (metaphone($s1) === metaphone($s2)) {
            $matches++;
        }

        return $matches / $total;
    }

    /**
     * Calculate Levenshtein similarity as a ratio
     *
     * @param string $s1 First string
     * @param string $s2 Second string
     * @return float Similarity ratio between 0 and 1
     */
    private function levenshteinSimilarity(string $s1, string $s2): float
    {
        $maxLen = max(strlen($s1), strlen($s2));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($s1, $s2);
        return 1 - ($distance / $maxLen);
    }

    /**
     * Check if two names are common nickname variants
     *
     * @param string $name1 First name
     * @param string $name2 Second name
     * @return bool True if they are nickname variants
     */
    private function areNicknameVariants(string $name1, string $name2): bool
    {
        $nicknames = [
            'william' => ['bill', 'billy', 'will', 'willy', 'liam'],
            'robert' => ['bob', 'bobby', 'rob', 'robbie', 'bert'],
            'richard' => ['dick', 'rick', 'richie', 'ricky'],
            'james' => ['jim', 'jimmy', 'jamie'],
            'john' => ['jack', 'johnny', 'jon'],
            'michael' => ['mike', 'mikey', 'mick'],
            'charles' => ['charlie', 'chuck', 'chas'],
            'edward' => ['ed', 'eddie', 'ted', 'teddy', 'ned'],
            'thomas' => ['tom', 'tommy'],
            'joseph' => ['joe', 'joey', 'jo'],
            'elizabeth' => ['liz', 'lizzy', 'beth', 'betty', 'eliza', 'ellie'],
            'margaret' => ['maggie', 'meg', 'peggy', 'marge', 'margie'],
            'catherine' => ['kate', 'katie', 'cathy', 'cat', 'katherine', 'kathryn'],
            'jennifer' => ['jen', 'jenny', 'jenna'],
            'patricia' => ['pat', 'patty', 'tricia', 'trish'],
            'rebecca' => ['becky', 'becca', 'bec'],
            'susan' => ['sue', 'susie', 'suzy'],
            'deborah' => ['deb', 'debbie', 'debra'],
            'dorothy' => ['dot', 'dotty', 'dottie'],
            'frances' => ['fran', 'frannie', 'fanny'],
            'alexander' => ['alex', 'sandy', 'xander'],
            'benjamin' => ['ben', 'benny', 'benji'],
            'christopher' => ['chris', 'kit', 'topher'],
            'daniel' => ['dan', 'danny'],
            'david' => ['dave', 'davey'],
            'donald' => ['don', 'donnie'],
            'douglas' => ['doug', 'dougie'],
            'frederick' => ['fred', 'freddy', 'freddie'],
            'george' => ['georgie'],
            'gerald' => ['gerry', 'jerry'],
            'gregory' => ['greg', 'gregg'],
            'henry' => ['hank', 'harry', 'hal'],
            'jacob' => ['jake', 'jack'],
            'jonathan' => ['jon', 'johnny', 'nathan'],
            'joshua' => ['josh'],
            'lawrence' => ['larry', 'lars'],
            'leonard' => ['leo', 'lenny', 'len'],
            'matthew' => ['matt', 'matty'],
            'nathaniel' => ['nate', 'nathan', 'nat'],
            'nicholas' => ['nick', 'nicky'],
            'patrick' => ['pat', 'paddy'],
            'peter' => ['pete', 'petey'],
            'phillip' => ['phil'],
            'raymond' => ['ray'],
            'ronald' => ['ron', 'ronnie'],
            'samuel' => ['sam', 'sammy'],
            'stephen' => ['steve', 'stevie', 'steven'],
            'theodore' => ['ted', 'teddy', 'theo'],
            'timothy' => ['tim', 'timmy'],
            'vincent' => ['vince', 'vinnie'],
            'walter' => ['walt', 'wally'],
            'anthony' => ['tony'],
            'barbara' => ['barb', 'barbie', 'babs'],
            'carolyn' => ['carol', 'carrie', 'caroline'],
            'christina' => ['chris', 'tina', 'christie', 'christine'],
            'cynthia' => ['cindy', 'cindi'],
            'diana' => ['di', 'diane'],
            'eleanor' => ['ellie', 'ella', 'nell', 'nelly'],
            'helen' => ['nell', 'nelly'],
            'jessica' => ['jess', 'jessie'],
            'judith' => ['judy', 'judi'],
            'katherine' => ['kate', 'katie', 'kathy', 'kay', 'kit'],
            'linda' => ['lindy'],
            'louise' => ['lou'],
            'marilyn' => ['mary'],
            'nancy' => ['nan'],
            'pamela' => ['pam', 'pammy'],
            'rachel' => ['rae'],
            'samantha' => ['sam', 'sammy'],
            'sandra' => ['sandy', 'sadie'],
            'sarah' => ['sally', 'sadie'],
            'sharon' => ['shari'],
            'stephanie' => ['steph', 'stevie'],
            'theresa' => ['terry', 'tess', 'tessa', 'teresa'],
            'victoria' => ['vicky', 'vicki', 'tori'],
            'virginia' => ['ginny', 'ginger'],
        ];

        $name1 = strtolower($name1);
        $name2 = strtolower($name2);

        // Check if either name is a nickname of the other
        foreach ($nicknames as $formal => $variants) {
            $allForms = array_merge([$formal], $variants);

            if (in_array($name1, $allForms) && in_array($name2, $allForms)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare two dates and return a similarity score
     *
     * @param string $date1 First date
     * @param string $date2 Second date
     * @return float Score between 0 and 1
     */
    private function compareDates(string $date1, string $date2): float
    {
        // Exact match
        if ($date1 === $date2) {
            return 1.0;
        }

        // Extract years (GEDCOM dates can be complex)
        $year1 = $this->extractYear($date1);
        $year2 = $this->extractYear($date2);

        if ($year1 && $year2) {
            $diff = abs($year1 - $year2);

            if ($diff === 0) {
                return 0.8; // Same year, different format
            } elseif ($diff <= 1) {
                return 0.6; // Off by one year (common transcription error)
            } elseif ($diff <= 2) {
                return 0.4; // Within 2 years
            } elseif ($diff <= 5) {
                return 0.2; // Within 5 years
            }
        }

        return 0.0;
    }

    /**
     * Extract year from various date formats
     *
     * @param string $date Date string
     * @return int|null Year or null if not found
     */
    private function extractYear(string $date): ?int
    {
        // Match 4-digit year
        if (preg_match('/\b(1[0-9]{3}|20[0-2][0-9])\b/', $date, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Compare two place names and return a similarity score
     *
     * @param string $place1 First place
     * @param string $place2 Second place
     * @return float Score between 0 and 1
     */
    private function comparePlaces(string $place1, string $place2): float
    {
        $p1 = strtolower(trim($place1));
        $p2 = strtolower(trim($place2));

        // Exact match
        if ($p1 === $p2) {
            return 1.0;
        }

        // Check if one contains the other (e.g., "London" vs "London, England")
        if (str_contains($p1, $p2) || str_contains($p2, $p1)) {
            return 0.8;
        }

        // Check Levenshtein similarity
        $similarity = $this->levenshteinSimilarity($p1, $p2);
        if ($similarity >= 0.8) {
            return $similarity * 0.9;
        }

        // Split into parts and check overlap
        $parts1 = preg_split('/[,\s]+/', $p1);
        $parts2 = preg_split('/[,\s]+/', $p2);

        $matching = 0;
        foreach ($parts1 as $part1) {
            if (strlen($part1) >= 3) { // Skip short words
                foreach ($parts2 as $part2) {
                    if (strlen($part2) >= 3 && $part1 === $part2) {
                        $matching++;
                        break;
                    }
                }
            }
        }

        if ($matching > 0) {
            return min(0.7, $matching * 0.25);
        }

        return 0.0;
    }

    /**
     * Check if two persons have the same parents
     *
     * @param int $person1Id First person ID
     * @param int $person2Id Second person ID
     * @return bool True if they share at least one parent
     */
    private function haveSameParents(int $person1Id, int $person2Id): bool
    {
        try {
            // Get family IDs where each person is a child
            $sql = "
                SELECT DISTINCT f.id, f.husband_id, f.wife_id
                FROM genealogy_families f
                JOIN genealogy_children c ON c.family_id = f.id
                WHERE c.person_id = ?
            ";

            $families1 = DB::select($sql, [$person1Id]);
            $families2 = DB::select($sql, [$person2Id]);

            // Check for overlapping families or parents
            foreach ($families1 as $f1) {
                foreach ($families2 as $f2) {
                    // Same family
                    if ($f1->id === $f2->id) {
                        return true;
                    }
                    // Same father
                    if ($f1->husband_id && $f1->husband_id === $f2->husband_id) {
                        return true;
                    }
                    // Same mother
                    if ($f1->wife_id && $f1->wife_id === $f2->wife_id) {
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
            // Silent fail - don't break duplicate detection if this check fails
            Log::debug('Same parents check failed', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Get human-readable reasons for duplicate match
     */
    private function getDuplicateReasons(object $p1, object $p2): array
    {
        $reasons = [];

        // Surname matching reasons
        if (!empty($p1->surname) && !empty($p2->surname)) {
            $s1 = strtolower(trim($p1->surname));
            $s2 = strtolower(trim($p2->surname));

            if ($s1 === $s2) {
                $reasons[] = 'Exact surname match';
            } else {
                if (soundex($s1) === soundex($s2) || metaphone($s1) === metaphone($s2)) {
                    $reasons[] = 'Similar surname (phonetic)';
                }
                $similarity = $this->levenshteinSimilarity($s1, $s2);
                if ($similarity >= 0.8) {
                    $reasons[] = sprintf('Similar surname spelling (%.0f%% match)', $similarity * 100);
                }
            }
        }

        // Given name matching reasons
        if (!empty($p1->given_name) && !empty($p2->given_name)) {
            $g1 = strtolower(trim($p1->given_name));
            $g2 = strtolower(trim($p2->given_name));

            if ($g1 === $g2) {
                $reasons[] = 'Exact given name match';
            } else {
                $first1 = explode(' ', $g1)[0];
                $first2 = explode(' ', $g2)[0];

                if ($first1 === $first2) {
                    $reasons[] = 'First name match';
                } elseif ($this->areNicknameVariants($first1, $first2)) {
                    $reasons[] = 'Nickname variant match';
                } elseif (soundex($first1) === soundex($first2) || metaphone($first1) === metaphone($first2)) {
                    $reasons[] = 'Similar given name (phonetic)';
                } else {
                    $similarity = $this->levenshteinSimilarity($first1, $first2);
                    if ($similarity >= 0.75) {
                        $reasons[] = sprintf('Similar given name spelling (%.0f%% match)', $similarity * 100);
                    }
                }
            }
        }

        // Date matching reasons
        if (!empty($p1->birth_date) && !empty($p2->birth_date)) {
            if ($p1->birth_date === $p2->birth_date) {
                $reasons[] = 'Same birth date';
            } else {
                $year1 = $this->extractYear($p1->birth_date);
                $year2 = $this->extractYear($p2->birth_date);
                if ($year1 && $year2) {
                    $diff = abs($year1 - $year2);
                    if ($diff === 0) {
                        $reasons[] = 'Same birth year';
                    } elseif ($diff <= 2) {
                        $reasons[] = "Birth years within $diff year(s)";
                    }
                }
            }
        }

        if (!empty($p1->death_date) && !empty($p2->death_date)) {
            if ($p1->death_date === $p2->death_date) {
                $reasons[] = 'Same death date';
            } else {
                $year1 = $this->extractYear($p1->death_date);
                $year2 = $this->extractYear($p2->death_date);
                if ($year1 && $year2 && $year1 === $year2) {
                    $reasons[] = 'Same death year';
                }
            }
        }

        // Place matching reasons
        if (!empty($p1->birth_place) && !empty($p2->birth_place)) {
            $placeScore = $this->comparePlaces($p1->birth_place, $p2->birth_place);
            if ($placeScore >= 0.8) {
                $reasons[] = 'Similar birth place';
            } elseif ($placeScore >= 0.5) {
                $reasons[] = 'Birth place overlap';
            }
        }

        // Gender matching reason
        if (!empty($p1->gender) && $p1->gender === $p2->gender) {
            $reasons[] = 'Same gender';
        }

        // Same parents check
        if (isset($p1->id) && isset($p2->id) && $this->haveSameParents($p1->id, $p2->id)) {
            $reasons[] = 'Share same parent(s)';
        }

        return $reasons;
    }

    /**
     * Mark a duplicate pair as resolved (not duplicates) or to be merged
     *
     * @param int $treeId Tree ID
     * @param int $person1Id First person ID
     * @param int $person2Id Second person ID
     * @param string $status Status: 'rejected' (not duplicates), 'pending_merge', 'merged'
     * @return bool Success
     */
    public function resolveDuplicatePair(int $treeId, int $person1Id, int $person2Id, string $status): bool
    {
        // Normalize order
        $minId = min($person1Id, $person2Id);
        $maxId = max($person1Id, $person2Id);

        // Check if pair exists
        $existing = DB::selectOne("
            SELECT id FROM genealogy_duplicate_pairs
            WHERE tree_id = ? AND person1_id = ? AND person2_id = ?
        ", [$treeId, $minId, $maxId]);

        if ($existing) {
            DB::update("
                UPDATE genealogy_duplicate_pairs
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ", [$status, $existing->id]);
        } else {
            DB::insert("
                INSERT INTO genealogy_duplicate_pairs
                (tree_id, person1_id, person2_id, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ", [$treeId, $minId, $maxId, $status]);
        }

        return true;
    }

    /**
     * Merge two persons, keeping primary and transferring data from secondary
     *
     * @param int $treeId Tree ID
     * @param int $primaryId Person to keep (primary)
     * @param int $secondaryId Person to merge into primary (will be deleted)
     * @param array $options Merge options (keepSecondaryNames, keepSecondaryDates, etc.)
     * @return array Merge result with transferred data counts
     */
    public function mergePersons(int $treeId, int $primaryId, int $secondaryId, array $options = []): array
    {
        $result = [
            'success' => true,
            'primary_id' => $primaryId,
            'secondary_id' => $secondaryId,
            'transferred' => [
                'events' => 0,
                'facts' => 0,
                'names' => 0,
                'media' => 0,
                'citations' => 0,
                'notes' => 0,
                'families_as_child' => 0,
                'families_as_spouse' => 0,
            ],
            'errors' => [],
        ];

        try {
            DB::beginTransaction();

            // Verify both persons exist in tree
            $primary = DB::selectOne("SELECT * FROM genealogy_persons WHERE id = ? AND tree_id = ?", [$primaryId, $treeId]);
            $secondary = DB::selectOne("SELECT * FROM genealogy_persons WHERE id = ? AND tree_id = ?", [$secondaryId, $treeId]);

            if (!$primary || !$secondary) {
                throw new Exception('One or both persons not found in tree');
            }

            // Transfer events from secondary to primary
            $eventsUpdated = DB::update("
                UPDATE genealogy_events SET person_id = ? WHERE person_id = ?
            ", [$primaryId, $secondaryId]);
            $result['transferred']['events'] = $eventsUpdated;

            // genealogy_person_facts table does not exist — skip
            $result['transferred']['facts'] = 0;

            // Transfer alternate names if option enabled
            if ($options['keepSecondaryNames'] ?? true) {
                $secondaryName = trim(($secondary->given_name ?? '') . ' ' . ($secondary->surname ?? ''));
                $primaryName = trim(($primary->given_name ?? '') . ' ' . ($primary->surname ?? ''));
                if ($secondaryName && $secondaryName !== $primaryName) {
                    // Store secondary name as nickname if primary doesn't have one
                    if (empty($primary->nickname) && !empty($secondaryName)) {
                        DB::update("UPDATE genealogy_persons SET nickname = ? WHERE id = ?", [$secondaryName, $primaryId]);
                        $result['transferred']['names']++;
                    }
                }
            }

            // Transfer media links via genealogy_person_media junction table
            $mediaUpdated = DB::update("
                UPDATE genealogy_person_media SET person_id = ?
                WHERE person_id = ? AND media_id NOT IN (
                    SELECT media_id FROM genealogy_person_media WHERE person_id = ?
                )
            ", [$primaryId, $secondaryId, $primaryId]);
            $result['transferred']['media'] = $mediaUpdated;

            // Transfer citations
            $citationsUpdated = DB::update("
                UPDATE genealogy_citations SET person_id = ? WHERE person_id = ?
            ", [$primaryId, $secondaryId]);
            $result['transferred']['citations'] = $citationsUpdated;

            // Transfer shared note references from secondary to primary
            $notesUpdated = DB::update("
                UPDATE genealogy_shared_note_refs SET record_id = ?
                WHERE record_type = 'person' AND record_id = ?
            ", [$primaryId, $secondaryId]);
            $result['transferred']['notes'] = $notesUpdated;

            // Update family relationships - child
            $childFamilies = DB::update("
                UPDATE genealogy_children SET person_id = ?
                WHERE person_id = ?
            ", [$primaryId, $secondaryId]);
            $result['transferred']['families_as_child'] = $childFamilies;

            // Update family relationships - spouse (husband)
            $husbandFamilies = DB::update("
                UPDATE genealogy_families SET husband_id = ?
                WHERE husband_id = ? AND tree_id = ?
            ", [$primaryId, $secondaryId, $treeId]);

            // Update family relationships - spouse (wife)
            $wifeFamilies = DB::update("
                UPDATE genealogy_families SET wife_id = ?
                WHERE wife_id = ? AND tree_id = ?
            ", [$primaryId, $secondaryId, $treeId]);
            $result['transferred']['families_as_spouse'] = $husbandFamilies + $wifeFamilies;

            // Update research hints
            DB::update("
                UPDATE genealogy_research_hints SET person_id = ? WHERE person_id = ?
            ", [$primaryId, $secondaryId]);

            // Update external links
            DB::update("
                UPDATE genealogy_person_external_links SET person_id = ?
                WHERE person_id = ? AND service_type NOT IN (
                    SELECT service_type FROM genealogy_person_external_links WHERE person_id = ?
                )
            ", [$primaryId, $secondaryId, $primaryId]);

            // Optionally merge dates if primary is missing them
            if ($options['fillMissingDates'] ?? true) {
                $updates = [];
                $params = [];

                if (empty($primary->birth_date) && !empty($secondary->birth_date)) {
                    $updates[] = 'birth_date = ?';
                    $params[] = $secondary->birth_date;
                }
                if (empty($primary->birth_place) && !empty($secondary->birth_place)) {
                    $updates[] = 'birth_place = ?';
                    $params[] = $secondary->birth_place;
                }
                if (empty($primary->death_date) && !empty($secondary->death_date)) {
                    $updates[] = 'death_date = ?';
                    $params[] = $secondary->death_date;
                }
                if (empty($primary->death_place) && !empty($secondary->death_place)) {
                    $updates[] = 'death_place = ?';
                    $params[] = $secondary->death_place;
                }

                if (!empty($updates)) {
                    $params[] = $primaryId;
                    DB::update("UPDATE genealogy_persons SET " . implode(', ', $updates) . " WHERE id = ?", $params);
                }
            }

            // Delete secondary person
            DB::delete("DELETE FROM genealogy_persons WHERE id = ?", [$secondaryId]);

            // Mark duplicate pair as merged
            $this->resolveDuplicatePair($treeId, $primaryId, $secondaryId, 'merged');

            DB::commit();

            Log::info("Merged persons", ['primary' => $primaryId, 'secondary' => $secondaryId, 'result' => $result]);

        } catch (Exception $e) {
            DB::rollBack();
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error("Person merge failed", ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Get duplicate detection statistics for a tree
     */
    public function getDuplicateStats(int $treeId): array
    {
        // Count total pairs by status
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_pairs,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'pending_merge' THEN 1 ELSE 0 END) as pending_merge,
                SUM(CASE WHEN status = 'merged' THEN 1 ELSE 0 END) as merged,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM genealogy_duplicate_pairs
            WHERE tree_id = ?
        ", [$treeId]);

        return [
            'total_pairs' => (int)($stats->total_pairs ?? 0),
            'pending' => (int)($stats->pending ?? 0),
            'pending_merge' => (int)($stats->pending_merge ?? 0),
            'merged' => (int)($stats->merged ?? 0),
            'rejected' => (int)($stats->rejected ?? 0),
        ];
    }
}

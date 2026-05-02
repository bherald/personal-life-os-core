<?php

namespace App\Services\Genealogy;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Name Variant Service
 *
 * Manages alternative name forms for genealogy persons including
 * maiden names, married names, aliases, nicknames, and phonetic variants.
 */
class NameVariantService
{
    private ?AIService $aiService = null;

    private function getAIService(): \App\Services\AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(\App\Services\AIService::class);
        }
        return $this->aiService;
    }

    public function addVariant(
        int $personId,
        string $nameType,
        ?string $givenNames = null,
        ?string $surname = null,
        ?int $sourceId = null,
        ?string $notes = null
    ): int {
        $fullName = trim(($givenNames ?? '') . ' ' . ($surname ?? ''));

        DB::insert(
            "INSERT INTO genealogy_name_variants (person_id, name_type, given_names, surname, full_name, source_id, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [$personId, $nameType, $givenNames, $surname, $fullName ?: null, $sourceId, $notes]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function getVariants(int $personId): array
    {
        return DB::select(
            "SELECT gnv.*, gs.title as source_title
             FROM genealogy_name_variants gnv
             LEFT JOIN genealogy_sources gs ON gs.id = gnv.source_id
             WHERE gnv.person_id = ?
             ORDER BY gnv.name_type ASC, gnv.created_at ASC",
            [$personId]
        );
    }

    public function searchByVariant(string $name, int $treeId): array
    {
        $searchTerm = '%' . $name . '%';

        return DB::select(
            "SELECT DISTINCT gp.id, gp.given_name, gp.surname, gp.sex, gp.birth_date,
                    gnv.name_type, gnv.full_name as variant_name
             FROM genealogy_name_variants gnv
             JOIN genealogy_persons gp ON gp.id = gnv.person_id
             WHERE gp.tree_id = ?
             AND (gnv.full_name LIKE ? OR gnv.given_names LIKE ? OR gnv.surname LIKE ?)
             ORDER BY gp.surname, gp.given_name",
            [$treeId, $searchTerm, $searchTerm, $searchTerm]
        );
    }

    public function suggestVariants(int $personId): array
    {
        $person = DB::selectOne(
            "SELECT gp.*, gp.given_name, gp.surname, gp.sex
             FROM genealogy_persons gp
             WHERE gp.id = ?",
            [$personId]
        );

        if (!$person) {
            return [];
        }

        $suggestions = [];
        $existingVariants = $this->getVariants($personId);
        $existingTypes = array_column($existingVariants, 'name_type');

        // 1. Maiden name from marriage records (for females)
        if ($person->sex === 'F' && !in_array('maiden', $existingTypes)) {
            $families = DB::select(
                "SELECT gf.* FROM genealogy_families gf WHERE gf.wife_id = ?",
                [$personId]
            );
            if (!empty($families)) {
                $suggestions[] = [
                    'type' => 'maiden',
                    'reason' => 'Person has marriage records - maiden name may differ',
                    'given_names' => $person->given_name,
                    'surname' => null, // User needs to fill in
                ];
            }
        }

        // 2. Common nickname variants
        $nicknameMap = [
            'William' => ['Will', 'Bill', 'Billy', 'Willy', 'Liam'],
            'Elizabeth' => ['Beth', 'Liz', 'Betty', 'Eliza', 'Lizzie'],
            'Robert' => ['Bob', 'Rob', 'Bobby', 'Robbie', 'Bert'],
            'Margaret' => ['Maggie', 'Meg', 'Peggy', 'Marge', 'Greta'],
            'James' => ['Jim', 'Jimmy', 'Jamie'],
            'John' => ['Jack', 'Johnny', 'Jon'],
            'Charles' => ['Charlie', 'Chuck', 'Chas'],
            'Thomas' => ['Tom', 'Tommy', 'Thom'],
            'Richard' => ['Dick', 'Rick', 'Rich', 'Richie'],
            'Catherine' => ['Kate', 'Katie', 'Cathy', 'Kitty'],
            'Mary' => ['Molly', 'Polly', 'Mae'],
            'Joseph' => ['Joe', 'Joey', 'Jos'],
            'Edward' => ['Ed', 'Eddie', 'Ted', 'Ned'],
            'Henry' => ['Harry', 'Hal', 'Hank'],
            'Dorothy' => ['Dot', 'Dotty', 'Dottie'],
            'Michael' => ['Mike', 'Mick', 'Mickey'],
            'Patrick' => ['Pat', 'Paddy', 'Patty'],
            'Sarah' => ['Sally', 'Sadie'],
            'Andrew' => ['Andy', 'Drew'],
            'Alexander' => ['Alex', 'Sandy', 'Alec'],
        ];

        $firstName = explode(' ', $person->given_name ?? '')[0] ?? '';
        if (!in_array('nickname', $existingTypes)) {
            foreach ($nicknameMap as $formal => $nicknames) {
                if (strcasecmp($firstName, $formal) === 0) {
                    foreach ($nicknames as $nick) {
                        $suggestions[] = [
                            'type' => 'nickname',
                            'reason' => "Common nickname for {$formal}",
                            'given_names' => $nick,
                            'surname' => $person->surname,
                        ];
                    }
                    break;
                }
                // Reverse: if they have a nickname, suggest formal name
                foreach ($nicknames as $nick) {
                    if (strcasecmp($firstName, $nick) === 0) {
                        $suggestions[] = [
                            'type' => 'birth',
                            'reason' => "{$nick} is a common nickname for {$formal}",
                            'given_names' => $formal,
                            'surname' => $person->surname,
                        ];
                        break 2;
                    }
                }
            }
        }

        // 3. Soundex variants for surname
        if ($person->surname && !in_array('phonetic', $existingTypes)) {
            $soundex = soundex($person->surname);
            $phonetic = DB::select(
                "SELECT DISTINCT surname FROM genealogy_persons
                 WHERE SOUNDEX(surname) = ? AND surname != ? AND tree_id = (SELECT tree_id FROM genealogy_persons WHERE id = ?)
                 LIMIT 5",
                [$soundex, $person->surname, $personId]
            );

            foreach ($phonetic as $match) {
                $suggestions[] = [
                    'type' => 'phonetic',
                    'reason' => "Soundex match for {$person->surname}",
                    'given_names' => $person->given_name,
                    'surname' => $match->surname,
                ];
            }
        }

        return $suggestions;
    }

    public function mergeVariants(int $primaryPersonId, int $secondaryPersonId): int
    {
        $secondaryVariants = $this->getVariants($secondaryPersonId);
        $merged = 0;

        foreach ($secondaryVariants as $variant) {
            // Check for duplicates
            $exists = DB::selectOne(
                "SELECT id FROM genealogy_name_variants
                 WHERE person_id = ? AND name_type = ? AND COALESCE(surname, '') = COALESCE(?, '')",
                [$primaryPersonId, $variant->name_type, $variant->surname]
            );

            if (!$exists) {
                DB::update(
                    "UPDATE genealogy_name_variants SET person_id = ? WHERE id = ?",
                    [$primaryPersonId, $variant->id]
                );
                $merged++;
            }
        }

        Log::info('NameVariant: Merged variants', [
            'primary' => $primaryPersonId,
            'secondary' => $secondaryPersonId,
            'merged' => $merged,
        ]);

        return $merged;
    }

    public function deleteVariant(int $variantId): bool
    {
        return DB::delete("DELETE FROM genealogy_name_variants WHERE id = ?", [$variantId]) > 0;
    }

    // ========================================================================
    // N04 ENHANCEMENTS: Phonetic Algorithms & AI-Powered Generation
    // ========================================================================

    /**
     * Generate all phonetic variants for a surname using multiple algorithms
     *
     * @param string $surname Surname to analyze
     * @return array Phonetic codes and similar surnames
     */
    public function generatePhoneticVariants(string $surname): array
    {
        $results = [
            'original' => $surname,
            'soundex' => soundex($surname),
            'metaphone' => metaphone($surname),
            'double_metaphone' => $this->doubleMetaphone($surname),
            'nysiis' => $this->nysiis($surname),
            'cologne_phonetic' => $this->colognePhonetic($surname),
            'dm_soundex' => $this->daitchMokotoff($surname),           // N92
            'beider_morse' => $this->beiderMorseApprox($surname),      // N92
        ];

        return $results;
    }

    /**
     * Double Metaphone algorithm implementation
     * More accurate than standard Metaphone for European names
     *
     * @param string $string Input string
     * @return array Primary and secondary codes
     */
    public function doubleMetaphone(string $string): array
    {
        $string = strtoupper(trim($string));
        $primary = '';
        $secondary = '';
        $current = 0;
        $length = strlen($string);

        // Skip these when at start of word
        if ($length > 1 && in_array(substr($string, 0, 2), ['GN', 'KN', 'PN', 'WR', 'PS'])) {
            $current++;
        }

        // Initial X pronounced as Z
        if (substr($string, 0, 1) === 'X') {
            $primary .= 'S';
            $secondary .= 'S';
            $current++;
        }

        while ($current < $length) {
            $char = substr($string, $current, 1);

            switch ($char) {
                case 'A':
                case 'E':
                case 'I':
                case 'O':
                case 'U':
                case 'Y':
                    if ($current === 0) {
                        $primary .= 'A';
                        $secondary .= 'A';
                    }
                    $current++;
                    break;

                case 'B':
                    $primary .= 'P';
                    $secondary .= 'P';
                    $current += (substr($string, $current + 1, 1) === 'B') ? 2 : 1;
                    break;

                case 'C':
                    // Germanic CH
                    if (substr($string, $current, 2) === 'CH') {
                        $primary .= 'K';
                        $secondary .= 'K';
                        $current += 2;
                    } elseif (in_array(substr($string, $current, 2), ['CI', 'CE', 'CY'])) {
                        $primary .= 'S';
                        $secondary .= 'S';
                        $current += 1;
                    } else {
                        $primary .= 'K';
                        $secondary .= 'K';
                        $current += (substr($string, $current + 1, 1) === 'C') ? 2 : 1;
                    }
                    break;

                case 'D':
                    if (substr($string, $current, 2) === 'DG') {
                        $primary .= 'J';
                        $secondary .= 'J';
                        $current += 2;
                    } else {
                        $primary .= 'T';
                        $secondary .= 'T';
                        $current += (substr($string, $current + 1, 1) === 'D') ? 2 : 1;
                    }
                    break;

                case 'F':
                    $primary .= 'F';
                    $secondary .= 'F';
                    $current += (substr($string, $current + 1, 1) === 'F') ? 2 : 1;
                    break;

                case 'G':
                    if (substr($string, $current + 1, 1) === 'H') {
                        $current += 2;
                    } elseif (in_array(substr($string, $current, 2), ['GI', 'GE', 'GY'])) {
                        $primary .= 'J';
                        $secondary .= 'K';
                        $current += 1;
                    } else {
                        $primary .= 'K';
                        $secondary .= 'K';
                        $current += (substr($string, $current + 1, 1) === 'G') ? 2 : 1;
                    }
                    break;

                case 'H':
                    if ($current > 0 && in_array(substr($string, $current - 1, 1), ['A', 'E', 'I', 'O', 'U'])) {
                        $current++;
                    } else {
                        $primary .= 'H';
                        $secondary .= 'H';
                        $current++;
                    }
                    break;

                case 'J':
                    $primary .= 'J';
                    $secondary .= 'J';
                    $current += (substr($string, $current + 1, 1) === 'J') ? 2 : 1;
                    break;

                case 'K':
                    $primary .= 'K';
                    $secondary .= 'K';
                    $current += (substr($string, $current + 1, 1) === 'K') ? 2 : 1;
                    break;

                case 'L':
                    $primary .= 'L';
                    $secondary .= 'L';
                    $current += (substr($string, $current + 1, 1) === 'L') ? 2 : 1;
                    break;

                case 'M':
                    $primary .= 'M';
                    $secondary .= 'M';
                    $current += (substr($string, $current + 1, 1) === 'M') ? 2 : 1;
                    break;

                case 'N':
                    $primary .= 'N';
                    $secondary .= 'N';
                    $current += (substr($string, $current + 1, 1) === 'N') ? 2 : 1;
                    break;

                case 'P':
                    if (substr($string, $current + 1, 1) === 'H') {
                        $primary .= 'F';
                        $secondary .= 'F';
                        $current += 2;
                    } else {
                        $primary .= 'P';
                        $secondary .= 'P';
                        $current += (substr($string, $current + 1, 1) === 'P') ? 2 : 1;
                    }
                    break;

                case 'Q':
                    $primary .= 'K';
                    $secondary .= 'K';
                    $current += (substr($string, $current + 1, 1) === 'Q') ? 2 : 1;
                    break;

                case 'R':
                    $primary .= 'R';
                    $secondary .= 'R';
                    $current += (substr($string, $current + 1, 1) === 'R') ? 2 : 1;
                    break;

                case 'S':
                    if (substr($string, $current, 2) === 'SH') {
                        $primary .= 'X';
                        $secondary .= 'X';
                        $current += 2;
                    } elseif (substr($string, $current, 3) === 'SCH') {
                        $primary .= 'SK';
                        $secondary .= 'SK';
                        $current += 3;
                    } else {
                        $primary .= 'S';
                        $secondary .= 'S';
                        $current += (substr($string, $current + 1, 1) === 'S') ? 2 : 1;
                    }
                    break;

                case 'T':
                    if (substr($string, $current, 2) === 'TH') {
                        $primary .= '0';
                        $secondary .= 'T';
                        $current += 2;
                    } else {
                        $primary .= 'T';
                        $secondary .= 'T';
                        $current += (substr($string, $current + 1, 1) === 'T') ? 2 : 1;
                    }
                    break;

                case 'V':
                    $primary .= 'F';
                    $secondary .= 'F';
                    $current += (substr($string, $current + 1, 1) === 'V') ? 2 : 1;
                    break;

                case 'W':
                    if (substr($string, $current + 1, 1) === 'H') {
                        $primary .= 'A';
                        $secondary .= 'A';
                        $current += 2;
                    } elseif (in_array(substr($string, $current + 1, 1), ['A', 'E', 'I', 'O', 'U'])) {
                        $primary .= 'A';
                        $secondary .= 'F';
                        $current++;
                    } else {
                        $current++;
                    }
                    break;

                case 'X':
                    $primary .= 'KS';
                    $secondary .= 'KS';
                    $current += (substr($string, $current + 1, 1) === 'X') ? 2 : 1;
                    break;

                case 'Z':
                    $primary .= 'S';
                    $secondary .= 'S';
                    $current += (substr($string, $current + 1, 1) === 'Z') ? 2 : 1;
                    break;

                default:
                    $current++;
            }
        }

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    /**
     * NYSIIS (New York State Identification and Intelligence System) phonetic algorithm
     * Better for American names, especially multi-ethnic
     *
     * @param string $string Input string
     * @return string NYSIIS code
     */
    public function nysiis(string $string): string
    {
        $string = strtoupper(preg_replace('/[^A-Z]/', '', $string));
        if (strlen($string) === 0) {
            return '';
        }

        // First character transformations
        $first = substr($string, 0, 3);
        $replacements = [
            'MAC' => 'MCC', 'KN' => 'NN', 'K' => 'C', 'PH' => 'FF',
            'PF' => 'FF', 'SCH' => 'SSS',
        ];

        foreach ($replacements as $search => $replace) {
            if (strpos($string, $search) === 0) {
                $string = $replace . substr($string, strlen($search));
                break;
            }
        }

        // Last character transformations
        $lastReplacements = [
            'EE' => 'Y', 'IE' => 'Y', 'DT' => 'D', 'RT' => 'D',
            'RD' => 'D', 'NT' => 'D', 'ND' => 'D',
        ];

        foreach ($lastReplacements as $search => $replace) {
            if (substr($string, -strlen($search)) === $search) {
                $string = substr($string, 0, -strlen($search)) . $replace;
                break;
            }
        }

        $key = substr($string, 0, 1);
        $string = substr($string, 1);

        // Main transformation
        $transMap = [
            'EV' => 'AF', 'A' => 'A', 'E' => 'A', 'I' => 'A', 'O' => 'A', 'U' => 'A',
            'Q' => 'G', 'Z' => 'S', 'M' => 'N', 'KN' => 'N', 'K' => 'C',
            'SCH' => 'SSS', 'PH' => 'FF', 'H' => '',
            'W' => '',
        ];

        foreach ($transMap as $search => $replace) {
            $string = str_replace($search, $replace, $string);
        }

        // Remove duplicate consecutive characters
        $string = preg_replace('/(.)\1+/', '$1', $string);

        // Remove trailing S or trailing A
        $string = preg_replace('/[SA]$/', '', $string);

        // Remove trailing AY -> Y
        if (substr($string, -2) === 'AY') {
            $string = substr($string, 0, -2) . 'Y';
        }

        return substr($key . $string, 0, 6);
    }

    /**
     * Cologne Phonetic algorithm - excellent for German names
     *
     * @param string $string Input string
     * @return string Cologne phonetic code
     */
    public function colognePhonetic(string $string): string
    {
        $string = strtoupper(preg_replace('/[^A-Z]/', '', $string));
        if (strlen($string) === 0) {
            return '';
        }

        $code = '';
        $length = strlen($string);

        for ($i = 0; $i < $length; $i++) {
            $char = $string[$i];
            $prev = $i > 0 ? $string[$i - 1] : '';
            $next = $i < $length - 1 ? $string[$i + 1] : '';

            $digit = match ($char) {
                'A', 'E', 'I', 'J', 'O', 'U', 'Y' => '0',
                'B' => '1',
                'P' => !in_array($next, ['H']) ? '1' : '3',
                'D', 'T' => !in_array($next, ['C', 'S', 'Z']) ? '2' : '8',
                'F', 'V', 'W' => '3',
                'G', 'K', 'Q' => '4',
                'X' => in_array($prev, ['C', 'K', 'Q']) ? '8' : '48',
                'L' => '5',
                'M', 'N' => '6',
                'R' => '7',
                'S', 'Z' => '8',
                'C' => $i === 0 && in_array($next, ['A', 'H', 'K', 'L', 'O', 'Q', 'R', 'U', 'X'])
                    ? '4'
                    : (in_array($prev, ['S', 'Z']) ? '8' : (in_array($next, ['A', 'H', 'K', 'O', 'Q', 'U', 'X']) ? '4' : '8')),
                'H' => '',
                default => '',
            };

            $code .= $digit;
        }

        // Remove consecutive duplicates and leading zeros (except first)
        $code = preg_replace('/(.)\1+/', '$1', $code);
        if ($code === '') {
            return '';
        }
        $code = $code[0] . ltrim(substr($code, 1), '0');

        return $code;
    }

    /**
     * Find similar surnames across the tree using multiple phonetic algorithms
     *
     * @param int $treeId Tree ID
     * @param string $surname Surname to match
     * @param int $limit Max results
     * @return array Matching surnames with scores
     */
    /**
     * N68: Find similar names using embedding cosine similarity.
     *
     * Generates an embedding for the query name and finds closest matches
     * in the tree using pgvector. Complements phonetic matching by catching
     * cross-script, transliteration, and semantic name similarities that
     * phonetic algorithms miss.
     *
     * @param int $treeId Tree to search
     * @param string $name Name to find matches for
     * @param int $limit Max results
     * @param float $minSimilarity Minimum cosine similarity threshold
     * @return array Matched names with similarity scores
     */
    public function findEmbeddingSimilarNames(int $treeId, string $name, int $limit = 10, float $minSimilarity = 0.7): array
    {
        try {
            $aiService = app(\App\Services\AIService::class);

            // Generate embedding for the query name (with context for better representation)
            $embeddingText = "Person name: {$name}";
            $result = $aiService->generateEmbedding($embeddingText);

            if (!($result['success'] ?? false) || empty($result['embedding'])) {
                return [];
            }

            $embeddingStr = PgVector::literal($result['embedding']);

            // Search against pre-computed name embeddings if table exists,
            // otherwise fall back to on-the-fly comparison
            try {
                $matches = \Illuminate\Support\Facades\DB::connection('pgsql_rag')->select("
                    SELECT name, tree_id, person_count,
                           1 - (embedding <=> ?::vector) as similarity
                    FROM genealogy_name_embeddings
                    WHERE tree_id = ?
                      AND 1 - (embedding <=> ?::vector) >= ?
                    ORDER BY embedding <=> ?::vector ASC
                    LIMIT ?
                ", [$embeddingStr, $treeId, $embeddingStr, $minSimilarity, $embeddingStr, $limit]);

                return array_map(fn($m) => [
                    'name' => $m->name,
                    'similarity' => round((float) $m->similarity, 4),
                    'person_count' => (int) $m->person_count,
                    'match_method' => 'embedding',
                ], $matches);

            } catch (\Throwable $e) {
                // Table doesn't exist yet — fall back to brute-force comparison
                // against distinct surnames (slow but works without pre-computation)
                $surnames = DB::select(
                    "SELECT DISTINCT surname, COUNT(*) as cnt
                     FROM genealogy_persons
                     WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
                     GROUP BY surname
                     HAVING COUNT(*) >= 1
                     ORDER BY cnt DESC
                     LIMIT 200",
                    [$treeId]
                );

                $matches = [];
                foreach ($surnames as $row) {
                    if (strcasecmp($row->surname, $name) === 0) continue;

                    $targetResult = $aiService->generateEmbedding("Person name: {$row->surname}");
                    if (!($targetResult['success'] ?? false) || empty($targetResult['embedding'])) continue;

                    // Cosine similarity
                    $sim = $this->cosineSimilarity($result['embedding'], $targetResult['embedding']);
                    if ($sim >= $minSimilarity) {
                        $matches[] = [
                            'name' => $row->surname,
                            'similarity' => round($sim, 4),
                            'person_count' => (int) $row->cnt,
                            'match_method' => 'embedding_brute',
                        ];
                    }
                }

                usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
                return array_slice($matches, 0, $limit);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('NameVariant: Embedding similarity failed', ['name' => $name, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Cosine similarity between two vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        for ($i = 0, $len = min(count($a), count($b)); $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dotProduct / $denom : 0;
    }

    public function findPhoneticMatches(int $treeId, string $surname, int $limit = 20): array
    {
        $phonetics = $this->generatePhoneticVariants($surname);
        $matches = [];

        // Get all distinct surnames in the tree
        $surnames = DB::select(
            "SELECT DISTINCT surname, COUNT(*) as person_count
             FROM genealogy_persons
             WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
             GROUP BY surname",
            [$treeId]
        );

        foreach ($surnames as $row) {
            if (strcasecmp($row->surname, $surname) === 0) {
                continue; // Skip exact match
            }

            $targetPhonetics = $this->generatePhoneticVariants($row->surname);
            $score = 0;
            $matchedAlgorithms = [];

            // Compare each algorithm
            if ($phonetics['soundex'] === $targetPhonetics['soundex']) {
                $score += 20;
                $matchedAlgorithms[] = 'soundex';
            }
            if ($phonetics['metaphone'] === $targetPhonetics['metaphone']) {
                $score += 25;
                $matchedAlgorithms[] = 'metaphone';
            }
            if ($phonetics['double_metaphone']['primary'] === $targetPhonetics['double_metaphone']['primary']) {
                $score += 30;
                $matchedAlgorithms[] = 'double_metaphone_primary';
            }
            if ($phonetics['double_metaphone']['secondary'] === $targetPhonetics['double_metaphone']['secondary']) {
                $score += 15;
                $matchedAlgorithms[] = 'double_metaphone_secondary';
            }
            if ($phonetics['nysiis'] === $targetPhonetics['nysiis']) {
                $score += 25;
                $matchedAlgorithms[] = 'nysiis';
            }
            if ($phonetics['cologne_phonetic'] === $targetPhonetics['cologne_phonetic']) {
                $score += 20;
                $matchedAlgorithms[] = 'cologne';
            }
            // N92: Daitch-Mokotoff — strong for Eastern European/Jewish surnames
            if (!empty($phonetics['dm_soundex']) && !empty($targetPhonetics['dm_soundex'])
                && array_intersect($phonetics['dm_soundex'], $targetPhonetics['dm_soundex'])) {
                $score += 35;
                $matchedAlgorithms[] = 'daitch_mokotoff';
            }
            // N92: Beider-Morse approximation — broad international coverage
            if (!empty($phonetics['beider_morse']) && !empty($targetPhonetics['beider_morse'])
                && array_intersect($phonetics['beider_morse'], $targetPhonetics['beider_morse'])) {
                $score += 30;
                $matchedAlgorithms[] = 'beider_morse';
            }

            // Levenshtein distance bonus
            $levenshtein = levenshtein(strtolower($surname), strtolower($row->surname));
            if ($levenshtein <= 2) {
                $score += (3 - $levenshtein) * 10;
                $matchedAlgorithms[] = "levenshtein_{$levenshtein}";
            }

            if ($score > 0) {
                $matches[] = [
                    'surname' => $row->surname,
                    'person_count' => $row->person_count,
                    'score' => $score,
                    'matched_algorithms' => $matchedAlgorithms,
                    'phonetics' => $targetPhonetics,
                ];
            }
        }

        // Sort by score descending
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * Generate historical spelling variants for a surname
     * Based on common historical spelling patterns and immigration changes
     *
     * @param string $surname Surname
     * @return array Historical variants with explanations
     */
    public function generateHistoricalVariants(string $surname): array
    {
        $variants = [];
        $lower = strtolower($surname);

        // German/Yiddish to English transformations
        $germanPatterns = [
            '/^sch/' => ['sh', 'sk', 's'],         // Schneider -> Shnider, Snider
            '/mann$/' => ['man'],                   // Hoffmann -> Hoffman
            '/stein$/' => ['stine', 'steen'],       // Goldstein -> Goldsteen
            '/berg$/' => ['burg', 'berger'],        // Rosenberg -> Rosenburg
            '/thal$/' => ['tal', 'dell'],           // Rosenthal -> Rosental
            '/ü/' => ['u', 'ue'],                   // Müller -> Muller, Mueller
            '/ö/' => ['o', 'oe'],                   // Schröder -> Schroder
            '/ä/' => ['a', 'ae'],                   // Bär -> Bar, Baer
            '/ß/' => ['ss', 's'],                   // Strauß -> Strauss
            '/ei/' => ['ey', 'i', 'ee'],            // Meyer -> Mayer, Myer
            '/ie/' => ['ee', 'y'],                  // Schmied -> Schmeed
            '/witz$/' => ['vitz', 'vich', 'wich'],  // Horowitz -> Horovitz
            '/^k/' => ['c'],                        // Klein -> Clein
            '/ck$/' => ['k', 'c'],                  // Beck -> Bek
        ];

        foreach ($germanPatterns as $pattern => $replacements) {
            if (preg_match($pattern, $lower)) {
                foreach ($replacements as $replacement) {
                    $variant = preg_replace($pattern, $replacement, $lower);
                    if ($variant !== $lower) {
                        $variants[] = [
                            'variant' => ucfirst($variant),
                            'origin' => 'German/Yiddish anglicization',
                            'pattern' => $pattern,
                        ];
                    }
                }
            }
        }

        // Irish patterns
        $irishPatterns = [
            '/^o\'/' => ['o', ''],                  // O'Brien -> OBrien, Brien
            '/^mc/' => ['mac', "m'"],               // McDonald -> MacDonald
            '/^mac/' => ['mc'],                     // MacArthur -> McArthur
            '/gh$/' => ['', 'g'],                   // Kavanagh -> Kavana
        ];

        foreach ($irishPatterns as $pattern => $replacements) {
            if (preg_match($pattern, $lower)) {
                foreach ($replacements as $replacement) {
                    $variant = preg_replace($pattern, $replacement, $lower);
                    if ($variant !== $lower) {
                        $variants[] = [
                            'variant' => ucfirst($variant),
                            'origin' => 'Irish spelling variation',
                            'pattern' => $pattern,
                        ];
                    }
                }
            }
        }

        // Polish/Slavic patterns
        $slavicPatterns = [
            '/ski$/' => ['sky', 'skie', 'skij'],    // Kowalski -> Kowalsky
            '/wicz$/' => ['witch', 'vich', 'vitz'], // Markiewicz -> Markewitch
            '/cz/' => ['ch', 'tch', 'tz'],          // Kowalczyk -> Kowalchik
            '/sz/' => ['sh', 's'],                  // Koszewski -> Koshewski
            '/ł/' => ['l', 'w'],                    // Michał -> Michal
        ];

        foreach ($slavicPatterns as $pattern => $replacements) {
            if (preg_match($pattern, $lower)) {
                foreach ($replacements as $replacement) {
                    $variant = preg_replace($pattern, $replacement, $lower);
                    if ($variant !== $lower) {
                        $variants[] = [
                            'variant' => ucfirst($variant),
                            'origin' => 'Polish/Slavic anglicization',
                            'pattern' => $pattern,
                        ];
                    }
                }
            }
        }

        // Italian patterns
        $italianPatterns = [
            '/i$/' => ['e', 'y'],                   // Rossi -> Rosse, Rossy
            '/o$/' => ['a', 'i'],                   // Russo -> Russa
            '/cc/' => ['c', 'ch'],                  // Ricci -> Rici
            '/gg/' => ['g', 'j'],                   // Maggio -> Magio
        ];

        foreach ($italianPatterns as $pattern => $replacements) {
            if (preg_match($pattern, $lower)) {
                foreach ($replacements as $replacement) {
                    $variant = preg_replace($pattern, $replacement, $lower);
                    if ($variant !== $lower) {
                        $variants[] = [
                            'variant' => ucfirst($variant),
                            'origin' => 'Italian spelling variation',
                            'pattern' => $pattern,
                        ];
                    }
                }
            }
        }

        // Remove duplicates
        $seen = [];
        $unique = [];
        foreach ($variants as $variant) {
            $key = strtolower($variant['variant']);
            if (!isset($seen[$key]) && $key !== $lower) {
                $seen[$key] = true;
                $unique[] = $variant;
            }
        }

        return $unique;
    }

    /**
     * Use AI to generate intelligent name variants
     *
     * @param int $personId Person ID
     * @param array $context Additional context (birth place, time period, ethnicity)
     * @return array AI-generated variants with explanations
     */
    public function generateAIVariants(int $personId, array $context = []): array
    {
        try {
            $person = DB::selectOne(
                "SELECT gp.*, gt.name as tree_name
                 FROM genealogy_persons gp
                 JOIN genealogy_trees gt ON gt.id = gp.tree_id
                 WHERE gp.id = ?",
                [$personId]
            );

            if (!$person) {
                return ['error' => 'Person not found'];
            }

            $existingVariants = $this->getVariants($personId);

            $prompt = "Generate historical name variants for genealogy research.

Person: {$person->given_name} {$person->surname}
Sex: " . ($person->sex === 'M' ? 'Male' : ($person->sex === 'F' ? 'Female' : 'Unknown')) . "
Birth Date: " . ($person->birth_date ?? 'Unknown') . "
Birth Place: " . ($context['birth_place'] ?? $person->birth_place ?? 'Unknown') . "
Time Period: " . ($context['time_period'] ?? '19th-20th century') . "
Ethnicity/Origin: " . ($context['ethnicity'] ?? 'Unknown') . "

Existing variants: " . implode(', ', array_map(fn($v) => "{$v->name_type}: {$v->full_name}", $existingVariants)) . "

Generate 5-10 NEW name variants this person might appear under in historical records.
Consider: immigration name changes, phonetic spellings, nickname forms, maiden/married names, Americanization, census errors, illiterate recordings.

Return JSON array with format:
[{\"type\": \"nickname|maiden|married|immigration|phonetic|census_error|translation\", \"given_names\": \"...\", \"surname\": \"...\", \"explanation\": \"why this variant might exist\"}]";

            $response = $this->getAIService()->process($prompt, [
                'temperature' => 0.7,
                'max_tokens' => 1000,
                'purpose' => 'name_variant_generation',
            ]);

            // Parse JSON from response
            if (!($response['success'] ?? false) || empty($response['response'])) {
                return ['error' => 'AI call failed', 'raw' => $response['error'] ?? 'unknown'];
            }

            $responseText = $response['response'];
            if (preg_match('/\[[\s\S]*\]/', $responseText, $matches)) {
                $variants = json_decode($matches[0], true);
                if (is_array($variants)) {
                    Log::info('NameVariant: AI generated variants', [
                        'person_id' => $personId,
                        'count' => count($variants),
                    ]);
                    return $variants;
                }
            }

            return ['error' => 'Could not parse AI response', 'raw' => substr($responseText, 0, 500)];
        } catch (Exception $e) {
            Log::error('NameVariant: AI generation failed', [
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Bulk add variants from AI suggestions
     *
     * @param int $personId Person ID
     * @param array $variants Variants from generateAIVariants
     * @param int|null $sourceId Optional source ID
     * @return int Number of variants added
     */
    public function bulkAddVariants(int $personId, array $variants, ?int $sourceId = null): int
    {
        $added = 0;

        foreach ($variants as $variant) {
            if (empty($variant['given_names']) && empty($variant['surname'])) {
                continue;
            }

            $type = $variant['type'] ?? 'other';
            $notes = $variant['explanation'] ?? null;

            try {
                $this->addVariant(
                    $personId,
                    $type,
                    $variant['given_names'] ?? null,
                    $variant['surname'] ?? null,
                    $sourceId,
                    $notes
                );
                $added++;
            } catch (Exception $e) {
                Log::warning('NameVariant: Failed to add variant', [
                    'person_id' => $personId,
                    'variant' => $variant,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $added;
    }

    /**
     * Get comprehensive variant statistics for a tree
     *
     * @param int $treeId Tree ID
     * @return array Statistics
     */
    public function getTreeStatistics(int $treeId): array
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(DISTINCT gnv.person_id) as persons_with_variants,
                COUNT(*) as total_variants,
                COUNT(DISTINCT gnv.name_type) as variant_types_used
             FROM genealogy_name_variants gnv
             JOIN genealogy_persons gp ON gp.id = gnv.person_id
             WHERE gp.tree_id = ?",
            [$treeId]
        );

        $byType = DB::select(
            "SELECT gnv.name_type, COUNT(*) as count
             FROM genealogy_name_variants gnv
             JOIN genealogy_persons gp ON gp.id = gnv.person_id
             WHERE gp.tree_id = ?
             GROUP BY gnv.name_type
             ORDER BY count DESC",
            [$treeId]
        );

        $totalPersons = DB::selectOne(
            "SELECT COUNT(*) as count FROM genealogy_persons WHERE tree_id = ?",
            [$treeId]
        );

        return [
            'total_persons' => $totalPersons->count ?? 0,
            'persons_with_variants' => $stats->persons_with_variants ?? 0,
            'total_variants' => $stats->total_variants ?? 0,
            'variant_types_used' => $stats->variant_types_used ?? 0,
            'coverage_percent' => $totalPersons->count > 0
                ? round(($stats->persons_with_variants / $totalPersons->count) * 100, 1)
                : 0,
            'by_type' => array_column(
                array_map(fn($r) => ['type' => $r->name_type, 'count' => $r->count], $byType),
                'count', 'type'
            ),
        ];
    }

    // =========================================================================
    // N92: Daitch-Mokotoff Soundex
    // Designed for Slavic/Germanic/Yiddish surnames where standard soundex fails.
    // Returns array of possible codes (branching rules produce multiple codes).
    // =========================================================================

    public function daitchMokotoff(string $name): array
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-z]/', '', $name);
        if ($name === '') {
            return [];
        }

        // DM coding rules: [pattern, code_at_start, code_before_vowel, code_other]
        // Patterns checked longest-first. null = not coded (skip).
        $rules = [
            ['schtsch', '2', '4', '4'], ['schtsh', '2', '4', '4'], ['schtch', '2', '4', '4'],
            ['shtch', '2', '4', '4'], ['shtsh', '2', '4', '4'], ['stsch', '2', '4', '4'],
            ['ttsch', '4', '4', '4'], ['zhdzh', '2', '4', '4'],
            ['tsch', '4', '4', '4'], ['ttch', '4', '4', '4'], ['tch', '4', '4', '4'],
            ['sch', '4', '4', '4'], ['sht', '2', '43', '43'], ['shch', '2', '4', '4'],
            ['sh', '4', '4', '4'], ['stch', '2', '4', '4'], ['st', '2', '43', '43'],
            ['szcz', '2', '4', '4'], ['szcs', '2', '4', '4'],
            ['sz', '4', '4', '4'], ['sc', '2', '4', '4'],
            ['tch', '4', '4', '4'], ['th', '3', '3', '3'],
            ['ts', '4', '4', '4'], ['tz', '4', '4', '4'],
            ['zs', '4', '4', '4'], ['zh', '4', '4', '4'],
            ['zd', '2', '43', '43'], ['zdz', '2', '4', '4'],
            ['ch', '5', '5', '5'], ['ck', '5', '5', '5'],
            ['cz', '4', '4', '4'], ['cs', '4', '4', '4'],
            ['dz', '4', '4', '4'], ['ds', '4', '4', '4'],
            ['dt', '3', '3', '3'], ['ei', '0', '1', null],
            ['ey', '0', '1', null], ['eu', '1', '1', null],
            ['ia', '1', null, null], ['ie', '1', null, null],
            ['io', '1', null, null], ['iu', '1', null, null],
            ['mn', '66', '66', '66'], ['nm', '66', '66', '66'],
            ['ph', '7', '7', '7'], ['pf', '7', '7', '7'],
            ['rz', '94', '94', '94'], ['rs', '94', '94', '94'],
            ['fb', '7', '7', '7'],
            ['a', '0', null, null], ['e', '0', null, null],
            ['i', '0', null, null], ['o', '0', null, null],
            ['u', '0', null, null], ['y', '0', null, null],
            ['b', '7', '7', '7'], ['c', '5', '5', '5'],
            ['d', '3', '3', '3'], ['f', '7', '7', '7'],
            ['g', '5', '5', '5'], ['h', '5', '5', '5'],
            ['j', '1', '1', '1'], ['k', '5', '5', '5'],
            ['l', '8', '8', '8'], ['m', '6', '6', '6'],
            ['n', '6', '6', '6'], ['p', '7', '7', '7'],
            ['q', '5', '5', '5'], ['r', '9', '9', '9'],
            ['s', '4', '4', '4'], ['t', '3', '3', '3'],
            ['v', '7', '7', '7'], ['w', '7', '7', '7'],
            ['x', '54', '54', '54'], ['z', '4', '4', '4'],
        ];

        $codes = [''];
        $pos = 0;
        $len = strlen($name);
        $lastCode = '';

        while ($pos < $len) {
            $matched = false;
            foreach ($rules as [$pattern, $startCode, $beforeVowelCode, $otherCode]) {
                $pLen = strlen($pattern);
                if (substr($name, $pos, $pLen) === $pattern) {
                    $isStart = ($pos === 0);
                    $nextChar = $pos + $pLen < $len ? $name[$pos + $pLen] : '';
                    $isBeforeVowel = in_array($nextChar, ['a', 'e', 'i', 'o', 'u', 'y']);

                    if ($isStart) {
                        $code = $startCode;
                    } elseif ($isBeforeVowel) {
                        $code = $beforeVowelCode;
                    } else {
                        $code = $otherCode;
                    }

                    if ($code !== null && $code !== $lastCode) {
                        $codes = array_map(fn($c) => $c . $code, $codes);
                        $lastCode = $code;
                    }

                    $pos += $pLen;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $pos++;
            }

            // Limit code length to 6 digits
            $codes = array_map(fn($c) => substr($c, 0, 6), $codes);
        }

        // Pad to 6 digits
        $codes = array_map(fn($c) => str_pad($c, 6, '0'), $codes);

        return array_unique($codes);
    }

    // =========================================================================
    // N92: Beider-Morse Phonetic Approximation
    // Simplified implementation covering common international name transformations.
    // Full BMPM has 1000+ rules; this covers the most impactful patterns.
    // Returns array of phonetic codes for multi-origin name matching.
    // =========================================================================

    public function beiderMorseApprox(string $name): array
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-z]/', '', $name);
        if ($name === '') {
            return [];
        }

        // Generate multiple phonetic interpretations based on language-origin rules
        $variants = [$name];

        // Germanic transformations
        $germanRules = [
            '/sch/' => 'S', '/tsch/' => 'tS', '/ch/' => 'x',
            '/ck/' => 'k', '/ei/' => 'aj', '/ie/' => 'i',
            '/eu/' => 'oj', '/au/' => 'aw', '/tz/' => 'ts',
            '/dt/' => 't', '/th/' => 't', '/ph/' => 'f',
            '/pf/' => 'f', '/v/' => 'f', '/w/' => 'v',
            '/z/' => 'ts', '/ß/' => 's',
        ];

        // Slavic transformations
        $slavicRules = [
            '/sz/' => 'S', '/cz/' => 'tS', '/szcz/' => 'StS',
            '/rz/' => 'Z', '/dz/' => 'dz', '/dź/' => 'dZ',
            '/ć/' => 'tS', '/ś/' => 'S', '/ź/' => 'Z',
            '/ż/' => 'Z', '/ł/' => 'w', '/ó/' => 'u',
            '/wicz$/' => 'vitS', '/ski$/' => 'ski', '/ska$/' => 'ska',
            '/owski$/' => 'ovski', '/owska$/' => 'ovska',
        ];

        // Romance transformations
        $romanceRules = [
            '/gn/' => 'nj', '/gl(?=i)/' => 'lj', '/ci/' => 'tSi',
            '/ce/' => 'tSe', '/ch/' => 'k', '/cci/' => 'tSi',
            '/cce/' => 'tSe', '/tion/' => 'Sion',
        ];

        // Apply each rule set to generate variant phonetic forms
        foreach ([$germanRules, $slavicRules, $romanceRules] as $ruleSet) {
            $variant = $name;
            foreach ($ruleSet as $pattern => $replacement) {
                $variant = preg_replace($pattern, $replacement, $variant);
            }
            if ($variant !== $name) {
                $variants[] = $variant;
            }
        }

        // Common surname suffix normalization
        $suffixVariants = [
            '/(?:berg|burg)$/' => 'BRG',
            '/(?:stein|stain)$/' => 'STAJN',
            '/(?:mann?|man)$/' => 'MAN',
            '/(?:witz|vitz|vich|wich)$/' => 'VTS',
            '/(?:sky|ski|skiy)$/' => 'SKI',
            '/(?:enko|enko)$/' => 'ENKO',
            '/(?:ov|off|eff)$/' => 'OF',
            '/(?:sen|son|sson)$/' => 'SN',
        ];

        foreach ($suffixVariants as $pattern => $code) {
            if (preg_match($pattern, $name)) {
                $variants[] = preg_replace($pattern, $code, $name);
            }
        }

        // Generate metaphone-like codes for each variant
        $codes = [];
        foreach (array_unique($variants) as $v) {
            // Simplify to consonant skeleton (vowels removed except leading)
            $skeleton = preg_replace('/(?<=.)[aeiou]+/', '', $v);
            $codes[] = substr($skeleton, 0, 8);

            // Also add standard metaphone of variant
            $m = metaphone($v);
            if ($m) {
                $codes[] = $m;
            }
        }

        return array_values(array_unique(array_filter($codes)));
    }
}

<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * DnaMatchService - DNA Match Analysis and Management
 *
 * Handles DNA kit management, match imports, relationship predictions,
 * triangulation detection, and chromosome browser data.
 *
 * Uses DNA Painter cM ranges for relationship predictions.
 * Compatible with GEDmatch, Ancestry, 23andMe, FTDNA, MyHeritage formats.
 *
 * @see https://dnapainter.com/tools/sharedcmv4
 */
class DnaMatchService
{
    /**
     * DNA Painter Shared cM Project ranges (v4)
     * Format: [min_cm, max_cm, avg_cm] for each relationship
     *
     * @see https://dnapainter.com/tools/sharedcmv4
     */
    private const CM_RANGES = [
        // Immediate family
        'self/identical_twin'       => [3400, 3600, 3500],
        'parent_child'              => [3330, 3720, 3485],
        'full_sibling'              => [2209, 3384, 2613],

        // Close family
        'grandparent_grandchild'    => [1156, 2311, 1754],
        'aunt_uncle_niece_nephew'   => [1201, 2282, 1741],
        'half_sibling'              => [1160, 2436, 1759],
        'great_grandparent'         => [485, 1486, 850],
        'great_aunt_uncle'          => [251, 1572, 850],

        // First cousins
        'first_cousin'              => [553, 1225, 866],
        'first_cousin_once_removed' => [141, 851, 433],
        'first_cousin_twice_removed'=> [43, 531, 219],
        'half_first_cousin'         => [137, 856, 449],

        // Second cousins
        'second_cousin'             => [41, 592, 229],
        'second_cousin_once_removed'=> [14, 353, 122],
        'second_cousin_twice_removed'=> [0, 212, 71],
        'half_second_cousin'        => [9, 397, 112],

        // Third cousins
        'third_cousin'              => [0, 234, 73],
        'third_cousin_once_removed' => [0, 192, 48],
        'third_cousin_twice_removed'=> [0, 158, 31],
        'half_third_cousin'         => [0, 173, 36],

        // Fourth cousins
        'fourth_cousin'             => [0, 139, 35],
        'fourth_cousin_once_removed'=> [0, 126, 22],
        'fourth_cousin_twice_removed'=> [0, 117, 14],

        // Fifth+ cousins
        'fifth_cousin'              => [0, 117, 18],
        'fifth_cousin_once_removed' => [0, 99, 11],
        'sixth_cousin'              => [0, 71, 11],
        'seventh_cousin'            => [0, 50, 5],
        'eighth_cousin'             => [0, 35, 3],
    ];

    /**
     * Chromosome sizes in base pairs for validation
     */
    private const CHROMOSOME_SIZES = [
        1 => 248956422, 2 => 242193529, 3 => 198295559, 4 => 190214555,
        5 => 181538259, 6 => 170805979, 7 => 159345973, 8 => 145138636,
        9 => 138394717, 10 => 133797422, 11 => 135086622, 12 => 133275309,
        13 => 114364328, 14 => 107043718, 15 => 101991189, 16 => 90338345,
        17 => 83257441, 18 => 80373285, 19 => 58617616, 20 => 64444167,
        21 => 46709983, 22 => 50818468, 23 => 156040895, // X chromosome
    ];

    // ========================================================================
    // KIT MANAGEMENT
    // ========================================================================

    /**
     * Create a new DNA kit for a person
     *
     * @param int $personId Person ID
     * @param string $provider Kit provider (ancestry, 23andme, etc.)
     * @param array $data Additional kit data
     * @return int New kit ID
     */
    public function createKit(int $personId, string $provider, array $data = []): int
    {
        $validProviders = ['ancestry', '23andme', 'ftdna', 'myheritage', 'gedmatch', 'livingdna', 'other'];
        if (!in_array($provider, $validProviders)) {
            throw new InvalidArgumentException("Invalid kit provider: {$provider}");
        }

        $sql = "INSERT INTO genealogy_dna_kits
                (person_id, kit_provider, kit_id, raw_data_file, haplogroup_maternal,
                 haplogroup_paternal, ethnicity_estimate, uploaded_at, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        DB::insert($sql, [
            $personId,
            $provider,
            $data['kit_id'] ?? null,
            $data['raw_data_file'] ?? null,
            $data['haplogroup_maternal'] ?? null,
            $data['haplogroup_paternal'] ?? null,
            isset($data['ethnicity_estimate']) ? json_encode($data['ethnicity_estimate']) : null,
            $data['uploaded_at'] ?? null,
            $data['notes'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Get kit by ID
     *
     * @param int $kitId Kit ID
     * @return object|null Kit data
     */
    public function getKit(int $kitId): ?object
    {
        return DB::selectOne("
            SELECT k.*, p.given_name, p.surname, p.id as person_id
            FROM genealogy_dna_kits k
            JOIN genealogy_persons p ON p.id = k.person_id
            WHERE k.id = ?
        ", [$kitId]);
    }

    /**
     * Get all kits for a person
     *
     * @param int $personId Person ID
     * @return array Kits
     */
    public function getKitsForPerson(int $personId): array
    {
        return DB::select("
            SELECT * FROM genealogy_dna_kits
            WHERE person_id = ?
            ORDER BY kit_provider, created_at DESC
        ", [$personId]);
    }

    // ========================================================================
    // MATCH IMPORT
    // ========================================================================

    /**
     * Import DNA matches for a kit
     *
     * @param int $kitId Kit ID
     * @param array $matchData Array of match records
     * @return int Number of matches imported
     */
    public function importMatches(int $kitId, array $matchData): int
    {
        $kit = $this->getKit($kitId);
        if (!$kit) {
            throw new InvalidArgumentException("Kit not found: {$kitId}");
        }

        $imported = 0;

        foreach ($matchData as $match) {
            if (empty($match['match_name']) || !isset($match['shared_cm'])) {
                Log::warning('DnaMatchService: Skipping invalid match data', ['match' => $match]);
                continue;
            }

            $sharedCm = (float) $match['shared_cm'];
            $predictedRelationship = $this->calculateRelationship($sharedCm);

            // Check for existing match by provider ID
            $existing = null;
            if (!empty($match['match_provider_id'])) {
                $existing = DB::selectOne("
                    SELECT id FROM genealogy_dna_matches
                    WHERE kit_id = ? AND match_provider_id = ?
                ", [$kitId, $match['match_provider_id']]);
            }

            if ($existing) {
                // Update existing match
                DB::update("
                    UPDATE genealogy_dna_matches SET
                        match_name = ?,
                        shared_cm = ?,
                        shared_segments = ?,
                        longest_segment_cm = ?,
                        predicted_relationship = ?,
                        confidence_score = ?,
                        match_tree_url = ?,
                        match_tree_size = ?,
                        shared_ancestor_hints = ?,
                        last_updated = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $match['match_name'],
                    $sharedCm,
                    $match['shared_segments'] ?? 0,
                    $match['longest_segment_cm'] ?? null,
                    $predictedRelationship,
                    $match['confidence_score'] ?? $this->calculateConfidence($sharedCm),
                    $match['match_tree_url'] ?? null,
                    $match['match_tree_size'] ?? null,
                    isset($match['shared_ancestor_hints']) ? json_encode($match['shared_ancestor_hints']) : null,
                    $existing->id,
                ]);
            } else {
                // Insert new match
                DB::insert("
                    INSERT INTO genealogy_dna_matches
                    (kit_id, match_name, match_kit_id, match_provider_id, shared_cm, shared_segments,
                     longest_segment_cm, predicted_relationship, confidence_score, maternal_side,
                     match_tree_url, match_tree_size, shared_ancestor_hints, notes, match_date,
                     created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $kitId,
                    $match['match_name'],
                    $match['match_kit_id'] ?? null,
                    $match['match_provider_id'] ?? null,
                    $sharedCm,
                    $match['shared_segments'] ?? 0,
                    $match['longest_segment_cm'] ?? null,
                    $predictedRelationship,
                    $match['confidence_score'] ?? $this->calculateConfidence($sharedCm),
                    $match['maternal_side'] ?? null,
                    $match['match_tree_url'] ?? null,
                    $match['match_tree_size'] ?? null,
                    isset($match['shared_ancestor_hints']) ? json_encode($match['shared_ancestor_hints']) : null,
                    $match['notes'] ?? null,
                    $match['match_date'] ?? null,
                ]);
                $imported++;
            }
        }

        // Update kit last sync time
        DB::update("UPDATE genealogy_dna_kits SET last_match_sync = NOW() WHERE id = ?", [$kitId]);

        Log::info('DnaMatchService: Imported matches', [
            'kit_id' => $kitId,
            'total_provided' => count($matchData),
            'new_imported' => $imported,
        ]);

        return $imported;
    }

    /**
     * Import DNA segments for a match
     *
     * @param int $matchId Match ID
     * @param array $segments Array of segment data
     * @return int Number of segments imported
     */
    public function importSegments(int $matchId, array $segments): int
    {
        $match = DB::selectOne("SELECT id FROM genealogy_dna_matches WHERE id = ?", [$matchId]);
        if (!$match) {
            throw new InvalidArgumentException("Match not found: {$matchId}");
        }

        // Clear existing segments for this match
        DB::delete("DELETE FROM genealogy_dna_segments WHERE match_id = ?", [$matchId]);

        $imported = 0;

        foreach ($segments as $segment) {
            $chromosome = (int) $segment['chromosome'];
            if ($chromosome < 1 || $chromosome > 23) {
                Log::warning('DnaMatchService: Invalid chromosome', ['chromosome' => $chromosome]);
                continue;
            }

            $startPos = (int) $segment['start_position'];
            $endPos = (int) $segment['end_position'];

            // Validate positions
            if ($startPos >= $endPos) {
                Log::warning('DnaMatchService: Invalid segment positions', [
                    'start' => $startPos,
                    'end' => $endPos,
                ]);
                continue;
            }

            // Validate against chromosome size
            $maxSize = self::CHROMOSOME_SIZES[$chromosome] ?? 300000000;
            if ($endPos > $maxSize) {
                Log::warning('DnaMatchService: Segment exceeds chromosome size', [
                    'chromosome' => $chromosome,
                    'end_position' => $endPos,
                    'max_size' => $maxSize,
                ]);
                continue;
            }

            DB::insert("
                INSERT INTO genealogy_dna_segments
                (match_id, chromosome, start_position, end_position, cm_length, snp_count, is_full_ibd, side, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $matchId,
                $chromosome,
                $startPos,
                $endPos,
                $segment['cm_length'] ?? 0,
                $segment['snp_count'] ?? null,
                $segment['is_full_ibd'] ?? null,
                $segment['side'] ?? null,
            ]);
            $imported++;
        }

        Log::info('DnaMatchService: Imported segments', [
            'match_id' => $matchId,
            'segments_imported' => $imported,
        ]);

        return $imported;
    }

    // ========================================================================
    // RELATIONSHIP PREDICTION
    // ========================================================================

    /**
     * Calculate predicted relationship based on shared cM
     *
     * Uses DNA Painter Shared cM Project v4 ranges
     *
     * @param float $sharedCm Shared centiMorgans
     * @return string Predicted relationship(s)
     */
    public function calculateRelationship(float $sharedCm): string
    {
        if ($sharedCm <= 0) {
            return 'no_match';
        }

        // Check ranges in order of specificity
        if ($sharedCm >= 3400) {
            return 'parent/child or identical twin';
        }

        if ($sharedCm >= 2200) {
            return 'full sibling';
        }

        if ($sharedCm >= 1700) {
            return 'grandparent/grandchild, aunt/uncle, half sibling, or full sibling';
        }

        if ($sharedCm >= 1300) {
            return 'grandparent/grandchild, aunt/uncle, or half sibling';
        }

        if ($sharedCm >= 1150) {
            return 'grandparent/grandchild, half sibling, aunt/uncle, or great grandparent';
        }

        if ($sharedCm >= 680) {
            return 'first cousin, half aunt/uncle, great grandparent, or great aunt/uncle';
        }

        if ($sharedCm >= 550) {
            return 'first cousin or half aunt/uncle';
        }

        if ($sharedCm >= 400) {
            return 'first cousin, first cousin once removed, or half first cousin';
        }

        if ($sharedCm >= 230) {
            return 'first cousin once removed, half first cousin, or second cousin';
        }

        if ($sharedCm >= 130) {
            return 'second cousin, first cousin twice removed, or half second cousin';
        }

        if ($sharedCm >= 65) {
            return 'second cousin, second cousin once removed, or third cousin';
        }

        if ($sharedCm >= 35) {
            return 'third cousin, second cousin twice removed, or fourth cousin';
        }

        if ($sharedCm >= 15) {
            return 'fourth cousin or more distant';
        }

        return 'distant cousin (5th+) or possible false positive';
    }

    /**
     * Get all possible relationships for a given cM value
     *
     * @param float $sharedCm Shared centiMorgans
     * @return array Array of possible relationships with probabilities
     */
    public function getPossibleRelationships(float $sharedCm): array
    {
        $possibilities = [];

        foreach (self::CM_RANGES as $relationship => [$min, $max, $avg]) {
            if ($sharedCm >= $min && $sharedCm <= $max) {
                // Calculate probability based on distance from average
                $distance = abs($sharedCm - $avg);
                $range = $max - $min;
                $probability = $range > 0 ? max(0, 100 - ($distance / $range * 100)) : 100;

                $possibilities[] = [
                    'relationship' => str_replace('_', ' ', $relationship),
                    'probability' => round($probability, 1),
                    'range_min' => $min,
                    'range_max' => $max,
                    'range_avg' => $avg,
                ];
            }
        }

        // Sort by probability descending
        usort($possibilities, fn($a, $b) => $b['probability'] <=> $a['probability']);

        return $possibilities;
    }

    /**
     * Calculate confidence score for relationship prediction
     *
     * @param float $sharedCm Shared centiMorgans
     * @return float Confidence score 0-100
     */
    private function calculateConfidence(float $sharedCm): float
    {
        // Higher cM = more confidence (less overlap in relationship ranges)
        if ($sharedCm >= 1700) {
            return 95.0;
        }
        if ($sharedCm >= 1000) {
            return 85.0;
        }
        if ($sharedCm >= 500) {
            return 75.0;
        }
        if ($sharedCm >= 200) {
            return 60.0;
        }
        if ($sharedCm >= 100) {
            return 45.0;
        }
        if ($sharedCm >= 50) {
            return 30.0;
        }

        return 15.0;
    }

    // ========================================================================
    // TRIANGULATION
    // ========================================================================

    /**
     * Find triangulation groups for a kit
     *
     * Identifies sets of 3+ matches that share overlapping DNA segments,
     * indicating descent from a common ancestor.
     *
     * @param int $kitId Kit ID
     * @param int $minOverlapCm Minimum overlap in cM to consider (default 7)
     * @return array Array of triangulation groups
     */
    public function findTriangulations(int $kitId = 0, int $minOverlapCm = 7): array
    {
        if ($kitId <= 0) {
            $total = DB::selectOne("SELECT COUNT(*) as cnt FROM genealogy_dna_kits")->cnt ?? 0;
            if ($total === 0) {
                return ['error' => 'No DNA kits configured. Upload a raw DNA file via the DNA section to get started.', 'triangulations' => []];
            }
            return ['error' => 'kitId is required. Use list_dna_kits to find available kit IDs.', 'triangulations' => []];
        }

        $kit = $this->getKit($kitId);
        if (!$kit) {
            return ['error' => "Kit not found: {$kitId}", 'triangulations' => []];
        }

        // Get all matches with segments for this kit
        $matches = DB::select("
            SELECT m.id as match_id, m.match_name, m.shared_cm
            FROM genealogy_dna_matches m
            WHERE m.kit_id = ?
            AND EXISTS (SELECT 1 FROM genealogy_dna_segments s WHERE s.match_id = m.id)
            ORDER BY m.shared_cm DESC
        ", [$kitId]);

        if (count($matches) < 2) {
            return [];
        }

        $triangulations = [];
        $matchIds = array_column($matches, 'match_id');

        // For each chromosome, find overlapping segments between matches
        for ($chr = 1; $chr <= 23; $chr++) {
            // Get all segments for this chromosome across all matches
            $segments = DB::select("
                SELECT s.*, m.match_name
                FROM genealogy_dna_segments s
                JOIN genealogy_dna_matches m ON m.id = s.match_id
                WHERE m.kit_id = ?
                AND s.chromosome = ?
                ORDER BY s.start_position
            ", [$kitId, $chr]);

            if (count($segments) < 2) {
                continue;
            }

            // Find overlapping segment pairs
            for ($i = 0; $i < count($segments) - 1; $i++) {
                for ($j = $i + 1; $j < count($segments); $j++) {
                    $seg1 = $segments[$i];
                    $seg2 = $segments[$j];

                    // Skip if same match
                    if ($seg1->match_id === $seg2->match_id) {
                        continue;
                    }

                    // Calculate overlap
                    $overlapStart = max($seg1->start_position, $seg2->start_position);
                    $overlapEnd = min($seg1->end_position, $seg2->end_position);

                    if ($overlapStart < $overlapEnd) {
                        // Estimate overlap in cM (rough approximation)
                        $overlapBp = $overlapEnd - $overlapStart;
                        $seg1Ratio = $overlapBp / ($seg1->end_position - $seg1->start_position);
                        $seg2Ratio = $overlapBp / ($seg2->end_position - $seg2->start_position);
                        $overlapCm = ($seg1->cm_length * $seg1Ratio + $seg2->cm_length * $seg2Ratio) / 2;

                        if ($overlapCm >= $minOverlapCm) {
                            $triangulations[] = [
                                'chromosome' => $chr,
                                'match_1' => [
                                    'id' => $seg1->match_id,
                                    'name' => $seg1->match_name,
                                ],
                                'match_2' => [
                                    'id' => $seg2->match_id,
                                    'name' => $seg2->match_name,
                                ],
                                'overlap_start' => $overlapStart,
                                'overlap_end' => $overlapEnd,
                                'overlap_cm' => round($overlapCm, 2),
                            ];
                        }
                    }
                }
            }
        }

        // Store triangulations in database
        foreach ($triangulations as $tri) {
            $this->storeTriangulation($kitId, $tri);
        }

        Log::info('DnaMatchService: Found triangulations', [
            'kit_id' => $kitId,
            'count' => count($triangulations),
        ]);

        return $triangulations;
    }

    /**
     * Store a triangulation group
     *
     * @param int $kitId Kit ID
     * @param array $triangulation Triangulation data
     * @return int Triangulation ID
     */
    private function storeTriangulation(int $kitId, array $triangulation): int
    {
        // Check for existing
        $existing = DB::selectOne("
            SELECT id FROM genealogy_dna_triangulation
            WHERE kit_id = ?
            AND match_id_1 = ?
            AND match_id_2 = ?
            AND chromosome = ?
            AND overlap_start = ?
        ", [
            $kitId,
            $triangulation['match_1']['id'],
            $triangulation['match_2']['id'],
            $triangulation['chromosome'],
            $triangulation['overlap_start'],
        ]);

        if ($existing) {
            return $existing->id;
        }

        DB::insert("
            INSERT INTO genealogy_dna_triangulation
            (kit_id, match_id_1, match_id_2, chromosome, overlap_start, overlap_end, overlap_cm, confidence, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $kitId,
            $triangulation['match_1']['id'],
            $triangulation['match_2']['id'],
            $triangulation['chromosome'],
            $triangulation['overlap_start'],
            $triangulation['overlap_end'],
            $triangulation['overlap_cm'],
            $triangulation['overlap_cm'] >= 15 ? 'high' : ($triangulation['overlap_cm'] >= 10 ? 'medium' : 'low'),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    // ========================================================================
    // CHROMOSOME BROWSER
    // ========================================================================

    /**
     * Get chromosome browser data for visualization
     *
     * Returns segment data organized by chromosome for visual display.
     *
     * @param int $kitId Kit ID
     * @param array $options Options: chromosome, match_ids, min_cm
     * @return array Chromosome browser data
     */
    public function getChromosomeBrowser(int $kitId, array $options = []): array
    {
        $kit = $this->getKit($kitId);
        if (!$kit) {
            throw new InvalidArgumentException("Kit not found: {$kitId}");
        }

        $sql = "
            SELECT
                s.id as segment_id,
                s.chromosome,
                s.start_position,
                s.end_position,
                s.cm_length,
                s.snp_count,
                s.side,
                m.id as match_id,
                m.match_name,
                m.shared_cm as total_shared_cm,
                m.predicted_relationship,
                m.confirmed_relationship,
                m.is_starred
            FROM genealogy_dna_segments s
            JOIN genealogy_dna_matches m ON m.id = s.match_id
            WHERE m.kit_id = ?
        ";

        $params = [$kitId];

        if (!empty($options['chromosome'])) {
            $sql .= " AND s.chromosome = ?";
            $params[] = $options['chromosome'];
        }

        if (!empty($options['match_ids'])) {
            $placeholders = implode(',', array_fill(0, count($options['match_ids']), '?'));
            $sql .= " AND m.id IN ({$placeholders})";
            $params = array_merge($params, $options['match_ids']);
        }

        if (!empty($options['min_cm'])) {
            $sql .= " AND s.cm_length >= ?";
            $params[] = $options['min_cm'];
        }

        $sql .= " ORDER BY s.chromosome, s.start_position";

        $segments = DB::select($sql, $params);

        // Organize by chromosome
        $browserData = [
            'kit' => [
                'id' => $kit->id,
                'person_name' => trim($kit->given_name . ' ' . $kit->surname),
                'provider' => $kit->kit_provider,
            ],
            'chromosomes' => [],
            'summary' => [
                'total_segments' => 0,
                'total_matches' => 0,
                'total_cm' => 0,
            ],
        ];

        $matchIds = [];
        foreach ($segments as $segment) {
            $chr = $segment->chromosome;
            $chrLabel = $chr == 23 ? 'X' : (string) $chr;

            if (!isset($browserData['chromosomes'][$chrLabel])) {
                $browserData['chromosomes'][$chrLabel] = [
                    'number' => $chr,
                    'label' => $chrLabel,
                    'size' => self::CHROMOSOME_SIZES[$chr] ?? 0,
                    'segments' => [],
                ];
            }

            $browserData['chromosomes'][$chrLabel]['segments'][] = [
                'segment_id' => $segment->segment_id,
                'match_id' => $segment->match_id,
                'match_name' => $segment->match_name,
                'start' => $segment->start_position,
                'end' => $segment->end_position,
                'cm' => (float) $segment->cm_length,
                'snps' => $segment->snp_count,
                'side' => $segment->side,
                'relationship' => $segment->confirmed_relationship ?? $segment->predicted_relationship,
                'total_shared_cm' => (float) $segment->total_shared_cm,
                'is_starred' => (bool) $segment->is_starred,
            ];

            $browserData['summary']['total_segments']++;
            $browserData['summary']['total_cm'] += (float) $segment->cm_length;
            $matchIds[$segment->match_id] = true;
        }

        $browserData['summary']['total_matches'] = count($matchIds);
        $browserData['summary']['total_cm'] = round($browserData['summary']['total_cm'], 2);

        // Sort chromosomes by number
        ksort($browserData['chromosomes'], SORT_NATURAL);

        return $browserData;
    }

    // ========================================================================
    // MATCH QUERIES
    // ========================================================================

    /**
     * Get all DNA matches for a person (across all their kits)
     *
     * @param int $personId Person ID
     * @param array $options Options: min_cm, relationship, starred_only, limit
     * @return array Matches grouped by kit
     */
    public function getMatchesByPerson(int $personId, array $options = []): array
    {
        $kits = $this->getKitsForPerson($personId);

        if (empty($kits)) {
            return [];
        }

        $results = [];

        foreach ($kits as $kit) {
            $sql = "
                SELECT
                    m.*,
                    (SELECT COUNT(*) FROM genealogy_dna_segments s WHERE s.match_id = m.id) as segment_count
                FROM genealogy_dna_matches m
                WHERE m.kit_id = ?
                AND m.is_hidden = 0
            ";

            $params = [$kit->id];

            if (!empty($options['min_cm'])) {
                $sql .= " AND m.shared_cm >= ?";
                $params[] = $options['min_cm'];
            }

            if (!empty($options['relationship'])) {
                $sql .= " AND (m.predicted_relationship LIKE ? OR m.confirmed_relationship LIKE ?)";
                $params[] = '%' . $options['relationship'] . '%';
                $params[] = '%' . $options['relationship'] . '%';
            }

            if (!empty($options['starred_only'])) {
                $sql .= " AND m.is_starred = 1";
            }

            $sql .= " ORDER BY m.shared_cm DESC";

            if (!empty($options['limit'])) {
                $sql .= " LIMIT ?";
                $params[] = (int) $options['limit'];
            }

            $matches = DB::select($sql, $params);

            $results[] = [
                'kit' => [
                    'id' => $kit->id,
                    'provider' => $kit->kit_provider,
                    'kit_id' => $kit->kit_id,
                ],
                'matches' => $matches,
                'match_count' => count($matches),
            ];
        }

        return $results;
    }

    /**
     * Get a single match with full details
     *
     * @param int $matchId Match ID
     * @return array|null Match data with segments
     */
    public function getMatch(int $matchId): ?array
    {
        $match = DB::selectOne("
            SELECT m.*, k.kit_provider, k.person_id,
                   p.given_name, p.surname
            FROM genealogy_dna_matches m
            JOIN genealogy_dna_kits k ON k.id = m.kit_id
            JOIN genealogy_persons p ON p.id = k.person_id
            WHERE m.id = ?
        ", [$matchId]);

        if (!$match) {
            return null;
        }

        $result = (array) $match;

        // Get segments
        $result['segments'] = DB::select("
            SELECT * FROM genealogy_dna_segments
            WHERE match_id = ?
            ORDER BY chromosome, start_position
        ", [$matchId]);

        // Get possible relationships
        $result['possible_relationships'] = $this->getPossibleRelationships((float) $match->shared_cm);

        // Get triangulations involving this match
        $result['triangulations'] = DB::select("
            SELECT t.*, m1.match_name as match_1_name, m2.match_name as match_2_name
            FROM genealogy_dna_triangulation t
            LEFT JOIN genealogy_dna_matches m1 ON m1.id = t.match_id_1
            LEFT JOIN genealogy_dna_matches m2 ON m2.id = t.match_id_2
            WHERE t.match_id_1 = ? OR t.match_id_2 = ?
            ORDER BY t.chromosome, t.overlap_start
        ", [$matchId, $matchId]);

        return $result;
    }

    /**
     * Update match with confirmed relationship
     *
     * @param int $matchId Match ID
     * @param string $confirmedRelationship Confirmed relationship
     * @param int|null $commonAncestorId Common ancestor person ID
     * @return bool Success
     */
    public function confirmRelationship(int $matchId, string $confirmedRelationship, ?int $commonAncestorId = null): bool
    {
        $affected = DB::update("
            UPDATE genealogy_dna_matches SET
                confirmed_relationship = ?,
                common_ancestor_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$confirmedRelationship, $commonAncestorId, $matchId]);

        return $affected > 0;
    }

    /**
     * Star or unstar a match
     *
     * @param int $matchId Match ID
     * @param bool $starred Star state
     * @return bool Success
     */
    public function starMatch(int $matchId, bool $starred = true): bool
    {
        $affected = DB::update("
            UPDATE genealogy_dna_matches SET
                is_starred = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$starred ? 1 : 0, $matchId]);

        return $affected > 0;
    }

    /**
     * Hide or unhide a match
     *
     * @param int $matchId Match ID
     * @param bool $hidden Hidden state
     * @return bool Success
     */
    public function hideMatch(int $matchId, bool $hidden = true): bool
    {
        $affected = DB::update("
            UPDATE genealogy_dna_matches SET
                is_hidden = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$hidden ? 1 : 0, $matchId]);

        return $affected > 0;
    }

    // ========================================================================
    // STATISTICS
    // ========================================================================

    /**
     * Get DNA statistics for a kit
     *
     * @param int $kitId Kit ID
     * @return array Statistics
     */
    public function getKitStatistics(int $kitId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_matches,
                COUNT(CASE WHEN shared_cm >= 1700 THEN 1 END) as close_family_matches,
                COUNT(CASE WHEN shared_cm >= 400 AND shared_cm < 1700 THEN 1 END) as first_cousin_range,
                COUNT(CASE WHEN shared_cm >= 90 AND shared_cm < 400 THEN 1 END) as second_cousin_range,
                COUNT(CASE WHEN shared_cm < 90 THEN 1 END) as distant_matches,
                COUNT(CASE WHEN confirmed_relationship IS NOT NULL THEN 1 END) as confirmed_matches,
                COUNT(CASE WHEN common_ancestor_id IS NOT NULL THEN 1 END) as with_common_ancestor,
                COUNT(CASE WHEN is_starred = 1 THEN 1 END) as starred_matches,
                AVG(shared_cm) as avg_shared_cm,
                MAX(shared_cm) as max_shared_cm,
                SUM(shared_segments) as total_segments
            FROM genealogy_dna_matches
            WHERE kit_id = ? AND is_hidden = 0
        ", [$kitId]);

        $triangulationCount = DB::selectOne("
            SELECT COUNT(*) as count FROM genealogy_dna_triangulation WHERE kit_id = ?
        ", [$kitId]);

        return [
            'total_matches' => (int) $stats->total_matches,
            'close_family' => (int) $stats->close_family_matches,
            'first_cousin_range' => (int) $stats->first_cousin_range,
            'second_cousin_range' => (int) $stats->second_cousin_range,
            'distant_matches' => (int) $stats->distant_matches,
            'confirmed_matches' => (int) $stats->confirmed_matches,
            'with_common_ancestor' => (int) $stats->with_common_ancestor,
            'starred_matches' => (int) $stats->starred_matches,
            'avg_shared_cm' => round((float) $stats->avg_shared_cm, 2),
            'max_shared_cm' => (float) $stats->max_shared_cm,
            'total_segments' => (int) $stats->total_segments,
            'triangulation_groups' => (int) $triangulationCount->count,
        ];
    }
}

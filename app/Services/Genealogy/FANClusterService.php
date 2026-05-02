<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * FAN Cluster Service - Friends, Associates, Neighbors Research
 *
 * Implements professional genealogy FAN methodology for identifying research clusters.
 * The FAN principle recognizes that ancestors didn't live in isolation - they interacted
 * with friends, associates, and neighbors who may hold clues to family connections.
 *
 * FAN cluster analysis helps:
 * - Identify potential family connections through social networks
 * - Find migration patterns (families often migrated together)
 * - Discover witnesses who may be relatives
 * - Locate associates who appear in multiple records
 *
 * RAW SQL ONLY - NO Eloquent models per project rules.
 *
 * @see Elizabeth Shown Mills, "Evidence Explained"
 * @see Board for Certification of Genealogists - Genealogical Proof Standard
 * @see "Quick Lesson 11: Identity Problems & the FAN Principle" - Evidence Explained
 */
class FANClusterService
{
    /**
     * Relationship types for FAN cluster members
     */
    public const RELATIONSHIP_TYPES = [
        'friend' => 'Friend - social relationship',
        'associate' => 'Associate - business, legal, or professional relationship',
        'neighbor' => 'Neighbor - lived nearby (same household, next door, same street)',
        'witness' => 'Witness - appeared as witness on document (marriage, will, deed)',
        'business' => 'Business partner or associate',
        'church' => 'Church member - same congregation, godparent, baptism sponsor',
        'other' => 'Other documented relationship',
    ];

    /**
     * Source record types commonly used in FAN analysis
     */
    public const SOURCE_RECORD_TYPES = [
        'census' => 'Census record',
        'marriage' => 'Marriage record or certificate',
        'marriage_witness' => 'Witness on marriage record',
        'deed' => 'Land deed or property record',
        'probate' => 'Probate or estate record',
        'will' => 'Will or testament',
        'will_witness' => 'Witness on will',
        'church' => 'Church record (baptism, confirmation, membership)',
        'godparent' => 'Godparent or baptism sponsor',
        'military' => 'Military record',
        'tax' => 'Tax record',
        'voter' => 'Voter registration',
        'court' => 'Court record',
        'newspaper' => 'Newspaper mention',
        'directory' => 'City directory',
        'cemetery' => 'Cemetery or burial record',
        'bond' => 'Bond (marriage, estate, other)',
        'other' => 'Other source',
    ];

    /**
     * Confidence levels
     */
    public const CONFIDENCE_LEVELS = [
        'high' => 'High - clear documentation with unambiguous identification',
        'medium' => 'Medium - reasonable inference from available evidence',
        'low' => 'Low - possible connection requiring further research',
    ];

    // =========================================================================
    // CLUSTER MANAGEMENT
    // =========================================================================

    /**
     * Create a new FAN cluster for a person
     *
     * @param int $personId Person ID (research subject)
     * @param string $name Cluster name
     * @param array $options Additional options (research_period, location, notes)
     * @return int New cluster ID
     * @throws InvalidArgumentException
     */
    public function createCluster(int $personId, string $name, array $options = []): int
    {
        // Validate person exists
        $person = DB::selectOne("SELECT id, given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            throw new InvalidArgumentException("Person not found: {$personId}");
        }

        $sql = "INSERT INTO genealogy_fan_clusters
                (person_id, cluster_name, research_period, location, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())";

        DB::insert($sql, [
            $personId,
            $name,
            $options['research_period'] ?? null,
            $options['location'] ?? null,
            $options['notes'] ?? null,
        ]);

        $clusterId = (int) DB::getPdo()->lastInsertId();

        Log::info('FANClusterService: Cluster created', [
            'cluster_id' => $clusterId,
            'person_id' => $personId,
            'name' => $name,
        ]);

        return $clusterId;
    }

    /**
     * Get a cluster by ID
     *
     * @param int $clusterId Cluster ID
     * @return object|null
     */
    public function getCluster(int $clusterId): ?object
    {
        $sql = "SELECT c.*,
                       p.given_name, p.surname, p.birth_date, p.death_date,
                       (SELECT COUNT(*) FROM genealogy_fan_members WHERE cluster_id = c.id) as member_count
                FROM genealogy_fan_clusters c
                JOIN genealogy_persons p ON p.id = c.person_id
                WHERE c.id = ?";

        return DB::selectOne($sql, [$clusterId]);
    }

    /**
     * Get all clusters for a person
     *
     * @param int $personId Person ID
     * @return array
     */
    public function getClustersForPerson(int $personId): array
    {
        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM genealogy_fan_members WHERE cluster_id = c.id) as member_count
                FROM genealogy_fan_clusters c
                WHERE c.person_id = ?
                ORDER BY c.created_at DESC";

        return DB::select($sql, [$personId]);
    }

    /**
     * Update a cluster
     *
     * @param int $clusterId Cluster ID
     * @param array $data Update data
     * @return bool
     */
    public function updateCluster(int $clusterId, array $data): bool
    {
        $fields = [];
        $params = [];

        foreach (['cluster_name', 'research_period', 'location', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $clusterId;

        $sql = "UPDATE genealogy_fan_clusters SET " . implode(', ', $fields) . " WHERE id = ?";
        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete a cluster and all its members
     *
     * @param int $clusterId Cluster ID
     * @return bool
     */
    public function deleteCluster(int $clusterId): bool
    {
        // Members will cascade delete via FK
        return DB::delete("DELETE FROM genealogy_fan_clusters WHERE id = ?", [$clusterId]) > 0;
    }

    // =========================================================================
    // MEMBER MANAGEMENT
    // =========================================================================

    /**
     * Add a member to a FAN cluster
     *
     * @param int $clusterId Cluster ID
     * @param array $memberData Member data
     * @return int New member ID
     * @throws InvalidArgumentException
     */
    public function addMember(int $clusterId, array $memberData): int
    {
        // Validate cluster exists
        $cluster = $this->getCluster($clusterId);
        if (!$cluster) {
            throw new InvalidArgumentException("Cluster not found: {$clusterId}");
        }

        // Validate required fields
        if (empty($memberData['member_name'])) {
            throw new InvalidArgumentException("member_name is required");
        }
        if (empty($memberData['source_record_type'])) {
            throw new InvalidArgumentException("source_record_type is required");
        }

        // Validate relationship type
        $relationshipType = $memberData['relationship_type'] ?? 'other';
        if (!array_key_exists($relationshipType, self::RELATIONSHIP_TYPES)) {
            throw new InvalidArgumentException("Invalid relationship_type: {$relationshipType}");
        }

        // Validate confidence
        $confidence = $memberData['confidence'] ?? 'medium';
        if (!array_key_exists($confidence, self::CONFIDENCE_LEVELS)) {
            throw new InvalidArgumentException("Invalid confidence level: {$confidence}");
        }

        // Validate member_person_id if provided
        if (!empty($memberData['member_person_id'])) {
            $memberPerson = DB::selectOne("SELECT id FROM genealogy_persons WHERE id = ?", [$memberData['member_person_id']]);
            if (!$memberPerson) {
                throw new InvalidArgumentException("Member person not found: {$memberData['member_person_id']}");
            }
        }

        $sql = "INSERT INTO genealogy_fan_members
                (cluster_id, member_person_id, member_name, relationship_type,
                 source_record_type, source_citation, interaction_date,
                 interaction_description, confidence, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        DB::insert($sql, [
            $clusterId,
            $memberData['member_person_id'] ?? null,
            $memberData['member_name'],
            $relationshipType,
            $memberData['source_record_type'],
            $memberData['source_citation'] ?? null,
            $memberData['interaction_date'] ?? null,
            $memberData['interaction_description'] ?? null,
            $confidence,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Get all members of a cluster
     *
     * @param int $clusterId Cluster ID
     * @return array
     */
    public function getClusterMembers(int $clusterId): array
    {
        $sql = "SELECT m.*,
                       p.given_name as linked_given_name,
                       p.surname as linked_surname,
                       p.birth_date as linked_birth_date,
                       p.death_date as linked_death_date
                FROM genealogy_fan_members m
                LEFT JOIN genealogy_persons p ON p.id = m.member_person_id
                WHERE m.cluster_id = ?
                ORDER BY m.interaction_date ASC, m.member_name ASC";

        return DB::select($sql, [$clusterId]);
    }

    /**
     * Update a cluster member
     *
     * @param int $memberId Member ID
     * @param array $data Update data
     * @return bool
     */
    public function updateMember(int $memberId, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowedFields = [
            'member_person_id', 'member_name', 'relationship_type',
            'source_record_type', 'source_citation', 'interaction_date',
            'interaction_description', 'confidence'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $memberId;
        $sql = "UPDATE genealogy_fan_members SET " . implode(', ', $fields) . " WHERE id = ?";
        return DB::update($sql, $params) > 0;
    }

    /**
     * Remove a member from a cluster
     *
     * @param int $memberId Member ID
     * @return bool
     */
    public function removeMember(int $memberId): bool
    {
        return DB::delete("DELETE FROM genealogy_fan_members WHERE id = ?", [$memberId]) > 0;
    }

    /**
     * Link a cluster member to a person in the database
     *
     * @param int $memberId Member ID
     * @param int $personId Person ID to link
     * @return bool
     */
    public function linkMemberToPerson(int $memberId, int $personId): bool
    {
        // Validate person exists
        $person = DB::selectOne("SELECT id, given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            throw new InvalidArgumentException("Person not found: {$personId}");
        }

        return DB::update(
            "UPDATE genealogy_fan_members SET member_person_id = ? WHERE id = ?",
            [$personId, $memberId]
        ) > 0;
    }

    // =========================================================================
    // EXTRACTION FROM RECORDS
    // =========================================================================

    /**
     * Extract potential FAN members from census records
     *
     * Analyzes census events to find neighbors (same household or nearby enumeration).
     *
     * @param int $personId Person ID
     * @param int|null $year Optional census year filter
     * @return array Extracted potential FAN members
     */
    public function extractFromCensus(int $personId, ?int $year = null): array
    {
        $person = DB::selectOne("SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            throw new InvalidArgumentException("Person not found: {$personId}");
        }

        // Get census events for this person
        $yearFilter = $year ? "AND YEAR(e.event_date) = ?" : "";
        $params = [$personId];
        if ($year) {
            $params[] = $year;
        }

        $sql = "SELECT e.id, e.event_type, e.event_date, e.event_place, e.description
                FROM genealogy_events e
                WHERE e.person_id = ?
                  AND e.event_type = 'CENS'
                  {$yearFilter}
                ORDER BY e.event_date ASC";

        $censusEvents = DB::select($sql, $params);

        $results = [];
        foreach ($censusEvents as $event) {
            $censusYear = $event->event_date ? date('Y', strtotime($event->event_date)) : null;

            // Find other persons with census events at same location/year
            if ($event->event_place && $censusYear) {
                $neighbors = DB::select(
                    "SELECT DISTINCT p.id, p.given_name, p.surname, p.birth_date,
                            e.event_place, e.description
                     FROM genealogy_persons p
                     JOIN genealogy_events e ON e.person_id = p.id
                     WHERE p.tree_id = ?
                       AND p.id != ?
                       AND e.event_type = 'CENS'
                       AND YEAR(e.event_date) = ?
                       AND e.event_place LIKE ?
                     ORDER BY p.surname, p.given_name
                     LIMIT " . config('genealogy.fan_census_neighbor_limit', 100),
                    [
                        $person->tree_id,
                        $personId,
                        $censusYear,
                        '%' . $this->extractLocationKey($event->event_place) . '%',
                    ]
                );

                foreach ($neighbors as $neighbor) {
                    $results[] = [
                        'member_name' => trim($neighbor->given_name . ' ' . $neighbor->surname),
                        'member_person_id' => $neighbor->id,
                        'relationship_type' => 'neighbor',
                        'source_record_type' => 'census',
                        'interaction_date' => $event->event_date,
                        'interaction_description' => "Census {$censusYear}: {$event->event_place}",
                        'source_citation' => "Census {$censusYear}, {$event->event_place}",
                        'confidence' => 'medium',
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Extract witnesses from vital records (marriages, wills, deeds)
     *
     * @param int $personId Person ID
     * @return array Extracted witnesses
     */
    public function extractWitnesses(int $personId): array
    {
        $person = DB::selectOne("SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            throw new InvalidArgumentException("Person not found: {$personId}");
        }

        $results = [];

        // Check for marriages where this person was a spouse
        $marriages = DB::select(
            "SELECT f.id, f.marriage_date, f.marriage_place,
                    h.id as husband_id, h.given_name as husband_given, h.surname as husband_surname,
                    w.id as wife_id, w.given_name as wife_given, w.surname as wife_surname
             FROM genealogy_families f
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
             WHERE (f.husband_id = ? OR f.wife_id = ?)
               AND f.tree_id = ?",
            [$personId, $personId, $person->tree_id]
        );

        foreach ($marriages as $marriage) {
            // Look for associated notes or events that might mention witnesses
            $familyEvents = DB::select(
                "SELECT event_type, event_date, event_place, description
                 FROM genealogy_family_events
                 WHERE family_id = ?
                   AND (event_type = 'MARR' OR event_type = 'MARB' OR event_type = 'MARL')",
                [$marriage->id]
            );

            foreach ($familyEvents as $event) {
                if ($event->description && preg_match_all('/witness[es]*[:\s]+([^,;.]+)/i', $event->description, $matches)) {
                    foreach ($matches[1] as $witnessName) {
                        $witnessName = trim($witnessName);
                        if (!empty($witnessName)) {
                            $results[] = [
                                'member_name' => $witnessName,
                                'member_person_id' => $this->findPersonByName($person->tree_id, $witnessName),
                                'relationship_type' => 'witness',
                                'source_record_type' => 'marriage_witness',
                                'interaction_date' => $marriage->marriage_date ?? $event->event_date,
                                'interaction_description' => "Witness to marriage",
                                'source_citation' => "Marriage record, {$marriage->marriage_place}",
                                'confidence' => 'high',
                            ];
                        }
                    }
                }
            }
        }

        // Check events for witness mentions
        $events = DB::select(
            "SELECT event_type, event_date, event_place, description
             FROM genealogy_events
             WHERE person_id = ?
               AND description IS NOT NULL
               AND description LIKE '%witness%'",
            [$personId]
        );

        foreach ($events as $event) {
            if (preg_match_all('/witness[es]*[:\s]+([^,;.]+)/i', $event->description, $matches)) {
                foreach ($matches[1] as $witnessName) {
                    $witnessName = trim($witnessName);
                    if (!empty($witnessName)) {
                        $results[] = [
                            'member_name' => $witnessName,
                            'member_person_id' => $this->findPersonByName($person->tree_id, $witnessName),
                            'relationship_type' => 'witness',
                            'source_record_type' => strtolower($event->event_type) . '_witness',
                            'interaction_date' => $event->event_date,
                            'interaction_description' => "Witness to {$event->event_type}",
                            'source_citation' => "{$event->event_type} record, {$event->event_place}",
                            'confidence' => 'high',
                        ];
                    }
                }
            }
        }

        // Check sources/citations for witness mentions
        $citations = DB::select(
            "SELECT c.id, c.fact_type, c.page, c.text, s.title as source_title
             FROM genealogy_citations c
             JOIN genealogy_sources s ON s.id = c.source_id
             WHERE c.person_id = ?
               AND (c.text LIKE '%witness%' OR c.page LIKE '%witness%')",
            [$personId]
        );

        foreach ($citations as $citation) {
            $text = $citation->text ?? $citation->page ?? '';
            if (preg_match_all('/witness[es]*[:\s]+([^,;.]+)/i', $text, $matches)) {
                foreach ($matches[1] as $witnessName) {
                    $witnessName = trim($witnessName);
                    if (!empty($witnessName)) {
                        $results[] = [
                            'member_name' => $witnessName,
                            'member_person_id' => $this->findPersonByName($person->tree_id, $witnessName),
                            'relationship_type' => 'witness',
                            'source_record_type' => strtolower($citation->fact_type ?? 'other'),
                            'interaction_date' => null,
                            'interaction_description' => "Witness mentioned in source: {$citation->source_title}",
                            'source_citation' => $citation->source_title,
                            'confidence' => 'medium',
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Extract godparents and church associates
     *
     * @param int $personId Person ID
     * @return array Extracted church associates
     */
    public function extractChurchAssociates(int $personId): array
    {
        $person = DB::selectOne("SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            throw new InvalidArgumentException("Person not found: {$personId}");
        }

        $results = [];

        // Check for christening/baptism events with godparents
        $churchEvents = DB::select(
            "SELECT event_type, event_date, event_place, description
             FROM genealogy_events
             WHERE person_id = ?
               AND event_type IN ('CHR', 'BAPM', 'CONF', 'FCOM')
               AND description IS NOT NULL",
            [$personId]
        );

        foreach ($churchEvents as $event) {
            // Look for godparent mentions
            if (preg_match_all('/(?:godparent|sponsor|godfather|godmother)[s]*[:\s]+([^,;.]+)/i', $event->description, $matches)) {
                foreach ($matches[1] as $name) {
                    $name = trim($name);
                    if (!empty($name)) {
                        $results[] = [
                            'member_name' => $name,
                            'member_person_id' => $this->findPersonByName($person->tree_id, $name),
                            'relationship_type' => 'church',
                            'source_record_type' => 'godparent',
                            'interaction_date' => $event->event_date,
                            'interaction_description' => "Godparent/Sponsor at {$event->event_type}",
                            'source_citation' => "{$event->event_type} record, {$event->event_place}",
                            'confidence' => 'high',
                        ];
                    }
                }
            }
        }

        return $results;
    }

    // =========================================================================
    // CLUSTER ANALYSIS
    // =========================================================================

    /**
     * Analyze a FAN cluster for patterns and research opportunities
     *
     * @param int $clusterId Cluster ID
     * @return array Analysis results
     */
    public function analyzeCluster(int $clusterId): array
    {
        $cluster = $this->getCluster($clusterId);
        if (!$cluster) {
            throw new InvalidArgumentException("Cluster not found: {$clusterId}");
        }

        $members = $this->getClusterMembers($clusterId);

        // Analyze relationship type distribution
        $relationshipDistribution = [];
        foreach ($members as $member) {
            $type = $member->relationship_type;
            $relationshipDistribution[$type] = ($relationshipDistribution[$type] ?? 0) + 1;
        }

        // Analyze source record type distribution
        $sourceDistribution = [];
        foreach ($members as $member) {
            $type = $member->source_record_type;
            $sourceDistribution[$type] = ($sourceDistribution[$type] ?? 0) + 1;
        }

        // Find common locations
        $locations = [];
        foreach ($members as $member) {
            if ($member->source_citation) {
                // Extract location patterns from citations
                if (preg_match('/,\s*([A-Za-z\s]+(?:County|Parish|Township|Town|City)?)\s*,/i', $member->source_citation, $matches)) {
                    $loc = trim($matches[1]);
                    $locations[$loc] = ($locations[$loc] ?? 0) + 1;
                }
            }
        }
        arsort($locations);

        // Find date ranges
        $dates = [];
        foreach ($members as $member) {
            if ($member->interaction_date) {
                $dates[] = $member->interaction_date;
            }
        }
        sort($dates);

        // Find members appearing multiple times (recurring associates)
        $memberOccurrences = [];
        foreach ($members as $member) {
            $key = strtolower(trim($member->member_name));
            if (!isset($memberOccurrences[$key])) {
                $memberOccurrences[$key] = [
                    'name' => $member->member_name,
                    'person_id' => $member->member_person_id,
                    'count' => 0,
                    'relationships' => [],
                    'sources' => [],
                ];
            }
            $memberOccurrences[$key]['count']++;
            $memberOccurrences[$key]['relationships'][] = $member->relationship_type;
            $memberOccurrences[$key]['sources'][] = $member->source_record_type;
        }

        // Filter to recurring members (appear 2+ times)
        $recurringMembers = array_filter($memberOccurrences, fn($m) => $m['count'] >= 2);
        usort($recurringMembers, fn($a, $b) => $b['count'] - $a['count']);

        // Identify potential family members (recurring + witness relationships)
        $potentialFamily = [];
        foreach ($recurringMembers as $member) {
            if (in_array('witness', $member['relationships']) || $member['count'] >= 3) {
                $potentialFamily[] = $member;
            }
        }

        // Find linked vs unlinked members
        $linkedCount = 0;
        $unlinkedCount = 0;
        foreach ($members as $member) {
            if ($member->member_person_id) {
                $linkedCount++;
            } else {
                $unlinkedCount++;
            }
        }

        return [
            'cluster_id' => $clusterId,
            'cluster_name' => $cluster->cluster_name,
            'subject_person' => [
                'id' => $cluster->person_id,
                'name' => trim($cluster->given_name . ' ' . $cluster->surname),
            ],
            'total_members' => count($members),
            'linked_members' => $linkedCount,
            'unlinked_members' => $unlinkedCount,
            'relationship_distribution' => $relationshipDistribution,
            'source_distribution' => $sourceDistribution,
            'common_locations' => array_slice($locations, 0, 10, true),
            'date_range' => [
                'earliest' => $dates[0] ?? null,
                'latest' => end($dates) ?: null,
            ],
            'recurring_members' => array_slice($recurringMembers, 0, 20),
            'potential_family_connections' => $potentialFamily,
            'analysis_notes' => $this->generateAnalysisNotes($cluster, $members, $recurringMembers, $potentialFamily),
        ];
    }

    /**
     * Suggest research targets based on cluster analysis
     *
     * @param int $clusterId Cluster ID
     * @return array Research suggestions
     */
    public function suggestResearchTargets(int $clusterId): array
    {
        $analysis = $this->analyzeCluster($clusterId);
        $suggestions = [];

        // Suggest researching recurring associates
        foreach ($analysis['recurring_members'] as $member) {
            $priority = $member['count'] >= 3 ? 'high' : 'medium';
            if (in_array('witness', $member['relationships'])) {
                $priority = 'high';
            }

            $suggestions[] = [
                'type' => 'recurring_associate',
                'priority' => $priority,
                'target_name' => $member['name'],
                'target_person_id' => $member['person_id'],
                'reason' => "Appears {$member['count']} times in cluster as " . implode(', ', array_unique($member['relationships'])),
                'suggested_records' => $this->suggestRecordsForPerson($member['name'], $analysis['common_locations']),
            ];
        }

        // Suggest researching unlinked members who appear as witnesses
        $cluster = $this->getCluster($clusterId);
        $members = $this->getClusterMembers($clusterId);

        foreach ($members as $member) {
            if (!$member->member_person_id && $member->relationship_type === 'witness') {
                $suggestions[] = [
                    'type' => 'unlinked_witness',
                    'priority' => 'high',
                    'target_name' => $member->member_name,
                    'target_person_id' => null,
                    'reason' => "Witness to important event, not yet linked to tree",
                    'suggested_records' => $this->suggestRecordsForPerson($member->member_name, $analysis['common_locations']),
                ];
            }
        }

        // Suggest filling gaps in source types
        $missingSourceTypes = array_diff(
            ['census', 'marriage', 'deed', 'probate', 'church'],
            array_keys($analysis['source_distribution'])
        );

        foreach ($missingSourceTypes as $sourceType) {
            $suggestions[] = [
                'type' => 'source_gap',
                'priority' => 'medium',
                'source_type' => $sourceType,
                'reason' => "No {$sourceType} records in cluster - may reveal additional associates",
                'suggested_action' => $this->getSuggestedActionForSourceType($sourceType, $analysis),
            ];
        }

        // Suggest expanding date range research
        if ($analysis['date_range']['earliest'] && $analysis['date_range']['latest']) {
            $earliestYear = (int) date('Y', strtotime($analysis['date_range']['earliest']));
            $latestYear = (int) date('Y', strtotime($analysis['date_range']['latest']));

            // Suggest looking 10 years earlier
            $suggestions[] = [
                'type' => 'date_expansion',
                'priority' => 'low',
                'direction' => 'earlier',
                'suggested_period' => ($earliestYear - 10) . '-' . $earliestYear,
                'reason' => "Expand research to earlier period to find migration origins",
            ];

            // Suggest looking 10 years later
            $suggestions[] = [
                'type' => 'date_expansion',
                'priority' => 'low',
                'direction' => 'later',
                'suggested_period' => $latestYear . '-' . ($latestYear + 10),
                'reason' => "Expand research to later period to track family developments",
            ];
        }

        // Sort by priority
        usort($suggestions, function ($a, $b) {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($priorityOrder[$a['priority']] ?? 3) - ($priorityOrder[$b['priority']] ?? 3);
        });

        return $suggestions;
    }

    /**
     * Get cluster network data for visualization
     *
     * Returns data structure suitable for network visualization (D3.js, vis.js, etc.)
     *
     * @param int $clusterId Cluster ID
     * @return array Network data with nodes and edges
     */
    public function getClusterNetwork(int $clusterId): array
    {
        $cluster = $this->getCluster($clusterId);
        if (!$cluster) {
            throw new InvalidArgumentException("Cluster not found: {$clusterId}");
        }

        $members = $this->getClusterMembers($clusterId);

        $nodes = [];
        $edges = [];
        $memberNodes = [];

        // Add central person (research subject) as node
        $nodes[] = [
            'id' => 'person_' . $cluster->person_id,
            'label' => trim($cluster->given_name . ' ' . $cluster->surname),
            'type' => 'subject',
            'person_id' => $cluster->person_id,
            'group' => 'subject',
        ];

        // Add cluster members as nodes
        foreach ($members as $member) {
            $nodeId = $member->member_person_id
                ? 'person_' . $member->member_person_id
                : 'unlinked_' . $member->id;

            // Avoid duplicate nodes for same person
            if (!isset($memberNodes[$nodeId])) {
                $memberNodes[$nodeId] = [
                    'id' => $nodeId,
                    'label' => $member->member_name,
                    'type' => $member->member_person_id ? 'linked' : 'unlinked',
                    'person_id' => $member->member_person_id,
                    'member_id' => $member->id,
                    'group' => $member->relationship_type,
                    'connections' => 0,
                ];
            }
            $memberNodes[$nodeId]['connections']++;

            // Add edge from subject to member
            $edges[] = [
                'from' => 'person_' . $cluster->person_id,
                'to' => $nodeId,
                'label' => $member->relationship_type,
                'relationship_type' => $member->relationship_type,
                'source_record_type' => $member->source_record_type,
                'interaction_date' => $member->interaction_date,
                'confidence' => $member->confidence,
            ];
        }

        // Add member nodes
        foreach ($memberNodes as $node) {
            $nodes[] = $node;
        }

        // Find connections between members (same family, same location, etc.)
        $linkedMembers = array_filter($members, fn($m) => $m->member_person_id !== null);
        $memberPersonIds = array_map(fn($m) => $m->member_person_id, $linkedMembers);

        // Batch fetch all family relationships between cluster members in one query
        $familyEdges = [];
        if (count($memberPersonIds) >= 2) {
            $placeholders = implode(',', array_fill(0, count($memberPersonIds), '?'));
            $families = DB::select(
                "SELECT husband_id, wife_id FROM genealogy_families
                 WHERE husband_id IN ({$placeholders}) AND wife_id IN ({$placeholders})",
                array_merge($memberPersonIds, $memberPersonIds)
            );
            foreach ($families as $fam) {
                $familyEdges[$fam->husband_id . ':' . $fam->wife_id] = true;
                $familyEdges[$fam->wife_id . ':' . $fam->husband_id] = true;
            }
        }

        foreach ($linkedMembers as $i => $member1) {
            foreach (array_slice($linkedMembers, $i + 1) as $member2) {
                $pairKey = $member1->member_person_id . ':' . $member2->member_person_id;
                if (isset($familyEdges[$pairKey])) {
                    $edges[] = [
                        'from' => 'person_' . $member1->member_person_id,
                        'to' => 'person_' . $member2->member_person_id,
                        'label' => 'spouse',
                        'relationship_type' => 'family',
                    ];
                }

                // Check if same census location/year
                if ($member1->source_record_type === 'census' && $member2->source_record_type === 'census') {
                    $date1 = $member1->interaction_date ? date('Y', strtotime($member1->interaction_date)) : null;
                    $date2 = $member2->interaction_date ? date('Y', strtotime($member2->interaction_date)) : null;
                    if ($date1 && $date1 === $date2) {
                        $edges[] = [
                            'from' => 'person_' . $member1->member_person_id,
                            'to' => 'person_' . $member2->member_person_id,
                            'label' => 'same_census',
                            'relationship_type' => 'co-location',
                            'year' => $date1,
                        ];
                    }
                }
            }
        }

        return [
            'cluster_id' => $clusterId,
            'cluster_name' => $cluster->cluster_name,
            'subject' => [
                'id' => $cluster->person_id,
                'name' => trim($cluster->given_name . ' ' . $cluster->surname),
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'groups' => array_keys(self::RELATIONSHIP_TYPES),
            'stats' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'linked_members' => count(array_filter($memberNodes, fn($n) => $n['type'] === 'linked')),
                'unlinked_members' => count(array_filter($memberNodes, fn($n) => $n['type'] === 'unlinked')),
            ],
        ];
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Find a person by name in the tree
     *
     * @param int $treeId Tree ID
     * @param string $name Name to search
     * @return int|null Person ID if found
     */
    private function findPersonByName(int $treeId, string $name): ?int
    {
        $name = trim($name);
        if (empty($name)) {
            return null;
        }

        // Try exact match first
        $person = DB::selectOne(
            "SELECT id FROM genealogy_persons
             WHERE tree_id = ?
               AND CONCAT(given_name, ' ', surname) = ?
             LIMIT 1",
            [$treeId, $name]
        );

        if ($person) {
            return (int) $person->id;
        }

        // Try partial match
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            $givenName = $parts[0];
            $surname = end($parts);

            $person = DB::selectOne(
                "SELECT id FROM genealogy_persons
                 WHERE tree_id = ?
                   AND (given_name LIKE ? OR given_name = ?)
                   AND (surname LIKE ? OR surname = ?)
                 LIMIT 1",
                [$treeId, $givenName . '%', $givenName, $surname . '%', $surname]
            );

            if ($person) {
                return (int) $person->id;
            }
        }

        return null;
    }

    /**
     * Extract location key for matching (county, town, etc.)
     *
     * @param string $location Full location string
     * @return string Simplified location key
     */
    private function extractLocationKey(string $location): string
    {
        // Extract county or town for matching
        if (preg_match('/([A-Za-z\s]+(?:County|Parish|Township))/i', $location, $matches)) {
            return trim($matches[1]);
        }

        // Fall back to first significant word
        $parts = preg_split('/[,\/]+/', $location);
        return trim($parts[0] ?? $location);
    }

    /**
     * Generate analysis notes
     */
    private function generateAnalysisNotes(object $cluster, array $members, array $recurring, array $potentialFamily): array
    {
        $notes = [];

        if (count($potentialFamily) > 0) {
            $names = array_column($potentialFamily, 'name');
            $notes[] = "Potential family connections identified: " . implode(', ', array_slice($names, 0, 5));
        }

        if (count($recurring) > count($potentialFamily)) {
            $notes[] = count($recurring) . " individuals appear multiple times in the cluster - consider researching these associates.";
        }

        $unlinked = array_filter($members, fn($m) => $m->member_person_id === null);
        if (count($unlinked) > 0) {
            $notes[] = count($unlinked) . " cluster members are not yet linked to persons in the tree.";
        }

        return $notes;
    }

    /**
     * Suggest records to search for a person
     */
    private function suggestRecordsForPerson(string $name, array $locations): array
    {
        $suggestions = [];

        // Always suggest vital records
        $suggestions[] = "Search vital records (birth, marriage, death) for {$name}";

        // Suggest census based on locations
        foreach (array_slice($locations, 0, 3, true) as $location => $count) {
            $suggestions[] = "Search census records for {$name} in {$location}";
        }

        // Suggest probate/will
        $suggestions[] = "Check probate and will records for {$name} - may name family members";

        return $suggestions;
    }

    /**
     * Get suggested action for filling source type gap
     */
    private function getSuggestedActionForSourceType(string $sourceType, array $analysis): string
    {
        $locations = array_keys($analysis['common_locations']);
        $locationStr = !empty($locations) ? "in " . implode(', ', array_slice($locations, 0, 2)) : "";

        switch ($sourceType) {
            case 'census':
                return "Review census records {$locationStr} for the research period";
            case 'marriage':
                return "Check marriage records {$locationStr} for witnesses and bondsmen";
            case 'deed':
                return "Review land deed records {$locationStr} for neighboring landowners";
            case 'probate':
                return "Search probate records {$locationStr} for executors and witnesses";
            case 'church':
                return "Locate church records {$locationStr} for baptism sponsors and members";
            default:
                return "Search {$sourceType} records {$locationStr}";
        }
    }
}

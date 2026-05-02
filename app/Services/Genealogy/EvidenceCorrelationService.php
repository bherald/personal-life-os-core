<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Evidence Correlation Service
 *
 * Implements GPS (Genealogical Proof Standard) evidence correlation methodology:
 * - Timeline-based multi-source analysis
 * - Cross-source correlation for same person/event
 * - Agreement and conflict detection between sources
 * - Evidence chain building showing how conclusions are supported
 * - Correlation scoring based on source independence and agreement
 *
 * RAW SQL ONLY - NO Eloquent models per project rules.
 *
 * @see GPS methodology - correlation of evidence
 * @see Board for Certification of Genealogists standards
 */
class EvidenceCorrelationService
{
    /**
     * Evidence types per GPS methodology
     */
    public const EVIDENCE_TYPES = [
        'direct' => 'Direct evidence - explicitly answers the research question',
        'indirect' => 'Indirect evidence - requires inference to answer the question',
        'negative' => 'Negative evidence - absence of expected record',
    ];

    /**
     * Source information quality per GEDCOM 5.5.1
     */
    public const INFORMATION_QUALITY = [
        'primary' => 'Primary information - firsthand knowledge, participant/eyewitness',
        'secondary' => 'Secondary information - secondhand knowledge, reported by others',
        'indeterminate' => 'Indeterminate - informant unknown or cannot be determined',
    ];

    /**
     * Correlation status values
     */
    public const CORRELATION_STATUS = [
        'pending' => 'Awaiting analysis',
        'corroborates' => 'Sources agree and support each other',
        'conflicts' => 'Sources disagree on key facts',
        'supplements' => 'Sources provide complementary non-overlapping information',
        'resolved' => 'Conflict analyzed and resolved',
    ];

    /**
     * Event types that can be correlated
     */
    public const CORRELATABLE_EVENTS = [
        'BIRT' => 'Birth',
        'DEAT' => 'Death',
        'MARR' => 'Marriage',
        'DIV' => 'Divorce',
        'BURI' => 'Burial',
        'BAPM' => 'Baptism',
        'CHR' => 'Christening',
        'CENS' => 'Census',
        'OCCU' => 'Occupation',
        'RESI' => 'Residence',
        'IMMI' => 'Immigration',
        'EMIG' => 'Emigration',
        'NATU' => 'Naturalization',
        'MILI' => 'Military Service',
    ];

    // =========================================================================
    // EVIDENCE CORRELATION MANAGEMENT
    // =========================================================================

    /**
     * Create an evidence correlation between two source citations
     *
     * @param int $citation1Id First citation ID
     * @param int $citation2Id Second citation ID
     * @param string $eventType Event type being correlated (BIRT, DEAT, MARR, etc.)
     * @param array $options Additional options (notes, assessed_by)
     * @return int New correlation ID
     * @throws InvalidArgumentException
     */
    public function createCorrelation(
        int $citation1Id,
        int $citation2Id,
        string $eventType,
        array $options = []
    ): int {
        // Validate citations exist
        $citation1 = $this->getCitation($citation1Id);
        $citation2 = $this->getCitation($citation2Id);

        if (!$citation1) {
            throw new InvalidArgumentException("Citation not found: {$citation1Id}");
        }
        if (!$citation2) {
            throw new InvalidArgumentException("Citation not found: {$citation2Id}");
        }

        // Validate event type
        if (!array_key_exists($eventType, self::CORRELATABLE_EVENTS)) {
            throw new InvalidArgumentException("Invalid event type: {$eventType}");
        }

        // Ensure citations are for same person or related persons
        $personId1 = $citation1->person_id;
        $personId2 = $citation2->person_id;

        // Get tree_id from citation source
        $source1 = DB::selectOne("SELECT tree_id FROM genealogy_sources WHERE id = ?", [$citation1->source_id]);
        if (!$source1) {
            throw new InvalidArgumentException("Source not found for citation: {$citation1Id}");
        }
        $treeId = $source1->tree_id;

        // Check for existing correlation
        $existing = DB::selectOne(
            "SELECT id FROM evidence_correlations
             WHERE ((citation1_id = ? AND citation2_id = ?) OR (citation1_id = ? AND citation2_id = ?))
               AND event_type = ?",
            [$citation1Id, $citation2Id, $citation2Id, $citation1Id, $eventType]
        );

        if ($existing) {
            return (int) $existing->id;
        }

        $sql = "INSERT INTO evidence_correlations
                (tree_id, person_id, citation1_id, citation2_id, event_type,
                 status, correlation_score, notes, assessed_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NULL, ?, ?, NOW(), NOW())";

        DB::insert($sql, [
            $treeId,
            $personId1 ?? $personId2, // Use whichever person_id is available
            $citation1Id,
            $citation2Id,
            $eventType,
            $options['notes'] ?? null,
            $options['assessed_by'] ?? null,
        ]);

        $correlationId = (int) DB::getPdo()->lastInsertId();

        Log::info('EvidenceCorrelationService: Correlation created', [
            'correlation_id' => $correlationId,
            'citation1_id' => $citation1Id,
            'citation2_id' => $citation2Id,
            'event_type' => $eventType,
        ]);

        return $correlationId;
    }

    /**
     * Analyze and score a correlation
     *
     * @param int $correlationId Correlation to analyze
     * @return array Analysis results
     */
    public function analyzeCorrelation(int $correlationId): array
    {
        $correlation = $this->getCorrelation($correlationId);
        if (!$correlation) {
            throw new InvalidArgumentException("Correlation not found: {$correlationId}");
        }

        // Get citations with source details
        $citation1 = $this->getCitationWithSource($correlation->citation1_id);
        $citation2 = $this->getCitationWithSource($correlation->citation2_id);

        // Analyze date agreement
        $dateAnalysis = $this->analyzeDateAgreement(
            $correlation->event_type,
            $citation1,
            $citation2
        );

        // Analyze place agreement
        $placeAnalysis = $this->analyzePlaceAgreement(
            $correlation->event_type,
            $citation1,
            $citation2
        );

        // Analyze source independence
        $independenceAnalysis = $this->analyzeSourceIndependence(
            $citation1,
            $citation2
        );

        // Calculate overall correlation score
        $score = $this->calculateCorrelationScore(
            $dateAnalysis,
            $placeAnalysis,
            $independenceAnalysis
        );

        // Determine status based on analysis
        $status = $this->determineStatus($dateAnalysis, $placeAnalysis, $score);

        // Save analysis results
        $this->updateCorrelationAnalysis($correlationId, [
            'status' => $status,
            'correlation_score' => $score,
            'date_agreement' => $dateAnalysis['agreement'],
            'place_agreement' => $placeAnalysis['agreement'],
            'source_independence' => $independenceAnalysis['score'],
            'analysis_details' => json_encode([
                'date_analysis' => $dateAnalysis,
                'place_analysis' => $placeAnalysis,
                'independence_analysis' => $independenceAnalysis,
            ]),
        ]);

        return [
            'correlation_id' => $correlationId,
            'status' => $status,
            'score' => $score,
            'date_analysis' => $dateAnalysis,
            'place_analysis' => $placeAnalysis,
            'independence_analysis' => $independenceAnalysis,
            'recommendation' => $this->generateRecommendation($status, $score, $dateAnalysis, $placeAnalysis),
        ];
    }

    /**
     * Get correlation by ID
     */
    public function getCorrelation(int $correlationId): ?object
    {
        $sql = "SELECT ec.*,
                       c1.source_id as source1_id, c1.fact_type as fact_type1, c1.page as page1,
                       c2.source_id as source2_id, c2.fact_type as fact_type2, c2.page as page2,
                       s1.title as source1_title, s2.title as source2_title
                FROM evidence_correlations ec
                JOIN genealogy_citations c1 ON c1.id = ec.citation1_id
                JOIN genealogy_citations c2 ON c2.id = ec.citation2_id
                JOIN genealogy_sources s1 ON s1.id = c1.source_id
                JOIN genealogy_sources s2 ON s2.id = c2.source_id
                WHERE ec.id = ?";

        return DB::selectOne($sql, [$correlationId]);
    }

    /**
     * Get all correlations for a person
     */
    public function getPersonCorrelations(int $personId, ?string $eventType = null): array
    {
        $sql = "SELECT ec.*,
                       s1.title as source1_title, s2.title as source2_title
                FROM evidence_correlations ec
                JOIN genealogy_citations c1 ON c1.id = ec.citation1_id
                JOIN genealogy_citations c2 ON c2.id = ec.citation2_id
                JOIN genealogy_sources s1 ON s1.id = c1.source_id
                JOIN genealogy_sources s2 ON s2.id = c2.source_id
                WHERE ec.person_id = ?";

        $params = [$personId];

        if ($eventType) {
            $sql .= " AND ec.event_type = ?";
            $params[] = $eventType;
        }

        $sql .= " ORDER BY ec.event_type, ec.created_at DESC";

        return DB::select($sql, $params);
    }

    /**
     * Get correlations for a tree
     */
    public function getTreeCorrelations(int $treeId, array $filters = []): array
    {
        $sql = "SELECT ec.*,
                       p.given_name, p.surname,
                       s1.title as source1_title, s2.title as source2_title
                FROM evidence_correlations ec
                JOIN genealogy_persons p ON p.id = ec.person_id
                JOIN genealogy_citations c1 ON c1.id = ec.citation1_id
                JOIN genealogy_citations c2 ON c2.id = ec.citation2_id
                JOIN genealogy_sources s1 ON s1.id = c1.source_id
                JOIN genealogy_sources s2 ON s2.id = c2.source_id
                WHERE ec.tree_id = ?";

        $params = [$treeId];

        if (!empty($filters['status'])) {
            $sql .= " AND ec.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['event_type'])) {
            $sql .= " AND ec.event_type = ?";
            $params[] = $filters['event_type'];
        }

        if (!empty($filters['person_id'])) {
            $sql .= " AND ec.person_id = ?";
            $params[] = $filters['person_id'];
        }

        $sql .= " ORDER BY FIELD(ec.status, 'conflicts', 'pending', 'supplements', 'corroborates', 'resolved'), ec.created_at DESC";

        return DB::select($sql, $params);
    }

    // =========================================================================
    // TIMELINE ANALYSIS
    // =========================================================================

    /**
     * Build timeline from multiple sources for a person
     *
     * @param int $personId Person to analyze
     * @return array Timeline with events from all sources
     */
    public function buildPersonTimeline(int $personId): array
    {
        // Get all events for person with their source citations
        $events = DB::select("
            SELECT e.id as event_id, e.event_type, e.event_date,
                   e.event_place as place, e.description,
                   c.id as citation_id, c.quality as citation_quality,
                   s.id as source_id, s.title as source_title, s.author as source_author
            FROM genealogy_events e
            LEFT JOIN genealogy_citations c ON c.person_id = e.person_id AND c.fact_type = e.event_type
            LEFT JOIN genealogy_sources s ON s.id = c.source_id
            WHERE e.person_id = ?
            ORDER BY e.event_date ASC, e.event_type
        ", [$personId]);

        // Group by event type
        $timeline = [];
        foreach ($events as $event) {
            $key = $event->event_type . '_' . ($event->event_date ?? 'unknown');

            if (!isset($timeline[$key])) {
                $timeline[$key] = [
                    'event_type' => $event->event_type,
                    'event_date' => $event->event_date,
                    'place' => $event->place,
                    'description' => $event->description,
                    'sources' => [],
                    'source_count' => 0,
                    'agreement_status' => 'unknown',
                ];
            }

            if ($event->source_id) {
                $timeline[$key]['sources'][] = [
                    'source_id' => $event->source_id,
                    'source_title' => $event->source_title,
                    'source_author' => $event->source_author,
                    'citation_id' => $event->citation_id,
                    'citation_quality' => $event->citation_quality,
                ];
                $timeline[$key]['source_count']++;
            }
        }

        // Analyze agreement for multi-source events
        foreach ($timeline as $key => $event) {
            if ($event['source_count'] > 1) {
                $timeline[$key]['agreement_status'] = 'multi_source';
            } elseif ($event['source_count'] === 1) {
                $timeline[$key]['agreement_status'] = 'single_source';
            }
        }

        return array_values($timeline);
    }

    /**
     * Detect timeline conflicts across sources
     *
     * @param int $personId Person to analyze
     * @return array List of detected conflicts
     */
    public function detectTimelineConflicts(int $personId): array
    {
        $conflicts = [];

        // Get all events grouped by type
        $eventsByType = [];
        $events = DB::select("
            SELECT e.id, e.person_id, e.event_type, e.event_date, e.event_place as place,
                   e.description, e.source_id as event_source_id,
                   c.id as citation_id, c.source_id, s.title as source_title
            FROM genealogy_events e
            LEFT JOIN genealogy_citations c ON c.person_id = e.person_id AND c.fact_type = e.event_type
            LEFT JOIN genealogy_sources s ON s.id = c.source_id
            WHERE e.person_id = ?
        ", [$personId]);

        foreach ($events as $event) {
            $type = $event->event_type;
            if (!isset($eventsByType[$type])) {
                $eventsByType[$type] = [];
            }
            $eventsByType[$type][] = $event;
        }

        // Check for date conflicts within same event type
        foreach ($eventsByType as $type => $typeEvents) {
            if (count($typeEvents) < 2) continue;

            // Compare each pair
            for ($i = 0; $i < count($typeEvents); $i++) {
                for ($j = $i + 1; $j < count($typeEvents); $j++) {
                    $e1 = $typeEvents[$i];
                    $e2 = $typeEvents[$j];

                    // Skip if same source
                    if ($e1->source_id === $e2->source_id) continue;

                    // Check date conflict
                    if ($e1->event_date && $e2->event_date && $e1->event_date !== $e2->event_date) {
                        $daysDiff = abs(strtotime($e1->event_date) - strtotime($e2->event_date)) / 86400;

                        // Only flag as conflict if difference is significant
                        if ($daysDiff > 365) { // More than a year difference
                            $conflicts[] = [
                                'type' => 'date_conflict',
                                'event_type' => $type,
                                'event1' => [
                                    'date' => $e1->event_date,
                                    'place' => $e1->place,
                                    'source_title' => $e1->source_title,
                                    'citation_id' => $e1->citation_id,
                                ],
                                'event2' => [
                                    'date' => $e2->event_date,
                                    'place' => $e2->place,
                                    'source_title' => $e2->source_title,
                                    'citation_id' => $e2->citation_id,
                                ],
                                'severity' => $daysDiff > 3650 ? 'high' : 'medium',
                                'days_difference' => $daysDiff,
                            ];
                        }
                    }

                    // Check place conflict
                    if ($e1->place && $e2->place) {
                        $placeSimilarity = $this->calculatePlaceSimilarity($e1->place, $e2->place);
                        if ($placeSimilarity < 0.5) { // Less than 50% similar
                            $conflicts[] = [
                                'type' => 'place_conflict',
                                'event_type' => $type,
                                'event1' => [
                                    'date' => $e1->event_date,
                                    'place' => $e1->place,
                                    'source_title' => $e1->source_title,
                                    'citation_id' => $e1->citation_id,
                                ],
                                'event2' => [
                                    'date' => $e2->event_date,
                                    'place' => $e2->place,
                                    'source_title' => $e2->source_title,
                                    'citation_id' => $e2->citation_id,
                                ],
                                'severity' => 'medium',
                                'place_similarity' => $placeSimilarity,
                            ];
                        }
                    }
                }
            }
        }

        return $conflicts;
    }

    // =========================================================================
    // EVIDENCE CHAIN BUILDING
    // =========================================================================

    /**
     * Build evidence chain for a conclusion
     *
     * Shows how sources support a conclusion through corroboration.
     *
     * @param int $personId Person being researched
     * @param string $eventType Event type (BIRT, DEAT, etc.)
     * @return array Evidence chain structure
     */
    public function buildEvidenceChain(int $personId, string $eventType): array
    {
        // Get all sources for this person/event
        $sources = DB::select("
            SELECT c.id as citation_id, c.quality, c.text as citation_note,
                   s.id as source_id, s.title, s.author, s.publication,
                   e.event_date, e.event_place as place, e.description
            FROM genealogy_citations c
            JOIN genealogy_sources s ON s.id = c.source_id
            LEFT JOIN genealogy_events e ON e.person_id = c.person_id AND e.event_type = c.fact_type
            WHERE c.person_id = ? AND c.fact_type = ?
            ORDER BY c.quality DESC, s.title
        ", [$personId, $eventType]);

        if (empty($sources)) {
            return [
                'person_id' => $personId,
                'event_type' => $eventType,
                'chain_strength' => 0,
                'sources' => [],
                'summary' => 'No sources found for this event',
            ];
        }

        // Categorize sources by evidence type
        $chain = [
            'person_id' => $personId,
            'event_type' => $eventType,
            'primary_sources' => [],
            'secondary_sources' => [],
            'supporting_sources' => [],
        ];

        foreach ($sources as $source) {
            $sourceEntry = [
                'citation_id' => $source->citation_id,
                'source_id' => $source->source_id,
                'title' => $source->title,
                'author' => $source->author,
                'quality' => $source->quality,
                'event_date' => $source->event_date,
                'place' => $source->place,
            ];

            // Quality 3 = primary/direct evidence
            if ($source->quality >= 3) {
                $chain['primary_sources'][] = $sourceEntry;
            } elseif ($source->quality >= 2) {
                $chain['secondary_sources'][] = $sourceEntry;
            } else {
                $chain['supporting_sources'][] = $sourceEntry;
            }
        }

        // Calculate chain strength
        $chain['chain_strength'] = $this->calculateChainStrength($chain);

        // Generate summary
        $chain['summary'] = $this->generateChainSummary($chain);

        // Get correlations between sources in this chain
        $citationIds = array_column($sources, 'citation_id');
        $chain['correlations'] = $this->getChainCorrelations($citationIds);

        return $chain;
    }

    /**
     * Get correlations within an evidence chain
     */
    private function getChainCorrelations(array $citationIds): array
    {
        if (count($citationIds) < 2) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($citationIds), '?'));

        return DB::select("
            SELECT ec.*, s1.title as source1_title, s2.title as source2_title
            FROM evidence_correlations ec
            JOIN genealogy_citations c1 ON c1.id = ec.citation1_id
            JOIN genealogy_citations c2 ON c2.id = ec.citation2_id
            JOIN genealogy_sources s1 ON s1.id = c1.source_id
            JOIN genealogy_sources s2 ON s2.id = c2.source_id
            WHERE ec.citation1_id IN ({$placeholders})
              AND ec.citation2_id IN ({$placeholders})
        ", array_merge($citationIds, $citationIds));
    }

    /**
     * Calculate evidence chain strength
     */
    private function calculateChainStrength(array $chain): int
    {
        $score = 0;

        // Primary sources are most valuable
        $primaryCount = count($chain['primary_sources']);
        $score += min(40, $primaryCount * 20);

        // Secondary sources add support
        $secondaryCount = count($chain['secondary_sources']);
        $score += min(30, $secondaryCount * 10);

        // Supporting sources provide context
        $supportingCount = count($chain['supporting_sources']);
        $score += min(20, $supportingCount * 5);

        // Bonus for multiple independent sources
        $totalSources = $primaryCount + $secondaryCount + $supportingCount;
        if ($totalSources >= 3) {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * Generate chain summary
     */
    private function generateChainSummary(array $chain): string
    {
        $primary = count($chain['primary_sources']);
        $secondary = count($chain['secondary_sources']);
        $supporting = count($chain['supporting_sources']);
        $total = $primary + $secondary + $supporting;

        if ($total === 0) {
            return 'No sources document this event.';
        }

        $parts = [];
        if ($primary > 0) {
            $parts[] = "{$primary} primary source(s)";
        }
        if ($secondary > 0) {
            $parts[] = "{$secondary} secondary source(s)";
        }
        if ($supporting > 0) {
            $parts[] = "{$supporting} supporting source(s)";
        }

        $strength = $chain['chain_strength'];
        $assessment = match (true) {
            $strength >= 80 => 'Strong evidence chain with good corroboration.',
            $strength >= 60 => 'Moderate evidence chain. Additional sources would strengthen the conclusion.',
            $strength >= 40 => 'Weak evidence chain. More research is recommended.',
            default => 'Insufficient evidence. Significant research needed.',
        };

        return "Event documented by " . implode(', ', $parts) . ". {$assessment}";
    }

    // =========================================================================
    // AUTO-CORRELATION
    // =========================================================================

    /**
     * Auto-correlate all citations for a person
     *
     * Finds and creates correlations between citations for the same event type.
     *
     * @param int $personId Person to analyze
     * @return array Created correlations
     */
    public function autoCorrelatePersonCitations(int $personId): array
    {
        $createdCorrelations = [];

        // Get all citations grouped by fact type
        $citations = DB::select("
            SELECT c.id as citation_id, c.fact_type, c.source_id, c.quality,
                   s.title as source_title
            FROM genealogy_citations c
            JOIN genealogy_sources s ON s.id = c.source_id
            WHERE c.person_id = ?
            ORDER BY c.fact_type, c.id
        ", [$personId]);

        // Group by fact type
        $byFactType = [];
        foreach ($citations as $citation) {
            $type = $citation->fact_type;
            if (!isset($byFactType[$type])) {
                $byFactType[$type] = [];
            }
            $byFactType[$type][] = $citation;
        }

        // Create correlations for each pair within same fact type
        foreach ($byFactType as $factType => $typeCitations) {
            if (count($typeCitations) < 2) continue;
            if (!array_key_exists($factType, self::CORRELATABLE_EVENTS)) continue;

            for ($i = 0; $i < count($typeCitations); $i++) {
                for ($j = $i + 1; $j < count($typeCitations); $j++) {
                    try {
                        $correlationId = $this->createCorrelation(
                            $typeCitations[$i]->citation_id,
                            $typeCitations[$j]->citation_id,
                            $factType
                        );

                        $createdCorrelations[] = [
                            'correlation_id' => $correlationId,
                            'event_type' => $factType,
                            'source1' => $typeCitations[$i]->source_title,
                            'source2' => $typeCitations[$j]->source_title,
                        ];
                    } catch (\Exception $e) {
                        Log::warning('EvidenceCorrelationService: Auto-correlation failed', [
                            'citation1_id' => $typeCitations[$i]->citation_id,
                            'citation2_id' => $typeCitations[$j]->citation_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        Log::info('EvidenceCorrelationService: Auto-correlation complete', [
            'person_id' => $personId,
            'correlations_created' => count($createdCorrelations),
        ]);

        return $createdCorrelations;
    }

    /**
     * Auto-analyze all pending correlations for a person
     */
    public function analyzeAllPendingCorrelations(int $personId): array
    {
        $pending = DB::select("
            SELECT id FROM evidence_correlations
            WHERE person_id = ? AND status = 'pending'
        ", [$personId]);

        $results = [];
        foreach ($pending as $correlation) {
            $results[] = $this->analyzeCorrelation($correlation->id);
        }

        return $results;
    }

    // =========================================================================
    // CONFLICT RESOLUTION
    // =========================================================================

    /**
     * Record resolution for a conflicting correlation
     *
     * @param int $correlationId Correlation ID
     * @param string $resolution Resolution explanation
     * @param int|null $preferredCitationId Citation given preference (if any)
     * @param int|null $assessedBy User who assessed
     * @return bool Success
     */
    public function resolveConflict(
        int $correlationId,
        string $resolution,
        ?int $preferredCitationId = null,
        ?int $assessedBy = null
    ): bool {
        $correlation = $this->getCorrelation($correlationId);
        if (!$correlation) {
            throw new InvalidArgumentException("Correlation not found: {$correlationId}");
        }

        if ($correlation->status !== 'conflicts') {
            throw new InvalidArgumentException("Correlation is not in conflict status: {$correlation->status}");
        }

        $sql = "UPDATE evidence_correlations
                SET status = 'resolved',
                    resolution_notes = ?,
                    preferred_citation_id = ?,
                    assessed_by = ?,
                    assessed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?";

        $updated = DB::update($sql, [$resolution, $preferredCitationId, $assessedBy, $correlationId]) > 0;

        if ($updated) {
            Log::info('EvidenceCorrelationService: Conflict resolved', [
                'correlation_id' => $correlationId,
                'preferred_citation_id' => $preferredCitationId,
            ]);
        }

        return $updated;
    }

    /**
     * Get conflicts requiring resolution for a tree
     */
    public function getUnresolvedConflicts(int $treeId): array
    {
        return DB::select("
            SELECT ec.*,
                   p.given_name, p.surname,
                   s1.title as source1_title, s2.title as source2_title
            FROM evidence_correlations ec
            JOIN genealogy_persons p ON p.id = ec.person_id
            JOIN genealogy_citations c1 ON c1.id = ec.citation1_id
            JOIN genealogy_citations c2 ON c2.id = ec.citation2_id
            JOIN genealogy_sources s1 ON s1.id = c1.source_id
            JOIN genealogy_sources s2 ON s2.id = c2.source_id
            WHERE ec.tree_id = ? AND ec.status = 'conflicts'
            ORDER BY ec.created_at DESC
        ", [$treeId]);
    }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get correlation statistics for a person
     */
    public function getPersonCorrelationStats(int $personId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_correlations,
                SUM(CASE WHEN status = 'corroborates' THEN 1 ELSE 0 END) as corroborating,
                SUM(CASE WHEN status = 'conflicts' THEN 1 ELSE 0 END) as conflicting,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN status = 'supplements' THEN 1 ELSE 0 END) as supplementing,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                AVG(correlation_score) as avg_score
            FROM evidence_correlations
            WHERE person_id = ?
        ", [$personId]);

        return [
            'total_correlations' => (int) $stats->total_correlations,
            'corroborating' => (int) $stats->corroborating,
            'conflicting' => (int) $stats->conflicting,
            'resolved' => (int) $stats->resolved,
            'supplementing' => (int) $stats->supplementing,
            'pending' => (int) $stats->pending,
            'average_score' => $stats->avg_score ? round((float) $stats->avg_score, 1) : null,
            'evidence_strength' => $this->calculateOverallEvidenceStrength($stats),
        ];
    }

    /**
     * Get correlation statistics for a tree
     */
    public function getTreeCorrelationStats(int $treeId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_correlations,
                COUNT(DISTINCT person_id) as persons_with_correlations,
                SUM(CASE WHEN status = 'corroborates' THEN 1 ELSE 0 END) as corroborating,
                SUM(CASE WHEN status = 'conflicts' THEN 1 ELSE 0 END) as conflicting,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                AVG(correlation_score) as avg_score
            FROM evidence_correlations
            WHERE tree_id = ?
        ", [$treeId]);

        $eventTypeStats = DB::select("
            SELECT event_type, COUNT(*) as count,
                   AVG(correlation_score) as avg_score
            FROM evidence_correlations
            WHERE tree_id = ?
            GROUP BY event_type
            ORDER BY count DESC
        ", [$treeId]);

        return [
            'total_correlations' => (int) $stats->total_correlations,
            'persons_with_correlations' => (int) $stats->persons_with_correlations,
            'corroborating' => (int) $stats->corroborating,
            'conflicting' => (int) $stats->conflicting,
            'resolved' => (int) $stats->resolved,
            'average_score' => $stats->avg_score ? round((float) $stats->avg_score, 1) : null,
            'by_event_type' => $eventTypeStats,
        ];
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Get citation by ID
     */
    private function getCitation(int $citationId): ?object
    {
        return DB::selectOne("SELECT * FROM genealogy_citations WHERE id = ?", [$citationId]);
    }

    /**
     * Get citation with source details
     */
    private function getCitationWithSource(int $citationId): ?object
    {
        return DB::selectOne("
            SELECT c.*, s.title as source_title, s.author, s.publication,
                   e.event_date, e.event_place as place
            FROM genealogy_citations c
            JOIN genealogy_sources s ON s.id = c.source_id
            LEFT JOIN genealogy_events e ON e.person_id = c.person_id AND e.event_type = c.fact_type
            WHERE c.id = ?
        ", [$citationId]);
    }

    /**
     * Analyze date agreement between two citations
     */
    private function analyzeDateAgreement(string $eventType, ?object $citation1, ?object $citation2): array
    {
        if (!$citation1 || !$citation2) {
            return ['agreement' => null, 'notes' => 'Insufficient citation data'];
        }

        $date1 = $citation1->event_date ?? null;
        $date2 = $citation2->event_date ?? null;

        if (!$date1 || !$date2) {
            return ['agreement' => null, 'notes' => 'One or both sources lack date information'];
        }

        $daysDiff = abs(strtotime($date1) - strtotime($date2)) / 86400;

        if ($daysDiff == 0) {
            return ['agreement' => 100, 'notes' => 'Exact date match', 'days_difference' => 0];
        } elseif ($daysDiff <= 30) {
            return ['agreement' => 90, 'notes' => 'Dates within one month', 'days_difference' => $daysDiff];
        } elseif ($daysDiff <= 365) {
            return ['agreement' => 70, 'notes' => 'Dates within one year', 'days_difference' => $daysDiff];
        } elseif ($daysDiff <= 1825) { // 5 years
            return ['agreement' => 40, 'notes' => 'Dates differ by more than a year', 'days_difference' => $daysDiff];
        } else {
            return ['agreement' => 10, 'notes' => 'Significant date discrepancy', 'days_difference' => $daysDiff];
        }
    }

    /**
     * Analyze place agreement between two citations
     */
    private function analyzePlaceAgreement(string $eventType, ?object $citation1, ?object $citation2): array
    {
        if (!$citation1 || !$citation2) {
            return ['agreement' => null, 'notes' => 'Insufficient citation data'];
        }

        $place1 = $citation1->place ?? null;
        $place2 = $citation2->place ?? null;

        if (!$place1 || !$place2) {
            return ['agreement' => null, 'notes' => 'One or both sources lack place information'];
        }

        $similarity = $this->calculatePlaceSimilarity($place1, $place2);
        $score = (int) round($similarity * 100);

        if ($score >= 90) {
            return ['agreement' => $score, 'notes' => 'Places match or are nearly identical'];
        } elseif ($score >= 70) {
            return ['agreement' => $score, 'notes' => 'Places are similar (likely same area)'];
        } elseif ($score >= 50) {
            return ['agreement' => $score, 'notes' => 'Places have some overlap'];
        } else {
            return ['agreement' => $score, 'notes' => 'Places appear different'];
        }
    }

    /**
     * Analyze source independence
     */
    private function analyzeSourceIndependence(?object $citation1, ?object $citation2): array
    {
        if (!$citation1 || !$citation2) {
            return ['score' => 0, 'notes' => 'Insufficient data'];
        }

        $score = 100;
        $notes = [];

        // Same author reduces independence
        if ($citation1->author && $citation2->author &&
            strcasecmp($citation1->author, $citation2->author) === 0) {
            $score -= 30;
            $notes[] = 'Same author';
        }

        // Same publication reduces independence
        if ($citation1->publication && $citation2->publication &&
            strcasecmp($citation1->publication, $citation2->publication) === 0) {
            $score -= 20;
            $notes[] = 'Same publication';
        }

        // Both from same source title type reduces independence slightly
        $title1 = strtolower($citation1->source_title ?? '');
        $title2 = strtolower($citation2->source_title ?? '');
        if ($this->isSameSourceType($title1, $title2)) {
            $score -= 10;
            $notes[] = 'Similar source types';
        }

        return [
            'score' => max(0, $score),
            'is_independent' => $score >= 70,
            'notes' => empty($notes) ? 'Sources appear independent' : implode('; ', $notes),
        ];
    }

    /**
     * Calculate overall correlation score
     */
    private function calculateCorrelationScore(array $dateAnalysis, array $placeAnalysis, array $independenceAnalysis): int
    {
        $dateScore = $dateAnalysis['agreement'] ?? 50;
        $placeScore = $placeAnalysis['agreement'] ?? 50;
        $independenceScore = $independenceAnalysis['score'] ?? 50;

        // Weight: date (40%), place (30%), independence (30%)
        return (int) round(
            ($dateScore * 0.4) +
            ($placeScore * 0.3) +
            ($independenceScore * 0.3)
        );
    }

    /**
     * Determine correlation status from analysis
     */
    private function determineStatus(array $dateAnalysis, array $placeAnalysis, int $score): string
    {
        $dateAgreement = $dateAnalysis['agreement'] ?? null;
        $placeAgreement = $placeAnalysis['agreement'] ?? null;

        // Check for conflicts
        if ($dateAgreement !== null && $dateAgreement < 30) {
            return 'conflicts';
        }
        if ($placeAgreement !== null && $placeAgreement < 30) {
            return 'conflicts';
        }

        // High agreement = corroborates
        if ($score >= 70) {
            return 'corroborates';
        }

        // Moderate scores with some null data = supplements
        if ($dateAgreement === null || $placeAgreement === null) {
            return 'supplements';
        }

        // Default for analyzed items
        return $score >= 50 ? 'corroborates' : 'conflicts';
    }

    /**
     * Generate recommendation based on analysis
     */
    private function generateRecommendation(string $status, int $score, array $dateAnalysis, array $placeAnalysis): string
    {
        return match ($status) {
            'corroborates' => 'Sources corroborate each other. Evidence strength is good.',
            'conflicts' => 'Sources conflict. Review primary/secondary source quality and resolve discrepancy before using as evidence.',
            'supplements' => 'Sources provide complementary information. Consider them together for a complete picture.',
            default => 'Further analysis recommended.',
        };
    }

    /**
     * Update correlation with analysis results
     */
    private function updateCorrelationAnalysis(int $correlationId, array $data): void
    {
        $sql = "UPDATE evidence_correlations SET
                status = ?,
                correlation_score = ?,
                date_agreement = ?,
                place_agreement = ?,
                source_independence_score = ?,
                analysis_details = ?,
                assessed_at = NOW(),
                updated_at = NOW()
                WHERE id = ?";

        DB::update($sql, [
            $data['status'],
            $data['correlation_score'],
            $data['date_agreement'],
            $data['place_agreement'],
            $data['source_independence'],
            $data['analysis_details'],
            $correlationId,
        ]);
    }

    /**
     * Calculate place similarity (0-1)
     */
    private function calculatePlaceSimilarity(string $place1, string $place2): float
    {
        // Normalize places
        $p1 = strtolower(trim($place1));
        $p2 = strtolower(trim($place2));

        if ($p1 === $p2) {
            return 1.0;
        }

        // Check if one contains the other
        if (str_contains($p1, $p2) || str_contains($p2, $p1)) {
            return 0.85;
        }

        // Use Levenshtein distance for similarity
        $maxLen = max(strlen($p1), strlen($p2));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($p1, $p2);
        return max(0, 1 - ($distance / $maxLen));
    }

    /**
     * Check if two source titles are likely same type
     */
    private function isSameSourceType(string $title1, string $title2): bool
    {
        $types = ['census', 'birth', 'death', 'marriage', 'certificate', 'register', 'index'];

        foreach ($types as $type) {
            if (str_contains($title1, $type) && str_contains($title2, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate overall evidence strength from stats
     */
    private function calculateOverallEvidenceStrength(object $stats): string
    {
        $total = $stats->total_correlations;
        if ($total === 0) {
            return 'none';
        }

        $corroborating = $stats->corroborating + $stats->resolved;
        $ratio = $corroborating / $total;

        return match (true) {
            $ratio >= 0.8 => 'strong',
            $ratio >= 0.6 => 'moderate',
            $ratio >= 0.4 => 'weak',
            default => 'poor',
        };
    }
}

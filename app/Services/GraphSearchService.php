<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Graph Search Service
 *
 * Provides three graph-augmented search modes for the RAG pipeline:
 * - Local: Entity-centric BFS from query entities, returns source documents
 * - Global: Community report cosine search for broad/thematic queries
 * - DRIFT: Hybrid global→local with follow-up expansion
 *
 * Part of the GraphRAG integration (Phase 2).
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class GraphSearchService
{
    use RecursionAware;

    private ?AIService $aiService = null;
    private ?KnowledgeGraphService $kgService = null;
    private ?CommunityReportService $reportService = null;
    private ?CausalEdgeClassifier $causalClassifier = null;

    private const CONNECTION = 'pgsql_rag';

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    private function getCausalClassifier(): CausalEdgeClassifier
    {
        if ($this->causalClassifier === null) {
            $this->causalClassifier = new CausalEdgeClassifier();
        }
        return $this->causalClassifier;
    }

    private function getKGService(): KnowledgeGraphService
    {
        if ($this->kgService === null) {
            $this->kgService = app(KnowledgeGraphService::class);
        }
        return $this->kgService;
    }

    private function getReportService(): CommunityReportService
    {
        if ($this->reportService === null) {
            $this->reportService = app(CommunityReportService::class);
        }
        return $this->reportService;
    }

    /**
     * Local search: entity-centric BFS from query entities → source documents.
     *
     * 1. Extract entities from query text
     * 2. Match to KG entities (fuzzy ILIKE + alias)
     * 3. BFS traverse 1-2 hops collecting source_document_ids
     * 4. Score documents by graph centrality + hop distance
     *
     * @param string $query Search query text
     * @param int $limit Max results
     * @param array $options {min_confidence, max_hops, entity_boost}
     * @return array [{document: stdClass, similarity: float, graph_source: 'local', graph_entities: [...]}]
     */
    public function localSearch(string $query, int $limit = 10, array $options = []): array
    {
        // RLM: Try recursive graph search
        $rlm = $this->tryRecursive('graph_search', 'quality_gate_retry', ['query' => $query, 'options' => array_merge($options, ['limit' => $limit])], function ($ctx) {
            return $this->localSearch($ctx['query'], $ctx['options']['limit'] ?? 10, $ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $minConfidence = $options['min_confidence'] ?? 0.5;
        $maxHops = $options['max_hops'] ?? 2;
        $entityBoost = $options['entity_boost'] ?? 0.1;

        try {
            // Step 1: Extract entities from query
            $queryEntities = $this->extractQueryEntities($query);

            if (empty($queryEntities)) {
                return [];
            }

            // Step 2: Match to KG entities
            $matchedEntityIds = $this->matchQueryEntitiesToKG($queryEntities);

            if (empty($matchedEntityIds)) {
                return [];
            }

            // Step 2.5: Expand matched entities via KG relationships (Phase 3D)
            $originalMatchCount = count($matchedEntityIds);
            $expandEntities = $options['expand_entities'] ?? true;
            if ($expandEntities && !empty($matchedEntityIds)) {
                $matchedEntityIds = $this->expandMatchedEntities($matchedEntityIds, [
                    'max_neighbors' => $options['expansion_max_neighbors'] ?? 5,
                    'min_confidence' => $options['expansion_min_confidence'] ?? 0.7,
                ]);
            }

            // Step 3: BFS traverse to collect source documents with hop distance
            $docScores = $this->bfsCollectDocuments($matchedEntityIds, $maxHops, $minConfidence);

            if (empty($docScores)) {
                return [];
            }

            // Step 4: Fetch documents and score
            $docIds = array_keys($docScores);
            $placeholders = implode(',', array_fill(0, count($docIds), '?'));

            $documents = DB::connection(self::CONNECTION)->select(
                "SELECT * FROM rag_documents WHERE id IN ({$placeholders}) ORDER BY id",
                $docIds
            );

            $results = [];
            foreach ($documents as $doc) {
                $scoreInfo = $docScores[$doc->id];
                // Score: inverse hop distance + entity centrality bonus
                $hopScore = 1.0 / (1 + $scoreInfo['min_hop']);
                $centralityScore = min($scoreInfo['max_pagerank'] * 2, 0.3);
                $baseScore = ($hopScore * 0.7) + ($centralityScore * 0.3);
                // GR-13: Causal edge weight — causal edges score 2x, hierarchical 1.5x
                $edgeWeight = $scoreInfo['max_edge_weight'] ?? 1.0;
                $graphScore = min(1.0, $baseScore * $edgeWeight);

                $results[] = [
                    'document' => $doc,
                    'similarity' => round($graphScore, 4),
                    'graph_source' => 'local',
                    'graph_entities' => $scoreInfo['entities'],
                    'graph_hop' => $scoreInfo['min_hop'],
                ];
            }

            // Sort by score descending
            usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

            Log::info('GraphSearchService: localSearch', [
                'query_entities' => count($queryEntities),
                'matched_entities' => $originalMatchCount,
                'expanded_entities' => count($matchedEntityIds) - $originalMatchCount,
                'documents_found' => count($docScores),
            ]);

            return array_slice($results, 0, $limit);
        } catch (Exception $e) {
            Log::warning('GraphSearchService: localSearch failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);
            return [];
        }
    }

    /**
     * Global search: community report cosine similarity for broad queries.
     *
     * Delegates to CommunityReportService.searchReports() and maps
     * results to the standard {document, similarity} format expected
     * by the fusion layer.
     *
     * @param string $query Search query text
     * @param int $limit Max results
     * @return array [{document: stdClass|null, similarity: float, graph_source: 'global', report: {...}}]
     */
    public function globalSearch(string $query, int $limit = 5): array
    {
        try {
            $reportResults = $this->getReportService()->searchReports($query, $limit);

            if (empty($reportResults)) {
                return [];
            }

            $results = [];
            foreach ($reportResults as $rr) {
                $report = $rr['report'];

                // Try to find source documents via community entity source_document_ids
                $communityDoc = $this->getCommunityTopDocument($report['community_id']);

                $results[] = [
                    'document' => $communityDoc,
                    'similarity' => $rr['similarity'],
                    'graph_source' => 'global',
                    'report' => [
                        'title' => $report['title'],
                        'summary' => $report['summary'],
                        'themes' => $report['themes'] ?? [],
                        'rating' => $report['rating'],
                        'entity_count' => $rr['entity_count'] ?? 0,
                    ],
                ];
            }

            return $results;
        } catch (Exception $e) {
            Log::warning('GraphSearchService: globalSearch failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);
            return [];
        }
    }

    /**
     * DRIFT search: global→local hybrid with follow-up expansion.
     *
     * 1. Global search for community reports
     * 2. Extract key entities from top report
     * 3. Local BFS from those entities
     * 4. Merge results (RRF-style)
     *
     * @param string $query Search query text
     * @param int $limit Max results
     * @return array [{document, similarity, graph_source: 'drift', ...}]
     */
    public function driftSearch(string $query, int $limit = 10): array
    {
        try {
            // Phase 1: Global search for community context
            $globalResults = $this->globalSearch($query, 3);

            // Phase 2: Extract entities from top community report
            $followUpEntities = [];
            foreach ($globalResults as $gr) {
                if (!empty($gr['report']['title'])) {
                    // Use report title + themes as expansion terms
                    $expansion = $gr['report']['title'];
                    if (!empty($gr['report']['themes'])) {
                        $expansion .= ' ' . implode(' ', $gr['report']['themes']);
                    }
                    $entities = $this->extractQueryEntities($expansion);
                    $followUpEntities = array_merge($followUpEntities, $entities);
                }
            }

            // Phase 3: Local search from expanded entities
            $localResults = [];
            if (!empty($followUpEntities)) {
                $matchedIds = $this->matchQueryEntitiesToKG(array_unique($followUpEntities));
                if (!empty($matchedIds)) {
                    $docScores = $this->bfsCollectDocuments($matchedIds, 1, 0.5);
                    $docIds = array_keys($docScores);
                    if (!empty($docIds)) {
                        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
                        $documents = DB::connection(self::CONNECTION)->select(
                            "SELECT * FROM rag_documents WHERE id IN ({$placeholders})",
                            $docIds
                        );
                        foreach ($documents as $doc) {
                            $info = $docScores[$doc->id];
                            $localResults[] = [
                                'document' => $doc,
                                'similarity' => round(1.0 / (1 + $info['min_hop']), 4),
                                'graph_source' => 'drift_local',
                                'graph_entities' => $info['entities'],
                            ];
                        }
                    }
                }
            }

            // Phase 4: RRF merge global + local
            $k = 60;
            $scores = [];
            $docMap = [];

            foreach ($globalResults as $rank => $result) {
                if ($result['document']) {
                    $id = $result['document']->id;
                    $scores[$id] = ($scores[$id] ?? 0) + (1 / ($k + $rank + 1));
                    $docMap[$id] = $result;
                    $docMap[$id]['graph_source'] = 'drift';
                }
            }

            foreach ($localResults as $rank => $result) {
                $id = $result['document']->id;
                $scores[$id] = ($scores[$id] ?? 0) + (1 / ($k + $rank + 1));
                if (!isset($docMap[$id])) {
                    $docMap[$id] = $result;
                    $docMap[$id]['graph_source'] = 'drift';
                }
            }

            arsort($scores);
            $results = [];
            foreach (array_slice(array_keys($scores), 0, $limit) as $docId) {
                $entry = $docMap[$docId];
                $entry['similarity'] = round($scores[$docId], 4);
                $results[] = $entry;
            }

            return $results;
        } catch (Exception $e) {
            Log::warning('GraphSearchService: driftSearch failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);
            return [];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Extract entity names from a query using lightweight NER.
     * Uses capitalized words and noun phrases as entity candidates.
     */
    public function extractQueryEntities(string $query): array
    {
        $entities = [];

        // Strategy 1: Capitalized multi-word phrases (e.g. "John Smith", "United States")
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $query, $matches)) {
            $entities = array_merge($entities, $matches[1]);
        }

        // Strategy 2: Quoted terms
        if (preg_match_all('/"([^"]+)"/', $query, $matches)) {
            $entities = array_merge($entities, $matches[1]);
        }

        // Strategy 3: If no capitalized phrases found, use significant words (>3 chars)
        if (empty($entities)) {
            $stopwords = ['what', 'when', 'where', 'which', 'who', 'whom', 'whose',
                'that', 'this', 'these', 'those', 'have', 'does', 'with', 'from',
                'about', 'between', 'through', 'during', 'before', 'after', 'above',
                'below', 'tell', 'show', 'find', 'search', 'know', 'like', 'also',
                'than', 'then', 'just', 'only', 'very', 'much', 'many', 'some', 'most'];
            $words = preg_split('/\s+/', $query);
            foreach ($words as $word) {
                $clean = preg_replace('/[^a-zA-Z0-9]/', '', $word);
                if (strlen($clean) > 3 && !in_array(strtolower($clean), $stopwords)) {
                    $entities[] = $clean;
                }
            }
        }

        return array_values(array_unique($entities));
    }

    /**
     * Match extracted entity strings to knowledge graph entity IDs.
     */
    private function matchQueryEntitiesToKG(array $entityNames): array
    {
        if (empty($entityNames)) {
            return [];
        }

        $matchedIds = [];

        foreach ($entityNames as $name) {
            $results = DB::connection(self::CONNECTION)->select("
                SELECT id, canonical_name, pagerank
                FROM knowledge_graph_entities
                WHERE LOWER(canonical_name) = LOWER(?)
                   OR aliases::text ILIKE ?
                ORDER BY pagerank DESC NULLS LAST
                LIMIT 3
            ", [$name, '%' . $name . '%']);

            foreach ($results as $r) {
                $matchedIds[$r->id] = [
                    'name' => $r->canonical_name,
                    'pagerank' => (float) ($r->pagerank ?? 0),
                ];
            }
        }

        return $matchedIds;
    }

    /**
     * Expand matched entity set by following high-confidence KG relationships (Phase 3D).
     * Discovers related entities (1 hop) and adds them to the search set.
     *
     * @param array $entityMap [entity_id => {name, pagerank}]
     * @param array $options {max_neighbors, min_confidence}
     * @return array Expanded entity map with 'source' => 'expanded' annotation
     */
    private function expandMatchedEntities(array $entityMap, array $options = []): array
    {
        $maxNeighbors = $options['max_neighbors'] ?? 5;
        $minConfidence = $options['min_confidence'] ?? 0.7;

        $allNeighborIds = [];

        // For each matched entity, find high-confidence neighbors
        foreach (array_keys($entityMap) as $entityId) {
            $neighbors = DB::connection(self::CONNECTION)->select("
                SELECT
                    CASE WHEN subject_entity_id = ? THEN object_entity_id ELSE subject_entity_id END AS neighbor_id,
                    confidence,
                    predicate
                FROM knowledge_graph
                WHERE (subject_entity_id = ? OR object_entity_id = ?)
                  AND confidence >= ?
                  AND subject_entity_id IS NOT NULL
                  AND object_entity_id IS NOT NULL
                  AND t_expired IS NULL
                ORDER BY confidence DESC
                LIMIT 20
            ", [$entityId, $entityId, $entityId, $minConfidence]);

            // Collect neighbor IDs with their confidence (for ranking)
            foreach ($neighbors as $n) {
                $nId = (int) $n->neighbor_id;
                if ($nId && !isset($entityMap[$nId])) {
                    if (!isset($allNeighborIds[$nId])) {
                        $allNeighborIds[$nId] = ['confidence' => 0, 'source_entity' => $entityId];
                    }
                    $allNeighborIds[$nId]['confidence'] = max($allNeighborIds[$nId]['confidence'], (float) $n->confidence);
                }
            }
        }

        if (empty($allNeighborIds)) {
            return $entityMap;
        }

        // Batch-fetch neighbor entity details
        $neighborIds = array_keys($allNeighborIds);
        $placeholders = implode(',', array_fill(0, count($neighborIds), '?'));

        $neighborEntities = DB::connection(self::CONNECTION)->select(
            "SELECT id, canonical_name, pagerank FROM knowledge_graph_entities WHERE id IN ({$placeholders})",
            $neighborIds
        );

        // Build scored list: confidence * pagerank
        $scored = [];
        foreach ($neighborEntities as $ne) {
            $nId = (int) $ne->id;
            $pagerank = (float) ($ne->pagerank ?? 0);
            $confidence = $allNeighborIds[$nId]['confidence'];
            $scored[] = [
                'id' => $nId,
                'name' => $ne->canonical_name,
                'pagerank' => $pagerank,
                'score' => $confidence * max($pagerank, 0.01), // floor pagerank to avoid zero
            ];
        }

        // Sort by score descending, take top N per original entity count
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $maxTotal = $maxNeighbors * count($entityMap);
        $scored = array_slice($scored, 0, $maxTotal);

        // Add expanded entities to the map
        foreach ($scored as $s) {
            if (!isset($entityMap[$s['id']])) {
                $entityMap[$s['id']] = [
                    'name' => $s['name'],
                    'pagerank' => $s['pagerank'],
                    'source' => 'expanded',
                ];
            }
        }

        return $entityMap;
    }

    /**
     * BFS from entity IDs, collecting source_document_ids with hop distances.
     *
     * @return array [doc_id => {min_hop, max_pagerank, entities: [...]}]
     */
    private function bfsCollectDocuments(array $entityMap, int $maxHops, float $minConfidence): array
    {
        $docScores = [];
        $visitedEntities = [];
        $queue = [];

        // Seed queue with matched entity IDs
        foreach ($entityMap as $entityId => $info) {
            $queue[] = ['entity_id' => $entityId, 'hop' => 0, 'pagerank' => $info['pagerank'], 'name' => $info['name']];
        }

        while (!empty($queue)) {
            $current = array_shift($queue);
            $entityId = $current['entity_id'];
            $hop = $current['hop'];

            if (isset($visitedEntities[$entityId])) {
                continue;
            }
            $visitedEntities[$entityId] = true;

            // Get triples where this entity is subject or object (by entity ID, active only)
            // GR-13: Include predicate for causal edge weighting
            $triples = DB::connection(self::CONNECTION)->select("
                SELECT source_document_id, subject_entity_id, object_entity_id, confidence, predicate
                FROM knowledge_graph
                WHERE (subject_entity_id = ? OR object_entity_id = ?)
                  AND confidence >= ?
                  AND t_expired IS NULL
                LIMIT " . config('rag.graph_triple_walk_limit', 100) . "
            ", [$entityId, $entityId, $minConfidence]);

            // Collect doc scores and neighbor IDs from triples
            $unvisitedNeighborIds = [];
            foreach ($triples as $triple) {
                // GR-13: Compute causal edge weight for this triple
                $edgeWeight = $this->getCausalClassifier()->getPredicateWeight($triple->predicate ?? '');

                // Collect source document
                if ($triple->source_document_id) {
                    $docId = (int) $triple->source_document_id;
                    if (!isset($docScores[$docId])) {
                        $docScores[$docId] = [
                            'min_hop'       => $hop,
                            'max_pagerank'  => $current['pagerank'],
                            'entities'      => [$current['name']],
                            'max_edge_weight' => $edgeWeight,
                        ];
                    } else {
                        $docScores[$docId]['min_hop'] = min($docScores[$docId]['min_hop'], $hop);
                        $docScores[$docId]['max_pagerank'] = max($docScores[$docId]['max_pagerank'], $current['pagerank']);
                        $docScores[$docId]['max_edge_weight'] = max($docScores[$docId]['max_edge_weight'] ?? 1.0, $edgeWeight);
                        if (!in_array($current['name'], $docScores[$docId]['entities'])) {
                            $docScores[$docId]['entities'][] = $current['name'];
                        }
                    }
                }

                // Collect unvisited neighbor IDs for batch fetch
                if ($hop < $maxHops) {
                    $neighborId = ($triple->subject_entity_id == $entityId)
                        ? $triple->object_entity_id
                        : $triple->subject_entity_id;

                    if ($neighborId && !isset($visitedEntities[$neighborId])) {
                        $unvisitedNeighborIds[$neighborId] = true;
                    }
                }
            }

            // Batch fetch all unvisited neighbors in one query
            if (!empty($unvisitedNeighborIds)) {
                $neighborIdList = array_keys($unvisitedNeighborIds);
                $placeholders = implode(',', array_fill(0, count($neighborIdList), '?'));
                $neighbors = DB::connection(self::CONNECTION)->select(
                    "SELECT id, canonical_name, pagerank FROM knowledge_graph_entities WHERE id IN ({$placeholders})",
                    $neighborIdList
                );
                foreach ($neighbors as $neighbor) {
                    $queue[] = [
                        'entity_id' => $neighbor->id,
                        'hop' => $hop + 1,
                        'pagerank' => (float) ($neighbor->pagerank ?? 0),
                        'name' => $neighbor->canonical_name,
                    ];
                }
            }
        }

        return $docScores;
    }

    /**
     * Get the highest-PageRank entity's source document from a community.
     */
    private function getCommunityTopDocument(int $communityId): ?object
    {
        $result = DB::connection(self::CONNECTION)->selectOne("
            SELECT kg.source_document_id
            FROM knowledge_graph_entity_communities ec
            JOIN knowledge_graph_entities e ON e.id = ec.entity_id
            JOIN knowledge_graph kg ON (kg.subject_entity_id = e.id OR kg.object_entity_id = e.id)
            WHERE ec.community_id = ?
              AND kg.source_document_id IS NOT NULL
            ORDER BY e.pagerank DESC NULLS LAST
            LIMIT 1
        ", [$communityId]);

        if (!$result || !$result->source_document_id) {
            return null;
        }

        return DB::connection(self::CONNECTION)->selectOne(
            "SELECT * FROM rag_documents WHERE id = ?",
            [$result->source_document_id]
        );
    }
}

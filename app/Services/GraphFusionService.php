<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Graph Fusion Service
 *
 * Fuses graph search results with vector search results using
 * Reciprocal Rank Fusion (RRF) with configurable weighting.
 *
 * Supports fusing any combination of:
 * - Vector search results (from RAGService.search())
 * - Local graph results (entity BFS)
 * - Global graph results (community report cosine)
 * - DRIFT results (hybrid global→local)
 *
 * Part of the GraphRAG integration (Phase 2).
 */
class GraphFusionService
{
    /** RRF constant — same k=60 used in RAGService.hybridSearch() */
    private const RRF_K = 60;

    /**
     * Fuse vector search results with graph search results.
     *
     * Uses Reciprocal Rank Fusion (RRF) with alpha weighting:
     *   final_score = alpha * vector_rrf + (1 - alpha) * graph_rrf
     *
     * @param array $vectorResults [{document: stdClass, similarity: float, ...}]
     * @param array $graphResults  [{document: stdClass|null, similarity: float, graph_source: string, ...}]
     * @param float $alpha Weight for vector results (0.0 = all graph, 1.0 = all vector)
     * @param int $limit Max results to return
     * @return array [{document: stdClass, similarity: float, graph_boost: bool, graph_source?: string, ...}]
     */
    public function fuse(array $vectorResults, array $graphResults, float $alpha = 0.5, int $limit = 10): array
    {
        $k = self::RRF_K;
        $vectorRRF = [];
        $graphRRF = [];
        $docMap = []; // id => full result entry

        // Calculate vector RRF scores
        foreach ($vectorResults as $rank => $result) {
            $docId = $result['document']->id;
            $vectorRRF[$docId] = ($vectorRRF[$docId] ?? 0) + (1 / ($k + $rank + 1));
            if (!isset($docMap[$docId])) {
                $docMap[$docId] = $result;
            }
        }

        // Calculate graph RRF scores
        foreach ($graphResults as $rank => $result) {
            if (!$result['document']) {
                continue; // Skip graph results without source documents
            }
            $docId = $result['document']->id;
            $graphRRF[$docId] = ($graphRRF[$docId] ?? 0) + (1 / ($k + $rank + 1));
            if (!isset($docMap[$docId])) {
                $docMap[$docId] = $result;
            }
        }

        // Fuse: alpha * vector + (1 - alpha) * graph
        $allDocIds = array_unique(array_merge(array_keys($vectorRRF), array_keys($graphRRF)));
        $fusedScores = [];

        foreach ($allDocIds as $docId) {
            $vScore = $vectorRRF[$docId] ?? 0;
            $gScore = $graphRRF[$docId] ?? 0;
            $fusedScores[$docId] = ($alpha * $vScore) + ((1 - $alpha) * $gScore);
        }

        arsort($fusedScores);

        // Build output in standard {document, similarity} format
        $results = [];
        foreach (array_slice(array_keys($fusedScores), 0, $limit, true) as $docId) {
            $entry = $docMap[$docId];
            $wasInGraph = isset($graphRRF[$docId]);
            $wasInVector = isset($vectorRRF[$docId]);

            $entry['similarity'] = round($fusedScores[$docId], 4);
            $entry['graph_boost'] = $wasInGraph;

            // Annotate source for tracing
            if ($wasInVector && $wasInGraph) {
                $entry['fusion_source'] = 'both';
            } elseif ($wasInGraph) {
                $entry['fusion_source'] = 'graph_only';
            } else {
                $entry['fusion_source'] = 'vector_only';
            }

            $results[] = $entry;
        }

        Log::info('GraphFusionService: fused results', [
            'vector_count' => count($vectorResults),
            'graph_count' => count($graphResults),
            'fused_count' => count($results),
            'alpha' => $alpha,
            'graph_only' => count(array_filter($results, fn($r) => ($r['fusion_source'] ?? '') === 'graph_only')),
            'both' => count(array_filter($results, fn($r) => ($r['fusion_source'] ?? '') === 'both')),
        ]);

        return $results;
    }

    /**
     * Fuse multiple result sets using multi-source RRF.
     *
     * @param array $resultSets [{results: array, weight: float, label: string}]
     * @param int $limit Max results
     * @return array [{document, similarity, fusion_sources: [...]}]
     */
    public function fuseMultiple(array $resultSets, int $limit = 10): array
    {
        $k = self::RRF_K;
        $docMap = [];
        $sourceScores = []; // docId => [label => score]

        foreach ($resultSets as $set) {
            $weight = $set['weight'] ?? 1.0;
            $label = $set['label'] ?? 'unknown';

            foreach (($set['results'] ?? []) as $rank => $result) {
                if (!$result['document']) {
                    continue;
                }
                $docId = $result['document']->id;
                $rrfScore = $weight * (1 / ($k + $rank + 1));

                if (!isset($sourceScores[$docId])) {
                    $sourceScores[$docId] = [];
                }
                $sourceScores[$docId][$label] = ($sourceScores[$docId][$label] ?? 0) + $rrfScore;

                if (!isset($docMap[$docId])) {
                    $docMap[$docId] = $result;
                }
            }
        }

        // Sum across sources
        $fusedScores = [];
        foreach ($sourceScores as $docId => $labelScores) {
            $fusedScores[$docId] = array_sum($labelScores);
        }

        arsort($fusedScores);

        $results = [];
        foreach (array_slice(array_keys($fusedScores), 0, $limit, true) as $docId) {
            $entry = $docMap[$docId];
            $entry['similarity'] = round($fusedScores[$docId], 4);
            $entry['fusion_sources'] = array_keys($sourceScores[$docId]);
            $entry['graph_boost'] = count($sourceScores[$docId]) > 1;
            $results[] = $entry;
        }

        return $results;
    }
}

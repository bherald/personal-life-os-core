<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\RecursionAware;

/**
 * FC-6: Multi-Hop Verification Chains
 *
 * Extends KG-based fact verification (GR-8) to handle claims requiring
 * multi-step reasoning across the knowledge graph. Uses BFS path finding
 * between entity pairs and chains evidence across 2-3 hops.
 *
 * Example: "Person A's grandfather fought in the Civil War"
 *   Hop 1: A → father_of → B
 *   Hop 2: B → father_of → C
 *   Hop 3: C → served_in → Civil War
 *
 * Pipeline:
 *   1. Extract entities from claim (reuse KGFactVerificationService)
 *   2. Find multi-hop paths between entity pairs via BFS
 *   3. LLM evaluates each path chain for claim support
 *   4. Aggregate path verdicts into final confidence
 *
 * Reference: PGR (Path-based Graph Reasoning, EMNLP 2025)
 */
class MultiHopVerificationService
{
    use RecursionAware;

    public const MAX_HOPS = 3;
    public const MAX_PATHS_PER_PAIR = 5;
    public const MIN_PATH_CONFIDENCE = 0.40;
    public const MAX_ENTITY_PAIRS = 10;

    private const CONNECTION = 'pgsql_rag';

    private AIService $ai;
    private ?KGFactVerificationService $kgVerifier = null;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    private function getKGVerifier(): KGFactVerificationService
    {
        if ($this->kgVerifier === null) {
            $this->kgVerifier = app(KGFactVerificationService::class);
        }
        return $this->kgVerifier;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Verify a claim using multi-hop KG path reasoning.
     *
     * @param string $claim Factual claim to verify
     * @param array $options Options: max_hops (default 3), min_confidence (default 0.40)
     * @return array Verification result with paths, chain reasoning, and verdict
     */
    public function verify(string $claim, array $options = []): array
    {
        // RLM: Try recursive multi-hop verification
        $rlm = $this->tryRecursive('multi_hop_verification', 'evidence_chase', ['claim' => $claim, 'options' => $options], function ($ctx) {
            return $this->verify($ctx['claim'] ?? $ctx['data'], $ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $maxHops = $options['max_hops'] ?? self::MAX_HOPS;
        $minConfidence = $options['min_confidence'] ?? self::MIN_PATH_CONFIDENCE;

        $empty = [
            'verdict' => 'insufficient_evidence',
            'confidence' => 0.0,
            'chains' => [],
            'entity_pairs' => 0,
            'paths_found' => 0,
            'max_hops_used' => 0,
            'reasoning' => 'No multi-hop evidence found.',
        ];

        if (empty(trim($claim))) {
            return $empty;
        }

        try {
            // Step 1: Extract and resolve entities
            $entityNames = $this->getKGVerifier()->extractClaimEntities($claim);
            if (count($entityNames) < 2) {
                return array_merge($empty, [
                    'reasoning' => 'Multi-hop verification requires at least 2 entities. Found: ' . count($entityNames),
                ]);
            }

            $resolved = $this->getKGVerifier()->resolveEntities($entityNames);
            if (count($resolved) < 2) {
                return array_merge($empty, [
                    'reasoning' => 'Could not resolve enough entities in the knowledge graph.',
                ]);
            }

            // Step 2: Find multi-hop paths between all entity pairs
            $pairs = $this->generateEntityPairs($resolved);
            $allChains = [];
            $maxHopsUsed = 0;

            foreach ($pairs as $pair) {
                $paths = $this->findMultiHopPaths(
                    $pair['source_id'],
                    $pair['target_id'],
                    $maxHops,
                    $minConfidence
                );

                if (!empty($paths)) {
                    foreach ($paths as $path) {
                        $hops = count($path) - 1;
                        $maxHopsUsed = max($maxHopsUsed, $hops);

                        $allChains[] = [
                            'source' => $pair['source_name'],
                            'target' => $pair['target_name'],
                            'hops' => $hops,
                            'path' => $path,
                            'path_summary' => $this->summarizePath($path),
                            'min_confidence' => $this->getPathMinConfidence($path),
                        ];
                    }
                }
            }

            if (empty($allChains)) {
                return array_merge($empty, [
                    'entity_pairs' => count($pairs),
                    'reasoning' => 'No connecting paths found between entities within ' . $maxHops . ' hops.',
                ]);
            }

            // Step 3: LLM evaluates chain evidence against claim
            $chainVerdict = $this->evaluateChains($claim, $allChains);

            return [
                'verdict' => $chainVerdict['verdict'],
                'confidence' => $chainVerdict['confidence'],
                'chains' => $allChains,
                'entity_pairs' => count($pairs),
                'paths_found' => count($allChains),
                'max_hops_used' => $maxHopsUsed,
                'reasoning' => $chainVerdict['reasoning'],
            ];

        } catch (\Exception $e) {
            Log::error('MultiHopVerification: Verification failed', [
                'claim' => substr($claim, 0, 100),
                'error' => $e->getMessage(),
            ]);
            return array_merge($empty, ['reasoning' => 'Error: ' . $e->getMessage()]);
        }
    }

    // =========================================================================
    // Entity pair generation (pure — unit-testable)
    // =========================================================================

    /**
     * Generate unique entity pairs from resolved entities.
     * Returns pairs ordered by distance in the original entity list.
     */
    public function generateEntityPairs(array $resolved): array
    {
        $pairs = [];
        $count = min(count($resolved), 6); // Cap to avoid combinatorial explosion

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pairs[] = [
                    'source_id' => $resolved[$i]['id'],
                    'source_name' => $resolved[$i]['canonical_name'],
                    'target_id' => $resolved[$j]['id'],
                    'target_name' => $resolved[$j]['canonical_name'],
                ];

                if (count($pairs) >= self::MAX_ENTITY_PAIRS) {
                    return $pairs;
                }
            }
        }

        return $pairs;
    }

    // =========================================================================
    // Multi-hop BFS path finding
    // =========================================================================

    /**
     * Find all paths between two entities within maxHops via BFS.
     *
     * @param int $sourceId Source entity ID
     * @param int $targetId Target entity ID
     * @param int $maxHops Maximum path length
     * @param float $minConfidence Minimum triple confidence
     * @return array Array of paths, each path is an array of nodes with edge info
     */
    public function findMultiHopPaths(int $sourceId, int $targetId, int $maxHops, float $minConfidence): array
    {
        if ($sourceId === $targetId) {
            return [];
        }

        try {
            // BFS with path tracking
            $queue = [['entity_id' => $sourceId, 'path' => [$this->getEntityInfo($sourceId)]]];
            $visited = [$sourceId => true];
            $foundPaths = [];

            for ($hop = 0; $hop < $maxHops && !empty($queue); $hop++) {
                $nextQueue = [];

                foreach ($queue as $state) {
                    $currentId = $state['entity_id'];
                    $currentPath = $state['path'];

                    // Get neighbors
                    $neighbors = $this->getNeighbors($currentId, $minConfidence);

                    foreach ($neighbors as $neighbor) {
                        $neighborId = (int) $neighbor->neighbor_id;

                        // Found target
                        if ($neighborId === $targetId) {
                            $pathWithEdge = $currentPath;
                            $pathWithEdge[] = [
                                'entity_id' => $neighborId,
                                'entity_name' => $neighbor->neighbor_name,
                                'edge_predicate' => $neighbor->predicate,
                                'edge_confidence' => (float) $neighbor->confidence,
                                'edge_direction' => $neighbor->direction,
                            ];
                            $foundPaths[] = $pathWithEdge;

                            if (count($foundPaths) >= self::MAX_PATHS_PER_PAIR) {
                                return $foundPaths;
                            }
                            continue;
                        }

                        // Continue BFS (don't revisit)
                        if (!isset($visited[$neighborId]) && $hop < $maxHops - 1) {
                            $visited[$neighborId] = true;
                            $newPath = $currentPath;
                            $newPath[] = [
                                'entity_id' => $neighborId,
                                'entity_name' => $neighbor->neighbor_name,
                                'edge_predicate' => $neighbor->predicate,
                                'edge_confidence' => (float) $neighbor->confidence,
                                'edge_direction' => $neighbor->direction,
                            ];
                            $nextQueue[] = ['entity_id' => $neighborId, 'path' => $newPath];
                        }
                    }
                }

                $queue = $nextQueue;
            }

            return $foundPaths;

        } catch (\Exception $e) {
            Log::warning('MultiHopVerification: Path finding failed', [
                'source' => $sourceId,
                'target' => $targetId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // Chain evaluation (LLM)
    // =========================================================================

    /**
     * Use LLM to evaluate whether the chain evidence supports the claim.
     */
    public function evaluateChains(string $claim, array $chains): array
    {
        $neutral = ['verdict' => 'insufficient_evidence', 'confidence' => 0.0, 'reasoning' => 'Evaluation failed.'];

        if (empty($chains)) {
            return $neutral;
        }

        // Build chain summaries for the prompt
        $chainDescriptions = [];
        foreach (array_slice($chains, 0, 5) as $i => $chain) {
            $chainDescriptions[] = "Chain " . ($i + 1) . " ({$chain['hops']} hops, "
                . "min confidence {$chain['min_confidence']}):\n  {$chain['path_summary']}";
        }
        $chainText = implode("\n\n", $chainDescriptions);

        $prompt = "You are verifying a factual claim using multi-hop knowledge graph reasoning chains.\n\n"
            . "CLAIM: \"{$claim}\"\n\n"
            . "REASONING CHAINS (paths through the knowledge graph):\n{$chainText}\n\n"
            . "Evaluate whether the chains, when combined, support or contradict the claim.\n"
            . "Consider: Do the chain steps logically connect to verify the claim?\n"
            . "Are there any broken links or contradictions in the reasoning?\n\n"
            . "Output ONLY valid JSON (no markdown):\n"
            . "{\"verdict\": \"supported|contradicted|insufficient_evidence\", \"confidence\": 0.0-1.0, \"reasoning\": \"brief explanation of chain logic\"}";

        $result = $this->ai->process($prompt, [
            'max_tokens' => 200,
            'temperature' => 0.0,
            'expect_json' => true,
            'task_type' => 'multihop_verification',
            'model_role' => 'fast',
            'suppress_alert' => true,
        ]);

        if (!($result['success'] ?? false)) {
            return $neutral;
        }

        $raw = trim($result['response'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return $neutral;
        }

        $verdict = $parsed['verdict'] ?? 'insufficient_evidence';
        if (!in_array($verdict, ['supported', 'contradicted', 'insufficient_evidence'], true)) {
            $verdict = 'insufficient_evidence';
        }

        return [
            'verdict' => $verdict,
            'confidence' => min(1.0, max(0.0, (float) ($parsed['confidence'] ?? 0.0))),
            'reasoning' => (string) ($parsed['reasoning'] ?? ''),
        ];
    }

    // =========================================================================
    // Path helpers (pure — unit-testable)
    // =========================================================================

    /**
     * Summarize a path as a human-readable chain string.
     */
    public function summarizePath(array $path): string
    {
        $parts = [];
        foreach ($path as $i => $node) {
            $name = $node['entity_name'] ?? 'unknown';
            if ($i === 0) {
                $parts[] = $name;
            } else {
                $predicate = $node['edge_predicate'] ?? '?';
                $direction = $node['edge_direction'] ?? 'outgoing';
                $arrow = $direction === 'incoming' ? ' ←[' . $predicate . ']← ' : ' →[' . $predicate . ']→ ';
                $parts[] = $arrow . $name;
            }
        }
        return implode('', $parts);
    }

    /**
     * Get the minimum confidence across all edges in a path.
     */
    public function getPathMinConfidence(array $path): float
    {
        $min = 1.0;
        foreach ($path as $node) {
            if (isset($node['edge_confidence'])) {
                $min = min($min, (float) $node['edge_confidence']);
            }
        }
        return round($min, 3);
    }

    // =========================================================================
    // DB helpers
    // =========================================================================

    /**
     * Get entity info by ID.
     */
    private function getEntityInfo(int $entityId): array
    {
        try {
            $entity = DB::connection(self::CONNECTION)->selectOne("
                SELECT id, canonical_name, entity_type
                FROM knowledge_graph_entities
                WHERE id = ?
            ", [$entityId]);

            return [
                'entity_id' => $entityId,
                'entity_name' => $entity->canonical_name ?? 'unknown',
                'entity_type' => $entity->entity_type ?? null,
            ];
        } catch (\Exception $e) {
            return ['entity_id' => $entityId, 'entity_name' => 'unknown'];
        }
    }

    /**
     * Get neighboring entities via KG triples.
     */
    private function getNeighbors(int $entityId, float $minConfidence): array
    {
        try {
            return DB::connection(self::CONNECTION)->select("
                SELECT
                    CASE WHEN subject_entity_id = ? THEN object_entity_id ELSE subject_entity_id END as neighbor_id,
                    CASE WHEN subject_entity_id = ?
                        THEN (SELECT canonical_name FROM knowledge_graph_entities WHERE id = object_entity_id)
                        ELSE (SELECT canonical_name FROM knowledge_graph_entities WHERE id = subject_entity_id)
                    END as neighbor_name,
                    predicate,
                    confidence,
                    CASE WHEN subject_entity_id = ? THEN 'outgoing' ELSE 'incoming' END as direction
                FROM knowledge_graph
                WHERE (subject_entity_id = ? OR object_entity_id = ?)
                  AND confidence >= ?
                  AND t_expired IS NULL
                LIMIT 50
            ", [$entityId, $entityId, $entityId, $entityId, $entityId, $minConfidence]);
        } catch (\Exception $e) {
            return [];
        }
    }
}

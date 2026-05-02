<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GR-8: KG-Integrated Fact Verification — PGR (EMNLP 2025)
 *
 * Verifies factual claims against the local Knowledge Graph rather than (or
 * in addition to) retrieved documents or web search. Finds multi-hop paths
 * between entities in the claim and uses them as structured evidence.
 *
 * Pipeline:
 *   1. Extract named entities from the claim (regex heuristic, no LLM)
 *   2. Look up those entities in knowledge_graph_entities
 *   3. For each entity pair, fetch connecting triples from knowledge_graph
 *   4. Use a fast LLM to classify whether the KG paths support or contradict
 *      the claim
 *   5. Return a structured verdict with the evidence paths
 *
 * Why KG over documents: structured triples are unambiguous — "X born_in Y"
 * cannot be misread. Useful for claims about genealogy, dates, and locations.
 *
 * Reference: PGR (Path-based Graph Reasoning for Fact Verification, EMNLP 2025)
 */
class KGFactVerificationService
{
    use RecursionAware;

    /** Min confidence of KG triples to include as evidence */
    public const MIN_TRIPLE_CONFIDENCE = 0.50;

    /** Max triples to retrieve per entity */
    public const MAX_TRIPLES_PER_ENTITY = 20;

    /** Max triples passed to the LLM classifier */
    public const MAX_TRIPLES_FOR_CLASSIFICATION = 15;

    /** Confidence threshold for a SUPPORTED verdict */
    public const SUPPORTED_THRESHOLD = 0.65;

    /** Confidence threshold for a CONTRADICTED verdict */
    public const CONTRADICTED_THRESHOLD = 0.65;

    private const CONNECTION = 'pgsql_rag';

    private AIService $ai;
    private ?KnowledgeGraphService $kgService = null;
    private ?GraphSearchService $graphSearchService = null;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    private function getKGService(): KnowledgeGraphService
    {
        if ($this->kgService === null) {
            $this->kgService = app(KnowledgeGraphService::class);
        }
        return $this->kgService;
    }

    private function getGraphSearchService(): GraphSearchService
    {
        if ($this->graphSearchService === null) {
            $this->graphSearchService = app(GraphSearchService::class);
        }
        return $this->graphSearchService;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Verify a claim against the knowledge graph.
     *
     * @param  string $claim  An atomic factual claim (e.g. "John Smith was born in Ohio in 1855")
     * @return array{
     *   verdict: string,
     *   confidence: float,
     *   label: string,
     *   paths: array,
     *   kg_entities: string[],
     *   path_count: int,
     *   evidence_summary: string
     * }
     */
    public function verify(string $claim): array
    {
        // RLM: Try recursive KG fact verification
        $rlm = $this->tryRecursive('kg_fact_verification', 'evidence_chase', ['claim' => $claim], function ($ctx) {
            return $this->verify($ctx['claim']);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $empty = [
            'verdict'          => 'insufficient_evidence',
            'confidence'       => 0.0,
            'label'            => 'neutral',
            'paths'            => [],
            'kg_entities'      => [],
            'path_count'       => 0,
            'evidence_summary' => 'No KG evidence found for this claim.',
        ];

        if (empty(trim($claim))) {
            return $empty;
        }

        // Step 1: entity extraction (pure regex — fast, no I/O)
        $entityNames = $this->extractClaimEntities($claim);
        if (empty($entityNames)) {
            return array_merge($empty, ['evidence_summary' => 'No named entities found in claim.']);
        }

        // Step 2: resolve entities against KG
        $resolvedEntities = $this->resolveEntities($entityNames);
        if (empty($resolvedEntities)) {
            return array_merge($empty, [
                'evidence_summary' => 'Claim entities not found in knowledge graph.',
            ]);
        }

        // Step 3: fetch KG triples for resolved entities
        $paths = $this->fetchPaths($resolvedEntities);
        if (empty($paths)) {
            return array_merge($empty, [
                'kg_entities'      => array_column($resolvedEntities, 'canonical_name'),
                'evidence_summary' => 'Entities found in KG but no relevant triples.',
            ]);
        }

        // Step 4: LLM classification of paths against claim
        $classification = $this->classifyPaths($claim, $paths);

        $evidenceSummary = $this->buildEvidenceSummary($paths, $classification);

        Log::info('KGFactVerificationService: claim verified', [
            'claim'      => substr($claim, 0, 100),
            'entities'   => array_column($resolvedEntities, 'canonical_name'),
            'paths'      => count($paths),
            'label'      => $classification['label'],
            'confidence' => $classification['confidence'],
        ]);

        return [
            'verdict'          => $classification['label'] === 'supported'
                                    ? 'supported'
                                    : ($classification['label'] === 'contradicted'
                                        ? 'contradicted'
                                        : 'insufficient_evidence'),
            'confidence'       => $classification['confidence'],
            'label'            => $classification['label'],
            'paths'            => $paths,
            'kg_entities'      => array_column($resolvedEntities, 'canonical_name'),
            'path_count'       => count($paths),
            'evidence_summary' => $evidenceSummary,
        ];
    }

    // =========================================================================
    // Entity extraction (pure — unit-testable)
    // =========================================================================

    /**
     * Extract candidate named entities from a claim string.
     * Uses capitalized-phrase heuristic (same as GraphSearchService::extractQueryEntities)
     * plus quoted-term extraction. No LLM, no DB.
     *
     * @return string[]
     */
    public function extractClaimEntities(string $claim): array
    {
        $entities = [];

        // Capitalized multi-word phrases: "John Smith", "New York", "Ohio"
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $claim, $matches)) {
            $entities = array_merge($entities, $matches[1]);
        }

        // Quoted terms
        if (preg_match_all('/"([^"]+)"/', $claim, $matches)) {
            $entities = array_merge($entities, $matches[1]);
        }

        // Filter: skip very short tokens and common sentence-starting words
        $stopStarters = ['the', 'a', 'an', 'in', 'on', 'at', 'by', 'to', 'of', 'for',
                         'and', 'or', 'but', 'was', 'were', 'is', 'are', 'has', 'had'];
        $entities = array_filter($entities, function (string $e) use ($stopStarters): bool {
            $lower = mb_strtolower(trim($e));
            return mb_strlen($lower) >= 3 && !in_array($lower, $stopStarters, true);
        });

        return array_values(array_unique($entities));
    }

    // =========================================================================
    // KG resolution
    // =========================================================================

    /**
     * Fuzzy-match candidate entity names against knowledge_graph_entities.
     * Returns array of [canonical_name, entity_type, id] for matches found.
     */
    public function resolveEntities(array $entityNames): array
    {
        if (empty($entityNames)) {
            return [];
        }

        $resolved = [];
        $seen     = [];

        foreach ($entityNames as $name) {
            $matches = $this->getKGService()->searchEntities($name, ['limit' => 3]);
            foreach ($matches as $match) {
                $key = mb_strtolower($match['canonical_name']);
                if (!isset($seen[$key])) {
                    $seen[$key]  = true;
                    $resolved[] = $match;
                }
            }
        }

        return $resolved;
    }

    // =========================================================================
    // Path fetching
    // =========================================================================

    /**
     * Retrieve triples connecting the resolved entities.
     * Returns a flat list of triple arrays in {subject, predicate, object, confidence} format.
     */
    public function fetchPaths(array $resolvedEntities): array
    {
        $paths = [];
        $seen  = [];

        foreach ($resolvedEntities as $entity) {
            $triples = $this->getKGService()->findRelationships(
                $entity['canonical_name'],
                [
                    'min_confidence' => self::MIN_TRIPLE_CONFIDENCE,
                    'limit'          => self::MAX_TRIPLES_PER_ENTITY,
                ]
            );

            foreach ($triples as $triple) {
                $key = mb_strtolower("{$triple['subject']}|{$triple['predicate']}|{$triple['object']}");
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $paths[]    = $triple;
                }
            }
        }

        // Sort by confidence descending, limit for LLM context
        usort($paths, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));

        return array_slice($paths, 0, self::MAX_TRIPLES_FOR_CLASSIFICATION);
    }

    // =========================================================================
    // LLM path classification
    // =========================================================================

    /**
     * Ask a fast LLM whether the KG triples support or contradict the claim.
     *
     * @return array{label: string, confidence: float, reason: string}
     */
    public function classifyPaths(string $claim, array $paths): array
    {
        $neutral = ['label' => 'neutral', 'confidence' => 0.0, 'reason' => 'Classification failed.'];

        if (empty($paths)) {
            return $neutral;
        }

        $tripleSummary = implode("\n", array_map(
            fn($p) => "- {$p['subject']} {$p['predicate']} {$p['object']} (confidence: {$p['confidence']})",
            array_slice($paths, 0, self::MAX_TRIPLES_FOR_CLASSIFICATION)
        ));

        $prompt = "You are verifying a factual claim against structured knowledge graph triples.\n\n"
            . "CLAIM: \"{$claim}\"\n\n"
            . "KNOWLEDGE GRAPH EVIDENCE:\n{$tripleSummary}\n\n"
            . "Classification rules:\n"
            . "- supported: the triples directly confirm the claim's key facts\n"
            . "- contradicted: the triples directly deny or conflict with the claim\n"
            . "- neutral: the triples are unrelated or insufficient to confirm or deny\n\n"
            . "Output ONLY valid JSON (no markdown):\n"
            . "{\"label\": \"supported|contradicted|neutral\", \"confidence\": 0.0-1.0, \"reason\": \"brief explanation\"}";

        $result = $this->ai->process($prompt, [
            'max_tokens'     => 150,
            'temperature'    => 0.0,
            'expect_json'    => true,
            'task_type'      => 'kg_fact_verification',
            'model_role'     => 'fast',
            'suppress_alert' => true,
        ]);

        if (!($result['success'] ?? false)) {
            Log::warning('KGFactVerificationService: classification failed', [
                'error' => $result['error'] ?? 'unknown',
            ]);
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

        $label = $parsed['label'] ?? 'neutral';
        if (!in_array($label, ['supported', 'contradicted', 'neutral'], true)) {
            $label = 'neutral';
        }

        return [
            'label'      => $label,
            'confidence' => (float) ($parsed['confidence'] ?? 0.0),
            'reason'     => (string) ($parsed['reason'] ?? ''),
        ];
    }

    // =========================================================================
    // Evidence summary (pure — unit-testable)
    // =========================================================================

    /**
     * Build a human-readable summary of the KG evidence paths.
     */
    public function buildEvidenceSummary(array $paths, array $classification): string
    {
        if (empty($paths)) {
            return 'No KG paths found.';
        }

        $label  = $classification['label'] ?? 'neutral';
        $reason = $classification['reason'] ?? '';
        $count  = count($paths);

        $tripleLines = array_map(
            fn($p) => "  • {$p['subject']} → {$p['predicate']} → {$p['object']}",
            array_slice($paths, 0, 5)
        );

        $more   = $count > 5 ? " (and " . ($count - 5) . " more)" : '';
        $header = ucfirst($label) . " by {$count} KG triple(s){$more}";
        if ($reason) {
            $header .= ": {$reason}";
        }

        return $header . "\n" . implode("\n", $tripleLines);
    }
}

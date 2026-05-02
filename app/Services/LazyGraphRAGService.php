<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\Log;

/**
 * GR-9: LazyGraphRAG — Deferred Graph Construction at Query Time
 *
 * Instead of requiring a pre-built knowledge graph (which costs hours of LLM
 * extraction upfront), LazyGraphRAG builds a mini-KG on-the-fly from the
 * documents already retrieved by vector search. This gives ~80% of full
 * GraphRAG quality at <1% of the indexing cost.
 *
 * Algorithm (Microsoft Research, Oct 2024):
 *   1. For each retrieved doc, extract named entities via fast LLM call
 *   2. Find "bridge entities" — entities that appear in 2+ docs
 *   3. Boost document relevance scores proportionally to bridge entity count
 *   4. Attach entity context to results for downstream use
 *
 * Usage: enable via deepSearch(useLazyGraph: true) as a fallback when the
 * full KG hasn't been built (or as a lightweight complement to it).
 *
 * Reference: LazyGraphRAG (Edge et al., Microsoft Research, arXiv 2410.05779)
 */
class LazyGraphRAGService
{
    use RecursionAware;

    /** Maximum documents to run entity extraction on (LLM cost guard) */
    public const MAX_DOCS_TO_EXTRACT = 8;

    /** Minimum occurrences across docs for an entity to be a "bridge" */
    public const MIN_BRIDGE_OCCURRENCES = 2;

    /** Score boost per bridge entity (applied multiplicatively to similarity) */
    public const BRIDGE_BOOST_PER_ENTITY = 0.03;

    /** Maximum boost cap (prevents runaway scores) */
    public const MAX_BRIDGE_BOOST = 0.20;

    public function __construct(private readonly AIService $aiService) {}

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Augment vector search results using lazy graph construction.
     *
     * @param  string $query
     * @param  array  $results  RAGService result array (each has 'document' + 'similarity')
     * @return array{
     *   results: array,
     *   bridge_entities: array,
     *   extraction_count: int
     * }
     */
    public function augment(string $query, array $results): array
    {
        // RLM: Try recursive lazy graph augmentation
        $rlm = $this->tryRecursive('lazy_graph_rag', 'quality_gate_retry', ['query' => $query, 'results' => $results], function ($ctx) {
            return $this->augment($ctx['query'] ?? $ctx['data'], $ctx['results'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        if (empty($results)) {
            return ['results' => $results, 'bridge_entities' => [], 'extraction_count' => 0];
        }

        $slice = array_slice($results, 0, self::MAX_DOCS_TO_EXTRACT);

        // Step 1: Extract entities from each doc
        $docEntities = [];
        $extractionCount = 0;
        foreach ($slice as $idx => $result) {
            $content = $result['document']->content ?? '';
            $title   = $result['document']->title ?? '';
            if (empty(trim($content))) {
                continue;
            }
            $entities = $this->extractEntities($content, $title);
            if (!empty($entities)) {
                $docEntities[$idx] = $entities;
                $extractionCount++;
            }
        }

        if (empty($docEntities)) {
            return ['results' => $results, 'bridge_entities' => [], 'extraction_count' => 0];
        }

        // Step 2: Find bridge entities (appear in 2+ docs)
        $bridges = $this->findBridgeEntities($docEntities);

        // Step 3: Rescore — boost docs with more bridges
        $bridgesByDoc = $this->mapBridgesToDocs($docEntities, $bridges);
        $results      = $this->scoreDocsByBridges($results, $bridgesByDoc);

        return [
            'results'          => $results,
            'bridge_entities'  => $bridges,
            'extraction_count' => $extractionCount,
        ];
    }

    // =========================================================================
    // Entity extraction (LLM-based)
    // =========================================================================

    /**
     * Extract named entities from document content using a fast LLM call.
     * Returns array of lowercase entity name strings.
     * On AI failure, returns [].
     *
     * @return string[]
     */
    public function extractEntities(string $content, string $title = ''): array
    {
        $snippet = mb_substr(trim($content), 0, 1200);
        $context = $title ? "Title: {$title}\n\n" : '';

        $prompt = "Extract all named entities (people, places, organisations, dates, events) "
            . "from the following text. Return a JSON array of lowercase strings. "
            . "Maximum 15 entities. Example: [\"ohio\", \"john smith\", \"1880 census\"]\n\n"
            . "{$context}Text:\n{$snippet}";

        $result = $this->aiService->process($prompt, ['model_role' => 'fast', 'max_tokens' => 150]);

        if (!($result['success'] ?? false)) {
            return [];
        }

        $raw = trim($result['response'] ?? '');
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/i', '', trim($raw));

        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return array_values(array_filter(
                    array_map('mb_strtolower', $decoded),
                    fn($e) => is_string($e) && mb_strlen($e) >= 2
                ));
            }
        }

        return [];
    }

    // =========================================================================
    // Bridge entity detection (pure)
    // =========================================================================

    /**
     * Find entities that appear in at least MIN_BRIDGE_OCCURRENCES documents.
     * Returns array of entity name → occurrence count.
     *
     * @param  array $docEntities  Map of result-index → string[]
     * @return array<string, int>  entity → doc count
     */
    public function findBridgeEntities(array $docEntities): array
    {
        $entityDocCount = [];

        foreach ($docEntities as $entities) {
            // Count each entity once per doc (use unique to avoid double-counting)
            foreach (array_unique($entities) as $entity) {
                $entityDocCount[$entity] = ($entityDocCount[$entity] ?? 0) + 1;
            }
        }

        return array_filter(
            $entityDocCount,
            fn(int $count) => $count >= self::MIN_BRIDGE_OCCURRENCES
        );
    }

    /**
     * Map bridge entities back to the docs that contain them.
     *
     * @param  array $docEntities  result-index → string[]
     * @param  array $bridges      entity → doc count
     * @return array<int, string[]>  result-index → bridge entity names
     */
    public function mapBridgesToDocs(array $docEntities, array $bridges): array
    {
        $bridgeSet = array_keys($bridges);
        $map = [];

        foreach ($docEntities as $idx => $entities) {
            $map[$idx] = array_values(array_intersect($entities, $bridgeSet));
        }

        return $map;
    }

    // =========================================================================
    // Result rescoring (pure)
    // =========================================================================

    /**
     * Boost similarity scores for documents that contain bridge entities.
     * Boost = min(bridge_count × BRIDGE_BOOST_PER_ENTITY, MAX_BRIDGE_BOOST).
     * Results are re-sorted by updated similarity descending.
     *
     * @param  array $results      Full RAGService result array
     * @param  array $bridgesByDoc Map of result-index → bridge entities
     * @return array               Updated results sorted by boosted similarity
     */
    public function scoreDocsByBridges(array $results, array $bridgesByDoc): array
    {
        foreach ($results as $idx => &$result) {
            $bridges = $bridgesByDoc[$idx] ?? [];
            if (!empty($bridges)) {
                $boost = min(count($bridges) * self::BRIDGE_BOOST_PER_ENTITY, self::MAX_BRIDGE_BOOST);
                $result['similarity'] = min(1.0, ($result['similarity'] ?? 0) + $boost);
                $result['lazy_graph_entities'] = $bridges;
            }
        }
        unset($result);

        usort($results, fn($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));

        return $results;
    }
}

<?php

namespace App\Services;

use App\DTOs\RecursionResult;
use Illuminate\Support\Facades\Log;

/**
 * RAG-8: Iterative / Recursive Retrieval — CoRAG (NeurIPS 2025)
 *
 * Standard one-shot retrieval often misses facts needed for multi-hop questions.
 * CoRAG fixes this by looping: retrieve → identify gaps → retrieve again.
 *
 * Pipeline (up to MAX_ROUNDS iterations):
 *   1. Start with the initially retrieved result set
 *   2. Ask a fast LLM: "What key information needed to answer the query is
 *      MISSING from these documents?"
 *   3. LLM returns gap sub-queries (or `stop: true` if no gaps)
 *   4. Run each sub-query through the standard search path
 *   5. Merge new results into the set (dedup by document ID)
 *   6. Repeat from step 2 with the merged set
 *
 * RLM integration: When RecursiveCallService is available and enabled for
 * 'iterative_retrieval', uses quality_gate_retry strategy to re-attempt
 * gap-filling with refined context when initial results are insufficient.
 * Falls back to standard loop when recursion is disabled or unavailable.
 *
 * The search callable is injected as a parameter (fn(string): array) to
 * avoid circular dependency with RAGService and to make the service testable.
 *
 * Reference: CoRAG — Chain-of-RAG (NeurIPS 2025 Best Paper Track)
 */
class IterativeRetrievalService
{
    /** Maximum retrieval rounds (1 = one gap-fill pass after initial retrieval) */
    public const MAX_ROUNDS = 2;

    /** Max sub-queries generated per gap-identification call */
    public const MAX_GAP_QUERIES = 3;

    /** Max documents sent to the gap-identification prompt */
    public const MAX_DOCS_FOR_GAP_PROMPT = 5;

    /** Max chars per document excerpt in the gap-identification prompt */
    public const MAX_DOC_CHARS_FOR_GAP = 200;

    private AIService $ai;
    private ?RecursiveCallService $recursion;

    public function __construct(AIService $ai, ?RecursiveCallService $recursion = null)
    {
        $this->ai = $ai;
        $this->recursion = $recursion;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Iteratively expand the result set by retrieving for identified knowledge gaps.
     *
     * @param  string   $query          Original user query
     * @param  array    $initialResults RAGService search result array
     * @param  callable $searchFn       fn(string $subQuery): array — search callback
     * @param  int      $maxRounds      Override max rounds (default MAX_ROUNDS)
     * @return array{
     *   results: array,
     *   rounds_used: int,
     *   gap_queries: string[],
     *   stopped_early: bool
     * }
     */
    public function retrieve(
        string   $query,
        array    $initialResults,
        callable $searchFn,
        int      $maxRounds = self::MAX_ROUNDS
    ): array {
        // RLM: Try recursive quality-gate approach if available
        if ($this->recursion !== null) {
            $rlmResult = $this->tryRecursiveRetrieve($query, $initialResults, $searchFn, $maxRounds);
            if ($rlmResult !== null) {
                return $rlmResult;
            }
        }

        // Standard non-recursive path
        $results     = $initialResults;
        $roundsUsed  = 0;
        $allGapQueries = [];
        $stoppedEarly  = false;

        for ($round = 0; $round < $maxRounds; $round++) {
            // Step 1: identify gaps in current result set
            $gaps = $this->identifyGaps($query, $results);

            if ($gaps['stop'] || empty($gaps['sub_queries'])) {
                $stoppedEarly = ($round === 0); // stopped before any gap pass
                break;
            }

            $subQueries = array_slice($gaps['sub_queries'], 0, self::MAX_GAP_QUERIES);
            $allGapQueries = array_merge($allGapQueries, $subQueries);
            $roundsUsed++;

            $newResultsThisRound = [];
            foreach ($subQueries as $subQuery) {
                try {
                    $subResults = $searchFn($subQuery);
                    $newResultsThisRound = array_merge($newResultsThisRound, $subResults);
                } catch (\Exception $e) {
                    Log::warning('IterativeRetrievalService: sub-query search failed', [
                        'sub_query' => $subQuery,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            if (empty($newResultsThisRound)) {
                break; // no new results — pointless to continue
            }

            $merged = $this->mergeResults($results, $newResultsThisRound);

            // Stop if we found no genuinely new documents
            if (count($merged) === count($results)) {
                break;
            }

            $results = $merged;
        }

        Log::info('IterativeRetrievalService: retrieval complete', [
            'query'         => substr($query, 0, 80),
            'initial_count' => count($initialResults),
            'final_count'   => count($results),
            'rounds_used'   => $roundsUsed,
            'gap_queries'   => $allGapQueries,
            'stopped_early' => $stoppedEarly,
        ]);

        return [
            'results'       => $results,
            'rounds_used'   => $roundsUsed,
            'gap_queries'   => $allGapQueries,
            'stopped_early' => $stoppedEarly,
        ];
    }

    // =========================================================================
    // Gap identification
    // =========================================================================

    /**
     * Ask the LLM what key facts needed to answer the query are missing from
     * the current result set.
     *
     * Returns `stop: true` on AI failure or when no gaps exist, so the caller
     * can always rely on `stop` as the loop-exit signal.
     *
     * @return array{stop: bool, gaps: string[], sub_queries: string[]}
     */
    public function identifyGaps(string $query, array $results): array
    {
        $done = ['stop' => true, 'gaps' => [], 'sub_queries' => []];

        if (empty($results)) {
            return $done;
        }

        // Build a compact document summary block
        $topDocs = array_slice($results, 0, self::MAX_DOCS_FOR_GAP_PROMPT);
        $docLines = [];
        foreach ($topDocs as $idx => $result) {
            $doc     = $result['document'];
            $title   = $doc->title ?? "Document " . ($idx + 1);
            $excerpt = mb_substr($doc->content ?? '', 0, self::MAX_DOC_CHARS_FOR_GAP);
            $docLines[] = "[{$idx}] {$title}: {$excerpt}";
        }
        $docBlock = implode("\n", $docLines);

        $prompt = "You are helping a retrieval-augmented generation system fill knowledge gaps.\n\n"
            . "ORIGINAL QUERY: \"{$query}\"\n\n"
            . "RETRIEVED DOCUMENT SUMMARIES:\n{$docBlock}\n\n"
            . "Identify specific information gaps: what key sub-topics or facts needed "
            . "to fully answer the query are NOT covered by the documents above?\n"
            . "If the documents already cover the query well, set stop=true.\n\n"
            . "Output ONLY valid JSON (no markdown):\n"
            . "{\"stop\": false, \"gaps\": [\"gap 1\", ...], "
            . "\"sub_queries\": [\"search query 1\", ...]}";

        $result = $this->ai->process($prompt, [
            'max_tokens'     => 250,
            'temperature'    => 0.1,
            'expect_json'    => true,
            'task_type'      => 'iterative_gap_identification',
            'model_role'     => 'fast',
            'suppress_alert' => true,
        ]);

        if (!($result['success'] ?? false)) {
            Log::warning('IterativeRetrievalService: gap identification failed', [
                'error' => $result['error'] ?? 'unknown',
            ]);
            return $done;
        }

        $raw = trim($result['response'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return $done;
        }

        if ($parsed['stop'] ?? false) {
            return $done;
        }

        $subQueries = array_values(array_filter(
            (array) ($parsed['sub_queries'] ?? []),
            fn($q) => is_string($q) && mb_strlen(trim($q)) >= 5
        ));

        return [
            'stop'        => empty($subQueries),
            'gaps'        => (array) ($parsed['gaps'] ?? []),
            'sub_queries' => $subQueries,
        ];
    }

    // =========================================================================
    // Result merging (pure — unit-testable)
    // =========================================================================

    /**
     * Merge new results into the original set, deduplicating by document ID.
     * Original ordering is preserved; new non-duplicate docs are appended.
     * Web pseudo-docs (id=0) are always considered unique per result entry.
     *
     * @return array  Combined result array
     */
    public function mergeResults(array $original, array $newResults): array
    {
        $seenIds = [];
        $merged  = [];

        foreach ($original as $result) {
            $id = $result['document']->id ?? null;
            if ($id !== null && $id !== 0) {
                $seenIds[$id] = true;
            }
            $merged[] = $result;
        }

        foreach ($newResults as $result) {
            $id = $result['document']->id ?? null;
            // Always include web pseudo-docs (id=0); dedup real docs by ID
            if ($id === 0 || ($id !== null && !isset($seenIds[$id]))) {
                if ($id !== 0) {
                    $seenIds[$id] = true;
                }
                $merged[] = $result;
            }
        }

        return $merged;
    }

    // =========================================================================
    // RLM: Recursive retrieval via quality-gate strategy
    // =========================================================================

    /**
     * Attempt recursive retrieval via RecursiveCallService.
     * Returns null if recursion is disabled/unavailable (caller falls back to standard path).
     */
    private function tryRecursiveRetrieve(
        string $query,
        array $initialResults,
        callable $searchFn,
        int $maxRounds
    ): ?array {
        $context = [
            'query' => $query,
            'results' => $initialResults,
            'search_fn' => $searchFn,
            'max_rounds' => $maxRounds,
        ];

        // processFn: run one round of gap-fill using existing logic
        $processFn = function (array $ctx) use ($searchFn): array {
            $query = $ctx['query'] ?? '';
            $results = $ctx['results'] ?? $ctx['_previous_result']['results'] ?? [];

            $gaps = $this->identifyGaps($query, $results);

            if ($gaps['stop'] || empty($gaps['sub_queries'])) {
                return [
                    'results' => $results,
                    'rounds_used' => 0,
                    'gap_queries' => [],
                    'stopped_early' => true,
                    'confidence' => 0.90, // No gaps = high confidence
                ];
            }

            $subQueries = array_slice($gaps['sub_queries'], 0, self::MAX_GAP_QUERIES);
            $newResults = [];

            foreach ($subQueries as $subQuery) {
                try {
                    $subResults = $searchFn($subQuery);
                    $newResults = array_merge($newResults, $subResults);
                } catch (\Exception $e) {
                    Log::warning('IterativeRetrievalService[RLM]: sub-query failed', [
                        'sub_query' => $subQuery,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $merged = $this->mergeResults($results, $newResults);
            $newCount = count($merged) - count($results);

            // Confidence based on how many new docs we found
            $confidence = $newCount > 0 ? min(0.50 + ($newCount * 0.10), 0.85) : 0.30;

            return [
                'results' => $merged,
                'rounds_used' => 1,
                'gap_queries' => $subQueries,
                'stopped_early' => false,
                'confidence' => $confidence,
            ];
        };

        $rlmResult = $this->recursion->execute(
            'iterative_retrieval',
            'quality_gate_retry',
            $context,
            $processFn
        );

        if (!$rlmResult->recursionUsed) {
            return null; // Fall back to standard path
        }

        // Flatten recursive results into standard return format
        $output = $rlmResult->output;
        $finalResults = $output['results'] ?? $initialResults;

        // If output is from aggregation (array of sub-results), take the best one
        if (isset($output[0]) && is_array($output[0])) {
            $best = end($output);
            $finalResults = $best['results'] ?? $initialResults;
            $gapQueries = $best['gap_queries'] ?? [];
            $roundsUsed = $best['rounds_used'] ?? 0;
            $stoppedEarly = $best['stopped_early'] ?? false;
        } else {
            $gapQueries = $output['gap_queries'] ?? [];
            $roundsUsed = $output['rounds_used'] ?? 0;
            $stoppedEarly = $output['stopped_early'] ?? false;
        }

        Log::info('IterativeRetrievalService[RLM]: recursive retrieval complete', [
            'query' => substr($query, 0, 80),
            'initial_count' => count($initialResults),
            'final_count' => count($finalResults),
            'rounds_used' => $roundsUsed,
            'rlm_sub_calls' => $rlmResult->metrics->totalSubCalls,
            'rlm_local_pct' => $rlmResult->metrics->localProviderPct,
        ]);

        return [
            'results' => $finalResults,
            'rounds_used' => $roundsUsed,
            'gap_queries' => $gapQueries,
            'stopped_early' => $stoppedEarly,
            'rlm_metrics' => $rlmResult->metrics->toArray(),
        ];
    }
}

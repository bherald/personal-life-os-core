<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * RAG-6: Self-RAG Relevance Gating (Asai et al., ICLR 2024 — arXiv:2310.11511)
 *
 * After retrieval, scores each candidate document for relevance to the query
 * using a fast LLM call. Documents below the relevance threshold are filtered
 * out before they are injected into the generation context.
 *
 * Why this matters: vector similarity ≠ relevance. A document can be
 * embedding-close to the query without actually containing an answer. Filtering
 * irrelevant documents reduces context noise and improves answer precision,
 * especially for topical or factual queries where off-topic context confuses
 * the generator.
 *
 * Pipeline:
 *   1. For each of the top MAX_DOCS_TO_GATE results, ask a fast LLM to score
 *      relevance 0.0–1.0 (one LLM call per document)
 *   2. Drop documents whose score < threshold
 *   3. Safety fallback: if all documents are filtered, return the single
 *      highest-scoring one to prevent empty context
 *   4. Results beyond MAX_DOCS_TO_GATE are passed through with no gating
 *      (lower-similarity docs rarely need extra filtering)
 *
 * Reference: Self-RAG (Asai et al., ICLR 2024, arXiv:2310.11511)
 */
class RelevanceGatingService
{
    /** Documents below this relevance score are dropped */
    public const RELEVANCE_THRESHOLD = 0.50;

    /** Max documents to actively gate (caps LLM calls) */
    public const MAX_DOCS_TO_GATE = 12;

    /** Max chars of document content sent in the relevance prompt */
    public const MAX_DOC_CHARS = 500;

    private AIService $ai;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Filter the retrieval result set to documents relevant to the query.
     *
     * @param  string $query    The user query
     * @param  array  $results  RAGService search result array (each has 'document' stdClass + 'similarity')
     * @param  float  $threshold  Override default threshold (0.0 = keep all)
     * @return array{
     *   results: array,
     *   filtered_count: int,
     *   total_count: int
     * }
     */
    public function gateResults(string $query, array $results, float $threshold = self::RELEVANCE_THRESHOLD): array
    {
        $total = count($results);

        if (empty($results) || $threshold <= 0.0) {
            return ['results' => $results, 'filtered_count' => 0, 'total_count' => $total];
        }

        // Split: gate the top slice, pass the rest through unchanged
        $toGate    = array_slice($results, 0, self::MAX_DOCS_TO_GATE);
        $passThrough = array_slice($results, self::MAX_DOCS_TO_GATE);

        $scored        = [];
        $filteredCount = 0;
        $bestScore     = -1.0;
        $bestResult    = null;

        foreach ($toGate as $result) {
            $doc     = $result['document'];
            $content = $doc->content ?? '';
            $title   = $doc->title ?? '';

            $scored_result = $result;

            // Web pseudo-docs (id=0) always pass — they were web-fetched for this query
            if (($doc->id ?? 1) === 0) {
                $scored_result['relevance_score'] = 1.0;
                $scored[] = $scored_result;
                if (1.0 > $bestScore) {
                    $bestScore  = 1.0;
                    $bestResult = $scored_result;
                }
                continue;
            }

            $scoreResult = $this->scoreDocument($query, $content, $title);
            $score = $scoreResult['score'];
            $scored_result['relevance_score'] = $score;

            if ($score > $bestScore) {
                $bestScore  = $score;
                $bestResult = $scored_result;
            }

            if ($score < $threshold) {
                $filteredCount++;
                Log::debug('RelevanceGatingService: filtered document', [
                    'doc_id'  => $doc->id ?? null,
                    'score'   => $score,
                    'reason'  => $scoreResult['reason'] ?? '',
                ]);
                continue;
            }

            $scored[] = $scored_result;
        }

        // Safety: never return an empty set — keep the best-scored doc
        if (empty($scored) && $bestResult !== null) {
            $scored        = [$bestResult];
            $filteredCount = $total - 1;

            Log::warning('RelevanceGatingService: all docs filtered — keeping best-scored doc', [
                'query'      => substr($query, 0, 80),
                'best_score' => $bestScore,
            ]);
        }

        $final = array_merge($scored, $passThrough);

        Log::info('RelevanceGatingService: gating complete', [
            'query'          => substr($query, 0, 80),
            'total'          => $total,
            'gated'          => count($toGate),
            'filtered'       => $filteredCount,
            'kept'           => count($scored),
            'pass_through'   => count($passThrough),
            'threshold'      => $threshold,
        ]);

        return [
            'results'        => $final,
            'filtered_count' => $filteredCount,
            'total_count'    => $total,
        ];
    }

    // =========================================================================
    // Per-document relevance scoring
    // =========================================================================

    /**
     * Ask a fast LLM whether the document is relevant to the query.
     *
     * On AI failure, returns score = 0.5 (neutral — passes through rather than
     * incorrectly filtering).
     *
     * @return array{relevant: bool, score: float, reason: string}
     */
    public function scoreDocument(string $query, string $docContent, string $docTitle): array
    {
        $neutral = ['relevant' => true, 'score' => 0.5, 'reason' => 'Scoring unavailable — neutral pass-through.'];

        $snippet = mb_substr($docContent, 0, self::MAX_DOC_CHARS);

        $prompt = "You are assessing document relevance for a retrieval-augmented generation system.\n\n"
            . "QUERY: \"{$query}\"\n\n"
            . "DOCUMENT TITLE: {$docTitle}\n"
            . "DOCUMENT EXCERPT:\n{$snippet}\n\n"
            . "Is this document relevant to the query? A document is relevant if it contains "
            . "information that would directly help answer the query.\n\n"
            . "Output ONLY valid JSON (no markdown):\n"
            . "{\"relevant\": true, \"score\": 0.0-1.0, \"reason\": \"brief explanation\"}";

        $result = $this->ai->process($prompt, [
            'max_tokens'     => 100,
            'temperature'    => 0.0,
            'expect_json'    => true,
            'task_type'      => 'relevance_gating',
            'model_role'     => 'fast',
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

        return [
            'relevant' => (bool) ($parsed['relevant'] ?? true),
            'score'    => max(0.0, min(1.0, (float) ($parsed['score'] ?? 0.5))),
            'reason'   => (string) ($parsed['reason'] ?? ''),
        ];
    }
}

<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\Log;

/**
 * RAG-2: CRAG (Corrective RAG) — ICLR 2025
 *
 * Evaluates the quality of retrieved chunks and triggers a web fallback
 * when the RAG index cannot answer the query adequately.
 *
 * Three-class classification based on best cosine similarity:
 *   CORRECT   (≥ HIGH_THRESHOLD)  — RAG is sufficient, no web needed
 *   AMBIGUOUS (≥ LOW_THRESHOLD)   — some results useful, augment with web
 *   INCORRECT (< LOW_THRESHOLD)   — RAG is unhelpful, fall back to web
 *
 * Web pseudo-documents carry id=0 and source_type='web' so callers can
 * identify them and handle them separately from persisted RAG chunks.
 *
 * Reference: Corrective RAG — Shi et al., ICLR 2025 (arXiv:2401.15884)
 */
class CRAGService
{
    use RecursionAware;

    public const CORRECT = 'CORRECT';

    public const AMBIGUOUS = 'AMBIGUOUS';

    public const INCORRECT = 'INCORRECT';

    /** Similarity above this → CORRECT (web not needed) */
    public const HIGH_THRESHOLD = 0.65;

    /** Similarity above this but below HIGH → AMBIGUOUS (augment with web) */
    public const LOW_THRESHOLD = 0.45;

    /** Max web results to fetch on fallback */
    public const WEB_FALLBACK_LIMIT = 5;

    private AIService $ai;

    private SearXNGService $searxng;

    public function __construct(AIService $ai, SearXNGService $searxng)
    {
        $this->ai = $ai;
        $this->searxng = $searxng;
    }

    // =========================================================================
    // Retrieval quality evaluation
    // =========================================================================

    /**
     * Classify retrieval quality and decide if a web fallback is needed.
     *
     * @param  array  $results  RAGService::search() result format [{document, similarity}]
     * @return array{
     *   classification: string,
     *   best_score: float,
     *   avg_score: float,
     *   web_fallback_needed: bool,
     *   scores: float[]
     * }
     */
    public function evaluateRetrieval(string $query, array $results): array
    {
        // RLM: Try recursive retrieval evaluation
        $rlm = $this->tryRecursive('crag', 'quality_gate_retry', ['query' => $query, 'results' => $results], function ($ctx) {
            return $this->evaluateRetrievalDirect($ctx['query'] ?? $ctx['data'], $ctx['results'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        return $this->evaluateRetrievalDirect($query, $results);
    }

    private function evaluateRetrievalDirect(string $query, array $results): array
    {
        if (empty($results)) {
            return [
                'classification' => self::INCORRECT,
                'best_score' => 0.0,
                'avg_score' => 0.0,
                'web_fallback_needed' => true,
                'scores' => [],
            ];
        }

        $scores = array_map(fn ($r) => (float) ($r['similarity'] ?? 0.0), $results);

        $bestScore = max($scores);
        $avgScore = array_sum($scores) / count($scores);

        if ($bestScore >= self::HIGH_THRESHOLD) {
            $classification = self::CORRECT;
        } elseif ($bestScore >= self::LOW_THRESHOLD) {
            $classification = self::AMBIGUOUS;
        } else {
            $classification = self::INCORRECT;
        }

        $webFallbackNeeded = $classification !== self::CORRECT;

        Log::debug('CRAGService: retrieval evaluated', [
            'query' => substr($query, 0, 80),
            'classification' => $classification,
            'best_score' => round($bestScore, 3),
            'avg_score' => round($avgScore, 3),
            'doc_count' => count($results),
            'web_needed' => $webFallbackNeeded,
        ]);

        return [
            'classification' => $classification,
            'best_score' => $bestScore,
            'avg_score' => $avgScore,
            'web_fallback_needed' => $webFallbackNeeded,
            'scores' => $scores,
        ];
    }

    // =========================================================================
    // Web fallback
    // =========================================================================

    /**
     * Search the web via SearXNG and return results as RAG-compatible
     * pseudo-document objects.
     *
     * Pseudo-doc fields: id=0, title, content (snippet), source_type='web',
     *   url, parent_id=null, document_type='web_result'
     *
     * @return array<int, array{document: object, similarity: float, crag_web: true}>
     */
    public function webFallback(string $query, int $limit = self::WEB_FALLBACK_LIMIT): array
    {
        $searchResult = $this->searxng->search($query, $limit);

        if (! ($searchResult['success'] ?? false) || empty($searchResult['results'])) {
            Log::warning('CRAGService: web fallback returned no results', [
                'query' => substr($query, 0, 80),
                'error' => $searchResult['error'] ?? 'no results',
            ]);

            return [];
        }

        $webDocs = [];
        foreach ($searchResult['results'] as $idx => $item) {
            $snippet = trim($item['snippet'] ?? '');
            if (empty($snippet)) {
                continue;
            }

            $pseudoDoc = (object) [
                'id' => 0,
                'document_type' => 'web_result',
                'source_type' => 'web',
                'title' => $item['title'] ?? 'Web result',
                'content' => $snippet,
                'url' => $item['url'] ?? null,
                'parent_id' => null,
                'metadata' => json_encode([
                    'source_engine' => $item['source_engine'] ?? 'SearXNG',
                    'engines' => $item['engines'] ?? [],
                ]),
                'created_at' => null,
            ];

            // Assign a decaying similarity proxy — web results rank below good RAG hits
            // but above poor ones. First result gets 0.55, last gets 0.40.
            $similarityProxy = 0.55 - ($idx * (0.15 / max(1, $limit - 1)));

            $webDocs[] = [
                'document' => $pseudoDoc,
                'similarity' => round(max(0.40, $similarityProxy), 3),
                'crag_web' => true,
            ];
        }

        Log::info('CRAGService: web fallback retrieved', [
            'query' => substr($query, 0, 80),
            'results' => count($webDocs),
        ]);

        return $webDocs;
    }

    // =========================================================================
    // Merge helpers
    // =========================================================================

    /**
     * Merge RAG results with web results according to classification.
     *
     * CORRECT:   RAG only (web unused).
     * AMBIGUOUS: High-quality RAG chunks (≥ LOW_THRESHOLD) + web.
     * INCORRECT: Web only (RAG is below threshold).
     *            Falls back to RAG if web is empty (SearXNG unavailable).
     *
     * @param  array  $ragResults  [{document, similarity}]
     * @param  array  $webResults  [{document, similarity, crag_web}]
     * @param  string  $classification  CRAGService::CORRECT|AMBIGUOUS|INCORRECT
     * @return array Merged result list in RAGService result format
     */
    public function merge(array $ragResults, array $webResults, string $classification): array
    {
        if ($classification === self::CORRECT) {
            return $ragResults;
        }

        if (empty($webResults)) {
            // SearXNG unavailable — degrade gracefully
            return $ragResults;
        }

        if ($classification === self::INCORRECT) {
            // RAG is useless — web only
            return $webResults;
        }

        // AMBIGUOUS — keep relevant RAG + supplement with web
        $relevantRag = array_values(array_filter(
            $ragResults,
            fn ($r) => ($r['similarity'] ?? 0) >= self::LOW_THRESHOLD
        ));

        return array_merge($relevantRag, $webResults);
    }
}

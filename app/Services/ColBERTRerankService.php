<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RAG-7: ColBERT-Inspired Late Interaction Reranker (SIGIR 2025 Best Paper)
 *
 * ColBERT (Contextualized Late Interaction over BERT) scores query–document
 * relevance via MaxSim: each query token embedding finds its closest document
 * token embedding, and scores are summed across all query tokens.
 *
 * This implementation adapts the MaxSim operator to our existing infrastructure:
 *   - Query is split into overlapping word-span "sub-queries" (bigrams, trigrams)
 *     rather than token-level embeddings (we don't have a token-level model)
 *   - Document sentence-window embeddings (rag_sentence_embeddings) act as the
 *     per-segment document representation
 *   - MaxSim SQL: MAX(1 - cosine_distance) per span across the doc's sentences
 *   - ColBERT score = mean MaxSim across all spans → blended with base similarity
 *
 * Documents without sentence-window embeddings are passed through unchanged.
 * If fewer than MIN_COVERED_DOCS docs have sentence embeddings, reranking is
 * skipped entirely to avoid biasing the result set.
 *
 * Reference: ColBERTv2 (Santhanam et al., SIGIR 2025 Best Paper Award)
 */
class ColBERTRerankService
{
    /** Weight of ColBERT score in final blend (0=ignore ColBERT, 1=ignore similarity) */
    public const BLEND_WEIGHT = 0.30;

    /** Max word-spans to embed per query (caps embedding calls) */
    public const MAX_QUERY_SPANS = 6;

    /** Min word length for unigrams to qualify as a span */
    public const MIN_UNIGRAM_LEN = 4;

    /**
     * Min fraction of result docs that must have sentence embeddings for
     * ColBERT reranking to fire. Below this → skip (avoid skewing scores).
     */
    public const MIN_COVERAGE_FRACTION = 0.50;

    private const CONNECTION = 'pgsql_rag';

    private AIService $ai;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Rerank the result array using ColBERT-style MaxSim against sentence embeddings.
     *
     * @param  string $query   The user query
     * @param  array  $results RAGService result array (each has 'document' + 'similarity')
     * @return array  Same structure, sorted by blended colbert+similarity score descending
     */
    public function rerank(string $query, array $results): array
    {
        if (count($results) < 2) {
            return $results;
        }

        // Step 1: generate query spans
        $spans = $this->generateQuerySpans($query);
        if (empty($spans)) {
            return $results;
        }

        // Step 2: identify doc IDs that have sentence embeddings (skip web pseudo-docs id=0)
        $docIds = array_values(array_filter(
            array_map(fn($r) => $r['document']->id, $results),
            fn($id) => $id > 0
        ));

        if (empty($docIds)) {
            return $results;
        }

        // Check coverage before spending embedding calls
        $coveredIds = $this->getDocIdsWithSentenceEmbeddings($docIds);
        $coverage   = count($coveredIds) / count($docIds);

        if ($coverage < self::MIN_COVERAGE_FRACTION) {
            Log::debug('ColBERTRerankService: insufficient sentence embedding coverage — skipping', [
                'covered'  => count($coveredIds),
                'total'    => count($docIds),
                'coverage' => round($coverage, 2),
            ]);
            return $results;
        }

        // Step 3: embed each query span
        $spanEmbeddings = [];
        foreach ($spans as $span) {
            $emb = $this->ai->generateEmbedding($span);
            if ($emb['success'] ?? false) {
                $spanEmbeddings[] = $emb['embedding'];
            }
        }

        if (empty($spanEmbeddings)) {
            return $results;
        }

        // Step 4: compute MaxSim scores for covered docs
        $maxSimByDoc = $this->computeMaxSimScores($spanEmbeddings, $coveredIds);

        // Step 5: blend and re-sort
        $reranked = [];
        foreach ($results as $result) {
            $docId      = $result['document']->id ?? 0;
            $similarity = $result['similarity'] ?? 0.0;

            if (isset($maxSimByDoc[$docId])) {
                $colbertScore            = $maxSimByDoc[$docId]; // already normalised 0–1
                $result['colbert_score'] = $colbertScore;
                $result['final_score']   = $this->blendScore($similarity, $colbertScore, self::BLEND_WEIGHT);
            } else {
                // No sentence embeddings → use similarity as-is for stable sort position
                $result['colbert_score'] = null;
                $result['final_score']   = $similarity;
            }

            $reranked[] = $result;
        }

        usort($reranked, fn($a, $b) => ($b['final_score'] ?? 0) <=> ($a['final_score'] ?? 0));

        Log::info('ColBERTRerankService: reranked results', [
            'query'         => substr($query, 0, 80),
            'spans'         => count($spans),
            'span_embedded' => count($spanEmbeddings),
            'docs_scored'   => count($maxSimByDoc),
            'docs_total'    => count($results),
        ]);

        return $reranked;
    }

    // =========================================================================
    // Query span generation (pure — unit-testable)
    // =========================================================================

    /**
     * Split the query into overlapping word spans for MaxSim matching.
     * Produces: content unigrams (len >= MIN_UNIGRAM_LEN) + bigrams + trigrams,
     * capped at MAX_QUERY_SPANS.
     *
     * @return string[]
     */
    public function generateQuerySpans(string $query): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        $words = preg_split('/\s+/', $query);
        $spans = [];

        // Unigrams (content words only)
        foreach ($words as $w) {
            if (mb_strlen($w) >= self::MIN_UNIGRAM_LEN) {
                $spans[] = $w;
            }
        }

        // Bigrams
        for ($i = 0; $i < count($words) - 1; $i++) {
            $spans[] = $words[$i] . ' ' . $words[$i + 1];
        }

        // Trigrams
        for ($i = 0; $i < count($words) - 2; $i++) {
            $spans[] = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
        }

        return array_slice(array_unique($spans), 0, self::MAX_QUERY_SPANS);
    }

    // =========================================================================
    // Score blending (pure — unit-testable)
    // =========================================================================

    /**
     * Blend base similarity with ColBERT score.
     * final = (1 - weight) * similarity + weight * colbertScore
     */
    public function blendScore(float $similarity, float $colbertScore, float $weight = self::BLEND_WEIGHT): float
    {
        $weight = max(0.0, min(1.0, $weight));
        return (1 - $weight) * $similarity + $weight * $colbertScore;
    }

    // =========================================================================
    // Database operations (protected — mockable in tests)
    // =========================================================================

    /**
     * Return the subset of docIds that have at least one sentence embedding.
     *
     * @param  int[] $docIds
     * @return int[]
     */
    protected function getDocIdsWithSentenceEmbeddings(array $docIds): array
    {
        if (empty($docIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT DISTINCT document_id FROM rag_sentence_embeddings WHERE document_id IN ({$placeholders})",
            $docIds
        );

        return array_map(fn($r) => (int) $r->document_id, $rows);
    }

    /**
     * Compute per-document MaxSim scores across all span embeddings.
     *
     * For each span embedding, finds the maximum cosine similarity with any
     * sentence embedding in the document. The per-document ColBERT score is
     * the mean MaxSim across all spans (normalised to 0–1).
     *
     * @param  float[][] $spanEmbeddings  Array of 768-dim float arrays
     * @param  int[]     $docIds
     * @return array<int, float>  Map of document_id → normalised colbert score
     */
    protected function computeMaxSimScores(array $spanEmbeddings, array $docIds): array
    {
        if (empty($spanEmbeddings) || empty($docIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($docIds), '?'));

        // Accumulate MaxSim per doc across all spans
        $sumByDoc   = [];
        $spanCount  = count($spanEmbeddings);

        foreach ($spanEmbeddings as $embedding) {
            $vectorStr = PgVector::literal($embedding);
            $bindings  = array_merge([$vectorStr], $docIds);

            $rows = DB::connection(self::CONNECTION)->select(
                "SELECT document_id, MAX(1.0 - (embedding <=> ?::vector)) AS max_sim
                 FROM rag_sentence_embeddings
                 WHERE document_id IN ({$placeholders})
                 GROUP BY document_id",
                $bindings
            );

            foreach ($rows as $row) {
                $id = (int) $row->document_id;
                $sumByDoc[$id] = ($sumByDoc[$id] ?? 0.0) + (float) $row->max_sim;
            }
        }

        // Normalise: mean MaxSim → 0–1
        $result = [];
        foreach ($sumByDoc as $docId => $sum) {
            $result[$docId] = max(0.0, min(1.0, $sum / $spanCount));
        }

        return $result;
    }
}

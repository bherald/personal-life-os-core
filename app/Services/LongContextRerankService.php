<?php

namespace App\Services;

/**
 * RAG-16: Long Context Reranking — document-level rerank via sliding windows
 *
 * Standard rerankers score only the first 500–2000 chars of each document.
 * For long documents (genealogy records, research reports, transcripts),
 * the most relevant passage may appear deep in the text — a phenomenon
 * known as "lost in the middle" (Liu et al., 2023).
 *
 * This service splits each document into overlapping windows and scores
 * each window independently against the query. The document's final score
 * is the maximum window score, adjusted by a position-aware weight:
 *   - Windows in the first 30% get a small early-content bonus (+8%)
 *   - Windows in the last 20% get a trailing bonus (+4%) to counter the
 *     tendency for models to ignore content between start and end
 *
 * All logic is pure (no LLM, no DB) — zero latency overhead beyond splitting.
 * Scoring uses TF-weighted term overlap (consistent and deterministic).
 *
 * Reference: "Lost in the Middle" (Liu et al., ACL 2023)
 */
class LongContextRerankService
{
    /** Default window size in characters */
    public const DEFAULT_WINDOW_SIZE = 1500;

    /** Default overlap between windows in characters */
    public const DEFAULT_OVERLAP = 300;

    /** Minimum document length to bother windowing (shorter = score whole doc) */
    public const MIN_LONG_DOC_CHARS = 2000;

    /** Fraction of query terms that must match for a window to be "relevant" */
    public const MIN_MATCH_FRACTION = 0.20;

    /** Position bonus for windows in the first 30% of the document */
    public const EARLY_WINDOW_BONUS = 0.08;

    /** Position bonus for windows in the last 20% (lost-in-the-middle counter) */
    public const TRAILING_WINDOW_BONUS = 0.04;

    /** Maximum blend weight for window score vs original similarity */
    public const WINDOW_BLEND_WEIGHT = 0.35;

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Rerank results using long-context window scoring.
     * Only affects documents longer than MIN_LONG_DOC_CHARS — short docs pass through.
     *
     * @param  string $query
     * @param  array  $results  RAGService result array (each has 'document' + 'similarity')
     * @return array  Updated results sorted by final score descending
     */
    public function rerank(string $query, array $results): array
    {
        if (empty($results)) {
            return $results;
        }

        $queryTerms = $this->tokenize($query);
        if (empty($queryTerms)) {
            return $results;
        }

        foreach ($results as &$result) {
            $content = $result['document']->content ?? '';
            if (strlen($content) < self::MIN_LONG_DOC_CHARS) {
                continue; // Short doc — existing score is sufficient
            }

            $scored = $this->scoreDocument($queryTerms, $content);
            if ($scored['window_count'] <= 1) {
                continue;
            }

            // Blend window score into existing similarity
            $original = (float) ($result['similarity'] ?? 0);
            $blended  = ($original * (1 - self::WINDOW_BLEND_WEIGHT))
                      + ($scored['score'] * self::WINDOW_BLEND_WEIGHT);

            $result['similarity']           = round(min(1.0, $blended), 4);
            $result['long_context_score']   = $scored['score'];
            $result['long_context_window']  = $scored['best_window_idx'];
            $result['long_context_windows'] = $scored['window_count'];
        }
        unset($result);

        usort($results, fn($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));

        return $results;
    }

    // =========================================================================
    // Document scoring (pure)
    // =========================================================================

    /**
     * Score a document by finding its best-matching window.
     *
     * @param  string[] $queryTerms  Tokenized query terms
     * @param  string   $content
     * @return array{score: float, best_window_idx: int, window_count: int}
     */
    public function scoreDocument(array $queryTerms, string $content): array
    {
        $windows = $this->splitWindows($content);
        $n = count($windows);

        if ($n === 0) {
            return ['score' => 0.0, 'best_window_idx' => 0, 'window_count' => 0];
        }

        $bestScore = 0.0;
        $bestIdx   = 0;

        foreach ($windows as $idx => $window) {
            $raw = $this->scoreWindow($queryTerms, $window);
            $positioned = $this->applyPositionWeight($raw, $idx, $n);

            if ($positioned > $bestScore) {
                $bestScore = $positioned;
                $bestIdx   = $idx;
            }
        }

        return [
            'score'           => round($bestScore, 4),
            'best_window_idx' => $bestIdx,
            'window_count'    => $n,
        ];
    }

    // =========================================================================
    // Window splitting (pure)
    // =========================================================================

    /**
     * Split content into overlapping character windows.
     * Windows always start at word boundaries to avoid cutting mid-word.
     *
     * @return string[]
     */
    public function splitWindows(
        string $content,
        int $windowSize = self::DEFAULT_WINDOW_SIZE,
        int $overlap    = self::DEFAULT_OVERLAP
    ): array {
        $len = strlen($content);
        if ($len <= $windowSize) {
            return [$content];
        }

        $windows = [];
        $step    = max(1, $windowSize - $overlap);
        $start   = 0;

        while ($start < $len) {
            $end = $start + $windowSize;
            if ($end >= $len) {
                $windows[] = substr($content, $start);
                break;
            }

            // Extend to next word boundary (avoid mid-word cuts)
            $boundary = strrpos(substr($content, $start, $windowSize + 50), ' ');
            $end = $boundary !== false ? $start + $boundary : $end;

            $windows[] = substr($content, $start, $end - $start);
            $start    += $step;
        }

        return $windows;
    }

    // =========================================================================
    // Window scoring (pure)
    // =========================================================================

    /**
     * Score a single window against query terms.
     * Uses TF-weighted term overlap: fraction of query terms present,
     * weighted by how many times each term appears in the window.
     *
     * @param  string[] $queryTerms
     * @return float 0.0–1.0
     */
    public function scoreWindow(array $queryTerms, string $window): float
    {
        if (empty($queryTerms) || empty($window)) {
            return 0.0;
        }

        $windowLower = mb_strtolower($window);
        $totalTerms  = count($queryTerms);
        $matched     = 0;
        $tfSum       = 0.0;

        foreach ($queryTerms as $term) {
            $count = substr_count($windowLower, $term);
            if ($count > 0) {
                $matched++;
                // Log-dampened TF: prevents single repeated word dominating
                $tfSum += 1 + log($count);
            }
        }

        if ($matched < max(1, (int) ceil($totalTerms * self::MIN_MATCH_FRACTION))) {
            return 0.0;
        }

        $overlapScore = $matched / $totalTerms;
        $tfScore      = $totalTerms > 0 ? min(1.0, $tfSum / ($totalTerms * 3)) : 0.0;

        return round(($overlapScore * 0.7) + ($tfScore * 0.3), 4);
    }

    /**
     * Apply position-aware weight to a raw window score.
     * Implements "lost-in-the-middle" counter-weighting:
     *   - Early windows (+8%): typical users expect answers near the start
     *   - Late windows (+4%): LLMs tend to attend to the end as well
     *   - Middle windows: no bonus (most likely to be missed by LLMs)
     */
    public function applyPositionWeight(float $score, int $idx, int $total): float
    {
        if ($total <= 1 || $score <= 0.0) {
            return $score;
        }

        $fraction = $idx / max(1, $total - 1); // 0.0 (first) → 1.0 (last)

        if ($fraction <= 0.30) {
            $score *= (1 + self::EARLY_WINDOW_BONUS);
        } elseif ($fraction >= 0.80) {
            $score *= (1 + self::TRAILING_WINDOW_BONUS);
        }

        return min(1.0, $score);
    }

    // =========================================================================
    // Text utilities (pure)
    // =========================================================================

    /**
     * Tokenize query into lowercase terms, removing stop words and short tokens.
     *
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $text  = mb_strtolower($text);
        $text  = preg_replace('/[^\w\s]/u', ' ', $text);
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        $stopWords = [
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'this', 'that', 'these', 'those', 'it', 'its',
            'not', 'as', 'up', 'if', 'about', 'into', 'over', 'also', 'than',
        ];

        return array_values(array_filter(
            $words,
            fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stopWords, true)
        ));
    }
}

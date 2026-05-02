<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * RAG-13: RAG Security — Provenance Tracking & Anomaly Detection
 *
 * Defends against PoisonedRAG-style attacks (USENIX 2025) where adversarial
 * documents are injected into the corpus to manipulate LLM responses.
 *
 * Attack patterns detected:
 *   1. Similarity outlier — one document scores 2+ standard deviations above
 *      the retrieval mean. Legitimate corpora cluster; adversarial docs are
 *      engineered for embedding proximity and stand out statistically.
 *
 *   2. Content-query keyword mismatch — document has high similarity score but
 *      shares very few keywords with the query. Indicates the similarity was
 *      achieved via embedding manipulation rather than genuine topical relevance.
 *
 *   3. Near-duplicate amplification — multiple retrieved documents with nearly
 *      identical content (Jaccard ≥ threshold). Signals a flooding attack that
 *      tries to inject a false fact through repetition.
 *
 *   4. Unknown-source web documents — CRAG web pseudo-docs (id=0) carry no
 *      established credibility and are flagged at LOW severity for awareness.
 *
 * All checks are pure (no LLM, no DB) — zero latency overhead.
 * The audit is advisory: results are never filtered automatically.
 * Callers choose whether to act on high-risk flags.
 *
 * Reference: PoisonedRAG (Zou et al., USENIX Security 2025)
 */
class RAGSecurityService
{
    /** Z-score threshold above which a similarity score is flagged as an outlier */
    public const OUTLIER_ZSCORE = 2.0;

    /** Minimum docs in result set for statistical outlier detection to fire */
    public const MIN_DOCS_FOR_STATS = 3;

    /**
     * Minimum Jaccard overlap between query keywords and doc keywords.
     * Below this → content-query mismatch flag.
     */
    public const MIN_KEYWORD_OVERLAP = 0.10;

    /**
     * Jaccard similarity between two documents' keyword sets above which
     * they are considered near-duplicates.
     */
    public const NEAR_DUPLICATE_THRESHOLD = 0.80;

    /** Minimum words in a document to qualify for keyword analysis */
    public const MIN_DOC_WORDS = 20;

    private const SEVERITY_WEIGHTS = [
        'low'    => 0.1,
        'medium' => 0.3,
        'high'   => 0.6,
    ];

    private const STOP_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'this', 'that', 'these', 'those', 'it', 'its',
        'not', 'as', 'up', 'if', 'about', 'into', 'over', 'also', 'than',
    ];

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Audit a retrieval result set for security anomalies.
     *
     * @param  string $query
     * @param  array  $results  RAGService result array (each has 'document' + 'similarity')
     * @return array{
     *   safe: bool,
     *   risk_score: float,
     *   flagged_count: int,
     *   flags: array,
     *   doc_count: int
     * }
     */
    public function auditResults(string $query, array $results): array
    {
        $empty = [
            'safe'          => true,
            'risk_score'    => 0.0,
            'flagged_count' => 0,
            'flags'         => [],
            'doc_count'     => count($results),
        ];

        if (empty($results)) {
            return $empty;
        }

        $flags = [];

        // Check 1: Similarity Z-score outliers
        $similarities = array_map(fn($r) => (float) ($r['similarity'] ?? 0), $results);
        $outlierIdxs  = $this->detectSimilarityOutliers($similarities);
        foreach ($outlierIdxs as $idx) {
            $doc     = $results[$idx]['document'];
            $flags[] = [
                'doc_id'   => $doc->id ?? null,
                'doc_title' => $doc->title ?? '',
                'type'     => 'similarity_outlier',
                'severity' => 'medium',
                'detail'   => sprintf(
                    'Similarity %.3f is %.1f+ std devs above mean (possible crafted embedding)',
                    $similarities[$idx],
                    self::OUTLIER_ZSCORE
                ),
            ];
        }

        // Check 2: Content-query keyword mismatch for high-similarity docs
        $queryKeywords = $this->extractKeywords($query);
        foreach ($results as $result) {
            $doc        = $result['document'];
            $content    = $doc->content ?? '';
            $similarity = (float) ($result['similarity'] ?? 0);

            if ($similarity < 0.50) {
                continue; // Only check high-similarity docs
            }

            $wordCount = count(preg_split('/\s+/', trim($content)));
            if ($wordCount < self::MIN_DOC_WORDS) {
                continue;
            }

            $docKeywords = $this->extractKeywords($content);
            $overlap     = $this->jaccardSimilarity($queryKeywords, $docKeywords);

            if ($overlap < self::MIN_KEYWORD_OVERLAP && !empty($queryKeywords)) {
                $flags[] = [
                    'doc_id'    => $doc->id ?? null,
                    'doc_title' => $doc->title ?? '',
                    'type'      => 'keyword_mismatch',
                    'severity'  => 'high',
                    'detail'    => sprintf(
                        'Similarity %.3f but keyword overlap only %.2f (below %.2f threshold)',
                        $similarity,
                        $overlap,
                        self::MIN_KEYWORD_OVERLAP
                    ),
                ];
            }
        }

        // Check 3: Near-duplicate pairs
        $dupPairs = $this->detectNearDuplicates($results);
        foreach ($dupPairs as [$i, $j, $jaccard]) {
            $docI = $results[$i]['document'];
            $docJ = $results[$j]['document'];
            $flags[] = [
                'doc_id'    => $docI->id ?? null,
                'doc_title' => $docI->title ?? '',
                'type'      => 'near_duplicate',
                'severity'  => 'medium',
                'detail'    => sprintf(
                    'Doc #%s and Doc #%s share %.0f%% keyword overlap — possible amplification',
                    $docI->id ?? '?',
                    $docJ->id ?? '?',
                    $jaccard * 100
                ),
            ];
        }

        // Check 4: Web pseudo-docs (low credibility baseline)
        foreach ($results as $result) {
            $doc = $result['document'];
            if (($doc->id ?? 1) === 0) {
                $flags[] = [
                    'doc_id'    => 0,
                    'doc_title' => $doc->title ?? 'Web result',
                    'type'      => 'unverified_web_source',
                    'severity'  => 'low',
                    'detail'    => 'Web-fetched document with no established provenance',
                ];
            }
        }

        $riskScore    = $this->computeRiskScore($flags);
        $flaggedCount = count($flags);
        $safe         = $riskScore < 0.50;

        if ($flaggedCount > 0) {
            Log::info('RAGSecurityService: anomalies detected', [
                'query'          => substr($query, 0, 80),
                'flagged_count'  => $flaggedCount,
                'risk_score'     => $riskScore,
                'safe'           => $safe,
                'flag_types'     => array_unique(array_column($flags, 'type')),
            ]);
        }

        return [
            'safe'          => $safe,
            'risk_score'    => $riskScore,
            'flagged_count' => $flaggedCount,
            'flags'         => $flags,
            'doc_count'     => count($results),
        ];
    }

    // =========================================================================
    // Statistical anomaly detection (pure — unit-testable)
    // =========================================================================

    /**
     * Return the array indices where the similarity score is a Z-score outlier.
     * Requires at least MIN_DOCS_FOR_STATS values.
     *
     * @param  float[] $similarities
     * @return int[]   Indices of outlier elements
     */
    public function detectSimilarityOutliers(array $similarities): array
    {
        $n = count($similarities);
        if ($n < self::MIN_DOCS_FOR_STATS) {
            return [];
        }

        $mean  = array_sum($similarities) / $n;
        $variance = array_sum(array_map(fn($s) => ($s - $mean) ** 2, $similarities)) / $n;
        $stdDev   = $variance > 0 ? sqrt($variance) : 0.0;

        if ($stdDev < 0.001) {
            return []; // All similarities nearly equal — no outliers
        }

        $outliers = [];
        foreach ($similarities as $idx => $s) {
            if (($s - $mean) / $stdDev >= self::OUTLIER_ZSCORE) {
                $outliers[] = $idx;
            }
        }

        return $outliers;
    }

    /**
     * Find near-duplicate document pairs by Jaccard similarity of keyword sets.
     * Returns array of [$i, $j, $jaccard] triples for pairs above the threshold.
     *
     * @param  array $results  RAGService result array
     * @return array           Array of [$i, $j, float] near-duplicate triples
     */
    public function detectNearDuplicates(array $results): array
    {
        $n    = count($results);
        $sets = [];
        $dups = [];

        // Pre-extract keyword sets
        for ($i = 0; $i < $n; $i++) {
            $content = $results[$i]['document']->content ?? '';
            $words   = count(preg_split('/\s+/', trim($content)));
            $sets[$i] = $words >= self::MIN_DOC_WORDS
                ? $this->extractKeywords($content)
                : [];
        }

        // O(n²) comparison — acceptable for small n (typically ≤ 10 results)
        for ($i = 0; $i < $n - 1; $i++) {
            if (empty($sets[$i])) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (empty($sets[$j])) {
                    continue;
                }
                $jaccard = $this->jaccardSimilarity($sets[$i], $sets[$j]);
                if ($jaccard >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $dups[] = [$i, $j, $jaccard];
                }
            }
        }

        return $dups;
    }

    // =========================================================================
    // Text utilities (pure — unit-testable)
    // =========================================================================

    /**
     * Extract a set of meaningful keywords from text.
     * Lowercases, removes punctuation, filters stop words and short tokens.
     *
     * @return string[]
     */
    public function extractKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $words = preg_split('/\s+/', trim($text));

        $keywords = array_filter($words, function (string $w): bool {
            return mb_strlen($w) >= 3 && !in_array($w, self::STOP_WORDS, true);
        });

        return array_values(array_unique($keywords));
    }

    /**
     * Compute the Jaccard similarity between two keyword sets.
     * Returns 0.0 if either set is empty.
     */
    public function jaccardSimilarity(array $setA, array $setB): float
    {
        if (empty($setA) || empty($setB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($setA, $setB));
        $union        = count(array_unique(array_merge($setA, $setB)));

        return $union > 0 ? round($intersection / $union, 4) : 0.0;
    }

    // =========================================================================
    // Risk scoring (pure — unit-testable)
    // =========================================================================

    /**
     * Compute an overall risk score from a list of flags.
     * Score = max severity weight in the flag set (not additive — single worst signal).
     * Returns 0.0 if no flags.
     */
    public function computeRiskScore(array $flags): float
    {
        if (empty($flags)) {
            return 0.0;
        }

        $maxWeight = 0.0;
        foreach ($flags as $flag) {
            $severity = $flag['severity'] ?? 'low';
            $weight   = self::SEVERITY_WEIGHTS[$severity] ?? self::SEVERITY_WEIGHTS['low'];
            if ($weight > $maxWeight) {
                $maxWeight = $weight;
            }
        }

        return $maxWeight;
    }
}

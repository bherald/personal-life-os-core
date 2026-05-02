<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * MMR (Maximal Marginal Relevance) Diversity Service
 *
 * Reranks search results to balance relevance with diversity,
 * avoiding redundant results in RAG retrieval.
 *
 * MMR formula: lambda * sim(q,d) - (1-lambda) * max(sim(d,d'))
 */
class MMRDiversityService
{
    public function rerank(array $results, string $query, float $lambda = 0.7, int $topK = 5): array
    {
        if (count($results) <= 1) {
            return $results;
        }

        return $this->diverseTopK($results, $topK, $lambda);
    }

    public function computeDocSimilarity(array $doc1Embedding, array $doc2Embedding): float
    {
        if (empty($doc1Embedding) || empty($doc2Embedding)) {
            return 0.0;
        }

        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;

        $len = min(count($doc1Embedding), count($doc2Embedding));
        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $doc1Embedding[$i] * $doc2Embedding[$i];
            $norm1 += $doc1Embedding[$i] * $doc1Embedding[$i];
            $norm2 += $doc2Embedding[$i] * $doc2Embedding[$i];
        }

        $denominator = sqrt($norm1) * sqrt($norm2);
        return $denominator > 0 ? $dotProduct / $denominator : 0.0;
    }

    public function diverseTopK(array $results, int $k, float $lambda = 0.7): array
    {
        if (empty($results)) return [];

        // Cap input to 50 candidates — beyond that, MMR's O(k*n*s) cost
        // is not worth it and risks timeouts. Results are pre-sorted by relevance,
        // so trimming the tail loses only low-relevance items.
        if (count($results) > 50) {
            $results = array_slice($results, 0, 50);
        }

        $k = min($k, count($results));
        $selected = [];
        $remaining = $results;

        // Select first item (highest relevance)
        $selected[] = array_shift($remaining);

        while (count($selected) < $k && !empty($remaining)) {
            $bestScore = -INF;
            $bestIdx = 0;

            foreach ($remaining as $idx => $candidate) {
                $relevance = $candidate['similarity'] ?? $candidate['rerank_score'] ?? 0;

                // Find max similarity to any already-selected document
                $maxSim = 0;
                foreach ($selected as $sel) {
                    $sim = $this->computeResultSimilarity($candidate, $sel);
                    $maxSim = max($maxSim, $sim);
                }

                // MMR score
                $mmrScore = ($lambda * $relevance) - ((1 - $lambda) * $maxSim);

                if ($mmrScore > $bestScore) {
                    $bestScore = $mmrScore;
                    $bestIdx = $idx;
                }
            }

            $selected[] = $remaining[$bestIdx];
            $selected[count($selected) - 1]['mmr_score'] = $bestScore;
            unset($remaining[$bestIdx]);
            $remaining = array_values($remaining);
        }

        return $selected;
    }

    private function computeResultSimilarity(array $doc1, array $doc2): float
    {
        // If we have embeddings, use cosine similarity (fast path)
        $emb1 = $doc1['embedding'] ?? null;
        $emb2 = $doc2['embedding'] ?? null;

        if ($emb1 && $emb2 && is_array($emb1) && is_array($emb2)) {
            return $this->computeDocSimilarity($emb1, $emb2);
        }

        // Fallback: text-based Jaccard similarity on first 2000 chars only.
        // Full-document tokenization caused 60s timeouts on large content
        // when called O(k*n*s) times in the diverseTopK loop.
        $text1 = $doc1['document']->content ?? $doc1['content'] ?? '';
        $text2 = $doc2['document']->content ?? $doc2['content'] ?? '';

        $text1 = strtolower(mb_substr($text1, 0, 2000));
        $text2 = strtolower(mb_substr($text2, 0, 2000));

        $words1 = array_unique(str_word_count($text1, 1));
        $words2 = array_unique(str_word_count($text2, 1));

        if (empty($words1) || empty($words2)) {
            return 0.0;
        }

        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    public function getOptimalLambda(string $queryType): float
    {
        return match ($queryType) {
            'factual' => 0.8,       // Prioritize relevance
            'exploratory' => 0.5,   // Balance relevance and diversity
            'comparative' => 0.3,   // Prioritize diversity
            'temporal' => 0.7,      // Slight relevance priority
            default => 0.7,
        };
    }
}

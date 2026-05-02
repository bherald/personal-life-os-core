<?php

namespace App\Support;

/**
 * Shared vector math utilities for embedding operations.
 *
 * Consolidates duplicate cosineSimilarity implementations from:
 * RAGService, SemanticChunkerService, SemanticCache, RAGEvaluationService,
 * LLMKnowledgeVettingService
 */
class VectorMath
{
    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array $a First vector
     * @param array $b Second vector
     * @return float Similarity score between -1.0 and 1.0 (0.0 if vectors are incompatible)
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA == 0.0 || $magnitudeB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }
}

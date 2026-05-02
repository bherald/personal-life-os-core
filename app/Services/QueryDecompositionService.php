<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RAG-5 — Query Decomposition
 *
 * Breaks complex queries into focused sub-queries for improved recall.
 * Each sub-query is searched independently, results merged via RRF deduplication.
 *
 * Only triggered for multi_step queries (via QueryRouterService).
 * Simple queries bypass decomposition entirely.
 *
 * Performance: One LLM call (~2-5s) to decompose. Cache 1h on query hash.
 * Fallback: Returns original query as single sub-query if LLM fails.
 */
class QueryDecompositionService
{
    use RecursionAware;

    private AIService $aiService;

    private const CACHE_TTL = 3600; // 1 hour
    private const MAX_SUB_QUERIES = 4;
    private const MIN_QUERY_WORDS = 4; // Don't decompose short queries

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Decompose a complex query into focused sub-queries.
     *
     * @param string $query The original complex query
     * @return array ['sub_queries' => string[], 'original' => string, 'decomposed' => bool]
     */
    public function decompose(string $query): array
    {
        // RLM: Try recursive query decomposition
        $rlm = $this->tryRecursive('query_decomposition', 'quality_gate_retry', ['query' => $query], function ($ctx) {
            return $this->decompose($ctx['query']);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $wordCount = str_word_count($query);

        // Too short to decompose meaningfully
        if ($wordCount < self::MIN_QUERY_WORDS) {
            return $this->passthrough($query);
        }

        // Check cache
        $cacheKey = 'query_decomp:' . md5($query);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $subQueries = $this->llmDecompose($query);

            // Validate: must have 2+ sub-queries, each non-empty
            $subQueries = array_filter($subQueries, fn($q) => strlen(trim($q)) >= 5);
            $subQueries = array_values($subQueries);

            if (count($subQueries) < 2) {
                return $this->passthrough($query);
            }

            // Cap at max
            $subQueries = array_slice($subQueries, 0, self::MAX_SUB_QUERIES);

            $result = [
                'sub_queries' => $subQueries,
                'original' => $query,
                'decomposed' => true,
                'count' => count($subQueries),
            ];

            Cache::put($cacheKey, $result, self::CACHE_TTL);

            Log::info('QueryDecomposition: decomposed', [
                'original' => substr($query, 0, 100),
                'sub_queries' => $subQueries,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::warning('QueryDecomposition: LLM failed, using original', [
                'error' => $e->getMessage(),
            ]);
            return $this->passthrough($query);
        }
    }

    /**
     * Use LLM to decompose a complex query into sub-queries.
     *
     * @return string[] Array of sub-query strings
     */
    private function llmDecompose(string $query): array
    {
        $prompt = "Break this complex search query into 2-4 focused sub-queries for a knowledge base search. "
            . "Each sub-query should target a different aspect of the original question. "
            . "Return ONLY a JSON array of strings, nothing else.\n\n"
            . "Query: {$query}\n\n"
            . "Example output: [\"sub-query 1\", \"sub-query 2\", \"sub-query 3\"]";

        $response = $this->aiService->process($prompt, [
            'temperature' => 0.2,
            'max_tokens' => 300,
            'model_role' => 'fast',
            'use_cache' => false,
            'skip_if_busy' => true,
        ]);

        $content = $response['response'] ?? '';

        if (empty($content)) {
            return [];
        }

        // Extract JSON array from response (may have markdown fences)
        if (preg_match('/\[.*\]/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return array_map('trim', $decoded);
            }
        }

        return [];
    }

    /**
     * Return the original query without decomposition.
     */
    private function passthrough(string $query): array
    {
        return [
            'sub_queries' => [$query],
            'original' => $query,
            'decomposed' => false,
            'count' => 1,
        ];
    }
}

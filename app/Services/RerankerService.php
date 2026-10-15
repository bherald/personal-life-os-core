<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RAG Reranker Service
 *
 * Provides cross-encoder reranking for improved search accuracy.
 * Uses hybrid approach: fast keyword-based for normal queries,
 * AI-powered for high-value deep search operations.
 *
 * Expected accuracy improvement: 15-30% for relevant result ranking.
 */
class RerankerService
{
    private AIService $aiService;

    // Reranking thresholds
    private const MIN_DOCS_FOR_RERANK = 3;

    private const MAX_DOCS_FOR_AI_RERANK = 20;

    private const AI_RERANK_CACHE_TTL = 3600; // 1 hour

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Rerank search results using appropriate strategy
     *
     * @param  string  $query  Original search query
     * @param  array  $results  Search results with ['document' => stdClass, 'similarity' => float]
     * @param  string  $mode  'fast' (keyword) or 'ai' (cross-encoder)
     * @return array Reranked results with optional 'rerank_score' key
     */
    public function rerank(string $query, array $results, string $mode = 'fast'): array
    {
        if ($mode === 'cross_encoder') {
            return $this->dedicatedRerank($query, $results);
        }

        if (count($results) < self::MIN_DOCS_FOR_RERANK) {
            return $results;
        }

        return match ($mode) {
            'ai' => $this->aiRerank($query, $results),
            default => $this->fastRerank($query, $results),
        };
    }

    /** Explicit dedicated mode retains every source byte; unavailable admission preserves input order. */
    private function dedicatedRerank(string $query, array $results): array
    {
        $documents = [];
        foreach (array_values($results) as $index => $result) {
            $document = $result['document'] ?? null;
            $content = is_object($document) ? ($document->content ?? null) : null;
            if (! is_string($content) || trim($content) === '') {
                return $results;
            }
            $documents[] = ['id' => (string) $index, 'text' => $content];
        }
        if ($documents === []) {
            return $results;
        }
        $ranking = app(RerankerSidecarService::class)->rerankControlled($query, $documents);
        if (($ranking['status'] ?? null) !== 'pass') {
            return $results;
        }
        $scores = array_column($ranking['results'], 'score', 'id');
        $ranked = array_values($results);
        foreach ($ranked as $index => &$result) {
            $result['rerank_score'] = $scores[(string) $index];
            $result['rerank_method'] = 'cross_encoder';
        }
        unset($result);
        usort($ranked, fn ($left, $right) => $right['rerank_score'] <=> $left['rerank_score']);

        return $ranked;
    }

    /**
     * Fast keyword-based reranking
     * Uses: query term overlap, position weighting, title boost
     */
    private function fastRerank(string $query, array $results): array
    {
        $queryTerms = $this->tokenize($query);
        if (empty($queryTerms)) {
            return $results;
        }

        foreach ($results as &$result) {
            $doc = $result['document'];
            $title = strtolower($doc->title ?? '');
            $content = strtolower(substr($doc->content ?? '', 0, 2000));

            // Score components
            $titleScore = $this->calculateTermOverlap($queryTerms, $title) * 3.0; // 3x title boost
            $contentScore = $this->calculateTermOverlap($queryTerms, $content);
            $positionScore = $this->calculatePositionScore($queryTerms, $content);

            // Combine with original similarity (weighted blend)
            $originalSim = $result['similarity'];
            $rerankScore = ($originalSim * 0.6) + ($titleScore * 0.2) + ($contentScore * 0.1) + ($positionScore * 0.1);

            $result['rerank_score'] = $rerankScore;
            $result['rerank_method'] = 'fast';
        }

        // Sort by rerank score descending
        usort($results, fn ($a, $b) => $b['rerank_score'] <=> $a['rerank_score']);

        Log::debug('RerankerService: Fast rerank completed', [
            'query' => substr($query, 0, 50),
            'docs_reranked' => count($results),
        ]);

        return $results;
    }

    /**
     * AI-powered cross-encoder reranking
     * Prefer optional typed judgments; retain the general and deterministic scoring routes.
     */
    private function aiRerank(string $query, array $results): array
    {
        // Limit docs to prevent excessive AI calls
        $docsToRerank = array_slice($results, 0, self::MAX_DOCS_FOR_AI_RERANK);
        $remaining = array_slice($results, self::MAX_DOCS_FOR_AI_RERANK);

        // Reuse an existing fallback judgment before paying for another optional call.
        $cacheKey = 'rerank:v2:'.hash('sha256', serialize([$query, $docsToRerank]));
        try {
            $scores = Cache::get($cacheKey);
        } catch (Throwable) {
            $scores = null;
        }
        $method = 'ai';
        if (! $this->validScores($scores, count($docsToRerank))) {
            $scores = $this->jevScores($query, $docsToRerank);
            if ($scores !== null) {
                $method = 'jev';
            } else {
                $scores = $this->batchScoreDocuments($query, $docsToRerank);
                if ($scores === null) {
                    return $this->fastRerank($query, $results);
                }
                try {
                    Cache::put($cacheKey, $scores, self::AI_RERANK_CACHE_TTL);
                } catch (Throwable) {
                    // Return the result already computed; do not repeat inference.
                }
            }
        }

        // Apply scores
        foreach ($docsToRerank as $i => &$result) {
            $aiScore = $scores[$i] ?? 5.0;
            $originalSim = $result['similarity'];

            // Blend: 40% original, 60% AI score (normalized to 0-1)
            $result['rerank_score'] = ($originalSim * 0.4) + (($aiScore / 10.0) * 0.6);
            $result['ai_relevance_score'] = $aiScore;
            $result['rerank_method'] = $method;
        }

        // Sort reranked docs
        usort($docsToRerank, fn ($a, $b) => $b['rerank_score'] <=> $a['rerank_score']);

        // Append remaining docs (below threshold)
        $docsToRerank = array_merge($docsToRerank, $remaining);

        Log::info('RerankerService: AI rerank completed', [
            'method' => $method,
            'docs_reranked' => count($docsToRerank) - count($remaining),
        ]);

        return $docsToRerank;
    }

    /** Optional typed scoring; discard partial results and keep the complete fallback input. */
    private function jevScores(string $query, array $results): ?array
    {
        $reason = 'policy_unavailable';
        $calls = 0;
        try {
            $policy = app(SystemConfigService::class)->get(TypeSafeJevDecisionService::POLICY_KEY, []);
            $policy = is_string($policy) ? json_decode($policy, true, flags: JSON_THROW_ON_ERROR) : $policy;
            $task = $policy['tasks']['evidence_relevance'] ?? [];
            $reason = 'policy_disabled';
            $settings = $task['reranking'] ?? [];
            if (($policy['enabled'] ?? false) !== true || ($task['enabled'] ?? false) !== true
                || ($settings['enabled'] ?? false) !== true) {
                return null;
            }
            $reason = 'private_input_not_allowed';
            if (! in_array('private_internal', $policy['allowed_data_classifications'] ?? [], true)
                || ! in_array('private_internal', $task['allowed_data_classifications'] ?? [], true)) {
                return null;
            }
            $reason = 'tool_unavailable';
            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->first();
            if ($tool === null || ! $tool->enabled || $tool->requires_confirmation || ! $tool->allows_private_data
                || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate') {
                return null;
            }
            $reason = 'reranking_policy_invalid';
            $batchSize = $settings['batch_size'] ?? null;
            $maxBatches = $settings['max_batches'] ?? null;
            $minimumConfidence = $settings['minimum_confidence'] ?? null;
            $cacheTtl = $settings['cache_ttl_seconds'] ?? null;
            $criteria = $settings['criteria'] ?? null;
            $instructions = $settings['instructions'] ?? null;
            if (! is_int($batchSize) || $batchSize < 1 || $batchSize > 8
                || ! is_int($maxBatches) || $maxBatches < 1 || $maxBatches > 3
                || ! is_int($cacheTtl) || $cacheTtl < 0 || $cacheTtl > 3600
                || ! is_numeric($minimumConfidence) || $minimumConfidence < 0 || $minimumConfidence > 1
                || ! is_array($criteria) || ! array_is_list($criteria) || count($criteria) < 2 || count($criteria) > 10
                || ! is_string($instructions) || trim($instructions) === '') {
                return null;
            }
            $batches = array_chunk($results, $batchSize);
            $reason = 'batch_budget_exceeded';
            if (count($batches) > $maxBatches) {
                return null;
            }
            // Cache only complete judgments and bind them to exact evidence and current policy.
            $cacheKey = 'rerank:jev:v2:'.hash('sha256', serialize([$query, $results, $policy]));
            if ($cacheTtl > 0) {
                try {
                    $cached = Cache::get($cacheKey);
                    if ($this->validScores($cached, count($results))) {
                        $reason = 'completed_cached';

                        return $cached;
                    }
                } catch (Throwable) {
                    // A cache outage must not stop scoring.
                }
            }
            $scores = [];
            foreach ($batches as $batch) {
                $documents = [];
                $questions = [];
                foreach ($batch as $index => $result) {
                    $document = $result['document'];
                    // Keep exact evidence; an oversized request falls back without truncation.
                    $documents[] = ['id' => $document->id ?? null, 'title' => $document->title ?? '',
                        'content' => $document->content ?? ''];
                    $questions['document_'.$index] = ['type' => 'score',
                        'instructions' => $instructions.' Evaluate only `documents['.$index.']` (zero-based array index '.$index.') against `query`. Do not use other candidates as evidence for this document.',
                        'criteria' => $criteria];
                }
                $reason = 'service_unavailable';
                $calls++;
                $judgment = app(TypeSafeJevDecisionService::class)->evaluate('evidence_relevance', 'private_internal',
                    ['query' => $query, 'documents' => $documents], $questions);
                $reason = 'judgment_skipped';
                if (($judgment['status'] ?? null) !== 'completed' || ($judgment['success'] ?? false) !== true) {
                    return null;
                }
                $reason = 'invalid_or_uncertain_judgment';
                if (! is_array($judgment['answers'] ?? null)
                    || array_diff_key($questions, $judgment['answers']) !== []
                    || array_diff_key($judgment['answers'], $questions) !== []) {
                    return null;
                }
                foreach ($questions as $id => $question) {
                    $answer = $judgment['answers'][$id];
                    $score = $answer['score'] ?? null;
                    $confidence = $answer['confidence'] ?? null;
                    if (($answer['type'] ?? null) !== 'score'
                        || ! (is_int($score) || is_float($score)) || ! is_finite((float) $score)
                        || $score < 0 || $score > count($criteria) - 1
                        || ! (is_int($confidence) || is_float($confidence)) || ! is_finite((float) $confidence)
                        || $confidence < $minimumConfidence || $confidence > 1) {
                        return null;
                    }
                    $scores[] = 10.0 * $score / (count($criteria) - 1);
                }
            }
            $reason = 'completed';
            if ($cacheTtl > 0) {
                try {
                    Cache::put($cacheKey, $scores, $cacheTtl);
                } catch (Throwable) {
                    // Use the completed scores without another provider request.
                }
            }

            return $scores;
        } catch (Throwable) {
            return null;
        } finally {
            try {
                Log::info('Jev reranking route', ['outcome' => $reason, 'batches' => $calls,
                    'candidate_count' => count($results), 'general_scorer_bypassed' => in_array($reason, ['completed', 'completed_cached'], true)]);
            } catch (Throwable) {
                // Observability must not block a search or its fallback.
            }
        }
    }

    private function validScores(mixed $scores, int $count): bool
    {
        return is_array($scores) && array_is_list($scores) && count($scores) === $count
            && count(array_filter($scores, fn ($value) => (is_int($value) || is_float($value))
                && is_finite((float) $value) && $value >= 0 && $value <= 10)) === $count;
    }

    /**
     * Batch score documents for relevance
     */
    private function batchScoreDocuments(string $query, array $results): ?array
    {
        $prompt = "Score each document's relevance to the query on a scale of 0-10.\n";
        $prompt .= "Query: \"{$query}\"\n\n";
        $prompt .= "Documents:\n";

        foreach ($results as $i => $result) {
            $doc = $result['document'];
            $title = $doc->title ?? 'Untitled';
            $snippet = substr($doc->content ?? '', 0, 500);
            $prompt .= "[{$i}] Title: {$title}\nContent: {$snippet}\n\n";
        }

        $prompt .= "Return ONLY a JSON array of scores: [score0, score1, ...]\n";
        $prompt .= "Example: [8.5, 3.0, 7.2]\n";

        try {
            $response = $this->aiService->process($prompt, [
                'system_prompt' => 'You are a search relevance scorer. Output only valid JSON array of numbers.',
                'temperature' => 0.1,
                'max_tokens' => 200,
            ]);

            if ($response['success']) {
                // Extract JSON array from response
                $text = $response['response'];
                if (preg_match('/\[[\d.,\s]+\]/', $text, $matches)) {
                    $scores = json_decode($matches[0], true);
                    if ($this->validScores($scores, count($results))) {
                        return $scores;
                    }
                }
            }
        } catch (Throwable) {
            Log::warning('AI rerank scoring failed');
        }

        // No synthetic model scores: the caller uses the existing deterministic ranker.
        return null;
    }

    /**
     * Tokenize query into searchable terms
     */
    private function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Remove stopwords
        $stopwords = [
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'must', 'shall', 'can', 'of', 'in', 'to',
            'for', 'with', 'on', 'at', 'by', 'from', 'as', 'into', 'through',
            'and', 'or', 'but', 'if', 'then', 'else', 'when', 'where', 'why',
            'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other',
            'some', 'such', 'no', 'not', 'only', 'own', 'same', 'so', 'than',
            'too', 'very', 'just', 'also', 'now', 'my', 'me', 'i', 'you', 'your',
            'what', 'which', 'this', 'that', 'these', 'those', 'it', 'its',
        ];

        return array_values(array_diff($words, $stopwords));
    }

    /**
     * Calculate term overlap between query terms and text
     */
    private function calculateTermOverlap(array $queryTerms, string $text): float
    {
        if (empty($queryTerms)) {
            return 0.0;
        }

        $matches = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($text, $term)) {
                $matches++;
            }
        }

        return $matches / count($queryTerms);
    }

    /**
     * Calculate position score based on where terms appear
     */
    private function calculatePositionScore(array $queryTerms, string $content): float
    {
        if (empty($queryTerms) || empty($content)) {
            return 0.0;
        }

        // Score based on where terms appear (earlier = better)
        $totalScore = 0;
        $contentLength = strlen($content);

        foreach ($queryTerms as $term) {
            $pos = strpos($content, $term);
            if ($pos !== false) {
                // Score inversely proportional to position
                $totalScore += 1 - ($pos / $contentLength);
            }
        }

        return $totalScore / count($queryTerms);
    }
}

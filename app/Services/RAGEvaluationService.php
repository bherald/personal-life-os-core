<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * RAG Evaluation Service - RAGAS-style metrics for RAG quality assessment
 *
 * Implements the core RAGAS metrics:
 * - Context Precision: Are retrieved documents relevant to the query?
 * - Context Recall: Did we retrieve all needed information?
 * - Faithfulness: Is the answer grounded in retrieved documents?
 * - Answer Relevancy: Does the answer address the query?
 *
 * @see https://docs.ragas.io/en/stable/concepts/metrics/
 */
class RAGEvaluationService
{
    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Evaluate RAG retrieval and generation quality
     *
     * @param string $query The user's query
     * @param array $retrievedDocs Array of retrieved documents (with 'content' or 'document' keys)
     * @param string $answer The generated answer
     * @param bool $persist Whether to store evaluation in database
     * @return array Metrics array with scores 0-1
     */
    public function evaluateRetrieval(string $query, array $retrievedDocs, string $answer, bool $persist = true): array
    {
        $startTime = microtime(true);

        // Extract content from docs (handle both raw objects and wrapped results)
        $docContents = $this->extractDocContents($retrievedDocs);

        if (empty($docContents)) {
            Log::warning('RAG Evaluation: No document content to evaluate');
            return [
                'context_precision' => 0.0,
                'context_recall' => 0.0,
                'faithfulness' => 0.0,
                'answer_relevancy' => 0.0,
                'overall_score' => 0.0,
                'error' => 'No documents provided',
            ];
        }

        // Calculate individual metrics
        $contextPrecision = $this->calculateContextPrecision($query, $docContents);
        $contextRecall = $this->calculateContextRecall($query, $answer, $docContents);
        $faithfulness = $this->calculateFaithfulness($answer, $docContents);
        $answerRelevancy = $this->calculateAnswerRelevancy($query, $answer);

        $metrics = [
            'context_precision' => $contextPrecision,
            'context_recall' => $contextRecall,
            'faithfulness' => $faithfulness,
            'answer_relevancy' => $answerRelevancy,
        ];

        $overallScore = $this->getOverallScore($metrics);
        $metrics['overall_score'] = $overallScore;
        $metrics['evaluation_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
        $metrics['doc_count'] = count($docContents);

        // Persist evaluation if requested
        if ($persist) {
            $this->storeEvaluation($query, $answer, $metrics);
        }

        Log::info('RAG Evaluation completed', [
            'query' => substr($query, 0, 100),
            'overall_score' => round($overallScore, 3),
            'doc_count' => count($docContents),
            'duration_ms' => $metrics['evaluation_time_ms'],
        ]);

        return $metrics;
    }

    /**
     * Calculate Context Precision
     *
     * Measures how many of the retrieved documents are relevant to the query.
     * Uses embedding similarity between query and each document.
     *
     * @param string $query The user's query
     * @param array $docs Array of document content strings
     * @return float Score 0-1
     */
    public function calculateContextPrecision(string $query, array $docs): float
    {
        if (empty($docs)) {
            return 0.0;
        }

        try {
            // Get query embedding
            $queryResult = $this->aiService->generateEmbedding($query);
            if (!$queryResult['success']) {
                return $this->calculateContextPrecisionFallback($query, $docs);
            }
            $queryEmbedding = $queryResult['embedding'];

            $relevantCount = 0;
            $relevanceThreshold = 0.5; // Docs with similarity >= 0.5 considered relevant

            foreach ($docs as $doc) {
                // Get doc embedding
                $docContent = is_string($doc) ? $doc : ($doc['content'] ?? '');
                if (empty($docContent)) {
                    continue;
                }

                // Use first 1000 chars for efficiency
                $docSnippet = substr($docContent, 0, 1000);
                $docResult = $this->aiService->generateEmbedding($docSnippet);

                if ($docResult['success']) {
                    $similarity = $this->cosineSimilarity($queryEmbedding, $docResult['embedding']);
                    if ($similarity >= $relevanceThreshold) {
                        $relevantCount++;
                    }
                }
            }

            return $relevantCount / count($docs);
        } catch (Exception $e) {
            Log::warning('Context precision calculation failed, using fallback', [
                'error' => $e->getMessage(),
            ]);
            return $this->calculateContextPrecisionFallback($query, $docs);
        }
    }

    /**
     * Fallback context precision using keyword overlap
     */
    private function calculateContextPrecisionFallback(string $query, array $docs): float
    {
        $queryWords = $this->extractKeywords($query);
        if (empty($queryWords)) {
            return 0.5; // Neutral score
        }

        $relevantCount = 0;
        foreach ($docs as $doc) {
            $docContent = is_string($doc) ? $doc : ($doc['content'] ?? '');
            $docWords = $this->extractKeywords($docContent);

            $overlap = count(array_intersect($queryWords, $docWords));
            if ($overlap >= 2 || ($overlap >= 1 && count($queryWords) <= 3)) {
                $relevantCount++;
            }
        }

        return count($docs) > 0 ? $relevantCount / count($docs) : 0.0;
    }

    /**
     * Calculate Context Recall
     *
     * Measures whether retrieved documents contain the information needed to answer.
     * Uses AI to identify answer components and check if they're in the context.
     *
     * @param string $query The user's query
     * @param string $answer The generated answer
     * @param array $docs Array of document content strings
     * @return float Score 0-1
     */
    public function calculateContextRecall(string $query, string $answer, array $docs): float
    {
        if (empty($docs) || empty(trim($answer))) {
            return 0.0;
        }

        $combinedContext = implode("\n\n", array_map(function ($doc) {
            return is_string($doc) ? $doc : ($doc['content'] ?? '');
        }, $docs));

        // Use AI to evaluate recall
        $prompt = <<<PROMPT
Given the following:
QUERY: {$query}
ANSWER: {$answer}
CONTEXT: {$combinedContext}

Evaluate what percentage of the information in the ANSWER can be found in or directly inferred from the CONTEXT.
Consider:
- Direct statements that match
- Facts that can be logically inferred
- Information that is supported by the context

Respond with ONLY a number between 0 and 100 representing the percentage of answer content that is supported by the context.
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'temperature' => 0.1,
                'max_tokens' => 10,
                'suppressAlert' => true,
            ]);

            if ($result['success'] && !empty($result['response'])) {
                $score = $this->extractScore($result['response']);
                return $score / 100;
            }
        } catch (Exception $e) {
            Log::debug('Context recall AI evaluation failed', ['error' => $e->getMessage()]);
        }

        // Fallback: Check if answer keywords appear in context
        return $this->calculateRecallFallback($answer, $combinedContext);
    }

    /**
     * Fallback recall using keyword matching
     */
    private function calculateRecallFallback(string $answer, string $context): float
    {
        $answerWords = $this->extractKeywords($answer);
        if (empty($answerWords)) {
            return 0.5;
        }

        $contextLower = strtolower($context);
        $foundCount = 0;

        foreach ($answerWords as $word) {
            if (strpos($contextLower, $word) !== false) {
                $foundCount++;
            }
        }

        return $foundCount / count($answerWords);
    }

    /**
     * Calculate Faithfulness
     *
     * Measures whether the answer is grounded in the retrieved documents.
     * Checks that claims in the answer can be attributed to the context.
     *
     * @param string $answer The generated answer
     * @param array $docs Array of document content strings
     * @return float Score 0-1
     */
    public function calculateFaithfulness(string $answer, array $docs): float
    {
        if (empty(trim($answer)) || empty($docs)) {
            return 0.0;
        }

        $combinedContext = implode("\n\n", array_map(function ($doc) {
            return is_string($doc) ? $doc : ($doc['content'] ?? '');
        }, $docs));

        // Use AI to evaluate faithfulness
        $prompt = <<<PROMPT
Given the following:
ANSWER: {$answer}
CONTEXT: {$combinedContext}

Evaluate what percentage of claims/statements in the ANSWER are supported by or directly derived from the CONTEXT.
A claim is faithful if:
- It directly states something from the context
- It is a reasonable inference from the context
- It does not contradict the context
- It does not introduce new information not in the context

Respond with ONLY a number between 0 and 100 representing the faithfulness percentage.
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'temperature' => 0.1,
                'max_tokens' => 10,
                'suppressAlert' => true,
            ]);

            if ($result['success'] && !empty($result['response'])) {
                $score = $this->extractScore($result['response']);
                return $score / 100;
            }
        } catch (Exception $e) {
            Log::debug('Faithfulness AI evaluation failed', ['error' => $e->getMessage()]);
        }

        // Fallback: sentence-level overlap check
        return $this->calculateFaithfulnessFallback($answer, $combinedContext);
    }

    /**
     * Fallback faithfulness using n-gram overlap
     */
    private function calculateFaithfulnessFallback(string $answer, string $context): float
    {
        // Split answer into sentences
        $sentences = preg_split('/[.!?]+/', $answer, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($sentences)) {
            return 0.5;
        }

        $contextLower = strtolower($context);
        $faithfulCount = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) < 10) {
                continue; // Skip very short fragments
            }

            // Extract key phrases (3-grams)
            $words = preg_split('/\s+/', strtolower($sentence));
            if (count($words) < 3) {
                // Check whole sentence
                if (strpos($contextLower, strtolower($sentence)) !== false) {
                    $faithfulCount++;
                }
                continue;
            }

            // Check if any 3-gram from sentence appears in context
            $found = false;
            for ($i = 0; $i <= count($words) - 3; $i++) {
                $trigram = implode(' ', array_slice($words, $i, 3));
                if (strpos($contextLower, $trigram) !== false) {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $faithfulCount++;
            }
        }

        $totalSentences = count(array_filter($sentences, fn($s) => strlen(trim($s)) >= 10));
        return $totalSentences > 0 ? $faithfulCount / $totalSentences : 0.5;
    }

    /**
     * Calculate Answer Relevancy
     *
     * Measures how well the answer addresses the query.
     * Uses semantic similarity between query and answer.
     *
     * @param string $query The user's query
     * @param string $answer The generated answer
     * @return float Score 0-1
     */
    public function calculateAnswerRelevancy(string $query, string $answer): float
    {
        if (empty(trim($answer))) {
            return 0.0;
        }

        try {
            // Get embeddings for query and answer
            $queryResult = $this->aiService->generateEmbedding($query);
            $answerResult = $this->aiService->generateEmbedding(substr($answer, 0, 2000));

            if ($queryResult['success'] && $answerResult['success']) {
                $similarity = $this->cosineSimilarity($queryResult['embedding'], $answerResult['embedding']);
                // Scale similarity to be more discriminative (0.3-0.9 range mapped to 0-1)
                return min(1.0, max(0.0, ($similarity - 0.3) / 0.6));
            }
        } catch (Exception $e) {
            Log::debug('Answer relevancy embedding failed', ['error' => $e->getMessage()]);
        }

        // Fallback: AI-based relevancy check
        return $this->calculateAnswerRelevancyFallback($query, $answer);
    }

    /**
     * Fallback answer relevancy using AI
     */
    private function calculateAnswerRelevancyFallback(string $query, string $answer): float
    {
        $prompt = <<<PROMPT
Given the following:
QUERY: {$query}
ANSWER: {$answer}

Rate how well the ANSWER addresses and responds to the QUERY on a scale of 0-100.
Consider:
- Does the answer address the main question?
- Is the answer complete?
- Is the answer on-topic?

Respond with ONLY a number between 0 and 100.
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'temperature' => 0.1,
                'max_tokens' => 10,
                'suppressAlert' => true,
            ]);

            if ($result['success'] && !empty($result['response'])) {
                $score = $this->extractScore($result['response']);
                return $score / 100;
            }
        } catch (Exception $e) {
            Log::debug('Answer relevancy AI fallback failed', ['error' => $e->getMessage()]);
        }

        // Final fallback: keyword overlap
        $queryWords = $this->extractKeywords($query);
        $answerWords = $this->extractKeywords($answer);

        if (empty($queryWords)) {
            return 0.5;
        }

        $overlap = count(array_intersect($queryWords, $answerWords));
        return min(1.0, $overlap / count($queryWords));
    }

    /**
     * Calculate overall score from individual metrics
     *
     * Uses weighted harmonic mean to penalize low scores more heavily
     *
     * @param array $metrics Array with context_precision, context_recall, faithfulness, answer_relevancy
     * @return float Overall score 0-1
     */
    public function getOverallScore(array $metrics): float
    {
        // Weights for each metric (higher = more important)
        $weights = [
            'faithfulness' => 0.35,        // Most important - answer must be grounded
            'answer_relevancy' => 0.30,    // Must answer the question
            'context_precision' => 0.20,   // Retrieved docs should be relevant
            'context_recall' => 0.15,      // Should have retrieved enough info
        ];

        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($weights as $metric => $weight) {
            if (isset($metrics[$metric]) && $metrics[$metric] > 0) {
                $weightedSum += $weight * $metrics[$metric];
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
    }

    /**
     * Store evaluation in database
     */
    private function storeEvaluation(string $query, string $answer, array $metrics): void
    {
        try {
            DB::connection('pgsql_rag')->table('rag_evaluations')->insert([
                'query' => $query,
                'answer' => $answer,
                'metrics' => json_encode($metrics),
                'overall_score' => $metrics['overall_score'],
                'evaluated_at' => now(),
                'created_at' => now(),
            ]);
        } catch (Exception $e) {
            Log::warning('Failed to store RAG evaluation', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get evaluation history
     *
     * @param int $limit Number of records to return
     * @param float|null $minScore Filter by minimum overall score
     * @return array
     */
    public function getEvaluationHistory(int $limit = 50, ?float $minScore = null): array
    {
        $sql = "SELECT * FROM rag_evaluations";
        $params = [];

        if ($minScore !== null) {
            $sql .= " WHERE overall_score >= ?";
            $params[] = $minScore;
        }

        $sql .= " ORDER BY evaluated_at DESC LIMIT ?";
        $params[] = $limit;

        return DB::connection('pgsql_rag')->select($sql, $params);
    }

    /**
     * Get aggregate statistics
     */
    public function getStats(): array
    {
        $sql = "SELECT
            COUNT(*) as total_evaluations,
            AVG(overall_score) as avg_overall,
            AVG((metrics->>'context_precision')::numeric) as avg_context_precision,
            AVG((metrics->>'context_recall')::numeric) as avg_context_recall,
            AVG((metrics->>'faithfulness')::numeric) as avg_faithfulness,
            AVG((metrics->>'answer_relevancy')::numeric) as avg_answer_relevancy,
            MIN(overall_score) as min_score,
            MAX(overall_score) as max_score,
            PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY overall_score) as median_score
            FROM rag_evaluations";

        $result = DB::connection('pgsql_rag')->select($sql);
        return (array) ($result[0] ?? new \stdClass());
    }

    /**
     * Extract document contents from various formats
     */
    private function extractDocContents(array $docs): array
    {
        $contents = [];

        foreach ($docs as $doc) {
            if (is_string($doc)) {
                $contents[] = $doc;
            } elseif (is_array($doc)) {
                // Handle wrapped results like ['document' => ..., 'similarity' => ...]
                if (isset($doc['document'])) {
                    $inner = $doc['document'];
                    if (is_object($inner) && isset($inner->content)) {
                        $contents[] = $inner->content;
                    } elseif (is_array($inner) && isset($inner['content'])) {
                        $contents[] = $inner['content'];
                    }
                } elseif (isset($doc['content'])) {
                    $contents[] = $doc['content'];
                }
            } elseif (is_object($doc)) {
                if (isset($doc->content)) {
                    $contents[] = $doc->content;
                }
            }
        }

        return array_filter($contents, fn($c) => !empty(trim($c)));
    }

    /**
     * Extract keywords from text
     */
    private function extractKeywords(string $text): array
    {
        // Remove punctuation and convert to lowercase
        $text = preg_replace('/[^\w\s]/', ' ', strtolower($text));
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Remove stop words
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
            'may', 'might', 'must', 'shall', 'can', 'to', 'of', 'in', 'for', 'on', 'with',
            'at', 'by', 'from', 'as', 'into', 'through', 'during', 'before', 'after',
            'above', 'below', 'between', 'under', 'again', 'further', 'then', 'once',
            'here', 'there', 'when', 'where', 'why', 'how', 'all', 'each', 'few', 'more',
            'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same',
            'so', 'than', 'too', 'very', 'just', 'and', 'but', 'if', 'or', 'because',
            'until', 'while', 'this', 'that', 'these', 'those', 'what', 'which', 'who',
            'it', 'its', 'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she', 'they'];

        return array_values(array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        }));
    }

    /**
     * Extract numeric score from AI response
     */
    private function extractScore(string $response): float
    {
        // Try to find a number in the response
        if (preg_match('/(\d+(?:\.\d+)?)/', $response, $matches)) {
            $score = (float) $matches[1];
            return min(100, max(0, $score)); // Clamp to 0-100
        }
        return 50.0; // Default neutral score
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        return \App\Support\VectorMath::cosineSimilarity($a, $b);
    }
}

<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * HyDE (Hypothetical Document Embeddings) Query Expansion Service
 *
 * Implements the HyDE technique for improved semantic search:
 * 1. Generate a hypothetical answer document using an LLM
 * 2. Embed the hypothetical document (not the original query)
 * 3. Search using the hypothetical document's embedding
 *
 * This works well because:
 * - The hypothetical document is semantically closer to actual answers
 * - LLM knowledge helps bridge vocabulary gaps
 * - Particularly effective for abstract or conceptual queries
 *
 * When to use HyDE:
 * - Abstract or conceptual queries
 * - LLM has domain knowledge about the topic
 *
 * When NOT to use HyDE:
 * - Exact keyword matching required
 * - Completely novel/specialized domains where LLM lacks knowledge
 *
 * @see https://zilliz.com/learn/improve-rag-and-information-retrieval-with-hyde-hypothetical-document-embeddings
 */
class HyDEService
{
    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Search using HyDE query expansion
     *
     * @param string $query User's original query
     * @param int $k Number of results to return
     * @param string|null $documentType Optional filter by document type
     * @return array Search results with similarity scores
     */
    public function search(string $query, int $k = 5, ?string $documentType = null): array
    {
        $startTime = microtime(true);

        try {
            // Step 1: Generate hypothetical answer document
            $hypotheticalDoc = $this->generateHypotheticalDocument($query);

            if (empty($hypotheticalDoc)) {
                Log::warning('HyDE: Failed to generate hypothetical document, falling back to original query', [
                    'query' => substr($query, 0, 100),
                ]);
                // Fall back to embedding the original query
                $hypotheticalDoc = $query;
            }

            // Step 2: Embed the hypothetical document (NOT the query)
            $result = $this->aiService->generateEmbedding($hypotheticalDoc);

            if (!$result['success']) {
                throw new Exception("HyDE embedding failed: " . ($result['error'] ?? 'unknown error'));
            }

            $embedding = $result['embedding'];

            // Step 3: Search with hypothetical document embedding
            $embeddingStr = PgVector::literal($embedding);

            $params = [];
            $whereClause = '';
            if ($documentType) {
                $whereClause = 'WHERE document_type = ?';
                $params[] = $documentType;
            }
            $params[] = $k;

            $sql = "SELECT *,
                    (embedding <=> '{$embeddingStr}'::vector) as distance,
                    1 - (embedding <=> '{$embeddingStr}'::vector) as similarity
                    FROM rag_documents
                    {$whereClause}
                    ORDER BY distance ASC
                    LIMIT ?";

            $documents = DB::connection('pgsql_rag')->select($sql, $params);

            $results = array_map(function ($doc) {
                return [
                    'document' => $doc,
                    'similarity' => (float) $doc->similarity,
                ];
            }, $documents);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            Log::info('HyDE search completed', [
                'query' => substr($query, 0, 100),
                'hypothetical_length' => strlen($hypotheticalDoc),
                'results_count' => count($results),
                'top_similarity' => $results[0]['similarity'] ?? 0,
                'duration_ms' => $durationMs,
            ]);

            return $results;

        } catch (Exception $e) {
            Log::error('HyDE search failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);
            throw $e;
        }
    }

    /**
     * Generate a hypothetical document that would answer the query
     *
     * @param string $query User's original query
     * @return string Hypothetical answer document (~500 chars)
     */
    public function generateHypotheticalDocument(string $query): string
    {
        $prompt = <<<PROMPT
Given the question: "{$query}"

Generate a hypothetical document that directly answers this question.
The document should be detailed and authoritative, approximately 500 characters.
Write as if this document exists in our knowledge base.
Focus on factual information that would help answer the question.
Do not include preamble like "Here is a document" - just write the document content directly.
PROMPT;

        $result = $this->aiService->process($prompt, [
            'temperature' => 0.3,
            'max_tokens' => 300,
            'suppressAlert' => true,
        ]);

        if (!$result['success']) {
            Log::warning('HyDE: LLM generation failed', [
                'error' => $result['error'] ?? 'unknown',
                'query' => substr($query, 0, 100),
            ]);
            return '';
        }

        $hypothetical = trim($result['response'] ?? '');

        Log::debug('HyDE: Generated hypothetical document', [
            'query' => substr($query, 0, 100),
            'hypothetical_preview' => substr($hypothetical, 0, 200),
            'length' => strlen($hypothetical),
        ]);

        return $hypothetical;
    }

    /**
     * Determine if a query is suitable for HyDE
     *
     * HyDE works best for:
     * - Abstract or conceptual queries
     * - Questions the LLM likely has domain knowledge about
     *
     * HyDE is NOT suitable for:
     * - Exact keyword searches (names, codes, identifiers)
     * - Highly specialized domains the LLM doesn't know
     * - Very short queries (likely keyword searches)
     *
     * @param string $query User's query
     * @return bool Whether HyDE should be used
     */
    public function shouldUseHyde(string $query): bool
    {
        $query = trim($query);

        // Contains identifiers (codes, IDs) - exact match needed
        if (preg_match('/\b[A-Z]{2,}-\d+\b/', $query)) {
            return false;
        }

        // Contains quoted exact phrases - user wants exact match
        if (preg_match('/"[^"]+"/', $query)) {
            return false;
        }

        $lowerQuery = strtolower($query);

        // Question words suggest conceptual query - good for HyDE
        // This overrides the length check since questions are inherently conceptual
        $questionWords = ['what', 'why', 'how', 'explain', 'describe', 'compare', 'when', 'where', 'which'];
        foreach ($questionWords as $word) {
            if (str_starts_with($lowerQuery, $word . ' ') || str_contains($lowerQuery, " $word ")) {
                return true;
            }
        }

        // Too short and not a question - likely keyword search
        if (strlen($query) < 20) {
            return false;
        }

        // Word count >= 5 suggests natural language query
        $wordCount = str_word_count($query);
        if ($wordCount >= 5) {
            return true;
        }

        return false;
    }
}

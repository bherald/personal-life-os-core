<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Sentence Window Retrieval Service
 *
 * Implements sentence-level embedding with context window expansion.
 * Instead of embedding entire chunks, this approach:
 *
 * 1. Embeds individual sentences
 * 2. Retrieves the most relevant sentences
 * 3. Expands context by including surrounding sentences
 *
 * Benefits:
 * - More precise retrieval (sentence-level granularity)
 * - Better context through window expansion
 * - Handles long documents where relevant info is scattered
 *
 * @see LlamaIndex Sentence Window Retrieval
 */
class SentenceWindowRetrievalService
{
    private AIService $aiService;
    private SemanticChunkerService $chunkerService;

    /** Default number of sentences before/after to include in window */
    private const DEFAULT_WINDOW_SIZE = 2;

    public function __construct(AIService $aiService, SemanticChunkerService $chunkerService)
    {
        $this->aiService = $aiService;
        $this->chunkerService = $chunkerService;
    }

    /**
     * Index a document with sentence-level embeddings
     *
     * @param int $documentId RAG document ID
     * @param string $content Document content
     * @param bool $updateDocumentMode Update document's embedding_mode column
     * @return array Results with sentence count
     */
    public function indexDocument(int $documentId, string $content, bool $updateDocumentMode = true): array
    {
        $startTime = microtime(true);

        try {
            // Pre-check: if no sentence boundaries exist, mark permanent immediately.
            // Avoids infinite retry loops for structured/JSON/binary docs that will never split.
            $sentencesFound = $this->chunkerService->splitSentencesWithPositions($content);
            if (empty($sentencesFound)) {
                $permanent = empty(trim($content)) || strlen(trim($content)) < 20;
                return [
                    'success' => false,
                    'permanent' => true, // always permanent — no sentence structure, won't improve on retry
                    'error' => $permanent ? 'No sentence content' : 'No sentence boundaries found (structured/binary content)',
                ];
            }

            // Generate sentence embeddings
            $sentenceRecords = $this->chunkerService->generateSentenceEmbeddings($documentId, $content);

            if (empty($sentenceRecords)) {
                // splitSentencesWithPositions returned sentences but embedding failed — transient
                return [
                    'success' => false,
                    'permanent' => false,
                    'error' => 'Embedding failed — provider unavailable or returned no results',
                ];
            }

            // Clear existing sentence embeddings for this document
            DB::connection('pgsql_rag')->delete("
                DELETE FROM rag_sentence_embeddings WHERE document_id = ?
            ", [$documentId]);

            // Insert sentence embeddings
            foreach ($sentenceRecords as $record) {
                $embeddingStr = PgVector::literal($record['embedding']);

                DB::connection('pgsql_rag')->insert("
                    INSERT INTO rag_sentence_embeddings
                    (document_id, sentence_index, sentence_text, char_start, char_end, embedding)
                    VALUES (?, ?, ?, ?, ?, ?::vector)
                ", [
                    $record['document_id'],
                    $record['sentence_index'],
                    $record['sentence_text'],
                    $record['char_start'],
                    $record['char_end'],
                    $embeddingStr,
                ]);
            }

            // Update document with sentence positions
            $positions = array_map(fn($r) => [
                'index' => $r['sentence_index'],
                'start' => $r['char_start'],
                'end' => $r['char_end'],
            ], $sentenceRecords);

            DB::connection('pgsql_rag')->update("
                UPDATE rag_documents
                SET sentence_positions = ?,
                    embedding_mode = 'sentence'
                WHERE id = ?
            ", [json_encode($positions), $documentId]);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            Log::info('SentenceWindowRetrieval: Document indexed', [
                'document_id' => $documentId,
                'sentence_count' => count($sentenceRecords),
                'duration_ms' => $durationMs,
            ]);

            return [
                'success' => true,
                'document_id' => $documentId,
                'sentence_count' => count($sentenceRecords),
                'duration_ms' => $durationMs,
            ];

        } catch (Exception $e) {
            Log::error('SentenceWindowRetrieval: Indexing failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'permanent' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Screen a document for sentence-indexing eligibility (N81).
     * Heuristics-only — no AI vetting (SE is less selective than RAPTOR).
     * Documents >= 2000 chars are always eligible.
     *
     * @param object $doc RAG document row with id, content, document_type columns
     * @return bool true = eligible, false = ineligible (noise/structured/too short)
     */
    public function screenForIndexing(object $doc): bool
    {
        $content = $doc->content ?? '';
        $len = strlen($content);

        // Too short for meaningful sentence windows
        if ($len < 500) {
            return false;
        }

        // >= 2000 chars always eligible (enough content for retrieval value)
        if ($len >= 2000) {
            return true;
        }

        // Borderline 500–1999: apply heuristics
        $trimmed = ltrim($content);

        // JSON blobs
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return false;
        }

        // Code file markers
        if (str_starts_with($trimmed, '<?php') || str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '#!/')) {
            return false;
        }

        // Semicolon density (code/CSS/SQL)
        $lines = substr_count($content, "\n") + 1;
        $semicolons = substr_count($content, ';');
        if ($lines > 0 && ($semicolons / $lines) >= 0.25) {
            return false;
        }

        // Multi-occurrence code keywords (FoxPro, PHP, etc.)
        $codeKeywords = ['PROCEDURE', 'FUNCTION', 'ENDIF', 'ENDDO', 'function(', 'function (', '=>', '->'];
        foreach ($codeKeywords as $kw) {
            if (substr_count($content, $kw) >= 2) {
                return false;
            }
        }

        // Structured-char ratio > 15% (JSON-like, XML, code)
        $structuredChars = preg_match_all('/[{}\[\]<>|=;,]/', $content);
        if ($len > 0 && ($structuredChars / $len) > 0.15) {
            return false;
        }

        // Too few sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        if (count($sentences) < 5) {
            return false;
        }

        return true;
    }

    /**
     * Search with sentence-level retrieval and window expansion
     *
     * @param string $query Search query
     * @param array $options Search options:
     *   - limit: int (default 5) - Number of sentences to retrieve
     *   - window_size: int (default 2) - Sentences before/after to include
     *   - document_type: string|null - Filter by document type
     *   - min_similarity: float (default 0.5) - Minimum similarity threshold
     *   - merge_overlapping: bool (default true) - Merge overlapping windows
     * @return array Search results with expanded context
     */
    public function search(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 5;
        $windowSize = $options['window_size'] ?? self::DEFAULT_WINDOW_SIZE;
        $documentType = $options['document_type'] ?? null;
        $minSimilarity = $options['min_similarity'] ?? 0.5;
        $mergeOverlapping = $options['merge_overlapping'] ?? true;

        try {
            // Generate query embedding
            $embeddingResult = $this->aiService->generateEmbedding($query);
            if (!$embeddingResult['success']) {
                return ['success' => false, 'error' => 'Failed to generate query embedding'];
            }

            $queryEmbedding = PgVector::literal($embeddingResult['embedding']);

            // Search sentence embeddings
            $sql = "
                SELECT
                    se.id,
                    se.document_id,
                    se.sentence_index,
                    se.sentence_text,
                    se.char_start,
                    se.char_end,
                    d.title,
                    d.document_type,
                    d.content as full_content,
                    d.metadata,
                    1 - (se.embedding <=> ?::vector) as similarity
                FROM rag_sentence_embeddings se
                JOIN rag_documents d ON d.id = se.document_id
                WHERE 1=1
            ";
            $params = [$queryEmbedding];

            if ($documentType) {
                $sql .= " AND d.document_type = ?";
                $params[] = $documentType;
            }

            $sql .= "
                ORDER BY se.embedding <=> ?::vector
                LIMIT ?
            ";
            $params[] = $queryEmbedding;
            $params[] = $limit * 3; // Get more to allow for filtering

            $sentences = DB::connection('pgsql_rag')->select($sql, $params);

            // Filter by similarity threshold
            $sentences = array_filter($sentences, fn($s) => $s->similarity >= $minSimilarity);
            $sentences = array_slice($sentences, 0, $limit);

            if (empty($sentences)) {
                return [
                    'success' => true,
                    'results' => [],
                    'query' => $query,
                ];
            }

            // Expand context windows
            $results = [];
            foreach ($sentences as $sentence) {
                $window = $this->expandWindow(
                    $sentence->document_id,
                    $sentence->sentence_index,
                    $windowSize,
                    $sentence->full_content
                );

                $results[] = [
                    'document_id' => $sentence->document_id,
                    'title' => $sentence->title,
                    'document_type' => $sentence->document_type,
                    'matched_sentence' => $sentence->sentence_text,
                    'sentence_index' => $sentence->sentence_index,
                    'similarity' => round($sentence->similarity, 4),
                    'expanded_context' => $window['text'],
                    'window_start' => $window['start_sentence'],
                    'window_end' => $window['end_sentence'],
                    'metadata' => json_decode($sentence->metadata, true),
                ];
            }

            // Merge overlapping windows from same document
            if ($mergeOverlapping) {
                $results = $this->mergeOverlappingWindows($results);
            }

            return [
                'success' => true,
                'results' => $results,
                'query' => $query,
                'window_size' => $windowSize,
            ];

        } catch (Exception $e) {
            Log::error('SentenceWindowRetrieval: Search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Expand context window around a sentence
     */
    private function expandWindow(int $documentId, int $sentenceIndex, int $windowSize, string $fullContent): array
    {
        // Get all sentences for this document
        $sentences = DB::connection('pgsql_rag')->select("
            SELECT sentence_index, sentence_text, char_start, char_end
            FROM rag_sentence_embeddings
            WHERE document_id = ?
            ORDER BY sentence_index
        ", [$documentId]);

        if (empty($sentences)) {
            // Fallback to chunker if no sentence embeddings
            return $this->chunkerService->getSentenceWindow($fullContent, $sentenceIndex, $windowSize);
        }

        // Build index map for O(1) lookup instead of O(n) scan per sentence
        $sentenceMap = [];
        foreach ($sentences as $s) {
            $sentenceMap[$s->sentence_index] = $s->sentence_text;
        }

        $totalSentences = count($sentences);
        $startIndex = max(0, $sentenceIndex - $windowSize);
        $endIndex = min($totalSentences - 1, $sentenceIndex + $windowSize);

        $windowTexts = [];
        for ($i = $startIndex; $i <= $endIndex; $i++) {
            if (isset($sentenceMap[$i])) {
                $windowTexts[] = $sentenceMap[$i];
            }
        }

        return [
            'text' => implode(' ', $windowTexts),
            'start_sentence' => $startIndex,
            'end_sentence' => $endIndex,
        ];
    }

    /**
     * Merge overlapping windows from the same document
     */
    private function mergeOverlappingWindows(array $results): array
    {
        // Group by document
        $byDocument = [];
        foreach ($results as $result) {
            $docId = $result['document_id'];
            if (!isset($byDocument[$docId])) {
                $byDocument[$docId] = [];
            }
            $byDocument[$docId][] = $result;
        }

        $merged = [];
        foreach ($byDocument as $docId => $docResults) {
            if (count($docResults) === 1) {
                $merged[] = $docResults[0];
                continue;
            }

            // Sort by window start
            usort($docResults, fn($a, $b) => $a['window_start'] - $b['window_start']);

            // Merge overlapping
            $current = $docResults[0];
            for ($i = 1; $i < count($docResults); $i++) {
                $next = $docResults[$i];

                // Check overlap
                if ($next['window_start'] <= $current['window_end'] + 1) {
                    // Merge
                    $current['window_end'] = max($current['window_end'], $next['window_end']);
                    $current['similarity'] = max($current['similarity'], $next['similarity']);
                    $current['matched_sentence'] .= ' [...] ' . $next['matched_sentence'];

                    // Regenerate expanded context
                    // (For simplicity, concatenate; in production, re-fetch from DB)
                    if ($next['expanded_context'] !== $current['expanded_context']) {
                        $current['expanded_context'] = $current['expanded_context'] . ' ' . $next['expanded_context'];
                    }
                } else {
                    $merged[] = $current;
                    $current = $next;
                }
            }
            $merged[] = $current;
        }

        // Sort by similarity
        usort($merged, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return $merged;
    }

    /**
     * Batch index documents with sentence-level embeddings
     *
     * @param int $limit Maximum documents to process
     * @return array Processing results
     */
    public function batchIndex(int $limit = 100): array
    {
        // Find documents that haven't been sentence-indexed
        $documents = DB::connection('pgsql_rag')->select("
            SELECT id, content
            FROM rag_documents
            WHERE embedding_mode IS NULL OR embedding_mode = 'chunk'
            LIMIT ?
        ", [$limit]);

        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
        ];

        foreach ($documents as $doc) {
            $results['processed']++;

            $result = $this->indexDocument($doc->id, $doc->content);

            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Hybrid search combining chunk and sentence retrieval
     *
     * Retrieves results from both chunk-level and sentence-level embeddings,
     * then combines and deduplicates.
     *
     * @param string $query Search query
     * @param array $options Search options
     * @return array Combined search results
     */
    public function hybridSearch(string $query, array $options = []): array
    {
        $sentenceResults = $this->search($query, $options);

        // Also do chunk-level search (using RAGService pattern)
        $chunkResults = $this->chunkSearch($query, $options);

        // Combine and deduplicate by document_id
        $combined = [];
        $seenDocs = [];

        // Prioritize sentence results (more precise)
        if ($sentenceResults['success'] && !empty($sentenceResults['results'])) {
            foreach ($sentenceResults['results'] as $result) {
                $docId = $result['document_id'];
                if (!isset($seenDocs[$docId])) {
                    $result['retrieval_method'] = 'sentence_window';
                    $combined[] = $result;
                    $seenDocs[$docId] = true;
                }
            }
        }

        // Add chunk results that weren't found by sentence search
        if ($chunkResults['success'] && !empty($chunkResults['results'])) {
            foreach ($chunkResults['results'] as $result) {
                $docId = $result['document_id'];
                if (!isset($seenDocs[$docId])) {
                    $result['retrieval_method'] = 'chunk';
                    $combined[] = $result;
                    $seenDocs[$docId] = true;
                }
            }
        }

        return [
            'success' => true,
            'results' => $combined,
            'query' => $query,
            'sentence_results' => count($sentenceResults['results'] ?? []),
            'chunk_results' => count($chunkResults['results'] ?? []),
        ];
    }

    /**
     * Basic chunk-level search for hybrid mode
     */
    private function chunkSearch(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 5;
        $documentType = $options['document_type'] ?? null;
        $minSimilarity = $options['min_similarity'] ?? 0.5;

        try {
            $embeddingResult = $this->aiService->generateEmbedding($query);
            if (!$embeddingResult['success']) {
                return ['success' => false, 'results' => []];
            }

            $queryEmbedding = PgVector::literal($embeddingResult['embedding']);

            $sql = "
                SELECT
                    id as document_id,
                    title,
                    document_type,
                    content,
                    metadata,
                    1 - (embedding <=> ?::vector) as similarity
                FROM rag_documents
                WHERE 1=1
            ";
            $params = [$queryEmbedding];

            if ($documentType) {
                $sql .= " AND document_type = ?";
                $params[] = $documentType;
            }

            $sql .= "
                ORDER BY embedding <=> ?::vector
                LIMIT ?
            ";
            $params[] = $queryEmbedding;
            $params[] = $limit;

            $results = DB::connection('pgsql_rag')->select($sql, $params);

            $filtered = [];
            foreach ($results as $result) {
                if ($result->similarity >= $minSimilarity) {
                    $filtered[] = [
                        'document_id' => $result->document_id,
                        'title' => $result->title,
                        'document_type' => $result->document_type,
                        'expanded_context' => $result->content,
                        'similarity' => round($result->similarity, 4),
                        'metadata' => json_decode($result->metadata, true),
                    ];
                }
            }

            return ['success' => true, 'results' => $filtered];

        } catch (Exception $e) {
            return ['success' => false, 'results' => [], 'error' => $e->getMessage()];
        }
    }
}

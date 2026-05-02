<?php

namespace App\Services;

use App\Services\DataSanitizer;
use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * RAG (Retrieval Augmented Generation) Service
 *
 * Provides semantic search capabilities over workflow data
 * using pgvector embeddings and PostgreSQL HNSW indexes.
 *
 * Performance optimizations:
 * - Native PostgreSQL vector operations (10-100x faster than PHP)
 * - HNSW index for fast approximate nearest neighbor search
 * - Optional hybrid search combining semantic + full-text
 *
 * Now uses AIService for resilience (circuit breaker, retry, fallback).
 */
class RAGService
{
    private AIService $aiService;
    private ?RerankerService $reranker = null;
    private ?HyDEService $hydeService = null;
    private ?SemanticChunkerService $semanticChunker = null;
    private ?ContextualRetrievalService $contextualRetrieval = null;
    private ?RaptorSummarizationService $raptorService = null;
    private ?SemDeDupService $dedupService = null;
    private ?MMRDiversityService $mmrService = null;
    private ?RAGTracingService $tracingService = null;
    private ?MetadataFilterService $metadataFilterService = null;
    private ?ContextualCompressionService $compressionService = null;
    private ?GraphSearchService $graphSearchService = null;
    private ?GraphFusionService $graphFusionService = null;
    private ?QueryDecompositionService $decompositionService = null;
    private ?SPLADEService $spladeService = null;
    private ?HyPEService $hypeService = null;
    private ?CRAGService $cragService = null;
    private ?TemporalScoringService $temporalService = null;
    private ?RelevanceGatingService $relevanceGatingService = null;
    private ?ColBERTRerankService $colbertService = null;
    private ?IterativeRetrievalService $iterativeService = null;
    private ?RAGStrategyService $strategyService = null;
    private ?RAGSecurityService $securityService = null;
    private ?LazyGraphRAGService $lazyGraphService = null;
    private ?LongContextRerankService $longContextService = null;
    private ?MultimodalEmbeddingService $multimodalService = null;
    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Fetch a RAG document by ID using raw SQL (no Eloquent)
     */
    private function findRagDocument(int $id): ?object
    {
        $results = DB::connection('pgsql_rag')->select(
            "SELECT * FROM rag_documents WHERE id = ? LIMIT 1", [$id]
        );
        return $results[0] ?? null;
    }

    private function toVectorLiteral(array $embedding): string
    {
        return PgVector::literal($embedding);
    }

    /**
     * Get or create SemDeDupService instance (lazy loaded)
     */
    private function getDedupService(): SemDeDupService
    {
        if ($this->dedupService === null) {
            $this->dedupService = new SemDeDupService($this->aiService);
        }
        return $this->dedupService;
    }

    private function getMMRService(): MMRDiversityService
    {
        if ($this->mmrService === null) {
            $this->mmrService = new MMRDiversityService($this->aiService);
        }
        return $this->mmrService;
    }

    private function getTracingService(): RAGTracingService
    {
        if ($this->tracingService === null) {
            $this->tracingService = new RAGTracingService();
        }
        return $this->tracingService;
    }

    private function getMetadataFilterService(): MetadataFilterService
    {
        if ($this->metadataFilterService === null) {
            $this->metadataFilterService = new MetadataFilterService($this->aiService);
        }
        return $this->metadataFilterService;
    }

    private function getCompressionService(): ContextualCompressionService
    {
        if ($this->compressionService === null) {
            $this->compressionService = new ContextualCompressionService($this->aiService);
        }
        return $this->compressionService;
    }

    private function getDecompositionService(): QueryDecompositionService
    {
        if ($this->decompositionService === null) {
            $this->decompositionService = new QueryDecompositionService($this->aiService);
        }
        return $this->decompositionService;
    }

    private function getSpladeService(): SPLADEService
    {
        if ($this->spladeService === null) {
            $this->spladeService = app(SPLADEService::class);
        }
        return $this->spladeService;
    }

    /**
     * Get or create RaptorSummarizationService instance (lazy loaded)
     */
    private function getRaptorService(): RaptorSummarizationService
    {
        if ($this->raptorService === null) {
            $this->raptorService = new RaptorSummarizationService($this->aiService);
        }
        return $this->raptorService;
    }

    private function getGraphSearchService(): GraphSearchService
    {
        if ($this->graphSearchService === null) {
            $this->graphSearchService = new GraphSearchService();
        }
        return $this->graphSearchService;
    }

    private function getGraphFusionService(): GraphFusionService
    {
        if ($this->graphFusionService === null) {
            $this->graphFusionService = new GraphFusionService();
        }
        return $this->graphFusionService;
    }

    /**
     * Get or create ContextualRetrievalService instance (lazy loaded)
     */
    private function getContextualRetrieval(): ContextualRetrievalService
    {
        if ($this->contextualRetrieval === null) {
            $this->contextualRetrieval = new ContextualRetrievalService($this->aiService);
        }
        return $this->contextualRetrieval;
    }

    /**
     * Get or create RerankerService instance (lazy loaded)
     */
    private function getReranker(): RerankerService
    {
        if ($this->reranker === null) {
            $this->reranker = new RerankerService($this->aiService);
        }
        return $this->reranker;
    }

    /**
     * Get or create HyDEService instance (lazy loaded)
     */
    private function getHydeService(): HyDEService
    {
        if ($this->hydeService === null) {
            $this->hydeService = new HyDEService($this->aiService);
        }
        return $this->hydeService;
    }

    /**
     * Get or create TemporalScoringService instance (lazy loaded)
     */
    private function getTemporalService(): TemporalScoringService
    {
        if ($this->temporalService === null) {
            $this->temporalService = new TemporalScoringService();
        }
        return $this->temporalService;
    }

    /**
     * Get or create RelevanceGatingService instance (lazy loaded)
     */
    private function getRelevanceGatingService(): RelevanceGatingService
    {
        if ($this->relevanceGatingService === null) {
            $this->relevanceGatingService = new RelevanceGatingService($this->aiService);
        }
        return $this->relevanceGatingService;
    }

    /**
     * Get or create RAGSecurityService instance (lazy loaded)
     */
    private function getSecurityService(): RAGSecurityService
    {
        if ($this->securityService === null) {
            $this->securityService = new RAGSecurityService();
        }
        return $this->securityService;
    }

    /**
     * Get or create RAGStrategyService instance (lazy loaded)
     */
    private function getStrategyService(): RAGStrategyService
    {
        if ($this->strategyService === null) {
            $this->strategyService = new RAGStrategyService();
        }
        return $this->strategyService;
    }

    /**
     * Get or create IterativeRetrievalService instance (lazy loaded)
     */
    private function getIterativeService(): IterativeRetrievalService
    {
        if ($this->iterativeService === null) {
            $this->iterativeService = new IterativeRetrievalService($this->aiService);
        }
        return $this->iterativeService;
    }

    /**
     * Get or create ColBERTRerankService instance (lazy loaded)
     */
    private function getColBERTService(): ColBERTRerankService
    {
        if ($this->colbertService === null) {
            $this->colbertService = new ColBERTRerankService($this->aiService);
        }
        return $this->colbertService;
    }

    private function getLazyGraphService(): LazyGraphRAGService
    {
        if ($this->lazyGraphService === null) {
            $this->lazyGraphService = new LazyGraphRAGService($this->aiService);
        }
        return $this->lazyGraphService;
    }

    private function getLongContextRerankService(): LongContextRerankService
    {
        if ($this->longContextService === null) {
            $this->longContextService = new LongContextRerankService();
        }
        return $this->longContextService;
    }

    private function getMultimodalService(): MultimodalEmbeddingService
    {
        if ($this->multimodalService === null) {
            $this->multimodalService = new MultimodalEmbeddingService($this->aiService);
        }
        return $this->multimodalService;
    }

    /**
     * Get or create CRAGService instance (lazy loaded)
     */
    private function getCRAGService(): CRAGService
    {
        if ($this->cragService === null) {
            $this->cragService = new CRAGService($this->aiService, new SearXNGService());
        }
        return $this->cragService;
    }

    /**
     * Get or create HyPEService instance (lazy loaded)
     */
    private function getHyPEService(): HyPEService
    {
        if ($this->hypeService === null) {
            $this->hypeService = new HyPEService($this->aiService);
        }
        return $this->hypeService;
    }

    /**
     * Get or create SemanticChunkerService instance (lazy loaded)
     */
    private function getSemanticChunker(): SemanticChunkerService
    {
        if ($this->semanticChunker === null) {
            $this->semanticChunker = new SemanticChunkerService($this->aiService);
        }
        return $this->semanticChunker;
    }

    /**
     * Index a document for semantic search
     *
     * @param string $documentType Type of document (joplin_note, joplin_attachment, etc.)
     * @param string $content Document content for embedding
     * @param string|null $title Document title
     * @param array|null $metadata Additional metadata (JSON)
     * @param string|int|null $sourceId Source identifier
     * @param string|null $sourceType Source type class
     * @param string|null $designation Document designation
     * @param string|null $mediaUrl Nextcloud WebDAV URL for source media (E17/EA1)
     */
    public function indexDocument(
        string $documentType,
        string $content,
        ?string $title = null,
        ?array $metadata = null,
        string|int|null $sourceId = null,
        ?string $sourceType = null,
        ?string $designation = null,
        ?string $mediaUrl = null,
        array $options = []
    ): ?object {
        $traceTiming = !empty($options['trace_timing']);
        $startedAt = microtime(true);
        $stepStartedAt = $startedAt;

        $logStep = function (string $step, array $extra = []) use (&$stepStartedAt, $startedAt, $traceTiming, $documentType, $sourceType, $sourceId): void {
            if (!$traceTiming) {
                return;
            }

            $now = microtime(true);
            Log::info('RAG index timing', array_merge([
                'step' => $step,
                'step_ms' => (int) (($now - $stepStartedAt) * 1000),
                'total_ms' => (int) (($now - $startedAt) * 1000),
                'type' => $documentType,
                'source' => $sourceType,
                'source_id' => $sourceId,
            ], $extra));
            $stepStartedAt = $now;
        };

        try {
            // Validate content is not empty before attempting embedding generation
            $trimmedContent = trim($content);
            if (empty($trimmedContent)) {
                throw new Exception("Cannot index document with empty content (type: {$documentType}, source: {$sourceType}, sourceId: {$sourceId})");
            }
            $logStep('trim', ['content_chars' => strlen($trimmedContent)]);

            // Semantic dedup check
            $dedupResult = $this->getDedupService()->checkDuplicate(
                $trimmedContent, $title, $sourceType, $sourceId, $options
            );
            $contentHash = $dedupResult['content_hash'];
            $logStep('dedup', [
                'skip_dedup' => !empty($options['skip_dedup']),
                'dedup_action' => $dedupResult['action'] ?? null,
                'dedup_duplicate' => $dedupResult['is_duplicate'] ?? false,
            ]);

            if ($dedupResult['is_duplicate']) {
                $matchedId = $dedupResult['matched_document_id'];

                if ($dedupResult['action'] === 'update') {
                    $this->getDedupService()->updateExisting($matchedId, $trimmedContent, $title, $metadata);
                    return $this->findRagDocument($matchedId);
                }

                if ($dedupResult['action'] === 'merge') {
                    $this->getDedupService()->mergeMetadata($matchedId, $metadata);
                    return $this->findRagDocument($matchedId);
                }

                // Default: block
                Log::info('RAG: Document blocked by dedup', [
                    'strategy' => $dedupResult['strategy'],
                    'matched_id' => $matchedId,
                    'similarity' => $dedupResult['similarity'],
                ]);
                return $this->findRagDocument($matchedId);
            }

            // Generate embedding using AIService with full resilience (retry, circuit breaker)
            $embeddingOptions = [];
            if (array_key_exists('allow_cpu_fallback', $options)) {
                $embeddingOptions['allow_cpu_fallback'] = (bool) $options['allow_cpu_fallback'];
            }

            $result = $this->aiService->generateEmbedding($trimmedContent, $embeddingOptions);
            if (!$result['success']) {
                throw new Exception("Embedding generation failed: " . ($result['error'] ?? 'unknown error'));
            }
            $embedding = $result['embedding'];
            $logStep('embedding', [
                'provider' => $result['provider'] ?? null,
                'embedding_ms' => $result['duration_ms'] ?? null,
                'embedding_dims' => is_array($embedding) ? count($embedding) : null,
            ]);

            // Auto-set designation from documentType if not provided
            if (!$designation) {
                $designation = $documentType;
            }

            // Convert embedding array to PostgreSQL vector format
            $embeddingStr = $this->toVectorLiteral($embedding);
            $logStep('vector_literal', ['vector_chars' => strlen($embeddingStr)]);

            // Create document record using raw SQL (sanitize for PostgreSQL UTF-8)
            $now = now()->toDateTimeString();
            $result = DB::connection('pgsql_rag')->select(
                "INSERT INTO rag_documents (document_type, title, content, embedding, metadata, source_id, source_type, designation, media_url, content_hash, dedup_status, dedup_checked_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?::vector, ?, ?, ?, ?, ?, ?, 'unique', ?, ?, ?)
                 RETURNING id",
                [
                    $documentType,
                    DataSanitizer::cleanUtf8($title),
                    DataSanitizer::cleanUtf8($trimmedContent),
                    $embeddingStr,
                    $metadata ? json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE) : null,
                    $sourceId,
                    $sourceType,
                    $designation,
                    $mediaUrl,
                    $contentHash ?? null,
                    $now,
                    $now,
                    $now,
                ]
            );
            $documentId = $result[0]->id;
            $logStep('insert', ['document_id' => $documentId]);

            Log::info('RAG document indexed', [
                'id' => $documentId,
                'type' => $documentType,
                'source' => $sourceType,
            ]);

            // GR-12: Incremental graph maintenance — extract KG at index time
            if ($options['extract_kg'] ?? false) {
                $this->extractKGForDocument($documentId, $trimmedContent, $contentHash);
            }

            $document = $this->findRagDocument($documentId);
            $logStep('fetch');

            return $document;
        } catch (\Exception $e) {
            Log::error('RAG indexing failed', [
                'error' => $e->getMessage(),
                'type' => $documentType,
                'source' => $sourceType,
                'source_id' => $sourceId,
                'trace_timing' => $traceTiming,
            ]);
            throw $e;
        }
    }

    /**
     * GR-12: Extract KG triples for a newly indexed document.
     * Non-fatal — failures are logged but do not prevent indexing.
     * Also stamps kg_content_hash for GR-5 diff detection.
     */
    private function extractKGForDocument(int $documentId, string $content, ?string $contentHash): void
    {
        try {
            if (strlen($content) < 50) {
                return; // Too short for meaningful extraction
            }

            $kgService = app(KnowledgeGraphService::class);
            $result = $kgService->buildFromDocument($documentId);

            if ($result['success'] && $contentHash) {
                DB::connection('pgsql_rag')->update(
                    "UPDATE rag_documents SET kg_content_hash = ? WHERE id = ?",
                    [$contentHash, $documentId]
                );
            }

            Log::info('GR-12: Incremental KG extraction at index time', [
                'document_id'       => $documentId,
                'success'           => $result['success'],
                'entities_extracted' => $result['entities_extracted'] ?? 0,
                'triples_created'   => $result['triples_created'] ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::warning('GR-12: Incremental KG extraction failed (non-fatal)', [
                'document_id' => $documentId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Search for similar documents using semantic similarity
     *
     * Uses PostgreSQL's native vector operations with HNSW index
     * for fast approximate nearest neighbor search.
     *
     * Automatically falls back to hybrid search (vector + full-text) when:
     * - Query is short (<=3 words) - short queries often have poor embeddings
     * - Top vector similarity is below threshold (0.55)
     *
     * @param string $query Search query
     * @param int $limit Number of results to return
     * @param string|null $documentType Optional filter by document type
     * @param bool|string $useHyde Enable HyDE query expansion (true, false, or 'auto')
     *                             'auto' uses HyDEService::shouldUseHyde() to decide
     */
    public function search(string $query, int $limit = 5, ?string $documentType = null, bool|string $useHyde = false, ?string $agentId = null): array
    {
        try {
            // HyDE (Hypothetical Document Embeddings) query expansion
            // When enabled, delegates to HyDEService which generates a hypothetical answer
            // and embeds that instead of the original query
            $hydeEnabled = false;
            if ($useHyde === 'auto') {
                $hydeEnabled = $this->getHydeService()->shouldUseHyde($query);
            } elseif ($useHyde === true) {
                $hydeEnabled = true;
            }

            if ($hydeEnabled) {
                Log::info('RAG search: Using HyDE query expansion', [
                    'query' => substr($query, 0, 100),
                    'mode' => $useHyde,
                ]);
                return $this->getHydeService()->search($query, $limit, $documentType);
            }

            // Generate query embedding using AIService with resilience
            $result = $this->aiService->generateEmbedding($query);
            if (!$result['success']) {
                throw new Exception("Query embedding failed: " . ($result['error'] ?? 'unknown error'));
            }
            $queryEmbedding = $result['embedding'];

            // PostgreSQL with pgvector extension
            $embeddingStr = $this->toVectorLiteral($queryEmbedding);

            $params = [];
            $whereClauses = [];
            if ($documentType) {
                $whereClauses[] = 'document_type = ?';
                $params[] = $documentType;
            }
            if ($agentId) {
                $whereClauses[] = "metadata::jsonb->>'agent_id' = ?";
                $params[] = $agentId;
            }

            $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
            $params[] = $limit;

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

            // Auto-enhance with hybrid search for short queries or low similarity
            $wordCount = str_word_count($query);
            $topSimilarity = $results[0]['similarity'] ?? 0;
            $similarityThreshold = 0.55;

            if ($wordCount <= 3 || $topSimilarity < $similarityThreshold) {
                Log::info('RAG search: enhancing with hybrid for short/low-similarity query', [
                    'query' => $query,
                    'word_count' => $wordCount,
                    'top_similarity' => $topSimilarity,
                ]);

                // Get full-text search results
                $ftsResults = $this->getFullTextResults($query, $limit * 2, $documentType, $agentId);

                // RAG-3: Get SPLADE sparse search results (three-way hybrid)
                $spladeResults = $this->trySpladeSearch($query, $limit * 2, $documentType);

                if (!empty($ftsResults) || !empty($spladeResults)) {
                    $results = $this->mergeResultsTripleRRF($results, $ftsResults, $spladeResults, $limit);
                }
            }

            // Apply fast reranking for improved relevance
            if (count($results) >= 3) {
                $results = $this->getReranker()->rerank($query, $results, 'fast');
            }

            // Apply MMR diversity reranking when enough results
            if (count($results) >= 5) {
                try {
                    $results = $this->getMMRService()->diverseTopK($results, $limit, 0.7);
                } catch (\Throwable $e) {
                    Log::warning('RAG search: MMR reranking failed, using original order', ['error' => $e->getMessage()]);
                }
            }

            // Apply temporal decay for agent-scoped searches
            // Formula: score * e^(-decay * ageDays)
            // Newer agent findings rank higher than stale ones
            if ($agentId && !empty($results)) {
                $results = $this->applyTemporalDecay($results);
            }

            // Record trace
            try {
                $this->getTracingService()->startTrace($query);
            } catch (\Exception $e) {
                Log::debug('RAGService: trace recording failed', ['error' => $e->getMessage()]);
            }

            Log::info('RAG search completed', [
                'query' => substr($query, 0, 100),
                'results_count' => count($results),
                'top_similarity' => $results[0]['similarity'] ?? 0,
                'reranked' => count($results) >= 3,
                'temporal_decay' => $agentId ? true : false,
                'driver' => 'pgvector',
            ]);

            return $results;
        } catch (\Exception $e) {
            Log::error('RAG search failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);
            throw $e;
        }
    }

    /**
     * Apply temporal decay to search results
     *
     * Formula: adjusted_score = similarity * e^(-decay * ageDays)
     * Default decay rate 0.005 means:
     *   - 1 day old: 99.5% of original score
     *   - 7 days: 96.5%
     *   - 30 days: 86.1%
     *   - 90 days: 63.8%
     *   - 180 days: 40.7%
     *   - 365 days: 16.1%
     *
     * Re-sorts results by decay-adjusted score after applying.
     */
    private function applyTemporalDecay(array $results, float $decayRate = 0.005): array
    {
        $now = time();

        foreach ($results as &$result) {
            $createdAt = $result['document']->created_at ?? null;
            if ($createdAt) {
                $docTime = strtotime($createdAt);
                $ageDays = max(0, ($now - $docTime) / 86400);
                $decayFactor = exp(-$decayRate * $ageDays);
                $result['original_similarity'] = $result['similarity'];
                $result['similarity'] = $result['similarity'] * $decayFactor;
                $result['age_days'] = round($ageDays, 1);
                $result['decay_factor'] = round($decayFactor, 4);
            }
        }
        unset($result);

        usort($results, fn($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));

        return $results;
    }

    /**
     * Get full-text search results from PostgreSQL
     */
    private function getFullTextResults(string $query, int $limit, ?string $documentType = null, ?string $agentId = null): array
    {
        try {
            $params = [$query, $query];
            $whereClause = '';
            if ($documentType) {
                $whereClause .= 'AND document_type = ?';
                $params[] = $documentType;
            }
            if ($agentId) {
                $whereClause .= " AND metadata::jsonb->>'agent_id' = ?";
                $params[] = $agentId;
            }
            $params[] = $limit;

            // Also search in title for better short query matching
            $sql = "SELECT *,
                    ts_rank(to_tsvector('english', COALESCE(title, '') || ' ' || content), plainto_tsquery('english', ?)) as fts_rank
                    FROM rag_documents
                    WHERE to_tsvector('english', COALESCE(title, '') || ' ' || content) @@ plainto_tsquery('english', ?)
                    {$whereClause}
                    ORDER BY fts_rank DESC
                    LIMIT ?";

            $documents = DB::connection('pgsql_rag')->select($sql, $params);

            return array_map(function ($doc) {
                return [
                    'document' => $doc,
                    'similarity' => (float) $doc->fts_rank,
                    'source' => 'fts',
                ];
            }, $documents);
        } catch (\Exception $e) {
            Log::warning('Full-text search failed, continuing with vector only', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Merge vector and full-text results using Reciprocal Rank Fusion
     */
    private function mergeResultsRRF(array $vectorResults, array $ftsResults, int $limit): array
    {
        $k = 60; // RRF constant
        $scores = [];
        $docMap = [];

        // Score vector results
        foreach ($vectorResults as $rank => $result) {
            $docId = $result['document']->id;
            $scores[$docId] = ($scores[$docId] ?? 0) + (1 / ($k + $rank + 1));
            $docMap[$docId] = $result['document'];
        }

        // Score FTS results (with slight boost for exact matches)
        foreach ($ftsResults as $rank => $result) {
            $docId = $result['document']->id;
            $scores[$docId] = ($scores[$docId] ?? 0) + (1.2 / ($k + $rank + 1)); // 20% boost for FTS
            $docMap[$docId] = $result['document'];
        }

        // Sort by combined score
        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $limit);

        // Build final results
        $results = [];
        foreach ($topIds as $docId) {
            if (isset($docMap[$docId])) {
                $results[] = [
                    'document' => $docMap[$docId],
                    'similarity' => $scores[$docId],
                ];
            }
        }

        return $results;
    }

    /**
     * RAG-5: Merge results from multiple sub-query searches via RRF deduplication.
     * Documents appearing in multiple sub-query results get boosted.
     */
    private function mergeDecomposedResults(array $resultSets, int $limit): array
    {
        $k = 60;
        $scores = [];
        $docMap = [];
        $bestSimilarity = [];

        foreach ($resultSets as $setIdx => $results) {
            foreach ($results as $rank => $result) {
                $docId = $result['document']->id;
                $scores[$docId] = ($scores[$docId] ?? 0) + (1 / ($k + $rank + 1));
                $docMap[$docId] = $result;
                // Keep highest similarity seen for this doc
                $bestSimilarity[$docId] = max($bestSimilarity[$docId] ?? 0, $result['similarity'] ?? 0);
            }
        }

        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $limit);

        $merged = [];
        foreach ($topIds as $docId) {
            if (isset($docMap[$docId])) {
                $entry = $docMap[$docId];
                $entry['similarity'] = $bestSimilarity[$docId];
                $entry['rrf_score'] = $scores[$docId];
                $entry['decomposed_boost'] = count(array_filter($scores, fn($s) => $s > 0)) > 1;
                $merged[] = $entry;
            }
        }

        return $merged;
    }

    /**
     * RAG-3: Try SPLADE sparse search. Returns empty array if SPLADE unavailable or fails.
     */
    private function trySpladeSearch(string $query, int $limit, ?string $documentType): array
    {
        try {
            $splade = $this->getSpladeService();
            if (!$splade->isAvailable()) {
                return [];
            }
            return $splade->search($query, $limit, $documentType);
        } catch (\Throwable $e) {
            Log::debug('RAG search: SPLADE search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * RAG-3: Three-way RRF merge — vector + FTS + SPLADE sparse results.
     * Extends the existing two-way RRF. Gracefully handles any empty signal.
     */
    private function mergeResultsTripleRRF(array $vectorResults, array $ftsResults, array $spladeResults, int $limit): array
    {
        $k = 60;
        $scores = [];
        $docMap = [];

        // Score vector results (baseline)
        foreach ($vectorResults as $rank => $result) {
            $docId = $result['document']->id;
            $scores[$docId] = ($scores[$docId] ?? 0) + (1.0 / ($k + $rank + 1));
            $docMap[$docId] = $result['document'];
        }

        // Score FTS results (1.2x boost for exact keyword matches)
        foreach ($ftsResults as $rank => $result) {
            $docId = $result['document']->id;
            $scores[$docId] = ($scores[$docId] ?? 0) + (1.2 / ($k + $rank + 1));
            $docMap[$docId] = $result['document'];
        }

        // Score SPLADE results (1.1x boost — learned sparse captures semantic expansion)
        foreach ($spladeResults as $rank => $result) {
            $docId = $result['document']->id;
            $scores[$docId] = ($scores[$docId] ?? 0) + (1.1 / ($k + $rank + 1));
            $docMap[$docId] = $result['document'];
        }

        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $limit);

        $results = [];
        foreach ($topIds as $docId) {
            if (isset($docMap[$docId])) {
                $results[] = [
                    'document' => $docMap[$docId],
                    'similarity' => $scores[$docId],
                ];
            }
        }

        return $results;
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        return \App\Support\VectorMath::cosineSimilarity($a, $b);
    }

    /**
     * Hybrid search combining semantic similarity + full-text search
     *
     * Uses Reciprocal Rank Fusion (RRF) to combine results from both methods.
     * Generally provides better results than pure semantic search.
     *
     */
    public function hybridSearch(string $query, int $limit = 5, ?string $documentType = null): array
    {
        try {
            // Get semantic search results
            $semanticResults = $this->search($query, $limit * 2, $documentType);

            // Get full-text search results using raw SQL (PostgreSQL only)
            $params = [$query, $query];
            $whereClause = '';
            if ($documentType) {
                $whereClause = 'AND document_type = ?';
                $params[] = $documentType;
            }
            $params[] = $limit * 2;

            $sql = "SELECT *,
                    ts_rank(to_tsvector('english', content), plainto_tsquery('english', ?)) as fts_rank
                    FROM rag_documents
                    WHERE to_tsvector('english', content) @@ plainto_tsquery('english', ?)
                    {$whereClause}
                    ORDER BY fts_rank DESC
                    LIMIT ?";

            $ftsResults = DB::connection('pgsql_rag')->select($sql, $params);

            // Reciprocal Rank Fusion (RRF) - combine rankings
            $k = 60; // RRF constant
            $scores = [];

            foreach ($semanticResults as $rank => $result) {
                $docId = $result['document']->id;
                $scores[$docId] = ($scores[$docId] ?? 0) + (1 / ($k + $rank + 1));
            }

            foreach ($ftsResults as $rank => $doc) {
                $docId = $doc->id;
                $scores[$docId] = ($scores[$docId] ?? 0) + (1 / ($k + $rank + 1));
            }

            // Sort by combined score and get top results
            arsort($scores);
            $topIds = array_slice(array_keys($scores), 0, $limit);

            // Fetch final documents using raw SQL
            if (empty($topIds)) {
                $results = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($topIds), '?'));
                $sql = "SELECT * FROM rag_documents WHERE id IN ({$placeholders})";
                $documents = DB::connection('pgsql_rag')->select($sql, $topIds);

                $results = array_map(function ($doc) use ($scores) {
                    return [
                        'document' => $doc,
                        'similarity' => $scores[$doc->id],
                    ];
                }, $documents);

                // Sort by similarity descending
                usort($results, function($a, $b) {
                    return $b['similarity'] <=> $a['similarity'];
                });
            }

            Log::info('RAG hybrid search completed', [
                'query' => substr($query, 0, 100),
                'results_count' => count($results),
            ]);

            return $results;
        } catch (\Exception $e) {
            Log::error('RAG hybrid search failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);
            throw $e;
        }
    }

    /**
     * Find documents similar to a given document
     */
    public function findSimilar(int $documentId, int $limit = 5): array
    {
        // Get document using raw SQL
        $sql = "SELECT * FROM rag_documents WHERE id = ? LIMIT 1";
        $documents = DB::connection('pgsql_rag')->select($sql, [$documentId]);

        if (empty($documents)) {
            throw new \Exception("Document not found: {$documentId}");
        }

        $document = $documents[0];
        $documentEmbedding = json_decode($document->embedding, true);

        if (!$documentEmbedding) {
            return [];
        }

        // PostgreSQL with pgvector
        $embeddingStr = $this->toVectorLiteral($documentEmbedding);

        $sql = "SELECT *,
                (embedding <=> '{$embeddingStr}'::vector) as distance,
                1 - (embedding <=> '{$embeddingStr}'::vector) as similarity
                FROM rag_documents
                WHERE id != ? AND document_type = ?
                ORDER BY distance ASC
                LIMIT ?";

        $docs = DB::connection('pgsql_rag')->select($sql, [$documentId, $document->document_type, $limit]);

        $results = array_map(function ($doc) {
            return [
                'document' => $doc,
                'similarity' => (float) $doc->similarity,
            ];
        }, $docs);

        return $results;
    }

    /**
     * Bulk index multiple documents
     */
    public function bulkIndex(array $documents): array
    {
        $indexed = [];
        foreach ($documents as $doc) {
            try {
                $indexed[] = $this->indexDocument(
                    $doc['document_type'],
                    $doc['content'],
                    $doc['title'] ?? null,
                    $doc['metadata'] ?? null,
                    $doc['source_id'] ?? null,
                    $doc['source_type'] ?? null
                );
            } catch (\Exception $e) {
                Log::error('Bulk indexing failed for document', [
                    'error' => $e->getMessage(),
                    'title' => $doc['title'] ?? 'untitled',
                ]);
            }
        }
        return $indexed;
    }

    /**
     * Index a document using semantic chunking
     *
     * Splits the document into semantically coherent chunks based on topic shifts,
     * then indexes each chunk separately. This improves retrieval accuracy by
     * ~70% compared to fixed-size chunking.
     *
     * Each chunk is stored with:
     * - parent_id linking to first chunk (for document reconstruction)
     * - chunk_index in metadata for ordering
     * - Same source_id/source_type as parent for filtering
     *
     * @param string $documentType Type of document
     * @param string $content Full document content
     * @param string|null $title Document title
     * @param array|null $metadata Additional metadata
     * @param string|int|null $sourceId Source identifier
     * @param string|null $sourceType Source type class
     * @param string|null $designation Document designation
     * @param string|null $mediaUrl Media URL for source
     * @param array $chunkOptions Semantic chunking options (see SemanticChunkerService::chunk)
     * @return array Array of indexed RAGDocument instances
     */
    public function indexDocumentWithSemanticChunking(
        string $documentType,
        string $content,
        ?string $title = null,
        ?array $metadata = null,
        string|int|null $sourceId = null,
        ?string $sourceType = null,
        ?string $designation = null,
        ?string $mediaUrl = null,
        array $chunkOptions = [],
        array $options = []
    ): array {
        $startTime = microtime(true);
        $trimmedContent = trim($content);

        if (empty($trimmedContent)) {
            throw new Exception("Cannot index document with empty content");
        }

        // Semantic dedup check on full document before chunking
        if (empty($options['skip_dedup'])) {
            $dedupResult = $this->getDedupService()->checkDuplicate(
                $trimmedContent, $title, $sourceType, $sourceId, $options
            );
            if ($dedupResult['is_duplicate'] && $dedupResult['action'] === 'block') {
                Log::info('RAG: Chunked document blocked by dedup', [
                    'strategy' => $dedupResult['strategy'],
                    'matched_id' => $dedupResult['matched_document_id'],
                ]);
                $existing = $this->findRagDocument($dedupResult['matched_document_id']);
                return $existing ? [$existing] : [];
            }
        }

        // Check if content is short enough to index as single document
        $minContentForChunking = $chunkOptions['min_content_for_chunking'] ?? 1000;
        if (strlen($trimmedContent) < $minContentForChunking) {
            Log::debug('RAG: Content too short for semantic chunking, indexing as single document', [
                'length' => strlen($trimmedContent),
                'threshold' => $minContentForChunking,
            ]);
            return [$this->indexDocument(
                $documentType,
                $trimmedContent,
                $title,
                $metadata,
                $sourceId,
                $sourceType,
                $designation,
                $mediaUrl
            )];
        }

        // Perform semantic chunking
        $chunks = $this->getSemanticChunker()->chunk($trimmedContent, $chunkOptions);

        if (empty($chunks)) {
            Log::warning('RAG: Semantic chunking produced no chunks, indexing as single document');
            return [$this->indexDocument(
                $documentType,
                $trimmedContent,
                $title,
                $metadata,
                $sourceId,
                $sourceType,
                $designation,
                $mediaUrl
            )];
        }

        $indexedDocuments = [];
        $parentId = null;

        foreach ($chunks as $index => $chunkContent) {
            try {
                // Build chunk-specific metadata
                $chunkMetadata = $metadata ?? [];
                $chunkMetadata['chunk_index'] = $index;
                $chunkMetadata['total_chunks'] = count($chunks);
                $chunkMetadata['chunking_method'] = 'semantic';

                if ($parentId !== null) {
                    $chunkMetadata['parent_id'] = $parentId;
                }

                // Generate chunk title
                $chunkTitle = $title;
                if (count($chunks) > 1) {
                    $chunkTitle = ($title ?? 'Document') . " [Part " . ($index + 1) . "/" . count($chunks) . "]";
                }

                // Generate embedding for this chunk
                $result = $this->aiService->generateEmbedding($chunkContent);
                if (!$result['success']) {
                    Log::warning('RAG: Failed to embed chunk', [
                        'chunk_index' => $index,
                        'error' => $result['error'] ?? 'unknown',
                    ]);
                    continue;
                }

                $embeddingStr = $this->toVectorLiteral($result['embedding']);

                // Insert chunk
                $now = now()->toDateTimeString();
                $insertResult = DB::connection('pgsql_rag')->select(
                    "INSERT INTO rag_documents (document_type, title, content, embedding, metadata, source_id, source_type, designation, media_url, parent_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?::vector, ?, ?, ?, ?, ?, ?, ?, ?)
                     RETURNING id",
                    [
                        $documentType,
                        $chunkTitle,
                        $chunkContent,
                        $embeddingStr,
                        json_encode($chunkMetadata),
                        $sourceId,
                        $sourceType,
                        $designation ?? $documentType,
                        $mediaUrl,
                        $parentId,
                        $now,
                        $now,
                    ]
                );
                $documentId = $insertResult[0]->id;

                // First chunk becomes the parent
                if ($parentId === null) {
                    $parentId = $documentId;
                }

                $indexedDocuments[] = $this->findRagDocument($documentId);

            } catch (\Exception $e) {
                Log::error('RAG: Failed to index chunk', [
                    'chunk_index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        Log::info('RAG: Semantic chunking indexing completed', [
            'document_type' => $documentType,
            'source_id' => $sourceId,
            'original_length' => strlen($trimmedContent),
            'chunks_created' => count($indexedDocuments),
            'duration_ms' => $durationMs,
        ]);

        return $indexedDocuments;
    }

    /**
     * Re-index an existing document using semantic chunking
     *
     * Deletes the original document and re-indexes with semantic chunks.
     * Useful for migrating from fixed-size to semantic chunking.
     *
     * @param int $documentId ID of document to re-chunk
     * @param array $chunkOptions Semantic chunking options
     * @return array Array of new chunk documents
     */
    public function rechunkDocument(int $documentId, array $chunkOptions = []): array
    {
        // Fetch original document
        $sql = "SELECT * FROM rag_documents WHERE id = ? LIMIT 1";
        $docs = DB::connection('pgsql_rag')->select($sql, [$documentId]);

        if (empty($docs)) {
            throw new Exception("Document not found: {$documentId}");
        }

        $original = $docs[0];

        // Delete the original
        DB::connection('pgsql_rag')->delete("DELETE FROM rag_documents WHERE id = ?", [$documentId]);

        // Also delete any existing chunks (children)
        DB::connection('pgsql_rag')->delete("DELETE FROM rag_documents WHERE parent_id = ?", [$documentId]);

        // Re-index with semantic chunking
        $metadata = $original->metadata ? json_decode($original->metadata, true) : [];
        $metadata['rechunked_from'] = $documentId;
        $metadata['rechunked_at'] = now()->toIso8601String();

        return $this->indexDocumentWithSemanticChunking(
            $original->document_type,
            $original->content,
            $original->title,
            $metadata,
            $original->source_id,
            $original->source_type,
            $original->designation,
            $original->media_url,
            $chunkOptions
        );
    }

    /**
     * Index a document with Contextual Retrieval (Anthropic pattern)
     *
     * Combines semantic chunking with contextual embeddings for 67% better retrieval.
     * Each chunk gets a context prefix generated by LLM before embedding.
     *
     * Process:
     * 1. Semantic chunking to split document
     * 2. For each chunk, generate context explaining its role in document
     * 3. Embed contextualized content (context + chunk)
     * 4. Store context_prefix and contextualized_at for tracking
     *
     * @param string $documentType Type of document
     * @param string $content Full document content
     * @param string|null $title Document title
     * @param array|null $metadata Additional metadata
     * @param string|int|null $sourceId Source identifier
     * @param string|null $sourceType Source type class
     * @param string|null $designation Document designation
     * @param string|null $mediaUrl Media URL
     * @param array $options Options:
     *   - enable_contextual: bool (default true) - Enable contextual retrieval
     *   - chunk_options: array - Options for SemanticChunkerService
     *   - context_options: array - Options for ContextualRetrievalService
     * @return array Array of indexed RAGDocument instances
     */
    public function contextualizeAndIndex(
        string $documentType,
        string $content,
        ?string $title = null,
        ?array $metadata = null,
        string|int|null $sourceId = null,
        ?string $sourceType = null,
        ?string $designation = null,
        ?string $mediaUrl = null,
        array $options = []
    ): array {
        $startTime = microtime(true);
        $enableContextual = $options['enable_contextual'] ?? true;
        $chunkOptions = $options['chunk_options'] ?? [];
        $contextOptions = $options['context_options'] ?? [];

        $trimmedContent = trim($content);
        if (empty($trimmedContent)) {
            throw new Exception("Cannot index document with empty content");
        }

        // Semantic dedup check on full document before contextual chunking
        if (empty($options['skip_dedup'])) {
            $dedupResult = $this->getDedupService()->checkDuplicate(
                $trimmedContent, $title, $sourceType, $sourceId, $options
            );
            if ($dedupResult['is_duplicate'] && $dedupResult['action'] === 'block') {
                Log::info('RAG: Contextual document blocked by dedup', [
                    'strategy' => $dedupResult['strategy'],
                    'matched_id' => $dedupResult['matched_document_id'],
                ]);
                $existing = $this->findRagDocument($dedupResult['matched_document_id']);
                return $existing ? [$existing] : [];
            }
        }

        // Check if content is short enough to index as single document
        $minContentForChunking = $chunkOptions['min_content_for_chunking'] ?? 1000;
        if (strlen($trimmedContent) < $minContentForChunking) {
            Log::debug('RAG: Content too short for contextual chunking, indexing as single document', [
                'length' => strlen($trimmedContent),
            ]);
            return [$this->indexDocument(
                $documentType,
                $trimmedContent,
                $title,
                $metadata,
                $sourceId,
                $sourceType,
                $designation,
                $mediaUrl
            )];
        }

        // Step 1: Semantic chunking
        $chunks = $this->getSemanticChunker()->chunk($trimmedContent, $chunkOptions);

        if (empty($chunks)) {
            Log::warning('RAG: Semantic chunking produced no chunks');
            return [$this->indexDocument(
                $documentType, $trimmedContent, $title, $metadata,
                $sourceId, $sourceType, $designation, $mediaUrl
            )];
        }

        // Step 2: Generate contexts for all chunks (if enabled)
        $contextualizedChunks = [];
        if ($enableContextual) {
            $contextualizedChunks = $this->getContextualRetrieval()->batchContextualize(
                $trimmedContent,
                $chunks,
                $contextOptions
            );
        } else {
            // No contextualization - use chunks as-is
            foreach ($chunks as $chunk) {
                $contextualizedChunks[] = [
                    'context' => '',
                    'original_chunk' => $chunk,
                    'contextualized' => $chunk,
                ];
            }
        }

        // Step 3: Index each chunk
        $indexedDocuments = [];
        $parentId = null;

        foreach ($contextualizedChunks as $index => $contextData) {
            try {
                $chunkContent = $contextData['original_chunk'];
                $contextPrefix = $contextData['context'];
                $embeddingContent = $contextData['contextualized'];

                // Build chunk-specific metadata
                $chunkMetadata = $metadata ?? [];
                $chunkMetadata['chunk_index'] = $index;
                $chunkMetadata['total_chunks'] = count($chunks);
                $chunkMetadata['chunking_method'] = 'semantic';
                $chunkMetadata['contextualized'] = $enableContextual;

                if ($parentId !== null) {
                    $chunkMetadata['parent_id'] = $parentId;
                }

                // Generate chunk title
                $chunkTitle = $title;
                if (count($chunks) > 1) {
                    $chunkTitle = ($title ?? 'Document') . " [Part " . ($index + 1) . "/" . count($chunks) . "]";
                }

                // Generate embedding from contextualized content
                $result = $this->aiService->generateEmbedding($embeddingContent);
                if (!$result['success']) {
                    Log::warning('RAG: Failed to embed contextualized chunk', [
                        'chunk_index' => $index,
                        'error' => $result['error'] ?? 'unknown',
                    ]);
                    continue;
                }

                $embeddingStr = $this->toVectorLiteral($result['embedding']);

                // Insert chunk with context_prefix
                $now = now()->toDateTimeString();
                $insertResult = DB::connection('pgsql_rag')->select(
                    "INSERT INTO rag_documents (document_type, title, content, embedding, metadata, source_id, source_type, designation, media_url, parent_id, context_prefix, contextualized_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?::vector, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     RETURNING id",
                    [
                        $documentType,
                        $chunkTitle,
                        $chunkContent,
                        $embeddingStr,
                        json_encode($chunkMetadata),
                        $sourceId,
                        $sourceType,
                        $designation ?? $documentType,
                        $mediaUrl,
                        $parentId,
                        !empty($contextPrefix) ? $contextPrefix : null,
                        $enableContextual ? $now : null,
                        $now,
                        $now,
                    ]
                );
                $documentId = $insertResult[0]->id;

                // First chunk becomes the parent
                if ($parentId === null) {
                    $parentId = $documentId;
                }

                $indexedDocuments[] = $this->findRagDocument($documentId);

            } catch (\Exception $e) {
                Log::error('RAG: Failed to index contextualized chunk', [
                    'chunk_index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        Log::info('RAG: Contextual retrieval indexing completed', [
            'document_type' => $documentType,
            'source_id' => $sourceId,
            'original_length' => strlen($trimmedContent),
            'chunks_created' => count($indexedDocuments),
            'contextualized' => $enableContextual,
            'duration_ms' => $durationMs,
        ]);

        return $indexedDocuments;
    }

    /**
     * Delete documents by type or source
     */
    public function deleteDocuments(?string $documentType = null, ?int $sourceId = null): int
    {
        $conditions = [];
        $params = [];

        if ($documentType) {
            $conditions[] = 'document_type = ?';
            $params[] = $documentType;
        }

        if ($sourceId) {
            $conditions[] = 'source_id = ?';
            $params[] = $sourceId;
        }

        $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql = "DELETE FROM rag_documents {$whereClause}";

        return DB::connection('pgsql_rag')->delete($sql, $params);
    }

    /**
     * Get full document content for specified IDs
     *
     * Used for deep RAG retrieval where we need complete document content
     * rather than just previews for AI synthesis.
     *
     * @param array $ids Document IDs to retrieve
     * @param int|null $maxContentLength Optional limit per document (null = unlimited)
     * @return array Documents with full content
     */
    public function getFullDocuments(array $ids, ?int $maxContentLength = null): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Exclude embedding field to reduce memory usage
        $sql = "SELECT id, document_type, title, content, metadata, source_id, source_type,
                       created_at, updated_at, designation, parent_id, content_hash,
                       last_synced_at, media_url
                FROM rag_documents
                WHERE id IN ({$placeholders})";

        $documents = DB::connection('pgsql_rag')->select($sql, $ids);

        // Optionally truncate content if max length specified
        if ($maxContentLength !== null) {
            foreach ($documents as $doc) {
                if (strlen($doc->content) > $maxContentLength) {
                    $doc->content = substr($doc->content, 0, $maxContentLength) . "\n\n[... content truncated at {$maxContentLength} chars ...]";
                }
            }
        }

        // Preserve order from input IDs
        $docMap = [];
        foreach ($documents as $doc) {
            $docMap[$doc->id] = $doc;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($docMap[$id])) {
                $ordered[] = $docMap[$id];
            }
        }

        return $ordered;
    }

    /**
     * Deep search with full content retrieval
     *
     * Performs semantic search then fetches full content for top results.
     * Designed for AI synthesis tasks that need comprehensive context.
     *
     * @param string $query Search query
     * @param int $topN Number of top results to fetch full content for
     * @param int $maxContentPerDoc Max chars per document (prevents context explosion)
     * @param string|null $documentType Optional filter by document type
     * @param bool|string $useHyde Enable HyDE query expansion (true, false, or 'auto')
     * @return array ['results' => search results, 'full_documents' => full content docs]
     */
    public function deepSearch(
        string $query,
        int $topN = 5,
        int $maxContentPerDoc = 15000,
        ?string $documentType = null,
        bool|string $useHyde = false,
        bool $useRaptor = true,
        ?string $agentId = null,
        bool $useGraph = false,
        string $graphMode = 'local',
        float $graphAlpha = 0.5,
        bool $useHype = false,
        bool $useCrag = false,
        bool $useTemporal = false,
        bool $useRelevanceGating = false,
        bool $useColbert = false,
        bool $useIterative = false,
        bool $useAutoStrategy = false,
        bool $securityAudit = false,
        bool $useLazyGraph = false,
        bool $useLongContextRerank = false,
        bool $useMultimodal = false
    ): array {
        // RAG-12: Adaptive Strategy Selection — override all flags from query classification
        $selectedStrategy = null;
        if ($useAutoStrategy) {
            try {
                $strategyConfig  = $this->getStrategyService()->selectStrategy($query, $documentType);
                $selectedStrategy = $strategyConfig['strategy_name'];
                $useHyde             = $strategyConfig['useHyde'];
                $useRaptor           = $strategyConfig['useRaptor'];
                $useGraph            = $strategyConfig['useGraph'];
                $graphMode           = $strategyConfig['graphMode'];
                $graphAlpha          = $strategyConfig['graphAlpha'];
                $useHype             = $strategyConfig['useHype'];
                $useCrag             = $strategyConfig['useCrag'];
                $useTemporal         = $strategyConfig['useTemporal'];
                $useRelevanceGating  = $strategyConfig['useRelevanceGating'];
                $useColbert          = $strategyConfig['useColbert'];
                $useIterative        = $strategyConfig['useIterative'];
            } catch (\Exception $e) {
                Log::warning('RAG deep search: auto strategy selection failed, using caller flags', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // RAG-5: Query decomposition — break complex queries into sub-queries for better recall
        $decomposition = $this->getDecompositionService()->decompose($query);
        $subQueries = $decomposition['sub_queries'];

        if ($decomposition['decomposed']) {
            // Search each sub-query independently, merge results via RRF
            $allResults = [];
            $perSubQuery = max(3, (int) ceil($topN * 2 / count($subQueries)));

            foreach ($subQueries as $subIdx => $subQuery) {
                $subResults = $this->search($subQuery, $perSubQuery, $documentType, $useHyde, $agentId);
                foreach ($subResults as &$r) {
                    $r['sub_query'] = $subQuery;
                    $r['sub_query_rank'] = $subIdx;
                }
                unset($r);
                $allResults[] = $subResults;
            }

            // Also search the original query to avoid losing holistic matches
            $originalResults = $this->search($query, $topN, $documentType, $useHyde, $agentId);
            foreach ($originalResults as &$r) {
                $r['sub_query'] = $query;
                $r['sub_query_rank'] = -1; // Original gets priority
            }
            unset($r);
            $allResults[] = $originalResults;

            // Merge via RRF deduplication (by document ID)
            $searchResults = $this->mergeDecomposedResults($allResults, $topN * 2);
        } else {
            // Single query — standard path
            $searchResults = $this->search($query, $topN * 2, $documentType, $useHyde, $agentId);
        }

        // RAG-4: HyPE — blend hypothetical-question-matched chunks via RRF
        $hypeResults = [];
        if ($useHype) {
            try {
                $hypeResults = $this->getHyPEService()->search($query, $topN * 2, $documentType);
                if (!empty($hypeResults)) {
                    $searchResults = $this->mergeResultsRRF($searchResults, $hypeResults, $topN * 2);
                }
            } catch (\Exception $e) {
                Log::warning('RAG deep search: HyPE search failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // RAG-2: CRAG — evaluate retrieval quality, web fallback on poor results
        $cragEvaluation = null;
        $cragWebResults = [];
        if ($useCrag && !empty($searchResults)) {
            try {
                $cragService    = $this->getCRAGService();
                $cragEvaluation = $cragService->evaluateRetrieval($query, $searchResults);
                if ($cragEvaluation['web_fallback_needed']) {
                    $cragWebResults = $cragService->webFallback($query, $topN);
                    $searchResults  = $cragService->merge($searchResults, $cragWebResults, $cragEvaluation['classification']);
                }
            } catch (\Exception $e) {
                Log::warning('RAG deep search: CRAG evaluation failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // RAG-6: Relevance Gating — per-document LLM relevance filter before RAPTOR/rerank
        $relevanceFilteredCount = 0;
        if ($useRelevanceGating && !empty($searchResults)) {
            try {
                $gated                  = $this->getRelevanceGatingService()->gateResults($query, $searchResults);
                $searchResults          = $gated['results'];
                $relevanceFilteredCount = $gated['filtered_count'];
            } catch (\Exception $e) {
                Log::warning('RAG deep search: relevance gating failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // RAG-8: Iterative retrieval — fill knowledge gaps via CoRAG-style gap queries
        $iterativeRounds    = 0;
        $iterativeGapQueries = [];
        if ($useIterative && !empty($searchResults)) {
            try {
                $searchFn = fn(string $subQuery) => $this->search(
                    $subQuery, $topN, $documentType, $useHyde, $agentId
                );
                $iterResult          = $this->getIterativeService()->retrieve($query, $searchResults, $searchFn);
                $searchResults        = $iterResult['results'];
                $iterativeRounds      = $iterResult['rounds_used'];
                $iterativeGapQueries  = $iterResult['gap_queries'];
            } catch (\Exception $e) {
                Log::warning('RAG deep search: iterative retrieval failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Blend in RAPTOR summary results if enabled
        $raptorResults = [];
        if ($useRaptor) {
            try {
                $raptorSearch = $this->getRaptorService()->search($query, $topN);
                // Collect document IDs from summary matches to expand to source chunks
                $raptorDocIds = [];
                foreach ($raptorSearch['levels'] ?? [] as $levelName => $levelResults) {
                    foreach ($levelResults as $summary) {
                        $raptorDocIds[] = $summary->document_id;
                        $raptorResults[] = [
                            'raptor_level' => $levelName,
                            'summary' => $summary->summary_text,
                            'similarity' => $summary->similarity ?? 0,
                            'document_id' => $summary->document_id,
                            'document_title' => $summary->document_title ?? null,
                        ];
                    }
                }

                // Boost search results from RAPTOR-matched documents
                $raptorDocIds = array_unique($raptorDocIds);
                foreach ($searchResults as &$result) {
                    if (in_array($result['document']->id, $raptorDocIds) ||
                        in_array($result['document']->parent_id ?? 0, $raptorDocIds)) {
                        $result['raptor_boost'] = true;
                        $result['similarity'] = min(($result['similarity'] ?? 0) + 0.05, 1.0);
                    }
                }
                unset($result);

                // Re-sort by boosted similarity
                usort($searchResults, fn($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));
            } catch (\Exception $e) {
                Log::warning('RAG deep search: RAPTOR search failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // GR-9: LazyGraphRAG — on-the-fly mini-KG from retrieved docs
        $lazyGraphEnabled  = false;
        $lazyBridgeEntities = [];
        if ($useLazyGraph && !empty($searchResults)) {
            try {
                $lazyOut = $this->getLazyGraphService()->augment($query, $searchResults);
                $searchResults      = $lazyOut['results'];
                $lazyBridgeEntities = $lazyOut['bridge_entities'];
                $lazyGraphEnabled   = true;
            } catch (\Exception $e) {
                Log::warning('RAG deep search: lazy graph augment failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // RAG-15: Multimodal — append visual search results (image_embedding + description FTS)
        $multimodalResults = [];
        if ($useMultimodal) {
            try {
                $visualOut = $this->getMultimodalService()->searchVisual($query, $topN);
                if (($visualOut['success'] ?? false) && !empty($visualOut['results'])) {
                    // Merge: deduplicate by document ID, keep highest similarity
                    $existingIds = array_flip(array_map(
                        fn($r) => $r['document']->id ?? 0,
                        $searchResults
                    ));
                    foreach ($visualOut['results'] as $vr) {
                        $vid = $vr['document']->id ?? 0;
                        if (!isset($existingIds[$vid])) {
                            $vr['search_type']   = 'visual';
                            $searchResults[]     = $vr;
                            $multimodalResults[] = $vr;
                            $existingIds[$vid]   = true;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('RAG deep search: multimodal search failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Blend in graph search results if enabled (GraphRAG Phase 2)
        $graphResults = [];
        $graphRaw = [];
        if ($useGraph) {
            try {
                $graphSearch = $this->getGraphSearchService();
                $graphRaw = match ($graphMode) {
                    'global' => $graphSearch->globalSearch($query, $topN),
                    'drift' => $graphSearch->driftSearch($query, $topN * 2),
                    default => $graphSearch->localSearch($query, $topN * 2),
                };

                if (!empty($graphRaw)) {
                    // Fuse vector + graph results via RRF
                    $searchResults = $this->getGraphFusionService()->fuse(
                        $searchResults,
                        $graphRaw,
                        $graphAlpha,
                        $topN * 2
                    );

                    // Capture graph metadata for response
                    $graphResults = array_map(fn($r) => [
                        'graph_source' => $r['graph_source'] ?? $graphMode,
                        'similarity' => $r['similarity'] ?? 0,
                        'entities' => $r['graph_entities'] ?? [],
                        'report' => $r['report'] ?? null,
                    ], $graphRaw);
                }
            } catch (\Exception $e) {
                Log::warning('RAG deep search: graph search failed, continuing without', [
                    'error' => $e->getMessage(),
                    'graph_mode' => $graphMode,
                ]);
            }
        }

        if (empty($searchResults)) {
            return [
                'results' => [],
                'full_documents' => [],
                'total_chars' => 0,
                'raptor_results' => $raptorResults,
                'graph_results' => $graphResults,
            ];
        }

        // Get IDs of top N results — skip web pseudo-docs (id=0)
        $topSlice = array_slice($searchResults, 0, $topN);
        $topIds   = array_values(array_filter(
            array_map(fn($r) => $r['document']->id, $topSlice),
            fn($id) => $id > 0
        ));

        // Fetch full content for persisted RAG docs
        $fullDocs = $this->getFullDocuments($topIds, $maxContentPerDoc);

        // Append web pseudo-docs to full_documents (they carry their own content)
        foreach ($topSlice as $r) {
            if (($r['document']->id ?? 1) === 0) {
                $doc = $r['document'];
                if ($maxContentPerDoc !== null && strlen($doc->content) > $maxContentPerDoc) {
                    $doc->content = substr($doc->content, 0, $maxContentPerDoc);
                }
                $fullDocs[] = $doc;
            }
        }

        // Apply AI reranking for deep search (high-value queries)
        if (count($searchResults) >= 3) {
            $searchResults = $this->getReranker()->rerank($query, $searchResults, 'ai');
        }

        // RAG-7: ColBERT late interaction — MaxSim rerank using sentence embeddings
        if ($useColbert && count($searchResults) >= 2) {
            try {
                $searchResults = $this->getColBERTService()->rerank($query, $searchResults);
            } catch (\Exception $e) {
                Log::warning('RAG deep search: ColBERT rerank failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Phase 3C: Apply graph centrality boost after reranking
        if ($useGraph && !empty($graphRaw)) {
            $searchResults = $this->applyGraphBoost($searchResults, $graphRaw);
        }

        // RAG-11: Temporal scoring — recency-weighted re-sort for time-sensitive queries
        $temporalApplied = false;
        if ($useTemporal) {
            $temporalService = $this->getTemporalService();
            if ($temporalService->isTemporalQuery($query)) {
                $searchResults   = $temporalService->applyDecay($searchResults);
                $temporalApplied = true;
            }
        }

        // RAG-13: Security audit — anomaly detection (advisory, never filters results)
        $securityAuditResult = null;
        if ($securityAudit && !empty($searchResults)) {
            try {
                $securityAuditResult = $this->getSecurityService()->auditResults($query, $searchResults);
            } catch (\Exception $e) {
                Log::warning('RAG deep search: security audit failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // RAG-16: Long Context Reranking — sliding-window score for long docs
        $longContextApplied = false;
        if ($useLongContextRerank && !empty($searchResults)) {
            try {
                $searchResults      = $this->getLongContextRerankService()->rerank($query, $searchResults);
                $longContextApplied = true;
            } catch (\Exception $e) {
                Log::warning('RAG deep search: long-context rerank failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Calculate total content size
        $totalChars = array_sum(array_map(fn($d) => strlen($d->content), $fullDocs));

        Log::info('RAG deep search completed', [
            'query' => substr($query, 0, 100),
            'candidates' => count($searchResults),
            'full_docs_fetched' => count($fullDocs),
            'total_content_chars' => $totalChars,
            'ai_reranked' => count($searchResults) >= 3,
            'hyde_mode' => $useHyde,
            'raptor_enabled' => $useRaptor,
            'raptor_matches' => count($raptorResults),
            'graph_enabled' => $useGraph,
            'graph_mode' => $graphMode,
            'graph_matches' => count($graphResults),
            'graph_boosted' => $useGraph && !empty($graphRaw),
            'hype_enabled'        => $useHype,
            'hype_matches'        => count($hypeResults),
            'crag_enabled'        => $useCrag,
            'crag_classification' => $cragEvaluation['classification'] ?? null,
            'crag_web_count'      => count($cragWebResults),
            'temporal_enabled'        => $useTemporal,
            'temporal_applied'        => $temporalApplied,
            'relevance_gating_enabled' => $useRelevanceGating,
            'relevance_filtered'      => $relevanceFilteredCount,
            'colbert_enabled'         => $useColbert,
            'iterative_enabled'       => $useIterative,
            'iterative_rounds'        => $iterativeRounds,
            'auto_strategy'           => $selectedStrategy,
            'security_audit_enabled'  => $securityAudit,
            'security_risk_score'     => $securityAuditResult['risk_score'] ?? null,
            'long_context_rerank'     => $longContextApplied,
            'multimodal_enabled'      => $useMultimodal,
            'multimodal_results'      => count($multimodalResults),
        ]);

        return [
            'results'            => $searchResults,
            'full_documents'     => $fullDocs,
            'total_chars'        => $totalChars,
            'raptor_results'     => $raptorResults,
            'graph_results'      => $graphResults,
            'hype_results'       => $hypeResults,
            'crag_web_results'         => $cragWebResults,
            'crag_classification'      => $cragEvaluation['classification'] ?? null,
            'relevance_filtered_count' => $relevanceFilteredCount,
            'iterative_gap_queries'   => $iterativeGapQueries,
            'security_audit'          => $securityAuditResult,
            'lazy_graph_enabled'      => $lazyGraphEnabled,
            'lazy_graph_bridges'      => $lazyBridgeEntities,
            'long_context_rerank'     => $longContextApplied,
            'multimodal_results'      => $multimodalResults,
        ];
    }

    /**
     * Apply graph centrality boost to reranked results (Phase 3C).
     * Boosts documents containing high-centrality KG entities.
     * Called after AI reranking when graph search is enabled.
     */
    private function applyGraphBoost(array $results, array $graphRaw): array
    {
        // Build document_id → graph signals map from raw graph results
        $graphDocMap = [];
        foreach ($graphRaw as $gr) {
            if (!isset($gr['document']) || !$gr['document']) {
                continue;
            }
            $docId = $gr['document']->id;
            $graphDocMap[$docId] = [
                'entities' => $gr['graph_entities'] ?? [],
                'min_hop' => $gr['graph_hop'] ?? 0,
                'max_pagerank' => $gr['similarity'] ?? 0,
            ];
        }

        if (empty($graphDocMap)) {
            return $results;
        }

        // Batch-fetch centrality data for all entities referenced in graph results
        $allEntityNames = [];
        foreach ($graphDocMap as $info) {
            foreach ($info['entities'] as $name) {
                $allEntityNames[$name] = true;
            }
        }

        $centralityMap = [];
        if (!empty($allEntityNames)) {
            $names = array_keys($allEntityNames);
            $placeholders = implode(',', array_fill(0, count($names), '?'));

            $entities = DB::connection('pgsql_rag')->select(
                "SELECT e.id, e.canonical_name, e.degree, e.pagerank, e.primary_community_id,
                        COALESCE(ec.is_bridge, false) AS is_bridge
                 FROM knowledge_graph_entities e
                 LEFT JOIN knowledge_graph_entity_communities ec
                    ON ec.entity_id = e.id AND ec.community_id = e.primary_community_id
                 WHERE LOWER(e.canonical_name) IN ({$placeholders})",
                array_map('strtolower', $names)
            );

            foreach ($entities as $e) {
                $centralityMap[strtolower($e->canonical_name)] = [
                    'degree' => (int) ($e->degree ?? 0),
                    'pagerank' => (float) ($e->pagerank ?? 0),
                    'is_bridge' => (bool) $e->is_bridge,
                ];
            }
        }

        // Apply boost to each result that appeared in graph results
        foreach ($results as &$result) {
            if (!isset($result['document'])) {
                continue;
            }
            $docId = $result['document']->id;
            if (!isset($graphDocMap[$docId])) {
                continue;
            }

            $gInfo = $graphDocMap[$docId];
            $minHop = $gInfo['min_hop'];

            // Compute per-entity centrality signals
            $maxPagerank = 0;
            $hasBridge = false;
            foreach ($gInfo['entities'] as $entityName) {
                $key = strtolower($entityName);
                if (isset($centralityMap[$key])) {
                    $maxPagerank = max($maxPagerank, $centralityMap[$key]['pagerank']);
                    if ($centralityMap[$key]['is_bridge']) {
                        $hasBridge = true;
                    }
                }
            }

            // Boost formula
            $hopDecay = 1.0 / (1 + $minHop);
            $centralityScore = min($maxPagerank * 5, 0.3);
            $bridgeBonus = $hasBridge ? 0.1 : 0.0;

            $graphBoost = ($hopDecay * 0.4) + ($centralityScore * 0.4) + ($bridgeBonus * 0.2);
            $graphBoost = min($graphBoost, 0.25); // Cap at 25%

            $baseScore = $result['rerank_score'] ?? $result['similarity'] ?? 0;
            $result['rerank_score'] = round($baseScore * (1 + $graphBoost), 4);
            $result['graph_boost'] = round($graphBoost, 4);
            $result['graph_boost_entities'] = $gInfo['entities'];
        }
        unset($result);

        // Re-sort by adjusted score
        usort($results, function ($a, $b) {
            $scoreA = $a['rerank_score'] ?? $a['similarity'] ?? 0;
            $scoreB = $b['rerank_score'] ?? $b['similarity'] ?? 0;
            return $scoreB <=> $scoreA;
        });

        return $results;
    }

    /**
     * Index a document and build RAPTOR hierarchy in one call
     *
     * @param string $documentType Type of document
     * @param string $content Document content
     * @param string|null $title Document title
     * @param array|null $metadata Additional metadata
     * @param string|int|null $sourceId Source identifier
     * @param string|null $sourceType Source type
     * @param string|null $designation Document designation
     * @return array ['document' => RAGDocument, 'raptor' => hierarchy stats]
     */
    public function indexDocumentWithRAPTOR(
        string $documentType,
        string $content,
        ?string $title = null,
        ?array $metadata = null,
        string|int|null $sourceId = null,
        ?string $sourceType = null,
        ?string $designation = null,
        array $options = []
    ): array {
        // Index the document normally (dedup handled inside indexDocument)
        $document = $this->indexDocument($documentType, $content, $title, $metadata, $sourceId, $sourceType, $designation, null, $options);

        // Build RAPTOR hierarchy
        $raptorStats = null;
        try {
            $raptorStats = $this->getRaptorService()->buildHierarchy($document->id);
        } catch (\Exception $e) {
            Log::warning('RAPTOR hierarchy build failed after indexing', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'document' => $document,
            'raptor' => $raptorStats,
        ];
    }

    /**
     * Get statistics about indexed documents
     */
    public function getStats(): array
    {
        // Get total count
        $sql = "SELECT COUNT(*) as count FROM rag_documents";
        $totalDocuments = DB::connection('pgsql_rag')->select($sql)[0]->count ?? 0;

        // Get count by document type
        $sql = "SELECT document_type, COUNT(*) as count FROM rag_documents GROUP BY document_type";
        $byTypeResults = DB::connection('pgsql_rag')->select($sql);
        $byType = [];
        foreach ($byTypeResults as $row) {
            $byType[$row->document_type] = $row->count;
        }

        // Get count by designation
        $sql = "SELECT designation, COUNT(*) as count FROM rag_documents GROUP BY designation";
        $byDesignationResults = DB::connection('pgsql_rag')->select($sql);
        $byDesignation = [];
        foreach ($byDesignationResults as $row) {
            $byDesignation[$row->designation] = $row->count;
        }

        // Get oldest created_at
        $sql = "SELECT created_at FROM rag_documents ORDER BY created_at ASC LIMIT 1";
        $oldest = DB::connection('pgsql_rag')->select($sql)[0]->created_at ?? null;

        // Get newest created_at
        $sql = "SELECT created_at FROM rag_documents ORDER BY created_at DESC LIMIT 1";
        $newest = DB::connection('pgsql_rag')->select($sql)[0]->created_at ?? null;

        return [
            'total_documents' => $totalDocuments,
            'by_type' => $byType,
            'by_designation' => $byDesignation,
            'oldest' => $oldest,
            'newest' => $newest,
        ];
    }
}

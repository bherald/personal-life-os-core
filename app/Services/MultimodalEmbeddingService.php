<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Multimodal Embedding Service for RAG
 *
 * Generates and manages visual embeddings alongside text embeddings:
 * - Uses AIService vision capabilities to analyze images
 * - Generates text descriptions of visual content
 * - Embeds descriptions using nomic-embed-text for vector similarity
 * - Enables hybrid text+visual search across documents
 *
 * Architecture:
 * - Image -> AIService::processImage() -> Description text
 * - Description -> AIService::generateEmbedding() -> vector(768)
 * - Stored in rag_documents.image_embedding + image_description
 */
class MultimodalEmbeddingService
{
    private AIService $aiService;
    private ?RAGService $ragService = null;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Get or create RAGService instance (lazy loaded to avoid circular dependency)
     */
    private function getRAGService(): RAGService
    {
        if ($this->ragService === null) {
            $this->ragService = new RAGService($this->aiService);
        }
        return $this->ragService;
    }

    /**
     * Generate embedding for an image by first generating a description
     *
     * Process:
     * 1. Read image from path
     * 2. Use AIService vision to generate detailed description
     * 3. Embed the description using nomic-embed-text
     *
     * @param string $imagePath Path to image file
     * @param array $options Options:
     *   - detail_level: 'brief'|'detailed'|'comprehensive' (default: 'detailed')
     *   - focus: string - Specific aspects to focus on (e.g., 'faces', 'text', 'objects')
     * @return array ['success' => bool, 'embedding' => array|null, 'description' => string|null, 'error' => string|null]
     */
    public function generateImageEmbedding(string $imagePath, array $options = []): array
    {
        $startTime = microtime(true);

        if (!file_exists($imagePath)) {
            return [
                'success' => false,
                'embedding' => null,
                'description' => null,
                'error' => "Image file not found: {$imagePath}",
            ];
        }

        try {
            // Read image content
            $imageContent = file_get_contents($imagePath);
            if ($imageContent === false) {
                return [
                    'success' => false,
                    'embedding' => null,
                    'description' => null,
                    'error' => "Failed to read image file: {$imagePath}",
                ];
            }

            // Build vision prompt based on options
            $detailLevel = $options['detail_level'] ?? 'detailed';
            $focus = $options['focus'] ?? null;
            $prompt = $this->buildVisionPrompt($detailLevel, $focus);

            // Generate description using vision AI
            $visionResult = $this->aiService->processImage($imageContent, $prompt, [
                'suppressAlert' => true, // We'll handle errors ourselves
            ]);

            if (!$visionResult['success']) {
                return [
                    'success' => false,
                    'embedding' => null,
                    'description' => null,
                    'error' => 'Vision analysis failed: ' . ($visionResult['error'] ?? 'unknown'),
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
                ];
            }

            $description = trim($visionResult['response']);

            if (empty($description)) {
                return [
                    'success' => false,
                    'embedding' => null,
                    'description' => null,
                    'error' => 'Vision analysis returned empty description',
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
                ];
            }

            // Generate embedding from description
            $embeddingResult = $this->aiService->generateEmbedding($description);

            if (!$embeddingResult['success']) {
                return [
                    'success' => false,
                    'embedding' => null,
                    'description' => $description,
                    'error' => 'Embedding generation failed: ' . ($embeddingResult['error'] ?? 'unknown'),
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
                ];
            }

            Log::info('MultimodalEmbedding: Generated image embedding', [
                'image_path' => basename($imagePath),
                'description_length' => strlen($description),
                'provider' => $visionResult['provider'] ?? 'unknown',
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => true,
                'embedding' => $embeddingResult['embedding'],
                'description' => $description,
                'error' => null,
                'vision_provider' => $visionResult['provider'] ?? null,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];

        } catch (Exception $e) {
            Log::error('MultimodalEmbedding: Failed to generate image embedding', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'embedding' => null,
                'description' => null,
                'error' => $e->getMessage(),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Build vision analysis prompt based on detail level and focus
     */
    private function buildVisionPrompt(string $detailLevel, ?string $focus = null): string
    {
        $basePrompts = [
            'brief' => "Describe this image in 2-3 sentences, focusing on the main subject and key visual elements.",
            'detailed' => "Provide a detailed description of this image including:\n- Main subjects and their positions\n- Colors, lighting, and atmosphere\n- Any text visible in the image\n- Notable objects, people, or scenery\n- Overall context and mood",
            'comprehensive' => "Provide a comprehensive analysis of this image:\n1. SUBJECTS: Describe all people, objects, and elements visible\n2. COMPOSITION: Layout, framing, perspective\n3. COLORS & LIGHTING: Color palette, light sources, shadows\n4. TEXT: Any text, labels, signs, or writing\n5. CONTEXT: Setting, time period, purpose\n6. DETAILS: Fine details, textures, patterns\n7. MOOD: Emotional tone and atmosphere\n8. TECHNICAL: Photo type (portrait, landscape, document, etc.)",
        ];

        $prompt = $basePrompts[$detailLevel] ?? $basePrompts['detailed'];

        if ($focus) {
            $prompt .= "\n\nPay special attention to: {$focus}";
        }

        $prompt .= "\n\nProvide your description in plain text without markdown formatting.";

        return $prompt;
    }

    /**
     * Analyze and embed visual content for an existing RAG document
     *
     * Fetches the document, analyzes its associated image (from media_url or metadata),
     * generates embedding, and updates the document record.
     *
     * @param int $documentId RAG document ID to analyze
     * @param array $options Options:
     *   - image_path: string - Override path to image (instead of using media_url)
     *   - force: bool - Re-analyze even if already analyzed
     * @return array ['success' => bool, 'document_id' => int, 'description' => string|null, 'error' => string|null]
     */
    public function analyzeAndEmbed(int $documentId, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            // Fetch document
            $sql = "SELECT id, document_type, title, media_url, metadata, has_visual_content, visual_analyzed_at
                    FROM rag_documents WHERE id = ? LIMIT 1";
            $docs = DB::connection('pgsql_rag')->select($sql, [$documentId]);

            if (empty($docs)) {
                return [
                    'success' => false,
                    'document_id' => $documentId,
                    'description' => null,
                    'error' => "Document not found: {$documentId}",
                ];
            }

            $doc = $docs[0];

            // Check if already analyzed (unless force=true)
            $force = $options['force'] ?? false;
            if (!$force && $doc->visual_analyzed_at) {
                return [
                    'success' => true,
                    'document_id' => $documentId,
                    'description' => null,
                    'error' => null,
                    'skipped' => true,
                    'reason' => 'Already analyzed at ' . $doc->visual_analyzed_at,
                ];
            }

            // Determine image path
            $imagePath = $options['image_path'] ?? null;

            if (!$imagePath && $doc->media_url) {
                // Try to resolve media_url to local path
                $imagePath = $this->resolveMediaUrl($doc->media_url);
            }

            if (!$imagePath && $doc->metadata) {
                // Try to get path from metadata
                $metadata = json_decode($doc->metadata, true);
                $imagePath = $metadata['image_path'] ?? $metadata['file_path'] ?? null;
            }

            if (!$imagePath || !file_exists($imagePath)) {
                return [
                    'success' => false,
                    'document_id' => $documentId,
                    'description' => null,
                    'error' => 'No valid image path found for document',
                ];
            }

            // Generate embedding
            $embeddingResult = $this->generateImageEmbedding($imagePath, $options);

            if (!$embeddingResult['success']) {
                return [
                    'success' => false,
                    'document_id' => $documentId,
                    'description' => null,
                    'error' => $embeddingResult['error'],
                    'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
                ];
            }

            // Update document with visual data
            $embeddingStr = PgVector::literal($embeddingResult['embedding']);

            $updateSql = "UPDATE rag_documents SET
                          image_embedding = ?::vector,
                          image_description = ?,
                          has_visual_content = TRUE,
                          visual_analyzed_at = NOW(),
                          updated_at = NOW()
                          WHERE id = ?";

            DB::connection('pgsql_rag')->update($updateSql, [
                $embeddingStr,
                $embeddingResult['description'],
                $documentId,
            ]);

            Log::info('MultimodalEmbedding: Document visual analysis complete', [
                'document_id' => $documentId,
                'document_type' => $doc->document_type,
                'description_length' => strlen($embeddingResult['description']),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => true,
                'document_id' => $documentId,
                'description' => $embeddingResult['description'],
                'error' => null,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];

        } catch (Exception $e) {
            Log::error('MultimodalEmbedding: analyzeAndEmbed failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'document_id' => $documentId,
                'description' => null,
                'error' => $e->getMessage(),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Resolve media URL to local file path
     *
     * Handles Nextcloud WebDAV URLs and other common formats.
     */
    private function resolveMediaUrl(string $mediaUrl): ?string
    {
        // If it's already a local path, return it
        if (str_starts_with($mediaUrl, '/') && file_exists($mediaUrl)) {
            return $mediaUrl;
        }

        // Handle Nextcloud WebDAV URLs
        // Format: https://nextcloud.example.com/remote.php/dav/files/user/path/to/file
        if (str_contains($mediaUrl, '/remote.php/dav/files/')) {
            // Extract path after /files/username/
            if (preg_match('#/remote\.php/dav/files/[^/]+/(.+)$#', $mediaUrl, $matches)) {
                $relativePath = urldecode($matches[1]);

                // Try common Nextcloud data directories
                $possiblePaths = [
                    storage_path('nextcloud/' . $relativePath),
                    '/var/www/nextcloud/data/' . $relativePath,
                    rtrim(config('services.nextcloud.data_path', ''), '/') . '/' . ltrim($relativePath, '/'),
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        return $path;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Search for documents by text query against image descriptions
     *
     * Performs hybrid search:
     * 1. Embeds the query text
     * 2. Searches against image_embedding vectors
     * 3. Also searches image_description with full-text search
     * 4. Combines results using RRF
     *
     * @param string $query Text query describing what to find visually
     * @param int $limit Number of results to return
     * @param array $options Options:
     *   - document_type: string - Filter by document type
     *   - hybrid: bool - Use hybrid vector + FTS search (default: true)
     * @return array Search results with similarity scores
     */
    public function searchVisual(string $query, int $limit = 10, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            // Generate query embedding
            $embeddingResult = $this->aiService->generateEmbedding($query);

            if (!$embeddingResult['success']) {
                return [
                    'success' => false,
                    'results' => [],
                    'error' => 'Failed to generate query embedding: ' . ($embeddingResult['error'] ?? 'unknown'),
                ];
            }

            $queryEmbedding = $embeddingResult['embedding'];
            $embeddingStr = PgVector::literal($queryEmbedding);

            $useHybrid = $options['hybrid'] ?? true;
            $documentType = $options['document_type'] ?? null;

            // Build WHERE clause
            $conditions = ['has_visual_content = TRUE', 'image_embedding IS NOT NULL'];
            $params = [];

            if ($documentType) {
                $conditions[] = 'document_type = ?';
                $params[] = $documentType;
            }

            $whereClause = 'WHERE ' . implode(' AND ', $conditions);

            // Vector similarity search
            $vectorSql = "SELECT id, document_type, title, content, image_description, metadata,
                          source_id, source_type, media_url,
                          (image_embedding <=> '{$embeddingStr}'::vector) as distance,
                          1 - (image_embedding <=> '{$embeddingStr}'::vector) as similarity
                          FROM rag_documents
                          {$whereClause}
                          ORDER BY distance ASC
                          LIMIT ?";

            $params[] = $limit * 2; // Get more for RRF merge
            $vectorResults = DB::connection('pgsql_rag')->select($vectorSql, $params);

            // Full-text search on image descriptions (if hybrid enabled)
            $ftsResults = [];
            if ($useHybrid) {
                $ftsParams = [$query, $query];
                $ftsConditions = array_merge($conditions, [
                    "to_tsvector('english', COALESCE(image_description, '')) @@ plainto_tsquery('english', ?)"
                ]);
                $ftsWhereClause = 'WHERE ' . implode(' AND ', $ftsConditions);

                if ($documentType) {
                    $ftsParams[] = $documentType;
                }
                $ftsParams[] = $limit * 2;

                $ftsSql = "SELECT id, document_type, title, content, image_description, metadata,
                           source_id, source_type, media_url,
                           ts_rank(to_tsvector('english', COALESCE(image_description, '')), plainto_tsquery('english', ?)) as fts_rank
                           FROM rag_documents
                           {$ftsWhereClause}
                           ORDER BY fts_rank DESC
                           LIMIT ?";

                $ftsResults = DB::connection('pgsql_rag')->select($ftsSql, $ftsParams);
            }

            // Combine results using RRF
            $results = $this->mergeResultsRRF($vectorResults, $ftsResults, $limit);

            Log::info('MultimodalEmbedding: Visual search completed', [
                'query' => substr($query, 0, 100),
                'vector_results' => count($vectorResults),
                'fts_results' => count($ftsResults),
                'merged_results' => count($results),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => true,
                'results' => $results,
                'error' => null,
                'query' => $query,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];

        } catch (Exception $e) {
            Log::error('MultimodalEmbedding: Visual search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search by image - find similar images using embedding comparison
     *
     * @param string $imagePath Path to query image
     * @param int $limit Number of results to return
     * @param array $options Options:
     *   - document_type: string - Filter by document type
     *   - exclude_id: int - Exclude specific document ID
     * @return array Similar documents with similarity scores
     */
    public function searchByImage(string $imagePath, int $limit = 10, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            // Generate embedding for query image
            $embeddingResult = $this->generateImageEmbedding($imagePath, [
                'detail_level' => 'detailed',
            ]);

            if (!$embeddingResult['success']) {
                return [
                    'success' => false,
                    'results' => [],
                    'error' => 'Failed to analyze query image: ' . ($embeddingResult['error'] ?? 'unknown'),
                ];
            }

            $queryEmbedding = $embeddingResult['embedding'];
            $queryDescription = $embeddingResult['description'];
            $embeddingStr = PgVector::literal($queryEmbedding);

            // Build WHERE clause
            $conditions = ['has_visual_content = TRUE', 'image_embedding IS NOT NULL'];
            $params = [];

            if (!empty($options['document_type'])) {
                $conditions[] = 'document_type = ?';
                $params[] = $options['document_type'];
            }

            if (!empty($options['exclude_id'])) {
                $conditions[] = 'id != ?';
                $params[] = $options['exclude_id'];
            }

            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
            $params[] = $limit;

            // Search by image embedding similarity
            $sql = "SELECT id, document_type, title, content, image_description, metadata,
                    source_id, source_type, media_url, visual_analyzed_at,
                    (image_embedding <=> '{$embeddingStr}'::vector) as distance,
                    1 - (image_embedding <=> '{$embeddingStr}'::vector) as similarity
                    FROM rag_documents
                    {$whereClause}
                    ORDER BY distance ASC
                    LIMIT ?";

            $results = DB::connection('pgsql_rag')->select($sql, $params);

            // Format results
            $formattedResults = array_map(function ($doc) {
                return [
                    'document' => $doc,
                    'similarity' => (float) $doc->similarity,
                    'search_type' => 'image_to_image',
                ];
            }, $results);

            Log::info('MultimodalEmbedding: Image-to-image search completed', [
                'query_image' => basename($imagePath),
                'results_count' => count($formattedResults),
                'top_similarity' => $formattedResults[0]['similarity'] ?? 0,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => true,
                'results' => $formattedResults,
                'query_description' => $queryDescription,
                'error' => null,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];

        } catch (Exception $e) {
            Log::error('MultimodalEmbedding: Image search failed', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get documents with visual content
     *
     * @param int $limit Maximum documents to return
     * @param array $options Options:
     *   - document_type: string - Filter by document type
     *   - analyzed_only: bool - Only return analyzed documents (default: true)
     *   - pending_only: bool - Only return documents pending analysis
     * @return array List of visual documents
     */
    public function getVisualDocuments(int $limit = 100, array $options = []): array
    {
        try {
            $documentType = $options['document_type'] ?? null;
            $analyzedOnly = $options['analyzed_only'] ?? true;
            $pendingOnly = $options['pending_only'] ?? false;

            $conditions = [];
            $params = [];

            if ($documentType) {
                $conditions[] = 'document_type = ?';
                $params[] = $documentType;
            }

            if ($pendingOnly) {
                // Documents with media_url but not yet analyzed
                $conditions[] = 'media_url IS NOT NULL';
                $conditions[] = 'visual_analyzed_at IS NULL';
            } elseif ($analyzedOnly) {
                $conditions[] = 'has_visual_content = TRUE';
                $conditions[] = 'image_embedding IS NOT NULL';
            }

            $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
            $params[] = $limit;

            $sql = "SELECT id, document_type, title, media_url, image_description,
                    has_visual_content, visual_analyzed_at, created_at, updated_at
                    FROM rag_documents
                    {$whereClause}
                    ORDER BY visual_analyzed_at DESC NULLS LAST, created_at DESC
                    LIMIT ?";

            $documents = DB::connection('pgsql_rag')->select($sql, $params);

            return [
                'success' => true,
                'documents' => $documents,
                'count' => count($documents),
            ];

        } catch (Exception $e) {
            Log::error('MultimodalEmbedding: getVisualDocuments failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'documents' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Hybrid search combining text and visual content
     *
     * Searches both text embeddings and image embeddings, merging results.
     * Useful for queries that might match either text or visual content.
     *
     * @param string $query Search query
     * @param int $limit Number of results
     * @param array $options Options:
     *   - document_type: string - Filter by document type
     *   - text_weight: float - Weight for text results (default: 0.6)
     *   - visual_weight: float - Weight for visual results (default: 0.4)
     * @return array Combined search results
     */
    public function hybridTextVisualSearch(string $query, int $limit = 10, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            $textWeight = $options['text_weight'] ?? 0.6;
            $visualWeight = $options['visual_weight'] ?? 0.4;
            $documentType = $options['document_type'] ?? null;

            // Get text search results from RAGService
            $ragService = $this->getRAGService();
            $textResults = $ragService->search($query, $limit * 2, $documentType);

            // Get visual search results
            $visualSearchResult = $this->searchVisual($query, $limit * 2, [
                'document_type' => $documentType,
                'hybrid' => true,
            ]);

            $visualResults = $visualSearchResult['success'] ? $visualSearchResult['results'] : [];

            // Combine using weighted RRF
            $results = $this->mergeResultsWeightedRRF(
                $textResults,
                $visualResults,
                $textWeight,
                $visualWeight,
                $limit
            );

            Log::info('MultimodalEmbedding: Hybrid text+visual search completed', [
                'query' => substr($query, 0, 100),
                'text_results' => count($textResults),
                'visual_results' => count($visualResults),
                'merged_results' => count($results),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => true,
                'results' => $results,
                'error' => null,
                'text_count' => count($textResults),
                'visual_count' => count($visualResults),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];

        } catch (Exception $e) {
            Log::error('MultimodalEmbedding: Hybrid search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Merge vector and FTS results using Reciprocal Rank Fusion
     */
    private function mergeResultsRRF(array $vectorResults, array $ftsResults, int $limit): array
    {
        $k = 60; // RRF constant
        $scores = [];
        $docMap = [];

        // Score vector results
        foreach ($vectorResults as $rank => $doc) {
            $docId = $doc->id;
            $scores[$docId] = ($scores[$docId] ?? 0) + (1 / ($k + $rank + 1));
            $docMap[$docId] = $doc;
        }

        // Score FTS results with slight boost
        foreach ($ftsResults as $rank => $doc) {
            $docId = $doc->id;
            $scores[$docId] = ($scores[$docId] ?? 0) + (1.2 / ($k + $rank + 1));
            if (!isset($docMap[$docId])) {
                $docMap[$docId] = $doc;
            }
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
                    'search_type' => 'visual_hybrid',
                ];
            }
        }

        return $results;
    }

    /**
     * Merge text and visual results using weighted RRF
     */
    private function mergeResultsWeightedRRF(
        array $textResults,
        array $visualResults,
        float $textWeight,
        float $visualWeight,
        int $limit
    ): array {
        $k = 60; // RRF constant
        $scores = [];
        $docMap = [];

        // Score text results with weight
        foreach ($textResults as $rank => $result) {
            $doc = $result['document'] ?? $result;
            $docId = is_object($doc) ? $doc->id : $doc['id'];
            $scores[$docId] = ($scores[$docId] ?? 0) + ($textWeight / ($k + $rank + 1));
            $docMap[$docId] = $doc;
        }

        // Score visual results with weight
        foreach ($visualResults as $rank => $result) {
            $doc = $result['document'] ?? $result;
            $docId = is_object($doc) ? $doc->id : $doc['id'];
            $scores[$docId] = ($scores[$docId] ?? 0) + ($visualWeight / ($k + $rank + 1));
            if (!isset($docMap[$docId])) {
                $docMap[$docId] = $doc;
            }
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
                    'search_type' => 'text_visual_hybrid',
                ];
            }
        }

        return $results;
    }

    /**
     * Batch analyze multiple documents
     *
     * @param array $documentIds Array of document IDs to analyze
     * @param array $options Options passed to analyzeAndEmbed
     * @return array Summary of batch processing
     */
    public function batchAnalyze(array $documentIds, array $options = []): array
    {
        $startTime = microtime(true);
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($documentIds as $documentId) {
            $result = $this->analyzeAndEmbed($documentId, $options);

            if ($result['success']) {
                if ($result['skipped'] ?? false) {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
                $results['errors'][$documentId] = $result['error'];
            }
        }

        $results['total'] = count($documentIds);
        $results['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);

        Log::info('MultimodalEmbedding: Batch analysis completed', $results);

        return $results;
    }

    /**
     * Get statistics about visual content in RAG
     */
    public function getStats(): array
    {
        try {
            // Total documents with visual content
            $sql = "SELECT COUNT(*) as count FROM rag_documents WHERE has_visual_content = TRUE";
            $visualCount = DB::connection('pgsql_rag')->select($sql)[0]->count ?? 0;

            // Total documents with image embeddings
            $sql = "SELECT COUNT(*) as count FROM rag_documents WHERE image_embedding IS NOT NULL";
            $embeddedCount = DB::connection('pgsql_rag')->select($sql)[0]->count ?? 0;

            // Documents pending visual analysis (have media_url but not analyzed)
            $sql = "SELECT COUNT(*) as count FROM rag_documents WHERE media_url IS NOT NULL AND visual_analyzed_at IS NULL";
            $pendingCount = DB::connection('pgsql_rag')->select($sql)[0]->count ?? 0;

            // By document type
            $sql = "SELECT document_type, COUNT(*) as count
                    FROM rag_documents
                    WHERE has_visual_content = TRUE
                    GROUP BY document_type
                    ORDER BY count DESC";
            $byTypeResults = DB::connection('pgsql_rag')->select($sql);
            $byType = [];
            foreach ($byTypeResults as $row) {
                $byType[$row->document_type] = $row->count;
            }

            // Recent analyses
            $sql = "SELECT visual_analyzed_at
                    FROM rag_documents
                    WHERE visual_analyzed_at IS NOT NULL
                    ORDER BY visual_analyzed_at DESC
                    LIMIT 1";
            $lastAnalyzed = DB::connection('pgsql_rag')->select($sql)[0]->visual_analyzed_at ?? null;

            return [
                'visual_documents' => $visualCount,
                'embedded_documents' => $embeddedCount,
                'pending_analysis' => $pendingCount,
                'by_type' => $byType,
                'last_analyzed' => $lastAnalyzed,
            ];

        } catch (Exception $e) {
            Log::error('MultimodalEmbedding: getStats failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FileCategorizationRAGService
 *
 * File Catalog - RAG Integration for File Discovery
 *
 * Uses RAG (Retrieval Augmented Generation) to enable intelligent file search:
 * 1. Index files from file_registry with extracted metadata
 * 2. Search for similar files by content or characteristics
 * 3. Support semantic file discovery via natural language queries
 *
 * Uses raw SQL per project standards - NO Eloquent models
 */
class FileCategorizationRAGService
{
    private const DEFAULT_DESCRIPTION_PREVIEW_CHARS = 1000;

    private const DEFAULT_KEYWORDS_PREVIEW_CHARS = 500;

    private const DEFAULT_TEXT_PREVIEW_CHARS = 2000;

    private const BULK_DESCRIPTION_PREVIEW_CHARS = 350;

    private const BULK_KEYWORDS_PREVIEW_CHARS = 200;

    private const BULK_TEXT_PREVIEW_CHARS = 800;

    private const BULK_IMAGE_TEXT_PREVIEW_CHARS = 250;

    private const BULK_TEXT_HEAVY_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'txt', 'md', 'rtf', 'odt', 'csv', 'json', 'xml', 'html', 'htm',
    ];

    private RAGService $ragService;

    private AIService $aiService;

    private ?AgentGuardrailService $guardrail = null;

    /** Document type for file catalog RAG entries */
    private const RAG_DOCUMENT_TYPE = 'file_catalog';

    /** Minimum similarity threshold to consider RAG results */
    private const SIMILARITY_THRESHOLD = 0.60;

    public function __construct(RAGService $ragService, AIService $aiService)
    {
        $this->ragService = $ragService;
        $this->aiService = $aiService;
    }

    /**
     * Query RAG for similar files
     *
     * @param  string  $query  Search query (natural language or file characteristics)
     * @param  int  $limit  Maximum results
     * @param  array  $filters  Optional filters (category, extension, etc.)
     * @return array Search results with similarity scores
     */
    public function searchFiles(
        string $query,
        int $limit = 10,
        array $filters = []
    ): array {
        try {
            // Search RAG for similar files
            $results = $this->ragService->search(
                $query,
                $limit,
                self::RAG_DOCUMENT_TYPE
            );

            // Filter and format results
            $files = [];
            foreach ($results as $result) {
                $similarity = $result['similarity'] ?? 0;

                if ($similarity < self::SIMILARITY_THRESHOLD) {
                    continue;
                }

                $metadata = is_string($result['document']->metadata)
                    ? json_decode($result['document']->metadata, true)
                    : ($result['document']->metadata ?? []);

                // Apply optional filters
                if (! empty($filters['category']) && ($metadata['category'] ?? '') !== $filters['category']) {
                    continue;
                }
                if (! empty($filters['extension']) && ($metadata['extension'] ?? '') !== $filters['extension']) {
                    continue;
                }

                $files[] = [
                    'rag_id' => $result['document']->id,
                    'similarity' => round($similarity, 3),
                    'asset_uuid' => $metadata['asset_uuid'] ?? null,
                    'filename' => $metadata['filename'] ?? null,
                    'path' => $metadata['path'] ?? null,
                    'category' => $metadata['category'] ?? null,
                    'extension' => $metadata['extension'] ?? null,
                    'file_size' => $metadata['file_size'] ?? null,
                    'indexed_at' => $metadata['indexed_at'] ?? null,
                ];
            }

            Log::info('FileCategorizationRAG: Search completed', [
                'query' => substr($query, 0, 100),
                'results_count' => count($files),
            ]);

            return [
                'success' => true,
                'files' => $files,
                'total' => count($files),
            ];
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Search failed', [
                'query' => substr($query, 0, 100),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'files' => [],
            ];
        }
    }

    /**
     * Query RAG for files similar to a given file
     *
     * @param  string  $assetUuid  Asset UUID of the file to find similar files for
     * @param  int  $limit  Maximum results
     * @return array Similar files with similarity scores
     */
    public function findSimilarFiles(string $assetUuid, int $limit = 5): array
    {
        try {
            // Get the file info from registry
            $file = DB::selectOne('
                SELECT * FROM file_registry WHERE asset_uuid = ?
            ', [$assetUuid]);

            if (! $file) {
                return ['success' => false, 'error' => 'File not found in registry'];
            }

            // Build query from file characteristics
            $queryParts = [];
            $queryParts[] = 'Filename: '.basename($file->current_path);
            $queryParts[] = "Extension: {$file->extension}";
            $queryParts[] = 'Folder: '.dirname($file->current_path);

            if ($file->category) {
                $queryParts[] = "Category: {$file->category}";
            }

            $query = implode("\n", $queryParts);

            // Search for similar (excluding self)
            $results = $this->searchFiles($query, $limit + 1);

            if (! $results['success']) {
                return $results;
            }

            // Filter out the source file
            $results['files'] = array_values(array_filter(
                $results['files'],
                fn ($f) => $f['asset_uuid'] !== $assetUuid
            ));

            // Limit to requested count
            $results['files'] = array_slice($results['files'], 0, $limit);
            $results['total'] = count($results['files']);

            return $results;
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Find similar failed', [
                'asset_uuid' => $assetUuid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'files' => [],
            ];
        }
    }

    /**
     * Index a file from the file_registry to RAG
     *
     * @param  string  $assetUuid  Asset UUID of the file to index
     * @param  string|null  $extractedText  Optional extracted text content
     * @param  array|null  $extractedMetadata  Optional extracted metadata
     * @return array Indexing result
     */
    public function indexFile(
        string $assetUuid,
        ?string $extractedText = null,
        ?array $extractedMetadata = null,
        array $options = []
    ): array {
        try {
            $file = DB::selectOne('
                SELECT * FROM file_registry WHERE asset_uuid = ?
            ', [$assetUuid]);

            if (! $file) {
                return ['success' => false, 'error' => 'File not found in registry'];
            }

            // Build content for RAG indexing
            $content = $this->buildIndexContent($file, $extractedText, $extractedMetadata, $options);

            // Build metadata
            $metadata = [
                'asset_uuid' => $assetUuid,
                'filename' => basename($file->current_path),
                'path' => $file->current_path,
                'folder_path' => dirname($file->current_path),
                'extension' => $file->extension,
                'category' => $file->category,
                'file_size' => $file->file_size,
                'mime_type' => $file->mime_type,
                'nextcloud_fileid' => $file->nextcloud_fileid,
                'ai_document_type' => $file->ai_document_type ?? null,
                'date_taken' => $file->date_taken ?? null,
                'has_ocr_text' => ! empty($file->ai_detected_text),
                'indexed_at' => now()->toIso8601String(),
            ];

            if (! $this->isBulkMode($options)) {
                // Face names
                try {
                    $faces = DB::select("
                        SELECT DISTINCT person_name FROM file_registry_faces
                        WHERE file_registry_id = ? AND person_name IS NOT NULL AND person_name != ''
                    ", [$file->id]);
                    if (! empty($faces)) {
                        $metadata['face_names'] = array_column(array_map(fn ($f) => (array) $f, $faces), 'person_name');
                    }
                } catch (\Exception $e) {
                    // Non-fatal
                }

                // Genealogy media link
                try {
                    $genMedia = DB::selectOne('
                        SELECT gm.id, gm.media_type FROM genealogy_media gm
                        WHERE gm.nextcloud_path = ? LIMIT 1
                    ', [$file->current_path]);
                    if ($genMedia) {
                        $metadata['genealogy_media_id'] = $genMedia->id;
                        $metadata['media_type'] = $genMedia->media_type;

                        $linkedPersons = DB::select('
                            SELECT gpm.person_id FROM genealogy_person_media gpm
                            WHERE gpm.media_id = ?
                        ', [$genMedia->id]);
                        if (! empty($linkedPersons)) {
                            $metadata['linked_person_ids'] = array_column(array_map(fn ($p) => (array) $p, $linkedPersons), 'person_id');
                        }
                    }
                } catch (\Exception $e) {
                    // Non-fatal
                }
            }

            // Add extracted metadata if available
            if ($extractedMetadata) {
                $metadata['extracted'] = $extractedMetadata;
            }

            // Index to RAG
            $doc = $this->ragService->indexDocument(
                self::RAG_DOCUMENT_TYPE,
                $content,
                basename($file->current_path),
                $metadata,
                $file->id,
                'file_registry',
                null,
                null,
                $options
            );

            // Mark as indexed in registry
            DB::update('
                UPDATE file_registry
                SET rag_indexed_at = NOW()
                WHERE id = ?
            ', [$file->id]);

            Log::info('FileCategorizationRAG: Indexed file', [
                'asset_uuid' => $assetUuid,
                'rag_id' => $doc->id,
                'path' => $file->current_path,
            ]);

            return [
                'success' => true,
                'rag_id' => $doc->id,
            ];
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Failed to index file', [
                'asset_uuid' => $assetUuid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function indexFileForBulkRag(string $assetUuid): array
    {
        return $this->indexFile($assetUuid, null, null, [
            'bulk_mode' => true,
            // Bulk file indexing already targets unindexed file_registry rows.
            // Skipping semantic dedup avoids a second embedding+vector search
            // before the real index call, which can stall large backfills.
            'skip_dedup' => true,
            'trace_timing' => true,
        ]);
    }

    /**
     * Remove a file from RAG index
     *
     * @param  string  $assetUuid  Asset UUID of the file to remove
     * @return array Result
     */
    public function removeFile(string $assetUuid): array
    {
        try {
            // Find and delete RAG document by asset_uuid
            $deleted = DB::connection('pgsql_rag')->delete("
                DELETE FROM rag_documents
                WHERE document_type = ?
                  AND metadata->>'asset_uuid' = ?
            ", [self::RAG_DOCUMENT_TYPE, $assetUuid]);

            if ($deleted > 0) {
                Log::info('FileCategorizationRAG: Removed file from index', [
                    'asset_uuid' => $assetUuid,
                ]);
            }

            return [
                'success' => true,
                'deleted' => $deleted,
            ];
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Failed to remove file', [
                'asset_uuid' => $assetUuid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build content for RAG indexing
     */
    private function buildIndexContent(
        object $file,
        ?string $extractedText = null,
        ?array $extractedMetadata = null,
        array $options = []
    ): string {
        $parts = [];
        $descriptionPreviewChars = $this->getPreviewLimit($options, self::DEFAULT_DESCRIPTION_PREVIEW_CHARS, self::BULK_DESCRIPTION_PREVIEW_CHARS);
        $keywordsPreviewChars = $this->getPreviewLimit($options, self::DEFAULT_KEYWORDS_PREVIEW_CHARS, self::BULK_KEYWORDS_PREVIEW_CHARS);
        $includeSupplemental = ! $this->isBulkMode($options);
        $textPreviewChars = $this->resolveTextPreviewChars($file, $options);

        // File info
        $filename = basename($file->current_path);
        $parentFolder = basename(dirname($file->current_path));

        $parts[] = "Filename: {$filename}";
        $parts[] = "Extension: {$file->extension}";
        $parts[] = "Folder: {$parentFolder}";
        $parts[] = "Full path: {$file->current_path}";

        if ($file->category) {
            $parts[] = "Category: {$file->category}";
        }

        if ($file->mime_type) {
            $parts[] = "MIME type: {$file->mime_type}";
        }

        if ($file->file_size) {
            $sizeKb = round($file->file_size / 1024, 1);
            $parts[] = "Size: {$sizeKb} KB";
        }

        // Add extracted metadata
        if ($extractedMetadata) {
            if (! empty($extractedMetadata['title'])) {
                $parts[] = "Title: {$extractedMetadata['title']}";
            }
            if (! empty($extractedMetadata['author'])) {
                $parts[] = "Author: {$extractedMetadata['author']}";
            }
            if (! empty($extractedMetadata['description'])) {
                $parts[] = 'Description: '.substr($extractedMetadata['description'], 0, min(500, $descriptionPreviewChars));
            }
            if (! empty($extractedMetadata['keywords'])) {
                $keywords = is_array($extractedMetadata['keywords'])
                    ? implode(', ', $extractedMetadata['keywords'])
                    : $extractedMetadata['keywords'];
                $parts[] = "Keywords: {$keywords}";
            }
        }

        // Add AI-generated metadata from file_registry (already analyzed)
        if (! empty($file->ai_description)) {
            $parts[] = 'Description: '.substr($this->sanitizeIndexedText($file->ai_description), 0, $descriptionPreviewChars);
        }

        if (! empty($file->title)) {
            $parts[] = "Title: {$file->title}";
        }

        if (! empty($file->tags)) {
            $parts[] = "Tags: {$file->tags}";
        }

        if (! empty($file->ai_tags)) {
            $parts[] = "AI Tags: {$file->ai_tags}";
        }

        if (! empty($file->ai_document_type)) {
            $parts[] = "Document type: {$file->ai_document_type}";
        }

        if (! empty($file->date_taken)) {
            $parts[] = "Date taken: {$file->date_taken}";
        }

        if (! empty($file->gps_location)) {
            $parts[] = "Location: {$file->gps_location}";
        }

        if (! empty($file->camera_make) || ! empty($file->camera_model)) {
            $camera = trim(($file->camera_make ?? '').' '.($file->camera_model ?? ''));
            $parts[] = "Camera: {$camera}";
        }

        if (! empty($file->exif_keywords)) {
            $parts[] = 'Keywords: '.substr($file->exif_keywords, 0, min(300, $keywordsPreviewChars));
        }

        if (! empty($file->exif_caption)) {
            $parts[] = 'Caption: '.substr($file->exif_caption, 0, min(500, $descriptionPreviewChars));
        }

        if (! empty($file->exif_rating)) {
            $parts[] = "Rating: {$file->exif_rating}/5";
        }

        if (! empty($file->search_keywords)) {
            $parts[] = 'Keywords: '.substr($file->search_keywords, 0, $keywordsPreviewChars);
        }

        // Add extracted metadata
        if ($extractedMetadata) {
            if (! empty($extractedMetadata['title']) && empty($file->title)) {
                $parts[] = "Title: {$extractedMetadata['title']}";
            }
            if (! empty($extractedMetadata['author'])) {
                $parts[] = "Author: {$extractedMetadata['author']}";
            }
            if (! empty($extractedMetadata['description']) && empty($file->ai_description)) {
                $parts[] = 'Description: '.substr($extractedMetadata['description'], 0, min(500, $descriptionPreviewChars));
            }
            if (! empty($extractedMetadata['keywords'])) {
                $keywords = is_array($extractedMetadata['keywords'])
                    ? implode(', ', $extractedMetadata['keywords'])
                    : $extractedMetadata['keywords'];
                $parts[] = "Keywords: {$keywords}";
            }
        }

        // Add extracted text content (limited)
        if ($extractedText && $textPreviewChars > 0) {
            $textPreview = substr($this->sanitizeIndexedText($extractedText), 0, $textPreviewChars);
            if ($textPreview) {
                $parts[] = "\n--- Content ---";
                $parts[] = $textPreview;
            }
        }

        // Add OCR text if available
        if (! empty($file->ai_detected_text) && ! $extractedText && $textPreviewChars > 0) {
            $ocrPreview = substr($this->sanitizeIndexedText($file->ai_detected_text), 0, $textPreviewChars);
            if ($ocrPreview) {
                $parts[] = "\n--- OCR Text ---";
                $parts[] = $ocrPreview;
            }
        }

        if ($includeSupplemental) {
            // Face names from file_registry_faces
            try {
                $faces = DB::select("
                    SELECT DISTINCT person_name
                    FROM file_registry_faces
                    WHERE file_registry_id = ? AND person_name IS NOT NULL AND person_name != ''
                ", [$file->id]);

                if (! empty($faces)) {
                    $names = array_column(array_map(fn ($f) => (array) $f, $faces), 'person_name');
                    $parts[] = 'People in photo: '.implode(', ', $names);
                }
            } catch (\Exception $e) {
                // Non-fatal — face data is supplementary
            }

            // Genealogy media metadata (linked via nextcloud_path)
            try {
                $genealogyMedia = DB::selectOne('
                    SELECT gm.title as gen_title, gm.description as gen_description,
                           gm.transcription_text, gm.media_type
                    FROM genealogy_media gm
                    WHERE gm.nextcloud_path = ?
                    LIMIT 1
                ', [$file->current_path]);

                if ($genealogyMedia) {
                    if (! empty($genealogyMedia->gen_title) && empty($file->title)) {
                        $parts[] = "Genealogy title: {$genealogyMedia->gen_title}";
                    }
                    if (! empty($genealogyMedia->gen_description) && empty($file->ai_description)) {
                        $parts[] = 'Genealogy description: '.substr($genealogyMedia->gen_description, 0, min(500, $descriptionPreviewChars));
                    }
                    if (! empty($genealogyMedia->transcription_text)) {
                        $parts[] = "\n--- Transcription ---";
                        $parts[] = substr($this->sanitizeIndexedText($genealogyMedia->transcription_text), 0, $textPreviewChars);
                    }
                    if (! empty($genealogyMedia->media_type)) {
                        $parts[] = "Media type: {$genealogyMedia->media_type}";
                    }

                    // Linked person names from genealogy
                    $linkedPersons = DB::select("
                        SELECT DISTINCT CONCAT(gp.given_name, ' ', gp.surname) as full_name
                        FROM genealogy_person_media gpm
                        JOIN genealogy_media gm ON gpm.media_id = gm.id
                        JOIN genealogy_persons gp ON gpm.person_id = gp.id
                        WHERE gm.nextcloud_path = ?
                    ", [$file->current_path]);

                    if (! empty($linkedPersons)) {
                        $personNames = array_column(array_map(fn ($p) => (array) $p, $linkedPersons), 'full_name');
                        $parts[] = 'Linked persons: '.implode(', ', $personNames);
                    }
                }
            } catch (\Exception $e) {
                // Non-fatal — genealogy data is supplementary
            }
        }

        return implode("\n", $parts);
    }

    private function sanitizeIndexedText(?string $text): string
    {
        $trimmed = trim((string) $text);
        if ($trimmed === '') {
            return '';
        }

        return $this->getGuardrail()->sanitizeUntrustedText($trimmed);
    }

    private function getGuardrail(): AgentGuardrailService
    {
        if (! $this->guardrail) {
            $this->guardrail = app(AgentGuardrailService::class);
        }

        return $this->guardrail;
    }

    /**
     * Get RAG statistics for file catalog
     */
    public function getStats(): array
    {
        try {
            // Count indexed files
            $total = DB::connection('pgsql_rag')->selectOne('
                SELECT COUNT(*) as cnt FROM rag_documents
                WHERE document_type = ?
            ', [self::RAG_DOCUMENT_TYPE]);

            // Count by category
            $byCategory = DB::connection('pgsql_rag')->select("
                SELECT
                    metadata->>'category' as category,
                    COUNT(*) as cnt
                FROM rag_documents
                WHERE document_type = ?
                GROUP BY metadata->>'category'
            ", [self::RAG_DOCUMENT_TYPE]);

            $categoryStats = [];
            foreach ($byCategory as $row) {
                $categoryStats[$row->category ?? 'uncategorized'] = (int) $row->cnt;
            }

            // Count by extension
            $byExtension = DB::connection('pgsql_rag')->select("
                SELECT
                    metadata->>'extension' as extension,
                    COUNT(*) as cnt
                FROM rag_documents
                WHERE document_type = ?
                GROUP BY metadata->>'extension'
                ORDER BY cnt DESC
                LIMIT 10
            ", [self::RAG_DOCUMENT_TYPE]);

            $extensionStats = [];
            foreach ($byExtension as $row) {
                $extensionStats[$row->extension ?? 'unknown'] = (int) $row->cnt;
            }

            // Count pending indexing in file_registry
            $pending = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM file_registry
                WHERE rag_indexed_at IS NULL
                  AND status = 'active'
            ");

            return [
                'total_indexed' => (int) ($total->cnt ?? 0),
                'pending_indexing' => (int) ($pending->cnt ?? 0),
                'by_category' => $categoryStats,
                'by_extension' => $extensionStats,
            ];
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Failed to get stats', ['error' => $e->getMessage()]);

            return [
                'total_indexed' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Batch index files from file_registry that haven't been indexed yet
     *
     * @param  int  $limit  Maximum files to index
     * @return array Indexing results
     */
    public function batchIndexFiles(int $limit = 50, ?string $workerId = null, int $maxSeconds = 600, bool $bulkMode = false): array
    {
        $results = [
            'indexed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
        $startTime = microtime(true);

        // Reconnect pgsql_rag before batch to recover from stale/exhausted pool
        try {
            DB::connection('pgsql_rag')->reconnect();
        } catch (\Throwable $e) {
            Log::warning('FileCategorizationRAG: pgsql_rag reconnect failed, proceeding', ['error' => $e->getMessage()]);
        }

        try {
            // Get files that need indexing (filter by claim_worker for parallel safety)
            if ($workerId) {
                $files = DB::select('
                    SELECT asset_uuid
                    FROM file_registry
                    WHERE rag_indexed_at IS NULL
                      AND claim_worker = ?
                    ORDER BY created_at DESC
                    LIMIT ?
                ', [$workerId, $limit]);
            } else {
                $docExts = config('file_types.rag_indexable');
                $imgExts = config('file_types.image');
                $docPlaceholders = implode(',', array_fill(0, count($docExts), '?'));
                $imgPlaceholders = implode(',', array_fill(0, count($imgExts), '?'));
                $files = DB::select("
                    SELECT asset_uuid
                    FROM file_registry
                    WHERE rag_indexed_at IS NULL
                      AND status = 'active'
                      AND (extension IN ({$docPlaceholders})
                           OR (extension IN ({$imgPlaceholders}) AND ai_description IS NOT NULL))
                    ORDER BY created_at DESC
                    LIMIT ?
                ", array_merge($docExts, $imgExts, [$limit]));
            }

            $processed = 0;
            foreach ($files as $file) {
                if ((microtime(true) - $startTime) >= $maxSeconds) {
                    Log::warning('FileCategorizationRAG: Wall-clock limit reached in batch indexing', [
                        'elapsed_seconds' => round(microtime(true) - $startTime),
                        'limit_seconds' => $maxSeconds,
                        'indexed' => $results['indexed'],
                        'remaining' => count($files) - $results['indexed'] - $results['errors'],
                    ]);
                    $results['time_limited'] = true;
                    break;
                }

                // Periodic pgsql_rag reconnect to prevent connection staleness
                if (++$processed % 50 === 0) {
                    try {
                        DB::connection('pgsql_rag')->reconnect();
                    } catch (\Throwable $e) {
                    }
                }

                $result = $bulkMode
                    ? $this->indexFileForBulkRag($file->asset_uuid)
                    : $this->indexFile($file->asset_uuid);

                if ($result['success']) {
                    $results['indexed']++;
                } else {
                    $results['errors']++;
                }
            }
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Batch indexing failed', [
                'error' => $e->getMessage(),
            ]);
            $results['error'] = $e->getMessage();
        }

        $results['elapsed_seconds'] = round(microtime(true) - $startTime);
        Log::info('FileCategorizationRAG: Batch indexing completed', $results);

        return $results;
    }

    /**
     * Sync RAG index with file_registry
     *
     * - Index new files
     * - Remove deleted files from RAG
     *
     * @param  int  $limit  Maximum operations
     * @return array Sync results
     */
    public function syncWithRegistry(int $limit = 100, ?string $workerId = null, ?int $maxSeconds = null): array
    {
        $results = [
            'indexed' => 0,
            'removed' => 0,
            'errors' => 0,
        ];

        try {
            $maxSeconds = $maxSeconds ?? 600;
            $maxSeconds = max(60, $maxSeconds);

            // Index new files (90% of budget for indexing, 10% for orphan cleanup)
            $indexLimit = max(10, (int) ($limit * 0.9));
            $orphanCheckLimit = max(5, $limit - $indexLimit);
            $indexSeconds = max(45, (int) floor($maxSeconds * 0.8));
            $staleSeconds = max(30, $maxSeconds - $indexSeconds);

            $indexResult = $this->batchIndexFiles($indexLimit, $workerId, $indexSeconds, true);
            $results['indexed'] = $indexResult['indexed'];
            $results['errors'] += $indexResult['errors'];
            if (! empty($indexResult['time_limited'])) {
                $results['time_limited'] = true;
            }

            // Find and remove orphaned RAG entries (files no longer in registry)
            $orphaned = DB::connection('pgsql_rag')->select("
                SELECT metadata->>'asset_uuid' as asset_uuid
                FROM rag_documents
                WHERE document_type = ?
                LIMIT ?
            ", [self::RAG_DOCUMENT_TYPE, $orphanCheckLimit]);

            foreach ($orphaned as $doc) {
                if (! $doc->asset_uuid) {
                    continue;
                }

                // Check if still exists in registry
                $exists = DB::selectOne("
                    SELECT 1 FROM file_registry
                    WHERE asset_uuid = ?
                      AND status = 'active'
                ", [$doc->asset_uuid]);

                if (! $exists) {
                    $removeResult = $this->removeFile($doc->asset_uuid);
                    if ($removeResult['success'] && $removeResult['deleted'] > 0) {
                        $results['removed']++;
                    }
                }
            }

            // Re-index stale files only if the main indexing loop did not already
            // consume the full runtime budget.
            if (empty($results['time_limited']) && $this->shouldReindexStaleFiles($limit, $workerId)) {
                $staleResult = $this->reindexStaleFiles(max(5, (int) ($limit * 0.1)), $staleSeconds);
                $results['reindexed'] = $staleResult['reindexed'];
                $results['errors'] += $staleResult['errors'];
                if (! empty($staleResult['time_limited'])) {
                    $results['time_limited'] = true;
                }
            } else {
                $results['reindexed'] = 0;
            }
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Sync failed', [
                'error' => $e->getMessage(),
            ]);
            $results['error'] = $e->getMessage();
        }

        Log::info('FileCategorizationRAG: Sync completed', $results);

        return $results;
    }

    /**
     * Index a genealogy_media record into RAG.
     * If the media has a matching file_registry record, uses indexFile() for full enrichment.
     * Otherwise creates a standalone RAG document from genealogy metadata.
     */
    public function indexGenealogyMediaFile(int $genealogyMediaId): array
    {
        try {
            $media = DB::selectOne('
                SELECT gm.*, gm.nextcloud_path
                FROM genealogy_media gm
                WHERE gm.id = ?
            ', [$genealogyMediaId]);

            if (! $media) {
                return ['success' => false, 'error' => 'Genealogy media not found'];
            }

            // Try to find matching file_registry record
            if (! empty($media->nextcloud_path)) {
                $fileReg = DB::selectOne("
                    SELECT asset_uuid FROM file_registry
                    WHERE current_path = ? AND status = 'active'
                ", [$media->nextcloud_path]);

                if ($fileReg) {
                    // Use full indexFile() which includes genealogy metadata enrichment
                    $result = $this->indexFile($fileReg->asset_uuid);
                    if ($result['success']) {
                        DB::update('UPDATE genealogy_media SET rag_indexed_at = NOW() WHERE id = ?', [$genealogyMediaId]);
                    }

                    return $result;
                }
            }

            // No file_registry match — build standalone RAG document from genealogy data
            $content = $this->buildGenealogyContent($media);
            if (empty(trim($content))) {
                return ['success' => false, 'error' => 'No indexable content'];
            }

            $metadata = [
                'genealogy_media_id' => $genealogyMediaId,
                'media_type' => $media->media_type ?? null,
                'tree_id' => $media->tree_id ?? null,
                'nextcloud_path' => $media->nextcloud_path ?? null,
                'indexed_at' => now()->toIso8601String(),
            ];

            // Get linked person names
            $linkedPersons = DB::select("
                SELECT DISTINCT CONCAT(gp.given_name, ' ', gp.surname) as full_name, gp.id as person_id
                FROM genealogy_person_media gpm
                JOIN genealogy_persons gp ON gpm.person_id = gp.id
                WHERE gpm.media_id = ?
            ", [$genealogyMediaId]);

            if (! empty($linkedPersons)) {
                $metadata['linked_person_ids'] = array_column(array_map(fn ($p) => (array) $p, $linkedPersons), 'person_id');
                $metadata['face_names'] = array_column(array_map(fn ($p) => (array) $p, $linkedPersons), 'full_name');
            }

            $doc = $this->ragService->indexDocument(
                self::RAG_DOCUMENT_TYPE,
                $content,
                $media->title ?? 'Genealogy Media #'.$genealogyMediaId,
                $metadata,
                'gen_media_'.$genealogyMediaId,
                'genealogy_media'
            );

            DB::update('UPDATE genealogy_media SET rag_indexed_at = NOW() WHERE id = ?', [$genealogyMediaId]);

            return ['success' => true, 'rag_id' => $doc->id];
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Failed to index genealogy media', [
                'media_id' => $genealogyMediaId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Batch index genealogy_media records that haven't been RAG-indexed.
     */
    public function batchIndexGenealogyMedia(int $limit = 100): array
    {
        $results = ['indexed' => 0, 'errors' => 0];

        try {
            $media = DB::select('
                SELECT id FROM genealogy_media
                WHERE rag_indexed_at IS NULL
                  AND (title IS NOT NULL OR description IS NOT NULL OR transcription_text IS NOT NULL)
                ORDER BY created_at DESC
                LIMIT ?
            ', [$limit]);

            foreach ($media as $m) {
                $result = $this->indexGenealogyMediaFile($m->id);
                if ($result['success']) {
                    $results['indexed']++;
                } else {
                    $results['errors']++;
                }
            }
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Batch genealogy media index failed', ['error' => $e->getMessage()]);
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Build RAG content from genealogy_media record (standalone, no file_registry).
     */
    private function buildGenealogyContent(object $media): string
    {
        $parts = [];

        if (! empty($media->title)) {
            $parts[] = "Title: {$media->title}";
        }
        if (! empty($media->description)) {
            $parts[] = 'Description: '.substr($media->description, 0, 1000);
        }
        if (! empty($media->media_type)) {
            $parts[] = "Type: {$media->media_type}";
        }
        if (! empty($media->ai_description)) {
            $parts[] = 'AI Description: '.substr($media->ai_description, 0, 1000);
        }
        if (! empty($media->nextcloud_path)) {
            $parts[] = 'File: '.basename($media->nextcloud_path);
        }
        if (! empty($media->transcription_text)) {
            $parts[] = "\n--- Transcription ---";
            $parts[] = substr(trim($media->transcription_text), 0, 2000);
        }

        // Linked person names
        try {
            $persons = DB::select("
                SELECT DISTINCT CONCAT(gp.given_name, ' ', gp.surname) as full_name
                FROM genealogy_person_media gpm
                JOIN genealogy_persons gp ON gpm.person_id = gp.id
                WHERE gpm.media_id = ?
            ", [$media->id]);

            if (! empty($persons)) {
                $names = array_column(array_map(fn ($p) => (array) $p, $persons), 'full_name');
                $parts[] = 'Linked persons: '.implode(', ', $names);
            }
        } catch (\Exception $e) {
            // Non-fatal
        }

        return implode("\n", $parts);
    }

    /**
     * Re-index files that have been updated since their last RAG indexing.
     * Catches files re-analyzed by AI, face-scanned, or otherwise modified.
     */
    public function reindexStaleFiles(int $limit = 100, int $maxSeconds = 300): array
    {
        $results = ['reindexed' => 0, 'errors' => 0];
        $startTime = microtime(true);

        try {
            $staleFiles = DB::select("
                SELECT asset_uuid
                FROM file_registry
                WHERE rag_indexed_at IS NOT NULL
                  AND status = 'active'
                  AND (
                    (ai_analyzed_at IS NOT NULL AND ai_analyzed_at > rag_indexed_at)
                    OR (face_scan_at IS NOT NULL AND face_scan_at > rag_indexed_at)
                  )
                ORDER BY GREATEST(
                    COALESCE(ai_analyzed_at, '1970-01-01'),
                    COALESCE(face_scan_at, '1970-01-01')
                ) DESC
                LIMIT ?
            ", [$limit]);

            foreach ($staleFiles as $file) {
                if ((microtime(true) - $startTime) >= $maxSeconds) {
                    $results['time_limited'] = true;
                    break;
                }
                $result = $this->indexFile($file->asset_uuid);
                if ($result['success']) {
                    $results['reindexed']++;
                } else {
                    $results['errors']++;
                }
            }

            if ($results['reindexed'] > 0) {
                Log::info('FileCategorizationRAG: Re-indexed stale files', $results);
            }
        } catch (\Exception $e) {
            Log::error('FileCategorizationRAG: Stale re-index failed', ['error' => $e->getMessage()]);
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    private function isBulkMode(array $options): bool
    {
        return ! empty($options['bulk_mode']);
    }

    private function getPreviewLimit(array $options, int $defaultChars, int $bulkChars): int
    {
        return $this->isBulkMode($options) ? $bulkChars : $defaultChars;
    }

    private function resolveTextPreviewChars(object $file, array $options): int
    {
        if (! $this->isBulkMode($options)) {
            return self::DEFAULT_TEXT_PREVIEW_CHARS;
        }

        $extension = strtolower((string) ($file->extension ?? ''));
        if (in_array($extension, self::BULK_TEXT_HEAVY_EXTENSIONS, true)) {
            return 0;
        }

        return self::BULK_IMAGE_TEXT_PREVIEW_CHARS;
    }

    private function shouldReindexStaleFiles(int $limit, ?string $workerId): bool
    {
        if ($workerId) {
            return false;
        }

        try {
            $pending = DB::selectOne("
                SELECT 1
                FROM file_registry
                WHERE rag_indexed_at IS NULL
                  AND status = 'active'
                  AND (
                    extension IN ('pdf','doc','docx','txt','md','rtf','odt','csv','json','xml','html','htm')
                    OR (extension IN ('jpg','jpeg','png','gif','webp','bmp','tiff','heic') AND ai_description IS NOT NULL)
                  )
                LIMIT 1
            ");

            if ($pending) {
                return false;
            }
        } catch (\Exception $e) {
            Log::warning('FileCategorizationRAG: pending backlog check failed, skipping stale reindex', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $limit <= 25;
    }
}

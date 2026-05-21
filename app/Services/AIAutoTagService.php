<?php

namespace App\Services;

use App\DTOs\TrustEnvelope;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Auto-Tagging Service
 *
 * Analyzes files using AI vision and content extraction to automatically
 * generate tags, descriptions, document classifications, and OCR text.
 *
 * Capabilities:
 * - Image analysis: objects, scenes, colors, text (OCR)
 * - Document analysis: type classification, key entities, summary
 * - Batch processing via queue for existing files
 * - Tag-based search across file registry
 */
class AIAutoTagService
{
    private AIService $aiService;

    private ?ContentExtractionService $contentExtraction = null;

    private ?NextcloudFileApiService $nextcloudApi = null;

    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    /** Current analysis version - increment when prompts/models change significantly */
    private const ANALYSIS_VERSION = 'v1.0';

    /** Image extensions for vision analysis */
    /** @see config/file_types.php */

    /** Standard document type classifications */
    private const DOCUMENT_TYPES = [
        'invoice', 'receipt', 'letter', 'contract', 'report', 'resume',
        'tax_document', 'bank_statement', 'bill', 'legal_document',
        'medical_record', 'insurance', 'certificate', 'license', 'id_card',
        'obituary', 'census_record', 'vital_record', 'military_record',
        'headstone', 'cemetery_record', 'genealogy_record', 'newspaper_clipping',
        'photo', 'screenshot', 'diagram', 'chart', 'presentation',
        'spreadsheet', 'form', 'manual', 'article', 'book_page',
        'handwritten_note', 'email', 'memo', 'other',
    ];

    /** Temp directory for downloaded files */
    private string $tempDir;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
        $this->tempDir = storage_path('app/temp/ai-auto-tag');

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Set ContentExtractionService (lazy loading)
     */
    public function setContentExtractionService(ContentExtractionService $service): void
    {
        $this->contentExtraction = $service;
    }

    /**
     * Get ContentExtractionService, creating if needed
     */
    private function getContentExtractionService(): ContentExtractionService
    {
        if (! $this->contentExtraction) {
            $this->contentExtraction = app(ContentExtractionService::class);
        }

        return $this->contentExtraction;
    }

    /**
     * Set NextcloudFileApiService (lazy loading)
     */
    public function setNextcloudApi(NextcloudFileApiService $service): void
    {
        $this->nextcloudApi = $service;
    }

    /**
     * Get NextcloudFileApiService, creating if needed
     */
    private function getNextcloudApi(): NextcloudFileApiService
    {
        if (! $this->nextcloudApi) {
            $this->nextcloudApi = app(NextcloudFileApiService::class);
        }

        return $this->nextcloudApi;
    }

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    // ========================================================================
    // MAIN ANALYSIS METHODS
    // ========================================================================

    /**
     * Analyze a file from the registry and update with AI tags
     *
     * @param  int  $fileRegistryId  The file_registry.id to analyze
     * @param  bool  $forceRefresh  Re-analyze even if already done
     * @return array Analysis result with tags, description, etc.
     */
    public function analyzeFile(int $fileRegistryId, bool $forceRefresh = false): array
    {
        $file = DB::selectOne('
            SELECT id, asset_uuid, current_path, filename, extension, mime_type,
                   ai_analyzed_at, ai_analysis_version, status
            FROM file_registry
            WHERE id = ?
        ', [$fileRegistryId]);

        if (! $file) {
            return ['success' => false, 'error' => 'File not found in registry'];
        }

        if ($file->status !== 'active') {
            return ['success' => false, 'error' => "File status is '{$file->status}', not active"];
        }

        // Skip if already analyzed (unless force refresh)
        if (! $forceRefresh && $file->ai_analyzed_at && $file->ai_analysis_version === self::ANALYSIS_VERSION) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'Already analyzed with current version',
                'ai_analyzed_at' => $file->ai_analyzed_at,
            ];
        }

        $extension = strtolower($file->extension ?? '');
        $isImage = in_array($extension, config('file_types.image'));
        $isDocument = in_array($extension, config('file_types.document'));

        if (! $isImage && ! $isDocument) {
            // Permanent skip — mark so file is never re-selected
            $this->markSkipped($fileRegistryId, "unsupported_type:{$extension}");

            return [
                'success' => false,
                'skipped' => true,
                'error' => "Unsupported file type: {$extension}",
            ];
        }

        // Download file from Nextcloud to temp
        $tempPath = $this->downloadToTemp($file->current_path);
        if (! $tempPath) {
            // Track download failures — permanent skip after 3 attempts
            $this->trackFailure($fileRegistryId, 'download_failed', 3);

            return ['success' => false, 'error' => 'Failed to download file from Nextcloud'];
        }

        try {
            // FM-1: Pass folder path for hierarchical context in AI analysis
            $folderPath = $file->current_path ? dirname($file->current_path) : null;

            $result = $isImage
                ? $this->analyzeImage($tempPath, $file->filename, $folderPath)
                : $this->analyzeDocument($tempPath, $file->filename, $folderPath);

            if (! $result['success']) {
                // Track AI analysis failures — permanent skip after 5 attempts
                $this->trackFailure($fileRegistryId, $result['error'] ?? 'analysis_failed', 5);

                return $result;
            }

            // Update file registry with analysis results
            $this->updateFileWithAnalysis($fileRegistryId, $result);

            // Invalidate RAG index so backfill job re-indexes with new AI data
            DB::update('UPDATE file_registry SET rag_indexed_at = NULL WHERE id = ?', [$fileRegistryId]);

            $result['file_registry_id'] = $fileRegistryId;
            $result['asset_uuid'] = $file->asset_uuid;

            Log::info('AIAutoTagService: File analyzed', [
                'file_registry_id' => $fileRegistryId,
                'filename' => $file->filename,
                'document_type' => $result['document_type'] ?? 'unknown',
                'tag_count' => count($result['tags'] ?? []),
            ]);

            return $result;

        } finally {
            // Cleanup temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Analyze an image file
     *
     * @param  string  $filePath  Local path to image file
     * @param  string|null  $filename  Original filename for context
     * @return array Analysis result with tags, objects, scenes, OCR text
     */
    public function analyzeImage(string $filePath, ?string $filename = null, ?string $folderPath = null): array
    {
        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'Image file not found'];
        }

        $filename = $filename ?? basename($filePath);
        $imageContent = file_get_contents($filePath);

        if (! $imageContent) {
            return ['success' => false, 'error' => 'Failed to read image file'];
        }

        // Build comprehensive analysis prompt (FM-1: includes folder context)
        $prompt = $this->buildImageAnalysisPrompt($filename, $folderPath);

        // Process with vision AI (auto-routes: Ollama if free, Claude if Ollama busy)
        $visionResult = $this->aiService->processImage($imageContent, $prompt, [
            'suppressAlert' => true,
            'skip_if_busy' => true,
        ]);

        if (! $visionResult['success']) {
            return [
                'success' => false,
                'error' => 'Vision analysis failed: '.($visionResult['error'] ?? 'unknown'),
            ];
        }

        // Parse AI response
        $parsed = $this->parseAnalysisResponse($visionResult['response']);

        return [
            'success' => true,
            'tags' => $parsed['tags'] ?? [],
            'description' => $parsed['description'] ?? '',
            'document_type' => $this->normalizeDocumentType($parsed['document_type'] ?? 'photo'),
            'detected_text' => $parsed['detected_text'] ?? '',
            'objects' => $parsed['objects'] ?? [],
            'scenes' => $parsed['scenes'] ?? [],
            'colors' => $parsed['colors'] ?? [],
            'key_info' => $parsed['key_info'] ?? [],
            'confidence' => $parsed['confidence'] ?? 'medium',
            'provider' => $visionResult['provider'] ?? 'unknown',
            'analysis_version' => self::ANALYSIS_VERSION,
        ];
    }

    /**
     * Analyze a document file
     *
     * @param  string  $filePath  Local path to document file
     * @param  string|null  $filename  Original filename for context
     * @return array Analysis result with type, entities, summary
     */
    public function analyzeDocument(string $filePath, ?string $filename = null, ?string $folderPath = null): array
    {
        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'Document file not found'];
        }

        $filename = $filename ?? basename($filePath);

        // Extract content using ContentExtractionService
        $extraction = $this->getContentExtractionService()->extract($filePath, [
            'use_vision' => true,
            'use_ocr' => true,
            'extract_entities' => true,
        ]);

        $extractedText = $extraction['text'] ?? '';

        if (empty(trim($extractedText))) {
            return [
                'success' => false,
                'error' => 'No text could be extracted from document',
                'extraction_method' => $extraction['method'] ?? 'unknown',
            ];
        }

        // Analyze extracted content with AI (FM-1: includes folder context)
        $prompt = $this->buildDocumentAnalysisPrompt($filename, $extractedText, $folderPath);

        $aiResult = $this->aiService->process($prompt, [
            'max_tokens' => 2000,
            'use_cache' => false,
            'skip_if_busy' => true,
        ]);

        if (! $aiResult['success']) {
            // Still return extraction results even if AI analysis fails
            return [
                'success' => true,
                'tags' => [],
                'description' => substr($extractedText, 0, 500),
                'document_type' => $this->guessDocumentTypeFromExtension(pathinfo($filePath, PATHINFO_EXTENSION)),
                'detected_text' => $extractedText,
                'key_info' => [],
                'analysis_partial' => true,
                'ai_error' => $aiResult['error'] ?? 'AI analysis failed',
                'analysis_version' => self::ANALYSIS_VERSION,
            ];
        }

        // Parse AI response
        $parsed = $this->parseAnalysisResponse($aiResult['response']);

        return [
            'success' => true,
            'tags' => $parsed['tags'] ?? [],
            'description' => $parsed['description'] ?? '',
            'document_type' => $this->normalizeDocumentType($parsed['document_type'] ?? 'other'),
            'detected_text' => $extractedText,
            'key_info' => $parsed['key_info'] ?? [],
            'entities' => $parsed['entities'] ?? [],
            'summary' => $parsed['summary'] ?? '',
            'confidence' => $parsed['confidence'] ?? 'medium',
            'extraction_method' => $extraction['method'] ?? 'unknown',
            'provider' => $aiResult['provider'] ?? 'unknown',
            'analysis_version' => self::ANALYSIS_VERSION,
        ];
    }

    // ========================================================================
    // BATCH PROCESSING
    // ========================================================================

    /**
     * Process multiple files in batch
     *
     * @param  array  $fileRegistryIds  Array of file_registry IDs to analyze
     * @param  int  $batchSize  Process in chunks of this size
     * @param  bool  $forceRefresh  Re-analyze even if already done
     * @return array Batch results summary
     */
    public function batchAnalyze(array $fileRegistryIds, int $batchSize = 10, bool $forceRefresh = false): array
    {
        $results = [
            'total' => count($fileRegistryIds),
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Recover files left in 'processing' state from crashed previous runs (SIGALRM poison pill fix)
        $this->recoverOrphanedProcessingFiles();

        $chunks = array_chunk($fileRegistryIds, $batchSize);
        $perFileTimeoutSeconds = 90; // Hard cap per file — prevents pdftoppm/LLM hangs from consuming entire batch
        $fileTimes = []; // Track actual per-file times for adaptive abort

        foreach ($chunks as $chunk) {
            foreach ($chunk as $id) {
                // Adaptive early-abort: predict if next file would exceed remaining alarm time
                if (function_exists('pcntl_alarm') && count($fileTimes) >= 3) {
                    $remaining = pcntl_alarm(0);
                    if ($remaining > 0) {
                        // Use p75 of recent file times as estimate for next file
                        $sorted = $fileTimes;
                        sort($sorted);
                        $p75Index = (int) floor(count($sorted) * 0.75);
                        $estimatedNext = $sorted[$p75Index] ?? end($sorted);
                        $buffer = 60; // 60s safety buffer for cleanup

                        if ($remaining < ($estimatedNext + $buffer)) {
                            Log::info('AIAutoTagService: Adaptive abort — next file would exceed alarm', [
                                'remaining_seconds' => $remaining,
                                'estimated_next_seconds' => round($estimatedNext, 1),
                                'p75_seconds' => round($estimatedNext, 1),
                                'files_processed' => $results['processed'],
                                'files_in_batch' => count($fileRegistryIds),
                            ]);
                            pcntl_alarm($remaining); // Re-arm

                            return $results;
                        }
                        pcntl_alarm($remaining); // Re-arm
                    }
                }

                $fileStart = microtime(true);
                try {
                    // Mark as 'processing' BEFORE analysis — if SIGALRM kills us,
                    // the next run's recoverOrphanedProcessingFiles() will increment fail counter
                    DB::update("UPDATE file_registry SET ai_analysis_version = 'processing', updated_at = NOW() WHERE id = ?", [$id]);

                    $result = $this->analyzeFile($id, $forceRefresh);

                    if ($result['skipped'] ?? false) {
                        $results['skipped']++;
                    } elseif ($result['success']) {
                        $results['processed']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][$id] = $result['error'] ?? 'Unknown error';
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][$id] = $e->getMessage();
                    $this->trackFailure($id, 'exception:'.mb_substr($e->getMessage(), 0, 80), 5);

                    Log::error('AIAutoTagService: Batch analysis failed for file', [
                        'file_registry_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $elapsed = microtime(true) - $fileStart;
                $fileTimes[] = $elapsed;

                if ($elapsed > $perFileTimeoutSeconds) {
                    Log::warning('AIAutoTagService: File analysis exceeded per-file timeout', [
                        'file_registry_id' => $id,
                        'elapsed_seconds' => round($elapsed, 1),
                        'timeout_seconds' => $perFileTimeoutSeconds,
                    ]);
                }

                // Fallback hard check — abort if alarm < 2min (catches edge cases)
                if (function_exists('pcntl_alarm')) {
                    $remaining = pcntl_alarm(0);
                    if ($remaining > 0 && $remaining < 120) {
                        Log::warning('AIAutoTagService: Hard abort — parent alarm has <2min remaining', [
                            'remaining_seconds' => $remaining,
                            'processed_so_far' => $results['processed'],
                        ]);
                        pcntl_alarm($remaining); // Re-arm what was left

                        return $results;
                    }
                    if ($remaining > 0) {
                        pcntl_alarm($remaining); // Re-arm — pcntl_alarm(0) cancels it
                    }
                }
            }

            // Small delay between batches to prevent overwhelming AI services
            usleep(500000); // 0.5 second
        }

        return $results;
    }

    /**
     * Get files that haven't been analyzed yet
     *
     * @param  int  $limit  Max files to return
     * @param  string|null  $extensionFilter  Filter by extension (e.g., 'jpg', 'pdf')
     * @return array Array of file registry records
     */
    public function getUnanalyzedFiles(int $limit = 100, ?string $extensionFilter = null): array
    {
        $supportedExtensions = array_merge(config('file_types.image'), config('file_types.document'));
        $extensionPlaceholders = implode(',', array_fill(0, count($supportedExtensions), '?'));

        $sql = "
            SELECT id, asset_uuid, current_path, filename, extension, mime_type, file_size
            FROM file_registry
            WHERE status = 'active'
              AND (
                  (ai_analyzed_at IS NULL AND (ai_analysis_version IS NULL OR (ai_analysis_version NOT IN ('skipped', 'processing') AND ai_analysis_version NOT LIKE 'fail:%')))
                  OR
                  (ai_analyzed_at IS NOT NULL AND ai_analysis_version != ? AND ai_analysis_version != 'skipped' AND ai_analysis_version != 'processing' AND ai_analysis_version NOT LIKE 'fail:%')
              )
              AND extension IN ({$extensionPlaceholders})
        ";

        $params = [self::ANALYSIS_VERSION, ...$supportedExtensions];

        if ($extensionFilter) {
            $sql .= ' AND extension = ?';
            $params[] = strtolower($extensionFilter);
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;

        return DB::select($sql, $params);
    }

    // ========================================================================
    // SEARCH METHODS
    // ========================================================================

    /**
     * Search files by AI-generated tags
     *
     * @param  array  $tags  Tags to search for
     * @param  string  $matchMode  'any' (OR) or 'all' (AND)
     * @param  int  $limit  Max results
     * @return array Matching files
     */
    public function searchByTags(array $tags, string $matchMode = 'any', int $limit = 50): array
    {
        if (empty($tags)) {
            return [];
        }

        // Build JSON search conditions for each tag
        $conditions = [];
        $params = [];

        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            if (empty($tag)) {
                continue;
            }

            // Search in ai_tags JSON array for tag name
            $conditions[] = "JSON_SEARCH(LOWER(ai_tags), 'one', ?, NULL, '\$[*].tag') IS NOT NULL";
            $params[] = $tag;
        }

        if (empty($conditions)) {
            return [];
        }

        $operator = $matchMode === 'all' ? ' AND ' : ' OR ';
        $whereClause = '('.implode($operator, $conditions).')';

        $sql = "
            SELECT id, asset_uuid, current_path, filename, extension, mime_type,
                   ai_tags, ai_description, ai_document_type, ai_analyzed_at
            FROM file_registry
            WHERE status = 'active'
              AND ai_tags IS NOT NULL
              AND {$whereClause}
            ORDER BY ai_analyzed_at DESC
            LIMIT ?
        ";
        $params[] = $limit;

        $results = DB::select($sql, $params);

        // Decode JSON tags for each result
        return array_map(function ($row) {
            $row->ai_tags = $row->ai_tags ? json_decode($row->ai_tags, true) : null;

            return $row;
        }, $results);
    }

    /**
     * Search files by document type
     *
     * @param  string  $documentType  Document type to search for
     * @param  int  $limit  Max results
     * @return array Matching files
     */
    public function searchByDocumentType(string $documentType, int $limit = 50): array
    {
        $sql = "
            SELECT id, asset_uuid, current_path, filename, extension, mime_type,
                   ai_tags, ai_description, ai_document_type, ai_analyzed_at
            FROM file_registry
            WHERE status = 'active'
              AND ai_document_type = ?
            ORDER BY ai_analyzed_at DESC
            LIMIT ?
        ";

        $results = DB::select($sql, [$documentType, $limit]);

        return array_map(function ($row) {
            $row->ai_tags = $row->ai_tags ? json_decode($row->ai_tags, true) : null;

            return $row;
        }, $results);
    }

    /**
     * Full-text search in AI descriptions and detected text
     *
     * @param  string  $query  Search query
     * @param  int  $limit  Max results
     * @return array Matching files
     */
    public function searchByContent(string $query, int $limit = 50): array
    {
        $searchTerm = '%'.$query.'%';

        $sql = "
            SELECT id, asset_uuid, current_path, filename, extension, mime_type,
                   ai_tags, ai_description, ai_document_type, ai_detected_text, ai_analyzed_at
            FROM file_registry
            WHERE status = 'active'
              AND (
                  ai_description LIKE ?
                  OR ai_detected_text LIKE ?
                  OR filename LIKE ?
              )
            ORDER BY ai_analyzed_at DESC
            LIMIT ?
        ";

        $results = DB::select($sql, [$searchTerm, $searchTerm, $searchTerm, $limit]);

        return array_map(function ($row) {
            $row->ai_tags = $row->ai_tags ? json_decode($row->ai_tags, true) : null;
            // Truncate detected_text for display
            if ($row->ai_detected_text && strlen($row->ai_detected_text) > 500) {
                $row->ai_detected_text = substr($row->ai_detected_text, 0, 500).'...';
            }

            return $row;
        }, $results);
    }

    /**
     * Get analysis statistics
     *
     * @return array Stats about analyzed files
     */
    public function getStats(): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_files,
                SUM(CASE WHEN ai_analyzed_at IS NOT NULL THEN 1 ELSE 0 END) as analyzed_count,
                SUM(CASE WHEN ai_analyzed_at IS NULL AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif','pdf','doc','docx','rtf','odt','txt','xls','xlsx','csv','ods','ppt','pptx','odp','html','htm','md') THEN 1 ELSE 0 END) as pending_count
            FROM file_registry
            WHERE status = 'active'
        ");

        $typeBreakdown = DB::select("
            SELECT ai_document_type, COUNT(*) as count
            FROM file_registry
            WHERE status = 'active' AND ai_document_type IS NOT NULL
            GROUP BY ai_document_type
            ORDER BY count DESC
            LIMIT 20
        ");

        $topTags = DB::select("
            SELECT
                JSON_UNQUOTE(JSON_EXTRACT(tag_item, '\$.tag')) as tag_name,
                COUNT(*) as count
            FROM file_registry,
                 JSON_TABLE(ai_tags, '\$[*]' COLUMNS (tag_item JSON PATH '\$')) as tags
            WHERE status = 'active' AND ai_tags IS NOT NULL
            GROUP BY tag_name
            ORDER BY count DESC
            LIMIT 20
        ");

        return [
            'total_files' => (int) $stats->total_files,
            'analyzed_count' => (int) $stats->analyzed_count,
            'pending_count' => (int) $stats->pending_count,
            'analysis_version' => self::ANALYSIS_VERSION,
            'document_types' => array_map(fn ($r) => ['type' => $r->ai_document_type, 'count' => (int) $r->count], $typeBreakdown),
            'top_tags' => array_map(fn ($r) => ['tag' => $r->tag_name, 'count' => (int) $r->count], $topTags),
        ];
    }

    // ========================================================================
    // PRIVATE HELPER METHODS
    // ========================================================================

    /**
     * Download file from Nextcloud to temp directory.
     * Uses direct filesystem copy when NEXTCLOUD_DATA_PATH is configured (avoids WebDAV hang).
     */
    private function downloadToTemp(string $nextcloudPath): ?string
    {
        try {
            $extension = pathinfo($nextcloudPath, PATHINFO_EXTENSION);
            $tempPath = $this->tempDir.'/'.uniqid('ai_tag_').'.'.$extension;

            // Filesystem-first: skip WebDAV if local path is available
            $localPath = $this->getNextcloudApi()->localPath($nextcloudPath);
            if ($localPath) {
                if (! copy($localPath, $tempPath)) {
                    Log::warning('AIAutoTagService: Failed to copy local file to temp', [
                        'local_path' => $localPath,
                        'temp_path' => $tempPath,
                    ]);

                    return null;
                }

                return $tempPath;
            }

            $result = $this->getNextcloudApi()->downloadFile($nextcloudPath);

            if (! $result['success']) {
                Log::warning('AIAutoTagService: Failed to download file', [
                    'path' => $nextcloudPath,
                    'error' => $result['error'] ?? 'Unknown',
                ]);

                return null;
            }

            // Write content to temp file
            $written = file_put_contents($tempPath, $result['content']);
            if ($written === false) {
                Log::warning('AIAutoTagService: Failed to write temp file', [
                    'path' => $nextcloudPath,
                    'temp_path' => $tempPath,
                ]);

                return null;
            }

            return $tempPath;
        } catch (Exception $e) {
            Log::error('AIAutoTagService: Download exception', [
                'path' => $nextcloudPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update file registry with analysis results
     */
    private function updateFileWithAnalysis(int $fileRegistryId, array $result): void
    {
        $tags = $result['tags'] ?? [];

        // Ensure tags is a proper JSON string (ai_tags is JSON column)
        if (is_string($tags)) {
            $tagsJson = $tags;
        } elseif (is_array($tags) && ! empty($tags)) {
            $tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE);
        } else {
            $tagsJson = null;
        }

        // Flatten any array/object values to strings before DB insert
        $description = $result['description'] ?? null;
        if (is_array($description) || is_object($description)) {
            $description = json_encode($description, JSON_UNESCAPED_UNICODE);
        }
        $description = $description !== null ? (string) $description : null;

        // Detect AI error responses stored as descriptions (e.g. Claude sandbox permission errors)
        if ($description !== null && $this->isAiErrorResponse($description)) {
            Log::warning('AI analysis returned error response, discarding', [
                'file_id' => $fileRegistryId,
                'response_preview' => mb_substr($description, 0, 200),
            ]);

            return; // Don't overwrite existing data with error text
        }

        $detectedText = $result['detected_text'] ?? null;
        if (is_array($detectedText)) {
            $detectedText = implode("\n", array_map(function ($v) {
                return is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
            }, $detectedText));
        } elseif (is_object($detectedText)) {
            $detectedText = json_encode($detectedText, JSON_UNESCAPED_UNICODE);
        }
        $detectedText = $detectedText !== null ? (string) $detectedText : null;
        // MEDIUMTEXT utf8mb4 max is ~4MB — truncate to 1MB for sanity
        if ($detectedText !== null && strlen($detectedText) > 1000000) {
            $detectedText = mb_substr($detectedText, 0, 1000000);
        }

        $documentType = $result['document_type'] ?? null;
        if (is_array($documentType)) {
            // Pick first element if array, normalize it
            $documentType = reset($documentType) ?: 'other';
        } elseif (is_object($documentType)) {
            $documentType = 'other';
        }
        $documentType = $documentType !== null ? (string) $documentType : null;
        // ai_document_type is VARCHAR(50) — truncate to prevent data-too-long error
        if ($documentType !== null) {
            $documentType = mb_substr($documentType, 0, 50);
        }

        // ai_analysis_version is VARCHAR(20) — truncate safety
        $analysisVersion = mb_substr(self::ANALYSIS_VERSION, 0, 20);

        DB::update('
            UPDATE file_registry
            SET ai_tags = ?,
                ai_description = ?,
                ai_document_type = ?,
                ai_detected_text = ?,
                ai_analyzed_at = NOW(),
                ai_analysis_version = ?,
                updated_at = NOW()
            WHERE id = ?
        ', [
            $tagsJson,
            $description,
            $documentType,
            $detectedText,
            $analysisVersion,
            $fileRegistryId,
        ]);
    }

    /**
     * Recover files left in 'processing' state from crashed previous runs.
     * When SIGALRM kills a process, trackFailure() never executes for the
     * in-progress file. This method finds those orphans and increments their
     * failure counter so they eventually get permanently skipped.
     */
    private function recoverOrphanedProcessingFiles(): void
    {
        $orphaned = DB::select("
            SELECT id, filename FROM file_registry WHERE ai_analysis_version = 'processing'
        ");

        foreach ($orphaned as $file) {
            Log::warning('AIAutoTagService: Recovering orphaned processing file (likely SIGALRM crash)', [
                'file_registry_id' => $file->id,
                'filename' => $file->filename,
            ]);
            $this->trackFailure($file->id, 'timeout_crash', 5);
        }

        if (count($orphaned) > 0) {
            Log::info('AIAutoTagService: Recovered orphaned processing files', [
                'count' => count($orphaned),
            ]);
        }
    }

    /**
     * Mark a file as permanently skipped for AI analysis.
     * Sets ai_analyzed_at so it's excluded from future selection.
     */
    private function markSkipped(int $fileRegistryId, string $reason): void
    {
        DB::update("
            UPDATE file_registry
            SET ai_analyzed_at = NOW(),
                ai_analysis_version = 'skipped',
                ai_description = ?,
                updated_at = NOW()
            WHERE id = ?
        ", ["[skipped] {$reason}", $fileRegistryId]);
    }

    /**
     * Track transient failures. After maxRetries, permanently skip the file.
     * Uses ai_analysis_version as a lightweight retry counter (e.g., 'fail:3').
     */
    private function trackFailure(int $fileRegistryId, string $reason, int $maxRetries = 5): void
    {
        $file = DB::selectOne('SELECT ai_analysis_version FROM file_registry WHERE id = ?', [$fileRegistryId]);
        $failCount = 0;

        if ($file && $file->ai_analysis_version && str_starts_with($file->ai_analysis_version, 'fail:')) {
            $failCount = (int) substr($file->ai_analysis_version, 5);
        }

        $failCount++;

        if ($failCount >= $maxRetries) {
            // Permanent skip
            $this->markSkipped($fileRegistryId, "max_retries:{$reason}");
            Log::warning('AIAutoTagService: Permanently skipping file after max retries', [
                'file_registry_id' => $fileRegistryId,
                'reason' => $reason,
                'attempts' => $failCount,
            ]);
        } else {
            // Increment counter but keep file eligible
            DB::update('
                UPDATE file_registry SET ai_analysis_version = ?, updated_at = NOW() WHERE id = ?
            ', ["fail:{$failCount}", $fileRegistryId]);
        }
    }

    /**
     * Detect if an AI response is actually an error message rather than real analysis.
     * Claude CLI sandbox errors, permission denials, and other non-analysis responses.
     */
    private function isAiErrorResponse(string $text): bool
    {
        $errorPatterns = [
            'I need permission to read',
            'Please grant access to',
            'I cannot access the file',
            'I don\'t have permission',
            'Unable to read the image',
            'I cannot read the file',
            'file not found',
            'I\'m unable to view',
            'I cannot view the image',
            'I\'m not able to see',
            'I can\'t access',
            'permission denied',
            '/tmp/claude_img_',
        ];

        $lower = strtolower($text);
        foreach ($errorPatterns as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build prompt for image analysis
     */
    private function buildImageAnalysisPrompt(string $filename, ?string $folderPath = null): string
    {
        $docTypes = implode(', ', self::DOCUMENT_TYPES);

        // FM-1: Inject folder path for hierarchical context
        $folderContext = $folderPath
            ? "The file is located at: {$folderPath}\nUse the folder path as context for what this file likely contains.\n\n"
            : '';

        return <<<PROMPT
Analyze this image comprehensively. The filename is: {$filename}
{$folderContext}

Provide the following in JSON format:
{
    "document_type": "one of: {$docTypes}",
    "description": "A detailed 1-3 sentence description of the image content",
    "tags": [
        {"tag": "tag_name", "confidence": 0.0-1.0},
        ...
    ],
    "objects": ["list", "of", "detected", "objects"],
    "scenes": ["indoor", "outdoor", "office", etc.],
    "colors": ["dominant", "colors"],
    "detected_text": "Any text visible in the image (OCR)",
    "key_info": {
        "names": ["any names found"],
        "dates": ["any dates found"],
        "amounts": ["any monetary amounts"],
        "addresses": ["any addresses"],
        "other": "any other important information"
    },
    "confidence": "high/medium/low"
}

Focus on:
1. What type of document/photo this is
2. Key information visible (text, numbers, dates)
3. Relevant tags for categorization and search
4. Objects and scenes for photos

Respond ONLY with valid JSON, no additional text.
PROMPT;
    }

    /**
     * Build prompt for document analysis
     */
    private function buildDocumentAnalysisPrompt(string $filename, string $extractedText, ?string $folderPath = null): string
    {
        $docTypes = implode(', ', self::DOCUMENT_TYPES);
        $textSample = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'extracted_file',
            contentType: 'text/plain',
            origin: $folderPath ? "{$folderPath}/{$filename}" : $filename,
            payload: $extractedText,
            maxChars: 8000,
        ));

        // FM-1: folder context
        $folderContext = $folderPath ? "\nFile location: {$folderPath}\n" : '';

        return <<<PROMPT
Analyze this document. Filename: {$filename}{$folderContext}

Document content:
---
{$textSample}
---

Provide the following in JSON format:
{
    "document_type": "one of: {$docTypes}",
    "description": "A detailed 1-3 sentence summary of the document",
    "summary": "A longer summary of key points (3-5 sentences)",
    "tags": [
        {"tag": "tag_name", "confidence": 0.0-1.0},
        ...
    ],
    "key_info": {
        "names": ["any person/company names"],
        "dates": ["significant dates"],
        "amounts": ["monetary amounts"],
        "addresses": ["any addresses"],
        "other": "any other important information"
    },
    "entities": {
        "people": ["names"],
        "organizations": ["company names"],
        "locations": ["places"],
        "dates": ["dates"]
    },
    "confidence": "high/medium/low"
}

Focus on:
1. Document classification (what type is it?)
2. Key entities and information
3. Relevant tags for search and organization
4. Brief but informative summary

Respond ONLY with valid JSON, no additional text.
PROMPT;
    }

    /**
     * Parse AI response into structured data
     */
    private function parseAnalysisResponse(string $response): array
    {
        // Extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $json = json_decode($matches[0], true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                // Normalize tags format
                if (isset($json['tags']) && is_array($json['tags'])) {
                    $json['tags'] = array_map(function ($tag) {
                        if (is_string($tag)) {
                            return ['tag' => strtolower($tag), 'confidence' => 0.8];
                        }
                        if (is_array($tag) && isset($tag['tag'])) {
                            return [
                                'tag' => strtolower($tag['tag']),
                                'confidence' => (float) ($tag['confidence'] ?? 0.8),
                            ];
                        }

                        return null;
                    }, $json['tags']);

                    // Remove nulls and limit to top 20 tags
                    $json['tags'] = array_slice(array_filter($json['tags']), 0, 20);
                }

                return $json;
            }
        }

        // Fallback: try to extract some info from raw text
        return [
            'description' => substr($response, 0, 500),
            'tags' => [],
            'document_type' => 'other',
            'confidence' => 'low',
        ];
    }

    /**
     * Normalize document type to standard values
     */
    private function normalizeDocumentType(string|array $type): string
    {
        // LLM sometimes returns array instead of string
        if (is_array($type)) {
            $type = $type[0] ?? 'other';
        }
        $type = strtolower(trim($type));

        // Direct match
        if (in_array($type, self::DOCUMENT_TYPES)) {
            return $type;
        }

        // Common aliases
        $aliases = [
            'photograph' => 'photo',
            'image' => 'photo',
            'picture' => 'photo',
            'scan' => 'photo',
            'scanned' => 'photo',
            'bill_of_sale' => 'invoice',
            'sales_receipt' => 'receipt',
            'purchase_receipt' => 'receipt',
            'agreement' => 'contract',
            'cv' => 'resume',
            'curriculum_vitae' => 'resume',
            'tax_form' => 'tax_document',
            'w2' => 'tax_document',
            '1099' => 'tax_document',
            'drivers_license' => 'license',
            'driver_license' => 'license',
            'passport' => 'id_card',
            'identification' => 'id_card',
            'note' => 'handwritten_note',
            'notes' => 'handwritten_note',
            'excel' => 'spreadsheet',
            'powerpoint' => 'presentation',
            'slides' => 'presentation',
        ];

        if (isset($aliases[$type])) {
            return $aliases[$type];
        }

        // Partial match
        foreach (self::DOCUMENT_TYPES as $docType) {
            if (str_contains($type, $docType) || str_contains($docType, $type)) {
                return $docType;
            }
        }

        return 'other';
    }

    /**
     * Guess document type from file extension
     */
    private function guessDocumentTypeFromExtension(string $extension): string
    {
        $extension = strtolower($extension);

        $typeMap = [
            'pdf' => 'other',
            'doc' => 'other',
            'docx' => 'other',
            'xls' => 'spreadsheet',
            'xlsx' => 'spreadsheet',
            'csv' => 'spreadsheet',
            'ppt' => 'presentation',
            'pptx' => 'presentation',
            'jpg' => 'photo',
            'jpeg' => 'photo',
            'png' => 'photo',
            'gif' => 'photo',
            'txt' => 'other',
            'md' => 'article',
            'html' => 'article',
        ];

        return $typeMap[$extension] ?? 'other';
    }

    // ─── File Curator Agent Methods ───

    /**
     * Analyze tag quality across the file registry
     */
    public function getTagQualityReport(int $limit = 50): array
    {
        $genericTags = ['file', 'document', 'unknown', 'other', 'image', 'text', 'data'];

        // Get files with AI tags for analysis
        $files = DB::select("
            SELECT asset_uuid, filename, ai_tags, ai_document_type
            FROM file_registry
            WHERE status = 'active' AND ai_tags IS NOT NULL AND ai_tags != '[]' AND ai_tags != 'null'
            ORDER BY ai_analyzed_at DESC
            LIMIT ?
        ", [$limit]);

        $totalTagged = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry
            WHERE status = 'active' AND ai_tags IS NOT NULL AND ai_tags != '[]' AND ai_tags != 'null'
        ");

        $genericTagFiles = [];
        $lowConfidenceFiles = [];
        $tagCounts = [];
        $typeCounts = [];
        $qualityScores = [];

        foreach ($files as $f) {
            $tags = json_decode($f->ai_tags, true);
            if (! is_array($tags)) {
                continue;
            }

            $fileScore = 1.0;
            $fileGenericTags = [];

            foreach ($tags as $tagEntry) {
                $tagName = is_array($tagEntry) ? ($tagEntry['tag'] ?? $tagEntry[0] ?? '') : (string) $tagEntry;
                $confidence = is_array($tagEntry) ? ($tagEntry['confidence'] ?? 1.0) : 1.0;
                $tagName = strtolower(trim($tagName));

                if (empty($tagName)) {
                    continue;
                }

                $tagCounts[$tagName] = ($tagCounts[$tagName] ?? 0) + 1;

                // Check generic tags
                if (in_array($tagName, $genericTags)) {
                    $fileGenericTags[] = $tagName;
                    $fileScore -= 0.2;
                }

                // Check low confidence
                if ($confidence < 0.5) {
                    $lowConfidenceFiles[$f->asset_uuid] = [
                        'uuid' => $f->asset_uuid,
                        'filename' => $f->filename,
                        'tag' => $tagName,
                        'confidence' => $confidence,
                    ];
                    $fileScore -= 0.1;
                }
            }

            if (! empty($fileGenericTags)) {
                $genericTagFiles[] = [
                    'uuid' => $f->asset_uuid,
                    'filename' => $f->filename,
                    'generic_tags' => $fileGenericTags,
                ];
            }

            if ($f->ai_document_type) {
                $typeCounts[$f->ai_document_type] = ($typeCounts[$f->ai_document_type] ?? 0) + 1;
            }

            $qualityScores[] = max(0, $fileScore);
        }

        $avgQuality = ! empty($qualityScores) ? round(array_sum($qualityScores) / count($qualityScores), 2) : 0;

        // Tag distribution (top 20)
        arsort($tagCounts);
        $topTags = array_slice($tagCounts, 0, 20, true);

        // Document type distribution
        arsort($typeCounts);

        // "other" type dominance check
        $otherPct = isset($typeCounts['other']) && array_sum($typeCounts) > 0
            ? round($typeCounts['other'] / array_sum($typeCounts) * 100, 1) : 0;

        $recommendations = [];
        if ($otherPct > 30) {
            $recommendations[] = "Document type 'other' accounts for {$otherPct}% of typed files — AI classification may need prompt tuning";
        }
        if (count($genericTagFiles) > count($files) * 0.3) {
            $recommendations[] = count($genericTagFiles).' files have generic/unhelpful tags — consider re-analysis with improved prompts';
        }
        if (count($lowConfidenceFiles) > count($files) * 0.2) {
            $recommendations[] = count($lowConfidenceFiles).' files have low-confidence tags — candidates for re-analysis';
        }

        return [
            'quality_score' => $avgQuality,
            'total_tagged_files' => $totalTagged->cnt ?? 0,
            'sample_size' => count($files),
            'tag_distribution' => $topTags,
            'document_type_distribution' => $typeCounts,
            'generic_tag_files' => array_slice($genericTagFiles, 0, 10),
            'generic_tag_count' => count($genericTagFiles),
            'low_confidence_files' => array_slice(array_values($lowConfidenceFiles), 0, 10),
            'low_confidence_count' => count($lowConfidenceFiles),
            'other_type_percentage' => $otherPct,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Review AI-generated tags for quality issues
     */
    public function reviewTagQuality(int $limit = 50): array
    {
        $files = DB::select("
            SELECT asset_uuid, filename, extension, ai_tags, ai_document_type, ai_analyzed_at
            FROM file_registry
            WHERE status = 'active' AND ai_tags IS NOT NULL AND ai_tags != '[]' AND ai_tags != 'null'
            ORDER BY ai_analyzed_at DESC
            LIMIT ?
        ", [$limit]);

        $genericTags = ['file', 'document', 'unknown', 'other', 'image', 'text', 'data', 'content', 'item'];
        $genericTagFiles = [];
        $lowConfidenceFiles = [];
        $reAnalysisCandidates = [];

        foreach ($files as $f) {
            $tags = json_decode($f->ai_tags, true);
            if (! is_array($tags)) {
                continue;
            }

            $hasOnlyGeneric = true;
            $hasLowConf = false;

            foreach ($tags as $tagEntry) {
                $tagName = strtolower(is_array($tagEntry) ? ($tagEntry['tag'] ?? $tagEntry[0] ?? '') : (string) $tagEntry);
                $confidence = is_array($tagEntry) ? ($tagEntry['confidence'] ?? 1.0) : 1.0;

                if (! empty($tagName) && ! in_array($tagName, $genericTags)) {
                    $hasOnlyGeneric = false;
                }
                if ($confidence < 0.4) {
                    $hasLowConf = true;
                }
            }

            if ($hasOnlyGeneric && count($tags) > 0) {
                $genericTagFiles[] = [
                    'uuid' => $f->asset_uuid,
                    'filename' => $f->filename,
                    'tags' => $tags,
                ];
            }

            if ($hasLowConf) {
                $lowConfidenceFiles[] = [
                    'uuid' => $f->asset_uuid,
                    'filename' => $f->filename,
                ];
            }

            // Candidate for re-analysis: generic tags only OR all low confidence
            if ($hasOnlyGeneric || $hasLowConf) {
                $reAnalysisCandidates[] = $f->asset_uuid;
            }
        }

        return [
            'reviewed' => count($files),
            'issues_found' => count($genericTagFiles) + count($lowConfidenceFiles),
            'generic_tag_files' => array_slice($genericTagFiles, 0, 15),
            'generic_tag_count' => count($genericTagFiles),
            'low_confidence_files' => array_slice($lowConfidenceFiles, 0, 15),
            'low_confidence_count' => count($lowConfidenceFiles),
            're_analysis_candidates' => count($reAnalysisCandidates),
        ];
    }

    /**
     * Check tag consistency across similar files
     */
    public function checkTagConsistency(int $limit = 100): array
    {
        // Group files by extension and check tag consistency within groups
        $files = DB::select("
            SELECT asset_uuid, filename, extension, ai_tags, ai_document_type
            FROM file_registry
            WHERE status = 'active'
            AND ai_tags IS NOT NULL AND ai_tags != '[]' AND ai_tags != 'null'
            AND ai_document_type IS NOT NULL
            ORDER BY extension, ai_analyzed_at DESC
            LIMIT ?
        ", [$limit]);

        // Group by extension
        $byExtension = [];
        foreach ($files as $f) {
            $ext = strtolower($f->extension ?? 'unknown');
            $byExtension[$ext][] = $f;
        }

        $inconsistencies = [];
        $typesByExtension = [];

        foreach ($byExtension as $ext => $extFiles) {
            $types = [];
            foreach ($extFiles as $f) {
                $type = $f->ai_document_type ?? 'untyped';
                $types[$type] = ($types[$type] ?? 0) + 1;
            }
            $typesByExtension[$ext] = $types;

            // If same extension has very different document types, that's inconsistent
            if (count($types) > 3 && count($extFiles) > 5) {
                $inconsistencies[] = [
                    'extension' => $ext,
                    'issue' => "Extension '.{$ext}' classified as ".count($types).' different document types',
                    'type_distribution' => $types,
                    'sample_count' => count($extFiles),
                ];
            }
        }

        // Check for document_type drift (types that might be the same thing)
        $allTypes = DB::select("
            SELECT ai_document_type, COUNT(*) as cnt
            FROM file_registry
            WHERE status = 'active' AND ai_document_type IS NOT NULL
            GROUP BY ai_document_type
            ORDER BY cnt DESC
        ");

        $typeNames = array_column($allTypes, 'ai_document_type');
        $driftPatterns = [];

        // Find similar type names that might be duplicates
        for ($i = 0; $i < count($typeNames); $i++) {
            for ($j = $i + 1; $j < count($typeNames); $j++) {
                $similarity = 0;
                similar_text(strtolower($typeNames[$i]), strtolower($typeNames[$j]), $similarity);
                if ($similarity > 70 && $typeNames[$i] !== $typeNames[$j]) {
                    $driftPatterns[] = [
                        'type_a' => $typeNames[$i],
                        'type_b' => $typeNames[$j],
                        'similarity' => round($similarity, 1),
                    ];
                }
            }
        }

        $totalTypes = count($typeNames);
        $consistencyScore = $totalTypes > 0
            ? round(1 - (count($inconsistencies) + count($driftPatterns)) / max($totalTypes, 1), 2)
            : 1.0;
        $consistencyScore = max(0, min(1, $consistencyScore));

        return [
            'consistency_score' => $consistencyScore,
            'total_document_types' => $totalTypes,
            'files_sampled' => count($files),
            'inconsistencies' => array_slice($inconsistencies, 0, 10),
            'inconsistency_count' => count($inconsistencies),
            'tag_drift_patterns' => array_slice($driftPatterns, 0, 10),
            'drift_count' => count($driftPatterns),
            'type_by_extension' => $typesByExtension,
        ];
    }
}

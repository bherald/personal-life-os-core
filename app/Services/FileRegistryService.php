<?php

namespace App\Services;

use App\Services\Genealogy\FaceLinkBridgeService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * File Registry Service - File Catalog
 *
 * Provides read-only file cataloging with RAG sync:
 * - Register files from the configured Nextcloud library directory
 * - Track file changes via content hash and nextcloud_fileid
 * - Detect duplicates and moved files
 * - Sync with RAG for full-text search
 *
 * Uses a three-tier lookup strategy:
 *   1. asset_uuid (our permanent reference)
 *   2. nextcloud_fileid (survives moves within Nextcloud)
 *   3. current_path (fallback, updated on moves)
 *
 * Reference format: {{ASSET:uuid-here}}
 */
class FileRegistryService
{
    private const MAX_ORPHAN_WARNING_DETAILS_PER_PROCESS = 1;

    private static int $orphanWarningsLogged = 0;

    private static bool $orphanWarningSummaryLogged = false;

    private NextcloudFileApiService $nextcloudApi;

    private ?PhotoAnalysisService $photoAnalysis = null;

    private ?AIService $aiService = null;

    private ?MediaAnalysisService $mediaAnalysis = null;

    private ?PerceptualHashService $perceptualHash = null;

    private ?VideoHashService $videoHash = null;

    private ?AIAutoTagService $aiAutoTag = null;

    private ?ThumbnailService $thumbnailService = null;

    private ?FileCategorizationRAGService $fileCategorizationRag = null;

    private ?FileRegistryLifecycleService $lifecycleService = null;

    /** Document extensions for content extraction via Tika */
    /** @see config/file_types.php — use imageExtensions()/videoExtensions()/documentExtensions() */

    /** Temp directory for downloaded files */
    private string $tempDir;

    public function __construct(NextcloudFileApiService $nextcloudApi)
    {
        $this->nextcloudApi = $nextcloudApi;
        $this->tempDir = storage_path('app/temp/file-registry');

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Set the AI service for document content analysis
     */
    public function setAIService(AIService $aiService): void
    {
        $this->aiService = $aiService;
    }

    /**
     * Get the AI service, resolving from container if needed
     */
    private function getAIService(): ?AIService
    {
        if (! $this->aiService) {
            try {
                $this->aiService = app(AIService::class);
            } catch (Exception $e) {
                Log::debug('FileRegistry: AIService not available', ['error' => $e->getMessage()]);

                return null;
            }
        }

        return $this->aiService;
    }

    private function getLifecycleService(): FileRegistryLifecycleService
    {
        if (! $this->lifecycleService) {
            $this->lifecycleService = app(FileRegistryLifecycleService::class);
        }

        return $this->lifecycleService;
    }

    /**
     * Get the photo analysis service, creating if needed
     */
    private function getPhotoAnalysisService(): PhotoAnalysisService
    {
        if (! $this->photoAnalysis) {
            $this->photoAnalysis = new PhotoAnalysisService($this->nextcloudApi);
        }

        return $this->photoAnalysis;
    }

    /**
     * Get the media analysis service, creating if needed
     */
    private function getMediaAnalysisService(): MediaAnalysisService
    {
        if (! $this->mediaAnalysis) {
            $this->mediaAnalysis = new MediaAnalysisService;
        }

        return $this->mediaAnalysis;
    }

    /**
     * Set the perceptual hash service (optional dependency)
     */
    public function setPerceptualHashService(PerceptualHashService $perceptualHash): void
    {
        $this->perceptualHash = $perceptualHash;
    }

    /**
     * Get the perceptual hash service, creating if needed
     */
    private function getPerceptualHashService(): PerceptualHashService
    {
        if (! $this->perceptualHash) {
            $this->perceptualHash = new PerceptualHashService;
        }

        return $this->perceptualHash;
    }

    /**
     * Set the video hash service (optional dependency)
     */
    public function setVideoHashService(VideoHashService $videoHash): void
    {
        $this->videoHash = $videoHash;
    }

    /**
     * Get the video hash service, creating if needed
     */
    private function getVideoHashService(): VideoHashService
    {
        if (! $this->videoHash) {
            $this->videoHash = app(VideoHashService::class);
        }

        return $this->videoHash;
    }

    /**
     * Set the AI auto-tag service (optional dependency)
     */
    public function setAIAutoTagService(AIAutoTagService $aiAutoTag): void
    {
        $this->aiAutoTag = $aiAutoTag;
    }

    /**
     * Get the AI auto-tag service, creating if needed
     */
    private function getAIAutoTagService(): AIAutoTagService
    {
        if (! $this->aiAutoTag) {
            $this->aiAutoTag = app(AIAutoTagService::class);
        }

        return $this->aiAutoTag;
    }

    private function getThumbnailService(): ThumbnailService
    {
        if (! $this->thumbnailService) {
            $this->thumbnailService = app(ThumbnailService::class);
        }

        return $this->thumbnailService;
    }

    private function getFileCategorizationRAGService(): FileCategorizationRAGService
    {
        if (! $this->fileCategorizationRag) {
            $this->fileCategorizationRag = app(FileCategorizationRAGService::class);
        }

        return $this->fileCategorizationRag;
    }

    // ========================================================================
    // CORE FILE REGISTRATION
    // ========================================================================

    /**
     * Register a new file in the registry
     *
     * @param  string  $nextcloudPath  Path relative to Nextcloud user root
     * @param  array  $options  Additional options:
     *                          - original_path: Original source path
     *                          - original_source: Source type (nextcloud, etc.)
     *                          - title: Human-readable title
     *                          - category: Category for organization
     *                          - compute_hash: Whether to compute content hash (default: true)
     *                          - auto_analyze: Queue AI auto-tagging (default: false)
     *                          - analyze_sync: Run AI auto-tagging synchronously (default: false)
     * @return array Registered file info with asset_uuid
     */
    public function registerFile(string $nextcloudPath, array $options = []): array
    {
        $nextcloudPath = '/'.ltrim($nextcloudPath, '/');
        $pathHash = hash('sha256', $nextcloudPath);

        // Get file info from Nextcloud first
        $fileInfo = $this->nextcloudApi->getFileInfo($nextcloudPath);
        if (! $fileInfo['success']) {
            throw new Exception('Failed to get file info from Nextcloud: '.($fileInfo['error'] ?? 'Unknown error'));
        }

        // Parse Nextcloud last_modified timestamp
        $ncModifiedAt = null;
        if (! empty($fileInfo['last_modified'])) {
            try {
                $ncModifiedAt = \Carbon\Carbon::parse($fileInfo['last_modified'])->toDateTimeString();
            } catch (\Exception $e) {
                Log::debug('FileRegistryService: date parse failed for last_modified', ['value' => $fileInfo['last_modified'], 'error' => $e->getMessage()]);
            }
        }

        // Check if already registered
        $existing = DB::selectOne('
            SELECT id, asset_uuid, nextcloud_fileid, content_hash, status, nextcloud_modified_at
            FROM file_registry
            WHERE path_hash = ?
        ', [$pathHash]);

        if ($existing) {
            // Skip if file hasn't changed
            if ($ncModifiedAt && $existing->nextcloud_modified_at === $ncModifiedAt) {
                return [
                    'success' => true,
                    'asset_uuid' => $existing->asset_uuid,
                    'reference' => "{{ASSET:{$existing->asset_uuid}}}",
                    'skipped' => true,
                    'reason' => 'File unchanged (lastmodified match)',
                ];
            }

            // Update if needed and return existing
            return $this->refreshFileRegistration($existing->asset_uuid, $ncModifiedAt);
        }

        $nextcloudFileid = $fileInfo['fileid'] ?? null;
        if ($nextcloudFileid) {
            $existingByFileid = DB::selectOne('
                SELECT id, asset_uuid, nextcloud_fileid, current_path, nextcloud_modified_at
                FROM file_registry
                WHERE nextcloud_fileid = ?
                  AND status != ?
                LIMIT 1
            ', [$nextcloudFileid, 'deleted']);

            if ($existingByFileid) {
                if ($existingByFileid->current_path !== $nextcloudPath) {
                    $this->updateFilePath(
                        $existingByFileid->asset_uuid,
                        $nextcloudPath,
                        'nextcloud_sync',
                        'Registration matched existing fileid'
                    );
                }

                return $this->refreshFileRegistration($existingByFileid->asset_uuid, $ncModifiedAt);
            }
        }

        // Generate UUID
        $assetUuid = (string) Str::uuid();

        // Extract filename and extension
        $filename = basename($nextcloudPath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $extension = $extension ? strtolower($extension) : null;

        // Compute content hash if requested
        $contentHash = null;
        if ($options['compute_hash'] ?? true) {
            $hashResult = $this->nextcloudApi->computeFileHash($nextcloudPath);
            if ($hashResult['success']) {
                $contentHash = $hashResult['hash'];
            }
        }

        // Check for duplicates if we have a hash
        $duplicateOf = null;
        if ($contentHash) {
            $duplicateOf = DB::selectOne("
                SELECT id, asset_uuid, nextcloud_fileid, current_path
                FROM file_registry
                WHERE content_hash = ? AND status = 'active'
                LIMIT 1
            ", [$contentHash]);
        }

        $identityPolicy = FileRegistryLifecycleService::sameContentNewIdentityPolicy(
            $duplicateOf,
            $nextcloudFileid ? (int) $nextcloudFileid : null
        );

        // Insert new registration
        DB::insert("
            INSERT INTO file_registry (
                asset_uuid, nextcloud_fileid, current_path, path_hash,
                original_path, original_source, filename, extension, mime_type, file_size,
                nextcloud_modified_at, content_hash, content_hash_verified_at, status, last_verified_at,
                title, category, tags, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), ?, ?, ?, NOW(), NOW())
        ", [
            $assetUuid,
            $fileInfo['fileid'] ?? null,
            $nextcloudPath,
            $pathHash,
            $options['original_path'] ?? null,
            $options['original_source'] ?? 'nextcloud',
            $filename,
            $extension,
            $fileInfo['mime_type'] ?? null,
            $fileInfo['size'] ?? 0,
            $ncModifiedAt,
            $contentHash,
            $contentHash ? now() : null,
            $options['title'] ?? null,
            $options['category'] ?? null,
            isset($options['tags']) ? json_encode($options['tags']) : null,
        ]);

        $registeredId = DB::getPdo()->lastInsertId();

        // Record duplicate if found
        if ($duplicateOf) {
            DB::insert("
                INSERT INTO file_registry_duplicates (
                    content_hash, canonical_file_id, duplicate_file_id, status, created_at
                ) VALUES (?, ?, ?, 'pending_review', NOW())
            ", [$contentHash, $duplicateOf->id, $registeredId]);

            Log::info('FileRegistry: Duplicate file detected', [
                'new_uuid' => $assetUuid,
                'duplicate_of_uuid' => $duplicateOf->asset_uuid,
                'identity_policy' => $identityPolicy,
                'path' => $nextcloudPath,
            ]);
        }

        Log::info('FileRegistry: File registered', [
            'asset_uuid' => $assetUuid,
            'path' => $nextcloudPath,
            'nextcloud_fileid' => $fileInfo['fileid'] ?? null,
            'has_hash' => (bool) $contentHash,
        ]);

        // Compute perceptual hash for images (async-safe, non-blocking)
        $perceptualHashResult = null;
        if ($extension && in_array($extension, array_merge(config('file_types.image'), config('file_types.image_raw'))) && ($options['compute_perceptual_hash'] ?? true)) {
            $perceptualHashResult = $this->computePerceptualHashForFile((int) $registeredId, $nextcloudPath);
        }

        // Compute video hash for videos (requires FFmpeg)
        $videoHashResult = null;
        if ($extension && in_array($extension, config('file_types.video')) && ($options['compute_video_hash'] ?? false)) {
            $videoHashResult = $this->computeVideoHashForFile((int) $registeredId, $nextcloudPath);
        }

        // Queue AI auto-tagging if requested
        $aiTagResult = null;
        if ($options['auto_analyze'] ?? false) {
            \App\Jobs\AIAutoTagJob::dispatch((int) $registeredId);
            $aiTagResult = ['queued' => true];
        } elseif ($options['analyze_sync'] ?? false) {
            // Run synchronously (blocking - use for small batches only)
            try {
                $aiTagResult = $this->getAIAutoTagService()->analyzeFile((int) $registeredId);
            } catch (Exception $e) {
                Log::warning('FileRegistry: Sync AI analysis failed', [
                    'asset_uuid' => $assetUuid,
                    'error' => $e->getMessage(),
                ]);
                $aiTagResult = ['error' => $e->getMessage()];
            }
        }

        return [
            'success' => true,
            'asset_uuid' => $assetUuid,
            'reference' => "{{ASSET:{$assetUuid}}}",
            'nextcloud_fileid' => $fileInfo['fileid'] ?? null,
            'path' => $nextcloudPath,
            'content_hash' => $contentHash,
            'is_duplicate' => (bool) $duplicateOf,
            'duplicate_of' => $duplicateOf ? $duplicateOf->asset_uuid : null,
            'identity_policy' => $identityPolicy,
            'perceptual_hash' => $perceptualHashResult,
            'video_hash' => $videoHashResult,
            'ai_analysis' => $aiTagResult,
        ];
    }

    /**
     * Queue AI auto-tagging for unanalyzed files in the registry
     *
     * @param  int  $limit  Max files to queue
     * @param  string|null  $extensionFilter  Filter by extension (e.g., 'jpg', 'pdf')
     * @param  bool  $forceRefresh  Re-analyze even if already done
     * @return array Queue result with counts
     */
    public function queueBatchAIAnalysis(int $limit = 100, ?string $extensionFilter = null, bool $forceRefresh = false): array
    {
        $aiAutoTag = $this->getAIAutoTagService();
        $files = $aiAutoTag->getUnanalyzedFiles($limit, $extensionFilter);

        $queued = 0;
        foreach ($files as $file) {
            \App\Jobs\AIAutoTagJob::dispatch((int) $file->id, $forceRefresh);
            $queued++;
        }

        Log::info('FileRegistry: Queued AI auto-tagging batch', [
            'queued' => $queued,
            'limit' => $limit,
            'extension_filter' => $extensionFilter,
            'force_refresh' => $forceRefresh,
        ]);

        return [
            'success' => true,
            'queued' => $queued,
            'files' => array_map(fn ($f) => [
                'id' => $f->id,
                'filename' => $f->filename,
                'extension' => $f->extension,
            ], $files),
        ];
    }

    /**
     * Get AI auto-tag statistics
     *
     * @return array Analysis statistics
     */
    public function getAITagStats(): array
    {
        return $this->getAIAutoTagService()->getStats();
    }

    // ========================================================================
    // ASSET RESOLUTION
    // ========================================================================

    /**
     * Resolve an asset reference to a downloadable URL
     *
     * @param  string  $assetUuid  The asset UUID
     * @return array Resolution result with URL
     */
    public function resolveAsset(string $assetUuid): array
    {
        $file = DB::selectOne('
            SELECT id, asset_uuid, nextcloud_fileid, current_path, filename, status
            FROM file_registry
            WHERE asset_uuid = ?
        ', [$assetUuid]);

        if (! $file) {
            return [
                'success' => false,
                'error' => 'Asset not found',
                'asset_uuid' => $assetUuid,
            ];
        }

        if ($file->status === 'deleted') {
            return [
                'success' => false,
                'error' => 'Asset has been deleted',
                'asset_uuid' => $assetUuid,
            ];
        }

        // Try to get direct download URL via Nextcloud fileid
        if ($file->nextcloud_fileid) {
            $directResult = $this->nextcloudApi->getDirectDownloadUrl($file->nextcloud_fileid);
            if ($directResult['success']) {
                return [
                    'success' => true,
                    'asset_uuid' => $assetUuid,
                    'url' => $directResult['url'],
                    'method' => 'direct_fileid',
                    'expires_at' => $directResult['expires_at'] ?? null,
                    'filename' => $file->filename,
                    'path' => $file->current_path,
                ];
            }

            Log::warning('FileRegistry: Direct download failed, trying path resolution', [
                'asset_uuid' => $assetUuid,
                'fileid' => $file->nextcloud_fileid,
            ]);
        }

        // Fallback: Use WebDAV path
        $webdavResult = $this->nextcloudApi->getWebDavUrl($file->current_path);
        if ($webdavResult['success']) {
            $exists = $this->nextcloudApi->fileExists($file->current_path);

            if ($exists) {
                return [
                    'success' => true,
                    'asset_uuid' => $assetUuid,
                    'url' => $webdavResult['url'],
                    'method' => 'webdav_path',
                    'filename' => $file->filename,
                    'path' => $file->current_path,
                ];
            }

            // File not at expected path - try to find by fileid
            if ($file->nextcloud_fileid) {
                $searchResult = $this->nextcloudApi->findFileByFileid($file->nextcloud_fileid);
                if ($searchResult['success'] && $searchResult['path']) {
                    $this->updateFilePath($assetUuid, $searchResult['path'], 'nextcloud_sync');

                    return [
                        'success' => true,
                        'asset_uuid' => $assetUuid,
                        'url' => $this->nextcloudApi->getWebDavUrl($searchResult['path'])['url'],
                        'method' => 'fileid_search',
                        'filename' => $file->filename,
                        'path' => $searchResult['path'],
                        'path_updated' => true,
                    ];
                }
            }

            // Mark as orphaned
            $this->markOrphaned($assetUuid);

            return [
                'success' => false,
                'error' => 'File not found at expected path and cannot be located',
                'asset_uuid' => $assetUuid,
                'last_known_path' => $file->current_path,
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to resolve asset',
            'asset_uuid' => $assetUuid,
        ];
    }

    /**
     * Parse and resolve asset references in text
     *
     * @param  string  $text  Text containing {{ASSET:uuid}} references
     * @return array Resolved text and resolution details
     */
    public function resolveReferencesInText(string $text): array
    {
        $resolutions = [];
        $resolvedText = preg_replace_callback(
            '/\{\{ASSET:([a-f0-9-]{36})\}\}/i',
            function ($matches) use (&$resolutions) {
                $uuid = $matches[1];
                $result = $this->resolveAsset($uuid);
                $resolutions[$uuid] = $result;

                if ($result['success']) {
                    return $result['url'];
                }

                return $matches[0];
            },
            $text
        );

        return [
            'text' => $resolvedText,
            'resolutions' => $resolutions,
            'total' => count($resolutions),
            'successful' => count(array_filter($resolutions, fn ($r) => $r['success'])),
        ];
    }

    // ========================================================================
    // PATH TRACKING (Read-only - only updates when file detected as moved)
    // ========================================================================

    /**
     * Update file path after detecting a move (internal use)
     *
     * @param  string  $assetUuid  Asset UUID
     * @param  string  $newPath  New Nextcloud path
     * @param  string  $movedBy  Detection source
     * @param  string|null  $reason  Reason for the update
     * @return bool Success
     */
    private function updateFilePath(string $assetUuid, string $newPath, string $movedBy = 'system', ?string $reason = null): bool
    {
        return $this->getLifecycleService()->remapFilePath($assetUuid, $newPath, $movedBy, $reason);
    }

    /**
     * Mark a file as orphaned (cannot be found)
     */
    public function markOrphaned(string $assetUuid): bool
    {
        $file = DB::selectOne(
            'SELECT status, verification_failures FROM file_registry WHERE asset_uuid = ? LIMIT 1',
            [$assetUuid]
        );

        if (! $file) {
            return false;
        }

        $wasOrphaned = ($file->status ?? null) === 'orphaned';
        $affected = DB::update("
            UPDATE file_registry
            SET status = 'orphaned', verification_failures = verification_failures + 1, updated_at = NOW()
            WHERE asset_uuid = ?
        ", [$assetUuid]);

        if ($affected && ! $wasOrphaned) {
            $this->logOrphanedTransition($assetUuid, (string) ($file->status ?? ''), ((int) ($file->verification_failures ?? 0)) + 1);
        }

        return $affected > 0;
    }

    private function logOrphanedTransition(string $assetUuid, string $previousStatus, int $verificationFailures): void
    {
        if (self::$orphanWarningsLogged < self::MAX_ORPHAN_WARNING_DETAILS_PER_PROCESS) {
            self::$orphanWarningsLogged++;

            Log::warning('FileRegistry: File marked as orphaned', [
                'asset_uuid' => $assetUuid,
                'previous_status' => $previousStatus,
                'verification_failures' => $verificationFailures,
            ]);

            return;
        }

        if (! self::$orphanWarningSummaryLogged) {
            self::$orphanWarningSummaryLogged = true;

            Log::warning('FileRegistry: Additional orphaned files suppressed for this process', [
                'detailed_warnings_logged' => self::MAX_ORPHAN_WARNING_DETAILS_PER_PROCESS,
            ]);
        }
    }

    /**
     * Mark a file as deleted (confirmed non-existent, e.g., 404 from Nextcloud)
     */
    public function markAsDeleted(string $assetUuid, string $reason = 'File not found'): bool
    {
        return $this->getLifecycleService()->markAsDeleted($assetUuid, $reason);
    }

    /**
     * Centralized delete path for user-requested file deletion.
     * Keeps the existing tombstone model while absorbing controller-level cleanup.
     */
    public function deleteFileFromRegistry(string $assetUuid, ?string $currentPath = null, string $reason = 'Deleted by user'): bool
    {
        return $this->getLifecycleService()->deleteFileFromRegistry($assetUuid, $currentPath, $reason);
    }

    /**
     * Self-healing maintenance: promote orphaned files with repeated failures to deleted
     * Also verifies orphaned files one more time before deletion
     *
     * @param  int  $failureThreshold  Number of verification failures before marking deleted
     * @param  int  $limit  Max files to process per run
     * @return array Stats about cleanup operation
     */
    public function cleanupOrphanedFiles(int $failureThreshold = 3, int $limit = 100): array
    {
        $stats = [
            'checked' => 0,
            'recovered' => 0,
            'deleted' => 0,
            'still_orphaned' => 0,
        ];

        // Get orphaned files with high failure count
        $orphaned = DB::select("
            SELECT asset_uuid, current_path, nextcloud_fileid, verification_failures
            FROM file_registry
            WHERE status = 'orphaned'
            AND verification_failures >= ?
            ORDER BY verification_failures DESC
            LIMIT ?
        ", [$failureThreshold, $limit]);

        foreach ($orphaned as $file) {
            $stats['checked']++;

            // One final attempt to find the file
            $exists = $this->nextcloudApi->fileExists($file->current_path);

            // Try fileid lookup if path fails
            if (! $exists && $file->nextcloud_fileid) {
                $searchResult = $this->nextcloudApi->findFileByFileid($file->nextcloud_fileid);
                if ($searchResult['success'] && $searchResult['path']) {
                    // File found at new location - recover it
                    $this->updateFilePath($file->asset_uuid, $searchResult['path'], 'self_healing', 'Recovered via fileid during cleanup');
                    DB::update("
                        UPDATE file_registry
                        SET status = 'active', verification_failures = 0, last_verified_at = NOW(), updated_at = NOW()
                        WHERE asset_uuid = ?
                    ", [$file->asset_uuid]);
                    $stats['recovered']++;

                    continue;
                }
            }

            if ($exists) {
                // File exists - restore to active
                DB::update("
                    UPDATE file_registry
                    SET status = 'active', verification_failures = 0, last_verified_at = NOW(), updated_at = NOW()
                    WHERE asset_uuid = ?
                ", [$file->asset_uuid]);
                $stats['recovered']++;
            } else {
                // Confirmed deleted
                $this->markAsDeleted($file->asset_uuid, "Confirmed deleted after {$file->verification_failures} verification failures");
                $stats['deleted']++;
            }
        }

        // Also check files that got 404 during thumbnail generation (have thumbnail_error with 404)
        $notFoundFiles = DB::select("
            SELECT asset_uuid, current_path, nextcloud_fileid
            FROM file_registry
            WHERE status = 'active'
            AND thumbnail_error LIKE '%404%'
            LIMIT ?
        ", [$limit]);

        foreach ($notFoundFiles as $file) {
            $stats['checked']++;

            $exists = $this->nextcloudApi->fileExists($file->current_path);

            if (! $exists && $file->nextcloud_fileid) {
                $searchResult = $this->nextcloudApi->findFileByFileid($file->nextcloud_fileid);
                if ($searchResult['success'] && $searchResult['path']) {
                    $this->updateFilePath($file->asset_uuid, $searchResult['path'], 'self_healing', 'Recovered via fileid after 404');
                    DB::update('
                        UPDATE file_registry
                        SET thumbnail_error = NULL, thumbnail_generated_at = NULL, last_verified_at = NOW(), updated_at = NOW()
                        WHERE asset_uuid = ?
                    ', [$file->asset_uuid]);
                    $stats['recovered']++;

                    continue;
                }
            }

            if (! $exists) {
                $this->markAsDeleted($file->asset_uuid, 'HTTP 404 during thumbnail generation');
                $stats['deleted']++;
            } else {
                // File exists, clear the error so thumbnail can retry
                DB::update('
                    UPDATE file_registry
                    SET thumbnail_error = NULL, thumbnail_generated_at = NULL, last_verified_at = NOW(), updated_at = NOW()
                    WHERE asset_uuid = ?
                ', [$file->asset_uuid]);
                $stats['recovered']++;
            }
        }

        Log::info('FileRegistry: Self-healing cleanup completed', $stats);

        return $stats;
    }

    /**
     * Get maintenance statistics
     */
    public function getMaintenanceStats(): array
    {
        $statusCounts = DB::select('
            SELECT status, COUNT(*) as count
            FROM file_registry
            GROUP BY status
        ');

        $orphanedWithFailures = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN verification_failures >= 3 THEN 1 ELSE 0 END) as ready_for_deletion
            FROM file_registry
            WHERE status = 'orphaned'
        ");

        $errorCounts = DB::selectOne("
            SELECT
                COUNT(*) as total_errors,
                SUM(CASE WHEN thumbnail_error LIKE '%404%' THEN 1 ELSE 0 END) as not_found_errors
            FROM file_registry
            WHERE thumbnail_error IS NOT NULL
            AND status = 'active'
        ");

        return [
            'status_counts' => $statusCounts,
            'orphaned_ready_for_deletion' => (int) ($orphanedWithFailures->ready_for_deletion ?? 0),
            'total_orphaned' => (int) ($orphanedWithFailures->total ?? 0),
            'thumbnail_errors' => (int) ($errorCounts->total_errors ?? 0),
            'not_found_errors' => (int) ($errorCounts->not_found_errors ?? 0),
        ];
    }

    /**
     * Refresh a file registration (re-verify and update metadata)
     */
    public function refreshFileRegistration(string $assetUuid, ?string $ncModifiedAt = null): array
    {
        $file = DB::selectOne('
            SELECT * FROM file_registry WHERE asset_uuid = ?
        ', [$assetUuid]);

        if (! $file) {
            return ['success' => false, 'error' => 'Asset not found'];
        }

        // Verify file still exists
        $exists = $this->nextcloudApi->fileExists($file->current_path);

        if (! $exists && $file->nextcloud_fileid) {
            $searchResult = $this->nextcloudApi->findFileByFileid($file->nextcloud_fileid);
            if ($searchResult['success'] && $searchResult['path']) {
                $this->updateFilePath($assetUuid, $searchResult['path'], 'nextcloud_sync', 'Auto-discovered via fileid');
                $file->current_path = $searchResult['path'];
                $exists = true;
            }
        }

        if (! $exists) {
            $this->markOrphaned($assetUuid);

            return [
                'success' => false,
                'error' => 'File no longer exists',
                'asset_uuid' => $assetUuid,
                'status' => 'orphaned',
            ];
        }

        // Check if file content has changed (timestamp mismatch)
        $contentChanged = false;
        $invalidationResult = null;
        if ($ncModifiedAt && $file->nextcloud_modified_at && $ncModifiedAt !== $file->nextcloud_modified_at) {
            // File was modified externally (GIMP, Photoshop, etc.)
            $contentChanged = true;
            Log::info("File content changed for {$assetUuid}: {$file->nextcloud_modified_at} -> {$ncModifiedAt}");
            $invalidationResult = $this->invalidateDerivedData($file->id, $assetUuid);
        }

        // Update verification timestamp
        if ($ncModifiedAt) {
            DB::update("
                UPDATE file_registry
                SET last_verified_at = NOW(), nextcloud_modified_at = ?, status = 'active', verification_failures = 0, updated_at = NOW()
                WHERE asset_uuid = ?
            ", [$ncModifiedAt, $assetUuid]);
        } else {
            DB::update("
                UPDATE file_registry
                SET last_verified_at = NOW(), status = 'active', verification_failures = 0, updated_at = NOW()
                WHERE asset_uuid = ?
            ", [$assetUuid]);
        }

        return [
            'success' => true,
            'asset_uuid' => $assetUuid,
            'reference' => "{{ASSET:{$assetUuid}}}",
            'path' => $file->current_path,
            'nextcloud_fileid' => $file->nextcloud_fileid,
            'status' => 'active',
            'content_changed' => $contentChanged,
            'invalidated' => $invalidationResult,
        ];
    }

    /**
     * Invalidate all derived data for a file (thumbnails, hashes, faces)
     * Called when file content has changed externally (edited in GIMP, etc.)
     *
     * @param  int  $fileRegistryId  The file_registry.id
     * @param  string  $assetUuid  The asset UUID for logging
     * @return array Summary of what was invalidated
     */
    public function invalidateDerivedData(int $fileRegistryId, string $assetUuid): array
    {
        return $this->getLifecycleService()->invalidateDerivedData($fileRegistryId, $assetUuid);
    }

    // ========================================================================
    // FILE RETRIEVAL & STATISTICS
    // ========================================================================

    /**
     * Get file info by asset UUID
     */
    public function getFile(string $assetUuid): ?object
    {
        return DB::selectOne('
            SELECT * FROM file_registry WHERE asset_uuid = ?
        ', [$assetUuid]);
    }

    /**
     * Find files by content hash (for duplicate detection)
     */
    public function findByContentHash(string $contentHash): array
    {
        return DB::select('
            SELECT asset_uuid, current_path, filename, status, created_at
            FROM file_registry
            WHERE content_hash = ?
            ORDER BY created_at ASC
        ', [$contentHash]);
    }

    /**
     * Get duplicate report
     */
    public function getDuplicatesReport(): array
    {
        return DB::select("
            SELECT
                d.content_hash,
                d.status,
                c.asset_uuid as canonical_uuid,
                c.current_path as canonical_path,
                dup.asset_uuid as duplicate_uuid,
                dup.current_path as duplicate_path,
                d.created_at
            FROM file_registry_duplicates d
            JOIN file_registry c ON c.id = d.canonical_file_id
            JOIN file_registry dup ON dup.id = d.duplicate_file_id
            WHERE d.status = 'pending_review'
            ORDER BY d.created_at DESC
            LIMIT 100
        ");
    }

    /**
     * Get duplicates statistics
     */
    public function getDuplicatesStats(): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_duplicates,
                SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'keep_both' THEN 1 ELSE 0 END) as keep_both,
                SUM(CASE WHEN status = 'merged' THEN 1 ELSE 0 END) as merged,
                SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) as ignored
            FROM file_registry_duplicates
        ");

        return [
            'total' => (int) ($stats->total_duplicates ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'resolved' => (int) ($stats->merged ?? 0),
            'merged' => (int) ($stats->merged ?? 0),
            'keep_both' => (int) ($stats->keep_both ?? 0),
            'ignored' => (int) ($stats->ignored ?? 0),
        ];
    }

    /**
     * Get registry statistics
     */
    public function getStatistics(): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_files,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_files,
                SUM(CASE WHEN status = 'orphaned' THEN 1 ELSE 0 END) as orphaned_files,
                SUM(CASE WHEN nextcloud_fileid IS NOT NULL THEN 1 ELSE 0 END) as with_fileid,
                SUM(CASE WHEN content_hash IS NOT NULL THEN 1 ELSE 0 END) as with_hash,
                SUM(file_size) as total_size_bytes
            FROM file_registry
        ");

        $duplicates = DB::selectOne("
            SELECT COUNT(*) as pending_duplicates
            FROM file_registry_duplicates
            WHERE status = 'pending_review'
        ");

        $bySource = DB::select('
            SELECT original_source, COUNT(*) as count
            FROM file_registry
            GROUP BY original_source
        ');

        $byCategory = DB::select("
            SELECT COALESCE(category, 'uncategorized') as category, COUNT(*) as count
            FROM file_registry
            WHERE status = 'active'
            GROUP BY category
            ORDER BY count DESC
            LIMIT 20
        ");

        $recentActivity = DB::selectOne('
            SELECT
                MAX(created_at) as last_registered,
                MAX(last_verified_at) as last_verified
            FROM file_registry
        ');

        return [
            'total_files' => (int) $stats->total_files,
            'active_files' => (int) $stats->active_files,
            'orphaned_files' => (int) $stats->orphaned_files,
            'with_nextcloud_fileid' => (int) $stats->with_fileid,
            'with_content_hash' => (int) $stats->with_hash,
            'total_size_bytes' => (int) $stats->total_size_bytes,
            'total_size_human' => $this->formatBytes((int) $stats->total_size_bytes),
            'pending_duplicates' => (int) $duplicates->pending_duplicates,
            'by_source' => $bySource,
            'by_category' => $byCategory,
            'last_registered' => $recentActivity->last_registered ?? null,
            'last_verified' => $recentActivity->last_verified ?? null,
        ];
    }

    /**
     * List files with pagination and filtering
     */
    public function listFiles(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $whereClauses = ['1=1'];
        $params = [];

        if (! empty($filters['status'])) {
            $whereClauses[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if (! empty($filters['category'])) {
            $whereClauses[] = 'category = ?';
            $params[] = $filters['category'];
        }

        if (! empty($filters['extension'])) {
            $whereClauses[] = 'extension = ?';
            $params[] = strtolower($filters['extension']);
        }

        if (! empty($filters['search'])) {
            $whereClauses[] = '(filename LIKE ? OR current_path LIKE ?)';
            $searchTerm = '%'.$filters['search'].'%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $whereClauses);
        $params[] = $limit;
        $params[] = $offset;

        $files = DB::select("
            SELECT asset_uuid, filename, current_path, extension, mime_type, file_size,
                   category, status, last_verified_at, rag_indexed_at, created_at
            FROM file_registry
            WHERE {$whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", $params);

        // Get total count
        $countParams = array_slice($params, 0, -2);
        $total = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry WHERE {$whereClause}
        ", $countParams);

        return [
            'files' => $files,
            'total' => (int) $total->cnt,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    // ========================================================================
    // SCANNING & VERIFICATION
    // ========================================================================

    /**
     * Verify a batch of files still exist in Nextcloud
     *
     * @param  int  $limit  Number of files to verify
     * @return array Results with verified, orphaned, and error counts
     */
    public function verifyBatch(int $limit = 100): array
    {
        $results = [
            'verified' => 0,
            'orphaned' => 0,
            'updated_paths' => 0,
            'errors' => 0,
            'details' => [],
        ];

        $files = DB::select("
            SELECT asset_uuid, current_path, nextcloud_fileid, last_verified_at
            FROM file_registry
            WHERE status = 'active'
            ORDER BY COALESCE(last_verified_at, '1970-01-01') ASC, created_at ASC
            LIMIT ?
        ", [$limit]);

        $hasFs = $this->nextcloudApi->hasDirectAccess();

        // Batch filesystem verification: collect UUIDs that pass file_exists, bulk-update
        $fsVerifiedUuids = [];
        $needsWebdav = [];

        foreach ($files as $file) {
            if ($hasFs && $file->current_path) {
                $localFile = $this->nextcloudApi->localPath($file->current_path);
                if ($localFile) {
                    $fsVerifiedUuids[] = $file->asset_uuid;

                    continue;
                }
            }
            $needsWebdav[] = $file;
        }

        // Bulk update all filesystem-verified files in batches of 500
        if (! empty($fsVerifiedUuids)) {
            foreach (array_chunk($fsVerifiedUuids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                DB::update("
                    UPDATE file_registry SET last_verified_at = NOW()
                    WHERE asset_uuid IN ({$placeholders})
                ", $chunk);
            }
            $results['verified'] += count($fsVerifiedUuids);
        }

        // WebDAV fallback for files not found on filesystem (moved/deleted)
        foreach ($needsWebdav as $file) {
            try {
                $resolution = $this->resolveAsset($file->asset_uuid);

                if ($resolution['success']) {
                    DB::update('
                        UPDATE file_registry
                        SET last_verified_at = NOW(),
                            current_path = ?
                        WHERE asset_uuid = ?
                    ', [$resolution['path'] ?? $file->current_path, $file->asset_uuid]);

                    $results['verified']++;

                    if (($resolution['path'] ?? $file->current_path) !== $file->current_path) {
                        $results['updated_paths']++;
                        $results['details'][] = "Path updated: {$file->current_path} → {$resolution['path']}";
                    }
                } else {
                    $this->markOrphaned($file->asset_uuid);
                    $results['orphaned']++;
                    $results['details'][] = "Orphaned: {$file->current_path}";
                }
            } catch (\Exception $e) {
                $results['errors']++;
                Log::warning('File registry verification error', [
                    'asset_uuid' => $file->asset_uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Scan a directory and register any new files
     *
     * @param  string  $path  Nextcloud path to scan
     * @param  int  $limit  Maximum files to register
     * @return array Results
     */
    public function scanAndRegisterNew(?string $path = null, int $limit = 500): array
    {
        $path ??= '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');

        $results = [
            'scanned' => 0,
            'registered' => 0,
            'already_registered' => 0,
            'skipped_unchanged' => 0,
            'duplicates' => 0,
            'errors' => 0,
        ];

        try {
            $listResult = $this->nextcloudApi->listFiles($path, recursive: true, limit: $limit);

            if (! $listResult['success']) {
                $results['errors']++;

                return $results;
            }

            foreach ($listResult['files'] ?? [] as $file) {
                if ($file['is_directory'] ?? true) {
                    continue;
                }

                // 0 = unlimited, otherwise respect the limit
                if ($limit > 0 && $results['scanned'] >= $limit) {
                    break;
                }

                $results['scanned']++;
                $filePath = $file['path'] ?? null;

                if (! $filePath) {
                    continue;
                }

                // Check if already registered
                $existing = DB::selectOne('
                    SELECT asset_uuid FROM file_registry
                    WHERE nextcloud_fileid = ? OR current_path = ?
                ', [$file['fileid'] ?? null, $filePath]);

                if ($existing) {
                    $results['already_registered']++;

                    continue;
                }

                // Register the new file
                try {
                    $registration = $this->registerFile($filePath);

                    if ($registration['success']) {
                        if ($registration['skipped'] ?? false) {
                            $results['skipped_unchanged']++;
                        } else {
                            $results['registered']++;
                            if ($registration['is_duplicate'] ?? false) {
                                $results['duplicates']++;
                            }
                        }
                    } else {
                        $results['errors']++;
                    }
                } catch (\Exception $e) {
                    Log::warning('FileRegistryService: file registration failed during scan', ['error' => $e->getMessage()]);
                    $results['errors']++;
                }
            }
        } catch (\Exception $e) {
            Log::error('File registry scan error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            $results['errors']++;
        }

        return $results;
    }

    /**
     * Detect files that have been removed from Nextcloud
     *
     * @param  int  $limit  Number of files to check
     * @return array Results with removed file UUIDs
     */
    public function detectRemovedFiles(int $limit = 100): array
    {
        $results = [
            'checked' => 0,
            'removed' => [],
            'errors' => 0,
        ];

        // Get files that haven't been verified recently
        $files = DB::select("
            SELECT asset_uuid, current_path, nextcloud_fileid
            FROM file_registry
            WHERE status = 'active'
            AND last_verified_at < NOW() - INTERVAL 7 DAY
            ORDER BY last_verified_at ASC
            LIMIT ?
        ", [$limit]);

        foreach ($files as $file) {
            $results['checked']++;

            try {
                $exists = $this->nextcloudApi->fileExists($file->current_path);

                if (! $exists && $file->nextcloud_fileid) {
                    // Try to find by fileid (file might have moved)
                    $searchResult = $this->nextcloudApi->findFileByFileid($file->nextcloud_fileid);
                    if ($searchResult['success'] && $searchResult['path']) {
                        // File moved, update path
                        $this->updateFilePath($file->asset_uuid, $searchResult['path'], 'detection_scan');

                        continue;
                    }
                }

                if (! $exists) {
                    $this->markOrphaned($file->asset_uuid);
                    $results['removed'][] = $file->asset_uuid;
                } else {
                    // Update verification timestamp
                    DB::update('
                        UPDATE file_registry SET last_verified_at = NOW() WHERE asset_uuid = ?
                    ', [$file->asset_uuid]);
                }
            } catch (\Exception $e) {
                Log::warning('FileRegistryService: file verification failed', ['uuid' => $file->asset_uuid ?? null, 'error' => $e->getMessage()]);
                $results['errors']++;
            }
        }

        return $results;
    }

    // ========================================================================
    // RAG SYNC
    // ========================================================================

    /**
     * Get files needing RAG sync (new or modified since last sync)
     *
     * @param  int  $limit  Maximum files to return
     * @return array Files needing sync
     */
    public function getFilesNeedingRAGSync(int $limit = 50): array
    {
        // rag_documents is on PostgreSQL — cannot JOIN across DB engines
        // Instead, fetch RAG-indexed UUIDs from pgsql, then exclude them in MySQL
        $ragIndexed = DB::connection('pgsql_rag')->select("
            SELECT source_id FROM rag_documents WHERE source_type = 'file_registry'
        ");
        $indexedUuids = array_column($ragIndexed, 'source_id');

        if (empty($indexedUuids)) {
            return DB::select("
                SELECT asset_uuid, current_path, filename, extension, mime_type, file_size
                FROM file_registry
                WHERE status = 'active'
                ORDER BY updated_at DESC
                LIMIT ?
            ", [$limit]);
        }

        $placeholders = implode(',', array_fill(0, count($indexedUuids), '?'));

        return DB::select("
            SELECT asset_uuid, current_path, filename, extension, mime_type, file_size
            FROM file_registry
            WHERE status = 'active'
            AND asset_uuid NOT IN ({$placeholders})
            ORDER BY updated_at DESC
            LIMIT ?
        ", array_merge($indexedUuids, [$limit]));
    }

    /**
     * Sync file to RAG index
     *
     * @param  string  $assetUuid  Asset UUID
     * @return array Sync result
     */
    public function syncFileToRAG(string $assetUuid): array
    {
        $file = $this->getFile($assetUuid);
        if (! $file) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if ($file->status !== 'active') {
            return ['success' => false, 'error' => 'File is not active'];
        }

        try {
            $ragService = app(RAGService::class);

            // Build metadata
            $metadata = [
                'asset_uuid' => $file->asset_uuid,
                'filename' => $file->filename,
                'extension' => $file->extension,
                'mime_type' => $file->mime_type,
                'path' => $file->current_path,
                'category' => $file->category,
                'file_size' => $file->file_size,
            ];

            // Build searchable content
            $content = "File: {$file->filename}\n";
            $content .= "Path: {$file->current_path}\n";
            if ($file->category) {
                $content .= "Category: {$file->category}\n";
            }

            // Index to RAG
            $ragService->indexDocument(
                'file_catalog',
                $content,
                $file->filename,
                $metadata,
                $file->asset_uuid,
                'file_registry'
            );

            return ['success' => true, 'asset_uuid' => $assetUuid];
        } catch (\Exception $e) {
            Log::error('FileRegistry: RAG sync failed', [
                'asset_uuid' => $assetUuid,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================================================
    // SYNC RUN TRACKING
    // ========================================================================

    /**
     * Get recent sync runs
     */
    public function getSyncRuns(int $limit = 20): array
    {
        return DB::select('
            SELECT id, run_type, status, started_at, completed_at, scope_path,
                   files_scanned, files_registered, duplicates_found, errors
            FROM file_registry_sync_runs
            ORDER BY started_at DESC
            LIMIT ?
        ', [$limit]);
    }

    /**
     * Create a new sync run record
     */
    public function createSyncRun(string $runType, string $scopePath): int
    {
        DB::insert("
            INSERT INTO file_registry_sync_runs (run_type, status, started_at, scope_path)
            VALUES (?, 'running', NOW(), ?)
        ", [$runType, $scopePath]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update sync run with results
     */
    public function completeSyncRun(int $runId, array $stats): void
    {
        DB::update("
            UPDATE file_registry_sync_runs
            SET status = 'completed',
                completed_at = NOW(),
                files_scanned = ?,
                files_registered = ?,
                duplicates_found = ?,
                errors = ?
            WHERE id = ?
        ", [
            $stats['scanned'] ?? 0,
            $stats['registered'] ?? 0,
            $stats['duplicates'] ?? 0,
            $stats['errors'] ?? 0,
            $runId,
        ]);
    }

    /**
     * Mark sync run as failed
     */
    public function failSyncRun(int $runId, string $error): void
    {
        DB::update("
            UPDATE file_registry_sync_runs
            SET status = 'failed',
                completed_at = NOW(),
                error_log = ?
            WHERE id = ?
        ", [json_encode(['error' => $error]), $runId]);
    }

    /**
     * Cleanup stuck sync runs
     */
    public function cleanupStuckSyncRuns(int $stuckMinutes = 60): int
    {
        return DB::update("
            UPDATE file_registry_sync_runs
            SET status = 'failed',
                completed_at = NOW(),
                error_log = JSON_OBJECT('error', 'Marked as stuck - no heartbeat for over 1 hour')
            WHERE status = 'running'
            AND (heartbeat_at < NOW() - INTERVAL ? MINUTE OR heartbeat_at IS NULL AND started_at < NOW() - INTERVAL ? MINUTE)
        ", [$stuckMinutes, $stuckMinutes]);
    }

    // ========================================================================
    // PERCEPTUAL HASH / VISUAL DUPLICATE DETECTION
    // ========================================================================

    /**
     * Compute perceptual hash for a registered image file
     *
     * Downloads file from Nextcloud temporarily, computes hash, cleans up.
     *
     * @param  int  $fileRegistryId  File registry ID
     * @param  string  $nextcloudPath  Path in Nextcloud
     * @return array|null Hash result or null on failure
     */
    private function computePerceptualHashForFile(int $fileRegistryId, string $nextcloudPath): ?array
    {
        $extension = strtolower(pathinfo($nextcloudPath, PATHINFO_EXTENSION));

        // Only process supported image formats (not RAW files)
        $supportedFormats = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'];
        if (! in_array($extension, $supportedFormats)) {
            return null;
        }

        try {
            $phashService = $this->getPerceptualHashService();

            // Filesystem-first: skip WebDAV when local path is available (eliminates timeout risk)
            $tempFile = $this->tempDir.'/'.uniqid('phash_').'.'.$extension;
            $localFsPath = $this->nextcloudApi->localPath($nextcloudPath);
            if ($localFsPath) {
                if (! copy($localFsPath, $tempFile)) {
                    Log::warning('FileRegistry: Failed to copy local file for phash', ['path' => $nextcloudPath]);

                    return null;
                }
            } else {
                $downloadResult = $this->nextcloudApi->downloadFile($nextcloudPath);
                if (! $downloadResult['success'] || empty($downloadResult['content'])) {
                    Log::warning('FileRegistry: Failed to download file for perceptual hashing', [
                        'file_registry_id' => $fileRegistryId,
                        'path' => $nextcloudPath,
                    ]);

                    return null;
                }
                file_put_contents($tempFile, $downloadResult['content']);
            }

            if (! file_exists($tempFile)) {
                return null;
            }

            // Compute and register hash
            $hashes = $phashService->registerHash($fileRegistryId, $tempFile);

            // Find and record similar images
            $similarResult = $phashService->findAndRecordSimilar($fileRegistryId, 10);

            // Cleanup temp file
            @unlink($tempFile);

            return [
                'dhash' => $hashes['dhash_hex'] ?? null,
                'phash' => $hashes['phash_hex'] ?? null,
                'similar_found' => $similarResult['found'] ?? 0,
                'similar_recorded' => $similarResult['recorded'] ?? 0,
            ];
        } catch (Exception $e) {
            Log::warning('FileRegistry: Perceptual hash computation failed', [
                'file_registry_id' => $fileRegistryId,
                'path' => $nextcloudPath,
                'error' => $e->getMessage(),
            ]);

            // Cleanup on error
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return null;
        }
    }

    /**
     * Compute video hash for a video file
     *
     * @param  int  $fileRegistryId  File registry ID
     * @param  string  $nextcloudPath  Path in Nextcloud
     * @return array|null Hash result or null on failure
     */
    private function computeVideoHashForFile(int $fileRegistryId, string $nextcloudPath): ?array
    {
        $extension = strtolower(pathinfo($nextcloudPath, PATHINFO_EXTENSION));

        if (! in_array($extension, config('file_types.video'))) {
            return null;
        }

        $videoHashService = $this->getVideoHashService();

        // Check if FFmpeg is available
        if (! $videoHashService->isFFmpegAvailable()) {
            Log::debug('FileRegistry: FFmpeg not available for video hashing', [
                'file_registry_id' => $fileRegistryId,
            ]);

            return null;
        }

        try {
            // Filesystem-first: skip WebDAV when local path is available
            $tempFile = $this->tempDir.'/'.uniqid('vhash_').'.'.$extension;
            $localFsPath = $this->nextcloudApi->localPath($nextcloudPath);
            if ($localFsPath) {
                if (! copy($localFsPath, $tempFile)) {
                    Log::warning('FileRegistry: Failed to copy local file for video hash', ['path' => $nextcloudPath]);

                    return null;
                }
            } else {
                $downloadResult = $this->nextcloudApi->downloadFile($nextcloudPath);
                if (! $downloadResult['success'] || empty($downloadResult['content'])) {
                    Log::warning('FileRegistry: Failed to download file for video hashing', [
                        'file_registry_id' => $fileRegistryId,
                        'path' => $nextcloudPath,
                    ]);

                    return null;
                }
                file_put_contents($tempFile, $downloadResult['content']);
            }

            if (! file_exists($tempFile)) {
                return null;
            }

            // Index the video
            $hashId = $videoHashService->indexVideo($fileRegistryId);

            // Find similar videos
            $similarResult = $videoHashService->findAndRecordSimilar($hashId, 0.85);

            // Cleanup temp file
            @unlink($tempFile);

            // Get the hash data
            $hashData = DB::selectOne(
                'SELECT duration_seconds, keyframe_count, combined_hash FROM file_registry_video_hashes WHERE id = ?',
                [$hashId]
            );

            return [
                'hash_id' => $hashId,
                'duration_seconds' => $hashData->duration_seconds ?? null,
                'keyframe_count' => $hashData->keyframe_count ?? null,
                'combined_hash' => $hashData->combined_hash ? substr($hashData->combined_hash, 0, 32).'...' : null,
                'similar_found' => $similarResult['found'] ?? 0,
                'similar_recorded' => $similarResult['recorded'] ?? 0,
            ];
        } catch (Exception $e) {
            Log::warning('FileRegistry: Video hash computation failed', [
                'file_registry_id' => $fileRegistryId,
                'path' => $nextcloudPath,
                'error' => $e->getMessage(),
            ]);

            // Cleanup on error
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return null;
        }
    }

    /**
     * Find visual duplicates for a given file
     *
     * @param  string  $assetUuid  Asset UUID to find duplicates for
     * @param  int  $threshold  Hamming distance threshold (default 10 = similar)
     * @return array ['success', 'duplicates' => [...], 'count']
     */
    public function findVisualDuplicates(string $assetUuid, int $threshold = 10): array
    {
        $file = DB::selectOne('
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.extension, ph.dhash_hex
            FROM file_registry fr
            LEFT JOIN file_registry_perceptual_hashes ph ON ph.file_registry_id = fr.id
            WHERE fr.asset_uuid = ?
        ', [$assetUuid]);

        if (! $file) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if (! $file->dhash_hex) {
            // Try to compute hash if not present
            if ($file->extension && in_array(strtolower($file->extension), array_merge(config('file_types.image'), config('file_types.image_raw')))) {
                $hashResult = $this->computePerceptualHashForFile($file->id, $file->current_path);
                if (! $hashResult || ! isset($hashResult['dhash'])) {
                    return ['success' => false, 'error' => 'Could not compute perceptual hash'];
                }
                $dhashHex = $hashResult['dhash'];
            } else {
                return ['success' => false, 'error' => 'Not an image file or hash not computed'];
            }
        } else {
            $dhashHex = $file->dhash_hex;
        }

        $phashService = $this->getPerceptualHashService();
        $similar = $phashService->findSimilar($dhashHex, $threshold);

        // Filter out self and format results
        $duplicates = [];
        foreach ($similar as $match) {
            if ($match->asset_uuid === $assetUuid) {
                continue;
            }

            $duplicates[] = [
                'asset_uuid' => $match->asset_uuid,
                'path' => $match->current_path,
                'filename' => $match->filename,
                'hamming_distance' => $match->hamming_distance,
                'similarity_type' => $phashService->classifySimilarity($match->hamming_distance),
            ];
        }

        return [
            'success' => true,
            'asset_uuid' => $assetUuid,
            'dhash' => $dhashHex,
            'threshold' => $threshold,
            'duplicates' => $duplicates,
            'count' => count($duplicates),
        ];
    }

    /**
     * Get visual duplicate report (similar to content hash duplicates)
     *
     * @param  string  $status  Filter by status (pending_review, confirmed_duplicate, etc.)
     * @param  int  $limit  Maximum results
     * @return array List of similar image pairs
     */
    public function getVisualDuplicatesReport(string $status = 'pending_review', int $limit = 100): array
    {
        return DB::select('
            SELECT
                sim.id,
                sim.hamming_distance,
                sim.similarity_type,
                sim.algorithm_used,
                sim.status,
                sim.created_at,
                a.asset_uuid as file_a_uuid,
                a.current_path as file_a_path,
                a.filename as file_a_name,
                b.asset_uuid as file_b_uuid,
                b.current_path as file_b_path,
                b.filename as file_b_name
            FROM file_registry_similar_images sim
            JOIN file_registry a ON a.id = sim.file_id_a
            JOIN file_registry b ON b.id = sim.file_id_b
            WHERE sim.status = ?
            ORDER BY sim.hamming_distance ASC, sim.created_at DESC
            LIMIT ?
        ', [$status, $limit]);
    }

    /**
     * Update visual duplicate status after review
     *
     * @param  int  $similarImageId  ID in file_registry_similar_images
     * @param  string  $status  New status
     * @return bool Success
     */
    public function updateVisualDuplicateStatus(int $similarImageId, string $status): bool
    {
        $validStatuses = ['pending_review', 'confirmed_duplicate', 'false_positive', 'different_versions'];
        if (! in_array($status, $validStatuses)) {
            return false;
        }

        $affected = DB::update('
            UPDATE file_registry_similar_images
            SET status = ?, reviewed_at = NOW()
            WHERE id = ?
        ', [$status, $similarImageId]);

        return $affected > 0;
    }

    /**
     * Get perceptual hash statistics
     */
    public function getPerceptualHashStats(): array
    {
        return $this->getPerceptualHashService()->getStatistics();
    }

    /**
     * Get video hash statistics
     */
    public function getVideoHashStats(): array
    {
        return $this->getVideoHashService()->getStatistics();
    }

    /**
     * Find similar videos for a given file
     *
     * @param  string  $assetUuid  Asset UUID to find similar videos for
     * @param  float  $threshold  Minimum similarity score (default 0.85)
     * @return array ['success', 'similar' => [...], 'count']
     */
    public function findSimilarVideos(string $assetUuid, float $threshold = 0.85): array
    {
        $file = DB::selectOne('
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.extension, vh.id as hash_id
            FROM file_registry fr
            LEFT JOIN file_registry_video_hashes vh ON vh.file_registry_id = fr.id
            WHERE fr.asset_uuid = ?
        ', [$assetUuid]);

        if (! $file) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if (! $file->hash_id) {
            // Try to compute hash if video and FFmpeg available
            if ($file->extension && in_array(strtolower($file->extension), config('file_types.video'))) {
                $hashResult = $this->computeVideoHashForFile($file->id, $file->current_path);
                if (! $hashResult || ! isset($hashResult['hash_id'])) {
                    return ['success' => false, 'error' => 'Could not compute video hash'];
                }
                $hashId = $hashResult['hash_id'];
            } else {
                return ['success' => false, 'error' => 'Not a video file or hash not computed'];
            }
        } else {
            $hashId = $file->hash_id;
        }

        $videoHashService = $this->getVideoHashService();
        $similar = $videoHashService->findSimilarVideos($hashId, $threshold);

        return [
            'success' => true,
            'similar' => $similar,
            'count' => count($similar),
        ];
    }

    /**
     * Get video duplicate report
     *
     * @param  string  $status  Filter by status
     * @param  int  $limit  Maximum results
     * @return array List of similar video pairs
     */
    public function getVideoDuplicatesReport(string $status = 'pending_review', int $limit = 100): array
    {
        return DB::select('
            SELECT
                sv.id,
                sv.similarity_score,
                sv.matched_keyframes,
                sv.status,
                sv.created_at,
                a.asset_uuid as file_a_uuid,
                a.current_path as file_a_path,
                a.filename as file_a_name,
                vh1.duration_seconds as file_a_duration,
                b.asset_uuid as file_b_uuid,
                b.current_path as file_b_path,
                b.filename as file_b_name,
                vh2.duration_seconds as file_b_duration
            FROM file_registry_similar_videos sv
            JOIN file_registry_video_hashes vh1 ON vh1.id = sv.video_hash_id_1
            JOIN file_registry_video_hashes vh2 ON vh2.id = sv.video_hash_id_2
            JOIN file_registry a ON a.id = vh1.file_registry_id
            JOIN file_registry b ON b.id = vh2.file_registry_id
            WHERE sv.status = ?
            ORDER BY sv.similarity_score DESC, sv.created_at DESC
            LIMIT ?
        ', [$status, $limit]);
    }

    /**
     * Update video duplicate status after review
     *
     * @param  int  $similarVideoId  ID in file_registry_similar_videos
     * @param  string  $status  New status
     * @return bool Success
     */
    public function updateVideoDuplicateStatus(int $similarVideoId, string $status): bool
    {
        $videoHashService = $this->getVideoHashService();

        return $videoHashService->updateReviewStatus($similarVideoId, $status);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Check if a path is in a structured/organized folder (used for dedup scoring).
     * Files in numbered folders are preferred over unsorted files.
     */
    public function isOrganizedFolder(string $path): bool
    {
        // Match any path component that starts with a number prefix like "01-", "101-"
        return (bool) preg_match('#/\d{2,3}-[^/]+/#', $path);
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    // ========================================================================
    // FACE REGION INTEGRATION
    // ========================================================================

    private ?FaceRegionService $faceRegionService = null;

    /**
     * Get or create FaceRegionService
     */
    private function getFaceRegionService(): FaceRegionService
    {
        if (! $this->faceRegionService) {
            $this->faceRegionService = app(FaceRegionService::class);
        }

        return $this->faceRegionService;
    }

    /**
     * Scan a file for face regions and store in database
     *
     * @param  string  $assetUuid  Asset UUID
     * @param  string|null  $localPath  Local file path (if already downloaded)
     * @return array Result with faces found
     */
    public function scanFileFaces(string $assetUuid, ?string $localPath = null): array
    {
        $file = DB::selectOne('SELECT * FROM file_registry WHERE asset_uuid = ?', [$assetUuid]);
        if (! $file) {
            return ['success' => false, 'error' => 'Asset not found'];
        }

        $extension = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
        if (! in_array($extension, array_merge(config('file_types.image'), config('file_types.image_raw')))) {
            return ['success' => false, 'error' => 'Not an image file'];
        }

        $faceService = $this->getFaceRegionService();
        if (! $faceService->isAvailable()) {
            return ['success' => false, 'error' => 'ExifTool not available'];
        }

        // Use local path if available, otherwise download via WebDAV
        $tempFile = null;
        if (! $localPath) {
            // Check if current_path is accessible locally (same server)
            if (file_exists($file->current_path)) {
                $localPath = $file->current_path;
            } else {
                // Fall back to WebDAV download
                $downloadResult = $this->nextcloudApi->downloadFile($file->current_path);
                if (! $downloadResult['success']) {
                    return ['success' => false, 'error' => 'Failed to download file'];
                }
                $tempFile = $this->tempDir.'/'.$file->filename;
                file_put_contents($tempFile, $downloadResult['content']);
                $localPath = $tempFile;
            }
        }

        try {
            // Read face regions from XMP metadata
            $regions = $faceService->readFaceRegions($localPath);

            // Clear existing faces for this file
            DB::delete('DELETE FROM file_registry_faces WHERE file_registry_id = ?', [$file->id]);

            // Store new faces
            $facesStored = 0;
            foreach ($regions as $region) {
                if (empty($region['name'])) {
                    continue;
                }

                // Try to match with genealogy person
                $genealogyPersonId = $this->matchGenealogyPerson($region['name']);

                DB::insert("
                    INSERT INTO file_registry_faces (
                        file_registry_id, person_name, genealogy_person_id,
                        region_x, region_y, region_w, region_h,
                        source, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'xmp', NOW(), NOW())
                ", [
                    $file->id,
                    $region['name'],
                    $genealogyPersonId,
                    $region['x'],
                    $region['y'],
                    $region['w'],
                    $region['h'],
                ]);
                $facesStored++;
            }

            // Update file_registry with face count and scan time
            DB::update('
                UPDATE file_registry SET face_count = ?, face_scan_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ', [$facesStored, $file->id]);

            return [
                'success' => true,
                'asset_uuid' => $assetUuid,
                'faces_found' => count($regions),
                'faces_stored' => $facesStored,
                'regions' => $regions,
            ];
        } finally {
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Search files by person name (in face regions)
     *
     * @param  string  $personName  Person name to search
     * @param  int  $limit  Max results
     * @return array Files containing the person
     */
    public function searchFilesByPerson(string $personName, int $limit = 100): array
    {
        return DB::select('
            SELECT fr.*, frf.person_name, frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   frf.genealogy_person_id
            FROM file_registry fr
            JOIN file_registry_faces frf ON frf.file_registry_id = fr.id
            WHERE frf.person_name LIKE ?
            ORDER BY fr.created_at DESC
            LIMIT ?
        ', ['%'.$personName.'%', $limit]);
    }

    /**
     * Get all persons found in files
     *
     * @param  int  $minOccurrences  Minimum number of files containing person
     * @return array Person names with file counts
     */
    public function getPersonsInFiles(int $minOccurrences = 1): array
    {
        return DB::select('
            SELECT person_name, genealogy_person_id, COUNT(*) as file_count
            FROM file_registry_faces
            GROUP BY person_name, genealogy_person_id
            HAVING COUNT(*) >= ?
            ORDER BY file_count DESC
        ', [$minOccurrences]);
    }

    /**
     * Get faces in a specific file
     *
     * @param  string  $assetUuid  Asset UUID
     * @return array Face regions in the file
     */
    public function getFileFaces(string $assetUuid): array
    {
        $file = DB::selectOne('SELECT id FROM file_registry WHERE asset_uuid = ?', [$assetUuid]);
        if (! $file) {
            return [];
        }

        return DB::select('
            SELECT * FROM file_registry_faces
            WHERE file_registry_id = ?
            ORDER BY person_name
        ', [$file->id]);
    }

    /**
     * Link a face to a genealogy person
     *
     * @param  int  $faceId  Face record ID
     * @param  int  $genealogyPersonId  Genealogy person ID
     * @return bool Success
     */
    public function linkFaceToGenealogyPerson(int $faceId, int $genealogyPersonId): bool
    {
        $result = app(FaceLinkBridgeService::class)->syncFaceLink($faceId, $genealogyPersonId);

        return $result['success'] ?? false;
    }

    /**
     * Get files for a genealogy person (via face regions)
     *
     * @param  int  $genealogyPersonId  Genealogy person ID
     * @return array Files containing the person
     */
    public function getFilesForGenealogyPerson(int $genealogyPersonId): array
    {
        return DB::select('
            SELECT fr.*, frf.person_name, frf.region_x, frf.region_y, frf.region_w, frf.region_h
            FROM file_registry fr
            JOIN file_registry_faces frf ON frf.file_registry_id = fr.id
            WHERE frf.genealogy_person_id = ?
            ORDER BY fr.created_at DESC
        ', [$genealogyPersonId]);
    }

    /**
     * Batch scan files for faces
     *
     * @param  int  $limit  Max files to scan
     * @param  bool  $rescan  Whether to rescan already scanned files
     * @return array Results
     */
    public function batchScanFaces(int $limit = 100, bool $rescan = false): array
    {
        $query = "
            SELECT asset_uuid, original_filename, current_path
            FROM file_registry
            WHERE status = 'active'
            AND LOWER(SUBSTRING_INDEX(original_filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'heic', 'tiff', 'tif')
        ";

        if (! $rescan) {
            $query .= ' AND face_scan_at IS NULL';
        }

        $query .= ' ORDER BY created_at DESC LIMIT ?';

        $files = DB::select($query, [$limit]);

        $results = ['scanned' => 0, 'faces_found' => 0, 'errors' => 0];

        foreach ($files as $file) {
            $scanResult = $this->scanFileFaces($file->asset_uuid);
            if ($scanResult['success']) {
                $results['scanned']++;
                $results['faces_found'] += $scanResult['faces_found'];
            } else {
                $results['errors']++;
            }
        }

        Log::info('FileRegistry: Batch face scan completed', $results);

        return $results;
    }

    /**
     * Try to match a person name with genealogy_persons table
     *
     * @param  string  $name  Person name from face region
     * @return int|null Genealogy person ID if matched
     */
    private function matchGenealogyPerson(string $name): ?int
    {
        // Try exact match first
        $person = DB::selectOne("
            SELECT id FROM genealogy_persons
            WHERE CONCAT(given_name, ' ', surname) = ?
            OR CONCAT(surname, ', ', given_name) = ?
            LIMIT 1
        ", [$name, $name]);

        if ($person) {
            return $person->id;
        }

        // Try fuzzy match on parts
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) >= 2) {
            $firstName = $parts[0];
            $lastName = end($parts);

            $person = DB::selectOne('
                SELECT id FROM genealogy_persons
                WHERE (given_name LIKE ? OR given_name LIKE ?)
                AND surname LIKE ?
                LIMIT 1
            ', [$firstName.'%', '%'.$firstName, $lastName.'%']);

            if ($person) {
                return $person->id;
            }
        }

        return null;
    }

    // ─── File Curator Agent Methods ───

    /**
     * Get files with no AI tags, no document type, or no category
     */
    public function getUncategorizedFiles(int $limit = 100, ?string $extension_filter = null): array
    {
        $where = "WHERE status = 'active' AND (ai_tags IS NULL OR ai_tags = '[]' OR ai_tags = 'null' OR ai_document_type IS NULL OR ai_document_type = '' OR category IS NULL OR category = '')";
        $params = [];

        if ($extension_filter) {
            $where .= ' AND extension = ?';
            $params[] = strtolower($extension_filter);
        }

        $params[] = $limit;

        $files = DB::select("
            SELECT asset_uuid, filename, extension, current_path, status, category,
                   ai_document_type, ai_tags, created_at
            FROM file_registry
            {$where}
            ORDER BY created_at DESC
            LIMIT ?
        ", $params);

        $total = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry
            WHERE status = 'active' AND (ai_tags IS NULL OR ai_tags = '[]' OR ai_tags = 'null' OR ai_document_type IS NULL OR ai_document_type = '' OR category IS NULL OR category = '')
        ");

        return [
            'total_uncategorized' => $total->cnt ?? 0,
            'returned' => count($files),
            'files' => array_map(function ($f) {
                return [
                    'uuid' => $f->asset_uuid,
                    'filename' => $f->filename,
                    'extension' => $f->extension,
                    'path' => $f->current_path,
                    'category' => $f->category,
                    'ai_document_type' => $f->ai_document_type,
                    'has_ai_tags' => ! empty($f->ai_tags) && $f->ai_tags !== '[]' && $f->ai_tags !== 'null',
                    'created_at' => $f->created_at,
                ];
            }, $files),
        ];
    }

    /**
     * Analyze folder distribution of active files
     */
    public function getFolderDistribution(int $depth = 2): array
    {
        $files = DB::select("
            SELECT current_path, COUNT(*) as file_count
            FROM file_registry
            WHERE status = 'active' AND current_path IS NOT NULL AND current_path != ''
            GROUP BY current_path
        ");

        $folderCounts = [];
        foreach ($files as $row) {
            $parts = explode('/', trim($row->current_path, '/'));
            $folderKey = implode('/', array_slice($parts, 0, min($depth, max(count($parts) - 1, 1))));
            if ($folderKey === '') {
                $folderKey = '/';
            }
            $folderCounts[$folderKey] = ($folderCounts[$folderKey] ?? 0) + $row->file_count;
        }

        arsort($folderCounts);

        $totalFiles = array_sum($folderCounts);
        $folderCount = count($folderCounts);
        $avgPerFolder = $folderCount > 0 ? round($totalFiles / $folderCount, 1) : 0;

        // Top 20 largest folders
        $largest = array_slice($folderCounts, 0, 20, true);

        // Folders with very few files (potential cleanup candidates)
        $sparse = array_filter($folderCounts, fn ($c) => $c <= 3);

        return [
            'total_folders' => $folderCount,
            'total_files' => $totalFiles,
            'avg_files_per_folder' => $avgPerFolder,
            'largest_folders' => $largest,
            'sparse_folders_count' => count($sparse),
            'sparse_folders' => array_slice($sparse, 0, 10, true),
        ];
    }

    /**
     * Get recently registered files
     */
    public function getRecentIngestions(int $hours = 24, int $limit = 100): array
    {
        $since = now()->subHours($hours)->format('Y-m-d H:i:s');

        $files = DB::select("
            SELECT asset_uuid, filename, extension, current_path, ai_document_type,
                   category, ai_tags, created_at
            FROM file_registry
            WHERE status = 'active' AND created_at >= ?
            ORDER BY created_at DESC
            LIMIT ?
        ", [$since, $limit]);

        $total = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry
            WHERE status = 'active' AND created_at >= ?
        ", [$since]);

        return [
            'period_hours' => $hours,
            'total_new' => $total->cnt ?? 0,
            'returned' => count($files),
            'files' => array_map(function ($f) {
                return [
                    'uuid' => $f->asset_uuid,
                    'filename' => $f->filename,
                    'extension' => $f->extension,
                    'path' => $f->current_path,
                    'ai_document_type' => $f->ai_document_type,
                    'category' => $f->category,
                    'has_ai_tags' => ! empty($f->ai_tags) && $f->ai_tags !== '[]' && $f->ai_tags !== 'null',
                    'created_at' => $f->created_at,
                ];
            }, $files),
        ];
    }

    /**
     * Suggest categories for uncategorized files based on filename, extension, path patterns
     */
    public function suggestCategories(int $limit = 50): array
    {
        $files = DB::select("
            SELECT id, asset_uuid, filename, extension, current_path, ai_document_type, ai_tags, category
            FROM file_registry
            WHERE status = 'active'
            AND (ai_document_type IS NULL OR ai_document_type = '' OR category IS NULL OR category = '')
            ORDER BY created_at DESC
            LIMIT ?
        ", [$limit]);

        $suggestions = [];
        foreach ($files as $f) {
            $suggestion = $this->inferCategoryFromMetadata($f);
            if ($suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        return [
            'analyzed' => count($files),
            'suggestions_generated' => count($suggestions),
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Infer category from file metadata (filename, extension, path)
     */
    private function inferCategoryFromMetadata(object $file): ?array
    {
        $ext = strtolower($file->extension ?? '');
        $filename = strtolower($file->filename ?? '');
        $path = strtolower($file->current_path ?? '');

        $suggestedType = null;
        $suggestedCategory = null;
        $confidence = 0.0;
        $reasoning = '';

        // Extension-based inference
        $extMap = [
            'pdf' => ['document', 'Documents', 0.5, 'PDF file'],
            'jpg' => ['photo', 'Photos', 0.6, 'JPEG image'],
            'jpeg' => ['photo', 'Photos', 0.6, 'JPEG image'],
            'png' => ['image', 'Images', 0.5, 'PNG image'],
            'gif' => ['image', 'Images', 0.5, 'GIF image'],
            'mp4' => ['video', 'Videos', 0.7, 'MP4 video'],
            'mov' => ['video', 'Videos', 0.7, 'MOV video'],
            'mp3' => ['audio', 'Audio', 0.7, 'MP3 audio'],
            'docx' => ['document', 'Documents', 0.6, 'Word document'],
            'xlsx' => ['spreadsheet', 'Documents', 0.7, 'Excel spreadsheet'],
            'pptx' => ['presentation', 'Documents', 0.7, 'PowerPoint presentation'],
            'csv' => ['data', 'Data', 0.6, 'CSV data file'],
            'json' => ['data', 'Data', 0.5, 'JSON data file'],
            'txt' => ['document', 'Documents', 0.4, 'Text file'],
            'zip' => ['archive', 'Archives', 0.7, 'ZIP archive'],
            'rar' => ['archive', 'Archives', 0.7, 'RAR archive'],
        ];

        if (isset($extMap[$ext])) {
            [$suggestedType, $suggestedCategory, $confidence, $reasoning] = $extMap[$ext];
        }

        // Path-based inference (overrides extension if stronger signal)
        $pathPatterns = [
            'photo' => ['photo', 'Photos', 0.8, 'Located in photos folder'],
            'picture' => ['photo', 'Photos', 0.8, 'Located in pictures folder'],
            'document' => ['document', 'Documents', 0.7, 'Located in documents folder'],
            'invoice' => ['invoice', 'Financial', 0.8, 'Located in invoices folder'],
            'receipt' => ['receipt', 'Financial', 0.8, 'Located in receipts folder'],
            'tax' => ['tax_document', 'Financial', 0.7, 'Located in tax folder'],
            'medical' => ['medical_record', 'Medical', 0.7, 'Located in medical folder'],
            'video' => ['video', 'Videos', 0.8, 'Located in videos folder'],
            'music' => ['audio', 'Audio', 0.8, 'Located in music folder'],
            'backup' => ['backup', 'Backups', 0.7, 'Located in backup folder'],
            'genealogy' => ['genealogy', 'Genealogy', 0.8, 'Located in genealogy folder'],
        ];

        foreach ($pathPatterns as $pattern => [$type, $cat, $conf, $reason]) {
            if (str_contains($path, $pattern) && $conf > $confidence) {
                $suggestedType = $type;
                $suggestedCategory = $cat;
                $confidence = $conf;
                $reasoning = $reason;
            }
        }

        // Filename-based inference (strongest signal for specific types)
        $filenamePatterns = [
            '/invoice/i' => ['invoice', 'Financial', 0.85, 'Filename contains "invoice"'],
            '/receipt/i' => ['receipt', 'Financial', 0.85, 'Filename contains "receipt"'],
            '/statement/i' => ['bank_statement', 'Financial', 0.8, 'Filename contains "statement"'],
            '/resume|cv\b/i' => ['resume', 'Career', 0.8, 'Filename suggests resume/CV'],
            '/contract/i' => ['contract', 'Legal', 0.8, 'Filename contains "contract"'],
            '/screenshot/i' => ['screenshot', 'Screenshots', 0.9, 'Filename contains "screenshot"'],
            '/IMG_\d+/i' => ['photo', 'Photos', 0.7, 'Camera-style filename pattern'],
            '/DSC_?\d+/i' => ['photo', 'Photos', 0.7, 'DSLR camera filename pattern'],
            '/scan/i' => ['scan', 'Scans', 0.7, 'Filename suggests scanned document'],
        ];

        foreach ($filenamePatterns as $pattern => [$type, $cat, $conf, $reason]) {
            if (preg_match($pattern, $filename) && $conf > $confidence) {
                $suggestedType = $type;
                $suggestedCategory = $cat;
                $confidence = $conf;
                $reasoning = $reason;
            }
        }

        if (! $suggestedType || $confidence < 0.4) {
            return null;
        }

        return [
            'uuid' => $file->asset_uuid,
            'filename' => $file->filename,
            'current_type' => $file->ai_document_type,
            'current_category' => $file->category,
            'suggested_type' => $suggestedType,
            'suggested_category' => $suggestedCategory,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * Recommend resolutions for duplicate file pairs
     */
    public function recommendDuplicateResolutions(int $limit = 20): array
    {
        $duplicates = DB::select("
            SELECT d.id as pair_id, d.content_hash,
                   f1.asset_uuid as uuid_a, f1.filename as filename_a, f1.current_path as path_a,
                   f1.file_size as size_a, f1.ai_tags as tags_a, f1.ai_document_type as type_a,
                   f1.category as category_a, f1.updated_at as updated_a,
                   f2.asset_uuid as uuid_b, f2.filename as filename_b, f2.current_path as path_b,
                   f2.file_size as size_b, f2.ai_tags as tags_b, f2.ai_document_type as type_b,
                   f2.category as category_b, f2.updated_at as updated_b
            FROM file_registry_duplicates d
            JOIN file_registry f1 ON d.canonical_file_id = f1.id
            JOIN file_registry f2 ON d.duplicate_file_id = f2.id
            WHERE d.status = 'pending_review'
            AND f1.status = 'active' AND f2.status = 'active'
            ORDER BY f1.file_size DESC
            LIMIT ?
        ", [$limit]);

        $recommendations = [];
        foreach ($duplicates as $d) {
            $scoreA = $this->scoreDuplicateCandidate($d, 'a');
            $scoreB = $this->scoreDuplicateCandidate($d, 'b');

            $keepSide = $scoreA >= $scoreB ? 'a' : 'b';
            $keepUuid = $keepSide === 'a' ? $d->uuid_a : $d->uuid_b;
            $removeUuid = $keepSide === 'a' ? $d->uuid_b : $d->uuid_a;
            $reasoning = [];

            if ($scoreA !== $scoreB) {
                if ($keepSide === 'a') {
                    $reasoning[] = "File A scores higher ({$scoreA} vs {$scoreB})";
                } else {
                    $reasoning[] = "File B scores higher ({$scoreB} vs {$scoreA})";
                }
            }

            // Explain scoring factors
            $keepPath = $keepSide === 'a' ? $d->path_a : $d->path_b;
            if ($this->isOrganizedFolder($keepPath)) {
                $reasoning[] = 'Kept file is in an organized folder';
            }

            $keepTags = $keepSide === 'a' ? $d->tags_a : $d->tags_b;
            if (! empty($keepTags) && $keepTags !== '[]' && $keepTags !== 'null') {
                $reasoning[] = 'Kept file has AI tags';
            }

            $recommendations[] = [
                'pair_id' => $d->pair_id,
                'keep_uuid' => $keepUuid,
                'remove_uuid' => $removeUuid,
                'keep_filename' => $keepSide === 'a' ? $d->filename_a : $d->filename_b,
                'remove_filename' => $keepSide === 'a' ? $d->filename_b : $d->filename_a,
                'file_size' => $d->size_a,
                'reasoning' => implode('; ', $reasoning),
                'confidence' => min(1.0, abs($scoreA - $scoreB) / 5 + 0.5),
            ];
        }

        return [
            'analyzed_pairs' => count($duplicates),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Score a duplicate candidate (higher = keep this one)
     */
    public function scoreDuplicateCandidate(object $dup, string $side): int
    {
        $score = 0;

        $path = $side === 'a' ? $dup->path_a : $dup->path_b;
        $tags = $side === 'a' ? $dup->tags_a : $dup->tags_b;
        $type = $side === 'a' ? $dup->type_a : $dup->type_b;
        $category = $side === 'a' ? $dup->category_a : $dup->category_b;
        $updated = $side === 'a' ? $dup->updated_a : $dup->updated_b;

        // In organized folder (+3)
        if ($this->isOrganizedFolder($path)) {
            $score += 3;
        }

        // Has AI tags (+2)
        if (! empty($tags) && $tags !== '[]' && $tags !== 'null') {
            $score += 2;
        }

        // Has document type (+1)
        if (! empty($type)) {
            $score += 1;
        }

        // Has category (+1)
        if (! empty($category)) {
            $score += 1;
        }

        // More recently updated (+1)
        $otherUpdated = $side === 'a' ? $dup->updated_b : $dup->updated_a;
        if ($updated > $otherUpdated) {
            $score += 1;
        }

        return $score;
    }
}

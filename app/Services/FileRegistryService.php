<?php

namespace App\Services;

use App\Jobs\AIAutoTagJob;
use App\Jobs\ExecuteFileRegistryScan;
use App\Services\Files\ReviewedFaceMetadataReplacementService;
use App\Services\Genealogy\AppearanceProfileRefreshService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\Genealogy\FaceMatchReviewDecisionService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Context;
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
    public const SOURCE_INDEX_ACTION = 'source_index_reconcile';

    public const SINGLE_SOURCE_INDEX_ACTION = 'source_index_correct';

    private const MAX_ORPHAN_WARNING_DETAILS_PER_PROCESS = 1;

    private static int $orphanWarningsLogged = 0;

    private static bool $orphanWarningSummaryLogged = false;

    private NextcloudFileApiService $nextcloudApi;

    private FileIntakeStabilityService $intakeStability;

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

    public function __construct(
        NextcloudFileApiService $nextcloudApi,
        ?FileIntakeStabilityService $intakeStability = null
    ) {
        $this->nextcloudApi = $nextcloudApi;
        $this->intakeStability = $intakeStability ?? app(FileIntakeStabilityService::class);
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
        $localOnly = ($options['require_local_source'] ?? false) === true;
        $deadline = $options['deadline'] ?? null;
        if ($deadline !== null && (! is_numeric($deadline) || ! is_finite((float) $deadline)
            || hrtime(true) / 1e9 >= (float) $deadline)) {
            return ['success' => false, 'reason' => 'intake_deadline_exceeded', 'retryable' => true];
        }
        if ($localOnly) {
            // Catalog custody cannot authorize unbounded external enrichment inside its lease.
            $options = array_replace($options, ['compute_perceptual_hash' => false, 'compute_video_hash' => false,
                'auto_analyze' => false, 'analyze_sync' => false]);
        }

        // Get file info from Nextcloud first
        $fileInfo = $localOnly ? $this->nextcloudApi->getFileInfo($nextcloudPath, true)
            : $this->nextcloudApi->getFileInfo($nextcloudPath);
        if (! $fileInfo['success']) {
            if ($localOnly) {
                return $fileInfo;
            }
            throw new Exception('Failed to get file info from Nextcloud: '.($fileInfo['error'] ?? 'Unknown error'));
        }
        if ($localOnly && (! is_array($options['expected_source_fingerprint'] ?? null)
            || ($fileInfo['source_fingerprint'] ?? null) !== $options['expected_source_fingerprint'])) {
            return ['success' => false, 'reason' => 'source_changed_before_intake', 'retryable' => true];
        }
        if (($fileInfo['is_directory'] ?? false) || in_array($fileInfo['mime_type'] ?? null, ['httpd/unix-directory', 'inode/directory'], true)) {
            return ['success' => false, 'error' => 'Directory cannot be registered as a file', 'review_required' => true];
        }

        // Parse Nextcloud last_modified timestamp
        $ncModifiedAt = null;
        if (! empty($fileInfo['last_modified'])) {
            try {
                $ncModifiedAt = Carbon::parse($fileInfo['last_modified'])->toDateTimeString();
            } catch (Exception $e) {
                Log::debug('FileRegistryService: date parse failed for last_modified', ['value' => $fileInfo['last_modified'], 'error' => $e->getMessage()]);
            }
        }

        // An indexed legacy lowercase hash must never manufacture a second asset.
        try {
            $existing = $this->sourceIndexPathRow($nextcloudPath, false, $deadline === null ? null : (float) $deadline, $localOnly);
        } catch (\RuntimeException $error) {
            return ['success' => false, 'reason' => 'source_index_review_required',
                'error' => $error->getMessage(), 'review_required' => true];
        }

        if ($existing) {
            if (($existing->status ?? null) !== 'active'
                || ($existing->current_path ?? null) !== $nextcloudPath
                || ($existing->nextcloud_fileid !== null && ($fileInfo['fileid'] ?? null) !== null
                    && (int) $existing->nextcloud_fileid !== (int) $fileInfo['fileid'])) {
                return ['success' => false, 'asset_uuid' => $existing->asset_uuid,
                    'error' => 'Existing file identity or status requires reviewed recovery', 'review_required' => true];
            }
            $currentSize = isset($fileInfo['size']) ? filter_var($fileInfo['size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            $savedSize = isset($existing->file_size) ? filter_var($existing->file_size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            // Timestamp equality alone does not prove even the physical metadata is unchanged.
            if ($ncModifiedAt && $existing->nextcloud_modified_at === $ncModifiedAt
                && $currentSize !== false && $savedSize !== false && $currentSize === $savedSize) {
                if ($deadline !== null && hrtime(true) / 1e9 >= (float) $deadline) {
                    return ['success' => false, 'reason' => 'intake_deadline_exceeded', 'retryable' => true];
                }

                return [
                    'success' => true,
                    'asset_uuid' => $existing->asset_uuid,
                    'reference' => "{{ASSET:{$existing->asset_uuid}}}",
                    'skipped' => true,
                    'outcome' => 'unchanged',
                    'reason' => 'File metadata unchanged (size and lastmodified match)',
                ];
            }

            // Update if needed and return existing
            return $localOnly ? $this->refreshFileRegistration($existing->asset_uuid, $ncModifiedAt, $options)
                : $this->refreshFileRegistration($existing->asset_uuid, $ncModifiedAt);
        }

        $nextcloudFileid = $fileInfo['fileid'] ?? null;
        if ($nextcloudFileid) {
            $fileidMatches = DB::select('
                SELECT id, asset_uuid, nextcloud_fileid, current_path, status, nextcloud_modified_at
                FROM file_registry
                WHERE nextcloud_fileid = ?
                LIMIT 2
            ', [$nextcloudFileid]);
            if (count($fileidMatches) > 1) {
                return ['success' => false, 'error' => 'Ambiguous fileid requires reviewed recovery', 'review_required' => true];
            }
            $existingByFileid = $fileidMatches[0] ?? null;

            if ($existingByFileid) {
                if (($existingByFileid->status ?? null) !== 'active' || $existingByFileid->current_path !== $nextcloudPath) {
                    return ['success' => false, 'asset_uuid' => $existingByFileid->asset_uuid,
                        'error' => 'Existing fileid requires current-source recovery', 'review_required' => true];
                }

                return $localOnly ? $this->refreshFileRegistration($existingByFileid->asset_uuid, $ncModifiedAt, $options)
                    : $this->refreshFileRegistration($existingByFileid->asset_uuid, $ncModifiedAt);
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
            $hashResult = $localOnly ? $this->nextcloudApi->computeFileHash($nextcloudPath, $deadline === null ? null : (float) $deadline, true)
                : $this->nextcloudApi->computeFileHash($nextcloudPath);
            if ($hashResult['success']) {
                $contentHash = $hashResult['hash'];
            }
            if ($localOnly && (! ($hashResult['success'] ?? false)
                || ! is_string($contentHash) || ! preg_match('/^[a-f0-9]{64}$/D', $contentHash))) {
                return array_replace($hashResult, ['success' => false]);
            }
            if ($localOnly && ($hashResult['source_fingerprint'] ?? null) !== $fileInfo['source_fingerprint']) {
                return ['success' => false, 'reason' => 'source_changed_during_intake', 'retryable' => true];
            }
        }
        if ($localOnly) {
            $after = $this->nextcloudApi->getFileInfo($nextcloudPath, true);
            if (! ($after['success'] ?? false)
                || ($after['source_fingerprint'] ?? null) !== $fileInfo['source_fingerprint']) {
                return ['success' => false, 'reason' => 'source_changed_during_intake', 'retryable' => true];
            }
            if ($deadline !== null && hrtime(true) / 1e9 >= (float) $deadline) {
                return ['success' => false, 'reason' => 'intake_deadline_exceeded', 'retryable' => true];
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

        Log::info($localOnly ? 'FileRegistry: File registration staged for discovery commit' : 'FileRegistry: File registered', [
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
            AIAutoTagJob::dispatch((int) $registeredId);
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
            'outcome' => $duplicateOf ? 'duplicate' : 'registered',
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
     * Reconcile the registry row for the exact photo accepted by an operator
     * face-region review. The caller must already hold the review's MySQL
     * transaction; this method never relocates a registry row by file ID.
     *
     * @return array{id:int,asset_uuid:string,current_path:string,content_hash:string}
     */
    public function registerReviewedFaceSource(array $proof, string $receiptId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reviewed face source registration requires an outer MySQL transaction.');
        }

        $receipt = DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first();
        if (! $receipt || $receipt->action !== 'refresh_region' || $receipt->state !== 'processing') {
            throw new \RuntimeException('Current operator face-region review receipt is unavailable.');
        }

        $payload = json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR);
        $decision = is_array($payload) ? ($payload['current_face_region_decision'] ?? null) : null;
        if (! is_array($decision)
            || ($payload['reference'] ?? null) !== $receipt->reference
            || ($payload['action'] ?? null) !== 'refresh_region'
            || ($payload['decision_scope'] ?? null) !== 'review_current_face_region'
            || ! is_string($payload['actor'] ?? null) || trim($payload['actor']) === '') {
            throw new \RuntimeException('Receipt lacks the exact operator face-region decision.');
        }

        $queueId = filter_var($decision['queue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $treeId = filter_var($decision['tree_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $mediaId = filter_var($decision['media_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sourceSha256 = $decision['source_sha256'] ?? null;
        $region = $this->reviewedFaceRegion($decision['region'] ?? null);
        if ($queueId === false || $treeId === false || $mediaId === false
            || ! is_string($sourceSha256) || ! preg_match('/^[a-f0-9]{64}$/D', $sourceSha256)
            || $region === null || ($decision['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($decision['coordinate_basis'] ?? null) !== 'encoded_pixels') {
            throw new \RuntimeException('Receipt face-region evidence is invalid.');
        }

        $policy = DB::table('review_desk_policy')->where('review_type', 'face')->where('action', 'refresh_region')->first();
        $conditions = $policy ? json_decode((string) $policy->conditions, true) : null;
        if (! $policy || ! $policy->enabled || $policy->bulk_enabled || ! $policy->sensitive
            || $policy->service_class !== FaceMatchReviewDecisionService::class
            || $policy->method !== 'refreshRegion' || ! is_array($conditions)
            || ($conditions['operator_only'] ?? null) !== true
            || ($conditions['current_source_required'] ?? null) !== true
            || ($conditions['materialize_current_source'] ?? null) !== true) {
            throw new \RuntimeException('Current-source face-region materialization is not admitted by policy.');
        }

        $queue = DB::table('genealogy_face_match_queue')->where('id', $queueId)->lockForUpdate()->first();
        if (! $queue || $queue->status !== 'pending' || (int) $queue->tree_id !== $treeId
            || (int) $queue->media_id !== $mediaId) {
            throw new \RuntimeException('Pending face review no longer matches its receipt.');
        }
        $queueSourceRevision = hash('sha256', json_encode((array) $queue, JSON_THROW_ON_ERROR));
        $reference = DB::table('review_desk_references')->where('reference', $receipt->reference)->lockForUpdate()->first();
        $sourceRows = DB::table('review_desk_sources')->where('source_table', 'genealogy_face_match_queue')->get();
        $sourceKeyIsRegistered = $sourceRows->contains(static function (object $source) use ($reference): bool {
            $key = ($source->connection_name ?? 'default').':genealogy_face_match_queue';

            return $reference && $reference->source === $key;
        });
        if (! $reference || ! $sourceKeyIsRegistered || $reference->review_type !== 'face'
            || $reference->source_id !== (string) $queueId || $reference->status !== 'pending'
            || $reference->source_state !== 'pending'
            || (int) ($decision['desk_revision'] ?? 0) !== (int) $reference->revision
            || ! is_string($reference->source_revision)
            || ! hash_equals($reference->source_revision, $queueSourceRevision)
            || ! is_string($decision['source_revision'] ?? null)
            || ! hash_equals($reference->source_revision, $decision['source_revision'])) {
            throw new \RuntimeException('Receipt source revision no longer identifies this pending queue row.');
        }

        $media = DB::table('genealogy_media')->where('id', $mediaId)->where('tree_id', $treeId)->lockForUpdate()->first();
        if (! $media) {
            throw new \RuntimeException('Tree-scoped source media is unavailable.');
        }
        $mediaPath = $media->nextcloud_path ?: $media->original_path;
        $currentPath = $this->canonicalReviewedFacePath($mediaPath);
        $proofPath = $this->canonicalReviewedFacePath($proof['source_path'] ?? null);
        $sourceDimensions = $proof['source_dimensions'] ?? null;
        if ($currentPath === null || $proofPath !== $currentPath
            || ($proof['success'] ?? null) !== true
            || ($proof['source_sha256'] ?? null) !== $sourceSha256
            || ($proof['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($proof['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ! is_array($sourceDimensions) || count($sourceDimensions) !== 2
            || ! isset($sourceDimensions[0], $sourceDimensions[1])
            || ! is_int($sourceDimensions[0]) || ! is_int($sourceDimensions[1])
            || min($sourceDimensions) < 1
            || ! $this->sameReviewedFaceRegion($region, $proof['region'] ?? null)) {
            throw new \RuntimeException('Current source proof does not match the receipt media and region.');
        }

        $fileInfo = $this->nextcloudApi->getFileInfo($currentPath, false, true);
        if (! ($fileInfo['success'] ?? false)) {
            throw new \RuntimeException('Current source file information is unavailable.');
        }
        $fileId = isset($fileInfo['fileid']) && (string) $fileInfo['fileid'] !== '' ? (string) $fileInfo['fileid'] : null;
        $currentHash = $this->reviewedFaceSourceHash($currentPath);
        if (! hash_equals($sourceSha256, $currentHash)) {
            throw new \RuntimeException('Current source bytes changed after operator review.');
        }

        $pathHash = hash('sha256', $currentPath);
        $registry = $this->sourceIndexPathRow($currentPath, true);
        if ($registry && $registry->current_path !== $currentPath) {
            throw new \RuntimeException('Registry path hash does not match the reviewed source path.');
        }
        if ($registry && $registry->nextcloud_fileid !== null && $fileId !== null
            && (string) $registry->nextcloud_fileid !== $fileId) {
            throw new \RuntimeException('Registry file ID conflicts with the current source.');
        }
        if ($registry && $registry->nextcloud_fileid !== null && $fileId === null) {
            throw new \RuntimeException('Current source file ID cannot be verified.');
        }
        if ($fileId !== null) {
            $fileIdRows = DB::table('file_registry')->where('nextcloud_fileid', $fileId)
                ->where('status', '<>', 'deleted')->lockForUpdate()->get();
            foreach ($fileIdRows as $fileIdRow) {
                if (! $registry || (int) $fileIdRow->id !== (int) $registry->id
                    || $fileIdRow->current_path !== $currentPath) {
                    throw new \RuntimeException('Current Nextcloud file ID is registered at another path.');
                }
            }
        }

        if (! $registry) {
            $registration = $this->registerFile($currentPath, [
                'original_path' => $media->original_path,
                'original_source' => 'nextcloud',
                'compute_hash' => true,
                'compute_perceptual_hash' => false,
                'compute_video_hash' => false,
                'auto_analyze' => false,
                'analyze_sync' => false,
            ]);
            if (! ($registration['success'] ?? false)) {
                throw new \RuntimeException('Reviewed source could not be registered.');
            }
            $pathRows = DB::table('file_registry')->where('path_hash', $pathHash)->lockForUpdate()->get();
            if ($pathRows->count() !== 1) {
                throw new \RuntimeException('Reviewed source registration did not produce one exact path row.');
            }
            $registry = $pathRows->first();
        } else {
            if (! in_array($registry->status, ['active', 'deleted'], true)) {
                throw new \RuntimeException('Registry source is not eligible for reviewed recovery.');
            }
            $this->getLifecycleService()->supersedeFaceGeneration(
                (int) $registry->id,
                (string) $registry->asset_uuid,
                $sourceSha256,
                true
            );
            DB::table('file_registry')->where('id', $registry->id)->update([
                'status' => 'active', 'content_hash' => $sourceSha256,
                'content_hash_verified_at' => now(), 'nextcloud_fileid' => $fileId,
                'last_verified_at' => now(), 'verification_failures' => 0, 'updated_at' => now(),
            ]);
        }

        $registry = DB::table('file_registry')->where('id', $registry->id)->lockForUpdate()->first();
        if (! $registry || $registry->status !== 'active' || $registry->current_path !== $currentPath
            || $registry->path_hash !== $pathHash || ! is_string($registry->content_hash)
            || ! hash_equals($sourceSha256, $registry->content_hash)
            || ($registry->nextcloud_fileid !== null && $fileId !== null && (string) $registry->nextcloud_fileid !== $fileId)) {
            throw new \RuntimeException('Active registry row does not match the reviewed source.');
        }
        $finalInfo = $this->nextcloudApi->getFileInfo($currentPath, false, true);
        $finalHash = $this->reviewedFaceSourceHash($currentPath);
        $finalFileId = isset($finalInfo['fileid']) && (string) $finalInfo['fileid'] !== '' ? (string) $finalInfo['fileid'] : null;
        if (! ($finalInfo['success'] ?? false) || ! hash_equals($sourceSha256, $finalHash)
            || $finalFileId !== $fileId) {
            throw new \RuntimeException('Reviewed source changed before registration completed.');
        }

        return [
            'id' => (int) $registry->id,
            'asset_uuid' => (string) $registry->asset_uuid,
            'current_path' => (string) $registry->current_path,
            'content_hash' => (string) $registry->content_hash,
        ];
    }

    /** Read-only lookup; preserve path case and reuse reviewed alias/current-byte admission. */
    public function currentExactSourcePath(string $path, bool $lockForUpdate = false): ?object
    {
        $canonical = $this->canonicalReviewedFacePath($path);
        if ($canonical === null) {
            throw new \RuntimeException('Exact source path is invalid.');
        }
        $row = $this->sourceIndexPathRow($canonical, $lockForUpdate);
        if ($row !== null && $row->status !== 'active') {
            throw new \RuntimeException('Exact source registry is not active.');
        }

        return $row;
    }

    /** Byte-exact paths preserve Nextcloud's case-sensitive identity. */
    private function sourceIndexPathRow(string $path, bool $lock = false, ?float $deadline = null, bool $localOnly = false): ?object
    {
        $hash = hash('sha256', $path);
        $query = DB::table('file_registry')->where(function ($q) use ($path, $hash): void {
            $q->where('path_hash', $hash)->orWhere('current_path', $path);
        })->orderBy('id');
        if ($lock) {
            // Serialize normal exact-path claims on the unique hash slot, then
            // lock only rows discovered by the byte-exact path lookup below.
            DB::table('file_registry')->where('path_hash', $hash)->lockForUpdate()->first(['id']);
        }
        $rows = $query->get()
            ->filter(fn ($row) => $row->path_hash === $hash || $row->current_path === $path)->values();
        if ($lock && $rows->isNotEmpty()) {
            $rows = DB::table('file_registry')->whereIn('id', $rows->pluck('id')->all())
                ->orderBy('id')->lockForUpdate()->get()
                ->filter(fn ($row) => $row->path_hash === $hash || $row->current_path === $path)->values();
        }
        if ($rows->count() > 1) {
            if ($rows->count() !== 2) {
                throw new \RuntimeException('Multiple registry rows claim the exact source path; reviewed source-index reconciliation is required.');
            }
            $canonical = $rows->first(fn ($row) => $row->path_hash === $hash && $row->current_path === $path);
            $legacy = $rows->first(fn ($row) => $canonical && $row->id !== $canonical->id);
            if (! $canonical || ! $legacy || ! $this->resolvedSourceIndexAlias($canonical, $legacy, $lock)) {
                throw new \RuntimeException('Multiple registry rows claim the exact source path; reviewed source-index reconciliation is required.');
            }
            // Historical alias proof does not substitute for today's source fingerprint.
            if (! is_string($canonical->content_hash) || ! hash_equals($canonical->content_hash, $this->reviewedFaceSourceHash($path, $deadline, $localOnly))) {
                throw new \RuntimeException('Canonical alias source bytes require reviewed recovery.');
            }

            return $canonical;
        }
        $row = $rows->first();
        if ($row && ($row->current_path !== $path || $row->path_hash !== $hash)) {
            throw new \RuntimeException('Existing exact source path has a legacy or conflicting index; reviewed reconciliation is required.');
        }

        return $row;
    }

    /** Resolve only an exact, already-reviewed lowercase-path alias while its caller holds row locks. */
    public function canonicalExactSourceIndexAlias(array $lockedRows, string $logicalPath, string $observedSourceSha256): object
    {
        if (count($lockedRows) !== 2 || ! preg_match('/^[a-f0-9]{64}$/D', $observedSourceSha256)) {
            throw new \RuntimeException('Ambiguous source registry; metadata transition requires review');
        }
        $canonical = null;
        $legacy = null;
        foreach ($lockedRows as $row) {
            if (($row->current_path ?? null) !== $logicalPath) {
                throw new \RuntimeException('Ambiguous source registry; metadata transition requires review');
            }
            if (($row->path_hash ?? null) === hash('sha256', $logicalPath)) {
                $canonical = $row;
            } elseif (($row->path_hash ?? null) === hash('sha256', strtolower($logicalPath))) {
                $legacy = $row;
            }
        }
        if (! $canonical || ! $legacy || ! $this->resolvedSourceIndexAlias($canonical, $legacy, true)
            || ! is_string($canonical->content_hash) || ! hash_equals($canonical->content_hash, $observedSourceSha256)) {
            throw new \RuntimeException('Ambiguous source registry; metadata transition requires review');
        }

        return $canonical;
    }

    private function resolvedSourceIndexAlias(object $canonical, object $legacy, bool $lock = false): bool
    {
        if ($canonical->status !== 'active' || $legacy->status !== 'active' || $canonical->current_path !== $legacy->current_path
            || $legacy->path_hash !== hash('sha256', strtolower($legacy->current_path))) {
            return false;
        }
        $relationQuery = DB::table('file_registry_duplicates')->where('canonical_file_id', $canonical->id)
            ->where('duplicate_file_id', $legacy->id);
        $relations = ($lock ? $relationQuery->lockForUpdate() : $relationQuery)->get();
        if ($relations->count() !== 1) {
            return false;
        }
        $relation = $relations->first();
        $notes = json_decode((string) $relation->notes, true);
        if ($relation->status !== 'ignored' || $relation->reviewed_by !== 'human'
            || ($notes['schema'] ?? null) !== 'plos.source-index-alias.v1'
            || ($notes['historical_alias'] ?? null) !== true || ($notes['source_index_only'] ?? null) !== true
            || ($notes['canonical_asset_uuid'] ?? null) !== $canonical->asset_uuid
            || ($notes['legacy_asset_uuid'] ?? null) !== $legacy->asset_uuid
            || ($notes['exact_path_sha256'] ?? null) !== hash('sha256', $canonical->current_path)) {
            return false;
        }
        $receiptQuery = DB::table('review_desk_receipts')->where('id', $notes['receipt_id'] ?? '');
        $receipt = ($lock ? $receiptQuery->lockForUpdate() : $receiptQuery)->first();
        $payload = $receipt ? json_decode($receipt->payload, true) : null;
        if (! $receipt || $receipt->action !== self::SOURCE_INDEX_ACTION || $receipt->state !== 'completed'
            || ($payload['schema'] ?? null) !== 'plos.source-index-reconciliation.v1'
            || ($payload['success'] ?? null) !== true || ($payload['result']['relation_id'] ?? null) !== (int) $relation->id
            || ($payload['request']['canonicalAssetUuid'] ?? null) !== $canonical->asset_uuid
            || ($payload['request']['legacyAssetUuid'] ?? null) !== $legacy->asset_uuid
            || ($payload['request']['expectedSourceSha256'] ?? null) !== $relation->content_hash
            || $receipt->request_digest !== $this->sourceIndexDigest($payload['request'])
            || ($payload['relation_sha256'] ?? null) !== $this->sourceIndexDigest((array) $relation)) {
            return false;
        }
        $legacyFaces = DB::table('file_registry_faces')->where('file_registry_id', $legacy->id);
        if ((clone $legacyFaces)->where('source_current', 1)->exists()) {
            return false;
        }
        $ids = $legacyFaces->pluck('id')->all();

        return ! DB::connection('pgsql_rag')->table('face_embeddings')->where(function ($q) use ($legacy, $ids): void {
            $q->where('file_registry_id', $legacy->id)->orWhereIn('file_registry_face_id', $ids);
        })->where('source_current', true)->exists();
    }

    public function singleSourceIndexPlan(string $assetUuid): array
    {
        try {
            $state = $this->singleSourceIndexSnapshot($assetUuid);
        } catch (\RuntimeException $error) {
            if (! in_array($error->getMessage(), ['source_index_asset_invalid', 'source_index_single_invalid',
                'source_index_single_conflict', 'source_index_current_source_changed', 'source_index_scope_too_large',
                'source_index_foreign_face_or_vector'], true)) {
                throw $error;
            }

            return ['success' => false, 'state' => 'held', 'error_code' => $error->getMessage(),
                'effects_started' => false, 'identity_approval' => false];
        }

        return ['success' => true, 'asset_uuid' => $assetUuid, 'snapshot_sha256' => $this->sourceIndexDigest($state),
            'source_sha256' => $state['observed_source_sha256'], 'old_path_hash' => $state['registry']['path_hash'],
            'new_path_hash' => hash('sha256', $state['registry']['current_path']),
            'historical_content_hash' => $state['registry']['content_hash'], 'identity_approval' => false];
    }

    private function singleSourceIndexSnapshot(string $assetUuid, bool $lock = false, bool $corrected = false): array
    {
        if (! Str::isUuid($assetUuid)) {
            throw new \RuntimeException('source_index_asset_invalid');
        }
        $query = DB::table('file_registry')->where('asset_uuid', $assetUuid);
        $registry = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $registry || $registry->status !== 'active'
            || $this->canonicalReviewedFacePath($registry->current_path) !== $registry->current_path
            || $registry->path_hash !== hash('sha256', $corrected ? $registry->current_path : strtolower($registry->current_path))
            || (! $corrected && $registry->path_hash === hash('sha256', $registry->current_path))) {
            throw new \RuntimeException('source_index_single_invalid');
        }
        $claimants = DB::table('file_registry')->where(function ($q) use ($registry): void {
            $q->where('current_path', $registry->current_path)->orWhere('path_hash', hash('sha256', $registry->current_path));
            if ($registry->nextcloud_fileid !== null) {
                $q->orWhere('nextcloud_fileid', $registry->nextcloud_fileid);
            }
        })->get()->filter(fn ($row) => $row->current_path === $registry->current_path
            || $row->path_hash === hash('sha256', $registry->current_path)
            || ($registry->nextcloud_fileid !== null && (string) $row->nextcloud_fileid === (string) $registry->nextcloud_fileid));
        if ($claimants->count() !== 1 || (int) $claimants->first()->id !== (int) $registry->id) {
            throw new \RuntimeException('source_index_single_conflict');
        }
        $info = $this->nextcloudApi->getFileInfo($registry->current_path, false, true);
        $hash = $this->reviewedFaceSourceHash($registry->current_path);
        if (! ($info['success'] ?? false)
            || ($registry->nextcloud_fileid !== null && (string) ($info['fileid'] ?? '') !== (string) $registry->nextcloud_fileid)) {
            throw new \RuntimeException('source_index_current_source_changed');
        }
        $fileId = $info['fileid'] ?? null;
        if ($fileId !== null && (string) $fileId !== '' && DB::table('file_registry')->where('nextcloud_fileid', $fileId)->where('id', '<>', $registry->id)->exists()) {
            throw new \RuntimeException('source_index_single_conflict');
        }
        $faces = DB::table('file_registry_faces')->where('file_registry_id', $registry->id)->orderBy('id')
            ->limit(config('file_registry.profile_refresh.max_source_faces') + 1);
        $faces = ($lock ? $faces->lockForUpdate() : $faces)->get()->map(fn ($row) => (array) $row)->all();
        $vectors = DB::connection('pgsql_rag')->table('face_embeddings')->where(function ($q) use ($registry, $faces): void {
            $q->where('file_registry_id', $registry->id)->orWhereIn('file_registry_face_id', array_column($faces, 'id'));
        })->orderBy('id')->limit(config('file_registry.profile_refresh.max_source_faces') + 1);
        $vectors = ($lock ? $vectors->lockForUpdate() : $vectors)->get()->map(fn ($row) => (array) $row)->all();
        if (max(count($faces), count($vectors)) > config('file_registry.profile_refresh.max_source_faces')) {
            throw new \RuntimeException('source_index_scope_too_large');
        }
        foreach ($vectors as $vector) {
            if ((int) $vector['file_registry_id'] !== (int) $registry->id) {
                throw new \RuntimeException('source_index_foreign_face_or_vector');
            }
        }
        $relations = DB::table('file_registry_duplicates')->where(function ($q) use ($registry): void {
            $q->where('canonical_file_id', $registry->id)->orWhere('duplicate_file_id', $registry->id);
        })->orderBy('id');
        $relations = ($lock ? $relations->lockForUpdate() : $relations)->get()->map(fn ($row) => (array) $row)->all();

        return ['registry' => (array) $registry, 'faces' => $faces, 'vectors' => $vectors, 'relations' => $relations,
            'observed_source_sha256' => $hash, 'file_info' => array_intersect_key($info, array_flip(['fileid', 'size', 'last_modified', 'etag']))];
    }

    public function correctReviewedSingleSourceIndex(string $assetUuid, string $expectedSnapshotSha256,
        string $expectedSourceSha256, string $idempotencyKey, bool $dryRun = true): array
    {
        $request = compact('assetUuid', 'expectedSnapshotSha256', 'expectedSourceSha256', 'idempotencyKey', 'dryRun');
        if (! Str::isUuid($idempotencyKey) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshotSha256)
            || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSourceSha256)) {
            throw new \RuntimeException('source_index_request_invalid');
        }
        if ($dryRun) {
            $plan = $this->singleSourceIndexPlan($assetUuid);
            if (($plan['success'] ?? false) !== true) {
                return $plan + ['dry_run' => true];
            }
            if ($plan['snapshot_sha256'] !== $expectedSnapshotSha256 || $plan['source_sha256'] !== $expectedSourceSha256) {
                throw new \RuntimeException('source_index_snapshot_changed');
            }

            return $plan + ['dry_run' => true, 'effects_started' => false];
        }
        $caller = $this->sourceIndexCaller($request);
        $digest = $this->sourceIndexDigest($request);
        $old = DB::transaction(function () use ($idempotencyKey, $request, $digest, $caller): ?array {
            $row = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
            if ($row) {
                $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($row->action !== self::SINGLE_SOURCE_INDEX_ACTION || $row->request_digest !== $digest) {
                    throw new \RuntimeException('source_index_receipt_conflict');
                }
                if ($row->state !== 'completed' || ($payload['success'] ?? null) !== true) {
                    throw new \RuntimeException('source_index_outcome_held');
                }

                return $payload['result'] + ['replayed' => true];
            }
            $this->sourceIndexPendingScope([$request['assetUuid']]);
            DB::table('review_desk_receipts')->insert(['id' => $idempotencyKey, 'reference' => $idempotencyKey,
                'idempotency_key' => $idempotencyKey, 'request_digest' => $digest, 'action' => self::SINGLE_SOURCE_INDEX_ACTION,
                'state' => 'processing', 'payload' => json_encode(['schema' => 'plos.single-source-index.v1',
                    'request' => $request, 'caller' => $caller, 'success' => false, 'stage' => 'claimed'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);

            return null;
        });
        if ($old !== null) {
            return $old;
        }
        $backupStarted = false;
        $pg = DB::connection('pgsql_rag');
        try {
            return $pg->transaction(function () use ($pg, $request, $digest, &$backupStarted): array {
                if ($pg->getDriverName() === 'pgsql') {
                    $ms = max(100, min(30000, (int) config('file_registry.face_generation.reconciliation_lock_timeout_ms', 5000)));
                    $pg->statement("SET LOCAL lock_timeout = '{$ms}ms'");
                    $pg->statement('LOCK TABLE face_embeddings IN SHARE ROW EXCLUSIVE MODE');
                }

                return DB::transaction(function () use ($request, $digest, &$backupStarted): array {
                    $state = $this->singleSourceIndexSnapshot($request['assetUuid'], true);
                    if ($this->sourceIndexDigest($state) !== $request['expectedSnapshotSha256'] || $state['observed_source_sha256'] !== $request['expectedSourceSha256']) {
                        throw new \RuntimeException('source_index_snapshot_changed');
                    }
                    $row = DB::table('review_desk_receipts')->where('id', $request['idempotencyKey'])->lockForUpdate()->first();
                    if (! $row || $row->state !== 'processing' || $row->request_digest !== $digest) {
                        throw new \RuntimeException('source_index_receipt_changed');
                    }
                    $backupStarted = true;
                    $backup = $this->sourceIndexBackup($row->id, $state);
                    if ($this->sourceIndexDigest($this->singleSourceIndexSnapshot($request['assetUuid'], true)) !== $request['expectedSnapshotSha256']
                        || ! hash_equals($backup['sha256'], (string) hash_file('sha256', $backup['path']))) {
                        throw new \RuntimeException('source_index_snapshot_changed');
                    }
                    $timestamp = (string) DB::selectOne('SELECT CURRENT_TIMESTAMP AS stamp')->stamp;
                    $newHash = hash('sha256', $state['registry']['current_path']);
                    $update = ['path_hash' => $newHash];
                    if (array_key_exists('updated_at', $state['registry'])) {
                        // Pin the SQL-derived audit stamp explicitly so a second rollover
                        // cannot make MySQL's implicit stamp fail exact readback.
                        $update['updated_at'] = $timestamp;
                    }
                    if (DB::table('file_registry')->where('id', $state['registry']['id'])->where('path_hash', $state['registry']['path_hash'])
                        ->update($update) !== 1) {
                        throw new \RuntimeException('source_index_single_update_held');
                    }
                    $after = $this->singleSourceIndexSnapshot($request['assetUuid'], true, true);
                    $expected = $state;
                    $expected['registry']['path_hash'] = $newHash;
                    if (array_key_exists('updated_at', $expected['registry']) && $after['registry']['updated_at'] === $timestamp) {
                        $expected['registry']['updated_at'] = $timestamp;
                    }
                    if ($expected !== $after) {
                        throw new \RuntimeException('source_index_history_changed');
                    }
                    $outcome = ['success' => true, 'receipt_id' => $row->id, 'asset_uuid' => $request['assetUuid'],
                        'old_path_hash' => $state['registry']['path_hash'], 'new_path_hash' => $newHash,
                        'source_sha256' => $request['expectedSourceSha256'], 'historical_content_hash' => $state['registry']['content_hash'],
                        'registry_updated_at' => $after['registry']['updated_at'] ?? null,
                        'backup_sha256' => $backup['sha256'], 'identity_approval' => false];
                    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                    $payload['stage'] = 'complete';
                    $payload['success'] = true;
                    $payload['backup'] = $backup;
                    $payload['result'] = $outcome;
                    $payload['after_snapshot_sha256'] = $this->sourceIndexDigest($after);
                    DB::table('review_desk_receipts')->where('id', $row->id)->update(['state' => 'completed',
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

                    return $outcome;
                });
            });
        } catch (\Throwable $error) {
            if (! $backupStarted && $error instanceof \RuntimeException && in_array($error->getMessage(), [
                'source_index_asset_invalid', 'source_index_single_invalid', 'source_index_single_conflict',
                'source_index_current_source_changed', 'source_index_scope_too_large', 'source_index_foreign_face_or_vector', 'source_index_snapshot_changed'], true)) {
                return $this->sourceIndexKnownHold($idempotencyKey, $digest, $error->getMessage());
            }
            throw $error;
        }
    }

    private function sourceIndexCaller(array $request): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        if (! is_array($caller) || array_diff(array_keys($caller), ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256']) !== []
            || count($caller) !== 5 || ($caller['arguments_sha256'] ?? null) !== $this->sourceIndexDigest($request)) {
            throw new \RuntimeException('source_index_trusted_cli_required');
        }
        foreach ($caller as $key => $value) {
            if (! is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/[\x00-\x20\x7f]/', $value)
                || (str_ends_with($key, 'sha256') && ! preg_match('/^[a-f0-9]{64}$/D', $value))) {
                throw new \RuntimeException('source_index_trusted_cli_required');
            }
        }

        return $caller;
    }

    private function sourceIndexPendingScope(array $assets): void
    {
        $pending = DB::table('review_desk_receipts')->whereIn('action', [self::SOURCE_INDEX_ACTION, self::SINGLE_SOURCE_INDEX_ACTION])
            ->where('state', 'processing')->lockForUpdate()->get();
        foreach ($pending as $row) {
            $prior = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['request'] ?? null;
            if (! is_array($prior) || array_intersect($assets,
                [$prior['assetUuid'] ?? null, $prior['canonicalAssetUuid'] ?? null, $prior['legacyAssetUuid'] ?? null]) !== []) {
                throw new \RuntimeException('source_index_scope_outcome_held');
            }
        }
    }

    private function sourceIndexKnownHold(string $idempotencyKey, string $digest, string $code): array
    {
        $held = ['success' => false, 'status' => 'held', 'failure_code' => $code,
            'effect_status' => 'not_started', 'identity_approval' => false, 'receipt_id' => $idempotencyKey];
        DB::transaction(function () use ($idempotencyKey, $digest, $held): void {
            $row = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
            if (! $row || $row->state !== 'processing' || $row->request_digest !== $digest) {
                throw new \RuntimeException('source_index_receipt_changed');
            }
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $payload['stage'] = 'not_started';
            $payload['result'] = $held;
            DB::table('review_desk_receipts')->where('id', $row->id)->update(['state' => 'failed',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });

        return $held;
    }

    public function sourceIndexReconciliationPlan(string $canonicalAssetUuid, string $legacyAssetUuid): array
    {
        $state = $this->sourceIndexSnapshot($canonicalAssetUuid, $legacyAssetUuid);

        return $this->sourceIndexPlan($state);
    }

    private function sourceIndexPlan(array $state): array
    {
        return ['success' => true, 'snapshot_sha256' => $this->sourceIndexDigest($state),
            'source_sha256' => $state['observed_source_sha256'], 'physical_files' => 1, 'retained_registries' => 2,
            'legacy_original_bytes' => 'unknown', 'identity_approval' => false,
            'faces_to_retire' => array_values(array_map(fn ($row) => (int) $row['id'], array_filter($state['faces'],
                fn ($row) => (int) $row['file_registry_id'] === (int) $state['legacy']['id'] && ! empty($row['source_current'])))),
            'vectors_to_retire' => array_values(array_map(fn ($row) => (int) $row['id'], array_filter($state['vectors'],
                fn ($row) => (int) $row['file_registry_id'] === (int) $state['legacy']['id'] && ! empty($row['source_current']))))];
    }

    private function sourceIndexSnapshot(string $canonicalAssetUuid, string $legacyAssetUuid, bool $lock = false): array
    {
        if (! Str::isUuid($canonicalAssetUuid) || ! Str::isUuid($legacyAssetUuid) || $canonicalAssetUuid === $legacyAssetUuid) {
            throw new \RuntimeException('source_index_asset_invalid');
        }
        $query = DB::table('file_registry')->whereIn('asset_uuid', [$canonicalAssetUuid, $legacyAssetUuid])->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        $canonical = $rows->firstWhere('asset_uuid', $canonicalAssetUuid);
        $legacy = $rows->firstWhere('asset_uuid', $legacyAssetUuid);
        if ($rows->count() !== 2 || ! $canonical || ! $legacy || $canonical->status !== 'active' || $legacy->status !== 'active'
            || $canonical->current_path !== $legacy->current_path
            || $this->canonicalReviewedFacePath($canonical->current_path) !== $canonical->current_path
            || $canonical->path_hash !== hash('sha256', $canonical->current_path)
            || $legacy->path_hash !== hash('sha256', strtolower($legacy->current_path))
            || $legacy->path_hash === $canonical->path_hash
            || ($legacy->nextcloud_fileid !== null && $canonical->nextcloud_fileid !== null
                && (string) $legacy->nextcloud_fileid !== (string) $canonical->nextcloud_fileid)) {
            throw new \RuntimeException('source_index_pair_invalid');
        }
        $claimants = DB::table('file_registry')->where('current_path', $canonical->current_path)->orderBy('id');
        $claimants = $claimants->get()
            ->filter(fn ($row) => $row->current_path === $canonical->current_path)->values();
        if ($claimants->count() !== 2 || $claimants->pluck('id')->sort()->values()->all() !== $rows->pluck('id')->sort()->values()->all()) {
            throw new \RuntimeException('source_index_foreign_claimant');
        }
        $info = $this->nextcloudApi->getFileInfo($canonical->current_path, false, true);
        $hash = $this->reviewedFaceSourceHash($canonical->current_path);
        if (! ($info['success'] ?? false) || ! is_string($canonical->content_hash) || $canonical->content_hash !== $hash
            || ($canonical->nextcloud_fileid !== null && (string) ($info['fileid'] ?? '') !== (string) $canonical->nextcloud_fileid)) {
            throw new \RuntimeException('source_index_current_source_changed');
        }
        $faces = DB::table('file_registry_faces')->whereIn('file_registry_id', $rows->pluck('id')->all())->orderBy('id');
        $faces->limit(config('file_registry.profile_refresh.max_source_faces') + 1);
        $faces = ($lock ? $faces->lockForUpdate() : $faces)->get()->map(fn ($row) => (array) $row)->all();
        $vectors = DB::connection('pgsql_rag')->table('face_embeddings')->where(function ($q) use ($rows, $faces): void {
            $q->whereIn('file_registry_id', $rows->pluck('id')->all())->orWhereIn('file_registry_face_id', array_column($faces, 'id'));
        })->orderBy('id');
        $vectors->limit(config('file_registry.profile_refresh.max_source_faces') + 1);
        $vectors = ($lock ? $vectors->lockForUpdate() : $vectors)->get()->map(fn ($row) => (array) $row)->all();
        if (max(count($faces), count($vectors)) > config('file_registry.profile_refresh.max_source_faces')) {
            throw new \RuntimeException('source_index_scope_too_large');
        }
        $legacyCurrentFaceIds = array_values(array_map('intval', array_column(array_filter($faces, fn ($face) => (int) $face['file_registry_id'] === (int) $legacy->id && ! empty($face['source_current'])), 'id')));
        $faceReviews = [];
        if ($legacyCurrentFaceIds !== []) {
            $reviewQuery = DB::table('genealogy_face_match_queue')->whereIn('file_registry_face_id', $legacyCurrentFaceIds)->orderBy('id')->limit(1);
            $faceReviews = ($lock ? $reviewQuery->lockForUpdate() : $reviewQuery)->get()->map(fn ($row) => (array) $row)->all();
        }
        foreach (array_merge($faces, $vectors) as $row) {
            if (! in_array((int) $row['file_registry_id'], [(int) $canonical->id, (int) $legacy->id], true)) {
                throw new \RuntimeException('source_index_foreign_face_or_vector');
            }
            if (empty($row['source_current'])) {
                continue;
            }
            if (! empty($row['superseded_at'])) {
                throw new \RuntimeException('source_index_current_marker_ambiguous');
            }
            $source = $row['source_sha256'] ?? null;
            if ((int) $row['file_registry_id'] === (int) $canonical->id && $source !== $hash) {
                throw new \RuntimeException('source_index_canonical_face_unproven');
            }
            if ((int) $row['file_registry_id'] === (int) $legacy->id && array_key_exists('id', $row)
                && (array_key_exists('genealogy_person_id', $row) && $row['genealogy_person_id'] !== null
                    || trim((string) ($row['person_name'] ?? '')) !== ''
                    || ! empty($row['verified'])
                    || ($row['source'] ?? null) === 'manual'
                    || ! empty($row['hidden'])
                    || ! empty($row['favorite'])
                    || ! empty($row['global_identity_uuid'])
                    || ! empty($row['global_identity_approval_receipt_id'])
                    || ! empty($row['cluster_id'])
                    || ! empty($row['person_cluster_id']))) {
                throw new \RuntimeException('source_index_legacy_current_protected');
            }
            if ((int) $row['file_registry_id'] === (int) $legacy->id && ($source === $hash
                || ($source !== null && (! is_string($source) || ! preg_match('/^[a-f0-9]{64}$/D', $source))))) {
                throw new \RuntimeException('source_index_legacy_current_protected');
            }
        }
        if ($faceReviews !== []) {
            throw new \RuntimeException('source_index_legacy_current_protected');
        }
        $relations = DB::table('file_registry_duplicates')->where(function ($q) use ($canonical, $legacy): void {
            $q->whereIn('canonical_file_id', [$canonical->id, $legacy->id])->orWhereIn('duplicate_file_id', [$canonical->id, $legacy->id]);
        })->orderBy('id');
        $relations = ($lock ? $relations->lockForUpdate() : $relations)->get()->map(fn ($row) => (array) $row)->all();
        foreach ($relations as $relation) {
            $endpoints = [(int) $relation['canonical_file_id'], (int) $relation['duplicate_file_id']];
            // Preserve untouched generic duplicate hints to another physical path.
            // Any decision, annotation or relation between this pair remains protected.
            if (count(array_intersect($endpoints, [(int) $canonical->id, (int) $legacy->id])) !== 1
                || min($endpoints) <= 0 || $endpoints[0] === $endpoints[1]
                || $relation['status'] !== 'pending_review' || $relation['reviewed_by'] !== null
                || $relation['reviewed_at'] !== null || $relation['notes'] !== null
                || $relation['content_hash'] !== $hash) {
                throw new \RuntimeException('source_index_existing_relation_protected');
            }
        }

        $state = ['canonical' => (array) $canonical, 'legacy' => (array) $legacy, 'faces' => $faces,
            'vectors' => $vectors, 'face_reviews' => $faceReviews, 'relations' => $relations, 'observed_source_sha256' => $hash,
            'file_info' => array_intersect_key($info, array_flip(['fileid', 'size', 'last_modified', 'etag']))];
        $this->sourceIndexAssertRetirementReferences($state, $lock);

        return $state;
    }

    /** Original exact retiring IDs remain authoritative after source_current is changed. */
    private function sourceIndexAssertRetirementReferences(array $state, bool $lock): void
    {
        $ids = fn ($rows) => array_values(array_map(fn ($row) => (int) $row['id'], array_filter($rows,
            fn ($row) => (int) $row['file_registry_id'] === (int) $state['legacy']['id'] && ! empty($row['source_current']))));
        try {
            app(FaceGenerationReconciliationService::class)->assertSourceIndexRetirementReferences(
                (int) $state['legacy']['id'], $ids($state['faces']), $ids($state['vectors']), $lock);
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException && $error->getMessage() === 'reconciliation_referenced_face') {
                throw new \RuntimeException('source_index_legacy_current_protected', 0, $error);
            }
            throw new \RuntimeException('source_index_reference_evidence_unavailable', 0, $error);
        }
    }

    public function reconcileReviewedSourceIndex(string $canonicalAssetUuid, string $legacyAssetUuid,
        string $expectedSnapshotSha256, string $expectedSourceSha256, string $idempotencyKey, bool $dryRun = true): array
    {
        $request = compact('canonicalAssetUuid', 'legacyAssetUuid', 'expectedSnapshotSha256', 'expectedSourceSha256', 'idempotencyKey', 'dryRun');
        if (! Str::isUuid($idempotencyKey) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshotSha256)
            || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSourceSha256)) {
            throw new \RuntimeException('source_index_request_invalid');
        }
        if ($dryRun) {
            $plan = $this->sourceIndexReconciliationPlan($canonicalAssetUuid, $legacyAssetUuid);
            if ($plan['snapshot_sha256'] !== $expectedSnapshotSha256 || $plan['source_sha256'] !== $expectedSourceSha256) {
                throw new \RuntimeException('source_index_snapshot_changed');
            }

            return $plan + ['dry_run' => true, 'effects_started' => false];
        }
        $caller = $this->sourceIndexCaller($request);
        $digest = $this->sourceIndexDigest($request);
        $old = DB::transaction(function () use ($idempotencyKey, $request, $digest, $caller): ?array {
            $row = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
            if ($row) {
                $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($row->action !== self::SOURCE_INDEX_ACTION || $row->request_digest !== $digest) {
                    throw new \RuntimeException('source_index_receipt_conflict');
                }
                if ($row->state !== 'completed' || ($payload['success'] ?? null) !== true) {
                    throw new \RuntimeException('source_index_outcome_held');
                }

                return $payload['result'] + ['replayed' => true];
            }
            $this->sourceIndexPendingScope([$request['canonicalAssetUuid'], $request['legacyAssetUuid']]);
            DB::table('review_desk_receipts')->insert(['id' => $idempotencyKey, 'reference' => $idempotencyKey,
                'idempotency_key' => $idempotencyKey, 'request_digest' => $digest, 'action' => self::SOURCE_INDEX_ACTION,
                'state' => 'processing', 'payload' => json_encode(['schema' => 'plos.source-index-reconciliation.v1',
                    'request' => $request, 'caller' => $caller, 'success' => false, 'stage' => 'claimed'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);

            return null;
        });
        if ($old !== null) {
            return $old;
        }
        $pg = DB::connection('pgsql_rag');
        $backupStarted = false;
        try {
            $result = $pg->transaction(function () use ($pg, $request, $digest, &$backupStarted): array {
                app(FaceGenerationReconciliationService::class)->lockPostgresqlReferences($pg);

                return DB::transaction(function () use ($request, $digest, &$backupStarted): array {
                    $state = $this->sourceIndexSnapshot($request['canonicalAssetUuid'], $request['legacyAssetUuid'], true);
                    if ($this->sourceIndexDigest($state) !== $request['expectedSnapshotSha256']
                        || $state['observed_source_sha256'] !== $request['expectedSourceSha256']) {
                        throw new \RuntimeException('source_index_snapshot_changed');
                    }
                    $row = DB::table('review_desk_receipts')->where('id', $request['idempotencyKey'])->lockForUpdate()->first();
                    if (! $row || $row->state !== 'processing' || $row->request_digest !== $digest) {
                        throw new \RuntimeException('source_index_receipt_changed');
                    }
                    $backupStarted = true;
                    $backup = $this->sourceIndexBackup($request['idempotencyKey'], $state);
                    if ($this->sourceIndexDigest($this->sourceIndexSnapshot($request['canonicalAssetUuid'], $request['legacyAssetUuid'], true)) !== $request['expectedSnapshotSha256']) {
                        throw new \RuntimeException('source_index_snapshot_changed');
                    }
                    if (! hash_equals($backup['sha256'], (string) hash_file('sha256', $backup['path']))) {
                        throw new \RuntimeException('source_index_backup_changed');
                    }
                    $retired = $this->getLifecycleService()->supersedeFaceGeneration((int) $state['legacy']['id'],
                        $state['legacy']['asset_uuid'], $request['expectedSourceSha256'], true);
                    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                    $payload['stage'] = 'mysql_retired_pg_commit_pending';
                    $payload['backup'] = $backup;
                    $payload['retirement'] = $retired;
                    DB::table('review_desk_receipts')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

                    return ['state' => $state, 'backup' => $backup, 'retirement' => $retired];
                });
            });

        } catch (\Throwable $error) {
            $known = ['source_index_asset_invalid', 'source_index_pair_invalid', 'source_index_foreign_claimant',
                'source_index_current_source_changed', 'source_index_current_marker_ambiguous', 'source_index_scope_too_large',
                'source_index_canonical_face_unproven', 'source_index_legacy_current_protected',
                'source_index_existing_relation_protected', 'source_index_snapshot_changed'];
            if (! $backupStarted && $error instanceof \RuntimeException && in_array($error->getMessage(), $known, true)) {
                return $this->sourceIndexKnownHold($idempotencyKey, $digest, $error->getMessage());
            }
            throw $error;
        }

        // PostgreSQL commit has returned. Publication requires exact readback of both stores.
        return $pg->transaction(function () use ($pg, $request, $digest, $result): array {
            app(FaceGenerationReconciliationService::class)->lockPostgresqlReferences($pg);

            return DB::transaction(function () use ($request, $digest, $result): array {
                $canonical = DB::table('file_registry')->where('asset_uuid', $request['canonicalAssetUuid'])->lockForUpdate()->first();
                $legacy = DB::table('file_registry')->where('asset_uuid', $request['legacyAssetUuid'])->lockForUpdate()->first();
                if (! $canonical || ! $legacy || (array) $canonical !== $result['state']['canonical'] || (array) $legacy !== $result['state']['legacy']
                    || $this->reviewedFaceSourceHash($canonical->current_path) !== $request['expectedSourceSha256']) {
                    throw new \RuntimeException('source_index_final_source_changed');
                }
                $this->sourceIndexRetirementReadback($result['state']);
                $this->sourceIndexAssertRetirementReferences($result['state'], true);
                $row = DB::table('review_desk_receipts')->where('id', $request['idempotencyKey'])->lockForUpdate()->first();
                if (! $row || $row->state !== 'processing' || $row->request_digest !== $digest) {
                    throw new \RuntimeException('source_index_receipt_changed');
                }
                $relations = DB::table('file_registry_duplicates')->where(function ($q) use ($canonical, $legacy): void {
                    $q->whereIn('canonical_file_id', [$canonical->id, $legacy->id])->orWhereIn('duplicate_file_id', [$canonical->id, $legacy->id]);
                })->orderBy('id')->lockForUpdate()->get()->map(fn ($relation) => (array) $relation)->all();
                if ($relations !== $result['state']['relations']) {
                    throw new \RuntimeException('source_index_external_relation_changed');
                }
                $notes = ['schema' => 'plos.source-index-alias.v1', 'historical_alias' => true, 'source_index_only' => true,
                    'physical_files' => 1, 'retained_registries' => 2, 'legacy_original_bytes' => 'unknown',
                    'legacy_original_content_hash' => $legacy->content_hash, 'observed_current_source_sha256' => $request['expectedSourceSha256'],
                    'canonical_asset_uuid' => $canonical->asset_uuid, 'legacy_asset_uuid' => $legacy->asset_uuid,
                    'exact_path_sha256' => $canonical->path_hash, 'receipt_id' => $row->id];
                $relationId = DB::table('file_registry_duplicates')->insertGetId(['content_hash' => $request['expectedSourceSha256'],
                    'canonical_file_id' => $canonical->id, 'duplicate_file_id' => $legacy->id, 'status' => 'ignored',
                    'reviewed_by' => 'human', 'reviewed_at' => now(), 'notes' => json_encode($notes, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                $relation = DB::table('file_registry_duplicates')->where('id', $relationId)->first();
                $outcome = ['success' => true, 'receipt_id' => $row->id, 'relation_id' => $relationId,
                    'canonical_asset_uuid' => $canonical->asset_uuid, 'legacy_asset_uuid' => $legacy->asset_uuid,
                    'physical_files' => 1, 'retained_registries' => 2, 'legacy_original_bytes' => 'unknown', 'identity_approval' => false,
                    'backup_sha256' => $result['backup']['sha256'], 'retirement' => $result['retirement']];
                $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['stage'] = 'complete';
                $payload['success'] = true;
                $payload['result'] = $outcome;
                $payload['relation_sha256'] = $this->sourceIndexDigest((array) $relation);
                DB::table('review_desk_receipts')->where('id', $row->id)->update(['state' => 'completed', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

                return $outcome;
            });
        });
    }

    protected function sourceIndexRetirementReadback(array $state): void
    {
        foreach (['faces' => DB::connection(), 'vectors' => DB::connection('pgsql_rag')] as $key => $connection) {
            $table = $key === 'faces' ? 'file_registry_faces' : 'face_embeddings';
            $current = $connection->table($table)->whereIn('file_registry_id', [$state['canonical']['id'], $state['legacy']['id']])->orderBy('id')->get();
            $expected = $state[$key];
            if ($current->count() !== count($expected)) {
                throw new \RuntimeException('source_index_retirement_readback_changed');
            }
            foreach ($current as $i => $row) {
                $before = $expected[$i];
                $after = (array) $row;
                $retired = (int) $before['file_registry_id'] === (int) $state['legacy']['id'] && ! empty($before['source_current']);
                if ($retired && (! empty($after['source_current']) || empty($after['superseded_at']))) {
                    throw new \RuntimeException('source_index_cross_store_outcome_held');
                }
                if ($retired) {
                    // MySQL may stamp updated_at implicitly on this marker-only update.
                    // Accept only the exact retirement timestamp; PG history stays strict.
                    if ($key === 'faces' && ($before['superseded_at'] ?? null) === null
                        && array_key_exists('updated_at', $before) && array_key_exists('updated_at', $after)
                        && $after['updated_at'] === $after['superseded_at']) {
                        $before['updated_at'] = $after['updated_at'];
                    }
                    unset($before['source_current'], $before['superseded_at'], $after['source_current'], $after['superseded_at']);
                }
                if ($before !== $after) {
                    throw new \RuntimeException('source_index_history_changed');
                }
            }
        }
    }

    protected function sourceIndexBackup(string $id, array $state): array
    {
        $relative = config('file_registry.source_index.private_directory');
        if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)) {
            throw new \RuntimeException('source_index_backup_unavailable');
        }
        $directory = storage_path();
        foreach (explode('/', $relative) as $part) {
            $directory .= '/'.$part;
            if (is_link($directory)) {
                throw new \RuntimeException('source_index_backup_unavailable');
            }
        }
        if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || ! chmod($directory, 0700)) {
            throw new \RuntimeException('source_index_backup_unavailable');
        }
        $bytes = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($bytes) > config('file_registry.profile_refresh.max_backup_bytes')) {
            throw new \RuntimeException('source_index_backup_too_large');
        }
        $path = $directory.'/'.$id.'.json';
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('source_index_backup_unavailable');
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new \RuntimeException('source_index_backup_unavailable');
            }
        } finally {
            fclose($handle);
        }
        $hash = hash('sha256', $bytes);
        if (hash_file('sha256', $path) !== $hash || (fileperms($path) & 0777) !== 0600) {
            throw new \RuntimeException('source_index_backup_unavailable');
        }

        return ['path' => $path, 'sha256' => $hash];
    }

    private function sourceIndexDigest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function reviewedFaceSourceHash(string $path, ?float $deadline = null, bool $localOnly = false): string
    {
        $result = $localOnly ? $this->nextcloudApi->computeFileHash($path, $deadline, true) : $this->nextcloudApi->computeFileHash($path);
        $hash = $result['hash'] ?? null;
        if (! ($result['success'] ?? false) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new \RuntimeException('Current source hash is unavailable.');
        }

        return $hash;
    }

    private function canonicalReviewedFacePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            return null;
        }
        $canonical = '/'.ltrim($path, '/');

        return $canonical === '/' ? null : $canonical;
    }

    private function reviewedFaceRegion(mixed $region): ?array
    {
        if (! is_array($region)) {
            return null;
        }
        $normalized = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($region[$axis] ?? null) || ! is_finite((float) $region[$axis])) {
                return null;
            }
            $normalized[$axis] = (float) $region[$axis];
        }
        if ($normalized['x'] < 0 || $normalized['y'] < 0 || $normalized['w'] <= 0 || $normalized['h'] <= 0
            || $normalized['x'] + $normalized['w'] > 1 || $normalized['y'] + $normalized['h'] > 1) {
            return null;
        }

        return $normalized;
    }

    private function sameReviewedFaceRegion(array $expected, mixed $actual): bool
    {
        $actual = $this->reviewedFaceRegion($actual);
        if ($actual === null) {
            return false;
        }
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (abs($expected[$axis] - $actual[$axis]) > 0.00000001) {
                return false;
            }
        }

        return true;
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
            AIAutoTagJob::dispatch((int) $file->id, $forceRefresh);
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
                    if (! $this->updateFilePath($assetUuid, $searchResult['path'], 'nextcloud_sync')) {
                        return [
                            'success' => false,
                            'asset_uuid' => $assetUuid,
                            'error' => 'Found source path requires reviewed registry recovery',
                            'review_required' => true,
                            'path_updated' => false,
                            'recovered_path' => $searchResult['path'],
                        ];
                    }

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
                'marked_orphaned' => true,
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

    /** Recover or tombstone a bounded set, retaining lifecycle evidence at every threshold. */
    public function cleanupOrphanedFiles(int $failureThreshold = 1, int $limit = 100): array
    {
        $stats = ['checked' => 0, 'recovered' => 0, 'deleted' => 0, 'still_orphaned' => 0];
        if ($failureThreshold < 1 || $limit < 1) {
            return $stats;
        }
        $orphaned = DB::select("SELECT asset_uuid, current_path, nextcloud_fileid, status, verification_failures, thumbnail_error
            FROM file_registry WHERE status = 'orphaned' AND verification_failures >= ?
            ORDER BY verification_failures DESC, updated_at ASC, id ASC LIMIT ?", [$failureThreshold, $limit]);
        $remaining = max(0, $limit - count($orphaned));
        $notFound = $remaining === 0 ? [] : DB::select("SELECT asset_uuid, current_path, nextcloud_fileid, status, verification_failures, thumbnail_error
            FROM file_registry WHERE status = 'active' AND (
                thumbnail_error LIKE '%404%' OR thumbnail_error LIKE '%not found%'
                OR thumbnail_error LIKE '%no such file%' OR thumbnail_error LIKE '%Failed to download source file%')
            ORDER BY id ASC LIMIT ?", [$remaining]);
        foreach (array_merge($orphaned, $notFound) as $candidate) {
            $stats['checked']++;
            try {
                $outcome = DB::transaction(function () use ($candidate): string {
                    $current = DB::selectOne('SELECT asset_uuid, current_path, nextcloud_fileid, status, verification_failures, thumbnail_error
                        FROM file_registry WHERE asset_uuid = ? FOR UPDATE', [$candidate->asset_uuid]);
                    if (! $current) {
                        return 'still_orphaned';
                    }
                    foreach (['current_path', 'nextcloud_fileid', 'status', 'verification_failures', 'thumbnail_error'] as $field) {
                        if (($current->{$field} ?? null) !== ($candidate->{$field} ?? null)) {
                            return 'still_orphaned';
                        }
                    }
                    $path = $current->current_path;
                    $proof = $this->cleanupSourcePresence($path);
                    if ($proof === null) {
                        return 'still_orphaned';
                    }
                    if (! $proof && $current->nextcloud_fileid) {
                        $found = $this->nextcloudApi->findFileByFileid((int) $current->nextcloud_fileid);
                        if (($found['success'] ?? false) !== true || ! is_string($found['path'] ?? null)
                            || $this->cleanupSourcePresence($found['path'], (int) $current->nextcloud_fileid) !== true) {
                            // A failed fileid lookup cannot prove that a moved physical file is gone.
                            return 'still_orphaned';
                        }
                        $path = '/'.ltrim($found['path'], '/');
                        if (! $this->updateFilePath($current->asset_uuid, $path, 'self_healing', 'Recovered via fileid during cleanup')) {
                            return 'still_orphaned';
                        }
                        $proof = true;
                    }
                    if ($proof) {
                        $affected = DB::update("UPDATE file_registry SET status = 'active', verification_failures = 0,
                            thumbnail_generated_at = CASE WHEN thumbnail_error IS NULL THEN thumbnail_generated_at ELSE NULL END, thumbnail_error = NULL,
                            last_verified_at = NOW(), updated_at = NOW()
                            WHERE asset_uuid = ? AND status = ? AND current_path = ?", [$current->asset_uuid, $current->status, $path]);

                        if ($affected < 1) {
                            throw new \RuntimeException('Cleanup recovery was not applied');
                        }

                        return 'recovered';
                    }

                    // The candidate row stays locked while the existing lifecycle saves restore state and cascades.
                    return $this->markAsDeleted($current->asset_uuid, 'Confirmed missing during guarded registry cleanup')
                        ? 'deleted' : 'still_orphaned';
                });
            } catch (\Throwable $e) {
                $outcome = 'still_orphaned';
                Log::warning('FileRegistry: Cleanup source proof held', ['asset_uuid' => $candidate->asset_uuid, 'exception' => $e::class]);
            }
            $stats[$outcome]++;
        }
        Log::info('FileRegistry: Self-healing cleanup completed', $stats);

        return $stats;
    }

    /** True means a validated present file; false is an explicit adapter 404; null is unavailable proof. */
    private function cleanupSourcePresence(mixed $path, ?int $expectedFileId = null): ?bool
    {
        if (! is_string($path) || trim($path) === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            return null;
        }
        $normalized = '/'.ltrim($path, '/');
        $info = $this->nextcloudApi->getFileInfo($normalized);
        if (($info['success'] ?? false) !== true) {
            return ($info['error'] ?? null) === 'PROPFIND failed: HTTP 404' ? false : null;
        }
        if (($info['path'] ?? null) !== $normalized || filter_var($info['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
            || in_array($info['mime_type'] ?? null, ['httpd/unix-directory', 'inode/directory'], true)
            || ($expectedFileId !== null && isset($info['fileid']) && (int) $info['fileid'] !== $expectedFileId)) {
            return null;
        }
        if (($info['source'] ?? null) === 'filesystem') {
            $local = $this->nextcloudApi->localPath($normalized);
            $root = realpath((string) config('services.nextcloud.data_path'));
            $actual = is_string($local) ? realpath($local) : false;
            if (! $root || ! $actual || ! str_starts_with($actual, $root.DIRECTORY_SEPARATOR) || ! is_file($actual) || ! is_readable($actual)) {
                return null;
            }
        }

        return $this->nextcloudApi->fileExists($normalized) ? true : null;
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
                SUM(CASE WHEN verification_failures >= 1 THEN 1 ELSE 0 END) as ready_for_deletion
            FROM file_registry
            WHERE status = 'orphaned'
        ");

        $errorCounts = DB::selectOne("
            SELECT
                COUNT(*) as total_errors,
                SUM(CASE
                    WHEN thumbnail_error LIKE '%404%'
                      OR thumbnail_error LIKE '%not found%'
                      OR thumbnail_error LIKE '%no such file%'
                      OR thumbnail_error LIKE '%Failed to download source file%'
                    THEN 1 ELSE 0
                END) as not_found_errors
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

    /** Internal producer preimage; this does not verify the provenance of supplied/cached text. */
    public function currentSourcePublicationProof(object $file): array
    {
        $expected = (array) $file;
        unset($expected['content_extraction_metadata']);
        $row = DB::table('file_registry')->where('id', $file->id)->first();
        if (! $row || (array) $row !== $expected || $row->status !== 'active') {
            throw new \RuntimeException('projection_catalog_changed');
        }
        $seconds = config('file_registry.current_source_hash.max_seconds');
        if (! is_int($seconds) || $seconds < 1 || $seconds > 120) {
            throw new \RuntimeException('projection_source_policy_unavailable');
        }
        $physical = $this->nextcloudApi->computeFileHash($row->current_path, hrtime(true) / 1e9 + $seconds, true);
        if (! ($physical['success'] ?? false) || ! is_array($physical['source_fingerprint'] ?? null)
            || ! is_string($physical['hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $physical['hash'])
            || (! empty($row->content_hash) && ! hash_equals($row->content_hash, $physical['hash']))) {
            throw new \RuntimeException('projection_source_unavailable_or_changed');
        }

        return ['registry' => $expected, 'source_sha256' => $physical['hash'], 'source_fingerprint' => $physical['source_fingerprint']];
    }

    /** Internal final publication only: caller prepares embeddings before entering custody. */
    public function publishCurrentSourceProjection(array $proof, callable $publish, callable $complete): mixed
    {
        // A caller-owned PG transaction could commit after registry custody ends.
        if (DB::connection('pgsql_rag')->transactionLevel() !== 0) {
            throw new \RuntimeException('projection_transaction_order_invalid');
        }

        return DB::transaction(function () use ($proof, $publish, $complete): mixed {
            $row = DB::table('file_registry')->where('id', $proof['registry']['id'])->lockForUpdate()->first();
            if (! $row || (array) $row !== $proof['registry'] || $row->status !== 'active') {
                throw new \RuntimeException('projection_catalog_changed');
            }
            $seconds = config('file_registry.current_source_hash.max_seconds');
            if (! is_int($seconds) || $seconds < 1 || $seconds > 120) {
                throw new \RuntimeException('projection_source_policy_unavailable');
            }
            $deadline = hrtime(true) / 1e9 + $seconds;
            $check = function () use ($row, $proof, $deadline): void {
                $fresh = $this->nextcloudApi->computeFileHash($row->current_path, $deadline, true);
                if (! ($fresh['success'] ?? false) || ($fresh['hash'] ?? null) !== $proof['source_sha256']
                    || ($fresh['source_fingerprint'] ?? null) !== $proof['source_fingerprint'] || hrtime(true) / 1e9 >= $deadline) {
                    throw new \RuntimeException('projection_source_unavailable_or_changed');
                }
            };
            $check();

            return DB::connection('pgsql_rag')->transaction(function () use ($publish, $complete, $check): mixed {
                $result = $publish();
                if ($result === null || $result === false) {
                    throw new \RuntimeException('projection_publication_failed');
                }
                $check();
                $complete();
                $check();

                return $result;
            });
        });
    }

    /** Current-byte baseline only. Never backfills the provenance of existing projections. */
    public function repairCurrentSourceHash(int $id, ?string $expectedProof = null, ?string $receiptId = null): array
    {
        $held = ['success' => false, 'schema' => 'plos.current_source_hash.v1', 'status' => 'held',
            'source_id' => $id, 'fileid_reverified' => false, 'historical_hashes_backfilled' => false, 'derivatives_changed' => false];
        try {
            $seconds = config('file_registry.current_source_hash.max_seconds');
            if ($id < 1 || ! is_int($seconds) || $seconds < 1 || $seconds > 120
                || (($expectedProof === null) !== ($receiptId === null))
                || ($expectedProof !== null && (! preg_match('/^[a-f0-9]{64}$/D', $expectedProof) || ! Str::isUuid($receiptId)))) {
                throw new \RuntimeException('hash_request_invalid');
            }
            $deadline = hrtime(true) / 1e9 + $seconds;
            $before = DB::table('file_registry')->where('id', $id)->first();
            if (! $before) {
                throw new \RuntimeException('catalog_unavailable');
            }
            $info = $this->nextcloudApi->getFileInfo((string) $before->current_path, true);
            $proof = $this->nextcloudApi->computeFileHash((string) $before->current_path, $deadline, true);
            $after = $this->nextcloudApi->getFileInfo((string) $before->current_path, true);
            if (! ($info['success'] ?? false) || ! ($proof['success'] ?? false) || ! ($after['success'] ?? false)
                || ! is_array($info['source_fingerprint'] ?? null)
                || ($proof['source_fingerprint'] ?? null) !== $info['source_fingerprint']
                || ($after['source_fingerprint'] ?? null) !== $info['source_fingerprint']
                || ! is_string($proof['hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $proof['hash'])
                || hrtime(true) / 1e9 >= $deadline) {
                throw new \RuntimeException('current_source_unavailable_or_changed');
            }
            $latest = DB::table('file_registry')->where('id', $id)->first();
            if ($latest !== null && (array) $latest !== (array) $before) {
                throw new \RuntimeException('catalog_changed');
            }
            $snapshot = ['registry' => (array) $before, 'source_fingerprint' => $proof['source_fingerprint'],
                'observed_source_sha256' => $proof['hash']];
            $digest = $this->sourceIndexDigest($snapshot);
            $result = $held + ['source_sha256' => $proof['hash'], 'proof_sha256' => $digest,
                'catalog_sha256' => $this->sourceIndexDigest((array) $before)];
            // Evidence remains useful for held legacy rows, but cannot authorize publication.
            $this->currentHashPublicationGuard($before, $info, $deadline);
            if ($expectedProof === null) {
                return array_replace($result, ['success' => true, 'status' => 'preview', 'apply_eligible' => true]);
            }
            if (! hash_equals($expectedProof, $digest)) {
                throw new \RuntimeException('hash_proof_changed');
            }

            return DB::transaction(function () use ($id, $before, $info, $proof, $snapshot, $digest, $receiptId, $deadline, $result): array {
                $locked = DB::table('file_registry')->where('id', $id)->lockForUpdate()->first();
                if (! $locked || (array) $locked !== (array) $before) {
                    throw new \RuntimeException('catalog_changed');
                }
                $this->currentHashPublicationGuard($locked, $info, $deadline);
                if (DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->exists()) {
                    throw new \RuntimeException('hash_receipt_already_exists');
                }
                $backup = $this->sourceIndexBackup($receiptId, $snapshot);
                $fresh = $this->nextcloudApi->computeFileHash($locked->current_path, $deadline, true);
                if (! ($fresh['success'] ?? false) || ($fresh['source_fingerprint'] ?? null) !== $proof['source_fingerprint']
                    || ($fresh['hash'] ?? null) !== $proof['hash']) {
                    throw new \RuntimeException('current_source_unavailable_or_changed');
                }
                $this->currentHashPublicationGuard($locked, $info, $deadline);
                $verifiedAt = now()->toDateTimeString();
                // Full row preimage is locked and compared; the conditional write also protects the missing-hash boundary.
                $changed = DB::table('file_registry')->where('id', $id)->where('asset_uuid', $locked->asset_uuid)
                    ->where(function ($query): void {
                        $query->whereNull('content_hash')->orWhere('content_hash', '');
                    })
                    ->update(['content_hash' => $proof['hash'], 'content_hash_verified_at' => $verifiedAt, 'updated_at' => $locked->updated_at]);
                if ($changed !== 1) {
                    throw new \RuntimeException('catalog_changed');
                }
                $final = $this->nextcloudApi->getFileInfo($locked->current_path, true);
                if (! ($final['success'] ?? false) || ($final['source_fingerprint'] ?? null) !== $proof['source_fingerprint']
                    || hrtime(true) / 1e9 >= $deadline) {
                    throw new \RuntimeException('current_source_unavailable_or_changed');
                }
                $published = DB::table('file_registry')->where('id', $id)->first();
                $expected = array_replace((array) $locked, ['content_hash' => $proof['hash'], 'content_hash_verified_at' => $verifiedAt]);
                if (! $published || (array) $published !== $expected) {
                    throw new \RuntimeException('catalog_changed');
                }
                $this->currentHashPublicationGuard($published, $final, $deadline, true);
                $completed = array_replace($result, ['success' => true, 'status' => 'published', 'receipt_id' => $receiptId]);
                DB::table('review_desk_receipts')->insert(['id' => $receiptId, 'reference' => $receiptId,
                    'idempotency_key' => $receiptId, 'request_digest' => $digest, 'action' => 'current_source_hash',
                    'state' => 'completed', 'payload' => json_encode(['schema' => 'plos.current_source_hash.v1',
                        'request' => ['assetUuid' => $locked->asset_uuid, 'source_id' => $id],
                        'preimage' => $snapshot, 'backup' => $backup, 'result' => $completed], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);

                return $completed;
            });
        } catch (\Throwable $error) {
            $codes = ['hash_request_invalid', 'catalog_unavailable', 'catalog_changed', 'hash_proof_changed',
                'current_source_unavailable_or_changed', 'hash_receipt_already_exists', 'source_identity_ambiguous',
                'hash_already_recorded', 'retirement_or_projection_unknown', 'pending_transition_held'];

            return array_replace($result ?? $held, ['success' => false, 'status' => 'held', 'apply_eligible' => false,
                'reason_code' => in_array($error->getMessage(), $codes, true) ? $error->getMessage() : 'hash_guard_unavailable']);
        }
    }

    private function currentHashPublicationGuard(object $row, array $info, float $deadline, bool $published = false): void
    {
        if (! $published && ($row->content_hash ?? null) !== null && $row->content_hash !== '') {
            throw new \RuntimeException('hash_already_recorded');
        }
        $lock = DB::transactionLevel() > 0;
        try {
            $indexed = $this->sourceIndexPathRow((string) $row->current_path, $lock, $deadline, true);
        } catch (\RuntimeException) {
            throw new \RuntimeException('source_identity_ambiguous');
        }
        if (! $indexed || (int) $indexed->id !== (int) $row->id || ($row->status ?? null) !== 'active' || ! Str::isUuid($row->asset_uuid ?? '')
            || ! is_string($row->current_path ?? null) || $row->current_path !== '/'.ltrim($row->current_path, '/')
            || ($row->path_hash ?? null) !== hash('sha256', $row->current_path)
            || (($info['fileid'] ?? null) !== null && (string) ($row->nextcloud_fileid ?? '') !== (string) $info['fileid'])
            || ($info['is_directory'] ?? true) || $row->file_size === null || (int) $row->file_size !== ($info['size'] ?? null)
            || ! is_string($info['last_modified'] ?? null)
            || ($row->nextcloud_modified_at ?? null) !== Carbon::parse($info['last_modified'])->toDateTimeString()
            || DB::table('file_registry')->where('asset_uuid', $row->asset_uuid)->limit(2)->when($lock, fn ($query) => $query->lockForUpdate())->get(['id'])->count() !== 1
            || DB::table('file_registry')->where('path_hash', $row->path_hash)->limit(2)->when($lock, fn ($query) => $query->lockForUpdate())->get(['id'])->count() !== 1
            || (($row->nextcloud_fileid ?? null) !== null && DB::table('file_registry')->where('nextcloud_fileid', $row->nextcloud_fileid)->limit(2)->when($lock, fn ($query) => $query->lockForUpdate())->get(['id'])->count() !== 1)) {
            throw new \RuntimeException('source_identity_ambiguous');
        }
        // A nonnull generation may still own queued retirement. Never infer its outcome from age.
        foreach (['lifecycle_generation_key', 'content_hash_verified_at', 'thumbnail_generated_at', 'face_scan_at',
            'chunked_at', 'semantic_indexed_at', 'rag_indexed_at', 'ai_analyzed_at'] as $field) {
            if (! property_exists($row, $field) || ($row->{$field} !== null && ! ($published && $field === 'content_hash_verified_at'))) {
                throw new \RuntimeException('retirement_or_projection_unknown');
            }
        }
        if (DB::table('file_registry_faces')->where('file_registry_id', $row->id)->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null
            || DB::table('file_registry_perceptual_hashes')->where('file_registry_id', $row->id)->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null
            || DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_id', $row->id)->exists()
            || DB::connection('pgsql_rag')->table('file_semantic_embeddings')->where('file_id', $row->id)->exists()
            || DB::connection('pgsql_rag')->table('rag_documents')->where(function ($query) use ($row): void {
                $query->where('metadata->asset_uuid', $row->asset_uuid)
                    ->orWhere(fn ($q) => $q->where('source_type', 'file_registry')->where('source_id', (string) $row->id));
            })->exists()) {
            throw new \RuntimeException('retirement_or_projection_unknown');
        }
        $limit = config('file_registry.profile_refresh.max_pending_receipts');
        if (! is_int($limit) || $limit < 1) {
            throw new \RuntimeException('pending_transition_held');
        }
        $pending = DB::table('review_desk_receipts')->where(function ($query): void {
            $query->whereNull('state')->orWhere('state', '<>', 'completed');
        })->limit($limit + 1)->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($pending->count() > $limit) {
            throw new \RuntimeException('pending_transition_held');
        }
        foreach ($pending as $receipt) {
            $payload = json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($this->currentHashReceiptTouches($receipt, $payload, $row, $lock)) {
                throw new \RuntimeException('pending_transition_held');
            }
        }
    }

    /** Exact action-owned custody scopes; unrecognized or incomplete evidence stays globally held. */
    private function currentHashReceiptTouches(object $receipt, array $payload, object $row, bool $lock): bool
    {
        if ($receipt->action === AppearanceProfileRefreshService::ACTION) {
            return app(AppearanceProfileRefreshService::class)->receiptTouchesRegistry($payload, (int) $row->id, $lock);
        }
        if ($receipt->action === 'refresh_region') {
            $decision = $payload['current_face_region_decision'] ?? null;
            if (! is_array($decision)
                || $this->reviewedFaceRegion($decision['region'] ?? null) === null
                || ! is_string($decision['source_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $decision['source_sha256'])
                || ($decision['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ($decision['coordinate_basis'] ?? null) !== 'encoded_pixels') {
                throw new \RuntimeException('pending_transition_held');
            }
            foreach (['queue_id', 'media_id', 'tree_id'] as $field) {
                if (filter_var($decision[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new \RuntimeException('pending_transition_held');
                }
            }
            $queue = DB::table('genealogy_face_match_queue')->where('id', $decision['queue_id'])->where('tree_id', $decision['tree_id'])
                ->where('media_id', $decision['media_id'])->when($lock, fn ($q) => $q->lockForUpdate())->first();
            $media = DB::table('genealogy_media')->where('id', $decision['media_id'])->where('tree_id', $decision['tree_id'])
                ->when($lock, fn ($q) => $q->lockForUpdate())->first();
            if (! $queue || ! $media || ($decision['source_revision'] ?? null) !== $this->sourceIndexDigest((array) $queue)) {
                throw new \RuntimeException('pending_transition_held');
            }
            $paths = array_values(array_unique(array_filter([$media->nextcloud_path, $media->original_path], fn ($p) => is_string($p) && $p !== '')));
            if ($paths === []) {
                throw new \RuntimeException('pending_transition_held');
            }
            foreach ($paths as $path) {
                $canonical = $this->canonicalReviewedFacePath($path);
                if ($canonical === null) {
                    throw new \RuntimeException('pending_transition_held');
                }
                if ($canonical === $row->current_path || $canonical === ($row->original_path ?? null)) {
                    return true;
                }
                $claims = DB::table('file_registry')->where('current_path', $canonical)->limit(2)
                    ->when($lock, fn ($q) => $q->lockForUpdate())->get();
                if ($claims->count() !== 1 || $claims->first()->current_path !== $canonical) {
                    throw new \RuntimeException('pending_transition_held');
                }
            }

            return false;
        }
        $request = $payload['request'] ?? null;
        if (! is_array($request)) {
            throw new \RuntimeException('pending_transition_held');
        }
        if ($receipt->action === 'write_exact_capture_date') {
            $asset = $request['asset_uuid'] ?? null;
            $id = $request['file_registry_id'] ?? null;
            if (! is_string($asset) || ! Str::isUuid($asset) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \RuntimeException('pending_transition_held');
            }
            $claims = DB::table('file_registry')->where('asset_uuid', $asset)->limit(2)->when($lock, fn ($q) => $q->lockForUpdate())->get();
            if ($claims->count() !== 1 || (int) $claims->first()->id !== (int) $id) {
                throw new \RuntimeException('pending_transition_held');
            }

            return (int) $id === (int) $row->id || $claims->first()->current_path === $row->current_path;
        }
        if (! in_array($receipt->action, [self::SOURCE_INDEX_ACTION, self::SINGLE_SOURCE_INDEX_ACTION, 'current_source_hash',
            ReviewedFaceMetadataReplacementService::ACTION, ReviewedFaceMetadataReplacementService::APPEND_ACTION], true)) {
            throw new \RuntimeException('pending_transition_held');
        }
        $assets = array_values(array_filter([$request['assetUuid'] ?? null, $request['canonicalAssetUuid'] ?? null, $request['legacyAssetUuid'] ?? null]));
        if ($assets === [] || count(array_filter($assets, fn ($asset) => is_string($asset) && Str::isUuid($asset))) !== count($assets)) {
            throw new \RuntimeException('pending_transition_held');
        }
        foreach ($assets as $asset) {
            $claims = DB::table('file_registry')->where('asset_uuid', $asset)->limit(2)->when($lock, fn ($q) => $q->lockForUpdate())->get();
            if ($claims->count() !== 1) {
                throw new \RuntimeException('pending_transition_held');
            }
            if ($asset === $row->asset_uuid || $claims->first()->current_path === $row->current_path) {
                return true;
            }
        }

        return false;
    }

    /** Refresh a file registration (re-verify and update metadata). */
    public function refreshFileRegistration(string $assetUuid, ?string $ncModifiedAt = null, array $options = []): array
    {
        return DB::transaction(function () use ($assetUuid, $ncModifiedAt, $options): array {
            $localOnly = ($options['require_local_source'] ?? false) === true;
            $deadline = $options['deadline'] ?? null;
            $file = DB::selectOne('
                SELECT * FROM file_registry WHERE asset_uuid = ? FOR UPDATE
            ', [$assetUuid]);

            if (! $file) {
                return ['success' => false, 'error' => 'Asset not found'];
            }

            if (($file->status ?? null) === 'deleted') {
                return ['success' => false, 'error' => 'Deleted source requires reviewed recovery', 'review_required' => true];
            }

            // Verify file still exists
            $exists = $localOnly ? true : $this->nextcloudApi->fileExists($file->current_path);
            $recoveredPath = null;

            if (! $exists && $file->nextcloud_fileid) {
                $searchResult = $this->nextcloudApi->findFileByFileid($file->nextcloud_fileid);
                if (($searchResult['success'] ?? false) && is_string($searchResult['path'] ?? null)
                    && $this->cleanupSourcePresence($searchResult['path'], (int) $file->nextcloud_fileid) === true) {
                    $recoveredPath = $searchResult['path'];
                    $file->current_path = $searchResult['path'];
                    $exists = true;
                }
            }

            if (! $exists) {
                if ($this->cleanupSourcePresence($file->current_path) !== false) {
                    return ['success' => false, 'error' => 'Current source presence is unavailable', 'review_required' => true];
                }
                $this->markOrphaned($assetUuid);

                return [
                    'success' => false,
                    'error' => 'File no longer exists',
                    'asset_uuid' => $assetUuid,
                    'status' => 'orphaned',
                ];
            }

            // Refresh basic physical metadata after recovering the current path.
            // A failed or partial lookup must not erase the existing catalog fields.
            try {
                $info = $localOnly ? $this->nextcloudApi->getFileInfo($file->current_path, true)
                    : $this->nextcloudApi->getFileInfo($file->current_path);
            } catch (\Throwable $e) {
                Log::warning('FileRegistry: refresh file info unavailable', [
                    'asset_uuid' => $assetUuid, 'error' => $e->getMessage(),
                ]);
                $info = ['success' => false];
            }
            if ($localOnly && (! ($info['success'] ?? false)
                || ! is_array($options['expected_source_fingerprint'] ?? null)
                || ($info['source_fingerprint'] ?? null) !== $options['expected_source_fingerprint'])) {
                return ['success' => false, 'reason' => 'source_changed_before_refresh', 'retryable' => true];
            }
            if ($recoveredPath !== null && ! ($info['success'] ?? false)) {
                return ['success' => false, 'error' => 'Recovered source metadata is unavailable', 'review_required' => true];
            }
            $size = false;
            if ($info['success'] ?? false) {
                if (($info['is_directory'] ?? false) || in_array($info['mime_type'] ?? null, ['httpd/unix-directory', 'inode/directory'], true)
                    || (isset($info['path']) && '/'.ltrim($info['path'], '/') !== '/'.ltrim($file->current_path, '/'))
                    || ($file->nextcloud_fileid !== null && ($info['fileid'] ?? null) !== null
                        && (int) $file->nextcloud_fileid !== (int) $info['fileid'])) {
                    return ['success' => false, 'error' => 'Source identity changed during refresh', 'review_required' => true];
                }
                if (is_string($info['last_modified'] ?? null) && trim($info['last_modified']) !== '') {
                    try {
                        $ncModifiedAt = Carbon::parse($info['last_modified'])->toDateTimeString();
                    } catch (\Throwable $e) {
                        // Unknown source time cannot supply a freshness proof.
                    }
                }
                $size = isset($info['size'])
                    ? filter_var($info['size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
                    : false;
                $mimeType = is_string($info['mime_type'] ?? null) ? trim($info['mime_type']) : '';
            }

            // Compare with the locked preimage, before publishing any new physical metadata.
            $savedSize = isset($file->file_size) ? filter_var($file->file_size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            $metadataChanged = ($size !== false && ($savedSize === false || $size !== $savedSize))
                || ($ncModifiedAt !== null && $ncModifiedAt !== ($file->nextcloud_modified_at ?? null));

            // Incomplete or changed metadata requires the existing actual-byte/lifecycle path.
            $contentChanged = false;
            $invalidationResult = null;
            if ($metadataChanged) {
                $verifiedBytesUnchanged = false;
                if (! empty($file->content_hash_verified_at) && is_string($file->content_hash ?? null)
                    && preg_match('/^[a-f0-9]{64}$/D', $file->content_hash)) {
                    try {
                        $physical = $localOnly ? $this->nextcloudApi->computeFileHash($file->current_path, $deadline === null ? null : (float) $deadline, true)
                            : $this->nextcloudApi->computeFileHash($file->current_path);
                        if ($localOnly && (! ($physical['success'] ?? false)
                            || ($physical['source_fingerprint'] ?? null) !== $info['source_fingerprint'])) {
                            return ['success' => false, 'reason' => 'source_changed_or_hash_unavailable', 'retryable' => true];
                        }
                        $verifiedBytesUnchanged = ($physical['success'] ?? false) && is_string($physical['hash'] ?? null)
                            && hash_equals($file->content_hash, $physical['hash']);
                        if ($verifiedBytesUnchanged) {
                            $beforeTime = is_string($info['last_modified'] ?? null) ? strtotime($info['last_modified']) : false;
                            if (! ($info['success'] ?? false) || $size === false || $beforeTime === false
                                || filter_var($physical['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== $size) {
                                $verifiedBytesUnchanged = false;
                            } else {
                                $after = $localOnly ? $this->nextcloudApi->getFileInfo($file->current_path, true)
                                    : $this->nextcloudApi->getFileInfo($file->current_path);
                                $afterTime = is_string($after['last_modified'] ?? null) ? strtotime($after['last_modified']) : false;
                                if (! ($after['success'] ?? false)
                                    || ($after['is_directory'] ?? false)
                                    || in_array($after['mime_type'] ?? null, ['httpd/unix-directory', 'inode/directory'], true)
                                    || filter_var($after['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== $size
                                    || $afterTime !== $beforeTime
                                    || (isset($after['path']) && '/'.ltrim($after['path'], '/') !== $file->current_path)
                                    || (($info['fileid'] ?? null) !== null && ($after['fileid'] ?? null) !== $info['fileid'])
                                    || (($info['etag'] ?? null) !== null && ($after['etag'] ?? null) !== $info['etag'])
                                    || (isset($info['source_fingerprint']) && (($physical['source_fingerprint'] ?? null) !== $info['source_fingerprint']
                                        || ($after['source_fingerprint'] ?? null) !== $info['source_fingerprint']))) {
                                    return ['success' => false, 'error' => 'Source changed or became unavailable during hash verification', 'review_required' => true];
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // Unavailable proof retains normal invalidation.
                        $verifiedBytesUnchanged = false;
                    }
                }
                if (! $verifiedBytesUnchanged) {
                    $contentChanged = true;
                }
            }

            if ($localOnly) {
                $after = $this->nextcloudApi->getFileInfo($file->current_path, true);
                if (! ($after['success'] ?? false) || ($after['source_fingerprint'] ?? null) !== $info['source_fingerprint']) {
                    return ['success' => false, 'reason' => 'source_changed_during_refresh', 'retryable' => true];
                }
                if ($deadline !== null && (! is_numeric($deadline) || ! is_finite((float) $deadline)
                    || hrtime(true) / 1e9 >= (float) $deadline)) {
                    return ['success' => false, 'reason' => 'intake_deadline_exceeded', 'retryable' => true];
                }
            }

            if ($recoveredPath !== null
                && ! $this->updateFilePath($assetUuid, $recoveredPath, 'nextcloud_sync', 'Auto-discovered via fileid')) {
                return ['success' => false, 'error' => 'Recovered source could not be remapped', 'review_required' => true];
            }
            if ($contentChanged && ! $localOnly) {
                Log::info('FileRegistry: physical metadata changed; invalidating unproven derivatives', ['asset_uuid' => $assetUuid]);
                $invalidationResult = $this->invalidateDerivedData($file->id, $assetUuid);
            }
            if (($info['success'] ?? false) && ($size !== false || $mimeType !== '')) {
                DB::update('
                    UPDATE file_registry
                    SET file_size = COALESCE(?, file_size), mime_type = COALESCE(?, mime_type)
                    WHERE asset_uuid = ?
                ', [$size !== false ? $size : null, $mimeType !== '' ? $mimeType : null, $assetUuid]);
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

            if ($contentChanged && $localOnly) {
                // SQL stale markers and durable retirement custody share the lease transaction.
                if (! is_array($options['_discovery_retirement_policy'] ?? null)) {
                    throw new \LogicException('Discovery retirement policy must be admitted before the intake transaction.');
                }
                $invalidationResult = $this->getLifecycleService()->invalidateDerivedDataForDiscovery($file->id, $assetUuid,
                    $options['_discovery_retirement_policy']);
            }

            return [
                'success' => true,
                'outcome' => 'updated',
                'asset_uuid' => $assetUuid,
                'reference' => "{{ASSET:{$assetUuid}}}",
                'path' => $file->current_path,
                'nextcloud_fileid' => $file->nextcloud_fileid,
                'status' => 'active',
                'content_changed' => $contentChanged,
                'invalidated' => $invalidationResult,
            ];
        });
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

        if (($filters['status'] ?? null) === 'active' && empty($filters['include_unavailable'])) {
            $whereClauses[] = 'COALESCE(verification_failures, 0) = 0';
            $whereClauses[] = "(
                thumbnail_error IS NULL
                OR (
                    LOWER(thumbnail_error) NOT LIKE '%404%'
                    AND LOWER(thumbnail_error) NOT LIKE '%not found%'
                    AND LOWER(thumbnail_error) NOT LIKE '%no such file%'
                    AND LOWER(thumbnail_error) NOT LIKE '%failed to download source file%'
                )
            )";
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
            $searchTerm = '%'.$this->normalizeListSearchTerm((string) $filters['search']).'%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $whereClauses);
        $params[] = $limit;
        $params[] = $offset;

        $files = DB::select("
            SELECT asset_uuid, filename, current_path, extension, mime_type, file_size,
                   category, status, verification_failures, thumbnail_error,
                   last_verified_at, rag_indexed_at, created_at
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

    private function normalizeListSearchTerm(string $search): string
    {
        $term = trim($search);
        if ($term !== '*' && str_starts_with($term, '*')) {
            $term = ltrim(substr($term, 1));
        }

        if (strlen($term) >= 2) {
            $first = $term[0];
            $last = $term[strlen($term) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $term = substr($term, 1, -1);
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $term));
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

        $limitSql = $limit > 0 ? 'LIMIT ?' : '';
        $bindings = $limit > 0 ? [$limit] : [];

        $files = DB::select("
            SELECT asset_uuid, current_path, nextcloud_fileid, last_verified_at
            FROM file_registry
            WHERE status = 'active'
            ORDER BY COALESCE(last_verified_at, '1970-01-01') ASC, created_at ASC
            {$limitSql}
        ", $bindings);

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
                    if (empty($resolution['marked_orphaned'])) {
                        $this->markOrphaned($file->asset_uuid);
                    }
                    $results['orphaned']++;
                    $results['details'][] = "Orphaned: {$file->current_path}";
                }
            } catch (Exception $e) {
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
    public function scanAndRegisterNew(?string $path = null, int $limit = 500, array $options = []): array
    {
        try {
            $path ??= $this->nextcloudApi->configuredLibraryRoot();
            // Admit durable retirement policy before any lease transaction or callback.
            $retirement = $this->getLifecycleService()->prepareDiscoveryRetirement();

            $result = app(FileRegistryDiscoveryService::class)->scan($path,
                fn (string $source, array $intakeOptions) => $this->registerFile($source,
                    array_replace($options, $intakeOptions, ['_discovery_retirement_policy' => $retirement])),
                $limit, $options);
            try {
                $result['continuation'] = ExecuteFileRegistryScan::admitContinuation(
                    $path, $limit, $options['compute_hash'] ?? true, $result, $retirement);
            } catch (\Throwable $e) {
                Log::warning('Discovery continuation held after committed slice', ['exception' => $e::class]);
                $result['continuation'] = ['state' => 'held', 'reason' => 'durable_continuation_unavailable'];
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('File registry discovery admission held', ['exception' => $e::class]);

            return ['state' => 'held', 'scope' => $path, 'scanned' => 0, 'registered' => 0,
                'already_registered' => 0, 'skipped_unchanged' => 0, 'duplicates' => 0,
                'deferred_unstable' => 0, 'deferred_reasons' => [], 'stability_wait_seconds' => 0.0,
                'stability_policy_source' => FileIntakeStabilityService::CONFIG_KEY,
                'errors' => 1, 'error_details' => [['reason' => 'discovery_admission_unavailable']],
                'discovery_complete' => false, 'intake_complete' => false,
                'stop_reason' => 'discovery_admission_unavailable', 'next_attempt_at' => null];
        }
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
                        if (! $this->updateFilePath($file->asset_uuid, $searchResult['path'], 'detection_scan')) {
                            $results['errors']++;
                        }

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
            } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        $state = $stats['state'] ?? null;
        $status = $state === 'held' || ($stats['continuation']['state'] ?? null) === 'held'
            ? 'failed' : ($state === 'cancelled' ? 'cancelled' : 'completed');
        $progress = array_intersect_key($stats, array_flip(['state', 'scope_id', 'epoch', 'revision',
            'discovery_complete', 'intake_complete', 'pending_work', 'held_work', 'next_attempt_at', 'stop_reason', 'continuation']));
        DB::update('
            UPDATE file_registry_sync_runs
            SET status = ?,
                completed_at = NOW(),
                files_scanned = ?,
                files_registered = ?,
                duplicates_found = ?,
                errors = ?,
                error_log = ?
            WHERE id = ?
        ', [
            $status,
            $stats['scanned'] ?? $stats['files_scanned'] ?? 0,
            $stats['registered'] ?? $stats['files_registered'] ?? 0,
            $stats['duplicates'] ?? $stats['duplicates_found'] ?? 0,
            $stats['errors'] ?? 0,
            $progress === [] ? null : json_encode(['slice' => $progress], JSON_THROW_ON_ERROR),
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
            $sourceHash = @hash_file('sha256', $localPath);
            if (! is_string($sourceHash)) {
                return ['success' => false, 'error' => 'Source image hash unavailable'];
            }
            // Embedded names are source claims, not permission to assign identities.
            $regions = $faceService->readFaceRegions($localPath, true, true);
            $sourceDimensions = $regions !== [] ? $faceService->decodedImageDimensions($localPath) : null;

            return DB::transaction(function () use ($file, $assetUuid, $regions, $sourceHash, $sourceDimensions, $faceService): array {
                $current = DB::selectOne('SELECT current_path, status FROM file_registry WHERE id = ? FOR UPDATE', [$file->id]);
                if (! $current || $current->status !== 'active' || $current->current_path !== $file->current_path) {
                    return ['success' => false, 'error' => 'Source registry changed during metadata scan'];
                }
                $physical = $this->nextcloudApi->computeFileHash($current->current_path);
                if (! ($physical['success'] ?? false) || ! is_string($physical['hash'] ?? null)
                    || ! hash_equals($sourceHash, $physical['hash'])) {
                    return ['success' => false, 'error' => 'Source image changed during metadata scan'];
                }
                $existing = DB::select('SELECT id, source, region_x, region_y, region_w, region_h FROM file_registry_faces WHERE file_registry_id = ? AND source_current = 1 AND superseded_at IS NULL', [$file->id]);
                $tolerance = max(0.0, (float) config('genealogy.face_region_match_tolerance'));
                $stored = 0;
                $held = 0;
                foreach ($regions as &$region) {
                    if (($region['type'] ?? 'Face') !== 'Face') {
                        continue;
                    }
                    $holdReason = $faceService->appliedDimensionsHoldReason($region['applied_to_dimensions'] ?? null, $sourceDimensions);
                    if ($holdReason !== null) {
                        $region['hold_reason'] = $holdReason;
                        $held++;

                        continue;
                    }
                    if (strtolower((string) ($region['unit'] ?? '')) !== 'normalized') {
                        $region['hold_reason'] = 'region_unit_unproven';
                        $held++;

                        continue;
                    }
                    $box = [];
                    foreach (['x', 'y', 'w', 'h'] as $axis) {
                        if (! is_numeric($region[$axis] ?? null) || ! is_finite((float) $region[$axis])) {
                            $box = [];
                            break;
                        }
                        $box[$axis] = (float) $region[$axis];
                    }
                    // The existing reader returns MWG normalized centers; detector storage uses top-left.
                    if (count($box) !== 4 || $box['w'] <= 0 || $box['h'] <= 0
                        || $box['x'] - $box['w'] / 2 < 0 || $box['y'] - $box['h'] / 2 < 0
                        || $box['x'] + $box['w'] / 2 > 1 || $box['y'] + $box['h'] / 2 > 1) {
                        $region['hold_reason'] = 'region_invalid';
                        $held++;

                        continue;
                    }
                    $topLeft = ['x' => $box['x'] - $box['w'] / 2, 'y' => $box['y'] - $box['h'] / 2,
                        'w' => $box['w'], 'h' => $box['h']];
                    $alreadyPresent = false;
                    foreach ($existing as $face) {
                        // Legacy XMP rows stored raw centers. Retain those IDs/decisions for review.
                        foreach (($face->source ?? null) === 'xmp' ? [$topLeft, $box] : [$topLeft] as $candidate) {
                            $same = true;
                            foreach (['x', 'y', 'w', 'h'] as $axis) {
                                $value = $face->{'region_'.$axis} ?? null;
                                if (! is_numeric($value) || abs((float) $value - $candidate[$axis]) > $tolerance) {
                                    $same = false;
                                    break;
                                }
                            }
                            $alreadyPresent = $alreadyPresent || $same;
                        }
                    }
                    if ($alreadyPresent) {
                        continue;
                    }
                    DB::insert("INSERT INTO file_registry_faces
                        (file_registry_id, person_name, genealogy_person_id, region_x, region_y, region_w, region_h, source, source_sha256, face_region_convention, source_generation_key, source_current, created_at, updated_at)
                        VALUES (?, ?, NULL, ?, ?, ?, ?, 'xmp', ?, 'normalized_top_left', ?, 1, NOW(), NOW())", [
                        $file->id, $region['name'] ?? null, $topLeft['x'], $topLeft['y'], $topLeft['w'], $topLeft['h'], $sourceHash, $sourceHash,
                    ]);
                    $existing[] = (object) ['source' => 'xmp', 'region_x' => $topLeft['x'], 'region_y' => $topLeft['y'],
                        'region_w' => $topLeft['w'], 'region_h' => $topLeft['h']];
                    $stored++;
                }
                unset($region);
                $holdReview = $held > 0 ? $this->retainFaceMetadataHold($file, $sourceHash, $regions) : null;
                $attemptRetained = $held === 0 || $holdReview !== null;
                DB::update('UPDATE file_registry SET face_count = ?, face_scan_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END, updated_at = NOW() WHERE id = ?', [count($existing), (int) $attemptRetained, $file->id]);
                if ($held > 0) {
                    Log::warning('FileRegistry metadata face regions require review', ['asset_uuid' => $assetUuid,
                        'regions_held' => $held, 'hold_reasons' => array_count_values(array_column($regions, 'hold_reason'))]);
                }

                return ['success' => $held === 0, 'status' => $held > 0 ? 'held' : 'completed',
                    'error_code' => $held > 0 ? 'face_metadata_requires_review' : null,
                    'error' => $held > 0 ? 'Embedded face coordinates require review; metadata scan is incomplete' : null,
                    'asset_uuid' => $assetUuid, 'faces_found' => count($regions),
                    'faces_stored' => $stored, 'faces_retained' => count($existing) - $stored,
                    'regions_held' => $held, 'hold_review_id' => $holdReview['review_id'] ?? null, 'hold_review' => $holdReview, 'identity_approved' => false, 'source_dimensions' => $sourceDimensions, 'regions' => $regions];
            });
        } catch (\Throwable $e) {
            Log::warning('FileRegistry metadata face scan did not complete', ['asset_uuid' => $assetUuid, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Face metadata read did not complete'];
        } finally {
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /** Retain an inspection-only hold through the registered review route, with no notifications or identity actions. */
    private function retainFaceMetadataHold(object $file, string $sourceHash, array $regions): ?array
    {
        try {
            $type = config('file_registry.image_audit.review.type');
            $registration = DB::selectOne('SELECT * FROM review_type_registry WHERE name = ? AND enabled = 1', [$type]);
            if (! $registration || $registration->source_table !== 'agent_review_queue'
                || $registration->source_connection !== 'mysql' || json_decode($registration->actions, true) !== []
                || empty(json_decode($registration->ui_schema, true)['inspection_only'])
                || ! app(ReviewTypeRegistryService::class)->isInspectionOnly($type)) {
                throw new \RuntimeException('Face metadata inspection review route is unavailable');
            }
            $reasons = array_count_values(array_column($regions, 'hold_reason'));
            ksort($reasons);
            $reason = 'Embedded face coordinate basis requires review: '.json_encode($reasons, JSON_THROW_ON_ERROR)
                .'. Original embedded labels and areas remain unchanged; no identity approval.';
            $title = 'Photo metadata '.hash('sha256', $file->asset_uuid."\0".$sourceHash);
            $agent = config('file_registry.image_audit.review.agent_id');
            // Existing dispositions are never replayed or overwritten by a rescan.
            $existing = DB::selectOne('SELECT id, status FROM agent_review_queue WHERE agent_id = ? AND review_type = ? AND title = ? ORDER BY id DESC LIMIT 1', [$agent, $type, $title]);
            $submitted = $existing ? ['success' => true, 'review_id' => $existing->id] : app(AgentLoopService::class)->submitForReview([
                'agent_id' => $agent, 'review_type' => $type, 'finding_type' => 'face_metadata_requires_review',
                'title' => $title, 'summary' => $reason, 'priority' => config('file_registry.image_audit.review.priority'),
                'notify' => false, 'actionability_state' => AgentActionabilityGateService::STATE_REVIEW_READY,
                'details' => ['inspection_only' => true, 'asset_uuid' => $file->asset_uuid,
                    'source_sha256' => $sourceHash, 'filename' => $file->filename,
                    'classification' => 'face_metadata_requires_review', 'reason' => $reason],
            ]);
            $id = (int) ($submitted['review_id'] ?? 0);
            $retained = $id > 0 ? DB::selectOne('SELECT * FROM agent_review_queue WHERE id = ? AND agent_id = ? AND review_type = ?', [$id, $agent, $type]) : null;
            $details = $retained ? json_decode($retained->details, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! ($submitted['success'] ?? false) || ! $retained || $retained->title !== $title
                || ! in_array($retained->status, ['pending', 'approved', 'rejected', 'expired', 'ignored'], true)
                || ($retained->status === 'pending' && ($retained->expires_at ?? null) !== null) || ! is_array($details)
                || ($details['inspection_only'] ?? null) !== true || ($details['asset_uuid'] ?? null) !== $file->asset_uuid
                || ($details['source_sha256'] ?? null) !== $sourceHash || ($details['reason'] ?? null) !== $reason
                || ($details['classification'] ?? null) !== 'face_metadata_requires_review') {
                throw new \RuntimeException('Face metadata inspection hold could not be verified');
            }

            return ['review_id' => $id, 'status' => $retained->status];
        } catch (\Throwable $e) {
            Log::warning('FileRegistry face metadata hold was not retained', ['asset_uuid' => $file->asset_uuid, 'error' => $e->getMessage()]);

            return null;
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

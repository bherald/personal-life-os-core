<?php

namespace App\Services;

use App\Services\Files\PhotoReviewEvidenceService;
use Illuminate\Database\ConnectionInterface;
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

    private ContentExtractionProvenanceService $extractionProvenance;

    /** Document type for file catalog RAG entries */
    private const RAG_DOCUMENT_TYPE = 'file_catalog';

    /** Minimum similarity threshold to consider RAG results */
    private const SIMILARITY_THRESHOLD = 0.60;

    public function __construct(
        RAGService $ragService,
        AIService $aiService,
        ?ContentExtractionProvenanceService $extractionProvenance = null
    ) {
        $this->ragService = $ragService;
        $this->aiService = $aiService;
        $this->extractionProvenance = $extractionProvenance ?? new ContentExtractionProvenanceService;
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
                SELECT fr.*, fcep.metadata AS content_extraction_metadata
                FROM file_registry fr
                LEFT JOIN file_content_extraction_provenance fcep ON fcep.file_registry_id = fr.id
                WHERE fr.asset_uuid = ?
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
        $file = null;
        $fieldReview = null;
        try {
            $file = DB::selectOne('
                SELECT fr.*, fcep.metadata AS content_extraction_metadata
                FROM file_registry fr
                LEFT JOIN file_content_extraction_provenance fcep ON fcep.file_registry_id = fr.id
                WHERE fr.asset_uuid = ?
            ', [$assetUuid]);

            if (! $file) {
                return ['success' => false, 'error' => 'File not found in registry'];
            }

            $registry = app(FileRegistryService::class);
            $fieldReview = $this->indexFieldReviewProjection($file);
            if ($fieldReview['present'] && ! $fieldReview['valid']) {
                $this->holdPhotoReviewIndex($file);

                return ['success' => false, 'error' => $fieldReview['error'] ?? 'photo_field_review_held'];
            }
            foreach ($fieldReview['fields'] ?? [] as $reviewedField) {
                if (($reviewedField['state'] ?? null) !== 'current') {
                    $this->holdPhotoReviewIndex($file);

                    return ['success' => false, 'error' => 'photo_field_review_source_reconciliation_required'];
                }
            }

            // Pin current registry/bytes; cached content provenance remains unverified.
            $publicationProof = $registry->currentSourcePublicationProof($file);
            $content = $this->buildIndexContent($file, $extractedText, $extractedMetadata, $options,
                $fieldReview['present'] ? $fieldReview['fields'] : []);

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
                'ai_document_type' => $this->hasUsableRegistryAnalysis($file) ? ($file->ai_document_type ?? null) : null,
                'date_taken' => $file->date_taken ?? null,
                'date_taken_precision' => $file->date_taken_precision ?? null,
                'date_taken_source' => $file->date_taken_source ?? null,
                'date_taken_confidence' => $file->date_taken_confidence ?? null,
                'date_needs_review' => (bool) ($file->date_needs_review ?? false),
                'is_scan' => (bool) ($file->is_scan ?? false),
                'scan_date' => $file->scan_date ?? null,
                'has_ocr_text' => $this->hasUsableRegistryAnalysis($file) && ! empty($file->ai_detected_text),
                'indexed_at' => now()->toIso8601String(),
            ];

            if ($fieldReview['present']) {
                $metadata['photo_field_reviews'] = $fieldReview['fields'];
                $metadata['photo_field_review_source_sha256'] = $fieldReview['source_sha256'];
                $metadata['photo_field_review_state'] = 'source_bound';
                $metadata['machine_photo_claims'] = [
                    'caption' => $file->ai_description ?? null,
                    'ocr' => $file->ai_detected_text ?? null,
                ];
            }

            $storedExtractionProvenance = $extractedMetadata[ContentExtractionProvenanceService::METADATA_KEY]
                ?? $extractedMetadata['extraction_provenance']
                ?? $file->content_extraction_metadata
                ?? null;
            $storedExtractionProvenance = $this->extractionProvenance->decode($storedExtractionProvenance);
            if ($storedExtractionProvenance !== null) {
                if ($fieldReview['present']) {
                    unset($storedExtractionProvenance['_photo_field_reviews']);
                }
                $metadata[ContentExtractionProvenanceService::METADATA_KEY] = $storedExtractionProvenance;
            }

            if ($fieldReview['present'] && ! $this->photoReviewSnapshotCurrent($file, $fieldReview)) {
                $this->holdPhotoReviewIndex($file);

                return ['success' => false, 'error' => 'photo_field_review_source_or_provenance_changed'];
            }

            if (! $this->isBulkMode($options)) {
                // Face names
                try {
                    $faces = DB::select("
                        SELECT DISTINCT person_name FROM file_registry_faces
                        WHERE file_registry_id = ? AND source_current = 1 AND superseded_at IS NULL
                          AND person_name IS NOT NULL AND person_name != ''
                    ", [$file->id]);
                    if (! empty($faces)) {
                        $metadata['face_label_claims'] = array_column(array_map(fn ($f) => (array) $f, $faces), 'person_name');
                        $metadata['face_label_identity_approved'] = false;
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
                            JOIN genealogy_media gm ON gm.id = gpm.media_id
                            JOIN genealogy_persons gp ON gp.id = gpm.person_id AND gp.tree_id = gm.tree_id
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
                $generalExtractedMetadata = $extractedMetadata;
                unset(
                    $generalExtractedMetadata[ContentExtractionProvenanceService::METADATA_KEY],
                    $generalExtractedMetadata['extraction_provenance']
                );
                if ($generalExtractedMetadata !== []) {
                    $metadata['extracted'] = $generalExtractedMetadata;
                }
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
                ['source_identity_upsert' => true, ...$options],
                function (callable $publish) use ($registry, $publicationProof, $file, $fieldReview): object {
                    return $registry->publishCurrentSourceProjection($publicationProof, $publish, function () use ($file, $fieldReview): void {
                        if ($fieldReview['present'] && ! $this->photoReviewSnapshotCurrent($file, $fieldReview)) {
                            throw new \RuntimeException('photo_field_review_changed_before_publication');
                        }
                        DB::update('UPDATE file_registry SET rag_indexed_at = ? WHERE id = ?', [now(), $file->id]);
                    });
                },
            );

            if ($fieldReview['present'] && ! $this->photoReviewSnapshotCurrent($file, $fieldReview)) {
                $this->holdPhotoReviewIndex($file);

                return ['success' => false, 'error' => 'photo_field_review_changed_during_index'];
            }

            Log::info('FileCategorizationRAG: Indexed file', [
                'asset_uuid' => $assetUuid,
                'rag_id' => $doc->id,
                'path' => $file->current_path,
            ]);

            return [
                'success' => true,
                'rag_id' => $doc->id,
            ];
        } catch (\Throwable $e) {
            $reconciliationRequired = false;
            if (($fieldReview['present'] ?? false) && $file) {
                try {
                    $this->holdPhotoReviewIndex($file);
                } catch (\Throwable) {
                    $reconciliationRequired = true;
                }
            }
            Log::error('FileCategorizationRAG: Failed to index file', [
                'asset_uuid' => $assetUuid,
                'error' => $e->getMessage(),
                'photo_review_reconciliation_required' => $reconciliationRequired,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                ...($reconciliationRequired ? ['reconciliation_required' => true] : []),
            ];
        }
    }

    public function indexFileForBulkRag(string $assetUuid): array
    {
        return $this->indexFile($assetUuid, null, null, [
            'bulk_mode' => true,
            'trace_timing' => true,
        ]);
    }

    /** Resolve field-review receipts only for images; legacy documents remain unchanged. */
    private function indexFieldReviewProjection(object $file): array
    {
        $empty = ['present' => false, 'valid' => true, 'fields' => []];
        $images = array_map('strtolower', (array) config('file_types.image', []));
        if (! in_array(strtolower((string) ($file->extension ?? '')), $images, true)) {
            return $empty;
        }

        $reviews = app(PhotoReviewEvidenceService::class);
        $raw = $file->content_extraction_metadata ?? null;
        $initial = $reviews->projectFieldReviewsForIndex($raw, $file);
        if (! ($initial['valid'] ?? false)) {
            return ['present' => true, 'valid' => false, 'fields' => [], 'error' => 'photo_field_review_history_held'];
        }
        if (! ($initial['present'] ?? false)) {
            return $empty;
        }

        $hash = $this->computeBoundedPhotoHash((string) $file->current_path);
        if ($hash === null) {
            return ['present' => true, 'valid' => false, 'fields' => [], 'error' => 'photo_field_review_source_unavailable'];
        }
        $projection = $reviews->projectFieldReviewsForIndex($raw, $file, $hash);
        if (! ($projection['valid'] ?? false) || ! ($projection['present'] ?? false)) {
            return ['present' => true, 'valid' => false, 'fields' => [], 'error' => 'photo_field_review_history_held'];
        }

        return ['present' => true, 'valid' => true, 'fields' => $projection['fields'],
            'source_sha256' => $hash, 'provenance_fingerprint' => $this->metadataFingerprint($raw)];
    }

    private function computeBoundedPhotoHash(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $deadline = hrtime(true) / 1e9 + max(.001, min(30, (float) config('file_registry.photo_review.hash_timeout_seconds', 10)));
        $proof = app(NextcloudFileApiService::class)->computeFileHash($path, $deadline, true);
        $hash = $proof['hash'] ?? null;

        return ($proof['success'] ?? false) && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) ? $hash : null;
    }

    private function photoReviewSnapshotCurrent(object $file, array $projection): bool
    {
        $current = DB::selectOne('
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.status, fcep.metadata AS content_extraction_metadata
            FROM file_registry fr
            LEFT JOIN file_content_extraction_provenance fcep ON fcep.file_registry_id = fr.id
            WHERE fr.id = ?
        ', [$file->id]);
        if (! $current || (int) $current->id !== (int) $file->id
            || ($current->asset_uuid ?? null) !== ($file->asset_uuid ?? null)
            || ($current->current_path ?? null) !== ($file->current_path ?? null)
            || ($current->status ?? null) !== 'active'
            || ! hash_equals((string) ($projection['provenance_fingerprint'] ?? ''), $this->metadataFingerprint($current->content_extraction_metadata ?? null))) {
            return false;
        }

        return $this->computeBoundedPhotoHash((string) $current->current_path) === ($projection['source_sha256'] ?? null);
    }

    private function completePhotoReviewIndex(object $file, array $projection): bool
    {
        try {
            return DB::transaction(function () use ($file, $projection): bool {
                $current = DB::table('file_registry')->where('id', $file->id)->lockForUpdate()->first(['id', 'asset_uuid', 'current_path', 'status']);
                $provenance = DB::table('file_content_extraction_provenance')->where('file_registry_id', $file->id)
                    ->lockForUpdate()->first(['metadata']);
                if (! $current || $current->asset_uuid !== $file->asset_uuid || $current->current_path !== $file->current_path
                    || $current->status !== 'active'
                    || ! hash_equals((string) ($projection['provenance_fingerprint'] ?? ''), $this->metadataFingerprint($provenance?->metadata))) {
                    return false;
                }

                $updated = DB::table('file_registry')->where('id', $file->id)->where('asset_uuid', $file->asset_uuid)
                    ->where('current_path', $file->current_path)->where('status', 'active')->update(['rag_indexed_at' => now()]);
                if ($updated < 1 || $this->computeBoundedPhotoHash((string) $current->current_path) !== ($projection['source_sha256'] ?? null)) {
                    throw new \RuntimeException('photo_field_review_source_changed_before_index_commit');
                }

                return true;
            });
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function holdPhotoReviewIndex(object $file): void
    {
        DB::update('UPDATE file_registry SET rag_indexed_at = NULL WHERE id = ? AND asset_uuid = ?', [$file->id, $file->asset_uuid]);
    }

    private function metadataFingerprint(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return hash('sha256', 'null');
        }
        try {
            $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : (array) $raw;
        } catch (\Throwable) {
            return hash('sha256', is_string($raw) ? $raw : serialize($raw));
        }
        if (! is_array($decoded)) {
            return hash('sha256', is_string($raw) ? $raw : serialize($raw));
        }
        $sort = function (&$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$child) {
                $sort($child);
            }
        };
        $sort($decoded);

        return hash('sha256', json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
            $connection = DB::connection('pgsql_rag');
            $result = $connection->transaction(function () use ($connection, $assetUuid): array {
                $documents = $connection->select("
                    SELECT id
                    FROM rag_documents
                    WHERE document_type = ?
                      AND metadata->>'asset_uuid' = ?
                    ORDER BY id
                    FOR UPDATE
                ", [self::RAG_DOCUMENT_TYPE, $assetUuid]);
                $documentIds = array_values(array_map(fn (object $row): int => (int) $row->id, $documents));
                if ($documentIds === []) {
                    return ['success' => true, 'deleted' => 0, 'retained_for_provenance' => 0];
                }

                $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
                $referenceCounts = $this->sourceProvenanceReferenceCounts($connection, $documentIds);
                if (array_sum($referenceCounts) > 0) {
                    return [
                        'success' => false,
                        'deleted' => 0,
                        'retained_for_provenance' => count($documentIds),
                        'reason' => 'source_provenance_references_present',
                        'references' => $referenceCounts,
                    ];
                }

                $deleted = $connection->delete("
                    DELETE FROM rag_documents
                    WHERE id IN ({$placeholders})
                ", $documentIds);

                return ['success' => true, 'deleted' => $deleted, 'retained_for_provenance' => 0];
            });

            if (($result['deleted'] ?? 0) > 0) {
                Log::info('FileCategorizationRAG: Removed file from index', [
                    'asset_uuid' => $assetUuid,
                ]);
            }

            return $result;
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

    /** Retire one exact standalone projection while preserving referenced evidence. */
    public function retireUnlinkedGenealogyMediaProjection(
        int $treeId,
        int $mediaId,
        int $documentId,
        string $reason,
        bool $dryRun = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        $base = [
            'tool' => 'retire_unlinked_genealogy_media_projection',
            'tree_id' => $treeId,
            'media_id' => $mediaId,
            'document_id' => $documentId,
            'dry_run' => $dryRun,
            'applied' => false,
            'deleted' => 0,
        ];
        if ($treeId <= 0 || $mediaId <= 0 || $documentId <= 0 || trim($reason) === '' || (! $dryRun && ! $confirm)) {
            return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'positive_ids_reason_and_confirm_required'];
        }

        try {
            $result = DB::transaction(function () use ($treeId, $mediaId, $documentId, $dryRun, $base): array {
                $media = DB::table('genealogy_media')->where('id', $mediaId)->where('tree_id', $treeId)
                    ->lockForUpdate()->first(['id', 'tree_id', 'nextcloud_path']);
                if (! $media) {
                    return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'media_not_found_in_tree'];
                }

                $mediaReferences = $this->genealogyMediaReferenceCounts($mediaId, $media->nextcloud_path);
                if (array_sum($mediaReferences) > 0) {
                    return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'genealogy_media_dependencies_present', 'references' => $mediaReferences];
                }

                $connection = DB::connection('pgsql_rag');

                return $connection->transaction(function () use ($connection, $treeId, $mediaId, $documentId, $dryRun, $base): array {
                    $identitySql = "id = ? AND document_type = ? AND source_type = ? AND source_id = ?
                        AND metadata->>'genealogy_media_id' = ? AND metadata->>'tree_id' = ?";
                    $identity = [$documentId, self::RAG_DOCUMENT_TYPE, 'genealogy_media', 'gen_media_'.$mediaId, (string) $mediaId, (string) $treeId];
                    $document = $connection->selectOne("SELECT id FROM rag_documents WHERE {$identitySql} FOR UPDATE", $identity);
                    if (! $document) {
                        return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'standalone_projection_identity_mismatch'];
                    }

                    $references = $this->projectionReferenceCounts($connection, $documentId)['references'];
                    if (array_sum($references) > 0) {
                        return [...$base, 'success' => false, 'eligible' => false, 'retained_for_provenance' => 1,
                            'reason' => 'source_provenance_references_present', 'references' => $references];
                    }
                    if ($dryRun) {
                        return [...$base, 'success' => true, 'eligible' => true, 'references' => $references];
                    }

                    $deleted = $connection->delete("DELETE FROM rag_documents WHERE {$identitySql}", $identity);

                    return [...$base, 'success' => $deleted === 1, 'eligible' => true, 'applied' => $deleted === 1, 'deleted' => $deleted];
                });
            });
            if ($result['applied']) {
                Log::info('FileCategorizationRAG: Retired unlinked genealogy media projection', [
                    'tree_id' => $treeId, 'media_id' => $mediaId, 'document_id' => $documentId,
                    'reason' => $reason, 'actor' => $actor,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            return [...$base, 'success' => false, 'eligible' => false, 'error' => $e->getMessage()];
        }
    }

    /** Preserve an obsolete source document as a canonical duplicate alias. */
    public function redirectUnlinkedGenealogyMediaProjection(
        int $treeId,
        int $dropMediaId,
        int $keepMediaId,
        int $documentId,
        string $reason,
        bool $dryRun = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        $base = ['tool' => 'redirect_unlinked_genealogy_media_projection', 'tree_id' => $treeId,
            'drop_media_id' => $dropMediaId, 'keep_media_id' => $keepMediaId, 'document_id' => $documentId,
            'dry_run' => $dryRun, 'applied' => false, 'deleted' => 0];
        if (min($treeId, $dropMediaId, $keepMediaId, $documentId) <= 0 || $dropMediaId === $keepMediaId
            || trim($reason) === '' || (! $dryRun && ! $confirm)) {
            return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'positive_distinct_ids_reason_and_confirm_required'];
        }

        try {
            return DB::transaction(function () use ($treeId, $dropMediaId, $keepMediaId, $documentId, $reason, $dryRun, $actor, $base): array {
                $media = DB::table('genealogy_media')->where('tree_id', $treeId)->whereIn('id', [$dropMediaId, $keepMediaId])
                    ->orderBy('id')->lockForUpdate()->get(['id', 'tree_id', 'nextcloud_path'])->keyBy('id');
                if (! isset($media[$dropMediaId], $media[$keepMediaId])) {
                    return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'both_media_required_in_tree'];
                }
                $drop = $media[$dropMediaId];
                $keep = $media[$keepMediaId];
                $mediaReferences = $this->genealogyMediaReferenceCounts($dropMediaId, $drop->nextcloud_path);
                if (array_sum($mediaReferences) > 0) {
                    return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'genealogy_media_dependencies_present', 'references' => $mediaReferences];
                }
                $dropPath = $this->genealogyProjectionDiskPath($drop->nextcloud_path);
                $keepPath = $this->genealogyProjectionDiskPath($keep->nextcloud_path);
                if ($dropPath === null || $keepPath === null) {
                    return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'readable_files_inside_configured_nextcloud_root_required'];
                }
                $hash = hash_file('sha256', $dropPath);
                if (! is_string($hash) || $hash !== hash_file('sha256', $keepPath)) {
                    return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'physical_duplicate_hash_mismatch'];
                }
                $connection = DB::connection('pgsql_rag');

                return $connection->transaction(function () use ($connection, $treeId, $dropMediaId, $keepMediaId, $documentId, $keep, $hash, $reason, $dryRun, $actor, $base): array {
                    $sourceId = 'gen_media_'.$dropMediaId;
                    $document = $connection->selectOne("SELECT id FROM rag_documents WHERE id = ? AND document_type = ? AND source_type = ? AND source_id = ?
                        AND metadata->>'genealogy_media_id' = ? AND metadata->>'tree_id' = ? FOR UPDATE",
                        [$documentId, self::RAG_DOCUMENT_TYPE, 'genealogy_media', $sourceId, (string) $dropMediaId, (string) $treeId]);
                    $count = $connection->selectOne('SELECT COUNT(*) AS source_count FROM rag_documents WHERE document_type = ? AND source_type = ? AND source_id = ?',
                        [self::RAG_DOCUMENT_TYPE, 'genealogy_media', $sourceId]);
                    if (! $document || (int) ($count->source_count ?? 0) !== 1) {
                        return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'unique_standalone_projection_identity_required'];
                    }
                    $references = $this->projectionReferenceCounts($connection, $documentId);
                    if (array_sum($references['blocking_references']) > 0) {
                        return [...$base, 'success' => false, 'eligible' => false, 'reason' => 'independent_provenance_references_present', ...$references];
                    }
                    $metadata = ['genealogy_media_id' => $keepMediaId, 'canonical_genealogy_media_id' => $keepMediaId,
                        'retired_genealogy_media_id' => $dropMediaId, 'retired_duplicate' => true,
                        'nextcloud_path' => $keep->nextcloud_path, 'tree_id' => $treeId, 'duplicate_sha256' => $hash];
                    $content = "Retired duplicate genealogy media #{$dropMediaId}.\nCanonical media #{$keepMediaId}.\nCanonical path: {$keep->nextcloud_path}\nSHA-256: {$hash}\nHistorical source alias: {$sourceId}.";
                    $plan = [...$base, 'success' => true, 'eligible' => true, 'protected_history_retained' => true,
                        'derived_expiration_expected' => true, 'source_id' => $sourceId, 'sha256' => $hash,
                        'canonical_path' => $keep->nextcloud_path, 'planned_content' => $content, 'planned_metadata' => $metadata, ...$references];
                    if ($dryRun) {
                        return $plan;
                    }
                    $updated = $this->ragService->indexDocument(self::RAG_DOCUMENT_TYPE, $content,
                        'Retired duplicate genealogy media #'.$dropMediaId, $metadata, $sourceId, 'genealogy_media', null, null,
                        ['source_identity_upsert' => true]);
                    if ((int) ($updated->id ?? 0) !== $documentId) {
                        throw new \RuntimeException('Source identity upsert did not preserve the exact document ID.');
                    }
                    Log::info('FileCategorizationRAG: Redirected unlinked duplicate genealogy projection', [
                        'tree_id' => $treeId, 'drop_media_id' => $dropMediaId, 'keep_media_id' => $keepMediaId,
                        'document_id' => $documentId, 'sha256' => $hash, 'reason' => $reason, 'actor' => $actor]);

                    return [...$plan, 'applied' => true, 'rag_id' => $documentId];
                });
            });
        } catch (\Exception $e) {
            return [...$base, 'success' => false, 'eligible' => false, 'error' => $e->getMessage()];
        }
    }

    private function genealogyProjectionDiskPath(?string $path): ?string
    {
        $configured = trim((string) config('services.nextcloud.data_path', ''));
        $root = $configured === '' ? false : realpath($configured);
        if (! $root || ! $path) {
            return null;
        }
        $physical = realpath(is_file($path) ? $path : $root.'/'.ltrim($path, '/'));

        return $physical && str_starts_with($physical, rtrim($root, '/').'/') && is_file($physical) && is_readable($physical)
            ? $physical : null;
    }

    private function genealogyMediaReferenceCounts(int $mediaId, ?string $path): array
    {
        return (array) DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM genealogy_person_media WHERE media_id = ?) AS person_links,
                        (SELECT COUNT(*) FROM genealogy_family_media WHERE media_id = ?) AS family_links,
                        (SELECT COUNT(*) FROM genealogy_citations WHERE media_id = ?) AS citations,
                        (SELECT COUNT(*) FROM genealogy_persons WHERE primary_photo_id = ?) AS primary_refs,
                        (SELECT COUNT(*) FROM genealogy_face_match_queue WHERE media_id = ?) AS face_queue_rows,
                        (SELECT COUNT(*) FROM genealogy_media_files WHERE media_id = ?) AS media_files,
                        (SELECT COUNT(*) FROM genealogy_media_crops WHERE media_id = ?) AS media_crops,
                        (SELECT COUNT(*) FROM genealogy_media_scan_log WHERE nextcloud_path = ?) AS scan_log_rows
                ', [$mediaId, $mediaId, $mediaId, $mediaId, $mediaId, $mediaId, $mediaId, $path]);
    }

    private function projectionReferenceCounts(ConnectionInterface $connection, int $documentId): array
    {
        $references = $this->sourceProvenanceReferenceCounts($connection, [$documentId]);
        $references['linked_documents'] = (int) ($connection->selectOne('
            SELECT COUNT(*) AS count FROM rag_documents
            WHERE parent_id = ? OR near_duplicate_of = ? OR dedup_matched_id = ?
        ', [$documentId, $documentId, $documentId])->count ?? 0);
        $foreignKeys = $connection->select("
            SELECT c.conname AS constraint_name, n.nspname AS table_schema,
                   t.relname AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN unnest(c.conkey) WITH ORDINALITY k(attnum, ord) ON true
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
            JOIN pg_attribute target ON target.attrelid = c.confrelid AND target.attnum = c.confkey[k.ord]
            WHERE c.contype = 'f' AND c.confrelid = 'public.rag_documents'::regclass
              AND target.attname = 'id'
        ");
        foreach ($foreignKeys as $foreignKey) {
            $quote = static fn (string $value): string => '"'.str_replace('"', '""', $value).'"';
            $table = $quote($foreignKey->table_schema).'.'.$quote($foreignKey->table_name);
            $column = $quote($foreignKey->column_name);
            $references['foreign_key:'.$foreignKey->constraint_name] = (int) ($connection->selectOne(
                "SELECT COUNT(*) AS count FROM {$table} WHERE {$column} = ?", [$documentId]
            )->count ?? 0);
        }
        $blocking = $references;
        unset($blocking['graph_triples'], $blocking['graph_hyperedges']);
        // These are exactly the derived tables invalidated by SemDeDupService.
        $derived = [
            'knowledge_graph' => 'source_document_id',
            'knowledge_graph_hyperedges' => 'source_document_id',
            'rag_chunk_hypotheticals' => 'document_id',
            'rag_propositions' => 'document_id',
            'rag_sentence_embeddings' => 'document_id',
            'raptor_summaries' => 'document_id',
        ];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->table_schema === 'public' && ($derived[$foreignKey->table_name] ?? null) === $foreignKey->column_name) {
                unset($blocking['foreign_key:'.$foreignKey->constraint_name]);
            }
        }

        return ['references' => $references, 'blocking_references' => $blocking];
    }

    private function sourceProvenanceReferenceCounts(ConnectionInterface $connection, array $documentIds): array
    {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $references = $connection->selectOne("
            SELECT
                (SELECT COUNT(*) FROM knowledge_graph WHERE source_document_id IN ({$placeholders})) AS graph_triples,
                (SELECT COUNT(*) FROM knowledge_graph_hyperedges WHERE source_document_id IN ({$placeholders})) AS graph_hyperedges,
                (SELECT COUNT(*) FROM claims WHERE source_document_id IN ({$placeholders})) AS claims,
                (SELECT COUNT(*) FROM research_facts WHERE rag_document_id IN ({$placeholders})) AS research_facts
        ", [...$documentIds, ...$documentIds, ...$documentIds, ...$documentIds]);

        return [
            'graph_triples' => (int) ($references->graph_triples ?? 0),
            'graph_hyperedges' => (int) ($references->graph_hyperedges ?? 0),
            'claims' => (int) ($references->claims ?? 0),
            'research_facts' => (int) ($references->research_facts ?? 0),
        ];
    }

    private function hasUsableRegistryAnalysis(object $file): bool
    {
        $version = strtolower(trim((string) ($file->ai_analysis_version ?? '')));

        return ! str_starts_with($version, 'fail:')
            && ! str_starts_with($version, 'processing:')
            && ! in_array($version, ['stale', 'processing', 'skipped', 'failed'], true);
    }

    /**
     * Build content for RAG indexing
     */
    private function buildIndexContent(
        object $file,
        ?string $extractedText = null,
        ?array $extractedMetadata = null,
        array $options = [],
        array $reviewedFieldOutcomes = []
    ): string {
        $parts = [];
        $descriptionPreviewChars = $this->getPreviewLimit($options, self::DEFAULT_DESCRIPTION_PREVIEW_CHARS, self::BULK_DESCRIPTION_PREVIEW_CHARS);
        $keywordsPreviewChars = $this->getPreviewLimit($options, self::DEFAULT_KEYWORDS_PREVIEW_CHARS, self::BULK_KEYWORDS_PREVIEW_CHARS);
        $includeSupplemental = ! $this->isBulkMode($options);
        $includeRegistryAi = $this->hasUsableRegistryAnalysis($file);
        $textPreviewChars = $this->resolveTextPreviewChars($file, $options);
        $ocrReview = $reviewedFieldOutcomes['ocr'] ?? null;
        $ocrCurrent = is_array($ocrReview) && ($ocrReview['state'] ?? null) === 'current';
        if ($ocrCurrent) {
            // Do not allow an alternate caller-supplied copy of the OCR claim to
            // bypass an operator correction or rejection.
            $extractedText = null;
        }

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
        $captionReview = $reviewedFieldOutcomes['caption'] ?? null;
        $captionCurrent = is_array($captionReview) && ($captionReview['state'] ?? null) === 'current';
        if ($captionCurrent && in_array($captionReview['decision'] ?? null, ['accept', 'correct'], true)
            && is_string($captionReview['text'] ?? null)) {
            $parts[] = 'Operator-reviewed caption: '.substr($this->sanitizeIndexedText($captionReview['text']), 0, $descriptionPreviewChars);
        } elseif ($captionCurrent && ($captionReview['decision'] ?? null) === 'reject') {
            $parts[] = 'Operator rejected the machine-generated caption proposal.';
        } elseif ($includeRegistryAi && ! empty($file->ai_description)) {
            $parts[] = 'AI-generated description (unverified): '.substr($this->sanitizeIndexedText($file->ai_description), 0, $descriptionPreviewChars);
        }

        if (! empty($file->title)) {
            $parts[] = "Title: {$file->title}";
        }

        if (! empty($file->tags)) {
            $parts[] = "Tags: {$file->tags}";
        }

        if ($includeRegistryAi && ! empty($file->ai_tags)) {
            $parts[] = "AI-generated tags (unverified): {$file->ai_tags}";
        }

        if ($includeRegistryAi && ! empty($file->ai_document_type)) {
            $parts[] = "AI-generated document type (unverified): {$file->ai_document_type}";
        }

        if (! empty($file->date_taken)) {
            $precision = $file->date_taken_precision ?? 'exact';
            $date = (string) $file->date_taken;
            if ($precision === 'year' && preg_match('/^\d{4}/', $date)) {
                $date = substr($date, 0, 4);
            } elseif ($precision === 'month' && preg_match('/^\d{4}-\d{2}/', $date)) {
                $date = substr($date, 0, 7);
            }

            $dateSource = $file->date_taken_source ?? '';
            $estimated = preg_match('/^(?:ai_|inferred|path_extracted|filename_extracted)/i', $dateSource) === 1;
            $isScan = (bool) ($file->is_scan ?? false);
            $label = match (true) {
                $dateSource === 'file_modified' => 'File modification date',
                $dateSource === 'exif_modified' => 'Metadata modification date',
                $dateSource === 'exif_digitized' => 'Digitization date',
                $dateSource === 'scan_exif' => 'Scan date',
                $estimated => $isScan ? 'Estimated original-photo date (unverified)' : 'Estimated photo date (unverified)',
                $isScan && $dateSource !== 'user_manual' => 'Recorded scan date',
                $isScan => 'Recorded original-photo date',
                $precision !== 'exact' || ! in_array($dateSource, ['exif_original', 'user_manual'], true) => 'Recorded photo date',
                default => 'Date taken',
            };
            $parts[] = "{$label}: {$date}";
            if ($label !== 'Date taken' || ! empty($file->date_needs_review)) {
                $parts[] = 'Date evidence: precision='.$precision.'; source='.($dateSource ?: 'unspecified')
                    .'; confidence='.($file->date_taken_confidence ?? 'unspecified')
                    .'; review='.(! empty($file->date_needs_review) ? 'required' : 'not flagged');
            }
        }

        if (! empty($file->scan_date)) {
            $parts[] = "Scan/digitization date: {$file->scan_date}";
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
            if (! empty($extractedMetadata['description']) && (! $includeRegistryAi || empty($file->ai_description))) {
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
        if ($ocrCurrent && in_array($ocrReview['decision'] ?? null, ['accept', 'correct'], true)
            && is_string($ocrReview['text'] ?? null)) {
            $ocrPreview = substr($this->sanitizeIndexedText($ocrReview['text']), 0, $textPreviewChars);
            if ($ocrPreview !== '' && $textPreviewChars > 0) {
                $parts[] = "\n--- OCR Text ---";
                $parts[] = 'Operator-reviewed OCR:';
                $parts[] = $ocrPreview;
            }
        } elseif ($ocrCurrent && ($ocrReview['decision'] ?? null) === 'reject') {
            $parts[] = 'Operator rejected the machine-extracted OCR proposal.';
        } elseif ($includeRegistryAi && ! empty($file->ai_detected_text) && ! $extractedText && $textPreviewChars > 0) {
            $ocrPreview = substr($this->sanitizeIndexedText($file->ai_detected_text), 0, $textPreviewChars);
            if ($ocrPreview) {
                $parts[] = "\n--- OCR Text ---";
                $parts[] = 'Machine-extracted OCR (unverified):';
                $parts[] = $ocrPreview;
            }
        }

        if ($includeSupplemental) {
            // Face names from file_registry_faces
            try {
                $faces = DB::select("
                    SELECT DISTINCT person_name
                    FROM file_registry_faces
                    WHERE file_registry_id = ? AND source_current = 1 AND superseded_at IS NULL
                      AND person_name IS NOT NULL AND person_name != ''
                ", [$file->id]);

                if (! empty($faces)) {
                    $names = array_column(array_map(fn ($f) => (array) $f, $faces), 'person_name');
                    $parts[] = 'Stored face-label claims (identity unverified): '.implode(', ', $names);
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
                    if (! empty($genealogyMedia->gen_description) && (! $includeRegistryAi || empty($file->ai_description))) {
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
                        $parts[] = 'Associated genealogy records (pictured identity unverified): '.implode(', ', $personNames);
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
                           OR (extension IN ({$imgPlaceholders}) AND (ai_description IS NOT NULL OR EXISTS (
                               SELECT 1 FROM file_content_extraction_provenance fcep
                               WHERE fcep.file_registry_id = file_registry.id
                                 AND JSON_CONTAINS_PATH(fcep.metadata, 'one', '$._photo_field_reviews') = 1
                           ))))
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
        $limitSemantics = $this->buildSyncLimitSemantics($limit);

        $results = [
            'indexed' => 0,
            'removed' => 0,
            'errors' => 0,
            'limit_semantics' => $limitSemantics,
        ];

        try {
            $maxSeconds = $maxSeconds ?? 600;
            $maxSeconds = max(60, $maxSeconds);

            // Index new files. Orphan deletion is intentionally excluded from this
            // recurring lane; the explicit purge command owns preview and execution.
            $indexLimit = $limitSemantics['effective_index_limit'];
            $indexSeconds = max(45, (int) floor($maxSeconds * 0.8));
            $staleSeconds = max(30, $maxSeconds - $indexSeconds);

            $indexResult = $this->batchIndexFiles($indexLimit, $workerId, $indexSeconds, true);
            $results['indexed'] = $indexResult['indexed'];
            $results['errors'] += $indexResult['errors'];
            if (! empty($indexResult['time_limited'])) {
                $results['time_limited'] = true;
            }

            $results['orphan_cleanup'] = [
                'mode' => 'explicit_only',
                'preview_command' => 'files:purge-orphan-rag-documents --json',
                'execution_requires_confirmation' => true,
            ];

            // Re-index stale files only if the main indexing loop did not already
            // consume the full runtime budget.
            if (empty($results['time_limited']) && $this->shouldReindexStaleFiles($limit, $workerId)) {
                $staleResult = $this->reindexStaleFiles($limitSemantics['effective_stale_reindex_limit'], $staleSeconds);
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

    private function buildSyncLimitSemantics(int $limit): array
    {
        $indexLimit = max(10, (int) ($limit * 0.9));
        $staleReindexLimit = max(5, (int) ($limit * 0.1));

        return [
            'requested_limit' => $limit,
            'effective_index_limit' => $indexLimit,
            'effective_stale_reindex_limit' => $staleReindexLimit,
            'index_limit_expanded' => $limit > 0 && $indexLimit > $limit,
            'stale_reindex_minimum_applied' => $staleReindexLimit > (int) ($limit * 0.1),
            'stale_reindex_runs_conditionally' => true,
            'orphan_cleanup_mode' => 'explicit_only',
            'behavior_changed' => true,
        ];
    }

    /**
     * Index a genealogy_media record into RAG.
     * If the media has a matching file_registry record, uses indexFile() for full enrichment.
     * Otherwise creates a standalone RAG document from genealogy metadata.
     */
    public function indexGenealogyMediaFile(int $genealogyMediaId, bool $refreshExistingProjection = false): array
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
                $fileReg = app(FileRegistryService::class)->currentExactSourcePath($media->nextcloud_path);

                if ($fileReg) {
                    // Use full indexFile() which includes genealogy metadata enrichment
                    $result = $this->indexFile($fileReg->asset_uuid);
                    if ($result['success']) {
                        DB::update('UPDATE genealogy_media SET rag_indexed_at = NOW() WHERE id = ?', [$genealogyMediaId]);
                    }

                    if (! $result['success'] || ! $refreshExistingProjection) {
                        return $result;
                    }

                    $existingProjection = DB::connection('pgsql_rag')->selectOne("
                        SELECT id FROM rag_documents WHERE document_type = ? AND source_type = ? AND source_id = ?
                          AND metadata->>'genealogy_media_id' = ? AND metadata->>'tree_id' = ?
                    ", [self::RAG_DOCUMENT_TYPE, 'genealogy_media', 'gen_media_'.$genealogyMediaId, (string) $genealogyMediaId, (string) $media->tree_id]);
                    if (! $existingProjection) {
                        return $result;
                    }
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
                WHERE gpm.media_id = ? AND gp.tree_id = ?
            ", [$genealogyMediaId, $media->tree_id]);

            if (! empty($linkedPersons)) {
                $metadata['linked_person_ids'] = array_column(array_map(fn ($p) => (array) $p, $linkedPersons), 'person_id');
                $metadata['associated_person_names'] = array_column(array_map(fn ($p) => (array) $p, $linkedPersons), 'full_name');
                $metadata['pictured_identity_approved'] = false;
            }

            $doc = $this->ragService->indexDocument(
                self::RAG_DOCUMENT_TYPE,
                $content,
                $media->title ?? 'Genealogy Media #'.$genealogyMediaId,
                $metadata,
                'gen_media_'.$genealogyMediaId,
                'genealogy_media',
                null,
                null,
                ['source_identity_upsert' => true]
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
        if (! in_array($media->analysis_status ?? null, ['pending', 'processing', 'failed', 'skipped'], true) && ! empty($media->ai_description)) {
            $parts[] = 'AI-generated description (unverified): '.substr($media->ai_description, 0, 1000);
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
                WHERE gpm.media_id = ? AND gp.tree_id = ?
            ", [$media->id, $media->tree_id]);

            if (! empty($persons)) {
                $names = array_column(array_map(fn ($p) => (array) $p, $persons), 'full_name');
                $parts[] = 'Associated genealogy records (pictured identity unverified): '.implode(', ', $names);
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

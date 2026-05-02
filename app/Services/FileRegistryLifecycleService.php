<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FileRegistryLifecycleService
{
    private const DESCRIPTION_STATE_MARKER = '[LIFECYCLE_STATE]';

    public const BUCKET_KEEP = 'keep';

    public const BUCKET_CLEAR_ON_SOFT_DELETE = 'clear_on_soft_delete';

    public const BUCKET_REMAP_ON_MOVE = 'remap_on_move';

    public const BUCKET_DELETE_ON_HARD_PURGE = 'delete_on_hard_purge';

    /**
     * Central lifecycle matrix for file-registry-owned artifacts.
     *
     * primary_bucket is the first lifecycle action a surface participates in.
     * hard_purge_action documents final cleanup when the tombstone is purged.
     */
    private const LIFECYCLE_BUCKETS = [
        'file_registry' => [
            'primary_bucket' => self::BUCKET_KEEP,
            'identity' => 'asset_uuid',
            'move_action' => 'update current_path/path_hash and preserve path history',
            'soft_delete_action' => 'retain tombstone row with lifecycle restore state',
            'content_change_action' => 'reset derived freshness columns',
            'hard_purge_action' => self::BUCKET_DELETE_ON_HARD_PURGE,
        ],
        'file_registry.chunk_metadata' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry.id chunk_* columns',
            'content_change_action' => 'clear chunk hashes/count/timestamp tied to old bytes',
            'soft_delete_action' => 'clear chunk metadata before tombstone retention',
            'hard_purge_action' => 'deleted with file_registry row',
        ],
        'file_registry_tags' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry_id',
            'soft_delete_action' => 'archive tag names into restore state, then delete links',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_registry_faces' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry_id',
            'content_change_action' => 'delete detected faces tied to old pixels',
            'soft_delete_action' => 'delete detected faces',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_registry_perceptual_hashes' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry_id',
            'content_change_action' => 'delete hashes tied to old pixels',
            'soft_delete_action' => 'delete hashes',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_registry_video_hashes' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry_id',
            'content_change_action' => 'delete video hashes tied to old media bytes',
            'soft_delete_action' => 'delete video hashes after similar-video links',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_registry_duplicates' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'canonical_file_id|duplicate_file_id',
            'soft_delete_action' => 'delete duplicate review rows involving the file',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_registry_similar_images' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_id_a|file_id_b',
            'soft_delete_action' => 'delete similarity rows involving the file',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_registry_similar_videos' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'video_hash_id_1|video_hash_id_2 via file_registry_video_hashes',
            'soft_delete_action' => 'delete similarity rows before deleting video hashes',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_registry_path_history' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'file_registry_id',
            'move_action' => 'append previous/new path row',
            'soft_delete_action' => 'retain for tombstone audit',
            'hard_purge_action' => self::BUCKET_DELETE_ON_HARD_PURGE,
        ],
        'file_quarantine' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'file_registry_id|asset_uuid',
            'soft_delete_action' => 'retain review history through tombstone state',
            'hard_purge_action' => self::BUCKET_DELETE_ON_HARD_PURGE,
        ],
        'file_bundles' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'primary_file_id',
            'soft_delete_action' => 'archive bundle ids where file was primary, then clear primary_file_id',
            'hard_purge_action' => 'primary_file_id already cleared before hard purge',
        ],
        'file_bundle_members' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry_id',
            'soft_delete_action' => 'archive bundle ids into restore state, then delete links',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_collections' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'cover_image_uuid',
            'soft_delete_action' => 'archive collection ids where file was cover, then clear cover_image_uuid',
            'hard_purge_action' => 'cover_image_uuid already cleared before hard purge',
        ],
        'file_collection_items' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry_id',
            'soft_delete_action' => 'archive collection ids into restore state, then delete links',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'file_versions' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'file_registry_id',
            'soft_delete_action' => 'retain version provenance through tombstone state',
            'hard_purge_action' => self::BUCKET_DELETE_ON_HARD_PURGE,
        ],
        'disk_thumbnails' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'asset_uuid',
            'content_change_action' => 'delete thumbnails tied to old bytes',
            'soft_delete_action' => 'delete thumbnail files',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'pgsql_rag.rag_documents' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => "metadata->>'asset_uuid'|metadata->>'file_path'",
            'move_action' => 'remap file path metadata for matching asset/path',
            'content_change_action' => 'delete stale file_catalog document and reset file_registry.rag_indexed_at',
            'soft_delete_action' => 'delete file_catalog document by asset_uuid',
            'hard_purge_action' => 'delete indirect references by asset_uuid/file_path',
        ],
        'pgsql_rag.face_embeddings' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_registry_id',
            'content_change_action' => 'delete embeddings tied to old face detections',
            'soft_delete_action' => 'delete embeddings',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'pgsql_rag.file_semantic_embeddings' => [
            'primary_bucket' => self::BUCKET_CLEAR_ON_SOFT_DELETE,
            'identity' => 'file_id',
            'content_change_action' => 'delete semantic chunks tied to old extracted content',
            'soft_delete_action' => 'delete semantic chunks',
            'hard_purge_action' => 'already cleared before hard purge',
        ],
        'genealogy_media_scan_log' => [
            'primary_bucket' => self::BUCKET_REMAP_ON_MOVE,
            'identity' => 'nextcloud_path',
            'move_action' => 'remap nextcloud_path',
            'soft_delete_action' => 'retain scan evidence through tombstone state',
            'hard_purge_action' => self::BUCKET_DELETE_ON_HARD_PURGE,
        ],
        'genealogy_media' => [
            'primary_bucket' => self::BUCKET_REMAP_ON_MOVE,
            'identity' => 'nextcloud_path',
            'move_action' => 'remap nextcloud_path and updated_at',
            'soft_delete_action' => 'retain media row through tombstone state',
            'hard_purge_action' => self::BUCKET_DELETE_ON_HARD_PURGE,
        ],
        'genealogy_persons' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'primary_photo_id via genealogy_media.nextcloud_path',
            'soft_delete_action' => 'retain primary photo references while media row is retained',
            'hard_purge_action' => 'clear primary_photo_id before deleting genealogy_media rows',
        ],
        'genealogy_citations' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'media_id via genealogy_media.nextcloud_path',
            'soft_delete_action' => 'retain citation media references while media row is retained',
            'hard_purge_action' => 'clear nullable media_id before deleting genealogy_media rows',
        ],
        'genealogy_face_match_queue' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'media_id via genealogy_media.nextcloud_path',
            'soft_delete_action' => 'retain review queue references while media row is retained',
            'hard_purge_action' => 'delete media-owned review rows before deleting genealogy_media rows',
        ],
        'genealogy_person_media' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'media_id via genealogy_media.nextcloud_path',
            'soft_delete_action' => 'retain person media links while media row is retained',
            'hard_purge_action' => 'delete media-owned links before deleting genealogy_media rows',
        ],
        'genealogy_family_media' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'media_id via genealogy_media.nextcloud_path',
            'soft_delete_action' => 'retain family media links while media row is retained',
            'hard_purge_action' => 'delete media-owned links before deleting genealogy_media rows',
        ],
        'genealogy_media_files' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'media_id via genealogy_media.nextcloud_path',
            'soft_delete_action' => 'retain GEDCOM media files while media row is retained',
            'hard_purge_action' => 'delete media-owned files before deleting genealogy_media rows',
        ],
        'genealogy_media_crops' => [
            'primary_bucket' => self::BUCKET_DELETE_ON_HARD_PURGE,
            'identity' => 'media_id via genealogy_media.nextcloud_path',
            'soft_delete_action' => 'retain GEDCOM media crops while media row is retained',
            'hard_purge_action' => 'delete media-owned crops before deleting genealogy_media rows',
        ],
    ];

    public function __construct(
        private ThumbnailService $thumbnailService,
        private FileCategorizationRAGService $fileCategorizationRag
    ) {}

    public static function lifecycleBuckets(): array
    {
        return self::LIFECYCLE_BUCKETS;
    }

    public static function sameContentNewIdentityPolicy(?object $duplicateOf, ?int $newNextcloudFileid): array
    {
        if (! $duplicateOf) {
            return [
                'outcome' => 'new_unique_asset',
                'action' => 'register_new_asset',
                'requires_review' => false,
            ];
        }

        $existingFileid = isset($duplicateOf->nextcloud_fileid) ? (int) $duplicateOf->nextcloud_fileid : null;
        if ($existingFileid && $newNextcloudFileid && $existingFileid === $newNextcloudFileid) {
            return [
                'outcome' => 'same_stable_identity',
                'action' => 'active_remap',
                'requires_review' => false,
                'existing_asset_uuid' => $duplicateOf->asset_uuid ?? null,
            ];
        }

        return [
            'outcome' => 'same_content_new_identity',
            'action' => 'register_new_asset_and_link_duplicate_for_review',
            'requires_review' => true,
            'existing_asset_uuid' => $duplicateOf->asset_uuid ?? null,
        ];
    }

    /**
     * Record and apply a detected path move while preserving path history.
     */
    public function remapFilePath(string $assetUuid, string $newPath, string $movedBy = 'system', ?string $reason = null): bool
    {
        $newPath = '/'.ltrim($newPath, '/');
        $newPathHash = hash('sha256', $newPath);

        $file = DB::selectOne('
            SELECT id, current_path, path_hash
            FROM file_registry
            WHERE asset_uuid = ?
        ', [$assetUuid]);

        if (! $file) {
            Log::warning('FileRegistryLifecycle: Cannot update path - asset not found', ['asset_uuid' => $assetUuid]);

            return false;
        }

        if ($file->current_path === $newPath) {
            return true;
        }

        DB::insert('
            INSERT INTO file_registry_path_history (
                file_registry_id, previous_path, new_path, moved_by, move_reason, moved_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ', [$file->id, $file->current_path, $newPath, $movedBy, $reason]);

        DB::update('
            UPDATE file_registry
            SET current_path = ?, path_hash = ?, path_updated_at = NOW(), updated_at = NOW()
            WHERE asset_uuid = ?
        ', [$newPath, $newPathHash, $assetUuid]);

        $this->remapOperationalPathReferences($assetUuid, $file->current_path, $newPath);

        Log::info('FileRegistryLifecycle: Path updated', [
            'asset_uuid' => $assetUuid,
            'old_path' => $file->current_path,
            'new_path' => $newPath,
            'moved_by' => $movedBy,
        ]);

        return true;
    }

    /**
     * Mark a file as deleted while preserving the tombstone row for auditability.
     */
    public function markAsDeleted(string $assetUuid, string $reason = 'File not found'): bool
    {
        $file = DB::selectOne('
            SELECT id, asset_uuid, current_path, description
            FROM file_registry
            WHERE asset_uuid = ?
            LIMIT 1
        ', [$assetUuid]);

        if (! $file) {
            return false;
        }

        $restoreState = $this->collectSoftDeleteRestoreState($file->id);

        $affected = DB::update("
            UPDATE file_registry
            SET status = 'deleted',
                description = CONCAT(COALESCE(description, ''), '\n[DELETED] ', NOW(), ': ', ?),
                updated_at = NOW()
            WHERE asset_uuid = ?
        ", [$reason, $assetUuid]);

        if ($affected) {
            $this->persistSoftDeleteRestoreState($file->id, $file->description, $restoreState);
            $cascade = $this->cascadeDeleteArtifacts($file);
            Log::info('FileRegistryLifecycle: File marked as deleted', [
                'asset_uuid' => $assetUuid,
                'reason' => $reason,
                'restore_state' => $restoreState,
                'cascade' => $cascade,
            ]);
        }

        return $affected > 0;
    }

    /**
     * Centralized delete path for user-requested file deletion.
     * Soft delete preserves genealogy/path audit surfaces for later restore.
     */
    public function deleteFileFromRegistry(string $assetUuid, ?string $currentPath = null, string $reason = 'Deleted by user'): bool
    {
        $deleted = $this->markAsDeleted($assetUuid, $reason);

        return $deleted;
    }

    /**
     * Hard purge removes the tombstone plus preserved provenance and known path-linked records.
     */
    public function hardPurgeFileFromRegistry(string $assetUuid, ?string $currentPath = null, bool $force = false): bool
    {
        $file = DB::selectOne('
            SELECT id, asset_uuid, current_path, status, updated_at
            FROM file_registry
            WHERE asset_uuid = ?
            LIMIT 1
        ', [$assetUuid]);

        if (! $file) {
            return false;
        }

        $eligibility = $this->hardPurgeEligibility($file, $force);
        if (! $eligibility['allowed']) {
            Log::warning('FileRegistryLifecycle: Hard purge blocked by eligibility guard', [
                'asset_uuid' => $assetUuid,
                'status' => $file->status ?? null,
                'reason' => $eligibility['reason'],
                'retention_days' => $eligibility['retention_days'] ?? null,
                'purge_after' => $eligibility['purge_after'] ?? null,
            ]);

            return false;
        }

        $path = $currentPath ?: $file->current_path;

        try {
            DB::delete('DELETE FROM genealogy_media_scan_log WHERE nextcloud_path = ?', [$path]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to purge genealogy media scan log', [
                'asset_uuid' => $assetUuid,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->purgeGenealogyMediaRowsForPath($path);
            DB::delete('DELETE FROM genealogy_media WHERE nextcloud_path = ?', [$path]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to purge genealogy media rows', [
                'asset_uuid' => $assetUuid,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            DB::delete('DELETE FROM file_versions WHERE file_registry_id = ?', [$file->id]);
            DB::delete('DELETE FROM file_registry_path_history WHERE file_registry_id = ?', [$file->id]);
            DB::delete('DELETE FROM file_quarantine WHERE file_registry_id = ? OR asset_uuid = ?', [$file->id, $assetUuid]);
            DB::update('UPDATE file_bundles SET primary_file_id = NULL WHERE primary_file_id = ?', [$file->id]);
            DB::update('UPDATE file_collections SET cover_image_uuid = NULL, updated_at = NOW() WHERE cover_image_uuid = ?', [$assetUuid]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to purge first-class lifecycle history rows', [
                'asset_uuid' => $assetUuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $ragDocumentIds = $this->ragDocumentIdsForFile($assetUuid, $path, (int) $file->id);
            $this->invalidateKnowledgeGraphForRagDocuments($ragDocumentIds, 'file_hard_purged');

            $this->deleteRagDocumentsForFile($assetUuid, $path, (int) $file->id);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to purge indirect RAG references', [
                'asset_uuid' => $assetUuid,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        $deleted = DB::delete('DELETE FROM file_registry WHERE asset_uuid = ?', [$assetUuid]) > 0;

        if ($deleted) {
            Log::info('FileRegistryLifecycle: File hard purged', [
                'asset_uuid' => $assetUuid,
                'path' => $path,
            ]);
        }

        return $deleted;
    }

    public function canHardPurgeFile(string $assetUuid, bool $force = false): array
    {
        $file = DB::selectOne('
            SELECT id, asset_uuid, current_path, status, updated_at
            FROM file_registry
            WHERE asset_uuid = ?
            LIMIT 1
        ', [$assetUuid]);

        if (! $file) {
            return [
                'allowed' => false,
                'reason' => 'not_found',
            ];
        }

        return $this->hardPurgeEligibility($file, $force);
    }

    private function hardPurgeEligibility(object $file, bool $force): array
    {
        if ($force) {
            return [
                'allowed' => true,
                'reason' => 'force_override',
                'force' => true,
            ];
        }

        $retentionDays = $this->hardPurgeRetentionDays();
        if (($file->status ?? null) === 'deleted') {
            if ($retentionDays <= 0) {
                return [
                    'allowed' => true,
                    'reason' => 'deleted_tombstone',
                    'force' => false,
                    'retention_days' => $retentionDays,
                ];
            }

            if (empty($file->updated_at)) {
                return [
                    'allowed' => false,
                    'reason' => 'missing_deleted_timestamp',
                    'force' => false,
                    'retention_days' => $retentionDays,
                ];
            }

            $deletedAt = Carbon::parse($file->updated_at);
            $purgeAfter = $deletedAt->copy()->addDays($retentionDays);
            if ($purgeAfter->isFuture()) {
                return [
                    'allowed' => false,
                    'reason' => 'retention_window_active',
                    'force' => false,
                    'retention_days' => $retentionDays,
                    'purge_after' => $purgeAfter->toDateTimeString(),
                ];
            }

            return [
                'allowed' => true,
                'reason' => 'deleted_tombstone_retained',
                'force' => false,
                'retention_days' => $retentionDays,
                'purge_after' => $purgeAfter->toDateTimeString(),
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'not_deleted_tombstone',
            'force' => false,
        ];
    }

    private function hardPurgeRetentionDays(): int
    {
        return max(0, (int) config('file_lifecycle.hard_purge_retention_days', 7));
    }

    /**
     * Invalidate derived data when file content changes in place.
     */
    public function invalidateDerivedData(int $fileRegistryId, string $assetUuid, bool $removeRagDocument = true): array
    {
        $invalidated = [
            'thumbnail' => false,
            'perceptual_hashes' => 0,
            'faces_mysql' => 0,
            'face_embeddings_pg' => 0,
            'file_semantic_embeddings_pg' => 0,
            'chunk_fields_reset' => false,
            'rag_freshness_reset' => false,
            'rag_docs_deleted' => 0,
            'knowledge_graph_triples_expired' => 0,
            'knowledge_graph_hyperedges_deleted' => 0,
        ];

        DB::update('
            UPDATE file_registry
            SET thumbnail_generated_at = NULL,
                thumbnail_error = NULL,
                face_scan_at = NULL,
                face_count = NULL,
                content_hash = NULL,
                content_hash_verified_at = NULL,
                chunk_hashes = NULL,
                chunk_count = NULL,
                chunked_at = NULL,
                semantic_indexed_at = NULL,
                semantic_chunk_count = 0,
                rag_indexed_at = NULL,
                updated_at = NOW()
            WHERE id = ?
        ', [$fileRegistryId]);
        $invalidated['thumbnail'] = true;
        $invalidated['chunk_fields_reset'] = true;
        $invalidated['rag_freshness_reset'] = true;

        $invalidated['perceptual_hashes'] = DB::delete('
            DELETE FROM file_registry_perceptual_hashes WHERE file_registry_id = ?
        ', [$fileRegistryId]);

        $invalidated['faces_mysql'] = DB::delete('
            DELETE FROM file_registry_faces WHERE file_registry_id = ?
        ', [$fileRegistryId]);

        try {
            $invalidated['face_embeddings_pg'] = DB::connection('pgsql_rag')->delete('
                DELETE FROM face_embeddings WHERE file_registry_id = ?
            ', [$fileRegistryId]);
        } catch (Exception $e) {
            Log::warning("Failed to delete face embeddings for file {$assetUuid}: ".$e->getMessage());
        }

        try {
            $invalidated['file_semantic_embeddings_pg'] = DB::connection('pgsql_rag')->delete('
                DELETE FROM file_semantic_embeddings WHERE file_id = ?
            ', [$fileRegistryId]);
        } catch (Exception $e) {
            Log::warning("Failed to delete semantic embeddings for file {$assetUuid}: ".$e->getMessage());
        }

        if ($removeRagDocument) {
            $ragDocumentIds = $this->ragDocumentIdsForFile($assetUuid, null, $fileRegistryId);
            $graphInvalidation = $this->invalidateKnowledgeGraphForRagDocuments($ragDocumentIds, 'file_content_changed');
            $invalidated['knowledge_graph_triples_expired'] = $graphInvalidation['triples_expired'];
            $invalidated['knowledge_graph_hyperedges_deleted'] = $graphInvalidation['hyperedges_deleted'];

            try {
                $ragResult = $this->fileCategorizationRag->removeFile($assetUuid);
                $invalidated['rag_docs_deleted'] = (int) ($ragResult['deleted'] ?? 0)
                    + $this->deleteRagDocumentsForFile($assetUuid, null, $fileRegistryId);
            } catch (Exception $e) {
                Log::warning('Failed to delete stale RAG document for changed file '.$assetUuid.': '.$e->getMessage());
            }
        }

        Log::info("Invalidated derived data for file {$assetUuid}", $invalidated);

        return $invalidated;
    }

    private function purgeGenealogyMediaRowsForPath(string $path): void
    {
        $mediaIds = array_map(
            fn ($row) => (int) $row->id,
            DB::select('SELECT id FROM genealogy_media WHERE nextcloud_path = ?', [$path])
        );

        if (empty($mediaIds)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($mediaIds), '?'));

        DB::update("UPDATE genealogy_persons SET primary_photo_id = NULL WHERE primary_photo_id IN ({$placeholders})", $mediaIds);
        DB::update("UPDATE genealogy_citations SET media_id = NULL WHERE media_id IN ({$placeholders})", $mediaIds);
        DB::delete("DELETE FROM genealogy_face_match_queue WHERE media_id IN ({$placeholders})", $mediaIds);
        DB::delete("DELETE FROM genealogy_person_media WHERE media_id IN ({$placeholders})", $mediaIds);
        DB::delete("DELETE FROM genealogy_family_media WHERE media_id IN ({$placeholders})", $mediaIds);
        DB::delete("DELETE FROM genealogy_media_files WHERE media_id IN ({$placeholders})", $mediaIds);
        DB::delete("DELETE FROM genealogy_media_crops WHERE media_id IN ({$placeholders})", $mediaIds);
    }

    private function cascadeDeleteArtifacts(object $file): array
    {
        $result = [
            'collections_deleted' => 0,
            'collection_covers_cleared' => 0,
            'bundle_members_deleted' => 0,
            'primary_bundles_cleared' => 0,
            'tags_deleted' => 0,
            'duplicate_rows_deleted' => 0,
            'similar_image_rows_deleted' => 0,
            'similar_video_rows_deleted' => 0,
            'thumbnails_deleted' => 0,
            'derived_data' => null,
            'video_hashes_deleted' => 0,
            'rag_docs_deleted' => 0,
            'knowledge_graph_triples_expired' => 0,
            'knowledge_graph_hyperedges_deleted' => 0,
        ];

        try {
            $result['collections_deleted'] = DB::delete('
                DELETE FROM file_collection_items
                WHERE file_registry_id = ?
            ', [$file->id]);
            $result['collection_covers_cleared'] = DB::update('
                UPDATE file_collections
                SET cover_image_uuid = NULL, updated_at = NOW()
                WHERE cover_image_uuid = ?
            ', [$file->asset_uuid]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete collection links during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['bundle_members_deleted'] = DB::delete('
                DELETE FROM file_bundle_members
                WHERE file_registry_id = ?
            ', [$file->id]);
            $result['primary_bundles_cleared'] = DB::update('
                UPDATE file_bundles
                SET primary_file_id = NULL
                WHERE primary_file_id = ?
            ', [$file->id]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete bundle members during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['tags_deleted'] = DB::delete('
                DELETE FROM file_registry_tags
                WHERE file_registry_id = ?
            ', [$file->id]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete file tags during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['duplicate_rows_deleted'] = DB::delete('
                DELETE FROM file_registry_duplicates
                WHERE canonical_file_id = ? OR duplicate_file_id = ?
            ', [$file->id, $file->id]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete duplicate rows during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['similar_image_rows_deleted'] = DB::delete('
                DELETE FROM file_registry_similar_images
                WHERE file_id_a = ? OR file_id_b = ?
            ', [$file->id, $file->id]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete similar image rows during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['similar_video_rows_deleted'] = DB::delete('
                DELETE FROM file_registry_similar_videos
                WHERE video_hash_id_1 IN (
                    SELECT id FROM file_registry_video_hashes WHERE file_registry_id = ?
                ) OR video_hash_id_2 IN (
                    SELECT id FROM file_registry_video_hashes WHERE file_registry_id = ?
                )
            ', [$file->id, $file->id]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete similar video rows during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['thumbnails_deleted'] = $this->thumbnailService->deleteThumbnails($file->asset_uuid);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete thumbnails during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['derived_data'] = $this->invalidateDerivedData($file->id, $file->asset_uuid, false);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to invalidate derived data during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['video_hashes_deleted'] = DB::delete('
                DELETE FROM file_registry_video_hashes
                WHERE file_registry_id = ?
            ', [$file->id]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete video hashes during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $ragDocumentIds = $this->ragDocumentIdsForFile($file->asset_uuid, $file->current_path ?? null, (int) $file->id);
            $graphInvalidation = $this->invalidateKnowledgeGraphForRagDocuments($ragDocumentIds, 'file_soft_deleted');
            $result['knowledge_graph_triples_expired'] = $graphInvalidation['triples_expired'];
            $result['knowledge_graph_hyperedges_deleted'] = $graphInvalidation['hyperedges_deleted'];

            $ragResult = $this->fileCategorizationRag->removeFile($file->asset_uuid);
            $result['rag_docs_deleted'] = (int) ($ragResult['deleted'] ?? 0)
                + $this->deleteRagDocumentsForFile($file->asset_uuid, $file->current_path ?? null, (int) $file->id);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to remove RAG documents during file deletion cascade', [
                'asset_uuid' => $file->asset_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function remapOperationalPathReferences(string $assetUuid, string $oldPath, string $newPath): void
    {
        try {
            DB::update('
                UPDATE genealogy_media_scan_log
                SET nextcloud_path = ?
                WHERE nextcloud_path = ?
            ', [$newPath, $oldPath]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to remap genealogy media scan log path', [
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            DB::update('
                UPDATE genealogy_media
                SET nextcloud_path = ?, updated_at = NOW()
                WHERE nextcloud_path = ?
            ', [$newPath, $oldPath]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to remap genealogy media path', [
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            DB::connection('pgsql_rag')->update("
                UPDATE rag_documents
                SET title = CASE WHEN document_type = 'file_catalog' THEN ? ELSE title END,
                    metadata = jsonb_set(
                        jsonb_set(
                            jsonb_set(
                                CASE
                                    WHEN jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'file_path')
                                    THEN jsonb_set(COALESCE(metadata, '{}'::jsonb), '{file_path}', to_jsonb(?::text), true)
                                    ELSE COALESCE(metadata, '{}'::jsonb)
                                END,
                                '{path}',
                                to_jsonb(?::text),
                                true
                            ),
                            '{folder_path}',
                            to_jsonb(?::text),
                            true
                        ),
                        '{filename}',
                        to_jsonb(?::text),
                        true
                    ),
                    updated_at = NOW()
                WHERE metadata->>'asset_uuid' = ?
                   OR metadata->>'path' = ?
                   OR metadata->>'file_path' = ?
            ", [
                basename($newPath),
                $newPath,
                $newPath,
                dirname($newPath),
                basename($newPath),
                $assetUuid,
                $oldPath,
                $oldPath,
            ]);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to remap RAG file metadata path', [
                'asset_uuid' => $assetUuid,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ragDocumentIdsForFile(string $assetUuid, ?string $path = null, ?int $fileRegistryId = null): array
    {
        $legacySourceId = $fileRegistryId ? "file_registry_{$fileRegistryId}" : null;

        try {
            $rows = DB::connection('pgsql_rag')->select("
                SELECT id
                FROM rag_documents
                WHERE (source_type = 'file_registry' OR document_type = 'file_catalog')
                  AND (
                    metadata->>'asset_uuid' = ?
                    OR (? IS NOT NULL AND metadata->>'file_path' = ?)
                    OR (? IS NOT NULL AND metadata->>'path' = ?)
                    OR source_id = ?
                    OR (? IS NOT NULL AND source_id = ?)
                  )
            ", [$assetUuid, $path, $path, $path, $path, $assetUuid, $legacySourceId, $legacySourceId]);

            return array_values(array_map(static fn ($row) => (int) $row->id, $rows));
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to find file RAG documents for graph invalidation', [
                'asset_uuid' => $assetUuid,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function deleteRagDocumentsForFile(string $assetUuid, ?string $path = null, ?int $fileRegistryId = null): int
    {
        $legacySourceId = $fileRegistryId ? "file_registry_{$fileRegistryId}" : null;

        return DB::connection('pgsql_rag')->delete("
            DELETE FROM rag_documents
            WHERE (source_type = 'file_registry' OR document_type = 'file_catalog')
              AND (
                metadata->>'asset_uuid' = ?
                OR (? IS NOT NULL AND metadata->>'file_path' = ?)
                OR (? IS NOT NULL AND metadata->>'path' = ?)
                OR source_id = ?
                OR (? IS NOT NULL AND source_id = ?)
              )
        ", [$assetUuid, $path, $path, $path, $path, $assetUuid, $legacySourceId, $legacySourceId]);
    }

    private function invalidateKnowledgeGraphForRagDocuments(array $ragDocumentIds, string $reason): array
    {
        $result = [
            'triples_expired' => 0,
            'hyperedges_deleted' => 0,
        ];

        $ragDocumentIds = array_values(array_unique(array_map('intval', $ragDocumentIds)));
        if ($ragDocumentIds === []) {
            return $result;
        }

        $placeholders = implode(', ', array_fill(0, count($ragDocumentIds), '?'));

        try {
            $triples = DB::connection('pgsql_rag')->select("
                SELECT id
                FROM knowledge_graph
                WHERE source_document_id IN ({$placeholders})
                  AND t_expired IS NULL
            ", $ragDocumentIds);

            $tripleIds = array_values(array_map(static fn ($row) => (int) $row->id, $triples));
            if ($tripleIds !== []) {
                $triplePlaceholders = implode(', ', array_fill(0, count($tripleIds), '?'));
                DB::connection('pgsql_rag')->update("
                    UPDATE knowledge_graph
                    SET t_expired = NOW(), updated_at = NOW()
                    WHERE id IN ({$triplePlaceholders})
                ", $tripleIds);

                foreach ($tripleIds as $tripleId) {
                    DB::connection('pgsql_rag')->insert("
                        INSERT INTO knowledge_graph_edge_history (
                            triple_id, action, old_values, reason, caused_by_triple_id, actor, created_at
                        ) VALUES (?, 'invalidated', ?::jsonb, ?, NULL, 'file_lifecycle', NOW())
                    ", [
                        $tripleId,
                        json_encode(['t_expired' => null, 'superseded_by' => null]),
                        $reason,
                    ]);
                }
            }

            $result['triples_expired'] = count($tripleIds);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to expire knowledge graph triples for file RAG documents', [
                'rag_document_ids' => $ragDocumentIds,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result['hyperedges_deleted'] = DB::connection('pgsql_rag')->delete("
                DELETE FROM knowledge_graph_hyperedges
                WHERE source_document_id IN ({$placeholders})
            ", $ragDocumentIds);
        } catch (Exception $e) {
            Log::warning('FileRegistryLifecycle: Failed to delete knowledge graph hyperedges for file RAG documents', [
                'rag_document_ids' => $ragDocumentIds,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function collectSoftDeleteRestoreState(int $fileRegistryId): array
    {
        return [
            'collection_ids' => array_map(
                fn ($row) => (int) $row->collection_id,
                DB::select('SELECT collection_id FROM file_collection_items WHERE file_registry_id = ? ORDER BY collection_id', [$fileRegistryId])
            ),
            'cover_collection_ids' => array_map(
                fn ($row) => (int) $row->id,
                DB::select('
                    SELECT fc.id
                    FROM file_collections fc
                    JOIN file_registry fr ON fr.asset_uuid = fc.cover_image_uuid
                    WHERE fr.id = ?
                    ORDER BY fc.id
                ', [$fileRegistryId])
            ),
            'bundle_ids' => array_map(
                fn ($row) => (int) $row->bundle_id,
                DB::select('SELECT bundle_id FROM file_bundle_members WHERE file_registry_id = ? ORDER BY bundle_id', [$fileRegistryId])
            ),
            'primary_bundle_ids' => array_map(
                fn ($row) => (int) $row->id,
                DB::select('SELECT id FROM file_bundles WHERE primary_file_id = ? ORDER BY id', [$fileRegistryId])
            ),
            'tags' => array_map(
                fn ($row) => (string) $row->tag,
                DB::select('SELECT tag FROM file_registry_tags WHERE file_registry_id = ? ORDER BY tag', [$fileRegistryId])
            ),
            'had_duplicate_relations' => $this->hasDuplicateRelations($fileRegistryId),
            'had_similarity_relations' => $this->hasSimilarityRelations($fileRegistryId),
            'archived_at' => now()->toIso8601String(),
        ];
    }

    private function hasDuplicateRelations(int $fileRegistryId): bool
    {
        return (bool) (DB::selectOne(
            'SELECT 1 as present FROM file_registry_duplicates WHERE canonical_file_id = ? OR duplicate_file_id = ? LIMIT 1',
            [$fileRegistryId, $fileRegistryId]
        )?->present ?? false);
    }

    private function hasSimilarityRelations(int $fileRegistryId): bool
    {
        $imageHit = (bool) (DB::selectOne(
            'SELECT 1 as present FROM file_registry_similar_images WHERE file_id_a = ? OR file_id_b = ? LIMIT 1',
            [$fileRegistryId, $fileRegistryId]
        )?->present ?? false);

        if ($imageHit) {
            return true;
        }

        return (bool) (DB::selectOne(
            'SELECT 1 as present
             FROM file_registry_similar_videos
             WHERE video_hash_id_1 IN (SELECT id FROM file_registry_video_hashes WHERE file_registry_id = ?)
                OR video_hash_id_2 IN (SELECT id FROM file_registry_video_hashes WHERE file_registry_id = ?)
             LIMIT 1',
            [$fileRegistryId, $fileRegistryId]
        )?->present ?? false);
    }

    private function persistSoftDeleteRestoreState(int $fileRegistryId, ?string $existingDescription, array $state): void
    {
        $base = $this->stripLifecycleState($existingDescription);
        $suffix = self::DESCRIPTION_STATE_MARKER.' '.json_encode([
            'restore_state' => $state,
        ], JSON_UNESCAPED_SLASHES);

        $description = trim($base);
        $description = $description === '' ? $suffix : "{$description}\n{$suffix}";

        DB::update('UPDATE file_registry SET description = ? WHERE id = ?', [$description, $fileRegistryId]);
    }

    private function stripLifecycleState(?string $description): string
    {
        $text = (string) ($description ?? '');
        $pos = strpos($text, self::DESCRIPTION_STATE_MARKER);

        if ($pos === false) {
            return rtrim($text);
        }

        return rtrim(substr($text, 0, $pos));
    }
}

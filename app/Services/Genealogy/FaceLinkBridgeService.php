<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps file-registry face links and genealogy person-media links in sync.
 */
class FaceLinkBridgeService
{
    public function syncFaceLink(int $fileRegistryFaceId, int $personId, ?int $mediaId = null): array
    {
        $face = DB::selectOne(
            'SELECT frf.id, frf.file_registry_id, frf.person_name, frf.region_x, frf.region_y, frf.region_w, frf.region_h, frf.cluster_id,
                    fr.current_path, fr.original_path, fr.filename, fr.mime_type, fr.file_size, fr.status AS file_status
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             WHERE frf.id = ?',
            [$fileRegistryFaceId]
        );

        if (! $face) {
            return ['success' => false, 'error' => 'file_registry_face not found'];
        }

        $person = DB::selectOne(
            'SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?',
            [$personId]
        );

        if (! $person) {
            return ['success' => false, 'error' => 'genealogy_person not found'];
        }

        $personName = trim(($person->given_name ?? '').' '.($person->surname ?? ''));
        DB::update(
            'UPDATE file_registry_faces
             SET genealogy_person_id = ?, person_name = COALESCE(NULLIF(person_name, \'\'), ?), verified = 1, updated_at = NOW()
             WHERE id = ?',
            [$personId, $personName, $fileRegistryFaceId]
        );

        DB::update(
            'UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?',
            [$face->file_registry_id]
        );

        $this->syncClusterPerson((int) $personId, $face);

        $media = $this->resolveMedia((int) $person->tree_id, $face, $mediaId);
        if (! $media) {
            return [
                'success' => true,
                'file_registry_face_id' => $fileRegistryFaceId,
                'person_id' => $personId,
                'media_id' => null,
                'person_media_action' => 'skipped',
                'warning' => 'genealogy_media could not be resolved',
            ];
        }

        $existing = DB::selectOne(
            'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
            [$personId, $media->id]
        );

        $coordinates = [
            $this->nullableFloat($face->region_x),
            $this->nullableFloat($face->region_y),
            $this->nullableFloat($face->region_w),
            $this->nullableFloat($face->region_h),
        ];

        if ($existing) {
            DB::update(
                'UPDATE genealogy_person_media
                 SET face_region_x = ?, face_region_y = ?, face_region_w = ?, face_region_h = ?, face_confirmed = 1
                 WHERE id = ?',
                [...$coordinates, $existing->id]
            );
            $personMediaAction = 'updated';
        } else {
            DB::insert(
                'INSERT INTO genealogy_person_media
                 (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h, face_confirmed, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                [$personId, $media->id, ...$coordinates]
            );
            $personMediaAction = 'created';
        }

        $faceCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_person_media WHERE media_id = ?',
            [$media->id]
        );

        DB::update(
            'UPDATE genealogy_media SET has_faces = 1, face_count = ?, last_face_sync_at = NOW(), updated_at = NOW() WHERE id = ?',
            [(int) ($faceCount->count ?? 1), $media->id]
        );

        return [
            'success' => true,
            'file_registry_face_id' => $fileRegistryFaceId,
            'person_id' => $personId,
            'media_id' => $media->id,
            'person_media_action' => $personMediaAction,
        ];
    }

    public function syncClusterLinks(int $clusterId, int $personId): array
    {
        $faces = DB::select(
            'SELECT id FROM file_registry_faces WHERE cluster_id = ? AND hidden = 0',
            [$clusterId]
        );

        $results = [
            'processed' => 0,
            'linked' => 0,
            'media_linked' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($faces as $face) {
            $results['processed']++;
            $result = $this->syncFaceLink((int) $face->id, $personId);
            if ($result['success'] ?? false) {
                $results['linked']++;
                if (($result['person_media_action'] ?? null) !== 'skipped') {
                    $results['media_linked']++;
                }
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'face_id' => (int) $face->id,
                    'error' => $result['error'] ?? 'unknown',
                ];
            }
        }

        return $results;
    }

    private function resolveMedia(int $treeId, object $face, ?int $mediaId): ?object
    {
        if ($mediaId !== null) {
            $media = DB::selectOne(
                'SELECT id FROM genealogy_media WHERE id = ? AND tree_id = ?',
                [$mediaId, $treeId]
            );
            if ($media) {
                return $media;
            }

            Log::warning('FaceLinkBridgeService: Ignoring media from different tree', [
                'media_id' => $mediaId,
                'tree_id' => $treeId,
                'file_registry_face_id' => $face->id,
            ]);
        }

        $path = $face->current_path ?: $face->original_path;
        if (! $path) {
            Log::warning('FaceLinkBridgeService: Cannot resolve media without a file path', [
                'file_registry_face_id' => $face->id,
            ]);

            return null;
        }

        $media = DB::selectOne(
            'SELECT id FROM genealogy_media
             WHERE tree_id = ? AND (nextcloud_path = ? OR original_path = ?)
             LIMIT 1',
            [$treeId, $path, $face->original_path]
        );
        if ($media) {
            return $media;
        }

        DB::insert(
            "INSERT INTO genealogy_media
             (tree_id, media_type, nextcloud_path, original_path, local_filename, mime_type, file_size, file_exists, has_faces, face_count, imported_at, created_at, updated_at)
             VALUES (?, 'photo', ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW(), NOW())",
            [
                $treeId,
                $path,
                $face->original_path,
                $face->filename ?: basename((string) $path),
                $face->mime_type,
                $this->normalizeFileSize($face->file_size),
                $face->file_status === 'active' ? 1 : 0,
            ]
        );

        return DB::selectOne(
            'SELECT id FROM genealogy_media WHERE tree_id = ? AND nextcloud_path = ? ORDER BY id DESC LIMIT 1',
            [$treeId, $path]
        );
    }

    private function syncClusterPerson(int $personId, object $face): void
    {
        if (empty($face->cluster_id)) {
            return;
        }

        try {
            DB::connection('pgsql_rag')->update(
                'UPDATE person_clusters SET genealogy_person_id = ?, updated_at = NOW() WHERE id = ?',
                [$personId, $face->cluster_id]
            );
        } catch (Throwable $e) {
            Log::warning('FaceLinkBridgeService: Cluster link sync failed', [
                'cluster_id' => $face->cluster_id,
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function normalizeFileSize(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(0, min((int) $value, 4294967295));
    }
}

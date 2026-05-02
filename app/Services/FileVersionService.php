<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class FileVersionService
{
    private ?NextcloudFileApiService $nextcloud = null;

    private function getNextcloud(): NextcloudFileApiService
    {
        if ($this->nextcloud === null) {
            $this->nextcloud = app(NextcloudFileApiService::class);
        }
        return $this->nextcloud;
    }

    public function recordVersion(int $fileRegistryId, string $path, ?int $size = null, ?string $hash = null, ?string $description = null): int
    {
        $latest = DB::selectOne(
            "SELECT MAX(version_number) as max_version FROM file_versions WHERE file_registry_id = ?",
            [$fileRegistryId]
        );
        $nextVersion = ($latest->max_version ?? 0) + 1;

        DB::insert(
            "INSERT INTO file_versions (file_registry_id, version_number, nextcloud_path, file_size, content_hash, change_description, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$fileRegistryId, $nextVersion, $path, $size, $hash, $description]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function getVersionHistory(int $fileRegistryId): array
    {
        return DB::select(
            "SELECT * FROM file_versions WHERE file_registry_id = ? ORDER BY version_number DESC",
            [$fileRegistryId]
        );
    }

    public function rollbackToVersion(int $fileRegistryId, int $versionId): array
    {
        $version = DB::selectOne(
            "SELECT * FROM file_versions WHERE id = ? AND file_registry_id = ?",
            [$versionId, $fileRegistryId]
        );

        if (!$version) {
            return ['success' => false, 'error' => 'Version not found'];
        }

        $current = DB::selectOne(
            "SELECT current_path FROM file_registry WHERE id = ?",
            [$fileRegistryId]
        );

        if (!$current) {
            return ['success' => false, 'error' => 'File not found in registry'];
        }

        // Record current state as new version before rollback
        $this->recordVersion(
            $fileRegistryId,
            $current->current_path,
            null,
            null,
            "Pre-rollback snapshot (rolling back to v{$version->version_number})"
        );

        Log::info('FileVersion: Rollback initiated', [
            'file_registry_id' => $fileRegistryId,
            'target_version' => $version->version_number,
            'target_path' => $version->nextcloud_path,
        ]);

        return [
            'success' => true,
            'rolled_back_to' => $version->version_number,
            'path' => $version->nextcloud_path,
        ];
    }

    public function compareVersions(int $v1Id, int $v2Id): array
    {
        $v1 = DB::selectOne("SELECT * FROM file_versions WHERE id = ?", [$v1Id]);
        $v2 = DB::selectOne("SELECT * FROM file_versions WHERE id = ?", [$v2Id]);

        if (!$v1 || !$v2) {
            return ['error' => 'Version not found'];
        }

        return [
            'v1' => ['version' => $v1->version_number, 'size' => $v1->file_size, 'hash' => $v1->content_hash, 'path' => $v1->nextcloud_path],
            'v2' => ['version' => $v2->version_number, 'size' => $v2->file_size, 'hash' => $v2->content_hash, 'path' => $v2->nextcloud_path],
            'size_diff' => ($v2->file_size ?? 0) - ($v1->file_size ?? 0),
            'hash_match' => $v1->content_hash === $v2->content_hash && $v1->content_hash !== null,
            'path_changed' => $v1->nextcloud_path !== $v2->nextcloud_path,
        ];
    }

    public function getStats(): array
    {
        $stats = DB::selectOne("
            SELECT COUNT(*) as total_versions,
                   COUNT(DISTINCT file_registry_id) as files_with_versions,
                   MAX(version_number) as max_version_depth
            FROM file_versions
        ");

        return [
            'total_versions' => $stats->total_versions ?? 0,
            'files_with_versions' => $stats->files_with_versions ?? 0,
            'max_version_depth' => $stats->max_version_depth ?? 0,
        ];
    }
}

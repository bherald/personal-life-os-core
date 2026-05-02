<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class FileBundleService
{
    public function detectBundles(string $path): array
    {
        // Get all files grouped by directory and stem (filename without extension)
        $files = DB::select(
            "SELECT id, current_path, filename,
                    SUBSTRING_INDEX(filename, '.', 1) as stem,
                    LOWER(SUBSTRING_INDEX(filename, '.', -1)) as ext
             FROM file_registry
             WHERE current_path LIKE ?
             ORDER BY current_path, stem",
            [$path . '%']
        );

        $groups = [];
        foreach ($files as $file) {
            $dir = dirname($file->current_path);
            $key = $dir . '/' . $file->stem;
            $groups[$key][] = $file;
        }

        $detected = [];
        foreach ($groups as $key => $group) {
            if (count($group) < 2) continue;

            $exts = array_map(fn($f) => $f->ext, $group);

            // RAW+JPG bundles
            $rawExts = ['raw', 'cr2', 'cr3', 'nef', 'arw', 'orf', 'rw2', 'dng'];
            $jpgExts = ['jpg', 'jpeg'];
            $hasRaw = !empty(array_intersect($exts, $rawExts));
            $hasJpg = !empty(array_intersect($exts, $jpgExts));

            if ($hasRaw && $hasJpg) {
                $detected[] = [
                    'type' => 'raw_jpg',
                    'stem' => basename($key),
                    'path' => dirname($key),
                    'files' => $group,
                ];
                continue;
            }

            // Video+Subtitle bundles
            $videoExts = ['mp4', 'mkv', 'avi', 'mov', 'webm'];
            $subExts = ['srt', 'vtt', 'ass', 'sub'];
            $hasVideo = !empty(array_intersect($exts, $videoExts));
            $hasSub = !empty(array_intersect($exts, $subExts));

            if ($hasVideo && $hasSub) {
                $detected[] = [
                    'type' => 'video_subtitle',
                    'stem' => basename($key),
                    'path' => dirname($key),
                    'files' => $group,
                ];
                continue;
            }

            // Document sets (same stem, multiple doc formats)
            $docExts = ['pdf', 'docx', 'doc', 'odt', 'txt', 'rtf', 'xlsx', 'csv'];
            $docMatches = array_intersect($exts, $docExts);
            if (count($docMatches) >= 2) {
                $detected[] = [
                    'type' => 'document_set',
                    'stem' => basename($key),
                    'path' => dirname($key),
                    'files' => $group,
                ];
            }
        }

        return $detected;
    }

    public function createBundle(int $primaryFileId, string $name, string $type, bool $autoDetected = false): int
    {
        DB::insert(
            "INSERT INTO file_bundles (primary_file_id, name, bundle_type, auto_detected, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$primaryFileId, $name, $type, $autoDetected ? 1 : 0]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function addToBundle(int $bundleId, int $fileRegistryId, string $role = 'related'): bool
    {
        try {
            DB::insert(
                "INSERT IGNORE INTO file_bundle_members (bundle_id, file_registry_id, role, created_at)
                 VALUES (?, ?, ?, NOW())",
                [$bundleId, $fileRegistryId, $role]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getBundles(?string $type = null, int $limit = 50): array
    {
        $params = [];
        $where = '';
        if ($type) {
            $where = 'WHERE fb.bundle_type = ?';
            $params[] = $type;
        }
        $params[] = $limit;

        return DB::select(
            "SELECT fb.*, COUNT(fbm.id) as member_count,
                    fr.current_path as primary_path, fr.filename as primary_filename
             FROM file_bundles fb
             LEFT JOIN file_bundle_members fbm ON fbm.bundle_id = fb.id
             LEFT JOIN file_registry fr ON fr.id = fb.primary_file_id
             {$where}
             GROUP BY fb.id
             ORDER BY fb.created_at DESC
             LIMIT ?",
            $params
        );
    }

    public function getBundle(int $id): ?array
    {
        $bundle = DB::selectOne("SELECT * FROM file_bundles WHERE id = ?", [$id]);
        if (!$bundle) return null;

        $members = DB::select(
            "SELECT fbm.*, fr.current_path, fr.filename, fr.file_size, fr.mime_type
             FROM file_bundle_members fbm
             JOIN file_registry fr ON fr.id = fbm.file_registry_id
             WHERE fbm.bundle_id = ?
             ORDER BY fbm.role ASC",
            [$id]
        );

        return ['bundle' => $bundle, 'members' => $members];
    }

    public function autoDetectBundles(string $path, bool $dryRun = false): array
    {
        $detected = $this->detectBundles($path);
        $created = 0;

        if ($dryRun) {
            return ['detected' => count($detected), 'created' => 0, 'dry_run' => true, 'bundles' => $detected];
        }

        foreach ($detected as $bundle) {
            $primaryFile = $bundle['files'][0];
            $bundleId = $this->createBundle(
                $primaryFile->id,
                $bundle['stem'],
                $bundle['type'],
                true
            );

            foreach ($bundle['files'] as $i => $file) {
                $role = $i === 0 ? 'primary' : 'sidecar';
                $this->addToBundle($bundleId, $file->id, $role);
            }
            $created++;
        }

        Log::info('FileBundles: Auto-detected bundles', [
            'path' => $path,
            'detected' => count($detected),
            'created' => $created,
        ]);

        return ['detected' => count($detected), 'created' => $created, 'dry_run' => false];
    }
}

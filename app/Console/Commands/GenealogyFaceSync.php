<?php

namespace App\Console\Commands;

use App\Services\FaceRegionService;
use App\Services\Genealogy\GenealogyMediaService;
use App\Services\NextcloudFileApiService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled command to incrementally scan the configured media folder for face metadata
 * and match/link faces to genealogy persons.
 *
 * Designed for ongoing scheduled execution with:
 * - Rate-limited Nextcloud downloads (configurable delay between files)
 * - Incremental scanning via scan_log (never re-scans a file unless --rescan)
 * - Batch size limit per run to avoid overloading Nextcloud
 * - Folder-by-folder processing instead of PROPFIND infinity
 * - Automatic retry on 503 with backoff
 */
class GenealogyFaceSync extends Command
{
    protected $signature = 'genealogy:face-sync
                            {--tree-id=4 : Tree ID}
                            {--folder= : Root Nextcloud folder to scan (default: configured genealogy face sync root)}
                            {--batch=100 : Max files to process per run}
                            {--delay=500 : Delay between downloads in ms}
                            {--rescan : Re-scan files already in scan log}
                            {--status : Show sync status and stats}
                            {--dry-run : List files to scan without processing}';

    protected $description = 'Incremental face metadata sync from Nextcloud media to genealogy (schedule-safe)';

    private const SUPPORTED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'bmp', 'webp'];

    private const CACHE_KEY = 'genealogy_face_sync_status';

    private const MAX_RETRIES = 3;

    public function handle(): int
    {
        $treeId = (int) $this->option('tree-id');
        $rootFolder = $this->option('folder') ?: config('genealogy.face_sync_root', '/Library/Media');
        $batchSize = (int) $this->option('batch');
        $delayMs = (int) $this->option('delay');
        $rescan = $this->option('rescan');
        $dryRun = $this->option('dry-run');

        if ($this->option('status')) {
            return $this->showStatus($treeId);
        }

        // Prevent concurrent runs
        $lockKey = "genealogy_face_sync_lock_{$treeId}";
        if (Cache::has($lockKey)) {
            $this->warn('Another face sync is already running. Use --status to check progress.');

            return Command::SUCCESS;
        }

        Cache::put($lockKey, true, now()->addHours(2));

        try {
            return $this->runSync($treeId, $rootFolder, $batchSize, $delayMs, $rescan, $dryRun);
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function runSync(int $treeId, string $rootFolder, int $batchSize, int $delayMs, bool $rescan, bool $dryRun): int
    {
        $tree = DB::selectOne('SELECT id, name FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            $this->error("Tree #{$treeId} not found.");

            return Command::FAILURE;
        }

        $this->info("Genealogy Face Sync - {$tree->name}");
        $this->info("Folder: {$rootFolder} | Batch: {$batchSize} | Delay: {$delayMs}ms");

        // Get FaceRegionService
        try {
            $faceService = app(FaceRegionService::class);
        } catch (\Exception $e) {
            $this->error('FaceRegionService not available: '.$e->getMessage());

            return Command::FAILURE;
        }

        $mediaService = app(GenealogyMediaService::class);
        $nc = app(NextcloudFileApiService::class);

        // Build list of already-scanned paths for fast lookup
        $scannedPaths = [];
        if (! $rescan) {
            $rows = DB::select(
                'SELECT nextcloud_path FROM genealogy_media_scan_log WHERE tree_id = ?',
                [$treeId]
            );
            foreach ($rows as $row) {
                $scannedPaths[$row->nextcloud_path] = true;
            }
            $this->info('Already scanned: '.count($scannedPaths).' files');
        }

        // Also skip files already imported as genealogy_media
        $importedPaths = [];
        $rows = DB::select(
            'SELECT nextcloud_path FROM genealogy_media WHERE tree_id = ? AND nextcloud_path IS NOT NULL',
            [$treeId]
        );
        foreach ($rows as $row) {
            $importedPaths[$row->nextcloud_path] = true;
        }
        $this->info('Already imported media: '.count($importedPaths).' files');

        // Discover files folder-by-folder (avoids PROPFIND infinity overload)
        $this->info('Discovering files...');
        $filesToScan = $this->discoverFiles($nc, $rootFolder, $scannedPaths, $batchSize);

        if (empty($filesToScan)) {
            $this->info('No new files to scan.');
            $this->updateStatus($treeId, 'idle', ['message' => 'No new files', 'last_run' => now()->toIso8601String()]);

            return Command::SUCCESS;
        }

        $this->info('Found '.count($filesToScan).' new files to scan.');

        if ($dryRun) {
            foreach ($filesToScan as $f) {
                $this->line('  '.$f['path']);
            }

            return Command::SUCCESS;
        }

        // Process files with rate limiting
        $stats = [
            'files_scanned' => 0,
            'files_with_faces' => 0,
            'faces_found' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'persons_linked' => 0,
            'download_errors' => 0,
        ];

        $this->updateStatus($treeId, 'running', [
            'started_at' => now()->toIso8601String(),
            'total_files' => count($filesToScan),
            'progress' => $stats,
        ]);

        foreach ($filesToScan as $index => $file) {
            $path = $file['path'];
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            // Skip non-images
            if (! in_array($ext, self::SUPPORTED_IMAGE_EXT)) {
                $this->logScan($treeId, $path, false, 0, null, $file['size'] ?? null);
                $stats['files_scanned']++;

                continue;
            }

            // Download with retry on 503
            $content = $this->downloadWithRetry($nc, $path);
            if ($content === null) {
                $stats['download_errors']++;
                $stats['failed']++;
                $this->logScan($treeId, $path, false, 0, null, null, 'Download failed (503/timeout)');
                $stats['files_scanned']++;

                // If we hit too many download errors in a row, Nextcloud is likely overloaded
                if ($stats['download_errors'] >= 10) {
                    $this->warn('Too many consecutive download errors. Stopping to let Nextcloud recover.');
                    break;
                }

                continue;
            }

            // Reset consecutive error counter on success
            $stats['download_errors'] = max(0, $stats['download_errors'] - 1);
            $stats['files_scanned']++;

            // Write to temp file for exiftool
            $tmpFile = tempnam(sys_get_temp_dir(), 'face_sync_').'.'.$ext;
            file_put_contents($tmpFile, $content);
            unset($content); // Free memory

            // Read face regions
            try {
                $regions = $faceService->readFaceRegions($tmpFile);
            } catch (\Exception $e) {
                $regions = [];
            }
            @unlink($tmpFile);

            $hasFaces = count($regions) > 0;
            $faceNames = $hasFaces ? array_map(fn ($r) => $r['name'] ?? 'Unknown', $regions) : null;

            // Log the scan
            $this->logScan($treeId, $path, $hasFaces, count($regions), $faceNames, $file['size'] ?? null);

            if (! $hasFaces) {
                // Rate limit
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                continue;
            }

            $stats['files_with_faces']++;
            $stats['faces_found'] += count($regions);

            $this->info("  FACES [{$stats['files_scanned']}/".count($filesToScan).']: '.basename($path).' => '.implode(', ', $faceNames));

            // Check if already imported
            if (isset($importedPaths[$path])) {
                // Update face data on existing media record
                $existingMedia = DB::selectOne(
                    'SELECT id, nextcloud_path FROM genealogy_media WHERE tree_id = ? AND nextcloud_path = ?',
                    [$treeId, $path]
                );
                if ($existingMedia) {
                    DB::update(
                        'UPDATE genealogy_media SET has_faces = 1, face_count = ?, updated_at = NOW() WHERE id = ?',
                        [count($regions), $existingMedia->id]
                    );
                    $this->storeFaceRegionsDirectly($existingMedia->id, $treeId, $regions);
                    $stats['skipped']++;
                }
            } else {
                // Import as new media
                $importResult = $this->importNewMedia($treeId, $tree->name, $path, $file, $regions, $nc);
                if ($importResult['success']) {
                    $stats['imported']++;
                    $stats['persons_linked'] += $importResult['persons_linked'] ?? 0;
                    $importedPaths[$path] = true;
                } else {
                    $stats['failed']++;
                }
            }

            // Update progress periodically
            if ($stats['files_scanned'] % 10 === 0) {
                $this->updateStatus($treeId, 'running', [
                    'started_at' => Cache::get(self::CACHE_KEY."_{$treeId}")['started_at'] ?? now()->toIso8601String(),
                    'total_files' => count($filesToScan),
                    'progress' => $stats,
                    'current_file' => basename($path),
                ]);
            }

            // Rate limit between downloads
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        // Final status
        $this->updateStatus($treeId, 'completed', [
            'started_at' => Cache::get(self::CACHE_KEY."_{$treeId}")['started_at'] ?? now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'results' => $stats,
        ]);

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Files Scanned', $stats['files_scanned']],
                ['Files with Faces', $stats['files_with_faces']],
                ['Total Faces Found', $stats['faces_found']],
                ['New Media Imported', $stats['imported']],
                ['Existing Updated', $stats['skipped']],
                ['Persons Linked', $stats['persons_linked']],
                ['Failed', $stats['failed']],
            ]
        );

        Log::info('genealogy:face-sync completed', [
            'tree_id' => $treeId,
            'results' => $stats,
        ]);

        $this->line(sprintf('[ITEMS_PROCESSED:%d]', $stats['files_scanned']));

        return Command::SUCCESS;
    }

    /**
     * Discover files by listing folders one level at a time (breadth-first).
     * Stops when batchSize files are collected.
     */
    private function discoverFiles(NextcloudFileApiService $nc, string $rootFolder, array $scannedPaths, int $batchSize): array
    {
        $files = [];
        $foldersToScan = [$rootFolder];
        $foldersVisited = 0;

        while (! empty($foldersToScan) && count($files) < $batchSize) {
            $folder = array_shift($foldersToScan);
            $foldersVisited++;

            try {
                $items = $nc->listFiles($folder, false);
                $listing = $items['files'] ?? $items;
            } catch (\Exception $e) {
                Log::debug('Failed to list folder', ['folder' => $folder, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($listing as $item) {
                $path = $item['path'] ?? '';
                if (empty($path)) {
                    continue;
                }

                // If it's a directory, queue for later scanning
                if (str_ends_with($path, '/')) {
                    // Skip trash and hidden folders
                    $basename = basename(rtrim($path, '/'));
                    if (str_starts_with($basename, '.') || $basename === '.dtrash') {
                        continue;
                    }
                    $foldersToScan[] = $path;

                    continue;
                }

                // Skip already-scanned files
                if (isset($scannedPaths[$path])) {
                    continue;
                }

                // Only include image files
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (! in_array($ext, self::SUPPORTED_IMAGE_EXT)) {
                    continue;
                }

                $files[] = [
                    'path' => $path,
                    'size' => $item['size'] ?? null,
                    'filename' => basename($path),
                    'extension' => $ext,
                    'mime_type' => $item['mime_type'] ?? null,
                ];

                if (count($files) >= $batchSize) {
                    break;
                }
            }

            // Brief pause between folder listings to be kind to Nextcloud
            usleep(100000); // 100ms
        }

        $this->info("Discovered files from {$foldersVisited} folders");

        return $files;
    }

    /**
     * Download file with retry on 503/timeout.
     * Uses filesystem-first read when NEXTCLOUD_DATA_PATH is configured.
     */
    private function downloadWithRetry(NextcloudFileApiService $nc, string $path): ?string
    {
        // Filesystem-first: avoid WebDAV entirely when local path available
        $localFsPath = $nc->localPath($path);
        if ($localFsPath) {
            $content = @file_get_contents($localFsPath);
            if ($content !== false && $content !== '') {
                return $content;
            }
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = $nc->downloadFile($path);
            if ($result['success'] && ! empty($result['content'])) {
                return $result['content'];
            }

            $error = $result['error'] ?? '';
            // Only retry on 503 (overloaded) or timeout
            if (str_contains($error, '503') || str_contains($error, 'timeout') || str_contains($error, 'timed out')) {
                $backoff = $attempt * 2;
                Log::debug("Nextcloud 503, backing off {$backoff}s", ['path' => $path, 'attempt' => $attempt]);
                sleep($backoff);

                continue;
            }

            // Non-retryable error (404, 403, etc.)
            return null;
        }

        return null;
    }

    /**
     * Log a scanned file to prevent re-scanning.
     */
    private function logScan(int $treeId, string $path, bool $hasFaces, int $faceCount, ?array $faceNames, ?int $fileSize, ?string $error = null): void
    {
        try {
            DB::insert(
                'INSERT INTO genealogy_media_scan_log (tree_id, nextcloud_path, scanned_at, has_faces, face_count, face_names, file_size, scan_error)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE scanned_at = NOW(), has_faces = VALUES(has_faces), face_count = VALUES(face_count),
                     face_names = VALUES(face_names), scan_error = VALUES(scan_error)',
                [$treeId, $path, $hasFaces ? 1 : 0, $faceCount, $faceNames ? json_encode($faceNames) : null, $fileSize, $error]
            );
        } catch (\Exception $e) {
            // Non-critical, just log
            Log::debug('Failed to log scan', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Store face regions directly using raw SQL (matches GenealogyMediaService::storeFaceRegions logic).
     */
    private function storeFaceRegionsDirectly(int $mediaId, int $treeId, array $faceRegions): void
    {
        $mediaService = app(GenealogyMediaService::class);

        // Use reflection to call private storeFaceRegions method
        $reflection = new \ReflectionMethod($mediaService, 'storeFaceRegions');
        $reflection->setAccessible(true);
        $reflection->invoke($mediaService, $mediaId, $faceRegions);
    }

    /**
     * Import a new media file into the genealogy system.
     * If the file has faces and is outside 101-Genealogy, copy it there first.
     */
    private function importNewMedia(int $treeId, string $treeName, string $nextcloudPath, array $fileInfo, array $faceRegions, NextcloudFileApiService $nc): array
    {
        try {
            // Get next GEDCOM ID
            $lastMedia = DB::selectOne(
                "SELECT gedcom_id FROM genealogy_media WHERE tree_id = ? AND gedcom_id LIKE 'M%' ORDER BY CAST(SUBSTRING(gedcom_id, 2) AS UNSIGNED) DESC LIMIT 1",
                [$treeId]
            );
            $nextNum = $lastMedia ? ((int) substr($lastMedia->gedcom_id, 1)) + 1 : 1;
            $gedcomId = 'M'.$nextNum;

            $ext = $fileInfo['extension'] ?? strtolower(pathinfo($nextcloudPath, PATHINFO_EXTENSION));
            $mimeType = $fileInfo['mime_type'] ?? 'image/jpeg';
            $fileSize = $fileInfo['size'] ?? 0;
            $filename = basename($nextcloudPath);

            // If file has faces and is outside 101-Genealogy, copy to family tree folder
            $finalPath = $nextcloudPath;
            $subfolder = $this->getSubfolder($ext);
            $genealogyBase = $this->genealogyBase();
            $isExternal = ! str_starts_with($nextcloudPath, $genealogyBase.'/');

            if (count($faceRegions) > 0 && $isExternal) {
                $destPath = $genealogyBase."/{$subfolder}/{$filename}";
                $destPath = $this->resolvePathCollision($destPath);

                $copyResult = $this->copyWithRetryToGenealogy($nc, $nextcloudPath, $destPath);
                if ($copyResult) {
                    $finalPath = $destPath;
                    $filename = basename($destPath);
                    Log::info('Face sync: copied matched file to genealogy', [
                        'from' => $nextcloudPath, 'to' => $finalPath,
                    ]);
                } else {
                    Log::warning('Face sync: copy failed, linking to original path', ['path' => $nextcloudPath]);
                }
            }

            $sourceFolder = dirname($finalPath);
            $mediaType = 'photo';

            DB::insert(
                "INSERT INTO genealogy_media (
                    tree_id, gedcom_id, original_path, nextcloud_path, local_filename,
                    file_format, mime_type, file_size, title, media_type,
                    file_exists, imported_at, has_faces, face_count, source_folder,
                    analysis_status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?, 'pending', NOW(), NOW())",
                [
                    $treeId, $gedcomId, $nextcloudPath, $finalPath, $filename,
                    $ext, $mimeType, $fileSize, pathinfo($filename, PATHINFO_FILENAME), $mediaType,
                    count($faceRegions) > 0 ? 1 : 0, count($faceRegions), $sourceFolder,
                ]
            );

            $mediaId = (int) DB::getPdo()->lastInsertId();

            // Store face regions and match to persons
            $this->storeFaceRegionsDirectly($mediaId, $treeId, $faceRegions);

            // Count linked persons
            $linkedCount = DB::selectOne(
                'SELECT COUNT(*) as cnt FROM genealogy_person_media WHERE media_id = ?',
                [$mediaId]
            );

            // Update tree stats
            DB::update(
                'UPDATE genealogy_trees SET media_count = (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ?), updated_at = NOW() WHERE id = ?',
                [$treeId, $treeId]
            );

            return [
                'success' => true,
                'media_id' => $mediaId,
                'persons_linked' => $linkedCount->cnt ?? 0,
                'copied_to' => $isExternal && $finalPath !== $nextcloudPath ? $finalPath : null,
            ];
        } catch (\Exception $e) {
            Log::error('Face sync import failed', ['path' => $nextcloudPath, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function getSubfolder(string $ext): string
    {
        $imageExts = ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'bmp', 'webp', 'gif'];
        $docExts = ['pdf', 'doc', 'docx', 'txt', 'html', 'htm', 'rtf'];
        if (in_array($ext, $imageExts)) {
            return 'photos';
        }
        if (in_array($ext, $docExts)) {
            return 'documents';
        }

        return 'other';
    }

    private function resolvePathCollision(string $destPath): string
    {
        $exists = DB::selectOne('SELECT id FROM genealogy_media WHERE nextcloud_path = ?', [$destPath]);
        if (! $exists) {
            return $destPath;
        }

        $dir = dirname($destPath);
        $base = pathinfo($destPath, PATHINFO_FILENAME);
        $ext = pathinfo($destPath, PATHINFO_EXTENSION);

        for ($i = 2; $i <= 999; $i++) {
            $candidate = "{$dir}/{$base}_{$i}.{$ext}";
            $exists = DB::selectOne('SELECT id FROM genealogy_media WHERE nextcloud_path = ?', [$candidate]);
            if (! $exists) {
                return $candidate;
            }
        }

        return $destPath;
    }

    private function copyWithRetryToGenealogy(NextcloudFileApiService $nc, string $source, string $dest): bool
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = $nc->copyFile($source, $dest);
            if ($result['success']) {
                return true;
            }

            $error = $result['error'] ?? '';
            if (str_contains($error, '412')) {
                return true;
            } // Already exists
            if (str_contains($error, '503') || str_contains($error, 'timeout')) {
                sleep($attempt * 3);

                continue;
            }

            return false;
        }

        return false;
    }

    private function updateStatus(int $treeId, string $status, array $data): void
    {
        Cache::put(self::CACHE_KEY."_{$treeId}", array_merge(['status' => $status], $data), now()->addHours(24));
    }

    private function genealogyBase(): string
    {
        $fallback = config('genealogy.media_consolidation_base', '/srv/genealogy/library');
        $configured = app(SystemConfigService::class)->get('genealogy.media_consolidation_base', $fallback);

        return rtrim((string) ($configured ?: $fallback), '/');
    }

    private function showStatus(int $treeId): int
    {
        $status = Cache::get(self::CACHE_KEY."_{$treeId}");

        // Scan log stats
        $logStats = DB::selectOne(
            'SELECT COUNT(*) as total, SUM(has_faces) as with_faces, SUM(face_count) as total_faces
             FROM genealogy_media_scan_log WHERE tree_id = ?',
            [$treeId]
        );

        $this->info('Genealogy Face Sync Status');
        $this->info('==========================');
        $this->info("Tree ID: {$treeId}");

        if ($status) {
            $this->info('Status: '.strtoupper($status['status'] ?? 'unknown'));
            if (isset($status['started_at'])) {
                $this->info("Started: {$status['started_at']}");
            }
            if (isset($status['completed_at'])) {
                $this->info("Completed: {$status['completed_at']}");
            }
            if (isset($status['results'])) {
                $this->newLine();
                $this->info('Last Run Results:');
                $this->table(
                    ['Metric', 'Value'],
                    array_map(fn ($k, $v) => [ucwords(str_replace('_', ' ', $k)), $v], array_keys($status['results']), array_values($status['results']))
                );
            }
        } else {
            $this->info('Status: No runs recorded');
        }

        $this->newLine();
        $this->info('Scan Log Totals:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Files Scanned (all-time)', $logStats->total ?? 0],
                ['Files with Faces', $logStats->with_faces ?? 0],
                ['Total Faces Found', $logStats->total_faces ?? 0],
            ]
        );

        // Queue stats
        $queueStats = DB::select(
            'SELECT status, COUNT(*) as cnt FROM genealogy_face_match_queue WHERE tree_id = ? GROUP BY status',
            [$treeId]
        );
        if (! empty($queueStats)) {
            $this->newLine();
            $this->info('Face Match Queue:');
            $this->table(
                ['Status', 'Count'],
                array_map(fn ($r) => [ucfirst($r->status), $r->cnt], $queueStats)
            );
        }

        return Command::SUCCESS;
    }
}

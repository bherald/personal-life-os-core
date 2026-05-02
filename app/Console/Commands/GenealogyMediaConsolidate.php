<?php

namespace App\Console\Commands;

use App\Services\NextcloudFileApiService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consolidates genealogy media into the configured genealogy media folder.
 *
 * Copies matched files from external media folders to the configured
 * family tree folder via Nextcloud WebDAV COPY, then re-links person_media records
 * to the new path. Originals are left untouched.
 *
 * This ensures GEDCOM exports include all supporting media in one unified location.
 */
class GenealogyMediaConsolidate extends Command
{
    protected $signature = 'genealogy:media-consolidate
                            {--tree-id=4 : Tree ID}
                            {--batch=50 : Max files to process per run}
                            {--delay=1000 : Delay between copies in ms}
                            {--timeout= : Wall-clock timeout in minutes (stops early before scheduler SIGALRM)}
                            {--dry-run : Show what would be copied without doing it}
                            {--status : Show consolidation progress}
                            {--unlink-only : Only unlink external person_media, no copy}';

    protected $description = 'Copy external genealogy media into the configured genealogy folder and re-link persons';

    private const DEFAULT_TIMEOUT_MINUTES = 30;

    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 300;

    private const MIN_SECONDS_FOR_NEXT_COPY = 420;

    private const MAX_RETRIES = 3;

    private bool $lastCopyTimedOut = false;

    public function handle(): int
    {
        $treeId = (int) $this->option('tree-id');
        $batchSize = (int) $this->option('batch');
        $delayMs = (int) $this->option('delay');
        $dryRun = $this->option('dry-run');

        if ($this->option('status')) {
            return $this->showStatus($treeId);
        }

        if ($this->option('unlink-only')) {
            return $this->unlinkExternal($treeId, $dryRun);
        }

        $startedAt = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();

        $tree = DB::selectOne('SELECT id, name FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            $this->error("Tree #{$treeId} not found.");

            return Command::FAILURE;
        }

        $nc = app(NextcloudFileApiService::class);
        $genealogyBase = $this->genealogyBase();

        // Get all person-media links pointing to external paths
        $externalMedia = DB::select(
            'SELECT m.id as media_id, m.nextcloud_path, m.local_filename, m.file_format, m.mime_type,
                    m.file_size, m.has_faces, m.face_count, m.gedcom_id, m.title, m.media_type,
                    m.source_folder, m.original_path,
                    GROUP_CONCAT(pm.person_id) as person_ids,
                    GROUP_CONCAT(pm.id) as link_ids
             FROM genealogy_media m
             JOIN genealogy_person_media pm ON pm.media_id = m.id
             WHERE m.tree_id = ? AND m.nextcloud_path NOT LIKE ?
             GROUP BY m.id
             ORDER BY m.id ASC
             LIMIT ?',
            [$treeId, $genealogyBase.'/%', $batchSize]
        );

        if (empty($externalMedia)) {
            $this->info('No external person-linked media to consolidate.');

            return $this->showStatus($treeId);
        }

        $this->info("Genealogy Media Consolidation - {$tree->name}");
        $this->info('Found '.count($externalMedia).' linked media files outside 101-Genealogy');
        if (! $dryRun) {
            $this->line("Runtime budget: {$deadlineSeconds}s");
        }
        $this->newLine();

        $stats = ['copied' => 0, 'relinked' => 0, 'skipped' => 0, 'failed' => 0, 'already_exists' => 0, 'time_limited' => false];

        foreach ($externalMedia as $index => $media) {
            $sourcePath = $media->nextcloud_path;
            $filename = $media->local_filename ?: basename($sourcePath);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Determine destination subfolder
            $subfolder = $this->getSubfolder($ext, $media->media_type);
            $destPath = $genealogyBase."/{$subfolder}/{$filename}";

            // Handle filename collisions
            $destPath = $this->resolveCollision($destPath, $nc);

            $personIds = $media->person_ids ? explode(',', $media->person_ids) : [];
            $linkIds = $media->link_ids ? explode(',', $media->link_ids) : [];

            if ($dryRun) {
                $this->line("  COPY: {$sourcePath}");
                $this->line("    TO: {$destPath}");
                $this->line('    Persons: '.implode(', ', $personIds));
                $this->newLine();
                $stats['copied']++;

                continue;
            }

            if ($this->shouldStopBeforeNextCopy($startedAt, $deadlineSeconds)) {
                $stats['time_limited'] = true;
                $this->warn("Stopped early to stay within runtime budget ({$deadlineSeconds}s).");

                break;
            }

            // Server-side copy via WebDAV COPY with retry
            $copied = $this->copyWithRetry($nc, $sourcePath, $destPath);

            if (! $copied) {
                $stats['failed']++;
                $this->warn("  FAIL [{$index}/".count($externalMedia)."]: {$filename} - copy failed");
                if ($this->lastCopyTimedOut) {
                    $stats['time_limited'] = true;
                    $this->warn('Stopped early after a Nextcloud COPY timeout; the next scheduled run will retry remaining media.');

                    break;
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                continue;
            }

            $stats['copied']++;

            // Create new media record pointing to the copy
            $newMediaId = $this->createCopiedMediaRecord($treeId, $media, $destPath, $subfolder);

            if (! $newMediaId) {
                $stats['failed']++;

                continue;
            }

            // Re-link all person_media to the new media record
            foreach ($linkIds as $linkId) {
                try {
                    DB::update(
                        'UPDATE genealogy_person_media SET media_id = ? WHERE id = ?',
                        [$newMediaId, (int) $linkId]
                    );
                    $stats['relinked']++;
                } catch (\Exception $e) {
                    Log::error('Failed to relink person_media', ['link_id' => $linkId, 'error' => $e->getMessage()]);
                }
            }

            // Copy face regions from old media to new media
            $this->copyFaceData($media->media_id, $newMediaId);

            $this->info("  OK [{$index}/".count($externalMedia)."]: {$filename} => {$subfolder}/ (persons: ".implode(',', $personIds).')');

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Files Copied', $stats['copied']],
                ['Links Re-pointed', $stats['relinked']],
                ['Failed', $stats['failed']],
                ['Skipped', $stats['skipped']],
            ]
        );

        if ($stats['time_limited']) {
            $this->warn('Consolidation is incomplete; the next scheduled run will continue with the remaining backlog.');
        }

        if (! $dryRun) {
            // Update tree stats
            DB::update(
                'UPDATE genealogy_trees SET media_count = (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ?), updated_at = NOW() WHERE id = ?',
                [$treeId, $treeId]
            );

            Log::info('genealogy:media-consolidate completed', ['tree_id' => $treeId, 'stats' => $stats]);
        }

        return Command::SUCCESS;
    }

    private function getSubfolder(string $ext, ?string $mediaType): string
    {
        $imageExts = ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'bmp', 'webp', 'gif'];
        $docExts = ['pdf', 'doc', 'docx', 'txt', 'html', 'htm', 'rtf', 'odt', 'csv', 'xls', 'xlsx'];
        $videoExts = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm'];
        $audioExts = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'wma'];

        if (in_array($ext, $imageExts)) {
            return 'photos';
        }
        if (in_array($ext, $docExts)) {
            return 'documents';
        }
        if (in_array($ext, $videoExts)) {
            return 'videos';
        }
        if (in_array($ext, $audioExts)) {
            return 'audio';
        }

        return 'other';
    }

    private function resolveCollision(string $destPath, NextcloudFileApiService $nc): string
    {
        // Check if a genealogy_media record already points to this path
        $exists = DB::selectOne(
            'SELECT id FROM genealogy_media WHERE nextcloud_path = ?',
            [$destPath]
        );

        if (! $exists) {
            return $destPath;
        }

        // Append a numeric suffix
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

        return $destPath; // Shouldn't happen
    }

    private function copyWithRetry(NextcloudFileApiService $nc, string $source, string $dest): bool
    {
        $this->lastCopyTimedOut = false;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = $nc->copyFile($source, $dest);

            if ($result['success']) {
                return true;
            }

            $error = $result['error'] ?? '';

            // 412 Precondition Failed = destination already exists (Overwrite: F)
            if (str_contains($error, '412')) {
                Log::debug('File already exists at destination', ['dest' => $dest]);

                return true; // Treat as success
            }

            if ($this->isTimeoutError($error)) {
                $this->lastCopyTimedOut = true;
                Log::warning('Nextcloud COPY timed out; not retrying in this run', [
                    'source' => $source,
                    'dest' => $dest,
                    'attempt' => $attempt,
                    'error' => $error,
                ]);

                return false;
            }

            if (str_contains($error, '503')) {
                $backoff = $attempt * 3;
                Log::debug("Nextcloud overloaded, backing off {$backoff}s", ['source' => $source, 'attempt' => $attempt]);
                sleep($backoff);

                continue;
            }

            // 404 = source file doesn't exist on Nextcloud (stale index)
            if (str_contains($error, '404')) {
                Log::warning('Source file not found on Nextcloud', ['source' => $source]);

                return false;
            }

            Log::warning('Copy failed', ['source' => $source, 'dest' => $dest, 'error' => $error]);

            return false;
        }

        return false;
    }

    private function createCopiedMediaRecord(int $treeId, object $oldMedia, string $newPath, string $subfolder): ?int
    {
        try {
            $filename = basename($newPath);

            DB::insert(
                "INSERT INTO genealogy_media (
                    tree_id, gedcom_id, original_path, nextcloud_path, local_filename,
                    file_format, mime_type, file_size, title, media_type,
                    file_exists, imported_at, has_faces, face_count, source_folder,
                    analysis_status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?, 'completed', NOW(), NOW())",
                [
                    $treeId,
                    $oldMedia->gedcom_id.'_c', // Suffix to avoid GEDCOM ID collision
                    $oldMedia->original_path,
                    $newPath,
                    $filename,
                    $oldMedia->file_format,
                    $oldMedia->mime_type,
                    $oldMedia->file_size,
                    $oldMedia->title,
                    $oldMedia->media_type,
                    $oldMedia->has_faces ? 1 : 0,
                    $oldMedia->face_count ?? 0,
                    $this->genealogyBase().'/'.$subfolder,
                ]
            );

            return (int) DB::getPdo()->lastInsertId();

        } catch (\Exception $e) {
            Log::error('Failed to create copied media record', ['path' => $newPath, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function copyFaceData(int $oldMediaId, int $newMediaId): void
    {
        try {
            // Copy face_regions records
            $faceRegions = DB::select(
                'SELECT person_id, face_region_x, face_region_y, face_region_w, face_region_h,
                        face_confirmed
                 FROM genealogy_person_media WHERE media_id = ?',
                [$oldMediaId]
            );

            // face_match_queue entries referencing old media - update to new
            DB::update(
                'UPDATE genealogy_face_match_queue SET media_id = ? WHERE media_id = ?',
                [$newMediaId, $oldMediaId]
            );

        } catch (\Exception $e) {
            Log::debug('Failed to copy face data', ['old' => $oldMediaId, 'new' => $newMediaId, 'error' => $e->getMessage()]);
        }
    }

    private function unlinkExternal(int $treeId, bool $dryRun): int
    {
        $genealogyBase = $this->genealogyBase();

        $count = DB::selectOne(
            'SELECT COUNT(*) as cnt
             FROM genealogy_person_media pm
             JOIN genealogy_media m ON pm.media_id = m.id
             WHERE m.tree_id = ? AND m.nextcloud_path NOT LIKE ?',
            [$treeId, $genealogyBase.'/%']
        );

        $this->info("Found {$count->cnt} person-media links pointing outside 101-Genealogy.");

        if ($count->cnt === 0) {
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $samples = DB::select(
                'SELECT m.nextcloud_path, pm.person_id
                 FROM genealogy_person_media pm
                 JOIN genealogy_media m ON pm.media_id = m.id
                 WHERE m.tree_id = ? AND m.nextcloud_path NOT LIKE ?
                 LIMIT 10',
                [$treeId, $genealogyBase.'/%']
            );
            foreach ($samples as $s) {
                $this->line("  Would unlink: person #{$s->person_id} from {$s->nextcloud_path}");
            }
            if ($count->cnt > 10) {
                $this->line('  ... and '.($count->cnt - 10).' more');
            }

            return Command::SUCCESS;
        }

        $deleted = DB::delete(
            'DELETE pm FROM genealogy_person_media pm
             JOIN genealogy_media m ON pm.media_id = m.id
             WHERE m.tree_id = ? AND m.nextcloud_path NOT LIKE ?',
            [$treeId, $genealogyBase.'/%']
        );

        $this->info("Unlinked {$deleted} person-media links.");

        Log::info('genealogy:media-consolidate unlink-only', ['tree_id' => $treeId, 'deleted' => $deleted]);

        return Command::SUCCESS;
    }

    private function showStatus(int $treeId): int
    {
        $this->info('Media Consolidation Status');
        $this->info('=========================');
        $genealogyBase = $this->genealogyBase();

        $internal = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM genealogy_person_media pm
             JOIN genealogy_media m ON pm.media_id = m.id
             WHERE m.tree_id = ? AND m.nextcloud_path LIKE ?',
            [$treeId, $genealogyBase.'/%']
        );

        $external = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM genealogy_person_media pm
             JOIN genealogy_media m ON pm.media_id = m.id
             WHERE m.tree_id = ? AND m.nextcloud_path NOT LIKE ?',
            [$treeId, $genealogyBase.'/%']
        );

        $totalMedia = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM genealogy_media WHERE tree_id = ?',
            [$treeId]
        );

        $internalMedia = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM genealogy_media WHERE tree_id = ? AND nextcloud_path LIKE ?',
            [$treeId, $genealogyBase.'/%']
        );

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Media Records', $totalMedia->cnt],
                ['Media in 101-Genealogy', $internalMedia->cnt],
                ['Media Outside (to consolidate)', $totalMedia->cnt - $internalMedia->cnt],
                ['Person-Media Links (internal)', $internal->cnt],
                ['Person-Media Links (external)', $external->cnt],
                ['Consolidation %', $external->cnt > 0
                    ? round(($internal->cnt / ($internal->cnt + $external->cnt)) * 100, 1).'%'
                    : '100%'],
            ]
        );

        return Command::SUCCESS;
    }

    private function genealogyBase(): string
    {
        $fallback = config('genealogy.media_consolidation_base', '/srv/genealogy/library');
        $configured = app(SystemConfigService::class)->get('genealogy.media_consolidation_base', $fallback);

        return rtrim((string) ($configured ?: $fallback), '/');
    }

    private function resolveDeadlineSeconds(): int
    {
        $optionTimeout = $this->option('timeout');
        if (is_numeric($optionTimeout) && (int) $optionTimeout > 0) {
            return $this->runtimeBudgetSeconds((int) $optionTimeout);
        }

        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs
                 WHERE name = 'genealogy_media_consolidate'
                    OR command LIKE 'genealogy:media-consolidate%'
                 ORDER BY CASE WHEN name = 'genealogy_media_consolidate' THEN 0 ELSE 1 END
                 LIMIT 1"
            );

            return $this->runtimeBudgetSeconds((int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            return $this->runtimeBudgetSeconds(self::DEFAULT_TIMEOUT_MINUTES);
        }
    }

    private function runtimeBudgetSeconds(int $timeoutMinutes): int
    {
        $timeoutMinutes = max(1, $timeoutMinutes);

        return max(60, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
    }

    private function shouldStopBeforeNextCopy(float $startedAt, int $deadlineSeconds): bool
    {
        if ($deadlineSeconds <= 0) {
            return false;
        }

        return $this->remainingBudgetSeconds($startedAt, $deadlineSeconds) < self::MIN_SECONDS_FOR_NEXT_COPY;
    }

    private function remainingBudgetSeconds(float $startedAt, int $deadlineSeconds): int
    {
        return max(0, (int) floor($deadlineSeconds - (microtime(true) - $startedAt)));
    }

    private function isTimeoutError(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'timeout')
            || str_contains($error, 'timed out')
            || str_contains($error, 'curl error 28');
    }
}

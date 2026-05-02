<?php

namespace App\Console\Commands;

use App\Services\ThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * File Thumbnail Generation Command
 *
 * Usage:
 *   php artisan files:thumbnails --generate --type=image --limit=10
 *   php artisan files:thumbnails --regenerate --uuid=73763041-ac2c-466f-9ac9-61e07815e60b
 *   php artisan files:thumbnails --stats
 *   php artisan files:thumbnails --cleanup
 */
class FileThumbnailsCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 130;

    protected $signature = 'files:thumbnails
        {--generate : Generate thumbnails for files without them}
        {--regenerate : Regenerate thumbnails (even if already generated)}
        {--uuid= : Target a specific file by UUID}
        {--path= : Filter files by path prefix}
        {--type= : Filter by type (image, pdf, video)}
        {--limit=50 : Max files to process}
        {--timeout= : Runtime budget in seconds for batch generation}
        {--stats : Show thumbnail statistics}
        {--cleanup : Remove orphaned thumbnails}
        {--backfill-db : Scan disk thumbnails and backfill DB records}
        {--reprocess-unsupported : Clear unsupported_type errors for now-supported extensions and queue for regeneration}';

    protected $description = 'Generate and manage file thumbnails/previews';

    public function handle(): int
    {
        $service = app(ThumbnailService::class);

        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        if ($this->option('backfill-db')) {
            return $this->backfillDb($service);
        }

        if ($this->option('cleanup')) {
            return $this->cleanup($service);
        }

        if ($this->option('uuid')) {
            return $this->generateForUuid($service);
        }

        if ($this->option('reprocess-unsupported')) {
            $resetCount = $this->reprocessUnsupported($service);
            if (!$this->option('generate') && !$this->option('regenerate')) {
                return 0;
            }
        }

        if ($this->option('generate') || $this->option('regenerate')) {
            return $this->batchGenerate($service);
        }

        $this->info('Usage: files:thumbnails --generate|--regenerate|--uuid=|--stats|--cleanup|--reprocess-unsupported');
        return 0;
    }

    private function showStats(ThumbnailService $service): int
    {
        $stats = $service->getStats();

        $this->info("Thumbnail Statistics");
        $this->line("Generated: {$stats['generated']}");
        $this->line("Errors: {$stats['errors']}");
        $this->line("Pending: {$stats['pending']}");
        $this->line("Disk usage: {$stats['disk_usage_human']}");

        return 0;
    }

    private function cleanup(ThumbnailService $service): int
    {
        $this->info("Cleaning up orphaned thumbnails...");
        $result = $service->cleanupOrphaned();

        $this->line("Checked: {$result['checked']}");
        $this->line("Removed: {$result['removed']}");
        if ($result['bytes_freed'] > 0) {
            $this->line("Freed: " . $this->formatBytes($result['bytes_freed']));
        }

        return 0;
    }

    private function generateForUuid(ThumbnailService $service): int
    {
        $uuid = $this->option('uuid');
        $this->info("Generating thumbnails for: {$uuid}");

        $results = $service->generateAllSizes($uuid);

        foreach ($results as $size => $result) {
            $status = $result['success'] ? 'OK' : 'FAIL';
            $detail = $result['success']
                ? ($result['from_cache'] ? '(cached)' : '(generated)')
                : ($result['error'] ?? 'unknown error');
            $this->line("  {$size}: [{$status}] {$detail}");
        }

        return 0;
    }

    private function batchGenerate(ThumbnailService $service): int
    {
        $limit = (int) $this->option('limit');
        $deadlineSeconds = $this->resolveDeadlineSeconds();
        $filters = [];

        if ($this->option('type')) {
            $filters['type'] = $this->option('type');
        }

        if ($this->option('path')) {
            $filters['path'] = $this->option('path');
        }

        $this->info("Generating thumbnails (limit: {$limit}, runtime budget: {$deadlineSeconds}s)...");

        $stats = $service->batchGenerate($filters, $limit, $deadlineSeconds);

        $this->line("Processed: {$stats['processed']}");
        $this->line("Generated: {$stats['generated']}");
        $this->line("Skipped: {$stats['skipped']}");
        $this->line("Errors: {$stats['errors']}");
        if (!empty($stats['stopped_early'])) {
            $this->warn("Stopped early to stay within runtime budget ({$deadlineSeconds}s).");
        }
        $this->line("[ITEMS_PROCESSED:{$stats['processed']}]");

        return 0;
    }

    private function resolveDeadlineSeconds(): int
    {
        $requested = max(0, (int) ($this->option('timeout') ?? 0));
        if ($requested > 0) {
            return $requested;
        }

        return (self::DEFAULT_TIMEOUT_MINUTES * 60) - 300;
    }

    private function backfillDb(ThumbnailService $service): int
    {
        $thumbDir = storage_path('app/thumbnails');
        if (!is_dir($thumbDir)) {
            $this->error('No thumbnails directory found.');
            return 1;
        }

        $this->info('Scanning disk thumbnails for DB backfill...');

        $stats = ['scanned' => 0, 'updated' => 0, 'skipped' => 0, 'orphaned' => 0];
        $uuidSizes = []; // uuid => [sizes]

        // Scan all prefix directories
        $prefixDirs = glob($thumbDir . '/*', GLOB_ONLYDIR);
        foreach ($prefixDirs as $prefixDir) {
            $files = glob($prefixDir . '/*.jpg');
            foreach ($files as $thumbFile) {
                $stats['scanned']++;
                $basename = basename($thumbFile, '.jpg');
                // Format: {uuid}.{size}
                $lastDot = strrpos($basename, '.');
                if ($lastDot === false) {
                    continue;
                }
                $uuid = substr($basename, 0, $lastDot);
                $size = substr($basename, $lastDot + 1);

                if (!in_array($size, ['small', 'medium', 'large'])) {
                    continue;
                }

                if (!isset($uuidSizes[$uuid])) {
                    $uuidSizes[$uuid] = [];
                }
                $uuidSizes[$uuid][] = $size;
            }
        }

        $this->info(sprintf('Found %d thumbnails for %d unique files.', $stats['scanned'], count($uuidSizes)));

        $bar = $this->output->createProgressBar(count($uuidSizes));
        $bar->start();

        foreach ($uuidSizes as $uuid => $sizes) {
            $bar->advance();

            $file = DB::selectOne(
                "SELECT id, thumbnail_sizes, thumbnail_generated_at FROM file_registry WHERE asset_uuid = ? AND status = 'active' LIMIT 1",
                [$uuid]
            );

            if (!$file) {
                $stats['orphaned']++;
                continue;
            }

            $existingSizes = json_decode($file->thumbnail_sizes ?? '[]', true) ?: [];
            $mergedSizes = array_values(array_unique(array_merge($existingSizes, $sizes)));
            sort($mergedSizes);
            sort($existingSizes);

            if ($mergedSizes === $existingSizes && $file->thumbnail_generated_at) {
                $stats['skipped']++;
                continue;
            }

            DB::update(
                "UPDATE file_registry SET thumbnail_sizes = ?, thumbnail_generated_at = COALESCE(thumbnail_generated_at, NOW()), thumbnail_error = NULL WHERE id = ?",
                [json_encode($mergedSizes), $file->id]
            );
            $stats['updated']++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Backfill complete:');
        $this->line("  Scanned:  {$stats['scanned']} thumbnail files");
        $this->line("  Updated:  {$stats['updated']} DB records");
        $this->line("  Skipped:  {$stats['skipped']} (already up to date)");
        $this->line("  Orphaned: {$stats['orphaned']} (no matching file_registry entry)");

        return 0;
    }

    private function reprocessUnsupported(ThumbnailService $service): int
    {
        $this->info('Clearing unsupported_type errors for now-supported types...');

        // Extension-based clear: derived from ThumbnailService::EXTENSION_TYPE_MAP (single source of truth)
        $extensions = ThumbnailService::getSupportedExtensions();
        $pattern = '\.(' . implode('|', array_map('preg_quote', $extensions)) . ')$';

        $byExt = DB::update(
            "UPDATE file_registry
             SET thumbnail_error = NULL, thumbnail_generated_at = NULL
             WHERE thumbnail_error = 'unsupported_type'
               AND current_path REGEXP ?",
            [$pattern]
        );

        // MIME-based clear: files whose mime is now supported but were previously flagged
        $mimes = ThumbnailService::getAllSupportedMimeTypes();
        $placeholders = implode(',', array_fill(0, count($mimes), '?'));
        $byMime = DB::update(
            "UPDATE file_registry
             SET thumbnail_error = NULL, thumbnail_generated_at = NULL
             WHERE thumbnail_error = 'unsupported_type'
               AND mime_type IN ($placeholders)",
            $mimes
        );

        $total = $byExt + $byMime;
        $this->line("  Reset by extension: {$byExt}");
        $this->line("  Reset by MIME type: {$byMime}");
        $this->line("  Total cleared: {$total}");
        return $total;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
}

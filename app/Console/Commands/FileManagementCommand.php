<?php

namespace App\Console\Commands;

use App\Services\FileBundleService;
use App\Services\FileCollectionService;
use App\Services\FileQuarantineService;
use App\Services\FileSemanticSearchService;
use App\Services\FileVersionService;
use Illuminate\Console\Command;

/**
 * File Management Command
 *
 * Usage:
 *   php artisan files:manage --quarantine-scan --path=/Library
 *   php artisan files:manage --detect-bundles --path=/Library --dry-run
 *   php artisan files:manage --smart-collections
 *   php artisan files:manage --generate-descriptions --limit=10
 *   php artisan files:manage --version-snapshot --path=/Library
 *   php artisan files:manage --stats
 */
class FileManagementCommand extends Command
{
    protected $signature = 'files:manage
        {--quarantine-scan : Scan for suspicious files}
        {--detect-bundles : Auto-detect file bundles}
        {--smart-collections : Evaluate all smart collections}
        {--generate-descriptions : Generate AI descriptions for files}
        {--version-snapshot : Create version snapshots}
        {--stats : Show management statistics}
        {--dry-run : Preview without changes}
        {--path= : Filter by path prefix}
        {--limit=50 : Max items to process}';

    protected $description = 'File management: quarantine, bundles, collections, descriptions, versions';

    public function handle(): int
    {
        if ($this->option('quarantine-scan')) {
            return $this->quarantineScan();
        }
        if ($this->option('detect-bundles')) {
            return $this->detectBundles();
        }
        if ($this->option('smart-collections')) {
            return $this->evalSmartCollections();
        }
        if ($this->option('generate-descriptions')) {
            return $this->generateDescriptions();
        }
        if ($this->option('version-snapshot')) {
            return $this->versionSnapshot();
        }
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $this->info('Usage: files:manage --quarantine-scan|--detect-bundles|--smart-collections|--generate-descriptions|--version-snapshot|--stats');

        return self::SUCCESS;
    }

    private function quarantineScan(): int
    {
        $service = app(FileQuarantineService::class);
        $path = $this->option('path') ?? $this->nextcloudLibraryRoot();
        $dryRun = $this->option('dry-run');

        $this->info("Scanning {$path} for suspicious files...");
        $suspicious = $service->scanForSuspicious($path);

        if (empty($suspicious)) {
            $this->info('No suspicious files found.');

            return self::SUCCESS;
        }

        $this->info(count($suspicious).' suspicious files found:');
        foreach ($suspicious as $item) {
            $this->line("  [{$item['reason']}] {$item['file']->filename} ({$item['file']->current_path})");
            if (! $dryRun) {
                $service->quarantineFile($item['file']->id, $item['reason'], 'scan');
            }
        }

        if ($dryRun) {
            $this->warn('Dry run - no files were quarantined.');
        }

        return self::SUCCESS;
    }

    private function detectBundles(): int
    {
        $service = app(FileBundleService::class);
        $path = $this->option('path') ?? $this->nextcloudLibraryRoot();
        $dryRun = $this->option('dry-run');

        $this->info("Detecting file bundles in {$path}...");
        $result = $service->autoDetectBundles($path, $dryRun);

        $this->info("Detected: {$result['detected']}, Created: {$result['created']}");

        if ($dryRun && ! empty($result['bundles'])) {
            foreach ($result['bundles'] as $bundle) {
                $fileCount = count($bundle['files']);
                $this->line("  [{$bundle['type']}] {$bundle['stem']} ({$fileCount} files)");
            }
        }

        return self::SUCCESS;
    }

    private function evalSmartCollections(): int
    {
        $service = app(FileCollectionService::class);
        $collections = $service->getCollections();

        $evaluated = 0;
        foreach ($collections as $collection) {
            if (! $collection->is_smart) {
                continue;
            }

            $result = $service->evaluateSmartCollection($collection->id);
            $this->line("  [{$collection->name}] matched: ".($result['matched'] ?? 0));
            $evaluated++;
        }

        $this->info("Evaluated {$evaluated} smart collections.");

        return self::SUCCESS;
    }

    private function generateDescriptions(): int
    {
        $service = app(FileSemanticSearchService::class);
        $path = $this->option('path');
        $limit = (int) $this->option('limit');

        $this->info("Generating AI descriptions (limit: {$limit})...");
        $result = $service->batchGenerateDescriptions($path, $limit);

        $this->info("Processed: {$result['processed']}, Success: {$result['success']}, Failed: {$result['failed']}");

        return self::SUCCESS;
    }

    private function versionSnapshot(): int
    {
        $service = app(FileVersionService::class);
        $this->info('Version tracking stats:');
        $stats = $service->getStats();
        $this->line("  Total versions: {$stats['total_versions']}");
        $this->line("  Files with versions: {$stats['files_with_versions']}");
        $this->line("  Max version depth: {$stats['max_version_depth']}");

        return self::SUCCESS;
    }

    private function showStats(): int
    {
        $quarantineStats = app(FileQuarantineService::class)->getStats();
        $versionStats = app(FileVersionService::class)->getStats();

        $this->info('File Management Statistics:');
        $this->line("  Quarantine: {$quarantineStats['total']} total");
        foreach ($quarantineStats['by_status'] as $status => $count) {
            $this->line("    {$status}: {$count}");
        }
        $this->line("  Versions: {$versionStats['total_versions']} across {$versionStats['files_with_versions']} files");

        return self::SUCCESS;
    }

    private function nextcloudLibraryRoot(): string
    {
        return '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\GenealogyFaceScanJob;
use App\Services\Genealogy\GenealogyMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to scan Nextcloud media folders for face metadata.
 *
 * Scans images for XMP-mwg-rs face region data (as tagged by photo software
 * like Picasa, digiKam, or Adobe Lightroom) and imports media with faces
 * into the genealogy system, linking faces to matching persons.
 *
 * This command NEVER changes primary photos - it only adds media links.
 *
 * Sprint 2: Genealogy Face Scan Automation
 */
class GenealogyFaceScan extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'genealogy:face-scan
                            {--tree-id=4 : Tree ID to scan (default: 4)}
                            {--folder= : Nextcloud folder to scan (default: configured genealogy face sync root)}
                            {--recursive : Scan subfolders recursively}
                            {--sync : Run synchronously (for small scans)}
                            {--status : Check status of running scan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan Nextcloud media for face metadata and import to genealogy system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $treeId = (int) $this->option('tree-id');
        $folder = $this->option('folder') ?: config('genealogy.face_sync_root', '/Library/Media');
        $recursive = $this->option('recursive');
        $sync = $this->option('sync');
        $checkStatus = $this->option('status');

        // Check status mode
        if ($checkStatus) {
            return $this->showStatus($treeId);
        }

        $this->info('Genealogy Face Scan');
        $this->info('==================');
        $this->info("Tree ID: {$treeId}");
        $this->info("Folder: {$folder}");
        $this->info('Recursive: '.($recursive ? 'Yes' : 'No'));
        $this->info('Mode: '.($sync ? 'Synchronous' : 'Background Job'));

        // Check for existing running job
        $cacheKey = GenealogyFaceScanJob::getStatusCacheKey($treeId);
        $existingStatus = Cache::get($cacheKey);

        if ($existingStatus && $existingStatus['status'] === 'running') {
            $this->warn("\nA face scan is already running for this tree.");
            $this->info('Started at: '.($existingStatus['started_at'] ?? 'Unknown'));
            $this->info('Progress: '.json_encode($existingStatus['progress'] ?? []));
            $this->newLine();

            if (! $this->confirm('Do you want to start a new scan anyway?', false)) {
                return Command::SUCCESS;
            }
        }

        if ($sync) {
            // Run synchronously (for small scans or testing)
            return $this->runSync($treeId, $folder, $recursive);
        }

        // Dispatch background job
        return $this->runAsync($treeId, $folder, $recursive);
    }

    /**
     * Run the face scan synchronously.
     */
    private function runSync(int $treeId, string $folder, bool $recursive): int
    {
        $this->info("\nRunning synchronous face scan...");

        try {
            $mediaService = app(GenealogyMediaService::class);
            $results = $mediaService->scanNextcloudFolderWithFaces($treeId, $folder, $recursive);

            if (! $results['success']) {
                $this->error('Scan failed: '.($results['error'] ?? 'Unknown error'));

                return Command::FAILURE;
            }

            $this->displayResults($results);

            Log::info('genealogy:face-scan completed (sync)', [
                'tree_id' => $treeId,
                'folder' => $folder,
                'results' => $results,
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Scan failed: '.$e->getMessage());
            Log::error('genealogy:face-scan failed', [
                'tree_id' => $treeId,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Run the face scan as a background job.
     */
    private function runAsync(int $treeId, string $folder, bool $recursive): int
    {
        $this->info("\nDispatching background face scan job...");

        try {
            GenealogyFaceScanJob::dispatch($treeId, $folder, $recursive);

            $this->info('Job dispatched successfully!');
            $this->info("Job will run on the 'long-running' queue.");
            $this->newLine();
            $this->info('Monitor progress:');
            $this->info("  - CLI: php artisan genealogy:face-scan --status --tree-id={$treeId}");
            $this->info("  - API: GET /api/genealogy/trees/{$treeId}/media/face-scan-status");

            Log::info('genealogy:face-scan job dispatched', [
                'tree_id' => $treeId,
                'folder' => $folder,
                'recursive' => $recursive,
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to dispatch job: '.$e->getMessage());
            Log::error('genealogy:face-scan dispatch failed', [
                'tree_id' => $treeId,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Show the status of a running or completed scan.
     */
    private function showStatus(int $treeId): int
    {
        $cacheKey = GenealogyFaceScanJob::getStatusCacheKey($treeId);
        $status = Cache::get($cacheKey);

        if (! $status) {
            $this->info("No face scan status found for tree ID {$treeId}.");
            $this->info("Start a scan with: php artisan genealogy:face-scan --tree-id={$treeId}");

            return Command::SUCCESS;
        }

        $this->info('Face Scan Status');
        $this->info('================');
        $this->info("Tree ID: {$treeId}");
        $this->info('Status: '.strtoupper($status['status'] ?? 'unknown'));
        $this->info('Folder: '.($status['folder'] ?? 'N/A'));

        if (isset($status['started_at'])) {
            $this->info("Started: {$status['started_at']}");
        }

        if (isset($status['completed_at'])) {
            $this->info("Completed: {$status['completed_at']}");
        }

        if (isset($status['failed_at'])) {
            $this->error("Failed: {$status['failed_at']}");
            $this->error('Error: '.($status['error'] ?? 'Unknown'));
        }

        if (isset($status['progress'])) {
            $this->newLine();
            $this->info('Progress:');
            $progress = $status['progress'];
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Files Scanned', $progress['files_scanned'] ?? 0],
                    ['Files with Faces', $progress['files_with_faces'] ?? 0],
                    ['Faces Found', $progress['faces_found'] ?? 0],
                    ['Imported', $progress['imported'] ?? 0],
                    ['Skipped', $progress['skipped'] ?? 0],
                    ['Failed', $progress['failed'] ?? 0],
                ]
            );

            if (! empty($progress['current_file'])) {
                $this->info("Current file: {$progress['current_file']}");
            }
        }

        if (isset($status['results'])) {
            $this->displayResults($status['results']);
        }

        if (! empty($status['errors'])) {
            $this->newLine();
            $this->warn('Errors ('.count($status['errors']).'):');
            foreach (array_slice($status['errors'], 0, 10) as $error) {
                $this->error('  - '.(is_array($error) ? json_encode($error) : $error));
            }
            if (count($status['errors']) > 10) {
                $this->warn('  ... and '.(count($status['errors']) - 10).' more errors');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Display scan results in a formatted table.
     */
    private function displayResults(array $results): void
    {
        $this->newLine();
        $this->info('Scan Results:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Files Scanned', $results['files_scanned'] ?? 0],
                ['Files with Faces', $results['files_with_faces'] ?? 0],
                ['Total Faces Found', $results['faces_found'] ?? 0],
                ['Media Imported', $results['imported'] ?? 0],
                ['Media Skipped (exists)', $results['skipped'] ?? 0],
                ['Persons Linked', $results['persons_linked'] ?? 0],
                ['Failed', $results['failed'] ?? 0],
            ]
        );

        if (! empty($results['errors'])) {
            $this->newLine();
            $this->warn('Errors ('.count($results['errors']).'):');
            foreach (array_slice($results['errors'], 0, 5) as $error) {
                $path = $error['path'] ?? 'Unknown';
                $msg = $error['error'] ?? 'Unknown error';
                $this->error("  - {$path}: {$msg}");
            }
            if (count($results['errors']) > 5) {
                $this->warn('  ... and '.(count($results['errors']) - 5).' more errors');
            }
        }
    }
}

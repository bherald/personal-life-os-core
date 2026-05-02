<?php

namespace App\Console\Commands;

use App\Services\FileRegistryService;
use App\Services\NextcloudFileApiService;
use App\Services\ProcessHealthFlagService;
use App\Traits\HasHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * File Registry Management Command
 *
 * Manage the file registry for persistent file references.
 * This is a simplified read-only catalog version (no file movement or action proposals).
 *
 * Usage:
 *   php artisan files:registry --status
 *   php artisan files:registry --register="/Library/Documents/file.pdf"
 *   php artisan files:registry --resolve="uuid-here"
 *   php artisan files:registry --scan="/Library/Documents" --limit=10
 *   php artisan files:registry --verify
 *   php artisan files:registry --duplicates
 *   php artisan files:registry --info="uuid-here"
 *   php artisan files:registry --test
 */
class FileRegistryCommand extends Command
{
    use HasHeartbeat;

    protected $signature = 'files:registry
        {--status : Show registry statistics}
        {--register= : Register a single file by Nextcloud path}
        {--resolve= : Resolve an asset UUID to a URL}
        {--scan= : Scan a Nextcloud directory and register files}
        {--limit=500 : Limit number of files to scan}
        {--skip-hash : Skip content hash computation for faster scanning}
        {--verify : Verify all registered files still exist}
        {--duplicates : Show duplicate file report}
        {--test : Run test with sample files}
        {--info= : Get detailed info for an asset UUID}
        {--cleanup-stuck : Clean up stuck sync jobs (running > 1 hour)}
        {--maintenance : Run self-healing maintenance (cleanup deleted files)}
        {--maintenance-stats : Show maintenance/cleanup statistics}';

    protected $description = 'Manage the file registry for persistent file references';

    private FileRegistryService $registry;

    private NextcloudFileApiService $nextcloudApi;

    public function handle(): int
    {
        $this->nextcloudApi = new NextcloudFileApiService;
        $this->registry = new FileRegistryService($this->nextcloudApi);

        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($this->option('register')) {
            return $this->registerFile($this->option('register'));
        }

        if ($this->option('resolve')) {
            return $this->resolveAsset($this->option('resolve'));
        }

        if ($this->option('scan')) {
            return $this->scanDirectory(
                $this->option('scan'),
                (int) $this->option('limit'),
                (bool) $this->option('skip-hash')
            );
        }

        if ($this->option('verify')) {
            return $this->verifyFiles();
        }

        if ($this->option('duplicates')) {
            return $this->showDuplicates();
        }

        if ($this->option('test')) {
            return $this->runTest();
        }

        if ($this->option('info')) {
            return $this->showFileInfo($this->option('info'));
        }

        if ($this->option('cleanup-stuck')) {
            return $this->cleanupStuckJobs();
        }

        if ($this->option('maintenance')) {
            return $this->runMaintenance();
        }

        if ($this->option('maintenance-stats')) {
            return $this->showMaintenanceStats();
        }

        // Default: show help
        $this->info('File Registry Management');
        $this->line('');
        $this->line('Usage:');
        $this->line('  --status              Show registry statistics');
        $this->line('  --register=<path>     Register a Nextcloud file');
        $this->line('  --resolve=<uuid>      Resolve UUID to downloadable URL');
        $this->line('  --scan=<path>         Scan directory and register files');
        $this->line('  --limit=<n>           Limit files to scan (default 100)');
        $this->line('  --skip-hash           Skip hash computation for speed');
        $this->line('  --verify              Verify all files still exist');
        $this->line('  --duplicates          Show duplicate file report');
        $this->line('  --test                Run test with sample files');
        $this->line('  --info=<uuid>         Show detailed file info');
        $this->line('  --cleanup-stuck       Clean up stuck sync jobs');
        $this->line('  --maintenance         Run self-healing maintenance');
        $this->line('  --maintenance-stats   Show maintenance statistics');
        $this->line('');
        $this->line('For full catalog sync, use: php artisan file-catalog:sync');

        return 0;
    }

    private function showStatus(): int
    {
        $this->info('File Registry Statistics');
        $this->line('');

        $stats = $this->registry->getStatistics();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Files', number_format($stats['total_files'])],
                ['Active Files', number_format($stats['active_files'])],
                ['Orphaned Files', number_format($stats['orphaned_files'])],
                ['With Nextcloud FileID', number_format($stats['with_nextcloud_fileid'])],
                ['With Content Hash', number_format($stats['with_content_hash'])],
                ['Total Size', $stats['total_size_human']],
                ['Pending Duplicates', number_format($stats['pending_duplicates'])],
            ]
        );

        if (! empty($stats['by_source'])) {
            $this->line('');
            $this->info('By Source:');
            $sourceData = array_map(fn ($s) => [$s->original_source ?? 'unknown', $s->count], $stats['by_source']);
            $this->table(['Source', 'Count'], $sourceData);
        }

        if (! empty($stats['by_category'])) {
            $this->line('');
            $this->info('By Category (top 10):');
            $catData = array_slice(
                array_map(fn ($c) => [$c->category, $c->count], $stats['by_category']),
                0, 10
            );
            $this->table(['Category', 'Count'], $catData);
        }

        return 0;
    }

    private function registerFile(string $path): int
    {
        $this->info("Registering file: {$path}");

        try {
            $result = $this->registry->registerFile($path, [
                'original_source' => 'nextcloud',
                'compute_hash' => true,
            ]);

            if ($result['success']) {
                $this->info('File registered successfully');
                $this->line('');
                $this->line("Asset UUID:    {$result['asset_uuid']}");
                $this->line("Reference:     {$result['reference']}");
                $this->line('Nextcloud ID:  '.($result['nextcloud_fileid'] ?? 'N/A'));
                $this->line('Content Hash:  '.($result['content_hash'] ?? 'N/A'));

                if ($result['is_duplicate']) {
                    $this->warn("Duplicate of: {$result['duplicate_of']}");
                }
            } else {
                $this->error('Registration failed: '.($result['error'] ?? 'Unknown error'));

                return 1;
            }

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function resolveAsset(string $uuid): int
    {
        $this->info("Resolving asset: {$uuid}");

        $result = $this->registry->resolveAsset($uuid);

        if ($result['success']) {
            $this->info('Asset resolved');
            $this->line('');
            $this->line("URL:      {$result['url']}");
            $this->line("Method:   {$result['method']}");
            $this->line("Filename: {$result['filename']}");
            $this->line("Path:     {$result['path']}");

            if (isset($result['expires_at'])) {
                $this->line("Expires:  {$result['expires_at']}");
            }

            if (! empty($result['path_updated'])) {
                $this->warn('Path was updated (file had moved)');
            }
        } else {
            $this->error('Resolution failed: '.($result['error'] ?? 'Unknown error'));
            if (isset($result['last_known_path'])) {
                $this->line("Last known path: {$result['last_known_path']}");
            }

            return 1;
        }

        return 0;
    }

    private function scanDirectory(string $path, int $limit, bool $skipHash = false): int
    {
        $this->info("Scanning directory: {$path} (limit: {$limit}, hash: ".($skipHash ? 'skip' : 'compute').')');

        // Create sync run record
        DB::insert("
            INSERT INTO file_registry_sync_runs (run_type, status, started_at, scope_path)
            VALUES ('initial_import', 'running', NOW(), ?)
        ", [$path]);
        $runId = (int) DB::getPdo()->lastInsertId();

        // Initialize heartbeat for stuck process detection
        $this->initHeartbeat('file_registry_sync_runs', $runId, 20);

        $stats = [
            'scanned' => 0,
            'registered' => 0,
            'skipped' => 0,
            'updated' => 0,
            'duplicates' => 0,
            'errors' => 0,
        ];

        try {
            $result = $this->nextcloudApi->listFiles($path, true, 0, $limit);

            if (! $result['success']) {
                $this->error('Failed to list files: '.($result['error'] ?? 'Unknown error'));

                return 1;
            }

            $files = array_filter($result['files'], fn ($f) => ! $f['is_directory']);
            $files = array_slice($files, 0, $limit);

            $this->line('Found '.count($files).' files to process');
            $this->line('');

            $bar = $this->output->createProgressBar(count($files));
            $bar->start();

            foreach ($files as $file) {
                $stats['scanned']++;
                $this->checkHeartbeat();

                try {
                    $regResult = $this->registry->registerFile($file['path'], [
                        'original_source' => 'nextcloud',
                        'compute_hash' => ! $skipHash,
                    ]);

                    if ($regResult['success']) {
                        if ($regResult['skipped'] ?? false) {
                            $stats['skipped']++;
                        } else {
                            $stats['registered']++;
                            if ($regResult['is_duplicate'] ?? false) {
                                $stats['duplicates']++;
                            }
                        }
                    }

                } catch (\Exception $e) {
                    $stats['errors']++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->line('');
            $this->line('');

            $this->clearHeartbeat();

            app(ProcessHealthFlagService::class)->clearFlag(
                'file_registry_sync_runs',
                $runId,
                'completed',
                'Scan completed successfully'
            );

            DB::update("
                UPDATE file_registry_sync_runs
                SET status = 'completed', completed_at = NOW(),
                    files_scanned = ?, files_registered = ?, duplicates_found = ?, errors = ?
                WHERE id = ?
            ", [$stats['scanned'], $stats['registered'], $stats['duplicates'], $stats['errors'], $runId]);

            $this->info('Scan complete');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Files Scanned', $stats['scanned']],
                    ['Files Registered', $stats['registered']],
                    ['Skipped (Unchanged)', $stats['skipped']],
                    ['Duplicates Found', $stats['duplicates']],
                    ['Errors', $stats['errors']],
                ]
            );

        } catch (\Exception $e) {
            DB::update("
                UPDATE file_registry_sync_runs
                SET status = 'failed', completed_at = NOW(), error_log = ?
                WHERE id = ?
            ", [json_encode(['error' => $e->getMessage()]), $runId]);

            $this->error('Scan failed: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function verifyFiles(): int
    {
        $this->info('Verifying registered files...');

        $result = $this->registry->verifyBatch(100);

        $this->info('Verification complete');
        $this->table(
            ['Status', 'Count'],
            [
                ['Checked', $result['verified']],
                ['Paths updated', $result['updated_paths']],
                ['Orphaned (not found)', $result['orphaned']],
                ['Errors', $result['errors']],
            ]
        );

        return 0;
    }

    private function showDuplicates(): int
    {
        $this->info('Duplicate Files Report');
        $this->line('');

        $duplicates = $this->registry->getDuplicatesReport();

        if (empty($duplicates)) {
            $this->line('No pending duplicates found.');

            return 0;
        }

        $data = array_map(fn ($d) => [
            substr($d->content_hash, 0, 12).'...',
            $d->canonical_uuid,
            basename($d->canonical_path),
            $d->duplicate_uuid,
            basename($d->duplicate_path),
        ], $duplicates);

        $this->table(
            ['Hash', 'Canonical UUID', 'Canonical File', 'Duplicate UUID', 'Duplicate File'],
            $data
        );

        $this->line('');
        $this->line('Total: '.count($duplicates).' pending duplicate pairs');

        return 0;
    }

    private function showFileInfo(string $uuid): int
    {
        $file = $this->registry->getFile($uuid);

        if (! $file) {
            $this->error("Asset not found: {$uuid}");

            return 1;
        }

        $this->info('File Information');
        $this->line('');

        $this->table(['Field', 'Value'], [
            ['Asset UUID', $file->asset_uuid],
            ['Reference', "{{ASSET:{$file->asset_uuid}}}"],
            ['Status', $file->status],
            ['Filename', $file->filename],
            ['Current Path', $file->nextcloud_path],
            ['Original Path', $file->original_path ?? 'N/A'],
            ['Original Source', $file->original_source ?? 'N/A'],
            ['Nextcloud FileID', $file->nextcloud_fileid ?? 'N/A'],
            ['Content Hash', $file->content_hash ? substr($file->content_hash, 0, 16).'...' : 'N/A'],
            ['MIME Type', $file->mime_type ?? 'N/A'],
            ['File Size', $this->formatBytes($file->file_size ?? 0)],
            ['Category', $file->category ?? 'N/A'],
            ['RAG Indexed', $file->rag_indexed_at ?? 'Never'],
            ['Last Verified', $file->verified_at ?? 'Never'],
            ['Created', $file->created_at],
        ]);

        // Show path history
        $history = DB::select('
            SELECT previous_path, new_path, moved_by, moved_at, move_reason
            FROM file_registry_path_history
            WHERE file_registry_id = (SELECT id FROM file_registry WHERE asset_uuid = ?)
            ORDER BY moved_at DESC
            LIMIT 10
        ', [$uuid]);

        if (! empty($history)) {
            $this->line('');
            $this->info('Path History:');
            foreach ($history as $h) {
                $this->line("  [{$h->moved_at}] {$h->moved_by}: {$h->previous_path} -> {$h->new_path}");
                if ($h->move_reason) {
                    $this->line("    Reason: {$h->move_reason}");
                }
            }
        }

        return 0;
    }

    private function runTest(): int
    {
        $this->info('Running File Registry Tests');
        $this->line('');

        // Test 1: Check Nextcloud connectivity
        $this->line('Test 1: Nextcloud connectivity...');
        $libraryRoot = $this->nextcloudLibraryRoot();
        $listResult = $this->nextcloudApi->listFiles($libraryRoot, false);
        if ($listResult['success']) {
            $this->info("  Connected - found {$listResult['count']} items in {$libraryRoot}");
        } else {
            $this->error('  Failed: '.($listResult['error'] ?? 'Unknown'));
            $this->warn('  Trying root directory...');

            $rootResult = $this->nextcloudApi->listFiles('/', false);
            if ($rootResult['success']) {
                $this->info("  Connected to root - found {$rootResult['count']} items");
                $this->line('  Available directories:');
                foreach (array_slice($rootResult['files'], 0, 10) as $f) {
                    $icon = $f['is_directory'] ? 'D' : 'F';
                    $this->line("    [{$icon}] {$f['name']}");
                }
            } else {
                $this->error('  Cannot connect to Nextcloud');

                return 1;
            }
        }

        // Test 2: Register a test file (if we found any)
        $this->line('');
        $this->line('Test 2: File registration...');

        $testFile = null;
        $searchPaths = [$libraryRoot, '/'];
        foreach ($searchPaths as $searchPath) {
            $files = $this->nextcloudApi->listFiles($searchPath, false);
            if ($files['success']) {
                foreach ($files['files'] as $f) {
                    if (! $f['is_directory'] && $f['size'] < 1048576) {
                        $testFile = $f;
                        break 2;
                    }
                }
            }
        }

        if (! $testFile) {
            $this->warn('  No suitable test file found, skipping registration test');
        } else {
            $this->line("  Testing with: {$testFile['path']}");

            try {
                $regResult = $this->registry->registerFile($testFile['path'], [
                    'original_source' => 'nextcloud',
                    'compute_hash' => true,
                ]);

                if ($regResult['success']) {
                    $this->info("  Registered: {$regResult['asset_uuid']}");

                    // Test 3: Resolve the asset
                    $this->line('');
                    $this->line('Test 3: Asset resolution...');

                    $resolveResult = $this->registry->resolveAsset($regResult['asset_uuid']);
                    if ($resolveResult['success']) {
                        $this->info("  Resolved via {$resolveResult['method']}");
                        $this->line('  URL: '.substr($resolveResult['url'], 0, 80).'...');
                    } else {
                        $this->error('  Resolution failed: '.($resolveResult['error'] ?? 'Unknown'));
                    }

                    // Test 4: Text reference resolution
                    $this->line('');
                    $this->line('Test 4: Text reference parsing...');

                    $testText = "Check this file: {{ASSET:{$regResult['asset_uuid']}}} for details.";
                    $textResult = $this->registry->resolveReferencesInText($testText);
                    $this->info("  Resolved {$textResult['successful']}/{$textResult['total']} references");
                }

            } catch (\Exception $e) {
                $this->error('  Error: '.$e->getMessage());
            }
        }

        // Final summary
        $this->line('');
        $this->info('Registry Status After Tests:');
        $stats = $this->registry->getStatistics();
        $this->line("  Total files: {$stats['total_files']}");
        $this->line("  With fileid: {$stats['with_nextcloud_fileid']}");
        $this->line("  With hash: {$stats['with_content_hash']}");

        return 0;
    }

    private function cleanupStuckJobs(): int
    {
        $this->info('Checking for stuck sync jobs...');
        $this->line('');

        $cleaned = $this->registry->cleanupStuckSyncRuns(60);

        if ($cleaned > 0) {
            $this->info("Cleaned up {$cleaned} stuck job(s).");
        } else {
            $this->info('No stuck jobs found.');
        }

        return 0;
    }

    private function runMaintenance(): int
    {
        $this->info('Running self-healing maintenance...');
        $this->line('');

        $orphanCount = DB::selectOne("SELECT COUNT(*) as cnt FROM file_registry WHERE status = 'orphaned' AND verification_failures >= 3")->cnt ?? 0;
        $stats = $this->registry->cleanupOrphanedFiles(3, max((int) $orphanCount, 1));

        $this->table(
            ['Action', 'Count'],
            [
                ['Files Checked', $stats['checked']],
                ['Recovered (found)', $stats['recovered']],
                ['Marked Deleted', $stats['deleted']],
                ['Still Orphaned', $stats['still_orphaned']],
            ]
        );

        if ($stats['deleted'] > 0) {
            $this->warn("{$stats['deleted']} files confirmed deleted and removed from active tracking.");
        }

        if ($stats['recovered'] > 0) {
            $this->info("{$stats['recovered']} files recovered to active status.");
        }

        return 0;
    }

    private function showMaintenanceStats(): int
    {
        $this->info('Maintenance Statistics');
        $this->line('');

        $stats = $this->registry->getMaintenanceStats();

        $this->info('Status Distribution:');
        $statusData = array_map(fn ($s) => [$s->status, number_format($s->count)], $stats['status_counts']);
        $this->table(['Status', 'Count'], $statusData);

        $this->line('');
        $this->info('Cleanup Candidates:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Orphaned', number_format($stats['total_orphaned'])],
                ['Ready for Deletion (3+ failures)', number_format($stats['orphaned_ready_for_deletion'])],
                ['Thumbnail Errors', number_format($stats['thumbnail_errors'])],
                ['404 Not Found Errors', number_format($stats['not_found_errors'])],
            ]
        );

        if ($stats['orphaned_ready_for_deletion'] > 0 || $stats['not_found_errors'] > 0) {
            $this->line('');
            $this->warn('Run --maintenance to clean up these files.');
        }

        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    private function nextcloudLibraryRoot(): string
    {
        return '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
    }
}

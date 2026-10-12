<?php

namespace App\Console\Commands;

use App\Services\FileRegistryImageSourceAuditService;
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
 *   php artisan files:registry --verify --all
 *   php artisan files:registry --duplicates
 *   php artisan files:registry --info="uuid-here"
 *   php artisan files:registry --test
 */
class FileRegistryCommand extends Command
{
    use HasHeartbeat;

    protected $signature = 'files:registry
        {--current-source-hash= : Preview one exact missing current-source hash by registry ID}
        {--apply-current-source-hash : Publish only the pinned eligible current-source hash}
        {--expected-hash-proof= : Preview proof SHA256 required for publication}
        {--hash-receipt-id= : Unique UUID for the private publication preimage and receipt}
        {--status : Show registry statistics}
        {--register= : Register a single file by Nextcloud path}
        {--resolve= : Resolve an asset UUID to a URL}
        {--scan= : Scan a Nextcloud directory and register files}
        {--limit=500 : Limit number of files to scan}
        {--all : Process all matching rows without a per-run limit}
        {--skip-hash : Skip content hash computation for faster scanning}
        {--verify : Verify all registered files still exist}
        {--audit-images : Read-only bounded image source hash and header audit}
        {--decode-images : Also validate full image decodability within configured resource caps}
        {--after-id=0 : Resume image audit after this registry ID}
        {--catalog-cutoff= : Keep the image audit within a captured maximum registry ID}
        {--max-seconds=30 : Image audit time budget; never interrupts a write}
        {--json : Emit image audit JSON, including private source paths}
        {--duplicates : Show duplicate file report}
        {--test : Run test with sample files}
        {--info= : Get detailed info for an asset UUID}
        {--cleanup-stuck : Clean up stuck sync jobs (running > 1 hour)}
        {--maintenance : Run self-healing maintenance (cleanup deleted files)}
        {--failure-threshold= : Required verification failure threshold for maintenance deletion}
        {--maintenance-stats : Show maintenance/cleanup statistics}';

    protected $description = 'Manage the file registry for persistent file references';

    private FileRegistryService $registry;

    private NextcloudFileApiService $nextcloudApi;

    public function handle(): int
    {
        if ($this->option('current-source-hash') !== null || $this->option('apply-current-source-hash')
            || $this->option('expected-hash-proof') !== null || $this->option('hash-receipt-id') !== null) {
            return $this->currentSourceHash();
        }
        if ($this->option('decode-images') && ! $this->option('audit-images')) {
            $this->error('--decode-images requires --audit-images.');

            return self::FAILURE;
        }
        if ($this->option('audit-images')) {
            return $this->auditImages();
        }

        $this->nextcloudApi = app(NextcloudFileApiService::class);
        $this->registry = app(FileRegistryService::class);

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
                $this->option('all') ? 0 : (int) $this->option('limit'),
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
        $this->line('  --limit=<n>           Limit files to scan/verify (default 500)');
        $this->line('  --all                 Process all matching rows without a limit');
        $this->line('  --skip-hash           Skip hash computation for speed');
        $this->line('  --verify              Verify all files still exist');
        $this->line('  --duplicates          Show duplicate file report');
        $this->line('  --test                Run test with sample files');
        $this->line('  --info=<uuid>         Show detailed file info');
        $this->line('  --cleanup-stuck       Clean up stuck sync jobs');
        $this->line('  --maintenance         Run self-healing maintenance');
        $this->line('  --failure-threshold=n Required for maintenance deletion');
        $this->line('  --maintenance-stats   Show maintenance statistics');
        $this->line('');
        $this->line('For full catalog sync, use: php artisan file-catalog:sync');

        return 0;
    }

    private function currentSourceHash(): int
    {
        $id = filter_var($this->option('current-source-hash'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $apply = (bool) $this->option('apply-current-source-hash');
        $invalid = $id === false || ($apply !== ($this->option('expected-hash-proof') !== null))
            || ($apply !== ($this->option('hash-receipt-id') !== null));
        foreach (['status', 'register', 'resolve', 'scan', 'all', 'skip-hash', 'verify', 'audit-images', 'decode-images',
            'duplicates', 'test', 'info', 'cleanup-stuck', 'maintenance', 'maintenance-stats'] as $option) {
            $invalid = $invalid || (bool) $this->option($option);
        }
        $result = $invalid
            ? ['success' => false, 'schema' => 'plos.current_source_hash.v1', 'status' => 'held', 'reason_code' => 'hash_request_invalid']
            : app(FileRegistryService::class)->repairCurrentSourceHash($id,
                $apply ? $this->option('expected-hash-proof') : null, $apply ? $this->option('hash-receipt-id') : null);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function auditImages(): int
    {
        $afterId = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        $cutoffOption = $this->option('catalog-cutoff');
        $cutoff = $cutoffOption === null ? null : filter_var($cutoffOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $audit = app(FileRegistryImageSourceAuditService::class);
        if ($afterId === false || $limit === false || $seconds === false || $cutoff === false) {
            $payload = $audit->auditRegistryBatch(-1, 0, 0, null, (bool) $this->option('decode-images'));

            return $this->emitImageAudit($payload, self::FAILURE);
        }
        foreach (['all', 'verify', 'register', 'scan', 'maintenance', 'cleanup-stuck', 'test'] as $option) {
            if ($this->option($option)) {
                $payload = $audit->auditRegistryBatch(-1, 0, 0, null, (bool) $this->option('decode-images'));
                $payload['error'] = 'Image audit cannot be combined with mutation or unlimited selectors.';

                return $this->emitImageAudit($payload, self::FAILURE);
            }
        }
        $payload = $audit->auditRegistryBatch($afterId, $limit, $seconds, $cutoff, (bool) $this->option('decode-images'));

        return $this->emitImageAudit($payload, $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS);
    }

    private function emitImageAudit(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf('Image source audit: %s checked=%d next=%s cutoff=%s has_more=%s; %s.',
                $payload['status'], count($payload['rows']), $payload['next_after_id'] ?? '-',
                $payload['catalog_cutoff'] ?? '-', ! empty($payload['has_more']) ? 'yes' : 'no',
                ! empty($payload['decode_requested']) ? 'bounded decode requested' : 'header checks only'));
            if (isset($payload['error'])) {
                $this->error($payload['error']);
            }
        }

        return $exitCode;
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
        $this->info("Scanning directory slice: {$path} (limit: {$limit}, hash: ".($skipHash ? 'skip' : 'compute').')');
        $runId = $this->registry->createSyncRun('initial_import', $path);
        try {
            $this->initHeartbeat('file_registry_sync_runs', $runId, 20);
            $result = $this->registry->scanAndRegisterNew($path, $limit, ['compute_hash' => ! $skipHash]);
            $this->checkHeartbeat();
            $this->registry->completeSyncRun($runId, $result);
            $complete = ($result['state'] ?? null) === 'complete' && ($result['discovery_complete'] ?? false) === true && ($result['intake_complete'] ?? false) === true;
            $failed = ($result['state'] ?? null) === 'held' || ($result['continuation']['state'] ?? null) === 'held' || ($result['errors'] ?? 0) > 0;
            if ($complete && ! $failed) {
                app(ProcessHealthFlagService::class)->clearFlag('file_registry_sync_runs', $runId, 'completed', 'Discovery and intake completed');
                $this->info('Discovery and intake complete');
            } else {
                $this->warn('Scan slice finished; discovery state: '.($result['state'] ?? 'unproven'));
                $this->line('Discovery complete: '.(($result['discovery_complete'] ?? false) ? 'yes' : 'no').
                    '; intake complete: '.(($result['intake_complete'] ?? false) ? 'yes' : 'no'));
                $this->line('Pending work: '.($result['pending_work'] ?? 0).'; held work: '.($result['held_work'] ?? 0).
                    '; next attempt: '.($result['next_attempt_at'] ?? 'not scheduled'));
            }
            if (isset($result['continuation']['state'])) {
                $this->line('Continuation: '.$result['continuation']['state'].
                    (isset($result['continuation']['reason']) ? '; reason: '.$result['continuation']['reason'] : '').
                    (isset($result['continuation']['next_attempt_at']) ? '; due: '.$result['continuation']['next_attempt_at'] : ''));
            }
            $this->table(['Metric', 'Count'], [
                ['Files Scanned', $result['scanned'] ?? 0], ['Files Registered', $result['registered'] ?? 0],
                ['Already Registered', $result['already_registered'] ?? 0], ['Skipped (Unchanged)', $result['skipped_unchanged'] ?? 0],
                ['Deferred (Still Changing)', $result['deferred_unstable'] ?? 0], ['Duplicates Found', $result['duplicates'] ?? 0],
                ['Errors', $result['errors'] ?? 0],
            ]);

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->registry->failSyncRun($runId, $e->getMessage());
            $this->error('Scan failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->clearHeartbeat();
        }
    }

    private function verifyFiles(): int
    {
        $limit = $this->option('all') ? 0 : max(1, (int) $this->option('limit'));
        $limitDisplay = $limit > 0 ? (string) $limit : 'all';

        $this->info("Verifying registered files ({$limitDisplay})...");

        $result = $this->registry->verifyBatch($limit);

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
        $threshold = filter_var($this->option('failure-threshold'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($threshold === false) {
            $this->error('--maintenance requires an explicit positive --failure-threshold.');

            return 2;
        }
        $limit = $this->option('all') ? 0 : max(1, (int) $this->option('limit'));

        $this->info('Running self-healing maintenance...');
        $this->line("Failure threshold: {$threshold}, Limit: ".($limit > 0 ? (string) $limit : 'all'));
        $this->line('');

        if ($limit <= 0) {
            $orphanCount = DB::selectOne(
                'SELECT COUNT(*) as cnt FROM file_registry WHERE status = ? AND verification_failures >= ?',
                ['orphaned', $threshold]
            )->cnt ?? 0;
            $limit = max((int) $orphanCount, 1);
        }

        $stats = $this->registry->cleanupOrphanedFiles($threshold, $limit);

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
                ['Ready for Deletion (1+ failures)', number_format($stats['orphaned_ready_for_deletion'])],
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
        return $this->nextcloudApi->configuredLibraryRoot();
    }
}

<?php

namespace App\Console\Commands;

use App\Services\FileCategorizationRAGService;
use App\Services\FileRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FileCatalogSyncCommand
 *
 * Nightly scheduled command to sync the file catalog:
 * 1. Scan the configured Nextcloud library root for new files
 * 2. Verify existing files still exist
 * 3. Detect removed files
 * 4. Sync changes to RAG (new, modified, removed)
 *
 * Usage:
 *   php artisan file-catalog:sync --full          # Run all operations (default for scheduled)
 *   php artisan file-catalog:sync --scan          # Only scan for new files
 *   php artisan file-catalog:sync --verify        # Only verify existing files
 *   php artisan file-catalog:sync --rag-sync      # Only sync RAG index
 *   php artisan file-catalog:sync --stats         # Show current status only
 *   php artisan file-catalog:sync --dry-run      # Preview without changes
 */
class FileCatalogSyncCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 90;

    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 300;

    private const RAG_FALLBACK_SECONDS_PER_FILE = 20.0;

    private const RAG_MIN_TARGET_MINUTES = 10;

    private const RAG_MAX_TARGET_MINUTES = 120;

    protected $signature = 'file-catalog:sync
                            {--scan : Scan Nextcloud for new files}
                            {--verify : Verify existing files still exist}
                            {--rag-sync : Sync changes to RAG index}
                            {--full : Run all operations (scan, verify, rag-sync)}
                            {--dry-run : Preview without making changes}
                            {--stats : Show current catalog status only}
                            {--path= : Specific path to scan (default: configured Nextcloud library root)}
                            {--limit=500 : Maximum files to process per operation}
                            {--all : Process ALL files (no limit, for initial bulk import)}
                            {--worker-id= : Worker ID for parallel execution (set by scheduler)}';

    protected $description = 'Sync file catalog with Nextcloud and RAG';

    private FileRegistryService $fileRegistry;

    private FileCategorizationRAGService $ragService;

    private bool $dryRun = false;

    public function __construct(
        FileRegistryService $fileRegistry,
        FileCategorizationRAGService $ragService
    ) {
        parent::__construct();
        $this->fileRegistry = $fileRegistry;
        $this->ragService = $ragService;
    }

    public function handle(): int
    {
        $this->dryRun = $this->option('dry-run');

        if ($this->option('stats')) {
            return $this->showStats();
        }

        // Determine operations to run
        $runScan = $this->option('scan') || $this->option('full');
        $runVerify = $this->option('verify') || $this->option('full');
        $runRagSync = $this->option('rag-sync') || $this->option('full');

        // If no specific option given, default to full
        if (! $runScan && ! $runVerify && ! $runRagSync) {
            $runScan = $runVerify = $runRagSync = true;
        }

        $path = $this->option('path') ?? $this->nextcloudLibraryRoot();
        $limit = $this->option('all') ? 0 : (int) $this->option('limit');  // 0 = unlimited
        $startedAt = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();

        // Cap RAG sync limit based on throughput history, scheduler timeout,
        // and the interval between scheduled runs.
        if ($runRagSync && $limit > 0) {
            $ragCap = $this->getDynamicRagCap();
            if ($limit > $ragCap) {
                Log::info('FileCatalogSync: RAG sync limit capped', ['original' => $limit, 'capped' => $ragCap]);
                $limit = $ragCap;
            }
        }

        if ($this->dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('File Catalog Sync - '.now()->format('Y-m-d H:i:s'));
        $limitDisplay = $limit > 0 ? (string) $limit : 'unlimited';
        $this->line("Path: {$path}, Limit: {$limitDisplay}");
        $this->newLine();

        // Create sync run record
        $runId = null;
        if (! $this->dryRun) {
            $runId = $this->fileRegistry->createSyncRun('catalog_sync', $path);
        }

        $results = [
            'scan' => null,
            'verify' => null,
            'rag_sync' => null,
        ];
        $multiPhaseRun = count(array_filter([$runScan, $runVerify, $runRagSync])) > 1;

        try {
            // Step 1: Scan for new files
            if ($runScan) {
                if ($this->shouldStopBeforePhase($startedAt, $deadlineSeconds, 240)) {
                    $this->warn("Skipping scan to stay within runtime budget ({$deadlineSeconds}s)");
                } else {
                    $results['scan'] = $this->runScan($path, $this->resolvePhaseLimit('scan', $limit, $multiPhaseRun));
                }
            }

            // Step 2: Verify existing files
            // With filesystem access, this is a fast file_exists() per file.
            // Only files NOT on filesystem fall back to WebDAV (moved/deleted).
            if ($runVerify) {
                if ($this->shouldStopBeforePhase($startedAt, $deadlineSeconds, 180)) {
                    $this->warn("Skipping verify to stay within runtime budget ({$deadlineSeconds}s)");
                } else {
                    $results['verify'] = $this->runVerify($this->resolvePhaseLimit('verify', $limit, $multiPhaseRun));
                }
            }

            // Step 3: Sync to RAG
            if ($runRagSync) {
                if ($this->shouldStopBeforePhase($startedAt, $deadlineSeconds, 120)) {
                    $this->warn("Skipping RAG sync to stay within runtime budget ({$deadlineSeconds}s)");
                } else {
                    $ragLimit = $this->resolvePhaseLimit('rag_sync', $limit, $multiPhaseRun);
                    $remainingSeconds = $this->getRemainingBudgetSeconds($startedAt, $deadlineSeconds);
                    $maxRagSeconds = max(60, min($this->getDynamicRagMaxSeconds(), $remainingSeconds));
                    $results['rag_sync'] = $this->runRagSync($ragLimit, $maxRagSeconds);
                }
            }

            // Complete sync run
            if ($runId && ! $this->dryRun) {
                $stats = [
                    'files_scanned' => $results['scan']['scanned'] ?? 0,
                    'files_registered' => $results['scan']['registered'] ?? 0,
                    'files_verified' => $results['verify']['verified'] ?? 0,
                    'files_orphaned' => $results['verify']['orphaned'] ?? 0,
                    'rag_indexed' => $results['rag_sync']['indexed'] ?? 0,
                    'rag_removed' => $results['rag_sync']['removed'] ?? 0,
                ];
                $this->fileRegistry->completeSyncRun($runId, $stats);
            }

            $this->newLine();
            $this->info('Sync completed successfully!');
            $this->showSummary($results);

            // Emit structured items_processed marker for ScheduledJobService
            $totalProcessed = ($results['scan']['registered'] ?? 0)
                            + ($results['rag_sync']['indexed'] ?? 0);
            $this->line("[ITEMS_PROCESSED:{$totalProcessed}]");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            $this->error("At: {$e->getFile()}:{$e->getLine()}");
            // Output stack trace so scheduler-bg.log captures it for debugging
            $this->error('Stack: '.$e->getTraceAsString());
            Log::error('FileCatalogSync: Failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($runId && ! $this->dryRun) {
                $this->fileRegistry->failSyncRun($runId, $e->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * Calculate dynamic RAG sync cap from job history, timeout, and schedule interval.
     * Uses real avg time/file from scheduled_job_runs, falls back to a conservative default.
     */
    private function getDynamicRagCap(): int
    {
        $avgSecondsPerFile = self::RAG_FALLBACK_SECONDS_PER_FILE;
        $targetMinutes = $this->getBatchTargetMinutes('rag_file_bulk_index', 24);

        try {
            // Try real job history
            $recent = DB::selectOne("
                SELECT AVG(duration_seconds / items_processed) as avg_seconds,
                       COUNT(*) as sample_count
                FROM scheduled_job_runs sjr
                JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
                WHERE sj.name = 'rag_file_bulk_index'
                  AND sjr.status = 'success'
                  AND sjr.items_processed > 0
                  AND sjr.duration_seconds > 0
                  AND sjr.completed_at > NOW() - INTERVAL 7 DAY
                LIMIT 10
            ");

            if ($recent && $recent->sample_count >= 3 && $recent->avg_seconds > 0) {
                $avgSecondsPerFile = max((float) $recent->avg_seconds, 5.0);
            }
        } catch (\Exception $e) {
            // use default
        }

        return max(50, (int) floor(($targetMinutes * 60) / $avgSecondsPerFile));
    }

    private function getDynamicRagMaxSeconds(): int
    {
        return $this->getBatchTargetMinutes('rag_file_bulk_index', 24) * 60;
    }

    private function getBatchTargetMinutes(string $jobName, int $defaultMinutes): int
    {
        $targetMinutes = $defaultMinutes;

        try {
            $job = DB::selectOne('
                SELECT cron_expression, timeout_minutes
                FROM scheduled_jobs
                WHERE name = ?
                LIMIT 1
            ', [$jobName]);

            if (! $job) {
                return $targetMinutes;
            }

            $timeoutTarget = ! empty($job->timeout_minutes)
                ? max(self::RAG_MIN_TARGET_MINUTES, ((int) $job->timeout_minutes) - 5)
                : null;
            $intervalTarget = $this->getIntervalSafeMinutes($job->cron_expression ?? null);

            $candidates = array_values(array_filter([
                $timeoutTarget,
                $intervalTarget,
            ], fn ($value) => $value !== null && $value > 0));

            if (! empty($candidates)) {
                $targetMinutes = min($candidates);
            }
        } catch (\Exception $e) {
            // use default
        }

        return max(self::RAG_MIN_TARGET_MINUTES, min(self::RAG_MAX_TARGET_MINUTES, $targetMinutes));
    }

    private function getIntervalSafeMinutes(?string $cronExpression): ?int
    {
        if (! $cronExpression) {
            return null;
        }

        try {
            $cron = new \Cron\CronExpression($cronExpression);
            $first = $cron->getNextRunDate(now()->toDateTimeImmutable());
            $second = $cron->getNextRunDate($first);
            $intervalMinutes = (int) floor(($second->getTimestamp() - $first->getTimestamp()) / 60);

            if ($intervalMinutes <= 0) {
                return null;
            }

            return max(
                self::RAG_MIN_TARGET_MINUTES,
                $intervalMinutes - max(2, (int) floor($intervalMinutes * 0.2))
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function showStats(): int
    {
        $this->info('File Catalog Status');
        $this->line(str_repeat('-', 50));

        // Registry stats
        $stats = $this->fileRegistry->getStatistics();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Files', number_format($stats['total_files'] ?? 0)],
                ['Active Files', number_format($stats['active_files'] ?? 0)],
                ['Total Size', $this->formatBytes($stats['total_size_bytes'] ?? 0)],
            ]
        );

        // By category
        if (! empty($stats['by_category'])) {
            $this->newLine();
            $this->info('Files by Category:');
            $categoryRows = [];
            foreach ($stats['by_category'] as $row) {
                $cat = is_object($row) ? ($row->category ?? 'uncategorized') : ($row['category'] ?? 'uncategorized');
                $count = is_object($row) ? ($row->count ?? 0) : ($row['count'] ?? 0);
                $categoryRows[] = [$cat, number_format($count)];
            }
            $this->table(['Category', 'Count'], $categoryRows);
        }

        // RAG stats
        $ragStats = $this->ragService->getStats();
        $this->newLine();
        $this->info('RAG Index Status:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Indexed Files', number_format($ragStats['total_indexed'] ?? 0)],
                ['Pending Indexing', number_format($ragStats['pending_indexing'] ?? 0)],
            ]
        );

        // Recent sync runs
        $recentRuns = $this->fileRegistry->getSyncRuns(5);
        if (! empty($recentRuns)) {
            $this->newLine();
            $this->info('Recent Sync Runs:');
            $runRows = [];
            foreach ($recentRuns as $run) {
                $runRows[] = [
                    $run->id,
                    $run->run_type,
                    $run->status,
                    $run->files_registered ?? 0,
                    $run->started_at,
                ];
            }
            $this->table(['ID', 'Type', 'Status', 'Registered', 'Started'], $runRows);
        }

        return self::SUCCESS;
    }

    private function runScan(string $path, int $limit): array
    {
        $this->info('Step 1: Scanning for new files...');

        if ($this->dryRun) {
            $this->line("  Would scan: {$path} (limit: {$limit})");

            return ['scanned' => 0, 'registered' => 0];
        }

        $result = $this->fileRegistry->scanAndRegisterNew($path, $limit);

        $this->line("  Scanned: {$result['scanned']}");
        $this->line("  Registered: {$result['registered']}");
        $this->line('  Already registered: '.($result['already_registered'] ?? 0));
        if (! empty($result['errors'])) {
            $this->warn('  Errors: '.$result['errors']);
        }

        return $result;
    }

    private function runVerify(int $limit): array
    {
        $this->info('Step 2: Verifying existing files...');

        if ($this->dryRun) {
            $this->line("  Would verify up to {$limit} files");

            return ['verified' => 0, 'orphaned' => 0];
        }

        $result = $this->fileRegistry->verifyBatch($limit);

        $this->line("  Verified: {$result['verified']}");
        $this->line("  Paths updated: {$result['updated_paths']}");
        $this->line("  Orphaned: {$result['orphaned']}");
        $this->line("  Errors: {$result['errors']}");

        return $result;
    }

    private function runRagSync(int $limit, ?int $maxSeconds = null): array
    {
        $this->info('Step 3: Syncing with RAG index...');

        if ($this->dryRun) {
            $this->line("  Would sync up to {$limit} files with RAG");

            return ['indexed' => 0, 'removed' => 0];
        }

        $workerId = $this->option('worker-id');
        $maxSeconds = $maxSeconds ?? $this->getDynamicRagMaxSeconds();

        if ($workerId) {
            // Claim-based: atomically claim files for RAG indexing
            $claimExpiry = now()->addMinutes(30)->format('Y-m-d H:i:s');
            $docExts = config('file_types.rag_indexable');
            $imgExts = config('file_types.image');
            $docPlaceholders = implode(',', array_fill(0, count($docExts), '?'));
            $imgPlaceholders = implode(',', array_fill(0, count($imgExts), '?'));
            DB::update("
                UPDATE file_registry
                SET claim_worker = ?, claim_expires_at = ?
                WHERE status = 'active'
                AND rag_indexed_at IS NULL
                AND (claim_worker IS NULL OR claim_expires_at < NOW())
                AND (extension IN ({$docPlaceholders})
                     OR (extension IN ({$imgPlaceholders}) AND ai_description IS NOT NULL))
                ORDER BY created_at DESC
                LIMIT ?
            ", array_merge([$workerId, $claimExpiry], $docExts, $imgExts, [$limit]));

            // Only sync claimed files
            $result = $this->ragService->syncWithRegistry($limit, $workerId, $maxSeconds);

            // Release claims
            DB::update('UPDATE file_registry SET claim_worker = NULL, claim_expires_at = NULL WHERE claim_worker = ?', [$workerId]);
        } else {
            $result = $this->ragService->syncWithRegistry($limit, null, $maxSeconds);
        }

        $this->line("  Indexed: {$result['indexed']}");
        $this->line("  Removed: {$result['removed']}");
        if (isset($result['reindexed'])) {
            $this->line("  Re-indexed stale: {$result['reindexed']}");
        }
        if (! empty($result['time_limited'])) {
            $this->warn("  Stopped early to stay within runtime budget ({$maxSeconds}s)");
        }
        if (! empty($result['errors'])) {
            $this->warn("  Errors: {$result['errors']}");
        }

        return $result;
    }

    private function resolveDeadlineSeconds(): int
    {
        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'File Catalog Sync' LIMIT 1"
            );
            $timeoutMinutes = max(1, (int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(120, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
    }

    private function shouldStopBeforePhase(float $startedAt, int $deadlineSeconds, int $minimumRemainingSeconds): bool
    {
        return $this->getRemainingBudgetSeconds($startedAt, $deadlineSeconds) < $minimumRemainingSeconds;
    }

    private function getRemainingBudgetSeconds(float $startedAt, int $deadlineSeconds): int
    {
        if ($deadlineSeconds <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, (int) floor($deadlineSeconds - (microtime(true) - $startedAt)));
    }

    private function resolvePhaseLimit(string $phase, int $limit, bool $multiPhaseRun): int
    {
        if ($limit <= 0 || ! $multiPhaseRun) {
            return $limit;
        }

        return match ($phase) {
            'scan' => min($limit, 100),
            'verify' => min($limit, 200),
            'rag_sync' => min($limit, max(25, (int) floor($limit * 0.5))),
            default => $limit,
        };
    }

    private function showSummary(array $results): void
    {
        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Operation', 'Result'],
            [
                ['Scan - Registered', $results['scan']['registered'] ?? 'skipped'],
                ['Verify - Orphaned', $results['verify']['orphaned'] ?? 'skipped'],
                ['RAG - Indexed', $results['rag_sync']['indexed'] ?? 'skipped'],
                ['RAG - Removed', $results['rag_sync']['removed'] ?? 'skipped'],
            ]
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    private function nextcloudLibraryRoot(): string
    {
        return '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
    }
}

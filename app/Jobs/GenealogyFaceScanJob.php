<?php

namespace App\Jobs;

use App\Services\Genealogy\GenealogyMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Background job for scanning Nextcloud folders for media with face metadata.
 *
 * This job can run for extended periods and reports progress via cache.
 * Progress can be monitored via API: GET /api/genealogy/trees/{tree}/media/face-scan-status
 */
class GenealogyFaceScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum job runtime in seconds (2 hours) */
    public int $timeout = 7200;

    /** Number of retry attempts */
    public int $tries = 1;

    protected int $treeId;
    protected string $folder;
    protected bool $recursive;
    protected string $jobId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $treeId, string $folder, bool $recursive = true)
    {
        $this->treeId = $treeId;
        $this->folder = $folder;
        $this->recursive = $recursive;
        $this->jobId = uniqid('face_scan_', true);

        // Use long-running queue due to 2-hour timeout
        $this->onQueue('long-running');
    }

    /**
     * Get the cache key for this job's status
     */
    public static function getStatusCacheKey(int $treeId): string
    {
        return "genealogy_face_scan_status_{$treeId}";
    }

    /**
     * Execute the job.
     */
    public function handle(GenealogyMediaService $mediaService): void
    {
        $cacheKey = self::getStatusCacheKey($this->treeId);

        try {
            // Set initial status
            $this->updateStatus($cacheKey, [
                'job_id' => $this->jobId,
                'status' => 'running',
                'started_at' => now()->toIso8601String(),
                'tree_id' => $this->treeId,
                'folder' => $this->folder,
                'recursive' => $this->recursive,
                'progress' => [
                    'files_scanned' => 0,
                    'files_with_faces' => 0,
                    'faces_found' => 0,
                    'imported' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'current_file' => null,
                ],
                'errors' => [],
            ]);

            Log::info("GenealogyFaceScanJob started", [
                'job_id' => $this->jobId,
                'tree_id' => $this->treeId,
                'folder' => $this->folder,
            ]);

            // Run the face scan with progress callback
            $results = $mediaService->scanNextcloudFolderWithFacesAsync(
                $this->treeId,
                $this->folder,
                $this->recursive,
                function ($progress) use ($cacheKey) {
                    // Update progress in cache
                    $current = Cache::get($cacheKey, []);
                    $current['progress'] = $progress;
                    $current['updated_at'] = now()->toIso8601String();
                    Cache::put($cacheKey, $current, now()->addHours(24));
                }
            );

            // Set completed status
            $this->updateStatus($cacheKey, [
                'job_id' => $this->jobId,
                'status' => 'completed',
                'started_at' => Cache::get($cacheKey)['started_at'] ?? now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'tree_id' => $this->treeId,
                'folder' => $this->folder,
                'recursive' => $this->recursive,
                'results' => $results,
                'errors' => $results['errors'] ?? [],
            ]);

            Log::info("GenealogyFaceScanJob completed", [
                'job_id' => $this->jobId,
                'tree_id' => $this->treeId,
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            // Set failed status
            $this->updateStatus($cacheKey, [
                'job_id' => $this->jobId,
                'status' => 'failed',
                'started_at' => Cache::get($cacheKey)['started_at'] ?? now()->toIso8601String(),
                'failed_at' => now()->toIso8601String(),
                'tree_id' => $this->treeId,
                'folder' => $this->folder,
                'error' => $e->getMessage(),
            ]);

            Log::error("GenealogyFaceScanJob failed", [
                'job_id' => $this->jobId,
                'tree_id' => $this->treeId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update job status in cache
     */
    protected function updateStatus(string $cacheKey, array $status): void
    {
        Cache::put($cacheKey, $status, now()->addHours(24));
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        $cacheKey = self::getStatusCacheKey($this->treeId);

        $this->updateStatus($cacheKey, [
            'job_id' => $this->jobId,
            'status' => 'failed',
            'failed_at' => now()->toIso8601String(),
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'error' => $exception->getMessage(),
        ]);

        Log::error("GenealogyFaceScanJob failed", [
            'job_id' => $this->jobId,
            'tree_id' => $this->treeId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

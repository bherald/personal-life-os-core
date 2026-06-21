<?php

namespace App\Jobs;

use App\Services\Genealogy\GenealogyMediaService;
use App\Services\SystemConfigService;
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

    private const RUNTIME_POLICY_CONFIG_KEY = 'genealogy.face_scan_job_runtime_policy';

    private const DEFAULT_TIMEOUT_SECONDS = 7200;

    private const DEFAULT_TRIES = 1;

    private const DEFAULT_QUEUE = 'long-running';

    private const DEFAULT_STATUS_CACHE_TTL_HOURS = 24;

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => self::DEFAULT_TIMEOUT_SECONDS,
        'tries' => self::DEFAULT_TRIES,
        'queue' => self::DEFAULT_QUEUE,
        'status_cache_ttl_hours' => self::DEFAULT_STATUS_CACHE_TTL_HOURS,
    ];

    public int $timeout = self::DEFAULT_TIMEOUT_SECONDS;

    public int $tries = self::DEFAULT_TRIES;

    protected int $treeId;

    protected string $folder;

    protected bool $recursive;

    protected string $jobId;

    private int $statusCacheTtlHours = self::DEFAULT_STATUS_CACHE_TTL_HOURS;

    private string $runtimePolicySource = 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY;

    /**
     * Create a new job instance.
     */
    public function __construct(int $treeId, string $folder, bool $recursive = true)
    {
        $this->treeId = $treeId;
        $this->folder = $folder;
        $this->recursive = $recursive;
        $this->jobId = uniqid('face_scan_', true);

        $policy = $this->runtimePolicy();
        $this->timeout = $this->positiveInt($policy['timeout_seconds'] ?? null, self::DEFAULT_TIMEOUT_SECONDS);
        $this->tries = $this->positiveInt($policy['tries'] ?? null, self::DEFAULT_TRIES);
        $this->statusCacheTtlHours = $this->positiveInt($policy['status_cache_ttl_hours'] ?? null, self::DEFAULT_STATUS_CACHE_TTL_HOURS);
        $this->onQueue($this->stringValue($policy['queue'] ?? null, self::DEFAULT_QUEUE));
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
                'timeout_seconds' => $this->timeout,
                'tries' => $this->tries,
                'status_cache_ttl_hours' => $this->statusCacheTtlHours,
                'runtime_policy_source' => $this->runtimePolicySource,
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

            Log::info('GenealogyFaceScanJob started', [
                'job_id' => $this->jobId,
                'tree_id' => $this->treeId,
                'folder' => $this->folder,
                'timeout_seconds' => $this->timeout,
                'tries' => $this->tries,
                'status_cache_ttl_hours' => $this->statusCacheTtlHours,
                'runtime_policy_source' => $this->runtimePolicySource,
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
                    Cache::put($cacheKey, $current, $this->statusCacheExpiresAt());
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
                'runtime_policy_source' => $this->runtimePolicySource,
            ]);

            Log::info('GenealogyFaceScanJob completed', [
                'job_id' => $this->jobId,
                'tree_id' => $this->treeId,
                'results' => $results,
                'runtime_policy_source' => $this->runtimePolicySource,
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
                'runtime_policy_source' => $this->runtimePolicySource,
            ]);

            Log::error('GenealogyFaceScanJob failed', [
                'job_id' => $this->jobId,
                'tree_id' => $this->treeId,
                'error' => $e->getMessage(),
                'runtime_policy_source' => $this->runtimePolicySource,
            ]);

            throw $e;
        }
    }

    /**
     * Update job status in cache
     */
    protected function updateStatus(string $cacheKey, array $status): void
    {
        Cache::put($cacheKey, $status, $this->statusCacheExpiresAt());
    }

    private function statusCacheExpiresAt(): \DateTimeInterface
    {
        return now()->addHours($this->statusCacheTtlHours);
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
            'runtime_policy_source' => $this->runtimePolicySource,
        ]);

        Log::error('GenealogyFaceScanJob failed', [
            'job_id' => $this->jobId,
            'tree_id' => $this->treeId,
            'error' => $exception->getMessage(),
            'runtime_policy_source' => $this->runtimePolicySource,
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return trim($value);
    }
}

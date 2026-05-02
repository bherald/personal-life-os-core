<?php

namespace App\Jobs;

use App\Services\AIAutoTagService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * AIAutoTagJob
 *
 * Queue job for AI auto-tagging of files in the registry.
 * Processes files individually to avoid timeouts and enable parallel processing.
 *
 * Queue: 'long-running' (AI/vision extraction can be slow)
 * Timeout: 5 minutes per file (vision/extraction can be slow)
 * Retries: 3 with exponential backoff
 */
class AIAutoTagJob implements ShouldQueue
{
    use Queueable;

    /**
     * Job timeout in seconds (5 minutes for complex documents)
     */
    public int $timeout = 300;

    /**
     * Number of retry attempts
     */
    public int $tries = 3;

    /**
     * Retry backoff in seconds (exponential)
     */
    public array $backoff = [30, 60, 180];

    /**
     * File registry ID to process
     */
    protected int $fileRegistryId;

    /**
     * Force refresh even if already analyzed
     */
    protected bool $forceRefresh;

    /**
     * Create a new job instance.
     */
    public function __construct(int $fileRegistryId, bool $forceRefresh = false)
    {
        $this->fileRegistryId = $fileRegistryId;
        $this->forceRefresh = $forceRefresh;

        // AI analysis is latency-heavy and should not contend with the shared default lane.
        $this->onQueue('long-running');
    }

    /**
     * Get middleware for the job.
     */
    public function middleware(): array
    {
        // Prevent duplicate processing of same file
        return [
            (new WithoutOverlapping("ai_tag_{$this->fileRegistryId}"))
                ->releaseAfter(60)
                ->expireAfter(300),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::channel('single')->info('AIAutoTagJob starting', [
            'file_registry_id' => $this->fileRegistryId,
            'force_refresh' => $this->forceRefresh,
        ]);

        try {
            $service = app(AIAutoTagService::class);

            $result = $service->analyzeFile($this->fileRegistryId, $this->forceRefresh);

            if ($result['skipped'] ?? false) {
                Log::channel('single')->debug('AIAutoTagJob skipped (already analyzed)', [
                    'file_registry_id' => $this->fileRegistryId,
                ]);
            } elseif ($result['success']) {
                Log::channel('single')->info('AIAutoTagJob completed', [
                    'file_registry_id' => $this->fileRegistryId,
                    'document_type' => $result['document_type'] ?? 'unknown',
                    'tag_count' => count($result['tags'] ?? []),
                    'provider' => $result['provider'] ?? 'unknown',
                ]);
            } else {
                Log::channel('single')->warning('AIAutoTagJob analysis failed', [
                    'file_registry_id' => $this->fileRegistryId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }

        } catch (\Exception $e) {
            Log::channel('single')->error('AIAutoTagJob failed', [
                'file_registry_id' => $this->fileRegistryId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw for retry
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('single')->error('AIAutoTagJob permanently failed', [
            'file_registry_id' => $this->fileRegistryId,
            'error' => $exception->getMessage(),
        ]);

        // Could optionally mark file as having analysis error
        // DB::update("UPDATE file_registry SET ai_analysis_error = ? WHERE id = ?", [
        //     $exception->getMessage(),
        //     $this->fileRegistryId,
        // ]);
    }

    /**
     * Get unique job ID for deduplication
     */
    public function uniqueId(): string
    {
        return "ai_tag_{$this->fileRegistryId}";
    }

    /**
     * Get job display name for Horizon
     */
    public function displayName(): string
    {
        return "AIAutoTag:file_{$this->fileRegistryId}";
    }

    /**
     * Get job tags for Horizon filtering
     */
    public function tags(): array
    {
        return [
            'ai-auto-tag',
            'file_registry',
            "file:{$this->fileRegistryId}",
        ];
    }
}

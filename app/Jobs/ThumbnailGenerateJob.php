<?php

namespace App\Jobs;

use App\Services\ThumbnailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * ThumbnailGenerateJob
 *
 * Queue job for generating thumbnails for files in the registry.
 * Follows the AIAutoTagJob pattern with WithoutOverlapping middleware.
 *
 * Queue: 'long-running' (image processing)
 * Timeout: 2 minutes per file
 * Retries: 2 with backoff
 */
class ThumbnailGenerateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 2;
    public array $backoff = [30, 60];

    protected string $assetUuid;

    public function __construct(string $assetUuid)
    {
        $this->assetUuid = $assetUuid;
        $this->onQueue('long-running');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("thumb_{$this->assetUuid}"))
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    public function handle(): void
    {
        Log::channel('single')->info('ThumbnailGenerateJob starting', [
            'asset_uuid' => $this->assetUuid,
        ]);

        try {
            $service = app(ThumbnailService::class);
            $results = $service->generateAllSizes($this->assetUuid);

            $successes = 0;
            $failures = 0;
            foreach ($results as $size => $result) {
                if ($result['success']) {
                    $successes++;
                } else {
                    $failures++;
                }
            }

            Log::channel('single')->info('ThumbnailGenerateJob completed', [
                'asset_uuid' => $this->assetUuid,
                'successes' => $successes,
                'failures' => $failures,
            ]);
        } catch (\Exception $e) {
            Log::channel('single')->error('ThumbnailGenerateJob failed', [
                'asset_uuid' => $this->assetUuid,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('single')->error('ThumbnailGenerateJob permanently failed', [
            'asset_uuid' => $this->assetUuid,
            'error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "thumb_{$this->assetUuid}";
    }

    public function displayName(): string
    {
        return "Thumbnail:{$this->assetUuid}";
    }

    public function tags(): array
    {
        return [
            'thumbnail',
            'file_registry',
            "uuid:{$this->assetUuid}",
        ];
    }
}

<?php

namespace App\Jobs;

use App\Services\SystemConfigService;
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

    private const RUNTIME_POLICY_CONFIG_KEY = 'files.thumbnail_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 120,
        'tries' => 2,
        'backoff_seconds' => [30, 60],
        'overlap_release_after_seconds' => 30,
        'overlap_expire_after_seconds' => 120,
    ];

    public int $timeout = 120;
    public int $tries = 2;
    public array $backoff = [30, 60];

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    protected string $assetUuid;

    public function __construct(string $assetUuid)
    {
        $this->assetUuid = $assetUuid;
        $this->applyRuntimePolicy();
        $this->onQueue('long-running');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("thumb_{$this->assetUuid}"))
                ->releaseAfter($this->runtimePolicyInt('overlap_release_after_seconds'))
                ->expireAfter($this->runtimePolicyInt('overlap_expire_after_seconds')),
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

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('timeout_seconds');
        $this->tries = $this->runtimePolicyInt('tries');
        $this->backoff = $this->runtimePolicyIntList('backoff_seconds');
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return list<int>
     */
    private function runtimePolicyIntList(string $key): array
    {
        $fallback = self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (! is_array($value)) {
            return $fallback;
        }

        $ints = array_values(array_filter(
            array_map(fn ($item) => is_numeric($item) ? (int) $item : 0, $value),
            fn (int $item) => $item > 0
        ));

        return $ints !== [] ? $ints : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }
}

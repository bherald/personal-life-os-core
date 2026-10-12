<?php

namespace App\Jobs;

use App\Services\FileRegistryLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RetireFileRegistryDerivedArtifacts implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $fileRegistryId,
        public string $assetUuid,
        public string $generation,
        public string $cutoff,
        public ?string $oldSourceSha256,
        public int $tries,
        public int $backoff
    ) {}

    public function handle(FileRegistryLifecycleService $lifecycle): void
    {
        $result = $lifecycle->retireDiscoveryArtifacts(
            $this->fileRegistryId, $this->assetUuid, $this->generation, $this->cutoff, $this->oldSourceSha256
        );
        Log::info('FileRegistryLifecycle: discovery retirement result', [
            'asset_uuid' => $this->assetUuid, 'lifecycle_generation_key' => $this->generation, 'result' => $result,
        ]);
    }
}

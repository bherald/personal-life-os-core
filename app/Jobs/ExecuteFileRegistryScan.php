<?php

namespace App\Jobs;

use App\Services\FileRegistryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteFileRegistryScan implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(
        private string $path,
        private int $limit
    ) {
        $this->onQueue('long-running');
    }

    public function handle(FileRegistryService $fileRegistry): void
    {
        Log::info('Starting queued file registry scan', [
            'path' => $this->path,
            'limit' => $this->limit,
        ]);

        $result = $fileRegistry->scanAndRegisterNew($this->path, $this->limit);

        Log::info('Queued file registry scan completed', [
            'path' => $this->path,
            'limit' => $this->limit,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued file registry scan failed', [
            'path' => $this->path,
            'limit' => $this->limit,
            'error' => $exception?->getMessage(),
        ]);
    }
}

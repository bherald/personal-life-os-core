<?php

namespace App\Jobs;

use App\Services\FileCategorizationRAGService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteFileCatalogRagSync implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(private int $limit)
    {
        $this->onQueue('long-running');
    }

    public function handle(FileCategorizationRAGService $ragService): void
    {
        Log::info('Starting queued file catalog RAG sync', [
            'limit' => $this->limit,
        ]);

        $result = $ragService->syncWithRegistry($this->limit);

        Log::info('Queued file catalog RAG sync completed', [
            'limit' => $this->limit,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued file catalog RAG sync failed', [
            'limit' => $this->limit,
            'error' => $exception?->getMessage(),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Services\EmailSuggestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteEmailSuggestionScan implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        private string $folder,
        private int $limit
    ) {
        $this->onQueue('long-running');
    }

    public function handle(EmailSuggestionService $suggestionService): void
    {
        Log::info('Queued email suggestion scan started', [
            'folder' => $this->folder,
            'limit' => $this->limit,
        ]);

        $result = $suggestionService->scanAndProcess($this->folder, $this->limit);

        Log::info('Queued email suggestion scan completed', [
            'folder' => $this->folder,
            'limit' => $this->limit,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued email suggestion scan failed', [
            'folder' => $this->folder,
            'limit' => $this->limit,
            'error' => $exception?->getMessage(),
        ]);
    }
}

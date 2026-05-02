<?php

namespace App\Jobs;

use App\Services\Research\SourceOptimizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteResearchCategoryRefresh implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(private string $category)
    {
        $this->onQueue('long-running');
    }

    public function handle(SourceOptimizationService $optimizationService): void
    {
        Log::info('Queued research category refresh started', [
            'category' => $this->category,
        ]);

        $result = $optimizationService->refreshCategorySources($this->category);

        Log::info('Queued research category refresh completed', [
            'category' => $this->category,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued research category refresh failed', [
            'category' => $this->category,
            'error' => $exception?->getMessage(),
        ]);
    }
}

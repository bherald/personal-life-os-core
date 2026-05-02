<?php

namespace App\Jobs;

use App\Services\Research\SourceOptimizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteResearchMaintenance implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(private array $operations)
    {
        $this->onQueue('long-running');
    }

    public function handle(SourceOptimizationService $optimizationService): void
    {
        Log::info('Queued research maintenance started', [
            'operations' => $this->operations,
        ]);

        $result = [];

        if ($this->operations['heal'] ?? false) {
            $result['healing'] = $optimizationService->runSelfHealing();
        }
        if ($this->operations['optimize'] ?? false) {
            $result['rule_optimization'] = $optimizationService->optimizeDiscoveryRules();
        }
        if ($this->operations['report'] ?? false) {
            $result['health_report'] = $optimizationService->generateHealthReport();
        }
        if (!empty($this->operations['category'])) {
            $result['category_refresh'] = $optimizationService->refreshCategorySources($this->operations['category']);
        }

        Log::info('Queued research maintenance completed', [
            'operations' => $this->operations,
            'result_keys' => array_keys($result),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued research maintenance failed', [
            'operations' => $this->operations,
            'error' => $exception?->getMessage(),
        ]);
    }
}

<?php

namespace App\Traits;

use App\DTOs\RecursionResult;
use App\Services\RecursiveCallService;
use Illuminate\Support\Facades\Log;

/**
 * RLM: Trait for services that support recursive task decomposition.
 *
 * Add this trait to any service, then call tryRecursive() in the main method.
 * If recursion is disabled or unavailable, returns null and the service
 * proceeds with its normal non-recursive logic.
 *
 * Usage:
 *   use RecursionAware;
 *
 *   public function mainMethod(array $input): array {
 *       $rlm = $this->tryRecursive('service_name', 'strategy', $input, fn($ctx) => $this->processChunk($ctx));
 *       if ($rlm !== null) return $rlm;
 *       // ... normal non-recursive path
 *   }
 */
trait RecursionAware
{
    private ?RecursiveCallService $recursionService = null;

    /**
     * Inject RecursiveCallService. Called by Laravel container or manually.
     */
    public function setRecursionService(?RecursiveCallService $service): void
    {
        $this->recursionService = $service;
    }

    /**
     * Try recursive execution. Returns null if recursion is disabled/unavailable.
     *
     * @param string   $serviceName  Config key in recursion_config table
     * @param string   $strategy     partition_map, quality_gate_retry, evidence_chase, hierarchical_summarize
     * @param array    $context      Input data to process
     * @param callable $processFn    fn(array $subContext): mixed — the service's existing logic
     * @param array    $parentBudget Optional parent budget [tokens, time, cost]
     * @return array|null            Aggregated result, or null if recursion not used
     */
    protected function tryRecursive(
        string $serviceName,
        string $strategy,
        array $context,
        callable $processFn,
        array $parentBudget = []
    ): ?array {
        if ($this->recursionService === null) {
            // Try to resolve from container
            try {
                $this->recursionService = app(RecursiveCallService::class);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            $result = $this->recursionService->execute(
                $serviceName,
                $strategy,
                $context,
                $processFn,
                $parentBudget ?: null
            );

            if (!$result->recursionUsed) {
                return null; // Fall back to normal path
            }

            // Log metrics for observability
            Log::info("RLM[{$serviceName}]: recursive execution complete", $result->metrics->toArray());

            return $result->output;
        } catch (\Throwable $e) {
            Log::warning("RLM[{$serviceName}]: recursion failed, falling back", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if recursion is available and enabled for this service.
     */
    protected function isRecursionEnabled(string $serviceName): bool
    {
        if ($this->recursionService === null) {
            try {
                $this->recursionService = app(RecursiveCallService::class);
            } catch (\Throwable) {
                return false;
            }
        }

        return $this->recursionService->isMasterEnabled()
            && ($this->recursionService->getServiceConfig($serviceName)['enabled'] ?? false);
    }
}

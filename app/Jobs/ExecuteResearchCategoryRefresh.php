<?php

namespace App\Jobs;

use App\Services\Research\SourceOptimizationService;
use App\Services\SystemConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteResearchCategoryRefresh implements ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'research.source_maintenance_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'category_refresh_job_timeout_seconds' => 1800,
        'category_refresh_job_tries' => 1,
    ];

    public int $timeout = 1800;

    public int $tries = 1;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(private string $category)
    {
        $this->applyRuntimePolicy();

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

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('category_refresh_job_timeout_seconds');
        $this->tries = $this->runtimePolicyInt('category_refresh_job_tries');
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
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

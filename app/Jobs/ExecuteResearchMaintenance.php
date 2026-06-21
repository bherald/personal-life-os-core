<?php

namespace App\Jobs;

use App\Services\Research\SourceOptimizationService;
use App\Services\SystemConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteResearchMaintenance implements ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'research.source_maintenance_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'maintenance_job_timeout_seconds' => 3600,
        'maintenance_job_tries' => 1,
    ];

    public int $timeout = 3600;

    public int $tries = 1;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(private array $operations)
    {
        $this->applyRuntimePolicy();

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
        if (! empty($this->operations['category'])) {
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

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('maintenance_job_timeout_seconds');
        $this->tries = $this->runtimePolicyInt('maintenance_job_tries');
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

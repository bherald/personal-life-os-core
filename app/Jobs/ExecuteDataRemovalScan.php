<?php

namespace App\Jobs;

use App\Services\SystemConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteDataRemovalScan implements ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'data_removal.data_removal_scan_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 3600,
        'tries' => 1,
    ];

    public $timeout = 3600;
    public $tries = 1;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(private array $args)
    {
        $this->applyRuntimePolicy();

        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        Log::info('Starting queued data removal scan', [
            'args' => $this->args,
        ]);

        $exitCode = Artisan::call('data-removal:scan', $this->args);

        Log::info('Queued data removal scan completed', [
            'args' => $this->args,
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued data removal scan failed', [
            'args' => $this->args,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('timeout_seconds');
        $this->tries = $this->runtimePolicyInt('tries');
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

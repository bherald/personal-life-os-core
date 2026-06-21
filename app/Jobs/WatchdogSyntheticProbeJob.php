<?php

namespace App\Jobs;

use App\Services\SystemConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * APL #8B layer 9 — synthetic transaction probe job.
 *
 * The only side effect is writing the probe ID to a Redis key with a short
 * TTL. `WatchdogSyntheticTransactionCommand` dispatches this job and polls
 * for that key — appearance = the whole dispatch → enqueue → supervisor →
 * worker → execute → Redis-write chain is healthy.
 *
 * Intentionally cheap: no DB writes, no LLM, no external HTTP. Must not
 * become a load source.
 */
class WatchdogSyntheticProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const REDIS_PREFIX = 'watchdog:synthetic:';

    public const TTL_SECONDS = 120;

    private const RUNTIME_POLICY_CONFIG_KEY = 'ops.watchdog_synthetic_probe_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'ttl_seconds' => self::TTL_SECONDS,
        'tries' => 1,
        'timeout_seconds' => 10,
    ];

    public int $tries = 1;

    public int $timeout = 10;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(public readonly string $probeId)
    {
        $this->applyRuntimePolicy();
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Redis::setex(
            self::REDIS_PREFIX.$this->probeId,
            $this->runtimePolicyInt('ttl_seconds'),
            (string) time()
        );
    }

    private function applyRuntimePolicy(): void
    {
        $this->tries = $this->runtimePolicyInt('tries');
        $this->timeout = $this->runtimePolicyInt('timeout_seconds');
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

<?php

namespace App\Jobs;

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

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $probeId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Redis::setex(
            self::REDIS_PREFIX.$this->probeId,
            self::TTL_SECONDS,
            (string) time()
        );
    }
}

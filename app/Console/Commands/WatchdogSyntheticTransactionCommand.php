<?php

namespace App\Console\Commands;

use App\Jobs\WatchdogSyntheticProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * APL #8B layer 9 — synthetic transaction observer (artisan-side half).
 *
 * Dispatches a `WatchdogSyntheticProbeJob` onto the `default` queue with a
 * unique probe ID, then polls Redis for the key the job is supposed to
 * write. If the key appears within the deadline: queue loop is healthy →
 * exit 0. If the deadline passes with no key: queue loop is wedged →
 * exit 1.
 *
 * Invoked from `framework-watchdog.sh` with a hard shell timeout so an
 * in-app hang is still caught by the outside observer.
 */
class WatchdogSyntheticTransactionCommand extends Command
{
    protected $signature = 'watchdog:synthetic-transaction
        {--deadline=30 : Max seconds to wait for the probe job to complete}
        {--poll-interval-ms=250 : How often to poll Redis while waiting}';

    protected $description = 'APL #8B layer 9: dispatch a probe job and verify end-to-end queue completion';

    public function handle(): int
    {
        $deadline = max(1, (int) $this->option('deadline'));
        $pollIntervalMs = max(50, (int) $this->option('poll-interval-ms'));

        $probeId = bin2hex(random_bytes(8));
        $key = WatchdogSyntheticProbeJob::REDIS_PREFIX.$probeId;

        $dispatchedAt = microtime(true);

        try {
            WatchdogSyntheticProbeJob::dispatch($probeId);
        } catch (\Throwable $e) {
            $this->error(sprintf('[FAIL] dispatch threw: %s', $e->getMessage()));
            return self::FAILURE;
        }

        $deadlineAt = $dispatchedAt + $deadline;
        $completedAt = null;

        while (microtime(true) < $deadlineAt) {
            if ((bool) Redis::exists($key)) {
                $completedAt = microtime(true);
                break;
            }
            usleep($pollIntervalMs * 1000);
        }

        if ($completedAt === null) {
            // Clean up any late-arriving marker so a wedged worker doesn't
            // leave stale state behind once it catches up.
            $this->scheduleCleanup($key);

            $this->error(sprintf(
                '[FAIL] synthetic probe %s did not complete within %ds (queue wedged or supervisor down)',
                $probeId,
                $deadline
            ));
            return self::FAILURE;
        }

        $elapsedMs = (int) round(($completedAt - $dispatchedAt) * 1000);
        $this->info(sprintf('[OK] synthetic probe %s completed in %dms', $probeId, $elapsedMs));

        Redis::del($key);

        $this->info(sprintf('[ITEMS_PROCESSED:1]'));

        return self::SUCCESS;
    }

    /**
     * Schedule a late-arrival cleanup so a probe that eventually runs (after
     * we've already declared FAIL) doesn't leak a stale Redis key past its
     * TTL.
     *
     * No-op today — Redis's own TTL on the SETEX write already handles this;
     * keeping this as an extension point for a future slice that might want
     * to observe late arrivals for stall-detection analysis.
     */
    private function scheduleCleanup(string $key): void
    {
        // Intentionally empty. See method docblock.
    }
}

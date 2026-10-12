<?php

namespace App\Jobs;

use App\Services\FileRegistryLifecycleService;
use App\Services\FileRegistryService;
use App\Services\SystemConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class ExecuteFileRegistryScan implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'files.file_registry_scan_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 3600,
        'tries' => 1,
    ];

    public $timeout = 3600;

    public $tries = 1;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    private bool $computeHash = true;

    private string $uniqueCacheStore;

    public function __construct(
        private string $path,
        private int $limit,
        bool $computeHash = true,
        ?array $queuePolicy = null,
    ) {
        $this->computeHash = $computeHash;
        $queuePolicy ??= app(FileRegistryLifecycleService::class)->prepareDiscoveryRetirement();
        self::assertDurableQueue($queuePolicy);
        $this->uniqueCacheStore = self::durableCacheName();
        $this->applyRuntimePolicy();

        $this->onConnection($queuePolicy['connection'])->onQueue($queuePolicy['queue']);
    }

    public function handle(FileRegistryService $fileRegistry): void
    {
        Log::info('Starting queued file registry scan', [
            'path' => $this->path,
            'limit' => $this->limit,
        ]);

        $result = $fileRegistry->scanAndRegisterNew($this->path, $this->limit, ['compute_hash' => $this->computeHash, 'deadline' => hrtime(true) / 1000000000 + $this->timeout]);

        Log::info('Queued file registry discovery slice finished', [
            'path' => $this->path,
            'limit' => $this->limit,
            'result' => $result,
        ]);
    }

    /** Shared caller admission after the coordinator has released committed custody. */
    public static function admitContinuation(string $path, int $limit, bool $computeHash, array $result, array $queuePolicy): array
    {
        if (! in_array($result['state'] ?? null, ['pending', 'waiting'], true)
            || ($result['stop_reason'] ?? null) === 'cancelled'
            || ($result['intake_complete'] ?? false) === true
            || ! is_int($result['pending_work'] ?? null) || ! is_int($result['held_work'] ?? null)
            || $result['pending_work'] <= $result['held_work']) {
            return ['state' => 'not_needed'];
        }
        if (in_array($result['stop_reason'] ?? null, ['pending_capacity', 'depth_capacity',
            'directory_record_budget_too_small', 'directory_output_budget_too_small', 'metadata_probe_budget'], true)
            && ($result['slice']['intake_attempts'] ?? 0) === 0
            && ($result['scanned'] ?? 0) === 0 && ($result['deferred_unstable'] ?? 0) === 0) {
            return ['state' => 'not_needed'];
        }
        $policy = app(SystemConfigService::class)->get('files.registry_discovery_policy', config('file_registry.discovery.policy'));
        if (is_string($policy)) {
            $policy = json_decode($policy, true);
        }
        $backoff = is_array($policy) ? ($policy['backoff_seconds'] ?? null) : null;
        $ceiling = config('file_registry.discovery.bounds.backoff_seconds');
        if (! is_int($backoff) || ! is_int($ceiling) || $backoff < 1 || $ceiling < 1) {
            Log::warning('File registry continuation held: invalid registered delay policy');

            return ['state' => 'held', 'reason' => 'invalid_delay_policy'];
        }
        $next = CarbonImmutable::now('UTC')->addSeconds(min($backoff, $ceiling));
        if (($result['state'] ?? null) === 'waiting') {
            try {
                if (! is_string($result['next_attempt_at'] ?? null)) {
                    return ['state' => 'held', 'reason' => 'missing_wait_deadline'];
                }
                $due = CarbonImmutable::parse($result['next_attempt_at'], 'UTC');
                if ($due->greaterThan($next)) {
                    $next = $due;
                }
            } catch (Throwable $e) {
                return ['state' => 'held', 'reason' => 'invalid_wait_deadline'];
            }
        }
        $job = null;
        $lock = null;
        $acquired = false;
        try {
            if (isset($result['scope']) && $result['scope'] !== $path) {
                throw new RuntimeException('Continuation scope does not match committed discovery.');
            }
            $job = new self($path, $limit, $computeHash, $queuePolicy);
            $job->delay($next)->afterCommit();
            $lock = new UniqueLock($job->uniqueVia());
            if (! ($acquired = $lock->acquire($job))) {
                return ['state' => 'deduplicated', 'next_attempt_at' => $next->format('Y-m-d H:i:s.u')];
            }
            // Direct framework dispatcher avoids acquiring the same uniqueness lock twice.
            Bus::dispatch($job);

            return ['state' => 'queued', 'next_attempt_at' => $next->format('Y-m-d H:i:s.u')];
        } catch (Throwable $e) {
            if ($acquired && $lock !== null && $job !== null) {
                $lock->release($job);
            }
            Log::warning('File registry continuation admission held', ['exception' => $e::class]);

            return ['state' => 'held', 'reason' => 'durable_continuation_unavailable'];
        }
    }

    public function uniqueId(): string
    {
        return hash('sha256', json_encode([$this->path, $this->computeHash], JSON_THROW_ON_ERROR));
    }

    public function uniqueFor(): int
    {
        $delay = $this->delay instanceof \DateTimeInterface ? max(0, $this->delay->getTimestamp() - CarbonImmutable::now('UTC')->getTimestamp()) : 0;

        return $this->timeout + $delay;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store($this->uniqueCacheStore ?? self::durableCacheName());
    }

    private static function assertDurableQueue(array $policy): void
    {
        $connection = $policy['connection'] ?? null;
        if (! is_string($connection) || ! is_string($policy['queue'] ?? null) || trim($policy['queue']) === ''
            || config('queue.connections.'.$connection.'.driver') !== 'database') {
            throw new RuntimeException('Discovery continuation requires an admitted database queue.');
        }
        $queue = Queue::connection($connection);
        $database = DB::connection();
        if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== $database
            || $queue->getDatabase()->getPdo() !== $database->getPdo() || $database->transactionLevel() !== 0) {
            throw new RuntimeException('Discovery continuation requires committed custody and the intake database.');
        }
        $table = config('queue.connections.'.$connection.'.table');
        if (! is_string($table) || ! $database->getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException('Discovery durable queue table is unavailable.');
        }
    }

    private static function durableCacheName(): string
    {
        $name = 'database';
        if (config('cache.stores.'.$name.'.driver') !== 'database') {
            throw new RuntimeException('Discovery uniqueness requires configured shared database locks.');
        }
        $store = Cache::store($name)->getStore();
        $database = DB::connection();
        if (! $store instanceof DatabaseStore || $store->getConnection() !== $database
            || $store->getLockConnection() !== $database || $database->transactionLevel() !== 0) {
            throw new RuntimeException('Discovery uniqueness must share the intake database.');
        }

        $lockTable = config('cache.stores.'.$name.'.lock_table') ?: 'cache_locks';
        if (! is_string($lockTable) || ! $database->getSchemaBuilder()->hasTable($lockTable)) {
            throw new RuntimeException('Discovery shared database lock table is unavailable.');
        }

        return $name;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued file registry scan failed', [
            'path' => $this->path,
            'limit' => $this->limit,
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

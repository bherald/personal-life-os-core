<?php

namespace App\Console\Commands;

use App\Services\FileRegistryLifecycleService;
use App\Services\SystemConfigService;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class FilesDiscoveryDrainCommand extends Command
{
    protected $signature = 'files:discovery-drain {--status : Inspect admission and aggregate backlog without starting a worker} {--json : Emit aggregate JSON}';

    protected $description = 'Consume a bounded slice of the dedicated durable discovery queue';

    public function handle(SystemConfigService $config, FileRegistryLifecycleService $lifecycle): int
    {
        $report = ['state' => 'held', 'reason' => null, 'worker_started' => false, 'before' => null, 'after' => null];
        $lock = null;
        $acquired = false;
        try {
            if (app()->isDownForMaintenance()) {
                throw new RuntimeException('maintenance_mode');
            }
            $policy = $config->get('files.registry_discovery_worker_policy', config('file_registry.discovery.worker'));
            if (is_string($policy)) {
                $policy = json_decode($policy, true, 512, JSON_THROW_ON_ERROR);
            }
            if (! is_array($policy)) {
                throw new RuntimeException('invalid_worker_policy');
            }
            $retirement = $lifecycle->prepareDiscoveryRetirement();
            if (($policy['connection'] ?? null) !== $retirement['connection'] || ($policy['queue'] ?? null) !== $retirement['queue']
                || in_array($policy['queue'], config('file_registry.discovery.worker_bounds.shared_queues', []), true)) {
                throw new RuntimeException('dedicated_queue_policy_mismatch');
            }
            foreach (['max_jobs', 'max_seconds', 'hard_timeout_seconds', 'outer_timeout_seconds', 'timeout_seconds', 'tries', 'sleep_seconds', 'memory_mb', 'lock_seconds'] as $key) {
                $bound = config('file_registry.discovery.worker_bounds.'.$key);
                if (! is_int($policy[$key] ?? null) || $policy[$key] < 1 || ! is_int($bound) || $policy[$key] > $bound) {
                    throw new RuntimeException('invalid_worker_bounds');
                }
            }
            $retryAfter = config('queue.connections.'.$policy['connection'].'.retry_after');
            $scheduled = DB::table('scheduled_jobs')->where('name', 'file_registry_discovery_worker')->first();
            if (! $scheduled || $scheduled->command !== 'files:discovery-drain --json' || (int) $scheduled->enabled !== 1
                || (int) $scheduled->run_in_background !== 1 || (int) $scheduled->without_overlapping !== 1 || (int) $scheduled->max_parallel !== 1) {
                throw new RuntimeException('worker_scheduler_admission_invalid');
            }
            $scheduledTimeout = $scheduled->timeout_minutes;
            if (! is_numeric($retryAfter) || $policy['hard_timeout_seconds'] >= (int) $retryAfter
                || ! is_numeric($scheduledTimeout) || (int) $scheduledTimeout * 60 <= $policy['outer_timeout_seconds']
                || $policy['max_seconds'] >= $policy['hard_timeout_seconds']
                || $policy['timeout_seconds'] >= $policy['hard_timeout_seconds']
                || $policy['outer_timeout_seconds'] <= $policy['hard_timeout_seconds']
                || $policy['lock_seconds'] <= $policy['outer_timeout_seconds'] || $policy['lock_seconds'] >= (int) $retryAfter) {
                throw new RuntimeException('worker_timeout_fence_invalid');
            }
            $timeoutBinary = $policy['timeout_binary'] ?? null;
            if (! is_string($timeoutBinary) || ! is_file($timeoutBinary) || ! is_executable($timeoutBinary)) {
                throw new RuntimeException('independent_timeout_unavailable');
            }
            $storeName = $policy['cache_store'] ?? null;
            if (! is_string($storeName) || config('cache.stores.'.$storeName.'.driver') !== 'database') {
                throw new RuntimeException('shared_worker_lock_unavailable');
            }
            $cache = Cache::store($storeName);
            $store = $cache->getStore();
            if (! $store instanceof DatabaseStore || $store->getConnection() !== DB::connection()
                || $store->getLockConnection() !== DB::connection() || DB::transactionLevel() !== 0) {
                throw new RuntimeException('shared_worker_lock_unavailable');
            }
            $lockTable = config('cache.stores.'.$storeName.'.lock_table') ?: 'cache_locks';
            if (! is_string($lockTable) || ! DB::connection()->getSchemaBuilder()->hasTable($lockTable)) {
                throw new RuntimeException('shared_worker_lock_unavailable');
            }
            $report['before'] = $this->backlog($policy, (int) $retryAfter);
            if ($this->option('status')) {
                $report['state'] = 'admitted';
            } elseif ($report['before']['ready'] === 0) {
                $report['state'] = 'idle';
            } else {
                $lock = $cache->lock('files:discovery-drain:'.hash('sha256', $policy['connection'].':'.$policy['queue']), $policy['lock_seconds']);
                $acquired = $lock->get();
                if (! $acquired) {
                    throw new RuntimeException('worker_busy');
                }
                $report['worker_started'] = true;
                try {
                    // GNU timeout owns a separate process-group deadline even if this parent dies.
                    // Omit --foreground so SIGKILL reaches the worker and its ordinary descendants.
                    $result = Process::path(base_path())->timeout($policy['outer_timeout_seconds'])->quietly()->run([
                        $timeoutBinary, '--signal=KILL', $policy['hard_timeout_seconds'].'s',
                        PHP_BINARY, base_path('artisan'), 'queue:work', $policy['connection'],
                        '--queue='.$policy['queue'], '--max-jobs='.$policy['max_jobs'],
                        '--max-time='.$policy['max_seconds'], '--timeout='.$policy['timeout_seconds'],
                        '--tries='.$policy['tries'], '--sleep='.$policy['sleep_seconds'], '--memory='.$policy['memory_mb'],
                        '--no-interaction',
                    ]);
                    $timedOut = in_array($result->exitCode(), [124, 137], true);
                    $report['state'] = $timedOut ? 'timed_out' : ($result->successful() ? 'drained_slice' : 'failed');
                    $report['reason'] = $timedOut ? 'worker_hard_timeout' : ($result->successful() ? null : 'worker_nonzero_exit');
                    $report['worker_exit_code'] = $result->exitCode();
                } catch (ProcessTimedOutException) {
                    $report['state'] = 'timed_out';
                    $report['reason'] = 'worker_hard_timeout';
                }
            }
            $report['after'] = $this->backlog($policy, (int) $retryAfter);
            if ($report['after']['failed'] > $report['before']['failed'] && $report['state'] === 'drained_slice') {
                $report['state'] = 'failed';
                $report['reason'] ??= 'queue_jobs_failed';
            }
        } catch (Throwable $e) {
            $report['reason'] = $e instanceof RuntimeException && in_array($e->getMessage(), [
                'maintenance_mode', 'invalid_worker_policy', 'dedicated_queue_policy_mismatch',
                'invalid_worker_bounds', 'worker_timeout_fence_invalid',
                'shared_worker_lock_unavailable', 'worker_busy',
                'worker_scheduler_admission_invalid',
                'independent_timeout_unavailable',
            ], true) ? $e->getMessage() : 'worker_admission_or_reporting_unavailable';
            $report['state'] = 'held';
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    $report['state'] = 'held';
                    $report['reason'] = 'worker_lock_release_unavailable';
                }
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Discovery worker: '.$report['state'].($report['reason'] ? ' ('.$report['reason'].')' : ''));
            foreach (['before', 'after'] as $phase) {
                if ($report[$phase] !== null) {
                    $this->line($phase.': '.json_encode($report[$phase], JSON_THROW_ON_ERROR));
                }
            }
        }

        return in_array($report['state'], ['admitted', 'idle', 'drained_slice'], true) ? self::SUCCESS : self::FAILURE;
    }

    private function backlog(array $policy, int $retryAfter): array
    {
        $table = config('queue.connections.'.$policy['connection'].'.table');
        $query = DB::table($table)->where('queue', $policy['queue']);
        $time = now()->timestamp;

        return [
            'pending' => (clone $query)->count(),
            'ready' => (clone $query)->where('available_at', '<=', $time)->where(fn ($q) => $q
                ->whereNull('reserved_at')->orWhere('reserved_at', '<=', $time - $retryAfter))->count(),
            'reserved' => (clone $query)->whereNotNull('reserved_at')->count(),
            'delayed' => (clone $query)->whereNull('reserved_at')->where('available_at', '>', $time)->count(),
            'failed' => DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))
                ->where('connection', $policy['connection'])->where('queue', $policy['queue'])->count(),
        ];
    }
}

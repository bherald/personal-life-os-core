<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * N106 — Dynamic Compute Routing Service
 *
 * Generalizes the LLMPoolManagerService pattern to GPU/CPU compute tasks
 * (HTR, NLP, face detection, community detection, etc.).
 *
 * DB-driven routing: `compute_instances` table stores hosts, capabilities,
 * circuit breaker state, health scores. Services call executeScript() with
 * a capability name; this service routes to the best available instance,
 * acquires locks, executes (local shell_exec or remote SSH), records metrics.
 *
 * Circuit breaker: same open/half_open/closed state machine as LLMPoolManagerService.
 * GPU locks: per-instance via `compute_gpu_lock:{id}`. Transition compatibility
 * on the primary local GPU role checks legacy whisper_gpu_lock and
 * ollama_busy_lock before granting.
 */
class ComputeRouterService
{
    private const CACHE_KEY_ALL = 'compute_instances_all';

    private const CACHE_KEY_HEALTHY = 'compute_instances_healthy';

    /**
     * Get all active compute instances, optionally filtered to healthy only.
     *
     * @return array<object>
     */
    public function getInstances(bool $onlyHealthy = false): array
    {
        $cacheKey = $onlyHealthy ? self::CACHE_KEY_HEALTHY : self::CACHE_KEY_ALL;
        $ttl = config('compute.cache_ttl', 300);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $sql = 'SELECT * FROM compute_instances WHERE is_active = 1';
        if ($onlyHealthy) {
            $sql .= " AND is_healthy = 1 AND circuit_state = 'closed'";
        }
        $sql .= ' ORDER BY priority ASC';

        $instances = DB::select($sql);
        Cache::put($cacheKey, $instances, $ttl);

        return $instances;
    }

    /**
     * Get a single instance by its instance_id.
     */
    public function getInstance(string $id): ?object
    {
        return DB::selectOne('SELECT * FROM compute_instances WHERE instance_id = ?', [$id]);
    }

    /**
     * Route to the best available instance for a given capability.
     *
     * Filters by capability, circuit state, VRAM requirements.
     * Scores by weighted formula: priority 40%, health 30%, speed 30%.
     *
     * @param  string  $capability  e.g. 'htr', 'nlp', 'face_detection'
     * @param  array  $requirements  Optional: ['min_vram_mb' => 4096]
     * @return object|null Best instance or null if none available
     */
    public function route(string $capability, array $requirements = []): ?object
    {
        $instances = $this->getInstances(false);
        $candidates = [];

        foreach ($instances as $instance) {
            // Check capability
            $caps = json_decode($instance->capabilities ?? '[]', true) ?: [];
            if (! in_array($capability, $caps, true)) {
                continue;
            }

            // Check circuit state
            if (! $this->isCircuitClosed($instance)) {
                continue;
            }

            // Check VRAM requirement
            if (! empty($requirements['min_vram_mb']) && ($instance->gpu_vram_mb ?? 0) < $requirements['min_vram_mb']) {
                continue;
            }

            // Check instance is not busy (all slots taken)
            if ($this->isInstanceBusy($instance)) {
                continue;
            }

            $candidates[] = $instance;
        }

        if (empty($candidates)) {
            return null;
        }

        // Score and sort
        usort($candidates, function ($a, $b) {
            return $this->calculateScore($b) <=> $this->calculateScore($a);
        });

        return $candidates[0];
    }

    /**
     * Route + execute a Python script on the best available instance.
     *
     * @param  string  $capability  Capability to route for
     * @param  string  $scriptName  Script filename (e.g. 'htr_transcribe.py')
     * @param  string  $args  Arguments/stdin for the script
     * @param  array  $requirements  Optional routing requirements
     * @param  int|null  $timeout  Override timeout (seconds)
     * @return array ['success'=>bool, 'output'=>string|null, 'instance_id'=>string, 'duration_ms'=>int, 'error'=>string|null]
     */
    public function executeScript(string $capability, string $scriptName, string $args = '', array $requirements = [], ?int $timeout = null): array
    {
        $instance = $this->route($capability, $requirements);
        if (! $instance) {
            return [
                'success' => false,
                'output' => null,
                'instance_id' => null,
                'duration_ms' => 0,
                'error' => "No available compute instance for capability: {$capability}",
            ];
        }

        $scriptPath = $this->resolveScriptPath($instance, $scriptName);
        $cmd = $this->buildScriptCommand($instance, $scriptPath, $args, $timeout);

        return $this->execute($instance, $cmd, $timeout);
    }

    /**
     * Execute a command on a specific instance.
     * Acquires GPU lock, runs command, records metrics, releases lock.
     *
     * @return array ['success'=>bool, 'output'=>string|null, 'instance_id'=>string, 'duration_ms'=>int, 'error'=>string|null]
     */
    public function execute(object $instance, string $command, ?int $timeout = null): array
    {
        $instanceId = $instance->instance_id;

        // Acquire lock
        if (! $this->acquireGpuLock($instance)) {
            return [
                'success' => false,
                'output' => null,
                'instance_id' => $instanceId,
                'duration_ms' => 0,
                'error' => "Could not acquire lock for {$instanceId} (GPU busy)",
            ];
        }

        $startMs = microtime(true) * 1000;

        try {
            if ($instance->is_local) {
                $procTimeout = $timeout ?? config('compute.ssh_timeout', 30);
                $result = Process::timeout($procTimeout)->run(['bash', '-lc', $command.' 2>/dev/null']);
                if ($result->failed()) {
                    $durationMs = (int) (microtime(true) * 1000 - $startMs);
                    $errorMsg = mb_substr($result->errorOutput() ?: $result->output(), 0, 500);
                    Log::warning('ComputeRouterService: local command failed', [
                        'instance' => $instanceId,
                        'exitCode' => $result->exitCode(),
                        'error' => $errorMsg,
                    ]);
                    $this->recordFailure($instanceId, "Exit code {$result->exitCode()}: {$errorMsg}");

                    return [
                        'success' => false,
                        'output' => null,
                        'instance_id' => $instanceId,
                        'duration_ms' => $durationMs,
                        'error' => "Command failed (exit {$result->exitCode()}): {$errorMsg}",
                    ];
                }
                $output = $result->output();
            } else {
                $output = $this->executeRemote($instance, $command, $timeout);
            }

            $durationMs = (int) (microtime(true) * 1000 - $startMs);

            if ($output === null || $output === false || $output === '') {
                $this->recordFailure($instanceId, 'Empty output from command');

                return [
                    'success' => false,
                    'output' => null,
                    'instance_id' => $instanceId,
                    'duration_ms' => $durationMs,
                    'error' => 'Command returned empty output',
                ];
            }

            $this->recordSuccess($instanceId, $durationMs);

            return [
                'success' => true,
                'output' => $output,
                'instance_id' => $instanceId,
                'duration_ms' => $durationMs,
                'error' => null,
            ];
        } catch (\Exception $e) {
            $durationMs = (int) (microtime(true) * 1000 - $startMs);
            $this->recordFailure($instanceId, $e->getMessage());

            return [
                'success' => false,
                'output' => null,
                'instance_id' => $instanceId,
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->releaseGpuLock($instance);
        }
    }

    /**
     * Execute a command on a remote instance via SSH.
     */
    private function executeRemote(object $instance, string $command, ?int $timeout = null): ?string
    {
        $sshTimeout = $timeout ?? config('compute.ssh_timeout', 30);
        $sshUser = $instance->ssh_user ?? config('compute.ssh_user_default', 'plos');
        $host = $instance->host;

        $sshArgs = [
            'ssh',
            '-o', 'ConnectTimeout=5',
            '-o', 'StrictHostKeyChecking=no',
            "{$sshUser}@{$host}",
            $command,
        ];

        try {
            $result = Process::timeout($sshTimeout)->run($sshArgs);

            if ($result->failed()) {
                Log::warning('ComputeRouterService: SSH command failed', [
                    'host' => $host,
                    'exitCode' => $result->exitCode(),
                    'error' => mb_substr($result->errorOutput(), 0, 300),
                ]);

                return null;
            }

            return $result->output();
        } catch (\Exception $e) {
            Log::warning('ComputeRouterService: SSH exception', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build the full command to execute a Python script on an instance.
     */
    private function buildScriptCommand(object $instance, string $scriptPath, string $args, ?int $timeout = null): string
    {
        $python = $instance->python_path ?? 'python3';
        $timeoutSec = $timeout ?? config('compute.ssh_timeout', 30);

        $cmd = sprintf('timeout %d %s %s', $timeoutSec, escapeshellarg($python), escapeshellarg($scriptPath));

        if ($args !== '') {
            // Use printf instead of echo so escaped JSON newlines stay escaped
            // when document content is piped to Python scripts.
            $cmd = sprintf('printf %%s %s | %s', escapeshellarg($args), $cmd);
        }

        return $cmd;
    }

    /**
     * Resolve full script path for an instance.
     */
    private function resolveScriptPath(object $instance, string $scriptName): string
    {
        return rtrim($instance->scripts_path, '/').'/'.$scriptName;
    }

    // ──────────────────────────────────────────────
    // Circuit Breaker
    // ──────────────────────────────────────────────

    /**
     * Check if circuit is closed (ready for requests).
     * Advances open → half_open when cooldown has elapsed.
     */
    public function isCircuitClosed(object $instance): bool
    {
        if ($instance->circuit_state === 'closed') {
            return true;
        }

        if ($instance->circuit_state === 'half_open') {
            return true; // allow a test request
        }

        // State is 'open' — check if cooldown has elapsed
        if ($instance->circuit_retry_at && strtotime($instance->circuit_retry_at) <= time()) {
            $this->setCircuitState($instance->instance_id, 'half_open');

            return true;
        }

        return false;
    }

    /**
     * Record a successful execution — close circuit if half_open.
     */
    public function recordSuccess(string $instanceId, int $durationMs = 0): void
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance) {
            return;
        }

        $healthDelta = config('compute.health_delta_success', 5);
        $maxScore = config('health_thresholds.compute.score_max', 100);
        $newHealth = min($maxScore, $instance->health_score + $healthDelta);
        $newTotal = $instance->total_executions + 1;
        $newRate = $newTotal > 0 ? round((($newTotal - $instance->total_failures) / $newTotal) * 100, 2) : 100;

        // Exponential moving average for execution time
        $newAvg = $instance->avg_execution_ms
            ? round(($instance->avg_execution_ms * 0.8) + ($durationMs * 0.2), 2)
            : $durationMs;

        DB::update('
            UPDATE compute_instances
            SET health_score = ?,
                total_executions = total_executions + 1,
                consecutive_failures = 0,
                success_rate = ?,
                avg_execution_ms = ?,
                is_healthy = 1,
                updated_at = NOW()
            WHERE instance_id = ?
        ', [$newHealth, $newRate, $newAvg, $instanceId]);

        // Close circuit if was half_open
        if ($instance->circuit_state === 'half_open') {
            $this->setCircuitState($instanceId, 'closed');
        }

        $this->clearInstanceCache();
    }

    /**
     * Record a failed execution — open circuit if threshold hit.
     */
    public function recordFailure(string $instanceId, string $reason = ''): void
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance) {
            return;
        }

        $healthDelta = config('compute.health_delta_failure', 10);
        $minScore = config('health_thresholds.compute.score_min', 0);
        $unhealthyThreshold = config('health_thresholds.compute.unhealthy_threshold', 30);
        $newHealth = max($minScore, $instance->health_score - $healthDelta);
        $newConsecutive = $instance->consecutive_failures + 1;
        $newTotal = $instance->total_executions + 1;
        $newFailures = $instance->total_failures + 1;
        $newRate = $newTotal > 0 ? round((($newTotal - $newFailures) / $newTotal) * 100, 2) : 0;

        DB::update('
            UPDATE compute_instances
            SET health_score = ?,
                total_executions = total_executions + 1,
                total_failures = total_failures + 1,
                consecutive_failures = ?,
                success_rate = ?,
                is_healthy = ?,
                updated_at = NOW()
            WHERE instance_id = ?
        ', [
            $newHealth,
            $newConsecutive,
            $newRate,
            $newHealth >= $unhealthyThreshold ? 1 : 0,
            $instanceId,
        ]);

        // Open circuit if threshold hit
        $threshold = config('compute.circuit_breaker.failure_threshold', 5);
        if ($newConsecutive >= $threshold) {
            $this->openCircuit($instanceId, $reason);
        }

        $this->clearInstanceCache();

        Log::warning('ComputeRouterService: failure recorded', [
            'instance' => $instanceId,
            'consecutive' => $newConsecutive,
            'reason' => mb_substr($reason, 0, 200),
        ]);
    }

    /**
     * Open the circuit breaker for an instance.
     */
    public function openCircuit(string $instanceId, string $reason = ''): void
    {
        $cooldown = config('compute.circuit_breaker.cooldown_seconds', 60);
        $retryAt = date('Y-m-d H:i:s', time() + $cooldown);

        DB::update("
            UPDATE compute_instances
            SET circuit_state = 'open',
                circuit_opened_at = NOW(),
                circuit_retry_at = ?,
                is_healthy = 0,
                updated_at = NOW()
            WHERE instance_id = ?
        ", [$retryAt, $instanceId]);

        $this->clearInstanceCache();

        Log::warning('ComputeRouterService: circuit opened', [
            'instance' => $instanceId,
            'retry_at' => $retryAt,
            'reason' => mb_substr($reason, 0, 200),
        ]);
    }

    /**
     * Set circuit state directly (e.g., close after successful half-open test).
     */
    public function setCircuitState(string $instanceId, string $state): void
    {
        $updates = 'circuit_state = ?, updated_at = NOW()';
        $params = [$state];

        if ($state === 'closed') {
            $updates .= ', consecutive_failures = 0, circuit_opened_at = NULL, circuit_retry_at = NULL';
        }

        $params[] = $instanceId;
        DB::update("UPDATE compute_instances SET {$updates} WHERE instance_id = ?", $params);

        $this->clearInstanceCache();
    }

    // ──────────────────────────────────────────────
    // GPU Locks
    // ──────────────────────────────────────────────

    /**
     * Check if an instance has all its concurrent slots occupied.
     */
    public function isInstanceBusy(object $instance): bool
    {
        $maxConcurrent = $instance->max_concurrent ?? 1;
        $lockKey = "compute_gpu_lock:{$instance->instance_id}";

        if ($maxConcurrent <= 1) {
            return Cache::has($lockKey);
        }

        // Multi-slot: count active locks
        $activeSlots = 0;
        for ($i = 0; $i < $maxConcurrent; $i++) {
            if (Cache::has("{$lockKey}:{$i}")) {
                $activeSlots++;
            }
        }

        return $activeSlots >= $maxConcurrent;
    }

    /**
     * Acquire a GPU/compute lock for an instance.
     * On the primary local GPU role, also checks legacy whisper_gpu_lock and
     * ollama_busy_lock.
     */
    public function acquireGpuLock(object $instance): bool
    {
        $instanceId = $instance->instance_id;
        $isGpu = ($instance->gpu_vram_mb ?? 0) > 0;
        $lockTtl = $isGpu
            ? config('lock_ttls.compute_gpu', 300)
            : config('lock_ttls.compute_cpu', 120);

        // If this instance shares its GPU with Ollama/Whisper, check their locks
        if (! empty($instance->shares_gpu_with_llm)) {
            if (Cache::has('whisper_gpu_lock') || Cache::has('ollama_busy_lock')) {
                return false;
            }
        }

        $lockKey = "compute_gpu_lock:{$instanceId}";
        $maxConcurrent = $instance->max_concurrent ?? 1;

        if ($maxConcurrent <= 1) {
            return Cache::add($lockKey, getmypid(), $lockTtl);
        }

        // Multi-slot: find and acquire first free slot
        for ($i = 0; $i < $maxConcurrent; $i++) {
            if (Cache::add("{$lockKey}:{$i}", getmypid(), $lockTtl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Release the GPU/compute lock for an instance.
     */
    public function releaseGpuLock(object $instance): void
    {
        $lockKey = "compute_gpu_lock:{$instance->instance_id}";
        $maxConcurrent = $instance->max_concurrent ?? 1;

        if ($maxConcurrent <= 1) {
            Cache::forget($lockKey);

            return;
        }

        // Multi-slot: release the slot held by this PID
        $pid = getmypid();
        for ($i = 0; $i < $maxConcurrent; $i++) {
            $slotKey = "{$lockKey}:{$i}";
            if (Cache::get($slotKey) == $pid) {
                Cache::forget($slotKey);

                return;
            }
        }

        // Fallback: release any slot (shouldn't reach here normally)
        for ($i = 0; $i < $maxConcurrent; $i++) {
            $slotKey = "{$lockKey}:{$i}";
            if (Cache::has($slotKey)) {
                Cache::forget($slotKey);

                return;
            }
        }
    }

    // ──────────────────────────────────────────────
    // Health Checks
    // ──────────────────────────────────────────────

    /**
     * Run health checks on all active instances.
     *
     * @return array Per-instance health check results
     */
    public function healthCheckAll(): array
    {
        $instances = $this->getInstances(false);
        $results = [];

        foreach ($instances as $instance) {
            $results[$instance->instance_id] = $this->healthCheckInstance($instance);
        }

        return $results;
    }

    /**
     * Health-check a single instance.
     * GPU instances: nvidia-smi. CPU instances: SSH connectivity test.
     */
    public function healthCheckInstance(object $instance): array
    {
        $startMs = microtime(true) * 1000;
        $hasGpu = ($instance->gpu_vram_mb ?? 0) > 0;

        try {
            if ($instance->is_local) {
                $result = $this->healthCheckLocal($instance, $hasGpu);
            } else {
                $result = $this->healthCheckRemote($instance, $hasGpu);
            }

            $durationMs = (int) (microtime(true) * 1000 - $startMs);
            $result['duration_ms'] = $durationMs;
            $result['instance_id'] = $instance->instance_id;

            // Update health state
            if ($result['healthy']) {
                $this->updateInstanceHealth($instance->instance_id, true);
            } else {
                $this->updateInstanceHealth($instance->instance_id, false);
            }

            return $result;

        } catch (\Exception $e) {
            $this->updateInstanceHealth($instance->instance_id, false);

            return [
                'instance_id' => $instance->instance_id,
                'healthy' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) (microtime(true) * 1000 - $startMs),
            ];
        }
    }

    /**
     * Probe unhealthy or open-circuit instances to see if they recovered.
     */
    public function probeUnhealthyInstances(): array
    {
        $instances = DB::select("
            SELECT * FROM compute_instances
            WHERE is_active = 1
              AND (is_healthy = 0 OR circuit_state != 'closed')
        ");

        $results = [];
        foreach ($instances as $instance) {
            $check = $this->healthCheckInstance($instance);
            if ($check['healthy'] && $instance->circuit_state !== 'closed') {
                $this->setCircuitState($instance->instance_id, 'closed');
                $check['circuit_reset'] = true;
            }
            $results[$instance->instance_id] = $check;
        }

        return $results;
    }

    private function healthCheckLocal(object $instance, bool $hasGpu): array
    {
        if ($hasGpu) {
            try {
                $result = Process::timeout(10)->run([
                    'nvidia-smi',
                    '--query-gpu=name,memory.total,memory.free,utilization.gpu',
                    '--format=csv,noheader,nounits',
                ]);

                if ($result->failed() || empty($result->output())) {
                    Log::debug('ComputeRouterService: local nvidia-smi failed', [
                        'instance' => $instance->instance_id,
                        'exitCode' => $result->exitCode(),
                        'error' => mb_substr($result->errorOutput(), 0, 200),
                    ]);

                    return ['healthy' => false, 'error' => 'nvidia-smi failed or not available'];
                }

                $parts = array_map('trim', explode(',', trim($result->output())));
            } catch (\Exception $e) {
                Log::debug('ComputeRouterService: local nvidia-smi exception', [
                    'instance' => $instance->instance_id,
                    'error' => $e->getMessage(),
                ]);

                return ['healthy' => false, 'error' => 'nvidia-smi exception: '.$e->getMessage()];
            }

            return [
                'healthy' => true,
                'gpu_name' => $parts[0] ?? 'unknown',
                'memory_total_mb' => (int) ($parts[1] ?? 0),
                'memory_free_mb' => (int) ($parts[2] ?? 0),
                'gpu_utilization' => (int) ($parts[3] ?? 0),
            ];
        }

        // CPU instance — just verify scripts_path exists
        return [
            'healthy' => is_dir($instance->scripts_path),
            'type' => 'cpu',
            'scripts_path_exists' => is_dir($instance->scripts_path),
        ];
    }

    private function healthCheckRemote(object $instance, bool $hasGpu): array
    {
        $sshUser = $instance->ssh_user ?? config('compute.ssh_user_default', 'plos');
        $host = $instance->host;

        $remoteCmd = $hasGpu
            ? 'nvidia-smi --query-gpu=name,memory.total,memory.free,utilization.gpu --format=csv,noheader,nounits'
            : 'echo ok';

        $sshArgs = [
            'ssh',
            '-o', 'ConnectTimeout=5',
            '-o', 'StrictHostKeyChecking=no',
            "{$sshUser}@{$host}",
            $remoteCmd,
        ];

        try {
            $result = Process::timeout(15)->run($sshArgs);

            if ($result->failed() || empty($result->output())) {
                Log::debug('ComputeRouterService: remote health check failed', [
                    'host' => $host,
                    'exitCode' => $result->exitCode(),
                    'error' => mb_substr($result->errorOutput(), 0, 200),
                ]);

                return ['healthy' => false, 'error' => "SSH to {$host} failed or GPU unavailable"];
            }

            $output = $result->output();
        } catch (\Exception $e) {
            Log::debug('ComputeRouterService: remote health check exception', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return ['healthy' => false, 'error' => "SSH to {$host} exception: ".$e->getMessage()];
        }

        if ($hasGpu) {
            $parts = array_map('trim', explode(',', trim($output)));

            return [
                'healthy' => true,
                'gpu_name' => $parts[0] ?? 'unknown',
                'memory_total_mb' => (int) ($parts[1] ?? 0),
                'memory_free_mb' => (int) ($parts[2] ?? 0),
                'gpu_utilization' => (int) ($parts[3] ?? 0),
            ];
        }

        return ['healthy' => trim($output) === 'ok', 'type' => 'cpu'];
    }

    /**
     * Update health state after a health check.
     */
    private function updateInstanceHealth(string $instanceId, bool $healthy): void
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance) {
            return;
        }

        $delta = $healthy
            ? config('compute.health_delta_success', 5)
            : config('compute.health_delta_failure', 10);

        $maxScore = config('health_thresholds.compute.score_max', 100);
        $minScore = config('health_thresholds.compute.score_min', 0);
        $unhealthyThreshold = config('health_thresholds.compute.unhealthy_threshold', 30);

        $newHealth = $healthy
            ? min($maxScore, $instance->health_score + $delta)
            : max($minScore, $instance->health_score - $delta);

        DB::update('
            UPDATE compute_instances
            SET is_healthy = ?,
                health_score = ?,
                last_health_check = NOW(),
                updated_at = NOW()
            WHERE instance_id = ?
        ', [
            $newHealth >= $unhealthyThreshold ? 1 : 0,
            $newHealth,
            $instanceId,
        ]);

        $this->clearInstanceCache();
    }

    // ──────────────────────────────────────────────
    // Status / Monitoring
    // ──────────────────────────────────────────────

    /**
     * Get full pool status for agents/monitoring.
     */
    public function getStatus(): array
    {
        $instances = $this->getInstances(false);
        $status = [];

        foreach ($instances as $instance) {
            $caps = json_decode($instance->capabilities ?? '[]', true) ?: [];
            $status[] = [
                'instance_id' => $instance->instance_id,
                'host' => $instance->host,
                'is_local' => (bool) $instance->is_local,
                'gpu_model' => $instance->gpu_model,
                'gpu_vram_mb' => $instance->gpu_vram_mb,
                'capabilities' => $caps,
                'priority' => $instance->priority,
                'health_score' => $instance->health_score,
                'circuit_state' => $instance->circuit_state,
                'is_healthy' => (bool) $instance->is_healthy,
                'is_busy' => $this->isInstanceBusy($instance),
                'avg_execution_ms' => $instance->avg_execution_ms,
                'success_rate' => $instance->success_rate,
                'total_executions' => $instance->total_executions,
                'consecutive_failures' => $instance->consecutive_failures,
                'last_health_check' => $instance->last_health_check,
            ];
        }

        return [
            'instances' => $status,
            'total' => count($status),
            'healthy' => count(array_filter($status, fn ($s) => $s['is_healthy'])),
            'open_circuits' => count(array_filter($status, fn ($s) => $s['circuit_state'] !== 'closed')),
        ];
    }

    /**
     * Get instances formatted for agent monitoring tools.
     */
    public function getInstancesForMonitoring(): array
    {
        return $this->getStatus();
    }

    // ──────────────────────────────────────────────
    // Internal
    // ──────────────────────────────────────────────

    /**
     * Calculate routing score for an instance.
     * Weighted: priority 40%, health 30%, speed 30%.
     */
    private function calculateScore(object $instance): float
    {
        $wPriority = config('health_thresholds.compute.weight_priority', 0.40);
        $wHealth = config('health_thresholds.compute.weight_health', 0.30);
        $wSpeed = config('health_thresholds.compute.weight_speed', 0.30);

        // Priority: lower = better → invert (50 - priority) / 50 gives higher score for lower priority
        $priorityScore = max(0, (50 - $instance->priority) / 50) * 100;

        // Health: direct mapping 0-100
        $healthScore = $instance->health_score;

        // Speed: inverse of avg_execution_ms, capped
        $avgMs = $instance->avg_execution_ms ?? 5000; // default 5s if no data
        $speedScore = max(0, min(100, 100 - ($avgMs / 200))); // 0ms=100, 20000ms=0

        return ($priorityScore * $wPriority) + ($healthScore * $wHealth) + ($speedScore * $wSpeed);
    }

    /**
     * Clear the instance cache.
     */
    private function clearInstanceCache(): void
    {
        Cache::forget(self::CACHE_KEY_ALL);
        Cache::forget(self::CACHE_KEY_HEALTHY);
    }
}

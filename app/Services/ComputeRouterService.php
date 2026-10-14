<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Compute\ComputeExecutionCustodyService;
use App\Services\Compute\ComputeTerminalProcessResult;
use App\Services\Ops\JetsonTegrastatsParserService;
use Carbon\CarbonInterface;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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
 * Owned GPU locks use `compute_gpu_lock:{id}[:slot]:owned`; legacy PID markers stay holds.
 * Transition compatibility
 * on the primary local GPU role checks legacy whisper_gpu_lock and
 * ollama_busy_lock before granting.
 */
class ComputeRouterService
{
    /** Legacy public API owns at most one handle per instance; execution handles are invocation-local. */
    private array $publicGpuLocks = [];

    private static ?string $requiredInstance = null;

    private static bool $workflowScopeActive = false;

    private static ?\Closure $workflowOutputObserver = null;

    private static ?\Closure $workflowDispatchObserver = null;

    /** Scope an operator selection to one synchronous workflow, retaining table admission. */
    public static function withRequiredInstance(string $selection, \Closure $operation, ?\Closure $outputObserver = null, ?\Closure $dispatchObserver = null): mixed
    {
        if ($selection === '' || trim($selection) !== $selection || strlen($selection) > 191
            || preg_match('/[\x00-\x1f\x7f]/', $selection)) {
            throw new \InvalidArgumentException('Choose auto or an exact registered compute instance.');
        }
        $previous = self::$requiredInstance;
        $previousScope = self::$workflowScopeActive;
        $previousObserver = self::$workflowOutputObserver;
        $previousDispatchObserver = self::$workflowDispatchObserver;
        self::$requiredInstance = $selection === 'auto' ? null : $selection;
        self::$workflowScopeActive = true;
        self::$workflowOutputObserver = $outputObserver ?? $previousObserver;
        self::$workflowDispatchObserver = $dispatchObserver ?? $previousDispatchObserver;
        try {
            return $operation();
        } finally {
            self::$requiredInstance = $previous;
            self::$workflowScopeActive = $previousScope;
            self::$workflowOutputObserver = $previousObserver;
            self::$workflowDispatchObserver = $previousDispatchObserver;
        }
    }

    /** Manual selection reads the current target row rather than the shared inventory cache. */
    private function routingInstances(): array
    {
        if (self::$requiredInstance === null) {
            return array_map($this->executionInstance(...), $this->getInstances(false));
        }
        $instance = $this->getInstance(self::$requiredInstance);

        return $instance !== null && (bool) $instance->is_active ? [$this->executionInstance($instance)] : [];
    }

    /** Production's synced local flag is relative to production, not a dev workflow worker. */
    private function executionInstance(object $instance): object
    {
        if (! self::$workflowScopeActive || empty($instance->is_local)) {
            return $instance;
        }
        $localHosts = ['localhost', gethostname()];
        foreach (net_get_interfaces() ?: [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $address) {
                if (isset($address['address'])) {
                    $localHosts[] = $address['address'];
                }
            }
        }
        if (in_array($instance->host ?? null, $localHosts, true)) {
            return $instance;
        }
        $resolved = clone $instance;
        $resolved->is_local = false;

        return $resolved;
    }

    private const SHARED_RESOURCE_ADMISSION_HOLD = 'shared_resource_admission_unqualified';

    /** A group string cannot establish atomic cross-surface physical resource custody. */
    private function sharedResourceAdmissionReason(object $instance): ?string
    {
        return ! empty($instance->shares_gpu_with_llm) ? self::SHARED_RESOURCE_ADMISSION_HOLD : null;
    }

    private const CACHE_KEY_ALL = 'compute_instances_all';

    private const CACHE_KEY_HEALTHY = 'compute_instances_healthy';

    private readonly ComputeExecutionCustodyService $executionCustody;

    private array $ownedLockSlots = [];

    public function __construct(private readonly ComputeRouterRuntimePolicyService $runtimePolicy, ?ComputeExecutionCustodyService $executionCustody = null)
    {
        $this->executionCustody = $executionCustody ?? app(ComputeExecutionCustodyService::class);
    }

    /**
     * Get all active compute instances, optionally filtered to healthy only.
     *
     * @return array<object>
     */
    public function getInstances(bool $onlyHealthy = false): array
    {
        $cacheKey = $onlyHealthy ? self::CACHE_KEY_HEALTHY : self::CACHE_KEY_ALL;
        $ttl = $this->runtimePolicy->cacheTtlSeconds();

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
     * Filters by capability, fresh health, circuit state, and VRAM requirements.
     * Scores priority, health, observed speed, and available slot headroom.
     *
     * @param  string  $capability  e.g. 'htr', 'nlp', 'face_detection'
     * @param  array  $requirements  Optional: ['min_vram_mb' => 4096]
     * @return object|null Best instance or null if none available
     */
    public function route(string $capability, array $requirements = []): ?object
    {
        $instances = $this->routingInstances();
        $candidates = [];

        foreach ($instances as $instance) {
            // Check capability
            $caps = json_decode($instance->capabilities ?? '[]', true) ?: [];
            if (! in_array($capability, $caps, true)) {
                continue;
            }

            if (! (bool) $instance->is_healthy || ! $this->isHealthFresh($instance)) {
                continue;
            }

            // Check circuit state
            if (! $this->isCircuitClosed($instance)) {
                continue;
            }

            if ($this->sharedResourceAdmissionReason($instance) !== null) {
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
     * Return bounded routing evidence for one exact compute capability.
     *
     * @return array{capability:string,state:string,registered:int,healthy:int,fresh:int,circuit_closed:int,available:int,busy:int,admission_held:int,reason_code:?string}
     */
    public function getCapabilityStatus(string $capability): array
    {
        $capability = trim($capability);
        $summary = [
            'capability' => $capability,
            'state' => 'unregistered',
            'registered' => 0,
            'healthy' => 0,
            'fresh' => 0,
            'circuit_closed' => 0,
            'available' => 0,
            'busy' => 0,
            'admission_held' => 0,
            'reason_code' => null,
        ];

        if ($capability === '') {
            return $summary;
        }

        foreach ($this->routingInstances() as $instance) {
            $capabilities = json_decode($instance->capabilities ?? '[]', true) ?: [];
            if (! in_array($capability, $capabilities, true)) {
                continue;
            }

            $summary['registered']++;
            if (! (bool) $instance->is_healthy) {
                continue;
            }

            $summary['healthy']++;
            if (! $this->isHealthFresh($instance)) {
                continue;
            }

            $summary['fresh']++;
            if (($reason = $this->sharedResourceAdmissionReason($instance)) !== null) {
                $summary['admission_held']++;
                $summary['reason_code'] = $reason;

                continue;
            }
            if ($instance->circuit_state !== 'closed') {
                continue;
            }

            $summary['circuit_closed']++;
            if ($this->isInstanceBusy($instance)) {
                $summary['busy']++;

                continue;
            }

            $summary['available']++;
        }

        $summary['state'] = match (true) {
            $summary['available'] > 0 => 'available',
            $summary['registered'] === 0 => 'unregistered',
            $summary['healthy'] === 0 => 'unhealthy',
            $summary['fresh'] === 0 => 'health_stale',
            $summary['admission_held'] > 0 => self::SHARED_RESOURCE_ADMISSION_HOLD,
            $summary['circuit_closed'] === 0 => 'circuit_open',
            $summary['busy'] > 0 => 'capacity_busy',
            default => 'unavailable',
        };

        return $summary;
    }

    /**
     * Recheck only stale, unhealthy, or open-circuit workers for a capability.
     *
     * @return array{capability:string,state:string,registered:int,healthy:int,fresh:int,circuit_closed:int,available:int,busy:int,recovery_attempted:int,recovered:bool}
     */
    public function recoverCapability(string $capability): array
    {
        $before = $this->getCapabilityStatus($capability);
        $attempted = 0;

        if ($before['state'] !== 'available' && $before['registered'] > 0) {
            foreach ($this->routingInstances() as $instance) {
                $capabilities = json_decode($instance->capabilities ?? '[]', true) ?: [];
                if (! in_array($capability, $capabilities, true)) {
                    continue;
                }

                if ((bool) $instance->is_healthy
                    && $this->isHealthFresh($instance)
                    && $instance->circuit_state === 'closed') {
                    continue;
                }

                $this->healthCheckInstance($instance);
                $attempted++;
            }
        }

        $after = $this->getCapabilityStatus($capability);
        $after['recovery_attempted'] = $attempted;
        $after['recovered'] = $before['state'] !== 'available' && $after['state'] === 'available';

        return $after;
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
            $status = $this->getCapabilityStatus($capability);
            if (($status['reason_code'] ?? null) === self::SHARED_RESOURCE_ADMISSION_HOLD) {
                throw new InferenceAdmissionException(self::SHARED_RESOURCE_ADMISSION_HOLD, 0, null, ['dispatched' => false]);
            }

            return [
                'success' => false,
                'output' => null,
                'instance_id' => null,
                'duration_ms' => 0,
                'error' => self::$requiredInstance === null
                    ? "No available compute instance for capability: {$capability}"
                    : 'Selected compute instance '.self::$requiredInstance." is unavailable for {$capability}; no alternate host was selected.",
            ];
        }

        $scriptPath = $this->resolveScriptPath($instance, $scriptName);
        $cmd = $this->buildScriptCommand($instance, $scriptPath, $timeout);

        return $this->executeWithInput($instance, $cmd, $args, $timeout);
    }

    /**
     * Execute a command on a specific instance.
     * Acquires GPU lock, runs command, records metrics, releases lock.
     *
     * @return array ['success'=>bool, 'output'=>string|null, 'instance_id'=>string, 'duration_ms'=>int, 'error'=>string|null]
     */
    public function execute(object $instance, string $command, ?int $timeout = null): array
    {
        return $this->executeWithInput($instance, $command, '', $timeout);
    }

    /**
     * Execute a command while streaming sensitive payloads over stdin.
     *
     * @return array ['success'=>bool, 'output'=>string|null, 'instance_id'=>string, 'duration_ms'=>int, 'error'=>string|null]
     */
    private function executeWithInput(object $instance, string $command, string $input = '', ?int $timeout = null): array
    {
        $instance = $this->executionInstance($instance);
        $instanceId = $instance->instance_id;
        if (self::$requiredInstance !== null && $instanceId !== self::$requiredInstance) {
            return ['success' => false, 'output' => null, 'instance_id' => $instanceId, 'duration_ms' => 0,
                'error' => 'Compute command targets a different host from the operator selection; execution was refused.'];
        }

        if (($reason = $this->sharedResourceAdmissionReason($instance)) !== null) {
            throw new InferenceAdmissionException($reason, 0, null, ['dispatched' => false]);
        }

        // Acquire lock
        $gpuLock = $this->acquireOwnedGpuLock($instance);
        if ($gpuLock === null) {
            return [
                'success' => false,
                'output' => null,
                'instance_id' => $instanceId,
                'duration_ms' => 0,
                'error' => "Could not acquire lock for {$instanceId} (GPU busy)",
            ];
        }

        $startMs = microtime(true) * 1000;
        $result = null;
        $failureCode = null;
        $custody = null;
        $dispatched = false;
        $terminal = false;

        try {
            $procTimeout = $timeout ?? $this->runtimePolicy->executionTimeoutSeconds();
            $captureLimits = $this->runtimePolicy->captureLimits(strlen($input), self::$workflowOutputObserver !== null);
            $ownerToken = (string) Str::uuid();
            $supervisedCommand = $this->supervisedCommand($instance, $command, $ownerToken, $procTimeout, $captureLimits['raw_bytes']);
            $custody = $this->executionCustody->acquire($instanceId, $this->ownedLockSlots[spl_object_id($gpuLock)],
                $ownerToken, $gpuLock->owner(), $this->runtimePolicy->custodyTimeoutSeconds($procTimeout));
            if ($custody === null) {
                throw new InferenceAdmissionException('Compute persisted slot custody is unavailable; dispatch held.');
            }
            $this->executionCustody->dispatching($custody);
            if (app()->isDownForMaintenance()) {
                try {
                    $this->executionCustody->noDispatch($custody);
                } catch (\Throwable $error) {
                    throw new InferenceAdmissionException('Compute no-dispatch terminal publication is unproven; hold retained.', 0, $error);
                }
                $terminal = true;
                $failureCode = 'compute_maintenance_no_dispatch';
                throw new InferenceAdmissionException('Compute entered maintenance before runner dispatch; no command started.');
            }
            if (self::$workflowDispatchObserver !== null) {
                // Bind the existing durable supervisor identity before launching
                // any child. A failed observer must not become a dispatch.
                try {
                    (self::$workflowDispatchObserver)(['schema' => 'plos.compute_dispatch_binding.v1',
                        'custody_id' => (int) $custody->id, 'surface_ref' => $custody->surface_ref,
                        'owner_token' => $ownerToken, 'instance_id' => $instanceId,
                        'command_sha256' => hash('sha256', $command)]);
                } catch (\Throwable $error) {
                    $this->executionCustody->noDispatch($custody);
                    $terminal = true;
                    $failureCode = 'compute_dispatch_binding_refused';
                    throw new InferenceAdmissionException('Compute dispatch binding failed before launch.', 0, $error,
                        ['dispatched' => false]);
                }
            }
            // Persist dispatch intent before run: process startup itself may fork before throwing.
            $dispatched = true;
            $transportArguments = $instance->is_local ? $supervisedCommand
                : $this->buildSshArguments($instance, implode(' ', array_map(escapeshellarg(...), $supervisedCommand)));
            $result = $this->runBoundedTransport($transportArguments, $input, $procTimeout, $captureLimits, $result);
            [$result, $receipt] = $this->terminalResult($result, $ownerToken, $command, $captureLimits);
            $this->executionCustody->terminal($custody, $receipt);
            $terminal = true;
            if (! $receipt['dispatched']) {
                $failureCode = 'compute_supervisor_unavailable';

                return ['success' => false, 'output' => null, 'instance_id' => $instanceId,
                    'duration_ms' => (int) (microtime(true) * 1000 - $startMs), 'error' => $failureCode];
            }
            if ($result->failed()) {
                $durationMs = (int) (microtime(true) * 1000 - $startMs);
                $failureCode = 'process_exit_'.max(0, (int) $result->exitCode());
                $this->recordFailure($instanceId, $failureCode);
                Log::warning('ComputeRouterService: command failed', [
                    'instance' => $instanceId, 'exitCode' => $result->exitCode(), 'duration_ms' => $durationMs,
                ]);

                return ['success' => false, 'output' => null, 'instance_id' => $instanceId,
                    'duration_ms' => $durationMs, 'error' => $failureCode];
            }
            $output = $result->output();

            $durationMs = (int) (microtime(true) * 1000 - $startMs);

            if ($output === null || $output === false || $output === '') {
                $failureCode = 'empty_output';
                $this->recordFailure($instanceId, $failureCode);

                return [
                    'success' => false,
                    'output' => null,
                    'instance_id' => $instanceId,
                    'duration_ms' => $durationMs,
                    'error' => 'empty_output',
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
        } catch (\Throwable $e) {
            if ($dispatched) {
                $failureCode = $terminal ? 'compute_accounting_unknown' : 'compute_terminal_unknown';
                $terminal = false;
                if ($custody !== null) {
                    try {
                        $this->executionCustody->unknown($custody, 'terminal_unproven');
                    } catch (\Throwable) {
                        // The unreleased exact row survives; expiry still cannot release it.
                    }
                }
                throw new InferenceAdmissionException('Compute execution state UNKNOWN; persisted hold retained and execution must not be replayed.', 0, $e);
            }
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            if ($e instanceof ProcessTimedOutException) {
                $result = $e->result;
            }
            $durationMs = (int) (microtime(true) * 1000 - $startMs);
            $failureCode = $e instanceof ProcessTimedOutException
                ? 'process_timeout'
                : 'execution_exception';
            $this->recordFailure($instanceId, $failureCode);
            Log::warning('ComputeRouterService: execution exception', [
                'instance' => $instanceId,
                'failure_code' => $failureCode,
                'exception_class' => $e::class,
                'duration_ms' => $durationMs,
            ]);

            return [
                'success' => false,
                'output' => null,
                'instance_id' => $instanceId,
                'duration_ms' => $durationMs,
                'error' => $failureCode,
            ];
        } finally {
            try {
                if (self::$workflowOutputObserver !== null) {
                    (self::$workflowOutputObserver)([
                        'instance_id' => $instanceId,
                        'success' => $failureCode === null && $result !== null && $result->successful(),
                        'exit_code' => $result?->exitCode(), 'error' => $failureCode,
                        'stdin_base64' => base64_encode($input),
                        'stdout_base64' => base64_encode($result?->output() ?? ''),
                        'stderr_base64' => base64_encode($result?->errorOutput() ?? ''),
                    ]);
                }
            } catch (\Throwable $observerError) {
                if ($dispatched) {
                    $terminal = false;
                    try {
                        $this->executionCustody->unknown($custody, 'observer_unproven');
                    } catch (\Throwable) {
                    }
                    throw new InferenceAdmissionException('Compute output accounting failed; persisted hold retained and execution must not be replayed.', 0, $observerError);
                }
                throw $observerError;
            } finally {
                if (! $dispatched || $terminal) {
                    $this->releaseOwnedGpuLock($gpuLock);
                    if ($custody !== null && $terminal) {
                        try {
                            $this->executionCustody->release($custody, $failureCode === null);
                        } catch (\Throwable $error) {
                            try {
                                $this->executionCustody->unknown($custody, 'release_unproven');
                            } catch (\Throwable) {
                            }
                            throw new InferenceAdmissionException('Compute terminal custody release is unproven; hold retained.', 0, $error);
                        }
                    }
                }
            }
        }
    }

    /** Child output is captured by the supervisor and cannot become an outer receipt. */
    private function supervisedCommand(object $instance, string $command, string $ownerToken, int $timeout, int $rawLimit): array
    {
        $source = file_get_contents(config('compute.execution_supervisor'));
        if (! is_string($source) || $source === '') {
            throw new InferenceAdmissionException('Compute supervisor source is unavailable; dispatch held.');
        }

        $arguments = [$instance->python_path ?? 'python3', '-c', $source, '--owner-token', $ownerToken,
            '--timeout', (string) $timeout, '--cleanup-grace', (string) $this->runtimePolicy->cleanupGraceSeconds(),
            '--output-max-bytes', (string) $rawLimit, '--command', $command];
        $scope = $this->runtimePolicy->executionScopeLimits($instance);
        if ($scope !== null) {
            array_push($arguments, '--scope-limits', json_encode($scope, JSON_THROW_ON_ERROR));
        }

        return $arguments;
    }

    /** Bare transport exit, partial output, or foreign receipts never prove physical completion. */
    private function terminalResult(ProcessResult $transport, string $ownerToken, string $command, array $limits): array
    {
        if ($transport->exitCode() !== 0) {
            throw new InferenceAdmissionException('Compute transport ended without proven terminal cleanup.');
        }
        if (strlen($transport->output()) + strlen($transport->errorOutput()) > $limits['transport_bytes']) {
            throw new InferenceAdmissionException('Compute terminal envelope exceeds its capture bound; hold retained.');
        }
        // The helper emits sorted keys on every supported Python version. Check
        // this fixed scalar/string wire shape before json_decode: bounded bytes
        // alone do not bound PHP allocation for adversarial arrays or objects.
        $base64Token = '"[A-Za-z0-9+/=\\x5c]*"';
        $wireFields = [
            '"cleanup_verified"\\s*:\\s*true',
            '"command_sha256"\\s*:\\s*"'.hash('sha256', $command).'"',
            '"dispatched"\\s*:\\s*(?:true|false)',
            '"exit_code"\\s*:\\s*(?:0|[1-9][0-9]{0,2})',
            '"owner_token"\\s*:\\s*'.preg_quote(json_encode($ownerToken, JSON_THROW_ON_ERROR), '~'),
            '"schema"\\s*:\\s*"plos\\.compute_terminal\\.v1"',
            '"stderr_base64"\\s*:\\s*'.$base64Token,
            '"stdout_base64"\\s*:\\s*'.$base64Token,
        ];
        if (preg_match('~\\A\\s*\\{\\s*'.implode('\\s*,\\s*', $wireFields).'\\s*\\}\\s*\\z~D', $transport->output()) !== 1) {
            throw new InferenceAdmissionException('Compute terminal wire shape is invalid; hold retained.');
        }
        $this->runtimePolicy->assertDecodeMemory($limits['raw_bytes']);
        $receipt = json_decode($transport->output(), true, 512, JSON_THROW_ON_ERROR);
        $fields = ['schema', 'owner_token', 'command_sha256', 'cleanup_verified', 'dispatched', 'exit_code', 'stdout_base64', 'stderr_base64'];
        if (! is_array($receipt) || count($receipt) !== count($fields) || array_diff(array_keys($receipt), $fields) !== []
            || ($receipt['schema'] ?? null) !== 'plos.compute_terminal.v1'
            || ($receipt['owner_token'] ?? null) !== $ownerToken
            || ($receipt['command_sha256'] ?? null) !== hash('sha256', $command)
            || ($receipt['cleanup_verified'] ?? null) !== true || ! is_bool($receipt['dispatched'] ?? null)
            || ! is_int($receipt['exit_code'] ?? null) || $receipt['exit_code'] < 0 || $receipt['exit_code'] > 255
            || (! $receipt['dispatched'] && ($receipt['exit_code'] !== 125
                || ($receipt['stdout_base64'] ?? null) !== '' || ($receipt['stderr_base64'] ?? null) !== ''))
            || ! is_string($receipt['stdout_base64'] ?? null) || ! is_string($receipt['stderr_base64'] ?? null)) {
            throw new InferenceAdmissionException('Compute terminal receipt is invalid; hold retained.');
        }
        $decodedBytes = 0;
        foreach (['stdout_base64', 'stderr_base64'] as $field) {
            $value = $receipt[$field];
            $decodedBytes += (int) floor(strlen($value) / 4) * 3 - (strlen($value) - strlen(rtrim($value, '=')));
        }
        if ($decodedBytes < 0 || $decodedBytes > $limits['raw_bytes']) {
            throw new InferenceAdmissionException('Compute terminal streams exceed their decoded capture bound; hold retained.');
        }
        $this->runtimePolicy->assertDecodeMemory($decodedBytes);
        $stdout = base64_decode($receipt['stdout_base64'], true);
        $stderr = base64_decode($receipt['stderr_base64'], true);
        if ($stdout === false || $stderr === false || strlen($stdout) + strlen($stderr) > $limits['raw_bytes'] || base64_encode($stdout) !== $receipt['stdout_base64']
            || base64_encode($stderr) !== $receipt['stderr_base64']) {
            throw new InferenceAdmissionException('Compute terminal streams are invalid; hold retained.');
        }

        return [new ComputeTerminalProcessResult($transport, $receipt, $stdout, $stderr), $receipt];
    }

    /**
     * Execute a command on a remote instance via SSH.
     */
    private function executeRemote(object $instance, string $command, string $input = '', ?int $timeout = null): ProcessResult
    {
        $capture = null;

        return $this->runBoundedTransport($this->buildSshArguments($instance, $command), $input,
            $timeout ?? $this->runtimePolicy->executionTimeoutSeconds(), $this->runtimePolicy->captureLimits(), $capture);
    }

    /** Trusted local relay bounds remote bursts before Symfony's pipe aggregation; no disk spool. */
    private function runBoundedTransport(array $arguments, string $input, int $timeout, array $limits, ?ProcessResult &$capture): ProcessResult
    {
        $source = file_get_contents(config('compute.execution_supervisor'));
        $relay = [config('compute.execution_transport_python'), '-c', $source,
            '--transport-argv', json_encode($arguments, JSON_THROW_ON_ERROR), '--timeout', (string) $this->runtimePolicy->relayTimeoutSeconds($timeout),
            '--cleanup-grace', (string) $this->runtimePolicy->cleanupGraceSeconds(), '--output-max-bytes', (string) $limits['transport_bytes']];
        $buffers = ['out' => '', 'err' => ''];
        $received = 0;
        $process = Process::timeout($this->runtimePolicy->transportTimeoutSeconds($timeout))->quietly();
        if ($input !== '') {
            $process->input($input);
        }
        try {
            $transport = $process->run($relay, function (string $type, string $bytes) use (&$buffers, &$received, $limits): bool {
                if (! array_key_exists($type, $buffers) || strlen($bytes) > $limits['transport_bytes'] - $received) {
                    throw new InferenceAdmissionException('Compute transport capture overflow; hold retained.');
                }
                $received += strlen($bytes);
                $buffers[$type] .= $bytes;

                return false;
            });
        } catch (\Throwable $error) {
            $capture = new ComputeTerminalProcessResult(null, ['exit_code' => null], $buffers['out'], $buffers['err']);
            throw $error;
        }
        // A quiet Symfony result has no readable output buffer: only our bounded
        // callback owns bytes, including the transport's diagnostic stderr.
        $capture = new ComputeTerminalProcessResult($transport, ['exit_code' => $transport->exitCode()], $buffers['out'], $buffers['err']);

        return $capture;
    }

    /**
     * Build the full command to execute a Python script on an instance.
     */
    private function buildScriptCommand(object $instance, string $scriptPath, ?int $timeout = null): string
    {
        $python = $instance->python_path ?? 'python3';
        $timeoutSec = $timeout ?? $this->runtimePolicy->executionTimeoutSeconds();

        return sprintf('timeout %d %s %s', $timeoutSec, escapeshellarg($python), escapeshellarg($scriptPath));
    }

    /** @return list<string> */
    private function buildSshArguments(object $instance, string $command): array
    {
        $sshUser = $instance->ssh_user ?? config('compute.ssh_user_default', 'plos');

        return [
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'PasswordAuthentication=no',
            '-o', 'KbdInteractiveAuthentication=no',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'ConnectTimeout='.$this->runtimePolicy->sshConnectTimeoutSeconds(),
            "{$sshUser}@{$instance->host}",
            $command,
        ];
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

        $healthDelta = $this->runtimePolicy->healthSuccessDelta();
        $maxScore = 100;
        $newHealth = min($maxScore, $instance->health_score + $healthDelta);
        $newTotal = $instance->total_executions + 1;
        $newRate = $newTotal > 0 ? round((($newTotal - $instance->total_failures) / $newTotal) * 100, 2) : 100;

        // Exponential moving average for execution time
        $alpha = $this->runtimePolicy->latencyEmaAlpha();
        $newAvg = $instance->avg_execution_ms
            ? round(($instance->avg_execution_ms * (1 - $alpha)) + ($durationMs * $alpha), 2)
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
        $reason = $this->contentFreeFailureCode($reason);

        $healthDelta = $this->runtimePolicy->healthFailureDelta();
        $minScore = 0;
        $unhealthyThreshold = $this->runtimePolicy->unhealthyThreshold();
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
        $threshold = $this->runtimePolicy->circuitFailureThreshold();
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
        $reason = $this->contentFreeFailureCode($reason);
        $cooldown = $this->runtimePolicy->circuitCooldownSeconds();
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

    private function contentFreeFailureCode(string $reason): string
    {
        $reason = strtolower(trim($reason));

        return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,79}$/', $reason) === 1
            ? $reason
            : 'compute_execution_failed';
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
        $slots = $this->slotState($instance);

        return $slots['available'] === 0;
    }

    /** @return array{total: int, active: int, available: int, utilization_pct: float} */
    private function slotState(object $instance): array
    {
        $total = max(1, (int) ($instance->max_concurrent ?? 1));
        $lockKey = "compute_gpu_lock:{$instance->instance_id}";
        $active = 0;

        if ($total === 1) {
            $active = ($this->slotOccupied($lockKey) || $this->persistedSlotOccupied((string) $instance->instance_id, 0)) ? 1 : 0;
        } else {
            for ($i = 0; $i < $total; $i++) {
                if ($this->slotOccupied("{$lockKey}:{$i}") || $this->persistedSlotOccupied((string) $instance->instance_id, $i)) {
                    $active++;
                }
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'available' => max(0, $total - $active),
            'utilization_pct' => round(($active / $total) * 100, 2),
        ];
    }

    /** Missing custody metadata cannot turn a physical slot into available capacity. */
    private function persistedSlotOccupied(string $instanceId, int $slot): bool
    {
        try {
            return $this->executionCustody->occupied($instanceId, $slot);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Acquire a GPU/compute lock for an instance.
     * On the primary local GPU role, also checks legacy whisper_gpu_lock and
     * ollama_busy_lock.
     */
    public function acquireGpuLock(object $instance): bool
    {
        $id = (string) $instance->instance_id;
        if (isset($this->publicGpuLocks[$id])) {
            return false;
        }
        $lock = $this->acquireOwnedGpuLock($instance);
        if ($lock === null) {
            return false;
        }
        $this->publicGpuLocks[$id] = $lock;

        return true;
    }

    private function acquireOwnedGpuLock(object $instance): ?Lock
    {
        $id = (string) $instance->instance_id;
        if (app()->isDownForMaintenance()) {
            return null;
        }
        $ttl = (int) config(($instance->gpu_vram_mb ?? 0) > 0 ? 'lock_ttls.compute_gpu' : 'lock_ttls.compute_cpu');
        if ($ttl < 1) {
            return null;
        }
        try {
            if (! empty($instance->shares_gpu_with_llm)
                && (Cache::has('whisper_gpu_lock') || Cache::has('ollama_busy_lock'))) {
                return null;
            }
            $total = max(1, (int) ($instance->max_concurrent ?? 1));
            for ($slot = 0; $slot < $total; $slot++) {
                $legacyKey = "compute_gpu_lock:{$id}".($total === 1 ? '' : ":{$slot}");
                if (Cache::has($legacyKey) || $this->executionCustody->occupied($id, $slot)) {
                    continue;
                }
                $lock = $this->atomicSlotLock($legacyKey, $ttl);
                if ($lock->get()) {
                    $this->ownedLockSlots[spl_object_id($lock)] = $slot;

                    return $lock;
                }
            }
        } catch (\Throwable $error) {
            Log::warning('Compute lock admission unavailable; dispatch held', ['exception_class' => get_class($error)]);
        }

        return null;
    }

    /** Read current lock presence without acquiring it; legacy values remain conservative holds. */
    private function slotOccupied(string $legacyKey): bool
    {
        try {
            if (Cache::has($legacyKey)) {
                return true;
            }
            $lock = $this->atomicSlotLock($legacyKey, 0);
            $store = Cache::getStore();
            if ($lock instanceof DatabaseLock) {
                $table = config('cache.stores.'.Cache::getDefaultDriver().'.lock_table');
                if (! $store instanceof DatabaseStore || ! is_string($table) || $table === '') {
                    return true;
                }
                $row = ($store->getLockConnection() ?? $store->getConnection())->table($table)
                    ->where('key', $store->getPrefix().$legacyKey.':owned')->first(['owner', 'expiration']);
                if ($row === null) {
                    return false;
                }
                if (! is_string($row->owner) || $row->owner === ''
                    || ! preg_match('/\A[0-9]+\z/', (string) $row->expiration)) {
                    return true;
                }

                return (int) $row->expiration > now()->getTimestamp();
            }
            if ($lock instanceof ArrayLock && $store instanceof ArrayStore) {
                $entry = $store->locks[$legacyKey.':owned'] ?? null;
                if ($entry === null) {
                    return false;
                }

                return ! ($entry['expiresAt'] ?? null) instanceof CarbonInterface
                    || $entry['expiresAt']->isFuture();
            }

            return ! $lock->isOwnedBy(null);
        } catch (\Throwable) {
            return true;
        }
    }

    private function atomicSlotLock(string $legacyKey, int $ttl): Lock
    {
        $lock = Cache::lock($legacyKey.':owned', $ttl);
        if ($lock instanceof DatabaseLock
            && (! is_string(config('cache.stores.'.Cache::getDefaultDriver().'.lock_table'))
                || config('cache.stores.'.Cache::getDefaultDriver().'.lock_table') === '')) {
            throw new InferenceAdmissionException('Compute database lock metadata is unavailable; dispatch held.');
        }
        if (! $lock instanceof RedisLock && ! $lock instanceof DatabaseLock
            && ! ($lock instanceof ArrayLock && app()->environment('testing'))) {
            throw new InferenceAdmissionException('Compute cache backend does not prove atomic owner release; dispatch held.');
        }

        return $lock;
    }

    public function releaseGpuLock(object $instance): void
    {
        $id = (string) $instance->instance_id;
        $lock = $this->publicGpuLocks[$id] ?? null;
        if ($lock === null) {
            return;
        }
        $this->releaseOwnedGpuLock($lock);
        unset($this->publicGpuLocks[$id]);
    }

    private function releaseOwnedGpuLock(Lock $lock): void
    {
        try {
            // Redis Lua and database owner predicates make check+release atomic.
            // Only this exact handle may release; never search for another slot.
            $lock->release();
            unset($this->ownedLockSlots[spl_object_id($lock)]);
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Compute lock release is unproven; hold retained and execution must not be replayed.', 0, $error);
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
            $check = $this->healthCheckInstance($instance);
            $updated = $this->getInstance($instance->instance_id);
            if (
                ($check['healthy'] ?? false)
                && (bool) ($updated->is_healthy ?? false)
                && $instance->circuit_state !== 'closed'
            ) {
                $this->setCircuitState($instance->instance_id, 'closed');
                $check['circuit_reset'] = true;
            }
            $results[$instance->instance_id] = $check;
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
            $nvidiaError = 'nvidia-smi failed or not available';
            try {
                $result = Process::timeout($this->runtimePolicy->localGpuProbeTimeoutSeconds())->run([
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

                } else {
                    $parts = array_map('trim', explode(',', trim($result->output())));

                    return [
                        'healthy' => true,
                        'telemetry_source' => 'nvidia_smi',
                        'memory_model' => 'dedicated_gpu',
                        'gpu_name' => $parts[0] ?? 'unknown',
                        'memory_total_mb' => (int) ($parts[1] ?? 0),
                        'memory_free_mb' => (int) ($parts[2] ?? 0),
                        'gpu_utilization' => (int) ($parts[3] ?? 0),
                    ];
                }
            } catch (\Exception $e) {
                $nvidiaError = 'nvidia-smi exception: '.$e->getMessage();
                Log::debug('ComputeRouterService: local nvidia-smi exception', [
                    'instance' => $instance->instance_id,
                    'error' => $e->getMessage(),
                ]);

            }

            try {
                $tegrastats = Process::timeout($this->runtimePolicy->localJetsonProbeTimeoutSeconds())->run([
                    'bash', '-lc', JetsonTegrastatsParserService::SAMPLE_COMMAND,
                ]);
                $jetsonHealth = $this->jetsonHealth($tegrastats->successful() ? $tegrastats->output() : null);
                if ($jetsonHealth !== null) {
                    return $jetsonHealth;
                }
            } catch (\Exception $e) {
                Log::debug('ComputeRouterService: local tegrastats exception', [
                    'instance' => $instance->instance_id,
                    'error' => $e->getMessage(),
                ]);
            }

            return ['healthy' => false, 'error' => $nvidiaError.'; tegrastats unavailable'];
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
        $host = $instance->host;

        if ($hasGpu) {
            $nvidia = $this->runRemoteHealthCommand(
                $instance,
                'nvidia-smi --query-gpu=name,memory.total,memory.free,utilization.gpu --format=csv,noheader,nounits'
            );
            if ($nvidia !== null) {
                $parts = array_map('trim', explode(',', trim($nvidia)));

                return [
                    'healthy' => true,
                    'telemetry_source' => 'nvidia_smi',
                    'memory_model' => 'dedicated_gpu',
                    'gpu_name' => $parts[0] ?? 'unknown',
                    'memory_total_mb' => (int) ($parts[1] ?? 0),
                    'memory_free_mb' => (int) ($parts[2] ?? 0),
                    'gpu_utilization' => (int) ($parts[3] ?? 0),
                ];
            }

            $jetsonHealth = $this->jetsonHealth(
                $this->runRemoteHealthCommand($instance, JetsonTegrastatsParserService::SAMPLE_COMMAND)
            );
            if ($jetsonHealth !== null) {
                return $jetsonHealth;
            }

            return ['healthy' => false, 'error' => "SSH to {$host} failed or GPU telemetry unavailable"];
        }

        $output = $this->runRemoteHealthCommand($instance, 'echo ok');

        return ['healthy' => trim((string) $output) === 'ok', 'type' => 'cpu'];
    }

    private function runRemoteHealthCommand(object $instance, string $remoteCommand): ?string
    {
        $host = $instance->host;

        try {
            $result = Process::timeout($this->runtimePolicy->remoteHealthTimeoutSeconds())
                ->run($this->buildSshArguments($instance, $remoteCommand));

            if ($result->successful() && trim($result->output()) !== '') {
                return $result->output();
            }

            Log::debug('ComputeRouterService: remote health command failed', [
                'host' => $host,
                'exitCode' => $result->exitCode(),
                'error' => mb_substr($result->errorOutput(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::debug('ComputeRouterService: remote health command exception', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function jetsonHealth(?string $output): ?array
    {
        $sample = app(JetsonTegrastatsParserService::class)->parse($output);
        if ($sample === null) {
            return null;
        }

        return [
            'healthy' => true,
            'telemetry_source' => 'tegrastats',
            'memory_model' => 'unified_system',
            'gpu_name' => 'NVIDIA Jetson integrated GPU',
            'memory_total_mb' => $sample['memory_total_mb'],
            'memory_free_mb' => $sample['memory_available_mb'],
            'largest_free_block_mb' => $sample['largest_free_block_mb'],
            'gpu_utilization' => $sample['gpu_utilization_percent'],
            'gpu_frequency_mhz' => $sample['gpu_frequency_mhz'],
            'emc_utilization_percent' => $sample['emc_utilization_percent'],
            'emc_frequency_mhz' => $sample['emc_frequency_mhz'],
            'temperature_c' => $sample['gpu_temperature_c'] ?? $sample['max_temperature_c'],
        ];
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
            ? $this->runtimePolicy->healthSuccessDelta()
            : $this->runtimePolicy->healthFailureDelta();

        $maxScore = 100;
        $minScore = 0;
        $unhealthyThreshold = $this->runtimePolicy->unhealthyThreshold();

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

    public function isHealthFresh(object $instance): bool
    {
        $checkedAt = isset($instance->last_health_check)
            ? strtotime((string) $instance->last_health_check)
            : false;
        if ($checkedAt === false) {
            return false;
        }

        return max(0, time() - $checkedAt) <= $this->runtimePolicy->healthFreshnessSeconds();
    }

    private function healthAgeSeconds(object $instance): ?int
    {
        $checkedAt = isset($instance->last_health_check)
            ? strtotime((string) $instance->last_health_check)
            : false;

        return $checkedAt === false ? null : max(0, time() - $checkedAt);
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
            $slots = $this->slotState($instance);
            $healthFresh = $this->isHealthFresh($instance);
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
                'health_fresh' => $healthFresh,
                'health_age_seconds' => $this->healthAgeSeconds($instance),
                'is_busy' => $slots['available'] === 0,
                'is_routable' => (bool) $instance->is_healthy
                    && $healthFresh
                    && $instance->circuit_state === 'closed'
                    && $slots['available'] > 0,
                'slots' => $slots,
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
            'routable' => count(array_filter($status, fn ($s) => $s['is_routable'])),
            'stale_health' => count(array_filter($status, fn ($s) => ! $s['health_fresh'])),
            'open_circuits' => count(array_filter($status, fn ($s) => $s['circuit_state'] !== 'closed')),
            'policy_source' => 'system_configs.'.ComputeRouterRuntimePolicyService::CONFIG_KEY,
            'health_freshness_seconds' => $this->runtimePolicy->healthFreshnessSeconds(),
            'routing_weights' => $this->runtimePolicy->routingWeights(),
            'remote_host_key_policy' => 'strict_known_hosts_required',
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
     * Calculate the table-owned routing score for an instance.
     */
    private function calculateScore(object $instance): float
    {
        $weights = $this->runtimePolicy->routingWeights();
        $priorityReference = $this->runtimePolicy->priorityReference();
        $speedReference = $this->runtimePolicy->speedReferenceMs();
        $slots = $this->slotState($instance);

        $priorityScore = max(0, min(100, (($priorityReference - $instance->priority) / $priorityReference) * 100));
        $healthScore = max(0, min(100, (float) $instance->health_score));
        $avgMs = $instance->avg_execution_ms ?? $this->runtimePolicy->unknownLatencyMs();
        $speedScore = max(0, min(100, 100 - (((float) $avgMs / $speedReference) * 100)));
        $loadScore = ($slots['available'] / $slots['total']) * 100;

        return ($priorityScore * $weights['priority'])
            + ($healthScore * $weights['health'])
            + ($speedScore * $weights['speed'])
            + ($loadScore * $weights['load']);
    }

    /**
     * Clear the instance cache.
     */
    public static function clearInstanceCache(bool $strict = false): void
    {
        foreach ([self::CACHE_KEY_ALL, self::CACHE_KEY_HEALTHY] as $key) {
            Cache::forget($key);
            if ($strict && Cache::has($key)) {
                throw new \RuntimeException('Provider inventory cache invalidation unavailable.');
            }
        }
    }
}

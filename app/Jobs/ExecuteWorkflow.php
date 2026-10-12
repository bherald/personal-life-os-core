<?php

namespace App\Jobs;

use App\Engine\WorkflowEngine;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\SystemConfigService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteWorkflow implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'workflow.execute_workflow_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 900,
        'tries' => 2,
        'unique_for_seconds' => 1800,
        'backoff_seconds' => [60, 120],
        'overlap_release_after_seconds' => 300,
        'overlap_expire_after_seconds' => 1800,
    ];

    /**
     * Job timeout in seconds.
     * Match Horizon's dedicated workflow supervisor timeout.
     */
    public $timeout = 900;

    /**
     * Number of retry attempts
     */
    public $tries = 2;

    /**
     * Keep one queued/executing copy of the same workflow admitted at a time.
     */
    public $uniqueFor = 1800;

    /**
     * Retry backoff in seconds (wait 60s, then 120s)
     */
    public $backoff = [60, 120];

    /**
     * Delete job if models missing
     */
    public $deleteWhenMissingModels = true;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    protected string $workflowName;

    protected ?int $workflowId;

    protected array $input;

    protected ?string $traceSessionId;

    /** Content-free SHA-256 key; never a raw upstream delivery identifier. */
    protected ?string $idempotencyKey;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $workflowName,
        ?int $workflowId = null,
        array $input = [],
        ?string $traceSessionId = null,
        ?string $idempotencyKey = null
    ) {
        if ($idempotencyKey !== null && preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Workflow idempotency key must be a SHA-256 digest.');
        }

        $this->workflowName = $workflowName;
        $this->workflowId = $workflowId;
        $this->input = $input;
        $this->traceSessionId = $traceSessionId;
        $this->idempotencyKey = $idempotencyKey;
        $this->applyRuntimePolicy();

        // Use the dedicated workflow queue to isolate orchestration work.
        $this->onQueue('workflow');
    }

    /**
     * Get middleware for the job.
     * Prevents duplicate workflow executions.
     */
    public function middleware(): array
    {
        return [
            // Prevent same workflow from running concurrently
            (new WithoutOverlapping($this->workflowName))
                ->releaseAfter($this->runtimePolicyInt('overlap_release_after_seconds'))
                ->expireAfter($this->runtimePolicyInt('overlap_expire_after_seconds')),
        ];
    }

    /**
     * Determine the unique ID for the job.
     */
    public function uniqueId(): string
    {
        if ($this->idempotencyKey !== null) {
            return $this->workflowName.':'.$this->idempotencyKey;
        }

        if (empty($this->input)) {
            return $this->workflowName;
        }

        return $this->workflowName.':'.hash('sha256', json_encode($this->recursiveKeySort($this->input)));
    }

    /**
     * Keep queued uniqueness stable for equivalent payloads with different key order.
     */
    private function recursiveKeySort(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->recursiveKeySort($value);
            }
        }

        return $array;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting queued workflow execution', [
            'workflow' => $this->workflowName,
            'id' => $this->workflowId,
            'attempt' => $this->attempts(),
            'input_keys' => array_keys($this->input),
            'trace_session_id' => $this->traceSessionId,
        ]);

        try {
            $engine = app(WorkflowEngine::class);
            $result = $engine->executeWorkflow($this->workflowName, $this->input, [
                'trace_session_id' => $this->traceSessionId,
                'idempotency_key' => $this->idempotencyKey,
            ]);
        } catch (Throwable $error) {
            if (! InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }
            $this->fail($error);

            return;
        }

        Log::info('Queued workflow completed successfully', [
            'workflow' => $this->workflowName,
            'result' => $result,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Queued workflow execution failed permanently', [
            'workflow' => $this->workflowName,
            'id' => $this->workflowId,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);

        // Could send notification here
        // NotificationController::send('pushover', [...])
    }

    /**
     * Get tags for Horizon monitoring.
     */
    public function tags(): array
    {
        return ['workflow', 'workflow:'.$this->workflowName];
    }

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('timeout_seconds');
        $this->tries = $this->runtimePolicyInt('tries');
        $this->uniqueFor = $this->runtimePolicyInt('unique_for_seconds');
        $this->backoff = $this->runtimePolicyIntList('backoff_seconds');
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return list<int>
     */
    private function runtimePolicyIntList(string $key): array
    {
        $fallback = self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (! is_array($value)) {
            return $fallback;
        }

        $ints = array_values(array_filter(
            array_map(fn ($item) => is_numeric($item) ? (int) $item : 0, $value),
            fn (int $item) => $item > 0
        ));

        return $ints !== [] ? $ints : $fallback;
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

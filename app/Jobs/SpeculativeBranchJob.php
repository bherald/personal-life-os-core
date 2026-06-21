<?php

namespace App\Jobs;

use App\Services\AgentLoopService;
use App\Services\SpeculativeExecutionService;
use App\Services\SystemConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Speculative Branch Job
 *
 * Executes one branch of a speculative execution run.
 * Two of these jobs are dispatched per speculative run (branch_a and branch_b),
 * each running the same task in a different workflow mode.
 *
 * When both branches complete, the last-finishing branch triggers arbitration inline.
 *
 * Redis coordination keys (TTL follows agents.speculative_execution_runtime_policy):
 * - speculative:{specRunId}:status — overall run status
 * - speculative:{specRunId}:{branch}:status — pending|running|completed|failed
 * - speculative:{specRunId}:{branch}:result — JSON blob (full AgentLoopService result)
 */
class SpeculativeBranchJob implements ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'agents.speculative_execution_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'branch_timeout_seconds' => 1200,
        'branch_tries' => 1,
        'coordination_ttl_seconds' => 1800,
        'arbitration_lock_seconds' => 300,
        'request_flag_ttl_seconds' => 3600,
        'auto_disable_ttl_seconds' => 86400,
    ];

    public int $timeout = 1200;  // 20 minutes max per branch
    public int $tries = 1;       // No retries — speculative is best-effort

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(
        private string $specRunId,
        private string $branch,      // 'branch_a' or 'branch_b'
        private string $agentId,
        private string $task,
        private string $mode,
        private array $options = []
    ) {
        $this->applyRuntimePolicy();

        $this->onQueue('speculative');
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->specRunId . ':' . $this->branch),
        ];
    }

    public function handle(): void
    {
        $startTime = microtime(true);
        $redisPrefix = "speculative:{$this->specRunId}";

        // Update DB: branch running
        $this->updateBranchStatus('running');
        Cache::put("{$redisPrefix}:{$this->branch}:status", 'running', $this->coordinationTtlSeconds());
        Cache::put("{$redisPrefix}:status", 'running', $this->coordinationTtlSeconds());

        Log::info("SpeculativeBranch: Starting", [
            'spec_run_id' => $this->specRunId,
            'branch' => $this->branch,
            'agent_id' => $this->agentId,
            'mode' => $this->mode,
        ]);

        try {
            $agentLoop = app(AgentLoopService::class);

            $branchOptions = array_merge($this->options, [
                'benchmark_mode' => $this->mode,
                '_speculative_branch' => true,
                '_spec_run_id' => $this->specRunId,
                '_spec_branch' => $this->branch,
            ]);

            $result = $agentLoop->execute($this->agentId, $this->task, $branchOptions);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $tokensUsed = $result['tokens_used'] ?? 0;

            // Store result in Redis
            Cache::put("{$redisPrefix}:{$this->branch}:result", json_encode($result), $this->coordinationTtlSeconds());
            Cache::put("{$redisPrefix}:{$this->branch}:status", 'completed', $this->coordinationTtlSeconds());

            // Update DB
            $this->updateBranchStatus('completed');
            $this->updateBranchMetrics($tokensUsed, $durationMs);

            Log::info("SpeculativeBranch: Completed", [
                'spec_run_id' => $this->specRunId,
                'branch' => $this->branch,
                'mode' => $this->mode,
                'tokens' => $tokensUsed,
                'duration_ms' => $durationMs,
                'success' => $result['success'] ?? false,
            ]);

        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            Cache::put("{$redisPrefix}:{$this->branch}:status", 'failed', $this->coordinationTtlSeconds());
            Cache::put("{$redisPrefix}:{$this->branch}:result", json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]), $this->coordinationTtlSeconds());

            $this->updateBranchStatus('failed');
            $this->updateBranchMetrics(0, $durationMs);

            Log::error("SpeculativeBranch: Failed", [
                'spec_run_id' => $this->specRunId,
                'branch' => $this->branch,
                'error' => $e->getMessage(),
            ]);
        }

        // Check if both branches are done — if so, trigger arbitration
        $this->checkAndArbitrate();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error("SpeculativeBranch: Job failed permanently", [
            'spec_run_id' => $this->specRunId,
            'branch' => $this->branch,
            'agent_id' => $this->agentId,
            'error' => $exception?->getMessage(),
        ]);

        $redisPrefix = "speculative:{$this->specRunId}";
        Cache::put("{$redisPrefix}:{$this->branch}:status", 'failed', $this->coordinationTtlSeconds());
        $this->updateBranchStatus('failed');
        $this->checkAndArbitrate();
    }

    public function tags(): array
    {
        return [
            'speculative',
            "spec:{$this->specRunId}",
            "agent:{$this->agentId}",
            "branch:{$this->branch}",
        ];
    }

    private function updateBranchStatus(string $status): void
    {
        $column = $this->branch === 'branch_a' ? 'branch_a_status' : 'branch_b_status';
        DB::update("UPDATE speculative_executions SET {$column} = ? WHERE spec_run_id = ?", [
            $status,
            $this->specRunId,
        ]);
    }

    private function updateBranchMetrics(int $tokens, int $durationMs): void
    {
        $prefix = $this->branch === 'branch_a' ? 'branch_a' : 'branch_b';
        DB::update("
            UPDATE speculative_executions
            SET {$prefix}_tokens = ?, {$prefix}_duration_ms = ?
            WHERE spec_run_id = ?
        ", [$tokens, $durationMs, $this->specRunId]);
    }

    private function checkAndArbitrate(): void
    {
        $redisPrefix = "speculative:{$this->specRunId}";
        $statusA = Cache::get("{$redisPrefix}:branch_a:status");
        $statusB = Cache::get("{$redisPrefix}:branch_b:status");

        if (!in_array($statusA, ['completed', 'failed']) || !in_array($statusB, ['completed', 'failed'])) {
            return; // Other branch still running
        }

        // Use a lock to prevent both branches from triggering arbitration simultaneously
        $arbitrationLock = Cache::lock("speculative_arbitration:{$this->specRunId}", $this->arbitrationLockSeconds());
        if (!$arbitrationLock->get()) {
            return; // Other branch already arbitrating
        }

        try {
            $speculativeService = app(SpeculativeExecutionService::class);
            $speculativeService->arbitrate($this->specRunId);
        } catch (Throwable $e) {
            Log::error("SpeculativeBranch: Arbitration failed", [
                'spec_run_id' => $this->specRunId,
                'error' => $e->getMessage(),
            ]);

            DB::update("UPDATE speculative_executions SET status = 'failed' WHERE spec_run_id = ?", [
                $this->specRunId,
            ]);
        } finally {
            $arbitrationLock->release();
        }
    }

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('branch_timeout_seconds');
        $this->tries = $this->runtimePolicyInt('branch_tries');
    }

    private function coordinationTtlSeconds(): int
    {
        return $this->runtimePolicyInt('coordination_ttl_seconds');
    }

    private function arbitrationLockSeconds(): int
    {
        return $this->runtimePolicyInt('arbitration_lock_seconds');
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

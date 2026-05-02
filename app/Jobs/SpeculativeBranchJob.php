<?php

namespace App\Jobs;

use App\Services\AgentLoopService;
use App\Services\SpeculativeExecutionService;
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
 * Redis coordination keys (all with 30min TTL):
 * - speculative:{specRunId}:status — overall run status
 * - speculative:{specRunId}:{branch}:status — pending|running|completed|failed
 * - speculative:{specRunId}:{branch}:result — JSON blob (full AgentLoopService result)
 */
class SpeculativeBranchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;  // 20 minutes max per branch
    public int $tries = 1;       // No retries — speculative is best-effort

    private const REDIS_TTL = 1800; // 30 minutes

    public function __construct(
        private string $specRunId,
        private string $branch,      // 'branch_a' or 'branch_b'
        private string $agentId,
        private string $task,
        private string $mode,
        private array $options = []
    ) {
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
        Cache::put("{$redisPrefix}:{$this->branch}:status", 'running', self::REDIS_TTL);
        Cache::put("{$redisPrefix}:status", 'running', self::REDIS_TTL);

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
            Cache::put("{$redisPrefix}:{$this->branch}:result", json_encode($result), self::REDIS_TTL);
            Cache::put("{$redisPrefix}:{$this->branch}:status", 'completed', self::REDIS_TTL);

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

            Cache::put("{$redisPrefix}:{$this->branch}:status", 'failed', self::REDIS_TTL);
            Cache::put("{$redisPrefix}:{$this->branch}:result", json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]), self::REDIS_TTL);

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
        Cache::put("{$redisPrefix}:{$this->branch}:status", 'failed', self::REDIS_TTL);
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
        $arbitrationLock = Cache::lock("speculative_arbitration:{$this->specRunId}", 300);
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
}

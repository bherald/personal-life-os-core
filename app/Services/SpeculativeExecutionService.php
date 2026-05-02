<?php

namespace App\Services;

use App\Contracts\ReviewApprovalHandler;
use App\Jobs\SpeculativeBranchJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Speculative Execution Service — S19 (Tier 4 Dynamic Intelligence)
 *
 * For critical/high-variance tasks, runs the SAME task through 2 workflow modes
 * simultaneously. An LLM-as-judge compares both outputs and picks the winner.
 * The losing result is discarded but its benchmark data is retained for S20 learning.
 *
 * Execution model:
 * - Horizon `speculative` queue handles branch jobs (timeout, memory, dashboard)
 * - Redis coordination layer tracks cross-branch state (which completed, who won)
 * - Single GTX 1060 means GPU calls serialize via ollama_busy_lock; non-GPU work overlaps
 *
 * Trigger sources:
 * - Agent request (via request_speculative tool → Redis flag)
 * - Variance detected (benchmark stddev > threshold)
 * - Manual (CLI or API)
 * - Benchmark (from benchmark service)
 *
 * Cost control:
 * - Auto-disables per-agent for 24hr if avg quality uplift < 10% over last 10 runs
 * - GPU contention guard prevents launch when GPU is busy
 * - Queue depth guard caps concurrent speculative runs
 */
class SpeculativeExecutionService implements ReviewApprovalHandler
{
    private const REDIS_TTL = 1800;             // 30 minutes for coordination keys
    private const DISABLE_TTL = 86400;          // 24 hours auto-disable cooldown
    // N82: runtime thresholds read from config/agents.php

    private ?AIService $aiService = null;

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    // ─── Main Entry Point ───────────────────────────────────────────────

    /**
     * Execute a speculative run: dispatch two branches with different workflow modes.
     *
     * Returns immediately with spec_run_id. Use getResult() to poll for completion.
     */
    public function execute(string $agentId, string $task, array $options = []): array
    {
        $startTime = microtime(true);
        $specRunId = 'spec_' . Str::random(12);
        $triggerType = $options['trigger_type'] ?? 'manual';

        // Select 2 modes to compare
        [$modeA, $modeB] = $this->selectModes($agentId, $task);

        // Insert tracking row
        DB::insert("
            INSERT INTO speculative_executions
                (spec_run_id, agent_id, task_description, task_key, branch_a_mode, branch_b_mode,
                 status, trigger_type, trigger_context, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
        ", [
            $specRunId,
            $agentId,
            $task,
            $options['task_key'] ?? $this->deriveTaskKey($task),
            $modeA,
            $modeB,
            $triggerType,
            json_encode($options['trigger_context'] ?? []),
        ]);

        // Set Redis coordination state
        $prefix = "speculative:{$specRunId}";
        Cache::put("{$prefix}:status", 'pending', self::REDIS_TTL);
        Cache::put("{$prefix}:branch_a:status", 'pending', self::REDIS_TTL);
        Cache::put("{$prefix}:branch_b:status", 'pending', self::REDIS_TTL);

        // Dispatch branch jobs to Horizon speculative queue
        $branchOptions = array_diff_key($options, array_flip([
            'trigger_type', 'trigger_context', 'speculative', 'speculative_trigger',
        ]));

        $jobA = new SpeculativeBranchJob($specRunId, 'branch_a', $agentId, $task, $modeA, $branchOptions);
        $jobB = new SpeculativeBranchJob($specRunId, 'branch_b', $agentId, $task, $modeB, $branchOptions);

        dispatch($jobA);
        dispatch($jobB);

        // Update status to running
        DB::update("UPDATE speculative_executions SET status = 'running' WHERE spec_run_id = ?", [$specRunId]);
        Cache::put("{$prefix}:status", 'running', self::REDIS_TTL);

        Log::info("SpeculativeExecution: Dispatched", [
            'spec_run_id' => $specRunId,
            'agent_id' => $agentId,
            'branch_a' => $modeA,
            'branch_b' => $modeB,
            'trigger' => $triggerType,
        ]);

        return [
            'success' => true,
            'speculative' => true,
            'spec_run_id' => $specRunId,
            'status' => 'running',
            'branch_a_mode' => $modeA,
            'branch_b_mode' => $modeB,
            'agent_id' => $agentId,
            'duration_ms' => round((microtime(true) - $startTime) * 1000),
            'response' => "Speculative execution dispatched ({$modeA} vs {$modeB})",
        ];
    }

    // ─── Mode Selection ─────────────────────────────────────────────────

    /**
     * Select 2 workflow modes to compare.
     *
     * Priority:
     * 1. Benchmark data: pick 2 highest-scoring modes (or highest vs fastest)
     * 2. Procedural memory: modes with different procedure coverage
     * 3. Default: agentic vs deterministic (max diversity)
     *
     * @return array [modeA, modeB]
     */
    public function selectModes(string $agentId, string $task): array
    {
        // 1. Check benchmark data for this agent
        $benchStats = DB::select("
            SELECT workflow_mode,
                   AVG(accuracy_score) as avg_accuracy,
                   AVG(completeness_score) as avg_completeness,
                   AVG(relevance_score) as avg_relevance,
                   AVG(duration_ms) as avg_duration,
                   COUNT(*) as runs
            FROM agent_benchmarks
            WHERE agent_id = ? AND accuracy_score IS NOT NULL
            GROUP BY workflow_mode
            HAVING runs >= 2
            ORDER BY (AVG(accuracy_score) + AVG(completeness_score) + AVG(relevance_score)) DESC
        ", [$agentId]);

        if (count($benchStats) >= 2) {
            // Pick top scorer vs runner-up for head-to-head
            return [$benchStats[0]->workflow_mode, $benchStats[1]->workflow_mode];
        }

        // 2. Check procedural memory for mode-specific procedures
        $allModes = ['agentic', 'hybrid', 'deterministic'];
        $modesWithProcedures = [];
        foreach ($allModes as $mode) {
            $found = DB::selectOne("
                SELECT 1 FROM agent_procedures
                WHERE agent_id = ?
                  AND trigger_pattern LIKE ?
                LIMIT 1
            ", [$agentId, "%{$mode}%"]);
            if ($found) {
                $modesWithProcedures[] = $mode;
            }
        }

        if (count($modesWithProcedures) >= 2) {
            return [array_shift($modesWithProcedures), array_shift($modesWithProcedures)];
        }

        // 3. Default: max diversity — agentic vs deterministic
        return ['agentic', 'deterministic'];
    }

    // ─── Arbitration (LLM-as-Judge) ─────────────────────────────────────

    /**
     * Compare both branch results and pick a winner.
     * Called by SpeculativeBranchJob when both branches complete.
     */
    public function arbitrate(string $specRunId): array
    {
        $run = $this->getRun($specRunId);
        if (!$run) {
            throw new \RuntimeException("Speculative run not found: {$specRunId}");
        }

        // Mark as arbitrating
        DB::update("UPDATE speculative_executions SET status = 'arbitrating' WHERE spec_run_id = ?", [$specRunId]);

        $prefix = "speculative:{$specRunId}";

        // Load results from Redis
        $resultA = json_decode(Cache::get("{$prefix}:branch_a:result") ?? '{}', true);
        $resultB = json_decode(Cache::get("{$prefix}:branch_b:result") ?? '{}', true);

        $successA = !empty($resultA['success']);
        $successB = !empty($resultB['success']);

        // If one branch failed, the other wins automatically
        if ($successA && !$successB) {
            return $this->recordWinner($specRunId, $run, 'branch_a', $resultA, $resultB,
                'Branch B failed, Branch A wins by default', 0);
        }
        if (!$successA && $successB) {
            return $this->recordWinner($specRunId, $run, 'branch_b', $resultA, $resultB,
                'Branch A failed, Branch B wins by default', 0);
        }
        if (!$successA && !$successB) {
            return $this->recordFailure($specRunId, 'Both branches failed');
        }

        // Both succeeded — run LLM comparison
        $responseA = $this->extractResponse($resultA);
        $responseB = $this->extractResponse($resultB);

        $arbitrationPrompt = $this->buildArbitrationPrompt(
            $run->task_description,
            $run->branch_a_mode, $responseA,
            $run->branch_b_mode, $responseB
        );

        try {
            $aiResult = $this->getAIService()->process($arbitrationPrompt, [
                'temperature' => 0.1,
                'max_tokens' => 500,
                'use_cache' => false,
            ]);

            $content = $aiResult['content'] ?? $aiResult['response'] ?? '';
            $tokens = $aiResult['tokens_used'] ?? 0;

            // Parse judge response
            $judgment = $this->parseJudgment($content);

            if (!$judgment) {
                Log::warning("SpeculativeExecution: Could not parse arbitration response", [
                    'spec_run_id' => $specRunId,
                    'response' => substr($content, 0, 500),
                ]);
                // Fall back to simple: pick branch with more tokens (proxy for thoroughness)
                $judgment = [
                    'winner' => ($run->branch_a_tokens >= $run->branch_b_tokens) ? 'A' : 'B',
                    'reasoning' => 'Arbitration parse failed; fallback to token count heuristic',
                    'output_a' => ['accuracy' => 3, 'completeness' => 3, 'relevance' => 3],
                    'output_b' => ['accuracy' => 3, 'completeness' => 3, 'relevance' => 3],
                ];
            }

            // Map winner
            $winner = match (strtoupper($judgment['winner'])) {
                'A' => 'branch_a',
                'B' => 'branch_b',
                default => 'tie',
            };

            // Calculate quality uplift
            $scoresA = $judgment['output_a'];
            $scoresB = $judgment['output_b'];
            $totalA = ($scoresA['accuracy'] ?? 0) + ($scoresA['completeness'] ?? 0) + ($scoresA['relevance'] ?? 0);
            $totalB = ($scoresB['accuracy'] ?? 0) + ($scoresB['completeness'] ?? 0) + ($scoresB['relevance'] ?? 0);
            $loserTotal = min($totalA, $totalB);
            $winnerTotal = max($totalA, $totalB);
            $uplift = $loserTotal > 0 ? round(($winnerTotal - $loserTotal) / $loserTotal * 100, 2) : 0;

            // Update arbitration tokens
            DB::update("
                UPDATE speculative_executions SET arbitration_tokens = ? WHERE spec_run_id = ?
            ", [$tokens, $specRunId]);

            // Store benchmark entries for both branches
            $this->storeBenchmarkEntries($specRunId, $run, $resultA, $resultB, $scoresA, $scoresB);

            $result = $this->recordWinner($specRunId, $run, $winner, $resultA, $resultB,
                $judgment['reasoning'] ?? '', $uplift);

            // Post-arbitration: check if speculative is worth continuing for this agent
            $this->evaluateCostBenefit($run->agent_id);

            return $result;

        } catch (\Throwable $e) {
            Log::error("SpeculativeExecution: Arbitration LLM call failed", [
                'spec_run_id' => $specRunId,
                'error' => $e->getMessage(),
            ]);

            // Graceful degradation: pick branch A as winner
            return $this->recordWinner($specRunId, $run, 'branch_a', $resultA, $resultB,
                'Arbitration failed; defaulting to branch A. Error: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Build the LLM-as-judge prompt.
     */
    private function buildArbitrationPrompt(
        string $taskDescription,
        string $modeA, string $responseA,
        string $modeB, string $responseB
    ): string {
        // Truncate responses to avoid context overflow
        $maxLen = 3000;
        $responseA = mb_substr($responseA, 0, $maxLen);
        $responseB = mb_substr($responseB, 0, $maxLen);

        return <<<PROMPT
You are an expert evaluator comparing two AI agent outputs for the same task.

## Task
{$taskDescription}

## Output A ({$modeA} mode)
{$responseA}

## Output B ({$modeB} mode)
{$responseB}

Rate each output on three criteria (1-5 scale):
1. **Accuracy** - Are the facts correct? Are tool results interpreted properly?
2. **Completeness** - Does it address all aspects of the task?
3. **Relevance** - Does it stay focused on the task without unnecessary content?

Respond in JSON ONLY:
{"output_a": {"accuracy": N, "completeness": N, "relevance": N}, "output_b": {"accuracy": N, "completeness": N, "relevance": N}, "winner": "A" | "B" | "tie", "reasoning": "Brief explanation"}
PROMPT;
    }

    /**
     * Parse the judge's JSON response.
     */
    private function parseJudgment(string $content): ?array
    {
        // Try to extract JSON from response
        if (preg_match('/\{[\s\S]*"winner"[\s\S]*\}/U', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['winner'], $decoded['output_a'], $decoded['output_b'])) {
                return $decoded;
            }
        }

        // More aggressive parse — find the largest JSON block
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['winner'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract a displayable response text from an agent result.
     */
    private function extractResponse(array $result): string
    {
        // AgentLoopService returns various shapes
        if (!empty($result['response'])) {
            return $result['response'];
        }
        if (!empty($result['final_response'])) {
            return $result['final_response'];
        }
        if (!empty($result['summary'])) {
            return $result['summary'];
        }
        if (!empty($result['episodes'])) {
            // Build from episodes
            $parts = [];
            foreach ($result['episodes'] as $ep) {
                if (is_string($ep)) {
                    $parts[] = $ep;
                } elseif (is_array($ep) && !empty($ep['content'])) {
                    $parts[] = $ep['content'];
                }
            }
            return implode("\n", $parts);
        }
        return json_encode($result);
    }

    // ─── Result Recording ───────────────────────────────────────────────

    /**
     * Record the winning branch and finalize the speculative run.
     */
    private function recordWinner(
        string $specRunId,
        object $run,
        string $winner,
        array $resultA,
        array $resultB,
        string $reasoning,
        float $uplift
    ): array {
        $winningMode = match ($winner) {
            'branch_a' => $run->branch_a_mode,
            'branch_b' => $run->branch_b_mode,
            default => null,
        };

        $totalCost = ($run->branch_a_tokens ?? 0) + ($run->branch_b_tokens ?? 0) + ($run->arbitration_tokens ?? 0);

        DB::update("
            UPDATE speculative_executions
            SET status = 'completed',
                winner = ?,
                winning_mode = ?,
                arbitration_reasoning = ?,
                quality_uplift_pct = ?,
                total_cost_tokens = ?,
                completed_at = NOW()
            WHERE spec_run_id = ?
        ", [$winner, $winningMode, $reasoning, $uplift, $totalCost, $specRunId]);

        // Clean up Redis
        $prefix = "speculative:{$specRunId}";
        Cache::put("{$prefix}:status", 'completed', self::REDIS_TTL);

        $winningResult = $winner === 'branch_a' ? $resultA : $resultB;

        Log::info("SpeculativeExecution: Completed", [
            'spec_run_id' => $specRunId,
            'winner' => $winner,
            'winning_mode' => $winningMode,
            'uplift' => $uplift,
            'total_tokens' => $totalCost,
        ]);

        return [
            'success' => true,
            'spec_run_id' => $specRunId,
            'winner' => $winner,
            'winning_mode' => $winningMode,
            'reasoning' => $reasoning,
            'quality_uplift_pct' => $uplift,
            'total_cost_tokens' => $totalCost,
            'result' => $winningResult,
        ];
    }

    /**
     * Record a failed speculative run.
     */
    private function recordFailure(string $specRunId, string $reason): array
    {
        DB::update("
            UPDATE speculative_executions
            SET status = 'failed', arbitration_reasoning = ?, completed_at = NOW()
            WHERE spec_run_id = ?
        ", [$reason, $specRunId]);

        Cache::put("speculative:{$specRunId}:status", 'failed', self::REDIS_TTL);

        Log::warning("SpeculativeExecution: Failed", [
            'spec_run_id' => $specRunId,
            'reason' => $reason,
        ]);

        return [
            'success' => false,
            'spec_run_id' => $specRunId,
            'error' => $reason,
        ];
    }

    /**
     * Store benchmark entries for both branches (tagged with is_speculative=1).
     */
    private function storeBenchmarkEntries(
        string $specRunId,
        object $run,
        array $resultA,
        array $resultB,
        array $scoresA,
        array $scoresB
    ): void {
        $benchmarkRunId = 'spec_bench_' . Str::random(8);

        foreach (['branch_a' => [$resultA, $scoresA, $run->branch_a_mode],
                   'branch_b' => [$resultB, $scoresB, $run->branch_b_mode]] as $branch => [$result, $scores, $mode]) {
            try {
                $responseSummary = mb_substr($this->extractResponse($result), 0, 1000);
                $tokensField = $branch . '_tokens';
                $durationField = $branch . '_duration_ms';

                DB::insert("
                    INSERT INTO agent_benchmarks
                        (run_id, agent_id, task_key, task_description, workflow_mode,
                         tokens_used, duration_ms, iterations, tool_calls_count,
                         accuracy_score, completeness_score, relevance_score,
                         response_summary, success, is_speculative, spec_run_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
                ", [
                    $benchmarkRunId,
                    $run->agent_id,
                    $run->task_key ?? $this->deriveTaskKey($run->task_description),
                    $run->task_description,
                    $mode,
                    $run->$tokensField ?? 0,
                    $run->$durationField ?? 0,
                    $result['iterations'] ?? 0,
                    $result['tool_calls_count'] ?? count($result['tool_calls'] ?? []),
                    max(1, min(5, (int) ($scores['accuracy'] ?? 3))),
                    max(1, min(5, (int) ($scores['completeness'] ?? 3))),
                    max(1, min(5, (int) ($scores['relevance'] ?? 3))),
                    $responseSummary,
                    ($result['success'] ?? false) ? 1 : 0,
                    $specRunId,
                ]);

                // Cross-reference benchmark ID back to speculative_executions
                $lastId = DB::selectOne("SELECT LAST_INSERT_ID() as id")?->id;
                if ($lastId) {
                    $benchCol = $branch . '_benchmark_id';
                    DB::update("UPDATE speculative_executions SET {$benchCol} = ? WHERE spec_run_id = ?", [
                        $lastId, $specRunId,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("SpeculativeExecution: Failed to store benchmark for {$branch}", [
                    'spec_run_id' => $specRunId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ─── Adaptive Triggering (Phase 4) ──────────────────────────────────

    /**
     * Determine if speculative execution is justified for this agent/task.
     *
     * Checks: auto-disable flag, GPU contention, queue depth, benchmark variance,
     * procedural memory failure patterns.
     */
    public function shouldSpeculate(string $agentId, string $task): bool
    {
        // 1. Check auto-disable flag
        if (Cache::has("speculative_disabled:{$agentId}")) {
            return false;
        }

        // 2. Check GPU contention
        if (!$this->isGpuAvailable()) {
            return false;
        }

        // 3. Check speculative queue depth
        try {
            if (Queue::size('speculative') >= config('agents.max_speculative_queue', 2)) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::debug('SpeculativeExecutionService: queue depth check failed', ['error' => $e->getMessage()]);
        }

        // 4. Check benchmark variance for this agent
        $variance = $this->getBenchmarkVariance($agentId);
        if ($variance && $variance->acc_stddev > config('agents.speculative_variance_threshold', 1.0)) {
            return true;
        }

        // 5. Check procedural memory for failure patterns on similar tasks
        if ($this->checkFailurePatterns($agentId, $task)) {
            return true;
        }

        return false;
    }

    /**
     * Get benchmark score variance for an agent.
     */
    private function getBenchmarkVariance(string $agentId): ?object
    {
        return DB::selectOne("
            SELECT
                STDDEV(accuracy_score) as acc_stddev,
                STDDEV(completeness_score) as comp_stddev,
                STDDEV(relevance_score) as rel_stddev,
                COUNT(*) as total
            FROM agent_benchmarks
            WHERE agent_id = ? AND accuracy_score IS NOT NULL
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", [$agentId]);
    }

    /**
     * Check procedural memory for failure patterns matching this task.
     */
    private function checkFailurePatterns(string $agentId, string $task): bool
    {
        $taskWords = array_unique(array_filter(
            explode(' ', strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $task)))
        ));

        if (empty($taskWords)) {
            return false;
        }

        // Look for failed procedures for this agent
        $failures = DB::select("
            SELECT name, trigger_pattern
            FROM agent_procedures
            WHERE agent_id = ? AND procedure_type = 'failure'
              AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
            LIMIT 20
        ", [$agentId]);

        foreach ($failures as $failure) {
            $failureWords = array_unique(array_filter(
                explode(',', strtolower($failure->trigger_pattern ?? ''))
            ));
            if (empty($failureWords)) {
                continue;
            }

            $overlap = count(array_intersect($taskWords, $failureWords));
            $union = count(array_unique(array_merge($taskWords, $failureWords)));
            $jaccard = $union > 0 ? $overlap / $union : 0;

            if ($jaccard > 0.5) {
                return true; // Similar to a previously failed task
            }
        }

        return false;
    }

    /**
     * Check if GPU is available for speculative runs.
     */
    private function isGpuAvailable(): bool
    {
        if (Cache::has('ollama_busy_lock')) return false;
        if (Cache::has('whisper_gpu_lock')) return false;
        if (Cache::has('embedding_training_gpu_lock')) return false;
        return true;
    }

    // ─── Cost Tracking + Auto-Disable ───────────────────────────────────

    /**
     * Evaluate cost-benefit of speculative execution for an agent.
     * Auto-disables if average uplift over last 10 runs is below threshold.
     */
    private function evaluateCostBenefit(string $agentId): void
    {
        $stats = DB::selectOne("
            SELECT AVG(quality_uplift_pct) as avg_uplift, COUNT(*) as total_runs
            FROM (
                SELECT quality_uplift_pct
                FROM speculative_executions
                WHERE agent_id = ? AND status = 'completed'
                ORDER BY created_at DESC
                LIMIT 10
            ) recent
        ", [$agentId]);

        if (!$stats || $stats->total_runs < 5) {
            return; // Not enough data
        }

        if ($stats->avg_uplift < config('agents.speculative_min_uplift', 10.0)) {
            Cache::put("speculative_disabled:{$agentId}", true, self::DISABLE_TTL);

            Log::warning("SpeculativeExecution: Auto-disabled for agent", [
                'agent_id' => $agentId,
                'avg_uplift' => round($stats->avg_uplift, 2),
                'threshold' => config('agents.speculative_min_uplift', 10.0),
                'runs_evaluated' => $stats->total_runs,
                'cooldown_hours' => self::DISABLE_TTL / 3600,
            ]);
        }
    }

    // ─── Query / Status ─────────────────────────────────────────────────

    /**
     * Get a single speculative run by ID.
     */
    public function getRun(string $specRunId): ?object
    {
        return DB::selectOne("SELECT * FROM speculative_executions WHERE spec_run_id = ?", [$specRunId]);
    }

    /**
     * Poll for completion. Returns null if still running, or full result if done.
     */
    public function getResult(string $specRunId): ?array
    {
        $run = $this->getRun($specRunId);
        if (!$run) return null;

        if (in_array($run->status, ['pending', 'running', 'arbitrating'])) {
            return null; // Still in progress
        }

        return [
            'spec_run_id' => $run->spec_run_id,
            'status' => $run->status,
            'agent_id' => $run->agent_id,
            'winner' => $run->winner,
            'winning_mode' => $run->winning_mode,
            'reasoning' => $run->arbitration_reasoning,
            'quality_uplift_pct' => (float) $run->quality_uplift_pct,
            'total_cost_tokens' => (int) $run->total_cost_tokens,
            'branch_a_mode' => $run->branch_a_mode,
            'branch_b_mode' => $run->branch_b_mode,
            'branch_a_status' => $run->branch_a_status,
            'branch_b_status' => $run->branch_b_status,
            'branch_a_tokens' => (int) $run->branch_a_tokens,
            'branch_b_tokens' => (int) $run->branch_b_tokens,
            'branch_a_duration_ms' => (int) $run->branch_a_duration_ms,
            'branch_b_duration_ms' => (int) $run->branch_b_duration_ms,
            'created_at' => $run->created_at,
            'completed_at' => $run->completed_at,
        ];
    }

    /**
     * Cancel a running speculative execution.
     */
    public function cancel(string $specRunId): bool
    {
        $run = $this->getRun($specRunId);
        if (!$run || !in_array($run->status, ['pending', 'running'])) {
            return false;
        }

        DB::update("UPDATE speculative_executions SET status = 'cancelled', completed_at = NOW() WHERE spec_run_id = ?", [
            $specRunId,
        ]);

        Cache::put("speculative:{$specRunId}:status", 'cancelled', self::REDIS_TTL);

        Log::info("SpeculativeExecution: Cancelled", ['spec_run_id' => $specRunId]);
        return true;
    }

    /**
     * Get aggregate stats, optionally filtered by agent.
     */
    public function getStats(?string $agentId = null): array
    {
        $params = [];
        $where = '';
        if ($agentId) {
            $where = 'WHERE agent_id = ?';
            $params[] = $agentId;
        }

        $overall = DB::selectOne("
            SELECT
                COUNT(*) as total_runs,
                SUM(status = 'completed') as completed,
                SUM(status = 'failed') as failed,
                SUM(status = 'cancelled') as cancelled,
                SUM(status IN ('pending', 'running', 'arbitrating')) as active,
                AVG(CASE WHEN status = 'completed' THEN quality_uplift_pct END) as avg_uplift,
                AVG(CASE WHEN status = 'completed' THEN total_cost_tokens END) as avg_cost_tokens,
                AVG(CASE WHEN status = 'completed' THEN branch_a_duration_ms + branch_b_duration_ms END) as avg_total_duration_ms
            FROM speculative_executions
            {$where}
        ", $params);

        $modeWins = DB::select("
            SELECT
                winning_mode,
                COUNT(*) as wins,
                AVG(quality_uplift_pct) as avg_uplift
            FROM speculative_executions
            {$where}
            " . ($where ? 'AND' : 'WHERE') . " status = 'completed' AND winning_mode IS NOT NULL
            GROUP BY winning_mode
            ORDER BY wins DESC
        ", $params);

        $triggerBreakdown = DB::select("
            SELECT
                trigger_type,
                COUNT(*) as runs,
                SUM(status = 'completed') as completed,
                AVG(CASE WHEN status = 'completed' THEN quality_uplift_pct END) as avg_uplift
            FROM speculative_executions
            {$where}
            GROUP BY trigger_type
            ORDER BY runs DESC
        ", $params);

        // Disabled agents
        $disabledAgents = [];
        if (!$agentId) {
            $agents = DB::select("SELECT DISTINCT agent_id FROM speculative_executions");
            foreach ($agents as $agent) {
                if (Cache::has("speculative_disabled:{$agent->agent_id}")) {
                    $disabledAgents[] = $agent->agent_id;
                }
            }
        } elseif (Cache::has("speculative_disabled:{$agentId}")) {
            $disabledAgents[] = $agentId;
        }

        return [
            'overall' => $overall,
            'mode_wins' => $modeWins,
            'trigger_breakdown' => $triggerBreakdown,
            'disabled_agents' => $disabledAgents,
        ];
    }

    /**
     * Get recent speculative runs.
     */
    public function getHistory(int $limit = 20, ?string $agentId = null): array
    {
        $params = [];
        $where = '';
        if ($agentId) {
            $where = 'WHERE agent_id = ?';
            $params[] = $agentId;
        }
        $params[] = $limit;

        return DB::select("
            SELECT spec_run_id, agent_id, task_key, status, winner, winning_mode,
                   branch_a_mode, branch_b_mode, quality_uplift_pct, total_cost_tokens,
                   trigger_type, created_at, completed_at,
                   branch_a_duration_ms, branch_b_duration_ms
            FROM speculative_executions
            {$where}
            ORDER BY created_at DESC
            LIMIT ?
        ", $params);
    }

    // ─── Review Approval Handler ────────────────────────────────────────

    /**
     * Handle approval of a speculative run review item.
     * Future: human can override the LLM judge's decision.
     */
    public function onApprove(int $itemId, array $details): array
    {
        $specRunId = $details['spec_run_id'] ?? null;
        if (!$specRunId) {
            return ['success' => false, 'error' => 'Missing spec_run_id'];
        }

        $overrideWinner = $details['override_winner'] ?? null;
        if ($overrideWinner && in_array($overrideWinner, ['branch_a', 'branch_b', 'tie'])) {
            $run = $this->getRun($specRunId);
            if (!$run) {
                return ['success' => false, 'error' => 'Run not found'];
            }

            $winningMode = match ($overrideWinner) {
                'branch_a' => $run->branch_a_mode,
                'branch_b' => $run->branch_b_mode,
                default => null,
            };

            DB::update("
                UPDATE speculative_executions
                SET winner = ?, winning_mode = ?,
                    arbitration_reasoning = CONCAT(arbitration_reasoning, ' [HUMAN OVERRIDE]')
                WHERE spec_run_id = ?
            ", [$overrideWinner, $winningMode, $specRunId]);

            return ['success' => true, 'overridden_to' => $overrideWinner];
        }

        return ['success' => true, 'message' => 'Approved as-is'];
    }

    // ─── Agent Tool Handlers ────────────────────────────────────────────

    /**
     * Handle the request_speculative agent tool.
     * Sets a Redis flag so the next run of this agent uses speculative execution.
     */
    public function requestSpeculative(array $params): array
    {
        $agentId = $params['agent_id'] ?? '';
        $reason = $params['reason'] ?? 'Agent requested speculative execution';

        if (empty($agentId)) {
            return ['success' => false, 'error' => 'agent_id required'];
        }

        if (Cache::has("speculative_disabled:{$agentId}")) {
            return [
                'success' => false,
                'error' => "Speculative execution is currently auto-disabled for {$agentId} (low uplift)",
            ];
        }

        Cache::put("speculative_request:{$agentId}", [
            'reason' => $reason,
            'requested_at' => now()->toISOString(),
        ], 3600); // 1 hour TTL

        return [
            'success' => true,
            'message' => "Speculative execution requested for next run of {$agentId}",
            'flag_ttl_minutes' => 60,
        ];
    }

    /**
     * Handle the speculative_status agent tool.
     */
    public function speculativeStatus(array $params): array
    {
        $specRunId = $params['spec_run_id'] ?? '';
        if (empty($specRunId)) {
            return ['success' => false, 'error' => 'spec_run_id required'];
        }

        $result = $this->getResult($specRunId);
        if ($result) {
            return ['success' => true, 'completed' => true, 'result' => $result];
        }

        $run = $this->getRun($specRunId);
        if (!$run) {
            return ['success' => false, 'error' => 'Run not found'];
        }

        return [
            'success' => true,
            'completed' => false,
            'status' => $run->status,
            'branch_a_status' => $run->branch_a_status,
            'branch_b_status' => $run->branch_b_status,
        ];
    }

    /**
     * Handle the speculative_stats agent tool.
     */
    public function speculativeStats(array $params): array
    {
        $agentId = $params['agent_id'] ?? null;
        return ['success' => true, 'stats' => $this->getStats($agentId)];
    }

    /**
     * Handle the cancel_speculative agent tool.
     */
    public function cancelSpeculative(array $params): array
    {
        $specRunId = $params['spec_run_id'] ?? '';
        if (empty($specRunId)) {
            return ['success' => false, 'error' => 'spec_run_id required'];
        }

        $cancelled = $this->cancel($specRunId);
        return ['success' => $cancelled, 'spec_run_id' => $specRunId];
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Derive a task key from task description for categorization.
     */
    private function deriveTaskKey(string $task): string
    {
        // Extract first meaningful words, slugify
        $words = array_slice(
            array_filter(explode(' ', strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $task)))),
            0, 4
        );
        return implode('_', $words) ?: 'unknown';
    }
}

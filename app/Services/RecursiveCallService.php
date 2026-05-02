<?php

namespace App\Services;

use App\DTOs\RecursionMetrics;
use App\DTOs\RecursionResult;
use App\DTOs\RecursionState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RLM: Orchestrates recursive task decomposition.
 *
 * Checks master kill switch → per-service config → executes strategy → records metrics.
 * Non-invasive: if disabled or unconfigured, returns RecursionResult::bypassed()
 * and the calling service proceeds with its normal non-recursive logic.
 */
class RecursiveCallService
{
    private RecursionBudgetManager $budgetManager;

    private MoveOnNudge $moveOnNudge;

    private SystemConfigService $systemConfig;

    private AIService $ai;

    public function __construct(
        RecursionBudgetManager $budgetManager,
        MoveOnNudge $moveOnNudge,
        SystemConfigService $systemConfig,
        AIService $ai
    ) {
        $this->budgetManager = $budgetManager;
        $this->moveOnNudge = $moveOnNudge;
        $this->systemConfig = $systemConfig;
        $this->ai = $ai;
    }

    /**
     * Entry point. Returns RecursionResult::bypassed() if:
     * - Master kill switch OFF
     * - Per-service kill switch OFF
     * - No config row exists for this service
     * - Parent budget too small
     */
    public function execute(
        string $serviceName,
        string $strategy,
        array $context,
        callable $processFn,
        ?array $parentBudget = null,
        ?int $sessionId = null,
        bool $sensitiveSafe = false
    ): RecursionResult {
        // 1. Check master kill switch
        if (! $this->isMasterEnabled()) {
            Log::debug('RecursiveCallService: master switch disabled');

            return RecursionResult::bypassed($context);
        }

        // 2. Load per-service config
        $config = $this->getServiceConfig($serviceName);
        if ($config === null || ! ($config['enabled'] ?? false)) {
            Log::debug('RecursiveCallService: service not enabled', ['service' => $serviceName]);

            return RecursionResult::bypassed($context);
        }

        // 3. Validate strategy
        $allowedStrategies = json_decode($config['strategies'] ?? '[]', true) ?: [];
        if (! in_array($strategy, $allowedStrategies, true)) {
            Log::warning('RecursiveCallService: strategy not allowed', [
                'service' => $serviceName,
                'strategy' => $strategy,
                'allowed' => $allowedStrategies,
            ]);

            return RecursionResult::bypassed($context);
        }

        // 4. Allocate budget
        $parentTokens = $parentBudget['tokens'] ?? config('recursion.default_max_tokens', 30000);
        $parentTime = $parentBudget['time'] ?? config('recursion.default_max_time_seconds', 300);
        $parentCost = $parentBudget['cost'] ?? config('recursion.default_max_cost_usd', 0.50);

        $budget = $this->budgetManager->allocate($parentTokens, $parentTime, $parentCost, $config);
        if ($budget === null) {
            Log::info('RecursiveCallService: parent budget too small for recursion', ['service' => $serviceName]);

            return RecursionResult::bypassed($context);
        }

        // 5. Build initial state
        $state = new RecursionState(
            $serviceName,
            $strategy,
            $budget,
            (int) ($config['max_depth'] ?? 1),
            $sensitiveSafe,
            $sessionId
        );

        // 6. Set model role for sub-calls
        $subCallRole = $config['sub_call_model_role'] ?? config('recursion.sub_call_model_role', 'fast');
        $synthesisRole = $config['synthesis_model_role'] ?? config('recursion.synthesis_model_role', 'quality');

        // 7. Record root call
        $rootCallId = $this->recordCall($state, null, mb_substr(json_encode($context), 0, 1000));

        // 8. Execute strategy
        $metrics = new RecursionMetrics;

        try {
            $previousModelRole = $this->getCurrentModelRole();
            AIService::setAgentModelRole($subCallRole);

            $subResults = $this->dispatchSubCalls($state, $strategy, $context, $processFn, $rootCallId, $config, $metrics);

            // Switch to synthesis role for aggregation
            AIService::setAgentModelRole($synthesisRole);
            $aggregated = $this->aggregate($strategy, $subResults);

            // Restore original model role
            AIService::setAgentModelRole($previousModelRole);
        } catch (\Throwable $e) {
            AIService::setAgentModelRole($previousModelRole ?? null);
            Log::error('RecursiveCallService: execution failed', [
                'service' => $serviceName,
                'strategy' => $strategy,
                'error' => $e->getMessage(),
            ]);

            return RecursionResult::bypassed($context);
        }

        // 9. Finalize metrics
        $metrics->totalTimeSeconds = microtime(true) - $state->startTime;
        $metrics->depthReached = max($metrics->depthReached, $state->currentDepth);
        $metrics->noveltyScores = $state->noveltyScores;
        $localCount = count(array_filter($metrics->providersUsed, fn ($p) => str_contains($p ?? '', 'ollama')));
        $metrics->localProviderPct = $metrics->totalSubCalls > 0
            ? round(($localCount / $metrics->totalSubCalls) * 100, 2)
            : 0.0;

        // 10. Update root call record
        $this->completeCall($rootCallId, mb_substr(json_encode($aggregated), 0, 1000), $metrics);

        // 11. Record effectiveness summary
        $this->recordEffectiveness($state, $metrics);

        Log::info('RecursiveCallService: complete', [
            'service' => $serviceName,
            'strategy' => $strategy,
            'sub_calls' => $metrics->totalSubCalls,
            'depth' => $metrics->depthReached,
            'tokens' => $metrics->totalTokens,
            'time_s' => round($metrics->totalTimeSeconds, 2),
            'local_pct' => $metrics->localProviderPct,
            'move_ons' => $metrics->moveOnCount,
        ]);

        return new RecursionResult(true, $aggregated, $metrics);
    }

    /**
     * Decides whether to spawn another sub-call at current depth.
     */
    public function shouldRecurse(RecursionState $state, array $config = []): bool
    {
        // Budget check
        $exhausted = $this->budgetManager->exhausted($state->budget);
        if ($exhausted !== false) {
            $state->unwindRequested = true;
            $state->unwindReason = $exhausted;

            return false;
        }

        // MoveOnNudge check
        $decision = $this->moveOnNudge->evaluate($state, $config);
        if (! $decision->shouldContinue()) {
            $state->unwindRequested = true;
            $state->unwindReason = $decision->trigger;

            return false;
        }

        return true;
    }

    /**
     * Dispatches sub-calls according to strategy.
     */
    public function dispatchSubCalls(
        RecursionState $state,
        string $strategy,
        array $context,
        callable $processFn,
        ?int $parentCallId,
        array $config,
        RecursionMetrics $metrics
    ): array {
        return match ($strategy) {
            'partition_map' => $this->executePartitionMap($state, $context, $processFn, $parentCallId, $config, $metrics),
            'quality_gate_retry' => $this->executeQualityGateRetry($state, $context, $processFn, $parentCallId, $config, $metrics),
            'evidence_chase' => $this->executeEvidenceChase($state, $context, $processFn, $parentCallId, $config, $metrics),
            'hierarchical_summarize' => $this->executeHierarchicalSummarize($state, $context, $processFn, $parentCallId, $config, $metrics),
            default => [$processFn($context)],
        };
    }

    /**
     * Merges sub-call results into a single output per strategy.
     */
    public function aggregate(string $strategy, array $subResults): array
    {
        if (empty($subResults)) {
            return [];
        }

        return match ($strategy) {
            'partition_map' => $this->aggregateMerge($subResults),
            'quality_gate_retry' => $this->aggregateBest($subResults),
            'evidence_chase' => $this->aggregateChain($subResults),
            'hierarchical_summarize' => $this->aggregateMerge($subResults),
            default => $this->aggregateMerge($subResults),
        };
    }

    // =========================================================================
    // Strategy implementations
    // =========================================================================

    private function executePartitionMap(
        RecursionState $state,
        array $context,
        callable $processFn,
        ?int $parentCallId,
        array $config,
        RecursionMetrics $metrics
    ): array {
        $items = $context['items'] ?? $context['partitions'] ?? [$context];
        $results = [];

        foreach ($items as $idx => $partition) {
            if (! $this->shouldRecurse($state, $config)) {
                break;
            }

            $subContext = is_array($partition) ? $partition : ['data' => $partition];
            $result = $this->executeSubCall($state, $subContext, $processFn, $parentCallId, $config, $metrics);
            $results[] = $result;
        }

        return $results;
    }

    private function executeQualityGateRetry(
        RecursionState $state,
        array $context,
        callable $processFn,
        ?int $parentCallId,
        array $config,
        RecursionMetrics $metrics
    ): array {
        $results = [];
        $maxAttempts = min($state->maxDepth + 1, 3); // At most 3 attempts

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0 && ! $this->shouldRecurse($state, $config)) {
                break;
            }

            $result = $this->executeSubCall($state, $context, $processFn, $parentCallId, $config, $metrics);
            $results[] = $result;

            // If result is good enough, stop
            $quality = $this->assessQuality($result);
            if ($quality >= 0.70) {
                break;
            }

            // Refine context for retry
            $context['_retry_attempt'] = $attempt + 1;
            $context['_previous_result'] = $result;
        }

        return $results;
    }

    private function executeEvidenceChase(
        RecursionState $state,
        array $context,
        callable $processFn,
        ?int $parentCallId,
        array $config,
        RecursionMetrics $metrics
    ): array {
        $results = [];
        $currentContext = $context;

        while ($this->shouldRecurse($state, $config)) {
            $result = $this->executeSubCall($state, $currentContext, $processFn, $parentCallId, $config, $metrics);
            $results[] = $result;

            // Extract next lead from result
            $nextLead = $result['next_lead'] ?? $result['citations'] ?? null;
            if (empty($nextLead)) {
                break; // No further leads to chase
            }

            $currentContext = array_merge($context, [
                '_chase_depth' => count($results),
                '_lead' => $nextLead,
                '_prior_results' => $results,
            ]);
        }

        return $results;
    }

    private function executeHierarchicalSummarize(
        RecursionState $state,
        array $context,
        callable $processFn,
        ?int $parentCallId,
        array $config,
        RecursionMetrics $metrics
    ): array {
        $items = $context['items'] ?? $context['chunks'] ?? [$context];
        $results = [];

        // Process leaf nodes
        foreach ($items as $item) {
            if (! $this->shouldRecurse($state, $config)) {
                break;
            }

            $subContext = is_array($item) ? $item : ['data' => $item];
            $result = $this->executeSubCall($state, $subContext, $processFn, $parentCallId, $config, $metrics);
            $results[] = $result;
        }

        return $results;
    }

    // =========================================================================
    // Sub-call execution
    // =========================================================================

    private function executeSubCall(
        RecursionState $state,
        array $subContext,
        callable $processFn,
        ?int $parentCallId,
        array $config,
        RecursionMetrics $metrics
    ): mixed {
        $callStart = microtime(true);

        $state->currentDepth++;
        $state->budget->currentDepth = $state->currentDepth;
        $metrics->depthReached = max($metrics->depthReached, $state->currentDepth);

        // Record the sub-call
        $callId = $this->recordCall($state, $parentCallId, mb_substr(json_encode($subContext), 0, 1000));

        // Track context window size
        $contextSize = (int) (strlen(json_encode($subContext)) / 1.5); // Conservative token estimate
        $metrics->contextWindowSizes[] = $contextSize;

        try {
            $result = $processFn($subContext);
        } catch (\Throwable $e) {
            Log::warning('RecursiveCallService: sub-call failed', [
                'service' => $state->serviceName,
                'depth' => $state->currentDepth,
                'error' => $e->getMessage(),
            ]);
            $result = null;
            $state->subCallOutputs[] = null; // Counts toward stall detection
        }

        $state->currentDepth--;
        $state->budget->currentDepth = $state->currentDepth;

        $callTime = microtime(true) - $callStart;
        $callTokens = $this->estimateTokens($result);

        // Update budget
        $this->budgetManager->consume($state->budget, $callTokens, $callTime, 0.0);

        // Track output for novelty/repetition
        $outputStr = is_string($result) ? $result : json_encode($result);
        $state->subCallOutputs[] = $outputStr;

        // Compute novelty
        $priorOutputs = array_slice($state->subCallOutputs, 0, -1);
        $novelty = $this->moveOnNudge->computeNovelty($outputStr ?? '', array_filter($priorOutputs, 'is_string'));
        $state->noveltyScores[] = $novelty;

        // Track provider (from last AIService call context)
        $provider = $this->getLastProvider();
        $metrics->providersUsed[] = $provider;

        // Update metrics
        $metrics->totalSubCalls++;
        $metrics->totalTokens += $callTokens;
        $metrics->totalCostUsd += 0.0; // Cost tracking TBD per provider

        // Check if MoveOnNudge should fire
        $decision = $this->moveOnNudge->evaluate($state, $config);
        $moveOnTriggered = ! $decision->shouldContinue();
        if ($moveOnTriggered) {
            $state->unwindRequested = true;
            $state->unwindReason = $decision->trigger;
            $metrics->moveOnCount++;
            if ($metrics->primaryMoveOnReason === null) {
                $metrics->primaryMoveOnReason = $decision->trigger;
            }
        }

        // Update call record
        $this->completeSubCall($callId, $outputStr, $novelty, $callTokens, $contextSize, $provider, $callTime, $moveOnTriggered, $decision->trigger ?? null);

        return $result;
    }

    // =========================================================================
    // Aggregation helpers
    // =========================================================================

    private function aggregateMerge(array $subResults): array
    {
        $merged = [];
        foreach ($subResults as $result) {
            if (is_array($result)) {
                $merged = array_merge($merged, $result);
            } elseif ($result !== null) {
                $merged[] = $result;
            }
        }

        return $merged;
    }

    private function aggregateBest(array $subResults): array
    {
        if (empty($subResults)) {
            return [];
        }

        // Return the last result (highest quality attempt from retry strategy)
        $best = end($subResults);

        return is_array($best) ? $best : ['result' => $best];
    }

    private function aggregateChain(array $subResults): array
    {
        // Evidence chase: return the chain in order
        return array_values(array_filter($subResults, fn ($r) => $r !== null));
    }

    // =========================================================================
    // Config & state helpers
    // =========================================================================

    /**
     * Check master kill switch. Redis-cached for performance.
     */
    public function isMasterEnabled(): bool
    {
        $ttl = config('recursion.config_cache_ttl', 60);

        return Cache::remember('rlm:master_enabled', $ttl, function () {
            return $this->systemConfig->get('recursion.master_enabled', false) === true
                || $this->systemConfig->get('recursion.master_enabled', 'false') === 'true';
        });
    }

    /**
     * Load per-service config from recursion_config table. Redis-cached.
     */
    public function getServiceConfig(string $serviceName): ?array
    {
        $ttl = config('recursion.config_cache_ttl', 60);

        return Cache::remember("rlm:config:{$serviceName}", $ttl, function () use ($serviceName) {
            $row = DB::selectOne(
                'SELECT * FROM recursion_config WHERE service_name = ? LIMIT 1',
                [$serviceName]
            );

            return $row ? (array) $row : null;
        });
    }

    private function getCurrentModelRole(): ?string
    {
        // Read the static property via reflection since there's no getter
        try {
            $ref = new \ReflectionClass(AIService::class);
            $prop = $ref->getProperty('agentModelRole');
            $prop->setAccessible(true);

            return $prop->getValue();
        } catch (\Throwable) {
            return null;
        }
    }

    private function getLastProvider(): ?string
    {
        // Best effort — check AIService's last provider used
        try {
            return Cache::get('ai_last_provider') ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function estimateTokens(mixed $result): int
    {
        if ($result === null) {
            return 0;
        }
        $str = is_string($result) ? $result : json_encode($result);

        return (int) (strlen($str) / 4); // ~4 chars per token
    }

    private function assessQuality(mixed $result): float
    {
        if ($result === null || (is_array($result) && empty($result))) {
            return 0.0;
        }

        if (is_array($result)) {
            // Check for explicit quality/confidence indicators
            if (isset($result['confidence'])) {
                return (float) $result['confidence'];
            }
            if (isset($result['quality'])) {
                return (float) $result['quality'];
            }

            // Non-empty array = moderate quality
            return 0.60;
        }

        if (is_string($result) && strlen($result) > 50) {
            return 0.70;
        }

        return 0.50;
    }

    // =========================================================================
    // Database recording (non-fatal)
    // =========================================================================

    private function recordCall(RecursionState $state, ?int $parentCallId, ?string $inputSummary): ?int
    {
        try {
            DB::insert('
                INSERT INTO agent_recursion_calls
                    (session_id, service_name, parent_call_id, depth, strategy, input_summary, model_role, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ', [
                $state->sessionId,
                $state->serviceName,
                $parentCallId,
                $state->currentDepth,
                $state->strategy,
                $inputSummary,
                $state->currentDepth === 0
                    ? config('recursion.synthesis_model_role', 'quality')
                    : config('recursion.sub_call_model_role', 'fast'),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable $e) {
            Log::debug('RecursiveCallService: failed to record call (non-fatal)', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function completeSubCall(
        ?int $callId,
        ?string $outputSummary,
        float $noveltyScore,
        int $tokensUsed,
        int $contextWindowSize,
        ?string $providerUsed,
        float $timeSeconds,
        bool $moveOnTriggered,
        ?string $moveOnReason
    ): void {
        if ($callId === null) {
            return;
        }

        try {
            DB::update('
                UPDATE agent_recursion_calls SET
                    output_summary = ?,
                    novelty_score = ?,
                    tokens_used = ?,
                    context_window_size = ?,
                    provider_used = ?,
                    time_seconds = ?,
                    move_on_triggered = ?,
                    move_on_reason = ?,
                    completed_at = NOW()
                WHERE id = ?
            ', [
                mb_substr($outputSummary ?? '', 0, 5000),
                round($noveltyScore, 4),
                $tokensUsed,
                $contextWindowSize,
                $providerUsed,
                round($timeSeconds, 2),
                $moveOnTriggered ? 1 : 0,
                $moveOnReason,
                $callId,
            ]);
        } catch (\Throwable $e) {
            Log::debug('RecursiveCallService: failed to update call (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function completeCall(?int $callId, ?string $outputSummary, RecursionMetrics $metrics): void
    {
        if ($callId === null) {
            return;
        }

        try {
            DB::update('
                UPDATE agent_recursion_calls SET
                    output_summary = ?,
                    tokens_used = ?,
                    time_seconds = ?,
                    completed_at = NOW()
                WHERE id = ?
            ', [
                mb_substr($outputSummary ?? '', 0, 5000),
                $metrics->totalTokens,
                round($metrics->totalTimeSeconds, 2),
                $callId,
            ]);
        } catch (\Throwable $e) {
            Log::debug('RecursiveCallService: failed to complete root call (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function recordEffectiveness(RecursionState $state, RecursionMetrics $metrics): void
    {
        try {
            DB::insert('
                INSERT INTO recursion_effectiveness
                    (session_id, service_name, max_depth_reached, total_sub_calls, total_tokens,
                     total_time_seconds, total_cost_usd, avg_novelty_score, avg_context_window,
                     move_on_count, primary_move_on_reason, local_provider_pct, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ', [
                $state->sessionId,
                $state->serviceName,
                $metrics->depthReached,
                $metrics->totalSubCalls,
                $metrics->totalTokens,
                round($metrics->totalTimeSeconds, 2),
                round($metrics->totalCostUsd, 4),
                count($metrics->noveltyScores) > 0
                    ? round(array_sum($metrics->noveltyScores) / count($metrics->noveltyScores), 4)
                    : null,
                count($metrics->contextWindowSizes) > 0
                    ? (int) round(array_sum($metrics->contextWindowSizes) / count($metrics->contextWindowSizes))
                    : null,
                $metrics->moveOnCount,
                $metrics->primaryMoveOnReason,
                $metrics->localProviderPct,
            ]);
        } catch (\Throwable $e) {
            Log::debug('RecursiveCallService: failed to record effectiveness (non-fatal)', ['error' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Services;

use App\DTOs\RecursionBudget;

/**
 * RLM: Pure logic budget manager for recursive task decomposition.
 *
 * Carves a recursion budget from the parent's remaining resources.
 * No DB, no side effects — all state lives in RecursionBudget DTO.
 */
class RecursionBudgetManager
{
    /**
     * Carve a recursion budget from the parent's remaining resources.
     * Returns null if parent budget is too small to justify recursion.
     */
    public function allocate(
        float $parentTokensRemaining,
        float $parentTimeRemaining,
        float $parentCostRemaining,
        array $config
    ): ?RecursionBudget {
        $minTokens = config('recursion.min_parent_tokens', 5000);
        $minTime = config('recursion.min_parent_time_seconds', 30);

        if ($parentTokensRemaining < $minTokens || $parentTimeRemaining < $minTime) {
            return null;
        }

        $tokenFraction = config('recursion.budget_fraction_tokens', 0.60);
        $timeFraction = config('recursion.budget_fraction_time', 0.70);
        $costFraction = config('recursion.budget_fraction_cost', 0.80);

        $maxTokens = min(
            (int) ($parentTokensRemaining * $tokenFraction),
            (int) ($config['max_tokens'] ?? config('recursion.default_max_tokens', 30000))
        );

        $maxTime = min(
            $parentTimeRemaining * $timeFraction,
            (float) ($config['max_time_seconds'] ?? config('recursion.default_max_time_seconds', 300))
        );

        $maxCost = min(
            $parentCostRemaining * $costFraction,
            (float) ($config['max_cost_usd'] ?? config('recursion.default_max_cost_usd', 0.50))
        );

        $maxDepth = (int) ($config['max_depth'] ?? config('recursion.default_max_depth', 1));

        return new RecursionBudget($maxTokens, $maxTime, $maxCost, $maxDepth);
    }

    /**
     * Record consumption from a completed sub-call.
     */
    public function consume(RecursionBudget $budget, int $tokens, float $seconds, float $cost): void
    {
        $budget->tokensUsed += $tokens;
        $budget->timeUsed += $seconds;
        $budget->costUsed += $cost;
    }

    /**
     * Check if any budget limit is exhausted.
     * Returns false (keep going) or the trigger name.
     */
    public function exhausted(RecursionBudget $budget): false|string
    {
        if ($budget->tokensUsed >= $budget->maxTokens) {
            return 'token_budget';
        }

        if ($budget->timeUsed >= $budget->maxTimeSeconds) {
            return 'time_budget';
        }

        if ($budget->costUsed >= $budget->maxCostUsd) {
            return 'cost_budget';
        }

        if ($budget->currentDepth >= $budget->maxDepth) {
            return 'depth_limit';
        }

        return false;
    }

    /**
     * Returns remaining capacity as percentages for logging.
     */
    public function remaining(RecursionBudget $budget): array
    {
        return [
            'tokens_pct' => $budget->maxTokens > 0
                ? round(1.0 - ($budget->tokensUsed / $budget->maxTokens), 4)
                : 0.0,
            'time_pct' => $budget->maxTimeSeconds > 0
                ? round(1.0 - ($budget->timeUsed / $budget->maxTimeSeconds), 4)
                : 0.0,
            'cost_pct' => $budget->maxCostUsd > 0
                ? round(1.0 - ($budget->costUsed / $budget->maxCostUsd), 4)
                : 0.0,
            'depth_remaining' => $budget->maxDepth - $budget->currentDepth,
        ];
    }
}

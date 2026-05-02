<?php

namespace App\DTOs;

class RecursionMetrics
{
    public int $depthReached = 0;
    public int $totalSubCalls = 0;
    public int $totalTokens = 0;
    public float $totalTimeSeconds = 0.0;
    public float $totalCostUsd = 0.0;
    public array $noveltyScores = [];
    public array $contextWindowSizes = [];
    public array $providersUsed = [];
    public int $moveOnCount = 0;
    public ?string $primaryMoveOnReason = null;
    public float $localProviderPct = 0.0;

    public function toArray(): array
    {
        return [
            'depth_reached' => $this->depthReached,
            'total_sub_calls' => $this->totalSubCalls,
            'total_tokens' => $this->totalTokens,
            'total_time_seconds' => round($this->totalTimeSeconds, 2),
            'total_cost_usd' => round($this->totalCostUsd, 4),
            'avg_novelty' => count($this->noveltyScores) > 0
                ? round(array_sum($this->noveltyScores) / count($this->noveltyScores), 4)
                : null,
            'avg_context_window' => count($this->contextWindowSizes) > 0
                ? (int) round(array_sum($this->contextWindowSizes) / count($this->contextWindowSizes))
                : null,
            'move_on_count' => $this->moveOnCount,
            'primary_move_on_reason' => $this->primaryMoveOnReason,
            'local_provider_pct' => round($this->localProviderPct, 2),
        ];
    }
}

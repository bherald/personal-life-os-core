<?php

namespace App\DTOs;

class RecursionBudget
{
    public int $maxTokens;
    public float $maxTimeSeconds;
    public float $maxCostUsd;
    public int $maxDepth;

    public int $tokensUsed = 0;
    public float $timeUsed = 0.0;
    public float $costUsed = 0.0;
    public int $currentDepth = 0;

    public function __construct(int $maxTokens, float $maxTimeSeconds, float $maxCostUsd, int $maxDepth)
    {
        $this->maxTokens = $maxTokens;
        $this->maxTimeSeconds = $maxTimeSeconds;
        $this->maxCostUsd = $maxCostUsd;
        $this->maxDepth = $maxDepth;
    }
}

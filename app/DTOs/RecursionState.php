<?php

namespace App\DTOs;

class RecursionState
{
    public int $currentDepth;
    public int $maxDepth;
    public RecursionBudget $budget;
    public array $subCallOutputs = [];
    public array $noveltyScores = [];
    public bool $unwindRequested = false;
    public ?string $unwindReason = null;
    public string $strategy;
    public string $serviceName;
    public ?int $sessionId;
    public bool $sensitiveSafe;
    public float $startTime;

    public function __construct(
        string $serviceName,
        string $strategy,
        RecursionBudget $budget,
        int $maxDepth = 1,
        bool $sensitiveSafe = false,
        ?int $sessionId = null,
        int $currentDepth = 0
    ) {
        $this->serviceName = $serviceName;
        $this->strategy = $strategy;
        $this->budget = $budget;
        $this->maxDepth = $maxDepth;
        $this->sensitiveSafe = $sensitiveSafe;
        $this->sessionId = $sessionId;
        $this->currentDepth = $currentDepth;
        $this->startTime = microtime(true);
    }
}

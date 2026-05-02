<?php

namespace App\DTOs;

class RecursionResult
{
    public bool $recursionUsed;
    public array $output;
    public RecursionMetrics $metrics;

    public function __construct(bool $recursionUsed, array $output, ?RecursionMetrics $metrics = null)
    {
        $this->recursionUsed = $recursionUsed;
        $this->output = $output;
        $this->metrics = $metrics ?? new RecursionMetrics();
    }

    public static function bypassed(array $output): self
    {
        return new self(false, $output);
    }
}

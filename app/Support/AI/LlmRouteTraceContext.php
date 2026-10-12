<?php

namespace App\Support\AI;

final class LlmRouteTraceContext
{
    private int $attemptIndex = 0;

    private function __construct(private readonly string $traceId) {}

    public static function create(?string $traceId = null): self
    {
        $normalized = strtolower(trim((string) $traceId));

        if (preg_match('/^[0-9a-f]{32}$/', $normalized) !== 1
            || $normalized === str_repeat('0', 32)) {
            $normalized = bin2hex(random_bytes(16));
        }

        return new self($normalized);
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function nextAttemptIndex(): int
    {
        return ++$this->attemptIndex;
    }
}

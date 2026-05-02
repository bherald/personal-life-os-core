<?php

namespace App\Exceptions\AI;

/**
 * Rate limited (HTTP 429) - retryable with configurable backoff (default 30s).
 */
class RateLimitException extends TransientException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        int $suggestedBackoffMs = 30000,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus ?? 429, $suggestedBackoffMs, $previous);
    }
}

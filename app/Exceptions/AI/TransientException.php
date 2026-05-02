<?php

namespace App\Exceptions\AI;

/**
 * Transient errors that should be retried with backoff.
 */
abstract class TransientException extends AIServiceException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        int $suggestedBackoffMs = 1000,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus, true, $suggestedBackoffMs, $previous);
    }
}

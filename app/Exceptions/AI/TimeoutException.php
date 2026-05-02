<?php

namespace App\Exceptions\AI;

/**
 * Request timed out - retryable with 2000ms backoff.
 */
class TimeoutException extends TransientException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus, 2000, $previous);
    }
}

<?php

namespace App\Exceptions\AI;

/**
 * Network/connection error - retryable with 1000ms backoff.
 */
class ConnectionException extends TransientException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus, 1000, $previous);
    }
}

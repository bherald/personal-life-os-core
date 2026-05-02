<?php

namespace App\Exceptions\AI;

/**
 * Server overloaded (HTTP 503) - retryable with 5000ms backoff.
 */
class ServerOverloadException extends TransientException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus ?? 503, 5000, $previous);
    }
}

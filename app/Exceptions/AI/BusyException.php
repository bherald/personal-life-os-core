<?php

namespace App\Exceptions\AI;

/**
 * Provider busy/locked - retryable with 500ms backoff, consider fallback.
 */
class BusyException extends TransientException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus, 500, $previous);
    }
}

<?php

namespace App\Exceptions\AI;

/**
 * Invalid request format (HTTP 400) - NOT retryable.
 */
class ValidationException extends PermanentException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus ?? 400, $previous);
    }
}

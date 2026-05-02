<?php

namespace App\Exceptions\AI;

/**
 * Context/prompt too long - NOT retryable.
 */
class ContextLengthException extends PermanentException
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

<?php

namespace App\Exceptions\AI;

/**
 * Model not found - NOT retryable.
 */
class ModelNotFoundException extends PermanentException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus ?? 404, $previous);
    }
}

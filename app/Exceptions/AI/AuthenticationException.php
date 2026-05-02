<?php

namespace App\Exceptions\AI;

/**
 * Invalid API key or authentication failed (HTTP 401/403) - NOT retryable.
 */
class AuthenticationException extends PermanentException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus ?? 401, $previous);
    }
}

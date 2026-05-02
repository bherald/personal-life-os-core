<?php

namespace App\Exceptions\AI;

/**
 * Permanent errors that should NOT be retried.
 */
abstract class PermanentException extends AIServiceException
{
    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $provider, $model, $httpStatus, false, 0, $previous);
    }
}

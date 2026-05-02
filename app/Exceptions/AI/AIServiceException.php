<?php

namespace App\Exceptions\AI;

use Exception;

/**
 * Base exception for all AI service errors.
 * Provides context about provider, model, and error details.
 */
class AIServiceException extends Exception
{
    protected string $provider;
    protected ?string $model;
    protected ?int $httpStatus;
    protected bool $retryable;
    protected int $suggestedBackoffMs;

    public function __construct(
        string $message,
        string $provider = 'unknown',
        ?string $model = null,
        ?int $httpStatus = null,
        bool $retryable = false,
        int $suggestedBackoffMs = 1000,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
        $this->provider = $provider;
        $this->model = $model;
        $this->httpStatus = $httpStatus;
        $this->retryable = $retryable;
        $this->suggestedBackoffMs = $suggestedBackoffMs;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getSuggestedBackoffMs(): int
    {
        return $this->suggestedBackoffMs;
    }

    public function toArray(): array
    {
        return [
            'type' => static::class,
            'message' => $this->getMessage(),
            'provider' => $this->provider,
            'model' => $this->model,
            'http_status' => $this->httpStatus,
            'retryable' => $this->retryable,
            'suggested_backoff_ms' => $this->suggestedBackoffMs,
        ];
    }
}

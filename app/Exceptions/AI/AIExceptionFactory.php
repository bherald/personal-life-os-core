<?php

namespace App\Exceptions\AI;

use Illuminate\Http\Client\Response;
use Exception;

/**
 * Factory for creating typed AI exceptions from HTTP responses or error messages.
 */
class AIExceptionFactory
{
    /**
     * Create typed exception from HTTP response.
     */
    public static function fromHttpResponse(
        Response $response,
        string $provider,
        ?string $model = null
    ): AIServiceException {
        $status = $response->status();
        $body = $response->body();
        $bodyLower = strtolower($body);

        return match (true) {
            $status === 429 => new RateLimitException(
                "Rate limited: {$body}",
                $provider,
                $model,
                $status,
                self::parseRetryAfter($response)
            ),
            $status === 503 => new ServerOverloadException(
                "Server overloaded: {$body}",
                $provider,
                $model,
                $status
            ),
            $status === 401 || $status === 403 => new AuthenticationException(
                "Authentication failed: {$body}",
                $provider,
                $model,
                $status
            ),
            $status === 400 && str_contains($bodyLower, 'context') => new ContextLengthException(
                "Context length exceeded: {$body}",
                $provider,
                $model,
                $status
            ),
            $status === 400 => new ValidationException(
                "Invalid request: {$body}",
                $provider,
                $model,
                $status
            ),
            $status === 404 && str_contains($bodyLower, 'model') => new ModelNotFoundException(
                "Model not found: {$body}",
                $provider,
                $model,
                $status
            ),
            $status >= 500 => new ServerOverloadException(
                "Server error: {$body}",
                $provider,
                $model,
                $status
            ),
            default => new AIServiceException(
                "Request failed ({$status}): {$body}",
                $provider,
                $model,
                $status
            ),
        };
    }

    /**
     * Create typed exception from error message (for CLI/non-HTTP errors).
     */
    public static function fromMessage(
        string $message,
        string $provider,
        ?string $model = null,
        ?Exception $previous = null
    ): AIServiceException {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'timeout') || str_contains($lower, 'timed out')
                => new TimeoutException($message, $provider, $model, null, $previous),
            str_contains($lower, 'connection refused') || str_contains($lower, 'connection reset') || str_contains($lower, 'could not resolve')
                => new ConnectionException($message, $provider, $model, null, $previous),
            str_contains($lower, 'content policy') || str_contains($lower, 'content blocked')
                => new ContentPolicyException($message, $provider, $model, null, $previous),
            str_contains($lower, 'busy') || preg_match('/\block\b/', $lower) === 1
                => new BusyException($message, $provider, $model, null, $previous),
            str_contains($lower, 'context length') || str_contains($lower, 'too long') || str_contains($lower, 'token limit')
                => new ContextLengthException($message, $provider, $model, null, $previous),
            str_contains($lower, 'authentication') || str_contains($lower, 'api key') || str_contains($lower, 'unauthorized')
                => new AuthenticationException($message, $provider, $model, null, $previous),
            str_contains($lower, 'model not found') || str_contains($lower, 'unknown model')
                => new ModelNotFoundException($message, $provider, $model, null, $previous),
            str_contains($lower, 'rate limit') || str_contains($lower, '429')
                || str_contains($lower, "you've hit your limit") || str_contains($lower, 'you have hit your limit')
                || str_contains($lower, 'usage limit') || str_contains($lower, 'daily limit')
                || str_contains($lower, 'weekly limit')
                => new RateLimitException($message, $provider, $model, null, 30000, $previous),
            str_contains($lower, '503') || str_contains($lower, 'overload') || str_contains($lower, 'unavailable')
                => new ServerOverloadException($message, $provider, $model, null, $previous),
            str_contains($lower, 'malformed utf') || str_contains($lower, 'json_encode error') || str_contains($lower, 'incorrectly encoded')
                => new ValidationException('Input contained invalid UTF-8 characters — sanitized and skipping to fallback: ' . $message, $provider, $model, null, $previous),
            default => new AIServiceException($message, $provider, $model, null, false, 1000, $previous),
        };
    }

    /**
     * Parse Retry-After header from response.
     */
    private static function parseRetryAfter(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');
        if ($retryAfter && is_numeric($retryAfter)) {
            return max(10000, (int) $retryAfter * 1000);
        }
        return 30000; // Default 30s for rate limits
    }
}

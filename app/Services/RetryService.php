<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Retry Service
 *
 * Provides intelligent retry logic with exponential backoff, jitter, and
 * configurable retry strategies for handling transient failures
 *
 * Features:
 * - Exponential backoff with jitter (prevents thundering herd)
 * - Linear backoff
 * - Fixed delay
 * - Configurable retry conditions
 * - Automatic logging
 * - Timeout protection
 *
 * Usage:
 * ```php
 * $retryService = app(RetryService::class);
 *
 * $result = $retryService->retry(
 *     operation: fn() => Http::get('https://api.example.com'),
 *     maxAttempts: 3,
 *     backoffStrategy: 'exponential',
 *     shouldRetry: fn($e) => $e instanceof TimeoutException
 * );
 * ```
 */
class RetryService
{
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_BASE_DELAY = 1000; // milliseconds
    private const DEFAULT_MAX_DELAY = 30000; // milliseconds
    private const DEFAULT_JITTER_PERCENT = 25; // ±25%

    /**
     * Execute an operation with retry logic
     *
     * @param callable $operation Operation to execute
     * @param int $maxAttempts Maximum number of attempts (default: 3)
     * @param string $backoffStrategy Backoff strategy: 'exponential', 'linear', 'fixed'
     * @param callable|null $shouldRetry Function to determine if exception should be retried
     * @param int|null $baseDelay Base delay in milliseconds (default: 1000)
     * @param int|null $maxDelay Maximum delay in milliseconds (default: 30000)
     * @param string|null $operationName Name for logging (default: null)
     * @return mixed Result of operation
     * @throws Exception If all attempts fail
     */
    public function retry(
        callable $operation,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        string $backoffStrategy = 'exponential',
        ?callable $shouldRetry = null,
        ?int $baseDelay = null,
        ?int $maxDelay = null,
        ?string $operationName = null
    ): mixed {
        $baseDelay = $baseDelay ?? self::DEFAULT_BASE_DELAY;
        $maxDelay = $maxDelay ?? self::DEFAULT_MAX_DELAY;
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                // Execute operation
                $result = $operation();

                // Success - log if it took retries
                if ($attempt > 1) {
                    Log::info("RetryService: Operation succeeded after {$attempt} attempts", [
                        'operation' => $operationName ?? 'unknown',
                        'attempts' => $attempt,
                    ]);
                }

                return $result;

            } catch (Exception $e) {
                $lastException = $e;

                // Check if we should retry this exception
                if ($shouldRetry && !$shouldRetry($e)) {
                    Log::info("RetryService: Exception not retryable", [
                        'operation' => $operationName ?? 'unknown',
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                // Don't sleep on last attempt
                if ($attempt >= $maxAttempts) {
                    break;
                }

                // Calculate delay
                $delay = $this->calculateDelay($attempt, $backoffStrategy, $baseDelay, $maxDelay);

                Log::warning("RetryService: Attempt {$attempt}/{$maxAttempts} failed, retrying in {$delay}ms", [
                    'operation' => $operationName ?? 'unknown',
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'delay_ms' => $delay,
                ]);

                // Sleep before retry
                usleep($delay * 1000); // Convert ms to microseconds
            }
        }

        Log::error("RetryService: All {$maxAttempts} attempts failed", [
            'operation' => $operationName ?? 'unknown',
            'final_exception' => get_class($lastException),
            'message' => $lastException->getMessage(),
        ]);

        throw $lastException;
    }

    /**
     * Calculate delay for next retry attempt
     *
     * @param int $attempt Current attempt number (1-based)
     * @param string $strategy Backoff strategy
     * @param int $baseDelay Base delay in milliseconds
     * @param int $maxDelay Maximum delay in milliseconds
     * @return int Delay in milliseconds
     */
    private function calculateDelay(
        int $attempt,
        string $strategy,
        int $baseDelay,
        int $maxDelay
    ): int {
        switch ($strategy) {
            case 'exponential':
                // Exponential backoff: delay = baseDelay * 2^(attempt-1)
                // Attempt 1: 1s, Attempt 2: 2s, Attempt 3: 4s, Attempt 4: 8s
                $delay = $baseDelay * pow(2, $attempt - 1);

                // Add jitter (±25% randomness to prevent thundering herd)
                $jitter = $delay * (self::DEFAULT_JITTER_PERCENT / 100);
                $delay = $delay + random_int(-$jitter, $jitter);

                // Cap at max delay
                return min((int)$delay, $maxDelay);

            case 'linear':
                // Linear backoff: delay = baseDelay * attempt
                // Attempt 1: 1s, Attempt 2: 2s, Attempt 3: 3s
                $delay = $baseDelay * $attempt;
                return min($delay, $maxDelay);

            case 'fixed':
                // Fixed delay: always use base delay
                return $baseDelay;

            default:
                Log::warning("RetryService: Unknown backoff strategy '{$strategy}', using exponential");
                return $this->calculateDelay($attempt, 'exponential', $baseDelay, $maxDelay);
        }
    }

    /**
     * Quick helper: Retry HTTP requests
     *
     * @param callable $httpCall HTTP call to execute
     * @param int $maxAttempts Maximum attempts
     * @return mixed HTTP response
     */
    public function retryHttp(callable $httpCall, int $maxAttempts = 3): mixed
    {
        return $this->retry(
            operation: $httpCall,
            maxAttempts: $maxAttempts,
            backoffStrategy: 'exponential',
            shouldRetry: function (Exception $e) {
                // Retry on network errors, not on client errors (4xx)
                $message = $e->getMessage();
                return str_contains($message, 'timeout') ||
                       str_contains($message, 'Connection refused') ||
                       str_contains($message, 'Connection reset') ||
                       str_contains($message, '500') ||
                       str_contains($message, '502') ||
                       str_contains($message, '503') ||
                       str_contains($message, '504');
            },
            operationName: 'HTTP Request'
        );
    }

    /**
     * Quick helper: Retry database operations
     *
     * @param callable $dbCall Database call to execute
     * @param int $maxAttempts Maximum attempts
     * @return mixed Query result
     */
    public function retryDatabase(callable $dbCall, int $maxAttempts = 2): mixed
    {
        return $this->retry(
            operation: $dbCall,
            maxAttempts: $maxAttempts,
            backoffStrategy: 'fixed',
            baseDelay: 500, // 500ms fixed delay
            shouldRetry: function (Exception $e) {
                // Retry on deadlocks and connection errors
                $message = $e->getMessage();
                return str_contains($message, 'Deadlock') ||
                       str_contains($message, 'Lock wait timeout') ||
                       str_contains($message, 'Connection lost') ||
                       str_contains($message, 'server has gone away');
            },
            operationName: 'Database Query'
        );
    }

    /**
     * Quick helper: Retry AI/LLM calls
     *
     * @param callable $aiCall AI call to execute
     * @param int $maxAttempts Maximum attempts (default: 2)
     * @return mixed AI response
     */
    public function retryAI(callable $aiCall, int $maxAttempts = 2): mixed
    {
        return $this->retry(
            operation: $aiCall,
            maxAttempts: $maxAttempts,
            backoffStrategy: 'fixed',
            baseDelay: 2000, // 2s fixed delay for AI
            shouldRetry: function (Exception $e) {
                // Only retry on timeout/connection, not on invalid responses
                $message = $e->getMessage();
                return str_contains($message, 'timeout') ||
                       str_contains($message, 'Connection refused') ||
                       str_contains($message, 'Connection reset') ||
                       str_contains($message, 'Could not connect');
            },
            operationName: 'AI Call'
        );
    }

}

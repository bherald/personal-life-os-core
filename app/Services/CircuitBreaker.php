<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker Exception
 *
 * Thrown when circuit breaker is open and rejecting requests
 */
class CircuitBreakerException extends Exception
{
    public function __construct(string $serviceName, int $failureCount)
    {
        parent::__construct(
            "Circuit breaker is OPEN for service '{$serviceName}' (failures: {$failureCount}). Service unavailable."
        );
    }
}

/**
 * Circuit Breaker Service
 *
 * Implements circuit breaker pattern to prevent cascading failures
 * when external services (Ollama, RSS feeds, APIs) are failing
 *
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Service failing, requests rejected immediately (fail fast)
 * - HALF_OPEN: Testing if service recovered, limited requests allowed
 *
 * Flow:
 * 1. Start in CLOSED state
 * 2. Track failures - if N consecutive failures, open circuit
 * 3. While OPEN, reject all requests for timeout period
 * 4. After timeout, move to HALF_OPEN
 * 5. In HALF_OPEN, allow limited requests to test recovery
 * 6. If successful, close circuit; if fail, open again
 *
 * Usage:
 * ```php
 * $breaker = app(CircuitBreaker::class);
 *
 * $result = $breaker->call('ollama_api', function() {
 *     return Http::post('http://ollama:11434/api/generate', [...]);
 * });
 * ```
 */
class CircuitBreaker
{
    // Circuit states
    private const STATE_CLOSED = 'closed';       // Normal operation
    private const STATE_OPEN = 'open';           // Failing, reject requests
    private const STATE_HALF_OPEN = 'half_open'; // Testing recovery

    // Thresholds (configurable per service via config)
    private const FAILURE_THRESHOLD = 5;      // Failures before opening
    private const SUCCESS_THRESHOLD = 2;      // Successes to close from half-open
    private const TIMEOUT_SECONDS = 60;       // Time before trying half-open
    private const HALF_OPEN_MAX_CALLS = 3;    // Max concurrent calls in half-open

    /**
     * Execute operation with circuit breaker protection
     *
     * @param string $serviceName Unique service identifier (e.g., 'ollama_api', 'rss_feed')
     * @param callable $operation Operation to execute
     * @param array $config Optional configuration overrides
     * @return mixed Result of operation
     * @throws CircuitBreakerException If circuit is open
     * @throws Exception If operation fails
     */
    public function call(string $serviceName, callable $operation, array $config = []): mixed
    {
        // Get configuration
        $failureThreshold = $config['failure_threshold'] ?? self::FAILURE_THRESHOLD;
        $successThreshold = $config['success_threshold'] ?? self::SUCCESS_THRESHOLD;
        $timeoutSeconds = $config['timeout_seconds'] ?? self::TIMEOUT_SECONDS;

        $state = $this->getState($serviceName);

        // If circuit is OPEN, fail fast
        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset($serviceName, $timeoutSeconds)) {
                // Timeout expired, try half-open
                $this->setState($serviceName, self::STATE_HALF_OPEN);
                Log::info("CircuitBreaker: Moving to HALF_OPEN", ['service' => $serviceName]);
            } else {
                // Still in timeout, reject request
                $failures = $this->getFailureCount($serviceName);
                throw new CircuitBreakerException($serviceName, $failures);
            }
        }

        // If HALF_OPEN, check if we're at max concurrent calls
        if ($state === self::STATE_HALF_OPEN) {
            $halfOpenCalls = $this->getHalfOpenCallCount($serviceName);
            if ($halfOpenCalls >= self::HALF_OPEN_MAX_CALLS) {
                $failures = $this->getFailureCount($serviceName);
                throw new CircuitBreakerException($serviceName, $failures);
            }
            $this->incrementHalfOpenCalls($serviceName);
        }

        try {
            // Execute operation
            $result = $operation();

            // Success - record it
            $this->recordSuccessInternal($serviceName, $successThreshold);

            // Decrement half-open counter if applicable
            if ($state === self::STATE_HALF_OPEN) {
                $this->decrementHalfOpenCalls($serviceName);
            }

            return $result;

        } catch (Exception $e) {
            // Failure - record it
            $this->recordFailureInternal($serviceName, $failureThreshold);

            // Decrement half-open counter if applicable
            if ($state === self::STATE_HALF_OPEN) {
                $this->decrementHalfOpenCalls($serviceName);
            }

            throw $e;
        }
    }

    /**
     * Check if service is available (circuit is not open)
     *
     * When timeoutSeconds is provided, an open circuit that has exceeded its cooldown
     * will be transitioned to half-open (allowing requests through for recovery testing).
     *
     * @param string $serviceName Service name
     * @param int|null $timeoutSeconds Optional cooldown override (default: TIMEOUT_SECONDS)
     * @return bool True if requests are allowed
     */
    public function isAvailable(string $serviceName, ?int $timeoutSeconds = null): bool
    {
        $state = $this->getState($serviceName);

        if ($state === self::STATE_OPEN) {
            $timeout = $timeoutSeconds ?? self::TIMEOUT_SECONDS;
            if ($this->shouldAttemptReset($serviceName, $timeout)) {
                $this->setState($serviceName, self::STATE_HALF_OPEN);
                Log::info("CircuitBreaker: Moving to HALF_OPEN (availability check)", ['service' => $serviceName]);
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * Record a successful operation (public interface for external callers)
     *
     * @param string $serviceName Service name
     * @param int|null $successThreshold Optional override for successes needed to close from half-open
     * @return void
     */
    public function recordSuccess(string $serviceName, ?int $successThreshold = null): void
    {
        $this->recordSuccessInternal($serviceName, $successThreshold ?? self::SUCCESS_THRESHOLD);
    }

    /**
     * Record a failed operation (public interface for external callers)
     *
     * @param string $serviceName Service name
     * @param int|null $failureThreshold Optional override for failures before opening circuit
     * @return void
     */
    public function recordFailure(string $serviceName, ?int $failureThreshold = null): void
    {
        $this->recordFailureInternal($serviceName, $failureThreshold ?? self::FAILURE_THRESHOLD);
    }

    /**
     * Get current circuit state for service (with Redis resilience)
     *
     * @param string $serviceName Service name
     * @return string State: 'closed', 'open', or 'half_open'
     */
    public function getState(string $serviceName): string
    {
        return $this->safeCache('get', "circuit_breaker.{$serviceName}.state", self::STATE_CLOSED);
    }

    /**
     * Redis-resilient cache operation wrapper
     *
     * @param string $operation Cache operation: 'get', 'put', 'forget'
     * @param string $key Cache key
     * @param mixed $default Default value for get, or value for put, or TTL
     * @param int|null $ttl TTL for put operations
     * @return mixed Result of cache operation or default on failure
     */
    private function safeCache(string $operation, string $key, mixed $default = null, ?int $ttl = null): mixed
    {
        try {
            return match ($operation) {
                'get' => Cache::get($key, $default),
                'put' => Cache::put($key, $default, $ttl ?? 3600),
                'forget' => Cache::forget($key),
                default => $default,
            };
        } catch (\Predis\Connection\ConnectionException $e) {
            Log::warning("CircuitBreaker: Redis connection failed, using default", [
                'operation' => $operation,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $default;
        } catch (\RedisException $e) {
            Log::warning("CircuitBreaker: Redis error, using default", [
                'operation' => $operation,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $default;
        } catch (Exception $e) {
            Log::warning("CircuitBreaker: Cache error, using default", [
                'operation' => $operation,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Set circuit state for service
     *
     * @param string $serviceName Service name
     * @param string $state New state
     * @return void
     */
    private function setState(string $serviceName, string $state): void
    {
        $this->safeCache('put', "circuit_breaker.{$serviceName}.state", $state, 3600);

        if ($state === self::STATE_OPEN) {
            $this->safeCache('put', "circuit_breaker.{$serviceName}.opened_at", now(), 3600);
        }
    }

    /**
     * Record successful operation (internal)
     *
     * @param string $serviceName Service name
     * @param int $successThreshold Success threshold
     * @return void
     */
    private function recordSuccessInternal(string $serviceName, int $successThreshold): void
    {
        $state = $this->getState($serviceName);

        if ($state === self::STATE_HALF_OPEN) {
            // In half-open, count successes
            $successes = $this->safeCache('get', "circuit_breaker.{$serviceName}.successes", 0);
            $successes++;

            if ($successes >= $successThreshold) {
                // Enough successes, close circuit
                $this->setState($serviceName, self::STATE_CLOSED);
                $this->safeCache('forget', "circuit_breaker.{$serviceName}.failures");
                $this->safeCache('forget', "circuit_breaker.{$serviceName}.successes");
                $this->safeCache('forget', "circuit_breaker.{$serviceName}.opened_at");

                Log::info("CircuitBreaker: Circuit CLOSED (recovered)", [
                    'service' => $serviceName,
                    'successes' => $successes,
                ]);
            } else {
                $this->safeCache('put', "circuit_breaker.{$serviceName}.successes", $successes, 300);
            }
        } else {
            // In closed state, reset failure count on success
            $this->safeCache('forget', "circuit_breaker.{$serviceName}.failures");
        }
    }

    /**
     * Record failed operation (internal)
     *
     * @param string $serviceName Service name
     * @param int $failureThreshold Failure threshold
     * @return void
     */
    private function recordFailureInternal(string $serviceName, int $failureThreshold): void
    {
        $failures = $this->safeCache('get', "circuit_breaker.{$serviceName}.failures", 0);
        $failures++;

        $this->safeCache('put', "circuit_breaker.{$serviceName}.failures", $failures, 300);

        if ($failures >= $failureThreshold) {
            // Too many failures, open circuit
            $this->setState($serviceName, self::STATE_OPEN);

            Log::error("CircuitBreaker: Circuit OPENED", [
                'service' => $serviceName,
                'consecutive_failures' => $failures,
            ]);

            // Generate system alert so circuit opens are visible beyond logs
            try {
                app(ProactiveAlertService::class)->generateAlert(
                    'circuit_breaker_open',
                    'warning',
                    "Circuit open: {$serviceName}",
                    "Circuit breaker opened for {$serviceName} after {$failures} consecutive failures. Will auto-retry after cooldown.",
                    [
                        'source_type' => 'circuit_breaker',
                        'source_id' => $serviceName,
                        'metric_name' => 'consecutive_failures',
                    ],
                    $failures,
                    $failureThreshold
                );
            } catch (\Throwable $e) {
                Log::debug('CircuitBreaker: Failed to generate alert (non-fatal)', ['error' => $e->getMessage()]);
            }
        } else {
            Log::warning("CircuitBreaker: Failure recorded", [
                'service' => $serviceName,
                'failures' => $failures,
                'threshold' => $failureThreshold,
            ]);
        }

        // Reset success counter
        $this->safeCache('forget', "circuit_breaker.{$serviceName}.successes");
    }

    /**
     * Check if enough time has passed to attempt reset
     *
     * @param string $serviceName Service name
     * @param int $timeoutSeconds Timeout in seconds
     * @return bool True if should attempt reset
     */
    private function shouldAttemptReset(string $serviceName, int $timeoutSeconds): bool
    {
        $openedAt = $this->safeCache('get', "circuit_breaker.{$serviceName}.opened_at");

        if (!$openedAt) {
            return true;
        }

        return now()->diffInSeconds($openedAt) >= $timeoutSeconds;
    }

    /**
     * Get failure count for service
     *
     * @param string $serviceName Service name
     * @return int Failure count
     */
    private function getFailureCount(string $serviceName): int
    {
        return $this->safeCache('get', "circuit_breaker.{$serviceName}.failures", 0);
    }

    /**
     * Get half-open call count
     *
     * @param string $serviceName Service name
     * @return int Current call count
     */
    private function getHalfOpenCallCount(string $serviceName): int
    {
        return $this->safeCache('get', "circuit_breaker.{$serviceName}.half_open_calls", 0);
    }

    /**
     * Increment half-open call counter
     *
     * @param string $serviceName Service name
     * @return void
     */
    private function incrementHalfOpenCalls(string $serviceName): void
    {
        $count = $this->getHalfOpenCallCount($serviceName);
        $this->safeCache('put', "circuit_breaker.{$serviceName}.half_open_calls", $count + 1, 60);
    }

    /**
     * Decrement half-open call counter
     *
     * @param string $serviceName Service name
     * @return void
     */
    private function decrementHalfOpenCalls(string $serviceName): void
    {
        $count = $this->getHalfOpenCallCount($serviceName);
        if ($count > 0) {
            $this->safeCache('put', "circuit_breaker.{$serviceName}.half_open_calls", $count - 1, 60);
        }
    }

    /**
     * Manually reset circuit (for admin use)
     *
     * @param string $serviceName Service name
     * @return void
     */
    public function reset(string $serviceName): void
    {
        $this->setState($serviceName, self::STATE_CLOSED);
        $this->safeCache('forget', "circuit_breaker.{$serviceName}.failures");
        $this->safeCache('forget', "circuit_breaker.{$serviceName}.successes");
        $this->safeCache('forget', "circuit_breaker.{$serviceName}.opened_at");
        $this->safeCache('forget', "circuit_breaker.{$serviceName}.half_open_calls");

        Log::info("CircuitBreaker: Manually reset", ['service' => $serviceName]);
    }

    /**
     * Get circuit breaker status for all services
     *
     * @return array Status array
     */
    public function getStatus(): array
    {
        $services = ['ollama_api', 'rss_feed', 'news_api', 'weather_api', 'claude_cli', 'searxng'];
        $status = [];

        foreach ($services as $service) {
            $state = $this->getState($service);
            $failures = $this->getFailureCount($service);

            $openedAt = $this->safeCache('get', "circuit_breaker.{$service}.opened_at");
            $status[$service] = [
                'state' => $state,
                'failures' => $failures,
                'opened_at' => $state === self::STATE_OPEN && $openedAt
                    ? $openedAt->toISOString()
                    : null,
            ];
        }

        return $status;
    }

}

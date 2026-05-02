<?php

/**
 * Circuit Breaker Configuration (SC-2.1)
 *
 * Single source of truth for circuit breaker behavior across:
 * - AIService (provider-level circuits)
 * - LLMPoolManagerService (pool-level circuits)
 *
 * All services read from config() with local constant fallback.
 */

return [
    // Number of consecutive failures before opening the circuit
    'failure_threshold' => (int) env('CIRCUIT_FAILURE_THRESHOLD', 5),

    // Seconds to wait before testing a half-open circuit
    'cooldown_seconds' => (int) env('CIRCUIT_COOLDOWN_SECONDS', 30),

    // Number of test requests allowed in half-open state
    'half_open_requests' => (int) env('CIRCUIT_HALF_OPEN_REQUESTS', 1),
];

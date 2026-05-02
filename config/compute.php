<?php

/**
 * Compute Router Configuration (N106)
 *
 * Central config for ComputeRouterService — dynamic routing of GPU/CPU
 * compute tasks across multiple machines (modeled on LLMPoolManagerService).
 */

return [
    // Circuit breaker
    'circuit_breaker' => [
        'failure_threshold' => (int) env('COMPUTE_CIRCUIT_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('COMPUTE_CIRCUIT_COOLDOWN', 60),
    ],

    // SSH execution
    'ssh_timeout' => (int) env('COMPUTE_SSH_TIMEOUT', 30),
    'ssh_user_default' => env('COMPUTE_SSH_USER_DEFAULT', 'plos'),

    // Health scoring deltas (applied per check)
    'health_delta_success' => 5,
    'health_delta_failure' => 10,

    // Instance cache TTL (seconds)
    'cache_ttl' => (int) env('COMPUTE_CACHE_TTL', 300),

    // Face detection batch size — images per Python subprocess invocation (N87)
    'face_detection_batch_size' => (int) env('FACE_DETECTION_BATCH_SIZE', 50), // Images per Python subprocess invocation for face detection
];

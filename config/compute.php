<?php

/**
 * Compute Router Configuration (N106)
 *
 * Central config for ComputeRouterService — dynamic routing of GPU/CPU
 * compute tasks across multiple machines (modeled on LLMPoolManagerService).
 */

return [
    'execution_supervisor' => base_path('scripts/plos-compute-execution-supervisor.py'),
    'execution_transport_python' => env('COMPUTE_TRANSPORT_PYTHON_BINARY', '/usr/bin/python3'),
    'execution_scope_systemd_run' => env('COMPUTE_SCOPE_SYSTEMD_RUN_BINARY', '/usr/bin/systemd-run'),
    'execution_scope_systemctl' => env('COMPUTE_SCOPE_SYSTEMCTL_BINARY', '/usr/bin/systemctl'),
    'execution_scope_cgroup_root' => env('COMPUTE_SCOPE_CGROUP_ROOT', '/sys/fs/cgroup'),
    'execution_scope_proof_max_directories' => 64,
    'execution_scope_proof_max_depth' => 8,
    'execution_scope_proof_timeout_seconds' => 2,

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

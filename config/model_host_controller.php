<?php

return [
    // Installation inputs only; runtime transport is table-owned. A public
    // install has no reviewed controller host or credential by default.
    'url' => env('PLOS_MODEL_HOST_CONTROLLER_URL', ''),
    'token_file' => env('PLOS_MODEL_HOST_CONTROLLER_TOKEN_FILE', ''),
    'expected_host_id' => env('PLOS_MODEL_HOST_CONTROLLER_HOST_ID', ''),
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLOS_MODEL_HOST_CONTROLLER_ALLOWED_HOSTS', ''))))),
    'reviewed_runtime_host' => env('PLOS_MODEL_HOST_REVIEWED_RUNTIME_HOST', ''),
    'reviewed_production_host' => env('PLOS_MODEL_HOST_REVIEWED_PRODUCTION_HOST', ''),
    'production_git_dir' => env('PLOS_MODEL_HOST_PRODUCTION_GIT_DIR', ''),
    'allowed_port' => (int) env('PLOS_MODEL_HOST_CONTROLLER_PORT', 11440),
    'connect_timeout_seconds' => 5,
    'request_timeout_seconds' => 30,
    'status_timeout_seconds' => 120,
    'mutation_timeout_seconds' => 600,
    'k3_mutation_timeout_seconds' => 2400,
];

<?php

/**
 * Timeout values in seconds for various operation categories (N82 SC-3).
 * Keys match TimeoutManager::TIMEOUTS array.
 */
return [
    'http'     => env('TIMEOUT_HTTP', 15),
    'rss'      => env('TIMEOUT_RSS', 20),
    'api'      => env('TIMEOUT_API', 25),
    'ai'       => env('TIMEOUT_AI', 300),
    'node'     => env('TIMEOUT_NODE', 420),
    'workflow' => env('TIMEOUT_WORKFLOW', 900),
    'db'       => env('TIMEOUT_DB', 10),
    'file'     => env('TIMEOUT_FILE', 60),

    // Dead Letter Queue retry batch (N87)
    'dlq_retry_batch' => (int) env('DLQ_RETRY_BATCH', 10), // DLQ items retried per maintenance run

    // Cache TTLs in seconds (N87 — moved from hardcoded constants)
    'cache' => [
        'analysis'     => (int) env('CACHE_TTL_ANALYSIS', 900),
        'load_data'    => (int) env('CACHE_TTL_LOAD', 3600),
        'workflow'     => (int) env('CACHE_TTL_WORKFLOW', 300),
        'research'     => (int) env('CACHE_TTL_RESEARCH', 900),
        'email'        => (int) env('CACHE_TTL_EMAIL', 3600),
    ],
];

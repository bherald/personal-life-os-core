<?php

/**
 * Research/RSS pipeline operational knobs (N82 SC-3 Config Promotion).
 */
return [
    'rss_max_parallel'   => env('RSS_MAX_PARALLEL', 10),
    'scheduler_heavy'    => env('SCHEDULER_HEAVY_MAX', 2),
    'scheduler_default'  => env('SCHEDULER_DEFAULT_MAX', 5),

    'web' => [
        'search_depth' => (int) env('RESEARCH_SEARCH_DEPTH', 3),
        'max_sources' => (int) env('RESEARCH_MAX_SOURCES', 10),
        'max_results_per_source' => (int) env('RESEARCH_MAX_RESULTS_PER_SOURCE', 5),
        'date_filter_days' => (int) env('RESEARCH_DATE_FILTER_DAYS', 30),
        'request_delay_ms' => (int) env('RESEARCH_REQUEST_DELAY_MS', 1000),
    ],

    // Research orchestration limits (N87)
    'max_articles_per_source' => (int) env('RESEARCH_MAX_ARTICLES_PER_SOURCE', 3),  // Articles to follow per source in research orchestration
    'max_sources_stored'      => (int) env('RESEARCH_MAX_SOURCES_STORED', 10),       // Max source URLs stored per research result

    // SmartSchedulerService constants (N87 — moved from hardcoded)
    'scheduler' => [
        'min_history_for_prediction'   => (int) env('SCHEDULER_MIN_HISTORY', 5),
        'heavy_workflow_threshold_sec' => (int) env('SCHEDULER_HEAVY_THRESHOLD', 300),
        'load_threshold_percent'       => (int) env('SCHEDULER_LOAD_THRESHOLD', 80),
        'peak_hours'                   => [6, 7, 8, 9, 17, 18, 19, 20],
    ],
];

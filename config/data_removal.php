<?php

/**
 * Data Removal pipeline operational limits (N87).
 */
return [
    // DataRemovalOpsService batch limits
    'broker_health_check_batch' => (int) env('DATA_REMOVAL_BROKER_HEALTH_BATCH', 10),  // Brokers health-checked per run
    'flag_stale_batch'          => (int) env('DATA_REMOVAL_FLAG_STALE_BATCH', 20),     // Stale requests flagged per run
    'flag_relistings_batch'     => (int) env('DATA_REMOVAL_FLAG_RELISTINGS_BATCH', 10), // Relistings flagged per run

    // DataRemovalService / BrokerScraperService runtime settings
    'followup_interval_days' => (int) env('DATA_REMOVAL_FOLLOWUP_INTERVAL_DAYS', 7),
    'recheck_interval_days' => (int) env('DATA_REMOVAL_RECHECK_INTERVAL_DAYS', 30),
    'max_followups' => (int) env('DATA_REMOVAL_MAX_FOLLOWUPS', 3),
    'ai_confidence_threshold' => (float) env('DATA_REMOVAL_AI_CONFIDENCE_THRESHOLD', 75),
    'auto_submit_threshold' => (float) env('DATA_REMOVAL_AUTO_SUBMIT_THRESHOLD', 90),
    'throttle_ms' => (int) env('DATA_REMOVAL_THROTTLE_MS', 3000),
    'max_requests_per_day' => (int) env('DATA_REMOVAL_MAX_REQUESTS_PER_DAY', 50),
    'scraper_timeout' => (int) env('DATA_REMOVAL_SCRAPER_TIMEOUT', 30),
    'browser_automation_timeout' => (int) env('BROWSER_AUTOMATION_TIMEOUT', 30),
];

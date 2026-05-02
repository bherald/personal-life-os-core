<?php

/**
 * Health & Monitoring Thresholds (SC-2.5)
 *
 * Central configuration for health scores, error tracking,
 * and system monitoring thresholds used by:
 * - LLMPoolManagerService (LLM health scoring)
 * - ErrorTrackingService (error rate detection)
 * - OpsMCPService (system health checks)
 */

return [
    // LLM health scoring
    'llm' => [
        'score_min' => 0,
        'score_max' => 100,
        'unhealthy_threshold' => 30,
        'degraded_threshold' => 60,

        // Routing score weights (must sum to 1.0)
        'weight_health' => 0.30,
        'weight_response_time' => 0.25,
        'weight_success_rate' => 0.20,
        'weight_priority' => 0.15,
        'weight_load' => 0.10,

        // Latency demotion: if ALL Ollama instances exceed this avg, prefer external providers
        'latency_demotion_ms' => 5000,
    ],

    // Compute instance health scoring (N106)
    'compute' => [
        'score_min' => 0,
        'score_max' => 100,
        'unhealthy_threshold' => 30,
        'degraded_threshold' => 60,

        // Routing score weights (must sum to 1.0)
        'weight_priority' => 0.40,
        'weight_health' => 0.30,
        'weight_speed' => 0.30,
    ],

    // Error tracking
    'errors' => [
        'rate_threshold' => (int) env('ERROR_RATE_THRESHOLD', 10),  // errors/hour
        'spike_multiplier' => (int) env('ERROR_SPIKE_MULTIPLIER', 3), // 3x baseline = spike
    ],

    // System health (ops monitoring)
    'system' => [
        'redis_memory_warning_percent' => 80,
        'failed_jobs_warning_count' => 5,
        'stuck_workflow_hours' => 6,
        'disk_space_critical_percent' => 90,
        'log_file_size_warning_mb' => 50,
        'min_horizon_workers' => 2,
        'backup_max_age_hours' => 25,
        'ssl_expiry_warning_days' => 14,
        'ssl_expiry_critical_days' => 7,
    ],

    // Consecutive job failure escalation (ScheduledJobService)
    'job_failures' => [
        'warning_threshold' => 3,   // Pushover priority 0
        'high_threshold' => 5,   // Pushover priority 1
        'emergency_threshold' => 10,  // Pushover priority 2
        'cooldown_seconds' => 21600, // 6h dedup per job per threshold
    ],

    // Agent doctor observe-only diagnostics
    'agents' => [
        'consecutive_failures_warning' => (int) env('AGENT_DOCTOR_FAILURES_WARNING', 2),
        'consecutive_failures_critical' => (int) env('AGENT_DOCTOR_FAILURES_CRITICAL', 3),
        'runtime_timeout_warning_ratio' => (float) env('AGENT_DOCTOR_RUNTIME_WARN_RATIO', 0.70),
        'runtime_timeout_critical_ratio' => (float) env('AGENT_DOCTOR_RUNTIME_CRIT_RATIO', 1.00),
        'review_queue_warning_fraction' => (float) env('AGENT_DOCTOR_REVIEW_WARN_FRACTION', 0.50),
        'review_queue_critical_fraction' => (float) env('AGENT_DOCTOR_REVIEW_CRIT_FRACTION', 0.90),
        'high_priority_warning_hours' => (float) env('AGENT_DOCTOR_HIGH_PRIORITY_WARN_HOURS', 6.0),
        'high_priority_critical_hours' => (float) env('AGENT_DOCTOR_HIGH_PRIORITY_CRIT_HOURS', 24.0),
        'tools_missing_warning' => (int) env('AGENT_DOCTOR_TOOLS_MISSING_WARNING', 1),
        'tools_blocked_critical' => (int) env('AGENT_DOCTOR_TOOLS_BLOCKED_CRITICAL', 1),
        'tools_inventory_max_listed' => (int) env('AGENT_DOCTOR_TOOLS_INVENTORY_MAX_LISTED', 25),
        'memory_tokens_warning' => (int) env('AGENT_DOCTOR_MEMORY_TOKENS_WARNING', 100_000),
        'episodes_without_distillation_warning' => (int) env('AGENT_DOCTOR_EPISODES_WITHOUT_DISTILLATION_WARNING', 25),
        'distillation_stale_hours_warning' => (float) env('AGENT_DOCTOR_DISTILLATION_STALE_HOURS_WARNING', 48.0),
        'procedure_min_success_rate' => (float) env('AGENT_DOCTOR_PROCEDURE_MIN_SUCCESS_RATE', 0.50),
        'procedure_min_uses_for_quality' => (int) env('AGENT_DOCTOR_PROCEDURE_MIN_USES_FOR_QUALITY', 3),
        'recursion' => [
            'depth_scan_row_limit' => (int) env('AGENT_DOCTOR_RECURSION_DEPTH_SCAN_ROW_LIMIT', 100_000),
            'min_calls_for_rate_status' => (int) env('AGENT_DOCTOR_RECURSION_MIN_CALLS_FOR_RATE_STATUS', 100),
            'move_on_rate_warning' => (float) env('AGENT_DOCTOR_RECURSION_MOVE_ON_RATE_WARNING', 0.40),
            'move_on_rate_critical' => (float) env('AGENT_DOCTOR_RECURSION_MOVE_ON_RATE_CRITICAL', 0.70),
        ],
    ],

    // LLM circuit cascade alerting
    'llm_cascade' => [
        'open_threshold' => 3,          // Number of open circuits to trigger alert
        'cooldown_seconds' => 1800,     // 30 min dedup between cascade alerts
    ],

    // Cleanup retention
    'retention' => [
        'log_days' => 7,
        'failed_jobs_days' => 7,
        'execution_logs_days' => 30,
    ],

    // Observe-only DBA telemetry reporting
    'dba_telemetry' => [
        'arc_size_review_gb' => 10.0,
        'arc_size_review_rows' => 10_000_000,
        'arc_growth_review_rows_7d' => 1_000_000,
        'arc_raw_scan_row_limit' => 5_000_000,
        'service_strategy_grouping_row_limit' => 100_000,
        'redis_memory_warning_ratio' => 0.70,
        'redis_memory_review_ratio' => 0.85,
        'redis_fragmentation_warning_ratio' => 1.8,
    ],
];

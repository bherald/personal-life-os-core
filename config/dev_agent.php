<?php

return [
    'trace' => [
        'enabled' => env('DEV_AGENT_TRACE_ENABLED', true),
        'dir' => env('DEV_AGENT_TRACE_DIR', storage_path('app/dev-agent/traces')),
        'max_event_bytes' => env('DEV_AGENT_TRACE_MAX_EVENT_BYTES', 16384),
        'max_summary_chars' => env('DEV_AGENT_TRACE_MAX_SUMMARY_CHARS', 500),
        'min_free_bytes' => env('DEV_AGENT_TRACE_MIN_FREE_BYTES', 10485760),
        'scan_hours_default' => env('DEV_AGENT_TRACE_SCAN_HOURS_DEFAULT', 24),
        'scan_hours_max' => env('DEV_AGENT_TRACE_SCAN_HOURS_MAX', 168),
        'retention_days' => env('DEV_AGENT_TRACE_RETENTION_DAYS', 14),
        'redaction_rules_version' => '2026-05-01',
    ],

    'readiness' => [
        'source' => 'config/dev_agent.php',
        'required_read_tools' => [
            'repo-dev.find_repo_files',
            'repo-dev.search_repo',
            'repo-dev.list_repo_directory',
            'repo-dev.read_repo_file',
            'repo-dev.list_routes',
        ],
        'required_write_tools' => [
            'repo-dev.write_repo_file',
            'repo-dev.apply_repo_patch',
            'repo-dev.run_verification',
        ],
        'required_patch_verify_tools' => [
            'repo-dev.apply_repo_patch',
            'repo-dev.run_verification',
        ],
    ],
];

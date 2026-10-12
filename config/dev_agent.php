<?php

return [
    'process_output' => [
        'max_bytes' => env('DEV_AGENT_PROCESS_OUTPUT_MAX_BYTES', 12000),
        'head_percent' => env('DEV_AGENT_PROCESS_OUTPUT_HEAD_PERCENT', 67),
    ],

    'trace' => [
        'enabled' => env('DEV_AGENT_TRACE_ENABLED', true),
        'dir' => env('DEV_AGENT_TRACE_DIR', storage_path('app/dev-agent/traces')),
        'max_event_bytes' => env('DEV_AGENT_TRACE_MAX_EVENT_BYTES', 16384),
        'max_summary_chars' => env('DEV_AGENT_TRACE_MAX_SUMMARY_CHARS', 500),
        'min_free_bytes' => env('DEV_AGENT_TRACE_MIN_FREE_BYTES', 10485760),
        'scan_hours_default' => env('DEV_AGENT_TRACE_SCAN_HOURS_DEFAULT', 24),
        'scan_hours_max' => env('DEV_AGENT_TRACE_SCAN_HOURS_MAX', 168),
        'retention_days' => env('DEV_AGENT_TRACE_RETENTION_DAYS', 14),
        'redaction_rules_version' => '2026-05-08',
    ],

    'trace_golden_eval' => [
        'schema' => 'plos.trace_golden_eval.v1',
        'cases' => [
            'model_success_redaction' => [
                'event' => [
                    'trace_id' => 'trc_golden_model_success',
                    'event_id' => 'evt_golden_model_success',
                    'sequence' => 2,
                    'event_type' => 'model_response',
                    'occurred_at' => '2026-07-09T12:00:00Z',
                    'surface' => 'offline:dev-assist',
                    'actor' => ['type' => 'system', 'id' => 'offline:dev-assist'],
                    'request' => [
                        'prompt_hash' => 'sha256:golden-model-request',
                        'prompt_summary' => 'Inspect /home/golden/private.txt with Bearer test-golden-bearer and api_key=test-trace-fixture-secret',
                    ],
                    'model' => ['instance_id' => 'fixture_local', 'model_role' => 'standard'],
                    'result' => ['status' => 'success', 'output_hash' => 'sha256:golden-model-response'],
                    'files' => [
                        ['path' => '/home/golden/private.txt', 'change' => 'observed'],
                    ],
                ],
                'expect' => [
                    'accepted' => true,
                    'paths' => [
                        'schema_version' => 'plos.dev_agent.trace.v1',
                        'event_type' => 'model_response',
                        'result.status' => 'success',
                        'request.prompt_hash' => 'sha256:golden-model-request',
                        'files.0.path' => '[REDACTED_PATH]',
                    ],
                    'required_paths' => [
                        'integrity.event_hash',
                        'integrity.redaction_rules_version',
                        'recorded_at',
                    ],
                    'required_fragments' => [
                        '[REDACTED_PATH]',
                        'Bearer [REDACTED]',
                        '[REDACTED_SECRET]',
                    ],
                    'forbidden_fragments' => [
                        '/home/golden/private.txt',
                        'test-golden-bearer',
                        'test-trace-fixture-secret',
                    ],
                ],
            ],
            'guarded_tool_denial' => [
                'event' => [
                    'trace_id' => 'trc_golden_tool_denial',
                    'event_id' => 'evt_golden_tool_denial',
                    'event_type' => 'tool_response',
                    'surface' => 'offline:dev-assist',
                    'actor' => ['type' => 'agent', 'id' => 'fixture-agent'],
                    'policy' => ['allowed' => false, 'reason_code' => 'tool_policy_denied'],
                    'tool' => ['name' => 'repo-dev.write_repo_file', 'risk_level' => 'write'],
                    'result' => ['status' => 'denied', 'reason_code' => 'approval_required'],
                ],
                'expect' => [
                    'accepted' => true,
                    'paths' => [
                        'event_type' => 'tool_response',
                        'policy.allowed' => false,
                        'policy.reason_code' => 'tool_policy_denied',
                        'tool.name' => 'repo-dev.write_repo_file',
                        'result.status' => 'denied',
                    ],
                    'required_paths' => ['integrity.event_hash'],
                    'forbidden_fragments' => [],
                ],
            ],
            'forbidden_raw_prompt' => [
                'event' => [
                    'trace_id' => 'trc_golden_forbidden',
                    'event_type' => 'model_request',
                    'request' => ['raw_prompt' => 'must never enter a trace envelope'],
                ],
                'expect' => [
                    'accepted' => false,
                    'error' => 'forbidden_raw_field',
                ],
            ],
        ],
    ],

    'post_edit_diagnostics' => [
        'enabled' => true,
        'max_files' => 20,
        'timeout_seconds' => 15,
        'runners' => [
            'php-syntax' => [
                'extensions' => ['php'],
            ],
        ],
    ],

    'repo_edit_recovery' => [
        'enabled' => true,
        'lock_timeout_seconds' => 10,
        'retention_minutes' => 120,
        'max_files' => 50,
        'max_file_bytes' => 4194304,
        'max_total_bytes' => 16777216,
        'max_session_operations' => 50,
    ],

    'repo_context_map' => [
        'enabled' => true,
        'max_runtime_ms' => 60000,
        'max_terms' => 12,
        'max_indexed_files' => 5000,
        'max_file_bytes' => 524288,
        'max_scan_bytes' => 67108864,
        'max_results' => 20,
        'max_symbols_per_file' => 12,
        'max_metadata_only_files' => 20,
        'metadata_only_match_weight' => 2,
        'metadata_only_frontend_match_weight' => 4,
        'max_native_match_file_bytes' => 2097152,
        'max_native_candidates' => 250,
        'max_native_declaration_candidates' => 750,
        'native_excluded_segments' => [
            '.git',
            '.mypy_cache',
            '.nox',
            '.pytest_cache',
            '.ruff_cache',
            '.tox',
            '.venv',
            '.venv-htr',
            '__pycache__',
            'node_modules',
            'vendor',
        ],
        'native_excluded_paths' => [
            'bootstrap/cache',
            'mcp-server/dist',
            'mcp-servers/plos/dist',
            'mcp-servers/prompt-compressor/dist',
            'public/build',
            'storage',
        ],
        'stop_words' => [
            'about', 'add', 'after', 'are', 'before', 'can', 'change', 'code',
            'could', 'does', 'from', 'have', 'how', 'improve', 'into', 'later',
            'make', 'need', 'plos', 'should', 'that',
            'the', 'this', 'update', 'what', 'when', 'where', 'which', 'with',
            'without',
        ],
    ],

    'readiness' => [
        'source' => 'config/dev_agent.php',
        'required_read_tools' => [
            'repo-dev.find_repo_files',
            'repo-dev.search_repo',
            'repo-dev.context_map',
            'repo-dev.list_repo_directory',
            'repo-dev.read_repo_file',
            'repo-dev.list_routes',
            'repo-dev.summarize_repo_edits',
        ],
        'required_write_tools' => [
            'repo-dev.write_repo_file',
            'repo-dev.apply_repo_patch',
            'repo-dev.restore_repo_edit',
            'repo-dev.run_verification',
        ],
        'required_patch_verify_tools' => [
            'repo-dev.apply_repo_patch',
            'repo-dev.run_verification',
        ],
    ],
];

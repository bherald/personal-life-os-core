<?php

return [
    'diagnostics' => [
        'search_loop_threshold' => 10,
        'redundant_search_window' => 10,
        'tail_validation_window' => 5,
        'structured_plan_write_threshold' => 5,

        // Ordered from strongest intent to broadest read-only fallback.
        'canonical_action_terms' => [
            'validation' => [
                'test', 'verify', 'validate', 'validation', 'lint', 'build',
                'audit', 'check', 'smoke', 'diagnostic',
            ],
            'plan' => ['plan', 'todo'],
            'file_write' => [
                'write', 'create', 'update', 'delete', 'remove', 'apply',
                'patch', 'save', 'submit', 'propose', 'link', 'unlink',
                'capture', 'download', 'import', 'sync', 'backfill',
            ],
            'search' => [
                'search', 'query', 'find', 'lookup', 'grep', 'discover',
                'locate', 'context_map',
            ],
            'file_read' => [
                'read', 'get', 'list', 'show', 'inspect', 'context', 'status',
                'report', 'profile', 'coverage', 'summary',
            ],
        ],

        'search_target_keys' => [
            'query', 'search', 'term', 'keyword', 'name', 'surname',
            'given_name', 'title', 'path', 'relative_path', 'file',
        ],
        'read_target_keys' => [
            'path', 'relative_path', 'file', 'filename', 'uri', 'url',
        ],
        'command_param_keys' => ['cmd', 'command'],
    ],
];

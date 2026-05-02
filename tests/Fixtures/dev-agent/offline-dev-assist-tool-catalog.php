<?php

return [
    'read_only' => [
        ['server' => 'repo-dev', 'name' => 'find_repo_files', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'search_repo', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'list_repo_directory', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'read_repo_file', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'list_routes', 'tool_class' => 'read'],
    ],
    'repo_write' => [
        ['server' => 'repo-dev', 'name' => 'find_repo_files', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'search_repo', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'list_repo_directory', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'read_repo_file', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'list_routes', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'write_repo_file', 'tool_class' => 'bounded-write'],
        ['server' => 'repo-dev', 'name' => 'apply_repo_patch', 'tool_class' => 'bounded-write'],
        ['server' => 'repo-dev', 'name' => 'run_verification', 'tool_class' => 'command-safe'],
    ],
    'missing_search' => [
        ['server' => 'repo-dev', 'name' => 'find_repo_files', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'list_repo_directory', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'read_repo_file', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'list_routes', 'tool_class' => 'read'],
        ['server' => 'repo-dev', 'name' => 'write_repo_file', 'tool_class' => 'bounded-write'],
        ['server' => 'repo-dev', 'name' => 'apply_repo_patch', 'tool_class' => 'bounded-write'],
        ['server' => 'repo-dev', 'name' => 'run_verification', 'tool_class' => 'command-safe'],
    ],
];

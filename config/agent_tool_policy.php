<?php

return [
    'schema_generation' => 2,

    'privacy' => [
        'default_class' => 'internal_private',
        'default_allows_private_data' => true,
        'external_no_private_class' => 'external_no_private',
        'external_no_private_mcp_servers' => [
            'searxng',
            'web-research',
        ],
        'external_no_private_service_patterns' => [
            'InternetArchiveService',
            'WebResearchService',
        ],
        'external_no_private_tool_prefixes' => [
            'internet_archive_',
            'loc_',
            'nara_',
            'web_research_',
        ],
    ],

    'result_limits' => [
        'read' => 12000,
        'write' => 8000,
        'destructive' => 4000,
        'blocked' => 1000,
        'default' => 8000,
    ],
];

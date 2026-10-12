<?php

return [
    'schema' => 'plos.release_documentation_audit.v1',

    'production_surface' => [
        'prefixes' => [
            'app/',
            'config/',
            'database/',
            'dev-ops/',
            'mcp-server/src/',
            'mcp-servers/plos/src/',
            'resources/js/',
            'resources/views/',
            'routes/',
            'scripts/',
        ],
        'exact' => [
            'backup.sh',
            'composer.json',
            'composer.lock',
            'offline_dev.sh',
            'package.json',
            'package-lock.json',
            'prod1.sh',
        ],
    ],

    'rules' => [
        [
            'key' => 'release_record',
            'reason_code' => 'release_record_missing',
            'trigger' => 'production_surface',
            'required_all' => [
                'README.md',
                'docs/releases/v2.1.md',
            ],
        ],
        [
            'key' => 'operational_reference',
            'reason_code' => 'operational_reference_missing',
            'trigger_prefixes' => [
                'app/Console/Commands/',
                'database/migrations/',
                'dev-ops/',
                'routes/',
                'scripts/',
            ],
            'trigger_exact' => [
                'backup.sh',
                'offline_dev.sh',
                'prod1.sh',
            ],
            'required_all' => [
                'docs/operation.md',
            ],
        ],
        [
            'key' => 'architecture_explanation',
            'reason_code' => 'architecture_explanation_missing',
            'trigger_prefixes' => [
                'app/Engine/',
                'app/Nodes/',
                'app/Services/',
                'config/',
                'database/migrations/',
                'mcp-server/src/',
                'mcp-servers/plos/src/',
            ],
            'required_all' => [
                'docs/architecture.md',
            ],
        ],
        [
            'key' => 'external_source_provenance',
            'reason_code' => 'external_source_provenance_missing',
            'trigger_exact' => [
                'config/whiteroom_projects.php',
            ],
            'required_all' => [
                'docs/research-provenance.md',
                'THIRD_PARTY.md',
            ],
        ],
    ],
];

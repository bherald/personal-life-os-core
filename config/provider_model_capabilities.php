<?php

return [
    'schema_version' => 'plos.provider_model_capabilities.v1',

    'structured_output' => [
        'model_capability_aliases' => [
            'structured_output',
            'structured_json',
            'json_schema',
        ],
        'allowed_transports' => [
            'openai_json_schema',
        ],
        'require_exact_model' => true,
        'require_local_validation' => true,
    ],
];

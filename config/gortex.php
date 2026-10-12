<?php

$runtime = json_decode(file_get_contents(base_path('resources/gortex/runtime.json')), true, flags: JSON_THROW_ON_ERROR);

return $runtime + [
    'enabled' => env('GORTEX_ENABLED', true),
    'schema_connections' => ['mysql', 'pgsql_rag'],
    'schema_directory' => storage_path('app/private/gortex/schemas'),
];

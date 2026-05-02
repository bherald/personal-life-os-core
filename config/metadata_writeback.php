<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Metadata Writeback Safety Gate
    |--------------------------------------------------------------------------
    |
    | Public installs must not silently modify original media files. Keep
    | writeback disabled until an operator has reviewed the risk, confirmed
    | backups/sidecar policy, and explicitly enabled in-place writes.
    */
    'enabled' => (bool) env('PLOS_METADATA_WRITEBACK_ENABLED', false),
    'in_place_enabled' => (bool) env('PLOS_METADATA_WRITEBACK_IN_PLACE', false),
];

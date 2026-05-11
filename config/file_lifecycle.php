<?php

return [
    // Deleted file tombstones must age this many days before hard purge.
    // Operators can still pass force=true for explicit recovery cleanup.
    'hard_purge_retention_days' => (int) env('FILE_HARD_PURGE_RETENTION_DAYS', 7),

    // File lifecycle reconciliation writeback stays disabled by default.
    // Apply mode also requires an explicit command confirmation token.
    'writeback_enabled' => env('FILE_LIFECYCLE_WRITEBACK_ENABLED', false),
];

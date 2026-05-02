<?php

return [
    // Deleted file tombstones must age this many days before hard purge.
    // Operators can still pass force=true for explicit recovery cleanup.
    'hard_purge_retention_days' => (int) env('FILE_HARD_PURGE_RETENTION_DAYS', 7),
];

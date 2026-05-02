<?php

return [
    /*
     * Minimum confidence required for a research-derived review item to
     * appear on the operator's review surface. Items scoring below this
     * threshold stay pending in the DB but are hidden from the mobile
     * web UI and /api/research-hub/items response until research raises
     * their confidence. NULL-confidence items (system alerts, non-scored
     * review types) are never filtered by this rule — they always show.
     *
     * Callers may override per-request via the `min_confidence` query
     * param. Pass 0 to disable filtering entirely for an admin-level view.
     */
    'min_confidence' => env('REVIEW_QUEUE_MIN_CONFIDENCE', 0.55),
];

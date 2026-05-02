<?php

/**
 * FactCheck pipeline limits (N82 SC-3 Config Promotion).
 */
return [
    'max_claims'   => env('FACTCHECK_MAX_CLAIMS', 50),
    'max_evidence' => env('FACTCHECK_MAX_EVIDENCE', 10),
    'query_count'  => env('FACTCHECK_QUERY_COUNT', 3),

    // FactCheckOpsService review flagging batch (N87)
    'flag_low_confidence_batch' => (int) env('FACTCHECK_FLAG_LOW_CONFIDENCE_BATCH', 20), // Low-confidence verdicts flagged per run
];

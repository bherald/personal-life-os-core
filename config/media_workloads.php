<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media workload admission
    |--------------------------------------------------------------------------
    |
    | High-write and high-derived-output media jobs can amplify storage pressure.
    | Default rollout is observe/warn only; set enforce=true after telemetry proves
    | the thresholds fit production.
    |
    */

    'admission' => [
        'enabled' => (bool) env('MEDIA_WORKLOAD_ADMISSION_ENABLED', true),
        'enforce' => (bool) env('MEDIA_WORKLOAD_ADMISSION_ENFORCE', false),
        'min_free_gb' => (float) env('MEDIA_WORKLOAD_MIN_FREE_GB', 8),
        'max_used_percent' => (float) env('MEDIA_WORKLOAD_MAX_USED_PERCENT', 92),

        'workloads' => [
            'file_thumbnails' => [
                'label' => 'File thumbnails',
                'path' => storage_path('app/thumbnails'),
                'min_free_gb' => (float) env('MEDIA_WORKLOAD_THUMBNAILS_MIN_FREE_GB', 10),
                'write_amplification_gb' => 2.0,
            ],
            'file_rag_backfill' => [
                'label' => 'File RAG backfill',
                'path' => storage_path('app'),
                'min_free_gb' => (float) env('MEDIA_WORKLOAD_RAG_MIN_FREE_GB', 8),
                'write_amplification_gb' => 1.0,
            ],
            'file_catalog_sync' => [
                'label' => 'File catalog sync',
                'path' => storage_path('app'),
                'min_free_gb' => (float) env('MEDIA_WORKLOAD_CATALOG_MIN_FREE_GB', 8),
                'write_amplification_gb' => 1.0,
            ],
            'genealogy_source_media_capture' => [
                'label' => 'Genealogy source media capture',
                'path' => storage_path('app/genealogy'),
                'min_free_gb' => (float) env('MEDIA_WORKLOAD_GENEALOGY_CAPTURE_MIN_FREE_GB', 12),
                'write_amplification_gb' => 4.0,
            ],
        ],
    ],
];

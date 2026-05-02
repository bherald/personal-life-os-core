<?php

/**
 * Health-gate configuration.
 *
 * `classifier_deviations` lists extensions that an individual service is
 * allowed to carry in its private *EXTENSIONS constant even though they
 * are absent from `config/file_types.*`. Use this for legitimate orphans
 * like ThumbnailService's dbf/cdx DBase handling — extensions that don't
 * fit any file-types class by design.
 *
 * Every deviation requires a comment explaining why it is not a drift.
 */
return [
    'classifier_deviations' => [
        // ThumbnailService handles legacy DBase files (dbf/cdx) via a
        // dedicated thumbnailer; these are not a general file-types category.
        \App\Services\ThumbnailService::class . '::SUPPORTED_DBF_EXTENSIONS' => ['dbf', 'cdx'],

        // BULK_TEXT_HEAVY_EXTENSIONS is the RAG service's "worth considering"
        // set, which is broader than rag_indexable. json and xml are marked
        // text-heavy for bulk triage but intentionally excluded from RAG
        // ingestion (see config/file_types.php rag_indexable comment —
        // binary-markup produces low-value, noisy RAG content).
        \App\Services\FileCategorizationRAGService::class . '::BULK_TEXT_HEAVY_EXTENSIONS' => ['json', 'xml'],
    ],
];

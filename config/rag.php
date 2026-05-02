<?php

/**
 * RAG pipeline operational knobs (N82 SC-3 Config Promotion).
 */
return [
    // ContentChunkingService chunk size thresholds (bytes)
    'chunking_min' => env('RAG_CHUNK_MIN', 2048),
    'chunking_avg' => env('RAG_CHUNK_AVG', 8192),
    'chunking_max' => env('RAG_CHUNK_MAX', 65536),

    // GraphSearchService traversal limits (N87)
    'graph_triple_walk_limit' => (int) env('RAG_GRAPH_TRIPLE_WALK_LIMIT', 100), // Max triples fetched per hop in graph traversal

    // EntityResolutionService embedding backfill batch/sleep
    'entity_embed_batch'   => env('ENTITY_EMBED_BATCH', 50),
    'entity_embed_sleep'   => env('ENTITY_EMBED_SLEEP', 1000),   // ms
    'entity_compare_batch' => env('ENTITY_COMPARE_BATCH', 20),
    'entity_compare_sleep' => env('ENTITY_COMPARE_SLEEP', 2000), // ms
];

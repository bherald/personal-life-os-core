<?php

/**
 * Agent Memory Configuration (SC-2.6)
 *
 * Central configuration for episodic and procedural memory systems.
 * Both AgentEpisodicMemoryService and AgentProceduralMemoryService
 * read from config() with local constant fallback.
 */

return [
    // Episodic memory (run-level narrative recall)
    'episodic' => [
        'semantic_min_similarity' => 0.30,
        'max_recall_summaries' => 2,
        'max_context_tokens' => 400,
        'recency_half_life_days' => 14,
        'default_retention_days' => 90,
    ],

    // Procedural memory (tool sequence patterns)
    'procedural' => [
        'min_sequence_length' => 2,
        'recall_min_success_rate' => 0.70,
        'recall_min_uses' => 2,
        'max_recall_procedures' => 3,
        'stale_days' => 30,
        'retire_threshold' => 0.30,
        'retire_min_uses' => 5,
        'merge_similarity_threshold' => 0.80,
        'semantic_min_similarity' => 0.35,
    ],
];

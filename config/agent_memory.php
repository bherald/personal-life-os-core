<?php

/**
 * Agent Memory Configuration (SC-2.6)
 *
 * Central configuration for episodic and procedural memory systems.
 * Both AgentEpisodicMemoryService and AgentProceduralMemoryService
 * read from config() with local constant fallback.
 */

return [
    // Content-free phase receipts; never stores task, memory, prompt, or output text.
    'telemetry' => [
        'enabled' => true,
        'report_max_hours' => 168,
        'report_max_rows' => 5000,
    ],

    // Episodic memory (run-level narrative recall)
    'episodic' => [
        'semantic_min_similarity' => 0.30,
        'max_recall_summaries' => 2,
        'max_context_tokens' => 400,
        'recency_half_life_days' => 14,
    ],

    // Multi-signal recall keeps objectives and durable failure lessons visible.
    'recall' => [
        'outcome_diversity_score_margin' => 0.12,
        'outcome_diversity_min_objective_match' => 0.60,
        'weights' => [
            'semantic' => 0.55,
            'objective' => 0.20,
            'importance' => 0.10,
            'recency' => 0.05,
            'gotcha' => 0.10,
        ],
    ],

    'gating' => [
        'recent_experience_days' => 14,
        'gotcha_min_importance' => 0.70,
        'high_frequency_run_threshold' => 10,
    ],

    // Similarity creates additive links; it does not prove supersession.
    'evolution' => [
        'link_min_similarity' => 0.40,
        'extends_threshold' => 0.75,
        'scan_limit' => 10,
        'max_links_per_distill' => 3,
        'max_linked_context' => 2,
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

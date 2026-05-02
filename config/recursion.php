<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RLM Recursion Framework Configuration
    |--------------------------------------------------------------------------
    |
    | Default thresholds for recursive task decomposition. Per-service overrides
    | are stored in the recursion_config DB table for runtime tunability.
    | Master kill switch is in system_configs (section=recursion, key=master_enabled).
    |
    */

    // Default budget limits (carved from parent)
    'default_max_depth' => 1,
    'default_max_tokens' => 30000,
    'default_max_time_seconds' => 300,
    'default_max_cost_usd' => 0.50,

    // MoveOnNudge defaults
    'default_novelty_threshold' => 0.15,
    'default_repetition_threshold' => 0.90,
    'default_decay_window' => 3,
    'default_move_on_mode' => 'graceful', // graceful | hard
    'default_max_consecutive_stalls' => 2,
    'time_budget_reserve_pct' => 0.30, // Reserve 30% of job timeout for synthesis

    // Model roles for sub-calls
    'sub_call_model_role' => 'fast',
    'synthesis_model_role' => 'quality',

    // Redis cache TTL for config lookups (seconds)
    'config_cache_ttl' => 60,

    // Strategy registry — available strategies
    'strategies' => [
        'partition_map',
        'hierarchical_summarize',
        'evidence_chase',
        'quality_gate_retry',
    ],

    // Budget carving — what fraction of parent budget to allocate
    'budget_fraction_tokens' => 0.60,
    'budget_fraction_time' => 0.70,
    'budget_fraction_cost' => 0.80,

    // Minimum parent budget to justify recursion
    'min_parent_tokens' => 5000,
    'min_parent_time_seconds' => 30,

    // Auto-decompose: transparent context shrinkage in AIService::process()
    'auto_decompose_threshold' => 8000,       // Token estimate threshold to trigger decomposition
    'auto_decompose_target_chunk' => 3000,    // Target tokens per sub-call chunk
    'auto_decompose_max_chunks' => 8,         // Max chunks to prevent explosion
    'auto_decompose_overlap_chars' => 200,    // Overlap between chunks for context continuity
    'auto_decompose_min_prompt_chars' => 500, // Min instruction prefix chars to preserve

    // Retention: purge agent_recursion_calls older than this (days).
    // Temporarily shortened to protect backup/runtime health until DBA review.
    'retention_days' => 3,
];

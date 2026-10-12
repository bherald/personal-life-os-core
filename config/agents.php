<?php

/**
 * Agent framework operational knobs (N82 SC-3 Config Promotion).
 * Override in .env or via env vars listed beside each key.
 */
return [
    // Source-controlled agent skill definitions.
    'skills_path' => env('AGENT_SKILLS_PATH', 'resources/agents/skills'),

    // Optional skill intake scanner. Static PLOS checks always run; the
    // SkillSpector CLI is used only when present and enabled.
    'skill_audit' => [
        'allowed_roots' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AGENT_SKILL_AUDIT_ALLOWED_ROOTS', ''))
        ))),
        'skillspector' => [
            'enabled' => (bool) env('SKILLSPECTOR_ENABLED', true),
            'command' => env('SKILLSPECTOR_COMMAND', 'skillspector'),
            'use_llm_default' => (bool) env('SKILLSPECTOR_USE_LLM_DEFAULT', false),
            'enforce_recommendation' => (bool) env('SKILLSPECTOR_ENFORCE_RECOMMENDATION', false),
        ],
    ],

    // Agent loop
    'max_loop_iterations' => env('AGENT_MAX_LOOP', 10),
    'context_max_tokens' => env('AGENT_CONTEXT_TOKENS', 4000),

    // Parallel tool execution
    'parallel_tool_max_concurrent' => env('PARALLEL_TOOL_MAX', 10),

    // Phase retries / context (N87)
    'phase_max_retries' => (int) env('AGENT_PHASE_MAX_RETRIES', 2),    // LLM re-prompt attempts on phase validation failure
    'chat_context_messages' => (int) env('AGENT_CHAT_CONTEXT_MESSAGES', 10), // Recent messages included in AI context per turn
    'assess_tool_result_max_chars' => (int) env('AGENT_ASSESS_TOOL_MAX_CHARS', 3000), // N143: Per-tool output cap in assess phase prompt (prevents context overflow on large trees)
    'tool_result_max_chars' => (int) env('AGENT_TOOL_RESULT_MAX_CHARS', 2000), // Cap per-turn tool result context in agentic mode to avoid oversized follow-up prompts

    // Lossy agent log precompaction is operator-disabled. This is intentionally
    // not env-overridable because collapsed log lines removed diagnostic detail
    // before the resulting prompt reached every provider in the fallback chain.
    'enable_log_precompaction' => false,
    'log_precompaction_threshold_bytes' => (int) env('AGENT_LOG_PRECOMPACTION_THRESHOLD', 1000),

    'ai_timeout_seconds' => (int) env('AGENT_AI_TIMEOUT_SECONDS', 45), // Bound each agent LLM call so scheduled runs fail fast instead of stalling for minutes

    // Safety guardrails (N87 — moved from hardcoded constants)
    'max_nesting_depth' => env('AGENT_MAX_NESTING', 1),
    'stuck_detection' => [
        'history_limit' => (int) env('AGENT_STUCK_HISTORY_LIMIT', 20),
        'canonical_json_max_bytes' => (int) env('AGENT_STUCK_CANONICAL_JSON_MAX_BYTES', 65536),
        'repeat_pair_replan_threshold' => (int) env('AGENT_STUCK_REPLAN_THRESHOLD', 3),
        'action_observation_threshold' => (int) env('AGENT_STUCK_ACTION_OBSERVATION_THRESHOLD', 4),
        'action_error_threshold' => (int) env('AGENT_STUCK_ACTION_ERROR_THRESHOLD', 3),
        'alternating_pattern_threshold' => (int) env('AGENT_STUCK_ALTERNATING_THRESHOLD', 6),
        'periodic_pattern_max_period' => (int) env('AGENT_STUCK_PERIODIC_MAX_PERIOD', 5),
        'periodic_pattern_repetitions' => (int) env('AGENT_STUCK_PERIODIC_REPETITIONS', 3),
    ],
    'review_expiry_days' => (int) env('AGENT_REVIEW_EXPIRY_DAYS', 7),
    'pushover_emergency_timeout_sec' => (int) env('AGENT_PUSHOVER_TIMEOUT', 3600),

    // Hybrid workflow entity cap (N87 — was hardcoded 0.70 / 2.5)
    'hybrid_overhead_fraction' => (float) env('AGENT_HYBRID_OVERHEAD', 0.70),
    'hybrid_minutes_per_entity' => (float) env('AGENT_HYBRID_MIN_PER_ENTITY', 15),
    'hybrid_entity_floor' => (int) env('AGENT_HYBRID_ENTITY_FLOOR', 3),
    'hybrid_entity_ceil' => (int) env('AGENT_HYBRID_ENTITY_CEIL', 20),
    'hybrid_default_timeout_minutes' => (int) env('AGENT_HYBRID_DEFAULT_TIMEOUT', 90),
    'hybrid_report_reserve_seconds' => (int) env('AGENT_REPORT_RESERVE_SEC', 300),  // Reserve 5min for report phase

    // Adaptive timeout — allows agents to extend deadline mid-run when productive
    'adaptive_timeout_max_minutes' => (int) env('AGENT_ADAPTIVE_TIMEOUT_MAX', 150),

    // HWR-003 DS/delegation governance marker defaults.
    'delegation_governance' => [
        'max_depth' => (int) env('AGENT_DELEGATION_MAX_DEPTH', 1),
        'max_sidecar_depth' => (int) env('AGENT_DELEGATION_MAX_SIDECAR_DEPTH', 0),
        'max_concurrent_sidecars' => (int) env('AGENT_DELEGATION_MAX_SIDECARS', 2),
        'runtime_budget_seconds' => (int) env('AGENT_DELEGATION_RUNTIME_BUDGET', 900),
        'max_runtime_budget_seconds' => (int) env('AGENT_DELEGATION_RUNTIME_BUDGET_MAX', 3600),
        'max_iterations' => (int) env('AGENT_DELEGATION_MAX_ITERATIONS', 8),
        'max_iterations_ceiling' => (int) env('AGENT_DELEGATION_MAX_ITERATIONS_CEILING', 20),
        'default_profile' => env('AGENT_DELEGATION_DEFAULT_PROFILE', 'sidecar_read_only'),
        'allowed_ds_lanes' => ['general_ds', 'agent_dev_ds', 'genea_ds'],
    ],

    // HWR-004 RAG/Joplin retrieved-context trust envelope.
    'retrieved_context_fencing' => [
        'enabled' => (bool) env('AGENT_RETRIEVED_CONTEXT_FENCING', true),
        'max_items' => (int) env('AGENT_RETRIEVED_CONTEXT_MAX_ITEMS', 3),
        'max_preview_chars' => (int) env('AGENT_RETRIEVED_CONTEXT_PREVIEW_CHARS', 500),
        'stale_preview_chars' => (int) env('AGENT_RETRIEVED_CONTEXT_STALE_PREVIEW_CHARS', 250),
        'memory_fragment_max_chars' => (int) env('AGENT_MEMORY_FRAGMENT_MAX_CHARS', 8000),
        'reconcile_events_enabled' => (bool) env('AGENT_CONTEXT_RECONCILE_EVENTS', true),
        'max_reconcile_events_per_run' => (int) env('AGENT_CONTEXT_RECONCILE_MAX_EVENTS', 25),
    ],

];

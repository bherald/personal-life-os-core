<?php

/**
 * Agent framework operational knobs (N82 SC-3 Config Promotion).
 * Override in .env or via env vars listed beside each key.
 */
return [
    // Source-controlled agent skill definitions.
    'skills_path' => env('AGENT_SKILLS_PATH', 'resources/agents/skills'),

    // Agent loop
    'max_loop_iterations' => env('AGENT_MAX_LOOP', 10),
    'context_max_tokens' => env('AGENT_CONTEXT_TOKENS', 4000),

    // Claude CLI concurrency
    'claude_default_max' => env('CLAUDE_DEFAULT_MAX', 10),
    'claude_absolute_max' => env('CLAUDE_ABSOLUTE_MAX', 20),
    'claude_min_concurrent' => env('CLAUDE_MIN_CONCURRENT', 3),
    'claude_ollama_fallback_min' => env('CLAUDE_OLLAMA_FALLBACK_MIN', 8),

    // Speculative execution
    'max_speculative_queue' => env('SPECULATIVE_QUEUE_DEPTH', 2),
    'speculative_variance_threshold' => env('SPECULATIVE_VARIANCE', 1.0),
    'speculative_min_uplift' => env('SPECULATIVE_MIN_UPLIFT', 10.0),

    // Parallel tool execution
    'parallel_tool_max_concurrent' => env('PARALLEL_TOOL_MAX', 10),

    // Phase retries / context (N87)
    'phase_max_retries' => (int) env('AGENT_PHASE_MAX_RETRIES', 2),    // LLM re-prompt attempts on phase validation failure
    'chat_context_messages' => (int) env('AGENT_CHAT_CONTEXT_MESSAGES', 10), // Recent messages included in AI context per turn
    'assess_tool_result_max_chars' => (int) env('AGENT_ASSESS_TOOL_MAX_CHARS', 3000), // N143: Per-tool output cap in assess phase prompt (prevents context overflow on large trees)
    'tool_result_max_chars' => (int) env('AGENT_TOOL_RESULT_MAX_CHARS', 2000), // Cap per-turn tool result context in agentic mode to avoid oversized follow-up prompts

    // Framework B7 — log pre-compaction for log-shaped tool results. Applies
    // to log_parse_errors / log_scan_errors / log_tail / log_read tool outputs
    // before truncation. Savings flow through AIService to every provider in
    // the fallback chain (Ollama local, Claude CLI, Groq, OpenRouter, etc.).
    // Disable via env AGENT_LOG_PRECOMPACTION=false if compaction ever damages signal.
    'enable_log_precompaction' => (bool) env('AGENT_LOG_PRECOMPACTION', true),
    'log_precompaction_threshold_bytes' => (int) env('AGENT_LOG_PRECOMPACTION_THRESHOLD', 1000),

    'ai_timeout_seconds' => (int) env('AGENT_AI_TIMEOUT_SECONDS', 45), // Bound each agent LLM call so scheduled runs fail fast instead of stalling for minutes

    // Safety guardrails (N87 — moved from hardcoded constants)
    'max_nesting_depth' => env('AGENT_MAX_NESTING', 5),
    'consecutive_tool_limit' => env('AGENT_CONSECUTIVE_TOOL_LIMIT', 3),
    'consecutive_tool_limit_alt' => env('AGENT_CONSECUTIVE_FALLBACK', 2),
    'auto_approve_confidence' => (float) env('AGENT_AUTO_APPROVE_CONFIDENCE', 1.00),
    'review_expiry_days' => (int) env('AGENT_REVIEW_EXPIRY_DAYS', 7),
    'pushover_emergency_timeout_sec' => (int) env('AGENT_PUSHOVER_TIMEOUT', 3600),

    // Hybrid workflow entity cap (N87 — was hardcoded 0.70 / 2.5)
    'hybrid_overhead_fraction' => (float) env('AGENT_HYBRID_OVERHEAD', 0.70),
    'hybrid_minutes_per_entity' => (float) env('AGENT_HYBRID_MIN_PER_ENTITY', 15),
    'hybrid_entity_floor' => (int) env('AGENT_HYBRID_ENTITY_FLOOR', 3),
    'hybrid_entity_ceil' => (int) env('AGENT_HYBRID_ENTITY_CEIL', 20),
    'hybrid_default_timeout' => (int) env('AGENT_HYBRID_DEFAULT_TIMEOUT', 90),
    'hybrid_report_reserve_seconds' => (int) env('AGENT_REPORT_RESERVE_SEC', 300),  // Reserve 5min for report phase

    // Adaptive timeout — allows agents to extend deadline mid-run when productive
    'adaptive_timeout_max_minutes' => (int) env('AGENT_ADAPTIVE_TIMEOUT_MAX', 150),

    // Claude CLI auto-scaling thresholds (N119c — per-core normalized load averages)
    'claude_load_scale_up' => (float) env('CLAUDE_LOAD_SCALE_UP', 1.5),    // Scale up if per-core load < this
    'claude_load_scale_down' => (float) env('CLAUDE_LOAD_SCALE_DOWN', 3.0),   // Scale down if per-core load > this
    'claude_load_critical' => (float) env('CLAUDE_LOAD_CRITICAL', 5.0),     // Emergency minimum slots if per-core load > this
    'claude_memory_min_free_mb' => (int) env('CLAUDE_MEMORY_MIN_FREE_MB', 512),
    'claude_memory_critical_mb' => (int) env('CLAUDE_MEMORY_CRITICAL_MB', 256),
    'claude_slots_per_core' => (int) env('CLAUDE_SLOTS_PER_CORE', 2),
];

<?php

/**
 * AI-1: Model Cascading configuration.
 *
 * When a cheap/local provider succeeds, CascadeQualityEvaluator scores the
 * response. If the score falls below the threshold the request is re-run on
 * the next provider in the fallback chain — transparently to callers.
 */
return [
    // Master switch — set CASCADE_ENABLED=false to disable globally
    'enabled' => env('CASCADE_ENABLED', true),

    // Quality score below this triggers escalation (0.0–1.0)
    'default_threshold' => (float) env('CASCADE_THRESHOLD', 0.50),

    // Minimum acceptable character length for a non-trivial response
    'min_response_length' => (int) env('CASCADE_MIN_LENGTH', 80),

    // Self-assessment: ask the same local model to score its own response.
    // Adds ~1–2s latency. Disabled by default; enable per-agent in SKILL.md.
    'self_assess_enabled' => (bool) env('CASCADE_SELF_ASSESS', false),
    'self_assess_timeout' => (int) env('CASCADE_SELF_ASSESS_TIMEOUT', 10),

    // Never cascade more than once per request (prevents infinite escalation)
    'max_cascades_per_request' => 1,

    // Signal weights — must sum to 1.0 when all signals are active.
    // json_validity only fires when the prompt expects JSON; otherwise its
    // weight is redistributed proportionally to the remaining signals.
    'weights' => [
        'length'       => 0.20,
        'json_validity' => 0.30,
        'refusal'      => 0.25,
        'repetition'   => 0.10,
        'self_assess'  => 0.15,
    ],

    // Roles that skip cascading — they already target a strong model
    'skip_roles' => ['quality'],

    // Config keys that skip cascading — caller has already routed intentionally
    'skip_if_set' => ['prefer_claude', 'prefer_external'],
];

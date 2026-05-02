<?php

/**
 * AI-12: Externalized AI token/context limits.
 *
 * Previously hardcoded as constants across AIRouter, AgentLoopService,
 * ScheduledJobService, and other services. Now configurable via .env.
 */
return [

    // Max output tokens for text generation (AIRouter default)
    'default_max_tokens' => (int) env('AI_DEFAULT_MAX_TOKENS', 4096),

    // Max input characters for embedding (nomic-embed-text 8192 tokens ~ 30K chars)
    'embedding_char_limit' => (int) env('AI_EMBEDDING_CHAR_LIMIT', 30000),

    // Agent response cap (AgentLoopService truncation)
    'agent_response_max_chars' => (int) env('AI_AGENT_RESPONSE_MAX_CHARS', 16000),

    // Scheduled job output truncation
    'job_output_max_chars' => (int) env('AI_JOB_OUTPUT_MAX_CHARS', 16384),

    // Semantic cache settings (also in config/services.php ai section)
    'cache_enabled' => env('AI_CACHE_ENABLED', true),
    'cache_similarity' => (float) env('AI_CACHE_SIMILARITY', 0.85),
    'cache_ttl' => (int) env('AI_CACHE_TTL', 86400),
];

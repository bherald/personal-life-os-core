<?php

/**
 * Lock TTL Configuration (SC-2.3)
 *
 * Central registry for all Redis/cache lock TTLs.
 * Services read from config() with local constant fallback.
 */

return [
    // Agent session lock — prevents concurrent runs of same agent
    'agent_session' => (int) env('LOCK_TTL_AGENT_SESSION', 600), // 10 min

    // Ollama busy lock — single-GPU mutual exclusion
    'ollama_busy' => (int) env('LOCK_TTL_OLLAMA_BUSY', 90), // 1.5 min (was 150s — most requests complete in <30s)

    // Face detection batch lock — prevents concurrent heavy batches
    'face_batch' => (int) env('LOCK_TTL_FACE_BATCH', 600), // 10 min

    // Whisper GPU lock — mutual exclusion with Ollama
    'whisper_gpu' => (int) env('LOCK_TTL_WHISPER_GPU', 900), // 15 min

    // Claude CLI slot TTL — max duration per slot
    'claude_slot' => (int) env('LOCK_TTL_CLAUDE_SLOT', 180), // 3 min

    // Compute router GPU lock — mutual exclusion per GPU instance
    'compute_gpu' => (int) env('LOCK_TTL_COMPUTE_GPU', 300), // 5 min

    // Compute router CPU lock — per-slot for CPU-only tasks
    'compute_cpu' => (int) env('LOCK_TTL_COMPUTE_CPU', 120), // 2 min
];

<?php

/**
 * 3j P04 — TrustBoundaryFormatterService control plane configuration.
 *
 * This file is the single surface an operator can use to turn off the trust
 * envelope without a deploy. Two modes:
 *
 *   enabled = true   (default)
 *     Formatter wraps every payload with sentinels, neutralizes injection
 *     openers, and truncates at max_chars.
 *
 *   enabled = false  (kill switch)
 *     Formatter becomes a deterministic pass-through: returns the raw
 *     payload unchanged. No sentinel wrap, no injection neutralization,
 *     no truncation. Used when operator needs to inspect payload behavior
 *     without the envelope layer in the way, or when an unexpected
 *     formatter regression blocks legitimate traffic.
 *
 * Toggling this flag is a conscious operator decision — there is no
 * automatic transition. Audit the change via `system_configs`-based
 * override if needed (config cache must be rebuilt after edits).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Master enable flag
    |--------------------------------------------------------------------------
    |
    | True (default) — formatter applies the full envelope + neutralization.
    | False          — formatter returns the raw payload unchanged (bypass).
    |
    | Env override: TRUST_BOUNDARY_ENABLED=false at the .env layer disables
    | the formatter without a code change.
    |
    */

    'enabled' => env('TRUST_BOUNDARY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Per-seam overrides
    |--------------------------------------------------------------------------
    |
    | Allow narrow bypass for a specific ingress seam (genealogy document
    | parsing, OCR result, RAG tool output, etc.) without disabling the
    | whole formatter. A seam name appearing in this list makes
    | TrustBoundaryFormatterService::format() skip neutralization for that
    | seam only. Empty list = global flag governs everything.
    |
    | Intended use: diagnose a formatter over-sanitization regression on one
    | surface without widening for all surfaces.
    |
    */

    'bypass_seams' => [
        // 'genealogy.document',
        // 'ocr.content_extraction',
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | When true, the formatter logs an info line on every invocation with
    | seam + whether a neutralization fired. Off by default to avoid log
    | volume; turn on during incident response.
    |
    */

    'log_invocations' => env('TRUST_BOUNDARY_LOG', false),

];

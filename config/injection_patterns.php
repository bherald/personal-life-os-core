<?php

/**
 * Unified injection-pattern corpus for the trust-boundary and guardrail
 * paths. Single source of truth for the regexes that detect or
 * neutralize prompt-injection attempts across the PLOS ingress surface.
 *
 * Consumed by:
 *   - App\Services\TrustBoundaryFormatterService::neutralizeInjectionOpeners
 *     — preg_replace each regex with the marker
 *     `[neutralized-injection-opener]`
 *   - App\Services\AgentGuardrailService::detectContentContamination
 *     — preg_match each regex; label surfaces as a threat
 *   - App\Services\AgentGuardrailService::sanitizeUntrustedText
 *     — preg_replace each regex with `[REDACTED_UNTRUSTED_INSTRUCTION]`
 *
 * Slice D (2026-04-18): closes the silent-divergence gap where the two
 * services held parallel corpora of different shapes. Adding a new
 * attack pattern here now covers both services simultaneously;
 * dropping or narrowing one must be a conscious edit, not accidental
 * drift.
 *
 * Pattern key: the PCRE regex string (delimiters + flags).
 * Pattern value: the human-readable label surfaced by the guardrail
 * detector and used for audit logging.
 *
 * Order is load-bearing. `preg_replace($patterns, ...)` applies each
 * pattern sequentially against the progressively-replaced subject; the
 * opener-literals alternation lives last so it never fires on a region
 * that a more specific verb-form pattern has already replaced.
 */

return [
    'patterns' => [
        // Verb-form instruction override:
        // "Ignore/disregard/forget all previous instructions/rules/prompts"
        '/\b(ignore|disregard|forget)\s+(all\s+)?(previous|prior|above)\s+(instructions?|rules?|prompts?)/i'
            => 'Instruction override attempt',

        // Role reassignment:
        // "you are now …", "act as …", "pretend to be …", "your new role/instructions"
        '/\b(you\s+are\s+now|act\s+as|pretend\s+to\s+be|your\s+new\s+(role|instructions?))\b/i'
            => 'Role reassignment attempt',

        // System prompt injection: "system prompt: …" / "system prompt = …"
        '/\bsystem\s*prompt\s*[:=]/i'
            => 'System prompt injection',

        // Tool-call injection:
        // "call/execute/run/invoke tool/function/command:" (or `=`/`(`)
        '/\b(call|execute|run|invoke)\s+(tool|function|command)\s*[:=\(]/i'
            => 'Tool-call injection attempt',

        // Chat template injection: "[INST]" or "<|im_start|"
        '/\[\s*INST\s*\]|\<\|\s*im_start\s*\|/i'
            => 'Chat template injection',

        // Output format hijacking:
        // "output/return/respond with only/exactly/just \"…\""
        '/\b(output|return|respond\s+with)\s+(only|exactly|just)\s+["\']/i'
            => 'Output format hijacking',

        // Injection-opener literals (historical TrustBoundaryFormatter
        // corpus): openers at line-start or after whitespace. Kept as a
        // single alternation to preserve original match spans for
        // payloads that already triggered this path.
        '/(^|(?<=\s))(ignore previous instructions|ignore all previous|disregard the above|you are now|system:|assistant:|##SYSTEM|<\|system\|>)/imu'
            => 'Injection opener literal',
    ],
];

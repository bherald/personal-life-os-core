<?php

/**
 * Runtime surface inventory consumed by `OpsRuntimeManifestCommand`
 * (section: seams). Descriptive only — this registry records what each
 * surface is and its current implementation status. Policy semantics
 * (severity, action-on-detect, ingress trust level) are NOT defined
 * here; trust-boundary policy lives in the trust-boundary formatter
 * call sites, and the broader trust-boundary seam registry is a
 * separate planned effort.
 *
 * Ordering is load-bearing: the manifest preserves this exact sequence
 * so drift between source-of-truth and manifest output is detectable.
 * Do not reorder rows without an explicit reason.
 *
 * Row shape: {name, status, notes}
 *   status ∈ {real, mixed, placeholder-like}
 *
 * Adding a row: append to `rows`. Updating a status: edit in place.
 * Both require a human decision; there is no auto-discovery here.
 */

return [
    'rows' => [
        ['name' => 'AIService', 'status' => 'real', 'notes' => 'Provider chain with circuit-breaker + fallback'],
        ['name' => 'AIRouter', 'status' => 'real', 'notes' => 'Ollama/Claude CLI/external calls; token calibration logging live'],
        ['name' => 'LLMPoolManagerService', 'status' => 'real', 'notes' => 'Score-based selectInstance + routability + profile gates'],
        ['name' => 'ComputeRouterService', 'status' => 'real', 'notes' => 'Dynamic GPU/CPU routing with circuit state'],
        ['name' => 'AgentLoopService', 'status' => 'real', 'notes' => 'Hybrid/agentic/deterministic modes live'],
        ['name' => 'ScheduledJobService', 'status' => 'mixed', 'notes' => 'Runs via schedule:run; lease semantics pending B6 implementation'],
        ['name' => 'TrustBoundaryFormatterService', 'status' => 'real', 'notes' => 'Narrow wire-up across 6 ingress seams'],
        ['name' => 'SkillLoaderService', 'status' => 'real', 'notes' => 'YAML parser + typed metadata + protected write_scope gate'],
        ['name' => 'RecursiveCallService', 'status' => 'real', 'notes' => 'RLM framework with master kill-switch and per-service opt-in'],
        ['name' => 'TaskCustodyRecord (B6)', 'status' => 'placeholder-like', 'notes' => 'Design doc only (commit b98343e6a); no code yet'],
        ['name' => 'OpsRuntimeManifest (B3)', 'status' => 'real', 'notes' => 'This command'],
        ['name' => 'OpsRuntimeDiagnostics (B8)', 'status' => 'mixed', 'notes' => 'Pending; complement to this report'],
    ],
];

# AIService LLM Gateway

`App\Services\AIService` is the application-wide gateway for text, vision,
embedding, tool-calling, and bounded external-provider requests. Application
code should use `AIService` instead of calling model runtimes directly.

## Routing

Normal text routing is local-first:

1. Resolve the requested model role and privacy profile.
2. Try healthy, table-admitted Ollama instances in score order.
3. Try allowed OpenAI-compatible `local_llm` sidecars and external
   `llm_instances` rows in policy and priority order. Runtime identities,
   endpoints, roles, and explicit/no-fallback constraints come from tables;
   application code does not encode a named replacement path.
4. Report an all-provider failure unless the caller suppresses alerts because
   it owns a higher-level fallback.

`required_instance_id` selects one admitted OpenAI-compatible provider, whether
local or external. `prefer_codex` and `ai_mode=codex_exec` select the bounded,
table-backed Codex Exec route. `prefer_external` requests an admitted
non-Ollama table provider before Ollama. None of these options bypasses offline,
privacy, routability, health, model,
structured-output, or approval-policy gates.

Vision requests try eligible Ollama vision instances and then admitted
OpenAI-compatible vision providers, including `local_llm` sidecars. Sensitive
images stay local unless the caller explicitly classifies them for a provider
whose table record permits that data class.

## Primary APIs

```php
$result = app(\App\Services\AIService::class)->process($prompt, [
    'model_role' => 'standard',
    'sensitive_data' => true,
]);

$vision = app(\App\Services\AIService::class)->processImage(
    $imageBytes,
    'Describe the evidence visible in this image.',
    ['sensitive_data' => true]
);
```

Use `processWithTools()` only when the selected Ollama model has native tool
capability and the caller supplies an allowed MCP catalog. Use
`generateEmbedding()` for local embeddings and `extractContent()` for the
Tika, vision, OCR, and transcription pipeline.

## Provider Configuration

Provider activation, role maps, capabilities, privacy posture, routing status,
cost metadata, and health live in `llm_instances`. Runtime policy that is not a
provider secret lives in `system_configs`. Environment variables hold only
bootstrap values and secret references.

The retired Claude CLI and agent-proxy routes are not application providers.
Migration `2026_09_02_180000_retire_claude_runtime_routes` disables legacy rows;
restoring one requires a new reviewed migration and implementation.

## Reliability Controls

- Cache-based and database-backed circuit breakers prevent retry storms.
- Provider-specific retry policy uses bounded exponential backoff.
- Ollama locks protect constrained GPUs and stale locks are cleaned by
  maintenance jobs.
- Semantic cache and in-flight deduplication reduce repeated inference.
- Content-free route traces record provider order and outcomes.
- Offline mode fails closed and keeps LAN services available.

Operational checks:

```bash
php artisan ai:test
php artisan ai:test --instance=<llm_instances.instance_id>
php artisan ollama:offline-mode status
php artisan ops:llm-portfolio-review --json --compact
```

For the full current offline tool inventory, use the repository-mandated
`./offline_dev.sh --pipeline-tools` surface rather than copying an inventory
into this document.

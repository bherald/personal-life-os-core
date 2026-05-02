# AIService - Production-Grade LLM Gateway

**Version:** 2.7
**Updated:** March 3, 2026
**Status:** Production Ready

## What Is This?

The AIService is your project's unified AI interface. Instead of calling Ollama or Claude directly throughout the codebase, everything goes through AIService which handles:

- **Automatic retries** with smart backoff when Ollama is slow
- **Exception-typed retries** using LiteLLM-style exception hierarchy
- **Circuit breakers** to prevent hammering a failing service
- **Fallback chain** from local providers to optional external providers and operator notification
- **Health monitoring** so you know what's working
- **Ollama busy lock** to prevent concurrent single-GPU requests
- **Claude slot management** for parallel Claude CLI calls
- **Auto-scaling** based on CPU load and memory availability

## Quick Start

```php
use App\Services\AIService;

$aiService = app(AIService::class);

// Simple text processing
$result = $aiService->process('Summarize this text: ...');

if ($result['success']) {
    echo $result['response'];
    echo "Provider: " . $result['provider'];  // e.g., "ollama_primary"
    echo "Time: " . $result['duration_ms'] . "ms";
}
```

## Provider Priority Chain

Provider chain is DB-driven via `llm_instances` table, priority-ordered. Two chains depending on task type:

### Text Processing (`process()`)
```
1. Ollama Primary (configured local endpoint)
   ↓ Skip if busy (single GPU lock)
2. Ollama Secondary (optional configured local endpoint)
   ↓ (3 retries with backoff)
3. External APIs (priority-ordered from DB)
   ↓ SambaNova → Cerebras → Groq → OpenRouter
   ↓ Rate limited per-provider, circuit breakers
   ↓ sensitive_safe flag controls personal data routing
4. Claude CLI (optional paid/local CLI route)
   ↓ Parallel slots (up to 20 concurrent)
5. Operator notification (notifies of total failure)
```

### Vision/Image Analysis (`processImage()`)
```
1. Ollama Primary + Secondary (llava:7b)
   ↓ skip_if_busy=true — never waits
2. External Vision APIs (filtered by vision capability)
   ↓ OpenRouter (google/gemma-3-27b-it:free)
   ↓ Gemini, Mistral available when API keys configured
3. Claude CLI Vision (multimodal)
   ↓ Parallel slots
4. Operator notification (notifies of total failure)
```

### Privacy Rules
- `sensitive_safe=false` providers (OpenRouter, Gemini, Mistral) skipped when `sensitive_data=true`
- Gemini and Mistral: `vision=false` in DB — never receive images (training risk)
- Personal photos default to Ollama → Claude CLI path
- `php artisan ops:audit-privacy-routing --strict --json` provides a read-only coexistence audit for enabled sensitive tools, active routing profile, `routing.offline_mode`, and reachable provider classes.

## Sprint A Local-First Routing Direction

This gateway doc describes the production provider chain. Sprint A adds a more explicit
local-first operating intent for bounded work.

Preferred local-first categories:

- extraction to strict JSON
- search-log compression
- OCR/post-OCR cleanup
- source triage
- wrong-subject detection
- review-summary cleanup

Escalate only when needed:

- complex identity resolution
- ambiguous genealogy reasoning
- long-form synthesis from weak evidence
- final judgment tasks where false positives are expensive

Design rule:

- use local Ollama for bounded worker tasks
- use deterministic PHP validation around model output
- avoid letting local freeform prose directly drive structured FT mutations

## Ollama Busy Lock (New in v2.0)

Since Ollama runs on a single GPU, concurrent requests cause performance degradation. AIService now implements a busy lock:

```php
// AIService automatically handles this
// When Ollama is processing a request, other requests skip to Claude

// Check if Ollama is busy
$aiService->isOllamaBusy();  // true/false

// Get busy lock info
$aiService->getOllamaBusyInfo();
// Returns: ['locked_at' => '...', 'request_id' => '...', 'expires_at' => '...']
```

**Configuration:**
- Lock TTL: 5 minutes (auto-releases if request crashes)
- Cache key: `ollama_busy_lock`

## Claude Slot Management (New in v2.0)

Claude CLI can handle parallel requests. AIService manages slots:

```php
// Check slot availability
$aiService->hasClaudeSlotAvailable();  // true/false

// Get detailed slot info
$aiService->getClaudeSlotUsage();
// Returns:
// [
//     'active' => 2,
//     'max' => 20,              // Dynamic based on resources
//     'default_max' => 7,
//     'absolute_max' => 20,
//     'available' => 18,
//     'slots' => [...],
//     'system_load' => [...],
//     'ollama_healthy' => true,
//     'scaling_mode' => 'normal'  // or 'ollama_fallback'
// ]
```

**Configuration:**
- Min slots: 1
- Default max: 7
- Absolute max: 20
- Slots per core: 2 (dynamic scaling baseline)
- Slot TTL: 10 minutes

## Auto-Scaling (Updated v2.2)

Claude max slots dynamically scale based on CPU cores and system resources:

```php
// Get current dynamic max slots
$aiService->getDynamicClaudeMaxSlots();  // 1-20 (based on resources)

// Get system load metrics
$aiService->getSystemLoad();
// Returns:
// [
//     'load_1m' => 0.5,
//     'load_5m' => 0.8,
//     'load_15m' => 0.6,
//     'memory_free_mb' => 8000,
//     'memory_total_mb' => 16000,
//     'memory_used_percent' => 50,
//     'cpu_count' => 8
// ]
```

**Resource-Based Scaling Formula:**
```
max_slots = min(ABSOLUTE_MAX, cpu_count * SLOTS_PER_CORE)
Example: 12 cores × 2 slots/core = 24, capped at 20 → 20 slots
```

**Scaling Rules:**
| Condition | Action |
|-----------|--------|
| Excellent resources (load < 1.5, memory > 3GB) | Full resource-based max (up to 20) |
| Good resources (load < 3.0, memory > 2GB) | 80% of resource-based max |
| High load (> 3.0 per core) | Scale down to ~33% of max |
| Low memory (< 1GB free) | Scale down to ~33% of max |
| Critical (< 512MB or load > 10) | Emergency minimum (1 slot) |
| **Ollama down** | Scale UP aggressively (up to absolute max) |

## How Retry Works

When a provider fails, AIService uses **exception-typed backoff** (v2.1):

### Exception Hierarchy (LiteLLM-style)

AIService classifies errors into typed exceptions that determine retry behavior:

**Transient Exceptions (retryable):**
| Exception | Backoff | Description |
|-----------|---------|-------------|
| `TimeoutException` | 2000ms | Request timed out |
| `ConnectionException` | 1000ms | Connection failed |
| `ServerOverloadException` | 5000ms | Server overloaded (503) |
| `BusyException` | 500ms | Model busy, skip to fallback |
| `RateLimitException` | 30000ms | Rate limited (429), uses Retry-After header |

**Permanent Exceptions (non-retryable):**
| Exception | Description |
|-----------|-------------|
| `ValidationException` | Invalid request parameters |
| `AuthenticationException` | Auth failed (401/403) |
| `ContentPolicyException` | Content blocked |
| `ContextLengthException` | Token limit exceeded |
| `ModelNotFoundException` | Model not found |

### Exception Classification

```php
use App\Exceptions\AI\AIExceptionFactory;

// From HTTP response
$exception = AIExceptionFactory::fromHttpResponse($response, 'ollama', 'llama3.1');

// From error message
$exception = AIExceptionFactory::fromMessage('connection refused', 'ollama');

// Check retry behavior
$exception->isRetryable();         // true/false
$exception->getSuggestedBackoffMs(); // e.g., 2000
```

### Retry Logic

```
1. Try provider
2. On failure, classify exception:
   - If PermanentException → stop, move to next provider
   - If TransientException → wait getSuggestedBackoffMs(), retry
3. After 3 transient failures → move to next provider
```

The jitter (random variation) prevents "thundering herd" where all retries happen at exactly the same moment.

## How Circuit Breaker Works

If a provider keeps failing, AIService "opens the circuit" to stop wasting time:

```
CLOSED (normal)
    ↓ 3 consecutive failures
OPEN (skip this provider for 60 seconds)
    ↓ 60 seconds pass
HALF-OPEN (try 1 test request)
    ↓ success?
CLOSED (back to normal)
```

This prevents your app from waiting 30 seconds for a dead Ollama server on every request.

## Smart Timeouts

AIService knows Ollama's quirks. Timeouts adjust based on model state:

| Model State | Timeout | Why |
|-------------|---------|-----|
| Loaded in VRAM | 90s | Model is ready, just processing |
| Loading from disk | 180s | Cold start, loading weights |
| Model swap needed | 240s | Unload current model + load new one |

## Available Methods

### `process(string $prompt, array $config = []): array`
Main text processing. This is what you'll use 90% of the time.

```php
$result = $aiService->process('Explain quantum computing', [
    'model' => 'llama3.1:8b-instruct-q5_K_M',  // optional
    'skip_ollama_if_busy' => false,  // optional: skip to Claude if Ollama busy
]);
```

### `processImage(string $imagePath, string $prompt, array $config = []): array`
Vision processing using LLaVA (Ollama) or Claude CLI.

```php
$result = $aiService->processImage('/path/to/photo.jpg', 'Describe this image');
// Automatically skips Ollama if busy, uses Claude CLI slot management
```

### `generateEmbedding(string $content): array`
Create text embeddings for semantic search.

```php
$result = $aiService->generateEmbedding('Some text to embed');
if ($result['success']) {
    $vector = $result['embedding'];  // array of floats
}
```

### `processWithTools(string $prompt, array $config = [], int $maxIterations = 5): array`
AI with MCP tool access (web search, file access, etc).

### `getHealthStats(): array`
Check status of all providers. Great for monitoring dashboards.

```php
$health = $aiService->getHealthStats();
// Shows: availability, circuit states, success rates, VRAM usage
```

### `resetCircuit(string $providerId): void`
Manually reset a circuit if you know a service is back online.

```php
$aiService->resetCircuit('ollama_primary');
```

### Concurrency Methods (New in v2.0)

```php
// Ollama busy detection
$aiService->isOllamaBusy(): bool
$aiService->getOllamaBusyInfo(): ?array

// Claude slot management
$aiService->hasClaudeSlotAvailable(): bool
$aiService->getClaudeSlotUsage(): array
$aiService->getDynamicClaudeMaxSlots(): int

// System monitoring
$aiService->getSystemLoad(): array
```

## Return Value Structure

All methods return a consistent array:

```php
[
    'success' => true|false,
    'response' => 'The AI response text',  // null on failure
    'provider' => 'ollama_primary',         // which provider succeeded
    'model' => 'llama3.1:8b-instruct-q5_K_M',
    'duration_ms' => 2500,
    'error' => null,                        // error message on failure
    'attempts' => [...],                    // details of failed attempts
]
```

## Configuration

Settings in `config/services.php`:

```php
'ollama' => [
    'api_url' => env('OLLAMA_API_URL', 'http://127.0.0.1:11434'),
    'model' => env('OLLAMA_MODEL', 'llama3.1:8b-instruct-q5_K_M'),
    'vision_model' => env('OLLAMA_VISION_MODEL', 'llava:7b'),
    'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    'secondary_urls' => [],  // Add more Ollama instances here
],
```

### Concurrency Constants (in AIService.php)

```php
// Ollama busy detection
private const OLLAMA_BUSY_LOCK_TTL = 300;     // 5 min max lock
private const OLLAMA_BUSY_CACHE_KEY = 'ollama_busy_lock';

// Claude CLI concurrency (dynamic scaling)
private const CLAUDE_MIN_CONCURRENT = 1;       // Minimum slots
private const CLAUDE_DEFAULT_MAX = 7;          // Default when resources normal
private const CLAUDE_ABSOLUTE_MAX = 20;        // Hard ceiling
private const CLAUDE_OLLAMA_FALLBACK_MIN = 5;  // Min when Ollama down
private const CLAUDE_SLOTS_PER_CORE = 2;       // Slots per CPU core
private const CLAUDE_SLOT_TTL = 600;           // 10 min max slot
private const CLAUDE_SLOTS_CACHE_KEY = 'claude_cli_slots';

// Auto-scaling thresholds
private const LOAD_THRESHOLD_SCALE_DOWN = 3.0;
private const LOAD_THRESHOLD_SCALE_UP = 1.5;
private const LOAD_THRESHOLD_CRITICAL = 10.0;
private const MEMORY_MIN_FREE_MB = 1024;
private const MEMORY_CRITICAL_MB = 512;
```

## Adding a Second Ollama Instance

If you set up another Ollama server:

```php
// config/services.php
'ollama' => [
    'api_url' => 'http://127.0.0.1:11434',
    'secondary_urls' => [
        'http://127.0.0.2:11434',  // Optional second local endpoint
    ],
],
```

AIService will automatically use both, preferring the healthier one.

## Monitoring & Debugging

### Check Health Status
```bash
php artisan tinker --execute="
use App\Services\AIService;
echo json_encode(app(AIService::class)->getHealthStats(), JSON_PRETTY_PRINT);
"
```

### Check Concurrency Status (New)
```bash
php artisan tinker --execute="
\$ai = app(App\Services\AIService::class);
echo 'System Load: ' . json_encode(\$ai->getSystemLoad()) . PHP_EOL;
echo 'Ollama Busy: ' . (\$ai->isOllamaBusy() ? 'Yes' : 'No') . PHP_EOL;
echo 'Claude Slots: ' . json_encode(\$ai->getClaudeSlotUsage()) . PHP_EOL;
echo 'Dynamic Max: ' . \$ai->getDynamicClaudeMaxSlots() . PHP_EOL;
"
```

### Test Vision Failover
```bash
php artisan tinker --execute="
\$ai = app(App\Services\AIService::class);
\$result = \$ai->processImage('/path/to/image.jpg', 'Describe this image');
var_dump(\$result);
"
```

### View Logs
AIService logs to Laravel's default log channel:
- Retry attempts
- Circuit state changes
- Provider failures
- Slot acquisition/release

```bash
tail -f storage/logs/laravel.log | grep AIService
```

## Failure Alerts

When all configured providers fail, AIService sends an operator notification:

```
Title: AI Service Failure
Body:
  ollama_primary: Connection timeout
  claude_cli: API rate limit exceeded

  Prompt: [first 200 chars of your prompt]
```

This uses the configured notification channel for the install.

## Vision Provider Details

### Ollama Vision (LLaVA)
- Uses `llava:7b` model on both primary and secondary instances
- Requires base64-encoded image data
- Subject to busy lock (single GPU), `skip_if_busy=true` by default

### External Vision APIs (v2.3)
- OpenAI-compatible vision format via `AIRouter::callOpenAICompatibleVision()`
- Base64 image sent as `image_url` content type in chat messages
- `getVisionCapableProviders()` filters by `vision` capability flag in DB
- Currently active: OpenRouter (`google/gemma-3-27b-it:free`)
- Rate limited per-provider, circuit breakers per-endpoint
- Privacy: `sensitive_safe=false` providers skipped when `sensitive_data=true`
- Gemini/Mistral have `vision=false` in DB — never receive images

### Claude CLI Vision
- Uses Claude Code CLI with image file path
- Image path included in prompt, piped via stdin
- Supports parallel processing via slot management (up to 20 concurrent)

## External API Providers (v2.3)

Provider configuration is DB-driven via `llm_instances` table. All OpenAI-compatible providers use `AIRouter::callOpenAICompatible()`.

| Provider | Type | Vision | sensitive_safe | Status |
|----------|------|--------|----------------|--------|
| SambaNova | custom | No | Yes | Active |
| Cerebras | custom | No | Yes | Active |
| Groq | custom | No | Yes | Active |
| OpenRouter | custom | Yes | No | Active |
| Gemini | google_gemini | No* | No | Inactive (no key) |
| Mistral | custom | No* | No | Inactive (no key) |

\* Vision disabled in DB to prevent personal photo training

### Adding a New Provider
1. Insert row in `llm_instances` with `base_url`, `api_key`, `priority`, `capabilities` JSON
2. Set `is_active=1`, `is_healthy=1`
3. Add `config.models` JSON with role mappings (see Role-Based Model Selection below)
4. Clear cache: `Cache::forget('external_api_providers')`
5. Provider auto-joins fallback chain on next request

## Role-Based Model Selection (v2.7)

Callers request a **capability level** (role), not a specific model name. This makes model upgrades a DB change with zero code deploys.

### Roles

| Role | Purpose | Claude CLI | Groq |
|------|---------|-----------|------|
| `standard` | Agents, general processing (default) | sonnet | llama-3.3-70b-versatile |
| `fast` | Tagging, classification, short completions | haiku | llama-3.1-8b-instant |
| `quality` | Research synthesis, complex reasoning | opus | llama-3.3-70b-versatile |
| `vision` | Image analysis | sonnet | — |

### Using Roles

```php
// Default (standard role — no change needed for existing callers)
$result = $aiService->process($prompt);

// Fast role — cheaper, lower latency
$result = $aiService->process($prompt, ['model_role' => 'fast']);

// Quality role — best reasoning, used by claudeWebResearch() automatically
$result = $aiService->process($prompt, ['model_role' => 'quality']);
```

### Resolution Chain

`AIService::resolveModelForProvider(instanceId, role)` resolves in order:
1. `llm_instances.config.models.{role}` — explicit role mapping
2. `llm_instances.config.models.standard` — standard model as fallback
3. `llm_instances.config.default_model` — legacy default
4. `null` — provider uses its own default (no `--model` flag appended)

Results are cached per-request in `$providerModelCache` to avoid repeated DB hits.

### Claude CLI Model Flag

Before this change, Claude CLI always ran with no `--model` flag, consuming the Opus quota by default. Now:
- `tryClaudeCLI()` resolves model via role → appends `--model {model}` to the CLI command
- `claudeWebResearch()` uses `quality` role by default (sonnet, not opus)
- Vision path resolves `vision` role → uses sonnet for image analysis
- `streamingFallbackToClaude()` passes model through config

### DB Configuration

Models are stored in `llm_instances.config` JSON:

```json
{
  "default_model": "llama-3.3-70b-versatile",
  "models": {
    "standard": "llama-3.3-70b-versatile",
    "fast": "llama-3.1-8b-instant",
    "quality": "llama-3.3-70b-versatile"
  }
}
```

To change a model: `UPDATE llm_instances SET config = JSON_SET(config, '$.models.quality', 'new-model-name') WHERE instance_id = 'groq_free'`

### Claude CLI Priority from DB

`buildFallbackChain()` now reads `llm_instances.priority` for Claude CLI instead of hardcoded 20. Change priority in DB to reposition Claude CLI in the fallback order without a code deploy.

## Model Discovery Tool (`check_model_updates`)

The ai-ops agent runs `check_model_updates` during every assess phase to detect model drift:

- **Ollama**: GET `/api/tags` — compares against `supported_models` in DB
- **Claude CLI**: runs `claude --version` — reports CLI version (no model list endpoint)
- **External APIs**: GET `{base_url}/models` (OpenAI-compatible endpoint) — diffs against DB

When new or deprecated models are found, a review queue item is created for human review. Update `supported_models` and `config.models` in DB to acknowledge changes.

```bash
# Manually trigger via agent:
php artisan agent:run ai-ops --task="check for model updates on all providers"
```

## Files Reference

| File | Purpose |
|------|---------|
| `app/Services/AIService.php` | Main service with provider chains, concurrency, circuit breakers |
| `app/Engine/AIRouter.php` | HTTP calls: Ollama, Claude CLI, OpenAI-compatible (text + vision) |
| `app/Exceptions/AI/` | Exception hierarchy (14 classes) |
| `app/Exceptions/AI/AIExceptionFactory.php` | Creates typed exceptions from responses/messages |
| `config/services.php` | Ollama URL and model configuration |
| `docs/AIService-LLM-Gateway.md` | This documentation |

## Version History

### v2.7 (March 3, 2026)
- **Role-based model selection**: `llm_instances.config.models` maps roles (`standard`, `fast`, `quality`, `vision`) to model names per provider. Callers set `$config['model_role']` to opt in.
- `AIService::resolveModelForProvider(instanceId, role)`: resolves role → model with 4-level fallback. Results cached per-request in `$providerModelCache`.
- **Claude CLI `--model` flag**: `tryClaudeCLI()`, `streamingFallbackToClaude()`, vision path, `claudeWebResearch()` all resolve and pass `--model {model}` via `config['claude_model']` through to `AIRouter::callClaudeCLI()` and `callClaudeCLIWithImage()`.
- `claudeWebResearch()` uses `quality` role by default (was burning Opus — now uses sonnet).
- **DB-driven Claude CLI priority**: `buildFallbackChain()` reads priority from `llm_instances` table instead of hardcoded 20. Reorder without code deploy.
- **`check_model_updates` agent tool**: `AIOperationsService::checkModelUpdates()` probes Ollama `/api/tags`, Claude CLI `--version`, external `/models` endpoints. Creates review queue items for model drift. Added to ai-ops assess phase.
- Migration: `2026_03_03_200000_add_role_model_config_to_llm_instances` seeds role maps for 8 providers.

### v2.3 (February 23, 2026)
- External vision provider support: `processImage()` now tries external APIs between Ollama and Claude CLI
- Added `AIRouter::callOpenAICompatibleVision()` for OpenAI-format vision requests (base64 image in messages)
- Added `getVisionCapableProviders()` with capability detection (handles both array/object JSON formats)
- OpenRouter configured with `google/gemma-3-27b-it:free` as vision model
- Privacy enforcement: Gemini/Mistral set to `vision=false` in DB (no personal photos sent to training-risk providers)
- `sensitive_data` config flag allows callers to restrict to `sensitive_safe=true` providers only
- Pipeline auto-scaling: `files:enrich --limit=auto` with additive factors (CPU, memory, time-of-day, backlog)
- Batch face detection: 50 images per Python process (eliminates ~2.5s startup overhead per image)
- Fixed database backup false failures (pg_dump/mysqldump exit code + stderr checking)

### v2.2 (February 2, 2026)
- Increased CLAUDE_ABSOLUTE_MAX from 10 to 20
- Increased CLAUDE_DEFAULT_MAX from 5 to 7
- Increased CLAUDE_OLLAMA_FALLBACK_MIN from 3 to 5
- Added CLAUDE_SLOTS_PER_CORE = 2 for dynamic resource-based scaling
- New formula: max_slots = min(ABSOLUTE_MAX, cpu_count * SLOTS_PER_CORE)
- Example: 12-core server can now use up to 20 concurrent Claude slots

### v2.1 (February 2, 2026)
- Added LiteLLM-style exception hierarchy (14 exception classes)
- `AIExceptionFactory` classifies errors from HTTP responses or messages
- Typed backoff per exception: Timeout (2s), Connection (1s), RateLimit (30s), etc.
- Removed `isNonRetryableError()` method in favor of `$exception->isRetryable()`
- Transient exceptions retry, Permanent exceptions skip to next provider

### v2.0 (January 7, 2026)
- Added Ollama busy lock for single-GPU protection
- Added Claude CLI slot management (1-5 parallel calls)
- Added auto-scaling based on CPU load and memory
- Fixed Claude CLI vision (removed invalid `--image` flag)
- Applied concurrency to both vision AND text processing
- Added future cloud LLM expansion stubs

### v1.0 (December 25, 2025)
- Initial release with circuit breaker and retry logic
- Ollama → Claude CLI → notification fallback chain
- Smart timeouts based on model state

<?php

namespace App\Services;

use App\DTOs\TrustEnvelope;
use App\Engine\AIRouter;
use App\Exceptions\AI\AIExceptionFactory;
use App\Exceptions\AI\AIServiceException;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AIService - Production-Grade Multi-Provider LLM Gateway
 *
 * Implements industry best practices from LiteLLM, Portkey, and enterprise LLM gateways:
 * - Circuit Breaker: Prevents retry storms, auto-cooldown after failures
 * - Exponential Backoff: 3 retries with increasing delays before fallback
 * - Multi-Instance Ollama: Primary → Secondary → Tertiary with health-aware routing
 * - Smart Timeouts: Dynamic based on model state (loaded/loading/swap)
 * - Semantic Health Tracking: Per-provider success rates, latencies, circuit states
 * - Ollama Busy Lock: Prevents concurrent requests to single-GPU Ollama
 * - Claude Slot Management: Enables parallel Claude CLI calls with auto-scaling
 * - Resource Monitoring: Auto-scales based on CPU load and memory availability
 *
 * Current Priority Chain:
 * 1. Ollama instances (ordered by health score) with retry + backoff
 * 2. Optional Claude CLI (operator-configured Anthropic/Claude provider) - supports parallel calls
 * 3. Pushover alert on total failure
 *
 * FUTURE EXPANSION (Stubbed):
 * - OpenAI GPT-4/GPT-4o via API
 * - Google Gemini via API
 * - Anthropic API (direct, vs CLI)
 * - Azure OpenAI
 * - Custom cost-based routing between cloud providers
 *
 * @see https://docs.litellm.ai/docs/proxy/reliability
 * @see https://portkey.ai/blog/retries-fallbacks-and-circuit-breakers-in-llm-apps/
 */
class AIService
{
    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    private AIRouter $aiRouter;

    // ═══════════════════════════════════════════════════════════════════
    // CONFIGURATION CONSTANTS
    // ═══════════════════════════════════════════════════════════════════

    // Timeout configuration (seconds)
    // Note: Even with OLLAMA_KEEP_ALIVE=10m, model may unload between status check
    // and actual request. Use conservative timeouts that account for reload time.
    private const TIMEOUT_MODEL_LOADED = 60;      // Model in VRAM — 60s is generous; if hung, fail fast to fallback

    private const TIMEOUT_MODEL_LOADING = 120;    // Model loading from disk

    private const TIMEOUT_MODEL_SWAP = 180;       // Different model needs swap

    private const TIMEOUT_CONNECT = 5;            // Connection check

    // Circuit breaker configuration — reads from config/circuit_breaker.php (SC-2.1)
    private const CIRCUIT_FAILURE_THRESHOLD = 5;  // Fallback only — config() is primary

    private const CIRCUIT_COOLDOWN_SECONDS = 30;

    // Retry configuration — LLM-specific (faster than RetryService defaults for provider failover)
    // See RetryService for generic retry (3 attempts, 1-30s). These are intentionally lower. (SC-2.4)
    private const MAX_RETRIES = 2;                // Retries per provider

    private const INITIAL_BACKOFF_MS = 500;       // Initial delay (500ms)

    private const MAX_BACKOFF_MS = 4000;          // Max delay

    private const BACKOFF_MULTIPLIER = 2;         // Exponential multiplier

    // Busy detection configuration
    private const OLLAMA_BUSY_LOCK_TTL = 150;     // Fallback — config/lock_ttls.php is primary (SC-2.3)

    private const OLLAMA_BUSY_CACHE_KEY = 'ollama_busy_lock';

    // Claude CLI concurrency configuration (with dynamic auto-scaling)
    // Note: Max slots scale dynamically based on CPU cores and available memory
    // N82: CLAUDE_MIN_CONCURRENT/DEFAULT_MAX/ABSOLUTE_MAX/OLLAMA_FALLBACK_MIN read from config/agents.php
    private const CLAUDE_SLOT_TTL = 180;          // Max slot duration (was 600s/10min — calls take 10-60s)

    private const CLAUDE_SLOTS_CACHE_KEY = 'claude_cli_slots';

    private const CLAUDE_SLOT_STALE_SECONDS = 90; // Consider slot stale if PID dead (was 300s/5min)
    // N119c: All scaling thresholds moved to config/agents.php — no hardcoded constants

    // Wait-for-slot configuration (fault tolerance with progressive backoff)
    private const SLOT_WAIT_TIMEOUT_SECONDS = 60;   // Max wait for slot (was 300s/5min — pointless waiting that long)

    private const SLOT_WAIT_POLL_INTERVAL_MS = 500;  // Initial check interval during wait

    private const SLOT_WAIT_MAX_POLL_MS = 3000;      // Max poll interval (was 5000)

    // Failure alerting configuration
    private const SLOT_TIMEOUT_ALERT_THRESHOLD = 3;   // Alert after N consecutive slot timeouts

    private const SLOT_TIMEOUT_ALERT_COOLDOWN = 5400;  // Don't alert more than once per 1.5 hours

    private const SLOT_TIMEOUT_CACHE_KEY = 'ai_slot_timeout_count';

    // ═══════════════════════════════════════════════════════════════════
    // INSTANCE PROPERTIES
    // ═══════════════════════════════════════════════════════════════════

    private string $defaultModel;

    private string $visionModel;

    private string $embeddingModel;

    /** @var array Ollama instance configurations */
    private array $ollamaInstances = [];

    /** @var array|null Cached model profiles from DB */
    private ?array $modelProfiles = null;

    /** @var array Per-request cache for resolveModelForProvider() — avoids repeated DB hits */
    private array $providerModelCache = [];

    /** @var SemanticCache|null Semantic cache instance */
    private ?SemanticCache $semanticCache = null;

    /**
     * Agent-scoped model role override — set by AgentLoopService before tool execution.
     * When set, any AIService::process() call that lacks an explicit model_role will
     * inherit this value, ensuring tool-level LLM calls use the agent's quality setting.
     * Static because AIService is not a singleton — multiple instances may exist per request.
     */
    private static ?string $agentModelRole = null;

    /**
     * Set the agent-scoped default model role (e.g. 'quality', 'fast', 'standard').
     * Called by AgentLoopService before hybrid/agentic tool execution.
     */
    public static function setAgentModelRole(?string $role): void
    {
        self::$agentModelRole = $role;
    }

    /**
     * Clear the agent-scoped model role (called after agent workflow completes).
     */
    public static function clearAgentModelRole(): void
    {
        self::$agentModelRole = null;
    }

    /** @var bool Whether caching is enabled */
    private bool $cacheEnabled = true;

    /** @var LLMPoolManagerService|null Dynamic LLM pool manager */
    private ?LLMPoolManagerService $poolManager = null;

    /** @var bool Whether to use dynamic pool routing */
    private bool $useDynamicPool = true;

    /** @var CircuitBreaker Shared cache-based circuit breaker (fallback when pool manager unavailable) */
    private CircuitBreaker $circuitBreaker;

    public function __construct(?AIRouter $aiRouter = null, ?LLMPoolManagerService $poolManager = null, ?CircuitBreaker $circuitBreaker = null)
    {
        $this->aiRouter = $aiRouter ?? app(AIRouter::class);
        $this->circuitBreaker = $circuitBreaker ?? app(CircuitBreaker::class);

        // Load models from DB-backed role maps first; env config is bootstrap-only.
        $dbModels = $this->loadModelsFromDb();
        $this->defaultModel = (string) ($dbModels['standard'] ?? config('services.ollama.model') ?? '');
        $this->visionModel = (string) ($dbModels['vision'] ?? config('services.ollama.vision_model') ?? '');
        $this->embeddingModel = (string) ($dbModels['embedding'] ?? config('services.ollama.embedding_model') ?? '');

        // Initialize Ollama instances (legacy fallback if pool manager unavailable)
        $this->initializeOllamaInstances();

        // Load default model from config.default_model if not resolved from roles
        if (! isset($dbModels['standard'])) {
            $defaultFromDb = $dbModels['default_model'] ?? null;
            if ($defaultFromDb) {
                $this->defaultModel = $defaultFromDb;
            }
        }

        // Initialize LLM Pool Manager for dynamic routing
        $this->useDynamicPool = config('services.ai.use_dynamic_pool', true);
        if ($this->useDynamicPool) {
            try {
                $this->poolManager = $poolManager ?? app(LLMPoolManagerService::class);
            } catch (\Exception $e) {
                Log::warning('AIService: LLMPoolManager unavailable, using legacy routing', [
                    'error' => $e->getMessage(),
                ]);
                $this->useDynamicPool = false;
            }
        }

        // E01 Phase 3.5: Initialize semantic cache
        $this->cacheEnabled = config('services.ai.cache_enabled', true);
        if ($this->cacheEnabled) {
            $this->semanticCache = new SemanticCache([
                'similarity_threshold' => config('services.ai.cache_similarity', 0.85),
                'ttl' => config('services.ai.cache_ttl', 86400),
                'semantic_enabled' => config('services.ai.semantic_cache', true),
            ]);
            $this->semanticCache->setAIService($this);
        }
    }

    /**
     * Load model names from the primary Ollama instance's DB config.
     * Returns role→model map. Falls back to empty array if DB unavailable.
     */
    private function loadModelsFromDb(): array
    {
        try {
            $row = DB::selectOne(
                "SELECT config FROM llm_instances WHERE instance_type = 'ollama' AND is_active = 1 ORDER BY priority ASC LIMIT 1"
            );

            if ($row) {
                $config = json_decode($row->config ?? '{}', true) ?: [];
                $models = $config['models'] ?? [];
                $models['default_model'] = $config['default_model'] ?? null;
                $models['embedding'] = $config['embedding_model'] ?? null;

                return $models;
            }
        } catch (\Throwable $e) {
            // DB not available during boot — fall through to config defaults
        }

        return [];
    }

    /**
     * Initialize Ollama instance pool from llm_instances DB table
     * Falls back to config only if DB returns zero rows
     */
    private function initializeOllamaInstances(): void
    {
        try {
            $instances = DB::select(
                "SELECT instance_id, instance_name, base_url, priority, is_active, is_healthy, max_concurrent
                 FROM llm_instances
                 WHERE instance_type = 'ollama' AND is_active = 1
                 ORDER BY priority ASC"
            );

            if (! empty($instances)) {
                foreach ($instances as $instance) {
                    $this->ollamaInstances[] = [
                        'id' => $instance->instance_id,
                        'url' => rtrim($instance->base_url, '/'),
                        'priority' => (int) $instance->priority,
                        'name' => $instance->instance_name,
                        'max_concurrent' => (int) $instance->max_concurrent,
                        'is_healthy' => (bool) $instance->is_healthy,
                    ];
                }

                return;
            }
        } catch (\Exception $e) {
            Log::warning('AIService: Failed to load Ollama instances from DB, falling back to config', [
                'error' => $e->getMessage(),
            ]);
        }

        // Last resort fallback: config-based (DB returned zero rows or query failed)
        Log::warning('AIService: No active Ollama instances in llm_instances table, using config fallback', [
            'fallback_url' => config('services.ollama.api_url', 'http://127.0.0.1:11434'),
        ]);
        $primaryUrl = config('services.ollama.api_url', 'http://127.0.0.1:11434');
        $this->ollamaInstances[] = [
            'id' => 'ollama_config_fallback',
            'url' => rtrim($primaryUrl, '/'),
            'priority' => 1,
            'name' => 'Config Fallback',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // MODEL SELECTION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Select optimal model for a given task type
     *
     * @param  string  $taskType  Task type: 'default', 'fast', 'creative', 'coding', 'vision', 'embedding'
     * @return string Model name to use
     */
    public function selectModel(string $taskType = 'default'): string
    {
        // For creative/uncensored tasks, first check model registry for uncensored models
        if ($taskType === 'creative') {
            $uncensoredModel = $this->findModelWithCapability('uncensored');
            if ($uncensoredModel) {
                Log::info('AIService: Selected uncensored model from registry', [
                    'model' => $uncensoredModel['model_name'],
                    'instance' => $uncensoredModel['instance_id'],
                    'quality' => $uncensoredModel['quality_rating'],
                ]);

                return $uncensoredModel['model_name'];
            }
        }

        // For coding tasks, check for models with code capability
        if ($taskType === 'coding') {
            $codingModel = $this->findModelWithCapability('code');
            if ($codingModel) {
                Log::info('AIService: Selected coding model from registry', [
                    'model' => $codingModel['model_name'],
                    'instance' => $codingModel['instance_id'],
                    'quality' => $codingModel['quality_rating'],
                ]);

                return $codingModel['model_name'];
            }
        }

        // Get available models on Ollama
        $availableModels = $this->getAvailableModels();

        // Get profile for task type
        $profiles = $this->loadModelProfiles();
        $profile = $profiles[$taskType] ?? $profiles['default'] ?? null;
        $preferredModel = $profile['model'] ?? null;

        // Check if preferred model is available
        if (is_string($preferredModel) && $preferredModel !== '') {
            foreach ($availableModels as $model) {
                if ($this->isModelMatch($model['name'], $preferredModel)) {
                    return $model['name'];
                }
            }
        }

        // Fallback to default model if preferred not available
        Log::warning('AIService: Preferred model not available, using default', [
            'task_type' => $taskType,
            'preferred' => $preferredModel,
            'fallback' => $this->defaultModel,
        ]);

        if ($this->defaultModel !== '') {
            return $this->defaultModel;
        }

        return $availableModels[0]['name'] ?? '';
    }

    /**
     * Find the best vetted model with a specific capability
     *
     * @param  string  $capability  Capability to search for (e.g., 'uncensored', 'code', 'vision')
     * @return array|null Model info with instance details, or null if not found
     */
    private function findModelWithCapability(string $capability): ?array
    {
        try {
            // Query model registry for vetted models with the capability on healthy instances
            $result = DB::select("
                SELECT om.model_name, om.quality_rating, om.profile, om.capabilities,
                       li.instance_id, li.base_url, li.is_healthy, li.health_score
                FROM ollama_models om
                JOIN llm_instances li ON li.id = om.instance_id
                WHERE om.status = 'vetted'
                  AND om.is_available = 1
                  AND li.is_active = 1
                  AND li.is_healthy = 1
                  AND li.circuit_state = 'closed'
                  AND JSON_CONTAINS(om.capabilities, ?)
                ORDER BY om.quality_rating DESC, li.health_score DESC
                LIMIT 1
            ", [json_encode($capability)]);

            if (! empty($result)) {
                return (array) $result[0];
            }
        } catch (\Exception $e) {
            Log::warning('AIService: Failed to query model registry for capability', [
                'capability' => $capability,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Auto-select model based on prompt analysis
     *
     * @param  string  $prompt  The prompt to analyze
     * @param  array  $config  Optional config with hints
     * @return string Optimal model for the task
     */
    public function autoSelectModel(string $prompt, array $config = []): string
    {
        // If explicit task_type provided, use it
        if (! empty($config['task_type'])) {
            return $this->selectModel($config['task_type']);
        }

        // If explicit model provided, use it
        if (! empty($config['model'])) {
            return $config['model'];
        }

        // Analyze prompt to determine task type
        $taskType = $this->analyzeTaskType($prompt, $config);

        return $this->selectModel($taskType);
    }

    /**
     * Analyze prompt to determine optimal task type
     *
     * @param  string  $prompt  The prompt to analyze
     * @param  array  $config  Optional context
     * @return string Task type
     */
    private function analyzeTaskType(string $prompt, array $config = []): string
    {
        $promptLower = strtolower($prompt);

        // Check for uncensored/unrestricted request indicators (highest priority)
        // Users may explicitly request uncensored models for unrestricted responses
        $uncensoredKeywords = [
            'uncensored', 'unfiltered', 'no filter', 'without restrictions',
            'unrestricted', 'unmoderated', 'raw response', 'bypass', 'jailbreak',
            'without censorship', 'no limits', 'dolphin', 'without safety',
        ];
        foreach ($uncensoredKeywords as $keyword) {
            if (str_contains($promptLower, $keyword)) {
                Log::info('AIService: Uncensored request detected, selecting creative/uncensored model', [
                    'keyword_matched' => $keyword,
                ]);

                return 'creative'; // Creative profile includes uncensored models
            }
        }

        // Check for coding indicators
        $codingKeywords = ['code', 'function', 'class', 'method', 'debug', 'error', 'compile',
            'syntax', 'programming', 'javascript', 'python', 'php', 'sql',
            'implement', 'refactor', 'bug', 'fix the', 'write a script'];
        foreach ($codingKeywords as $keyword) {
            if (str_contains($promptLower, $keyword)) {
                return 'coding';
            }
        }

        // Check for creative indicators
        $creativeKeywords = ['story', 'creative', 'imagine', 'fiction', 'poem', 'write me',
            'roleplay', 'character', 'narrative', 'fantasy'];
        foreach ($creativeKeywords as $keyword) {
            if (str_contains($promptLower, $keyword)) {
                return 'creative';
            }
        }

        // Check for fast/simple tasks
        $simpleKeywords = ['classify', 'categorize', 'yes or no', 'true or false',
            'extract', 'list the', 'what is the'];
        foreach ($simpleKeywords as $keyword) {
            if (str_contains($promptLower, $keyword)) {
                return 'fast';
            }
        }

        // Check prompt length - short prompts may benefit from fast model
        if (strlen($prompt) < 100 && ! str_contains($promptLower, 'explain')) {
            return 'fast';
        }

        // Default to high quality model
        return 'default';
    }

    /**
     * Get all available models from Ollama
     *
     * @param  bool  $forceRefresh  Force refresh from API
     * @return array Available models with metadata
     */
    public function getAvailableModels(bool $forceRefresh = false): array
    {
        $cacheKey = 'ollama_available_models';

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $url = $this->ollamaInstances[0]['url'];
            $response = Http::connectTimeout(5)->timeout(10)->get("{$url}/api/tags");

            if ($response->successful()) {
                $models = $response->json()['models'] ?? [];

                // Add profile info to each model
                foreach ($models as &$model) {
                    $model['profile'] = $this->getModelProfile($model['name']);
                }

                Cache::put($cacheKey, $models, 300); // Cache for 5 minutes

                return $models;
            }
        } catch (\Exception $e) {
            Log::warning('AIService: Failed to fetch available models', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Get the profile name for a model
     */
    private function getModelProfile(string $modelName): ?string
    {
        foreach ($this->loadModelProfiles() as $profileName => $profile) {
            if ($this->isModelMatch($modelName, $profile['model'])) {
                return $profileName;
            }
        }

        return null;
    }

    /**
     * Load model profiles from llm_model_profiles table (cached per-request).
     * If profile rows are missing, synthesize a minimal fallback from llm_instances
     * role mappings so the DB remains authoritative for model selection.
     */
    private function loadModelProfiles(): array
    {
        if ($this->modelProfiles !== null) {
            return $this->modelProfiles;
        }

        // Try cache first (60s TTL — refreshes often enough for config changes)
        $cached = Cache::get('llm_model_profiles');
        if ($cached) {
            $this->modelProfiles = $cached;

            return $cached;
        }

        try {
            $rows = DB::select('SELECT profile_name, model_name, description, use_cases, enabled FROM llm_model_profiles WHERE enabled = 1 ORDER BY profile_name');
            if (! empty($rows)) {
                $profiles = [];
                foreach ($rows as $row) {
                    $profiles[$row->profile_name] = [
                        'model' => $row->model_name,
                        'description' => $row->description,
                        'use_cases' => json_decode($row->use_cases ?? '[]', true) ?: [],
                    ];
                }
                Cache::put('llm_model_profiles', $profiles, 60);
                $this->modelProfiles = $profiles;

                return $profiles;
            }
        } catch (\Throwable $e) {
            Log::debug('AIService: model profiles DB query failed, using synthesized fallback', ['error' => $e->getMessage()]);
        }

        $this->modelProfiles = $this->buildModelProfilesFallback();

        return $this->modelProfiles;
    }

    private function buildModelProfilesFallback(): array
    {
        $roleModels = $this->loadModelsFromDb();

        $fallbackModels = [
            'default' => $roleModels['standard'] ?? $roleModels['default_model'] ?? $this->defaultModel,
            'fast' => $roleModels['fast'] ?? $roleModels['standard'] ?? $roleModels['default_model'] ?? $this->defaultModel,
            'quality' => $roleModels['quality'] ?? $roleModels['standard'] ?? $roleModels['default_model'] ?? $this->defaultModel,
            'creative' => $roleModels['creative'] ?? $roleModels['standard'] ?? $roleModels['default_model'] ?? $this->defaultModel,
            'coding' => $roleModels['coding'] ?? $roleModels['quality'] ?? $roleModels['standard'] ?? $roleModels['default_model'] ?? $this->defaultModel,
            'vision' => $roleModels['vision'] ?? $this->visionModel,
            'embedding' => $roleModels['embedding'] ?? $this->embeddingModel,
        ];

        $descriptions = [
            'default' => 'General purpose local model',
            'fast' => 'Fast local extraction and cleanup',
            'quality' => 'Higher-quality local reasoning',
            'creative' => 'Creative local generation',
            'coding' => 'Code generation and review',
            'vision' => 'Image analysis',
            'embedding' => 'Vector embeddings',
        ];

        $profiles = [];
        foreach ($fallbackModels as $profile => $model) {
            $model = is_string($model) ? trim($model) : '';
            if ($model === '') {
                continue;
            }

            $profiles[$profile] = [
                'model' => $model,
                'description' => $descriptions[$profile],
                'use_cases' => [],
            ];
        }

        return $profiles;
    }

    /**
     * Resolve the model name for a given provider + role.
     *
     * Reads config->models->{role} from llm_instances. Falls back to config->default_model
     * if the role is not mapped. Returns null if no model is configured (provider uses its own default).
     *
     * Roles: standard (general/agents), fast (tagging/classification), quality (research/complex),
     *        vision (image analysis), embedding (handled separately by getEmbeddingProviders).
     *
     * Results cached per-request in $this->providerModelCache to avoid repeated DB hits.
     *
     * @param  string  $instanceId  e.g. 'claude_cli', 'groq_free'
     * @param  string  $role  'standard' | 'fast' | 'quality' | 'vision'
     */
    private function resolveModelForProvider(string $instanceId, string $role = 'standard'): ?string
    {
        $cacheKey = "{$instanceId}:{$role}";

        if (isset($this->providerModelCache[$cacheKey])) {
            return $this->providerModelCache[$cacheKey];
        }

        try {
            $row = DB::selectOne(
                'SELECT config FROM llm_instances WHERE instance_id = ?',
                [$instanceId]
            );

            if ($row) {
                $config = json_decode($row->config ?? '{}', true) ?: [];
                $model = $config['models'][$role]
                    ?? $config['models']['standard']
                    ?? $config['default_model']
                    ?? null;

                $this->providerModelCache[$cacheKey] = $model;

                return $model;
            }
        } catch (\Throwable $e) {
            Log::warning("AIService: resolveModelForProvider({$instanceId}, {$role}) failed", [
                'error' => $e->getMessage(),
            ]);
        }

        $this->providerModelCache[$cacheKey] = null;

        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PROMPT MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get AI prompt from database by key
     *
     * Prompts are stored in the ai_prompts table and can be edited via UI.
     * Falls back to hardcoded default if database prompt not found.
     *
     * @param  string  $promptKey  Unique prompt identifier
     * @param  string|null  $fallback  Fallback prompt if not found in DB
     * @return string The prompt text
     */
    public function getPrompt(string $promptKey, ?string $fallback = null): string
    {
        // Cache prompts for 5 minutes to reduce DB queries
        $cacheKey = "ai_prompt:{$promptKey}";

        return Cache::remember($cacheKey, 300, function () use ($promptKey, $fallback) {
            try {
                $result = DB::select(
                    'SELECT prompt_text FROM ai_prompts WHERE prompt_key = ? AND is_active = 1 LIMIT 1',
                    [$promptKey]
                );

                return ! empty($result) ? $result[0]->prompt_text : ($fallback ?? '');
            } catch (\Exception $e) {
                Log::warning('AIService: Failed to load prompt from DB', [
                    'prompt_key' => $promptKey,
                    'error' => $e->getMessage(),
                ]);

                return $fallback ?? '';
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // FACTUAL MODE - ANTI-HALLUCINATION SETTINGS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Anti-hallucination system prompt injected in factual mode
     */
    private const FACTUAL_MODE_SYSTEM_PROMPT = <<<'PROMPT'
CRITICAL ACCURACY REQUIREMENTS:
- You must ONLY state facts that are DIRECTLY supported by the provided information
- If you are uncertain about ANY fact, explicitly state "I cannot confirm this from the provided sources"
- NEVER fabricate, invent, or guess information - accuracy is paramount
- If sources are insufficient, say "Insufficient information available" rather than speculating
- When sources conflict, note the discrepancy rather than choosing one
- Cite specific sources when making factual claims
- Distinguish clearly between facts (from sources) and analysis (your interpretation)
PROMPT;

    /**
     * Apply factual mode settings to config for accuracy-critical tasks
     *
     * Factual mode enforces:
     * - Temperature 0.1 (minimal creativity/randomness)
     * - Anti-hallucination system prompt injection
     * - Designed for: genealogy research, data extraction, fact verification
     *
     * @param  array  $config  Original config
     * @param  string  $prompt  The prompt being processed
     * @return array Modified config with factual mode settings
     */
    private function applyFactualMode(array $config, string $prompt): array
    {
        // Force low temperature for factual accuracy
        $config['temperature'] = 0.1;

        // Disable semantic cache for factual tasks - each query needs fresh analysis
        // Research topics have similar prompts but need unique responses per topic
        $config['use_cache'] = false;

        // Inject anti-hallucination system prompt
        $existingSystemPrompt = $config['system_prompt'] ?? '';
        if (! empty($existingSystemPrompt)) {
            $config['system_prompt'] = self::FACTUAL_MODE_SYSTEM_PROMPT."\n\n".$existingSystemPrompt;
        } else {
            $config['system_prompt'] = self::FACTUAL_MODE_SYSTEM_PROMPT;
        }

        Log::debug('AIService: Factual mode enabled', [
            'temperature' => $config['temperature'],
            'use_cache' => false,
            'prompt_length' => strlen($prompt),
        ]);

        return $config;
    }

    // ═══════════════════════════════════════════════════════════════════
    // MAIN PROCESSING METHODS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Process text with AI - full resilience pipeline
     * E01 Phase 3.5: Now with semantic caching for 40-60% cost reduction
     *
     * @param  string  $prompt  The prompt to process
     * @param  array  $config  Configuration options:
     *                         - model: string - Specific model to use
     *                         - use_cache: bool - Whether to use semantic cache
     *                         - suppressAlert: bool - Suppress failure alerts (for pipelines with fallbacks)
     *                         - factual_mode: bool - Enable strict factual mode (temp=0.1, anti-hallucination prompt)
     *                         - temperature: float - Override temperature (default 0.3, factual_mode forces 0.1)
     */
    public function process(string $prompt, array $config = []): array
    {
        $startTime = microtime(true);
        $attempts = [];

        // Auto-select model if not explicitly provided
        // Supports: $config['model'] (explicit), $config['task_type'] (profile), or auto-detect
        $autoSelect = $config['auto_select_model'] ?? false;
        $modelRole = $config['model_role'] ?? self::$agentModelRole; // N119c: role-based model resolution, with agent inheritance
        if ($modelRole === null && empty($config['model'])) {
            $modelRole = 'standard';
        }
        if ($modelRole && ! isset($config['model_role'])) {
            $config['model_role'] = $modelRole; // Propagate to fallback providers
        }
        $config = $this->applyPreferredOllamaInstanceOrder($config, $modelRole);
        if ($autoSelect && empty($config['model'])) {
            $requestedModel = $this->autoSelectModel($prompt, $config);
            Log::info('AIService: Auto-selected model', [
                'model' => $requestedModel,
                'task_type' => $config['task_type'] ?? 'auto-detected',
            ]);
        } else {
            $requestedModel = $config['model'] ?? $this->defaultModel;
        }

        $suppressAlert = $config['suppressAlert'] ?? false;

        // RLM Auto-decompose: transparently split large prompts into smaller sub-calls.
        // Fires BEFORE cache/dedup — each sub-call gets its own cache entry.
        // Skip when: explicitly disabled, already a sub-call, or embedding request.
        // expect_json calls ARE eligible — sub-calls extract text, synthesis produces JSON.
        if (empty($config['_skip_decompose']) && empty($config['embedding'])) {
            $autoResult = $this->tryAutoDecompose($prompt, $config, $startTime);
            if ($autoResult !== null) {
                return $autoResult;
            }
        }

        // Factual mode: enforce strict settings for accuracy-critical tasks
        // MUST run before checking use_cache, as factual_mode disables caching
        if (! empty($config['factual_mode'])) {
            $config = $this->applyFactualMode($config, $prompt);
        }

        // Get cache setting AFTER factual_mode may have disabled it
        $useCache = $config['use_cache'] ?? $this->cacheEnabled;

        // E01 Phase 3.5: Check semantic cache first
        if ($useCache && $this->semanticCache) {
            $cacheContext = [
                'model' => $requestedModel,
                'temperature' => $config['temperature'] ?? 0.3, // Default 0.3 for factual accuracy
            ];

            $cachedResult = $this->semanticCache->get($prompt, $cacheContext);

            if ($cachedResult !== null) {
                $cachedResult['from_cache'] = true;
                $cachedResult['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                return $cachedResult;
            }
        }

        // AI-4: Request deduplication — if an identical prompt is already in-flight,
        // wait briefly then check semantic cache (which the first request will populate).
        // Uses Redis atomic add to prevent duplicate concurrent LLM calls.
        $dedupEnabled = ($config['dedup'] ?? true) && $useCache;
        if ($dedupEnabled) {
            $dedupKey = 'ai_inflight:'.md5($prompt.'|'.($modelRole ?? 'default'));
            // Try to claim this request (atomic: returns false if key already exists)
            if (! Cache::add($dedupKey, getmypid(), 60)) {
                // Another request is in-flight — wait up to 15s for it to finish
                $waited = $this->waitForInflightResult($prompt, $cacheContext ?? [], 15);
                if ($waited !== null) {
                    $waited['from_dedup'] = true;
                    $waited['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                    return $waited;
                }
                // Timed out waiting — proceed with our own call (dedup key expired)
            }
            // Register cleanup: remove in-flight marker when this request finishes
            register_shutdown_function(function () use ($dedupKey) {
                Cache::forget($dedupKey);
            });
        }

        // prefer_claude: Try Claude CLI first for quality-critical tasks (research synthesis)
        $preferClaude = ! empty($config['prefer_claude']);

        if ($preferClaude && $this->isClaudeCliEnabled() && ! $this->isCircuitOpen('claude_cli')) {
            $result = $this->tryClaudeCLI($prompt, $config);

            if ($result['success']) {
                $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                $result['from_cache'] = false;

                if ($useCache && $this->semanticCache && isset($cacheContext)) {
                    $this->semanticCache->put($prompt, $result, $cacheContext);
                }

                return $result;
            }

            $attempts['claude_cli'] = $result['error'];
            Log::info('AIService: Claude preferred but failed, falling back to Ollama');
        }

        // prefer_external: Try external APIs (Groq, OpenRouter) BEFORE Ollama.
        // Used by agents to get faster, more capable models instead of slow local 8B.
        $preferExternal = ! empty($config['prefer_external']);

        // Latency demotion: if ALL healthy Ollama instances are slow (avg > 5s),
        // auto-prefer external providers to avoid cumulative timeout in agent runs.
        // Embedding calls are excluded (must stay local for 768d consistency).
        if (! $preferExternal && empty($config['embedding']) && $this->poolManager) {
            $demotionThresholdMs = (float) config('health_thresholds.llm.latency_demotion_ms', 5000);
            $allSlow = true;
            foreach ($this->getHealthyOllamaInstancesForConfig($config) as $inst) {
                if (($inst['avg_response_ms'] ?? 0) < $demotionThresholdMs) {
                    $allSlow = false;
                    break;
                }
            }
            if ($allSlow && ! empty($this->getHealthyOllamaInstancesForConfig($config))) {
                $preferExternal = true;
                Log::info('AIService: Latency demotion — all Ollama instances above threshold, preferring external', [
                    'threshold_ms' => $demotionThresholdMs,
                ]);
            }
        }

        if (! $preferExternal) {
            // Step 1: Try each Ollama instance with retry + backoff
            // When multiple instances exist, skip busy ones instead of waiting (try next instance)
            $multiInstance = count($this->getHealthyOllamaInstancesForConfig($config)) > 1;
            foreach ($this->getHealthyOllamaInstancesForConfig($config) as $instance) {
                // N119c: Resolve model_role per instance (quality, standard, fast, etc.).
                // Graceful downgrade: if requested role unavailable, try standard → default_model
                $instanceModel = $requestedModel;
                if ($modelRole) {
                    $instanceConfig = $instance['config'] ?? [];
                    $roleModel = $instanceConfig['models'][$modelRole] ?? null;
                    if ($roleModel) {
                        $instanceModel = $roleModel;
                    } elseif ($modelRole !== 'standard') {
                        // Requested role not configured — downgrade to standard role
                        $standardModel = $instanceConfig['models']['standard'] ?? null;
                        if ($standardModel) {
                            $instanceModel = $standardModel;
                            Log::debug("AIService: Model role '{$modelRole}' unavailable on {$instance['name']}, downgraded to standard ({$standardModel})");
                        }
                    }
                }

                // Skip instances that don't support the resolved model
                $supportedModels = $instance['supported_models'] ?? [];
                if (! empty($supportedModels) && ! in_array($instanceModel, $supportedModels)) {
                    // Last resort: try the instance's default model before giving up
                    $defaultModel = $instance['default_model'] ?? null;
                    if ($defaultModel && in_array($defaultModel, $supportedModels)) {
                        $instanceModel = $defaultModel;
                        Log::debug("AIService: Fell back to default model '{$defaultModel}' on {$instance['name']}");
                    } else {
                        $attempts[$instance['id']] = "Model '{$instanceModel}' (role: {$modelRole}) not available on {$instance['name']}";

                        continue;
                    }
                }

                // Never wait for a busy Ollama lock — fall through to external providers immediately.
                // Vision mode already does this (line ~896). Text mode was blocking 30s on single-instance.
                $config['skip_if_busy'] = $config['skip_if_busy'] ?? true;

                $result = $this->tryOllamaWithRetry($instance, $prompt, $instanceModel, $config);

                if ($result['success']) {
                    // AI-1: Cascade quality check — escalate if local response is low quality
                    if ($this->shouldCascade($config)) {
                        $cascade = $this->evaluateCascade($prompt, $result['response'] ?? '', $config, $startTime);
                        if ($cascade['escalate']) {
                            Log::info('AIService: Cascade escalation triggered', [
                                'provider' => $result['provider'] ?? $instance['id'],
                                'score' => $cascade['score'],
                                'reason' => $cascade['reason'],
                            ]);
                            $attempts[$instance['id'].'_cascade'] = 'cascade: '.$cascade['reason'];
                            $config['_cascade_attempt'] = true;
                            $config['_cascade_initial'] = [
                                'provider' => $result['provider'] ?? $instance['id'],
                                'model' => $result['model'] ?? $instanceModel,
                                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
                                'score' => $cascade['score'],
                                'reason' => $cascade['reason'],
                                'signals' => $cascade['signals'],
                                'prompt_hash' => hash('sha256', $prompt),
                            ];

                            continue; // Fall through to external/Claude fallback chain
                        }
                    }

                    $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                    $result['from_cache'] = false;

                    // E01 Phase 3.5: Cache successful response
                    if ($useCache && $this->semanticCache) {
                        $this->semanticCache->put($prompt, $result, $cacheContext);
                    }

                    return $result;
                }

                $attempts[$instance['id']] = $result['error'];

                // If this instance is busy/unavailable, try next instance before falling to Claude
                if ($result['skip_to_fallback'] ?? false) {
                    continue;
                }
            }
        }

        // Step 2: Build remaining fallback chain — external APIs + Claude CLI, ordered by priority
        // External API providers from llm_instances table (Groq, OpenRouter, Mistral, etc.)
        // Claude CLI priority defaults to 20, so providers with lower priority run first
        $isSensitive = ! empty($config['sensitive_data']);
        $fallbackProviders = $this->buildFallbackChain($isSensitive, $preferClaude);

        foreach ($fallbackProviders as $fallback) {
            if ($fallback['type'] === 'claude_cli') {
                // Claude CLI fallback — skip if already tried above
                if ($preferClaude || $this->isCircuitOpen('claude_cli')) {
                    if (! $preferClaude) {
                        $attempts['claude_cli'] = 'Circuit open (cooldown)';
                    }

                    continue;
                }

                $result = $this->tryClaudeCLI($prompt, $config);

                if ($result['success']) {
                    $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                    $result['from_cache'] = false;

                    if ($useCache && $this->semanticCache) {
                        $this->semanticCache->put($prompt, $result, $cacheContext);
                    }

                    $this->logCascadeResult($config, $result);

                    return $result;
                }

                $attempts['claude_cli'] = $result['error'];
            } else {
                // External API provider
                $result = $this->tryExternalProvider($fallback, $prompt, $config);

                if ($result['success']) {
                    $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                    $result['from_cache'] = false;

                    if ($useCache && $this->semanticCache) {
                        $this->semanticCache->put($prompt, $result, $cacheContext);
                    }

                    $this->logCascadeResult($config, $result);

                    return $result;
                }

                $attempts[$fallback['id']] = $result['error'];
            }
        }

        // Step 3: If prefer_external was set, try Ollama as last resort
        if ($preferExternal) {
            $config['skip_if_busy'] = $config['skip_if_busy'] ?? true;
            foreach ($this->getHealthyOllamaInstancesForConfig($config) as $instance) {
                // N119c: Resolve model_role per-instance (same as Step 1)
                $instanceModel = $requestedModel;
                if ($modelRole) {
                    $instanceConfig = $instance['config'] ?? [];
                    $roleModel = $instanceConfig['models'][$modelRole] ?? null;
                    if ($roleModel) {
                        $instanceModel = $roleModel;
                    }
                }

                $supportedModels = $instance['supported_models'] ?? [];
                if (! empty($supportedModels) && ! in_array($instanceModel, $supportedModels)) {
                    $attempts[$instance['id']] = "Model '{$instanceModel}' (role: {$modelRole}) not available on {$instance['name']} (fallback)";

                    continue;
                }

                $result = $this->tryOllamaWithRetry($instance, $prompt, $instanceModel, $config);

                if ($result['success']) {
                    $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                    $result['from_cache'] = false;

                    if ($useCache && $this->semanticCache) {
                        $this->semanticCache->put($prompt, $result, $cacheContext);
                    }

                    return $result;
                }

                $attempts[$instance['id']] = $result['error'];
            }
        }

        // All providers failed - only alert if not suppressed
        if (! $suppressAlert) {
            $this->sendFailureAlert($attempts, $prompt);
        }

        return [
            'success' => false,
            'response' => null,
            'provider' => null,
            'error' => 'All AI providers failed',
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'attempts' => $attempts,
        ];
    }

    /**
     * Check if vision capability is available (at least one provider supports it)
     */
    public function isVisionAvailable(): bool
    {
        // Vision is available if we have healthy Ollama instances or Claude CLI
        $healthyOllama = count($this->getHealthyOllamaInstances()) > 0;
        $claudeAvailable = $this->isClaudeCliEnabled() && ! $this->isCircuitOpen('claude_cli_vision');

        return $healthyOllama || $claudeAvailable;
    }

    /**
     * Process image with AI vision - full resilience pipeline
     * Supports concurrent Claude CLI calls with slot management
     *
     * @param  string  $imageContent  Raw image content
     * @param  string  $prompt  The prompt to send with the image
     * @param  array  $config  Configuration options:
     *                         - suppressAlert: bool - Suppress failure alerts (for pipelines with fallbacks)
     */
    public function processImage(string $imageContent, string $prompt, array $config = []): array
    {
        $startTime = microtime(true);
        $attempts = [];
        $requestId = uniqid('vision_', true);
        $suppressAlert = $config['suppressAlert'] ?? false;

        // Vision provider chain: Ollama → External vision APIs → Claude CLI
        // Try Ollama first (non-blocking) — if busy, try external vision providers, then Claude
        $config['skip_if_busy'] = true; // Never wait for Ollama lock — fall through to Claude
        $ollamaHandled = false;

        // Score-based order: the instance advertising the best vision model wins
        // first. Fall back to the remaining healthy instances in the same order
        // so a busy primary still cascades to the secondary before external APIs.
        $visionInstances = $this->orderOllamaInstancesForRole('vision', $config);

        foreach ($visionInstances as $instance) {
            $result = $this->tryOllamaVisionWithRetry($instance, $imageContent, $prompt, $config);

            if ($result['success']) {
                // AI-1: Cascade quality check for vision responses
                if ($this->shouldCascade($config)) {
                    $cascade = $this->evaluateCascade($prompt, $result['response'] ?? '', $config, $startTime);
                    if ($cascade['escalate']) {
                        Log::info('AIService: Vision cascade escalation triggered', [
                            'provider' => $result['provider'] ?? $instance['id'],
                            'score' => $cascade['score'],
                            'reason' => $cascade['reason'],
                        ]);
                        $attempts[$instance['id'].'_cascade'] = 'cascade: '.$cascade['reason'];
                        $config['_cascade_attempt'] = true;
                        $config['_cascade_initial'] = [
                            'provider' => $result['provider'] ?? $instance['id'],
                            'model' => $result['model'] ?? null,
                            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
                            'score' => $cascade['score'],
                            'reason' => $cascade['reason'],
                            'signals' => $cascade['signals'],
                            'prompt_hash' => hash('sha256', $prompt),
                        ];
                        $ollamaHandled = true;
                        break; // Fall through to external/Claude vision fallback
                    }
                }

                return array_merge($result, [
                    'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                ]);
            }

            // If Ollama is busy, skip to external/Claude (don't try other Ollama instances)
            if ($result['skip_to_fallback'] ?? false) {
                $attempts[$instance['id']] = $result['error'].' (auto-routed to fallback)';
                $ollamaHandled = true;
                break;
            }

            $attempts[$instance['id']] = $result['error'];
            $ollamaHandled = true;
        }

        // External vision providers (OpenRouter, Gemini, Mistral pixtral, etc.)
        // Personal photos: sensitiveData=false since user is data subject processing own files
        $sensitiveData = $config['sensitive_data'] ?? false;
        $visionProviders = $this->getVisionCapableProviders($sensitiveData);

        if (! empty($visionProviders)) {
            // Read image content if it's a file path
            $imageData = $imageContent;
            if (! str_contains($imageContent, "\0") && strlen($imageContent) < 512 && file_exists($imageContent)) {
                $imageData = file_get_contents($imageContent);
            }

            foreach ($visionProviders as $provider) {
                $result = $this->tryExternalVisionProvider($provider, $imageData, $prompt, $config);

                if ($result['success']) {
                    Log::info('AIService: Vision handled by external provider', [
                        'provider' => $result['provider_name'] ?? $result['provider'],
                        'model' => $result['model'],
                    ]);
                    $merged = array_merge($result, [
                        'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                    ]);
                    $this->logCascadeResult($config, $merged);

                    return $merged;
                }

                $attempts[$provider['id'].'_vision'] = $result['error'];
            }
        }

        // Claude CLI vision (supports multiple concurrent calls)
        if ($this->isClaudeCliEnabled() && ! $this->isCircuitOpen('claude_cli_vision')) {
            // Try to acquire a Claude slot
            $slotId = $this->acquireClaudeSlot($requestId);

            if ($slotId === null) {
                // No slots available - all Claude instances busy
                $attempts['claude_cli_vision'] = 'No Claude slots available (max concurrent: '.config('agents.claude_absolute_max', 20).')';
                Log::info('AIService: Vision request queued - no Claude slots', [
                    'slot_usage' => $this->getClaudeSlotUsage(),
                ]);
            } else {
                try {
                    $config['ai_mode'] = 'claude';
                    // Resolve vision model from DB role map
                    $visionModel = $this->resolveModelForProvider('claude_cli', 'vision')
                        ?? $this->resolveModelForProvider('claude_cli', 'standard');
                    if ($visionModel) {
                        $config['claude_model'] = $visionModel;
                    }
                    $response = $this->aiRouter->processWithImage($imageContent, $prompt, $config);
                    $this->recordSuccess('claude_cli_vision', microtime(true) - $startTime);

                    return [
                        'success' => true,
                        'response' => $response,
                        'provider' => 'claude_cli_vision',
                        'model' => 'claude',
                        'slot_id' => $slotId,
                        'error' => null,
                        'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                    ];
                } catch (Exception $e) {
                    $this->recordFailure('claude_cli_vision');
                    $attempts['claude_cli_vision'] = $e->getMessage();
                } finally {
                    $this->releaseClaudeSlot($slotId);
                }
            }
        } else {
            $attempts['claude_cli_vision'] = 'Circuit breaker open';
        }

        // Only send alert if not suppressed (caller has fallbacks to try)
        if (! $suppressAlert) {
            $this->sendFailureAlert($attempts, "Vision: $prompt");
        }

        return [
            'success' => false,
            'response' => null,
            'provider' => null,
            'error' => 'All vision providers failed',
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'attempts' => $attempts,
        ];
    }

    /**
     * Classify an image file (photos of documents, ID cards, receipts, etc.)
     *
     * Uses AI vision to analyze the image and classify what type of document it is.
     * Particularly useful for images like dl.jpg (driver's license), receipt photos, etc.
     *
     * @param  string  $filePath  Path to the image file
     * @param  array  $options  Optional settings
     * @return array Classification result with document_type, key_info, suggested_category, etc.
     */
    public function classifyImage(string $filePath, array $options = []): array
    {
        try {
            if (! file_exists($filePath)) {
                return ['success' => false, 'error' => 'File not found'];
            }

            $filename = basename($filePath);
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            // Read image content
            $imageContent = file_get_contents($filePath);
            if (! $imageContent) {
                return ['success' => false, 'error' => 'Failed to read image file'];
            }

            // Get the image classification prompt from database
            $defaultPrompt = "Analyze this image and classify it. Identify what type of document or content it shows.\n\nProvide:\n1. Document type classification\n2. Key information visible (names, dates, numbers, amounts)\n3. Suggested filing category\n4. Confidence level (high/medium/low)\n5. Is this a personal identity document that should be secured?\n\nFilename hint: {filename}\n\nRespond in JSON format.";

            $promptTemplate = $this->getPrompt('image_classification', $defaultPrompt);
            $prompt = str_replace('{filename}', $filename, $promptTemplate);

            // Process with vision AI
            $visionResult = $this->processImage($imageContent, $prompt);

            if (! $visionResult['success']) {
                return $visionResult;
            }

            // Try to parse JSON response
            $response = $visionResult['response'];
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $json = json_decode($matches[0], true);
                if ($json && is_array($json)) {
                    return [
                        'success' => true,
                        'classification' => $json,
                        'document_type' => $json['document_type'] ?? 'unknown',
                        'sub_type' => $json['sub_type'] ?? null,
                        'key_info' => $json['key_info'] ?? [],
                        'suggested_category' => $json['suggested_category'] ?? null,
                        'confidence' => $json['confidence'] ?? 'low',
                        'is_sensitive' => $json['is_sensitive'] ?? false,
                        'summary' => $json['summary'] ?? null,
                        'provider' => $visionResult['provider'],
                    ];
                }
            }

            // Fallback if JSON parsing fails
            return [
                'success' => true,
                'classification' => ['raw_response' => $response],
                'document_type' => 'unknown',
                'summary' => substr($response, 0, 500),
                'provider' => $visionResult['provider'],
            ];

        } catch (Exception $e) {
            Log::error('AIService: Image classification failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Estimate token count from text length.
     * Uses 1.5 chars/token — conservative for code/JSON (BPE worst case ≈1 char/token).
     * English prose is ~4 chars/token but structured data can be 1:1 or less.
     */
    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(strlen($text) / 1.5);
    }

    /**
     * Get the embedding context length from healthy instances.
     * Returns the limit from the first available instance, or default 8192.
     */
    private function getEmbeddingContextLimit(array $instances): int
    {
        foreach ($instances as $instance) {
            $limit = $instance['embedding_context_length'] ?? $instance['context_length'] ?? null;
            if ($limit) {
                return (int) $limit;
            }
        }

        return 8192; // Default: nomic-embed-text
    }

    /**
     * Split text into overlapping chunks for embedding.
     *
     * Uses hierarchical splitting: paragraphs → sentences → hard split.
     * Each chunk stays within 80% of token limit (safety margin).
     * 10% overlap between chunks for context continuity.
     *
     * @param  string  $text  The text to split
     * @param  int  $tokenLimit  Max tokens per chunk
     * @return array Array of chunk strings
     */
    private function splitForEmbedding(string $text, int $tokenLimit): array
    {
        $chunkCharLimit = (int) ($tokenLimit * 0.60 * 1.5); // 60% safety margin, 1.5 chars/token (handles code/JSON BPE worst-case)
        $overlapChars = (int) ($chunkCharLimit * 0.10);

        if (strlen($text) <= $chunkCharLimit) {
            return [$text];
        }

        $chunks = [];
        $currentChunk = '';

        // Split by paragraphs first (double newline)
        $paragraphs = preg_split('/\n\n+/', $text);

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // If adding this paragraph fits, accumulate
            if (strlen($currentChunk) + strlen($paragraph) + 2 <= $chunkCharLimit) {
                $currentChunk .= ($currentChunk !== '' ? "\n\n" : '').$paragraph;

                continue;
            }

            // Current chunk is full — save it
            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
                // Start next chunk with overlap from end of previous
                $currentChunk = $this->getOverlapText($currentChunk, $overlapChars);
            }

            // If this single paragraph fits in a chunk, start accumulating
            if (strlen($paragraph) <= $chunkCharLimit) {
                $currentChunk .= ($currentChunk !== '' ? "\n\n" : '').$paragraph;

                continue;
            }

            // Paragraph exceeds limit — split by sentences
            $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }

                if (strlen($currentChunk) + strlen($sentence) + 1 <= $chunkCharLimit) {
                    $currentChunk .= ($currentChunk !== '' ? ' ' : '').$sentence;

                    continue;
                }

                // Save current chunk
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                    $currentChunk = $this->getOverlapText($currentChunk, $overlapChars);
                }

                // If single sentence fits, start new chunk
                if (strlen($sentence) <= $chunkCharLimit) {
                    $currentChunk .= ($currentChunk !== '' ? ' ' : '').$sentence;

                    continue;
                }

                // Sentence exceeds limit — hard split at word boundaries
                $words = explode(' ', $sentence);
                foreach ($words as $word) {
                    // If single word exceeds limit (e.g., base64 blob), hard-split it
                    if (strlen($word) > $chunkCharLimit) {
                        if ($currentChunk !== '') {
                            $chunks[] = $currentChunk;
                            $currentChunk = '';
                        }
                        $wordParts = str_split($word, $chunkCharLimit);
                        foreach ($wordParts as $part) {
                            $chunks[] = $part;
                        }
                        $currentChunk = $this->getOverlapText(end($wordParts), $overlapChars);

                        continue;
                    }

                    if (strlen($currentChunk) + strlen($word) + 1 <= $chunkCharLimit) {
                        $currentChunk .= ($currentChunk !== '' ? ' ' : '').$word;
                    } else {
                        if ($currentChunk !== '') {
                            $chunks[] = $currentChunk;
                            $currentChunk = $this->getOverlapText($currentChunk, $overlapChars);
                        }
                        $currentChunk .= ($currentChunk !== '' ? ' ' : '').$word;
                    }
                }
            }
        }

        // Don't forget the last chunk
        if ($currentChunk !== '' && trim($currentChunk) !== '') {
            $chunks[] = $currentChunk;
        }

        // Safety: if no chunks produced (e.g., whitespace-only input), return original
        if (empty($chunks)) {
            return [trim($text) ?: $text];
        }

        return $chunks;
    }

    /**
     * Extract overlap text from the end of a chunk.
     * Tries to break at a sentence or word boundary.
     */
    private function getOverlapText(string $chunk, int $overlapChars): string
    {
        if (strlen($chunk) <= $overlapChars) {
            return $chunk;
        }

        $overlap = substr($chunk, -$overlapChars);

        // Try to start at a sentence boundary
        $sentenceStart = preg_match('/^.*?[.!?]\s+/s', $overlap, $matches);
        if ($sentenceStart && strlen($matches[0]) < strlen($overlap) * 0.8) {
            return substr($overlap, strlen($matches[0]));
        }

        // Fall back to word boundary
        $firstSpace = strpos($overlap, ' ');
        if ($firstSpace !== false && $firstSpace < strlen($overlap) * 0.3) {
            return substr($overlap, $firstSpace + 1);
        }

        return $overlap;
    }

    /**
     * Mean-pool multiple embeddings into a single vector, then L2-normalize.
     * Standard technique for combining chunk embeddings (LangChain, LlamaIndex, Sentence Transformers).
     *
     * @param  array  $embeddings  Array of embedding vectors (each is float[768])
     * @return array Single mean-pooled, L2-normalized embedding vector
     */
    private function meanPoolEmbeddings(array $embeddings): array
    {
        if (count($embeddings) === 1) {
            return $embeddings[0];
        }

        $dims = count($embeddings[0]);
        $result = array_fill(0, $dims, 0.0);

        // Element-wise sum
        foreach ($embeddings as $embedding) {
            for ($i = 0; $i < $dims; $i++) {
                $result[$i] += $embedding[$i];
            }
        }

        // Average
        $count = count($embeddings);
        for ($i = 0; $i < $dims; $i++) {
            $result[$i] /= $count;
        }

        // L2 normalize for cosine similarity
        $norm = 0.0;
        for ($i = 0; $i < $dims; $i++) {
            $norm += $result[$i] * $result[$i];
        }
        $norm = sqrt($norm);

        if ($norm > 0) {
            for ($i = 0; $i < $dims; $i++) {
                $result[$i] /= $norm;
            }
        } else {
            Log::warning('AIService: Zero-norm embedding detected during mean pooling', [
                'chunk_count' => count($embeddings),
                'dims' => $dims,
            ]);
        }

        return $result;
    }

    /**
     * Generate embedding with unified provider fallback chain.
     * Ollama instances tried first (local, preferred), then external APIs (Gemini, DeepInfra, etc.).
     * Auto-chunks oversized text per-provider and mean-pools the result for zero data loss.
     */
    public function generateEmbedding(string $content, array $options = []): array
    {
        $startTime = microtime(true);
        $allowCpuFallback = $options['allow_cpu_fallback'] ?? true;

        $providers = $this->getEmbeddingProviders();

        if (empty($providers)) {
            return [
                'success' => false,
                'embedding' => null,
                'provider' => null,
                'error' => 'No healthy embedding providers available',
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }

        // Embedding jobs are batch-heavy and more sensitive to a single degraded Ollama
        // endpoint than chat requests. Keep provider order stable and health-aware so a
        // weak primary does not get picked just because of round-robin rotation.
        $orderedProviders = $this->orderEmbeddingProviders($providers);

        foreach ($orderedProviders as $provider) {
            $isOllama = ($provider['instance_type'] ?? '') === 'ollama';

            // Per-provider context limit for chunking decisions
            $tokenLimit = (int) ($provider['embedding_context_length'] ?? 8192);
            $estimatedTokens = $this->estimateTokenCount($content);

            // Auto-chunk if text exceeds this provider's context limit (70% threshold guards against token underestimate for code/JSON)
            if ($estimatedTokens > (int) ($tokenLimit * 0.70)) {
                $result = $this->generateChunkedEmbeddingForProvider($content, $tokenLimit, $provider);
                if ($result['success']) {
                    return array_merge($result, [
                        'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                    ]);
                }

                // This provider failed chunked — try next provider
                continue;
            }

            // Standard single-embedding path
            if ($isOllama) {
                $result = $this->tryEmbeddingWithRetry($provider, $content);
            } else {
                $result = $this->tryExternalEmbeddingWithRetry($provider, $content);
            }

            if ($result['success']) {
                return array_merge($result, [
                    'chunked' => false,
                    'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                ]);
            }

            // If context length exceeded on Ollama, try chunked for this provider
            $errorMsg = $result['error'] ?? '';
            if ($isOllama && (str_contains($errorMsg, 'input length exceeds') || str_contains($errorMsg, 'context length'))) {
                Log::info('AIService: Token estimate undercount, falling back to chunked embedding', [
                    'provider' => $provider['id'] ?? $provider['name'] ?? 'unknown',
                    'text_length' => strlen($content),
                    'estimated_tokens' => $estimatedTokens,
                    'token_limit' => $tokenLimit,
                ]);
                $chunkedResult = $this->generateChunkedEmbeddingForProvider($content, $tokenLimit, $provider);
                if ($chunkedResult['success']) {
                    return array_merge($chunkedResult, [
                        'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                    ]);
                }
            }

            // Provider failed — continue to next
            Log::debug('AIService: Embedding provider failed, trying next', [
                'provider' => $provider['id'] ?? $provider['name'] ?? 'unknown',
                'type' => $provider['instance_type'] ?? 'unknown',
                'error' => $result['error'] ?? 'unknown',
            ]);
        }

        if (! $allowCpuFallback) {
            return [
                'success' => false,
                'embedding' => null,
                'provider' => null,
                'error' => 'All non-CPU embedding providers failed; CPU fallback disabled for this request',
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }

        // N49: CPU fallback — last resort when all providers are down
        $cpuResult = $this->tryCpuEmbeddingFallback($content);
        if ($cpuResult['success']) {
            Log::info('AIService: CPU embedding fallback succeeded', [
                'text_length' => strlen($content),
                'duration_ms' => $cpuResult['duration_ms'] ?? 0,
            ]);

            return array_merge($cpuResult, [
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);
        }

        return [
            'success' => false,
            'embedding' => null,
            'provider' => null,
            'error' => 'All embedding providers failed (Ollama + external + CPU fallback)',
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
        ];
    }

    public function hasNonCpuEmbeddingProvider(): bool
    {
        foreach ($this->getEmbeddingProviders() as $provider) {
            if (($provider['instance_type'] ?? '') !== 'ollama') {
                return true;
            }

            $circuitState = $provider['circuit_state'] ?? 'closed';
            $healthScore = (float) ($provider['health_score'] ?? 1);

            if ($circuitState === 'closed' && $healthScore > 0) {
                return true;
            }
        }

        return false;
    }

    private function orderEmbeddingProviders(array $providers): array
    {
        $ollamaProviders = array_values(array_filter($providers, fn ($p) => ($p['instance_type'] ?? '') === 'ollama'));
        $externalProviders = array_values(array_filter($providers, fn ($p) => ($p['instance_type'] ?? '') !== 'ollama'));

        usort($ollamaProviders, function (array $a, array $b) {
            $aBusy = $this->isOllamaBusy($a['id'] ?? null);
            $bBusy = $this->isOllamaBusy($b['id'] ?? null);
            if ($aBusy !== $bBusy) {
                return $aBusy <=> $bBusy;
            }

            $aPriority = (int) ($a['priority'] ?? 999);
            $bPriority = (int) ($b['priority'] ?? 999);
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            $aHealth = (float) ($a['health_score'] ?? 0);
            $bHealth = (float) ($b['health_score'] ?? 0);
            if ($aHealth !== $bHealth) {
                return $bHealth <=> $aHealth;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        usort($externalProviders, function (array $a, array $b) {
            $aPriority = (int) ($a['priority'] ?? 999);
            $bPriority = (int) ($b['priority'] ?? 999);
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        return array_merge($ollamaProviders, $externalProviders);
    }

    /**
     * Generate chunked embedding using a single provider.
     * Splits text, embeds each chunk via the given provider, mean-pools result.
     */
    private function generateChunkedEmbeddingForProvider(string $content, int $tokenLimit, array $provider): array
    {
        $startTime = microtime(true);
        $isOllama = ($provider['instance_type'] ?? '') === 'ollama';

        $chunks = $this->splitForEmbedding($content, $tokenLimit);
        $chunkCount = count($chunks);

        Log::info('AIService: Auto-chunked embedding for provider', [
            'provider' => $provider['id'] ?? $provider['name'] ?? 'unknown',
            'type' => $provider['instance_type'] ?? 'unknown',
            'original_chars' => strlen($content),
            'chunks' => $chunkCount,
            'token_limit' => $tokenLimit,
        ]);

        $embeddings = [];
        $resultProvider = null;
        $model = null;

        foreach ($chunks as $index => $chunk) {
            if ($isOllama) {
                $result = $this->tryEmbeddingWithRetry($provider, $chunk);
            } else {
                $result = $this->tryExternalEmbeddingWithRetry($provider, $chunk);
            }

            if (! $result['success']) {
                return [
                    'success' => false,
                    'embedding' => null,
                    'provider' => null,
                    'error' => "Chunk {$index}/{$chunkCount} failed on {$provider['id']}: ".($result['error'] ?? 'unknown'),
                    'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                ];
            }

            $embeddings[] = $result['embedding'];
            $resultProvider = $resultProvider ?? $result['provider'];
            $model = $model ?? $result['model'];
        }

        $pooledEmbedding = $this->meanPoolEmbeddings($embeddings);

        return [
            'success' => true,
            'embedding' => $pooledEmbedding,
            'provider' => $resultProvider,
            'model' => $model,
            'error' => null,
            'chunked' => true,
            'chunk_count' => $chunkCount,
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Process with MCP tools - Ollama-aware
     */
    public function processWithTools(string $prompt, array $config = [], int $maxIterations = 5): array
    {
        $startTime = microtime(true);

        // Tool-calling defaults to the `coding` role so the host advertising the
        // strongest coding model wins on score. Selection is fully table-driven:
        // LLMPoolManagerService::selectInstance() filters by capabilities, role
        // availability, health, and circuit state, then scores by health, latency,
        // success rate, priority, and role-fit. Local Ollama instances always
        // outscore external providers because externals are not in this pool at
        // all — they live in buildFallbackChain() and only kick in if local fails.
        $role = $config['model_role'] ?? 'coding';
        $instance = $this->selectOllamaInstanceForRole($role, $config);
        if ($instance !== null) {
            $perInstanceModel = $this->resolveModelForProvider($instance['id'], $role)
                ?? $this->resolveModelForProvider($instance['id'], 'standard')
                ?? $this->defaultModel;
            $ollamaStatus = $this->getOllamaStatus($perInstanceModel, $instance['url']);

            if ($ollamaStatus['available']) {
                $config['ai_timeout'] = $this->calculateTimeout($ollamaStatus, $perInstanceModel);
            }

            $config['instance_url'] = $instance['url'];
            $config['instance_model'] = $perInstanceModel;
        }

        try {
            $response = $this->aiRouter->processWithTools($prompt, $config, $maxIterations);

            return [
                'success' => true,
                'response' => $response,
                'provider' => 'ollama_tools',
                'error' => null,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        } catch (Exception $e) {
            $this->sendFailureAlert(['tools' => $e->getMessage()], $prompt);

            return [
                'success' => false,
                'response' => null,
                'provider' => null,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Process with MCP tools and stream responses - with fault tolerance
     *
     * Provides circuit breaker protection and automatic fallback for streaming:
     * 1. Checks Ollama availability before streaming
     * 2. Falls back to non-streaming Claude if Ollama fails
     * 3. Records failures for circuit breaker
     *
     * @param  string  $prompt  User prompt
     * @param  array  $config  Configuration (temperature, max_tokens, system_prompt)
     * @param  int  $maxIterations  Maximum tool calling iterations
     * @return \Generator Yields SSE-formatted chunks
     */
    public function processWithToolsStreaming(string $prompt, array $config = [], int $maxIterations = 5): \Generator
    {
        $providerId = 'ollama_primary';
        $requestId = uniqid('stream_', true);

        // Check circuit breaker first
        if ($this->isCircuitOpen($providerId)) {
            Log::info('AIService: Streaming circuit open, falling back to non-streaming Claude');
            yield from $this->streamingFallbackToClaude($prompt, $config);

            return;
        }

        // Streaming defaults to the `standard` role. selectOllamaInstanceForRole()
        // routes table-driven: the host whose standard model scores best wins.
        // If no local instance is available the existing cascade falls through to
        // streamingFallbackToClaude().
        $role = $config['model_role'] ?? 'standard';
        $instance = $this->selectOllamaInstanceForRole($role, $config);
        if ($instance === null) {
            Log::info('AIService: No healthy Ollama instances for streaming, falling back to Claude');
            yield from $this->streamingFallbackToClaude($prompt, $config);

            return;
        }

        $streamModel = $config['model']
            ?? $this->resolveModelForProvider($instance['id'], $role)
            ?? $this->resolveModelForProvider($instance['id'], 'standard')
            ?? $this->defaultModel;
        $config['instance_url'] = $instance['url'];
        $config['instance_model'] = $streamModel;
        $ollamaStatus = $this->getOllamaStatus($streamModel, $instance['url']);

        if (! $ollamaStatus['available']) {
            $this->recordFailure($providerId);
            Log::info('AIService: Ollama not available for streaming, falling back to Claude');
            yield from $this->streamingFallbackToClaude($prompt, $config);

            return;
        }

        // Check if Ollama is busy
        if ($this->isOllamaBusy()) {
            Log::info('AIService: Ollama busy, falling back to Claude for streaming');
            yield from $this->streamingFallbackToClaude($prompt, $config);

            return;
        }

        // Try to acquire busy lock
        if (! $this->acquireOllamaBusyLock($requestId)) {
            Log::info('AIService: Could not acquire Ollama lock for streaming, falling back to Claude');
            yield from $this->streamingFallbackToClaude($prompt, $config);

            return;
        }

        try {
            // Set timeout based on model state
            $config['ai_timeout'] = $this->calculateTimeout($ollamaStatus, $streamModel);

            // Yield start event
            yield json_encode(['type' => 'provider', 'provider' => 'ollama', 'model' => $streamModel])."\n";

            // Stream from AIRouter
            $hasContent = false;
            $startTime = microtime(true);

            foreach ($this->aiRouter->processWithToolsStreaming($prompt, $config, $maxIterations) as $chunk) {
                $hasContent = true;
                yield $chunk;

                // Check for error in chunk
                $decoded = json_decode(trim($chunk), true);
                if ($decoded && ($decoded['type'] ?? '') === 'error') {
                    $this->recordFailure($providerId);
                    break;
                }
            }

            if ($hasContent) {
                $this->recordSuccess($providerId, microtime(true) - $startTime);
            }

        } catch (\Exception $e) {
            $this->recordFailure($providerId);
            $correlationId = substr(uniqid('fallback_', true), 0, 24);
            Log::error('AIService: Streaming failed, falling back to Claude', [
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId,
                'provider' => $providerId,
            ]);

            // Yield error and fall back
            yield json_encode(['type' => 'error', 'content' => 'Ollama streaming failed: '.$e->getMessage()])."\n";
            yield json_encode(['type' => 'fallback', 'provider' => 'claude'])."\n";

            yield from $this->streamingFallbackToClaude($prompt, $config, $correlationId);

        } finally {
            $this->releaseOllamaBusyLock($requestId);
        }
    }

    /**
     * Fallback to Claude for streaming requests
     *
     * Since Claude CLI doesn't support true streaming, this simulates streaming
     * by processing the request and yielding the response in chunks.
     *
     * @param  string  $prompt  User prompt
     * @param  array  $config  Configuration options
     * @return \Generator Yields SSE-formatted chunks
     */
    private function streamingFallbackToClaude(string $prompt, array $config = [], ?string $correlationId = null): \Generator
    {
        yield json_encode(['type' => 'provider', 'provider' => 'claude', 'model' => 'claude'])."\n";
        yield json_encode(['type' => 'fallback_notice', 'message' => 'Using Claude fallback (non-streaming)'])."\n";

        try {
            // Resolve model via role (standard by default for streaming fallback)
            $role = $config['model_role'] ?? 'standard';
            $resolvedModel = $this->resolveModelForProvider('claude_cli', $role);
            $claudeConfig = array_merge($config, ['ai_mode' => 'claude']);
            if ($resolvedModel) {
                $claudeConfig['claude_model'] = $resolvedModel;
            }
            // Use processWithTools for Claude (non-streaming but with tool support)
            $result = $this->processWithTools($prompt, $claudeConfig);

            if ($result['success']) {
                // Simulate streaming by chunking the response
                $response = $result['response'];
                $chunkSize = 50; // Characters per chunk

                for ($i = 0; $i < strlen($response); $i += $chunkSize) {
                    $chunk = substr($response, $i, $chunkSize);
                    yield json_encode(['type' => 'content', 'content' => $chunk])."\n";
                    usleep(10000); // 10ms delay between chunks for streaming effect
                }

                yield json_encode(['type' => 'done', 'content' => $response])."\n";
            } else {
                yield json_encode(['type' => 'error', 'content' => $result['error'] ?? 'Claude processing failed'])."\n";
            }

        } catch (\Exception $e) {
            Log::error('AIService: Claude fallback also failed', [
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);
            yield json_encode(['type' => 'error', 'content' => 'All providers failed: '.$e->getMessage()])."\n";
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // RETRY WITH EXPONENTIAL BACKOFF
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Try Ollama instance with exponential backoff retry
     * Uses busy lock to prevent concurrent requests to single-GPU Ollama
     */
    private function tryOllamaWithRetry(array $instance, string $prompt, string $model, array $config): array
    {
        $providerId = $instance['id'];
        $requestId = uniqid('ollama_', true);

        // Check circuit breaker
        if ($this->isCircuitOpen($providerId)) {
            return [
                'success' => false,
                'error' => "Circuit open for {$instance['name']} (cooldown)",
                'skip_to_fallback' => true,
            ];
        }

        // Check if this specific Ollama instance is busy with another request
        // Now supports per-instance busy locks for multiple Ollama servers
        $skipIfBusy = $config['skip_if_busy'] ?? false;
        if ($skipIfBusy && $this->isOllamaBusy($providerId)) {
            $busyInfo = $this->getOllamaBusyInfo($providerId);
            Log::info('AIService: Ollama instance busy, skipping to fallback', [
                'instance_id' => $providerId,
                'busy_since' => $busyInfo['started_at'] ?? 'unknown',
                'busy_request' => $busyInfo['request_id'] ?? 'unknown',
            ]);

            return [
                'success' => false,
                'error' => "Ollama instance {$providerId} busy with another request",
                'skip_to_fallback' => true,
            ];
        }

        // Try to acquire busy lock for this specific instance (non-blocking if skip_if_busy)
        if (! $this->acquireOllamaBusyLock($requestId, $providerId)) {
            if ($skipIfBusy) {
                return [
                    'success' => false,
                    'error' => "Could not acquire lock for {$providerId}",
                    'skip_to_fallback' => true,
                ];
            }
            // Wait for lock if not skipping
            $lockWaitSeconds = max(1, (int) ($config['ollama_lock_wait_seconds'] ?? 30));
            $pollIntervalUs = max(100000, (int) ($config['ollama_lock_poll_us'] ?? 500000));
            $waitStart = microtime(true);
            while (! $this->acquireOllamaBusyLock($requestId, $providerId)) {
                if ((microtime(true) - $waitStart) > $lockWaitSeconds) {
                    return [
                        'success' => false,
                        'error' => "Timeout waiting for lock on {$providerId}",
                        'skip_to_fallback' => true,
                    ];
                }
                usleep($pollIntervalUs);
            }
        }

        try {
            // Get Ollama status for this instance
            $ollamaStatus = $this->getOllamaStatus($model, $instance['url']);

            if (! $ollamaStatus['available']) {
                $this->recordFailure($providerId);

                return [
                    'success' => false,
                    'error' => 'Ollama not available: '.($ollamaStatus['error'] ?? 'connection failed'),
                    'skip_to_fallback' => true, // Skip directly to Claude, don't waste time on retries
                ];
            }

            if (array_key_exists('model_installed', $ollamaStatus) && ! $ollamaStatus['model_installed']) {
                return [
                    'success' => false,
                    'error' => "Model '{$model}' not installed on {$instance['name']}",
                    'skip_to_fallback' => true,
                ];
            }

            // Calculate timeout based on model state
            $timeout = $this->resolveOllamaTimeout(
                $ollamaStatus,
                $this->calculateTimeout($ollamaStatus, $model),
                $config['ai_timeout'] ?? null
            );
            $config['ai_timeout'] = $timeout;
            $config['ai_mode'] = 'local';

            // Pre-warm model if not loaded (cold start or model swap needed)
            if (! $ollamaStatus['model_loaded']) {
                Log::info('AIService: Model not loaded, pre-warming', [
                    'model' => $model,
                    'needs_swap' => $ollamaStatus['needs_swap'] ?? false,
                    'current_model' => $ollamaStatus['current_model'] ?? 'none',
                ]);
                $preWarmResult = $this->preWarmModel($model, $instance['url']);
                if (($preWarmResult['status'] ?? 'failed') === 'failed') {
                    Log::warning('AIService: Pre-warm failed, continuing with extended timeout', [
                        'reason' => $preWarmResult['reason'] ?? 'unknown',
                    ]);
                }
            }

            // Retry loop with exponential backoff
            $lastError = null;
            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    Log::info("AIService: Ollama attempt {$attempt}/".self::MAX_RETRIES, [
                        'instance' => $instance['name'],
                        'model' => $model,
                        'model_loaded' => $ollamaStatus['model_loaded'],
                        'timeout' => $timeout,
                        'request_id' => $requestId,
                    ]);

                    $startTime = microtime(true);
                    // Pass instance URL so AIRouter routes to the correct Ollama
                    $config['ollama_url'] = $instance['url'];
                    $config['ollama_model'] = $model;
                    $response = $this->aiRouter->processWithAI($prompt, $config);

                    $this->recordSuccess($providerId, microtime(true) - $startTime);

                    return [
                        'success' => true,
                        'response' => $response,
                        'provider' => $providerId,
                        'model' => $model,
                        'instance' => $instance['name'],
                        'attempt' => $attempt,
                        'model_was_loaded' => $ollamaStatus['model_loaded'],
                        'error' => null,
                    ];

                } catch (Exception $e) {
                    // Convert to typed exception if not already
                    $typed = $e instanceof AIServiceException
                        ? $e
                        : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $model);

                    $lastError = $typed->getMessage();

                    Log::warning("AIService: Ollama attempt {$attempt} failed", [
                        'instance' => $instance['name'],
                        'error' => $lastError,
                        'exception_type' => get_class($typed),
                        'retryable' => $typed->isRetryable(),
                        'will_retry' => $typed->isRetryable() && $attempt < self::MAX_RETRIES,
                    ]);

                    // Don't retry permanent errors
                    if (! $typed->isRetryable()) {
                        break;
                    }

                    // Use exception-suggested backoff or calculate default
                    if ($attempt < self::MAX_RETRIES) {
                        $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                        Log::info("AIService: Backing off {$backoffMs}ms before retry");
                        usleep($backoffMs * 1000);
                    }
                }
            }

            $this->recordFailure($providerId, $lastError);

            return [
                'success' => false,
                'error' => $lastError ?? 'Unknown error',
            ];

        } finally {
            // Always release the busy lock for this specific instance
            $this->releaseOllamaBusyLock($requestId, $providerId);
        }
    }

    /**
     * Try Ollama vision with exponential backoff
     * Uses per-instance busy lock to prevent concurrent requests to single-GPU Ollama
     */
    private function tryOllamaVisionWithRetry(array $instance, string $imageContent, string $prompt, array $config): array
    {
        $baseProviderId = $instance['id'];
        $providerId = $baseProviderId.'_vision';
        $requestId = uniqid('ollama_vision_', true);

        if ($this->isCircuitOpen($providerId)) {
            return ['success' => false, 'error' => 'Circuit open', 'skip_to_fallback' => true];
        }

        // Check if this specific Ollama instance is busy - for vision, always skip to Claude if busy
        // (vision operations take longer and we don't want to block)
        if ($this->isOllamaBusy($baseProviderId)) {
            $busyInfo = $this->getOllamaBusyInfo($baseProviderId);
            Log::info('AIService: Ollama instance busy, skipping vision to Claude fallback', [
                'instance_id' => $baseProviderId,
                'busy_since' => $busyInfo['started_at'] ?? 'unknown',
            ]);

            return ['success' => false, 'error' => "Ollama instance {$baseProviderId} busy", 'skip_to_fallback' => true];
        }

        // Acquire busy lock for this specific instance
        if (! $this->acquireOllamaBusyLock($requestId, $baseProviderId)) {
            return ['success' => false, 'error' => "Could not acquire lock for {$baseProviderId}", 'skip_to_fallback' => true];
        }

        try {
            // Resolve vision model for THIS instance rather than using the primary's
            // vision model — the 4070 can host a stronger vision model than the 1060.
            $perInstanceVisionModel = $this->resolveModelForProvider($instance['id'], 'vision')
                ?? $this->visionModel;

            $ollamaStatus = $this->getOllamaStatus($perInstanceVisionModel, $instance['url']);

            if (! $ollamaStatus['available']) {
                $this->recordFailure($providerId, 'Ollama not available');

                return ['success' => false, 'error' => 'Ollama not available', 'skip_to_fallback' => true];
            }

            $timeout = $this->applyCallerTimeoutCap(
                $this->calculateTimeout($ollamaStatus, $perInstanceVisionModel),
                $config['timeout'] ?? null
            );
            $config['timeout'] = $timeout;
            $config['ai_mode'] = 'local';
            $config['instance_url'] = $instance['url'];
            $config['instance_model'] = $perInstanceVisionModel;

            $lastError = null;
            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    $startTime = microtime(true);
                    $response = $this->aiRouter->processWithImage($imageContent, $prompt, $config);
                    $this->recordSuccess($providerId, microtime(true) - $startTime);

                    return [
                        'success' => true,
                        'response' => $response,
                        'provider' => $providerId,
                        'model' => $perInstanceVisionModel,
                        'attempt' => $attempt,
                        'error' => null,
                    ];
                } catch (Exception $e) {
                    $typed = $e instanceof AIServiceException
                        ? $e
                        : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $perInstanceVisionModel);
                    $lastError = $typed->getMessage();
                    if (! $typed->isRetryable() || $attempt >= self::MAX_RETRIES) {
                        break;
                    }
                    $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                    usleep($backoffMs * 1000);
                }
            }

            $this->recordFailure($providerId, $lastError);

            return ['success' => false, 'error' => $lastError];

        } finally {
            $this->releaseOllamaBusyLock($requestId, $baseProviderId);
        }
    }

    /**
     * Try embedding with retry
     */
    private function tryEmbeddingWithRetry(array $instance, string $content): array
    {
        $providerId = $instance['id'].'_embedding';

        if ($this->isCircuitOpen($providerId)) {
            return ['success' => false, 'error' => 'Circuit open', 'skip_to_fallback' => true];
        }

        // Embeddings use nomic-embed-text which is lightweight (~137MB VRAM) and can
        // coexist with inference models on the GPU. No busy lock needed — this enables
        // both Ollama instances to handle embeddings simultaneously during batch operations.
        $baseInstanceId = $instance['id']; // e.g. 'ollama_primary' - for pool manager metrics

        try {
            $ollamaStatus = $this->getOllamaStatus($this->embeddingModel, $instance['url']);

            if (! $ollamaStatus['available']) {
                $this->recordFailure($providerId);

                return ['success' => false, 'error' => 'Ollama not available', 'skip_to_fallback' => true];
            }

            $embeddingTimeout = $this->resolveEmbeddingTimeoutSeconds($instance, $ollamaStatus);

            $lastError = null;
            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    $startTime = microtime(true);
                    $embedding = $this->aiRouter->generateEmbedding(
                        $content,
                        $instance['url'] ?? null,
                        null,
                        $embeddingTimeout
                    );
                    $duration = microtime(true) - $startTime;
                    $this->recordSuccess($providerId, $duration);
                    // Also record on the base instance ID so pool manager updates llm_instances table
                    $this->recordSuccess($baseInstanceId, $duration);

                    return [
                        'success' => true,
                        'embedding' => $embedding,
                        'provider' => 'ollama',
                        'model' => $this->embeddingModel,
                        'error' => null,
                    ];
                } catch (Exception $e) {
                    $typed = $e instanceof AIServiceException
                        ? $e
                        : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $this->embeddingModel);
                    $lastError = $typed->getMessage();
                    if (! $typed->isRetryable() || $attempt >= self::MAX_RETRIES) {
                        break;
                    }
                    $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                    usleep($backoffMs * 1000);
                }
            }

            // Context-length errors are client-side miscalculations, not provider failures.
            // Do NOT open the circuit breaker — the caller will retry with chunking.
            $isContextLength = $lastError && (
                str_contains($lastError, 'input length exceeds') ||
                str_contains($lastError, 'context length')
            );
            if (! $isContextLength) {
                $this->recordFailure($providerId);
            }

            return ['success' => false, 'error' => $lastError, 'context_length_exceeded' => $isContextLength];

        } catch (\Exception $e) {
            $this->recordFailure($providerId);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function resolveEmbeddingTimeoutSeconds(array $instance, array $ollamaStatus): int
    {
        $defaultEmbeddingTimeout = (int) config('services.ollama.embedding_timeout', 10);
        $avgResponseMs = max(0.0, (float) ($instance['avg_response_ms'] ?? 0));
        $latencyFloorSeconds = $avgResponseMs > 0
            ? (int) ceil(($avgResponseMs / 1000) * 4)
            : $defaultEmbeddingTimeout;

        if (! ($ollamaStatus['model_loaded'] ?? false)) {
            return max($defaultEmbeddingTimeout, $latencyFloorSeconds, 20);
        }

        return max($defaultEmbeddingTimeout, min($latencyFloorSeconds, 20));
    }

    /**
     * Try external embedding provider via OpenAI-compatible /v1/embeddings API.
     * Mirrors tryEmbeddingWithRetry() but routes through AIRouter::generateExternalEmbedding().
     *
     * @param  array  $provider  Provider config from getEmbeddingProviders()
     * @param  string  $content  Text to embed
     * @return array Result with success, embedding, provider, model, error keys
     */
    private function tryExternalEmbeddingWithRetry(array $provider, string $content): array
    {
        $providerId = $provider['id'].'_embedding';

        if ($this->isCircuitOpen($providerId)) {
            return ['success' => false, 'error' => 'Circuit open', 'skip_to_fallback' => true];
        }

        // Check rate limit
        if ($provider['rate_limit_rpm'] ?? null) {
            $rpmKey = "rpm_count_{$provider['id']}";
            $currentRpm = Cache::get($rpmKey, 0);
            if ($currentRpm >= $provider['rate_limit_rpm']) {
                return ['success' => false, 'error' => "Rate limit exhausted for {$provider['name']}", 'skip_to_fallback' => true];
            }
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $startTime = microtime(true);

                $embedding = $this->aiRouter->generateExternalEmbedding($content, [
                    'base_url' => $provider['base_url'],
                    'api_key' => $provider['api_key'],
                    'embedding_model' => $provider['embedding_model'],
                    'embedding_dimensions' => $provider['embedding_dimensions'] ?? null,
                    'extra_headers' => $provider['extra_headers'] ?? [],
                    'instance_id' => $provider['id'],
                ]);

                $duration = microtime(true) - $startTime;

                // Validate dimension compatibility (768 for pgvector)
                if (count($embedding) !== 768) {
                    Log::warning('AIService: External embedding dimension mismatch', [
                        'provider' => $provider['id'],
                        'got' => count($embedding),
                        'expected' => 768,
                    ]);

                    return ['success' => false, 'error' => 'Dimension mismatch: got '.count($embedding).', expected 768'];
                }

                $this->recordSuccess($providerId, $duration);
                $this->recordSuccess($provider['id'], $duration);

                // Track rate limit
                if ($provider['rate_limit_rpm'] ?? null) {
                    Cache::increment("rpm_count_{$provider['id']}");
                }

                return [
                    'success' => true,
                    'embedding' => $embedding,
                    'provider' => $provider['id'],
                    'model' => $provider['embedding_model'],
                    'error' => null,
                ];

            } catch (\Exception $e) {
                $typed = $e instanceof \App\Exceptions\AI\AIServiceException
                    ? $e
                    : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $provider['embedding_model'] ?? 'unknown');
                $lastError = $typed->getMessage();

                if (! $typed->isRetryable() || $attempt >= self::MAX_RETRIES) {
                    break;
                }

                $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                usleep($backoffMs * 1000);
            }
        }

        $this->recordFailure($providerId);

        return ['success' => false, 'error' => $lastError];
    }

    /**
     * N49: CPU embedding fallback using BAAI/bge-base-en-v1.5 via Python.
     * Last-resort when all Ollama + external embedding providers are down.
     * Runs on CPU only (no GPU dependency). First call downloads model (~440MB).
     */
    private function tryCpuEmbeddingFallback(string $content): array
    {
        $startTime = microtime(true);
        $scriptPath = base_path('scripts/embeddings/cpu_embedding.py');
        $process = null;
        $pipes = [];

        if (! file_exists($scriptPath)) {
            return ['success' => false, 'error' => 'CPU embedding script not found'];
        }

        // Truncate to ~512 tokens worth of text (bge-base-en-v1.5 max sequence = 512)
        $maxChars = 512 * 4; // ~4 chars per token for English
        $text = mb_substr($content, 0, $maxChars);

        try {
            $process = proc_open(
                ['python3', $scriptPath, '--text', $text],
                [
                    0 => ['pipe', 'r'],  // stdin
                    1 => ['pipe', 'w'],  // stdout
                    2 => ['pipe', 'w'],  // stderr
                ],
                $pipes
            );

            if (! is_resource($process)) {
                return ['success' => false, 'error' => 'Failed to start CPU embedding process'];
            }

            fclose($pipes[0]);

            // Set non-blocking so we can enforce a timeout
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $timeout = 90; // seconds — first run may download model
            $deadline = microtime(true) + $timeout;

            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                $remaining = max(0.0, $deadline - microtime(true));
                $seconds = (int) floor($remaining);
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);

                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                $changed = @stream_select($read, $write, $except, $seconds, $microseconds);

                if ($changed === false) {
                    break;
                }

                if ($changed > 0) {
                    foreach ($read as $pipe) {
                        $chunk = stream_get_contents($pipe);
                        if ($chunk !== false && $chunk !== '') {
                            if ($pipe === $pipes[1]) {
                                $stdout .= $chunk;
                            } else {
                                $stderr .= $chunk;
                            }
                        }
                    }
                }

                if (! $status['running']) {
                    break;
                }
            }

            // Final read
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_get_status($process);
            if ($status['running']) {
                // Timed out — kill process
                proc_terminate($process, 9);
                proc_close($process);
                $process = null;

                return ['success' => false, 'error' => "CPU embedding timed out after {$timeout}s"];
            }

            $exitCode = $status['exitcode'];
            if ($exitCode === -1) {
                $exitCode = proc_close($process);
            } else {
                proc_close($process);
            }
            $process = null;
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($exitCode !== 0) {
                Log::warning('AIService: CPU embedding fallback failed', [
                    'exit_code' => $exitCode,
                    'stderr' => mb_substr($stderr, 0, 500),
                    'duration_ms' => $durationMs,
                ]);

                return ['success' => false, 'error' => 'CPU embedding process failed: '.mb_substr($stderr, 0, 200)];
            }

            $result = json_decode($stdout, true);
            if (! $result || ! isset($result['embedding'])) {
                return ['success' => false, 'error' => 'CPU embedding returned invalid JSON'];
            }

            $embedding = $result['embedding'];
            $dimension = count($embedding);

            if ($dimension !== 768) {
                return ['success' => false, 'error' => "CPU embedding dimension mismatch: {$dimension} (expected 768)"];
            }

            return [
                'success' => true,
                'embedding' => $embedding,
                'provider' => 'cpu_fallback',
                'model' => $result['model'] ?? 'BAAI/bge-base-en-v1.5',
                'chunked' => false,
                'duration_ms' => $durationMs,
            ];
        } catch (\Throwable $e) {
            Log::warning('AIService: CPU embedding fallback exception', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'CPU embedding exception: '.$e->getMessage()];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
        }
    }

    /**
     * Preserve the normal process environment when adding Claude CLI auth.
     */
    private function buildClaudeCliEnv(): ?array
    {
        $token = config('services.anthropic.cli_oauth_token');
        if (! $token) {
            return null;
        }

        $env = $_ENV;
        foreach (['HOME', 'PATH', 'USER', 'LOGNAME', 'SHELL', 'LANG', 'TERM', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'PWD'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== null && $value !== '') {
                $env[$key] = $value;
            }
        }

        $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;

        return $env;
    }

    /**
     * Try Claude CLI with slot management for concurrency control
     * Supports multiple parallel Claude calls with auto-scaling
     */
    private function tryClaudeCLI(string $prompt, array $config): array
    {
        $providerId = 'claude_cli';

        if (! $this->isClaudeCliEnabled()) {
            return ['success' => false, 'error' => 'Claude CLI is disabled'];
        }

        // Pre-flight: fast-fail on expired OAuth token instead of burning a slot + timeout
        $tokenStatus = app(LLMPoolManagerService::class)->checkClaudeTokenExpiry();
        if ($tokenStatus === 'expired') {
            Log::warning('AIService: Claude CLI OAuth token expired — skipping provider');

            return ['success' => false, 'error' => 'Claude CLI OAuth token expired. Run: claude login'];
        }

        $requestId = uniqid('claude_text_', true);

        // Try to acquire a slot
        $slotId = $this->acquireClaudeSlot($requestId);

        if ($slotId === null) {
            // No slots available
            return [
                'success' => false,
                'error' => 'No Claude slots available',
                'slot_usage' => $this->getClaudeSlotUsage(),
            ];
        }

        try {
            $config['ai_mode'] = 'claude';

            // AI-11: Build model fallback chain from DB roles.
            // Primary model from requested role, fallback to 'fast' role on rate limit.
            // Separate per-model rate limits on the Claude CLI mean haiku may work when sonnet is capped.
            $role = $config['model_role'] ?? 'standard';
            $primaryModel = $this->resolveModelForProvider('claude_cli', $role);
            $fallbackModel = ($role !== 'fast') ? $this->resolveModelForProvider('claude_cli', 'fast') : null;
            // Only use fallback if it's a different model
            if ($fallbackModel && $fallbackModel === $primaryModel) {
                $fallbackModel = null;
            }

            $modelsToTry = array_filter([$primaryModel, $fallbackModel]);
            if (empty($modelsToTry)) {
                $modelsToTry = [null]; // No model resolved — let CLI use its default
            }

            $lastError = null;
            $usedFallback = false;

            foreach ($modelsToTry as $modelIndex => $model) {
                if ($model) {
                    $config['claude_model'] = $model;
                }

                for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                    try {
                        $startTime = microtime(true);
                        $response = $this->aiRouter->processWithAI($prompt, $config);
                        $this->recordSuccess($providerId, microtime(true) - $startTime);

                        return [
                            'success' => true,
                            'response' => $response,
                            'provider' => 'claude_cli',
                            'model' => $model ?? 'claude',
                            'attempt' => $attempt,
                            'slot_id' => $slotId,
                            'used_fallback' => $usedFallback,
                            'error' => null,
                        ];
                    } catch (Exception $e) {
                        $typed = $e instanceof AIServiceException
                            ? $e
                            : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $model ?? 'claude');
                        $lastError = $typed->getMessage();

                        // On rate limit with fallback available, skip retries and try next model
                        if ($typed instanceof \App\Exceptions\AI\RateLimitException && $modelIndex === 0 && $fallbackModel) {
                            Log::info('AIService: claude_cli primary model rate-limited, falling back', [
                                'primary' => $primaryModel,
                                'fallback' => $fallbackModel,
                                'error' => $lastError,
                            ]);
                            $usedFallback = true;
                            break; // Exit retry loop, continue to next model in outer loop
                        }

                        if (! $typed->isRetryable() || $attempt >= self::MAX_RETRIES) {
                            break;
                        }
                        $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                        usleep($backoffMs * 1000);
                    }
                }
            }

            // Skip circuit breaker increment for rate limits — they're transient and
            // opening the circuit would block all Claude CLI access until cooldown.
            // Also skip during cascade (3+ external providers rate-limited simultaneously).
            $isRateLimit = isset($typed) && $typed instanceof \App\Exceptions\AI\RateLimitException;
            $allRateLimited = $this->countActiveRateLimitedProviders() >= 3;

            if ($isRateLimit) {
                Log::info('AIService: claude_cli rate-limited — skipping circuit increment (transient)', [
                    'error' => $lastError,
                    'used_fallback' => $usedFallback,
                ]);
            } elseif ($allRateLimited) {
                Log::warning('AIService: claude_cli failure during provider rate-limit cascade — skipping circuit increment', [
                    'error' => $lastError,
                ]);
            } else {
                $this->recordFailure($providerId);
            }

            return ['success' => false, 'error' => $lastError, 'used_fallback' => $usedFallback];

        } finally {
            $this->releaseClaudeSlot($slotId);
        }
    }

    /**
     * Count external providers with active rate_limit_ cache (simultaneous 429 storm indicator).
     */
    private function countActiveRateLimitedProviders(): int
    {
        $count = 0;
        foreach ($this->getExternalApiProviders() as $provider) {
            if (Cache::has('rate_limit_'.$provider['id'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Build the fallback chain: external API providers + Claude CLI, ordered by priority.
     *
     * Reads active external providers from llm_instances table and interleaves them
     * with Claude CLI based on priority (lower = tried first).
     *
     * @param  bool  $sensitiveData  If true, skip providers marked as not safe for sensitive data
     * @param  bool  $skipClaude  If true, omit Claude CLI from chain (already tried)
     * @return array Ordered list of providers with type, config, priority
     */
    private function buildFallbackChain(bool $sensitiveData = false, bool $skipClaude = false): array
    {
        // Offline kill switch: when enabled, NO external providers run — full
        // stop. Cloud APIs and Claude CLI are both blocked; local Ollama
        // instances continue to operate via the non-fallback routing path.
        // Fail-closed: if the lookup errors, isOfflineMode() returns true so
        // personal data never leaks through the fallback chain by accident.
        if ($this->isOfflineMode()) {
            return [];
        }

        // 3b3 profile enforcement + Defect A (2026-04-19) per-provider gate:
        //   (a) instance_type allowlist — coarse first filter
        //   (b) per-provider sensitive_safe filter when the active profile
        //       allows `cloud_sensitive_safe` but NOT `cloud_external`.
        //       instance_type='custom' covers both privacy postures — only
        //       the provider's real `sensitive_safe` flag is authoritative.
        $profile = $this->getActiveProfile();
        $profileAllowedTypes = null;
        if ($profile !== null && isset($profile['allowed_instance_types']) && is_array($profile['allowed_instance_types'])) {
            $profileAllowedTypes = $profile['allowed_instance_types'];
        }

        $profileProviderClasses = is_array($profile['allowed_provider_classes'] ?? null)
            ? $profile['allowed_provider_classes']
            : null;
        $requireSensitiveSafe = $profileProviderClasses !== null
            && in_array('cloud_sensitive_safe', $profileProviderClasses, true)
            && ! in_array('cloud_external', $profileProviderClasses, true);

        $chain = [];

        // Add external API providers from DB
        foreach ($this->getExternalApiProviders() as $provider) {
            // Skip providers unsafe for sensitive data (caller-level)
            if ($sensitiveData && ! ($provider['sensitive_safe'] ?? false)) {
                continue;
            }

            // Profile instance_type gate
            if ($profileAllowedTypes !== null && ! in_array($provider['instance_type'] ?? '', $profileAllowedTypes, true)) {
                continue;
            }

            // Defect A fix: profile-level per-provider sensitive_safe gate.
            // Under hybrid_review / hybrid_dev_assist / cloud_escalation_only
            // the provider's real `sensitive_safe` flag is the authoritative
            // test — `custom` instance_type alone is not sufficient.
            if ($requireSensitiveSafe && ! ($provider['sensitive_safe'] ?? false)) {
                continue;
            }

            // Skip providers with open circuits (pre-filter to avoid wasted attempts)
            if ($this->isCircuitOpen($provider['id'])) {
                continue;
            }

            // Check rate limit — skip if exhausted
            $rateLimitKey = 'rate_limit_'.$provider['id'];
            if (Cache::has($rateLimitKey)) {
                continue;
            }

            $chain[] = array_merge($provider, ['type' => 'external_api']);
        }

        // Add Claude CLI — read priority, latency, success_rate from DB for scoring
        if (! $skipClaude) {
            $claudeData = ['priority' => 20, 'avg_response_ms' => 30000, 'success_rate' => 80, 'consecutive_failures' => 0];
            try {
                $row = DB::selectOne("SELECT is_active, priority, avg_response_ms, success_rate, consecutive_failures FROM llm_instances WHERE instance_id = 'claude_cli'");
                if ($row) {
                    $claudeData = [
                        'priority' => (int) $row->priority,
                        'avg_response_ms' => (float) ($row->avg_response_ms ?? 30000),
                        'success_rate' => (float) ($row->success_rate ?? 80),
                        'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('AIService: claude_cli lookup failed, using defaults', ['error' => $e->getMessage()]);
            }

            $claudeAllowedByProfile = $profileAllowedTypes === null
                || in_array('claude_cli', $profileAllowedTypes, true);

            if ($claudeAllowedByProfile && $this->isClaudeCliEnabled()) {
                $chain[] = array_merge($claudeData, [
                    'type' => 'claude_cli',
                    'id' => 'claude_cli',
                ]);
            }
        }

        // INF-8: Dynamic provider ordering — composite score of priority, latency, and health
        // Lower score = tried first. Weights: priority 40%, latency 30%, failures 30%
        usort($chain, function ($a, $b) {
            $scoreA = $this->calculateProviderScore($a);
            $scoreB = $this->calculateProviderScore($b);

            return $scoreA <=> $scoreB;
        });

        return $chain;
    }

    private function isClaudeCliEnabled(): bool
    {
        // Defence in depth: offline mode blocks Claude CLI even if a caller
        // reaches this directly outside buildFallbackChain().
        if ($this->isOfflineMode()) {
            return false;
        }

        try {
            $row = DB::selectOne("SELECT is_active FROM llm_instances WHERE instance_id = 'claude_cli'");

            return (bool) ($row->is_active ?? false);
        } catch (\Throwable $e) {
            Log::debug('AIService: claude_cli activation lookup failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Is PLOS running in offline-only mode?
     *
     * "Offline" here means INTERNET offline, not LAN offline. The kill switch
     * blocks external/cloud LLM providers only:
     *   - Claude CLI (--print)
     *   - SambaNova, Cerebras, Groq, OpenRouter, Gemini, Mistral, DeepInfra
     *
     * Everything on the local network stays fully operational, including:
     *   - Nextcloud at /mnt/llm-storage (local storage + WebDAV)
     *   - MySQL (229 tables) and PostgreSQL (61 tables)
     *   - Redis (locks, circuit breakers, caches)
     *   - Configured local Ollama hosts
     *   - MCP servers bound to localhost or LAN (Thunderbird, SearXNG, etc.)
     *   - Horizon, queue workers, scheduled jobs
     *   - Web UI, API, file registry, thumbnails, RAG pipeline
     *
     * PLOS continues to function for review, planning, local file work, and
     * local LLM inference. Only the cloud escape hatch is closed.
     *
     * Fail-closed: any error (cache miss + DB exception, missing row, etc.)
     * returns true. That is safer than false — we would rather over-block
     * cloud providers than leak personal data through a transient fault.
     *
     * Values stored in system_configs (`routing.offline_mode`):
     *   - 'disabled' (default) → cloud fallback active
     *   - 'enabled'            → block all external + Claude CLI
     *   - any other value      → treated as enabled (safe default)
     */
    public function isOfflineMode(): bool
    {
        try {
            $svc = app(\App\Services\SystemConfigService::class);
            $value = $svc->get('routing.offline_mode', 'disabled');
            if (! is_string($value)) {
                return true;
            }

            return strtolower(trim($value)) !== 'disabled';
        } catch (\Throwable $e) {
            Log::warning('AIService: offline mode lookup failed, failing closed', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Read the active operator routing profile (3b2 + R3 six-rung ladder).
     *
     * Lookup precedence:
     *   1. routing.active_profile (system_configs) — current rung name.
     *   2. routing.profile.{name} (system_configs JSON) — legacy per-rung
     *      override if seeded. If present, wins (operator can tweak a single
     *      profile without editing config).
     *   3. config/offline_policy.php → profiles.{name} — AUTHORITATIVE
     *      source for the six-rung ladder. Synthesized to the legacy shape
     *      (allowed_instance_types + allowed_capabilities) so the fallback-
     *      chain filter works end-to-end.
     *   4. default / unknown → return null (no restriction).
     *
     * R3 (2026-04-19 defect fix): previously only (1) + (2) existed, so
     * hybrid_review / hybrid_dev_assist / cloud_escalation_only all fell
     * through to null (no restriction) — widening silently. The config-
     * synthesis path now covers every rung the routing:profile command
     * accepts.
     *
     * @return array<string,mixed>|null null when default / unknown / error
     */
    public function getActiveProfile(): ?array
    {
        try {
            $svc = app(\App\Services\SystemConfigService::class);
            $active = $svc->get('routing.active_profile', 'default');

            if (! is_string($active)) {
                return null;
            }

            $active = strtolower(trim($active));
            if ($active === '' || $active === 'default') {
                return null;
            }

            // Legacy DB override wins if present.
            $row = $svc->get('routing.profile.'.$active, null);
            if (is_string($row)) {
                $decoded = json_decode($row, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            if (is_array($row)) {
                return $row;
            }

            // Fallback: synthesize legacy shape from the authoritative
            // config/offline_policy.php profile row.
            return $this->synthesizeLegacyProfileFromOfflinePolicy($active);
        } catch (\Throwable $e) {
            Log::warning('AIService: active profile lookup failed, returning null', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * R3 + Defect A fix: translate a config/offline_policy.php profile row
     * into the legacy {allowed_instance_types, allowed_capabilities,
     * allowed_provider_classes, description} shape that buildFallbackChain()
     * + LLMPoolManagerService consume.
     *
     * Important: `instance_type` alone is NOT authoritative for privacy
     * posture. `custom` covers providers with mixed sensitive_safe flags
     * (OpenRouter, Groq, SambaNova, etc.). The coarse allowed_instance_types
     * list lets `custom` through under cloud_sensitive_safe profiles, and
     * then buildFallbackChain applies a per-provider sensitive_safe filter
     * via the `allowed_provider_classes` field we preserve below. Tests in
     * AIServiceFallbackChainSensitiveSafeTest exercise this real behavior.
     *
     * Provider-class → instance_type coarse admission:
     *   local_llm             → ollama
     *   cloud_sensitive_safe  → claude_cli, anthropic_api, custom
     *                           (custom admission is coarse — per-provider
     *                           sensitive_safe filter applies in
     *                           buildFallbackChain)
     *   cloud_external        → openai, google_gemini, openrouter, azure_openai, custom
     */
    private function synthesizeLegacyProfileFromOfflinePolicy(string $profileName): ?array
    {
        $profile = config('offline_policy.profiles.'.$profileName);
        if (! is_array($profile)) {
            // Unknown profile name — fall through to null so behavior
            // matches a missing row (no restriction applied — caller sees
            // "no profile" and operates under default chain).
            return null;
        }

        $providerClasses = (array) ($profile['allowed_provider_classes'] ?? []);

        $classToTypes = [
            'local_llm' => ['ollama'],
            'cloud_sensitive_safe' => ['claude_cli', 'anthropic_api', 'custom'],
            'cloud_external' => ['openai', 'google_gemini', 'openrouter', 'azure_openai'],
        ];

        $allowedTypes = [];
        foreach ($providerClasses as $class) {
            foreach ($classToTypes[$class] ?? [] as $type) {
                $allowedTypes[] = $type;
            }
        }

        return [
            'allowed_instance_types' => array_values(array_unique($allowedTypes)),
            'allowed_capabilities' => ['text'],
            'allowed_provider_classes' => $providerClasses,
            'description' => (string) ($profile['description'] ?? ''),
            'source' => 'config/offline_policy.php',
        ];
    }

    /**
     * INF-8: Calculate composite score for dynamic provider ordering.
     * Lower score = tried first.
     *
     * Weights: priority 30%, success_rate 30%, latency 20%, health 20%
     * Success rate is critical — a fast provider that fails 80% of the time
     * wastes more time than a slow provider that succeeds reliably.
     */
    private function calculateProviderScore(array $provider): float
    {
        $priority = (float) ($provider['priority'] ?? 50);
        $avgMs = (float) ($provider['avg_response_ms'] ?? 5000);
        $failures = (int) ($provider['consecutive_failures'] ?? 0);
        $successRate = (float) ($provider['success_rate'] ?? 50);

        // Normalize priority (0-100, lower is better)
        $priorityScore = min(100, $priority);

        // Normalize latency (0-100, lower is better)
        // Cap at 10s — beyond that, latency differences matter less than reliability
        $latencyScore = min(100, ($avgMs / 100));

        // Success rate penalty (0-100, lower is better) — inverted from success_rate
        $reliabilityPenalty = max(0, 100 - $successRate);

        // Health penalty: each consecutive failure adds 25 points
        $healthPenalty = min(100, $failures * 25);

        return ($priorityScore * 0.3) + ($reliabilityPenalty * 0.3) + ($latencyScore * 0.2) + ($healthPenalty * 0.2);
    }

    /**
     * Get active external API providers from llm_instances table.
     * Cached for 60 seconds to avoid DB hits on every request.
     */
    private function getExternalApiProviders(): array
    {
        $cacheKey = 'external_api_providers';
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $externalTypes = ['openai', 'anthropic_api', 'google_gemini', 'azure_openai', 'custom'];
            $placeholders = implode(',', array_fill(0, count($externalTypes), '?'));
            $rows = DB::select("
                SELECT instance_id, instance_name, instance_type, base_url, api_key, api_key_env,
                       priority, supported_models, capabilities, config, rate_limit_rpm,
                       rate_limit_tpm, circuit_state, max_concurrent, avg_response_ms,
                       consecutive_failures, success_rate
                FROM llm_instances
                WHERE is_active = 1
                  AND is_healthy = 1
                  AND instance_type IN ({$placeholders})
                ORDER BY priority ASC
            ", $externalTypes);

            $providers = [];
            foreach ($rows as $row) {
                // API key: DB column first, .env fallback
                $apiKey = $row->api_key ?: $this->resolveRuntimeEnvValue($row->api_key_env ?? null);
                if (! $apiKey) {
                    continue; // Skip unconfigured providers (no key yet)
                }

                $config = json_decode($row->config ?? '{}', true) ?: [];
                $capabilities = json_decode($row->capabilities ?? '{}', true) ?: [];

                $providers[] = [
                    'id' => $row->instance_id,
                    'name' => $row->instance_name,
                    'instance_type' => $row->instance_type,
                    'base_url' => $row->base_url,
                    'api_key' => $apiKey,
                    'priority' => $row->priority,
                    'default_model' => $config['default_model'] ?? null,
                    'supported_models' => json_decode($row->supported_models ?? '[]', true) ?: [],
                    'rate_limit_rpm' => $row->rate_limit_rpm,
                    'rate_limit_tpm' => $row->rate_limit_tpm,
                    'max_concurrent' => $row->max_concurrent,
                    'circuit_state' => $row->circuit_state,
                    'sensitive_safe' => in_array('sensitive_safe', $capabilities) || ($capabilities['sensitive_safe'] ?? false),
                    'capabilities' => $capabilities,
                    'extra_headers' => $config['extra_headers'] ?? [],
                    'config' => $config,
                    'avg_response_ms' => (float) ($row->avg_response_ms ?? 5000),
                    'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
                    'success_rate' => (float) ($row->success_rate ?? 50),
                ];
            }

            Cache::put($cacheKey, $providers, 60);

            return $providers;

        } catch (\Exception $e) {
            Log::warning('AIService: Failed to load external providers', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Try an external API provider (Groq, OpenRouter, Mistral, etc.)
     * Uses AIRouter's callOpenAICompatible() method.
     */
    private function tryExternalProvider(array $provider, string $prompt, array $config): array
    {
        $providerId = $provider['id'];
        $startTime = microtime(true);

        // Check circuit breaker
        if ($this->isCircuitOpen($providerId)) {
            return [
                'success' => false,
                'error' => "Circuit open for {$provider['name']}",
            ];
        }

        // Check rate limit tracking
        $rpmKey = "rpm_count_{$providerId}";
        $currentRpm = Cache::get($rpmKey, 0);
        if ($provider['rate_limit_rpm'] && $currentRpm >= $provider['rate_limit_rpm']) {
            // Rate limit exhausted — mark cooldown for remaining window
            Cache::put("rate_limit_{$providerId}", true, 60);

            return [
                'success' => false,
                'error' => "Rate limit exhausted for {$provider['name']} ({$currentRpm}/{$provider['rate_limit_rpm']} RPM)",
            ];
        }

        try {
            // Role-based model resolution: model_role → config.models.{role} → default_model → first supported
            $role = $config['model_role'] ?? 'standard';
            $roleModel = $provider['config']['models'][$role] ?? null;
            $model = $config['model_override'] ?? $roleModel ?? $provider['default_model'] ?? ($provider['supported_models'][0] ?? null);
            if (! $model) {
                return ['success' => false, 'error' => "No model configured for {$provider['name']}"];
            }

            $systemPrompt = $config['system_prompt'] ?? $config['system'] ?? '';
            if (empty($systemPrompt)) {
                $systemPrompt = $this->aiRouter->buildToolAwarePrompt('', false);
            }

            $providerConfig = [
                'ai_mode' => 'external_api',
                'base_url' => $provider['base_url'],
                'api_key' => $provider['api_key'],
                'model' => $model,
                'instance_id' => $providerId,
                'max_tokens' => $config['max_tokens'] ?? 2000,
                'temperature' => $config['temperature'] ?? 0.1,
                'system_prompt' => $systemPrompt,
                'timeout' => $config['timeout'] ?? 120,
                'extra_headers' => $provider['extra_headers'] ?? [],
            ];

            $response = $this->aiRouter->processWithAI($prompt, $providerConfig);

            // Track rate limit
            if ($provider['rate_limit_rpm']) {
                Cache::put($rpmKey, $currentRpm + 1, 60);
            }

            $this->recordSuccess($providerId, microtime(true) - $startTime);

            return [
                'success' => true,
                'response' => $response,
                'provider' => $providerId,
                'provider_name' => $provider['name'],
                'model' => $model,
                'error' => null,
            ];

        } catch (\Exception $e) {
            // Extract Retry-After for rate limit errors — set cache AND circuit to provider's window
            $retryAfterSeconds = null;
            if ($e instanceof \App\Exceptions\AI\RateLimitException) {
                $retryAfterSeconds = (int) round($e->getSuggestedBackoffMs() / 1000);
            } elseif (str_contains($e->getMessage(), 'Rate limit') || str_contains($e->getMessage(), '429')) {
                $retryAfterSeconds = 60;
                if (preg_match('/retry after (\d+)s/', $e->getMessage(), $m)) {
                    $retryAfterSeconds = (int) $m[1];
                }
            }

            // Set rate_limit_ cache before recording failure so buildFallbackChain() skips immediately
            if ($retryAfterSeconds !== null) {
                Cache::put("rate_limit_{$providerId}", true, $retryAfterSeconds);
            }

            $this->recordFailure($providerId, $e->getMessage(), $retryAfterSeconds);

            return [
                'success' => false,
                'error' => "{$provider['name']}: ".$e->getMessage(),
            ];
        }
    }

    /**
     * Try an external API provider for vision (image analysis).
     * Uses OpenAI-compatible vision format (base64 image in message content).
     */
    private function tryExternalVisionProvider(array $provider, string $imageContent, string $prompt, array $config): array
    {
        $providerId = $provider['id'];
        $startTime = microtime(true);

        // Check circuit breaker
        if ($this->isCircuitOpen($providerId.'_vision')) {
            return [
                'success' => false,
                'error' => "Circuit open for {$provider['name']} vision",
            ];
        }

        // Check rate limit
        $rpmKey = "rpm_count_{$providerId}";
        $currentRpm = Cache::get($rpmKey, 0);
        if ($provider['rate_limit_rpm'] && $currentRpm >= $provider['rate_limit_rpm']) {
            Cache::put("rate_limit_{$providerId}", true, 60);

            return [
                'success' => false,
                'error' => "Rate limit exhausted for {$provider['name']} ({$currentRpm}/{$provider['rate_limit_rpm']} RPM)",
            ];
        }

        try {
            // Select vision model from config or supported models
            $providerConfig = $provider['config'] ?? [];
            $model = $providerConfig['vision_model']
                ?? $config['vision_model']
                ?? $provider['default_model']
                ?? ($provider['supported_models'][0] ?? null);

            if (! $model) {
                return ['success' => false, 'error' => "No vision model configured for {$provider['name']}"];
            }

            $base64Image = base64_encode($imageContent);

            $visionConfig = [
                'base_url' => $provider['base_url'],
                'api_key' => $provider['api_key'],
                'model' => $model,
                'instance_id' => $providerId,
                'max_tokens' => $config['max_tokens'] ?? 2000,
                'temperature' => $config['temperature'] ?? 0.3,
                'timeout' => $config['timeout'] ?? 120,
                'extra_headers' => $provider['extra_headers'] ?? [],
            ];

            $response = $this->aiRouter->callOpenAICompatibleVision($base64Image, $prompt, $visionConfig);

            // Track rate limit
            if ($provider['rate_limit_rpm']) {
                Cache::put($rpmKey, $currentRpm + 1, 60);
            }

            $this->recordSuccess($providerId.'_vision', microtime(true) - $startTime);

            return [
                'success' => true,
                'response' => $response,
                'provider' => $providerId,
                'provider_name' => $provider['name'],
                'model' => $model,
                'error' => null,
            ];

        } catch (\Exception $e) {
            // Extract Retry-After for rate limit errors
            $retryAfterSeconds = null;
            if ($e instanceof \App\Exceptions\AI\RateLimitException) {
                $retryAfterSeconds = (int) round($e->getSuggestedBackoffMs() / 1000);
            } elseif (str_contains($e->getMessage(), 'Rate limit') || str_contains($e->getMessage(), '429')) {
                $retryAfterSeconds = 60;
                if (preg_match('/retry after (\d+)s/', $e->getMessage(), $m)) {
                    $retryAfterSeconds = (int) $m[1];
                }
            }

            if ($retryAfterSeconds !== null) {
                Cache::put("rate_limit_{$providerId}", true, $retryAfterSeconds);
            }

            $this->recordFailure($providerId.'_vision', $e->getMessage(), $retryAfterSeconds);

            return [
                'success' => false,
                'error' => "{$provider['name']} vision: ".$e->getMessage(),
            ];
        }
    }

    /**
     * Get vision-capable external providers, sorted by priority.
     * Filters getExternalApiProviders() to those with vision capability.
     */
    private function getVisionCapableProviders(bool $sensitiveData = false): array
    {
        $providers = [];
        foreach ($this->getExternalApiProviders() as $provider) {
            // Check vision capability — handles both formats:
            // Array format: ["text", "vision", "embedding"]
            // Object format: {"vision": true, "chat": true}
            $caps = $provider['capabilities'] ?? [];
            $hasVision = false;
            if (is_array($caps) && ! empty($caps)) {
                if (array_is_list($caps)) {
                    $hasVision = in_array('vision', $caps);
                } else {
                    $hasVision = ! empty($caps['vision']);
                }
            }

            if (! $hasVision) {
                continue;
            }

            // Skip providers unsafe for sensitive data
            if ($sensitiveData && ! ($provider['sensitive_safe'] ?? false)) {
                continue;
            }

            // Check rate limit
            $rateLimitKey = 'rate_limit_'.$provider['id'];
            if (Cache::has($rateLimitKey)) {
                continue;
            }

            $providers[] = $provider;
        }

        usort($providers, fn ($a, $b) => ($a['priority'] ?? 50) - ($b['priority'] ?? 50));

        return $providers;
    }

    /**
     * Claude-powered web research using Claude CLI with WebSearch tool
     *
     * This method produces high-quality research results by leveraging Claude's
     * built-in WebSearch capability, which returns content snippets directly
     * (unlike SearXNG which only returns URLs requiring scraping).
     *
     * Use for research queries requiring authoritative, well-sourced answers.
     *
     * @param  string  $query  The research query
     * @param  array  $config  Optional config: timeout, max_tokens, system_prompt
     * @return array {success: bool, response: string, sources: array, error: ?string}
     */
    public function claudeWebResearch(string $query, array $config = []): array
    {
        $startTime = microtime(true);
        $providerId = 'claude_cli';
        $requestId = uniqid('claude_research_', true);
        $process = null;
        $pipes = [];
        $lastError = null;

        if (! $this->isClaudeCliEnabled()) {
            return [
                'success' => false,
                'error' => 'Claude CLI is disabled',
                'response' => null,
                'sources' => [],
            ];
        }

        // Pre-flight: check OAuth token expiry before wasting a slot
        $tokenStatus = app(LLMPoolManagerService::class)->checkClaudeTokenExpiry();
        if ($tokenStatus === 'expired') {
            return [
                'success' => false,
                'error' => 'Claude CLI OAuth token expired. Run: claude login',
                'response' => null,
                'sources' => [],
            ];
        }

        // Check circuit breaker
        if ($this->isCircuitOpen('claude_cli')) {
            return [
                'success' => false,
                'error' => 'Claude CLI circuit is open',
                'response' => null,
                'sources' => [],
            ];
        }

        // Acquire slot
        $slotId = $this->acquireClaudeSlot($requestId);
        if ($slotId === null) {
            return [
                'success' => false,
                'error' => 'No Claude slots available',
                'response' => null,
                'sources' => [],
            ];
        }

        try {
            $claudePath = config('services.anthropic.cli_path', 'claude');
            $timeout = $config['timeout'] ?? 120;

            // Build system prompt for research
            $systemPrompt = $config['system_prompt'] ??
                'You are a research assistant. Provide factual, well-sourced answers. '.
                'Always cite sources with URLs. Format responses in clear markdown with sections for: '.
                'Key Findings, Verified Facts, Sources. Be concise but thorough.';

            // Resolve model — web research uses 'quality' role by default
            $role = $config['model_role'] ?? 'quality';
            $model = $this->resolveModelForProvider('claude_cli', $role);

            // Build command with WebSearch tool enabled
            $command = [$claudePath, '--print'];
            if ($model) {
                $command[] = '--model';
                $command[] = $model;
            }
            $command[] = '--tools';
            $command[] = 'WebSearch';
            $command[] = '--system-prompt';
            $command[] = $systemPrompt;

            $env = $this->buildClaudeCliEnv();

            // Use proc_open to pipe query via stdin
            $descriptorspec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            Log::info('AIService: Claude web research starting', [
                'query_length' => strlen($query),
                'slot_id' => $slotId,
            ]);

            $process = proc_open($command, $descriptorspec, $pipes, null, $env);

            if (! is_resource($process)) {
                throw new Exception('Failed to start Claude CLI process');
            }

            // Write query to stdin
            fwrite($pipes[0], $query);
            fclose($pipes[0]);

            // Poll both pipes so a hung CLI cannot block the worker indefinitely.
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $response = '';
            $stderr = '';
            $deadline = microtime(true) + $timeout;

            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                $remaining = max(0.0, $deadline - microtime(true));
                $seconds = (int) floor($remaining);
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);

                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                $changed = @stream_select($read, $write, $except, $seconds, $microseconds);

                if ($changed === false) {
                    break;
                }

                if ($changed > 0) {
                    foreach ($read as $pipe) {
                        $chunk = stream_get_contents($pipe);
                        if ($chunk !== false && $chunk !== '') {
                            if ($pipe === $pipes[1]) {
                                $response .= $chunk;
                            } else {
                                $stderr .= $chunk;
                            }
                        }
                    }
                }

                if (! $status['running']) {
                    break;
                }
            }

            $response .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
                proc_close($process);
                $process = null;
                throw new Exception("Claude CLI timed out after {$timeout}s");
            }

            $exitCode = $status['exitcode'];
            if ($exitCode === -1) {
                $exitCode = proc_close($process);
            } else {
                proc_close($process);
            }
            $process = null;

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($exitCode !== 0) {
                $lastError = trim($stderr) !== ''
                    ? "Claude CLI exited with code {$exitCode}: ".trim($stderr)
                    : "Claude CLI exited with code {$exitCode}";
                $typed = AIExceptionFactory::fromMessage($lastError, $providerId, $model ?? 'claude');

                Log::warning('AIService: Claude web research failed', [
                    'exit_code' => $exitCode,
                    'stderr' => $stderr,
                    'duration_ms' => $durationMs,
                ]);
                $allRateLimited = $this->countActiveRateLimitedProviders() >= 3;
                if ($typed instanceof \App\Exceptions\AI\RateLimitException) {
                    Log::info('AIService: claude_web_research rate-limited — skipping circuit increment (transient)', [
                        'error' => $lastError,
                    ]);
                } elseif ($allRateLimited) {
                    Log::warning('AIService: claude_web_research failure during provider rate-limit cascade — skipping circuit increment', [
                        'error' => $lastError,
                    ]);
                } else {
                    $this->recordFailure($providerId, $lastError);
                }

                return [
                    'success' => false,
                    'error' => $lastError,
                    'response' => null,
                    'sources' => [],
                ];
            }

            // Extract sources from response (look for URLs in markdown links)
            $sources = [];
            if (preg_match_all('/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/', $response, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $sources[] = [
                        'title' => $match[1],
                        'url' => $match[2],
                    ];
                }
            }

            $this->recordSuccess($providerId, microtime(true) - $startTime);

            Log::info('AIService: Claude web research completed', [
                'response_length' => strlen($response),
                'sources_found' => count($sources),
                'duration_ms' => $durationMs,
            ]);

            return [
                'success' => true,
                'response' => $response,
                'sources' => array_unique($sources, SORT_REGULAR),
                'duration_ms' => $durationMs,
                'provider' => 'claude_web_research',
                'error' => null,
            ];

        } catch (Exception $e) {
            $typed = $e instanceof AIServiceException
                ? $e
                : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $model ?? 'claude');
            $lastError = $typed->getMessage();
            $allRateLimited = $this->countActiveRateLimitedProviders() >= 3;

            if ($typed instanceof \App\Exceptions\AI\RateLimitException) {
                Log::info('AIService: claude_web_research rate-limited — skipping circuit increment (transient)', [
                    'error' => $lastError,
                ]);
            } elseif ($allRateLimited) {
                Log::warning('AIService: claude_web_research failure during provider rate-limit cascade — skipping circuit increment', [
                    'error' => $lastError,
                ]);
            } else {
                $this->recordFailure($providerId, $lastError);
            }

            Log::error('AIService: Claude web research exception', [
                'error' => $lastError,
            ]);

            return [
                'success' => false,
                'error' => $lastError,
                'response' => null,
                'sources' => [],
            ];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }

            $this->releaseClaudeSlot($slotId);
        }
    }

    /**
     * Calculate exponential backoff delay
     */
    private function calculateBackoff(int $attempt): int
    {
        $backoff = self::INITIAL_BACKOFF_MS * pow(self::BACKOFF_MULTIPLIER, $attempt - 1);
        // Add jitter (±25%)
        $jitter = $backoff * 0.25 * (mt_rand() / mt_getrandmax() * 2 - 1);

        return (int) min($backoff + $jitter, self::MAX_BACKOFF_MS);
    }

    // ═══════════════════════════════════════════════════════════════════
    // CIRCUIT BREAKER
    // Delegates to shared CircuitBreaker service for cache-based state.
    // Pool manager (DB-backed) remains the primary source of truth when available.
    // Supplemental stats cache provides diagnostics metadata (total_requests, etc.).
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Check if circuit is open for provider
     */
    private function isCircuitOpen(string $providerId): bool
    {
        // Primary: Check DB via pool manager (source of truth for all providers)
        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $instance = $this->poolManager->getInstance($providerId);
                if ($instance && ! $this->poolManager->isCircuitClosed($instance)) {
                    return true;
                }
                if ($instance) {
                    return false;
                }
                // Instance not in DB — fall through to shared circuit breaker
            } catch (\Throwable $e) {
                Log::debug('AIService: pool manager circuit check failed, falling through to cache', ['provider' => $providerId, 'error' => $e->getMessage()]);
            }
        }

        // Fallback: Shared cache-based circuit breaker (for providers not in llm_instances)
        $cooldown = (int) config('circuit_breaker.cooldown_seconds', self::CIRCUIT_COOLDOWN_SECONDS);

        return ! $this->circuitBreaker->isAvailable($providerId, $cooldown);
    }

    /**
     * Get circuit state for diagnostics — composes shared CircuitBreaker state with supplemental stats
     */
    private function getCircuitState(string $providerId): array
    {
        $state = $this->circuitBreaker->getState($providerId);
        $stats = Cache::get("ai_circuit_stats_{$providerId}", [
            'failures' => 0,
            'successes' => 0,
            'opened_at' => null,
            'last_failure' => null,
            'last_success' => null,
            'last_duration_ms' => null,
            'total_requests' => 0,
            'total_failures' => 0,
        ]);

        // Normalize state name (shared CB uses 'half_open', legacy used 'half-open')
        $normalizedState = $state === 'half_open' ? 'half-open' : $state;

        return array_merge($stats, ['state' => $normalizedState]);
    }

    /**
     * Record successful request
     */
    private function recordSuccess(string $providerId, float $duration): void
    {
        $durationMs = (int) ($duration * 1000);

        if (! $this->shouldRecordProviderHealth($providerId)) {
            return;
        }

        // Update pool manager if available (persistent storage)
        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $this->poolManager->recordSuccess($providerId, $durationMs);
            } catch (\Exception $e) {
                Log::debug('AIService: Pool manager recordSuccess failed', ['error' => $e->getMessage()]);
            }
        }

        // Update shared circuit breaker state
        $this->circuitBreaker->recordSuccess($providerId);

        // Update supplemental stats for diagnostics
        $stats = Cache::get("ai_circuit_stats_{$providerId}", [
            'failures' => 0, 'successes' => 0, 'opened_at' => null,
            'last_failure' => null, 'last_success' => null, 'last_duration_ms' => null,
            'total_requests' => 0, 'total_failures' => 0,
        ]);
        $stats['successes']++;
        $stats['total_requests']++;
        $stats['last_success'] = time();
        $stats['last_duration_ms'] = $durationMs;

        // Sync opened_at from circuit state
        $cbState = $this->circuitBreaker->getState($providerId);
        if ($cbState === 'closed') {
            $stats['failures'] = 0;
            $stats['opened_at'] = null;
        }

        Cache::put("ai_circuit_stats_{$providerId}", $stats, 3600);
    }

    /**
     * Record failed request
     */
    private function recordFailure(string $providerId, ?string $error = null, ?int $retryAfterSeconds = null): void
    {
        if (! $this->shouldRecordProviderHealth($providerId)) {
            return;
        }

        // Update pool manager if available (persistent storage)
        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $this->poolManager->recordFailure($providerId, $error ?? 'Unknown error', $retryAfterSeconds);
            } catch (\Exception $e) {
                Log::debug('AIService: Pool manager recordFailure failed', ['error' => $e->getMessage()]);
            }
        }

        // Update shared circuit breaker state
        $this->circuitBreaker->recordFailure($providerId);

        // Update supplemental stats for diagnostics
        $stats = Cache::get("ai_circuit_stats_{$providerId}", [
            'failures' => 0, 'successes' => 0, 'opened_at' => null,
            'last_failure' => null, 'last_success' => null, 'last_duration_ms' => null,
            'total_requests' => 0, 'total_failures' => 0,
        ]);
        $stats['failures']++;
        $stats['total_failures']++;
        $stats['total_requests']++;
        $stats['last_failure'] = time();

        // Sync opened_at from circuit state
        $cbState = $this->circuitBreaker->getState($providerId);
        if ($cbState === 'open') {
            $stats['opened_at'] = time();
        }

        Cache::put("ai_circuit_stats_{$providerId}", $stats, 3600);
    }

    private function shouldRecordProviderHealth(string $providerId): bool
    {
        $baseProviderId = str_ends_with($providerId, '_vision')
            ? substr($providerId, 0, -7)
            : $providerId;

        try {
            $row = DB::selectOne('SELECT is_active FROM llm_instances WHERE instance_id = ? LIMIT 1', [$baseProviderId]);
            if ($row !== null) {
                return (bool) ($row->is_active ?? false);
            }
        } catch (\Throwable $e) {
            Log::debug('AIService: provider health eligibility lookup failed', [
                'provider_id' => $providerId,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // OLLAMA BUSY LOCK (Per-Instance - Supports Multiple Ollama Servers)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Check if Ollama is currently busy processing another request
     * Uses Redis lock to track active requests across all workers
     *
     * @param  string|null  $instanceId  Specific instance to check (null = primary)
     */
    public function isOllamaBusy(?string $instanceId = null): bool
    {
        $instanceId = $instanceId ?? 'ollama_primary';

        // Use pool manager for per-instance busy check
        if ($this->useDynamicPool && $this->poolManager) {
            return $this->poolManager->isInstanceBusy($instanceId);
        }

        // Legacy: single global lock
        return Cache::has(self::OLLAMA_BUSY_CACHE_KEY);
    }

    /**
     * Check if Whisper is currently using the GPU
     */
    public function isWhisperRunning(): bool
    {
        return Cache::has('whisper_gpu_lock');
    }

    /**
     * Acquire Ollama busy lock before making a request
     * Returns false if lock cannot be acquired (Ollama is busy)
     *
     * @param  string  $requestId  Unique request identifier
     * @param  string|null  $instanceId  Specific instance (null = primary)
     */
    private function acquireOllamaBusyLock(string $requestId, ?string $instanceId = null): bool
    {
        // GPU contention: don't acquire Ollama lock while Whisper is transcribing
        if ($this->isWhisperRunning()) {
            Log::debug('AIService: Whisper using GPU, cannot acquire Ollama lock', [
                'request_id' => $requestId,
            ]);

            return false;
        }

        // GPU contention: don't acquire Ollama lock while embedding training is running
        if (Cache::has('embedding_training_gpu_lock')) {
            Log::debug('AIService: Embedding training using GPU, cannot acquire Ollama lock', [
                'request_id' => $requestId,
            ]);

            return false;
        }

        $instanceId = $instanceId ?? 'ollama_primary';

        // Use pool manager for per-instance locks
        if ($this->useDynamicPool && $this->poolManager) {
            $locked = $this->poolManager->acquireBusyLock($instanceId, $requestId);
            if ($locked) {
                Log::debug('AIService: Acquired busy lock via pool manager', [
                    'request_id' => $requestId,
                    'instance_id' => $instanceId,
                ]);
            }

            return $locked;
        }

        // Legacy: single global lock
        $lockKey = self::OLLAMA_BUSY_CACHE_KEY;

        // Use atomic add to prevent race conditions
        $locked = Cache::add($lockKey, [
            'request_id' => $requestId,
            'started_at' => time(),
            'pid' => getmypid(),
        ], config('lock_ttls.ollama_busy', self::OLLAMA_BUSY_LOCK_TTL));

        if ($locked) {
            Log::debug('AIService: Acquired Ollama busy lock (legacy)', [
                'request_id' => $requestId,
            ]);
        }

        return $locked;
    }

    /**
     * Release Ollama busy lock after request completes
     *
     * @param  string  $requestId  Request identifier that acquired the lock
     * @param  string|null  $instanceId  Specific instance (null = primary)
     */
    private function releaseOllamaBusyLock(string $requestId, ?string $instanceId = null): void
    {
        $instanceId = $instanceId ?? 'ollama_primary';

        // Use pool manager for per-instance locks
        if ($this->useDynamicPool && $this->poolManager) {
            $this->poolManager->releaseBusyLock($instanceId, $requestId);
            Log::debug('AIService: Released busy lock via pool manager', [
                'request_id' => $requestId,
                'instance_id' => $instanceId,
            ]);

            return;
        }

        // Legacy: single global lock
        $lockKey = self::OLLAMA_BUSY_CACHE_KEY;
        $current = Cache::get($lockKey);

        // Only release if we own the lock
        if ($current && ($current['request_id'] ?? null) === $requestId) {
            Cache::forget($lockKey);
            Log::debug('AIService: Released Ollama busy lock (legacy)', [
                'request_id' => $requestId,
            ]);
        }
    }

    /**
     * Get info about current Ollama busy lock
     *
     * @param  string|null  $instanceId  Specific instance (null = primary)
     */
    public function getOllamaBusyInfo(?string $instanceId = null): ?array
    {
        $instanceId = $instanceId ?? 'ollama_primary';

        // Pool manager provides more detailed info
        if ($this->useDynamicPool && $this->poolManager) {
            $isBusy = $this->poolManager->isInstanceBusy($instanceId);

            return [
                'busy' => $isBusy,
                'instance_id' => $instanceId,
                'from_pool' => true,
            ];
        }

        return Cache::get(self::OLLAMA_BUSY_CACHE_KEY);
    }

    /**
     * Get LLM Pool Manager instance for external access
     */
    public function getPoolManager(): ?LLMPoolManagerService
    {
        return $this->poolManager;
    }

    /**
     * Get LLM pool statistics
     */
    public function getPoolStats(): ?array
    {
        if ($this->useDynamicPool && $this->poolManager) {
            return $this->poolManager->getPoolStats();
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // CLAUDE CLI CONCURRENCY MANAGEMENT (Multiple Parallel Calls)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get current Claude CLI slot usage with dynamic max
     * Returns comprehensive status including Ollama health
     */
    public function getClaudeSlotUsage(): array
    {
        $slots = Cache::get(self::CLAUDE_SLOTS_CACHE_KEY, []);
        $now = time();

        // Clean up expired slots
        $activeSlots = array_filter($slots, function ($slot) use ($now) {
            return ($slot['expires_at'] ?? 0) > $now;
        });

        $dynamicMax = $this->getDynamicClaudeMaxSlots();
        $ollamaHealth = $this->getOllamaHealthStatus();

        return [
            'active' => count($activeSlots),
            'max' => $dynamicMax,
            'default_max' => config('agents.claude_default_max', 10),
            'absolute_max' => config('agents.claude_absolute_max', 20),
            'static_min' => config('agents.claude_min_concurrent', 3),
            'available' => max(0, $dynamicMax - count($activeSlots)),
            'slots' => $activeSlots,
            'system_load' => $this->getSystemLoad(),
            'ollama_healthy' => $ollamaHealth['healthy'],
            'ollama_status' => $ollamaHealth,
            'scaling_mode' => $ollamaHealth['healthy'] ? 'normal' : 'ollama_fallback',
        ];
    }

    /**
     * Check if Claude CLI has available slots
     */
    public function hasClaudeSlotAvailable(): bool
    {
        $usage = $this->getClaudeSlotUsage();

        return $usage['available'] > 0;
    }

    /**
     * Acquire a Claude CLI slot with wait-for-availability support
     *
     * This method implements fault-tolerant slot acquisition:
     * 1. First attempts immediate acquisition (with stale slot cleanup)
     * 2. If no slots available, waits with progressive backoff
     * 3. Periodically cleans stale slots (dead PIDs)
     * 4. Alerts via Pushover after repeated timeouts
     * 5. Returns slot ID on success, null only after timeout exhausted
     *
     * @param  string  $requestId  Unique request identifier for tracking
     * @param  bool  $waitForSlot  Whether to wait for slot availability (default true)
     * @return string|null Slot ID on success, null on timeout
     */
    private function acquireClaudeSlot(string $requestId, bool $waitForSlot = true): ?string
    {
        $waitStartTime = microtime(true);
        $maxWaitTime = $waitForSlot ? self::SLOT_WAIT_TIMEOUT_SECONDS : 0;
        $attempt = 0;
        $currentPollInterval = self::SLOT_WAIT_POLL_INTERVAL_MS;
        $lastStaleCleanup = 0;

        while (true) {
            $attempt++;

            // Periodically clean stale slots (every 10 attempts or 5 seconds)
            $elapsedSeconds = microtime(true) - $waitStartTime;
            if ($attempt % 10 === 0 || ($elapsedSeconds - $lastStaleCleanup) > 5) {
                $this->cleanStaleSlots();
                $lastStaleCleanup = $elapsedSeconds;
            }

            $slotId = $this->tryAcquireClaudeSlotImmediate($requestId);

            if ($slotId !== null) {
                // Reset timeout counter on success
                $this->resetSlotTimeoutCounter();

                if ($attempt > 1) {
                    Log::info('AIService: Acquired Claude slot after waiting', [
                        'slot_id' => $slotId,
                        'wait_seconds' => round($elapsedSeconds, 2),
                        'attempts' => $attempt,
                    ]);
                }

                return $slotId;
            }

            // Check if we should keep waiting
            if ($elapsedSeconds >= $maxWaitTime) {
                if ($waitForSlot && $attempt > 1) {
                    $slotUsage = $this->getClaudeSlotUsage();
                    Log::warning('AIService: Slot wait timeout exhausted', [
                        'wait_seconds' => round($elapsedSeconds, 2),
                        'attempts' => $attempt,
                        'slot_usage' => $slotUsage,
                    ]);

                    // Track consecutive timeouts and alert if threshold reached
                    $this->handleSlotTimeout($slotUsage);
                }

                return null;
            }

            // Log first wait attempt for monitoring
            if ($attempt === 1) {
                Log::debug('AIService: No immediate slot available, waiting...', [
                    'max_wait_seconds' => $maxWaitTime,
                    'slot_usage' => $this->getClaudeSlotUsage(),
                ]);
            }

            // Progressive backoff: increase poll interval over time
            // Start at 500ms, increase by 50% each time, cap at 5000ms
            if ($attempt > 5) {
                $currentPollInterval = min(
                    (int) ($currentPollInterval * 1.5),
                    self::SLOT_WAIT_MAX_POLL_MS
                );
            }

            usleep($currentPollInterval * 1000);
        }
    }

    /**
     * Clean stale slots where the owning process is dead
     * This prevents slots from being stuck when a process crashes
     */
    private function cleanStaleSlots(): void
    {
        $lockKey = self::CLAUDE_SLOTS_CACHE_KEY.'_lock';

        // Try to acquire lock (non-blocking)
        if (! Cache::add($lockKey, 'cleanup', 5)) {
            return; // Another process is handling slots
        }

        try {
            $slots = Cache::get(self::CLAUDE_SLOTS_CACHE_KEY, []);
            $now = time();
            $cleaned = 0;

            foreach ($slots as $slotId => $slot) {
                $shouldRemove = false;
                $reason = '';

                // Check if expired
                if (($slot['expires_at'] ?? 0) <= $now) {
                    $shouldRemove = true;
                    $reason = 'expired';
                }
                // Check if PID is dead (stale slot from crashed process)
                elseif (isset($slot['pid']) && isset($slot['started_at'])) {
                    $age = $now - $slot['started_at'];
                    // Only check PID for slots older than 30 seconds (avoid race with new slots)
                    if ($age > 30 && ! $this->isProcessAlive($slot['pid'])) {
                        $shouldRemove = true;
                        $reason = 'dead_pid';
                    }
                    // Force remove very old slots even if PID check fails
                    elseif ($age > self::CLAUDE_SLOT_STALE_SECONDS) {
                        $shouldRemove = true;
                        $reason = 'stale_age';
                    }
                }

                if ($shouldRemove) {
                    unset($slots[$slotId]);
                    $cleaned++;
                    Log::info('AIService: Cleaned stale Claude slot', [
                        'slot_id' => $slotId,
                        'reason' => $reason,
                        'pid' => $slot['pid'] ?? 'unknown',
                        'age_seconds' => isset($slot['started_at']) ? $now - $slot['started_at'] : 'unknown',
                    ]);
                }
            }

            if ($cleaned > 0) {
                Cache::put(self::CLAUDE_SLOTS_CACHE_KEY, $slots, self::CLAUDE_SLOT_TTL + 60);
            }

        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Check if a process is still alive
     */
    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Use POSIX if available (more reliable)
        if (function_exists('posix_kill')) {
            // Signal 0 doesn't kill, just checks if process exists
            return @posix_kill($pid, 0);
        }

        // Fallback to /proc filesystem (Linux)
        if (is_dir("/proc/{$pid}")) {
            return true;
        }

        // Last resort: try kill -0 via shell
        return \Illuminate\Support\Facades\Process::timeout(5)->run(['kill', '-0', (string) $pid])->successful();
    }

    /**
     * Handle slot timeout - track consecutive failures and alert
     */
    private function handleSlotTimeout(array $slotUsage): void
    {
        // Increment timeout counter
        $count = (int) Cache::get(self::SLOT_TIMEOUT_CACHE_KEY, 0) + 1;
        Cache::put(self::SLOT_TIMEOUT_CACHE_KEY, $count, 600); // 10 min TTL

        // Alert if threshold reached and cooldown expired
        if ($count >= self::SLOT_TIMEOUT_ALERT_THRESHOLD) {
            $alertKey = 'ai_slot_timeout_alerted';
            if (! Cache::has($alertKey)) {
                Cache::put($alertKey, true, self::SLOT_TIMEOUT_ALERT_COOLDOWN);

                $this->sendSlotTimeoutAlert($count, $slotUsage);
            }
        }
    }

    /**
     * Reset slot timeout counter (called on successful slot acquisition)
     */
    private function resetSlotTimeoutCounter(): void
    {
        Cache::forget(self::SLOT_TIMEOUT_CACHE_KEY);
    }

    /**
     * Log slot timeout alert (Pushover removed — self-healing handles recovery,
     * daily report surfaces slot exhaustion via job failure rates).
     */
    private function sendSlotTimeoutAlert(int $timeoutCount, array $slotUsage): void
    {
        try {
            Log::error('AIService: Slot timeout threshold reached', [
                'timeout_count' => $timeoutCount,
                'active_slots' => $slotUsage['active'] ?? 0,
                'max_slots' => $slotUsage['max'] ?? 0,
                'system_load' => $slotUsage['system_load']['load_1m'] ?? 'unknown',
                'memory_free_mb' => $slotUsage['system_load']['memory_free_mb'] ?? 'unknown',
            ]);
        } catch (Exception $e) {
            Log::error('AIService: Failed to log slot timeout', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Try to acquire a Claude slot immediately (no waiting)
     * Also cleans expired and stale slots inline for efficiency
     *
     * @internal Used by acquireClaudeSlot
     */
    private function tryAcquireClaudeSlotImmediate(string $requestId): ?string
    {
        $slotId = uniqid('claude_slot_', true);
        $now = time();

        // Use Redis lock for atomic slot acquisition
        $lockKey = self::CLAUDE_SLOTS_CACHE_KEY.'_lock';

        // Simple spin lock with timeout
        $lockAcquired = false;
        $lockStart = microtime(true);
        while (! $lockAcquired && (microtime(true) - $lockStart) < 2.0) {
            $lockAcquired = Cache::add($lockKey, $requestId, 5);
            if (! $lockAcquired) {
                usleep(50000); // 50ms
            }
        }

        if (! $lockAcquired) {
            Log::warning('AIService: Failed to acquire Claude slots lock', ['lock_key' => self::CLAUDE_SLOTS_CACHE_KEY]);

            return null;
        }

        try {
            $slots = Cache::get(self::CLAUDE_SLOTS_CACHE_KEY, []);
            $originalCount = count($slots);

            // Clean expired AND stale slots in one pass
            $slots = array_filter($slots, function ($slot) use ($now) {
                // Remove expired slots
                if (($slot['expires_at'] ?? 0) <= $now) {
                    return false;
                }

                // Remove slots with dead PIDs (stale from crashed processes)
                if (isset($slot['pid']) && isset($slot['started_at'])) {
                    $age = $now - $slot['started_at'];
                    // Only check PID for slots older than 30 seconds
                    if ($age > 30 && ! $this->isProcessAlive($slot['pid'])) {
                        Log::info('AIService: Removing stale slot (dead PID)', [
                            'slot_id' => $slot['slot_id'] ?? 'unknown',
                            'pid' => $slot['pid'],
                            'age_seconds' => $age,
                        ]);

                        return false;
                    }
                }

                return true;
            });

            // If we cleaned any slots, save immediately
            if (count($slots) < $originalCount) {
                Cache::put(self::CLAUDE_SLOTS_CACHE_KEY, $slots, self::CLAUDE_SLOT_TTL + 60);
            }

            // Check if slot available (using dynamic max based on system load + Ollama health)
            $dynamicMax = $this->getDynamicClaudeMaxSlots();
            if (count($slots) >= $dynamicMax) {
                // Don't log here - caller handles wait logic and logging
                return null;
            }

            // Add new slot
            $slots[$slotId] = [
                'slot_id' => $slotId,
                'request_id' => $requestId,
                'started_at' => $now,
                'expires_at' => $now + self::CLAUDE_SLOT_TTL,
                'pid' => getmypid(),
            ];

            Cache::put(self::CLAUDE_SLOTS_CACHE_KEY, $slots, self::CLAUDE_SLOT_TTL + 60);

            Log::debug('AIService: Acquired Claude slot', [
                'slot_id' => $slotId,
                'active_slots' => count($slots),
                'dynamic_max' => $dynamicMax,
            ]);

            return $slotId;

        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Release a Claude CLI slot
     */
    private function releaseClaudeSlot(string $slotId): void
    {
        $lockKey = self::CLAUDE_SLOTS_CACHE_KEY.'_lock';

        // Simple spin lock
        $lockAcquired = false;
        $lockStart = microtime(true);
        while (! $lockAcquired && (microtime(true) - $lockStart) < 2.0) {
            $lockAcquired = Cache::add($lockKey, $slotId, 5);
            if (! $lockAcquired) {
                usleep(50000);
            }
        }

        if (! $lockAcquired) {
            Log::warning('AIService: Failed to acquire lock for releasing Claude slot', ['lock_key' => self::CLAUDE_SLOTS_CACHE_KEY]);

            return;
        }

        try {
            $slots = Cache::get(self::CLAUDE_SLOTS_CACHE_KEY, []);
            unset($slots[$slotId]);
            Cache::put(self::CLAUDE_SLOTS_CACHE_KEY, $slots, self::CLAUDE_SLOT_TTL + 60);

            Log::debug('AIService: Released Claude slot', [
                'slot_id' => $slotId,
                'remaining_slots' => count($slots),
            ]);

        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Get dynamic max concurrent Claude slots based on system resources and provider health
     *
     * Implements aggressive resource-aware auto-scaling:
     * 1. Base capacity: CLAUDE_SLOTS_PER_CORE * cpu_count (e.g., 2 * 8 cores = 16 slots)
     * 2. Scale down only when resources are constrained
     * 3. When Ollama is down: Scale UP aggressively (Claude becomes primary)
     * 4. Critical resource state: Emergency minimum mode
     *
     * Note: Logging is intentionally rate-limited to prevent log spam
     */
    public function getDynamicClaudeMaxSlots(): int
    {
        $systemLoad = $this->getSystemLoad();
        $ollamaHealth = $this->getOllamaHealthStatus();
        $cpuCount = max(1, $systemLoad['cpu_count']);

        // N119c: All thresholds from config/agents.php — per-core normalized values
        $loadScaleUp = config('agents.claude_load_scale_up', 1.5);
        $loadScaleDown = config('agents.claude_load_scale_down', 3.0);
        $loadCritical = config('agents.claude_load_critical', 5.0);
        $memMinFree = config('agents.claude_memory_min_free_mb', 512);
        $memCritical = config('agents.claude_memory_critical_mb', 256);
        $slotsPerCore = config('agents.claude_slots_per_core', 2);

        // Normalize load per CPU core for fair comparison
        $normalizedLoad = $systemLoad['load_1m'] / $cpuCount;

        // Calculate resource-based maximum (cores * slots_per_core, capped at absolute max)
        $resourceBasedMax = min(
            config('agents.claude_absolute_max', 20),
            $cpuCount * $slotsPerCore
        );

        // CRITICAL STATE: Emergency minimum mode (per-core load > 5.0 or memory < 256MB)
        if ($systemLoad['memory_free_mb'] < $memCritical || $normalizedLoad > $loadCritical) {
            $this->logScalingDecision('critical', [
                'reason' => 'Critical resources',
                'memory_free_mb' => $systemLoad['memory_free_mb'],
                'normalized_load' => $normalizedLoad,
                'threshold' => $loadCritical,
                'max_slots' => config('agents.claude_min_concurrent', 3),
            ]);

            return config('agents.claude_min_concurrent', 3);
        }

        // OLLAMA DOWN: Scale UP Claude aggressively to compensate
        if (! $ollamaHealth['healthy']) {
            if ($normalizedLoad < $loadScaleUp && $systemLoad['memory_free_mb'] > $memMinFree * 2) {
                $maxSlots = config('agents.claude_absolute_max', 20);
            } elseif ($normalizedLoad < $loadScaleDown && $systemLoad['memory_free_mb'] > $memMinFree) {
                $maxSlots = $resourceBasedMax;
            } else {
                $maxSlots = max(config('agents.claude_ollama_fallback_min', 8), (int) ceil($resourceBasedMax / 2));
            }

            $this->logScalingDecision('ollama_down', [
                'reason' => 'Ollama unhealthy - scaling up Claude',
                'ollama_status' => $ollamaHealth,
                'resource_based_max' => $resourceBasedMax,
                'max_slots' => $maxSlots,
            ]);

            return $maxSlots;
        }

        // NORMAL OPERATION: Ollama healthy, scale based on resources

        // High load - scale down moderately (Ollama handles most load)
        if ($normalizedLoad > $loadScaleDown) {
            $slots = max(config('agents.claude_min_concurrent', 3), (int) ceil($resourceBasedMax / 3));
            $this->logScalingDecision('high_load', [
                'reason' => 'High system load',
                'normalized_load' => $normalizedLoad,
                'resource_based_max' => $resourceBasedMax,
                'max_slots' => $slots,
            ]);

            return $slots;
        }

        // Low memory - scale down moderately
        if ($systemLoad['memory_free_mb'] < $memMinFree) {
            $slots = max(config('agents.claude_min_concurrent', 3), (int) ceil($resourceBasedMax / 3));
            $this->logScalingDecision('low_memory', [
                'reason' => 'Low memory',
                'memory_free_mb' => $systemLoad['memory_free_mb'],
                'resource_based_max' => $resourceBasedMax,
                'max_slots' => $slots,
            ]);

            return $slots;
        }

        // Excellent resources - allow full resource-based maximum
        if ($normalizedLoad < $loadScaleUp && $systemLoad['memory_free_mb'] > $memMinFree * 3) {
            return $resourceBasedMax;
        }

        // Good resources - use most of resource-based max
        if ($normalizedLoad < $loadScaleDown && $systemLoad['memory_free_mb'] > $memMinFree * 2) {
            return max(config('agents.claude_default_max', 10), (int) ceil($resourceBasedMax * 0.8));
        }

        // Middle ground - use default max or half of resource-based, whichever is higher
        return max(config('agents.claude_default_max', 10), (int) ceil($resourceBasedMax / 2));
    }

    /**
     * Get Ollama health status from circuit breaker and recent activity
     */
    private function getOllamaHealthStatus(): array
    {
        $circuitState = $this->getCircuitState('ollama_api');
        $isCircuitOpen = $circuitState['state'] === 'open';
        $recentFailures = $circuitState['failures'] ?? 0;
        $lastFailure = $circuitState['last_failure'] ?? null;
        $lastSuccess = $circuitState['last_success'] ?? null;

        // Check all Ollama instances
        $allInstancesDown = true;
        foreach ($this->ollamaInstances as $instance) {
            $instanceCircuit = $this->getCircuitState($instance['id']);
            if ($instanceCircuit['state'] !== 'open') {
                $allInstancesDown = false;
                break;
            }
        }

        // Consider unhealthy if:
        // 1. Main circuit is open, OR
        // 2. All instances have open circuits, OR
        // 3. Recent high failure rate (3+ failures in last 5 min without success)
        $recentFailureWindow = $lastFailure && (! $lastSuccess || $lastFailure > $lastSuccess);
        $highRecentFailures = $recentFailures >= 3 && $recentFailureWindow;

        $healthy = ! $isCircuitOpen && ! $allInstancesDown && ! $highRecentFailures;

        return [
            'healthy' => $healthy,
            'circuit_open' => $isCircuitOpen,
            'all_instances_down' => $allInstancesDown,
            'recent_failures' => $recentFailures,
            'high_failure_rate' => $highRecentFailures,
        ];
    }

    /**
     * Rate-limited logging for scaling decisions
     */
    private function logScalingDecision(string $type, array $context): void
    {
        $logKey = "ai_scale_{$type}_logged";
        if (! Cache::has($logKey)) {
            Cache::put($logKey, true, 60); // Log at most once per minute per type
            Log::info("AIService: Scaling decision - {$type}", $context);
        }
    }

    /**
     * Get system load metrics for auto-scaling decisions
     */
    public function getSystemLoad(): array
    {
        // Cache for 5 seconds to avoid frequent sys calls
        $cacheKey = 'system_load_metrics';
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // Get CPU load average
        $load = [0, 0, 0];
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg() ?: [0, 0, 0];
        }

        // Get memory info (Linux)
        $memoryFreeMb = 0;
        $memoryTotalMb = 0;
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                $memoryFreeMb = (int) ($matches[1] / 1024);
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                $memoryFreeMb = (int) ($matches[1] / 1024); // Use MemAvailable if present
            }
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                $memoryTotalMb = (int) ($matches[1] / 1024);
            }
        }

        $metrics = [
            'load_1m' => round($load[0], 2),
            'load_5m' => round($load[1], 2),
            'load_15m' => round($load[2], 2),
            'memory_free_mb' => $memoryFreeMb,
            'memory_total_mb' => $memoryTotalMb,
            'memory_used_percent' => $memoryTotalMb > 0
                ? round(($memoryTotalMb - $memoryFreeMb) / $memoryTotalMb * 100, 1)
                : 0,
            'cpu_count' => function_exists('swoole_cpu_num')
                ? swoole_cpu_num()
                : ((int) \Illuminate\Support\Facades\Process::timeout(5)->run(['nproc'])->output() ?: 1),
        ];

        Cache::put($cacheKey, $metrics, 5);

        return $metrics;
    }

    // ═══════════════════════════════════════════════════════════════════
    // OLLAMA INSTANCE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get healthy Ollama instances sorted by health score
     */
    public function getHealthyOllamaInstances(): array
    {
        // Use dynamic pool manager if available
        if ($this->useDynamicPool && $this->poolManager) {
            return $this->getHealthyOllamaInstancesFromPool();
        }

        // Legacy fallback: use hardcoded instances
        return $this->getHealthyOllamaInstancesLegacy();
    }

    /**
     * Select the best Ollama instance for a role using table-driven scoring.
     *
     * Delegates to LLMPoolManagerService::selectInstance() which scores on
     * health, latency, success rate, priority, and role-fit (bigger models
     * win heavy roles; smaller models win fast/embedding roles). Falls back
     * to the first healthy instance when the pool manager is unavailable —
     * keeps the legacy path working without requiring dynamic pool config.
     *
     * Adding a third Ollama host later is a DB-only change: insert a row in
     * llm_instances with its config.models map and its capabilities, and this
     * selector picks it up without code changes.
     *
     * @param  string  $role  'fast' | 'standard' | 'quality' | 'coding' | 'vision' | 'embedding' | 'uncensored'
     * @param  array  $config  Request config — respects allowed_ollama_instance_ids, preferred_ollama_instance_ids, urgency
     * @return array{id:string,url:string,priority:int}|null Normalized instance shape, or null if none available
     */
    private function selectOllamaInstanceForRole(string $role, array $config = []): ?array
    {
        $capabilities = ['text'];
        if ($role === 'vision') {
            $capabilities = ['vision'];
        } elseif ($role === 'embedding') {
            $capabilities = ['embedding'];
        }

        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $selected = $this->poolManager->selectInstance([
                    'role' => $role,
                    'capabilities' => $capabilities,
                    'urgency' => $config['urgency'] ?? 'normal',
                    'prefer_instance' => $config['preferred_ollama_instance_ids'][0] ?? null,
                    'exclude_instances' => array_values(array_diff(
                        array_map('strval', $this->listAllOllamaInstanceIds()),
                        array_map('strval', (array) ($config['allowed_ollama_instance_ids'] ?? []))
                    )) ?: [],
                ]);

                if ($selected !== null && ($selected['instance']->instance_type ?? '') === 'ollama') {
                    $instance = $selected['instance'];

                    return [
                        'id' => $instance->instance_id,
                        'url' => $instance->base_url,
                        'priority' => (int) $instance->priority,
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('AIService: selectOllamaInstanceForRole pool path failed, using fallback', [
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: use the legacy healthy-instance list filtered by config.
        $instances = $this->getHealthyOllamaInstancesForConfig($config);

        return $instances[0] ?? null;
    }

    /**
     * Return all healthy Ollama instances ordered by role-fit score.
     *
     * Used by paths that need to iterate through instances (e.g. vision, where
     * a busy primary should cascade to the next-best local host before giving
     * up to external APIs). The head of the list is the score winner; the rest
     * are the remaining candidates in descending score order.
     *
     * @return array<int, array{id:string,url:string,priority:int}>
     */
    private function orderOllamaInstancesForRole(string $role, array $config = []): array
    {
        $capabilities = ['text'];
        if ($role === 'vision') {
            $capabilities = ['vision'];
        } elseif ($role === 'embedding') {
            $capabilities = ['embedding'];
        }

        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $selected = $this->poolManager->selectInstance([
                    'role' => $role,
                    'capabilities' => $capabilities,
                    'urgency' => $config['urgency'] ?? 'normal',
                ]);

                if ($selected !== null && ! empty($selected['all_candidates'])) {
                    $healthy = $this->getHealthyOllamaInstances();
                    $byId = [];
                    foreach ($healthy as $inst) {
                        $byId[$inst['id']] = $inst;
                    }

                    $ordered = [];
                    foreach ($selected['all_candidates'] as $cand) {
                        $id = $cand['instance_id'] ?? null;
                        if ($id !== null && isset($byId[$id])) {
                            $ordered[] = $byId[$id];
                        }
                    }

                    if (! empty($ordered)) {
                        return $ordered;
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('AIService: orderOllamaInstancesForRole pool path failed, using fallback', [
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: legacy priority-ordered list.
        return $this->getHealthyOllamaInstancesForConfig($config);
    }

    /**
     * List every configured Ollama instance_id. Used to build the
     * exclude_instances list when the caller supplies allowed_ollama_instance_ids.
     */
    private function listAllOllamaInstanceIds(): array
    {
        if (! ($this->useDynamicPool && $this->poolManager)) {
            return array_map(fn ($inst) => $inst['id'], $this->getHealthyOllamaInstances());
        }

        try {
            $rows = $this->poolManager->getInstances(false);
            $ids = [];
            foreach ($rows as $row) {
                if (($row->instance_type ?? '') === 'ollama') {
                    $ids[] = (string) $row->instance_id;
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get healthy Ollama instances filtered by request config.
     */
    private function getHealthyOllamaInstancesForConfig(array $config): array
    {
        $instances = $this->getHealthyOllamaInstances();
        $allowed = $config['allowed_ollama_instance_ids'] ?? null;
        $preferred = $config['preferred_ollama_instance_ids'] ?? null;

        if (! is_array($allowed) || empty($allowed)) {
            return $this->sortOllamaInstancesByPreference($instances, $preferred);
        }

        $allowedSet = array_fill_keys(array_map('strval', $allowed), true);
        $filtered = array_values(array_filter($instances, function (array $instance) use ($allowedSet) {
            return isset($allowedSet[$instance['id']]);
        }));

        return $this->sortOllamaInstancesByPreference($filtered, $preferred);
    }

    private function applyPreferredOllamaInstanceOrder(array $config, ?string $modelRole): array
    {
        if (! empty($config['preferred_ollama_instance_ids']) || ! empty($config['allowed_ollama_instance_ids'])) {
            return $config;
        }

        $taskType = (string) ($config['task_type'] ?? '');
        $preferSecondary = ! in_array($modelRole, ['fast', 'vision'], true)
            && ! in_array($taskType, ['embedding', 'vision', 'fast'], true);

        if ($preferSecondary) {
            $config['preferred_ollama_instance_ids'] = ['ollama_secondary', 'ollama_primary'];
        }

        return $config;
    }

    private function sortOllamaInstancesByPreference(array $instances, mixed $preferred): array
    {
        if (! is_array($preferred) || empty($preferred)) {
            return $instances;
        }

        $preferenceOrder = array_flip(array_map('strval', $preferred));

        usort($instances, function (array $a, array $b) use ($preferenceOrder) {
            $aRank = $preferenceOrder[(string) ($a['id'] ?? '')] ?? 999;
            $bRank = $preferenceOrder[(string) ($b['id'] ?? '')] ?? 999;

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            $aPriority = (int) ($a['priority'] ?? 999);
            $bPriority = (int) ($b['priority'] ?? 999);

            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        return $instances;
    }

    /**
     * Get healthy Ollama instances from LLM Pool Manager
     * Uses dynamic routing based on health, load, and performance
     */
    private function getHealthyOllamaInstancesFromPool(): array
    {
        $healthy = [];

        try {
            $instances = $this->poolManager->getInstances(false);

            foreach ($instances as $instance) {
                // Only Ollama instances
                if ($instance->instance_type !== 'ollama') {
                    continue;
                }

                // Check circuit state via pool manager
                if (! $this->poolManager->isCircuitClosed($instance)) {
                    continue;
                }

                $healthy[] = [
                    'id' => $instance->instance_id,
                    'url' => $instance->base_url,
                    'priority' => $instance->priority,
                    'name' => $instance->instance_name,
                    'health_score' => $instance->health_score / 100,
                    'circuit_state' => $instance->circuit_state,
                    'max_concurrent' => $instance->max_concurrent,
                    'db_id' => $instance->id,
                    'from_pool' => true,
                    'supported_models' => json_decode($instance->supported_models ?? '[]', true) ?: [],
                    'config' => json_decode($instance->config ?? '{}', true) ?: [], // N119c: needed for model_role resolution
                    'context_length' => $instance->context_length ?? null,
                    'embedding_context_length' => $instance->embedding_context_length ?? null,
                ];
            }

            // Sort by priority first, then health score
            usort($healthy, function ($a, $b) {
                if ($a['priority'] !== $b['priority']) {
                    return $a['priority'] - $b['priority'];
                }

                return $b['health_score'] <=> $a['health_score'];
            });

        } catch (\Exception $e) {
            Log::warning('AIService: Pool manager query failed, falling back to legacy', [
                'error' => $e->getMessage(),
            ]);

            return $this->getHealthyOllamaInstancesLegacy();
        }

        return $healthy;
    }

    /**
     * Legacy method: Get healthy Ollama instances from hardcoded config
     * Used as fallback when pool manager unavailable
     */
    private function getHealthyOllamaInstancesLegacy(): array
    {
        $healthy = [];

        foreach ($this->ollamaInstances as $instance) {
            $circuitState = $this->getCircuitState($instance['id']);

            // Skip if circuit is open
            if ($circuitState['state'] === 'open') {
                $elapsed = time() - ($circuitState['opened_at'] ?? 0);
                if ($elapsed < config('circuit_breaker.cooldown_seconds', self::CIRCUIT_COOLDOWN_SECONDS)) {
                    continue;
                }
            }

            // Calculate health score
            $totalRequests = max($circuitState['total_requests'], 1);
            $successRate = ($totalRequests - $circuitState['total_failures']) / $totalRequests;

            $healthy[] = array_merge($instance, [
                'health_score' => $successRate,
                'circuit_state' => $circuitState['state'],
                'from_pool' => false,
            ]);
        }

        // Sort by priority first, then health score
        usort($healthy, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] - $b['priority'];
            }

            return $b['health_score'] <=> $a['health_score'];
        });

        return $healthy;
    }

    /**
     * Get all embedding-capable providers (Ollama + external) sorted by priority.
     * Ollama instances come first (local, preferred), then external by priority.
     * Filters by: embedding capability, active status, circuit breaker state, API key present.
     *
     * @return array Unified provider array with 'instance_type' to dispatch routing
     */
    private function getEmbeddingProviders(): array
    {
        $providers = [];

        // 1. Ollama instances (local, preferred) — reuse existing healthy instance logic
        $ollamaInstances = $this->getHealthyOllamaInstances();
        foreach ($ollamaInstances as $instance) {
            $instance['instance_type'] = 'ollama';
            $providers[] = $instance;
        }

        // 2. External embedding-capable providers from DB
        try {
            $externalTypes = ['openai', 'anthropic_api', 'google_gemini', 'azure_openai', 'custom'];
            $placeholders = implode(',', array_fill(0, count($externalTypes), '?'));
            $rows = DB::select("
                SELECT instance_id, instance_name, instance_type, base_url, api_key, api_key_env,
                       priority, capabilities, config, rate_limit_rpm, embedding_context_length
                FROM llm_instances
                WHERE is_active = 1
                  AND instance_type IN ({$placeholders})
                ORDER BY priority ASC
            ", $externalTypes);

            foreach ($rows as $row) {
                $capabilities = json_decode($row->capabilities ?? '{}', true) ?: [];

                // Must have embedding capability (supports both array and object format)
                if (! in_array('embedding', $capabilities) && empty($capabilities['embedding'])) {
                    continue;
                }

                // Must have API key
                $apiKey = $row->api_key ?: $this->resolveRuntimeEnvValue($row->api_key_env ?? null);
                if (! $apiKey) {
                    continue;
                }

                // Check circuit breaker
                if ($this->isCircuitOpen($row->instance_id)) {
                    continue;
                }

                $config = json_decode($row->config ?? '{}', true) ?: [];
                $embeddingModel = $config['embedding_model'] ?? null;
                $embeddingDimensions = $config['embedding_dimensions'] ?? null;

                // Must have embedding model configured
                if (! $embeddingModel) {
                    continue;
                }

                // Skip if dimensions don't match our 768-dim pgvector schema
                if ($embeddingDimensions && (int) $embeddingDimensions !== 768) {
                    Log::debug('AIService: Skipping embedding provider with incompatible dimensions', [
                        'provider' => $row->instance_id,
                        'dimensions' => $embeddingDimensions,
                        'required' => 768,
                    ]);

                    continue;
                }

                $providers[] = [
                    'id' => $row->instance_id,
                    'name' => $row->instance_name,
                    'instance_type' => $row->instance_type,
                    'base_url' => $row->base_url,
                    'api_key' => $apiKey,
                    'priority' => $row->priority,
                    'embedding_model' => $embeddingModel,
                    'embedding_dimensions' => $embeddingDimensions,
                    'embedding_context_length' => $row->embedding_context_length,
                    'extra_headers' => $config['extra_headers'] ?? [],
                    'rate_limit_rpm' => $row->rate_limit_rpm,
                    'config' => $config,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('AIService: Failed to load external embedding providers', [
                'error' => $e->getMessage(),
            ]);
        }

        return $providers;
    }

    /**
     * Get Ollama status for specific instance
     */
    public function getOllamaStatus(?string $targetModel = null, ?string $url = null): array
    {
        $targetModel = $targetModel ?? $this->defaultModel;
        $url = $url ?? $this->ollamaInstances[0]['url'];
        $cacheKey = 'ollama_status_'.md5($url);

        // Cache status for 10 seconds
        $cached = Cache::get($cacheKey);
        if ($cached && (time() - ($cached['timestamp'] ?? 0)) < 10) {
            // When Ollama is unavailable, cached response won't have current_model
            $currentModel = $cached['current_model'] ?? null;
            $installedModels = $cached['models_installed'] ?? [];
            $cached['model_installed'] = $this->isInstalledModel($targetModel, $installedModels);
            $cached['model_loaded'] = $this->isModelMatch($currentModel, $targetModel);
            $cached['needs_swap'] = $cached['model_installed'] && ! empty($currentModel) && ! $cached['model_loaded'];

            return $cached;
        }

        try {
            $tagsResponse = Http::connectTimeout(self::TIMEOUT_CONNECT)
                ->timeout(self::TIMEOUT_CONNECT)
                ->get("{$url}/api/tags");

            if (! $tagsResponse->successful()) {
                return $this->cacheStatus($cacheKey, [
                    'available' => false,
                    'error' => 'API returned '.$tagsResponse->status(),
                ]);
            }

            $models = $tagsResponse->json('models') ?? [];
            $modelNames = array_column($models, 'name');

            $psResponse = Http::connectTimeout(self::TIMEOUT_CONNECT)
                ->timeout(self::TIMEOUT_CONNECT)
                ->get("{$url}/api/ps");

            $loadedModels = [];
            $currentModel = null;
            $vramUsed = 0;

            if ($psResponse->successful()) {
                $runningModels = $psResponse->json('models') ?? [];
                foreach ($runningModels as $model) {
                    $loadedModels[] = $model['name'] ?? $model['model'] ?? 'unknown';
                    $vramUsed += $model['size_vram'] ?? 0;
                }
                $currentModel = $loadedModels[0] ?? null;
            }

            $modelLoaded = $this->isModelMatch($currentModel, $targetModel);
            $modelInstalled = $this->isInstalledModel($targetModel, $modelNames);

            // Liveness probe: a successful /api/tags + optional /api/ps round-trip
            // is enough to consider the instance reachable. Do not use /api/generate
            // here because model-missing or cold-load latency creates false "instance
            // down" failures and opens circuits on healthy nodes.
            $probeCacheKey = "ollama_gen_probe_{$url}";
            $probeResult = Cache::get($probeCacheKey);
            if ($probeResult === null) {
                Cache::put($probeCacheKey, 'ok', 60);
            }
            // If probe failed (now or cached), mark instance unavailable — triggers
            // circuit breaker in tryOllamaWithRetry and falls through to next instance
            if (Cache::get($probeCacheKey) === 'failed') {
                return $this->cacheStatus($cacheKey, [
                    'available' => false,
                    'error' => 'Generation probe failed — Ollama hung or unresponsive',
                ]);
            }

            return $this->cacheStatus($cacheKey, [
                'available' => true,
                'url' => $url,
                'models_installed' => $modelNames,
                'model_installed' => $modelInstalled,
                'current_model' => $currentModel,
                'loaded_models' => $loadedModels,
                'model_loaded' => $modelLoaded,
                'needs_swap' => $modelInstalled && ! empty($currentModel) && ! $modelLoaded,
                'vram_used_gb' => round($vramUsed / 1024 / 1024 / 1024, 2),
                'error' => null,
            ]);

        } catch (Exception $e) {
            return $this->cacheStatus($cacheKey, [
                'available' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pre-warm model on specific instance
     * Uses explicit CURL timeouts since Http::timeout() is unreliable
     */
    public function preWarmModel(string $model, ?string $url = null): array
    {
        $url = $url ?? $this->ollamaInstances[0]['url'];

        // Determine which instance this URL belongs to for busy-lock check
        $instanceId = null;
        foreach ($this->ollamaInstances as $inst) {
            if (($inst['url'] ?? '') === $url) {
                $instanceId = $inst['id'] ?? null;
                break;
            }
        }

        // Skip pre-warm if Ollama is busy — the actual process() call will
        // route through the full provider chain (external LLMs don't need pre-warming)
        if ($this->isOllamaBusy($instanceId)) {
            Log::info('AIService: Skipping pre-warm, Ollama busy', ['model' => $model, 'url' => $url]);

            return ['status' => 'skipped', 'reason' => 'busy'];
        }

        // Skip if Whisper is using the GPU
        if ($this->isWhisperRunning()) {
            Log::info('AIService: Skipping pre-warm, Whisper using GPU', ['model' => $model]);

            return ['status' => 'skipped', 'reason' => 'whisper_running'];
        }

        // Skip if circuit is open for this instance
        if ($instanceId && $this->isCircuitOpen($instanceId)) {
            Log::info('AIService: Skipping pre-warm, circuit open', ['model' => $model, 'instance' => $instanceId]);

            return ['status' => 'skipped', 'reason' => 'circuit_open'];
        }

        Log::info('AIService: Pre-warming model', ['model' => $model, 'url' => $url]);

        try {
            $response = Http::withOptions([
                'timeout' => self::TIMEOUT_MODEL_LOADING,
                'connect_timeout' => 10,
            ])
                ->post("{$url}/api/generate", [
                    'model' => $model,
                    'prompt' => 'hi',
                    'think' => false,
                    'stream' => false,
                    'options' => ['num_predict' => 1],
                ]);

            $success = $response->successful();
            Log::info('AIService: Pre-warm completed', ['model' => $model, 'success' => $success]);

            return [
                'status' => $success ? 'success' : 'failed',
                'reason' => $success ? null : 'unsuccessful_response',
            ];
        } catch (Exception $e) {
            Log::warning('AIService: Pre-warm failed', ['error' => $e->getMessage()]);

            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // UTILITY METHODS
    // ═══════════════════════════════════════════════════════════════════

    private function calculateTimeout(array $ollamaStatus, string $targetModel): int
    {
        if ($ollamaStatus['model_loaded']) {
            return self::TIMEOUT_MODEL_LOADED;
        }
        if ($ollamaStatus['needs_swap'] ?? false) {
            return self::TIMEOUT_MODEL_SWAP;
        }

        return self::TIMEOUT_MODEL_LOADING;
    }

    private function applyCallerTimeoutCap(int $calculatedTimeout, mixed $requestedTimeout): int
    {
        $requested = is_numeric($requestedTimeout) ? (int) $requestedTimeout : 0;
        if ($requested <= 0) {
            return $calculatedTimeout;
        }

        return min($calculatedTimeout, max(1, $requested));
    }

    private function resolveOllamaTimeout(array $ollamaStatus, int $calculatedTimeout, mixed $requestedTimeout): int
    {
        $requested = is_numeric($requestedTimeout) ? (int) $requestedTimeout : 0;
        if ($requested <= 0) {
            return $calculatedTimeout;
        }

        if (! ($ollamaStatus['model_loaded'] ?? false)) {
            return max($calculatedTimeout, $requested);
        }

        return $this->applyCallerTimeoutCap($calculatedTimeout, $requested);
    }

    private function isModelMatch(?string $current, string $target): bool
    {
        if (! $current) {
            return false;
        }
        if ($current === $target) {
            return true;
        }

        return explode(':', $current)[0] === explode(':', $target)[0];
    }

    private function isInstalledModel(?string $targetModel, array $installedModels): bool
    {
        if (! $targetModel) {
            return false;
        }

        foreach ($installedModels as $installedModel) {
            if ($this->isModelMatch($installedModel, $targetModel)) {
                return true;
            }
        }

        return false;
    }

    private function cacheStatus(string $key, array $status): array
    {
        $status['timestamp'] = time();
        Cache::put($key, $status, 30);

        return $status;
    }

    /**
     * AI-4: Wait for an in-flight duplicate request to finish by polling semantic cache.
     */
    private function waitForInflightResult(string $prompt, array $cacheContext, int $maxWaitSeconds): ?array
    {
        if (! $this->semanticCache) {
            return null;
        }

        for ($i = 0; $i < $maxWaitSeconds; $i++) {
            usleep(1000000); // 1 second

            $result = $this->semanticCache->get($prompt, $cacheContext);
            if ($result !== null) {
                return $result;
            }
        }

        return null; // Timed out — caller will proceed with its own call
    }

    /**
     * Log AI provider failures (Pushover removed — circuit breaker + provider
     * fallback handle recovery autonomously; daily report surfaces via LLM
     * usage distribution and job failure rates).
     */
    private function sendFailureAlert(array $attempts, string $prompt): void
    {
        Log::warning('AIService: All providers failed for request', [
            'providers' => array_map(fn ($e) => mb_substr($e, 0, 100), $attempts),
            'prompt_prefix' => mb_substr($prompt, 0, 80),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // HEALTH & DIAGNOSTICS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get comprehensive health stats for all providers
     */
    public function getHealthStats(): array
    {
        $instances = [];
        foreach ($this->ollamaInstances as $instance) {
            $status = $this->getOllamaStatus($this->defaultModel, $instance['url']);
            $circuit = $this->getCircuitState($instance['id']);

            $instances[$instance['id']] = [
                'name' => $instance['name'],
                'url' => $instance['url'],
                'available' => $status['available'],
                'model_loaded' => $status['model_loaded'] ?? false,
                'current_model' => $status['current_model'] ?? null,
                'vram_used_gb' => $status['vram_used_gb'] ?? 0,
                'circuit_state' => $circuit['state'],
                'circuit_failures' => $circuit['failures'],
                'success_rate' => $circuit['total_requests'] > 0
                    ? round(($circuit['total_requests'] - $circuit['total_failures']) / $circuit['total_requests'] * 100, 1).'%'
                    : 'N/A',
            ];
        }

        $claudeCircuit = $this->getCircuitState('claude_cli');

        return [
            'ollama_instances' => $instances,
            'claude_cli' => [
                'available' => $this->aiRouter->isClaudeCLIAvailable(),
                'circuit_state' => $claudeCircuit['state'],
                'circuit_failures' => $claudeCircuit['failures'],
            ],
            'config' => [
                'circuit_threshold' => config('circuit_breaker.failure_threshold', self::CIRCUIT_FAILURE_THRESHOLD),
                'circuit_cooldown' => config('circuit_breaker.cooldown_seconds', self::CIRCUIT_COOLDOWN_SECONDS).'s',
                'max_retries' => self::MAX_RETRIES,
                'backoff_initial' => self::INITIAL_BACKOFF_MS.'ms',
                'backoff_max' => self::MAX_BACKOFF_MS.'ms',
            ],
        ];
    }

    /**
     * Reset circuit breaker for provider
     */
    public function resetCircuit(string $providerId): void
    {
        $this->circuitBreaker->reset($providerId);
        Cache::forget("ai_circuit_stats_{$providerId}");
        Log::info("AIService: Circuit reset for {$providerId}");
    }

    /**
     * Get underlying AIRouter
     */
    public function getRouter(): AIRouter
    {
        return $this->aiRouter;
    }

    // ═══════════════════════════════════════════════════════════════════
    // QUEUE DEPTH MONITORING (E01 Phase 3.4)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get queue depth monitor instance
     *
     * @param  array  $options  Monitor options
     */
    public function getQueueMonitor(array $options = []): QueueDepthMonitor
    {
        return new QueueDepthMonitor($options);
    }

    /**
     * Get queue metrics for dashboard/monitoring
     *
     * @return array Queue depth metrics
     */
    public function getQueueMetrics(): array
    {
        $monitor = $this->getQueueMonitor();

        return $monitor->getMetrics();
    }

    /**
     * Check if service should reject requests due to overload
     *
     * E01 Phase 3.4: Load shedding when queue is critical.
     *
     * @return bool True if requests should be rejected
     */
    public function shouldRejectRequest(): bool
    {
        $monitor = $this->getQueueMonitor();

        return $monitor->shouldShedLoad();
    }

    /**
     * Get estimated wait time for new requests
     *
     * @return float Estimated wait in seconds
     */
    public function getEstimatedWaitTime(): float
    {
        $monitor = $this->getQueueMonitor();

        // Estimate 2s per request with available workers
        $workers = $this->estimateActiveWorkers();

        return $monitor->getEstimatedWaitTime(2.0, $workers);
    }

    /**
     * Estimate number of active workers based on healthy providers
     */
    private function estimateActiveWorkers(): int
    {
        $healthyInstances = count($this->getHealthyOllamaInstances());
        $claudeAvailable = ! $this->isCircuitOpen('claude_cli') ? 1 : 0;

        return max($healthyInstances + $claudeAvailable, 1);
    }

    // ═══════════════════════════════════════════════════════════════════
    // SEMANTIC CACHE MANAGEMENT (E01 Phase 3.5)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get semantic cache instance
     */
    public function getCache(): ?SemanticCache
    {
        return $this->semanticCache;
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        if (! $this->semanticCache) {
            return ['enabled' => false];
        }

        return array_merge(
            ['enabled' => true],
            $this->semanticCache->getStats()
        );
    }

    /**
     * Clear semantic cache
     */
    public function clearCache(): void
    {
        if ($this->semanticCache) {
            $this->semanticCache->invalidate('*');
        }
    }

    /**
     * Enable or disable caching
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    /**
     * Estimate cost savings from cache
     *
     * @param  float  $costPerRequest  Cost per AI request in USD
     */
    public function estimateCacheSavings(float $costPerRequest = 0.01): array
    {
        if (! $this->semanticCache) {
            return ['enabled' => false];
        }

        return $this->semanticCache->estimateSavings($costPerRequest);
    }

    // ═══════════════════════════════════════════════════════════════════
    // UNIFIED CONTENT EXTRACTION (v3.0)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Extract content from any file type - CENTRALIZED EXTRACTION GATEWAY
     *
     * ALL media/document extraction should flow through this method.
     * Pipeline order:
     * 1. Tika (first) - Fast, handles 1000+ formats, built-in OCR
     * 2. Vision AI (images/scanned PDFs) - Ollama→Claude failover
     * 3. OCR/Tesseract (fallback if vision fails)
     * 4. Whisper (audio/video transcription)
     *
     * @param  string  $filePath  Path to file OR raw content with $options['is_content']=true
     * @param  array  $options  Extraction options:
     *                          - use_tika: bool (default true) - Try Tika first
     *                          - use_vision: bool (default true) - Use AI vision for images
     *                          - use_ocr: bool (default true) - Fallback to Tesseract
     *                          - use_whisper: bool (default true) - Transcribe audio/video
     *                          - include_metadata: bool (default true) - Extract metadata
     *                          - is_content: bool (default false) - filePath is raw content, not a path
     *                          - mime_type: string - Override MIME type detection
     *                          - filename: string - Original filename hint
     * @return array ['success' => bool, 'text' => string, 'method' => string, 'metadata' => array]
     */
    public function extractContent(string $filePath, array $options = []): array
    {
        $startTime = microtime(true);

        // Merge defaults
        $options = array_merge([
            'use_tika' => true,
            'use_vision' => true,
            'use_ocr' => true,
            'use_whisper' => true,
            'include_metadata' => true,
            'is_content' => false,
            'mime_type' => null,
            'filename' => null,
        ], $options);

        // Handle raw content vs file path
        $isRawContent = $options['is_content'];
        $filename = $options['filename'] ?? ($isRawContent ? 'content' : basename($filePath));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Validate file exists (if not raw content)
        if (! $isRawContent && ! file_exists($filePath)) {
            return [
                'success' => false,
                'text' => '',
                'error' => "File not found: {$filePath}",
                'method' => 'none',
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }

        // Get content and MIME type
        $content = $isRawContent ? $filePath : file_get_contents($filePath);
        $mimeType = $options['mime_type'] ?? ($isRawContent ? null : mime_content_type($filePath));

        Log::info('AIService::extractContent starting', [
            'filename' => $filename,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => strlen($content),
        ]);

        // Determine file category
        $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp', 'heic']);
        $isPdf = $extension === 'pdf';
        $isAudio = in_array($extension, ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'wma']);
        $isVideo = in_array($extension, ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'webm', 'm4v']);

        $result = [
            'success' => false,
            'text' => '',
            'method' => 'none',
            'metadata' => [],
            'attempts' => [],
        ];

        // ═══════════════════════════════════════════════════════════════
        // STEP 1: TIKA (Primary for documents)
        // ═══════════════════════════════════════════════════════════════
        if ($options['use_tika'] && ! $isAudio && ! $isVideo) {
            $tikaResult = $this->extractWithTika($content, $mimeType, $filename, $options);
            $result['attempts']['tika'] = $tikaResult['success'];

            if ($tikaResult['success'] && ! empty(trim($tikaResult['text']))) {
                // Tika succeeded with substantial text
                $result['success'] = true;
                $result['text'] = $tikaResult['text'];
                $result['method'] = 'tika';
                $result['metadata'] = $tikaResult['metadata'] ?? [];

                // For images, even if Tika extracted text, vision might provide better description
                if (! $isImage || strlen(trim($tikaResult['text'])) > 100) {
                    $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                    Log::info('AIService::extractContent completed via Tika', [
                        'filename' => $filename,
                        'text_length' => strlen($result['text']),
                    ]);

                    return $result;
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // STEP 1.5: PHP OFFICE (Fallback for Office documents when Tika fails)
        // ═══════════════════════════════════════════════════════════════
        $isOfficeDoc = in_array($extension, ['docx', 'xlsx', 'xls']);
        if ($isOfficeDoc && empty(trim($result['text']))) {
            $phpOfficeResult = $this->extractWithPhpOffice($content, $filename);
            $result['attempts']['phpoffice'] = $phpOfficeResult['success'];

            if ($phpOfficeResult['success'] && ! empty(trim($phpOfficeResult['text']))) {
                $result['success'] = true;
                $result['text'] = $phpOfficeResult['text'];
                $result['method'] = 'phpoffice';
                $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                Log::info('AIService::extractContent completed via PhpOffice (Tika fallback)', [
                    'filename' => $filename,
                    'text_length' => strlen($result['text']),
                ]);

                return $result;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // STEP 2: VISION AI (Images and scanned PDFs)
        // ═══════════════════════════════════════════════════════════════
        if ($options['use_vision'] && ($isImage || ($isPdf && empty(trim($result['text']))))) {
            $visionResult = $this->visionExtractContent($isRawContent ? null : $filePath, $content, $filename, $options);
            $result['attempts']['vision'] = $visionResult['success'];

            if ($visionResult['success'] && ! empty($visionResult['text'])) {
                $result['success'] = true;
                $result['text'] = $visionResult['text'];
                $result['method'] = 'vision';
                $result['provider'] = $visionResult['provider'] ?? 'unknown';
                $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                Log::info('AIService::extractContent completed via Vision', [
                    'filename' => $filename,
                    'provider' => $result['provider'],
                    'text_length' => strlen($result['text']),
                ]);

                return $result;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // STEP 3: OCR/TESSERACT (Fallback for images/scanned docs)
        // ═══════════════════════════════════════════════════════════════
        if ($options['use_ocr'] && ($isImage || $isPdf) && empty(trim($result['text']))) {
            $ocrResult = $this->extractWithOcr($isRawContent ? null : $filePath, $content, $extension);
            $result['attempts']['ocr'] = $ocrResult['success'];

            if ($ocrResult['success'] && ! empty($ocrResult['text'])) {
                $result['success'] = true;
                $result['text'] = $ocrResult['text'];
                $result['method'] = 'tesseract';
                $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                Log::info('AIService::extractContent completed via OCR', [
                    'filename' => $filename,
                    'text_length' => strlen($result['text']),
                ]);

                return $result;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // STEP 4: WHISPER (Audio/Video transcription)
        // ═══════════════════════════════════════════════════════════════
        if ($options['use_whisper'] && ($isAudio || $isVideo)) {
            $whisperResult = $this->extractWithWhisper($isRawContent ? null : $filePath, $content, $extension);
            $result['attempts']['whisper'] = $whisperResult['success'];

            if ($whisperResult['success']) {
                $result['success'] = true;
                $result['text'] = $this->formatAudioTranscript(
                    (string) $whisperResult['text'],
                    $isRawContent ? $filename : $filePath
                );
                $result['method'] = 'whisper';
                $result['metadata'] = array_merge($result['metadata'], $whisperResult['metadata'] ?? []);
                $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                Log::info('AIService::extractContent completed via Whisper', [
                    'filename' => $filename,
                    'text_length' => strlen($result['text']),
                ]);

                return $result;
            }
        }

        // If we have partial success from any step, return it
        if (! empty(trim($result['text']))) {
            $result['success'] = true;
        }

        $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

        // Log extraction failures for human review (no Pushover alert - just move on)
        if (! $result['success'] && empty(trim($result['text']))) {
            Log::channel('extraction_failures')->info('Extraction failed - requires human review', [
                'file_path' => $isRawContent ? '[raw_content]' : $filePath,
                'filename' => $filename,
                'extension' => $extension,
                'attempts' => $result['attempts'],
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        Log::warning('AIService::extractContent completed with limited success', [
            'filename' => $filename,
            'method' => $result['method'],
            'attempts' => $result['attempts'],
        ]);

        return $result;
    }

    /**
     * Extract using Apache Tika server
     */
    private function extractWithTika(string $content, ?string $mimeType, string $filename, array $options): array
    {
        $tikaUrl = config('services.tika.url', 'http://127.0.0.1:9998');
        $timeout = config('services.tika.timeout', 120);

        try {
            // Check Tika health first (with circuit breaker)
            if ($this->isCircuitOpen('tika_server')) {
                return ['success' => false, 'error' => 'Tika circuit open'];
            }

            $tikaStartTime = microtime(true);

            // Build headers
            $headers = ['Accept' => 'text/plain'];
            if ($mimeType) {
                $headers['Content-Type'] = $mimeType;
            }
            if ($filename) {
                $headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
            }

            // Include OCR option
            $ocrStrategy = $options['ocr_strategy'] ?? 'ocr_and_text';
            $headers['X-Tika-OCRskipOcr'] = $ocrStrategy === 'no_ocr' ? 'true' : 'false';

            // Extract text via Tika
            $response = Http::connectTimeout(5)->timeout($timeout)
                ->withHeaders($headers)
                ->withBody($content, $mimeType ?? 'application/octet-stream')
                ->put("{$tikaUrl}/tika");

            $tikaDuration = microtime(true) - $tikaStartTime;

            if (! $response->successful()) {
                $this->recordFailure('tika_server');

                return [
                    'success' => false,
                    'error' => 'Tika extraction failed: HTTP '.$response->status(),
                ];
            }

            $this->recordSuccess('tika_server', $tikaDuration);
            $extractedText = trim($response->body());

            // Extract metadata if requested
            $metadata = [];
            if ($options['include_metadata'] ?? true) {
                try {
                    $metaResponse = Http::connectTimeout(5)->timeout(30)
                        ->withHeaders(['Accept' => 'application/json'])
                        ->withBody($content, $mimeType ?? 'application/octet-stream')
                        ->put("{$tikaUrl}/meta");

                    if ($metaResponse->successful()) {
                        $metadata = $metaResponse->json() ?? [];
                    }
                } catch (\Exception $e) {
                    // Metadata extraction is optional, don't fail
                    Log::debug('Tika metadata extraction failed', ['error' => $e->getMessage()]);
                }
            }

            return [
                'success' => true,
                'text' => $extractedText,
                'metadata' => $metadata,
                'extractor' => 'tika',
            ];

        } catch (\Exception $e) {
            $this->recordFailure('tika_server');
            Log::error('Tika extraction error', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract text from Office documents using PHP-native libraries (fallback when Tika fails)
     * Supports: .docx (PhpWord), .xlsx (PhpSpreadsheet)
     */
    private function extractWithPhpOffice(string $content, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Save content to temp file (phpoffice libraries need file paths)
        $tempFile = tempnam(sys_get_temp_dir(), 'phpoffice_');
        $tempPath = $tempFile.'.'.$extension;
        rename($tempFile, $tempPath);
        file_put_contents($tempPath, $content);

        try {
            $extractedText = '';

            if ($extension === 'docx') {
                // Extract from Word document
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($tempPath);
                $sections = $phpWord->getSections();

                foreach ($sections as $section) {
                    foreach ($section->getElements() as $element) {
                        $extractedText .= $this->extractTextFromPhpWordElement($element);
                    }
                }
            } elseif ($extension === 'xlsx' || $extension === 'xls') {
                // Extract from Excel spreadsheet
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tempPath);

                foreach ($spreadsheet->getAllSheets() as $sheet) {
                    $sheetName = $sheet->getTitle();
                    $extractedText .= "=== Sheet: {$sheetName} ===\n";

                    foreach ($sheet->getRowIterator() as $row) {
                        $rowData = [];
                        foreach ($row->getCellIterator() as $cell) {
                            $value = $cell->getValue();
                            if ($value !== null && $value !== '') {
                                $rowData[] = $value;
                            }
                        }
                        if (! empty($rowData)) {
                            $extractedText .= implode("\t", $rowData)."\n";
                        }
                    }
                    $extractedText .= "\n";
                }
            } else {
                return [
                    'success' => false,
                    'error' => "PhpOffice: Unsupported extension: {$extension}",
                ];
            }

            $extractedText = trim($extractedText);

            if (empty($extractedText)) {
                return [
                    'success' => false,
                    'error' => 'PhpOffice: No text extracted',
                ];
            }

            Log::info('AIService::extractWithPhpOffice succeeded', [
                'filename' => $filename,
                'text_length' => strlen($extractedText),
            ]);

            return [
                'success' => true,
                'text' => $extractedText,
                'extractor' => 'phpoffice',
            ];

        } catch (\Exception $e) {
            Log::warning('AIService::extractWithPhpOffice failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'PhpOffice: '.$e->getMessage(),
            ];
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Recursively extract text from PhpWord elements
     */
    private function extractTextFromPhpWordElement($element): string
    {
        $text = '';

        // Handle Text elements (leaf nodes with actual text)
        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            return $element->getText() ?? '';
        }

        // Handle TextRun (container of Text elements)
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromPhpWordElement($child);
            }

            return $text."\n";
        }

        // Handle containers with getElements()
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromPhpWordElement($child);
            }
        }

        // Handle elements with getText() that aren't Text instances
        if (method_exists($element, 'getText') && ! ($element instanceof \PhpOffice\PhpWord\Element\Text)) {
            $elementText = $element->getText();
            if (is_string($elementText)) {
                $text .= $elementText."\n";
            }
        }

        return $text;
    }

    /**
     * Convert the first page of a PDF to a base64-encoded PNG for vision AI.
     * Returns null if pdftoppm is unavailable or conversion fails.
     */
    private function pdfToImage(string $pdfPath): ?string
    {
        $outputDir = storage_path('app/temp/pdf_img_'.uniqid());
        @mkdir($outputDir, 0755, true);

        try {
            $result = \Illuminate\Support\Facades\Process::timeout(60)->run(
                'pdftoppm -png -r 150 -l 1 '.escapeshellarg($pdfPath).' '.escapeshellarg($outputDir.'/page')
            );

            if (! $result->successful()) {
                @rmdir($outputDir);

                return null;
            }

            $images = glob($outputDir.'/*.png');
            if (empty($images)) {
                @rmdir($outputDir);

                return null;
            }

            sort($images);
            $imageData = @file_get_contents($images[0]);
            array_map('unlink', $images);
            @rmdir($outputDir);

            return $imageData ? base64_encode($imageData) : null;

        } catch (\Exception $e) {
            Log::warning('AIService: PDF thumbnail extraction failed', ['error' => $e->getMessage()]);
            array_map('unlink', glob($outputDir.'/*') ?: []);
            @rmdir($outputDir);

            return null;
        }
    }

    /**
     * Extract content using Vision AI (Ollama/Claude) for content extraction pipeline
     */
    private function visionExtractContent(?string $filePath, string $content, string $filename, array $options): array
    {
        try {
            // For PDFs, convert to image first
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $tempPdfPath = null;
            $origin = $filePath ?: $filename;

            if ($extension === 'pdf') {
                // Save content to temp file if needed (pdfToImage needs a file path)
                if (! $filePath) {
                    $tempPdfPath = storage_path('app/temp/vision_'.uniqid().'.pdf');
                    file_put_contents($tempPdfPath, $content);
                    $filePath = $tempPdfPath;
                }

                // Convert first page to image - pdfToImage returns image content directly
                $imageContent = $this->pdfToImage($filePath);

                // Clean up temp PDF if we created it
                if ($tempPdfPath) {
                    @unlink($tempPdfPath);
                }

                if (! $imageContent) {
                    return ['success' => false, 'error' => 'PDF to image conversion failed'];
                }
            } else {
                $imageContent = $content;
            }

            // Use existing processImage method (has circuit breaker, retry, failover)
            // suppressAlert=true because extractContent has OCR fallback
            $prompt = 'Extract ALL text from this image. If it is a document, transcribe the complete text content. If it is a photo, describe what you see in detail including any visible text.';

            $result = $this->processImage($imageContent, $prompt, ['suppressAlert' => true]);

            return [
                'success' => $result['success'],
                'text' => ! empty($result['response'])
                    ? $this->formatVisionExtractionText((string) $result['response'], $origin)
                    : '',
                'provider' => $result['provider'] ?? 'unknown',
            ];

        } catch (\Exception $e) {
            Log::error('Vision extraction error', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract using Tesseract OCR
     */
    private function extractWithOcr(?string $filePath, string $content, string $extension): array
    {
        $tempFile = null;

        try {
            // Save content to temp file if needed
            if (! $filePath) {
                $tempFile = storage_path('app/temp/ocr_'.uniqid().'.'.$extension);
                file_put_contents($tempFile, $content);
                $filePath = $tempFile;
            }

            // For PDFs, convert to images first
            if ($extension === 'pdf') {
                $text = $this->ocrPdf($filePath);
            } else {
                // Direct OCR for images
                $result = \Illuminate\Support\Facades\Process::timeout(120)
                    ->run(['tesseract', $filePath, 'stdout', '-l', 'eng']);

                $text = $result->successful() ? trim($result->output()) : '';
            }

            return [
                'success' => ! empty($text),
                'text' => $text,
            ];

        } catch (\Exception $e) {
            Log::error('OCR extraction error', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Extract using Whisper transcription
     */
    private function extractWithWhisper(?string $filePath, string $content, string $extension): array
    {
        $tempFile = null;
        $audioFile = null;
        $outputDir = null;

        try {
            // Save content to temp file if needed
            if (! $filePath) {
                $tempFile = storage_path('app/temp/whisper_'.uniqid().'.'.$extension);
                file_put_contents($tempFile, $content);
                $filePath = $tempFile;
            }

            // Find Whisper
            $whisperPath = $this->findWhisperPath();
            if (! $whisperPath) {
                if ($tempFile) {
                    @unlink($tempFile);
                }

                return ['success' => false, 'error' => 'Whisper not available'];
            }

            // For video, extract audio track first
            $isVideo = in_array($extension, ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'webm', 'm4v']);
            $audioFile = $filePath;

            if ($isVideo) {
                $audioFile = storage_path('app/temp/audio_'.uniqid().'.wav');
                $ffmpegResult = \Illuminate\Support\Facades\Process::timeout(120)->run([
                    'ffmpeg', '-i', $filePath,
                    '-vn', '-acodec', 'pcm_s16le',
                    '-ar', '16000', '-ac', '1',
                    '-y', $audioFile,
                ]);

                if (! $ffmpegResult->successful() || ! file_exists($audioFile)) {
                    if ($tempFile) {
                        @unlink($tempFile);
                    }

                    return ['success' => false, 'error' => 'Audio extraction failed'];
                }
            }

            // Run Whisper
            $outputDir = storage_path('app/temp/whisper_out_'.uniqid());
            @mkdir($outputDir, 0755, true);

            $model = config('services.whisper.model', 'base');
            $result = \Illuminate\Support\Facades\Process::timeout(300)->run([
                $whisperPath, $audioFile,
                '--model', $model,
                '--output_dir', $outputDir,
                '--output_format', 'txt',
            ]);

            // Get transcription
            $transcription = '';
            if ($result->successful()) {
                $txtFiles = glob($outputDir.'/*.txt');
                if (! empty($txtFiles)) {
                    $transcription = trim(file_get_contents($txtFiles[0]));
                }
            }

            // Extract metadata via ffprobe
            $metadata = $this->extractMediaMetadata($filePath);

            return [
                'success' => ! empty($transcription),
                'text' => $transcription,
                'metadata' => $metadata,
            ];

        } catch (\Exception $e) {
            Log::error('Whisper extraction error', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            foreach (glob(($outputDir ?? '').'/*') ?: [] as $artifact) {
                @unlink($artifact);
            }
            if ($outputDir && is_dir($outputDir)) {
                @rmdir($outputDir);
            }
            if ($audioFile && $audioFile !== $filePath && file_exists($audioFile)) {
                @unlink($audioFile);
            }
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function formatAudioTranscript(string $transcription, string $origin): string
    {
        return $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'audio_transcript',
            contentType: 'text/plain',
            origin: $origin,
            payload: $transcription,
        ));
    }

    private function formatVisionExtractionText(string $text, string $origin): string
    {
        return $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'vision_image',
            contentType: 'text/plain',
            origin: $origin,
            payload: $text,
        ));
    }

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    /**
     * OCR a PDF by converting pages to images
     */
    private function ocrPdf(string $pdfPath): string
    {
        $outputDir = storage_path('app/temp/pdf_ocr_'.uniqid());
        @mkdir($outputDir, 0755, true);

        try {
            // Convert PDF to images
            $result = \Illuminate\Support\Facades\Process::timeout(120)->run([
                'pdftoppm',
                '-png',
                '-r',
                '150',
                $pdfPath,
                $outputDir.'/page',
            ]);

            if (! $result->successful()) {
                return '';
            }

            $images = glob($outputDir.'/*.png');
            sort($images);

            $allText = [];
            foreach ($images as $i => $img) {
                $ocrResult = \Illuminate\Support\Facades\Process::timeout(120)
                    ->run(['tesseract', $img, 'stdout', '-l', 'eng']);

                if ($ocrResult->successful() && trim($ocrResult->output())) {
                    $allText[] = '--- Page '.($i + 1)." ---\n".trim($ocrResult->output());
                }
                @unlink($img);
            }

            @rmdir($outputDir);

            return implode("\n\n", $allText);

        } catch (\Exception $e) {
            Log::warning('AIService: PDF OCR extraction failed', ['error' => $e->getMessage()]);
            array_map('unlink', glob($outputDir.'/*'));
            @rmdir($outputDir);

            return '';
        }
    }

    /**
     * Find Whisper executable
     */
    private function findWhisperPath(): ?string
    {
        $paths = [
            config('services.whisper.path'),
            '/usr/local/bin/whisper',
            '/usr/bin/whisper',
            $this->resolveRuntimeEnvValue('HOME').'/.local/bin/whisper',
        ];

        foreach ($paths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try 'which'
        try {
            $result = \Illuminate\Support\Facades\Process::timeout(5)->run(['which', 'whisper']);
            if ($result->successful()) {
                $path = trim($result->output());
                if (! empty($path) && file_exists($path) && is_executable($path)) {
                    return $path;
                }
            }
        } catch (\Exception $e) {
            Log::debug('AIService: whisper path lookup failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract media metadata via ffprobe
     */
    private function extractMediaMetadata(string $filePath): array
    {
        try {
            $result = \Illuminate\Support\Facades\Process::timeout(30)->run([
                'ffprobe', '-v', 'quiet', '-print_format', 'json',
                '-show_format', '-show_streams', $filePath,
            ]);

            if ($result->successful()) {
                $json = json_decode($result->output(), true);
                $metadata = [];

                if (! empty($json['format'])) {
                    $format = $json['format'];
                    if (! empty($format['duration'])) {
                        $metadata['duration_seconds'] = (float) $format['duration'];
                    }
                    if (! empty($format['tags'])) {
                        $tags = array_change_key_case($format['tags'], CASE_LOWER);
                        if (! empty($tags['title'])) {
                            $metadata['title'] = $tags['title'];
                        }
                        if (! empty($tags['artist'])) {
                            $metadata['artist'] = $tags['artist'];
                        }
                        if (! empty($tags['album'])) {
                            $metadata['album'] = $tags['album'];
                        }
                    }
                }

                return $metadata;
            }
        } catch (\Exception $e) {
            Log::debug('ffprobe failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Check if Tika server is available
     */
    public function isTikaAvailable(): bool
    {
        try {
            $tikaUrl = config('services.tika.url', 'http://127.0.0.1:9998');
            $response = Http::connectTimeout(5)->timeout(5)->get("{$tikaUrl}/version");

            return $response->successful();
        } catch (\Exception $e) {
            Log::debug('AIService: Tika availability check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get Tika server info
     */
    public function getTikaInfo(): array
    {
        try {
            $tikaUrl = config('services.tika.url', 'http://127.0.0.1:9998');
            $response = Http::connectTimeout(5)->timeout(5)->get("{$tikaUrl}/version");

            if ($response->successful()) {
                return [
                    'available' => true,
                    'version' => trim($response->body()),
                    'url' => $tikaUrl,
                ];
            }
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }

        return ['available' => false];
    }

    // ═══════════════════════════════════════════════════════════════════
    // AI-1: MODEL CASCADING HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Decide whether cascade quality evaluation should run for this request.
     *
     * Skipped when:
     *  - CASCADE_ENABLED is false globally
     *  - This is already a cascaded retry (_cascade_attempt flag)
     *  - prefer_claude or prefer_external is set (caller has already routed intentionally)
     *  - model_role is 'quality' (already targeting a strong model)
     */
    private function shouldCascade(array $config): bool
    {
        if (! config('cascade.enabled', true)) {
            return false;
        }

        if (! empty($config['_cascade_attempt'])) {
            return false; // Prevent recursive cascading
        }

        $skipIfSet = config('cascade.skip_if_set', ['prefer_claude', 'prefer_external']);
        foreach ($skipIfSet as $key) {
            if (! empty($config[$key])) {
                return false;
            }
        }

        $role = $config['model_role'] ?? self::$agentModelRole ?? 'standard';
        $skipRoles = config('cascade.skip_roles', ['quality']);
        if (in_array($role, $skipRoles, true)) {
            return false;
        }

        return true;
    }

    /**
     * Run the quality evaluator and return the cascade decision.
     */
    private function evaluateCascade(string $prompt, string $response, array $config, float $startTime): array
    {
        try {
            $evaluator = app(CascadeQualityEvaluator::class);

            // Optionally inject self (AIService) for self-assessment calls
            $evalConfig = $config;
            if ($config['cascade']['self_assess'] ?? config('cascade.self_assess_enabled', false)) {
                $evalConfig['_ai_service'] = $this;
            }

            return $evaluator->evaluate($prompt, $response, $evalConfig);
        } catch (\Throwable $e) {
            Log::warning('AIService: Cascade evaluator threw, skipping escalation', [
                'error' => $e->getMessage(),
            ]);

            return ['escalate' => false, 'score' => 1.0, 'reason' => 'evaluator error', 'signals' => []];
        }
    }

    /**
     * Persist a cascade event to llm_cascade_log when a cascaded request resolves.
     * No-op when the request was not a cascade escalation.
     */
    private function logCascadeResult(array $config, array $result): void
    {
        if (empty($config['_cascade_attempt']) || empty($config['_cascade_initial'])) {
            return;
        }

        $initial = $config['_cascade_initial'];

        try {
            DB::insert(
                'INSERT INTO llm_cascade_log
                    (prompt_hash, caller, initial_provider, initial_model, escalated,
                     escalation_reason, escalated_provider, escalated_model,
                     quality_score, signals, latency_initial_ms, latency_escalated_ms, created_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $initial['prompt_hash'] ?? '',
                    self::$agentModelRole ? (self::$agentModelRole.'_agent') : 'direct',
                    $initial['provider'] ?? 'ollama',
                    $initial['model'] ?? null,
                    $initial['reason'] ?? null,
                    $result['provider'] ?? null,
                    $result['model'] ?? null,
                    $initial['score'] ?? null,
                    json_encode($initial['signals'] ?? []),
                    $initial['latency_ms'] ?? null,
                    $result['duration_ms'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('AIService: Failed to write llm_cascade_log', ['error' => $e->getMessage()]);
        }
    }

    private function resolveRuntimeEnvValue(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    // =========================================================================
    // RLM: Auto-decompose — transparent context shrinkage for large prompts
    // =========================================================================

    /**
     * Attempt to auto-decompose a large prompt into smaller sub-calls.
     * Returns null if decomposition is not applicable (prompt too small, disabled, etc.).
     * The caller falls through to normal processing when null is returned.
     */
    private function tryAutoDecompose(string $prompt, array $config, float $startTime): ?array
    {
        $threshold = config('recursion.auto_decompose_threshold', 8000);
        $estimatedTokens = (int) (strlen($prompt) / 4); // ~4 chars per token

        if ($estimatedTokens <= $threshold) {
            return null; // Below threshold — process normally
        }

        // Check RLM master switch + auto_decompose service config
        try {
            $recursion = app(RecursiveCallService::class);
            if (! $recursion->isMasterEnabled()) {
                return null;
            }
            $svcConfig = $recursion->getServiceConfig('auto_decompose');
            if (! $svcConfig || ! ($svcConfig['enabled'] ?? false)) {
                return null;
            }
        } catch (\Throwable) {
            return null; // Can't resolve service — skip
        }

        // Split the prompt into instruction + content, then chunk the content
        $split = $this->splitPromptForDecompose($prompt);
        if ($split === null) {
            return null; // Can't split — process normally
        }

        $instruction = $split['instruction'];
        $chunks = $split['chunks'];

        if (count($chunks) <= 1) {
            return null; // Nothing to decompose
        }

        $subCallRole = config('recursion.sub_call_model_role', 'fast');
        $synthesisRole = config('recursion.synthesis_model_role', 'quality');
        $previousRole = self::$agentModelRole;

        Log::info('AIService: Auto-decompose triggered', [
            'estimated_tokens' => $estimatedTokens,
            'chunks' => count($chunks),
            'instruction_len' => strlen($instruction),
        ]);

        $subResults = [];
        $totalSubTokens = 0;
        $rootCallId = $this->recordAutoDecomposeCall($estimatedTokens, count($chunks));

        // Process each chunk as a sub-call with fast model role
        self::setAgentModelRole($subCallRole);
        foreach ($chunks as $idx => $chunk) {
            $subPrompt = $instruction."\n\n--- SECTION ".($idx + 1).' of '.count($chunks)." ---\n\n".$chunk;
            $subConfig = array_merge($config, [
                '_skip_decompose' => true, // Prevent infinite recursion
                'model_role' => $subCallRole,
                'suppress_alert' => true,
            ]);
            // Sub-calls extract/summarize text — only synthesis produces structured JSON
            unset($subConfig['expect_json']);

            $subStart = microtime(true);
            $subResult = $this->process($subPrompt, $subConfig);
            $subTime = microtime(true) - $subStart;

            $subTokenEstimate = (int) (strlen($subPrompt) / 4);

            // Record sub-call
            $this->recordAutoDecomposeSubCall(
                $rootCallId, $idx, $subTokenEstimate,
                $subResult['success'] ?? false,
                $subResult['provider'] ?? 'unknown',
                $subTime
            );

            if ($subResult['success'] ?? false) {
                $subResults[] = $subResult['response'] ?? '';
                $totalSubTokens += $subTokenEstimate;
            } else {
                Log::warning('AIService: Auto-decompose sub-call failed', [
                    'chunk' => $idx + 1,
                    'error' => $subResult['error'] ?? 'unknown',
                ]);
                // Continue — partial results are better than none
            }
        }

        if (empty($subResults)) {
            // All sub-calls failed — fall through to normal processing
            self::setAgentModelRole($previousRole);

            return null;
        }

        // Synthesize sub-results into final response
        self::setAgentModelRole($synthesisRole);
        $synthesized = $this->synthesizeDecomposeResults($subResults, $instruction, $config);
        self::setAgentModelRole($previousRole);

        $totalTime = microtime(true) - $startTime;

        // Update root call record
        $this->completeAutoDecomposeCall($rootCallId, count($subResults), $totalSubTokens, $totalTime);

        Log::info('AIService: Auto-decompose complete', [
            'chunks' => count($chunks),
            'successful_chunks' => count($subResults),
            'total_sub_tokens' => $totalSubTokens,
            'original_tokens' => $estimatedTokens,
            'shrinkage_pct' => round((1 - ($totalSubTokens / max(1, $estimatedTokens * count($chunks)))) * 100, 1),
            'duration_s' => round($totalTime, 2),
        ]);

        return [
            'success' => $synthesized['success'] ?? ! empty($subResults),
            'response' => $synthesized['response'] ?? implode("\n\n", $subResults),
            'provider' => 'auto_decompose',
            'model' => 'rlm_synthesized',
            'duration_ms' => (int) ($totalTime * 1000),
            'from_cache' => false,
            'rlm_auto_decompose' => true,
            'rlm_chunks' => count($chunks),
            'rlm_sub_tokens_avg' => count($subResults) > 0 ? (int) ($totalSubTokens / count($subResults)) : 0,
        ];
    }

    /**
     * Split a prompt into instruction prefix + content chunks.
     * Returns null if the prompt can't be meaningfully split.
     */
    private function splitPromptForDecompose(string $prompt): ?array
    {
        $targetChunkTokens = config('recursion.auto_decompose_target_chunk', 3000);
        $maxChunks = config('recursion.auto_decompose_max_chunks', 8);
        $overlapChars = config('recursion.auto_decompose_overlap_chars', 200);
        $minInstructionChars = config('recursion.auto_decompose_min_prompt_chars', 500);
        $targetChunkChars = $targetChunkTokens * 4; // ~4 chars per token

        // Strategy: Find the instruction/task portion (usually shorter) and the content (longer).
        // Common patterns:
        //   1. "Analyze the following text:\n\n<long content>"
        //   2. "You are... <instructions>\n\nContext:\n<long content>\n\nQuestion: ..."
        //   3. "<long content>\n\nBased on the above, ..."

        // Heuristic: split at the first double-newline boundary where the remaining text
        // is significantly longer than the prefix. The prefix is the instruction.
        $paragraphs = preg_split('/\n{2,}/', $prompt);

        if (count($paragraphs) < 3) {
            return null; // Too few paragraphs to split meaningfully
        }

        // Find the instruction boundary: accumulate paragraphs until we've seen
        // at least minInstructionChars, then everything after is "content"
        $instructionParts = [];
        $contentParts = [];
        $instructionLen = 0;
        $foundBoundary = false;

        foreach ($paragraphs as $para) {
            if (! $foundBoundary) {
                $instructionParts[] = $para;
                $instructionLen += strlen($para);
                // Boundary: we have enough instruction AND remaining content is substantial
                if ($instructionLen >= $minInstructionChars) {
                    $foundBoundary = true;
                }
            } else {
                $contentParts[] = $para;
            }
        }

        if (empty($contentParts)) {
            return null; // All instruction, no content to chunk
        }

        $instruction = implode("\n\n", $instructionParts);
        $contentText = implode("\n\n", $contentParts);

        // If content is small enough after splitting, don't decompose
        if (strlen($contentText) / 4 <= $targetChunkTokens) {
            return null;
        }

        // Chunk the content by paragraph groups
        $chunks = [];
        $currentChunk = '';

        foreach ($contentParts as $para) {
            if (strlen($currentChunk) + strlen($para) > $targetChunkChars && $currentChunk !== '') {
                $chunks[] = $currentChunk;
                // Overlap: carry last portion into next chunk
                $currentChunk = mb_substr($currentChunk, -$overlapChars)."\n\n".$para;
            } else {
                $currentChunk .= ($currentChunk !== '' ? "\n\n" : '').$para;
            }
        }
        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        // Cap chunks
        if (count($chunks) > $maxChunks) {
            $chunks = array_slice($chunks, 0, $maxChunks);
        }

        return [
            'instruction' => $instruction,
            'chunks' => $chunks,
        ];
    }

    /**
     * Synthesize partial results from chunk processing into a coherent response.
     */
    private function synthesizeDecomposeResults(array $subResults, string $instruction, array $config): array
    {
        if (count($subResults) === 1) {
            return ['success' => true, 'response' => $subResults[0]];
        }

        $partialBlock = '';
        foreach ($subResults as $idx => $result) {
            $partialBlock .= '--- Partial Result '.($idx + 1)." ---\n".$result."\n\n";
        }

        $synthesisPrompt = 'You previously analyzed sections of a document separately. '
            .'Below are your partial analyses. Synthesize them into a single, coherent response '
            .'that addresses the original task. Remove redundancy, resolve contradictions, '
            ."and provide a unified answer.\n\n"
            ."ORIGINAL TASK:\n".mb_substr($instruction, 0, 1000)."\n\n"
            ."PARTIAL RESULTS:\n".$partialBlock."\n"
            .'SYNTHESIZED RESPONSE:';

        $synthConfig = array_merge($config, [
            '_skip_decompose' => true,
            'model_role' => config('recursion.synthesis_model_role', 'quality'),
            'suppress_alert' => true,
            'max_tokens' => $config['max_tokens'] ?? 2000,
        ]);

        return $this->process($synthesisPrompt, $synthConfig);
    }

    // =========================================================================
    // RLM Auto-decompose: DB recording (non-fatal)
    // =========================================================================

    private function recordAutoDecomposeCall(int $estimatedTokens, int $chunkCount): ?int
    {
        try {
            DB::insert("
                INSERT INTO agent_recursion_calls
                    (service_name, depth, strategy, input_summary, context_window_size, model_role, created_at)
                VALUES ('auto_decompose', 0, 'partition_map', ?, ?, 'fast', NOW())
            ", [
                "Auto-decompose: {$estimatedTokens} est. tokens → {$chunkCount} chunks",
                $estimatedTokens,
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordAutoDecomposeSubCall(
        ?int $rootCallId, int $chunkIdx, int $tokenEstimate,
        bool $success, string $provider, float $timeSeconds
    ): void {
        if ($rootCallId === null) {
            return;
        }
        try {
            DB::insert("
                INSERT INTO agent_recursion_calls
                    (service_name, parent_call_id, depth, strategy, input_summary,
                     tokens_used, context_window_size, provider_used, model_role, time_seconds, created_at, completed_at)
                VALUES ('auto_decompose', ?, 1, 'partition_map', ?, ?, ?, ?, 'fast', ?, NOW(), NOW())
            ", [
                $rootCallId,
                'Chunk '.($chunkIdx + 1).($success ? ' OK' : ' FAIL'),
                $tokenEstimate,
                $tokenEstimate,
                $provider,
                round($timeSeconds, 2),
            ]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    private function completeAutoDecomposeCall(?int $callId, int $successfulChunks, int $totalTokens, float $totalTime): void
    {
        if ($callId === null) {
            return;
        }
        try {
            DB::update('
                UPDATE agent_recursion_calls SET
                    output_summary = ?,
                    tokens_used = ?,
                    time_seconds = ?,
                    completed_at = NOW()
                WHERE id = ?
            ', [
                "Synthesized {$successfulChunks} chunks",
                $totalTokens,
                round($totalTime, 2),
                $callId,
            ]);

            // Record effectiveness
            DB::insert("
                INSERT INTO recursion_effectiveness
                    (service_name, max_depth_reached, total_sub_calls, total_tokens,
                     total_time_seconds, total_cost_usd, created_at)
                VALUES ('auto_decompose', 1, ?, ?, ?, 0, NOW())
            ", [$successfulChunks, $totalTokens, round($totalTime, 2)]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }
}

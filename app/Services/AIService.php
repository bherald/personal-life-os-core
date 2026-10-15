<?php

namespace App\Services;

use App\DTOs\TrustEnvelope;
use App\Engine\AIRouter;
use App\Engine\MCPRouter;
use App\Exceptions\AI\AIExceptionFactory;
use App\Exceptions\AI\AIServiceException;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\AI\RateLimitException;
use App\Exceptions\ChatStreamCancelledException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\UnifiedCli\CliAgentRuntime;
use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliPromptCounter;
use App\Services\UnifiedCli\LosslessConversation;
use App\Services\UnifiedCli\PrimaryAgentLoop;
use App\Support\AI\LlmRouteTraceContext;
use App\Support\AI\PoolExecutionReceipt;
use App\Support\AI\StreamFallbackGuard;
use App\Support\PromptIntegrityPolicy;
use App\Support\RuntimeBoolean;
use App\Support\SensitiveTextTelemetry;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;

/**
 * AIService - Production-Grade Multi-Provider LLM Gateway
 *
 * Implements industry best practices from LiteLLM, Portkey, and enterprise LLM gateways:
 * - Circuit Breaker: Prevents retry storms, auto-cooldown after failures
 * - Exponential Backoff: 3 retries with increasing delays before fallback
 * - Multi-runtime routing: Ollama plus table-admitted OpenAI-compatible local and cloud providers
 * - Smart Timeouts: Dynamic based on model state (loaded/loading/swap)
 * - Semantic Health Tracking: Per-provider success rates, latencies, circuit states
 * - Ollama Busy Lock: Prevents concurrent requests to single-GPU Ollama
 * - Resource Monitoring: Auto-scales based on CPU load and memory availability
 *
 * Current Priority Chain:
 * 1. Role-eligible Ollama instances (ordered by health score) with retry + backoff
 * 2. Table-admitted OpenAI-compatible local_llm and external providers
 * 3. Explicit Codex Exec when its table and request gates admit it
 *
 * @see https://docs.litellm.ai/docs/proxy/reliability
 * @see https://portkey.ai/blog/retries-fallbacks-and-circuit-breakers-in-llm-apps/
 */
class AIService
{
    private const EXTERNAL_PROVIDER_CACHE_KEY = 'external_api_providers';

    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    private AIRouter $aiRouter;

    // ═══════════════════════════════════════════════════════════════════
    // CONFIGURATION CONSTANTS
    // ═══════════════════════════════════════════════════════════════════

    private const RUNTIME_POLICY_CONFIG_KEY = 'ai.service_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'short_prompt_fast_threshold_chars' => 100,
        'available_models_connect_timeout_seconds' => 5,
        'available_models_request_timeout_seconds' => 10,
        'available_models_cache_ttl_seconds' => 300,
        'model_profiles_cache_ttl_seconds' => 60,
        'provider_retry_max_attempts' => 2,
        'provider_retry_initial_backoff_ms' => 500,
        'provider_retry_max_backoff_ms' => 4000,
        'provider_retry_backoff_multiplier' => 2,
    ];

    // Timeout configuration (seconds)
    // Note: Even with OLLAMA_KEEP_ALIVE=10m, model may unload between status check
    // and actual request. Use conservative timeouts that account for reload time.
    private const TIMEOUT_MODEL_LOADED = 60;      // Model in VRAM — 60s is generous; if hung, fail fast to fallback

    private const TIMEOUT_MODEL_LOADING = 120;    // Model loading from disk

    private const TIMEOUT_MODEL_SWAP = 180;       // Different model needs swap

    private const TIMEOUT_CONNECT = 5;            // Connection check

    // Retry configuration — LLM-specific (faster than RetryService defaults for provider failover)
    // See RetryService for generic retry (3 attempts, 1-30s). These are intentionally lower. (SC-2.4)
    private const MAX_RETRIES = 2;                // Retries per provider

    private const INITIAL_BACKOFF_MS = 500;       // Initial delay (500ms)

    private const MAX_BACKOFF_MS = 4000;          // Max delay

    private const BACKOFF_MULTIPLIER = 2;         // Exponential multiplier

    // Busy detection configuration
    private const OLLAMA_BUSY_LOCK_TTL = 150;     // Fallback — config/lock_ttls.php is primary (SC-2.3)

    private const OLLAMA_BUSY_CACHE_KEY = 'ollama_busy_lock';

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

    private static ?string $workflowModelRole = null;

    private static ?\Closure $workflowModelRoleValidator = null;

    private static ?\Closure $workflowModelResolver = null;

    private static ?\Closure $workflowVisionModelResolver = null;

    private static ?\Closure $workflowEmbeddingModelResolver = null;

    private static int $nonReplayableInferenceDepth = 0;

    private static ?\Closure $inferenceTransportValidator = null;

    private static ?\Closure $inferenceProgressKeeper = null;

    /** Scope custody checks to model HTTP, preserving an earlier caller's progress hook. */
    public static function withInferenceProgressKeeper(callable $operation, \Closure $keeper): mixed
    {
        $previous = self::$inferenceProgressKeeper;
        $failure = null;
        self::$inferenceProgressKeeper = static function () use ($keeper, $previous, &$failure): void {
            if ($failure !== null) {
                throw $failure;
            }
            try {
                $previous?->__invoke();
                $keeper();
            } catch (\Throwable $error) {
                $failure = new InferenceAdmissionException('Inference custody could not be renewed; the outcome requires reconciliation.', 0, $error);
                throw $failure;
            }
        };
        try {
            $result = $operation();
            if ($failure !== null) {
                throw $failure;
            }

            return $result;
        } catch (\Throwable $error) {
            throw $failure ?? $error;
        } finally {
            self::$inferenceProgressKeeper = $previous;
        }
    }

    public static function maintainsInference(): bool
    {
        return self::$inferenceProgressKeeper !== null;
    }

    /** Both custody renewal and exact transport admission must be scoped to this request. */
    public static function hasManagedInferenceContract(): bool
    {
        return self::$inferenceTransportValidator !== null && self::$inferenceProgressKeeper !== null;
    }

    /** The buffered model transports need cURL ticks even while waiting for the first byte. */
    public static function inferenceProgressOptions(array $options): array
    {
        $keeper = self::$inferenceProgressKeeper;
        if ($keeper === null) {
            return $options;
        }
        if (! extension_loaded('curl')) {
            throw new InferenceAdmissionException('Maintained inference requires the cURL transport.');
        }
        $progress = $options['progress'] ?? null;
        $options['progress'] = static function (...$counts) use ($keeper, $progress): void {
            $keeper();
            if ($progress !== null) {
                $progress(...$counts);
            }
        };
        $options['allow_redirects'] = false;
        $options['stream'] = false;

        return $options;
    }

    /** Preserve Laravel's middleware, stubs and request recording around the one-shot handler. */
    public static function maintainInferenceHttp(PendingRequest $request): PendingRequest
    {
        return self::maintainsInference()
            ? $request->setHandler(PipelineHttpClientFactory::oneShotHandler())->withOptions(self::inferenceProgressOptions([]))
            : $request;
    }

    /** Validate actual transport settings within this synchronous, serial execution. */
    public static function withInferenceTransportValidator(callable $operation, \Closure $validator): mixed
    {
        $previous = self::$inferenceTransportValidator;
        self::$inferenceTransportValidator = static function (string $wire, string $endpoint, array $settings) use ($previous, $validator): void {
            $previous?->__invoke($wire, $endpoint, $settings);
            $validator($wire, $endpoint, $settings);
        };
        try {
            return $operation();
        } finally {
            self::$inferenceTransportValidator = $previous;
        }
    }

    public static function assertInferenceTransport(string $wire, string $endpoint, array $settings): void
    {
        try {
            if (self::$inferenceTransportValidator === null) {
                app(ModelHostControllerResourcePolicyService::class)->assertUnmanagedTransportAllowed($endpoint);
            }
            self::$inferenceTransportValidator?->__invoke($wire, $endpoint, $settings);
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Inference request admission failed before send.', 0, $error);
        }
    }

    /** Reuse the gateway's role merge without model, network or lifecycle work. */
    public function prepareManagedRuntimeConfig(array $config, array $providerConfig, string $role, string $adapter): array
    {
        if (! empty($config['factual_mode'])) {
            // Apply the same sampling rule without injecting the factual prompt twice.
            $config['temperature'] = $this->applyFactualMode($config, '')['temperature'];
        }

        $config = $this->applyOllamaInstanceRuntimeConfig($config, ['config' => $providerConfig], $role);

        if ($adapter === 'openai_compatible') {
            foreach (['num_ctx' => 'context_window', 'temperature' => 'temperature'] as $requestKey => $providerKey) {
                if (! array_key_exists($requestKey, $config) && array_key_exists($providerKey, $providerConfig)) {
                    $config[$requestKey] = $providerConfig[$providerKey];
                }
            }
        }

        return $adapter === 'ollama'
            ? $this->applyOllamaRoutingPolicy($config, $this->activeLlmRoutingPolicy())
            : $config;
    }

    /** Exact non-message payload shared by required-provider dispatch and capacity preparation. */
    public static function openAICompatibleTextPayload(array $config, array $providerConfig, string $model,
        ?array $responseFormat = null, ?string $grammar = null): array
    {
        $value = array_key_exists('max_tokens', $config) ? $config['max_tokens'] : ($providerConfig['max_tokens'] ?? 256);
        $maximum = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($maximum === false) {
            throw new InferenceAdmissionException('Compatible text output budget must be a positive integer.');
        }
        $payload = [
            'model' => $model,
            'max_tokens' => $maximum,
            'temperature' => (float) ($config['temperature'] ?? $providerConfig['temperature'] ?? 0.1),
            'enable_thinking' => (bool) ($config['enable_thinking'] ?? false),
        ];
        $kwargs = is_array($providerConfig['chat_template_kwargs'] ?? null) ? $providerConfig['chat_template_kwargs'] : [];
        if (array_key_exists('enable_thinking', $kwargs)) {
            $payload['chat_template_kwargs'] = ['enable_thinking' => (bool) $kwargs['enable_thinking']];
        }
        if ($grammar !== null) {
            $payload['grammar'] = $grammar;
        } elseif ($responseFormat !== null) {
            $payload['response_format'] = $responseFormat;
        }

        return $payload;
    }

    /** Keep lower-level retries inside the controller's durable dispatch boundary. */
    public static function withNonReplayableInference(callable $operation): mixed
    {
        self::$nonReplayableInferenceDepth++;
        try {
            return $operation();
        } finally {
            self::$nonReplayableInferenceDepth--;
        }
    }

    public static function inferenceReplayAllowed(): bool
    {
        return self::$nonReplayableInferenceDepth === 0;
    }

    /** Scope supporting text, vision and embedding choices independently, restoring them afterwards. */
    public static function withWorkflowModelRole(?string $role, \Closure $operation, ?\Closure $validate = null, ?\Closure $resolveModel = null, ?\Closure $resolveVisionModel = null, ?\Closure $resolveEmbeddingModel = null): mixed
    {
        $previous = self::$workflowModelRole;
        $previousValidator = self::$workflowModelRoleValidator;
        $previousResolver = self::$workflowModelResolver;
        $previousVisionResolver = self::$workflowVisionModelResolver;
        $previousEmbeddingResolver = self::$workflowEmbeddingModelResolver;
        self::$workflowModelRole = $role;
        self::$workflowModelRoleValidator = $validate;
        self::$workflowModelResolver = $resolveModel;
        self::$workflowVisionModelResolver = $resolveVisionModel;
        self::$workflowEmbeddingModelResolver = $resolveEmbeddingModel;
        try {
            return $operation();
        } finally {
            self::$workflowModelRole = $previous;
            self::$workflowModelRoleValidator = $previousValidator;
            self::$workflowModelResolver = $previousResolver;
            self::$workflowVisionModelResolver = $previousVisionResolver;
            self::$workflowEmbeddingModelResolver = $previousEmbeddingResolver;
        }
    }

    private static function workflowModelConfig(array $config): array
    {
        if ((self::$workflowModelRole !== null || self::$workflowModelResolver !== null) && empty($config['embedding'])
            && ! in_array($config['task_type'] ?? null, ['embedding', 'vision'], true)
            && ! in_array($config['model_role'] ?? null, ['embedding', 'vision'], true)) {
            // A workflow may perform useful work without an LLM. Validate its
            // selected text role only when a text-model request is actually made.
            if (self::$workflowModelRole !== null) {
                (self::$workflowModelRoleValidator)?->__invoke();
                $config['model_role'] = self::$workflowModelRole;
            }
            if (self::$workflowModelResolver !== null) {
                $config = array_replace($config, (self::$workflowModelResolver)($config));
            }
        }

        return $config;
    }

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

    private LlmCircuitBreakerPolicyService $circuitBreakerPolicy;

    private ProviderModelCapabilityService $providerModelCapabilities;

    private OpenAICompatibleProgressClient $openAICompatibleProgress;

    public function __construct(
        ?AIRouter $aiRouter = null,
        ?LLMPoolManagerService $poolManager = null,
        ?CircuitBreaker $circuitBreaker = null,
        ?LlmCircuitBreakerPolicyService $circuitBreakerPolicy = null,
        ?ProviderModelCapabilityService $providerModelCapabilities = null,
        ?OpenAICompatibleProgressClient $openAICompatibleProgress = null
    ) {
        $this->aiRouter = $aiRouter ?? app(AIRouter::class);
        $this->circuitBreaker = $circuitBreaker ?? app(CircuitBreaker::class);
        $this->circuitBreakerPolicy = $circuitBreakerPolicy ?? app(LlmCircuitBreakerPolicyService::class);
        $this->providerModelCapabilities = $providerModelCapabilities ?? app(ProviderModelCapabilityService::class);
        $this->openAICompatibleProgress = $openAICompatibleProgress ?? app(OpenAICompatibleProgressClient::class);

        // Runtime model selection is owned by registered table rows only.
        $dbModels = $this->loadModelsFromDb();
        $this->defaultModel = (string) ($dbModels['standard'] ?? '');
        $this->visionModel = (string) ($dbModels['vision'] ?? '');
        $this->embeddingModel = (string) ($dbModels['embedding'] ?? '');

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
            } catch (Exception $e) {
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
                $models['embedding'] = $config['embedding_model'] ?? ($models['embedding'] ?? null);

                return $models;
            }
        } catch (\Throwable $e) {
            // Missing registry authority must not resurrect an environment model.
        }

        return [];
    }

    /**
     * Initialize Ollama instance pool from llm_instances DB table
     * An empty or unavailable registry leaves the pool empty.
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
        } catch (Exception $e) {
            Log::warning('AIService: Ollama instance registry unavailable', [
                'error' => $e->getMessage(),
            ]);
        }

        // No configured provider is a valid application state; never invent one.
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
        } catch (Exception $e) {
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
        if (strlen($prompt) < $this->runtimePolicyInt('short_prompt_fast_threshold_chars', 100)
            && ! str_contains($promptLower, 'explain')) {
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
            $response = Http::connectTimeout($this->runtimePolicyInt('available_models_connect_timeout_seconds', 5))
                ->timeout($this->runtimePolicyInt('available_models_request_timeout_seconds', 10))
                ->get("{$url}/api/tags");

            if ($response->successful()) {
                $models = $response->json()['models'] ?? [];

                // Add profile info to each model
                foreach ($models as &$model) {
                    $model['profile'] = $this->getModelProfile($model['name']);
                }

                Cache::put($cacheKey, $models, $this->runtimePolicyInt('available_models_cache_ttl_seconds', 300));

                return $models;
            }
        } catch (Exception $e) {
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
                Cache::put('llm_model_profiles', $profiles, $this->runtimePolicyInt('model_profiles_cache_ttl_seconds', 60));
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
     * @param  string  $instanceId  e.g. 'codex_exec', 'ollama_primary'
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
                if (! $this->providerConfigAllowsModelRole($config, $role)) {
                    $this->providerModelCache[$cacheKey] = null;

                    return null;
                }

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
            } catch (Exception $e) {
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

    private function normalizePrivacyRoutingConfig(array $config): array
    {
        if (array_key_exists('sensitive_data', $config)) {
            return $config;
        }

        $terms = $this->runtimePolicy()['sensitive_context_terms'] ?? null;
        if (! is_array($terms) || ! array_is_list($terms)
            || array_filter($terms, static fn ($term) => ! is_string($term) || trim($term) === '') !== []) {
            // Missing or invalid policy must not silently admit private content.
            $config['sensitive_data'] = true;
            $config['sensitive_data_reason'] = 'privacy_classification_policy_unavailable';

            return $config;
        }
        if ($terms === []) {
            return $config;
        }
        $pattern = '/\\b(?:'.implode('|', array_map(static fn (string $term) => preg_quote(trim($term), '/'), $terms)).')\\b/i';

        $classifiers = [
            $config['task_type'] ?? null,
            $config['data_class'] ?? null,
            $config['context_type'] ?? null,
            $config['caller'] ?? null,
        ];

        foreach ($classifiers as $classifier) {
            $value = strtolower((string) $classifier);
            if ($value === '') {
                continue;
            }

            if (preg_match($pattern, $value)) {
                $config['sensitive_data'] = true;
                $config['sensitive_data_reason'] = $config['sensitive_data_reason'] ?? 'inferred_from_context';

                return $config;
            }
        }

        return $config;
    }

    private function truthyDbValue(mixed $value): bool
    {
        return RuntimeBoolean::isTrue($value);
    }

    private function rowAllowsPrivateData(object $row, array $capabilities, array $config): bool
    {
        if (property_exists($row, 'allows_private_data')) {
            return $this->truthyDbValue($row->allows_private_data ?? false);
        }

        return in_array('sensitive_safe', $capabilities, true)
            || $this->truthyDbValue($capabilities['sensitive_safe'] ?? false)
            || $this->truthyDbValue($config['sensitive_safe'] ?? false);
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
        try {
            $config = self::workflowModelConfig($config);
        } catch (RuntimeException $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['success' => false, 'response' => null, 'error' => $error->getMessage(), 'duration_ms' => 0];
        }
        $config = $this->normalizePrivacyRoutingConfig($config);
        $config['_prompt_chars'] = mb_strlen($prompt)
            + mb_strlen((string) ($config['system_prompt'] ?? $config['system'] ?? ''));
        $requiredInstanceId = is_string($config['required_instance_id'] ?? null)
            ? trim($config['required_instance_id'])
            : '';
        if ($requiredInstanceId !== '' && app(SystemConfigService::class)->isDedicatedLlmTarget($requiredInstanceId)) {
            $admissionRequestId = is_string($config['_mhc08_admission_request_id'] ?? null)
                ? trim($config['_mhc08_admission_request_id'])
                : '';
            $admissionToken = is_string($config['_mhc08_admission_token'] ?? null)
                ? trim($config['_mhc08_admission_token'])
                : '';
            app(ModelHostControllerMhc08K3AdmissionGuardService::class)->assert($admissionRequestId, $admissionToken);
            unset($config['_mhc08_admission_request_id'], $config['_mhc08_admission_token']);
        } elseif (array_key_exists('_mhc08_admission_request_id', $config)
            || array_key_exists('_mhc08_admission_token', $config)) {
            throw new RuntimeException('MHC-08 K3 admission credentials cannot be used by another provider route.');
        }
        $requiredOllamaNoFallback = $this->truthyDbValue($config['ollama_required_no_fallback'] ?? false);
        $config = $this->withRouteTraceContext($config);
        $structuredOutputSchemaId = $this->registeredStructuredOutputSchemaId($config);
        if ($structuredOutputSchemaId !== null) {
            app(OllamaStructuredOutputSchemaService::class)->resolve($structuredOutputSchemaId);
        }
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

        $suppressAlert = $config['suppressAlert'] ?? $requiredInstanceId !== '';

        // Automatic decomposition is a lossy prompt transform. The source-level
        // policy is fail-closed even if stale DB or cached config says enabled.
        if (PromptIntegrityPolicy::allowsLossyTransforms()
            && (bool) config('recursion.auto_decompose_enabled', false)
            && $requiredInstanceId === ''
            && empty($config['_skip_decompose'])
            && empty($config['embedding'])) {
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
        $useCache = $requiredInstanceId === ''
            && ! $requiredOllamaNoFallback
            && $structuredOutputSchemaId === null
            && ($config['use_cache'] ?? $this->cacheEnabled);

        $cacheContext = $useCache ? $this->generationCacheContext($config, $requestedModel, $prompt) : null;
        $useCache = $useCache && $cacheContext !== null;
        if ($useCache && $this->semanticCache) {
            $cachedResult = $this->semanticCache->get($prompt, $cacheContext);
            if ($cachedResult !== null && $this->cachedGenerationIsAdmitted($cachedResult, $requestedModel, $modelRole, $config)) {
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
            $dedupKey = 'ai_inflight:'.hash('sha256', $prompt.'|'.json_encode($cacheContext));
            // Try to claim this request (atomic: returns false if key already exists)
            if (! Cache::add($dedupKey, getmypid(), 60)) {
                // Another request is in-flight — wait up to 15s for it to finish
                $waited = $this->waitForInflightResult($prompt, $cacheContext ?? [], 15);
                if ($waited !== null && $this->cachedGenerationIsAdmitted($waited, $requestedModel, $modelRole, $config)) {
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

        if ($requiredInstanceId !== '') {
            $result = $this->processRequiredOpenAICompatibleProvider($requiredInstanceId, $prompt, $config);
            $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $result['from_cache'] = false;

            return $result;
        }

        // Explicit external routes. Normal routing still tries local Ollama before fallback providers.
        $preferCodex = ! $requiredOllamaNoFallback && $requiredInstanceId === ''
            && (! empty($config['prefer_codex']) || ($config['ai_mode'] ?? null) === 'codex_exec');
        if ($preferCodex) {
            $result = $structuredOutputSchemaId !== null
                ? ['success' => false, 'error' => 'Codex route is not admitted for registered PLOS structured output']
                : $this->tryCodexExec($prompt, $config);

            if ($result['success']) {
                $result['duration_ms'] = $result['duration_ms'] ?? (int) ((microtime(true) - $startTime) * 1000);
                $result['from_cache'] = false;

                if ($useCache && $this->semanticCache && isset($cacheContext)) {
                    $this->semanticCache->put($prompt, $result, $cacheContext);
                }

                return $result;
            }

            $attempts['codex_exec'] = $result['error'];
            if (! empty($config['codex_required'])) {
                throw new Exception('Codex Exec failed: '.$result['error']);
            }

            Log::info('AIService: Codex Exec preferred but failed, falling back to normal provider chain', [
                'error' => $result['error'],
            ]);
        }

        // prefer_external: Try external APIs (Groq, OpenRouter) BEFORE Ollama.
        // Used by agents to get faster, more capable models instead of slow local 8B.
        $preferExternal = ! $requiredOllamaNoFallback
            && (! empty($config['prefer_external']) || $requiredInstanceId !== '');
        $activeLlmRoutingPolicy = $this->activeLlmRoutingPolicy();

        // Latency demotion: if ALL healthy Ollama instances are slow (avg > 5s),
        // auto-prefer external providers to avoid cumulative timeout in agent runs.
        // Embedding calls are excluded (must stay local for 768d consistency).
        // Patient local-first profiles explicitly prefer slow success over cloud latency.
        if (! $preferExternal
            && ! $requiredOllamaNoFallback
            && ($activeLlmRoutingPolicy['allow_latency_demotion'] ?? true) !== false
            && empty($config['embedding'])
            && $this->poolManager
        ) {
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
            // Patient profiles try immediately available hosts before waiting on busy capacity.
            $ollamaInstances = $this->getOrderedTextOllamaInstances($modelRole, $config);
            if ($this->truthyDbValue($activeLlmRoutingPolicy['patient_local_capacity'] ?? false)) {
                $ollamaInstances = $this->orderAvailableOllamaInstancesFirst($ollamaInstances);
            }
            foreach ($ollamaInstances as $instance) {
                $instanceModel = $this->resolveOllamaRequestModel($instance, $requestedModel, $modelRole, $config);
                if ($instanceModel === null) {
                    $unavailableModel = $requiredOllamaNoFallback ? ($config['model_override'] ?? $config['model'] ?? $requestedModel) : $requestedModel;
                    $attempts[$instance['id']] = "Requested model '{$unavailableModel}' (role: {$modelRole}) not available on {$instance['name']}";

                    continue;
                }

                $instanceRequestConfig = $this->applyOllamaInstanceRuntimeConfig(
                    $config,
                    $instance,
                    (string) ($modelRole ?? 'standard')
                );

                $instanceRequestConfig = $this->applyOllamaRoutingPolicy($instanceRequestConfig, $activeLlmRoutingPolicy);

                $result = $this->tryOllamaWithRetry($instance, $prompt, $instanceModel, $instanceRequestConfig);

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

                            continue; // Fall through to the table-gated fallback chain
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

                // If this instance is busy or unavailable, try the next instance.
                if ($result['skip_to_fallback'] ?? false) {
                    continue;
                }
            }
        }

        if ($requiredOllamaNoFallback) {
            return [
                'success' => false,
                'response' => null,
                'provider' => null,
                'error' => 'Required Ollama target failed; external fallback was suppressed',
                'fallback_suppressed' => true,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'attempts' => $attempts,
            ];
        }

        // Step 2: Build remaining fallback chain — table-approved external APIs, ordered by priority
        // External API providers from llm_instances table (Groq, OpenRouter, Mistral, etc.)
        $isSensitive = ! empty($config['sensitive_data']);
        $fallbackProviders = $this->buildFallbackChain($isSensitive, false, $config);

        foreach ($fallbackProviders as $fallback) {
            if ($fallback['type'] === 'codex_exec') {
                if ($structuredOutputSchemaId !== null) {
                    $attempts['codex_exec'] = 'Codex route is not admitted for registered PLOS structured output';

                    continue;
                }
                $rpmKey = 'rpm_count_codex_exec';
                $currentRpm = Cache::get($rpmKey, 0);
                if (($fallback['rate_limit_rpm'] ?? null) && $currentRpm >= $fallback['rate_limit_rpm']) {
                    Cache::put('rate_limit_codex_exec', true, 60);
                    $attempts['codex_exec'] = "Rate limit exhausted for {$fallback['name']} ({$currentRpm}/{$fallback['rate_limit_rpm']} RPM)";

                    continue;
                }

                $result = $this->tryCodexExec($prompt, $config);

                if ($result['success']) {
                    if ($fallback['rate_limit_rpm'] ?? null) {
                        Cache::put($rpmKey, $currentRpm + 1, 60);
                    }

                    $result['duration_ms'] = $result['duration_ms'] ?? (int) ((microtime(true) - $startTime) * 1000);
                    $result['from_cache'] = false;

                    if ($useCache && $this->semanticCache) {
                        $this->semanticCache->put($prompt, $result, $cacheContext);
                    }

                    $this->logCascadeResult($config, $result);

                    return $result;
                }

                $attempts['codex_exec'] = $result['error'];
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
        if ($preferExternal && $requiredInstanceId === '') {
            $config['skip_if_busy'] = $config['skip_if_busy'] ?? true;
            foreach ($this->getOrderedTextOllamaInstances($modelRole, $config) as $instance) {
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
            'error' => $requiredInstanceId !== ''
                ? "Required AI provider {$requiredInstanceId} failed; fallback was suppressed"
                : 'All AI providers failed',
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'attempts' => $attempts,
        ];
    }

    /**
     * Check if vision capability is available (at least one provider supports it)
     */
    public function isVisionAvailable(): bool
    {
        return count($this->getHealthyOllamaInstances()) > 0;
    }

    /**
     * Process image with AI vision - full resilience pipeline
     *
     * @param  string  $imageContent  Raw image content
     * @param  string  $prompt  The prompt to send with the image
     * @param  array  $config  Configuration options:
     *                         - suppressAlert: bool - Suppress failure alerts (for pipelines with fallbacks)
     */
    public function processImage(string $imageContent, string $prompt, array $config = []): array
    {
        // Workflow model selection may tighten routing, never weaken caller privacy.
        $callerConstraints = [];
        foreach (['local_only', 'disable_external_fallback', 'ollama_required_no_fallback', 'disable_cascade', 'sensitive_data'] as $constraint) {
            if ($this->truthyDbValue($config[$constraint] ?? false)) {
                $callerConstraints[$constraint] = true;
            }
        }
        $imageValidationError = $this->validateVisionImageBytes($imageContent);
        if ($imageValidationError !== null) {
            return [
                'success' => false,
                'response' => null,
                'error' => $imageValidationError['message'],
                'error_code' => $imageValidationError['code'],
                'duration_ms' => 0,
                'attempts' => [],
            ];
        }

        try {
            if (self::$workflowVisionModelResolver !== null) {
                $config = array_replace($config, (self::$workflowVisionModelResolver)($config));
            }
        } catch (RuntimeException $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['success' => false, 'response' => null, 'error' => $error->getMessage(), 'duration_ms' => 0, 'fallback_suppressed' => true];
        }
        $config = $this->normalizePrivacyRoutingConfig($config);
        $config = array_replace($config, $callerConstraints);
        $config['model_role'] = 'vision';
        $config['_prompt_chars'] = mb_strlen($prompt) + mb_strlen((string) ($config['system_prompt'] ?? $config['system'] ?? ''));
        $config = $this->applyPreferredOllamaInstanceOrder($config, 'vision');
        $requiredInstanceId = trim((string) ($config['required_instance_id'] ?? ''));
        $requiredOllamaInstance = $requiredInstanceId !== '' && in_array($requiredInstanceId, $this->listAllOllamaInstanceIds(), true);
        if ($requiredOllamaInstance) {
            $config['ollama_required_no_fallback'] = true;
            $config['allowed_ollama_instance_ids'] = [$requiredInstanceId];
            $config['preferred_ollama_instance_ids'] = [$requiredInstanceId];
            $config['_ollama_instance_selection_explicit'] = true;
        }
        $requiredOllama = $this->truthyDbValue($config['ollama_required_no_fallback'] ?? false);
        $exactSelection = $requiredInstanceId !== '' || $requiredOllama;
        if ($exactSelection) {
            $config['disable_cascade'] = true;
        }
        $requestedModel = $exactSelection ? ($config['model_override'] ?? $config['model'] ?? null) : null;
        $config = $this->withRouteTraceContext($config);
        $config['sensitive_data'] = $config['sensitive_data'] ?? true;
        $startTime = microtime(true);
        $attempts = [];
        $suppressAlert = $config['suppressAlert'] ?? false;

        // Vision provider chain: Ollama, then admitted external vision APIs.
        $config['skip_if_busy'] = true;
        $ollamaHandled = false;

        // Score-based order: the instance advertising the best vision model wins
        // first. Fall back to the remaining healthy instances in the same order
        // so a busy primary still cascades to the secondary before external APIs.
        $visionInstances = $requiredInstanceId !== '' && ! $requiredOllamaInstance ? [] : $this->orderOllamaInstancesForRole('vision', $config, requiredModel: $requestedModel);
        $allowedInstances = $config['allowed_ollama_instance_ids'] ?? [];
        if (is_array($allowedInstances) && $allowedInstances !== []) {
            $visionInstances = array_values(array_filter($visionInstances, fn ($instance) => in_array($instance['id'], $allowedInstances, true)));
        }

        foreach ($visionInstances as $instance) {
            $result = $this->tryOllamaVisionWithRetry($instance, $imageContent, $prompt, $config);
            if (($result['fallback_suppressed'] ?? false) === true) {
                return $result + ['duration_ms' => (int) ((microtime(true) - $startTime) * 1000)];
            }

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
                        break;
                    }
                }

                return array_merge($result, [
                    'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                ]);
            }

            // If Ollama is busy, skip to external providers.
            if ($result['skip_to_fallback'] ?? false) {
                $attempts[$instance['id']] = $result['error'].($exactSelection ? ' (fallback suppressed)' : ' (auto-routed to fallback)');
                $ollamaHandled = true;
                break;
            }

            $attempts[$instance['id']] = $result['error'];
            $ollamaHandled = true;
        }

        $externalFallbackDisabled = ! empty($config['disable_external_fallback'])
            || ! empty($config['local_only']) || $requiredOllama;

        // External vision providers (OpenRouter, Gemini, Mistral pixtral, etc.)
        // Images are private by default unless the caller explicitly classifies
        // the payload as non-sensitive.
        $sensitiveData = $config['sensitive_data'] ?? false;
        $visionProviders = $externalFallbackDisabled ? [] : $this->getVisionCapableProviders($sensitiveData);
        if ($requiredInstanceId !== '') {
            $visionProviders = array_values(array_filter($visionProviders, fn ($provider) => $provider['id'] === $requiredInstanceId));
        }

        if (! $externalFallbackDisabled && ! empty($visionProviders)) {
            // Read image content if it's a file path
            $imageData = $imageContent;
            if (! str_contains($imageContent, "\0") && strlen($imageContent) < 512 && file_exists($imageContent)) {
                $imageData = file_get_contents($imageContent);
            }

            foreach ($visionProviders as $provider) {
                if (! $this->providerRoutePolicyAllowsRequest($provider, $config)) {
                    $attempts[$provider['id'].'_vision'] = 'Vision request is not permitted by the provider routing rules';

                    continue;
                }
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
        } elseif ($externalFallbackDisabled) {
            $attempts['external_vision'] = 'Disabled by caller privacy/local-only config';
        }

        // Only send alert if not suppressed (caller has fallbacks to try)
        if (! $suppressAlert) {
            $this->sendFailureAlert($attempts, "Vision: $prompt");
        }

        return [
            'success' => false,
            'response' => null,
            'provider' => null,
            'error' => $exactSelection ? 'Selected vision model/provider is unavailable or failed; no alternate model/provider was selected.' : 'All vision providers failed',
            'fallback_suppressed' => $exactSelection,
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'attempts' => $attempts,
        ];
    }

    /**
     * Reject unreadable image payloads before selecting or calling any provider.
     * The vision API accepts raw bytes, so file paths, text, and encoded wrappers
     * are not treated as image data here.
     *
     * @return array{code:string,message:string}|null
     */
    public function inspectImageBytes(string $imageContent): array
    {
        $error = $this->validateVisionImageBytes($imageContent);
        $imageInfo = @getimagesizefromstring($imageContent);
        $decodePerformed = is_array($imageInfo)
            && ($error === null || ($error['code'] ?? null) === 'invalid_image_data');

        return [
            'valid' => $error === null,
            'decode_performed' => $decodePerformed,
            'error_code' => $error['code'] ?? null,
            'error' => $error['message'] ?? null,
            'mime_type' => is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null,
            'width' => is_array($imageInfo) ? ($imageInfo[0] ?? null) : null,
            'height' => is_array($imageInfo) ? ($imageInfo[1] ?? null) : null,
        ];
    }

    private function validateVisionImageBytes(string $imageContent): ?array
    {
        if ($imageContent === '') {
            return ['code' => 'invalid_image_data', 'message' => 'Image data is empty or invalid'];
        }

        $imageInfo = @getimagesizefromstring($imageContent);
        $gdSupportKeys = [
            'image/jpeg' => 'JPEG Support', 'image/jpg' => 'JPEG Support',
            'image/png' => 'PNG Support', 'image/gif' => 'GIF Read Support',
            'image/webp' => 'WebP Support', 'image/bmp' => 'BMP Support',
            'image/x-ms-bmp' => 'BMP Support', 'image/avif' => 'AVIF Support',
        ];
        // Preserve the existing input policy while an older config cache is
        // still present during rollout, without admitting extra formats.
        $supportedMimeTypes = config('ai.vision_supported_mime_types') ?? array_keys($gdSupportKeys);
        if ($imageInfo === false || empty($imageInfo['mime'])) {
            $mime = null;
            if (class_exists(\finfo::class)) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->buffer($imageContent);
                $mime = is_string($detectedMime) ? $detectedMime : null;
            }

            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                if (! in_array($mime, $supportedMimeTypes, true)) {
                    return ['code' => 'unsupported_image_format', 'message' => 'This image format is not supported'];
                }

                return ['code' => 'invalid_image_data', 'message' => 'Image data is malformed or truncated'];
            }

            return ['code' => 'invalid_image_data', 'message' => 'Image data is not a readable image'];
        }

        $mime = strtolower((string) $imageInfo['mime']);
        if (! in_array($mime, $supportedMimeTypes, true)) {
            return ['code' => 'unsupported_image_format', 'message' => 'This image format is not supported'];
        }
        $gdSupportKey = $gdSupportKeys[$mime] ?? null;

        $gdInfo = function_exists('gd_info') ? gd_info() : [];
        $gdSupportsMime = $gdSupportKey !== null && ! empty($gdInfo[$gdSupportKey]);
        $hasGdDecoder = function_exists('imagecreatefromstring') && $gdSupportsMime;
        $hasImagickDecoder = class_exists(\Imagick::class);

        if (! $hasGdDecoder && ! $hasImagickDecoder) {
            if (! function_exists('imagecreatefromstring') && ! $hasImagickDecoder) {
                return ['code' => 'image_decoder_unavailable', 'message' => 'No image decoder is available to validate this image'];
            }

            return ['code' => 'unsupported_image_format', 'message' => 'This image format is not supported by an available decoder'];
        }

        $decoded = false;
        if ($hasGdDecoder) {
            $image = @imagecreatefromstring($imageContent);
            if ($image !== false) {
                $decoded = true;
                imagedestroy($image);
            }
        }

        if (! $decoded && $hasImagickDecoder) {
            try {
                $image = new \Imagick;
                $image->readImageBlob($imageContent);
                $decoded = $image->getNumberImages() > 0;
                $image->clear();
                $image->destroy();
            } catch (\Throwable) {
                $decoded = false;
            }
        }

        if (! $decoded) {
            return ['code' => 'invalid_image_data', 'message' => 'Image data is malformed or truncated'];
        }

        return null;
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
    private function splitForEmbedding(string $text, int $tokenLimit, bool $preserveInput = false): array
    {
        $preserveInput = $preserveInput || ! PromptIntegrityPolicy::allowsLossyTransforms();
        $chunkCharLimit = (int) ($tokenLimit * 0.60 * 1.5); // 60% safety margin, 1.5 chars/token (handles code/JSON BPE worst-case)
        $overlapChars = (int) ($chunkCharLimit * 0.10);

        if ($preserveInput) {
            if ($chunkCharLimit < 1 || ! mb_check_encoding($text, 'UTF-8')) {
                throw new InferenceAdmissionException('Strict embedding chunk input or context is unavailable.');
            }
            if ($text === '') {
                return [$text];
            }
            $chunks = [];
            for ($offset = 0; $offset < strlen($text);) {
                $chunk = mb_strcut($text, $offset, $chunkCharLimit, 'UTF-8');
                if ($chunk === '') {
                    throw new InferenceAdmissionException('Selected embedding context cannot contain the next UTF-8 character.');
                }
                $chunks[] = $chunk;
                $offset += strlen($chunk);
            }

            return $chunks;
        }

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
        $options = $this->normalizePrivacyRoutingConfig($options);
        $callerConstraints = [];
        foreach (['local_only', 'disable_external_fallback', 'ollama_required_no_fallback', 'sensitive_data', 'preserve_embedding_input', '_ollama_instance_selection_explicit'] as $constraint) {
            if ($this->truthyDbValue($options[$constraint] ?? false)) {
                $callerConstraints[$constraint] = true;
            }
        }
        foreach (['allow_cpu_fallback', 'allow_cpu_model_download'] as $constraint) {
            if (array_key_exists($constraint, $options) && ! $this->truthyDbValue($options[$constraint])) {
                $callerConstraints[$constraint] = false;
            }
        }
        foreach (['required_instance_id', 'allowed_ollama_instance_ids', 'preferred_ollama_instance_ids'] as $constraint) {
            if (array_key_exists($constraint, $options)) {
                $callerConstraints[$constraint] = $options[$constraint];
            }
        }
        if (! empty($options['required_instance_id']) || ! empty($options['ollama_required_no_fallback'])) {
            foreach (['model', 'model_override'] as $constraint) {
                if (array_key_exists($constraint, $options)) {
                    $callerConstraints[$constraint] = $options[$constraint];
                }
            }
        }
        try {
            if (self::$workflowEmbeddingModelResolver !== null) {
                $options = array_replace($options, (self::$workflowEmbeddingModelResolver)($options));
            }
        } catch (RuntimeException $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['success' => false, 'embedding' => null, 'provider' => null,
                'error' => $error->getMessage(), 'duration_ms' => 0, 'fallback_suppressed' => true];
        }
        $options = $this->normalizePrivacyRoutingConfig(array_replace($options, $callerConstraints));
        $exactSelection = ! empty($options['required_instance_id']) || ! empty($options['ollama_required_no_fallback']);
        $role = $options['model_role'] ?? 'embedding';
        if (! is_string($role) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $role)
            || ($role !== 'embedding' && ! $exactSelection)) {
            return ['success' => false, 'embedding' => null, 'provider' => null,
                'error' => 'An alternate embedding role requires an exact table-bound selection.',
                'duration_ms' => 0, 'fallback_suppressed' => true];
        }
        $options['model_role'] = $role;
        if ($exactSelection || ! empty($options['preferred_ollama_instance_ids']) || ! empty($options['allowed_ollama_instance_ids'])) {
            $options = $this->applyPreferredOllamaInstanceOrder($options, $role);
        }
        if ($exactSelection) {
            $options['exact_embedding_model'] = $options['model_override'] ?? $options['model'] ?? null;
            if (! is_string($options['exact_embedding_model']) || $options['exact_embedding_model'] === '') {
                return ['success' => false, 'embedding' => null, 'provider' => null,
                    'error' => 'An exact embedding model is required; no alternate selected.', 'duration_ms' => 0, 'fallback_suppressed' => true];
            }
            $options['allow_cpu_fallback'] = false;
        }
        $preserveInput = (bool) ($options['preserve_embedding_input'] ?? false);
        $options = $this->withRouteTraceContext($options);
        $startTime = microtime(true);
        $allowCpuFallback = $options['allow_cpu_fallback'] ?? true;

        $providers = $this->getEmbeddingProviders($options);

        if (empty($providers)) {
            return [
                'success' => false,
                'embedding' => null,
                'provider' => null,
                'error' => $exactSelection ? 'Selected embedding provider/model is unavailable; no alternate selected.' : 'No healthy embedding providers available',
                'fallback_suppressed' => $exactSelection,
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
            // The admitted table-selected provider must retain originals even without a caller override.
            $strictChunks = ($preserveInput || ! PromptIntegrityPolicy::allowsLossyTransforms()) && $isOllama
                && strlen($content) > (int) ($tokenLimit * 0.60 * 1.5);
            if ($strictChunks || (! $preserveInput && $estimatedTokens > (int) ($tokenLimit * 0.70))) {
                $result = $this->generateChunkedEmbeddingForProvider($content, $tokenLimit, $provider, $options);
                if (($result['fallback_suppressed'] ?? false) === true) {
                    return array_merge($result, ['duration_ms' => (int) ((microtime(true) - $startTime) * 1000)]);
                }
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
                $result = $this->tryEmbeddingWithRetry($provider, $content, $options);
            } else {
                $result = $this->tryExternalEmbeddingWithRetry($provider, $content, $options);
            }

            if (($result['fallback_suppressed'] ?? false) === true) {
                return array_merge($result, ['duration_ms' => (int) ((microtime(true) - $startTime) * 1000)]);
            }
            if ($result['success']) {
                return array_merge($result, [
                    'chunked' => false,
                    'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                ]);
            }

            // If context length exceeded on Ollama, try chunked for this provider
            $errorMsg = $result['error'] ?? '';
            if (! $preserveInput && $isOllama && (str_contains($errorMsg, 'input length exceeds') || str_contains($errorMsg, 'context length'))) {
                Log::info('AIService: Token estimate undercount, falling back to chunked embedding', [
                    'provider' => $provider['id'] ?? $provider['name'] ?? 'unknown',
                    'text_length' => strlen($content),
                    'estimated_tokens' => $estimatedTokens,
                    'token_limit' => $tokenLimit,
                ]);
                $chunkedResult = $this->generateChunkedEmbeddingForProvider($content, $tokenLimit, $provider, $options);
                if (($chunkedResult['fallback_suppressed'] ?? false) === true) {
                    return array_merge($chunkedResult, ['duration_ms' => (int) ((microtime(true) - $startTime) * 1000)]);
                }
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
                'error' => $exactSelection ? 'Selected embedding provider/model failed; no alternate selected. '.($result['error'] ?? '') : 'All non-CPU embedding providers failed; CPU fallback disabled for this request',
                'fallback_suppressed' => $exactSelection,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }

        // N49: CPU fallback — last resort when all providers are down
        $cpuResult = $this->tryCpuEmbeddingFallback($content, $preserveInput, $this->cpuEmbeddingDownloadAllowed($options));
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
            'error' => $preserveInput ? ($cpuResult['error'] ?? 'Embedding failed without modifying the input.') : 'All embedding providers failed (Ollama + external + CPU fallback)',
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

        // Ollama providers arrive from orderOllamaInstancesForRole(), which is
        // already pool-scored for health, busy state, role-fit, and table-owned
        // provider-role bias. Do not re-sort them here by legacy priority, or
        // embedding routes will erase the DB policy that keeps idle local hosts busy.

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
    private function generateChunkedEmbeddingForProvider(
        string $content,
        int $tokenLimit,
        array $provider,
        array $traceConfig = []
    ): array {
        $startTime = microtime(true);
        $isOllama = ($provider['instance_type'] ?? '') === 'ollama';

        $chunks = $this->splitForEmbedding($content, $tokenLimit, (bool) ($traceConfig['preserve_embedding_input'] ?? false));
        $chunkCount = count($chunks);

        Log::info('AIService: Auto-chunked embedding for provider', [
            'provider' => $provider['id'] ?? $provider['name'] ?? 'unknown',
            'type' => $provider['instance_type'] ?? 'unknown',
            'original_chars' => strlen($content),
            'chunks' => $chunkCount,
            'token_limit' => $tokenLimit,
        ]);

        $embeddings = [];
        $chunkDimensions = null;
        $chunkIdentity = null;

        foreach ($chunks as $index => $chunk) {
            if ($isOllama) {
                $result = $this->tryEmbeddingWithRetry($provider, $chunk, $traceConfig);
            } else {
                $result = $this->tryExternalEmbeddingWithRetry($provider, $chunk, $traceConfig);
            }

            if (($result['fallback_suppressed'] ?? false) === true) {
                return array_merge($result, ['duration_ms' => (int) ((microtime(true) - $startTime) * 1000)]);
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

            $vector = $result['embedding'] ?? null;
            if (! is_array($vector) || ! array_is_list($vector) || $vector === []
                || ($chunkDimensions !== null && count($vector) !== $chunkDimensions)) {
                throw new InferenceAdmissionException('Embedding chunk vector shape or dimensions are unverified.');
            }
            foreach ($vector as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    throw new InferenceAdmissionException('Embedding chunk vector contains an unverified value.');
                }
            }
            $identity = [
                'provider' => $result['provider'] ?? null,
                'instance_id' => $result['instance_id'] ?? $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'revision' => $result['revision'] ?? null,
            ];
            foreach (['provider', 'instance_id', 'model'] as $field) {
                if (! is_string($identity[$field]) || trim($identity[$field]) === '') {
                    throw new InferenceAdmissionException('Embedding chunk identity is unverified.');
                }
            }
            if ($identity['revision'] !== null && (! is_string($identity['revision']) || trim($identity['revision']) === '')) {
                throw new InferenceAdmissionException('Embedding chunk revision is unverified.');
            }
            if ($chunkIdentity !== null && $identity !== $chunkIdentity) {
                throw new InferenceAdmissionException('Embedding chunk provider, instance, model or revision changed.');
            }
            $chunkIdentity ??= $identity;
            $chunkDimensions ??= count($vector);
            $embeddings[] = $vector;
        }

        $pooledEmbedding = $this->meanPoolEmbeddings($embeddings);

        return [
            'success' => true,
            'embedding' => $pooledEmbedding,
            'provider' => $chunkIdentity['provider'],
            'instance_id' => $chunkIdentity['instance_id'],
            'model' => $chunkIdentity['model'],
            'revision' => $chunkIdentity['revision'],
            'error' => null,
            'chunked' => true,
            'chunk_count' => $chunkCount,
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
        ] + (empty($traceConfig['preserve_embedding_input']) ? [] : [
            'embedding_input_hash' => hash('sha256', $content),
            'embedding_input_bytes' => strlen($content),
            'preserved_input_chunks' => array_map(static fn (string $chunk): array => [
                'sha256' => hash('sha256', $chunk), 'bytes' => strlen($chunk),
            ], $chunks),
        ]);
    }

    /**
     * Process with MCP tools - Ollama-aware
     */
    public function processWithTools(string $prompt, array $config = [], int $maxIterations = 5): array
    {
        $config = $this->normalizePrivacyRoutingConfig($config);
        if (is_string($config['required_instance_id'] ?? null) && trim($config['required_instance_id']) !== '') {
            $out = ['success' => false, 'response' => '', 'provider' => $config['required_instance_id'], 'fallback_suppressed' => true];
            foreach ($this->processWithToolsStreaming($prompt, $config, $maxIterations) as $line) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (($event['type'] ?? null) === 'content') {
                    $out['response'] .= $event['content'];
                }
                if (($event['type'] ?? null) === 'done') {
                    $out['success'] = true;
                }
                if (in_array($event['type'] ?? null, ['error', 'stream_interrupted'], true)) {
                    $out['error'] = $event['content'] ?? $event['message'] ?? 'Required provider failed';
                    $out['success'] = false;
                }
            }

            return $out;
        }
        $startTime = microtime(true);

        // Coding fitness and native tool-protocol support are independent facts.
        // The role model must advertise an exact tool capability before host
        // health, latency, success rate, priority, and role fit can rank it.
        $role = $config['model_role'] ?? 'standard';
        $instance = $this->selectOllamaInstanceForRole(
            $role,
            $config,
            [['tool_calling', 'tool_use', 'tools']]
        );
        if ($instance === null) {
            return [
                'success' => false,
                'response' => null,
                'provider' => null,
                'error' => 'No healthy Ollama role model is admitted for native tool calling.',
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }

        $perInstanceModel = $this->resolveModelForProvider($instance['id'], $role)
            ?? $this->resolveModelForProvider($instance['id'], 'standard')
            ?? $this->defaultModel;
        $managed = app(ModelHostControllerResourcePolicyService::class)->managedProvider($instance['url']) !== null;
        if ($managed) {
            $config = $this->prepareManagedRuntimeConfig($config, $instance['config'] ?? [], $role, 'ollama');
        } else {
            $ollamaStatus = $this->getOllamaStatus($perInstanceModel, $instance['url']);
            if ($ollamaStatus['available']) {
                $config['ai_timeout'] = $this->calculateTimeout($ollamaStatus, $perInstanceModel);
            }
        }

        $config['instance_url'] = $instance['url'];
        $config['instance_model'] = $perInstanceModel;

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
     * 2. Falls back to configured non-streaming providers if Ollama fails
     * 3. Records failures for circuit breaker
     *
     * @param  string  $prompt  User prompt
     * @param  array  $config  Configuration (temperature, max_tokens, system_prompt)
     * @param  int  $maxIterations  Maximum tool calling iterations
     * @return \Generator Yields SSE-formatted chunks
     */
    public function processWithToolsStreaming(string $prompt, array $config = [], int $maxIterations = 5): \Generator
    {
        try {
            $config = self::workflowModelConfig($config);
        } catch (RuntimeException $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }
            yield json_encode(['type' => 'error', 'content' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n";

            return;
        }
        $config = $this->normalizePrivacyRoutingConfig($config);
        $config['_prompt_chars'] = mb_strlen($prompt)
            + mb_strlen((string) ($config['system_prompt'] ?? $config['system'] ?? ''));
        $requiredInstanceId = is_string($config['required_instance_id'] ?? null)
            ? trim($config['required_instance_id'])
            : '';
        if ($requiredInstanceId !== '') {
            $config['_native_max_iterations'] = $maxIterations;
            yield from $this->streamRequiredOpenAICompatibleProvider($requiredInstanceId, $prompt, $config);

            return;
        }

        $requestId = uniqid('stream_', true);

        // Streaming defaults to the `standard` role. selectOllamaInstanceForRole()
        // routes table-driven: the host whose standard model scores best wins.
        // If no local instance is available the existing cascade falls through to
        // the table-gated non-streaming fallback.
        $role = $config['model_role'] ?? 'standard';
        $requiredModel = $this->truthyDbValue($config['ollama_required_no_fallback'] ?? false)
            ? trim((string) ($config['model_override'] ?? $config['model'] ?? '')) : '';
        $instance = $this->selectOllamaInstanceForRole(
            $role,
            $config,
            [['tool_calling', 'tool_use', 'tools']],
            $requiredModel !== '' ? $requiredModel : null
        );
        if ($instance === null) {
            Log::info('AIService: No healthy Ollama instances for streaming, falling back to table-gated non-streaming processing');
            yield from $this->streamingFallbackToNonStreamingProcess($prompt, $config);

            return;
        }

        $providerId = $instance['id'];
        if ($this->isCircuitOpen($providerId)) {
            Log::info('AIService: Selected streaming circuit open, falling back to table-gated non-streaming processing');
            yield from $this->streamingFallbackToNonStreamingProcess($prompt, $config);

            return;
        }

        $streamModel = $config['model_override'] ?? $config['model']
            ?? $this->resolveModelForProvider($instance['id'], $role)
            ?? $this->resolveModelForProvider($instance['id'], 'standard')
            ?? $this->defaultModel;
        $config['instance_url'] = $instance['url'];
        $config['instance_model'] = $streamModel;
        $managed = app(ModelHostControllerResourcePolicyService::class)->managedProvider($instance['url']) !== null;
        if ($managed) {
            $config = $this->prepareManagedRuntimeConfig($config, $instance['config'] ?? [], $role, 'ollama');
        }
        $ollamaStatus = $managed ? null : $this->getOllamaStatus($streamModel, $instance['url']);

        if (! $managed && ! $ollamaStatus['available']) {
            $this->recordFailure($providerId);
            Log::info('AIService: Ollama not available for streaming, falling back to table-gated non-streaming processing');
            yield from $this->streamingFallbackToNonStreamingProcess($prompt, $config);

            return;
        }

        // Check if Ollama is busy
        if (! $managed && $this->isOllamaBusy($providerId)) {
            Log::info('AIService: Ollama busy, falling back to table-gated non-streaming processing for streaming request');
            yield from $this->streamingFallbackToNonStreamingProcess($prompt, $config);

            return;
        }

        // Try to acquire busy lock
        if (! $managed && ! $this->acquireOllamaBusyLock($requestId, $providerId)) {
            Log::info('AIService: Could not acquire Ollama lock for streaming, falling back to table-gated non-streaming processing');
            yield from $this->streamingFallbackToNonStreamingProcess($prompt, $config);

            return;
        }

        $poolReceipt = $managed ? null : $this->poolManager->executionReceipt($providerId, $requestId, $streamModel);
        if ($poolReceipt !== null) {
            $config['_pool_execution_receipt'] = $poolReceipt;
        }
        $terminalStop = null;
        $streamState = new StreamFallbackGuard;

        try {
            // Set timeout based on model state
            if (! $managed) {
                $config['ai_timeout'] = $this->calculateTimeout($ollamaStatus, $streamModel);
            }

            // Yield start event
            yield json_encode(['type' => 'provider', 'provider' => 'ollama', 'instance_id' => $providerId, 'model' => $streamModel])."\n";

            // Stream from AIRouter
            $startTime = microtime(true);

            foreach ($this->aiRouter->processWithToolsStreaming($prompt, $config, $maxIterations) as $chunk) {
                $event = $streamState->observe($chunk);
                if (($event['type'] ?? '') === 'error') {
                    $poolReceipt?->stopReplayAfterFailure();
                    $this->recordFailure($providerId);
                    $correlationId = substr(uniqid('fallback_', true), 0, 24);

                    if (! $managed && $streamState->canFallback()) {
                        Log::warning('AIService: Streaming failed before response commit; using table-gated fallback', [
                            'correlation_id' => $correlationId,
                            'provider' => $providerId,
                        ]);
                        yield from $this->streamingFallbackAfterPreCommitFailure($prompt, $config, $correlationId);
                    } else {
                        Log::error('AIService: Streaming failed after response commit; fallback suppressed', [
                            'correlation_id' => $correlationId,
                            'provider' => $providerId,
                            'commit_kind' => $streamState->commitKind(),
                        ]);
                        yield from $this->streamInterruptedChunks($streamState, $correlationId);
                    }

                    return;
                }

                yield $chunk;
            }

            if ($streamState->completedSuccessfully()) {
                $poolReceipt?->assertTerminalBeforeSuccess();
                $this->recordSuccess($providerId, microtime(true) - $startTime);

                return;
            }

            $poolReceipt?->stopReplayAfterFailure();
            $this->recordFailure($providerId);
            $correlationId = substr(uniqid('fallback_', true), 0, 24);
            if (! $managed && $streamState->canFallback()) {
                Log::warning('AIService: Streaming ended before completion; using table-gated fallback', [
                    'correlation_id' => $correlationId,
                    'provider' => $providerId,
                ]);
                yield from $this->streamingFallbackAfterPreCommitFailure($prompt, $config, $correlationId);
            } else {
                Log::error('AIService: Streaming ended after response commit; fallback suppressed', [
                    'correlation_id' => $correlationId,
                    'provider' => $providerId,
                    'commit_kind' => $streamState->commitKind(),
                ]);
                yield from $this->streamInterruptedChunks($streamState, $correlationId);
            }

        } catch (\Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                $terminalStop = $e;
                throw $e;
            }
            try {
                $poolReceipt?->stopReplayAfterFailure($e);
            } catch (\Throwable $stop) {
                $terminalStop = $stop;
                throw $stop;
            }
            $this->recordFailure($providerId);
            $correlationId = substr(uniqid('fallback_', true), 0, 24);
            if (! $managed && $streamState->canFallback()) {
                Log::warning('AIService: Streaming exception before response commit; using table-gated fallback', [
                    'error' => $e->getMessage(),
                    'correlation_id' => $correlationId,
                    'provider' => $providerId,
                ]);
                yield from $this->streamingFallbackAfterPreCommitFailure($prompt, $config, $correlationId);
            } else {
                Log::error('AIService: Streaming exception after response commit; fallback suppressed', [
                    'error' => $e->getMessage(),
                    'correlation_id' => $correlationId,
                    'provider' => $providerId,
                    'commit_kind' => $streamState->commitKind(),
                ]);
                yield from $this->streamInterruptedChunks($streamState, $correlationId);
            }

        } finally {
            try {
                if (! $managed) {
                    $this->releaseOllamaBusyLock($requestId, $providerId);
                }
            } catch (\Throwable $cleanupError) {
                throw $terminalStop ?? $cleanupError;
            }
        }
    }

    /**
     * @return \Generator<int, string>
     */
    private function streamingFallbackAfterPreCommitFailure(string $prompt, array $config, string $correlationId): \Generator
    {
        yield json_encode([
            'type' => 'fallback',
            'provider' => 'table_gated_non_streaming',
            'reason' => 'pre_commit_stream_failure',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        yield from $this->streamingFallbackToNonStreamingProcess($prompt, $config, $correlationId);
    }

    /** @return \Generator<int,string> */
    private function streamInterruptedChunks(StreamFallbackGuard $streamState, string $correlationId): \Generator
    {
        foreach ($streamState->drainPendingToolFailures() as $toolFailure) {
            yield json_encode($toolFailure, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }

        yield json_encode([
            'type' => 'stream_interrupted',
            'message' => 'Response interrupted after partial output. Automatic fallback was suppressed to avoid duplicating content or tool effects.',
            'partial_content_preserved' => true,
            'fallback_suppressed' => true,
            'commit_kind' => $streamState->commitKind(),
            'correlation_id' => $correlationId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Fallback to table-gated non-streaming processing for streaming requests
     *
     * The normal process() chain enforces llm_instances privacy attributes and
     * only uses private-approved providers when sensitive_data=true.
     *
     * @param  string  $prompt  User prompt
     * @param  array  $config  Configuration options
     * @return \Generator Yields SSE-formatted chunks
     */
    private function streamingFallbackToNonStreamingProcess(string $prompt, array $config = [], ?string $correlationId = null): \Generator
    {
        $config = $this->normalizePrivacyRoutingConfig($config);
        yield json_encode(['type' => 'provider', 'provider' => 'table_gated_non_streaming', 'model' => $config['model_role'] ?? 'standard'])."\n";
        yield json_encode(['type' => 'fallback_notice', 'message' => 'Using table-gated non-streaming fallback'])."\n";

        try {
            $result = $this->process($prompt, array_merge($config, [
                'sensitive_data' => $config['sensitive_data'] ?? true,
                'skip_if_busy' => true,
            ]));

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
                yield json_encode(['type' => 'error', 'content' => $result['error'] ?? 'Fallback processing failed'])."\n";
            }

        } catch (Exception $e) {
            Log::error('AIService: table-gated non-streaming fallback also failed', [
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
        $endpoint = rtrim($instance['url'], '/').'/api/generate';
        if (! self::hasManagedInferenceContract()
            && app(ModelHostControllerResourcePolicyService::class)->managedProvider($endpoint) !== null) {
            $config = $this->prepareManagedRuntimeConfig($config, $instance['config'] ?? [], $config['model_role'] ?? 'standard', 'ollama');
            $settings = AIRouter::ollamaGenerationSettings($model, (float) ($config['temperature'] ?? 0.1),
                (int) ($config['max_tokens'] ?? $instance['config']['max_tokens'] ?? 0), $config);

            try {
                return app(ModelHostControllerTransportSupervisor::class)->run('ollama_generate', $endpoint, $settings,
                    fn () => $this->tryOllamaWithRetry($instance, $prompt, $model, $config),
                    ['capacity_fallback_supported' => true]);
            } catch (ModelHostControllerUnavailableException $error) {
                if (! $error->allowsCapacityFallback() || ! empty($config['required_instance_id'])
                    || $this->truthyDbValue($config['ollama_required_no_fallback'] ?? false)) {
                    throw new InferenceAdmissionException('Exact controller admission refused; no alternate model request is allowed.', 0, $error);
                }

                return ['success' => false, 'response' => null, 'error' => 'Local capacity wait expired before dispatch',
                    'provider' => $instance['id'], 'skip_to_fallback' => true,
                    'controller_failure' => InferenceAdmissionException::controllerFailureFrom($error)];
            }
        }
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

        $poolReceipt = $this->poolManager->executionReceipt($providerId, $requestId, $model);
        $config['_pool_execution_receipt'] = $poolReceipt;
        $terminalStop = null;
        try {
            // Get Ollama status for this instance
            $ollamaStatus = $this->getOllamaStatus($model, $instance['url']);

            if (! $ollamaStatus['available']) {
                $this->recordFailure($providerId, 'Ollama not available', null, $this->modelTelemetry($config, $model));

                return [
                    'success' => false,
                    'error' => 'Ollama not available: '.($ollamaStatus['error'] ?? 'connection failed'),
                    'skip_to_fallback' => true,
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
                $this->calculateTimeout(
                    $ollamaStatus,
                    $model,
                    is_array($instance['config'] ?? null) ? $instance['config'] : []
                ),
                $config['ai_timeout'] ?? null,
                $this->truthyDbValue($config['_allow_ai_timeout_extension'] ?? false)
            );
            $config['ai_timeout'] = $timeout;
            $config = $this->alignOllamaProgressIdleTimeout($config, $timeout);
            $config['ai_mode'] = 'local';

            // Pre-warm model if not loaded (cold start or model swap needed)
            if (self::inferenceReplayAllowed() && ! $ollamaStatus['model_loaded']) {
                Log::info('AIService: Model not loaded, pre-warming', [
                    'model' => $model,
                    'needs_swap' => $ollamaStatus['needs_swap'] ?? false,
                    'current_model' => $ollamaStatus['current_model'] ?? 'none',
                ]);
                $preWarmResult = $this->preWarmModel($model, $instance['url'], $poolReceipt);
                if (($preWarmResult['status'] ?? 'failed') === 'failed') {
                    Log::warning('AIService: Pre-warm failed, continuing with extended timeout', [
                        'reason' => $preWarmResult['reason'] ?? 'unknown',
                    ]);
                }
            }

            // Retry loop with exponential backoff
            $lastError = null;
            $maxAttempts = $this->providerRetryMaxAttempts();
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    Log::info("AIService: Ollama attempt {$attempt}/{$maxAttempts}", [
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
                    if ($this->isExactStructuredOutputEvalProbe($config, 'ollama')) {
                        // AIRouter must return schema-invalid probe output to the
                        // eval runner as quality evidence. Ordinary structured
                        // routes still fail closed before output is returned.
                        $config['_structured_output_eval_probe_admitted'] = true;
                    }
                    $response = $this->aiRouter->processWithAI($prompt, $config);

                    $poolReceipt->assertTerminalBeforeSuccess();
                    $this->recordSuccess($providerId, microtime(true) - $startTime, $this->modelTelemetry($config, $model));

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
                    $poolReceipt->stopReplayAfterFailure($e);
                    if (InferenceAdmissionException::isAdmissionFailure($e)) {
                        throw $e;
                    }
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
                        'will_retry' => $typed->isRetryable() && $attempt < $maxAttempts,
                    ]);

                    // Don't retry permanent errors
                    if (! $typed->isRetryable()) {
                        break;
                    }

                    // Use exception-suggested backoff or calculate default
                    if ($attempt < $maxAttempts) {
                        $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                        Log::info("AIService: Backing off {$backoffMs}ms before retry");
                        usleep($backoffMs * 1000);
                    }
                }
            }

            $this->recordFailure($providerId, $lastError, null, $this->modelTelemetry($config, $model));

            return [
                'success' => false,
                'error' => $lastError ?? 'Unknown error',
            ];

        } catch (\Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                $terminalStop = $error;
            }
            throw $error;
        } finally {
            try {
                $this->releaseOllamaBusyLock($requestId, $providerId);
            } catch (\Throwable $cleanupError) {
                throw $terminalStop ?? $cleanupError;
            }
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

        $exactModel = null;
        if ($this->truthyDbValue($config['ollama_required_no_fallback'] ?? false)) {
            $exactModel = $this->resolveOllamaRequestModel($instance, (string) ($config['model_override'] ?? $config['model'] ?? ''), 'vision', $config);
            if ($exactModel === null) {
                return ['success' => false, 'error' => 'Selected vision model is not advertised by this Ollama instance.'];
            }
        }

        if ($this->isCircuitOpen($providerId)) {
            return ['success' => false, 'error' => 'Circuit open', 'skip_to_fallback' => true];
        }

        // Check if this specific Ollama instance is busy.
        // (vision operations take longer and we don't want to block)
        if ($this->isOllamaBusy($baseProviderId)) {
            $busyInfo = $this->getOllamaBusyInfo($baseProviderId);
            Log::info('AIService: Ollama instance busy, skipping vision to table-gated fallback', [
                'instance_id' => $baseProviderId,
                'busy_since' => $busyInfo['started_at'] ?? 'unknown',
            ]);

            return ['success' => false, 'error' => "Ollama instance {$baseProviderId} busy", 'skip_to_fallback' => true];
        }

        // Acquire busy lock for this specific instance
        if (! $this->acquireOllamaBusyLock($requestId, $baseProviderId)) {
            return ['success' => false, 'error' => "Could not acquire lock for {$baseProviderId}", 'skip_to_fallback' => true];
        }

        $poolReceipt = null;
        $terminalStop = null;
        try {
            // Resolve vision model for THIS instance rather than using the primary's
            // vision model — the 4070 can host a stronger vision model than the 1060.
            $perInstanceVisionModel = $exactModel ?? $this->resolveModelForProvider($instance['id'], 'vision')
                ?? $this->visionModel;
            $poolReceipt = $this->poolManager->executionReceipt($baseProviderId, $requestId, $perInstanceVisionModel);
            $config['_pool_execution_receipt'] = $poolReceipt;

            $ollamaStatus = $this->getOllamaStatus($perInstanceVisionModel, $instance['url']);

            if (! $ollamaStatus['available']) {
                $this->recordFailure($providerId, 'Ollama not available', null, $this->modelTelemetry($config, $perInstanceVisionModel, [
                    'instance_id' => $baseProviderId,
                    'request_kind' => 'vision',
                ]));

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
            $config = $this->prepareManagedRuntimeConfig($config, $instance['config'] ?? [], 'vision', 'ollama');

            $lastError = null;
            $maxAttempts = $this->providerRetryMaxAttempts();
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $startTime = microtime(true);
                    $response = $this->aiRouter->processWithImage($imageContent, $prompt, $config);
                    $poolReceipt->assertTerminalBeforeSuccess();
                    $this->recordSuccess($providerId, microtime(true) - $startTime, $this->modelTelemetry($config, $perInstanceVisionModel, [
                        'instance_id' => $baseProviderId,
                        'request_kind' => 'vision',
                    ]));

                    return [
                        'success' => true,
                        'response' => $response,
                        'provider' => $providerId,
                        'model' => $perInstanceVisionModel,
                        'attempt' => $attempt,
                        'error' => null,
                    ];
                } catch (InferenceAdmissionException $e) {
                    $terminalStop = $e;
                    $controllerFailure = InferenceAdmissionException::controllerFailureFrom($e);
                    $this->recordModelAttemptTelemetry($baseProviderId, false, null, $this->modelTelemetry($config, $perInstanceVisionModel, [
                        'instance_id' => $baseProviderId,
                        'request_kind' => 'vision',
                        'error' => $controllerFailure === [] ? $e->getMessage() : json_encode(['controller_failure' => $controllerFailure], JSON_THROW_ON_ERROR),
                    ]));

                    return ['success' => false, 'response' => null, 'error' => $e->getMessage(),
                        'provider' => $baseProviderId, 'fallback_suppressed' => true]
                        + ($controllerFailure === [] ? [] : ['controller_failure' => $controllerFailure]);
                } catch (Exception $e) {
                    $poolReceipt?->stopReplayAfterFailure($e);
                    if (InferenceAdmissionException::isAdmissionFailure($e)) {
                        throw $e;
                    }
                    $typed = $e instanceof AIServiceException
                        ? $e
                        : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $perInstanceVisionModel);
                    $lastError = $typed->getMessage();
                    if (! self::inferenceReplayAllowed() || ! $typed->isRetryable() || $attempt >= $maxAttempts) {
                        break;
                    }
                    $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                    usleep($backoffMs * 1000);
                }
            }

            $this->recordFailure($providerId, $lastError, null, $this->modelTelemetry($config, $perInstanceVisionModel, [
                'instance_id' => $baseProviderId,
                'request_kind' => 'vision',
            ]));

            return ['success' => false, 'error' => $lastError];

        } catch (\Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                $terminalStop = $error;
            }
            throw $terminalStop ?? $error;
        } finally {
            try {
                $this->releaseOllamaBusyLock($requestId, $baseProviderId);
            } catch (\Throwable $cleanupError) {
                throw $terminalStop ?? $cleanupError;
            }
        }
    }

    /**
     * Try embedding with retry
     */
    private function tryEmbeddingWithRetry(array $instance, string $content, array $traceConfig = []): array
    {
        $providerId = $instance['id'].'_embedding';
        $modelOverride = $traceConfig['exact_embedding_model'] ?? null;
        $model = $modelOverride ?? $this->embeddingModel;

        if ($this->isCircuitOpen($providerId)) {
            return ['success' => false, 'error' => 'Circuit open', 'skip_to_fallback' => true];
        }

        // Embeddings use nomic-embed-text which is lightweight (~137MB VRAM) and can
        // coexist with inference models on the GPU. No busy lock needed — this enables
        // both Ollama instances to handle embeddings simultaneously during batch operations.
        $baseInstanceId = $instance['id']; // Registered identity for pool manager metrics

        try {
            $ollamaStatus = $this->getOllamaStatus($model, $instance['url']);

            if (! $ollamaStatus['available']) {
                $this->recordFailure($providerId);

                return ['success' => false, 'error' => 'Ollama not available', 'skip_to_fallback' => true];
            }

            $embeddingTimeout = $this->resolveEmbeddingTimeoutSeconds($instance, $ollamaStatus);

            $lastError = null;
            $maxAttempts = $this->providerRetryMaxAttempts();
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $startTime = microtime(true);
                    $embedding = $this->aiRouter->generateEmbedding(
                        $content,
                        $instance['url'] ?? null,
                        null,
                        $embeddingTimeout,
                        $modelOverride,
                        (bool) ($traceConfig['preserve_embedding_input'] ?? false)
                    );
                    $duration = microtime(true) - $startTime;
                    $this->recordSuccess($providerId, $duration);
                    // Also record on the base instance ID so pool manager updates llm_instances and model telemetry.
                    $this->recordSuccess($baseInstanceId, $duration, $this->modelTelemetry(
                        $traceConfig,
                        $model,
                        ['request_kind' => 'embedding']
                    ));

                    return [
                        'success' => true,
                        'embedding' => $embedding,
                        'provider' => 'ollama',
                        'instance_id' => $baseInstanceId,
                        'model' => $model,
                        'revision' => $ollamaStatus['model_digest'] ?? null,
                        'error' => null,
                    ];
                } catch (InferenceAdmissionException $e) {
                    return $this->managedEmbeddingFailure($e, $baseInstanceId, $model);
                } catch (Exception $e) {
                    if (InferenceAdmissionException::isAdmissionFailure($e)) {
                        throw $e;
                    }
                    $typed = $e instanceof AIServiceException
                        ? $e
                        : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $model);
                    $lastError = $typed->getMessage();
                    if (! $typed->isRetryable() || $attempt >= $maxAttempts) {
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
                $this->recordFailure($baseInstanceId, $lastError, null, $this->modelTelemetry(
                    $traceConfig,
                    $model,
                    ['request_kind' => 'embedding']
                ));
            }

            return ['success' => false, 'error' => $lastError, 'context_length_exceeded' => $isContextLength];

        } catch (InferenceAdmissionException $e) {
            return $this->managedEmbeddingFailure($e, $baseInstanceId, $model);
        } catch (Exception $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            $this->recordFailure($providerId);
            $this->recordFailure($baseInstanceId, $e->getMessage(), null, $this->modelTelemetry(
                $traceConfig,
                $model,
                ['request_kind' => 'embedding']
            ));

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function managedEmbeddingFailure(InferenceAdmissionException $error, string $provider, ?string $model): array
    {
        // Preserve execution custody across callers; an ordinary error array loses the typed stop.
        throw $error;
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
    private function tryExternalEmbeddingWithRetry(array $provider, string $content, array $traceConfig = []): array
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
        $maxAttempts = $this->providerRetryMaxAttempts();
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $startTime = microtime(true);

                $embedding = $this->aiRouter->generateExternalEmbedding($content, [
                    'base_url' => $provider['base_url'],
                    'api_key' => $provider['api_key'],
                    'embedding_model' => $provider['embedding_model'],
                    'embedding_dimensions' => $provider['embedding_dimensions'] ?? null,
                    'extra_headers' => $provider['extra_headers'] ?? [],
                    'instance_id' => $provider['id'],
                    'preserve_input' => (bool) ($traceConfig['preserve_embedding_input'] ?? false),
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
                $this->recordSuccess($provider['id'], $duration, $this->modelTelemetry(
                    $traceConfig,
                    $provider['embedding_model'],
                    ['request_kind' => 'embedding']
                ));

                // Track rate limit
                if ($provider['rate_limit_rpm'] ?? null) {
                    Cache::increment("rpm_count_{$provider['id']}");
                }

                return [
                    'success' => true,
                    'embedding' => $embedding,
                    'provider' => $provider['id'],
                    'instance_id' => $provider['id'],
                    'model' => $provider['embedding_model'],
                    'revision' => $provider['config']['embedding_revision']
                        ?? $provider['config']['embedding_model_sha256']
                        ?? null,
                    'error' => null,
                ];

            } catch (InferenceAdmissionException $e) {
                return $this->managedEmbeddingFailure($e, $provider['id'], $provider['embedding_model'] ?? null);
            } catch (Exception $e) {
                if (InferenceAdmissionException::isAdmissionFailure($e)) {
                    throw $e;
                }
                $typed = $e instanceof AIServiceException
                    ? $e
                    : AIExceptionFactory::fromMessage($e->getMessage(), $providerId, $provider['embedding_model'] ?? 'unknown');
                $lastError = $typed->getMessage();

                if (! $typed->isRetryable() || $attempt >= $maxAttempts) {
                    break;
                }

                $backoffMs = $typed->getSuggestedBackoffMs() ?: $this->calculateBackoff($attempt);
                usleep($backoffMs * 1000);
            }
        }

        $this->recordFailure($providerId);
        $this->recordModelAttemptTelemetry($provider['id'], false, null, $this->modelTelemetry(
            $traceConfig,
            $provider['embedding_model'] ?? null,
            ['request_kind' => 'embedding']
        ));

        return ['success' => false, 'error' => $lastError];
    }

    private function cpuEmbeddingDownloadAllowed(array $options): bool
    {
        if (! $this->truthyDbValue($options['allow_cpu_model_download'] ?? false)
            || $this->truthyDbValue($options['sensitive_data'] ?? false)
            || $this->truthyDbValue($options['local_only'] ?? false)
            || $this->truthyDbValue($options['disable_external_fallback'] ?? false)) {
            return false;
        }
        try {
            $policy = app(OfflinePolicyService::class);

            return ! $policy->isOfflineModeActive() && $policy->isInternetAccessEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * N49: CPU embedding fallback using BAAI/bge-base-en-v1.5 via Python.
     * Last-resort when all Ollama + external embedding providers are down.
     * Runs on CPU only (no GPU dependency). First call downloads model (~440MB).
     */
    private function tryCpuEmbeddingFallback(string $content, bool $preserveInput = false, bool $allowModelDownload = false): array
    {
        $startTime = microtime(true);
        $scriptPath = base_path('scripts/embeddings/cpu_embedding.py');
        $process = null;
        $pipes = [];

        if (! file_exists($scriptPath)) {
            return ['success' => false, 'error' => 'CPU embedding script not found'];
        }

        if ($preserveInput) {
            try {
                $command = ['python3', $scriptPath, '--preserve-input'];
                $execution = Process::timeout(90)->input($content);
                if (! $allowModelDownload) {
                    $command[] = '--local-files-only';
                    $execution->env(['HF_HUB_OFFLINE' => '1', 'TRANSFORMERS_OFFLINE' => '1', 'HF_HUB_DISABLE_TELEMETRY' => '1']);
                }
                $execution = $execution->run($command);
                $result = json_decode($execution->output(), true);
                if (! $execution->successful() || ! is_array($result['embedding'] ?? null)) {
                    return ['success' => false, 'error' => $result['error'] ?? 'CPU embedding failed without a complete result.'];
                }
                if (count($result['embedding']) !== 768) {
                    return ['success' => false, 'error' => 'CPU embedding dimension mismatch; expected 768.'];
                }

                return ['success' => true, 'embedding' => $result['embedding'], 'provider' => 'cpu_fallback',
                    'instance_id' => 'cpu_fallback', 'model' => $result['model'] ?? 'BAAI/bge-base-en-v1.5',
                    'chunked' => false, 'duration_ms' => (int) ((microtime(true) - $startTime) * 1000)];
            } catch (\Throwable $error) {
                return ['success' => false, 'error' => 'CPU embedding did not complete: '.$error->getMessage()];
            }
        }

        // Truncate to ~512 tokens worth of text (bge-base-en-v1.5 max sequence = 512)
        $maxChars = 512 * 4; // ~4 chars per token for English
        $text = mb_substr($content, 0, $maxChars);

        try {
            $command = ['python3', $scriptPath, '--text', $text];
            $environment = null;
            if (! $allowModelDownload) {
                $command[] = '--local-files-only';
                $environment = array_replace(getenv(), ['HF_HUB_OFFLINE' => '1', 'TRANSFORMERS_OFFLINE' => '1', 'HF_HUB_DISABLE_TELEMETRY' => '1']);
            }
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],  // stdin
                    1 => ['pipe', 'w'],  // stdout
                    2 => ['pipe', 'w'],  // stderr
                ],
                $pipes,
                null,
                $environment
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
                'instance_id' => 'cpu_fallback',
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
     * Try Codex Exec as an explicit, table-backed noninteractive pipeline provider.
     */
    private function tryCodexExec(string $prompt, array $config): array
    {
        $providerId = 'codex_exec';

        if ($this->isOfflineMode()) {
            return ['success' => false, 'error' => 'Codex Exec blocked: routing.offline_mode is enabled.'];
        }

        if ($this->truthyDbValue($config['local_only'] ?? false)
            || $this->truthyDbValue($config['disable_external_fallback'] ?? false)) {
            return ['success' => false, 'error_type' => 'policy', 'error' => 'Codex Exec blocked: request requires local-only processing.'];
        }

        if (! $this->isCodexExecEnabled()) {
            return ['success' => false, 'error' => 'Codex Exec is disabled'];
        }

        if ($this->isCircuitOpen($providerId)) {
            return ['success' => false, 'error' => 'Codex Exec circuit is open'];
        }

        // Explicit preference is not privacy or routing authority. Reuse the
        // same table/profile/role admission as ordinary Codex fallback.
        $admitted = false;
        foreach ($this->buildFallbackChain($this->truthyDbValue($config['sensitive_data'] ?? false), false, $config) as $provider) {
            if (($provider['type'] ?? null) === 'codex_exec' && ($provider['id'] ?? null) === $providerId) {
                $admitted = true;
                break;
            }
        }
        if (! $admitted) {
            return ['success' => false, 'error_type' => 'policy', 'error' => 'Codex Exec blocked: provider privacy/profile/route admission refused.'];
        }

        $startTime = microtime(true);
        $result = app(CodexExecConnectorService::class)->execute($prompt, $config);
        $metadata = $this->codexExecTelemetry($config, $result);

        if ($result['success'] ?? false) {
            $this->recordSuccess($providerId, microtime(true) - $startTime, $metadata);

            return $result;
        }

        if (in_array($result['error_type'] ?? null, ['capacity', 'policy'], true)) {
            Log::info('AIService: Codex Exec request not admitted; leaving provider health unchanged', [
                'error' => $result['error'] ?? null,
            ]);

            return $result;
        }

        $retryAfterSeconds = null;
        if (($result['error_type'] ?? null) === 'rate_limited') {
            $retryAfterSeconds = $this->retryAfterSecondsFromProviderError((string) ($result['error'] ?? ''));
            Cache::put('rate_limit_codex_exec', true, $retryAfterSeconds);
        }

        $this->recordFailure($providerId, (string) ($result['error'] ?? 'Unknown Codex Exec error'), $retryAfterSeconds, $metadata);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function codexExecTelemetry(array $config, array $result): array
    {
        $metadata = $this->modelTelemetry(
            $config,
            is_string($result['model'] ?? null) ? $result['model'] : null,
            [
                'instance_id' => 'codex_exec',
                'caller' => 'codex_exec',
                'request_kind' => 'chat',
                'escalated' => true,
            ]
        );

        if (empty($metadata['model_role'])) {
            $role = $config['model_role'] ?? null;
            $metadata['model_role'] = is_string($role) && trim($role) !== '' ? trim($role) : 'standard';
        }

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        foreach ([
            'input_tokens',
            'cached_input_tokens',
            'cache_write_input_tokens',
            'output_tokens',
            'reasoning_output_tokens',
        ] as $field) {
            $value = filter_var(
                $usage[$field] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );
            if ($value !== false) {
                $metadata[$field] = $value;
            }
        }

        return $metadata;
    }

    private function retryAfterSecondsFromProviderError(string $error): int
    {
        return $this->circuitBreakerPolicy->retryAfterSecondsFromMessage($error);
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
     * Build the fallback chain: table-approved OpenAI-compatible providers +
     * Codex Exec, ordered by priority.
     *
     * Local Ollama is tried before this chain. Local OpenAI-compatible sidecars
     * such as BitNet enter here as `instance_type=local_llm`; table route policy
     * limits them to explicitly configured roles.
     *
     * @param  bool  $sensitiveData  If true, skip providers whose llm_instances row disallows private data
     * @param  bool  $skipRetiredProvider  Retained for signature compatibility.
     * @param  array<string,mixed>  $config
     * @return array Ordered list of providers with type, config, priority
     */
    private function buildFallbackChain(bool $sensitiveData = false, bool $skipRetiredProvider = false, array $config = []): array
    {
        // Offline kill switch: when enabled, cloud APIs are blocked but local
        // OpenAI-compatible sidecars remain eligible if their table route_policy
        // admits the requested role. Fail-closed: if the lookup errors,
        // isOfflineMode() returns true and this method admits local_llm only.
        $offlineMode = $this->isOfflineMode();

        // 3b3 profile enforcement + provider privacy gate:
        //   (a) instance_type allowlist — coarse first filter
        //   (b) per-provider allows_private_data filter when the active profile
        //       allows `cloud_sensitive_safe` but NOT `cloud_external`.
        //       instance_type='custom' covers both privacy postures — only
        //       the provider's table privacy row is authoritative.
        $profile = $offlineMode ? $this->failClosedLocalProfile('offline_mode', 'routing.offline_mode') : $this->getActiveProfile();
        $profileAllowedTypes = null;
        if ($profile !== null && isset($profile['allowed_instance_types']) && is_array($profile['allowed_instance_types'])) {
            $profileAllowedTypes = $profile['allowed_instance_types'];
        }

        $profileProviderClasses = is_array($profile['allowed_provider_classes'] ?? null)
            ? $profile['allowed_provider_classes']
            : null;
        $profileLlmRouting = is_array($profile['llm_routing'] ?? null) ? $profile['llm_routing'] : [];
        $allowedFallbackInstanceIds = is_array($profileLlmRouting['allowed_external_fallback_instance_ids'] ?? null)
            ? array_values(array_filter($profileLlmRouting['allowed_external_fallback_instance_ids'], 'is_string'))
            : null;
        $requireSensitiveSafe = $profileProviderClasses !== null
            && in_array('cloud_sensitive_safe', $profileProviderClasses, true)
            && ! in_array('cloud_external', $profileProviderClasses, true);

        $chain = [];

        $requiredInstanceId = is_string($config['required_instance_id'] ?? null)
            ? trim($config['required_instance_id'])
            : '';

        // Add external API providers from DB. A managed cold start has fresh
        // controller custody; its exact local route may opt out of cached health.
        foreach ($this->getExternalApiProviders($requiredInstanceId) as $provider) {
            if ($requiredInstanceId !== '' && ($provider['id'] ?? '') !== $requiredInstanceId) {
                continue;
            }

            if ($offlineMode && ($provider['instance_type'] ?? '') !== 'local_llm') {
                continue;
            }

            // Skip providers unsafe for sensitive/private data (caller-level)
            if ($sensitiveData && ! ($provider['allows_private_data'] ?? false)) {
                continue;
            }

            // Profile instance_type gate
            if ($profileAllowedTypes !== null && ! in_array($provider['instance_type'] ?? '', $profileAllowedTypes, true)) {
                continue;
            }

            $providerIsLocal = in_array((string) ($provider['instance_type'] ?? ''), ['ollama', 'local_llm'], true);
            if (! $providerIsLocal
                && $allowedFallbackInstanceIds !== null
                && ! in_array($provider['id'] ?? '', $allowedFallbackInstanceIds, true)
            ) {
                continue;
            }

            // Profile-level per-provider privacy gate.
            // Under hybrid_review / hybrid_dev_assist / cloud_escalation_only
            // the provider's real `allows_private_data` table value is the authoritative
            // test — `custom` instance_type alone is not sufficient.
            if ($requireSensitiveSafe && ! ($provider['allows_private_data'] ?? false)) {
                continue;
            }

            if (! $this->providerRoutePolicyAllowsRequest($provider, $config)) {
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

        $codexProvider = $offlineMode ? null : $this->getCodexExecFallbackProvider();
        if ($codexProvider !== null
            && ($requiredInstanceId === '' || $requiredInstanceId === ($codexProvider['id'] ?? null))) {
            if (! $sensitiveData || ($codexProvider['allows_private_data'] ?? false)) {
                $codexAllowedByProfile = $profileAllowedTypes === null
                    || in_array('codex_cli', $profileAllowedTypes, true);
                if ($codexAllowedByProfile
                    && ($allowedFallbackInstanceIds === null || in_array('codex_exec', $allowedFallbackInstanceIds, true))
                    && ! ($requireSensitiveSafe && ! ($codexProvider['allows_private_data'] ?? false))
                    && $this->providerRoutePolicyAllowsRequest($codexProvider, $config)
                ) {
                    if (! $this->isCircuitOpen('codex_exec') && ! Cache::has('rate_limit_codex_exec')) {
                        $chain[] = array_merge($codexProvider, ['type' => 'codex_exec']);
                    }
                }
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

    /** Existing native loop, selected provider and MCP policy; no alternate request on failure. */
    protected function streamRequiredNativeTools(array $provider, string $model, string $role, string $prompt, array $config): \Generator
    {
        $events = [];
        try {
            $pc = $provider['config'];
            $instanceId = $provider['id'];
            $caps = DB::table('ollama_models')->join('llm_instances', 'llm_instances.id', '=', 'ollama_models.instance_id')
                ->where('llm_instances.instance_id', $instanceId)->where('ollama_models.model_name', $model)
                ->where('ollama_models.is_available', 1)->value('ollama_models.capabilities');
            $native = (new LlmRoutePolicyService)->nativeToolsConfigured($pc, json_decode($caps ?? '[]', true, flags: JSON_THROW_ON_ERROR), $role);
            $targets = array_values(array_filter($pc['controller_catalog']['targets'] ?? [], fn ($target) => ($target['model'] ?? null) === $model));
            if (count($targets) !== 1) {
                throw new RuntimeException('Exact native controller target is unavailable.');
            }
            $contract = CliAgentRuntime::validateContract(['instance' => ['config' => $pc], 'engine' => $targets[0]['engine'],
                'model' => $model, 'role' => $role, 'native_tools_configured' => $native]);
            if (! $native || ($contract['native_tools_enabled'] ?? false) !== true) {
                throw new RuntimeException('Native tools are not admitted.');
            }
            $requestConfig = $this->prepareManagedRuntimeConfig($config, $pc, $role, 'openai_compatible');
            $settings = self::openAICompatibleTextPayload($requestConfig, $pc, $model);
            $route = $contract + ['instance_id' => $instanceId, 'model' => $model, 'agent_admitted' => true];
            if (isset($config['_native_max_iterations'])) {
                $route['max_iterations'] = min($route['max_iterations'], max(1, (int) $config['_native_max_iterations']));
            }
            $router = app(MCPRouter::class);
            $policy = app(OfflinePolicyService::class);
            $catalog = $router->getAvailableToolsForProfile($policy->activeProfile());
            $filter = $config['tool_filter'] ?? $this->aiRouter->detectRelevantToolServers($prompt);
            $requested = isset($config['available_tools']) && is_array($config['available_tools'])
                ? array_column($config['available_tools'], null, 'name') : null;
            $tools = [];
            $definitions = [];
            foreach ($catalog as $tool) {
                if (! is_string($tool['server'] ?? null) || ! is_string($tool['name'] ?? null)
                    || ($tool['requires_confirmation'] ?? false) === true
                    || ($filter !== [] && ! in_array($tool['server'], $filter, true))) {
                    continue;
                }
                if ($requested !== null && (! isset($requested[$tool['name']]) || ($requested[$tool['name']]['server'] ?? null) !== $tool['server'])) {
                    continue;
                }
                $name = $tool['server'].'_'.$tool['name'];
                if (isset($definitions[$name])) {
                    throw new RuntimeException('Ambiguous native tool identity.');
                }
                $definitions[$name] = $tool;
                $tools[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass]]];
            }
            $runId = (string) Str::uuid();
            $artifacts = new CliArtifactStore(storage_path('app/private/native-web-tools/'.$runId));
            $bindings = $pc['tokenizer_bindings_path'] ?? null;
            if (! is_string($bindings) || $bindings === '') {
                throw new RuntimeException('Local exact tokenizer binding is unavailable.');
            }
            if (! str_starts_with($bindings, '/')) {
                $bindings = base_path($bindings);
            }
            $counter = CliPromptCounter::fromBindingsFile(null, null, $artifacts, $bindings);
            $messages = [];
            $system = $config['system_prompt'] ?? $config['system'] ?? '';
            if ($system !== '') {
                $messages[] = ['role' => 'system', 'content' => $system];
            }
            $messages[] = ['role' => 'user', 'content' => $prompt];
            $conversation = new LosslessConversation($messages);
            $headers = $provider['extra_headers'] ?? [];
            if (($provider['api_key'] ?? '') !== '') {
                $headers['Authorization'] = 'Bearer '.$provider['api_key'];
            }
            $streamPolicy = $pc['stream_policy'] ?? [];
            $streamPolicy['_pool_execution_receipt'] = PoolExecutionReceipt::fromConfig($config);
            $streamPolicy['idle_timeout_seconds'] = $contract['idle_timeout_seconds'];
            $streamPolicy['absolute_timeout_seconds'] = $contract['absolute_timeout_seconds'];
            $streamPolicy['provider'] = $instanceId;
            if (isset($config['cancellation_run_id'])) {
                $streamPolicy['cancellation_run_id'] = $config['cancellation_run_id'];
            }
            yield json_encode(['type' => 'provider', 'provider' => $instanceId, 'model' => $model, 'tools_enabled' => true,
                'fallback_enabled' => false, 'receipt_id' => $runId], JSON_THROW_ON_ERROR)."\n";
            $result = $this->runRequiredNativeToolLoop($route, $conversation, $tools,
                fn ($r, $m, $t) => $this->openAICompatibleProgress->completeNativeToolTurn($provider['base_url'],
                    ['messages' => $m, ...$settings, ...($t === [] ? [] : ['tools' => $t, 'tool_choice' => 'auto'])], $headers, $streamPolicy, $instanceId,
                    fn ($kind, $bytes) => $artifacts->captureBytes($bytes)),
                $counter->count(...),
                function ($kind, $r, $action) use ($policy, $definitions): array {
                    if ($kind === 'model') {
                        return ['allowed' => true];
                    } // Transport revalidates exact controller admission every round.
                    $tool = $definitions[$action['name']] ?? null;
                    if ($tool === null) {
                        return ['allowed' => false, 'reason' => 'Tool not advertised'];
                    }
                    $decision = $policy->evaluateMcpTool($tool['server'], $tool['name'], $action['arguments']);

                    return ['allowed' => $decision->allowed && ! $decision->requiresConfirmation,
                        'reason' => $decision->requiresConfirmation ? 'Existing operator approval is required' : $decision->reason];
                },
                function ($action, $r) use ($router, $definitions, &$events): array {
                    $tool = $definitions[$action['name']];
                    $events[] = ['type' => 'tool_call', 'tool_call_id' => $action['id'], 'server' => $tool['server'], 'tool' => $tool['name']];
                    $value = $router->callTool($tool['server'], $tool['name'], $action['arguments']);
                    $outcome = app(MCPToolResultValidatorService::class)->outcome($value, true);
                    $events[] = ['type' => 'tool_result', 'tool_call_id' => $action['id'], 'server' => $tool['server'], 'tool' => $tool['name']];

                    return ['success' => $outcome['success'], 'output' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                },
                function ($phase, $action, $r, $result) use ($artifacts, $runId): bool {
                    $artifacts->captureBytes(json_encode(['run_id' => $runId, 'phase' => $phase, 'action' => $action, 'result' => $result], JSON_THROW_ON_ERROR));

                    return true;
                }, fn ($m) => $artifacts->captureBytes(json_encode(['run_id' => $runId, 'messages' => $m], JSON_THROW_ON_ERROR)));
            PoolExecutionReceipt::fromConfig($config)?->assertTerminalBeforeSuccess();
            foreach ($events as $event) {
                yield json_encode($event, JSON_THROW_ON_ERROR)."\n";
            }
            $events = [];
            yield json_encode(['type' => 'content', 'content' => $result['response']], JSON_THROW_ON_ERROR)."\n";
            yield json_encode(['type' => 'done', 'tool_calls' => $result['tool_calls'], 'receipt_id' => $runId], JSON_THROW_ON_ERROR)."\n";
        } catch (\Throwable $error) {
            $receipt = PoolExecutionReceipt::fromConfig($config);
            $receipt?->stopReplayAfterFailure($error);
            if ($receipt !== null && InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }
            foreach ($events as $event) {
                yield json_encode($event, JSON_THROW_ON_ERROR)."\n";
            }
            yield json_encode(['type' => 'error', 'content' => 'Required native tool turn stopped; inspect its private receipt. No retry or fallback was attempted.',
                'error_type' => get_class($error), 'fallback_suppressed' => true, 'receipt_id' => $runId ?? null], JSON_THROW_ON_ERROR)."\n";
        }
    }

    /** Reuse exact argument validation, no-replay journal and tool/iteration limits. */
    protected function runRequiredNativeToolLoop(array $route, LosslessConversation $conversation, array $tools,
        \Closure $transport, \Closure $counter, \Closure $authorize, \Closure $execute, \Closure $journal, \Closure $checkpoint): array
    {
        return (new PrimaryAgentLoop($transport, $counter, $authorize, $execute, $journal))->run($conversation, $route, $tools, $checkpoint);
    }

    /**
     * Stream one explicitly selected compatible provider or Codex connector. This
     * path deliberately disables tools and all fallback so an operator-selected
     * deep-offline model cannot silently route elsewhere.
     *
     * @param  array<string,mixed>  $config
     * @return \Generator<int,string>
     */
    private function acquireRequiredPoolReceipt(array $provider, string $model, ?string &$requestId): ?PoolExecutionReceipt
    {
        $requestId = null;
        if (! in_array($provider['instance_type'] ?? null, ['ollama', 'local_llm'], true)) {
            return null;
        }
        $this->poolManager ??= app(LLMPoolManagerService::class);
        $candidate = uniqid('required_provider_', true);
        if (! $this->poolManager->acquireBusyLock($provider['id'], $candidate)) {
            return null;
        }
        $requestId = $candidate;

        return $this->poolManager->executionReceipt($provider['id'], $requestId, $model);
    }

    private function streamRequiredOpenAICompatibleProvider(string $instanceId, string $prompt, array $config): \Generator
    {
        $provider = null;
        foreach ($this->buildFallbackChain(! empty($config['sensitive_data']), false, $config) as $candidate) {
            if (in_array($candidate['type'] ?? '', ['external_api', 'codex_exec'], true)
                && ($candidate['id'] ?? '') === $instanceId
            ) {
                $provider = $candidate;
                break;
            }
        }

        if ($provider === null) {
            yield json_encode([
                'type' => 'error',
                'content' => "Required provider {$instanceId} is unavailable or not admitted; fallback was suppressed",
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            return;
        }

        $rpmKey = 'rpm_count_'.$instanceId;
        $currentRpm = (int) Cache::get($rpmKey, 0);
        $rateLimitRpm = (int) ($provider['rate_limit_rpm'] ?? 0);
        if ($rateLimitRpm > 0 && $currentRpm >= $rateLimitRpm) {
            Cache::put('rate_limit_'.$instanceId, true, 60);
            yield json_encode([
                'type' => 'error',
                'content' => "Required provider {$instanceId} rate limit is exhausted; fallback was suppressed",
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            return;
        }
        if ($rateLimitRpm > 0) {
            Cache::put($rpmKey, $currentRpm + 1, 60);
        }

        if (($provider['type'] ?? '') === 'codex_exec') {
            if ($this->registeredStructuredOutputSchemaId($config) !== null) {
                yield json_encode(['type' => 'error', 'content' => 'Codex route is not admitted for registered PLOS structured output'])."\n";

                return;
            }
            $result = $this->tryCodexExec($prompt, $config);
            yield json_encode(['type' => 'provider', 'provider' => $instanceId,
                'model' => $result['model'] ?? $config['model_override'] ?? $config['model'] ?? null], JSON_THROW_ON_ERROR)."\n";
            if (is_string($result['response'] ?? null) && $result['response'] !== '') {
                yield json_encode(['type' => 'content', 'content' => $result['response']], JSON_THROW_ON_ERROR)."\n";
            }
            yield json_encode(($result['success'] ?? false)
                ? ['type' => 'done', 'telemetry' => $result['usage'] ?? []]
                : ['type' => 'error', 'content' => $result['error'] ?? 'Required Codex provider failed; fallback was suppressed'], JSON_THROW_ON_ERROR)."\n";

            return;
        }

        $providerConfig = is_array($provider['config'] ?? null) ? $provider['config'] : [];
        $slotPolicy = is_array($providerConfig['slot_policy'] ?? null) ? $providerConfig['slot_policy'] : [];
        $sharedAdmissionGroup = trim((string) ($slotPolicy['admission_group'] ?? ''));
        $role = (string) ($config['model_role'] ?? 'deep_offline');
        $model = (string) ($config['model_override']
            ?? $providerConfig['models'][$role]
            ?? $provider['default_model']
            ?? $provider['supported_models'][0]
            ?? '');
        if ($model === '') {
            yield json_encode(['type' => 'error', 'content' => "Required provider {$instanceId} has no admitted model"])."\n";

            return;
        }

        if (($providerConfig['cli_agent_runtime']['native_tools_enabled'] ?? false) === true) {
            $nativeRequestId = null;
            $nativeReceipt = $this->acquireRequiredPoolReceipt($provider, $model, $nativeRequestId);
            if (in_array($provider['instance_type'] ?? null, ['ollama', 'local_llm'], true) && $nativeReceipt === null) {
                yield json_encode(['type' => 'error', 'content' => 'Required native provider admission is busy; fallback was suppressed'], JSON_THROW_ON_ERROR)."\n";

                return;
            }
            if ($nativeReceipt !== null) {
                $config['_pool_execution_receipt'] = $nativeReceipt;
            }
            $terminalStop = null;
            try {
                yield from $this->streamRequiredNativeTools($provider, $model, $role, $prompt, $config);
            } catch (\Throwable $error) {
                if (InferenceAdmissionException::isAdmissionFailure($error)) {
                    $terminalStop = $error;
                }
                throw $error;
            } finally {
                try {
                    if ($nativeRequestId !== null && ! $this->poolManager->releaseBusyLock($instanceId, $nativeRequestId)) {
                        throw new InferenceAdmissionException('Required native pool release owner is unavailable; custody retained.');
                    }
                } catch (\Throwable $cleanupError) {
                    throw $terminalStop ?? $cleanupError;
                }
            }

            return;
        }

        $messages = [];
        $systemPrompt = trim((string) ($config['system_prompt'] ?? $config['system'] ?? ''));
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];
        $headers = is_array($provider['extra_headers'] ?? null) ? $provider['extra_headers'] : [];
        if ((string) ($provider['api_key'] ?? '') !== '') {
            $headers['Authorization'] = 'Bearer '.$provider['api_key'];
        }
        $streamPolicy = is_array($providerConfig['stream_policy'] ?? null) ? $providerConfig['stream_policy'] : [];
        $streamPolicy['provider'] = $instanceId;
        if (is_string($config['cancellation_run_id'] ?? null) && trim($config['cancellation_run_id']) !== '') {
            $streamPolicy['cancellation_run_id'] = trim($config['cancellation_run_id']);
        }
        if (is_numeric($config['max_sentences'] ?? null)) {
            $streamPolicy['max_sentences'] = max(1, min(20, (int) $config['max_sentences']));
        }
        $providerMaxTokens = max(1, (int) ($providerConfig['max_tokens'] ?? 256));
        $requestedMaxTokens = max(1, (int) ($config['max_tokens'] ?? $providerMaxTokens));
        $schemaId = $this->registeredStructuredOutputSchemaId($config);
        $responseFormat = null;
        $customGrammar = null;
        if ($schemaId !== null) {
            $structuredOutputProbe = $this->isExactStructuredOutputEvalProbe($config, 'provider');
            $evaluatorValidatedKimiK3Probe = $structuredOutputProbe
                && $this->isEvaluatorValidatedKimiK3Probe($config);
            if (! $structuredOutputProbe) {
                $capability = $this->providerModelCapabilities->resolve($provider, $model, 'structured_output');
                if (! $capability['supported']) {
                    yield json_encode([
                        'type' => 'error',
                        'content' => "Required provider {$instanceId} structured output is not admitted: {$capability['reason']}",
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

                    return;
                }
            }

            if (! $evaluatorValidatedKimiK3Probe) {
                $schemaService = app(OllamaStructuredOutputSchemaService::class);
                $taskClass = trim((string) ($config['eval_task_class'] ?? ''));
                $customGrammar = $structuredOutputProbe && $requestedMaxTokens <= 128
                    ? $schemaService->boundedEvalGrammar($schemaId, $taskClass)
                    : null;
                $schema = $structuredOutputProbe && $requestedMaxTokens <= 128
                    ? $schemaService->boundedEvalProjection(
                        $schemaId,
                        $taskClass
                    )
                    : $schemaService->resolve($schemaId);
                if ($customGrammar === null) {
                    $responseFormat = [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => $schemaId,
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ];
                }
            }
        }
        $terminalStop = null;
        $admissionRequestId = null;
        $poolReceipt = $this->acquireRequiredPoolReceipt($provider, $model, $admissionRequestId);
        if (in_array($provider['instance_type'] ?? null, ['ollama', 'local_llm'], true) && $poolReceipt === null) {
            yield json_encode(['type' => 'error', 'content' => "Required provider {$instanceId} admission is busy; fallback was suppressed"], JSON_THROW_ON_ERROR)."\n";

            return;
        }
        if ($poolReceipt !== null) {
            $streamPolicy['_pool_execution_receipt'] = $poolReceipt;
        }
        $startedAt = microtime(true);
        $contentCommitted = false;

        try {
            yield json_encode([
                'type' => 'provider',
                'provider' => $instanceId,
                'model' => $model,
                'tools_enabled' => false,
                'fallback_enabled' => false,
                'admission_group' => $sharedAdmissionGroup !== '' ? $sharedAdmissionGroup : null,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            $payload = ['messages' => $messages, ...self::openAICompatibleTextPayload(
                $config, $providerConfig, $model, $responseFormat, $customGrammar)];

            foreach ($this->openAICompatibleProgress->streamChat(
                (string) $provider['base_url'],
                $payload,
                $headers,
                $streamPolicy
            ) as $chunk) {
                if (array_key_exists('queue_wait_ms', $chunk)) {
                    yield json_encode([
                        'type' => 'progress',
                        'state' => 'generating',
                        'queue_wait_ms' => $chunk['queue_wait_ms'],
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                }
                $content = (string) ($chunk['content'] ?? '');
                if ($content !== '') {
                    $contentCommitted = true;
                    yield json_encode(['type' => 'content', 'content' => $content], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                }
                if (($chunk['done'] ?? false) === true) {
                    $poolReceipt?->assertTerminalBeforeSuccess();
                    $telemetry = is_array($chunk['telemetry'] ?? null) ? $chunk['telemetry'] : [];
                    $this->recordSuccess($instanceId, microtime(true) - $startedAt, array_merge(
                        $this->modelTelemetry($config, $model),
                        [
                            'tokens_per_second' => $telemetry['decode_tokens_per_second'] ?? null,
                            'input_tokens' => $telemetry['prompt_tokens'] ?? null,
                            'output_tokens' => $telemetry['completion_tokens'] ?? null,
                            'queue_wait_ms' => $telemetry['queue_wait_ms'] ?? null,
                            'time_to_first_content_ms' => $telemetry['time_to_first_content_ms'] ?? null,
                            'response_bytes' => $telemetry['downloaded_bytes'] ?? null,
                            'transport_chunk_count' => $telemetry['chunk_count'] ?? null,
                            'finish_reason' => $telemetry['finish_reason'] ?? null,
                        ]
                    ));
                    yield json_encode([
                        'type' => 'done',
                        'telemetry' => $telemetry,
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                }
            }
        } catch (ChatStreamCancelledException $e) {
            Log::info('AIService: Required monitored OpenAI-compatible stream cancelled', [
                'provider' => $instanceId,
                'partial_content_preserved' => $contentCommitted,
                'fallback_suppressed' => true,
            ]);
            yield json_encode([
                'type' => 'stream_interrupted',
                'message' => $contentCommitted
                    ? 'Response cancelled by the operator. Partial output was saved.'
                    : 'Response cancelled by the operator before content was produced.',
                'operator_cancelled' => true,
                'partial_content_preserved' => $contentCommitted,
                'fallback_suppressed' => true,
                'commit_kind' => $contentCommitted ? 'content' : null,
                'correlation_id' => substr(uniqid('colibri_cancel_', true), 0, 32),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (\Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                $terminalStop = $e;
                throw $e;
            }
            try {
                $poolReceipt?->stopReplayAfterFailure($e);
            } catch (\Throwable $stop) {
                $terminalStop = $stop;
                throw $stop;
            }
            $this->recordFailure($instanceId, $e->getMessage(), null, $this->modelTelemetry($config, $model));
            Log::warning('AIService: Required monitored OpenAI-compatible stream failed', [
                'provider' => $instanceId,
                'error' => $e->getMessage(),
                'fallback_suppressed' => true,
            ]);
            if ($contentCommitted) {
                yield json_encode([
                    'type' => 'stream_interrupted',
                    'message' => 'Required provider response was interrupted. Partial output was preserved and fallback was suppressed.',
                    'partial_content_preserved' => true,
                    'fallback_suppressed' => true,
                    'commit_kind' => 'content',
                    'correlation_id' => substr(uniqid('colibri_', true), 0, 24),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            } else {
                yield json_encode([
                    'type' => 'error',
                    'content' => "Required provider {$instanceId} failed; fallback was suppressed: ".$e->getMessage(),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            }
        } finally {
            try {
                if ($admissionRequestId !== null && $this->poolManager !== null) {
                    $this->poolManager->releaseBusyLock($instanceId, $admissionRequestId);
                }
            } catch (\Throwable $cleanupError) {
                throw $terminalStop ?? $cleanupError;
            }
        }
    }

    /**
     * Use the same monitored SSE transport for non-streaming explicit callers,
     * including offline:dev-assist. This preserves patient idle handling and
     * exact-provider semantics without exposing transport chunks to the caller.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function processRequiredOpenAICompatibleProvider(string $instanceId, string $prompt, array $config): array
    {
        $content = '';
        $model = (string) ($config['model_override'] ?? $config['model'] ?? '');
        $error = null;
        $completed = false;
        $telemetry = [];

        foreach ($this->streamRequiredOpenAICompatibleProvider($instanceId, $prompt, $config) as $line) {
            $event = json_decode(trim($line), true);
            if (! is_array($event)) {
                continue;
            }
            if (($event['type'] ?? null) === 'provider') {
                $model = (string) ($event['model'] ?? $model);
            } elseif (($event['type'] ?? null) === 'content') {
                $content .= (string) ($event['content'] ?? '');
            } elseif (($event['type'] ?? null) === 'done') {
                $completed = $error === null;
                $telemetry = is_array($event['telemetry'] ?? null) ? $event['telemetry'] : [];
            } elseif (($event['type'] ?? null) === 'error') {
                $completed = false;
                $error = (string) ($event['content'] ?? 'Required provider failed');
            } elseif (($event['type'] ?? null) === 'stream_interrupted') {
                $completed = false;
                $error = (string) ($event['message'] ?? 'Required provider response was interrupted');
            }
        }

        return [
            'success' => $completed,
            'response' => $content !== '' ? $content : null,
            'provider' => $instanceId,
            'model' => $model !== '' ? $model : null,
            'error' => $completed ? null : ($error ?? "Required AI provider {$instanceId} failed; fallback was suppressed"),
            'fallback_suppressed' => true,
            'partial_content_preserved' => ! $completed && $content !== '',
            'transport_telemetry' => $telemetry,
        ];
    }

    /**
     * Enforce optional per-provider table route policy from llm_instances.config.
     *
     * This is intentionally generic rather than BitNet-specific. A local sidecar
     * can be routable/healthy while still refusing generic roles unless the DB
     * row lists the role and the caller marks the payload appropriately.
     *
     * Supported config shapes:
     *   route_policy.allowed_model_roles: ["privacy_deny_prefilter"]
     *   route_policy.require_model_role: true
     *   route_policy.require_redacted_input: true
     *   route_policy.allow_generic_fallback: false
     *
     * @param  array<string,mixed>  $provider
     * @param  array<string,mixed>  $requestConfig
     */
    private function providerRoutePolicyAllowsRequest(array $provider, array $requestConfig): bool
    {
        $providerConfig = is_array($provider['config'] ?? null) ? $provider['config'] : [];
        $routePolicy = is_array($providerConfig['route_policy'] ?? null) ? $providerConfig['route_policy'] : [];
        $role = (string) ($requestConfig['model_role'] ?? 'standard');

        if (! $this->providerConfigAllowsModelRole(
            $providerConfig,
            $role,
            $this->truthyDbValue($requestConfig['require_model_role'] ?? false)
        )) {
            return false;
        }

        $redactedRoles = $routePolicy['redacted_input_roles'] ?? null;
        if ($redactedRoles !== null && (! is_array($redactedRoles) || ! array_is_list($redactedRoles)
            || array_filter($redactedRoles, static fn ($value): bool => ! is_string($value) || $value === '') !== [])) {
            return false;
        }
        if ($this->truthyDbValue($routePolicy['require_redacted_input'] ?? false)
            && ($redactedRoles === null || in_array($role, $redactedRoles, true))
            && ! $this->truthyDbValue($requestConfig['redacted_input'] ?? false)
        ) {
            return false;
        }

        if ($this->truthyDbValue($routePolicy['require_explicit_instance'] ?? false)
            && (string) ($requestConfig['required_instance_id'] ?? '') !== (string) ($provider['id'] ?? '')
        ) {
            return false;
        }

        $maxPromptChars = (int) ($routePolicy['max_prompt_chars'] ?? 0);
        if ($maxPromptChars > 0 && (int) ($requestConfig['_prompt_chars'] ?? 0) > $maxPromptChars) {
            return false;
        }

        return true;
    }

    /**
     * Apply table-owned Ollama runtime options for a specific host and role.
     * Example llm_instances.config shape:
     *   role_runtime.standard.think = false
     *   role_runtime.standard.options.num_ctx = 4096
     *
     * Caller-supplied request options still win over table defaults.
     *
     * @param  array<string,mixed>  $requestConfig
     * @param  array<string,mixed>  $instance
     * @return array<string,mixed>
     */
    private function applyOllamaInstanceRuntimeConfig(array $requestConfig, array $instance, string $role): array
    {
        $providerConfig = is_array($instance['config'] ?? null) ? $instance['config'] : [];
        $roleRuntime = is_array($providerConfig['role_runtime'] ?? null) ? $providerConfig['role_runtime'] : [];
        $runtime = is_array($roleRuntime[$role] ?? null) ? $roleRuntime[$role] : [];
        $maxNumCtx = (int) ($providerConfig['route_policy']['max_num_ctx'] ?? 0);

        // Resolve caller-owned output settings before role defaults erase their origin.
        // An explicit provider option remains authoritative when max_tokens is absent.
        $callerOptions = is_array($requestConfig['ollama_options'] ?? null) ? $requestConfig['ollama_options'] : [];
        if (array_key_exists('num_predict', $callerOptions)
            || array_key_exists('num_predict', is_array($runtime['options'] ?? null) ? $runtime['options'] : [])) {
            $budgets = [];
            foreach (['max_tokens' => $requestConfig, 'num_predict' => $callerOptions] as $key => $source) {
                if (! array_key_exists($key, $source)) {
                    continue;
                }
                $value = $source[$key];
                $budget = (is_int($value) || is_string($value))
                    ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                if ($budget === false) {
                    throw new InferenceAdmissionException('Explicit Ollama output budgets must be positive integers.');
                }
                $budgets[$key] = $budget;
            }
            if (isset($budgets['max_tokens'], $budgets['num_predict']) && $budgets['max_tokens'] !== $budgets['num_predict']) {
                throw new InferenceAdmissionException('Explicit max_tokens and Ollama num_predict conflict.');
            }
            if ($budgets !== []) {
                $requestConfig['max_tokens'] = $budgets['max_tokens'] ?? $budgets['num_predict'];
                $requestConfig['ollama_options'] = $callerOptions;
                $requestConfig['ollama_options']['num_predict'] = $requestConfig['max_tokens'];
            }
        }

        if ($runtime === [] && $maxNumCtx <= 0) {
            return $requestConfig;
        }

        $merged = $requestConfig;

        if (array_key_exists('think', $runtime) && ! array_key_exists('ollama_think', $merged)) {
            $merged['ollama_think'] = $this->truthyDbValue($runtime['think']);
        }

        if (array_key_exists('keep_alive', $runtime) && ! array_key_exists('ollama_keep_alive', $merged)) {
            $merged['ollama_keep_alive'] = $runtime['keep_alive'];
        }

        if (isset($runtime['options']) && is_array($runtime['options'])) {
            $merged['ollama_options'] = array_merge(
                $runtime['options'],
                is_array($merged['ollama_options'] ?? null) ? $merged['ollama_options'] : []
            );
        }

        if (isset($runtime['prompt_prefix']) && is_string($runtime['prompt_prefix']) && ! array_key_exists('ollama_prompt_prefix', $merged)) {
            $merged['ollama_prompt_prefix'] = $runtime['prompt_prefix'];
        }

        foreach (['num_ctx', 'temperature', 'max_tokens', 'ai_timeout'] as $key) {
            if (array_key_exists($key, $runtime) && ! array_key_exists($key, $merged)) {
                $merged[$key] = $runtime[$key];
            }
        }

        if ($maxNumCtx > 0) {
            $effective = $maxNumCtx;
            foreach ([$merged['num_ctx'] ?? null, $merged['ollama_options']['num_ctx'] ?? null] as $requested) {
                if ($requested !== null) {
                    $effective = max(1, min($effective, (int) $requested));
                }
            }
            $merged['num_ctx'] = $effective;
            $merged['ollama_options']['num_ctx'] = $effective;
        }

        return $merged;
    }

    /** Use the same active routing policy during admission and actual dispatch. */
    private function applyOllamaRoutingPolicy(array $requestConfig, array $routingPolicy): array
    {
        $patientLocal = $this->truthyDbValue($routingPolicy['patient_local_capacity'] ?? false);
        $requestConfig['skip_if_busy'] = $requestConfig['skip_if_busy'] ?? ! $patientLocal;
        if ($patientLocal) {
            $requestConfig['ollama_lock_wait_seconds'] = max(1, (int) ($routingPolicy['local_lock_wait_seconds'] ?? 600));
            $requestConfig['ai_timeout'] = max(
                (int) ($requestConfig['ai_timeout'] ?? 0),
                (int) ($routingPolicy['minimum_local_timeout_seconds'] ?? 300)
            );
            $requestConfig['_allow_ai_timeout_extension'] = true;
            $requestConfig = $this->applyPatientOllamaProgressPolicy($requestConfig, $routingPolicy);
        }

        return $requestConfig;
    }

    /**
     * @param  array<string,mixed>  $requestConfig
     * @param  array<string,mixed>  $routingPolicy
     * @return array<string,mixed>
     */
    private function applyPatientOllamaProgressPolicy(array $requestConfig, array $routingPolicy): array
    {
        $requestConfig['_monitor_ollama_progress'] = $this->truthyDbValue(
            $routingPolicy['monitor_ollama_progress'] ?? true
        );
        $requestConfig['ollama_progress_idle_timeout_seconds'] = max(
            30,
            (int) ($routingPolicy['ollama_progress_idle_timeout_seconds'] ?? 600),
            (int) ($requestConfig['ai_timeout'] ?? 0)
        );
        $requestConfig['ollama_absolute_timeout_seconds'] = max(
            0,
            (int) ($routingPolicy['ollama_absolute_timeout_seconds'] ?? 0)
        );
        $requestConfig['ollama_progress_log_interval_seconds'] = max(
            5,
            (int) ($routingPolicy['ollama_progress_log_interval_seconds'] ?? 30)
        );

        return $requestConfig;
    }

    /** @param array<string,mixed> $requestConfig @return array<string,mixed> */
    private function alignOllamaProgressIdleTimeout(array $requestConfig, int $resolvedModelTimeout): array
    {
        if ($this->truthyDbValue($requestConfig['_monitor_ollama_progress'] ?? false)) {
            $requestConfig['ollama_progress_idle_timeout_seconds'] = max(
                (int) ($requestConfig['ollama_progress_idle_timeout_seconds'] ?? 0),
                $resolvedModelTimeout
            );
        }

        return $requestConfig;
    }

    /**
     * Enforce table-owned role policy from an llm_instances.config payload.
     *
     * @param  array<string,mixed>  $providerConfig
     */
    private function providerConfigAllowsModelRole(
        array $providerConfig,
        string $role,
        bool $requestRequiresModelRole = false
    ): bool {
        $routePolicy = is_array($providerConfig['route_policy'] ?? null) ? $providerConfig['route_policy'] : [];
        $allowedRoles = $routePolicy['allowed_model_roles']
            ?? $providerConfig['allowed_model_roles']
            ?? null;

        if (is_array($allowedRoles) && $allowedRoles !== []) {
            $allowedRoles = array_values(array_filter($allowedRoles, 'is_string'));
            if (! in_array($role, $allowedRoles, true)) {
                return false;
            }
        }

        $models = is_array($providerConfig['models'] ?? null) ? $providerConfig['models'] : [];
        $hasRoleModel = is_string($models[$role] ?? null) && trim((string) $models[$role]) !== '';
        $requiresRoleModel = $requestRequiresModelRole
            || $this->truthyDbValue($routePolicy['require_model_role'] ?? false)
            || ! $this->truthyDbValue($routePolicy['allow_generic_fallback'] ?? true);

        if ($requiresRoleModel && ! $hasRoleModel) {
            return false;
        }

        return true;
    }

    private function getCodexExecFallbackProvider(): ?array
    {
        try {
            $row = DB::selectOne("
                SELECT *
                FROM llm_instances
                WHERE instance_id = 'codex_exec'
                  AND is_active = 1
                  AND is_healthy = 1
                  AND routability = 'allowed'
                LIMIT 1
            ");

            if (! $row) {
                return null;
            }

            if ((string) ($row->quarantine_status ?? 'none') === 'quarantined') {
                return null;
            }

            $capabilities = json_decode($row->capabilities ?? '[]', true) ?: [];
            $config = json_decode($row->config ?? '{}', true) ?: [];
            $allowsPrivateData = $this->rowAllowsPrivateData($row, $capabilities, $config);

            return [
                'id' => $row->instance_id,
                'name' => $row->instance_name,
                'instance_type' => $row->instance_type,
                'data_privacy_scope' => $row->data_privacy_scope ?? null,
                'allows_private_data' => $allowsPrivateData,
                'priority' => (int) ($row->priority ?? 18),
                'rate_limit_rpm' => $row->rate_limit_rpm,
                'rate_limit_tpm' => $row->rate_limit_tpm,
                'max_concurrent' => $row->max_concurrent,
                'circuit_state' => $row->circuit_state,
                'sensitive_safe' => $allowsPrivateData,
                'capabilities' => $capabilities,
                'config' => $config,
                'avg_response_ms' => (float) ($row->avg_response_ms ?? 30000),
                'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
                'success_rate' => (float) ($row->success_rate ?? 80),
            ];
        } catch (\Throwable $e) {
            Log::debug('AIService: codex_exec fallback provider lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function isCodexExecEnabled(): bool
    {
        if ($this->isOfflineMode()) {
            return false;
        }

        try {
            $row = DB::selectOne("SELECT * FROM llm_instances WHERE instance_id = 'codex_exec'");

            return (bool) ($row->is_active ?? false)
                && (bool) ($row->is_healthy ?? false)
                && (string) ($row->routability ?? 'blocked') === 'allowed'
                && (string) ($row->quarantine_status ?? 'none') !== 'quarantined';
        } catch (\Throwable $e) {
            Log::debug('AIService: codex_exec activation lookup failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Is PLOS running in offline-only mode?
     *
     * "Offline" here means INTERNET offline, not LAN offline. The kill switch
     * blocks external/cloud LLM providers only:
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
     *   - 'enabled'            → block all external providers
     *   - any other value      → treated as enabled (safe default)
     */
    public function isOfflineMode(): bool
    {
        try {
            $svc = app(SystemConfigService::class);
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
     *   4. default → return null (no restriction).
     *   5. unknown/error → fail closed to local_llm only.
     *
     * R3 (2026-04-19 defect fix): previously only (1) + (2) existed, so
     * hybrid_review / hybrid_dev_assist / cloud_escalation_only all fell
     * through to null (no restriction) — widening silently. The config-
     * synthesis path now covers every rung the routing:profile command
     * accepts.
     *
     * @return array<string,mixed>|null null only when default
     */
    public function getActiveProfile(): ?array
    {
        try {
            $svc = app(SystemConfigService::class);
            $active = $svc->get('routing.active_profile', 'default');

            if (! is_string($active)) {
                return $this->failClosedLocalProfile('invalid_profile_type', 'system_configs:routing.active_profile:invalid_type');
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
            Log::warning('AIService: active profile lookup failed, failing closed to local-only profile', [
                'error' => $e->getMessage(),
            ]);

            return $this->failClosedLocalProfile('lookup_exception', 'system_configs:routing.active_profile:lookup_exception');
        }
    }

    /**
     * R3 + Defect A fix: translate a config/offline_policy.php profile row
     * into the legacy {allowed_instance_types, allowed_capabilities,
     * allowed_provider_classes, description} shape that buildFallbackChain()
     * + LLMPoolManagerService consume.
     *
     * Important: `instance_type` alone is NOT authoritative for privacy
     * posture. `custom` covers providers with mixed table privacy settings
     * (OpenRouter, Groq, SambaNova, etc.). The coarse allowed_instance_types
     * list lets `custom` through under cloud_sensitive_safe profiles, and
     * then buildFallbackChain applies a per-provider allows_private_data filter
     * via the `allowed_provider_classes` field we preserve below. Tests in
     * AIServiceFallbackChainSensitiveSafeTest exercise this real behavior.
     *
     * Provider-class → instance_type coarse admission:
     *   local_llm             → ollama, local_llm
     *   cloud_sensitive_safe  → codex_cli, custom
     *                           (custom admission is coarse — per-provider
     *                           allows_private_data filter applies in
     *                           buildFallbackChain)
     *   cloud_external        → openai, google_gemini, openrouter, azure_openai, custom
     */
    private function synthesizeLegacyProfileFromOfflinePolicy(string $profileName): ?array
    {
        $profile = config('offline_policy.profiles.'.$profileName);
        if (! is_array($profile)) {
            Log::warning('AIService: unknown routing profile; failing closed to local-only profile', [
                'profile' => $profileName,
            ]);

            return $this->failClosedLocalProfile($profileName, 'config/offline_policy.php:fail_closed_unknown_profile');
        }

        $providerClasses = (array) ($profile['allowed_provider_classes'] ?? []);

        $classToTypes = [
            'local_llm' => ['ollama', 'local_llm'],
            'cloud_sensitive_safe' => ['codex_cli', 'custom'],
            'cloud_external' => ['openai', 'google_gemini', 'openrouter', 'azure_openai'],
        ];

        $allowedTypes = [];
        foreach ($providerClasses as $class) {
            foreach ($classToTypes[$class] ?? [] as $type) {
                $allowedTypes[] = $type;
            }
        }

        $legacy = [
            'allowed_instance_types' => array_values(array_unique($allowedTypes)),
            'allowed_capabilities' => ['text'],
            'allowed_provider_classes' => $providerClasses,
            'description' => (string) ($profile['description'] ?? ''),
            'source' => 'config/offline_policy.php',
        ];

        if (is_array($profile['llm_routing'] ?? null)) {
            $legacy['llm_routing'] = $profile['llm_routing'];
        }

        return $legacy;
    }

    /** @return array<string,mixed> */
    private function activeLlmRoutingPolicy(): array
    {
        if ($this->isOfflineMode()) {
            return [];
        }

        $profile = $this->getActiveProfile();

        return is_array($profile['llm_routing'] ?? null) ? $profile['llm_routing'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function failClosedLocalProfile(string $profileName, string $source): array
    {
        return [
            'allowed_instance_types' => ['ollama', 'local_llm'],
            'allowed_capabilities' => ['text'],
            'allowed_provider_classes' => ['local_llm'],
            'description' => "Profile '{$profileName}' failed closed to local_llm only.",
            'source' => $source,
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
    private function getExternalApiProviders(string $requiredInstanceId = ''): array
    {
        $managedRequiredProvider = $requiredInstanceId !== '';
        $cacheKey = self::EXTERNAL_PROVIDER_CACHE_KEY;
        // Exact selection precedes supervisor admission on normal required-provider calls.
        // Read fresh gates without putting cold candidates in the shared health cache.
        $cached = $managedRequiredProvider ? null : Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $apiTypes = ['openai', 'google_gemini', 'azure_openai', 'custom', 'local_llm'];
            $placeholders = implode(',', array_fill(0, count($apiTypes), '?'));
            $healthClause = $managedRequiredProvider
                ? "(is_healthy = 1 OR (instance_type = 'local_llm' AND instance_id = ?))"
                : 'is_healthy = 1';
            $bindings = $managedRequiredProvider ? [$requiredInstanceId, ...$apiTypes] : $apiTypes;
            $rows = DB::select("
                SELECT *
                FROM llm_instances
                WHERE is_active = 1
                  AND {$healthClause}
                  AND routability = 'allowed'
                  AND instance_type IN ({$placeholders})
                ORDER BY priority ASC
            ", $bindings);

            $providers = [];
            foreach ($rows as $row) {
                if ((string) ($row->quarantine_status ?? 'none') === 'quarantined' || ($row->compat_status ?? null) === 'stale') {
                    continue;
                }

                // API key: DB column first, .env fallback. Local sidecars such
                // as llama-server are OpenAI-compatible but intentionally do
                // not require an API key.
                $apiKey = $row->api_key ?: $this->resolveRuntimeEnvValue($row->api_key_env ?? null);
                if (! $apiKey && $row->instance_type !== 'local_llm') {
                    continue; // Skip unconfigured providers (no key yet)
                }

                $config = json_decode($row->config ?? '{}', true) ?: [];
                if ($managedRequiredProvider && ! (bool) ($row->is_healthy ?? false)
                    && ((string) $row->instance_id !== $requiredInstanceId
                        || (string) $row->instance_type !== 'local_llm'
                        || ($config['route_policy']['require_cached_health'] ?? true) !== false
                        || (! self::hasManagedInferenceContract() && ! $this->isRegisteredColdControllerProvider($row, $config)))
                ) {
                    continue;
                }
                $capabilities = json_decode($row->capabilities ?? '{}', true) ?: [];
                $allowsPrivateData = $this->rowAllowsPrivateData($row, $capabilities, $config);

                $providers[] = [
                    'id' => $row->instance_id,
                    'name' => $row->instance_name,
                    'instance_type' => $row->instance_type,
                    'data_privacy_scope' => $row->data_privacy_scope ?? null,
                    'allows_private_data' => $allowsPrivateData,
                    'base_url' => $row->base_url,
                    'api_key' => $apiKey ?: '',
                    'priority' => $row->priority,
                    'default_model' => $config['default_model'] ?? null,
                    'supported_models' => json_decode($row->supported_models ?? '[]', true) ?: [],
                    'rate_limit_rpm' => $row->rate_limit_rpm,
                    'rate_limit_tpm' => $row->rate_limit_tpm,
                    'max_concurrent' => $row->max_concurrent,
                    'circuit_state' => $row->circuit_state,
                    'sensitive_safe' => $allowsPrivateData,
                    'capabilities' => $capabilities,
                    'extra_headers' => $config['extra_headers'] ?? [],
                    'config' => $config,
                    'embedding_context_length' => $row->embedding_context_length ?? null,
                    'avg_response_ms' => (float) ($row->avg_response_ms ?? 5000),
                    'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
                    'success_rate' => (float) ($row->success_rate ?? 50),
                ];
            }

            if (! $managedRequiredProvider) {
                Cache::put($cacheKey, $providers, 60);
            }

            return $providers;

        } catch (Exception $e) {
            Log::warning('AIService: Failed to load external providers', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** Selection only: the transport supervisor still validates and acquires exact custody before HTTP. */
    private function isRegisteredColdControllerProvider(object $row, array $config): bool
    {
        $targets = $config['controller_catalog']['targets'] ?? [];
        if (! is_array($targets) || $targets === []) {
            return false;
        }
        $registered = app(ModelHostControllerResourcePolicyService::class)->managedProvider((string) $row->base_url);
        if ($registered === null || (string) $registered->instance_id !== (string) $row->instance_id) {
            return false;
        }
        foreach ($targets as $targetId => $target) {
            if (is_array($target) && ($target['target_id'] ?? null) === $targetId
                && ($target['provider_instance_id'] ?? null) === $row->instance_id
                && is_string($target['controller_host_id'] ?? null) && $target['controller_host_id'] !== ''
                && is_string($target['engine'] ?? null) && $target['engine'] !== ''
                && is_int($target['port'] ?? null) && parse_url($row->base_url, PHP_URL_PORT) === $target['port']
                && ($target['activation_allowed'] ?? false) === true) {
                return true;
            }
        }

        return false;
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

            $schemaId = $this->registeredStructuredOutputSchemaId($config);
            $structuredCapability = null;
            if ($schemaId !== null) {
                $structuredCapability = $this->providerModelCapabilities->resolve(
                    $provider,
                    (string) $model,
                    'structured_output'
                );
                if (! $structuredCapability['supported']) {
                    return [
                        'success' => false,
                        'error' => "Structured output route denied for {$provider['name']}: {$structuredCapability['reason']}",
                    ];
                }
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
            if ($schemaId !== null) {
                $providerConfig['structured_output_schema_id'] = $schemaId;
                $providerConfig['structured_output_transport'] = $structuredCapability['transport'];
            }

            $response = $this->aiRouter->processWithAI($prompt, $providerConfig);

            // Track rate limit
            if ($provider['rate_limit_rpm']) {
                Cache::put($rpmKey, $currentRpm + 1, 60);
            }

            $this->recordSuccess($providerId, microtime(true) - $startTime, $this->modelTelemetry($config, $model));

            return [
                'success' => true,
                'response' => $response,
                'provider' => $providerId,
                'provider_name' => $provider['name'],
                'model' => $model,
                'error' => null,
            ];

        } catch (Exception $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            // Extract Retry-After for rate limit errors — set cache AND circuit to provider's window
            $retryAfterSeconds = null;
            if ($e instanceof RateLimitException) {
                $retryAfterSeconds = $this->circuitBreakerPolicy
                    ->retryAfterSecondsFromBackoffMilliseconds($e->getSuggestedBackoffMs());
            } elseif (str_contains($e->getMessage(), 'Rate limit') || str_contains($e->getMessage(), '429')) {
                $retryAfterSeconds = $this->retryAfterSecondsFromProviderError($e->getMessage());
            }

            // Set rate_limit_ cache before recording failure so buildFallbackChain() skips immediately
            if ($retryAfterSeconds !== null) {
                Cache::put("rate_limit_{$providerId}", true, $retryAfterSeconds);
            }

            $this->recordFailure($providerId, $e->getMessage(), $retryAfterSeconds, $this->modelTelemetry($config, $model ?? null));

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
            $exactModel = ! empty($config['required_instance_id']) ? ($config['model_override'] ?? $config['model'] ?? null) : null;
            if ($exactModel !== null && ! in_array($exactModel, $provider['supported_models'] ?? [], true)) {
                return ['success' => false, 'error' => 'Selected vision model is not advertised by this provider.'];
            }
            $model = $exactModel ?? $providerConfig['vision_model']
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

            $duration = microtime(true) - $startTime;
            $this->recordSuccess($providerId.'_vision', $duration);
            $this->recordModelAttemptTelemetry($providerId, true, $duration, $this->modelTelemetry(
                $config,
                $model,
                ['request_kind' => 'vision']
            ));

            return [
                'success' => true,
                'response' => $response,
                'provider' => $providerId,
                'provider_name' => $provider['name'],
                'model' => $model,
                'error' => null,
            ];

        } catch (Exception $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            // Extract Retry-After for rate limit errors
            $retryAfterSeconds = null;
            if ($e instanceof RateLimitException) {
                $retryAfterSeconds = $this->circuitBreakerPolicy
                    ->retryAfterSecondsFromBackoffMilliseconds($e->getSuggestedBackoffMs());
            } elseif (str_contains($e->getMessage(), 'Rate limit') || str_contains($e->getMessage(), '429')) {
                $retryAfterSeconds = $this->retryAfterSecondsFromProviderError($e->getMessage());
            }

            if ($retryAfterSeconds !== null) {
                Cache::put("rate_limit_{$providerId}", true, $retryAfterSeconds);
            }

            $this->recordFailure($providerId.'_vision', $e->getMessage(), $retryAfterSeconds);
            $this->recordModelAttemptTelemetry($providerId, false, null, $this->modelTelemetry(
                $config,
                $model ?? null,
                ['request_kind' => 'vision', 'error' => $e->getMessage()]
            ));

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

            // Skip providers unsafe for sensitive/private data
            if ($sensitiveData && ! ($provider['allows_private_data'] ?? false)) {
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
     * Calculate exponential backoff delay
     */
    private function calculateBackoff(int $attempt): int
    {
        $backoff = $this->providerRetryInitialBackoffMs() * pow($this->providerRetryBackoffMultiplier(), $attempt - 1);
        // Add jitter (±25%)
        $jitter = $backoff * 0.25 * (mt_rand() / mt_getrandmax() * 2 - 1);

        return (int) min($backoff + $jitter, $this->providerRetryMaxBackoffMs());
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
        $cooldown = $this->circuitBreakerPolicy->cooldownSeconds();

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

    private function modelTelemetry(array $config, ?string $model, array $metadata = []): array
    {
        if ($model !== null && trim($model) !== '') {
            $metadata['model'] = $model;
        }

        $caller = $config['caller'] ?? $config['telemetry_caller'] ?? null;
        if (is_string($caller) && trim($caller) !== '') {
            $metadata['caller'] = trim($caller);
        }

        $modelRole = $config['model_role'] ?? null;
        if (is_string($modelRole) && trim($modelRole) !== '') {
            $metadata['model_role'] = trim($modelRole);
        }

        $requestKind = $config['request_kind'] ?? null;
        if (is_string($requestKind) && trim($requestKind) !== '') {
            $metadata['request_kind'] = trim($requestKind);
        }

        $traceContext = $config['_route_trace_context'] ?? null;
        if ($traceContext instanceof LlmRouteTraceContext) {
            $metadata['route_trace_id'] = $traceContext->traceId();
            $metadata['route_attempt_index'] = $traceContext->nextAttemptIndex();
        }

        return $metadata;
    }

    private function withRouteTraceContext(array $config): array
    {
        if (($config['_route_trace_context'] ?? null) instanceof LlmRouteTraceContext) {
            return $config;
        }

        $traceId = $config['route_trace_id'] ?? null;
        $config['_route_trace_context'] = LlmRouteTraceContext::create(
            is_string($traceId) ? $traceId : null
        );

        return $config;
    }

    private function recordModelAttemptTelemetry(
        string $instanceId,
        bool $success,
        ?float $durationSeconds,
        array $metadata
    ): void {
        if (! $this->useDynamicPool || ! $this->poolManager) {
            return;
        }

        try {
            $this->poolManager->recordModelRequestAttempt(
                $instanceId,
                $success,
                $durationSeconds === null ? null : (int) ($durationSeconds * 1000),
                $this->normalizeMetricMetadata($metadata)
            );
        } catch (Exception $e) {
            Log::debug('AIService: Pool manager model attempt telemetry failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeMetricMetadata(array $metadata): array
    {
        if (empty($metadata['caller'])) {
            $metadata['caller'] = self::$agentModelRole ? (self::$agentModelRole.'_agent') : 'direct';
        }

        if (empty($metadata['model_role']) && self::$agentModelRole) {
            $metadata['model_role'] = self::$agentModelRole;
        }

        return $metadata;
    }

    /**
     * Record successful request
     */
    private function recordSuccess(string $providerId, float $duration, array $metadata = []): void
    {
        $durationMs = (int) ($duration * 1000);
        $metadata = $this->normalizeMetricMetadata($metadata);

        if (! $this->shouldRecordProviderHealth($providerId)) {
            return;
        }

        // Update pool manager if available (persistent storage)
        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $this->poolManager->recordSuccess($metadata['instance_id'] ?? $providerId, $durationMs, $metadata);
            } catch (Exception $e) {
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
    private function recordFailure(string $providerId, ?string $error = null, ?int $retryAfterSeconds = null, array $metadata = []): void
    {
        $metadata = $this->normalizeMetricMetadata($metadata);

        if (! $this->shouldRecordProviderHealth($providerId)) {
            return;
        }

        // Update pool manager if available (persistent storage)
        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $this->poolManager->recordFailure($metadata['instance_id'] ?? $providerId, $error ?? 'Unknown error', $retryAfterSeconds, $metadata);
            } catch (Exception $e) {
                Log::debug('AIService: Pool manager recordFailure failed', ['error' => $e->getMessage()]);
            }
        }

        // Update shared circuit breaker state
        $this->circuitBreaker->recordFailure($providerId, $this->circuitBreakerPolicy->failureThreshold());

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
        $instanceId = $instanceId ?? ($this->ollamaInstances[0]['id'] ?? null);
        if ($instanceId === null) {
            return false;
        }

        $this->poolManager ??= app(LLMPoolManagerService::class);

        return $this->poolManager->isInstanceBusy($instanceId);
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
        if (! is_string($instanceId) || trim($instanceId) === '') {
            throw new InferenceAdmissionException('Busy-lock acquisition requires the selected registered instance.');
        }
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

        $this->poolManager ??= app(LLMPoolManagerService::class);

        return $this->poolManager->acquireBusyLock($instanceId, $requestId);
    }

    /**
     * Release Ollama busy lock after request completes
     *
     * @param  string  $requestId  Request identifier that acquired the lock
     * @param  string|null  $instanceId  Specific instance (null = primary)
     */
    private function releaseOllamaBusyLock(string $requestId, ?string $instanceId = null): void
    {
        if (! is_string($instanceId) || trim($instanceId) === '') {
            throw new InferenceAdmissionException('Busy-lock release requires the original registered instance.');
        }

        $this->poolManager ??= app(LLMPoolManagerService::class);
        if (! $this->poolManager->releaseBusyLock($instanceId, $requestId)) {
            throw new InferenceAdmissionException('Exact pool release ownership is unavailable; custody retained.');
        }
    }

    /**
     * Get info about current Ollama busy lock
     *
     * @param  string|null  $instanceId  Specific instance (null = primary)
     */
    public function getOllamaBusyInfo(?string $instanceId = null): ?array
    {
        $instanceId = $instanceId ?? ($this->ollamaInstances[0]['id'] ?? null);
        if ($instanceId === null) {
            return null;
        }

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

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function runtimePolicyInt(string $key, int $fallback): int
    {
        $value = $this->runtimePolicy()[$key] ?? null;
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function providerRetryMaxAttempts(): int
    {
        // One dispatch is a custody invariant, independent of retry tuning.
        return self::inferenceReplayAllowed()
            ? $this->runtimePolicyInt('provider_retry_max_attempts', self::MAX_RETRIES)
            : 1;
    }

    private function providerRetryInitialBackoffMs(): int
    {
        return $this->runtimePolicyInt('provider_retry_initial_backoff_ms', self::INITIAL_BACKOFF_MS);
    }

    private function providerRetryMaxBackoffMs(): int
    {
        return $this->runtimePolicyInt('provider_retry_max_backoff_ms', self::MAX_BACKOFF_MS);
    }

    private function providerRetryBackoffMultiplier(): int
    {
        return $this->runtimePolicyInt('provider_retry_backoff_multiplier', self::BACKOFF_MULTIPLIER);
    }

    /**
     * @param  array<string,int|float>  $values
     */
    private function positiveArrayInt(array $values, string $key): ?int
    {
        if (! isset($values[$key]) || ! is_numeric($values[$key])) {
            return null;
        }

        $value = (int) $values[$key];

        return $value > 0 ? $value : null;
    }

    /**
     * @param  array<string,int|float>  $values
     */
    private function positiveArrayFloat(array $values, string $key): ?float
    {
        if (! isset($values[$key]) || ! is_numeric($values[$key])) {
            return null;
        }

        $value = (float) $values[$key];

        return $value > 0 ? $value : null;
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
                : ((int) Process::timeout(5)->run(['nproc'])->output() ?: 1),
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

        // Legacy callers still use the registered table inventory.
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
     * @param  array<int,array<int,string>>  $modelCapabilityGroups  Each group requires one exact role-model capability
     * @return array{id:string,url:string,priority:int}|null Normalized instance shape, or null if none available
     */
    private function selectOllamaInstanceForRole(
        string $role,
        array $config = [],
        array $modelCapabilityGroups = [],
        ?string $requiredModel = null
    ): ?array {
        $embeddingRequest = $role === 'embedding' || $modelCapabilityGroups === [['embedding']];
        $capabilities = ['text'];
        if ($role === 'vision') {
            $capabilities = ['vision'];
        } elseif ($embeddingRequest) {
            $capabilities = ['embedding'];
        }

        if ($this->useDynamicPool && $this->poolManager) {
            try {
                $selected = $this->poolManager->selectInstance([
                    'role' => $role,
                    'capabilities' => $capabilities,
                    'model' => $requiredModel,
                    'model_capability_groups' => $modelCapabilityGroups,
                    'urgency' => $config['urgency'] ?? 'normal',
                    'prefer_instance' => $config['preferred_ollama_instance_ids'][0] ?? null,
                    'explicit_instance_selection' => $config['_ollama_instance_selection_explicit'] ?? false,
                    'exclude_instances' => $this->excludedOllamaInstanceIds($config),
                ]);

                if ($selected !== null && ($selected['instance']->instance_type ?? '') === 'ollama') {
                    $instance = $selected['instance'];

                    $context = $instance->embedding_context_length ?? null;
                    if ($embeddingRequest
                        && ((! is_int($context) && (! is_string($context) || ! preg_match('/^[1-9][0-9]*$/D', $context)))
                            || filter_var($context, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
                        return null;
                    }

                    return [
                        'id' => $instance->instance_id,
                        'url' => $instance->base_url,
                        'priority' => (int) $instance->priority,
                    ] + ($embeddingRequest ? ['embedding_context_length' => (int) $context] : []);
                }

                return null;
            } catch (\Throwable $e) {
                Log::debug('AIService: selectOllamaInstanceForRole pool eligibility unavailable; refusing Ollama selection', [
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // Capability-constrained routes must not widen into a legacy host/model
        // path that has no model-level registry admission evidence.
        if ($modelCapabilityGroups !== [] || $requiredModel !== null) {
            return null;
        }

        // Fallback: use the legacy healthy-instance list filtered by config.
        $instances = $this->filterOllamaInstancesForRole(
            $this->getHealthyOllamaInstancesForConfig($config),
            $role
        );

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
    private function orderOllamaInstancesForRole(
        string $role,
        array $config = [],
        array $modelCapabilityGroups = [],
        ?string $requiredModel = null
    ): array {
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
                    'model' => $requiredModel,
                    'urgency' => $config['urgency'] ?? 'normal',
                    'prefer_instance' => $config['preferred_ollama_instance_ids'][0] ?? null,
                    'explicit_instance_selection' => $config['_ollama_instance_selection_explicit'] ?? false,
                    'exclude_instances' => $this->excludedOllamaInstanceIds($config),
                    'model_capability_groups' => $modelCapabilityGroups,
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

                    $ordered = $this->filterOllamaInstancesForRole($ordered, $role);
                    if (! empty($ordered)) {
                        return $ordered;
                    }
                }

                // The pool applied all table gates. No candidates is a denial,
                // not permission to reconstruct a circuit-only legacy list.
                return [];
            } catch (\Throwable $e) {
                Log::debug('AIService: orderOllamaInstancesForRole pool path failed', [
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        }

        if ($modelCapabilityGroups !== [] || $requiredModel !== null) {
            return [];
        }

        // Fallback: legacy priority-ordered list.
        return $this->filterOllamaInstancesForRole(
            $this->getHealthyOllamaInstancesForConfig($config),
            $role
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $instances
     * @return array<int,array<string,mixed>>
     */
    private function filterOllamaInstancesForRole(array $instances, string $role): array
    {
        return array_values(array_filter(
            $instances,
            fn (array $instance): bool => $this->providerConfigAllowsModelRole(
                is_array($instance['config'] ?? null) ? $instance['config'] : [],
                $role
            )
        ));
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

    private function excludedOllamaInstanceIds(array $config): array
    {
        $allowed = $config['allowed_ollama_instance_ids'] ?? null;
        if (! is_array($allowed) || $allowed === []) {
            return [];
        }

        return array_values(array_diff(
            array_map('strval', $this->listAllOllamaInstanceIds()),
            array_map('strval', $allowed)
        ));
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

    /** Resolve a request against one instance without substituting an explicit required model. */
    private function resolveOllamaRequestModel(array $instance, string $requestedModel, ?string $role, array $config): ?string
    {
        $explicit = $this->truthyDbValue($config['ollama_required_no_fallback'] ?? false)
            ? trim((string) ($config['model_override'] ?? $config['model'] ?? '')) : '';
        $supported = $instance['supported_models'] ?? [];
        if ($explicit !== '') {
            return in_array($explicit, $supported, true) ? $explicit : null;
        }
        $model = $requestedModel;
        if ($role) {
            $models = $instance['config']['models'] ?? [];
            if (! empty($models[$role])) {
                $model = $models[$role];
            } elseif ($role !== 'standard' && ! empty($models['standard'])) {
                $model = $models['standard'];
            }
        }
        if ($supported !== [] && ! in_array($model, $supported, true)) {
            $default = $instance['default_model'] ?? null;

            return $default && in_array($default, $supported, true) ? $default : null;
        }

        return $model;
    }

    private function getOrderedTextOllamaInstances(?string $modelRole, array $config): array
    {
        $role = $modelRole ?: 'standard';

        $structuredOutputProbe = $this->isExactStructuredOutputEvalProbe($config, 'ollama');
        $capabilityGroups = $this->registeredStructuredOutputSchemaId($config) !== null
            && ! $structuredOutputProbe
            ? $this->structuredOutputCapabilityGroups()
            : [];

        $requiredModel = $structuredOutputProbe || $this->truthyDbValue($config['ollama_required_no_fallback'] ?? false)
            ? trim((string) ($config['model_override'] ?? $config['model'] ?? ''))
            : null;

        return $this->orderOllamaInstancesForRole($role, $config, $capabilityGroups, $requiredModel ?: null);
    }

    /**
     * Preserve routing preference within each group, but consume free local
     * capacity before waiting on a busy host in patient local-first mode.
     */
    private function orderAvailableOllamaInstancesFirst(array $instances): array
    {
        $available = [];
        $busy = [];

        foreach ($instances as $instance) {
            if ($this->isOllamaBusy((string) ($instance['id'] ?? ''))) {
                $busy[] = $instance;
            } else {
                $available[] = $instance;
            }
        }

        return array_merge($available, $busy);
    }

    /** @return list<list<string>> */
    private function structuredOutputCapabilityGroups(): array
    {
        $aliases = config('provider_model_capabilities.structured_output.model_capability_aliases', []);

        return [array_values(array_filter(is_array($aliases) ? $aliases : [], 'is_string'))];
    }

    private function registeredStructuredOutputSchemaId(array $config): ?string
    {
        $schemaId = $config['ollama_output_schema'] ?? null;

        return is_string($schemaId) && trim($schemaId) !== '' ? trim($schemaId) : null;
    }

    /**
     * Permit a capability probe only for one exact evaluation target with all
     * fallback and cascade paths suppressed. This admits evidence gathering;
     * it does not promote or persist a model capability.
     */
    private function isExactStructuredOutputEvalProbe(array $config, string $route): bool
    {
        if (! $this->truthyDbValue($config['structured_output_eval_probe'] ?? false)
            || ($config['task_type'] ?? null) !== 'ollama_eval'
            || $this->registeredStructuredOutputSchemaId($config) === null
            || ! $this->truthyDbValue($config['disable_cascade'] ?? false)
        ) {
            return false;
        }

        $model = trim((string) ($config['model'] ?? ''));
        $modelOverride = trim((string) ($config['model_override'] ?? ''));
        $maxTokens = (int) ($config['max_tokens'] ?? 0);
        if ($model === '' || $modelOverride === '' || $maxTokens < 1 || $maxTokens > 700) {
            return false;
        }

        if ($route === 'provider') {
            $requiredInstanceId = trim((string) ($config['required_instance_id'] ?? ''));

            return $requiredInstanceId !== ''
                && $model === "provider:{$requiredInstanceId}/{$modelOverride}"
                && ($config['model_role'] ?? null) === 'deep_offline';
        }

        $allowed = array_values(array_map('strval', is_array($config['allowed_ollama_instance_ids'] ?? null)
            ? $config['allowed_ollama_instance_ids']
            : []));
        $preferred = array_values(array_map('strval', is_array($config['preferred_ollama_instance_ids'] ?? null)
            ? $config['preferred_ollama_instance_ids']
            : []));

        return $model === $modelOverride
            && count($allowed) === 1
            && $allowed === $preferred
            && $this->truthyDbValue($config['ollama_required_no_fallback'] ?? false);
    }

    /**
     * Kimi's six-field engine protocol cannot carry response-format grammars.
     * Permit only the exact K3-05 target to return raw output for the existing
     * registered-schema evaluator; this does not weaken ordinary routing.
     */
    private function isEvaluatorValidatedKimiK3Probe(array $config): bool
    {
        return $this->isExactStructuredOutputEvalProbe($config, 'provider')
            && app(SystemConfigService::class)->isDedicatedLlmTarget((string) ($config['required_instance_id'] ?? ''))
            && ($config['model'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'candidate_target')
            && ($config['model_override'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            && ($config['required_instance_id'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            && ($config['model_role'] ?? null) === 'deep_offline'
            && (int) ($config['max_tokens'] ?? 0) === 128;
    }

    private function applyPreferredOllamaInstanceOrder(array $config, ?string $modelRole): array
    {
        if (! empty($config['preferred_ollama_instance_ids']) || ! empty($config['allowed_ollama_instance_ids'])) {
            $config['_ollama_instance_selection_explicit'] = true;

            return $config;
        }

        $config['_ollama_instance_selection_explicit'] = false;

        $preferences = $this->runtimePolicy()['preferred_ollama_instance_ids_by_role'] ?? [];
        $preferred = is_array($preferences) ? ($preferences[$modelRole ?? 'standard'] ?? []) : [];
        if (is_array($preferred) && $preferred !== []) {
            $config['preferred_ollama_instance_ids'] = array_values(array_filter(
                $preferred,
                static fn (mixed $id): bool => is_string($id) && trim($id) !== ''
            ));
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

                $instanceConfig = json_decode($instance->config ?? '{}', true) ?: [];
                foreach (['timeout_model_loaded', 'timeout_model_loading', 'timeout_model_swap'] as $timeoutField) {
                    if (isset($instance->{$timeoutField}) && is_numeric($instance->{$timeoutField})) {
                        $instanceConfig[$timeoutField] = (int) $instance->{$timeoutField};
                    }
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
                    'config' => $instanceConfig, // N119c: needed for model_role resolution and per-host timeout policy
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

        } catch (Exception $e) {
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
                if ($elapsed < $this->circuitBreakerPolicy->cooldownSeconds()) {
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
    private function getEmbeddingProviders(array $options = []): array
    {
        $providers = [];
        $requiredId = (string) ($options['required_instance_id'] ?? '');
        $requiredOllama = ! empty($options['ollama_required_no_fallback']);
        if ($requiredOllama) {
            $instance = $this->selectOllamaInstanceForRole($options['model_role'] ?? 'embedding', $options, [['embedding']], $options['exact_embedding_model']);

            return $instance === null ? [] : [$instance + ['instance_type' => 'ollama']];
        }

        // 1. Ollama instances (local, preferred) — use role-aware pool ordering
        // so table-owned embedding/health bias can keep otherwise idle local
        // hardware busy without changing global provider priority.
        $ollamaInstances = $requiredId === '' ? $this->orderOllamaInstancesForRole('embedding', $options) : [];
        foreach ($ollamaInstances as $instance) {
            $instance['instance_type'] = 'ollama';
            $providers[] = $instance;
        }

        // Reuse the existing table/profile/privacy/circuit/role admission chain.
        if ($this->truthyDbValue($options['local_only'] ?? false)
            || $this->truthyDbValue($options['disable_external_fallback'] ?? false)) {
            return $providers;
        }
        foreach ($this->buildFallbackChain(! empty($options['sensitive_data']), false, $options + ['model_role' => 'embedding']) as $provider) {
            if (($provider['type'] ?? null) !== 'external_api'
                || ! in_array($provider['instance_type'], ['openai', 'google_gemini', 'azure_openai', 'custom'], true)) {
                continue;
            }
            $capabilities = $provider['capabilities'];
            if (! in_array('embedding', $capabilities) && empty($capabilities['embedding'])) {
                continue;
            }
            $config = $provider['config'];
            $embeddingModel = $requiredId !== '' ? $options['exact_embedding_model'] : ($config['embedding_model'] ?? null);
            $dimensions = $config['embedding_dimensions'] ?? null;
            if (! $embeddingModel || ($dimensions && (int) $dimensions !== 768)) {
                continue;
            }
            $providers[] = $provider + ['embedding_model' => $embeddingModel, 'embedding_dimensions' => $dimensions];
        }

        return $providers;
    }

    /**
     * Get Ollama status for specific instance
     */
    public function getOllamaStatus(?string $targetModel = null, ?string $url = null): array
    {
        $targetModel = $targetModel ?? $this->defaultModel;
        $url = $url ?? ($this->ollamaInstances[0]['url'] ?? null);
        if (! is_string($url) || trim($url) === '') {
            return ['available' => false, 'error' => 'No registered Ollama endpoint.', 'models_installed' => [], 'model_installed' => false, 'model_loaded' => false];
        }
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
            $cached['model_digest'] = $this->matchingModelDigest(
                $targetModel,
                (array) ($cached['model_digests'] ?? [])
            );

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
            $modelDigests = [];
            foreach ($models as $model) {
                $name = trim((string) ($model['name'] ?? $model['model'] ?? ''));
                $digest = trim((string) ($model['digest'] ?? ''));
                if ($name !== '' && $digest !== '') {
                    $modelDigests[$name] = $digest;
                }
            }

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
                'model_digests' => $modelDigests,
                'model_installed' => $modelInstalled,
                'model_digest' => $this->matchingModelDigest($targetModel, $modelDigests),
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
    public function preWarmModel(string $model, ?string $url = null, ?PoolExecutionReceipt $poolReceipt = null): array
    {
        // The admitted inference loads its own settings; do not issue a second request.
        if (! self::inferenceReplayAllowed()) {
            return ['status' => 'skipped', 'reason' => 'non_replayable_inference'];
        }

        $url = $url ?? $this->ollamaInstances[0]['url'];
        if (app(ModelHostControllerResourcePolicyService::class)->managedHostForEndpoint($url) !== null) {
            return ['status' => 'skipped', 'reason' => 'controller_owned_model_start'];
        }

        if ($poolReceipt === null) {
            $owner = DB::table('llm_instances')->where('base_url', $url)->get(['instance_id']);
            if ($owner->count() !== 1) {
                throw new InferenceAdmissionException('Prewarm exact pool owner is unqualified; dispatch held.');
            }
            $instanceId = $owner->first()->instance_id;
            $requestId = (string) Str::uuid();
            if (! $this->acquireOllamaBusyLock($requestId, $instanceId)) {
                return ['status' => 'skipped', 'reason' => 'busy'];
            }
            try {
                $receipt = $this->poolManager->executionReceipt($instanceId, $requestId, $model);

                return $this->preWarmModel($model, $url, $receipt);
            } finally {
                $this->releaseOllamaBusyLock($requestId, $instanceId);
            }
        }

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
        if ($poolReceipt === null && $this->isOllamaBusy($instanceId)) {
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
            $request = $poolReceipt->transportHttp(Http::withOptions([
                'timeout' => self::TIMEOUT_MODEL_LOADING,
                'connect_timeout' => 10,
            ]));
            $poolReceipt->beforeSend('ollama_generate', "{$url}/api/generate", $model);
            $response = $request->post("{$url}/api/generate", [
                'model' => $model,
                'prompt' => 'hi',
                'think' => false,
                'stream' => false,
                'options' => ['num_predict' => 1],
            ]);

            $success = $response->successful();
            if (! $success) {
                throw new InferenceAdmissionException('Pool prewarm returned no valid terminal response; custody retained.');
            }
            $poolReceipt->ollamaTerminal($response->json());
            Log::info('AIService: Pre-warm completed', ['model' => $model, 'success' => $success]);

            return [
                'status' => $success ? 'success' : 'failed',
                'reason' => $success ? null : 'unsuccessful_response',
            ];
        } catch (Exception $e) {
            $poolReceipt->stopReplayAfterFailure($e);
            Log::warning('AIService: Pre-warm failed', ['error' => $e->getMessage()]);

            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // UTILITY METHODS
    // ═══════════════════════════════════════════════════════════════════

    private function calculateTimeout(array $ollamaStatus, string $targetModel, array $providerConfig = []): int
    {
        if ($ollamaStatus['model_loaded']) {
            return $this->positiveTimeoutValue(
                $providerConfig['timeout_model_loaded'] ?? null,
                self::TIMEOUT_MODEL_LOADED
            );
        }
        if ($ollamaStatus['needs_swap'] ?? false) {
            return $this->positiveTimeoutValue(
                $providerConfig['timeout_model_swap'] ?? null,
                self::TIMEOUT_MODEL_SWAP
            );
        }

        return $this->positiveTimeoutValue(
            $providerConfig['timeout_model_loading'] ?? null,
            self::TIMEOUT_MODEL_LOADING
        );
    }

    private function positiveTimeoutValue(mixed $value, int $fallback): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }

    private function applyCallerTimeoutCap(int $calculatedTimeout, mixed $requestedTimeout): int
    {
        $requested = is_numeric($requestedTimeout) ? (int) $requestedTimeout : 0;
        if ($requested <= 0) {
            return $calculatedTimeout;
        }

        return min($calculatedTimeout, max(1, $requested));
    }

    private function resolveOllamaTimeout(
        array $ollamaStatus,
        int $calculatedTimeout,
        mixed $requestedTimeout,
        bool $allowRequestedExtension = false
    ): int {
        $requested = is_numeric($requestedTimeout) ? (int) $requestedTimeout : 0;
        if ($requested <= 0) {
            return $calculatedTimeout;
        }

        if ($allowRequestedExtension || ! ($ollamaStatus['model_loaded'] ?? false)) {
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

    /** @param array<string,string> $modelDigests */
    private function matchingModelDigest(?string $targetModel, array $modelDigests): ?string
    {
        if (! is_string($targetModel) || trim($targetModel) === '') {
            return null;
        }

        $targetModel = trim($targetModel);
        if (isset($modelDigests[$targetModel])) {
            $digest = trim((string) $modelDigests[$targetModel]);

            return $digest !== '' ? $digest : null;
        }

        foreach ($modelDigests as $model => $digest) {
            if ($this->isModelMatch((string) $model, $targetModel)) {
                $digest = trim((string) $digest);

                return $digest !== '' ? $digest : null;
            }
        }

        return null;
    }

    private function cacheStatus(string $key, array $status): array
    {
        $status['timestamp'] = time();
        Cache::put($key, $status, 30);

        return $status;
    }

    /** Only a keyed digest enters SemanticCache; credentials are never selected. */
    private function generationCacheContext(array $config, string $model, string $prompt): ?array
    {
        try {
            foreach (array_keys($config) as $key) {
                if (preg_match('/auth|credential|password|secret|api[_-]?key|access[_-]?token|bearer|cookie|header|admission[_-]?token/i', (string) $key)
                    || in_array($key, ['instance_url', 'base_url', 'ollama_url'], true)) {
                    return null;
                }
            }
            // Bind all supplied evidence/options. A new task or transport field
            // must not silently disappear from an allowlist. Only generated trace
            // identity is excluded; it has no effect on the inference input.
            unset($config['_route_trace_context'], $config['route_trace_id']);
            $identity = ['prompt' => $prompt, 'request' => $config];
            $identity['model'] = $model;
            $identity['router_defaults'] = $this->aiRouter->generationCacheDefaults($config);
            $identity['profile'] = $this->getActiveProfile();
            $identity['offline'] = $this->isOfflineMode();
            $identity['local_generations'] = [];
            $role = $config['model_role'] ?? 'standard';
            foreach ($this->getOrderedTextOllamaInstances($role, $config) as $instance) {
                $effective = $this->applyOllamaInstanceRuntimeConfig($config, $instance, $role);
                $effective = $this->applyOllamaRoutingPolicy($effective, $this->activeLlmRoutingPolicy());
                $identity['local_generations'][] = [
                    'provider' => $instance['id'],
                    'endpoint' => $instance['url'],
                    'provider_config' => $instance['config'] ?? [],
                    'model' => $this->resolveOllamaRequestModel($instance, $model, $role, $config),
                    'settings' => $effective,
                ];
            }
            $budget = (int) config('services.ai.cache_identity_max_bytes');
            $depth = (int) config('services.ai.cache_identity_max_depth');
            if ($budget <= 0 || $depth <= 0) {
                return null;
            }
            $canonical = $this->canonicalCacheValue($identity, $budget, $depth);
            $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $receipt = SensitiveTextTelemetry::content($encoded, 'ai_generation_cache.v2', 'generation_identity');
            if ($receipt['private_text_fingerprint'] === null) {
                return null;
            }

            return ['schema' => 'plos.ai_generation_cache.v2', 'identity' => $receipt['private_text_fingerprint']];
        } catch (\Throwable) {
            // Malformed/oversized settings or unavailable identity proof disable caching only.
            return null;
        }
    }

    private function canonicalCacheValue(mixed $value, int &$budget, int $depth): mixed
    {
        if ($depth <= 0 || --$budget < 0) {
            throw new \InvalidArgumentException('Cache identity budget exceeded');
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => &$item) {
                if (is_string($key)) {
                    $budget -= strlen($key);
                    if (preg_match('/auth|credential|password|secret|api[_-]?key|access[_-]?token|bearer|cookie|header/i', $key)) {
                        throw new \InvalidArgumentException('Credential-bearing cache settings');
                    }
                }
                $item = $this->canonicalCacheValue($item, $budget, $depth - 1);
            }
            unset($item);
        } elseif (is_string($value)) {
            $budget -= strlen($value);
        } elseif (! is_null($value) && ! is_bool($value) && ! is_int($value) && (! is_float($value) || ! is_finite($value))) {
            throw new \InvalidArgumentException('Invalid cache identity value');
        }
        if ($budget < 0) {
            throw new \InvalidArgumentException('Cache identity budget exceeded');
        }

        return $value;
    }

    /** Reuse routing admission, never infer eligibility from the cached response. */
    private function cachedGenerationIsAdmitted(array $result, string $model, ?string $role, array $config): bool
    {
        if (($result['success'] ?? false) !== true || ! is_string($result['provider'] ?? null)) {
            return false;
        }
        foreach ($this->getOrderedTextOllamaInstances($role, $config) as $instance) {
            if ($instance['id'] === $result['provider']
                && $this->resolveOllamaRequestModel($instance, $model, $role, $config) === ($result['model'] ?? null)) {
                return true;
            }
        }

        // Nonlocal cache hits have no exact reusable admission proof here.
        return false;
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

        $providers = $instances;
        if ($this->useDynamicPool && $this->poolManager) {
            try {
                foreach ($this->poolManager->getInstances(false) as $provider) {
                    if (in_array((string) ($provider->instance_type ?? ''), ['claude_cli', 'anthropic_api'], true)) {
                        continue;
                    }

                    $id = (string) ($provider->instance_id ?? '');
                    if ($id === '') {
                        continue;
                    }

                    $existing = $providers[$id] ?? [];
                    $circuitState = (string) ($provider->circuit_state ?? 'unknown');
                    $providerConfig = json_decode((string) ($provider->config ?? '{}'), true);
                    $providerConfig = is_array($providerConfig) ? $providerConfig : [];
                    $providers[$id] = array_merge($existing, [
                        'name' => (string) ($provider->instance_name ?? $id),
                        'type' => (string) ($provider->instance_type ?? 'unknown'),
                        'adapter' => (string) ($providerConfig['adapter'] ?? 'unspecified'),
                        'transport' => (string) ($providerConfig['transport'] ?? 'unspecified'),
                        'routability' => (string) ($provider->routability ?? 'blocked'),
                        'available' => (bool) ($provider->is_healthy ?? false)
                            && $circuitState !== 'open'
                            && (string) ($provider->routability ?? 'blocked') === 'allowed',
                        'circuit_state' => $circuitState,
                        'health_score' => isset($provider->health_score) ? (int) $provider->health_score : null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::debug('AIService: Provider health inventory unavailable', [
                    'exception_type' => $e::class,
                ]);
            }
        }

        return [
            'ollama_instances' => $instances,
            'providers' => $providers,
            'config' => [
                'circuit_threshold' => $this->circuitBreakerPolicy->failureThreshold(),
                'circuit_cooldown' => $this->circuitBreakerPolicy->cooldownSeconds().'s',
                'max_retries' => $this->providerRetryMaxAttempts(),
                'backoff_initial' => $this->providerRetryInitialBackoffMs().'ms',
                'backoff_max' => $this->providerRetryMaxBackoffMs().'ms',
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
        return max(count($this->getHealthyOllamaInstances()), 1);
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

    /** Refresh only the external-provider inventory, retaining response/custody caches. */
    public static function clearProviderInventoryCache(bool $strict = false): void
    {
        Cache::forget(self::EXTERNAL_PROVIDER_CACHE_KEY);
        if ($strict && Cache::has(self::EXTERNAL_PROVIDER_CACHE_KEY)) {
            throw new RuntimeException('External provider inventory invalidation unavailable.');
        }
    }

    /** Clear semantic cache. */
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
     * 2. Vision AI (images/scanned PDFs) with configured provider failover
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

                // For images, long OCR/Tika output can still be mostly form
                // noise. Escalate suspicious image text to vision before
                // accepting it as the extraction result.
                if (
                    ! $isImage
                    || ! $options['use_vision']
                    || ! $this->shouldEscalateImageTextToVision((string) $tikaResult['text'])
                ) {
                    $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                    Log::info('AIService::extractContent completed via Tika', [
                        'filename' => $filename,
                        'text_length' => strlen($result['text']),
                    ]);

                    return $result;
                }

                Log::info('AIService::extractContent escalating noisy image text to Vision', [
                    'filename' => $filename,
                    'text_length' => strlen((string) $tikaResult['text']),
                ]);
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
            if ($isPdf) {
                $result['vision_result'] = $visionResult;
            }

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
            $result['ocr_result'] = $ocrResult;

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
                $result['metadata'] = array_merge($result['metadata'], $whisperResult['metadata'] ?? [], [
                    'transcript' => (string) $whisperResult['text'],
                ]);
                $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                Log::info('AIService::extractContent completed via Whisper', [
                    'filename' => $filename,
                    'text_length' => strlen($result['text']),
                ]);

                return $result;
            }

            $result['error'] = $whisperResult['error'] ?? 'Whisper transcription failed';
            $result['failure_code'] = $whisperResult['failure_code'] ?? 'unknown_failure';
        }

        // If we have partial success from any step, return it
        if (! empty(trim($result['text']))) {
            $result['success'] = true;
        }

        $result['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);

        if (! $result['success']) {
            $result['error'] = $result['ocr_result']['error'] ?? $result['vision_result']['error'] ?? ($result['error'] ?? null);
        }

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

    protected function shouldEscalateImageTextToVision(string $text): bool
    {
        $compact = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        $length = mb_strlen($compact);

        if ($length < 100) {
            return true;
        }

        $charCount = max(1, mb_strlen($compact));
        preg_match_all('/[[:alnum:]]/u', $compact, $alnumMatches);
        preg_match_all('/[|_~^{}<>=\\\\\/]+|[;:,.]{3,}/u', $compact, $noiseMatches);
        preg_match_all('/(?<![[:alpha:]])[[:alpha:]](?![[:alpha:]])/u', $compact, $singleLetterMatches);
        preg_match_all('/\b[[:alpha:]]{3,}\b/u', $compact, $wordMatches);
        preg_match_all('/\b(?:certificate|bureau|vital|statistics|registration|district|primary|file|place of|full name|date of|birth|death|father|mother|informant|occupation)\b/iu', $compact, $labelMatches);
        preg_match_all('/\b(?:Jan|January|Feb|February|Mar|March|Apr|April|May|Jun|June|Jul|July|Aug|August|Sep|Sept|September|Oct|October|Nov|November|Dec|December|\d{4}|county|township|borough|cemetery|M\.D\.|Mrs\.|Miss)\b/iu', $compact, $dataMatches);

        $alnumRatio = count($alnumMatches[0]) / $charCount;
        $singleLetterRatio = count($singleLetterMatches[0]) / max(1, count($singleLetterMatches[0]) + count($wordMatches[0]));

        if ($alnumRatio < 0.55) {
            return true;
        }

        if (count($noiseMatches[0]) >= 12 && $singleLetterRatio > 0.18) {
            return true;
        }

        return count($labelMatches[0]) >= 8 && count($dataMatches[0]) < 3;
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
            $extractedText = $response->body();

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
                } catch (Exception $e) {
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

        } catch (Exception $e) {
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
                $phpWord = IOFactory::load($tempPath);
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

        } catch (Exception $e) {
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
        if ($element instanceof Text) {
            return $element->getText() ?? '';
        }

        // Handle TextRun (container of Text elements)
        if ($element instanceof TextRun) {
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
        if (method_exists($element, 'getText') && ! ($element instanceof Text)) {
            $elementText = $element->getText();
            if (is_string($elementText)) {
                $text .= $elementText."\n";
            }
        }

        return $text;
    }

    /** Yield every verified PDF page as raw PNG bytes for the existing vision pipeline. */
    private function pdfPageImages(string $pdfPath, ?int &$pageCount): \Generator
    {
        $info = Process::timeout(30)->run(['pdfinfo', $pdfPath]);
        if (! $info->successful() || ! preg_match('/^Pages:\s+([1-9][0-9]*)\s*$/m', $info->output(), $match)
            || ($count = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
            throw new RuntimeException('PDF page inventory failed; complete vision extraction was not attempted.');
        }
        $pageCount = $count;
        for ($page = 1; $page <= $count; $page++) {
            $result = Process::timeout(120)->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page,
                '-singlefile', '-r', '150', '-png', $pdfPath]);
            $bytes = $result->output();
            $dimensions = @getimagesizefromstring($bytes);
            if (! $result->successful() || $result->errorOutput() !== '' || ($dimensions[2] ?? null) !== IMAGETYPE_PNG) {
                throw new RuntimeException('PDF page '.$page.' did not render completely.');
            }
            yield ['page' => $page, 'page_count' => $count, 'image' => $bytes];
        }
    }

    /** Extract content using the configured vision pipeline, retaining every PDF page result. */
    private function visionExtractContent(?string $filePath, string $content, string $filename, array $options): array
    {
        $tempPdfPath = null;
        $pages = [];
        $texts = [];
        $pageCount = null;
        $isPdf = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf';
        $origin = $filePath ?: $filename;
        $prompt = 'Extract ALL text from this image. If it is a document, transcribe the complete text content. If it is a photo, describe what you see in detail including any visible text.';
        try {
            if ($isPdf) {
                if ($filePath === null) {
                    $directory = storage_path('app/temp');
                    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                        throw new RuntimeException('A private PDF working directory could not be created.');
                    }
                    $tempPdfPath = tempnam($directory, 'vision_pdf_');
                    if ($tempPdfPath === false || file_put_contents($tempPdfPath, $content) !== strlen($content)) {
                        throw new RuntimeException('The complete PDF input could not be staged.');
                    }
                    chmod($tempPdfPath, 0600);
                    $filePath = $tempPdfPath;
                }
                foreach ($this->pdfPageImages($filePath, $pageCount) as $page) {
                    $pageCount = $page['page_count'];
                    // processImage accepts raw bytes; its provider adapter owns base64 encoding.
                    $result = $this->processImage($page['image'], $prompt, ['suppressAlert' => true] + $options);
                    if (($result['fallback_suppressed'] ?? false) === true) {
                        throw new InferenceAdmissionException('Vision extraction admission refused; request must not be replayed.',
                            controllerFailure: is_array($result['controller_failure'] ?? null) ? $result['controller_failure'] : []);
                    }
                    $response = (string) ($result['response'] ?? '');
                    $text = $response !== '' ? $this->formatVisionExtractionText($response, $origin.'#page='.$page['page']) : '';
                    if ($text !== '') {
                        $texts[] = $text;
                    }
                    $complete = ($result['success'] ?? false) && trim($response) !== '';
                    $pages[] = ['page' => $page['page'], 'success' => $complete, 'response' => $response, 'text' => $text,
                        'provider' => $result['provider'] ?? null, 'model' => $result['model'] ?? null,
                        'error' => $result['error'] ?? null];
                    if (! $complete) {
                        return ['success' => false, 'complete' => false, 'text' => implode("\n\n", $texts),
                            'pages' => $pages, 'page_count' => $pageCount, 'failed_page' => $page['page'],
                            'error' => 'Vision extraction failed or returned no text for PDF page '.$page['page'].'.'];
                    }
                }

                return ['success' => true, 'complete' => true, 'text' => implode("\n\n", $texts),
                    'pages' => $pages, 'page_count' => $pageCount,
                    'provider' => count(array_unique(array_column($pages, 'provider'))) === 1 ? $pages[0]['provider'] : 'multiple'];
            }
            $result = $this->processImage($content, $prompt, ['suppressAlert' => true] + $options);
            if (($result['fallback_suppressed'] ?? false) === true) {
                throw new InferenceAdmissionException('Vision extraction admission refused; request must not be replayed.',
                    controllerFailure: is_array($result['controller_failure'] ?? null) ? $result['controller_failure'] : []);
            }

            return ['success' => $result['success'],
                'text' => ! empty($result['response']) ? $this->formatVisionExtractionText((string) $result['response'], $origin) : '',
                'provider' => $result['provider'] ?? 'unknown'];
        } catch (Exception $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            Log::error('Vision extraction error', ['filename' => $filename, 'error' => $e->getMessage()]);

            return ['success' => false, 'complete' => false, 'text' => implode("\n\n", $texts),
                'pages' => $pages, 'page_count' => $pageCount, 'failed_page' => count($pages) + 1, 'error' => $e->getMessage()];
        } finally {
            if (is_string($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
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
                $directory = storage_path('app/temp');
                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('A private OCR working directory could not be created.');
                }
                $tempFile = tempnam($directory, 'ocr_');
                if ($tempFile === false || file_put_contents($tempFile, $content) !== strlen($content)) {
                    throw new RuntimeException('The complete OCR input could not be staged.');
                }
                chmod($tempFile, 0600);
                $filePath = $tempFile;
            }

            // For PDFs, convert to images first
            if ($extension === 'pdf') {
                return $this->ocrPdf($filePath);
            } else {
                // Direct OCR for images
                $result = Process::timeout(120)
                    ->run(['tesseract', $filePath, 'stdout', '-l', 'eng']);

                return ['success' => $result->successful() && trim($result->output()) !== '',
                    'complete' => $result->successful(), 'text' => $result->output(),
                    'stdout_base64' => base64_encode($result->output()), 'stderr_base64' => base64_encode($result->errorOutput()),
                    'exit_code' => $result->exitCode(), 'error' => $result->successful() ? null : 'Image OCR failed.'];
            }

        } catch (Exception $e) {
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
        $workingDir = null;
        $workingDirIdentity = null;
        $preparationAttempted = false;
        $preparationReturned = false;
        $retainInputs = false;

        try {
            $workingDir = rtrim(sys_get_temp_dir(), '/').'/plos-whisper-'.bin2hex(random_bytes(8));
            if (! mkdir($workingDir, 0700, true)) {
                return ['success' => false, 'error' => 'Unable to create Whisper working directory', 'failure_code' => 'whisper_working_directory_unavailable'];
            }

            $workingDirIdentity = @stat($workingDir);

            // Save content to temp file if needed
            if (! $filePath) {
                $tempFile = $workingDir.'/input.'.$extension;
                if (file_put_contents($tempFile, $content) === false) {
                    return ['success' => false, 'error' => 'Unable to write Whisper input', 'failure_code' => 'whisper_input_unavailable'];
                }
                $filePath = $tempFile;
            }

            // Find Whisper
            $whisperPath = $this->findWhisperPath();
            if (! $whisperPath) {
                return ['success' => false, 'error' => 'Whisper not available', 'failure_code' => 'runtime_or_input_unavailable'];
            }

            $runtime = app(WhisperRuntimeService::class);
            $sourceDurationSeconds = $runtime->mediaDurationSeconds($filePath);

            // For video, extract audio track first
            $isVideo = in_array($extension, ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'webm', 'm4v']);
            $audioFile = $filePath;

            if ($isVideo) {
                $audioFile = $workingDir.'/audio.wav';
                $preparationAttempted = true;
                $ffmpegResult = Process::timeout($runtime->preparationTimeoutSeconds($sourceDurationSeconds))->run([
                    'ffmpeg', '-i', $filePath,
                    '-vn', '-acodec', 'pcm_s16le',
                    '-ar', '16000', '-ac', '1',
                    '-y', $audioFile,
                ]);

                $preparationReturned = true;
                if (! $ffmpegResult->successful() || ! file_exists($audioFile)) {
                    return ['success' => false, 'error' => 'Audio extraction failed', 'failure_code' => 'audio_extraction_failed'];
                }
            }

            $language = trim((string) config('services.whisper.language', ''));
            $transcriptionResult = $runtime->transcribe(
                $audioFile,
                $language !== '' ? $language : null,
                'chat_attachment_transcription'
            );
            $transcription = $transcriptionResult['text'];

            // Extract metadata via ffprobe
            $metadata = $this->extractMediaMetadata($filePath);

            return [
                'success' => ! empty($transcription),
                'text' => $transcription,
                'metadata' => $metadata,
                'error' => $transcriptionResult['error'],
                'failure_code' => $transcriptionResult['failure_reason'] ?? null,
            ];

        } catch (\Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                $retainInputs = true;
                throw $e;
            }
            if ($preparationAttempted && ! $preparationReturned) {
                $retainInputs = true;
                throw new InferenceAdmissionException('Whisper audio preparation outcome is UNKNOWN; owned input artifacts require reconciliation.', 0, $e);
            }
            Log::error('Whisper extraction error', [
                'error_type' => $e::class,
                'error_sha256' => hash('sha256', $e->getMessage()),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'failure_code' => 'unknown_failure'];
        } finally {
            clearstatcache(true, $workingDir ?? '');
            $currentDirectory = $workingDir !== null ? @lstat($workingDir) : false;
            $disposeInputs = ! $retainInputs && is_array($workingDirIdentity) && is_array($currentDirectory)
                && ! is_link($workingDir) && $currentDirectory['dev'] === $workingDirIdentity['dev']
                && $currentDirectory['ino'] === $workingDirIdentity['ino'];
            if ($disposeInputs && $audioFile && $audioFile !== $filePath && file_exists($audioFile)) {
                @unlink($audioFile);
            }
            if ($disposeInputs && $tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            if ($disposeInputs && $workingDir && is_dir($workingDir)) {
                @rmdir($workingDir);
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
            maxChars: PHP_INT_MAX,
        ));
    }

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    /**
     * OCR a PDF by converting pages to images
     */
    private function ocrPdf(string $pdfPath): array
    {
        $pages = [];
        $texts = [];
        $pageCount = null;
        $hasText = false;
        try {
            foreach ($this->pdfPageImages($pdfPath, $pageCount) as $page) {
                $timedOut = false;
                try {
                    $ocr = Process::timeout(120)->input($page['image'])
                        ->run(['tesseract', 'stdin', 'stdout', '-l', 'eng']);
                } catch (ProcessTimedOutException $error) {
                    $ocr = $error->result;
                    $timedOut = true;
                }
                $text = $ocr->output();
                $complete = ! $timedOut && $ocr->successful();
                $hasText = $hasText || trim($text) !== '';
                $texts[] = '--- Page '.$page['page']." ---\n".$text;
                $pages[] = ['page' => $page['page'], 'success' => $complete, 'text' => $text,
                    'stdout_base64' => base64_encode($text), 'stderr_base64' => base64_encode($ocr->errorOutput()),
                    'exit_code' => $ocr->exitCode(), 'timed_out' => $timedOut];
                if (! $complete) {
                    return ['success' => false, 'complete' => false, 'text' => implode("\n\n", $texts),
                        'pages' => $pages, 'page_count' => $pageCount, 'failed_page' => $page['page'],
                        'error' => ($timedOut ? 'OCR timed out' : 'OCR failed').' on PDF page '.$page['page'].'.'];
                }
            }

            return ['success' => $hasText, 'complete' => true, 'text' => $hasText ? implode("\n\n", $texts) : '',
                'pages' => $pages, 'page_count' => $pageCount,
                'error' => $hasText ? null : 'OCR completed but returned no text.'];
        } catch (Exception $error) {
            return ['success' => false, 'complete' => false, 'text' => implode("\n\n", $texts),
                'pages' => $pages, 'page_count' => $pageCount, 'failed_page' => count($pages) + 1,
                'error' => $error->getMessage()];
        }
    }

    /**
     * Find Whisper executable
     */
    private function findWhisperPath(): ?string
    {
        return app(WhisperRuntimeService::class)->executablePath();
    }

    /**
     * Extract media metadata via ffprobe
     */
    private function extractMediaMetadata(string $filePath): array
    {
        try {
            $result = Process::timeout(30)->run([
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
     *  - prefer_external is set (caller has already routed intentionally)
     *  - model_role is 'quality' (already targeting a strong model)
     */
    private function shouldCascade(array $config): bool
    {
        if (! empty($config['disable_cascade'])) {
            return false;
        }

        if (! config('cascade.enabled', true)) {
            return false;
        }

        if (! empty($config['_cascade_attempt'])) {
            return false; // Prevent recursive cascading
        }

        $skipIfSet = config('cascade.skip_if_set', ['prefer_external']);
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
                    $config['caller'] ?? (self::$agentModelRole ? (self::$agentModelRole.'_agent') : 'direct'),
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
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fileKey = $key.'_FILE';
        $file = getenv($fileKey);
        if ($file === false || trim($file) === '') {
            $file = $_ENV[$fileKey] ?? $_SERVER[$fileKey] ?? null;
        }
        if (! is_string($file) || trim($file) === '') {
            $file = config('services.runtime_secret_files.'.$key);
        }
        if (! is_string($file) || trim($file) === '' || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $fileValue = trim((string) file_get_contents($file));

        return $fileValue !== '' ? $fileValue : null;
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
        if (! PromptIntegrityPolicy::allowsLossyTransforms()
            || ! (bool) config('recursion.auto_decompose_enabled', false)) {
            return null;
        }

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

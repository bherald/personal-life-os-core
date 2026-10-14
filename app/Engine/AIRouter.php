<?php

namespace App\Engine;

use App\DTOs\TrustEnvelope;
use App\Exceptions\AI\AIExceptionFactory;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\AI\RateLimitException;
use App\Exceptions\AI\TransientException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\AIService;
use App\Services\CircuitBreaker;
use App\Services\LlmCircuitBreakerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerTransportSupervisor;
use App\Services\OllamaProgressClient;
use App\Services\OllamaStructuredOutputSchemaService;
use App\Services\PipelineHttpClientFactory;
use App\Services\RetryService;
use App\Services\SystemConfigService;
use App\Services\TimeoutManager;
use App\Services\TrustBoundaryFormatterService;
use App\Support\AI\PoolExecutionReceipt;
use App\Support\AI\StreamTextStepJoiner;
use App\Support\PromptIntegrityPolicy;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level transport router for Ollama and OpenAI-compatible providers.
 *
 * Features:
 * - Direct Ollama integration (bypasses AnythingLLM overhead)
 * - Embedding generation for RAG
 * - OpenAI-compatible local_llm sidecars and admitted external APIs
 * - Performance optimized
 */
class AIRouter
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'ai.router_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'text_generation_timeout_seconds' => 120,
        'default_max_tokens' => 4096,
        'embedding_char_limit' => 30000,
        'vision_cli_timeout_seconds' => 180,
    ];

    private string $ollamaUrl;

    private string $ollamaModel;

    private string $embeddingModel;

    private string $visionModel;

    private int $timeout;

    private int $defaultMaxTokens;

    private int $embeddingCharLimit;

    private MCPRouter $mcpRouter;

    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    private ?LlmCircuitBreakerPolicyService $circuitBreakerPolicy = null;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct()
    {
        $ollamaConfig = $this->loadPrimaryOllamaConfig();
        $roleModels = $ollamaConfig['models'] ?? [];

        $this->ollamaUrl = (string) ($ollamaConfig['base_url'] ?? '');
        $this->ollamaModel = (string) ($roleModels['standard'] ?? $ollamaConfig['default_model'] ?? '');
        $this->embeddingModel = (string) ($ollamaConfig['embedding_model'] ?? $roleModels['embedding'] ?? '');
        $this->visionModel = (string) ($roleModels['vision'] ?? '');
        $this->timeout = $this->runtimePolicyInt('text_generation_timeout_seconds');
        $this->defaultMaxTokens = $this->runtimePolicyInt('default_max_tokens');
        $this->embeddingCharLimit = $this->runtimePolicyInt('embedding_char_limit');
        $this->mcpRouter = new MCPRouter;
    }

    private function loadPrimaryOllamaConfig(): array
    {
        try {
            $row = DB::selectOne(
                "SELECT base_url, config
                 FROM llm_instances
                 WHERE instance_type = 'ollama' AND is_active = 1
                 ORDER BY priority ASC, id ASC
                 LIMIT 1"
            );

            if (! $row) {
                return [];
            }

            $config = json_decode($row->config ?? '{}', true);

            return [
                'base_url' => $row->base_url ?? null,
                'models' => is_array($config['models'] ?? null) ? $config['models'] : [],
                'default_model' => $config['default_model'] ?? null,
                'embedding_model' => $config['embedding_model'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    private function circuitBreakerPolicy(): LlmCircuitBreakerPolicyService
    {
        return $this->circuitBreakerPolicy ??= app(LlmCircuitBreakerPolicyService::class);
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function formatMcpToolResultForPrompt(string $server, string $tool, mixed $payload, bool $isError = false): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            $encoded = $isError ? '{"error":"Unable to encode MCP tool error."}' : '{"error":"Unable to encode MCP tool result."}';
        }

        $wrapped = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: $isError ? 'mcp_tool_error' : 'mcp_tool_result',
            contentType: 'application/json',
            origin: $server.'.'.$tool,
            payload: $encoded,
            maxChars: (int) config('mcp.ollama_tool_calling.tool_result_max_chars', 6000),
        ));

        $instruction = $isError
            ? 'Explain this error to the user in natural language.'
            : 'Summarize this information in natural language for the user. Do not output raw JSON.';

        return $wrapped."\n\n[{$instruction}]";
    }

    /** Effective defaults shared by generation and cache identity, never logged. */
    public function generationCacheDefaults(array $config): array
    {
        return ['system_prompt' => $this->resolveSystemPrompt($config), 'max_tokens' => $this->defaultMaxTokens];
    }

    /**
     * Resolve system prompt with fallback chain
     *
     * Priority: config parameter → database default → hardcoded fallback
     *
     * @param  array  $config  Configuration array that may contain 'system_prompt'
     * @return string The resolved system prompt
     */
    private function resolveSystemPrompt(array $config = []): string
    {
        // Check if system_prompt is in config
        if (! empty($config['system_prompt'])) {
            return $config['system_prompt'];
        }

        // Try to get from database
        try {
            $row = DB::selectOne('SELECT config_value FROM system_configs WHERE section = ? AND config_key = ?', ['ai_settings', 'default_system_prompt']);

            if ($row && $row->config_value) {
                return $row->config_value;
            }
        } catch (Exception $e) {
            Log::warning('Could not fetch default_system_prompt from database', [
                'error' => $e->getMessage(),
            ]);
        }

        // Hardcoded fallback
        return 'You are the operator\'s personal AI assistant. Be concise and direct — answer the question first. No filler, no disclaimers. Never fabricate facts or sources — say "I don\'t know" over guessing.';
    }

    /**
     * Build a tool-aware system prompt that informs the LLM about available capabilities
     *
     * This helps Ollama with direct tool access and guidance-mode clients.
     * understand what tools are available and when to use them.
     *
     * @param  string  $basePrompt  The base system prompt
     * @param  bool  $includeToolGuidance  Whether to include tool usage guidance
     * @return string Enhanced system prompt
     */
    public function buildToolAwarePrompt(string $basePrompt = '', bool $includeToolGuidance = true): string
    {
        // Personal AI chat — direct, no filler, concise communication style
        $prompt = "You are the operator's personal AI assistant in PLOS (Personal Life OS). "
            .'Be direct and concise. No disclaimers, no filler phrases, no trailing questions. '
            .'Answer the question first. Accuracy is mandatory — never fabricate facts, sources, or data. '
            ."Say \"I don't know\" over guessing. Today: ".now()->format('Y-m-d').".\n";

        if (! empty($basePrompt)) {
            $prompt .= "\n".$basePrompt;
        }

        if (! $includeToolGuidance) {
            return $prompt;
        }

        // Build tool guide from available MCP servers
        $tools = $this->mcpRouter->getAvailableTools();
        $toolsByServer = collect($tools)->groupBy('server');

        $toolGuide = "\n## Tools\n\n";
        $toolGuide .= "**Web** (external info, current events, general knowledge):\n";

        if ($toolsByServer->has('web-research')) {
            $toolGuide .= "- `web_search` — internet search | `scrape_page` — fetch URL | `discover_sources` — find sources\n";
        }

        $toolGuide .= "\n**Local** (your files, notes, calendar, email, contacts):\n";

        $localTools = [];
        if ($toolsByServer->has('rag')) {
            $localTools[] = '`rag_search` — knowledge base';
        }
        if ($toolsByServer->has('joplin-files')) {
            $localTools[] = '`joplin_search` / `joplin_get_note` — notes';
        }
        if ($toolsByServer->has('nextcloud-calendar')) {
            $localTools[] = '`get_calendar_events` — calendar';
        }
        if ($toolsByServer->has('nextcloud-contacts')) {
            $localTools[] = '`search_contacts` — contacts';
        }
        if ($toolsByServer->has('thunderbird')) {
            $localTools[] = '`searchMessages` — email';
        }
        if ($toolsByServer->has('nextcloud-files')) {
            $localTools[] = '`search-files` / `download-file` — files';
        }
        if ($toolsByServer->has('plos')) {
            $localTools[] = '`workflow_run` / `system_diagnostics` — system';
        }

        $toolGuide .= '- '.implode("\n- ", $localTools)."\n";
        $toolGuide .= "\nUse web tools for external queries, local tools for personal data. Summarize results naturally.\n";

        return $prompt.$toolGuide;
    }

    /**
     * Detect which MCP servers are likely needed based on the query
     *
     * This improves tool calling reliability by limiting tools to relevant ones.
     * llama3.1 works much better with ~5-10 tools than 60.
     */
    public function detectRelevantToolServers(string $prompt): array
    {
        $lower = strtolower($prompt);
        $servers = [];

        // Web search / external information patterns
        $webPatterns = [
            'search', 'look up', 'find out', 'what is', 'how does', 'how do',
            'tell me about', 'explain', 'current', 'latest', 'news', 'recent',
            'who is', 'when did', 'where is', 'information about', 'learn about',
            'research', 'investigate', 'discover',
        ];
        foreach ($webPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                $servers[] = 'web-research';
                break;
            }
        }

        // If asking about something external and no local data patterns, add web-research
        if (empty($servers) && ! $this->hasLocalDataPattern($lower)) {
            // General knowledge question likely needs web search
            $servers[] = 'web-research';
        }

        // Calendar patterns
        if (preg_match('/calendar|schedule|event|appointment|meeting|busy|free|agenda/', $lower)) {
            $servers[] = 'nextcloud-calendar';
        }

        // Contact patterns
        if (preg_match('/contact|phone|email address|address book|reach|call/', $lower)) {
            $servers[] = 'nextcloud-contacts';
        }

        // File/document patterns
        if (preg_match('/file|document|folder|upload|download|nextcloud|share/', $lower)) {
            $servers[] = 'nextcloud-files';
        }

        // Notes/Joplin patterns
        if (preg_match('/note|notebook|joplin|written|saved note/', $lower)) {
            $servers[] = 'joplin-files';
        }

        // Email patterns
        if (preg_match('/\bemail\b|inbox|message|reply|draft|thunderbird/', $lower)) {
            $servers[] = 'thunderbird';
        }

        // Workflow patterns
        if (preg_match('/workflow|automation|run|execute|trigger|schedule/', $lower)) {
            $servers[] = 'plos';
        }

        // RAG/knowledge base patterns
        if (preg_match('/my documents|my files|indexed|knowledge base|remember|stored/', $lower)) {
            $servers[] = 'rag';
        }

        // Always include RAG for potential context (lightweight)
        if (! in_array('rag', $servers)) {
            $servers[] = 'rag';
        }

        return array_unique($servers);
    }

    /**
     * Check if query has patterns suggesting local/personal data access
     */
    protected function hasLocalDataPattern(string $lower): bool
    {
        $localPatterns = [
            'my ', 'mine', 'schedule', 'calendar', 'contact', 'email', 'inbox',
            'file', 'document', 'note', 'notebook', 'workflow', 'joplin',
            'nextcloud', 'appointment', 'meeting',
        ];

        foreach ($localPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize OpenAI-compatible content payloads into a plain string.
     *
     * Some providers return `message.content` as an array of content parts rather
     * than a single text string. Collapse the text-bearing parts so downstream
     * callers always receive stable string output.
     */
    protected function normalizeOpenAICompatibleContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $part) {
                $text = $this->extractOpenAICompatibleContentPartText($part);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return trim(implode("\n", $parts));
        }

        return trim((string) $content);
    }

    protected function extractOpenAICompatibleContentPartText(mixed $part): string
    {
        if (is_string($part)) {
            return trim($part);
        }

        if (! is_array($part)) {
            return '';
        }

        if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
            return trim($part['text']);
        }

        if (is_string($part['content'] ?? null)) {
            return trim($part['content']);
        }

        return '';
    }

    /**
     * Extract natural language from LLM response that may be incorrectly formatted as JSON
     *
     * When Ollama is in tool-calling mode, it sometimes returns JSON structures
     * even when no tools are needed. This method attempts to extract the actual
     * natural language answer from such responses.
     */
    protected function extractNaturalLanguageFromResponse(string $content): string
    {
        $trimmed = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches) === 1) {
            $trimmed = trim((string) ($matches[1] ?? ''));
        }

        // Check if response looks like JSON
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return $trimmed !== '' ? $trimmed : $content; // Already natural language
        }

        try {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return $content; // Not valid JSON, return as-is
            }

            // Common patterns when LLM outputs JSON incorrectly:
            // {"output": "..."} or {"outputs": "..."}
            // {"answer": "..."} or {"response": "..."}
            // {"result": {"answer": "..."}}
            // {"inputs": "...", "outputs": "..."}

            $textKeys = ['output', 'outputs', 'answer', 'response', 'text', 'content', 'message', 'result'];

            foreach ($textKeys as $key) {
                if (isset($decoded[$key])) {
                    $value = $decoded[$key];

                    // If it's a nested JSON string, try to decode it
                    if (is_string($value) && str_starts_with(trim($value), '{')) {
                        $nested = json_decode($value, true);
                        if (is_array($nested)) {
                            // Recursively extract from nested structure
                            foreach ($textKeys as $nestedKey) {
                                if (isset($nested[$nestedKey]) && is_string($nested[$nestedKey])) {
                                    Log::info('Extracted natural language from nested JSON response', [
                                        'outer_key' => $key,
                                        'inner_key' => $nestedKey,
                                    ]);

                                    return $nested[$nestedKey];
                                }
                            }
                        }
                    }

                    // Direct string value
                    if (is_string($value) && ! empty($value)) {
                        Log::info('Extracted natural language from JSON response', ['key' => $key]);

                        return $value;
                    }

                    // Nested object with answer
                    if (is_array($value)) {
                        foreach ($textKeys as $innerKey) {
                            if (isset($value[$innerKey]) && is_string($value[$innerKey])) {
                                Log::info('Extracted natural language from nested JSON response', [
                                    'outer_key' => $key,
                                    'inner_key' => $innerKey,
                                ]);

                                return $value[$innerKey];
                            }
                        }
                    }
                }
            }

            // Check for "inputs"/"outputs" pattern (common with llama)
            if (isset($decoded['outputs']) && is_string($decoded['outputs'])) {
                Log::info('Extracted natural language from inputs/outputs JSON');

                return $decoded['outputs'];
            }

            // Last resort: if only one string value exists, use it
            $stringValues = array_filter($decoded, 'is_string');
            if (count($stringValues) === 1) {
                Log::info('Extracted single string value from JSON');

                return reset($stringValues);
            }

            // Check for malformed tool-calling response patterns
            // {"inputs": "...", "expected_output_format": "json"} means LLM didn't answer
            if (isset($decoded['inputs']) && isset($decoded['expected_output_format'])) {
                Log::warning('LLM returned tool-format metadata instead of answer - falling back to simple query');

                return '__RETRY_WITHOUT_TOOLS__';
            }

            // Could not extract, return original (will still be JSON but we tried)
            Log::warning('Could not extract natural language from JSON response', [
                'keys' => array_keys($decoded),
            ]);

            return $content;

        } catch (Exception $e) {
            Log::warning('Error parsing potential JSON response', ['error' => $e->getMessage()]);

            return $content;
        }
    }

    /**
     * Process text with a selected AI provider.
     */
    public function processWithAI(string $prompt, array $config = []): string
    {
        // Sanitize at entry point — prevents json_encode failures deep in Ollama calls
        $prompt = $this->sanitizeUtf8($prompt);
        if (isset($config['system_prompt'])) {
            $config['system_prompt'] = $this->sanitizeUtf8($config['system_prompt']);
        }

        $mode = $config['ai_mode'] ?? config('services.ai.default_mode', 'auto');
        $temperature = $config['temperature'] ?? 0.1;
        $maxTokens = $config['max_tokens'] ?? $this->defaultMaxTokens;
        $systemPrompt = $this->resolveSystemPrompt($config);

        // Support timeout override from node config (ai_timeout or timeout)
        $timeout = $config['ai_timeout'] ?? $config['timeout'] ?? $this->timeout;
        if (is_string($timeout)) {
            $timeout = (int) $timeout;
        }

        // Support per-instance URL and model override from AIService multi-instance routing
        $instanceUrl = $config['ollama_url'] ?? $config['instance_url'] ?? null;
        $instanceModel = $config['ollama_model'] ?? $config['instance_model'] ?? null;

        // External API mode — called directly by AIService with full provider config
        if ($mode === 'external_api') {
            return $this->callOpenAICompatible($prompt, $config);
        }

        try {
            if ($mode === 'local' || $mode === 'auto') {
                return $this->callOllama($prompt, $temperature, $maxTokens, $systemPrompt, $timeout, $instanceUrl, $instanceModel, $config);
            }
        } catch (Exception $e) {
            throw $e;
        }

        throw new Exception("Unsupported AI mode: {$mode}");
    }

    /**
     * Process an image with a local Ollama vision model.
     *
     * @param  string  $imageContent  Binary image content OR file path
     * @param  string  $prompt  The prompt/question about the image
     * @param  array  $config  Configuration options (temperature, max_tokens, etc.)
     * @return string AI analysis/description of the image
     */
    public function processWithImage(string $imageContent, string $prompt, array $config = []): string
    {
        $mode = $config['ai_mode'] ?? config('services.ai.default_mode', 'auto');
        $temperature = $config['temperature'] ?? 0.3;
        $maxTokens = $config['max_tokens'] ?? $this->defaultMaxTokens;
        $timeout = $config['timeout'] ?? $this->timeout;

        // Per-instance routing: AIService passes instance_url/instance_model when
        // it has selected a specific Ollama instance from the pool (e.g. the 4070
        // secondary vs the 1060 primary). Fall back to constructor-bound primary.
        $instanceUrl = $config['instance_url'] ?? null;
        $instanceModel = $config['instance_model'] ?? null;

        // If imageContent is a file path, read the file
        $isFilePath = ! str_contains($imageContent, "\0") && file_exists($imageContent);
        if ($isFilePath) {
            $imagePath = $imageContent;
            $imageContent = file_get_contents($imageContent);
            if ($imageContent === false) {
                throw new Exception("Failed to read image file: {$imagePath}");
            }
        } else {
            $imagePath = null;
        }

        // Encode image to base64
        $base64Image = base64_encode($imageContent);

        try {
            if ($mode === 'local' || $mode === 'auto') {
                return $this->callOllamaVision($base64Image, $prompt, $temperature, $maxTokens, $timeout, $instanceUrl, $instanceModel, $config);
            }
        } catch (Exception $e) {
            throw $e;
        }

        throw new Exception("Unsupported vision AI mode: {$mode}");
    }

    /**
     * Call Ollama vision model (llava) with base64-encoded image
     */
    private function callOllamaVision(string $base64Image, string $prompt, float $temperature, int $maxTokens, int $timeout, ?string $instanceUrl = null, ?string $instanceModel = null, array $config = []): string
    {
        $targetUrl = $instanceUrl ?? $this->ollamaUrl;
        $targetModel = $instanceModel ?? $this->visionModel;
        $payload = self::ollamaGenerationSettings($targetModel, $temperature, $maxTokens, $config)
            + ['prompt' => $prompt, 'images' => [$base64Image]];
        // This endpoint consumes a single JSON response, while keeping the exact role options.
        $payload['stream'] = false;
        if (! AIService::hasManagedInferenceContract() && $this->controllerManaged("{$targetUrl}/api/generate")) {
            return app(ModelHostControllerTransportSupervisor::class)->run('ollama_generate', "{$targetUrl}/api/generate", $payload,
                fn () => $this->callOllamaVision($base64Image, $prompt, $temperature, $maxTokens, $timeout, $instanceUrl, $instanceModel, $config));
        }

        Log::info('Calling Ollama vision model', [
            'model' => $targetModel,
            'host' => $targetUrl,
            'prompt_length' => strlen($prompt),
            'image_size_kb' => round(strlen($base64Image) * 3 / 4 / 1024, 2), // Approximate decoded size
        ]);

        // Ensure vision model is loaded before making request
        // This prevents timeout on first request while model loads
        // llava:7b typically takes 50-150 seconds to load from disk (depends on Ollama's
        // memory management - it may need to unload llama3.1 first)
        if (! AIService::hasManagedInferenceContract() && ! $this->ensureVisionModelLoaded(180, $instanceUrl, $targetModel, PoolExecutionReceipt::fromConfig($config))) {
            throw new Exception('Vision model failed to load within timeout');
        }

        // Use Guzzle directly - Laravel's Http facade doesn't handle long timeouts properly
        $client = PipelineHttpClientFactory::make([
            'timeout' => $timeout,
            'connect_timeout' => 10,
        ]);

        $this->assertControllerTransport('ollama_generate', "{$targetUrl}/api/generate", $payload);
        $receipt = PoolExecutionReceipt::fromConfig($config);
        $options = AIService::inferenceProgressOptions(['json' => $payload]);
        if ($receipt !== null) {
            $options = $receipt->transportOptions($options);
            $receipt->beforeSend('ollama_generate', "{$targetUrl}/api/generate", $targetModel);
        }
        $response = $client->request('POST', "{$targetUrl}/api/generate", $options);

        if ($response->getStatusCode() !== 200) {
            throw new Exception('Ollama vision API failed: '.$response->getStatusCode());
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $receipt?->ollamaTerminal(is_array($data) ? $data : []);
        $result = $data['response'] ?? null;

        if (! $result) {
            throw new Exception('Invalid Ollama vision response format');
        }

        Log::info('Ollama vision completed', [
            'response_length' => strlen($result),
        ]);

        return $result;
    }

    /**
     * Detect image format from binary content
     */
    private function detectImageExtension(string $content): string
    {
        $signatures = [
            'png' => "\x89PNG",
            'jpg' => "\xFF\xD8\xFF",
            'gif' => 'GIF8',
            'webp' => 'RIFF',
            'bmp' => 'BM',
        ];

        foreach ($signatures as $ext => $sig) {
            if (str_starts_with($content, $sig)) {
                return $ext;
            }
        }

        return 'jpg'; // Default to jpg if unknown
    }

    /**
     * Check if binary data starts with a known image file signature
     */
    private function isBinaryImageData(string $data): bool
    {
        $signatures = ["\x89PNG", "\xFF\xD8\xFF", 'GIF8', 'RIFF', 'BM'];
        foreach ($signatures as $sig) {
            if (str_starts_with($data, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if vision model is available
     */
    public function isVisionAvailable(): bool
    {
        try {
            $response = Http::connectTimeout(5)->timeout(5)->get("{$this->ollamaUrl}/api/tags");
            if (! $response->successful()) {
                return false;
            }

            $models = $response->json('models') ?? [];
            foreach ($models as $model) {
                if (str_starts_with($model['name'] ?? '', 'llava')) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if a specific model is currently loaded in Ollama memory
     */
    public function isModelLoaded(string $modelName, ?string $instanceUrl = null): bool
    {
        $targetUrl = $instanceUrl ?? $this->ollamaUrl;
        if ($targetUrl === '' || trim($modelName) === '') {
            return false;
        }
        try {
            $response = Http::connectTimeout(5)->timeout(5)->get("{$targetUrl}/api/ps");
            if (! $response->successful()) {
                return false;
            }

            $runningModels = $response->json('models') ?? [];
            foreach ($runningModels as $model) {
                if (str_starts_with($model['name'] ?? '', $modelName) ||
                    str_starts_with($model['model'] ?? '', $modelName)) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            Log::debug('Failed to check running models', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Ensure a model is loaded in Ollama, waiting for it to load if necessary
     *
     * @param  string  $modelName  Model name to load (e.g., 'llava:7b')
     * @param  int  $timeoutSeconds  Maximum time to wait for model to load
     * @return bool True if model is loaded and ready
     */
    public function ensureModelLoaded(string $modelName, int $timeoutSeconds = 60, ?string $instanceUrl = null, ?PoolExecutionReceipt $receipt = null): bool
    {
        $targetUrl = $instanceUrl ?? $this->ollamaUrl;
        if ($targetUrl === '' || trim($modelName) === '') {
            return false;
        }
        if ($this->controllerManaged("{$targetUrl}/api/generate")) {
            // The owning controller activates models; this helper may only observe them.
            return $this->isModelLoaded($modelName, $targetUrl);
        }

        // Check if already loaded
        if ($this->isModelLoaded($modelName, $targetUrl)) {
            Log::debug('Model already loaded', ['model' => $modelName, 'host' => $targetUrl]);

            return true;
        }

        Log::info('Model not loaded, triggering load', ['model' => $modelName, 'host' => $targetUrl]);

        // Send a minimal request to trigger model loading
        // Using /api/generate with a tiny prompt forces Ollama to load the model
        // We wait for the response - if it succeeds, the model is loaded and ready
        $startTime = time();

        try {
            // Give the model load request enough time to complete
            // llava typically needs 30-150 seconds to load from disk to GPU
            $loadTimeout = min($timeoutSeconds, 180);

            // Use Guzzle directly - Laravel's Http facade doesn't properly handle
            // long timeouts for slow model loading operations
            $client = PipelineHttpClientFactory::make([
                'timeout' => $loadTimeout,
                'connect_timeout' => 10,
            ]);

            $options = [
                'json' => [
                    'model' => $modelName,
                    'prompt' => 'hi',
                    'think' => false,
                    'stream' => false,
                    'options' => [
                        'num_predict' => 1, // Minimal output
                    ],
                ],
            ];
            if ($receipt !== null) {
                $options = $receipt->transportOptions($options);
                $receipt->beforeSend('ollama_generate', "{$targetUrl}/api/generate", $modelName);
            }
            $response = $client->request('POST', "{$targetUrl}/api/generate", $options);

            // If the request succeeded, the model is loaded and responded
            // Don't trust /api/ps - Ollama may unload immediately after
            if ($response->getStatusCode() === 200) {
                if ($receipt !== null) {
                    $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
                    $receipt->ollamaTerminal(is_array($data) ? $data : []);
                }
                $elapsed = time() - $startTime;
                Log::info('Model loaded and ready', [
                    'model' => $modelName,
                    'load_time_seconds' => $elapsed,
                ]);

                return true;
            }

            $receipt?->stopReplayAfterFailure();
            // Request failed but didn't timeout - model issue
            Log::warning('Model load request failed', [
                'model' => $modelName,
                'status' => $response->getStatusCode(),
            ]);

            return false;

        } catch (RequestException $e) {
            $receipt?->stopReplayAfterFailure($e);
            $elapsed = time() - $startTime;
            Log::warning('Model load request error', [
                'model' => $modelName,
                'error' => $e->getMessage(),
                'elapsed' => $elapsed,
            ]);

            return false;
        } catch (Exception $e) {
            $receipt?->stopReplayAfterFailure($e);
            $elapsed = time() - $startTime;
            Log::warning('Model load failed', [
                'model' => $modelName,
                'error' => $e->getMessage(),
                'elapsed' => $elapsed,
            ]);

            return false;
        }
    }

    /**
     * Ensure vision model is loaded and ready
     *
     * @param  int  $timeoutSeconds  Maximum time to wait
     * @return bool True if vision model is ready
     */
    public function ensureVisionModelLoaded(int $timeoutSeconds = 60, ?string $instanceUrl = null, ?string $instanceModel = null, ?PoolExecutionReceipt $receipt = null): bool
    {
        $targetModel = $instanceModel ?? $this->visionModel;

        return $this->ensureModelLoaded($targetModel, $timeoutSeconds, $instanceUrl, $receipt);
    }

    /**
     * Sanitize text to valid UTF-8, removing or replacing invalid characters
     */
    private function sanitizeUtf8(string $text): string
    {
        // Remove null bytes
        $text = str_replace("\0", '', $text);

        // Convert to UTF-8 if not already valid
        if (! mb_check_encoding($text, 'UTF-8')) {
            // Try to convert from various encodings
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
        }

        // Remove any remaining invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Replace problematic control characters (keep newlines, tabs)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return $text;
    }

    /**
     * Generate text embedding vector for RAG
     */
    public function generateEmbedding(string $text, ?string $instanceUrl = null, ?int $charLimit = null, ?int $timeoutSeconds = null, ?string $modelOverride = null, bool $preserveInput = true): array
    {
        try {
            $url = $instanceUrl ?? $this->ollamaUrl;
            $managed = $this->controllerManaged($url.'/api/embed');
            $preserveInput = $preserveInput || $managed || ! PromptIntegrityPolicy::allowsLossyTransforms();
            // The complete-input policy also covers legacy callers and unmanaged hosts.
            if ($preserveInput) {
                if (! mb_check_encoding($text, 'UTF-8')) {
                    throw new InferenceAdmissionException('Embedding input must be valid UTF-8; original bytes were not modified.');
                }
            } else {
                $text = $this->sanitizeUtf8($text);
            }

            // Hard safety net: truncate to embedding model context limit
            $embeddingCharLimit = $charLimit ?? $this->embeddingCharLimit;
            if (! $preserveInput && strlen($text) > $embeddingCharLimit) {
                $text = substr($text, 0, $embeddingCharLimit);
                $lastSpace = strrpos($text, ' ');
                if ($lastSpace !== false && $lastSpace > $embeddingCharLimit * 0.8) {
                    $text = substr($text, 0, $lastSpace);
                }
            }

            // Embeddings are fast (~100ms) — use short timeout, not the text generation timeout.
            // Text gen timeout ($this->timeout) is 180-300s which causes blocking stalls.
            $embeddingTimeout = $timeoutSeconds ?? (int) config('services.ollama.embedding_timeout', 10);
            // /api/embed explicitly rejects context overflow with truncate=false.
            // Keep the legacy endpoint for callers that have not requested preservation.
            $endpoint = $preserveInput ? '/api/embed' : '/api/embeddings';
            $payload = ['model' => $modelOverride ?? $this->embeddingModel]
                + ($preserveInput ? ['input' => $text, 'truncate' => false] : ['prompt' => $text]);
            $supervisor = app(ModelHostControllerTransportSupervisor::class);
            if ($managed) {
                $endpoint = '/api/embed';
                $payload = $supervisor->embeddingPayload($url.$endpoint, $payload['model'], $text);
            }

            return $supervisor->run('ollama_embeddings', $url.$endpoint, $payload, function () use ($url, $endpoint, $payload, $embeddingTimeout): array {
                $this->assertControllerTransport('ollama_embeddings', $url.$endpoint, $payload);
                $response = AIService::maintainInferenceHttp(Http::withOptions([
                    'timeout' => $embeddingTimeout,
                    'connect_timeout' => 5,
                ]))->post($url.$endpoint, $payload);
                if (! $response->successful()) {
                    throw new Exception('Ollama embedding request failed.');
                }
                $embedding = $response->json($endpoint === '/api/embed' ? 'embeddings.0' : 'embedding');
                $this->assertEmbeddingVector($embedding);

                return $embedding;
            });
        } catch (Exception $e) {
            // Context length overflows are handled by AIService chunked fallback — log as debug
            $level = str_contains($e->getMessage(), 'context length') ? 'debug' : 'error';
            Log::$level('Embedding generation failed', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);
            throw $e;
        }
    }

    /**
     * Generate embedding via external OpenAI-compatible API (/v1/embeddings endpoint).
     * Works with Gemini, DeepInfra, Mistral, OpenAI, and other compatible providers.
     *
     * @param  string  $text  The text to embed
     * @param  array  $providerConfig  Provider config: base_url, api_key, embedding_model, extra_headers, instance_id
     * @return array Float array of embedding dimensions
     *
     * @throws Exception On API failure
     */
    public function generateExternalEmbedding(string $text, array $providerConfig): array
    {
        $baseUrl = rtrim($providerConfig['base_url'], '/');
        if (! empty($providerConfig['preserve_input']) || $this->controllerManaged($baseUrl.'/embeddings')) {
            if (! mb_check_encoding($text, 'UTF-8')) {
                throw new InferenceAdmissionException('Embedding input must be valid UTF-8; original bytes were not modified.');
            }
        } else {
            $text = $this->sanitizeUtf8($text);
        }

        $apiKey = $providerConfig['api_key'] ?? '';
        $model = $providerConfig['embedding_model'];
        $extraHeaders = $providerConfig['extra_headers'] ?? [];
        $dimensions = $providerConfig['embedding_dimensions'] ?? null;

        $headers = array_merge([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ], $extraHeaders);

        $body = [
            'model' => $model,
            'input' => $text,
        ];

        // Some providers (Gemini) support output_dimensionality to control dimensions
        if ($dimensions) {
            $body['dimensions'] = (int) $dimensions;
        }

        return app(ModelHostControllerTransportSupervisor::class)->run('openai_embeddings', "{$baseUrl}/embeddings", $body, function () use ($baseUrl, $headers, $body, $providerConfig, $model, $dimensions): array {
            $this->assertControllerTransport('openai_embeddings', "{$baseUrl}/embeddings", $body);
            $response = AIService::maintainInferenceHttp(Http::withHeaders($headers)
                ->timeout(30)
                ->connectTimeout(5))
                ->post("{$baseUrl}/embeddings", $body);

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');
                $retryAfterSeconds = $this->circuitBreakerPolicy()->retryAfterSeconds($retryAfter);
                throw new RateLimitException(
                    "Embedding rate limited by {$baseUrl} (retry after {$retryAfterSeconds}s)",
                    $providerConfig['instance_id'] ?? 'external_embedding',
                    $model,
                    429,
                    $retryAfterSeconds * 1000
                );
            }

            if (! $response->successful()) {
                throw AIExceptionFactory::fromHttpResponse(
                    $response,
                    $providerConfig['instance_id'] ?? 'external_embedding',
                    $model
                );
            }

            $data = $response->json();

            // Standard OpenAI embeddings response: data[0].embedding
            $embedding = $data['data'][0]['embedding'] ?? null;

            $this->assertEmbeddingVector($embedding, $dimensions);

            return $embedding;
        });
    }

    private function assertEmbeddingVector(mixed $embedding, ?int $dimensions = null): void
    {
        if (! is_array($embedding) || ! array_is_list($embedding) || $embedding === []
            || ($dimensions !== null && count($embedding) !== $dimensions)) {
            throw new Exception('Embedding response has an invalid shape.');
        }
        foreach ($embedding as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new Exception('Embedding response contains a non-finite or non-numeric value.');
            }
        }
    }

    /**
     * Call Ollama directly with fault tolerance (offline-first, fast)
     */
    /** Content-free settings shared by capacity preparation and the actual send. */
    public static function ollamaGenerationSettings(string $model, float $temperature, int $maxTokens, array $config): array
    {
        if (array_key_exists('ollama_keep_alive', $config)
            && (! is_int($config['ollama_keep_alive']) || $config['ollama_keep_alive'] < 0)) {
            throw new \InvalidArgumentException('Ollama keep_alive must be a non-negative integer number of seconds.');
        }
        $settings = [
            'model' => $model,
            'think' => $config['ollama_think'] ?? false,
            'temperature' => $temperature,
            'stream' => filter_var($config['_monitor_ollama_progress'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'options' => array_merge([
                'num_predict' => $maxTokens, 'temperature' => $temperature,
                'top_p' => 0.9, 'num_ctx' => (int) ($config['num_ctx'] ?? 4096),
            ], is_array($config['ollama_options'] ?? null) ? $config['ollama_options'] : []),
        ];
        if (array_key_exists('ollama_keep_alive', $config)) {
            $settings['keep_alive'] = $config['ollama_keep_alive'];
        }
        $schema = is_string($config['ollama_output_schema'] ?? null) ? trim($config['ollama_output_schema']) : '';
        if ($schema !== '') {
            $settings['format'] = app(OllamaStructuredOutputSchemaService::class)->resolve($schema);
        }

        return $settings;
    }

    public static function openAITextSettings(string $model, int $maxTokens, float $temperature, ?array $responseFormat = null): array
    {
        $settings = ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => $temperature];
        if ($responseFormat !== null) {
            $settings['response_format'] = $responseFormat;
        }

        return $settings;
    }

    private function callOllama(string $prompt, float $temperature, int $maxTokens, string $systemPrompt = '', ?int $timeout = null, ?string $instanceUrl = null, ?string $instanceModel = null, array $config = []): string
    {
        // Defence-in-depth: sanitize here too in case callOllama is invoked directly
        $prompt = $this->sanitizeUtf8($prompt);
        $systemPrompt = $this->sanitizeUtf8($systemPrompt);

        $retryService = app(RetryService::class);
        $circuitBreaker = app(CircuitBreaker::class);
        $timeoutManager = app(TimeoutManager::class);

        // Use instance-specific URL/model if provided by AIService multi-instance routing
        $targetUrl = $instanceUrl ?? $this->ollamaUrl;
        $targetModel = $instanceModel ?? $this->ollamaModel;

        // Use adaptive timeout if available, otherwise provided or default
        $requestTimeout = $timeoutManager->getTimeout('ai_processing', $timeout ?? $this->timeout);

        $settings = self::ollamaGenerationSettings($targetModel, $temperature, $maxTokens, $config);
        if (! AIService::hasManagedInferenceContract() && app(ModelHostControllerResourcePolicyService::class)->managedProvider("{$targetUrl}/api/generate") !== null) {
            return app(ModelHostControllerTransportSupervisor::class)->run(
                'ollama_generate', "{$targetUrl}/api/generate", $settings,
                fn () => $this->callOllama($prompt, $temperature, $maxTokens, $systemPrompt, $timeout, $instanceUrl, $instanceModel, $config));
        }
        AIService::assertInferenceTransport('ollama_generate', "{$targetUrl}/api/generate", $settings);
        $startTime = microtime(true);

        try {
            // Circuit breaker protection — use instance-specific key when routing to specific instance
            $circuitKey = $instanceUrl ? 'ollama_api_'.md5($instanceUrl) : 'ollama_api';
            $response = $circuitBreaker->call($circuitKey, function () use ($retryService, $prompt, $systemPrompt, $requestTimeout, $targetUrl, $config, $settings) {
                // Retry logic with fixed backoff (AI calls are expensive)
                return $retryService->retry(
                    operation: function () use ($prompt, $systemPrompt, $requestTimeout, $targetUrl, $config, $settings) {
                        // Override PHP's default_socket_timeout
                        $originalSocketTimeout = ini_get('default_socket_timeout');
                        ini_set('default_socket_timeout', (string) $requestTimeout);

                        $userPrompt = $prompt;
                        if (isset($config['ollama_prompt_prefix']) && is_string($config['ollama_prompt_prefix']) && trim($config['ollama_prompt_prefix']) !== '') {
                            $userPrompt = rtrim($config['ollama_prompt_prefix'])."\n".$userPrompt;
                        }
                        if (isset($config['ollama_prompt_suffix']) && is_string($config['ollama_prompt_suffix']) && trim($config['ollama_prompt_suffix']) !== '') {
                            $userPrompt .= "\n".ltrim($config['ollama_prompt_suffix']);
                        }

                        // Prepend system prompt since /api/generate doesn't support system role
                        $fullPrompt = $systemPrompt
                            ? "System: {$systemPrompt}\n\nUser: {$userPrompt}"
                            : $userPrompt;

                        $schemaId = is_string($config['ollama_output_schema'] ?? null)
                            ? trim($config['ollama_output_schema']) : '';
                        $requestPayload = [...$settings, 'prompt' => $fullPrompt];

                        try {
                            if ($settings['stream']) {
                                $monitored = app(OllamaProgressClient::class)->generate(
                                    $targetUrl,
                                    $requestPayload,
                                    [
                                        '_pool_execution_receipt' => PoolExecutionReceipt::fromConfig($config),
                                        'connect_timeout_seconds' => 5,
                                        'idle_timeout_seconds' => $config['ollama_progress_idle_timeout_seconds'] ?? 600,
                                        'absolute_timeout_seconds' => $config['ollama_absolute_timeout_seconds'] ?? 0,
                                        'log_interval_seconds' => $config['ollama_progress_log_interval_seconds'] ?? 30,
                                    ]
                                );
                                $result = $monitored['response'];
                            } else {
                                $receipt = PoolExecutionReceipt::fromConfig($config);
                                $request = AIService::maintainInferenceHttp(Http::withOptions([
                                    'http_version' => '1.1',
                                    'timeout' => $requestTimeout,
                                    'connect_timeout' => 5,
                                ]));
                                if ($receipt !== null) {
                                    $request = $receipt->transportHttp($request);
                                    $receipt->beforeSend('ollama_generate', "{$targetUrl}/api/generate", (string) $requestPayload['model']);
                                }
                                $response = $request->post("{$targetUrl}/api/generate", $requestPayload);

                                if (! $response->successful()) {
                                    throw AIExceptionFactory::fromHttpResponse($response, 'ollama', $this->ollamaModel);
                                }

                                $data = $response->json();
                                $receipt?->ollamaTerminal(is_array($data) ? $data : []);
                                $result = $data['response'] ?? throw new Exception('Invalid Ollama response format');
                            }
                        } finally {
                            ini_set('default_socket_timeout', $originalSocketTimeout);
                        }

                        $result = $this->stripOllamaThinkingLeak($result);

                        if ($schemaId !== ''
                            && ! app(OllamaStructuredOutputSchemaService::class)->validateResponse($schemaId, $result)
                            && ($config['_structured_output_eval_probe_admitted'] ?? false) !== true) {
                            throw new Exception("Ollama structured output failed local schema validation: {$schemaId}");
                        }

                        return $result;
                    },
                    maxAttempts: AIService::inferenceReplayAllowed() ? 2 : 1,
                    backoffStrategy: 'fixed',
                    baseDelay: 2000, // 2s fixed delay
                    shouldRetry: function (Exception $e) use ($config) {
                        PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure($e);
                        // Use typed exception for retry decisions
                        if ($e instanceof TransientException) {
                            return $e->isRetryable();
                        }
                        // Fallback: string matching for non-typed exceptions
                        $message = strtolower($e->getMessage());

                        return str_contains($message, 'timeout') ||
                               str_contains($message, 'timed out') ||
                               str_contains($message, 'operation too slow') ||
                               str_contains($message, 'incomplete ollama monitored generation stream') ||
                               str_contains($message, 'connection refused') ||
                               str_contains($message, 'connection reset') ||
                               str_contains($message, 'could not connect') ||
                               str_contains($message, 'operation timed out');
                    },
                    operationName: 'Ollama API Call'
                );
            });

            // Record successful execution
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('ai_processing', $duration, true);

            return $response;

        } catch (InferenceAdmissionException $e) {
            throw $e;
        } catch (Exception $e) {
            // Record failed execution
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('ai_processing', $duration, false);

            // Re-throw to trigger the configured fallback chain.
            throw $e;
        }
    }

    private function enabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Call any OpenAI-compatible API (Groq, OpenRouter, Mistral, Together.ai, etc.)
     *
     * Uses the standard OpenAI chat completions format:
     * POST {base_url}/chat/completions
     *
     * @param  string  $prompt  The user prompt
     * @param  array  $providerConfig  Provider config from llm_instances table:
     *                                 - base_url: API endpoint base (e.g. https://api.groq.com/openai/v1)
     *                                 - api_key: Resolved API key value
     *                                 - model: Model identifier (e.g. llama-3.3-70b-versatile)
     *                                 - instance_id: For circuit breaker keying
     *                                 - max_tokens: Optional override
     *                                 - temperature: Optional override
     *                                 - system_prompt: Optional system prompt
     *                                 - timeout: Optional timeout in seconds
     *                                 - extra_headers: Optional additional headers (e.g. OpenRouter requires HTTP-Referer)
     * @return string The response text
     */
    public function callOpenAICompatible(string $prompt, array $providerConfig): string
    {
        $retryService = app(RetryService::class);
        $circuitBreaker = app(CircuitBreaker::class);
        $timeoutManager = app(TimeoutManager::class);

        $baseUrl = rtrim($providerConfig['base_url'], '/');
        $apiKey = $providerConfig['api_key'];
        $model = $providerConfig['model'];
        $instanceId = $providerConfig['instance_id'] ?? 'external_api';
        $maxTokens = $providerConfig['max_tokens'] ?? $this->defaultMaxTokens;
        $temperature = $providerConfig['temperature'] ?? 0.1;
        $systemPrompt = $providerConfig['system_prompt'] ?? '';
        $requestTimeout = $providerConfig['timeout'] ?? 120;
        $extraHeaders = $providerConfig['extra_headers'] ?? [];
        $schemaId = is_string($providerConfig['structured_output_schema_id'] ?? null)
            ? trim($providerConfig['structured_output_schema_id'])
            : '';
        $structuredOutputTransport = $providerConfig['structured_output_transport'] ?? null;
        $responseFormat = null;
        if ($schemaId !== '') {
            if ($structuredOutputTransport !== 'openai_json_schema') {
                throw new Exception('OpenAI-compatible structured output transport is not admitted');
            }
            $responseFormat = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaId,
                    'strict' => true,
                    'schema' => app(OllamaStructuredOutputSchemaService::class)->resolve($schemaId),
                ],
            ];
        }

        $settings = self::openAITextSettings($model, $maxTokens, $temperature, $responseFormat);
        if (! AIService::hasManagedInferenceContract()
            && app(ModelHostControllerResourcePolicyService::class)->managedProvider("{$baseUrl}/chat/completions") !== null) {
            return app(ModelHostControllerTransportSupervisor::class)->run(
                'openai_chat_completions', "{$baseUrl}/chat/completions", $settings,
                fn () => $this->callOpenAICompatible($prompt, $providerConfig));
        }
        AIService::assertInferenceTransport('openai_chat_completions', "{$baseUrl}/chat/completions", $settings);
        $circuitKey = 'external_api_'.md5($baseUrl);
        $startTime = microtime(true);

        try {
            $response = $circuitBreaker->call($circuitKey, function () use (
                $retryService, $baseUrl, $apiKey, $model,
                $systemPrompt, $prompt, $requestTimeout, $extraHeaders, $schemaId, $settings
            ) {
                return $retryService->retry(
                    operation: function () use ($baseUrl, $apiKey, $model, $systemPrompt, $prompt, $requestTimeout, $extraHeaders, $schemaId, $settings) {
                        $messages = [];
                        if (! empty($systemPrompt)) {
                            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
                        }
                        $messages[] = ['role' => 'user', 'content' => $prompt];

                        $headers = ['Content-Type' => 'application/json'];
                        if ($apiKey !== '') {
                            $headers['Authorization'] = "Bearer {$apiKey}";
                        }
                        $headers = array_merge($headers, $extraHeaders);

                        $requestBody = [...$settings, 'messages' => $messages];

                        $response = AIService::maintainInferenceHttp(Http::withHeaders($headers)
                            ->timeout($requestTimeout)
                            ->connectTimeout(10))
                            ->post("{$baseUrl}/chat/completions", $requestBody);

                        if ($response->status() === 429) {
                            $retryAfter = $response->header('Retry-After');
                            $retryAfterSeconds = $this->circuitBreakerPolicy()->retryAfterSeconds($retryAfter);
                            throw new RateLimitException(
                                "Rate limited by {$baseUrl} (retry after {$retryAfterSeconds}s)",
                                'external_api',
                                $model,
                                429,
                                $retryAfterSeconds * 1000
                            );
                        }

                        if (! $response->successful()) {
                            $body = $response->body();
                            throw AIExceptionFactory::fromHttpResponse($response, 'external_api', $model);
                        }

                        $data = $response->json();
                        $content = $data['choices'][0]['message']['content']
                            ?? $data['choices'][0]['text']
                            ?? null;

                        if ($content === null) {
                            throw new Exception('Invalid response format from '.$baseUrl);
                        }

                        $normalized = $this->normalizeOpenAICompatibleContent($content);
                        if ($schemaId !== ''
                            && ! app(OllamaStructuredOutputSchemaService::class)->validateResponse($schemaId, $normalized)) {
                            throw new Exception('OpenAI-compatible response failed local structured-output validation');
                        }

                        return $normalized;
                    },
                    maxAttempts: AIService::inferenceReplayAllowed() ? 2 : 1,
                    backoffStrategy: 'exponential',
                    baseDelay: 1000,
                    shouldRetry: function (Exception $e) {
                        if ($e instanceof TransientException) {
                            return $e->isRetryable();
                        }
                        $msg = strtolower($e->getMessage());

                        return str_contains($msg, 'timeout') ||
                               str_contains($msg, 'rate limit') ||
                               str_contains($msg, '429') ||
                               str_contains($msg, 'connection');
                    },
                    operationName: 'OpenAI-Compatible API Call'
                );
            });

            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('external_api', $duration, true);

            return $response;

        } catch (InferenceAdmissionException $e) {
            throw $e;
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('external_api', $duration, false);
            throw $e;
        }
    }

    /**
     * Call OpenAI-compatible API with vision (base64 image + text prompt).
     * Supports OpenRouter, Gemini, Mistral pixtral, and any provider with OpenAI vision format.
     */
    public function callOpenAICompatibleVision(string $base64Image, string $prompt, array $providerConfig): string
    {
        $retryService = app(RetryService::class);
        $circuitBreaker = app(CircuitBreaker::class);
        $timeoutManager = app(TimeoutManager::class);

        $baseUrl = rtrim($providerConfig['base_url'], '/');
        $apiKey = $providerConfig['api_key'];
        $model = $providerConfig['model'];
        $instanceId = $providerConfig['instance_id'] ?? 'external_vision';
        $maxTokens = $providerConfig['max_tokens'] ?? $this->defaultMaxTokens;
        $temperature = $providerConfig['temperature'] ?? 0.3;
        $requestTimeout = $providerConfig['timeout'] ?? 120;
        $extraHeaders = $providerConfig['extra_headers'] ?? [];

        $circuitKey = 'external_vision_'.md5($baseUrl);
        $startTime = microtime(true);

        // Detect MIME type from base64 header bytes
        $mimeType = 'image/jpeg';
        $decoded = base64_decode(substr($base64Image, 0, 16), true);
        if ($decoded) {
            if (str_starts_with($decoded, "\x89PNG")) {
                $mimeType = 'image/png';
            } elseif (str_starts_with($decoded, 'GIF')) {
                $mimeType = 'image/gif';
            } elseif (str_starts_with($decoded, 'RIFF')) {
                $mimeType = 'image/webp';
            }
        }

        $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => $temperature,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64Image}"]],
            ]]]];
        if (! AIService::hasManagedInferenceContract() && $this->controllerManaged("{$baseUrl}/chat/completions")) {
            return app(ModelHostControllerTransportSupervisor::class)->run('openai_chat_completions', "{$baseUrl}/chat/completions", $payload,
                fn () => $this->callOpenAICompatibleVision($base64Image, $prompt, $providerConfig));
        }

        try {
            $response = $circuitBreaker->call($circuitKey, function () use (
                $retryService, $baseUrl, $apiKey, $model, $maxTokens, $temperature,
                $prompt, $base64Image, $mimeType, $requestTimeout, $extraHeaders
            ) {
                return $retryService->retry(
                    operation: function () use ($baseUrl, $apiKey, $model, $maxTokens, $temperature, $prompt, $base64Image, $mimeType, $requestTimeout, $extraHeaders) {
                        $messages = [
                            [
                                'role' => 'user',
                                'content' => [
                                    ['type' => 'text', 'text' => $prompt],
                                    ['type' => 'image_url', 'image_url' => [
                                        'url' => "data:{$mimeType};base64,{$base64Image}",
                                    ]],
                                ],
                            ],
                        ];

                        $headers = array_merge([
                            'Authorization' => "Bearer {$apiKey}",
                            'Content-Type' => 'application/json',
                        ], $extraHeaders);

                        $payload = ['model' => $model, 'messages' => $messages,
                            'max_tokens' => $maxTokens, 'temperature' => $temperature];
                        $this->assertControllerTransport('openai_chat_completions', "{$baseUrl}/chat/completions", $payload);
                        $response = AIService::maintainInferenceHttp(Http::withHeaders($headers)
                            ->timeout($requestTimeout)->connectTimeout(10))
                            ->post("{$baseUrl}/chat/completions", $payload);

                        if ($response->status() === 429) {
                            $retryAfter = $response->header('Retry-After');
                            $retryAfterSeconds = $this->circuitBreakerPolicy()->retryAfterSeconds($retryAfter);
                            throw new RateLimitException(
                                "Rate limited by {$baseUrl} (retry after {$retryAfterSeconds}s)",
                                'external_vision',
                                $model,
                                429,
                                $retryAfterSeconds * 1000
                            );
                        }

                        if (! $response->successful()) {
                            throw AIExceptionFactory::fromHttpResponse($response, 'external_vision', $model);
                        }

                        $data = $response->json();
                        $content = $data['choices'][0]['message']['content']
                            ?? $data['choices'][0]['text']
                            ?? null;

                        if ($content === null) {
                            throw new Exception('Invalid vision response format from '.$baseUrl);
                        }

                        return $this->normalizeOpenAICompatibleContent($content);
                    },
                    maxAttempts: AIService::inferenceReplayAllowed() ? 2 : 1,
                    backoffStrategy: 'exponential',
                    baseDelay: 1000,
                    shouldRetry: function (Exception $e) {
                        if ($e instanceof TransientException) {
                            return $e->isRetryable();
                        }
                        $msg = strtolower($e->getMessage());

                        return str_contains($msg, 'timeout') ||
                               str_contains($msg, 'rate limit') ||
                               str_contains($msg, '429') ||
                               str_contains($msg, 'connection');
                    },
                    operationName: 'OpenAI-Compatible Vision API Call'
                );
            });

            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('external_vision', $duration, true);

            return $response;

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('external_vision', $duration, false);
            throw $e;
        }
    }

    /**
     * Check if Ollama is available (for health checks)
     */
    public function isOllamaAvailable(): bool
    {
        try {
            $response = Http::connectTimeout(5)->timeout(5)->get("{$this->ollamaUrl}/api/tags");

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Is PLOS running in fail-closed offline mode?
     *
     * INTERNET offline only. Blocks all external cloud LLM APIs.
     * LAN services (Nextcloud, local DB/Redis/Ollama, local MCP, queue workers)
     * stay online — offline mode closes the cloud escape hatch, nothing else.
     *
     * Reads `routing.offline_mode` from system_configs via SystemConfigService.
     * Any value other than 'disabled' (including lookup errors) returns true
     * so cloud traffic is blocked even on transient config faults.
     */
    private function isOfflineModeEnabled(): bool
    {
        try {
            $value = app(SystemConfigService::class)
                ->get('routing.offline_mode', 'disabled');
            if (! is_string($value)) {
                return true;
            }

            return strtolower(trim($value)) !== 'disabled';
        } catch (\Throwable $e) {
            Log::warning('AIRouter: offline mode lookup failed, failing closed', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Process text with AI and MCP tool calling support
     *
     * @param  string  $prompt  User prompt
     * @param  array  $config  Configuration options
     * @param  int  $maxIterations  Maximum tool calling iterations
     * @return string Final AI response
     */
    public function processWithTools(string $prompt, array $config = [], int $maxIterations = 5): string
    {
        $temperature = $config['temperature'] ?? 0.1;
        $maxTokens = $config['max_tokens'] ?? $this->defaultMaxTokens;
        $systemPrompt = $config['system_prompt'] ?? null;
        $toolFilter = $config['tool_filter'] ?? null; // Filter by server names

        // Per-instance routing: AIService passes instance_url/instance_model when
        // it has selected a specific Ollama instance from the pool. Fall back to
        // constructor-bound primary when not supplied.
        $targetUrl = $config['instance_url'] ?? $this->ollamaUrl;
        $targetModel = $config['instance_model'] ?? $this->ollamaModel;

        // Smart tool filtering: llama3.1 works better with fewer tools
        // Auto-detect which tools are likely needed based on query
        if ($toolFilter === null) {
            $toolFilter = $this->detectRelevantToolServers($prompt);
            Log::info('Auto-detected tool filter for query', [
                'query_preview' => substr($prompt, 0, 100),
                'servers' => $toolFilter,
            ]);
        }

        // Let trusted callers pass a pre-filtered catalog so the model only
        // plans with tools the active profile can legally use.
        $availableTools = $this->resolveAvailableTools($config);

        // Convert to Ollama tool format, optionally filtering by server
        $tools = [];
        foreach ($availableTools as $tool) {
            // Filter tools if tool_filter is specified
            if ($toolFilter && ! in_array($tool['server'], $toolFilter)) {
                continue;
            }

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['server'].'_'.$tool['name'], // Prefix with server name
                    'description' => $tool['description']." (Server: {$tool['server']})",
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                ],
            ];
        }

        // Message history for tool calling conversation
        // Add system message if provided (Ollama /api/chat supports system role)
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $iterations = 0;
        while ($iterations < $maxIterations) {
            $iterations++;

            try {
                // Log iteration info
                Log::debug('Ollama tool calling iteration', [
                    'iteration' => $iterations,
                    'tools_count' => count($tools),
                    'messages_count' => count($messages),
                    'has_system_prompt' => ! empty($systemPrompt),
                    'first_tool' => count($tools) > 0 ? $tools[0]['function']['name'] : 'none',
                    'user_message_preview' => substr($messages[count($messages) - 1]['content'] ?? '', 0, 100),
                ]);

                // Call Ollama with tools
                $toolTimeout = (int) config('services.ollama.tool_timeout', 180);
                $payload = [
                    'model' => $targetModel,
                    'messages' => $messages,
                    'tools' => $tools,
                    'temperature' => $temperature,
                    'stream' => false,
                    'options' => $this->toolRequestOptions($config, $maxTokens),
                ];
                $receipt = PoolExecutionReceipt::fromConfig($config);
                $request = AIService::maintainInferenceHttp(Http::connectTimeout(5)->timeout($toolTimeout));
                if ($receipt !== null) {
                    $request = $receipt->transportHttp($request);
                }
                $response = $this->controllerRequest('ollama_chat', "{$targetUrl}/api/chat", $payload,
                    fn () => $request->post("{$targetUrl}/api/chat", $payload), $receipt);

                if (! $response->successful()) {
                    $error = $response->body();
                    Log::error('Ollama tool calling failed', [
                        'status' => $response->status(),
                        'error' => $error,
                    ]);
                    throw new Exception('Ollama tool calling failed: '.$response->status().' - '.substr($error, 0, 200));
                }

                $data = $response->json();
                $message = $data['message'] ?? null;

                if (! $message) {
                    throw new Exception('Invalid Ollama response format');
                }

                // Debug log to see response structure
                Log::debug('Ollama response', [
                    'has_tool_calls' => isset($message['tool_calls']),
                    'tool_calls_count' => count($message['tool_calls'] ?? []),
                    'has_content' => ! empty($message['content']),
                    'content_preview' => substr($message['content'] ?? '', 0, 200),
                ]);

                // Add assistant message to history
                // Fix: Ensure tool_calls arguments are always objects, not arrays
                // Ollama can return [] but expects {} on subsequent calls
                if (isset($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as &$toolCall) {
                        if (isset($toolCall['function']['arguments']) && is_array($toolCall['function']['arguments'])) {
                            // Convert empty array to empty object
                            if (empty($toolCall['function']['arguments'])) {
                                $toolCall['function']['arguments'] = new \stdClass;
                            }
                        }
                    }
                    unset($toolCall); // Break reference
                }
                $messages[] = $message;

                $toolCalls = $message['tool_calls'] ?? [];
                if (empty($toolCalls)) {
                    $plainTextToolCall = $this->plainTextToolCallFromContent((string) ($message['content'] ?? ''), $tools);
                    if ($plainTextToolCall !== null) {
                        $toolCalls = [$plainTextToolCall];
                        Log::info('Treating plain-text Ollama tool name as a tool call', [
                            'function' => $plainTextToolCall['function']['name'] ?? null,
                        ]);
                    }
                }

                // Check if there are tool calls
                if (! empty($toolCalls)) {
                    // Execute each tool call
                    foreach ($toolCalls as $toolCall) {
                        $functionName = $toolCall['function']['name'] ?? '';
                        $arguments = $toolCall['function']['arguments'] ?? [];

                        // Convert stdClass to array (fix for Ollama tool calling)
                        if ($arguments instanceof \stdClass) {
                            $arguments = (array) $arguments;
                        }

                        // Parse server and tool name (format: server_toolname)
                        $parts = explode('_', $functionName, 2);
                        if (count($parts) !== 2) {
                            continue;
                        }

                        [$server, $tool] = $parts;

                        Log::info('Executing MCP tool call', [
                            'server' => $server,
                            'tool' => $tool,
                            'arguments' => $arguments,
                        ]);

                        try {
                            $result = $this->mcpRouter->callTool($server, $tool, $arguments);

                            // Add tool result to message history with instruction to summarize.
                            $messages[] = [
                                'role' => 'tool',
                                'content' => $this->formatMcpToolResultForPrompt($server, $tool, $result),
                                'name' => $functionName,
                            ];

                        } catch (Exception $e) {
                            PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure($e);
                            Log::error('MCP tool execution failed', [
                                'server' => $server,
                                'tool' => $tool,
                                'error' => $e->getMessage(),
                            ]);

                            // Add error to message history
                            $messages[] = [
                                'role' => 'tool',
                                'content' => $this->formatMcpToolResultForPrompt($server, $tool, ['error' => $e->getMessage()], true),
                                'name' => $functionName,
                            ];
                        }
                    }

                    // Continue loop to get final response after tool execution
                    continue;
                }

                // No tool calls, return final response
                $content = $message['content'] ?? 'No response from AI';

                // Post-process: If the LLM returned JSON despite instructions, extract the answer
                $content = $this->extractNaturalLanguageFromResponse($content);

                // If extraction returned sentinel, retry without tools
                if ($content === '__RETRY_WITHOUT_TOOLS__') {
                    if ($this->controllerManaged("{$targetUrl}/api/chat")) {
                        throw new InferenceAdmissionException('Managed tool response was invalid; no replacement request was sent.');
                    }
                    Log::info('Retrying query without tools due to malformed tool response');

                    PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure();

                    return $this->processWithAI($prompt, $config);
                }

                return $content;

            } catch (InferenceAdmissionException|ModelHostControllerUnavailableException $e) {
                throw $e;
            } catch (Exception $e) {
                PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure($e);
                Log::error('Tool calling iteration failed', [
                    'iteration' => $iterations,
                    'error' => $e->getMessage(),
                ]);

                // Fall back to simple text generation without tools.
                if ($iterations === 1) {
                    Log::info('Falling back to simple AI processing (no tools)');

                    PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure();

                    return $this->processWithAI($prompt, $config);
                }

                throw $e;
            }
        }

        return "Maximum tool calling iterations reached ($maxIterations)";
    }

    /**
     * Some local Ollama models echo the selected function name as plain text
     * instead of returning a structured tool_calls array. Accept only an exact
     * match against the already-filtered advertised tool catalog.
     *
     * @param  array<int,array<string,mixed>>  $tools
     * @return array{function:array{name:string,arguments:array<string,mixed>|\stdClass}}|null
     */
    private function plainTextToolCallFromContent(string $content, array $tools): ?array
    {
        $parsed = $this->extractPlainTextToolCall($content);
        if ($parsed === null) {
            return null;
        }

        foreach ($tools as $tool) {
            $advertised = $tool['function']['name'] ?? null;
            if (is_string($advertised) && hash_equals($advertised, $parsed['name'])) {
                return [
                    'function' => [
                        'name' => $advertised,
                        'arguments' => $this->normalizePlainTextToolArguments($parsed['arguments']),
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{name:string,arguments:mixed}|null
     */
    private function extractPlainTextToolCall(string $content): ?array
    {
        $content = $this->stripJsonCodeFence(trim($content));
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_string($decoded)) {
                return $this->extractPlainTextToolCall($decoded);
            }

            if (is_array($decoded)) {
                $functionName = $this->extractToolNameFromDecodedToolCall($decoded);
                if ($functionName !== null) {
                    return [
                        'name' => $functionName,
                        'arguments' => $this->extractArgumentsFromDecodedToolCall($decoded),
                    ];
                }
            }
        }

        $functionName = $this->extractPlainTextFunctionName($content);
        if ($functionName === null) {
            return null;
        }

        return [
            'name' => $functionName,
            'arguments' => new \stdClass,
        ];
    }

    private function stripJsonCodeFence(string $content): string
    {
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $content, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return $content;
    }

    /**
     * @param  array<string,mixed>  $decoded
     */
    private function extractToolNameFromDecodedToolCall(array $decoded): ?string
    {
        foreach (['name', 'tool', 'tool_name', 'function', 'action'] as $key) {
            $candidate = $decoded[$key] ?? null;
            if (is_string($candidate)) {
                $functionName = $this->extractPlainTextFunctionName($candidate);
                if ($functionName !== null) {
                    return $functionName;
                }
            }
        }

        foreach (['function', 'tool_call', 'toolCall'] as $key) {
            $candidate = $decoded[$key] ?? null;
            if (! is_array($candidate)) {
                continue;
            }

            $functionName = $this->extractToolNameFromDecodedToolCall($candidate);
            if ($functionName !== null) {
                return $functionName;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $decoded
     */
    private function extractArgumentsFromDecodedToolCall(array $decoded): mixed
    {
        foreach (['arguments', 'args', 'parameters', 'params', 'input'] as $key) {
            if (array_key_exists($key, $decoded)) {
                return $decoded[$key];
            }
        }

        foreach (['function', 'tool_call', 'toolCall'] as $key) {
            $candidate = $decoded[$key] ?? null;
            if (! is_array($candidate)) {
                continue;
            }

            $arguments = $this->extractArgumentsFromDecodedToolCall($candidate);
            if ($arguments !== null) {
                return $arguments;
            }
        }

        return new \stdClass;
    }

    /**
     * @return array<string,mixed>|\stdClass
     */
    private function normalizePlainTextToolArguments(mixed $arguments): array|\stdClass
    {
        if ($arguments instanceof \stdClass) {
            return $arguments;
        }

        if (is_array($arguments)) {
            return $arguments === [] ? new \stdClass : $arguments;
        }

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded === [] ? new \stdClass : $decoded;
            }
        }

        return new \stdClass;
    }

    private function extractPlainTextFunctionName(string $content): ?string
    {
        $content = trim($this->stripJsonCodeFence($content));
        $content = trim($content, " \t\n\r\0\x0B\"'");
        if ($content === '') {
            return null;
        }

        if (preg_match('/^`+([A-Za-z0-9_.-]+_[A-Za-z0-9_.-]+)`+$/', $content, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^<function=([A-Za-z0-9_.-]+_[A-Za-z0-9_.-]+)>$/', $content, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[A-Za-z0-9_.-]+_[A-Za-z0-9_.-]+$/', $content) === 1) {
            return $content;
        }

        return null;
    }

    /**
     * Process with MCP tools and stream responses in real-time
     *
     * @param  string  $prompt  User prompt
     * @param  array  $config  Configuration (temperature, max_tokens)
     * @param  int  $maxIterations  Maximum tool calling iterations
     * @return \Generator Yields SSE-formatted chunks
     */
    public function processWithToolsStreaming(string $prompt, array $config = [], int $maxIterations = 5): \Generator
    {
        $temperature = $config['temperature'] ?? 0.1;
        $maxTokens = $config['max_tokens'] ?? $this->defaultMaxTokens;
        $systemPrompt = $this->resolveSystemPrompt($config);
        $toolFilter = $config['tool_filter'] ?? null;

        // Per-instance routing: AIService passes instance_url/instance_model when it
        // has selected a specific Ollama instance from the pool. The legacy `model`
        // key remains supported for callers that only want to override the model.
        $targetUrl = $config['instance_url'] ?? $this->ollamaUrl;
        $streamModel = $config['instance_model'] ?? $config['model'] ?? $this->ollamaModel;

        // Smart tool filtering for better llama3.1 performance
        if ($toolFilter === null) {
            $toolFilter = $this->detectRelevantToolServers($prompt);
            Log::info('Streaming: Auto-detected tool filter', [
                'query_preview' => substr($prompt, 0, 100),
                'servers' => $toolFilter,
            ]);
        }

        // Let trusted callers pass a pre-filtered catalog so the model only
        // plans with tools the active profile can legally use.
        $availableTools = $this->resolveAvailableTools($config);

        // Convert to Ollama tool format, filtered by detected servers
        $tools = [];
        foreach ($availableTools as $tool) {
            // Apply filter
            if ($toolFilter && ! in_array($tool['server'], $toolFilter)) {
                continue;
            }
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['server'].'_'.$tool['name'],
                    'description' => $tool['description']." (Server: {$tool['server']})",
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                ],
            ];
        }

        // Message history for tool calling conversation
        // Add system message first if we have one (Ollama /api/chat supports system role)
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $settings = ['model' => $streamModel, 'tools' => $tools, 'temperature' => $temperature,
            'stream' => true, 'options' => $this->toolRequestOptions($config, $maxTokens)];
        $iterations = 0;
        $textStepJoiner = new StreamTextStepJoiner;
        while ($iterations < $maxIterations) {
            $iterations++;

            try {
                $fullMessage = '';
                $assistantMessage = null;
                $toolCalls = [];
                foreach ($this->streamOllamaChatRequest("{$targetUrl}/api/chat", $settings + ['messages' => $messages], PoolExecutionReceipt::fromConfig($config)) as $chunk) {
                    $message = $chunk['message'] ?? null;
                    if (! is_array($message)) {
                        continue;
                    }
                    $content = $message['content'] ?? '';
                    if (is_string($content) && $content !== '') {
                        $fullMessage .= $content;
                        $streamContent = $textStepJoiner->append($iterations, $content);
                        if ($streamContent !== null) {
                            yield json_encode(['type' => 'content', 'content' => $streamContent])."\n";
                        }
                    }
                    if (! empty($message['tool_calls'])) {
                        $toolCalls = $message['tool_calls'];
                    }
                    if ($chunk['done'] ?? false) {
                        $assistantMessage = $message;
                    }
                }

                // Add assistant message to history
                if ($assistantMessage) {
                    // Fix: Ensure tool_calls arguments are always objects
                    if (isset($assistantMessage['tool_calls'])) {
                        foreach ($assistantMessage['tool_calls'] as &$toolCall) {
                            if (isset($toolCall['function']['arguments']) && is_array($toolCall['function']['arguments'])) {
                                if (empty($toolCall['function']['arguments'])) {
                                    $toolCall['function']['arguments'] = new \stdClass;
                                }
                            }
                        }
                        unset($toolCall);
                    }
                    $messages[] = $assistantMessage;
                }

                // If there are tool calls, execute them
                if (! empty($toolCalls)) {
                    yield json_encode(['type' => 'tool_start', 'count' => count($toolCalls)])."\n";

                    $toolCallPosition = 0;
                    foreach ($toolCalls as $toolCall) {
                        $functionName = $toolCall['function']['name'] ?? '';
                        $arguments = $toolCall['function']['arguments'] ?? [];

                        // Convert stdClass to array
                        if ($arguments instanceof \stdClass) {
                            $arguments = (array) $arguments;
                        }

                        // Parse server and tool name
                        $parts = explode('_', $functionName, 2);
                        if (count($parts) !== 2) {
                            continue;
                        }

                        [$server, $tool] = $parts;
                        $toolCallPosition++;
                        $toolCallId = "tool_{$iterations}_{$toolCallPosition}";

                        yield json_encode(['type' => 'tool_call', 'tool_call_id' => $toolCallId, 'server' => $server, 'tool' => $tool, 'arguments' => $arguments])."\n";

                        try {
                            $toolResult = $this->mcpRouter->callTool($server, $tool, $arguments);

                            // Add tool result to conversation with instruction to summarize.
                            $messages[] = [
                                'role' => 'tool',
                                'content' => $this->formatMcpToolResultForPrompt($server, $tool, $toolResult),
                            ];

                            yield json_encode(['type' => 'tool_result', 'tool_call_id' => $toolCallId, 'server' => $server, 'tool' => $tool])."\n";

                        } catch (Exception $e) {
                            PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure($e);
                            Log::error('Tool execution failed during streaming', [
                                'server' => $server,
                                'tool' => $tool,
                                'error' => $e->getMessage(),
                            ]);

                            $messages[] = [
                                'role' => 'tool',
                                'content' => $this->formatMcpToolResultForPrompt($server, $tool, ['error' => $e->getMessage()], true),
                            ];

                            yield json_encode([
                                'type' => 'tool_error',
                                'tool_call_id' => $toolCallId,
                                'server' => $server,
                                'tool' => $tool,
                                'error_code' => 'tool_execution_failed',
                            ])."\n";
                        }
                    }

                    yield json_encode(['type' => 'tool_end'])."\n";

                    // Continue loop to get final response with tool results
                    continue;
                }

                // No tool calls, we're done
                // Post-process: If the LLM returned JSON despite instructions, extract the answer
                $cleanedMessage = $this->extractNaturalLanguageFromResponse($fullMessage);

                // If extraction returned sentinel, fall back to simple AI (no tools)
                if ($cleanedMessage === '__RETRY_WITHOUT_TOOLS__') {
                    if ($this->controllerManaged("{$targetUrl}/api/chat")) {
                        throw new InferenceAdmissionException('Managed tool response was invalid; no replacement request was sent.');
                    }
                    Log::info('Streaming: Retrying without tools due to malformed response');
                    PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure();
                    $fallbackResponse = $this->processWithAI($prompt, $config);
                    yield json_encode(['type' => 'content_replace', 'content' => $fallbackResponse])."\n";
                    yield json_encode(['type' => 'done', 'content' => $fallbackResponse])."\n";

                    return;
                }

                // If we extracted something different, yield it as a replacement
                if ($cleanedMessage !== $fullMessage) {
                    yield json_encode(['type' => 'content_replace', 'content' => $cleanedMessage])."\n";
                }

                yield json_encode(['type' => 'done', 'content' => $cleanedMessage])."\n";

                return;

            } catch (Exception $e) {
                PoolExecutionReceipt::fromConfig($config)?->stopReplayAfterFailure($e);
                if (AIService::hasManagedInferenceContract() || $e instanceof InferenceAdmissionException || $e instanceof ModelHostControllerUnavailableException) {
                    throw $e;
                }
                Log::error('Streaming error', ['error' => $e->getMessage()]);
                yield json_encode(['type' => 'error', 'content' => $e->getMessage()])."\n";

                return;
            }
        }

        // Max iterations reached
        yield json_encode(['type' => 'done', 'content' => 'Max iterations reached'])."\n";
    }

    /**
     * Use a caller-supplied MCP catalog when present; otherwise fall back to
     * the full router catalog.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveAvailableTools(array $config): array
    {
        $provided = $config['available_tools'] ?? null;
        if (is_array($provided)) {
            return $provided;
        }

        return $this->mcpRouter->getAvailableTools();
    }

    /**
     * Get current AI service status
     */
    public function getStatus(): array
    {
        return [
            'ollama' => [
                'available' => $this->isOllamaAvailable(),
                'url' => $this->ollamaUrl,
                'model' => $this->ollamaModel,
                'vision_model' => $this->visionModel,
            ],
            'vision' => [
                'available' => $this->isVisionAvailable(),
                'ollama_model' => $this->visionModel,
            ],
            'mode' => config('services.ai.default_mode', 'auto'),
            'provider_routing_source' => 'llm_instances',
        ];
    }

    /**
     * Process chat with conversation history
     *
     * @param  array  $config  Configuration with 'messages' array and optional 'model'
     * @return array Response with content, tool_calls, and tokens
     */
    public function processChat(array $config): array
    {
        $messages = $config['messages'] ?? [];
        $model = $config['model'] ?? $this->ollamaModel;
        $temperature = $config['temperature'] ?? 0.7;
        $maxTokens = $config['max_tokens'] ?? $this->defaultMaxTokens;
        $numCtx = max(512, (int) ($config['num_ctx'] ?? 4096));

        if (empty($messages)) {
            throw new Exception('No messages provided for chat');
        }

        if (str_starts_with($model, 'ollama:')) {
            $model = str_replace('ollama:', '', $model);
        }

        try {
            return $this->callOllamaChat($messages, $model, $temperature, $maxTokens, $numCtx, PoolExecutionReceipt::fromConfig($config));
        } catch (Exception $e) {
            Log::error('Chat processing failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'message_count' => count($messages),
            ]);
            throw $e;
        }
    }

    /**
     * Call Ollama chat endpoint with message history
     */
    private function callOllamaChat(array $messages, string $model, float $temperature, int $maxTokens, int $numCtx, ?PoolExecutionReceipt $receipt = null): array
    {
        // Sanitize all message content before json_encode
        foreach ($messages as &$msg) {
            if (isset($msg['content']) && is_string($msg['content'])) {
                $msg['content'] = $this->sanitizeUtf8($msg['content']);
            }
        }
        unset($msg);

        $chatTimeout = (int) config('services.ollama.chat_timeout', 90);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'think' => false,
            'temperature' => $temperature,
            'stream' => false,
            'options' => [
                'num_predict' => $maxTokens,
                'temperature' => $temperature,
                'num_ctx' => $numCtx,
            ],
        ];
        $request = AIService::maintainInferenceHttp(Http::connectTimeout(5)->timeout($chatTimeout));
        if ($receipt !== null) {
            $request = $receipt->transportHttp($request);
        }
        $response = $this->controllerRequest('ollama_chat', "{$this->ollamaUrl}/api/chat", $payload,
            fn () => $request->post("{$this->ollamaUrl}/api/chat", $payload), $receipt);

        if (! $response->successful()) {
            throw new Exception('Ollama chat request failed: '.$response->status());
        }

        $data = $response->json();
        $message = $data['message'] ?? null;

        if (! $message) {
            throw new Exception('Invalid Ollama chat response format');
        }

        $this->logTokenCalibration($messages, $model, $data['prompt_eval_count'] ?? null);

        return [
            'content' => $this->stripOllamaThinkingLeak($message['content'] ?? ''),
            'tool_calls' => $message['tool_calls'] ?? null,
            'tokens' => $data['eval_count'] ?? null,
            'prompt_tokens' => $data['prompt_eval_count'] ?? null,
        ];
    }

    private function logTokenCalibration(array $messages, string $model, $promptEvalCount): void
    {
        if (! is_int($promptEvalCount) || $promptEvalCount <= 0) {
            return;
        }

        $messageText = '';
        foreach ($messages as $msg) {
            if (isset($msg['content']) && is_string($msg['content'])) {
                $messageText .= $msg['content']."\n";
            }
        }

        $chars = strlen($messageText);
        $estimated = (int) ceil($chars / 1.5);
        $ratio = $estimated > 0 ? round($promptEvalCount / $estimated, 3) : null;

        Log::info('ollama.token_calibration', [
            'model' => $model,
            'estimated' => $estimated,
            'actual' => $promptEvalCount,
            'ratio' => $ratio,
            'chars' => $chars,
        ]);
    }

    private function stripOllamaThinkingLeak(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $cleaned = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $text) ?? $text;

        if (str_contains($cleaned, '</think>')) {
            $parts = explode('</think>', $cleaned);
            $cleaned = end($parts) ?: '';
        }

        return ltrim($cleaned);
    }

    private function getProcessEnvironment(): array
    {
        $environment = getenv();

        return is_array($environment) ? $environment : [];
    }

    /** Each tool round has its own request identity and keeps custody through stream completion. */
    private function streamOllamaChatRequest(string $endpoint, array $payload, ?PoolExecutionReceipt $receipt = null): \Generator
    {
        if (! AIService::hasManagedInferenceContract() && $this->controllerManaged($endpoint)) {
            yield from app(ModelHostControllerTransportSupervisor::class)->stream('ollama_chat', $endpoint, $payload,
                fn () => $this->streamOllamaChatRequest($endpoint, $payload, $receipt),
                ['cancelled' => static fn (): bool => connection_aborted() !== 0]);

            return;
        }
        $this->assertControllerTransport('ollama_chat', $endpoint, $payload);
        $client = PipelineHttpClientFactory::make();
        $options = AIService::inferenceProgressOptions([
            'json' => $payload, 'stream' => true,
            'timeout' => (int) config('services.ollama.streaming_timeout', 240),
        ]);
        if ($receipt !== null) {
            $options = $receipt->transportOptions($options);
            $receipt->beforeSend('ollama_chat', $endpoint, (string) $payload['model']);
        }
        $response = $client->request('POST', $endpoint, $options);
        if ($response->getStatusCode() !== 200) {
            throw new InferenceAdmissionException('Tool stream failed after dispatch; no automatic replay is allowed.');
        }
        $body = $response->getBody();
        $buffer = '';
        try {
            while (! $body->eof()) {
                $char = $body->read(1);
                if ($char !== "\n") {
                    $buffer .= $char;

                    continue;
                }
                $chunk = json_decode(trim($buffer), true);
                $buffer = '';
                if (! is_array($chunk)) {
                    continue;
                }
                if (($chunk['done'] ?? false) === true && ! is_array($chunk['message'] ?? null)) {
                    throw new InferenceAdmissionException('Tool stream completion has no valid message; no automatic replay is allowed.');
                }
                if (($chunk['done'] ?? null) === true) {
                    $receipt?->ollamaTerminal($chunk);
                }
                yield $chunk;
                if (($chunk['done'] ?? false) === true) {
                    return;
                }
            }
            if (trim($buffer) !== '') {
                $chunk = json_decode($buffer, true);
                if (is_array($chunk)) {
                    if (($chunk['done'] ?? false) === true && ! is_array($chunk['message'] ?? null)) {
                        throw new InferenceAdmissionException('Tool stream completion has no valid message; no automatic replay is allowed.');
                    }
                    if (($chunk['done'] ?? null) === true) {
                        $receipt?->ollamaTerminal($chunk);
                    }
                    yield $chunk;
                    if (($chunk['done'] ?? false) === true) {
                        return;
                    }
                }
            }
            throw new InferenceAdmissionException('Tool stream ended without completion; no automatic replay is allowed.');
        } finally {
            $body->close();
        }
    }

    private function toolRequestOptions(array $config, int $maxTokens): array
    {
        $options = is_array($config['ollama_options'] ?? null) ? $config['ollama_options'] : [];
        if (array_key_exists('num_ctx', $config)) {
            $options['num_ctx'] = $config['num_ctx'];
        }
        $options['num_predict'] = $maxTokens;

        return $options;
    }

    private function controllerManaged(string $endpoint): bool
    {
        return app(ModelHostControllerResourcePolicyService::class)->managedHostForEndpoint($endpoint) !== null;
    }

    private function assertControllerTransport(string $wire, string $endpoint, array $payload): void
    {
        if (! in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string($payload['model'] ?? null) || trim($payload['model']) === '') {
            throw new InferenceAdmissionException('Registered LLM endpoint and model are required.');
        }
        unset($payload['messages'], $payload['prompt'], $payload['system'], $payload['images'], $payload['input']);
        AIService::assertInferenceTransport($wire, $endpoint, $payload);
    }

    private function controllerRequest(string $wire, string $endpoint, array $payload, callable $send, ?PoolExecutionReceipt $receipt = null): mixed
    {
        return app(ModelHostControllerTransportSupervisor::class)->run($wire, $endpoint, $payload, function () use ($wire, $endpoint, $payload, $send, $receipt) {
            $this->assertControllerTransport($wire, $endpoint, $payload);
            $receipt?->beforeSend($wire, $endpoint, (string) $payload['model']);
            $response = $send();
            if ($receipt !== null && $response->successful()) {
                $data = $response->json();
                $receipt->ollamaTerminal(is_array($data) ? $data : []);
            }
            if (AIService::hasManagedInferenceContract()
                && (! $response->successful() || ! is_array($response->json('message')))) {
                throw new InferenceAdmissionException('Managed chat returned an unsuccessful or invalid response; no automatic replay is allowed.');
            }

            return $response;
        });
    }
}

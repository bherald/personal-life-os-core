<?php

namespace App\Engine;

use App\DTOs\TrustEnvelope;
use App\Exceptions\AI\AIExceptionFactory;
use App\Exceptions\AI\RateLimitException;
use App\Exceptions\AI\TransientException;
use App\Services\CircuitBreaker;
use App\Services\LLMPoolManagerService;
use App\Services\RetryService;
use App\Services\TimeoutManager;
use App\Services\TrustBoundaryFormatterService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process as ProcessFacade;

/**
 * AI Router - Intelligent routing between Ollama (offline-first) and Claude Agent SDK (fallback)
 *
 * Features:
 * - Direct Ollama integration (bypasses AnythingLLM overhead)
 * - Automatic fallback to Claude Agent SDK Proxy when an operator-configured Claude provider is available
 * - Agent SDK provides full MCP tool access when Ollama is unavailable
 * - Embedding generation for RAG
 * - Offline-first design with local-only fallback
 * - Performance optimized
 *
 * Fallback Chain:
 * 1. Ollama (local GPU server) - primary
 * 2. Claude Agent SDK Proxy (localhost:8770) - fallback with MCP tools
 * 3. Claude CLI (--print mode) - simple text fallback
 */
class AIRouter
{
    private const DEFAULT_MAX_TOKENS_FALLBACK = 4096;

    private const EMBEDDING_CHAR_LIMIT_FALLBACK = 30000;

    private string $ollamaUrl;

    private string $ollamaModel;

    private string $embeddingModel;

    private string $visionModel;

    private int $timeout;

    private int $defaultMaxTokens;

    private int $embeddingCharLimit;

    private MCPRouter $mcpRouter;

    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    private string $agentProxyUrl;

    public function __construct()
    {
        $ollamaConfig = $this->loadPrimaryOllamaConfig();
        $roleModels = $ollamaConfig['models'] ?? [];

        $this->ollamaUrl = $ollamaConfig['base_url'] ?? config('services.ollama.api_url', 'http://127.0.0.1:11434');
        $this->ollamaModel = (string) ($roleModels['standard'] ?? $ollamaConfig['default_model'] ?? config('services.ollama.model') ?? '');
        $this->embeddingModel = (string) ($ollamaConfig['embedding_model'] ?? $roleModels['embedding'] ?? config('services.ollama.embedding_model') ?? '');
        $this->visionModel = (string) ($roleModels['vision'] ?? config('services.ollama.vision_model') ?? '');
        // Base text generation timeout — overridden per operation type below
        $this->timeout = (int) config('services.ollama.timeout', 120);
        $this->defaultMaxTokens = (int) config('ai.default_max_tokens', self::DEFAULT_MAX_TOKENS_FALLBACK);
        $this->embeddingCharLimit = (int) config('ai.embedding_char_limit', self::EMBEDDING_CHAR_LIMIT_FALLBACK);
        $this->mcpRouter = new MCPRouter;
        $this->agentProxyUrl = config('services.claude.agent_proxy_url', 'http://127.0.0.1:8770');
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
     * This helps both Ollama (with direct tool access) and Claude CLI (guidance mode)
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
    protected function detectRelevantToolServers(string $prompt): array
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
     * Process text with AI - tries Ollama first, falls back to Claude
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
        $instanceUrl = $config['ollama_url'] ?? null;
        $instanceModel = $config['ollama_model'] ?? null;

        // External API mode — called directly by AIService with full provider config
        if ($mode === 'external_api') {
            return $this->callOpenAICompatible($prompt, $config);
        }

        // Extract role-resolved model from AIService (null = let Claude CLI use its default)
        $claudeModel = $config['claude_model'] ?? null;

        try {
            if ($mode === 'local' || $mode === 'auto') {
                return $this->callOllama($prompt, $temperature, $maxTokens, $systemPrompt, $timeout, $instanceUrl, $instanceModel);
            }
        } catch (Exception $e) {
            if ($mode === 'auto') {
                // Fallback to the optional Claude CLI when operator-configured.
                if ($this->isClaudeCLIAvailable()) {
                    Log::info('Ollama failed, falling back to Claude Code CLI', [
                        'error' => $e->getMessage(),
                    ]);

                    return $this->callClaudeCLI($prompt, $systemPrompt, $claudeModel, $timeout);
                }
                // No Claude CLI available - fail
                throw new Exception('Ollama failed and Claude CLI not available: '.$e->getMessage());
            }
            throw $e;
        }

        // For explicit 'claude' mode - use CLI only
        if ($this->isClaudeCLIAvailable()) {
            return $this->callClaudeCLI($prompt, $systemPrompt, $claudeModel, $timeout);
        }

        throw new Exception('Claude CLI not available');
    }

    /**
     * Process an image with AI vision model - tries Ollama llava first, falls back to Claude CLI
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

        // Extract role-resolved model from AIService (null = let Claude CLI use its default)
        $claudeModel = $config['claude_model'] ?? null;

        try {
            if ($mode === 'local' || $mode === 'auto') {
                return $this->callOllamaVision($base64Image, $prompt, $temperature, $maxTokens, $timeout, $instanceUrl, $instanceModel);
            }
        } catch (Exception $e) {
            if ($mode === 'auto') {
                // Fallback to Claude CLI with image file (native multimodal support)
                if ($this->isClaudeCLIAvailable()) {
                    Log::info('Ollama vision failed, falling back to Claude Code CLI', [
                        'error' => $e->getMessage(),
                    ]);

                    return $this->callClaudeCLIWithImage($imageContent, $prompt, $imagePath, $claudeModel);
                }
                throw new Exception('Ollama vision failed and Claude CLI not available: '.$e->getMessage());
            }
            throw $e;
        }

        // For explicit 'claude' mode - use CLI only
        if ($this->isClaudeCLIAvailable()) {
            return $this->callClaudeCLIWithImage($imageContent, $prompt, $imagePath, $claudeModel);
        }

        throw new Exception('Claude CLI not available for vision processing');
    }

    /**
     * Call Ollama vision model (llava) with base64-encoded image
     */
    private function callOllamaVision(string $base64Image, string $prompt, float $temperature, int $maxTokens, int $timeout, ?string $instanceUrl = null, ?string $instanceModel = null): string
    {
        $targetUrl = $instanceUrl ?? $this->ollamaUrl;
        $targetModel = $instanceModel ?? $this->visionModel;

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
        if (! $this->ensureVisionModelLoaded(180, $instanceUrl, $targetModel)) {
            throw new Exception('Vision model failed to load within timeout');
        }

        // Use Guzzle directly - Laravel's Http facade doesn't handle long timeouts properly
        $client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => 10,
        ]);

        $response = $client->post("{$targetUrl}/api/generate", [
            'json' => [
                'model' => $targetModel,
                'prompt' => $prompt,
                'images' => [$base64Image],
                'think' => false,
                'temperature' => $temperature,
                'stream' => false,
                'options' => [
                    'num_predict' => $maxTokens,
                ],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new Exception('Ollama vision API failed: '.$response->getStatusCode());
        }

        $data = json_decode($response->getBody()->getContents(), true);
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
     * Call Claude CLI with image file (native multimodal support)
     *
     * Claude Code CLI supports images natively - we save to a temp file if needed
     * and pass the file path as part of the prompt.
     */
    private function callClaudeCLIWithImage(string $imageContent, string $prompt, ?string $imagePath = null, ?string $model = null): string
    {
        // Offline kill switch: same policy as the text path.
        if ($this->isOfflineModeEnabled()) {
            throw new Exception('Claude CLI vision blocked: routing.offline_mode is enabled. Local Ollama vision only.');
        }

        // Pre-flight: check OAuth token before wasting time on a dead token
        $tokenStatus = app(LLMPoolManagerService::class)->checkClaudeTokenExpiry();
        if ($tokenStatus === 'expired') {
            throw new Exception('Claude CLI authentication failed: OAuth token expired. Run: claude login');
        }

        $claudePath = config('services.anthropic.cli_path', 'claude');
        $tempFile = null;
        $process = null;
        $pipes = [];

        // Use app storage for temp files instead of /tmp/ — avoids permission issues
        // when Claude CLI needs to read the file via its Read tool
        $tempDir = storage_path('app/tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // If no original file path, save to temp file
        if (! $imagePath) {
            // imageContent may be base64-encoded (from AIService::processImage callers)
            // or raw binary. Detect and decode if needed so the file is a valid image.
            $rawContent = $imageContent;
            if (! $this->isBinaryImageData($rawContent)) {
                $decoded = base64_decode($rawContent, true);
                if ($decoded !== false && $this->isBinaryImageData($decoded)) {
                    $rawContent = $decoded;
                }
            }

            $extension = $this->detectImageExtension($rawContent);
            $imagePath = $tempDir.'/claude_img_'.uniqid().'.'.$extension;
            file_put_contents($imagePath, $rawContent);
            $tempFile = $imagePath; // Mark for cleanup
        }

        try {
            // Claude CLI in --print mode processes stdin as text only. When the prompt
            // references a file path, Claude needs its Read tool to access that file.
            // --permission-mode bypassPermissions: allows Read tool without interactive
            //   permission prompts (required for headless backend usage)
            // --allowedTools "Read": restricts tool use to only Read (no writes/edits)
            $imagePrompt = "Read and analyze this image file: {$imagePath}\n\n{$prompt}";

            $command = [$claudePath, '--print'];
            if ($model) {
                $command[] = '--model';
                $command[] = $model;
            }
            $command[] = '--permission-mode';
            $command[] = 'bypassPermissions';
            $command[] = '--allowedTools';
            $command[] = 'Read';

            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            Log::debug('Claude CLI vision: Starting process', [
                'image_path' => $imagePath,
                'prompt_length' => strlen($prompt),
            ]);

            // Ensure OAuth token reaches Claude CLI subprocess (same as callClaudeCLI)
            $env = null;
            $oauthToken = config('services.anthropic.cli_oauth_token');
            if ($oauthToken) {
                $env = array_merge($this->getProcessEnvironment(), ['CLAUDE_CODE_OAUTH_TOKEN' => $oauthToken]);
            }

            $process = proc_open($command, $descriptorspec, $pipes, null, $env);

            if (! is_resource($process)) {
                throw new Exception('Failed to start Claude CLI process');
            }

            fwrite($pipes[0], $imagePrompt);
            fclose($pipes[0]);

            // Drain both pipes under a hard deadline so a busy stderr stream cannot
            // block the child and cause a false timeout.
            $visionTimeout = (int) config('services.ollama.timeout', 180);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + $visionTimeout;
            $stdout = '';
            $stderr = '';
            $timedOut = false;

            while (true) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    $timedOut = true;
                    break;
                }
                $seconds = (int) floor($remaining);
                $micros = (int) max(0, ($remaining - $seconds) * 1_000_000);
                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                $changed = stream_select($read, $write, $except, $seconds, $micros);
                if ($changed === false) {
                    break;
                }
                if ($changed === 0) {
                    $timedOut = true;
                    break;
                }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }

                $status = proc_get_status($process);
                if (! (($status['running'] ?? false) === true) && feof($pipes[1]) && feof($pipes[2])) {
                    break;
                }
            }

            // On timeout, kill child FIRST — stream_get_contents on stderr blocks if process alive
            if ($timedOut) {
                proc_terminate($process, SIGKILL);
                usleep(100_000);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $process = null;
                throw new Exception("Claude CLI vision timed out after {$visionTimeout}s");
            }

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);
            $process = null;

            if ($returnCode !== 0) {
                $errorMsg = ! empty($stderr) ? $stderr : 'Unknown error';
                throw new Exception('Claude CLI vision failed (exit code '.$returnCode.'): '.$errorMsg);
            }

            if (empty($stdout)) {
                throw new Exception('Claude CLI returned empty response for image');
            }

            Log::info('Claude CLI vision completed', [
                'response_length' => strlen($stdout),
            ]);

            return trim($stdout);

        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($process, SIGKILL);
                }
                proc_close($process);
            }

            // Clean up temp file if we created one
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
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

            // Vision model not found in Ollama, but Claude CLI may support it
            return $this->isClaudeCLIAvailable();
        } catch (Exception $e) {
            // If Ollama check fails, still return true if Claude CLI is available
            return $this->isClaudeCLIAvailable();
        }
    }

    /**
     * Check if a specific model is currently loaded in Ollama memory
     */
    public function isModelLoaded(string $modelName, ?string $instanceUrl = null): bool
    {
        $targetUrl = $instanceUrl ?? $this->ollamaUrl;
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
    public function ensureModelLoaded(string $modelName, int $timeoutSeconds = 60, ?string $instanceUrl = null): bool
    {
        $targetUrl = $instanceUrl ?? $this->ollamaUrl;

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
            $client = new Client([
                'timeout' => $loadTimeout,
                'connect_timeout' => 10,
            ]);

            $response = $client->post("{$targetUrl}/api/generate", [
                'json' => [
                    'model' => $modelName,
                    'prompt' => 'hi',
                    'think' => false,
                    'stream' => false,
                    'options' => [
                        'num_predict' => 1, // Minimal output
                    ],
                ],
            ]);

            // If the request succeeded, the model is loaded and responded
            // Don't trust /api/ps - Ollama may unload immediately after
            if ($response->getStatusCode() === 200) {
                $elapsed = time() - $startTime;
                Log::info('Model loaded and ready', [
                    'model' => $modelName,
                    'load_time_seconds' => $elapsed,
                ]);

                return true;
            }

            // Request failed but didn't timeout - model issue
            Log::warning('Model load request failed', [
                'model' => $modelName,
                'status' => $response->getStatusCode(),
            ]);

            return false;

        } catch (RequestException $e) {
            $elapsed = time() - $startTime;
            Log::warning('Model load request error', [
                'model' => $modelName,
                'error' => $e->getMessage(),
                'elapsed' => $elapsed,
            ]);

            return false;
        } catch (Exception $e) {
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
    public function ensureVisionModelLoaded(int $timeoutSeconds = 60, ?string $instanceUrl = null, ?string $instanceModel = null): bool
    {
        $targetModel = $instanceModel ?? $this->visionModel;

        return $this->ensureModelLoaded($targetModel, $timeoutSeconds, $instanceUrl);
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
    public function generateEmbedding(string $text, ?string $instanceUrl = null, ?int $charLimit = null, ?int $timeoutSeconds = null): array
    {
        try {
            // Sanitize text to prevent UTF-8 encoding errors
            $text = $this->sanitizeUtf8($text);

            // Hard safety net: truncate to embedding model context limit
            $embeddingCharLimit = $charLimit ?? $this->embeddingCharLimit;
            if (strlen($text) > $embeddingCharLimit) {
                $text = substr($text, 0, $embeddingCharLimit);
                $lastSpace = strrpos($text, ' ');
                if ($lastSpace !== false && $lastSpace > $embeddingCharLimit * 0.8) {
                    $text = substr($text, 0, $lastSpace);
                }
            }

            // Use instance-specific URL if provided (for multi-instance pool routing)
            $url = $instanceUrl ?? $this->ollamaUrl;

            // Embeddings are fast (~100ms) — use short timeout, not the text generation timeout.
            // Text gen timeout ($this->timeout) is 180-300s which causes blocking stalls.
            $embeddingTimeout = $timeoutSeconds ?? (int) config('services.ollama.embedding_timeout', 10);
            $response = Http::withOptions([
                'timeout' => $embeddingTimeout,
                'connect_timeout' => 5,
            ])
                ->post("{$url}/api/embeddings", [
                    'model' => $this->embeddingModel,
                    'prompt' => $text,
                ]);

            if (! $response->successful()) {
                throw new Exception('Ollama embedding failed: '.$response->body());
            }

            $embedding = $response->json('embedding');

            if (! is_array($embedding) || empty($embedding)) {
                throw new Exception('Invalid embedding response from Ollama');
            }

            return $embedding;
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
        $text = $this->sanitizeUtf8($text);

        $baseUrl = rtrim($providerConfig['base_url'], '/');
        $apiKey = $providerConfig['api_key'];
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

        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->connectTimeout(5)
            ->post("{$baseUrl}/embeddings", $body);

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');
            throw new RateLimitException(
                "Embedding rate limited by {$baseUrl}".($retryAfter ? " (retry after {$retryAfter}s)" : ''),
                $providerConfig['instance_id'] ?? 'external_embedding',
                $model,
                429,
                $retryAfter ? (int) $retryAfter * 1000 : 30000
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

        if (! is_array($embedding) || empty($embedding)) {
            throw new Exception('Invalid embedding response from '.$baseUrl.': '.substr(json_encode($data), 0, 200));
        }

        return $embedding;
    }

    /**
     * Call Ollama directly with fault tolerance (offline-first, fast)
     */
    private function callOllama(string $prompt, float $temperature, int $maxTokens, string $systemPrompt = '', ?int $timeout = null, ?string $instanceUrl = null, ?string $instanceModel = null): string
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

        $startTime = microtime(true);

        try {
            // Circuit breaker protection — use instance-specific key when routing to specific instance
            $circuitKey = $instanceUrl ? 'ollama_api_'.md5($instanceUrl) : 'ollama_api';
            $response = $circuitBreaker->call($circuitKey, function () use ($retryService, $prompt, $temperature, $maxTokens, $systemPrompt, $requestTimeout, $targetUrl, $targetModel) {
                // Retry logic with fixed backoff (AI calls are expensive)
                return $retryService->retry(
                    operation: function () use ($prompt, $temperature, $maxTokens, $systemPrompt, $requestTimeout, $targetUrl, $targetModel) {
                        // Override PHP's default_socket_timeout
                        $originalSocketTimeout = ini_get('default_socket_timeout');
                        ini_set('default_socket_timeout', (string) $requestTimeout);

                        // Prepend system prompt since /api/generate doesn't support system role
                        $fullPrompt = $systemPrompt
                            ? "System: {$systemPrompt}\n\nUser: {$prompt}"
                            : $prompt;

                        // Use Guzzle timeout options - CURLOPT constants need to be in 'curl' array
                        $response = Http::withOptions([
                            'http_version' => '1.1',
                            'timeout' => $requestTimeout,        // Total request timeout in seconds
                            'connect_timeout' => 5,              // Fast connect timeout to fail fast
                        ])
                            ->post("{$targetUrl}/api/generate", [
                                'model' => $targetModel,
                                'prompt' => $fullPrompt,
                                'think' => false,
                                'temperature' => $temperature,
                                'stream' => false,
                                'options' => [
                                    'num_predict' => $maxTokens,
                                    'top_p' => 0.9,
                                    'num_ctx' => $config['num_ctx'] ?? 4096,
                                ],
                            ]);

                        // Restore original socket timeout
                        ini_set('default_socket_timeout', $originalSocketTimeout);

                        if (! $response->successful()) {
                            throw AIExceptionFactory::fromHttpResponse($response, 'ollama', $this->ollamaModel);
                        }

                        $data = $response->json();
                        $result = $data['response'] ?? throw new Exception('Invalid Ollama response format');
                        $result = $this->stripOllamaThinkingLeak($result);

                        return $result;
                    },
                    maxAttempts: 2,  // Only retry once for AI (expensive)
                    backoffStrategy: 'fixed',
                    baseDelay: 2000, // 2s fixed delay
                    shouldRetry: function (Exception $e) {
                        // Use typed exception for retry decisions
                        if ($e instanceof TransientException) {
                            return $e->isRetryable();
                        }
                        // Fallback: string matching for non-typed exceptions
                        $message = strtolower($e->getMessage());

                        return str_contains($message, 'timeout') ||
                               str_contains($message, 'timed out') ||
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

        } catch (Exception $e) {
            // Record failed execution
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('ai_processing', $duration, false);

            // Re-throw to trigger fallback to Claude
            throw $e;
        }
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

        $circuitKey = 'external_api_'.md5($baseUrl);
        $startTime = microtime(true);

        try {
            $response = $circuitBreaker->call($circuitKey, function () use (
                $retryService, $baseUrl, $apiKey, $model, $maxTokens, $temperature,
                $systemPrompt, $prompt, $requestTimeout, $extraHeaders
            ) {
                return $retryService->retry(
                    operation: function () use ($baseUrl, $apiKey, $model, $maxTokens, $temperature, $systemPrompt, $prompt, $requestTimeout, $extraHeaders) {
                        $messages = [];
                        if (! empty($systemPrompt)) {
                            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
                        }
                        $messages[] = ['role' => 'user', 'content' => $prompt];

                        $headers = array_merge([
                            'Authorization' => "Bearer {$apiKey}",
                            'Content-Type' => 'application/json',
                        ], $extraHeaders);

                        $response = Http::withHeaders($headers)
                            ->timeout($requestTimeout)
                            ->connectTimeout(10)
                            ->post("{$baseUrl}/chat/completions", [
                                'model' => $model,
                                'messages' => $messages,
                                'max_tokens' => $maxTokens,
                                'temperature' => $temperature,
                            ]);

                        if ($response->status() === 429) {
                            $retryAfter = $response->header('Retry-After');
                            throw new RateLimitException(
                                "Rate limited by {$baseUrl}".($retryAfter ? " (retry after {$retryAfter}s)" : ''),
                                'external_api',
                                $model,
                                429,
                                $retryAfter ? (int) $retryAfter * 1000 : 30000
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
                            throw new \Exception('Invalid response format from '.$baseUrl);
                        }

                        return $this->normalizeOpenAICompatibleContent($content);
                    },
                    maxAttempts: 2,
                    backoffStrategy: 'exponential',
                    baseDelay: 1000,
                    shouldRetry: function (\Exception $e) {
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

        } catch (\Exception $e) {
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

                        $response = Http::withHeaders($headers)
                            ->timeout($requestTimeout)
                            ->connectTimeout(10)
                            ->post("{$baseUrl}/chat/completions", [
                                'model' => $model,
                                'messages' => $messages,
                                'max_tokens' => $maxTokens,
                                'temperature' => $temperature,
                            ]);

                        if ($response->status() === 429) {
                            $retryAfter = $response->header('Retry-After');
                            throw new RateLimitException(
                                "Rate limited by {$baseUrl}".($retryAfter ? " (retry after {$retryAfter}s)" : ''),
                                'external_vision',
                                $model,
                                429,
                                $retryAfter ? (int) $retryAfter * 1000 : 30000
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
                            throw new \Exception('Invalid vision response format from '.$baseUrl);
                        }

                        return $this->normalizeOpenAICompatibleContent($content);
                    },
                    maxAttempts: 2,
                    backoffStrategy: 'exponential',
                    baseDelay: 1000,
                    shouldRetry: function (\Exception $e) {
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

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $timeoutManager->recordExecution('external_vision', $duration, false);
            throw $e;
        }
    }

    /**
     * Call the optional Claude Code CLI (operator-configured Anthropic/Claude provider).
     * Uses proc_open with stdin to handle large prompts without shell escaping issues
     */
    private function callClaudeCLI(string $prompt, string $systemPrompt = '', ?string $model = null, int $timeout = 120): string
    {
        // Offline kill switch: refuse cloud calls even when the CLI binary is
        // present on the host. Throws rather than silently returning empty so
        // the caller's existing exception path (log + alert + fallback) fires.
        if ($this->isOfflineModeEnabled()) {
            throw new Exception('Claude CLI blocked: routing.offline_mode is enabled. Local Ollama only.');
        }

        $process = null;
        $pipes = [];

        // Pre-flight: check OAuth token before wasting a slot + timeout on a dead token
        $poolManager = app(LLMPoolManagerService::class);
        $tokenStatus = $poolManager->checkClaudeTokenExpiry();
        if ($tokenStatus === 'expired') {
            throw new Exception('Claude CLI authentication failed: OAuth token expired. Run: claude login');
        }

        try {
            $claudePath = config('services.anthropic.cli_path', 'claude');

            $command = [$claudePath, '--print'];
            if ($model) {
                $command[] = '--model';
                $command[] = $model;
            }
            if ($systemPrompt !== '') {
                $command[] = '--system-prompt';
                $command[] = $systemPrompt;
            }

            // Use proc_open to pipe prompt via stdin (handles large content safely)
            $descriptorspec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            // Ensure OAuth token reaches Claude CLI subprocess even under systemd
            // (systemd services don't inherit .bashrc exports)
            $env = null;
            $oauthToken = config('services.anthropic.cli_oauth_token');
            if ($oauthToken) {
                $env = array_merge($this->getProcessEnvironment(), ['CLAUDE_CODE_OAUTH_TOKEN' => $oauthToken]);
            }

            $process = proc_open($command, $descriptorspec, $pipes, null, $env);

            if (! is_resource($process)) {
                throw new Exception('Failed to start Claude CLI process');
            }

            // Write prompt to stdin and close
            fwrite($pipes[0], $prompt);
            fclose($pipes[0]);

            // Drain both pipes with a wall-clock deadline. If stderr is not consumed,
            // the child can block on a full pipe and look like a hung process.
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + $timeout;
            $timedOut = false;
            $stdout = '';
            $stderr = '';

            while (true) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    $timedOut = true;
                    break;
                }

                $seconds = (int) floor($remaining);
                $micros = (int) max(0, ($remaining - $seconds) * 1_000_000);
                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                $changed = stream_select($read, $write, $except, $seconds, $micros);

                if ($changed === false) {
                    break; // stream_select error — bail out
                }

                if ($changed === 0) {
                    // Deadline elapsed with no data
                    $timedOut = true;
                    break;
                }

                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }

                $status = proc_get_status($process);
                if (! (($status['running'] ?? false) === true) && feof($pipes[1]) && feof($pipes[2])) {
                    break;
                }
            }

            // On timeout, kill the child FIRST — before reading stderr.
            // stream_get_contents on stderr blocks if the process is still alive.
            if ($timedOut) {
                proc_terminate($process, SIGKILL);
                usleep(100_000); // 100ms for process to die
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $process = null;
                throw new Exception("Claude CLI timed out after {$timeout}s");
            }

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);

            // Get exit code
            $returnCode = proc_close($process);
            $process = null;

            if ($returnCode !== 0) {
                // Combine stderr + stdout for error detection (Claude CLI may report errors in either)
                $combinedOutput = trim(($stderr ?: '').' '.($stdout ?: ''));
                $errorMsg = ! empty($stderr) ? trim($stderr) : (! empty($stdout) ? trim($stdout) : null);

                // When both stdout and stderr are empty, diagnose the root cause instead of "Unknown error"
                if ($errorMsg === null) {
                    $tokenStatus = app(LLMPoolManagerService::class)->checkClaudeTokenExpiry();
                    if ($tokenStatus === 'expired') {
                        throw new Exception('Claude CLI authentication failed: OAuth token expired (exit code '.$returnCode.'). Run: claude login');
                    }
                    $errorMsg = 'Unknown error (exit code '.$returnCode.', empty stdout+stderr — possible auth/network hang)';
                    Log::error('AIRouter: Claude CLI exited with no output', [
                        'exit_code' => $returnCode,
                        'token_status' => $tokenStatus,
                        'timeout_budget' => $timeout,
                    ]);
                }

                // Detect rate limit / daily cap: the Claude CLI emits "You've hit your limit"
                // or similar when daily cap is reached — must be classified as rate limit,
                // not permanent failure, to avoid opening circuit breaker
                $lowerCombined = strtolower($combinedOutput);
                if (str_contains($lowerCombined, "you've hit your limit")
                    || str_contains($lowerCombined, 'you have hit your limit')
                    || str_contains($lowerCombined, 'rate limit')
                    || str_contains($lowerCombined, 'usage limit')
                    || str_contains($lowerCombined, 'daily limit')
                    || str_contains($lowerCombined, 'weekly limit')
                    || str_contains($lowerCombined, 'too many requests')
                ) {
                    throw new Exception('Claude CLI rate limit: '.$errorMsg);
                }

                throw new Exception('Claude CLI failed (exit code '.$returnCode.'): '.$errorMsg);
            }

            if (empty($stdout)) {
                throw new Exception('Claude CLI returned empty response');
            }

            // Detect auth errors that Claude CLI reports via stdout with exit code 0
            if (str_contains($stdout, 'OAuth token has expired') || str_contains($stdout, 'authentication_error')) {
                throw new Exception('Claude CLI authentication failed: OAuth token expired. Run: claude login');
            }

            // Detect rate limit reported via stdout with exit code 0
            $lowerStdout = strtolower($stdout);
            if (str_contains($lowerStdout, "you've hit your limit")
                || str_contains($lowerStdout, 'you have hit your limit')
                || str_contains($lowerStdout, 'usage limit')
                || str_contains($lowerStdout, 'daily limit')
                || str_contains($lowerStdout, 'weekly limit')
            ) {
                throw new Exception('Claude CLI rate limit: '.trim($stdout));
            }

            return trim($stdout);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($process, SIGKILL);
                }
                proc_close($process);
            }
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
     * Check if Claude CLI is available (for health checks)
     */
    public function isClaudeCLIAvailable(): bool
    {
        $claudePath = config('services.anthropic.cli_path', 'claude');

        return ProcessFacade::run(['which', $claudePath])->successful();
    }

    /**
     * Is PLOS running in fail-closed offline mode?
     *
     * INTERNET offline only. Blocks Claude CLI and all external cloud LLM APIs.
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
            $value = app(\App\Services\SystemConfigService::class)
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
     * Check if any Claude service is available (CLI or API)
     */
    public function isClaudeAvailable(): bool
    {
        return $this->isClaudeCLIAvailable() || ! empty(config('services.anthropic.api_key'));
    }

    /**
     * Check if Claude Agent SDK Proxy is available
     */
    public function isAgentProxyAvailable(): bool
    {
        try {
            $response = Http::connectTimeout(5)->timeout(3)->get("{$this->agentProxyUrl}/health");
            if ($response->successful()) {
                $data = $response->json();

                return ($data['status'] ?? '') === 'ok' && ($data['claudeAvailable'] ?? false);
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Call Claude Agent SDK Proxy for tool-enabled AI processing
     *
     * This provides full MCP tool access via Claude Code when Ollama is unavailable.
     *
     * @param  string  $prompt  User prompt
     * @param  array  $config  Configuration options
     * @return string AI response
     */
    public function callAgentProxy(string $prompt, array $config = []): string
    {
        $endpoint = $config['use_plos_context'] ?? false
            ? "{$this->agentProxyUrl}/query-plos"
            : "{$this->agentProxyUrl}/query-tools";

        $payload = [
            'prompt' => $prompt,
            'system_prompt' => $config['system_prompt'] ?? null,
            'model' => $config['model'] ?? null,  // e.g., 'sonnet', 'opus'
            'timeout' => ($config['timeout'] ?? (int) config('services.claude.agent_proxy_timeout', 120)) * 1000, // Convert to ms
            'output_format' => $config['output_format'] ?? 'text',
        ];

        // Add tools if specified
        if (! empty($config['tools'])) {
            $payload['tools'] = $config['tools'];
        }

        // Add PLOS-specific tools if using PLOS context
        if ($config['use_plos_context'] ?? false) {
            $payload['plos_tools'] = $config['plos_tools'] ?? ['plos', 'rag', 'web-research'];
        }

        // For automated workflows, skip permission prompts
        if ($config['skip_permissions'] ?? false) {
            $payload['dangerously_skip_permissions'] = true;
        }

        try {
            $response = Http::connectTimeout(5)->timeout($config['timeout'] ?? 120)
                ->post($endpoint, $payload);

            if (! $response->successful()) {
                throw new Exception('Agent proxy request failed: '.$response->status());
            }

            $data = $response->json();

            if (! ($data['success'] ?? false)) {
                throw new Exception('Agent proxy error: '.($data['error'] ?? 'Unknown error'));
            }

            return $data['content'] ?? '';

        } catch (Exception $e) {
            Log::error('Agent proxy call failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);
            throw $e;
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
                $response = Http::connectTimeout(5)->timeout($toolTimeout)
                    ->post("{$targetUrl}/api/chat", [
                        'model' => $targetModel,
                        'messages' => $messages,
                        'tools' => $tools,
                        'temperature' => $temperature,
                        'stream' => false,
                        'options' => [
                            'num_predict' => $maxTokens,
                        ],
                    ]);

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

                // Check if there are tool calls
                if (isset($message['tool_calls']) && ! empty($message['tool_calls'])) {
                    // Execute each tool call
                    foreach ($message['tool_calls'] as $toolCall) {
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
                    Log::info('Retrying query without tools due to malformed tool response');

                    return $this->processWithAI($prompt, $config);
                }

                return $content;

            } catch (Exception $e) {
                Log::error('Tool calling iteration failed', [
                    'iteration' => $iterations,
                    'error' => $e->getMessage(),
                ]);

                // Fallback chain: Ollama failed → try Agent SDK Proxy → simple CLI
                if ($iterations === 1) {
                    // Try Agent SDK Proxy first (has MCP tool access)
                    if ($this->isAgentProxyAvailable()) {
                        Log::info('Ollama tool calling failed, falling back to Claude Agent SDK Proxy');
                        try {
                            return $this->callAgentProxy($prompt, array_merge($config, [
                                'use_plos_context' => true,
                                'skip_permissions' => true,
                            ]));
                        } catch (Exception $proxyError) {
                            Log::warning('Agent SDK Proxy also failed', [
                                'error' => $proxyError->getMessage(),
                            ]);
                        }
                    }

                    // Last resort: simple text generation (no tools)
                    Log::info('Falling back to simple AI processing (no tools)');

                    return $this->processWithAI($prompt, $config);
                }

                throw $e;
            }
        }

        return "Maximum tool calling iterations reached ($maxIterations)";
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

        $iterations = 0;
        while ($iterations < $maxIterations) {
            $iterations++;

            try {
                // Call Ollama with streaming enabled using Guzzle directly
                $client = new \GuzzleHttp\Client;
                $response = $client->post("{$targetUrl}/api/chat", [
                    'json' => [
                        'model' => $streamModel,
                        'messages' => $messages,
                        'tools' => $tools,
                        'temperature' => $temperature,
                        'stream' => true, // Enable streaming
                        'options' => [
                            'num_predict' => $maxTokens,
                        ],
                    ],
                    'stream' => true, // Enable Guzzle streaming
                    'timeout' => (int) config('services.ollama.streaming_timeout', 240),
                ]);

                if ($response->getStatusCode() !== 200) {
                    Log::error('Ollama streaming failed', [
                        'status' => $response->getStatusCode(),
                    ]);
                    yield json_encode(['type' => 'error', 'content' => 'AI request failed'])."\n";

                    return;
                }

                // Get the stream body
                $stream = $response->getBody();
                $buffer = '';

                $fullMessage = '';
                $assistantMessage = null;
                $toolCalls = [];

                // Read stream line by line
                while (! $stream->eof()) {
                    $char = $stream->read(1);
                    if ($char === "\n") {
                        $line = trim($buffer);
                        $buffer = '';

                        if (empty($line)) {
                            continue;
                        }

                        $chunk = json_decode($line, true);
                        if (! $chunk) {
                            continue;
                        }

                        $message = $chunk['message'] ?? null;
                        if (! $message) {
                            continue;
                        }

                        // Extract content and yield it
                        $content = $message['content'] ?? '';
                        if (! empty($content)) {
                            $fullMessage .= $content;
                            yield json_encode(['type' => 'content', 'content' => $content])."\n";
                        }

                        // Check for tool calls
                        if (isset($message['tool_calls']) && ! empty($message['tool_calls'])) {
                            $toolCalls = $message['tool_calls'];
                        }

                        // Check if done
                        if ($chunk['done'] ?? false) {
                            $assistantMessage = $message;
                            break;
                        }
                    } else {
                        $buffer .= $char;
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

                        yield json_encode(['type' => 'tool_call', 'server' => $server, 'tool' => $tool, 'arguments' => $arguments])."\n";

                        try {
                            $toolResult = $this->mcpRouter->callTool($server, $tool, $arguments);

                            // Add tool result to conversation with instruction to summarize.
                            $messages[] = [
                                'role' => 'tool',
                                'content' => $this->formatMcpToolResultForPrompt($server, $tool, $toolResult),
                            ];

                            yield json_encode(['type' => 'tool_result', 'server' => $server, 'tool' => $tool])."\n";

                        } catch (Exception $e) {
                            Log::error('Tool execution failed during streaming', [
                                'server' => $server,
                                'tool' => $tool,
                                'error' => $e->getMessage(),
                            ]);

                            $messages[] = [
                                'role' => 'tool',
                                'content' => $this->formatMcpToolResultForPrompt($server, $tool, ['error' => $e->getMessage()], true),
                            ];

                            yield json_encode(['type' => 'tool_error', 'server' => $server, 'tool' => $tool, 'error' => $e->getMessage()])."\n";
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
                    Log::info('Streaming: Retrying without tools due to malformed response');
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
        $claudeCLIAvailable = $this->isClaudeCLIAvailable();
        $apiKey = config('services.anthropic.api_key');
        $apiConfigured = ! empty($apiKey);
        $agentProxyAvailable = $this->isAgentProxyAvailable();

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
                'claude_fallback' => $claudeCLIAvailable,
            ],
            'claude' => [
                'available' => $claudeCLIAvailable || $apiConfigured || $agentProxyAvailable,
                'cli_available' => $claudeCLIAvailable,
                'api_configured' => $apiConfigured,
                'path' => config('services.anthropic.cli_path', 'claude'),
                'configured' => $claudeCLIAvailable || $apiConfigured,
            ],
            'agent_proxy' => [
                'available' => $agentProxyAvailable,
                'url' => $this->agentProxyUrl,
                'description' => 'Claude Agent SDK with MCP tool access',
            ],
            'mode' => config('services.ai.default_mode', 'auto'),
            'fallback_chain' => [
                '1' => 'Ollama (local GPU)',
                '2' => 'Claude Agent SDK Proxy (MCP tools)',
                '3' => 'Claude CLI (text only)',
            ],
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

        if (empty($messages)) {
            throw new Exception('No messages provided for chat');
        }

        // Extract model prefix (ollama: or claude:)
        $useOllama = true;
        if (str_starts_with($model, 'claude:')) {
            $useOllama = false;
            $model = str_replace('claude:', '', $model);
        } elseif (str_starts_with($model, 'ollama:')) {
            $model = str_replace('ollama:', '', $model);
        }

        try {
            if ($useOllama) {
                return $this->callOllamaChat($messages, $model, $temperature, $maxTokens);
            } else {
                return $this->callClaudeChat($messages, $model, $temperature, $maxTokens);
            }
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
    private function callOllamaChat(array $messages, string $model, float $temperature, int $maxTokens): array
    {
        // Sanitize all message content before json_encode
        foreach ($messages as &$msg) {
            if (isset($msg['content']) && is_string($msg['content'])) {
                $msg['content'] = $this->sanitizeUtf8($msg['content']);
            }
        }
        unset($msg);

        $chatTimeout = (int) config('services.ollama.chat_timeout', 90);

        $response = Http::connectTimeout(5)->timeout($chatTimeout)
            ->post("{$this->ollamaUrl}/api/chat", [
                'model' => $model,
                'messages' => $messages,
                'think' => false,
                'temperature' => $temperature,
                'stream' => false,
                'options' => [
                    'num_predict' => $maxTokens,
                    'num_ctx' => 4096,
                ],
            ]);

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

    /**
     * Claude chat not supported (API removed - use Claude Code CLI via processWithAI instead)
     */
    private function callClaudeChat(array $messages, string $model, float $temperature, int $maxTokens): array
    {
        throw new Exception('Claude API chat not supported. Use Ollama or convert to single prompt for Claude CLI.');
    }

    private function getProcessEnvironment(): array
    {
        $environment = getenv();

        return is_array($environment) ? $environment : [];
    }
}

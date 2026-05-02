<?php

namespace App\Engine;

use App\DTOs\TrustEnvelope;
use App\Services\AIService;
use App\Services\OfflinePolicyService;
use App\Services\TrustBoundaryFormatterService;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Ollama Tool Caller
 *
 * Enables Ollama to call MCP tools via prompt engineering.
 * Uses a two-step process:
 * 1. Ask Ollama which tool to use (temperature=0.0 for determinism)
 * 2. Execute tool and format response
 *
 * This bridges the gap between Ollama (no native tool calling) and
 * MCP protocol (designed for tool calling).
 *
 * E01 Phase 3: Now uses AIService for resilient AI operations with
 * circuit breaker and automatic failover.
 */
class OllamaToolCaller
{
    private MCPRouter $mcpRouter;

    private AIRouter $aiRouter;

    private AIService $aiService;

    private TrustBoundaryFormatterService $trustBoundaryFormatter;

    private float $temperature;

    private int $maxTokens;

    private int $timeout;

    public function __construct(
        ?AIService $aiService = null,
        ?TrustBoundaryFormatterService $trustBoundaryFormatter = null,
        ?MCPRouter $mcpRouter = null
    ) {
        $this->mcpRouter = $mcpRouter ?? new MCPRouter;
        $this->aiRouter = new AIRouter;
        $this->aiService = $aiService ?? app(AIService::class);
        $this->trustBoundaryFormatter = $trustBoundaryFormatter ?? app(TrustBoundaryFormatterService::class);
        $this->temperature = config('mcp.ollama_tool_calling.temperature', 0.0);
        $this->maxTokens = config('mcp.ollama_tool_calling.max_tokens', 500);
        $this->timeout = config('mcp.ollama_tool_calling.timeout', 10);
    }

    /**
     * Process user request with tool calling
     *
     * @param  string  $userRequest  User's natural language request
     * @param  array  $options  Additional options
     * @return string Final response
     */
    public function process(string $userRequest, array $options = []): string
    {
        try {
            // Step 1: Get available tools
            $tools = $this->getToolCatalog();

            if (empty($tools)) {
                return $this->directResponse($userRequest);
            }

            // Step 2: Ask Ollama which tool to use
            $toolDecision = $this->selectTool($userRequest, $tools);

            // Step 3: Execute tool if needed
            if ($toolDecision['tool'] !== null) {
                $toolResult = $this->executeTool($toolDecision);

                // Step 4: Format final response
                return $this->formatResponse($userRequest, $toolDecision, $toolResult);
            }

            // No tool needed, direct response
            return $toolDecision['response'] ?? $this->directResponse($userRequest);

        } catch (Exception $e) {
            Log::error('Ollama Tool Caller Error', [
                'request' => $userRequest,
                'error' => $e->getMessage(),
            ]);

            // Fallback to direct AI response
            return $this->directResponse($userRequest);
        }
    }

    /**
     * Select appropriate tool using Ollama
     *
     * @param  string  $userRequest  User's request
     * @param  array  $tools  Available tools
     * @return array Tool decision
     */
    private function selectTool(string $userRequest, array $tools): array
    {
        $toolCatalog = $this->buildToolCatalog($tools);

        $prompt = <<<PROMPT
You are a tool-calling assistant. Analyze the user's request and determine if any available tool should be used.

Available tools:
{$toolCatalog}

User request: "{$userRequest}"

Respond ONLY with valid JSON in this exact format:
{
  "tool": "tool_name_or_null",
  "server": "server_name_or_null",
  "params": {},
  "reasoning": "brief explanation"
}

If no tool is needed, set "tool" to null and include a "response" field with your answer.
If a tool is needed, set "tool" to the tool name, "server" to the server name, and "params" to the required parameters.

JSON response:
PROMPT;

        // E01 Phase 3: Use AIService for resilient tool selection
        $result = $this->aiService->process($prompt, [
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ]);

        if (! $result['success']) {
            Log::warning('Tool selection failed via AIService', ['error' => $result['error'] ?? 'Unknown']);

            return ['tool' => null, 'response' => $this->directResponse($userRequest)];
        }

        $response = $result['response'];

        // Parse JSON response
        $decoded = $this->parseJson($response);

        if ($decoded === null) {
            Log::warning('Failed to parse tool selection JSON', ['response' => $response]);

            return ['tool' => null, 'response' => $this->directResponse($userRequest)];
        }

        return $decoded;
    }

    /**
     * Build human-readable tool catalog
     */
    private function buildToolCatalog(array $tools): string
    {
        $catalog = [];

        foreach ($tools as $i => $tool) {
            $num = $i + 1;
            $name = $tool['name'] ?? 'unknown';
            $description = $tool['description'] ?? 'No description';
            $server = $tool['server'] ?? 'unknown';
            $params = $this->formatParameters($tool['parameters'] ?? []);

            $catalog[] = "{$num}. {$name} (server: {$server})\n   Description: {$description}\n   Parameters: {$params}";
        }

        return implode("\n\n", $catalog);
    }

    /**
     * Format tool parameters for catalog
     */
    private function formatParameters(array $paramSchema): string
    {
        if (empty($paramSchema['properties'])) {
            return 'none';
        }

        $parts = [];
        foreach ($paramSchema['properties'] as $name => $info) {
            $type = $info['type'] ?? 'any';
            $required = in_array($name, $paramSchema['required'] ?? []) ? 'required' : 'optional';
            $desc = $info['description'] ?? '';
            $parts[] = "{$name} ({$type}, {$required}) - {$desc}";
        }

        return implode(', ', $parts);
    }

    /**
     * Execute selected tool via MCPRouter
     */
    private function executeTool(array $toolDecision): mixed
    {
        $server = $toolDecision['server'] ?? null;
        $tool = $toolDecision['tool'] ?? null;
        $params = $toolDecision['params'] ?? [];

        if (! $server || ! $tool) {
            throw new Exception('Invalid tool decision: missing server or tool name');
        }

        Log::info('Executing MCP tool', [
            'server' => $server,
            'tool' => $tool,
            'params' => $params,
        ]);

        return $this->mcpRouter->callTool($server, $tool, $params);
    }

    /**
     * Format final response using tool result
     */
    private function formatResponse(string $userRequest, array $toolDecision, mixed $toolResult): string
    {
        $toolName = $toolDecision['tool'] ?? 'unknown';
        $resultJson = json_encode($toolResult, JSON_PRETTY_PRINT);
        $formattedToolResult = $this->trustBoundaryFormatter->format(new TrustEnvelope(
            sourceType: 'tool_result',
            contentType: 'application/json',
            origin: "mcp:{$toolName}",
            payload: $resultJson === false ? '' : $resultJson,
            maxChars: (int) config('mcp.ollama_tool_calling.tool_result_max_chars', 6000),
        ));

        $prompt = <<<PROMPT
User asked: "{$userRequest}"

I used the tool "{$toolName}" which returned this result:
{$formattedToolResult}

Provide a clear, helpful response to the user based on this tool result. Be concise and natural.

Response:
PROMPT;

        // E01 Phase 3: Use AIService for resilient response formatting
        $result = $this->aiService->process($prompt, [
            'temperature' => 0.1,
        ]);

        return $result['success'] ? $result['response'] : 'I processed the tool result but had trouble formatting the response.';
    }

    /**
     * Direct AI response without tool calling
     * E01 Phase 3: Uses AIService for resilient fallback
     */
    private function directResponse(string $userRequest): string
    {
        $result = $this->aiService->process($userRequest, [
            'temperature' => 0.1,
        ]);

        return $result['success'] ? $result['response'] : 'I apologize, but I encountered an error processing your request.';
    }

    /**
     * Parse JSON response, handling common issues
     */
    private function parseJson(string $response): ?array
    {
        // Remove markdown code blocks if present
        $cleaned = preg_replace('/```json\s*|\s*```/', '', $response);
        $cleaned = trim($cleaned);

        // Try to extract JSON if there's extra text
        if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
            $cleaned = $matches[0];
        }

        $decoded = json_decode($cleaned, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get tool catalog for display
     */
    public function getToolCatalog(): array
    {
        try {
            $profile = app(OfflinePolicyService::class)->activeProfile();

            return $this->mcpRouter->getAvailableToolsForProfile($profile);
        } catch (\Throwable $e) {
            Log::debug('OllamaToolCaller: profile-aware catalog failed, using raw catalog', [
                'error' => $e->getMessage(),
            ]);

            return $this->mcpRouter->getAvailableTools();
        }
    }

    /**
     * Check if tool calling is available
     * E01 Phase 3: Checks AIService health instead of direct Ollama check
     */
    public function isAvailable(): bool
    {
        $health = $this->aiService->getHealthStats();
        $hasHealthyProvider = false;

        foreach ($health['providers'] ?? [] as $provider) {
            if (($provider['circuit_state'] ?? 'closed') !== 'open') {
                $hasHealthyProvider = true;
                break;
            }
        }

        return $hasHealthyProvider && count($this->getToolCatalog()) > 0;
    }

    /**
     * Get status information
     * E01 Phase 3: Enhanced with AIService health stats
     */
    public function getStatus(): array
    {
        $tools = $this->getToolCatalog();
        $servers = $this->mcpRouter->getAllServersStatus();
        $aiHealth = $this->aiService->getHealthStats();

        return [
            'available' => $this->isAvailable(),
            'ollama_available' => $this->aiRouter->isOllamaAvailable(),
            'ai_health' => $aiHealth,
            'total_tools' => count($tools),
            'servers' => $servers,
            'tools_by_server' => $this->groupToolsByServer($tools),
        ];
    }

    /**
     * Group tools by server for status display
     */
    private function groupToolsByServer(array $tools): array
    {
        $grouped = [];

        foreach ($tools as $tool) {
            $server = $tool['server'] ?? 'unknown';

            if (! isset($grouped[$server])) {
                $grouped[$server] = [];
            }

            $grouped[$server][] = $tool['name'] ?? 'unknown';
        }

        return $grouped;
    }
}

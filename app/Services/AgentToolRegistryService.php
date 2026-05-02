<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agent Tool Registry Service
 *
 * Database-driven tool resolution for agents. Maps tool names declared
 * in SKILL.md frontmatter to actual service classes and methods.
 *
 * Tool registry is stored in agent_tool_registry table (primary) with
 * config/agent_tools.php as fallback. New tools can be added via DB
 * without code changes — agents can even propose tools for human approval.
 *
 * Adding new tools: INSERT into agent_tool_registry. No code changes needed.
 * Adding new agents: resources/agents/skills/{name}/SKILL.md only. No code changes.
 */
class AgentToolRegistryService
{
    private ?array $toolConfig = null;

    private ?AgentGuardrailService $guardrailService = null;

    private ?\App\Engine\MCPRouter $mcpRouter = null;

    private function getGuardrailService(): AgentGuardrailService
    {
        if ($this->guardrailService === null) {
            $this->guardrailService = app(AgentGuardrailService::class);
        }

        return $this->guardrailService;
    }

    private function getMCPRouter(): \App\Engine\MCPRouter
    {
        if ($this->mcpRouter === null) {
            $this->mcpRouter = app(\App\Engine\MCPRouter::class);
        }

        return $this->mcpRouter;
    }

    private function getToolConfig(): array
    {
        if ($this->toolConfig === null) {
            $this->toolConfig = $this->loadToolsFromDB();
        }

        return $this->toolConfig;
    }

    /**
     * Load tools from database, fall back to config file
     */
    private function loadToolsFromDB(): array
    {
        try {
            $rows = DB::select('
                SELECT name, service_class, method, description, parameters,
                       returns_description, permissions, risk_level, category,
                       requires_confirmation, max_calls_per_run, mcp_server,
                       mcp_tool, max_tokens_per_call
                FROM agent_tool_registry
                WHERE enabled = 1
            ');

            if (! empty($rows)) {
                $tools = [];
                foreach ($rows as $row) {
                    $tools[$row->name] = [
                        'service' => $row->service_class,
                        'method' => $row->method,
                        'description' => $row->description,
                        'parameters' => json_decode($row->parameters ?? '[]', true) ?? [],
                        'returns' => $row->returns_description,
                        'permissions' => json_decode($row->permissions ?? '[]', true) ?? [],
                        'risk_level' => $row->risk_level ?? 'read',
                        'category' => $row->category ?? null,
                        'requires_confirmation' => (bool) ($row->requires_confirmation ?? false),
                        'max_calls_per_run' => $row->max_calls_per_run ?? null,
                        'mcp_server' => $row->mcp_server ?? null,
                        'mcp_tool' => $row->mcp_tool ?? null,
                        'max_tokens_per_call' => $row->max_tokens_per_call ?? null,
                    ];
                }

                return $tools;
            }
        } catch (\Exception $e) {
            // Table may not exist yet (pre-migration) — fall back to config
            Log::debug('AgentToolRegistry: DB read failed, using config fallback', ['error' => $e->getMessage()]);
        }

        return config('agent_tools', []);
    }

    /**
     * Register a new tool in the database
     *
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function registerTool(array $toolDef): array
    {
        $required = ['name', 'service_class', 'method', 'description'];
        foreach ($required as $field) {
            if (empty($toolDef[$field])) {
                return ['success' => false, 'error' => "Missing required field: {$field}"];
            }
        }

        // Validate service class exists
        if (! class_exists($toolDef['service_class'])) {
            return ['success' => false, 'error' => "Service class not found: {$toolDef['service_class']}"];
        }

        // Validate method exists
        if (! method_exists($toolDef['service_class'], $toolDef['method'])) {
            return ['success' => false, 'error' => "Method not found: {$toolDef['service_class']}::{$toolDef['method']}"];
        }

        try {
            DB::insert('
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, source, proposed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    service_class = VALUES(service_class),
                    method = VALUES(method),
                    description = VALUES(description),
                    parameters = VALUES(parameters),
                    returns_description = VALUES(returns_description),
                    permissions = VALUES(permissions),
                    updated_at = NOW()
            ', [
                $toolDef['name'],
                $toolDef['service_class'],
                $toolDef['method'],
                $toolDef['description'],
                json_encode($toolDef['parameters'] ?? []),
                $toolDef['returns'] ?? null,
                json_encode($toolDef['permissions'] ?? []),
                $toolDef['source'] ?? 'manual',
                $toolDef['proposed_by'] ?? null,
            ]);

            // Clear cache so next getToolConfig() reloads from DB
            $this->toolConfig = null;

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Disable a tool by name (soft delete)
     */
    public function disableTool(string $name): array
    {
        $affected = DB::update('UPDATE agent_tool_registry SET enabled = 0 WHERE name = ?', [$name]);
        $this->toolConfig = null;

        return ['success' => $affected > 0, 'disabled' => $name];
    }

    /**
     * Enable a tool by name
     */
    public function enableTool(string $name): array
    {
        $affected = DB::update('UPDATE agent_tool_registry SET enabled = 1 WHERE name = ?', [$name]);
        $this->toolConfig = null;

        return ['success' => $affected > 0, 'enabled' => $name];
    }

    /**
     * Propose a new tool (by an agent) — requires human approval before enabled
     */
    public function proposeTool(array $toolDef, string $agentId): array
    {
        $toolDef['source'] = 'agent_proposed';
        $toolDef['proposed_by'] = $agentId;
        $result = $this->registerTool($toolDef);

        if ($result['success']) {
            // Mark as disabled until approved
            DB::update("UPDATE agent_tool_registry SET enabled = 0 WHERE name = ? AND source = 'agent_proposed'", [$toolDef['name']]);
            $this->toolConfig = null;
        }

        return $result;
    }

    /**
     * Approve a proposed tool (by human)
     */
    public function approveTool(string $name, string $approvedBy = 'human'): array
    {
        $affected = DB::update("
            UPDATE agent_tool_registry
            SET enabled = 1, approved_by = ?, approved_at = NOW()
            WHERE name = ? AND source = 'agent_proposed'
        ", [$approvedBy, $name]);

        $this->toolConfig = null;

        return ['success' => $affected > 0, 'approved' => $name];
    }

    /**
     * Get pending tool proposals awaiting approval
     */
    public function getPendingProposals(): array
    {
        $rows = DB::select("
            SELECT name, service_class, method, description, proposed_by, created_at
            FROM agent_tool_registry
            WHERE source = 'agent_proposed' AND enabled = 0 AND approved_at IS NULL
        ");

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * Get tool definitions available to a specific agent
     *
     * @param  string  $agentId  Agent name (matches skill name)
     * @param  array  $agentPermissions  Permissions from SKILL.md frontmatter
     * @return array Filtered tool definitions the agent can use
     */
    /**
     * Get tools for a specific phase within an agent's tool set.
     * If phaseTools is null, returns all agent tools (backwards compatible).
     */
    public function getToolsForPhase(array $allAgentTools, ?array $phaseToolNames): array
    {
        if ($phaseToolNames === null) {
            return $allAgentTools;
        }

        $phaseTools = [];
        foreach ($phaseToolNames as $name) {
            if (isset($allAgentTools[$name])) {
                $phaseTools[$name] = $allAgentTools[$name];
            }
        }

        return $phaseTools;
    }

    public function getToolsForAgent(string $agentId, array $agentPermissions = []): array
    {
        if (empty($agentPermissions)) {
            // Load from skill if not provided
            $skillLoader = app(SkillLoaderService::class);
            $config = $skillLoader->getSkillConfig($agentId);
            $agentPermissions = $config['permissions'] ?? [];
            // Also check if skill declares specific tool names
            $declaredTools = $config['tools'] ?? [];
        } else {
            $declaredTools = [];
        }

        $allTools = $this->getToolConfig();
        $available = $this->filterToolsForAgent($allTools, $declaredTools, $agentPermissions);
        $missingDeclared = $this->findMissingDeclaredTools($available, $declaredTools);

        // Long-lived workers may hold a stale in-memory registry after deploys or
        // migrations. Refresh once from DB before treating a declared tool as missing.
        if (! empty($missingDeclared)) {
            $this->toolConfig = null;
            $allTools = $this->getToolConfig();
            $available = $this->filterToolsForAgent($allTools, $declaredTools, $agentPermissions);
            $missingDeclared = $this->findMissingDeclaredTools($available, $declaredTools);
        }

        // Warn about declared tools that didn't resolve to any registry entry
        foreach ($missingDeclared as $declared) {
            Log::warning('AgentToolRegistry: declared tool not found in registry', [
                'agent' => $agentId,
                'tool' => $declared,
            ]);
            $this->trackMissingTool($agentId, $declared);
        }

        return $available;
    }

    private function filterToolsForAgent(array $allTools, array $declaredTools, array $agentPermissions): array
    {
        $available = [];

        foreach ($allTools as $toolName => $toolDef) {
            // If skill declares specific tools, filter to those
            if (! empty($declaredTools) && ! in_array($toolName, $declaredTools, true)) {
                // Check if the tool name matches a service class name (e.g., "RecordHintService")
                $matchByService = false;
                foreach ($declaredTools as $declared) {
                    if (str_contains($toolDef['service'], $declared)) {
                        $matchByService = true;
                        break;
                    }
                }
                if (! $matchByService) {
                    continue;
                }
            }

            // Check permissions (AND-based: agent must have ALL required permissions)
            $requiredPerms = $toolDef['permissions'] ?? [];
            if (! empty($requiredPerms) && ! empty($agentPermissions)) {
                $hasAllPermissions = true;
                foreach ($requiredPerms as $perm) {
                    if (! in_array($perm, $agentPermissions, true)) {
                        $hasAllPermissions = false;
                        break;
                    }
                }
                if (! $hasAllPermissions) {
                    continue;
                }
            }

            $available[$toolName] = $toolDef;
        }

        return $available;
    }

    private function findMissingDeclaredTools(array $available, array $declaredTools): array
    {
        $missing = [];

        foreach ($declaredTools as $declared) {
            if (isset($available[$declared])) {
                continue;
            }

            $matchedByService = false;
            foreach ($available as $toolDef) {
                if (str_contains($toolDef['service'] ?? '', $declared)) {
                    $matchedByService = true;
                    break;
                }
            }

            if (! $matchedByService) {
                $missing[] = $declared;
            }
        }

        return $missing;
    }

    /**
     * Track missing tool occurrences. After 5 consecutive runs with the same missing tool,
     * create a one-time review queue item so the issue gets human attention.
     */
    private function trackMissingTool(string $agentId, string $toolName): void
    {
        $cacheKey = "missing_tool:{$agentId}:{$toolName}";
        $alertKey = "missing_tool_alerted:{$agentId}:{$toolName}";

        $count = (int) Cache::get($cacheKey, 0) + 1;
        Cache::put($cacheKey, $count, 86400); // 24h TTL

        if ($count >= 5 && ! Cache::has($alertKey)) {
            Cache::put($alertKey, true, 604800); // Don't alert again for 7 days

            try {
                DB::insert(
                    "INSERT INTO agent_review_queue (agent_id, review_type, title, summary, status, confidence, details, token, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())",
                    [
                        $agentId,
                        'agent_tool_missing',
                        "Agent '{$agentId}' missing tool: {$toolName}",
                        "Tool '{$toolName}' declared in SKILL.md but not found in agent_tool_registry after {$count} consecutive runs.",
                        1.0,
                        json_encode(['agent_id' => $agentId, 'tool_name' => $toolName, 'occurrences' => $count]),
                        bin2hex(random_bytes(32)),
                    ]
                );
                Log::warning('AgentToolRegistry: created review item for persistent missing tool', [
                    'agent' => $agentId, 'tool' => $toolName, 'occurrences' => $count,
                ]);
            } catch (\Throwable $e) {
                Log::debug('AgentToolRegistry: failed to create missing tool review item', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Build tool descriptions for LLM prompt injection
     *
     * @param  array  $tools  Tool definitions from getToolsForAgent()
     * @return string Prompt-friendly tool descriptions
     */
    public function buildToolDescriptions(array $tools): string
    {
        if (empty($tools)) {
            return '';
        }

        $lines = ["## Available Tools\n"];
        $lines[] = "IMPORTANT: To call a tool, you MUST output a JSON block in EXACTLY this format:\n```json\n{\"tool\": \"tool_name\", \"params\": {\"key\": \"value\"}}\n```\n";
        $lines[] = "RULES:\n- You MUST call at least one tool before providing any findings or conclusions.\n- Do NOT describe tool calls in natural language — actually output the JSON block above.\n- Do NOT fabricate, invent, or hallucinate tool results. Only report data returned by actual tool calls.\n- One tool call per response. After receiving results, continue analysis or call another tool.\n- When done with all tool calls, respond with plain text (no JSON block) for your final answer.\n";

        foreach ($tools as $name => $def) {
            $params = [];
            $paramDefs = $def['parameters'] ?? [];

            // Normalize JSON Schema format ({type: object, properties: {...}}) to dict format
            if (isset($paramDefs['type']) && $paramDefs['type'] === 'object' && isset($paramDefs['properties'])) {
                $requiredFields = is_array($paramDefs['required'] ?? null) ? $paramDefs['required'] : [];
                $normalized = [];
                foreach ($paramDefs['properties'] as $propName => $propDef) {
                    $normalized[$propName] = array_merge(
                        is_array($propDef) ? $propDef : ['type' => (string) $propDef],
                        ['required' => in_array($propName, $requiredFields)]
                    );
                }
                $paramDefs = $normalized;
            }

            foreach ($paramDefs as $pName => $pDef) {
                // List format: [{name: "x", type: "string", ...}] — promote name from inner key
                if (is_int($pName) && is_array($pDef) && isset($pDef['name'])) {
                    $pName = $pDef['name'];
                }
                if (! is_array($pDef)) {
                    continue; // Skip scalar values (malformed entries)
                }
                $req = ($pDef['required'] ?? false) ? 'required' : 'optional';
                $type = $pDef['type'] ?? 'any';
                $desc = $pDef['description'] ?? $type;
                $params[] = "  - {$pName} ({$req}, {$type}): {$desc}";
            }
            $paramStr = ! empty($params) ? "\n".implode("\n", $params) : ' (no parameters)';
            $lines[] = "### {$name}\n{$def['description']}{$paramStr}\n";
        }

        $lines[] = "REMINDER: You MUST call tools using the JSON format above. Start by calling a tool NOW — do not respond with text only on your first turn. Example:\n```json\n{\"tool\": \"get_tree_statistics\", \"params\": {}}\n```\nWhen you have completed your analysis and have no more tools to call, respond with your final answer as plain text (no JSON tool block).";

        return implode("\n", $lines);
    }

    /**
     * Execute a tool by name with given parameters
     *
     * @param  string  $toolName  Tool name from config
     * @param  array  $params  Parameters to pass
     * @param  array  $context  Runtime context (tree_id, agent_id, etc.)
     * @return array ['success' => bool, 'result' => mixed, 'error' => ?string]
     */
    public function executeTool(string $toolName, array $params, array $context = []): array
    {
        $startTime = microtime(true);
        $tools = $this->getToolConfig();
        $simulate = $context['simulate'] ?? false;

        if (! isset($tools[$toolName])) {
            $this->recordToolCall($toolName, null, null, $context, 'agent', false, $startTime, "Unknown tool: {$toolName}");

            return ['success' => false, 'result' => null, 'error' => "Unknown tool: {$toolName}"];
        }

        $toolDef = $tools[$toolName];
        $agentId = $context['agent_id'] ?? null;

        // AG-19: Simulation mode — validate params, check guardrails, but don't execute
        if ($simulate) {
            return $this->simulateToolCall($toolName, $toolDef, $params, $context, $agentId);
        }

        // 1. Blocked tools are rejected immediately
        if (($toolDef['risk_level'] ?? 'read') === 'blocked') {
            Log::warning('AgentToolRegistry: Blocked tool attempted', ['tool' => $toolName, 'agent_id' => $agentId]);
            $this->recordToolCall($toolName, $toolDef['mcp_server'] ?? null, $toolDef['mcp_tool'] ?? null, $context, 'agent', false, $startTime, 'Blocked by security policy', riskLevel: 'blocked');

            return [
                'success' => false,
                'result' => null,
                'error' => "Tool '{$toolName}' is blocked by security policy",
                'result_text' => "Error: Tool '{$toolName}' is blocked by security policy.",
                'guardrail_blocked' => true,
            ];
        }

        // 2. Run guardrail validation
        // Fix C (2026-04-19): pass risk_level + mcp_server/mcp_tool into
        // the guardrail context so OfflinePolicyService can classify
        // `tool:<name>` operations correctly. Without this, non-default
        // profiles returned `unknown` for every registry tool and refused
        // legitimate read-class tools like `tool:file_read`. For
        // MCP-backed tools the policy layer routes to evaluateMcpTool
        // so the per-tool/trust-boundary/path classifier applies too.
        $context['risk_level'] = $context['risk_level'] ?? ($toolDef['risk_level'] ?? 'read');
        if (! empty($toolDef['mcp_server'])) {
            $context['mcp_server'] = $toolDef['mcp_server'];
        }
        if (! empty($toolDef['mcp_tool'])) {
            $context['mcp_tool'] = $toolDef['mcp_tool'];
        }
        try {
            $guardrailResult = $this->getGuardrailService()->validate("tool:{$toolName}", $context, $agentId);
            if (! $guardrailResult['allowed']) {
                Log::warning('AgentToolRegistry: Guardrail blocked tool', [
                    'tool' => $toolName,
                    'agent_id' => $agentId,
                    'reason' => $guardrailResult['reason'],
                ]);

                return [
                    'success' => false,
                    'result' => null,
                    'error' => $guardrailResult['reason'] ?? 'Blocked by guardrail',
                    'result_text' => "Error: Tool '{$toolName}' blocked by guardrail: ".($guardrailResult['reason'] ?? 'policy violation'),
                    'guardrail_blocked' => true,
                ];
            }

            // 3. Requires confirmation — request it and return pending
            if ($guardrailResult['requires_confirmation'] || ($toolDef['requires_confirmation'] ?? false)) {
                $token = $this->getGuardrailService()->requestConfirmation("tool:{$toolName}", $context, $agentId);

                return [
                    'success' => false,
                    'result' => null,
                    'error' => "Tool '{$toolName}' requires human confirmation. Approval requested (token: {$token}).",
                    'result_text' => "Tool '{$toolName}' requires human confirmation before execution. A notification has been sent for approval.",
                    'confirmation_pending' => true,
                    'confirmation_token' => $token,
                ];
            }
        } catch (Exception $e) {
            // Guardrail service failure is fail-closed — block tool execution
            Log::error('AgentToolRegistry: Guardrail check failed, blocking tool', ['tool' => $toolName, 'error' => $e->getMessage()]);
            $this->recordToolCall($toolName, null, null, $context, 'agent', false, $startTime, 'Guardrail check error: '.$e->getMessage(), 0, $params, riskLevel: $toolDef['risk_level'] ?? 'read');

            return [
                'success' => false,
                'result_text' => "Tool '{$toolName}' blocked: guardrail service unavailable. Error: ".$e->getMessage(),
            ];
        }

        // 4. Composite tools — route through ToolCompositionService pipeline
        if (($toolDef['category'] ?? null) === 'composed' && $toolDef['service'] === 'App\\Services\\ToolCompositionService' && $toolDef['method'] === 'executeComposition') {
            try {
                $compositionService = app(ToolCompositionService::class);
                $compositionParams = array_merge($params, $context, ['_composed_tool_name' => $toolName]);
                $result = $compositionService->executeComposition($compositionParams);
                $success = $result['success'] ?? false;
                $resultText = $result['result_text'] ?? 'No output';
                $this->recordToolCall($toolName, null, null, $context, 'agent', $success, $startTime, $success ? null : 'Composition failed', strlen($resultText), $params, riskLevel: $toolDef['risk_level'] ?? 'read');

                return [
                    'success' => $success,
                    'result' => $result['result'] ?? null,
                    'result_text' => $resultText,
                ];
            } catch (\Throwable $e) {
                $this->recordToolCall($toolName, null, null, $context, 'agent', false, $startTime, $e->getMessage(), null, $params, riskLevel: $toolDef['risk_level'] ?? 'read');

                return [
                    'success' => false,
                    'result' => null,
                    'error' => $e->getMessage(),
                    'result_text' => "Error executing composite tool {$toolName}: {$e->getMessage()}",
                ];
            }
        }

        // 5. MCP bridge tools — route through MCPRouter
        $mcpServer = $toolDef['mcp_server'] ?? null;
        $mcpTool = $toolDef['mcp_tool'] ?? null;
        if ($mcpServer && $mcpTool) {
            // Merge context values (tree_id, agent_id, etc.) into params for MCP tools
            $mergedParams = array_merge($context, $params);
            $result = $this->executeMCPTool($mcpServer, $mcpTool, $mergedParams, $toolName);
            $this->recordToolCall($toolName, $mcpServer, $mcpTool, $context, 'agent', $result['success'], $startTime, $result['error'] ?? null, strlen($result['result_text'] ?? ''), $params, riskLevel: $toolDef['risk_level'] ?? 'read');

            return $result;
        }

        // 6. Standard PHP service execution path
        $serviceClass = $toolDef['service'];
        $method = $toolDef['method'];

        try {
            $resolvedParams = $this->resolveParameters($toolDef, $params, $context);
            $service = app($serviceClass);
            $result = call_user_func_array([$service, $method], $resolvedParams);
            $serialized = $this->serializeResult($result);
            $toolSucceeded = ! $this->isLogicalFailureResult($result);
            $toolError = $toolSucceeded ? null : $this->extractLogicalFailureMessage($result);

            Log::debug('AgentToolRegistry: Tool executed', [
                'tool' => $toolName,
                'success' => $toolSucceeded,
                'result_size' => strlen($serialized),
            ]);

            $this->recordToolCall($toolName, null, null, $context, 'agent', $toolSucceeded, $startTime, $toolError, strlen($serialized), $params, riskLevel: $toolDef['risk_level'] ?? 'read');

            return [
                'success' => $toolSucceeded,
                'result' => $result,
                'error' => $toolError,
                'result_text' => $serialized,
            ];

        } catch (\Throwable $e) {
            Log::warning('AgentToolRegistry: Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            $this->recordToolCall($toolName, null, null, $context, 'agent', false, $startTime, $e->getMessage(), null, $params, riskLevel: $toolDef['risk_level'] ?? 'read');

            return [
                'success' => false,
                'result' => null,
                'error' => $e->getMessage(),
                'result_text' => "Error executing {$toolName}: {$e->getMessage()}",
            ];
        }
    }

    private function isLogicalFailureResult(mixed $result): bool
    {
        if (! is_array($result)) {
            return false;
        }

        if (array_key_exists('success', $result)) {
            return $result['success'] === false;
        }

        return isset($result['error']) && filled($result['error']);
    }

    private function extractLogicalFailureMessage(mixed $result): ?string
    {
        if (! is_array($result)) {
            return null;
        }

        if (isset($result['error']) && filled($result['error'])) {
            return (string) $result['error'];
        }

        if (array_key_exists('success', $result) && $result['success'] === false) {
            return isset($result['message']) && filled($result['message'])
                ? (string) $result['message']
                : 'Tool reported failure';
        }

        return null;
    }

    /**
     * Execute a tool via MCP bridge
     */
    private function executeMCPTool(string $mcpServer, string $mcpTool, array $params, string $registryName): array
    {
        try {
            $result = $this->getMCPRouter()->callTool($mcpServer, $mcpTool, $params);
            $serialized = $this->serializeResult($result);

            Log::debug('AgentToolRegistry: MCP tool executed', [
                'registry_name' => $registryName,
                'mcp_server' => $mcpServer,
                'mcp_tool' => $mcpTool,
                'result_size' => strlen($serialized),
            ]);

            return [
                'success' => true,
                'result' => $result,
                'result_text' => $serialized,
            ];
        } catch (Exception $e) {
            Log::warning('AgentToolRegistry: MCP tool execution failed', [
                'registry_name' => $registryName,
                'mcp_server' => $mcpServer,
                'mcp_tool' => $mcpTool,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'result' => null,
                'error' => $e->getMessage(),
                'result_text' => "Error executing MCP tool {$registryName} ({$mcpServer}/{$mcpTool}): {$e->getMessage()}",
            ];
        }
    }

    /**
     * Resolve parameters: validate required, apply defaults, order by method signature
     */
    private function resolveParameters(array $toolDef, array $params, array $context): array
    {
        $paramDefs = $toolDef['parameters'] ?? [];

        // Normalize JSON Schema format ({type: object, properties: {...}}) to dict format
        if (isset($paramDefs['type']) && $paramDefs['type'] === 'object' && isset($paramDefs['properties'])) {
            $requiredFields = is_array($paramDefs['required'] ?? null) ? $paramDefs['required'] : [];
            $normalized = [];
            foreach ($paramDefs['properties'] as $propName => $propDef) {
                $normalized[$propName] = array_merge(
                    is_array($propDef) ? $propDef : ['type' => (string) $propDef],
                    ['required' => in_array($propName, $requiredFields)]
                );
            }
            $paramDefs = $normalized;
        }

        // Normalize list format ([{name: "x", type: "string", ...}]) to dict format
        if (! empty($paramDefs) && isset($paramDefs[0]) && is_array($paramDefs[0]) && isset($paramDefs[0]['name'])) {
            $normalized = [];
            foreach ($paramDefs as $item) {
                if (isset($item['name'])) {
                    $normalized[$item['name']] = $item;
                }
            }
            $paramDefs = $normalized;
        }

        // Build named map of resolved values from params, context, and defaults
        $namedValues = [];
        foreach ($paramDefs as $name => $def) {
            if (! is_array($def)) {
                continue; // Skip scalar values (malformed entries)
            }
            // Also check snake_case version for context lookup (treeId -> tree_id)
            $snakeName = ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $name)), '_');
            $pluralSnakeName = str_ends_with($snakeName, '_id') ? $snakeName.'s' : null;
            $contextAlias = match ($snakeName) {
                'from_agent' => 'agent_id',
                default => null,
            };

            if (isset($params[$name])) {
                $namedValues[$name] = $this->castParam($params[$name], $def['type'] ?? 'string');
            } elseif (isset($params[$snakeName])) {
                $namedValues[$name] = $this->castParam($params[$snakeName], $def['type'] ?? 'string');
            } elseif ($pluralSnakeName !== null && isset($params[$pluralSnakeName])) {
                $namedValues[$name] = $this->coercePluralAliasValue($params[$pluralSnakeName], $def['type'] ?? 'string');
            } elseif (isset($context[$name])) {
                $namedValues[$name] = $this->castParam($context[$name], $def['type'] ?? 'string');
            } elseif (isset($context[$snakeName])) {
                $namedValues[$name] = $this->castParam($context[$snakeName], $def['type'] ?? 'string');
            } elseif ($contextAlias !== null && isset($context[$contextAlias])) {
                $namedValues[$name] = $this->castParam($context[$contextAlias], $def['type'] ?? 'string');
            } elseif (! ($def['required'] ?? false)) {
                $namedValues[$name] = $def['default'] ?? null;
            } else {
                throw new Exception("Missing required parameter: {$name}");
            }
        }

        // Order by method signature using reflection (handles JSON key order != method param order)
        try {
            $serviceClass = $toolDef['service'];
            $method = $toolDef['method'];
            $refMethod = new \ReflectionMethod($serviceClass, $method);
            $methodParams = $refMethod->getParameters();

            // Special case: method takes a single array $params — pass all named values as that array
            if (count($methodParams) === 1) {
                $singleParam = $methodParams[0];
                $type = $singleParam->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === 'array'
                    && ! array_key_exists($singleParam->getName(), $namedValues)) {
                    return [$namedValues ?: $params];
                }
            }

            $resolved = [];
            foreach ($methodParams as $refParam) {
                $paramName = $refParam->getName();
                // Convert camelCase to snake_case for matching
                $snakeName = ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $paramName)), '_');
                $matchedValue = null;
                $hasMatch = false;

                if (array_key_exists($snakeName, $namedValues)) {
                    $matchedValue = $namedValues[$snakeName];
                    $hasMatch = true;
                } elseif (array_key_exists($paramName, $namedValues)) {
                    $matchedValue = $namedValues[$paramName];
                    $hasMatch = true;
                }

                // Use matched value if non-null, or fall back to method default for null values
                // on non-nullable params (prevents TypeError when passing null to typed params)
                if ($hasMatch && $matchedValue !== null) {
                    $resolved[] = $matchedValue;
                } elseif ($hasMatch && $matchedValue === null && $refParam->allowsNull()) {
                    $resolved[] = null;
                } elseif ($refParam->isDefaultValueAvailable()) {
                    $resolved[] = $refParam->getDefaultValue();
                } elseif ($hasMatch) {
                    $resolved[] = $matchedValue; // pass null, let PHP type system handle
                } else {
                    $resolved[] = null;
                }
            }

            return $resolved;
        } catch (\ReflectionException $e) {
            Log::debug('AgentToolRegistryService: reflection failed for parameter ordering, using fallback', ['error' => $e->getMessage()]);

            return array_values($namedValues);
        }
    }

    /**
     * Cast a parameter to its declared type
     */
    private function castParam(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer', 'int' => (int) $value,
            'float', 'double' => (float) $value,
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => is_array($value) ? $value : json_decode($value, true) ?? [$value],
            'string' => (string) $value,
            default => $value,
        };
    }

    private function coercePluralAliasValue(mixed $value, string $type): mixed
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return $this->castParam($value, $type);
    }

    /**
     * Serialize a tool result to a readable string for the LLM
     */
    private function serializeResult(mixed $result, int $maxLength = 8000): string
    {
        if (is_string($result)) {
            return substr($result, 0, $maxLength);
        }

        if (is_bool($result)) {
            return $result ? 'true' : 'false';
        }

        if (is_numeric($result)) {
            return (string) $result;
        }

        if (is_object($result)) {
            // Handle RAGDocument and similar objects
            if (method_exists($result, 'toArray')) {
                $result = $result->toArray();
            } else {
                $result = (array) $result;
            }
        }

        if (is_array($result)) {
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (strlen($json) > $maxLength) {
                // Shrink the results payload until the encoded JSON fits under the
                // cap WITHOUT truncating mid-string — prior implementation did a
                // final substr() that produced invalid JSON and broke every
                // downstream consumer that json_decode'd the result (notably the
                // queue-mode source proposal extractor in AgentLoopService).
                $reserve = 96; // room for the trailing note

                // Strategy 1: if the top level is an array of rows (numeric keys), slice it.
                // Strategy 2: if the top level is an assoc with a 'results' list, slice that.
                // Strategy 3: generic assoc — iteratively shrink the largest array-valued
                //             field by 25% until it fits; if that's insufficient, emit a
                //             safe descriptive stub. Never return invalid JSON.
                $shrunk = $result;
                $isList = array_is_list($result);
                if ($isList) {
                    $rows = $result;
                    $shrunkRowsRef = &$shrunk;
                    $countLabel = 'entries';

                    $totalRows = count($rows);
                    $take = $totalRows;
                    $json = json_encode($shrunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    while (strlen($json) > ($maxLength - $reserve) && $take > 1) {
                        $take = max(1, (int) floor($take * 0.75));
                        $shrunkRowsRef = array_slice($rows, 0, $take);
                        $json = json_encode($shrunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    unset($shrunkRowsRef);

                    $json .= "\n... ({$totalRows} total {$countLabel}, showing first {$take})";
                } elseif (is_array($result['results'] ?? null)) {
                    $rows = $result['results'];
                    $shrunkRowsRef = &$shrunk['results'];
                    $countLabel = 'results in payload';

                    $totalRows = count($rows);
                    $take = $totalRows;
                    $json = json_encode($shrunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    while (strlen($json) > ($maxLength - $reserve) && $take > 1) {
                        $take = max(1, (int) floor($take * 0.75));
                        $shrunkRowsRef = array_slice($rows, 0, $take);
                        $json = json_encode($shrunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    unset($shrunkRowsRef);

                    $json .= "\n... ({$totalRows} total {$countLabel}, showing first {$take})";
                } else {
                    // Generic assoc: find array-valued fields, sort largest first by
                    // encoded size, and iteratively trim them 25% at a time.
                    $originalSize = strlen($json);
                    $arrayFields = [];
                    foreach ($result as $key => $value) {
                        if (is_array($value) && count($value) > 0) {
                            $encodedSize = strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE));
                            $arrayFields[] = [
                                'key' => $key,
                                'size' => $encodedSize,
                                'original_count' => count($value),
                                'current_count' => count($value),
                                'is_list' => array_is_list($value),
                            ];
                        }
                    }

                    if (empty($arrayFields)) {
                        // No array fields to trim — emit safe stub.
                        return (string) json_encode([
                            '_note' => "payload too large to serialize ({$maxLength} char cap)",
                            'keys' => array_keys($result),
                            'original_size' => $originalSize,
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    // Sort largest first.
                    usort($arrayFields, fn ($a, $b) => $b['size'] <=> $a['size']);

                    // Iteratively shrink the currently-largest array field by 25%.
                    $maxIterations = 200; // hard guard
                    $iter = 0;
                    $json = json_encode($shrunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    while (strlen($json) > ($maxLength - $reserve) && $iter < $maxIterations) {
                        // Re-evaluate largest field each pass (sizes shift as we trim).
                        $largestIdx = null;
                        $largestSize = 0;
                        foreach ($arrayFields as $idx => $field) {
                            if ($field['current_count'] < 2) {
                                continue; // can't trim further meaningfully
                            }
                            $currentVal = $shrunk[$field['key']] ?? null;
                            if (! is_array($currentVal)) {
                                continue;
                            }
                            $currentSize = strlen((string) json_encode($currentVal, JSON_UNESCAPED_UNICODE));
                            if ($currentSize > $largestSize) {
                                $largestSize = $currentSize;
                                $largestIdx = $idx;
                            }
                        }

                        if ($largestIdx === null) {
                            break; // nothing left to trim
                        }

                        $field = &$arrayFields[$largestIdx];
                        $newCount = max(1, (int) floor($field['current_count'] * 0.75));
                        if ($newCount >= $field['current_count']) {
                            $newCount = $field['current_count'] - 1;
                        }
                        if ($newCount < 1) {
                            $newCount = 1;
                        }
                        $field['current_count'] = $newCount;

                        $original = $result[$field['key']];
                        if ($field['is_list']) {
                            $shrunk[$field['key']] = array_slice($original, 0, $newCount);
                        } else {
                            $shrunk[$field['key']] = array_slice($original, 0, $newCount, true);
                        }
                        unset($field);

                        $json = json_encode($shrunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        $iter++;
                    }

                    if (strlen($json) > ($maxLength - $reserve)) {
                        // Shrinking didn't bring it under the cap — emit safe stub.
                        return (string) json_encode([
                            '_note' => "payload too large to serialize ({$maxLength} char cap)",
                            'keys' => array_keys($result),
                            'original_size' => $originalSize,
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    // Build a summary of what we trimmed.
                    $trimmed = [];
                    foreach ($arrayFields as $field) {
                        if ($field['current_count'] < $field['original_count']) {
                            $trimmed[] = "{$field['key']}: {$field['current_count']}/{$field['original_count']}";
                        }
                    }
                    $suffix = empty($trimmed)
                        ? "\n... (payload shrunk to fit {$maxLength} char cap)"
                        : "\n... (trimmed to fit {$maxLength} char cap; ".implode(', ', $trimmed).')';
                    $json .= $suffix;
                }
            }

            return $json;
        }

        return (string) $result;
    }

    /**
     * Parse tool call(s) from LLM response text
     *
     * Forgiving parser that handles various LLM output formats:
     * - {"tool": "name", "params": {...}}
     * - ```json\n{...}\n```
     * - Mixed text + JSON
     *
     * @return array ['has_tool_call' => bool, 'tool' => ?string, 'params' => array, 'text' => string]
     */
    public function parseToolCall(string $response): array
    {
        $text = $response;

        // Try to find JSON tool call in the response
        // Pattern 1: ```json ... ``` blocks
        if (preg_match('/```(?:json)?\s*(\{[^`]*"tool"\s*:\s*"[^"]+?"[^`]*\})\s*```/s', $response, $m)) {
            $json = json_decode(trim($m[1]), true);
            if ($json && isset($json['tool'])) {
                $text = trim(str_replace($m[0], '', $response));

                return [
                    'has_tool_call' => true,
                    'tool' => $json['tool'],
                    'params' => $json['params'] ?? [],
                    'text' => $text,
                ];
            }
        }

        // Pattern 2: Bare JSON object with "tool" key
        if (preg_match('/(\{[^{}]*"tool"\s*:\s*"[^"]+?"[^{}]*(?:\{[^{}]*\}[^{}]*)?\})/s', $response, $m)) {
            $json = json_decode(trim($m[1]), true);
            if ($json && isset($json['tool'])) {
                $text = trim(str_replace($m[1], '', $response));

                return [
                    'has_tool_call' => true,
                    'tool' => $json['tool'],
                    'params' => $json['params'] ?? [],
                    'text' => $text,
                ];
            }
        }

        // No tool call found
        return [
            'has_tool_call' => false,
            'tool' => null,
            'params' => [],
            'text' => $response,
        ];
    }

    /**
     * List all registered tool names
     */
    public function listTools(): array
    {
        return array_keys($this->getToolConfig());
    }

    /**
     * List only registry tools legal under the supplied offline/hybrid profile.
     * This is a catalog filter, not an execution gate; executeTool still
     * re-checks policy at call time.
     */
    public function listToolsForProfile(string $profile): array
    {
        $policy = app(OfflinePolicyService::class);
        $allowed = [];

        foreach ($this->getToolConfig() as $name => $config) {
            $context = [
                'risk_level' => $config['risk_level'] ?? null,
                'mcp_server' => $config['mcp_server'] ?? null,
                'mcp_tool' => $config['mcp_tool'] ?? null,
                '_audit' => false,
            ];

            try {
                if (! empty($context['mcp_server']) && ! empty($context['mcp_tool'])) {
                    $decision = $policy->evaluateMcpTool(
                        (string) $context['mcp_server'],
                        (string) $context['mcp_tool'],
                        $context,
                        $profile
                    );
                } else {
                    $decision = $policy->evaluateOperation('tool:'.$name, $context, $profile);
                }
            } catch (\Throwable $e) {
                Log::debug('AgentToolRegistryService: profile catalog evaluation failed', [
                    'tool' => $name,
                    'profile' => $profile,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($decision->allowed) {
                $allowed[] = $name;
            }
        }

        return $allowed;
    }

    /**
     * Record a tool call to mcp_tool_calls for analytics
     */
    private function recordToolCall(
        string $toolName,
        ?string $mcpServer,
        ?string $mcpTool,
        array $context,
        string $caller,
        bool $success,
        float $startTime,
        ?string $errorMessage = null,
        ?int $resultSize = null,
        ?array $params = null,
        ?string $riskLevel = null
    ): void {
        try {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $paramsSummary = null;
            if ($params) {
                // Store param keys and types only, never values (privacy)
                $summary = [];
                foreach ($params as $k => $v) {
                    $summary[] = $k.':'.gettype($v);
                }
                $paramsSummary = substr(implode(', ', $summary), 0, 500);
            }

            DB::insert('
                INSERT INTO mcp_tool_calls (tool_name, mcp_server, mcp_tool, agent_id, session_id, caller, success, duration_ms, error_message, params_summary, result_size, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ', [
                substr($toolName, 0, 150),
                $mcpServer ? substr($mcpServer, 0, 50) : null,
                $mcpTool ? substr($mcpTool, 0, 100) : null,
                isset($context['agent_id']) ? substr($context['agent_id'], 0, 100) : null,
                isset($context['session_id']) ? substr($context['session_id'], 0, 100) : null,
                $caller,
                $success ? 1 : 0,
                $durationMs,
                $errorMessage ? substr($errorMessage, 0, 65535) : null,
                $paramsSummary,
                $resultSize,
            ]);
            // INF-3: Structured audit log
            app(AgentAuditService::class)->recordToolCall(
                sessionId: $context['session_id'] ?? '',
                agentName: $context['agent_id'] ?? $caller,
                toolName: $toolName,
                riskLevel: $riskLevel ?? 'read',
                durationMs: (float) $durationMs,
                outcome: $success ? 'success' : 'failure',
                error: $errorMessage,
            );
        } catch (\Throwable $e) {
            // Analytics recording must never break tool execution
            Log::debug('AgentToolRegistry: Failed to record tool call analytics', ['error' => $e->getMessage()]);
        }
    }

    /**
     * AG-19: Simulate a tool call — validate params, check guardrails, report what would happen.
     *
     * Does NOT execute the tool. Returns validation results and predicted outcome.
     * Useful for dry-run agent testing and pre-execution safety checks.
     */
    private function simulateToolCall(string $toolName, array $toolDef, array $params, array $context, ?string $agentId): array
    {
        $results = [
            'success' => true,
            'simulated' => true,
            'tool' => $toolName,
            'risk_level' => $toolDef['risk_level'] ?? 'read',
            'checks' => [],
        ];

        // Check: blocked
        if (($toolDef['risk_level'] ?? 'read') === 'blocked') {
            $results['checks'][] = ['check' => 'risk_level', 'passed' => false, 'detail' => 'Tool is blocked by security policy'];
            $results['success'] = false;
            $results['result_text'] = "SIMULATION: Tool '{$toolName}' would be BLOCKED (security policy).";

            return $results;
        }
        $results['checks'][] = ['check' => 'risk_level', 'passed' => true, 'detail' => $toolDef['risk_level'] ?? 'read'];

        // Check: guardrails
        // Fix C: mirror the live executeTool() path so simulation sees the
        // same policy verdict. Pass risk_level + mcp refs in context.
        $context['risk_level'] = $context['risk_level'] ?? ($toolDef['risk_level'] ?? 'read');
        if (! empty($toolDef['mcp_server'])) {
            $context['mcp_server'] = $toolDef['mcp_server'];
        }
        if (! empty($toolDef['mcp_tool'])) {
            $context['mcp_tool'] = $toolDef['mcp_tool'];
        }
        try {
            $guardrailResult = $this->getGuardrailService()->validate("tool:{$toolName}", $context, $agentId);
            $results['checks'][] = [
                'check' => 'guardrail',
                'passed' => $guardrailResult['allowed'],
                'detail' => $guardrailResult['reason'] ?? 'Allowed',
                'requires_confirmation' => $guardrailResult['requires_confirmation'] ?? false,
            ];
            if (! $guardrailResult['allowed']) {
                $results['success'] = false;
            }
        } catch (\Throwable $e) {
            $results['checks'][] = ['check' => 'guardrail', 'passed' => false, 'detail' => 'Guardrail unavailable: '.$e->getMessage()];
            $results['success'] = false;
        }

        // Check: required params
        $requiredParams = $toolDef['required_params'] ?? [];
        $missingParams = [];
        foreach ($requiredParams as $param) {
            if (! isset($params[$param]) && ! isset($context[$param])) {
                $missingParams[] = $param;
            }
        }
        $results['checks'][] = [
            'check' => 'params',
            'passed' => empty($missingParams),
            'detail' => empty($missingParams) ? 'All required params present' : 'Missing: '.implode(', ', $missingParams),
        ];

        // Check: MCP/service routing
        $mcpServer = $toolDef['mcp_server'] ?? null;
        $service = $toolDef['service'] ?? null;
        $method = $toolDef['method'] ?? null;

        if ($mcpServer) {
            $results['checks'][] = ['check' => 'routing', 'passed' => true, 'detail' => "MCP: {$mcpServer}"];
        } elseif ($service && $method) {
            $classExists = class_exists($service);
            $methodExists = $classExists && method_exists($service, $method);
            $results['checks'][] = [
                'check' => 'routing',
                'passed' => $methodExists,
                'detail' => $methodExists ? "Service: {$service}::{$method}" : "Missing: {$service}::{$method}",
            ];
            if (! $methodExists) {
                $results['success'] = false;
            }
        }

        $passedCount = count(array_filter($results['checks'], fn ($c) => $c['passed']));
        $totalChecks = count($results['checks']);

        $results['result_text'] = "SIMULATION: Tool '{$toolName}' — {$passedCount}/{$totalChecks} checks passed. "
            .($results['success'] ? 'Would execute successfully.' : 'Would be blocked.');

        return $results;
    }
}

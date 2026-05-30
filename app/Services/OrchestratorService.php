<?php

namespace App\Services;

use App\Jobs\ExecuteWorkflow;
use App\Services\AIService;
use App\Engine\MCPRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent Orchestrator Service
 *
 * Routes user requests to appropriate handlers (workflows, RAG, MCP, chat)
 * with context-aware tool selection and multi-step task orchestration.
 */
class OrchestratorService
{
    private AIService $aiService;
    private MCPRouter $mcpRouter;
    private RAGService $ragService;
    private EmailClassificationService $emailService;

    public function __construct(
        AIService $aiService,
        MCPRouter $mcpRouter,
        RAGService $ragService,
        EmailClassificationService $emailService
    ) {
        $this->aiService = $aiService;
        $this->mcpRouter = $mcpRouter;
        $this->ragService = $ragService;
        $this->emailService = $emailService;
    }

    private function normalizePrivacyOptions(array $options, ?int $conversationId): array
    {
        if (array_key_exists('sensitive_data', $options)) {
            return $options;
        }

        if ($conversationId !== null) {
            $options['sensitive_data'] = true;
            $options['data_class'] = $options['data_class'] ?? 'chat_orchestration';
            $options['sensitive_data_reason'] = $options['sensitive_data_reason'] ?? 'conversation_context';
        }

        return $options;
    }

    /**
     * Process a user request with intelligent routing
     *
     * @param string $request User's natural language request
     * @param int|null $conversationId Optional conversation ID for context
     * @param array $options Additional options (model, temperature, etc.)
     * @return array Response with result, intent, and metadata
     */
    public function process(string $request, ?int $conversationId = null, array $options = []): array
    {
        $startTime = microtime(true);
        $options = $this->normalizePrivacyOptions($options, $conversationId);

        try {
            // 1. Load conversation context if provided
            $context = $this->loadContext($conversationId);

            // 2. Classify intent using AI
            $intent = $this->classifyIntent($request, $context, $options);

            // 3. Route to appropriate handler based on intent
            $result = $this->routeRequest($intent, $request, $context, $options);

            // 4. Save to conversation if provided
            if ($conversationId) {
                $this->saveToConversation($conversationId, $request, $result);
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'intent' => $intent,
                'result' => $result,
                'metadata' => [
                    'duration_ms' => $duration,
                    'conversation_id' => $conversationId,
                    'timestamp' => now()->toIso8601String(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Orchestrator error', [
                'request' => $request,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'intent' => 'error',
                'result' => null,
            ];
        }
    }

    /**
     * Classify user intent using AI
     *
     * @param string $request User request
     * @param array $context Conversation context
     * @return array Intent classification with confidence and parameters
     */
    private function classifyIntent(string $request, array $context, array $options): array
    {
        $systemPrompt = <<<PROMPT
You are an intent classifier for an AI automation framework. Analyze the user's request and classify it into one of these intents:

1. **workflow_execution** - User wants to run a workflow
   - Examples: "run morning weather", "execute news brief", "trigger joplin sync"
   - Extract: workflow_name

2. **rag_search** - User wants to search historical data
   - Examples: "find executions from last week", "search for weather data", "what workflows ran today"
   - Extract: query, filters

3. **mcp_tool** - User wants to call a specific MCP tool
   - Examples: "search my emails for X", "list my calendar events", "get trending news topics"
   - Extract: tool_name, tool_params

4. **email_classify** - User wants to classify or analyze emails
   - Examples: "classify my recent emails", "categorize inbox messages", "analyze email from X"
   - Extract: query, folder, limit

5. **email_reply** - User wants to generate email reply drafts
   - Examples: "draft a reply to X", "generate response to email about Y", "create reply draft"
   - Extract: message_id, tone, template_id

6. **email_search** - User wants to search emails with classification context
   - Examples: "find work emails from last week", "show urgent emails", "search personal messages"
   - Extract: query, category, priority

7. **multi_step** - Complex request requiring multiple operations
   - Examples: "search my notes for X and create a summary", "find similar documents and email them"
   - Extract: steps (array of sub-intents)

8. **general_conversation** - General question or conversation
   - Examples: "what can you do?", "hello", "explain how workflows work"

Respond ONLY with a JSON object in this format:
{
  "intent": "workflow_execution|rag_search|mcp_tool|email_classify|email_reply|email_search|multi_step|general_conversation",
  "confidence": 0.0-1.0,
  "parameters": {
    // Intent-specific parameters extracted from the request
  },
  "reasoning": "Brief explanation of classification"
}
PROMPT;

        $userPrompt = <<<PROMPT
Request: {$request}

Context: {$this->formatContext($context)}

Classify this request:
PROMPT;

        try {
            $result = $this->aiService->process($userPrompt, [
                'system_prompt' => $systemPrompt,
                'temperature' => 0.1,
                'max_tokens' => 500,
            ] + $options);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'AI classification failed');
            }

            // Parse JSON response
            $intent = $this->parseIntentResponse($result['response']);

            return $intent;
        } catch (\Exception $e) {
            Log::warning('Intent classification failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback: simple keyword matching
            return $this->fallbackIntentClassification($request);
        }
    }

    /**
     * Parse AI response into structured intent
     */
    private function parseIntentResponse(string $response): array
    {
        // Extract JSON from response (might have markdown code blocks)
        $pattern = '/\{[\s\S]*?\}/';
        if (preg_match($pattern, $response, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json && isset($json['intent'])) {
                return $json;
            }
        }

        throw new \Exception('Could not parse intent response');
    }

    /**
     * Fallback intent classification using keyword matching
     */
    private function fallbackIntentClassification(string $request): array
    {
        $lower = strtolower($request);

        // Workflow execution keywords
        if (preg_match('/\b(run|execute|trigger|start)\s+(\w+)/i', $request, $matches)) {
            return [
                'intent' => 'workflow_execution',
                'confidence' => 0.7,
                'parameters' => ['workflow_name' => $matches[2]],
                'reasoning' => 'Keyword match: ' . $matches[1],
            ];
        }

        // RAG search keywords
        if (preg_match('/\b(find|search|lookup|query|get|show)\b/i', $request)) {
            return [
                'intent' => 'rag_search',
                'confidence' => 0.6,
                'parameters' => ['query' => $request],
                'reasoning' => 'Search keywords detected',
            ];
        }

        // General conversation
        return [
            'intent' => 'general_conversation',
            'confidence' => 0.5,
            'parameters' => [],
            'reasoning' => 'Default fallback',
        ];
    }

    /**
     * Route request to appropriate handler
     */
    private function routeRequest(array $intent, string $request, array $context, array $options): array
    {
        switch ($intent['intent']) {
            case 'workflow_execution':
                return $this->handleWorkflowExecution($intent, $request, $context);

            case 'rag_search':
                return $this->handleRAGSearch($intent, $request, $context);

            case 'mcp_tool':
                return $this->handleMCPTool($intent, $request, $context);

            case 'email_classify':
                return $this->handleEmailClassification($intent, $request, $context);

            case 'email_reply':
                return $this->handleEmailReply($intent, $request, $context);

            case 'email_search':
                return $this->handleEmailSearch($intent, $request, $context);

            case 'multi_step':
                return $this->handleMultiStep($intent, $request, $context, $options);

            case 'general_conversation':
            default:
                return $this->handleConversation($intent, $request, $context, $options);
        }
    }

    /**
     * Handle workflow execution request
     */
    private function handleWorkflowExecution(array $intent, string $request, array $context): array
    {
        $workflowName = $intent['parameters']['workflow_name'] ?? null;

        if (!$workflowName) {
            // Use AI to extract workflow name using raw SQL
            $sql = "SELECT name FROM workflows WHERE active = ?";
            $workflowResults = DB::select($sql, [true]);
            $workflows = array_column($workflowResults, 'name');

            $workflowName = $this->extractWorkflowName($request, $workflows);
        }

        if (!$workflowName) {
            return [
                'type' => 'error',
                'message' => 'Could not determine which workflow to execute. Available workflows: ' . implode(', ', $workflows ?? []),
            ];
        }

        try {
            $workflow = DB::selectOne(
                "SELECT id, name, active FROM workflows WHERE name = ? LIMIT 1",
                [$workflowName]
            );

            if (!$workflow) {
                return [
                    'type' => 'error',
                    'message' => "Workflow not found: {$workflowName}",
                ];
            }

            if (!(bool) $workflow->active) {
                return [
                    'type' => 'error',
                    'message' => "Workflow is not active: {$workflowName}",
                ];
            }

            ExecuteWorkflow::dispatch($workflow->name, $workflow->id, []);

            return [
                'type' => 'workflow_execution',
                'workflow' => $workflow->name,
                'workflow_id' => $workflow->id,
                'run_id' => null,
                'execution_id' => null,
                'status' => 'queued',
                'message' => "Workflow '{$workflow->name}' queued for execution",
                'data' => [
                    'workflow_id' => $workflow->id,
                    'workflow_name' => $workflow->name,
                    'status' => 'queued',
                ],
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => "Failed to execute workflow '{$workflowName}': " . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle RAG search request
     */
    private function handleRAGSearch(array $intent, string $request, array $context): array
    {
        $query = $intent['parameters']['query'] ?? $request;
        $limit = $intent['parameters']['limit'] ?? 5;
        $documentType = $intent['parameters']['document_type'] ?? null;

        try {
            $results = $this->ragService->search($query, $limit, $documentType);

            $resultCount = count($results);
            return [
                'type' => 'rag_search',
                'query' => $query,
                'results_count' => $resultCount,
                'results' => array_map(function ($result) {
                    return [
                        'title' => $result['document']->title,
                        'content_preview' => substr($result['document']->content, 0, 200) . '...',
                        'similarity' => round($result['similarity'], 4),
                        'document_type' => $result['document']->document_type,
                        'id' => $result['document']->id,
                    ];
                }, $results),
                'message' => "Found {$resultCount} results for query: {$query}",
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Search failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle MCP tool call request
     */
    private function handleMCPTool(array $intent, string $request, array $context): array
    {
        $toolName = $intent['parameters']['tool_name'] ?? null;
        $params = $intent['parameters']['tool_params'] ?? [];

        if (!$toolName) {
            return [
                'type' => 'error',
                'message' => 'Could not determine which MCP tool to call',
            ];
        }

        try {
            // Determine server from tool name
            $tools = $this->mcpRouter->getAvailableTools();
            $tool = collect($tools)->firstWhere('name', $toolName);

            if (!$tool) {
                return [
                    'type' => 'error',
                    'message' => "MCP tool '{$toolName}' not found",
                ];
            }

            $result = $this->mcpRouter->callTool($tool['server'], $toolName, $params);

            return [
                'type' => 'mcp_tool',
                'tool' => $toolName,
                'server' => $tool['server'],
                'result' => $result,
                'message' => "MCP tool '{$toolName}' executed successfully",
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => "MCP tool call failed: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle multi-step task
     */
    private function handleMultiStep(array $intent, string $request, array $context, array $options): array
    {
        $steps = $intent['parameters']['steps'] ?? [];

        if (empty($steps)) {
            // Use AI to break down the request into steps
            $steps = $this->planMultiStepTask($request, $context, $options);
        }

        $results = [];
        foreach ($steps as $step) {
            $stepResult = $this->routeRequest($step, $step['request'] ?? $request, $context, $options);
            $results[] = $stepResult;

            // Add step result to context for next steps
            $context['previous_steps'][] = $stepResult;
        }

        return [
            'type' => 'multi_step',
            'steps_count' => count($steps),
            'steps' => $results,
            'message' => "Multi-step task completed with {count($steps)} steps",
        ];
    }

    /**
     * Handle general conversation
     */
    private function handleConversation(array $intent, string $request, array $context, array $options): array
    {
        // Build context-aware system prompt
        $systemPrompt = $this->buildSystemPrompt($context);

        try {
            $result = $this->aiService->process($request, [
                'system_prompt' => $systemPrompt,
                'temperature' => $options['temperature'] ?? 0.7,
                'max_tokens' => $options['max_tokens'] ?? 2000,
            ] + $options);

            if (!$result['success']) {
                return [
                    'type' => 'error',
                    'message' => 'Conversation failed: ' . ($result['error'] ?? 'Unknown error'),
                ];
            }

            return [
                'type' => 'conversation',
                'message' => $result['response'],
                'provider' => $result['provider'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Conversation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Load conversation context
     */
    private function loadContext(?int $conversationId): array
    {
        if (!$conversationId) {
            return [];
        }

        $sql = "SELECT * FROM conversations WHERE id = ? LIMIT 1";
        $conversations = DB::select($sql, [$conversationId]);
        $conversation = $conversations[0] ?? null;

        $sql = "SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT " . config('agents.chat_context_messages', 10);
        $messages = DB::select($sql, [$conversationId]);

        // Reverse to get chronological order
        $messagesReversed = array_reverse($messages);

        return [
            'conversation_id' => $conversationId,
            'conversation' => $conversation,
            'messages' => $messagesReversed,
        ];
    }

    /**
     * Format context for AI prompt
     */
    private function formatContext(array $context): string
    {
        if (empty($context['messages'])) {
            return 'No previous conversation context.';
        }

        $formatted = "Previous conversation:\n";
        foreach ($context['messages'] as $msg) {
            $role = $msg->role ?? 'unknown';
            $content = substr($msg->content ?? '', 0, 200);
            $formatted .= "- {$role}: {$content}\n";
        }

        return $formatted;
    }

    /**
     * Build system prompt with framework capabilities
     */
    private function buildSystemPrompt(array $context): string
    {
        $sql = "SELECT name FROM workflows WHERE active = ?";
        $workflowResults = DB::select($sql, [true]);
        $workflowNames = array_column($workflowResults, 'name');
        $workflows = implode(', ', $workflowNames);

        $tools = $this->mcpRouter->getAvailableTools();
        $toolCount = count($tools);

        return <<<PROMPT
You are an assistant for the PLOS AI Automation Framework.

## COMMUNICATION STYLE (MANDATORY)
- Be CONCISE and DIRECT - answer the specific question asked first
- NO unnecessary recommendations, suggestions, or tangents unless explicitly requested
- Keep responses focused on what was asked - no fluff

## CAPABILITIES
- Workflows: {$workflows}
- RAG semantic search (historical data)
- MCP tools ({$toolCount} tools across 12 servers)
PROMPT;
    }

    /**
     * Save interaction to conversation
     */
    private function saveToConversation(int $conversationId, string $request, array $result): void
    {
        // Save user message using raw SQL
        $sql = "INSERT INTO chat_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)";
        DB::insert($sql, [$conversationId, 'user', $request, now()]);

        // Save assistant response
        $responseContent = $this->formatResultForConversation($result);

        $sql = "INSERT INTO chat_messages (conversation_id, role, content, metadata, created_at) VALUES (?, ?, ?, ?, ?)";
        DB::insert($sql, [
            $conversationId,
            'assistant',
            $responseContent,
            json_encode([
                'intent' => $result['type'] ?? 'unknown',
                'data' => $result,
            ]),
            now()
        ]);

        // Update conversation updated_at using raw SQL
        $sql = "UPDATE conversations SET updated_at = ? WHERE id = ?";
        DB::update($sql, [now(), $conversationId]);
    }

    /**
     * Format result for conversation display
     */
    private function formatResultForConversation(array $result): string
    {
        switch ($result['type'] ?? 'unknown') {
            case 'workflow_execution':
                return "✓ Executed workflow '{$result['workflow']}' (ID: {$result['execution_id']})";

            case 'rag_search':
                $count = $result['results_count'] ?? 0;
                return "Found {$count} results for your search.";

            case 'mcp_tool':
                return "✓ Executed MCP tool '{$result['tool']}'";

            case 'multi_step':
                return "✓ Completed multi-step task with {$result['steps_count']} steps";

            case 'conversation':
                return $result['message'] ?? '';

            case 'error':
                return "❌ Error: " . ($result['message'] ?? 'Unknown error');

            default:
                return json_encode($result, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Extract workflow name from request using AI
     */
    private function extractWorkflowName(string $request, array $workflows): ?string
    {
        // Simple fuzzy matching
        $lower = strtolower($request);
        foreach ($workflows as $workflow) {
            if (str_contains($lower, strtolower($workflow))) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * CT-3: Plan multi-step task using AI
     *
     * Decomposes a complex request into sequential sub-intents that
     * can be routed individually through handleMultiStep().
     */
    private function planMultiStepTask(string $request, array $context, array $options): array
    {
        $availableIntents = 'rag_search, workflow_execution, mcp_tool, email_classify, email_reply, email_search, general_conversation';
        $contextSummary = !empty($context['previous_steps'])
            ? 'Previous steps completed: ' . count($context['previous_steps'])
            : 'No previous context';

        $prompt = <<<PROMPT
Break down this complex request into 2-5 sequential steps.
Each step must map to one of these intent types: {$availableIntents}

Context: {$contextSummary}
Request: {$request}

Respond ONLY with a JSON array of steps:
[
  {
    "intent": "intent_type",
    "request": "specific sub-request for this step",
    "parameters": {},
    "depends_on_previous": false
  }
]

Rules:
- Each step should be self-contained enough to execute independently
- Set depends_on_previous=true if the step needs results from an earlier step
- Keep steps minimal — prefer 2-3 focused steps over 5 vague ones
- If the request is actually simple, return a single step
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'max_tokens' => 1000,
                'temperature' => 0.3,
                'skip_if_busy' => true,
            ] + $options);

            if (!$result['success']) {
                Log::warning('OrchestratorService: AI task planning failed', ['error' => $result['error']]);
                return [];
            }

            $response = $result['response'] ?? '';
            // Extract JSON from response (may be wrapped in markdown)
            if (preg_match('/\[[\s\S]*\]/m', $response, $m)) {
                $steps = json_decode($m[0], true);
                if (is_array($steps) && !empty($steps)) {
                    Log::info('OrchestratorService: AI planned multi-step task', [
                        'request' => mb_substr($request, 0, 100),
                        'steps' => count($steps),
                    ]);
                    return $steps;
                }
            }

            Log::warning('OrchestratorService: Could not parse AI planning response');
            return [];
        } catch (\Exception $e) {
            Log::warning('OrchestratorService: Task planning exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Handle email classification request
     */
    private function handleEmailClassification(array $intent, string $request, array $context): array
    {
        $query = $intent['parameters']['query'] ?? '';
        $folder = $intent['parameters']['folder'] ?? 'INBOX';
        $limit = $intent['parameters']['limit'] ?? 10;

        try {
            // Search emails using MCP
            $searchResult = $this->mcpRouter->callTool('thunderbird', 'searchMessages', [
                'query' => $query,
                'folder' => $folder,
            ]);

            if (!($searchResult['success'] ?? false)) {
                return [
                    'type' => 'error',
                    'message' => 'Email search failed via Thunderbird MCP',
                ];
            }

            $emails = $searchResult['result'] ?? [];
            $classified = [];

            // Classify each email
            foreach (array_slice($emails, 0, $limit) as $email) {
                $result = $this->emailService->classifyEmail($email);
                if ($result['success'] ?? false) {
                    $classified[] = [
                        'email' => [
                            'from' => $email['from'] ?? 'unknown',
                            'subject' => $email['subject'] ?? '',
                            'date' => $email['date'] ?? null,
                        ],
                        'classification' => $result['classification'],
                    ];
                }
            }

            return [
                'type' => 'email_classify',
                'total_classified' => count($classified),
                'results' => $classified,
                'message' => "Classified {count($classified)} emails successfully",
            ];

        } catch (\Exception $e) {
            Log::error('Email classification failed', ['error' => $e->getMessage()]);
            return [
                'type' => 'error',
                'message' => 'Email classification failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle email reply generation request
     */
    private function handleEmailReply(array $intent, string $request, array $context): array
    {
        $messageId = $intent['parameters']['message_id'] ?? null;
        $tone = $intent['parameters']['tone'] ?? 'professional';
        $templateId = $intent['parameters']['template_id'] ?? null;

        if (!$messageId) {
            return [
                'type' => 'error',
                'message' => 'Email message_id is required for reply generation',
            ];
        }

        try {
            // Get the original email
            $searchResult = $this->mcpRouter->callTool('thunderbird', 'searchMessages', [
                'query' => '', // Will need to implement message_id lookup
                'folder' => 'INBOX',
            ]);

            $emails = $searchResult['result'] ?? [];
            $email = collect($emails)->firstWhere('message_id', $messageId);

            if (!$email) {
                return [
                    'type' => 'error',
                    'message' => "Email with message_id '{$messageId}' not found",
                ];
            }

            // Generate reply draft
            $draftId = $this->emailService->generateReplyDraft($messageId, $email, [
                'tone' => $tone,
                'template_id' => $templateId,
            ]);

            // Get draft details
            $draft = DB::selectOne('SELECT * FROM email_reply_drafts WHERE id = ?', [$draftId]);

            return [
                'type' => 'email_reply',
                'draft_id' => $draftId,
                'draft' => [
                    'to' => $draft->to,
                    'subject' => $draft->subject,
                    'body' => $draft->body,
                    'confidence' => $draft->ai_confidence,
                    'status' => $draft->status,
                ],
                'message' => "Generated reply draft for email from {$draft->to}",
            ];

        } catch (\Exception $e) {
            Log::error('Email reply generation failed', ['error' => $e->getMessage()]);
            return [
                'type' => 'error',
                'message' => 'Reply generation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle email search with classification filters
     */
    private function handleEmailSearch(array $intent, string $request, array $context): array
    {
        $query = $intent['parameters']['query'] ?? '';
        $category = $intent['parameters']['category'] ?? null;
        $priority = $intent['parameters']['priority'] ?? null;

        try {
            // Build SQL query with filters
            $sql = 'SELECT * FROM email_classifications WHERE 1=1';
            $params = [];

            if ($category) {
                $sql .= ' AND category = ?';
                $params[] = $category;
            }

            if ($priority) {
                $sql .= ' AND priority = ?';
                $params[] = $priority;
            }

            if ($query) {
                $sql .= ' AND (JSON_EXTRACT(metadata, "$.subject") LIKE ? OR JSON_EXTRACT(metadata, "$.from") LIKE ?)';
                $params[] = "%{$query}%";
                $params[] = "%{$query}%";
            }

            $sql .= ' ORDER BY classified_at DESC LIMIT 20';

            $results = DB::select($sql, $params);

            $formattedResults = collect($results)->map(function ($result) {
                $metadata = json_decode($result->metadata, true);
                return [
                    'message_id' => $result->message_id,
                    'from' => $metadata['from'] ?? 'unknown',
                    'subject' => $metadata['subject'] ?? '',
                    'category' => $result->category,
                    'priority' => $result->priority,
                    'tags' => json_decode($result->tags ?? '[]', true),
                    'summary' => $result->summary,
                    'confidence' => $result->confidence,
                    'date' => $result->classified_at,
                ];
            })->toArray();

            return [
                'type' => 'email_search',
                'results_count' => count($formattedResults),
                'results' => $formattedResults,
                'filters' => array_filter([
                    'category' => $category,
                    'priority' => $priority,
                    'query' => $query,
                ]),
                'message' => "Found {count($formattedResults)} classified emails matching your criteria",
            ];

        } catch (\Exception $e) {
            Log::error('Email search failed', ['error' => $e->getMessage()]);
            return [
                'type' => 'error',
                'message' => 'Email search failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get orchestrator status and statistics
     */
    public function getStatus(): array
    {
        $conversationsCount = 0;
        $messagesCount = 0;
        $workflowCount = 0;
        $mcpToolCount = 0;
        $ragDocCount = 0;

        try {
            $sql = "SELECT COUNT(*) as count FROM conversations WHERE deleted_at IS NULL";
            $conversationsCount = DB::select($sql)[0]->count ?? 0;
        } catch (\Exception $e) {
            Log::warning('Failed to count conversations', ['error' => $e->getMessage()]);
        }

        try {
            $sql = "SELECT COUNT(*) as count FROM chat_messages";
            $messagesCount = DB::select($sql)[0]->count ?? 0;
        } catch (\Exception $e) {
            Log::warning('Failed to count messages', ['error' => $e->getMessage()]);
        }

        try {
            $sql = "SELECT COUNT(*) as count FROM workflows WHERE active = ?";
            $workflowCount = DB::select($sql, [true])[0]->count ?? 0;
        } catch (\Exception $e) {
            Log::warning('Failed to count workflows', ['error' => $e->getMessage()]);
        }

        try {
            $mcpToolCount = count($this->mcpRouter->getAvailableTools());
        } catch (\Exception $e) {
            Log::warning('Failed to count MCP tools', ['error' => $e->getMessage()]);
        }

        try {
            $sql = "SELECT COUNT(*) as count FROM rag_documents";
            $ragDocCount = DB::connection('pgsql_rag')->select($sql)[0]->count ?? 0;
        } catch (\Exception $e) {
            Log::warning('Failed to count RAG documents', ['error' => $e->getMessage()]);
        }

        return [
            'status' => 'operational',
            'available' => true,
            'conversations' => $conversationsCount,
            'total_messages' => $messagesCount,
            'available_intents' => [
                'workflow_execution',
                'rag_search',
                'mcp_tool',
                'multi_step',
                'general_conversation',
            ],
            'capabilities' => [
                'workflows' => $workflowCount,
                'mcp_tools' => $mcpToolCount,
                'rag_documents' => $ragDocCount,
            ],
            'services' => [
                'ai' => true,
                'workflows' => true,
                'rag' => true,
                'mcp' => true,
            ],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Engine\AIRouter;
use App\Engine\MCPRouter;
use App\Http\Controllers\Controller;
use App\Services\AIResponseSanitizer;
use App\Services\AIService;
use App\Services\OrchestratorService;
use App\Services\RAGService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    /**
     * List all conversations (excluding soft deleted)
     */
    public function index()
    {
        // Get conversations using raw SQL
        $sql = 'SELECT * FROM conversations WHERE deleted_at IS NULL ORDER BY created_at DESC';
        $conversations = DB::select($sql);

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Create a new conversation
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:100',
            'is_private' => 'nullable|boolean',
            'model_mode' => 'nullable|string|in:standard,fast,quality,uncensored',
        ]);

        $title = $validated['title'] ?? null;
        $model = $validated['model'] ?? $this->resolveDefaultChatModel();
        $isPrivate = $validated['is_private'] ?? false;
        $modelMode = $validated['model_mode'] ?? 'standard';

        // For uncensored mode, resolve the uncensored model from DB
        if ($modelMode === 'uncensored') {
            $uncensoredModel = $this->resolveUncensoredModel();
            if ($uncensoredModel) {
                $model = 'ollama:'.$uncensoredModel;
            }
            // Private by default for uncensored conversations
            $isPrivate = true;
        }

        $now = now();
        DB::insert(
            'INSERT INTO conversations (title, model, is_private, model_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$title, $model, $isPrivate, $modelMode, $now, $now]
        );
        $conversationId = DB::getPdo()->lastInsertId();

        $sql = 'SELECT * FROM conversations WHERE id = ? LIMIT 1';
        $conversation = DB::select($sql, [$conversationId])[0] ?? null;

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ], 201);
    }

    /**
     * Get a conversation with all its messages
     */
    public function show($id)
    {
        // Get conversation using raw SQL
        $sql = 'SELECT * FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1';
        $conversations = DB::select($sql, [$id]);
        $conversation = $conversations[0] ?? null;

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        // Get messages using raw SQL
        $sql = 'SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC';
        $messages = DB::select($sql, [$id]);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * Clear a conversation (soft delete)
     */
    public function destroy($id)
    {
        // Check if conversation is private — hard-delete messages for privacy
        $conversation = DB::selectOne(
            'SELECT is_private FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$id]
        );

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        if ($conversation->is_private) {
            DB::delete('DELETE FROM chat_messages WHERE conversation_id = ?', [$id]);
            DB::delete('DELETE FROM conversations WHERE id = ?', [$id]);
        } else {
            $now = now();
            DB::update(
                'UPDATE conversations SET deleted_at = ?, updated_at = ? WHERE id = ?',
                [$now, $now, $id]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation cleared',
        ]);
    }

    /**
     * Clear all messages in a conversation (keep conversation, delete messages)
     */
    public function clearMessages($id)
    {
        // Verify conversation exists
        $sql = 'SELECT * FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1';
        $conversations = DB::select($sql, [$id]);

        if (empty($conversations)) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        // Delete all messages in the conversation
        $deleted = DB::delete('DELETE FROM chat_messages WHERE conversation_id = ?', [$id]);

        // Update conversation timestamp
        DB::update(
            'UPDATE conversations SET updated_at = ? WHERE id = ?',
            [now(), $id]
        );

        return response()->json([
            'success' => true,
            'message' => "Cleared {$deleted} messages from conversation",
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Send a message and get AI response (non-streaming).
     * Private conversations skip message persistence.
     *
     * @param  Request  $request
     *                            - content: (required) The message content
     *                            - deep_search: (optional) Force deep RAG search with full document content
     *                            - multi_step: (optional) AI-8: Route through OrchestratorService for multi-step delegation
     */
    public function sendMessage(Request $request, $id)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'deep_search' => 'nullable|boolean',
            'multi_step' => 'nullable|boolean',
        ]);
        $forceDeepSearch = $validated['deep_search'] ?? false;
        $useMultiStep = $validated['multi_step'] ?? false;

        // Verify conversation exists using raw SQL
        $sql = 'SELECT * FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1';
        $conversations = DB::select($sql, [$id]);
        $conversation = $conversations[0] ?? null;

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        $isPrivate = $conversation->is_private ?? false;

        // Save user message (private conversations also persist for multi-turn;
        // messages are hard-deleted when conversation is deleted)
        $now = now();
        DB::insert(
            'INSERT INTO chat_messages (conversation_id, role, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$id, 'user', $validated['content'], $now, $now]
        );
        $userMessageId = DB::getPdo()->lastInsertId();

        // Load conversation history
        $messages = DB::select(
            'SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC', [$id]
        );

        // Build context for AI (convert to array format AIRouter expects)
        $context = [];
        foreach ($messages as $msg) {
            $context[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }

        // Get AI response through AIService with MCP tool support
        // E01 Phase 3: Now uses AIService for resilient processing
        try {
            $aiService = app(AIService::class);
            $mcpRouter = app(MCPRouter::class);
            $ragService = app(RAGService::class);

            // AI-8: Multi-step delegation — route through OrchestratorService
            // Ollama handles grunt work (summarize, extract, classify), quality model reasons
            if ($useMultiStep) {
                $orchestrator = app(\App\Services\OrchestratorService::class);
                $orchResult = $orchestrator->process(
                    $validated['content'],
                    $id,
                    ['temperature' => 0.7]
                );

                $responseText = $this->formatOrchestratorResult($orchResult);
                $provider = $orchResult['provider'] ?? 'orchestrator';

                // Save AI response
                DB::insert(
                    'INSERT INTO chat_messages (conversation_id, role, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, 'assistant', $responseText, now(), now()]
                );

                return response()->json([
                    'success' => true,
                    'data' => [
                        'content' => $responseText,
                        'provider' => $provider,
                        'multi_step' => true,
                        'type' => $orchResult['type'] ?? 'orchestrated',
                    ],
                ]);
            }

            // Check if RAG search might be helpful (supports forced deep search)
            $ragResults = $this->tryRAGSearch($validated['content'], $ragService, $forceDeepSearch);
            $ragSources = [];
            $hasFullContent = false;

            // Build base system prompt
            $basePrompt = $conversation->system_prompt ?? '';
            $systemPrompt = $basePrompt;

            // If RAG found results, add them to context with source tracking
            if (! empty($ragResults)) {
                // Check if we have full content (deep search mode)
                $hasFullContent = isset($ragResults[0]['full_content']);

                if ($hasFullContent) {
                    // Deep search mode: include full document content
                    $ragContext = "\n\n---\n## Knowledge Base Documents\n\n";

                    foreach ($ragResults as $idx => $result) {
                        $doc = $result['document'];
                        $title = $doc->title ?? 'Untitled';
                        $sourceNum = $idx + 1;
                        $mediaUrl = $doc->media_url ?? null;

                        // Use full content if available, otherwise fall back to document content
                        $content = $result['full_content'] ?? $doc->content ?? '';

                        $ragContext .= "### [{$sourceNum}] {$title}";
                        if ($mediaUrl) {
                            $ragContext .= "\n**Source URL:** {$mediaUrl}";
                        }
                        $ragContext .= "\n\n{$content}\n\n---\n\n";

                        $ragSources[] = [
                            'id' => $doc->id,
                            'num' => $sourceNum,
                            'title' => $title,
                            'url' => $mediaUrl,
                            'type' => $doc->document_type ?? null,
                            'similarity' => $result['similarity'] ?? null,
                            'content_length' => strlen($content),
                        ];
                    }

                    $ragContext .= "---\nRULES:\n";
                    $ragContext .= "1. ANSWER the user's question directly using info from documents above\n";
                    $ragContext .= "2. NEVER just list document titles - synthesize the actual content into an answer\n";
                    $ragContext .= "3. Cite inline with [1], [2] etc when using specific facts\n";
                    $ragContext .= "4. Do NOT output a 'Sources:' section - the UI displays sources separately\n";
                    $ragContext .= "5. Be concise, no filler phrases, no trailing questions\n";
                } else {
                    // Normal mode: preview snippets
                    $ragContext = "\n\n---\n## Knowledge Base Results\n\n";
                    foreach ($ragResults as $idx => $result) {
                        $doc = $result['document'];
                        $title = $doc->title ?? 'Untitled';
                        $sourceNum = $idx + 1;
                        $mediaUrl = $doc->media_url ?? null;
                        $contentPreview = substr($doc->content ?? '', 0, 400);

                        $ragContext .= "[{$sourceNum}] **{$title}**";
                        if ($mediaUrl) {
                            $ragContext .= " (URL: {$mediaUrl})";
                        }
                        $ragContext .= "\n{$contentPreview}...\n\n";

                        $ragSources[] = [
                            'id' => $doc->id,
                            'num' => $sourceNum,
                            'title' => $title,
                            'url' => $mediaUrl,
                            'type' => $doc->document_type ?? null,
                            'similarity' => $result['similarity'] ?? null,
                        ];
                    }

                    $ragContext .= "---\nRULES:\n";
                    $ragContext .= "1. ANSWER the user's question directly using info from documents above\n";
                    $ragContext .= "2. NEVER just list document titles - synthesize the actual content into an answer\n";
                    $ragContext .= "3. Cite inline with [1], [2] etc when using specific facts\n";
                    $ragContext .= "4. Do NOT output a 'Sources:' section - the UI displays sources separately\n";
                    $ragContext .= "5. Be concise, no filler phrases, no trailing questions\n";
                }

                $systemPrompt .= $ragContext;
            }

            // Build last user message
            $lastUserMessage = end($context)['content'] ?? '';

            // When we have deep RAG context, use simple generation without tool calling
            // This prevents the AI from ignoring the provided context and making web searches
            if ($hasFullContent) {
                \Log::info('Chat using direct generation with deep RAG context', [
                    'sources_count' => count($ragSources),
                    'total_content_chars' => array_sum(array_map(fn ($s) => $s['content_length'] ?? 0, $ragSources)),
                ]);

                // Use process() for direct generation - no tool calling
                $result = $aiService->process($lastUserMessage, [
                    'temperature' => 0.7,
                    'max_tokens' => 4000, // Allow longer responses for synthesis
                    'system_prompt' => $systemPrompt,
                ]);
            } else {
                // Normal mode: use tool-aware processing
                $aiRouter = app(AIRouter::class);
                $systemPrompt = $aiRouter->buildToolAwarePrompt($systemPrompt, true);

                $result = $aiService->processWithTools($lastUserMessage, [
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                    'system_prompt' => $systemPrompt,
                ]);
            }

            $assistantContent = $result['success'] ? $result['response'] : 'I apologize, but I encountered an error processing your request.';

            // Post-process to remove forbidden patterns (INTJ compliance)
            $modelName = $conversation->model ?? 'llama';
            $sanitizer = app(AIResponseSanitizer::class);
            $assistantContent = $sanitizer->sanitize($assistantContent, $modelName);

            // Tool calls are tracked in the AIRouter logs, extract if available
            $toolCalls = null; // processWithTools doesn't return tool_calls directly
            $tokens = null; // Estimate based on response length
            if (! empty($assistantContent)) {
                $tokens = (int) (strlen($assistantContent) / 4);
            }

            // Save assistant message (skip for private conversations)
            $assistantNow = now();
            $toolCallsJson = $toolCalls ? json_encode($toolCalls) : null;
            DB::insert(
                'INSERT INTO chat_messages (conversation_id, role, content, tool_calls, tokens, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, 'assistant', $assistantContent, $toolCallsJson, $tokens, $assistantNow, $assistantNow]
            );
            $assistantMessageId = DB::getPdo()->lastInsertId();

            // Auto-generate title from first user message if needed
            if (is_null($conversation->title) && count($messages) === 1) {
                $title = \Illuminate\Support\Str::limit($validated['content'], 50);
                $titleNow = now();
                DB::update(
                    'UPDATE conversations SET title = ?, updated_at = ? WHERE id = ?',
                    [$title, $titleNow, $id]
                );
            }

            // Get the saved messages using raw SQL
            $sql = 'SELECT * FROM chat_messages WHERE id = ? LIMIT 1';
            $userMessage = DB::select($sql, [$userMessageId])[0] ?? null;
            $assistantMessage = DB::select($sql, [$assistantMessageId])[0] ?? null;

            return response()->json([
                'success' => true,
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
            ]);

        } catch (\Exception $e) {
            // Log error and return error response
            \Log::error('Chat AI Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'AI processing failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Try RAG semantic search for relevant context
     *
     * Enhanced with deep search mode that fetches full document content
     * for top results, enabling comprehensive AI synthesis like Claude Code.
     *
     * Modes:
     * - Normal: Returns preview snippets (400 chars) for quick responses
     * - Deep: Fetches full content (up to 15K chars each) for top 5 docs
     *
     * Deep mode is triggered when:
     * - Query contains research keywords (collect, summarize, compile, research)
     * - Query asks about combining/synthesizing information
     * - Query is longer (suggests complex research need)
     *
     * @param  string  $query  User's query
     * @param  RAGService  $ragService  RAG service instance
     * @param  bool  $forceDeep  Force deep search mode
     * @return array Search results with optional full_documents key
     */
    private function tryRAGSearch(string $query, RAGService $ragService, bool $forceDeep = false): array
    {
        // Skip RAG for very short queries that are likely greetings/commands
        $trimmedQuery = trim($query);
        if (strlen($trimmedQuery) < 5) {
            return [];
        }

        // Skip common greetings and commands that don't need RAG
        $skipPatterns = [
            '/^(hi|hello|hey|thanks|thank you|ok|okay|yes|no|bye|goodbye)$/i',
            '/^(help|clear|reset|start over)$/i',
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $trimmedQuery)) {
                return [];
            }
        }

        try {
            // Detect if deep search is needed based on query patterns
            $deepSearchPatterns = [
                '/\b(collect|compile|gather|summarize|synthesize|research)\b/i',
                '/\b(combine|combining|consolidate|aggregate)\b/i',
                '/\b(everything|all|comprehensive|complete|full)\s+(about|on|regarding)\b/i',
                '/\b(what do (i|we|you) (know|have))\b/i',
                '/\b(find all|search for|look up)\b/i',
            ];

            $isDeepSearch = $forceDeep;
            if (! $isDeepSearch) {
                foreach ($deepSearchPatterns as $pattern) {
                    if (preg_match($pattern, $trimmedQuery)) {
                        $isDeepSearch = true;
                        break;
                    }
                }
            }

            // Also trigger deep search for longer, more complex queries
            if (! $isDeepSearch && str_word_count($trimmedQuery) >= 10) {
                $isDeepSearch = true;
            }

            \Log::info('Chat RAG search starting', [
                'query' => $query,
                'deep_mode' => $isDeepSearch,
            ]);

            if ($isDeepSearch) {
                // Deep search: fetch full content for relevant results
                $deepResult = $ragService->deepSearch(
                    query: $query,
                    topN: 50,              // Get candidates
                    maxContentPerDoc: 20000,  // Up to 20K chars each
                    documentType: null,
                    useGraph: true,
                    graphMode: 'local',
                    graphAlpha: 0.6        // Favor vector slightly for general chat
                );

                // Apply adaptive threshold filtering to deep search results
                $searchResults = $deepResult['results'];
                $topSim = ! empty($searchResults) ? ($searchResults[0]['similarity'] ?? 0) : 0;

                // Detect if scores are RRF (0.01-0.05) or cosine (0.3-0.9)
                // RRF scores are much smaller, so use different thresholds
                $isRRFScore = $topSim < 0.1;
                if ($isRRFScore) {
                    // RRF: keep docs within 50% of top score, min 0.005
                    $threshold = max(0.005, $topSim * 0.5);
                } else {
                    // Cosine: keep docs within 70% of top score (they cluster tighter)
                    $threshold = max(0.3, $topSim * 0.7);
                }

                $filteredResults = array_filter($searchResults, function ($r) use ($threshold) {
                    return ($r['similarity'] ?? 0) >= $threshold;
                });
                $filteredResults = array_values($filteredResults);

                // Get IDs of filtered results for full doc lookup
                $filteredIds = array_map(fn ($r) => $r['document']->id, $filteredResults);

                \Log::info('Chat RAG deep search completed', [
                    'query' => $query,
                    'candidates' => count($searchResults),
                    'filtered' => count($filteredResults),
                    'threshold' => $threshold,
                    'total_content_chars' => $deepResult['total_chars'],
                ]);

                // Return enhanced results with full documents attached
                $results = [];
                $fullDocMap = [];
                foreach ($deepResult['full_documents'] as $doc) {
                    if (in_array($doc->id, $filteredIds)) {
                        $fullDocMap[$doc->id] = $doc;
                    }
                }

                foreach ($filteredResults as $searchResult) {
                    $docId = $searchResult['document']->id;
                    $result = $searchResult;

                    // Attach full content if available
                    if (isset($fullDocMap[$docId])) {
                        $result['full_content'] = $fullDocMap[$docId]->content;
                    }

                    $results[] = $result;
                }

                return $results;
            }

            // Normal search: fetch candidates for filtering
            $results = $ragService->search($query, 30);

            \Log::info('Chat RAG search completed', [
                'query' => $query,
                'results_count' => count($results),
                'top_titles' => array_slice(array_map(fn ($r) => $r['document']->title ?? 'untitled', $results), 0, 3),
            ]);

            // Apply strict relevance filtering
            // Must have minimum similarity AND be close to top score
            $topSim = ! empty($results) ? ($results[0]['similarity'] ?? 0) : 0;
            $isRRFScore = $topSim < 0.1;

            if ($isRRFScore) {
                // RRF scores: keep top 5-10 that are within 50% of top score
                $threshold = max(0.008, $topSim * 0.5);
            } else {
                // Cosine similarity: require 0.55 minimum AND within 85% of top score
                $threshold = max(0.55, $topSim * 0.85);
            }

            $filtered = array_filter($results, function ($r) use ($threshold) {
                return ($r['similarity'] ?? 0) >= $threshold;
            });

            // Hard cap at 10 documents max for chat context
            $finalResults = array_values(array_slice($filtered, 0, 10));

            // Auto-upgrade to deep search if we found high-quality matches
            $topSimilarity = ! empty($finalResults) ? ($finalResults[0]['similarity'] ?? 0) : 0;
            // Only upgrade if top result is actually relevant (cosine > 0.6 or RRF > 0.015)
            if (($isRRFScore && $topSimilarity >= 0.015 && count($finalResults) >= 2) ||
                (! $isRRFScore && $topSimilarity >= 0.60 && count($finalResults) >= 1)) {
                \Log::info('Chat RAG upgrading to deep search due to high similarity', [
                    'query' => $query,
                    'top_similarity' => $topSimilarity,
                    'results_count' => count($finalResults),
                ]);

                // Re-run as deep search with stricter limit
                $deepResult = $ragService->deepSearch(
                    query: $query,
                    topN: 15,  // Reduced from 50
                    maxContentPerDoc: 15000,
                    documentType: null,
                    useGraph: true,
                    graphMode: 'local',
                    graphAlpha: 0.6
                );

                // Re-apply strict filtering to deep search results
                $deepResults = $deepResult['results'];
                $deepTopSim = ! empty($deepResults) ? ($deepResults[0]['similarity'] ?? 0) : 0;
                $deepThreshold = $isRRFScore ? max(0.008, $deepTopSim * 0.5) : max(0.55, $deepTopSim * 0.85);

                $results = [];
                $fullDocMap = [];
                foreach ($deepResult['full_documents'] as $doc) {
                    $fullDocMap[$doc->id] = $doc;
                }

                $count = 0;
                foreach ($deepResults as $searchResult) {
                    if ($count >= 8) {
                        break;
                    }  // Hard limit 8 docs for deep search
                    if (($searchResult['similarity'] ?? 0) < $deepThreshold) {
                        continue;
                    }

                    $docId = $searchResult['document']->id;
                    $result = $searchResult;
                    if (isset($fullDocMap[$docId])) {
                        $result['full_content'] = $fullDocMap[$docId]->content;
                    }
                    $results[] = $result;
                    $count++;
                }

                \Log::info('Chat RAG deep upgrade completed', [
                    'query' => $query,
                    'results_count' => count($results),
                    'full_docs_count' => count($deepResult['full_documents']),
                ]);

                return $results;
            }

            \Log::info('Chat RAG returning results', ['count' => count($finalResults)]);

            return $finalResults;
        } catch (\Exception $e) {
            \Log::error('RAG search failed in chat', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Send a message and stream AI response in real-time (SSE)
     * Now with integrated intent detection from OrchestratorService
     *
     * @param  Request  $request
     *                            - content: (required) The message content
     *                            - deep_search: (optional) Force deep RAG search with full document content
     */
    public function sendMessageStream(Request $request, $id)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'deep_search' => 'nullable|boolean',
        ]);
        $forceDeepSearch = $validated['deep_search'] ?? false;

        // Verify conversation exists using raw SQL
        $sql = 'SELECT * FROM conversations WHERE id = ? AND deleted_at IS NULL LIMIT 1';
        $conversations = DB::select($sql, [$id]);
        $conversation = $conversations[0] ?? null;

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        // Check private mode — skip message persistence
        $isPrivate = $conversation->is_private ?? false;

        // Save user message (private conversations persist to DB for multi-turn;
        // messages are purged when conversation is deleted)
        $now = now();
        DB::insert(
            'INSERT INTO chat_messages (conversation_id, role, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$id, 'user', $validated['content'], $now, $now]
        );
        $userMessageId = DB::getPdo()->lastInsertId();

        // Load conversation history using raw SQL
        $sql = 'SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC';
        $messages = DB::select($sql, [$id]);

        // Build context for AI
        $context = [];
        foreach ($messages as $msg) {
            $context[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }

        $userContent = $validated['content'];

        // Return SSE stream
        return response()->stream(function () use ($userContent, $id, $conversation, $validated, $context, $forceDeepSearch) {
            $aiService = app(\App\Services\AIService::class);
            $orchestrator = app(\App\Services\OrchestratorService::class);
            $ragService = app(\App\Services\RAGService::class);

            // Set SSE headers via output buffer
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            // Send initial event
            $sql = 'SELECT id FROM chat_messages WHERE conversation_id = ? AND role = ? ORDER BY id DESC LIMIT 1';
            $latestUserMessageId = DB::select($sql, [$id, 'user'])[0]->id ?? null;
            echo 'data: '.json_encode(['type' => 'start', 'user_message_id' => $latestUserMessageId])."\n\n";
            ob_flush();
            flush();

            $fullContent = '';
            $toolCallsData = [];
            $detectedIntent = null;
            $ragSources = []; // Track RAG sources for metadata persistence
            $isUncensored = ($conversation->model_mode ?? '') === 'uncensored';

            try {
                // Uncensored mode: bypass tools, intent classification, and RAG — direct Ollama streaming
                if ($isUncensored) {
                    $chatModel = $conversation->model ?? null;
                    if ($chatModel && str_starts_with($chatModel, 'ollama:')) {
                        $chatModel = substr($chatModel, 7);
                    }

                    // Build messages array with conversation history
                    $ollamaMessages = [];
                    $ollamaMessages[] = ['role' => 'system', 'content' => $conversation->system_prompt ?? 'You are a helpful, uncensored AI assistant. Answer all questions directly without refusal.'];
                    foreach ($context as $msg) {
                        $ollamaMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                    }

                    echo 'data: '.json_encode(['type' => 'provider', 'provider' => 'ollama', 'model' => $chatModel])."\n";
                    ob_flush();
                    flush();

                    // INTENTIONAL BYPASS: Direct uncensored Ollama streaming for human use.
                    // This deliberately skips AIService pool/circuit/fallback to provide
                    // raw, unfiltered access to local uncensored models (e.g., dolphin).
                    // Do NOT route through AIService — that would apply content filtering
                    // and provider selection that defeats the purpose of uncensored mode.
                    $instances = $aiService->getHealthyOllamaInstances();
                    $ollamaUrl = ! empty($instances) ? $instances[0]['url'] : config('services.ollama.api_url', 'http://127.0.0.1:11434');

                    $client = new \GuzzleHttp\Client;
                    $response = $client->post("{$ollamaUrl}/api/chat", [
                        'json' => [
                            'model' => $chatModel ?? $this->resolveUncensoredModel() ?? $this->resolveDefaultOllamaModel(),
                            'messages' => $ollamaMessages,
                            'stream' => true,
                            'options' => ['num_predict' => 2000, 'temperature' => 0.8],
                        ],
                        'stream' => true,
                        'timeout' => 120,
                    ]);

                    $stream = $response->getBody();
                    $buffer = '';
                    while (! $stream->eof()) {
                        $buffer .= $stream->read(1024);
                        while (($pos = strpos($buffer, "\n")) !== false) {
                            $line = substr($buffer, 0, $pos);
                            $buffer = substr($buffer, $pos + 1);
                            if (empty(trim($line))) {
                                continue;
                            }
                            $data = json_decode($line, true);
                            if ($data && isset($data['message']['content'])) {
                                $token = $data['message']['content'];
                                $fullContent .= $token;
                                echo 'data: '.json_encode(['type' => 'content', 'content' => $token])."\n\n";
                                ob_flush();
                                flush();
                            }
                        }
                    }

                    // Skip to save section (after the normal processing try block)
                    goto save_and_complete;
                }

                // Step 1: Classify intent using OrchestratorService
                $intent = $this->classifyIntentForChat($userContent, $orchestrator, $aiService);
                $detectedIntent = $intent;

                // Send intent detection event to frontend
                echo 'data: '.json_encode([
                    'type' => 'intent_detected',
                    'intent' => $intent['intent'],
                    'confidence' => $intent['confidence'],
                    'reasoning' => $intent['reasoning'] ?? null,
                ])."\n\n";
                ob_flush();
                flush();

                // Step 2: Handle based on intent
                if ($this->isActionableIntent($intent)) {
                    // Route to orchestrator for workflow/RAG/MCP/email actions
                    $result = $orchestrator->process($userContent, $id);

                    if ($result['success']) {
                        $fullContent = $this->formatOrchestratorResult($result);

                        // Send as content chunks for streaming feel
                        $chunks = str_split($fullContent, 20);
                        foreach ($chunks as $chunk) {
                            echo 'data: '.json_encode(['type' => 'content', 'content' => $chunk])."\n\n";
                            ob_flush();
                            flush();
                            usleep(10000); // Small delay for streaming effect
                        }

                        // Add tool call info if applicable
                        if (isset($result['intent']['intent'])) {
                            $toolCallsData[] = [
                                'type' => 'orchestrator_action',
                                'intent' => $result['intent']['intent'],
                                'result_type' => $result['result']['type'] ?? 'unknown',
                            ];
                        }
                    } else {
                        // Orchestrator failed, fall back to conversation
                        $fullContent = $this->handleConversationStream(
                            $userContent, $context, $conversation, $aiService, $ragService
                        );
                    }
                } else {
                    // General conversation - use streaming AI with RAG + web search fallback
                    // Check if RAG search might be helpful (with deep search detection)
                    $ragResults = $this->tryRAGSearch($userContent, $ragService, $forceDeepSearch);
                    $isDeepSearch = false;
                    $useWebSearch = false;

                    // Build base system prompt
                    $basePrompt = $conversation->system_prompt ?? '';
                    $systemPrompt = $basePrompt;

                    // Determine if RAG results are useful (top similarity > 0.6 for cosine)
                    $topSimilarity = ! empty($ragResults) ? ($ragResults[0]['similarity'] ?? 0) : 0;
                    $hasRelevantRAG = $topSimilarity >= 0.60;

                    // If RAG found relevant results, add them to context with source tracking
                    if (! empty($ragResults) && $hasRelevantRAG) {
                        // Check if we have full content (deep search mode)
                        $hasFullContent = isset($ragResults[0]['full_content']);
                        $isDeepSearch = $hasFullContent;

                        if ($hasFullContent) {
                            // Deep search mode: include full document content
                            $ragContext = "\n\n---\n## Knowledge Base Documents\n\n";

                            foreach ($ragResults as $idx => $result) {
                                $doc = $result['document'];
                                $title = $doc->title ?? 'Untitled';
                                $sourceNum = $idx + 1;
                                $mediaUrl = $doc->media_url ?? null;

                                // Use full content if available
                                $content = $result['full_content'] ?? $doc->content ?? '';

                                $ragContext .= "### [{$sourceNum}] {$title}";
                                if ($mediaUrl) {
                                    $ragContext .= "\n**Source URL:** {$mediaUrl}";
                                }
                                $ragContext .= "\n\n{$content}\n\n---\n\n";

                                $ragSources[] = [
                                    'id' => $doc->id,
                                    'num' => $sourceNum,
                                    'title' => $title,
                                    'url' => $mediaUrl,
                                    'type' => $doc->document_type ?? null,
                                    'similarity' => $result['similarity'] ?? null,
                                    'content_length' => strlen($content),
                                ];
                            }

                            $ragContext .= "---\n## STRICT RULES (MUST FOLLOW):\n";
                            $ragContext .= "1. ONLY use information from the documents above. Do NOT use general knowledge.\n";
                            $ragContext .= "2. SYNTHESIZE the content into a direct answer. Never list document titles.\n";
                            $ragContext .= "3. Cite inline with [1], [2] etc when using specific facts.\n";
                            $ragContext .= "4. NEVER output 'Sources:', 'References:', or any source list - UI handles this.\n";
                            $ragContext .= "5. If documents don't contain the answer, say 'I don't have information about that in my knowledge base.'\n";
                            $ragContext .= "6. Be concise. No filler. No trailing questions. No 'I hope this helps'.\n";
                        } else {
                            // Normal mode: preview snippets
                            $ragContext = "\n\n---\n## Knowledge Base Results\n\n";
                            foreach ($ragResults as $idx => $result) {
                                $doc = $result['document'];
                                $title = $doc->title ?? 'Untitled';
                                $sourceNum = $idx + 1;
                                $mediaUrl = $doc->media_url ?? null;
                                $contentPreview = substr($doc->content ?? '', 0, 400);

                                $ragContext .= "[{$sourceNum}] **{$title}**";
                                if ($mediaUrl) {
                                    $ragContext .= " (URL: {$mediaUrl})";
                                }
                                $ragContext .= "\n{$contentPreview}...\n\n";

                                $ragSources[] = [
                                    'id' => $doc->id,
                                    'num' => $sourceNum,
                                    'title' => $title,
                                    'url' => $mediaUrl,
                                    'type' => $doc->document_type ?? null,
                                    'similarity' => $result['similarity'] ?? null,
                                ];
                            }

                            $ragContext .= "---\n## STRICT RULES (MUST FOLLOW):\n";
                            $ragContext .= "1. ONLY use information from the documents above. Do NOT use general knowledge.\n";
                            $ragContext .= "2. SYNTHESIZE the content into a direct answer. Never list document titles.\n";
                            $ragContext .= "3. Cite inline with [1], [2] etc when using specific facts.\n";
                            $ragContext .= "4. NEVER output 'Sources:', 'References:', or any source list - UI handles this.\n";
                            $ragContext .= "5. If documents don't contain the answer, say 'I don't have information about that in my knowledge base.'\n";
                            $ragContext .= "6. Be concise. No filler. No trailing questions. No 'I hope this helps'.\n";
                        }

                        $systemPrompt .= $ragContext;

                        // DON'T send rag_search event here - sources will be sent with 'complete' event
                        // This prevents sources from appearing before AI response
                        \Log::info('Chat: RAG sources prepared, will send with completion', [
                            'sources_count' => count($ragSources),
                            'deep_search' => $isDeepSearch,
                        ]);
                    } else {
                        // RAG has no relevant results - fall back to web search
                        $useWebSearch = true;

                        \Log::info('Chat: RAG returned no relevant results, enabling web search', [
                            'query' => $userContent,
                            'top_similarity' => $topSimilarity,
                            'threshold' => 0.60,
                        ]);

                        // Notify frontend that web search will be used
                        echo 'data: '.json_encode([
                            'type' => 'web_search_fallback',
                            'reason' => 'No relevant documents in knowledge base',
                            'rag_top_similarity' => $topSimilarity,
                        ])."\n\n";
                        ob_flush();
                        flush();

                        // Add instruction to use parallel web search (SearXNG + Wikipedia + NewsAPI simultaneously)
                        $systemPrompt .= "\n\n---\n## Web Search Required\n";
                        $systemPrompt .= "The knowledge base does not have relevant information for this query.\n";
                        $systemPrompt .= "Use the web_search_parallel tool to search multiple sources simultaneously (SearXNG + Wikipedia + NewsAPI).\n";
                        $systemPrompt .= "After getting results, synthesize them into a concise answer.\n";
                        $systemPrompt .= "RULES: Be concise. No filler. Cite sources inline when using specific facts.\n";
                    }

                    // Both deep search and normal search use tool-aware processing
                    // Deep search has full content in context, normal has previews
                    if ($isDeepSearch) {
                        \Log::info('Chat streaming with deep RAG context (tool-aware)', [
                            'sources_count' => count($ragSources),
                            'total_content_chars' => array_sum(array_map(fn ($s) => $s['content_length'] ?? 0, $ragSources)),
                        ]);
                    }

                    // Use tool-aware streaming for all modes

                    $aiRouter = app(AIRouter::class);
                    $systemPrompt = $aiRouter->buildToolAwarePrompt($systemPrompt, true);

                    // Extract model name for Ollama (strip 'ollama:' prefix)
                    $chatModel = $conversation->model ?? null;
                    if ($chatModel && str_starts_with($chatModel, 'ollama:')) {
                        $chatModel = substr($chatModel, 7);
                    }

                    foreach ($aiService->processWithToolsStreaming($userContent, array_filter([
                        'temperature' => 0.7,
                        'max_tokens' => 2000,
                        'system_prompt' => $systemPrompt,
                        'model' => $chatModel,
                    ])) as $chunk) {
                        // Parse chunk to build full message
                        $chunkData = json_decode(trim($chunk), true);
                        if ($chunkData) {
                            // Skip 'done' event - we send our own 'complete' event with message ID
                            if ($chunkData['type'] === 'done') {
                                continue;
                            }

                            if ($chunkData['type'] === 'content') {
                                $fullContent .= $chunkData['content'];
                            } elseif ($chunkData['type'] === 'tool_call') {
                                $toolCallsData[] = $chunkData;
                            }
                        }

                        // Forward chunk to client (except 'done' which was skipped above)
                        echo 'data: '.$chunk."\n";
                        ob_flush();
                        flush();
                    }

                }

                // Post-process to remove forbidden patterns (INTJ compliance)
                // Detect model family from conversation settings for model-specific sanitization
                $modelName = $conversation->model ?? 'llama';
                $sanitizer = app(AIResponseSanitizer::class);
                $originalContent = $fullContent;
                $fullContent = $sanitizer->sanitize($fullContent, $modelName);

                // If sanitization changed content, send replacement to frontend
                if ($fullContent !== $originalContent) {
                    echo 'data: '.json_encode([
                        'type' => 'content_replace',
                        'content' => $fullContent,
                    ])."\n\n";
                    ob_flush();
                    flush();
                }

                save_and_complete:
                // Save assistant message to database
                $tokens = (int) (strlen($fullContent) / 4);
                $assistantNow = now();
                $toolCallsJson = ! empty($toolCallsData) ? json_encode($toolCallsData) : null;

                // Build metadata with intent and RAG sources for persistence
                $metadataArray = [];
                if ($detectedIntent) {
                    $metadataArray['intent'] = $detectedIntent;
                }
                if (! empty($ragSources)) {
                    $metadataArray['ragSources'] = $ragSources;
                }
                $metadata = ! empty($metadataArray) ? json_encode($metadataArray) : null;

                // Persist assistant message (private conversations also persist for multi-turn;
                // messages are purged when conversation is deleted)
                DB::insert(
                    'INSERT INTO chat_messages (conversation_id, role, content, tool_calls, tokens, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, 'assistant', $fullContent, $toolCallsJson, $tokens, $metadata, $assistantNow, $assistantNow]
                );
                $assistantMessageId = DB::getPdo()->lastInsertId();

                // Auto-generate title from first user message if needed
                if (is_null($conversation->title)) {
                    $sql = 'SELECT COUNT(*) as count FROM chat_messages WHERE conversation_id = ?';
                    $messageCount = DB::select($sql, [$id])[0]->count ?? 0;

                    if ($messageCount === 2) { // User + assistant = first exchange
                        $title = \Illuminate\Support\Str::limit($validated['content'], 50);
                        $titleNow = now();
                        DB::update(
                            'UPDATE conversations SET title = ?, updated_at = ? WHERE id = ?',
                            [$title, $titleNow, $id]
                        );
                    }
                }

                // Send final completion event with message ID, intent, and RAG sources
                // RAG sources sent here (not during streaming) so they appear AFTER the response
                $completeEvent = [
                    'type' => 'complete',
                    'assistant_message_id' => $assistantMessageId,
                    'tokens' => $tokens,
                    'intent' => $detectedIntent,
                ];
                if (! empty($ragSources)) {
                    $completeEvent['ragSources'] = $ragSources;
                    \Log::info('Chat: Sending RAG sources with complete event', [
                        'sources_count' => count($ragSources),
                        'source_ids' => array_column($ragSources, 'id'),
                    ]);
                }
                echo 'data: '.json_encode($completeEvent)."\n\n";
                ob_flush();
                flush();

            } catch (\Exception $e) {
                \Log::error('Streaming chat error: '.$e->getMessage());
                echo 'data: '.json_encode(['type' => 'error', 'message' => $e->getMessage()])."\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Classify intent for chat using a lightweight approach
     */
    private function classifyIntentForChat(string $content, OrchestratorService $orchestrator, AIService $aiService): array
    {
        // Use keyword-based classification first for speed
        $lower = strtolower($content);

        // Workflow execution patterns
        if (preg_match('/\b(run|execute|trigger|start)\s+(the\s+)?(\w+)\s*(workflow)?/i', $content, $matches)) {
            return [
                'intent' => 'workflow_execution',
                'confidence' => 0.85,
                'parameters' => ['workflow_name' => $matches[3]],
                'reasoning' => 'Keyword match: '.$matches[1],
            ];
        }

        // Email patterns
        if (preg_match('/\b(email|inbox|message|reply|draft)\b/i', $content)) {
            if (preg_match('/\b(classify|categorize|analyze)\b/i', $content)) {
                return [
                    'intent' => 'email_classify',
                    'confidence' => 0.8,
                    'parameters' => ['query' => $content],
                    'reasoning' => 'Email classification keywords detected',
                ];
            }
            if (preg_match('/\b(reply|respond|draft|write)\b/i', $content)) {
                return [
                    'intent' => 'email_reply',
                    'confidence' => 0.8,
                    'parameters' => [],
                    'reasoning' => 'Email reply keywords detected',
                ];
            }
            if (preg_match('/\b(search|find|show|list)\b/i', $content)) {
                return [
                    'intent' => 'email_search',
                    'confidence' => 0.8,
                    'parameters' => ['query' => $content],
                    'reasoning' => 'Email search keywords detected',
                ];
            }
        }

        // RAG/Document search patterns
        if (preg_match('/\b(search|find|lookup|query)\s+(my\s+)?(documents?|notes?|files?|knowledge|history)/i', $content)) {
            return [
                'intent' => 'rag_search',
                'confidence' => 0.8,
                'parameters' => ['query' => $content],
                'reasoning' => 'Document search keywords detected',
            ];
        }

        // MCP tool patterns
        if (preg_match('/\b(calendar|events?|schedule|appointment)/i', $content)) {
            return [
                'intent' => 'mcp_tool',
                'confidence' => 0.7,
                'parameters' => ['tool_hint' => 'calendar'],
                'reasoning' => 'Calendar-related request',
            ];
        }

        // Default to general conversation
        return [
            'intent' => 'general_conversation',
            'confidence' => 0.6,
            'parameters' => [],
            'reasoning' => 'No specific action keywords detected',
        ];
    }

    /**
     * Check if intent requires orchestrator action vs just conversation
     */
    private function isActionableIntent(array $intent): bool
    {
        $actionableIntents = [
            'workflow_execution',
            'email_classify',
            'email_reply',
            'email_search',
            'mcp_tool',
            'multi_step',
        ];

        // Only route to orchestrator if confidence is high enough
        return in_array($intent['intent'], $actionableIntents) && ($intent['confidence'] ?? 0) >= 0.75;
    }

    /**
     * Format orchestrator result as readable message
     */
    private function formatOrchestratorResult(array $result): string
    {
        $res = $result['result'] ?? [];
        $type = $res['type'] ?? 'unknown';

        switch ($type) {
            case 'workflow_execution':
                $status = $res['status'] ?? 'unknown';
                $workflow = $res['workflow'] ?? 'workflow';
                $execId = $res['execution_id'] ?? '';
                $heading = $status === 'queued' ? 'Workflow Queued' : 'Workflow Executed';
                $summary = $status === 'queued'
                    ? "I queued the `{$workflow}` workflow."
                    : "I ran the `{$workflow}` workflow.";

                return "**{$heading}**\n\n{$summary}\n\n".
                       "- **Status:** {$status}\n".
                       ($execId ? "- **Execution ID:** {$execId}\n" : '').
                       "\n".($res['message'] ?? '');

            case 'rag_search':
                $count = $res['results_count'] ?? 0;
                $query = $res['query'] ?? '';
                $output = "**Knowledge Base Search**\n\nFound **{$count}** results for: \"{$query}\"\n\n";
                if (! empty($res['results'])) {
                    foreach (array_slice($res['results'], 0, 5) as $doc) {
                        $title = $doc['title'] ?? 'Untitled';
                        $preview = $doc['content_preview'] ?? '';
                        $sim = isset($doc['similarity']) ? round($doc['similarity'] * 100).'%' : '';
                        $output .= "- **{$title}** ({$sim})\n  {$preview}\n\n";
                    }
                }

                return $output;

            case 'email_classify':
            case 'email_search':
                $count = $res['total_classified'] ?? $res['results_count'] ?? 0;

                return "**Email Operation**\n\n".($res['message'] ?? "Processed {$count} emails.");

            case 'mcp_tool':
                $tool = $res['tool'] ?? 'tool';

                return "**MCP Tool: {$tool}**\n\n".($res['message'] ?? 'Tool executed successfully.');

            case 'conversation':
                return $res['message'] ?? '';

            case 'error':
                return "**Error**\n\n".($res['message'] ?? 'An error occurred.');

            default:
                return $res['message'] ?? json_encode($res, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Handle conversation stream when orchestrator is not used
     */
    private function handleConversationStream(
        string $content,
        array $context,
        object $conversation,
        AIService $aiService,
        RAGService $ragService
    ): string {
        // This is called as fallback - just return empty since streaming handles it
        return '';
    }

    /**
     * Save a chat message to RAG knowledge base
     *
     * Allows users to persist useful AI responses for future reference
     */
    public function saveMessageToRAG(Request $request, $conversationId, $messageId)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'designation' => 'nullable|string|max:100',
        ]);

        // Get the message using raw SQL
        $sql = 'SELECT cm.*, c.title as conversation_title
                FROM chat_messages cm
                JOIN conversations c ON c.id = cm.conversation_id
                WHERE cm.id = ? AND cm.conversation_id = ? LIMIT 1';
        $messages = DB::select($sql, [$messageId, $conversationId]);
        $message = $messages[0] ?? null;

        if (! $message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        // Only allow saving assistant messages (AI responses)
        if ($message->role !== 'assistant') {
            return response()->json([
                'success' => false,
                'message' => 'Only AI assistant responses can be saved to knowledge base',
            ], 400);
        }

        // Check if this message was already saved
        $existingSql = "SELECT id FROM rag_documents WHERE source_type = 'chat_message' AND source_id = ?";
        $existing = DB::connection('pgsql_rag')->select($existingSql, [(string) $messageId]);
        if (! empty($existing)) {
            return response()->json([
                'success' => false,
                'message' => 'This message has already been saved to the knowledge base',
                'existing_id' => $existing[0]->id,
            ], 409);
        }

        try {
            $ragService = app(RAGService::class);

            // Generate a title if not provided
            $title = $validated['title'] ?? $this->generateTitleFromContent($message->content, $message->conversation_title);

            // Use provided designation or default to 'chat_saved'
            $designation = $validated['designation'] ?? 'chat_saved';

            // Metadata includes conversation context
            $metadata = [
                'conversation_id' => $conversationId,
                'conversation_title' => $message->conversation_title,
                'message_role' => $message->role,
                'saved_at' => now()->toIso8601String(),
                'tokens' => $message->tokens,
            ];

            // Index to RAG
            $document = $ragService->indexDocument(
                documentType: 'chat_response',
                content: $message->content,
                title: $title,
                metadata: $metadata,
                sourceId: (string) $messageId,
                sourceType: 'chat_message',
                designation: $designation
            );

            return response()->json([
                'success' => true,
                'message' => 'Message saved to knowledge base',
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'designation' => $document->designation,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save to knowledge base: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a title from message content
     */
    private function generateTitleFromContent(string $content, ?string $conversationTitle): string
    {
        // If conversation has a title, use it as prefix
        $prefix = $conversationTitle ? "{$conversationTitle}: " : 'Chat Response: ';

        // Take first line or first 50 chars of content
        $firstLine = strtok(trim($content), "\n");
        $snippet = strlen($firstLine) > 50 ? substr($firstLine, 0, 47).'...' : $firstLine;

        // Clean up markdown
        $snippet = preg_replace('/^#+\s*/', '', $snippet);
        $snippet = preg_replace('/\*\*([^*]+)\*\*/', '$1', $snippet);

        return $prefix.$snippet;
    }

    /**
     * Check if a conversation is in private mode (no message persistence).
     */
    private function isPrivateConversation(int $conversationId): bool
    {
        $conv = DB::selectOne(
            'SELECT is_private FROM conversations WHERE id = ?',
            [$conversationId]
        );

        return $conv && $conv->is_private;
    }

    /**
     * Resolve uncensored model from profile rows first, then instance role maps.
     */
    private function resolveUncensoredModel(): ?string
    {
        try {
            $profile = DB::selectOne(
                "SELECT model_name
                 FROM llm_model_profiles
                 WHERE enabled = 1 AND profile_name IN ('uncensored', 'creative')
                 ORDER BY CASE profile_name WHEN 'uncensored' THEN 0 ELSE 1 END
                 LIMIT 1"
            );

            if ($profile?->model_name) {
                return (string) $profile->model_name;
            }

            $model = DB::selectOne(
                "SELECT om.model_name
                 FROM ollama_models om
                 JOIN llm_instances li ON li.id = om.instance_id
                 WHERE li.instance_type = 'ollama'
                   AND li.is_active = 1
                   AND om.is_available = 1
                   AND (
                        om.profile IN ('uncensored', 'creative')
                        OR JSON_SEARCH(om.capabilities, 'one', 'uncensored') IS NOT NULL
                        OR JSON_SEARCH(om.use_cases, 'one', 'uncensored') IS NOT NULL
                   )
                 ORDER BY
                    CASE om.status
                        WHEN 'vetted' THEN 0
                        WHEN 'testing' THEN 1
                        WHEN 'discovered' THEN 2
                        ELSE 3
                    END,
                    COALESCE(om.quality_rating, 0) DESC,
                    li.priority ASC,
                    om.model_name ASC
                 LIMIT 1"
            );

            if ($model?->model_name) {
                return (string) $model->model_name;
            }

            $row = DB::selectOne(
                "SELECT config FROM llm_instances WHERE instance_type = 'ollama' AND is_active = 1 ORDER BY priority ASC LIMIT 1"
            );

            if ($row) {
                $config = json_decode($row->config ?? '{}', true) ?: [];

                return $config['models']['uncensored']
                    ?? $config['models']['creative']
                    ?? null;
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return config('services.ollama.model');
    }

    private function resolveDefaultChatModel(): string
    {
        $model = $this->resolveDefaultOllamaModel();

        return $model !== null && $model !== '' ? 'ollama:'.$model : 'ollama';
    }

    private function resolveDefaultOllamaModel(): ?string
    {
        try {
            $profile = DB::selectOne(
                "SELECT model_name
                 FROM llm_model_profiles
                 WHERE enabled = 1 AND profile_name IN ('default', 'standard')
                 ORDER BY CASE profile_name WHEN 'default' THEN 0 ELSE 1 END
                 LIMIT 1"
            );

            if ($profile?->model_name) {
                return (string) $profile->model_name;
            }

            $row = DB::selectOne(
                "SELECT config
                 FROM llm_instances
                 WHERE instance_type = 'ollama' AND is_active = 1
                 ORDER BY priority ASC, id ASC
                 LIMIT 1"
            );

            if ($row) {
                $config = json_decode($row->config ?? '{}', true) ?: [];

                return $config['models']['standard']
                    ?? $config['default_model']
                    ?? config('services.ollama.model');
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return config('services.ollama.model');
    }

    /**
     * Get available model modes for the chat UI.
     */
    public function getModelModes()
    {
        $modes = [
            ['id' => 'standard', 'label' => 'Standard', 'description' => 'Default model', 'private' => false],
            ['id' => 'fast', 'label' => 'Fast', 'description' => 'Quick responses', 'private' => false],
            ['id' => 'quality', 'label' => 'Quality', 'description' => 'Best reasoning', 'private' => false],
            ['id' => 'uncensored', 'label' => 'Uncensored', 'description' => 'No filters, private', 'private' => true],
        ];

        return response()->json(['modes' => $modes]);
    }
}

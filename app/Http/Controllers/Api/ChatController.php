<?php

namespace App\Http\Controllers\Api;

use App\Engine\AIRouter;
use App\Engine\MCPRouter;
use App\Http\Controllers\Controller;
use App\Rules\NonEmptyUploadedFile;
use App\Services\AIResponseSanitizer;
use App\Services\AIService;
use App\Services\ChatAttachmentService;
use App\Services\ChatResponseFeedbackService;
use App\Services\ChatRunFailureService;
use App\Services\ChatRuntimePolicyService;
use App\Services\ChatStreamCancellationService;
use App\Services\ChatToolActivityService;
use App\Services\ContextPositioningService;
use App\Services\ImageGenerationService;
use App\Services\OfflineTtsService;
use App\Services\OllamaProgressClient;
use App\Services\OrchestratorService;
use App\Services\RAGService;
use App\Services\RagSourceReferenceService;
use App\Services\WhisperRuntimeService;
use App\Support\SensitiveTextTelemetry;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    private const RAG_QUERY_TELEMETRY_SCOPE = 'chat-rag';

    /** @var array<string,mixed>|null */
    private ?array $imageSidecarCapability = null;

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
            'model_mode' => 'nullable|string|max:50',
            'model_override' => 'nullable|boolean',
        ]);

        $title = $validated['title'] ?? null;
        $modelMode = $validated['model_mode'] ?? 'standard';
        $modelOverride = (bool) ($validated['model_override'] ?? false);
        $profile = $this->resolveChatProfile($modelMode, $modelOverride);

        if (($profile['override_required'] ?? false) && ! $modelOverride) {
            return response()->json([
                'success' => false,
                'message' => 'This model profile requires operator override.',
            ], 422);
        }

        if (($profile['selectable'] ?? true) === false) {
            return response()->json([
                'success' => false,
                'message' => 'This model profile is not available on the configured runtime.',
            ], 422);
        }

        $model = $validated['model'] ?? $profile['model'];
        $isPrivate = ($validated['is_private'] ?? false) || (bool) ($profile['private'] ?? false);

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
        if (Schema::hasColumn('conversations', 'model_override')) {
            DB::insert(
                'INSERT INTO conversations (title, model, is_private, model_mode, model_override, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$title, $model, $isPrivate, $modelMode, $modelOverride, $now, $now]
            );
        } else {
            DB::insert(
                'INSERT INTO conversations (title, model, is_private, model_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$title, $model, $isPrivate, $modelMode, $now, $now]
            );
        }
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
    public function show(
        $id,
        ChatAttachmentService $attachments,
        ChatResponseFeedbackService $responseFeedback
    ) {
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
        $messages = $attachments->appendAttachmentsToMessages($messages);
        $messages = $responseFeedback->appendToMessages($messages);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * Clear a conversation (soft delete)
     */
    public function destroy($id, ChatAttachmentService $attachments)
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
            $attachments->deleteForConversation((int) $id);
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
    public function clearMessages($id, ChatAttachmentService $attachments)
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

        // Delete all messages and uploaded attachments in the conversation
        $attachments->deleteForConversation((int) $id);
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
    public function sendMessage(Request $request, $id, ChatAttachmentService $attachments)
    {
        $validated = $request->validate([
            'content' => 'nullable|string',
            'deep_search' => 'nullable|boolean',
            'multi_step' => 'nullable|boolean',
            'attachments' => 'nullable|array|max:12',
            'attachments.*' => ['file', new NonEmptyUploadedFile, 'max:51200'],
        ]);
        $forceDeepSearch = $validated['deep_search'] ?? false;
        $useMultiStep = $validated['multi_step'] ?? false;
        $uploadedFiles = $this->uploadedAttachments($request);
        $content = trim((string) ($validated['content'] ?? ''));

        if ($content === '' && empty($uploadedFiles)) {
            return response()->json([
                'success' => false,
                'message' => 'Message content or at least one attachment is required.',
            ], 422);
        }

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

        $attachmentCapabilityError = $this->validateAttachmentCapabilities($uploadedFiles, $conversation, $attachments);
        if ($attachmentCapabilityError !== null) {
            return $attachmentCapabilityError;
        }

        $isPrivate = $conversation->is_private ?? false;
        $privacyRoutingConfig = [
            'sensitive_data' => true,
            'data_class' => $isPrivate ? 'private_chat' : 'chat',
            'sensitive_data_reason' => 'chat_conversation',
        ];

        $storedAttachments = $attachments->storeUploadedAttachments((int) $id, $uploadedFiles);
        $messageContent = $content !== '' ? $content : $this->attachmentOnlyContent($storedAttachments);

        // Save user message (private conversations also persist for multi-turn;
        // messages are hard-deleted when conversation is deleted)
        $now = now();
        DB::insert(
            'INSERT INTO chat_messages (conversation_id, role, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$id, 'user', $messageContent, $now, $now]
        );
        $userMessageId = DB::getPdo()->lastInsertId();
        $attachments->attachToMessage($storedAttachments, (int) $userMessageId);
        $storedAttachments = DB::select(
            'SELECT * FROM chat_message_attachments WHERE message_id = ? ORDER BY id ASC',
            [$userMessageId]
        );

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
            $attachmentPrompt = $attachments->prepareForPrompt($storedAttachments, $conversation, $aiService, $privacyRoutingConfig);
            $modelContent = $this->appendAttachmentPrompt($messageContent, $attachmentPrompt['prompt_suffix']);
            $generatedAttachments = [];

            if (! empty($context)) {
                $lastIndex = array_key_last($context);
                $context[$lastIndex]['content'] = $modelContent;
            }

            if ($this->isAudioTranscriptionConversation($conversation)) {
                $assistantContent = $this->formatTranscriptionResponse(
                    $attachmentPrompt['transcriptions'] ?? [],
                    $attachmentPrompt['metadata']['attachments'] ?? []
                );

                goto persist_assistant_response;
            }

            $modelCapabilities = $attachmentPrompt['metadata']['model_capabilities'] ?? [];
            if ($this->isAudioGenerationConversation($conversation, $modelCapabilities)) {
                $audioResult = $this->generateAudioForChat(
                    $modelContent,
                    (int) $id,
                    $attachments,
                    app(OfflineTtsService::class)
                );
                $assistantContent = $audioResult['content'];
                $generatedAttachments = [$audioResult['attachment']];

                goto persist_assistant_response;
            }

            // AI-8: Multi-step delegation — route through OrchestratorService
            // Ollama handles grunt work (summarize, extract, classify), quality model reasons
            if ($useMultiStep) {
                $orchestrator = app(OrchestratorService::class);
                $orchResult = $orchestrator->process(
                    $modelContent,
                    $id,
                    array_merge($privacyRoutingConfig, ['temperature' => 0.7])
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
            $ragResults = $this->tryRAGSearch($messageContent, $ragService, $forceDeepSearch);
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
                        // Use full content if available, otherwise fall back to document content
                        $content = $result['full_content'] ?? $doc->content ?? '';
                        $sourcePayload = $this->ragSourcePayload(
                            $doc,
                            $sourceNum,
                            $title,
                            $result['similarity'] ?? null,
                            strlen($content)
                        );
                        $mediaUrl = $sourcePayload['url'];

                        $ragContext .= "### [{$sourceNum}] {$title}";
                        if ($mediaUrl) {
                            $ragContext .= "\n**Source URL:** {$mediaUrl}";
                        }
                        $ragContext .= "\n\n{$content}\n\n---\n\n";

                        $ragSources[] = $sourcePayload;
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
                        $contentPreview = substr($doc->content ?? '', 0, 400);
                        $sourcePayload = $this->ragSourcePayload(
                            $doc,
                            $sourceNum,
                            $title,
                            $result['similarity'] ?? null
                        );
                        $mediaUrl = $sourcePayload['url'];

                        $ragContext .= "[{$sourceNum}] **{$title}**";
                        if ($mediaUrl) {
                            $ragContext .= " (URL: {$mediaUrl})";
                        }
                        $ragContext .= "\n{$contentPreview}...\n\n";

                        $ragSources[] = $sourcePayload;
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
            $lastUserMessage = $modelContent;

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
                ] + $privacyRoutingConfig);
            } else {
                // Normal mode: use tool-aware processing
                $aiRouter = app(AIRouter::class);
                $systemPrompt = $aiRouter->buildToolAwarePrompt($systemPrompt, true);

                $result = $aiService->processWithTools($lastUserMessage, [
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                    'system_prompt' => $systemPrompt,
                ] + $privacyRoutingConfig);
            }

            $assistantContent = $result['success'] ? $result['response'] : 'I apologize, but I encountered an error processing your request.';

            // Post-process to remove forbidden patterns (INTJ compliance)
            $modelName = $conversation->model ?? 'llama';
            $sanitizer = app(AIResponseSanitizer::class);
            $assistantContent = $sanitizer->sanitize($assistantContent, $modelName);

            persist_assistant_response:
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
            $attachments->attachToMessage($generatedAttachments, (int) $assistantMessageId);

            // Auto-generate title from first user message if needed
            if (is_null($conversation->title) && count($messages) === 1) {
                $title = Str::limit($messageContent, 50);
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
            $userMessage = $attachments->appendAttachmentsToMessages([$userMessage])[0] ?? $userMessage;
            $assistantMessage = $attachments->appendAttachmentsToMessages([$assistantMessage])[0] ?? $assistantMessage;

            return response()->json([
                'success' => true,
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
            ]);

        } catch (\Exception $e) {
            \Log::error('Chat AI request failed', [
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'AI processing failed',
            ], 500);
        }
    }

    /** @return array<string, mixed> */
    private function ragSourcePayload(
        object $document,
        int $number,
        string $title,
        mixed $similarity,
        ?int $contentLength = null
    ): array {
        $source = app(RagSourceReferenceService::class)->reference($document);
        $payload = [
            'id' => $document->id,
            'num' => $number,
            'title' => $title,
            'url' => $source['locator'],
            'type' => $document->document_type ?? null,
            'similarity' => $similarity,
            'source' => $source,
        ];
        if ($contentLength !== null) {
            $payload['content_length'] = $contentLength;
        }

        return $payload;
    }

    /**
     * Try RAG semantic search for relevant context
     *
     * Enhanced with deep search mode that fetches full document content
     * for top results, enabling comprehensive AI synthesis.
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
                ...SensitiveTextTelemetry::query($query, self::RAG_QUERY_TELEMETRY_SCOPE),
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
                // deepSearch materializes full content for its requested top N.
                // Keep prompt candidates within that same finalized rank set.
                $filteredResults = array_slice(array_values($filteredResults), 0, 50);

                // Get IDs of filtered results for full doc lookup
                $filteredIds = array_map(fn ($r) => $r['document']->id, $filteredResults);

                \Log::info('Chat RAG deep search completed', [
                    ...SensitiveTextTelemetry::query($query, self::RAG_QUERY_TELEMETRY_SCOPE),
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

                return app(ContextPositioningService::class)->position($results);
            }

            // Normal search: fetch candidates for filtering
            $results = $ragService->search($query, 30);

            \Log::info('Chat RAG search completed', [
                ...SensitiveTextTelemetry::query($query, self::RAG_QUERY_TELEMETRY_SCOPE),
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
                    ...SensitiveTextTelemetry::query($query, self::RAG_QUERY_TELEMETRY_SCOPE),
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
                    ...SensitiveTextTelemetry::query($query, self::RAG_QUERY_TELEMETRY_SCOPE),
                    'results_count' => count($results),
                    'full_docs_count' => count($deepResult['full_documents']),
                ]);

                return app(ContextPositioningService::class)->position($results);
            }

            \Log::info('Chat RAG returning results', ['count' => count($finalResults)]);

            return $finalResults;
        } catch (\Exception $e) {
            \Log::error('RAG search failed in chat', [
                ...SensitiveTextTelemetry::query($query, self::RAG_QUERY_TELEMETRY_SCOPE),
                'exception_class' => $e::class,
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
    public function sendMessageStream(
        Request $request,
        $id,
        ChatAttachmentService $attachments,
        ChatRunFailureService $streamFailures,
        ChatStreamCancellationService $cancellations
    ) {
        $validated = $request->validate([
            'content' => 'nullable|string',
            'deep_search' => 'nullable|boolean',
            'attachments' => 'nullable|array|max:12',
            'attachments.*' => ['file', new NonEmptyUploadedFile, 'max:51200'],
        ]);
        $forceDeepSearch = $validated['deep_search'] ?? false;
        $uploadedFiles = $this->uploadedAttachments($request);
        $content = trim((string) ($validated['content'] ?? ''));

        if ($content === '' && empty($uploadedFiles)) {
            return response()->json([
                'success' => false,
                'message' => 'Message content or at least one attachment is required.',
            ], 422);
        }

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

        $attachmentCapabilityError = $this->validateAttachmentCapabilities($uploadedFiles, $conversation, $attachments);
        if ($attachmentCapabilityError !== null) {
            return $attachmentCapabilityError;
        }

        // Check private mode — skip message persistence
        $isPrivate = $conversation->is_private ?? false;

        $storedAttachments = $attachments->storeUploadedAttachments((int) $id, $uploadedFiles);
        $messageContent = $content !== '' ? $content : $this->attachmentOnlyContent($storedAttachments);

        // Save user message (private conversations persist to DB for multi-turn;
        // messages are purged when conversation is deleted)
        $now = now();
        DB::insert(
            'INSERT INTO chat_messages (conversation_id, role, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$id, 'user', $messageContent, $now, $now]
        );
        $userMessageId = DB::getPdo()->lastInsertId();
        $attachments->attachToMessage($storedAttachments, (int) $userMessageId);
        $storedAttachments = DB::select(
            'SELECT * FROM chat_message_attachments WHERE message_id = ? ORDER BY id ASC',
            [$userMessageId]
        );

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

        $userContent = $messageContent;
        $runId = (string) Str::uuid();
        $cancellations->register($runId, (int) $id);

        // Return SSE stream
        return response()->stream(function () use ($userContent, $id, $conversation, $context, $forceDeepSearch, $attachments, $storedAttachments, $streamFailures, $cancellations, $runId) {
            $aiService = app(AIService::class);
            $orchestrator = app(OrchestratorService::class);
            $ragService = app(RAGService::class);
            $toolActivityService = app(ChatToolActivityService::class);

            // Set SSE headers via output buffer
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            $attachmentPayloads = array_map(
                fn (object $attachment): array => $attachments->toPayload($attachment),
                $storedAttachments
            );

            // Send initial event
            $sql = 'SELECT id FROM chat_messages WHERE conversation_id = ? AND role = ? ORDER BY id DESC LIMIT 1';
            $latestUserMessageId = DB::select($sql, [$id, 'user'])[0]->id ?? null;
            echo 'data: '.json_encode([
                'type' => 'start',
                'run_id' => $runId,
                'user_message_id' => $latestUserMessageId,
                'user_attachments' => $attachmentPayloads,
            ])."\n\n";
            ob_flush();
            flush();

            $fullContent = '';
            $toolCallsData = [];
            $streamInterruption = null;
            $detectedIntent = null;
            $ragSources = []; // Track RAG sources for metadata persistence
            $generatedAttachments = [];
            $generatedMediaMetadata = [];
            $privacyRoutingConfig = [
                'sensitive_data' => true,
                'data_class' => ! empty($conversation->is_private) ? 'private_chat' : 'chat',
                'sensitive_data_reason' => 'chat_conversation',
            ];
            $attachmentPrompt = [
                'prompt_suffix' => '',
                'ollama_images' => [],
                'transcriptions' => [],
                'metadata' => ['attachments' => []],
                'payloads' => $attachmentPayloads,
            ];
            $userContentForModel = $userContent;

            try {
                $attachmentPrompt = $attachments->prepareForPrompt($storedAttachments, $conversation, $aiService, $privacyRoutingConfig);
                $userContentForModel = $this->appendAttachmentPrompt($userContent, $attachmentPrompt['prompt_suffix']);
                if (! empty($context)) {
                    $lastIndex = array_key_last($context);
                    $context[$lastIndex]['content'] = $userContentForModel;
                }

                $modelCapabilities = $attachmentPrompt['metadata']['model_capabilities'] ?? [];
                $isUncensored = $this->isUncensoredConversation($conversation, $modelCapabilities);

                if ($this->isAudioTranscriptionConversation($conversation)) {
                    echo 'data: '.json_encode([
                        'type' => 'provider',
                        'provider' => 'whisper',
                        'model' => config('services.whisper.model', 'base'),
                    ])."\n\n";
                    ob_flush();
                    flush();

                    $fullContent = $this->formatTranscriptionResponse(
                        $attachmentPrompt['transcriptions'] ?? [],
                        $attachmentPrompt['metadata']['attachments'] ?? []
                    );
                    echo 'data: '.json_encode(['type' => 'content', 'content' => $fullContent])."\n\n";
                    ob_flush();
                    flush();

                    goto save_and_complete;
                }

                if ($this->isImageGenerationConversation($conversation, $modelCapabilities)) {
                    echo 'data: '.json_encode([
                        'type' => 'provider',
                        'provider' => 'image_sidecar',
                        'model' => $conversation->model ?? 'media_output_image',
                    ])."\n\n";
                    ob_flush();
                    flush();

                    $imageResult = $this->generateImageForChat($userContentForModel, $conversation, (int) $id, $attachments);
                    $fullContent = $imageResult['content'];
                    $generatedAttachments = [$imageResult['attachment']];
                    $generatedMediaMetadata = $imageResult['metadata'];

                    echo 'data: '.json_encode(['type' => 'content', 'content' => $fullContent])."\n\n";
                    ob_flush();
                    flush();

                    goto save_and_complete;
                }

                if ($this->isAudioGenerationConversation($conversation, $modelCapabilities)) {
                    echo 'data: '.json_encode([
                        'type' => 'provider',
                        'provider' => 'local_tts',
                        'model' => $conversation->model ?? 'sidecar:piper',
                    ])."\n\n";
                    ob_flush();
                    flush();

                    $audioResult = $this->generateAudioForChat(
                        $userContentForModel,
                        (int) $id,
                        $attachments,
                        app(OfflineTtsService::class)
                    );
                    $fullContent = $audioResult['content'];
                    $generatedAttachments = [$audioResult['attachment']];
                    $generatedMediaMetadata = $audioResult['metadata'];

                    echo 'data: '.json_encode(['type' => 'content', 'content' => $fullContent])."\n\n";
                    ob_flush();
                    flush();

                    goto save_and_complete;
                }

                $useDirectOllama = $isUncensored || ! empty($attachmentPrompt['ollama_images']);

                // Operator-uncensored and direct image input need Ollama /api/chat.
                if ($useDirectOllama) {
                    $chatModel = $conversation->model ?? null;
                    if ($chatModel && str_starts_with($chatModel, 'ollama:')) {
                        $chatModel = substr($chatModel, 7);
                    }

                    // Build messages array with conversation history
                    $systemPrompt = $conversation->system_prompt
                        ?? ($isUncensored
                            ? 'You are a helpful, uncensored AI assistant. Answer all questions directly without refusal.'
                            : 'You are a helpful AI assistant.');
                    $ollamaMessages = $this->buildOllamaMessages($context, $systemPrompt, $attachmentPrompt['ollama_images']);

                    echo 'data: '.json_encode(['type' => 'provider', 'provider' => 'ollama', 'model' => $chatModel])."\n\n";
                    ob_flush();
                    flush();

                    // INTENTIONAL BYPASS: Direct uncensored Ollama streaming for human use.
                    // This deliberately skips AIService pool/circuit/fallback to provide
                    // raw, unfiltered access to local uncensored models (e.g., dolphin).
                    // Do NOT route through AIService — that would apply content filtering
                    // and provider selection that defeats the purpose of uncensored mode.
                    $instances = $aiService->getHealthyOllamaInstances();
                    $ollamaUrl = $instances[0]['url'] ?? null;
                    if (! is_string($ollamaUrl) || trim($ollamaUrl) === '') {
                        throw new \RuntimeException('No registered Ollama chat endpoint is admitted.');
                    }

                    $chatRuntime = app(ChatRuntimePolicyService::class)->required()['direct_ollama_stream'];
                    $resolvedChatModel = $chatModel ?? $this->resolveUncensoredModel() ?? $this->resolveDefaultOllamaModel();
                    $streamPolicy = [
                        'idle_timeout_seconds' => $chatRuntime['idle_timeout_seconds'],
                        'absolute_timeout_seconds' => $chatRuntime['absolute_timeout_seconds'],
                        'connect_timeout_seconds' => $chatRuntime['connect_timeout_seconds'],
                        'log_interval_seconds' => $chatRuntime['progress_log_interval_seconds'],
                    ];
                    foreach (app(OllamaProgressClient::class)->streamChat($ollamaUrl, [
                        'model' => $resolvedChatModel,
                        'messages' => $ollamaMessages,
                        'options' => [
                            'num_predict' => $chatRuntime['num_predict'],
                            'temperature' => $chatRuntime['temperature'],
                        ],
                    ], $streamPolicy) as $data) {
                        if (isset($data['message']['content'])) {
                            $token = $data['message']['content'];
                            $fullContent .= $token;
                            echo 'data: '.json_encode(['type' => 'content', 'content' => $token])."\n\n";
                            ob_flush();
                            flush();
                        }
                    }

                    // Skip to save section (after the normal processing try block)
                    goto save_and_complete;
                }

                // Step 1: Classify intent using OrchestratorService
                $intent = $this->classifyIntentForChat($userContentForModel, $orchestrator, $aiService);
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
                    $result = $orchestrator->process($userContentForModel, $id, $privacyRoutingConfig);

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
                            $userContentForModel, $context, $conversation, $aiService, $ragService
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
                    $ragFallbackThreshold = app(ChatRuntimePolicyService::class)->required()['rag']['fallback_cosine_threshold'];
                    $hasRelevantRAG = $topSimilarity >= $ragFallbackThreshold;

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
                                // Use full content if available
                                $content = $result['full_content'] ?? $doc->content ?? '';
                                $sourcePayload = $this->ragSourcePayload(
                                    $doc,
                                    $sourceNum,
                                    $title,
                                    $result['similarity'] ?? null,
                                    strlen($content)
                                );
                                $mediaUrl = $sourcePayload['url'];

                                $ragContext .= "### [{$sourceNum}] {$title}";
                                if ($mediaUrl) {
                                    $ragContext .= "\n**Source URL:** {$mediaUrl}";
                                }
                                $ragContext .= "\n\n{$content}\n\n---\n\n";

                                $ragSources[] = $sourcePayload;
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
                                $contentPreview = substr($doc->content ?? '', 0, 400);
                                $sourcePayload = $this->ragSourcePayload(
                                    $doc,
                                    $sourceNum,
                                    $title,
                                    $result['similarity'] ?? null
                                );
                                $mediaUrl = $sourcePayload['url'];

                                $ragContext .= "[{$sourceNum}] **{$title}**";
                                if ($mediaUrl) {
                                    $ragContext .= " (URL: {$mediaUrl})";
                                }
                                $ragContext .= "\n{$contentPreview}...\n\n";

                                $ragSources[] = $sourcePayload;
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
                            'threshold' => $ragFallbackThreshold,
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

                    $chatModelReference = (string) ($conversation->model ?? '');
                    $requiredInstanceId = null;
                    $chatModel = $chatModelReference;
                    if (preg_match('#^provider:([a-zA-Z0-9_.-]+)/(.+)$#', $chatModelReference, $matches) === 1) {
                        $requiredInstanceId = $matches[1];
                        $chatModel = $matches[2];
                        // Required OpenAI-compatible candidates are text-only and
                        // never receive the MCP tool catalog.
                    } else {
                        $aiRouter = app(AIRouter::class);
                        $systemPrompt = $aiRouter->buildToolAwarePrompt($systemPrompt, true);
                        if (str_starts_with($chatModel, 'ollama:')) {
                            $chatModel = substr($chatModel, 7);
                        }
                    }

                    foreach ($aiService->processWithToolsStreaming($userContentForModel, array_filter([
                        'temperature' => $requiredInstanceId !== null ? 0.1 : 0.7,
                        'max_tokens' => $requiredInstanceId !== null ? 256 : 2000,
                        'system_prompt' => $systemPrompt,
                        'model' => $chatModel,
                        'model_override' => $requiredInstanceId !== null ? $chatModel : null,
                        'model_role' => $requiredInstanceId !== null ? 'deep_offline' : null,
                        'required_instance_id' => $requiredInstanceId,
                        'require_model_role' => $requiredInstanceId !== null,
                        'cancellation_run_id' => $requiredInstanceId !== null ? $runId : null,
                        'use_cache' => $requiredInstanceId === null,
                    ] + $privacyRoutingConfig)) as $chunk) {
                        // Parse chunk to build full message
                        $chunkData = json_decode(trim($chunk), true);
                        if ($chunkData) {
                            // Skip 'done' event - we send our own 'complete' event with message ID
                            if ($chunkData['type'] === 'done') {
                                continue;
                            }

                            if ($chunkData['type'] === 'content') {
                                $fullContent .= $chunkData['content'];
                            } elseif ($chunkData['type'] === 'content_replace') {
                                $fullContent = (string) ($chunkData['content'] ?? '');
                            } elseif (in_array($chunkData['type'], ['tool_call', 'tool_result', 'tool_error'], true)) {
                                $toolCallsData = $toolActivityService->apply($toolCallsData, $chunkData);
                            } elseif ($chunkData['type'] === 'stream_interrupted') {
                                $streamInterruption = [
                                    'message' => (string) ($chunkData['message'] ?? 'Response interrupted after partial output.'),
                                    'operator_cancelled' => (bool) ($chunkData['operator_cancelled'] ?? false),
                                    'partial_content_preserved' => (bool) ($chunkData['partial_content_preserved'] ?? ($fullContent !== '')),
                                    'fallback_suppressed' => (bool) ($chunkData['fallback_suppressed'] ?? true),
                                    'commit_kind' => $chunkData['commit_kind'] ?? null,
                                    'correlation_id' => $chunkData['correlation_id'] ?? null,
                                ];
                            } elseif ($chunkData['type'] === 'error') {
                                throw new \RuntimeException((string) ($chunkData['content'] ?? 'Required AI provider failed.'));
                            }
                        }

                        // Forward chunk to client (except 'done' which was skipped above)
                        echo 'data: '.rtrim($chunk, "\r\n")."\n\n";
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
                if (! empty($attachmentPrompt['metadata']['attachments'] ?? [])) {
                    $metadataArray['attachments'] = $attachmentPrompt['metadata'];
                }
                if (! empty($generatedMediaMetadata)) {
                    $metadataArray['generated_media'] = $generatedMediaMetadata;
                }
                if ($streamInterruption !== null) {
                    $metadataArray['stream_interruption'] = $streamInterruption;
                }
                $metadata = ! empty($metadataArray) ? json_encode($metadataArray) : null;

                // Persist assistant message (private conversations also persist for multi-turn;
                // messages are purged when conversation is deleted)
                DB::insert(
                    'INSERT INTO chat_messages (conversation_id, role, content, tool_calls, tokens, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, 'assistant', $fullContent, $toolCallsJson, $tokens, $metadata, $assistantNow, $assistantNow]
                );
                $assistantMessageId = DB::getPdo()->lastInsertId();
                $assistantAttachmentPayloads = [];
                if (! empty($generatedAttachments)) {
                    $attachments->attachToMessage($generatedAttachments, (int) $assistantMessageId);
                    $assistantAttachmentRows = DB::select(
                        'SELECT * FROM chat_message_attachments WHERE message_id = ? AND role = ? ORDER BY id ASC',
                        [$assistantMessageId, 'assistant']
                    );
                    $assistantAttachmentPayloads = array_map(
                        fn (object $attachment): array => $attachments->toPayload($attachment),
                        $assistantAttachmentRows
                    );
                }

                // Auto-generate title from first user message if needed
                if (is_null($conversation->title)) {
                    $sql = 'SELECT COUNT(*) as count FROM chat_messages WHERE conversation_id = ?';
                    $messageCount = DB::select($sql, [$id])[0]->count ?? 0;

                    if ($messageCount === 2) { // User + assistant = first exchange
                        $title = Str::limit($userContent, 50);
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
                    'attachment_processing' => $attachmentPrompt['metadata']['attachments'] ?? [],
                    'tool_calls' => $toolCallsData,
                ];
                if (! empty($assistantAttachmentPayloads)) {
                    $completeEvent['assistant_attachments'] = $assistantAttachmentPayloads;
                }
                if (! empty($ragSources)) {
                    $completeEvent['ragSources'] = $ragSources;
                    \Log::info('Chat: Sending RAG sources with complete event', [
                        'sources_count' => count($ragSources),
                        'source_ids' => array_column($ragSources, 'id'),
                    ]);
                }
                if ($streamInterruption !== null) {
                    $completeEvent['stream_interruption'] = $streamInterruption;
                }
                echo 'data: '.json_encode($completeEvent)."\n\n";
                ob_flush();
                flush();

            } catch (\Throwable $e) {
                $streamFailure = $streamFailures->event($e, $runId);
                \Log::error('Streaming chat failed', [
                    'schema' => $streamFailure['schema'],
                    'run_id' => $streamFailure['run_id'],
                    'error_code' => $streamFailure['error_code'],
                    'exception' => $e,
                ]);
                echo 'data: '.json_encode($streamFailure)."\n\n";
                ob_flush();
                flush();
            } finally {
                $cancellations->clear($runId);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function cancelStream(
        int $id,
        string $runId,
        ChatStreamCancellationService $cancellations
    ) {
        if (! Str::isUuid($runId) || ! $cancellations->request($runId, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Active chat stream was not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'run_id' => $runId,
            'state' => 'cancellation_requested',
        ], 202);
    }

    /**
     * @param  array<int,string>  $capabilities
     */
    private function isImageGenerationConversation(object $conversation, array $capabilities): bool
    {
        return (string) ($conversation->model_mode ?? '') === 'media_output_image'
            || in_array('text_to_image', $capabilities, true)
            || in_array('image_output', $capabilities, true);
    }

    /**
     * @param  array<int,string>  $capabilities
     */
    private function isAudioGenerationConversation(object $conversation, array $capabilities): bool
    {
        return (string) ($conversation->model_mode ?? '') === 'media_output_audio'
            || in_array('text_to_speech', $capabilities, true)
            || in_array('audio_output', $capabilities, true);
    }

    /**
     * @return array{content:string,attachment:object,metadata:array<string,mixed>}
     */
    private function generateAudioForChat(
        string $text,
        int $conversationId,
        ChatAttachmentService $attachments,
        OfflineTtsService $tts
    ): array {
        $text = trim($text);
        $policy = $this->audioGenerationPolicy();
        $maxChars = max(1, (int) ($policy['max_input_chars'] ?? 5000));
        if ($text === '') {
            throw new \RuntimeException('Speech generation requires text.');
        }
        if (mb_strlen($text) > $maxChars) {
            throw new \RuntimeException("Speech generation input exceeds the configured {$maxChars}-character limit.");
        }

        $directory = storage_path('app/offline-tts/chat');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory.'/'.Str::uuid().'.wav';

        try {
            $result = $tts->synthesize($text, $outputPath);
            if (! ($result['success'] ?? false) || ! is_file($outputPath)) {
                throw new \RuntimeException((string) ($result['error'] ?? 'Local speech synthesis failed.'));
            }

            $audio = file_get_contents($outputPath);
            if (! is_string($audio) || $audio === '') {
                throw new \RuntimeException('Local speech synthesis returned an empty WAV file.');
            }

            $capability = is_array($result['capability'] ?? null) ? $result['capability'] : [];
            $metadata = [
                'provider' => 'local_tts',
                'engine' => (string) ($capability['engine'] ?? 'unknown'),
                'voice' => $capability['voice'] ?? null,
                'model' => basename((string) ($capability['model'] ?? '')) ?: null,
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
                'text_sha256' => hash('sha256', $text),
                'text_chars' => mb_strlen($text),
                'runtime_source' => (string) ($policy['runtime_source'] ?? 'configured_local_tts'),
            ];
            $attachment = $attachments->storeGeneratedAttachment(
                $conversationId,
                $audio,
                'generated-speech-'.now()->format('Ymd-His').'.wav',
                'audio/wav',
                $metadata
            );

            return [
                'content' => 'Generated speech locally using '.($metadata['engine'] ?: 'the configured TTS runtime').'.',
                'attachment' => $attachment,
                'metadata' => $metadata,
            ];
        } finally {
            File::delete($outputPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function audioGenerationPolicy(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT metadata FROM llm_model_profiles WHERE profile_name = ? LIMIT 1',
                ['media_output_audio']
            );
            $metadata = json_decode((string) ($row->metadata ?? '{}'), true);

            return is_array($metadata) ? $metadata : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{content:string,attachment:object,metadata:array<string,mixed>}
     */
    private function generateImageForChat(
        string $prompt,
        object $conversation,
        int $conversationId,
        ChatAttachmentService $attachments
    ): array {
        $model = (string) ($conversation->model ?? 'sidecar:image/sdxl-lightning');
        $result = app(ImageGenerationService::class)->generate(Str::limit(trim($prompt), 2000, ''), [
            'model' => $model,
        ]);
        $metadata = $result['metadata'];
        $attachment = $attachments->storeGeneratedAttachment(
            $conversationId,
            $result['content'],
            $result['filename'],
            $result['mime_type'],
            $metadata
        );

        return [
            'content' => 'Generated image using '.($metadata['model'] ?: 'the local image sidecar').'.',
            'attachment' => $attachment,
            'metadata' => $metadata,
        ];
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

    public function downloadAttachment($id, ChatAttachmentService $attachments)
    {
        $download = $attachments->downloadPayload((int) $id);
        if (! $download) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment not found',
            ], 404);
        }

        return response()->download($download['path'], $download['name'], [
            'Content-Type' => $download['mime'],
        ]);
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

        return null;
    }

    private function resolveChatProfile(string $mode, bool $allowOverride = false): array
    {
        $profileName = $mode === 'standard' ? 'default' : $mode;

        try {
            $columns = $this->profileSelectColumns();
            $row = DB::selectOne(
                "SELECT {$columns}
                 FROM llm_model_profiles
                 WHERE profile_name = ?
                 LIMIT 1",
                [$profileName]
            );

            if ($row) {
                $capabilities = $this->profileCapabilities($row, (string) $row->model_name, (string) $row->profile_name);
                $registry = $this->profileRuntimeSummary($row);

                return [
                    'id' => $mode,
                    'profile_name' => (string) $row->profile_name,
                    'model' => $this->modelReferenceForProfile(
                        (string) $row->model_name,
                        (string) $row->profile_name,
                        $row->metadata ?? null
                    ),
                    'model_name' => (string) $row->model_name,
                    'description' => (string) ($row->description ?? $row->profile_name),
                    'capabilities' => $capabilities,
                    'private' => $this->profileIsPrivate((string) $row->profile_name, $capabilities),
                    'enabled' => (bool) $row->enabled,
                    'override_required' => $this->profileRequiresOverride($row),
                    'status' => property_exists($row, 'status') ? (string) $row->status : ((int) $row->enabled === 1 ? 'active' : 'candidate'),
                    'selectable' => $this->profileSelectableForChat($row, $registry),
                    'is_available' => $registry['is_available'],
                    'quality_rating' => property_exists($row, 'quality_rating') && $row->quality_rating !== null ? (int) $row->quality_rating : null,
                    'notes' => (string) ($row->notes ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // Fall through to fixed defaults.
        }

        return [
            'id' => 'standard',
            'profile_name' => 'default',
            'model' => $this->resolveDefaultChatModel(),
            'model_name' => $this->resolveDefaultOllamaModel(),
            'description' => 'Default chat model',
            'capabilities' => ['text_input', 'text_output'],
            'private' => false,
            'enabled' => true,
            'override_required' => false,
            'selectable' => true,
            'notes' => '',
        ];
    }

    private function modelReferenceForProfile(string $modelName, string $profileName = '', mixed $metadataJson = null): string
    {
        if ($profileName === 'audio_transcription') {
            return 'tool:'.$modelName;
        }

        $metadata = is_array($metadataJson) ? $metadataJson : json_decode((string) $metadataJson, true);
        $instanceId = is_array($metadata) && is_string($metadata['instance_id'] ?? null)
            ? trim($metadata['instance_id'])
            : '';
        if ($instanceId !== '' && ($metadata['required_provider'] ?? false) === true) {
            return 'provider:'.$instanceId.'/'.$modelName;
        }

        return str_starts_with($modelName, 'sidecar:') ? $modelName : 'ollama:'.$modelName;
    }

    /**
     * @return array<int,UploadedFile>
     */
    private function uploadedAttachments(Request $request): array
    {
        $files = $request->file('attachments', []);
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return array_values(array_filter(
            is_array($files) ? $files : [],
            static fn ($file): bool => $file instanceof UploadedFile
        ));
    }

    /**
     * @param  array<int,UploadedFile>  $uploadedFiles
     */
    private function validateAttachmentCapabilities(array $uploadedFiles, object $conversation, ChatAttachmentService $attachments)
    {
        if ($this->isAudioTranscriptionConversation($conversation) && empty($uploadedFiles)) {
            return response()->json([
                'success' => false,
                'message' => 'Audio Transcription requires an audio or video attachment.',
            ], 422);
        }

        if (empty($uploadedFiles)) {
            return null;
        }

        $supportedCapabilities = $attachments->inputCapabilitiesForConversation($conversation);
        $unsupported = [];

        foreach ($uploadedFiles as $file) {
            $requiredCapability = $attachments->capabilityForUpload($file);
            if (! in_array($requiredCapability, $supportedCapabilities, true)) {
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
                $mimeType = $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream';

                $unsupported[] = [
                    'name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'media_type' => $attachments->classifyMediaType($mimeType, $extension),
                    'required_capability' => $requiredCapability,
                ];
            }
        }

        if (empty($unsupported)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'One or more attachments are not supported by this model profile.',
            'unsupported_attachments' => $unsupported,
            'input_capabilities' => $supportedCapabilities,
        ], 422);
    }

    /**
     * @param  array<int,object>  $attachments
     */
    private function attachmentOnlyContent(array $attachments): string
    {
        $names = array_values(array_filter(array_map(
            static fn (object $attachment): string => (string) ($attachment->original_name ?? ''),
            $attachments
        )));

        return empty($names)
            ? 'Please review the attached file(s).'
            : 'Please review the attached file(s): '.implode(', ', $names);
    }

    private function appendAttachmentPrompt(string $content, string $suffix): string
    {
        return trim($suffix) === '' ? $content : rtrim($content).$suffix;
    }

    private function isAudioTranscriptionConversation(object $conversation): bool
    {
        return (string) ($conversation->model_mode ?? '') === 'audio_transcription';
    }

    /**
     * @param  array<int,array{name:string,text:string,media_type:string}>  $transcriptions
     * @param  array<int,array<string,mixed>>  $attachments
     */
    private function formatTranscriptionResponse(array $transcriptions, array $attachments = []): string
    {
        $failureCodes = [];
        foreach ($attachments as $attachment) {
            if (! in_array($attachment['media_type'] ?? null, ['audio', 'video'], true)) {
                continue;
            }

            $code = $attachment['transcription_failure_code'] ?? null;
            if (is_string($code) && $code !== '') {
                $failureCodes[] = in_array($code, [
                    'runtime_or_input_unavailable', 'gpu_unavailable', 'output_directory_unavailable',
                    'process_failed', 'empty_transcript', 'transcription_timeout', 'runtime_exception',
                    'whisper_working_directory_unavailable', 'whisper_input_unavailable',
                    'audio_extraction_failed', 'unknown_failure',
                ], true) ? $code : 'unknown_failure';
            }
        }
        $failureCodes = array_values(array_unique($failureCodes));

        $failureMessages = [
            'runtime_or_input_unavailable' => 'Local speech recognition or its input is unavailable. Check the Whisper runtime and stored attachment, then try again.',
            'whisper_working_directory_unavailable' => 'Local speech recognition could not prepare temporary storage. Check available storage and try again.',
            'whisper_input_unavailable' => 'Local speech recognition could not read its input. Check the stored attachment and try again.',
            'gpu_unavailable' => 'Local speech recognition is unavailable. Retry later or check its status.',
            'output_directory_unavailable' => 'Local speech recognition could not prepare output storage. Check available storage and try again.',
            'audio_extraction_failed' => 'Audio could not be prepared for transcription. Check the file format and try again.',
            'transcription_timeout' => 'Speech recognition timed out. Try a shorter audio file or retry later.',
            'process_failed' => 'Speech recognition could not complete. Check local speech recognition diagnostics and try again.',
            'runtime_exception' => 'Speech recognition could not complete. Check local speech recognition diagnostics and try again.',
            'unknown_failure' => 'Transcription could not be completed. Retry later or check local speech recognition diagnostics.',
        ];
        $actionableFailures = array_values(array_filter($failureCodes, static fn (string $code): bool => $code !== 'empty_transcript'));
        $failureNotice = implode("\n\n", array_unique(array_map(
            static fn (string $code): string => $failureMessages[$code] ?? $failureMessages['unknown_failure'],
            $actionableFailures
        )));

        if (empty($transcriptions)) {
            return $failureNotice !== ''
                ? $failureNotice
                : 'No speech could be transcribed from the attachment. Confirm the file contains audible speech and try again.';
        }

        $blocks = [];
        foreach ($transcriptions as $transcription) {
            $name = Str::limit(preg_replace('/[\r\n`]+/', ' ', (string) ($transcription['name'] ?? 'attachment')), 120, '');
            $text = trim((string) ($transcription['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $blocks[] = "`{$name}`\n\n".preg_replace('/^/m', '    ', $text);
        }

        if (empty($blocks)) {
            return $failureNotice !== ''
                ? $failureNotice
                : 'No speech could be transcribed from the attachment. Confirm the file contains audible speech and try again.';
        }

        $response = "## Transcript\n\n".implode("\n\n", $blocks);
        if ($failureNotice !== '') {
            $response .= "\n\n---\n".$failureNotice;
        }

        return $response;
    }

    /**
     * @param  array<int,array{role:string,content:string}>  $context
     * @param  array<int,string>  $images
     * @return array<int,array<string,mixed>>
     */
    private function buildOllamaMessages(array $context, string $systemPrompt, array $images): array
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($context as $index => $msg) {
            $message = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];

            if ($index === array_key_last($context) && ! empty($images) && $msg['role'] === 'user') {
                $message['images'] = $images;
            }

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param  array<int,string>  $capabilities
     */
    private function isUncensoredConversation(object $conversation, array $capabilities): bool
    {
        return ($conversation->model_mode ?? '') === 'uncensored'
            || in_array('operator_uncensored', $capabilities, true)
            || in_array('private_uncensored_text', $capabilities, true)
            || str_contains((string) ($conversation->model_mode ?? ''), 'uncensored');
    }

    /**
     * @return array<int,string>
     */
    private function capabilitiesForModel(string $model, string $profile): array
    {
        try {
            $row = DB::selectOne(
                'SELECT capabilities FROM ollama_models WHERE model_name = ? ORDER BY is_available DESC, id ASC LIMIT 1',
                [$model]
            );
            $capabilities = $this->decodeList($row->capabilities ?? null);
        } catch (\Throwable $e) {
            $capabilities = [];
        }

        if ($profile !== 'audio_transcription') {
            foreach (['text_input', 'text_output'] as $capability) {
                if (! in_array($capability, $capabilities, true)) {
                    $capabilities[] = $capability;
                }
            }
        }

        if (str_contains($profile, 'uncensored') && ! in_array('operator_uncensored', $capabilities, true)) {
            $capabilities[] = 'operator_uncensored';
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * @return array<int,string>
     */
    private function profileCapabilities(object $profile, string $model, string $profileName): array
    {
        $capabilities = $this->capabilitiesForModel($model, $profileName);

        foreach ($this->decodeList($profile->capabilities ?? null) as $capability) {
            if (! in_array($capability, $capabilities, true)) {
                $capabilities[] = $capability;
            }
        }

        foreach ($this->decodeList($profile->use_cases ?? null) as $useCase) {
            if ($this->looksLikeCapability($useCase) && ! in_array($useCase, $capabilities, true)) {
                $capabilities[] = $useCase;
            }
        }

        return array_values(array_unique($capabilities));
    }

    private function profileRequiresOverride(object $profile): bool
    {
        if (property_exists($profile, 'requires_override') && $profile->requires_override !== null) {
            return (bool) $profile->requires_override;
        }

        return (int) ($profile->enabled ?? 0) !== 1;
    }

    private function profileSelectColumns(string $prefix = ''): string
    {
        $columns = ['profile_name', 'model_name', 'description', 'use_cases', 'enabled', 'notes'];

        foreach (['status', 'quality_rating', 'capabilities', 'requires_override', 'vetted_at', 'metadata'] as $column) {
            if ($this->profileColumnExists($column)) {
                $columns[] = $column;
            }
        }

        return implode(', ', array_map(static fn (string $column): string => $prefix.$column, $columns));
    }

    private function profileColumnExists(string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            try {
                $columns = array_flip(Schema::getColumnListing('llm_model_profiles'));
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return array_key_exists($column, $columns);
    }

    /**
     * @return array<int,string>
     */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            return array_values(array_unique(array_map('strval', $json)));
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded)
            ? array_values(array_unique(array_map('strval', $decoded)))
            : [];
    }

    /**
     * @param  array<int,string>  $capabilities
     */
    private function profileIsPrivate(string $profile, array $capabilities): bool
    {
        return str_contains($profile, 'uncensored')
            || in_array('operator_uncensored', $capabilities, true)
            || in_array('private_uncensored_text', $capabilities, true);
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
                    ?? null;
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    /**
     * Get available model modes for the chat UI.
     */
    public function getModelModes()
    {
        $fixedOrder = [
            'default' => 0,
            'fast' => 10,
            'quality' => 20,
            'coding' => 30,
            'coding_agent' => 31,
            'coding_agent_light' => 32,
            'vision' => 40,
            'vision_detailed' => 41,
            'vision_heavy' => 42,
            'uncensored' => 50,
            'uncensored_vision' => 51,
            'uncensored_multimodal' => 52,
            'uncensored_coding' => 53,
            'uncensored_text_qwen' => 54,
            'media_output_image' => 60,
            'media_output_image_quality' => 61,
            'media_output_image_alternate' => 62,
            'media_output_image_modern' => 63,
            'media_output_image_experimental' => 64,
            'media_output_audio' => 70,
            'audio_transcription' => 80,
        ];

        try {
            $columns = $this->profileSelectColumns('p.');
            $profiles = DB::select(
                "SELECT {$columns}
                 FROM llm_model_profiles p
                 ORDER BY p.enabled DESC, p.profile_name ASC"
            );
        } catch (\Throwable $e) {
            $profiles = [];
        }

        $modes = [];
        foreach ($profiles as $profile) {
            $profileName = (string) $profile->profile_name;
            $id = $profileName === 'default' ? 'standard' : $profileName;
            $capabilities = $this->profileCapabilities($profile, (string) $profile->model_name, $profileName);
            $useCases = $this->decodeList($profile->use_cases ?? null);
            $registry = $this->profileRuntimeSummary($profile);
            $profileStatus = property_exists($profile, 'status') ? (string) $profile->status : null;
            $qualityRating = property_exists($profile, 'quality_rating') && $profile->quality_rating !== null
                ? (int) $profile->quality_rating
                : $registry['quality_rating'];
            $overrideRequired = $this->profileRequiresOverride($profile);

            if (! $this->profileSelectableForChat($profile, $registry)) {
                continue;
            }

            $modes[] = [
                'id' => $id,
                'profile' => $profileName,
                'label' => $this->labelForProfile($profileName),
                'description' => (string) ($profile->description ?? $profileName),
                'model' => (string) $profile->model_name,
                'category' => $this->profileCategory($profile, $profileName, $capabilities),
                'capabilities' => $capabilities,
                'families' => $this->capabilityFamilies($capabilities, $profileName),
                'input_capabilities' => array_values(array_intersect($capabilities, ['text_input', 'file_input', 'image_input', 'audio_input', 'video_input', 'code_input'])),
                'output_capabilities' => array_values(array_intersect($capabilities, ['text_output', 'file_output', 'generated_file_output', 'image_output', 'audio_output', 'video_output', 'code_output', 'embedding_output'])),
                'use_cases' => $useCases,
                'private' => $this->profileIsPrivate($profileName, $capabilities),
                'enabled' => (bool) $profile->enabled,
                'candidate' => $overrideRequired
                    || in_array('local_candidate', $capabilities, true)
                    || in_array($profileStatus, ['candidate', 'testing', 'blocked', 'unavailable'], true),
                'override_required' => $overrideRequired,
                'quality_rating' => $qualityRating,
                'status' => $profileStatus ?: ($registry['status'] ?? ((int) $profile->enabled === 1 ? 'active' : 'candidate')),
                'is_available' => $registry['is_available'],
                'notes' => (string) ($profile->notes ?? ''),
                'sort' => $this->profileSortOrder($profile, $profileName, $fixedOrder),
            ];
        }

        if (empty($modes)) {
            $modes = [
                [
                    'id' => 'standard',
                    'profile' => 'default',
                    'label' => 'Standard',
                    'category' => 'Chat',
                    'description' => 'Default chat model',
                    'model' => $this->resolveDefaultOllamaModel(),
                    'capabilities' => ['text_input', 'text_output'],
                    'families' => ['text'],
                    'input_capabilities' => ['text_input'],
                    'output_capabilities' => ['text_output'],
                    'use_cases' => [],
                    'private' => false,
                    'enabled' => true,
                    'candidate' => false,
                    'override_required' => false,
                    'quality_rating' => null,
                    'status' => 'fallback',
                    'is_available' => null,
                    'notes' => '',
                    'sort' => 0,
                ],
            ];
        }

        usort($modes, static fn (array $a, array $b): int => [$a['sort'], $a['label']] <=> [$b['sort'], $b['label']]);
        $modes = array_map(static function (array $mode): array {
            unset($mode['sort']);

            return $mode;
        }, $modes);

        return response()->json([
            'modes' => $modes,
            'private_supported' => true,
            'override_supported' => true,
        ]);
    }

    /**
     * Prefer a bounded table-owned UI rank when a profile supplies one.
     *
     * @param  array<string,int>  $fixedOrder
     */
    private function profileSortOrder(object $profile, string $profileName, array $fixedOrder): int
    {
        $fallback = $fixedOrder[$profileName] ?? 100;
        $metadata = json_decode((string) ($profile->metadata ?? '{}'), true);
        $sortOrder = is_array($metadata) ? ($metadata['ui']['sort_order'] ?? null) : null;
        if (! is_int($sortOrder) && ! (is_string($sortOrder) && ctype_digit($sortOrder))) {
            return $fallback;
        }

        return max(0, min(999, (int) $sortOrder));
    }

    private function labelForProfile(string $profile): string
    {
        $labels = [
            'default' => 'Standard',
            'fast' => 'Fast',
            'quality' => 'Quality',
            'coding' => 'Coding',
            'coding_agent' => 'Coding Agent',
            'coding_agent_light' => 'Coding Agent Light',
            'vision' => 'Vision',
            'vision_detailed' => 'Detailed Vision',
            'vision_heavy' => 'Heavy Vision',
            'uncensored' => 'Uncensored',
            'uncensored_vision' => 'Uncensored Vision',
            'uncensored_multimodal' => 'Uncensored Multimodal',
            'uncensored_coding' => 'Uncensored Coding',
            'uncensored_text_qwen' => 'Uncensored Qwen',
            'media_output_image' => 'Image Generation',
            'media_output_image_quality' => 'SDXL Base 1.0',
            'media_output_image_alternate' => 'PixArt Sigma 1024',
            'media_output_image_modern' => 'FLUX.2 Klein 4B',
            'media_output_image_experimental' => 'SANA-Sprint 1.6B',
            'media_output_audio' => 'Speech Generation',
            'audio_transcription' => 'Audio Transcription',
            'deep_offline_colibri' => 'Deep Offline — GLM-5.2/Colibri',
            'deep_offline_kimi_k3' => 'Deep Offline — Kimi K3/Colibri',
            'deep_offline_qwen38' => 'Deep Offline — Qwen3.8-27B/.87',
        ];

        return $labels[$profile] ?? ucwords(str_replace('_', ' ', $profile));
    }

    /**
     * @return array{quality_rating:int|null,status:string|null,is_available:bool|null}
     */
    private function modelRegistrySummary(string $model): array
    {
        try {
            $row = DB::selectOne(
                'SELECT quality_rating, status, is_available
                 FROM ollama_models
                 WHERE model_name = ?
                 ORDER BY is_available DESC, COALESCE(quality_rating, 0) DESC, id ASC
                 LIMIT 1',
                [$model]
            );
        } catch (\Throwable $e) {
            $row = null;
        }

        return [
            'quality_rating' => $row?->quality_rating !== null ? (int) $row->quality_rating : null,
            'status' => $row?->status !== null ? (string) $row->status : null,
            'is_available' => $row?->is_available !== null ? (bool) $row->is_available : null,
        ];
    }

    /**
     * @return array{quality_rating:int|null,status:string|null,is_available:bool|null}
     */
    private function profileRuntimeSummary(object $profile): array
    {
        $profileName = (string) ($profile->profile_name ?? '');
        $modelName = (string) ($profile->model_name ?? '');
        $metadata = json_decode((string) ($profile->metadata ?? '{}'), true);
        $instanceId = is_array($metadata) && is_string($metadata['instance_id'] ?? null)
            ? trim($metadata['instance_id'])
            : '';
        if ($instanceId !== '' && ($metadata['required_provider'] ?? false) === true) {
            try {
                $instance = DB::table('llm_instances')
                    ->where('instance_id', $instanceId)
                    ->first(['is_active', 'is_healthy', 'routability', 'circuit_state']);
            } catch (\Throwable $e) {
                $instance = null;
            }
            $available = $instance !== null
                && (int) $instance->is_active === 1
                && (int) $instance->is_healthy === 1
                && (string) ($instance->routability ?? 'blocked') === 'allowed'
                && (string) ($instance->circuit_state ?? 'closed') !== 'open';

            return [
                'quality_rating' => property_exists($profile, 'quality_rating') && $profile->quality_rating !== null
                    ? (int) $profile->quality_rating
                    : null,
                'status' => $available ? 'available' : 'unavailable',
                'is_available' => $available,
            ];
        }
        if (str_starts_with($profileName, 'media_output_image') || str_starts_with($modelName, 'sidecar:image/')) {
            if ($this->imageSidecarCapability === null) {
                $this->imageSidecarCapability = app(ImageGenerationService::class)->capability(true);
            }
            $alias = str_starts_with($modelName, 'sidecar:image/')
                ? substr($modelName, strlen('sidecar:image/'))
                : $modelName;
            foreach ($this->imageSidecarCapability['models'] ?? [] as $model) {
                if (($model['id'] ?? null) === $alias) {
                    $available = ($model['installed'] ?? false) === true
                        && ($model['published'] ?? false) === true;

                    return [
                        'quality_rating' => null,
                        'status' => $available ? 'available' : 'unavailable',
                        'is_available' => $available,
                    ];
                }
            }

            return ['quality_rating' => null, 'status' => 'unavailable', 'is_available' => false];
        }

        if (! in_array($profileName, ['audio_transcription', 'media_output_audio'], true)) {
            return $this->modelRegistrySummary($modelName);
        }

        $capability = $profileName === 'audio_transcription'
            ? app(WhisperRuntimeService::class)->capability()
            : app(OfflineTtsService::class)->capability();
        $available = ($capability['status'] ?? '') === 'available';

        return [
            'quality_rating' => null,
            'status' => $available ? 'available' : 'unavailable',
            'is_available' => $available,
        ];
    }

    /**
     * @param  array<int,string>  $capabilities
     */
    private function profileCategory(object $profile, string $profileName, array $capabilities): string
    {
        $metadata = json_decode((string) ($profile->metadata ?? '{}'), true);
        if (is_array($metadata) && is_string($metadata['category'] ?? null) && trim($metadata['category']) !== '') {
            return mb_substr(trim($metadata['category']), 0, 80);
        }
        if (str_starts_with($profileName, 'media_output_image') || in_array('image_output', $capabilities, true)) {
            return 'Image Generation';
        }
        if (in_array('image_input', $capabilities, true)) {
            return 'Vision';
        }
        if (in_array('code_output', $capabilities, true)) {
            return 'Coding';
        }
        if (in_array('audio_output', $capabilities, true) || in_array('audio_input', $capabilities, true)) {
            return 'Audio';
        }
        if (str_contains($profileName, 'uncensored')) {
            return 'Private / Uncensored';
        }

        return 'Chat';
    }

    /**
     * Operator override unlocks candidate/risky profiles, not broken or absent models.
     *
     * @param  array{quality_rating:int|null,status:string|null,is_available:bool|null}  $registry
     */
    private function profileSelectableForChat(object $profile, array $registry): bool
    {
        $profileStatus = strtolower(trim((string) ($profile->status ?? '')));
        $registryStatus = strtolower(trim((string) ($registry['status'] ?? '')));
        if (in_array($profileStatus, ['unavailable', 'blocked'], true)
            || in_array($registryStatus, ['unavailable', 'blocked'], true)) {
            return false;
        }

        return $registry['is_available'] === true;
    }

    private function looksLikeCapability(string $value): bool
    {
        return preg_match('/_(input|output)$/', $value) === 1
            || in_array($value, [
                'image_to_text',
                'audio_to_text',
                'video_to_text',
                'text_to_image',
                'text_to_speech',
                'generated_file_output',
                'ocr_text_extraction',
                'operator_uncensored',
                'private_uncensored_text',
                'agentic_coding',
                'visual_coding',
                'visual_reasoning',
                'tool_calling',
                'long_context',
                'local_tool',
                'local_candidate',
                'experimental',
            ], true);
    }

    /**
     * @param  array<int,string>  $capabilities
     * @return array<int,string>
     */
    private function capabilityFamilies(array $capabilities, string $profile): array
    {
        $families = [];
        $map = [
            'operator_uncensored' => 'uncensored',
            'private_uncensored_text' => 'uncensored',
            'code_input' => 'coding',
            'code_output' => 'coding',
            'agentic_coding' => 'agentic',
            'image_input' => 'vision',
            'image_to_text' => 'vision',
            'ocr_text_extraction' => 'ocr',
            'audio_input' => 'audio',
            'audio_to_text' => 'audio',
            'audio_output' => 'audio',
            'text_to_speech' => 'audio',
            'video_input' => 'video',
            'video_to_text' => 'video',
            'image_output' => 'generation',
            'text_to_image' => 'generation',
            'tool_calling' => 'tools',
            'embedding_output' => 'embedding',
        ];

        foreach ($map as $capability => $family) {
            if (in_array($capability, $capabilities, true) && ! in_array($family, $families, true)) {
                $families[] = $family;
            }
        }

        if (empty($families)) {
            $families[] = 'text';
        }
        if (str_contains($profile, 'uncensored') && ! in_array('uncensored', $families, true)) {
            $families[] = 'uncensored';
        }

        return $families;
    }
}

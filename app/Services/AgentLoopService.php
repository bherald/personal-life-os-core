<?php

namespace App\Services;

use App\DTOs\TrustEnvelope;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agent Loop Service - Core Agent Execution Engine
 *
 * Implements the agentic loop pattern (adapted from OpenClaw architecture):
 * context assembly → skill loading → prompt building → LLM call → tool execution → result persistence
 *
 * Features:
 * - Session-keyed serial execution (Redis locks prevent race conditions)
 * - Skill-based context injection (progressive disclosure)
 * - Config-driven tool registry (agents declare tools in SKILL.md, no code changes)
 * - Multi-turn tool execution loop (LLM calls tools, gets results, continues reasoning)
 * - Agent-scoped RAG memory retrieval
 * - Episodic memory recording
 * - Auto-index findings to RAG with agent_id scoping
 * - Pushover notification on completion/findings
 * - Configurable max iterations for tool-calling loops
 */
class AgentLoopService
{
    private ?AIService $aiService = null;

    private ?AgentSessionService $sessionService = null;

    private ?SkillLoaderService $skillLoader = null;

    private ?RAGService $ragService = null;

    private ?AgentToolRegistryService $toolRegistry = null;

    private ?AgentProceduralMemoryService $proceduralMemory = null;

    private ?AgentEpisodicMemoryService $episodicMemory = null;

    private ?AgentCoTAuditService $cotAuditor = null;

    private ?AgentConstitutionService $constitution = null;

    private ?AgentSelectiveVerificationService $selectiveVerifier = null;

    private ?AgentProgressTrackingService $progressTracker = null;

    private ?AgentMemoryGatingService $memoryGating = null;

    private ?\App\Services\AgentLoop\RunMemoryService $runMemory = null;

    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    /** @var array Accumulated quality metrics from hybrid workflow run (reset per execution) */
    private array $hybridRunMetrics = [];

    private const SESSION_LOCK_TTL = 600; // Fallback — config/lock_ttls.php is primary (SC-2.3)

    private const OPERATIONAL_SEVERITIES = ['critical', 'high', 'medium', 'low'];

    private const CJK_GUARDED_OPERATIONAL_AGENTS = [
        'ai-ops',
        'data-removal-ops',
        'email-ops',
        'factcheck-ops',
        'file-curator',
        'file-ops',
        'knowledge-curator',
        'log-analyst',
        'research-analyst',
        'research-ops',
        'system-guardian',
        'workflow-ops',
        'youtube-ops',
    ];

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }

        return $this->aiService;
    }

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    private function getSessionService(): AgentSessionService
    {
        if ($this->sessionService === null) {
            $this->sessionService = app(AgentSessionService::class);
        }

        return $this->sessionService;
    }

    private function getSkillLoader(): SkillLoaderService
    {
        if ($this->skillLoader === null) {
            $this->skillLoader = app(SkillLoaderService::class);
        }

        return $this->skillLoader;
    }

    private function getRAGService(): RAGService
    {
        if ($this->ragService === null) {
            $this->ragService = app(RAGService::class);
        }

        return $this->ragService;
    }

    private function getToolRegistry(): AgentToolRegistryService
    {
        if ($this->toolRegistry === null) {
            $this->toolRegistry = app(AgentToolRegistryService::class);
        }

        return $this->toolRegistry;
    }

    private function getProceduralMemory(): AgentProceduralMemoryService
    {
        if ($this->proceduralMemory === null) {
            $this->proceduralMemory = app(AgentProceduralMemoryService::class);
        }

        return $this->proceduralMemory;
    }

    private function getEpisodicMemory(): AgentEpisodicMemoryService
    {
        if ($this->episodicMemory === null) {
            $this->episodicMemory = app(AgentEpisodicMemoryService::class);
        }

        return $this->episodicMemory;
    }

    private function getCotAuditor(): AgentCoTAuditService
    {
        if ($this->cotAuditor === null) {
            $this->cotAuditor = app(AgentCoTAuditService::class);
        }

        return $this->cotAuditor;
    }

    private function getConstitution(): AgentConstitutionService
    {
        if ($this->constitution === null) {
            $this->constitution = app(AgentConstitutionService::class);
        }

        return $this->constitution;
    }

    private function getSelectiveVerifier(): AgentSelectiveVerificationService
    {
        if ($this->selectiveVerifier === null) {
            $this->selectiveVerifier = app(AgentSelectiveVerificationService::class);
        }

        return $this->selectiveVerifier;
    }

    private function getProgressTracker(): AgentProgressTrackingService
    {
        if ($this->progressTracker === null) {
            $this->progressTracker = app(AgentProgressTrackingService::class);
        }

        return $this->progressTracker;
    }

    private function getMemoryGating(): AgentMemoryGatingService
    {
        if ($this->memoryGating === null) {
            $this->memoryGating = app(AgentMemoryGatingService::class);
        }

        return $this->memoryGating;
    }

    /**
     * Framework C1 — Compact run-memory slice accessor.
     */
    private function getRunMemory(): \App\Services\AgentLoop\RunMemoryService
    {
        if ($this->runMemory === null) {
            $this->runMemory = app(\App\Services\AgentLoop\RunMemoryService::class);
        }

        return $this->runMemory;
    }

    /**
     * Execute an agent loop for a given task
     *
     * @param  string  $agentId  Agent identifier (matches skill name)
     * @param  string  $task  Task description / user message
     * @param  array  $options  Options:
     *                          - session_id: Resume existing session (null = create new)
     *                          - context: Additional context data
     *                          - max_iterations: Override max loop iterations
     *                          - model: Override LLM model
     *                          - tree_id: Genealogy tree context
     *                          - notify: Send Pushover on completion (default: false)
     *                          - depth: Current nesting depth for sub-agent limits
     *                          - index_findings: Auto-index final response to RAG (default: true)
     * @return array Execution result with session_id, response, episodes, duration_ms
     */
    public function execute(string $agentId, string $task, array $options = []): array
    {
        $startTime = microtime(true);
        $maxIterations = $options['max_iterations'] ?? config('agents.max_loop_iterations', 10);
        $depth = $options['depth'] ?? 0;
        $maxDepth = config('agents.max_nesting_depth', 5);

        // S19: Speculative execution trigger — runs same task through 2 modes in parallel
        if (empty($options['_speculative_branch']) && empty($options['benchmark_mode'])) {
            try {
                $speculativeService = app(SpeculativeExecutionService::class);
                $shouldSpeculate = false;
                $triggerType = 'manual';

                // Explicit agent request (Redis flag from previous run)
                if (Cache::has("speculative_request:{$agentId}")) {
                    $shouldSpeculate = true;
                    $triggerType = 'agent_request';
                    Cache::forget("speculative_request:{$agentId}");
                }

                // Options-driven (from CLI or API)
                if (! empty($options['speculative'])) {
                    $shouldSpeculate = true;
                    $triggerType = $options['speculative_trigger'] ?? 'manual';
                }

                // Variance-detected (auto)
                if (! $shouldSpeculate && $speculativeService->shouldSpeculate($agentId, $task)) {
                    $shouldSpeculate = true;
                    $triggerType = 'variance_detected';
                }

                if ($shouldSpeculate) {
                    return $speculativeService->execute($agentId, $task, array_merge($options, [
                        'trigger_type' => $triggerType,
                    ]));
                }
            } catch (\Throwable $e) {
                Log::warning('Speculative execution check failed, continuing normal execution', [
                    'agent_id' => $agentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($depth >= $maxDepth) {
            return [
                'success' => false,
                'error' => "Agent nesting depth limit ({$maxDepth}) exceeded",
                'agent_id' => $agentId,
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ];
        }

        // AG-6: Safety card validation — warn if agent lacks formal safety documentation
        $safetyCardPath = base_path(config('agents.skills_path', 'resources/agents/skills')."/{$agentId}/SAFETY-CARD.md");
        if (! file_exists($safetyCardPath) && ! Cache::has("safety_card_warned:{$agentId}")) {
            Log::warning("Agent '{$agentId}' has no SAFETY-CARD.md — see docs/AGENT-SAFETY-CARDS.md", [
                'agent_id' => $agentId,
                'recommendation' => 'MIT 2025 AI Agent Index recommends formal safety cards for all agents',
            ]);
            Cache::put("safety_card_warned:{$agentId}", true, 86400); // Warn once per day
        }

        // 1. Acquire session lock (serial execution per session)
        $sessionId = $options['session_id'] ?? 'agent:'.$agentId.':'.uniqid();
        $lockKey = "agent_session_lock:{$sessionId}";
        $lock = Cache::lock($lockKey, config('lock_ttls.agent_session', self::SESSION_LOCK_TTL));

        if (! $lock->get()) {
            return [
                'success' => false,
                'error' => 'Agent session is busy (another run in progress)',
                'agent_id' => $agentId,
                'session_id' => $sessionId,
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ];
        }

        try {
            // 2. Load or create session
            $session = $this->getSessionService()->findOrCreateSession(null, 'agent', [
                'session_id' => $sessionId,
                'agent_name' => $agentId,
                'ttl_hours' => 24,
                'context' => $options['context'] ?? [],
                'metadata' => [
                    'skill' => $agentId,
                    'tree_id' => $options['tree_id'] ?? null,
                    'depth' => $depth,
                ],
            ]);

            // 3. Load skill definition (also tracks version in skill_versions table)
            $skill = $this->getSkillLoader()->loadSkill($agentId);
            $skillInstructions = $skill['body'] ?? '';
            $skillConfig = $skill['frontmatter'] ?? [];
            $skillVersion = $skillConfig['version'] ?? '1.0.0';

            // 3b. Record skill version in session
            try {
                DB::update('
                    UPDATE agent_sessions SET skill_version = ? WHERE session_id = ?
                ', [$skillVersion, $session['session_id']]);
            } catch (\Throwable $e) {
                Log::debug('AgentLoopService: skill_version update failed', ['error' => $e->getMessage()]);
            }

            // 3d. Framework C1 — start compact run-memory slice. Constraints are
            // derived from skill config (permissions + workflow_mode + sensitive flag)
            // so the LLM sees them as structured facts, not free-form prompt text.
            try {
                $runConstraints = [];
                $perms = $skillConfig['permissions'] ?? [];
                if (! empty($perms) && is_array($perms)) {
                    $runConstraints[] = 'permissions: '.implode(',', array_slice($perms, 0, 10));
                }
                if (! empty($skillConfig['workflow_mode'])) {
                    $runConstraints[] = 'workflow_mode: '.$skillConfig['workflow_mode'];
                }
                if (! empty($options['tree_id'])) {
                    $runConstraints[] = 'tree_id: '.$options['tree_id'];
                }
                $earlySensitive = ! empty(array_filter(
                    (array) ($skillConfig['permissions'] ?? []),
                    fn ($p) => is_string($p) && (
                        str_starts_with($p, 'email:')
                        || str_starts_with($p, 'health:')
                        || str_starts_with($p, 'finance:')
                    )
                ));
                if ($earlySensitive) {
                    $runConstraints[] = 'sensitive_safe_routing: required';
                }
                $this->getRunMemory()->start($session['session_id'], $task, $runConstraints);
            } catch (\Throwable $ignore) {
                // Non-fatal — run memory is an optimization
                Log::debug('AgentLoopService: run memory start failed', [
                    'session' => $session['session_id'] ?? null,
                    'error' => $ignore->getMessage(),
                ]);
            }

            // 3c. Monitoring agent pre-screen: skip LLM if all healthy (token savings)
            $preScreenResult = $this->monitoringPreScreen($agentId, $session);
            if ($preScreenResult !== null) {
                $this->getSessionService()->completeSession($session['session_id']);
                $lock->release();

                return [
                    'success' => true,
                    'agent_id' => $agentId,
                    'session_id' => $session['session_id'],
                    'pre_screened' => true,
                    'result' => $preScreenResult,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                    'tokens_used' => 0,
                    'tool_calls' => [],
                ];
            }

            // 4. Load available tools for this agent
            $agentTools = $this->getToolRegistry()->getToolsForAgent($agentId);
            $toolDescriptions = $this->getToolRegistry()->buildToolDescriptions($agentTools);

            // 5. Build system prompt (with tool descriptions + procedural memory)
            $options['task'] = $task; // Pass task to buildSystemPrompt for procedural memory recall
            $options['run_memory_session_id'] = $session['session_id']; // C1: thread to buildSystemPrompt for run memory fragment
            $systemPrompt = $this->buildSystemPrompt($agentId, $skillInstructions, $skillConfig, $options, $toolDescriptions);

            // 6. Retrieve relevant agent memory from RAG
            $memoryContext = $this->retrieveAgentMemory($agentId, $task, $options);

            // 7. Build conversation context
            $messages = $this->getSessionService()->buildChatContext($session['session_id'], 20);

            // Inject memory context if available
            if ($memoryContext) {
                $messages[] = [
                    'role' => 'system',
                    'content' => "## Relevant Memory\n".$memoryContext,
                ];
            }

            // Add the current task as user message
            $this->getSessionService()->addMessage($session['session_id'], 'user', $task);
            $messages[] = ['role' => 'user', 'content' => $task];

            // 8. Execute: deterministic workflow OR agentic loop
            $model = $options['model'] ?? $skillConfig['model'] ?? null;
            $useAutoSelect = ($model === null); // No model specified → let AIService pick best available
            $modelRole = $options['model_role'] ?? $skillConfig['model_role'] ?? 'standard'; // N119b: quality/standard/fast
            AIService::setAgentModelRole($modelRole); // Inherit model_role into tool-level LLM calls
            $temperature = $skillConfig['temperature'] ?? 0.7;
            // AI-1: cascade opt-in — SKILL.md can set `cascade: true` or `cascade: {threshold: 0.7}`
            $cascadeConfig = $skillConfig['cascade'] ?? null;
            // Local genealogy work may use private/living family-tree records
            // inside PLOS. Export/public sharing workflows own privacy and
            // redaction checks at that boundary.
            $hasSensitivePerms = ! empty(array_filter(
                $skillConfig['permissions'] ?? [],
                fn ($p) => str_starts_with($p, 'email:') || str_starts_with($p, 'health:') || str_starts_with($p, 'finance:')
            ));
            $totalTokens = 0;
            $toolCalls = [];
            $iterations = 0;
            $finalResponse = '';
            $backtracker = new \App\Services\AgentBacktrackService; // AG-10

            // Build runtime context for tool parameter injection
            $toolContext = [
                'tree_id' => $options['tree_id'] ?? null,
                'agent_id' => $agentId,
            ];

            // RLM: Pass recursion config from SKILL.md into tool context
            if (! empty($skillConfig['recursion']) && is_array($skillConfig['recursion'])) {
                $toolContext['recursion_config'] = $skillConfig['recursion'];
            }

            // Parse tool phases early — used by both hybrid and agentic modes
            $toolPhases = $skillConfig['tool_phases'] ?? null;

            // Record task start episode
            $workflowMode = $skillConfig['workflow_mode'] ?? 'agentic';
            $adaptiveSelectionId = null;

            // Adaptive mode selection (S20): auto-pick optimal mode from benchmark data
            if ($workflowMode === 'auto' && empty($options['benchmark_mode'])) {
                try {
                    $adaptiveService = app(AdaptiveModeService::class);
                    $adaptiveResult = $adaptiveService->selectMode($agentId, $task, [
                        'default_mode' => $skillConfig['default_mode'] ?? 'agentic',
                        'session_id' => $sessionId,
                    ]);
                    $workflowMode = $adaptiveResult['mode'];
                    $adaptiveSelectionId = $adaptiveResult['selection_id'] ?? null;

                    // Synthesize deterministic steps if adaptive picked deterministic
                    if ($workflowMode === 'deterministic' && empty($skillConfig['workflow_steps'])) {
                        $steps = [];
                        foreach ($agentTools as $toolName => $toolDef) {
                            // Skip tools whose required params can't be satisfied from context
                            $unsatisfied = $this->getUnsatisfiedRequiredParams($toolDef, $toolContext);
                            if (! empty($unsatisfied)) {
                                Log::debug('AgentLoop: Skipping tool in deterministic synthesis (unsatisfied params)', [
                                    'tool' => $toolName, 'missing' => $unsatisfied,
                                ]);

                                continue;
                            }
                            $label = ucwords(str_replace('_', ' ', $toolName));
                            $steps[] = "{$toolName}|{$label}";
                        }
                        $skillConfig['workflow_steps'] = $steps;
                    }

                    Log::info('AgentLoop: Adaptive mode selected', [
                        'agent_id' => $agentId,
                        'mode' => $workflowMode,
                        'confidence' => $adaptiveResult['confidence'] ?? 0,
                        'fallback' => $adaptiveResult['fallback'] ?? false,
                        'task_key' => $adaptiveResult['task_key'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    // Non-fatal — fall back to agentic
                    $workflowMode = 'agentic';
                    Log::warning('AgentLoop: Adaptive mode failed, using agentic', [
                        'agent_id' => $agentId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Benchmark mode override: allows running any agent in any workflow mode
            if (! empty($options['benchmark_mode'])) {
                $benchmarkMode = $options['benchmark_mode'];
                if (in_array($benchmarkMode, ['agentic', 'hybrid', 'deterministic'])) {
                    $workflowMode = $benchmarkMode;

                    // For deterministic mode: synthesize workflow_steps from tool list if not defined
                    if ($benchmarkMode === 'deterministic' && empty($skillConfig['workflow_steps'])) {
                        $steps = [];
                        foreach ($agentTools as $toolName => $toolDef) {
                            // Skip tools whose required params can't be satisfied from context
                            $unsatisfied = $this->getUnsatisfiedRequiredParams($toolDef, $toolContext);
                            if (! empty($unsatisfied)) {
                                Log::debug('AgentLoop: Skipping tool in benchmark deterministic synthesis (unsatisfied params)', [
                                    'tool' => $toolName, 'missing' => $unsatisfied,
                                ]);

                                continue;
                            }
                            $label = ucwords(str_replace('_', ' ', $toolName));
                            $steps[] = "{$toolName}|{$label}";
                        }
                        $skillConfig['workflow_steps'] = $steps;
                    }

                    Log::info('AgentLoop: Benchmark mode override', [
                        'agent_id' => $agentId,
                        'original_mode' => $skillConfig['workflow_mode'] ?? 'agentic',
                        'benchmark_mode' => $benchmarkMode,
                    ]);
                }
            }

            $this->recordEpisode($agentId, $sessionId, 'task_started', $task, [
                'options' => array_diff_key($options, ['context' => 1]),
                'tools_available' => array_keys($agentTools),
                'workflow_mode' => $workflowMode,
            ]);

            if ($workflowMode === 'hybrid' && ! empty($toolPhases)) {
                // Hybrid mode: framework drives tool execution per phase, LLM analyzes
                // between phases and provides final synthesis. Best of both worlds:
                // - Tools are called systematically (no wasted iterations)
                // - LLM interprets results and guides cross-phase analysis
                $finalResponse = $this->executeHybridWorkflow(
                    $agentId, $sessionId, $task, $skillConfig, $skillInstructions,
                    $agentTools, $toolPhases, $toolContext, $toolCalls,
                    $systemPrompt, $messages, $model, $useAutoSelect, $temperature, $hasSensitivePerms, $options
                );
                $iterations = count($toolCalls);
                $totalTokens = $this->hybridRunMetrics['total_tokens'] ?? 0;
                $response = ['model' => $model ?? 'hybrid']; // set for episode recording

                // Capture metrics before Pushover resets them
                $capturedHybridMetrics = $this->hybridRunMetrics;

                // Hybrid run summary Pushover disabled (AG-24) — redundant with daily report
                // Metrics still logged; summary data available via agent:trace-run
                Log::info('AgentLoop: Hybrid run completed', [
                    'agent_id' => $agentId,
                    'phases_completed' => $this->hybridRunMetrics['phases_completed'] ?? 0,
                    'review_items' => $this->hybridRunMetrics['review_items_submitted'] ?? 0,
                ]);
                $this->hybridRunMetrics = [];

            } elseif ($workflowMode === 'deterministic' && ! empty($skillConfig['workflow_steps'])) {
                // Deterministic mode: execute tools in declared order, then LLM analyzes
                $workflowResults = $this->executeDeterministicWorkflow(
                    $agentId, $sessionId, $skillConfig['workflow_steps'], $agentTools, $toolContext, $toolCalls
                );

                $iterations = count($workflowResults);

                // Build analysis prompt with all tool results
                $analysisPrompt = 'You have been given the following real data from tool executions. '
                    ."Analyze this data and provide your research findings, recommendations, and next steps.\n\n"
                    ."Original task: {$task}\n\n";

                foreach ($workflowResults as $wr) {
                    $analysisPrompt .= "## {$wr['label']}\n"
                        ."Tool: {$wr['tool']} (status: ".($wr['success'] ? 'OK' : 'FAILED').")\n"
                        ."Result:\n{$wr['result_text']}\n\n";
                }

                $analysisPrompt .= 'Based on the REAL data above (do NOT fabricate additional data), provide your analysis.';

                $messages[] = ['role' => 'user', 'content' => $analysisPrompt];

                // Single LLM call to analyze all collected results
                $aiOptions = [
                    'system' => $systemPrompt,
                    'temperature' => $temperature,
                    'ai_timeout' => $this->getAgentAiTimeout($skillConfig, $options),
                    'use_cache' => false, // N119: Agent iterations must analyze fresh tool results
                    'model_role' => $modelRole, // N119b: quality role → bigger models
                ];
                if ($model) {
                    $aiOptions['model'] = $model;
                }
                if ($cascadeConfig !== null) {
                    $aiOptions['cascade'] = $cascadeConfig;
                }

                $response = $this->getAIService()->process(
                    $this->formatMessagesForAI($messages),
                    $aiOptions
                );

                $totalTokens = $response['tokens'] ?? 0;
                $finalResponse = $response['content'] ?? $response['response'] ?? '';

            } else {
                // Agentic mode: LLM drives tool calls via JSON in responses
                $maxTokenBudget = $skillConfig['max_tokens'] ?? 50000;
                $toolCallCounts = [];
                $consecutiveSameTool = 0;
                $lastToolName = null;
                $recentToolCalls = []; // Track last N tool calls for alternation detection
                $submitForReviewCount = 0;

                // --- Phased tool loading ---
                // If SKILL.md defines tool_phases, only show one phase of tools at a time.
                // The LLM sees fewer tools per phase (5-10 instead of 40+), improving
                // reliability on smaller models. Phase advances when LLM gives a text
                // response (no tool call) summarizing the phase, or when it calls a tool
                // not in the current phase but available overall.
                $toolPhases = $skillConfig['tool_phases'] ?? null;
                $currentPhaseIdx = 0;
                $phaseResults = [];

                if ($toolPhases && is_array($toolPhases)) {
                    $phaseNames = array_keys($toolPhases);
                    $currentPhaseName = $phaseNames[0] ?? 'default';
                    $currentPhaseTools = $this->getToolRegistry()->getToolsForPhase(
                        $agentTools,
                        $toolPhases[$currentPhaseName] ?? null
                    );
                    // Rebuild tool descriptions + system prompt for this phase
                    $phaseToolDescriptions = $this->getToolRegistry()->buildToolDescriptions($currentPhaseTools);
                    $systemPrompt = $this->buildSystemPrompt($agentId, $skillInstructions, $skillConfig, $options, $phaseToolDescriptions);

                    Log::info('AgentLoop: Phase started', [
                        'agent_id' => $agentId,
                        'phase' => $currentPhaseName,
                        'tools' => array_keys($currentPhaseTools),
                    ]);
                } else {
                    $phaseNames = [];
                    $currentPhaseName = null;
                    $currentPhaseTools = $agentTools;
                }

                // Calculate per-phase iteration budget for auto-advancement
                $phaseCount = count($phaseNames);
                $iterationsPerPhase = $phaseCount > 0 ? max(3, intdiv($maxIterations, $phaseCount)) : $maxIterations;
                $phaseIterationCount = 0;

                while ($iterations < $maxIterations) {
                    $iterations++;
                    $phaseIterationCount++;

                    // Auto-advance phase if iteration budget exhausted
                    if (! empty($phaseNames) && $phaseIterationCount > $iterationsPerPhase && $currentPhaseIdx < count($phaseNames) - 1) {
                        $phaseResults[$currentPhaseName] = $assistantContent ?? "Phase '{$currentPhaseName}' completed (auto-advanced after {$iterationsPerPhase} iterations).";

                        $currentPhaseIdx++;
                        $currentPhaseName = $phaseNames[$currentPhaseIdx];
                        $currentPhaseTools = $this->getToolRegistry()->getToolsForPhase(
                            $agentTools,
                            $toolPhases[$currentPhaseName] ?? null
                        );

                        $phaseToolDescriptions = $this->getToolRegistry()->buildToolDescriptions($currentPhaseTools);
                        $systemPrompt = $this->buildSystemPrompt($agentId, $skillInstructions, $skillConfig, $options, $phaseToolDescriptions);

                        $messages[] = ['role' => 'user', 'content' => "Phase '{$phaseNames[$currentPhaseIdx - 1]}' time budget reached. ".
                            "Moving to phase '{$currentPhaseName}'. Use these tools now: ".
                            implode(', ', array_keys($currentPhaseTools)).'. '.
                            'Call a tool to begin this phase.',
                        ];

                        Log::info('AgentLoop: Auto-advanced phase', [
                            'agent_id' => $agentId,
                            'from_phase' => $phaseNames[$currentPhaseIdx - 1],
                            'to_phase' => $currentPhaseName,
                            'iteration' => $iterations,
                        ]);

                        $this->recordEpisode($agentId, $sessionId, 'phase_advanced',
                            "Auto-advanced from '{$phaseNames[$currentPhaseIdx - 1]}' to '{$currentPhaseName}'", [
                                'iteration' => $iterations,
                                'phase_iterations' => $phaseIterationCount - 1,
                            ]);

                        $phaseIterationCount = 0;
                        $consecutiveSameTool = 0;
                        $lastToolName = null;
                    }

                    // Kill switch check
                    if (Cache::has("agent_kill:{$agentId}")) {
                        $this->recordEpisode($agentId, $sessionId, 'killed', 'Terminated by kill switch', [
                            'iteration' => $iterations,
                            'tokens_used' => $totalTokens,
                        ]);
                        Log::warning('AgentLoop: Kill switch activated', ['agent_id' => $agentId]);
                        $finalResponse = $assistantContent ?? "Agent terminated by kill switch after {$iterations} iterations.";
                        break;
                    }

                    // Force summary on final iteration — prevent empty final response
                    if ($iterations >= $maxIterations && count($toolCalls) > 0) {
                        $messages[] = ['role' => 'user', 'content' => 'FINAL ITERATION: You have used all available iterations. '.
                            'Do NOT call any more tools. Instead, provide a comprehensive summary of ALL your findings '.
                            'from the tool results above. Format your response with clear sections for each finding.',
                        ];
                    }

                    $aiOptions = [
                        'system' => $systemPrompt,
                        'system_prompt' => $systemPrompt, // For external API providers
                        'temperature' => $temperature,
                        'max_tokens' => config('agents.context_max_tokens', 4000), // Agent responses need more tokens than default 2000
                        'ai_timeout' => $this->getAgentAiTimeout($skillConfig, $options),
                        'sensitive_data' => $hasSensitivePerms,
                        'use_cache' => false, // N119: Agent iterations must analyze fresh tool results
                    ];

                    // Pass num_ctx from SKILL.md for Ollama context window sizing
                    $numCtx = $skillConfig['num_ctx'] ?? null;
                    if ($numCtx) {
                        $aiOptions['num_ctx'] = (int) $numCtx;
                    }

                    if ($model) {
                        $aiOptions['model'] = $model;
                    } elseif ($useAutoSelect) {
                        $aiOptions['skip_if_busy'] = true;
                        $aiOptions['model_role'] = $modelRole; // N119b: quality role → bigger models
                    }
                    if ($cascadeConfig !== null) {
                        $aiOptions['cascade'] = $cascadeConfig;
                    }

                    $response = $this->getAIService()->process(
                        $this->formatMessagesForAI($messages),
                        $aiOptions
                    );

                    $totalTokens += $response['tokens'] ?? 0;
                    $assistantContent = $response['content'] ?? $response['response'] ?? '';

                    // Token budget enforcement
                    if ($totalTokens > $maxTokenBudget) {
                        $this->recordEpisode($agentId, $sessionId, 'budget_exceeded', "Token budget {$maxTokenBudget} exceeded at {$totalTokens}", [
                            'tokens_used' => $totalTokens,
                            'budget' => $maxTokenBudget,
                            'iteration' => $iterations,
                        ]);
                        Log::warning('AgentLoop: Token budget exceeded', [
                            'agent_id' => $agentId,
                            'tokens' => $totalTokens,
                            'budget' => $maxTokenBudget,
                        ]);
                        $finalResponse = $assistantContent;
                        break;
                    }

                    // Parse response for tool calls
                    $parsed = $this->getToolRegistry()->parseToolCall($assistantContent);

                    if (! $parsed['has_tool_call'] || empty($agentTools)) {
                        // Anti-hallucination guard: if tools are available but LLM made
                        // ZERO tool calls, the response is unsupported by any data source.
                        // Reject it — do NOT accept fabricated content as final output.
                        $visibleTools = $currentPhaseTools ?? $agentTools;
                        $autoPrimedToolCall = false;
                        if (! empty($visibleTools) && count($toolCalls) === 0) {
                            if ($iterations <= 2) {
                                // Scheduled monitor agents occasionally answer in prose even
                                // after being instructed to call tools. Prime the loop with a
                                // safe first phase tool so the next response is grounded.
                                $firstTool = null;
                                foreach ($visibleTools as $name => $def) {
                                    $required = $def['parameters']['required'] ?? [];
                                    if (empty($required)) {
                                        $firstTool = $name;
                                        break;
                                    }
                                }
                                $firstTool ??= array_key_first($visibleTools);
                                $assistantContent = "```json\n{\"tool\": \"{$firstTool}\", \"params\": {}}\n```";
                                $parsed = [
                                    'has_tool_call' => true,
                                    'tool' => $firstTool,
                                    'params' => [],
                                ];
                                $autoPrimedToolCall = true;
                                Log::warning('AgentLoop: Auto-priming zero-tool response with first available tool', [
                                    'agent_id' => $agentId,
                                    'iteration' => $iterations,
                                    'tool' => $firstTool,
                                ]);
                            } elseif ($iterations < 4) {
                                // Retry: force the LLM to use its tools
                                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                                $firstTool = array_key_first($visibleTools);
                                $messages[] = ['role' => 'user', 'content' => 'CRITICAL: You must output a JSON tool call block, not natural language. '.
                                    "Do NOT describe what you would do — actually call the tool using this exact format:\n".
                                    "```json\n{\"tool\": \"{$firstTool}\", \"params\": {}}\n```\n".
                                    'Available tools: '.implode(', ', array_keys($visibleTools))."\n".
                                    'Output ONLY the JSON block above (you may include brief reasoning before it). Do NOT fabricate any data.',
                                ];
                                Log::warning('AgentLoop: Anti-hallucination retry — no tool calls yet', [
                                    'agent_id' => $agentId,
                                    'iteration' => $iterations,
                                    'response_preview' => substr($assistantContent, 0, 200),
                                ]);

                                continue;
                            } else {
                                // Already retried — terminate run, do NOT accept the response
                                $this->recordEpisode($agentId, $sessionId, 'hallucination_blocked',
                                    'LLM failed to call any tools after retry — response rejected as potential hallucination', [
                                        'iteration' => $iterations,
                                        'response_preview' => substr($assistantContent, 0, 500),
                                    ]);
                                Log::error('AgentLoop: Hallucination blocked — zero tool calls after retry', [
                                    'agent_id' => $agentId,
                                    'iterations' => $iterations,
                                ]);
                                $finalResponse = 'ERROR: Agent run terminated — LLM failed to use available tools and may have produced fabricated content. '.
                                    'No output accepted. Tool calls made: 0. The agent must use its tools to gather real data.';
                                break;
                            }
                        }

                        if (! $autoPrimedToolCall) {
                            // --- Phase advancement ---
                            // If tool_phases is active and more phases remain, advance to next phase
                            // instead of ending the run. The LLM's text response becomes the
                            // phase summary, and we give it the next set of tools.
                            if (! empty($phaseNames) && $currentPhaseIdx < count($phaseNames) - 1) {
                                $phaseResults[$currentPhaseName] = $assistantContent;

                                $currentPhaseIdx++;
                                $currentPhaseName = $phaseNames[$currentPhaseIdx];
                                $currentPhaseTools = $this->getToolRegistry()->getToolsForPhase(
                                    $agentTools,
                                    $toolPhases[$currentPhaseName] ?? null
                                );

                                // Rebuild system prompt with new phase tools
                                $phaseToolDescriptions = $this->getToolRegistry()->buildToolDescriptions($currentPhaseTools);
                                $systemPrompt = $this->buildSystemPrompt($agentId, $skillInstructions, $skillConfig, $options, $phaseToolDescriptions);

                                // Feed phase summary back and instruct to continue
                                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                                $messages[] = ['role' => 'user', 'content' => "Phase '{$phaseNames[$currentPhaseIdx - 1]}' complete. ".
                                    "Now entering phase '{$currentPhaseName}'. ".
                                    'Use the tools now available to continue your work. '.
                                    'Start by calling a tool.',
                                ];

                                Log::info('AgentLoop: Phase advanced', [
                                    'agent_id' => $agentId,
                                    'from_phase' => $phaseNames[$currentPhaseIdx - 1],
                                    'to_phase' => $currentPhaseName,
                                    'tools' => array_keys($currentPhaseTools),
                                    'iteration' => $iterations,
                                ]);

                                // Reset tracking for new phase
                                $phaseIterationCount = 0;
                                $consecutiveSameTool = 0;
                                $lastToolName = null;

                                continue;
                            }

                            // No more phases (or no phases defined) — this is the final summary
                            $finalResponse = $assistantContent;
                            break;
                        }
                    }

                    // Execute the tool call
                    $toolName = $parsed['tool'];
                    $toolParams = $parsed['params'];

                    // --- Loop safety: consecutive same-tool detection ---
                    if ($toolName === $lastToolName) {
                        $consecutiveSameTool++;
                    } else {
                        $consecutiveSameTool = 0;
                    }
                    $lastToolName = $toolName;

                    // --- Loop safety: alternating-tool pattern detection ---
                    // Catches A→B→A→B spinning that evades consecutive-same-tool check
                    $recentToolCalls[] = $toolName;
                    if (count($recentToolCalls) > 6) {
                        array_shift($recentToolCalls);
                    }
                    $alternatingSpinDetected = false;
                    if (count($recentToolCalls) >= 6) {
                        // Check for A-B-A-B-A-B pattern (3 full cycles)
                        $last6 = array_slice($recentToolCalls, -6);
                        $uniqueInWindow = array_unique($last6);
                        if (count($uniqueInWindow) <= 2
                            && $last6[0] === $last6[2] && $last6[2] === $last6[4]
                            && $last6[1] === $last6[3] && $last6[3] === $last6[5]) {
                            $alternatingSpinDetected = true;
                            $spinTools = implode(' ↔ ', array_unique($last6));
                            Log::warning('AgentLoop: Alternating tool spin detected', [
                                'agent_id' => $agentId, 'tools' => $spinTools, 'iteration' => $iterations,
                            ]);
                            if (! empty($phaseNames) && $currentPhaseIdx < count($phaseNames) - 1) {
                                $phaseResults[$currentPhaseName] = $assistantContent;
                                $currentPhaseIdx++;
                                $currentPhaseName = $phaseNames[$currentPhaseIdx];
                                $currentPhaseTools = $this->getToolRegistry()->getToolsForPhase(
                                    $agentTools, $toolPhases[$currentPhaseName] ?? null
                                );
                                $phaseToolDescriptions = $this->getToolRegistry()->buildToolDescriptions($currentPhaseTools);
                                $systemPrompt = $this->buildSystemPrompt($agentId, $skillInstructions, $skillConfig, $options, $phaseToolDescriptions);
                                $messages[] = ['role' => 'user', 'content' => "Spinning detected: you alternated '{$spinTools}' without progress. ".
                                    "Moving to phase '{$currentPhaseName}'. Use these tools: ".
                                    implode(', ', array_keys($currentPhaseTools)).'.',
                                ];
                                $this->recordEpisode($agentId, $sessionId, 'phase_advanced',
                                    "Forced advance from alternating spin ({$spinTools}) to '{$currentPhaseName}'", [
                                        'iteration' => $iterations,
                                    ]);
                                $phaseIterationCount = 0;
                                $consecutiveSameTool = 0;
                                $lastToolName = null;
                                $recentToolCalls = [];

                                continue;
                            }
                            $this->recordEpisode($agentId, $sessionId, 'loop_detected',
                                "Alternating tool spin ({$spinTools}) — breaking loop", ['iteration' => $iterations]);
                            $finalResponse = $assistantContent;
                            break;
                        }
                    }

                    $toolLoopLimit = config('agents.consecutive_tool_limit', 3);
                    if ($consecutiveSameTool >= $toolLoopLimit) {
                        // Consecutive limit hit = phase advance or break, not just warning
                        if (! empty($phaseNames) && $currentPhaseIdx < count($phaseNames) - 1) {
                            // Force phase advance instead of breaking the entire run
                            $phaseResults[$currentPhaseName] = $assistantContent;
                            $currentPhaseIdx++;
                            $currentPhaseName = $phaseNames[$currentPhaseIdx];
                            $currentPhaseTools = $this->getToolRegistry()->getToolsForPhase(
                                $agentTools, $toolPhases[$currentPhaseName] ?? null
                            );
                            $phaseToolDescriptions = $this->getToolRegistry()->buildToolDescriptions($currentPhaseTools);
                            $systemPrompt = $this->buildSystemPrompt($agentId, $skillInstructions, $skillConfig, $options, $phaseToolDescriptions);
                            $messages[] = ['role' => 'user', 'content' => "You repeated '{$toolName}' 3 times. Moving to phase '{$currentPhaseName}' with new tools: ".
                                implode(', ', array_keys($currentPhaseTools)).'. Call a different tool now.',
                            ];
                            $this->recordEpisode($agentId, $sessionId, 'phase_advanced',
                                "Forced advance from repeat of '{$toolName}' to '{$currentPhaseName}'", [
                                    'iteration' => $iterations,
                                ]);
                            $phaseIterationCount = 0;
                            $consecutiveSameTool = 0;
                            $lastToolName = null;

                            continue;
                        }

                        $this->recordEpisode($agentId, $sessionId, 'loop_detected', "Same tool '{$toolName}' called 3+ times — breaking loop", [
                            'tool' => $toolName,
                            'iteration' => $iterations,
                        ]);
                        Log::warning('AgentLoop: Infinite loop detected', ['agent_id' => $agentId, 'tool' => $toolName]);
                        $finalResponse = $assistantContent;
                        break;
                    }

                    if ($consecutiveSameTool >= config('agents.consecutive_tool_limit_alt', 2)) {
                        // Inject warning after 2 consecutive same-tool calls
                        $visibleTools = $currentPhaseTools ?? $agentTools;
                        $otherTools = array_diff(array_keys($visibleTools), [$toolName]);
                        $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                        $messages[] = ['role' => 'user', 'content' => "Warning: You called '{$toolName}' twice in a row. Use a DIFFERENT tool next. ".
                            'Available alternatives: '.implode(', ', array_slice($otherTools, 0, 5)),
                        ];

                        continue;
                    }

                    // --- Loop safety: per-tool call limit ---
                    $toolCallCounts[$toolName] = ($toolCallCounts[$toolName] ?? 0) + 1;
                    $toolDef = $agentTools[$toolName] ?? [];
                    $maxCallsPerRun = $toolDef['max_calls_per_run'] ?? null;

                    if ($maxCallsPerRun !== null && $toolCallCounts[$toolName] > $maxCallsPerRun) {
                        $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                        $messages[] = ['role' => 'user', 'content' => "Error: Tool '{$toolName}' has reached its maximum of {$maxCallsPerRun} calls per run. Use a different tool or provide your final answer."];

                        continue;
                    }

                    // --- Loop safety: submitForReview cap ---
                    if ($toolName === 'submit_for_review') {
                        $submitForReviewCount++;
                        if ($submitForReviewCount > 3) {
                            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                            $messages[] = ['role' => 'user', 'content' => 'You have already submitted 3 items for review. Consolidate remaining findings into your final response instead of submitting more reviews.'];

                            continue;
                        }
                    }

                    Log::info('AgentLoop: Tool call', [
                        'agent_id' => $agentId,
                        'tool' => $toolName,
                        'iteration' => $iterations,
                    ]);

                    // AG-7: Chain-of-Thought Audit — check reasoning before executing write tools
                    $cotAuditor = $this->getCotAuditor();
                    if (! $cotAuditor->isDisabledForAgent($skillConfig ?? [])) {
                        $cotResult = $cotAuditor->audit(
                            $agentId,
                            $assistantContent,
                            $toolName,
                            $toolParams,
                            $toolCalls
                        );

                        if ($cotResult['action'] === 'block') {
                            $this->recordEpisode($agentId, $sessionId, 'cot_audit_block',
                                "CoT audit blocked '{$toolName}': {$cotResult['reason']}", [
                                    'tool' => $toolName,
                                    'risk' => $cotResult['risk'],
                                    'iteration' => $iterations,
                                ]);
                            Log::warning('AgentLoop: CoT audit blocked tool', [
                                'agent_id' => $agentId,
                                'tool' => $toolName,
                                'risk' => $cotResult['risk'],
                                'reason' => $cotResult['reason'],
                            ]);
                            // Inject feedback — do not execute the tool
                            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                            $messages[] = ['role' => 'user', 'content' => 'REASONING REVIEW: Your proposed action was paused. '.$cotResult['reason'].
                                ' Please gather additional evidence using your research tools before proceeding.',
                            ];

                            continue;
                        }

                        if ($cotResult['action'] === 'warn') {
                            $this->recordEpisode($agentId, $sessionId, 'cot_audit_warn',
                                "CoT audit warning for '{$toolName}': {$cotResult['reason']}", [
                                    'tool' => $toolName,
                                    'risk' => $cotResult['risk'],
                                    'iteration' => $iterations,
                                ]);
                            Log::info('AgentLoop: CoT audit warning', [
                                'agent_id' => $agentId,
                                'tool' => $toolName,
                                'risk' => $cotResult['risk'],
                                'reason' => $cotResult['reason'],
                            ]);
                            // Warn but proceed
                        }
                    }

                    // AG-8: Agent Constitution — hard rule evaluation before execution
                    $constitutionRules = $this->getConstitution()->getRules($agentId, $skillConfig ?? []);
                    $constitutionResult = $this->getConstitution()->evaluateTool(
                        $toolName,
                        $toolParams,
                        $constitutionRules,
                        ['tree_id' => $options['tree_id'] ?? null, 'agent_id' => $agentId, 'iteration' => $iterations]
                    );

                    if ($constitutionResult['action'] === 'deny') {
                        $this->recordEpisode($agentId, $sessionId, 'constitution_deny',
                            "Constitution denied '{$toolName}' [{$constitutionResult['rule_id']}]: {$constitutionResult['reason']}", [
                                'tool' => $toolName,
                                'rule_id' => $constitutionResult['rule_id'],
                                'iteration' => $iterations,
                            ]);
                        Log::warning('AgentLoop: AG-8 constitution deny', [
                            'agent_id' => $agentId,
                            'tool' => $toolName,
                            'rule_id' => $constitutionResult['rule_id'],
                            'reason' => $constitutionResult['reason'],
                        ]);
                        $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                        $messages[] = ['role' => 'user', 'content' => "CONSTITUTION VIOLATION [{$constitutionResult['rule_id']}]: ".
                            $constitutionResult['reason'].
                            ' Please revise your approach to comply with the agent constitution.',
                        ];

                        continue;
                    }

                    if ($constitutionResult['action'] === 'warn') {
                        $this->recordEpisode($agentId, $sessionId, 'constitution_warn',
                            "Constitution warning for '{$toolName}' [{$constitutionResult['rule_id']}]", [
                                'tool' => $toolName,
                                'rule_id' => $constitutionResult['rule_id'],
                                'iteration' => $iterations,
                            ]);
                        Log::info('AgentLoop: AG-8 constitution warn', [
                            'agent_id' => $agentId,
                            'tool' => $toolName,
                            'rule_id' => $constitutionResult['rule_id'],
                        ]);
                        // Warn but proceed
                    }

                    // Validate tool is in agent's allowed set
                    $toolStartTime = microtime(true);
                    $toolSuccess = false;
                    if (! isset($agentTools[$toolName])) {
                        $toolResultText = "Error: Tool '{$toolName}' is not available. Available tools: ".implode(', ', array_keys($agentTools));
                    } else {
                        $toolResult = $this->getToolRegistry()->executeTool($toolName, $toolParams, $toolContext);
                        $toolResultText = $toolResult['result_text'] ?? 'No output';
                        $toolSuccess = $toolResult['success'] ?? false;

                        $toolCalls[] = [
                            'tool' => $toolName,
                            'params' => $toolParams,
                            'success' => $toolSuccess,
                            'iteration' => $iterations,
                            'phase' => $currentPhaseName,
                        ];
                    }
                    $toolDurationMs = (int) round((microtime(true) - $toolStartTime) * 1000);

                    // Record tool call episode with result
                    $this->recordEpisode($agentId, $sessionId, 'tool_call', "Calling {$toolName}", [
                        'tool' => $toolName,
                        'params' => $toolParams,
                        'iteration' => $iterations,
                        'success' => $toolSuccess,
                        'duration_ms' => $toolDurationMs,
                        'tool_result' => mb_substr($toolResultText, 0, 500),
                    ]);

                    // Framework C1 — update compact run memory from tool result signals.
                    // Simple heuristic only: zero-result searches become open questions,
                    // successful *_add calls become recorded decisions. Don't try to
                    // infer memory from every tool.
                    try {
                        $runMemSid = $session['session_id'] ?? $sessionId;
                        $runMem = $this->getRunMemory();
                        $isSearch = str_starts_with($toolName, 'search_') || str_contains($toolName, '_search');
                        $isAdd = str_ends_with($toolName, '_add') || str_ends_with($toolName, '_create');

                        if ($isSearch && $toolSuccess) {
                            $lower = mb_strtolower($toolResultText);
                            $zeroResults = (
                                str_contains($lower, 'no results')
                                || str_contains($lower, 'zero results')
                                || str_contains($lower, '"results":[]')
                                || str_contains($lower, '"count":0')
                                || str_contains($lower, 'nothing found')
                            );
                            if ($zeroResults) {
                                $queryHint = '';
                                foreach (['query', 'q', 'term', 'name'] as $k) {
                                    if (! empty($toolParams[$k]) && is_scalar($toolParams[$k])) {
                                        $queryHint = (string) $toolParams[$k];
                                        break;
                                    }
                                }
                                $question = "no results from {$toolName}"
                                    .($queryHint !== '' ? " for \"{$queryHint}\"" : '');
                                $runMem->recordOpenQuestion($runMemSid, $question);
                                $runMem->updateVerificationState($runMemSid, $question, 'speculative');
                            }
                        }

                        if ($isAdd && $toolSuccess) {
                            $decision = "applied {$toolName}";
                            $evidenceBits = [];
                            foreach (['id', 'person_id', 'source_id', 'record_id'] as $k) {
                                if (! empty($toolParams[$k]) && is_scalar($toolParams[$k])) {
                                    $evidenceBits[] = "{$k}=".$toolParams[$k];
                                }
                            }
                            $runMem->recordDecision($runMemSid, $decision, $evidenceBits);
                            $runMem->updateVerificationState($runMemSid, $decision, 'confirmed');
                        }
                    } catch (\Throwable $ignore) {
                        // Non-fatal — run memory is best-effort
                    }

                    // AG-11: Selective verification — check error-prone tools
                    $verificationNote = '';
                    if ($toolSuccess) {
                        try {
                            $verifier = $this->getSelectiveVerifier();
                            if ($verifier->shouldVerify($agentId, $toolName)) {
                                $reliability = $verifier->getToolReliability($agentId, $toolName);
                                $verificationNote = "\n\n".$verifier->buildVerificationPrompt(
                                    $toolName, $toolResultText, $reliability ?? 0.5
                                );
                            }
                        } catch (\Throwable $e) {
                            // Non-fatal
                        }
                    }

                    // Add assistant response + tool result to conversation
                    $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

                    // --- Tool diversity nudge ---
                    // Every 5 iterations, remind LLM which phase tools it hasn't used yet
                    $usedToolNames = array_unique(array_column($toolCalls, 'tool'));
                    $visibleTools = $currentPhaseTools ?? $agentTools;
                    $unusedPhaseTools = array_diff(array_keys($visibleTools), $usedToolNames);
                    $diversityNudge = '';
                    if ($iterations % 5 === 0 && count($unusedPhaseTools) > 0) {
                        $diversityNudge = "\n\nDIVERSITY REMINDER: You have NOT yet used these available tools: "
                            .implode(', ', array_slice($unusedPhaseTools, 0, 6))
                            .'. Try calling one of these next instead of repeating tools you already used.';
                    }

                    $messages[] = ['role' => 'user', 'content' => $this->buildToolResultMessage(
                        $toolName,
                        $toolResultText,
                        $verificationNote,
                        $diversityNudge
                    )];

                    // AG-12: Progress tracking + stall detection
                    try {
                        $tracker = $this->getProgressTracker();
                        $progress = $tracker->calculateProgress(
                            $agentId, $iterations, $maxIterations, $toolCalls,
                            $phaseNames ?? [], $currentPhaseIdx ?? 0
                        );
                        $stallCheck = $tracker->detectStall($iterations, $toolCalls);

                        if ($stallCheck['stalled']) {
                            $replanPrompt = $tracker->buildReplanPrompt(
                                $progress, $stallCheck, $currentPhaseTools ?? $agentTools
                            );
                            $messages[] = ['role' => 'user', 'content' => $replanPrompt];
                            $this->recordEpisode($agentId, $sessionId, 'stall_detected',
                                "Progress: {$progress['overall_pct']}% — {$stallCheck['reason']}", [
                                    'iteration' => $iterations,
                                    'progress' => $progress,
                                ]);
                        }

                        // Checkpoint every 5 iterations
                        if ($iterations % 5 === 0) {
                            $tracker->saveCheckpoint($sessionId, [
                                'iteration' => $iterations,
                                'progress' => $progress,
                                'tool_calls' => count($toolCalls),
                                'phase' => $currentPhaseName ?? null,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        // Non-fatal
                    }

                    // Agentic-mode adaptive timeout: extend after productive tool calls
                    if ($toolSuccess && ($options['timeout_extender'] ?? null)) {
                        $elapsedMin = (microtime(true) - $startTime) / 60;
                        $avgMinPerIteration = $elapsedMin / max(1, $iterations);
                        $remainingIterations = $maxIterations - $iterations;
                        $requestedTotal = (int) ceil($elapsedMin + ($remainingIterations * $avgMinPerIteration) + 5);
                        $this->callTimeoutExtender(
                            $options['timeout_extender'],
                            $requestedTotal,
                            "Agentic tool '{$toolName}' succeeded (iteration {$iterations}/{$maxIterations})"
                        );
                    }

                    // AG-10: Backtracking on Failure (EnCompass pattern)
                    if ($toolSuccess) {
                        $backtracker->recordSuccess($iterations, $messages, $toolName, $toolCalls);
                    } else {
                        $backtracker->recordFailure($toolName, $toolResultText);
                        if ($backtracker->shouldBacktrack()) {
                            $rollback = $backtracker->backtrack(array_keys($agentTools));
                            if ($rollback !== null) {
                                $messages = $rollback['messages'];
                                $messages[] = ['role' => 'user', 'content' => $rollback['context']];
                                $this->recordEpisode($agentId, $sessionId, 'backtrack',
                                    "Backtrack to iteration {$rollback['branchpoint_iteration']}", [
                                        'branchpoint_iteration' => $rollback['branchpoint_iteration'],
                                        'failed_tools' => $rollback['failed_tools'],
                                        'backtracks_performed' => $backtracker->getBacktracksPerformed(),
                                        'iteration' => $iterations,
                                    ]);
                                Log::info('AgentLoop: AG-10 backtrack triggered', [
                                    'agent_id' => $agentId,
                                    'backtrack_to' => $rollback['branchpoint_iteration'],
                                    'iteration' => $iterations,
                                    'failed_tools' => $rollback['failed_tools'],
                                ]);

                                continue;
                            }
                        }
                    }
                }

                // Guard: if while loop exhausted iterations without setting finalResponse,
                // use the last LLM response. This prevents empty final responses.
                if (empty($finalResponse) && ! empty($assistantContent)) {
                    $finalResponse = $assistantContent;
                    Log::info('AgentLoop: Using last assistant response as final (iterations exhausted)', [
                        'agent_id' => $agentId,
                        'iterations' => $iterations,
                        'tool_calls' => count($toolCalls),
                    ]);
                }
            }

            // Anti-hallucination guard: if agentic agent made zero tool calls
            // (not pre-screened), the response is likely fabricated — fail the run.
            if (empty($toolCalls) && ! ($options['pre_screened'] ?? false) && $iterations > 1) {
                Log::warning("AgentLoop: Zero tool calls across {$iterations} iterations — likely hallucination", [
                    'agent_id' => $agentId,
                    'iterations' => $iterations,
                    'response_length' => strlen($finalResponse),
                ]);
                $this->getSessionService()->completeSession($session['session_id']);
                $lock->release();

                return [
                    'success' => false,
                    'agent_id' => $agentId,
                    'session_id' => $session['session_id'],
                    'error' => 'Zero tool calls — response likely fabricated',
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                    'tokens_used' => $totalTokens,
                    'tool_calls' => [],
                    'iterations' => $iterations,
                ];
            }

            $finalResponse = $this->stabilizeFinalResponse($agentId, $finalResponse, $messages, $toolCalls);

            // Store final assistant response in session
            $this->getSessionService()->addMessage($session['session_id'], 'assistant', $finalResponse);

            // 9. Auto-index findings to RAG (with agent_id scoping)
            if (($options['index_findings'] ?? true) && strlen($finalResponse) > 100) {
                $this->indexFindings($agentId, $task, $finalResponse, $options);
            }

            // 10. Record completion episode (with RLM metrics if any)
            $durationMs = round((microtime(true) - $startTime) * 1000);
            $episodeDetails = [
                'tokens_used' => $totalTokens,
                'duration_ms' => $durationMs,
                'model' => $response['model'] ?? 'unknown',
                'iterations' => $iterations,
                'tool_calls' => count($toolCalls),
            ];

            // RLM: Attach recursion metrics from this session if any sub-calls were made
            try {
                $rlmStats = DB::selectOne(
                    'SELECT COUNT(*) as calls, SUM(tokens_used) as tokens, ROUND(AVG(novelty_score), 4) as avg_novelty,
                            SUM(CASE WHEN move_on_triggered = 1 THEN 1 ELSE 0 END) as move_ons
                     FROM agent_recursion_calls WHERE session_id = ?',
                    [$sessionId]
                );
                if ($rlmStats && $rlmStats->calls > 0) {
                    $episodeDetails['rlm_sub_calls'] = (int) $rlmStats->calls;
                    $episodeDetails['rlm_tokens'] = (int) $rlmStats->tokens;
                    $episodeDetails['rlm_avg_novelty'] = (float) $rlmStats->avg_novelty;
                    $episodeDetails['rlm_move_ons'] = (int) $rlmStats->move_ons;
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            $this->recordEpisode($agentId, $sessionId, 'task_completed', $finalResponse, $episodeDetails);

            // 11. Auto-capture procedural memory from successful tool sequences
            if (($options['capture_procedural_memory'] ?? true) && ! empty($toolCalls) && count($toolCalls) >= 2) {
                try {
                    $this->getProceduralMemory()->captureFromSession(
                        $agentId, $session['session_id'], $task, $toolCalls
                    );
                } catch (\Throwable $e) {
                    Log::debug('AgentLoop: Procedural capture failed (non-fatal)', ['error' => $e->getMessage()]);
                }
            }

            // 11b. Distill episodic memory from this run
            if ($options['capture_episodic_memory'] ?? true) {
                try {
                    $episodeSummaryId = $this->getEpisodicMemory()->distillRunEpisodes($agentId, $session['session_id'], $task, [
                        'success' => true,
                        'tokens_used' => $totalTokens,
                        'duration_ms' => $durationMs,
                        'tool_calls' => $toolCalls,
                        'response' => $finalResponse,
                        'hybrid_metrics' => $capturedHybridMetrics ?? [],
                    ]);

                    // Persist hybrid run metrics to episode summary for tracing
                    if (! empty($capturedHybridMetrics) && ! empty($episodeSummaryId)) {
                        try {
                            // Add elapsed time to metrics snapshot
                            $capturedHybridMetrics['elapsed_seconds'] = (int) round($durationMs / 1000);
                            DB::update('UPDATE agent_episode_summaries SET notes = ? WHERE id = ?',
                                [json_encode($capturedHybridMetrics), $episodeSummaryId]);
                        } catch (\Throwable $e) {
                            Log::debug('AgentLoop: Failed to persist hybrid metrics to episode summary', ['error' => $e->getMessage()]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::debug('AgentLoop: Episodic distillation failed (non-fatal)', ['error' => $e->getMessage()]);
                }
            }

            // 12. Send notification if requested
            if ($options['notify'] ?? false) {
                $this->notifyCompletion($agentId, $task, $finalResponse, $durationMs, $toolCalls);
            }

            $result = [
                'success' => true,
                'agent_id' => $agentId,
                'session_id' => $session['session_id'],
                'response' => $finalResponse,
                'tokens_used' => $totalTokens,
                'duration_ms' => $durationMs,
                'model' => $response['model'] ?? 'unknown',
                'iterations' => $iterations,
                'tool_calls' => $toolCalls,
            ];

            // Record adaptive mode outcome for continuous learning (S20)
            if ($adaptiveSelectionId && ($options['record_adaptive_outcome'] ?? true)) {
                try {
                    app(AdaptiveModeService::class)->recordOutcome(
                        $adaptiveSelectionId,
                        true,
                        $durationMs,
                        $totalTokens
                    );
                } catch (\Throwable $e) {
                    Log::debug('AgentLoopService: adaptive mode outcome recording failed', ['error' => $e->getMessage()]);
                }
            }

            Log::info('AgentLoop: Execution completed', [
                'agent_id' => $agentId,
                'session_id' => $session['session_id'],
                'duration_ms' => $durationMs,
                'tokens' => $totalTokens,
                'iterations' => $iterations,
                'tool_calls' => count($toolCalls),
            ]);

            // Mark session as completed now that execution succeeded
            try {
                $this->getSessionService()->completeSession($session['session_id']);
            } catch (\Throwable $ignore) {
                Log::debug('AgentLoopService: session completion failed after success', ['session' => $session['session_id'], 'error' => $ignore->getMessage()]);
            }

            return $result;

        } catch (Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000);

            // Record adaptive mode failure outcome (S20)
            if (! empty($adaptiveSelectionId)) {
                try {
                    app(AdaptiveModeService::class)->recordOutcome($adaptiveSelectionId, false, $durationMs, 0);
                } catch (\Throwable $ignore) {
                    Log::debug('AgentLoopService: adaptive mode failure outcome recording failed', ['error' => $ignore->getMessage()]);
                }
            }

            $this->recordEpisode($agentId, $sessionId, 'error', $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500),
                'duration_ms' => $durationMs,
            ]);

            // Distill episodic memory from failed run
            try {
                $this->getEpisodicMemory()->distillRunEpisodes($agentId, $sessionId, $task ?? 'unknown', [
                    'success' => false,
                    'duration_ms' => $durationMs,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignore) {
                Log::debug('AgentLoopService: episodic memory distillation failed', ['error' => $ignore->getMessage()]);
            }

            Log::error('AgentLoop: Execution failed', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            // Mark session as completed even on failure (the run is over)
            try {
                $this->getSessionService()->completeSession($sessionId);
            } catch (\Throwable $ignore) {
                Log::debug('AgentLoopService: session completion failed after error', ['session' => $sessionId ?? null, 'error' => $ignore->getMessage()]);
            }

            return [
                'success' => false,
                'agent_id' => $agentId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ];

        } finally {
            // Ensure session is marked completed even when process is killed (SIGALRM/stall)
            // completeSession() is idempotent — only updates WHERE status='active'
            try {
                $this->getSessionService()->completeSession($sessionId);
            } catch (\Throwable $ignore) {
                // Best-effort — process may be mid-termination
            }

            // Framework C1 — clear compact run-memory slice on success OR failure.
            try {
                $runMemorySid = $session['session_id'] ?? $sessionId;
                if ($runMemorySid) {
                    $this->getRunMemory()->clear($runMemorySid);
                }
            } catch (\Throwable $ignore) {
                // Best-effort
            }

            AIService::clearAgentModelRole();
            $lock->release();
        }
    }

    private function emitProgressCallback(array $options, string $event, array $details = []): void
    {
        $callback = $options['progress_callback'] ?? null;
        if (! is_callable($callback)) {
            return;
        }

        try {
            $callback($event, $details);
        } catch (\Throwable $e) {
            Log::debug('AgentLoop: Progress callback failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Execute deterministic workflow: tools called in declared order
     *
     * Workflow steps format: "tool_name|Label|key=value,key2=value2"
     *
     * @return array Array of step results [{tool, label, success, result_text}]
     */
    private function executeDeterministicWorkflow(
        string $agentId,
        string $sessionId,
        array $steps,
        array $agentTools,
        array $toolContext,
        array &$toolCalls
    ): array {
        $results = [];

        foreach ($steps as $stepStr) {
            $parts = explode('|', $stepStr);
            $toolName = trim($parts[0]);
            $label = trim($parts[1] ?? $toolName);
            $paramsStr = trim($parts[2] ?? '');

            // Parse override params (key=value,key2=value2)
            $overrideParams = [];
            if ($paramsStr) {
                foreach (explode(',', $paramsStr) as $pair) {
                    $kv = explode('=', $pair, 2);
                    if (count($kv) === 2) {
                        $overrideParams[trim($kv[0])] = trim($kv[1]);
                    }
                }
            }

            Log::info('AgentLoop: Deterministic step', [
                'agent_id' => $agentId,
                'tool' => $toolName,
                'label' => $label,
            ]);

            $this->recordEpisode($agentId, $sessionId, 'tool_call', $label, [
                'tool' => $toolName,
                'params' => $overrideParams,
                'mode' => 'deterministic',
            ]);

            if (! isset($agentTools[$toolName])) {
                $errorMsg = "Tool '{$toolName}' not available";
                Log::warning('AgentLoop: Deterministic step skipped', ['agent_id' => $agentId, 'tool' => $toolName, 'reason' => $errorMsg]);
                $toolCalls[] = ['tool' => $toolName, 'params' => $overrideParams, 'success' => false, 'iteration' => count($results) + 1];
                $results[] = ['tool' => $toolName, 'label' => $label, 'success' => false, 'result_text' => $errorMsg];

                continue;
            }

            // Check if required params can be satisfied from override params + context
            $mergedAvailable = array_merge($toolContext, $overrideParams);
            $unsatisfied = $this->getUnsatisfiedRequiredParams($agentTools[$toolName], $mergedAvailable);
            if (! empty($unsatisfied)) {
                $errorMsg = 'Skipped: required parameters ['.implode(', ', $unsatisfied).'] not available in deterministic mode';
                Log::debug('AgentLoop: Deterministic step skipped (unsatisfied params)', [
                    'agent_id' => $agentId, 'tool' => $toolName, 'missing' => $unsatisfied,
                ]);
                $toolCalls[] = ['tool' => $toolName, 'params' => $overrideParams, 'success' => false, 'iteration' => count($results) + 1];
                $results[] = ['tool' => $toolName, 'label' => $label, 'success' => false, 'result_text' => $errorMsg];

                continue;
            }

            try {
                $toolResult = $this->getToolRegistry()->executeTool($toolName, $overrideParams, $toolContext);

                $toolCalls[] = [
                    'tool' => $toolName,
                    'params' => $overrideParams,
                    'success' => $toolResult['success'],
                    'iteration' => count($results) + 1,
                ];

                $results[] = [
                    'tool' => $toolName,
                    'label' => $label,
                    'success' => $toolResult['success'],
                    'result_text' => $toolResult['result_text'] ?? ($toolResult['error'] ?? 'No output'),
                ];
            } catch (\Throwable $e) {
                $errorMsg = 'Exception: '.$e->getMessage();
                Log::error('AgentLoop: Tool execution exception', ['agent_id' => $agentId, 'tool' => $toolName, 'error' => $errorMsg]);
                $toolCalls[] = ['tool' => $toolName, 'params' => $overrideParams, 'success' => false, 'iteration' => count($results) + 1];
                $results[] = ['tool' => $toolName, 'label' => $label, 'success' => false, 'result_text' => $errorMsg];
            }
        }

        return $results;
    }

    /**
     * Hybrid workflow: framework drives tool execution, LLM analyzes between phases.
     *
     * For each phase defined in tool_phases:
     * 1. Execute ALL tools in the phase deterministically (no LLM choice)
     * 2. Send results to LLM for analysis and direction
     * 3. If LLM identifies specific items (e.g. person IDs), use them in next phase
     * 4. Final LLM call synthesizes all phase results into report
     */
    private function executeHybridWorkflow(
        string $agentId, string $sessionId, string $task, array $skillConfig,
        string $skillInstructions, array $agentTools, array $toolPhases,
        array $toolContext, array &$toolCalls, string $systemPrompt,
        array $messages, ?string $model, bool $useAutoSelect,
        float $temperature, bool $hasSensitivePerms, array $options
    ): string {
        $phaseNames = array_keys($toolPhases);
        $phaseResults = [];
        $allToolResults = [];
        $modelRole = $skillConfig['model_role'] ?? 'standard'; // N119b: quality/standard/fast
        $cascadeConfig = $skillConfig['cascade'] ?? null; // AI-1: cascade opt-in
        $reportMode = $skillConfig['report_mode'] ?? 'person_research';
        $isOperationalMode = $reportMode === 'operational';

        // FRAMEWORK RULE (N87): Entity caps in hybrid agents MUST be computed from job timeout —
        // never hardcoded. Formula: floor(timeout × 0.70 / estimated_min_per_entity).
        // 0.70 = usable fraction after overhead. Estimated 2.5 min/entity for research+analyze.
        // Floor 3 (minimum viable run), ceil 20 (prevent runaway on large entity sets).
        // ScheduledJobService passes timeout_minutes from scheduled_jobs into $options.
        // This pattern applies to ALL hybrid-mode agents, not just genealogy.
        $timeoutMinutes = $options['timeout_minutes'] ?? config('agents.hybrid_default_timeout', 44);
        $overhead = config('agents.hybrid_overhead_fraction', 0.70);
        $minPerEntity = config('agents.hybrid_minutes_per_entity', 2.5);
        $entityFloor = config('agents.hybrid_entity_floor', 3);
        $entityCeil = config('agents.hybrid_entity_ceil', 20);
        $maxPersonsPerRun = max($entityFloor, min($entityCeil, (int) floor($timeoutMinutes * $overhead / $minPerEntity)));
        $toolContext['max_persons_per_run'] = $maxPersonsPerRun;

        // N119c: Phase time budgeting — track elapsed time and reserve time for report phase
        $workflowStartTime = microtime(true);
        $timeoutSeconds = $timeoutMinutes * 60;
        $reportReserveSeconds = config('agents.hybrid_report_reserve_seconds', 300); // 5min for report
        $phaseQualityMetrics = []; // Track quality per phase for run summary

        // Initialize hybrid run metrics for Pushover summary
        $this->hybridRunMetrics = [
            'agent_id' => $agentId,
            'total_phases' => count($phaseNames),
            'phases_completed' => 0,
            'phases_skipped' => [],
            'phase_providers' => [],        // phaseName => provider string
            'template_detections' => 0,
            'claude_escalations' => 0,
            'review_items_submitted' => 0,
            'review_item_types' => [],      // change_type => count
            'proposals_filtered' => 0,      // vague/duplicate filtered
            'timeout_minutes' => $timeoutMinutes,
            'start_time' => $workflowStartTime,
        ];

        // N120: Per-person iteration mode — process one person at a time through all phases.
        // Assess phase runs in batch (needs full tree context), then each person gets
        // research → analyze → report individually. Controlled by SKILL.md iteration_mode.
        $iterationMode = $skillConfig['iteration_mode'] ?? 'batch';
        if ($iterationMode === 'per_person' && count($phaseNames) >= 2) {
            return $this->executePerPersonHybridWorkflow(
                $agentId, $sessionId, $task, $skillConfig, $skillInstructions,
                $agentTools, $toolPhases, $toolContext, $toolCalls, $systemPrompt,
                $messages, $model, $useAutoSelect, $temperature, $hasSensitivePerms,
                $options, $phaseNames, $maxPersonsPerRun, $workflowStartTime,
                $timeoutSeconds, $reportReserveSeconds, $modelRole, $cascadeConfig
            );
        }

        foreach ($phaseNames as $phaseIdx => $phaseName) {
            $elapsedSeconds = microtime(true) - $workflowStartTime;
            $remainingSeconds = $timeoutSeconds - $elapsedSeconds;
            $isLastPhase = ($phaseIdx === count($phaseNames) - 1);
            $phasesRemaining = count($phaseNames) - $phaseIdx;

            // N119c: If not enough time for this phase + report, skip to report
            if (! $isLastPhase && $remainingSeconds < $reportReserveSeconds + 60) {
                Log::warning('AgentLoop: Skipping to report phase — insufficient time', [
                    'agent_id' => $agentId,
                    'skipped_phase' => $phaseName,
                    'elapsed_seconds' => (int) $elapsedSeconds,
                    'remaining_seconds' => (int) $remainingSeconds,
                    'report_reserve' => $reportReserveSeconds,
                ]);
                // Record skipped phases for run summary
                for ($si = $phaseIdx; $si < count($phaseNames) - 1; $si++) {
                    $this->hybridRunMetrics['phases_skipped'][] = $phaseNames[$si];
                }
                // Jump to report phase (last phase)
                $phaseName = $phaseNames[count($phaseNames) - 1];
                $phaseIdx = count($phaseNames) - 1;
                $isLastPhase = true;
            }

            $phaseToolNames = $toolPhases[$phaseName] ?? [];
            $phaseTools = $this->getToolRegistry()->getToolsForPhase($agentTools, $phaseToolNames);

            Log::info('AgentLoop: Hybrid phase starting', [
                'agent_id' => $agentId,
                'phase' => $phaseName,
                'tools' => array_keys($phaseTools),
                'elapsed_seconds' => (int) $elapsedSeconds,
                'remaining_seconds' => (int) $remainingSeconds,
            ]);

            $this->recordEpisode($agentId, $sessionId, 'phase_started',
                "Hybrid phase '{$phaseName}' — executing ".count($phaseTools).' tools', [
                    'phase' => $phaseName,
                    'tools' => array_keys($phaseTools),
                ]);

            // Execute every tool in this phase with smart parameter injection
            $phaseToolResults = [];

            // Get target persons from previous phase's LLM analysis
            $targetPersons = $toolContext['persons_found'] ?? [];
            $phaseTargets = $toolContext['phase_targets'] ?? [];

            foreach ($phaseToolNames as $toolName) {
                if (! isset($agentTools[$toolName])) {
                    continue; // Tool not available for this agent
                }

                $toolDef = $agentTools[$toolName];
                $toolParams = $toolDef['parameters'] ?? [];

                // Determine if this tool needs per-person execution
                $needsPersonId = isset($toolParams['person_id']['required']) && $toolParams['person_id']['required']
                    || isset($toolParams['personId']['required']) && $toolParams['personId']['required'];
                $needsQuery = isset($toolParams['query']['required']) && $toolParams['query']['required']
                    || isset($toolParams['name']['required']) && $toolParams['name']['required'];
                $needsSurname = isset($toolParams['surname']['required']) && $toolParams['surname']['required'];

                // Build call list: per-person or single call
                $callConfigs = [];

                // Special handling for report-phase tools that need structured input
                if ($phaseName === 'report') {
                    // Skip propose_change/propose_relationship in report phase tool execution —
                    // proposals are extracted from the LLM's final_report JSON, not from
                    // framework-driven tool calls (which can't synthesize the required params).
                    if (in_array($toolName, ['propose_change', 'propose_relationship'])) {
                        continue;
                    }
                    $params = $this->buildReportToolParams($toolName, $agentId, $targetPersons, $phaseResults);
                    if (! empty($params)) {
                        $callConfigs[] = ['params' => $params, 'label' => ''];
                    }
                    // If buildReportToolParams returns [], skip tool — it has required params we cannot synthesize
                } elseif (($needsPersonId || $needsQuery || $needsSurname) && ! empty($targetPersons)) {
                    // Call once per target person — cap derived from job timeout (self-adjusting).
                    $personsToProcess = array_slice($targetPersons, 0, $maxPersonsPerRun);
                    foreach ($personsToProcess as $person) {
                        $params = [];
                        $personId = $person['id'] ?? null;
                        $personName = $person['name'] ?? '';
                        $personSurname = trim(explode(' ', $personName)[count(explode(' ', $personName)) - 1] ?? '');

                        // Always pass person_id for per-person calls (metrics tracking + tools that accept it)
                        if ($personId) {
                            $params['person_id'] = (int) $personId;
                            $params['personId'] = (int) $personId;
                        }
                        if ($needsQuery && $personName) {
                            $params['query'] = $personName;
                            $params['name'] = $personName;
                        }
                        if ($needsSurname && $personSurname) {
                            $params['surname'] = $personSurname;

                            // Enrich search tools with available person data (given name, dates, location)
                            $nameParts = explode(' ', trim($personName));
                            $givenName = count($nameParts) > 1 ? $nameParts[0] : null;
                            if ($givenName && isset($toolParams['given_name'])) {
                                $params['given_name'] = $givenName;
                            }
                            $birthYear = $person['birth_year'] ?? null;
                            if ($birthYear && isset($toolParams['birth_year'])) {
                                $params['birth_year'] = (int) $birthYear;
                            }
                            $deathYear = $person['death_year'] ?? null;
                            if ($deathYear && isset($toolParams['death_year'])) {
                                $params['death_year'] = (int) $deathYear;
                            }
                            $birthPlace = $person['birth_place'] ?? null;
                            if ($birthPlace) {
                                if (isset($toolParams['birth_place'])) {
                                    $params['birth_place'] = $birthPlace;
                                }
                                if (isset($toolParams['state']) && strlen($birthPlace) <= 20) {
                                    $params['state'] = $birthPlace;
                                }
                            }
                        }

                        // Add event type for evidence_build_chain
                        if ($toolName === 'evidence_build_chain') {
                            $params['eventType'] = 'birth';
                        }

                        // Synthesize question for generate_gps_proof
                        if ($toolName === 'generate_gps_proof') {
                            $params['question'] = "What are the established facts and outstanding questions for {$personName}?";
                        }

                        // Inject defaults for update_search_coverage — framework records general cycle coverage
                        if ($toolName === 'update_search_coverage') {
                            $params['positive'] = false;
                            $params['repository_name'] = 'General Research Cycle';
                            $params['repository_type'] = 'other';
                            $params['notes'] = "Automated hybrid research cycle for {$personName}";
                        }

                        $label = $personName ? " for {$personName}" : " (person {$personId})";
                        $callConfigs[] = ['params' => $params, 'label' => $label];
                    }
                } else {
                    // Special case: memory recall tools need query synthesized from task context
                    if (in_array($toolName, ['recall_procedures', 'recall_episodes'])) {
                        $taskQuery = $toolContext['task'] ?? "genealogy research tree {$toolContext['tree_id']}";
                        $callConfigs[] = ['params' => ['query' => $taskQuery], 'label' => ''];
                    } else {
                        // Single call with no person-specific params — but first check
                        // if the tool has required params that can't be satisfied from context.
                        // Tools like handoff_to_agent (needs reason), route_task (needs task_type),
                        // and mcp_searxng_search (needs query) would fail with empty params.
                        $unsatisfied = $this->getUnsatisfiedRequiredParams($toolDef, $toolContext);
                        if (! empty($unsatisfied)) {
                            Log::debug('AgentLoop: Hybrid skipping tool (unsatisfied required params)', [
                                'agent_id' => $agentId,
                                'phase' => $phaseName,
                                'tool' => $toolName,
                                'missing' => $unsatisfied,
                            ]);
                            $phaseToolResults[$toolName] = [
                                'success' => false,
                                'result_text' => 'Skipped: required parameters ['.implode(', ', $unsatisfied).'] not available in framework-driven mode',
                            ];

                            continue;
                        }
                        $callConfigs[] = ['params' => [], 'label' => ''];
                    } // end else (non-memory-recall single-call tools)
                }

                foreach ($callConfigs as $callConfig) {
                    $callParams = $callConfig['params'];
                    $callLabel = $callConfig['label'];

                    $toolStartTime = microtime(true);
                    try {
                        $toolResult = $this->getToolRegistry()->executeTool($toolName, $callParams, $toolContext);
                        $resultText = $toolResult['result_text'] ?? 'No output';
                        $success = $toolResult['success'] ?? false;
                    } catch (\Throwable $e) {
                        $resultText = 'Error: '.$e->getMessage();
                        $success = false;
                    }
                    $toolDurationMs = (int) round((microtime(true) - $toolStartTime) * 1000);

                    $this->recordEpisode($agentId, $sessionId, 'tool_call', "Calling {$toolName}{$callLabel}", [
                        'tool' => $toolName,
                        'phase' => $phaseName,
                        'mode' => 'hybrid',
                        'params' => $callParams,
                        'success' => $success,
                        'duration_ms' => $toolDurationMs,
                        'tool_result' => mb_substr($resultText, 0, 500),
                    ]);

                    $toolCalls[] = [
                        'tool' => $toolName,
                        'params' => $callParams,
                        'success' => $success,
                        'phase' => $phaseName,
                    ];

                    $resultKey = $callLabel ? "{$toolName}{$callLabel}" : $toolName;
                    $phaseToolResults[$resultKey] = [
                        'success' => $success,
                        'result_text' => $resultText,
                    ];
                }
            }

            $allToolResults[$phaseName] = $phaseToolResults;

            // Build phase analysis prompt
            $phasePrompt = "## Phase '{$phaseName}' Results\n\n";
            foreach ($phaseToolResults as $tn => $tr) {
                $status = $tr['success'] ? 'OK' : 'FAILED';
                $phasePrompt .= "### {$tn} [{$status}]\n{$tr['result_text']}\n\n";
            }

            // Ask LLM to analyze this phase's results with structured output
            if ($phaseIdx < count($phaseNames) - 1) {
                $nextPhase = $phaseNames[$phaseIdx + 1];
                if ($isOperationalMode) {
                    $phasePrompt .= "Analyze the above operational results. You MUST respond with valid JSON in this exact format:\n"
                        ."```json\n{\n"
                        ."  \"phase\": \"{$phaseName}\",\n"
                        ."  \"status\": \"<healthy|degraded|critical>\",\n"
                        ."  \"key_findings\": [\"<string>\"],\n"
                        ."  \"next_phase_targets\": [{\"action\": \"<string>\", \"reason\": \"<string>\"}],\n"
                        ."  \"issues\": [\"<string>\"],\n"
                        ."  \"tool_failures\": [\"<tool_name that failed>\"]\n"
                        ."}\n```\n"
                        .'CRITICAL: Use ONLY real operational findings from the tool results above. '
                        .'Do NOT invent people, placeholder data, or genealogy proposals. '
                        ."Keep next_phase_targets focused on operational follow-up actions for '{$nextPhase}'.";
                } else {
                    $phasePrompt .= "Analyze the above results. You MUST respond with valid JSON in this exact format:\n"
                        ."```json\n{\n"
                        ."  \"phase\": \"{$phaseName}\",\n"
                        ."  \"persons_found\": [{\"id\": <number>, \"name\": \"<full name string>\", \"surname\": \"<last name>\", \"birth_year\": <number or null>, \"death_year\": <number or null>, \"birth_place\": \"<state/place or null>\", \"findings\": \"<string>\"}],\n"
                        ."  \"key_findings\": [\"<string>\"],\n"
                        ."  \"next_phase_targets\": [{\"person_id\": <number>, \"action\": \"<string>\"}],\n"
                        ."  \"issues\": [\"<string>\"],\n"
                        ."  \"tool_failures\": [\"<tool_name that failed>\"]\n"
                        ."}\n```\n"
                        ."CRITICAL: Use ONLY real person IDs and names from the tool results above. Do NOT invent names like 'John Doe' or 'Jane Smith'. "
                        ."Do NOT generate sample, example, or placeholder data. Every person_id must come from the actual tool output.\n"
                        ."Include ALL persons found in the data. List specific person IDs and actions for the next phase ('{$nextPhase}'). "
                        ."Include UP TO {$maxPersonsPerRun} persons in next_phase_targets (time budget for this run).";
                }
            } else {
                // Collect concrete findings from previous phases to feed into proposal generation
                $priorFindings = [];
                if (! $isOperationalMode) {
                    foreach ($phaseResults as $prPhase => $prResult) {
                        $prJson = null;
                        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $prResult, $prMatches)) {
                            $prJson = json_decode($prMatches[1], true);
                        }
                        if ($prJson) {
                            foreach ($prJson['persons_found'] ?? [] as $pf) {
                                if (! is_array($pf)) {
                                    continue;
                                } // LLM sometimes returns string elements
                                $pid = $pf['id'] ?? null;
                                if ($pid && ! empty($pf['findings'])) {
                                    $priorFindings[$pid] = [
                                        'id' => $pid,
                                        'name' => $pf['name'] ?? 'Unknown',
                                        'findings' => $pf['findings'] ?? '',
                                    ];
                                }
                            }
                        }
                    }
                }
                $findingsSummary = '';
                if (! empty($priorFindings)) {
                    $findingsSummary = "\n\nFINDINGS FROM PRIOR PHASES (use these to build proposals):\n";
                    foreach ($priorFindings as $pf) {
                        $findingsSummary .= "- Person #{$pf['id']} ({$pf['name']}): {$pf['findings']}\n";
                    }
                }

                if ($isOperationalMode) {
                    $phasePrompt .= "\n\n=== FINAL REPORT ===\n"
                        ."IMPORTANT: This is the FINAL phase. Generate a NEW JSON object (do NOT reuse previous phase output).\n"
                        ."The phase MUST be \"final_report\".\n"
                        ."CRITICAL: Output ONLY the JSON. Do NOT generate sample, placeholder, or genealogy-style person/proposal data.\n\n"
                        ."\nYou MUST respond with valid JSON:\n"
                        ."```json\n{\n"
                        ."  \"phase\": \"final_report\",\n"
                        ."  \"status\": \"<healthy|degraded|critical>\",\n"
                        ."  \"summary\": \"<concise narrative summary of operational state>\",\n"
                        ."  \"key_findings\": [\"<string>\"],\n"
                        ."  \"findings\": [{\"title\": \"<short issue title>\", \"severity\": \"<critical|high|medium|low>\", \"summary\": \"<what happened>\", \"action\": \"<recommended action or null>\"}],\n"
                        ."  \"issues\": [\"<string>\"],\n"
                        ."  \"follow_up_tasks\": [\"<string>\"],\n"
                        ."  \"total_tools_used\": <number>,\n"
                        ."  \"total_findings\": <number>\n"
                        ."}\n```\n\n"
                        ."CRITICAL RULES:\n"
                        ."- findings MUST describe operational issues only.\n"
                        ."- severity MUST be one of critical/high/medium/low.\n"
                        ."- Do NOT include persons_researched, persons_found, proposed_changes, proposed_relationships, or proposed_marriages.\n"
                        .'- Keep action text concise and actionable.';
                } else {
                    $phasePrompt .= "\n\n=== FINAL REPORT ===\n"
                        ."IMPORTANT: This is the FINAL phase. Generate a NEW JSON object (do NOT reuse previous phase output).\n"
                        ."The phase MUST be \"final_report\" (NOT \"research\", \"analyze\", or any other phase name).\n"
                        ."CRITICAL: Output ONLY the JSON. Do NOT say 'here is a sample', 'here is an example', or 'adjust the values'. "
                        ."Your response IS the real report with real data from the tools above. Do NOT generate placeholder or template content.\n\n"
                        .$findingsSummary
                        ."\nYou MUST respond with valid JSON:\n"
                        ."```json\n{\n"
                        ."  \"phase\": \"final_report\",\n"
                        ."  \"summary\": \"<comprehensive narrative summary of ALL findings from ALL phases>\",\n"
                        ."  \"persons_researched\": [{\"id\": <number>, \"name\": \"<string>\", \"findings\": \"<string>\", \"confidence\": <0.0-1.0>, \"recommendation\": \"<confirmed|probable|possible|needs_review>\"}],\n"
                        ."  \"proposed_changes\": [{\"person_id\": <number>, \"change_type\": \"<fact_update|event_add|source_add|media_link|notes_append|residence_add|family_event_update|external_record_link|source_create|clipping_link|media_metadata_update>\", \"field_name\": \"<birth_date|death_date|birth_place|death_place|occupation|etc or null>\", \"proposed_value\": \"<value or JSON payload>\", \"evidence_sources\": [\"<source URL or citation>\"], \"evidence_summary\": \"<what was found and where>\", \"confidence\": <0.0-1.0>}],\n"
                        ."  \"proposed_relationships\": [{\"person_id\": <existing person ID>, \"relationship_type\": \"<parent|child|sibling|spouse>\", \"proposed_name\": \"<full name>\", \"proposed_sex\": \"<M|F|null>\", \"proposed_birth_date\": \"<date or null>\", \"proposed_birth_place\": \"<place or null>\", \"proposed_death_date\": \"<date or null>\", \"proposed_death_place\": \"<place or null>\", \"evidence_summary\": \"<how relationship was determined>\", \"confidence\": <0.0-1.0>}],\n"
                        ."  \"proposed_marriages\": [{\"person1_id\": <number>, \"person2_id\": <number or null>, \"person2_name\": \"<name if new person>\", \"marriage_date\": \"<date or null>\", \"marriage_place\": \"<place or null>\", \"divorce_date\": \"<date or null>\", \"evidence_summary\": \"<string>\", \"confidence\": <0.0-1.0>}],\n"
                        ."  \"total_tools_used\": <number>,\n"
                        ."  \"total_findings\": <number>,\n"
                        ."  \"follow_up_tasks\": [\"<string>\"]\n"
                        ."}\n```\n\n"
                        ."CRITICAL RULES FOR PROPOSALS:\n"
                        ."- You MUST convert research findings into proposed_changes. This is the ENTIRE PURPOSE of this agent.\n"
                        ."- If a tool found a birth date, death date, birth place, death place, marriage, occupation, or any other fact → it MUST become a proposed_change entry.\n"
                        ."- If a source/citation was found for a person → it MUST become a proposed_change with change_type \"source_add\".\n"
                        ."- If a new relative was discovered → it MUST become a proposed_relationship entry.\n"
                        ."- If a marriage/spouse was found → it MUST become a proposed_marriages entry.\n"
                        ."- DO NOT return empty proposed_changes if you found ANY facts about ANY person in the research/analyze phases.\n"
                        ."- summary is REQUIRED: A detailed narrative of all findings.\n"
                        ."- Cover ALL persons from ALL phases.\n\n"
                        ."EXAMPLE — If research found John Smith born 1864 in census and died 1920 from obituary:\n"
                        ."\"proposed_changes\": [\n"
                        ."  {\"person_id\": 42, \"change_type\": \"fact_update\", \"field_name\": \"birth_date\", \"proposed_value\": \"1864\", \"evidence_sources\": [\"1870 US Census\"], \"evidence_summary\": \"Census shows John Smith age 6 in 1870, born ~1864\", \"confidence\": 0.7},\n"
                        ."  {\"person_id\": 42, \"change_type\": \"fact_update\", \"field_name\": \"death_date\", \"proposed_value\": \"15 Mar 1920\", \"evidence_sources\": [\"Springfield Daily News obituary\"], \"evidence_summary\": \"Obituary published March 16, 1920\", \"confidence\": 0.8},\n"
                        ."  {\"person_id\": 42, \"change_type\": \"source_add\", \"field_name\": null, \"proposed_value\": \"https://ancestry.com/census/1870/record/12345\", \"evidence_sources\": [\"1870 US Census\"], \"evidence_summary\": \"Located in 1870 census household\", \"confidence\": 0.7}\n"
                        ."]\n"
                        ."IMPORTANT: source_add proposed_value MUST be a URL (https://...) or numeric source_id. NEVER put narrative text. Use notes_append for text findings.\n"
                        ."Use fact_update for birth_date, death_date, birth_place, death_place, occupation.\n"
                        ."EXTENDED CHANGE TYPES:\n"
                        ."- notes_append: proposed_value = plain text narrative. CRITICAL: ALWAYS use notes_append for conflicting evidence, research leads, and negative findings regardless of confidence. Never suppress findings.\n"
                        ."- residence_add: proposed_value = JSON: {\"residence_date\":\"1880\",\"place\":\"Lancaster Co, PA\",\"source_id\":null}\n"
                        ."- family_event_update: proposed_value = JSON: {\"family_id\":123,\"marriage_date\":\"15 Jun 1885\",\"marriage_place\":\"Philadelphia, PA\"}\n"
                        ."- external_record_link: proposed_value = JSON: {\"service_type\":\"familysearch\",\"external_id\":\"K2X3-ABC\",\"record_type\":\"census\",\"match_confidence\":0.85}\n"
                        ."- source_create: proposed_value = JSON: {\"title\":\"1870 US Federal Census\",\"repository\":\"NARA\",\"url\":\"https://...\",\"source_quality\":\"original\"}\n"
                        ."- clipping_link: proposed_value = JSON: {\"clipping_id\":456,\"relevance_type\":\"subject\",\"confidence\":0.8}\n"
                        .'- media_metadata_update: proposed_value = JSON: {"media_id":789,"title":"Wedding photo","media_date":"1890"}';
                }
            }

            $messages[] = ['role' => 'user', 'content' => $phasePrompt];

            // LLM analysis call with retry on validation failure
            // N119b: Report phase needs more tokens for 12-person JSON with proposed_changes.
            // Non-report phases use standard max_tokens; report phase gets 2x to avoid truncation.
            $isReportPhase = ($phaseIdx === count($phaseNames) - 1);
            $phaseMaxTokens = $isReportPhase
                ? config('agents.context_max_tokens', 4000) * 2
                : config('agents.context_max_tokens', 4000);
            $aiOptions = [
                'system' => $systemPrompt,
                'system_prompt' => $systemPrompt,
                'temperature' => $temperature,
                'max_tokens' => $phaseMaxTokens,
                'ai_timeout' => $this->getAgentAiTimeout($skillConfig, $options),
                'sensitive_data' => $hasSensitivePerms,
                // N119: Disable semantic cache for hybrid phase calls — each phase has
                // unique tool results that must be analyzed fresh. Cached responses from
                // prior phases poison subsequent phases with stale assess-phase data.
                'use_cache' => false,
            ];

            if ($model) {
                $aiOptions['model'] = $model;
            } elseif ($useAutoSelect) {
                $aiOptions['skip_if_busy'] = true;
                $aiOptions['model_role'] = $modelRole; // N119b: quality role → bigger models
                // N119b: Report phase requires stronger reasoning to convert findings → proposals.
                // Use the quality tier, but let AIService route local-first before external fallback.
                if ($isReportPhase) {
                    $aiOptions['model_role'] = 'quality';
                }
            }

            $assistantContent = '';
            $validationResult = null;
            $maxRetries = config('agents.phase_max_retries', 2);
            if ($cascadeConfig !== null) {
                $aiOptions['cascade'] = $cascadeConfig;
            }

            for ($retry = 0; $retry <= $maxRetries; $retry++) {
                $response = $this->getAIService()->process(
                    $this->formatMessagesForAI($messages),
                    $aiOptions
                );

                // N75b: Detect AI provider failure
                if (empty($response['success'])) {
                    Log::warning('AgentLoop: AI call failed in batch hybrid phase', [
                        'agent_id' => $agentId,
                        'phase' => $phaseName,
                        'retry' => $retry,
                        'error' => $response['error'] ?? 'Unknown AI failure',
                    ]);
                    if ($retry < $maxRetries) {
                        $aiOptions['prefer_claude'] = true;
                        unset($aiOptions['skip_if_busy']);
                    }

                    continue;
                }

                $assistantContent = $response['content'] ?? $response['response'] ?? '';
                $responseProvider = $response['provider'] ?? 'unknown';

                // AGT-004: Track tokens from hybrid LLM calls
                $this->hybridRunMetrics['total_tokens'] = ($this->hybridRunMetrics['total_tokens'] ?? 0) + ($response['tokens'] ?? 0);

                // N119c: Detect template/placeholder/hallucinated output BEFORE validation
                $isTemplate = preg_match('/\b(sample response|adjust the values|match your actual|example response|placeholder|here is a sample|here is an example)\b/i', $assistantContent);
                $hasPlaceholderNames = preg_match('/\b(John Doe|Jane Smith|Jane Doe|John Smith)\b/', $assistantContent);

                if ($isTemplate || $hasPlaceholderNames) {
                    Log::warning('AgentLoop: Template/placeholder detected in phase response', [
                        'agent_id' => $agentId,
                        'phase' => $phaseName,
                        'provider' => $responseProvider,
                        'is_template' => (bool) $isTemplate,
                        'has_placeholders' => (bool) $hasPlaceholderNames,
                        'retry' => $retry,
                    ]);

                    // Escalate to Claude for retry
                    if ($retry < $maxRetries) {
                        $aiOptions['prefer_claude'] = true;
                        $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                        $messages[] = ['role' => 'user', 'content' => "CRITICAL ERROR: Your response contains template/placeholder content (e.g. 'John Doe', 'sample response'). "
                            .'You MUST analyze the ACTUAL tool results above and produce real findings for the real persons. '
                            .'Do NOT generate example, sample, or placeholder data. Use ONLY the actual data from tool results.',
                        ];

                        continue;
                    }
                }

                // Phase Validator: check structured output
                $validationResult = $this->validatePhaseResponse($assistantContent, $phaseName, $phaseIdx, count($phaseNames));

                if ($validationResult['valid']) {
                    break;
                }

                // Inject correction and retry
                Log::warning('AgentLoop: Phase response validation failed', [
                    'agent_id' => $agentId,
                    'phase' => $phaseName,
                    'retry' => $retry,
                    'reason' => $validationResult['reason'],
                ]);

                if ($retry < $maxRetries) {
                    $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                    $messages[] = ['role' => 'user', 'content' => "VALIDATION FAILED: {$validationResult['reason']}\n\n"
                        .'You MUST respond with valid JSON as specified. Do not include any text outside the JSON block. '
                        .'Fix your response and try again.',
                    ];
                }
            }

            // Track quality metrics for this phase's LLM call
            $this->hybridRunMetrics['phases_completed']++;
            $this->hybridRunMetrics['phase_providers'][$phaseName] = $responseProvider ?? 'unknown';
            if ($isTemplate || $hasPlaceholderNames) {
                $this->hybridRunMetrics['template_detections']++;
            }
            // Count Claude escalations triggered by template detection (not routine report-phase Claude)
            if (! $isReportPhase && isset($aiOptions['prefer_claude']) && $aiOptions['prefer_claude']) {
                $this->hybridRunMetrics['claude_escalations']++;
            }

            // Adaptive timeout: extend deadline after productive phases (batch mode)
            $batchTimeoutExtender = $options['timeout_extender'] ?? null;
            if ($batchTimeoutExtender && ! $isLastPhase && ($validationResult['valid'] ?? false)) {
                $batchElapsed = (microtime(true) - $workflowStartTime) / 60;
                $batchPhasesLeft = count($phaseNames) - $phaseIdx - 1;
                $avgMinPerPhase = $batchElapsed / ($phaseIdx + 1);
                $batchRequested = (int) ceil($batchElapsed + ($batchPhasesLeft * $avgMinPerPhase) + 5);
                $this->callTimeoutExtender($batchTimeoutExtender, $batchRequested,
                    "Batch phase '{$phaseName}' productive, {$batchPhasesLeft} phases remaining");
            }

            // Final phase fallback: if retries exhausted and we have findings but no proposals,
            // accept the response and auto-synthesize proposals from findings text.
            // Better to produce low-confidence proposals for human review than lose all research.
            if ($phaseIdx === count($phaseNames) - 1 && $validationResult && ! $validationResult['valid']) {
                // Try to extract JSON even though validation failed
                $fallbackJson = null;
                if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $assistantContent, $fbMatches)) {
                    $fallbackJson = json_decode($fbMatches[1], true);
                } elseif (preg_match('/^\s*(\{[\s\S]*\})\s*$/m', $assistantContent, $fbMatches)) {
                    $fallbackJson = json_decode($fbMatches[1], true);
                }
                if ($fallbackJson) {
                    $fbPersons = $fallbackJson['persons_researched'] ?? $fallbackJson['persons_found'] ?? [];
                    $synthesized = [];
                    foreach ($fbPersons as $p) {
                        if (! is_array($p)) {
                            continue;
                        } // LLM sometimes returns string elements
                        $findings = $p['findings'] ?? '';
                        if (! is_string($findings)) {
                            $findings = is_array($findings) ? implode('; ', array_filter($findings)) : (string) $findings;
                        }
                        $pid = $p['id'] ?? null;
                        // N119b: Strip assess-phase metadata from findings before evaluation.
                        // Findings often start with "Tier 1 bloodline ancestor, priority rank N, ..."
                        // followed by actual research data after a pipe separator or period.
                        $cleanFindings = $this->stripAssessMetadata($findings);
                        $findingsTrimmed = trim($cleanFindings);
                        if (! $pid || strlen($findingsTrimmed) < 30) {
                            continue;
                        }
                        // Reject if findings indicate data belongs to a different person
                        if (preg_match('/likely (for )?a different (person|individual)/i', $findingsTrimmed)) {
                            continue;
                        }
                        // Reject pure gap descriptions
                        if (preg_match('/^(no |none|nothing|zero|empty)/i', $findingsTrimmed)) {
                            continue;
                        }
                        // N119b: Only produce notes_append — no spacy/regex fact extraction.
                        // Fallback findings are research suggestions (record types that exist),
                        // not confirmed facts. Spacy extracts false-positive places from source
                        // names (e.g. "Württemberg" from "Württemberg, Germany, Lutheran Baptisms").
                        $synthesized[] = [
                            'person_id' => (int) $pid,
                            'change_type' => 'notes_append',
                            'field_name' => null,
                            'proposed_value' => substr($findingsTrimmed, 0, 500),
                            'evidence_sources' => ['agent-synthesized from research findings'],
                            'evidence_summary' => $findingsTrimmed,
                            'confidence' => 0.4,
                        ];
                    }
                    if (! empty($synthesized)) {
                        $fallbackJson['proposed_changes'] = $synthesized;
                        $fallbackJson['phase'] = 'final_report';
                        Log::info('AgentLoop: Synthesized proposals from findings (fallback)', [
                            'agent_id' => $agentId,
                            'count' => count($synthesized),
                        ]);
                        // Re-encode and mark as valid
                        $assistantContent = "```json\n".json_encode($fallbackJson, JSON_PRETTY_PRINT)."\n```";
                        $validationResult = ['valid' => true, 'reason' => 'Fallback: proposals synthesized from findings', 'data' => $fallbackJson];
                    }
                }
            }

            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
            $phaseResults[$phaseName] = $assistantContent;

            // If we got valid JSON, extract structured data for next phase context
            if ($validationResult && $validationResult['valid'] && ! empty($validationResult['data'])) {
                $structuredData = $validationResult['data'];

                // Feed person targets into next phase's tool context
                if (! empty($structuredData['next_phase_targets'])) {
                    $toolContext['phase_targets'] = $structuredData['next_phase_targets'];
                }
                if (! empty($structuredData['persons_found'])) {
                    $toolContext['persons_found'] = $structuredData['persons_found'];
                }
            }

            $this->recordEpisode($agentId, $sessionId, 'phase_completed',
                "Phase '{$phaseName}' analyzed: ".$assistantContent, [
                    'phase' => $phaseName,
                    'tools_called' => count($phaseToolResults),
                    'model' => $response['model'] ?? 'unknown',
                    'provider' => $responseProvider ?? 'unknown',
                ]);

            Log::info('AgentLoop: Hybrid phase completed', [
                'agent_id' => $agentId,
                'phase' => $phaseName,
                'tools_called' => count($phaseToolResults),
                'analysis_length' => strlen($assistantContent),
            ]);
        }

        // Return the last phase's analysis as the final response
        $lastPhaseResult = $phaseResults[$phaseNames[count($phaseNames) - 1]] ?? 'No analysis produced.';

        // If the final response is structured JSON, format it into a readable report.
        // Accept both final_report format (summary + persons_researched) AND
        // analyze format (key_findings + persons_found) — LLMs sometimes return the
        // wrong phase label, but the data is still valuable.
        $finalValidation = $this->validatePhaseResponse($lastPhaseResult, 'report', count($phaseNames) - 1, count($phaseNames));
        $fd = ($finalValidation['valid'] && ! empty($finalValidation['data'])) ? $finalValidation['data'] : null;

        // N47: When final phase was accepted as free-text (data=null) or has no proposals,
        // synthesize proposals from prior phase findings. This catches the common case where
        // the LLM returns a markdown report instead of JSON on the final phase.
        if (! $isOperationalMode && (! $fd || (empty($fd['proposed_changes']) && empty($fd['proposed_relationships']) && empty($fd['proposed_marriages'])))) {
            $priorPersonFindings = [];
            foreach ($phaseResults as $prPhase => $prResult) {
                // N119b: Skip assess phase — its findings are metadata ("Tier 1, priority rank N"),
                // not research results. Including assess pollutes all downstream proposals.
                if ($prPhase === 'assess') {
                    continue;
                }
                $prJson = null;
                if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $prResult, $prMatches)) {
                    $prJson = json_decode($prMatches[1], true);
                } elseif (preg_match('/^\s*(\{[\s\S]*\})\s*$/m', $prResult, $prMatches)) {
                    $prJson = json_decode($prMatches[1], true);
                }
                if ($prJson) {
                    foreach ($prJson['persons_found'] ?? $prJson['persons_researched'] ?? [] as $pf) {
                        if (! is_array($pf)) {
                            continue;
                        }
                        $pid = $pf['id'] ?? null;
                        if ($pid && ! empty($pf['findings'])) {
                            // Guard: LLM sometimes returns findings as array
                            if (! is_string($pf['findings'])) {
                                $pf['findings'] = is_array($pf['findings']) ? implode('; ', array_filter($pf['findings'])) : (string) $pf['findings'];
                            }
                            // N119b: Strip any assess metadata that leaked into phase findings
                            $pf['findings'] = $this->stripAssessMetadata($pf['findings']);
                            if (strlen(trim($pf['findings'])) < 20) {
                                continue;
                            }
                            if (isset($priorPersonFindings[$pid])) {
                                $priorPersonFindings[$pid]['findings'] .= ' | '.$pf['findings'];
                            } else {
                                $priorPersonFindings[$pid] = $pf;
                            }
                        }
                    }
                }
            }

            if (! empty($priorPersonFindings)) {
                $synthesized = [];
                foreach ($priorPersonFindings as $pf) {
                    $findings = $pf['findings'] ?? '';
                    if (! is_string($findings)) {
                        $findings = is_array($findings) ? implode('; ', array_filter($findings)) : (string) $findings;
                    }
                    $pid = $pf['id'] ?? null;
                    $findingsTrimmed = trim($findings);
                    // Reject different-person data, gap descriptions, and short findings
                    if (preg_match('/likely (for )?a different (person|individual)/i', $findingsTrimmed)) {
                        continue;
                    }
                    $isGapDescription = preg_match('/^(no |none|nothing|zero|empty|missing )/i', $findingsTrimmed);
                    $hasActionableData = strlen($findingsTrimmed) > 30
                        && ! $isGapDescription
                        && preg_match('/(found|record|census|certificate|document|source|born|died|married|buried|\d{4})/i', $findingsTrimmed);
                    if ($pid && $hasActionableData) {
                        // N119b: Only produce notes_append — no spacy/regex fact extraction.
                        // Fallback synthesis operates on LLM summaries of tool results, not raw
                        // records. Spacy/regex extract false-positive places from source names
                        // and record type descriptions. Real fact extraction should happen in
                        // the LLM's proposed_changes (when it works), not in fallback.
                        $synthesized[] = [
                            'person_id' => (int) $pid,
                            'change_type' => 'notes_append',
                            'field_name' => null,
                            'proposed_value' => substr($findingsTrimmed, 0, 500),
                            'evidence_sources' => ['agent-synthesized from research findings'],
                            'evidence_summary' => $findingsTrimmed,
                            'confidence' => 0.4,
                        ];
                    }
                }
                if (! empty($synthesized)) {
                    $wasNull = ! $fd;
                    if (! $fd) {
                        // Build fd from prior data since final phase was free-text
                        $fd = [
                            'phase' => 'final_report',
                            'persons_researched' => array_values($priorPersonFindings),
                            'proposed_changes' => $synthesized,
                        ];
                    } else {
                        $fd['proposed_changes'] = array_merge($fd['proposed_changes'] ?? [], $synthesized);
                    }
                    Log::info('AgentLoop: N47 synthesized proposals from prior phase findings', [
                        'agent_id' => $agentId,
                        'count' => count($synthesized),
                        'source' => $wasNull ? 'free-text-recovery' : 'augmented-json',
                    ]);
                }
            }
        }

        if ($fd) {
            if ($this->isOperationalHybridReport($fd)) {
                return $this->renderOperationalHybridReport($fd, count($toolCalls));
            }

            $hasFinalReportFormat = ! empty($fd['summary']) || ! empty($fd['persons_researched']);
            $hasAnalyzeFormat = ! empty($fd['persons_found']) || ! empty($fd['key_findings']);
            $hasProposals = ! empty($fd['proposed_relationships']) || ! empty($fd['proposed_marriages']) || ! empty($fd['proposed_changes']);

            if ($hasFinalReportFormat || $hasAnalyzeFormat || $hasProposals) {
                $report = "## Genealogy Research Report\n\n";

                // Handle final_report format (preferred)
                if (! empty($fd['summary'])) {
                    $report .= $fd['summary']."\n\n";
                } elseif (! empty($fd['key_findings'])) {
                    // Fallback: synthesize summary from analyze format
                    $report .= "### Key Findings\n\n";
                    foreach ($fd['key_findings'] as $finding) {
                        $report .= '- '.(is_string($finding) ? $finding : json_encode($finding))."\n";
                    }
                    $report .= "\n";
                }

                // Handle persons — accept both persons_researched and persons_found
                $persons = $fd['persons_researched'] ?? $fd['persons_found'] ?? [];
                if (! empty($persons)) {
                    $report .= "### Persons Researched\n\n";
                    foreach ($persons as $p) {
                        if (! is_array($p)) {
                            continue;
                        }
                        $name = $p['name'] ?? 'Unknown';
                        $pid = $p['id'] ?? '?';
                        $conf = $p['confidence'] ?? 'N/A';
                        $rec = $p['recommendation'] ?? 'needs_review';
                        $findings = $p['findings'] ?? 'No findings';
                        if (! is_string($findings)) {
                            $findings = is_array($findings) ? implode('; ', array_filter($findings)) : (string) $findings;
                        }
                        $report .= "- **{$name}** (ID: {$pid}) — Confidence: {$conf} — {$rec}\n"
                            ."  {$findings}\n\n";
                    }
                }

                // N118: Only submit per-person review items when there's an actual
                // proposed_change for that person. Status reports, "nothing found",
                // and existing-data summaries do NOT belong in the review queue.
                $proposedChanges = $fd['proposed_changes'] ?? [];
                $proposedRels = $fd['proposed_relationships'] ?? [];
                $proposedMarriages = $fd['proposed_marriages'] ?? [];

                // N119b: Detect LLM "sample/template" responses — llama3.1:8b sometimes
                // generates example JSON instead of actual analysis. Reject all proposals.
                $rawReport = $lastPhaseResult ?? '';
                $isSampleResponse = preg_match('/\b(sample response|adjust the values|match your actual|example response|placeholder)\b/i', $rawReport);
                if ($isSampleResponse) {
                    Log::warning('AgentLoop: LLM returned sample/template response, discarding all proposals', [
                        'agent_id' => $agentId,
                    ]);
                    $proposedChanges = [];
                    $proposedRels = [];
                    $proposedMarriages = [];
                }

                // Build a map of person_id → their proposed changes
                // Validate against known person set to prevent cross-person contamination
                $validPersonIds = array_filter(array_map(fn ($p) => is_array($p) ? ($p['id'] ?? null) : null, $persons));
                $personProposals = [];
                foreach ($proposedChanges as $pc) {
                    if (! is_array($pc)) {
                        continue;
                    }
                    $pid = $pc['person_id'] ?? null;
                    if (! $pid) {
                        continue;
                    }
                    if (! empty($validPersonIds) && ! in_array((int) $pid, array_map('intval', $validPersonIds))) {
                        Log::warning('AgentLoop: Batch proposal person_id not in target set — skipping', [
                            'proposal_person_id' => $pid, 'valid_ids' => $validPersonIds,
                        ]);
                        $this->hybridRunMetrics['proposals_filtered']++;

                        continue;
                    }

                    // N119b: Reject proposals with vague/fabricated evidence
                    $evidSummary = $pc['evidence_summary'] ?? '';
                    $evidSources = $pc['evidence_sources'] ?? [];
                    $isVagueEvidence = preg_match('/\b(found in various|located in (multiple|various)|historical records?|various (historical )?documents?|multiple (historical )?sources)\b/i', trim($evidSummary))
                        || (count($evidSources) === 1 && preg_match('/^historical records?$/i', trim($evidSources[0] ?? '')));
                    if ($isVagueEvidence) {
                        Log::debug('AgentLoop: Skipping proposal with vague evidence', [
                            'person_id' => $pid, 'field' => $pc['field_name'] ?? null,
                            'evidence' => $evidSummary,
                        ]);
                        $this->hybridRunMetrics['proposals_filtered']++;

                        continue;
                    }

                    // N119b: Reject proposals that duplicate existing tree data.
                    // The person's current data was already provided to the LLM in context.
                    $field = $pc['field_name'] ?? null;
                    $proposedVal = trim($pc['proposed_value'] ?? '');
                    if ($field && $proposedVal) {
                        $existingVal = $this->getPersonFieldValue($pid, $field);
                        if ($existingVal !== null && stripos($existingVal, $proposedVal) !== false) {
                            Log::debug('AgentLoop: Skipping proposal that duplicates existing data', [
                                'person_id' => $pid, 'field' => $field,
                                'proposed' => $proposedVal, 'existing' => $existingVal,
                            ]);
                            $this->hybridRunMetrics['proposals_filtered']++;

                            continue;
                        }
                    }

                    $personProposals[$pid][] = $pc;
                }
                foreach ($proposedRels as $pr) {
                    if (! is_array($pr)) {
                        continue;
                    }
                    $pid = $pr['person_id'] ?? null;
                    if ($pid) {
                        $personProposals[$pid][] = $pr;
                    }
                }

                // Only submit review items for persons with actual proposals
                if (! empty($persons) && ! empty($personProposals)) {
                    foreach ($persons as $p) {
                        if (! is_array($p)) {
                            continue;
                        }
                        $personId = $p['id'] ?? null;
                        if (! $personId || ! isset($personProposals[$personId])) {
                            continue; // No proposals for this person — nothing to review
                        }

                        $personName = $p['name'] ?? 'Unknown';
                        $conf = (float) ($p['confidence'] ?? 0.5);

                        // Positive notes_append findings still belong in review; only suppress purely negative-noise items.
                        $hasActionableProposal = $this->shouldQueueGenealogyFindingReview($personProposals[$personId]);
                        if (! $hasActionableProposal) {
                            continue; // Only negative-result documentation — log but don't queue for review
                        }

                        // Merge new proposals into any existing pending row for this person so
                        // accumulated findings refresh instead of being silently skipped.
                        // Prefer a clean-title row over a synthetic "— search complete" row
                        // when both exist for this person, so the merge targets the richer row
                        // and leaves the search-complete marker untouched.
                        $existingPersonFinding = DB::selectOne(
                            "SELECT id FROM agent_review_queue
                             WHERE agent_id = ? AND review_type = 'genealogy_finding' AND status = 'pending'
                             AND JSON_EXTRACT(details, '$.person_id') = ?
                             ORDER BY title LIKE '% — search complete%' ASC, id DESC
                             LIMIT 1",
                            [$agentId, (int) $personId]
                        );
                        if ($existingPersonFinding) {
                            $this->mergePendingGenealogyFindingProposals(
                                (int) $existingPersonFinding->id,
                                $agentId,
                                (int) $personId,
                                (string) $personName,
                                $personProposals[$personId],
                                (float) $conf,
                                (int) ($conf < 0.5 ? 2 : 1),
                            );
                            $this->hybridRunMetrics['review_items_submitted']++;
                            foreach ($personProposals[$personId] as $prop) {
                                $type = $prop['change_type'] ?? $prop['relationship_type'] ?? 'other';
                                $this->hybridRunMetrics['review_item_types'][$type] =
                                    ($this->hybridRunMetrics['review_item_types'][$type] ?? 0) + 1;
                            }

                            continue;
                        }

                        // Build concise summary from actual proposals
                        $proposalLines = [];
                        foreach ($personProposals[$personId] as $prop) {
                            $type = $prop['change_type'] ?? $prop['relationship_type'] ?? 'update';
                            $field = $prop['field_name'] ?? '';
                            $value = $prop['proposed_value'] ?? $prop['proposed_name'] ?? '';
                            $source = '';
                            $sources = $prop['evidence_sources'] ?? [];
                            if (! empty($sources) && is_array($sources)) {
                                $source = ' ('.mb_substr(implode(', ', $sources), 0, 60).')';
                            }
                            if ($field) {
                                $proposalLines[] = "{$field}: {$value}{$source}";
                            } else {
                                $proposalLines[] = "{$type}: ".mb_substr((string) $value, 0, 80).$source;
                            }
                        }
                        $summary = implode("\n", $proposalLines);

                        $this->submitForReview([
                            'agent_id' => $agentId,
                            'review_type' => 'genealogy_finding',
                            'title' => "{$personName} (#{$personId})",
                            'summary' => $summary,
                            'confidence' => $conf,
                            'priority' => $conf < 0.5 ? 2 : 1,
                            'details' => [
                                'person_id' => $personId,
                                'person_name' => $personName,
                                'proposals' => $personProposals[$personId],
                            ],
                        ]);

                        // Track review item types for run summary
                        $this->hybridRunMetrics['review_items_submitted']++;
                        foreach ($personProposals[$personId] as $prop) {
                            $type = $prop['change_type'] ?? $prop['relationship_type'] ?? 'other';
                            $this->hybridRunMetrics['review_item_types'][$type] =
                                ($this->hybridRunMetrics['review_item_types'][$type] ?? 0) + 1;
                        }
                    }
                }

                // Delegate proposal handling to AgentProposalService (domain-agnostic dispatch)
                Log::info('AgentLoop: Hybrid final report proposal check', [
                    'agent_id' => $agentId,
                    'has_proposals' => $hasProposals,
                    'proposed_changes' => count($fd['proposed_changes'] ?? []),
                    'proposed_relationships' => count($fd['proposed_relationships'] ?? []),
                    'proposed_marriages' => count($fd['proposed_marriages'] ?? []),
                ]);
                if ($hasProposals) {
                    try {
                        $proposalService = app(\App\Services\AgentProposalService::class);
                        $report .= $proposalService->processProposals($fd, $agentId, $toolContext);
                    } catch (\Throwable $e) {
                        $report .= "- Error processing proposals: {$e->getMessage()}\n";
                        Log::error('AgentLoop: Proposal processing failed', [
                            'agent_id' => $agentId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('AgentLoop: No proposals in final report', [
                        'agent_id' => $agentId,
                        'persons_count' => count($persons),
                        'has_final_report_format' => $hasFinalReportFormat,
                    ]);
                }

                if (! empty($fd['follow_up_tasks'])) {
                    $report .= "### Follow-Up Tasks\n\n";
                    foreach ($fd['follow_up_tasks'] as $task) {
                        $report .= '- '.(is_string($task) ? $task : json_encode($task))."\n";
                    }
                }

                $report .= "\n**Tools used:** ".($fd['total_tools_used'] ?? count($toolCalls))
                    .' | **Findings:** '.($fd['total_findings'] ?? count($persons));

                return $report;
            }
        }

        return $lastPhaseResult;
    }

    /**
     * Per-person hybrid workflow: assess in batch, then process each person individually
     * through research → analyze → report phases. Reduces context complexity per LLM call
     * and prevents hallucination from cross-person confusion in batch mode.
     *
     * N120: Controlled by SKILL.md `iteration_mode: per_person`.
     */
    private function executePerPersonHybridWorkflow(
        string $agentId, string $sessionId, string $task, array $skillConfig,
        string $skillInstructions, array $agentTools, array $toolPhases,
        array $toolContext, array &$toolCalls, string $systemPrompt,
        array $messages, ?string $model, bool $useAutoSelect,
        float $temperature, bool $hasSensitivePerms, array $options,
        array $phaseNames, int $maxPersonsPerRun, float $workflowStartTime,
        float $timeoutSeconds, float $reportReserveSeconds, string $modelRole,
        ?array $cascadeConfig = null
    ): string {
        // Phase 1 (assess) runs in batch — needs full tree context to select persons
        $assessPhaseName = $phaseNames[0];
        $perPersonPhases = array_slice($phaseNames, 1); // research, analyze, report (or subset)

        // Queue mode: skip assess phase entirely — person pre-identified by research queue
        $skipAssess = ! empty($options['context']['skip_assess']);
        if ($skipAssess && ! empty($options['context']['target_person_id'])) {
            $targetPersonId = (int) $options['context']['target_person_id'];
            $targetPersonName = $options['context']['target_person_name'] ?? 'Unknown';

            // Enrich target person from DB for tool parameter injection (surname, birth_year, etc.)
            $personData = DB::selectOne('
                SELECT id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
                FROM genealogy_persons WHERE id = ? AND tree_id = ?
            ', [$targetPersonId, $toolContext['tree_id'] ?? 0]);

            $enrichedPerson = ['id' => $targetPersonId, 'name' => $targetPersonName];
            if ($personData) {
                $birthYear = null;
                if ($personData->birth_date && preg_match('/(\d{4})/', $personData->birth_date, $m)) {
                    $birthYear = (int) $m[1];
                }
                $deathYear = null;
                if ($personData->death_date && preg_match('/(\d{4})/', $personData->death_date, $m)) {
                    $deathYear = (int) $m[1];
                }
                $enrichedPerson = [
                    'id' => (int) $personData->id,
                    'name' => trim(($personData->given_name ?? '').' '.($personData->surname ?? '')),
                    'surname' => $personData->surname,
                    'birth_year' => $birthYear,
                    'death_year' => $deathYear,
                    'birth_place' => $personData->birth_place,
                ];
            }

            // Synthesize assess output for downstream context
            $assessContent = json_encode([
                'phase' => $assessPhaseName,
                'persons_found' => [$enrichedPerson],
                'key_findings' => ['Person pre-identified by research queue (score: '.($options['context']['priority_score'] ?? '?').')'],
                'next_phase_targets' => [['person_id' => $targetPersonId, 'action' => 'research']],
                'issues' => [],
                'tool_failures' => [],
            ]);

            $this->recordEpisode($agentId, $sessionId, 'phase_started',
                "Queue mode: skipping assess, targeting {$targetPersonName} (ID: {$targetPersonId})", [
                    'phase' => 'assess_skip', 'mode' => 'queue',
                    'person_id' => $targetPersonId,
                ]);

            $this->hybridRunMetrics['phases_completed']++;
            $this->hybridRunMetrics['phase_providers'][$assessPhaseName] = 'queue_skip';

            $personsToProcess = [$enrichedPerson];

            // Build messages with synthetic assess for per-person phases
            $assessMessages = $messages;
            $assessMessages[] = ['role' => 'user', 'content' => "Research queue has identified {$targetPersonName} (ID: {$targetPersonId}) as the priority target. Proceed with research."];
            $assessMessages[] = ['role' => 'assistant', 'content' => $assessContent];

            // Jump to per-person processing (skip the full assess phase below)
            goto perPersonLoop;
        }

        // Execute assess phase identically to batch mode
        $assessToolNames = $toolPhases[$assessPhaseName] ?? [];
        $assessTools = $this->getToolRegistry()->getToolsForPhase($agentTools, $assessToolNames);

        $this->recordEpisode($agentId, $sessionId, 'phase_started',
            'Hybrid per-person: assess phase — executing '.count($assessTools).' tools', [
                'phase' => $assessPhaseName,
                'mode' => 'per_person',
                'tools' => array_keys($assessTools),
            ]);

        // Execute assess tools (no person context yet — tree-wide)
        $assessToolResults = [];
        foreach ($assessToolNames as $toolName) {
            if (! isset($agentTools[$toolName])) {
                continue;
            }

            $toolDef = $agentTools[$toolName];

            // Memory recall tools need query parameter synthesized from task context
            $callParams = [];
            if (in_array($toolName, ['recall_procedures', 'recall_episodes'])) {
                $callParams = ['query' => $toolContext['task'] ?? "genealogy research tree {$toolContext['tree_id']}"];
            } else {
                $unsatisfied = $this->getUnsatisfiedRequiredParams($toolDef, $toolContext);
                if (! empty($unsatisfied)) {
                    $assessToolResults[$toolName] = ['success' => false, 'result_text' => 'Skipped: missing '.implode(', ', $unsatisfied)];

                    continue;
                }
            }

            $toolStartTime = microtime(true);
            try {
                $result = $this->getToolRegistry()->executeTool($toolName, $callParams, $toolContext);
                $assessToolResults[$toolName] = ['success' => $result['success'] ?? false, 'result_text' => $result['result_text'] ?? 'No output'];
            } catch (\Throwable $e) {
                $assessToolResults[$toolName] = ['success' => false, 'result_text' => 'Error: '.$e->getMessage()];
            }
            $toolDurationMs = (int) round((microtime(true) - $toolStartTime) * 1000);

            $this->recordEpisode($agentId, $sessionId, 'tool_call', "Calling {$toolName}", [
                'tool' => $toolName, 'phase' => $assessPhaseName, 'mode' => 'per_person',
                'success' => $assessToolResults[$toolName]['success'],
                'duration_ms' => $toolDurationMs,
                'tool_result' => mb_substr($assessToolResults[$toolName]['result_text'], 0, 500),
            ]);

            $toolCalls[] = ['tool' => $toolName, 'params' => $callParams, 'success' => $assessToolResults[$toolName]['success'], 'phase' => $assessPhaseName];
        }

        // LLM analyze assess results to get person list
        // N143: Cap per-tool output to prevent context overflow on large trees.
        // The assess phase only needs enough signal to pick priority persons, not full detail.
        // TODO: Proper fix — tools should accept a summary/limit mode for assess context.
        $assessPrompt = $this->buildAssessPrompt($assessPhaseName, $assessToolResults);
        $nextPhase = $perPersonPhases[0] ?? 'research';
        $assessPrompt .= "Analyze the above results. You MUST respond with valid JSON in this exact format:\n"
            ."```json\n{\n"
            ."  \"phase\": \"{$assessPhaseName}\",\n"
            ."  \"persons_found\": [{\"id\": <number>, \"name\": \"<full name string>\", \"surname\": \"<last name>\", \"birth_year\": <number or null>, \"death_year\": <number or null>, \"birth_place\": \"<state/place or null>\", \"findings\": \"<string>\"}],\n"
            ."  \"key_findings\": [\"<string>\"],\n"
            ."  \"next_phase_targets\": [{\"person_id\": <number>, \"action\": \"<string>\"}],\n"
            ."  \"issues\": [\"<string>\"],\n"
            ."  \"tool_failures\": [\"<tool_name that failed>\"]\n"
            ."}\n```\n"
            ."CRITICAL: Use ONLY real person IDs and names from the tool results above. Do NOT invent names.\n"
            ."Include UP TO {$maxPersonsPerRun} persons in next_phase_targets (time budget for this run).";

        $assessMessages = $messages;
        $assessMessages[] = ['role' => 'user', 'content' => $assessPrompt];

        $aiOptions = [
            'system' => $systemPrompt,
            'system_prompt' => $systemPrompt,
            'temperature' => $temperature,
            'max_tokens' => config('agents.context_max_tokens', 4000),
            'ai_timeout' => $this->getAgentAiTimeout($skillConfig, $options),
            'sensitive_data' => $hasSensitivePerms,
            'use_cache' => false,
        ];
        if ($model) {
            $aiOptions['model'] = $model;
        } elseif ($useAutoSelect) {
            $aiOptions['prefer_external'] = true;
            $aiOptions['skip_if_busy'] = true;
            $aiOptions['model_role'] = $modelRole;
        }

        $maxRetries = config('agents.phase_max_retries', 2);
        $assessContent = '';
        $assessValidation = null;
        $assessResponse = null;

        if ($cascadeConfig !== null) {
            $aiOptions['cascade'] = $cascadeConfig;
        }
        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            $assessResponse = $this->getAIService()->process($this->formatMessagesForAI($assessMessages), $aiOptions);

            // N75b: Detect AI provider failure — don't silently treat empty response as "no persons"
            if (empty($assessResponse['success'])) {
                $aiError = $assessResponse['error'] ?? 'Unknown AI failure';
                Log::warning('AgentLoop: AI call failed in assess phase', [
                    'agent_id' => $agentId,
                    'retry' => $retry,
                    'error' => $aiError,
                    'attempts' => $assessResponse['attempts'] ?? [],
                ]);
                // On retry, escalate to Claude CLI
                if ($retry < $maxRetries) {
                    $aiOptions['prefer_claude'] = true;
                    unset($aiOptions['skip_if_busy']);
                    $this->hybridRunMetrics['claude_escalations']++;
                }

                continue;
            }

            $assessContent = $assessResponse['content'] ?? $assessResponse['response'] ?? '';

            // Template/placeholder detection
            $isTemplate = preg_match('/\b(sample response|adjust the values|match your actual|example response|placeholder|here is a sample|here is an example)\b/i', $assessContent);
            $hasPlaceholderNames = preg_match('/\b(John Doe|Jane Smith|Jane Doe|John Smith)\b/', $assessContent);
            if (($isTemplate || $hasPlaceholderNames) && $retry < $maxRetries) {
                $this->hybridRunMetrics['template_detections']++;
                $aiOptions['prefer_claude'] = true;
                $assessMessages[] = ['role' => 'assistant', 'content' => $assessContent];
                $assessMessages[] = ['role' => 'user', 'content' => 'CRITICAL ERROR: Template/placeholder detected. Analyze the ACTUAL tool results and produce real findings.',
                ];

                continue;
            }

            $assessValidation = $this->validatePhaseResponse($assessContent, $assessPhaseName, 0, count($phaseNames));
            if ($assessValidation['valid']) {
                break;
            }

            if ($retry < $maxRetries) {
                $assessMessages[] = ['role' => 'assistant', 'content' => $assessContent];
                $assessMessages[] = ['role' => 'user', 'content' => "VALIDATION FAILED: {$assessValidation['reason']}\n\nFix your response and try again.",
                ];
            }
        }

        $this->hybridRunMetrics['phases_completed']++;
        $this->hybridRunMetrics['phase_providers'][$assessPhaseName] = $assessResponse['provider'] ?? 'unknown';

        $this->recordEpisode($agentId, $sessionId, 'phase_completed',
            "Phase '{$assessPhaseName}' analyzed: ".$assessContent, [
                'phase' => $assessPhaseName, 'mode' => 'per_person',
                'provider' => $assessResponse['provider'] ?? 'unknown',
                'model' => $assessResponse['model'] ?? 'unknown',
            ]);

        // Extract persons from assess
        $targetPersons = [];
        if ($assessValidation && $assessValidation['valid'] && ! empty($assessValidation['data'])) {
            $targetPersons = $assessValidation['data']['persons_found'] ?? [];
        }

        if (empty($targetPersons)) {
            $reason = empty($assessContent) ? 'AI provider returned empty response' :
                      ($assessValidation && ! $assessValidation['valid'] ? "Validation failed: {$assessValidation['reason']}" : 'LLM returned no persons');
            Log::warning('AgentLoop: Per-person mode — no persons found in assess', [
                'agent_id' => $agentId,
                'reason' => $reason,
                'provider' => $assessResponse['provider'] ?? 'unknown',
                'assess_content_length' => strlen($assessContent ?? ''),
            ]);
            // Defensive: complete session on early return to prevent orphaned 'active' sessions
            try {
                $this->getSessionService()->completeSession($sessionId);
            } catch (\Throwable $ignore) {
                Log::debug('AgentLoopService: session completion failed on early return (no persons)', ['session' => $sessionId]);
            }

            return "No persons identified for research in assess phase. Reason: {$reason}";
        }

        $personsToProcess = array_slice($targetPersons, 0, $maxPersonsPerRun);

        perPersonLoop:
        Log::info('AgentLoop: Per-person mode — processing persons individually', [
            'agent_id' => $agentId,
            'persons_count' => count($personsToProcess),
            'phases' => $perPersonPhases,
        ]);

        // Queue mode (single person): extend timeout immediately since the post-loop
        // extender condition ($personIdx < count-1) is structurally unreachable with 1 person.
        // Queue mode is intentionally kept bounded; do not auto-extend to skill max.
        $timeoutExtender = $options['timeout_extender'] ?? null;
        $queueMode = ! empty($options['context']['queue_mode']);
        if ($timeoutExtender && count($personsToProcess) === 1 && ! $queueMode) {
            $skillMaxMinutes = (int) ($skillConfig['max_timeout_minutes'] ?? 120);
            $this->callTimeoutExtender(
                $timeoutExtender,
                $skillMaxMinutes,
                "Queue mode: single person — extending to skill max ({$skillMaxMinutes}min)"
            );
        }

        // Process each person through remaining phases individually
        $allPersonReports = [];
        $allProposedChanges = [];
        $allProposedRels = [];
        $allProposedMarriages = [];

        foreach ($personsToProcess as $personIdx => $person) {
            $personId = $person['id'] ?? null;
            $personName = $person['name'] ?? 'Unknown';
            if (! $personId) {
                continue;
            }

            $elapsedSeconds = microtime(true) - $workflowStartTime;
            $remainingSeconds = $timeoutSeconds - $elapsedSeconds;

            // Time check: need at least report reserve + 60s for this person
            if ($remainingSeconds < $reportReserveSeconds + 60) {
                Log::warning('AgentLoop: Per-person mode — time budget exhausted', [
                    'agent_id' => $agentId,
                    'person' => $personName,
                    'remaining_seconds' => (int) $remainingSeconds,
                    'persons_completed' => $personIdx,
                ]);
                break;
            }

            Log::info('AgentLoop: Per-person processing', [
                'agent_id' => $agentId,
                'person' => $personName,
                'person_id' => $personId,
                'index' => $personIdx + 1,
                'total' => count($personsToProcess),
            ]);

            $personContext = $toolContext;
            $personContext['persons_found'] = [$person];
            $personContext['phase_targets'] = [['person_id' => $personId, 'action' => 'research']];

            $personPhaseResults = [];
            // Accumulates raw tool_result payloads across all per-person phases.
            // Queue-mode report phase only runs log_research_search + update_search_coverage,
            // so without this accumulator the research-phase source_search_all / generate_record_hints
            // hits are invisible to buildQueueModeSourceProposal() and zero proposals ever reach review.
            $personResearchToolResults = [];
            $personMessages = $assessMessages;
            $personMessages[] = ['role' => 'assistant', 'content' => $assessContent];

            // Run each per-person phase (research, analyze, report)
            foreach ($perPersonPhases as $ppIdx => $ppPhaseName) {
                $ppElapsed = microtime(true) - $workflowStartTime;
                $ppRemaining = $timeoutSeconds - $ppElapsed;
                $isLastPersonPhase = ($ppIdx === count($perPersonPhases) - 1);
                $isReportPhase = $isLastPersonPhase;

                // Time check within person phases
                if (! $isLastPersonPhase && $ppRemaining < $reportReserveSeconds + 30) {
                    $this->hybridRunMetrics['phases_skipped'][] = "{$ppPhaseName}({$personName})";
                    // Skip to report for this person
                    $ppPhaseName = $perPersonPhases[count($perPersonPhases) - 1];
                    $isLastPersonPhase = true;
                    $isReportPhase = true;
                }

                $ppToolNames = $toolPhases[$ppPhaseName] ?? [];
                if ($queueMode) {
                    $ppToolNames = $this->constrainQueueModePhaseTools(
                        $ppToolNames,
                        $ppPhaseName,
                        $options['context'] ?? []
                    );
                }
                $ppTools = $this->getToolRegistry()->getToolsForPhase($agentTools, $ppToolNames);

                $this->recordEpisode($agentId, $sessionId, 'phase_started',
                    "Per-person phase '{$ppPhaseName}' for {$personName}", [
                        'phase' => $ppPhaseName, 'person_id' => $personId, 'mode' => 'per_person',
                    ]);
                $this->emitProgressCallback($options, 'phase_started', [
                    'phase' => $ppPhaseName,
                    'person_id' => $personId,
                    'person_name' => $personName,
                    'tool_count' => count($ppToolNames),
                ]);

                // Execute tools for this person with time budget enforcement
                $ppToolResults = [];
                $personTimeBudgetSec = ($queueMode ? 8 : (($skillConfig['max_timeout_minutes'] ?? 120) * 0.20)) * 60;
                $personStartTime = microtime(true);
                $toolsSkippedByBudget = 0;

                foreach ($ppToolNames as $toolName) {
                    if (! isset($agentTools[$toolName])) {
                        continue;
                    }
                    if ($ppPhaseName === 'report' && in_array($toolName, ['propose_change', 'propose_relationship'])) {
                        continue;
                    }

                    // Time budget check: skip remaining research tools if budget exhausted
                    if ($ppPhaseName === 'research' && (microtime(true) - $personStartTime) > $personTimeBudgetSec) {
                        $toolsSkippedByBudget++;

                        continue;
                    }

                    $toolDef = $agentTools[$toolName];
                    $toolParams = $toolDef['parameters'] ?? [];
                    $needsPersonId = isset($toolParams['person_id']['required']) && $toolParams['person_id']['required']
                        || isset($toolParams['personId']['required']) && $toolParams['personId']['required'];
                    $needsQuery = isset($toolParams['query']['required']) && $toolParams['query']['required']
                        || isset($toolParams['name']['required']) && $toolParams['name']['required'];
                    $needsSurname = isset($toolParams['surname']['required']) && $toolParams['surname']['required'];

                    $params = [];
                    if ($needsPersonId || $needsQuery || $needsSurname) {
                        $personSurname = trim(explode(' ', $personName)[count(explode(' ', $personName)) - 1] ?? '');
                        if ($personId) {
                            $params['person_id'] = (int) $personId;
                            $params['personId'] = (int) $personId;
                        }
                        if ($needsQuery) {
                            $params['query'] = $personName;
                            $params['name'] = $personName;
                        }
                        if ($needsSurname && $personSurname) {
                            $params['surname'] = $personSurname;
                            $nameParts = explode(' ', trim($personName));
                            if (count($nameParts) > 1 && isset($toolParams['given_name'])) {
                                $params['given_name'] = $nameParts[0];
                            }
                            if (! empty($person['birth_year']) && isset($toolParams['birth_year'])) {
                                $params['birth_year'] = (int) $person['birth_year'];
                            }
                            if (! empty($person['death_year']) && isset($toolParams['death_year'])) {
                                $params['death_year'] = (int) $person['death_year'];
                            }
                            if (! empty($person['birth_place'])) {
                                if (isset($toolParams['birth_place'])) {
                                    $params['birth_place'] = $person['birth_place'];
                                }
                                if (isset($toolParams['state']) && strlen($person['birth_place']) <= 20) {
                                    $params['state'] = $person['birth_place'];
                                }
                            }
                        }
                        if ($toolName === 'evidence_build_chain') {
                            $params['eventType'] = 'birth';
                        }
                        if ($toolName === 'generate_gps_proof') {
                            $params['question'] = "What are the established facts and outstanding questions for {$personName}?";
                        }
                        if ($toolName === 'update_search_coverage') {
                            $params['positive'] = false;
                            $params['repository_name'] = 'General Research Cycle';
                            $params['repository_type'] = 'other';
                            $params['notes'] = "Automated hybrid research cycle for {$personName}";
                        }
                    } elseif ($ppPhaseName === 'report') {
                        $params = $this->buildReportToolParams($toolName, $agentId, [$person], $personPhaseResults);
                        if (empty($params)) {
                            continue;
                        }
                    } else {
                        $unsatisfied = $this->getUnsatisfiedRequiredParams($toolDef, $personContext);
                        if (! empty($unsatisfied)) {
                            $ppToolResults[$toolName] = ['success' => false, 'result_text' => 'Skipped: missing '.implode(', ', $unsatisfied)];

                            continue;
                        }
                    }

                    $toolStartTime = microtime(true);
                    try {
                        $result = $this->getToolRegistry()->executeTool($toolName, $params, $personContext);
                        // Keep BOTH the serialized string (for LLM prompt injection) AND
                        // the raw array (for in-code structured consumption). The
                        // serialized string is capped at 8000 chars and may be truncated
                        // when source_search_all returns many rows; code-path consumers
                        // should prefer result_array when possible.
                        $ppToolResults["{$toolName} for {$personName}"] = [
                            'success' => $result['success'] ?? false,
                            'result_text' => $result['result_text'] ?? 'No output',
                            'result_array' => is_array($result['result'] ?? null) ? $result['result'] : null,
                        ];
                    } catch (\Throwable $e) {
                        $ppToolResults["{$toolName} for {$personName}"] = [
                            'success' => false,
                            'result_text' => 'Error: '.$e->getMessage(),
                            'result_array' => null,
                        ];
                    }
                    $toolDurationMs = (int) round((microtime(true) - $toolStartTime) * 1000);
                    $ppResultKey = "{$toolName} for {$personName}";

                    // Preserve successful research-phase evidence tools so the report phase
                    // can emit concrete source_add proposals. Without this, source_search_all
                    // hits evaporate when the phase ends. Capture the raw ARRAY as well so
                    // the proposal extractor never has to re-parse the possibly-truncated
                    // serialized string.
                    if ($queueMode
                        && ($ppToolResults[$ppResultKey]['success'] ?? false)
                        && in_array($toolName, ['source_search_all', 'generate_record_hints', 'nara_search', 'ellis_island_search', 'dar_search'], true)
                    ) {
                        $personResearchToolResults[$toolName] = [
                            'success' => true,
                            'result_text' => $ppToolResults[$ppResultKey]['result_text'],
                            'result_array' => $ppToolResults[$ppResultKey]['result_array'],
                            'phase' => $ppPhaseName,
                        ];
                    }

                    $this->recordEpisode($agentId, $sessionId, 'tool_call', "Calling {$toolName} for {$personName}", [
                        'tool' => $toolName, 'phase' => $ppPhaseName, 'person_id' => $personId, 'mode' => 'per_person',
                        'success' => $ppToolResults[$ppResultKey]['success'],
                        'duration_ms' => $toolDurationMs,
                        'tool_result' => mb_substr($ppToolResults[$ppResultKey]['result_text'], 0, 500),
                    ]);
                    $this->emitProgressCallback($options, 'tool_call', [
                        'phase' => $ppPhaseName,
                        'tool' => $toolName,
                        'person_id' => $personId,
                        'person_name' => $personName,
                        'success' => $ppToolResults[$ppResultKey]['success'],
                        'duration_ms' => $toolDurationMs,
                    ]);

                    $toolCalls[] = ['tool' => $toolName, 'params' => $params, 'success' => $ppToolResults[$ppResultKey]['success'], 'phase' => $ppPhaseName];
                }

                if ($toolsSkippedByBudget > 0) {
                    Log::info("AgentLoop: Time budget exceeded for {$personName} in {$ppPhaseName}", [
                        'skipped' => $toolsSkippedByBudget,
                        'elapsed_s' => round(microtime(true) - $personStartTime),
                        'budget_s' => round($personTimeBudgetSec),
                    ]);
                }

                // LLM analysis for this phase/person — only include tools with results
                $ppPrompt = "## Phase '{$ppPhaseName}' for {$personName} (ID: {$personId})\n\n";
                foreach ($ppToolResults as $tn => $tr) {
                    // Skip failed/empty tools from LLM prompt to save context
                    if (! $tr['success'] && str_starts_with($tr['result_text'], 'Error:')) {
                        continue;
                    }
                    if (! $tr['success'] && str_starts_with($tr['result_text'], 'Skipped:')) {
                        continue;
                    }
                    $status = $tr['success'] ? 'OK' : 'FAILED';
                    $ppPrompt .= "### {$tn} [{$status}]\n{$tr['result_text']}\n\n";
                }
                if ($toolsSkippedByBudget > 0) {
                    $ppPrompt .= "Note: {$toolsSkippedByBudget} lower-priority tools were skipped due to time budget.\n\n";
                }

                if (! $isReportPhase) {
                    $nextPP = $perPersonPhases[$ppIdx + 1] ?? 'report';
                    $ppPrompt .= "Analyze results for {$personName} (ID: {$personId}) ONLY.\n"
                        ."IMPORTANT: If a source record belongs to a DIFFERENT person (e.g., a spouse, child, or parent), do NOT attribute it to {$personName}. Only propose sources that directly reference {$personName}.\n"
                        ."Respond with valid JSON:\n"
                        ."```json\n{\n"
                        ."  \"phase\": \"{$ppPhaseName}\",\n"
                        ."  \"persons_found\": [{\"id\": {$personId}, \"name\": \"{$personName}\", \"findings\": \"<what was found>\"}],\n"
                        ."  \"key_findings\": [\"<string>\"],\n"
                        ."  \"next_phase_targets\": [{\"person_id\": {$personId}, \"action\": \"<action for {$nextPP}>\"}],\n"
                        ."  \"issues\": [\"<string>\"],\n"
                        ."  \"tool_failures\": [\"<tool_name>\"]\n"
                        ."}\n```\n"
                        ."CRITICAL: Only analyze data for {$personName} (ID: {$personId}). Do NOT invent or fabricate data.";
                } else {
                    // Report phase prompt for this person
                    $priorFindings = '';
                    foreach ($personPhaseResults as $prPhase => $prResult) {
                        $prJson = null;
                        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $prResult, $prM)) {
                            $prJson = json_decode($prM[1], true);
                        }
                        if ($prJson && ! empty($prJson['persons_found'])) {
                            foreach ($prJson['persons_found'] as $pf) {
                                if (is_array($pf) && ! empty($pf['findings'])) {
                                    $priorFindings .= "- {$prPhase}: {$pf['findings']}\n";
                                }
                            }
                        }
                    }

                    $ppPrompt .= "\n=== FINAL REPORT for {$personName} (ID: {$personId}) ===\n"
                        ."CRITICAL: Output ONLY real data. No samples, examples, or placeholders.\n"
                        ."CRITICAL: All proposed_changes must have person_id={$personId} ({$personName}). Do NOT propose changes for other persons (spouses, children, parents). If you found records for a related person, note them in follow_up_tasks instead.\n"
                        .(! empty($priorFindings) ? "\nFINDINGS FROM PRIOR PHASES:\n{$priorFindings}\n" : '')
                        ."Respond with valid JSON:\n"
                        ."```json\n{\n"
                        ."  \"phase\": \"final_report\",\n"
                        ."  \"summary\": \"<findings for {$personName}>\",\n"
                        ."  \"persons_researched\": [{\"id\": {$personId}, \"name\": \"{$personName}\", \"findings\": \"<string>\", \"confidence\": <0.0-1.0>, \"recommendation\": \"<confirmed|probable|possible|needs_review>\"}],\n"
                        ."  \"proposed_changes\": [{\"person_id\": {$personId}, \"change_type\": \"<fact_update|event_add|source_add|notes_append|residence_add|external_record_link|source_create>\", \"field_name\": \"<field or null>\", \"proposed_value\": \"<value>\", \"evidence_sources\": [\"<citation>\"], \"evidence_summary\": \"<what and where>\", \"confidence\": <0.0-1.0>}],\n"
                        ."  \"proposed_relationships\": [],\n"
                        ."  \"proposed_marriages\": [],\n"
                        ."  \"total_tools_used\": <number>,\n"
                        ."  \"total_findings\": <number>,\n"
                        ."  \"follow_up_tasks\": [\"<string>\"]\n"
                        ."}\n```\n"
                        .'Convert ALL research findings into proposed_changes. source_add proposed_value MUST be a URL. Use notes_append for text findings.';
                }

                $personMessages[] = ['role' => 'user', 'content' => $ppPrompt];

                $ppContent = '';
                $ppValidationInner = null;
                $ppResponse = [
                    'success' => true,
                    'provider' => 'deterministic',
                    'model' => 'framework',
                ];

                if ($queueMode) {
                    $ppContent = $isReportPhase
                        ? $this->buildQueueModeFinalReport(
                            $personName,
                            $personId,
                            $personPhaseResults,
                            $ppToolResults,
                            $personResearchToolResults
                        )
                        : $this->buildQueueModeIntermediatePhaseResponse(
                            $ppPhaseName,
                            $personName,
                            $personId,
                            $ppIdx,
                            $perPersonPhases,
                            $ppToolResults
                        );
                    $ppValidationInner = $this->validatePhaseResponse($ppContent, $ppPhaseName, $ppIdx + 1, count($phaseNames));
                } else {
                    $ppAiOptions = [
                        'system' => $systemPrompt,
                        'system_prompt' => $systemPrompt,
                        'temperature' => $temperature,
                        'max_tokens' => $isReportPhase
                            ? config('agents.context_max_tokens', 4000) * 2
                            : config('agents.context_max_tokens', 4000),
                        'ai_timeout' => $this->getAgentAiTimeout($skillConfig, $options),
                        'sensitive_data' => $hasSensitivePerms,
                        'use_cache' => false,
                    ];
                    if ($model) {
                        $ppAiOptions['model'] = $model;
                    } elseif ($useAutoSelect) {
                        $ppAiOptions['skip_if_busy'] = true;
                        $ppAiOptions['model_role'] = $modelRole;
                        // Report phase still asks for the quality tier, but AIService
                        // routes local-first via role-fit scoring. Conditional retry
                        // and template-detection escalation below still force
                        // prefer_claude when a real failure or placeholder pattern
                        // is observed — that's intentional and stays intact.
                        if ($isReportPhase) {
                            $ppAiOptions['model_role'] = 'quality';
                        }
                    }

                    // Retry loop with template detection and validation
                    $ppMaxRetries = config('agents.phase_max_retries', 2);
                    if ($cascadeConfig !== null) {
                        $ppAiOptions['cascade'] = $cascadeConfig;
                    }
                    for ($retry = 0; $retry <= $ppMaxRetries; $retry++) {
                        $ppResponse = $this->getAIService()->process($this->formatMessagesForAI($personMessages), $ppAiOptions);

                        // N75b: Detect AI provider failure
                        if (empty($ppResponse['success'])) {
                            Log::warning('AgentLoop: AI call failed in per-person phase', [
                                'agent_id' => $agentId,
                                'phase' => $ppPhaseName,
                                'person' => $personName,
                                'retry' => $retry,
                                'error' => $ppResponse['error'] ?? 'Unknown AI failure',
                            ]);
                            if ($retry < $ppMaxRetries) {
                                $ppAiOptions['prefer_claude'] = true;
                                unset($ppAiOptions['skip_if_busy']);
                            }

                            continue;
                        }

                        $ppContent = $ppResponse['content'] ?? $ppResponse['response'] ?? '';

                        $isTemplate = preg_match('/\b(sample response|adjust the values|match your actual|example response|placeholder|here is a sample|here is an example)\b/i', $ppContent);
                        $hasPlaceholders = preg_match('/\b(John Doe|Jane Smith|Jane Doe|John Smith)\b/', $ppContent);

                        if (($isTemplate || $hasPlaceholders) && $retry < $ppMaxRetries) {
                            $this->hybridRunMetrics['template_detections']++;
                            $ppAiOptions['prefer_claude'] = true;
                            $personMessages[] = ['role' => 'assistant', 'content' => $ppContent];
                            $personMessages[] = ['role' => 'user', 'content' => "CRITICAL ERROR: Template/placeholder detected. Analyze the ACTUAL tool results for {$personName} (ID: {$personId}). "
                                .'Do NOT generate example data.',
                            ];

                            continue;
                        }

                        // Validate structured output
                        $ppValidationInner = $this->validatePhaseResponse($ppContent, $ppPhaseName, $ppIdx + 1, count($phaseNames));
                        if ($ppValidationInner['valid']) {
                            break;
                        }

                        if ($retry < $ppMaxRetries) {
                            $personMessages[] = ['role' => 'assistant', 'content' => $ppContent];
                            $personMessages[] = ['role' => 'user', 'content' => "VALIDATION FAILED: {$ppValidationInner['reason']}\nFix your response and try again.",
                            ];
                        }
                    }
                }

                $this->hybridRunMetrics['phases_completed']++;
                $this->hybridRunMetrics['phase_providers']["{$ppPhaseName}({$personName})"] = $ppResponse['provider'] ?? 'unknown';
                if (isset($ppAiOptions['prefer_claude']) && $ppAiOptions['prefer_claude']) {
                    $this->hybridRunMetrics['claude_escalations']++;
                }

                $personMessages[] = ['role' => 'assistant', 'content' => $ppContent];
                $personPhaseResults[$ppPhaseName] = $ppContent;

                // Update person context from phase output (reuse validation from retry loop)
                $ppValidation = $ppValidationInner ?? $this->validatePhaseResponse($ppContent, $ppPhaseName, $ppIdx + 1, count($phaseNames));
                if ($ppValidation['valid'] && ! empty($ppValidation['data'])) {
                    if (! empty($ppValidation['data']['persons_found'])) {
                        $personContext['persons_found'] = $ppValidation['data']['persons_found'];
                    }
                }

                $this->recordEpisode($agentId, $sessionId, 'phase_completed',
                    "Per-person phase '{$ppPhaseName}' for {$personName}: ".$ppContent, [
                        'phase' => $ppPhaseName, 'person_id' => $personId, 'mode' => 'per_person',
                        'provider' => $ppResponse['provider'] ?? 'unknown',
                        'model' => $ppResponse['model'] ?? 'unknown',
                    ]);
                $this->emitProgressCallback($options, 'phase_completed', [
                    'phase' => $ppPhaseName,
                    'person_id' => $personId,
                    'person_name' => $personName,
                    'provider' => $ppResponse['provider'] ?? 'unknown',
                ]);

                if ($isReportPhase) {
                    break;
                } // Done with this person
            }

            // Extract proposals from this person's report
            $lastPersonPhase = $personPhaseResults[$perPersonPhases[count($perPersonPhases) - 1]] ?? '';
            $personValidation = $this->validatePhaseResponse($lastPersonPhase, 'report', count($phaseNames) - 1, count($phaseNames));
            $pfd = ($personValidation['valid'] && ! empty($personValidation['data'])) ? $personValidation['data'] : null;

            Log::info('AgentLoop: Per-person report extraction', [
                'person' => $personName,
                'person_id' => $personId,
                'validation_valid' => $personValidation['valid'],
                'validation_reason' => $personValidation['reason'] ?? null,
                'has_data' => ! is_null($pfd),
                'proposed_changes_count' => count($pfd['proposed_changes'] ?? []),
                'proposed_rels_count' => count($pfd['proposed_relationships'] ?? []),
            ]);

            if ($queueMode) {
                if ($pfd) {
                    foreach ($pfd['proposed_changes'] ?? [] as $proposal) {
                        if (is_array($proposal)) {
                            $toolCalls[] = ['tool' => 'propose_change', 'success' => true, 'phase' => 'report', 'synthetic' => true];
                        }
                    }
                    foreach ($pfd['proposed_relationships'] ?? [] as $proposal) {
                        if (is_array($proposal)) {
                            $toolCalls[] = ['tool' => 'propose_relationship', 'success' => true, 'phase' => 'report', 'synthetic' => true];
                        }
                    }
                    $this->submitQueueModeGenealogyFindingReview($agentId, $personName, $personId, $pfd, $toolCalls);
                }

                $report = $this->renderQueueModeWorkflowReport($personName, $personId, $pfd, $toolCalls);

                try {
                    $this->getSessionService()->completeSession($sessionId);
                } catch (\Throwable $ignore) {
                    Log::debug('AgentLoopService: session completion failed on queue-mode return', ['session' => $sessionId]);
                }

                return $report;
            }

            if ($pfd) {
                foreach ($pfd['proposed_changes'] ?? [] as $pc) {
                    if (! is_array($pc)) {
                        continue;
                    }
                    // Enforce current person_id — LLM may hallucinate proposals for other persons
                    $proposalPid = $pc['person_id'] ?? null;
                    if ($proposalPid && (int) $proposalPid !== (int) $personId) {
                        Log::warning('AgentLoop: Proposal person_id mismatch — skipping', [
                            'current_person_id' => $personId, 'proposal_person_id' => $proposalPid,
                        ]);
                        $this->hybridRunMetrics['proposals_filtered']++;

                        continue;
                    }
                    // Force correct person_id on proposal
                    $pc['person_id'] = (int) $personId;
                    $evidSummary = $pc['evidence_summary'] ?? '';
                    $evidSources = $pc['evidence_sources'] ?? [];
                    $isVague = preg_match('/\b(found in various|located in (multiple|various)|historical records?|various (historical )?documents?|multiple (historical )?sources)\b/i', trim($evidSummary))
                        || (count($evidSources) === 1 && preg_match('/^historical records?$/i', trim($evidSources[0] ?? '')));
                    if ($isVague) {
                        $this->hybridRunMetrics['proposals_filtered']++;

                        continue;
                    }
                    $allProposedChanges[] = $pc;
                }
                foreach ($pfd['proposed_relationships'] ?? [] as $pr) {
                    if (is_array($pr)) {
                        // Force correct person_id on relationship proposals
                        $prPid = $pr['person_id'] ?? null;
                        if ($prPid && (int) $prPid !== (int) $personId) {
                            $this->hybridRunMetrics['proposals_filtered']++;

                            continue;
                        }
                        $pr['person_id'] = (int) $personId;
                        $allProposedRels[] = $pr;
                    }
                }
                foreach ($pfd['proposed_marriages'] ?? [] as $pm) {
                    if (is_array($pm)) {
                        $allProposedMarriages[] = $pm;
                    }
                }
                $allPersonReports[] = [
                    'person' => $person,
                    'data' => $pfd,
                ];
            }

            // Adaptive timeout: extend deadline if productive and more persons remain
            $timeoutExtender = $options['timeout_extender'] ?? null;
            if ($timeoutExtender && $personIdx < count($personsToProcess) - 1) {
                $elapsedMinutes = (microtime(true) - $workflowStartTime) / 60;
                $avgMinPerPerson = $elapsedMinutes / ($personIdx + 1);
                $personsRemaining = count($personsToProcess) - $personIdx - 1;
                $requestedTotal = (int) ceil($elapsedMinutes + ($personsRemaining * $avgMinPerPerson) + 5);
                $completedCount = $personIdx + 1;
                $this->callTimeoutExtender(
                    $timeoutExtender,
                    $requestedTotal,
                    "Person '{$personName}' completed ({$completedCount}/".count($personsToProcess)."), {$personsRemaining} remaining"
                );
            }
        }

        // Build combined final report
        $report = "## Genealogy Research Report (Per-Person Mode)\n\n";
        $report .= 'Processed '.count($allPersonReports).' of '.count($personsToProcess)." persons.\n\n";

        foreach ($allPersonReports as $pr) {
            $p = $pr['person'];
            $d = $pr['data'];
            $report .= "### {$p['name']} (ID: {$p['id']})\n";
            $report .= ($d['summary'] ?? 'No summary')."\n\n";
        }

        // Submit per-person review items
        $fd = [
            'phase' => 'final_report',
            'summary' => $report,
            'persons_researched' => array_map(fn ($pr) => array_merge(
                $pr['person'],
                ['findings' => $pr['data']['summary'] ?? '', 'confidence' => $pr['data']['persons_researched'][0]['confidence'] ?? 0.5, 'recommendation' => $pr['data']['persons_researched'][0]['recommendation'] ?? 'needs_review']
            ), $allPersonReports),
            'proposed_changes' => $allProposedChanges,
            'proposed_relationships' => $allProposedRels,
            'proposed_marriages' => $allProposedMarriages,
        ];

        // Submit review items per person (same logic as batch mode)
        $personProposals = [];
        foreach ($allProposedChanges as $pc) {
            $pid = $pc['person_id'] ?? null;
            if ($pid) {
                $personProposals[$pid][] = $pc;
            }
        }
        foreach ($allProposedRels as $pr) {
            $pid = $pr['person_id'] ?? null;
            if ($pid) {
                $personProposals[$pid][] = $pr;
            }
        }

        Log::info('AgentLoop: Per-person review submission', [
            'allProposedChanges' => count($allProposedChanges),
            'allProposedRels' => count($allProposedRels),
            'allPersonReports' => count($allPersonReports),
            'personProposals_keys' => array_keys($personProposals),
        ]);

        foreach ($allPersonReports as $pr) {
            $personId = $pr['person']['id'] ?? null;
            if (! $personId || ! isset($personProposals[$personId])) {
                continue;
            }

            // Positive notes_append findings still belong in review; only suppress purely negative-noise items.
            $hasActionable = $this->shouldQueueGenealogyFindingReview($personProposals[$personId]);
            if (! $hasActionable) {
                Log::info('AgentLoop: Skipping notes_append-only review item', [
                    'person_id' => $personId,
                    'proposal_count' => count($personProposals[$personId]),
                    'types' => array_map(fn ($p) => $p['change_type'] ?? $p['relationship_type'] ?? '?', $personProposals[$personId]),
                ]);

                continue;
            }

            $personName = $pr['person']['name'] ?? 'Unknown';
            $conf = (float) ($pr['data']['persons_researched'][0]['confidence'] ?? 0.5);

            // Merge new proposals into any existing pending row for this person so
            // accumulated findings refresh instead of being silently skipped.
            // Prefer clean-title rows over synthetic "— search complete" rows for the same person.
            $existing = DB::selectOne(
                "SELECT id FROM agent_review_queue WHERE agent_id = ? AND review_type = 'genealogy_finding' AND status = 'pending' AND JSON_EXTRACT(details, '$.person_id') = ? ORDER BY title LIKE '% — search complete%' ASC, id DESC LIMIT 1",
                [$agentId, (int) $personId]
            );
            if ($existing) {
                $this->mergePendingGenealogyFindingProposals(
                    (int) $existing->id,
                    $agentId,
                    (int) $personId,
                    (string) $personName,
                    $personProposals[$personId],
                    (float) $conf,
                    (int) ($conf < 0.5 ? 2 : 1),
                );
                $this->hybridRunMetrics['review_items_submitted']++;
                foreach ($personProposals[$personId] as $prop) {
                    $type = $prop['change_type'] ?? $prop['relationship_type'] ?? 'other';
                    $this->hybridRunMetrics['review_item_types'][$type] =
                        ($this->hybridRunMetrics['review_item_types'][$type] ?? 0) + 1;
                }

                continue;
            }

            $reviewDetails = [
                'person_id' => $personId,
                'person_name' => $personName,
                'proposals' => $personProposals[$personId],
            ];

            $decorated = app(\App\Services\ReviewTypeRegistryService::class)->decorateItemForDisplay('genealogy_finding', [
                'summary' => '',
                'details' => $reviewDetails,
            ]);
            $summary = (string) ($decorated['details_human'] ?? $decorated['summary'] ?? '');

            $this->submitForReview([
                'agent_id' => $agentId,
                'review_type' => 'genealogy_finding',
                'title' => "{$personName} (#{$personId})",
                'summary' => $summary,
                'confidence' => $conf,
                'priority' => $conf < 0.5 ? 2 : 1,
                'details' => $reviewDetails,
            ]);

            $this->hybridRunMetrics['review_items_submitted']++;
            foreach ($personProposals[$personId] as $prop) {
                $type = $prop['change_type'] ?? $prop['relationship_type'] ?? 'other';
                $this->hybridRunMetrics['review_item_types'][$type] = ($this->hybridRunMetrics['review_item_types'][$type] ?? 0) + 1;
            }
        }

        // Process proposals through AgentProposalService
        if (! empty($allProposedChanges) || ! empty($allProposedRels) || ! empty($allProposedMarriages)) {
            try {
                $proposalService = app(\App\Services\AgentProposalService::class);
                $report .= $proposalService->processProposals($fd, $agentId, $toolContext);
            } catch (\Throwable $e) {
                $report .= "- Error processing proposals: {$e->getMessage()}\n";
            }
        }

        $report .= "\n**Tools used:** ".count($toolCalls).' | **Persons:** '.count($allPersonReports)
            .' | **Proposals:** '.count($allProposedChanges);

        // Defensive: complete session before return to prevent orphaned 'active' sessions
        // (idempotent — caller also calls completeSession, but this guards against
        // exceptions in post-workflow steps between here and the caller's completion)
        try {
            $this->getSessionService()->completeSession($sessionId);
        } catch (\Throwable $ignore) {
            Log::debug('AgentLoopService: session completion failed on per-person return', ['session' => $sessionId]);
        }

        return $report;
    }

    private function constrainQueueModePhaseTools(array $toolNames, string $phaseName, array $context): array
    {
        $questionType = $context['question_type'] ?? 'find_record';

        return match ($phaseName) {
            'research' => match ($questionType) {
                'negative_search_followup' => array_values(array_intersect($toolNames, [
                    'source_search_all',
                    'generate_record_hints',
                ])),
                default => array_values(array_intersect($toolNames, [
                    'source_search_all',
                    'generate_record_hints',
                ])),
            },
            'analyze' => array_values(array_intersect($toolNames, [
                'get_person_full',
                'get_person_events',
                'get_person_sources',
                'detect_source_conflicts',
                'evidence_build_chain',
            ])),
            'report' => array_values(array_intersect($toolNames, [
                'log_research_search',
                'update_search_coverage',
            ])),
            default => $toolNames,
        };
    }

    private function buildQueueModeIntermediatePhaseResponse(
        string $phaseName,
        string $personName,
        int $personId,
        int $phaseIndex,
        array $perPersonPhases,
        array $toolResults
    ): string {
        $successfulTools = [];
        $findings = [];

        foreach ($toolResults as $toolKey => $toolResult) {
            if (! is_array($toolResult) || empty($toolResult['success'])) {
                continue;
            }

            $toolName = trim((string) preg_replace('/\s+for\s+.+$/', '', $toolKey));
            if ($toolName !== '') {
                $successfulTools[] = $toolName;
            }

            $resultText = trim((string) ($toolResult['result_text'] ?? ''));
            if ($resultText !== '') {
                $findings[] = $this->summarizeQueueModeToolResult($toolName, $resultText);
            }
        }

        $nextPhase = $perPersonPhases[$phaseIndex + 1] ?? 'report';
        $summary = ! empty($findings)
            ? implode(' ', array_slice($findings, 0, 2))
            : "Queue-mode {$phaseName} completed for {$personName}.";

        $payload = [
            'phase' => $phaseName,
            'persons_found' => [[
                'id' => $personId,
                'name' => $personName,
                'findings' => $summary,
            ]],
            'key_findings' => ! empty($successfulTools)
                ? array_map(fn ($tool) => "{$phaseName}:{$tool}", array_slice(array_values(array_unique($successfulTools)), 0, 5))
                : ["{$phaseName}:no_additional_findings"],
            'next_phase_targets' => [[
                'person_id' => $personId,
                'action' => $nextPhase,
            ]],
            'issues' => [],
            'tool_failures' => [],
        ];

        return "```json\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```";
    }

    private function buildQueueModeFinalReport(string $personName, int $personId, array $personPhaseResults, array $reportToolResults, array $researchToolResults = []): string
    {
        $priorFindings = [];
        $followUpTasks = [];
        $evidenceSources = [];
        $sourcesChecked = [];

        foreach ($personPhaseResults as $phaseResult) {
            if (! preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $phaseResult, $matches)) {
                continue;
            }

            $data = json_decode($matches[1], true);
            if (! is_array($data)) {
                continue;
            }

            foreach ($data['persons_found'] ?? $data['persons_researched'] ?? [] as $person) {
                if (! is_array($person)) {
                    continue;
                }

                $findings = $person['findings'] ?? '';
                if (is_array($findings)) {
                    $findings = implode('; ', array_filter(array_map('strval', $findings)));
                }
                $findings = trim((string) $findings);
                if ($findings !== '') {
                    $priorFindings[] = $findings;
                }
            }

            foreach ($data['key_findings'] ?? [] as $finding) {
                $finding = trim((string) $finding);
                if ($finding !== '') {
                    $priorFindings[] = $finding;
                    if (str_contains($finding, ':')) {
                        [, $toolHint] = array_pad(explode(':', $finding, 2), 2, '');
                        $toolHint = trim($toolHint);
                        if ($toolHint !== '' && ! str_contains($toolHint, 'no_additional_findings')) {
                            $sourcesChecked[] = $toolHint;
                        }
                    }
                }
            }

            foreach ($data['issues'] ?? [] as $issue) {
                $issue = trim((string) $issue);
                if ($issue !== '') {
                    $followUpTasks[] = $issue;
                }
            }

            foreach ($data['follow_up_tasks'] ?? [] as $task) {
                $task = trim((string) $task);
                if ($task !== '') {
                    $followUpTasks[] = $task;
                }
            }
        }

        foreach ($reportToolResults as $toolName => $toolResult) {
            if (! is_array($toolResult) || empty($toolResult['success'])) {
                continue;
            }

            $resultText = trim((string) ($toolResult['result_text'] ?? ''));
            if ($resultText === '') {
                continue;
            }

            $baseToolName = trim((string) preg_replace('/\s+for\s+.+$/', '', $toolName));
            if ($baseToolName !== '') {
                $sourcesChecked[] = $baseToolName;
            }

            if (str_contains($toolName, 'rag_index')) {
                $evidenceSources[] = 'rag_index';
            }
            if (str_contains($toolName, 'update_search_coverage')) {
                $evidenceSources[] = 'update_search_coverage';
            }
        }

        $priorFindings = array_values(array_unique(array_filter($priorFindings)));
        $followUpTasks = array_values(array_unique(array_filter($followUpTasks)));
        $evidenceSources = array_values(array_unique(array_filter($evidenceSources)));
        $sourcesChecked = array_values(array_unique(array_filter($sourcesChecked)));

        $summary = ! empty($priorFindings)
            ? implode(' ', array_slice($priorFindings, 0, 3))
            : "Bounded queue run completed for {$personName}; no evidence-backed changes were confirmed.";

        $hasPositiveFinding = false;
        foreach ($priorFindings as $finding) {
            $isNegative = preg_match(
                '/^(none|nothing|zero|empty$)|^no records|^no results|found no records|found nothing|negative result|exhaustive search.*no|could not locate|unable to find/i',
                $finding
            ) && ! preg_match(
                '/\b(but found|however found|did find|also found|found a|found the|found one|found evidence|record found|records found)\b/i',
                $finding
            );
            $hasPositiveEvidence = preg_match('/(found|record|census|certificate|document|source|born|died|married|buried|\d{4})/i', $finding);

            if (strlen($finding) > 30 && ! $isNegative && $hasPositiveEvidence) {
                $hasPositiveFinding = true;
                break;
            }
        }

        // Merge research-phase evidence tools into the pool the proposal builder sees.
        // Report-phase is whitelisted to meta tools (log_research_search, update_search_coverage),
        // so without this the builder never sees source_search_all hits and emits no source_add.
        $evidenceToolResults = $reportToolResults;
        foreach ($researchToolResults as $toolName => $toolResult) {
            $keyForBuilder = $toolName.' for '.$personName;
            if (! isset($evidenceToolResults[$keyForBuilder])) {
                $evidenceToolResults[$keyForBuilder] = $toolResult;
            }
        }

        $sourceProposals = $this->buildQueueModeSourceProposals($personId, $evidenceToolResults);

        $proposedChanges = [];
        if (! empty($sourceProposals)) {
            // Concrete source URLs beat the synthetic notes_append blurb that the noise filter rejects.
            $proposedChanges = $sourceProposals;
        } elseif ($hasPositiveFinding) {
            $proposedChanges = $this->buildQueueModeProposals($personId, $summary, $evidenceToolResults, $evidenceSources);
        }

        $hasReviewableProposal = ! empty($this->filterQueueModeReviewableGenealogyProposals($proposedChanges));

        $outcomeState = $hasReviewableProposal ? 'completed' : 'deferred';
        $outcomeReason = $hasReviewableProposal
            ? 'Evidence-backed finding prepared for human review.'
            : 'No supported change found; preserve for future follow-up.';

        $report = [
            'phase' => 'final_report',
            'summary' => $summary,
            'persons_researched' => [[
                'id' => $personId,
                'name' => $personName,
                'findings' => $summary,
                'confidence' => $hasPositiveFinding ? 0.55 : 0.2,
                'recommendation' => $hasPositiveFinding ? 'possible' : 'needs_review',
            ]],
            'proposed_changes' => $proposedChanges,
            'proposed_relationships' => [],
            'proposed_marriages' => [],
            'total_tools_used' => count($reportToolResults),
            'total_findings' => count($priorFindings),
            'follow_up_tasks' => array_slice($followUpTasks, 0, 5),
            'outcome_state' => $outcomeState,
            'outcome_reason' => $outcomeReason,
            'scope_reason' => 'none',
            'related_people_used' => [],
            'sources_checked' => $sourcesChecked,
            'evidence_summary' => mb_substr($summary, 0, 500),
            'conflicts_found' => 'none',
        ];

        return "```json\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```";
    }

    private function buildQueueModeProposals(int $personId, string $summary, array $reportToolResults, array $evidenceSources): array
    {
        $proposals = [];
        $sourceProposal = $this->buildQueueModeSourceProposal($personId, $reportToolResults);
        if ($sourceProposal !== null) {
            $proposals[] = $sourceProposal;
        }

        $proposals[] = [
            'person_id' => $personId,
            'change_type' => 'notes_append',
            'field_name' => null,
            'proposed_value' => mb_substr($summary, 0, 500),
            'evidence_sources' => $evidenceSources,
            'evidence_summary' => mb_substr($summary, 0, 500),
            'confidence' => 0.55,
        ];

        return $proposals;
    }

    /**
     * Extract all concrete source_add candidates from accumulated tool results.
     * Returns one proposal per result that carries a real URL or record id,
     * capped to prevent flooding the review queue from a single research run.
     */
    private function buildQueueModeSourceProposals(int $personId, array $toolResults): array
    {
        $maxPerRun = (int) config('agents.queue_max_source_proposals', 6);
        $seenLocators = [];
        $proposals = [];

        // Widen past source_search_all — accumulator already preserves these.
        // Each tool uses a different top-level key for its result rows:
        //   source_search_all         → results[]
        //   generate_record_hints     → hints[]
        //   nara/ellis_island/dar/wikitree → results[] or records[]
        // Flexible: try all three common keys. New tools just need to use one.
        $parseableTools = [
            'source_search_all', 'generate_record_hints', 'nara_search',
            'ellis_island_search', 'dar_search',
            'wikitree_search', 'newspaper_search', 'europeana_search',
            'freedmens_bureau_search', 'german_church_records_search',
        ];

        foreach ($toolResults as $toolKey => $toolResult) {
            $baseToolName = trim((string) preg_replace('/\s+for\s+.+$/', '', (string) $toolKey));
            if (! in_array($baseToolName, $parseableTools, true) || ! is_array($toolResult) || empty($toolResult['success'])) {
                continue;
            }

            // Prefer the raw array the tool returned (no size limit, no JSON
            // round-trip). Fall back to parsing the serialized string only when
            // the array wasn't captured (e.g. legacy episode replay).
            $decoded = is_array($toolResult['result_array'] ?? null)
                ? $toolResult['result_array']
                : null;

            if ($decoded === null) {
                $resultText = trim((string) ($toolResult['result_text'] ?? ''));
                if ($resultText === '') {
                    continue;
                }
                $decoded = json_decode($resultText, true);
                if (! is_array($decoded)) {
                    continue;
                }
            }

            $query = trim((string) ($decoded['query'] ?? ''));
            $results = $decoded['results'] ?? $decoded['hints'] ?? $decoded['records'] ?? [];
            if (! is_array($results)) {
                continue;
            }

            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }
                if (count($proposals) >= $maxPerRun) {
                    break 2;
                }

                $source = trim((string) ($result['source'] ?? 'source_search_all'));
                $title = trim((string) ($result['title'] ?? $result['name'] ?? 'source record'));
                $date = trim((string) ($result['date'] ?? ''));
                $description = trim((string) ($result['description'] ?? $result['snippet'] ?? ''));

                // Name-match sanity filter: require at least one query token (len >= 3)
                // to appear in the result title, description, or URL. Without this we'd
                // attach "James King" FindAGrave memorials to "Dichtli Neimand" queries
                // simply because the tool returned them — tree poisoning.
                // URL is checked because LoC returns generic titles like
                // "Image 8 of Laurel outlook" but the person's name is in the ?q=... param.
                // Configurable via agents.queue_source_strict_namematch (default true).
                $urlForMatch = trim((string) ($result['url'] ?? $result['id'] ?? ''));
                if ((bool) config('agents.queue_source_strict_namematch', true)) {
                    if (! $this->resultHasQueryOverlap($query, $title, $description, $urlForMatch)) {
                        continue;
                    }
                }

                // PersonService::applyProposedChange can only consume URL-shaped
                // source_add locators (see PersonService.php:1225-1254). Find a
                // URL in whatever field the tool carries it in, or construct one
                // from the source+id using the config/genealogy.php template map.
                // Unknown providers with non-URL ids still get emitted as
                // structured JSON proposals (source_id fallback).
                $locator = $this->normalizeQueueModeSourceLocator($result, $source);
                if ($locator === '' || isset($seenLocators[$locator])) {
                    continue;
                }
                $seenLocators[$locator] = true;

                $summaryParts = ["{$source}: {$title}"];
                if ($date !== '') {
                    $summaryParts[] = "dated {$date}";
                }
                if ($description !== '') {
                    $summaryParts[] = mb_substr($description, 0, 160);
                }
                if ($query !== '') {
                    $summaryParts[] = "query: {$query}";
                }

                $proposals[] = [
                    'person_id' => $personId,
                    'change_type' => 'source_add',
                    'field_name' => null,
                    'proposed_value' => $locator,
                    'evidence_sources' => array_values(array_filter([$source, $baseToolName])),
                    'evidence_summary' => mb_substr(implode(' — ', $summaryParts), 0, 400),
                    'confidence' => 0.65,
                ];
            }
        }

        return $proposals;
    }

    /**
     * Kept for call sites that expect a single optional proposal; delegates to the
     * plural variant and returns the first result.
     */
    private function buildQueueModeSourceProposal(int $personId, array $reportToolResults): ?array
    {
        $proposals = $this->buildQueueModeSourceProposals($personId, $reportToolResults);

        return $proposals[0] ?? null;
    }

    /**
     * Lenient name-match check between a search query and a result title/description.
     *
     * Returns true when at least one query token (length >= 3, lower-cased, stripped of
     * punctuation) appears in the haystack of title + description. Guards against the
     * extractor attaching unrelated records (e.g., FindAGrave's "James King" memorial
     * returned in response to a "Dichtli Neimand" query) as source_add proposals.
     *
     * Empty query returns true (nothing to compare against — operator will catch at
     * review stage). Result with empty title + description returns false (nothing to
     * match, inherently suspect).
     */
    private function resultHasQueryOverlap(string $query, string $title, string $description, string $url = ''): bool
    {
        $query = trim($query);
        if ($query === '') {
            return true;
        }

        // URL-decode so "?q=michael+eyer" contributes "michael eyer" to the haystack.
        $decodedUrl = $url !== '' ? urldecode(str_replace('+', ' ', $url)) : '';
        $haystack = strtolower(trim($title.' '.$description.' '.$decodedUrl));
        if ($haystack === '') {
            return false;
        }

        $normalized = (string) preg_replace('/[^a-z0-9\s]+/', ' ', strtolower($query));
        $tokens = array_values(array_filter(
            preg_split('/\s+/', $normalized) ?: [],
            static fn ($t) => strlen((string) $t) >= 3
        ));

        if ($tokens === []) {
            return true; // queries of only short tokens (initials, etc.) bypass check
        }

        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a URL-shaped locator from a result row, using every configured
     * locator field plus the source-name → URL-template map in config.
     *
     * Returns:
     *   - an http(s) URL found in any `source_locator_fields` entry
     *   - a URL constructed from `source_url_templates[<matching source key>]`
     *   - '' when nothing applies (caller may emit a structured-JSON proposal
     *     downstream so the operator still sees it in review)
     *
     * Flexible by design: new providers require only a config entry in
     * config/genealogy.php — no PHP change here.
     */
    private function normalizeQueueModeSourceLocator(array $result, string $source): string
    {
        $locatorFields = (array) config('genealogy.source_locator_fields', ['url', 'id']);

        // 1. Walk every configured locator field for a ready-made URL.
        foreach ($locatorFields as $field) {
            $candidate = trim((string) ($result[$field] ?? ''));
            if ($candidate !== '' && preg_match('/^https?:\/\//i', $candidate)) {
                return $candidate;
            }
        }

        // 2. Collect non-URL id candidates for template substitution.
        $rawId = '';
        foreach ($locatorFields as $field) {
            $candidate = trim((string) ($result[$field] ?? ''));
            if ($candidate !== '' && ! preg_match('/^https?:\/\//i', $candidate)) {
                $rawId = $candidate;
                break;
            }
        }
        if ($rawId === '') {
            return '';
        }

        // 3. Match source name against the template map (substring, normalized).
        $sourceKey = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $source)));
        $templates = (array) config('genealogy.source_url_templates', []);

        // Reject ids with whitespace or characters that wouldn't survive URL
        // construction. Legit record ids are ASCII-safe.
        if (! preg_match('/^[A-Za-z0-9:_\-\.\/]+$/', $rawId)) {
            return '';
        }

        // Prefer longest-matching configured key so "national archives" beats "nara",
        // "findagrave" beats "find", etc. Prior order-dependent substring match could
        // pick the wrong template when the config had overlapping keys. (Block 7 #4.)
        $keys = array_keys($templates);
        usort($keys, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

        foreach ($keys as $key) {
            $template = $templates[$key] ?? null;
            if ($key === '' || $template === null || ! str_contains($sourceKey, (string) $key)) {
                continue;
            }
            $built = strtr((string) $template, ['{id}' => $rawId, '{ark}' => $rawId]);
            if (filter_var($built, FILTER_VALIDATE_URL) !== false
                && preg_match('/^https?:\/\//i', $built)) {
                return $built;
            }
        }

        return '';
    }

    private function summarizeQueueModeToolResult(string $toolName, string $resultText): string
    {
        $decoded = json_decode($resultText, true);

        return match ($toolName) {
            'source_search_all' => is_array($decoded)
                ? $this->summarizeQueueSourceSearchAll($decoded)
                : $this->summarizeQueueSourceSearchAllFromText($resultText),
            'generate_record_hints' => is_array($decoded)
                ? $this->summarizeQueueRecordHints($decoded)
                : $this->summarizeQueueRecordHintsFromText($resultText),
            'update_search_coverage' => is_array($decoded)
                ? $this->summarizeQueueCoverageUpdate($decoded)
                : $this->summarizeQueueCoverageUpdate(['message' => $resultText]),
            default => mb_substr(preg_replace('/\s+/', ' ', $resultText), 0, 220),
        };
    }

    private function summarizeQueueSourceSearchAll(array $decoded): string
    {
        $query = trim((string) ($decoded['query'] ?? ''));
        $sources = array_values(array_filter(array_map('strval', $decoded['sources_searched'] ?? [])));
        $totalResults = (int) ($decoded['total_results'] ?? count($decoded['results'] ?? []));
        $results = $decoded['results'] ?? [];

        $topEvidence = [];
        foreach (array_slice($results, 0, 2) as $result) {
            if (! is_array($result)) {
                continue;
            }
            $source = trim((string) ($result['source'] ?? 'source'));
            $title = trim((string) ($result['title'] ?? $result['name'] ?? 'record'));
            if ($title !== '') {
                $topEvidence[] = "{$source}: {$title}";
            }
        }

        $parts = [];
        if ($query !== '') {
            $parts[] = "Search for {$query}";
        }
        $parts[] = "{$totalResults} result(s)";
        if (! empty($sources)) {
            $parts[] = 'sources '.implode(', ', array_slice($sources, 0, 4));
        }
        if (! empty($topEvidence)) {
            $parts[] = 'top '.implode(' | ', $topEvidence);
        }

        return mb_substr(implode('; ', $parts), 0, 220);
    }

    private function summarizeQueueRecordHints(array $decoded): string
    {
        $generated = (int) ($decoded['hints_generated'] ?? count($decoded['hints'] ?? []));
        $hints = $decoded['hints'] ?? [];
        if ($generated <= 0 || empty($hints)) {
            return 'Record hints generated: 0';
        }

        $topHints = [];
        foreach (array_slice($hints, 0, 2) as $hint) {
            if (! is_array($hint)) {
                continue;
            }
            $type = trim((string) ($hint['record_type'] ?? $hint['type'] ?? 'record'));
            $source = trim((string) ($hint['source_name'] ?? $hint['source'] ?? 'source'));
            $topHints[] = "{$type} via {$source}";
        }

        $summary = "Record hints generated: {$generated}";
        if (! empty($topHints)) {
            $summary .= '; top '.implode(' | ', $topHints);
        }

        return mb_substr($summary, 0, 220);
    }

    private function summarizeQueueSourceSearchAllFromText(string $resultText): string
    {
        $parts = [];

        if (preg_match('/"query"\s*:\s*"([^"]+)"/', $resultText, $m)) {
            $parts[] = 'Search for '.$m[1];
        }

        if (preg_match('/"total_results"\s*:\s*(\d+)/', $resultText, $m)) {
            $parts[] = "{$m[1]} result(s)";
        }

        if (preg_match('/"sources_searched"\s*:\s*\[(.*?)\]/s', $resultText, $m)) {
            preg_match_all('/"([^"]+)"/', $m[1], $sources);
            $sourceList = array_slice($sources[1] ?? [], 0, 4);
            if (! empty($sourceList)) {
                $parts[] = 'sources '.implode(', ', $sourceList);
            }
        }

        if (empty($parts)) {
            return mb_substr(preg_replace('/\s+/', ' ', $resultText), 0, 220);
        }

        return mb_substr(implode('; ', $parts), 0, 220);
    }

    private function summarizeQueueRecordHintsFromText(string $resultText): string
    {
        if (preg_match('/"hints_generated"\s*:\s*(\d+)/', $resultText, $m)) {
            return "Record hints generated: {$m[1]}";
        }

        return mb_substr(preg_replace('/\s+/', ' ', $resultText), 0, 220);
    }

    private function summarizeQueueCoverageUpdate(array $decoded): string
    {
        $message = trim((string) ($decoded['message'] ?? $decoded['result'] ?? ''));
        if ($message !== '') {
            return mb_substr($message, 0, 220);
        }

        return 'Search coverage updated';
    }

    private function renderQueueModeWorkflowReport(string $personName, int $personId, ?array $reportData, array $toolCalls): string
    {
        $summary = trim((string) ($reportData['summary'] ?? ''));
        if ($summary === '') {
            $summary = "Queue-mode genealogy run completed for {$personName} (#{$personId}).";
        }

        $findings = $reportData['persons_researched'][0]['findings']
            ?? $reportData['persons_found'][0]['findings']
            ?? $summary;
        if (is_array($findings)) {
            $findings = implode('; ', array_filter(array_map('strval', $findings)));
        }
        $findings = trim((string) $findings);
        if ($findings === '') {
            $findings = $summary;
        }

        $outcomeState = trim((string) ($reportData['outcome_state'] ?? ''));
        if ($outcomeState === '') {
            $hasProposals = ! empty($reportData['proposed_changes']) || ! empty($reportData['proposed_relationships']) || ! empty($reportData['proposed_marriages']);
            $outcomeState = $hasProposals ? 'completed' : 'deferred';
        }

        $outcomeReason = trim((string) ($reportData['outcome_reason'] ?? ''));
        if ($outcomeReason === '') {
            $outcomeReason = $outcomeState === 'completed'
                ? 'Evidence-backed finding prepared for human review.'
                : 'No supported change found; preserve for future follow-up.';
        }

        $scopeReason = trim((string) ($reportData['scope_reason'] ?? 'none'));
        $relatedPeople = $reportData['related_people_used'] ?? [];
        $sourcesChecked = $reportData['sources_checked'] ?? [];
        $evidenceSummary = trim((string) ($reportData['evidence_summary'] ?? $findings));
        $conflictsFound = trim((string) ($reportData['conflicts_found'] ?? 'none'));

        $report = "## Genealogy Research Report (Queue Mode)\n\n";
        $report .= "### {$personName} (ID: {$personId})\n";
        $report .= $summary."\n\n";
        $report .= "Findings: {$findings}\n\n";
        $report .= '**Tools used:** '.count($toolCalls).' | **Mode:** deterministic queue'."\n\n";
        $report .= 'OUTCOME_STATE: '.$outcomeState."\n";
        $report .= 'OUTCOME_REASON: '.$outcomeReason."\n";
        $report .= 'SCOPE_REASON: '.$scopeReason."\n";
        $report .= 'RELATED_PEOPLE_USED: '.(! empty($relatedPeople) ? implode(', ', $relatedPeople) : 'none')."\n";
        $report .= 'SOURCES_CHECKED: '.(! empty($sourcesChecked) ? implode(', ', $sourcesChecked) : 'none')."\n";
        $report .= 'EVIDENCE_SUMMARY: '.($evidenceSummary !== '' ? $evidenceSummary : $summary)."\n";
        $report .= 'CONFLICTS_FOUND: '.($conflictsFound !== '' ? $conflictsFound : 'none');

        return $report;
    }

    private function submitQueueModeGenealogyFindingReview(
        string $agentId,
        string $personName,
        int $personId,
        array $reportData,
        array &$toolCalls
    ): void {
        $personProposals = array_merge(
            array_values(array_filter($reportData['proposed_changes'] ?? [], 'is_array')),
            array_values(array_filter($reportData['proposed_relationships'] ?? [], 'is_array')),
            array_values(array_filter($reportData['proposed_marriages'] ?? [], 'is_array'))
        );

        $reviewableProposals = $this->filterQueueModeReviewableGenealogyProposals($personProposals);
        // Post-deploy gap fix (2026-04-24): yesterday's GPS Sprint #3 wired
        // ProposalValidatorService into PersonService::proposeChange — but
        // hybrid-output bundled proposals from the per-person queue mode
        // bypass that path entirely (they go straight into
        // agent_review_queue.details.proposals[] via submitForReview).
        // Run the same validator gates here so the Mary Billington / Samuel
        // Howard cross-name false matches get filtered before assembly.
        $reviewableProposals = $this->applyProposalValidatorGates(
            $reviewableProposals,
            (int) $personId,
            $agentId
        );
        $actionable = ! empty($reviewableProposals) && $this->shouldQueueGenealogyFindingReview($personProposals);

        // Prefer clean-title rows over synthetic "— search complete" rows for the same person.
        $existing = DB::selectOne(
            "SELECT id FROM agent_review_queue
             WHERE agent_id = ? AND review_type = 'genealogy_finding' AND status = 'pending'
             AND JSON_EXTRACT(details, '$.person_id') = ?
             ORDER BY title LIKE '% — search complete%' ASC, id DESC
             LIMIT 1",
            [$agentId, (int) $personId]
        );

        // Merge path: refresh the pending row when this run produced actionable proposals.
        if ($existing && $actionable) {
            $confidence = (float) ($reportData['persons_researched'][0]['confidence'] ?? 0.5);
            $this->mergePendingGenealogyFindingProposals(
                (int) $existing->id,
                $agentId,
                $personId,
                $personName,
                $reviewableProposals,
                $confidence,
                $confidence < 0.5 ? 2 : 1,
            );
            $toolCalls[] = [
                'tool' => 'submit_for_review',
                'success' => true,
                'phase' => 'report',
                'merged_into' => (int) $existing->id,
                'reason' => 'pending_finding_refreshed_with_new_proposals',
            ];

            return;
        }

        // Non-actionable re-run with an existing pending row: touch updated_at so the
        // operator sees the agent actively retried this person without spamming a
        // second synthetic marker onto the queue.
        if ($existing && ! $actionable) {
            if (empty($personProposals)) {
                return;
            }
            DB::update(
                'UPDATE agent_review_queue SET updated_at = NOW() WHERE id = ? AND status = ?',
                [(int) $existing->id, 'pending']
            );
            Log::info('AgentLoop: genealogy_finding pending row touched (no new actionable proposals)', [
                'existing_id' => (int) $existing->id,
                'agent_id' => $agentId,
                'person_id' => $personId,
                'raw_proposals' => count($personProposals),
            ]);
            $toolCalls[] = [
                'tool' => 'submit_for_review',
                'success' => true,
                'phase' => 'report',
                'synthetic' => true,
                'reason' => 'progress_signal_touched_existing',
            ];

            return;
        }

        // Progress signal: when the agent ran a search for this person but surfaced
        // only generic coverage summaries (no concrete proposals), still submit a
        // low-priority review item so the operator sees the agent is active on this
        // person. Dedup above prevents flooding; the operator-in-loop memory (see
        // feedback_operator_in_review_loop.md) values visibility over strict filtering.
        if (! $actionable) {
            if (empty($personProposals)) {
                return; // Nothing happened at all — don't fabricate activity
            }

            $sourcesChecked = array_values(array_filter(
                $reportData['sources_checked'] ?? [],
                fn ($s) => is_string($s) && trim($s) !== ''
            ));
            $coverageSummary = (string) ($reportData['evidence_summary']
                ?? $reportData['summary']
                ?? '(no evidence summary)');

            $syntheticProposal = [
                'person_id' => $personId,
                'change_type' => 'search_complete',
                'field_name' => null,
                'proposed_value' => '0 actionable records from '.count($personProposals).' raw result(s)',
                'evidence_sources' => $sourcesChecked !== [] ? $sourcesChecked : ['update_search_coverage'],
                'evidence_summary' => mb_substr($coverageSummary, 0, 400),
                'confidence' => 0.30,
                'notes' => 'No actionable records extracted. LLM produced search-coverage summary only.',
            ];

            $reviewDetails = [
                'person_id' => $personId,
                'person_name' => $personName,
                'proposals' => [$syntheticProposal],
                'raw_proposal_count' => count($personProposals),
                'filtered_out_count' => count($personProposals) - count($reviewableProposals),
            ];

            $this->submitForReview([
                'agent_id' => $agentId,
                'review_type' => 'genealogy_finding',
                'title' => "{$personName} (#{$personId}) — search complete, no actionable records",
                'summary' => 'Searched '.count($sourcesChecked).' source(s); '.count($personProposals).' raw result(s); 0 actionable extractions. Operator progress signal.',
                'confidence' => 0.30,
                'priority' => 0,
                'details' => $reviewDetails,
            ]);

            $toolCalls[] = [
                'tool' => 'submit_for_review',
                'success' => true,
                'phase' => 'report',
                'synthetic' => true,
                'reason' => 'progress_signal_no_actionable',
            ];

            return;
        }

        $confidence = (float) ($reportData['persons_researched'][0]['confidence'] ?? 0.5);

        $reviewDetails = [
            'person_id' => $personId,
            'person_name' => $personName,
            'proposals' => $reviewableProposals,
        ];

        $decorated = app(\App\Services\ReviewTypeRegistryService::class)->decorateItemForDisplay('genealogy_finding', [
            'summary' => '',
            'details' => $reviewDetails,
        ]);
        $summary = (string) ($decorated['details_human'] ?? $decorated['summary'] ?? '');

        $review = $this->submitForReview([
            'agent_id' => $agentId,
            'review_type' => 'genealogy_finding',
            'title' => "{$personName} (#{$personId})",
            'summary' => $summary,
            'confidence' => $confidence,
            'priority' => $confidence < 0.5 ? 2 : 1,
            'details' => $reviewDetails,
        ]);

        $toolCalls[] = [
            'tool' => 'submit_for_review',
            'success' => ! empty($review['review_id']) || ! empty($review['token']),
            'phase' => 'report',
            'synthetic' => true,
        ];
    }

    /**
     * Run each bundled proposal through ProposalValidatorService — the
     * same gates that PersonService::proposeChange runs for the
     * single-row insertion path. Rejected proposals get logged via
     * validateAndLog (gate name + reason) and excluded from the
     * returned array.
     *
     * Post-deploy gap fix (2026-04-24): yesterday's GPS Sprint #3
     * wired the validator into proposeChange but the genealogy-records
     * hybrid agent in per_person queue mode never goes through that
     * path — it bundles all proposals per person and submits via
     * submitForReview. The Mary Billington / Samuel Howard / Benjamin
     * Carpenter cross-name proposals slipped past defense layer 1
     * because of this miss. Now both paths run the same gates.
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  int  $personId  Target person
     * @param  string  $agentId  Calling agent
     * @return array<int, array<string, mixed>> Survivors only
     */
    private function applyProposalValidatorGates(array $proposals, int $personId, string $agentId): array
    {
        if ($proposals === []) {
            return [];
        }
        $validator = app(\App\Services\Genealogy\ProposalValidatorService::class);
        $survivors = [];
        $rejectedByGate = [];
        foreach ($proposals as $proposal) {
            $changeType = (string) ($proposal['change_type'] ?? $proposal['relationship_type'] ?? 'notes_append');
            $proposedValue = $proposal['proposed_value'] ?? null;
            // Some proposal shapes use proposed_name (relationships); others
            // wrap the value under a different key. Normalize for the
            // validator so the temporal gate sees text it can scan.
            if ($proposedValue === null) {
                $proposedValue = $proposal['proposed_name'] ?? $proposal['proposed_value'] ?? '';
            }
            $proposedValueStr = is_array($proposedValue) ? json_encode($proposedValue) : (string) $proposedValue;

            $result = $validator->validateAndLog(
                $personId,
                $changeType,
                $proposal['field_name'] ?? null,
                $proposedValueStr,
                (string) ($proposal['evidence_summary'] ?? ''),
                is_array($proposal['evidence_sources'] ?? null) ? $proposal['evidence_sources'] : [],
                $agentId
            );
            if ($result['ok'] === true) {
                $survivors[] = $proposal;
            } else {
                $gate = (string) ($result['gate'] ?? 'unknown');
                $rejectedByGate[$gate] = ($rejectedByGate[$gate] ?? 0) + 1;
            }
        }
        Log::warning('ProposalValidator: bundled genealogy gate summary', [
            'person_id' => $personId,
            'agent_id' => $agentId,
            'input_count' => count($proposals),
            'survivor_count' => count($survivors),
            'rejected_count' => count($proposals) - count($survivors),
            'rejected_by_gate' => $rejectedByGate,
        ]);

        return $survivors;
    }

    private function shouldQueueGenealogyFindingReview(array $proposals): bool
    {
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $changeType = $proposal['change_type'] ?? $proposal['relationship_type'] ?? 'notes_append';
            if ($changeType !== 'notes_append') {
                return true;
            }
        }

        return ! empty($this->filterQueueModeReviewableGenealogyProposals($proposals));
    }

    private function filterQueueModeReviewableGenealogyProposals(array $proposals): array
    {
        $reviewable = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            if ($this->isConcreteQueueModeGenealogyProposal($proposal)) {
                $reviewable[] = $proposal;
            }
        }

        return $reviewable;
    }

    private function isConcreteQueueModeGenealogyProposal(array $proposal): bool
    {
        $changeType = $proposal['change_type'] ?? $proposal['relationship_type'] ?? 'notes_append';

        if ($changeType === 'source_add') {
            $value = trim((string) ($proposal['proposed_value'] ?? ''));

            return $value !== '' && (
                preg_match('/^https?:\/\//i', $value) === 1
                || ctype_digit($value)
            );
        }

        if ($changeType === 'notes_append') {
            $text = trim((string) ($proposal['evidence_summary'] ?? $proposal['proposed_value'] ?? ''));
            if ($text === '' || $this->isNegativeGenealogyFindingText($text)) {
                return false;
            }

            if ($this->isGenericQueueModeSearchActivitySummary($text)) {
                return false;
            }

            if (preg_match('/\bhttps?:\/\/\S+/i', $text) === 1) {
                return true;
            }

            return true;
        }

        return true;
    }

    private function isGenericQueueModeSearchActivitySummary(string $text): bool
    {
        $normalized = trim($text);

        return str_contains($normalized, 'update_search_coverage')
            || preg_match('/"success"\s*:\s*true/i', $normalized) === 1
            || preg_match('/\\"success\\"\s*:\s*true/i', $normalized) === 1
            || preg_match('/^search for .+;\s*\d+\s+result\(s\);/i', $normalized) === 1
            || preg_match('/\b\d+\s+result\(s\)\b/i', $normalized) === 1
            || preg_match('/\brecord hints generated:\s*0\b/i', $normalized) === 1
            || preg_match('/\bsources\s+[A-Z][^.;]*$/i', $normalized) === 1;
    }

    private function isNegativeGenealogyFindingText(string $text): bool
    {
        return (bool) (
            preg_match(
                '/^(none|nothing|zero|empty$)|^no records|^no results|found no records|found nothing|negative result|exhaustive search.*no|could not locate|unable to find/i',
                trim($text)
            ) && ! preg_match(
                '/\b(but found|however found|did find|also found|found a|found the|found one|found evidence)\b/i',
                $text
            )
        );
    }

    /**
     * Build parameters for report-phase tools based on accumulated research data.
     */
    private function buildReportToolParams(string $toolName, string $agentId, array $targetPersons, array $phaseResults): array
    {
        $summaryText = '';
        foreach ($phaseResults as $phase => $result) {
            $summaryText .= "## {$phase}\n{$result}\n\n";
        }

        switch ($toolName) {
            case 'submit_for_review':
                $personNames = array_filter(array_map(fn ($p) => $p['name'] ?? null, array_slice($targetPersons, 0, 5)));
                $titleSuffix = ! empty($personNames) ? implode(', ', $personNames) : 'No persons selected this run';
                $personCount = count($targetPersons);
                // Build human-readable summary — phase results are JSON analysis, not readable prose
                $humanSummary = "Hybrid workflow completed. Researched {$personCount} person(s): ".implode(', ', $personNames ?: ['(none selected)']).".\n\n"
                    .'Individual research findings are submitted as separate review items (genealogy_finding type) with per-person summaries and proposed changes.';
                // Extract any key_findings from the last phase's JSON if present
                $lastPhase = end($phaseResults);
                if ($lastPhase) {
                    $decoded = json_decode(preg_replace('/^```json?\s*\n?|\n?```$/m', '', trim($lastPhase)), true);
                    if (isset($decoded['key_findings']) && ! empty($decoded['key_findings'])) {
                        $humanSummary .= "\n\nKey findings:\n";
                        foreach (array_slice($decoded['key_findings'], 0, 5) as $f) {
                            $humanSummary .= '- '.(is_string($f) ? $f : json_encode($f))."\n";
                        }
                    }
                }

                // N118: This aggregate summary is informational only — per-person
                // review items (genealogy_finding type) carry the actual proposals.
                // Auto-approve by marking as status type so it doesn't clutter the queue.
                return [
                    'agent_id' => $agentId,
                    'review_type' => 'status',
                    'title' => 'Genealogy research summary: '.$titleSuffix,
                    'summary' => $humanSummary,
                    'confidence' => 0.6,
                    'priority' => 0,
                ];

            case 'create_research_task':
                $firstPerson = $targetPersons[0] ?? null;
                if (! $firstPerson || empty($firstPerson['id'])) {
                    return [];
                }
                // Skip if an open task already exists — avoid duplicates every run
                $existingTask = DB::selectOne(
                    "SELECT id FROM gps_research_tasks WHERE person_id = ? AND status IN ('open','in_progress') ORDER BY created_at DESC LIMIT 1",
                    [(int) $firstPerson['id']]
                );
                if ($existingTask) {
                    return [];
                }

                return [
                    'person_id' => (int) $firstPerson['id'],
                    'question' => "Continue research for {$firstPerson['name']}: verify findings and search additional sources",
                    'task_type' => 'other',
                ];

            case 'log_research_search':
                // Resolve a real task_id: look for an existing open task for the first person,
                // or fall back to the most recently created task in this session.
                $taskId = 0;
                $firstPerson = $targetPersons[0] ?? null;
                if ($firstPerson && ! empty($firstPerson['id'])) {
                    $existingTask = DB::selectOne(
                        "SELECT id FROM gps_research_tasks WHERE person_id = ? AND status IN ('open','in_progress') ORDER BY created_at DESC LIMIT 1",
                        [(int) $firstPerson['id']]
                    );
                    if ($existingTask) {
                        $taskId = (int) $existingTask->id;
                    }
                }
                if ($taskId === 0) {
                    // Nothing found — skip log_research_search gracefully so it does not fail
                    return [];
                }

                return [
                    'task_id' => $taskId,
                    'search_details' => [
                        'repository_searched' => 'Multiple (LOC newspapers, Internet Archive, RAG, SearXNG, genealogy DB)',
                        'search_terms' => implode('; ', array_map(fn ($p) => $p['name'] ?? '', $targetPersons)),
                        'results_summary' => 'Hybrid workflow completed '.count($targetPersons).' person(s) research',
                        'negative_result' => false,
                    ],
                ];

            case 'rag_index':
                return [
                    'content' => $summaryText,
                    'title' => 'Genealogy research session '.now()->format('Y-m-d H:i'),
                    'document_type' => 'genealogy_research',
                ];

            case 'post_agent_message':
                return [
                    'from_agent' => $agentId,
                    'subject' => 'Hybrid research completed',
                    'body' => 'Completed hybrid research for '.count($targetPersons).' person(s)',
                    'message_type' => 'finding',
                ];

            case 'propose_change':
                // Build one propose_change call per target person with findings
                // Return params for the FIRST person — the framework calls per-person anyway
                // But report phase doesn't iterate, so we build a batch here
                return $this->buildProposalParamsFromPhaseResults('change', $agentId, $targetPersons, $phaseResults);

            case 'propose_relationship':
                return $this->buildProposalParamsFromPhaseResults('relationship', $agentId, $targetPersons, $phaseResults);

            default:
                return [];
        }
    }

    /**
     * Build proposal params from accumulated phase results.
     *
     * For report-phase propose_change/propose_relationship tools: the hybrid framework
     * can't iterate per-person for these, so instead we skip them here (return []) and
     * rely on the LLM final-report JSON extraction path (processProposals) to handle
     * proposal creation properly from structured output.
     *
     * This method returns [] intentionally — proposals come from LLM structured JSON,
     * not from framework-driven tool calls with synthesized params.
     */
    private function buildProposalParamsFromPhaseResults(string $type, string $agentId, array $targetPersons, array $phaseResults): array
    {
        // Don't attempt to synthesize proposal params from phase results.
        // The propose_change/propose_relationship tools need specific, validated data
        // (exact person_id, change_type, proposed_value with evidence) that can only
        // come from the LLM's structured final_report output.
        //
        // Returning [] causes the tool call to be skipped (line 1091 check).
        // The actual proposals are processed via AgentProposalService.processProposals()
        // from the LLM's final_report JSON (lines 1347-1354).
        return [];
    }

    /**
     * Check which required parameters of a tool cannot be satisfied from context.
     *
     * Returns an array of unsatisfied required parameter names.
     * Empty array = all required params can be satisfied.
     *
     * Used by deterministic/hybrid modes to skip tools that need LLM-provided
     * params (e.g. handoff_to_agent needs "reason", mcp_searxng_search needs "query").
     */
    private function getUnsatisfiedRequiredParams(array $toolDef, array $availableContext): array
    {
        $unsatisfied = [];
        $paramDefs = $toolDef['parameters'] ?? [];

        foreach ($paramDefs as $paramName => $paramDef) {
            if (empty($paramDef['required'])) {
                continue;
            }

            // Check if the required param can be found in the available context
            $snakeName = strtolower(preg_replace('/[A-Z]/', '_$0', $paramName));
            if (isset($availableContext[$paramName]) || isset($availableContext[$snakeName])) {
                continue;
            }

            $unsatisfied[] = $paramName;
        }

        return $unsatisfied;
    }

    /**
     * Attempt to extend the job's wall-clock timeout after productive work.
     * The closure (from SchedulerRunCommand) resets pcntl_alarm and updates Redis.
     * Returns true if extension was granted, false if denied or unavailable.
     */
    private function callTimeoutExtender(?\Closure $extender, int $newTotalMinutes, string $reason = ''): bool
    {
        if ($extender === null) {
            return false;
        }
        try {
            $result = $extender($newTotalMinutes);
            if ($result) {
                Log::info("AgentLoop: Timeout extended to {$newTotalMinutes}min", ['reason' => $reason]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('AgentLoop: Timeout extension failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Validate LLM phase response: enforce structured JSON, detect drift.
     *
     * Returns ['valid' => bool, 'reason' => string, 'data' => ?array]
     */
    private function validatePhaseResponse(string $response, string $phaseName, int $phaseIdx, int $totalPhases): array
    {
        if (empty(trim($response))) {
            return ['valid' => false, 'reason' => 'Empty response', 'data' => null];
        }

        // Extract JSON from response (may be wrapped in ```json ... ``` or raw)
        $json = null;
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/^\s*(\{[\s\S]*\})\s*$/m', $response, $matches)) {
            $json = $matches[1];
        }

        if (! $json) {
            // Allow free-text on final phase as fallback (report is valuable even unstructured)
            if ($phaseIdx === $totalPhases - 1 && strlen($response) > 200) {
                return ['valid' => true, 'reason' => 'Free-text final report accepted', 'data' => null];
            }

            return ['valid' => false, 'reason' => 'No JSON block found in response. Wrap output in ```json ... ```', 'data' => null];
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'reason' => 'Invalid JSON: '.json_last_error_msg(), 'data' => null];
        }

        // Validate intermediate phases
        if ($phaseIdx < $totalPhases - 1) {
            if ($this->isOperationalHybridReport($data)) {
                if (empty($data['key_findings']) && empty($data['issues'])) {
                    return ['valid' => false, 'reason' => 'Operational phase must include key_findings or issues.', 'data' => null];
                }
                if (empty($data['next_phase_targets'])) {
                    return ['valid' => false, 'reason' => 'Operational phase must include next_phase_targets.', 'data' => null];
                }

                return ['valid' => true, 'reason' => 'OK', 'data' => $data];
            }
            if (empty($data['key_findings']) && empty($data['persons_found'])) {
                return ['valid' => false, 'reason' => 'Missing key_findings and persons_found. Analyze the tool results.', 'data' => null];
            }
            if (empty($data['next_phase_targets'])) {
                Log::warning('AgentLoop: next_phase_targets is empty — no research targets, aborting remaining phases');

                return ['valid' => false, 'reason' => 'No research targets identified. If nothing to research, produce a final summary instead.', 'data' => null];
            }
        }

        // Validate final phase — accept both final_report and analyze formats
        if ($phaseIdx === $totalPhases - 1) {
            if ($this->isOperationalHybridReport($data)) {
                $hasOperationalContent = ! empty($data['summary']) || ! empty($data['key_findings']) || ! empty($data['findings']) || ! empty($data['issues']);
                if (! $hasOperationalContent) {
                    return ['valid' => false, 'reason' => 'Operational final report must include summary, key_findings, findings, or issues.', 'data' => null];
                }
                if (! empty($data['findings']) && is_array($data['findings'])) {
                    foreach ($data['findings'] as $finding) {
                        if (! is_array($finding)) {
                            return ['valid' => false, 'reason' => 'Operational findings must be objects.', 'data' => null];
                        }
                        if (empty($finding['title']) && empty($finding['summary'])) {
                            return ['valid' => false, 'reason' => 'Operational findings need a title or summary.', 'data' => null];
                        }
                        $severity = strtolower((string) ($finding['severity'] ?? ''));
                        if ($severity !== '' && ! in_array($severity, self::OPERATIONAL_SEVERITIES, true)) {
                            return ['valid' => false, 'reason' => 'Operational finding severity must be critical/high/medium/low.', 'data' => null];
                        }
                    }
                }

                return ['valid' => true, 'reason' => 'OK', 'data' => $data];
            }
            $hasContent = ! empty($data['persons_researched']) || ! empty($data['summary'])
                || ! empty($data['persons_found']) || ! empty($data['key_findings']);
            if (! $hasContent) {
                return ['valid' => false, 'reason' => 'Final report must include persons_researched/persons_found or summary/key_findings.', 'data' => null];
            }

            // Enforce correct phase label — LLMs often return "research" or "analyze" instead
            $phaseLabel = $data['phase'] ?? '';
            if ($phaseLabel && $phaseLabel !== 'final_report') {
                Log::warning('AgentLoop: Final phase has wrong label, correcting', [
                    'got' => $phaseLabel,
                    'expected' => 'final_report',
                ]);
                $data['phase'] = 'final_report';
            }

            // Validate that proposals exist when persons have findings
            $hasProposals = ! empty($data['proposed_changes']) || ! empty($data['proposed_relationships']) || ! empty($data['proposed_marriages']);
            $persons = $data['persons_researched'] ?? $data['persons_found'] ?? [];
            $hasFindings = false;
            foreach ($persons as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $findings = $p['findings'] ?? '';
                if (! is_string($findings)) {
                    $findings = is_array($findings) ? implode('; ', array_filter($findings)) : (string) $findings;
                }
                // Person has meaningful findings if:
                // 1. Findings text is substantial
                // 2. Not a negative-result description (catches "no records", "found no",
                //    "exhaustive search found nothing", "Researched X — no records", etc.)
                // 3. Contains positive evidence indicators (dates, record types, places)
                // This mirrors the synthesis heuristic in the fallback path so they stay in sync.
                // Check for purely negative findings. Guard against "No X, but found Y" false-positives
                // by requiring that no positive-evidence words appear after the negative phrase.
                $findingsTrimmed = trim($findings);
                $isNegative = preg_match(
                    '/^(none|nothing|zero|empty$)|^no records|^no results|found no records|found nothing|negative result|exhaustive search.*no|could not locate|unable to find/i',
                    $findingsTrimmed
                ) && ! preg_match(
                    '/\b(but found|however found|did find|also found|found a|found the|found one|found evidence|record found|records found)\b/i',
                    $findingsTrimmed
                );
                $hasPositiveEvidence = preg_match(
                    '/(found|record|census|certificate|document|source|born|died|married|buried|\d{4})/i',
                    $findings
                );
                if (strlen($findings) > 30 && ! $isNegative && $hasPositiveEvidence) {
                    $hasFindings = true;
                    break;
                }
            }
            if ($hasFindings && ! $hasProposals) {
                return [
                    'valid' => false,
                    'reason' => 'You found research data for persons but proposed_changes, proposed_relationships, and proposed_marriages are ALL empty. '
                        .'Every fact discovered (dates, places, sources, relationships) MUST be converted into a proposal. '
                        .'Review the findings for each person and create at least one proposed_change per person with findings.',
                    'data' => null,
                ];
            }
        }

        return ['valid' => true, 'reason' => 'OK', 'data' => $data];
    }

    private function isOperationalHybridReport(array $data): bool
    {
        $hasOperationalSignals = isset($data['status']) || isset($data['findings']) || isset($data['issues']);
        $hasPersonSignals = isset($data['persons_researched']) || isset($data['persons_found'])
            || isset($data['proposed_changes']) || isset($data['proposed_relationships']) || isset($data['proposed_marriages']);

        return $hasOperationalSignals && ! $hasPersonSignals;
    }

    private function renderOperationalHybridReport(array $data, int $toolCallCount): string
    {
        $report = "## Operational Report\n\n";

        if (! empty($data['status'])) {
            $report .= '**Status:** '.strtoupper((string) $data['status'])."\n\n";
        }

        if (! empty($data['summary'])) {
            $report .= $this->stringifyHybridField($data['summary'])."\n\n";
        }

        if (! empty($data['key_findings']) && is_array($data['key_findings'])) {
            $report .= "### Key Findings\n\n";
            foreach ($data['key_findings'] as $finding) {
                $report .= '- '.$this->stringifyHybridField($finding)."\n";
            }
            $report .= "\n";
        }

        if (! empty($data['findings']) && is_array($data['findings'])) {
            $report .= "### Findings\n\n";
            foreach ($data['findings'] as $finding) {
                if (is_array($finding)) {
                    $severity = strtoupper((string) ($finding['severity'] ?? 'info'));
                    $title = $this->stringifyHybridField($finding['title'] ?? 'Untitled');
                    $summary = $this->stringifyHybridField($finding['summary'] ?? '');
                    $action = $this->stringifyHybridField($finding['action'] ?? '');
                    $report .= "- [{$severity}] {$title}";
                    if ($summary !== '') {
                        $report .= " | {$summary}";
                    }
                    if ($action !== '') {
                        $report .= " | Action: {$action}";
                    }
                    $report .= "\n";
                } else {
                    $report .= '- '.$this->stringifyHybridField($finding)."\n";
                }
            }
            $report .= "\n";
        }

        if (! empty($data['issues']) && is_array($data['issues'])) {
            $report .= "### Issues\n\n";
            foreach ($data['issues'] as $issue) {
                $report .= '- '.$this->stringifyHybridField($issue)."\n";
            }
            $report .= "\n";
        }

        if (! empty($data['follow_up_tasks']) && is_array($data['follow_up_tasks'])) {
            $report .= "### Follow-Up Tasks\n\n";
            foreach ($data['follow_up_tasks'] as $task) {
                $report .= '- '.$this->stringifyHybridField($task)."\n";
            }
            $report .= "\n";
        }

        $totalFindings = is_array($data['findings'] ?? null)
            ? count($data['findings'])
            : (int) ($data['total_findings'] ?? 0);

        $report .= "\n**Tools used:** ".($data['total_tools_used'] ?? $toolCallCount)
            .' | **Findings:** '.$totalFindings;

        return $report;
    }

    private function stringifyHybridField(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $stringified = $this->stringifyHybridField($item);
                if ($stringified !== '') {
                    $parts[] = $stringified;
                }
            }

            return implode('; ', $parts);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Extract next_phase_targets actions for a specific person from the report data.
     */
    private function extractNextActionsForPerson(mixed $personId, array $fd): array
    {
        $actions = [];
        foreach ($fd['next_phase_targets'] ?? [] as $target) {
            $tid = $target['person_id'] ?? null;
            if ($tid !== null && (string) $tid === (string) $personId && ! empty($target['action'])) {
                $actions[] = $target['action'];
            }
        }

        return $actions;
    }

    /**
     * N48c/N105: Extract structured fact proposals from narrative research findings.
     *
     * N105 upgrade: Uses spaCy en_core_web_sm (CPU-only, 50-200ms) when available.
     * Falls back to regex when spaCy is not installed (zero behavioral change).
     *
     * Parses dates, places, occupations from text like "born 1864", "died in Springfield, IL".
     * Returns fact_update proposals; caller falls back to generic source_add if empty.
     */
    /**
     * N119b: Get a person's current field value from the genealogy tree.
     * Used to detect proposals that duplicate existing data.
     */
    private function getPersonFieldValue(int $personId, string $field): ?string
    {
        $columnMap = [
            'birth_date' => 'birth_date',
            'death_date' => 'death_date',
            'birth_place' => 'birth_place',
            'death_place' => 'death_place',
            'occupation' => 'occupation',
        ];
        $column = $columnMap[$field] ?? null;
        if (! $column) {
            return null;
        }
        try {
            $row = DB::selectOne(
                "SELECT `{$column}` FROM genealogy_persons WHERE id = ?",
                [$personId]
            );

            return $row ? ($row->$column ?? null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * N119b: Strip assess-phase metadata from findings text.
     * Assess metadata looks like "Tier 1 bloodline ancestor, priority rank N, no birth/death data, never searched"
     * and gets prepended when findings are concatenated across phases.
     */
    private function stripAssessMetadata(string $findings): string
    {
        // Remove leading assess metadata (before pipe separator or after initial sentence)
        $cleaned = preg_replace(
            '/^(Tier \d+ )?bloodline ancestor,?\s*priority rank \d+[^|]*(\|\s*)?/i',
            '',
            $findings
        );
        // Remove other assess patterns that may appear anywhere
        $cleaned = preg_replace(
            '/\b(Tier \d+ bloodline ancestor|priority rank \d+|never searched|no birth\/death data|Missing info hint)[,.\s]*/i',
            '',
            $cleaned
        );
        // Clean up leftover pipe separators and whitespace
        $cleaned = preg_replace('/^\s*\|\s*/', '', $cleaned);
        $cleaned = preg_replace('/\s*\|\s*$/', '', $cleaned);

        return trim($cleaned);
    }

    private function extractStructuredFacts(string $findings, int $personId): array
    {
        // N105: Try spaCy first
        try {
            $spacy = app(\App\Services\SpacyNLPService::class);
            $extraction = $spacy->extract($findings);
            if ($extraction !== null && ! empty($extraction['facts'])) {
                return $spacy->toFactProposals($extraction, $personId, $findings);
            }
        } catch (\Throwable $e) {
            Log::debug('AgentLoopService: spaCy extraction unavailable, using regex fallback', ['error' => $e->getMessage()]);
        }

        // Regex fallback (N48c original)
        $proposals = [];

        // Birth date: "born 1864", "birth date: 15 Mar 1864", "b. 1864"
        if (preg_match('/\b(?:born|birth[- ]?date|b\.)\s*:?\s*(\d{1,2}\s+\w+\s+\d{4}|\d{4})\b/i', $findings, $m)) {
            $proposals[] = [
                'person_id' => $personId,
                'change_type' => 'fact_update',
                'field_name' => 'birth_date',
                'proposed_value' => trim($m[1]),
                'confidence' => 0.5,
            ];
        }

        // Death date: "died 1920", "death date: 26 May 2020", "d. 1920"
        if (preg_match('/\b(?:died|death[- ]?date|d\.)\s*:?\s*(\d{1,2}\s+\w+\s+\d{4}|\d{4})\b/i', $findings, $m)) {
            $proposals[] = [
                'person_id' => $personId,
                'change_type' => 'fact_update',
                'field_name' => 'death_date',
                'proposed_value' => trim($m[1]),
                'confidence' => 0.5,
            ];
        }

        // Birth place: "born in Springfield, IL", "birthplace: Springfield"
        if (preg_match('/\b(?:born in|birth[- ]?place)\s*:?\s*([A-Z][a-zA-Z\s,]+(?:,\s*[A-Z]{2})?)\b/i', $findings, $m)) {
            $proposals[] = [
                'person_id' => $personId,
                'change_type' => 'fact_update',
                'field_name' => 'birth_place',
                'proposed_value' => trim($m[1]),
                'confidence' => 0.5,
            ];
        }

        // Death place: "died in Chicago", "death place: Cook County"
        if (preg_match('/\b(?:died in|death[- ]?place)\s*:?\s*([A-Z][a-zA-Z\s,]+(?:,\s*[A-Z]{2})?)\b/i', $findings, $m)) {
            $proposals[] = [
                'person_id' => $personId,
                'change_type' => 'fact_update',
                'field_name' => 'death_place',
                'proposed_value' => trim($m[1]),
                'confidence' => 0.5,
            ];
        }

        // Occupation: "occupation: farmer", "worked as a blacksmith"
        if (preg_match('/\b(?:occupation|worked as(?: a)?|employed as(?: a)?)\s*:?\s*([a-zA-Z\s]+)\b/i', $findings, $m)) {
            $proposals[] = [
                'person_id' => $personId,
                'change_type' => 'fact_update',
                'field_name' => 'occupation',
                'proposed_value' => trim($m[1]),
                'confidence' => 0.4,
            ];
        }

        // Add evidence metadata to all extracted proposals
        foreach ($proposals as &$p) {
            $p['evidence_sources'] = ['agent-extracted from research findings'];
            $p['evidence_summary'] = substr($findings, 0, 300);
        }

        return $proposals;
    }

    /**
     * Build the system prompt from skill definition + context + tools
     */
    private function buildSystemPrompt(string $agentId, string $skillInstructions, array $skillConfig, array $options, string $toolDescriptions = ''): string
    {
        $parts = [];

        // Compact identity — agent-scoped, no personal details
        $parts[] = "Agent: {$agentId} | Date: ".now()->format('Y-m-d H:i')
            .' | System: PLOS (self-hosted, offline-first, all data private)';

        if (! empty($options['tree_id'])) {
            $parts[0] .= " | Tree: {$options['tree_id']}";
        }

        // Skill instructions
        if ($skillInstructions) {
            $parts[] = "## Skill\n\n{$skillInstructions}";
        }

        // Tool descriptions
        if ($toolDescriptions) {
            $parts[] = $toolDescriptions;
        }

        // Inter-agent messages (only if any exist — skip the query overhead otherwise)
        try {
            $messages = $this->getAgentMessages($agentId, 5, true);
            if (! empty($messages)) {
                $msgLines = ['## Messages'];
                foreach ($messages as $msg) {
                    $pri = ((int) ($msg['priority'] ?? 0)) >= 2 ? '[URGENT] ' : '';
                    $msgLines[] = "- {$pri}**{$msg['from_agent']}**: {$msg['subject']}";
                    $this->acknowledgeMessage((int) $msg['id'], $agentId);
                }
                $parts[] = implode("\n", $msgLines);
            }
        } catch (\Throwable $e) {
            Log::debug('AgentLoopService: message bus injection failed', ['agent' => $agentId, 'error' => $e->getMessage()]);
        }

        // AG-5: Memory retrieval gating
        $task = $options['task'] ?? '';
        $memoryGate = ['procedural' => true, 'episodic' => true, 'cross_agent' => true];
        if ($task) {
            try {
                $memoryGate = $this->getMemoryGating()->gate($agentId, $task);
            } catch (\Throwable $e) {
                Log::debug('AgentLoopService: memory gating failed', ['agent' => $agentId]);
            }
        }

        // Procedural memory (gated)
        if ($memoryGate['procedural'] && $task) {
            try {
                $ctx = $this->getProceduralMemory()->buildContextForTask($agentId, $task);
                if ($ctx) {
                    $parts[] = $ctx;
                }
            } catch (\Throwable $e) {
            }
        }

        // Episodic memory (gated)
        if ($memoryGate['episodic'] && $task) {
            try {
                $ctx = $this->getEpisodicMemory()->buildContextForTask($agentId, $task);
                if ($ctx) {
                    $parts[] = $ctx;
                }
            } catch (\Throwable $e) {
            }
        }

        // Cross-agent insights (gated)
        if ($memoryGate['cross_agent'] && $task) {
            try {
                $ctx = $this->getEpisodicMemory()->recallCrossAgentInsights($agentId, $task);
                if ($ctx) {
                    $parts[] = $ctx;
                }
            } catch (\Throwable $e) {
            }
        }

        // Constitution — binding principles
        $constitutionRules = $this->getConstitution()->getRules($agentId, $skillConfig);
        $constitutionFragment = $this->getConstitution()->buildSystemPromptFragment($constitutionRules);
        if ($constitutionFragment) {
            $parts[] = $constitutionFragment;
        }

        // Framework C1 — compact run-memory slice (goal/constraints/decisions/open
        // questions/verification state). Built fresh on every iteration so tool
        // results recorded since the last iteration are visible to the LLM.
        $runMemorySid = $options['run_memory_session_id'] ?? null;
        if ($runMemorySid) {
            try {
                $runMemoryFragment = $this->getRunMemory()->renderSystemPromptFragment($runMemorySid);
                if ($runMemoryFragment !== '') {
                    $parts[] = $runMemoryFragment;
                }
            } catch (\Throwable $e) {
                Log::debug('AgentLoopService: run memory fragment render failed', [
                    'session' => $runMemorySid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Retrieve relevant agent memory from RAG
     */
    private function retrieveAgentMemory(string $agentId, string $task, array $options): ?string
    {
        try {
            $results = $this->getRAGService()->search($task, 3, null, false);

            if (empty($results)) {
                return null;
            }

            $memoryParts = [];
            foreach ($results as $result) {
                $doc = $result['document'];
                $similarity = round($result['similarity'] ?? 0, 3);
                $title = $doc->title ?? 'Untitled';
                $preview = substr($doc->content ?? '', 0, 500);
                $memoryParts[] = "### {$title} (relevance: {$similarity})\n{$preview}";
            }

            return implode("\n\n", $memoryParts);

        } catch (Exception $e) {
            Log::debug('AgentLoop: Memory retrieval failed (non-fatal)', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Index agent findings into RAG with agent_id scoping
     */
    private function indexFindings(string $agentId, string $task, string $response, array $options): void
    {
        try {
            $title = "Agent [{$agentId}] findings: ".substr($task, 0, 100);
            $this->getRAGService()->indexDocument(
                'agent_finding',
                $response,
                $title,
                [
                    'agent_id' => $agentId,
                    'task' => substr($task, 0, 500),
                    'tree_id' => $options['tree_id'] ?? null,
                    'indexed_at' => now()->toIso8601String(),
                ],
                null,
                'agent',
            );

            Log::debug('AgentLoop: Findings indexed to RAG', [
                'agent_id' => $agentId,
                'content_length' => strlen($response),
            ]);
        } catch (Exception $e) {
            Log::warning('AgentLoop: Failed to index findings (non-fatal)', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format messages array for AIService->process()
     */
    private function formatMessagesForAI(array $messages): string
    {
        $formatted = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                continue; // System prompt handled separately
            }
            $role = $msg['role'] === 'assistant' ? 'Assistant' : 'User';
            $formatted[] = "{$role}: {$msg['content']}";
        }

        if (! empty($formatted)) {
            return implode("\n\n", $formatted);
        }

        return ! empty($messages) ? (end($messages)['content'] ?? '') : '';
    }

    /**
     * Record an episode to agent memory
     */
    private function recordEpisode(string $agentId, string $sessionId, string $eventType, string $summary, array $details = []): void
    {
        try {
            $skillVersion = $details['skill_version'] ?? ($this->getSkillLoader()->getVersionInfo($agentId)['version'] ?? null);
            DB::insert('
                INSERT INTO agent_episodes (agent_id, skill_version, session_id, event_type, summary, details, tokens_used, duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ', [
                $agentId,
                $skillVersion,
                $sessionId,
                $eventType,
                substr($summary, 0, 100000),
                json_encode($details),
                $details['tokens_used'] ?? 0,
                $details['duration_ms'] ?? 0,
            ]);
        } catch (Exception $e) {
            Log::debug('AgentLoop: Failed to record episode (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send Pushover notification on agent completion (alerts only)
     *
     * Only notifies on actual problems: tool failures, alerts, degraded status,
     * or action-required findings. Routine healthy/all-clear runs are fully
     * suppressed — no notification at all.
     */
    private function notifyCompletion(string $agentId, string $task, string $response, int $durationMs, array $toolCalls = []): void
    {
        // DISABLED: Agent Pushover notifications suspended pending unified reporting enhancement
        return;

        try {
            $hasFailures = collect($toolCalls)->contains(fn ($tc) => ! $tc['success']);
            $hasAlerts = $this->hasAlertIndicators($response);

            // Only notify when something actually needs attention
            if (! $hasFailures && ! $hasAlerts) {
                Log::debug('AgentLoop: Notification suppressed (routine/healthy)', ['agent_id' => $agentId]);

                return;
            }

            $title = "Agent: {$agentId}";
            $message = '';
            if (! empty($toolCalls)) {
                $toolNames = array_unique(array_column($toolCalls, 'tool'));
                $failCount = collect($toolCalls)->filter(fn ($tc) => ! $tc['success'])->count();
                $toolSummary = implode(', ', $toolNames).' ('.count($toolCalls).' calls';
                if ($failCount > 0) {
                    $toolSummary .= ", {$failCount} failed";
                }
                $message .= "Tools: {$toolSummary})\n\n";
            }
            $message .= 'Result: '.substr($response, 0, 600)."\n\n";
            $message .= 'Duration: '.round($durationMs / 1000, 1).'s';

            $controller = app(\App\Controllers\NotificationController::class);
            $result = $controller->send('pushover', [
                'source_group' => 'agent_run_summary',
                'title' => $title,
                'message' => $message,
                'priority' => $hasFailures ? 1 : 0,
                'sound' => $hasFailures ? 'siren' : 'pushover',
            ]);

            if (empty($result['success'])) {
                Log::warning('AgentLoop: Terminal summary Pushover failed', [
                    'agent_id' => $agentId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        } catch (Exception $e) {
            Log::warning('AgentLoop: Pushover notification failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send a concise Pushover summary after a hybrid workflow run completes.
     * Covers phases, providers, quality signals, and review items.
     */
    private function sendHybridRunSummary(string $agentId): void
    {
        if (empty($this->hybridRunMetrics)) {
            return;
        }

        try {
            $m = $this->hybridRunMetrics;
            $elapsed = microtime(true) - ($m['start_time'] ?? microtime(true));
            $elapsedMin = (int) round($elapsed / 60);
            $budgetMin = (int) ($m['timeout_minutes'] ?? 0);

            // Phase summary
            $completed = $m['phases_completed'] ?? 0;
            $total = $m['total_phases'] ?? 0;
            $skipped = $m['phases_skipped'] ?? [];
            $phaseStatus = "{$completed}/{$total} phases";
            if (empty($skipped)) {
                $phaseStatus .= " \xE2\x9C\x93"; // UTF-8 check mark
            } else {
                $phaseStatus .= ' (skipped: '.implode(', ', $skipped).')';
            }

            // Provider summary — group by provider, show count
            $providers = $m['phase_providers'] ?? [];
            $providerCounts = [];
            foreach ($providers as $phase => $provider) {
                $shortProvider = $this->shortenProviderName($provider);
                $providerCounts[$shortProvider] = ($providerCounts[$shortProvider] ?? 0) + 1;
            }
            $providerParts = [];
            foreach ($providerCounts as $name => $count) {
                $providerParts[] = "{$name}".($count > 1 ? " ({$count})" : '');
            }
            $providerLine = implode(' + ', $providerParts) ?: 'unknown';

            // Quality line
            $templates = $m['template_detections'] ?? 0;
            $escalations = $m['claude_escalations'] ?? 0;
            $filtered = $m['proposals_filtered'] ?? 0;

            // Review items line
            $reviewCount = $m['review_items_submitted'] ?? 0;
            $reviewTypes = $m['review_item_types'] ?? [];
            $reviewLine = "{$reviewCount} submitted";
            if (! empty($reviewTypes)) {
                $typeParts = [];
                foreach ($reviewTypes as $type => $count) {
                    $typeParts[] = "{$count} {$type}";
                }
                $reviewLine .= ' ('.implode(', ', $typeParts).')';
            }

            // Short label for title
            $shortLabel = str_contains($agentId, 'genealogy') ? 'Genea' : ucfirst(explode('-', $agentId)[0]);

            $message = "{$shortLabel} Run: {$phaseStatus}\n"
                ."Model: {$providerLine}\n"
                ."Quality: {$templates} templates, {$escalations} escalations, {$filtered} filtered\n"
                ."Items: {$reviewLine}\n"
                ."Time: {$elapsedMin}min / {$budgetMin}min budget";

            $hasIssues = $templates > 0 || $escalations > 1 || ! empty($skipped);

            $controller = app(\App\Controllers\NotificationController::class);
            $result = $controller->send('pushover', [
                'source_group' => 'agent_run_summary',
                'title' => "{$shortLabel} Run Summary",
                'message' => $message,
                'priority' => $hasIssues ? 0 : -1, // low priority for clean runs
                'sound' => $hasIssues ? 'pushover' : 'none',
            ]);

            if (empty($result['success'])) {
                Log::warning('AgentLoop: Hybrid run summary Pushover failed', [
                    'agent_id' => $agentId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }

            Log::info('AgentLoop: Hybrid run summary sent', [
                'agent_id' => $agentId,
                'phases' => "{$completed}/{$total}",
                'review_items' => $reviewCount,
                'templates' => $templates,
                'escalations' => $escalations,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AgentLoop: Hybrid run summary notification failed', ['error' => $e->getMessage()]);
        }

        // Reset metrics
        $this->hybridRunMetrics = [];
    }

    /**
     * Shorten LLM provider name for notification display.
     */
    private function shortenProviderName(string $provider): string
    {
        $map = [
            'ollama' => 'Ollama',
            'claude' => 'Claude',
            'claude_cli' => 'Claude',
            'openrouter' => 'OpenRouter',
            'sambanova' => 'SambaNova',
            'cerebras' => 'Cerebras',
            'groq' => 'Groq',
            'gemini' => 'Gemini',
            'mistral' => 'Mistral',
            'deepinfra' => 'DeepInfra',
        ];

        $lower = strtolower($provider);
        foreach ($map as $key => $short) {
            if (str_contains($lower, $key)) {
                return $short;
            }
        }

        // If provider includes model name (e.g., "ollama/qwen2.5:14b"), extract model
        if (str_contains($provider, '/')) {
            $parts = explode('/', $provider);
            $providerName = $map[strtolower($parts[0])] ?? $parts[0];

            return $providerName.'/'.($parts[1] ?? '');
        }

        return $provider;
    }

    /**
     * Detect if an agent response contains alert/problem indicators that warrant notification.
     *
     * Uses structured severity patterns (e.g., "State: CRITICAL", "Severity: HIGH")
     * rather than bare keyword matching, to avoid false positives from agents that
     * mention these words in routine status reports or prose descriptions.
     */
    private function hasAlertIndicators(string $response): bool
    {
        // Structured severity patterns — match how agents actually report problems.
        // Avoids bare keyword matching that false-positives on routine prose.
        $structuredPatterns = [
            '/\bstate:\s*(critical|degraded)/i',
            '/\bseverity:\s*(critical|high)/i',
            '/\bstatus:\s*(critical|degraded|down|unreachable|failing)/i',
            '/\baction:\s*(escalate|investigate)\b/i',
            '/\b(critical|high)\s+findings?\b/i',
            '/\bfindings:\s*\[?\s*[1-9]\d*\s*critical/i',  // "Findings: 2 critical" but NOT "0 critical"
            '/\b[1-9]\d*\s+critical\b/i',                   // "3 critical" but NOT "0 critical"
            '/\baction_required\b/i',
            '/\bneeds[_ ]attention\b/i',
            '/\burgent\b/i',
            '/\boutage\b/i',
            '/\bunresponsive\b/i',
            '/\ball\s+providers?\s+(failing|down|unavailable)\b/i',
        ];

        foreach ($structuredPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get recent episodes for an agent
     */
    public function getRecentEpisodes(string $agentId, int $limit = 20): array
    {
        try {
            return array_map(function ($row) {
                return [
                    'id' => $row->id,
                    'event_type' => $row->event_type,
                    'summary' => $row->summary,
                    'details' => json_decode($row->details, true),
                    'tokens_used' => $row->tokens_used,
                    'duration_ms' => $row->duration_ms,
                    'created_at' => $row->created_at,
                ];
            }, DB::select(
                'SELECT * FROM agent_episodes WHERE agent_id = ? ORDER BY created_at DESC LIMIT ?',
                [$agentId, $limit]
            ));
        } catch (Exception $e) {
            Log::debug('AgentLoopService: episode history query failed', ['agent' => $agentId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    // =========================================================================
    // HUMAN REVIEW QUEUE
    // =========================================================================

    /**
     * Merge new genealogy_finding proposals into an existing pending row.
     *
     * Prior behavior was to silently skip the write when a pending row existed
     * for the same person_id. That caused a workstoppage once the pending
     * backlog grew: every re-run for those persons wrote nothing and operators
     * never saw the newest evidence. This refreshes the pending row in place.
     */
    private function mergePendingGenealogyFindingProposals(
        int $existingId,
        string $agentId,
        int $personId,
        string $personName,
        array $newProposals,
        float $newConfidence,
        int $newPriority
    ): void {
        $existing = DB::selectOne(
            'SELECT details, confidence, priority FROM agent_review_queue WHERE id = ? AND status = ?',
            [$existingId, 'pending']
        );
        if (! $existing) {
            Log::warning('AgentLoop: merge target vanished before update', [
                'existing_id' => $existingId,
                'agent_id' => $agentId,
                'person_id' => $personId,
            ]);

            return;
        }

        $existingDetails = is_string($existing->details) ? json_decode($existing->details, true) : $existing->details;
        if (! is_array($existingDetails)) {
            $existingDetails = [];
        }
        $existingProposals = is_array($existingDetails['proposals'] ?? null) ? $existingDetails['proposals'] : [];

        $keyOf = static function (array $p): string {
            $type = (string) ($p['change_type'] ?? $p['relationship_type'] ?? '');
            $field = (string) ($p['field_name'] ?? '');
            $value = (string) ($p['proposed_value'] ?? $p['proposed_name'] ?? '');

            return sha1($type.'|'.$field.'|'.$value);
        };

        $realTypes = ['fact_update', 'event_add', 'source_add', 'media_link', 'notes_append',
            'residence_add', 'family_event_update', 'external_record_link', 'source_create',
            'clipping_link', 'media_metadata_update'];
        $newHasReal = false;
        foreach ($newProposals as $p) {
            if (is_array($p) && in_array($p['change_type'] ?? null, $realTypes, true)) {
                $newHasReal = true;
                break;
            }
        }

        $indexed = [];
        foreach ($existingProposals as $p) {
            if (! is_array($p)) {
                continue;
            }
            // Drop prior synthetic "search_complete" markers once the agent finds real evidence.
            if ($newHasReal && ($p['change_type'] ?? null) === 'search_complete') {
                continue;
            }
            $indexed[$keyOf($p)] = $p;
        }

        $addedCount = 0;
        $upgradedCount = 0;
        foreach ($newProposals as $p) {
            if (! is_array($p)) {
                continue;
            }
            $k = $keyOf($p);
            if (! isset($indexed[$k])) {
                $indexed[$k] = $p;
                $addedCount++;

                continue;
            }
            $existingConf = (float) ($indexed[$k]['confidence'] ?? 0);
            $newConf = (float) ($p['confidence'] ?? 0);
            if ($newConf > $existingConf) {
                $indexed[$k] = $p;
                $upgradedCount++;
            }
        }

        $mergedProposals = array_values($indexed);
        $mergedDetails = $existingDetails;
        $mergedDetails['person_id'] = $personId;
        $mergedDetails['person_name'] = $personName;
        $mergedDetails['proposals'] = $mergedProposals;
        unset($mergedDetails['raw_proposal_count'], $mergedDetails['filtered_out_count']);

        $existingConfidence = (float) ($existing->confidence ?? 0);
        $mergedConfidence = max($existingConfidence, $newConfidence);

        // Priority is 0=normal, 1=high, 2=urgent. Merge via max() so an urgent
        // pending review is never silently downgraded by a weaker re-run.
        $existingPriority = (int) ($existing->priority ?? 0);
        $mergedPriority = max($existingPriority, $newPriority);

        $mergedDetails = app(AgentOutputQualityGateService::class)->enrichReviewDetails([
            'agent_id' => $agentId,
            'review_type' => 'genealogy_finding',
            'finding_type' => 'genealogy_finding',
            'title' => $personName,
            'summary' => '',
            'confidence' => $mergedConfidence,
            'priority' => $mergedPriority,
        ], $mergedDetails);

        $decorated = app(\App\Services\ReviewTypeRegistryService::class)->decorateItemForDisplay('genealogy_finding', [
            'summary' => '',
            'details' => $mergedDetails,
        ]);
        $mergedSummary = (string) ($decorated['details_human'] ?? $decorated['summary'] ?? '');

        // Intentionally do NOT update title — the pending_dedup_key virtual unique
        // index (migration 2026_04_17_175500) is built from agent_id|review_type|title,
        // so rewriting the title to match another pending row for the same person
        // would trigger SQLSTATE 23000 / error 1062. Fresh evidence surfaces via
        // details + summary instead.
        try {
            DB::update(
                'UPDATE agent_review_queue
                 SET details = ?, summary = ?, confidence = ?, priority = ?, updated_at = NOW()
                 WHERE id = ? AND status = ?',
                [
                    json_encode($mergedDetails),
                    $mergedSummary,
                    $mergedConfidence,
                    $mergedPriority,
                    $existingId,
                    'pending',
                ]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('AgentLoop: genealogy_finding merge UPDATE failed', [
                'existing_id' => $existingId,
                'agent_id' => $agentId,
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('AgentLoop: genealogy_finding merged into pending row', [
            'existing_id' => $existingId,
            'agent_id' => $agentId,
            'person_id' => $personId,
            'proposals_before' => count($existingProposals),
            'proposals_added' => $addedCount,
            'proposals_upgraded' => $upgradedCount,
            'proposals_after' => count($mergedProposals),
            'confidence_before' => $existingConfidence,
            'confidence_after' => $mergedConfidence,
            'synthetic_markers_dropped' => $newHasReal,
        ]);
    }

    /**
     * Submit an item to the human review queue
     *
     * Agents call this when they find something that requires human approval
     * or when a workflow wants to record a reviewable item.
     */
    /**
     * Confidence threshold for generic finding auto-approval.
     * Genealogy findings are never auto-approved.
     */
    /** @see config/agents.php auto_approve_confidence */
    public function submitForReview(array $params): array
    {
        $agentId = $params['agent_id'] ?? 'unknown';
        $reviewType = $params['review_type'] ?? 'finding';
        $findingType = $params['finding_type'] ?? null; // INF-10e: links to remediation_actions
        $title = $params['title'] ?? 'Agent Review Item';
        $summary = $params['summary'] ?? '';
        $details = $this->sanitizeReviewDetails($params['details'] ?? []);
        $confidence = $params['confidence'] ?? null;
        $priority = (int) ($params['priority'] ?? 0);
        $notify = $params['notify'] ?? true;
        $details = app(AgentOutputQualityGateService::class)->enrichReviewDetails([
            'agent_id' => $agentId,
            'review_type' => $reviewType,
            'finding_type' => $findingType,
            'title' => $title,
            'summary' => $summary,
            'confidence' => $confidence,
            'priority' => $priority,
        ], $details);

        // Auto-approve: high-confidence generic findings (>= 0.90) or routine status reports.
        // genealogy_finding is intentionally excluded and always requires human review.
        $autoApproved = false;
        if ($confidence !== null && $confidence >= config('agents.auto_approve_confidence', 0.90) && $reviewType === 'finding') {
            $autoApproved = true;
        }
        // N118: Routine status reports, alerts, and status changes don't need human review — auto-dismiss.
        // Alerts are operational notifications (transient failures, pipeline state) — not human decisions.
        // isRoutineReport applies regardless of priority to prevent priority-gaming by LLMs.
        if (in_array($reviewType, ['status', 'status_change', 'alert'], true) || $this->isRoutineReport($title, $summary)) {
            $autoApproved = true;
        }
        // N128: Only genealogy_finding, tool_proposal, skill_optimization need human review.
        // Everything else (action, finding, suggestion, etc.) is ops informational with no
        // service handler — approve/reject just toggles status with no side effects.
        if (! in_array($reviewType, ['genealogy_finding', 'tool_proposal', 'skill_optimization'], true)) {
            $autoApproved = true;
        }
        $qualityGateRequiresHumanReview = $this->qualityGateRequiresHumanReview($details);
        if ($qualityGateRequiresHumanReview) {
            $autoApproved = false;
        }

        // Dedup: skip insert if identical item already exists (pending) or was submitted recently (any status).
        // Prevents agents from flooding the queue by re-submitting the same finding every run cycle.
        // Window: 24 hours for auto-approved items, pending items are always deduped regardless of age.
        // Title comparison uses first 80 chars so minor count variations ("4,683 files" vs "4,684 files")
        // on the same recurring issue do not create separate entries.
        $dedupStatusClause = $qualityGateRequiresHumanReview
            ? "status = 'pending'"
            : "(status = 'pending' OR created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR))";
        $existingItem = DB::selectOne(
            "SELECT id, token, status FROM agent_review_queue
             WHERE agent_id = ? AND review_type = ? AND LEFT(title, 80) = LEFT(?, 80)
               AND {$dedupStatusClause}
             ORDER BY id DESC LIMIT 1",
            [$agentId, $reviewType, substr($title, 0, 500)]
        );
        if ($existingItem) {
            Log::info('AgentLoop: Duplicate review item skipped (dedup)', [
                'agent_id' => $agentId,
                'review_type' => $reviewType,
                'title' => $title,
                'existing_id' => $existingItem->id,
                'existing_status' => $existingItem->status,
            ]);

            return [
                'success' => true,
                'review_id' => (int) $existingItem->id,
                'token' => $existingItem->token,
                'deduplicated' => true,
                'message' => 'Identical review item already exists (dedup window)',
            ];
        }

        $token = bin2hex(random_bytes(16));
        $status = $autoApproved ? 'approved' : 'pending';
        $isGenealogyItem = $reviewType === 'genealogy_finding'
            || $reviewType === 'genealogy_merge'
            || $findingType === 'genealogy_finding'
            || str_starts_with((string) $reviewType, 'genealogy_')
            || str_starts_with((string) $agentId, 'genealogy-');
        $expiresAt = ($autoApproved || $isGenealogyItem)
            ? null
            : now()->addDays(config('agents.review_expiry_days', 7))->format('Y-m-d H:i:s');
        $reviewerNotes = $autoApproved ? sprintf('Auto-approved: confidence %.0f%% >= %.0f%% threshold', $confidence * 100, config('agents.auto_approve_confidence', 0.90) * 100) : null;
        $reviewedAt = $autoApproved ? now()->format('Y-m-d H:i:s') : null;

        try {
            DB::insert('
                INSERT INTO agent_review_queue (agent_id, review_type, finding_type, title, summary, details, confidence, priority, status, reviewer_notes, reviewed_at, token, expires_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ', [
                $agentId,
                $reviewType,
                $findingType,
                substr($title, 0, 500),
                $summary,
                json_encode($details),
                $confidence,
                $priority,
                $status,
                $reviewerNotes,
                $reviewedAt,
                $token,
                $expiresAt,
            ]);

            $itemId = DB::getPdo()->lastInsertId();
        } catch (\Illuminate\Database\QueryException $e) {
            // Block 6 finding #5: close the race window between the SELECT-based
            // dedup above and this INSERT. The uk_arq_pending_dedup unique index
            // (see 2026_04_17_175500 migration) catches concurrent inserters.
            // SQLSTATE 23000 = integrity constraint violation; MySQL error 1062
            // inside the driver message confirms it's a duplicate-key hit.
            $sqlState = $e->getCode();
            $isDupKey = $sqlState === '23000' || str_contains($e->getMessage(), '1062');
            if (! $isDupKey) {
                throw $e;
            }

            $raced = DB::selectOne(
                "SELECT id, token, status FROM agent_review_queue
                 WHERE agent_id = ? AND review_type = ? AND LEFT(title, 80) = LEFT(?, 80)
                   AND status = 'pending'
                 ORDER BY id ASC LIMIT 1",
                [$agentId, $reviewType, substr($title, 0, 500)]
            );

            Log::info('AgentLoop: Review insert raced by unique constraint, returning existing', [
                'agent_id' => $agentId,
                'review_type' => $reviewType,
                'title' => substr($title, 0, 80),
                'existing_id' => $raced?->id,
            ]);

            if (! $raced) {
                // Defensive: constraint fired but we can't find the row. Re-throw.
                throw $e;
            }

            return [
                'success' => true,
                'review_id' => (int) $raced->id,
                'token' => $raced->token,
                'deduplicated' => true,
                'raced' => true,
                'message' => 'Concurrent session inserted first; returning existing review row',
            ];
        }

        // INF-3: Audit trail for review submissions
        app(AgentAuditService::class)->recordReviewSubmission(
            sessionId: $params['session_id'] ?? '',
            agentName: $agentId,
            reviewType: $reviewType,
            confidence: (float) ($confidence ?? 0),
            reviewId: (int) $itemId,
        );

        if ($autoApproved) {
            Log::info('AgentLoop: High-confidence finding auto-approved', [
                'id' => $itemId,
                'agent_id' => $agentId,
                'confidence' => $confidence,
                'title' => $title,
            ]);

            // Still notify human, but as informational (not requiring action)
            if ($notify) {
                $this->sendAutoApproveNotification($itemId, $agentId, $title, (float) ($confidence ?? 0.0));
            }
        } else {
            // Suppress Pushover for low-value items — still saved to review queue
            $suppressPushover = false;
            if (in_array($reviewType, ['status', 'suggestion'], true)) {
                $suppressPushover = true; // Informational — review in UI
            }

            if ($notify && ! $suppressPushover) {
                $this->sendReviewNotification($itemId, $token, $agentId, $reviewType, $title, $summary, $confidence, $priority);
            }

            Log::info('AgentLoop: Item submitted for human review', [
                'id' => $itemId,
                'agent_id' => $agentId,
                'review_type' => $reviewType,
                'confidence' => $confidence,
                'pushover_suppressed' => $suppressPushover,
            ]);
        }

        return [
            'success' => true,
            'review_id' => (int) $itemId,
            'token' => $token,
            'status' => $status,
            'auto_approved' => $autoApproved,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Revive expired review items back to pending with a fresh 7-day expiry.
     */
    public function reviveExpiredItems(?string $agentId = null): int
    {
        $params = [];
        $agentClause = '';
        if ($agentId) {
            $agentClause = ' AND agent_id = ?';
            $params[] = $agentId;
        }

        $newExpiry = now()->addDays(7)->format('Y-m-d H:i:s');

        $count = DB::update(
            "UPDATE agent_review_queue
             SET status = 'pending', expires_at = ?, updated_at = NOW()
             WHERE (status = 'expired' OR (status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()))
             {$agentClause}",
            array_merge([$newExpiry], $params)
        );

        if ($count > 0) {
            Log::info("AgentLoop: Revived {$count} expired review items", [
                'agent_id' => $agentId,
                'new_expiry' => $newExpiry,
            ]);
        }

        return $count;
    }

    private function qualityGateRequiresHumanReview(array $details): bool
    {
        $qualityGate = $details['quality_gate'] ?? null;
        if (! is_array($qualityGate)) {
            return false;
        }

        if (strtolower((string) ($qualityGate['risk_label'] ?? '')) === 'blocker') {
            return true;
        }

        $hardFailReasons = $qualityGate['hard_fail_reasons'] ?? [];
        if (is_array($hardFailReasons)) {
            return $hardFailReasons !== [];
        }

        return is_scalar($hardFailReasons) && trim((string) $hardFailReasons) !== '';
    }

    /**
     * Detect routine status reports that don't need human review.
     * These are health checks, pipeline statuses, and "all clear" reports.
     */
    private function isRoutineReport(string $title, string $summary): bool
    {
        // N118: Title patterns that indicate routine/status reports — not actionable review items
        $routineTitlePatterns = [
            'Health Review',
            'Status Report',
            'Pipeline Status',
            'Routine Check',
            'Health Check',
            'System Status',
            'Operational Summary',
            'All Clear',
            'All-Clear',
            'All Healthy',
            'Pipeline Health',
            'Workflow Pipeline Health',
            'No Issues',
            'Everything Healthy',
            'All Systems Operational',
            '100% Success Rate',
            'Email System: All',
            'No Findings',
            'System Healthy',
            'Nothing to Report',
            'Quality Report',           // e.g. "File Metadata Quality Report - Stable"
            'Low Activity',             // e.g. "Research Pipeline Low Activity"
            'Assessment Data Incomplete', // transient operational state
            'Data Incomplete',
            'Investigation Needed',     // agent should investigate, not ask human
            'Currently Running',        // e.g. "Watch Later workflow currently running"
        ];

        foreach ($routineTitlePatterns as $pattern) {
            if (stripos($title, $pattern) !== false) {
                return true;
            }
        }

        // N118: Summary patterns that indicate negative results or status dumps
        $routineSummaryPatterns = [
            '/\bnothing\s+(found|new|to\s+report)\b/i',
            '/\bno\s+(new\s+)?(findings|issues|problems|changes|results)\b/i',
            '/\ball\s+(systems?\s+)?(healthy|operational|clear|normal)\b/i',
            '/\bstable\b.*\bno\s+(action|changes)\b/i',
        ];

        foreach ($routineSummaryPatterns as $regex) {
            if (preg_match($regex, $summary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get pending review items
     */
    public function getPendingReviews(?string $agentId = null, int $limit = 50): array
    {
        $params = [];
        $where = "WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())";

        if ($agentId) {
            $where .= ' AND agent_id = ?';
            $params[] = $agentId;
        }

        $params[] = $limit;

        return array_map(function ($row) {
            $row->details = json_decode($row->details, true);

            return (array) $row;
        }, DB::select(
            "SELECT * FROM agent_review_queue {$where} ORDER BY priority DESC, created_at ASC LIMIT ?",
            $params
        ));
    }

    /**
     * Approve or reject a review item
     */
    public function resolveReview(string $token, bool $approved, ?string $notes = null): array
    {
        $item = DB::selectOne(
            "SELECT * FROM agent_review_queue WHERE token = ? AND status = 'pending'",
            [$token]
        );

        if (! $item) {
            return ['success' => false, 'error' => 'Review item not found or already resolved'];
        }

        $status = $approved ? 'approved' : 'rejected';

        DB::update(
            'UPDATE agent_review_queue SET status = ?, reviewer_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$status, $notes, $item->id]
        );

        Log::info('AgentLoop: Review item resolved', [
            'id' => $item->id,
            'agent_id' => $item->agent_id,
            'status' => $status,
        ]);

        // Registry-driven approval handler dispatch
        $applyResult = null;
        if ($approved) {
            $details = json_decode($item->details ?? '{}', true);
            $applyResult = $this->dispatchApprovalHandler($item, $details);
        }

        return [
            'success' => true,
            'id' => $item->id,
            'status' => $status,
            'agent_id' => $item->agent_id,
            'title' => $item->title,
            'apply_result' => $applyResult,
        ];
    }

    /**
     * Dispatch approval handler from review_type_registry.
     *
     * Looks up the review item's type in review_type_registry. If service_class + approve_method
     * are configured, delegates to that service dynamically. This keeps the agent engine
     * domain-agnostic — any review type can register its own approval handler via DB config.
     */
    private function dispatchApprovalHandler(object $item, array $details): ?array
    {
        // Look up review type in registry for handler config
        $reviewType = $item->review_type ?? null;
        if (! $reviewType) {
            return null;
        }

        $registry = DB::selectOne(
            'SELECT service_class, approve_method FROM review_type_registry WHERE name = ? AND enabled = 1',
            [$reviewType]
        );

        if (! $registry || ! $registry->service_class || ! $registry->approve_method) {
            return null;
        }

        // Determine the item ID to pass to the handler
        $itemId = $details['proposal_id'] ?? $details['item_id'] ?? $details['id'] ?? $item->id;

        try {
            $service = app($registry->service_class);
            $method = $registry->approve_method;

            $result = $service->$method((int) $itemId);

            Log::info('AgentLoop: Dispatched approval handler', [
                'review_type' => $reviewType,
                'service' => $registry->service_class,
                'method' => $method,
                'item_id' => $itemId,
                'result' => $result,
            ]);

            return is_array($result) ? $result : ['success' => true, 'result' => $result];
        } catch (\Throwable $e) {
            Log::error('AgentLoop: Approval handler failed', [
                'review_type' => $reviewType,
                'service' => $registry->service_class,
                'method' => $registry->approve_method,
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Public wrapper: re-push an existing agent_review_queue row to Pushover.
     * Used by review:resend-pushover to flush backlog that was suppressed or
     * never reached the notification path (e.g. historical <0.70 genealogy rule).
     */
    public function resendReviewPushover(int $reviewId): array
    {
        $row = DB::selectOne(
            'SELECT id, agent_id, review_type, title, summary, confidence, priority, status, token
             FROM agent_review_queue WHERE id = ?',
            [$reviewId]
        );

        if (! $row) {
            return ['success' => false, 'error' => "Review item #{$reviewId} not found"];
        }

        if ($row->status !== 'pending') {
            return ['success' => false, 'error' => "Item #{$reviewId} is {$row->status}, not pending"];
        }

        $this->sendReviewNotification(
            (int) $row->id,
            (string) $row->token,
            (string) $row->agent_id,
            (string) $row->review_type,
            (string) $row->title,
            (string) ($row->summary ?? ''),
            $row->confidence !== null ? (float) $row->confidence : null,
            (int) $row->priority,
        );

        return ['success' => true, 'review_id' => (int) $row->id];
    }

    /**
     * Send Pushover notification for a review item with approve/deny URLs
     */
    private function sendReviewNotification(int $itemId, string $token, string $agentId, string $reviewType, string $title, string $summary, ?float $confidence, int $priority): void
    {
        try {
            $agentLabels = [
                'genealogy-researcher' => 'Genealogy',
                'file-ops' => 'Files',
                'log-analyst' => 'Log Analyst',
                'ai-ops' => 'AI Ops',
                'system-guardian' => 'System',
                'research-ops' => 'Research',
                'email-ops' => 'Email',
                'file-curator' => 'File Curator',
                'youtube-ops' => 'YouTube',
                'workflow-ops' => 'Workflows',
                'knowledge-curator' => 'Knowledge',
                'factcheck-ops' => 'Fact Check',
                'data-removal-ops' => 'Data Removal',
                'research-analyst' => 'Research Analyst',
            ];

            $typeLabels = [
                'genealogy_finding' => 'Genealogy Finding',
                'finding' => 'Finding',
                'alert' => 'Alert',
                'tool_proposal' => 'Tool Proposal',
                'skill_optimization' => 'Skill Optimization',
                'log_analyst_finding' => 'Log Analysis',
                'status' => 'Status',
                'suggestion' => 'Suggestion',
            ];

            $agentLabel = $agentLabels[$agentId] ?? ucwords(str_replace('-', ' ', $agentId));
            $typeLabel = $typeLabels[$reviewType] ?? ucwords(str_replace('_', ' ', $reviewType));

            $baseUrl = rtrim((string) config('app.public_url', config('app.url')), '/');
            $unifiedId = $this->buildReviewUnifiedId($reviewType, $itemId, $token);
            $approveUrl = "{$baseUrl}/api/research-hub/quick-approve/{$unifiedId}";
            $rejectUrl = "{$baseUrl}/api/research-hub/quick-reject/{$unifiedId}";
            $viewUrl = "{$baseUrl}/api/research-hub/quick-view/{$unifiedId}";

            $confStr = $confidence !== null ? ' '.round($confidence * 100).'%' : '';
            $priorityLabel = match ($priority) {
                2 => 'URGENT: ',
                1 => '',
                default => '',
            };

            $cleanSummary = $this->sanitizeReviewNotificationSummary($summary);

            $message = "{$priorityLabel}{$title}\n\n";
            $message .= $cleanSummary;

            $controller = app(\App\Controllers\NotificationController::class);

            // Review items use high priority (1) with action buttons for LAN-based approve/deny.
            // Emergency priority (2) reserved for digests only.
            $pushoverPriority = $priority >= 2 ? 1 : ($priority >= 1 ? 1 : 0);

            $notifData = [
                'source_group' => 'agent_approval_review',
                'title' => "{$agentLabel} — {$typeLabel}{$confStr}",
                'message' => $message,
                'priority' => $pushoverPriority,
                'sound' => $priority >= 2 ? 'persistent' : 'pushover',
                'url' => $viewUrl,
                'url_title' => 'View Details',
                'actions' => [
                    ['label' => 'Approve', 'url' => $approveUrl],
                    ['label' => 'Reject', 'url' => $rejectUrl],
                    ['label' => 'View', 'url' => $viewUrl],
                ],
            ];

            $result = $controller->send('pushover', $notifData);

            if (empty($result['success'])) {
                Log::warning('AgentLoop: Review notification Pushover failed', [
                    'item_id' => $itemId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        } catch (Exception $e) {
            Log::warning('AgentLoop: Review notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function buildReviewUnifiedId(string $reviewType, int $itemId, string $token): string
    {
        $default = "{$reviewType}:{$itemId}";

        try {
            $type = app(ReviewTypeRegistryService::class)->getType($reviewType);
            $template = (string) ($type['field_mapping']['unified_id_template'] ?? '');
            if ($template === '') {
                return $default;
            }

            return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($itemId, $token) {
                return match ($matches[1]) {
                    'id' => (string) $itemId,
                    'token' => $token !== '' ? $token : (string) $itemId,
                    default => (string) $itemId,
                };
            }, $template) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function sanitizeReviewNotificationSummary(?string $summary): string
    {
        $cleanSummary = $this->normalizeReviewSummaryText((string) $summary);
        if ($cleanSummary === '') {
            return '';
        }

        $jsonOffset = $this->findStructuredPayloadOffset($cleanSummary);
        if ($jsonOffset !== null) {
            $prefix = rtrim(substr($cleanSummary, 0, $jsonOffset));
            $cleanSummary = $prefix !== ''
                ? "{$prefix} [details in Review Hub]"
                : '[details in Review Hub]';
        }

        return mb_substr($cleanSummary, 0, 300);
    }

    private function normalizeReviewSummaryText(string $summary): string
    {
        $clean = trim($summary);
        if ($clean === '') {
            return '';
        }

        $clean = str_replace(["\r\n", "\r"], "\n", $clean);
        $clean = preg_replace('/[ \t]+/', ' ', $clean) ?? $clean;
        $clean = preg_replace("/ *\n */", "\n", $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;

        return trim($clean);
    }

    private function findStructuredPayloadOffset(string $summary): ?int
    {
        $candidates = [
            '/\{\s*\\\\?"success"\s*:/i',
            '/\{\s*"success"\s*:/i',
            '/\{\s*\\\\?"query"\s*:/i',
            '/\{\s*"query"\s*:/i',
            '/\{\s*\\\\?"sources_searched"\s*:/i',
            '/\{\s*"sources_searched"\s*:/i',
        ];

        foreach ($candidates as $pattern) {
            if (preg_match($pattern, $summary, $matches, PREG_OFFSET_CAPTURE) === 1) {
                return $matches[0][1];
            }
        }

        return null;
    }

    /**
     * Send informational notification for auto-approved findings.
     * Suppressed — auto-approved items don't need human attention.
     * Kept as method for future use if notification granularity is needed.
     */
    private function sendAutoApproveNotification(int $itemId, string $agentId, string $title, float $confidence): void
    {
        // Fully suppressed — auto-approved items are routine, no notification needed
        Log::debug('AgentLoop: Auto-approve notification suppressed', [
            'item_id' => $itemId,
            'agent_id' => $agentId,
            'confidence' => $confidence,
        ]);
    }

    /**
     * Expire old pending review items
     */
    public function expirePendingReviews(): int
    {
        return DB::update(
            "UPDATE agent_review_queue SET status = 'expired', updated_at = NOW() WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
    }

    /**
     * Poll Pushover receipts for all pending review items with receipts.
     * If ack received, auto-approve the review item.
     * Called by scheduled job (every 16 min).
     */
    public function pollPushoverReceipts(): array
    {
        $pending = DB::select("
            SELECT id, token, details
            FROM agent_review_queue
            WHERE status = 'pending' AND details IS NOT NULL
            AND JSON_EXTRACT(details, '$.pushover_receipt') IS NOT NULL
        ");

        if (empty($pending)) {
            return ['checked' => 0, 'approved' => 0];
        }

        $controller = app(\App\Controllers\NotificationController::class);
        $approved = 0;

        foreach ($pending as $item) {
            $details = json_decode($item->details, true);
            $receipt = $details['pushover_receipt'] ?? null;
            if (! $receipt) {
                continue;
            }

            $result = $controller->checkPushoverReceipt($receipt);
            if (! $result['success']) {
                continue;
            }

            if ($result['acknowledged']) {
                // Ack received — auto-approve
                $this->resolveReview($item->token, true, 'Auto-approved via Pushover acknowledgment');
                $approved++;
                Log::info('AgentLoop: Auto-approved review via Pushover ack', ['id' => $item->id]);
            } elseif ($result['expired']) {
                // Receipt expired without ack — leave pending (don't auto-reject)
                Log::info('AgentLoop: Pushover receipt expired without ack', ['id' => $item->id]);
                // Clear the receipt so we don't keep polling it
                DB::update(
                    "UPDATE agent_review_queue SET details = JSON_REMOVE(details, '$.pushover_receipt') WHERE id = ?",
                    [$item->id]
                );
            }
        }

        return ['checked' => count($pending), 'approved' => $approved];
    }

    /**
     * Sanitize review details to strip potential secrets/credentials
     *
     * Agents construct details freely — this prevents leaking sensitive data
     * if an agent inadvertently includes credentials in its findings.
     */
    /**
     * Monitoring agent pre-screen: deterministic health checks before LLM.
     * Returns structured result if healthy (skip LLM), null if anomalies found (proceed).
     * Applies to: ai-ops, system-guardian, log-analyst only.
     */
    private function monitoringPreScreen(string $agentId, array $session): ?array
    {
        $monitoringAgents = ['ai-ops', 'system-guardian', 'log-analyst'];
        if (! in_array($agentId, $monitoringAgents, true)) {
            return null; // Not a monitoring agent — proceed normally
        }

        try {
            $preScreen = app(MonitoringPreScreenService::class);

            $result = match ($agentId) {
                'ai-ops' => $preScreen->preScreenAiOps(),
                'system-guardian' => $preScreen->preScreenSystemGuardian(),
                'log-analyst' => $preScreen->preScreenLogAnalyst(),
                default => null,
            };

            if ($result !== null) {
                Log::info('AgentLoop: Pre-screen all-clear, skipping LLM', [
                    'agent' => $agentId,
                    'session' => $session['session_id'],
                    'checks' => $result['checks'] ?? [],
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::debug('AgentLoop: Pre-screen failed, proceeding with LLM', [
                'agent' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return null; // Fail open — let LLM handle it
        }
    }

    private function sanitizeReviewDetails(array $details): array
    {
        $json = json_encode($details);

        // Strip password/secret/token assignments (key = value patterns)
        $json = preg_replace('/(?:password|passwd|pwd|api_key|apikey|secret|token|bearer)\s*[=:]\s*["\']?[^\s"\'}{,\]]{3,}["\']?/i', '[REDACTED]', $json);

        // Strip Stripe-style keys (sk_live_*, pk_live_*, sk_test_*, etc.)
        $json = preg_replace('/\b[sp]k_(live|test)_[A-Za-z0-9]{10,}\b/', '[REDACTED_KEY]', $json);

        // Strip long base64 strings (40+ chars, likely keys/tokens)
        $json = preg_replace('/\b[A-Za-z0-9+\/]{40,}={0,3}\b/', '[REDACTED_B64]', $json);

        // Strip SSH/PEM private key content
        $json = preg_replace('/-----BEGIN\s+(RSA\s+)?PRIVATE\s+KEY-----.*?-----END\s+(RSA\s+)?PRIVATE\s+KEY-----/s', '[REDACTED_PRIVATE_KEY]', $json);

        return json_decode($json, true) ?? $details;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Agent-to-Agent Message Bus
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Post a message to the agent message bus
     */
    public function postAgentMessage(
        string $from_agent = 'unknown',
        string $to_agent = '*',
        string $message_type = 'info',
        string $subject = '',
        string $body = '',
        ?array $metadata = null,
        int|string $priority = 0,
        int $ttl_hours = 24
    ): array {
        $fromAgent = $from_agent;
        $toAgent = $to_agent;
        $messageType = $message_type;
        $ttlHours = $ttl_hours;

        // Normalize string priority to integer (LLMs may send "high" instead of 1)
        if (is_string($priority)) {
            $priority = match (strtolower($priority)) {
                'urgent' => 2,
                'high' => 1,
                'normal', 'low', '' => 0,
                default => (int) $priority,
            };
        }

        $expiresAt = now()->addHours($ttlHours)->format('Y-m-d H:i:s');

        $db = DB::connection('mysql');
        $db->insert('
            INSERT INTO agent_messages (from_agent, to_agent, message_type, subject, body, metadata, priority, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ', [
            $fromAgent,
            $toAgent,
            $messageType,
            substr($subject, 0, 500),
            $body,
            $metadata ? json_encode($metadata) : null,
            $priority,
            $expiresAt,
        ]);

        $id = $db->getPdo()->lastInsertId();

        Log::info('AgentBus: Message posted', [
            'id' => $id,
            'from' => $fromAgent,
            'to' => $toAgent,
            'type' => $messageType,
            'subject' => $subject,
        ]);

        return [
            'success' => true,
            'message_id' => (int) $id,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Get messages for an agent (addressed to them or broadcast)
     */
    public function getAgentMessages(?string $agentId = null, int $limit = 20, bool $unacknowledgedOnly = false): array
    {
        $where = 'WHERE (expires_at IS NULL OR expires_at > NOW())';
        $params = [];

        if ($agentId) {
            $where .= " AND (to_agent = ? OR to_agent = '*')";
            $params[] = $agentId;

            if ($unacknowledgedOnly) {
                // Exclude messages already acknowledged by this agent
                $where .= " AND (acknowledged_by IS NULL OR NOT JSON_CONTAINS(acknowledged_by, ?, '$'))";
                $params[] = json_encode($agentId);
            }
        }

        $params[] = $limit;

        return array_map(function ($row) {
            $row->metadata = $this->decodeNullableJsonObject($row->metadata ?? null);
            $row->acknowledged_by = $this->decodeNullableJsonObject($row->acknowledged_by ?? null);

            return (array) $row;
        }, DB::connection('mysql')->select(
            "SELECT * FROM agent_messages {$where} ORDER BY priority DESC, created_at DESC LIMIT ?",
            $params
        ));
    }

    /**
     * Acknowledge a message (mark as read by an agent)
     */
    public function acknowledgeMessage(int $messageId, string $agentId): bool
    {
        $msg = DB::connection('mysql')->selectOne('SELECT acknowledged_by FROM agent_messages WHERE id = ?', [$messageId]);
        if (! $msg) {
            return false;
        }

        $acked = $this->decodeNullableJsonObject($msg->acknowledged_by ?? null) ?? [];
        if (! in_array($agentId, $acked)) {
            $acked[] = $agentId;
            DB::connection('mysql')->update(
                'UPDATE agent_messages SET acknowledged_by = ? WHERE id = ?',
                [json_encode($acked), $messageId]
            );
        }

        return true;
    }

    private function decodeNullableJsonObject(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Clean up expired agent messages
     */
    public function cleanupExpiredMessages(): int
    {
        return DB::connection('mysql')->delete(
            'DELETE FROM agent_messages WHERE expires_at IS NOT NULL AND expires_at < NOW()'
        );
    }

    /**
     * N143: Build the assess phase prompt from tool results, with per-tool truncation.
     * Public for testability.
     */
    public function buildAssessPrompt(string $phaseName, array $toolResults): string
    {
        $maxPerToolChars = (int) config('agents.assess_tool_result_max_chars', 3000);
        $prompt = "## Phase '{$phaseName}' Results\n\n";
        foreach ($toolResults as $toolName => $result) {
            $status = $result['success'] ? 'OK' : 'FAILED';
            $resultText = (string) ($result['result_text'] ?? '');
            if (mb_strlen($resultText) > $maxPerToolChars) {
                $resultText = mb_substr($resultText, 0, $maxPerToolChars)
                    ."\n... [truncated — {$toolName} returned ".number_format(mb_strlen((string) ($result['result_text'] ?? '')))." chars, showing first {$maxPerToolChars}]";
            }

            $prompt .= "### {$toolName} [{$status}]\n".$this->formatAgentToolResult(
                $toolName,
                $resultText,
                "agent_assess:{$phaseName}:{$toolName}",
                $maxPerToolChars + 500,
            )."\n\n";
        }

        return $prompt;
    }

    /**
     * Build an agentic-mode tool result message with bounded payload size.
     * Public for testability.
     *
     * Framework B7 — when the tool is log-shaped (log_parse_errors and
     * siblings) and the result crosses the compaction threshold, pre-compact
     * with LogPreCompactor before the truncation step. This runs UPSTREAM
     * of whatever provider AIService routes to, so savings apply to every
     * LLM in the fallback chain (Ollama local, Claude CLI, Groq, OpenRouter,
     * Gemini, Cerebras, Mistral). Config-gated via agents.enable_log_precompaction
     * so it can be flipped off without a deploy if the compaction ever
     * damages signal quality.
     */
    public function buildToolResultMessage(
        string $toolName,
        string $toolResultText,
        string $verificationNote = '',
        string $diversityNudge = ''
    ): string {
        $maxPerToolChars = (int) config('agents.tool_result_max_chars', 2000);
        $boundedResult = $toolResultText;

        $precompactSuffix = '';
        if ($this->shouldPrecompactLogToolResult($toolName, $boundedResult)) {
            $compactor = app(\App\Services\PreCompaction\LogPreCompactor::class);
            $compacted = $compactor->compact($boundedResult);
            $bytesIn = $compacted['stats']['bytes_in'];
            $bytesOut = $compacted['stats']['bytes_out'];
            if ($bytesIn > 0 && $bytesOut < $bytesIn) {
                $boundedResult = $compacted['compacted'];
                $reductionPct = (int) round((1 - $bytesOut / $bytesIn) * 100);
                $precompactSuffix = "\n[precompact: {$reductionPct}% reduction, {$compacted['stats']['signatures']} unique signatures]";
            }
        }

        if (mb_strlen($boundedResult) > $maxPerToolChars) {
            $boundedResult = mb_substr($boundedResult, 0, $maxPerToolChars)
                ."\n... [truncated — {$toolName} returned ".number_format(mb_strlen($toolResultText))." chars, showing first {$maxPerToolChars}]";
        }

        return "Tool result for {$toolName}:\n".$this->formatAgentToolResult(
            $toolName,
            $boundedResult,
            "agent_tool:{$toolName}",
            $maxPerToolChars + 500,
        )."{$precompactSuffix}{$verificationNote}{$diversityNudge}";
    }

    private function formatAgentToolResult(string $toolName, string $payload, string $origin, int $maxChars): string
    {
        return $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'agent_tool_result',
            contentType: 'text/plain',
            origin: $origin,
            payload: $payload,
            maxChars: $maxChars,
        ));
    }

    /**
     * Gate: only compact tool outputs that are both (a) log-shaped by tool
     * name convention, and (b) large enough that deterministic reduction can
     * save meaningful tokens. Structured-metadata tools (log_cluster_signatures,
     * log_error_timeline, etc.) are left alone — they return JSON-ish data
     * that should not be re-signature-collapsed.
     */
    private function shouldPrecompactLogToolResult(string $toolName, string $result): bool
    {
        if (! (bool) config('agents.enable_log_precompaction', true)) {
            return false;
        }
        $threshold = (int) config('agents.log_precompaction_threshold_bytes', 1000);
        if (strlen($result) < $threshold) {
            return false;
        }

        // Only tools whose raw output is actual log text. Structured-result
        // log_* tools (clustering, timeline, correlation, baseline, snapshot)
        // are explicitly excluded so their JSON-ish payload stays intact.
        $logShapedTools = ['log_parse_errors', 'log_scan_errors', 'log_tail', 'log_read'];

        return in_array($toolName, $logShapedTools, true);
    }

    private function getAgentAiTimeout(array $skillConfig = [], array $options = []): int
    {
        $timeout = $options['ai_timeout']
            ?? $skillConfig['ai_timeout_seconds']
            ?? config('agents.ai_timeout_seconds', 45);

        return max(5, (int) $timeout);
    }

    public function stabilizeFinalResponse(string $agentId, string $finalResponse, array $messages, array $toolCalls): string
    {
        $response = match ($agentId) {
            'research-analyst' => $this->buildResearchAnalystOperationalSummary($messages, $toolCalls, $finalResponse),
            default => $finalResponse,
        };

        if ($this->shouldGuardCjkOperationalResponse($agentId, $response)) {
            return $this->buildCjkGuardedOperationalSummary($agentId, $toolCalls);
        }

        return $response;
    }

    private function shouldGuardCjkOperationalResponse(string $agentId, string $response): bool
    {
        return in_array($agentId, self::CJK_GUARDED_OPERATIONAL_AGENTS, true)
            && $this->containsCjkScript($response);
    }

    private function containsCjkScript(string $value): bool
    {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $value) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    private function buildCjkGuardedOperationalSummary(string $agentId, array $toolCalls): string
    {
        $failedTools = array_values(array_unique(array_filter(array_map(
            fn (array $call): ?string => ($call['success'] ?? false) ? null : (string) ($call['tool'] ?? 'unknown'),
            $toolCalls
        ))));

        $lines = [];
        $lines[] = '**Agent Output Guard**';
        $lines[] = '';
        $lines[] = '- Status: Response Suppressed';
        $lines[] = '- Agent: '.$agentId;
        $lines[] = '- Reason: final response contained CJK/non-English script markers.';
        $lines[] = '- Tool Calls: '.count($toolCalls).' total, '.count($failedTools).' failed';
        if ($failedTools !== []) {
            $lines[] = '- Failed Tools: '.implode(', ', $failedTools);
        }
        $lines[] = '- Action: review this agent session and tool outputs before trusting the report; repeated signals should prompt model or prompt routing review.';

        return implode("\n", $lines);
    }

    public function buildResearchAnalystOperationalSummary(array $messages, array $toolCalls, string $fallback): string
    {
        $results = $this->extractToolResultsFromMessages($messages);
        $coverage = $results['research_topic_coverage'] ?? null;
        $pending = $results['research_pending_results'] ?? null;
        $quality = $results['research_result_quality'] ?? null;
        $credibility = $results['research_source_credibility'] ?? null;

        if (! is_array($coverage) && ! is_array($pending) && ! is_array($quality) && ! is_array($credibility)) {
            return $fallback;
        }

        $failedTools = array_values(array_unique(array_map(
            fn ($call) => $call['tool'],
            array_filter($toolCalls, fn ($call) => ! ($call['success'] ?? false))
        )));

        $topicSummary = is_array($coverage['summary'] ?? null) ? $coverage['summary'] : [];
        $pendingSummary = is_array($pending['summary'] ?? null) ? $pending['summary'] : [];
        $qualityAllTime = is_array($quality['all_time'] ?? null) ? $quality['all_time'] : [];
        $qualityRecent = is_array($quality['last_7_days'] ?? null) ? $quality['last_7_days'] : [];
        $credSummary = is_array($credibility['summary'] ?? null) ? $credibility['summary'] : [];

        $coverageGaps = (int) ($topicSummary['topics_with_gaps'] ?? count($coverage['coverage_gaps'] ?? []));
        $lowQualityTopics = (int) ($topicSummary['topics_low_quality'] ?? count($coverage['low_quality_topics'] ?? []));
        $staleTopics = (int) ($topicSummary['topics_stale'] ?? count($coverage['stale_topics'] ?? []));
        $pendingTotal = (int) ($pendingSummary['total_pending'] ?? count($pending['pending_results'] ?? []));
        $pendingHighQuality = (int) ($pendingSummary['high_quality'] ?? 0);
        $withFindings = (int) ($pendingSummary['with_findings'] ?? 0);
        $approvalRate = (float) ($qualityAllTime['approval_rate_pct'] ?? 0);
        $recentApprovalRate = (float) ($qualityRecent['approval_rate_pct'] ?? 0);
        $trend = (string) ($quality['trend'] ?? 'unknown');
        $activeSources = (int) ($credSummary['active_sources'] ?? 0);
        $lowTrustSources = (int) ($credSummary['low_trust_active'] ?? 0);
        $highFailureSources = (int) ($credSummary['high_failure_sources'] ?? 0);

        $status = 'healthy';
        if (! empty($failedTools) || $pendingTotal > 20 || $coverageGaps >= 5 || $approvalRate < 40) {
            $status = 'degraded';
        } elseif ($pendingTotal > 0 || $coverageGaps > 0 || $lowQualityTopics > 0 || $lowTrustSources > 0) {
            $status = 'needs_review';
        }

        $action = match (true) {
            ! empty($failedTools) => 'investigate tool failures',
            $pendingTotal > 0 => 'review pending results',
            $coverageGaps > 0 => 'close coverage gaps',
            $lowTrustSources > 0 || $highFailureSources > 0 => 'review source quality',
            default => 'monitor',
        };

        $lines = [];
        $lines[] = '**Research Analyst Status**';
        $lines[] = '';
        $lines[] = '- Status: '.ucwords(str_replace('_', ' ', $status));
        $lines[] = '- Topics: '.(int) ($topicSummary['total_active_topics'] ?? 0).' active, '
            .$coverageGaps.' gaps, '.$lowQualityTopics.' low-quality, '.$staleTopics.' stale';
        $lines[] = '- Pending: '.$pendingTotal.' total, '.$pendingHighQuality.' high-quality, '.$withFindings.' with findings';
        $lines[] = '- Quality: '.$approvalRate.'% all-time approval, '.$recentApprovalRate.'% last 7 days, trend '.$trend;
        $lines[] = '- Sources: '.$activeSources.' active, '.$lowTrustSources.' low-trust, '.$highFailureSources.' high-failure';

        if (! empty($failedTools)) {
            $lines[] = '- Tool Failures: '.implode(', ', $failedTools);
        }

        $lines[] = '- Action: '.$action;

        $highlights = [];
        if ($pendingTotal === 0) {
            $highlights[] = 'No pending research results require approval right now.';
        }
        if ($coverageGaps > 0) {
            $highlights[] = $coverageGaps.' topic(s) still show coverage gaps.';
        }
        if ($lowQualityTopics > 0) {
            $highlights[] = $lowQualityTopics.' topic(s) are below the quality threshold.';
        }
        if (! empty($failedTools)) {
            $highlights[] = 'Some agent tools failed and need follow-up before trusting broader conclusions.';
        }

        if (! empty($highlights)) {
            $lines[] = '';
            $lines[] = '**Evidence-Bounded Findings**';
            foreach ($highlights as $highlight) {
                $lines[] = '- '.$highlight;
            }
        }

        return implode("\n", $lines);
    }

    private function extractToolResultsFromMessages(array $messages): array
    {
        $results = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = (string) ($message['content'] ?? '');
            if (! str_starts_with($content, 'Tool result for ')) {
                continue;
            }

            if (! preg_match('/^Tool result for ([^:]+):\n/s', $content, $matches)) {
                continue;
            }

            $toolName = trim($matches[1]);
            $payload = substr($content, strlen($matches[0]));
            $decoded = $this->extractJsonPayload($payload);
            if ($decoded !== null) {
                $results[$toolName] = $decoded;
            }
        }

        return $results;
    }

    private function extractJsonPayload(string $payload): ?array
    {
        $trimmed = trim($payload);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($trimmed);

        for ($i = 0; $i < $length; $i++) {
            $char = $trimmed[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($trimmed, 0, $i + 1);
                    $decoded = json_decode($candidate, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }
}

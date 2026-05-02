<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Agent Handoff Service
 *
 * Implements context transfer between specialized agents following the OpenAI Agents SDK
 * handoff pattern. Enables seamless delegation of tasks between agents while preserving
 * conversation history, state, and goals.
 *
 * Features:
 * - Context preservation across handoffs (conversation history, state, goals)
 * - Handoff protocol: source agent, target agent, context payload, reason
 * - Handoff routing based on task type or agent capabilities
 * - Comprehensive handoff logging for audit trail
 * - Integration with AIService for agent execution
 *
 * Usage:
 * ```php
 * $handoff = app(AgentHandoffService::class);
 *
 * // Register an agent with capabilities
 * $handoff->registerAgent('research_agent', [
 *     'capabilities' => ['web_search', 'fact_check', 'summarize'],
 *     'description' => 'Specialized in research tasks',
 * ]);
 *
 * // Initiate a handoff
 * $result = $handoff->handoff('general_agent', 'research_agent', [
 *     'conversation_history' => [...],
 *     'current_state' => [...],
 *     'goals' => [...],
 * ], 'Task requires research capabilities');
 * ```
 *
 * @see https://openai.github.io/openai-agents-python/handoffs/
 */
class AgentHandoffService
{
    private const CACHE_KEY_AGENTS = 'agent_handoff_agents';
    private const CACHE_KEY_ROUTING = 'agent_handoff_routing';
    private const CACHE_TTL = 300; // 5 minutes

    /** @var array Cached agent registry */
    private ?array $cachedAgents = null;

    /** @var array Cached routing rules */
    private ?array $cachedRoutingRules = null;

    /** @var AIService|null AI service for agent execution */
    private ?AIService $aiService;

    /** @var bool Whether to use database storage */
    private bool $useDatabaseStorage;

    public function __construct(?AIService $aiService = null)
    {
        $this->aiService = $aiService;
        $this->useDatabaseStorage = config('services.agent_handoffs.use_database', true);
    }

    /**
     * Initiate a handoff from one agent to another
     *
     * @param string $sourceAgentId Agent initiating the handoff
     * @param string $targetAgentId Agent receiving the handoff
     * @param array $contextPayload Context to transfer (conversation_history, state, goals, etc.)
     * @param string $reason Reason for the handoff
     * @param array $options Additional options (priority, timeout, etc.)
     * @return array Handoff result with handoff_id, status, and any errors
     */
    public function handoff(
        string $sourceAgentId,
        string $targetAgentId,
        array $contextPayload,
        string $reason,
        array $options = []
    ): array {
        $handoffId = $this->generateHandoffId();
        $startTime = microtime(true);

        try {
            // Validate source agent exists and is active
            $sourceAgent = $this->getAgent($sourceAgentId);
            if (!$sourceAgent) {
                return $this->failHandoff($handoffId, $sourceAgentId, $targetAgentId, $contextPayload, $reason, 'Source agent not found');
            }

            // Validate target agent exists and is active
            $targetAgent = $this->getAgent($targetAgentId);
            if (!$targetAgent) {
                return $this->failHandoff($handoffId, $sourceAgentId, $targetAgentId, $contextPayload, $reason, 'Target agent not found');
            }

            // Check if target agent can handle this handoff
            $canHandle = $this->validateHandoff($sourceAgent, $targetAgent, $contextPayload);
            if (!$canHandle['valid']) {
                return $this->failHandoff($handoffId, $sourceAgentId, $targetAgentId, $contextPayload, $reason, $canHandle['error']);
            }

            // Normalize and validate context payload
            $normalizedContext = $this->normalizeContextPayload($contextPayload);

            // Log handoff initiation
            $handoffData = [
                'handoff_id' => $handoffId,
                'source_agent_id' => $sourceAgentId,
                'target_agent_id' => $targetAgentId,
                'reason' => $reason,
                'context_summary' => $this->summarizeContext($normalizedContext),
                'priority' => $options['priority'] ?? 'normal',
                'status' => 'initiated',
            ];

            $this->logHandoff($handoffData, $normalizedContext);

            // Execute the handoff
            $result = $this->executeHandoff($handoffId, $targetAgent, $normalizedContext, $options);

            // Update handoff status
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->updateHandoffStatus($handoffId, $result['success'] ? 'completed' : 'failed', [
                'duration_ms' => $duration,
                'result_summary' => $result['summary'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

            return [
                'success' => $result['success'],
                'handoff_id' => $handoffId,
                'source_agent' => $sourceAgentId,
                'target_agent' => $targetAgentId,
                'status' => $result['success'] ? 'completed' : 'failed',
                'duration_ms' => $duration,
                'result' => $result['data'] ?? null,
                'error' => $result['error'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error("AgentHandoffService: Handoff failed", [
                'handoff_id' => $handoffId,
                'source_agent' => $sourceAgentId,
                'target_agent' => $targetAgentId,
                'error' => $e->getMessage(),
            ]);

            return $this->failHandoff($handoffId, $sourceAgentId, $targetAgentId, $contextPayload, $reason, $e->getMessage());
        }
    }

    /**
     * Route a task to the most appropriate agent based on capabilities
     *
     * @param string $taskType Type of task to route
     * @param array $taskContext Task context for routing decisions
     * @param string|null $currentAgentId Current agent (if routing from another agent)
     * @return array Routing result with recommended agent and confidence
     */
    public function routeTask(string $taskType, array $taskContext = [], ?string $currentAgentId = null): array
    {
        $routingRules = $this->getRoutingRules();
        $agents = $this->getAgents();

        $candidates = [];

        // Check explicit routing rules first
        foreach ($routingRules as $rule) {
            if ($this->ruleMatchesTask($rule, $taskType, $taskContext)) {
                $targetAgentId = $rule['target_agent_id'];
                if (isset($agents[$targetAgentId]) && $agents[$targetAgentId]['is_active']) {
                    $candidates[] = [
                        'agent_id' => $targetAgentId,
                        'confidence' => $rule['confidence'] ?? 0.9,
                        'reason' => $rule['reason'] ?? 'Matched routing rule',
                        'rule_id' => $rule['id'],
                    ];
                }
            }
        }

        // If no explicit rules, match by capability
        if (empty($candidates)) {
            foreach ($agents as $agentId => $agent) {
                if (!$agent['is_active'] || $agentId === $currentAgentId) {
                    continue;
                }

                $capabilityMatch = $this->matchCapabilities($agent, $taskType, $taskContext);
                if ($capabilityMatch['matches']) {
                    $candidates[] = [
                        'agent_id' => $agentId,
                        'confidence' => $capabilityMatch['confidence'],
                        'reason' => $capabilityMatch['reason'],
                        'matched_capabilities' => $capabilityMatch['capabilities'],
                    ];
                }
            }
        }

        // Sort by confidence
        usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        if (empty($candidates)) {
            return [
                'found' => false,
                'agent_id' => null,
                'reason' => 'No suitable agent found for task type: ' . $taskType,
            ];
        }

        $bestMatch = $candidates[0];

        return [
            'found' => true,
            'agent_id' => $bestMatch['agent_id'],
            'confidence' => $bestMatch['confidence'],
            'reason' => $bestMatch['reason'],
            'alternatives' => array_slice($candidates, 1, 3),
        ];
    }

    /**
     * Register a new agent
     *
     * @param string $agentId Unique agent identifier
     * @param array $config Agent configuration (capabilities, description, etc.)
     * @return bool Success
     */
    public function registerAgent(string $agentId, array $config): bool
    {
        $agentData = [
            'agent_id' => $agentId,
            'name' => $config['name'] ?? $agentId,
            'description' => $config['description'] ?? null,
            'capabilities' => $config['capabilities'] ?? [],
            'max_concurrent_handoffs' => $config['max_concurrent_handoffs'] ?? 5,
            'timeout_seconds' => $config['timeout_seconds'] ?? 300,
            'is_active' => $config['is_active'] ?? true,
            'metadata' => $config['metadata'] ?? [],
        ];

        if ($this->useDatabaseStorage && $this->tableExists('agent_handoff_agents')) {
            $existing = DB::selectOne("SELECT id FROM agent_handoff_agents WHERE agent_id = ?", [$agentId]);

            if ($existing) {
                DB::update("
                    UPDATE agent_handoff_agents
                    SET name = ?, description = ?, capabilities = ?, max_concurrent_handoffs = ?,
                        timeout_seconds = ?, is_active = ?, metadata = ?, updated_at = NOW()
                    WHERE agent_id = ?
                ", [
                    $agentData['name'],
                    $agentData['description'],
                    json_encode($agentData['capabilities']),
                    $agentData['max_concurrent_handoffs'],
                    $agentData['timeout_seconds'],
                    $agentData['is_active'] ? 1 : 0,
                    json_encode($agentData['metadata']),
                    $agentId,
                ]);
            } else {
                DB::insert("
                    INSERT INTO agent_handoff_agents
                    (agent_id, name, description, capabilities, max_concurrent_handoffs, timeout_seconds, is_active, metadata, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $agentData['agent_id'],
                    $agentData['name'],
                    $agentData['description'],
                    json_encode($agentData['capabilities']),
                    $agentData['max_concurrent_handoffs'],
                    $agentData['timeout_seconds'],
                    $agentData['is_active'] ? 1 : 0,
                    json_encode($agentData['metadata']),
                ]);
            }
        }

        $this->clearCache();

        Log::info("AgentHandoffService: Agent registered", [
            'agent_id' => $agentId,
            'capabilities' => $agentData['capabilities'],
        ]);

        return true;
    }

    /**
     * Unregister an agent
     */
    public function unregisterAgent(string $agentId): bool
    {
        if ($this->useDatabaseStorage && $this->tableExists('agent_handoff_agents')) {
            DB::delete("DELETE FROM agent_handoff_agents WHERE agent_id = ?", [$agentId]);
        }

        $this->clearCache();

        Log::info("AgentHandoffService: Agent unregistered", ['agent_id' => $agentId]);

        return true;
    }

    /**
     * Get agent by ID
     */
    public function getAgent(string $agentId): ?array
    {
        $agents = $this->getAgents();
        return $agents[$agentId] ?? null;
    }

    /**
     * Get all registered agents
     */
    public function getAgents(): array
    {
        if ($this->cachedAgents !== null) {
            return $this->cachedAgents;
        }

        $this->cachedAgents = Cache::remember(self::CACHE_KEY_AGENTS, self::CACHE_TTL, function () {
            $agents = [];

            // Load from database
            if ($this->useDatabaseStorage && $this->tableExists('agent_handoff_agents')) {
                $dbAgents = DB::select("
                    SELECT agent_id, name, description, capabilities, max_concurrent_handoffs,
                           timeout_seconds, is_active, metadata
                    FROM agent_handoff_agents
                ");

                foreach ($dbAgents as $agent) {
                    $agents[$agent->agent_id] = [
                        'agent_id' => $agent->agent_id,
                        'name' => $agent->name,
                        'description' => $agent->description,
                        'capabilities' => json_decode($agent->capabilities, true) ?? [],
                        'max_concurrent_handoffs' => $agent->max_concurrent_handoffs,
                        'timeout_seconds' => $agent->timeout_seconds,
                        'is_active' => (bool) $agent->is_active,
                        'metadata' => json_decode($agent->metadata, true) ?? [],
                    ];
                }
            }

            // Merge with config-defined agents
            $configAgents = config('services.agent_handoffs.agents', []);
            foreach ($configAgents as $agentId => $config) {
                if (!isset($agents[$agentId])) {
                    $agents[$agentId] = array_merge([
                        'agent_id' => $agentId,
                        'name' => $agentId,
                        'description' => null,
                        'capabilities' => [],
                        'max_concurrent_handoffs' => 5,
                        'timeout_seconds' => 300,
                        'is_active' => true,
                        'metadata' => [],
                    ], $config);
                }
            }

            return $agents;
        });

        return $this->cachedAgents;
    }

    /**
     * Add a routing rule
     *
     * @param array $rule Routing rule configuration
     * @return int|string Rule ID
     */
    public function addRoutingRule(array $rule): int|string
    {
        if (!$this->useDatabaseStorage) {
            throw new Exception("Database storage is disabled. Add routing rules to config instead.");
        }

        DB::insert(
            "INSERT INTO agent_handoff_routing_rules (name, task_pattern, target_agent_id, conditions, confidence, reason, priority, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $rule['name'] ?? 'Unnamed Rule',
                $rule['task_pattern'],
                $rule['target_agent_id'],
                isset($rule['conditions']) ? json_encode($rule['conditions']) : null,
                $rule['confidence'] ?? 0.9,
                $rule['reason'] ?? null,
                $rule['priority'] ?? 0,
                $rule['is_active'] ?? true,
            ]
        );
        $ruleId = (int) DB::getPdo()->lastInsertId();

        $this->clearCache();

        return $ruleId;
    }

    /**
     * Get routing rules
     */
    public function getRoutingRules(): array
    {
        if ($this->cachedRoutingRules !== null) {
            return $this->cachedRoutingRules;
        }

        $this->cachedRoutingRules = Cache::remember(self::CACHE_KEY_ROUTING, self::CACHE_TTL, function () {
            $rules = [];

            if ($this->useDatabaseStorage && $this->tableExists('agent_handoff_routing_rules')) {
                $dbRules = DB::select("
                    SELECT id, name, task_pattern, target_agent_id, conditions, confidence, reason, priority
                    FROM agent_handoff_routing_rules
                    WHERE is_active = 1
                    ORDER BY priority DESC, id ASC
                ");

                foreach ($dbRules as $rule) {
                    $rules[] = [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'task_pattern' => $rule->task_pattern,
                        'target_agent_id' => $rule->target_agent_id,
                        'conditions' => $rule->conditions ? json_decode($rule->conditions, true) : [],
                        'confidence' => (float) $rule->confidence,
                        'reason' => $rule->reason,
                        'priority' => $rule->priority,
                    ];
                }
            }

            // Merge with config rules
            $configRules = config('services.agent_handoffs.routing_rules', []);
            foreach ($configRules as $index => $rule) {
                $rules[] = array_merge([
                    'id' => 'config_' . $index,
                    'priority' => $rule['priority'] ?? 0,
                    'confidence' => 0.9,
                ], $rule);
            }

            usort($rules, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

            return $rules;
        });

        return $this->cachedRoutingRules;
    }

    /**
     * Get handoff history
     *
     * @param int $limit Maximum records to return
     * @param string|null $agentId Filter by source or target agent
     * @param string|null $status Filter by status
     * @return array Handoff records
     */
    public function getHandoffHistory(int $limit = 100, ?string $agentId = null, ?string $status = null): array
    {
        if (!$this->tableExists('agent_handoffs')) {
            return [];
        }

        $query = "SELECT * FROM agent_handoffs WHERE 1=1";
        $params = [];

        if ($agentId) {
            $query .= " AND (source_agent_id = ? OR target_agent_id = ?)";
            $params[] = $agentId;
            $params[] = $agentId;
        }

        if ($status) {
            $query .= " AND status = ?";
            $params[] = $status;
        }

        $query .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        try {
            return array_map(function ($record) {
                return [
                    'handoff_id' => $record->handoff_id,
                    'source_agent_id' => $record->source_agent_id,
                    'target_agent_id' => $record->target_agent_id,
                    'reason' => $record->reason,
                    'status' => $record->status,
                    'context_summary' => $record->context_summary,
                    'duration_ms' => $record->duration_ms,
                    'error' => $record->error,
                    'created_at' => $record->created_at,
                    'completed_at' => $record->completed_at,
                ];
            }, DB::select($query, $params));
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get handoff statistics
     */
    public function getStats(int $hours = 24): array
    {
        if (!$this->tableExists('agent_handoffs')) {
            return [
                'total_handoffs' => 0,
                'completed' => 0,
                'failed' => 0,
                'agents_count' => count($this->getAgents()),
                'routing_rules_count' => count($this->getRoutingRules()),
            ];
        }

        try {
            $stats = [
                'total_handoffs' => 0,
                'completed' => 0,
                'failed' => 0,
                'initiated' => 0,
                'avg_duration_ms' => 0,
                'by_source_agent' => [],
                'by_target_agent' => [],
                'agents_count' => count($this->getAgents()),
                'routing_rules_count' => count($this->getRoutingRules()),
            ];

            // Status counts
            $statusCounts = DB::select("
                SELECT status, COUNT(*) as count, AVG(duration_ms) as avg_duration
                FROM agent_handoffs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY status
            ", [$hours]);

            $totalDuration = 0;
            $countWithDuration = 0;

            foreach ($statusCounts as $row) {
                $stats[$row->status] = $row->count;
                $stats['total_handoffs'] += $row->count;
                if ($row->avg_duration) {
                    $totalDuration += $row->avg_duration * $row->count;
                    $countWithDuration += $row->count;
                }
            }

            $stats['avg_duration_ms'] = $countWithDuration > 0 ? round($totalDuration / $countWithDuration) : 0;

            // By source agent
            $sourceStats = DB::select("
                SELECT source_agent_id, COUNT(*) as count
                FROM agent_handoffs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY source_agent_id
            ", [$hours]);

            foreach ($sourceStats as $row) {
                $stats['by_source_agent'][$row->source_agent_id] = $row->count;
            }

            // By target agent
            $targetStats = DB::select("
                SELECT target_agent_id, COUNT(*) as count, AVG(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as success_rate
                FROM agent_handoffs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY target_agent_id
            ", [$hours]);

            foreach ($targetStats as $row) {
                $stats['by_target_agent'][$row->target_agent_id] = [
                    'count' => $row->count,
                    'success_rate' => round($row->success_rate * 100, 1),
                ];
            }

            return $stats;

        } catch (Exception $e) {
            return [
                'error' => 'Statistics unavailable: ' . $e->getMessage(),
                'agents_count' => count($this->getAgents()),
                'routing_rules_count' => count($this->getRoutingRules()),
            ];
        }
    }

    /**
     * Get a specific handoff by ID
     */
    public function getHandoff(string $handoffId): ?array
    {
        if (!$this->tableExists('agent_handoffs')) {
            return null;
        }

        $record = DB::selectOne("
            SELECT h.*, c.context_payload
            FROM agent_handoffs h
            LEFT JOIN agent_handoff_contexts c ON c.handoff_id = h.handoff_id
            WHERE h.handoff_id = ?
        ", [$handoffId]);

        if (!$record) {
            return null;
        }

        return [
            'handoff_id' => $record->handoff_id,
            'source_agent_id' => $record->source_agent_id,
            'target_agent_id' => $record->target_agent_id,
            'reason' => $record->reason,
            'status' => $record->status,
            'context_summary' => $record->context_summary,
            'context_payload' => $record->context_payload ? json_decode($record->context_payload, true) : null,
            'duration_ms' => $record->duration_ms,
            'error' => $record->error,
            'created_at' => $record->created_at,
            'completed_at' => $record->completed_at,
        ];
    }

    /**
     * Clear cached data
     */
    public function clearCache(): void
    {
        $this->cachedAgents = null;
        $this->cachedRoutingRules = null;
        Cache::forget(self::CACHE_KEY_AGENTS);
        Cache::forget(self::CACHE_KEY_ROUTING);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Generate unique handoff ID
     */
    private function generateHandoffId(): string
    {
        return 'hnd_' . bin2hex(random_bytes(12));
    }

    /**
     * Validate handoff between agents
     */
    private function validateHandoff(array $sourceAgent, array $targetAgent, array $context): array
    {
        // Check if target agent is active
        if (!$targetAgent['is_active']) {
            return ['valid' => false, 'error' => 'Target agent is not active'];
        }

        // Check concurrent handoff limit
        if ($this->tableExists('agent_handoffs')) {
            $activeHandoffs = DB::selectOne("
                SELECT COUNT(*) as count
                FROM agent_handoffs
                WHERE target_agent_id = ? AND status = 'initiated'
            ", [$targetAgent['agent_id']]);

            if ($activeHandoffs && $activeHandoffs->count >= $targetAgent['max_concurrent_handoffs']) {
                return ['valid' => false, 'error' => 'Target agent has reached maximum concurrent handoffs'];
            }
        }

        return ['valid' => true];
    }

    /**
     * Normalize context payload
     */
    private function normalizeContextPayload(array $context): array
    {
        return [
            'conversation_history' => $context['conversation_history'] ?? [],
            'current_state' => $context['current_state'] ?? [],
            'goals' => $context['goals'] ?? [],
            'metadata' => $context['metadata'] ?? [],
            'original_request' => $context['original_request'] ?? null,
            'intermediate_results' => $context['intermediate_results'] ?? [],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Summarize context for logging
     */
    private function summarizeContext(array $context): string
    {
        $parts = [];

        if (!empty($context['conversation_history'])) {
            $parts[] = count($context['conversation_history']) . ' messages';
        }

        if (!empty($context['goals'])) {
            $goalCount = is_array($context['goals']) ? count($context['goals']) : 1;
            $parts[] = $goalCount . ' goal(s)';
        }

        if (!empty($context['current_state'])) {
            $parts[] = 'state preserved';
        }

        return implode(', ', $parts) ?: 'minimal context';
    }

    /**
     * Log handoff to database
     */
    private function logHandoff(array $handoffData, array $contextPayload): void
    {
        Log::info("AgentHandoffService: Handoff initiated", [
            'handoff_id' => $handoffData['handoff_id'],
            'source_agent' => $handoffData['source_agent_id'],
            'target_agent' => $handoffData['target_agent_id'],
            'reason' => $handoffData['reason'],
        ]);

        if ($this->useDatabaseStorage && $this->tableExists('agent_handoffs')) {
            DB::insert("
                INSERT INTO agent_handoffs
                (handoff_id, source_agent_id, target_agent_id, reason, context_summary, status, priority, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $handoffData['handoff_id'],
                $handoffData['source_agent_id'],
                $handoffData['target_agent_id'],
                $handoffData['reason'],
                $handoffData['context_summary'],
                $handoffData['status'],
                $handoffData['priority'],
            ]);

            // Store full context separately (may be large)
            if ($this->tableExists('agent_handoff_contexts')) {
                DB::insert("
                    INSERT INTO agent_handoff_contexts (handoff_id, context_payload, created_at)
                    VALUES (?, ?, NOW())
                ", [
                    $handoffData['handoff_id'],
                    json_encode($contextPayload),
                ]);
            }
        }
    }

    /**
     * Update handoff status
     */
    private function updateHandoffStatus(string $handoffId, string $status, array $data = []): void
    {
        if (!$this->useDatabaseStorage || !$this->tableExists('agent_handoffs')) {
            return;
        }

        $fields = ['status = ?', 'updated_at = NOW()'];
        $params = [$status];

        if ($status === 'completed' || $status === 'failed') {
            $fields[] = 'completed_at = NOW()';
        }

        if (isset($data['duration_ms'])) {
            $fields[] = 'duration_ms = ?';
            $params[] = $data['duration_ms'];
        }

        if (isset($data['error'])) {
            $fields[] = 'error = ?';
            $params[] = $data['error'];
        }

        if (isset($data['result_summary'])) {
            $fields[] = 'result_summary = ?';
            $params[] = $data['result_summary'];
        }

        $params[] = $handoffId;

        DB::update(
            "UPDATE agent_handoffs SET " . implode(', ', $fields) . " WHERE handoff_id = ?",
            $params
        );
    }

    /**
     * Execute the handoff to target agent
     *
     * Dispatches the target agent via one of three execution paths:
     * 1. AgentLoopService (skill-based agents) - dispatched as ProcessAgentTask queue job
     * 2. Artisan command handler - for agents registered with 'handler' = 'command:...'
     * 3. Direct synchronous execution - for simple agents with AIService
     */
    private function executeHandoff(string $handoffId, array $targetAgent, array $context, array $options): array
    {
        $agentId = $targetAgent['agent_id'];
        $handler = $targetAgent['metadata']['handler'] ?? null;
        $async = $options['async'] ?? true;

        try {
            // Build task description from context
            $taskDescription = $this->buildTaskFromContext($context);

            // Path 1: Skill-based agent via AgentLoopService (default for agents with skills)
            $skillLoader = app(SkillLoaderService::class);
            if ($skillLoader->skillExists($agentId) || !$handler) {
                $payload = [
                    'task' => $taskDescription,
                    'skill' => $agentId,
                    'context' => $context,
                    'handoff_id' => $handoffId,
                    'notify' => $options['notify'] ?? false,
                    'tree_id' => $context['metadata']['tree_id'] ?? null,
                    'depth' => ($options['depth'] ?? 0) + 1,
                ];

                if ($async) {
                    // Dispatch to queue via DistributedAgentService
                    $distributedService = app(DistributedAgentService::class);
                    $taskResult = $distributedService->submitTask($agentId, $payload, [
                        'priority' => $options['priority'] === 'high' ? 10 : 0,
                        'timeout' => $targetAgent['timeout_seconds'] ?? 300,
                    ]);

                    return [
                        'success' => true,
                        'data' => [
                            'handoff_id' => $handoffId,
                            'agent_id' => $agentId,
                            'task_id' => $taskResult['task_id'],
                            'execution_mode' => 'async_queue',
                        ],
                        'summary' => "Task dispatched to {$targetAgent['name']} (async)",
                    ];
                } else {
                    // Synchronous execution
                    $agentLoop = app(AgentLoopService::class);
                    $result = $agentLoop->execute($agentId, $taskDescription, [
                        'context' => $context,
                        'notify' => $options['notify'] ?? false,
                        'tree_id' => $context['metadata']['tree_id'] ?? null,
                        'depth' => ($options['depth'] ?? 0) + 1,
                    ]);

                    return [
                        'success' => $result['success'],
                        'data' => $result,
                        'summary' => $result['success']
                            ? "Task completed by {$targetAgent['name']}"
                            : "Task failed: " . ($result['error'] ?? 'Unknown error'),
                        'error' => $result['error'] ?? null,
                    ];
                }
            }

            // Path 2: Artisan command handler
            if (str_starts_with($handler, 'command:')) {
                $commandName = substr($handler, 7);
                $exitCode = \Illuminate\Support\Facades\Artisan::call($commandName, [
                    '--context' => json_encode($context),
                ]);

                $output = \Illuminate\Support\Facades\Artisan::output();

                return [
                    'success' => $exitCode === 0,
                    'data' => [
                        'handoff_id' => $handoffId,
                        'agent_id' => $agentId,
                        'exit_code' => $exitCode,
                        'output' => $output,
                    ],
                    'summary' => $exitCode === 0
                        ? "Command executed by {$targetAgent['name']}"
                        : "Command failed with exit code {$exitCode}",
                    'error' => $exitCode !== 0 ? "Exit code: {$exitCode}" : null,
                ];
            }

            // Path 3: Direct AIService execution (legacy handler)
            if ($this->aiService) {
                $result = $this->aiService->process($taskDescription, [
                    'system' => "You are {$targetAgent['name']}. {$targetAgent['description']}",
                ]);

                return [
                    'success' => true,
                    'data' => [
                        'handoff_id' => $handoffId,
                        'agent_id' => $agentId,
                        'response' => $result['content'] ?? $result['response'] ?? '',
                    ],
                    'summary' => "Direct execution by {$targetAgent['name']}",
                ];
            }

            return [
                'success' => false,
                'error' => "No execution handler available for agent {$agentId}",
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Agent execution failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build a task description from handoff context
     */
    private function buildTaskFromContext(array $context): string
    {
        $parts = [];

        if (!empty($context['original_request'])) {
            $parts[] = "Original request: " . $context['original_request'];
        }

        if (!empty($context['goals'])) {
            $goals = is_array($context['goals']) ? implode('; ', $context['goals']) : $context['goals'];
            $parts[] = "Goals: " . $goals;
        }

        if (!empty($context['intermediate_results'])) {
            $parts[] = "Previous results: " . json_encode($context['intermediate_results']);
        }

        return implode("\n\n", $parts) ?: 'Process the provided context and return results.';
    }

    /**
     * Create a failed handoff result
     */
    private function failHandoff(
        string $handoffId,
        string $sourceAgentId,
        string $targetAgentId,
        array $context,
        string $reason,
        string $error
    ): array {
        Log::warning("AgentHandoffService: Handoff failed", [
            'handoff_id' => $handoffId,
            'source_agent' => $sourceAgentId,
            'target_agent' => $targetAgentId,
            'error' => $error,
        ]);

        if ($this->useDatabaseStorage && $this->tableExists('agent_handoffs')) {
            DB::insert("
                INSERT INTO agent_handoffs
                (handoff_id, source_agent_id, target_agent_id, reason, status, error, created_at, completed_at)
                VALUES (?, ?, ?, ?, 'failed', ?, NOW(), NOW())
            ", [
                $handoffId,
                $sourceAgentId,
                $targetAgentId,
                $reason,
                $error,
            ]);
        }

        return [
            'success' => false,
            'handoff_id' => $handoffId,
            'source_agent' => $sourceAgentId,
            'target_agent' => $targetAgentId,
            'status' => 'failed',
            'error' => $error,
        ];
    }

    /**
     * Check if routing rule matches task
     */
    private function ruleMatchesTask(array $rule, string $taskType, array $context): bool
    {
        $pattern = $rule['task_pattern'];

        // Support wildcard patterns
        if (str_contains($pattern, '*')) {
            // Replace * with placeholder, quote, then restore as regex .*
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (!preg_match($regex, $taskType)) {
                return false;
            }
        } elseif ($pattern !== $taskType) {
            return false;
        }

        // Check conditions if present
        $conditions = $rule['conditions'] ?? [];
        if (!empty($conditions)) {
            foreach ($conditions as $key => $expectedValue) {
                $actualValue = $context[$key] ?? null;
                if ($actualValue !== $expectedValue) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Match agent capabilities to task
     */
    private function matchCapabilities(array $agent, string $taskType, array $context): array
    {
        $capabilities = $agent['capabilities'] ?? [];

        if (empty($capabilities)) {
            return ['matches' => false, 'confidence' => 0, 'reason' => 'No capabilities defined'];
        }

        // Direct capability match
        if (in_array($taskType, $capabilities, true)) {
            return [
                'matches' => true,
                'confidence' => 0.95,
                'reason' => "Direct capability match: {$taskType}",
                'capabilities' => [$taskType],
            ];
        }

        // Partial match (task type contains capability keyword)
        $matchedCapabilities = [];
        foreach ($capabilities as $capability) {
            if (str_contains(strtolower($taskType), strtolower($capability))) {
                $matchedCapabilities[] = $capability;
            }
        }

        if (!empty($matchedCapabilities)) {
            $confidence = min(0.8, 0.5 + (count($matchedCapabilities) * 0.15));
            return [
                'matches' => true,
                'confidence' => $confidence,
                'reason' => 'Partial capability match',
                'capabilities' => $matchedCapabilities,
            ];
        }

        return ['matches' => false, 'confidence' => 0, 'reason' => 'No capability match'];
    }

    /**
     * Check if a table exists
     */
    private function tableExists(string $tableName): bool
    {
        try {
            // SHOW TABLES LIKE doesn't support parameter binding
            // Use Schema facade or information_schema instead
            $escaped = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
            $result = DB::selectOne("SHOW TABLES LIKE '{$escaped}'");
            return $result !== null;
        } catch (Exception $e) {
            return false;
        }
    }
}

<?php

namespace App\Services;

use App\Contracts\ReviewApprovalHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tool Composition Service (S18: Dynamic Tool Composition)
 *
 * Discovers recurring tool sequences from agent execution history, proposes them
 * as composite (meta) tools, and executes approved compositions deterministically
 * with piped outputs. Decomposition fallback on failure.
 *
 * Flow:
 *  1. DISCOVER: Mine agent_procedures for recurring tool sequences (3+ occurrences)
 *  2. PROPOSE: Submit discovered compositions for human review
 *  3. EXECUTE: Run approved composite tools as deterministic sequences
 *  4. FALLBACK: Decompose and retry individual tools on composite failure
 *
 * Industry Pattern: AWO (Agent Workflow Optimization) meta-tool extraction.
 * Reduces LLM reasoning steps and improves reliability for proven sequences.
 */
class ToolCompositionService implements ReviewApprovalHandler
{
    // Minimum times a sequence must appear to be composition-worthy
    private const MIN_OCCURRENCES = 3;

    // Minimum success rate for source procedures
    private const MIN_SUCCESS_RATE = 0.80;

    // Minimum tools in a composition (2+ tools makes it a pipeline)
    private const MIN_TOOLS = 2;

    // Maximum tools in a composition (keep it manageable)
    private const MAX_TOOLS = 8;

    // Jaccard similarity threshold for sequence matching
    private const SEQUENCE_MATCH_THRESHOLD = 0.85;

    private ?AgentToolRegistryService $toolRegistry = null;

    private function getToolRegistry(): AgentToolRegistryService
    {
        if ($this->toolRegistry === null) {
            $this->toolRegistry = app(AgentToolRegistryService::class);
        }
        return $this->toolRegistry;
    }

    // =========================================================================
    // PHASE 1: Discover Recurring Tool Sequences
    // =========================================================================

    /**
     * Mine agent_procedures for recurring tool sequences across agents.
     *
     * Groups procedures by ordered tool sequence. Sequences appearing in 3+
     * procedures across any agents become composition candidates.
     *
     * @param string|null $agentId Filter to specific agent (null = all agents)
     * @return array Discovered composition candidates
     */
    public function discoverCompositions(?string $agentId = null): array
    {
        $where = 'is_retired = 0 AND procedure_type = ? AND success_rate >= ?';
        $bindings = ['success', self::MIN_SUCCESS_RATE];

        if ($agentId) {
            $where .= ' AND agent_id = ?';
            $bindings[] = $agentId;
        }

        $procedures = DB::select("
            SELECT id, agent_id, name, trigger_pattern, action_sequence,
                   success_rate, times_used, times_succeeded
            FROM agent_procedures
            WHERE {$where}
            ORDER BY success_rate DESC, times_used DESC
        ", $bindings);

        if (empty($procedures)) {
            return ['success' => true, 'candidates' => [], 'message' => 'No procedures to analyze'];
        }

        // Group by ordered tool sequence (tool names in order)
        $sequences = [];
        foreach ($procedures as $proc) {
            $actions = json_decode($proc->action_sequence, true) ?: [];
            $tools = array_column($actions, 'tool');

            if (count($tools) < self::MIN_TOOLS || count($tools) > self::MAX_TOOLS) {
                continue;
            }

            $seqKey = implode('→', $tools);

            if (!isset($sequences[$seqKey])) {
                $sequences[$seqKey] = [
                    'tools' => $tools,
                    'procedures' => [],
                    'agents' => [],
                    'total_uses' => 0,
                    'avg_success_rate' => 0,
                ];
            }

            $sequences[$seqKey]['procedures'][] = [
                'id' => $proc->id,
                'agent_id' => $proc->agent_id,
                'name' => $proc->name,
                'success_rate' => (float) $proc->success_rate,
                'times_used' => (int) $proc->times_used,
            ];
            $sequences[$seqKey]['agents'][] = $proc->agent_id;
            $sequences[$seqKey]['total_uses'] += (int) $proc->times_used;
        }

        // Also find near-matches using Jaccard similarity on ordered sequences
        $mergedSequences = $this->mergeNearMatches($sequences);

        // Filter to candidates meeting minimum occurrence threshold
        $candidates = [];
        foreach ($mergedSequences as $key => $seq) {
            $procCount = count($seq['procedures']);
            if ($procCount < self::MIN_OCCURRENCES) {
                continue;
            }

            // Calculate weighted average success rate
            $totalWeightedRate = 0;
            $totalWeight = 0;
            foreach ($seq['procedures'] as $p) {
                $weight = max(1, $p['times_used']);
                $totalWeightedRate += $p['success_rate'] * $weight;
                $totalWeight += $weight;
            }
            $avgRate = $totalWeight > 0 ? $totalWeightedRate / $totalWeight : 0;

            // Check if already registered as a composite tool
            $composedName = $this->generateComposedName($seq['tools']);
            $existing = DB::selectOne(
                "SELECT id, enabled FROM agent_tool_registry WHERE name = ?",
                [$composedName]
            );

            $candidates[] = [
                'sequence_key' => $key,
                'tools' => $seq['tools'],
                'composed_name' => $composedName,
                'procedure_count' => $procCount,
                'unique_agents' => count(array_unique($seq['agents'])),
                'agents' => array_values(array_unique($seq['agents'])),
                'total_uses' => $seq['total_uses'],
                'avg_success_rate' => round($avgRate, 4),
                'already_registered' => $existing !== null,
                'already_enabled' => $existing && $existing->enabled,
                'procedures' => $seq['procedures'],
            ];
        }

        // Sort by score: procedure_count × avg_success_rate × unique_agents
        usort($candidates, function ($a, $b) {
            $scoreA = $a['procedure_count'] * $a['avg_success_rate'] * $a['unique_agents'];
            $scoreB = $b['procedure_count'] * $b['avg_success_rate'] * $b['unique_agents'];
            return $scoreB <=> $scoreA;
        });

        return [
            'success' => true,
            'candidates' => $candidates,
            'total_procedures_analyzed' => count($procedures),
            'total_unique_sequences' => count($mergedSequences),
            'composition_candidates' => count($candidates),
        ];
    }

    /**
     * Merge near-match sequences using Jaccard similarity on ordered tool lists.
     * Keeps the most common variant as the canonical sequence.
     */
    private function mergeNearMatches(array $sequences): array
    {
        $keys = array_keys($sequences);
        $merged = [];
        $consumed = [];

        for ($i = 0; $i < count($keys); $i++) {
            if (in_array($keys[$i], $consumed)) {
                continue;
            }

            $canonical = $keys[$i];
            $merged[$canonical] = $sequences[$canonical];

            for ($j = $i + 1; $j < count($keys); $j++) {
                if (in_array($keys[$j], $consumed)) {
                    continue;
                }

                $sim = $this->orderedJaccardSimilarity(
                    $sequences[$canonical]['tools'],
                    $sequences[$keys[$j]]['tools']
                );

                if ($sim >= self::SEQUENCE_MATCH_THRESHOLD) {
                    // Merge into canonical
                    $merged[$canonical]['procedures'] = array_merge(
                        $merged[$canonical]['procedures'],
                        $sequences[$keys[$j]]['procedures']
                    );
                    $merged[$canonical]['agents'] = array_merge(
                        $merged[$canonical]['agents'],
                        $sequences[$keys[$j]]['agents']
                    );
                    $merged[$canonical]['total_uses'] += $sequences[$keys[$j]]['total_uses'];
                    $consumed[] = $keys[$j];
                }
            }
        }

        return $merged;
    }

    /**
     * Ordered Jaccard similarity — accounts for sequence order.
     * Considers both set overlap and positional alignment.
     */
    private function orderedJaccardSimilarity(array $a, array $b): float
    {
        if (empty($a) && empty($b)) return 1.0;
        if (empty($a) || empty($b)) return 0.0;

        // Set-based Jaccard
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        $setJaccard = $union > 0 ? $intersection / $union : 0.0;

        // Order penalty: count positional mismatches
        $minLen = min(count($a), count($b));
        $matches = 0;
        for ($i = 0; $i < $minLen; $i++) {
            if ($a[$i] === $b[$i]) {
                $matches++;
            }
        }
        $orderScore = $minLen > 0 ? $matches / $minLen : 0.0;

        // Combined: 60% set overlap + 40% order alignment
        return $setJaccard * 0.6 + $orderScore * 0.4;
    }

    // =========================================================================
    // PHASE 2: Register Composite Tools
    // =========================================================================

    /**
     * Register a discovered composition as a composite tool.
     * Creates a disabled tool entry + review queue entry for human approval.
     *
     * @param array $tools Ordered list of tool names
     * @param string $description Human-readable description
     * @param string|null $agentId Agent proposing (for review queue)
     * @return array
     */
    public function proposeComposition(array $tools, string $description = '', string $agentId = 'system'): array
    {
        if (count($tools) < self::MIN_TOOLS) {
            return ['success' => false, 'error' => 'Composition requires at least ' . self::MIN_TOOLS . ' tools'];
        }

        if (count($tools) > self::MAX_TOOLS) {
            return ['success' => false, 'error' => 'Composition exceeds maximum of ' . self::MAX_TOOLS . ' tools'];
        }

        // Validate all component tools exist and are enabled
        $invalidTools = [];
        foreach ($tools as $tool) {
            $exists = DB::selectOne(
                "SELECT name, enabled FROM agent_tool_registry WHERE name = ?",
                [$tool]
            );
            if (!$exists) {
                $invalidTools[] = "{$tool} (not found)";
            } elseif (!$exists->enabled) {
                $invalidTools[] = "{$tool} (disabled)";
            }
        }

        if (!empty($invalidTools)) {
            return ['success' => false, 'error' => 'Invalid component tools: ' . implode(', ', $invalidTools)];
        }

        $composedName = $this->generateComposedName($tools);

        // Check if already exists
        $existing = DB::selectOne("SELECT id, enabled FROM agent_tool_registry WHERE name = ?", [$composedName]);
        if ($existing) {
            if ($existing->enabled) {
                return ['success' => false, 'error' => "Composite tool '{$composedName}' already exists and is active"];
            }
            return ['success' => false, 'error' => "Composite tool '{$composedName}' already proposed and awaiting review"];
        }

        // Auto-generate description if not provided
        if (empty($description)) {
            $toolDescriptions = [];
            foreach ($tools as $tool) {
                $def = DB::selectOne("SELECT description FROM agent_tool_registry WHERE name = ?", [$tool]);
                if ($def) {
                    $toolDescriptions[] = $tool . ': ' . substr($def->description, 0, 60);
                }
            }
            $description = "Composite tool executing: " . implode(' → ', $tools)
                . ". Pipeline: " . implode('; ', $toolDescriptions);
        }

        // Build composition metadata
        $compositionMeta = [
            'component_tools' => $tools,
            'pipeline_order' => array_values($tools),
            'created_from' => 'discovery',
        ];

        // Register as disabled composite tool
        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, source, proposed_by, notes, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'composed', ?, ?, 0)
            ", [
                $composedName,
                'App\\Services\\ToolCompositionService',
                'executeComposition',
                substr($description, 0, 1000),
                json_encode([
                    'context' => ['type' => 'object', 'required' => false, 'description' => 'Additional context parameters to pass through the pipeline'],
                ]),
                'Array with success, results from each pipeline stage, and final output',
                json_encode(['system:read']),
                $this->deriveRiskLevel($tools),
                'composed',
                $agentId,
                json_encode($compositionMeta),
            ]);

            // Submit for review
            $reviewResult = $this->submitForReview($composedName, $tools, $agentId, $description);

            Log::info('ToolComposition: Composition proposed', [
                'name' => $composedName,
                'tools' => $tools,
                'agent' => $agentId,
            ]);

            return [
                'success' => true,
                'composed_name' => $composedName,
                'tools' => $tools,
                'status' => 'proposed',
                'review_token' => $reviewResult['token'] ?? null,
                'message' => "Composite tool '{$composedName}' proposed and submitted for review",
            ];

        } catch (\Throwable $e) {
            Log::error('ToolComposition: Failed to propose', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Derive the highest risk level from component tools.
     */
    private function deriveRiskLevel(array $tools): string
    {
        $levels = ['read' => 0, 'write' => 1, 'destructive' => 2];

        $maxLevel = 0;
        foreach ($tools as $tool) {
            $row = DB::selectOne("SELECT risk_level FROM agent_tool_registry WHERE name = ?", [$tool]);
            if ($row && isset($levels[$row->risk_level])) {
                $maxLevel = max($maxLevel, $levels[$row->risk_level]);
            }
        }

        return array_search($maxLevel, $levels) ?: 'read';
    }

    /**
     * Generate a composed tool name from component tools.
     * Format: compose_{tool1}_{tool2}_... (truncated to 100 chars)
     */
    private function generateComposedName(array $tools): string
    {
        // Use abbreviated tool names for readability
        $parts = array_map(function ($tool) {
            // Remove common prefixes to shorten
            $short = preg_replace('/^(get_|check_|assess_|analyze_|mcp_)/', '', $tool);
            // Take first 15 chars
            return substr($short, 0, 15);
        }, $tools);

        $name = 'compose_' . implode('_', $parts);

        // Ensure valid snake_case format, max 100 chars
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
        $name = preg_replace('/_+/', '_', $name);
        return substr(rtrim($name, '_'), 0, 100);
    }

    // =========================================================================
    // PHASE 3: Execute Composite Tools
    // =========================================================================

    /**
     * Execute a composite tool — runs component tools in sequence with piped outputs.
     *
     * Called by AgentToolRegistryService::executeTool() when a composite tool is invoked.
     * The composition metadata (component_tools, pipeline_order) is stored in the
     * tool's `notes` JSON field in agent_tool_registry.
     *
     * @param array $params Parameters including context and tool name
     * @return array Combined results from all pipeline stages
     */
    public function executeComposition(array $params): array
    {
        $toolName = $params['_composed_tool_name'] ?? $params['tool_name'] ?? null;
        $context = $params['context'] ?? $params;

        if (!$toolName) {
            return ['success' => false, 'error' => 'No composed tool name provided'];
        }

        // Look up composition metadata
        $tool = DB::selectOne(
            "SELECT name, notes, enabled FROM agent_tool_registry WHERE name = ? AND source = 'composed'",
            [$toolName]
        );

        if (!$tool) {
            return ['success' => false, 'error' => "Composite tool '{$toolName}' not found"];
        }

        if (!$tool->enabled) {
            return ['success' => false, 'error' => "Composite tool '{$toolName}' is not enabled"];
        }

        $meta = json_decode($tool->notes, true) ?: [];
        $componentTools = $meta['component_tools'] ?? [];

        if (empty($componentTools)) {
            return ['success' => false, 'error' => "No component tools defined for '{$toolName}'"];
        }

        return $this->executePipeline($toolName, $componentTools, $context);
    }

    /**
     * Execute a pipeline of tools with output piping.
     * Each tool's result is passed as context to the next tool.
     *
     * @param string $composedName Name of the composite tool (for logging)
     * @param array $componentTools Ordered list of tool names
     * @param array $context Initial context/params
     * @return array
     */
    public function executePipeline(string $composedName, array $componentTools, array $context = []): array
    {
        $startTime = microtime(true);
        $stageResults = [];
        $pipelineContext = $context;
        $allSucceeded = true;
        $failedAt = null;

        Log::info('ToolComposition: Pipeline started', [
            'name' => $composedName,
            'tools' => $componentTools,
        ]);

        foreach ($componentTools as $index => $toolName) {
            $stageStart = microtime(true);

            try {
                $result = $this->getToolRegistry()->executeTool($toolName, $pipelineContext, $context);

                $stageResults[] = [
                    'stage' => $index + 1,
                    'tool' => $toolName,
                    'success' => $result['success'],
                    'duration_ms' => round((microtime(true) - $stageStart) * 1000),
                    'result_preview' => substr($result['result_text'] ?? '', 0, 500),
                ];

                if (!$result['success']) {
                    $allSucceeded = false;
                    $failedAt = $toolName;

                    Log::warning('ToolComposition: Pipeline stage failed', [
                        'name' => $composedName,
                        'stage' => $index + 1,
                        'tool' => $toolName,
                        'error' => $result['error'] ?? 'unknown',
                    ]);

                    // Attempt decomposition fallback
                    $fallbackResult = $this->decompositionFallback(
                        $composedName, $componentTools, $index, $pipelineContext, $stageResults, $context
                    );

                    if ($fallbackResult !== null) {
                        return $fallbackResult;
                    }

                    break;
                }

                // Pipe output: merge successful result into context for next stage
                if (is_array($result['result'] ?? null)) {
                    $pipelineContext = array_merge($pipelineContext, $result['result']);
                }
                // Also set last_result for simple piping
                $pipelineContext['_last_result'] = $result['result'] ?? $result['result_text'] ?? null;
                $pipelineContext['_last_tool'] = $toolName;

            } catch (\Throwable $e) {
                $allSucceeded = false;
                $failedAt = $toolName;

                $stageResults[] = [
                    'stage' => $index + 1,
                    'tool' => $toolName,
                    'success' => false,
                    'duration_ms' => round((microtime(true) - $stageStart) * 1000),
                    'error' => $e->getMessage(),
                ];

                Log::error('ToolComposition: Pipeline stage exception', [
                    'name' => $composedName,
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        $totalDuration = round((microtime(true) - $startTime) * 1000);

        // Track usage
        $this->trackCompositionUsage($composedName, $allSucceeded, $totalDuration);

        // Build combined result text
        $resultLines = ["Composite tool '{$composedName}' — " . ($allSucceeded ? 'SUCCESS' : 'PARTIAL FAILURE')];
        foreach ($stageResults as $sr) {
            $status = $sr['success'] ? '✓' : '✗';
            $resultLines[] = "  Stage {$sr['stage']}: {$status} {$sr['tool']} ({$sr['duration_ms']}ms)";
            if (!$sr['success'] && isset($sr['error'])) {
                $resultLines[] = "    Error: {$sr['error']}";
            }
        }

        $lastSuccessResult = null;
        for ($i = count($stageResults) - 1; $i >= 0; $i--) {
            if ($stageResults[$i]['success'] && !empty($stageResults[$i]['result_preview'])) {
                $lastSuccessResult = $stageResults[$i]['result_preview'];
                break;
            }
        }
        if ($lastSuccessResult) {
            $resultLines[] = "\nFinal output:\n" . $lastSuccessResult;
        }

        return [
            'success' => $allSucceeded,
            'result' => $pipelineContext,
            'result_text' => implode("\n", $resultLines),
            'stages' => $stageResults,
            'duration_ms' => $totalDuration,
            'failed_at' => $failedAt,
        ];
    }

    // =========================================================================
    // PHASE 4: Decomposition Fallback
    // =========================================================================

    /**
     * When a composite tool fails at a stage, attempt to recover by:
     * 1. Retrying the failed tool with modified params
     * 2. Skipping the failed tool and continuing with remaining tools
     *
     * @return array|null Recovery result, or null if unrecoverable
     */
    private function decompositionFallback(
        string $composedName,
        array $componentTools,
        int $failedIndex,
        array $context,
        array $previousResults,
        array $originalContext
    ): ?array {
        $failedTool = $componentTools[$failedIndex];
        $remainingTools = array_slice($componentTools, $failedIndex + 1);

        Log::info('ToolComposition: Attempting decomposition fallback', [
            'name' => $composedName,
            'failed_tool' => $failedTool,
            'remaining_tools' => count($remainingTools),
        ]);

        // Strategy 1: Retry failed tool once with clean context (no piped state)
        $retryResult = $this->getToolRegistry()->executeTool($failedTool, $originalContext, $originalContext);

        if ($retryResult['success']) {
            Log::info('ToolComposition: Retry succeeded', ['tool' => $failedTool]);

            // Update context and continue with remaining tools
            $recoveredContext = $context;
            if (is_array($retryResult['result'] ?? null)) {
                $recoveredContext = array_merge($recoveredContext, $retryResult['result']);
            }
            $recoveredContext['_last_result'] = $retryResult['result'] ?? null;
            $recoveredContext['_last_tool'] = $failedTool;

            $previousResults[count($previousResults) - 1]['success'] = true;
            $previousResults[count($previousResults) - 1]['fallback'] = 'retry_clean';

            // Continue pipeline from next tool
            if (!empty($remainingTools)) {
                $continuedResult = $this->executePipeline(
                    $composedName . '_recovery',
                    $remainingTools,
                    $recoveredContext
                );

                return [
                    'success' => $continuedResult['success'],
                    'result' => $continuedResult['result'],
                    'result_text' => "DECOMPOSITION RECOVERY: Retried {$failedTool} successfully, continued pipeline.\n" . $continuedResult['result_text'],
                    'stages' => array_merge($previousResults, $continuedResult['stages'] ?? []),
                    'fallback_used' => 'retry_then_continue',
                ];
            }

            return [
                'success' => true,
                'result' => $recoveredContext,
                'result_text' => "DECOMPOSITION RECOVERY: Retried {$failedTool} successfully (final stage).",
                'stages' => $previousResults,
                'fallback_used' => 'retry_clean',
            ];
        }

        // Strategy 2: Skip failed tool, continue with remaining (if any)
        if (!empty($remainingTools)) {
            Log::info('ToolComposition: Skipping failed tool, continuing', [
                'skipped' => $failedTool,
                'remaining' => $remainingTools,
            ]);

            $continuedResult = $this->executePipeline(
                $composedName . '_skip',
                $remainingTools,
                $context
            );

            if ($continuedResult['success']) {
                return [
                    'success' => false, // Mark as partial — we skipped a stage
                    'result' => $continuedResult['result'],
                    'result_text' => "DECOMPOSITION: Skipped failed {$failedTool}, completed remaining stages.\n" . $continuedResult['result_text'],
                    'stages' => array_merge($previousResults, $continuedResult['stages'] ?? []),
                    'fallback_used' => 'skip_and_continue',
                    'skipped_tools' => [$failedTool],
                ];
            }
        }

        // Unrecoverable
        Log::warning('ToolComposition: Decomposition fallback failed', [
            'name' => $composedName,
            'failed_tool' => $failedTool,
        ]);

        return null;
    }

    // =========================================================================
    // PHASE 5: Review Queue Integration
    // =========================================================================

    /**
     * Submit a composition proposal for human review.
     */
    private function submitForReview(string $composedName, array $tools, string $agentId, string $description): array
    {
        $token = bin2hex(random_bytes(16));

        $details = [
            'composed_name' => $composedName,
            'component_tools' => $tools,
            'tool_count' => count($tools),
            'pipeline' => implode(' → ', $tools),
            'description' => $description,
        ];

        try {
            DB::insert("
                INSERT INTO agent_review_queue
                (agent_id, review_type, title, summary, details, confidence, priority, status, token, expires_at, created_at, updated_at)
                VALUES (?, 'tool_composition', ?, ?, ?, 0.75, 1, 'pending', ?, DATE_ADD(NOW(), INTERVAL 14 DAY), NOW(), NOW())
            ", [
                $agentId,
                "Tool Composition: {$composedName}",
                "Discovered recurring tool pipeline: " . implode(' → ', $tools) . ". {$description}",
                json_encode($details),
                $token,
            ]);

            return ['success' => true, 'token' => $token];
        } catch (\Throwable $e) {
            Log::error('ToolComposition: Review submission failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * ReviewApprovalHandler: Enable composite tool on approval.
     */
    public function onApprove(int $itemId, array $details): array
    {
        $composedName = $details['composed_name'] ?? null;
        if (!$composedName) {
            return ['success' => false, 'error' => 'No composed_name in review details'];
        }

        // Enable the composite tool
        $affected = DB::update(
            "UPDATE agent_tool_registry SET enabled = 1, approved_by = 'human', approved_at = NOW() WHERE name = ? AND source = 'composed'",
            [$composedName]
        );

        if ($affected === 0) {
            return ['success' => false, 'error' => "Composite tool '{$composedName}' not found in registry"];
        }

        Log::info('ToolComposition: Composition approved', ['name' => $composedName]);

        return ['success' => true, 'message' => "Composite tool '{$composedName}' approved and enabled"];
    }

    /**
     * Approve via token (called by ReviewTypeRegistryService).
     */
    public function approveComposition(string $token, ?string $notes = null): array
    {
        $review = DB::selectOne(
            "SELECT id, details FROM agent_review_queue WHERE token = ? AND review_type = 'tool_composition'",
            [$token]
        );

        if (!$review) {
            return ['success' => false, 'error' => 'Review entry not found'];
        }

        $details = json_decode($review->details, true) ?: [];
        $result = $this->onApprove($review->id, $details);

        // Update review queue
        DB::update(
            "UPDATE agent_review_queue SET status = 'approved', reviewed_at = NOW(), reviewer_notes = ?, updated_at = NOW() WHERE token = ?",
            [$notes, $token]
        );

        return $result;
    }

    /**
     * Reject a composition proposal.
     */
    public function rejectComposition(string $token, ?string $reason = null): array
    {
        $review = DB::selectOne(
            "SELECT id, details FROM agent_review_queue WHERE token = ? AND review_type = 'tool_composition'",
            [$token]
        );

        if (!$review) {
            return ['success' => false, 'error' => 'Review entry not found'];
        }

        $details = json_decode($review->details, true) ?: [];
        $composedName = $details['composed_name'] ?? null;

        if ($composedName) {
            DB::delete("DELETE FROM agent_tool_registry WHERE name = ? AND source = 'composed'", [$composedName]);
        }

        DB::update(
            "UPDATE agent_review_queue SET status = 'rejected', reviewed_at = NOW(), reviewer_notes = ?, updated_at = NOW() WHERE token = ?",
            [$reason, $token]
        );

        Log::info('ToolComposition: Composition rejected', ['name' => $composedName, 'reason' => $reason]);

        return ['success' => true, 'message' => "Composition '{$composedName}' rejected and removed"];
    }

    // =========================================================================
    // AGENT TOOLS: Methods callable by agents
    // =========================================================================

    /**
     * Agent tool: discover_compositions — mine procedures for recurring patterns.
     */
    public function discoverCompositionsTool(array $params): array
    {
        $agentId = $params['target_agent'] ?? $params['agent_id'] ?? null;
        $result = $this->discoverCompositions($agentId);

        if (empty($result['candidates'])) {
            return [
                'success' => true,
                'result_text' => "No composition candidates found. Analyzed {$result['total_procedures_analyzed']} procedures, found {$result['total_unique_sequences']} unique sequences. None meet the threshold of " . self::MIN_OCCURRENCES . "+ occurrences with " . (self::MIN_SUCCESS_RATE * 100) . "%+ success rate.",
            ];
        }

        $lines = ["Found {$result['composition_candidates']} composition candidate(s):"];
        foreach ($result['candidates'] as $c) {
            $status = $c['already_enabled'] ? '[ACTIVE]' : ($c['already_registered'] ? '[PENDING]' : '[NEW]');
            $rate = round($c['avg_success_rate'] * 100);
            $lines[] = "- {$status} **{$c['composed_name']}**: " . implode(' → ', $c['tools']);
            $lines[] = "  {$c['procedure_count']} procedures, {$c['unique_agents']} agent(s), {$c['total_uses']} total uses, {$rate}% success";
        }

        return [
            'success' => true,
            'result_text' => implode("\n", $lines),
            'candidates' => $result['candidates'],
        ];
    }

    /**
     * Agent tool: propose_composition — submit a specific composition for review.
     */
    public function proposeCompositionTool(array $params): array
    {
        $tools = $params['tools'] ?? [];
        $description = $params['description'] ?? '';
        $agentId = $params['agent_id'] ?? 'unknown';

        if (empty($tools)) {
            return ['success' => false, 'error' => 'tools array is required (ordered list of tool names)'];
        }

        return $this->proposeComposition($tools, $description, $agentId);
    }

    /**
     * Agent tool: composition_stats — view composition statistics.
     */
    public function compositionStats(array $params): array
    {
        $active = DB::select("
            SELECT name, notes, created_at
            FROM agent_tool_registry
            WHERE source = 'composed' AND enabled = 1
        ");

        $pending = DB::select("
            SELECT arq.title, arq.details, arq.created_at
            FROM agent_review_queue arq
            WHERE arq.review_type = 'tool_composition' AND arq.status = 'pending'
              AND (arq.expires_at IS NULL OR arq.expires_at > NOW())
        ");

        $usageStats = DB::select("
            SELECT tool_name, times_executed, times_succeeded, times_failed,
                   avg_duration_ms, last_executed_at
            FROM composite_tool_usage
            ORDER BY times_executed DESC
        ");

        $lines = [
            "Tool Composition Statistics",
            "Active compositions: " . count($active),
            "Pending review: " . count($pending),
            "",
        ];

        if (!empty($active)) {
            $lines[] = "Active Compositions:";
            foreach ($active as $a) {
                $meta = json_decode($a->notes, true) ?: [];
                $tools = $meta['component_tools'] ?? [];
                $lines[] = "- {$a->name}: " . implode(' → ', $tools);
            }
        }

        if (!empty($usageStats)) {
            $lines[] = "";
            $lines[] = "Usage Stats:";
            foreach ($usageStats as $u) {
                $total = (int) $u->times_executed;
                $rate = $total > 0 ? round(((int) $u->times_succeeded / $total) * 100) : 0;
                $lines[] = "- {$u->tool_name}: {$total} executions, {$rate}% success, avg {$u->avg_duration_ms}ms";
            }
        }

        return [
            'success' => true,
            'result_text' => implode("\n", $lines),
            'active' => count($active),
            'pending' => count($pending),
            'usage' => array_map(fn($u) => (array) $u, $usageStats),
        ];
    }

    /**
     * Agent tool: pending_compositions — list pending composition proposals.
     */
    public function pendingCompositions(array $params): array
    {
        $proposals = DB::select("
            SELECT agent_id, title, summary, details, token, created_at
            FROM agent_review_queue
            WHERE review_type = 'tool_composition' AND status = 'pending'
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
            LIMIT 20
        ");

        if (empty($proposals)) {
            return ['success' => true, 'result_text' => 'No pending composition proposals.', 'count' => 0];
        }

        $lines = ["Pending Composition Proposals (" . count($proposals) . "):"];
        foreach ($proposals as $p) {
            $details = json_decode($p->details, true) ?: [];
            $pipeline = $details['pipeline'] ?? 'unknown';
            $lines[] = "- {$details['composed_name']}: {$pipeline}";
            $lines[] = "  Proposed by: {$p->agent_id} at {$p->created_at}";
        }

        return [
            'success' => true,
            'result_text' => implode("\n", $lines),
            'count' => count($proposals),
            'proposals' => array_map(fn($p) => [
                'agent_id' => $p->agent_id,
                'title' => $p->title,
                'details' => json_decode($p->details, true),
                'token' => $p->token,
                'created_at' => $p->created_at,
            ], $proposals),
        ];
    }

    // =========================================================================
    // USAGE TRACKING
    // =========================================================================

    /**
     * Track composite tool execution metrics.
     */
    private function trackCompositionUsage(string $composedName, bool $success, int $durationMs): void
    {
        try {
            $existing = DB::selectOne("SELECT id FROM composite_tool_usage WHERE tool_name = ?", [$composedName]);

            if ($existing) {
                if ($success) {
                    DB::update("
                        UPDATE composite_tool_usage
                        SET avg_duration_ms = ((avg_duration_ms * times_executed) + ?) / (times_executed + 1),
                            times_executed = times_executed + 1,
                            times_succeeded = times_succeeded + 1,
                            last_executed_at = NOW()
                        WHERE tool_name = ?
                    ", [$durationMs, $composedName]);
                } else {
                    DB::update("
                        UPDATE composite_tool_usage
                        SET avg_duration_ms = ((avg_duration_ms * times_executed) + ?) / (times_executed + 1),
                            times_executed = times_executed + 1,
                            times_failed = times_failed + 1,
                            last_executed_at = NOW()
                        WHERE tool_name = ?
                    ", [$durationMs, $composedName]);
                }
            } else {
                DB::insert("
                    INSERT INTO composite_tool_usage
                    (tool_name, times_executed, times_succeeded, times_failed, avg_duration_ms, last_executed_at, created_at)
                    VALUES (?, 1, ?, ?, ?, NOW(), NOW())
                ", [
                    $composedName,
                    $success ? 1 : 0,
                    $success ? 0 : 1,
                    $durationMs,
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('ToolComposition: Usage tracking failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // API: Controller/Dashboard methods
    // =========================================================================

    /**
     * Get all compositions with status.
     */
    public function getCompositions(): array
    {
        $tools = DB::select("
            SELECT atr.name, atr.description, atr.notes, atr.enabled,
                   atr.source, atr.proposed_by, atr.approved_at, atr.created_at,
                   ctu.times_executed, ctu.times_succeeded, ctu.times_failed,
                   ctu.avg_duration_ms, ctu.last_executed_at
            FROM agent_tool_registry atr
            LEFT JOIN composite_tool_usage ctu ON ctu.tool_name = atr.name
            WHERE atr.source = 'composed'
            ORDER BY atr.enabled DESC, atr.created_at DESC
        ");

        return array_map(function ($t) {
            $meta = json_decode($t->notes, true) ?: [];
            return [
                'name' => $t->name,
                'description' => $t->description,
                'component_tools' => $meta['component_tools'] ?? [],
                'enabled' => (bool) $t->enabled,
                'proposed_by' => $t->proposed_by,
                'approved_at' => $t->approved_at,
                'times_executed' => (int) ($t->times_executed ?? 0),
                'times_succeeded' => (int) ($t->times_succeeded ?? 0),
                'times_failed' => (int) ($t->times_failed ?? 0),
                'avg_duration_ms' => (int) ($t->avg_duration_ms ?? 0),
                'last_executed_at' => $t->last_executed_at,
                'created_at' => $t->created_at,
            ];
        }, $tools);
    }

    /**
     * Disable a composite tool.
     */
    public function disableComposition(string $name): bool
    {
        return DB::update(
            "UPDATE agent_tool_registry SET enabled = 0 WHERE name = ? AND source = 'composed'",
            [$name]
        ) > 0;
    }

    /**
     * Enable a composite tool.
     */
    public function enableComposition(string $name): bool
    {
        return DB::update(
            "UPDATE agent_tool_registry SET enabled = 1 WHERE name = ? AND source = 'composed'",
            [$name]
        ) > 0;
    }

    /**
     * Delete a composite tool entirely.
     */
    public function deleteComposition(string $name): bool
    {
        DB::delete("DELETE FROM composite_tool_usage WHERE tool_name = ?", [$name]);
        return DB::delete(
            "DELETE FROM agent_tool_registry WHERE name = ? AND source = 'composed'",
            [$name]
        ) > 0;
    }
}
